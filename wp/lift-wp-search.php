<?php

/**
 * Lift_WP_Search is a class that incorporates Lift search functionality into
 * Wordpress, if a Lift search were to fail, default WP search functionality
 * will still occur.
 *
 * The following filters have been implemented in the class inorder to modify
 * functionality:
 *
 *	lift_search_facets: Array - Modify the facets set on the Lift Search Query
 *
 *	lift_override_post_results: Boolean - If set to be true, a Lift search will be skipped
 *  and default WP search is performed
 *
 *	lift_filter_query: Lift_Search_Query - Modify the Lift_Search_Query that was
 *	created from the WP_Query
 *
 */
class Lift_WP_Search {

	static $facets = array( 'post_type', 'post_status', 'post_category', 'tag_input' );

	public static function init() {
		add_filter( 'posts_request', function( $request, $wp_query ){
			if( $wp_query->is_search() )
				return false;
			return $request;
		}, 10, 2);

		add_filter( 'posts_results', array(  __CLASS__, 'posts_results'), 10, 2 );
		add_filter( 'query_vars', function($query_vars) {
			return array_merge( $query_vars, array( 'facet', 'date_start', 'date_end', 'post_types' ) );
		});
	}

	/**
	 * Creates the Lift_Search_Query object
	 * @param WP_Query $wp_query
	 * @return object $lift_search_query
	 */
	public static function lift_search_query($wp_query){

		$lift_search_query = new Cloud_Search_Query();

		$lift_search_query->add_facet( apply_filters( 'lift_search_facets', self::$facets ) );

		$tax_queries = self::parse_tax_queries( $wp_query->tax_query );

		$parameters = array();

		// label
		$parameters[] = sprintf( "(label '%s')", $wp_query->get( 's' ) );

		// other params
		$post_type = $wp_query->get( 'post_types' );
		if ( $post_type && ( 'any' !== $post_type ) ) {
			$post_type_field_obj = new Lift_Field('post_type', $post_type);
			$post_type_field = new Lift_Field_Expression(array( $post_type_field_obj ));

			$parameters[] = self::build_match_expression( $post_type_field );
		}

		$parameters[] = self::get_query_post_status($wp_query);

		foreach ( array( 'post_category', 'post_tag' ) as $taxonomy ) {
			if ( isset( $tax_queries[$taxonomy] ) ) {
				$post_taxonomy_field_obj = new Lift_Field($taxonomy, $tax_queries[$taxonomy], false);
				$post_taxonomy_field = new Lift_Field_Expression(array( $post_type_field_obj ));

				$parameters[] = self::build_match_expression( $post_taxonomy_field );
			}
		}

		$date_start = $wp_query->get( 'date_start' );
		$date_end   = $wp_query->get( 'date_end' );

		if ( $date_start || $date_end ) {
			$parameters[] = "post_date_gmt:{$date_start}..{$date_end}";
		}

		$boolean_query = sprintf( '(and %s)', trim( implode( ' ', $parameters ) ) );

		if ( count( $parameters ) > 1 )
			$boolean_query = "(and {$boolean_query})";

		$lift_search_query->set_boolean_query( $boolean_query );

		// size
		$posts_per_page = $wp_query->get( 'posts_per_page' );
		$lift_search_query->set_size( $posts_per_page );

		// start
		$paged = $wp_query->get( 'paged' );
		$start = 0;

		if ( $paged > 1 ) {
			$start = ( $posts_per_page * ( $paged - 1 ) );
		}
		$lift_search_query->set_start( $start );

		$orderby_values = array(
			'date'      => 'post_date_gmt',
			'relevancy' => 'text_relevance',
			'lift'      => 'weighted_text_relevance'
		);

		// rank
		$order   = $wp_query->get( 'order' );
		$orderby = isset($orderby_values[$wp_query->get('orderby')]) ? $orderby_values[$wp_query->get('orderby')] : $orderby_values['relevancy'];

		if ( $orderby )
			$lift_search_query->add_rank( $orderby, $order );

		// return fields
		$lift_search_query->add_return_field( 'id' );

		return $lift_search_query;
	}

