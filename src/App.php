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
		add_action( 'admin_enqueue_scripts', array( Assets::class, 'enqueue_menu_style' ) );
		add_action( 'wp_enqueue_scripts', array( Assets::class, 'enqueue_menu_style' ) );
		add_action( 'wp_app_admin_bar_menu', array( $this, 'add_edit_link' ) );
	}

	/** Add the current page's editor to the masterbar. */
	public function add_edit_link( $admin_bar ) {
		global $wp_app_route;

		if ( self::URL_PATH !== get_query_var( 'wp_app_path' ) ) {
			return;
		}

		$template = $wp_app_route['template'] ?? '';
		$url      = '';
		$title    = __( 'Edit Page', 'family-wiki' );

		if ( 'index.php' === $template && Front_Page::can_edit() ) {
			$url = Front_Page::edit_url();
		} elseif ( 'person.php' === $template ) {
			$person = self::routed_person();
			if ( $person && in_array( $person->post_status, array( 'publish', 'private', 'draft' ), true ) && Editor::can_edit( $person->ID ) ) {
				if ( Reunion::is_reunion( $person ) ) {
					$url = Reunion::edit_url( $person );
				} elseif ( Wiki_Page::is_page( $person ) ) {
					$url = Interview::edit_url( $person );
				} else {
					$title = __( 'Edit Person', 'family-wiki' );
					$url   = Person::edit_url( $person );
				}
			}
		}

		// Replace any edit link inherited from the underlying WordPress query.
		$admin_bar->remove_node( 'edit' );
		if ( $url ) {
			$admin_bar->add_node(
				array(
					'id'    => 'edit',
					'title' => $title,
					'href'  => $url,
				)
			);
		}
	}

	protected function get_url_path(): string {
		return self::URL_PATH;
	}

	protected function get_template_dir(): string {
		return dirname( __DIR__ ) . '/templates';
	}

	protected function get_plugin_name(): string {
		// Keep app navigation concise; the plugin listing name includes a tagline.
		return 'Familypedia';
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
		$this->app->route( 'reunions', 'reunions.php' );
		$this->app->route( 'reunions/(?P<reunion_id>[0-9]+)/edit', 'edit-reunion.php', array( 'reunion_id' ) );
		$this->app->route( 'new-reunion', 'edit-reunion.php' );
		$this->app->route( 'stubs', 'stubs.php' );
		$this->app->route( 'settings', 'settings.php' );
		$this->app->route( 'interviews', 'interview-index.php' );
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
		$this->app->route( '(?P<person>.+)/reunion', 'edit-reunion.php', array( 'person' ) );
		$this->app->route( '(?P<person>.+)/add-page', 'edit-related.php', array( 'person' ) );
		$this->app->route( '(?P<person>.+)/interviews', 'interviews.php', array( 'person' ) );
		$this->app->route( '(?P<person>.+)/(?P<related>[^/]+)/edit', 'edit.php', array( 'person', 'related' ) );
		$this->app->route( '(?P<person>.+)/edit', 'edit.php', array( 'person' ) );
		$this->app->route( '(?P<person>.+)/(?P<related>[^/]+)', 'person.php', array( 'person', 'related' ) );
		$this->app->route( '(?P<person>.+)', 'person.php', array( 'person' ) );
	}

	protected function setup_menu(): void {
		$this->app->add_menu_item( 'all', __( 'All Pages', 'family-wiki' ), home_url( '/' . self::URL_PATH . '/all/' ) );
		$this->app->add_menu_item( 'reunions', __( 'Reunions and visits', 'family-wiki' ), Reunion::url() );

		$this->app->add_menu_item( 'tree', __( 'Family Tree', 'family-wiki' ), home_url( '/' . self::URL_PATH . '/tree/' ) );

		if ( Calendar::is_calendar_enabled() ) {
			$this->app->add_menu_item( 'calendar', __( 'Calendar', 'family-wiki' ), Calendar::get_calendar_url() );
		}
		if ( Calendar::is_birthdays_enabled() ) {
			$this->app->add_menu_item( 'birthdays', __( 'Birthdays', 'family-wiki' ), Calendar::get_birthdays_url() );
		}

		if ( current_user_can( 'edit_others_pages' ) ) {
			$this->app->add_menu_item( 'interviews', __( 'Interviews', 'family-wiki' ), home_url( '/' . self::URL_PATH . '/interviews/' ) );
		}

		if ( Editor::can_create() ) {
			$this->app->add_menu_item( 'new', __( 'Add Person', 'family-wiki' ), home_url( '/' . self::URL_PATH . '/new/' ), array( 'class' => 'familypedia-menu-section-start' ) );
		}

		// Keep management alongside browsing and creation in WpApp's single menu.
		$manage_args = array( 'class' => 'familypedia-menu-section-start' );
		if ( Editor::can_edit() ) {
			$this->app->add_menu_item( 'stubs', __( 'Stubs', 'family-wiki' ), Stubs::url(), $manage_args );
			$manage_args = array();
		}
		if ( Gedcom::can_use() ) {
			$this->app->add_menu_item( 'import-export', __( 'Import / Export', 'family-wiki' ), Gedcom::get_page_url(), $manage_args );
			$manage_args = array();
		}
		if ( current_user_can( 'manage_options' ) ) {
			$this->app->add_menu_item( 'settings', __( 'Settings', 'family-wiki' ), Settings::url(), $manage_args );
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

	/**
	 * Resolve the masthead section independently of the current page title.
	 *
	 * @param string       $template Current route template.
	 * @param \WP_Post|null $person Current record, when viewing one.
	 * @return array Section label and URL, or an empty array for the home trail.
	 */
	public static function breadcrumb_section( $template, $person = null ) {
		$sections = array(
			'reunions'   => array(
				'label'     => __( 'Reunions and visits', 'family-wiki' ),
				'templates' => array( 'reunions.php', 'edit-reunion.php' ),
			),
			'interviews' => array(
				'label'     => __( 'Interviews', 'family-wiki' ),
				'templates' => array( 'interview-index.php', 'interviews.php' ),
			),
		);

		if ( 'person.php' === $template && $person ) {
			if ( Reunion::is_reunion( $person ) ) {
				$template = 'reunions.php';
			} elseif ( Interview::is_session( $person ) ) {
				$template = 'interview-index.php';
			}
		}

		foreach ( $sections as $path => $section ) {
			if ( in_array( $template, $section['templates'], true ) ) {
				return array(
					'label' => $section['label'],
					'url'   => home_url( '/' . self::URL_PATH . '/' . $path . '/' ),
				);
			}
		}

		return array();
	}

	public function activate(): void {
		Main::setup();
	}

	public function deactivate(): void {
		flush_rewrite_rules();
	}
}
