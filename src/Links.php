<?php
/**
 * Wiki links inside a person's text.
 *
 * A link to a person who exists points at their page in the app; one to a
 * person who does not is marked red, the way a wiki marks a page waiting to be
 * written; a link that leaves the site is marked green. Links that a peer wiki
 * can answer point there instead of being marked missing.
 *
 * @package Familypedia
 */

namespace Family_Wiki;

class Links {
	const CACHE_GROUP = 'family-wiki';

	/**
	 * Rewrite and colour the links in a person's wiki text.
	 */
	public static function filter_content( $content ) {
		if ( false === stripos( $content, '<a ' ) ) {
			return $content;
		}

		$index = self::path_index();

		return preg_replace_callback(
			'/<a .*?href="([^"]+)"/i',
			function ( $m ) use ( $index ) {
				return self::filter_link( $m[0], $m[1], $index );
			},
			$content
		);
	}

	private static function filter_link( $tag, $href, $index ) {
		$path = strtolower( $href );

		if ( 0 === strpos( $path, home_url() ) ) {
			$path = substr( $path, strlen( home_url() ) );
		}

		if ( 0 === strpos( $path, '/wp-content' ) || 0 === strpos( $path, '/wp-admin' ) || 0 === strpos( $path, '#' ) ) {
			return $tag;
		}

		if ( false !== strpos( $path, '#' ) ) {
			$path = strtok( $path, '#' );
		}

		if ( 0 === strpos( $path, 'http://' ) || 0 === strpos( $path, 'https://' ) || 0 === strpos( $path, 'mailto:' ) ) {
			return $tag . ' class="familypedia-link familypedia-link--external"';
		}

		$path = trim( $path, '/' );

		// A link already written against the app, and the app's own pages.
		$app_prefix = App::URL_PATH . '/';
		if ( 0 === strpos( $path, $app_prefix ) ) {
			$path = substr( $path, strlen( $app_prefix ) );
		}

		if ( '' === $path || self::is_app_route( $path ) ) {
			return $tag;
		}

		if ( isset( $index[ $path ] ) ) {
			return self::replace_href( $tag, Person::url( $index[ $path ] ) );
		}

		$remote_page = Cross_Wiki::get_remote_page( $path );
		if ( $remote_page ) {
			return self::replace_href( $tag, $remote_page['url'] ) . ' class="familypedia-link familypedia-link--external"';
		}

		return self::replace_href( $tag, home_url( '/' . App::URL_PATH . '/new/?name=' . rawurlencode( $path ) ) ) . ' class="familypedia-link familypedia-link--missing"';
	}

	private static function replace_href( $tag, $url ) {
		return preg_replace( '/href="[^"]+"/i', 'href="' . esc_url( $url ) . '"', $tag, 1 );
	}

	/**
	 * Routes the app serves itself, which are never missing pages.
	 */
	public static function is_app_route( $path ) {
		$path = trim( $path, '/' );

		if ( in_array( $path, array( 'all', 'tree', 'new', 'new-page' ), true ) ) {
			return true;
		}

		if ( Calendar::is_calendar_enabled() && ( 'calendar' === $path || preg_match( '#^calendar/(0?[1-9]|1[0-2])$#', $path ) ) ) {
			return true;
		}

		return Calendar::is_birthdays_enabled() && 'birthdays' === $path;
	}

	/**
	 * A link to a person recorded only by name, such as a father whose page has
	 * not been written yet.
	 */
	public static function name_link( $name ) {
		$slug  = sanitize_title_with_dashes( $name );
		$index = self::path_index();

		if ( isset( $index[ $slug ] ) ) {
			return '<a href="' . esc_url( Person::url( $index[ $slug ] ) ) . '">' . esc_html( $name ) . '</a>';
		}

		return '<a class="familypedia-link familypedia-link--missing" href="' . esc_url( home_url( '/' . App::URL_PATH . '/new/?name=' . rawurlencode( $name ) ) ) . '">' . esc_html( $name ) . '</a>';
	}

	/**
	 * Every published person, by slug and by full path.
	 *
	 * @return array Path => post ID.
	 */
	public static function path_index() {
		static $index;

		if ( isset( $index ) ) {
			return $index;
		}

		$cache_key = self::cache_key();
		$index     = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( is_array( $index ) ) {
			return $index;
		}

		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			$index = $cached;
			wp_cache_set( $cache_key, $index, self::CACHE_GROUP, HOUR_IN_SECONDS );
			return $index;
		}

		$index = array();
		foreach ( Person::get_all( array( 'fields' => 'ids' ) ) as $post_id ) {
			$index[ get_post_field( 'post_name', $post_id ) ] = $post_id;
			$index[ Person::path( $post_id ) ]                = $post_id;
		}

		wp_cache_set( $cache_key, $index, self::CACHE_GROUP, HOUR_IN_SECONDS );
		set_transient( $cache_key, $index, HOUR_IN_SECONDS );

		return $index;
	}

	public static function flush_cache() {
		$cache_key = self::cache_key();
		wp_cache_delete( $cache_key, self::CACHE_GROUP );
		delete_transient( $cache_key );
	}

	private static function cache_key() {
		return 'familypedia_path_index_' . get_current_blog_id();
	}
}
