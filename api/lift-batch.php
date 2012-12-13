<?php

/**
 * Handle batches to send to Amazon Cloud Search
 *
 * @class LiftBatch
 */
class Lift_Batch {

	/**
	 * @property documents
	 * @type array
	 */
	public $documents = array( );

	/**
	 * Max size for the batch
	 * @property BATCH_LIMIT
	 * @type integer
	 * @constant
	 */

	const BATCH_LIMIT = 5242880;

	/**
	 * Max size for a single document
	 * @property DOCUMENT_LIMIT
	 * @type integer
	 * @constant
	 */
	const DOCUMENT_LIMIT = 1048576;

	/**
	 * Collect all of the error data
	 * @property errors
	 * @type array
	 */
	public $errors = array( );

	/**
	 * @constructor
	 * @param array|object $documents
	 * @throws Lift_Batch_Exception
	 */
	public function __construct( $documents = false ) {
		if ( is_array( $documents ) ) {
			$this->add_documents( $documents );
		} else if ( is_object( $documents ) ) {
			$this->add_document( $documents );
		} else if ( $documents === false ) {
			// pass
		} else {
            $this->errors[] = array(
                'code' => 100,
                'message' => 'Tried to pass an invalid data type to constructor',
            );
			throw new Lift_Batch_Exception( $this->errors );
		}
	}

	/**
	 * @method check_required_fields
	 * @param object $document
	 * @return boolean
	 */
	public function check_required_fields( $document ) {
		if ( !isset( $document->lang ) || empty( $document->lang ) ) {
			$document->lang = 'en';
		}

		foreach (array( 'type', 'id', 'version' ) as $prop) {
			if ( !property_exists( $document, $prop ) ) {
				$this->errors[] = array(
					'code' => 300,
					'message' => sprintf( 'Required field is missing: %s', $prop )
				);
			}
			if ( empty( $document->{$prop} ) ) {
				$this->errors[] = array(
                    'code' => 310,
					'message' => sprintf( 'Required field is empty: %s', $prop )
				);
			}
		}
		if ( !empty( $this->errors ) ) {
			return false;
		} else {
			return true;
		}
	}

	/**
	 * @method add_document
	 * @param object $document
	 * @throws Lift_Batch_Exception
	 */
	public function add_document( $document ) {
		$document = (object) $document;
		if ( !$this->can_add( $document ) ) {
			throw new Lift_Batch_Exception( $this->errors );
		}
		$this->documents[] = $this->sanitize( $document );
	}

	/**
	 * @method add_documents
	 * @param array $documents
	 * @throws Lift_Batch_Exception
	 */
	public function add_documents( $documents ) {
		if ( is_array( $documents ) ) {

			if ( empty( $documents ) ) {
				$this->errors[] = array(
                    'code' => 220,
                    'message' => 'Passed empty array'
                );
				throw new Lift_Batch_Exception( $this->errors );
			}
			for ($i = 0; $i < count( $documents ); $i++) {
				try {
					$this->add_document( $documents[$i] );
				} catch ( Lift_Batch_Exception $e ) {
					$this->errors[] = array(
                        'code' => 400,
						'message' => sprintf( 'Failed adding document at index %s', $i ),
						'failedIndex' => $i
					);
					throw new Lift_Batch_Exception( $this->errors );
				}
			}
			return $this->documents;
		} else {
            $this->errors[] = array(
                'code' => 110,
                'message' => sprintf( 'Expecting array, given: %s', gettype( $documents ) ),
            );
			throw new Lift_Batch_Exception( $this->errors );
		}
	}

	/**
	 * @method filter_where
	 * @param string $where
	 * @return string $where
	 */
	public static function filter_where( $where = '' ) {
		global $wpdb;
		$where .= sprintf( " AND %s.ID >= %d", $wpdb->posts, get_transient( 'lift-filter-where-' . getmypid() ) );
		return $where;
	}

