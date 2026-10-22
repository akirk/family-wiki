<?php
namespace Family_Wiki;

/** Optional homepage alias; the app keeps its own URL and identity. */
class Homepage {
	const OPTION = 'family_wiki_member_homepage';

	public function __construct() {
		add_action( 'admin_init', array( $this, 'register_setting' ) );
		add_action( 'template_redirect', array( $this, 'route' ), 0 );
	}

	public function register_setting() {
		register_setting(
			Settings::PAGE,
			self::OPTION,
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
			)
		);
		add_settings_section( 'family_wiki_homepage', __( 'Member homepage', 'family-wiki' ), '__return_false', Settings::PAGE );
		add_settings_field( self::OPTION, __( 'Homepage', 'family-wiki' ), array( $this, 'field' ), Settings::PAGE, 'family_wiki_homepage' );
	}

	public function field() {
		echo '<input type="hidden" name="' . esc_attr( self::OPTION ) . '" value="0" />';
		echo '<label><input type="checkbox" name="' . esc_attr( self::OPTION ) . '" value="1" ' . checked( get_option( self::OPTION ), true, false ) . ' /> ';
		esc_html_e( 'Show the wiki at the homepage for signed-in wiki members.', 'family-wiki' );
		echo '</label>';
	}

	public static function enabled_for_user() {
		return get_option( self::OPTION, false ) && is_user_logged_in() && current_user_can( 'edit_others_pages' );
	}

	public function route() {
		global $wp;
		if ( ! self::enabled_for_user() || '' !== trim( $wp->request, '/' ) || ! is_front_page() || is_feed() || is_preview() || is_paged() || is_embed() || is_search() ) {
			return;
		}
		$apps = \WpApp\Registry::get_apps();
		if ( ! isset( $apps[ App::URL_PATH ] ) ) {
			return;
		}
		nocache_headers();
		status_header( 200 );
		set_query_var( 'wp_app_path', App::URL_PATH );
		set_query_var( 'wp_app_request', '' );
		$apps[ App::URL_PATH ]->handle_app_request_directly( '' );
		exit;
	}
}
