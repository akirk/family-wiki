<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are render-local state.
/**
 * The form for a person's facts and relationships.
 *
 * Relatives are entered by name. A name that matches somebody on the wiki
 * becomes a link to them; one that does not is kept as plain text, so a
 * grandmother can be recorded before anyone writes her page.
 *
 * @package Familypedia
 */

namespace Family_Wiki;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$familypedia_person = App::routed_person();

if ( $familypedia_person && Wiki_Page::is_page( $familypedia_person ) ) {
	// An existing wiki page has no facts of its own to show a form for —
	// only a title, and the block editor already edits that alongside
	// the text, so there is nothing left for this screen to do.
	wp_safe_redirect( get_edit_post_link( $familypedia_person->ID, '' ) );
	exit;
}

$familypedia_id = $familypedia_person ? (int) $familypedia_person->ID : 0;

if ( $familypedia_id ? ! Editor::can_edit( $familypedia_id ) : ! Editor::can_create() ) {
	status_header( 403 );
	$familypedia_page_title = __( 'Not allowed', 'family-wiki' );
	require __DIR__ . '/partials/header.php';
	?>
	<h1><?php echo esc_html( $familypedia_page_title ); ?></h1>
	<p><?php esc_html_e( 'You do not have permission to edit this wiki.', 'family-wiki' ); ?></p>
	<?php if ( ! is_user_logged_in() ) : ?>
		<p><a href="<?php echo esc_url( wp_login_url( home_url( add_query_arg( array() ) ) ) ); ?>"><?php esc_html_e( 'Log in', 'family-wiki' ); ?></a></p>
	<?php endif; ?>
	<?php
	require __DIR__ . '/partials/footer.php';
	return;
}

// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$familypedia_prefill = isset( $_GET['name'] ) ? sanitize_text_field( wp_unslash( $_GET['name'] ) ) : '';
$familypedia_prefill = trim( str_replace( array( '-', '_' ), ' ', $familypedia_prefill ) );

$familypedia_title = $familypedia_id ? get_the_title( $familypedia_id ) : $familypedia_prefill;

$familypedia_page_title = $familypedia_id
	? sprintf(
		// translators: %s is a person's name.
		__( 'Edit %s', 'family-wiki' ),
		$familypedia_title
	)
	: __( 'Add Person', 'family-wiki' );

require __DIR__ . '/partials/header.php';

$familypedia_marriages = $familypedia_id ? Person::field( 'marriages', $familypedia_id ) : array();
if ( empty( $familypedia_marriages ) ) {
	$familypedia_marriages = array();
}

$familypedia_children = array();
if ( $familypedia_id ) {
	foreach ( Person::field( 'children', $familypedia_id ) as $familypedia_child ) {
		$familypedia_children[] = Editor::label_for( $familypedia_child->ID );
	}
}
$familypedia_children[] = '';

/**
 * The value to show for a relative: the person they are linked to, or the plain
 * name recorded when no page exists for them.
 */
$familypedia_relative = function ( $field, $name_field ) use ( $familypedia_id ) {
	if ( ! $familypedia_id ) {
		return '';
	}

	$related = Person::field( $field, $familypedia_id );
	if ( $related ) {
		return Editor::label_for( $related->ID );
	}

	return (string) Person::field( $name_field, $familypedia_id );
};

$familypedia_value = function ( $field ) use ( $familypedia_id ) {
	return $familypedia_id ? (string) Person::field( $field, $familypedia_id ) : '';
};
?>

<h1><?php echo esc_html( $familypedia_page_title ); ?></h1>

<datalist id="familypedia-people-list">
	<?php foreach ( Editor::person_labels() as $familypedia_label ) : ?>
		<option value="<?php echo esc_attr( $familypedia_label ); ?>"></option>
	<?php endforeach; ?>
</datalist>

