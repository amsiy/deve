<?php if( !defined('ABSPATH') ) exit;

class deveAdmin{

	public $product_in         = array();
    public $exclude_product    = array();
    public $categories         = array();
    public $exclude_categories = array();

    public $sms_login  = '';
    public $sms_pswd   = '';
    public $sms_ident  = '';
    public $sms_title  = 'LiqPay';
    public $sms_url    = 'https://api-v2.hyber.im/';

    public $firebase_api_key = '';

	public function __construct(){
		global $pagenow;

		if( is_admin() ){

			// Scripts
			add_action( 'woocommerce_order_item_add_action_buttons', array( $this, 'add_buttons' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'scripts' ) );

			// Popups
			add_action( 'wp_ajax_get_items', array( $this, 'get_items' ) );
			add_action( 'wp_ajax_get_items_popup', array( $this, 'get_items_popup' ) );
			add_action( 'wp_ajax_get_item_detail', array( $this, 'get_item_detail' ) );
			add_action( 'wp_ajax_get_items_autocomplete', array( $this, 'get_items_autocomplete' ) );

			// deve 
			add_action( 'wp_ajax_get_deve_popup', array( $this, 'get_deve_popup' ) );
			add_action( 'wp_ajax_admin_deve_authentication', array( $this, 'deve_authentication' ) );
			add_action( 'woocommerce_order_after_calculate_totals', array( $this, 'deve_calculate' ), 10, 2 );
			add_action( 'woocommerce_saved_order_items', array( $this, 'deve_calculate_saved_items' ), 10, 2 );

			// bonuses
			add_action( 'wp_ajax_deve_add_bonuses', array( $this, 'deve_add_bonuses' ) );
			add_action( 'wp_ajax_deve_remove_bonuses', array( $this, 'deve_remove_bonuses' ) );

			// Delivery
			add_action( 'wp_ajax_deve_delivery_costs', array( $this, 'deve_delivery_costs' ) );

			// Nova poshta
			add_action( 'wp_ajax_nova_poshta_popup', array( $this, 'nova_poshta_popup' ), 90, 1 );

			// Manual delivery price
			add_action( 'wp_ajax_update_manual_price', array( $this, 'wc_admin_update_manual_price' ) );

			// Pickup addreses for order item
			add_action( 'woocommerce_after_order_itemmeta', array( $this, 'order_pickup_addresses' ), 10, 3 );

			// Liqpay SMS
			add_action( 'wp_ajax_liqpay_sms', array( $this, 'liqpay_sms' ) );

			// WC admin hooks
			add_action( 'add_meta_boxes', array( $this, 'remove_shop_order_meta_box' ), 90 );

		}
		add_action( 'woocommerce_new_order', array( $this, 'liqpay_init' ), 10, 1 );
		add_action( 'woocommerce_admin_order_data_after_shipping_address', array( $this, 'liqpay_link_field' ) );
		add_filter( 'woocommerce_admin_shipping_fields', '__return_empty_array' );
	}

	// JS scripts
	public function scripts(){
		wp_enqueue_script( 'jquery-inputmask', THEME_URI.'/js/jquery.inputmask.js', array('jquery'), false, true );
        wp_enqueue_script( 'swiper', THEME_URI.'/js/swiper.jquery.min.js', array('jquery'), false, true );
		wp_enqueue_script( 'admin-items-scripts', THEME_URI .'/functions/deve-admin/js/admin-items-scripts.js', array('jquery'), null, false );
		wp_enqueue_style( 'admin-items-style',  THEME_URI .'/functions/deve-admin/css/style.css');

		$deve = array(
	        'theme_uri' 		   => THEME_URI,
	        'error_server' 		   => __('error 1','deve'),
	        'error_data' 		   => __('error 2','deve'),
	        'error_empty_field'    => __('error 3','deve'),
	        'error_invalid_phone'  => __('error 4','deve'),
	        'error_invalid_email'  => __('error 5','deve'),
	        'error_invalid_stars'  => __('error 5','deve'),
	        'error_invalid_rules'  => __('error 5','deve'),
	        'error_short_password' => __('error 5','deve'),
	        'error_variation_purchasable' => __('error 5','deve'),
	        'error_invalid_password' 	 => __('error 5','deve'),
	        'error_deve_no_found_phone' => __('error 5','deve'),
	        'error_deve_invalid' 		 => __('error 5','deve')
	    );
	    wp_localize_script( 'admin-items-scripts', 'deve', $deve );
	}

	// Add buttons
	public function add_buttons( $order ){
		?>
			<button type="button" class="button deve-buttons add-popup-item"><?php esc_html_e( 'Add item(s)', 'woocommerce' ); ?></button>
			<button type="button" class="button deve-buttons add-deve"><?php esc_html_e( 'Card deve', 'woocommerce' ); ?></button>
		<?php
	}

	// Get catalog popup
	public function get_items_popup(){
		
		$terms = get_terms( 'product_cat', [
			'hide_empty' => false,
			'parent' => 0
		] );  ?>

		<div class="wc-backbone-modal items-wrapper-popup">
			<div class="wc-backbone-modal-content items-list-popup">
				<section class="wc-backbone-modal-main" role="main">
					<header class="wc-backbone-modal-header">
						<h1><?php esc_html_e( 'Add products', 'woocommerce' ); ?></h1>
						<button class="modal-close modal-close-link dashicons dashicons-no-alt">
							<span class="screen-reader-text">Close modal panel</span>
						</button>
					</header>
					<article>
						<div class="items-wrapper pizza-section">
							<div class="items-filter accordion-container">
								<div class="field-wrapper deve-autocomplete-wrapper">
									<input class="input-field deve-autocomplete-field" type="text" name="search" placeholder="<?php _e( 'Пошук', 'deve' ); ?>" >
									<span class="close-list"></span>
								</div>
								<?php 

								// ALL CATEGORIES

								/*if( $terms ){ ?>
									<ul class="outer-border">
										<?php $i = 0;
										foreach( $terms as $term ){
												$children = get_terms( array( 
		                                    		'taxonomy'   => 'product_cat',
		                                    		'parent'     => $term->term_id,
		                                    	) );

		                                    $i++;
						                    $children_class = $children ? ' children' : '';
											echo '<li data-slug="'.$term->slug.'" class="term-item control-section accordion-section hide-if-js'.$children_class.'">';
											if( $children ){
												echo '<p class="accordion-section-title hndle" tabindex="0"><span>'.$term->name.'</span></p>';
												echo '<ul class="accordion-section-content">';
												foreach( $children as $key => $value ){
													echo '<li data-slug="'.$value->slug.'" class="term-item"><span>'.$value->name.'</span></li>';
												}
												echo '</ul>';
											}else{
												echo '<span>'.$term->name.'</span>';
											}
											echo '</li>';
										} ?>
									</ul>
								<?php }*/ 

								// END ALL CATEGORIES
								?>
								<ul class="outer-border">
		                            <li class="control-section accordion-section hide-if-js children"><p class="accordion-section-title hndle" tabindex="0"><span><?php _e('по Львову','deve'); ?></span></p>
		                            <?php if(has_nav_menu('menu')){       
				                            $menu = wp_nav_menu( array( 
				                                'theme_location' => 'menu',
				                                'container' => 'ul',
				                                'menu_class' => 'accordion-section-content',
				                                'echo' => false,
				                                'depth' => 2,
				                                'walker' => new deve_Admin_Terms_Walker()
				                            ) );
				                            echo $menu;
				                        } ?></li>
		                            <?php if( has_nav_menu( 'menu_delivery_ukraine' ) ){ ?>
		                            	<li class="control-section accordion-section hide-if-js children"><p class="accordion-section-title hndle" tabindex="0"><span><?php _e('по Україні','deve'); ?></span></p>
		                            	<?php if(has_nav_menu('menu_delivery_ukraine')){       
				                            $menu_delivery_ukraine = wp_nav_menu( array( 
				                                'theme_location' => 'menu_delivery_ukraine',
				                                'container' => 'ul',
				                                'menu_class' => 'accordion-section-content',
				                                'echo' => false,
				                                'depth' => 2,
				                                'walker' => new deve_Admin_Terms_Walker()
				                            ) );
				                            echo $menu_delivery_ukraine;
				                        } ?>
				                        </li>
		                        	<?php }
		                            if( has_nav_menu( 'product_menu' ) ){ ?>
		                            	<li class="control-section accordion-section hide-if-js children"><p class="accordion-section-title hndle" tabindex="0"><span><?php _e('від Два Кроки','deve'); ?></span></p>
			                            <?php if(has_nav_menu('product_menu')){
					                            $product_menu = wp_nav_menu( array(
					                                'theme_location' => 'product_menu',
					                                'container' => 'ul',
					                                'menu_class' => 'accordion-section-content',
					                                'echo' => false,
					                                'depth' => 2,
					                                'walker' => new deve_Admin_Terms_Walker()
					                            ) );
					                            echo $product_menu;
					                    } ?>
					                    </li>
		                        	<?php } ?>
				                </ul>
							</div>
							<div class="items-list">
								<p class="error-text"><?php _e( 'Products not found', 'deve' ); ?></p>
							</div>
						</div>
					</article>
				</section>
			</div>
		</div>
		<div class="wc-backbone-modal-backdrop modal-close"></div>
		<?php wp_die();
	}

