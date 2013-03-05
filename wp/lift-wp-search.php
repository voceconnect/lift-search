<?php

/**
 * Wrapper class for attaching cloudsearch data to a WP_Query object
 * @todo clean up the api around this
 */
class Lift_WP_Query {

	private static $instances;

	/**
	 * WP_Query instance reference for search
	 * @var WP_Query 
	 */
	public $wp_query;
	private $results = false;
	private $formatted_facets = false;

	/**
	 * Returns an instance of the search form based on the given WP_Query instance
	 * @global WP_Query $wp_query
	 * @param WP_Query $a_wp_query
	 * @return Lift_WP_Query
	 */
	public static function GetInstance( $a_wp_query = null ) {
		global $wp_query;
		if ( is_null( $a_wp_query ) ) {
			$a_wp_query = $wp_query;
		}

		$query_id = spl_object_hash( $a_wp_query );
		if ( !isset( self::$instances ) ) {
			self::$instances = array( );
		}

		if ( !isset( self::$instances[$query_id] ) ) {
			self::$instances[$query_id] = new Lift_WP_Query( $a_wp_query );
		}
		return self::$instances[$query_id];
	}

	private function __construct( $wp_query ) {
		$this->wp_query = $wp_query;
	}

	public function has_valid_result() {
		return $this->results !== false;
	}

	public function set_results( $results ) {
		$this->results = $results;
	}

	public function get_posts() {
		$posts = array( );
		if ( $this->has_valid_result() ) {
			// include response post ids in query
			$hits = array( );
			array_map( function($hit) use (&$hits) {
					if ( property_exists( $hit, 'data' ) && property_exists( $hit->data, 'id' ) ) {
						$hits[] = (is_array( $hit->data->id )) ? array_shift( $hit->data->id ) : $hit->data->id;
					}
				}, $this->results->hits->hit
			);

			_prime_post_caches( $hits );
			$posts = array_values( array_map( 'get_post', $hits ) );
			$this->wp_query->post_count = count( $posts );
			$this->wp_query->found_posts = $this->results->hits->found;
			$this->wp_query->max_num_pages = ceil( $this->wp_query->found_posts / $this->wp_query->get( 'posts_per_page' ) );
			$this->wp_query->posts = $posts;
		}
		return $posts;
	}

	/**
	 * Converts the WP_Query to a Cloud_Search_Query
	 * @return Cloud_Search_Query
	 */
	public function get_cs_query() {
		$cs_query = new Cloud_Search_Query();

		$cs_query->add_facet( apply_filters( 'lift_search_facets', array( 'post_type', 'post_status', 'taxonomy_category_id', 'taxonomy_post_tag_id' ) ) );

		$parameters = apply_filters( 'list_search_bq_parameters', array( sprintf( "(label '%s')", $this->wp_query->get( 's' ) ) ), $this );

		//filter to the current blog/site
		$parameters[] = new Lift_Expression_Set( 'and', array(
			new Lift_Expression_Field( 'site_id', lift_get_current_site_id(), false ),
			new Lift_Expression_Field( 'blog_id', get_current_blog_id(), false )
			) );

		$boolean_query = sprintf( '(and %s)', trim( implode( ' ', $parameters ) ) );

		$cs_query->set_boolean_query( $boolean_query );

		// size
		$posts_per_page = $this->wp_query->get( 'posts_per_page' );
		if($posts_per_page < 0) {
			$posts_per_page = 9999999;
		}
		$cs_query->set_size( $posts_per_page );

		// start
		$paged = $this->wp_query->get( 'paged' );
		$start = 0;

		if ( $paged > 1 ) {
			$start = ( $posts_per_page * ( $paged - 1 ) );
		}
		$cs_query->set_start( $start );

		$orderby_values = array(
			'date' => 'post_date_gmt',
			'relevancy' => 'text_relevance',
		);

		// rank
		$order = $this->wp_query->get( 'order' );
		$orderby = isset( $orderby_values[$this->wp_query->get( 'orderby' )] ) ? $orderby_values[$this->wp_query->get( 'orderby' )] : $orderby_values['relevancy'];

		if ( $orderby )
			$cs_query->add_rank( $orderby, $order );

		// return fields
		$cs_query->add_return_field( 'id' );

		do_action_ref_array( 'get_cs_query', array( $cs_query ) );

		return $cs_query;
	}

