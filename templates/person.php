<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the_content is a core WordPress filter.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are render-local state.
/**
 * A person's page: their facts beside the text written about them.
 *
 * @package Familypedia
 */

namespace Family_Wiki;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$familypedia_person = App::routed_person();

if ( ! $familypedia_person || ! in_array( $familypedia_person->post_status, array( 'publish', 'private', 'draft' ), true ) ) {
	require __DIR__ . '/404.php';
	return;
}

if ( 'publish' !== $familypedia_person->post_status && ! Editor::can_edit( $familypedia_person->ID ) ) {
	require __DIR__ . '/404.php';
	return;
}

$familypedia_facts_id = Infobox::facts_post_id_for( $familypedia_person->ID );
$familypedia_title    = Infobox::title_with_parent( get_the_title( $familypedia_person ), $familypedia_person->ID );

$familypedia_page_title = $familypedia_title;

global $post;
$post = $familypedia_person; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
setup_postdata( $post );

Tree::reset_expanded();

$familypedia_content = $familypedia_person->post_content;
if ( Interview::is_session( $familypedia_person ) ) {
	$familypedia_content = Interview::transcript( get_post_meta( $familypedia_person->ID, Interview::META, true ) );
}
if ( $familypedia_facts_id ) {
	// The infobox already carries these facts, so the shortcode is left as
	// just the name rather than repeating them in prose.
	$familypedia_content = Bio::replace_shortcode_with_title( $familypedia_content, $familypedia_person->ID );
}
$familypedia_content = apply_filters( 'the_content', $familypedia_content );
$familypedia_content = Links::filter_content( $familypedia_content );

// Render blocks before wp_head() so their styles and script module imports
// are available when WordPress prints the head, including the image lightbox.
require __DIR__ . '/partials/header.php';
?>

