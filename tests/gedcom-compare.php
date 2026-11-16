<?php
/** Run with php tests/gedcom-compare.php. */
namespace Family_Wiki {
	class Person {
		const POST_TYPE = 'person';
		public static function url( $id ) { return 'https://example.org/familypedia/person-' . $id . '/'; }
		public static function field( $field, $id ) { return get_post_meta( $id, $field, true ); }
	}
}
namespace {
	function add_action( ...$args ) {}
	function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
	function esc_html__( $value, $domain ) { return esc_html( $value ); }
	function esc_url( $value ) { return esc_html( $value ); }
	function __( $text, $domain ) { return $text; }
	function sanitize_text_field( $text ) { return trim( strip_tags( $text ) ); }
	function get_posts( $args ) { return array( 1, 2 ); }
	function get_post_status( $id ) { return 2 === $id ? 'trash' : 'publish'; }
	function get_the_title( $id ) { return 1 === $id ? 'Jane Smith' : 'Deleted Person'; }
	function get_post_meta( $id, $field, $single ) { return $GLOBALS['meta'][ $id ][ $field ] ?? ''; }
	function current_user_can( $cap, $id ) { return true; }
	require __DIR__ . '/../src/Gedcom.php';
	$gedcom = new \Family_Wiki\Gedcom();
	$parse = new ReflectionMethod( $gedcom, 'parse_records' );
	$parse->setAccessible( true );
	$compare = new ReflectionMethod( $gedcom, 'comparison_changes' );
	$compare->setAccessible( true );
	$GLOBALS['meta'] = array(
		1 => array( '_family_wiki_gedcom_xref' => 'I1', 'birth_date' => '1900-01-01', 'born_as' => 'Jane Smith', 'alive' => 1 ),
		2 => array( '_family_wiki_gedcom_xref' => 'I3' ),
	);
	$records = $parse->invoke( $gedcom, "0 @I1@ INDI\n1 NAME Jane /Smith/\n1 BIRT\n2 DATE ABT 2 JAN 1900\n2 PLAC Vienna\n0 @I2@ INDI\n1 NAME New /Person/\n0 @I3@ INDI\n1 NAME Deleted /Person/\n1 SEX M\n0 TRLR\n" );
	$changes = $compare->invoke( $gedcom, $records );
	$fields = array_column( $changes, 'field' );
	if ( array( 'birth_place' ) !== $fields ) {
		throw new RuntimeException( 'Unexpected changes: ' . json_encode( $changes ) );
	}
	foreach ( $changes as $change ) {
		if ( 1 !== $change['id'] ) { throw new RuntimeException( 'Unmatched or trashed person proposed.' ); }
	}
	if ( '1900-01-01' !== $GLOBALS['meta'][1]['birth_date'] ) { throw new RuntimeException( 'Comparison mutated data.' ); }
	$GLOBALS['meta'][1]['exact_birth_date_unknown'] = 1;
	if ( array( 'birth_date', 'birth_place' ) !== array_column( $compare->invoke( $gedcom, $records ), 'field' ) ) {
		throw new RuntimeException( 'An approximate date should still be compared with another approximate date.' );
	}
	unset( $GLOBALS['meta'][1]['birth_date'], $GLOBALS['meta'][1]['exact_birth_date_unknown'] );
	if ( array( 'birth_date', 'exact_birth_date_unknown', 'birth_place' ) !== array_column( $compare->invoke( $gedcom, $records ), 'field' ) ) {
		throw new RuntimeException( 'An approximate date should still fill a missing date.' );
	}
	$filter = new ReflectionMethod( $gedcom, 'filter_comparison_changes' );
	$filter->setAccessible( true );
	foreach ( array( 'birth', 'death' ) as $event ) {
		$GLOBALS['meta'][1][ $event . '_date' ] = '1900-01-01';
		unset( $GLOBALS['meta'][1][ 'exact_' . $event . '_date_unknown' ] );
		$old_proposals = array(
			10 => array( 'id' => 1, 'field' => $event . '_date', 'old' => '1900-01-01', 'value' => '1901-01-01' ),
			11 => array( 'id' => 1, 'field' => 'exact_' . $event . '_date_unknown', 'old' => '', 'value' => 1 ),
		);
		if ( array() !== $filter->invoke( $gedcom, $old_proposals ) ) {
			throw new RuntimeException( 'Existing review must suppress approximate ' . $event . ' dates.' );
		}
		$old_proposals[11]['value'] = 0;
		if ( array_keys( $old_proposals ) !== array_keys( $filter->invoke( $gedcom, $old_proposals ) ) ) {
			throw new RuntimeException( 'Exact date proposals should remain available.' );
		}
	}
	$GLOBALS['meta'][1]['birth_date'] = '1900-01-02';
	$GLOBALS['meta'][1]['birth_place'] = 'Vienna';
	$GLOBALS['meta'][1]['exact_birth_date_unknown'] = 1;
	if ( array() !== $compare->invoke( $gedcom, $records ) ) { throw new RuntimeException( 'Unchanged facts proposed.' ); }
	unset( $GLOBALS['meta'][1]['born_as'] );
	if ( array() !== $compare->invoke( $gedcom, $records ) ) {
		throw new RuntimeException( 'Missing birth name should default to the person name.' );
	}
	$renamed = $records;
	$renamed['INDI']['I1'][0]['value'] = 'Jane /Jones/';
	$name_changes = $compare->invoke( $gedcom, $renamed );
	if ( array( 'post_title', 'born_as' ) !== array_column( $name_changes, 'field' ) ) {
		throw new RuntimeException( 'A different incoming name should still be proposed.' );
	}
	$GLOBALS['meta'][1]['born_as'] = 'Jane Brown';
	if ( array() !== $compare->invoke( $gedcom, $records ) ) {
		throw new RuntimeException( 'An ordinary matching NAME must not overwrite an explicit birth name.' );
	}
	$redundant = new ReflectionMethod( $gedcom, 'is_redundant_birth_name' );
	$redundant->setAccessible( true );
	foreach ( array( 'Jane Smith', ' jane  smith ', "Jane\xc2\xa0Smith", 'Jane&#32;Smith' ) as $name ) {
		if ( ! $redundant->invoke( $gedcom, 1, 'born_as', $name ) ) {
			throw new RuntimeException( 'Equivalent name was not suppressed.' );
		}
	}
	if ( $redundant->invoke( $gedcom, 1, 'born_as', 'Jane Jones' ) ) {
		throw new RuntimeException( 'Distinct birth name was suppressed.' );
	}
	$edit = new ReflectionMethod( $gedcom, 'comparison_edited_value' );
	$edit->setAccessible( true );
	foreach ( array(
		array( 'post_title', '  Custom name  ', 'Custom name' ),
		array( 'birth_place', '<b>Vienna</b>', 'Vienna' ),
		array( 'alive', '0', 0 ),
		array( 'alive', 'yes', null ),
		array( 'sex', 'Female', 'Female' ),
		array( 'sex', 'invalid', null ),
		array( 'post_title', '', null ),
		array( 'post_title', array( 'invalid' ), null ),
		array( 'birth_place', null, null ),
	) as $case ) {
		if ( $case[2] !== $edit->invoke( $gedcom, $case[0], $case[1] ) ) {
			throw new RuntimeException( 'Edited value validation failed.' );
		}
	}
	$context = new ReflectionMethod( $gedcom, 'render_comparison_family' );
	$context->setAccessible( true );
	$GLOBALS['meta'][1]['father_name'] = 'Parent <name>';
	ob_start();
	$context->invoke( $gedcom, 1, array( 1 => array( 'parents' => array(), 'children' => array( 3 ) ), 3 => array() ) );
	$html = ob_get_clean();
	foreach ( array( 'Parents', 'Children', 'Parent &lt;name&gt;', 'person-3/', 'target="_blank"', 'rel="noopener noreferrer"' ) as $expected ) {
		if ( false === strpos( $html, $expected ) ) {
			throw new RuntimeException( 'Family context missing: ' . $expected );
		}
	}
	echo "GEDCOM comparison checks passed.\n";
}
