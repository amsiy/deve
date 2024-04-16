<?php if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
    return;
}

// New wc order status
function monobank_get_new_order_status() {
    return array( 'wc-paid' => __( 'Оплачено', 'monobank' ) );
}

foreach ( monobank_get_new_order_status() as $key_status => $label_status ) {
    $key_status = substr( $key_status, 3 );

    // Order Status completed - give downloadable product access to customer.
    if ( function_exists( 'wc_downloadable_product_permissions' ) ) {
        add_action( 'woocommerce_order_status_' . $key_status, 'wc_downloadable_product_permissions' );
    }

    // Update total sales amount for each product within a paid order.
    if ( function_exists( 'wc_update_total_sales_counts' ) ) {
        add_action( 'woocommerce_order_status_' . $key_status, 'wc_update_total_sales_counts' );
    }

    // Update used coupon amount for each coupon within an order.
    if ( function_exists( 'wc_update_coupon_usage_counts' ) ) {
        add_action( 'woocommerce_order_status_' . $key_status, 'wc_update_coupon_usage_counts' );
    }

    // When a payment is complete, we can reduce stock levels for items within an order.
    if ( function_exists( 'wc_maybe_reduce_stock_levels' ) ) {
        add_action( 'woocommerce_order_status_' . $key_status, 'wc_maybe_reduce_stock_levels' );
    }
}

// Add order statuses
function monobank_order_statuses( $order_statuses ) {
    foreach ( monobank_get_new_order_status() as $key => $label ) {
        $order_statuses[ $key ] = $label;
    }
    return $order_statuses;
}
add_filter( 'wc_order_statuses', 'monobank_order_statuses' );

// Register new statuses
function monobank_register_new_statuses() {
    foreach ( monobank_get_new_order_status() as $key => $label ) {
        register_post_status( $key, array(
            'label'                     => $label,
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop( $label . ' <span class="count">(%s)</span>', $label . ' <span class="count">(%s)</span>' ),
        ) );
    }
}
add_action( 'init', 'monobank_register_new_statuses' );

// Reports
function monobank_reports_order_statuses( $order_status ) {
    if ( is_array( $order_status ) && in_array( 'completed', $order_status ) ) {
        foreach ( monobank_get_new_order_status() as $key => $label ) {
            $order_status[] = substr( $key, 3 );
        }
    }
    return $order_status;
}
add_filter( 'woocommerce_reports_order_statuses', 'monobank_reports_order_statuses' );

// Paid order statuses
function monobank_order_is_paid_statuses( $statuses ) {
    foreach ( monobank_get_new_order_status() as $key => $label ) {
        $statuses[] = substr( $key, 3 );
    }
    return $statuses;
}
add_filter( 'woocommerce_order_is_paid_statuses', 'monobank_order_is_paid_statuses' );

// Load gateways and hook in functions.
function monobank_payment_gateways( $methods ) {
    $methods[] = 'WC_Gateway_Monobank';
    return $methods;
}
add_filter( 'woocommerce_payment_gateways', 'monobank_payment_gateways' );

class WC_Gateway_Monobank extends WC_Payment_Gateway {

  const api_url        = 'https://api.monobank.ua/api/';
  const api_create_url = self::api_url.'merchant/invoice/create';
  const api_status_url = self::api_url.'merchant/invoice/status';

  private $supportedCurrencies = array( 'USD', 'EUR', 'RUB', 'UAH', 'BYN', 'KZT' );
  private $countMaxCheckmonobank = 5;

  public $instructions;
  public $name;
  public $destination;
  public $token;
  public $lang;
  public $status;
  public $checkout_url = '/';

