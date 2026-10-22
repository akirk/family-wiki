<?php
/**
 * The one-line biography generated from a person's recorded facts.
 *
 * "[name_with_bio]" at the top of a person's text expands to their name
 * followed by their dates and their closest relatives. When the page also shows
 * an infobox the same facts are already on screen, so the shortcode collapses
 * to the name alone.
 *
 * @package Familypedia
 */

namespace Family_Wiki;

class Bio {
	public function __construct() {
		add_shortcode( 'name_with_bio', array( $this, 'short_bio' ) );
		add_shortcode( 'born', array( $this, 'born' ) );
		add_shortcode( 'died', array( $this, 'died' ) );

		// Other plugins can add their own shortcodes by hooking into this.
		do_action( 'family_wiki_shortcodes' );
	}

	/**
	 * Replace the bio shortcode with the person's name, for pages that show an
	 * infobox with the same facts.
	 */
	public static function replace_shortcode_with_title( $content, $post_id ) {
		$title = '<strong>' . esc_html( get_the_title( $post_id ) ) . '</strong>';

		return preg_replace( '/\[name_with_bio[^\]]*\]\s*/i', $title . ' ', $content );
	}

	public function short_bio( $atts, $content = '' ) {
		unset( $atts, $content );

		return self::render( get_the_ID() );
	}

	public static function render( $post_id ) {
		if ( ! $post_id ) {
			return '';
		}

		$return = '<strong>' . esc_html( get_the_title( $post_id ) ) . '</strong>';
		$bio    = implode(
			'; ',
			array_filter(
				array(
					self::dates( $post_id ),
					self::parents( $post_id ),
					self::siblings( $post_id ),
					self::children( $post_id ),
				)
			)
		);

		if ( $bio ) {
			$return .= ' (' . $bio . ')';
		}

		return $return;
	}

	private static function date_link( \DateTime $date ) {
		return Infobox::linked_date( $date );
	}

	/**
	 * Pick the wording that matches the person's recorded sex.
	 *
	 * @param int   $post_id The person.
	 * @param array $forms   Male, female and neutral forms of the sentence.
	 * @param array $args    sprintf() arguments for the chosen form.
	 */
	private static function gendered( $post_id, $forms, $args ) {
		$sex = Person::field( 'sex', $post_id );
		if ( 'Male' === $sex ) {
			$format = $forms[0];
		} elseif ( 'Female' === $sex ) {
			$format = $forms[1];
		} else {
			$format = $forms[2];
		}

		return vsprintf( $format, $args );
	}

	public static function children( $post_id ) {
		$children = array();
		foreach ( Person::field( 'children', $post_id ) as $child ) {
			$children[ $child->ID ] = Relationships::person_link( $child );
		}

		if ( empty( $children ) ) {
			return '';
		}

		if ( 2 === count( $children ) ) {
			return self::gendered(
				$post_id,
				array(
					// translators: %1$s is a first child's name, %2$s is a second child's name.
					__( 'father of %1$s and %2$s', 'family-wiki' ),
					// translators: %1$s is a first child's name, %2$s is a second child's name.
					__( 'mother of %1$s and %2$s', 'family-wiki' ),
					// translators: %1$s is a first child's name, %2$s is a second child's name.
					__( 'parent of %1$s and %2$s', 'family-wiki' ),
				),
				array_values( $children )
			);
		}

		$last_child = array_pop( $children );

		if ( $children ) {
			return self::gendered(
				$post_id,
				array(
					// translators: %1$s is a list of children, %2$s is a child's name.
					__( 'father of %1$s, and %2$s', 'family-wiki' ),
					// translators: %1$s is a list of children, %2$s is a child's name.
					__( 'mother of %1$s, and %2$s', 'family-wiki' ),
					// translators: %1$s is a list of children, %2$s is a child's name.
					__( 'parent of %1$s, and %2$s', 'family-wiki' ),
				),
				array( implode( ', ', $children ), $last_child )
			);
		}

		return self::gendered(
			$post_id,
			array(
				// translators: %s is a child's name.
				__( 'father of %s', 'family-wiki' ),
				// translators: %s is a child's name.
				__( 'mother of %s', 'family-wiki' ),
				// translators: %s is a child's name.
				__( 'parent of %s', 'family-wiki' ),
			),
			array( $last_child )
		);
	}

