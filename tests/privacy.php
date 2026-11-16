<?php
/** Run with php tests/privacy.php; isolated legacy privacy compatibility checks. */

$GLOBALS['privacy_hooks'] = array();
function get_option( $key ) { return $GLOBALS['privacy_option']; }
function add_action( $hook, $callback, ...$args ) { $GLOBALS['privacy_hooks'][ $hook ] = $callback; }
function add_filter( $hook, $callback, ...$args ) { add_action( $hook, $callback, ...$args ); }
function checked( $actual, $expected ) { if ( (string) $actual === (string) $expected ) { echo 'checked="checked"'; } }
function esc_html_e( $text, $domain ) { echo htmlspecialchars( $text, ENT_QUOTES ); }
function get_current_blog_id() { return $GLOBALS['privacy_blog']; }
function wp_get_current_user() {
	return new class {
		public $ID = 0;
	};
}
function check_privacy( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }

require __DIR__ . '/../class-private-site.php';

foreach ( array( 1, '1', 0, '0', false ) as $value ) {
	$GLOBALS['privacy_option'] = $value;
	$GLOBALS['privacy_hooks'] = array();
	new Family_Wiki\Private_Site();
	check_privacy( ! $GLOBALS['privacy_hooks'], 'Non-legacy sites must not receive whole-site restrictions or the privacy selector.' );
}
foreach ( array( -1, '-1' ) as $value ) {
	$GLOBALS['privacy_option'] = $value;
	$GLOBALS['privacy_hooks'] = array();
	new Family_Wiki\Private_Site();
	foreach ( array( 'parse_request', 'rest_dispatch_request', 'xmlrpc_methods', 'preprocess_comment', 'blog_privacy_selector' ) as $hook ) {
		check_privacy( isset( $GLOBALS['privacy_hooks'][ $hook ] ), 'Legacy privacy hook missing: ' . $hook );
	}
	ob_start();
	call_user_func( $GLOBALS['privacy_hooks']['blog_privacy_selector'] );
	$html = ob_get_clean();
	check_privacy( strpos( $html, 'Legacy Family Wiki:' ) !== false, 'The legacy privacy option must identify its origin.' );
	check_privacy( strpos( $html, 'value="-1" checked="checked"' ) !== false, 'Legacy setting must remain selected when Reading settings are saved.' );
}
$GLOBALS['privacy_blog'] = 1;
check_privacy( Family_Wiki\Private_Site::is_private(), 'Anonymous visitors must remain blocked on a legacy private site.' );
$GLOBALS['privacy_blog'] = 2;
$GLOBALS['privacy_option'] = 1;
check_privacy( ! Family_Wiki\Private_Site::is_private(), 'Switching to a public peer site must not inherit legacy restrictions.' );
echo "Legacy private-site preservation and new-site visibility checks passed.\n";
