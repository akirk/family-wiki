<?php
/**
 * Plugin Name: Family Wiki
 * Text Domain: family-wiki
 */
namespace Family_Wiki;


require __DIR__ . '/class-calendar.php';
require __DIR__ . '/class-private-site.php';
require __DIR__ . '/class-shortcodes.php';

require __DIR__ . '/class-main.php';
new Main;
