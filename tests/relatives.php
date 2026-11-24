<?php
/** Run with php tests/relatives.php; isolated relationship checks. */
namespace {
	require __DIR__ . '/relative-fixture.php';
	use Family_Wiki\Editor;
	use Family_Wiki\Person;
	$GLOBALS['people'] = array();
	$id = wp_insert_post( array( 'post_title' => 'Alex' ) );
	$direct_sibling = Editor::add_relative( $id, 'sibling', 'Sam' );
	check( ! is_wp_error( $direct_sibling ), 'Sibling can be added without a recorded parent' );
	check( Person::to_ids( Person::field( 'siblings', $id ) ) === array( $direct_sibling ), 'Direct sibling saved' );
	check( Person::to_ids( Person::field( 'siblings', $direct_sibling ) ) === array( $id ), 'Sibling links back' );
	check( ! Person::field( 'father', $direct_sibling ) && ! Person::field( 'mother', $direct_sibling ), 'No parent invented' );
	Editor::add_relative( $id, 'sibling', 'Sam' );
	check( count( Person::field( 'siblings', $id ) ) === 1, 'Repeated sibling addition is idempotent' );
	check( Family_Wiki\Relationships::siblings( $id )[0]->ID === $direct_sibling, 'Direct sibling appears in relationships' );
	check( Family_Wiki\Relationships::siblings( $direct_sibling )[0]->ID === $id, 'Sibling visible from both people' );
	check( is_wp_error( Editor::add_relative( $id, 'sibling', 'Alex' ) ), 'Self sibling rejected' );
	$descendant = Editor::add_relative( $direct_sibling, 'child_father', 'Descendant' );
	check( is_wp_error( Editor::add_relative( $direct_sibling, 'sibling', 'Descendant' ) ), 'Descendant cannot become a sibling' );
	$before_siblings = Person::field( 'siblings', $direct_sibling );
	$GLOBALS['people'][99] = clone $GLOBALS['people'][$id];
	$GLOBALS['people'][99]->ID = 99;
	$GLOBALS['people'][99]->post_title = 'Restricted';
	check( is_wp_error( Editor::add_relative( 99, 'sibling', 'Sam' ) ), 'Direct sibling respects edit permissions' );
	unset( $GLOBALS['people'][99] );
	check( Person::field( 'siblings', $direct_sibling ) === $before_siblings, 'Rejected sibling leaves existing links unchanged' );
	$parent = Editor::add_relative( $id, 'father', 'Pat' );
	check( Person::field( 'father', $id )->ID === $parent, 'Father linked' );
	check( Person::to_ids( Person::field( 'children', $parent ) ) === array( $id ), 'Reciprocal child linked' );
	check( (bool) Person::field( 'is_stub', $parent ), 'Created person is a stub' );
	$sibling = Editor::add_relative( $id, 'sibling', 'Sam' );
	check( Person::field( 'father', $sibling )->ID === $parent, 'Sibling shares father' );
	check( Family_Wiki\Relationships::siblings( $id )[0]->ID === $sibling, 'Sibling appears in derived relationships' );
	$child = Editor::add_relative( $id, 'child_mother', 'Robin' );
	check( Person::field( 'mother', $child )->ID === $id, 'Child uses selected parent role' );
	check( is_wp_error( Editor::add_relative( $id, 'child_father', 'Pat' ) ), 'Ancestor cannot become a child' );
	$count = count( $GLOBALS['people'] );
	check( is_wp_error( Editor::add_relative( $id, 'father', 'Other' ) ), 'Existing parent protected' );
	check( count( $GLOBALS['people'] ) === $count, 'No orphan stub on failure' );
	check( is_wp_error( Editor::add_relative( $id, 'mother', 'Alex' ) ), 'Self relationship rejected' );
	$dated = Editor::add_relative( $id, 'child_father', 'Taylor', '1923-2000' );
	check( Person::field( 'birth_date', $dated ) === '1923-01-01', 'Birth year saved' );
	check( Person::field( 'death_date', $dated ) === '2000-01-01', 'Death year saved' );
	check( Person::field( 'exact_birth_date_unknown', $dated ) && Person::field( 'exact_death_date_unknown', $dated ), 'Years are not exact dates' );
	Person::update( 'birth_date', '1923-05-06', $dated );
	check( ! is_wp_error( Editor::add_relative( $id, 'child_father', 'Taylor', '1923' ) ), 'Existing person linked again safely' );
	check( Person::field( 'birth_date', $dated ) === '1923-05-06', 'Exact date preserved' );
	check( is_wp_error( Editor::add_relative( $id, 'child_father', 'Taylor', '1924' ) ), 'Conflicting year rejected' );
	foreach ( array( '2000-1923', '0000', '1923-0000', '23', '1923-02-29', '2000-13-01', '2000-05-06 – 2000-05-05', 'nonsense' ) as $years ) {
		check( is_wp_error( Editor::parse_relative_years( $years ) ), 'Invalid years rejected: ' . $years );
	}
	check( Editor::parse_relative_years( '1923–2000' ) === array( 'birth' => '1923', 'death' => '2000' ), 'En dash supported' );
	check( Editor::parse_relative_years( '' ) === array(), 'Years optional' );
	check( Editor::parse_relative_years( '1923-05-06 – 2000-10-12' ) === array( 'birth' => '1923-05-06', 'death' => '2000-10-12' ), 'Exact date range supported' );
	check( Editor::parse_relative_years( '1923-2000-10-12' ) === array( 'birth' => '1923', 'death' => '2000-10-12' ), 'Mixed precision supported' );
	$exact = Editor::add_relative( $id, 'child_father', 'Exact dates', '1923-05-06 – 2000-10-12' );
	check( Person::field( 'birth_date', $exact ) === '1923-05-06' && Person::field( 'death_date', $exact ) === '2000-10-12', 'Exact dates saved' );
	check( ! Person::field( 'exact_birth_date_unknown', $exact ) && ! Person::field( 'exact_death_date_unknown', $exact ), 'Exact date flags saved' );
	check( is_wp_error( Editor::add_relative( $id, 'child_father', 'Exact dates', '1923-05-07' ) ), 'Conflicting exact date rejected' );
	check( ! is_wp_error( Editor::add_relative( $id, 'child_father', 'Taylor', '1923-05-06' ) ), 'Year-only date can gain precision' );
	check( ! Person::field( 'exact_birth_date_unknown', $dated ), 'Adding precision clears unknown flag' );


