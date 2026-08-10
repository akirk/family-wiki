<?php
namespace Family_Wiki;

class ACF_Field_Marriages extends \acf_field {
	public function initialize() {
		$this->name     = 'family_wiki_marriages';
		$this->label    = __( 'Marriages', 'family-wiki' );
		$this->category = 'relational';
		$this->defaults = array();
	}

	public function input_admin_enqueue_scripts() {
		wp_enqueue_script(
			'family-wiki-marriages-field',
			plugin_dir_url( __FILE__ ) . 'family-wiki-marriages-field.js',
			array(),
			filemtime( plugin_dir_path( __FILE__ ) . 'family-wiki-marriages-field.js' ),
			true
		);

		wp_enqueue_style(
			'family-wiki-marriages-field',
			plugin_dir_url( __FILE__ ) . 'family-wiki-marriages-field.css',
			array(),
			filemtime( plugin_dir_path( __FILE__ ) . 'family-wiki-marriages-field.css' )
		);
	}

	public function render_field( $field ) {
		$value = is_array( $field['value'] ) ? $field['value'] : array();
		$rows  = array_values( array_filter( array_map( array( $this, 'normalize_row' ), $value ) ) );

		if ( empty( $rows ) ) {
			$rows[] = $this->empty_row();
		}

		echo '<div class="family-wiki-marriages-field" data-name="' . esc_attr( $field['name'] ) . '">';
		echo '<table class="family-wiki-marriages-field__table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Spouse', 'family-wiki' ) . '</th>';
		echo '<th>' . esc_html__( 'Spouse name', 'family-wiki' ) . '</th>';
		echo '<th>' . esc_html__( 'Marriage', 'family-wiki' ) . '</th>';
		echo '<th>' . esc_html__( 'Ended', 'family-wiki' ) . '</th>';
		echo '<th><span class="screen-reader-text">' . esc_html__( 'Actions', 'family-wiki' ) . '</span></th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ( $rows as $index => $row ) {
			$this->render_row( $field['name'], $index, $row );
		}

		echo '</tbody></table>';
		echo '<script type="text/html" class="family-wiki-marriages-field__template">';
		$this->render_row( $field['name'], '__index__', $this->empty_row() );
		echo '</script>';
		echo '<button type="button" class="button family-wiki-marriages-field__add">' . esc_html__( 'Add marriage', 'family-wiki' ) . '</button>';
		echo '</div>';
	}

	private function render_row( $name, $index, $row ) {
		$prefix = $name . '[' . $index . ']';

		echo '<tr class="family-wiki-marriages-field__row">';
		echo '<td>';
		$this->page_select( $prefix . '[spouse]', $row['spouse'] );
		echo '</td>';
		echo '<td><input type="text" name="' . esc_attr( $prefix . '[spouse_name]' ) . '" value="' . esc_attr( $row['spouse_name'] ) . '" /></td>';
		echo '<td>';
		echo '<label>' . esc_html__( 'Date', 'family-wiki' ) . '<input type="date" name="' . esc_attr( $prefix . '[marriage_date]' ) . '" value="' . esc_attr( $this->date_for_input( $row['marriage_date'] ) ) . '" /></label>';
		echo '<label>' . esc_html__( 'Year', 'family-wiki' ) . '<input type="number" min="1" max="9999" name="' . esc_attr( $prefix . '[marriage_year]' ) . '" value="' . esc_attr( $row['marriage_year'] ) . '" /></label>';
		echo '<label>' . esc_html__( 'Place', 'family-wiki' ) . '<input type="text" name="' . esc_attr( $prefix . '[marriage_place]' ) . '" value="' . esc_attr( $row['marriage_place'] ) . '" /></label>';
		echo '</td>';
		echo '<td>';
		echo '<label>' . esc_html__( 'Date', 'family-wiki' ) . '<input type="date" name="' . esc_attr( $prefix . '[ended_date]' ) . '" value="' . esc_attr( $this->date_for_input( $row['ended_date'] ) ) . '" /></label>';
		echo '<label>' . esc_html__( 'Year', 'family-wiki' ) . '<input type="number" min="1" max="9999" name="' . esc_attr( $prefix . '[ended_year]' ) . '" value="' . esc_attr( $row['ended_year'] ) . '" /></label>';
		echo '<label>' . esc_html__( 'Reason', 'family-wiki' ) . '<select name="' . esc_attr( $prefix . '[ended_reason]' ) . '">';
		foreach ( $this->ended_reason_choices() as $choice => $label ) {
			echo '<option value="' . esc_attr( $choice ) . '"' . selected( $row['ended_reason'], $choice, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label>';
		echo '</td>';
		echo '<td><button type="button" class="button-link-delete family-wiki-marriages-field__remove">' . esc_html__( 'Remove', 'family-wiki' ) . '</button></td>';
		echo '</tr>';
	}

	private function page_select( $name, $selected ) {
		$pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		echo '<select name="' . esc_attr( $name ) . '">';
		echo '<option value="">' . esc_html__( 'Select spouse', 'family-wiki' ) . '</option>';
		foreach ( $pages as $page ) {
			echo '<option value="' . esc_attr( $page->ID ) . '"' . selected( (string) $selected, (string) $page->ID, false ) . '>' . esc_html( get_the_title( $page ) ) . '</option>';
		}
		echo '</select>';
	}

	public function update_value( $value, $post_id, $field ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$rows = array();
		foreach ( $value as $row ) {
			$row = $this->normalize_row( $row );
			if ( ! $this->row_has_value( $row ) ) {
				continue;
			}

			$rows[] = $row;
		}

		return $rows;
	}

	public function format_value( $value, $post_id, $field ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		return array_values( array_filter( array_map( array( $this, 'normalize_row' ), $value ), array( $this, 'row_has_value' ) ) );
	}

	private function normalize_row( $row ) {
		$row     = is_array( $row ) ? $row : array();
		$choices = $this->ended_reason_choices();

		return array(
			'spouse'         => isset( $row['spouse'] ) ? absint( $row['spouse'] ) : 0,
			'spouse_name'    => isset( $row['spouse_name'] ) ? sanitize_text_field( $row['spouse_name'] ) : '',
			'marriage_date'  => isset( $row['marriage_date'] ) ? $this->normalize_date( $row['marriage_date'] ) : '',
			'marriage_year'  => isset( $row['marriage_year'] ) ? $this->normalize_year( $row['marriage_year'] ) : '',
			'marriage_place' => isset( $row['marriage_place'] ) ? sanitize_text_field( $row['marriage_place'] ) : '',
			'ended_date'     => isset( $row['ended_date'] ) ? $this->normalize_date( $row['ended_date'] ) : '',
			'ended_year'     => isset( $row['ended_year'] ) ? $this->normalize_year( $row['ended_year'] ) : '',
			'ended_reason'   => isset( $row['ended_reason'] ) && isset( $choices[ $row['ended_reason'] ] ) ? $row['ended_reason'] : '',
		);
	}

	private function empty_row() {
		return array(
			'spouse'         => 0,
			'spouse_name'    => '',
			'marriage_date'  => '',
			'marriage_year'  => '',
			'marriage_place' => '',
			'ended_date'     => '',
			'ended_year'     => '',
			'ended_reason'   => '',
		);
	}

	private function row_has_value( $row ) {
		return (bool) array_filter( $row );
	}

	private function normalize_date( $date ) {
		$date = sanitize_text_field( $date );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return str_replace( '-', '', $date );
		}
		if ( preg_match( '/^\d{8}$/', $date ) ) {
			return $date;
		}

		return '';
	}

	private function date_for_input( $date ) {
		$date = sanitize_text_field( $date );
		if ( preg_match( '/^\d{8}$/', $date ) ) {
			return substr( $date, 0, 4 ) . '-' . substr( $date, 4, 2 ) . '-' . substr( $date, 6, 2 );
		}

		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : '';
	}

	private function normalize_year( $year ) {
		$year = absint( $year );
		if ( $year < 1 || $year > 9999 ) {
			return '';
		}

		return (string) $year;
	}

	private function ended_reason_choices() {
		return array(
			''          => '',
			'divorced'  => __( 'Divorced', 'family-wiki' ),
			'widowed'   => __( 'Widowed', 'family-wiki' ),
			'annulled'  => __( 'Annulled', 'family-wiki' ),
			'separated' => __( 'Separated', 'family-wiki' ),
			'ended'     => __( 'Ended', 'family-wiki' ),
		);
	}
}