	/**
	 * Formats the facets into array
	 * @todo cache formatted output
	 * @return array
	 */
	public function get_facets() {
		if ( $this->formatted_facets === false ) {
			$this->formatted_facets = array( );
			if ( isset( $this->results->facets ) ) {
				$this->formatted_facets = $this->format_facet_constraints( $this->results->facets );
			}
		}
		return $this->formatted_facets;
	}

	private function format_facet_constraints( $facets ) {
		$formatted_facets = array( );
		$facets = self::object_to_array( $facets );
		if ( is_array( $facets ) ) {
			foreach ( $facets as $facet_type => $facet_value ) {
				$formatted_facets[$facet_type] = array( );
				if ( isset( $facet_value['constraints'] ) ) {
					foreach ( $facet_value['constraints'] as $facet ) {
						if ( isset( $facet['value'] ) && isset( $facet['count'] ) )
							$formatted_facets[$facet_type][$facet['value']] = $facet['count'];
					}
				}
			}
		}
		return $formatted_facets;
	}

	private function object_to_array( $data ) {
		if ( is_array( $data ) || is_object( $data ) ) {
			$result = array( );
			foreach ( $data as $key => $value ) {
				$result[$key] = self::object_to_array( $value );
			}
			return $result;
		}
		return $data;
	}

}

/**
 * Lift_WP_Search is a class that incorporates Lift search functionality into
 * Wordpress, if a Lift search were to fail, default WP search functionality
 * will still occur.
 *
 * The following filters have been implemented in the class inorder to modify
 * functionality:
 *
 * 	lift_search_facets: Array - Modify the facets set on the Lift Search Query
 *
 * 	lift_override_post_results: Boolean - If set to be true, a Lift search will be skipped
 *  and default WP search is performed
 *
 * 	lift_filter_query: Lift_Search_Query - Modify the Lift_Search_Query that was
 * 	created from the WP_Query
 *
 */
class Lift_WP_Search {

	public static function init() {
		add_filter( 'posts_request', array( __CLASS__, '_filter_posts_request' ), 10, 2 );

		add_filter( 'posts_results', array( __CLASS__, '_filter_posts_results' ), 10, 2 );
		add_filter( 'query_vars', function($query_vars) {
				return array_merge( $query_vars, array( 'facet', 'date_start', 'date_end', 'lift_post_type' ) );
			} );

		add_action( 'request', function($query_vars) {
				if ( isset( $query_vars['s'] ) && !isset( $query_vars['post_type'] ) && isset( $query_vars['lift_post_type'] ) ) {
					$lift_post_type = is_array( $query_vars['lift_post_type'] ) ? $query_vars['lift_post_type'] : array( $query_vars['lift_post_type'] );
					$in_search_post_types = get_post_types( array( 'exclude_from_search' => false ) );
					$post_types = array( );
					foreach ( $lift_post_type as $_post_type ) {
						if ( in_array( $_post_type, $in_search_post_types ) ) {
							$post_types[] = $_post_type;
						}
					}
					if ( !empty( $post_types ) ) {
						$query_vars['post_type'] = $post_types;
					}
				}
				return $query_vars;
			} );

		$bq_callbacks = array( '_bq_filter_post_type', '_bq_filter_post_date', '_bq_filter_post_status', '_bq_filter_taxonomies' );
		foreach ( $bq_callbacks as $callback_name ) {
			add_filter( 'list_search_bq_parameters', array( __CLASS__, $callback_name ), 10, 2 );
		}
	}

	/**
	 * Filters the sql for a WP_Query before it's sent to the DB.
	 * @param string $request
	 * @param WP_Query $wp_query
	 * @return string|bool false if the request is overwritten
	 */
	public static function _filter_posts_request( $request, $wp_query ) {
		$lift_query = Lift_WP_Query::GetInstance( $wp_query );
		if ( !apply_filters( 'lift_override_post_results', true ) || !$lift_query->wp_query->is_search() )
			return $request;

		// filter the lift query
		$cs_query = apply_filters( 'lift_filter_query', $lift_query->get_cs_query() );

		$lift_api = Lift_Search::get_search_api();

		$lift_results = $lift_api->sendSearch( $cs_query );

		if ( false !== $lift_results && is_object( $lift_results ) ) {
			$lift_query->set_results( $lift_results );
		}
		return $request;
	}

