<?php
/** Run with php tests/reunions.php; reunion validation, authorization and save checks. */
namespace Family_Wiki {
	class App { const URL_PATH = 'familypedia'; }
	class Person {
		const POST_TYPE = 'familypedia_person';
		public static function get_by_path( $path ) { return $GLOBALS['paths'][ $path ] ?? null; }
		public static function url( $post ) { return '/person/' . $post->ID; }
	}
	class Wiki_Page {
		const META_KEY = '_familypedia_page';
		public static function is_page( $post ) { return ! empty( $post->wiki_page ); }
		public static function can_create() { return $GLOBALS['allowed']; }
	}
	class Main { public static function flush_family_data_cache( $id ) {} }
}
namespace {
	class WP_Error {
		public $code;
		private $message;
		public function __construct( $code, $message ) { $this->code = $code; $this->message = $message; }
		public function get_error_message() { return $this->message; }
	}
	function home_url( $path ) { return 'https://example.org' . $path; }
	function wp_is_uuid( $v ) { return (bool) preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $v ); }
	function get_posts( $args ) { $found = array(); foreach ( $GLOBALS['posts'] as $p ) { if ( get_post_meta( $p->ID, $args['meta_key'], true ) === $args['meta_value'] ) { $found[] = $p; } } return $found; }
	function __( $text, $domain ) { return $text; }
	function esc_html__( $text, $domain ) { return $text; }
	function esc_html( $text ) { return $text; }
	function sanitize_text_field( $text ) { return trim( strip_tags( (string) $text ) ); }
	function add_action( ...$args ) {}
	function absint( $v ) { return abs( (int) $v ); }
	function wp_unslash( $v ) { return $v; }
	function wp_slash( $v ) { return $v; }
	function is_wp_error( $v ) { return $v instanceof WP_Error; }
	function get_post( $id ) { return is_object( $id ) ? $id : ( $GLOBALS['posts'][ $id ] ?? null ); }
	function get_post_meta( $id, $key, $single ) { return $GLOBALS['meta'][ $id ][ $key ] ?? ''; }
	function current_user_can( ...$args ) { return $GLOBALS['allowed']; }
	function check_admin_referer( $action ) { if ( ! $GLOBALS['nonce'] ) { throw new RuntimeException( 'nonce' ); } }
	function wp_die( $message, ...$args ) { throw new RuntimeException( $message ); }
	function wp_safe_redirect( $url ) { throw new RuntimeException( 'redirect:' . $url ); }
	function wp_insert_post( $data, $error ) { $id = 10; $GLOBALS['posts'][ $id ] = (object) array_merge( array( 'ID' => $id ), $data ); foreach ( $data['meta_input'] ?? array() as $key => $value ) { $GLOBALS['meta'][ $id ][ $key ] = $value; } $GLOBALS['writes']++; return $id; }
	function wp_update_post( $data, $error ) { $id = $data['ID']; $GLOBALS['posts'][ $id ] = (object) array_merge( (array) $GLOBALS['posts'][ $id ], $data ); $GLOBALS['writes']++; return $id; }
	function update_post_meta( $id, $key, $v ) { $GLOBALS['meta'][ $id ][ $key ] = $v; $GLOBALS['writes']++; }
	function verify_reunion( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }
	require __DIR__ . '/../src/Reunion.php';
	$GLOBALS['posts'] = array(
		1 => (object) array( 'ID' => 1, 'post_type' => 'familypedia_person', 'post_status' => 'publish' ),
		2 => (object) array( 'ID' => 2, 'post_type' => 'familypedia_person', 'post_status' => 'publish', 'wiki_page' => true ),
		3 => (object) array( 'ID' => 3, 'post_type' => 'attachment', 'post_status' => 'inherit' ),
		4 => (object) array( 'ID' => 4, 'post_type' => 'familypedia_person', 'post_status' => 'draft' ),
	);
	$GLOBALS['meta'] = array();
	$valid = array( 'year' => '2012', 'location' => '<b>Paris</b>', 'attendees' => array( '1', '1' ) );
	$data = Family_Wiki\Reunion::validate( $valid );
	verify_reunion( ! is_wp_error( $data ) && $data['year'] === '2012' && $data['start'] === '' && $data['end'] === '', 'Year-only reunions must not invent dates.' );
	verify_reunion( $data['location'] === 'Paris' && $data['attendees'] === array( 1 ), 'Sanitize place and deduplicate attendee IDs.' );
	foreach ( array(
		array( 'start' => '2012-02-30' ), array( 'year' => '12' ), array( 'year' => '0' ), array( 'year' => array() ),
		array( 'start' => '2013-01-01' ), array( 'end' => '2012-01-01' ),
		array( 'start' => '2012-02-02', 'end' => '2012-02-01' ),
		array( 'attendees' => '1' ), array( 'attendees' => array( array( 1 ) ) ),
		array( 'attendees' => array( 2 ) ), array( 'attendees' => array( 3 ) ),
		array( 'attendees' => array( 4 ) ), array( 'attendees' => array( 999 ) ),
	) as $invalid ) {
		verify_reunion( is_wp_error( Family_Wiki\Reunion::validate( array_merge( $valid, $invalid ) ) ), 'Invalid dates or attendee records must be rejected.' );
	}
	$data = Family_Wiki\Reunion::validate( array( 'start' => '2012-02-29', 'end' => '2012-03-01' ) );
	verify_reunion( ! is_wp_error( $data ) && $data['year'] === '2012', 'Valid dates infer the year.' );
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_POST = array_merge( $valid, array( 'familypedia_action' => Family_Wiki\Reunion::ACTION, 'post_title' => 'Paris reunion', 'creation_key' => '12345678-1234-4234-8234-123456789abc' ) );
	$GLOBALS['writes'] = 0;
	$feature = new Family_Wiki\Reunion();
	foreach ( array( array( false, true ), array( true, false ) ) as $permissions ) {
		list( $GLOBALS['allowed'], $GLOBALS['nonce'] ) = $permissions;
		try { $feature->maybe_save(); } catch ( RuntimeException $e ) {}
		verify_reunion( $GLOBALS['writes'] === 0, 'Capability and nonce failures must not write.' );
	}
	$GLOBALS['allowed'] = true;
	$GLOBALS['nonce'] = true;
	$_POST['reunion_id'] = 1;
	try { $feature->maybe_save(); } catch ( RuntimeException $e ) {}
	verify_reunion( $GLOBALS['writes'] === 0, 'Reunion save must not overwrite a person or ordinary page.' );
	$_POST['reunion_id'] = 0;
	try { $feature->maybe_save(); } catch ( RuntimeException $e ) { verify_reunion( $e->getMessage() === 'redirect:/person/10', 'Successful creation redirects to the reunion.' ); }
	verify_reunion( $GLOBALS['meta'][10][Family_Wiki\Wiki_Page::META_KEY] === 1 && $GLOBALS['meta'][10][Family_Wiki\Reunion::META]['attendees'] === array( 1 ), 'A reunion is a wiki page with structured attendees.' );
	$before = $GLOBALS['writes'];
	$_POST['post_title'] = 'Renamed reunion';
	try { $feature->maybe_save(); } catch ( RuntimeException $e ) { verify_reunion( $e->getMessage() === 'redirect:/person/10', 'Resubmitting creation opens the original record.' ); }
	verify_reunion( $GLOBALS['writes'] === $before && $GLOBALS['posts'][10]->post_title === 'Paris reunion', 'Creation resubmission must not create or overwrite a record.' );
	verify_reunion( Family_Wiki\Reunion::edit_post( '', '' ) === null, 'Only an explicit new route opens the create form.' );
	verify_reunion( is_wp_error( Family_Wiki\Reunion::edit_post( '', 'old-slug' ) ), 'A stale slug must not become a create form.' );
	verify_reunion( is_wp_error( Family_Wiki\Reunion::edit_post( '999', '' ) ), 'Missing ID must not become a create form.' );
	verify_reunion( is_wp_error( Family_Wiki\Reunion::edit_post( '1', '' ) ), 'Person IDs are not reunion editors.' );
	$GLOBALS['posts'][10]->post_name = 'renamed-in-gutenberg';
	verify_reunion( Family_Wiki\Reunion::edit_post( '10', '' )->ID === 10, 'The ID-based editor survives a slug change.' );
	verify_reunion( Family_Wiki\Reunion::edit_url( 10 ) === 'https://example.org/familypedia/reunions/10/edit/', 'Edit links must use IDs, independent of title and slug.' );
	$GLOBALS['posts'][10]->post_content = 'Memories and photos';
	$_POST['reunion_id'] = 10;
	$_POST['attendees'] = array();
	try { $feature->maybe_save(); } catch ( RuntimeException $e ) {}
	verify_reunion( $GLOBALS['meta'][10][Family_Wiki\Reunion::META]['attendees'] === array() && $GLOBALS['posts'][10]->post_content === 'Memories and photos', 'Editing facts can clear attendance without replacing memories or photos.' );
	echo "Reunion year-only dates, attendee validation, nonce, permissions and content preservation checks passed.\n";
}
