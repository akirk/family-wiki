<?php
namespace Family_Wiki;

class Shortcodes {
	public function __construct() {
		add_shortcode( 'name_with_bio', array( $this, 'short_bio' ) );
		add_shortcode( 'born', array( $this, 'born' ) );
		add_shortcode( 'died', array( $this, 'died' ) );

		// You can add more shortcodes by hooking into this.
		do_action( 'family_wiki_shortcodes' );
	}

	private function get_date( $date ) {
		$return = date_i18n( get_option( 'date_format' ), $date->format( 'U' ) );
		if ( get_option( 'family_wiki_calendar_page' ) ) {
			$return = '<a href="' . get_option( 'family_wiki_calendar_page' ) . '#' . date_i18n( 'F', $date->format( 'U' ) ) . '">' . $return . '</a>';
		}

		return $return;
	}

	public function short_bio( $atts, $content ) {
		$return = '<strong>' . get_the_title() . '</strong>';
		$bio = implode( '; ', array_filter( array( $this->short_date_bio(), $this->short_bio_parents(), $this->short_bio_siblings(), $this->short_bio_children() ) ) );
		if ( $bio ) {
			$return .= ' (' . $bio . ')';
		}

		return $return;
	}

	public function short_bio_children() {
		$children = array();
		foreach ( get_field( 'children' ) as $child ) {
			$children[ $child->ID ] = '<a href="' . get_permalink( $child ) . '">' . get_the_title( $child ) . '</a>';
		}
		if ( empty( $children ) ) {
			return $children;
		}

		if ( 2 === count( $children ) ) {
			return sprintf(
				// translators: %1$s is a first child's name, %2$s is a second child's name.
				__( 'parent of %1$s and %2$s', 'family-wiki' ),
				array_shift( $children ),
				array_shift( $children )
			);
		}

		$last_child = array_pop( $children );
		if ( $children ) {
			return sprintf(
				// translators: %1$s is a list of children, %2$s is a last child's name.
				__( 'parent of %1$s and %2$s', 'family-wiki' ),
				implode( ', ', $children ),
				$last_child
			);
		}
		return sprintf(
			// translators: %s is a child
			__( 'parent of %s', 'family-wiki' ),
			$last_child
		);
	}
	public function short_bio_siblings() {
		$father_children = array();
		$mother_children = array();
		if ( get_field( 'father' ) ) {
			foreach ( get_field( 'children', get_field( 'father' ) ) as $child ) {
				if ( get_the_ID() !== $child->ID ) {
					$father_children[ $child->ID ] = '<a href="' . get_permalink( $child ) . '">' . get_the_title( $child ) . '</a>';
				}
			}
		}

		if ( get_field( 'mother' ) ) {
			foreach ( get_field( 'children', get_field( 'mother' ) ) as $child ) {
				if ( get_the_ID() !== $child->ID ) {
					$mother_children[ $child->ID ] = '<a href="' . get_permalink( $child ) . '">' . get_the_title( $child ) . '</a>';
				}
			}
		}
		$siblings = array();
		$half_siblings = array();

		foreach ( $father_children as $child_id => $child ) {
			if ( isset( $mother_children[ $child_id ] ) ) {
				$siblings[ $child_id ] = $child;
			} else {
				$half_siblings[ $child_id ] = $child;
			}
		}
		foreach ( $mother_children as $child_id => $child ) {
			if ( isset( $father_children[ $child_id ] ) ) {
				$siblings[ $child_id ] = $child;
			} else {
				$half_siblings[ $child_id ] = $child;
			}
		}

		if ( ! get_field( 'father' ) || ! get_field( 'mother' ) ) {
			$siblings = $half_siblings;
			$half_siblings = array();
		}

		$return = array();
		if ( $siblings ) {
			$return[] = sprintf(
				// translators: %1$s is a list of siblings.
				__( 'sibling of %1$s', 'family-wiki' ),
				implode( ', ', $siblings )
			);
		}
		if ( $half_siblings ) {
			$return[] = sprintf(
				// translators: %1$s is a list of half-siblings.
				__( 'half-sibling of %1$s', 'family-wiki' ),
				implode( ', ', $half_siblings )
			);
		}

		return implode( ', ', $return );
	}

