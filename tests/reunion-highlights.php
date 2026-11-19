<?php
/** Run with php tests/reunion-highlights.php; anniversary windows and highlight rendering. */
namespace Family_Wiki {
	class Person {
		const POST_TYPE = 'familypedia_person';
		public static function get_all( $args = array() ) { return array(); }
		public static function url( $p ) { return 'https://example.org/familypedia/' . $p->post_name . '/'; }
	}
	class App { const URL_PATH = 'familypedia'; }
	class Wiki_Page { public static function is_page( $p ) { return ! empty( $p->wiki_page ); } }
	class Calendar {
		public static function is_birthdays_enabled() { return false; }
		public static function is_calendar_enabled() { return false; }
	}
}
namespace {
	define( 'MINUTE_IN_SECONDS', 60 );
	function wp_timezone() { return new DateTimeZone( 'Europe/Berlin' ); }
	function get_post( $p ) { return is_object( $p ) ? $p : ( $GLOBALS['posts'][$p] ?? null ); }
	function get_post_meta( $id, $key, $single ) { return $GLOBALS['meta'][$id][$key] ?? ''; }
	function get_posts( $args ) { return array_values( array_filter( $GLOBALS['posts'], function( $p ) use ( $args ) { return $p->post_type === $args['post_type'] && $p->post_status === $args['post_status'] && isset( $GLOBALS['meta'][$p->ID][$args['meta_key']] ); } ) ); }
	function get_post_thumbnail_id( $p ) { return get_post( $p )->thumbnail ?? 0; }
	function parse_blocks( $text ) { return json_decode( $text, true ) ?: array(); }
	function wp_get_attachment_image( $id, $size ) { return '<img data-id="' . $id . '" />'; }
	function get_the_title( $p ) { return get_post( $p )->post_title; }
	function home_url( $path ) { return 'https://example.org' . $path; }
	function __( $s, $domain ) { return $s; }
	function _n( $one, $many, $n, $domain ) { return $n === 1 ? $one : $many; }
	function esc_html( $s ) { return htmlspecialchars( $s, ENT_QUOTES ); }
	function esc_attr( $s ) { return esc_html( $s ); }
	function esc_html__( $s, $domain ) { return esc_html( $s ); }
	function esc_attr__( $s, $domain ) { return esc_attr( $s ); }
	function esc_url( $s ) { return esc_html( $s ); }
	function wp_date( $format, $timestamp = null ) { return ( new DateTimeImmutable( '@' . ( $timestamp ?? $GLOBALS['now']->getTimestamp() ) ) )->setTimezone( wp_timezone() )->format( $format ); }
	function get_current_blog_id() { return 1; }
	function get_locale() { return 'en_US'; }
	function wp_cache_get( $key, $group ) { return $GLOBALS['cache'][$key] ?? false; }
	function wp_cache_set( $key, $value, ...$args ) { $GLOBALS['cache'][$key] = $value; }
	function wp_cache_delete( $key, $group ) { unset( $GLOBALS['cache'][$key] ); }
	function get_transient( $key ) { return false; }
	function set_transient( ...$args ) {}
	function delete_transient( ...$args ) {}
	function verify_highlight( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } }
	require __DIR__ . '/../src/Reunion.php';
	require __DIR__ . '/../src/Highlights.php';
	function event_fixture( $id, $start, $end = '', $status = 'publish' ) {
		$GLOBALS['posts'][$id] = (object) array( 'ID'=>$id, 'post_title'=>'Visit ' . $id, 'post_name'=>'visit-' . $id, 'post_content'=>'', 'post_type'=>'familypedia_person', 'post_status'=>$status, 'wiki_page'=>true );
		$GLOBALS['meta'][$id][Family_Wiki\Reunion::META] = array( 'year'=>substr( $start, 0, 4 ), 'start'=>$start, 'end'=>$end, 'location'=>'Vienna', 'attendees'=>array() );
	}
	function events_on( $date, $days = 7 ) { return Family_Wiki\Reunion::anniversaries( new DateTimeImmutable( $date, wp_timezone() ), $days ); }
	function ids( $events ) { return array_map( function( $e ) { return $e['post']->ID; }, $events ); }
	$GLOBALS['posts'] = array(); $GLOBALS['meta'] = array();
	event_fixture( 1, '2024-11-15', '2024-11-17' );
	event_fixture( 2, '2023-11-19' );
	event_fixture( 3, '2020-11-26' );
	event_fixture( 4, '2020-11-27' );
	event_fixture( 5, '' ); $GLOBALS['meta'][5][Family_Wiki\Reunion::META]['year'] = '2012';
	event_fixture( 6, '2026-11-19' );
	event_fixture( 7, '2027-11-19' );
	event_fixture( 8, '2023-11-19', '', 'draft' );
	event_fixture( 9, '2023-11-19', '', 'private' );
	$events = events_on( '2026-11-19' );
	verify_highlight( ids( $events ) === array( 2, 3 ), 'Show today and the inclusive seven-day window, excluding ended, future, zero-year, private, draft and year-only records.' );
	verify_highlight( $events[0]['years'] === 3, 'Calculate elapsed years from the original date.' );
	$events = events_on( '2026-11-16', 0 );
	verify_highlight( ids( $events ) === array( 1 ) && $events[0]['years'] === 2, 'Show a multi-day anniversary throughout the original date range.' );
	verify_highlight( ids( events_on( '2026-11-17', 0 ) ) === array( 1 ), 'Include the last day of a visit.' );
	verify_highlight( events_on( '2026-11-18', 0 ) === array(), 'Stop after the end date.' );
	event_fixture( 10, '2020-12-30', '2021-01-03' );
	$events = events_on( '2027-01-02', 0 );
	verify_highlight( ids( $events ) === array( 10 ) && $events[0]['years'] === 6 && $events[0]['start']->format('Y-m-d') === '2026-12-30', 'New Year ranges retain the anniversary count of their start.' );
	verify_highlight( ids( events_on( '2026-12-24' ) ) === array( 10 ), 'Look ahead across New Year.' );
	event_fixture( 11, '2020-02-29' );
	verify_highlight( events_on( '2025-02-28' ) === array(), 'Do not silently move leap-day anniversaries to March 1.' );
	$events = events_on( '2024-02-29', 0 );
	verify_highlight( ids( $events ) === array( 11 ) && $events[0]['years'] === 4, 'Leap-day anniversaries appear on February 29.' );
	event_fixture( 12, '2020-02-30' ); event_fixture( 13, '2020-11-19', '2020-11-18' );
	verify_highlight( ids( events_on( '2026-11-19' ) ) === array( 2, 3 ), 'Malformed and reversed stored dates are ignored.' );
	$utc = new DateTimeImmutable( '2026-11-18 23:30:00', new DateTimeZone( 'UTC' ) );
	verify_highlight( ids( Family_Wiki\Reunion::anniversaries( $utc, 0 ) ) === array( 2 ), 'Determine today in the WordPress site timezone.' );
	$GLOBALS['posts'] = array(); $GLOBALS['meta'] = array(); $GLOBALS['cache'] = array();
	$GLOBALS['now'] = new DateTimeImmutable( 'today', wp_timezone() );
	$original = $GLOBALS['now']->setDate( (int)$GLOBALS['now']->format('Y') - 4, (int)$GLOBALS['now']->format('m'), (int)$GLOBALS['now']->format('d') );
	event_fixture( 20, $original->format('Y-m-d') );
	$GLOBALS['posts'][20]->post_title = 'A <family> visit';
	$GLOBALS['posts'][20]->post_content = json_encode( array( array( 'blockName'=>'core/gallery', 'attrs'=>array(), 'innerBlocks'=>array( array( 'blockName'=>'core/image', 'attrs'=>array('id'=>77), 'innerBlocks'=>array() ) ) ) ) );
	$GLOBALS['posts'][21] = (object) array( 'ID'=>21, 'post_title'=>'A & B', 'post_name'=>'attendee', 'post_type'=>'familypedia_person', 'post_status'=>'publish' );
	$GLOBALS['posts'][22] = (object) array( 'ID'=>22, 'post_title'=>'Private person', 'post_name'=>'private', 'post_type'=>'familypedia_person', 'post_status'=>'draft' );
	$GLOBALS['meta'][20][Family_Wiki\Reunion::META]['attendees'] = array( 21, 22, 999 );
	$html = Family_Wiki\Highlights::render();
	foreach ( array( 'Reunion and visit anniversaries', '4 years ago', 'data-id="77"', 'A &lt;family&gt; visit', 'Vienna', '/attendee/', 'A &amp; B' ) as $expected ) { verify_highlight( strpos( $html, $expected ) !== false, 'Missing rendered anniversary information: ' . $expected ); }
	verify_highlight( strpos($html,'Private person') === false, 'Do not reveal unpublished attendee names.' );
	$GLOBALS['posts'][20]->thumbnail = 88;
	verify_highlight( Family_Wiki\Reunion::photo_id( $GLOBALS['posts'][20] ) === 88, 'Prefer the featured photo over gallery images.' );
	$old_keys = array_keys($GLOBALS['cache']); $GLOBALS['now'] = $GLOBALS['now']->modify('+1 day'); Family_Wiki\Highlights::render();
	verify_highlight( count(array_keys($GLOBALS['cache'])) > count($old_keys), 'Highlights use a new cache key after the site-local day changes.' );
	echo "Reunion anniversary date windows, ranges, New Year, leap days, timezone, privacy and highlight rendering checks passed.\n";
}
