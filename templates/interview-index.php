<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are render-local state.
/**
 * Choose a person to start or resume an interview.
 *
 * @package Familypedia
 */

namespace Family_Wiki;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$familypedia_page_title = __( 'Interviews', 'family-wiki' );
if ( ! current_user_can( 'edit_others_pages' ) ) {
	status_header( 403 );
	require __DIR__ . '/partials/header.php';
	?>
	<p><?php esc_html_e( 'You do not have permission to manage interviews.', 'family-wiki' ); ?></p>
	<?php
	require __DIR__ . '/partials/footer.php';
	return;
}
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only search.
$familypedia_search = isset( $_GET['s'] ) && is_string( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
$familypedia_people = array_filter(
	Person::get_all( $familypedia_search ? array( 's' => $familypedia_search ) : array() ),
	function ( $person ) {
		return Interview::can_manage( $person->ID );
	}
);
$familypedia_recent = array_filter(
	get_posts(
		array(
			'post_type'      => Person::POST_TYPE,
			'post_status'    => 'draft',
			'posts_per_page' => 20,
			'meta_key'       => Interview::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Only interview drafts are listed.
			'orderby'        => array(
		'modified' => 'DESC',
		'ID'       => 'DESC',
		),
		)
	),
	function ( $interview ) {
		return (bool) Interview::session( $interview->post_parent, $interview->ID );
	}
);
require __DIR__ . '/partials/header.php';
?>
<h1><?php esc_html_e( 'Interviews', 'family-wiki' ); ?></h1>

<section aria-labelledby="recent-interviews">
	<h2 id="recent-interviews"><?php esc_html_e( 'Recent interview drafts', 'family-wiki' ); ?></h2>
	<?php if ( ! $familypedia_recent ) : ?>
		<p><?php esc_html_e( 'No saved interviews yet. Choose a person below to start a conversation.', 'family-wiki' ); ?></p>
	<?php else : ?>
		<ul class="familypedia-interview__list">
			<?php foreach ( $familypedia_recent as $familypedia_interview ) : ?>
				<?php $familypedia_details = get_post_meta( $familypedia_interview->ID, Interview::META, true ); ?>
				<li>
					<div>
						<a href="<?php echo esc_url( Interview::url( $familypedia_interview->post_parent, $familypedia_interview->ID ) ); ?>"><?php echo esc_html( get_the_title( $familypedia_interview ) ); ?></a>
						<p class="familypedia-interview__hint"><?php echo esc_html( get_the_title( $familypedia_interview->post_parent ) ); ?> · <?php echo esc_html( $familypedia_details['finished'] ? __( 'Finished for today', 'family-wiki' ) : __( 'In progress', 'family-wiki' ) ); ?></p>
					</div>
					<a class="familypedia-button" href="<?php echo esc_url( Interview::url( $familypedia_interview->post_parent, $familypedia_interview->ID ) ); ?>"><?php esc_html_e( 'Continue / review', 'family-wiki' ); ?></a>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</section>
<h2><?php esc_html_e( 'Start an interview', 'family-wiki' ); ?></h2>
<p><?php esc_html_e( 'Choose who the memories are about to start an interview, continue saved conversations, or customize your questions.', 'family-wiki' ); ?></p>
<form method="get" class="familypedia-search" action="<?php echo esc_url( home_url( '/' . App::URL_PATH . '/interviews/' ) ); ?>">
	<label for="interview-person-search"><?php esc_html_e( 'Find a person', 'family-wiki' ); ?></label>
	<input id="interview-person-search" type="search" name="s" value="<?php echo esc_attr( $familypedia_search ); ?>" />
	<button type="submit" class="familypedia-button"><?php esc_html_e( 'Search', 'family-wiki' ); ?></button>
</form>
<?php if ( ! $familypedia_people ) : ?>
	<p><?php esc_html_e( 'No people found.', 'family-wiki' ); ?></p>
<?php else : ?>
	<ul class="familypedia-person-list">
		<?php foreach ( $familypedia_people as $familypedia_person ) : ?>
			<li><a href="<?php echo esc_url( Interview::url( $familypedia_person->ID ) ); ?>"><?php echo esc_html( get_the_title( $familypedia_person ) ); ?></a></li>
		<?php endforeach; ?>
	</ul>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
