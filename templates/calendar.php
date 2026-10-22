<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are render-local state.
/**
 * The family calendar: a month grid, and the year's dates as a list.
 *
 * @package Familypedia
 */

namespace Family_Wiki;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$familypedia_page_title = Calendar::get_title( Calendar::VIEW_CALENDAR );
require __DIR__ . '/partials/header.php';
?>

<h1><a href="<?php echo esc_url( Calendar::get_calendar_url() ); ?>"><?php echo esc_html( $familypedia_page_title ); ?></a></h1>

<div class="familypedia-calendar">
	<?php
	// Built from escaped parts.
	echo Calendar::get()->render_family_calendar(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	?>
</div>

<?php
require __DIR__ . '/partials/footer.php';
