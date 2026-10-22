<?php
/**
 * A wiki page with no facts of its own — just a title and, like everywhere
 * else in the app, text written in the block editor. It may sit under a
 * person or another such page, or stand on its own with no parent at all.
 *
 * Related_Page narrows this to the case that started it: a chronology, a
 * house, anything hung under a specific person.
 *
 * @package Familypedia
 */

namespace Family_Wiki;

class Wiki_Page {
	const ACTION = 'familypedia_save_page';

	/**
	 * Marks a post as this kind of content-only page. Pages saved before
	 * this existed carry no meta, so is_page() falls back to the signal
	 * Related_Page always used: a parent, and none of a person's facts.
	 */
	const META_KEY = '_familypedia_page';

	public function __construct() {
		add_action( 'wp_loaded', array( $this, 'maybe_save' ) );
	}

	/**
	 * Whether the current user may add a page. A page under an existing
	 * parent takes that parent's own edit capability; a standalone page
	 * takes the same capability adding a person does.
	 */
	public static function can_create( $parent_id = 0 ) {
		if ( $parent_id ) {
			return current_user_can( 'edit_post', $parent_id );
		}

		return current_user_can( 'publish_pages' );
	}

	/**
	 * Handle the form before any template output, so that saving can
	 * redirect back to the page.
	 */
	public function maybe_save() {
		if ( empty( $_POST['familypedia_action'] ) || static::ACTION !== $_POST['familypedia_action'] ) {
			return;
		}

		$post_id   = isset( $_POST['page_id'] ) ? absint( $_POST['page_id'] ) : 0;
		$parent_id = isset( $_POST['parent_id'] ) ? absint( $_POST['parent_id'] ) : 0;

		check_admin_referer( static::ACTION . '_' . $post_id . '_' . $parent_id );

		if ( $parent_id ) {
			$parent = get_post( $parent_id );
			if ( ! $parent || Person::POST_TYPE !== $parent->post_type ) {
				wp_die( esc_html__( 'That page could not be found.', 'family-wiki' ), 404 );
			}
		}

		if ( $post_id ) {
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				wp_die( esc_html__( 'Sorry, you are not allowed to edit this page.', 'family-wiki' ), 403 );
			}
		} elseif ( ! static::can_create( $parent_id ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to add pages here.', 'family-wiki' ), 403 );
		}

		$title = isset( $_POST['post_title'] ) ? sanitize_text_field( wp_unslash( $_POST['post_title'] ) ) : '';
		if ( '' === $title ) {
			Editor::set_notice( __( 'A page needs a title.', 'family-wiki' ), 'error' );
			wp_safe_redirect( $post_id ? Person::edit_url( $post_id ) : static::add_url( $parent_id ) );
			exit;
		}

		$data = array(
			'post_type'   => Person::POST_TYPE,
			'post_status' => 'publish',
			'post_title'  => $title,
			'post_parent' => $parent_id,
		);

		if ( $post_id ) {
			$data['ID'] = $post_id;
			$result     = wp_update_post( wp_slash( $data ), true );
		} else {
			$result = wp_insert_post( wp_slash( $data ), true );
		}

		if ( is_wp_error( $result ) ) {
			Editor::set_notice( $result->get_error_message(), 'error' );
			wp_safe_redirect( $post_id ? Person::edit_url( $post_id ) : static::add_url( $parent_id ) );
			exit;
		}

		$post_id = (int) $result;
		update_post_meta( $post_id, static::META_KEY, 1 );

		Main::flush_family_data_cache( $parent_id ? $parent_id : $post_id );

		$is_new = ! isset( $data['ID'] );

		Editor::set_notice( __( 'Saved.', 'family-wiki' ) );

		// A new page has nothing written yet, so go straight to where that
		// happens; an existing one goes back to its own page, like the
		// facts form does.
		wp_safe_redirect( $is_new ? get_edit_post_link( $post_id, '' ) : Person::url( $post_id ) );
		exit;
	}

	/**
	 * Whether a post is one of these content-only pages rather than a
	 * person in their own right.
	 */
	public static function is_page( $person ) {
		$post = get_post( $person );
		if ( ! $post || Person::POST_TYPE !== $post->post_type ) {
			return false;
		}

		if ( get_post_meta( $post->ID, static::META_KEY, true ) ) {
			return true;
		}

		return (bool) $post->post_parent && ! Person::has_data( $post->ID );
	}

	/**
	 * Where a standalone page, with no parent, is added.
	 */
	public static function add_url( $parent_id = 0 ) {
		unset( $parent_id );

		return home_url( '/' . App::URL_PATH . '/new-page/' );
	}
}
