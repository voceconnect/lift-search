<?php

/**
 * Helper functions that may be needed.
 */
if ( !function_exists( 'array_diff_assoc_recursive' ) ) {

	function array_diff_assoc_recursive( $array1, $array2 ) {
		$difference = array( );
		foreach ( $array1 as $key => $value ) {
			if ( is_array( $value ) ) {
				if ( !isset( $array2[$key] ) ) {
					$difference[$key] = $value;
				} elseif ( !is_array( $array2[$key] ) ) {
					$new_diff = array_diff_assoc_recursive( $value, ( array ) $array2[$key] );
					if ( !empty( $new_diff ) )
						$difference[$key] = $new_diff;
				} else {
					$new_diff = array_diff_assoc_recursive( $value, $array2[$key] );
					if ( !empty( $new_diff ) )
						$difference[$key] = $new_diff;
				}
			} else if ( is_string( $key ) && (!array_key_exists( $key, $array2 ) || $array2[$key] != $value ) ) {
				if ( !(isset( $array2[$key] ) && is_array( $array2[$key] ) && in_array( $value, $array2[$key] )) ) {
					$difference[$key] = $value;
				}
			} elseif ( is_int( $key ) && !in_array( $value, $array2 ) ) {
				$difference[] = $value;
			}
		}
		return $difference;
	}

}

if ( !function_exists( 'arrayify' ) ) {

	function arraaayify( $value ) {
		return ( array ) $value;
	}

}