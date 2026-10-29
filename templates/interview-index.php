<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are render-local state.
/**
 * Choose a person to start or resume an interview.
 *
 * @package Familypedia
 */

namespace Family_Wiki;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$familypedia_page_title = __( 'Interviews', 'family-wiki' );
if ( ! current_user_can( 'edit_others_pages' ) ) {
	status_header( 403 );
	require __DIR__ . '/partials/header.php';
	?>
	<p><?php esc_html_e( 'You do not have permission to manage interviews.', 'family-wiki' ); ?></p>
	<?php
	require __DIR__ . '/partials/footer.php';
	return;
}
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only search.
$familypedia_search = isset( $_GET['s'] ) && is_string( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
$familypedia_people = array_filter(
	Person::get_all( $familypedia_search ? array( 's' => $familypedia_search ) : array() ),
	function ( $person ) {
		return Interview::can_manage( $person->ID );
	}
);
require __DIR__ . '/partials/header.php';
?>
<h1><?php esc_html_e( 'Interviews', 'family-wiki' ); ?></h1>
<p><?php esc_html_e( 'Choose who the memories are about to start an interview, continue saved conversations, or customize your questions.', 'family-wiki' ); ?></p>
<form method="get" class="familypedia-search" action="<?php echo esc_url( home_url( '/' . App::URL_PATH . '/interviews/' ) ); ?>">
	<label for="interview-person-search"><?php esc_html_e( 'Find a person', 'family-wiki' ); ?></label>
	<input id="interview-person-search" type="search" name="s" value="<?php echo esc_attr( $familypedia_search ); ?>" />
	<button type="submit" class="familypedia-button"><?php esc_html_e( 'Search', 'family-wiki' ); ?></button>
</form>
<?php if ( ! $familypedia_people ) : ?>
	<p><?php esc_html_e( 'No people found.', 'family-wiki' ); ?></p>
<?php else : ?>
	<ul class="familypedia-person-list">
		<?php foreach ( $familypedia_people as $familypedia_person ) : ?>
			<li><a href="<?php echo esc_url( Interview::url( $familypedia_person->ID ) ); ?>"><?php echo esc_html( get_the_title( $familypedia_person ) ); ?></a></li>
		<?php endforeach; ?>
	</ul>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
