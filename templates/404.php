<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are render-local state.
/**
 * A page that does not exist yet.
 *
 * On a wiki this is not really an error: it is an invitation to write the page,
 * so an editor is offered the form with the name already filled in.
 *
 * @package Familypedia
 */

namespace Family_Wiki;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

status_header( 404 );
nocache_headers();

// The last segment names the page that is missing, not the one above it.
$familypedia_slug = (string) wp_app_get_route_var( 'related', '' );
if ( '' === $familypedia_slug ) {
	$familypedia_slug = (string) wp_app_get_route_var( 'person', '' );
}
$familypedia_name = trim( str_replace( array( '-', '_' ), ' ', urldecode( $familypedia_slug ) ) );
$familypedia_name = $familypedia_name ? mb_convert_case( $familypedia_name, MB_CASE_TITLE ) : '';

$familypedia_page_title = $familypedia_name ? $familypedia_name : __( 'Not found', 'family-wiki' );
require __DIR__ . '/partials/header.php';
?>

<article class="familypedia-missing">
	<h1><?php echo esc_html( $familypedia_page_title ); ?></h1>

	<?php if ( Editor::can_create() ) : ?>
		<p><?php esc_html_e( 'There is no page here yet.', 'family-wiki' ); ?></p>
		<p>
			<a class="familypedia-button" href="<?php echo esc_url( add_query_arg( 'name', $familypedia_name, home_url( '/' . App::URL_PATH . '/new/' ) ) ); ?>">
				<?php
				echo esc_html(
					$familypedia_name
						? sprintf(
							// translators: %s is a person's name.
							__( 'Create “%s”', 'family-wiki' ),
							$familypedia_name
						)
						: __( 'Add a person', 'family-wiki' )
				);
				?>
			</a>
		</p>
	<?php else : ?>
		<p><?php esc_html_e( 'There is no page here.', 'family-wiki' ); ?></p>
	<?php endif; ?>
</article>

<?php
require __DIR__ . '/partials/footer.php';
