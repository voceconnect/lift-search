<?php

class Quick_Filter_Maker {
	
	public function init() {
		foreach ( array( 'date', 'post_type', 'post_categories', 'post_tags', 'orderby' ) as $field) {
			add_filter('lift_form_field_'.$field, array($this, $field), 10, 2);
		}
	}
	public function __call( $field, $arguments ) {
		return "<label>NOT YET IMPLEMENTED {$field}</label><br />";
	}
	
	/**
	 * 
	 * @param string $field_html
	 * @param Lift_Search_Form $lift_search_form
	 * @return string
	 */
	public function date($field_html, $lift_search_form) {
		return "HELLO WORLD";
	}
}
add_action('wp_loaded', array(new Quick_Filter_Maker(), 'init'));

class GenericControl {

	/**
	 * Test
	 * @var string 
	 */
	protected $id;

	public function getID() {
		return $this->id;
	}

	/**
	 *
	 * @var string
	 */
	protected $name;

	public function getName() {
		return $this->name;
	}

	/**
	 *
	 * @var array
	 */
	protected $classes;

	private function getClasses() {
		return $this->classes;
	}

	/**
	 *
	 * @var GenericControlValueCollection
	 */
	public $values;

	public function __construct( $id, $name, $values = array( ), $classes = array( ) ) {
		$this->id = $id;
		$this->name = $name;
		$this->values = new GenericControlValueCollection( $values );
		$this->classes = $classes;
	}

}

class GenericControlValueCollection implements Iterator, ArrayAccess {

	private $values;

	public function __construct() {
		$this->values = array( );
	}

	/**
	 * 
	 * @param  GenericControlValueCollection $value
	 * @return GenericControlValueCollection
	 */
	public function add( GenericControlValue $value ) {
		$this->values[] = $value;
		return $this;
	}

	/**
	 * 
	 * @param mixed $offset
	 * @return bool
	 */
	public function offsetExists( $offset ) {
		return isset( $this->values[$offset] );
	}

	/**
	 * 
	 * @param mixed $offset
	 * @return GenericControlValue
	 */
	public function offsetGet( $offset ) {
		return isset( $this->values[$offset] ) ? $this->values[$offset] : null;
	}

	/**
	 * 
	 * @param type $offset
	 * @param type $value
	 */
	public function offsetSet( $offset, $value ) {
		if ( is_null( $offset ) ) {
			$this->values[] = $value;
		} else {
			$this->values[$offset] = $value;
		}
	}

	/**
	 * 
	 * @param mixed $offset
	 */
	public function offsetUnset( $offset ) {
		unset( $this->values[$offset] );
	}

	/**
	 * 
	 * @return GenericControlValue
	 */
	public function current() {
		return current( $this->values );
	}

	/**
	 * 
	 * @return GenericControlValue
	 */
	public function key() {
		return key( $this->values );
	}

	/**
	 * 
	 * @return GenericControlValue
	 */
	public function next() {
		return next( $this->values );
	}

	/**
	 * 
	 * @return bool
	 */
	public function rewind() {
		return rewind( $this->values );
	}

	/**
	 * 
	 * @return bool
	 */
	public function valid() {
		return valid( $this->values );
	}

}

class GenericControlValue {

	/**
	 * @var bool
	 */
	protected $selected = false;

	public function setSelected( $value ) {
		$this->selected = ( bool ) $value;
		return this;
	}

	public function getSelected() {
		return $this->selected;
	}

	/**
	 * @var string
	 */
	protected $value = false;

	public function setValue( $value ) {
		$this->value = $value;
		return this;
	}

	public function getValue() {
		return $this->value;
	}

	/**
	 * @var string
	 */
	protected $label = false;

	public function setLabel( $label ) {
		$this->label = $label;
		return this;
	}

	public function getLabel() {
		return $this->label;
	}

}
