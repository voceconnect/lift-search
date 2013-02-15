<?php

class Lift_Domain_Manager {

	/**
	 *
	 * @var Cloud_Config_API 
	 */
	private $config_api;

	public function __construct( $access_key, $secret_key, $http_api ) {
		$this->config_api = new Cloud_Config_API( $access_key, $secret_key, $http_api );
	}

	public function credentials_are_valid() {
		return ( bool ) $this->config_api->DescribeDomains();
	}

	public function domain_exists( $domain_name ) {
		return ( bool ) $this->get_domain( $domain_name );
	}

	public function initialize_new_domain( $domain_name ) {
		if ( $this->domain_exists( $domain_name ) ) {
			return new WP_Error( 'domain_exists', 'There was an error creating the domain.  The domain already exists.' );
		}

		if ( is_wp_error( $error = $this->config_api->CreateDomain( $domain_name ) ) )
			return $error;

		$event_wathcher = Lift_Search::get_domain_event_watcher();
		$access_policies = $this->get_default_access_policies( $domain_name );

		$event_wathcher->when( array( $this, 'domain_is_created' ), array( $domain ) )
			->then( array( $this, 'apply_schema' ), array( $domain_name ) )
			->then( array( $this, 'apply_access_policy' ), array( $domain_name, $access_policies ) );

		return true;
	}

	public function apply_schema( $domain_name, $schema = null, &$changed_fields = array( ) ) {
		if ( is_null( $schema ) )
			$schema = apply_filters( 'lift_domain_schema', Cloud_Schemas::GetSchema() );

		if ( !is_array( $schema ) ) {
			return false;
		}

		$result = $this->config_api->DescribeIndexFields( $domain_name );
		if ( $result ) {
			$current_schema = $result->IndexFields;
		} else {
			$current_schema = array( );
		}
		if ( count( $current_schema ) ) {
			//convert to hashtable by name for hash lookup
			$current_schema = array_combine( array_map( function($field) {
						return $field->Options->IndexFieldName;
					}, $current_schema ), $current_schema );
		}

		foreach ( $schema as $index ) {
			$index = array_merge( array( 'options' => array( ) ), $index );
			if ( !isset( $current_schema[$index['field_name']] ) || $current_schema[$index['field_name']]->Options->IndexFieldType != $index['field_type'] ) {
				$response = $this->config_api->DefineIndexField( $domain_name, $index['field_name'], $index['field_type'], $index['options'] );

				if ( false === $response ) {
					Lift_Search::event_log( 'There was an error while applying the schema to the domain.', $this->config_api->get_last_error(), array( 'schema', 'error' ) );
					continue;
				} else {
					$changed_fields[] = $index['field_name'];
				}
			}
		}

		if ( count( $changed_fields ) ) {
			Lift_Search::get_domain_event_watcher()
				->when( array( $this, 'needs_indexing' ), array( $domain_name ) )
				->then( array( $this, 'index_documents' ), array( $domain_name ) )
				->then( array( 'Lift_Batch_Handler', 'queue_all' ) );
		}

		return true;
	}

	public function get_default_access_policies( $domain_name ) {
		$domain = $this->get_domain( $domain_name );

		$search_service = $domain->SearchService;
		$doc_service = $domain->DocService;

		$services = array( $search_service, $doc_service );
		$statement = array( );
		$net = '0.0.0.0/0';
		$warn = true; // for future error handling to warn of wide open access
		// try to get the IP address external services see to be more restrictive
		if ( $ip = $this->config_api->http_api->get( 'http://ifconfig.me/ip' ) ) {
			$net = sprintf( '%s/32', str_replace( "\n", '', $ip ) );
			$warn = false;
		}

		foreach ( $services as $service ) {
			if ( $service ) {
				$statement[] = array(
					'Effect' => 'Allow',
					'Action' => '*',
					'Resource' => $service->Arn,
					'Condition' => array(
						'IpAddress' => array(
							'aws:SourceIp' => array( $net ),
						)
					)
				);
			}
		}

		if ( !$statement ) {
			return false;
		}

		$policies = array( 'Statement' => $statement );

		return $policies;
	}