	check( Person::field( 'sex', $parent ) === 'Male', 'New father gets male sex' );
	check( Person::field( 'sex', $id ) === 'Female', 'A recorded sex is not overwritten by a different parent role' );
	Person::update( 'sex', 'Unknown', $parent );
	Editor::add_relative( $id, 'father', 'Pat' );
	check( Person::field( 'sex', $parent ) === 'Male', 'Linking an existing unknown father fills sex' );
	$partnership_rows = Person::sanitize_marriages( array( array( 'spouse' => $parent, 'relationship_type' => 'partnership' ), array( 'relationship_type' => 'partnership' ) ) );
	check( count( $partnership_rows ) === 1, 'Empty partnership row is discarded' );
	check( $partnership_rows[0]['relationship_type'] === 'partnership', 'Unmarried relationship type survives sanitization' );
	check( ! isset( Person::sanitize_marriages( array( array( 'spouse' => $parent ) ) )[0]['relationship_type'] ), 'Legacy marriages keep their default meaning' );
	$spouse = Editor::add_relative( $id, 'spouse', 'Avery', '1920-1990' );
	check( ! is_wp_error( $spouse ) && Person::field( 'is_stub', $spouse ), 'New spouse is a stub' );
	check( Person::field( 'marriages', $id )[0]['spouse'] === $spouse, 'Person links spouse' );
	check( Person::field( 'marriages', $spouse )[0]['spouse'] === $id, 'Spouse links back to person' );
	$marriages = Person::field( 'marriages', $id );
	$marriages[0]['marriage_date'] = '1940-06-07';
	Person::update( 'marriages', $marriages, $id );
	Editor::add_relative( $id, 'spouse', 'Avery' );
	check( count( Person::field( 'marriages', $id ) ) === 1, 'Retry does not duplicate spouse' );
	check( Person::field( 'marriages', $id )[0]['marriage_date'] === '1940-06-07', 'Marriage details preserved' );
	check( is_wp_error( Editor::add_relative( $id, 'spouse', 'Alex' ) ), 'Self marriage rejected' );
	$legacy_spouse = wp_insert_post( array( 'post_title' => 'Legacy spouse' ) );
	Person::update( 'spouse', array( $legacy_spouse ), $spouse );
	Person::update( 'marriage_date', '1960-01-02', $spouse );
	Editor::add_relative( $id, 'spouse', 'Avery' );
	check( count( Person::field( 'marriages', $spouse ) ) === 2, 'Legacy spouse remains visible with modern marriages' );
	check( Person::field( 'marriages', $spouse )[1]['marriage_date'] === '1960-01-02', 'Legacy marriage date preserved' );
	require __DIR__ . '/../src/Family_Graph.php';
	$editable_graph = Family_Wiki\Family_Graph::render( $id, true );
	check( substr_count( $editable_graph, 'data-family-add' ) === 4, 'One plus control for each family group' );
	check( strpos( $editable_graph, '1923–2000' ) !== false, 'Saved years appear in graph' );
	$readonly_graph = Family_Wiki\Family_Graph::render( $id, false, false );
	check( strpos( $readonly_graph, 'data-family-add' ) === false, 'Block display has no editing controls' );
	check( strpos( $readonly_graph, 'Avery' ) !== false, 'Spouse visible in shared graph' );
	check( strpos( $readonly_graph, '1923' ) === false, 'Show dates setting respected' );
	check( strpos( $readonly_graph, 'Sam' ) !== false, 'Sibling appears in shared graph' );

