<?php
// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Calendar entries are intentionally selected by person metadata.
/**
 * The family calendar and the birthday list.
 *
 * Family Wiki served these as virtual WordPress pages. Familypedia serves them
 * as app routes, so everything to do with faking a post has gone; what is left
 * is the date index and the rendering, which the templates and the blocks both
 * call into.
 *
 * @package Familypedia
 */

namespace Family_Wiki;

class Calendar {
	const MONTH_ROUTE_VAR = 'calendar_month';
	const VIEW_CALENDAR = 'calendar';
	const VIEW_BIRTHDAYS = 'birthdays';

	private $all_dates = null;

	/**
	 * The instance the templates render through.
	 *
	 * @var Calendar|null
	 */
	private static $instance = null;

	/**
	 * The shared instance, so a template can render a calendar without hooking
	 * everything up a second time.
	 */
	public static function get() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function __construct() {
		if ( ! self::$instance ) {
			self::$instance = $this;
		}

		add_action( 'init', array( $this, 'register_blocks' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_editor_assets' ) );
	}

	public function register_blocks() {
		register_block_type(
			'family-wiki/family-calendar',
			array(
				'render_callback' => array( $this, 'render_family_calendar' ),
			)
		);
		register_block_type(
			'family-wiki/birthday-calendar',
			array(
				'render_callback' => array( $this, 'render_birthday_calendar' ),
			)
		);
	}

	public function enqueue_block_editor_assets() {
		wp_enqueue_script(
			'familypedia-family-calendar',
			Assets::url( 'family-calendar.js' ),
			array( 'wp-blocks', 'wp-server-side-render' ),
			Assets::version( 'family-calendar.js' ),
			true
		);
		wp_enqueue_script(
			'familypedia-birthday-calendar',
			Assets::url( 'birthday-calendar.js' ),
			array( 'wp-blocks', 'wp-server-side-render' ),
			Assets::version( 'birthday-calendar.js' ),
			true
		);
	}

	public static function get_calendar_url( $date = null ) {
		$url = home_url( '/' . App::URL_PATH . '/calendar/' );

		if ( $date ) {
			$url  = home_url( sprintf( '/%s/calendar/%02d/', App::URL_PATH, (int) $date->format( 'm' ) ) );
			$url .= '#' . rawurlencode( self::get_day_anchor( $date ) );
		}

		return $url;
	}

	public static function get_month_anchor( \DateTime $date ) {
		return sanitize_title( date_i18n( 'F', $date->format( 'U' ) ) );
	}

	public static function get_day_anchor( \DateTime $date ) {
		return 'familypedia-day-' . $date->format( 'm-d' );
	}

	public static function get_birthdays_url() {
		return home_url( '/' . App::URL_PATH . '/birthdays/' );
	}

	public static function is_calendar_enabled() {
		return Settings::app_page_enabled( self::VIEW_CALENDAR );
	}

	public static function is_birthdays_enabled() {
		return Settings::app_page_enabled( self::VIEW_BIRTHDAYS );
	}

	public static function flush_dates_cache() {
		$cache_key = self::get_dates_cache_key();
		wp_cache_delete( $cache_key, 'family-wiki' );
		delete_transient( $cache_key );
	}

	public static function get_title( $view ) {
		if ( self::VIEW_CALENDAR === $view ) {
			return __( 'Family Calendar', 'family-wiki' );
		}

		if ( self::VIEW_BIRTHDAYS === $view ) {
			return __( 'Birthdays', 'family-wiki' );
		}

		return '';
	}

	private function get_dates() {
		if ( is_null( $this->all_dates ) ) {
			$cache_key = self::get_dates_cache_key();
			$cached    = wp_cache_get( $cache_key, 'family-wiki' );
			if ( false === $cached ) {
				$cached = get_transient( $cache_key );
				if ( false !== $cached ) {
					wp_cache_set( $cache_key, $cached, 'family-wiki', HOUR_IN_SECONDS );
				}
			}

			if ( is_array( $cached ) ) {
				$this->all_dates = $cached;
				return $this->all_dates;
			}

			$args = array(
				'post_type'      => Person::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				// One key lookup avoids the four metadata joins produced by OR/EXISTS.
				'meta_query'     => array(
					array(
						'key'         => array( 'birth_date', 'death_date', 'marriages', 'marriage_date' ),
						'compare_key' => 'IN',
						'compare'     => 'EXISTS',
					),
				),
			);

			$p               = get_posts( $args );
			$this->all_dates = array();
			$now             = new \DateTime( 'now' );
			$seen_marriages  = array();
			foreach ( $p as $person ) {
				$dates = array();
				try {
					if ( Person::field( 'birth_date', $person->ID ) && ! Person::field( 'exact_birth_date_unknown', $person->ID ) ) {
						$dates['born'] = new \DateTime( Person::field( 'birth_date', $person->ID ) );
					}
				} catch ( \Exception $e ) {
					unset( $e );
				}
				try {
					if ( Person::field( 'death_date', $person->ID ) && ! Person::field( 'exact_death_date_unknown', $person->ID ) ) {
						$dates['died'] = new \DateTime( Person::field( 'death_date', $person->ID ) );
					}
				} catch ( \Exception $e ) {
					unset( $e );
				}

				$link = '<a href="' . esc_url( Person::url( $person ) ) . '">' . esc_html( get_the_title( $person ) ) . '</a>';

				foreach ( $dates as $type => $date ) {
					$arr = array(
						'date'   => $date,
						'type'   => $type,
						'ID'     => $person->ID,
						'text'   => $link . ' ',
						'person' => $link,
						'dead'   => ! Person::field( 'alive', $person->ID ),
						'age'    => '',
					);

					if ( 'born' === $type ) {
						$arr['text'] = sprintf(
							// translators: %1$s is a name, %2$s is a date.
							__( '%1$s was born on %2$s', 'family-wiki' ),
							$arr['text'],
							date_i18n( get_option( 'date_format' ), $date->format( 'U' ) )
						);
						if ( Person::field( 'alive', $person->ID ) ) {
							if ( $date->format( 'm' ) < $now->format( 'm' ) || ( $date->format( 'm' ) === $now->format( 'm' ) && $date->format( 'j' ) < $now->format( 'j' ) ) ) {
								$age = $date->diff( $now );
								if ( $age->y ) {
									// translators: %d is an age in years.
									$age = sprintf( _n( 'turned %d', 'turned %d', $age->y, 'family-wiki' ), $age->y );
								} else {
									$age = __( 'was born', 'family-wiki' );
								}
							} elseif ( $date->format( 'm-d' ) === $now->format( 'm-d' ) ) {
								$age = $date->diff( $now );
								if ( $age->y ) {
									// translators: %d is an age in years.
									$age = sprintf( _n( 'turns %d today', 'turns %d today', $age->y, 'family-wiki' ), $age->y );
								} else {
									$age = __( 'was born today', 'family-wiki' );
								}
							} else {
								$age = $now->format( 'Y' ) - $date->format( 'Y' );
								// translators: %d is an age in years.
								$age = sprintf( _n( 'will turn %d', 'will turn %d', $age, 'family-wiki' ), $age );
							}
							$arr['age']   = $age;
							$arr['text'] .= ' (' . $age . ')';
						} else {
							$age = $now->format( 'Y' ) - $date->format( 'Y' );
							// translators: %d is a number of years.
							$arr['text'] .= ' (' . sprintf( _n( '%d years ago', '%d years ago', $age, 'family-wiki' ), $age ) . ')';
						}
					} else {
						$arr['text'] = sprintf(
							// translators: %1$s is a name, %2$s is a date.
							__( '%1$s died on %2$s', 'family-wiki' ),
							$arr['text'],
							date_i18n( get_option( 'date_format' ), $date->format( 'U' ) )
						);
						$age = $now->format( 'Y' ) - $date->format( 'Y' );
						// translators: %d is a number of years.
						$arr['text'] .= ' (' . sprintf( _n( '%d years ago', '%d years ago', $age, 'family-wiki' ), $age ) . ')';
					}

					$this->add_date_event( $arr );
				}

				foreach ( $this->get_marriage_anniversary_events( $person, $now, $seen_marriages ) as $event ) {
					$this->add_date_event( $event );
				}
			}
			ksort( $this->all_dates );
			wp_cache_set( $cache_key, $this->all_dates, 'family-wiki', HOUR_IN_SECONDS );
			set_transient( $cache_key, $this->all_dates, HOUR_IN_SECONDS );
		}

		return $this->all_dates;
	}

	private static function get_dates_cache_key() {
		return 'familypedia_calendar_dates_' . get_current_blog_id() . '_' . get_locale();
	}

	private function add_date_event( $event ) {
		$month_day = $event['date']->format( 'm-d' );
		if ( ! isset( $this->all_dates[ $month_day ] ) ) {
			$this->all_dates[ $month_day ] = array();
		}

		$this->all_dates[ $month_day ][] = $event;
	}

	private function get_marriage_anniversary_events( $person, \DateTime $now, &$seen_marriages ) {
		$marriages = Person::field( 'marriages', $person->ID );
		if ( empty( $marriages ) || ! is_array( $marriages ) ) {
			$marriages = array();
		}

		$marriages = array_merge( $this->legacy_marriage_rows( $person->ID ), $marriages );
		if ( empty( $marriages ) ) {
			return array();
		}

		$events = array();
		foreach ( $marriages as $marriage ) {
			if ( isset( $marriage['relationship_type'] ) && 'partnership' === $marriage['relationship_type'] ) {
				continue;
			}

			if (
				empty( $marriage['marriage_date'] )
				|| $this->person_is_deceased( $person->ID )
				|| $this->marriage_spouse_is_deceased( $marriage )
				|| ( ! empty( $marriage['ended_reason'] ) && 'divorced' === $marriage['ended_reason'] )
			) {
				continue;
			}

			try {
				$date = new \DateTime( Person::normalize_date( $marriage['marriage_date'] ) );
			} catch ( \Exception $e ) {
				continue;
			}

			$key = $this->marriage_key( $person->ID, $marriage, $date );
			if ( isset( $seen_marriages[ $key ] ) ) {
				continue;
			}
			$seen_marriages[ $key ] = true;

			$couple = $this->couple_links( $person, $marriage );
			$years  = (int) $now->format( 'Y' ) - (int) $date->format( 'Y' );
			$event  = array(
				'date'   => $date,
				'type'   => 'married',
				'ID'     => $person->ID,
				'text'   => sprintf(
					// translators: %1$s is a couple, %2$s is a date.
					__( '%1$s married on %2$s', 'family-wiki' ),
					$couple,
					date_i18n( get_option( 'date_format' ), $date->format( 'U' ) )
				),
				'person' => $couple,
				'dead'   => false,
				'age'    => '',
			);

			if ( $years ) {
				// translators: %d is a number of years.
				$event['text'] .= ' (' . sprintf( _n( '%d years ago', '%d years ago', $years, 'family-wiki' ), $years ) . ')';
			}

			$events[] = $event;
		}

		return $events;
	}

	/**
	 * The single marriage_date/spouse fields predate the marriages list and are
	 * still read so that data recorded that way keeps its anniversary.
	 */
	private function legacy_marriage_rows( $post_id ) {
		$marriage_date = Person::field( 'marriage_date', $post_id );
		if ( empty( $marriage_date ) ) {
			return array();
		}

		$rows = array();
		foreach ( Person::field( 'spouse', $post_id ) as $spouse ) {
			$rows[] = array(
				'spouse'        => (int) $spouse->ID,
				'spouse_name'   => '',
				'marriage_date' => $marriage_date,
			);
		}

		if ( empty( $rows ) && Person::field( 'spouse_name', $post_id ) ) {
			$rows[] = array(
				'spouse'        => 0,
				'spouse_name'   => Person::field( 'spouse_name', $post_id ),
				'marriage_date' => $marriage_date,
			);
		}

		return $rows;
	}

	private function marriage_spouse_is_deceased( $marriage ) {
		if ( empty( $marriage['spouse'] ) ) {
			return false;
		}

		return $this->person_is_deceased( $marriage['spouse'] );
	}

	private function person_is_deceased( $post_id ) {
		return (bool) Person::field( 'death_date', $post_id ) || ! Person::field( 'alive', $post_id );
	}

	private function marriage_key( $post_id, $marriage, \DateTime $date ) {
		if ( ! empty( $marriage['spouse'] ) ) {
			$ids = array( (int) $post_id, (int) $marriage['spouse'] );
			sort( $ids );

			return implode( '-', $ids ) . '-' . $date->format( 'Y-m-d' );
		}

		$spouse_name = isset( $marriage['spouse_name'] ) ? $marriage['spouse_name'] : '';

		return (int) $post_id . '-' . strtolower( trim( $spouse_name ) ) . '-' . $date->format( 'Y-m-d' );
	}

	private function couple_links( $person, $marriage ) {
		$link = '<a href="' . esc_url( Person::url( $person ) ) . '">' . esc_html( get_the_title( $person ) ) . '</a>';

		if ( ! empty( $marriage['spouse'] ) ) {
			$spouse  = '<a href="' . esc_url( Person::url( $marriage['spouse'] ) ) . '">';
			$spouse .= esc_html( get_the_title( $marriage['spouse'] ) ) . '</a>';
		} elseif ( ! empty( $marriage['spouse_name'] ) ) {
			$spouse = esc_html( $marriage['spouse_name'] );
		} else {
			return $link;
		}

		return sprintf(
			// translators: %1$s and %2$s are spouse names.
			__( '%1$s and %2$s', 'family-wiki' ),
			$link,
			$spouse
		);
	}

	public function render_family_calendar() {
		// Calendars are live views; omit them from static archive snapshots.
		if ( doing_filter( 'static_archive_post_html' ) || doing_filter( 'static_archive_post_markdown' ) ) {
			return '';
		}

		$dates = $this->get_dates();
		$month = $this->get_calendar_month_date();

		if ( $this->requested_month() ) {
			return $this->render_calendar_month_view( $month, $dates );
		}

		return $this->render_calendar_month_view( $month, $dates ) . $this->render_family_calendar_list( $dates );
	}

	private function render_family_calendar_list( $dates ) {
		$last_month = 0;
		$return     = '';

		foreach ( $dates as $date => $people ) {
			foreach ( $people as $person ) {
				$month = strtok( $date, '-' );
				if ( $month !== $last_month ) {
					if ( $return ) {
						$return .= '</ul>';
					}
					$m          = date_i18n( 'F', $person['date']->format( 'U' ) );
					$return    .= '<h4 id="' . esc_attr( self::get_month_anchor( $person['date'] ) ) . '"><a href="' . esc_url( $this->get_calendar_month_url( $person['date'] ) ) . '">' . esc_html( $m ) . '</a></h4><ul>';
					$last_month = $month;
				}
				$return .= '<li>' . wp_kses_post( str_replace( array( ' (' . __( '0 years ago', 'family-wiki' ) . ')', ' (' . __( 'was born', 'family-wiki' ) . ')' ), ' (' . __( 'this year', 'family-wiki' ) . ')', $person['text'] ) ) . '.</li>';
			}
		}

		if ( $return ) {
			$return .= '</ul>';
		}

		return $return;
	}

	private function requested_month() {
		$month = absint( wp_app_get_route_var( self::MONTH_ROUTE_VAR, 0 ) );

		return ( $month >= 1 && $month <= 12 ) ? $month : 0;
	}

	private function get_calendar_month_date() {
		$month = $this->requested_month();
		$year  = (int) current_time( 'Y' );
		if ( ! $month ) {
			$month = (int) current_time( 'n' );
		}

		return \DateTime::createFromFormat( 'Y-n-j', $year . '-' . $month . '-1' );
	}

	private function render_calendar_month_view( \DateTime $first_day, $dates ) {
		$month_title = date_i18n( 'F', $first_day->format( 'U' ) );
		$month       = (int) $first_day->format( 'n' );
		$days        = (int) $first_day->format( 't' );
		$start       = (int) get_option( 'start_of_week', 1 );
		$offset      = ( (int) $first_day->format( 'w' ) - $start + 7 ) % 7;
		$previous    = clone $first_day;
		$next        = clone $first_day;
		$previous->modify( '-1 month' );
		$next->modify( '+1 month' );

		$return  = '<section class="familypedia-calendar-month" aria-label="' . esc_attr__( 'Family calendar month view', 'family-wiki' ) . '">';
		$return .= '<header class="familypedia-calendar-month__header">';
		$return .= '<div class="familypedia-calendar-month__nav-slot"><a class="familypedia-calendar-month__nav familypedia-calendar-month__nav--previous" href="' . esc_url( $this->get_calendar_month_url( $previous ) ) . '" aria-label="' . esc_attr__( 'Previous month', 'family-wiki' ) . '">&lsaquo;</a></div>';
		$return .= '<h2>' . esc_html( $month_title ) . '</h2>';
		$return .= '<div class="familypedia-calendar-month__nav-slot"><a class="familypedia-calendar-month__nav familypedia-calendar-month__nav--next" href="' . esc_url( $this->get_calendar_month_url( $next ) ) . '" aria-label="' . esc_attr__( 'Next month', 'family-wiki' ) . '">&rsaquo;</a></div>';
		$return .= '</header>';
		$return .= '<table>';
		$return .= '<thead><tr>';

		for ( $day = 0; $day < 7; $day++ ) {
			$weekday = ( $start + $day ) % 7;
			$return .= '<th scope="col">' . esc_html( $this->weekday_label( $weekday ) ) . '</th>';
		}

		$return .= '</tr></thead><tbody><tr>';

		for ( $blank = 0; $blank < $offset; $blank++ ) {
			$return .= '<td class="familypedia-calendar-month__empty"></td>';
		}

		for ( $day = 1; $day <= $days; $day++ ) {
			$month_day = sprintf( '%02d-%02d', $month, $day );
			$events    = isset( $dates[ $month_day ] ) ? $dates[ $month_day ] : array();

			if ( 0 === ( $offset + $day - 1 ) % 7 && 1 !== $day ) {
				$return .= '</tr><tr>';
			}

			$return .= $this->render_calendar_day( $day, $events, $first_day );
		}

		$remaining = ( 7 - ( ( $offset + $days ) % 7 ) ) % 7;
		for ( $blank = 0; $blank < $remaining; $blank++ ) {
			$return .= '<td class="familypedia-calendar-month__empty"></td>';
		}

		$return .= '</tr></tbody></table>';
		$return .= '</section>';

		return $return;
	}

	private function get_calendar_month_url( \DateTime $date ) {
		return home_url( sprintf( '/%s/calendar/%02d/', App::URL_PATH, (int) $date->format( 'm' ) ) );
	}

	private function render_calendar_day( $day, $events, \DateTime $month_date ) {
		$day_date = clone $month_date;
		$day_date->setDate( (int) $month_date->format( 'Y' ), (int) $month_date->format( 'm' ), $day );
		$classes = array( 'familypedia-calendar-month__day' );
		if ( empty( $events ) ) {
			$classes[] = 'familypedia-calendar-month__day--empty';
		}

		$return  = '<td id="' . esc_attr( self::get_day_anchor( $day_date ) ) . '" class="' . esc_attr( implode( ' ', $classes ) ) . '">';
		$return .= '<span class="familypedia-calendar-month__date">' . esc_html( $day ) . '</span>';

		if ( empty( $events ) ) {
			$return .= '</td>';
			return $return;
		}

		$return .= '<ul class="familypedia-calendar-month__events">';
		foreach ( $events as $event ) {
			$return .= '<li title="' . esc_attr( wp_strip_all_tags( $event['text'] ) ) . '">' . wp_kses_post( $this->compact_event_text( $event ) ) . '</li>';
		}
		$return .= '</ul>';
		$return .= '</td>';

		return $return;
	}

	private function compact_event_text( $event ) {
		if ( 'married' === $event['type'] ) {
			$marker = '&hearts;';
		} elseif ( 'born' === $event['type'] ) {
			$marker = '*';
		} else {
			$marker = '&dagger;';
		}

		return sprintf(
			'%1$s %2$s%3$s',
			$event['person'],
			$marker,
			'<abbr class="familypedia-calendar-month__event-year" title="' . esc_attr( wp_strip_all_tags( $event['text'] ) ) . '">' . esc_html( $event['date']->format( 'Y' ) ) . '</abbr>'
		);
	}

	private function weekday_label( $weekday ) {
		$timestamp = strtotime( 'Sunday +' . (int) $weekday . ' days' );

		return date_i18n( 'D', $timestamp );
	}

	public function render_birthday_calendar() {
		// Calendars are live views; omit them from static archive snapshots.
		if ( doing_filter( 'static_archive_post_html' ) || doing_filter( 'static_archive_post_markdown' ) ) {
			return '';
		}

		$dates      = $this->get_dates();
		$last_month = 0;
		$return     = '';

		foreach ( $dates as $date => $people ) {
			foreach ( $people as $person ) {
				if ( 'born' !== $person['type'] || $person['dead'] ) {
					continue;
				}

				$month = strtok( $date, '-' );
				if ( $month !== $last_month ) {
					if ( $return ) {
						$return .= '</ul>';
					}
					$m          = date_i18n( 'F', $person['date']->format( 'U' ) );
					$return    .= '<h4 id="' . esc_attr( self::get_month_anchor( $person['date'] ) ) . '">' . esc_html( $m ) . '</h4><ul>';
					$last_month = $month;
				}
				$return .= '<li>' . esc_html( date_i18n( 'jS', $person['date']->format( 'U' ) ) ) . ': ' . wp_kses_post( $person['person'] ) . ' ' . esc_html( $person['age'] ) . '.</li>';
			}
		}

		if ( $return ) {
			$return .= '</ul>';
		}

		return $return;
	}

	/**
	 * Structured event data for API consumers such as AI Assistant, rather than
	 * the HTML the calendar views render.
	 *
	 * @param int      $days  How many days ahead to include.
	 * @param string[] $types Event types to include: born, died, married.
	 * @return array[] Events sorted by the date they next occur.
	 */
	public function get_upcoming_events( $days = 30, $types = array( 'born', 'died', 'married' ) ) {
		$types  = array_intersect( array( 'born', 'died', 'married' ), (array) $types );
		$dates  = $this->get_dates();
		$today  = new \DateTime( 'today' );
		$window = ( clone $today )->modify( '+' . max( 0, (int) $days ) . ' days' );
		$events = array();

		foreach ( $dates as $month_day => $day_events ) {
			foreach ( $day_events as $event ) {
				if ( ! in_array( $event['type'], $types, true ) ) {
					continue;
				}

				list( $month, $day ) = explode( '-', $month_day );
				$occurs_on            = \DateTime::createFromFormat( 'Y-m-d', $today->format( 'Y' ) . '-' . $month . '-' . $day );
				if ( ! $occurs_on ) {
					continue;
				}
				if ( $occurs_on < $today ) {
					$occurs_on->modify( '+1 year' );
				}
				if ( $occurs_on > $window ) {
					continue;
				}

				$events[] = array(
					'type'          => $event['type'],
					'person_id'     => (int) $event['ID'],
					'label'         => wp_strip_all_tags( $event['text'] ),
					'occurs_on'     => $occurs_on->format( 'Y-m-d' ),
					'original_date' => $event['date']->format( 'Y-m-d' ),
				);
			}
		}

		usort(
			$events,
			function ( $a, $b ) {
				return strcmp( $a['occurs_on'], $b['occurs_on'] );
			}
		);

		return $events;
	}
}
