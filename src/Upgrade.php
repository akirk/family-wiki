<?php
namespace Family_Wiki;

/** Reviewed, resumable migration from the page/ACF data model. */
class Upgrade {
	const OPTION = 'family_wiki_app_migration';
	const META = '_family_wiki_legacy_url';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
		add_action( 'admin_post_family_wiki_migrate', array( $this, 'submit' ) );
		add_action( 'template_redirect', array( $this, 'redirect' ), 1 );
		add_action( 'init', array( $this, 'setup' ), 100 );
	}

	public function setup() {
		if ( '2.0.0' !== get_option( 'family_wiki_app_version' ) ) {
			Main::setup();
			update_option( 'family_wiki_app_version', '2.0.0' );
		}
	}

	/** All pages remain selectable: a person need not have any facts yet. */
	public static function candidates() {
		return get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => array( 'publish', 'private', 'draft', 'pending', 'future' ),
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'exclude'        => array_filter( array( (int) get_option( 'page_on_front' ), (int) get_option( 'page_for_posts' ), (int) get_option( 'wp_page_for_privacy_policy' ) ) ),
			)
		);
	}

	public function menu() {
		add_management_page( __( 'Upgrade Familypedia', 'family-wiki' ), __( 'Upgrade Familypedia', 'family-wiki' ), 'manage_options', 'family-wiki-upgrade', array( $this, 'render' ) );
	}

	public function notice() {
		if ( ! current_user_can( 'manage_options' ) || get_option( self::OPTION ) || ! self::candidates() ) {
			return;
		}
		echo '<div class="notice notice-warning"><p><a href="' . esc_url( admin_url( 'tools.php?page=family-wiki-upgrade' ) ) . '">';
		esc_html_e( 'A new version of Familypedia is ready. Review your existing pages to bring them into the updated wiki.', 'family-wiki' );
		echo '</a></p></div>';
	}

	/** Family relationships are stored as IDs; ACF need not be active to read them. */
	public static function relationships( $post_id ) {
		$groups = array();
		foreach ( array( 'father', 'mother', 'children', 'spouse' ) as $field ) {
			$groups[ $field ] = array_values( array_filter( array_map( 'absint', (array) get_post_meta( $post_id, $field, true ) ) ) );
		}
		foreach ( (array) get_post_meta( $post_id, 'marriages', true ) as $marriage ) {
			if ( is_array( $marriage ) && ! empty( $marriage['spouse'] ) ) {
				$groups['spouse'][] = absint( $marriage['spouse'] );
			}
		}
		$groups['spouse'] = array_values( array_unique( $groups['spouse'] ) );
		return $groups;
	}

	/** Explain initial selections and include connected relatives and nested pages. */
	public static function review_rows() {
		$rows = array();
		foreach ( self::candidates() as $page ) {
			$fields = array();
			foreach ( array_keys( Person::FIELDS ) as $field ) {
				$value = get_post_meta( $page->ID, $field, true );
				if ( ! in_array( $value, array( '', null, false, array(), 0, '0' ), true ) ) {
					$fields[] = $field;
				}
			}
			$rows[ $page->ID ] = array(
				'page'          => $page,
				'relationships' => self::relationships( $page->ID ),
				'fields'        => $fields,
				'reason'        => $fields ? 'facts' : '',
			);
		}
		do {
			$changed = false;
			foreach ( $rows as $id => $row ) {
				$related = array_merge( ...array_values( $row['relationships'] ) );
				foreach ( $related as $related_id ) {
					if ( ! isset( $rows[ $related_id ] ) ) {
						continue;
					}
					if ( $rows[ $id ]['reason'] && ! $rows[ $related_id ]['reason'] ) {
						$rows[ $related_id ]['reason'] = 'relationship';
						$changed = true;
					} elseif ( $rows[ $related_id ]['reason'] && ! $rows[ $id ]['reason'] ) {
						$rows[ $id ]['reason'] = 'relationship';
						$changed = true;
					}
				}
				$parent_id = (int) $row['page']->post_parent;
				if ( ! isset( $rows[ $parent_id ] ) ) {
					continue;
				}
				if ( $rows[ $id ]['reason'] && ! $rows[ $parent_id ]['reason'] ) {
					$rows[ $parent_id ]['reason'] = 'hierarchy';
					$changed = true;
				} elseif ( $rows[ $parent_id ]['reason'] && ! $rows[ $id ]['reason'] ) {
					$rows[ $id ]['reason'] = 'hierarchy';
					$changed = true;
				}
			}
		} while ( $changed );
		return $rows;
	}

	private function page_link( $post_id ) {
		$page = get_post( $post_id );
		if ( ! $page ) {
			return esc_html__( 'Page no longer exists', 'family-wiki' );
		}
		$url = 'publish' === $page->post_status ? get_permalink( $page ) : get_edit_post_link( $page->ID, 'raw' );
		return '<a href="' . esc_url( $url ? $url : get_permalink( $page ) ) . '" target="_blank" rel="noopener noreferrer">'
			. esc_html( $page->post_title ? $page->post_title : __( '(no title)', 'family-wiki' ) )
			. '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'family-wiki' ) . '</span></a>';
	}

	public function render() {
		$rows = self::review_rows();
		echo '<div class="wrap"><h1>' . esc_html__( 'Upgrade Familypedia', 'family-wiki' ) . '</h1><p>';
		esc_html_e( 'The updated Familypedia gives your family its own space for people, stories, photos and family trees. Choose which existing pages to bring into it. Their content, images and family relationships will be kept, and their old addresses will redirect to the updated wiki.', 'family-wiki' );
		echo '</p><p>';
		esc_html_e( 'Pages with family details are selected automatically, together with connected relatives and nested pages. Each row explains its selection. Pages without these details are left unchecked for you to review; you can still include them. Page links open in a new tab.', 'family-wiki' );
		echo '</p><p>';
		esc_html_e( 'Family relationships come from your saved mother, father, children and spouse fields. Page nesting is separate: it places a story or other page underneath another page. Include connected relatives and nested pages together.', 'family-wiki' );
		echo '</p><p><strong>';
		esc_html_e( 'Back up your database before continuing.', 'family-wiki' );
		echo '</strong></p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="family_wiki_migrate" />';
		wp_nonce_field( 'family_wiki_migrate' );
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Include', 'family-wiki' ) . '</th><th>' . esc_html__( 'Page', 'family-wiki' ) . '</th><th>' . esc_html__( 'Family relationships', 'family-wiki' ) . '</th><th>' . esc_html__( 'Page nesting', 'family-wiki' ) . '</th><th>' . esc_html__( 'Initial selection', 'family-wiki' ) . '</th></tr></thead><tbody>';
		$labels = array(
			'father'   => __( 'Father', 'family-wiki' ),
			'mother'   => __( 'Mother', 'family-wiki' ),
			'children' => __( 'Children', 'family-wiki' ),
			'spouse'   => __( 'Spouse', 'family-wiki' ),
		);
		$reasons = array(
			'facts'        => __( 'Selected: saved family details found.', 'family-wiki' ),
			'relationship' => __( 'Selected: connected to a relative with family details.', 'family-wiki' ),
			'hierarchy'    => __( 'Selected: nested with a selected page.', 'family-wiki' ),
			''             => __( 'Unchecked: no saved family details or connections found. Review this page to decide whether to include it.', 'family-wiki' ),
		);
		foreach ( $rows as $id => $row ) {
			$page = $row['page'];
			// translators: %s is the page title.
			echo '<tr><td><input type="checkbox" name="pages[]" value="' . esc_attr( $id ) . '" ' . checked( (bool) $row['reason'], true, false ) . ' aria-label="' . esc_attr( sprintf( __( 'Include %s', 'family-wiki' ), $page->post_title ) ) . '" /></td><td>';
			echo $this->page_link( $id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in page_link().
			echo '<br /><code>' . esc_html( get_page_uri( $page ) ) . '</code></td><td>';
			$relationships = array();
			foreach ( $row['relationships'] as $field => $related_ids ) {
				$links = array();
				foreach ( $related_ids as $related_id ) {
					$links[] = $this->page_link( $related_id );
				}
				if ( ! $links && in_array( $field, array( 'father', 'mother', 'spouse' ), true ) ) {
					$name = get_post_meta( $id, $field . '_name', true );
					if ( $name ) {
						$links[] = esc_html( $name );
					}
				}
				if ( $links ) {
					$relationships[] = esc_html( $labels[ $field ] ) . ': ' . implode( ', ', $links );
				}
			}
			echo $relationships ? implode( '<br />', $relationships ) : esc_html__( 'No family relationships recorded.', 'family-wiki' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Labels and links escaped above.
			echo '</td><td>';
			if ( $page->post_parent ) {
				echo esc_html__( 'Under:', 'family-wiki' ) . ' ' . $this->page_link( $page->post_parent ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in page_link().
			} else {
				esc_html_e( 'Top-level page', 'family-wiki' );
			}
			echo '</td><td>' . esc_html( $reasons[ $row['reason'] ] ) . '</td></tr>';
		}
		echo '</tbody></table>';
		submit_button( __( 'Bring selected pages into the updated wiki', 'family-wiki' ) );
		echo '</form></div>';
	}

	public function submit() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot migrate this wiki.', 'family-wiki' ), 403 );
		}
		check_admin_referer( 'family_wiki_migrate' );
		$ids = isset( $_POST['pages'] ) ? array_map( 'absint', (array) $_POST['pages'] ) : array();
		$result = self::migrate( $ids );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . Person::POST_TYPE ) );
		exit;
	}

	public static function migrate( $ids ) {
		$pages = array();
		foreach ( self::candidates() as $page ) {
			if ( in_array( (int) $page->ID, $ids, true ) ) {
				$pages[ $page->ID ] = $page;
			}
		}
		if ( ! $pages ) {
			return 0;
		}
		// Validate the whole selection before changing any posts.
		foreach ( $pages as $page ) {
			if ( ! $page->post_parent && in_array( $page->post_name, array( 'all', 'new', 'new-page', 'tree', 'import-export', 'calendar', 'birthdays' ), true ) ) {
				return new \WP_Error( 'family_wiki_reserved_slug', __( 'A selected page uses a reserved app URL. Change its slug before migrating.', 'family-wiki' ) );
			}
			if ( $page->post_parent && ! isset( $pages[ $page->post_parent ] ) && Person::POST_TYPE !== get_post_type( $page->post_parent ) ) {
				return new \WP_Error( 'family_wiki_parent', __( 'Select the parent pages too before migrating this hierarchy.', 'family-wiki' ) );
			}
		}
		foreach ( self::candidates() as $candidate ) {
			if ( isset( $pages[ $candidate->post_parent ] ) && ! isset( $pages[ $candidate->ID ] ) ) {
				return new \WP_Error( 'family_wiki_children', __( 'Select the child pages too before migrating this hierarchy.', 'family-wiki' ) );
			}
		}
		foreach ( $pages as $page ) {
			$related_ids = array_merge( ...array_values( self::relationships( $page->ID ) ) );
			foreach ( array_filter( array_map( 'absint', $related_ids ) ) as $related_id ) {
				if ( 'page' === get_post_type( $related_id ) && ! isset( $pages[ $related_id ] ) ) {
					return new \WP_Error( 'family_wiki_relationship', __( 'Select the related people too before migrating these relationships.', 'family-wiki' ) );
				}
			}
		}
		foreach ( $pages as $page ) {
			// Capture every old path before moving any of its ancestors.
			if ( ! get_post_meta( $page->ID, self::META, true ) ) {
				update_post_meta( $page->ID, self::META, untrailingslashit( get_permalink( $page ) ) );
			}
		}
		foreach ( $pages as $page ) {
			$result = wp_update_post(
				array(
					'ID'        => $page->ID,
					'post_type' => Person::POST_TYPE,
				),
				true
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			// Raw ACF values already store relationship IDs. Only dates need a new format.
			foreach ( array( 'birth_date', 'death_date', 'marriage_date' ) as $field ) {
				$value = get_post_meta( $page->ID, $field, true );
				$date = Person::normalize_date( $value );
				if ( $date ) {
					update_post_meta( $page->ID, $field, $date );
				}
			}
			$marriages = get_post_meta( $page->ID, 'marriages', true );
			if ( is_array( $marriages ) ) {
				update_post_meta( $page->ID, 'marriages', Person::sanitize_marriages( $marriages ) );
			}
		}
		foreach ( array(
			'family_wiki_infobox_settings' => Settings::INFOBOX_OPTION,
			'family_wiki_virtual_pages'    => Settings::APP_PAGES_OPTION,
			'family_wiki_cross_wiki_sites' => Cross_Wiki::OPTION,
		) as $old => $new ) {
			if ( false !== get_option( $old, false ) ) {
				add_option( $new, get_option( $old ) );
			}
		}
		$front = get_post( (int) get_option( 'page_on_front' ) );
		if ( $front && ! get_option( self::OPTION ) ) {
			$app_front = Front_Page::ensure_post();
			if ( $app_front && trim( $front->post_content ) ) {
				wp_update_post(
					wp_slash(
						array(
							'ID'           => $app_front->ID,
							'post_content' => Front_Page::DEFAULT_CONTENT . "\n\n" . $front->post_content,
						)
					)
				);
			}
		}
		update_option( self::OPTION, '2.0.0' );
		Main::flush_family_data_cache();
		flush_rewrite_rules();
		return count( $pages );
	}

	public function redirect() {
		global $wp;
		if ( is_feed() || is_preview() ) {
			return;
		}
		$legacy_id = absint( get_query_var( 'page_id' ) );
		if ( $legacy_id && Person::POST_TYPE === get_post_type( $legacy_id ) && get_post_meta( $legacy_id, self::META, true ) ) {
			nocache_headers();
			wp_safe_redirect( Person::url( $legacy_id ), 302 );
			exit;
		}
		if ( is_front_page() ) {
			return;
		}
		if ( preg_match( '#^family-wiki/(calendar|birthdays)(/.*)?$#', $wp->request, $match ) ) {
			nocache_headers();
			wp_safe_redirect( home_url( '/' . App::URL_PATH . '/' . $match[1] . ( $match[2] ?? '/' ) ), 302 );
			exit;
		}
		$url = untrailingslashit( home_url( '/' . $wp->request ) );
		$posts = get_posts(
			array(
				'post_type'      => array( 'page', Person::POST_TYPE ),
				'post_status'    => array( 'publish', 'private', 'draft', 'pending', 'future' ),
				'posts_per_page' => 1,
				'meta_key'       => self::META,
				'meta_value'     => $url,
			)
		);
		if ( $posts ) {
			nocache_headers();
			wp_safe_redirect( Person::POST_TYPE === $posts[0]->post_type ? Person::url( $posts[0] ) : get_permalink( $posts[0] ), 302 );
			exit;
		}
	}
}
