<?php
namespace Family_Wiki;

class Front_Page {
	const UPCOMING_LIMIT = 5;

	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );
		add_filter( 'the_content', array( $this, 'add_front_page_box' ), 11 );
	}

	public function enqueue_styles() {
		if ( ! is_front_page() ) {
			return;
		}

		wp_enqueue_style(
			'family-wiki-infobox',
			plugin_dir_url( __FILE__ ) . 'family-wiki.css',
			array(),
			filemtime( plugin_dir_path( __FILE__ ) . 'family-wiki.css' )
		);
	}

	public function add_front_page_box( $content ) {
		if ( ! function_exists( 'get_field' ) || ! is_front_page() || ! in_the_loop() || ! is_main_query() || 'page' !== get_post_type() ) {
			return $content;
		}

		$box = $this->render_box();
		if ( ! $box ) {
			return $content;
		}

		return $box . $content;
	}

	private function render_box() {
		$cache_key = self::get_box_cache_key();
		$cached    = wp_cache_get( $cache_key, 'family-wiki' );
		if ( false === $cached ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				wp_cache_set( $cache_key, $cached, 'family-wiki', 15 * MINUTE_IN_SECONDS );
			}
		}

		if ( is_string( $cached ) ) {
			return $cached;
		}

		$random_person = $this->get_random_person();
		$birthdays     = $this->get_upcoming_events( 'birth' );
		$death_dates   = $this->get_upcoming_events( 'death' );

		if ( ! $random_person && empty( $birthdays ) && empty( $death_dates ) ) {
			wp_cache_set( $cache_key, '', 'family-wiki', 15 * MINUTE_IN_SECONDS );
			set_transient( $cache_key, '', 15 * MINUTE_IN_SECONDS );
			return '';
		}

		$return  = '<section class="family-wiki-homepage-box" aria-label="' . esc_attr__( 'Family Wiki highlights', 'family-wiki' ) . '">';
		$return .= '<h2 class="family-wiki-homepage-box__title">' . esc_html__( 'Family Wiki', 'family-wiki' ) . '</h2>';
		$return .= '<div class="family-wiki-homepage-box__content">';

		if ( $random_person ) {
			$return .= $this->render_random_person( $random_person );
		}

		$return .= $this->render_event_list( __( 'Upcoming Birthdays', 'family-wiki' ), $birthdays, Calendar::is_birthdays_enabled() ? Calendar::get_birthdays_url() : '' );
		$return .= $this->render_event_list( __( 'Upcoming Death Dates', 'family-wiki' ), $death_dates, Calendar::is_calendar_enabled() ? Calendar::get_calendar_url() : '' );

		$return .= '</div>';
		$return .= '</section>';

		wp_cache_set( $cache_key, $return, 'family-wiki', 15 * MINUTE_IN_SECONDS );
		set_transient( $cache_key, $return, 15 * MINUTE_IN_SECONDS );

		return $return;
	}

	public static function flush_cache() {
		$cache_key = self::get_box_cache_key();
		wp_cache_delete( $cache_key, 'family-wiki' );
		delete_transient( $cache_key );
	}

	private static function get_box_cache_key() {
		return 'family_wiki_front_page_box_' . get_current_blog_id() . '_' . get_locale();
	}

	private function render_random_person( $person ) {
		$return  = '<section class="family-wiki-homepage-box__section family-wiki-homepage-box__random">';
		$return .= '<h3>' . esc_html__( 'Random Person of the Hour', 'family-wiki' ) . '</h3>';
		$return .= '<div class="family-wiki-homepage-box__person">';

		if ( has_post_thumbnail( $person ) ) {
			$return .= '<a class="family-wiki-homepage-box__image" href="' . esc_url( get_permalink( $person ) ) . '">' . get_the_post_thumbnail( $person, 'thumbnail' ) . '</a>';
		}

		$return .= '<div>';
		$return .= '<a class="family-wiki-homepage-box__person-name" href="' . esc_url( get_permalink( $person ) ) . '">' . esc_html( get_the_title( $person ) ) . '</a>';
		$return .= $this->render_bio_snippet( $person );
		$return .= '</div>';
		$return .= '</div>';
		$return .= '</section>';

		return $return;
	}

	private function render_bio_snippet( $person ) {
		$parts      = array();
		$date_range = $this->render_life_years( $person );

		if ( $date_range ) {
			$parts[] = $date_range;
		}

		$relationships = $this->get_relationship_bios( $person );
		if ( ! empty( $relationships ) ) {
			$parts[] = implode( '; ', $relationships );
		}

		if ( empty( $parts ) ) {
			return '';
		}

		return ' <span class="family-wiki-homepage-box__meta">(' . wp_kses_post( implode( '; ', $parts ) ) . ')</span>';
	}

	private function render_life_years( $person ) {
		$birth = $this->get_page_date( $person->ID, 'birth' );
		$death = $this->get_page_date( $person->ID, 'death' );

		if ( ! $birth && ! $death ) {
			return '';
		}

		$birth_year = $birth ? $this->calendar_year_link( $birth ) : '';
		$death_year = $death ? $this->calendar_year_link( $death ) : '';

		return $birth_year . '-' . $death_year;
	}

	private function calendar_year_link( \DateTime $date ) {
		$year = esc_html( $date->format( 'Y' ) );
		if ( ! Calendar::is_calendar_enabled() ) {
			return $year;
		}

		return '<a href="' . esc_url( Calendar::get_calendar_url( $date ) ) . '">' . $year . '</a>';
	}

	private function get_relationship_bios( $person ) {
		$rows = array();

		$spouse_bio = $this->get_spouse_bio( $person->ID );
		if ( $spouse_bio ) {
			$rows[] = $spouse_bio;
		}

		$sibling_bio = $this->get_sibling_bio( $person->ID );
		if ( $sibling_bio ) {
			$rows[] = $sibling_bio;
		}

		$children_bio = $this->get_children_bio( $person->ID );
		if ( $children_bio ) {
			$rows[] = $children_bio;
		}

		if ( empty( $rows ) ) {
			return array();
		}

		return $rows;
	}

	private function get_spouse_bio( $post_id ) {
		$spouses = $this->get_spouse_links( $post_id );
		if ( empty( $spouses ) ) {
			return '';
		}

		if ( 'Male' === get_field( 'sex', $post_id ) ) {
			return sprintf(
				// translators: %s is a list of spouses.
				__( 'husband of %s', 'family-wiki' ),
				implode( ', ', $spouses )
			);
		}

		if ( 'Female' === get_field( 'sex', $post_id ) ) {
			return sprintf(
				// translators: %s is a list of spouses.
				__( 'wife of %s', 'family-wiki' ),
				implode( ', ', $spouses )
			);
		}

		return sprintf(
			// translators: %s is a list of spouses.
			__( 'spouse of %s', 'family-wiki' ),
			implode( ', ', $spouses )
		);
	}

	private function get_sibling_bio( $post_id ) {
		$siblings = $this->get_sibling_links( $post_id );
		if ( empty( $siblings ) ) {
			return '';
		}

		if ( 'Male' === get_field( 'sex', $post_id ) ) {
			return sprintf(
				// translators: %s is a list of siblings.
				__( 'brother of %s', 'family-wiki' ),
				implode( ', ', $siblings )
			);
		}

		if ( 'Female' === get_field( 'sex', $post_id ) ) {
			return sprintf(
				// translators: %s is a list of siblings.
				__( 'sister of %s', 'family-wiki' ),
				implode( ', ', $siblings )
			);
		}

		return sprintf(
			// translators: %s is a list of siblings.
			__( 'sibling of %s', 'family-wiki' ),
			implode( ', ', $siblings )
		);
	}

	private function get_children_bio( $post_id ) {
		$children = $this->get_child_links( $post_id );
		if ( empty( $children ) ) {
			return '';
		}

		if ( 'Male' === get_field( 'sex', $post_id ) ) {
			return sprintf(
				// translators: %s is a list of children.
				__( 'father of %s', 'family-wiki' ),
				implode( ', ', $children )
			);
		}

		if ( 'Female' === get_field( 'sex', $post_id ) ) {
			return sprintf(
				// translators: %s is a list of children.
				__( 'mother of %s', 'family-wiki' ),
				implode( ', ', $children )
			);
		}

		return sprintf(
			// translators: %s is a list of children.
			__( 'parent of %s', 'family-wiki' ),
			implode( ', ', $children )
		);
	}

	private function get_spouse_links( $post_id ) {
		$links     = array();
		$marriages = get_field( 'marriages', $post_id );

		if ( ! empty( $marriages ) && is_array( $marriages ) ) {
			foreach ( $marriages as $marriage ) {
				if ( ! empty( $marriage['spouse'] ) ) {
					$links[] = $this->person_link( $marriage['spouse'] );
				} elseif ( ! empty( $marriage['spouse_name'] ) ) {
					$links[] = esc_html( $marriage['spouse_name'] );
				}
			}
		}

		$spouses = get_field( 'spouse', $post_id );
		if ( empty( $spouses ) ) {
			$spouses = array();
		} elseif ( ! is_array( $spouses ) ) {
			$spouses = array( $spouses );
		}

		foreach ( $spouses as $spouse ) {
			$links[] = $this->person_link( $spouse );
		}

		if ( get_field( 'spouse_name', $post_id ) ) {
			$links[] = esc_html( get_field( 'spouse_name', $post_id ) );
		}

		return array_values( array_unique( array_filter( $links ) ) );
	}

	private function get_sibling_links( $post_id ) {
		$siblings = array();

		foreach ( array( 'father', 'mother' ) as $parent_field ) {
			$parent = get_field( $parent_field, $post_id );
			if ( ! $parent ) {
				continue;
			}

			$children = get_field( 'children', $parent );
			if ( empty( $children ) || ! is_array( $children ) ) {
				continue;
			}

			foreach ( $children as $child ) {
				$child_id = $this->person_id( $child );
				if ( $child_id && (int) $post_id !== $child_id ) {
					$siblings[ $child_id ] = $this->person_link( $child );
				}
			}
		}

		return array_values( array_filter( $siblings ) );
	}

	private function get_child_links( $post_id ) {
		$children = get_field( 'children', $post_id );
		if ( empty( $children ) || ! is_array( $children ) ) {
			return array();
		}

		$links = array();
		foreach ( $children as $child ) {
			$links[] = $this->person_link( $child );
		}

		return array_values( array_unique( array_filter( $links ) ) );
	}

	private function person_link( $person ) {
		$post_id = $this->person_id( $person );
		if ( ! $post_id ) {
			return '';
		}

		return '<a href="' . esc_url( get_permalink( $post_id ) ) . '">' . esc_html( get_the_title( $post_id ) ) . '</a>';
	}

	private function person_id( $person ) {
		if ( $person instanceof \WP_Post ) {
			return (int) $person->ID;
		}

		if ( is_numeric( $person ) ) {
			return (int) $person;
		}

		return 0;
	}

	private function render_event_list( $title, $events, $url ) {
		$return  = '<section class="family-wiki-homepage-box__section">';
		$return .= $url ? '<h3><a href="' . esc_url( $url ) . '">' . esc_html( $title ) . '</a></h3>' : '<h3>' . esc_html( $title ) . '</h3>';

		if ( empty( $events ) ) {
			$return .= '<p class="family-wiki-homepage-box__empty">' . esc_html__( 'No upcoming dates found.', 'family-wiki' ) . '</p>';
		} else {
			$return .= '<ul class="family-wiki-homepage-box__events">';
			foreach ( $events as $event ) {
				$return .= '<li>';
				$return .= '<span class="family-wiki-homepage-box__event-date">' . esc_html( $event['date_label'] ) . '</span> ';
				$return .= '<a href="' . esc_url( get_permalink( $event['page'] ) ) . '">' . esc_html( get_the_title( $event['page'] ) ) . '</a>';
				$return .= ' <span class="family-wiki-homepage-box__meta">' . esc_html( $event['note'] ) . '</span>';
				$return .= '</li>';
			}
			$return .= '</ul>';
		}

		$return .= '</section>';

		return $return;
	}

	private function get_random_person() {
		$people = $this->get_people_with_dates();
		if ( empty( $people ) ) {
			return null;
		}

		$hour  = floor( current_time( 'timestamp' ) / HOUR_IN_SECONDS );
		$seed  = crc32( get_current_blog_id() . ':' . $hour );
		$index = $seed % count( $people );

		return $people[ $index ];
	}

	private function get_upcoming_events( $type ) {
		$events = array();
		$now    = new \DateTime( 'today' );

		foreach ( $this->get_people_with_dates() as $page ) {
			$date = $this->get_page_date( $page->ID, $type );
			if ( ! $date ) {
				continue;
			}

			if ( 'birth' === $type && ! get_field( 'alive', $page->ID ) ) {
				continue;
			}

			$next = \DateTime::createFromFormat( 'Y-m-d', $now->format( 'Y' ) . '-' . $date->format( 'm-d' ) );
			if ( $next < $now ) {
				$next->modify( '+1 year' );
			}

			$years = (int) $next->format( 'Y' ) - (int) $date->format( 'Y' );
			$note  = 'birth' === $type
				? sprintf(
					// translators: %d is an age in years.
					_n( 'turns %d', 'turns %d', $years, 'family-wiki' ),
					$years
				)
				: sprintf(
					// translators: %d is a number of years.
					_n( '%d years ago', '%d years ago', $years, 'family-wiki' ),
					$years
				);

			$events[] = array(
				'page'       => $page,
				'next'       => $next,
				'date_label' => date_i18n( 'M j', $next->format( 'U' ) ),
				'note'       => $note,
			);
		}

		usort(
			$events,
			function ( $a, $b ) {
				if ( $a['next'] == $b['next'] ) {
					return strcasecmp( get_the_title( $a['page'] ), get_the_title( $b['page'] ) );
				}

				return $a['next'] < $b['next'] ? -1 : 1;
			}
		);

		return array_slice( $events, 0, self::UPCOMING_LIMIT );
	}

	private function get_people_with_dates() {
		static $people;

		if ( isset( $people ) ) {
			return $people;
		}

		$people = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'post_parent'    => 0,
				'post__not_in'   => array_filter( array( (int) get_option( 'page_on_front' ) ) ),
				'meta_query'     => array(
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

	private function get_page_date( $post_id, $type ) {
		$field = $type . '_date';
		$value = get_field( $field, $post_id );
		if ( ! $value || get_field( 'exact_' . $type . '_date_unknown', $post_id ) ) {
			return null;
		}

		try {
			return new \DateTime( $value );
		} catch ( \Exception $e ) {
			return null;
		}
	}
}
