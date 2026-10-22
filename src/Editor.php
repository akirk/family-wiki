<?php
/**
 * Editing a person's facts and relationships inside the app.
 *
 * The wiki text itself is written in the block editor in wp-admin. Everything
 * that is a fact rather than prose — dates, places, parents, children,
 * marriages — is edited here, next to the page it describes.
 *
 * People are referred to by name rather than picked from a list of every person
 * on the wiki: a name is what an editor knows, a select box with ten thousand
 * options is not. A name that matches nobody is kept as a plain name, which is
 * what the father_name, mother_name and spouse_name fields are for, so a person
 * can be recorded before their page exists.
 *
 * @package Familypedia
 */

namespace Family_Wiki;

class Editor {
	const ACTION = 'familypedia_save_person';
	const NOTICE_TRANSIENT = 'familypedia_notice_';

	public function __construct() {
		add_action( 'wp_loaded', array( $this, 'maybe_save' ) );
	}

	public static function can_edit( $post_id = 0 ) {
		if ( $post_id ) {
			return current_user_can( 'edit_post', $post_id );
		}

		return current_user_can( 'edit_pages' );
	}

	public static function can_create() {
		return current_user_can( 'publish_pages' );
	}

	/**
	 * Handle the edit form before any template output, so that saving can
	 * redirect back to the person.
	 */
	public function maybe_save() {
		if ( empty( $_POST['familypedia_action'] ) || self::ACTION !== $_POST['familypedia_action'] ) {
			return;
		}

		$post_id = isset( $_POST['person_id'] ) ? absint( $_POST['person_id'] ) : 0;

		check_admin_referer( self::ACTION . '_' . $post_id );

		if ( $post_id ) {
			if ( ! self::can_edit( $post_id ) ) {
				wp_die( esc_html__( 'Sorry, you are not allowed to edit this person.', 'family-wiki' ), 403 );
			}
		} elseif ( ! self::can_create() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to add people.', 'family-wiki' ), 403 );
		}

		$title = isset( $_POST['post_title'] ) ? sanitize_text_field( wp_unslash( $_POST['post_title'] ) ) : '';
		if ( '' === $title ) {
			self::set_notice( __( 'A person needs a name.', 'family-wiki' ), 'error' );
			wp_safe_redirect( $post_id ? Person::edit_url( $post_id ) : home_url( '/' . App::URL_PATH . '/new/' ) );
			exit;
		}

		$data = array(
			'post_type'   => Person::POST_TYPE,
			'post_status' => 'publish',
			'post_title'  => $title,
		);

		if ( $post_id ) {
			$data['ID'] = $post_id;
			$result     = wp_update_post( wp_slash( $data ), true );
		} else {
			$result = wp_insert_post( wp_slash( $data ), true );
		}

		if ( is_wp_error( $result ) ) {
			self::set_notice( $result->get_error_message(), 'error' );
			wp_safe_redirect( $post_id ? Person::edit_url( $post_id ) : home_url( '/' . App::URL_PATH . '/new/' ) );
			exit;
		}

		$post_id  = (int) $result;
		$unmatched = self::save_fields( $post_id );

		Main::flush_family_data_cache( $post_id );

		if ( $unmatched ) {
			self::set_notice(
				sprintf(
					// translators: %s is a comma-separated list of names.
					__( 'Saved. These names did not match a person and were kept as plain text: %s', 'family-wiki' ),
					implode( ', ', $unmatched )
				),
				'warning'
			);
		} else {
			self::set_notice( __( 'Saved.', 'family-wiki' ), 'success' );
		}

		wp_safe_redirect( Person::url( $post_id ) );
		exit;
	}

