<?php
namespace Family_Wiki;

class Main {
	public function __construct() {
		new Calendar();
		new Shortcodes();
		new Private_Site();

		add_action( 'template_redirect', array( $this, 'template_redirect' ) );
		add_action( 'the_content', array( $this, 'the_content' ), 100 );
		add_action( 'acf/settings/load_json', array( $this, 'acf_json_dir' ) );
		add_action( 'acf/settings/save_json', array( $this, 'acf_json_dir' ) );
	}

	public function acf_json_dir() {
		return __DIR__ . '/acf-json';
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
		static $all_pages;
		if ( ! isset( $all_pages ) ) {
			$p = get_posts(
				array(
					'post_type'      => 'page',
					'post_status'    => 'published',
					'posts_per_page' => -1,
					'fields'         => 'post_name',
				)
			);
			$all_pages = array();
			foreach ( $p as $page ) {
				$all_pages[ $page->post_name ] = $page->ID;
			}
		}
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

				if ( isset( $all_pages[ $p ] ) ) {
					return $m[0];
				}
				$l = strlen( $p );
				foreach ( array_keys( $all_pages ) as $k ) {
					if ( $p === substr( $k, 0, $l ) ) {
						return $m[0];
					}
				}
				if ( isset( $all_pages[ $p ] ) ) {
					return $m[0];
				}
				return $m[0] . ' style="color: #f00"';
			},
			$content
		);
		return $content;
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