	// Get items by term slug
	public function get_items(){
		$slug = isset( $_POST['slug'] ) ? $_POST['slug'] : '';
		if( !$slug ){ wp_die( __( 'Відсутні всі дані', 'deve' ) ); }

		$args = array(
			'fields' 		 => 'ids',
			'post_type' 	 => 'product',
			'post_status' 	 => 'publish',
			'posts_per_page' => -1,
			'order' 		 => 'DESC',
			'orderby' 		 => 'date',
			'tax_query'		 => array(
				array(
					'field'   => 'id',
					'taxonomy' => 'product_cat',
					'terms'	   => array( $slug )
				)
			),
			/*'meta_query' => array(
		        array(
		            'key' => '_stock_status',
		            'value' => 'instock',
		            'compare' => '=',
		        )
	       	)*/
		);

		$items = get_posts( $args ); ?>
		<div class="items-list">
			<div class="item-wrapper">
				<div class="row row-4-columns row-3-columns row-2-columns">
					<?php if( !empty( $items ) ){
						foreach( $items as $item ){ ?>
							<div class="my-col-33 col-md-4 col-sm-4 item-animation">
								<?php $this->preview( $item ); ?>
							</div>
						<?php }
					}else{
						echo '<p class="error-text">'.__( 'Products not found', 'deve' ).'</p>';
					} ?>
				</div>
			</div>
		</div>
		<?php 
		wp_die();
	}

	// Item preview
	public function preview( $postID, $auto = false ){
	    $decimals 			   = wc_get_price_decimals();
	    $decimal_separator 	   = wc_get_price_decimal_separator();
	    $thousand_separator	   = wc_get_price_thousand_separator();
	    $home_pizza_choose_cat = is_home() ? get_field('home_pizza_choose_cat') : '';

	    $product = wc_get_product( $postID );
	    $type    = $product->get_type();
	    $attrs   = $product->get_attributes();

	    $badge_product_text  = get_field('badge_product_text', $postID);
	    $badge_product_color = get_field('badge_product_color', $postID);
	    $badge_font_size     = get_field('badge_font_size', $postID);
	    $sale_price          = $product->get_sale_price();

	    $variation_id = 0;
	    $attrs_html   = '';
	    $default_attribute = array();
	    $variation = ( $type == 'variable' ? true : false );
	    if ( $type == 'simple' || $type == 'variable' ) {
	        $attrs = $product->get_attributes();
	        if ( $attrs ) {
	            foreach ( $attrs as $attr ) {
	                $values = array();
	                $attr_name = $attr->get_name();
	                if ( $attr->is_taxonomy() && $attr->get_visible() ) {
	                    $attr_values = wc_get_product_terms( $postID, $attr_name, array( 'fields' => 'all' ) );
	                    if ( $attr_values ) {
	                        $count = 1;
	                        foreach ( $attr_values as $attr_value ) {
	                            $values[] = '<div class="select size-pizza '.$attr_value->slug.' '.($count === 1 ? 'active' : '').'" data-variation="'.$variation.'" data-taxonomy="attribute_'.$attr_name.'" data-slug="'.$attr_value->slug.'">'.$attr_value->name.'</div>';
	                            if($count === 1){
	                                $default_attribute['attribute_'.$attr_name] = $attr_value->slug;
	                            }
	                            $count++;
	                        }
	                    }
	                }
	                if ( $values ) {
	                    $attrs_html.= '<div class="product-detail-item ingredients-block">';
	                    $attrs_html.= '<div class="select-item select-price">' . implode( '', $values ) . '</div>';
	                    $attrs_html.= '</div>';
	                }
	            }
	        }
	    }

	    $product_grammar = get_field( 'product_grammar', $postID );
	    if ( $type == 'variable' ) {
	        $data_store        = WC_Data_Store::load( 'product' );
	        $variation_id      = $data_store->find_matching_product_variation( $product, $default_attribute );
	        $product_variation = $variation_id ? $product->get_available_variation( $variation_id ) : false;

	        $purchasable = true;
	        if ( ! $product_variation['is_purchasable'] || ! $product_variation['is_in_stock'] || ! $product_variation['variation_is_visible'] ) {
	            $purchasable = false;
	        }
	        if ( $purchasable ) {
	            $return_price = $product_variation['display_price'];
	        } else {
	            $return_price = $product->get_variation_regular_price( 'min', true );
	        }
	    } else { 
	        $return_price = $product->get_price();
	    }
	    if ( $product_grammar ) {
	        $return_price = number_format( $return_price / 10, $decimals, $decimal_separator, $thousand_separator );
	    }

	    if($type == 'variable' && (!$product->has_child() || !$product->get_price())) return;
	    $link = get_the_permalink($postID);
	    $img  = get_the_post_thumbnail_url($postID);
	    $img = $img ? aq_resize($img, 720, '', true, true, true) : PRODUCT_NO_IMAGE;

	    $product_desc = array_filter( array( get_field( 'product_weight', $postID ), get_field( 'product_volume', $postID ) ) ); 
	    if ( $variation == true ) {
            // get attributes 
            $variation_id = '';
            
            $data_attr_array['product_id'] = $postID;
            foreach($attrs as $attr){
                $attr_name = $attr->get_name();
                /* DELETED */
                // break;
            }
            $variable_product = $product;
            $data_store   = WC_Data_Store::load('product');
            $variation_id = $data_store->find_matching_product_variation($variable_product,$data_attr_array);
            $_variation	  = $variation_id ? $variable_product->get_available_variation($variation_id) : false;
            

            $product_image = '';
            
            if(!empty($_variation['image']['url'])){
                $variation_img = $_variation['image']['url'];
                $img = $variation_img ? aq_resize($variation_img, 555, 550, true, true, true) : $img; 
            }
        }
    	/* DELETED */
	}

	// Item detail
	public function get_item_detail(){
		$postID = isset( $_POST['id'] ) ? $_POST['id'] : '';
		if( !$postID ){ wp_die( __( 'Відсутні всі дані', 'deve' ) ); }
		
		$product = wc_get_product( $postID ); ?>
		<div class="wc-backbone-modal-content single-item-popup">
			<section class="wc-backbone-modal-main" role="main">
				<header class="wc-backbone-modal-header">
					<h1><?php echo $product->get_name(); ?></h1>
					<button class="modal-close modal-close-link dashicons dashicons-no-alt">
						<span class="screen-reader-text">Close modal panel</span>
					</button>
				</header>
				<article>
					<div class="product-detail-wrapper">
			            <div class="row item-animation">
			            	<?php 
			            	$img 		= $product->get_image_id();
							$gallery 	= $product->get_gallery_image_ids();
							$id = $product_id = $postID;
							if($img) array_unshift($gallery,$img);

							$variation = '';
							if($product->is_type( 'variable' )){
								$attrs 	= $product->get_attributes();

								$data_attr_array['product_id'] = $id;
								foreach($attrs as $attr){
									$attr_name = $attr->get_name();
									$attr_values = wc_get_product_terms($id,$attr_name,array('fields' => 'all'));
									foreach ($attr_values as $attr_val) {
										$data_attr_array['attribute_'.$attr_values[0]->taxonomy] = $attr_val->slug;
										break;
									}
									// break;
								}
								$variable_product = wc_get_product(absint($id));
								$data_store   = WC_Data_Store::load('product');
								$variation_id = $data_store->find_matching_product_variation($variable_product,$data_attr_array);
								$variation    = $variation_id ? $variable_product->get_available_variation($variation_id) : false;
								
							}

							$top = $bottom = '';
							if($gallery){
								$i = 1;
								$count = count($gallery);
								foreach($gallery as $id){
									if($i == 1 && !empty($variation['image']['url'])){
										if(!empty($variation['image']['url'])){
								            $variation_img = $variation['image']['url'];
								            $product_image = aq_resize($variation_img, 720, '', true, true, true); 
								        }
								        else{
								            $product_image = wp_get_attachment_image_src( get_post_thumbnail_id( $id ), 'single-post-thumbnail' );
								            $product_image = aq_resize($product_image[0], 720, '', true, true, true); 
								        }
										$top.= '<div class="swiper-slide"><a href="'.$product_image.'" data-fancybox="product-1"><div class="product-img" style="background-image: url('.$product_image.');"></div></a></div>';
										if($count > 1) $bottom.= '<div class="swiper-slide '.($i == 1 ? 'active' : '').'"><div class="product-thumbnail control-top-slider"><div class="bg" style="background-image: url('.$product_image.');"></div></div></div>';
									}
									else{
										$img = wp_get_attachment_url($id);
										if($img){
											//top
											$top.= '<div class="swiper-slide"><a href="'.$img.'" data-fancybox="product-1"><div class="product-img" style="background-image: url('.$img.');"></div></a></div>';

											//bottom
											if($count > 1) $bottom.= '<div class="swiper-slide '.($i == 1 ? 'active' : '').'"><div class="product-thumbnail control-top-slider"><div class="bg" style="background-image: url('.$img.');"></div></div></div>';
								        }
									}
							        $i++;
								}
							} 
							else if($product->is_type( 'variable' )){
								$product_image = '';
								if(!empty($variation['image']['url'])){
							        $variation_img = $variation['image']['url'];
							        $product_image = aq_resize($variation_img, 555, 550, true, true, true); 
							        $top.= '<div class="swiper-slide"><a href="'.$product_image.'" data-fancybox="product-1"><div class="product-img" style="background-image: url('.$product_image.');"></div></a></div>';
							    }
							    else if(!empty(get_post_thumbnail_id( $data['product_id']))){
							        $product_image = wp_get_attachment_image_src( get_post_thumbnail_id( $data['product_id'] ), 'single-post-thumbnail' );
							        $product_image = aq_resize($product_image[0], 555, 550, true, true, true); 
							        $top.= '<div class="swiper-slide"><a href="'.( !$product_image ? PRODUCT_NO_IMAGE : $product_image ).'" data-fancybox="product-1"><div class="product-img" style="background-image: url('.( !$product_image ? PRODUCT_NO_IMAGE : $product_image ).');"></div></a></div>';
							    }
							    else{
							    	$top.= '<div class="swiper-slide"><a href="'.PRODUCT_NO_IMAGE.'" data-fancybox="product-1"><div class="product-img" style="background-image: url('.PRODUCT_NO_IMAGE.');"></div></a></div>';
							    }  
							}
							else {
								if(!empty($img)){
							    	$top.= '<div class="swiper-slide"><a href="'.( !$img ? PRODUCT_NO_IMAGE : $img ).'" data-fancybox="product-1"><div class="product-img" style="background-image: url('.( !$img ? PRODUCT_NO_IMAGE : $img ).');"></div></a></div>';
							    }
							    else{
									//top
									$top.= '<div class="swiper-slide"><div class="slider-big-img" style="background-image: url('.PRODUCT_NO_IMAGE.');"><div class="image-zoom" style="background-image: url('.PRODUCT_NO_IMAGE.');"></div></div></div>';
							    }
							} ?>
							<div class="col-lg-6 col-md-5 ">
								<div class="product-detail-slider-wrapper animation-top-md">
									<div class="swipers-wrapper product-detail-slider">
										<div class="product-top-slider arrow-wrapp hide-arrow">
										<?php if (isset($count) && $count > 1) { ?>
											<div class="swiper-button-prev"><i></i></div>
											<div class="swiper-button-next"><i></i></div>
										<?php } ?>
											<div class="swiper-container custom-slider swiper-control-top" data-pagination-type="bullets" data-effect="fade">	
												<div class="swiper-wrapper">
													<?php echo $top; ?>
												</div>
											<?php if (isset($count) && $count > 1) { ?>
												<div class="swiper-pagination pagination-relative hidden"></div>
											<?php } ?>
											</div>
										</div>
									<?php if($bottom){ ?>
									    <div class="product-bottom-slider arrow-wrapp pos-2">
									    	<div class="swiper-button-prev"><i></i></div>
											<div class="swiper-button-next"><i></i></div>
											<div class="swiper-container custom-slider swiper-control-bottom" data-breakpoints="1" data-xs-slides="2" data-sm-slides="5" data-md-slides="3" data-lg-slides="4" data-lx-slides="5" data-slides-per-view="5" data-space-between="20" data-pagination-type="bullets">
												<div class="swiper-wrapper">
													<?php echo $bottom; ?>
												</div>
												<div class="swiper-pagination pagination-relative hidden xs-visible"></div>
											</div>
										</div>
									<?php } ?>
								  	</div>
								</div>
							</div>
			                <?php
							$title 				 = $product->get_name();
							$product_composition = get_field('product_composition', $product_id);
							$type 				 = $product->get_type();

							$product_grammar = get_field( 'product_grammar', $product_id );
							if ( $type == 'variable' ) {
							    $return_price = $product->get_variation_regular_price( 'min', true );
							} else { 
							    $return_price = $product->get_price();
							}
							if ( $product_grammar ) {
							    $decimals           = wc_get_price_decimals();
							    $decimal_separator  = wc_get_price_decimal_separator();
							    $thousand_separator = wc_get_price_thousand_separator();
							    
							    $return_price = number_format( $return_price / 10, $decimals, $decimal_separator, $thousand_separator );
							} 
							/* DELETED */ ?>
			            </div>
			       	</div>
			    </article>
				<footer>
					<div class="inner">
					</div>
				</footer>
			</section>
		</div>
		<?php wp_die();
	}

