<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template state.
/**
 * The reunion index.
 *
 * @package Familypedia
 */
namespace Family_Wiki;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$familypedia_page_title = __( 'Reunions and visits', 'family-wiki' );
require __DIR__ . '/partials/header.php';
?>
<h1><?php echo esc_html( $familypedia_page_title ); ?></h1>
<?php if ( Wiki_Page::can_create() ) : ?>
	<p><a class="familypedia-button" href="<?php echo esc_url( Reunion::edit_url() ); ?>"><?php esc_html_e( 'Add a reunion or visit', 'family-wiki' ); ?></a></p>
<?php endif; ?>
<?php $familypedia_reunions = Reunion::get_all(); ?>
<?php if ( ! $familypedia_reunions ) : ?>
	<p><?php esc_html_e( 'No reunions or visits recorded yet.', 'family-wiki' ); ?></p>
<?php else : ?>
	<ul class="familypedia-person-list">
		<?php foreach ( $familypedia_reunions as $familypedia_reunion ) : ?>
			<?php $familypedia_details = Reunion::data( $familypedia_reunion->ID ); ?>
			<li>
				<a href="<?php echo esc_url( Person::url( $familypedia_reunion ) ); ?>"><?php echo esc_html( get_the_title( $familypedia_reunion ) ); ?></a>
				<span class="familypedia-person-list__meta"><?php echo esc_html( implode( ' · ', array_filter( array( $familypedia_details['year'], $familypedia_details['location'] ) ) ) ); ?></span>
			</li>
		<?php endforeach; ?>
	</ul>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
