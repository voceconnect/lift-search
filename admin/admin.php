<?php

class Lift_Admin {
	/**
	 * Page name used for admin setup 
	 */

	const LANDING_PAGE = 'lift-search/admin/setup.php';

	/**
	 * Page name used for admin status 
	 */
	const STATUS_PAGE = 'lift-search/admin/status.php';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, '_setup_management_pages' ) );
		add_action( 'admin_init', array( __CLASS__, '_admin_init' ) );
	}

	public function _admin_init() {


		add_filter( 'plugin_row_meta', array( __CLASS__, 'settings_link' ), 10, 2 );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( __CLASS__, 'settings_link' ), 10, 2 );

		//setup AJAX handlers
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			add_action( 'wp_ajax_lift_test_access', array( __CLASS__, '_ajax_test_and_save_credentials' ) );
			add_action( 'wp_ajax_lift_test_domain', array( __CLASS__, '_ajax_test_domain' ) );
			add_action( 'wp_ajax_lift_delete_error_logs', array( __CLASS__, '_ajax_delete_error_logs' ) );
			add_action( 'wp_ajax_lift_update_cron_interval', array( __CLASS__, '_ajax_update_cron_interval' ) );
			add_action( 'wp_ajax_lift_create_domain', array( __CLASS__, '_ajax_create_domain' ) );
			add_action( 'wp_ajax_lift_set_cron_status', array( __CLASS__, '_ajax_set_cron_status' ) );
		}

		if ( isset( $_GET['page'] ) && self::STATUS_PAGE == $_GET['page'] ) {

			// require landing page to be filled out
			if ( !isset( $_GET['lift-setup-complete'] ) && !Lift_Search::is_setup_complete() ) {
				wp_redirect( admin_url( 'options-general.php?page=' . self::LANDING_PAGE ) );
			}

			// send IndexDocuments request
			if ( current_user_can( 'manage_options' ) && isset( $_GET['lift-indexdocuments'] ) ) {
				Cloud_Config_Request::IndexDocuments( Lift_Search::get_search_domain() );
				wp_redirect( admin_url( 'options-general.php?page=' . self::STATUS_PAGE ) );
			}

			// send next batch
			if ( current_user_can( 'manage_options' ) && isset( $_GET['sync-queue'] ) ) {
				Lift_Batch_Queue::send_next_batch();
				wp_redirect( admin_url( 'options-general.php?page=' . self::STATUS_PAGE ) );
			}
		}

		if ( !Lift_Search::is_setup_complete() ) {
			if ( !isset( $_GET['page'] ) || (isset( $_GET['page'] ) && self::LANDING_PAGE != $_GET['page']) ) {
				add_action( 'admin_enqueue_scripts', array( __CLASS__, '_enqueue_style' ) );
				add_action( 'user_admin_notices', array( __CLASS__, '_print_configuration_nag' ) );
				add_action( 'admin_notices', array( __CLASS__, '_print_configuration_nag' ) );
			}
		}
	}

	/**
	 * Add link to access settings page on Plugin mainpage
	 * @param array $links
	 * @param string $page
	 * @return array 
	 */
	public static function settings_link( $links, $page ) {
		if ( $page == plugin_basename( __FILE__ ) ) {
			$links[] = '<a href="' . admin_url( 'options-general.php?page=' . Lift_Admin::LANDING_PAGE ) . '">Settings</a>';
		}
		return $links;
	}

	public static function _enqueue_style() {
		wp_enqueue_style( 'lift-search-admin', plugins_url( 'sass/admin.css', __DIR__ ) );
	}

	public static function _print_configuration_nag() {
		?>
		<div id="banneralert" class="lift-colorized">
			<div class="lift-balloon">
				<img src="<?php echo plugin_dir_url( __DIR__ ) ?>img/logo.png" alt="Lift Logo">
			</div>
			<div class="lift-message"><p><strong>Welcome to Lift</strong>: 	Now that you've activated the Lift plugin it's time to set it up. Click below to get started. </p></div>
			<div><a class="lift-btn" href="<?php echo admin_url( 'options-general.php?page=' . self::LANDING_PAGE ) ?>">Configure Lift</a></div>
			<div class="clr"></div>
		</div>
		<script>
			jQuery(document).ready(function($) {
				var $bannerAlert = $('#banneralert');
				if ( $bannerAlert.length ) {
					$('.wrap h2').first().after($bannerAlert);
				}
			});
		</script>
		<?php
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

		$credentials = array( 'access-key-id' => $id, 'secret-access-key' => $secret );
		$error = false;

		try {
			if ( Cloud_Config_Request::TestConnection( $credentials ) ) {
				$status_message = 'Success';
			} else {
				$status_message = 'There was an error authenticating. Please check your Access Key ID and Secret Access Key and try again.';

				$error = true;
			}
		} catch ( Exception $e ) {
			// @todo add exception logging?
			$status_message = 'There was an error authenticating your access keys. Please try again.';

			$error = true;
		}

		return array( 'error' => $error, 'message' => $status_message );
	}

	/**
	 * Called after setup is complete to enable the batch cron and 
	 * queue all current content 
	 */
	private static function _complete_setup() {
		Lift_Batch_Queue::init();
		// mark setup complete, enable cron and queue all posts
		update_option( Lift_Search::INITIAL_SETUP_COMPLETE_OPTION, 1 );
		Lift_Batch_Queue::enable_cron();
		Lift_Batch_Queue::queue_all();
	}

	/**
	 * Setup settings in admin
	 * @method add_settings
	 */
	public static function _setup_management_pages() {
		$capability = apply_filters( 'lift_settings_capability', 'manage_options' );

		add_options_page( 'Lift: Search for WordPress', 'Lift Search', $capability, Lift_Admin::STATUS_PAGE );
		add_submenu_page( '', 'Lift: Search for Wordpress', 'Lift Search', $capability, Lift_Admin::LANDING_PAGE );

		// since add_options/submenu_page doesn't give us the correct hook...
		$func_enqueue_admin_script = function() {
				wp_enqueue_script( 'lift-admin-settings', plugins_url( 'js/admin-settings.js', __DIR__ ), array( 'jquery' ) );
				Lift_Admin::_enqueue_style();
			};

		foreach ( array( 'lift-search/admin/setup.php', 'lift-search/admin/status.php' ) as $hook ) {
			add_action( "load-{$hook}", $func_enqueue_admin_script );
		}
	}

	/**
	 * Response handler for testing and saving new Access Key ID and Secret Keys
	 * 
	 * @todo separate out the actual test portion of the code into a separate class
	 * used for configuration testing 
	 */
	public static function _ajax_test_and_save_credentials() {
		$id = preg_replace( '/[^a-zA-Z0-9_\-\/\\\\+]/', '', $_POST['id'] );
		$secret = preg_replace( '/[^a-zA-Z0-9_\-\/\\\\+]/', '', $_POST['secret'] );

		$result = self::test_credentials( $id, $secret );

		if ( !$result['error'] ) {
			Lift_Search::set_access_key_id( $id );
			Lift_Search::set_secret_access_key( $secret );
		}

		die( json_encode( $result ) );
	}

	public static function _ajax_test_domain() {

		$test_access = self::test_credentials( Lift_Search::get_access_key_id(), Lift_Search::get_secret_access_key() );
		if ( $test_access['error'] ) {
			echo json_encode( $test_access );
			die;
		}

		$domain = strtolower( trim( $_POST['domain'] ) );

		$error = false;
		$replacing_domain = ( Lift_Search::get_search_domain() != $domain );

		try {
			if ( Cloud_Config_Request::TestDomain( $domain ) ) {
				$status_message = 'Success';

				Lift_Search::set_search_domain( $domain );

				$document_endpoint = Cloud_Config_Request::DocumentEndpoint( $domain );
				$search_endpoint = Cloud_Config_Request::SearchEndpoint( $domain );

				try {
					if ( $document_endpoint && $search_endpoint ) {
						Lift_Search::set_document_endpoint( $document_endpoint );
						Lift_Search::set_search_endpoint( $search_endpoint );
					} else {
						$status_message = 'Unable to set endpoints. If this is a newly-created search domain it will take up to 30 minutes for endpoints to become available. Please try back later.';
						$error = true;
					}
				} catch ( Exception $e ) {
					//@todo add exception logging for endpoint failure
					$status_message = "Unable to set endpoints. Please check the search domain's status in the AWS Console";
					$error = true;
				}
			} else {
				$status_message = 'Domain could not be found. <span class="">Would you like to <a id="lift-create-domain" data-domain="' . esc_attr( $domain ) . '" href="#">create this domain with Lift\'s default indexes</a>?</span>';
				$error = true;
			}
		} catch ( Exception $e ) {
			// @todo add exception logging for domain check failure
			$status_message = 'There was an error checking the domain. Please try again.';
			$error = true;
		}

		if ( !$error && $replacing_domain ) {
			self::_complete_setup();
		}

		die( json_encode( array( 'error' => $error, 'message' => $status_message ) ) );
	}

	public static function _ajax_delete_error_logs() {
		$response = Voce_Error_Logging::delete_logs( array( 'lift-search' ) );
		echo json_encode( $response );
		die();
	}

	public static function _ajax_update_cron_interval() {
		$units = ( string ) $_POST['cron_interval_units'];
		$units = in_array( $units, array( 'm', 'h', 'd' ) ) ? $units : 'm';

		$interval = ( int ) $_POST['cron_interval'];
		$interval = ($units == 'm' && $interval < 1 ? 1 : $interval);

		Lift_Search::set_batch_interval_display( $interval, $units );

		echo json_encode( array(
			'last_cron' => Lift_Batch_Queue::get_last_cron_time(),
			'next_cron' => Lift_Batch_Queue::get_next_cron_time()
		) );
		die;
	}

	public static function _ajax_create_domain() {

		$domain = strtolower( trim( $_POST['domain'] ) );

		$error = false;
		$status_messages = array( );

		$r = Cloud_Config_Request::CreateDomain( $domain );

		if ( $r ) {

			Lift_Search::set_search_domain( $domain );

			$r = Cloud_Config_Request::LoadSchema( $domain );

			if ( $r ) {
				if ( $r->DescribeDomainsResponse->DescribeDomainsResult->DomainStatusList ) {
					$status_messages[] = 'Index created succesfully.';
					Lift_Search::set_document_endpoint( '' );
					Lift_Search::set_search_endpoint( '' );

					Lift_Search::add_new_domain_crons(); // add crons
				} else {
					$status_messages[] = 'There was an error creating an index for your domain.';
					$error = true;

					Lift_Search::event_log( 'Cloud_Config_Request::LoadSchema (http success)', $r, array( 'error' ) );
				}
			} else {
				$status_message = 'There was an error creating an index for your domain.';
				$status_messages[] = $status_message;
				$error = true;

				Lift_Search::event_log( 'Cloud_Config_Request::LoadSchema', $status_message, array( 'error' ) );
			}

			$r = Cloud_Config_Request::UpdateServiceAccessPolicies( $domain, Cloud_Config_Request::GetDefaultServiceAccessPolicy( $domain ) );

			if ( $r ) {
				$status_messages[] = 'Service Access Policies successfully configured.';
			} else {
				$status_messages[] = 'Service Access Policies could not be set. You will need to use the AWS Console to set them for this search domain.';
				$error = true;

				Lift_Search::event_log( 'Cloud_Config_Request::UpdateServiceAccessPolicies', $r, array( 'error' ) );
			}
		} else {
			$status_message = 'There was an error creating your domain. Please make sure the domain name follows the rules above and try again.';
			$status_messages[] = $status_message;
			$error = true;

			Lift_Search::event_log( 'Cloud_Config_Request::CreateDomain', $status_message, array( 'error' ) );
		}

		if ( !$error ) {
			self::_complete_setup();

			$status_messages[] = "New search domains take approximately 30-45 minutes to become active. Once your search domain is 
                    available on CloudSearch, Lift will complete it's configuration, index all posts on your site, and 
                    queue up new posts to be synced periodically.";
		}

		echo json_encode( array(
			'error' => $error,
			'message' => join( ' ', $status_messages ),
		) );

		exit;
	}

	public static function _ajax_set_cron_status() {
		$set_cron = ( bool ) intval( $_POST['cron'] );

		if ( $set_cron ) {
			Lift_Batch_Queue::enable_cron();
		} else {
			Lift_Batch_Queue::disable_cron();
		}

		echo json_encode( array(
			'set_cron' => $set_cron,
			'last_cron' => Lift_Batch_Queue::get_last_cron_time(),
			'next_cron' => Lift_Batch_Queue::get_next_cron_time()
		) );
		die;
	}

}