	/**
	 * Write every field of the form onto the person.
	 *
	 * @return string[] Names that matched nobody and could not be stored as a link.
	 */
	private static function save_fields( $post_id ) {
		$unmatched = array();

		foreach ( array( 'born_as', 'birth_place', 'death_place', 'marriage_place' ) as $field ) {
			Person::update( $field, self::post_text( $field ), $post_id );
		}

		Person::update( 'sex', self::post_text( 'sex' ), $post_id );
		Person::update( 'citizenships', isset( $_POST['citizenships'] ) ? sanitize_textarea_field( wp_unslash( $_POST['citizenships'] ) ) : '', $post_id ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		Person::update( 'alive', ! empty( $_POST['alive'] ), $post_id ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		foreach ( array( 'birth', 'death' ) as $event ) {
			Person::update( $event . '_date', self::post_text( $event . '_date' ), $post_id );
			Person::update( 'exact_' . $event . '_date_unknown', ! empty( $_POST[ 'exact_' . $event . '_date_unknown' ] ), $post_id ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		Person::update( 'marriage_date', self::post_text( 'marriage_date' ), $post_id );

		// A parent is either a person on the wiki or, failing that, a name.
		foreach ( array( 'father', 'mother' ) as $parent ) {
			$entered = self::post_text( $parent );
			$related = self::resolve_person( $entered, $post_id );

			Person::update( $parent, $related, $post_id );
			Person::update( $parent . '_name', $related ? '' : $entered, $post_id );
		}

		$children = array();
		foreach ( self::post_list( 'children' ) as $entered ) {
			$child = self::resolve_person( $entered, $post_id );
			if ( $child ) {
				$children[] = $child;
			} else {
				$unmatched[] = $entered;
			}
		}
		Person::update( 'children', $children, $post_id );

		$marriages = array();
		foreach ( self::post_marriages() as $row ) {
			$spouse = self::resolve_person( $row['spouse'], $post_id );

			$marriages[] = array(
				'spouse'         => $spouse,
				'spouse_name'    => $spouse ? '' : $row['spouse'],
				'marriage_date'  => $row['marriage_date'],
				'marriage_year'  => $row['marriage_year'],
				'marriage_place' => $row['marriage_place'],
				'ended_date'     => $row['ended_date'],
				'ended_year'     => $row['ended_year'],
				'ended_reason'   => $row['ended_reason'],
			);
		}
		Person::update( 'marriages', $marriages, $post_id );

		return array_values( array_unique( $unmatched ) );
	}

	private static function post_text( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
	}

	private static function post_list( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( empty( $_POST[ $key ] ) || ! is_array( $_POST[ $key ] ) ) {
			return array();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$values = array_map( 'sanitize_text_field', wp_unslash( $_POST[ $key ] ) );

		return array_values( array_filter( array_map( 'trim', $values ) ) );
	}

	private static function post_marriages() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( empty( $_POST['marriages'] ) || ! is_array( $_POST['marriages'] ) ) {
			return array();
		}

		$rows = array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The nonce was verified by maybe_save(); every field of every row is run through sanitize_text_field() below.
		foreach ( wp_unslash( $_POST['marriages'] ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$row = array(
				'spouse'         => isset( $row['spouse'] ) ? sanitize_text_field( $row['spouse'] ) : '',
				'marriage_date'  => isset( $row['marriage_date'] ) ? sanitize_text_field( $row['marriage_date'] ) : '',
				'marriage_year'  => isset( $row['marriage_year'] ) ? sanitize_text_field( $row['marriage_year'] ) : '',
				'marriage_place' => isset( $row['marriage_place'] ) ? sanitize_text_field( $row['marriage_place'] ) : '',
				'ended_date'     => isset( $row['ended_date'] ) ? sanitize_text_field( $row['ended_date'] ) : '',
				'ended_year'     => isset( $row['ended_year'] ) ? sanitize_text_field( $row['ended_year'] ) : '',
				'ended_reason'   => isset( $row['ended_reason'] ) ? sanitize_text_field( $row['ended_reason'] ) : '',
			);

			if ( array_filter( $row ) ) {
				$rows[] = $row;
			}
		}

		return $rows;
	}

	/**
	 * The person an entered name refers to, or 0 when it matches nobody.
	 *
	 * Accepts the plain name, the disambiguated "Name (1900)" the picker offers
	 * when a name is shared, and a slug.
	 *
	 * @param string $entered  What the editor typed.
	 * @param int    $exclude  A person who may not be their own relative.
	 */
	public static function resolve_person( $entered, $exclude = 0 ) {
		$entered = trim( (string) $entered );
		if ( '' === $entered ) {
			return 0;
		}

		$index = self::name_index();
		$key   = self::name_key( $entered );

		if ( isset( $index[ $key ] ) && (int) $index[ $key ] !== (int) $exclude ) {
			return (int) $index[ $key ];
		}

		$paths = Links::path_index();
		$slug  = sanitize_title_with_dashes( $entered );
		if ( isset( $paths[ $slug ] ) && (int) $paths[ $slug ] !== (int) $exclude ) {
			return (int) $paths[ $slug ];
		}

		return 0;
	}

	private static function name_key( $name ) {
		return strtolower( trim( preg_replace( '/\s+/', ' ', $name ) ) );
	}

	/**
	 * Every person by the label the picker offers for them: their name, or
	 * their name and birth year where the name is shared.
	 *
	 * @return array Label => post ID.
	 */
	public static function name_index() {
		static $index;

		if ( isset( $index ) ) {
			return $index;
		}

		$by_name = array();
		foreach ( Person::get_all() as $person ) {
			$by_name[ self::name_key( get_the_title( $person ) ) ][] = $person;
		}

		$index = array();
		foreach ( $by_name as $key => $people ) {
			if ( 1 === count( $people ) ) {
				$index[ $key ] = (int) $people[0]->ID;
				continue;
			}

			// A shared name on its own cannot say who is meant, so each of them
			// is offered with their birth year instead.
			foreach ( $people as $person ) {
				$year  = substr( (string) Person::field( 'birth_date', $person->ID ), 0, 4 );
				$label = $year ? get_the_title( $person ) . ' (' . $year . ')' : get_the_title( $person ) . ' (#' . $person->ID . ')';

				$index[ self::name_key( $label ) ] = (int) $person->ID;
			}
		}

		return $index;
	}

	/**
	 * The labels for the person picker's datalist, in name order.
	 *
	 * @return string[]
	 */
	public static function person_labels() {
		$labels = array();
		foreach ( self::name_index() as $key => $post_id ) {
			$labels[ $key ] = self::label_for( $post_id, $key );
		}

		natcasesort( $labels );

		return array_values( $labels );
	}

	/**
	 * The label the picker uses for a person, matching name_index().
	 */
	public static function label_for( $post_id, $key = '' ) {
		$post_id = (int) $post_id;
		if ( ! $post_id ) {
			return '';
		}

		if ( ! $key ) {
			foreach ( self::name_index() as $index_key => $indexed_id ) {
				if ( $indexed_id === $post_id ) {
					$key = $index_key;
					break;
				}
			}
		}

		$title = get_the_title( $post_id );
		if ( $key && self::name_key( $title ) !== $key ) {
			// The person is one of several with this name, so keep the suffix
			// the index disambiguated them with.
			$year = substr( (string) Person::field( 'birth_date', $post_id ), 0, 4 );

			return $year ? $title . ' (' . $year . ')' : $title . ' (#' . $post_id . ')';
		}

		return $title;
	}

	public static function set_notice( $message, $type = 'success' ) {
		set_transient(
			self::NOTICE_TRANSIENT . get_current_user_id(),
			array(
				'message' => $message,
				'type'    => $type,
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Read and clear the notice left by the last save.
	 */
	public static function take_notice() {
		$key    = self::NOTICE_TRANSIENT . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) ) {
			return null;
		}

		delete_transient( $key );

		return $notice;
	}
}
