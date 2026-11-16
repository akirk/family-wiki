<?php
/**
 * Administrator settings inside the Familypedia app.
 *
 * @package Familypedia
 */

namespace Family_Wiki;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$familypedia_page_title = __( 'Settings', 'family-wiki' );
if ( ! current_user_can( 'manage_options' ) ) {
	status_header( 403 );
	require __DIR__ . '/partials/header.php';
	?>
	<h1><?php esc_html_e( 'Settings', 'family-wiki' ); ?></h1>
	<p><?php esc_html_e( 'You do not have permission to manage settings.', 'family-wiki' ); ?></p>
	<?php
	require __DIR__ . '/partials/footer.php';
	return;
}

wp_app_enqueue_style( 'familypedia-settings', Assets::url( 'settings.css' ), array(), Assets::version( 'settings.css' ), App::URL_PATH );
wp_app_enqueue_script( 'familypedia-settings', Assets::url( 'settings.js' ), array(), Assets::version( 'settings.js' ), true, App::URL_PATH );
require __DIR__ . '/partials/header.php';
( new Settings() )->render_page();
require __DIR__ . '/partials/footer.php';
