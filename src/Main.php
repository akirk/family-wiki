<?php
/**
 * Wiring that is not specific to one feature: the feature objects, the caches
 * they share, and the roles that let people edit.
 *
 * @package Familypedia
 */

namespace Family_Wiki;

class Main {
	public function __construct() {
		new Ai_Assistant();
		new Person();
		new Bio();
		new Calendar();
		new Editor();
		new Front_Page();
		new Gedcom();
		new Related_Page();
		new Settings();
		new Static_Archive();
		new Tree();
		new Wiki_Page();

		add_action( 'save_post_' . Person::POST_TYPE, array( __CLASS__, 'flush_family_data_cache' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'flush_family_data_cache' ) );
		add_action( 'trashed_post', array( __CLASS__, 'flush_family_data_cache' ) );
		add_action( 'untrashed_post', array( __CLASS__, 'flush_family_data_cache' ) );
		add_action( 'updated_post_meta', array( __CLASS__, 'flush_on_meta_change' ), 10, 3 );
		add_action( 'added_post_meta', array( __CLASS__, 'flush_on_meta_change' ), 10, 3 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'flush_on_meta_change' ), 10, 3 );
	}

	/**
	 * Everything cached about the family is derived from people and their meta,
	 * so any change to either drops the lot. Anything new that caches belongs
	 * here too.
	 */
	public static function flush_family_data_cache( $post_id = null ) {
		if ( $post_id && Person::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}

		Links::flush_cache();
		Calendar::flush_dates_cache();
		Highlights::flush_cache();
		Tree::flush_cache();
	}

	public static function flush_on_meta_change( $meta_id, $post_id, $meta_key ) {
		unset( $meta_id );

		if ( ! isset( Person::FIELDS[ $meta_key ] ) ) {
			return;
		}

		self::flush_family_data_cache( $post_id );
	}

	public static function activate_plugin( $network_activate = null ) {
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			if ( $network_activate ) {
				// Only Super Admins can use Network Activate.
				if ( ! is_super_admin() ) {
					return;
				}

				foreach ( get_sites() as $blog ) {
					switch_to_blog( (int) $blog->blog_id );
					self::setup();
					restore_current_blog();
				}
			} elseif ( current_user_can( 'activate_plugins' ) ) {
				self::setup();
			}

			return;
		}

		self::setup();
	}

	public static function activate_for_blog( $blog_id ) {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( $blog_id instanceof \WP_Site ) {
			$blog_id = (int) $blog_id->blog_id;
		}

		if ( is_plugin_active_for_network( 'family-wiki/family-wiki.php' ) ) {
			switch_to_blog( $blog_id );
			self::setup();
			restore_current_blog();
		}
	}

	public static function setup() {
		self::setup_roles();
		Person::register_post_type();
		Front_Page::register_post_type();
		Front_Page::ensure_post();
		flush_rewrite_rules();
	}

	/**
	 * The people post type uses the page capabilities, so these roles give the
	 * same access to people that Family Wiki's roles gave to wiki pages, and
	 * WordPress's own Editor role works without any further setup.
	 */
	public static function setup_roles() {
		$default_roles = array(
			'wiki-user'   => _x( 'Wiki User', 'User role', 'family-wiki' ),
			'wiki-editor' => _x( 'Wiki Editor', 'User role', 'family-wiki' ),
		);

		$roles = new \WP_Roles();

		foreach ( $default_roles as $type => $name ) {
			$role = false;
			foreach ( $roles->roles as $slug => $data ) {
				if ( isset( $data['capabilities'][ $type ] ) ) {
					$role = get_role( $slug );
					break;
				}
			}

			if ( ! $role ) {
				add_role( $type, $name, self::get_role_capabilities( $type ) );
				continue;
			}

			// This might add capabilities a previous version did not grant.
			foreach ( array_keys( self::get_role_capabilities( $type ) ) as $cap ) {
				$role->add_cap( $cap );
			}
		}
	}

	public static function get_role_capabilities( $role ) {
		$capabilities = array();

		$capabilities['wiki-user'] = array(
			'edit_pages'           => true,
			'edit_others_pages'    => true,
			'edit_published_pages' => true,
			'publish_pages'        => true,
			'edit_files'           => true,
			'upload_files'         => true,
			'read'                 => true,
		);

		$capabilities['wiki-editor'] = array_merge(
			$capabilities['wiki-user'],
			array(
				'delete_pages'           => true,
				'delete_others_pages'    => true,
				'delete_published_pages' => true,
			)
		);

		// All roles belonging to this plugin have the familypedia capability.
		foreach ( array_keys( $capabilities ) as $type ) {
			$capabilities[ $type ][ $type ]  = true;
			$capabilities[ $type ]['family-wiki'] = true;
		}

		if ( ! isset( $capabilities[ $role ] ) ) {
			return array();
		}

		return $capabilities[ $role ];
	}
}
