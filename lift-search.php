<?php
/*
Plugin Name: Lift Search
Version: 1.10.0
Plugin URI: http://getliftsearch.com/
Description: Improves WordPress search using Amazon CloudSearch
Author: Voce Platforms
Author URI: http://voceconnect.com/
 */

if ( !class_exists( 'Lift_Search' ) ) {

	if ( version_compare( phpversion(), '5.3.0', '>=') ) {
		require_once('lift-core.php');
	}

	function _lift_php_version_check() {
			if ( !class_exists( 'Lift_Search' ) ) {
				die( '<p style="font: 12px/1.4em sans-serif;"><strong>Lift Search requires PHP version 5.3 or higher. Installed version is: ' . phpversion() . '</strong></p>' );
			} elseif ( function_exists('_lift_activation') ) {
				_lift_activation();
			}
	}


	// check to see if .com functions exist, if not, run php version check on activation - with .com environments we can assume PHP 5.3 or higher
	if ( !function_exists( 'wpcom_is_vip' ) ) {
		register_activation_hook( __FILE__, '_lift_php_version_check' );
	}


	function _lift_deactivate() {
		if(class_exists('Left_Search')) {
			$domain_manager = Lift_Search::get_domain_manager();
			if ( $domain_name = Lift_Search::get_search_domain_name() ) {
				TAE_Async_Event::Unwatch( 'lift_domain_created_' . $domain_name );
				TAE_Async_Event::Unwatch( 'lift_needs_indexing_' . $domain_name );
			}


			//clean up options
			delete_option( Lift_Search::INITIAL_SETUP_COMPLETE_OPTION );
			delete_option( Lift_Search::SETTINGS_OPTION );
			delete_option( 'lift_db_version' );
			delete_option( Lift_Document_Update_Queue::QUEUE_IDS_OPTION );

			if ( class_exists( 'Voce_Error_Logging' ) ) {
				Voce_Error_Logging::delete_logs( array( 'lift-search' ) );
			}

			Lift_Batch_Handler::_deactivation_cleanup();
			Lift_Document_Update_Queue::_deactivation_cleanup();
		}
	}

	register_deactivation_hook( __FILE__, '_lift_deactivate' );

}