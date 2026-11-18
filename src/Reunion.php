<?php
/**
 * Family reunions: wiki pages with dates, a place and linked attendees.
 *
 * @package Familypedia
 */

namespace Family_Wiki;

class Reunion {
	const META = '_familypedia_reunion';
	const ACTION = 'familypedia_save_reunion';
	const CREATION_META = '_familypedia_reunion_creation_key';

	public function __construct() {
		add_action( 'wp_loaded', array( $this, 'maybe_save' ) );
	}

	public static function is_reunion( $post ) {
		$post = get_post( $post );
		return $post && Person::POST_TYPE === $post->post_type && (bool) get_post_meta( $post->ID, self::META, true );
	}

	public static function data( $id ) {
		$data = get_post_meta( $id, self::META, true );
		return array_merge(
			array(
				'year'      => '',
				'start'     => '',
				'end'       => '',
				'location'  => '',
				'attendees' => array(),
			),
			is_array( $data ) ? $data : array()
		);
	}

	public static function url() {
		return home_url( '/' . App::URL_PATH . '/reunions/' );
	}

	public static function edit_url( $post = null ) {
		return $post
			? home_url( '/' . App::URL_PATH . '/reunions/' . get_post( $post )->ID . '/edit/' )
			: home_url( '/' . App::URL_PATH . '/new-reunion/' );
	}

	/** A missing edit target must never become a new-entry form. */
	public static function edit_post( $id, $path ) {
		if ( '' === $id && '' === $path ) {
			return null;
		}
		$post = null;
		if ( '' !== $id && is_scalar( $id ) && preg_match( '/^[1-9][0-9]*$/', (string) $id ) ) {
			$post = get_post( (int) $id );
		} elseif ( '' === $id && is_string( $path ) ) {
			$post = Person::get_by_path( $path );
		}
		if ( ! $post || ! self::is_reunion( $post ) || ! in_array( $post->post_status, array( 'publish', 'private', 'draft', 'pending', 'future' ), true ) ) {
			return new \WP_Error( 'reunion_not_found', __( 'That reunion or visit could not be found.', 'family-wiki' ) );
		}
		return $post;
	}

	/** All published reunions, newest year first. */
	public static function get_all() {
		$posts = get_posts(
			array(
				'post_type'   => Person::POST_TYPE,
				'post_status' => 'publish',
				'numberposts' => -1,
				'meta_key'    => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Small, explicit reunion index.
				'orderby'     => 'title',
				'order'       => 'ASC',
			)
		);
		usort(
			$posts,
			function ( $a, $b ) {
				return strcmp( self::data( $b->ID )['year'], self::data( $a->ID )['year'] );
			}
		);
		return $posts;
	}

	public static function people() {
		return array_values(
			array_filter(
				Person::get_all(),
				function ( $person ) {
					return ! Wiki_Page::is_page( $person );
				}
			)
		);
	}

	/** Validate before writing either the post or its metadata. */
	public static function validate( $input ) {
		$data = array();
		foreach ( array( 'year', 'start', 'end', 'location' ) as $key ) {
			if ( isset( $input[ $key ] ) && ! is_scalar( $input[ $key ] ) ) {
				return new \WP_Error( 'invalid_reunion', __( 'Please check the reunion or visit details.', 'family-wiki' ) );
			}
			$data[ $key ] = sanitize_text_field( $input[ $key ] ?? '' );
		}
		if ( '' !== $data['year'] && ! preg_match( '/^[1-9][0-9]{3}$/', $data['year'] ) ) {
			return new \WP_Error( 'invalid_year', __( 'Enter a four-digit year, or leave it blank.', 'family-wiki' ) );
		}
		foreach ( array( 'start', 'end' ) as $key ) {
			$date = $data[ $key ];
			if ( '' === $date ) {
				continue;
			}
			if ( ! preg_match( '/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/', $date, $parts ) || ! checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ) {
				return new \WP_Error( 'invalid_date', __( 'Enter a valid date, or leave it blank when unknown.', 'family-wiki' ) );
			}
		}
		if ( $data['end'] && ( ! $data['start'] || $data['end'] < $data['start'] ) ) {
			return new \WP_Error( 'invalid_range', __( 'An end date needs a start date and must not be earlier.', 'family-wiki' ) );
		}
		if ( $data['start'] ) {
			$year = substr( $data['start'], 0, 4 );
			if ( $data['year'] && $data['year'] !== $year ) {
				return new \WP_Error( 'year_mismatch', __( 'The year must match the start date.', 'family-wiki' ) );
			}
			$data['year'] = $year;
		}
		$data['attendees'] = array();
		if ( isset( $input['attendees'] ) && ! is_array( $input['attendees'] ) ) {
			return new \WP_Error( 'invalid_attendees', __( 'Choose attendees from the people in this wiki.', 'family-wiki' ) );
		}
		foreach ( $input['attendees'] ?? array() as $id ) {
			if ( ! is_scalar( $id ) || ! ctype_digit( (string) $id ) ) {
				return new \WP_Error( 'invalid_attendee', __( 'Choose attendees from the people in this wiki.', 'family-wiki' ) );
			}
			$post = get_post( (int) $id );
			if ( ! $post || Person::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status || Wiki_Page::is_page( $post ) ) {
				return new \WP_Error( 'invalid_attendee', __( 'Choose attendees from the people in this wiki.', 'family-wiki' ) );
			}
			$data['attendees'][] = (int) $id;
		}
		$data['attendees'] = array_values( array_unique( $data['attendees'] ) );
		return $data;
	}