	public function short_bio_parents() {
		$father = '?';
		if ( get_field( 'father' ) ) {
			$father = '<a href="' . get_permalink( get_field( 'father' ) ) . '">' . get_the_title( get_field( 'father' ) ) . '</a>';
		} elseif ( get_field( 'father_name' ) ) {
			$father = '<a href="/' . sanitize_title_with_dashes( get_field( 'father_name' ) ) . '">' . get_field( 'father_name' ) . '</a>';
		}

		$mother = '?';
		if ( get_field( 'mother' ) ) {
			$mother = '<a href="' . get_permalink( get_field( 'mother' ) ) . '">' . get_the_title( get_field( 'mother' ) ) . '</a>';
		} elseif ( get_field( 'mother_name' ) ) {
			$mother = '<a href="/' . sanitize_title_with_dashes( get_field( 'mother_name' ) ) . '">' . get_field( 'mother_name' ) . '</a>';
		}

		if ( '?' === $mother && '?' === $father ) {
			return '';
		}

		if ( 'Male' === get_field( 'sex' ) ) {
			return sprintf(
				// translators: %1$s is a mother's name, %2$s is a father's name.
				__( 'son of %1$s and %2$s', 'family-wiki' ),
				$mother,
				$father
			);
		}
		if ( 'Female' === get_field( 'sex' ) ) {
			return sprintf(
				// translators: %1$s is a mother's name, %2$s is a father's name.
				__( 'daughter of %1$s and %2$s', 'family-wiki' ),
				$mother,
				$father
			);
		}

		return sprintf(
			// translators: %1$s is a mother's name, %2$s is a father's name.
			__( 'child of %1$s and %2$s', 'family-wiki' ),
			$mother,
			$father
		);
	}