	// Get autocomplete items
	public function get_items_autocomplete(){

		$items = get_posts( array(
			'fields'		=> 'ids',
			'post_type'     => array( 'product', 'product_variation' ),
			'post_status'   => 'publish',
			'nopaging'      => true,
			'posts_per_page'=> -1,
			's'             => stripslashes( $_POST['search'] ),
		) );

		/* -- Version for list under search input
		$items = '';
		if ( !empty( $results ) ) {
			foreach ( $results as $postID ) {
				ob_start();
				$this->preview( $postID, 'auto' );
				$items .= ob_get_contents();
				ob_get_clean();
			}
		}else{
			$items = '<tr><td>'.__( 'Products not found', 'deve' ).'</td></tr>';
		}

		echo '<table class="deve-autocomplete-list">'.$items.'</table>';*/ ?>

		<div class="items-list">
			<div class="item-wrapper">
				<div class="row row-4-columns row-3-columns row-2-columns">
					<?php if( !empty( $items ) ){
						foreach( $items as $item ){ ?>
							<div class="my-col-33 col-md-4 col-sm-6 item-animation">
								<?php $this->preview( $item ); ?>
							</div>
						<?php }
					}else{
						echo '<p class="error-text">'.__( 'Products not found', 'deve' ).'</p>';
					} ?>
				</div>
			</div>
		</div>
		<?php wp_die();
	}

	// deve popup
	public function get_deve_popup(){
		$order 	= isset( $_POST['order_id'] ) ? wc_get_order( $_POST['order_id'] ) : '';
		$deve_card = $order ? $order->get_meta( '_deve_card' ) : ''; ?>
		<div class="wc-backbone-modal items-wrapper-popup">
			<div class="wc-backbone-modal-content deve-item-popup">
				<section class="wc-backbone-modal-main" role="main">
					<header class="wc-backbone-modal-header">
						<h1>картка deve</h1>
						<button class="modal-close modal-close-link dashicons dashicons-no-alt">
							<span class="screen-reader-text">Close modal panel</span>
						</button>
					</header>
					<article>
						<?php if( !$deve_card ){ ?>
							<div class="field-wrapper">
								<input class="input-field deve-field-phone" type="tel" name="telephone" placeholder="Ваш телефон" >
								<button type="button" class="button deve-buttons add-deve-phone"><?php esc_html_e( 'Next', 'woocommerce' ); ?></button>
							</div>
							<div class="field-wrapper sms">
								<input class="input-field deve-field-sms" type="text" name="sms" placeholder="Введіть код з СМС" >
								<button type="button" class="button deve-buttons add-deve-sms"><?php esc_html_e( 'Next', 'woocommerce' ); ?></button>
							</div>
						<?php }else{
							$deve 	   = new deve( 'order', $order );
							$discounts = $this->deve_discounts( $order->get_id(), $deve_card );
							$user_bonuses = $order->get_meta( 'user_bonuses' );
							if( is_wp_error( $discounts ) ){
								echo '<div class="field-wrapper error-message">
		                    			<p>'.__( 'The goods processed by you are not available', 'deve' ).'</p>
					                </div>'; 
							}else{

								echo '<div class="field-wrapper">
		                    			<input type="hidden" name="deve_card" value="'.$deve_card.'">
					                    <input class="input-field deve-field-pay" type="number" name="bonuses" placeholder="'.__( 'Enter the amount of bonuses to be written off', 'deve' ).'" max="'.floor( $discounts['proposeBurnAmount'] ).'">
					                    <button type="button" class="button deve-buttons button-add-bonuses">'.__( 'accept', 'deve' ).'</button>
					                </div>'; 
					                if( $user_bonuses ){ ?>
					                	<div class="message">
					                		<span></span>
						                	<div class="field-wrapper">
						                		<p class="bonuses-message"><?php echo sprintf( __( 'Added to cart %s %s.', 'deve' ), $deve->set_format_bonuses( $user_bonuses ), deve_n( __( 'bonus', 'deve' ), __( 'bonuses', 'deve' ), __( 'bonuses', 'deve' ), $user_bonuses ) ); ?></p>
							                    <button type="button" class="button deve-buttons button-remove-bonuses"><?php _e( 'Remove', 'deve' ); ?></button>
							                </div>
							            </div>
						            <?php } ?>
					                <div class="info-balance-inner">
					                    <div class="info-img">
					                        <img src="<?php echo THEME_URI; ?>/img/lock-img.jpg" alt="">
					                    </div>
					                    <div class="card-number"><?php printf( __( 'Card deve %s', 'deve' ), '<b>' . $deve_card . '</b>' ); ?></div>
					                    <div class="card-balance"><?php printf( __( 'Balance: %s', 'deve' ), '<b>' . $deve->set_format_bonuses( floor( $discounts['balanceAfter'] ) ) . ' ' . deve_n( __( 'bonus', 'deve' ), __( 'bonus', 'deve' ), __( 'bonuses', 'deve' ), $discounts['balanceAfter'] ) . '</b>' ); ?></div>
					                    <div class="card-balance"><?php printf( __( 'Available for payment: %s', 'deve' ), '<b>' . $deve->set_format_bonuses( floor( $discounts['proposeBurnAmount'] ) ) . ' ' . deve_n( __( 'bonus', 'deve' ), __( 'bonuses', 'deve' ), __( 'bonuses', 'deve' ), $discounts['proposeBurnAmount'] ) . '</b>' ); ?></div>
					                    <div class="card-desc"><?php printf( __( '* Bonuses write-off is not available for excisable goods', 'deve' ) ); ?></div>
					                </div>
							<?php } 
						} ?>
					</article>
					<footer>
						<div class="inner">
						</div>
					</footer>
				</section>
			</div>
		</div>
		<div class="wc-backbone-modal-backdrop modal-close"></div>
		<?php wp_die();
	}

