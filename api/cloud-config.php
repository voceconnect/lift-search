<?php

require_once('cloud-schemas.php');

class Cloud_Config {

	const DATE_FORMAT_SIGV4 = 'Ymd\THis\Z';

	private $endpoint = 'https://cloudsearch.us-east-1.amazonaws.com';
	private $api_version = '2011-02-01';

	public function __construct( $credentials, $api, $operation, $payload = array( ) ) {
		$this->operation = $operation;
		$this->payload = $payload;
        $this->key = $credentials['access-key-id'];
        $this->secret_key = $credentials['secret-access-key'];
		$this->http_api = $api;
	}

	public function to_query_string( $array ) {
		$temp = array( );

		foreach ($array as $key => $value) {
			if ( is_string( $key ) && !is_array( $value ) ) {
				$temp[] = rawurlencode( $key ) . '=' . rawurlencode( $value );
			}
		}

		return implode( '&', $temp );
	}

	public function authenticate() {
		// Determine signing values
		$current_time = time();
		$timestamp = gmdate( self::DATE_FORMAT_SIGV4, $current_time );

		// Initialize
		$this->headers = array( );
		$this->signed_headers = array( );
		$this->canonical_headers = array( );
		$this->query = array( 'body' => is_array( $this->payload ) ? $this->payload : array( ) );

		//
		$this->query['body']['Action'] = $this->operation;
		$this->query['body']['Version'] = $this->api_version;

		// Do a case-sensitive, natural order sort on the array keys.
		uksort( $this->query['body'], 'strcmp' );

		// Parse our request.
		$parsed_url = parse_url( $this->endpoint );
		$host_header = strtolower( $parsed_url['host'] );

		// Generate the querystring from $this->query
		$this->querystring = $this->to_query_string( $this->query );

		// Compose the request.
		$request_url = $this->endpoint . ( isset( $parsed_url['path'] ) ? '' : '/' );

		$this->querystring = $this->canonical_querystring();

		$this->headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=utf-8';
		$this->headers['X-Amz-Date'] = $timestamp;
		$this->headers['Content-Length'] = strlen( $this->querystring );
		$this->headers['Content-MD5'] = $this->hex_to_base64( md5( $this->querystring ) );
		$this->headers['Host'] = $host_header;
		$this->headers['Accept'] = 'application/json';

		// Sort headers
		uksort( $this->headers, 'strnatcasecmp' );

		// Add headers to request and compute the string to sign
		foreach ($this->headers as $header_key => $header_value) {
			// Strip line breaks and remove consecutive spaces. Services collapse whitespace in signature calculation
			$this->headers[$header_key] = preg_replace( '/\s+/', ' ', trim( $header_value ) );

			$this->canonical_headers[] = strtolower( $header_key ) . ':' . $header_value;
			$this->signed_headers[] = strtolower( $header_key );
		}

		$this->headers['Authorization'] = $this->authorization( $timestamp );

		$post = $this->http_api->post( $request_url, $this->canonical_querystring(), $this->headers );
		$this->status_code = $this->http_api->getStatusCode();
		return $post;
	}

	public function hex_to_base64( $str ) {
		$raw = '';

		for ($i = 0; $i < strlen( $str ); $i += 2) {
			$raw .= chr( hexdec( substr( $str, $i, 2 ) ) );
		}

		return base64_encode( $raw );
	}

	protected function canonical_querystring() {
		if ( !isset( $this->canonical_querystring ) ) {
			$this->canonical_querystring = $this->to_signable_string( $this->query['body'] );
		}

		return $this->canonical_querystring;
	}

	public function encode_signature2( $string ) {
		$string = rawurlencode( $string );
		return str_replace( '%7E', '~', $string );
	}

	public function to_signable_string( $array ) {
		$t = array( );

		foreach ($array as $k => $v) {
            if ( is_array($v) ) {
                // json encode value if it is an array
                $value = $this->encode_signature2( json_encode( $v ) );
            } else {
                $value = $this->encode_signature2( $v );
            }
			$t[] = $this->encode_signature2( $k ) . '=' . $value;
		}

		return implode( '&', $t );
	}

