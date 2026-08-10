<?php
namespace Family_Wiki;

class Calendar {
	const QUERY_VAR = 'family_wiki_view';
	const MONTH_QUERY_VAR = 'family_wiki_month';
	const MENU_ID = 'family-wiki';
	const VIEW_CALENDAR = 'calendar';
	const VIEW_BIRTHDAYS = 'birthdays';

	private $all_dates = null;

	public function __construct() {
		add_action( 'init', array( $this, 'init' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_editor_assets' ) );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar_menu' ), 79 );
		add_action( 'parse_request', array( $this, 'parse_request' ) );
		add_action( 'pre_get_posts', array( $this, 'pre_get_posts' ) );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_filter( 'the_title', array( $this, 'the_title' ), 10, 2 );
		add_filter( 'document_title_parts', array( $this, 'document_title_parts' ) );
		add_filter( 'the_posts', array( $this, 'the_posts' ), 10, 2 );
		add_filter( 'pre_handle_404', array( $this, 'pre_handle_404' ), 10, 2 );
	}

	public function init() {
		self::register_rewrite_rules();

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

	public function enqueue_styles() {
		if ( ! $this->is_calendar_route( get_query_var( self::QUERY_VAR ) ) && ! $this->get_view_from_request_path() ) {
			return;
		}

		wp_enqueue_style(
			'family-wiki-infobox',
			plugin_dir_url( __FILE__ ) . 'family-wiki.css',
			array(),
			filemtime( plugin_dir_path( __FILE__ ) . 'family-wiki.css' )
		);
	}

	public function enqueue_block_editor_assets() {
		wp_enqueue_script(
			'family-wiki-family-calendar',
			plugin_dir_url( __FILE__ ) . 'family-calendar.js',
			array( 'wp-blocks', 'wp-server-side-render' ),
			filemtime( plugin_dir_path( __FILE__ ) . 'family-calendar.js' ),
			true
		);
		wp_enqueue_script(
			'family-wiki-birthday-calendar',
			plugin_dir_url( __FILE__ ) . 'birthday-calendar.js',
			array( 'wp-blocks', 'wp-server-side-render' ),
			filemtime( plugin_dir_path( __FILE__ ) . 'birthday-calendar.js' ),
			true
		);
	}

	public static function register_rewrite_rules() {
		add_rewrite_rule( '^family-wiki/calendar/(0?[1-9]|1[0-2])/?$', 'index.php?' . self::QUERY_VAR . '=' . self::VIEW_CALENDAR . '&' . self::MONTH_QUERY_VAR . '=$matches[1]', 'top' );
		add_rewrite_rule( '^family-wiki/calendar/?$', 'index.php?' . self::QUERY_VAR . '=' . self::VIEW_CALENDAR, 'top' );
		add_rewrite_rule( '^family-wiki/birthdays/?$', 'index.php?' . self::QUERY_VAR . '=' . self::VIEW_BIRTHDAYS, 'top' );
	}

	public static function get_calendar_url( \DateTime $date = null ) {
		$url = home_url( '/family-wiki/calendar/' );

		if ( $date ) {
			$url  = home_url( sprintf( '/family-wiki/calendar/%02d/', (int) $date->format( 'm' ) ) );
			$url .= '#' . rawurlencode( self::get_day_anchor( $date ) );
		}

		return $url;
	}

	public static function get_month_anchor( \DateTime $date ) {
		return sanitize_title( date_i18n( 'F', $date->format( 'U' ) ) );
	}

	public static function get_day_anchor( \DateTime $date ) {
		return 'family-wiki-day-' . $date->format( 'm-d' );
	}

	public static function get_birthdays_url() {
		return home_url( '/family-wiki/birthdays/' );
	}

	public function query_vars( $query_vars ) {
		$query_vars[] = self::QUERY_VAR;
		$query_vars[] = self::MONTH_QUERY_VAR;

		return $query_vars;
	}

	public function admin_bar_menu( \WP_Admin_Bar $wp_admin_bar ) {
		if ( ! current_user_can( Private_Site::MINIMUM_CAPABILITY ) ) {
			return;
		}

		$wp_admin_bar->add_node(
			array(
				'id'    => self::MENU_ID,
				'title' => __( 'Family Wiki', 'family-wiki' ),
				'href'  => home_url( '/' ),
			)
		);

		$wp_admin_bar->add_node(
			array(
				'id'     => 'family-wiki-calendar',
				'parent' => self::MENU_ID,
				'title'  => __( 'Calendar', 'family-wiki' ),
				'href'   => self::get_calendar_url(),
			)
		);

		$wp_admin_bar->add_node(
			array(
				'id'     => 'family-wiki-birthdays',
				'parent' => self::MENU_ID,
				'title'  => __( 'Birthdays', 'family-wiki' ),
				'href'   => self::get_birthdays_url(),
			)
		);
	}

	public function the_title( $title, $post_id = null ) {
		if ( is_admin() || 0 !== (int) $post_id || self::VIEW_CALENDAR !== get_query_var( self::QUERY_VAR ) ) {
			return $title;
		}

		return '<a href="' . esc_url( self::get_calendar_url() ) . '">' . esc_html( $title ) . '</a>';
	}

	public function document_title_parts( $parts ) {
		$title = $this->get_route_title();
		if ( $title ) {
			$parts['title'] = $title;
		}

		return $parts;
	}

	public function parse_request( $wp ) {
		$route = $this->get_route_from_request_path();
		if ( empty( $route['view'] ) ) {
			return;
		}

		$wp->query_vars[ self::QUERY_VAR ] = $route['view'];
		if ( ! empty( $route['month'] ) ) {
			$wp->query_vars[ self::MONTH_QUERY_VAR ] = $route['month'];
		}
		unset( $wp->query_vars['error'] );
	}

	public function pre_get_posts( $query ) {
		if ( is_admin() || ! $query->is_main_query() || ! $this->is_calendar_route( $query->get( self::QUERY_VAR ) ) ) {
			return;
		}

		$query->is_home     = false;
		$query->is_page     = true;
		$query->is_singular = true;
		$query->is_404      = false;
	}

	public function the_posts( $posts, $query ) {
		if ( is_admin() || ! $query->is_main_query() || ! $this->is_calendar_route( $query->get( self::QUERY_VAR ) ) ) {
			return $posts;
		}

		$query->found_posts   = 1;
		$query->post_count    = 1;
		$query->max_num_pages = 1;

		return array( $this->get_virtual_post( $query->get( self::QUERY_VAR ) ) );
	}

	public function pre_handle_404( $preempt, $query ) {
		if ( $this->is_calendar_route( $query->get( self::QUERY_VAR ) ) ) {
			$query->is_404 = false;
			status_header( 200 );

			return true;
		}

		return $preempt;
	}

	private function get_virtual_post( $view ) {
		$content = self::VIEW_BIRTHDAYS === $view ? $this->render_birthday_calendar() : $this->render_family_calendar();

		$post = new \WP_Post(
			(object) array(
				'ID'                    => 0,
				'post_author'           => 0,
				'post_date'             => current_time( 'mysql' ),
				'post_date_gmt'         => current_time( 'mysql', true ),
				'post_content'          => $content,
				'post_title'            => $this->get_route_title( $view ),
				'post_excerpt'          => '',
				'post_status'           => 'publish',
				'comment_status'        => 'closed',
				'ping_status'           => 'closed',
				'post_password'         => '',
				'post_name'             => self::VIEW_BIRTHDAYS === $view ? 'birthdays' : 'calendar',
				'to_ping'               => '',
				'pinged'                => '',
				'post_modified'         => current_time( 'mysql' ),
				'post_modified_gmt'     => current_time( 'mysql', true ),
				'post_content_filtered' => '',
				'post_parent'           => 0,
				'guid'                  => self::VIEW_BIRTHDAYS === $view ? self::get_birthdays_url() : self::get_calendar_url(),
				'menu_order'            => 0,
				'post_type'             => 'page',
				'post_mime_type'        => '',
				'comment_count'         => 0,
				'filter'                => 'raw',
			)
		);

		return $post;
	}

	private function is_calendar_route( $view ) {
		return in_array( $view, array( self::VIEW_CALENDAR, self::VIEW_BIRTHDAYS ), true );
	}

	public static function is_virtual_route( $path ) {
		$path = trim( $path, '/' );

		return in_array( $path, array( 'family-wiki/calendar', 'family-wiki/birthdays' ), true ) || (bool) preg_match( '#^family-wiki/calendar/(0?[1-9]|1[0-2])$#', $path );
	}

	private function get_view_from_request_path() {
		$route = $this->get_route_from_request_path();

		return empty( $route['view'] ) ? '' : $route['view'];
	}

	private function get_route_from_request_path() {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return array();
		}

		$path      = wp_parse_url( sanitize_url( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH );
		$home_path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );

		if ( $home_path && 0 === strpos( $path, $home_path ) ) {
			$path = substr( $path, strlen( $home_path ) );
		}

		$path = trim( $path, '/' );

		if ( preg_match( '#^family-wiki/calendar/(0?[1-9]|1[0-2])$#', $path, $matches ) ) {
			return array(
				'view'  => self::VIEW_CALENDAR,
				'month' => $matches[1],
			);
		}

		if ( 'family-wiki/calendar' === $path ) {
			return array( 'view' => self::VIEW_CALENDAR );
		}

		if ( 'family-wiki/birthdays' === $path ) {
			return array( 'view' => self::VIEW_BIRTHDAYS );
		}

		return array();
	}