	// deve auth by phone number & sms code checked
	public function deve_authentication() {
	    $order_id = isset( $_POST['order_id'] ) ? $_POST['order_id'] : '';
	    $phone    = isset( $_POST['phone'] ) ? $_POST['phone'] : '';
	    $authCode = isset( $_POST['authCode'] ) ? $_POST['authCode'] : '';
	    $html     = $bonuses = $pay_bonuses = '';

	    $deve = new deve();

	    if ( $phone ) {
	        $cards_by_mobile = $deve->get_cards_by_mobile( array( 'mobile' => $phone, 'authCode' => $authCode ) );
	        
	        if ( is_wp_error( $cards_by_mobile ) ) {
	            $success = false;

	            if ( $phone && $authCode ) {
	                $html = '<div class="bonuses-info-error"><div class="info-img"><img src="' . THEME_URI . '/img/warning-2.svg" alt=""></div><p>' . __( 'Please check that you have entered the data correctly and try again', 'deve' ) . '</p></div>';
	            } else {
	                $html = '<div class="bonuses-info-error"><div class="info-img"><img src="' . THEME_URI . '/img/warning-2.svg" alt=""></div><p>' . __( 'No user with this phone number was found in the system. Do you want to register?', 'deve' ) . '</p></div><div class="bonuses-info-offer"><a href="https://deve.lviv.ua/a/" target="_blank"><div class="info-img"><img src="' . THEME_URI . '/img/lock-img.jpg" alt=""></div><p>' . sprintf( __( 'Download the application %sdeve', 'deve' ), '<br> ' ) . '</p></a></div>';
	            }
	        } else if ( $phone || $authCode ) {
	            $success = true;
	            if ( $authCode ) {
	                $login = $deve->login( array( 'cardNo' => $cards_by_mobile['cardNo'], 'authCode' => $cards_by_mobile['authCode'] ) );
	                if ( is_wp_error( $login ) ) {
	                    $success = false;
	                    
	                    $html = '<div class="bonuses-info-error"><div class="info-img"><img src="' . THEME_URI . '/img/warning-2.svg" alt=""></div><p>' . $login->get_error_message() . '</p></div><div class="bonuses-info-offer"><a href="https://deve.lviv.ua/a/" target="_blank"><div class="info-img"><img src="' . THEME_URI . '/img/lock-img.jpg" alt=""></div><p>' . sprintf( __( 'Download the application %sdeve', 'deve' ), '<br> ' ) . '</p></a></div>';
	                } else {

	                    $order = wc_get_order( $order_id );
	                    $order->update_meta_data( '_deve_card', $cards_by_mobile['cardNo'] );
	                    $order->update_meta_data( 'deve_card', $cards_by_mobile['cardNo'] );
	                    $order->save();

						$discounts = $this->deve_discounts( $order_id, $cards_by_mobile['cardNo'] );

						ob_start(); 
							/* DELETED */
		                $html = ob_get_contents();
						ob_get_clean();
	                }
	            }
	        }
	    } else {
	        $success = false;
	        $html = '<div class="bonuses-info-error"><div class="info-img"><img src="' . THEME_URI . '/img/warning-2.svg" alt=""></div><p>' . __( 'Invalid phone number.', 'deve' ) . '</p></div>';
	    }


	    echo json_encode( array( 'success' => $success, 'html' => $html ) );

	    die();
	}

	// deve calculate discounts from SPARTA
	public function deve_discounts( $order_id, $deve_card = '' ) {
		

		if( !$order_id ){ 
			return new WP_Error( 'empty_cart', __( 'Empty ID', 'deve' ) );
		}

		$order = wc_get_order( $order_id );

	    if ( ! $order ) {
	        return new WP_Error( 'empty_cart', __( 'Account does not exist', 'deve' ) );
	    }

	    $deve = new deve( 'order', $order );
	    
	    $coupons    = array_keys( $order->get_items( 'coupon' ) );
	    $line_items = $order->get_items( apply_filters( 'woocommerce_admin_order_item_types', 'line_item' ) );
	    $basket     = array();
	    
	    foreach ( $line_items as $cart_item_key => $cart_item ) {
	        $_product = $cart_item->get_product();
	        $id       = $cart_item->get_product_id();

	        //file_put_contents(__DIR__.'/logs/cart_item - '.$cart_item_key.'_.php', var_export($cart_item, true));

	        if ( $_product && $_product->exists() && $cart_item['quantity'] > 0 ) {
	            $sets = array();
	            if ( get_field( 'enabled_sets', $id ) ) {
	                $products_sets = get_field( 'products_sets', $id );
	                if ( $products_sets ) {
	                    foreach ( $products_sets as $set ) {
	                        if ( ! empty( $set['search_product'] ) && ! empty( $set['price'] ) ) {
	                            /* DELETED */
	                        }
	                    }
	                }
	            }

	            if ( $sets ) {

	            	
	            	$discount      = 0;
	                $discount_name = '';
	                if ( $cart_item['line_subtotal'] !== $cart_item['line_total'] ) {
	                    $discount = $cart_item['line_subtotal'] - $cart_item['line_total'];
	                }
	            	
	                foreach ( $sets as $set ) {

	                	
	                	$single_discount = 0;
	                    $set_price       = $set['price'];

	                    if ( $discount > 0 ) {
	                        $single_discount = $discount / count( $sets );

	                        $discounts = array( array(
	                            'source'  => 'POS',
	                            'amount'  => $deve->set_price( $single_discount ),
	                            'percent' => 0,
	                            'code'    => implode( ',', $coupons ),
	                            'name'    => 'Coupons used - ' . implode( ', ', $coupons ),
	                            'order'   => 1,
	                        ) );


	                        if( $single_discount ){
	                            $set_price = $set_price - $single_discount;
	                        }

	                    } else {
	                        $discounts = array();
	                    }
	                    
	                    $basket[] = array(
	                        'productCode'    => $set['sku'] ? $set['sku'] : $set['id'],
	                        'productCode2'   => $cart_item_key,
	                        'quantity'       => 1,
	                        'amountGross'    => $deve->set_price( $set_price ),
	                        'discountGross'  => $single_discount,
	                        'unitPriceGross' => $deve->set_price( $set_price ),
	                        'discounts'      => $discounts,
	                        'skipCB'         => $deve->is_excise( $set['parent'] ? $set['parent'] : $set['id'] ),
	                        'skipRD'         => true
	                    );
	                }
	            } else {
	                $discount      = 0;
	                $discount_name = '';
	                if ( $cart_item->get_subtotal() !== $cart_item->get_total() ) {
	                    $discount = $cart_item->get_subtotal() - $cart_item->get_total();
	                } else if ( $_product->is_on_sale() ) {
	                    $discount      = ( $_product->get_regular_price() - $_product->get_sale_price() ) * $cart_item->get_quantity();
	                    $discount_name = 'Sale price';
	                }
	                
	                if ( $discount > 0 ) {
	                    $discounts = array( array(
	                        'source'  => 'POS',
	                        'amount'  => $deve->set_price( $discount ),
	                        'percent' => 0,
	                        'code'    => ! empty( $discount_name ) ? sanitize_title( $discount_name ) : implode( ',', $coupons ),
	                        'name'    => ! empty( $discount_name ) ? $discount_name : 'Coupons used - ' . implode( ', ', $coupons ),
	                        'order'   => 1,
	                    ) );
	                } else {
	                    $discounts = array();
	                }
	                //file_put_contents(__DIR__.'/item.php', var_export($cart_item, true));
	                $basket[] = array(
	                    'productCode'    => $_product->get_sku() ? $_product->get_sku() : $_product->get_id(),
	                    'productCode2'   => $cart_item_key,
	                    'quantity'       => (int)$cart_item->get_quantity(),
	                    'amountGross'    => $deve->set_price( $_product->get_price() * $cart_item->get_quantity() ),
	                    'discountGross'  => $deve->set_price( $discount ), 
	                    'unitPriceGross' => $deve->set_price( $cart_item->get_subtotal() ),
	                    'discounts'      => $discounts,
	                    'skipCB'         => $deve->is_excise( $id )
	                );
	            }

	        }
	    }
	    if ( $basket ) {
	        $bonuses = $delivery = $pickup = 0;

	        foreach ( $order->get_fees() as $fee_id => $fee ) {
	        	if( !isset( $fee['amount'] ) ){continue;}
	            $amount = $fee['amount'];
	            if ( ! empty( $fee['total'] ) ) {
	                $amount = $fee['total'];
	            }

	            if ( $fee['name'] == 'delivery' ) {
	                $delivery = $amount;
	            } else if ( $fee['name'] == 'bonuses' ) {
	                $bonuses = $amount;
	            } else if ( $fee['name'] == '_pickup' ) {
	                $pickup = abs( $amount );
	            }
	        }
	        $bonuses   = floatval( $bonuses < 0 ? $bonuses * -1 : $bonuses );
	        $delivery = floatval( $delivery < 0 ? $delivery * -1 : $delivery );
	        $pickup   = floatval( $pickup < 0 ? $pickup * -1 : $pickup );

	        // Add item shipping
	        if ( $delivery ) {
	            $basket[] = $deve->add_item_shipping( $delivery );
	        }

	        // Add pickup
	        if ( $pickup ) {
	            $basket = $deve->get_other_discount( $basket, array( 'discount' => $pickup, 'code' => 'delivery-pickup', 'name' => 'Delivery Pickup' ) );
	        }
	    	//file_put_contents(__DIR__.'/basket.php', var_export($basket, true));
	        
	        return $deve->get_discounts( array( 'card' => $deve_card, 'bonuses' => 0, 'basket' => $basket, 'no' => (string)rand( 0, 99999999 ) ) );
	    }

	    return new WP_Error( 'product_not_available', __( 'Goods are not available', 'deve' ) );
	}

	// deve recalculate order after saved items
	public function deve_calculate_saved_items( $order_id, $items ){
		$instance = wc_get_order( $order_id );

		// Coupons re-calculate
        if( $instance->get_coupon_codes() ){
            foreach( $instance->get_coupon_codes() as $coupon_key => $coupon ){
                if( is_string($coupon)) $coupon = new WC_Coupon( $coupon );

                if( $coupon->is_valid() == true ) {
                	$instance->remove_coupon( $coupon );
                }
            }
            $instance->recalculate_coupons();
        }

		$instance->calculate_totals();
		$instance->save();
	}

