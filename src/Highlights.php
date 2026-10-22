<?php
/**
 * The box on the app's home page: a person picked for the hour, and the dates
 * coming up next.
 *
 * @package Familypedia
 */

namespace Family_Wiki;

class Highlights {
	const UPCOMING_LIMIT = 5;
	const CACHE_GROUP = 'family-wiki';

	public static function render() {
		$cache_key = self::cache_key();
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false === $cached ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				wp_cache_set( $cache_key, $cached, self::CACHE_GROUP, 15 * MINUTE_IN_SECONDS );
			}
		}

		if ( is_string( $cached ) ) {
			return $cached;
		}

		$random_person = self::get_random_person();
		$birthdays     = self::get_upcoming_events( 'birth' );
		$death_dates   = self::get_upcoming_events( 'death' );

		if ( ! $random_person && empty( $birthdays ) && empty( $death_dates ) ) {
			wp_cache_set( $cache_key, '', self::CACHE_GROUP, 15 * MINUTE_IN_SECONDS );
			set_transient( $cache_key, '', 15 * MINUTE_IN_SECONDS );
			return '';
		}

		$return  = '<section class="familypedia-highlights" aria-label="' . esc_attr__( 'Familypedia highlights', 'family-wiki' ) . '">';
		$return .= '<div class="familypedia-highlights__content">';

		if ( $random_person ) {
			$return .= self::render_random_person( $random_person );
		}

		$return .= self::render_event_list( __( 'Upcoming Birthdays', 'family-wiki' ), $birthdays, Calendar::is_birthdays_enabled() ? Calendar::get_birthdays_url() : '' );
		$return .= self::render_event_list( __( 'Upcoming Death Dates', 'family-wiki' ), $death_dates, Calendar::is_calendar_enabled() ? Calendar::get_calendar_url() : '' );

		$return .= '</div>';
		$return .= '</section>';

		wp_cache_set( $cache_key, $return, self::CACHE_GROUP, 15 * MINUTE_IN_SECONDS );
		set_transient( $cache_key, $return, 15 * MINUTE_IN_SECONDS );

		return $return;
	}

	public static function flush_cache() {
		$cache_key = self::cache_key();
		wp_cache_delete( $cache_key, self::CACHE_GROUP );
		delete_transient( $cache_key );
	}

	private static function cache_key() {
		return 'familypedia_highlights_' . get_current_blog_id() . '_' . get_locale();
	}

	private static function render_random_person( $person ) {
		$return  = '<section class="familypedia-highlights__section familypedia-highlights__random">';
		$return .= '<h3>' . esc_html__( 'Random Person of the Hour', 'family-wiki' ) . '</h3>';
		$return .= '<div class="familypedia-highlights__person">';

		if ( has_post_thumbnail( $person ) ) {
			$return .= '<a class="familypedia-highlights__image" href="' . esc_url( Person::url( $person ) ) . '">' . get_the_post_thumbnail( $person, 'thumbnail' ) . '</a>';
		}

		$return .= '<div>';
		$return .= '<a class="familypedia-highlights__person-name" href="' . esc_url( Person::url( $person ) ) . '">' . esc_html( get_the_title( $person ) ) . '</a>';
		$return .= self::render_bio_snippet( $person );
		$return .= '</div>';
		$return .= '</div>';
		$return .= '</section>';

		return $return;
	}

	private static function render_bio_snippet( $person ) {
		$parts      = array();
		$date_range = self::render_life_years( $person );

		if ( $date_range ) {
			$parts[] = $date_range;
		}

		$relationships = self::get_relationship_bios( $person );
		if ( ! empty( $relationships ) ) {
			$parts[] = implode( '; ', $relationships );
		}

		if ( empty( $parts ) ) {
			return '';
		}

		return ' <span class="familypedia-highlights__meta">(' . wp_kses_post( implode( '; ', $parts ) ) . ')</span>';
	}

	private static function render_life_years( $person ) {
		$birth = self::get_person_date( $person->ID, 'birth' );
		$death = self::get_person_date( $person->ID, 'death' );

		if ( ! $birth && ! $death ) {
			return '';
		}

		$birth_year = $birth ? self::calendar_year_link( $birth ) : '';
		$death_year = $death ? self::calendar_year_link( $death ) : '';

		return $birth_year . '-' . $death_year;
	}

	private static function calendar_year_link( \DateTime $date ) {
		$year = esc_html( $date->format( 'Y' ) );
		if ( ! Calendar::is_calendar_enabled() ) {
			return $year;
		}

		return '<a href="' . esc_url( Calendar::get_calendar_url( $date ) ) . '">' . $year . '</a>';
	}

	private static function get_relationship_bios( $person ) {
		$rows = array();

		foreach ( array( 'spouse', 'sibling', 'children' ) as $kind ) {
			$row = self::relationship_bio( $person->ID, $kind );
			if ( $row ) {
				$rows[] = $row;
			}
		}

		return $rows;
	}

	private static function relationship_bio( $post_id, $kind ) {
		if ( 'spouse' === $kind ) {
			$links = Relationships::spouse_links( $post_id );
			$forms = array(
				// translators: %s is a list of spouses.
				__( 'husband of %s', 'family-wiki' ),
				// translators: %s is a list of spouses.
				__( 'wife of %s', 'family-wiki' ),
				// translators: %s is a list of spouses.
				__( 'spouse of %s', 'family-wiki' ),
			);
		} elseif ( 'sibling' === $kind ) {
			$links = array_map( array( Relationships::class, 'person_link' ), Relationships::siblings( $post_id ) );
			$forms = array(
				// translators: %s is a list of siblings.
				__( 'brother of %s', 'family-wiki' ),
				// translators: %s is a list of siblings.
				__( 'sister of %s', 'family-wiki' ),
				// translators: %s is a list of siblings.
				__( 'sibling of %s', 'family-wiki' ),
			);
		} else {
			$links = array_map( array( Relationships::class, 'person_link' ), Person::field( 'children', $post_id ) );
			$forms = array(
				// translators: %s is a list of children.
				__( 'father of %s', 'family-wiki' ),
				// translators: %s is a list of children.
				__( 'mother of %s', 'family-wiki' ),
				// translators: %s is a list of children.
				__( 'parent of %s', 'family-wiki' ),
			);
		}

		$links = array_values( array_unique( array_filter( $links ) ) );
		if ( empty( $links ) ) {
			return '';
		}

		$sex = Person::field( 'sex', $post_id );
		if ( 'Male' === $sex ) {
			$format = $forms[0];
		} elseif ( 'Female' === $sex ) {
			$format = $forms[1];
		} else {
			$format = $forms[2];
		}

		return sprintf( $format, implode( ', ', $links ) );
	}

	private static function render_event_list( $title, $events, $url ) {
		$return  = '<section class="familypedia-highlights__section">';
		$return .= $url ? '<h3><a href="' . esc_url( $url ) . '">' . esc_html( $title ) . '</a></h3>' : '<h3>' . esc_html( $title ) . '</h3>';

		if ( empty( $events ) ) {
			$return .= '<p class="familypedia-highlights__empty">' . esc_html__( 'No upcoming dates found.', 'family-wiki' ) . '</p>';
		} else {
			$return .= '<ul class="familypedia-highlights__events">';
			foreach ( $events as $event ) {
				$return .= '<li>';
				$return .= '<span class="familypedia-highlights__event-date">' . esc_html( $event['date_label'] ) . '</span> ';
				$return .= '<a href="' . esc_url( Person::url( $event['person'] ) ) . '">' . esc_html( get_the_title( $event['person'] ) ) . '</a>';
				$return .= ' <span class="familypedia-highlights__meta">' . esc_html( $event['note'] ) . '</span>';
				$return .= '</li>';
			}
			$return .= '</ul>';
		}

		$return .= '</section>';

		return $return;
	}

	/**
	 * The same person for everyone for an hour, rather than a different one on
	 * every page load: a page that changes under you is not a page you can share.
	 */
	private static function get_random_person() {
		$people = self::get_people_with_dates();
		if ( empty( $people ) ) {
			return null;
		}

		$hour  = (int) floor( time() / HOUR_IN_SECONDS );
		$seed  = crc32( get_current_blog_id() . ':' . $hour );
		$index = $seed % count( $people );

		return $people[ $index ];
	}

	private static function get_upcoming_events( $type ) {
		$events = array();
		$now    = new \DateTime( 'today' );

		foreach ( self::get_people_with_dates() as $person ) {
			$date = self::get_person_date( $person->ID, $type );
			if ( ! $date ) {
				continue;
			}

			if ( 'birth' === $type && ! Person::field( 'alive', $person->ID ) ) {
				continue;
			}

			$next = \DateTime::createFromFormat( 'Y-m-d', $now->format( 'Y' ) . '-' . $date->format( 'm-d' ) );
			if ( ! $next ) {
				continue;
			}
			if ( $next < $now ) {
				$next->modify( '+1 year' );
			}

			$years = (int) $next->format( 'Y' ) - (int) $date->format( 'Y' );
			$note  = 'birth' === $type
				// translators: %d is an age in years.
				? sprintf( _n( 'turns %d', 'turns %d', $years, 'family-wiki' ), $years )
				// translators: %d is a number of years.
				: sprintf( _n( '%d years ago', '%d years ago', $years, 'family-wiki' ), $years );

			$events[] = array(
				'person'     => $person,
				'next'       => $next,
				'date_label' => date_i18n( 'M j', $next->format( 'U' ) ),
				'note'       => $note,
			);
		}

		usort(
			$events,
			function ( $a, $b ) {
				$next_a = $a['next']->getTimestamp();
				$next_b = $b['next']->getTimestamp();

				if ( $next_a === $next_b ) {
					return strcasecmp( get_the_title( $a['person'] ), get_the_title( $b['person'] ) );
				}

				return $next_a < $next_b ? -1 : 1;
			}
		);

		return array_slice( $events, 0, self::UPCOMING_LIMIT );
	}

	private static function get_people_with_dates() {
		static $people;

		if ( isset( $people ) ) {
			return $people;
		}

		$people = Person::get_all(
			array(
				'post_parent' => 0,
				'orderby'     => 'ID',
				'order'       => 'ASC',
				'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'OR',
					array(
						'key'     => 'birth_date',
						'compare' => 'EXISTS',
					),
					array(
						'key'     => 'death_date',
						'compare' => 'EXISTS',
					),
				),
			)
		);

		return $people;
	}

	private static function get_person_date( $post_id, $type ) {
		$value = Person::field( $type . '_date', $post_id );
		if ( ! $value || Person::field( 'exact_' . $type . '_date_unknown', $post_id ) ) {
			return null;
		}

		try {
			return new \DateTime( $value );
		} catch ( \Exception $e ) {
			return null;
		}
	}
}