	protected function authorization( $datetime ) {
		$access_key_id = $this->key;

		$parts = array( );
		$parts[] = "AWS4-HMAC-SHA256 Credential=${access_key_id}/" . $this->credential_string( $datetime );
		$parts[] = 'SignedHeaders=' . implode( ';', $this->signed_headers );
		$parts[] = 'Signature=' . $this->hex16( $this->signature( $datetime ) );

		return implode( ',', $parts );
	}

	protected function signature( $datetime ) {
		$k_date = $this->hmac( 'AWS4' . $this->secret_key, substr( $datetime, 0, 8 ) );
		$k_region = $this->hmac( $k_date, $this->region() );
		$k_service = $this->hmac( $k_region, $this->service() );
		$k_credentials = $this->hmac( $k_service, 'aws4_request' );
		$signature = $this->hmac( $k_credentials, $this->string_to_sign( $datetime ) );

		return $signature;
	}

	protected function string_to_sign( $datetime ) {
		$parts = array( );
		$parts[] = 'AWS4-HMAC-SHA256';
		$parts[] = $datetime;
		$parts[] = $this->credential_string( $datetime );
		$parts[] = $this->hex16( $this->hash( $this->canonical_request() ) );

		$this->string_to_sign = implode( "\n", $parts );

		return $this->string_to_sign;
	}

	protected function credential_string( $datetime ) {
		$parts = array( );
		$parts[] = substr( $datetime, 0, 8 );
		$parts[] = $this->region();
		$parts[] = $this->service();
		$parts[] = 'aws4_request';

		return implode( '/', $parts );
	}

	protected function canonical_request() {
		$parts = array( );
		$parts[] = 'POST';
		$parts[] = $this->canonical_uri();
		$parts[] = ''; // $parts[] = $this->canonical_querystring();
		$parts[] = implode( "\n", $this->canonical_headers ) . "\n";
		$parts[] = implode( ';', $this->signed_headers );
		$parts[] = $this->hex16( $this->hash( $this->canonical_querystring() ) );

		$this->canonical_request = implode( "\n", $parts );

		return $this->canonical_request;
	}

	protected function region() {
		return 'us-east-1';
	}

	protected function service() {
		return 'cloudsearch';
	}

	protected function canonical_uri() {
		return '/';
	}

	protected function hex16( $value ) {
		$result = unpack( 'H*', $value );
		return reset( $result );
	}

	protected function hmac( $key, $string ) {
		return hash_hmac( 'sha256', $string, $key, true );
	}

	protected function hash( $string ) {
		return hash( 'sha256', $string, true );
	}

}

class Cloud_Config_Request {

	static $last_error;

	public static function GetLastError() {
		return self::$last_error;
	}

	protected static function SetLastError( $error ) {
		self::$last_error = $error;
	}

	/**
	 * Turn a nested array into dot-separated 1 dimensional array
	 *
	 * @param $array
	 * @param string $prefix
	 * @return array
	 */
	protected static function __flatten_keys( $array, $prefix = '' ) {

		$result = array();

		foreach( $array as $key => $value ) {

			if ( is_array( $value ) ) {

				$result += self::__flatten_keys( $value, ( $prefix . $key . '.' ) );

			} else {

				$result[$prefix . $key] = $value;

			}

		}

		return $result;

	}

	/**
	 * Helper method to make a Configuration API request, stores error if encountered
	 *
	 * @param string $method
	 * @param array $payload
	 * @return array [response string, Cloud_Config object used for request]
	 */
	protected static function __make_request( $method, $payload = array() ) {

		if ( $payload ) {

			$payload = self::__flatten_keys( $payload );

		}
        
        $credentials['key'] = Lift_Search::get_access_key_id();
        $credentials['secret_key'] = Lift_Search::get_secret_access_key();
        $api = Lift_Search::get_http_api();

		$config = new Cloud_Config( $credentials, $api, $method, $payload);

		$r = $config->authenticate();

		if ( $r ) {

			$r_json = json_decode( $r );

			if ( isset( $r_json->Error ) ) {
                
                self::SetLastError( $r_json );

				return false;

			}

		}

		return array($r, $config);

	}

