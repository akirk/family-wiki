<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are render-local state.
/**
 * Every page on the wiki — people and the pages hung off them or standing on
 * their own alike — with an optional search.
 *
 * @package Familypedia
 */

namespace Family_Wiki;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$familypedia_search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

$familypedia_page_title = $familypedia_search
	? sprintf(
		// translators: %s is a search term.
		__( 'Search: %s', 'family-wiki' ),
		$familypedia_search
	)
	: __( 'All Pages', 'family-wiki' );

require __DIR__ . '/partials/header.php';

$familypedia_people = Person::get_all( $familypedia_search ? array( 's' => $familypedia_search ) : array() );

/**
 * Group by the first letter of the name, which is how you look someone up in a
 * list this long.
 */
$familypedia_groups = array();
foreach ( $familypedia_people as $familypedia_item ) {
	$familypedia_letter = mb_strtoupper( mb_substr( get_the_title( $familypedia_item ), 0, 1 ) );
	if ( ! preg_match( '/\p{L}/u', $familypedia_letter ) ) {
		$familypedia_letter = '#';
	}
	$familypedia_groups[ $familypedia_letter ][] = $familypedia_item;
}
ksort( $familypedia_groups );
?>

<h1><?php echo esc_html( $familypedia_page_title ); ?></h1>

<?php if ( empty( $familypedia_people ) ) : ?>
	<p><?php esc_html_e( 'Nothing found.', 'family-wiki' ); ?></p>
<?php else : ?>
	<p class="familypedia-people__count">
		<?php
		echo esc_html(
			sprintf(
				// translators: %d is a number of pages.
				_n( '%d page', '%d pages', count( $familypedia_people ), 'family-wiki' ),
				count( $familypedia_people )
			)
		);
		?>
	</p>

	<?php if ( count( $familypedia_groups ) > 1 ) : ?>
		<p class="familypedia-people__index">
			<?php foreach ( array_keys( $familypedia_groups ) as $familypedia_letter ) : ?>
				<a href="#familypedia-letter-<?php echo esc_attr( sanitize_title( $familypedia_letter ) ); ?>"><?php echo esc_html( $familypedia_letter ); ?></a>
			<?php endforeach; ?>
		</p>
	<?php endif; ?>

	<?php foreach ( $familypedia_groups as $familypedia_letter => $familypedia_group ) : ?>
		<section class="familypedia-people__group">
			<h2 id="familypedia-letter-<?php echo esc_attr( sanitize_title( $familypedia_letter ) ); ?>"><?php echo esc_html( $familypedia_letter ); ?></h2>
			<ul class="familypedia-person-list">
				<?php foreach ( $familypedia_group as $familypedia_item ) : ?>
					<?php
					$familypedia_birth = substr( (string) Person::field( 'birth_date', $familypedia_item->ID ), 0, 4 );
					$familypedia_death = substr( (string) Person::field( 'death_date', $familypedia_item->ID ), 0, 4 );

					// A lone year needs to say which one it is, as the tree does.
					if ( $familypedia_birth && $familypedia_death ) {
						$familypedia_years = $familypedia_birth . '–' . $familypedia_death;
					} elseif ( $familypedia_birth ) {
						$familypedia_years = '*' . $familypedia_birth;
					} elseif ( $familypedia_death ) {
						$familypedia_years = '†' . $familypedia_death;
					} else {
						$familypedia_years = '';
					}
					?>
					<li>
						<a href="<?php echo esc_url( Person::url( $familypedia_item ) ); ?>"><?php echo esc_html( get_the_title( $familypedia_item ) ); ?></a>
						<?php if ( Person::field( 'is_stub', $familypedia_item->ID ) ) : ?>
							<span class="familypedia-stub-badge"><?php esc_html_e( 'Stub', 'family-wiki' ); ?></span>
						<?php endif; ?>
						<?php if ( $familypedia_years ) : ?>
							<span class="familypedia-person-list__meta">(<?php echo esc_html( $familypedia_years ); ?>)</span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
	<?php endforeach; ?>
<?php endif; ?>

<?php
require __DIR__ . '/partials/footer.php';
