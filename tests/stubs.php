<?php
/** Run with php tests/stubs.php; isolated stub review fixture. */
require __DIR__ . '/relatives.php';
require __DIR__ . '/../src/Stubs.php';

function has_post_thumbnail( $id ) { return ! empty( $GLOBALS['photos'][ $id ] ); }
function wp_strip_all_tags( $text ) { return strip_tags( $text ); }
function strip_shortcodes( $text ) { return preg_replace( '/\[[^\]]*\]/', '', $text ); }

use Family_Wiki\Person;
use Family_Wiki\Stubs;

$GLOBALS['people'] = array();
$GLOBALS['meta'] = array();
$empty = wp_insert_post( array( 'post_title' => 'Empty person' ) );
$full = wp_insert_post( array( 'post_title' => 'Detailed person' ) );
$prose = wp_insert_post( array( 'post_title' => 'Biography person' ) );
$page = wp_insert_post( array( 'post_title' => 'Wiki page' ) );
$draft = wp_insert_post( array( 'post_title' => 'Draft person' ) );
Person::update( 'birth_date', '1923', $full );
Person::update( 'birth_place', 'Vienna', $full );
Person::update( 'is_stub', true, $full );
Person::update( 'is_stub', true, $draft );
get_post( $draft )->post_status = 'draft';
get_post( $prose )->post_content = '<!-- wp:paragraph --><p>' . str_repeat( 'History ', 40 ) . '</p><!-- /wp:paragraph -->';
Person::update( 'sex', 'Male', $empty );
Person::update( 'father', $full, $empty );
update_post_meta( $page, '_familypedia_page', '1' );
Person::update( 'is_stub', true, $page );
check( Stubs::detail( $empty )['sparse'], 'Sex and links alone do not make a page complete' );
check( ! Stubs::detail( $full )['sparse'], 'Two personal facts pass the detail threshold' );
check( Stubs::detail( $prose )['words'] === 40 && ! Stubs::detail( $prose )['sparse'], '40 biography words pass the threshold without counting block markup' );
check( array_map( function ( $post ) { return $post->ID; }, Stubs::suggestions() ) === array( $empty, $full ), 'Review suggests adding and clearing flags, excluding pages and drafts' );
check( Person::field( 'is_stub', $empty ) === '' && Person::field( 'is_stub', $full ), 'Preview never changes flags' );
check( count( Stubs::people( 'Detailed' ) ) === 1 && count( Stubs::people( 'No match' ) ) === 0, 'Search filters stub work list' );
check( count( Stubs::suggestions( 'Empty' ) ) === 1, 'Search filters review suggestions' );
check( count( Stubs::people() ) === 1 && Stubs::people()[0]->ID === $full, 'Management list contains only published person stubs' );
check( Stubs::apply( array( $empty, $full ), true ) === 2, 'Apply suggestions in both directions' );
check( Person::field( 'is_stub', $empty ) && ! Person::field( 'is_stub', $full ), 'Only stub flags updated' );
check( Person::field( 'birth_place', $full ) === 'Vienna' && Person::field( 'father', $empty )->ID === $full, 'Facts and relationships untouched' );
check( Stubs::apply( array( $empty, $empty ) ) === 1, 'Manual completion deduplicates selected IDs' );
check( ! Person::field( 'is_stub', $empty ), 'Manual completion removes flag even on a sparse page' );
check( is_wp_error( Stubs::apply( array() ) ), 'Empty selection has an error' );
check( is_wp_error( Stubs::apply( array( $page ) ) ), 'Content pages cannot be managed as people' );
check( is_wp_error( Stubs::apply( array( $draft ) ) ), 'Drafts cannot be managed through published people view' );
$restricted = clone get_post( $empty );
$restricted->ID = 99;
$GLOBALS['people'][99] = $restricted;
check( is_wp_error( Stubs::apply( array( $empty, 99 ), true ) ), 'Mixed permission selection rejected' );
check( ! Person::field( 'is_stub', $empty ), 'No partial writes when another person is forbidden' );
check( count( Stubs::people( '', false ) ) === 3, 'Scan excludes people the editor cannot edit' );
get_post( $empty )->post_content = '<p>&nbsp;</p>[bio]';
check( Stubs::detail( $empty )['words'] === 0, 'Whitespace markup and biography shortcodes do not count as prose' );
Person::update( 'birth_date', '1900', $empty );
$GLOBALS['photos'][ $empty ] = true;
check( ! Stubs::detail( $empty )['sparse'], 'A photo counts as a personal fact' );
check( Stubs::apply( array( $empty ), true ) === 0, 'Apply reevaluates current detail rather than stale preview' );
echo "Stub review, completion and permissions checks passed.\n";

