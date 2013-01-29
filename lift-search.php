<?php

/*
  Plugin Name: Lift Search
  Version: 1.3
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
require_once('wp/lift-batch-handler.php');
require_once('wp/lift-health.php');
require_once('wp/lift-wp-search.php');
require_once('wp/lift-search-form.php');
require_once('wp/lift-update-queue.php');
require_once('wp/update-watchers/post.php');

if ( !class_exists( 'Lift_Search' ) ) {

	class Lift_Search {
		/**
		 * Option name for the marker of whether the user finisehd the setup process 
		 */

		const INITIAL_SETUP_COMPLETE_OPTION = 'lift-initial-setup-complete';
		const DB_VERSION = 3;

		/**
		 * Option name for storing all user based options 
		 */
		const SETTINGS_OPTION = 'lift-settings';
		const INDEX_DOCUMENTS_HOOK = 'lift_index_documents';
		const SET_ENDPOINTS_HOOK = 'lift_set_endpoints';
		const NEW_DOMAIN_CRON_INTERVAL = 'lift-index-documents';

		/**
		 * Returns whether setup has been complete by testing whether all
		 * required data is set
		 * @return bool 
		 */
		public static function is_setup_complete() {
			return self::get_access_key_id() && self::get_secret_access_key()
				&& self::get_search_domain() && get_option( self::INITIAL_SETUP_COMPLETE_OPTION, 0 );
		}

		public static function error_logging_enabled() {
			return !( defined( 'DISABLE_LIFT_ERROR_LOGGING' ) && DISABLE_LIFT_ERROR_LOGGING )
				&& ( class_exists( 'Voce_Error_Logging' ) || file_exists( __DIR__ . '/lib/voce-error-loggin/voce-error-logging' ) );
		}

		public static function init() {

			if ( self::error_logging_enabled() && !class_exists( 'Voce_Error_Logging' )
				&& file_exists( __DIR__ . '/lib/voce-error-loggin/voce-error-logging' ) ) {
				require_once (__DIR__ . '/lib/voce-error-loggin/voce-error-logging');
			}

			if ( self::get_search_endpoint() ) {
				add_action( 'init', array( 'Lift_WP_Search', 'init' ) );
			}

			if ( self::get_document_endpoint() ) {
				add_action( 'init', array( 'Lift_Batch_Handler', 'init' ) );
				add_action( 'lift_post_changes_to_data', array( __CLASS__, '_default_extended_post_data' ), 10, 3 );
			}

			if ( is_admin() ) {
				require_once(__DIR__ . '/admin/admin.php');
				Lift_Admin::init();
			}

			add_action( 'init', array( __CLASS__, '_upgrade_check' ) );

			//need cron hooks to be set prior to init
			add_action( Lift_Batch_Handler::BATCH_CRON_HOOK, array( 'Lift_Batch_Handler', 'send_next_batch' ) );
			add_action( Lift_Batch_Handler::QUEUE_ALL_CRON_HOOK, array( 'Lift_Batch_Handler', 'process_queue_all' ) );


			// @TODO only enqueue on search template or if someone calls the form
			add_action( 'wp_enqueue_scripts', function() {
					wp_enqueue_script( 'lift-search-form', plugins_url( 'js/lift-search-form.js', __FILE__ ), array( 'jquery' ) );
					wp_enqueue_style( 'lift-search', plugins_url( 'sass/style.css', __FILE__ ) );
				} );

			//default sdf filters
			add_filter( 'lift_document_fields_result', function($fields, $post_id) {
					$taxonomies = array( 'post_tag', 'category' );
					foreach ( $taxonomies as $taxonomy ) {
						if ( array_key_exists( 'taxonomy_' . $taxonomy, $fields ) ) {
							unset( $fields['taxonomy_' . $taxonomy] );
							$terms = get_the_terms( $post_id, $taxonomy );
							$fields['taxonomy_' . $taxonomy . '_id'] = array( );
							$fields['taxonomy_' . $taxonomy . '_label'] = array( );
							foreach ( $terms as $term ) {
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

					if ( Lift_Search::get_batch_interval() > 0 ) {
						$interval = Lift_Search::get_batch_interval();
					} else {
						$interval = 86400;
					}

					$schedules[Lift_Batch_Handler::CRON_INTERVAL] = array(
						'interval' => $interval,
						'display' => '',
					);

					return $schedules;
				} );

			//hooking into the index documents cron to tell AWS to start indexing documents
			add_action( self::INDEX_DOCUMENTS_HOOK, function() {
					$domain_name = Lift_Search::get_search_domain();

					if ( !$domain_name ) {
						return;
					}

					$r = Cloud_Config_Request::IndexDocuments( $domain_name );

					if ( $r ) {
						wp_clear_scheduled_hook( Lift_Search::INDEX_DOCUMENTS_HOOK );
					}
				} );

			//hooking into endpoints cron to "asynchronously" retrieve the endpoint data 
			//from AWS
			add_action( self::SET_ENDPOINTS_HOOK, array( 'Lift_Search', 'cron_set_endpoints' ) );
		}

		public function test_access( $id = '', $secret = '' ) {

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

		/**
		 * schedule crons needed for new domains. clear existing crons first.
		 * 
		 */
		public static function add_new_domain_crons() {
			wp_clear_scheduled_hook( self::INDEX_DOCUMENTS_HOOK );
			wp_clear_scheduled_hook( self::SET_ENDPOINTS_HOOK );

			wp_schedule_event( time(), self::NEW_DOMAIN_CRON_INTERVAL, self::INDEX_DOCUMENTS_HOOK );
			wp_schedule_event( time(), self::NEW_DOMAIN_CRON_INTERVAL, self::SET_ENDPOINTS_HOOK );
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

		/**
		 * Get a setting lift setting
		 * @param string $setting
		 * @param string $group
		 * @return string | mixed
		 */
		private static function __get_setting( $setting ) {
			// Note: batch-interval should be in seconds, regardless of what batch-interval-units is set to
			$default_settings = array( 'batch-interval' => 300, 'batch-interval-units' => 'm' );

			$settings = get_option( self::SETTINGS_OPTION, array( ) );

			if ( !is_array( $settings ) ) {
				$settings = $default_settings;
			} else {
				$settings = wp_parse_args( $settings, $default_settings );
			}

			return (isset( $settings[$setting] )) ? $settings[$setting] : false;
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
		 * Sets the access key id
		 * @param type $value 
		 */
		public static function set_access_key_id( $value ) {
			self::__set_setting( 'access-key-id', $value );
		}

		/**
		 * Get secret access key
		 * @return string
		 */
		public static function get_secret_access_key() {
			return apply_filters( 'lift_secret_access_key', self::__get_setting( 'secret-access-key' ) );
		}

		/**
		 * Sets the secret key id
		 * @param type $value 
		 */
		public static function set_secret_access_key( $value ) {
			self::__set_setting( 'secret-access-key', $value );
		}

		/**
		 * Get search domain
		 * @return string
		 */
		public static function get_search_domain() {
			return apply_filters( 'lift_search_domain', self::__get_setting( 'search-domain' ) );
		}

		public static function set_search_domain( $value ) {
			self::__set_setting( 'search-domain', $value );
			self::__update_endpoints();
		}

		private static function __update_endpoints() {
			if ( $search_domain = self::get_search_domain() ) {
				$document_endpoint = Cloud_Config_Request::DocumentEndpoint( $search_domain );
				$search_endpoint = Cloud_Config_Request::SearchEndpoint( $search_domain );

				if ( $document_endpoint && $search_endpoint ) {
					self::__set_setting( 'document-endpoint', $document_endpoint );
					self::__set_setting( 'search-endpoint', $search_endpoint );
				}
			}
		}

		/**
		 * Get search endpoint setting
		 * @return string
		 */
		public static function get_search_endpoint() {
			if ( !self::__get_setting( 'search-endpoint' ) ) {
				self::__update_endpoints();
			}
			return apply_filters( 'lift_search_endpoint', self::__get_setting( 'search-endpoint' ) );
		}

		public static function set_search_endpoint( $value ) {
			self::__set_setting( 'search-endpoint', $value );
		}

		/**
		 * Get document endpoint setting
		 * @return string
		 */
		public static function get_document_endpoint() {
			if ( !self::__get_setting( 'document-endpoint' ) ) {
				self::__update_endpoints();
			}
			return apply_filters( 'lift_document_endpoint', self::__get_setting( 'document-endpoint' ) );
		}

		public static function set_document_endpoint( $value ) {
			self::__set_setting( 'document-endpoint', $value );
		}

		/**
		 * Get batch interval setting
		 * @return int
		 */
		public static function get_batch_interval() {
			return apply_filters( 'lift_batch_interval', self::__get_setting( 'batch-interval' ) );
		}

		public static function get_batch_interval_display() {
			$value = self::get_batch_interval();
			$unit = self::__get_setting( 'batch-interval-unit' );
			switch ( $unit ) {
				case 'd':
					$value /= 24;
				case 'h':
					$value /= 60;
				case 'm':
				default:
					$unit = 'm';
					$value /= 60;
			}

			return apply_filters( 'lift_batch_interval_display', compact( 'value', 'unit' ) );
		}

		/**
		 * Sets the batch interval based off of user facing values
		 * @param int $value The number of units
		 * @param string $unit The shorthand value of the unit, options are 'm','h','d'
		 */
		public static function set_batch_interval_display( $value, $unit ) {
			$old_interval = self::get_batch_interval_display();
			$has_changed = false;

			foreach ( array( 'value', 'unit' ) as $key ) {
				if ( $old_interval[$key] != $$key ) {
					$has_changed = true;
					break;
				}
			}

			if ( $has_changed ) {
				$interval = $value;
				switch ( $unit ) {
					case 'd':
						$interval *= 24;
					case 'h':
						$interval *= 60;
					case 'm':
					default:
						$unit = 'm';
						$interval *= 60;
				}

				self::__set_setting( 'batch-interval-unit', $unit );
				self::__set_setting( 'batch-interval', $interval );

				if ( Lift_Batch_Handler::cron_enabled() ) {
					$last_time = get_option( Lift_Batch_Handler::LAST_CRON_TIME_OPTION, time() );

					Lift_Batch_Handler::enable_cron( $last_time + $interval );
				}
			}
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
			return apply_filters( 'lift_indexed_post_types', get_post_types( array( 'public' => true ) ) );
		}

		public static function get_indexed_post_fields( $post_type ) {
			return apply_filters( 'lift_indexed_post_fields', array(
					'post_title',
					'post_content',
					'post_excerpt',
					'post_date_gmt',
					'post_excerpt',
					'post_status',
					'post_type',
					'post_author'
					), $post_type );
		}

		public static function RecentErrorsTable() {
			if ( !self::error_logging_enabled() ) {
				return '<div class="notice">Error Logging is Disabled</div>';
			}

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
				$html .= '<tr><td colspan="2">No Recent Errors</td></tr>';
			}
			$html .= '</tbody></table>';
			$html .= $pages;

			return $html;
		}

		public static function _default_extended_post_data( $post_data, $updated_fields, $document_id ) {

			$post_data['post_author_name'] = get_the_author_meta( 'display_name', $post_data['post_author'], $document_id );

			$taxonomies = array( 'category', 'post_tag' );

			foreach ( $taxonomies as $taxonomy ) {
				$terms = get_the_terms( $document_id, $taxonomy );
				if ( !empty( $terms ) ) {

					$post_data["taxonomy_{$taxonomy}_label"] = array();
					$post_data["taxomomy_{$taxonomy}_id"] = array( );

					foreach ( $terms as $term ) {
						$post_data["taxonomy_{$taxonomy}_label"][] = $term->name ;
						$post_data["taxomomy_{$taxonomy}_id"][] = $term->term_id;
					}
					
					$post_data["taxonomy_{$taxonomy}_label"] = join(', ', $post_data["taxonomy_{$taxonomy}_label"]);
				}
			}
			return $post_data;
		}

		/**
		 * Log Events
		 * @param type $message
		 * @param type $tags
		 * @return boolean 
		 */
		public static function event_log( $message, $error, $tags = array( ) ) {
			if ( function_exists( 'voce_error_log' ) ) {
				return voce_error_log( $message, $error, array_merge( array( 'lift-search' ), ( array ) $tags ) );
			} else {
				return false;
			}
		}

		public static function _upgrade_check() {
			global $wpdb;

			$current_db_version = get_option( 'lift_db_version', 0 );
			$queue_all = false;


			if ( $current_db_version < 2 ) {
				//queue storage changes
				$post_ids = $wpdb->get_col( "SELECT ID FROM $wpdb->posts " .
					"WHERE post_type = '" . Lift_Document_Update_Queue::STORAGE_POST_TYPE . "'" );

				$queue_id = Lift_Document_Update_Queue::get_active_queue_id();

				foreach ( $post_ids as $post_id ) {
					if ( $update_meta = get_post_meta( $post_id, 'lift_content', true ) ) {
						if ( is_string( $update_meta ) )
							$update_meta = maybe_unserialize( $update_meta ); //previous versions double serialized meta

						$meta_key = 'lift_update_' . $update_meta['document_type'] . '_' . $update_meta['document_id'];
						$new_meta = array(
							'document_id' => $update_meta['document_id'],
							'document_type' => $update_meta['document_type'],
							'action' => $update_meta['action'],
							'fields' => $update_meta['fields'],
							'update_date_gmt' => get_post_time( 'Y-m-d H:i:s', true, $post_id ),
							'update_date' => get_post_time( 'Y-m-d H:i:s', false, $post_id )
						);
						update_post_meta( $queue_id, $meta_key, $new_meta );

						wp_delete_post( $post_id );
					}
				}

				update_option( 'lift_db_version', 2 );
			}

			if ( $current_db_version < 3 ) {
				//schema changes
				Cloud_Config_Request::LoadSchema( self::get_search_domain() );


				if ( $current_db_version > 0 ) {
					$queue_all = true;
				}

				update_option( 'lift_db_version', 3 );
			}

			if ( $queue_all ) {
				Lift_Batch_Handler::queue_all();
			}
		}

	}

	add_action( 'plugins_loaded', array( 'Lift_Search', 'init' ) );
}


register_deactivation_hook( __FILE__, '_lift_deactivate' );

function _lift_deactivate() {
	// @TODO Clean up batch posts and any scheduled crons
	//clean up options
	delete_option( Lift_Search::INITIAL_SETUP_COMPLETE_OPTION );
	delete_option( Lift_Search::SETTINGS_OPTION );
	delete_option( 'lift_db_version' );
	delete_option( Lift_Document_Update_Queue::QUEUE_IDS_OPTION );

	if ( class_exists( 'Voce_Error_Logging' ) ) {
		Voce_Error_Logging::delete_logs( array( 'lift-search' ) );
	}

	wp_clear_scheduled_hook( Lift_Search::INDEX_DOCUMENTS_HOOK );

	Lift_Batch_Handler::_deactivation_cleanup();
	Lift_Document_Update_Queue::_deactivation_cleanup();
}

register_activation_hook( __FILE__, '_lift_activation' );

function _lift_activation() {
	//register the queue posts
	Lift_Document_Update_Queue::get_active_queue_id();
	Lift_Document_Update_Queue::get_closed_queue_id();
}
