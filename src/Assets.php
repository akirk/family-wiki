<?php
/**
 * Paths and cache-busting versions for the plugin's own CSS and JavaScript.
 *
 * @package Familypedia
 */

namespace Family_Wiki;

class Assets {
	public static function dir( $file = '' ) {
		return dirname( __DIR__ ) . '/assets/' . ltrim( $file, '/' );
	}

	public static function url( $file = '' ) {
		return plugins_url( 'assets/' . ltrim( $file, '/' ), dirname( __DIR__ ) . '/family-wiki.php' );
	}

	/**
	 * The file's modification time, so an edited asset is picked up without a
	 * version bump. Falls back to the plugin version when the file is gone.
	 */
	public static function version( $file ) {
		$path = self::dir( $file );

		return file_exists( $path ) ? (string) filemtime( $path ) : App::VERSION;
	}

	/** Group headings also appear when the masterbar is shown in wp-admin. */
	public static function enqueue_menu_style() {
		if ( current_user_can( 'edit_others_pages' ) ) {
			wp_enqueue_style( 'familypedia-menu', self::url( 'menu.css' ), array(), self::version( 'menu.css' ) );
		}
	}

	/**
	 * Enqueue the app stylesheets into whichever WpApp is rendering.
	 *
	 * The content styles are a separate file because Static Archive inlines
	 * them on their own, without the app's page chrome.
	 */
	public static function enqueue_app_style() {
		wp_app_enqueue_style( 'familypedia-content', self::url( 'content.css' ), array(), self::version( 'content.css' ), App::URL_PATH );
		wp_app_enqueue_style( 'familypedia-menu', self::url( 'menu.css' ), array(), self::version( 'menu.css' ), App::URL_PATH );
		wp_app_enqueue_style( 'familypedia-app', self::url( 'app.css' ), array(), self::version( 'app.css' ), App::URL_PATH );
		wp_app_enqueue_style( 'familypedia-tree', self::url( 'tree.css' ), array(), self::version( 'tree.css' ), App::URL_PATH );

		if ( Settings::get_infobox_settings()['collapse_mobile'] ) {
			wp_app_enqueue_script( 'familypedia-infobox', self::url( 'infobox.js' ), array(), self::version( 'infobox.js' ), true, App::URL_PATH );
		}
	}

	/**
	 * Read one of the plugin's own asset files, for inlining.
	 *
	 * @return string Empty when the file is missing.
	 */
	public static function contents( $file ) {
		$path = self::dir( $file );
		if ( ! file_exists( $path ) ) {
			return '';
		}

		return (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}
}