<article class="familypedia-person">
	<header class="familypedia-person__header">
		<h1><?php echo esc_html( $familypedia_title ); ?></h1>
		<?php if ( Editor::can_edit( $familypedia_person->ID ) ) : ?>
			<nav id="person-actions" class="familypedia-person__actions" aria-label="<?php esc_attr_e( 'Page actions', 'family-wiki' ); ?>">
				<details class="familypedia-action-menu" name="person-actions">
					<summary><?php esc_html_e( 'Edit', 'family-wiki' ); ?></summary>
					<ul>
						<?php if ( Reunion::is_reunion( $familypedia_person ) ) : ?>
							<li><a href="<?php echo esc_url( Reunion::edit_url( $familypedia_person ) ); ?>"><?php esc_html_e( 'Reunion or visit facts', 'family-wiki' ); ?></a></li>
						<?php elseif ( ! Wiki_Page::is_page( $familypedia_person ) ) : ?>
							<li><a href="<?php echo esc_url( Person::edit_url( $familypedia_person ) ); ?>"><?php esc_html_e( 'Facts', 'family-wiki' ); ?></a></li>
						<?php endif; ?>
						<li><a href="<?php echo esc_url( Interview::edit_url( $familypedia_person ) ); ?>"><?php echo esc_html( Interview::is_session( $familypedia_person ) ? __( 'Continue interview', 'family-wiki' ) : __( 'Page text', 'family-wiki' ) ); ?></a></li>
					</ul>
				</details>
				<?php if ( ! Wiki_Page::is_page( $familypedia_person ) && ( Editor::can_create() || Related_Page::can_create( $familypedia_person->ID ) ) ) : ?>
					<details class="familypedia-action-menu" name="person-actions">
						<summary><?php esc_html_e( 'Add', 'family-wiki' ); ?></summary>
						<ul>
							<?php if ( Editor::can_create() ) : ?>
								<li><a href="#add-family-member"><?php esc_html_e( 'Family member', 'family-wiki' ); ?></a></li>
							<?php endif; ?>
							<?php if ( Related_Page::can_create( $familypedia_person->ID ) ) : ?>
								<li><a href="<?php echo esc_url( Related_Page::add_url( $familypedia_person ) ); ?>"><?php esc_html_e( 'Related page', 'family-wiki' ); ?></a></li>
							<?php endif; ?>
							<?php if ( Interview::can_manage( $familypedia_person->ID ) ) : ?>
								<li><a href="<?php echo esc_url( Interview::url( $familypedia_person->ID ) ); ?>"><?php esc_html_e( 'Interview', 'family-wiki' ); ?></a></li>
							<?php endif; ?>
						</ul>
					</details>
				<?php endif; ?>
			</nav>
		<?php endif; ?>
	</header>

	<?php if ( ! Wiki_Page::is_page( $familypedia_person ) && Person::field( 'is_stub', $familypedia_person->ID ) ) : ?>
		<p class="familypedia-person__stub"><span class="familypedia-stub-badge"><?php esc_html_e( 'Stub', 'family-wiki' ); ?></span> <?php esc_html_e( 'This person needs more information. Uncheck the stub box under Edit → Facts when complete.', 'family-wiki' ); ?></p>
	<?php endif; ?>
	<?php if ( ! Wiki_Page::is_page( $familypedia_person ) && Editor::can_edit( $familypedia_person->ID ) && Editor::can_create() ) : ?>
		<?php require __DIR__ . '/partials/add-relative.php'; ?>
	<?php endif; ?>

	<?php
	if ( $familypedia_facts_id ) {
		$familypedia_infobox = new Infobox( $familypedia_facts_id, $familypedia_person->ID );
		// Built from escaped parts; wp_kses_post() would strip the markup the
		// collapse toggle needs.
		echo $familypedia_infobox->render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	?>

	<?php if ( Reunion::is_reunion( $familypedia_person ) ) : ?>
		<?php require __DIR__ . '/partials/reunion-facts.php'; ?>
	<?php endif; ?>

	<div class="familypedia-person__content">
		<?php
		if ( trim( $familypedia_content ) ) {
			// Already through the_content filters, as in any theme template.
			echo $familypedia_content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} else {
			?>
			<p class="familypedia-person__empty">
				<?php
				if ( Reunion::is_reunion( $familypedia_person ) ) {
					esc_html_e( 'Nothing has been written about this reunion or visit yet.', 'family-wiki' );
				} elseif ( Wiki_Page::is_page( $familypedia_person ) ) {
					esc_html_e( 'Nothing has been written on this page yet.', 'family-wiki' );
				} else {
					esc_html_e( 'Nothing has been written about this person yet.', 'family-wiki' );
				}
				?>
			</p>
			<?php
		}
		?>
	</div>

	<?php if ( ! Wiki_Page::is_page( $familypedia_person ) ) : ?>
		<?php
		$familypedia_attended = array_filter(
			Reunion::get_all(),
			function ( $reunion ) use ( $familypedia_person ) {
				return in_array( (int) $familypedia_person->ID, Reunion::data( $reunion->ID )['attendees'], true );
			}
		);
		?>
		<?php if ( $familypedia_attended ) : ?>
			<section>
				<h2><?php esc_html_e( 'Reunions and visits attended', 'family-wiki' ); ?></h2>
				<ul>
					<?php foreach ( $familypedia_attended as $familypedia_reunion ) : ?>
						<li><a href="<?php echo esc_url( Person::url( $familypedia_reunion ) ); ?>"><?php echo esc_html( get_the_title( $familypedia_reunion ) ); ?></a></li>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endif; ?>
	<?php endif; ?>

	<?php if ( isset( Tree::get_people()[ $familypedia_person->ID ] ) ) : ?>
		<p class="familypedia-person__tree-link">
			<a href="<?php echo esc_url( home_url( '/' . App::URL_PATH . '/tree/' . rawurlencode( $familypedia_person->post_name ) . '/' ) ); ?>"><?php esc_html_e( 'Show the descendant tree from here', 'family-wiki' ); ?></a>
		</p>
	<?php endif; ?>
</article>

<?php
wp_reset_postdata();
require __DIR__ . '/partials/footer.php';
