<?php
/**
 * The WpApp application: routes, masterbar, and plugin lifecycle.
 *
 * @package Familypedia
 */

namespace Family_Wiki;

use WpApp\WpApp;
use WpApp\BaseApp;

class App extends BaseApp {
	const URL_PATH = 'familypedia';
	const VERSION = '2.0.0';

	public function __construct() {
		$this->app = new WpApp(
			$this->get_template_dir(),
			$this->get_url_path(),
			array(
				'app_name'                     => $this->get_plugin_name(),
				'app_name_textdomain'          => 'family-wiki',
				'require_login'                => true,
				'require_capability'           => 'edit_others_pages',
				'launcher'                     => true,
				'app_icon'                     => 'dashicons-groups',
				'app_icon_background'          => 'linear-gradient(135deg, #11998e, #38ef7d)',
				'app_icon_color'               => '#fff',
				'app_icon_shadow'              => true,
				// Owned content: REST reads are gated with the app's capability and
				// OpenStation keeps these menus out of its dock.
				'post_types'                   => array( Person::POST_TYPE, Front_Page::POST_TYPE ),
				// A wiki about a family is read by the family, so the masterbar
				// is only useful once you are signed in.
				'show_masterbar_for_anonymous' => false,
			)
		);

		// Explicitly gate REST reads as well as the app templates.
		\WpApp\Rest\Access::protect_post_type( Person::POST_TYPE, 'edit_others_pages' );
		\WpApp\Rest\Access::protect_post_type( Front_Page::POST_TYPE, 'edit_others_pages' );

		new Main();
		new Homepage();

		add_action( 'init', array( $this, 'register_post_types' ) );
	}

	protected function get_url_path(): string {
		return self::URL_PATH;
	}

	protected function get_template_dir(): string {
		return dirname( __DIR__ ) . '/templates';
	}

	protected function get_plugin_name(): string {
		if ( ! function_exists( 'get_file_data' ) ) {
			return 'Familypedia';
		}

		$plugin_data = get_file_data( dirname( __DIR__ ) . '/family-wiki.php', array( 'name' => 'Plugin Name' ) );

		return $plugin_data['name'] ? $plugin_data['name'] : 'Familypedia';
	}

	protected function setup_storage(): void {
		// People are posts and their facts are post meta, so there is no custom
		// table to set up.
	}

	protected function setup_database(): void {
		$this->setup_storage();
	}

	/**
	 * Routes are matched in the order they are registered, so everything the
	 * app serves itself comes before the catch-all that looks up a person.
	 */
	protected function setup_routes(): void {
		$this->app->route( '', 'index.php' );
		$this->app->route( 'all', 'all.php' );
		$this->app->route( 'new', 'edit.php' );
		$this->app->route( 'new-page', 'edit-page.php' );
		$this->app->route( 'tree', 'tree.php' );
		$this->app->route( 'tree/(?P<person>.+)', 'tree.php', array( 'person' ) );
		$this->app->route( Gedcom::URL_PATH, 'import-export.php' );

		if ( Calendar::is_calendar_enabled() ) {
			$this->app->route( 'calendar', 'calendar.php' );
			// Written as \d\d? rather than \d{1,2}: the router reads {…} as its
			// own placeholder syntax and would rewrite the quantifier.
			$this->app->route( 'calendar/(?P<calendar_month>\d\d?)', 'calendar.php', array( 'calendar_month' ) );
		}

		if ( Calendar::is_birthdays_enabled() ) {
			$this->app->route( 'birthdays', 'birthdays.php' );
		}

		// A person, optionally with a related page beneath them, and the form
		// for editing either one's facts.
		$this->app->route( '(?P<person>.+)/add-page', 'edit-related.php', array( 'person' ) );
		$this->app->route( '(?P<person>.+)/(?P<related>[^/]+)/edit', 'edit.php', array( 'person', 'related' ) );
		$this->app->route( '(?P<person>.+)/edit', 'edit.php', array( 'person' ) );
		$this->app->route( '(?P<person>.+)/(?P<related>[^/]+)', 'person.php', array( 'person', 'related' ) );
		$this->app->route( '(?P<person>.+)', 'person.php', array( 'person' ) );
	}

	protected function setup_menu(): void {
		$this->app->add_menu_item( 'all', __( 'All Pages', 'family-wiki' ), home_url( '/' . self::URL_PATH . '/all/' ) );

		if ( Calendar::is_calendar_enabled() ) {
			$this->app->add_menu_item( 'calendar', __( 'Calendar', 'family-wiki' ), Calendar::get_calendar_url() );
		}

		if ( Calendar::is_birthdays_enabled() ) {
			$this->app->add_menu_item( 'birthdays', __( 'Birthdays', 'family-wiki' ), Calendar::get_birthdays_url() );
		}

		$this->app->add_menu_item( 'tree', __( 'Tree', 'family-wiki' ), home_url( '/' . self::URL_PATH . '/tree/' ) );

		if ( Editor::can_create() ) {
			$this->app->add_menu_item( 'new', __( 'Add Person', 'family-wiki' ), home_url( '/' . self::URL_PATH . '/new/' ) );
		}

		if ( Wiki_Page::can_create() ) {
			$this->app->add_menu_item( 'new-page', __( 'Add Page', 'family-wiki' ), home_url( '/' . self::URL_PATH . '/new-page/' ) );
		}

		// Everything the app can be reached by is one menu, the one WpApp
		// builds: a second entry of the plugin's own would draw a second
		// Familypedia menu beside it, holding half the pages each.
		if ( Gedcom::can_use() ) {
			$this->app->add_menu_item( 'import-export', __( 'Import / Export', 'family-wiki' ), Gedcom::get_page_url() );
		}

		if ( current_user_can( 'manage_options' ) ) {
			$this->app->add_menu_item( 'settings', __( 'Settings', 'family-wiki' ), admin_url( 'options-general.php?page=' . Settings::PAGE ) );
		}
	}

	public function register_post_types(): void {
		Person::register_post_type();
		Person::register_meta();
	}

	/**
	 * The person a request is about, from the route.
	 */
	public static function routed_person() {
		$person  = wp_app_get_route_var( 'person', '' );
		$related = wp_app_get_route_var( 'related', '' );

		if ( '' === $person ) {
			return null;
		}

		$path = $related ? $person . '/' . $related : $person;

		return Person::get_by_path( $path );
	}

	public function activate(): void {
		Main::setup();
	}

	public function deactivate(): void {
		flush_rewrite_rules();
	}
}