	public static function siblings( $post_id ) {
		$return = array();

		$siblings = Relationships::siblings( $post_id, false );
		if ( $siblings ) {
			$return[] = self::gendered(
				$post_id,
				array(
					// translators: %s is a list of siblings.
					__( 'brother of %s', 'family-wiki' ),
					// translators: %s is a list of siblings.
					__( 'sister of %s', 'family-wiki' ),
					// translators: %s is a list of siblings.
					__( 'sibling of %s', 'family-wiki' ),
				),
				array( implode( ', ', array_map( array( Relationships::class, 'person_link' ), $siblings ) ) )
			);
		}

		$half_siblings = Relationships::siblings( $post_id, true );
		if ( $half_siblings ) {
			$return[] = self::gendered(
				$post_id,
				array(
					// translators: %s is a list of half-siblings.
					__( 'half-brother of %s', 'family-wiki' ),
					// translators: %s is a list of half-siblings.
					__( 'half-sister of %s', 'family-wiki' ),
					// translators: %s is a list of half-siblings.
					__( 'half-sibling of %s', 'family-wiki' ),
				),
				array( implode( ', ', array_map( array( Relationships::class, 'person_link' ), $half_siblings ) ) )
			);
		}

		return implode( ', ', $return );
	}

	public static function parents( $post_id ) {
		$father = '?';
		if ( Person::field( 'father', $post_id ) ) {
			$father = Relationships::person_link( Person::field( 'father', $post_id ) );
		} elseif ( Person::field( 'father_name', $post_id ) ) {
			$father = Links::name_link( Person::field( 'father_name', $post_id ) );
		}

		$mother = '?';
		if ( Person::field( 'mother', $post_id ) ) {
			$mother = Relationships::person_link( Person::field( 'mother', $post_id ) );
		} elseif ( Person::field( 'mother_name', $post_id ) ) {
			$mother = Links::name_link( Person::field( 'mother_name', $post_id ) );
		}

		if ( '?' === $mother && '?' === $father ) {
			return '';
		}

		return self::gendered(
			$post_id,
			array(
				// translators: %1$s is a mother's name, %2$s is a father's name.
				__( 'son of %1$s and %2$s', 'family-wiki' ),
				// translators: %1$s is a mother's name, %2$s is a father's name.
				__( 'daughter of %1$s and %2$s', 'family-wiki' ),
				// translators: %1$s is a mother's name, %2$s is a father's name.
				__( 'child of %1$s and %2$s', 'family-wiki' ),
			),
			array( $mother, $father )
		);
	}

	public static function dates( $post_id ) {
		$birth = self::to_date( Person::field( 'birth_date', $post_id ) );
		$death = self::to_date( Person::field( 'death_date', $post_id ) );

		if ( Person::field( 'alive', $post_id ) ) {
			return $birth ? self::living_bio( $post_id, $birth ) : '';
		}

		if ( ! $birth && ! $death ) {
			return '';
		}

		if ( ! $birth ) {
			return self::death_only_bio( $post_id, $death );
		}

		$return = self::birth_phrase( $post_id, $birth );
		if ( ! $death ) {
			return $return;
		}

		return $return . ', ' . self::death_phrase( $post_id, $birth, $death );
	}

	private static function to_date( $value ) {
		if ( ! $value ) {
			return null;
		}

		try {
			return new \DateTime( $value );
		} catch ( \Exception $e ) {
			return null;
		}
	}

	private static function living_bio( $post_id, \DateTime $birth ) {
		$approximate = (bool) Person::field( 'exact_birth_date_unknown', $post_id );
		$age         = $birth->diff( new \DateTime( 'now' ) );
		$age_label   = $approximate
			// translators: %d is an approximate age in years.
			? sprintf( _n( 'age: ~%d', 'age: ~%d', $age->y, 'family-wiki' ), $age->y )
			// translators: %d is an age in years.
			: sprintf( _n( 'age: %d', 'age: %d', $age->y, 'family-wiki' ), $age->y );

		$when  = $approximate ? esc_html( $birth->format( 'Y' ) ) : self::date_link( $birth );
		$place = Person::field( 'birth_place', $post_id );
		$as    = Person::field( 'born_as', $post_id );

		return self::assemble_birth( $as, $when, $approximate, $place, $age_label );
	}

	private static function birth_phrase( $post_id, \DateTime $birth ) {
		$approximate = (bool) Person::field( 'exact_birth_date_unknown', $post_id );
		$when        = $approximate ? esc_html( $birth->format( 'Y' ) ) : self::date_link( $birth );

		return self::assemble_birth( Person::field( 'born_as', $post_id ), $when, $approximate, Person::field( 'birth_place', $post_id ), '' );
	}

