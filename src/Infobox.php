<?php
/**
 * The facts box shown beside a person's wiki text.
 *
 * Family Wiki injected this through the_content. Here the app template asks for
 * it directly, so the box is built for an explicit person rather than for
 * whatever happens to be in the loop.
 *
 * @package Familypedia
 */

namespace Family_Wiki;

class Infobox {
	/**
	 * The person whose facts are shown.
	 *
	 * @var int
	 */
	private $post_id;

	/**
	 * The person whose page is being displayed, which may be a related page
	 * under the person the facts belong to.
	 *
	 * @var int
	 */
	private $display_post_id;

	public function __construct( $post_id, $display_post_id = null ) {
		$this->post_id         = (int) ( $post_id instanceof \WP_Post ? $post_id->ID : $post_id );
		$this->display_post_id = (int) ( null === $display_post_id ? $this->post_id : ( $display_post_id instanceof \WP_Post ? $display_post_id->ID : $display_post_id ) );
	}

	/**
	 * The person whose facts a page should show: their own, or the
	 * nearest ancestor's who has any — a related page can itself hold
	 * further related pages nested under it, such as
	 * "person-name/their-house/floor-plan".
	 */
	public static function facts_post_id_for( $post_id ) {
		if ( Person::has_data( $post_id ) ) {
			return (int) $post_id;
		}

		return self::nearest_facts_ancestor( $post_id );
	}

	/**
	 * A related page's title, prefixed with the person it belongs to —
	 * their nearest ancestor with facts, for a page nested several
	 * related pages deep.
	 */
	public static function title_with_parent( $title, $post_id ) {
		$parent_id = self::nearest_facts_ancestor( $post_id );
		if ( ! $parent_id ) {
			return $title;
		}

		$parent_title = get_the_title( $parent_id );
		if ( ! $parent_title ) {
			return $title;
		}

		$child_title = trim( $title );
		if ( 0 === stripos( $child_title, $parent_title ) ) {
			$child_title = trim( substr( $child_title, strlen( $parent_title ) ), " \t\n\r\0\x0B:-–—" );
		}

		if ( '' === $child_title ) {
			return $parent_title;
		}

		return sprintf(
			// translators: %1$s is a person's name, %2$s is a related page title.
			__( '%1$s: %2$s', 'family-wiki' ),
			$parent_title,
			$child_title
		);
	}

	/**
	 * Walks up from a page through its own chain of parents — as far as
	 * they are themselves related pages with no facts — to the first
	 * ancestor that has any, or 0 if the chain ends without one.
	 */
	private static function nearest_facts_ancestor( $post_id ) {
		$parent_id = (int) wp_get_post_parent_id( $post_id );
		$seen      = array( (int) $post_id => true );

		while ( $parent_id && empty( $seen[ $parent_id ] ) ) {
			if ( 'publish' !== get_post_status( $parent_id ) ) {
				return 0;
			}
			if ( Person::has_data( $parent_id ) ) {
				return $parent_id;
			}

			$seen[ $parent_id ] = true;
			$parent_id          = (int) wp_get_post_parent_id( $parent_id );
		}

		return 0;
	}

	/**
	 * The facts, as label and value pairs, with the empty ones dropped.
	 *
	 * Kept separate from render() so that output which is not HTML — the
	 * Markdown Static Archive writes — can lay the same facts out its own way.
	 *
	 * @return array[] Each entry has a 'label' and an HTML 'value'.
	 */
	public function rows() {
		$settings = Settings::get_infobox_settings();
		$rows     = array(
			array( __( 'Born as', 'family-wiki' ), $this->text_field( 'born_as' ) ),
			array( __( 'Born', 'family-wiki' ), $this->event_value( 'birth' ) ),
			array( __( 'Died', 'family-wiki' ), $this->event_value( 'death' ) ),
			array( __( 'Father', 'family-wiki' ), $this->person_link( 'father', 'father_name' ) ),
			array( __( 'Mother', 'family-wiki' ), $this->person_link( 'mother', 'mother_name' ) ),
			array( __( 'Siblings', 'family-wiki' ), $this->siblings_links( false ) ),
			array( __( 'Half-siblings', 'family-wiki' ), $this->siblings_links( true ) ),
			array( __( 'Spouse / Partner', 'family-wiki' ), $this->spouse_value() ),
			array( __( 'Children', 'family-wiki' ), $this->children_links() ),
			array( __( 'Citizenship', 'family-wiki' ), $this->citizenships_value() ),
		);

		if ( $settings['show_related_pages'] ) {
			$rows[] = array( __( 'Related pages', 'family-wiki' ), $this->related_pages_links() );
		}

		if ( $settings['show_cross_wiki'] ) {
			$rows[] = array( __( 'Also on', 'family-wiki' ), $this->cross_wiki_value() );
		}

		$return = array();
		foreach ( $rows as $row ) {
			if ( '' === $row[1] || null === $row[1] || false === $row[1] ) {
				continue;
			}

			$return[] = array(
				'label' => $row[0],
				'value' => $row[1],
			);
		}

		return $return;
	}