	// deve calculate fees after order calculate
	public function deve_calculate( $and_taxes = [], $instance ){

		// add sets meta data
	 	$line_items = $instance->get_items();
        foreach( $line_items as $cart_item_key => $cart_item ){

    		$id = $cart_item->get_product_id();
    		$products_sets = get_field( 'products_sets', $id );

			if( $products_sets ){
				$sets = array();
                foreach ( $products_sets as $set ) {
                    if ( ! empty( $set['search_product'] ) && ! empty( $set['price'] ) ) {
                        $sets[ $set['search_product'] ] = array( 'name' => get_the_title( $set['search_product'] ), 'price' => $set['price'] );
                    }
                }
	            if ( $sets ) {
                	$cart_item->add_meta_data( '_sets', $sets );
	            }
            }
        }

		$deve_card  = $instance->get_meta( '_deve_card' );
		$user_bonuses = $instance->get_meta( 'user_bonuses' );
		$delivery    = $instance->get_meta( 'delivery' );

		//file_put_contents(__DIR__.'/delivery.php', var_export($delivery, true));

		// Remove Free Delivery meta data
		$instance->update_meta_data( '_billing_shipping', '' );

		// Pickup delivery
        if( $delivery == 'driveway' ){

        	$fees = $instance->get_fees();
        	if( $fees ){
        		foreach ( $fees as $fee_id => $fee ) {
        			if( $fee['name'] === '_pickup' ){
        				$instance->remove_item( $fee_id );
        			}
        		}
				$instance->save();
        	}

	        $fee_price = 0;
	        $line_items = $instance->get_items( apply_filters( 'woocommerce_admin_order_item_types', 'line_item' ) );
	        foreach( $line_items as $cart_item_key => $cart_item ){

	            $_product = $cart_item->get_product();
        		$id       = $cart_item->get_product_id();

	            $term_list = wp_get_post_terms( $id, 'product_cat', array( 'fields'=>'ids' ) );
	   
	            /* DELETED */
	            
	        }
			
	        if( $fee_price ){
	            $fee = new WC_Order_Item_Fee();
				$fee->set_amount( - $fee_price );
				$fee->set_total( - $fee_price );
				$fee->set_name( sprintf( __( '_pickup', 'deve' ) ) );

				$instance->add_item( $fee );
	        }else{
	        	$fees = $instance->get_fees();
	        	if( $fees ){
	        		foreach ( $fees as $fee_id => $fee ) {
	        			if( $fee['name'] == '_pickup' ){
	        				$instance->remove_item( $fee_id );
	        				break;
	        			}
	        		}
	        	}
	        }
	        $instance->save();
	    }else{

	    	$fees = $instance->get_fees();
	    	if( $fees ){
        		foreach ( $fees as $fee_id => $fee ) {
        			if( $fee['name'] == '_pickup' ){
        				$instance->remove_item( $fee_id );
        				break;
        			}
        		}
        	}
        	$instance->save();
	    }

		$deve = new deve( 'order', $instance );
    	$discounts = $this->deve_discounts( $instance->get_id(), $deve_card );

		if( !empty( $deve_card ) ){ 

	    	// deve Discount
		    if ( ! is_wp_error( $discounts ) && ! empty( $discounts['discountGross'] ) ) {
		        $deve_discount = 0;
		        foreach ( $discounts['basket'] as $item ) {
		            if ( ! empty( $item['discounts'] ) ) {
		                foreach ( $item['discounts'] as $discount ) {
		                    if ( $deve->get_skip_discount( $discount ) ) {
		                        continue;
		                    }
		                    $deve_discount+= $discount['amount'];
		                }
		            }
		        }
		        if ( $deve_discount ) {

		        	$fees = $instance->get_fees();
		        	if( $fees ){
		        		foreach ( $fees as $fee_id => $fee ) {
		        			if( $fee['name'] == 'deve_discount' ){
		        				$instance->remove_item( $fee_id );
		        			}
		        		}
		        	}

		            $fee = new WC_Order_Item_Fee();
					$fee->set_amount( - $deve_discount );
					$fee->set_total( - $deve_discount );
					$fee->set_name( sprintf( __( 'deve_discount', 'deve' ) ) );

					$instance->add_item( $fee );
					$instance->save();

		        }
		    }
		    
		    // deve bonuses
		    if ( ! is_wp_error( $discounts ) ) {
		        $balance = ! empty( $discounts['proposeBurnAmount'] ) ? $discounts['proposeBurnAmount'] : 0;
		        if ( ! empty( $balance ) ) {

		            if ( $balance < $user_bonuses ) {

		            	$instance->update_meta_data( 'user_bonuses', '' );

		                wc_add_notice( sprintf( __( 'You cannot use more than %s %s. bonuses removed from the cart.', 'deve' ), $deve->set_format_bonuses( $balance ), deve_n( __( 'bonus', 'deve' ), __( 'bonuses', 'deve' ), __( 'bonuses', 'deve' ), $balance ) ), 'error' );

		            } else if ( $user_bonuses ) {

		            	/* DELETED */
		            }else{

		            	/* DELETED */
		            }

		        }
		    };
		}

	    // Delivery
	    $free_delivery = $this->deve_delivery_checker( $instance );

	    if ( is_numeric( $delivery ) && $free_delivery == false ) {

	    	$delivery_manual_price = $instance->get_meta( 'delivery_manual_price' );
	    	if( $delivery_manual_price ){
	    		$delivery = $delivery_manual_price;
	    	}

        	$fees = $instance->get_fees();
        	if( $fees ){
        		foreach ( $fees as $fee_id => $fee ) {
        			if( $fee['name'] == 'delivery' ){
        				$instance->remove_item( $fee_id );
        			}
        		}
        	}

            $fee = new WC_Order_Item_Fee();
			$fee->set_amount( $delivery );
			$fee->set_total( $delivery );
			$fee->set_name( sprintf( __( 'delivery', 'deve' ) ) );

			$instance->add_item( $fee );
			$instance->save();
        }else{

        	$fees = $instance->get_fees();
        	if( $fees ){
        		foreach ( $fees as $fee_id => $fee ) {
        			if( $fee['name'] == 'delivery' ){
        				$instance->remove_item( $fee_id );
        			}
        		}
        	}

        	if( ( is_numeric( $delivery ) || $delivery == 'novaposhta' ) && $free_delivery == true ){
				$instance->update_meta_data( '_billing_shipping', 'free' );
        	}
        	$instance->save();
        }
	}

	// deve add bonuses
	public function deve_add_bonuses() {
	    $bonuses   = isset( $_POST['bonuses'] ) ? floor( $_POST['bonuses'] ) : '';
	    $order_id = isset( $_POST['order_id'] ) ? $_POST['order_id'] : '';
	    $order    = $order_id ? wc_get_order( $order_id ) : '';
	    $deve_card = $order ? $order->get_meta( '_deve_card' ) : ''; 


	    $html = '';
	    $discounts = $this->deve_discounts( $order_id, $deve_card );
	    if ( is_wp_error( $discounts ) ) {
	        $success  = false;
	        $message = $discounts->get_error_message();
	    } else {
	        $deve   = new deve( 'order', $order );
	        $balance = ! empty( $discounts['proposeBurnAmount'] ) ? $discounts['proposeBurnAmount'] : 0;
	        $total   = $order->get_subtotal();
	        
	        if ( $total == 0 ) {
	            $success  = false;
	            $message = __( 'bonuses removed from the cart', 'deve' );
	        } else if ( empty( $balance ) || $balance < $bonuses ) {
	            $success = false;
	            /* DELETED */
	        } else {
	            $success = true;
	            if ( $total < $bonuses ) {
	                $bonuses = floor( $total );
	            }
	            
	            $order->update_meta_data( 'user_bonuses', $bonuses );
                $order->save();

	            ob_start(); ?>
		            <div class="field-wrapper">
	            		<p class="bonuses-message"><?php echo sprintf( __( 'Added to cart %s %s.', 'deve' ), $deve->set_format_bonuses( $bonuses ), deve_n( __( 'bonus', 'deve' ), __( 'bonuses', 'deve' ), __( 'bonuses', 'deve' ), $bonuses ) ); ?></p>
	                    <button type="button" class="button deve-buttons button-remove-bonuses"><?php _e( 'Remove', 'deve' ); ?></button>
	                </div>
                <?php $message = ob_get_contents();
				ob_get_clean();
	        }
	    }

	    echo json_encode( array( 'success' => $success, 'message' => $message, 'message2' => '<div class="bonuses-info-error"><div class="info-img"><img src="' . THEME_URI . '/img/warning-2.svg" alt=""></div><p>' . $message . '</p></div>' ) );

	    die();
	}

	// deve remove bonuses
	public function deve_remove_bonuses() {
		$order 		 = isset( $_POST['order_id'] ) ? wc_get_order( $_POST['order_id'] ) : '';
	    $user_bonuses = $order ? $order->get_meta( 'user_bonuses' ) : ''; 

	    if ( $user_bonuses) {
	       /* DELETED */
	    }

	    echo json_encode( array( 'success' => true ) );
	    die();
	}

	// Check delivery for order item
	public function deve_delivery_check( $item ) {
        $valid       = false;
        $product_ids = array( $item->get_variation_id(), $item->get_product_id() );
        
        if ( $this->categories || $this->exclude_categories ) {
            $product_cats = wc_get_product_cat_ids( $item->get_product_id() );
        }

        // Specific products get the discount.
        if ( $this->product_in && count( array_intersect( $product_ids, $this->product_in ) ) ) {
            $valid = true;
        }
        
        // Category discounts.
        if ( $this->categories && count( array_intersect( $product_cats, $this->categories ) ) ) {
            $valid = true;
        }
        
        // No product ids - all items discounted.
        if ( ! $this->product_in && ! $this->categories ) {
            $valid = true;
        }

        // Specific product IDs excluded from the discount.
        if ( $this->exclude_product && count( array_intersect( $product_ids, $this->exclude_product ) ) ) {
            $valid = false;
        }

        // Specific categories excluded from the discount.
        if ( $this->exclude_categories && count( array_intersect( $product_cats, $this->exclude_categories ) ) ) {
            $valid = false;
        }

        return $valid;
    }

