<?php
namespace Family_Wiki;

class Infobox {
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );
		add_filter( 'the_content', array( $this, 'replace_short_bio_shortcode' ), 10 );
		add_filter( 'the_content', array( $this, 'add_infobox' ), 12 );
	}

	public function enqueue_styles() {
		if ( ! is_singular( 'page' ) ) {
			return;
		}

		wp_enqueue_style(
			'family-wiki-infobox',
			plugin_dir_url( __FILE__ ) . 'family-wiki.css',
			array(),
			filemtime( plugin_dir_path( __FILE__ ) . 'family-wiki.css' )
		);
	}

	public function replace_short_bio_shortcode( $content ) {
		if ( ! $this->is_wiki_page() ) {
			return $content;
		}

		$title = '<strong>' . esc_html( get_the_title() ) . '</strong>';
		return preg_replace( '/\[name_with_bio[^\]]*\]\s*/i', $title . ' ', $content );
	}

	public function add_infobox( $content ) {
		if ( ! $this->is_wiki_page() || ! $this->has_infobox_data() ) {
			return $content;
		}

		return $this->render_infobox() . $content;
	}

	private function is_wiki_page() {
		if ( ! function_exists( 'get_field' ) || ! in_the_loop() || 'page' !== get_post_type() ) {
			return false;
		}

		$queried_object_id = get_queried_object_id();
		if ( $queried_object_id && get_the_ID() !== $queried_object_id ) {
			return false;
		}

		return is_singular( 'page' ) || ( defined( 'WP_CLI' ) && WP_CLI );
	}

	private function has_infobox_data() {
		foreach ( array( 'born_as', 'birth_date', 'birth_place', 'death_date', 'death_place', 'father', 'father_name', 'mother', 'mother_name', 'children', 'marriages', 'spouse', 'spouse_name', 'marriage_date', 'marriage_place' ) as $field ) {
			if ( get_field( $field ) ) {
				return true;
			}
		}

		return has_post_thumbnail();
	}

	private function render_infobox() {
		$rows = array_filter(
			array(
				$this->row( __( 'Born as', 'family-wiki' ), $this->text_field( 'born_as' ) ),
				$this->row( __( 'Born', 'family-wiki' ), $this->event_value( 'birth' ) ),
				$this->row( __( 'Died', 'family-wiki' ), $this->event_value( 'death' ) ),
				$this->row( __( 'Father', 'family-wiki' ), $this->person_link( 'father', 'father_name' ) ),
				$this->row( __( 'Mother', 'family-wiki' ), $this->person_link( 'mother', 'mother_name' ) ),
				$this->row( __( 'Siblings', 'family-wiki' ), $this->siblings_links( false ) ),
				$this->row( __( 'Half-siblings', 'family-wiki' ), $this->siblings_links( true ) ),
				$this->row( __( 'Spouse', 'family-wiki' ), $this->spouse_value() ),
				$this->row( __( 'Children', 'family-wiki' ), $this->children_links() ),
			)
		);

		if ( empty( $rows ) && ! has_post_thumbnail() ) {
			return '';
		}

		$return  = '<aside class="family-wiki-infobox" aria-label="' . esc_attr__( 'Family Wiki infobox', 'family-wiki' ) . '">';
		$return .= '<h2 class="family-wiki-infobox__title">' . esc_html( get_the_title() ) . '</h2>';

		if ( has_post_thumbnail() ) {
			$return .= '<div class="family-wiki-infobox__image">' . get_the_post_thumbnail( get_the_ID(), 'medium' ) . '</div>';
		}

		if ( ! empty( $rows ) ) {
			$return .= '<dl class="family-wiki-infobox__facts">' . implode( '', $rows ) . '</dl>';
		}

		$return .= '</aside>';

		return $return;
	}

	private function row( $label, $value ) {
		if ( '' === $value || null === $value || false === $value ) {
			return '';
		}

		return '<div class="family-wiki-infobox__row"><dt>' . esc_html( $label ) . '</dt><dd>' . wp_kses_post( $value ) . '</dd></div>';
	}

	private function text_field( $field ) {
		$value = get_field( $field );
		if ( ! $value ) {
			return '';
		}

		return esc_html( $value );
	}

	private function event_value( $type ) {
		$date_field    = $type . '_date';
		$place_field   = $type . '_place';
		$unknown_field = 'exact_' . $type . '_date_unknown';
		$date_value    = get_field( $date_field );
		$place_value   = get_field( $place_field );
		$parts         = array();

		if ( $date_value ) {
			try {
				$date = new \DateTime( $date_value );
				if ( get_field( $unknown_field ) ) {
					$parts[] = esc_html( $date->format( 'Y' ) );
				} else {
					$parts[] = $this->linked_date( $date );
				}
			} catch ( \Exception $e ) {
				$parts[] = esc_html( $date_value );
			}
		}

		if ( $place_value ) {
			$parts[] = esc_html( $place_value );
		}

		$age = $this->age_value( $type );
		if ( $age ) {
			$parts[] = '<span class="family-wiki-infobox__age">' . esc_html( $age ) . '</span>';
		}

		return implode( '<br />', $parts );
	}

	private function linked_date( \DateTime $date ) {
		$return = date_i18n( get_option( 'date_format' ), $date->format( 'U' ) );
		if ( get_option( 'family_wiki_calendar_page' ) ) {
			$return = '<a href="' . esc_url( get_option( 'family_wiki_calendar_page' ) . '#' . date_i18n( 'F', $date->format( 'U' ) ) ) . '">' . esc_html( $return ) . '</a>';
		} else {
			$return = esc_html( $return );
		}

		return $return;
	}

	private function age_value( $type ) {
		if ( 'birth' === $type && get_field( 'alive' ) && get_field( 'birth_date' ) ) {
			try {
				$birth = new \DateTime( get_field( 'birth_date' ) );
				$age   = $birth->diff( new \DateTime( 'now' ) );
				if ( get_field( 'exact_birth_date_unknown' ) ) {
					return sprintf(
						// translators: %d is an approximate age in years.
						_n( 'age: ~%d', 'age: ~%d', $age->y, 'family-wiki' ),
						$age->y
					);
				}

				return sprintf(
					// translators: %d is an age in years.
					_n( 'age: %d', 'age: %d', $age->y, 'family-wiki' ),
					$age->y
				);
			} catch ( \Exception $e ) {
				return '';
			}
		}

		if ( 'death' === $type && get_field( 'birth_date' ) && get_field( 'death_date' ) ) {
			try {
				$birth = new \DateTime( get_field( 'birth_date' ) );
				$death = new \DateTime( get_field( 'death_date' ) );
				$age   = $birth->diff( $death );
				if ( get_field( 'exact_birth_date_unknown' ) || get_field( 'exact_death_date_unknown' ) ) {
					return sprintf(
						// translators: %d is an approximate age in years.
						_n( 'aged: ~%d', 'aged: ~%d', $age->y, 'family-wiki' ),
						$age->y
					);
				}

				return sprintf(
					// translators: %d is an age in years.
					_n( 'aged: %d', 'aged: %d', $age->y, 'family-wiki' ),
					$age->y
				);
			} catch ( \Exception $e ) {
				return '';
			}
		}

		return '';
	}

	private function person_link( $field, $name_field ) {
		$person = get_field( $field );
		if ( $person ) {
			return '<a href="' . esc_url( get_permalink( $person ) ) . '">' . esc_html( get_the_title( $person ) ) . '</a>';
		}

		$name = get_field( $name_field );
		if ( $name ) {
			return '<a href="' . esc_url( home_url( '/' . sanitize_title_with_dashes( $name ) ) ) . '">' . esc_html( $name ) . '</a>';
		}

		return '';
	}

	private function children_links() {
		$children = get_field( 'children' );
		if ( empty( $children ) || ! is_array( $children ) ) {
			return '';
		}

		$links = array();
		foreach ( $children as $child ) {
			$links[] = '<a href="' . esc_url( get_permalink( $child ) ) . '">' . esc_html( get_the_title( $child ) ) . '</a>';
		}

		return implode( '<br />', $links );
	}

	private function siblings_links( $half_siblings = false ) {
		$siblings = $this->get_sibling_posts( $half_siblings );
		if ( empty( $siblings ) ) {
			return '';
		}

		$links = array();
		foreach ( $siblings as $sibling ) {
			$links[] = '<a href="' . esc_url( get_permalink( $sibling ) ) . '">' . esc_html( get_the_title( $sibling ) ) . '</a>';
		}

		return implode( '<br />', $links );
	}

	private function get_sibling_posts( $half_siblings = false ) {
		$father_children = $this->parent_children( 'father' );
		$mother_children = $this->parent_children( 'mother' );
		$siblings        = array();
		$half            = array();

		foreach ( $father_children as $child_id => $child ) {
			if ( isset( $mother_children[ $child_id ] ) ) {
				$siblings[ $child_id ] = $child;
			} else {
				$half[ $child_id ] = $child;
			}
		}

		foreach ( $mother_children as $child_id => $child ) {
			if ( isset( $father_children[ $child_id ] ) ) {
				$siblings[ $child_id ] = $child;
			} else {
				$half[ $child_id ] = $child;
			}
		}

		if ( ! get_field( 'father' ) || ! get_field( 'mother' ) ) {
			$siblings = $half;
			$half     = array();
		}

		return $half_siblings ? $half : $siblings;
	}

	private function parent_children( $parent_field ) {
		$parent = get_field( $parent_field );
		if ( ! $parent ) {
			return array();
		}

		$children = get_field( 'children', $parent );
		if ( empty( $children ) || ! is_array( $children ) ) {
			return array();
		}

		$return = array();
		foreach ( $children as $child ) {
			if ( get_the_ID() !== $child->ID ) {
				$return[ $child->ID ] = $child;
			}
		}

		return $return;
	}

	private function spouse_value() {
		$marriages = get_field( 'marriages' );
		if ( ! empty( $marriages ) && is_array( $marriages ) ) {
			return $this->marriages_value( $marriages );
		}

		$spouses = get_field( 'spouse' );
		if ( empty( $spouses ) ) {
			$spouses = array();
		} elseif ( ! is_array( $spouses ) ) {
			$spouses = array( $spouses );
		}

		$links = array();
		foreach ( $spouses as $spouse ) {
			$links[] = '<a href="' . esc_url( get_permalink( $spouse ) ) . '">' . esc_html( get_the_title( $spouse ) ) . '</a>';
		}

		if ( get_field( 'spouse_name' ) ) {
			$links[] = '<a href="' . esc_url( home_url( '/' . sanitize_title_with_dashes( get_field( 'spouse_name' ) ) ) ) . '">' . esc_html( get_field( 'spouse_name' ) ) . '</a>';
		}

		$details = array();
		if ( get_field( 'marriage_date' ) ) {
			try {
				$details[] = sprintf(
					// translators: %s is a marriage date.
					__( 'married %s', 'family-wiki' ),
					$this->linked_date( new \DateTime( get_field( 'marriage_date' ) ) )
				);
			} catch ( \Exception $e ) {
				$details[] = sprintf(
					// translators: %s is a marriage date.
					__( 'married %s', 'family-wiki' ),
					esc_html( get_field( 'marriage_date' ) )
				);
			}
		}

		if ( get_field( 'marriage_place' ) ) {
			$details[] = esc_html( get_field( 'marriage_place' ) );
		}

		if ( empty( $links ) ) {
			return implode( '<br />', $details );
		}

		$return = implode( '<br />', $links );
		if ( ! empty( $details ) ) {
			$return .= '<br /><span class="family-wiki-infobox__meta">' . implode( '<br />', $details ) . '</span>';
		}

		return $return;
	}

	private function marriages_value( $marriages ) {
		$values = array();
		foreach ( $marriages as $marriage ) {
			$value = $this->marriage_value( $marriage );
			if ( $value ) {
				$values[] = '<div class="family-wiki-infobox__marriage">' . $value . '</div>';
			}
		}

		return implode( '', $values );
	}

	private function marriage_value( $marriage ) {
		if ( ! is_array( $marriage ) ) {
			return '';
		}

		$lines = array();
		if ( ! empty( $marriage['spouse'] ) ) {
			$lines[] = '<a href="' . esc_url( get_permalink( $marriage['spouse'] ) ) . '">' . esc_html( get_the_title( $marriage['spouse'] ) ) . '</a>';
		} elseif ( ! empty( $marriage['spouse_name'] ) ) {
			$lines[] = '<a href="' . esc_url( home_url( '/' . sanitize_title_with_dashes( $marriage['spouse_name'] ) ) ) . '">' . esc_html( $marriage['spouse_name'] ) . '</a>';
		}

		$details = array();
		if ( ! empty( $marriage['marriage_date'] ) ) {
			$details[] = sprintf(
				// translators: %s is a marriage date.
				__( 'married %s', 'family-wiki' ),
				$this->linked_date( new \DateTime( $this->date_for_datetime( $marriage['marriage_date'] ) ) )
			);
		} elseif ( ! empty( $marriage['marriage_year'] ) ) {
			$details[] = sprintf(
				// translators: %s is a marriage year.
				__( 'married %s', 'family-wiki' ),
				esc_html( $marriage['marriage_year'] )
			);
		}

		if ( ! empty( $marriage['marriage_place'] ) ) {
			$details[] = esc_html( $marriage['marriage_place'] );
		}

		if ( ! empty( $marriage['ended_date'] ) ) {
			$details[] = $this->ended_value( $marriage['ended_reason'], $this->linked_date( new \DateTime( $this->date_for_datetime( $marriage['ended_date'] ) ) ) );
		} elseif ( ! empty( $marriage['ended_year'] ) ) {
			$details[] = $this->ended_value( $marriage['ended_reason'], esc_html( $marriage['ended_year'] ) );
		} elseif ( ! empty( $marriage['ended_reason'] ) ) {
			$details[] = esc_html( strtolower( $this->ended_reason_label( $marriage['ended_reason'] ) ) );
		}

		if ( ! empty( $details ) ) {
			$lines[] = '<span class="family-wiki-infobox__meta">' . implode( '<br />', $details ) . '</span>';
		}

		return implode( '<br />', $lines );
	}

	private function date_for_datetime( $date ) {
		if ( preg_match( '/^\d{8}$/', $date ) ) {
			return substr( $date, 0, 4 ) . '-' . substr( $date, 4, 2 ) . '-' . substr( $date, 6, 2 );
		}

		return $date;
	}

	private function ended_value( $reason, $date ) {
		if ( $reason ) {
			return sprintf(
				// translators: %1$s is an ended reason, %2$s is a date or year.
				__( '%1$s %2$s', 'family-wiki' ),
				esc_html( strtolower( $this->ended_reason_label( $reason ) ) ),
				$date
			);
		}

		return sprintf(
			// translators: %s is a date or year.
			__( 'ended %s', 'family-wiki' ),
			$date
		);
	}

	private function ended_reason_label( $reason ) {
		$labels = array(
			'divorced'  => __( 'Divorced', 'family-wiki' ),
			'widowed'   => __( 'Widowed', 'family-wiki' ),
			'annulled'  => __( 'Annulled', 'family-wiki' ),
			'separated' => __( 'Separated', 'family-wiki' ),
			'ended'     => __( 'Ended', 'family-wiki' ),
		);

		return isset( $labels[ $reason ] ) ? $labels[ $reason ] : $reason;
	}
}