	/**
	 * "born as X on DATE (age: N) in PLACE", with each part dropped when it is
	 * not known. "on" becomes "in" for a year without a day.
	 */
	private static function assemble_birth( $born_as, $when, $approximate, $place, $age_label ) {
		$return = $approximate
			? sprintf(
				// translators: %s is a birth year.
				__( 'born in %s', 'family-wiki' ),
				$when
			)
			: sprintf(
				// translators: %s is a birth date.
				__( 'born on %s', 'family-wiki' ),
				$when
			);

		if ( $born_as ) {
			$return = $approximate
				? sprintf(
					// translators: %1$s is a maiden name, %2$s is a birth year.
					__( 'born as %1$s in %2$s', 'family-wiki' ),
					'<i>' . esc_html( $born_as ) . '</i>',
					$when
				)
				: sprintf(
					// translators: %1$s is a maiden name, %2$s is a birth date.
					__( 'born as %1$s on %2$s', 'family-wiki' ),
					'<i>' . esc_html( $born_as ) . '</i>',
					$when
				);
		}

		if ( $age_label ) {
			$return .= ' (' . $age_label . ')';
		}

		if ( $place ) {
			$return = sprintf(
				// translators: %1$s is a phrase such as "born on 1 January 1900", %2$s is a place.
				__( '%1$s in %2$s', 'family-wiki' ),
				$return,
				esc_html( $place )
			);
		}

		return $return;
	}

	private static function death_only_bio( $post_id, $death ) {
		if ( ! $death ) {
			return '';
		}

		$approximate = (bool) Person::field( 'exact_death_date_unknown', $post_id );
		$when        = $approximate ? esc_html( $death->format( 'Y' ) ) : self::date_link( $death );

		return self::assemble_death( $when, $approximate, Person::field( 'death_place', $post_id ), '' );
	}

	private static function death_phrase( $post_id, \DateTime $birth, \DateTime $death ) {
		$approximate = Person::field( 'exact_birth_date_unknown', $post_id ) || Person::field( 'exact_death_date_unknown', $post_id );
		$aged        = $birth->diff( $death );
		$aged_label  = $approximate
			// translators: %d is an approximate age in years.
			? sprintf( _n( 'aged: ~%d', 'aged: ~%d', $aged->y, 'family-wiki' ), $aged->y )
			// translators: %d is an age in years.
			: sprintf( _n( 'aged: %d', 'aged: %d', $aged->y, 'family-wiki' ), $aged->y );

		$date_unknown = (bool) Person::field( 'exact_death_date_unknown', $post_id );
		$when         = $date_unknown ? esc_html( $death->format( 'Y' ) ) : self::date_link( $death );

		return self::assemble_death( $when, $date_unknown, Person::field( 'death_place', $post_id ), $aged_label );
	}

	private static function assemble_death( $when, $approximate, $place, $aged_label ) {
		$return = $approximate
			? sprintf(
				// translators: %s is a death year.
				__( 'died in %s', 'family-wiki' ),
				$when
			)
			: sprintf(
				// translators: %s is a death date.
				__( 'died on %s', 'family-wiki' ),
				$when
			);

		if ( $aged_label ) {
			$return .= ' (' . $aged_label . ')';
		}

		if ( $place ) {
			$return = sprintf(
				// translators: %1$s is a phrase such as "died on 1 January 1980", %2$s is a place.
				__( '%1$s in %2$s', 'family-wiki' ),
				$return,
				esc_html( $place )
			);
		}

		return $return;
	}

	public function born( $atts, $content = '' ) {
		if ( empty( $atts['date'] ) ) {
			return $content;
		}

		$birth = self::to_date( $atts['date'] );
		if ( ! $birth ) {
			return esc_html( $atts['date'] );
		}

		$return = self::date_link( $birth );

		if ( isset( $atts['showage'] ) || in_array( 'showage', $atts, true ) ) {
			$age = $birth->diff( new \DateTime( 'now' ) );
			// translators: %d is an age in years.
			$return .= ' (' . sprintf( _n( 'age %d', 'age %d', $age->y, 'family-wiki' ), $age->y ) . ')';
		}

		return $return;
	}

	public function died( $atts, $content = '' ) {
		if ( empty( $atts['date'] ) || empty( $atts['birth'] ) ) {
			return $content;
		}

		foreach ( array( 'date', 'birth' ) as $key ) {
			if ( 4 === strlen( trim( $atts[ $key ] ) ) && is_numeric( $atts[ $key ] ) ) {
				$atts[ $key ] .= '-12-31';
			}
		}

		$death = self::to_date( $atts['date'] );
		$birth = self::to_date( $atts['birth'] );
		if ( ! $death || ! $birth ) {
			return esc_html( $atts['date'] );
		}

		$age = $birth->diff( $death );

		// translators: %d is an age in years.
		return self::date_link( $death ) . ' (' . sprintf( _n( 'aged %d', 'aged %d', $age->y, 'family-wiki' ), $age->y ) . ')';
	}
}