	private function get_route_title( $view = null ) {
		if ( ! $view ) {
			$view = get_query_var( self::QUERY_VAR );
		}

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
			$args = array(
				'post_type'      => 'page',
				'posts_per_page' => -1,
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
			);

			$p = get_posts( $args );
			$this->all_dates = array();
			$now = new \DateTime( 'now' );
			foreach ( $p as $page ) {
				$dates = array();
				try {
					if ( get_field( 'birth_date', $page->ID ) && ! get_field( 'exact_birth_date_unknown', $page->ID ) ) {
						$dates['born'] = new \DateTime( get_field( 'birth_date', $page->ID ) );
					}
				} catch ( \Exception $e ) {
				}
				try {
					if ( get_field( 'death_date', $page->ID ) && ! get_field( 'exact_death_date_unknown', $page->ID ) ) {
						$dates['died'] = new \DateTime( get_field( 'death_date', $page->ID ) );
					}
				} catch ( \Exception $e ) {
				}

				foreach ( $dates as $type => $date ) {
					$month_day = $date->format( 'm-d' );
					if ( ! isset( $this->all_dates[ $month_day ] ) ) {
						$this->all_dates[ $month_day ] = array();
					}
					$arr = array(
						'date'   => $date,
						'type'   => $type,
						'ID'     => $page->ID,
						'text'   => '<a href="/' . $page->post_name . '">' . $page->post_title . '</a> ',
						'person' => '<a href="/' . $page->post_name . '">' . $page->post_title . '</a>',
						'dead'   => ! get_field( 'alive', $page->ID ),
						'age'    => '',
					);
					$age = $date->diff( $now );

					if ( 'born' === $type ) {
						$arr['text'] = sprintf(
						// translators: %1$s is a name, %2%s is a date.
							__( '%1$s was born on %2$s', 'family-wiki' ),
							$arr['text'],
							date_i18n( get_option( 'date_format' ), $date->format( 'U' ) )
						);
						if ( get_field( 'alive', $page->ID ) ) {
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
							$arr['age'] = $age;
							$arr['text'] .= ' (' . $age . ')';
						} else {
							$age = $now->format( 'Y' ) - $date->format( 'Y' );
							// translators: %s is a number of years.
							$arr['text'] .= ' (' . sprintf( _n( '%d years ago', '%d years ago', $age, 'family-wiki' ), $age ) . ')';
						}
					} else {
						$arr['text'] = sprintf(
						// translators: %1$s is a name, %2%s is a date.
							__( '%1$s died on %2$s', 'family-wiki' ),
							$arr['text'],
							date_i18n( get_option( 'date_format' ), $date->format( 'U' ) )
						);
						$age = $now->format( 'Y' ) - $date->format( 'Y' );
						// translators: %s is a number of years.
						$arr['text'] .= ' (' . sprintf( _n( '%d years ago', '%d years ago', $age, 'family-wiki' ), $age ) . ')';
					}

					$this->all_dates[ $month_day ][] = $arr;
				}
			}
			ksort( $this->all_dates );
		}
		return $this->all_dates;
	}

	public function render_family_calendar() {
		$dates = $this->get_dates();
		if ( get_query_var( self::MONTH_QUERY_VAR ) ) {
			$month = $this->get_calendar_month_date();

			return $this->render_calendar_month_view( $month, $dates );
		}

		$month = $this->get_calendar_month_date();

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
					$m       = date_i18n( 'F', $person['date']->format( 'U' ) );
					$return .= '<h4 id="' . esc_attr( self::get_month_anchor( $person['date'] ) ) . '"><a href="' . esc_url( $this->get_calendar_month_url( $person['date'] ) ) . '">' . esc_html( $m ) . '</a></h4><ul>';
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

	private function get_calendar_month_date() {
		$month = absint( get_query_var( self::MONTH_QUERY_VAR ) );
		$year  = (int) current_time( 'Y' );
		if ( $month < 1 || $month > 12 ) {
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

		$return  = '<section class="family-wiki-calendar-month" aria-label="' . esc_attr__( 'Family calendar month view', 'family-wiki' ) . '">';
		$return .= '<header class="family-wiki-calendar-month__header">';
		$return .= '<div class="family-wiki-calendar-month__nav-slot"><a class="family-wiki-calendar-month__nav family-wiki-calendar-month__nav--previous" href="' . esc_url( $this->get_calendar_month_url( $previous ) ) . '" aria-label="' . esc_attr__( 'Previous month', 'family-wiki' ) . '">&lsaquo;</a></div>';
		$return .= '<h2>' . esc_html( $month_title ) . '</h2>';
		$return .= '<div class="family-wiki-calendar-month__nav-slot"><a class="family-wiki-calendar-month__nav family-wiki-calendar-month__nav--next" href="' . esc_url( $this->get_calendar_month_url( $next ) ) . '" aria-label="' . esc_attr__( 'Next month', 'family-wiki' ) . '">&rsaquo;</a></div>';
		$return .= '</header>';
		$return .= '<table>';
		$return .= '<thead><tr>';

		for ( $day = 0; $day < 7; $day++ ) {
			$weekday = ( $start + $day ) % 7;
			$return .= '<th scope="col">' . esc_html( $this->weekday_label( $weekday ) ) . '</th>';
		}

		$return .= '</tr></thead><tbody><tr>';

		for ( $blank = 0; $blank < $offset; $blank++ ) {
			$return .= '<td class="family-wiki-calendar-month__empty"></td>';
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
			$return .= '<td class="family-wiki-calendar-month__empty"></td>';
		}

		$return .= '</tr></tbody></table>';
		$return .= '</section>';

		return $return;
	}

	private function get_calendar_month_url( \DateTime $date ) {
		return home_url( sprintf( '/family-wiki/calendar/%02d/', (int) $date->format( 'm' ) ) );
	}

	private function render_calendar_day( $day, $events, \DateTime $month_date ) {
		$day_date = clone $month_date;
		$day_date->setDate( (int) $month_date->format( 'Y' ), (int) $month_date->format( 'm' ), $day );
		$classes = array( 'family-wiki-calendar-month__day' );
		if ( empty( $events ) ) {
			$classes[] = 'family-wiki-calendar-month__day--empty';
		}

		$return  = '<td id="' . esc_attr( self::get_day_anchor( $day_date ) ) . '" class="' . esc_attr( implode( ' ', $classes ) ) . '">';
		$return .= '<span class="family-wiki-calendar-month__date">' . esc_html( $day ) . '</span>';

		if ( empty( $events ) ) {
			$return .= '</td>';
			return $return;
		}

		$return .= '<ul class="family-wiki-calendar-month__events">';
		foreach ( $events as $event ) {
			$return .= '<li>' . wp_kses_post( $this->compact_event_text( $event ) ) . '</li>';
		}
		$return .= '</ul>';
		$return .= '</td>';

		return $return;
	}

	private function compact_event_text( $event ) {
		$marker = 'born' === $event['type'] ? '*' : '&dagger;';

		return sprintf(
			'%1$s %2$s%3$s',
			$event['person'],
			$marker,
			esc_html( $event['date']->format( 'Y' ) )
		);
	}

	private function weekday_label( $weekday ) {
		$timestamp = strtotime( 'Sunday +' . (int) $weekday . ' days' );

		return date_i18n( 'D', $timestamp );
	}

	public function render_birthday_calendar() {
		$dates = $this->get_dates();
		$last_month = 0;
		$return = '';

		foreach ( $dates as $date => $people ) {
			foreach ( $people as $person ) {
				if ( $person['dead'] ) {
					continue;
				}

				$month = strtok( $date, '-' );
				if ( $month !== $last_month ) {
					if ( $return ) {
						$return .= '</ul>';
					}
					$m = date_i18n( 'F', $person['date']->format( 'U' ) );
					$return .= '<h4 id="' . esc_attr( self::get_month_anchor( $person['date'] ) ) . '">' . esc_html( $m ) . '</h4><ul>';
					$last_month = $month;
				}
				$return .= '<li>' . date_i18n( 'jS', $person['date']->format( 'U' ) ) . ': ' . wp_kses_post( $person['person'] ) . ' ' . esc_html( $person['age'] ) . '.</li>';
			}
		}

		if ( $return ) {
			$return .= '</ul>';
		}

		return $return;
	}
}