	public static function get_query_post_status($wp_query){
		$ret_status = array();
		if ( ! empty( $_REQUEST['post_status'] ) ) {
			$q_status = $_REQUEST['post_status'];
			if ( ! is_array( $q_status ) )
				$q_status = array($q_status);
			$public_states = get_post_stati(array('public' => true));
			if ( !in_array('any', $q_status) && !in_array('all', $q_status) ){
				foreach($q_status as $status){
					if(in_array($status, $public_states))
						$ret_status[] = $status;
				}
			}
		} elseif ( !$wp_query->is_singular ) {

			// Add public states.
			$public_states = get_post_stati( array('public' => true) );
			foreach ( (array) $public_states as $state ) {
				$ret_status[] = $state;
			}

			if ( $wp_query->is_admin ) {
				// Add protected states that should show in the admin all list.
				$admin_all_states = get_post_stati( array('protected' => true, 'show_in_admin_all_list' => true) );
				foreach ( (array) $admin_all_states as $state )
					$ret_status[] = $state;
			}

			$ret_status_field_objects = self::build_field_objs( 'post_status', $ret_status );

			$ret_status_field = new Lift_Field_Expression($ret_status_field_objects);

			if ( is_user_logged_in() ) {
				// Add private states that are limited to viewing by the author of a post or someone who has caps to read private states.
				$private_states = get_post_stati( array('private' => true) );

				$post_author_field_object = new Lift_Field('post_author', get_current_user_id(), false);

				foreach ( (array) $private_states as $state ) {
					$state_field_object = new Lift_Field('post_status', $state);
					$state_field = new Lift_Field_Expression(array( $state_field_object, $post_author_field_object ), 'and');

					$ret_status_field->field_objs[] = $state_field;
				}
			}

			$matched_expression = self::build_match_expression( $ret_status_field );
		}

		return $matched_expression;
	}

	/**
	 * Modifies a WP_Query's post results by using Lift search to get the post
	 * ids and returning the associated posts.  WP_Query is updated to reflect the
	 * counts returned from the Lift search
	 * @param array $posts
	 * @param WP_Query $wp_query
	 * @return array $posts
	 */
	public static function posts_results( $posts, $wp_query ){
		if ( !apply_filters( 'lift_override_post_results', true ) || !$wp_query->is_search() )
			return $posts;

		// filter the lift query
		$lift_query = apply_filters( 'lift_filter_query', self::lift_search_query( $wp_query ) );

		$lift_api = Lift_Search::get_search_api();

		$lift_results = $lift_api->sendSearch($lift_query);

		if( !empty($lift_results) && is_object($lift_results) ){
			// include response post ids in query
			$hits = array();
			array_map( function($hit) use (&$hits) {
					if(property_exists($hit, 'data') && property_exists($hit->data, 'id') ){
						$hits[] = (is_array($hit->data->id)) ? array_shift($hit->data->id) : $hit->data->id;
					}
				}, $lift_results->hits->hit
			);

			// include facets on query
			if ( isset( $lift_results->facets ) )
				$wp_query->set( 'facets', self::format_facet_constraints($lift_results->facets) );

			_prime_post_caches( $hits );
			$posts = array_values( array_filter( array_map( 'get_post', $hits ), function($val){
				return (!is_null($val) && !is_wp_error($val) && is_object($val) && $val->post_status == 'publish');
			}) );
			$wp_query->post_count = count($posts);
			$wp_query->found_posts = $lift_results->hits->found;
			$wp_query->max_num_pages = ceil( $wp_query->found_posts / get_query_var('posts_per_page') );
			$wp_query->posts = $posts;

		}
		return $posts;
	}