    public function __construct() {
        // Setup general properties.
        $this->setup_properties();

        // Load the settings.
        $this->init_form_fields();
        $this->init_settings();

        // Admin Panel
        add_filter( 'manage_shop_order_posts_columns', array( $this, 'shop_order_posts_columns_head' ), 11 );
        add_action( 'manage_shop_order_posts_custom_column', array( $this, 'shop_order_posts_columns_content' ), 11, 2 );
        add_filter( 'woocommerce_admin_order_data_after_order_details', array( $this, 'admin_order_data_after_order_details' ) );

        // Get settings.
        $this->title        = $this->get_option( 'title' );
        $this->description  = $this->get_option( 'description' );
        $this->destination  = $this->get_option( 'destination' );
        $this->instructions = $this->get_option( 'instructions' );
        $this->token   = $this->get_option( 'token' );
        $this->lang   = function_exists( 'wpm_get_language' ) ? wpm_get_language() : 'uk';
        $this->status = $this->get_option( 'status' );

        if( THEME_CHECKOUT_PAGE_ID ){
            $this->checkout_url = get_permalink( THEME_CHECKOUT_PAGE_ID );
        }

        // Actions
        if ( ! $this->is_valid_for_use() ) {
            $this->enabled = 'no';
        } else {
            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
            add_action( 'woocommerce_api_wc_gateway_' . $this->id, array( $this, 'check_monobank_server_response' ) );
            add_action( 'get_header', array( $this, 'check_monobank_result_response' ) );
            add_action( 'wp_loaded', array( $this, 'failed_order' ), 20 );
        }

    }

    // Setup general properties for the gateway.
    protected function setup_properties() {
        $this->id                 = 'monobank';
        $this->icon               = '';
        $this->has_fields         = false;
        $this->order_button_text  = __( 'Розрахуватися', 'monobank' );
        $this->method_title       = __( 'Monobank', 'monobank' );
        $this->method_description = __( 'Платіжна система Monobank', 'monobank' );
    }

    // Filters the columns displayed in the Posts list table for a Shop Order post type.
    public function shop_order_posts_columns_head( $columns ) {
        $columns = $this->array_insert_after( $columns, 'order_date', array( 'payment' => __( 'Оплата', 'monobank' ) ) );

        return $columns;
    }

    public function shop_order_posts_columns_content( $column_name, $postID ) {
        if ( $column_name == 'payment' ) {
            $payment       = get_post_meta( $postID, '_payment_method', true );
            $payment_title = get_post_meta( $postID, '_payment_method_title', true );
            if ( $payment == 'monobank' ) {
                echo $payment_title;
                if ( get_post_meta( $postID, 'monobank_status_wait_accept', true ) ) {
                    echo '<br>' . __( 'Проблема із оплатою', 'monobank' );
                }
            }
        }
    }

    // Order details fields
    public function admin_order_data_after_order_details( $order ) {
      if ( $order->get_payment_method() == 'monobank' && get_post_meta( $order->get_id(), 'monobank_status_wait_accept', true ) ) { ?>
        <p class="form-field form-field-wide"></p>
        <p class="form-field form-field-wide">
            <label for="monobank_status_wait_accept"><?php _e( 'Проблеми із monobank:', 'monobank' ); ?></label>
            <textarea rows="4" cols="40" id="monobank_status_wait_accept" readonly><?php _e( 'Гроші з клієнта списані, але магазин ще не пройшов перевірку. Якщо магазин не пройду активацію протягом 180 днів, платежі будуть автоматично скасовані', 'monobank' ); ?></textarea>
        </p>
        <?php 
      }
    }

    // Insert a value or key/value pair after a specific key in an array.  If key doesn't exist, value is appended to the end of the array.
    public function array_insert_after( array $array, $key, array $new ) {
        $keys  = array_keys( $array );
        $index = array_search( $key, $keys );
        $pos   = false === $index ? count( $array ) : $index + 1;
        return array_merge( array_slice( $array, 0, $pos ), $new, array_slice( $array, $pos ) );
    }

