<?php
// Make sure class name doesn't exist
if ( !class_exists( 'Lift_Search_Form' ) ) {

	/**
	 * Lift_Search_Form is a class for building the fields and html used in the Lift Search plugin. 
	 * This class uses the singleton design pattern, and shouldn't be called more than once on a document.
	 * 
	 * There are three filters within the class that can be used to modify the output:
	 * 
	 * 'lift_filters_default_fields' can be used to remove default search fields on the form.
	 * 
	 * 'lift_filters_form_field_objects' can be used to add or remove Voce Search Field objects.
	 * 
	 * 'lift_search_form' can be used to modify the form html output
	 */
	class Lift_Search_Form {

		private static $instance;
		public $fields = array( );

		/**
		 * Get an instance of Lift_Search_Form
		 * @return object instance of Lift_Search_Form
		 */
		public static function GetInstance() {
			if ( !isset( self::$instance ) ) {
				self::$instance = new Lift_Search_Form();
			}
			return self::$instance;
		}

		/**
		 * Lift_Search_Form constructor.
		 */
		private function __construct() {
			add_filter( 'lift_filters_default_fields', function($defaults) {
					$remove = array( 'post_categories', 'post_tags' );
					$filtered = array_diff( $defaults, $remove );
					return $filtered;
				} );

			$this->additional_fields();
		}

		/**
		 * Calls all of the default search field build methods, Not including the main search term field.
		 * Fields can be modified using the 'lift_filters_default_fields' filter.
		 */
		public function additional_fields() {
			$default_fields = array( 'date', 'post_type', 'post_categories', 'post_tags', 'orderby' );
			$fields = apply_filters( 'lift_filters_default_fields', $default_fields );
			if ( in_array( 'date', $fields ) ) {
				$this->add_date_fields();
			}
			if ( in_array( 'post_type', $fields ) ) {
				$this->add_posttype_field();
			}
			if ( in_array( 'post_categories', $fields ) ) {
				$this->add_taxonomy_checkbox_fields( 'post_categories', get_categories() );
			}
			if ( in_array( 'post_tags', $fields ) ) {
				$this->add_taxonomy_checkbox_fields( 'tags_input', get_tags() );
			}
			if ( in_array( 'orderby', $fields ) ) {
				$this->add_sort_field();
			}
		}

		/**
		 * Builds the sort by dropdown/select field.
		 */
		public function add_sort_field() {
			if ( !$selected = Lift_Search_Form::get_query_var( 'orderby' ) ) {
				$selected = 'relevancy';
			}
			$options = array(
				'label' => ($selected) ? ucwords( $selected ) : 'Sort By',
				'value' => array(
					'Date' => 'date',
					'Relevancy' => 'relevancy'
				),
				'selected' => $selected,
			);
			$this->add_field( 'orderby', 'select', $options );
		}

		/**
		 * Builds the taxonomy checkboxes.
		 * @param type $tax_id Wordpress Taxonomy ID
		 * @param array $terms Array of Wordpress term objects
		 */
		public function add_taxonomy_checkbox_fields( $tax_id, $terms ) {
			global $wp_query;
			$selected_terms = Lift_Search_Form::get_query_var( $tax_id );

			foreach ( $terms as $term ) {
				$facets = $wp_query->get( 'facets' );
				$label = (!empty( $facets ) && isset( $facets[$tax_id] ) && isset( $facets[$tax_id][$term->term_id] )) ? sprintf( '%s (%s)', $term->name, $facets[$tax_id][$term->term_id] ) : $term->name;
				$options = array(
					'label' => $label
				);
				if ( is_array( $selected_terms ) && in_array( $term->term_id, $selected_terms ) ) {
					$options['selected'] = true;
				}
				$this->add_field( $tax_id, 'checkbox', $options );
			}
		}

		/**
		 * Builds the post type dropdown/select field.
		 */
		public function add_posttype_field() {
			global $wp_query;
			$types = Lift_Search::get_indexed_post_types();
			$selected_types = Lift_Search_Form::get_query_var( 'post_types' );
			$label = (!$selected_types ) ? 'All Types' : '';
			$selected_labels = array( );
			if ( !is_array( $selected_types ) ) {
				$selected_types = array( $selected_types );
			}

			$values = array(
				'All Types' => ''
			);

			foreach ( $types as $type ) {
				$num = (isset( $wp_query->query_vars['facets'] ) && isset( $wp_query->query_vars['facets']['post_type'][$type] )) ? sprintf( '(%s)', $wp_query->query_vars['facets']['post_type'][$type] ) : '';

				$type_object = get_post_type_object( $type );
				$values[sprintf( '%s %s', $type_object->label, $num )] = $type;
			}


			foreach ( $values as $k => $v ) {
				if ( in_array( $v, $selected_types ) ) {
					$selected_labels[] = $k;
				}
			}

			if ( !$label ) {
				$label = join( ' / ', $selected_labels );
			}

			$options = array(
				'label' => $label,
				'value' => $values,
				'selected' => $selected_types,
			);
			$this->add_field( 'post_types', 'select', $options );
		}

		/**
		 * Builds the start and end date fields. 
		 * The date_end field is hidden and always set to current time.
		 */
		public function add_date_fields() {
			$query_end = Lift_Search_Form::get_query_var( 'date_end' );
			$date_end = $query_end ? $query_end : time();
			$this->add_field( 'date_end', 'hidden', array(
				'value' => $date_end,
				'selected' => $date_end,
			) );
			$query_start = Lift_Search_Form::get_query_var( 'date_start' );
			$date_start = $query_start ? $query_start : 0;
			$values = array(
				'All Dates' => '',
				'24 Hours' => $date_end - 86400,
				'7 Days' => $date_end - (86400 * 7),
				'30 Days' => $date_end - (86400 * 30)
			);

			$selected_label = 'Date';
			foreach ( $values as $k => $v ) {
				if ( $v == $date_start ) {
					$selected_label = $k;
				}
			}
			$label = ( $date_start ) ? $selected_label : 'All Dates';

			$this->add_field( 'date_start', 'select', array(
				'label' => $label,
				'value' => $values,
				'selected' => ( int ) $date_start,
			) );
		}

		/**
		 * Method used to create new Lift_Search_Field instances. Fields are stored in $this->fields.
		 * @param string $id Amazon index field
		 * @param string $type type of HTML element
		 * @param array $options 
		 *  value = current value
		 * 	css => CSS classes
		 *  html_cb => callable function to generate html
		 */
		public function add_field( $id, $type = 'text', $options = array( ) ) {
			$field = new Lift_Search_Field( $id, $type, $options );
			$this->fields[] = $field;
		}

		/**
		 * Get custom query var from the wp_query
		 * @param string $var query variable
		 * @return array|boolean query variable if it exists, else false
		 */
		public function get_query_var( $var ) {
			return ( $val = get_query_var( $var ) ) ? $val : false;
		}

		/**
		 * Builds the html form using all fields in $this->fields.
		 * @return string search form
		 */
		public function form() {
			$search_term = (is_search()) ? get_search_query() : "";
			$html = '<form role="search" class="lift-search" id="searchform" '.( ! is_search() ? 'action="'.site_url().'/"' : '').'><div>';
			$html .= "<input type='text' name='s' id='s' value='$search_term' />";
			$html .= ' <input type="submit" id="searchsubmit" value="' . esc_attr__( 'Search' ) . '" />';
			$html .= $this->form_filters();
			$html .= "</div></form>";
			apply_filters( 'lift_search_form', $html );
			return $html;
		}

		public function loop() {
			$path = dirname(__DIR__) . '/lift-search/templates/lift-loop.php';
			include_once $path;
		}

		public function form_filters() {
			if ( !is_search() ) {
				return;
			}

			$fields = apply_filters( 'lift_filters_form_field_objects', $this->fields );
			$html = '<fieldset class="lift-search-form-filters">';
			foreach ( $fields as $field ) {
				if ( is_a( $field, 'Lift_Search_Field' ) ) {
					$html .= $field->element();
				}
			}
			$html .= "</fieldset>";
			// @TODO setting to add JS form controls
			if ( 1 == 1 ) {
				$html .= $this->js_form_controls();
			}
			return $html;
		}

		/**
		 * Build additional elements to override standard form controls
		 * @return string 
		 */
		public function js_form_controls() {
			$fields = apply_filters( 'lift_filters_form_field_objects', $this->fields );
			$counter = 1;
			$html = "<div class='lift-js-filters lift-hidden' style='display: none'><ul id='lift-filters'>";
			$html .= "<li class='first'>Filter by: </li>";
			foreach ( $fields as $field ) {
				if ( is_a( $field, 'Lift_Search_Field' ) ) {
					$html .= $field->faux_element( $counter == count( $fields ) );
				}
				$counter++;
			}
			$html .= "</ul></div>";
			return $html;
		}

	}

	/**
	 * Custom field object that builds and returns fields used in this plugin.
	 */
	class Lift_Search_Field {

		/**
		 * Search Field Object Constructor
		 * @param string $id used for html id attribute
		 * @param string $type type of html form element
		 * @param array $options optional arguments for the field
		 */
		public function __construct( $id, $type, $options ) {
			$this->id = $id;
			$this->type = $type;
			$default_opts = array(
				'value' => null,
				'html_cb' => null,
				'label' => null,
				'css' => ' voce-field-class ',
				'selected' => null,
			);
			$this->options = array_merge( $default_opts, $options );
		}

		/**
		 * Build and return the html for the instance of a field. 
		 * If a custom callback is specified then it will be called. 
		 * @return string HTML form field
		 */
		public function element() {
			if ( is_callable( $this->options['html_cb'] ) ) {
				return call_user_func( $this->htmlcb, $this );
			} else {
				switch ( $this->type ) {
					case 'text':
						return $this->text_field();
					case 'checkbox':
						return $this->checkbox_field();
					case 'hidden':
						return $this->hidden_field();
					case 'select':
						return $this->select_field();
						break;
				}
			}
		}

		/**
		 * Call methods to create JS form element overrides
		 * @return type 
		 */
		public function faux_element( $last = false ) {
			switch ( $this->type ) {
				case 'checkbox':
					return $this->faux_checkbox_field( $last );
				case 'select':
					return $this->faux_select_field( $last );
					break;
			}
		}

		/**
		 * Creates a checkbox field.
		 * @return string HTML form field
		 */
		private function checkbox_field() {
			$selected = "";
			if ( $this->options['selected'] ) {
				$selected = " selected='selected'";
			}
			$html = "
				<label class='lift-field-label'>
				<input type='checkbox' 
					name='" . $this->id . "[]' 
					id='" . $this->id . "' 
					value='" . $this->options['value'] . "' 
					class='" . $this->options['css'] . " ' 
					$selected
				/>" . $this->options['label'] . "</label>";
			return $html;
		}

		/**
		 * Creates a text input field
		 * @return string HTML form field
		 */
		private function text_field() {
			$html = "<label class='lift-field-label'>" . $this->options['label'] . "</label>";
			if ( $this->id == 's' ) {
				$html .= '<span class="magnify">';
			}
			$html .="
				<input type='text' 
					name='" . $this->id . "' 
					id='" . $this->id . "' 
					value='" . $this->options['value'] . "' 
					class='" . $this->options['css'] . " ' 
					placeholder='" . $this->options['placeholder'] . "'
				/>";
			if ( $this->id == 's' ) {
				$html .= '</span>';
			}
			return $html;
		}

		/**
		 * Creates a hidden input field
		 * @return string HTML form field
		 */
		private function hidden_field() {
			$html = "
				<label class='lift-field-label'>
				<input type='hidden' 
					name='" . $this->id . "' 
					id='" . $this->id . "' 
					value='" . $this->options['value'] . "' 
					class='" . $this->options['css'] . " ' 
				/> </label>";
			return $html;
		}

		/**
		 * Creates a select field
		 * @return string HTML form field
		 */
		private function select_field() {
			$html = "
				<label>" . $this->options['label'] . "
				<select
				id='$this->id'
				name='$this->id'
				class='" . $this->id . " " . $this->options['css'] . "'
				>";
			foreach ( $this->options['value'] as $k => $v ) {
				$selected = "";
				if ( is_array( $this->options['selected'] ) && in_array( $v, $this->options['selected'], true ) ) {
					$selected = "selected='selected'";
				} else if ( $this->options['selected'] === $v ) {
					$selected = "selected='selected'";
				}
				$html .= '<option value="' . $v . '" ' . $selected . '>' . $k . '</option>';
			}
			$html .= '</select></label>';

			return $html;
		}

		/**
		 * Generate custom list elements to replace select fields
		 * @return boolean|string False if field type is unsupported | HTML list item elements
		 */
		private function faux_select_field( $last = false ) {
			if ( !in_array( $this->type, array( 'select' ) ) ) {
				return false;
			}

			$options = '';
			$has_selection = false;
			$last_class = ( $last ) ? 'last' : '';

			foreach ( $this->options['value'] as $k => $v ) {
				$selected = "";
				if ( is_array( $this->options['selected'] ) && in_array( $v, $this->options['selected'], true ) ) {
					$selected = "selected";
					$has_selection = true;
				} else if ( $this->options['selected'] === $v ) {
					$selected = "selected";
					$has_selection = true;
				}
				$options .= sprintf( '<li class="lift-list-item %s %s" data-lift_value="%s" ><a href="#">%s</a></li>', $selected, $last, $v, $k );
			}

			$selected_class = ( $has_selection ) ? 'selected' : '';

			$html = sprintf( '<li class="lift-list-toggler %s" id="lift-list-toggler-%s" data-role="list-toggler"><a href="#" class="%s">%s</a>', $last_class, $this->id, $selected_class, $this->options['label'] );
			$html .= "<ul class='lift-select-list lift-hidden' data-lift_bind='$this->id'>";
			$html .= $options;
			$html .= "</ul></li>";
			return $html;
		}

	}

}

