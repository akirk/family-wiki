<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are render-local state.
/**
 * The app's home page: whatever the front page post holds.
 *
 * @package Familypedia
 */

namespace Family_Wiki;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$familypedia_page_title = __( 'Familypedia', 'family-wiki' );
require __DIR__ . '/partials/header.php';

// A tree block on this page starts from nobody having been drawn yet.
Tree::reset_expanded();

$familypedia_edit_url = Front_Page::can_edit() ? Front_Page::edit_url() : '';
?>

<h1 class="screen-reader-text"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></h1>

<div class="familypedia-front-page">
	<?php
	// Already through the_content filters, as in any theme template.
	echo Front_Page::render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	?>
</div>

<?php if ( $familypedia_edit_url ) : ?>
	<p class="familypedia-front-page__actions">
		<a href="<?php echo esc_url( $familypedia_edit_url ); ?>"><?php esc_html_e( 'Edit this page', 'family-wiki' ); ?></a>
	</p>
<?php endif; ?>

<?php
require __DIR__ . '/partials/footer.php';
