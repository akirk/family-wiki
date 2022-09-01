<?php
namespace Family_Wiki;

class Main {
	public function __construct() {
		new Calendar();
		new Shortcodes();
		new Private_Site();

		\add_action( 'template_redirect', array( $this, 'template_redirect' ) );
		\add_action( 'the_content', array( $this, 'the_content' ) );

		register_activation_hook( __FILE__, array( $this, 'activate_plugin' ) );

	}

	public function template_redirect() {
		if ( is_404() && current_user_can( 'edit_pages' ) ) {
			\add_action( 'admin_bar_menu', array( $this, 'admin_bar' ), 139 );
		}
	}

	public function admin_bar( \WP_Admin_Bar $wp_menu ) {
		$title = mb_convert_case( trim( strtr( urldecode( $_SERVER['REQUEST_URI'] ), '/_-', '   ' ) ), MB_CASE_TITLE );
		$wp_menu->add_menu(
			array(
				'id'     => 'create-page-title',
				'title'  => 'Create "' . $title . '"',
				'href'   => \self_admin_url( 'post-new.php?post_type=page&post_title=' . urlencode( $title ) ),
			)
		);
	}

	public function the_content( $content ) {
		static $all_pages;
		if ( ! isset( $all_pages ) ) {
			$p = get_posts( array( 'post_type' => 'page', 'post_status' => 'published', 'posts_per_page' => -1, 'fields' => 'post_name' ) );
			$all_pages = array();
			foreach ( $p as $page ) {
				$all_pages[ $page->post_name ] = $page->ID;
			}
		}
		$content = preg_replace_callback( '/<a .*?href="([^"]+)"/i', function( $m ) use ( $all_pages ) {
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
		}, $content );
		return $content;
	}

	public function activate_plugin() {
		$wiki_user = get_role( 'wiki-user' );
		if ( ! $wiki_user ) {
			$wiki_user = add_role( 'wiki-user', 'Wiki User' );
		}
		$wiki_user->add_cap( 'edit_pages' );
		$wiki_user->add_cap( 'edit_others_pages' );
		$wiki_user->add_cap( 'edit_published_pages' );
		$wiki_user->add_cap( 'publish_pages' );
		$wiki_user->add_cap( 'edit_files' );
		$wiki_user->add_cap( 'upload_files' );
		$wiki_user->add_cap( 'read' );
		$wiki_user->add_cap( 'wiki-user' );
		$wiki_user->add_cap( 'family-wiki' );
		$wiki_user->add_cap( 'level_0' );

		$wiki_editor = get_role( 'wiki-editor' );
		if ( ! $wiki_editor ) {
			$wiki_editor = add_role( 'wiki-editor', 'Wiki Editor' );
		}
		$wiki_editor->add_cap( 'edit_pages' );
		$wiki_editor->add_cap( 'edit_others_pages' );
		$wiki_editor->add_cap( 'edit_published_pages' );
		$wiki_editor->add_cap( 'publish_pages' );
		$wiki_editor->add_cap( 'delete_pages' );
		$wiki_editor->add_cap( 'delete_others_pages' );
		$wiki_editor->add_cap( 'delete_published_pages' );
		$wiki_editor->add_cap( 'edit_files' );
		$wiki_editor->add_cap( 'upload_files' );
		$wiki_editor->add_cap( 'read' );
		$wiki_editor->add_cap( 'wiki-editor' );
		$wiki_editor->add_cap( 'family-wiki' );
		$wiki_editor->add_cap( 'level_0' );
	}


}
