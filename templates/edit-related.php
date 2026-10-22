<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are render-local state.
/**
 * The form for adding a new page under a person: a title and its parent
 * only. Like everywhere else in the app, the page's own text is written in
 * the block editor, which it goes straight to once saved — an existing
 * related page has nothing left for this screen to do, since the block
 * editor already edits its title alongside its text.
 *
 * @package Familypedia
 */

namespace Family_Wiki;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$familypedia_parent_slug = wp_app_get_route_var( 'person', '' );
$familypedia_parent      = Person::get_by_path( $familypedia_parent_slug );

if ( ! $familypedia_parent ) {
	require __DIR__ . '/404.php';
	return;
}

if ( ! Related_Page::can_create( $familypedia_parent->ID ) ) {
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

$familypedia_page_title = sprintf(
	// translators: %s is a person's name.
	__( 'Add a page under %s', 'family-wiki' ),
	get_the_title( $familypedia_parent )
);

require __DIR__ . '/partials/header.php';
?>

<h1><?php echo esc_html( $familypedia_page_title ); ?></h1>

<form class="familypedia-form" method="post" action="<?php echo esc_url( Related_Page::add_url( $familypedia_parent ) ); ?>">
	<input type="hidden" name="familypedia_action" value="<?php echo esc_attr( Related_Page::ACTION ); ?>" />
	<input type="hidden" name="page_id" value="0" />
	<input type="hidden" name="parent_id" value="<?php echo esc_attr( $familypedia_parent->ID ); ?>" />
	<?php wp_nonce_field( Related_Page::ACTION . '_0_' . $familypedia_parent->ID ); ?>

	<p class="familypedia-field">
		<label for="familypedia-post-title"><?php esc_html_e( 'Title', 'family-wiki' ); ?></label>
		<input id="familypedia-post-title" type="text" name="post_title" required />
	</p>

	<p class="familypedia-form__actions">
		<button type="submit" class="familypedia-button familypedia-button--primary"><?php esc_html_e( 'Save', 'family-wiki' ); ?></button>
		<a href="<?php echo esc_url( Person::url( $familypedia_parent ) ); ?>"><?php esc_html_e( 'Cancel', 'family-wiki' ); ?></a>
	</p>
</form>

<?php
require __DIR__ . '/partials/footer.php';
