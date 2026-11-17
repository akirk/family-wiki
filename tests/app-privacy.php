<?php
/** Run with php tests/app-privacy.php; app protection independent of site privacy. */
namespace WpApp {
	class BaseApp { protected $app; }
	class WpApp {
		public function __construct( $templates, $path, $config ) { $GLOBALS['app_privacy_config'] = $config; }
	}
}
namespace WpApp\Rest {
	class Access {
		public static function protect_post_type( $type, $capability ) { $GLOBALS['app_privacy_rest'][ $type ] = $capability; }
	}
}
namespace Family_Wiki {
	class Main {}
	class Homepage {}
}
namespace {
	function add_action( ...$args ) {}
	function get_option( $key, $default = false ) { return $GLOBALS['app_privacy_options'][ $key ] ?? $default; }
	function verify_app_privacy( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }
	require __DIR__ . '/../src/Person.php';
	require __DIR__ . '/../src/Front_Page.php';
	require __DIR__ . '/../src/App.php';
	foreach ( array( -1, 0, 1 ) as $visibility ) {
		$GLOBALS['app_privacy_options'] = array( 'blog_public' => $visibility, 'family_wiki_app_migration' => '2.0.0' );
		$GLOBALS['app_privacy_rest'] = array();
		new Family_Wiki\App();
		$config = $GLOBALS['app_privacy_config'];
		verify_app_privacy( true === $config['require_login'], 'Migrated app must require login even after whole-site privacy is disabled.' );
		verify_app_privacy( 'edit_others_pages' === $config['require_capability'], 'App must retain the editor capability requirement.' );
		foreach ( array( Family_Wiki\Person::POST_TYPE, Family_Wiki\Front_Page::POST_TYPE ) as $type ) {
			verify_app_privacy( in_array( $type, $config['post_types'], true ), 'Migrated content must remain owned by the protected app.' );
			verify_app_privacy( 'edit_others_pages' === $GLOBALS['app_privacy_rest'][ $type ], 'Migrated content must retain REST read protection.' );
		}
	}
	echo "App login, capability and REST protection survive disabling site privacy.\n";
}
