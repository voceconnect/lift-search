<?php

function lift_queue_field_update( $document_id, $field_name, $document_type = 'post' ) {
	return Lift_Document_Update_Queue::queue_field_update( $document_id, $field_name, $document_type );
}

function lift_queue_deletion( $document_id, $document_type = 'post' ) {
	return Lift_Document_Update_Queue::queue_deletion( $document_id, $document_type );
}

class Lift_Document_Update_Queue {

	private static $document_update_docs = array( );

	const STORAGE_POST_TYPE = 'lift_queued_document';

	public static function get_queue_count() {
		global $wpdb;

		if ( false === ($queue_count = wp_cache_get( 'lift_update_queue_count' )) ) {
			$queue_count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT( 1 ) FROM $wpdb->posts
				WHERE post_type = %s", self::STORAGE_POST_TYPE ) );

			wp_cache_set( 'lift_update_queue_count', $queue_count );
		}

		return $queue_count;
	}

	/**
	 * Sets a document field to be queued for an update
	 * @param int $document_id
	 * @param string $field_name
	 * @param string $document_type
	 * @return bool 
	 */
	public static function queue_field_update( $document_id, $field_name, $document_type = 'post' ) {
		$doc_update = self::get_queued_document_updates( $document_id, $document_type );
		return $doc_update->add_field( $field_name );
	}

	/**
	 * Queus the document for deletion
	 * @param int $document_id
	 * @param string $document_type
	 * @return bool 
	 */
	public static function queue_deletion( $document_id, $document_type = 'post' ) {
		$doc_update = self::get_queued_document_updates( $document_id, $document_type );
		return $doc_update->set_for_deletion();
	}

	/**
	 * Gets the instance of the LiftUpdateDocument for the given document.
	 * @param int $document_id
	 * @param string $document_type
	 * @return Lift_Update_Document 
	 */
	public static function get_queued_document_updates( $document_id, $document_type = 'post' ) {
		$key = $document_type . '_' . $document_id;
		if ( isset( self::$document_update_docs[$key] ) ) {
			return self::$document_update_docs[$key];
		}

		if ( $update_post = self::get_document_update_post( $document_id, $document_type ) ) {
			$post_meta_content = get_post_meta( $update_post->ID, 'lift_content', true );
			$update_data = ( array ) maybe_unserialize( $post_meta_content );
			$action = isset( $update_data['action'] ) ? $update_data['action'] : 'add';
			$fields = isset( $update_data['fields'] ) ? ( array ) $update_data['fields'] : array( );
			$document_update_doc = new Lift_Update_Document( $document_id, $document_type, $action, $fields );
		} else {
			$document_update_doc = new Lift_Update_Document( $document_id, $document_type );
		}

		self::$document_update_docs[$key] = $document_update_doc;
		return $document_update_doc;
	}

	/**
	 * Retrieves the post used to store the queued document update
	 * @param int $document_id
	 * @param string $document_type
	 * @return WP_Post|null 
	 */
	private static function get_document_update_post( $document_id, $document_type ) {
		$post_name = $document_type . '-' . $document_id;
		if ( false === ($post_id = wp_cache_get( 'lift_queue_post_id_' . $post_name ) ) ) {
			$posts = get_posts( array(
				'post_type' => self::STORAGE_POST_TYPE,
				'posts_per_page' => 1,
				'name' => $post_name,
				'fields' => 'ids'
				) );

			if ( count( $posts ) === 1 ) {
				$post_id = $posts[0];
				wp_cache_set( 'lift_queue_post_id_' . $post_name, $post_id );
				wp_cache_delete( 'lift_update_queue_count' );
			}
		}

		return $post_id ? get_post( $post_id ) : false;
	}

	/**
	 * Initializes needed post type for storage 
	 */
	public static function init() {
		register_post_type( self::STORAGE_POST_TYPE, array(
			'labels' => array(
				'name' => 'Lift Queue',
				'singular_name' => 'Queued Docs'
			),
			'publicly_queryable' => false,
			'public' => defined( 'LIFT_QUEUE_DEBUG' ),
			'rewrite' => false,
			'has_archive' => false,
			'query_var' => false,
			'taxonomies' => array( ),
			'show_ui' => defined( 'LIFT_QUEUE_DEBUG' ),
			'can_export' => false,
			'show_in_nav_menus' => false,
			'show_in_menu' => defined( 'LIFT_QUEUE_DEBUG' ),
			'show_in_admin_bar' => false,
			'delete_with_user' => false,
		) );

		add_action( 'shutdown', array( __CLASS__, '_save_updates' ) );

		Lift_Post_Update_Watcher::init();
		Lift_Post_Meta_Update_Watcher::init();
		Lift_Taxonomy_Update_Watcher::init();
	}

	/**
	 * Callback on shutdown to save any updated documents 
	 */
	public static function _save_updates() {
		foreach ( self::$document_update_docs as $change_doc ) {
			if ( !$change_doc->has_changed ) {
				continue;
			}
			$new = false;
			if ( false == ($post = self::get_document_update_post( $change_doc->document_id, $change_doc->document_type ) ) ) {
				$post = array(
					'post_type' => self::STORAGE_POST_TYPE,
					'post_name' => $change_doc->document_type . '-' . $change_doc->document_id,
					'post_title' => $change_doc->document_type . '-' . $change_doc->document_id,
					'post_status' => 'publish',
					'post_content' => '',
				);
				$new = true;
			}

			$post_content = serialize( array(
				'document_id' => $change_doc->document_id,
				'document_type' => $change_doc->document_type,
				'action' => $change_doc->action,
				'fields' => $change_doc->fields
				) );


			if ( ( $post_id = wp_insert_post( $post ) ) && $new ) {
				wp_cache_set( 'lift_queue_post_id_' . $change_doc->document_type . '_' . $change_doc->document_id, $post_id );
			}
			update_post_meta( $post_id, 'lift_content', $post_content );
		}
	}

	public static function _deactivation_cleanup() {
		global $wpdb;

		$batch_post_ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM $wpdb->posts
			WHERE post_type = %s", self::STORAGE_POST_TYPE ) );
		foreach ( $batch_post_ids as $post_id ) {
			wp_delete_post( $post_id, true );
		}
	}

}

add_action( 'init', array( 'Lift_Document_Update_Queue', 'init' ), 2 );

class Lift_Update_Document {

	public $action;
	public $fields;
	public $document_id;
	public $document_type;
	public $has_changed;

	public function __construct( $document_id, $document_type, $action = 'add', $fields = array( ), $has_changed = false ) {
		$this->document_id = $document_id;
		$this->document_type = $document_type;
		$this->action = $action;
		$this->fields = $fields;
		$this->has_changed = $has_changed;
	}

	public function add_field( $field_name ) {
		if ( $this->action == 'delete' ) {
			return false;
		}

		if ( !in_array( $field_name, $this->fields ) ) {
			$this->fields[] = $field_name;
			$this->has_changed = true;
		}
		return true;
	}

	public function set_for_deletion() {
		if ( $this->action !== 'delete' ) {
			$this->has_changed = true;
			$this->fields = array( );
			$this->action = 'delete';
		}
		return true;
	}

}