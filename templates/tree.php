<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are render-local state.
/**
 * The descendant tree from one person, or a choice of where to start.
 *
 * @package Familypedia
 */

namespace Family_Wiki;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$familypedia_root = App::routed_person();
$familypedia_people = Tree::get_people();

$familypedia_page_title = $familypedia_root
	? sprintf(
		// translators: %s is a person's name.
		__( 'Descendants of %s', 'family-wiki' ),
		get_the_title( $familypedia_root )
	)
	: __( 'Family Tree', 'family-wiki' );

require __DIR__ . '/partials/header.php';

Tree::reset_expanded();
?>

<h1><?php echo esc_html( $familypedia_page_title ); ?></h1>

<?php if ( $familypedia_root && isset( $familypedia_people[ $familypedia_root->ID ] ) ) : ?>
	<p id="person-actions" class="familypedia-tree__intro">
		<a href="<?php echo esc_url( Person::url( $familypedia_root ) ); ?>"><?php esc_html_e( 'Back to this person', 'family-wiki' ); ?></a>
		<?php if ( Editor::can_edit( $familypedia_root->ID ) && Editor::can_create() ) : ?>
			<a href="#add-family-member"><?php esc_html_e( 'Add family members', 'family-wiki' ); ?></a>
		<?php endif; ?>
	</p>
	<?php if ( Editor::can_edit( $familypedia_root->ID ) && Editor::can_create() ) : ?>
		<?php
		$familypedia_person = $familypedia_root;
		$familypedia_tree_entry = true;
		require __DIR__ . '/partials/add-relative.php';
		?>
	<?php endif; ?>
	<div class="familypedia-tree" data-family-descendants>
		<?php
		// Built from escaped parts.
		echo Tree::render_list( $familypedia_root->ID ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
	</div>
<?php else : ?>
	<?php
	/**
	 * People with no recorded parents head a branch, so they are where a tree
	 * naturally starts. The largest branches come first.
	 */
	$familypedia_roots = array();
	foreach ( $familypedia_people as $familypedia_id => $familypedia_entry ) {
		if ( ! empty( $familypedia_entry['parents'] ) || empty( $familypedia_entry['children'] ) ) {
			continue;
		}

		$familypedia_roots[ $familypedia_id ] = $familypedia_entry;
	}

	uasort(
		$familypedia_roots,
		function ( $a, $b ) {
			$difference = count( $b['children'] ) - count( $a['children'] );

			return $difference ? $difference : strcasecmp( $a['title'], $b['title'] );
		}
	);
	?>

	<?php if ( empty( $familypedia_roots ) ) : ?>
		<p><?php esc_html_e( 'No family relationships have been recorded yet.', 'family-wiki' ); ?></p>
	<?php else : ?>
		<p><?php esc_html_e( 'Pick the person to start a branch from.', 'family-wiki' ); ?></p>
		<ul class="familypedia-person-list">
			<?php foreach ( $familypedia_roots as $familypedia_id => $familypedia_entry ) : ?>
				<li>
					<a href="<?php echo esc_url( home_url( '/' . App::URL_PATH . '/tree/' . rawurlencode( get_post_field( 'post_name', $familypedia_id ) ) . '/' ) ); ?>"><?php echo esc_html( $familypedia_entry['title'] ); ?></a>
					<span class="familypedia-person-list__meta">
						<?php
						echo esc_html(
							sprintf(
								// translators: %d is a number of children.
								_n( '%d child', '%d children', count( $familypedia_entry['children'] ), 'family-wiki' ),
								count( $familypedia_entry['children'] )
							)
						);
						?>
					</span>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
<?php endif; ?>

<?php
require __DIR__ . '/partials/footer.php';