/**
 * Template Tags 
 */
if ( !function_exists( 'lift_search_form' ) ) {

	function lift_search_form() {
		echo Lift_Search_Form::GetInstance()->form();
	}

}

if ( !function_exists( 'lift_search_filters' ) ) {

	function lift_search_filters() {
		echo Lift_Search_Form::GetInstance()->form_filters();
	}

}

if ( !function_exists( 'lift_loop' ) ) {

	function lift_loop() {
		echo Lift_Search_Form::GetInstance()->loop();
	}

}

/**
 * Embed the Lift Search form in a sidebar
 * @class Lift_Form_Widget 
 */
class Lift_Form_Widget extends WP_Widget {

	/**
	 * @constructor 
	 */
	public function __construct() {
		parent::__construct(
			'lift_form_widget', "Lift Search Form", array( 'description' => "Add a Lift search form" )
		);
	}

	/**
	 * Output the widget
	 * 
	 * @method widget
	 */
	function widget( $args, $instance ) {
		extract( $args );
		$title = apply_filters( 'widget_title', empty( $instance['title'] ) ? '' : $instance['title'], $instance, $this->id_base );

		echo $before_widget;
		if ( $title )
			echo $before_title . $title . $after_title;

		if ( class_exists( 'Lift_Search_Form' ) ) {
			echo Lift_Search_Form::GetInstance()->form();
		}
		echo $after_widget;
	}

	function form( $instance ) {
		$instance = wp_parse_args( ( array ) $instance, array( 'title' => '' ) );
		$title = $instance['title'];
		?>
		<p><label for="<?php echo $this->get_field_id( 'title' ); ?>"><?php _e( 'Title:' ); ?> <input class="widefat" id="<?php echo $this->get_field_id( 'title' ); ?>" name="<?php echo $this->get_field_name( 'title' ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>" /></label></p>
		<?php
	}

	function update( $new_instance, $old_instance ) {
		$instance = $old_instance;
		$new_instance = wp_parse_args( ( array ) $new_instance, array( 'title' => '' ) );
		$instance['title'] = strip_tags( $new_instance['title'] );
		return $instance;
	}

}

add_action( 'widgets_init', function() {
		register_widget( 'Lift_Form_Widget' );
	} );

