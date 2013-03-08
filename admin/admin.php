<?php

class Lift_Admin {

	const OPTIONS_SLUG = '/lift-search';

	public function init() {
		add_action( 'admin_menu', array( $this, 'action__admin_menu' ) );
		add_action( 'admin_init', array( $this, 'action__admin_init' ) );
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
		wp_enqueue_style( 'lift-admin', plugins_url( 'css/admin.css', __DIR__ ) );
	}

	/**
	 * Sets up all admin hooks
	 */
	public function action__admin_init() {

		//add option links
		add_filter( 'plugin_row_meta', array( __CLASS__, 'settings_link' ), 10, 2 );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( __CLASS__, 'settings_link' ), 10, 2 );
	}

	public function callback__render_options_page() {
		?>
		<div class="wrap lift-admin" id="lift-status-page">
		</div>
		<script type="text/template" id="lift-state">
			<div>
				<table>
					<tr>
						<td>
							<h3>normal</h3>
						</td>
						<td>
							<h3>CloudSearch Document Syncronization</h3>
							<div>
								Last: Feb. 20 2013 @ 12:30 pm
							</div>
							<div>
								Next: Feb. 20 2013 @ 12:40 pm
							</div>
						</td>
						<td>
							<input id="batch_interval" value="" type="text" />
							<select id="batch_interval_unit">
								<option value="m">Minutes</option>
								<option value="h">Hours</option>
								<option value="d">Days</option>
							</select>
							<input type="button" id="batch_interval_update" value="Save"/>
						</td>
						<td>
							<input type="button" id="batch_sync_now" value="Sync Queue Now"/>
						</td>
					</tr>
				</table>
			</div>
			<div>
				<div>
					Override Search
					Off | On
				</div>
				<div>
					<input type="button" id="lift_update_keys" value="Change AWS Keys"/>
					<input type="button" id="lift_reset" value="Reset Lift"/>
				</div>
			</div>
			<div id="document_queue">
				<h3>Documents to be Synced</h3>
				<span>Documents in Queue: 30</span>
				<table class="wp-list-table widefat fixed posts">
					<thead>
						<tr>
							<th class="column-title">Title</th>
							<th class="column-author">Author</th>
							<th class="column-categories">Date Queued</th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td class="column-title"><a href="#">Hello world!</a></td>
							<td class="column-author">John Smith</td>
							<td class="column-categories">Fri. Mar 08 2013 2:32pm</td>
						</tr>
						<tr>
							<td class="column-title"><a href="#">Hello world!</a></td>
							<td class="column-author">John Smith</td>
							<td class="column-categories">Fri. Mar 08 2013 2:32pm</td>
						</tr>
						<tr>
							<td class="column-title"><a href="#">Hello world!</a></td>
							<td class="column-author">John Smith</td>
							<td class="column-categories">Fri. Mar 08 2013 2:32pm</td>
						</tr>
						<tr>
							<td class="column-title"><a href="#">Hello world!</a></td>
							<td class="column-author">John Smith</td>
							<td class="column-categories">Fri. Mar 08 2013 2:32pm</td>
						</tr>
						<tr>
							<td class="column-title"><a href="#">Hello world!</a></td>
							<td class="column-author">John Smith</td>
							<td class="column-categories">Fri. Mar 08 2013 2:32pm</td>
						</tr>
					</tbody>
				</table>

				<div class="tablenav bottom">
					<div class="tablenav-pages">
						<span class="pagination-links">
							<span class="page-numbers current">1</span>
							<a class="page-numbers" href="http://lifttest.staging.voceconnect.dev/wp-admin/options-general.php?page=lift-search%2Fadmin-old%2Fstatus.php&amp;paged=2">2</a>
							<a class="page-numbers" href="http://lifttest.staging.voceconnect.dev/wp-admin/options-general.php?page=lift-search%2Fadmin-old%2Fstatus.php&amp;paged=3">3</a>
							<span class="page-numbers dots">…</span>
							<a class="page-numbers" href="http://lifttest.staging.voceconnect.dev/wp-admin/options-general.php?page=lift-search%2Fadmin-old%2Fstatus.php&amp;paged=10">10</a>
							<a class="next page-numbers" href="http://lifttest.staging.voceconnect.dev/wp-admin/options-general.php?page=lift-search%2Fadmin-old%2Fstatus.php&amp;paged=2">Next »</a></span>
					</div>
				</div>

			</div> <!--end #document_queue -->
			<div id="error_log">
				<h3>Recent Errors</h3>
				<a href="#">View All Logs</a>
				<input type="button" id="error_logs_clear" value="Clear Errors"/>
				<table class="wp-list-table widefat fixed posts">
					<thead>
						<tr>
							<th class="column-title">Error</th>
							<th class="column-date">Date/Time</th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td class="column-title">
								<strong>CloudSearch Not Ready for Batch 1362599506</strong>
								<p>
									The batch is locked or the search domain is either currently processing, needs indexing, or your domain does not have indexes set up.
									<hr>
									event_log - (/Users/prettyboymp/projects/lifttest/wp-content/plugins/lift-search/wp/lift-batch-handler.php:299)
									<br>
									send_next_batch
								</p>
							</td>
							<td class="column-date">Fri. Mar 08 2013 2:32pm</td>
						</tr>
						<tr>
							<td class="column-title">
								<strong>CloudSearch Not Ready for Batch 1362599506</strong>
								<p>
									The batch is locked or the search domain is either currently processing, needs indexing, or your domain does not have indexes set up.
									<hr>
									event_log - (/Users/prettyboymp/projects/lifttest/wp-content/plugins/lift-search/wp/lift-batch-handler.php:299)
									<br>
									send_next_batch
								</p>
							</td>
							<td class="column-date">Fri. Mar 08 2013 2:32pm</td>
						</tr>
						<tr>
							<td class="column-title">
								<strong>CloudSearch Not Ready for Batch 1362599506</strong>
								<p>
									The batch is locked or the search domain is either currently processing, needs indexing, or your domain does not have indexes set up.
									<hr>
									event_log - (/Users/prettyboymp/projects/lifttest/wp-content/plugins/lift-search/wp/lift-batch-handler.php:299)
									<br>
									send_next_batch
								</p>
							</td>
							<td class="column-date">Fri. Mar 08 2013 2:32pm</td>
						</tr>
					</tbody>
				</table>
			</div> <!--end #error_log -->

		</div> <!-- end dashboard -->
		</script>
		<?php
	}

}