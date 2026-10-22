<?php
/**
 * Reviewing unfinished people and reconciling their explicit stub flags.
 *
 * @package Familypedia
 */
namespace Family_Wiki;

class Stubs {
	const ACTION = 'familypedia_manage_stubs';

	public function __construct() {
		add_action( 'wp_loaded', array( $this, 'maybe_save' ) );
	}

	public static function url() {
		return home_url( '/' . App::URL_PATH . '/stubs/' );
	}

	/** Published people the current editor can manage, excluding content pages. */
	public static function people( $search = '', $only_stubs = true ) {
		$args = array( 's' => $search );
		if ( $only_stubs ) {
			$args['meta_key'] = 'is_stub';
			$args['meta_value'] = '1';
		}
		return array_values( array_filter( Person::get_all( $args ), function ( $person ) use ( $only_stubs ) {
			return 'publish' === $person->post_status && ! Wiki_Page::is_page( $person ) && Editor::can_edit( $person->ID ) && ( ! $only_stubs || Person::field( 'is_stub', $person->ID ) );
		} ) );
	}

	/** Personal detail is separate from the sex and relationship scaffolding. */
	public static function detail( $person ) {
		$person = get_post( $person );
		$facts = array();
		$labels = array(
			'birth_date' => __( 'Birth date', 'family-wiki' ),
			'birth_place' => __( 'Birth place', 'family-wiki' ),
			'death_date' => __( 'Death date', 'family-wiki' ),
			'death_place' => __( 'Death place', 'family-wiki' ),
			'born_as' => __( 'Birth name', 'family-wiki' ),
			'citizenships' => __( 'Citizenship', 'family-wiki' ),
		);
		foreach ( $labels as $field => $label ) {
			$value = trim( (string) Person::field( $field, $person->ID ) );
			if ( '' !== $value ) {
				if ( in_array( $field, array( 'birth_date', 'death_date' ), true ) && Person::field( 'exact_' . $field . '_unknown', $person->ID ) ) {
					$value = substr( $value, 0, 4 );
				}
				$facts[ $field ] = array( 'label' => $label, 'value' => $value );
			}
		}
		if ( has_post_thumbnail( $person->ID ) ) {
			$facts['photo'] = array( 'label' => __( 'Photo', 'family-wiki' ), 'value' => __( 'Attached to the person’s page', 'family-wiki' ) );
		}
		// Strip block markup and shortcodes rather than counting generated biographies.
		$text = trim( html_entity_decode( wp_strip_all_tags( strip_shortcodes( self::without_placeholder( $person->post_content ) ) ), ENT_QUOTES, 'UTF-8' ) );
		preg_match_all( '/[\p{L}\p{N}]+(?:[’\x{0027}-][\p{L}\p{N}]+)*/u', $text, $words );
		return array( 'facts' => count( $facts ), 'fact_details' => $facts, 'words' => count( $words[0] ), 'biography' => $text, 'sparse' => count( $facts ) < 2 && count( $words[0] ) < 40 );
	}

	/** Exclude the imported handwritten-tree stub note, retaining actual family notes. */
	public static function without_placeholder( $content ) {
		$placeholder = 'This page is a stub, created from the handwritten family tree. Stories and further details can be added later.';
		if ( false === strpos( $content, $placeholder ) ) {
			return $content;
		}
		foreach ( array( $placeholder, 'Source: the original Family Wiki homepage.' ) as $text ) {
			$pattern = '~(?:<!--\s*wp:paragraph\b[^>]*-->\s*)?<p\b[^>]*>\s*' . preg_quote( $text, '~' ) . '\s*</p>(?:\s*<!--\s*/wp:paragraph\s*-->)?~u';
			$content = preg_replace( $pattern, '', $content );
			$content = str_replace( $text, '', $content );
		}
		return trim( $content );
	}

