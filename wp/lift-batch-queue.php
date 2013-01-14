<?php

/*
  @Name: Lift Batch Queue
  @Description: Add documents to batch queue
 */

if ( !class_exists( 'Lift_Batch_Queue' ) ) {

	class Lift_Batch_Queue {

		/**
		 * Private var to track whether this class was previously initialized
		 * 
		 * @var bool
		 */
		private static $is_initialized = false;

		/**
		 * Option name for the placeholder used to determine the documents
		 * still needed to be queued up for submission after initial install
		 */

		const QUEUE_ALL_MARKER_OPTION = 'lift-queue-all-content-timestamp';

		/**
		 * The number of documents to add to the queue at a time when doing the
		 * initial enqueuing of all documents 
		 */
		const QUEUE_ALL_SET_SIZE = 100;

		/**
		 * ID of the hook called by wp_cron when a batch should be processed 
		 */
		const BATCH_CRON_HOOK = 'lift_batch_cron';

		/**
		 * ID of the hook called by wp_cron when a next set of documents should
		 * be added to the queue 
		 */
		const QUEUE_ALL_CRON_HOOK = 'lift_queue_all_cron';

		/**
		 * Name of the custom interval created for batch processing
		 */
		const CRON_INTERVAL = 'lift-cron';

		/**
		 * Name of the transient key used to block multiple processes from 
		 * modifying batches at the same time. 
		 */
		const BATCH_LOCK = 'lift-batch-lock';

		/**
		 * Option name for the option storing the timestamp that the last
		 * batch was run. 
		 */
		const LAST_CRON_TIME_OPTION = 'lift-last-cron-time';

		public static function init() {
			if ( self::$is_initialized )
				return false;

			add_filter( 'cron_schedules', function( $schedules ) {
					if ( Lift_Search::get_batch_interval() > 0 ) {
						$interval = Lift_Search::get_batch_interval();
					} else {
						$interval = 86400;
					}

					$schedules[Lift_Batch_Queue::CRON_INTERVAL] = array(
						'interval' => $interval,
						'display' => '',
					);

					return $schedules;
				} );

			add_action( self::BATCH_CRON_HOOK, array( __CLASS__, 'send_next_batch' ) );
			add_action( self::QUEUE_ALL_CRON_HOOK, array( __CLASS__, 'process_queue_all' ) );

			require_once(__DIR__ . '/lift-update-queue.php');

			Lift_Document_Update_Queue::init();

			self::$is_initialized = true;
		}

		/**
		 * enable the cron 
		 */
		public static function enable_cron( $timestamp = null ) {
			if ( is_null( $timestamp ) )
				$timestamp = time();
			wp_clear_scheduled_hook( self::BATCH_CRON_HOOK );
			wp_schedule_event( $timestamp, self::CRON_INTERVAL, self::BATCH_CRON_HOOK );
		}

		/**
		 * disable the cron 
		 */
		public static function disable_cron() {
			wp_clear_scheduled_hook( self::BATCH_CRON_HOOK );
		}

		/**
		 * is cron enabled?
		 */
		public static function cron_enabled() {
			$enabled = ( bool ) wp_next_scheduled( self::BATCH_CRON_HOOK );

			return $enabled;
		}

		/**
		 * get the last cron run time formatted for the blog's timezone and date/time format. or 'n/a' if not available.
		 *
		 * * @return string date string or 'n/a' 
		 */
		public static function get_last_cron_time() {
			$date_format = sprintf( '%s @ %s', get_option( 'date_format' ), get_option( 'time_format' ) );

			$gmt_offset = 60 * 60 * get_option( 'gmt_offset' );

			if ( ($last_cron_time_raw = get_option( self::LAST_CRON_TIME_OPTION, FALSE ) ) ) {
				return date( $date_format, $last_cron_time_raw + $gmt_offset );
			} else {
				return 'n/a';
			}
		}

		/**
		 * get the next cron run time formatted for the blog's timezone and date/time format. or 'n/a' if not available.
		 *
		 * * @return string date string or 'n/a' 
		 */
		public static function get_next_cron_time() {
			$date_format = sprintf( '%s @ %s', get_option( 'date_format' ), get_option( 'time_format' ) );

			$gmt_offset = 60 * 60 * get_option( 'gmt_offset' );

			if ( ($next_cron_time_raw = wp_next_scheduled( self::BATCH_CRON_HOOK ) ) ) {
				return date( $date_format, $next_cron_time_raw + $gmt_offset );
			} elseif ( self::cron_enabled() ) {
				return date( $date_format, time() + Lift_Search::get_batch_interval() + $gmt_offset );
			} else {
				return 'n/a';
			}
		}

		/**
		 * count of posts in the queue to be sent to CloudSearch
		 * 
		 * @return int 
		 */
		public static function get_queue_count() {
			return Lift_Document_Update_Queue::get_queue_count();
		}

		/**
		 * get a table with the current queue
		 * 
		 * @return string 
		 */
		public static function get_queue_list() {
			$page = (isset( $_GET['paged'] )) ? $_GET['paged'] : 0;
			$args = array(
				'post_type' => 'lift_queued_document',
				'posts_per_page' => 10,
				'paged' => max( 1, $page ),
				'post_status' => 'any',
				'orderby' => 'ID',
				'order' => 'DESC'
			);
			$query = new WP_Query( $args );
			$html = '<table class="wp-list-table widefat fixed posts">
				<thead>
				<tr>
					<th class="column-date">Queue ID</th>
					<th class="column-title">Post</th>
					<th class="column-author">Last Author</th>
					<th class="column-categories">Time Queued</th>
				</tr>
				</thead>';
			$pages = '';
			if ( $query->have_posts() ) {
				while ( $query->have_posts() ) : $query->the_post();
					$pid = ( int ) substr( get_the_title(), 5 );
					if ( get_post_status( $pid ) ) {
						$last_user = '';
						if ( $last_id = get_post_meta( $pid, '_edit_last', true ) ) {
							$last_user = get_userdata( $last_id );
						}
						$html .= '<tr>';
						$html .= '<td class="column-date">' . get_the_ID() . '</td>';
						$html .= '<td class="column-title"><a href="' . get_post_permalink( $pid ) . '">' . get_the_title( $pid ) . '</a></td>';
						$html .= '<td class="column-author">' . (isset( $last_user->display_name ) ? $last_user->display_name : '') . '</td>';
						$html .= '<td class="column-categories">' . get_the_time( 'D. M d Y g:ia' ) . '</td>';
						$html .= '</tr>';
					} else {
						$html .= '<tr>';
						$html .= '<td class="column-date">' . get_the_ID() . '</td>';
						$html .= '<td class="column-title">Deleted ' . get_the_title() . '</td>';
						$html .= '<td class="column-author">&nbsp;</td>';
						$html .= '<td class="column-categories">' . get_the_time( 'D. M d Y g:ia' ) . '</td>';
						$html .= '</tr>';
					}
				endwhile;
				$big = 999999999;
				$pages = '<div class="tablenav bottom"><div class="tablenav-pages"><span class="pagination-links">';
				$pages .= paginate_links( array(
					'base' => str_replace( $big, '%#%', esc_url( get_pagenum_link( $big ) ) ),
					'format' => '?paged=%#%',
					'current' => max( 1, $page ),
					'total' => $query->max_num_pages
					) );
				$pages .= '</span></div></div>';
			} else {
				$html .= '<tr><td colspan="4">No Posts In Queue</td></tr>';
			}
			$html .= '</table>';
			$html .= $pages;

			return $html;
		}

		/**
		 * queue all posts for indexing. clear the prior cron job.
		 */
		public static function queue_all() {
			update_option( self::QUEUE_ALL_MARKER_OPTION, current_time( 'mysql', true ) );
			wp_clear_scheduled_hook( self::QUEUE_ALL_CRON_HOOK );
			wp_schedule_event( time(), self::CRON_INTERVAL, self::QUEUE_ALL_CRON_HOOK );
		}

		/**
		 * used by queue_all cron job to process the queue of all posts
		 * 
		 * @global object $wpdb
		 */
		public static function process_queue_all() {
			global $wpdb;

			$date_to = get_option( self::QUEUE_ALL_MARKER_OPTION );

			if ( !$date_to ) {
				wp_clear_scheduled_hook( self::QUEUE_ALL_CRON_HOOK );
				return;
			}

			$post_types = Lift_Search::get_indexed_post_types();

			$args = array(
				'post_type' => $post_types,
				'post_status' => 'any',
				'posts_per_page' => self::QUEUE_ALL_SET_SIZE,
				'fields' => 'ids',
				'orderby' => 'post_date',
				'order' => 'desc',
			);

			$post_ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM $wpdb->posts 
				WHERE post_type in ('" . implode( "','", $post_types ) . "') AND post_date_gmt < %s
				ORDER BY post_date desc
				LIMIT %d", $date_to, self::QUEUE_ALL_SET_SIZE ) );

			if ( empty( $post_ids ) ) {
				wp_clear_scheduled_hook( self::QUEUE_ALL_CRON_HOOK );
				delete_option( self::QUEUE_ALL_MARKER_OPTION );
				return;
			}

			_prime_post_caches( $post_ids );

			foreach ( $post_ids as $post_id ) {
				Lift_Post_Update_Watcher::queue_entire_post( $post_id );
			}

			$new_date_to = get_post( $post_ids[count( $post_ids ) - 1] )->post_date_gmt;

			update_option( self::QUEUE_ALL_MARKER_OPTION, $new_date_to );
		}

		/**
		 * is the batch locked?
		 * 
		 * @return bool 
		 */
		public static function is_batch_locked() {
			$locked = get_transient( self::BATCH_LOCK );

			return $locked;
		}

		/**
		 * is the domain ready for a batch. has to exist and be in a good state
		 * 
		 * @param string $domain_name
		 * @return boolean 
		 */
		public static function ready_for_batch( $domain_name ) {

			$domains = Cloud_Config_Request::GetDomains( array( $domain_name ) );
			if ( $domains ) {
				$ds = $domains->DescribeDomainsResponse->DescribeDomainsResult->DomainStatusList;
				if ( !count( $ds ) ) {
					return false;
				}
				foreach ( $ds as $d ) {
					if ( $d->DomainName == $domain_name ) {
						return ( bool ) (!$d->Deleted && !$d->Processing && !$d->RequiresIndexDocuments && $d->SearchInstanceCount > 0 );
					}
				}
			}
		}

		/**
		 * Pulls the next set of items from the queue and sends a batch from it
		 * Callback for Batch Submission Cron 
		 * 
		 * @todo Add locking
		 */
		public static function send_next_batch() {
			if ( !self::ready_for_batch( Lift_Search::get_search_domain() ) ) {
				delete_transient( self::BATCH_LOCK );
				Lift_Search::event_log( 'CloudSearch Not Ready for Batch ' . time(), 'The batch is locked or the search domain is either currently processing, needs indexing, or your domain does not have indexes set up.', array( 'send-queue', 'response-false', 'notice' ) );
				return;
			}

			$lock_key = md5( uniqid( microtime() . mt_rand(), true ) );
			if ( !get_transient( self::BATCH_LOCK ) ) {
				set_transient( self::BATCH_LOCK, $lock_key, 300 );
			}

			if ( get_transient( self::BATCH_LOCK ) !== $lock_key ) {
				//another cron has this lock
				return;
			}


			update_option( self::LAST_CRON_TIME_OPTION, time() );

			$args = array(
				'post_type' => Lift_Document_Update_Queue::STORAGE_POST_TYPE,
				'posts_per_page' => self::QUEUE_ALL_SET_SIZE,
				'orderby' => 'post_date',
				'order' => 'asc',
				'fields' => 'ids',
			);

			$queued_update_ids = get_posts( $args );

			if ( !$queued_update_ids ) {
				return;
			}

			_prime_post_caches( $queued_update_ids );

			$batch = new Lift_Batch();
			$batched_ids = array( );
			foreach ( $queued_update_ids as $update_id ) {
				if ( $update_post = get_post( $update_id ) ) {
					$post_meta_content = get_post_meta( $update_id, 'lift_content', true );
					$update_data = ( array ) maybe_unserialize( $post_meta_content );
					if ( $update_data['document_type'] == 'post' ) {
						$action = $update_data['action'];
						if ( $action == 'add' ) {
							$post = get_post( $update_data['document_id'], ARRAY_A );
							$post_data = array( 'ID' => $update_data['document_id'] );
							foreach ( $update_data['fields'] as $field ) {
								$post_data[$field] = isset( $post[$field] ) ? $post[$field] : null;
							}

							$sdf_field_data = apply_filters( 'lift_post_changes_to_data', $post_data, $update_data['fields'], $update_data['document_id'] );
						} else {
							$sdf_field_data = array( 'ID' => intval( $update_data['document_id'] ) );
						}


						$sdf_doc = Lift_Posts_To_SDF::format_post( ( object ) $sdf_field_data, array(
								'action' => $action,
								'time' => time()
							) );

						try {
							$batch->add_document( ( object ) $sdf_doc );

							$batched_ids[] = $update_id;
						} catch ( Lift_Batch_Exception $e ) {
							if ( isset( $e->errors[0]['code'] ) && 500 == $e->errors[0]['code'] ) {
								break;
							}
							Lift_Search::event_log( 'Batch Add Error ' . time(), json_encode( $e ), array( 'batch-add', 'error' ) );

							//@todo log error, stop cron? --- update_option( self::$search_semaphore, 1 );

							continue;
						}
					}
				}
			}

			//send the batch
			$cloud_api = Lift_Search::get_search_api();

			if ( $r = $cloud_api->sendBatch( $batch ) ) {
				if ( $r->status === "success" ) {
					$log_title = "Post Queue Sent ";
					$tag = 'success';

					foreach ( $batched_ids as $processed_id ) {
						wp_delete_post( $processed_id, true );
					}
				} else {
					$log_title = "Post Queue Send Error ";
					$tag = 'error';
				}
				Lift_Search::event_log( $log_title . time(), json_encode( $r ), array( 'send-queue', 'response-true', $tag ) );

				//@todo delete sent queued items
			} else {
				$messages = $cloud_api->getErrorMessages();
				Lift_Search::event_log( 'Post Queue Error ' . time(), $messages, array( 'send-queue', 'response-false', 'error' ) );
			}
			wp_cache_delete( 'lift_update_queue_count' );
			delete_transient( self::BATCH_LOCK );
		}

		public static function _deactivation_cleanup() {
			delete_option( self::QUEUE_ALL_MARKER_OPTION );
			delete_option( self::LAST_CRON_TIME_OPTION );
			wp_clear_scheduled_hook( self::BATCH_CRON_HOOK );
			delete_transient( self::BATCH_LOCK );
		}

	}

}