    // Check delivery is free
	public function deve_delivery_checker( $order ) {
        $price = 0;
        $array = get_field( 'general_free_delivery', 'options' );
        if ( empty( $array ) ) {
            return false;
        }

        $date_from                = ! empty( $array['date_from'] ) ? strtotime( $array['date_from'] ) : '';
        $date_to                  = ! empty( $array['date_to'] ) ? strtotime( $array['date_to'] ) : '';
        $min_amount               = ! empty( $array['min_amount'] ) ? floatval( $array['min_amount'] ) : '';
        $this->product_in         = ! empty( $array['product_in'] ) ? $array['product_in'] : '';
        $this->exclude_product    = ! empty( $array['exclude_product'] ) ? $array['exclude_product'] : '';
        $this->categories         = ! empty( $array['categories'] ) ? $array['categories'] : '';
        $this->exclude_categories = ! empty( $array['exclude_categories'] ) ? $array['exclude_categories'] : '';
        $total                    = floatval( $order->get_subtotal() );

        $valid_min_amount = $valid_date = $product_check = $coupon_valid = $free_cat = false;

        // Change Total
        if ( $this->product_in || $this->exclude_product || $this->categories || $this->exclude_categories ) {
            $total = 0;
            foreach ($order->get_items() as $item_id => $item ) {
                if ( $this->deve_delivery_check( $item ) ) {
                    $total+= $item->get_subtotal();

                    $product_check = true;
                }
            }
        }

        // Price check
        if ( $min_amount ) {
            if ( ( ! $this->product_in && ! $this->categories ) || $product_check ) {
                $price = $total < $min_amount ? $min_amount - $total : 0;
            }

            if ( $total >= $min_amount ) {
                $valid_min_amount = true;
            }
        }

        // Date check
        if ( $date_from || $date_to ) {
            $current_time = current_time( 'timestamp' );
            if ( $date_from && $date_to ) {
                if ( $date_from < $current_time && $date_to >= $current_time ) {
                    $valid_date = true;
                }
            } else if ( $date_from ) {
                if ( $date_from < $current_time ) {
                    $valid_date = true;
                }
            } else if ( $date_to ) {
                if ( $date_to >= $current_time ) {
                    $valid_date = true;
                }
            }
            if ( $price && ! $valid_date ) {
                $price = 0;
            }
        }

        if ( $valid_min_amount && $valid_date ) {
            $valid = true;
        } else if ( $valid_min_amount && ! $date_from && ! $date_to ) {
            $valid = true;
        } else if ( $valid_date && ! $min_amount ) {
            $valid = true;
        } else {
            $valid = false;
        }

        // Free delivery by Coupon
        if( $order->get_coupon_codes() ){
            foreach( $order->get_coupon_codes() as $coupon_key => $coupon ){
                if( is_string($coupon)) $coupon = new WC_Coupon( $coupon );

                if( $coupon->is_valid() == true && $coupon->get_free_shipping() == true ) {
                    $valid = $coupon_valid = true;
                    break;
                }
            }
        }

         // Free delivery from category
        foreach ( $order->get_items() as $item_id => $item ) {
            
            $cats = wc_get_product_cat_ids( $item->get_product_id() ); 
                
            /* DELETED */
        }

        if ( ( $min_amount || $date_from || $date_to ) && $valid === false ) {
            return $valid;

        }elseif( ( $min_amount || $date_from || $date_to ) && $valid === true ){
	        // Products check
	        foreach ( $order->get_items() as $item_id => $item ) {
	           /* DELETED */
	        }
        }

        if ( ! $free_cat && ! $min_amount && ! $date_from && ! $date_to && ! $this->product_in && ! $this->exclude_product && ! $this->categories && ! $this->exclude_categories && ! $coupon_valid ) {
            $valid = false;
        }

        // true - free delivery
        // false - payment delivery

        return $valid;
    }

    // Get payment delivery cost
	public function wc_admin_delivery_cost(){
		$packages = WC()->shipping->get_packages();
		$package  = reset( $packages );
		$zone     = wc_get_shipping_zone( $package );
		foreach ( $zone->get_shipping_methods( true ) as $k => $method ) {

	    	/* DELETED */
		}
		return false;
	}

	// Get AJAX admin delivery cost for Manual delivery price
	public function wc_admin_update_manual_price(){
		$order = isset( $_POST['order_id'] ) ? wc_get_order( $_POST['order_id'] ) : '';
		$price = isset( $_POST['price'] ) ? trim( $_POST['price'] ) : '';

		if( $order ){
			$order->update_meta_data( 'delivery_manual_price', $price );
			$order->save();
		}
		
		wp_die();
	}

	// Add delivery costs
	public function deve_delivery_costs( $order = '', $delivery = '' ){
		$order    = isset( $_POST['order_id'] ) ? wc_get_order( $_POST['order_id'] ) : $order;
		$delivery = isset( $_POST['delivery'] ) ? $_POST['delivery'] : $delivery;
		$delivery_price = 0; // current delivery price for frontend field

		if( $order ){
			$order->update_meta_data( 'delivery_manual_price', '' ); // remove manual price

			if( $delivery == 'courier' ){

				$delivery_manual_price = $order->get_meta( 'delivery_manual_price' ); // get manual price from order

				$cost = $delivery_manual_price ?: $this->wc_admin_delivery_cost();
				if( $cost != false ){
					/* DELETED */
				}

			}else{
				$delivery_price = 'false';
				$order->update_meta_data( 'delivery', $delivery );
            	$order->save();
			}
		}
		if( wp_doing_ajax() ){
			wp_die( json_encode( array( 'success' => true, 'delivery_price' => $delivery_price ) ) ); 
		}
	}

	// Nova poshta popup
	public function nova_poshta_popup(){
		?>
		<div class="wc-backbone-modal items-wrapper-popup">
			<div class="wc-backbone-modal-content deve-item-popup nova-poshta-popup">
				<section class="wc-backbone-modal-main" role="main">
					<header class="wc-backbone-modal-header">
						<h1>Нова Пошта</h1>
						<button class="modal-close modal-close-link dashicons dashicons-no-alt">
							<span class="screen-reader-text">Close modal panel</span>
						</button>
					</header>
					<article>
						<div class="logo-img-nova-poshta">
							<img src="<?php echo THEME_URI; ?>/img/nova-poshta.png" alt=""/>
						</div>
						<div class="nova-poshta-wrapper">
							<div class="field-wrapper select2">
								<select id="novaposhta_type" name="novaposhta_type" class="SelectBox">
                              		<option value="np_courier"><?php _e('Courier of the New Post', 'deve'); ?></option>
                              		<option value="np_department"><?php _e('At the branch', 'deve'); ?></option>
                        		</select>
							</div>
						</div>
						<div class="nova-poshta-wrapper style-2 department-block">
							<div class="field-wrapper select2">
								<?php 
								$cities_json = file_get_contents( THEME_URI.'/np-cities.json' );
								$new_cities = []; 
								/* DELETED */
								?>
								<input id="novaposhta_city" class="input-field" type="text" data-json="<?php echo wc_esc_json( wp_json_encode( $new_cities ) ); ?>" data-origin-json="<?php echo wc_esc_json( $cities_json ); ?>"name="novaposhta_city" placeholder="<?php _e( 'City', 'deve' ); ?>" >
							</div>
							<div class="field-wrapper select2">
								<select id="novaposhta_department" name="novaposhta_department" disabled class="SelectBoxSearch">
                              		<option value=""><?php _e('Nova Poshta branch', 'deve'); ?></option>
                            	</select>
							</div>
						</div>
						<?php /* DELETED */ ?>
						<div class="field-wrapper button-wrapper">
							<button type="button" class="button button-primary deve-buttons add-nova-poshta"><?php esc_html_e( 'Complete', 'woocommerce' ); ?></button>
						</div>
					</article>
					<footer>
						<div class="inner">
						</div>
					</footer>
				</section>
			</div>
		</div>
		<div class="wc-backbone-modal-backdrop modal-close"></div>
		<?php die();
	}

	// Liqpay 
	public function liqpay_init( $order_id ){
		$order     = wc_get_order( $order_id );
		$payment   = get_post_meta( $order_id,'_billing_payment', true );
		$telephone = get_post_meta( $order_id,'_billing_phone', true );

		if( $payment != 'online' ){ return; }


		$excise_total_price = $total_price_beer = $shipping = 0;

	    foreach( $order->get_items() as $item_id => $item ){
	        $product = $item['data'];

	        if($product){
	            $product_id = ($product->is_type('variation') ? $product->get_parent_id() : $product->get_id());

	            $excise_of_pravda = get_field('excise_of_pravda', $product_id);
	            $excise_product   = get_field('product_akciz', $product_id);

	            if ($excise_product) {
	                $excise_total_price += $item->get_total();
	            }
	            if($excise_of_pravda == 1){
	                $total_price_beer += $item->get_total();
	            }
	        }
	    }

	    $link = /* DELETED */
	    if( $link ){

	    	// Cut LIQPAY link
	    	$isset_link = get_post_meta( $order_id, 'liqpay_admin_link', true );
	    	if( !$isset_link ){
		    	$link = $this->short_link( $link );
				if( !is_wp_error( $link ) ){ 
		    		/* DELETED */
				}else{
					/* DELETED */
				}
	    	}else{
	    		$link = $isset_link;
	    	}

	    	// SMS SUBMIT
	    	$sms = $this->send_sms( $order_id, $telephone, $link );

	    	if ( is_wp_error( $sms ) ){
	    		$order->add_order_note( $sms->get_error_message() );
	    		return [ 'success' => false, 'message' => $sms->get_error_message() ];

	    	}else{
	    		$order->add_order_note( __( 'An SMS was sent to pay for LIQPAY.', 'deve' ) );
		    	return [ 'success' => true, 'message' => $this->sms_error( 'message_id' ) ];
	    	}

	    }else{
	    	/* DELETED */
	    }
	}