	foreach ( array( 'Male' => 'father', 'Female' => 'mother' ) as $sex => $role ) {
		Person::update( 'sex', $sex, $id );
		$inferred = Editor::add_relative( $id, 'child', 'Child of ' . $sex );
		check( ! is_wp_error( $inferred ) && Person::field( $role, $inferred )->ID === $id, 'Parent role inferred for ' . $sex );
		$graph = Family_Wiki\Family_Graph::render( $id, true );
		check( strpos( $graph, 'value="child"' ) !== false && strpos( $graph, 'value="child_father"' ) === false, 'Known sex hides child role selector' );
	}
	foreach ( array( '', 'Unknown' ) as $sex ) {
		Person::update( 'sex', $sex, $id );
		check( is_wp_error( Editor::add_relative( $id, 'child', 'Needs role' ) ), 'Unknown sex requires explicit parent role' );
		check( strpos( Family_Wiki\Family_Graph::render( $id, true ), 'value="child_father"' ) !== false, 'Unknown sex shows child role selector' );
	}


	Person::update( 'sex', 'Unknown', $id );
	$another_child = Editor::add_relative( $id, 'child_mother', 'Another child' );
	check( Person::field( 'sex', $id ) === 'Female', 'Unknown sex filled when linking an existing person as mother' );
	$_POST = array(
		'familypedia_action' => 'familypedia_add_relative',
		'familypedia_ajax' => '1',
		'familypedia_tree' => '1',
		'person_id' => $id,
		'relatives' => array(
			'7' => array( 'relationship' => 'mother', 'name' => 'Dana', 'years' => '1900-1980' ),
			'8' => array( 'relationship' => 'sibling', 'name' => 'Lee', 'years' => '1925' ),
			'9' => array( 'relationship' => 'child_father', 'name' => 'Invalid years', 'years' => 'bad' ),
		),
	);
	try {
		( new Editor() )->maybe_add_relative();
		throw new Exception( 'Expected AJAX response' );
	} catch ( Json_Response $response ) {
		check( ! empty( $GLOBALS['tree_reset'] ), 'Descendant rendering starts with a fresh expansion state' );
		check( $response->data['tree'] === '<ul data-root="' . $id . '"><li>Updated descendants</li></ul>', 'Tree-page batch response includes the updated root outline' );
		check( $GLOBALS['nonce_action'] === 'familypedia_add_relative_' . $id, 'Batch nonce tied to person' );
		check( count( $response->data['results'] ) === 3, 'Every batch row has a result' );
		check( substr_count( $response->data['graph'], 'data-family-add' ) === 3, 'Parents plus disappears after both parents are saved; siblings and children remain available' );
		check( array_column( $response->data['results'], 'saved' ) === array( true, true, false ), 'Batch reports individual failures' );
		check( array_column( $response->data['results'], 'key' ) === array( '7', '8', '9' ), 'Client row keys preserved' );
		check( strpos( $response->data['graph'], 'Dana' ) !== false && strpos( $response->data['graph'], 'Lee' ) !== false, 'Response graph includes saved family' );
		$lee = Editor::resolve_person( 'Lee' );
		check( Person::field( 'mother', $lee )->ID === Person::field( 'mother', $id )->ID, 'Sibling uses parent created earlier in the batch' );
		check( Editor::resolve_person( 'Invalid years' ) === 0, 'Invalid years do not create an orphan stub' );
	}
	$_POST = array();

	echo "Relative creation and stub checks passed.\n";
}
