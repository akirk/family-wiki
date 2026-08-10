<?php
namespace Family_Wiki;

class Main {
	public function __construct() {
		new ACF_Field_Marriages_Loader();
		new Calendar();
		new Front_Page();
		new Infobox();
		new Shortcodes();
		new Private_Site();
		new Settings();

		load_plugin_textdomain( 'family-wiki' );

		add_action( 'template_redirect', array( $this, 'template_redirect' ) );
		add_action( 'the_content', array( $this, 'the_content' ), 100 );
		add_action( 'save_post_page', array( $this, 'flush_family_data_cache' ) );
		add_action( 'before_delete_post', array( $this, 'flush_family_data_cache' ) );
		add_action( 'trashed_post', array( $this, 'flush_family_data_cache' ) );
		add_action( 'untrashed_post', array( $this, 'flush_family_data_cache' ) );
		add_action( 'acf/save_post', array( $this, 'flush_family_data_cache' ), 20 );
		add_action( 'acf/settings/load_json', array( $this, 'acf_json_dir' ) );
		add_action( 'acf/settings/save_json', array( $this, 'acf_json_dir' ) );
	}

	public function acf_json_dir() {
		$dir = __DIR__ . '/acf-json';
		if ( file_exists( $dir . '/' . get_locale() ) ) {
			$dir .= '/' . get_locale();
		}
		return $dir;
	}

	public function template_redirect() {
		if ( is_404() && current_user_can( Private_Site::MINIMUM_CAPABILITY ) ) {
			add_action( 'admin_bar_menu', array( $this, 'admin_bar' ), 139 );
		}
	}

	public function admin_bar( \WP_Admin_Bar $wp_menu ) {
		$title = sanitize_title( mb_convert_case( trim( strtr( urldecode( $_SERVER['REQUEST_URI'] ), '/_-', '   ' ) ), MB_CASE_TITLE ) );
		$wp_menu->add_menu(
			array(
				'id'    => 'create-page-title',
				'title' => 'Create "' . $title . '"',
				'href'  => self_admin_url( 'post-new.php?post_type=page&post_title=' . urlencode( $title ) ),
			)
		);
	}

	public function the_content( $content ) {
		if ( is_admin() || ! is_singular( 'page' ) || ! in_the_loop() || ! is_main_query() || 'page' !== get_post_type() || false === stripos( $content, '<a ' ) ) {
			return $content;
		}

		$all_pages = $this->get_page_path_index();
		$content = preg_replace_callback(
			'/<a .*?href="([^"]+)"/i',
			function ( $m ) use ( $all_pages ) {
				$p = strtolower( $m[1] );
				if ( 0 === strpos( $p, home_url() ) ) {
					$p = substr( $p, strlen( home_url() ) );
				}
				if ( 0 === strpos( $p, '/wp-content' ) ) {
					return $m[0];
				}
				if ( false !== strpos( $p, '#' ) ) {
					$p = strtok( $p, '#' );
				}
				if ( 0 === strpos( $p, 'http://' ) || 0 === strpos( $p, 'https://' ) ) {
					return $m[0] . ' style="color: #090"';
				}

				$p = trim( $p, '/' );

				if ( Calendar::is_virtual_route( $p ) ) {
					return $m[0];
				}

				if ( isset( $all_pages[ $p ] ) ) {
					return $m[0];
				}
				$l = strlen( $p );
				foreach ( array_keys( $all_pages ) as $k ) {
					if ( substr( $k, 0, $l ) === $p ) {
						return $m[0];
					}
				}
				if ( isset( $all_pages[ $p ] ) ) {
					return $m[0];
				}
				$remote_page = Cross_Wiki::get_remote_page( $p );
				if ( $remote_page ) {
					return preg_replace( '/href="[^"]+"/i', 'href="' . esc_url( $remote_page['url'] ) . '"', $m[0], 1 ) . ' style="color: #090"';
				}
				return $m[0] . ' style="color: #f00"';
			},
			$content
		);
		return $content;
	}

	private function get_page_path_index() {
		static $all_pages;

		if ( isset( $all_pages ) ) {
			return $all_pages;
		}

		$cache_key = 'family_wiki_page_path_index_' . get_current_blog_id();
		$all_pages = wp_cache_get( $cache_key, 'family-wiki' );
		if ( false !== $all_pages && is_array( $all_pages ) ) {
			return $all_pages;
		}

		$all_pages = get_transient( $cache_key );
		if ( false !== $all_pages && is_array( $all_pages ) ) {
			wp_cache_set( $cache_key, $all_pages, 'family-wiki', HOUR_IN_SECONDS );
			return $all_pages;
		}

		$pages = get_posts(
			array(
				'post_type'              => 'page',
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		$all_pages = array();
		foreach ( $pages as $page_id ) {
			$slug               = get_post_field( 'post_name', $page_id );
			$all_pages[ $slug ] = $page_id;
			$all_pages[ get_page_uri( $page_id ) ] = $page_id;
		}

		wp_cache_set( $cache_key, $all_pages, 'family-wiki', HOUR_IN_SECONDS );
		set_transient( $cache_key, $all_pages, HOUR_IN_SECONDS );

		return $all_pages;
	}

	public function flush_family_data_cache( $post_id = null ) {
		if ( $post_id && 'page' !== get_post_type( $post_id ) ) {
			return;
		}

		$cache_key = 'family_wiki_page_path_index_' . get_current_blog_id();
		wp_cache_delete( $cache_key, 'family-wiki' );
		delete_transient( $cache_key );
		Calendar::flush_dates_cache();
		Front_Page::flush_cache();
	}

	public static function activate_plugin( $network_activate = null ) {
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			if ( $network_activate ) {
				// Only Super Admins can use Network Activate.
				if ( ! is_super_admin() ) {
					return;
				}

				// Activate for each site.
				foreach ( get_sites() as $blog ) {
					self::activate_for_blog( $blog->blog_id );
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
		self::upgrade_plugin();
		Calendar::register_rewrite_rules();
		flush_rewrite_rules();
	}

	public static function upgrade_plugin() {
	}

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
				$role = add_role( $type, $name, self::get_role_capabilities( $type ) );
				continue;
			}

			// This might update missing capabilities.
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

		$capabilities['wiki-editor'] = $capabilities['wiki-user'];
		$capabilities['wiki-editor'] = array(
			'delete_pages'           => true,
			'delete_others_pages'    => true,
			'delete_published_pages' => true,
		);

		// All roles belonging to this plugin have the friends_plugin capability.
		foreach ( array_keys( $capabilities ) as $type ) {
			$capabilities[ $type ]['family-wiki'] = true;
		}

		if ( ! isset( $capabilities[ $role ] ) ) {
			return array();
		}

		return $capabilities[ $role ];
	}
}
