<?php
/**
 * Plugin Name: Family Wiki
 * Plugin author: Alex Kirk
 * Plugin URI: https://github.com/akirk/family-wiki
 * Version: 1.1.9
 * Requires at least: 5.0
 * Requires PHP: 5.2.4
 *
 * Description: Keep your family history in a wiki hosted on WordPress.
 *
 * License: GPL2
 * Text Domain: family-wiki
 *
 * @package Family_Wiki
 */
namespace Family_Wiki;


require __DIR__ . '/class-calendar.php';
require __DIR__ . '/class-private-site.php';
require __DIR__ . '/class-shortcodes.php';

require __DIR__ . '/class-main.php';

add_action( 'upgrader_process_complete', array( __NAMESPACE__ . '\Main', 'upgrade_plugin' ) );
register_activation_hook( __FILE__, array( __NAMESPACE__ . '\Main', 'activate_plugin' ) );
add_action( 'activate_blog', array( __NAMESPACE__ . '\Main', 'activate_plugin' ) );
add_action( 'wp_initialize_site', array( __NAMESPACE__ . '\Main', 'activate_for_blog' ) );

new Main();
