<?php
namespace Family_Wiki;

class Calendar {
	private $all_dates = null;

	public function __construct() {
		add_action( 'init', array( $this, 'init' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_editor_assets' ) );
	}

	public function init() {
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
		$last_month = 0;
		$return = '';

		foreach ( $dates as $date => $people ) {
			foreach ( $people as $person ) {
				$month = strtok( $date, '-' );
				if ( $month !== $last_month ) {
					if ( $return ) {
						$return .= '</ul>';
					}
					$m = date_i18n( 'F', $person['date']->format( 'U' ) );
					$return .= '<h4 id="' . $m . '">' . $m . '</h4><ul>';
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
					$return .= '<h4 id="' . $m . '">' . $m . '</h4><ul>';
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
