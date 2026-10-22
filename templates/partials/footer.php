<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template partial variables are provided by the parent template.
/**
 * Closing markup for every app page.
 *
 * @package Familypedia
 */

namespace Family_Wiki;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
		</main>

		<footer class="familypedia-footer">
			<nav aria-label="<?php esc_attr_e( 'Familypedia', 'family-wiki' ); ?>">
				<a href="<?php echo esc_url( home_url( '/' . App::URL_PATH . '/all/' ) ); ?>"><?php esc_html_e( 'All Pages', 'family-wiki' ); ?></a>
				<?php if ( Calendar::is_calendar_enabled() ) : ?>
					<a href="<?php echo esc_url( Calendar::get_calendar_url() ); ?>"><?php esc_html_e( 'Calendar', 'family-wiki' ); ?></a>
				<?php endif; ?>
				<?php if ( Calendar::is_birthdays_enabled() ) : ?>
					<a href="<?php echo esc_url( Calendar::get_birthdays_url() ); ?>"><?php esc_html_e( 'Birthdays', 'family-wiki' ); ?></a>
				<?php endif; ?>
				<a href="<?php echo esc_url( home_url( '/' . App::URL_PATH . '/tree/' ) ); ?>"><?php esc_html_e( 'Tree', 'family-wiki' ); ?></a>
				<?php if ( Editor::can_create() ) : ?>
					<a href="<?php echo esc_url( home_url( '/' . App::URL_PATH . '/new/' ) ); ?>"><?php esc_html_e( 'Add Person', 'family-wiki' ); ?></a>
				<?php endif; ?>
				<?php if ( Wiki_Page::can_create() ) : ?>
					<a href="<?php echo esc_url( Wiki_Page::add_url() ); ?>"><?php esc_html_e( 'Add Page', 'family-wiki' ); ?></a>
				<?php endif; ?>
				<?php if ( Gedcom::can_use() ) : ?>
					<a href="<?php echo esc_url( Gedcom::get_page_url() ); ?>"><?php esc_html_e( 'Import / Export', 'family-wiki' ); ?></a>
				<?php endif; ?>
			</nav>
		</footer>
	</div>

	<?php wp_app_body_close(); ?>
</body>
</html>
