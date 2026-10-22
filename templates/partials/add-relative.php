<?php
/**
 * Visual family entry on a person page.
 *
 * @package Familypedia
 */
namespace Family_Wiki;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
wp_app_enqueue_script( 'familypedia-family-entry', Assets::url( 'family-entry.js' ), array(), Assets::version( 'family-entry.js' ), true, App::URL_PATH );
?>
<section id="add-family-member" class="familypedia-add-relative" aria-labelledby="add-family-member-title">
	<h2 id="add-family-member-title"><?php esc_html_e( 'Add family members', 'family-wiki' ); ?></h2>
	<p><?php esc_html_e( 'Use + to add relatives, then save everyone together. Choose an existing name or create a stub. Years can be 1923 or 1923-2000. Siblings share the recorded parents.', 'family-wiki' ); ?></p>
	<form method="post" class="familypedia-form" data-family-entry data-saving="<?php esc_attr_e( 'Saving…', 'family-wiki' ); ?>" data-failed="<?php esc_attr_e( 'Could not confirm the save. Your entries are still here; retry to link any people already created.', 'family-wiki' ); ?>" data-saved="<?php esc_attr_e( 'Saved. New people are marked as stubs.', 'family-wiki' ); ?>" data-partial="<?php esc_attr_e( 'Review the highlighted cards, then save again. Saved people are already in the tree.', 'family-wiki' ); ?>" data-empty="<?php esc_attr_e( 'Use + to add a family member first.', 'family-wiki' ); ?>">
		<?php wp_nonce_field( 'familypedia_add_relative_' . $familypedia_person->ID ); ?>
		<input type="hidden" name="familypedia_action" value="familypedia_add_relative" />
		<input type="hidden" name="person_id" value="<?php echo esc_attr( $familypedia_person->ID ); ?>" />
		<datalist id="relative-people">
			<?php foreach ( Editor::person_labels() as $familypedia_label ) : ?>
				<option value="<?php echo esc_attr( $familypedia_label ); ?>"></option>
			<?php endforeach; ?>
		</datalist>
		<?php echo Family_Graph::render( $familypedia_person->ID, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by renderer. ?>
		<p data-family-status role="status" aria-live="polite"></p>
		<p><button class="familypedia-button familypedia-button--primary" type="submit"><?php esc_html_e( 'Save family members', 'family-wiki' ); ?></button> <a href="#person-actions"><?php esc_html_e( 'Close', 'family-wiki' ); ?></a></p>
		<noscript><?php esc_html_e( 'Enable JavaScript to build the family visually. You can also use Edit → Facts.', 'family-wiki' ); ?></noscript>
	</form>
</section>
