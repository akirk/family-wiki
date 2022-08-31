<?php
/**
 * Plugin Name: Family Wiki
 * Plugin author: Alex Kirk
 * Plugin URI: https://github.com/akirk/family-wiki
 * Version: 1.0.0
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
new Main;
