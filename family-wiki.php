<?php
/**
 * Plugin Name: Familypedia
 * Plugin URI: https://github.com/akirk/family-wiki
 * Description: Like Wikipedia, but private and just for your family — stories and photos for every relative, compatible with other family tree apps via GEDCOM.
 * Version: 2.0.0+9a2884349c18
 * Requires at least: 6.0
 * Tested up to: 7.1
 * Requires PHP: 7.4
 * Author: Alex Kirk
 * Author URI: https://alex.kirk.at/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: family-wiki
 *
 * @package Familypedia
 */

namespace Family_Wiki;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/class-private-site.php';

// Autoloader for plugin classes.
spl_autoload_register(
	function ( $class_name ) {
		$prefix = 'Family_Wiki\\';
		$len    = strlen( $prefix );
		if ( strncmp( $prefix, $class_name, $len ) !== 0 ) {
			return;
		}

		$file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class_name, $len ) ) . '.php';
		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);

add_action(
	'plugins_loaded',
	function () {
		// Translations are loaded automatically since WordPress 6.7, and by
		// WordPress.org since 4.6, so load_plugin_textdomain() is not called.
		Save_Debug::maybe_start();
		new Private_Site();
		new Upgrade();
		$app = new App();
		$app->init();
	}
);

register_activation_hook( __FILE__, array( __NAMESPACE__ . '\Main', 'activate_plugin' ) );
add_action( 'activate_blog', array( __NAMESPACE__ . '\Main', 'activate_plugin' ) );
add_action( 'wp_initialize_site', array( __NAMESPACE__ . '\Main', 'activate_for_blog' ) );

register_deactivation_hook(
	__FILE__,
	function () {
		flush_rewrite_rules();
	}
);
