<?php
namespace Family_Wiki;

class ACF_Field_Marriages_Loader {
	public function __construct() {
		add_action( 'acf/include_field_types', array( $this, 'register_field_type' ) );
	}

	public function register_field_type() {
		if ( ! class_exists( 'acf_field' ) ) {
			return;
		}

		require_once __DIR__ . '/class-acf-field-marriages-type.php';
		acf_register_field_type( __NAMESPACE__ . '\ACF_Field_Marriages' );
	}
}