	/**
	 * @method GetDomains
	 * @return boolean 
	 */
	public static function GetDomains( $domain_names = array() ) {

		$payload = array();

		if ( ! empty( $domain_names ) ) {

			foreach (array_values($domain_names) as $i => $domain_name) {
				$payload['DomainNames.member.' . ($i + 1)] = $domain_name;
			}

		}

		list($r, $config) = self::__make_request( 'DescribeDomains', $payload );

		return ( $r ? json_decode( $r ) : false );

	}

	/**
	 * Test if a domain exists
	 *
	 * @method TestDomain
	 * @param string $domain 
	 * @return boolean
	 */
	public static function TestDomain( $domain_name ) {
		$domains = self::GetDomains(array($domain_name));
		if ( $domains ) {
			$ds = $domains->DescribeDomainsResponse->DescribeDomainsResult->DomainStatusList;
			return ( 1 === count( $ds ) );
		}
		return false;
	}
	
	/**
	 * @method CreateDomain
	 * @param string $domain_name 
	 */
	public static function CreateDomain( $domain_name ) {

		list($r, $config) = self::__make_request( 'CreateDomain', array( 'DomainName' => $domain_name ) );
        
		return ( $r ? json_decode( $r ) : false );

	}

	/**
	 * Retrieve Document Endpoint for a domain
	 *
	 * @method DocumentEndpoint
	 * @param string $domain 
	 * @return string|boolean
	 */
	public static function DocumentEndpoint( $domain_name ) {
		$domains = self::GetDomains( array( $domain_name ) );
		if ( $domains ) {
			$d = $domains->DescribeDomainsResponse->DescribeDomainsResult->DomainStatusList;

			if ( $d ) {
				return $d[0]->DocService->Endpoint;
			}
		}
		return false;
	}

	/**
	 * Retrieve Search Endpoint for a domain
	 *
	 * @method SearchEndpoint
	 * @param string $domain 
	 * @return string|boolean
	 */
	public static function SearchEndpoint( $domain_name ) {
		$domains = self::GetDomains( array( $domain_name ) );
		if ( $domains ) {
			$d = $domains->DescribeDomainsResponse->DescribeDomainsResult->DomainStatusList;

			if ( $d ) {
				return $d[0]->SearchService->Endpoint;
			}
		}
		return false;
	}
    
    public static function DescribeDomain( $domain_name ) {
        $domains = self::GetDomains(  array($domain_name)  );
		if ( $domains ) {
			$d = $domains->DescribeDomainsResponse->DescribeDomainsResult->DomainStatusList;

			if ( $d ) {
				return $d[0];
			} else {
                return false;
            }
		}
		return false;
    }
    
    public static function DescribeServiceAccessPolicies( $domain_name ) {
        list($r, $config) = self::__make_request( 'DescribeServiceAccessPolicies', array( 'DomainName' => $domain_name ) );

		return ( $r ? json_decode( $r ) : false );
    }
    
    /**
	 * Retrieve Search Service endpoint for a domain
	 *
	 * @method SearchService
	 * @param string $domain 
	 * @return string|boolean
	 */
	public static function SearchService( $domain_name ) {
		$domain = self::DescribeDomain( $domain_name );
		if ( $domain ) {
			return $domain->SearchService;
		}
        
		return false;
	}
    
    /**
	 * Retrieve Doc Service endpoint for a domain
	 *
	 * @method DocService
	 * @param string $domain 
	 * @return string|boolean
	 */
	public static function DocService( $domain_name ) {
		$domain = self::DescribeDomain( $domain_name );
		if ( $domain ) {
			return $domain->DocService;
		}
        
		return false;
	}

