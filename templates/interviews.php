<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are local to this render.
/**
 * Interview notebook and biography review workspace.
 *
 * @package Familypedia
 */

namespace Family_Wiki;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$familypedia_person = Person::get_by_path( wp_app_get_route_var( 'person', '' ) );
if ( ! $familypedia_person || ! Interview::can_manage( $familypedia_person->ID ) ) {
	require __DIR__ . '/404.php';
	return;
}
$familypedia_person_id = $familypedia_person->ID;
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only selection, validated against the subject and edit permissions below.
$familypedia_session_id = isset( $_GET['session'] ) ? absint( $_GET['session'] ) : 0;
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only selection, validated below.
$familypedia_draft_id = isset( $_GET['draft'] ) ? absint( $_GET['draft'] ) : 0;
$familypedia_session = $familypedia_session_id ? Interview::session( $familypedia_person_id, $familypedia_session_id ) : null;
$familypedia_draft = $familypedia_draft_id ? Interview::draft( $familypedia_person_id, $familypedia_draft_id ) : null;
if ( ( $familypedia_session_id && ! $familypedia_session ) || ( $familypedia_draft_id && ! $familypedia_draft ) || ( $familypedia_session_id && $familypedia_draft_id ) ) {
	require __DIR__ . '/404.php';
	return;
}

// translators: %s is the subject's name.
$familypedia_page_title = sprintf( __( 'Interviews about %s', 'family-wiki' ), get_the_title( $familypedia_person ) );
wp_app_enqueue_script( 'familypedia-interviews', Assets::url( 'interviews.js' ), array(), Assets::version( 'interviews.js' ), true, App::URL_PATH );
wp_app_enqueue_script( 'familypedia-interview-audio', Assets::url( 'interview-audio.js' ), array(), Assets::version( 'interview-audio.js' ), true, App::URL_PATH );
$familypedia_form_fields = function () use ( $familypedia_person_id, $familypedia_session_id, $familypedia_draft_id ) {
	?>
	<input type="hidden" name="familypedia_action" value="<?php echo esc_attr( Interview::ACTION ); ?>" />
	<input type="hidden" name="person_id" value="<?php echo esc_attr( $familypedia_person_id ); ?>" />
	<input type="hidden" name="session_id" value="<?php echo esc_attr( $familypedia_session_id ); ?>" />
	<input type="hidden" name="draft_id" value="<?php echo esc_attr( $familypedia_draft_id ); ?>" />
	<?php
	wp_nonce_field( Interview::ACTION . '_' . $familypedia_person_id );
};
require __DIR__ . '/partials/header.php';
?>
<p><a href="<?php echo esc_url( Person::url( $familypedia_person ) ); ?>">← <?php echo esc_html( get_the_title( $familypedia_person ) ); ?></a></p>
<h1><?php echo esc_html( $familypedia_page_title ); ?></h1>
<?php
$familypedia_recovery_key = 'familypedia_interview_recovery_' . get_current_user_id() . '_' . $familypedia_person_id;
$familypedia_recovery = get_transient( $familypedia_recovery_key );
if ( $familypedia_recovery ) :
	delete_transient( $familypedia_recovery_key );
	?>
	<details class="familypedia-interview__panel" open>
		<summary><?php esc_html_e( 'Recover text from the unsuccessful save', 'family-wiki' ); ?></summary>
		<p><?php esc_html_e( 'Your submitted words are below. Copy any missing text into the current interview or draft before saving again.', 'family-wiki' ); ?></p>
		<label for="interview-recovery"><?php esc_html_e( 'Submitted text', 'family-wiki' ); ?></label>
		<textarea id="interview-recovery" rows="10" readonly style="width:100%;box-sizing:border-box"><?php echo esc_textarea( $familypedia_recovery ); ?></textarea>
	</details>
<?php endif; ?>
<?php if ( $familypedia_session || $familypedia_draft ) : ?>
	<p><a href="<?php echo esc_url( Interview::url( $familypedia_person_id ) ); ?>"><?php esc_html_e( 'All interviews and drafts', 'family-wiki' ); ?></a></p>
