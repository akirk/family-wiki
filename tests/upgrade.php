<?php
/** Run with php tests/upgrade.php; uses an isolated in-memory WordPress fixture. */
namespace Family_Wiki {
	class App { const URL_PATH = 'familypedia'; }
	class Main {
		public static function flush_family_data_cache() {}
	}
	class Front_Page {
		const DEFAULT_CONTENT = '';
		public static function ensure_post() { return null; }
	}
	class Settings {
		const INFOBOX_OPTION = 'familypedia_infobox_settings';
		const APP_PAGES_OPTION = 'familypedia_app_pages';
	}
	class Cross_Wiki {
		const OPTION = 'familypedia_cross_wiki_sites';
	}
}
namespace WpApp {
	class Registry {
		public static function get_apps() { return $GLOBALS['apps']; }
	}
}
namespace {
	class WP_Error {
		public $code;
		public function __construct( $code, $message ) { $this->code = $code; }
	}
	class WP_Post {}
	function add_action( ...$args ) {}
	function __( $text, $domain ) { return $text; }
	function absint( $id ) { return abs( (int) $id ); }
	function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
	function wp_roles() { return (object) array( 'roles' => $GLOBALS['fixture_roles'] ?? array() ); }
	function get_option( $key, $default = false ) { return $GLOBALS['options'][ $key ] ?? $default; }
	function update_option( $key, $value ) { $GLOBALS['options'][ $key ] = $value; }
	function add_option( $key, $value ) { if ( ! array_key_exists( $key, $GLOBALS['options'] ) ) { update_option( $key, $value ); } }
	function get_post( $id ) { return $GLOBALS['posts'][ $id ] ?? null; }
	function get_post_type( $id ) { return get_post( $id )->post_type ?? false; }
	function get_posts( $args ) {
		return array_values( array_filter( $GLOBALS['posts'], function ( $post ) use ( $args ) {
			return $post->post_type === $args['post_type'] && ! in_array( $post->ID, $args['exclude'] ?? array(), true );
		} ) );
	}
	function metadata_exists( $type, $id, $key ) { return array_key_exists( $key, $GLOBALS['meta'][ $id ] ?? array() ); }
	function get_post_meta( $id, $key, $single = true ) { return $GLOBALS['meta'][ $id ][ $key ] ?? ''; }
	function update_post_meta( $id, $key, $value ) { $GLOBALS['meta'][ $id ][ $key ] = $value; }
	function get_page_uri( $post ) {
		return $post->post_parent ? get_page_uri( get_post( $post->post_parent ) ) . '/' . $post->post_name : $post->post_name;
	}
	function get_permalink( $post ) { return 'https://example.org/' . get_page_uri( $post ) . '/'; }
	function untrailingslashit( $url ) { return rtrim( $url, '/' ); }
	function wp_update_post( $data, $error = false ) {
		foreach ( $data as $key => $value ) { $GLOBALS['posts'][ $data['ID'] ]->$key = $value; }
		return $data['ID'];
	}
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function flush_rewrite_rules() {}
	function is_user_logged_in() { return $GLOBALS['logged_in']; }
	function current_user_can( $cap ) { return $GLOBALS['allowed']; }
	function is_front_page() { return $GLOBALS['front']; }
	function is_feed() { return $GLOBALS['feed']; }
	function is_preview() { return false; }
	function is_paged() { return false; }
	function is_embed() { return false; }
	function is_search() { return false; }
	function nocache_headers() { $GLOBALS['nocache'] = true; }
	function status_header( $code ) { $GLOBALS['status'] = $code; }
	function set_query_var( $key, $value ) { $GLOBALS['query_vars'][ $key ] = $value; }
	function check( $condition, $message ) {
		if ( ! $condition ) { throw new \RuntimeException( $message ); }
	}
	require dirname( __DIR__ ) . '/src/Person.php';
	require dirname( __DIR__ ) . '/src/Upgrade.php';
	require dirname( __DIR__ ) . '/src/Homepage.php';
	// App identity is the only App dependency used by the homepage adapter.
	foreach ( array( 1 => array( 'john', 0 ), 2 => array( 'story', 1 ), 3 => array( 'jane', 0 ), 4 => array( 'contact', 0 ), 5 => array( 'home', 0 ) ) as $id => $values ) {
		$post = new WP_Post();
		$post->ID = $id;
		$post->post_type = 'page';
		$post->post_name = $values[0];
		$post->post_parent = $values[1];
		$post->post_content = 'Original text';
		$GLOBALS['posts'][ $id ] = $post;
	}
	$GLOBALS['options'] = array( 'page_on_front' => 5, 'family_wiki_infobox_settings' => array( 'collapse_mobile' => false ) );
	$GLOBALS['meta'] = array();
	check( ! \Family_Wiki\Upgrade::has_legacy_pages(), 'Fresh sites with ordinary pages must not trigger the upgrade notice.' );
	$GLOBALS['meta'][4]['birth_date'] = '';
	check( \Family_Wiki\Upgrade::has_legacy_pages(), 'Legacy ACF fields must be detected even when empty.' );
	$GLOBALS['meta'] = array( 1 => array( 'birth_date' => '19840612', 'spouse' => array( '3' ), 'marriages' => array( array( 'spouse' => '3', 'marriage_date' => '20100612' ) ), '_thumbnail_id' => 99 ) );
	$rows = \Family_Wiki\Upgrade::review_rows();
	check( $rows[1]['reason'] === 'facts', 'Saved facts must select the person.' );
	check( $rows[2]['reason'] === 'hierarchy', 'Nested page without facts must be included with its parent.' );
	check( $rows[3]['reason'] === 'relationship', 'Spouse without facts must be selected through the saved relationship.' );
	check( $rows[4]['reason'] === '', 'Unconnected ordinary page must stay unchecked with a reason.' );
	check( ! isset( $rows[5] ), 'Homepage must remain excluded.' );
	check( $rows[1]['relationships']['spouse'] === array( 3 ), 'Raw ACF relationship IDs must resolve without ACF, including marriage rows.' );
	$GLOBALS['meta'][4]['father'] = '3';
	$connected_rows = \Family_Wiki\Upgrade::review_rows();
	check( $connected_rows[4]['reason'] === 'facts', 'Raw ACF parent relationship must count as family details.' );
	check( $connected_rows[4]['relationships']['father'] === array( 3 ), 'Scalar ACF parent ID must be read.' );
	unset( $GLOBALS['meta'][4] );
	check( \Family_Wiki\Upgrade::has_legacy_pages(), 'Existing family facts must trigger migration detection.' );
	$saved_options = $GLOBALS['options'];
	$GLOBALS['allowed'] = true;
	$GLOBALS['options'] = array();
	$GLOBALS['fixture_roles'] = array();
	\Family_Wiki\Upgrade::record_previous_install();
	check( ! \Family_Wiki\Upgrade::should_show_notice(), 'Family metadata alone must not trigger an upgrade notice on a fresh install.' );
	$GLOBALS['fixture_roles'] = array( 'wiki-user' => array( 'capabilities' => array( 'family-wiki' => true ) ) );
	\Family_Wiki\Upgrade::record_previous_install();
	check( ! \Family_Wiki\Upgrade::should_show_notice(), 'Roles added after a fresh install must not change its recorded history.' );
	$GLOBALS['options'] = array();
	\Family_Wiki\Upgrade::record_previous_install();
	check( \Family_Wiki\Upgrade::should_show_notice(), 'Pre-existing 1.x wiki roles and unmigrated pages must trigger the notice.' );
	$GLOBALS['options'][\Family_Wiki\Upgrade::OPTION] = '2.0.0';
	check( ! \Family_Wiki\Upgrade::should_show_notice(), 'Completed migration must hide the notice.' );
	$GLOBALS['options'] = array( 'family_wiki_app_version' => '2.0.0' );
	\Family_Wiki\Upgrade::record_previous_install();
	check( ! \Family_Wiki\Upgrade::should_show_notice(), 'New-version roles must not be mistaken for old-install history.' );
	$GLOBALS['options'] = array( 'family_wiki_virtual_pages' => array() );
	$GLOBALS['fixture_roles'] = array();
	\Family_Wiki\Upgrade::record_previous_install();
	check( \Family_Wiki\Upgrade::should_show_notice(), 'Stored 1.x settings must establish prior use even if empty.' );
	$GLOBALS['options'] = $saved_options;

