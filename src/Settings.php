<?php
namespace Family_Wiki;

class Settings {
	const PAGE = 'family-wiki';
	const INFOBOX_OPTION = 'familypedia_infobox_settings';
	const APP_PAGES_OPTION = 'familypedia_app_pages';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_init', array( $this, 'admin_init' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
	}

	public function admin_menu() {
		add_options_page(
			__( 'Familypedia', 'family-wiki' ),
			__( 'Familypedia', 'family-wiki' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	public function admin_enqueue_scripts( $hook_suffix ) {
		if ( 'settings_page_' . self::PAGE !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'familypedia-settings',
			Assets::url( 'settings.css' ),
			array(),
			Assets::version( 'settings.css' )
		);

		wp_enqueue_script(
			'familypedia-settings',
			Assets::url( 'settings.js' ),
			array(),
			Assets::version( 'settings.js' ),
			true
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

		register_setting(
			self::PAGE,
			self::APP_PAGES_OPTION,
			array(
				'sanitize_callback' => array( $this, 'sanitize_app_pages_settings' ),
				'default'           => self::get_default_app_pages_settings(),
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

	public static function get_app_pages_settings() {
		$settings = get_option( self::APP_PAGES_OPTION, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		return array_merge( self::get_default_app_pages_settings(), $settings );
	}

	public static function get_default_app_pages_settings() {
		return array(
			'calendar'  => true,
			'birthdays' => true,
		);
	}

	public static function app_page_enabled( $page ) {
		$settings = self::get_app_pages_settings();

		return ! empty( $settings[ $page ] );
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$sites         = Cross_Wiki::get_sites();
		$current_pages = $this->get_page_choices();
		$infobox       = self::get_infobox_settings();
		$app_pages = self::get_app_pages_settings();
		$is_multisite  = function_exists( 'is_multisite' ) && is_multisite();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Familypedia Settings', 'family-wiki' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::PAGE );
				do_settings_sections( self::PAGE );
				?>

				<section class="familypedia-settings__section">
					<h2><?php esc_html_e( 'Infoboxes', 'family-wiki' ); ?></h2>
					<p><?php esc_html_e( 'Choose which optional sections and behaviors appear in person infoboxes.', 'family-wiki' ); ?></p>
					<div class="familypedia-settings__choices">
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

				<section class="familypedia-settings__section">
					<h2><?php esc_html_e( 'App pages', 'family-wiki' ); ?></h2>
					<p><?php esc_html_e( 'Familypedia serves these pages from the app. They do not need to be created as WordPress pages.', 'family-wiki' ); ?></p>
					<div class="familypedia-settings__choices">
						<label>
							<input type="checkbox" name="<?php echo esc_attr( self::APP_PAGES_OPTION ); ?>[calendar]" value="1" <?php checked( $app_pages['calendar'] ); ?> />
							<?php esc_html_e( 'Enable the family calendar page', 'family-wiki' ); ?>
						</label>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( self::APP_PAGES_OPTION ); ?>[birthdays]" value="1" <?php checked( $app_pages['birthdays'] ); ?> />
							<?php esc_html_e( 'Enable the birthdays page', 'family-wiki' ); ?>
						</label>
					</div>
				</section>

				<section class="familypedia-settings__section">
					<h2><?php esc_html_e( 'Cross-wiki links', 'family-wiki' ); ?></h2>
					<?php if ( ! $is_multisite ) : ?>
						<div class="familypedia-settings__notice">
							<p><?php esc_html_e( 'Cross-wiki links are only available on WordPress multisite networks. This site is not currently running as part of a multisite network.', 'family-wiki' ); ?></p>
						</div>
					<?php else : ?>
						<div class="familypedia-settings__intro">
							<h3><?php esc_html_e( 'How cross-wiki links work', 'family-wiki' ); ?></h3>
							<p><?php esc_html_e( 'Connect this family wiki to another family wiki in the same WordPress multisite network.', 'family-wiki' ); ?></p>
							<ul>
								<li><?php esc_html_e( 'People with the same URL slug are matched automatically and shown in the infobox under "Also on".', 'family-wiki' ); ?></li>
								<li><?php esc_html_e( 'Missing local wiki links are checked on the connected wikis before being marked as missing.', 'family-wiki' ); ?></li>
								<li><?php esc_html_e( 'Use page matches only when the same page has different URL slugs on two wikis.', 'family-wiki' ); ?></li>
							</ul>
						</div>

						<datalist id="familypedia-current-people">
							<?php $this->render_page_options( $current_pages ); ?>
						</datalist>

						<div class="familypedia-settings__sites" data-familypedia-sites>
							<?php if ( empty( $sites ) ) : ?>
								<p class="description" data-familypedia-empty><?php esc_html_e( 'No cross-wiki sites configured yet.', 'family-wiki' ); ?></p>
							<?php endif; ?>

							<?php foreach ( $sites as $index => $site ) : ?>
								<?php $this->render_site_card( $site, $index ); ?>
							<?php endforeach; ?>
						</div>

						<p>
							<button type="button" class="button" data-familypedia-add-site><?php esc_html_e( 'Add wiki', 'family-wiki' ); ?></button>
						</p>
					<?php endif; ?>
				</section>

				<?php submit_button(); ?>
			</form>

			<?php if ( $is_multisite ) : ?>
				<div class="familypedia-settings__site-template" data-familypedia-site-template>
					<?php
					$this->render_site_card(
						array(
							'url'   => '',
							'label' => '',
							'slugs' => array(),
						),
						'__site__'
					);
					?>
				</div>

				<div class="familypedia-settings__mapping-template" data-familypedia-mapping-template>
					<?php $this->render_mapping_row( '__site__', '__mapping__', '', '', 'familypedia-remote-people-__site__' ); ?>
				</div>
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
			$site_id = empty( $site['site_id'] ) ? 0 : absint( $site['site_id'] );
			if ( ! $site_id && ! empty( $site['url'] ) ) {
				$site_id = $this->get_blog_id_for_url( $site['url'] );
			}

			if ( ! $site_id || ! $this->current_user_can_reference_site( $site_id ) || get_current_blog_id() === $site_id ) {
				continue;
			}

			$url = untrailingslashit( esc_url_raw( get_home_url( $site_id ) ) );
			if ( empty( $url ) || untrailingslashit( home_url() ) === $url ) {
				continue;
			}

			$details = get_blog_details( $site_id );
			$return[] = array(
				'site_id' => $site_id,
				'url'     => $url,
				'label'   => empty( $site['label'] ) ? $this->get_site_name( $site_id, $details ) : sanitize_text_field( $site['label'] ),
				'slugs'   => $this->sanitize_slug_mappings( $site ),
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

	public function sanitize_app_pages_settings( $settings ) {
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		return array(
			'calendar'  => ! empty( $settings['calendar'] ),
			'birthdays' => ! empty( $settings['birthdays'] ),
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
		$site_id       = empty( $site['site_id'] ) ? $this->get_blog_id_for_url( isset( $site['url'] ) ? $site['url'] : '' ) : absint( $site['site_id'] );
		$remote_pages  = empty( $site_id ) ? array() : $this->get_page_choices( $site_id );
		$remote_list   = 'familypedia-remote-people-' . $index;
		$network_sites = $this->get_member_site_choices();
		?>
		<section class="familypedia-settings__site" data-familypedia-site="<?php echo esc_attr( $index ); ?>">
			<div class="familypedia-settings__site-header">
				<h3><?php echo empty( $site['label'] ) ? esc_html__( 'Cross-wiki site', 'family-wiki' ) : esc_html( $site['label'] ); ?></h3>
				<button type="button" class="button familypedia-settings__remove" data-familypedia-remove-site><?php esc_html_e( 'Remove wiki', 'family-wiki' ); ?></button>
			</div>

			<div class="familypedia-settings__fields">
				<p class="familypedia-settings__field">
					<label><?php esc_html_e( 'Network site', 'family-wiki' ); ?></label>
					<select class="regular-text" name="<?php echo esc_attr( Cross_Wiki::OPTION . '[' . $index . '][site_id]' ); ?>">
						<option value=""><?php esc_html_e( 'Select a wiki site', 'family-wiki' ); ?></option>
						<?php foreach ( $network_sites as $network_site ) : ?>
							<option value="<?php echo esc_attr( $network_site['site_id'] ); ?>" <?php selected( $site_id, $network_site['site_id'] ); ?>><?php echo esc_html( $network_site['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p class="familypedia-settings__field">
					<label><?php esc_html_e( 'Label', 'family-wiki' ); ?></label>
					<input class="regular-text" type="text" name="<?php echo esc_attr( Cross_Wiki::OPTION . '[' . $index . '][label]' ); ?>" value="<?php echo esc_attr( $site['label'] ); ?>" />
				</p>
			</div>

			<datalist id="<?php echo esc_attr( $remote_list ); ?>">
				<?php $this->render_page_options( $remote_pages ); ?>
			</datalist>

			<h4><?php esc_html_e( 'Person matches', 'family-wiki' ); ?></h4>
			<p class="description"><?php esc_html_e( 'Add a page match when automatic matching cannot find the same page because the two wikis use different URL slugs. Save after selecting a wiki to load page suggestions from that site.', 'family-wiki' ); ?></p>

			<div data-familypedia-mappings>
				<?php
				$mapping_index = 0;
				foreach ( $site['slugs'] as $local_slug => $remote_slug ) {
					$this->render_mapping_row( $index, $mapping_index, $local_slug, $remote_slug, $remote_list );
					++$mapping_index;
				}
				?>
			</div>

			<p>
				<button type="button" class="button" data-familypedia-add-mapping><?php esc_html_e( 'Add person match', 'family-wiki' ); ?></button>
			</p>
		</section>
		<?php
	}

	private function render_mapping_row( $site_index, $mapping_index, $local_slug, $remote_slug, $remote_list ) {
		?>
		<div class="familypedia-settings__mapping" data-familypedia-mapping>
			<p class="familypedia-settings__field">
				<label><?php esc_html_e( 'Local person', 'family-wiki' ); ?></label>
				<input type="text" list="familypedia-current-people" name="<?php echo esc_attr( Cross_Wiki::OPTION . '[' . $site_index . '][mappings][' . $mapping_index . '][local]' ); ?>" value="<?php echo esc_attr( $local_slug ); ?>" placeholder="local-person-slug" />
			</p>
			<p class="familypedia-settings__field">
				<label><?php esc_html_e( 'Peer wiki person', 'family-wiki' ); ?></label>
				<input type="text" list="<?php echo esc_attr( $remote_list ); ?>" name="<?php echo esc_attr( Cross_Wiki::OPTION . '[' . $site_index . '][mappings][' . $mapping_index . '][remote]' ); ?>" value="<?php echo esc_attr( $remote_slug ); ?>" placeholder="remote-person-slug" />
			</p>
			<p>
				<button type="button" class="button familypedia-settings__remove" data-familypedia-remove-mapping><?php esc_html_e( 'Remove', 'family-wiki' ); ?></button>
			</p>
		</div>
		<?php
	}

	private function render_page_options( $pages ) {
		foreach ( $pages as $page ) {
			echo '<option value="' . esc_attr( $page['slug'] ) . '" label="' . esc_attr( $page['title'] ) . '"></option>';
		}
	}

	private function get_page_choices( $site_id = null ) {
		$restore = false;
		if ( $site_id ) {
			$site_id = absint( $site_id );
			if ( ! $site_id ) {
				return array();
			}

			switch_to_blog( $site_id );
			$restore = true;
		}

		$people  = Person::get_all();
		$choices = array();
		foreach ( $people as $person ) {
			$path      = Person::path( $person );
			$choices[] = array(
				'slug'  => $path,
				'title' => get_the_title( $person ) . ' (' . $path . ')',
			);
		}

		if ( $restore ) {
			restore_current_blog();
		}

		return $choices;
	}

	private function get_blog_id_for_url( $site_url ) {
		if ( empty( $site_url ) ) {
			return 0;
		}

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

	private function get_member_site_choices() {
		if ( ! function_exists( 'is_multisite' ) || ! is_multisite() ) {
			return array();
		}

		$user_sites = get_blogs_of_user( get_current_user_id() );
		if ( empty( $user_sites ) || ! is_array( $user_sites ) ) {
			return array();
		}

		$choices = array();
		foreach ( $user_sites as $user_site ) {
			$site_id = empty( $user_site->userblog_id ) ? 0 : absint( $user_site->userblog_id );
			if ( ! $site_id || get_current_blog_id() === $site_id ) {
				continue;
			}

			$details = get_blog_details( $site_id );
			if ( ! $details || ! empty( $details->deleted ) || ! empty( $details->spam ) || ! empty( $details->archived ) ) {
				continue;
			}

			$choices[] = array(
				'site_id' => $site_id,
				'label'   => $this->get_site_name( $site_id, $details ),
			);
		}

		usort(
			$choices,
			function ( $a, $b ) {
				return strcasecmp( $a['label'], $b['label'] );
			}
		);

		return $choices;
	}

	private function current_user_can_reference_site( $site_id ) {
		foreach ( $this->get_member_site_choices() as $site ) {
			if ( absint( $site['site_id'] ) === absint( $site_id ) ) {
				return true;
			}
		}

		return false;
	}

	private function get_site_name( $site_id, $details = null ) {
		if ( ! $details ) {
			$details = get_blog_details( $site_id );
		}

		return $details && ! empty( $details->blogname ) ? $details->blogname : preg_replace( '/^https?:\/\//', '', get_home_url( $site_id ) );
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
