<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if (!class_exists('Theme_Auth')) {

	class Theme_Auth
	{
		static $user = null;
		const MAX_ATTEMPTS = 5;
		const CODE_LIFETIME = 15;//mins
		const ATTEMPTS_INTERVAL = 1; //mins
		static $account_page = null;
		static $privacy_page = null;


		public function __construct()
		{
			if ( THEME_IS_LOGIN ) {
				self::$user = wp_get_current_user();
				self::$user->display_name = self::$user->first_name.' '.self::$user->last_name;
				self::$user->middle_name = get_user_meta( self::$user->ID, 'billing_middle_name', true );
				self::$account_page = get_permalink( THEME_ACCOUNT_PAGE_ID );
			}else{
				self::$privacy_page = THEME_PRIVACY_PAGE_ID ? THEME_PRIVACY_PAGE_ID : get_option( 'wp_page_for_privacy_policy' );
			}

			add_action('wp_ajax_nopriv_register_user', ['Theme_Auth', 'register']);
			add_action('wp_ajax_nopriv_register_phone', ['Theme_Auth', 'register_phone']);
			add_action('wp_ajax_nopriv_login_email', ['Theme_Auth', 'login_by_email']);
			add_action('wp_ajax_nopriv_login_phone', ['Theme_Auth', 'login_by_phone']);
			add_action('wp_ajax_nopriv_send_code', ['Theme_Auth', 'repeat_send_code']);

			add_action('wp_ajax_nopriv_forgot_password', ['Theme_Auth', 'forgot_password']);
			add_action('wp_ajax_nopriv_check_password_code', ['Theme_Auth', 'check_password_code']);
			add_action('wp_ajax_nopriv_change_password', ['Theme_Auth', 'change_password']);

			add_action('wp_ajax_forgot_password', ['Theme_Auth', 'forgot_password']);
			add_action('wp_ajax_check_password_code', ['Theme_Auth', 'check_password_code']);
			add_action('wp_ajax_change_password', ['Theme_Auth', 'change_password']);
		}

		public static function repeat_send_code(){

			if (!check_ajax_referer('Theme_ajax_security', 'nonce', false)
				|| !isset( $_POST['phone'] ) || !$_POST['phone'] ) {
				die(__('Помилка надсилання коду', 'theme'));
			}

			$phone = sanitize_text_field($_POST['phone']);

			$code = self::send_code($phone);
			if (!$code) die(__('Помилка надсилання коду', 'theme'));
			$_SESSION['code'] = $code;

			wp_send_json([
				'success' => true,
				'open_popup' => '#registration-code-popup',
				'phone' => $_POST['phone'],
				'code' => $code
			]);
		}

		public static function send_code($phone, $text = null)
		{
			if (!$text) $text = __('Код підтвердження', 'theme');
			$code = '' . rand(1000, 9999);
			$result = $code;//Theme_Helpers::send_sms($phone, $text . ': ' . $code);
			return $result ? $code : false;
		}

		public static function register_phone()
		{
			if (
				!check_ajax_referer('theme_ajax_security', 'nonce', false)
				|| empty($_POST['phone'])
			) {
				die(__('Відсутні необхідні дані.', 'theme')); // Помилка реєстрації.
			}
			if (session_status() !== PHP_SESSION_ACTIVE) session_start();

			$phone = sanitize_text_field($_POST['phone']);

			if (get_user_by('login', Theme_Helpers::tel($phone))) {
				wp_send_json([
					'success' => false,
					'input' => 'phone',
					'input_message' => __('Телефон вже існує.', 'theme') // Помилка реєстрації.
				]);
			}

			if (empty($_POST['code'])) {
				$code = self::send_code($phone);
				if (!$code) die(__('Помилка надсилання коду', 'theme'));
				$_SESSION['code'] = $code;

				wp_send_json([
					'success' => true,
					'open_popup' => '31',
					'phone' => $_POST['phone'],
					'code' => $code
				]);
			} else {
				$code = str_replace(' ', '', $_POST['code']);

				if ($code != $_SESSION['code']) {
					wp_send_json([
						'success' => false,
						'input' => 'code',
						'input_message' => __('Невірний код', 'theme')
					]);
				};
			}

			wp_send_json([
				'success' => true,
				'open_popup' => '32',
				'phone' => $_POST['phone'],
				//'redirect' => Theme_Account::$page_url
			]);
		}

		public static function register()
		{
			if (
				/*!check_ajax_referer('theme_ajax_security', 'nonce', false)
				||*/ empty($_POST['first_name'])
				|| empty($_POST['email'])
				|| empty($_POST['phone'])
				|| empty($_POST['password'])
				|| empty($_POST['password2'])
			) {
				die(__('Відсутні необхідні дані.', 'theme')); // Помилка реєстрації.
			}
			if (session_status() !== PHP_SESSION_ACTIVE) session_start();

			if (!isset($_POST['agree'])) {
				wp_send_json([
					'success' => false,
					'input' => 'agree',
				]);
			}

			$password = sanitize_text_field(trim($_POST['password']));
			$password2 = sanitize_text_field(trim($_POST['password2']));
			if ($password != $password2) {
				wp_send_json([
					'success' => false,
					'input' => 'password2',
					'input_message' => __('Підтвердження паролю не співпадає.', 'theme') // Помилка реєстрації.
				]);
			}

			$email = sanitize_email($_POST['email']);
			$phone = Theme_Helpers::tel(sanitize_text_field($_POST['phone']));
			$first_name = sanitize_text_field($_POST['first_name']);

			if (get_user_by('email', $email)) {
				wp_send_json([
					'success' => false,
					'input' => 'email',
					'input_message' => __('Email вже існує.', 'theme') // Помилка реєстрації.
				]);
			}

			if (!empty($email) and !empty($first_name) and !empty($password)) {
				$user_data = array(
					'user_pass' => $password,
					'user_login' => $email,
					'user_email' => $email,
					'user_nicename' => $phone,
					'first_name' => $first_name,
					'display_name' => ( $first_name ? $first_name :__('Клієнт', 'theme') ),
				);
				$creds = array();
				$creds['user_login'] = $email;
				$creds['user_password'] = $password;
				$creds['remember'] = true;



				$user_id = wp_insert_user($user_data);

				if ( !is_wp_error( $user_id ) ) {
					update_user_meta( $user_id, 'billing_email', $email );
					update_user_meta( $user_id, 'billing_first_name', $first_name );
					update_user_meta( $user_id, 'billing_phone', $phone );

					// Add additional bonuses to user balance
					$register_balance = get_field( 'register_balance', 'options' );
					if( $register_balance ){
						update_field( 'customer_balance', $register_balance, 'user_'.$user_id );
					}

					self::register_email( $email, ( $first_name ? $first_name :__('Клієнт', 'theme') ) );
					wp_signon($creds, false);

				} else {
					die( $user_id->get_error_message() );
				}

				wp_send_json([
					'success' => true,
					'open_popup' => '31',
					//'redirect' => Theme_Account::$page_url
				]);
			} else {
				die(__('Помилка в даних користувача.', 'theme')); // Помилка реєстрації.
			}
		}

		public static function register_email($email, $name, $is_checkout = false)
		{

			$from = $admin_email = get_field('register_email', 'options');

			$from_name = THEME_BLOG_INFO;
			if (!(isset($from) && is_email($from))) {
				$from = 'do-not-reply@' . $from_name;
			}
			$to = sanitize_email($email);
			$subject = esc_html__('Реєстрація', 'theme');
			$sender = 'From: ' . $from_name . ' <' . $from . '>' . "\n\r";

			$message = esc_html__('Вітаємо ', 'theme') . ', <b>' . $name . '</b><br>' . sprintf( __('Ваша реєстрація на сайті %s успішно завершена ', 'theme'), $from_name );
			$message .= '<br>Зайдіть на сайт і увійдіть у свій обліковий запис, щоб насолоджуватися всіма перевагами сайту.';

			if ($is_checkout) $message .= '<br>' . __('Зайдіть на сайт і увійдіть у свій обліковий запис, щоб насолоджуватися всіма перевагами сайту.', 'theme');
			
			ob_start();
				get_template_part('template-parts/email-template', null, ['body'=>$message]);
			$message = ob_get_clean();

			$headers = [
				'Content-Type: text/html; charset=UTF-8',
				$sender
			];

			$mail = wp_mail($to, $subject, $message, $headers);

			if (!$mail) {
				die(__('Помилка відправки листа', 'theme'));
			}

			if (empty($admin_email)) return;

			$message = esc_html__('Користувач ', 'theme') . '<b>' . $name . '</b> (' . $email . ')<br>' . esc_html__('зареєтрувася на сайті ', 'theme') . $from_name;

			ob_start();
				get_template_part('template-parts/email-template', null, ['body'=>$message]);
			$message = ob_get_clean();

			$mail = wp_mail($admin_email, $subject, $message, $headers);

			if (!$mail) {
				die(__('Помилка відправки листа', 'theme'));
			}

		}

		public static function login_by_email()
		{
			if (!check_ajax_referer('theme_ajax_security', 'nonce', false)
				|| empty($_POST['email'])
				|| empty($_POST['password'])) {
				die('Помилка даних.');
			}

			$info = [];
			$info['user_login'] = sanitize_text_field($_POST['email']);
			$info['user_password'] = sanitize_text_field($_POST['password']);
			$info['remember'] = true;

			$user = wp_signon($info, false);
			if (is_wp_error($user)) {
				wp_send_json([
					'success' => false,
					'input' => 'password',
					'input_message' => __('Помилка входу. Вказано некоректний логін/пароль.', 'theme')
				]);

			} else {
				wp_set_current_user($user->ID);

				wp_send_json([
					'success' => true,
					'redirect' => Theme_Account::$page_url
				]);
			}
		}

		public static function login_by_phone()
		{
			if (!check_ajax_referer('theme_ajax_security', 'nonce', false)
				|| empty($_POST['phone'])
			) {
				die('Помилка даних.');
			}

			if (session_status() !== PHP_SESSION_ACTIVE) session_start();

			$reload_page = $_POST['reload_page'] ?? false;

			$phone = sanitize_text_field($_POST['phone']);
			$nicename = Theme_Helpers::phone_to_slug($phone);

			$user = get_user_by('login', $nicename);

			if (!$user) {
				wp_send_json([
					'success' => false,
					'input' => 'phone',
					'input_message' => __('Помилка входу. Вказано некоректний телефон.', 'theme')
				]);
			}

			/*if (empty($_POST['code'])) {
				$code = self::send_code($phone);

				if (!$code) die(__('Помилка надсилання коду', 'theme'));
				$_SESSION['code'] = $code;

				wp_send_json([
					'success' => true,
					'open_popup' => '#login-code-popup',
					'phone' => $_POST['phone'],
					'code' => $code
				]);
			} else {
				$code = str_replace(' ', '', $_POST['code']);

				if ($code != $_SESSION['code']) {
					wp_send_json([
						'success' => false,
						'input' => 'code',
						'input_message' => __('Невірний код', 'theme')
					]);
				};
			}*/


			wp_clear_auth_cookie();
			wp_set_current_user($user->ID);
			wp_set_auth_cookie($user->ID);

			wp_send_json([
				'success' => true,
				'redirect' => ( !$reload_page ? Theme_Account::$page_url : 'reload' )
			]);
		}


		public static function check_phone_code_attempts($user_id)
		{
			$password_change_attempts = (int)get_user_meta($user_id, 'password_change_attempts', true);
			$password_change_code_time_start = (int)get_user_meta($user_id, 'password_change_code_time_start', true);
			$password_change_code_time = (int)get_user_meta($user_id, 'password_change_code_time', true);
			$now = current_time('timestamp');

			if ($password_change_code_time_start && $now - $password_change_code_time_start <= 86400 && $password_change_attempts >= self::MAX_ATTEMPTS) return false;
			if ($now - $password_change_code_time < self::ATTEMPTS_INTERVAL * 60) return false;
			if ($now - $password_change_code_time_start > 86400) {
				update_user_meta($user_id, 'password_change_code_time_start', 0);
				update_user_meta($user_id, 'password_change_attempts', 0);
			}

			return true;
		}


		public static function forgot_password()
		{
			if (!check_ajax_referer('theme_ajax_security', 'nonce', false) ||
				( empty( $_POST['email'] ) && ( THEME_IS_LOGIN && !isset( $_POST['user_id'] ) ) ) ){
				die(__('Помилка. Відсутні необхідні дані.', 'theme'));
			}

			if( isset( $_POST['user_id'] ) && $_POST['user_id'] ){
				$user = get_user_by('id', $_POST['user_id']);

				if( $user ){

					$password = trim(sanitize_text_field($_POST['password']));
					$password2 = trim(sanitize_text_field($_POST['password2']));
					if($password !== $password2) die('Невірне підтвердження ');

					$user_id = wp_update_user([
						'ID' => $user->ID,
						'user_pass' => $password,
					]);

					if (is_wp_error($user_id)) {
						die( $user_id->get_error_message());
					}
					wp_send_json([
						'success' => true,
						'open_popup' => '51',
					]);
				}else{
					die(__('Помилка. Невірні дані', 'theme'));
				}
			} 

			$email = isset($_POST['email']) ? sanitize_text_field(trim($_POST['email'])) : null;
			$email = Theme_Helpers::phone_to_slug($email);


			$user = null;
			if ($email) {

				if( self::$user ){
					$user = get_user_by('login', $email);
					if( self::$user->ID != $user->ID ){
						die(__('Невірний e-mail', 'theme') );
					}
				}

				if (session_status() !== PHP_SESSION_ACTIVE) session_start();

				if (empty($_POST['code'])) {

					$user = get_user_by('login', $email);
					$code = rand(1000, 9999);

					if (!$user) {
						wp_send_json([
							'success' => false,
							'input' => 'email',
							'input_message' => __('Помилка. Невідомий e-mail.', 'theme')
						]);
					}

					$send = self::send_email( $email, __( 'Код підтвердження', 'theme' ), sprintf( __( 'Для відновлення паролю введіть наступний код: %s', 'theme' ), $code ) );

					if (!$send) die(__('Помилка надсилання коду', 'theme'));
					$_SESSION['code'] = $code;

					wp_send_json([
						'success' => true,
						'open_popup' => '41',
						'email' => $_POST['email'],
						'code' => $code
					]);
				} else {
					$code = str_replace(' ', '', $_POST['code']);

					if ($code != $_SESSION['code']) {
						wp_send_json([
							'success' => false,
							'input' => 'code',
							'input_message' => __('Невірний код', 'theme')
						]);
					};
				}

				$user = get_user_by('login', $email);

				wp_send_json([
					'success' => true,
					'open_popup' => '5',
					'email' => $_POST['email'],
					'user_id' => $user->ID
				]);
				

				/*if (!self::check_phone_code_attempts($user->ID)) {
					wp_send_json([
						'success' => false,
						'input' => 'phone',
						'input_message' => __('Зміна пароля наразі заблокована', 'theme')
					]);
				}*/

			} else {
				die(__('Помилка. Невірні дані', 'theme'));
			}

			die(__('Помилка. Спробуйте ще раз.', 'theme'));
		}

		public static function check_password_code()
		{
			if (!check_ajax_referer('theme_ajax_security', 'nonce', false)
				|| empty($_POST['code'])
				|| empty($_POST['user_id'])
			) {
				die(__('Помилка. Відсутні необхідні дані.', 'theme'));
			}

			$user_id = (int)$_POST['user_id'];
			$user = get_userdata($user_id);
			if (!$user) {
				wp_send_json([
					'success' => false,
					'input' => 'code',
					'input_message' => __('Помилка. Невідомий користувач.', 'theme')
				]);
			}

			$code = (int)get_user_meta($user_id, 'password_change_code', true);
			$password_change_code_time = (int)get_user_meta($user->ID, 'password_change_code_time', true);

			if ($code != (int)str_replace(' ', '', $_POST['code']) || current_time('timestamp') - $password_change_code_time > self::CODE_LIFETIME * 60) {
				wp_send_json([
					'success' => false,
					'input' => 'code',
					'input_message' => __('Помилка. Невірний або протермінований код.', 'theme')
				]);
			}

			$key = get_password_reset_key($user);

			wp_send_json([
				'success' => true,
				'login' => $user->user_login,
				'key' => $key,
				'open_popup' => '5',
			]);

		}

		public static function change_password()
		{
			if (!check_ajax_referer('theme_ajax_security', 'nonce', false)
				|| empty($_POST['key'])
				|| empty($_POST['login'])
				|| empty($_POST['password'])
				|| empty($_POST['password2'])) {
				die(__('Помилка. Відсутні необхідні дані.', 'theme'));
			}

			$key = sanitize_text_field($_POST['key']);
			$password = sanitize_text_field($_POST['password']);
			$password2 = sanitize_text_field($_POST['password2']);
			$login = sanitize_text_field($_POST['login']);

			if ($password != $password2) {
				wp_send_json([
					'success' => false,
					'input' => 'password2',
					'input_message' => __('Невірне підтвердження паролю', 'theme')
				]);
			}

			$user = check_password_reset_key($key, $login);

			if (!empty($user) and !is_wp_error($user)) {

				reset_password($user, $password);

				if( self::$user ){
					$creds = array();
					$creds['user_login'] = $login;
					$creds['user_password'] = $password;
					$creds['remember'] = true;

					wp_signon($creds, false);
				}

				wp_send_json([
					'success' => true,
					'open_popup' => '#change-password-ok-popup',
				]);
			} else {
				die(__('Користувач відсутній або час дії коду вичерпано', 'theme'));
			}

		}

		static function send_email( $email, $subject = '', $message = '' ){

			$from_name = THEME_BLOG_INFO;
			if (!(isset($from) && is_email($from))) {
				$from = 'do-not-reply@' . $from_name;
			}

			$to = sanitize_email($email);
			$subject = ( $subject ? $subject : esc_html__('Реєстрація на ', 'theme') );
			$sender = 'From: ' . $from_name . ' <' . $from . '>' . "\n\r";


			if( !$message ){
				$message = esc_html__('Вітаємо ', 'theme') . ', <b>' . $name . '</b><br>' . sprintf( __('Ваша реєстрація на сайті %s успішно завершена ', 'theme'), $from_name );
				//$message .= '<br>' . __('Будь ласка, створіть пароль через сервіс відновлення паролю', 'theme');
			}
			
			ob_start();
				get_template_part('template-parts/email-template', null, ['body'=>$message]);
			$message = ob_get_clean();

			$headers = [
				'Content-Type: text/html; charset=UTF-8',
				$sender
			];

			$mail = wp_mail($to, $subject, $message, $headers);
			return $email;
		}


	}


	new Theme_Auth();
}


