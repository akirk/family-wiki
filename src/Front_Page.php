<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the_content is a core WordPress filter.
/**
 * The app's home page, kept in a post so it can be edited.
 *
 * The two sections the template used to print itself — the highlights box and
 * the list of recently updated people — are blocks now, and the front page is a
 * post holding them. That is what makes a family tree on the home page possible
 * without a setting for it: add the Family Tree block and move it where it
 * belongs.
 *
 * @package Familypedia
 */

namespace Family_Wiki;

class Front_Page {
	// Post type names are capped at 20 characters, which "familypedia_front_page"
	// is over.
	const POST_TYPE = 'familypedia_front';
	const OPTION = 'familypedia_front_page';

	const BLOCK_HIGHLIGHTS = 'familypedia/highlights';
	const BLOCK_RECENT = 'familypedia/recent';

	/**
	 * What a fresh front page holds, and what is rendered when the post is
	 * missing: the page as it looked before it could be edited, with the
	 * list of what changed most recently underneath.
	 */
	const DEFAULT_CONTENT = '<!-- wp:familypedia/highlights /-->' . "\n\n" . '<!-- wp:familypedia/recent /-->';

	const RECENT_LIMIT = 10;

	public function __construct() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'init', array( $this, 'register_blocks' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_editor_assets' ) );
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_filter( 'post_type_link', array( __CLASS__, 'post_type_link' ), 10, 2 );
	}