	public function short_date_bio() {
		try {
			$birth = new \DateTime( get_field( 'birth_date' ) );
		} catch ( \Exception $e ) {
			$birth = null;
		}
		try {
			$death = new \DateTime( get_field( 'death_date' ) );
		} catch ( \Exception $e ) {
			$death = null;
		}

		if ( get_field( 'alive' ) ) {
			if ( ! get_field( 'birth_date' ) ) {
				return '';
			}
			$age = $birth->diff( new \DateTime( 'now' ) );

			if ( get_field( 'born_as' ) ) {
				if ( get_field( 'birth_place' ) ) {
					if ( get_field( 'exact_birth_date_unknown' ) ) {
						return sprintf(
							// translators: %1$s is a maiden name, %2$s is a birth year, %3$s is an age in years, %4$s is a birth place.
							__( 'born as %1$s in %2$s (age: ~%3$s) in %4$s', 'family-wiki' ),
							'<i>' . get_field( 'born_as' ) . '</i>',
							$birth->format( 'Y' ),
							$age->y,
							get_field( 'birth_place' )
						);
					}
					return sprintf(
						// translators: %1$s is a maiden name, %2$s is a birth date, %3$s is an age in years, %4$s is a birth place.
						__( 'born as %1$s on %2$s (age: %3$s) in %4$s', 'family-wiki' ),
						'<i>' . get_field( 'born_as' ) . '</i>',
						$this->get_date( $birth ),
						$age->y,
						get_field( 'birth_place' )
					);
				}
				if ( get_field( 'exact_birth_date_unknown' ) ) {
					return sprintf(
						// translators: %1$s is a maiden name, %2$s is a birth year, %3$s is an age in years.
						__( 'born as %1$s in %2$s (age: ~%3$s)', 'family-wiki' ),
						'<i>' . get_field( 'born_as' ) . '</i>',
						$birth->format( 'Y' ),
						$age->y
					);
				}
				return sprintf(
					// translators: %1$s is a maiden name, %2$s is a birth date, %3$s is an age in years.
					__( 'born as %1$s on %2$s (age: %3$s)', 'family-wiki' ),
						'<i>' . get_field( 'born_as' ) . '</i>',
					$this->get_date( $birth ),
					$age->y
				);
			}
			if ( get_field( 'birth_place' ) ) {
				if ( get_field( 'exact_birth_date_unknown' ) ) {
					return sprintf(
						// translators: %1$s is a birth year, %2$s is an age in years, %3$s is a birth place.
						__( 'born in %1$s (age: ~%2$s) in %3$s', 'family-wiki' ),
						$birth->format( 'Y' ),
						$age->y,
						get_field( 'birth_place' )
					);
				}
				return sprintf(
					// translators: %1$s is a birth date, %2$s is an age in years, %3$s is a birth place.
					__( 'born on %1$s (age: %2$s) in %3$s', 'family-wiki' ),
					$this->get_date( $birth ),
					$age->y,
					get_field( 'birth_place' )
				);
			}
			if ( get_field( 'exact_birth_date_unknown' ) ) {
				return sprintf(
					// translators: %1$s is a birth year, %2$s is an age in years.
					__( 'born in %1$s (age: ~%2$s)', 'family-wiki' ),
					$birth->format( 'Y' ),
					$age->y
				);
			}
			return sprintf(
				// translators: %1$s is a birth date, %2$s is an age in years.
				__( 'born on %1$s (age: %2$s)', 'family-wiki' ),
				$this->get_date( $birth ),
				$age->y
			);
		}

		if ( $birth && $death ) {
			$aged = $birth->diff( $death );
		}

		if ( get_field( 'born_as' ) ) {
			if ( get_field( 'birth_place' ) ) {
				if ( get_field( 'exact_birth_date_unknown' ) ) {
					$return = sprintf(
						// translators: %1$s is a maiden name, %2$s is a birth year, %3$s is a birth place.
						__( 'born as %1$s in %2$s in %3$s', 'family-wiki' ),
						'<i>' . get_field( 'born_as' ) . '</i>',
						$birth->format( 'Y' ),
						get_field( 'birth_place' )
					);
				} else {
					$return = sprintf(
						// translators: %1$s is a maiden name, %2$s is a birth date, %3$s is a birth place.
						__( 'born as %1$s on %2$s in %3$s', 'family-wiki' ),
						'<i>' . get_field( 'born_as' ) . '</i>',
						$this->get_date( $birth ),
						get_field( 'birth_place' )
					);
				}
			} else {
				if ( get_field( 'exact_birth_date_unknown' ) ) {
					$return = sprintf(
						// translators: %1$s is a maiden name, %2$s is a birth year.
						__( 'born as %1$s in %2$s', 'family-wiki' ),
						'<i>' . get_field( 'born_as' ) . '</i>',
						$birth->format( 'Y' )
					);
				} else {
					$return = sprintf(
						// translators: %1$s is a maiden name, %2$s is a birth date.
						__( 'born as %1$s on %2$s', 'family-wiki' ),
						'<i>' . get_field( 'born_as' ) . '</i>',
						$this->get_date( $birth )
					);
				}
			}
		} else {
			if ( get_field( 'birth_place' ) ) {
				if ( get_field( 'exact_birth_date_unknown' ) ) {
					$return = sprintf(
						// translators: %1$s is a birth year, %2$s is a birth place.
						__( 'born in %1$s in %2$s', 'family-wiki' ),
						$birth->format( 'Y' ),
						get_field( 'birth_place' )
					);
				} else {
					$return = sprintf(
						// translators: %1$s is a birth date, %2$s is a birth place.
						__( 'born on %1$s in %2$s', 'family-wiki' ),
						$this->get_date( $birth ),
						get_field( 'birth_place' )
					);
				}
			} else {
				if ( get_field( 'exact_birth_date_unknown' ) ) {
					$return = sprintf(
					// translators: %s is a birth year.
						__( 'born in %s', 'family-wiki' ),
						$birth->format( 'Y' )
					);
				} else {
					$return = sprintf(
						// translators: %s is a birth date.
						__( 'born on %s', 'family-wiki' ),
						$this->get_date( $birth )
					);
				}
			}
		}

		if ( get_field( 'death_place' ) ) {
			if ( get_field( 'exact_death_date_unknown' ) ) {
				return $return . ', ' . sprintf(
					// translators: %1$s is a birth year, %2$s is an age in years, %3$s is a death place.
					__( 'died in %1$s (aged: %1$s) in %4$s', 'family-wiki' ),
					$death->format( 'Y' ),
					$aged->y,
					get_field( 'death_place' )
				);
			}

			return $return . ', ' . sprintf(
				// translators: %1$s is a birth date, %2$s is a death date, %3$s is an age in years.
				__( 'died on %1$s (aged: %2$s) in %3$s', 'family-wiki' ),
				$this->get_date( $death ),
				$aged->y,
				get_field( 'death_place' )
			);
		}

		if ( get_field( 'exact_death_date_unknown' ) ) {
			return $return . ', ' . sprintf(
				// translators: %1$s is a birth year, %2$s is an age in years.
				__( 'died on %1$s (aged: %2$s)', 'family-wiki' ),
				$death->format( 'Y' ),
				$aged->y
			);
		}
		return $return . ', ' . sprintf(
			// translators: %1$s is a birth date, %2$s is an age in years.
			__( 'died on %1$s (aged: %2$s)', 'family-wiki' ),
			$this->get_date( $death ),
			$aged->y
		);
	}