	check( is_wp_error( \Family_Wiki\Upgrade::migrate( array( 2 ) ) ), 'Child without parent must be rejected.' );
	check( is_wp_error( \Family_Wiki\Upgrade::migrate( array( 1 ) ) ), 'Parent without child must be rejected.' );
	check( is_wp_error( \Family_Wiki\Upgrade::migrate( array( 1, 2 ) ) ), 'Omitted spouse must be rejected.' );
	check( get_post_type( 1 ) === 'page', 'Failed validation must leave posts untouched.' );
	get_post( 4 )->post_name = 'tree';
	check( is_wp_error( \Family_Wiki\Upgrade::migrate( array( 4 ) ) ), 'Reserved app slugs must be rejected before conversion.' );
	get_post( 4 )->post_name = 'contact';
	check( \Family_Wiki\Upgrade::migrate( array( 1, 2, 3 ) ) === 3, 'Three pages must migrate.' );
	check( get_post_type( 1 ) === \Family_Wiki\Person::POST_TYPE, 'Person must become app content.' );
	check( get_post_type( 4 ) === 'page' && get_post_type( 5 ) === 'page', 'Ordinary pages and homepage must remain pages.' );
	check( get_post( 2 )->post_parent === 1, 'Hierarchy must remain intact.' );
	check( get_post( 1 )->post_content === 'Original text', 'Content must remain intact.' );
	check( get_post_meta( 1, '_thumbnail_id' ) === 99, 'Featured image must remain intact.' );
	check( get_post_meta( 1, 'spouse' ) === array( '3' ), 'Relationship IDs must remain intact.' );
	check( get_post_meta( 1, 'birth_date' ) === '1984-06-12', 'ACF date must be normalized.' );
	check( get_post_meta( 1, 'marriages' )[0]['marriage_date'] === '2010-06-12', 'Marriage date must be normalized.' );
	check( get_post_meta( 2, \Family_Wiki\Upgrade::META ) === 'https://example.org/john/story', 'Legacy child URL must be captured before conversion.' );
	check( get_option( 'familypedia_infobox_settings' ) === array( 'collapse_mobile' => false ), 'Settings must migrate.' );
	check( \Family_Wiki\Upgrade::migrate( array( 1, 2, 3 ) ) === 0, 'Repeated migration must be harmless.' );
	$GLOBALS['logged_in'] = true;
	$GLOBALS['allowed'] = true;
	check( ! \Family_Wiki\Homepage::enabled_for_user(), 'Homepage must default off.' );
	update_option( \Family_Wiki\Homepage::OPTION, true );
	check( \Family_Wiki\Homepage::enabled_for_user(), 'Authorized member must see enabled homepage.' );
	$GLOBALS['allowed'] = false;
	check( ! \Family_Wiki\Homepage::enabled_for_user(), 'Unprivileged user must not see member homepage.' );
	$GLOBALS['logged_in'] = false;
	$GLOBALS['allowed'] = true;
	check( ! \Family_Wiki\Homepage::enabled_for_user(), 'Anonymous visitors must not see member homepage.' );
	$GLOBALS['logged_in'] = true;
	$GLOBALS['front'] = true;
	$GLOBALS['feed'] = false;
	$GLOBALS['wp'] = (object) array( 'request' => '' );
	$GLOBALS['apps'] = array( 'familypedia' => new class {
		public function handle_app_request_directly( $path ) {
			check( $path === '', 'Homepage must render the app index.' );
			throw new \RuntimeException( 'rendered' );
		}
	} );
	$homepage = new \Family_Wiki\Homepage();
	$GLOBALS['wp']->request = 'ordinary-page';
	$homepage->route();
	$GLOBALS['wp']->request = '';
	$GLOBALS['feed'] = true;
	$homepage->route();
	check( empty( $GLOBALS['nocache'] ), 'Other paths and feeds must not be intercepted.' );
	$GLOBALS['feed'] = false;
	try {
		$homepage->route();
		throw new \RuntimeException( 'Homepage was not rendered.' );
	} catch ( \RuntimeException $exception ) {
		check( $exception->getMessage() === 'rendered', $exception->getMessage() );
	}
	check( $GLOBALS['nocache'] && $GLOBALS['status'] === 200, 'Homepage must send private no-cache headers and success status.' );
	check( $GLOBALS['query_vars']['wp_app_path'] === 'familypedia', 'Homepage must set the app context.' );
	echo "Migration and homepage routing/access checks passed.\n";
}