	/**
	 * Define a new Rank Expression
	 *
	 * @param string $domain
	 * @param string $rank_name
	 * @param string $rank_expression
	 * @return array|bool|mixed
	 */
	public static function DefineRankExpression( $domain, $rank_name, $rank_expression ) {

		$payload = array(
			'DomainName'     => $domain,
			'RankExpression' => array(
				'RankName'       => $rank_name,
				'RankExpression' => $rank_expression
			)
		);

		list($r, $config) = self::__make_request( 'DefineRankExpression', $payload );

		if ( $r ) {

			$r = json_decode( $r );

			if ( isset( $r->DefineRankExpressionResponse->DefineRankExpressionResult->RankExpression ) ) {

				return true;

			}

		}

		return false;

	}

	/**
	 * Delete a Rank Expression
	 *
	 * @param string $domain
	 * @param string $rank_name
	 * @return array|bool|mixed
	 */
	public static function DeleteRankExpression( $domain, $rank_name ) {

		$payload = array(
			'DomainName' => $domain,
			'RankName'   => $rank_name,
		);

		list($r, $config) = self::__make_request( 'DeleteRankExpression', $payload );

		if ( $r ) {

			$r = json_decode( $r );

			if ( isset( $r->DeleteRankExpressionResponse->DeleteRankExpressionResult->RankExpression ) ) {

				return true;

			}

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
    public static function GetDefaultServiceAccessPolicy( $domain ) {
        $search_service = self::SearchService( $domain );
        $doc_service = self::DocService( $domain );
      
        $services = array($search_service, $doc_service);
        $statement = array();
        $net = '0.0.0.0/0';
        $warn = true; // for future error handling to warn of wide open access
        
        // try to get the IP address external services see to be more restrictive
        if ( $ip = Lift_Search::get_http_api()->get( 'http://ifconfig.me/ip' ) ) {
            $net = sprintf( '%s/32', str_replace( "\n", '', $ip) );
            $warn = false;
        }
        
        foreach ($services as $service) {
            if ($service) {
                $statement[] = array(
                    'Effect' => 'Allow',
                    'Action' => '*',
                    'Resource' => $service->Arn,
                    'Condition' => array(
                        'IpAddress' => array(
                            'aws:SourceIp' => array($net),
                        )
                    )
                );
            }
        }
        
        if ( ! $statement ) {
            return false;
        }
        
        $policies = array('Statement' => $statement);
        
        return $policies;
    }
    
    /**
     * call UpdateServiceAccessPolicies for the domain with the given policies
     * 
     * @param string $domain
     * @param array $policies
     * @return boolean 
     */
    public static function UpdateServiceAccessPolicies( $domain, $policies ) {
        
        if ( ! $policies ) {
            return false;
        }
        
        $payload = array(
            'AccessPolicies' => $policies,
            'DomainName' => $domain,
        );

        list($r, $config) = self::__make_request( 'UpdateServiceAccessPolicies', $payload );

		if ( $r ) {
			$r = json_decode( $r );

			if ( isset( $r->Error ) ) {
				self::SetLastError( $r );
			} else if ( isset( $r->UpdateServiceAccessPoliciesResponse->UpdateServiceAccessPoliciesResult->AccessPolicies ) ) {
                $policies = $r->UpdateServiceAccessPoliciesResponse->UpdateServiceAccessPoliciesResult->AccessPolicies;
                if ( ! $options = json_decode( $policies->Options ) || 'Processing' != $policies->Status->State ) {
                    // $policies->Options will be blank if there was a malformed request
                    return false;
                }
                
				return true;
			}
		}

		return false;
    }
    
    /**
	 * @method IndexDocuments
	 * @param string $domain_name 
     * 
     * @return bool true if request completed and documents will be/are being
     * indexed or false if request could not be completed or domain was in a 
     * status that documents could not be indexed
	 */
	public static function IndexDocuments( $domain_name ) {

		list($r, $config) = self::__make_request( 'IndexDocuments', array( 'DomainName' => $domain_name ) );

		return ( isset( $config->status_code ) && 200 == $config->status_code ) ;

	}

	public static function __parse_index_options( $field_type, $passed_options = array() ) {

		$field_types = array(
			'uint' => array(
				'option_name' => 'UIntOptions',
				'options' => array(
					'default' => array(
						'name'    => 'DefaultValue',
						'default' => null
					)
				)
			),
			'text' => array(
				'option_name' => 'TextOptions',
				'options' => array(
					'default' => array(
						'name'    => 'DefaultValue',
						'default' => null
					),
					'facet'   => array(
						'name'    => 'FacetEnabled',
						'default' => 'false'
					),
					'result'  => array(
						'name'    => 'ResultEnabled',
						'default' => 'false'
					)
				)
			),
			'literal' => array(
				'option_name' => 'LiteralOptions',
				'options' => array(
					'default' => array(
						'name'    => 'DefaultValue',
						'default' => null
					),
					'facet'   => array(
						'name'    => 'FacetEnabled',
						'default' => 'false'
					),
					'result'  => array(
						'name'    => 'ResultEnabled',
						'default' => 'false'
					),
					'search'  => array(
						'name'    => 'SearchEnabled',
						'default' => 'false'
					)
				)
			)
		);

		$index_option_name = $field_types[$field_type]['option_name'];
		$index_options = array();

		foreach ( $field_types[$field_type]['options'] as $option_key => $option_info ) {

			$option_name  = $option_info['name'];
			$option_value = $option_info['default'];

			if ( isset( $passed_options[$option_key] ) ) {

				$option_value = $passed_options[$option_key];

			}

			if ( ! is_null( $option_value ) ) {

				$index_options[$option_name] = $option_value;

			}

		}

		return array( $index_option_name => $index_options );

	}

	/**
	 * Define a new index field
	 *
	 * @param string $domain
	 * @param string $field_name
	 * @param string $field_type
	 * @param array $options
	 * @return bool
	 */
	public static function DefineIndexField( $domain, $field_name, $field_type, $options = array() ) {

		// @TODO: check valid domain format
		// @TODO: check valid field name format
		// @TODO: add support for SourceAttributes
		// @TODO: check text field isn't both "facet" and "result"

		if ( ! in_array( $field_type, array( 'uint', 'text', 'literal' ) ) ) {

			return false;

		}

		$payload = array(
			'DomainName' => $domain,
			'IndexField' => array(
				'IndexFieldName' => $field_name,
				'IndexFieldType' => $field_type
			)
		);

		$payload['IndexField'] += self::__parse_index_options( $field_type, $options );

		list($r, $config) = self::__make_request( 'DefineIndexField', self::__flatten_keys( $payload ) );

		if ( $r ) {

			$r = json_decode( $r );

			if ( isset( $r->Error ) ) {

				self::SetLastError( $r );

			} else if ( isset( $r->DefineIndexFieldResponse->DefineIndexFieldResult->IndexField ) ) {

				return true;

			}

		}

		return false;


	}

	/**
	 * Run a test to get the domains to determine if the auth keys are correct
	 *
	 * @method TestConnection
	 * @static
	 * @return boolean True if a position response
	 */
	public static function TestConnection( $credentials = array() ) {

		list($r, $config) = self::__make_request( 'DescribeDomains', array(), $credentials );

        if ( ! $config ) {
            return false;
        }
        
		return ( 200 == $config->status_code );

	}

	/**
	 * Push a predefined schema to CloudSearch
	 *
	 * @param string $domain
	 * @param string $schema
	 * @return bool
	 */
	public static function LoadSchema( $domain, $schema = 'wp_default' ) {

		$schema = Cloud_Schemas::GetSchema( $schema );

		if ( ! $schema ) {
			return false;
		}

		foreach ( $schema as $index ) {

			$index = array_merge( array( 'options' => array() ), $index );

			$r = self::DefineIndexField( $domain, $index['field_name'], $index['field_type'], $index['options'] );

			if ( false === $r ) {
				return false;
			}

		}

		return self::GetDomains( array( $domain ) );

	}


}