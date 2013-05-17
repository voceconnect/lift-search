<?php

class LiftLinkControl {

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
				$class = $option->selected ? 'selected" style="font-weight: bold' : '';
				//set empty values to false so they will be removed from the built querystring
				$option->value = array_map(function($value) {
					if(empty($value)) {
						$value = false;
					}
					return $value;
				}, $option->value);
				$opt_url = add_query_arg( $option->value, $url );

				$html .= sprintf( '<li class="%s"><a href="%s">%s</a></li>', $class, esc_url( $opt_url ), esc_html( $option->label ) );
			}
			$html .= '</ul>';
		}

		return $html;
	}

}
