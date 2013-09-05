<?php
/*
  Plugin Name: Lift Search
  Version: 1.6.0
  Plugin URI: http://getliftsearch.com/
  Description: Improves WordPress search using Amazon CloudSearch
  Author: Voce Platforms
  Author URI: http://voceconnect.com/
 */

function _lift_php_version_check() {
    if ( version_compare( phpversion(), '5.3.0', '<') ) {
        die( 'This plugin requires PHP version 5.3 or higher. Installed version is: ' . phpversion() );
    } else {
    	require_once('lift-core.php');
    	_lift_activation();
    }
}

register_activation_hook( __FILE__, '_lift_php_version_check' );