	/** Direct family references, using the tree's reciprocal relationship index. */
	public static function relatives( $id ) {
		$people = Tree::get_people();
		$person = $people[ $id ] ?? array();
		$groups = array( 'parents' => array(), 'spouses' => array(), 'siblings' => array(), 'children' => array() );
		$add = function ( $group, $relative_id, $name = '' ) use ( &$groups, $id ) {
			if ( $relative_id ) {
				$relative = Person::to_post( $relative_id );
				if ( ! $relative || (int) $relative_id === (int) $id || 'publish' !== $relative->post_status || Wiki_Page::is_page( $relative ) ) {
					return;
				}
				$groups[ $group ][ 'id:' . $relative_id ] = array( 'id' => $relative_id, 'name' => get_the_title( $relative ) );
			} elseif ( trim( $name ) ) {
				$groups[ $group ][ 'name:' . $name ] = array( 'id' => 0, 'name' => $name );
			}
		};
		foreach ( Person::to_ids( Person::field( 'siblings', $id ) ) as $sibling ) {
			$add( 'siblings', $sibling );
		}
		foreach ( $person['parents'] ?? array() as $parent ) {
			$add( 'parents', $parent );
			foreach ( $people[ $parent ]['children'] ?? array() as $sibling ) {
				$add( 'siblings', $sibling );
			}
		}
		foreach ( array( 'father', 'mother' ) as $field ) {
			if ( ! Person::field( $field, $id ) ) {
				$add( 'parents', 0, (string) Person::field( $field . '_name', $id ) );
			}
		}
		foreach ( $person['partners'] ?? array() as $partner ) {
			// Co-parents without a recorded marriage are not labelled as spouses.
			if ( ! empty( $partner['wed'] ) ) {
				$add( 'spouses', $partner['id'], $partner['name'] );
			}
		}
		foreach ( $person['children'] ?? array() as $child ) {
			$add( 'children', $child );
		}
		return array_filter( $groups );
	}

	/** Review only disagreements; this never writes or redefines existing flags. */
	public static function suggestions( $search = '' ) {
		return array_values( array_filter( self::people( $search, false ), function ( $person ) {
			return self::detail( $person )['sparse'] !== (bool) Person::field( 'is_stub', $person->ID );
		} ) );
	}

	/** Validate the whole selection before changing flags, leaving all other data intact. */
	public static function apply( $ids, $review = false ) {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', array_filter( $ids, 'is_scalar' ) ) ) ) );
		if ( ! $ids ) {
			return new \WP_Error( 'empty', __( 'Select at least one person.', 'family-wiki' ) );
		}
		foreach ( $ids as $id ) {
			$person = Person::to_post( $id );
			if ( ! $person || 'publish' !== $person->post_status || Wiki_Page::is_page( $person ) || ! Editor::can_edit( $id ) ) {
				return new \WP_Error( 'permission', __( 'One of these people cannot be managed. No flags were changed.', 'family-wiki' ) );
			}
		}
		$changed = 0;
		foreach ( $ids as $id ) {
			$stub = $review ? self::detail( $id )['sparse'] : false;
			if ( $stub !== (bool) Person::field( 'is_stub', $id ) ) {
				Person::update( 'is_stub', $stub, $id );
				++$changed;
			}
		}
		return $changed;
	}

	public function maybe_save() {
		if ( ! isset( $_POST['familypedia_action'] ) || self::ACTION !== $_POST['familypedia_action'] ) {
			return;
		}
		check_admin_referer( self::ACTION );
		if ( ! Editor::can_edit() ) {
			wp_die( esc_html__( 'You cannot manage stubs.', 'family-wiki' ), 403 );
		}
		$review = isset( $_POST['review'] ) && '1' === $_POST['review'];
		$ids = array();
		if ( isset( $_POST['complete_person'] ) && is_scalar( $_POST['complete_person'] ) ) {
			$ids = array( absint( $_POST['complete_person'] ) );
			$review = false;
		} elseif ( isset( $_POST['people'] ) && is_array( $_POST['people'] ) ) {
			$ids = array_map( 'absint', array_filter( $_POST['people'], 'is_scalar' ) );
		}
		$result = self::apply( $ids, $review );
		Editor::set_notice( is_wp_error( $result ) ? $result->get_error_message() : sprintf(
			// translators: %d is a number of people whose stub flags changed.
			_n( 'Updated %d person.', 'Updated %d people.', $result, 'family-wiki' ), $result
		), is_wp_error( $result ) ? 'error' : 'success' );
		$args = array( 'review' => $review ? '1' : false );
		if ( isset( $_POST['q'] ) && is_string( $_POST['q'] ) ) {
			$args['q'] = sanitize_text_field( wp_unslash( $_POST['q'] ) );
		}
		wp_safe_redirect( add_query_arg( $args, self::url() ) );
		exit;
	}
}
