<?php
/** Run with php tests/interviews.php; no WordPress database or network required. */
namespace Family_Wiki {
	class App { const URL_PATH = 'familypedia'; const VERSION = 'test'; }
	class Main { public static function flush_family_data_cache( $id ) {} }
	class Calendar {
		public static function is_calendar_enabled() { return false; }
		public static function is_birthdays_enabled() { return false; }
	}
	class Gedcom { public static function can_use() { return false; } }
	class Settings { public static function get_infobox_settings() { return array( 'collapse_mobile' => false ); } }
}
namespace {
	define( 'ABSPATH', '/tmp/' );
	define( 'OBJECT', 'OBJECT' );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'MINUTE_IN_SECONDS', 60 );
	class WP_Post {
		public $ID;
		public $post_type = 'familypedia_person';
		public $post_status = 'publish';
		public $post_parent = 0;
		public $post_title = '';
		public $post_name = '';
		public $post_content = '';
	}
	class WP_Error {
		private $code;
		private $message;
		public function __construct( $code, $message ) { $this->code = $code; $this->message = $message; }
		public function get_error_message() { return $this->message; }
		public function get_error_code() { return $this->code; }
	}
	class Interview_Redirect extends \Exception {}
	class Interview_Json extends \Exception {
		public $payload;
		public $status;
		public function __construct( $payload, $status ) { $this->payload = $payload; $this->status = $status; }
	}
	$GLOBALS['interview_posts'] = array();
	$GLOBALS['interview_meta'] = array();
	$GLOBALS['interview_transients'] = array();
	$GLOBALS['interview_denied'] = array();
	$GLOBALS['interview_caps'] = array();
	function add_action( ...$args ) {}
	function remove_action( ...$args ) {}
	function absint( $value ) { return abs( (int) $value ); }
	function __( $value, $domain ) { return $value; }
	function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
	function esc_attr( $value ) { return esc_html( $value ); }
	function esc_textarea( $value ) { return esc_html( $value ); }
	function esc_url( $value ) { return esc_html( $value ); }
	function esc_html__( $value, $domain ) { return esc_html( $value ); }
	function esc_html_e( $value, $domain ) { echo esc_html( $value ); }
	function esc_attr_e( $value, $domain ) { echo esc_attr( $value ); }
	function selected( $a, $b ) { if ( $a === $b ) { echo 'selected'; } }
	function checked( $value ) { if ( $value ) { echo 'checked'; } }
	function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
	function sanitize_textarea_field( $value ) { return trim( strip_tags( $value ) ); }
	function wpautop( $value ) { return $value ? '<p>' . str_replace( "\n\n", '</p><p>', $value ) . '</p>' : ''; }
	function wp_kses_post( $value ) { return $value; }
	function wp_slash( $value ) { return is_array( $value ) ? array_map( 'wp_slash', $value ) : ( is_string( $value ) ? addslashes( $value ) : $value ); }
	function wp_unslash( $value ) { return is_array( $value ) ? array_map( 'wp_unslash', $value ) : ( is_string( $value ) ? stripslashes( $value ) : $value ); }
	function get_post( $value ) { return $value instanceof WP_Post ? $value : ( $GLOBALS['interview_posts'][ $value ] ?? null ); }
	function get_post_meta( $id, $key, $single = true ) { return $GLOBALS['interview_meta'][ $id ][ $key ] ?? ''; }
	function update_post_meta( $id, $key, $value, $previous = null ) {
		if ( ! empty( $GLOBALS['interview_fail_meta'] ) ) { return false; }
		if ( null !== $previous && get_post_meta( $id, $key ) !== $previous ) { return false; }
		$GLOBALS['interview_meta'][ $id ][ $key ] = wp_unslash( $value );
		return true;
	}
	function delete_post_meta( $id, $key ) { unset( $GLOBALS['interview_meta'][ $id ][ $key ] ); }
	function wp_insert_post( $data, $error = false ) {
		$data = wp_unslash( $data );
		$post = new WP_Post();
		$post->ID = count( $GLOBALS['interview_posts'] ) + 1;
		foreach ( $data as $key => $value ) { if ( 'meta_input' !== $key ) { $post->$key = $value; } }
		$post->post_name = 'page-' . $post->ID;
		$GLOBALS['interview_posts'][ $post->ID ] = $post;
		$GLOBALS['interview_meta'][ $post->ID ] = $data['meta_input'] ?? array();
		return $post->ID;
	}
	function wp_update_post( $data, $error = false ) {
		if ( ! empty( $GLOBALS['interview_fail_post'] ) && $GLOBALS['interview_fail_post'] === $data['ID'] ) { return new WP_Error( 'database', 'Fixture database error' ); }
		$data = wp_unslash( $data );
		$post = get_post( $data['ID'] );
		foreach ( $data as $key => $value ) { $post->$key = $value; }
		return $post->ID;
	}
	function get_posts( $args ) {
		return array_values( array_filter( $GLOBALS['interview_posts'], function ( $post ) use ( $args ) {
			foreach ( array( 'post_type', 'post_parent', 'post_status' ) as $key ) {
				if ( isset( $args[ $key ] ) && ! in_array( $post->$key, (array) $args[ $key ], false ) ) { return false; }
			}
			return ! isset( $args['meta_key'] ) || '' !== get_post_meta( $post->ID, $args['meta_key'] );
		} ) );
	}
	function get_page_by_path( $path, $output, $type ) {
		foreach ( $GLOBALS['interview_posts'] as $post ) { if ( \Family_Wiki\Person::path( $post ) === $path && $post->post_type === $type ) { return $post; } }
		return null;
	}
	function get_the_title( $post ) { return get_post( $post )->post_title; }
	function current_user_can( $cap, $id = 0 ) { return ! in_array( $id, $GLOBALS['interview_denied'], true ) && ( $GLOBALS['interview_caps'][ $cap ] ?? true ); }
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function trailingslashit( $value ) { return rtrim( $value, '/' ) . '/'; }
	function home_url( $path ) { return 'https://example.test' . $path; }
	function plugins_url( $path, $file ) { return '/plugin/' . $path; }
	function get_edit_post_link( $id, $context ) { return '/wp-admin/post.php?post=' . $id; }
	function add_query_arg( $key, $value, $url ) { return $url . '?' . urlencode( $key ) . '=' . urlencode( $value ); }
	function get_current_user_id() { return 1; }
	function set_transient( $key, $value, $expiry ) { $GLOBALS['interview_transients'][ $key ] = $value; }
	function get_transient( $key ) { return $GLOBALS['interview_transients'][ $key ] ?? false; }
	function delete_transient( $key ) { unset( $GLOBALS['interview_transients'][ $key ] ); }
	function check_admin_referer( $action ) { $GLOBALS['interview_nonce'] = $action; }
	function wp_safe_redirect( $url ) { throw new Interview_Redirect( $url ); }
	function wp_send_json_success( $data ) { throw new Interview_Json( array( 'success' => true, 'data' => $data ), 200 ); }
	function wp_send_json_error( $data, $status ) { throw new Interview_Json( array( 'success' => false, 'data' => $data ), $status ); }
	function wp_nonce_field( $action ) { echo '<input type="hidden" name="_wpnonce" value="fixture" />'; }
	function wp_app_get_route_var( $key, $default ) { return $GLOBALS['interview_route'][ $key ] ?? $default; }
	function wp_app_enqueue_script( ...$args ) {}
	function wp_app_enqueue_style( ...$args ) {}
	function wp_app_language_attributes() { echo 'lang="en"'; }
	function wp_app_the_title( $title ) { echo esc_html( $title ); }
	function wp_app_head() {
		echo '<style>' . file_get_contents( __DIR__ . '/../assets/app.css' ) . '</style>';
	}
	function wp_app_body_open() {}
	function wp_app_body_close() { echo '<script>' . file_get_contents( __DIR__ . '/../assets/interviews.js' ) . '</script>'; }
	function get_query_var( $key ) { return ''; }
	function get_bloginfo( $key ) { return 'Family notebook'; }
	function bloginfo( $key ) { echo 'UTF-8'; }
	function current_time( $format ) { return '2026-10-29'; }
	function check( $condition, $message ) { if ( ! $condition ) { throw new \Exception( $message ); } }
	require __DIR__ . '/../src/Person.php';
	require __DIR__ . '/../src/Editor.php';
	require __DIR__ . '/../src/Wiki_Page.php';
	require __DIR__ . '/../src/Assets.php';
	require __DIR__ . '/../src/Interview.php';
	use Family_Wiki\Interview;
	use Family_Wiki\Person;
	use Family_Wiki\Wiki_Page;

	$person = wp_insert_post( array( 'post_title' => 'Maria', 'post_content' => '<p>Existing biography.</p>' ) );
	$other = wp_insert_post( array( 'post_title' => 'Another subject' ) );
	Person::update( 'birth_date', '1948-05-12', $person );
	Person::update( 'birth_place', 'Graz', $person );
	check( is_wp_error( Interview::create( $person, array( 'speaker' => '', 'date' => '2026-10-29' ) ) ), 'Speaker is required' );
	check( is_wp_error( Interview::create( $person, array( 'speaker' => 'Maria', 'date' => '2026-02-30' ) ) ), 'Invalid interview date rejected' );
	$session = Interview::create( $person, array( 'speaker' => "Maria O'Brien", 'date' => '2026-10-29' ) );
	check( ! is_wp_error( $session ), 'Interview created' );
	check( 'draft' === get_post( $session )->post_status && Wiki_Page::is_page( $session ), 'Sources are draft related pages, not people' );
	check( Interview::session( $person, $session ) && ! Interview::session( $other, $session ), 'Source cannot be accessed through another subject' );
	$data = get_post_meta( $session, Interview::META );
	check( $data['reviews']['birth_date']['original'] === '1948-05-12', 'Existing facts snapshotted' );
	$input = $data;
	$input['answers']['home'] = "We lived above Dad's shop. I think it was around 1962. C:\\photos";
	$input['notes'] = '<script>alert(1)</script> A spontaneous memory.';
	$input['follow_up'] = 'Ask Anna about the date.';
	$input['reviews']['birth_date']['status'] = 'correction';
	$input['reviews']['birth_date']['proposed'] = '1949';
	$input['reviews']['birth_date']['note'] = 'Not sure of the exact day.';
	$saved = Interview::save( $person, $session, $input );
	check( ! is_wp_error( $saved ) && 2 === $saved['version'], 'Notes saved with a new version' );
	check( $saved['answers']['home'] === $input['answers']['home'], 'Quotes and backslashes survive metadata storage' );
	check( strpos( get_post( $session )->post_content, '<script>' ) === false, 'Source transcript escapes user content' );
	check( Person::field( 'birth_date', $person ) === '1948-05-12', 'Saving a proposal never changes facts' );
	check( is_wp_error( Interview::save( $person, $session, $input ) ), 'Stale autosave rejected' );
	$applied = Interview::apply_fact( $person, $session, 'birth_date' );
	check( ! is_wp_error( $applied ) && '1949-01-01' === Person::field( 'birth_date', $person ) && Person::field( 'exact_birth_date_unknown', $person ), 'Explicitly applying a year preserves approximate precision' );
	check( '1948-05-12' === $applied['reviews']['birth_date']['original'] && '1949' === $applied['reviews']['birth_date']['applied_value'], 'Original and applied facts retained as provenance' );
	check( is_wp_error( Interview::apply_fact( $person, $session, 'birth_date' ) ), 'Repeated fact application rejected' );
	$input = $applied;
	$input['reviews']['birth_place']['status'] = 'correction';
	$input['reviews']['birth_place']['proposed'] = 'Vienna';
	$saved = Interview::save( $person, $session, $input );
	Person::update( 'birth_place', 'Salzburg', $person );
	check( is_wp_error( Interview::apply_fact( $person, $session, 'birth_place' ) ) && 'Salzburg' === Person::field( 'birth_place', $person ), 'Changed live facts cannot be overwritten by stale testimony' );
	$input = $saved;
	$input['reviews']['death_date']['status'] = 'correction';
	$input['reviews']['death_date']['proposed'] = '1940';
	$saved = Interview::save( $person, $session, $input );
	check( is_wp_error( Interview::apply_fact( $person, $session, 'death_date' ) ), 'Death before birth rejected' );
	$input = $saved;
	$input['reviews']['death_date']['proposed'] = '2020-02-30';
	$saved = Interview::save( $person, $session, $input );
	check( is_wp_error( Interview::apply_fact( $person, $session, 'death_date' ) ), 'Invalid calendar date rejected' );
	$input = $saved;
	$input['reviews']['death_date']['proposed'] = '2020-05-04';
	$saved = Interview::save( $person, $session, $input );
	Person::update( 'alive', true, $person );
	check( is_wp_error( Interview::apply_fact( $person, $session, 'death_date' ) ), 'Living status must be reviewed explicitly before a death date is applied' );
	Person::update( 'alive', false, $person );
	$exact = Interview::apply_fact( $person, $session, 'death_date' );
	check( ! is_wp_error( $exact ) && ! Person::field( 'exact_death_date_unknown', $person ), 'Exact dates remain exact' );
	$input = $exact;
	$GLOBALS['interview_fail_post'] = $session;
	check( is_wp_error( Interview::save( $person, $session, $input ) ), 'Database failure is surfaced' );
	check( get_post_meta( $session, Interview::META )['version'] === $exact['version'], 'Failed transcript save restores the prior version for retry' );
	unset( $GLOBALS['interview_fail_post'] );
	$GLOBALS['interview_fail_meta'] = true;
	check( is_wp_error( Interview::save( $person, $session, $input ) ), 'Metadata write failure is surfaced' );
	unset( $GLOBALS['interview_fail_meta'] );
	$GLOBALS['interview_denied'] = array( $session );
	check( ! Interview::session( $person, $session ) && ! Interview::sessions( $person ), 'Session-specific permissions enforced' );
	check( is_wp_error( Interview::save( $person, $session, $input ) ), 'Forbidden notes cannot be saved' );
	$GLOBALS['interview_denied'] = array();
	$GLOBALS['interview_caps']['edit_others_pages'] = false;
	check( ! Interview::can_manage( $person ) && is_wp_error( Interview::create_draft( $person, array( $session ) ) ), 'App membership required for all actions' );
	$GLOBALS['interview_caps'] = array();
	$third_person = Interview::create( $person, array( 'speaker' => 'Her son', 'date' => '2026-10-29', 'perspective' => 'other' ) );
	$third_data = get_post_meta( $third_person, Interview::META );
	check( $third_data['about_other'] && Interview::catalogue( true )['childhood']['questions']['home'][0] !== Interview::catalogue()['childhood']['questions']['home'][0], 'Prompts adapt when the speaker describes someone else' );
	$third_data['answers']['home'] = 'She lived above a shop.';
	$third_data['speaker'] = 'Her eldest son';
	$third_data['date'] = '2026-10-28';
	$third_saved = Interview::save( $person, $third_person, $third_data );
	check( ! is_wp_error( $third_saved ) && strpos( get_post( $third_person )->post_content, 'their childhood home' ) !== false, 'Source transcript uses the saved perspective' );
	check( strpos( get_post( $third_person )->post_title, 'Her eldest son' ) !== false && $third_saved['date'] === '2026-10-28', 'Speaker and date corrections update the source title' );
	$other_session = Interview::create( $other, array( 'speaker' => 'Someone else', 'date' => '2026-10-29' ) );
	check( is_wp_error( Interview::create_draft( $person, array( $session, $other_session ) ) ), 'Mixed-subject source selection rejected before writing' );
	$draft = Interview::create_draft( $person, array( $session, $session ) );
	check( ! is_wp_error( $draft ), 'Biography outline created' );
	$draft_data = get_post_meta( $draft, Interview::DRAFT_META );
	check( $draft_data['sources'] === array( $session ) && strpos( $draft_data['text'], 'around 1962' ) !== false, 'Outline keeps attribution, original uncertainty and unique source IDs' );
	check( strpos( $draft_data['text'], 'Ask Anna' ) === false && strpos( $draft_data['text'], 'Vienna' ) === false, 'Open questions and unapproved fact proposals stay out of the draft' );
	check( get_post( $person )->post_content === '<p>Existing biography.</p>', 'Creating a draft preserves the biography' );
	$draft_input = array( 'version' => 1, 'draft_text' => "Maria recalled living above her father's shop, perhaps around 1962.", 'biography_hash' => hash( 'sha256', get_post( $person )->post_content ) );
	check( is_wp_error( Interview::save_draft( $person, $draft, $draft_input, true ) ), 'Appending requires explicit review' );
	$draft_input['reviewed'] = '1';
	$draft_input['biography_hash'] = 'stale';
	check( is_wp_error( Interview::save_draft( $person, $draft, $draft_input, true ) ), 'Changed biography detected' );
	$draft_input['biography_hash'] = hash( 'sha256', get_post( $person )->post_content );
	$GLOBALS['interview_fail_post'] = $person;
	check( is_wp_error( Interview::save_draft( $person, $draft, $draft_input, true ) ), 'Append failures surface' );
	check( ! get_post_meta( $draft, Interview::DRAFT_META )['appended'], 'Failed append releases the reservation' );
	unset( $GLOBALS['interview_fail_post'] );
	check( ! is_wp_error( Interview::save_draft( $person, $draft, $draft_input, true ) ), 'Reviewed draft appended' );
	$biography = get_post( $person )->post_content;
	check( strpos( $biography, '<p>Existing biography.</p>' ) === 0 && strpos( $biography, 'Interview sources:' ) !== false, 'Append preserves existing markup and includes source links' );
	check( is_wp_error( Interview::save_draft( $person, $draft, $draft_input, true ) ) && get_post( $person )->post_content === $biography, 'Repeated append cannot duplicate text' );
	check( get_post_meta( $session, Interview::META )['answers']['home'] === $input['answers']['home'], 'Biography editing never changes source answers' );
	$_POST = wp_slash( array_merge( $input, array( 'familypedia_action' => Interview::ACTION, 'person_id' => $person, 'session_id' => $session, 'op' => 'save', 'autosave' => '1' ) ) );
	try { ( new Interview() )->maybe_save(); throw new \Exception( 'Expected JSON' ); }
	catch ( Interview_Json $response ) { check( $response->payload['success'] && 200 === $response->status, 'Autosave returns checked JSON' ); }
	check( $GLOBALS['interview_nonce'] === Interview::ACTION . '_' . $person, 'Action nonce is bound to the subject' );
	try { ( new Interview() )->maybe_save(); throw new \Exception( 'Expected conflict JSON' ); }
	catch ( Interview_Json $response ) { check( ! $response->payload['success'] && 409 === $response->status, 'Stale autosave uses HTTP 409' ); }
	unset( $_POST['autosave'] );
	try { ( new Interview() )->maybe_save(); throw new \Exception( 'Expected redirect' ); }
	catch ( Interview_Redirect $response ) { check( strpos( get_transient( 'familypedia_interview_recovery_1_' . $person ), "Dad's shop" ) !== false, 'Regular form failures retain submitted words for recovery' ); }
	$_POST = array();

	if ( in_array( '--render', $argv, true ) ) {
		$GLOBALS['interview_route'] = array( 'person' => get_post( $person )->post_name );
		delete_transient( 'familypedia_interview_recovery_1_' . $person );
		delete_transient( \Family_Wiki\Editor::NOTICE_TRANSIENT . '1' );
		foreach ( array( 'overview' => array(), 'session' => array( 'session' => $session ), 'draft' => array( 'draft' => Interview::create_draft( $person, array( $session ) ) ) ) as $view => $params ) {
			$_GET = $params;
			ob_start();
			require __DIR__ . '/../templates/interviews.php';
			file_put_contents( '/tmp/interview-' . $view . '.html', ob_get_clean() );
		}
	}
	echo "Interview sessions, dates, provenance, permissions, save conflicts, recovery and biography draft checks passed.\n";
}
