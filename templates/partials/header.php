<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template partial variables are provided by the parent template.
/**
 * Opening markup for every app page.
 *
 * Templates set $familypedia_page_title before including this.
 *
 * @package Familypedia
 */

namespace Family_Wiki;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! isset( $familypedia_page_title ) ) {
	$familypedia_page_title = '';
}

Assets::enqueue_app_style();

/*
 * The app prints its own title and viewport below. wp_head() would print a
 * second set: which of the two title callbacks is in play depends on whether
 * the site runs a block theme, so both are removed.
 */
remove_action( 'wp_head', '_wp_render_title_tag', 1 );
remove_action( 'wp_head', '_block_template_render_title_tag', 1 );
remove_action( 'wp_head', '_block_template_viewport_meta_tag', 0 );

$familypedia_notice = Editor::take_notice();
?>
<!DOCTYPE html>
<html <?php wp_app_language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?php wp_app_the_title( $familypedia_page_title ); ?></title>
	<?php wp_app_head(); ?>
</head>
<body class="familypedia">
	<?php wp_app_body_open(); ?>

	<div class="familypedia-page">
		<header class="familypedia-masthead">
			<a class="familypedia-masthead__home" href="<?php echo esc_url( home_url( '/' . App::URL_PATH . '/' ) ); ?>"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></a>
			<form class="familypedia-search" role="search" method="get" action="<?php echo esc_url( home_url( '/' . App::URL_PATH . '/all/' ) ); ?>">
				<label class="screen-reader-text" for="familypedia-search-field"><?php esc_html_e( 'Search the wiki', 'family-wiki' ); ?></label>
				<input id="familypedia-search-field" type="search" name="s" value="<?php echo esc_attr( isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>" placeholder="<?php esc_attr_e( 'Search the wiki…', 'family-wiki' ); ?>" />
				<button type="submit" class="familypedia-button"><?php esc_html_e( 'Search', 'family-wiki' ); ?></button>
			</form>
		</header>

		<?php if ( $familypedia_notice ) : ?>
			<p class="familypedia-notice familypedia-notice--<?php echo esc_attr( $familypedia_notice['type'] ); ?>"><?php echo esc_html( $familypedia_notice['message'] ); ?></p>
		<?php endif; ?>

		<main class="familypedia-main">
