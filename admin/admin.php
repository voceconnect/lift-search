<?php

class Lift_Admin {

	const OPTIONS_SLUG = '/lift-search';

	public function init() {
		add_action( 'admin_menu', array( $this, 'action__admin_menu' ) );
		add_action( 'admin_init', array( $this, 'action__admin_init' ) );

		//setup AJAX handlers
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX && current_user_can( $this->get_manage_capability() ) ) {
			add_action( 'wp_ajax_lift_domain', array( $this, 'action__wp_ajax_lift_domain' ) );
			add_action( 'wp_ajax_lift_credentials', array( $this, 'action__wp_ajax_lift_credentials' ) );
			add_action( 'wp_ajax_lift_settings', array( $this, 'action__wp_ajax_lift_settings' ) );
			add_action( 'wp_ajax_lift_setting', array( $this, 'action__wp_ajax_lift_setting' ) );
		}

		if ( !Lift_Search::is_setup_complete() ) {
			if ( !isset( $_GET['page'] ) || (isset( $_GET['page'] ) && self::OPTIONS_SLUG != $_GET['page']) ) {
				add_action( 'admin_enqueue_scripts', array( $this, '__admin_enqueue_style' ) );
				add_action( 'user_admin_notices', array( $this, '_print_configuration_nag' ) );
				add_action( 'admin_notices', array( $this, '_print_configuration_nag' ) );
			}
		}
	}

	/**
	 * Returns the capability for managing the admin
	 * @return strings
	 */
	private function get_manage_capability() {
		static $cap = null;

		if ( is_null( $cap ) )
			$cap = apply_filters( 'lift_settings_capability', 'manage_options' );
		return $cap;
	}

	/**
	 * Tests authentication using the access key id and secret key
	 * 
	 * @todo move to a separate class specifically responsible for safely using the API
	 * and formatting friendly results
	 * 
	 * @param string $id
	 * @param string $secret
	 * @return array 
	 */
	private static function test_credentials( $id = '', $secret = '' ) {
		$domain_manager = Lift_Search::get_domain_manager( $id, $secret );
		$error = false;

		if ( $domain_manager->credentials_are_valid() ) {
			$status_message = 'Success';
		} else {
			$status_message = 'There was an error authenticating. Please check your Access Key ID and Secret Access Key and try again.';
			$error = true;
		}

		return array( 'error' => $error, 'message' => $status_message );
	}

	/*	 * ************************   */
	/*             Callbacks          */
	/*	 * ************************   */

	/**
	 * Sets up menu pages
	 */
	public function action__admin_menu() {
		$hook = add_options_page( 'Lift: Search for WordPress', 'Lift Search', $this->get_manage_capability(), self::OPTIONS_SLUG, array( $this, 'callback__render_options_page' ) );
		add_action( $hook, array( $this, 'action__options_page_enqueue' ) );
	}

	public function action__options_page_enqueue() {
		wp_enqueue_script( 'lift-admin', plugins_url( 'js/admin.js', __DIR__ ), array( 'backbone' ), '0.1', true );
		wp_localize_script( 'lift-admin', 'lift_data', array(
			'template_dir' => plugins_url( '/templates/', __FILE__ )
		) );
	}

	public function __admin_enqueue_style() {
		wp_enqueue_style( 'lift-admin', plugins_url( 'css/admin.css', __DIR__ ) );
	}

	/**
	 * Sets up all admin hooks
	 */
	public function action__admin_init() {

		//add option links
		add_filter( 'plugin_row_meta', array( __CLASS__, 'filter__plugin_row_meta' ), 10, 2 );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( __CLASS__, 'filter__plugin_row_meta' ), 10, 2 );
	}

	public function action__wp_ajax_lift_credentials() {
		$current_state = array(
			'accessKey' => Lift_Search::get_access_key_id(),
			'secretKey' => Lift_Search::get_secret_access_key(),
		);



		if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
			var_dump( $_POST );
			$settable_attributes = array( 'domainname', 'batch_interval', 'override_search' );
		}

		$response = json_encode( $current_state );
		die( $response );
	}

	public function action__wp_ajax_lift_setting() {
		if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['model'] ) ) {
			if ( !isset( $_GET['nonce'] ) || !wp_verify_nonce( $_GET['nonce'], 'lift_setting' ) ) {
				status_header( 403 );
				die( json_encode( array( 'error' => array(
							'code' => 'invalid_nonce',
							'message' => 'The request was missing required authentication data.'
						) ) ) );
			}

			$settings_data = json_decode( stripslashes( $_POST['model'] ) );

			$response = array(
				'status' => 'SUCCESS',
				'data' => array( ),
				'errors' => array( )
			);

			$setting_value = $settings_data->value;
			$response['model']['id'] = $setting_key = $settings_data->id;

			$error = new WP_Error();

			if ( isset( $settings_data->nonce ) && wp_verify_nonce( $settings_data->nonce, 'lift_setting' ) ) {

				switch ( $setting_key ) {
					case 'credentials':
						$result = self::test_credentials( $setting_value->accessKey, $setting_value->secretKey );
						if ( $result['error'] ) {
							$error->add( 'invalid_credentials', $result['message'] );
						} else {
							Lift_Search::set_access_key_id( $setting_value->accessKey );
							Lift_Search::set_secret_access_key( $setting_value->secretKey );
						}
						$response['model']['value'] = array(
							'accessKey' => Lift_Search::get_access_key_id(),
							'secretKey' => Lift_Search::get_secret_access_key()
						);
						break;
					case 'batch_interval':
						$value = max( array( 1, intval( $setting_value->value ) ) );
						$unit = $setting_value->unit;
						Lift_Search::set_batch_interval_display( $value, $unit );
						$response['model']['value'] = Lift_Search::get_batch_interval_display();
						break;
				}
			} else {
				$error->add( 'invalid_nonce', 'The request was missing required authentication data.' );
			}

			if ( count( $error->get_error_codes() ) ) {

				foreach ( $error->get_error_codes() as $code ) {
					$response['errors'][] = array( 'code' => $code, 'message' => $error->get_error_message( $code ) );
				}
				status_header( 400 );
				header('Content-Type: application/json');
				$response['status'] = 'FAILURE';
			}
			die( json_encode( $response ) );
		}
	}

	public function action__wp_ajax_lift_settings() {

		$current_state = array(
			'credentials' => array(
				'accessKey' => Lift_Search::get_access_key_id(),
				'secretKey' => Lift_Search::get_secret_access_key(),
			),
			'domainname' => Lift_Search::get_search_domain_name(),
			'last_sync' => Lift_Batch_Handler::get_last_cron_time(),
			'next_sync' => Lift_Batch_Handler::get_next_cron_time(),
			'batch_interval' => Lift_Search::get_batch_interval_display(),
			'override_search' => Lift_Search::get_override_search(),
			'setup_complete' => Lift_Search::is_setup_complete(),
			'nonce' => wp_create_nonce( 'lift_setting' ),
		);

		$c_state = array( );
		foreach ( $current_state as $id => $value ) {
			$c_state[] = array( 'id' => $id, 'value' => $value );
		}
		$current_state = $c_state;

		$response = json_encode( $current_state );
		die( $response );
	}

	public function action__wp_ajax_lift_domain() {
		$dm = Lift_Search::get_domain_manager();
		$domain_name = Lift_Search::get_search_domain_name();
		$domain = $dm->get_domain( $domain_name );
		$response = json_encode( array(
			'domainname' => $domain_name,
			'domain' => $domain,
			'error' => '????'
			) );
		die( $response );
	}

	/**
	 * Add link to access settings page on Plugin mainpage
	 * @param array $links
	 * @param string $page
	 * @return array 
	 */
	public function filter__plugin_row_meta( $links, $page ) {
		if ( $page == self::OPTIONS_SLUG ) {
			$links[] = '<a href="' . admin_url( 'options-general.php?page=' . self::OPTIONS_SLUG ) . '">Settings</a>';
		}
		return $links;
	}

	public function callback__render_options_page() {
		?>
		<div class="wrap lift-admin" id="lift-status-page">
		</div>

		<?php
	}

	public static function _print_configuration_nag() {
		?>
		<div id="banneralert" class="lift-colorized">
			<div class="lift-balloon">
				<img src="<?php echo plugin_dir_url( __DIR__ ) ?>img/logo.png" alt="Lift Logo">
			</div>
			<div class="lift-message"><p><strong>Welcome to Lift</strong>: 	Now that you've activated the Lift plugin it's time to set it up. Click below to get started. </p></div>
			<div><a class="lift-btn" href="<?php echo admin_url( 'options-general.php?page=' . self::OPTIONS_SLUG ) ?>">Configure Lift</a></div>
			<div class="clr"></div>
		</div>
		<script>
			jQuery(document).ready(function($) {
				var $bannerAlert = $('#banneralert');
				if ($bannerAlert.length) {
					$('.wrap h2').first().after($bannerAlert);
				}
			});
		</script>
		<?php
	}

}