<form class="familypedia-form" method="post" action="<?php echo esc_url( $familypedia_id ? Person::edit_url( $familypedia_id ) : home_url( '/' . App::URL_PATH . '/new/' ) ); ?>">
	<input type="hidden" name="familypedia_action" value="<?php echo esc_attr( Editor::ACTION ); ?>" />
	<input type="hidden" name="person_id" value="<?php echo esc_attr( $familypedia_id ); ?>" />
	<?php wp_nonce_field( Editor::ACTION . '_' . $familypedia_id ); ?>

	<p><label><input type="checkbox" name="is_stub" value="1" <?php checked( (bool) Person::field( 'is_stub', $familypedia_id ) ); ?> /> <span class="familypedia-stub-badge"><?php esc_html_e( 'Stub', 'family-wiki' ); ?></span> <?php esc_html_e( 'This person needs more information', 'family-wiki' ); ?></label></p>

	<fieldset>
		<legend><?php esc_html_e( 'Identity', 'family-wiki' ); ?></legend>

		<p class="familypedia-field">
			<label for="familypedia-post-title"><?php esc_html_e( 'Name', 'family-wiki' ); ?></label>
			<input id="familypedia-post-title" type="text" name="post_title" value="<?php echo esc_attr( $familypedia_title ); ?>" required />
		</p>

		<p class="familypedia-field">
			<label for="familypedia-born-as"><?php esc_html_e( 'Born as', 'family-wiki' ); ?></label>
			<input id="familypedia-born-as" type="text" name="born_as" value="<?php echo esc_attr( $familypedia_value( 'born_as' ) ); ?>" />
		</p>

		<p class="familypedia-field">
			<label for="familypedia-sex"><?php esc_html_e( 'Sex', 'family-wiki' ); ?></label>
			<select id="familypedia-sex" name="sex">
				<?php foreach ( Person::sex_choices() as $familypedia_key => $familypedia_label ) : ?>
					<option value="<?php echo esc_attr( $familypedia_key ); ?>" <?php selected( $familypedia_value( 'sex' ), $familypedia_key ); ?>><?php echo esc_html( $familypedia_label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>

		<p class="familypedia-field familypedia-field--check">
			<label>
				<input type="checkbox" name="alive" value="1" <?php checked( $familypedia_id ? Person::field( 'alive', $familypedia_id ) : true ); ?> />
				<?php esc_html_e( 'Alive', 'family-wiki' ); ?>
			</label>
		</p>

		<p class="familypedia-field">
			<label for="familypedia-citizenships"><?php esc_html_e( 'Citizenships', 'family-wiki' ); ?></label>
			<textarea id="familypedia-citizenships" name="citizenships" rows="3"><?php echo esc_textarea( $familypedia_value( 'citizenships' ) ); ?></textarea>
			<span class="familypedia-field__hint"><?php esc_html_e( 'One per line.', 'family-wiki' ); ?></span>
		</p>
	</fieldset>

	<?php
	foreach ( array(
		'birth' => __( 'Birth', 'family-wiki' ),
		'death' => __( 'Death', 'family-wiki' ),
	) as $familypedia_event => $familypedia_legend ) :
		?>
		<fieldset>
			<legend><?php echo esc_html( $familypedia_legend ); ?></legend>

			<p class="familypedia-field">
				<label for="familypedia-<?php echo esc_attr( $familypedia_event ); ?>-date"><?php esc_html_e( 'Date', 'family-wiki' ); ?></label>
				<input id="familypedia-<?php echo esc_attr( $familypedia_event ); ?>-date" type="date" name="<?php echo esc_attr( $familypedia_event ); ?>_date" value="<?php echo esc_attr( $familypedia_value( $familypedia_event . '_date' ) ); ?>" />
			</p>

			<p class="familypedia-field familypedia-field--check">
				<label>
					<input type="checkbox" name="exact_<?php echo esc_attr( $familypedia_event ); ?>_date_unknown" value="1" <?php checked( $familypedia_id ? Person::field( 'exact_' . $familypedia_event . '_date_unknown', $familypedia_id ) : false ); ?> />
					<?php esc_html_e( 'Only the year is known', 'family-wiki' ); ?>
				</label>
			</p>

			<p class="familypedia-field">
				<label for="familypedia-<?php echo esc_attr( $familypedia_event ); ?>-place"><?php esc_html_e( 'Place', 'family-wiki' ); ?></label>
				<input id="familypedia-<?php echo esc_attr( $familypedia_event ); ?>-place" type="text" name="<?php echo esc_attr( $familypedia_event ); ?>_place" value="<?php echo esc_attr( $familypedia_value( $familypedia_event . '_place' ) ); ?>" />
			</p>
		</fieldset>
	<?php endforeach; ?>

	<fieldset>
		<legend><?php esc_html_e( 'Parents', 'family-wiki' ); ?></legend>

		<p class="familypedia-field">
			<label for="familypedia-father"><?php esc_html_e( 'Father', 'family-wiki' ); ?></label>
			<input id="familypedia-father" type="text" name="father" list="familypedia-people-list" value="<?php echo esc_attr( $familypedia_relative( 'father', 'father_name' ) ); ?>" />
		</p>

		<p class="familypedia-field">
			<label for="familypedia-mother"><?php esc_html_e( 'Mother', 'family-wiki' ); ?></label>
			<input id="familypedia-mother" type="text" name="mother" list="familypedia-people-list" value="<?php echo esc_attr( $familypedia_relative( 'mother', 'mother_name' ) ); ?>" />
		</p>
	</fieldset>

	<fieldset data-familypedia-repeat="children">
		<legend><?php esc_html_e( 'Children', 'family-wiki' ); ?></legend>
		<p class="familypedia-field__hint"><?php esc_html_e( 'Children need a page of their own before they can be linked here.', 'family-wiki' ); ?></p>

		<div data-familypedia-rows>
			<?php foreach ( $familypedia_children as $familypedia_child_label ) : ?>
				<p class="familypedia-field familypedia-row" data-familypedia-row>
					<input type="text" name="children[]" list="familypedia-people-list" value="<?php echo esc_attr( $familypedia_child_label ); ?>" aria-label="<?php esc_attr_e( 'Child', 'family-wiki' ); ?>" />
					<button type="button" class="familypedia-row__remove" data-familypedia-remove><?php esc_html_e( 'Remove', 'family-wiki' ); ?></button>
				</p>
			<?php endforeach; ?>
		</div>

		<p><button type="button" class="familypedia-button" data-familypedia-add><?php esc_html_e( 'Add child', 'family-wiki' ); ?></button></p>
	</fieldset>

	<fieldset data-familypedia-repeat="marriages">
		<legend><?php esc_html_e( 'Marriages', 'family-wiki' ); ?></legend>

		<div data-familypedia-rows>
			<?php
			$familypedia_rows = $familypedia_marriages;
			$familypedia_rows[] = array();
			foreach ( $familypedia_rows as $familypedia_index => $familypedia_marriage ) :
				$familypedia_marriage = wp_parse_args(
					$familypedia_marriage,
					array(
						'spouse'         => 0,
						'spouse_name'    => '',
						'marriage_date'  => '',
						'marriage_year'  => '',
						'marriage_place' => '',
						'ended_date'     => '',
						'ended_year'     => '',
						'ended_reason'   => '',
					)
				);
				$familypedia_spouse = $familypedia_marriage['spouse']
					? Editor::label_for( $familypedia_marriage['spouse'] )
					: $familypedia_marriage['spouse_name'];
				?>
				<fieldset class="familypedia-marriage" data-familypedia-row>
					<p class="familypedia-field">
						<label><?php esc_html_e( 'Spouse', 'family-wiki' ); ?>
							<input type="text" name="marriages[<?php echo esc_attr( $familypedia_index ); ?>][spouse]" list="familypedia-people-list" value="<?php echo esc_attr( $familypedia_spouse ); ?>" />
						</label>
					</p>
					<div class="familypedia-marriage__dates">
						<p class="familypedia-field">
							<label><?php esc_html_e( 'Married on', 'family-wiki' ); ?>
								<input type="date" name="marriages[<?php echo esc_attr( $familypedia_index ); ?>][marriage_date]" value="<?php echo esc_attr( Person::normalize_date( $familypedia_marriage['marriage_date'] ) ); ?>" />
							</label>
						</p>
						<p class="familypedia-field">
							<label><?php esc_html_e( 'or year', 'family-wiki' ); ?>
								<input type="number" min="1" max="9999" name="marriages[<?php echo esc_attr( $familypedia_index ); ?>][marriage_year]" value="<?php echo esc_attr( $familypedia_marriage['marriage_year'] ); ?>" />
							</label>
						</p>
						<p class="familypedia-field">
							<label><?php esc_html_e( 'Place', 'family-wiki' ); ?>
								<input type="text" name="marriages[<?php echo esc_attr( $familypedia_index ); ?>][marriage_place]" value="<?php echo esc_attr( $familypedia_marriage['marriage_place'] ); ?>" />
							</label>
						</p>
					</div>
					<div class="familypedia-marriage__dates">
						<p class="familypedia-field">
							<label><?php esc_html_e( 'Ended on', 'family-wiki' ); ?>
								<input type="date" name="marriages[<?php echo esc_attr( $familypedia_index ); ?>][ended_date]" value="<?php echo esc_attr( Person::normalize_date( $familypedia_marriage['ended_date'] ) ); ?>" />
							</label>
						</p>
						<p class="familypedia-field">
							<label><?php esc_html_e( 'or year', 'family-wiki' ); ?>
								<input type="number" min="1" max="9999" name="marriages[<?php echo esc_attr( $familypedia_index ); ?>][ended_year]" value="<?php echo esc_attr( $familypedia_marriage['ended_year'] ); ?>" />
							</label>
						</p>
						<p class="familypedia-field">
							<label><?php esc_html_e( 'Reason', 'family-wiki' ); ?>
								<select name="marriages[<?php echo esc_attr( $familypedia_index ); ?>][ended_reason]">
									<?php foreach ( Person::ended_reason_choices() as $familypedia_key => $familypedia_label ) : ?>
										<option value="<?php echo esc_attr( $familypedia_key ); ?>" <?php selected( $familypedia_marriage['ended_reason'], $familypedia_key ); ?>><?php echo esc_html( $familypedia_label ); ?></option>
									<?php endforeach; ?>
								</select>
							</label>
						</p>
					</div>
					<p><button type="button" class="familypedia-row__remove" data-familypedia-remove><?php esc_html_e( 'Remove marriage', 'family-wiki' ); ?></button></p>
				</fieldset>
			<?php endforeach; ?>
		</div>

		<p><button type="button" class="familypedia-button" data-familypedia-add><?php esc_html_e( 'Add marriage', 'family-wiki' ); ?></button></p>
	</fieldset>

	<p class="familypedia-form__actions">
		<button type="submit" class="familypedia-button familypedia-button--primary"><?php esc_html_e( 'Save', 'family-wiki' ); ?></button>
		<?php if ( $familypedia_id ) : ?>
			<a href="<?php echo esc_url( Person::url( $familypedia_id ) ); ?>"><?php esc_html_e( 'Cancel', 'family-wiki' ); ?></a>
			<a href="<?php echo esc_url( get_edit_post_link( $familypedia_id, '' ) ); ?>"><?php esc_html_e( 'Edit the text in the block editor', 'family-wiki' ); ?></a>
		<?php endif; ?>
	</p>
</form>

<?php
wp_app_enqueue_script( 'familypedia-form', Assets::url( 'form.js' ), array(), Assets::version( 'form.js' ), true, App::URL_PATH );

require __DIR__ . '/partials/footer.php';
