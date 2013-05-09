<?php

/*
  Plugin Name: Lift Comment Facet
  Version: 1.0
  Plugin URI: http://getliftsearch.com/
  Description: Adds ability to facet by comment count
  Author: Voce Platforms
  Author URI: http://voceconnect.com/
 */

class LiftField {

	private $delegates = array( );
	public $name;
	public $type;
	public $type_options;
	public $request_vars;

	public function __construct( $name, $type, $options = array( ) ) {
		$options = wp_parse_args( $options, array(
			'_built_in' => false
			) );
		$this->name = $name;
		$this->type = $type;
		$this->type_options = array( );
		$this->request_vars = array( );

		//setup actions
		add_action( 'wp_loaded', array( $this, '_registerSearchHooks' ) );
		if ( !$options['_built_in'] ) {
			$this->registerSchemaHooks();
		}
	}

	public function setTypeOption( $name, $value ) {
		$this->type_options[$name] = $value;
		return $this;
	}

	public function addRequestVars( $request_vars = array( ) ) {
		$this->request_vars = array_merge( $this->request_vars, ( array ) $request_vars );
		return $this;
	}

	public function delegate( $key, $handler, $args = array( ) ) {
		$this->delegates[$key] = array( 'handler' => $handler, 'args' => $args );
		return $this;
	}

	public function getDelegate( $key ) {
		return isset( $this->delegates[$key] ) ? $this->delegates[$key] : null;
	}

	private function execDelegate( $key, $arg1 = null ) {
		if ( $delegate = $this->getDelegate( $key ) ) {
			$args = array_slice( func_get_args(), 1 );
			array_push( $args, $this, $delegate['args'] );
			return call_user_func_array( $delegate['handler'], $args );
		}
		return null;
	}

	public function registerSchemaHooks() {
		add_filter( 'lift_domain_schema', array( $this, '_appendSchema' ) );
		add_filter( 'lift_post_changes_to_data', array( $this, '_appendFieldToDocument' ), 10, 3 );
		return $this;
	}

	/**
	 * Callback during 'wp_loaded' used to apply any filters that should be applied
	 * after initial construction that. 
	 */
	public function _registerSearchHooks() {
		if ( !is_admin() ) {
			if ( count( $this->request_vars ) ) {
				add_filter( 'query_vars', array( $this, '_appendRequestVars' ) );
			}
			if ( $this->getDelegate( 'requestToWP' ) ) {
				add_filter( 'request', array( $this, 'requestToWP' ) );
			}
			add_action( 'get_cs_query', array( $this, 'setFacetOptions' ) );
		}
		add_filter( 'list_search_bq_parameters', array( $this, '_filterCSBooleanQuery' ), 10, 2 );
	}

	/**
	 * Callback to 'lift_domain_schema' to append this field to the schema.
	 * @param array $schema
	 * @return array
	 */
	public function _appendSchema( $schema ) {
		$field = array(
			'field_name' => $this->name,
			'field_type' => $this->type
		);

		if ( count( $this->type_options ) ) {
			$map = array( 'uint' => 'UIntOptions', 'literal' => 'LiteralOptions', 'text' => 'TextOptions' );
			$field[$map[$this->type]] = $this->type_options;
		}

		$schema[] = $field;
		return $schema;
	}

	/**
	 * Callback to 'lift_post_changes_to_data' to append this field to the document
	 * as it's sent to the domain.
	 * @param array $post_data
	 * @param array $changed_fields Names of 
	 * @param int $post_id
	 * @return array
	 */
	public function _appendFieldToDocument( $post_data, $changed_fields, $post_id ) {
		if ( $this->getDelegate( 'getDocumentValue' ) ) {
			$post_data[$this->name] = $this->execDelegate( 'getDocumentValue', $post_id );
		}
		return $post_data;
	}

	/**
	 * Callback for 'query_vars' to append any extra needed request variables.
	 * @param array $query_vars
	 * @return array
	 */
	public function _appendRequestVars( $query_vars ) {
		if ( count( $this->request_vars ) )
			$query_vars = array_merge( $query_vars, $this->request_vars );
		return $query_vars;
	}

	/**
	 * Filter callback for 'list_search_bq_parameters' to append new parameters to
	 * the AWS query.
	 * @param array $bq
	 * @param Lift_WP_Query $lift_query
	 * @return array
	 */
	public function _filterCSBooleanQuery( $bq, $lift_query ) {
		$bq[] = $this->wpToBooleanQueryParam( $lift_query );
		var_dump( $bq );
		return $bq;
	}

	/**
	 * Converts any request variables relating to this field to the format needed for
	 * WP_Query.  By default, no changes are made.
	 * @param type $request
	 * @return type
	 */
	public function requestToWP( $request ) {
		if ( $this->getDelegate( __FUNCTION__ ) ) {
			return $this->execDelegate( __FUNCTION__, $request );
		}
		return $request;
	}

	/**
	 * Returns a boolean query param based on the current WP_Query
	 * @param WP_Lift_Query $lift_query
	 * @return string The resulting boolean query parameter
	 */
	public function wpToBooleanQueryParam( $lift_query ) {
		if ( $this->getDelegate( __FUNCTION__ ) ) {
			return $this->execDelegate( __FUNCTION__, $lift_query );
		}
		return '';
	}

	/**
	 * Returns the tanslated request variables as key/value array for the given 
	 * AWS bolean query value for this field.  Default behavior is to return single 
	 * item array with $this->name as the key and the value as the bq as the value.
	 * 
	 * @param string $bq_value
	 * @return array
	 */
	public function bqValueToRequest( $bq_value ) {
		if ( $this->getDelegate( __FUNCTION__ ) ) {
			return $this->execDelegate( __FUNCTION__, $bq_value );
		}
		return array( $this->name => $bq_value );
	}

