<?php
namespace Family_Wiki;

class Shortcodes {
	public function __construct() {
		add_shortcode( 'born', array( $this, 'born' ) );
		add_shortcode( 'died', array( $this, 'died' ) );

		// You can add more shortcodes by hooking into this.
		do_action( 'family_wiki_shortcodes' );
	}

	public function born( $atts, $content ) {
		if ( empty( $atts['date'] ) ) {
			return $content;
		}
		try {
			$birth = new \DateTime( $atts['date'] );
		} catch ( \Exception $e ) {
			return $atts['date'];
		}
		if ( ! $birth ) return $atts['date'];
		$return = date_i18n( get_option( 'date_format' ), $birth->format( 'U' ) );
		if ( get_option( 'family_wiki_calendar_page' ) ) {
			$return = '<a href="' . get_option( 'family_wiki_calendar_page' ) . '#' . date_i18n( 'F', $birth->format( 'U' ) ). '">' . $return . '</a>';
		}

		$age = '';

		if ( isset( $atts['showage'] ) || in_array( 'showage', $atts ) ) {
			$age = $birth->diff( new \DateTime( 'now' ) );
			// translators: %d is an age in years.
			$age = ' (' . sprintf( _n( 'age %d', 'age %d', $age->y, 'family-wiki' ), $age->y ) . ')';
		}

		return $return . $age;
	}

	function died( $atts, $content ) {
		if ( empty( $atts['date'] ) || empty( $atts['birth'] ) ) {
			return $content;
		}
		if ( 4 === strlen( trim( $atts['date'] ) ) && is_numeric( $atts['date'] ) ) {
			$atts['date'] .= '-12-31';
		}
		if ( 4 === strlen( trim( $atts['birth'] ) ) && is_numeric( $atts['birth'] ) ) {
			$atts['birth'] .= '-12-31';
		}
		try {
			$death = new \DateTime( $atts['date'] );
		} catch ( \Exception $e ) {
			return $atts['date'];
		}
		if ( ! $death ) return $atts['date'];

		try {

			$birth = new \DateTime( $atts['birth'] );
		} catch ( \Exception $e ) {
			return $atts['date'];
		}
		if ( ! $birth ) return $atts['date'];

		$age = $birth->diff( $death );

		$return = date_i18n( get_option( 'date_format' ), $death->format( 'U' ) );
		if ( get_option( 'family_wiki_calendar_page' ) ) {
			$return = '<a href="' . get_option( 'family_wiki_calendar_page' ) . '#' . date_i18n( 'F', $death->format( 'U' ) ) . '">' . $return . '</a>';
		}
		// translators: %d is an age in years.
		return $return . ' (' . sprintf( _n( 'aged %d', 'aged %d', $age->y, 'family-wiki' ), $age->y ) . ')';
	}
}
