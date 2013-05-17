<?php

class LiftLinkControl {

	/**
	 *
	 * @var Lift_Search_Form 
	 */
	protected $form;
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
				$class = $option->selected ? 'selected" style="font-weight: bold' : '';
				//set empty values to false so they will be removed from the built querystring
				$option->value = array_map( function($value) {
						if ( empty( $value ) ) {
							$value = false;
						}
						return $value;
					}, $option->value );
				$opt_url = add_query_arg( $option->value, $url );

				$html .= sprintf( '<li class="%s"><a href="%s">%s</a></li>', $class, esc_url( $opt_url ), esc_html( $option->label ) );
			}
			$html .= '</ul>';
		}

		return $html;
	}

}

class LiftMultiSelectControl extends LiftLinkControl {

	/**
	 * Returns the HTML for a single-selectable control
	 * @return string 
	 */
	public function toHTML() {
		$html = '';

		if ( count( $this->items ) ) {
			$url = $this->form->getSearchBaseURL();

			$html .= '<div>' . esc_html( $this->label ) . '</div>';
			$html .= '<ul>';
			foreach ( $this->items as $option ) {
				$class = $option->selected ? 'selected" style="font-weight: bold' : '';
				//set empty values to false so they will be removed from the built querystring
				$option->value = array_map( function($value) {
						if ( empty( $value ) ) {
							$value = false;
						}
						return $value;
					}, $option->value );
				if ( $option->selected ) {
					$bar = array_diff_assoc_recursive( $this->form->getStateVars(), $option->value );
					$qs = http_build_query( array_diff_assoc_recursive( $this->form->getStateVars(), $option->value ) );
					$opt_url = $url . (strlen( $qs ) ? '?' . $qs : '');
				} else {
					$qs = http_build_query( array_merge_recursive( $this->form->getStateVars(), $option->value ) );
					$opt_url = $url . (strlen( $qs ) ? '?' . $qs : '');
				}

				$html .= sprintf( '<li class="%s"><a href="%s">%s</a></li>', $class, esc_url( $opt_url ), esc_html( $option->label ) );
			}
			$html .= '</ul>';
		}

		return $html;
	}

}