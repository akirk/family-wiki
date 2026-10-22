<?php
/**
 * Family relationships, derived from parents or recorded directly.
 *
 * Siblings can be recorded directly when their shared parents are unknown.
 *
 * @package Familypedia
 */

namespace Family_Wiki;

class Relationships {
	/**
	 * A person's siblings, or their half-siblings.
	 *
	 * Full siblings share both recorded parents. When only one parent is known
	 * there is nothing to tell the two apart, so everyone found is treated as a
	 * full sibling rather than claiming a half-relationship that is a guess.
	 *
	 * @param int  $post_id       The person.
	 * @param bool $half_siblings Return the half-siblings instead.
	 * @return \WP_Post[]
	 */
	public static function siblings( $post_id, $half_siblings = false ) {
		$father_children = self::parent_children( $post_id, 'father' );
		$mother_children = self::parent_children( $post_id, 'mother' );
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

		if ( ! Person::field( 'father', $post_id ) || ! Person::field( 'mother', $post_id ) ) {
			$siblings = $half;
			$half     = array();
		}

		// Explicit siblings have unspecified parentage; do not infer full or half.
		foreach ( Person::field( 'siblings', $post_id ) as $sibling ) {
			if ( (int) $sibling->ID !== (int) $post_id && ! isset( $half[ $sibling->ID ] ) ) {
				$siblings[ $sibling->ID ] = $sibling;
			}
		}

		return array_values( $half_siblings ? $half : $siblings );
	}

	/**
	 * The other children of one of a person's parents, keyed by post ID.
	 *
	 * @return \WP_Post[]
	 */
	public static function parent_children( $post_id, $parent_field ) {
		$parent = Person::field( $parent_field, $post_id );
		if ( ! $parent ) {
			return array();
		}

		$return = array();
		foreach ( Person::field( 'children', $parent->ID ) as $child ) {
			if ( (int) $post_id !== (int) $child->ID ) {
				$return[ (int) $child->ID ] = $child;
			}
		}

		return $return;
	}

	/**
	 * Everyone a person is or was married to, as links.
	 *
	 * @return string[]
	 */
	public static function spouse_links( $post_id ) {
		$links = array();

		foreach ( Person::field( 'marriages', $post_id ) as $marriage ) {
			if ( isset( $marriage['relationship_type'] ) && 'partnership' === $marriage['relationship_type'] ) {
				continue;
			}

			if ( ! empty( $marriage['spouse'] ) ) {
				$links[] = self::person_link( $marriage['spouse'] );
			} elseif ( ! empty( $marriage['spouse_name'] ) ) {
				$links[] = esc_html( $marriage['spouse_name'] );
			}
		}

		foreach ( Person::field( 'spouse', $post_id ) as $spouse ) {
			$links[] = self::person_link( $spouse );
		}

		if ( Person::field( 'spouse_name', $post_id ) ) {
			$links[] = esc_html( Person::field( 'spouse_name', $post_id ) );
		}

		return array_values( array_unique( array_filter( $links ) ) );
	}

	public static function person_link( $person ) {
		$post_id = Person::to_id( $person );
		if ( ! $post_id || ! get_post( $post_id ) ) {
			return '';
		}

		return '<a href="' . esc_url( Person::url( $post_id ) ) . '">' . esc_html( get_the_title( $post_id ) ) . '</a>';
	}
}
