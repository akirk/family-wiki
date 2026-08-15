<?php
namespace Family_Wiki;

class Tree_Block {
	const BLOCK_NAME = 'family-wiki/tree';

	/**
	 * People already expanded in this request, so that a later tree block on the
	 * same page links back instead of repeating a subtree.
	 *
	 * @var array
	 */
	private static $expanded = array();

	public function __construct() {
		add_action( 'init', array( $this, 'register_block' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	public function register_block() {
		wp_register_script(
			'family-wiki-tree-block',
			plugin_dir_url( __FILE__ ) . 'family-wiki-tree-block.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-api-fetch', 'wp-i18n' ),
			filemtime( plugin_dir_path( __FILE__ ) . 'family-wiki-tree-block.js' ),
			true
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'family-wiki-tree-block', 'family-wiki' );
		}

		wp_register_style(
			'family-wiki-tree-block',
			plugin_dir_url( __FILE__ ) . 'family-wiki-tree-block.css',
			array(),
			filemtime( plugin_dir_path( __FILE__ ) . 'family-wiki-tree-block.css' )
		);

		register_block_type(
			self::BLOCK_NAME,
			array(
				'api_version'     => 3,
				'title'           => __( 'Family Tree', 'family-wiki' ),
				'description'     => __( 'A descendant outline starting from one person.', 'family-wiki' ),
				'category'        => 'widgets',
				'icon'            => 'networking',
				'editor_script'   => 'family-wiki-tree-block',
				'style'           => 'family-wiki-tree-block',
				'attributes'      => array(
					'root'        => array(
						'type'    => 'number',
						'default' => 0,
					),
					'maxDepth'    => array(
						'type'    => 'number',
						'default' => 0,
					),
					'expandFully' => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'showDates'   => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
				'render_callback' => array( $this, 'render' ),
			)
		);
	}

	public function register_rest_routes() {
		register_rest_route(
			'family-wiki/v1',
			'/people',
			array(
				'methods'             => 'GET',
				'permission_callback' => function () {
					return current_user_can( 'edit_pages' );
				},
				'callback'            => array( $this, 'rest_people' ),
			)
		);
	}

	/**
	 * People that can root a tree, most descendants first so likely branch
	 * starting points surface at the top of the picker.
	 */
	public function rest_people() {
		$people = self::get_people();
		$out    = array();

		foreach ( $people as $id => $person ) {
			if ( ! self::has_family_data( $person ) ) {
				continue;
			}

			$out[] = array(
				'id'          => $id,
				'title'       => $person['title'],
				'years'       => self::year_range( $person ),
				'descendants' => self::count_descendants( $id ) - 1,
				'rootish'     => empty( $person['parents'] ) && ! $person['father_name'] && ! $person['mother_name'],
			);
		}

		usort(
			$out,
			function ( $a, $b ) {
				if ( $a['descendants'] === $b['descendants'] ) {
					return strcasecmp( $a['title'], $b['title'] );
				}

				return $b['descendants'] - $a['descendants'];
			}
		);

		return $out;
	}

	public function render( $attributes ) {
		$attributes = wp_parse_args(
			is_array( $attributes ) ? $attributes : array(),
			array(
				'root'        => 0,
				'maxDepth'    => 0,
				'expandFully' => false,
				'showDates'   => false,
			)
		);

		$root   = (int) $attributes['root'];
		$people = self::get_people();

		if ( ! $root || ! isset( $people[ $root ] ) ) {
			if ( ! current_user_can( 'edit_pages' ) ) {
				return '';
			}

			return '<div ' . get_block_wrapper_attributes( array( 'class' => 'family-wiki-tree' ) ) . '><p class="family-wiki-tree__notice">'
				. esc_html__( 'Family Tree: pick the person to start this branch from.', 'family-wiki' )
				. '</p></div>';
		}

		$options = array(
			'max_depth'    => max( 0, (int) $attributes['maxDepth'] ),
			'expand_fully' => (bool) $attributes['expandFully'],
			'show_dates'   => (bool) $attributes['showDates'],
		);

		$items = self::render_person( $root, 1, $options );

		return '<div ' . get_block_wrapper_attributes( array( 'class' => 'family-wiki-tree' ) ) . '><ul class="family-wiki-tree__list">' . $items . '</ul></div>';
	}

	private static function render_person( $id, $depth, $options ) {
		$people = self::get_people();
		if ( ! isset( $people[ $id ] ) ) {
			return '';
		}

		$person = $people[ $id ];

		if ( ! $options['expand_fully'] && isset( self::$expanded[ $id ] ) ) {
			return '<li class="family-wiki-tree__repeat">' . self::person_link( $id, $options )
				. ' <em class="family-wiki-tree__seen">' . esc_html__( '(see above)', 'family-wiki' ) . '</em></li>';
		}

		self::$expanded[ $id ] = true;

		$partner_ids = array();
		foreach ( $person['partners'] as $partner ) {
			if ( $partner['id'] ) {
				$partner_ids[ $partner['id'] ] = true;
				self::$expanded[ $partner['id'] ] = true;
			}
		}

		$line = self::person_link( $id, $options ) . self::partner_suffix( $id, $options );

		$children = self::household_children( $id, array_keys( $partner_ids ) );

		if ( empty( $children ) ) {
			return '<li>' . $line . '</li>';
		}

		if ( $options['max_depth'] && $depth >= $options['max_depth'] ) {
			$remaining = self::count_descendants( $id ) - 1;

			return '<li>' . $line . ' <a class="family-wiki-tree__more" href="' . esc_url( $person['url'] ) . '">'
				. esc_html(
					sprintf(
						// translators: %d is a number of descendants.
						_n( '… %d more', '… %d more', $remaining, 'family-wiki' ),
						$remaining
					)
				)
				. '</a></li>';
		}

		$out = '<li>' . $line . '<ul>';
		foreach ( $children as $child_id ) {
			$out .= self::render_person( $child_id, $depth + 1, $options );
		}
		$out .= '</ul></li>';

		return $out;
	}

	/**
	 * Children of a person and of their partners, deduplicated and oldest first.
	 */
	private static function household_children( $id, $partner_ids ) {
		$people   = self::get_people();
		$children = $people[ $id ]['children'];

		foreach ( $partner_ids as $partner_id ) {
			if ( isset( $people[ $partner_id ] ) ) {
				$children = array_merge( $children, $people[ $partner_id ]['children'] );
			}
		}

		$children = array_values(
			array_unique(
				array_filter(
					$children,
					function ( $child_id ) use ( $people ) {
						return isset( $people[ $child_id ] );
					}
				)
			)
		);

		usort(
			$children,
			function ( $a, $b ) use ( $people ) {
				$birth_a = $people[ $a ]['birth'] ? $people[ $a ]['birth'] : '9999';
				$birth_b = $people[ $b ]['birth'] ? $people[ $b ]['birth'] : '9999';
				if ( $birth_a === $birth_b ) {
					return strcasecmp( $people[ $a ]['title'], $people[ $b ]['title'] );
				}

				return strcmp( $birth_a, $birth_b );
			}
		);

		return $children;
	}

	private static function partner_suffix( $id, $options ) {
		$people  = self::get_people();
		$current = array();
		$past    = array();

		foreach ( $people[ $id ]['partners'] as $partner ) {
			$label = $partner['id'] && isset( $people[ $partner['id'] ] )
				? self::person_link( $partner['id'], $options )
				: esc_html( $partner['name'] );

			if ( ! $label ) {
				continue;
			}

			if ( $partner['ended'] ) {
				$past[] = $label;
			} else {
				$current[] = ( $partner['wed'] ? '⚭ ' : '&amp; ' ) . $label;
			}
		}

		$suffix = $current ? ' ' . implode( ' ', $current ) : '';

		if ( $past ) {
			$suffix .= ' (⚮ ' . implode( ', ', $past ) . ')';
		}

		return $suffix;
	}

	private static function person_link( $id, $options ) {
		$people = self::get_people();
		$person = $people[ $id ];
		$label  = '<a href="' . esc_url( $person['url'] ) . '">' . esc_html( $person['title'] ) . '</a>';

		if ( $options['show_dates'] ) {
			$years = self::year_range( $person );
			if ( $years ) {
				$label .= ' <span class="family-wiki-tree__years">(' . esc_html( $years ) . ')</span>';
			}
		}

		return $label;
	}

	private static function year_range( $person ) {
		if ( $person['birth'] && $person['death'] ) {
			return $person['birth'] . '–' . $person['death'];
		}
		if ( $person['birth'] ) {
			return '*' . $person['birth'];
		}
		if ( $person['death'] ) {
			return '†' . $person['death'];
		}

		return '';
	}

	private static function count_descendants( $id, &$seen = array() ) {
		$people = self::get_people();
		if ( isset( $seen[ $id ] ) || ! isset( $people[ $id ] ) ) {
			return 0;
		}

		$seen[ $id ] = true;
		$count       = 1;

		foreach ( $people[ $id ]['children'] as $child_id ) {
			$count += self::count_descendants( $child_id, $seen );
		}

		return $count;
	}

	private static function has_family_data( $person ) {
		return $person['parents'] || $person['children'] || $person['partners']
			|| $person['father_name'] || $person['mother_name']
			|| $person['birth'] || $person['death'] || $person['sex'];
	}

	/**
	 * Index of every person on this site, with relationships made symmetric.
	 */
	public static function get_people() {
		static $people;

		if ( isset( $people ) ) {
			return $people;
		}

		$cache_key = self::get_cache_key();
		$cached    = wp_cache_get( $cache_key, 'family-wiki' );
		if ( false === $cached ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				wp_cache_set( $cache_key, $cached, 'family-wiki', HOUR_IN_SECONDS );
			}
		}

		if ( is_array( $cached ) ) {
			$people = $cached;
			return $people;
		}

		$people = self::build_people();

		wp_cache_set( $cache_key, $people, 'family-wiki', HOUR_IN_SECONDS );
		set_transient( $cache_key, $people, HOUR_IN_SECONDS );

		return $people;
	}

	public static function flush_cache() {
		$cache_key = self::get_cache_key();
		wp_cache_delete( $cache_key, 'family-wiki' );
		delete_transient( $cache_key );
	}

	private static function get_cache_key() {
		return 'family_wiki_people_index_' . get_current_blog_id();
	}

	private static function build_people() {
		if ( ! function_exists( 'get_field' ) ) {
			return array();
		}

		$pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'post_parent'    => 0,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$people = array();
		$raw    = array();

		foreach ( $pages as $page ) {
			$id = $page->ID;

			$people[ $id ] = array(
				'title'       => get_the_title( $id ),
				'url'         => get_permalink( $id ),
				'sex'         => (string) get_field( 'sex', $id ),
				'birth'       => self::year( get_field( 'birth_date', $id ) ),
				'death'       => self::year( get_field( 'death_date', $id ) ),
				'father_name' => (string) get_field( 'father_name', $id ),
				'mother_name' => (string) get_field( 'mother_name', $id ),
				'parents'     => array(),
				'children'    => array(),
				'partners'    => array(),
			);

			$marriages = get_field( 'marriages', $id );

			$raw[ $id ] = array(
				'father'    => self::to_id( get_field( 'father', $id ) ),
				'mother'    => self::to_id( get_field( 'mother', $id ) ),
				'children'  => self::to_ids( get_field( 'children', $id ) ),
				'marriages' => is_array( $marriages ) ? $marriages : array(),
				// The legacy spouse field allows multiple selections.
				'spouse'    => self::to_ids( get_field( 'spouse', $id ) ),
				'spouse_nm' => (string) get_field( 'spouse_name', $id ),
			);
		}

		// Parent/child links are recorded from either end; make them symmetric.
		foreach ( $raw as $id => $fields ) {
			foreach ( array( $fields['father'], $fields['mother'] ) as $parent_id ) {
				if ( $parent_id && isset( $people[ $parent_id ] ) ) {
					$people[ $id ]['parents'][]           = $parent_id;
					$people[ $parent_id ]['children'][]   = $id;
				}
			}

			foreach ( $fields['children'] as $child_id ) {
				if ( isset( $people[ $child_id ] ) ) {
					$people[ $id ]['children'][]        = $child_id;
					$people[ $child_id ]['parents'][]   = $id;
				}
			}
		}

		foreach ( $people as $id => $person ) {
			$people[ $id ]['parents']  = array_values( array_unique( $person['parents'] ) );
			$people[ $id ]['children'] = array_values( array_unique( $person['children'] ) );
		}

		// Partners, from the marriages repeater and the legacy spouse fields.
		$partners = array();
		foreach ( $raw as $id => $fields ) {
			$partners[ $id ] = array();

			foreach ( $fields['marriages'] as $marriage ) {
				$spouse_id   = isset( $marriage['spouse'] ) ? (int) $marriage['spouse'] : 0;
				$spouse_name = isset( $marriage['spouse_name'] ) ? (string) $marriage['spouse_name'] : '';

				if ( ! $spouse_id && ! $spouse_name ) {
					continue;
				}

				$key = $spouse_id ? 'p' . $spouse_id : 'n' . $spouse_name;

				$partners[ $id ][ $key ] = array(
					'id'    => $spouse_id,
					'name'  => $spouse_name,
					'wed'   => true,
					'ended' => self::marriage_ended( $marriage ),
				);
			}

			foreach ( $fields['spouse'] as $spouse_id ) {
				if ( isset( $partners[ $id ][ 'p' . $spouse_id ] ) ) {
					continue;
				}

				$partners[ $id ][ 'p' . $spouse_id ] = array(
					'id'    => $spouse_id,
					'name'  => '',
					'wed'   => true,
					'ended' => false,
				);
			}

			if ( $fields['spouse_nm'] && ! isset( $partners[ $id ][ 'n' . $fields['spouse_nm'] ] ) ) {
				$partners[ $id ][ 'n' . $fields['spouse_nm'] ] = array(
					'id'    => 0,
					'name'  => $fields['spouse_nm'],
					'wed'   => true,
					'ended' => false,
				);
			}
		}

		// A marriage recorded on one page counts for the spouse too.
		foreach ( $partners as $id => $rows ) {
			foreach ( $rows as $row ) {
				if ( ! $row['id'] || ! isset( $partners[ $row['id'] ] ) ) {
					continue;
				}
				if ( ! isset( $partners[ $row['id'] ][ 'p' . $id ] ) ) {
					$partners[ $row['id'] ][ 'p' . $id ] = array(
						'id'    => $id,
						'name'  => '',
						'wed'   => $row['wed'],
						'ended' => $row['ended'],
					);
				}
			}
		}

		// Co-parents without a recorded marriage still belong on the same line.
		foreach ( $people as $id => $person ) {
			foreach ( $person['children'] as $child_id ) {
				foreach ( $people[ $child_id ]['parents'] as $co_parent ) {
					if ( $co_parent === $id || isset( $partners[ $id ][ 'p' . $co_parent ] ) ) {
						continue;
					}

					$partners[ $id ][ 'p' . $co_parent ] = array(
						'id'    => $co_parent,
						'name'  => '',
						'wed'   => false,
						'ended' => false,
					);
				}
			}
		}

		foreach ( $partners as $id => $rows ) {
			$people[ $id ]['partners'] = array_values( $rows );
		}

		return $people;
	}

	private static function marriage_ended( $marriage ) {
		$reason = isset( $marriage['ended_reason'] ) ? $marriage['ended_reason'] : '';

		// A widowed spouse still belongs on the marriage line.
		return in_array( $reason, array( 'divorced', 'annulled', 'separated', 'ended' ), true );
	}

	private static function year( $value ) {
		$digits = preg_replace( '/\D/', '', (string) $value );

		return strlen( $digits ) >= 4 ? substr( $digits, 0, 4 ) : '';
	}

	private static function to_id( $value ) {
		if ( is_array( $value ) ) {
			$value = reset( $value );
		}

		if ( $value instanceof \WP_Post ) {
			return (int) $value->ID;
		}

		return is_numeric( $value ) ? (int) $value : 0;
	}

	private static function to_ids( $value ) {
		if ( empty( $value ) ) {
			return array();
		}

		if ( ! is_array( $value ) ) {
			$value = array( $value );
		}

		return array_values( array_filter( array_map( array( __CLASS__, 'to_id' ), $value ) ) );
	}
}
