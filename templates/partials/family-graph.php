<?php
/**
 * Shared family diagram. The entry form wraps this when editing is enabled.
 *
 * @package Familypedia
 */
namespace Family_Wiki;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$familypedia_graph_groups = array(
	'parents' => __( 'Parents', 'family-wiki' ),
	'spouses' => __( 'Spouses', 'family-wiki' ),
	'siblings' => __( 'Siblings', 'family-wiki' ),
	'children' => __( 'Children', 'family-wiki' ),
);
?>
<div class="familypedia-family-graph">
	<?php foreach ( $familypedia_graph_groups as $familypedia_group => $familypedia_group_label ) : ?>
		<?php if ( 'spouses' === $familypedia_group ) : ?>
			<div class="familypedia-family-focus">
				<?php echo Family_Graph::card( $familypedia_graph_id, $familypedia_graph_dates ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in card(). ?>
			</div>
		<?php endif; ?>
		<section class="familypedia-family-generation familypedia-family-generation--<?php echo esc_attr( $familypedia_group ); ?>" data-family-group="<?php echo esc_attr( $familypedia_group ); ?>">
			<h3><?php echo esc_html( $familypedia_group_label ); ?></h3>
			<div class="familypedia-family-cards" data-family-cards>
				<?php
				if ( 'spouses' === $familypedia_group ) {
					$familypedia_graph_ids = Person::to_ids( Person::field( 'spouse', $familypedia_graph_id ) );
					foreach ( Person::field( 'marriages', $familypedia_graph_id ) as $familypedia_marriage ) {
						if ( ! empty( $familypedia_marriage['spouse'] ) ) {
							$familypedia_graph_ids[] = (int) $familypedia_marriage['spouse'];
						}
					}
					$familypedia_graph_ids = array_unique( $familypedia_graph_ids );
				} elseif ( 'siblings' === $familypedia_group ) {
					$familypedia_graph_ids = Person::to_ids( array_merge( Relationships::siblings( $familypedia_graph_id ), Relationships::siblings( $familypedia_graph_id, true ) ) );
				} else {
					$familypedia_graph_ids = $familypedia_graph_people[ $familypedia_graph_id ][ $familypedia_group ] ?? array();
				}
				foreach ( $familypedia_graph_ids as $familypedia_graph_relative ) {
					echo Family_Graph::card( $familypedia_graph_relative, $familypedia_graph_dates ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in card().
				}
				?>
			</div>
			<?php if ( $familypedia_graph_editable ) : ?>
				<?php if ( 'parents' !== $familypedia_group || ! Person::field( 'father', $familypedia_graph_id ) || ! Person::field( 'mother', $familypedia_graph_id ) ) : ?>
					<button type="button" class="familypedia-family-plus" data-family-add aria-label="<?php echo esc_attr( sprintf( __( 'Add to %s', 'family-wiki' ), $familypedia_group_label ) ); ?>">+</button>
				<?php endif; ?>
				<template data-family-template>
					<div class="familypedia-family-card familypedia-family-card--entry" data-family-row>
						<label><?php esc_html_e( 'Name', 'family-wiki' ); ?><input type="text" data-family-field="name" list="relative-people" required autocomplete="off" /></label>
						<label><?php esc_html_e( 'Years', 'family-wiki' ); ?><input type="text" data-family-field="years" placeholder="1923 or 1923-2000" pattern="[0-9]{4}( *(-|–) *[0-9]{4})?" /></label>
						<?php if ( 'siblings' === $familypedia_group ) : ?>
							<input type="hidden" data-family-field="relationship" value="sibling" />
						<?php elseif ( 'spouses' === $familypedia_group ) : ?>
							<input type="hidden" data-family-field="relationship" value="spouse" />
						<?php elseif ( 'children' === $familypedia_group && in_array( Person::field( 'sex', $familypedia_graph_id ), array( 'Male', 'Female' ), true ) ) : ?>
							<input type="hidden" data-family-field="relationship" value="child" />
						<?php else : ?>
							<label><?php esc_html_e( 'Relationship', 'family-wiki' ); ?><select data-family-field="relationship">
								<?php if ( 'parents' === $familypedia_group ) : ?>
									<option value="father"><?php esc_html_e( 'Father', 'family-wiki' ); ?></option>
									<option value="mother" <?php selected( true, (bool) Person::field( 'father', $familypedia_graph_id ) && ! Person::field( 'mother', $familypedia_graph_id ) ); ?>><?php esc_html_e( 'Mother', 'family-wiki' ); ?></option>
								<?php else : ?>
									<option value="child_father"><?php esc_html_e( 'This person is the father', 'family-wiki' ); ?></option>
									<option value="child_mother" <?php selected( 'Female', Person::field( 'sex', $familypedia_graph_id ) ); ?>><?php esc_html_e( 'This person is the mother', 'family-wiki' ); ?></option>
								<?php endif; ?>
							</select></label>
						<?php endif; ?>
						<p class="familypedia-family-row-status" data-family-error role="status"></p>
						<button type="button" class="familypedia-family-remove" data-family-remove><?php esc_html_e( 'Remove', 'family-wiki' ); ?></button>
					</div>
				</template>
			<?php endif; ?>
		</section>
	<?php endforeach; ?>
</div>
