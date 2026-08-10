<?php
namespace Family_Wiki;

class Settings {
	const PAGE = 'family-wiki';
	const INFOBOX_OPTION = 'family_wiki_infobox_settings';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_init', array( $this, 'admin_init' ) );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar_menu' ), 80 );
	}

	public function admin_menu() {
		add_options_page(
			__( 'Family Wiki', 'family-wiki' ),
			__( 'Family Wiki', 'family-wiki' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	public function admin_init() {
		register_setting(
			self::PAGE,
			Cross_Wiki::OPTION,
			array(
				'sanitize_callback' => array( $this, 'sanitize_cross_wiki_sites' ),
				'default'           => array(),
			)
		);

		register_setting(
			self::PAGE,
			self::INFOBOX_OPTION,
			array(
				'sanitize_callback' => array( $this, 'sanitize_infobox_settings' ),
				'default'           => self::get_default_infobox_settings(),
			)
		);
	}

	public static function get_infobox_settings() {
		$settings = get_option( self::INFOBOX_OPTION, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		return array_merge( self::get_default_infobox_settings(), $settings );
	}

	public static function get_default_infobox_settings() {
		return array(
			'show_related_pages' => true,
			'show_cross_wiki'    => true,
			'collapse_mobile'    => true,
		);
	}

	public function admin_bar_menu( \WP_Admin_Bar $wp_admin_bar ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$wp_admin_bar->add_node(
			array(
				'id'     => 'family-wiki-settings',
				'parent' => 'site-name',
				'title'  => __( 'Family Wiki Settings', 'family-wiki' ),
				'href'   => admin_url( 'options-general.php?page=' . self::PAGE ),
			)
		);
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$sites        = Cross_Wiki::get_sites();
		$current_pages = $this->get_page_choices();
		$infobox      = self::get_infobox_settings();
		$is_multisite = function_exists( 'is_multisite' ) && is_multisite();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Family Wiki Settings', 'family-wiki' ); ?></h1>
			<style>
				.family-wiki-settings__sites {
					display: grid;
					gap: 1rem;
					max-width: 70rem;
				}

				.family-wiki-settings__section {
					max-width: 70rem;
				}

				.family-wiki-settings__intro {
					background: #fff;
					border-left: 4px solid #2271b1;
					box-shadow: 0 1px 1px rgba(0, 0, 0, 0.04);
					margin: 1rem 0;
					max-width: 70rem;
					padding: 1rem 1rem 0.75rem;
				}

				.family-wiki-settings__intro h2 {
					margin-top: 0;
				}

				.family-wiki-settings__intro ul {
					list-style: disc;
					margin-left: 1.5rem;
				}

				.family-wiki-settings__site {
					background: #fff;
					border: 1px solid #c3c4c7;
					padding: 1rem;
				}

				.family-wiki-settings__site-header {
					align-items: start;
					display: flex;
					gap: 1rem;
					justify-content: space-between;
				}

				.family-wiki-settings__fields {
					display: grid;
					gap: 1rem;
					grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr));
					margin-top: 1rem;
				}

				.family-wiki-settings__mapping {
					align-items: end;
					display: grid;
					gap: 0.75rem;
					grid-template-columns: minmax(12rem, 1fr) minmax(12rem, 1fr) auto;
					margin: 0.75rem 0;
				}

				.family-wiki-settings__field label {
					display: block;
					font-weight: 600;
					margin-bottom: 0.25rem;
				}

				.family-wiki-settings__choices label {
					display: block;
					margin: 0.65rem 0;
				}

				.family-wiki-settings__notice {
					background: #fff;
					border-left: 4px solid #dba617;
					box-shadow: 0 1px 1px rgba(0, 0, 0, 0.04);
					max-width: 70rem;
					padding: 1rem;
				}

				.family-wiki-settings__site-template,
				.family-wiki-settings__mapping-template {
					display: none;
				}

				.button.family-wiki-settings__remove {
					border-color: #b32d2e;
					color: #b32d2e;
				}

				.button.family-wiki-settings__remove:hover,
				.button.family-wiki-settings__remove:focus {
					background: #fcf0f1;
					border-color: #b32d2e;
					color: #b32d2e;
				}
			</style>
			<form method="post" action="options.php">
				<?php settings_fields( self::PAGE ); ?>

				<section class="family-wiki-settings__section">
					<h2><?php esc_html_e( 'Infoboxes', 'family-wiki' ); ?></h2>
					<p><?php esc_html_e( 'Choose which optional sections and behaviors appear in person infoboxes.', 'family-wiki' ); ?></p>
					<div class="family-wiki-settings__choices">
						<label>
							<input type="checkbox" name="<?php echo esc_attr( self::INFOBOX_OPTION ); ?>[show_related_pages]" value="1" <?php checked( $infobox['show_related_pages'] ); ?> />
							<?php esc_html_e( 'Show related child pages in the infobox', 'family-wiki' ); ?>
						</label>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( self::INFOBOX_OPTION ); ?>[show_cross_wiki]" value="1" <?php checked( $infobox['show_cross_wiki'] ); ?> />
							<?php esc_html_e( 'Show cross-wiki links in the "Also on" row', 'family-wiki' ); ?>
						</label>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( self::INFOBOX_OPTION ); ?>[collapse_mobile]" value="1" <?php checked( $infobox['collapse_mobile'] ); ?> />
							<?php esc_html_e( 'Collapse infoboxes by default on mobile screens', 'family-wiki' ); ?>
						</label>
					</div>
				</section>

				<h2><?php esc_html_e( 'Cross-wiki links', 'family-wiki' ); ?></h2>
				<?php if ( ! $is_multisite ) : ?>
					<div class="family-wiki-settings__notice">
						<p><?php esc_html_e( 'Cross-wiki links are only available on WordPress multisite networks. This site is not currently running as part of a multisite network.', 'family-wiki' ); ?></p>
					</div>
				<?php else : ?>
					<div class="family-wiki-settings__intro">
						<h3><?php esc_html_e( 'How cross-wiki links work', 'family-wiki' ); ?></h3>
						<p><?php esc_html_e( 'Connect this family wiki to another family wiki in the same WordPress multisite network.', 'family-wiki' ); ?></p>
						<ul>
							<li><?php esc_html_e( 'Pages with the same URL slug are matched automatically and shown in the infobox under "Also on".', 'family-wiki' ); ?></li>
							<li><?php esc_html_e( 'Missing local wiki links are checked on the connected wikis before being marked as missing.', 'family-wiki' ); ?></li>
							<li><?php esc_html_e( 'Use page matches only when the same page has different URL slugs on two wikis.', 'family-wiki' ); ?></li>
						</ul>
					</div>

					<datalist id="family-wiki-current-pages">
						<?php $this->render_page_options( $current_pages ); ?>
					</datalist>

					<div class="family-wiki-settings__sites" data-family-wiki-sites>
						<?php if ( empty( $sites ) ) : ?>
							<p class="description" data-family-wiki-empty><?php esc_html_e( 'No cross-wiki sites configured yet.', 'family-wiki' ); ?></p>
						<?php endif; ?>

						<?php foreach ( $sites as $index => $site ) : ?>
							<?php $this->render_site_card( $site, $index ); ?>
						<?php endforeach; ?>
					</div>

					<p>
						<button type="button" class="button" data-family-wiki-add-site><?php esc_html_e( 'Add wiki', 'family-wiki' ); ?></button>
					</p>
				<?php endif; ?>

				<?php submit_button(); ?>
			</form>

			<?php if ( $is_multisite ) : ?>
				<div class="family-wiki-settings__site-template" data-family-wiki-site-template>
					<?php $this->render_site_card( array( 'url' => '', 'label' => '', 'slugs' => array() ), '__site__' ); ?>
				</div>

				<div class="family-wiki-settings__mapping-template" data-family-wiki-mapping-template>
					<?php $this->render_mapping_row( '__site__', '__mapping__', '', '', 'family-wiki-remote-pages-__site__' ); ?>
				</div>

				<script>
					(function () {
						var sites = document.querySelector('[data-family-wiki-sites]');
						var siteTemplate = document.querySelector('[data-family-wiki-site-template]');
						var mappingTemplate = document.querySelector('[data-family-wiki-mapping-template]');

						document.addEventListener('click', function (event) {
							var addSite = event.target.closest('[data-family-wiki-add-site]');
							if (addSite) {
								var index = String(Date.now());
								var wrapper = document.createElement('div');
								wrapper.innerHTML = siteTemplate.innerHTML.replace(/__site__/g, index);
								sites.appendChild(wrapper.firstElementChild);
								var empty = document.querySelector('[data-family-wiki-empty]');
								if (empty) {
									empty.remove();
								}
								return;
							}

							var removeSite = event.target.closest('[data-family-wiki-remove-site]');
							if (removeSite) {
								removeSite.closest('[data-family-wiki-site]').remove();
								return;
							}

							var addMapping = event.target.closest('[data-family-wiki-add-mapping]');
							if (addMapping) {
								var site = addMapping.closest('[data-family-wiki-site]');
								var siteIndex = site.getAttribute('data-family-wiki-site');
								var mappingIndex = String(Date.now());
								var remoteList = 'family-wiki-remote-pages-' + siteIndex;
								var wrapper = document.createElement('div');
								wrapper.innerHTML = mappingTemplate.innerHTML
									.replace(/__site__/g, siteIndex)
									.replace(/__mapping__/g, mappingIndex)
									.replace(/family-wiki-remote-pages-__site__/g, remoteList);
								site.querySelector('[data-family-wiki-mappings]').appendChild(wrapper.firstElementChild);
								return;
							}

							var removeMapping = event.target.closest('[data-family-wiki-remove-mapping]');
							if (removeMapping) {
								removeMapping.closest('[data-family-wiki-mapping]').remove();
							}
						});
					}());
				</script>
			<?php endif; ?>
		</div>
		<?php
	}

	public function sanitize_cross_wiki_sites( $sites ) {
		if ( empty( $sites ) || ! is_array( $sites ) ) {
			return array();
		}

		$return = array();
		foreach ( $sites as $site ) {
			if ( empty( $site['url'] ) ) {
				continue;
			}

			$url = trim( $site['url'] );
			if ( ! preg_match( '#^https?://#i', $url ) ) {
				$url = 'https://' . $url;
			}

			$url = untrailingslashit( esc_url_raw( $url ) );
			if ( empty( $url ) || untrailingslashit( home_url() ) === $url ) {
				continue;
			}

			$return[] = array(
				'url'   => $url,
				'label' => empty( $site['label'] ) ? preg_replace( '/^https?:\/\//', '', $url ) : sanitize_text_field( $site['label'] ),
				'slugs' => $this->sanitize_slug_mappings( $site ),
			);
		}

		return $return;
	}

	public function sanitize_infobox_settings( $settings ) {
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		return array(
			'show_related_pages' => ! empty( $settings['show_related_pages'] ),
			'show_cross_wiki'    => ! empty( $settings['show_cross_wiki'] ),
			'collapse_mobile'    => ! empty( $settings['collapse_mobile'] ),
		);
	}

	private function sanitize_slug_mappings( $site ) {
		if ( isset( $site['mappings'] ) && is_array( $site['mappings'] ) ) {
			$slugs = array();
			foreach ( $site['mappings'] as $mapping ) {
				if ( empty( $mapping['local'] ) || empty( $mapping['remote'] ) ) {
					continue;
				}

				$local_slug  = $this->sanitize_slug_path( $mapping['local'] );
				$remote_slug = $this->sanitize_slug_path( $mapping['remote'] );
				if ( $local_slug && $remote_slug ) {
					$slugs[ $local_slug ] = $remote_slug;
				}
			}

			return $slugs;
		}

		return $this->parse_slug_mappings( isset( $site['slugs_text'] ) ? $site['slugs_text'] : '' );
	}

	private function render_site_card( $site, $index ) {
		$remote_pages = empty( $site['url'] ) ? array() : $this->get_page_choices( $site['url'] );
		$remote_list  = 'family-wiki-remote-pages-' . $index;
		?>
		<section class="family-wiki-settings__site" data-family-wiki-site="<?php echo esc_attr( $index ); ?>">
			<div class="family-wiki-settings__site-header">
				<h3><?php echo empty( $site['label'] ) ? esc_html__( 'Cross-wiki site', 'family-wiki' ) : esc_html( $site['label'] ); ?></h3>
				<button type="button" class="button family-wiki-settings__remove" data-family-wiki-remove-site><?php esc_html_e( 'Remove wiki', 'family-wiki' ); ?></button>
			</div>

			<div class="family-wiki-settings__fields">
				<p class="family-wiki-settings__field">
					<label><?php esc_html_e( 'Site URL', 'family-wiki' ); ?></label>
					<input class="regular-text" type="url" name="<?php echo esc_attr( Cross_Wiki::OPTION . '[' . $index . '][url]' ); ?>" value="<?php echo esc_attr( $site['url'] ); ?>" placeholder="https://example.com" />
				</p>
				<p class="family-wiki-settings__field">
					<label><?php esc_html_e( 'Label', 'family-wiki' ); ?></label>
					<input class="regular-text" type="text" name="<?php echo esc_attr( Cross_Wiki::OPTION . '[' . $index . '][label]' ); ?>" value="<?php echo esc_attr( $site['label'] ); ?>" />
				</p>
			</div>

			<datalist id="<?php echo esc_attr( $remote_list ); ?>">
				<?php $this->render_page_options( $remote_pages ); ?>
			</datalist>

			<h4><?php esc_html_e( 'Page matches', 'family-wiki' ); ?></h4>
			<p class="description"><?php esc_html_e( 'Add a page match when automatic matching cannot find the same page because the two wikis use different URL slugs. Save after adding a new site URL to load page suggestions from that wiki.', 'family-wiki' ); ?></p>

			<div data-family-wiki-mappings>
				<?php
				$mapping_index = 0;
				foreach ( $site['slugs'] as $local_slug => $remote_slug ) {
					$this->render_mapping_row( $index, $mapping_index, $local_slug, $remote_slug, $remote_list );
					$mapping_index++;
				}
				?>
			</div>

			<p>
				<button type="button" class="button" data-family-wiki-add-mapping><?php esc_html_e( 'Add page match', 'family-wiki' ); ?></button>
			</p>
		</section>
		<?php
	}

	private function render_mapping_row( $site_index, $mapping_index, $local_slug, $remote_slug, $remote_list ) {
		?>
		<div class="family-wiki-settings__mapping" data-family-wiki-mapping>
			<p class="family-wiki-settings__field">
				<label><?php esc_html_e( 'Local page', 'family-wiki' ); ?></label>
				<input type="text" list="family-wiki-current-pages" name="<?php echo esc_attr( Cross_Wiki::OPTION . '[' . $site_index . '][mappings][' . $mapping_index . '][local]' ); ?>" value="<?php echo esc_attr( $local_slug ); ?>" placeholder="local-page-slug" />
			</p>
			<p class="family-wiki-settings__field">
				<label><?php esc_html_e( 'Peer wiki page', 'family-wiki' ); ?></label>
				<input type="text" list="<?php echo esc_attr( $remote_list ); ?>" name="<?php echo esc_attr( Cross_Wiki::OPTION . '[' . $site_index . '][mappings][' . $mapping_index . '][remote]' ); ?>" value="<?php echo esc_attr( $remote_slug ); ?>" placeholder="remote-page-slug" />
			</p>
			<p>
				<button type="button" class="button family-wiki-settings__remove" data-family-wiki-remove-mapping><?php esc_html_e( 'Remove', 'family-wiki' ); ?></button>
			</p>
		</div>
		<?php
	}

	private function render_page_options( $pages ) {
		foreach ( $pages as $page ) {
			echo '<option value="' . esc_attr( $page['slug'] ) . '" label="' . esc_attr( $page['title'] ) . '"></option>';
		}
	}

	private function get_page_choices( $site_url = null ) {
		$restore = false;
		if ( $site_url ) {
			$blog_id = $this->get_blog_id_for_url( $site_url );
			if ( ! $blog_id ) {
				return array();
			}

			switch_to_blog( $blog_id );
			$restore = true;
		}

		$pages = get_pages(
			array(
				'post_status' => 'publish',
				'sort_column' => 'post_title',
			)
		);

		$choices = array();
		foreach ( $pages as $page ) {
			$choices[] = array(
				'slug'  => get_page_uri( $page ),
				'title' => get_the_title( $page ) . ' (' . get_page_uri( $page ) . ')',
			);
		}

		if ( $restore ) {
			restore_current_blog();
		}

		return $choices;
	}

	private function get_blog_id_for_url( $site_url ) {
		if ( ! function_exists( 'is_multisite' ) || ! is_multisite() ) {
			return 0;
		}

		$site_url = untrailingslashit( $site_url );
		foreach ( get_sites() as $site ) {
			if ( untrailingslashit( get_home_url( $site->blog_id ) ) === $site_url ) {
				return (int) $site->blog_id;
			}
		}

		return 0;
	}

	private function parse_slug_mappings( $text ) {
		if ( empty( $text ) || ! is_string( $text ) ) {
			return array();
		}

		$slugs = array();
		$lines = preg_split( '/\r\n|\r|\n/', $text );
		foreach ( $lines as $line ) {
			$line = trim( preg_replace( '/\s+#.*$/', '', $line ) );
			if ( '' === $line ) {
				continue;
			}

			$parts = preg_split( '/\s*(?:=>|=|,)\s*/', $line, 2 );
			if ( 2 !== count( $parts ) ) {
				continue;
			}

			$local_slug  = $this->sanitize_slug_path( $parts[0] );
			$remote_slug = $this->sanitize_slug_path( $parts[1] );
			if ( $local_slug && $remote_slug ) {
				$slugs[ $local_slug ] = $remote_slug;
			}
		}

		return $slugs;
	}

	private function format_slug_mappings( $slugs ) {
		if ( empty( $slugs ) || ! is_array( $slugs ) ) {
			return '';
		}

		$lines = array();
		foreach ( $slugs as $local_slug => $remote_slug ) {
			$lines[] = $local_slug . ' = ' . $remote_slug;
		}

		return implode( "\n", $lines );
	}

	private function sanitize_slug_path( $slug ) {
		$slug  = trim( strtolower( $slug ), " \t\n\r\0\x0B/" );
		$parts = array_filter( explode( '/', $slug ) );
		foreach ( $parts as $index => $part ) {
			$parts[ $index ] = sanitize_title_with_dashes( $part );
		}

		return implode( '/', array_filter( $parts ) );
	}
}
