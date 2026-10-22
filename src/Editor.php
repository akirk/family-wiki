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
		add_action( 'wp_loaded', array( $this, 'maybe_add_relative' ) );
	}

	/** Create a stub or link an existing relative, keeping both sides in sync. */
	public function maybe_add_relative() {
		if ( 'familypedia_add_relative' !== self::post_text( 'familypedia_action' ) ) {
			return;
		}
		$id = absint( self::post_text( 'person_id' ) );
		check_admin_referer( 'familypedia_add_relative_' . $id );
		$person = Person::to_post( $id );
		if ( ! $person || Wiki_Page::is_page( $person ) || ! self::can_edit( $id ) || ! self::can_create() ) {
			wp_die( esc_html__( 'You cannot add relatives to this person.', 'family-wiki' ), 403 );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each row is checked and sanitized below after nonce verification.
		$rows = isset( $_POST['relatives'] ) && is_array( $_POST['relatives'] ) ? wp_unslash( $_POST['relatives'] ) : array();
		$results = array();
		foreach ( $rows as $key => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$name = isset( $row['name'] ) && is_string( $row['name'] ) ? sanitize_text_field( $row['name'] ) : '';
			$relationship = isset( $row['relationship'] ) && is_string( $row['relationship'] ) ? sanitize_text_field( $row['relationship'] ) : '';
			$years = isset( $row['years'] ) && is_string( $row['years'] ) ? sanitize_text_field( $row['years'] ) : '';
			if ( '' === trim( $name ) && '' === trim( $years ) ) {
				continue;
			}
			$result = self::add_relative( $id, $relationship, $name, $years );
			$results[] = array(
				'key' => (string) $key,
				'saved' => ! is_wp_error( $result ),
				'message' => is_wp_error( $result ) ? $result->get_error_message() : $name,
				'url' => is_wp_error( $result ) ? '' : Person::url( $result ),
			);
		}
		if ( '1' === self::post_text( 'familypedia_ajax' ) ) {
			$response = array( 'results' => $results, 'graph' => Family_Graph::render( $id, true ) );
			if ( '1' === self::post_text( 'familypedia_tree' ) ) {
				Tree::reset_expanded();
				$response['tree'] = Tree::render_list( $id );
			}
			wp_send_json_success( $response );
		}
		self::set_notice( __( 'Family entries processed. Review the family links before adding more.', 'family-wiki' ) );
		wp_safe_redirect( Person::url( $id ) );
		exit;
	}

	/** Validate all affected people before creating a relative or changing links. */
	public static function add_relative( $id, $relationship, $name, $years = '' ) {
		$dates = self::parse_relative_years( $years );
		if ( is_wp_error( $dates ) ) {
			return $dates;
		}
		if ( 'child' === $relationship ) {
			$sex = Person::field( 'sex', $id );
			if ( ! in_array( $sex, array( 'Male', 'Female' ), true ) ) {
				return new \WP_Error( 'relative', __( 'Choose whether this person is the child’s father or mother.', 'family-wiki' ) );
			}
			$relationship = 'Female' === $sex ? 'child_mother' : 'child_father';
		}
		$links = array();
		if ( in_array( $relationship, array( 'father', 'mother' ), true ) ) {
			$links[] = array( $id, $relationship, 0 );
		} elseif ( in_array( $relationship, array( 'child_father', 'child_mother' ), true ) ) {
			$links[] = array( 0, substr( $relationship, 6 ), $id );
		} elseif ( 'sibling' === $relationship ) {
			foreach ( Person::SINGLE_PERSON_FIELDS as $field ) {
				$parent = Person::field( $field, $id );
				if ( $parent ) {
					$links[] = array( 0, $field, $parent->ID );
				}
			}
		}
		$name = trim( sanitize_text_field( $name ) );
		if ( ( ! $links && 'spouse' !== $relationship ) || '' === $name ) {
			return new \WP_Error( 'relative', __( 'Enter a name and relationship. To add a sibling, record a shared parent first.', 'family-wiki' ) );
		}
		$relative = self::resolve_person( $name, $id );
		if ( self::name_key( $name ) === self::name_key( get_the_title( $id ) ) || ( $relative && Wiki_Page::is_page( get_post( $relative ) ) ) ) {
			return new \WP_Error( 'relative', __( 'Choose a different person.', 'family-wiki' ) );
		}
		if ( 'spouse' === $relationship && ( ! self::can_edit( $id ) || ( $relative && ! self::can_edit( $relative ) ) ) ) {
			return new \WP_Error( 'relative', __( 'You cannot edit both spouses.', 'family-wiki' ) );
		}
		if ( $relative ) {
			foreach ( $dates as $event => $year ) {
				$existing_date = Person::field( $event . '_date', $relative );
				if ( $existing_date && substr( $existing_date, 0, 4 ) !== $year ) {
					return new \WP_Error( 'years', __( 'These years conflict with recorded dates. Edit the person’s facts to change them.', 'family-wiki' ) );
				}
			}
		}
		foreach ( $links as $link ) {
			list( $child, $field, $parent ) = $link;
			$child = $child ?: $relative;
			$parent = $parent ?: $relative;
			$existing = $child ? Person::field( $field, $child ) : false;
			// Following parent links must never lead back to the proposed child.
			$pending = $parent ? array( $parent ) : array();
			$seen = array();
			while ( $pending ) {
				$ancestor = array_pop( $pending );
				if ( $child && (int) $ancestor === (int) $child ) {
					return new \WP_Error( 'relative', __( 'This relationship would create a loop in the family tree.', 'family-wiki' ) );
				}
				if ( isset( $seen[ $ancestor ] ) ) {
					continue;
				}
				$seen[ $ancestor ] = true;
				foreach ( Person::SINGLE_PERSON_FIELDS as $ancestor_field ) {
					$next = Person::field( $ancestor_field, $ancestor );
					if ( $next ) {
						$pending[] = $next->ID;
					}
				}
			}
			if ( ( $child && ! self::can_edit( $child ) ) || ( $parent && ! self::can_edit( $parent ) ) || ( $child && $child === $parent ) || ( $existing && (int) $existing->ID !== (int) $parent ) ) {
				return new \WP_Error( 'relative', __( 'This relationship conflicts with an existing parent or a person you cannot edit. Review their facts first.', 'family-wiki' ) );
			}
		}
		if ( ! $relative ) {
			$relative = wp_insert_post( wp_slash( array( 'post_type' => Person::POST_TYPE, 'post_status' => 'publish', 'post_title' => $name ) ), true );
			if ( is_wp_error( $relative ) ) {
				return $relative;
			}
			Person::update( 'is_stub', true, $relative );
		}
		foreach ( $links as $link ) {
			list( $child, $field, $parent ) = $link;
			$child = $child ?: $relative;
			$parent = $parent ?: $relative;
			if ( ! in_array( Person::field( 'sex', $parent ), array( 'Male', 'Female' ), true ) ) {
				Person::update( 'sex', 'father' === $field ? 'Male' : 'Female', $parent );
			}
			Person::update( $field, $parent, $child );
			Person::update( $field . '_name', '', $child );
			$children = Person::to_ids( Person::field( 'children', $parent ) );
			$children[] = $child;
			Person::update( 'children', $children, $parent );
			Main::flush_family_data_cache( $child );
			Main::flush_family_data_cache( $parent );
		}
		if ( 'spouse' === $relationship ) {
			self::record_spouse( $id, $relative );
			self::record_spouse( $relative, $id );
			Main::flush_family_data_cache( $id );
		}
		foreach ( $dates as $event => $year ) {
			if ( ! Person::field( $event . '_date', $relative ) ) {
				Person::update( $event . '_date', $year . '-01-01', $relative );
				Person::update( 'exact_' . $event . '_date_unknown', true, $relative );
			}
			if ( 'death' === $event ) {
				Person::update( 'alive', false, $relative );
			}
		}
		Main::flush_family_data_cache( $relative );
		return $relative;
	}

	/** Add a marriage without replacing details or hiding older spouse records. */
	private static function record_spouse( $id, $spouse ) {
		$rows = Person::field( 'marriages', $id );
		$known = array();
		$names = array();
		foreach ( $rows as $row ) {
			if ( ! empty( $row['spouse'] ) ) {
				$known[] = (int) $row['spouse'];
			} elseif ( ! empty( $row['spouse_name'] ) ) {
				$names[] = $row['spouse_name'];
			}
		}
		foreach ( Person::field( 'spouse', $id ) as $legacy ) {
			if ( ! in_array( (int) $legacy->ID, $known, true ) ) {
				$rows[] = array( 'spouse' => $legacy->ID, 'marriage_date' => Person::field( 'marriage_date', $id ), 'marriage_place' => Person::field( 'marriage_place', $id ) );
				$known[] = (int) $legacy->ID;
			}
		}
		$legacy_name = Person::field( 'spouse_name', $id );
		if ( $legacy_name && ! in_array( $legacy_name, $names, true ) ) {
			$rows[] = array( 'spouse_name' => $legacy_name, 'marriage_date' => Person::field( 'marriage_date', $id ), 'marriage_place' => Person::field( 'marriage_place', $id ) );
		}
		if ( ! in_array( (int) $spouse, $known, true ) ) {
			$rows[] = array( 'spouse' => $spouse );
		}
		Person::update( 'marriages', $rows, $id );
	}

	/** Accept a birth year or a birth–death year range without inventing exact dates. */
	public static function parse_relative_years( $years ) {
		$years = trim( $years );
		if ( '' === $years ) {
			return array();
		}
		if ( ! preg_match( '/^([0-9]{4})(?:\s*[-–]\s*([0-9]{4}))?$/u', $years, $matches ) || (int) $matches[1] < 1 || ( isset( $matches[2] ) && (int) $matches[2] < (int) $matches[1] ) ) {
			return new \WP_Error( 'years', __( 'Use a birth year such as 1923 or a lifespan such as 1923-2000.', 'family-wiki' ) );
		}
		$dates = array( 'birth' => $matches[1] );
		if ( isset( $matches[2] ) ) {
			$dates['death'] = $matches[2];
		}
		return $dates;
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
		Person::update( 'is_stub', ! empty( $_POST['is_stub'] ), $post_id ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

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
				'spouse'            => $spouse,
				'relationship_type' => isset( $row['relationship_type'] ) ? $row['relationship_type'] : 'marriage',
				'spouse_name'       => $spouse ? '' : $row['spouse'],
				'marriage_date'     => $row['marriage_date'],
				'marriage_year'     => $row['marriage_year'],
				'marriage_place'    => $row['marriage_place'],
				'ended_date'        => $row['ended_date'],
				'ended_year'        => $row['ended_year'],
				'ended_reason'      => $row['ended_reason'],
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
				'relationship_type' => isset( $row['relationship_type'] ) && 'partnership' === $row['relationship_type'] ? 'partnership' : '',
				'spouse'            => isset( $row['spouse'] ) ? sanitize_text_field( $row['spouse'] ) : '',
				'marriage_date'     => isset( $row['marriage_date'] ) ? sanitize_text_field( $row['marriage_date'] ) : '',
				'marriage_year'     => isset( $row['marriage_year'] ) ? sanitize_text_field( $row['marriage_year'] ) : '',
				'marriage_place'    => isset( $row['marriage_place'] ) ? sanitize_text_field( $row['marriage_place'] ) : '',
				'ended_date'        => isset( $row['ended_date'] ) ? sanitize_text_field( $row['ended_date'] ) : '',
				'ended_year'        => isset( $row['ended_year'] ) ? sanitize_text_field( $row['ended_year'] ) : '',
				'ended_reason'      => isset( $row['ended_reason'] ) ? sanitize_text_field( $row['ended_reason'] ) : '',
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