	// LiqPay link generate
	public function liqpay_link( $order_id, $excise_total_price, $shipping, $total_price_beer ){
		if ( ! $order_id ) {
	        return false;
	    }

	    $order = wc_get_order( $order_id );
	    if ( ! $order ) {
	        return false;
	    }
	    
	    $amount = $order->get_total();

	    if ( empty( $amount ) ) {
	        return false;
	    }

	    if($total_price_beer && $total_price_beer == $amount - $shipping){
	        $public_key  = get_field( 'liqpay_public_key_akciz_pravda', 'options' );
	        $private_key = get_field( 'liqpay_private_key_akciz_pravda', 'options' );
	    }
	    else if ( $excise_total_price && $excise_total_price == $amount - $shipping) {
	        $public_key  = get_field( 'liqpay_public_key_akciz', 'options' );
	        $private_key = get_field( 'liqpay_private_key_akciz', 'options' );
	    } else if ( ($excise_total_price && ($excise_total_price != $amount - $shipping)) || ($total_price_beer && ($total_price_beer != $amount - $shipping))) {
	        $public_key  = get_field( 'liqpay_public_key_service', 'options' );
	        $private_key = get_field( 'liqpay_private_key_service', 'options' );
	    } else {
	        $public_key  = get_field( 'liqpay_public_key', 'options' );
	        $private_key = get_field( 'liqpay_private_key', 'options' );
	    }

	    if ( empty( $public_key ) || empty( $private_key ) ) {
	        return false;
	    }
	    
	    if ( ! class_exists( 'LiqPay' ) ) {
	        require_once THEME_URL . '/functions/libraries/class-liqpay.php';
	    }

	    update_post_meta( $order_id, 'liqpay_public_key', $public_key );
	    update_post_meta( $order_id, 'liqpay_private_key', $private_key );

	    $liqpay     = new LiqPay( $public_key, $private_key );
	    $result_url = get_the_permalink( PAGE_CHECKOUT_ID );
	    $server_url = home_url( '/index.php?liqpay-response=1' );
	    
	    $LiqPayArgs = array(
	        'version'     => '3',
	        'language'    => 'uk',
	        'result_url'  => $result_url,
	        'server_url'  => $server_url,
	        'sandbox'     => 1,
	        'amount'      => 0.01,
	        'currency'    => 'UAH',
	        'order_id'    => $order_id,
	        'description' => __( 'Order payment №' . $order_id, 'deve' )
    	);

	    if ( ($excise_total_price && ($excise_total_price != $amount - $shipping)) || ($total_price_beer && ($total_price_beer != $amount - $shipping))) {
	        
	        if ($total_price_beer != 0 && $excise_total_price != 0) {
	            $LiqPayArgs['split_rules'] = array(
	                array(
	                    'public_key'        => get_field( 'liqpay_public_key_akciz_pravda', 'options' ),
	                    'amount'            => $total_price_beer,
	                    'commission_payer'  => 'receiver',
	                ),
	                array(
	                    'public_key'       => get_field( 'liqpay_public_key_akciz', 'options' ),
	                    'amount'           => $excise_total_price,
	                    'commission_payer' => "receiver",
	                )
	            );

	            if($shipping != 0 || ($amount != $excise_total_price + $total_price_beer)){
	                $LiqPayArgs['split_rules'][] =
	                    array(
	                        'public_key'       => get_field( 'liqpay_public_key', 'options' ),
	                        'amount'           => $amount - $excise_total_price - $total_price_beer,
	                        'commission_payer' => "receiver",
	                    
	                );
	            }
	        }
	        else if($total_price_beer != 0){
	            $LiqPayArgs['split_rules'] = array(
	                array(
	                    'public_key'        => get_field( 'liqpay_public_key_akciz_pravda', 'options' ),
	                    'amount'            => $total_price_beer,
	                    'commission_payer'  => 'receiver',
	                ),
	                array(
	                    'public_key'       => get_field( 'liqpay_public_key', 'options' ),
	                    'amount'           => $amount - $total_price_beer,
	                    'commission_payer' => "receiver",
	                )
	            );
	        }
	        else if($excise_total_price != 0){
	            $LiqPayArgs['split_rules'] = array(
	                array(
	                    'public_key'        => get_field( 'liqpay_public_key_akciz', 'options' ),
	                    'amount'            => $excise_total_price,
	                    'commission_payer'  => 'receiver',
	                ),
	                array(
	                    'public_key'       => get_field( 'liqpay_public_key', 'options' ),
	                    'amount'           => $amount - $excise_total_price,
	                    'commission_payer' => "receiver",
	                )
	            );
	        }
	    }

	    $link = $liqpay->create_link($LiqPayArgs);
	    return $link;
	}

	// Liqpay admin link field
	public function liqpay_link_field( $order ){
		$link = $order->get_meta( 'liqpay_admin_link' ); 
		if( $link ){ ?>
			</div>
		</div>
			<div class="order_data_column_container">
				<h2 style="text-align: center;font-weight: bold;margin-top: 40px;padding-top:20px;">LiqPay</h2>
				<div class="order_data_column" style="width:100%">
		            <p class="form-field form-field-wide">
		                <label><b><?php _e( 'LIQPAY - link:', 'deve' ); ?></b> <br>(<i><?php _e( 'SMS is sent automatically when creating an order.', 'deve' ); ?></i>)</label>
		                <input type="text" value="<?php echo $link; ?>" name="liqpay_link" readonly>
		                <span class="button liqpay-submit<?php if( !$link ){ echo ' disabled'; } ?>"><?php _e( 'Submit SMS', 'deve' ); ?></span>
		            </p>

		<?php }
	}

	// Pickup delivery addresses
	public function order_pickup_addresses( $item_id, $item, $product ){
		$order_id = wc_get_order_id_by_order_item_id( $item_id );

		if( $order_id ){
			$order 	  = wc_get_order( $order_id );
			$delivery = $order->get_meta( 'delivery' );
			
			if( $delivery == 'driveway' ){

				$delivery_zones = get_field( 'delivery_zones', $item['product_id'] );
		        if ( !empty( $delivery_zones ) ){
		            $array_delivery_zoones[] = $delivery_zones[0];
		        } else {
		            $term_list = wp_get_post_terms( $item['product_id'], 'product_cat', array( 'fields' => 'ids' ) );
		            foreach ( $term_list as $cat_id ) {
		                if ( $cat_id != 123 ) {
		                    $cat_id_item = $cat_id;
		                }
		            }
		            $delivery_zones = get_field( 'delivery_zones', 'product_cat_' . $cat_id_item );
		            if( $delivery_zones ){
		                $array_delivery_zoones[] = $delivery_zones;
		            }
		        }

		        $terms = get_the_terms( $item['product_id'], 'product_cat' );

		        foreach ( $terms as $term ){
		            if ( $term->term_id != 123 ){
		                $cat_id = $term->term_id;
		            }
		        }
		        $delivery_address = get_field( 'select_delivery_address', 'product_cat_' . $cat_id );

		        // Formatting taxonomy
		        if( isset( $delivery_address) and is_array( $delivery_address ) and count( $delivery_address ) > 1 ) {
		            $delivery_address_str = '';
		            foreach ( $delivery_address as $value ) {
		                $delivery_address_str.= $value . '_';
		            }
		            $delivery_address_str = substr( $delivery_address_str, 0, -1 );
		            $delivery_address     = $delivery_address_str;
		        } else {
		            $delivery_address = $delivery_address[0];
		        }
		        
		        // Delivaery addresses array
		        if( $delivery_address ){
			        if ( strpos( $delivery_address, '_' ) > 0 ) {
			            $term_id_array = explode( '_', $delivery_address );
				        
				        if( $term_id_array ){ ?>
				        	<div class="field-wrapper select2 location_product_delivery">
				        		<label><b><?php _e( 'Адреса самовивозу', 'deve' ); ?>:</b></label>
								<select name="delivery-pickup-<?php echo $item_id; ?>" class="SelectBox item-delivery-address">
									<?php foreach( $term_id_array as $key => $term_id ){
										$term = get_term( $term_id, 'delivery_address' );
			                            echo '<option value="'.$term->name.'">' . $term->name . '</option>';
									} ?>
			            		</select>
							</div>
				        <?php }
			        }else{
			        	$term_id = $delivery_address;
			        	$term = get_term( $term_id, 'delivery_address' );
		                if ( $term && ! is_wp_error( $term ) ) { ?>
			                <div class="location_product_delivery">
			                	<label><b><?php _e( 'Адреса самовивозу', 'deve' ); ?>:</b></label>
			                  	<input type="text" disabled readonly class="item-delivery-address" name="delivery-pickup-<?php echo $item_id; ?>" value="<?php echo $term->name; ?>">
			                </div>
		            	<?php }
			        }
		        }

			}

		}
	}

