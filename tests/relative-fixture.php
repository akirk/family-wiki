<?php
/** Shared in-memory WordPress fixture; loading this file runs no assertions. */
namespace Family_Wiki {
	class App { const URL_PATH = 'familypedia'; }
	class Tree {
		public static function reset_expanded() { $GLOBALS['tree_reset'] = true; }
		public static function render_list( $id ) { return '<ul data-root="' . $id . '"><li>Updated descendants</li></ul>'; }
		public static function get_people() {
			if ( isset( $GLOBALS['fixture_tree'] ) ) { return $GLOBALS['fixture_tree']; }
			$people = array();
			foreach ( $GLOBALS['people'] as $id => $person ) {
				$people[ $id ] = array( 'parents' => array(), 'children' => Person::to_ids( Person::field( 'children', $id ) ) );
				foreach ( Person::SINGLE_PERSON_FIELDS as $field ) {
					$parent = Person::field( $field, $id );
					if ( $parent ) { $people[ $id ]['parents'][] = $parent->ID; }
				}
			}
			return $people;
		}
	}
	class Main { public static function flush_family_data_cache( $id ) {} }
	class Wiki_Page { public static function is_page( $post ) { return (bool) get_post_meta( get_post( $post )->ID, '_familypedia_page', true ); } }
	class Links { public static function path_index() { return array(); } }
}
namespace {
	class WP_Post {
		public $ID;
		public $post_type = 'familypedia_person';
		public $post_title;
		public $post_content = '';
		public $post_status = 'publish';
		public $post_parent = 0;
		public $post_name;
	}
	class WP_Error {
		private $message;
		public function __construct( $code, $message ) { $this->message = $message; }
		public function get_error_message() { return $this->message; }
	}
	class Json_Response extends Exception {
		public $data;
		public function __construct( $data ) { $this->data = $data; }
	}
	function add_action( ...$args ) {}
	function absint( $value ) { return abs( (int) $value ); }
	function wp_unslash( $value ) { return $value; }
	function check_admin_referer( $action ) { $GLOBALS['nonce_action'] = $action; }
	function wp_send_json_success( $data ) { throw new Json_Response( $data ); }
	function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
	function esc_html( $text ) { return esc_attr( $text ); }
	function esc_url( $text ) { return esc_attr( $text ); }
	function esc_html__( $text, $domain ) { return esc_html( $text ); }
	function esc_html_e( $text, $domain ) { echo esc_html( $text ); }
	function selected( $a, $b ) { if ( $a === $b ) { echo 'selected'; } }
	function home_url( $path ) { return 'https://example.org' . $path; }
	function __( $text, $domain ) { return $text; }
	function sanitize_text_field( $text ) { return trim( strip_tags( $text ) ); }
	function sanitize_title_with_dashes( $text ) { return strtolower( str_replace( ' ', '-', $text ) ); }
	function current_user_can( $cap, $id = 0 ) { return 99 !== $id; }
	function get_post( $id ) { return $id instanceof WP_Post ? $id : ( $GLOBALS['people'][ $id ] ?? null ); }
	function get_the_title( $id ) { return get_post( $id )->post_title; }
	function get_posts( $args ) {
		return array_values( array_filter( $GLOBALS['people'], function ( $post ) use ( $args ) {
			if ( ! empty( $args['s'] ) && false === stripos( $post->post_title . ' ' . $post->post_content, $args['s'] ) ) { return false; }
			if ( isset( $args['meta_key'] ) && (string) get_post_meta( $post->ID, $args['meta_key'], true ) !== (string) $args['meta_value'] ) { return false; }
			return true;
		} ) );
	}
	function get_post_meta( $id, $key, $single ) { return $GLOBALS['meta'][ $id ][ $key ] ?? ''; }
	function update_post_meta( $id, $key, $value ) { $GLOBALS['meta'][ $id ][ $key ] = $value; }
	function delete_post_meta( $id, $key ) { unset( $GLOBALS['meta'][ $id ][ $key ] ); }
	function wp_slash( $data ) { return $data; }
	function wp_insert_post( $data, $error = false ) {
		$id = count( $GLOBALS['people'] ) + 1;
		$post = new WP_Post();
		$post->ID = $id;
		$post->post_title = $data['post_title'];
		$post->post_name = sanitize_title_with_dashes( $post->post_title );
		$GLOBALS['people'][ $id ] = $post;
		return $id;
	}
	function is_wp_error( $result ) { return $result instanceof WP_Error; }
	require __DIR__ . '/../src/Person.php';
	require __DIR__ . '/../src/Editor.php';
	require __DIR__ . '/../src/Relationships.php';
	function check( $ok, $message ) { if ( ! $ok ) { throw new Exception( $message ); } }
	define( 'ABSPATH', __DIR__ . '/' );
}