	/**
	 * Modifies a WP_Query's post results by using Lift search to get the post
	 * ids and returning the associated posts.  WP_Query is updated to reflect the
	 * counts returned from the Lift search
	 * @param array $posts
	 * @param WP_Query $wp_query
	 * @return array $posts
	 */
	public static function _filter_posts_results( $posts, $wp_query ) {
		$lift_query = Lift_WP_Query::GetInstance( $wp_query );
		if ( $lift_query->has_valid_result() )
			return $lift_query->get_posts();

		return $posts;
	}

	/**
	 * Builds the query param for the post type filter
	 * @param array $params
	 * @param Lift_WP_Query $lift_query
	 * @return array
	 */
	public static function _bq_filter_post_type( $params, $lift_query ) {
		$wp_query = $lift_query->wp_query;
		$post_type = $wp_query->get( 'post_type' );
		$actual_post_types = array( );
		$post_type_expression = null;

		if ( $wp_query->is_tax ) {
			if ( empty( $post_type ) ) {
				// Do a fully inclusive search for currently registered post types of queried taxonomies
				$post_type = array( );
				$taxonomies = wp_list_pluck( $wp_query->tax_query->queries, 'taxonomy' );
				foreach ( get_post_types( array( 'exclude_from_search' => false ) ) as $pt ) {
					$object_taxonomies = $pt === 'attachment' ? get_taxonomies_for_attachments() : get_object_taxonomies( $pt );
					if ( array_intersect( $taxonomies, $object_taxonomies ) )
						$post_type[] = $pt;
				}
				if ( !$post_type )
					$post_type = 'any';
			}
		}

		if ( 'any' == $post_type ) {
			$in_search_post_types = get_post_types( array( 'exclude_from_search' => false ) );
			if ( !empty( $in_search_post_types ) ) {
				$post_type_expression = new Lift_Expression_Set();
				foreach ( $in_search_post_types as $_post_type ) {
					$post_type_expression->addExpression( new Lift_Expression_Field( 'post_type', $_post_type ) );
				}
			}
		} elseif ( !empty( $post_type ) && is_array( $post_type ) ) {
			$post_type_expression = new Lift_Expression_Set();
			foreach ( $post_type as $_post_type ) {
				$post_type_expression->addExpression( new Lift_Expression_Field( 'post_type', $_post_type ) );
				$actual_post_types[] = $_post_type;
			}
		} elseif ( !empty( $post_type ) ) {
			$post_type_expression = new Lift_Expression_Field( 'post_type', $post_type );
			$actual_post_types[] = $post_type;
		} elseif ( $wp_query->is_attachment ) {
			$post_type_expression = new Lift_Expression_Field( 'post_type', 'attachment' );
			$actual_post_types[] = 'attachment';
		} elseif ( $wp_query->is_page ) {
			$post_type_expression = new Lift_Expression_Field( 'post_type', 'page' );
			$actual_post_types[] = 'page';
		} else {
			$post_type_expression = new Lift_Expression_Field( 'post_type', 'post' );
			$actual_post_types[] = 'post';
		}

		//setting the actual post types queried since wp_query doesn't update the query_var after making changes
		$wp_query->query_vars['lift_post_type'] = $actual_post_types;

		if ( !is_null( $post_type_expression ) ) {
			$params[] = ( string ) $post_type_expression;
		}
		return $params;
	}

