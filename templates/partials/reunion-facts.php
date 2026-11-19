<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template state.
/**
 * A reunion's facts and linked attendees.
 *
 * @package Familypedia
 */
namespace Family_Wiki;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$familypedia_details = Reunion::data( $familypedia_person->ID );
?>
<section class="familypedia-reunion-facts" aria-label="<?php esc_attr_e( 'Reunion or visit facts', 'family-wiki' ); ?>">
	<?php if ( Editor::can_edit( $familypedia_person->ID ) ) : ?>
		<p class="familypedia-section-actions"><a href="<?php echo esc_url( Reunion::edit_url( $familypedia_person ) ); ?>"><?php esc_html_e( 'Edit', 'family-wiki' ); ?></a></p>
	<?php endif; ?>
	<dl class="familypedia-reunion-facts__details">
		<?php if ( $familypedia_details['year'] ) : ?>
			<div class="familypedia-reunion-facts__detail">
				<dt><?php esc_html_e( 'Year', 'family-wiki' ); ?></dt><dd><?php echo esc_html( $familypedia_details['year'] ); ?></dd>
			</div>
		<?php endif; ?>
		<?php if ( $familypedia_details['start'] ) : ?>
			<div class="familypedia-reunion-facts__detail">
				<dt><?php esc_html_e( 'Dates', 'family-wiki' ); ?></dt><dd><?php echo esc_html( $familypedia_details['start'] . ( $familypedia_details['end'] ? ' – ' . $familypedia_details['end'] : '' ) ); ?></dd>
			</div>
		<?php endif; ?>
		<?php if ( $familypedia_details['location'] ) : ?>
			<div class="familypedia-reunion-facts__detail">
				<dt><?php esc_html_e( 'Location', 'family-wiki' ); ?></dt><dd><?php echo esc_html( $familypedia_details['location'] ); ?></dd>
			</div>
		<?php endif; ?>
	</dl>
	<h2><?php esc_html_e( 'Who was there?', 'family-wiki' ); ?></h2>
	<?php if ( $familypedia_details['attendees'] ) : ?>
		<ul class="familypedia-person-list familypedia-reunion-facts__attendees">
			<?php foreach ( $familypedia_details['attendees'] as $familypedia_attendee_id ) : ?>
				<?php $familypedia_attendee = get_post( $familypedia_attendee_id ); ?>
				<?php if ( $familypedia_attendee && Person::POST_TYPE === $familypedia_attendee->post_type && 'publish' === $familypedia_attendee->post_status && ! Wiki_Page::is_page( $familypedia_attendee ) ) : ?>
					<li><a href="<?php echo esc_url( Person::url( $familypedia_attendee ) ); ?>"><?php echo esc_html( get_the_title( $familypedia_attendee ) ); ?></a></li>
				<?php endif; ?>
			<?php endforeach; ?>
		</ul>
	<?php else : ?>
		<p><?php esc_html_e( 'No attendees recorded yet.', 'family-wiki' ); ?></p>
	<?php endif; ?>
</section>
