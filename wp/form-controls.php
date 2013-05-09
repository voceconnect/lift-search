<?php

abstract class aSelectableFormFilter {

	/**
	 *
	 * @var LiftField 
	 */
	public $field;
	public $control;
	public $control_options;

	/**
	 * 
	 * @param string $label
	 * @param array $control_options
	 */
	public function __construct( $field, $label, $control_options = array( ) ) {
		$this->label = $label;
		$this->control_options = $control_options;
		$this->field = $field;
		add_filter( 'lift_form_filters', array( $this, '_addFormFilter' ), 10, 2 );
		add_filter( 'lift_form_field_' . $this->field->name, array( $this, 'getHTML' ), 10, 3 );
	}

	public abstract function applyFacetOptions( $cs_query );

	/**
	 * 
	 * @param array $filter_fields
	 * @param Lift_Search_Form $lift_search_form
	 * @return array
	 */
	public function _addFormFilter( $filter_fields, $search_form ) {
		if ( $search_form->lift_query->wp_query->is_search() )
			return array_merge( $filter_fields, array( $this->field->name ) );
		return $filter_fields;
	}

	/**
	 * 
	 * @param Lift_Search_Form $lift_search_form
	 * @param array $args
	 * @return string the resulting control
	 */
	public function getHTML( $filterHTML, $lift_search_form, $args ) {
		extract( $args );
		$control_items = $this->getControlItems( $lift_search_form->lift_query );
		if ( empty( $control_items ) ) {
			return $filterHTML;
		}

		$control = new LiftSelectableControl( $lift_search_form, $this->label, $control_items, $this->control_options );
		return $before_field . $control->toHTML() . $after_field;
	}

	/**
	 * Returns the selectable items for the filter.  All items should be objects with the following fields:
	 * 	-selected : boolean whether that value is currently selected
	 *  -value : mixed, the value that will be added to the request vars when selected
	 *  -label : the label applied to the selectable item
	 * @param Lift_WP_Query $lift_query
	 * @return array of items
	 */
	abstract protected function getControlItems( $lift_query );
}

class LiftSelectableControl {

	/**
	 *
	 * @var Lift_Search_Form 
	 */
	private $form;
	public $label;
	public $items;
	public $options;

	/**
	 *
	 * @param Lift_Search_Form $form
	 * @param string $label
	 * @param array $items
	 * @param array $options 
	 */
	public function __construct( $form, $label, $items, $options = array( ) ) {

		$this->form = $form;
		$this->label = $label;
		$this->items = $items;
		$this->options = $options;
	}

	/**
	 * Returns the HTML for a single-selectable control
	 * @return string 
	 */
	public function toHTML() {
		$html = '';

		if ( count( $this->items ) ) {
			$url = $this->form->getSearchBaseURL() . '?' . http_build_query( $this->form->getStateVars() );

			$html .= '<div>' . esc_html( $this->label ) . '</div>';
			$html .= '<ul>';
			foreach ( $this->items as $option ) {
				$class = $option->selected ? 'selected' : '';
				$opt_url = add_query_arg( $option->value, $url );

				$html .= sprintf( '<li class="%s"><a href="%s">%s</a></li>', $class, esc_url( $opt_url ), esc_html( $option->label ) );
			}
			$html .= '</ul>';
		}

		return $html;
	}

}

class LiftSelectableFacetControl extends aSelectableFormFilter {

	public $field;

	public function __construct( $field, $label, $control_options = array( ) ) {
		parent::__construct( $field, $label, $control_options );
	}

	public function applyFacetOptions( $cs_query ) {
		$cs_query->add_facet_contraint( $this->field->name, array(
			'..5', '6..10', '11..20', '21..'
		) );
	}

	/**
	 * Returns an array of selectable filter items
	 * @param Lift_WP_Query $lift_query
	 * @return array
	 */
	protected function getControlItems( $lift_query ) {
		$facets = $lift_query->get_facets();
		if ( empty( $facets[$this->field->name] ) )
			return array( );

		$my_facets = $facets[$this->field->name];

		$items = array( );

		foreach ( $my_facets as $bq_value => $count ) {
			$facet_request_vars = $this->field->bqValueToRequest( $bq_value );
			$facet_wp_vars = $this->field->requestToWP( $facet_request_vars );

			//determine if this item is selected by comparing the relative wp vars to this query
			$selected = 0 === count( array_diff_assoc_recursive( $facet_wp_vars, $lift_query->wp_query->query_vars ) );

			$label = $this->field->wpToLabel($facet_wp_vars);
			if ( $count ) {
				$label = sprintf( '%1$s (%2$d)', $label, $count );
			}
			$item = ( object ) array(
					'selected' => $selected,
					'value' => $facet_request_vars,
					'label' => $label
			);
			$items[] = $item;
		}
		return $items;
	}

}

//function liftFormFilter()