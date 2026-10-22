<?php
/**
 * The person custom post type and its metadata.
 *
 * Family Wiki kept people in WordPress pages with Advanced Custom Fields.
 * Familypedia keeps them in a custom post type with plain post meta, so the
 * plugin has no dependency beyond WordPress itself. Person::field() and
 * Person::update() give the rest of the plugin the same shape ACF returned:
 * relationship fields hand back WP_Post objects, everything else scalars.
 *
 * @package Familypedia
 */

namespace Family_Wiki;

class Person {
	const POST_TYPE = 'familypedia_person';

	/**
	 * Fields holding a single related person, stored as a post ID.
	 */
	const SINGLE_PERSON_FIELDS = array( 'father', 'mother' );

	/**
	 * Fields holding several related people, stored as an array of post IDs.
	 */
	const MULTI_PERSON_FIELDS = array( 'children', 'spouse' );

	/**
	 * Every meta key the plugin writes, with the sanitizer for its value.
	 */
	const FIELDS = array(
		'is_stub'                  => 'bool',
		'alive'                    => 'bool',
		'sex'                      => 'sex',
		'born_as'                  => 'text',
		'citizenships'             => 'textarea',
		'birth_date'               => 'date',
		'exact_birth_date_unknown' => 'bool',
		'birth_place'              => 'text',
		'death_date'               => 'date',
		'exact_death_date_unknown' => 'bool',
		'death_place'              => 'text',
		'father'                   => 'person',
		'father_name'              => 'text',
		'mother'                   => 'person',
		'mother_name'              => 'text',
		'children'                 => 'people',
		'marriages'                => 'marriages',
		'spouse'                   => 'people',
		'spouse_name'              => 'text',
		'marriage_date'            => 'date',
		'marriage_place'           => 'text',
	);

	public function __construct() {
		add_filter( 'post_type_link', array( __CLASS__, 'post_type_link' ), 10, 2 );
		add_filter( 'preview_post_link', array( __CLASS__, 'preview_post_link' ), 10, 2 );
		add_filter( 'post_row_actions', array( __CLASS__, 'post_row_actions' ), 10, 2 );
		add_filter( 'enter_title_here', array( __CLASS__, 'enter_title_here' ), 10, 2 );
	}

