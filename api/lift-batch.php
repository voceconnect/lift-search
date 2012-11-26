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
                        'code' => 300,
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