	public function maybe_save() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified below before any write.
		$action = isset( $_POST['familypedia_action'] ) && is_string( $_POST['familypedia_action'] ) ? sanitize_text_field( wp_unslash( $_POST['familypedia_action'] ) ) : '';
		if ( self::ACTION !== $action ) {
			return;
		}
		$id = isset( $_POST['reunion_id'] ) ? absint( $_POST['reunion_id'] ) : 0;
		if ( $id ? ( ! self::is_reunion( $id ) || ! current_user_can( 'edit_post', $id ) ) : ! Wiki_Page::can_create() ) {
			wp_die( esc_html__( 'You do not have permission to edit this reunion or visit.', 'family-wiki' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION . '_' . $id );
		$creation_key = '';
		if ( ! $id ) {
			$creation_key = isset( $_POST['creation_key'] ) && is_string( $_POST['creation_key'] ) ? sanitize_text_field( wp_unslash( $_POST['creation_key'] ) ) : '';
			if ( ! wp_is_uuid( $creation_key ) ) {
				wp_die( esc_html__( 'Please reopen the Add form and try again.', 'family-wiki' ), '', array( 'response' => 400 ) );
			}
			$existing = get_posts(
				array(
					'post_type'   => Person::POST_TYPE,
					'post_status' => 'any',
					'numberposts' => 1,
					'meta_key'    => self::CREATION_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Exact creation-token lookup prevents resubmission duplicates.
					'meta_value'  => $creation_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Exact creation-token lookup.
				)
			);
			if ( $existing ) {
				if ( ! self::is_reunion( $existing[0] ) || ! current_user_can( 'edit_post', $existing[0]->ID ) ) {
					wp_die( esc_html__( 'You do not have permission to edit this reunion or visit.', 'family-wiki' ), '', array( 'response' => 403 ) );
				}
				wp_safe_redirect( Person::url( $existing[0] ) );
				exit;
			}
		}
		$title = isset( $_POST['post_title'] ) && is_string( $_POST['post_title'] ) ? sanitize_text_field( wp_unslash( $_POST['post_title'] ) ) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each field is validated and sanitized by validate().
		$data = self::validate( wp_unslash( $_POST ) );
		if ( '' === $title || is_wp_error( $data ) ) {
			wp_die(
				esc_html( is_wp_error( $data ) ? $data->get_error_message() : __( 'A reunion or visit needs a title.', 'family-wiki' ) ),
				'',
				array(
					'response'  => 400,
					'back_link' => true,
				)
			);
		}
		$post = array(
			'post_title' => $title,
			'post_type'  => Person::POST_TYPE,
		);
		if ( $id ) {
			$post['ID'] = $id;
			$result     = wp_update_post( wp_slash( $post ), true );
		} else {
			$post['post_status'] = 'publish';
			$post['meta_input']  = array( self::CREATION_META => $creation_key );
			$result              = wp_insert_post( wp_slash( $post ), true );
		}
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 500 ) );
		}
		update_post_meta( $result, Wiki_Page::META_KEY, 1 );
		update_post_meta( $result, self::META, $data );
		Main::flush_family_data_cache( $result );
		wp_safe_redirect( Person::url( get_post( $result ) ) );
		exit;
	}
}