	public function render() {
		$settings = Settings::get_infobox_settings();
		$rows     = array();
		foreach ( $this->rows() as $row ) {
			$rows[] = $this->row( $row['label'], $row['value'] );
		}

		if ( empty( $rows ) && ! has_post_thumbnail( $this->post_id ) ) {
			return '';
		}

		$content_id = 'familypedia-infobox-content-' . $this->post_id;
		$classes    = array( 'familypedia-infobox' );
		if ( ! $settings['collapse_mobile'] ) {
			$classes[] = 'familypedia-infobox--always-open';
		}

		$return  = '<aside class="' . esc_attr( implode( ' ', $classes ) ) . '" aria-label="' . esc_attr__( 'Familypedia infobox', 'family-wiki' ) . '">';
		$return .= '<h2 class="familypedia-infobox__title" data-collapsed-title="' . esc_attr__( 'Infobox', 'family-wiki' ) . '"><span>' . $this->title_value() . '</span><button type="button" class="familypedia-infobox__toggle" aria-controls="' . esc_attr( $content_id ) . '" aria-expanded="true" aria-label="' . esc_attr__( 'Toggle infobox', 'family-wiki' ) . '">-</button></h2>';
		$return .= '<div id="' . esc_attr( $content_id ) . '" class="familypedia-infobox__content">';

		if ( has_post_thumbnail( $this->post_id ) ) {
			$return .= '<div class="familypedia-infobox__image">' . get_the_post_thumbnail( $this->post_id, 'medium' ) . '</div>';
		}

		if ( ! empty( $rows ) ) {
			$return .= '<dl class="familypedia-infobox__facts">' . implode( '', $rows ) . '</dl>';
		}

		$return .= '</div>';
		$return .= '</aside>';

		return $return;
	}

	private function field( $field ) {
		return Person::field( $field, $this->post_id );
	}

	private function title_value() {
		$title = esc_html( get_the_title( $this->post_id ) );
		if ( $this->post_id === $this->display_post_id ) {
			return $title;
		}

		return '<a href="' . esc_url( Person::url( $this->post_id ) ) . '">' . $title . '</a>';
	}

	private function row( $label, $value ) {
		if ( '' === $value || null === $value || false === $value ) {
			return '';
		}

		return '<div class="familypedia-infobox__row"><dt>' . esc_html( $label ) . '</dt><dd>' . wp_kses_post( $value ) . '</dd></div>';
	}

	private function text_field( $field ) {
		$value = $this->field( $field );
		if ( ! $value ) {
			return '';
		}

		return esc_html( $value );
	}

