<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are render-local state.
/**
 * The form for adding a new standalone page: a title only, with no parent.
 * Like everywhere else in the app, the page's own text is written in the
 * block editor, which it goes straight to once saved.
 *
 * @package Familypedia
 */

namespace Family_Wiki;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! Wiki_Page::can_create() ) {
	status_header( 403 );
	$familypedia_page_title = __( 'Not allowed', 'family-wiki' );
	require __DIR__ . '/partials/header.php';
	?>
	<h1><?php echo esc_html( $familypedia_page_title ); ?></h1>
	<p><?php esc_html_e( 'You do not have permission to edit this wiki.', 'family-wiki' ); ?></p>
	<?php
	require __DIR__ . '/partials/footer.php';
	return;
}

$familypedia_page_title = __( 'Add a page', 'family-wiki' );

require __DIR__ . '/partials/header.php';
?>

<h1><?php echo esc_html( $familypedia_page_title ); ?></h1>

<form class="familypedia-form" method="post" action="<?php echo esc_url( Wiki_Page::add_url() ); ?>">
	<input type="hidden" name="familypedia_action" value="<?php echo esc_attr( Wiki_Page::ACTION ); ?>" />
	<input type="hidden" name="page_id" value="0" />
	<input type="hidden" name="parent_id" value="0" />
	<?php wp_nonce_field( Wiki_Page::ACTION . '_0_0' ); ?>

	<p class="familypedia-field">
		<label for="familypedia-post-title"><?php esc_html_e( 'Title', 'family-wiki' ); ?></label>
		<input id="familypedia-post-title" type="text" name="post_title" required />
	</p>

	<p class="familypedia-form__actions">
		<button type="submit" class="familypedia-button familypedia-button--primary"><?php esc_html_e( 'Save', 'family-wiki' ); ?></button>
		<a href="<?php echo esc_url( home_url( '/' . App::URL_PATH . '/' ) ); ?>"><?php esc_html_e( 'Cancel', 'family-wiki' ); ?></a>
	</p>
</form>

<?php
require __DIR__ . '/partials/footer.php';