	/**
	 * Takes the array of tax queries attached to a WP_Query object and formats them for the API
	 * @param array $tax_queries Array of the tax queries
	 * @return array $parsed_tax_queries
	 */
	private static function parse_tax_queries( $tax_queries ) {
		$parsed_tax_queries = array();

		$defaults = array(
			'taxonomy' => '',
			'terms' => array(),
			'include_children' => true,
			'field' => 'term_id',
			'operator' => 'IN',
		);

		foreach ( $tax_queries as $tax_query ) {
			// Lift search currently only supports the IN operator for taxonomies, if anything else,
			if ( ! is_array( $tax_query ) ||
				!isset($tax_query['operator']) ||
				$tax_query['operator'] =! 'IN' ||
					empty( $tax_query['taxonomy'] ) ){
				continue;
			}

			$tax_query = array_merge( $defaults, $tax_query );

			$tax_query['terms'] = (array) $tax_query['terms'];

			// if terms ids are provided, we can just add them to the $parsed_tax_queries array,
			// otherwise we have to get each id
			if ( $tax_query['field'] == 'term_id' ){

				if ( isset( $parsed_tax_queries[$tax_query['taxonomy']] ) )
					$parsed_tax_queries[$tax_query['taxonomy']] = array_merge ( $parsed_tax_queries[$tax_query['taxonomy']], $tax_query['terms'] );
				else
					$parsed_tax_queries[$tax_query['taxonomy']] = $tax_query['terms'];

				if ( $tax_query['include_children'] ) {

					foreach ( $tax_query['terms'] as $term ) {
						$parsed_tax_queries[$tax_query['taxonomy']] = array_merge( $parsed_tax_queries[$tax_query['taxonomy']], get_term_children( $term, $tax_query['taxonomy'] ) );
					}
				}

			} else {

				$terms = array();

				foreach ( $tax_query['terms'] as $term ) {
					if ( $term_obj = get_term_by( $tax_query['field'], $term, $tax_query['taxonomy'] ) ) {

						$terms[] = $term_obj->term_id;

						// if tax query has the include_children argument set to true
						if ( $tax_query['include_children'] )
							$terms = array_merge ( $terms, get_term_children( $term_obj->term_id, $term_obj->taxonomy ) );
					}
				}

				$parsed_tax_queries[$tax_query['taxonomy']] = $terms;
			}
		}

		return $parsed_tax_queries;
	}

	private function build_field_objs( $field_name, $field_values, $is_string = true ) {

		$field_objects = array();

		foreach ( $field_values as $field_value ) {
			$field_objects[] = new Lift_Field($field_name, $field_value, ( $is_string === true ) ? $is_string : false);
		}

		return $field_objects;
	}

	/**
	 * Build a CloudSearch match expression for a field
	 *
	 * If $is_string, the value(s) will be enclosed in single quotes and the contents slashed
	 *
	 * If multiple values, the expression will take the form:  ($operator $field:$value1 ... $field:$valueN)
	 *
	 * @return string
	 */
	private static function build_match_expression( $field ) {

		$expression = array();

		if ( is_a( $field, 'Lift_Field_Expression' ) && is_array( $field->field_objs ) ) {

			$operator = strtolower( $field->operator );

			if ( ( 'and' !== $operator) && ( 'or' !== $operator ) ) {
				return '';
			}

			foreach ( $field->field_objs as $field_obj ) {

				if (is_a( $field_obj , 'Lift_Field_Expression' ) ) {

					$expression[] = self::build_match_expression( $field_obj );

				} else {

					if ( $field_obj->is_string ) {

						$expression[]= $field_obj->field_name . ":'" . addslashes( $field_obj->field_value ) . "'";

					} else {

						$expression[]= "{$field_obj->field_name}:{$field_obj->field_value}";
					}
				}
			}

			$expression = sprintf( '(%s %s)', $operator, implode( ' ', $expression ) );
		}

		return $expression;
	}

	private static function format_facet_constraints($facets){
		$formatted_facets = array();
		$facets = self::object_to_array($facets);
		if(is_array($facets)) {
			foreach($facets as $facet_type => $facet_value){
				$formatted_facets[$facet_type] = array();
				if(isset($facet_value['constraints'])){
					foreach($facet_value['constraints'] as $facet){
						if(isset($facet['value']) && isset($facet['count']))
							$formatted_facets[$facet_type][$facet['value']] = $facet['count'];
					}
				}
			}
		}
		return $formatted_facets;
	}

	private static function object_to_array($data){
		if (is_array($data) || is_object($data)){
			$result = array();
			foreach ($data as $key => $value){
				$result[$key] = self::object_to_array($value);
			}
			return $result;
		}
		return $data;
	}
}

class Lift_Field_Expression {
	public $field_objs;
	public $operator;

	function __construct($field_objs, $operator = 'or'){
		$this->field_objs = $field_objs;
		$this->operator = $operator;
	}
}


class Lift_Field {
	public $field_name;
	public $field_value;
	public $is_string;

	function __construct($field_name, $field_value, $is_string = true){
		$this->field_name = $field_name;
		$this->field_value = $field_value;
		$this->is_string = $is_string;
	}
}