    // Initialise Gateway Settings Form Fields.
    public function init_form_fields() {
        $statuses = wc_get_order_statuses();

        foreach ( $statuses as $key => $value ) {
            $status              = 'wc-' === substr( $key, 0, 3 ) ? substr( $key, 3 ) : $key;
            $statuses[ $status ] = $value;

            unset( $statuses[ $key ] );
        }

        $this->form_fields = array(
            'enabled' => array(
                'title'   => __( 'Включити/вимкнути', 'monobank' ),
                'type'    => 'checkbox',
                'label'   => __( 'Включити', 'monobank' ),
                'default' => 'yes',
            ),
            'token' => array(
                'title'       => __( 'Token', 'monobank' ),
                'type'        => 'text',
                'description' => __( 'Token доступу до Monobank API.', 'monobank' ),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'title' => array(
                'title'       => __( 'Назва', 'monobank' ),
                'type'        => 'text',
                'description' => __( 'Опис способу оплати, який покупець бачитиме при оформленні замовлення.', 'monobank' ),
                'default'     => 'Онлайн оплата Monobank',
                'desc_tip'    => true,
            ),
            'destination' => array(
                'title'       => __( 'Опис оплати', 'monobank' ),
                'type'        => 'text',
                'description' => __( 'Опис оплати для Monobank.', 'monobank' ),
                'default'     => 'Оплата замовлення',
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => __( 'Опис', 'monobank' ),
                'type'        => 'textarea',
                'description' => __( 'Опис способу оплати, який покупець буде бачити на вашому сайті.', 'monobank' ),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'instructions' => array(
                'title'       => __( 'Інструкції', 'monobank' ),
                'type'        => 'textarea',
                'description' => __( 'Інструкції, які будуть додані на сторінку подяки.', 'monobank' ),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'status' => array(
                'title'       => __( 'Статус замовлення',  'monobank' ),
                'type'        => 'select',
                'default'     => 'processing',
                'options'     => $statuses,
                'description' => __( 'Статус замовлення після успішної оплати.', 'monobank' ),
                'desc_tip'    => true,
            ),
        );
    }

    // Output the gateway settings screen.
    public function admin_options() {
        echo '<h2>';
            _e( 'Платіжна система monobank', 'monobank' );
            wc_back_link( __( 'Повернутися до платежів', 'monobank' ), admin_url( 'admin.php?page=wc-settings&tab=checkout' ) );
        echo '</h2>';

        if ( $this->is_valid_for_use() ) {
            echo '<table class="form-table">';
                $this->generate_settings_html();
            echo '</table>';
        } else {
            echo '<div class="inline error"><p><strong>' . __( 'Платіжний шлюз вимкнено', 'monobank' ) . '</strong>: ' . __( 'monobank не підтримує валюти Вашого магазину.', 'monobank' ) . '</p></div>';
        }
    }

    // Check currency shop
    private function is_valid_for_use() {
        return ! in_array( get_option( 'woocommerce_currency' ), $this->supportedCurrencies ) ? false : true;
    }

    // Process the payment and return the result.
    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );

        if ( $order->get_total() > 0 ) {
            $redirect = $this->create_link( $order_id );
            WC()->cart->empty_cart();
            
        } else {
            $order->payment_complete();
            WC()->cart->empty_cart();

            //$redirect = $this->get_return_url( $order );
        }
        return array(
            'result'   => 'success',
            'redirect' => $redirect
        );
    }

    private function str_to_sign( $str ) {
        return base64_encode( sha1( $str, 1 ) );
    }

    private function generate_params( $order_id ) {
        $order = wc_get_order( $order_id );

        $amount = $order->get_total( 'edit' );

        $data = [
          'amount'           => $this->uah_to_coins( $amount ), 
          'ccy'              => 980,
          'merchantPaymInfo' => $this->detail( $order ),
          'redirectUrl'      => wc_get_checkout_url().'?key='.base64_encode($order_id),
          'webHookUrl'       => add_query_arg( 'wc-api', 'wc_gateway_' . $this->id, home_url( '/' ) ),//home_url( '?monobank-response=1' ),
          'validity'         => 3600,
          'paymentType'      => 'debit',
          'qrId'             => '',
        ];


        return $data;
    }

    // Uah to coins
    private function uah_to_coins( $amount ){
      $amount = $amount * 100;

      return (int)$amount;
    }

    // Order detail
    private function detail( $order = null ){

        // Data variable
        $data = [];

        // Collected order ITEMS( not required for REQUEST )
        $order_items = [];

        foreach ($order->get_items() as $item_id => $item) {
            $data = $item->get_data();
            $id = $data['product_id'];
            $name = $data['name'];
            $quantity = $data['quantity'];
            $total = (int)$data['total'];
            $product_id = $data['product_id'];
            $variation_id = $data['variation_id'];
            $item_price = $total/$quantity;
            $final_price = $this->uah_to_coins($item_price);


            $order_items[] = array(
                "name"=> esc_attr($name),
                "qty"=> $quantity,
                "sum"=> $final_price,
                "unit"=> "шт.",
                "code"=> $product_id.$variation_id,
            );


        }

          // Collected data
          $data = [
            'reference'   => strval( $order->get_id() ),
            'destination' => $this->destination,
            'basketOrder' => $order_items
          ];

          return $data;
    }

    // Check monobank Result Response
    public function check_monobank_result_response() {

        if ( ! empty( $_POST ) && is_string( $_POST ) ) {
            $pay         = false;
            $status      = '';
            $data     = json_decode( $_POST );
            $order_id = isset( $data->reference ) ? $data->reference : 0;
            if ( empty( $order_id ) || !isset( $data->status ) ) {
                return false;
            }

            for ( $i = 0; $i < $this->countMaxCheckmonobank; $i++ ) {
                $data = $this->api( self::api_status_url, [ 'invoiceId' => $order_id ], 'GET' );

                $status = isset( $data->status ) ? $data->status : '';

                if ( $status == 'success' ) {
                    $pay    = true;

                    break;
                }
            }

            $order = wc_get_order( $order_id );
            
            $order->add_order_note( sprintf( esc_attr__( "Monobank відповідь на сайт: \nстатус - \"%s\"\n%s", 'fest' ), $data->status, base64_decode( $data ) ) );

            update_post_meta( $order_id, 'monobank_response_site', 1 );
            update_post_meta( $order_id, 'monobank_response_order', sprintf( esc_attr__( "Monobank відповідь на сайт: \nстатус - \"%s\"\n%s", 'fest' ), $data->status, base64_decode( $data ) ) );
            if ( $pay ) {
                if ( $status == 'processing' ) {
                    update_post_meta( $order->get_id(), 'monobank_status_wait_accept', 1 );
                }

                wp_redirect( $order->get_checkout_order_received_url() );
            } else if ( isset( $parsed_data->err_code ) && $parsed_data->err_code == 'cancel' ) {
                wp_redirect( esc_url_raw( $this->get_cancel_order_url_raw( $order ) ) );
            } else {
                wp_redirect( esc_url_raw( $this->get_failed_order_url_raw( $order ) ) );
            }
            exit;
        }
    }

    // Check monobank Server Response
    public function check_monobank_server_response() {

        $data = file_get_contents('php://input');

        self::write_log( 'server_response-'.$order_id.'-'.current_time( 'timestamp' ), $data );


      if ( ! empty( $data ) && is_string( $data ) ) {

        $data     = json_decode( $data );
        $order_id = isset( $data->reference ) || $data->reference != null ? $data->reference : 0;
        if ( empty( $order_id ) || !isset( $data->status ) ) {
            return false;
        }
        
        
        $order = wc_get_order( $order_id );

        if ( in_array( $data->status, ['success'] )  ) {

            $order->add_order_note( sprintf( esc_attr__( "Monobank відповідь на сайт: \nстатус - \"%s\"\n%s", 'fest' ), $data->status, '('.implode(',', (array)$data ).')' ) );

            update_post_meta( $order_id, 'monobank_response_order', sprintf( esc_attr__( "Monobank відповідь на сайт: \nстатус - \"%s\"\n%s", 'fest' ), $data->status, '('.implode(',', (array)$data ).')' ) );

            if( !get_post_meta( $order_id, 'monobank_response_site', 1 ) ){
                update_post_meta( $order_id, 'monobank_response_site', 1 );
                $order->update_status( 'processing', __( 'Оплата була отримана', 'monobank' ) );
            }

            exit();
   
        } else if( !in_array( $data->status, ['success','created','processing'] ) ){
            
          $order->add_order_note( sprintf( esc_attr__( "Monobank відповідь на сайт: \nстатус - \"%s\"\n%s", 'fest' ), $data->status, '('.implode(',', (array)$data ).')' ) );
          update_post_meta( $order_id, 'monobank_response_order', sprintf( esc_attr__( "Monobank відповідь на сайт: \nстатус - \"%s\"\n%s", 'fest' ), $data->status, '('.implode(',', (array)$data ).')' ) );
          $order->update_status( 'failed', __( 'Оплата не була отримана', 'monobank' ) );

          exit();
        }
      }
    }

    // Call API
    private function api( $url, $params = array(), $method = 'POST' ) {

        if( ! $this->token ){
            return new WP_Error( 'payment-error', __('Payment error!','verde') );
        }

        $ch = curl_init( $url );
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

        if (!empty($params)) {
          curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
        }

        // cURL expects full header strings in each element.
        $header  = array();
        $headers = array(
            'content-type' => 'application/json',
            'accept'       => 'application/json',
            'X-Token'      => $this->token,
        );
        foreach ( $headers as $name => $value ) {
            $header[] = "{$name}: $value";
        }
        curl_setopt( $ch, CURLOPT_HTTPHEADER, $header );

        $response = curl_exec($ch);
        $error = curl_error($ch);

        curl_close($ch);
        $response = json_decode($response);

        if ( $error ) {
          return new WP_Error( 'http_request_failed', $error );
        }elseif( isset( $response->errCode ) && !empty( $response->errCode ) ){
          return new WP_Error( $response->errCode, $response->errText );
        }elseif( isset( $response->errorDescription ) ){
          return new WP_Error( 'error', $response->errorDescription );
        }

        return $response;
    }

    // Create Link
    private function create_link( $order_id ) {
        $params = $this->generate_params( $order_id );
        $result = $this->api( self::api_create_url, $params );


        self::write_log( 'link-'.$order_id, [
            'token' => $this->token,
            'params' => $params,
            'result' => $result
        ] );

        if( ! is_wp_error( $result ) ){
          if( $result->invoiceId && $result->pageUrl ){

            // Add notice to order ( information about what browser user used )
            self::order_notice( $order_id );

            return $result->pageUrl;
          }
        }else{
          return false;
        }
    }

    // Generates a URL so that a customer can cancel their (unpaid - pending) order.
    private function get_cancel_order_url_raw( $order ) {
        return add_query_arg(
            array(
                'cancel_order' => 'true',
                'order'        => $order->get_order_key(),
                'order_id'     => $order->get_id(),
                'redirect'     => '',
                '_wpnonce'     => wp_create_nonce( 'woocommerce-cancel_order' ),
            ),
            $this->get_cancel_endpoint()
        );
    }

    // Helper method to return the cancel endpoint.
    private function get_cancel_endpoint() {
        $cancel_endpoint = wc_get_page_permalink( 'cart', wc_get_page_permalink( 'checkout' ) );
        if ( ! $cancel_endpoint ) {
            $cancel_endpoint = home_url();
        }

        if ( false === strpos( $cancel_endpoint, '?' ) ) {
            $cancel_endpoint = trailingslashit( $cancel_endpoint );
        }

        return $cancel_endpoint;
    }

    // Generates a URL so that a customer can failed their (unpaid - pending) order.
    private function get_failed_order_url_raw( $order ) {
        return add_query_arg(
            array(
                'failed_order' => 'true',
                'order'        => $order->get_order_key(),
                'order_id'     => $order->get_id(),
                'redirect'     => '',
                '_wpnonce'     => wp_create_nonce( 'woocommerce-monobank-failed_order' ),
            ),
            $this->get_cancel_endpoint()
        );
    }

    // Cancel a pending order.
    public function failed_order() {
        if (
            isset( $_GET['failed_order'] ) &&
            isset( $_GET['order'] ) &&
            isset( $_GET['order_id'] ) &&
            ( isset( $_GET['_wpnonce'] ) && wp_verify_nonce( wp_unslash( $_GET['_wpnonce'] ), 'woocommerce-monobank-failed_order' ) )
        ) {
            wc_nocache_headers();

            $order_key        = wp_unslash( $_GET['order'] );
            $order_id         = absint( $_GET['order_id'] );
            $order            = wc_get_order( $order_id );
            $user_can_failed  = current_user_can( 'cancel_order', $order_id );
            $order_can_failed = $order->has_status( array( 'pending', 'failed' ) );
            $redirect         = isset( $_GET['redirect'] ) ? wp_unslash( $_GET['redirect'] ) : '';

            if ( $user_can_failed && $order_can_failed && $order->get_id() === $order_id && hash_equals( $order->get_order_key(), $order_key ) ) {
                WC()->session->set( 'order_awaiting_payment', false );

                wc_add_notice( __( 'Ваше замовлення було невдалим. Будь ласка, зв\'яжіться з нами, якщо вам потрібна допомога.', 'monobank' ), 'error' );

            } elseif ( $user_can_failed && ! $order_can_failed ) {
                wc_add_notice( __( 'Ваше замовлення було невдалим. Будь ласка, зв\'яжіться з нами, якщо вам потрібна допомога.', 'monobank' ), 'error' );
            } else {
                wc_add_notice( __( 'Невірне замовлення.', 'monobank' ), 'error' );
            }

            if ( $redirect ) {
                wp_safe_redirect( $redirect );
                exit;
            }
        }
    }

    private function write_log( $name = 'log', $text = false ){
        file_put_contents(__DIR__.'/logs/mono-'.$name.'.php', var_export($text, true));
    }

    // Add order notice
    private function order_notice( $order_id  ){

        // If order ID not exist or empty
        if( !$order_id ){
          return false;
        }

        $order = wc_get_order( $order_id );

        // If order not exist or empty
        if( !$order ){
          return false;
        }

        // Detect user browser
        $ua = self::getBrowser();
        $yourbrowser = "Your browser: " . $ua['name'] . " " . $ua['version'] . " on " .$ua['platform'] . " reports: <br >" . $ua['userAgent'];
        $order->add_order_note( 'Monobank price - ' . $order->get_total('') . ', '.$yourbrowser );

        unset( $order );
    }

    static function getBrowser(){
        $u_agent = $_SERVER['HTTP_USER_AGENT'];
        $bname = 'Unknown';
        $platform = 'Unknown';
        $version = "";

        //First get the platform?
        if (preg_match('/linux/i', $u_agent)) {
            $platform = 'linux';
        }
        elseif (preg_match('/macintosh|mac os x/i', $u_agent)) {
            $platform = 'mac';
        }
        elseif (preg_match('/windows|win32/i', $u_agent)) {
            $platform = 'windows';
        }
       
        // Next get the name of the useragent yes seperately and for good reason
        if(preg_match('/MSIE/i',$u_agent) && !preg_match('/Opera/i',$u_agent))
        {
            $bname = 'Internet Explorer';
            $ub = "MSIE";
        }
        elseif(preg_match('/Firefox/i',$u_agent))
        {
            $bname = 'Mozilla Firefox';
            $ub = "Firefox";
        }
        elseif(preg_match('/Chrome/i',$u_agent))
        {
            $bname = 'Google Chrome';
            $ub = "Chrome";
        }
        elseif(preg_match('/Safari/i',$u_agent))
        {
            $bname = 'Apple Safari';
            $ub = "Safari";
        }
        elseif(preg_match('/Opera/i',$u_agent))
        {
            $bname = 'Opera';
            $ub = "Opera";
        }
        elseif(preg_match('/Netscape/i',$u_agent))
        {
            $bname = 'Netscape';
            $ub = "Netscape";
        }
       
        // finally get the correct version number
        $known = array('Version', $ub, 'other');
        $pattern = '#(?<browser>' . join('|', $known) .
        ')[/ ]+(?<version>[0-9.|a-zA-Z.]*)#';
        if (!preg_match_all($pattern, $u_agent, $matches)) {
            // we have no matching number just continue
        }
       
        // see how many we have
        $i = count($matches['browser']);
        if ($i != 1) {
            //we will have two since we are not using 'other' argument yet
            //see if version is before or after the name
            if (strripos($u_agent,"Version") < strripos($u_agent,$ub)){
                $version= $matches['version'][0];
            }
            else {
                $version= $matches['version'][1];
            }
        }
        else {
            $version= $matches['version'][0];
        }
       
        // check if we have a number
        if ($version==null || $version=="") {$version="?";}
       
        return array(
            'userAgent' => $u_agent,
            'name'      => $bname,
            'version'   => $version,
            'platform'  => $platform,
            'pattern'    => $pattern
        );
    }

}
global $monobank;
$monobank = new WC_Gateway_Monobank();