	public function born( $atts, $content ) {
		if ( empty( $atts['date'] ) ) {
			return $content;
		}
		try {
			$birth = new \DateTime( $atts['date'] );
		} catch ( \Exception $e ) {
			return $atts['date'];
		}
		if ( ! $birth ) {
			return $atts['date'];
		}
		$return = date_i18n( get_option( 'date_format' ), $birth->format( 'U' ) );
		if ( get_option( 'family_wiki_calendar_page' ) ) {
			$return = '<a href="' . get_option( 'family_wiki_calendar_page' ) . '#' . date_i18n( 'F', $birth->format( 'U' ) ) . '">' . $return . '</a>';
		}

		$age = '';

		if ( isset( $atts['showage'] ) || in_array( 'showage', $atts, true ) ) {
			$age = $birth->diff( new \DateTime( 'now' ) );
			// translators: %d is an age in years.
			$age = ' (' . sprintf( _n( 'age %d', 'age %d', $age->y, 'family-wiki' ), $age->y ) . ')';
		}

		return $return . $age;
	}

	public function died( $atts, $content ) {
		if ( empty( $atts['date'] ) || empty( $atts['birth'] ) ) {
			return $content;
		}
		if ( 4 === strlen( trim( $atts['date'] ) ) && is_numeric( $atts['date'] ) ) {
			$atts['date'] .= '-12-31';
		}
		if ( 4 === strlen( trim( $atts['birth'] ) ) && is_numeric( $atts['birth'] ) ) {
			$atts['birth'] .= '-12-31';
		}
		try {
			$death = new \DateTime( $atts['date'] );
		} catch ( \Exception $e ) {
			return $atts['date'];
		}
		if ( ! $death ) {
			return $atts['date'];
		}

		try {

			$birth = new \DateTime( $atts['birth'] );
		} catch ( \Exception $e ) {
			return $atts['date'];
		}
		if ( ! $birth ) {
			return $atts['date'];
		}

		$age = $birth->diff( $death );

		$return = date_i18n( get_option( 'date_format' ), $death->format( 'U' ) );
		if ( get_option( 'family_wiki_calendar_page' ) ) {
			$return = '<a href="' . get_option( 'family_wiki_calendar_page' ) . '#' . date_i18n( 'F', $death->format( 'U' ) ) . '">' . $return . '</a>';
		}
		// translators: %d is an age in years.
		return $return . ' (' . sprintf( _n( 'aged %d', 'aged %d', $age->y, 'family-wiki' ), $age->y ) . ')';
	}
}
