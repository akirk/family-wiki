<?php
namespace Family_Wiki;

/**
 * Keeps the people out of automatic page lists.
 *
 * A block theme with no menu falls back to a Page List block, which renders
 * every published page. On a family wiki that is the entire family, so the site
 * navigation grows by one entry per person and is unusable after an import.
 */
class Page_Lists {
	/**
	 * Meta keys that mark a page as a person rather than a page about the site.
	 */
	const PERSON_KEYS = array( 'birth_date', 'death_date', 'sex', 'father', 'mother', GEDCOM::XREF_META );

	public function __construct() {
		add_filter( 'pre_render_block', array( $this, 'pre_render_block' ), 10, 2 );
		add_filter( 'render_block', array( $this, 'render_block' ), 10, 2 );
	}

	/**
	 * Filter get_pages() only while a page list is rendering, so that page
	 * queries elsewhere, the parent dropdown in the editor above all, still see
	 * every page.
	 */
	public function pre_render_block( $pre_render, $block ) {
		if ( $this->applies_to( $block ) ) {
			add_filter( 'get_pages', array( $this, 'remove_people' ) );
		}

		return $pre_render;
	}

	public function render_block( $content, $block ) {
		if ( $this->applies_to( $block ) ) {
			remove_filter( 'get_pages', array( $this, 'remove_people' ) );
		}

		return $content;
	}

	private function applies_to( $block ) {
		return isset( $block['blockName'] )
			&& 'core/page-list' === $block['blockName']
			&& Settings::hide_people_from_page_lists();
	}

	public function remove_people( $pages ) {
		if ( empty( $pages ) || ! is_array( $pages ) ) {
			return $pages;
		}

		$people = self::person_page_ids();
		if ( empty( $people ) ) {
			return $pages;
		}

		return array_values(
			array_filter(
				$pages,
				function ( $page ) use ( $people ) {
					$page_id = is_object( $page ) ? (int) $page->ID : (int) $page;

					return ! isset( $people[ $page_id ] );
				}
			)
		);
	}

	/**
	 * Every page that holds family data, as an id keyed lookup.
	 */
	public static function person_page_ids() {
		static $ids;

		if ( isset( $ids ) ) {
			return $ids;
		}

		$cache_key = self::get_cache_key();
		$cached    = wp_cache_get( $cache_key, 'family-wiki' );
		if ( false === $cached ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				wp_cache_set( $cache_key, $cached, 'family-wiki', HOUR_IN_SECONDS );
			}
		}

		if ( is_array( $cached ) ) {
			$ids = $cached;
			return $ids;
		}

		global $wpdb;

		$keys         = self::PERSON_KEYS;
		$placeholders = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );

		// One query rather than a meta lookup per page in the list. The only
		// interpolation is the placeholder list itself, built from the number of
		// keys; the keys go through prepare().
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ( $placeholders ) AND meta_value != ''",
				$keys
			)
		);

		$ids = array_fill_keys( array_map( 'intval', $rows ), true );

		wp_cache_set( $cache_key, $ids, 'family-wiki', HOUR_IN_SECONDS );
		set_transient( $cache_key, $ids, HOUR_IN_SECONDS );

		return $ids;
	}

	public static function flush_cache() {
		$cache_key = self::get_cache_key();
		wp_cache_delete( $cache_key, 'family-wiki' );
		delete_transient( $cache_key );
	}

	private static function get_cache_key() {
		return 'family_wiki_person_page_ids_' . get_current_blog_id();
	}
}