	/**
	 * 
	 * @param array $query_vars
	 * @return string the label
	 */
	public function wpToLabel( $query_vars ) {
		return ( string ) $this->execDelegate( 'wpToLabel', $query_vars );
	}

	/**
	 * 
	 * @param Cloud_Search_Query $cs_query
	 */
	public function setFacetOptions( $cs_query ) {
		if ( $this->getDelegate( __FUNCTION__ ) ) {
			$this->execDelegate( __FUNCTION__, $cs_query );
		} else {
			if ( $this->type === 'uint' || !empty( $this->type_options['FacetEnabled'] ) ) {
				$cs_query->add_facet_contraint( $this->field->name, 10 );
			}
		}
	}

}

/**
 * Factory method to allow simplified chaining.
 * @param type $name
 * @param type $type
 * @param type $options
 * @return LiftField
 */
function liftField( $name, $type, $options = array( ) ) {
	return new LiftField( $name, $type, $options );
}

//setup default fields
add_action( 'init', function() {
		$eodTime = strtotime( date( 'Y-m-d 23:59:59' ) );

		$date_facets = array(
			array( 'label' => 'In Last Day', 'min' => $eodTime - (2 * DAY_IN_SECONDS) ),
			array( 'label' => 'In Last 7 Days', 'min' => $eodTime - (8 * DAY_IN_SECONDS) ),
			array( 'label' => 'In Last 30 Days', 'min' => $eodTime - (31 * DAY_IN_SECONDS) ),
			array( 'label' => 'In Last Year', 'min' => $eodTime - (1 * YEAR_IN_SECONDS) ),
			array( 'label' => 'In Last 2 Years', 'min' => $eodTime - (2 * YEAR_IN_SECONDS) ),
			array( 'label' => 'In Last 3 Years', 'min' => $eodTime - (3 * YEAR_IN_SECONDS) ),
			array( 'label' => 'In Last 4 Years', 'min' => $eodTime - (4 * YEAR_IN_SECONDS) )
		);

		$post_date_field = liftField( 'post_date_gmt', 'uint', array( '_built_in' => true ) )
			->addRequestVars( array( 'date_start', 'date_end' ) )
			->delegate( 'requestToWP', function($request) {
					if ( isset( $request['post_date'] ) ) {
						if ( false === strpos( $request['comment_count'], '..' ) ) {
							$request['min_comments'] = $request['max_comments'] = abs( intval( $request['comment_count'] ) );
						} else {
							$count_parts = explode( '..', $request['comment_count'] );

							if ( $count_parts[0] )
								$request['min_comments'] = $count_parts[0];

							if ( $count_parts[1] )
								$request['max_comments'] = $count_parts[1];
						}
						unset( $request['comment_count'] );
					}
					return $request;
				} )
			->delegate( 'wpToBooleanQueryParam', function($lift_query) {
					$wp_query = $lift_query->wp_query;
					$date_start = $wp_query->get( 'date_start' );
					$date_end = $wp_query->get( 'date_end' );
					$param = '';
					if ( !( $date_start || $date_end ) && $wp_query->get( 'year' ) ) {
						$year = $wp_query->get( 'year' );

						if ( $wp_query->get( 'monthnum' ) ) {
							$monthnum = sprintf( '%02d', $wp_query->get( 'monthnum' ) );

							if ( $wp_query->get( 'day' ) ) {
								// Padding
								$str_date_start = sprintf( '%02d', $query->get( 'day' ) );

								$str_date_start = $wp_query->get( 'year' ) . '-' . $date_monthnum . '-' . $date_day . ' 00:00:00';
								$str_date_end = $wp_query->get( 'year' ) . '-' . $date_monthnum . '-' . $date_day . ' 23:59:59';
							} else {
								$days_in_month = date( 't', mktime( 0, 0, 0, $monthnum, 14, $year ) ); // 14 = middle of the month so no chance of DST issues

								$str_date_start = $year . '-' . $monthnum . '-01 00:00:00';
								$str_date_end = $year . '-' . $monthnum . '-' . $days_in_month . ' 23:59:59';
							}
						} else {
							$str_date_start = $year . '-01-01 00:00:00';
							$str_date_end = $year . '-12-31 23:59:59';
						}

						$date_start = get_gmt_from_date( $str_date_start );
						$date_end = get_gmt_from_date( $str_date_end );
					}

					if ( $date_start || $date_end )
						$param = "post_date_gmt:{$date_start}..{$date_end}";

					return $param;
				} )
			->delegate( 'setFacetOptions', function($cs_query, $field, $args ) {
					$facets = array( );
					foreach ( $args['facets'] as $facet ) {
						$facets[] = sprintf( '%1$s..%2$s', isset( $facet['min'] ) ? $facet['min'] : '', isset( $facet['max'] ) ? $facet['max'] : ''  );
					}
					var_dump( $facets );
					$cs_query->add_facet( $field->name );
					$cs_query->add_facet_contraint( $field->name, $facets );
				}, array( 'facets' => $date_facets ) )
			->delegate( 'wpToLabel', function($query_vars) {
				$min = isset( $query_vars['date_start'] ) ? intval( $query_vars['date_start'] ) : '';
				$max = isset( $query_vars['date_end'] ) ? intval( $query_vars['date_end'] ) : '';
				if ( $min && $max ) {
					return sprintf( '%d to %d', $min, $max );
				} elseif ( $min ) {
					return sprintf( "More than %d", $min );
				} elseif ( $max ) {
					return sprintf( "Less than %d", $max );
				} else {
					return "Any";
				}
			} );

		new LiftSelectableFacetControl( $post_date_field, 'Type' );

		$post_type_field;

		$post_categories_field;

		$post_tags_field;

		$orderby_field;
	} );