<?php
/**
 * A wiki page hung under a specific person: a chronology, a house, anything
 * with no facts of its own. The mechanics — a title, straight to the block
 * editor once saved — are Wiki_Page's; this narrows it to always needing a
 * person as its parent.
 *
 * @package Familypedia
 */

namespace Family_Wiki;

class Related_Page extends Wiki_Page {
	const ACTION = 'familypedia_save_related_page';

	public static function can_create( $parent_id = 0 ) {
		return $parent_id && parent::can_create( $parent_id );
	}

	/**
	 * Whether a post is a page hung under a person, rather than standing on
	 * its own.
	 */
	public static function is_related_page( $person ) {
		$post = get_post( $person );

		return $post && $post->post_parent && self::is_page( $person );
	}

	public static function add_url( $parent_id = 0 ) {
		$post = get_post( $parent_id );
		if ( ! $post ) {
			return home_url( '/' . App::URL_PATH . '/' );
		}

		return home_url( '/' . App::URL_PATH . '/' . Person::path( $post ) . '/add-page/' );
	}
}
