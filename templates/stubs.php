<?php
/**
 * A work list for unfinished people and a preview of suggested stub flags.
 *
 * @package Familypedia
 */
namespace Family_Wiki;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$familypedia_page_title = __( 'Stubs', 'family-wiki' );
if ( ! Editor::can_edit() ) {
	status_header( 403 );
	require __DIR__ . '/partials/header.php';
	?>
	<h1><?php esc_html_e( 'Stubs', 'family-wiki' ); ?></h1>
	<p><?php esc_html_e( 'You do not have permission to manage stubs.', 'family-wiki' ); ?></p>
	<?php
	require __DIR__ . '/partials/footer.php';
	return;
}
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filters.
$familypedia_review = isset( $_GET['review'] ) && '1' === $_GET['review'];
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$familypedia_query = isset( $_GET['q'] ) && is_string( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$familypedia_page = isset( $_GET['pagenum'] ) ? max( 1, absint( $_GET['pagenum'] ) ) : 1;
$familypedia_page_title = $familypedia_review ? __( 'Review suggestions', 'family-wiki' ) : __( 'Marked stubs', 'family-wiki' );
$familypedia_stubs = $familypedia_review ? Stubs::suggestions( $familypedia_query ) : Stubs::people( $familypedia_query );
$familypedia_total = count( $familypedia_stubs );
$familypedia_pages = max( 1, (int) ceil( $familypedia_total / 25 ) );
$familypedia_page = min( $familypedia_page, $familypedia_pages );
$familypedia_stubs = array_slice( $familypedia_stubs, ( $familypedia_page - 1 ) * 25, 25 );
require __DIR__ . '/partials/header.php';
?>
<h1><?php echo esc_html( $familypedia_page_title ); ?></h1>
<?php if ( $familypedia_review ) : ?>
	<p><?php esc_html_e( 'Scan results: these people have a suggested change to their stub flag. Nothing changes until you select and apply suggestions.', 'family-wiki' ); ?></p>
<?php else : ?>
	<p><?php esc_html_e( 'These people are already marked as stubs. This list uses their saved flags. Add information, then mark them complete when you are satisfied.', 'family-wiki' ); ?></p>
<?php endif; ?>
<nav class="familypedia-stubs-tabs" aria-label="<?php esc_attr_e( 'Stub views', 'family-wiki' ); ?>">
	<a href="<?php echo esc_url( Stubs::url() ); ?>" <?php if ( ! $familypedia_review ) { echo 'aria-current="page"'; } ?>><?php esc_html_e( 'Marked stubs', 'family-wiki' ); ?></a>
	<a href="<?php echo esc_url( add_query_arg( 'review', '1', Stubs::url() ) ); ?>" <?php if ( $familypedia_review ) { echo 'aria-current="page"'; } ?>><?php esc_html_e( 'Review suggestions', 'family-wiki' ); ?></a>
</nav>
<?php if ( $familypedia_review ) : ?>
	<aside class="familypedia-stubs-explanation">
		<p><?php esc_html_e( 'This check suggests marking a person as a stub if they have fewer than two personal facts and less than 40 words of biography. It suggests clearing the flag otherwise. Personal facts are birth/death dates and places, birth name, citizenship and a photo. Sex and family links do not count.', 'family-wiki' ); ?></p>
		<p><?php esc_html_e( 'These are suggestions, not a definition of completeness. Only selected people will change when you apply them. People whose flags already match are omitted.', 'family-wiki' ); ?></p>
	</aside>
<?php endif; ?>
<form class="familypedia-stubs-search" method="get" action="<?php echo esc_url( Stubs::url() ); ?>">
	<?php if ( $familypedia_review ) : ?><input type="hidden" name="review" value="1" /><?php endif; ?>
	<label for="stub-search"><?php esc_html_e( 'Find a person', 'family-wiki' ); ?></label>
	<input type="search" id="stub-search" name="q" value="<?php echo esc_attr( $familypedia_query ); ?>" />
	<button type="submit" class="familypedia-button"><?php esc_html_e( 'Search', 'family-wiki' ); ?></button>
</form>
<p><?php
if ( $familypedia_review ) {
	echo esc_html( sprintf(
		// translators: %d is the number of proposed changes matching the search.
		_n( '%d suggested flag change', '%d suggested flag changes', $familypedia_total, 'family-wiki' ), $familypedia_total
	) );
} else {
	echo esc_html( sprintf(
		// translators: %d is the number of people already marked as stubs matching the search.
		_n( '%d person already marked as a stub', '%d people already marked as stubs', $familypedia_total, 'family-wiki' ), $familypedia_total
	) );
}
?></p>
<?php if ( ! $familypedia_stubs ) : ?>
	<p class="familypedia-stubs-explanation"><?php echo $familypedia_query ? esc_html__( 'No people match this search.', 'family-wiki' ) : ( $familypedia_review ? esc_html__( 'No suggested changes. Stub flags match this check.', 'family-wiki' ) : esc_html__( 'No marked stubs. Open Review suggestions to scan for unmarked sparse pages.', 'family-wiki' ) ); ?></p>
<?php else : ?>
	<form method="post" action="<?php echo esc_url( Stubs::url() ); ?>" data-stubs-form>
		<?php wp_nonce_field( Stubs::ACTION ); ?>
		<input type="hidden" name="familypedia_action" value="<?php echo esc_attr( Stubs::ACTION ); ?>" />
		<input type="hidden" name="review" value="<?php echo $familypedia_review ? '1' : '0'; ?>" />
		<input type="hidden" name="q" value="<?php echo esc_attr( $familypedia_query ); ?>" />
		<p><label hidden data-stubs-select-all-label><input type="checkbox" data-stubs-select-all /> <?php esc_html_e( 'Select all on this page', 'family-wiki' ); ?></label></p>
		<ul class="familypedia-stub-list">
			<?php foreach ( $familypedia_stubs as $familypedia_person ) : ?>
				<?php $familypedia_detail = Stubs::detail( $familypedia_person ); ?>
				<li class="familypedia-stub-card">
					<label class="familypedia-stub-select"><input type="checkbox" name="people[]" value="<?php echo esc_attr( $familypedia_person->ID ); ?>" /><span class="screen-reader-text"><?php echo esc_html( sprintf(
						// translators: %s is a person's name.
						__( 'Select %s', 'family-wiki' ), get_the_title( $familypedia_person )
					) ); ?></span></label>
					<div class="familypedia-stub-details">
						<h2><a href="<?php echo esc_url( Person::url( $familypedia_person ) ); ?>"><?php echo esc_html( get_the_title( $familypedia_person ) ); ?></a></h2>
						<?php if ( $familypedia_review ) : ?>
							<p class="familypedia-stub-detail-count"><?php echo Person::field( 'is_stub', $familypedia_person->ID ) ? esc_html__( 'Currently marked as a stub', 'family-wiki' ) : esc_html__( 'Currently not marked as a stub', 'family-wiki' ); ?></p>
							<p class="familypedia-stub-suggestion"><?php echo $familypedia_detail['sparse'] ? esc_html__( 'Suggested: mark as stub', 'family-wiki' ) : esc_html__( 'Suggested: clear stub flag', 'family-wiki' ); ?></p>
						<?php endif; ?>
						<?php if ( $familypedia_detail['facts'] ) : ?>
							<details class="familypedia-stub-facts">
								<summary><?php echo esc_html( sprintf(
									// translators: %d is the number of personal facts; expanding shows their labels and values.
									_n( '%d personal fact', '%d personal facts', $familypedia_detail['facts'], 'family-wiki' ), $familypedia_detail['facts']
								) ); ?></summary>
								<dl>
									<?php foreach ( $familypedia_detail['fact_details'] as $familypedia_fact ) : ?>
										<dt><?php echo esc_html( $familypedia_fact['label'] ); ?></dt>
										<dd><?php echo esc_html( $familypedia_fact['value'] ); ?></dd>
									<?php endforeach; ?>
								</dl>
							</details>
						<?php else : ?>
							<p class="familypedia-stub-detail-count"><?php esc_html_e( 'No personal facts yet', 'family-wiki' ); ?></p>
						<?php endif; ?>
						<?php if ( $familypedia_detail['words'] ) : ?>
							<details class="familypedia-stub-biography">
								<summary><?php echo esc_html( sprintf(
									// translators: %d is the number of words in the biography; expanding this shows the text.
									_n( '%d biography word — preview', '%d biography words — preview', $familypedia_detail['words'], 'family-wiki' ), $familypedia_detail['words']
								) ); ?></summary>
								<p><?php echo esc_html( $familypedia_detail['biography'] ); ?></p>
							</details>
						<?php else : ?>
							<p class="familypedia-stub-detail-count"><?php esc_html_e( 'No biography yet', 'family-wiki' ); ?></p>
						<?php endif; ?>
						<?php if ( ! $familypedia_review ) : ?>
							<?php
							$familypedia_prompts = array();
							foreach ( array( 'birth_date' => __( 'birth date', 'family-wiki' ), 'birth_place' => __( 'birth place', 'family-wiki' ) ) as $familypedia_prompt_field => $familypedia_prompt_label ) {
								if ( ! Person::field( $familypedia_prompt_field, $familypedia_person->ID ) ) {
									$familypedia_prompts[] = $familypedia_prompt_label;
								}
							}
							if ( ! $familypedia_detail['words'] ) {
								$familypedia_prompts[] = __( 'biography', 'family-wiki' );
							}
							?>
							<?php if ( $familypedia_prompts ) : ?>
								<p><?php echo esc_html( sprintf(
									// translators: %s is a list of missing details that may be worth researching.
									__( 'To research: %s', 'family-wiki' ), implode( ', ', $familypedia_prompts )
								) ); ?></p>
							<?php endif; ?>
						<?php endif; ?>
						<?php
						$familypedia_relatives = Stubs::relatives( $familypedia_person->ID );
						$familypedia_relation_labels = array(
							'parents' => __( 'Parents', 'family-wiki' ),
							'spouses' => __( 'Spouses', 'family-wiki' ),
							'siblings' => __( 'Siblings', 'family-wiki' ),
							'children' => __( 'Children', 'family-wiki' ),
						);
						?>
						<?php if ( $familypedia_relatives ) : ?>
							<dl class="familypedia-stub-relatives">
								<?php foreach ( $familypedia_relatives as $familypedia_relation => $familypedia_family ) : ?>
									<dt><?php echo esc_html( $familypedia_relation_labels[ $familypedia_relation ] ); ?></dt>
									<dd><ul>
										<?php foreach ( $familypedia_family as $familypedia_relative ) : ?>
											<li><?php if ( $familypedia_relative['id'] ) : ?><a href="<?php echo esc_url( Person::url( $familypedia_relative['id'] ) ); ?>"><?php echo esc_html( $familypedia_relative['name'] ); ?></a><?php else : ?><?php echo esc_html( $familypedia_relative['name'] ); ?><?php endif; ?></li>
										<?php endforeach; ?>
									</ul></dd>
								<?php endforeach; ?>
							</dl>
						<?php else : ?>
							<p class="familypedia-stub-detail-count"><?php esc_html_e( 'No direct relatives recorded yet.', 'family-wiki' ); ?></p>
						<?php endif; ?>
						<p class="familypedia-stub-actions">
							<a href="<?php echo esc_url( Person::edit_url( $familypedia_person ) ); ?>"><?php esc_html_e( 'Edit facts', 'family-wiki' ); ?></a>
							<a href="<?php echo esc_url( get_edit_post_link( $familypedia_person->ID, '' ) ); ?>"><?php esc_html_e( 'Write biography', 'family-wiki' ); ?></a>
							<?php if ( ! $familypedia_review ) : ?>
								<button type="submit" name="complete_person" value="<?php echo esc_attr( $familypedia_person->ID ); ?>" class="familypedia-button"><?php esc_html_e( 'Mark complete', 'family-wiki' ); ?></button>
							<?php endif; ?>
						</p>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
		<button type="submit" class="familypedia-button familypedia-button--primary"><?php echo $familypedia_review ? esc_html__( 'Apply selected suggestions', 'family-wiki' ) : esc_html__( 'Mark selected complete', 'family-wiki' ); ?></button>
	</form>
<?php endif; ?>
<?php if ( $familypedia_pages > 1 ) : ?>
	<nav class="familypedia-stubs-pagination" aria-label="<?php esc_attr_e( 'Stub list pages', 'family-wiki' ); ?>">
		<?php echo wp_kses_post( paginate_links( array(
			'base' => add_query_arg( array( 'pagenum' => '%#%', 'q' => $familypedia_query, 'review' => $familypedia_review ? '1' : false ), Stubs::url() ),
			'format' => '',
			'current' => $familypedia_page,
			'total' => $familypedia_pages,
		) ) ); ?>
	</nav>
<?php endif; ?>
<?php
wp_app_enqueue_script( 'familypedia-stubs', Assets::url( 'stubs.js' ), array(), Assets::version( 'stubs.js' ), true, App::URL_PATH );
require __DIR__ . '/partials/footer.php';
?>
