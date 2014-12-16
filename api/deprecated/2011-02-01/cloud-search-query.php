<?php

class Cloud_Search_Query_2011_02_01 extends Cloud_Search_Query {

	public function add_facet( $field ) {
		$this->facets = array_merge( $this->facets, ( array ) $field );
	}

	public function add_facet_constraint( $field, $constraints ) {
		$this->facet_constraints[$field] = ( array ) $constraints;
	}

	public function add_rank( $field, $order ) {
		$order = ('DESC' === strtoupper( $order )) ? 'DESC' : 'ASC';
		$this->ranks[$field] = $order;
	}
	
	public function get_query_string() {
		$ranks = array( );

		foreach ( $this->ranks as $field => $order ) {
			$ranks[] = ('DESC' === $order) ? "-{$field}" : $field;
		}

		$params = array_filter( array(
			'bq' => $this->boolean_query,
			'facet' => implode( ',', $this->facets ),
			'return-fields' => implode( ',', $this->return_fields ),
			'size' => $this->size,
			'start' => $this->start,
			'rank' => implode( ',', $ranks )
			) );

		if ( count( $this->facet_constraints ) ) {
			foreach ( $this->facet_constraints as $field => $constraints ) {
				$params['facet-' . $field . '-constraints'] = implode( ',', $constraints );
			}
		}

		if ( count( $this->facet_top_n ) ) {
			foreach ( $this->facet_top_n as $field => $limit ) {
				$params['facet-' . $field . '-top-n'] = $limit;
			}
		}
		return http_build_query( $params );
	}

}