	/**
	 * Builds the query param for the post status filter
	 * @param array $params
	 * @param Lift_WP_Query $lift_query
	 * @return array
	 */
	public static function _bq_filter_post_status( $params, $lift_query ) {
		$wp_query = $lift_query->wp_query;
		$q = $wp_query->query_vars;

		$user_ID = get_current_user_id();

		//mimic wp_query logic around post_type since it isn't performed on an accessible copy
		$post_type = $q['post_type'];
		if ( $wp_query->is_tax && empty( $post_type ) ) {
			$post_type = array( );
			$taxonomies = wp_list_pluck( $wp_query->tax_query->queries, 'taxonomy' );
			foreach ( get_post_types( array( 'exclude_from_search' => false ) ) as $pt ) {
				$object_taxonomies = $pt === 'attachment' ? get_taxonomies_for_attachments() : get_object_taxonomies( $pt );
				if ( array_intersect( $taxonomies, $object_taxonomies ) )
					$post_type[] = $pt;
			}
			if ( !$post_type )
				$post_type = 'any';
		}

		//direct copy from wp_query 3.5
		if ( is_array( $post_type ) ) {
			$post_type_cap = 'multiple_post_type';
		} else {
			$post_type_object = get_post_type_object( $post_type );
			if ( empty( $post_type_object ) )
				$post_type_cap = $post_type;
		}

		//direct copy from wp_query 3.5
		if ( 'any' == $post_type ) {
			//unused code
		} elseif ( !empty( $post_type ) && is_array( $post_type ) ) {
			//unused code
		} elseif ( !empty( $post_type ) ) {
			$post_type_object = get_post_type_object( $post_type );
		} elseif ( $wp_query->is_attachment ) {
			$post_type_object = get_post_type_object( 'attachment' );
		} elseif ( $wp_query->is_page ) {
			$post_type_object = get_post_type_object( 'page' );
		} else {
			$post_type_object = get_post_type_object( 'post' );
		}

		//direct copy from wp_query 3.5
		if ( !empty( $post_type_object ) ) {
			$edit_cap = $post_type_object->cap->edit_post;
			$read_cap = $post_type_object->cap->read_post;
			$edit_others_cap = $post_type_object->cap->edit_others_posts;
			$read_private_cap = $post_type_object->cap->read_private_posts;
		} else {
			$edit_cap = 'edit_' . $post_type_cap;
			$read_cap = 'read_' . $post_type_cap;
			$edit_others_cap = 'edit_others_' . $post_type_cap . 's';
			$read_private_cap = 'read_private_' . $post_type_cap . 's';
		}

		if ( !empty( $q['post_status'] ) ) {
			$q_status = $q['post_status'];
			$stati_expression = new Lift_Expression_Set( 'and' );

			if ( !is_array( $q_status ) )
				$q_status = explode( ',', $q_status );
			$r_stati_expression = new Lift_Expression_Set( );
			$p_stati_expression = new Lift_Expression_Set();
			$e_stati_expression = new Lift_Expression_Set( 'and' );

			if ( in_array( 'any', $q_status ) ) {
				foreach ( get_post_stati( array( 'exclude_from_search' => true ) ) as $status ) {
					$e_stati_expression->addExpression( new Lift_Expression_Field( 'post_status', '-' . $status ) );
				}
			} else {
				foreach ( get_post_stati() as $status ) {
					if ( in_array( $status, $q_status ) ) {
						if ( 'private' == $status ) {
							$p_stati_expression->addExpression( new Lift_Expression_Field( 'post_status', $status ) );
						} else {
							$r_stati_expression->addExpression( new Lift_Expression_Field( 'post_status', $status ) );
						}
					}
				}
			}

			if ( empty( $q['perm'] ) || 'readable' != $q['perm'] ) {
				foreach ( $p_stati_expression->sub_expressions as $expression ) {
					$r_stati_expression->addExpression( $expression );
				}
				unset( $p_stati_expression );
			}

			if ( isset( $r_stati_expression ) && count( $r_stati_expression ) ) {
				if ( !empty( $q['perm'] ) && 'editable' == $q['perm'] && !current_user_can( $edit_others_cap ) ) {
					$tmp_expression = new Lift_Expression_Set( 'and', array( new Lift_Expression_Field( 'post_author', $user_ID, false ), $r_stati_expression ) );
					$stati_expression->addExpression( $tmp_expression );
				} else {
					$stati_expression->addExpression( $r_stati_expression );
				}
			}

			if ( !empty( $p_stati_expression ) ) {
				if ( !empty( $q['perm'] ) && 'readable' == $q['perm'] && !current_user_can( $read_private_cap ) ) {
					$tmp_expression = new Lift_Expression_Set( 'and', array( new Lift_Expression_Field( 'post_author', $user_ID, false ), $p_stati_expression ) );
					$stati_expression->addExpression( $tmp_expression );
				} else {
					$stati_expression->addExpression( $p_stati_expression );
				}
			}
		} elseif ( !$wp_query->is_singular ) {
			$stati_expression = new Lift_Expression_Set( );

			// Add public states.
			$public_states = get_post_stati( array( 'public' => true ) );
			foreach ( ( array ) $public_states as $state ) {
				$stati_expression->addExpression( new Lift_Expression_Field( 'post_status', $state ) );
			}

			if ( $wp_query->is_admin ) {
				// Add protected states that should show in the admin all list.
				$admin_all_states = get_post_stati( array( 'protected' => true, 'show_in_admin_all_list' => true ) );
				foreach ( ( array ) $admin_all_states as $state )
					$stati_expression->addExpression( new Lift_Expression_Field( 'post_status', $state ) );
			}

			if ( is_user_logged_in() ) {
				// Add private states that are limited to viewing by the author of a post or someone who has caps to read private states.
				$private_states = get_post_stati( array( 'private' => true ) );
				foreach ( ( array ) $private_states as $state ) {
					if ( current_user_can( $read_private_cap ) ) {
						$stati_expression->addExpression( new Lift_Expression_Field( 'post_status', $state ) );
					} else {
						$tmp_expression = new Lift_Expression_Set( 'and', array(
							new Lift_Expression_Field( 'post_author', $user_ID, false ),
							new Lift_Expression_Field( 'post_status', $state ),
							) );
						$stati_expression->addExpression( $tmp_expression );
					}
				}
			}
		}

		$params[] = ( string ) $stati_expression;
		return $params;
	}

