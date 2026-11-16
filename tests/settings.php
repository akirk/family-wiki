<?php
/** Run with php tests/settings.php; settings save and authorization checks. */
namespace Family_Wiki {
	class App { const URL_PATH = 'familypedia'; }
	class Cross_Wiki { const OPTION = 'cross_wiki'; }
	class Editor { public static function set_notice( $message, $type ) {} }
}
namespace {
	require __DIR__ . '/../src/Settings.php';
	require __DIR__ . '/../src/Homepage.php';
	function add_action( ...$args ) {}
	function current_user_can( $cap ) { return $GLOBALS['allowed']; }
	function esc_html__( $text, $domain ) { return $text; }
	function __( $text, $domain ) { return $text; }
	function wp_die( ...$args ) { throw new RuntimeException( 'forbidden' ); }
	function check_admin_referer( $action ) { if ( ! $GLOBALS['nonce'] ) { throw new RuntimeException( 'nonce' ); } }
	function wp_unslash( $value ) { return $value; }
	function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
	function update_option( $key, $value ) { $GLOBALS['options'][ $key ] = $value; }
	function is_multisite() { return $GLOBALS['multisite']; }
	function home_url( $path ) { return 'https://example.org' . $path; }
	function wp_safe_redirect( $url ) { throw new RuntimeException( $url ); }
	function verify_settings( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }
	$settings = new \Family_Wiki\Settings();
	$GLOBALS['options'] = array( 'cross_wiki' => array( 'existing' ) );
	$GLOBALS['multisite'] = false;
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_POST = array( 'family_wiki_member_homepage' => '1', 'familypedia_save_settings' => '1', 'familypedia_infobox_settings' => array( 'show_related_pages' => '1' ) );
	foreach ( array( array( false, true, 'forbidden' ), array( true, false, 'nonce' ), array( true, true, \Family_Wiki\Settings::url() ) ) as $case ) {
		$GLOBALS['allowed'] = $case[0];
		$GLOBALS['nonce'] = $case[1];
		try { $settings->maybe_save(); } catch ( RuntimeException $e ) { verify_settings( $e->getMessage() === $case[2], 'Expected capability check, nonce check, or redirect' ); }
		if ( ! $case[0] || ! $case[1] ) { verify_settings( count( $GLOBALS['options'] ) === 1, 'Rejected save must not change options' ); }
	}
	verify_settings( $GLOBALS['options']['familypedia_infobox_settings'] === array( 'show_related_pages' => true, 'show_cross_wiki' => false, 'collapse_mobile' => false ), 'Checkboxes sanitize and missing values clear' );
	verify_settings( $GLOBALS['options']['familypedia_app_pages'] === array( 'calendar' => false, 'birthdays' => false ), 'All app pages can be disabled' );
	verify_settings( $GLOBALS['options']['cross_wiki'] === array( 'existing' ), 'Single-site save preserves cross-wiki configuration' );
	verify_settings( $GLOBALS['options'][ \Family_Wiki\Homepage::OPTION ] === true, 'Member homepage can be enabled' );
	unset( $_POST[ \Family_Wiki\Homepage::OPTION ] );
	$GLOBALS['multisite'] = true;
	try { $settings->maybe_save(); } catch ( RuntimeException $e ) {}
	verify_settings( $GLOBALS['options']['cross_wiki'] === array(), 'Removing every wiki clears saved connections' );
	verify_settings( $GLOBALS['options'][ \Family_Wiki\Homepage::OPTION ] === false, 'Member homepage can be disabled' );
	echo "Settings save, nonce and permission checks passed.\n";
}
