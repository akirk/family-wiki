<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template state.
/**
 * Reunion facts and attendance, separate from its block-edited memories.
 *
 * @package Familypedia
 */
namespace Family_Wiki;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$familypedia_reunion = Reunion::edit_post( get_query_var( 'reunion_id', '' ), wp_app_get_route_var( 'person', '' ) );
if ( is_wp_error( $familypedia_reunion ) ) {
	require __DIR__ . '/404.php';
	return;
}
$familypedia_id = $familypedia_reunion ? (int) $familypedia_reunion->ID : 0;
if ( $familypedia_id ? ! Editor::can_edit( $familypedia_id ) : ! Wiki_Page::can_create() ) {
	status_header( 403 );
	$familypedia_page_title = __( 'Not allowed', 'family-wiki' );
	require __DIR__ . '/partials/header.php';
	?>
	<p><?php esc_html_e( 'You do not have permission to edit this reunion or visit.', 'family-wiki' ); ?></p>
	<?php
	require __DIR__ . '/partials/footer.php';
	return;
}
$familypedia_page_title = $familypedia_id ? __( 'Edit reunion or visit facts', 'family-wiki' ) : __( 'Add a reunion or visit', 'family-wiki' );
$familypedia_details    = Reunion::data( $familypedia_id );
wp_enqueue_script( 'familypedia-reunions', Assets::url( 'reunions.js' ), array(), Assets::version( 'reunions.js' ), true );
require __DIR__ . '/partials/header.php';
?>
<h1><?php echo esc_html( $familypedia_page_title ); ?></h1>
<form class="familypedia-form" method="post" action="<?php echo esc_url( Reunion::edit_url( $familypedia_reunion ) ); ?>">
	<input type="hidden" name="familypedia_action" value="<?php echo esc_attr( Reunion::ACTION ); ?>" />
	<input type="hidden" name="reunion_id" value="<?php echo esc_attr( $familypedia_id ); ?>" />
	<?php wp_nonce_field( Reunion::ACTION . '_' . $familypedia_id ); ?>
	<?php if ( ! $familypedia_id ) : ?>
		<input type="hidden" name="creation_key" value="<?php echo esc_attr( wp_generate_uuid4() ); ?>" />
	<?php endif; ?>
	<p class="familypedia-field">
		<label for="reunion-title"><?php esc_html_e( 'Title', 'family-wiki' ); ?></label>
		<input id="reunion-title" name="post_title" type="text" value="<?php echo esc_attr( $familypedia_id ? get_the_title( $familypedia_id ) : '' ); ?>" required />
	</p>
	<p class="familypedia-field">
		<label for="reunion-location"><?php esc_html_e( 'Location', 'family-wiki' ); ?></label>
		<input id="reunion-location" name="location" type="text" value="<?php echo esc_attr( $familypedia_details['location'] ); ?>" />
	</p>
	<p class="familypedia-field">
		<label for="reunion-year"><?php esc_html_e( 'Year', 'family-wiki' ); ?></label>
		<input id="reunion-year" name="year" type="number" min="1000" max="9999" value="<?php echo esc_attr( $familypedia_details['year'] ); ?>" />
		<span><?php esc_html_e( 'A year is enough when the exact dates are unknown.', 'family-wiki' ); ?></span>
	</p>
	<p class="familypedia-field">
		<label for="reunion-start"><?php esc_html_e( 'Start date (optional)', 'family-wiki' ); ?></label>
		<input id="reunion-start" name="start" type="date" value="<?php echo esc_attr( $familypedia_details['start'] ); ?>" />
	</p>
	<p class="familypedia-field">
		<label for="reunion-end"><?php esc_html_e( 'End date (optional)', 'family-wiki' ); ?></label>
		<input id="reunion-end" name="end" type="date" value="<?php echo esc_attr( $familypedia_details['end'] ); ?>" />
	</p>
	<fieldset>
		<legend><?php esc_html_e( 'Who was there?', 'family-wiki' ); ?></legend>
		<p><?php esc_html_e( 'Select everyone who attended. Leave uncertain people unchecked until identified.', 'family-wiki' ); ?></p>
		<p class="familypedia-field">
			<label for="reunion-attendee-search"><?php esc_html_e( 'Find a person', 'family-wiki' ); ?></label>
			<input id="reunion-attendee-search" type="search" />
		</p>
		<div class="familypedia-reunion-attendees">
			<?php foreach ( Reunion::people() as $familypedia_attendee ) : ?>
				<label class="familypedia-reunion-attendee">
					<input type="checkbox" name="attendees[]" value="<?php echo esc_attr( $familypedia_attendee->ID ); ?>" <?php checked( in_array( (int) $familypedia_attendee->ID, $familypedia_details['attendees'], true ) ); ?> />
					<?php echo esc_html( Editor::label_for( $familypedia_attendee->ID ) ); ?>
				</label>
			<?php endforeach; ?>
		</div>
	</fieldset>
	<p class="familypedia-form__actions">
		<button type="submit" class="familypedia-button familypedia-button--primary"><?php esc_html_e( 'Save', 'family-wiki' ); ?></button>
		<a href="<?php echo esc_url( $familypedia_id ? Person::url( $familypedia_reunion ) : Reunion::url() ); ?>"><?php esc_html_e( 'Cancel', 'family-wiki' ); ?></a>
	</p>
</form>
<?php require __DIR__ . '/partials/footer.php'; ?>
