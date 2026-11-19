<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template state.
/**
 * The reunion index.
 *
 * @package Familypedia
 */
namespace Family_Wiki;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$familypedia_page_title = __( 'Reunions and visits', 'family-wiki' );
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only attendance filter.
$familypedia_person_id = isset( $_GET['person'] ) && is_string( $_GET['person'] ) ? absint( $_GET['person'] ) : 0;
$familypedia_people = Reunion::people();
$familypedia_person_labels = array();
foreach ( $familypedia_people as $familypedia_person ) {
	$familypedia_person_labels[ $familypedia_person->ID ] = Editor::label_for( $familypedia_person->ID );
}
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only datalist fallback without JavaScript.
if ( isset( $_GET['attendee'] ) && is_string( $_GET['attendee'] ) ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only attendance filter.
	$familypedia_person_id = (int) array_search( sanitize_text_field( wp_unslash( $_GET['attendee'] ) ), $familypedia_person_labels, true );
}
if ( ! in_array( $familypedia_person_id, array_map( 'intval', wp_list_pluck( $familypedia_people, 'ID' ) ), true ) ) {
	$familypedia_person_id = 0;
}
$familypedia_reunions = Reunion::get_all();
if ( $familypedia_person_id ) {
	$familypedia_reunions = array_filter(
		$familypedia_reunions,
		function ( $reunion ) use ( $familypedia_person_id ) {
			return in_array( $familypedia_person_id, Reunion::data( $reunion->ID )['attendees'], true );
		}
	);
}
wp_enqueue_script( 'familypedia-reunions', Assets::url( 'reunions.js' ), array(), Assets::version( 'reunions.js' ), true );
require __DIR__ . '/partials/header.php';
?>
<div class="familypedia-reunions-heading">
	<h1><?php echo esc_html( $familypedia_page_title ); ?></h1>
	<form class="familypedia-reunion-filter" method="get" action="<?php echo esc_url( Reunion::url() ); ?>">
		<label class="screen-reader-text" for="reunion-person"><?php esc_html_e( 'Filter by person', 'family-wiki' ); ?></label>
		<input type="search" id="reunion-person" name="attendee" list="reunion-people" data-invalid-person="<?php esc_attr_e( 'Choose a person from the suggestions.', 'family-wiki' ); ?>" value="<?php echo esc_attr( $familypedia_person_labels[ $familypedia_person_id ] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Filter by person…', 'family-wiki' ); ?>" autocomplete="off" />
		<datalist id="reunion-people">
			<?php foreach ( $familypedia_person_labels as $familypedia_id => $familypedia_label ) : ?>
				<option value="<?php echo esc_attr( $familypedia_label ); ?>" data-person-id="<?php echo esc_attr( $familypedia_id ); ?>"></option>
			<?php endforeach; ?>
		</datalist>
		<button type="submit" class="familypedia-button"><?php esc_html_e( 'Filter', 'family-wiki' ); ?></button>
		<?php if ( $familypedia_person_id ) : ?>
			<a href="<?php echo esc_url( Reunion::url() ); ?>"><?php esc_html_e( 'Clear', 'family-wiki' ); ?></a>
		<?php endif; ?>
	</form>
</div>
<?php if ( Wiki_Page::can_create() ) : ?>
	<p><a class="familypedia-button" href="<?php echo esc_url( Reunion::edit_url() ); ?>"><?php esc_html_e( 'Add a reunion or visit', 'family-wiki' ); ?></a></p>
<?php endif; ?>
<?php if ( $familypedia_person_id && $familypedia_reunions ) : ?>
	<p>
		<?php
		printf(
			/* translators: %s: Number of reunions and visits matching the selected person. */
			esc_html( _n( '%s reunion or visit', '%s reunions and visits', count( $familypedia_reunions ), 'family-wiki' ) ),
			esc_html( number_format_i18n( count( $familypedia_reunions ) ) )
		);
		?>
	</p>
<?php endif; ?>
<?php if ( ! $familypedia_reunions ) : ?>
	<p><?php echo esc_html( $familypedia_person_id ? __( 'No reunions or visits have this person recorded as an attendee.', 'family-wiki' ) : __( 'No reunions or visits recorded yet.', 'family-wiki' ) ); ?></p>
<?php else : ?>
	<ul class="familypedia-person-list">
		<?php foreach ( $familypedia_reunions as $familypedia_reunion ) : ?>
			<?php $familypedia_details = Reunion::data( $familypedia_reunion->ID ); ?>
			<li>
				<a href="<?php echo esc_url( Person::url( $familypedia_reunion ) ); ?>"><?php echo esc_html( get_the_title( $familypedia_reunion ) ); ?></a>
				<span class="familypedia-person-list__meta"><?php echo esc_html( implode( ' · ', array_filter( array( $familypedia_details['year'], $familypedia_details['location'] ) ) ) ); ?></span>
			</li>
		<?php endforeach; ?>
	</ul>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
