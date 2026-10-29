<?php
/**
 * Recordings captured in the interview workspace.
 *
 * @package Familypedia
 */
namespace Family_Wiki;

class Interview_Audio {
	const META = '_familypedia_audio';

	public function __construct() {
		add_action( 'wp_loaded', array( $this, 'request' ) );
	}

	public static function url( $person_id, $session_id, $clip ) {
		$attachment = get_post( absint( $clip ) );
		return $attachment && 'attachment' === $attachment->post_type && (int) $attachment->post_parent === (int) $session_id
			? wp_get_attachment_url( $attachment->ID ) : '';
	}

	/** A separate metadata row per clip avoids conflicts with note autosaves. */
	public function request() {
		if ( ! isset( $_POST['familypedia_recording'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified below.
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- Identifiers only; authorization and write nonce checked below.
		$input = $_POST;
		$person_id = absint( $input['person_id'] ?? 0 );
		$session_id = absint( $input['session_id'] ?? $input['session'] ?? 0 );
		if ( ! Interview::session( $person_id, $session_id ) ) {
			wp_die( esc_html__( 'This recording is unavailable.', 'family-wiki' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( Interview::ACTION . '_' . $person_id );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Uploaded bytes validated by size and detected MIME before using standard WordPress upload handling.
		$file = $_FILES['recording'] ?? array();
		if ( ! isset( $file['tmp_name'], $file['error'] ) || UPLOAD_ERR_OK !== $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) || filesize( $file['tmp_name'] ) > min( wp_max_upload_size(), 25 * MB_IN_BYTES ) ) {
			wp_send_json_error( array( 'message' => __( 'The recording could not be received or exceeds the recording size limit.', 'family-wiki' ) ), 400 );
		}
		$mime = ( new \finfo( FILEINFO_MIME_TYPE ) )->file( $file['tmp_name'] );
		if ( ! in_array( $mime, array( 'audio/webm', 'video/webm', 'audio/ogg', 'video/ogg', 'application/ogg', 'audio/mp4', 'video/mp4', 'audio/x-m4a' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'This recording format is unsupported.', 'family-wiki' ) ), 400 );
		}
		$extensions = array(
			'audio/webm'      => 'webm',
			'video/webm'      => 'webm',
			'audio/ogg'       => 'ogg',
			'video/ogg'       => 'ogg',
			'application/ogg' => 'ogg',
			'audio/mp4'       => 'm4a',
			'video/mp4'       => 'mp4',
			'audio/x-m4a'     => 'm4a',
		);
		// Never expose a client filename or a predictable recording sequence.
		$_FILES['recording']['name'] = 'interview-' . bin2hex( random_bytes( 24 ) ) . '.' . $extensions[ $mime ];
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$id = media_handle_upload( 'recording', $session_id, array( 'post_title' => __( 'Interview recording', 'family-wiki' ) ) );
		if ( is_wp_error( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not store the recording. Download your local copy before leaving.', 'family-wiki' ) ), 500 );
		}
		$question = isset( $input['question'] ) && is_string( $input['question'] ) ? sanitize_text_field( wp_unslash( $input['question'] ) ) : '';
		$row = array(
			'id'       => $id,
			'mime'     => $mime,
			'date'     => current_time( 'mysql' ),
			'question' => $question,
		);
		if ( ! add_post_meta( $session_id, self::META, $row ) ) {
			wp_delete_attachment( $id, true );
			wp_send_json_error( array( 'message' => __( 'Could not save recording details. Keep your local copy and retry.', 'family-wiki' ) ), 500 );
		}
		wp_send_json_success( array( 'url' => self::url( $person_id, $session_id, $id ) ) );
	}
}
