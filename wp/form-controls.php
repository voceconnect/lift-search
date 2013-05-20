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
		$options = wp_parse_args( $options, array(
			'show' => 5
			) );
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
			for ( $i = 0; $i < count( $this->items ); $i++ ) {
				$classes = array();
				if($this->items[$i]->selected) {
					$classes[] = 'selected';
				}
				if ( $i > 0 && ($i - 1 == $this->options['show']) ) {
					$html .= '<li class="lift-filter-expand hide-no-js hide-expanded">More options &hellip;</li>';
				}
				if ( $i > 0 && ($i > $this->options['show']) ) {
					$classes[] = 'hide-collapsed';
				}
				$html .= $this->itemHTML($this->items[$i], $classes, $url);
			}
			$html .= '<li class="lift-filter-collapse hide-collapsed">Less options</li>';
			$html .= '</ul>';
		}

		return $html;
	}

	public function itemHTML($item, $classes, $url) {
		//set empty values to false so they will be removed from the built querystring
		$item->value = array_map( function($value) {
				if ( empty( $value ) ) {
					$value = false;
				}
				return $value;
			}, $item->value );
		$opt_url = add_query_arg( $item->value, $url );

		return sprintf( '<li class="%s"><a href="%s">%s</a></li>', implode(' ', $classes), esc_url( $opt_url ), esc_html( $item->label ) );
	}

}

class LiftMultiSelectControl extends LiftLinkControl {

	public function itemHTML($item, $classes, $url) {
		//set empty values to false so they will be removed from the built querystring
		$item->value = array_map( function($value) {
				if ( empty( $value ) ) {
					$value = false;
				}
				return $value;
			}, $item->value );
		if ( $item->selected ) {
			$bar = array_diff_assoc_recursive( $this->form->getStateVars(), $item->value );
			$qs = http_build_query( array_diff_assoc_recursive( $this->form->getStateVars(), $item->value ) );
			$opt_url = $url . (strlen( $qs ) ? '?' . $qs : '');
		} else {
			$qs = http_build_query( array_merge_recursive( $this->form->getStateVars(), $item->value ) );
			$opt_url = $url . (strlen( $qs ) ? '?' . $qs : '');
		}

		return sprintf( '<li class="%s"><a href="%s">%s</a></li>', implode(' ', $classes), esc_url( $opt_url ), esc_html( $item->label ) );
	}

}