	/**
	 * Args:
	 * 
	 * p				- post ID to remove
	 * posts_per_page	- size of batch (default 500, max 1000)
	 * start_from		- post ID to start removing from (default is lowest post ID)
	 * 
	 * @method delete_document
	 * @param array $args
	 * @return array $$response
	 */
	public function delete_document( $args = array() ) {
		$q_args = array(
			'posts_per_page' => 500,
			'post_type' => 'lift_queued_document',
			'fields' => 'ids',
			'orderby' => 'ID',
			'order' => 'ASC'
		);

		// change batch size
		if ( array_key_exists('posts_per_page', $args) ) {
			$posts_per_page = (int)$args['posts_per_page'] == -1 ? 999999999 : (int)$args['posts_per_page'];
			$q_args['posts_per_page'] = min( 1000, max( 1, $posts_per_page ) );
		}

		// remove batch starting from specific ID
		if ( array_key_exists('start_from', $args) ) {
			if ( $q_args['posts_per_page'] > 1 ) {
				$from_id = (int)$args['start_from'];

				set_transient( 'lift-filter-where-' . getmypid(), $from_id );
				add_filter( 'posts_where', array( __CLASS__, 'filter_where' ) );
			}
			else {
				$args['p'] = (int)$args['start_from'];
			}
		}

		// remove specific ID
		if ( array_key_exists('p', $args) ) {
			$q_args['p'] = (int)$args['p'];
		}

		$q = new WP_Query($q_args);

		// remove fiter data, if necessary
		remove_filter( 'posts_where', array( __CLASS__, 'filter_where' ) );
		delete_transient( 'lift-filter-where-' . getmypid() );


		$deleted = array();
		$not_deleted = array();

		if ($q->have_posts()) {
			foreach ($q->posts as $post_id) {
				if ($p = wp_delete_post($post_id)){
					$deleted[] = $post_id;
				} else {
					$not_deleted[] = $post_id;
				}
			}
		}

		$response['success'] = (bool) (!$not_deleted);
		$response['error'] = (bool) ($not_deleted);
		$response['deleted'] = $deleted;
		$response['failed'] = $not_deleted;

		return $response;
	}

	/**
	 * @method check_document_length
	 * @param object $document
	 * @return boolean
	 */
	public function check_document_length( $document ) {
		$json = json_encode( $document );
		return (bool) (strlen( $json ) <= self::DOCUMENT_LIMIT);
	}

	/**
	 * @method check_documents_length
	 * @return boolean
	 */
	public function check_documents_length() {
		return (bool) ($this->get_documents_length() <= self::BATCH_LIMIT);
	}

	/**
	 * @method get_documents_length
	 * @return integer
	 */
	public function get_documents_length() {
		return (strlen( json_encode( $this->documents ) ));
	}

	/**
	 * @method can_add
	 * @param object $document
	 * @return boolean
	 */
	public function can_add( $document ) {
		if ( !is_object( $document ) || !$this->check_required_fields( $document ) ) {
            $this->errors[] = array(
                'code' => 120,
                'message' => 'Document is not an object',
            );
			throw new Lift_Batch_Exception( $this->errors );
		}
		$localDocs = $this->documents;
		$localDocs[] = $document;
		$json = json_encode( $localDocs );
		if ( strlen( $json ) <= self::BATCH_LIMIT ) {
			return true;
		} else {
			$this->errors[] = array(
                'code' => 500,
				'message' => 'Batch limit reached',
				'current_count' => count( $this->documents ),
				'current_size' => $this->get_documents_length()
			);
			return false;
		}
        
        return false;
	}

	/**
	 * Check the object values and update if needed
	 * @method sanitize
	 * @param object $document
	 * return object
	 */
	public function sanitize( $document ) {
		if ( property_exists($document, 'fields') && is_array( $document->fields ) ) {
			$document->fields = array_change_key_case($document->fields);
			foreach ($document->fields as $key => $value) {
				if ( $value === null ) {
					$document->fields[$key] = "";
				}
			}
		}
		return $document;
	}

	public function convert_to_JSON() {
		$json = json_encode( $this->documents );
		if ( empty( $this->documents ) ||
				$json === null ||
				!$json ) {
			return false;
		} else {
			return $json;
		}
	}

	public function convert_to_XML() {
		return true;
	}

}

/**
 * @class LiftBatchException
 * @extends Exception
 */
class Lift_Batch_Exception extends Exception {

	public $message = 'There was an error';
	public $errors = array( );

	public function __construct( $errors ) {
		if ( is_string( $errors ) ) {
			$this->message = $errors;
		} else if ( is_array( $errors ) ) {
			$this->errors = $errors;
		}
	}

	public function get_errors() {
		return $this->errors;
	}

}