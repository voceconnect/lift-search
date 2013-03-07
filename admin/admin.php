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
		add_action($hook, array($this, 'action__options_page_enqueue' ));
	}
	
	public function action__options_page_enqueue() {
		wp_enqueue_script('lift-admin', plugins_url('js/admin.js', __DIR__), array('jquery'), '0.1', true);
		wp_enqueue_style( 'lift-admin', plugins_url( 'sass/admin.css', __DIR__ ) );
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
		<?php
	}

}