<?php
/**
 * Shared immediate-family card layout for quick entry and the tree block.
 *
 * @package Familypedia
 */
namespace Family_Wiki;

class Family_Graph {
	/** Render one person's parents, siblings and children as connected cards. */
	public static function render( $id, $editable = false, $show_dates = true ) {
		$person = Person::to_post( $id );
		if ( ! $person || 'publish' !== $person->post_status || Wiki_Page::is_page( $person ) ) {
			return '';
		}
		$editable = $editable && Editor::can_edit( $id ) && Editor::can_create();
		$familypedia_graph_id = $id;
		$familypedia_graph_editable = $editable;
		$familypedia_graph_dates = $show_dates;
		$familypedia_graph_people = Tree::get_people();
		ob_start();
		require dirname( __DIR__ ) . '/templates/partials/family-graph.php';
		return ob_get_clean();
	}

	/** A saved person card, shared by read and edit views. */
	public static function card( $id, $show_dates = true ) {
		$person = Person::to_post( $id );
		if ( ! $person || 'publish' !== $person->post_status ) {
			return '';
		}
		$out = '<div class="familypedia-family-card"><a href="' . esc_url( Person::url( $id ) ) . '">' . esc_html( get_the_title( $id ) ) . '</a>';
		if ( $show_dates ) {
			$birth = substr( (string) Person::field( 'birth_date', $id ), 0, 4 );
			$death = substr( (string) Person::field( 'death_date', $id ), 0, 4 );
			$out .= '<small>' . esc_html( $birth . ( $death ? '–' . $death : '' ) ) . '</small>';
		}
		if ( Person::field( 'is_stub', $id ) ) {
			$out .= '<small>' . esc_html__( 'Stub', 'family-wiki' ) . '</small>';
		}
		return $out . '</div>';
	}
}