	/**
	 * One post, edited in the block editor and rendered by the app. It has no
	 * title of its own — the masthead already carries the site's name — and it is
	 * not publicly queryable, because the app serves it.
	 */
	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Front Page', 'family-wiki' ),
					'singular_name' => __( 'Front Page', 'family-wiki' ),
					'edit_item'     => __( 'Edit the front page', 'family-wiki' ),
					'view_item'     => __( 'View the front page', 'family-wiki' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				// The submenu below points straight at the one post, so the list
				// table this would add is of no use.
				'show_in_menu'        => false,
				'show_in_nav_menus'   => false,
				'show_in_rest'        => true,
				'hierarchical'        => false,
				'capability_type'     => 'page',
				'map_meta_cap'        => true,
				'supports'            => array( 'editor', 'revisions' ),
				'rewrite'             => false,
				'query_var'           => false,
				'has_archive'         => false,
			)
		);
	}

	public function register_blocks() {
		register_block_type(
			self::BLOCK_HIGHLIGHTS,
			array(
				'render_callback' => array( $this, 'render_highlights' ),
			)
		);

		register_block_type(
			self::BLOCK_RECENT,
			array(
				'attributes'      => array(
					'count' => array(
						'type'    => 'number',
						'default' => self::RECENT_LIMIT,
					),
				),
				'render_callback' => array( $this, 'render_recent' ),
			)
		);
	}

	public function enqueue_block_editor_assets() {
		wp_enqueue_script(
			'familypedia-front-page',
			Assets::url( 'front-page.js' ),
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-i18n' ),
			Assets::version( 'front-page.js' ),
			true
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'familypedia-front-page', 'family-wiki' );
		}
	}

	/**
	 * The front page is edited beneath the People menu rather than in a list
	 * table of its own: there is only ever one of it.
	 */
	public function admin_menu() {
		if ( ! current_user_can( 'edit_pages' ) ) {
			return;
		}

		$post = self::ensure_post();
		if ( ! $post ) {
			return;
		}

		add_submenu_page(
			'edit.php?post_type=' . Person::POST_TYPE,
			__( 'Front Page', 'family-wiki' ),
			__( 'Front Page', 'family-wiki' ),
			'edit_pages',
			'post.php?post=' . $post->ID . '&action=edit'
		);
	}

	/**
	 * The front page is the app's home, so that is where the editor's View link
	 * has to point.
	 */
	public static function post_type_link( $url, $post ) {
		if ( ! $post instanceof \WP_Post || self::POST_TYPE !== $post->post_type ) {
			return $url;
		}

		return self::url();
	}

	public static function url() {
		return Homepage::enabled_for_user() ? home_url( '/' ) : home_url( '/' . App::URL_PATH . '/' );
	}

	public static function get_post() {
		$post_id = (int) get_option( self::OPTION );
		if ( ! $post_id ) {
			return null;
		}

		$post = get_post( $post_id );
		if ( ! $post || self::POST_TYPE !== $post->post_type || 'trash' === $post->post_status ) {
			return null;
		}

		return $post;
	}

	/**
	 * The front page post, created the first time somebody needs it.
	 */
	public static function ensure_post() {
		$post = self::get_post();
		if ( $post ) {
			return $post;
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => self::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => __( 'Front Page', 'family-wiki' ),
				'post_content' => self::DEFAULT_CONTENT,
			)
		);

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return null;
		}

		update_option( self::OPTION, $post_id );

		return get_post( $post_id );
	}

	/**
	 * The rendered front page. An empty or missing post falls back to the
	 * default content, so the home page is never blank.
	 */
	public static function render() {
		$post    = self::get_post();
		$content = $post ? $post->post_content : '';

		if ( '' === trim( $content ) ) {
			$content = self::DEFAULT_CONTENT;
		}

		$content = apply_filters( 'the_content', $content );

		return Links::filter_content( $content );
	}

	/**
	 * Whether the front page already draws a family tree. What is rendered when
	 * the post is empty is the default content, so that is what gets asked.
	 */
	public static function has_tree() {
		$post    = self::get_post();
		$content = $post ? trim( $post->post_content ) : '';

		if ( '' === $content ) {
			$content = self::DEFAULT_CONTENT;
		}

		return false !== strpos( $content, 'wp:' . Tree::BLOCK_NAME . ' ' );
	}

	/**
	 * Add family trees to the front page, one per person given, ahead of the
	 * Recently Updated block if it is there. These are asked for a line at a
	 * time in the import review, so a page that already draws a tree gets
	 * another: the request was made about this branch, knowing what is
	 * already there.
	 *
	 * @param int[] $roots People to start the branches from.
	 * @return int[] The people a tree was added for, in the order they were added.
	 */
	public static function add_trees( $roots ) {
		$roots = array_values( array_unique( array_filter( array_map( 'intval', (array) $roots ) ) ) );
		if ( ! $roots ) {
			return array();
		}

		$post = self::ensure_post();
		if ( ! $post ) {
			return array();
		}

		$content = trim( $post->post_content );
		if ( '' === $content ) {
			// An empty post renders the default content, so appending to nothing
			// would quietly drop the blocks the page was showing.
			$content = self::DEFAULT_CONTENT;
		}

		$trees = array();
		foreach ( $roots as $root ) {
			$trees[] = '<!-- wp:family-wiki/tree {"root":' . $root . ',"showDates":true} /-->';
		}
		$trees = implode( "\n\n", $trees );

		$marker = '<!-- wp:' . self::BLOCK_RECENT . ' ';
		$marker_pos = strpos( $content, $marker );

		if ( false !== $marker_pos ) {
			$content = rtrim( substr( $content, 0, $marker_pos ) ) . "\n\n" . $trees . "\n\n" . substr( $content, $marker_pos );
		} else {
			$content = rtrim( $content ) . "\n\n" . $trees;
		}

		$updated = wp_update_post(
			array(
				'ID'           => $post->ID,
				'post_content' => wp_slash( $content ),
			),
			true
		);

		return is_wp_error( $updated ) ? array() : $roots;
	}

	public static function can_edit() {
		$post = self::get_post();

		return $post ? current_user_can( 'edit_post', $post->ID ) : current_user_can( 'edit_pages' );
	}

	/**
	 * Where the front page is edited. Empty when the post could not be created.
	 */
	public static function edit_url() {
		$post = self::ensure_post();
		if ( ! $post ) {
			return '';
		}

		return (string) get_edit_post_link( $post->ID, '' );
	}

	public function render_highlights() {
		$box = Highlights::render();

		if ( $box || ! current_user_can( 'edit_pages' ) ) {
			return $box;
		}

		return '<p class="familypedia-block-notice">'
			. esc_html__( 'Family Highlights: nobody on this wiki has a date recorded yet.', 'family-wiki' )
			. '</p>';
	}

	public function render_recent( $attributes ) {
		$count = isset( $attributes['count'] ) ? (int) $attributes['count'] : self::RECENT_LIMIT;
		$count = max( 1, min( 50, $count ) );

		$recent = Person::get_all(
			array(
				'posts_per_page' => $count,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);

		$return = '<section class="familypedia-recent"><h2>' . esc_html__( 'Recently updated', 'family-wiki' ) . '</h2>';

		if ( empty( $recent ) ) {
			$return .= '<p>' . esc_html__( 'This wiki has no people yet.', 'family-wiki' ) . '</p>';

			// Somebody arriving with a family tree app's export should not have
			// to find Import at the far end of the menu: a GEDCOM is the usual
			// starting point, so it is offered first, and adding by hand second.
			$actions = array();
			if ( Gedcom::can_import() ) {
				$actions[] = '<a class="familypedia-button familypedia-button--primary" href="'
					. esc_url( Gedcom::get_page_url() ) . '">'
					. esc_html__( 'Import a GEDCOM file', 'family-wiki' ) . '</a>';
			}
			if ( Editor::can_create() ) {
				$actions[] = '<a class="familypedia-button' . ( $actions ? '' : ' familypedia-button--primary' ) . '" href="'
					. esc_url( home_url( '/' . App::URL_PATH . '/new/' ) ) . '">'
					. esc_html__( 'Add the first person', 'family-wiki' ) . '</a>';
			}
			if ( $actions ) {
				$return .= '<p class="familypedia-recent__actions">' . implode( ' ', $actions ) . '</p>';
			}
			if ( Gedcom::can_import() ) {
				$return .= '<p class="familypedia-field__hint">'
					. esc_html__( 'GEDCOM is the file format Ancestry, MyHeritage, FamilySearch, Gramps and most other family tree apps export to.', 'family-wiki' )
					. '</p>';
			}

			return $return . '</section>';
		}

		$return .= '<ul class="familypedia-person-list">';
		foreach ( $recent as $person ) {
			$return .= '<li><a href="' . esc_url( Person::url( $person ) ) . '">'
				. esc_html( get_the_title( $person ) ) . '</a> <span class="familypedia-person-list__meta">'
				. esc_html( get_the_modified_date( '', $person ) ) . '</span></li>';
		}
		$return .= '</ul>';

		// All Pages lists people and pages alike, so it needs comparing
		// against the same total, not just the people among them.
		$total = count( Person::get_all( array( 'fields' => 'ids' ) ) );

		// Nothing to see beyond what the list above already shows.
		if ( $total > count( $recent ) ) {
			$return .= '<p><a href="' . esc_url( home_url( '/' . App::URL_PATH . '/all/' ) ) . '">'
				. esc_html__( 'List all pages', 'family-wiki' )
				. '</a></p>';
		}

		return $return . '</section>';
	}
}