	/**
	 * The post type is not publicly queryable: people are rendered by the app's
	 * own templates, so WordPress must not serve them through the theme.
	 * Editing the wiki text still happens in the block editor in wp-admin.
	 *
	 * Capabilities map to the page capabilities so that the wiki roles, and
	 * WordPress's own Editor role, can edit people without further setup.
	 */
	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'               => _x( 'People', 'post type general name', 'family-wiki' ),
					'singular_name'      => _x( 'Person', 'post type singular name', 'family-wiki' ),
					'menu_name'          => _x( 'Familypedia', 'admin menu', 'family-wiki' ),
					'add_new'            => __( 'Add Person', 'family-wiki' ),
					'add_new_item'       => __( 'Add Person', 'family-wiki' ),
					'edit_item'          => __( 'Edit Person', 'family-wiki' ),
					'new_item'           => __( 'New Person', 'family-wiki' ),
					'view_item'          => __( 'View Person', 'family-wiki' ),
					'search_items'       => __( 'Search People', 'family-wiki' ),
					'not_found'          => __( 'No people found.', 'family-wiki' ),
					'not_found_in_trash' => __( 'No people found in Trash.', 'family-wiki' ),
					'all_items'          => __( 'People', 'family-wiki' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_nav_menus'   => false,
				'show_in_rest'        => true,
				'menu_icon'           => 'dashicons-groups',
				'menu_position'       => 21,
				'hierarchical'        => true,
				'capability_type'     => 'page',
				'map_meta_cap'        => true,
				'supports'            => array( 'title', 'editor', 'thumbnail', 'revisions', 'page-attributes', 'custom-fields' ),
				'rewrite'             => false,
				'query_var'           => false,
				'has_archive'         => false,
			)
		);
	}

	/**
	 * Meta is registered for sanitization and so that other code can see the
	 * schema, but not exposed over REST: this is data about living people on a
	 * site that is usually private.
	 */
	public static function register_meta() {
		foreach ( self::FIELDS as $field => $type ) {
			register_post_meta(
				self::POST_TYPE,
				$field,
				array(
					'single'            => true,
					'type'              => in_array( $type, array( 'people', 'marriages' ), true ) ? 'array' : 'string',
					'show_in_rest'      => false,
					'sanitize_callback' => function ( $value ) use ( $type ) {
						return self::sanitize( $value, $type );
					},
					'auth_callback'     => function ( $allowed, $meta_key, $post_id ) {
						return current_user_can( 'edit_post', $post_id );
					},
				)
			);
		}
	}

	/**
	 * A person's URL inside the app. Filtering post_type_link means every
	 * get_permalink() call in the plugin, and in the block editor, points here.
	 */
	public static function post_type_link( $url, $post ) {
		if ( ! $post instanceof \WP_Post || self::POST_TYPE !== $post->post_type ) {
			return $url;
		}

		return self::url( $post );
	}

	public static function preview_post_link( $url, $post ) {
		if ( ! $post instanceof \WP_Post || self::POST_TYPE !== $post->post_type ) {
			return $url;
		}

		return self::url( $post );
	}

	/**
	 * The wp-admin list table's "View" link is built before post_type_link runs
	 * for non-public types, so it needs adding back.
	 */
	public static function post_row_actions( $actions, $post ) {
		if ( self::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
			return $actions;
		}

		$actions['view'] = sprintf(
			'<a href="%1$s" rel="bookmark">%2$s</a>',
			esc_url( self::url( $post ) ),
			esc_html__( 'View', 'family-wiki' )
		);

		$actions['familypedia-facts'] = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( self::edit_url( $post ) ),
			esc_html__( 'Edit facts', 'family-wiki' )
		);

		return $actions;
	}

	public static function enter_title_here( $text, $post ) {
		if ( $post instanceof \WP_Post && self::POST_TYPE === $post->post_type ) {
			return __( "Person's name", 'family-wiki' );
		}

		return $text;
	}

	/**
	 * A person's page in the app.
	 *
	 * @param int|\WP_Post $person Person post or ID.
	 */
	public static function url( $person ) {
		$post = get_post( $person );
		if ( ! $post ) {
			return home_url( '/' . App::URL_PATH . '/' );
		}

		return home_url( '/' . App::URL_PATH . '/' . self::path( $post ) . '/' );
	}

	public static function edit_url( $person ) {
		$post = get_post( $person );
		if ( ! $post ) {
			return home_url( '/' . App::URL_PATH . '/' );
		}

		return home_url( '/' . App::URL_PATH . '/' . self::path( $post ) . '/edit/' );
	}

	/**
	 * A person's path within the app, including any parent for related pages.
	 */
	public static function path( $person ) {
		$post = get_post( $person );
		if ( ! $post ) {
			return '';
		}

		$path   = array( $post->post_name );
		$parent = (int) $post->post_parent;
		$seen   = array( (int) $post->ID => true );

		while ( $parent && empty( $seen[ $parent ] ) ) {
			$seen[ $parent ] = true;
			$parent_post     = get_post( $parent );
			if ( ! $parent_post || self::POST_TYPE !== $parent_post->post_type ) {
				break;
			}
			array_unshift( $path, $parent_post->post_name );
			$parent = (int) $parent_post->post_parent;
		}

		return implode( '/', $path );
	}

	/**
	 * Look up a person by the path used in the app URL.
	 *
	 * @param string $path One or more slugs, as in "person-name/related-page".
	 * @return \WP_Post|null
	 */
	public static function get_by_path( $path ) {
		$path = trim( (string) $path, '/' );
		if ( '' === $path ) {
			return null;
		}

		$post = get_page_by_path( $path, OBJECT, self::POST_TYPE );
		if ( $post instanceof \WP_Post ) {
			return $post;
		}

		// A related page can also be reached by its own slug, but only on its
		// own: a wrong parent in the path is a wrong URL, not a shortcut.
		if ( false !== strpos( $path, '/' ) ) {
			return null;
		}

		$posts = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'name'           => $path,
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => 1,
			)
		);

		return empty( $posts ) ? null : $posts[0];
	}

	/**
	 * Read a person field, in the shape the rest of the plugin expects:
	 * single relationships as a WP_Post, lists as arrays of WP_Post.
	 *
	 * @param string   $field   Field name.
	 * @param int|null $post_id Person, defaults to the current one.
	 */
	public static function field( $field, $post_id = null ) {
		if ( null === $post_id ) {
			$post_id = get_the_ID();
		}
		if ( $post_id instanceof \WP_Post ) {
			$post_id = $post_id->ID;
		}
		if ( ! $post_id ) {
			return '';
		}

		$value = get_post_meta( $post_id, $field, true );

		if ( in_array( $field, self::SINGLE_PERSON_FIELDS, true ) ) {
			$related = self::to_post( $value );

			return $related ? $related : false;
		}

		if ( in_array( $field, self::MULTI_PERSON_FIELDS, true ) ) {
			return self::to_posts( $value );
		}

		if ( 'marriages' === $field ) {
			return is_array( $value ) ? array_values( $value ) : array();
		}

		return $value;
	}

	/**
	 * Write a person field, accepting post objects or IDs for relationships.
	 */
	public static function update( $field, $value, $post_id ) {
		if ( $post_id instanceof \WP_Post ) {
			$post_id = $post_id->ID;
		}
		if ( ! $post_id ) {
			return;
		}

		$type  = isset( self::FIELDS[ $field ] ) ? self::FIELDS[ $field ] : 'text';
		$value = self::sanitize( $value, $type );

		if ( '' === $value || array() === $value ) {
			delete_post_meta( $post_id, $field );
			return;
		}

		update_post_meta( $post_id, $field, $value );
	}

	public static function sanitize( $value, $type ) {
		switch ( $type ) {
			case 'bool':
				return $value ? 1 : '';

			case 'sex':
				return in_array( $value, array( 'Male', 'Female', 'Unknown' ), true ) ? $value : '';

			case 'date':
				return self::normalize_date( $value );

			case 'textarea':
				return sanitize_textarea_field( (string) $value );

			case 'person':
				$id = self::to_id( $value );
				return $id ? (string) $id : '';

			case 'people':
				return self::to_ids( $value );

			case 'marriages':
				return self::sanitize_marriages( $value );

			default:
				return sanitize_text_field( (string) $value );
		}
	}

	/**
	 * Dates are kept as Y-m-d. GEDCOM and the legacy marriage rows also produce
	 * the compact Ymd form, which is accepted and expanded.
	 */
	public static function normalize_date( $date ) {
		$date = trim( sanitize_text_field( (string) $date ) );
		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date ) ) {
			return $date;
		}
		if ( preg_match( '/^(\d{4})(\d{2})(\d{2})$/', $date, $matches ) ) {
			return $matches[1] . '-' . $matches[2] . '-' . $matches[3];
		}
		if ( preg_match( '/^(\d{4})$/', $date ) ) {
			return $date . '-01-01';
		}

		return '';
	}

	public static function sanitize_marriages( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$reasons = array_keys( self::ended_reason_choices() );
		$rows    = array();

		foreach ( $value as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$row = array(
				'spouse'         => isset( $row['spouse'] ) ? self::to_id( $row['spouse'] ) : 0,
				'spouse_name'    => isset( $row['spouse_name'] ) ? sanitize_text_field( $row['spouse_name'] ) : '',
				'marriage_date'  => isset( $row['marriage_date'] ) ? self::normalize_date( $row['marriage_date'] ) : '',
				'marriage_year'  => isset( $row['marriage_year'] ) ? self::normalize_year( $row['marriage_year'] ) : '',
				'marriage_place' => isset( $row['marriage_place'] ) ? sanitize_text_field( $row['marriage_place'] ) : '',
				'ended_date'     => isset( $row['ended_date'] ) ? self::normalize_date( $row['ended_date'] ) : '',
				'ended_year'     => isset( $row['ended_year'] ) ? self::normalize_year( $row['ended_year'] ) : '',
				'ended_reason'   => isset( $row['ended_reason'] ) && in_array( $row['ended_reason'], $reasons, true ) ? $row['ended_reason'] : '',
			);

			if ( array_filter( $row ) ) {
				$rows[] = $row;
			}
		}

		return $rows;
	}

	public static function normalize_year( $year ) {
		$year = absint( $year );
		if ( $year < 1 || $year > 9999 ) {
			return '';
		}

		return (string) $year;
	}

	public static function ended_reason_choices() {
		return array(
			''          => '',
			'divorced'  => __( 'Divorced', 'family-wiki' ),
			'widowed'   => __( 'Widowed', 'family-wiki' ),
			'annulled'  => __( 'Annulled', 'family-wiki' ),
			'separated' => __( 'Separated', 'family-wiki' ),
			'ended'     => __( 'Ended', 'family-wiki' ),
		);
	}

	public static function sex_choices() {
		return array(
			''        => __( 'Unspecified', 'family-wiki' ),
			'Male'    => __( 'Male', 'family-wiki' ),
			'Female'  => __( 'Female', 'family-wiki' ),
			'Unknown' => __( 'Unknown', 'family-wiki' ),
		);
	}

	public static function to_id( $value ) {
		if ( is_array( $value ) ) {
			$value = reset( $value );
		}
		if ( $value instanceof \WP_Post ) {
			return (int) $value->ID;
		}

		return is_numeric( $value ) ? (int) $value : 0;
	}

	public static function to_ids( $value ) {
		if ( empty( $value ) ) {
			return array();
		}
		if ( ! is_array( $value ) ) {
			$value = array( $value );
		}

		$ids = array();
		foreach ( $value as $item ) {
			$id = self::to_id( $item );
			if ( $id ) {
				$ids[ $id ] = $id;
			}
		}

		return array_values( $ids );
	}

	public static function to_post( $value ) {
		$id = self::to_id( $value );
		if ( ! $id ) {
			return null;
		}

		$post = get_post( $id );

		return ( $post instanceof \WP_Post && self::POST_TYPE === $post->post_type ) ? $post : null;
	}

	public static function to_posts( $value ) {
		$posts = array();
		foreach ( self::to_ids( $value ) as $id ) {
			$post = self::to_post( $id );
			if ( $post ) {
				$posts[] = $post;
			}
		}

		return $posts;
	}

	/**
	 * Every person on the site, published only, title order.
	 *
	 * @return \WP_Post[]
	 */
	public static function get_all( $args = array() ) {
		return get_posts(
			array_merge(
				array(
					'post_type'      => self::POST_TYPE,
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'orderby'        => 'title',
					'order'          => 'ASC',
				),
				$args
			)
		);
	}

	/**
	 * Whether a person carries any of the recorded facts, which is what decides
	 * if they get an infobox or appear in an export.
	 */
	public static function has_data( $post_id ) {
		foreach ( array( 'born_as', 'citizenships', 'birth_date', 'birth_place', 'death_date', 'death_place', 'father', 'father_name', 'mother', 'mother_name', 'children', 'marriages', 'spouse', 'spouse_name', 'marriage_date', 'marriage_place' ) as $field ) {
			if ( get_post_meta( $post_id, $field, true ) ) {
				return true;
			}
		}

		return has_post_thumbnail( $post_id );
	}
}