	private function citizenships_value() {
		$value = $this->field( 'citizenships' );
		if ( ! $value ) {
			return '';
		}

		$citizenships = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $value ) ) );
		if ( empty( $citizenships ) ) {
			return '';
		}

		return implode( '<br />', array_map( 'esc_html', $citizenships ) );
	}

	private function event_value( $type ) {
		$date_value  = $this->field( $type . '_date' );
		$place_value = $this->field( $type . '_place' );
		$parts       = array();

		if ( $date_value ) {
			try {
				$date = new \DateTime( $date_value );
				if ( $this->field( 'exact_' . $type . '_date_unknown' ) ) {
					$parts[] = esc_html( $date->format( 'Y' ) );
				} else {
					$parts[] = self::linked_date( $date );
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
			$parts[] = '<span class="familypedia-infobox__age">' . esc_html( $age ) . '</span>';
		}

		return implode( '<br />', $parts );
	}

	public static function linked_date( \DateTime $date ) {
		$return = date_i18n( get_option( 'date_format' ), $date->format( 'U' ) );
		if ( Calendar::is_calendar_enabled() ) {
			$return = '<a href="' . esc_url( Calendar::get_calendar_url( $date ) ) . '">' . esc_html( $return ) . '</a>';
		} else {
			$return = esc_html( $return );
		}

		return $return;
	}

	private function age_value( $type ) {
		if ( 'birth' === $type && $this->field( 'alive' ) && $this->field( 'birth_date' ) ) {
			try {
				$birth = new \DateTime( $this->field( 'birth_date' ) );
				$age   = $birth->diff( new \DateTime( 'now' ) );
				if ( $this->field( 'exact_birth_date_unknown' ) ) {
					// translators: %d is an approximate age in years.
					return sprintf( _n( 'age: ~%d', 'age: ~%d', $age->y, 'family-wiki' ), $age->y );
				}

				// translators: %d is an age in years.
				return sprintf( _n( 'age: %d', 'age: %d', $age->y, 'family-wiki' ), $age->y );
			} catch ( \Exception $e ) {
				return '';
			}
		}

		if ( 'death' === $type && $this->field( 'birth_date' ) && $this->field( 'death_date' ) ) {
			try {
				$birth = new \DateTime( $this->field( 'birth_date' ) );
				$death = new \DateTime( $this->field( 'death_date' ) );
				$age   = $birth->diff( $death );
				if ( $this->field( 'exact_birth_date_unknown' ) || $this->field( 'exact_death_date_unknown' ) ) {
					// translators: %d is an approximate age in years.
					return sprintf( _n( 'aged: ~%d', 'aged: ~%d', $age->y, 'family-wiki' ), $age->y );
				}

				// translators: %d is an age in years.
				return sprintf( _n( 'aged: %d', 'aged: %d', $age->y, 'family-wiki' ), $age->y );
			} catch ( \Exception $e ) {
				return '';
			}
		}

		return '';
	}

	private function person_link( $field, $name_field ) {
		$person = $this->field( $field );
		if ( $person ) {
			return '<a href="' . esc_url( Person::url( $person ) ) . '">' . esc_html( get_the_title( $person ) ) . '</a>';
		}

		$name = $this->field( $name_field );
		if ( $name ) {
			return Links::name_link( $name );
		}

		return '';
	}

	private function cross_wiki_value() {
		$pages = Cross_Wiki::get_remote_pages( get_post_field( 'post_name', $this->post_id ) );
		if ( empty( $pages ) ) {
			return '';
		}

		$links = array();
		foreach ( $pages as $page ) {
			$links[] = sprintf(
				'%1$s: <a href="%2$s">%3$s</a>',
				esc_html( $page['label'] ),
				esc_url( $page['url'] ),
				esc_html( $page['title'] )
			);
		}

		return implode( '<br />', $links );
	}

	private function related_pages_links() {
		$pages = get_posts(
			array(
				'post_type'      => Person::POST_TYPE,
				'post_parent'    => $this->post_id,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				),
			)
		);

		if ( empty( $pages ) ) {
			return '';
		}

		$links = array();
		foreach ( $pages as $page ) {
			if ( (int) $page->ID === $this->display_post_id ) {
				$links[] = '<strong>' . esc_html( get_the_title( $page ) ) . '</strong>';
			} else {
				$links[] = '<a href="' . esc_url( Person::url( $page ) ) . '">' . esc_html( get_the_title( $page ) ) . '</a>';
			}
		}

		return implode( '<br />', $links );
	}

	private function children_links() {
		$children = $this->field( 'children' );
		if ( empty( $children ) ) {
			return '';
		}

		$links = array();
		foreach ( $children as $child ) {
			$links[] = '<a href="' . esc_url( Person::url( $child ) ) . '">' . esc_html( get_the_title( $child ) ) . '</a>';
		}

		return implode( '<br />', $links );
	}

	private function siblings_links( $half_siblings = false ) {
		$siblings = Relationships::siblings( $this->post_id, $half_siblings );
		if ( empty( $siblings ) ) {
			return '';
		}

		$links = array();
		foreach ( $siblings as $sibling ) {
			$links[] = '<a href="' . esc_url( Person::url( $sibling ) ) . '">' . esc_html( get_the_title( $sibling ) ) . '</a>';
		}

		return implode( '<br />', $links );
	}

	private function spouse_value() {
		$marriages = $this->field( 'marriages' );
		if ( ! empty( $marriages ) ) {
			return $this->marriages_value( $marriages );
		}

		$links = array();
		foreach ( $this->field( 'spouse' ) as $spouse ) {
			$links[] = '<a href="' . esc_url( Person::url( $spouse ) ) . '">' . esc_html( get_the_title( $spouse ) ) . '</a>';
		}

		if ( $this->field( 'spouse_name' ) ) {
			$links[] = Links::name_link( $this->field( 'spouse_name' ) );
		}

		$details = array();
		if ( $this->field( 'marriage_date' ) ) {
			try {
				$details[] = sprintf(
					// translators: %s is a marriage date.
					__( 'married %s', 'family-wiki' ),
					self::linked_date( new \DateTime( $this->field( 'marriage_date' ) ) )
				);
			} catch ( \Exception $e ) {
				$details[] = sprintf(
					// translators: %s is a marriage date.
					__( 'married %s', 'family-wiki' ),
					esc_html( $this->field( 'marriage_date' ) )
				);
			}
		}

		if ( $this->field( 'marriage_place' ) ) {
			$details[] = esc_html( $this->field( 'marriage_place' ) );
		}

		if ( empty( $links ) ) {
			return implode( '<br />', $details );
		}

		$return = implode( '<br />', $links );
		if ( ! empty( $details ) ) {
			$return .= '<br /><span class="familypedia-infobox__meta">' . implode( '<br />', $details ) . '</span>';
		}

		return $return;
	}

	private function marriages_value( $marriages ) {
		$values = array();
		foreach ( $marriages as $marriage ) {
			$value = $this->marriage_value( $marriage );
			if ( $value ) {
				$values[] = '<div class="familypedia-infobox__marriage">' . $value . '</div>';
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
			$lines[] = '<a href="' . esc_url( Person::url( $marriage['spouse'] ) ) . '">' . esc_html( get_the_title( $marriage['spouse'] ) ) . '</a>';
		} elseif ( ! empty( $marriage['spouse_name'] ) ) {
			$lines[] = Links::name_link( $marriage['spouse_name'] );
		}

		$details = array();
		if ( isset( $marriage['relationship_type'] ) && 'partnership' === $marriage['relationship_type'] ) {
			$details[] = __( 'Unmarried partnership', 'family-wiki' );
		}
		if ( ! empty( $marriage['marriage_date'] ) ) {
			$details[] = sprintf(
				// translators: %s is a marriage date.
				( isset( $marriage['relationship_type'] ) && 'partnership' === $marriage['relationship_type'] ? __( 'together since %s', 'family-wiki' ) : __( 'married %s', 'family-wiki' ) ),
				self::linked_date( new \DateTime( Person::normalize_date( $marriage['marriage_date'] ) ) )
			);
		} elseif ( ! empty( $marriage['marriage_year'] ) ) {
			$details[] = sprintf(
				// translators: %s is a marriage year.
				( isset( $marriage['relationship_type'] ) && 'partnership' === $marriage['relationship_type'] ? __( 'together since %s', 'family-wiki' ) : __( 'married %s', 'family-wiki' ) ),
				esc_html( $marriage['marriage_year'] )
			);
		}

		if ( ! empty( $marriage['marriage_place'] ) ) {
			$details[] = esc_html( $marriage['marriage_place'] );
		}

		if ( ! empty( $marriage['ended_date'] ) ) {
			$details[] = $this->ended_value( $marriage['ended_reason'], self::linked_date( new \DateTime( Person::normalize_date( $marriage['ended_date'] ) ) ) );
		} elseif ( ! empty( $marriage['ended_year'] ) ) {
			$details[] = $this->ended_value( $marriage['ended_reason'], esc_html( $marriage['ended_year'] ) );
		} elseif ( ! empty( $marriage['ended_reason'] ) ) {
			$details[] = esc_html( strtolower( self::ended_reason_label( $marriage['ended_reason'] ) ) );
		}

		if ( ! empty( $details ) ) {
			$lines[] = '<span class="familypedia-infobox__meta">' . implode( '<br />', $details ) . '</span>';
		}

		return implode( '<br />', $lines );
	}

	private function ended_value( $reason, $date ) {
		if ( $reason ) {
			return sprintf(
				// translators: %1$s is a reason a marriage ended, %2$s is a date or year.
				__( '%1$s %2$s', 'family-wiki' ),
				esc_html( strtolower( self::ended_reason_label( $reason ) ) ),
				$date
			);
		}

		return sprintf(
			// translators: %s is a date or year.
			__( 'ended %s', 'family-wiki' ),
			$date
		);
	}

	public static function ended_reason_label( $reason ) {
		$labels = Person::ended_reason_choices();

		return isset( $labels[ $reason ] ) && $labels[ $reason ] ? $labels[ $reason ] : $reason;
	}
}
