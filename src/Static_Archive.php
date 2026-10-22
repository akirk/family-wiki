<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the_content is a core WordPress filter.
/**
 * Support for the Static Archive plugin.
 *
 * A family wiki is worth keeping long after the site that holds it, so people
 * are offered to Static Archive and rendered the way the app renders them:
 * infobox first, then the wiki text with its links resolved. Static Archive's
 * built-in renderer only knows posts and pages, so everything here is what a
 * third-party post type has to supply for itself.
 *
 * @package Familypedia
 */

namespace Family_Wiki;

class Static_Archive {
	public function __construct() {
		add_filter( 'static_archive_post_types', array( __CLASS__, 'add_post_type' ) );
		// After the built-in renderer at 5, which leaves this post type alone.
		add_filter( 'static_archive_post_html', array( __CLASS__, 'post_html' ), 20, 2 );
		add_filter( 'static_archive_post_markdown', array( __CLASS__, 'post_markdown' ), 20, 4 );
	}

	/**
	 * Offer people to Static Archive. The post type is not public, so it would
	 * not otherwise appear in Static Archive's settings at all.
	 *
	 * @param string[] $post_types Post type names.
	 * @return string[]
	 */
	public static function add_post_type( $post_types ) {
		if ( ! is_array( $post_types ) ) {
			$post_types = array();
		}

		$post_types[] = Person::POST_TYPE;

		return $post_types;
	}

	/**
	 * The HTML body saved for a person.
	 *
	 * @param string   $html    Raw or previously filtered HTML body.
	 * @param \WP_Post $wp_post Post object.
	 * @return string
	 */
	public static function post_html( $html, $wp_post ) {
		if ( ! self::supports( $wp_post ) ) {
			return $html;
		}

		// Somebody has already rendered this; do not stack a second infobox.
		if ( false !== strpos( (string) $html, 'familypedia-infobox' ) ) {
			return $html;
		}

		return self::assets() . self::body( $wp_post );
	}

	/**
	 * The Markdown body saved for a person.
	 *
	 * Derived from the body without the inlined stylesheet: a page of CSS is not
	 * prose, and Static Archive would otherwise carry it into the Markdown.
	 *
	 * @param string|null $markdown  Markdown body, or null to derive from HTML.
	 * @param \WP_Post    $wp_post   Post object.
	 * @param object      $generator Static Archive generator instance.
	 * @param string      $html      Filtered HTML body.
	 * @return string|null
	 */
	public static function post_markdown( $markdown, $wp_post, $generator, $html ) {
		unset( $html );

		if ( null !== $markdown || ! self::supports( $wp_post ) ) {
			return $markdown;
		}

		if ( ! is_object( $generator ) || ! method_exists( $generator, 'html_to_markdown' ) ) {
			return $markdown;
		}

		$facts_id = Infobox::facts_post_id_for( $wp_post->ID );
		$content  = $wp_post->post_content;

		if ( $facts_id ) {
			$content = Bio::replace_shortcode_with_title( $content, $wp_post->ID );
		}

		$content  = self::rendered_content( $wp_post, $content );
		$content  = Links::filter_content( $content );
		$markdown = $generator->html_to_markdown( $content );

		if ( ! $facts_id ) {
			return $markdown;
		}

		return self::facts_markdown( $facts_id, $wp_post->ID, $generator ) . $markdown;
	}

	/**
	 * The infobox as a Markdown list.
	 *
	 * The HTML infobox is a definition list inside a floated aside, which a
	 * generic HTML-to-Markdown pass turns into a run-on line with the labels
	 * welded to their values. Laying the same facts out directly keeps them
	 * readable in the Markdown copy.
	 */
	private static function facts_markdown( $facts_id, $display_post_id, $generator ) {
		$infobox = new Infobox( $facts_id, $display_post_id );
		$lines   = array();

		foreach ( $infobox->rows() as $row ) {
			$value = $generator->html_to_markdown( str_replace( array( '<br />', '<br>' ), ' / ', $row['value'] ) );
			$value = trim( preg_replace( '/\s+/', ' ', $value ) );

			if ( '' === $value ) {
				continue;
			}

			$lines[] = '- **' . $row['label'] . ':** ' . $value;
		}

		if ( empty( $lines ) ) {
			return '';
		}

		return implode( "\n", $lines ) . "\n\n";
	}

	private static function supports( $wp_post ) {
		return $wp_post instanceof \WP_Post && Person::POST_TYPE === $wp_post->post_type;
	}

	/**
	 * A person's page, as the app renders it: infobox, then the wiki text.
	 */
	private static function body( $wp_post ) {
		$facts_id = Infobox::facts_post_id_for( $wp_post->ID );
		$content  = $wp_post->post_content;

		if ( $facts_id ) {
			// The infobox carries these facts, so the shortcode is left as the
			// name alone rather than repeating them in prose.
			$content = Bio::replace_shortcode_with_title( $content, $wp_post->ID );
		}

		$content = self::rendered_content( $wp_post, $content );
		$content = Links::filter_content( $content );

		if ( ! $facts_id ) {
			return $content;
		}

		$infobox = new Infobox( $facts_id, $wp_post->ID );

		return $infobox->render() . $content;
	}

	/**
	 * Run the content filters with this person in the loop, so shortcodes and
	 * blocks resolve against them rather than whatever the generator last had.
	 */
	private static function rendered_content( $wp_post, $content ) {
		global $post;

		$original = $post;
		$post     = $wp_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $post );

		Tree::reset_expanded();
		$content = apply_filters( 'the_content', $content );

		$post = $original; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		if ( $post ) {
			setup_postdata( $post );
		} else {
			wp_reset_postdata();
		}

		return $content;
	}

	/**
	 * The styles and behaviour the archived page needs, inlined: an archive is
	 * a folder of files, with nothing to enqueue from.
	 *
	 * Only the content styles go in. The app's own chrome would restyle the
	 * archive's pages around content that is no longer in the app.
	 */
	private static function assets() {
		$css = Assets::contents( 'content.css' ) . "\n" . Assets::contents( 'tree.css' );
		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- This is a generated standalone archive document, not a WordPress page.
		$out = trim( $css ) ? '<style>' . $css . '</style>' : '';

		if ( Settings::get_infobox_settings()['collapse_mobile'] ) {
			$js = Assets::contents( 'infobox.js' );
			if ( trim( $js ) ) {
				// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- This is a generated standalone archive document, not a WordPress page.
				$out .= '<script>' . $js . '</script>';
			}
		}

		return $out;
	}
}