	// Curl request
    private function request( $params = array(), $method = 'POST' ) {
        
        $handle = curl_init();

        $options = array(
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_URL            => $this->sms_url.$this->sms_ident,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER         => false,
            //CURLOPT_PORT           => 443,
        );
        curl_setopt_array( $handle, $options );
        
        switch ( $method ) {
            case 'POST':
                curl_setopt( $handle, CURLOPT_POST, true );
                curl_setopt( $handle, CURLOPT_POSTFIELDS, $params );
            break;
            case 'PUT':
                curl_setopt( $handle, CURLOPT_CUSTOMREQUEST, 'PUT' );
                curl_setopt( $handle, CURLOPT_POSTFIELDS, $params );
            break;
            default:
                curl_setopt( $handle, CURLOPT_CUSTOMREQUEST, $method );
                if ( ! empty( $params ) ) {
                    curl_setopt( $handle, CURLOPT_POSTFIELDS, $params );
                }
            break;
        }

        // cURL expects full header strings in each element.
        $header  = array();
        $headers = array(
            'content-type' => 'application/json',
            'accept'       => 'application/json',
            'authorization' => 'Basic '. base64_encode( $this->sms_login . ':' . $this->sms_pswd )
        );
        foreach ( $headers as $name => $value ) {
            $header[] = "{$name}: $value";
        }
        curl_setopt( $handle, CURLOPT_HTTPHEADER, $header );

        $curl       = curl_exec( $handle );
        $curl_error = curl_errno( $handle );


        // If an error occurred, or, no response.
        if ( $curl_error || empty( $curl ) ) {
            if ( $curl_error = curl_error( $handle ) ) {
                curl_close( $handle );
                return new WP_Error( 'http_request_failed', $curl_error );
            }
            if ( in_array( curl_getinfo( $handle, CURLINFO_HTTP_CODE ), array( 301, 302 ) ) ) {
                curl_close( $handle );
                return new WP_Error( 'http_request_failed', __( 'Too many redirects.' ) );
            }
        }
        curl_close( $handle );

        $response = json_decode( $curl, true );
        if ( ! $response ) {
            return new WP_Error( 'http_request_failed', $curl );
        }
        if( isset( $response['error_code'] ) ){
        	return [ 'success' => false, 'message' => sprintf( __( 'Sorry, an error occurred - %s, please try again.', 'deve' ), $this->sms_error( $response['error_code'] ) ) ];
        }elseif( isset( $response['message_id'] ) ){
        	return [ 'success' => true, 'message' => $this->sms_error( 'message_id' ) ];
        }
        return $response;
    }

	// Send sms
	public function send_sms( $order_id, $telephone, $link = '' ){

		if( !$telephone || !$link ){ return new WP_Error( 'error', __( 'An error has occurred. No link.', 'deve' ) ); }

		// Cut link
		//$link = $this->short_link( $link );
		//if( is_wp_error( $link ) ){ return $link; }

		$text = get_field( 'general_sms_text', 'option' );
		if( $text ){
			$text = str_replace( '%order_id%', $order_id, $text );
			$text = str_replace( '%link%', $link, $text );
		}

		$text = $text ? wp_kses_post( $text ) : sprintf( __( 'Order payment №%s - %s', 'deve' ), $order_id, $link );
		
		$params = /* DELETED */

		$result = $this->request( $params );
		/*file_put_contents( __DIR__ . '/logs_sms/sms.log', "- ".$telephone." -" . current_time( 'd-m-Y H:i:s' ) . "--\nSMS STATUS: " . $result['message'] ."\n\n", FILE_APPEND );*/

		if( isset( $result['success'] ) && $result['success'] == false ){
			return new WP_Error( 'error', $result['message'] );
		}
		return $result;
	}

	// Firebase shortlink
	public function short_link( $link ){

		if( !$link || !$this->firebase_api_key ){ return false; }

		$args = array(
			'dynamicLinkInfo' => array(
				'domainUriPrefix' => '',
        		'link'            => $link
			),
			'suffix' => array(
				'option' => 'SHORT'
			)
		);
		$args = json_encode( $args, JSON_UNESCAPED_SLASHES );

		$defaults = array(
			CURLOPT_POST           => 1,
			CURLOPT_HEADER         => 0,
			CURLOPT_FRESH_CONNECT  => 1,
			CURLOPT_RETURNTRANSFER => 1,
			CURLOPT_FORBID_REUSE   => 1,
			CURLOPT_SSL_VERIFYHOST => 0,
			CURLOPT_SSL_VERIFYPEER => 0,
			CURLOPT_URL => 'https://firebasedynamiclinks.googleapis.com/v1/shortLinks?key=' . $this->firebase_api_key,
			CURLOPT_POSTFIELDS => $args,
			CURLOPT_HTTPHEADER => [ 'Content-Type: application/json' ]
		);
		
		$curl = curl_init();
		curl_setopt_array( $curl, $defaults );
		$result = curl_exec( $curl );
		if ( ! $result ) {
			$error = curl_error( $curl );
			if ( ! is_string( $error ) ) {
				$error = json_decode( $error, true );
			}
			$this->error_logs( $error );
			
			return new WP_Error( 'error', __( 'Error requesting Firebase.', 'deve' ) );
		}
		$result = json_decode( $result, true );

		curl_close( $curl );
		if ( empty( $result['shortLink'] ) ) {
			$this->error_logs( $curl );
			return new WP_Error( 'error', $result['error']['message'] );
		}
		return $result['shortLink'];
	}

	// Liqpay sms
	public function liqpay_sms(){
		$order_id = isset( $_POST['order_id'] ) ? $_POST['order_id'] : false;

		if( $order_id == false ){ wp_die( json_encode( array( 'success' => false, 'message' => __( 'Error. All data is missing.', 'deve' ) ) ) ); }

		$sms = $this->liqpay_init( $order_id ); // Need message reading

        if( $sms['success'] == true ){
        	$liqpay_link = get_post_meta( $order_id, 'liqpay_admin_link', true );
    		echo json_encode( array( 'success' => true, 'liqpay' => $liqpay_link, 'message' => $sms['message'] ) );
        }else{
    		echo json_encode( array( 'success' => false, 'message' => $sms['message'] ) );
        }
        wp_die();
	}

	// Remove downloads order box
	public function remove_shop_order_meta_box(){
		remove_meta_box('woocommerce-order-downloads', 'shop_order', 'normal');
	}

	// Error log
	public function error_logs( $logs = '' ) {
		if ( ! $logs ) {
			return false;
		}

		file_put_contents( __DIR__ . '/logs/errors.log', "--" . current_time( 'd-m-Y H:i:s' ) . "--\nError: " . json_encode( $logs, JSON_UNESCAPED_UNICODE ) . "\n\n", FILE_APPEND );
	}

	// Get SMS error
	public function sms_error( $code ){
		$error = '';
		switch( $code ){
    		
			case '10305':
			case '36365':
				$error = __( 'Message accepted', 'deve' );
				break;

    		case 'message_id':  
				$error = __( 'SMS sent!', 'deve' );
    			break;

    		default:
    			$error = __( 'Error, no SMS sent', 'deve' );
    			break;
    	}
    	return $error;
	}
}
new deveAdmin;

// Walker for sidebar filter
class deve_Admin_Terms_Walker extends Walker_Nav_Menu {

	function start_lvl( &$output, $depth = 0, $args = array() ) {
		// depth dependent classes
		$indent = ( $depth > 0 ? str_repeat( "\t", $depth ) : '' ); // code indent
		$display_depth = $depth + 1; // because it counts the first submenu as 0

		$output .= "\n" . '<ul class="accordion-section-content">'. "\n";

	}

	function end_lvl( &$output, $depth = 0, $args = array() ) {
		$indent = str_repeat( "\t", $depth );
		$output .= "\n" . '</ul>'. "\n";

	}

	// add main/sub classes to li's and links
	function start_el( &$output, $item, $depth = 0, $args = array(), $id = 0 ) {
		$indent = ( $depth > 0 ? str_repeat( "\t", $depth ) : '' ); // code indent
		// depth dependent classes


		$depth_classes = array(
		( $depth == 0 ? 'main-menu-item' : 'sub-menu-item' ),
		( $depth >=2 ? 'sub-sub-menu-item depth-'.$depth : '' ),
		( $depth % 2 ? 'menu-item-odd' : 'menu-item-even' ),
		'menu-item-depth-' . $depth
		);
		$depth_class_names = esc_attr( implode( ' ', $depth_classes ) );


		// passed classes
		$classes = empty( $item->classes ) ? array() : (array) $item->classes;
		$has_children = false;
		if ( in_array( 'menu-item-has-children', $classes ) ) {
		
			$classes[] = 'children';
		}

		$class_names = esc_attr( implode( ' ', apply_filters( 'nav_menu_css_class', array_filter( $classes ), $item ) ) );

		$atts = '';
		if( isset( $item->object_id ) && $item->type == 'taxonomy' ){
			$atts = 'data-slug="'.$item->object_id.'"';
        	$classes[] = 'taxonomy-term';
		}

		// build html
		$output.= $indent . '<li class="term-item control-section accordion-section hide-if-js ' . $class_names . ' term-item" '.$atts.'>';

		$title = apply_filters( 'the_title', $item->title, $item->ID );

		$item_output = $args->before;
		if( in_array( 'menu-item-has-children', $classes ) ){
			$item_output .= '<p class="accordion-section-title hndle" tabindex="0">';
		}
		$item_output .= '<span>'.$args->link_before . apply_filters( 'the_title', $item->title, $item->ID ) . $args->link_after.'</span>';
		if( in_array( 'menu-item-has-children', $classes ) ){
			$item_output .= '</p>';
		}
		$item_output .= $args->after;

		$output .= apply_filters( 'walker_nav_menu_start_el', $item_output, $item, $depth, $args );
	}

	function end_el( &$output, $item, $depth = 0, $args = array() ) {
		$output .='</li>';
	}
}