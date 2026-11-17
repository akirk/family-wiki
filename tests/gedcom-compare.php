<?php
/** Run with php tests/gedcom-compare.php. */
namespace Family_Wiki {
	class Person {
		const POST_TYPE = 'person';
		public static function update( $field, $value, $id ) { update_post_meta( $id, $field, $value ); }
		public static function url( $id ) { return 'https://example.org/familypedia/person-' . $id . '/'; }
		public static function field( $field, $id ) { return get_post_meta( $id, $field, true ); }
	}
}
namespace {
	use Family_Wiki\Gedcom;
	define( 'DAY_IN_SECONDS', 86400 );
	function get_transient( $key ) {
		$entry = $GLOBALS['transients'][ $key ] ?? null;
		return $entry && $entry['expires'] > $GLOBALS['clock'] ? $entry['value'] : false;
	}
	function set_transient( $key, $value, $expiration ) {
		$GLOBALS['transients'][ $key ] = array( 'value' => $value, 'expires' => $GLOBALS['clock'] + $expiration );
		return true;
	}
	function add_action( ...$args ) {}
	function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
	function esc_html__( $value, $domain ) { return esc_html( $value ); }
	function esc_attr( $value ) { return esc_html( $value ); }
	function esc_html_e( $value, $domain ) { echo esc_html( $value ); }
	function esc_attr_e( $value, $domain ) { echo esc_attr( $value ); }
	function checked( $value ) { if ( $value ) { echo 'checked="checked"'; } }
	function selected( $value, $option ) { if ( $value === $option ) { echo 'selected="selected"'; } }
	function esc_url( $value ) { return esc_html( $value ); }
	function wp_kses_post( $value ) { return strip_tags( $value, '<a><span>' ); }
	function __( $text, $domain ) { return $text; }
	function sanitize_text_field( $text ) { return trim( strip_tags( $text ) ); }
	function get_posts( $args ) { return array_merge( array( 1, 2 ), array_keys( $GLOBALS['created'] ?? array() ) ); }
	function get_post_status( $id ) { return 2 === $id ? 'trash' : 'publish'; }
	function get_the_title( $id ) { return 1 === $id ? 'Jane Smith' : 'Deleted Person'; }
	function get_post_meta( $id, $field, $single ) { return $GLOBALS['meta'][ $id ][ $field ] ?? ''; }
	function current_user_can( $cap, $id = null ) { return ! in_array( $id, $GLOBALS['denied'] ?? array(), true ); }
	function get_current_user_id() { return $GLOBALS['user_id'] ?? 7; }
	function get_current_blog_id() { return $GLOBALS['blog_id'] ?? 3; }
	function get_post_type( $id ) { return 99 === $id ? 'post' : \Family_Wiki\Person::POST_TYPE; }
	function wp_slash( $value ) { return $value; }
	function wp_insert_post( $data, $error ) { $id = 10 + count( $GLOBALS['created'] ?? array() ); $GLOBALS['created'][ $id ] = $data; return $id; }
	function is_wp_error( $value ) { return false; }
	function update_post_meta( $id, $field, $value ) { $GLOBALS['meta'][ $id ][ $field ] = $value; }
	require __DIR__ . '/../src/Gedcom.php';
	// These helpers can be exercised without constructing Gedcom or registering hooks.
	$parsed_text = Gedcom::parse_records( "0 @I1@ INDI\r\n1 NAME Jane /Smith/\r1 BIRT\n2 DATE 2 JAN 1900\n0 @F1@ FAM\n1 CHIL @I1@\n0 TRLR\n" );
	if ( 'Jane /Smith/' !== $parsed_text['INDI']['I1'][0]['value'] || '@I1@' !== $parsed_text['FAM']['F1'][0]['value'] ) {
		throw new RuntimeException( 'Static parsing must handle mixed line endings and preserve family pointers.' );
	}
	foreach ( array(
		array( '2 JAN 1900', '1900-01-02', false ),
		array( 'ABT 2 JAN 1900', '1900-01-02', true ),
		array( 'jan 1900', '1900-01-01', true ),
		array( '1900', '1900-01-01', true ),
		array( 'unknown', '', false ),
	) as $case ) {
		if ( array( 'date' => $case[1], 'approximate' => $case[2] ) !== Gedcom::parse_gedcom_date( $case[0] ) ) {
			throw new RuntimeException( 'Static GEDCOM date parsing failed: ' . $case[0] );
		}
	}
	if ( 'jane smith' !== Gedcom::matching_name( " Jane&#32; \xc2\xa0Smith " ) ) {
		throw new RuntimeException( 'Static name normalization must collapse encoded and non-breaking spaces.' );
	}
	foreach ( array(
		array( 'samuel reed', 'samuel james thomas reed', true ),
		array( 'samuel james thomas reed', 'samuel reed', true ),
		array( 'emily reed', 'emily reed parker', true ),
		array( 'daniel reed', 'daniel a reed', true ),
		array( 'jane smith', 'john smith', false ),
		array( 'jane smith', 'smith jane', false ),
		array( 'jane', 'jane smith', false ),
	) as $case ) {
		if ( $case[2] !== Gedcom::matching_name_variant( $case[0], $case[1] ) ) {
			throw new RuntimeException( 'Static name variant matching failed.' );
		}
	}
	$gedcom = new \Family_Wiki\Gedcom();
	$save_state = new ReflectionMethod( $gedcom, 'save_import_state' );
	$save_state->setAccessible( true );
	$read_state = new ReflectionMethod( $gedcom, 'import_state' );
	$read_state->setAccessible( true );
	$GLOBALS['clock'] = 0;
	$save_state->invoke( $gedcom, 'retention-test', array( 'gedcom' => '/tmp/example.ged', 'owner' => 7 ) );
	$GLOBALS['clock'] = 6 * DAY_IN_SECONDS;
	$state = $read_state->invoke( $gedcom, 'retention-test' );
	if ( '/tmp/example.ged' !== ( $state['gedcom'] ?? '' ) || 7 !== ( $state['owner'] ?? 0 ) ) {
		throw new RuntimeException( 'GEDCOM reviews must survive a multi-day pause.' );
	}
	$save_state->invoke( $gedcom, 'retention-test', array() );
	$GLOBALS['clock'] = 8 * DAY_IN_SECONDS;
	if ( $state !== $read_state->invoke( $gedcom, 'retention-test' ) ) {
		throw new RuntimeException( 'Refreshing an import must preserve its files and ownership for another week.' );
	}
	$GLOBALS['clock'] = 13 * DAY_IN_SECONDS;
	if ( array() !== $read_state->invoke( $gedcom, 'retention-test' ) ) {
		throw new RuntimeException( 'Inactive GEDCOM reviews must still expire after a week.' );
	}
	$compare = new ReflectionMethod( $gedcom, 'comparison_changes' );
	$compare->setAccessible( true );
	$GLOBALS['meta'] = array(
		1 => array( '_family_wiki_gedcom_xref' => 'I1', 'birth_date' => '1900-01-01', 'born_as' => 'Jane Smith', 'alive' => 1 ),
		2 => array( '_family_wiki_gedcom_xref' => 'I3' ),
	);
	$records = Gedcom::parse_records( "0 @I1@ INDI\n1 NAME Jane /Smith/\n1 BIRT\n2 DATE ABT 2 JAN 1900\n2 PLAC Vienna\n0 @I2@ INDI\n1 NAME New /Person/\n0 @I3@ INDI\n1 NAME Deleted /Person/\n1 SEX M\n0 TRLR\n" );
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
		if ( $case[2] !== Gedcom::comparison_edited_value( $case[0], $case[1] ) ) {
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
	$missing = new ReflectionMethod( $gedcom, 'comparison_missing_people' );
	$missing->setAccessible( true );
	$adjacent_records = Gedcom::parse_records( "0 @I1@ INDI\n1 NAME Jane /Smith/\n0 @P@ INDI\n1 NAME A /Parent/\n0 @S@ INDI\n1 NAME A /Spouse/\n0 @C@ INDI\n1 NAME A /Child/\n0 @G@ INDI\n1 NAME A /Grandchild/\n0 @B@ INDI\n1 NAME A /Sibling/\n0 @U@ INDI\n1 NAME An /Unrelated/\n0 @I3@ INDI\n1 NAME Deleted /Person/\n0 @F1@ FAM\n1 HUSB @P@\n1 CHIL @I1@\n1 CHIL @B@\n0 @F2@ FAM\n1 HUSB @I1@\n1 WIFE @S@\n1 CHIL @C@\n1 CHIL @I3@\n0 @F3@ FAM\n1 HUSB @C@\n1 CHIL @G@\n0 TRLR\n" );
	$xrefs = array_column( $missing->invoke( $gedcom, $adjacent_records ), 'xref' );
	sort( $xrefs );
	if ( array( 'C', 'P', 'S' ) !== $xrefs ) {
		throw new RuntimeException( 'Only direct parents, children, and spouses should be offered: ' . json_encode( $xrefs ) );
	}
	$import_missing = new ReflectionMethod( $gedcom, 'import_comparison_people' );
	$import_missing->setAccessible( true );
	if ( 1 !== $import_missing->invoke( $gedcom, $adjacent_records, array( 'C', 'G', 'I3', 'invalid' ) ) ) {
		throw new RuntimeException( 'Only selected eligible people should be imported.' );
	}
	if ( 'C' !== $GLOBALS['meta'][10]['_family_wiki_gedcom_xref'] || 'A Child' !== $GLOBALS['created'][10]['post_title'] ) {
		throw new RuntimeException( 'Missing person was not created with GEDCOM facts.' );
	}
	if ( 0 !== $import_missing->invoke( $gedcom, $adjacent_records, array( 'C' ) ) ) {
		throw new RuntimeException( 'A person matched since review must not be imported again.' );
	}
	if ( 1 !== $import_missing->invoke( $gedcom, $adjacent_records, array( 'P' ), array( 'P' => array( 'post_title', 'alive', 'sex' ) ), array( 'P' => array( 'post_title' => 'Custom Parent', 'alive' => '0', 'sex' => 'invalid', 'father' => '1' ) ) ) ) {
		throw new RuntimeException( 'A selected missing person should accept valid overrides.' );
	}
	if ( 'Custom Parent' !== $GLOBALS['created'][11]['post_title'] || 0 !== $GLOBALS['meta'][11]['alive'] || isset( $GLOBALS['meta'][11]['born_as'], $GLOBALS['meta'][11]['father'] ) ) {
		throw new RuntimeException( 'Overrides and unchecked fields were not respected.' );
	}
	if ( isset( $GLOBALS['meta'][11]['sex'] ) || isset( $GLOBALS['meta'][11]['father'] ) || isset( $GLOBALS['meta'][11]['born_as'] ) ) {
		throw new RuntimeException( 'Invalid, unknown, or unchecked fields must not be saved.' );
	}
	$gedcom_family = new ReflectionMethod( $gedcom, 'render_comparison_gedcom_family' );
	$gedcom_family->setAccessible( true );
	ob_start();
	$gedcom_family->invoke( $gedcom, 'S', $adjacent_records );
	$html = ob_get_clean();
	foreach ( array( 'Parents', 'Children', 'Spouses', 'Jane Smith', 'person-1/', 'A Child' ) as $expected ) {
		if ( false === strpos( $html, $expected ) ) {
			throw new RuntimeException( 'Missing GEDCOM family context: ' . $expected );
		}
	}
	$table = new ReflectionMethod( $gedcom, 'render_comparison_table' );
	$table->setAccessible( true );
	ob_start();
	$table->invoke( $gedcom, array( 'post_title' => array( 'field' => 'post_title', 'old' => 'A Parent', 'value' => 'A Parent' ) ), array( 'post_title' => 'Name on wiki' ), 'missing_fields[P]', 'missing_values[P]', true );
	$html = ob_get_clean();
	foreach ( array( 'missing_fields[P][]', 'missing_values[P][post_title]', 'data-gedcom-new', 'data-gedcom-copy="A Parent"', 'checked="checked"', 'Familypedia value' ) as $expected ) {
		if ( false === strpos( $html, $expected ) ) {
			throw new RuntimeException( 'Shared missing-person editor is incomplete: ' . $expected );
		}
	}
	$adjacent_records['FAM'] = array();
	if ( array() !== $missing->invoke( $gedcom, $adjacent_records ) ) {
		throw new RuntimeException( 'Unrelated missing people should not be offered.' );
	}
	$find = new ReflectionMethod( $gedcom, 'find_person_post' );
	$find->setAccessible( true );
	$GLOBALS['meta'][40] = array( 'birth_date' => '1920-04-12' );
	$GLOBALS['meta'][41] = array( 'birth_date' => '1950-06-21' );
	$matching_index = array(
		'xref' => array(), 'title' => array(), 'trashed' => array(),
		'aliases' => array( 'olivia rose thomas' => array( 40 ), 'samuel reed' => array( 41 ), 'samuel james thomas reed' => array( 41 ) ),
	);
	foreach ( array(
		array( 'Olivia Rose Thomas', '1920', 40 ),
		array( " Olivia  Rose Thomas ", '1920', 40 ),
		array( 'Samuel James Reed', '1950', 41 ),
		array( 'Samuel James Reed', '1951', 0 ),
		array( 'Samuel James Reed', '', 0 ),
		array( 'Samuel Smith', '1950', 0 ),
	) as $case ) {
		if ( $case[2] !== $find->invoke( $gedcom, 'NEW', $case[0], $matching_index, array(), $case[1] ) ) {
			throw new RuntimeException( 'Alias or variant match failed: ' . $case[0] );
		}
	}
	$GLOBALS['meta'][42] = array( 'birth_date' => '1950-01-01' );
	$matching_index['aliases']['samuel reed'][] = 42;
	if ( 0 !== $find->invoke( $gedcom, 'NEW', 'Samuel James Reed', $matching_index, array(), '1950' ) ) {
		throw new RuntimeException( 'Ambiguous name variants must not be automatically matched.' );
	}
	unset( $matching_index['aliases']['samuel reed'][1] );
	$GLOBALS['meta'][41]['_family_wiki_gedcom_xref'] = 'OTHER';
	if ( 0 !== $find->invoke( $gedcom, 'NEW', 'Samuel James Reed', $matching_index, array(), '1950' ) ) {
		throw new RuntimeException( 'Alias matching must respect conflicting GEDCOM IDs.' );
	}
	$reason = new ReflectionMethod( $gedcom, 'render_comparison_match_reason' );
	$reason->setAccessible( true );
	ob_start();
	$reason->invoke( $gedcom, array( 'xref' => 'MISSING', 'name' => 'A New Person', 'birth_year' => '1900' ) );
	$html = ob_get_clean();
	foreach ( array( 'Why offered as a new person?', 'MISSING', 'birth name', '1900', 'unique candidate', 'parent, child, or spouse' ) as $expected ) {
		if ( false === strpos( $html, $expected ) ) {
			throw new RuntimeException( 'Missing match explanation: ' . $expected );
		}
	}
	$skipped_method = new ReflectionMethod( $gedcom, 'comparison_skipped_people' );
	$skipped_method->setAccessible( true );
	$skipped_people = $skipped_method->invoke( $gedcom, $records, array( array( 'id' => 1 ) ), array() );
	$skipped_by_xref = array_column( $skipped_people, null, 'xref' );
	if ( isset( $skipped_by_xref['I1'] ) || ! isset( $skipped_by_xref['I2'], $skipped_by_xref['I3'] ) ) {
		throw new RuntimeException( 'Skipped tab must exclude editable people and include excluded records.' );
	}
	if ( false === strpos( $skipped_by_xref['I3']['reason'], 'trash' ) || false === strpos( $skipped_by_xref['I2']['reason'], 'not a direct' ) ) {
		throw new RuntimeException( 'Skipped records must explain trash and adjacency exclusions.' );
	}
	$unchanged = $skipped_method->invoke( $gedcom, $records, array(), array() );
	$unchanged_by_xref = array_column( $unchanged, null, 'xref' );
	if ( false === strpos( $unchanged_by_xref['I1']['reason'], 'No eligible fact differences' ) ) {
		throw new RuntimeException( 'Unchanged matched people must be explained in Skipped.' );
	}
	if ( 0 !== $import_missing->invoke( $gedcom, $records, array( 'I2' ) ) ) {
		throw new RuntimeException( 'A skipped person must require an explicit adjacency override.' );
	}
	if ( 1 !== $import_missing->invoke( $gedcom, $records, array(), array( 'I2' => array( 'post_title' ) ), array( 'I2' => array( 'post_title' => 'Overridden skipped person' ) ), array( 'I2' ) ) ) {
		throw new RuntimeException( 'Explicitly selected skipped people should be imported.' );
	}
	if ( 0 !== $import_missing->invoke( $gedcom, $records, array(), null, array(), array( 'I1', 'I3', 'I2', 'FORGED' ) ) ) {
		throw new RuntimeException( 'Skipped overrides must reject active, trashed, newly matched, and unknown records.' );
	}
	$validate_snapshot = new ReflectionMethod( $gedcom, 'client_comparison_snapshot' );
	$validate_snapshot->setAccessible( true );
	$save_review = new ReflectionMethod( $gedcom, 'save_client_comparison' );
	$save_review->setAccessible( true );
	$browser_snapshot = array(
		'version' => 1, 'owner' => 7, 'site' => 3, 'contents' => "0 TRLR\n",
		'changes' => array(
			4 => array( 'id' => 1, 'field' => 'birth_place', 'old' => 'Original', 'value' => 'GEDCOM place' ),
			8 => array( 'id' => 1, 'field' => 'death_place', 'old' => '', 'value' => 'GEDCOM death place' ),
			9 => array( 'id' => 1, 'field' => 'alive', 'old' => 1, 'value' => 0 ),
			10 => array( 'id' => 1, 'field' => 'sex', 'old' => '', 'value' => 'Female' ),
			11 => array( 'id' => 2, 'field' => 'birth_place', 'old' => '', 'value' => 'Trash change' ),
			12 => array( 'id' => 99, 'field' => 'birth_place', 'old' => '', 'value' => 'Wrong post type' ),
		),
	);
	if ( $browser_snapshot !== $validate_snapshot->invoke( $gedcom, json_encode( $browser_snapshot ) ) ) {
		throw new RuntimeException( 'A browser snapshot should be usable without a server-side review.' );
	}
	$structured_snapshot = $browser_snapshot;
	$structured_snapshot['contents'] = "0 @JSON_TEST@ INDI\n1 NAME Example /Person/\n1 NOTE Quotes \"example\", slashes /path/, percent %20, and <literal> text.\n0 TRLR\n";
	if ( $structured_snapshot !== $validate_snapshot->invoke( $gedcom, json_encode( $structured_snapshot ) ) ) {
		throw new RuntimeException( 'Snapshot validation must preserve GEDCOM JSON text for later field sanitization.' );
	}
	foreach ( array( array( 'owner', 8 ), array( 'site', 4 ), array( 'version', 2 ), array( 'contents', array() ) ) as $invalid ) {
		$bad = $browser_snapshot;
		$bad[ $invalid[0] ] = $invalid[1];
		if ( false !== $validate_snapshot->invoke( $gedcom, $bad ) ) {
			throw new RuntimeException( 'Snapshot validation failed: ' . $invalid[0] );
		}
	}
	$bad = $browser_snapshot;
	$bad['changes'][4]['field'] = 'post_content';
	if ( false !== $validate_snapshot->invoke( $gedcom, $bad ) || false !== $validate_snapshot->invoke( $gedcom, '{invalid' ) ) {
		throw new RuntimeException( 'Client-supplied snapshots must enforce the field allowlist and valid JSON.' );
	}
	$GLOBALS['meta'][1]['birth_place'] = 'Another editor changed this';
	unset( $GLOBALS['meta'][1]['death_place'], $GLOBALS['meta'][1]['sex'] );
	$GLOBALS['meta'][1]['alive'] = 1;
	$selection = array( 'changes' => array( '4', '8', '9', '10', '11', '12' ), 'values' => array( 4 => 'My birth place', 8 => 'My death place', 9 => '0', 10 => 'invalid', 11 => 'Trash edit', 12 => 'Wrong type edit' ) );
	$saved = $save_review->invoke( $gedcom, $browser_snapshot, $selection );
	if ( array( 'post:1:death_place', 'post:1:alive' ) !== $saved['saved'] || 4 !== count( $saved['errors'] ) ) {
		throw new RuntimeException( 'Independent saves must preserve conflicts and invalid or forbidden edits: ' . json_encode( $saved ) );
	}
	if ( 'Another editor changed this' !== $GLOBALS['meta'][1]['birth_place'] || 'My death place' !== $GLOBALS['meta'][1]['death_place'] || 0 !== $GLOBALS['meta'][1]['alive'] ) {
		throw new RuntimeException( 'A conflict must not prevent other fields from being saved.' );
	}
	if ( 'Another editor changed this' !== $saved['errors']['post:1:birth_place']['current'] ) {
		throw new RuntimeException( 'Conflict responses must include the current value for explicit review.' );
	}
	$retry = $save_review->invoke( $gedcom, $browser_snapshot, $selection );
	if ( $saved['saved'] !== $retry['saved'] ) {
		throw new RuntimeException( 'Retrying after a lost response must recognize already saved values.' );
	}
	$GLOBALS['denied'] = array( 1 );
	$forbidden = $save_review->invoke( $gedcom, $browser_snapshot, array( 'changes' => array( '8' ), 'values' => array( 8 => 'Forbidden' ) ) );
	if ( $forbidden['saved'] || ! isset( $forbidden['errors']['post:1:death_place'] ) ) {
		throw new RuntimeException( 'Browser snapshots must not bypass current editing permissions.' );
	}
	$GLOBALS['denied'] = array();
	$browser_snapshot['changes'][4]['old'] = $saved['errors']['post:1:birth_place']['current'];
	$resolved = $save_review->invoke( $gedcom, $browser_snapshot, array( 'changes' => array( '4' ), 'values' => array( 4 => 'My birth place' ) ) );
	if ( array( 'post:1:birth_place' ) !== $resolved['saved'] ) {
		throw new RuntimeException( 'An explicitly reselected conflict should be saved.' );
	}
	$browser_snapshot['changes'] = array();
	$browser_snapshot['contents'] = "0 @LATER@ INDI\n1 NAME Later /Person/\n0 TRLR\n";
	$import_selection = array( 'skipped' => array( 'LATER' ), 'missing_fields' => array( 'LATER' => array( 'post_title' ) ), 'missing_values' => array( 'LATER' => array( 'post_title' => '' ) ) );
	$invalid_import = $save_review->invoke( $gedcom, $browser_snapshot, $import_selection );
	if ( $invalid_import['imported'] || ! isset( $invalid_import['errors']['import:LATER'] ) ) {
		throw new RuntimeException( 'Invalid selected import facts must leave the whole person pending.' );
	}
	$import_selection['missing_values']['LATER']['post_title'] = 'Later override';
	$created_from_browser = $save_review->invoke( $gedcom, $browser_snapshot, $import_selection );
	if ( array( 'LATER' ) !== $created_from_browser['imported'] ) {
		throw new RuntimeException( 'A skipped import should work from browser GEDCOM contents with no transient or upload file.' );
	}
	$duplicate = $save_review->invoke( $gedcom, $browser_snapshot, $import_selection );
	if ( $duplicate['imported'] || ! isset( $duplicate['errors']['import:LATER'] ) ) {
		throw new RuntimeException( 'Repeated browser import requests must not create duplicate people.' );
	}
	echo "GEDCOM comparison checks passed.\n";
}