$placeholder = '<!-- wp:paragraph --><p>This page is a stub, created from the handwritten family tree. Stories and further details can be added later.</p><!-- /wp:paragraph -->';
$source = '<!-- wp:paragraph --><p>Source: the original Family Wiki homepage.</p><!-- /wp:paragraph -->';
get_post( $empty )->post_content = $placeholder . "\n\n" . $source;
check( Stubs::detail( $empty )['words'] === 0 && Stubs::detail( $empty )['biography'] === '', 'Imported stub boilerplate does not count as biography or appear in preview' );
$note = '<!-- wp:paragraph --><p>Her surname has two recorded spellings.</p><!-- /wp:paragraph -->';
check( Stubs::without_placeholder( $placeholder . "\n\n" . $note . "\n\n" . $source ) === $note, 'Cleaning placeholder preserves real family notes and valid block markup' );
check( Stubs::without_placeholder( $source ) === $source, 'Standalone source attribution is preserved on pages without a stub placeholder' );
check( Stubs::detail( $prose )['biography'] === trim( str_repeat( 'History ', 40 ) ), 'Biography preview matches the counted text' );

class Stub_Redirect extends Exception {}
function get_current_user_id() { return 1; }
function set_transient( $key, $value, $expiry ) { $GLOBALS['stub_notice'] = $value; }
function _n( $singular, $plural, $count, $domain ) { return 1 === $count ? $singular : $plural; }
function add_query_arg( $args, $value = null, $url = null ) {
	if ( ! is_array( $args ) ) { $args = array( $args => $value ); } else { $url = $value; }
	return $url . '?' . http_build_query( array_filter( $args, function ( $value ) { return false !== $value; } ) );
}
function wp_safe_redirect( $url ) { throw new Stub_Redirect( $url ); }
define( 'MINUTE_IN_SECONDS', 60 );
Person::update( 'is_stub', true, $empty );
Person::update( 'is_stub', true, $full );
$_POST = array( 'familypedia_action' => Stubs::ACTION, 'complete_person' => $empty, 'people' => array( $full ), 'q' => 'Empty', 'review' => '0' );
try {
	( new Stubs() )->maybe_save();
	throw new Exception( 'Expected completion redirect' );
} catch ( Stub_Redirect $redirect ) {
	check( ! Person::field( 'is_stub', $empty ) && Person::field( 'is_stub', $full ), 'Individual completion ignores other selected checkboxes' );
	check( $GLOBALS['nonce_action'] === Stubs::ACTION, 'Management action verifies its nonce' );
	check( strpos( $redirect->getMessage(), 'q=Empty' ) !== false, 'Completion preserves the search' );
}
$_POST = array();


$reference_spouse = wp_insert_post( array( 'post_title' => 'Reference spouse' ) );
$reference_child = wp_insert_post( array( 'post_title' => 'Reference child' ) );
Person::update( 'mother_name', 'Unlinked mother', $empty );
$GLOBALS['fixture_tree'] = array(
	$empty => array(
		'parents' => array( $full ),
		'children' => array( $reference_child, $draft ),
		'partners' => array(
			array( 'id' => $reference_spouse, 'name' => '', 'wed' => true ),
			array( 'id' => $reference_spouse, 'name' => '', 'wed' => true ),
			array( 'id' => $page, 'name' => '', 'wed' => true ),
			array( 'id' => $draft, 'name' => '', 'wed' => true ),
			array( 'id' => $prose, 'name' => '', 'wed' => false ),
		),
	),
	$full => array( 'children' => array( $empty, $prose, $draft ) ),
);
$references = Stubs::relatives( $empty );
check( count( $references['parents'] ) === 2, 'Family context includes linked parents and recorded plain names' );
check( array_column( $references['siblings'], 'id' ) === array( $prose ), 'Shared-parent references exclude self and unpublished people' );
check( array_column( $references['spouses'], 'id' ) === array( $reference_spouse ), 'Spouse references deduplicate and exclude co-parents, wiki pages and drafts' );
check( array_column( $references['children'], 'id' ) === array( $reference_child ), 'Child references exclude unpublished people' );
unset( $GLOBALS['fixture_tree'] );
echo "Direct relative references checks passed.\n";
