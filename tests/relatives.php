<?php
/** Run with php tests/relatives.php; isolated relationship fixture. */
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
	foreach ( array( '2000-1923', '0000', '1923-0000', '23', '1923-05-06', 'nonsense' ) as $years ) {
		check( is_wp_error( Editor::parse_relative_years( $years ) ), 'Invalid years rejected: ' . $years );
	}
	check( Editor::parse_relative_years( '1923–2000' ) === array( 'birth' => '1923', 'death' => '2000' ), 'En dash supported' );
	check( Editor::parse_relative_years( '' ) === array(), 'Years optional' );

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
	define( 'ABSPATH', __DIR__ . '/' );
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
