<?php

/*

  Example usage:

  $query = new Cloud_Search_Query('post_content:"ratchet"');

  $query->add_facet('post_category');
  $query->add_return_field('id');
  $query->add_rank('post_date_gmt', 'DESC');

  $query_string = $query->get_query_string();

 */

class Cloud_Search_Query {

	protected $facets = array();
	protected $facet_constraints = array();
	protected $facet_top_n = array();
	protected $return_fields = array();
	protected $size = 10;
	protected $start = 0;
	protected $boolean_query = '';
	protected $ranks = array();

	public function __construct( $boolean_query = '' ) {
		$this->boolean_query = $boolean_query;
	}

	public function set_boolean_query( $boolean_query ) {
		$this->boolean_query = $boolean_query;
	}

	public function add_facet( $field ) {
		if ( $field ) {
			$this->facets[] = $field;
		}
	}

	public function add_facet_contraint( $field, $constraints ) {
		// fix for old style facet constraints (i.e. 1..2 => 1,2)
		if ( is_array( $constraints ) ) {
			$constraints = array_map( function ( $n ) {
				return str_replace( '..', ',', $n );
			}, $constraints );
		}
		$this->facet_constraints[ $field ] = ( array ) $constraints;
	}

	public function add_facet_top_n( $field, $limit ) {
		$this->facet_top_n[ $field ] = $limit;
	}

	public function add_return_field( $field ) { // string or array
		$this->return_fields = array_merge( $this->return_fields, ( array ) $field );
	}

	private function __validate_size( $size ) {
		if ( ( int ) $size != $size || ( int ) $size < 0 ) {
			throw new CloudSearchAPIException( 'Size must be a positive integer.', 2 );
		}
	}

	public function set_size( $size = 10 ) {
		$this->__validate_size( $size );
		$this->size = $size;
	}

	private function __validate_start( $start ) {
		if ( ( int ) $start != $start || ( int ) $start < 0 ) {
			throw new CloudSearchAPIException( 'Start must be a positive integer', 1 );
		}
	}

	public function set_start( $start ) {
		$this->__validate_start( $start );
		$this->start = $start;
	}

	public function add_rank( $field, $order ) {
		//http://docs.aws.amazon.com/cloudsearch/latest/developerguide/migrating.html shows asc/desc as lowercase
		$order                 = ( 'DESC' === strtoupper( $order ) ) ? 'desc' : 'asc';
		$this->ranks[ $field ] = $order;
	}

	public function get_query_string() {
		$ranks = array();

		foreach ( $this->ranks as $field => $order ) {
			//Use the sort parameter to specify the fields or expressions you want to use for sorting. You must explicitly specify the sort direction in the sort parameter. For example, sort=rank asc, date desc. The rank parameter is no longer supported.
			$ranks[] = sprintf( '%s %s', $field, $order );
		}

		$params = array_filter( array(
			'q'      => $this->boolean_query,
			'return' => implode( ',', $this->return_fields ),
			//Parameter 'return-fields' is no longer valid. Use 'return' instead.
			'size'   => $this->size,
			'start'  => $this->start,
			'sort'   => implode( ',', $ranks )
			//Use the sort parameter to specify the fields or expressions you want to use for sorting. You must explicitly specify the sort direction in the sort parameter. For example, sort=rank asc, date desc. The rank parameter is no longer supported.
		) );


		// build the facet fields ( see http://docs.aws.amazon.com/cloudsearch/latest/developerguide/faceting.html)
		foreach ( $this->facets as $field ) {
			$params[ 'facet.' . $field ] = json_encode( (object) array() );
		}


		// from amazon docs (migration guide) "Use the q parameter to specify search criteria for all requests. The bq parameter is no longer supported. To use the structured (Boolean) search syntax, specify q.parser=structured in the request."
		// from error received from cloudsearch "[*Deprecated*: Use the outer message field] Parameter 'bq' is no longer valid. Replace 'bq=query' with 'q.parser=structured&q=query'."
		if ( $this->boolean_query ) {
			$params = array_merge( $params, array( 'q.parser' => 'structured' ) );
		}


		//@todo this doesn't conform to the new API see: Use the facet.FIELD parameter to specify all facet options. The facet-FIELD-top-N, facet-FIELD-sort, and facet-FIELD-constraints parameters are no longer supported.
		if ( count( $this->facet_constraints ) ) {

			$field             = array_shift( array_keys( $this->facet_constraints ) );
			$constraint_fields = array_map( function ( $val ) {
				return array($val);
			}, $this->facet_constraints[ $field ] );
			$params[ 'facet.' . $field ] = json_encode( (object)array('buckets'=> $constraint_fields ) );

		}

		if ( count( $this->facet_top_n ) ) {
			foreach ( $this->facet_top_n as $field => $limit ) {
				$params[ 'facet-' . $field . '-top-n' ] = $limit;
			}
		}

		return http_build_query( $params );
	}

}

class CloudSearchAPIException extends Exception {

}