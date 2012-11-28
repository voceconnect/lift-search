<?php
/*
  Plugin Name: Lift Search
  Version: 1.0
  Plugin URI: http://getliftsearch.com/
  Description: Improves WordPress search using Amazon CloudSearch
  Author: Voce Platforms
  Author URI: http://voceconnect.com/
 */

require_once('lib/voce-error-logging/voce-error-logging.php');
require_once('api/lift-batch.php');
require_once('api/lift-http.php');
require_once('api/cloud-api.php');
require_once('api/cloud-search.php');
require_once('api/cloud-config.php');
require_once('lib/posts-to-sdf.php');
require_once('wp/lift-batch-queue.php');
require_once('wp/lift-health.php');
require_once('wp/lift-wp-search.php');
require_once('wp/lift-search-form.php');
require_once('wp/lift-update-queue.php');
require_once('wp/update-watchers/post.php');

if ( !class_exists( 'Lift_Search' ) ) {

	class Lift_Search {

		const INITIAL_SETUP_COMPLETE_OPTION = 'lift-initial-setup-complete';
		const SETTINGS_OPTION = 'lift-settings';
		const SEARCH_DOMAIN = 'search-domain';

		// Note: batch-interval should be in seconds, regardless of what batch-interval-units is set to
		private static $default_settings = array( 'batch-interval' => 300, 'batch-interval-units' => 'm' );
		private static $allowed_post_types = array( 'post', 'page' );

		const ADMIN_LANDING_PAGE = 'lift-search/admin/setup.php';
		const ADMIN_STATUS_PAGE = 'lift-search/admin/status.php';
		const INDEX_DOCUMENTS_HOOK = 'lift_index_documents';
		const SET_ENDPOINTS_HOOK = 'lift_set_endpoints';
		const NEW_DOMAIN_CRON_INTERVAL = 'lift-index-documents';

		public static function init() {
			add_action( 'admin_menu', array( __CLASS__, 'add_settings' ) );

			add_action( 'admin_init', array( __CLASS__, 'admin_init' ) );
			add_filter( 'plugin_row_meta', array( __CLASS__, 'settings_link' ), 10, 2 );
			add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( __CLASS__, 'settings_link' ), 10, 2 );

			add_action( 'wp_ajax_lift_test_access', array( __CLASS__, 'ajax_test_access' ) );
			add_action( 'wp_ajax_lift_test_domain', array( __CLASS__, 'ajax_test_domain' ) );

			add_action( 'wp_ajax_lift_delete_error_logs', array( __CLASS__, 'ajax_delete_error_logs' ) );

			add_action( 'wp_ajax_lift_update_cron_interval', array( __CLASS__, 'ajax_update_cron_interval' ) );

			add_action( 'wp_ajax_lift_create_domain', array( __CLASS__, 'ajax_create_domain' ) );

			add_action( 'wp_ajax_lift_set_cron_status', array( __CLASS__, 'ajax_set_cron_status' ) );

			// @TODO only enqueue on search template or if someone calls the form
			add_action( 'wp_enqueue_scripts', function() {
						wp_enqueue_script( 'lift-search-form', plugins_url( 'js/lift-search-form.js', __FILE__ ), array( 'jquery' ) );
						wp_enqueue_style( 'lift-search-font', 'https://fonts.googleapis.com/css?family=Lato:400,700,900' );
						wp_enqueue_style( 'lift-search', plugins_url( 'sass/style.css', __FILE__ ) );
					} );

			if ( self::get_search_endpoint() ) {
				Lift_WP_Search::init();
			}

			if ( self::get_document_endpoint() ) {
				Lift_Batch_Queue::init();
			}

			//default sdf filters
			add_filter( 'lift_document_fields_result', function($fields, $post_id) {
						$taxonomies = array( 'post_tag', 'category' );
						foreach ($taxonomies as $taxonomy) {
							if ( array_key_exists( 'taxonomy_' . $taxonomy, $fields ) ) {
								unset( $fields['taxonomy_' . $taxonomy] );
								$terms = get_the_terms( $post_id, $taxonomy );
								$fields['taxonomy_' . $taxonomy . '_id'] = array( );
								$fields['taxonomy_' . $taxonomy . '_label'] = array( );
								foreach ($terms as $term) {
									$fields['taxonomy_' . $taxonomy . '_id'][] = $term->term_id;
									$fields['taxonomy_' . $taxonomy . '_label'][] = $term->name;
								}
							}
						}

						if ( array_key_exists( 'post_author', $fields ) ) {
							$display_name = get_user_meta( $fields['post_author'], 'display_name', true );

							if ( $display_name ) {
								$fields['post_author_name'] = $display_name;
							}
						}

						return $fields;
					}, 10, 2 );

			add_filter( 'cron_schedules', function( $schedules ) {
						$schedules[Lift_Search::NEW_DOMAIN_CRON_INTERVAL] = array(
							'interval' => 60 * 5, // 5 mins
							'display' => '',
						);

						return $schedules;
					} );

			add_action( self::INDEX_DOCUMENTS_HOOK, array( __CLASS__, 'cron_index_documents' ) );
			add_action( self::SET_ENDPOINTS_HOOK, array( __CLASS__, 'cron_set_endpoints' ) );
		}

		public static function admin_init() {

			// delete all data
			if ( current_user_can( 'administrator' ) && isset( $_GET['lift-delete-all'] ) ) {
				self::delete_all_data();
				wp_redirect( admin_url( 'options-general.php?page=' . Lift_Search::ADMIN_STATUS_PAGE ) );
			}

			if ( isset( $_GET['page'] ) && Lift_Search::ADMIN_STATUS_PAGE == $_GET['page'] ) {

				// require landing page to be filled out
				if ( !isset( $_GET['lift-setup-complete'] ) &&
						(!(self::get_access_key_id() && self::get_secret_access_key() && self::get_search_domain() && get_option( self::INITIAL_SETUP_COMPLETE_OPTION, 0 )) )
				) {
					wp_redirect( admin_url( 'options-general.php?page=' . Lift_Search::ADMIN_LANDING_PAGE ) );
				}

				// send IndexDocuments request
				if ( current_user_can( 'manage_options' ) && isset( $_GET['lift-indexdocuments'] ) ) {
					Cloud_Config_Request::IndexDocuments( self::get_search_domain() );
					wp_redirect( admin_url( 'options-general.php?page=' . Lift_Search::ADMIN_STATUS_PAGE ) );
				}

				// send next batch
				if ( current_user_can( 'manage_options' ) && isset( $_GET['sync-queue'] ) ) {
					Lift_Batch_Queue::send_next_batch();
					wp_redirect( admin_url( 'options-general.php?page=' . Lift_Search::ADMIN_STATUS_PAGE ) );
				}
			}

			foreach (array( 'user_admin_notices', 'admin_notices' ) as $filter) {
				add_action( $filter, function() {
							if ( !(get_option( Lift_Search::INITIAL_SETUP_COMPLETE_OPTION, 0 ) && Lift_Search::get_access_key_id() && Lift_Search::get_secret_access_key() && Lift_Search::get_search_domain() ) ) {
								if ( !isset( $_GET['page'] ) || (isset( $_GET['page'] ) && Lift_Search::ADMIN_LANDING_PAGE != $_GET['page']) ) {
									Lift_Search::configure_lift_nag();
								}
							}
						} );
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
				$links[] = '<a href="' . admin_url( 'options-general.php?page=' . Lift_Search::ADMIN_LANDING_PAGE ) . '">Settings</a>';
			}
			return $links;
		}

		/**
		 * Setup settings in admin
		 * @method add_settings
		 */
		public static function add_settings() {
			$capability = apply_filters( 'lift_settings_capability', 'manage_options' );

			add_options_page( 'Lift: Search for WordPress', 'Lift Search', $capability, self::ADMIN_STATUS_PAGE );
			add_submenu_page( '', 'Lift: Search for Wordpress', 'Lift Search', $capability, self::ADMIN_LANDING_PAGE );

			wp_enqueue_style( 'lift-search', plugins_url( 'sass/admin.css', __FILE__ ) );

			// since add_options/submenu_page doesn't give us the correct hook...
			foreach (array( 'lift-search/admin/setup.php', 'lift-search/admin/status.php' ) as $hook) {
				add_action( "load-{$hook}", function() {
							wp_enqueue_script( 'lift-admin-settings', plugins_url( 'js/admin-settings.js', __FILE__ ), array( 'jquery' ) );
						} );
			}
		}

		/**
		 * deletes all Lift: options, transients, queued posts, error logs, cron
		 * jobs
		 * 
		 * NOTE: not currently used anywhere but intended to be run manually for
		 * debugging/testing but can be used in a uninstall hook later
		 */
		public static function delete_all_data() {
			global $wpdb;

			delete_option( self::SETTINGS_OPTION );
			delete_option( self::INITIAL_SETUP_COMPLETE_OPTION );
			delete_option( Lift_Batch_Queue::LAST_CRON_TIME_OPTION );
			delete_option( self::INITIAL_SETUP_COMPLETE_OPTION );
			delete_option( Lift_Batch_Queue::QUEUE_ALL_MARKER_OPTION );
			delete_transient( Lift_Batch_Queue::BATCH_LOCK );

			$wpdb->delete( $wpdb->posts, array( 'post_type ' => Lift_Document_Update_Queue::STORAGE_POST_TYPE ) );

			Voce_Error_Logging::delete_logs( array( 'lift-search' ) );

			wp_clear_scheduled_hook( self::INDEX_DOCUMENTS_HOOK );
			wp_clear_scheduled_hook( Lift_Batch_Queue::BATCH_CRON_HOOK );
			wp_clear_scheduled_hook( Lift_Batch_Queue::QUEUE_ALL_CRON_HOOK );
		}

		public static function ajax_set_cron_status() {
			$set_cron = (bool) intval( $_POST['cron'] );

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

		public static function ajax_test_access() {
			echo json_encode( self::test_access( trim( $_POST['id'] ), trim( $_POST['secret'] ) ) );
			die;
		}

		private function test_access( $id = '', $secret = '' ) {

			$credentials = array( 'access-key-id' => $id, 'secret-access-key' => $secret );
			$error = false;

			try {
				if ( Cloud_Config_Request::TestConnection( $credentials ) ) {
					$status_message = 'Success';
					self::__set_setting( 'access-key-id', $id );
					self::__set_setting( 'secret-access-key', $secret );
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

		public static function ajax_test_domain() {

			$test_access = self::test_access( self::get_access_key_id(), self::get_secret_access_key() );
			if ( $test_access['error'] ) {
				echo json_encode( $test_access );
				die;
			}

			$domain = strtolower( trim( $_POST['domain'] ) );

			$error = false;
			$new_domain = true;
			$replacing_domain = ( self::get_search_domain() != $domain );

			try {
				if ( Cloud_Config_Request::TestDomain( $domain ) ) {
					$status_message = 'Success';
					self::__set_setting( self::SEARCH_DOMAIN, $domain );

					$document_endpoint = Cloud_Config_Request::DocumentEndpoint( $domain );
					$search_endpoint = Cloud_Config_Request::SearchEndpoint( $domain );

					try {
						if ( $document_endpoint && $search_endpoint ) {
							self::__set_setting( 'document-endpoint', $document_endpoint );
							self::__set_setting( 'search-endpoint', $search_endpoint );
						} else {
							$status_message = 'Unable to set endpoints. If this is a newly-created search domain it will take up to 30 minutes for endpoints to become available. Please try back later.';
							$error = true;

							self::__set_setting( self::SEARCH_DOMAIN, $domain );
						}
					} catch ( Exception $e ) {
						//@todo add exception logging for endpoint failure
						$status_message = "Unable to set endpoints. Please check the search domain's status in the AWS Console";
						$error = true;

						self::__set_setting( self::SEARCH_DOMAIN, $domain );
					}

					$new_domain = false;
				} else {
					$status_message = 'Domain could not be found. <span class="">Would you like to <a id="lift-create-domain" data-domain="' . esc_attr( $domain ) . '" href="#">create this domain with Lift\'s default indexes</a>?</span>';
					$error = true;
				}
			} catch ( Exception $e ) {
				// @todo add exception logging for domain check failure
				$status_message = 'There was an error checking the domain. Please try again.';
				$error = true;
			}

			if ( !$error && ( $new_domain || $replacing_domain ) ) {
				// mark setup complete
				update_option( self::INITIAL_SETUP_COMPLETE_OPTION, 1 );
				Lift_Batch_Queue::enable_cron();
				Lift_Batch_Queue::queue_all();
			}

			echo json_encode( array( 'error' => $error, 'message' => $status_message ) );
			die;
		}

		public static function ajax_create_domain() {

			$domain = strtolower( trim( $_POST['domain'] ) );

			$error = false;
			$status_messages = array( );

			$r = Cloud_Config_Request::CreateDomain( $domain );

			if ( $r ) {

				self::__set_setting( self::SEARCH_DOMAIN, $domain );

				$r = Cloud_Config_Request::LoadSchema( $domain );

				if ( $r ) {
					if ( $r->DescribeDomainsResponse->DescribeDomainsResult->DomainStatusList ) {
						$status_messages[] = 'Index created succesfully.';
						self::__set_setting( 'document-endpoint', '' );
						self::__set_setting( 'search-endpoint', '' );
						self::add_new_domain_crons(); // add crons
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

				Lift_Search::event_log( 'Cloud_Config_Request::CreateDomain', $status_message, array('error' ) );
			}

			if ( !$error ) {
				// mark setup complete, enable cron and queue all posts
				update_option( self::INITIAL_SETUP_COMPLETE_OPTION, 1 );
				Lift_Batch_Queue::enable_cron();
				Lift_Batch_Queue::queue_all();

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

		public static function ajax_delete_error_logs() {
			$response = Voce_Error_Logging::delete_logs( array( 'lift-search' ) );
			echo json_encode( $response );
			die();
		}

		/**
		 * schedule crons needed for new domains. clear existing crons first.
		 * 
		 */
		private static function add_new_domain_crons() {
			wp_clear_scheduled_hook( self::INDEX_DOCUMENTS_HOOK );
			wp_clear_scheduled_hook( self::SET_ENDPOINTS_HOOK );

			wp_schedule_event( time(), self::NEW_DOMAIN_CRON_INTERVAL, self::INDEX_DOCUMENTS_HOOK );
			wp_schedule_event( time(), self::NEW_DOMAIN_CRON_INTERVAL, self::SET_ENDPOINTS_HOOK );
		}

		/**
		 * cron hook to send an IndexDocuments request to CloudSearch. cron
		 * is unscheduled when the documents are indexed successfully.
		 *
		 */
		public static function cron_index_documents() {
			$domain_name = self::__get_setting( self::SEARCH_DOMAIN );

			if ( !$domain_name ) {
				return;
			}

			$r = Cloud_Config_Request::IndexDocuments( $domain_name );

			if ( $r ) {
				wp_clear_scheduled_hook( self::INDEX_DOCUMENTS_HOOK );
			}
		}

		/**
		 * cron hook to set up index documents for new domains. cron
		 * is unscheduled when the documents are indexed successfully.
		 *
		 */
		public static function cron_set_endpoints() {
			$domain_name = self::get_search_domain();

			if ( !$domain_name ) {
				return;
			}

			$document_endpoint = Cloud_Config_Request::DocumentEndpoint( $domain_name );
			$search_endpoint = Cloud_Config_Request::SearchEndpoint( $domain_name );

			if ( $document_endpoint && $search_endpoint ) {
				self::__set_setting( 'document-endpoint', $document_endpoint );
				self::__set_setting( 'search-endpoint', $search_endpoint );
				wp_clear_scheduled_hook( self::SET_ENDPOINTS_HOOK );
			}
		}

		public static function ajax_update_cron_interval() {
			$units = (string) $_POST['cron_interval_units'];
			$units = in_array( $units, array( 'm', 'h', 'd' ) ) ? $units : 'm';

			$interval = (int) $_POST['cron_interval'];
			$interval = ($units == 'm' && $interval < 1 ? 1 : $interval);

			switch ( $units ) {
				case 'd':
					$interval *= 24;
				case 'h':
					$interval *= 60;
				case 'm':
					$interval *= 60;
			}

			self::__set_setting( 'batch-interval', $interval );
			self::__set_setting( 'batch-interval-units', $units );

			if ( Lift_Batch_Queue::cron_enabled() ) {
				Lift_Batch_Queue::disable_cron(); // kill the scheduled event
				$last_time = get_option( Lift_Batch_Queue::LAST_CRON_TIME_OPTION, time() );

				wp_schedule_event( $last_time + $interval, Lift_Batch_Queue::CRON_INTERVAL, Lift_Batch_Queue::BATCH_CRON_HOOK ); // schedule the next one based on the last

				Lift_Batch_Queue::enable_cron();
			}

			echo json_encode( array(
				'last_cron' => Lift_Batch_Queue::get_last_cron_time(),
				'next_cron' => Lift_Batch_Queue::get_next_cron_time()
			) );
			die;
		}

		/**
		 * Get a setting using Voce_Settings_API
		 * @param string $setting
		 * @param string $group
		 * @return string | mixed
		 */
		private static function __get_setting( $setting ) {
			$option = get_option( self::SETTINGS_OPTION, array( ) );
			// Ensure this is an array. WP does not despite the request for a default.
			if ( is_array( $option ) ) {
				$settings = array_merge( self::$default_settings, $option );
				return (isset( $settings[$setting] )) ? $settings[$setting] : false;
			} else {
				return $option;
			}
		}

		private static function __set_setting( $setting, $value ) {
			$settings = array( $setting => $value ) + get_option( self::SETTINGS_OPTION, array( ) );

			update_option( self::SETTINGS_OPTION, $settings );
		}

		/**
		 * Get access key id
		 * @return string
		 */
		public static function get_access_key_id() {
			return apply_filters( 'lift_access_key_id', self::__get_setting( 'access-key-id' ) );
		}

		/**
		 * Get secret access key
		 * @return string
		 */
		public static function get_secret_access_key() {
			return apply_filters( 'lift_secret_access_key', self::__get_setting( 'secret-access-key' ) );
		}

		/**
		 * Get search domain
		 * @return string
		 */
		public static function get_search_domain() {
			return apply_filters( 'lift_search_domain', self::__get_setting( self::SEARCH_DOMAIN ) );
		}

		/**
		 * Get search endpoint setting
		 * @return string
		 */
		public static function get_search_endpoint() {
			return apply_filters( 'lift_search_endpoint', self::__get_setting( 'search-endpoint' ) );
		}

		/**
		 * Get document endpoint setting
		 * @return string
		 */
		public static function get_document_endpoint() {
			return apply_filters( 'lift_document_endpoint', self::__get_setting( 'document-endpoint' ) );
		}

		/**
		 * Get batch interval setting
		 * @return int
		 */
		public static function get_batch_interval() {
			return apply_filters( 'lift_batch_interval', self::__get_setting( 'batch-interval' ) );
		}

		/**
		 * Get batch interval setting, adjusted for time unit
		 * @return int
		 */
		public static function get_batch_interval_adjusted() {
			$adjusted = self::get_batch_interval();

			switch ( self::get_batch_interval_unit() ) {
				case 'd':
					$adjusted /= 24;
				case 'h':
					$adjusted /= 60;
				case 'm':
					$adjusted /= 60;
			}

			return apply_filters( 'lift_batch_interval_adjusted', $adjusted );
		}

		/**
		 * Get batch interval unit setting
		 * @return string
		 */
		public static function get_batch_interval_unit() {
			return apply_filters( 'lift_batch_interval_unit', self::__get_setting( 'batch-interval-units' ) );
		}

		public static function get_http_api() {
			if ( function_exists( 'wpcom_is_vip' ) && wpcom_is_vip() ) {
				$lift_http = new Lift_HTTP_WP_VIP();
			} else {
				$lift_http = new Lift_HTTP_WP();
			}
			return $lift_http;
		}

		/**
		 * semi-factory method for simplify getting an api instance
		 */
		public static function get_search_api() {
			$lift_http = self::get_http_api();
			return new Cloud_API( $lift_http,
							Lift_Search::get_document_endpoint(), Lift_Search::get_search_endpoint(), '2011-02-01' );
		}

		public static function get_indexed_post_types() {
			return apply_filters( 'lift_indexed_post_types', self::$allowed_post_types );
		}

		public static function configure_lift_nag() {
			?>
			<div id="banneralert" class="lift-colorized">
				<div class="lift-balloon">
					<img src="<?php echo plugin_dir_url( __FILE__ ) ?>img/logo.png" alt="Lift Logo">
				</div>
				<div class="lift-message"><p><strong>Welcome to Lift</strong>: 	Now that you've activated the Lift plugin it's time to set it up. Click below to get started. </p></div>
				<div><a class="lift-btn" href="<?php echo admin_url( 'options-general.php?page=' . Lift_Search::ADMIN_LANDING_PAGE ) ?>">Configure Lift</a></div>
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

		public static function RecentLogTable() {
			$args = array(
				'post_type' => Voce_Error_Logging::POST_TYPE,
				'posts_per_page' => 5,
				'post_status' => 'any',
				'orderby' => 'date',
				'order' => 'DESC',
				'tax_query' => array( array(
						'taxonomy' => Voce_Error_Logging::TAXONOMY,
						'field' => 'slug',
						'terms' => array( 'error', 'lift-search' ),
						'operator' => 'AND'
					) ),
			);
			$query = new WP_Query( $args );
			$html = '<table id="lift-recent-logs-table" class="wp-list-table widefat fixed posts">
				<thead>
				<tr>
					<th class="column-date">Log ID</th>
					<th class="column-title">Log Title</th>
					<th class="column-categories">Time Logged</th>
				</tr>
				</thead><tbody>';
			$pages = '';
			if ( $query->have_posts() ) {
				while ( $query->have_posts() ) : $query->the_post();
					$html .= '<tr>';
					$html .= '<td class="column-date">' . get_the_ID() . '</td>';
					$html .= '<td class="column-title"><a href="' . get_admin_url() . 'post.php?post=' . get_the_ID() . '&action=edit' . '">' . get_the_title() . '</a></td>';
					$html .= '<td class="column-categories">' . get_the_time( 'D. M d Y g:ia' ) . '</td>';
					$html .= '</tr>';
				endwhile;
			} else {
				$html .= '<tr><td colspan="2">No Recent Logs</td></tr>';
			}
			$html .= '</tbody></table>';
			$html .= $pages;

			return $html;
		}
		
		/**
		 * Log Events
		 * @param type $message
		 * @param type $tags
		 * @return boolean 
		 */
		public static function event_log($message, $error, $tags = array()){
			if(function_exists('voce_error_log')){
				return voce_error_log( $message, $error, array_merge(array( 'lift-search'), (array) $tags) );
			} else {
				return false;
			}
		}

	}

	Lift_Search::init();
}