	public function apply_access_policy( $domain_name, $policies ) {
		if ( !$policies ) {
			return false;
		}

		if ( !$this->config_api->UpdateServiceAccessPolicies( $domain_name, $policies ) ) {
			Lift_Search::event_log( 'There was an error while applying the default access policy to the domain.', $this->config_api->get_last_error(), array( 'access policy', 'error' ) );
			return false;
		}

		return true;
	}

	public function domain_is_created( $domain_name ) {
		if ( $domain = $this->get_domain( $domain_name ) ) {
			return $domain->Created;
		}
		return false;
	}

	public function needs_indexing( $domain_name ) {
		if ( $domain = $this->get_domain( $domain_name ) ) {
			return $domain->RequiresIndexDocuments;
		}
		return false;
	}

	public function index_documents( $domain_name ) {
		return ( bool ) $this->config_api->IndexDocuments( $domain_name );
	}

	/**
	 * Returns the DomainStatus object for the given domain
	 * @param string $domain_name
	 * @return DomainStatus|boolean
	 */
	public function get_domain( $domain_name ) {
		$response = $this->config_api->DescribeDomains( array( $domain_name ) );
		if ( $response ) {
			$domain_list = $response->DescribeDomainsResponse->DescribeDomainsResult->DomainStatusList;
			if ( is_array( $domain_list ) && count( $domain_list ) ) {
				return $domain_list[0];
			} else {
				return false;
			}
		}
		return false;
	}

	/**
	 * Returns the document endpoint for the domain
	 * @param type $domain_name
	 * @return string|boolean
	 */
	public function get_document_endpoint( $domain_name ) {
		if ( $domain = $this->get_domain( $domain_name ) ) {
			return $domain->DocService->Endpoint;
		}
		return false;
	}

	/**
	 * Returns the search endpoint for the domain
	 * @param type $domain_name
	 * @return string|boolean
	 */
	public function get_search_endpoint( $domain_name ) {
		if ( $domain = $this->get_domain( $domain_name ) ) {
			return $domain->SearchService->Endpoint;
		}
		return false;
	}

	/**
	 * get a default service access policy. try to be restrictive and use
	 * the outbound ip/32 and fall back to allow everyone if it can't be determined
	 * 
	 * @param string $domain
	 * @return boolean|array 
	 */
	public function GetDefaultServiceAccessPolicy( $domain ) {
		
	}

	/**
	 * call UpdateServiceAccessPolicies for the domain with the given policies
	 * 
	 * @param string $domain
	 * @param array $policies
	 * @return boolean 
	 */
	public function UpdateServiceAccessPolicies( $domain, $policies ) {

		if ( !$policies ) {
			return false;
		}

		$payload = array(
			'AccessPolicies' => $policies,
			'DomainName' => $domain,
		);

		list($r, $config) = $this->_make_request( 'UpdateServiceAccessPolicies', $payload, null, false );

		if ( $r ) {
			$r = json_decode( $r );

			if ( isset( $r->Error ) ) {
				$this->set_last_error( $r );
			} else if ( isset( $r->UpdateServiceAccessPoliciesResponse->UpdateServiceAccessPoliciesResult->AccessPolicies ) ) {
				$policies = $r->UpdateServiceAccessPoliciesResponse->UpdateServiceAccessPoliciesResult->AccessPolicies;
				if ( !$options = json_decode( $policies->Options ) || 'Processing' != $policies->Status->State ) {
					// $policies->Options will be blank if there was a malformed request
					return false;
				}

				return true;
			}
		}

		return false;
	}

}

//cache domain
//run index asap


/*
 * @todo, convert Cloud_Config_API to object instance
 * 
 * Plugin ->
 *	Search
 *		Search Form
 *		WP_Query/Query_Vars/Etc
 *		WP_Query -> Boolean Converter
 *			Boolean -> CloudSearch Converter
 * 
 *	Document Submission
 *		Update Watcher 
 *		Update Queue 
 *		Update Submission
 *			Update to CloudSearch Converter
 * 
 *	Configuration/Status
 *		Setup
 *		Search Status
 *		Document Update Status
 *		
 */