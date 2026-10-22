<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are render-local state.
/**
 * Birthdays of the living, month by month.
 *
 * @package Familypedia
 */

namespace Family_Wiki;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$familypedia_page_title = Calendar::get_title( Calendar::VIEW_BIRTHDAYS );
require __DIR__ . '/partials/header.php';

$familypedia_birthdays = Calendar::get()->render_birthday_calendar();
?>

<h1><?php echo esc_html( $familypedia_page_title ); ?></h1>

<div class="familypedia-birthdays">
	<?php if ( $familypedia_birthdays ) : ?>
		<?php
		// Built from escaped parts.
		echo $familypedia_birthdays; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
	<?php else : ?>
		<p><?php esc_html_e( 'No birthdays recorded yet.', 'family-wiki' ); ?></p>
	<?php endif; ?>
</div>

<?php
require __DIR__ . '/partials/footer.php';