	/**
	 * Builds the query param for the taxonomies
	 * @param array $params
	 * @param Lift_WP_Query $lift_query
	 * @return array
	 */
	public static function _bq_filter_taxonomies( $params, $lift_query ) {
		$indexed_taxonomies = Lift_Search::get_indexed_taxonomies();

		foreach ( $lift_query->wp_query->tax_query->queries as $tax_query ) {
			if ( in_array( $tax_query['taxonomy'], $indexed_taxonomies ) ) {
				if ( empty( $tax_query['terms'] ) )
					continue;

				if ( $tax_query['field'] != 'term_id' ) {
					$tax_query = clone $tax_query;
					$lift_query->wp_query->tax_query->transform_query( $tax_query, 'term_id' );
				}

				$term_expressions = array( );
				foreach ( $tax_query['terms'] as $term_id ) {
					//note that taxonomies are stored as literal fields and literal fields are strings
					$term_expressions[] = new Lift_Expression_Field( 'taxonomy_' . $tax_query['taxonomy'] . '_id', $term_id );
				}
				switch ( $tax_query['operator'] ) {
					case 'IN':
						$exp_set = new Lift_Expression_Set( 'or', $term_expressions );
						$params[] = ( string ) $exp_set;
						break;
					case 'NOT IN':
						$exp_set = new Lift_Expression_Set( 'or', $term_expressions );
						$params[] = ( string ) $exp_set;
						$not_set = new Lift_Expression_Set( 'not', array( $exp_set ) );
						$params[] = ( string ) $not_set;
						break;
					case 'AND':
						$exp_set = new Lift_Expression_Set( 'and', $term_expressions );
						$params[] = ( string ) $exp_set;
						break;
					default:
						continue 2;
				}
			}
		}

		return $params;
	}

	public static function _bq_filter_post_date( $params, $lift_query ) {
		$wp_query = $lift_query->wp_query;
		$date_start = $wp_query->get( 'date_start' );
		$date_end = $wp_query->get( 'date_end' );

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
			$params[] = "post_date_gmt:{$date_start}..{$date_end}";

		return $params;
	}

}

abstract class aLift_Expression {

	public function __construct() {
		//nothing to do here
	}

	abstract public function __toString();
}

class Lift_Expression_Set extends aLift_Expression implements Countable {

	public $sub_expressions;
	public $operator;

	public function __construct( $operator = 'or', $sub_expressions = array( ) ) {
		parent::__construct();
		$this->sub_expressions = $sub_expressions;
		$this->operator = $operator;
	}

	/**
	 * 
	 * @param aLift_Expression $expression 
	 */
	public function addExpression( $expression ) {
		$this->sub_expressions[] = $expression;
	}

	public function __toString() {
		return sprintf( '(%s %s)', $this->operator, implode( ' ', $this->sub_expressions ) );
	}

	public function count() {
		return count( $this->sub_expressions );
	}

}

class Lift_Expression_Field extends aLift_Expression {

	public $field_name;
	public $field_value;
	public $is_string;

	public function __construct( $field_name, $field_value, $is_string = true ) {
		parent::__construct();
		$this->field_name = $field_name;
		$this->field_value = $field_value;
		$this->is_string = $is_string;
	}

	public function __toString() {
		if ( $this->is_string ) {
			return $this->field_name . ":'" . addslashes( $this->field_value ) . "'";
		} else {
			return "{$this->field_name}:{$this->field_value}";
		}
	}

}