<?php endif; ?>

<?php if ( $familypedia_session ) : ?>
	<?php
	$familypedia_data = get_post_meta( $familypedia_session_id, Interview::META, true );
	$familypedia_catalogue = $familypedia_data['catalogue'] ?? Interview::legacy_catalogue( ! empty( $familypedia_data['about_other'] ) );
	?>
	<p><?php echo esc_html( $familypedia_data['speaker'] . ' — ' . $familypedia_data['date'] ); ?> · <a href="<?php echo esc_url( Person::url( $familypedia_session ) ); ?>"><?php esc_html_e( 'Read source notes', 'family-wiki' ); ?></a></p>
	<p><?php esc_html_e( 'Follow the conversation in any order. Skip questions freely and keep approximate dates in the speaker’s own words.', 'family-wiki' ); ?></p>
	<p><a href="<?php echo esc_url( Interview::url( $familypedia_person_id ) . '#customize-questions' ); ?>"><?php esc_html_e( 'Customize questions', 'family-wiki' ); ?></a></p>
	<section class="familypedia-interview__panel" data-recorder
		data-name="<?php esc_attr_e( 'Recording name', 'family-wiki' ); ?>" data-rename="<?php esc_attr_e( 'Save name', 'family-wiki' ); ?>" data-delete="<?php esc_attr_e( 'Delete recording', 'family-wiki' ); ?>"
		data-confirm-delete="<?php esc_attr_e( 'Permanently delete this recording and its audio file?', 'family-wiki' ); ?>" data-managed="<?php esc_attr_e( 'Recording updated.', 'family-wiki' ); ?>" data-manage-error="<?php esc_attr_e( 'Could not update the recording. Please retry.', 'family-wiki' ); ?>"
		data-ready="<?php esc_attr_e( 'Ready to review and save.', 'family-wiki' ); ?>"
		data-recording="<?php esc_attr_e( 'Recording…', 'family-wiki' ); ?>" data-paused="<?php esc_attr_e( 'Paused', 'family-wiki' ); ?>"
		data-saving="<?php esc_attr_e( 'Saving recording…', 'family-wiki' ); ?>" data-saved="<?php esc_attr_e( 'Recording saved', 'family-wiki' ); ?>"
		data-error="<?php esc_attr_e( 'Could not save. Download the recording or retry before leaving.', 'family-wiki' ); ?>"
		data-unavailable="<?php esc_attr_e( 'Recording is unavailable. Use HTTPS and allow microphone access.', 'family-wiki' ); ?>">
		<h2><?php esc_html_e( 'Audio recordings', 'family-wiki' ); ?></h2>
		<p><?php esc_html_e( 'Ask the speaker before recording. You can keep recording as you move between questions. Stop to review and save each clip; keep this page open until it is saved.', 'family-wiki' ); ?></p>
		<noscript><?php esc_html_e( 'Enable JavaScript to record audio.', 'family-wiki' ); ?></noscript>
		<div data-audio-dock>
		<h3><?php esc_html_e( 'Interview recorder', 'family-wiki' ); ?></h3>
		<div data-recording-controls hidden>
			<button class="familypedia-button familypedia-button--primary" type="button" data-record><?php esc_html_e( 'Record', 'family-wiki' ); ?></button>
			<button class="familypedia-button" type="button" data-pause disabled><?php esc_html_e( 'Pause', 'family-wiki' ); ?></button>
			<button class="familypedia-button" type="button" data-resume disabled><?php esc_html_e( 'Resume', 'family-wiki' ); ?></button>
			<button class="familypedia-button" type="button" data-stop disabled><?php esc_html_e( 'Stop', 'family-wiki' ); ?></button>
			<output data-recording-time>0:00</output>
		</div>
		<p role="status" data-recording-status></p>
		<div data-recording-preview hidden>
			<p><label for="recording-name"><?php esc_html_e( 'Recording name', 'family-wiki' ); ?></label> <input id="recording-name" type="text" data-recording-name /></p>
			<audio controls data-local-audio></audio>
			<p><a data-local-download><?php esc_html_e( 'Download recording', 'family-wiki' ); ?></a></p>
			<button class="familypedia-button familypedia-button--primary" type="button" data-save-recording><?php esc_html_e( 'Save recording', 'family-wiki' ); ?></button>
		</div>
		<ol data-live-markers aria-label="<?php esc_attr_e( 'Question timeline', 'family-wiki' ); ?>"></ol>
		<p class="familypedia-recording__timeline-hint"><?php esc_html_e( 'Question changes add timestamps automatically while recording.', 'family-wiki' ); ?></p>
		</div>
		<ul data-saved-recordings>
			<?php foreach ( get_post_meta( $familypedia_session_id, Interview_Audio::META, false ) as $familypedia_clip ) : ?>
				<li data-recording-id="<?php echo esc_attr( $familypedia_clip['id'] ); ?>">
					<label><?php esc_html_e( 'Recording name', 'family-wiki' ); ?> <input type="text" data-clip-name value="<?php echo esc_attr( get_the_title( $familypedia_clip['id'] ) ); ?>" /></label>
					<button class="familypedia-button" type="button" data-rename-recording><?php esc_html_e( 'Save name', 'family-wiki' ); ?></button>
					<button class="familypedia-button" type="button" data-delete-recording><?php esc_html_e( 'Delete recording', 'family-wiki' ); ?></button>
					<span class="familypedia-recording__date"><?php echo esc_html( $familypedia_clip['date'] ); ?></span>
					<audio controls preload="none" src="<?php echo esc_url( Interview_Audio::url( $familypedia_person_id, $familypedia_session_id, $familypedia_clip['id'] ) ); ?>"></audio>
					<a href="<?php echo esc_url( Interview_Audio::url( $familypedia_person_id, $familypedia_session_id, $familypedia_clip['id'] ) ); ?>" download><?php esc_html_e( 'Download recording', 'family-wiki' ); ?></a>
					<ol class="familypedia-recording__markers">
						<?php foreach ( $familypedia_clip['markers'] ?? array() as $familypedia_marker ) : ?>
							<li><button type="button" class="familypedia-button" data-seek="<?php echo esc_attr( $familypedia_marker['time'] ); ?>"><?php echo esc_html( sprintf( '%d:%02d', floor( $familypedia_marker['time'] / 60 ), floor( $familypedia_marker['time'] ) % 60 ) . ' · ' . $familypedia_marker['label'] ); ?></button></li>
						<?php endforeach; ?>
					</ol>
				</li>
			<?php endforeach; ?>
		</ul>
	</section>
	<form method="post" action="<?php echo esc_url( Interview::url( $familypedia_person_id, $familypedia_session_id ) ); ?>" class="familypedia-form familypedia-interview" data-interview-form
		data-saving="<?php esc_attr_e( 'Saving…', 'family-wiki' ); ?>" data-saved="<?php esc_attr_e( 'All changes saved', 'family-wiki' ); ?>"
		data-error="<?php esc_attr_e( 'Could not save. Keep this page open, copy your notes, and retry Save.', 'family-wiki' ); ?>"
		data-unsaved="<?php esc_attr_e( 'Unsaved changes', 'family-wiki' ); ?>">
		<?php $familypedia_form_fields(); ?>
		<button type="submit" name="op" value="save" hidden><?php esc_html_e( 'Save interview', 'family-wiki' ); ?></button>
		<input type="hidden" name="version" value="<?php echo esc_attr( $familypedia_data['version'] ); ?>" />
		<div data-question-picker hidden data-visited="<?php esc_attr_e( 'Visited', 'family-wiki' ); ?>">
			<p class="familypedia-interview__hint"><?php esc_html_e( 'Choose a topic, then pick whichever question fits the conversation.', 'family-wiki' ); ?></p>
			<div class="familypedia-interview__topics" role="group" aria-label="<?php esc_attr_e( 'Topics', 'family-wiki' ); ?>" data-topic-chips></div>
			<div class="familypedia-interview__cards" data-question-cards></div>
		</div>
		<div class="familypedia-interview__toolbar">
			<p class="familypedia-field"><label for="interview-topic"><?php esc_html_e( 'Topic', 'family-wiki' ); ?></label>
				<select name="topic" id="interview-topic">
					<?php foreach ( $familypedia_catalogue as $familypedia_key => $familypedia_group ) : ?>
						<option value="<?php echo esc_attr( $familypedia_key ); ?>" <?php selected( $familypedia_data['topic'], $familypedia_key ); ?>><?php echo esc_html( $familypedia_group['label'] ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p class="familypedia-field"><label for="interview-question"><?php esc_html_e( 'Question', 'family-wiki' ); ?></label>
				<select name="question" id="interview-question">
					<?php foreach ( $familypedia_catalogue as $familypedia_key => $familypedia_group ) : ?>
						<optgroup label="<?php echo esc_attr( $familypedia_group['label'] ); ?>" data-topic="<?php echo esc_attr( $familypedia_key ); ?>">
							<?php foreach ( $familypedia_group['questions'] as $familypedia_question_id => $familypedia_prompt ) : ?>
								<option value="<?php echo esc_attr( $familypedia_question_id ); ?>" <?php selected( $familypedia_data['question'], $familypedia_question_id ); ?>><?php echo esc_html( $familypedia_prompt[0] ); ?></option>
							<?php endforeach; ?>
						</optgroup>
					<?php endforeach; ?>
				</select>
			</p>
		</div>
		<?php foreach ( $familypedia_catalogue as $familypedia_group ) : ?>
			<?php foreach ( $familypedia_group['questions'] as $familypedia_question_id => $familypedia_prompt ) : ?>
				<section class="familypedia-interview__question" data-question="<?php echo esc_attr( $familypedia_question_id ); ?>">
					<h2><label for="answer-<?php echo esc_attr( $familypedia_question_id ); ?>"><?php echo esc_html( $familypedia_prompt[0] ); ?></label></h2>
					<p id="hint-<?php echo esc_attr( $familypedia_question_id ); ?>" class="familypedia-interview__hint"><?php echo esc_html( $familypedia_prompt[1] ); ?></p>
					<details class="familypedia-interview__notes"><summary><?php esc_html_e( 'Add written notes', 'family-wiki' ); ?></summary>
					<textarea id="answer-<?php echo esc_attr( $familypedia_question_id ); ?>" aria-label="<?php echo esc_attr( $familypedia_prompt[0] ); ?>" aria-describedby="hint-<?php echo esc_attr( $familypedia_question_id ); ?>" name="answers[<?php echo esc_attr( $familypedia_question_id ); ?>]" rows="5"><?php echo esc_textarea( $familypedia_data['answers'][ $familypedia_question_id ] ?? '' ); ?></textarea>
					</details>
				</section>
			<?php endforeach; ?>
		<?php endforeach; ?>

		<details class="familypedia-interview__panel">
			<summary><?php esc_html_e( 'Interview details', 'family-wiki' ); ?></summary>
			<p class="familypedia-field"><label for="session-speaker"><?php esc_html_e( 'Speaker', 'family-wiki' ); ?></label>
				<input id="session-speaker" name="speaker" type="text" value="<?php echo esc_attr( $familypedia_data['speaker'] ); ?>" required /></p>
			<p class="familypedia-field"><label for="session-date"><?php esc_html_e( 'Interview date', 'family-wiki' ); ?></label>
				<input id="session-date" name="date" type="date" value="<?php echo esc_attr( $familypedia_data['date'] ); ?>" required /></p>
			<input type="hidden" name="perspective" value="<?php echo esc_attr( ! empty( $familypedia_data['about_other'] ) ? 'other' : 'self' ); ?>" />
		</details>
		<details class="familypedia-interview__panel">
			<summary><?php esc_html_e( 'Other memories and follow-up questions', 'family-wiki' ); ?></summary>
			<p class="familypedia-field"><label for="interview-notes"><?php esc_html_e( 'Other memories or spontaneous stories', 'family-wiki' ); ?></label>
				<textarea id="interview-notes" name="notes" rows="6"><?php echo esc_textarea( $familypedia_data['notes'] ); ?></textarea></p>
			<p class="familypedia-field"><label for="interview-follow-up"><?php esc_html_e( 'Questions to return to / things to look up', 'family-wiki' ); ?></label>
				<textarea id="interview-follow-up" name="follow_up" rows="4"><?php echo esc_textarea( $familypedia_data['follow_up'] ); ?></textarea></p>
		</details>
		<details class="familypedia-interview__panel" id="fact-review">
			<summary><?php esc_html_e( 'Check dates, places and other recorded facts', 'family-wiki' ); ?></summary>
			<p><?php esc_html_e( 'Confirming or questioning a fact records what the speaker said. Only “Apply this fact” changes the person’s facts. Keep conflicting memories and uncertainty in the note.', 'family-wiki' ); ?></p>
			<p><a href="<?php echo esc_url( Person::edit_url( $familypedia_person ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Review family relationships and all facts (opens a new tab)', 'family-wiki' ); ?></a></p>
			<?php foreach ( Interview::fact_labels() as $familypedia_field => $familypedia_label ) : ?>
				<?php $familypedia_review = $familypedia_data['reviews'][ $familypedia_field ]; ?>
				<fieldset class="familypedia-interview__fact">
					<legend><?php echo esc_html( $familypedia_label ); ?></legend>
					<p><?php esc_html_e( 'Recorded when this interview started:', 'family-wiki' ); ?> <strong><?php echo esc_html( $familypedia_review['original'] ?: __( 'Not recorded', 'family-wiki' ) ); ?></strong></p>
					<?php if ( Interview::fact_value( $familypedia_person_id, $familypedia_field ) !== $familypedia_review['original'] ) : ?>
						<p><?php esc_html_e( 'Currently recorded:', 'family-wiki' ); ?> <strong><?php echo esc_html( Interview::fact_value( $familypedia_person_id, $familypedia_field ) ?: __( 'Not recorded', 'family-wiki' ) ); ?></strong></p>
					<?php endif; ?>
					<p class="familypedia-field"><label for="review-<?php echo esc_attr( $familypedia_field ); ?>"><?php esc_html_e( 'Speaker’s account', 'family-wiki' ); ?></label>
						<select id="review-<?php echo esc_attr( $familypedia_field ); ?>" name="reviews[<?php echo esc_attr( $familypedia_field ); ?>][status]">
							<?php
							foreach ( array(
								'unreviewed' => __( 'Not discussed', 'family-wiki' ),
								'confirmed'  => __( 'Confirmed by speaker', 'family-wiki' ),
								'correction' => __( 'Add or correct', 'family-wiki' ),
								'unsure'     => __( 'Unsure / conflicting memories', 'family-wiki' ),
							) as $familypedia_status => $familypedia_status_label ) :
								?>
								<option value="<?php echo esc_attr( $familypedia_status ); ?>" <?php selected( $familypedia_status, $familypedia_review['status'] ); ?>><?php echo esc_html( $familypedia_status_label ); ?></option>
							<?php endforeach; ?>
							<?php if ( 'applied' === $familypedia_review['status'] ) : ?>
								<option value="applied" selected><?php esc_html_e( 'Applied to person’s facts', 'family-wiki' ); ?></option>
							<?php endif; ?>
						</select>
					</p>
					<p class="familypedia-field"><label for="proposed-<?php echo esc_attr( $familypedia_field ); ?>"><?php esc_html_e( 'Proposed value (dates: YYYY-MM-DD or year)', 'family-wiki' ); ?></label>
						<input type="text" id="proposed-<?php echo esc_attr( $familypedia_field ); ?>" name="reviews[<?php echo esc_attr( $familypedia_field ); ?>][proposed]" value="<?php echo esc_attr( $familypedia_review['proposed'] ); ?>" /></p>
					<p class="familypedia-field"><label for="fact-note-<?php echo esc_attr( $familypedia_field ); ?>"><?php esc_html_e( 'Speaker’s wording / uncertainty / source', 'family-wiki' ); ?></label>
						<textarea id="fact-note-<?php echo esc_attr( $familypedia_field ); ?>" name="reviews[<?php echo esc_attr( $familypedia_field ); ?>][note]" rows="2"><?php echo esc_textarea( $familypedia_review['note'] ); ?></textarea></p>
					<?php if ( isset( $familypedia_review['applied_value'] ) ) : ?>
						<p><?php esc_html_e( 'Value applied from this interview:', 'family-wiki' ); ?> <?php echo esc_html( $familypedia_review['applied_value'] ); ?></p>
					<?php else : ?>
						<button class="familypedia-button" type="submit" name="op" value="apply:<?php echo esc_attr( $familypedia_field ); ?>"><?php esc_html_e( 'Apply this fact', 'family-wiki' ); ?></button>
					<?php endif; ?>
				</fieldset>
			<?php endforeach; ?>
		</details>
		<div class="familypedia-interview__save">
			<label><input type="checkbox" name="finished" value="1" <?php checked( $familypedia_data['finished'] ); ?> /> <?php esc_html_e( 'Finished for today (you can resume later)', 'family-wiki' ); ?></label>
			<p><button type="submit" name="op" value="save" class="familypedia-button familypedia-button--primary"><?php esc_html_e( 'Save interview', 'family-wiki' ); ?></button> <span role="status" aria-live="polite" data-save-status><?php esc_html_e( 'Saved notes are kept as the interview source.', 'family-wiki' ); ?></span></p>
		</div>
	</form>
<?php elseif ( $familypedia_draft ) : ?>
	<?php $familypedia_data = get_post_meta( $familypedia_draft_id, Interview::DRAFT_META, true ); ?>
	<h2><?php esc_html_e( 'Biography draft', 'family-wiki' ); ?></h2>
	<p><?php esc_html_e( 'This outline collects the original notes with speaker attribution. Rewrite it into a biography, preserving uncertainty and differing accounts. Questions to return to and unapproved fact proposals are left out.', 'family-wiki' ); ?></p>
	<div class="familypedia-interview__sources">
		<strong><?php esc_html_e( 'Interview sources', 'family-wiki' ); ?></strong>
		<ul>
			<?php foreach ( $familypedia_data['sources'] as $familypedia_source_id ) : ?>
				<?php if ( Interview::session( $familypedia_person_id, $familypedia_source_id ) ) : ?>
					<li><a href="<?php echo esc_url( Person::url( $familypedia_source_id ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( get_the_title( $familypedia_source_id ) ); ?></a></li>
				<?php endif; ?>
			<?php endforeach; ?>
		</ul>
	</div>
	<details class="familypedia-interview__panel">
		<summary><?php esc_html_e( 'Read the current biography', 'family-wiki' ); ?></summary>
		<?php echo wp_kses_post( wpautop( $familypedia_person->post_content ) ); ?>
	</details>
	<?php if ( $familypedia_data['appended'] ) : ?>
		<p class="familypedia-notice"><?php esc_html_e( 'This draft has been added to the biography. You can edit the biography on the person’s page.', 'family-wiki' ); ?></p>
		<?php echo wp_kses_post( wpautop( esc_html( $familypedia_data['text'] ) ) ); ?>
	<?php else : ?>
		<form method="post" class="familypedia-form" data-draft-form action="<?php echo esc_url( add_query_arg( 'draft', $familypedia_draft_id, Interview::url( $familypedia_person_id ) ) ); ?>">
			<?php $familypedia_form_fields(); ?>
			<input type="hidden" name="version" value="<?php echo esc_attr( $familypedia_data['version'] ); ?>" />
			<input type="hidden" name="biography_hash" value="<?php echo esc_attr( hash( 'sha256', $familypedia_person->post_content ) ); ?>" />
			<p class="familypedia-field"><label for="biography-draft"><?php esc_html_e( 'Edit the draft', 'family-wiki' ); ?></label>
				<textarea id="biography-draft" name="draft_text" rows="22"><?php echo esc_textarea( $familypedia_data['text'] ); ?></textarea></p>
			<p><label><input type="checkbox" name="reviewed" value="1" /> <?php esc_html_e( 'I have reviewed this text and want to add it after the current biography.', 'family-wiki' ); ?></label></p>
			<p class="familypedia-form__actions">
				<button type="submit" name="op" value="save_draft" class="familypedia-button"><?php esc_html_e( 'Save draft', 'family-wiki' ); ?></button>
				<button type="submit" name="op" value="append" class="familypedia-button familypedia-button--primary"><?php esc_html_e( 'Add reviewed text to biography', 'family-wiki' ); ?></button>
			</p>
		</form>
	<?php endif; ?>
<?php else : ?>
	<p><?php esc_html_e( 'Collect memories over several conversations. Each interview keeps its own speaker, date and source notes, separate from the biography.', 'family-wiki' ); ?></p>
	<details id="customize-questions" class="familypedia-interview__panel" data-question-library
		data-topic="<?php esc_attr_e( 'Topic', 'family-wiki' ); ?>" data-question="<?php esc_attr_e( 'Question', 'family-wiki' ); ?>" data-hint="<?php esc_attr_e( 'Follow-up prompts', 'family-wiki' ); ?>"
		data-up="<?php esc_attr_e( 'Move up', 'family-wiki' ); ?>" data-down="<?php esc_attr_e( 'Move down', 'family-wiki' ); ?>" data-remove="<?php esc_attr_e( 'Remove', 'family-wiki' ); ?>"
		data-imported="<?php esc_attr_e( 'Questions added for review. Save to keep them.', 'family-wiki' ); ?>" data-invalid="<?php esc_attr_e( 'Import a JSON list with topic, question and optional hint fields (maximum 200 questions and 1 MB).', 'family-wiki' ); ?>">
		<summary><?php esc_html_e( 'My question library', 'family-wiki' ); ?></summary>
		<p><?php esc_html_e( 'Ask for one scene: where were you, who was there, what happened next? Follow the story before moving on. Your library applies to new interviews; saved interviews keep their questions. Custom wording is used for both perspectives.', 'family-wiki' ); ?></p>
		<form method="post" action="<?php echo esc_url( Interview::url( $familypedia_person_id ) ); ?>">
			<?php $familypedia_form_fields(); ?>
			<?php
			$familypedia_rows = Interview::question_rows();
			?>
			<script type="application/json" data-question-defaults><?php echo wp_json_encode( Interview::question_defaults(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?></script>
			<div data-library-rows></div>
			<p><button type="button" data-add-question hidden><?php esc_html_e( 'Add question', 'family-wiki' ); ?></button></p>
			<label for="question-library"><?php esc_html_e( 'Question set (JSON)', 'family-wiki' ); ?></label>
			<p><?php esc_html_e( 'Import adds questions for review. Edit or reorder the list, then save to replace your personal library.', 'family-wiki' ); ?></p>
			<textarea id="question-library" name="question_library" rows="12" style="width:100%" required><?php echo esc_textarea( wp_json_encode( $familypedia_rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ); ?></textarea>
			<p><input type="file" accept=".json,application/json" data-import-questions aria-label="<?php esc_attr_e( 'Import questions', 'family-wiki' ); ?>" />
			<button type="button" data-export-questions hidden><?php esc_html_e( 'Export questions', 'family-wiki' ); ?></button></p>
			<p role="status" data-library-status></p>
			<button type="submit" name="op" value="questions" class="familypedia-button"><?php esc_html_e( 'Save my question library', 'family-wiki' ); ?></button>
		</form>
	</details>
	<section class="familypedia-interview__panel">
		<h2><?php esc_html_e( 'Start an interview', 'family-wiki' ); ?></h2>
		<form method="post" class="familypedia-form" action="<?php echo esc_url( Interview::url( $familypedia_person_id ) ); ?>">
			<?php $familypedia_form_fields(); ?>
			<p class="familypedia-field"><label for="interview-speaker"><?php esc_html_e( 'Who are you speaking with?', 'family-wiki' ); ?></label>
				<input id="interview-speaker" name="speaker" type="text" value="<?php echo esc_attr( get_the_title( $familypedia_person ) ); ?>" required /></p>
			<p class="familypedia-field"><label for="interview-date"><?php esc_html_e( 'Interview date', 'family-wiki' ); ?></label>
				<input id="interview-date" name="date" type="date" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" required /></p>
			<p class="familypedia-field"><label for="interview-perspective"><?php esc_html_e( 'The speaker is describing', 'family-wiki' ); ?></label>
				<select id="interview-perspective" name="perspective">
					<option value="self"><?php esc_html_e( 'Their own life', 'family-wiki' ); ?></option>
					<option value="other"><?php esc_html_e( 'Someone else’s life', 'family-wiki' ); ?></option>
				</select></p>
			<button type="submit" name="op" value="start" class="familypedia-button familypedia-button--primary"><?php esc_html_e( 'Start interview', 'family-wiki' ); ?></button>
		</form>
	</section>
	<?php $familypedia_sessions = Interview::sessions( $familypedia_person_id ); ?>
	<?php if ( $familypedia_sessions ) : ?>
		<h2><?php esc_html_e( 'Saved interviews', 'family-wiki' ); ?></h2>
		<form method="post" action="<?php echo esc_url( Interview::url( $familypedia_person_id ) ); ?>" class="familypedia-form">
			<?php $familypedia_form_fields(); ?>
			<ul class="familypedia-interview__list">
				<?php foreach ( $familypedia_sessions as $familypedia_item ) : ?>
					<?php $familypedia_data = get_post_meta( $familypedia_item->ID, Interview::META, true ); ?>
					<li>
						<label><input type="checkbox" name="sources[]" value="<?php echo esc_attr( $familypedia_item->ID ); ?>" /> <span class="screen-reader-text"><?php esc_html_e( 'Use for biography outline:', 'family-wiki' ); ?></span> <?php echo esc_html( $familypedia_data['speaker'] . ' — ' . $familypedia_data['date'] ); ?></label>
						<span><?php echo esc_html( $familypedia_data['finished'] ? __( 'Finished for today', 'family-wiki' ) : __( 'In progress', 'family-wiki' ) ); ?></span>
						<a href="<?php echo esc_url( Interview::url( $familypedia_person_id, $familypedia_item->ID ) ); ?>"><?php esc_html_e( 'Continue / review', 'family-wiki' ); ?></a>
					</li>
				<?php endforeach; ?>
			</ul>
			<p><?php esc_html_e( 'Select the interviews to use. The outline is a separate editable draft; your existing biography stays in place.', 'family-wiki' ); ?></p>
			<button type="submit" name="op" value="outline" class="familypedia-button familypedia-button--primary"><?php esc_html_e( 'Create biography outline', 'family-wiki' ); ?></button>
		</form>
	<?php endif; ?>
	<?php
	$familypedia_drafts = get_posts(
		array(
			'post_type'      => Person::POST_TYPE,
			'post_parent'    => $familypedia_person_id,
			'post_status'    => 'draft',
			'posts_per_page' => -1,
			'meta_key'       => Interview::DRAFT_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Direct child drafts only.
		)
	);
	?>
	<?php if ( $familypedia_drafts ) : ?>
		<h2><?php esc_html_e( 'Biography drafts', 'family-wiki' ); ?></h2>
		<ul>
			<?php foreach ( $familypedia_drafts as $familypedia_item ) : ?>
				<?php if ( Interview::draft( $familypedia_person_id, $familypedia_item->ID ) ) : ?>
					<?php $familypedia_data = get_post_meta( $familypedia_item->ID, Interview::DRAFT_META, true ); ?>
					<li><a href="<?php echo esc_url( add_query_arg( 'draft', $familypedia_item->ID, Interview::url( $familypedia_person_id ) ) ); ?>"><?php echo esc_html( get_the_title( $familypedia_item ) ); ?> #<?php echo esc_html( $familypedia_item->ID ); ?></a> — <?php echo esc_html( $familypedia_data['appended'] ? __( 'Added to biography', 'family-wiki' ) : __( 'Draft', 'family-wiki' ) ); ?></li>
				<?php endif; ?>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
