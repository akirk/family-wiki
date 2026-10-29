<?php
/**
 * Private recordings captured in the interview workspace.
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
		return add_query_arg( array( 'familypedia_audio' => $clip ), Interview::url( $person_id, $session_id ) );
	}

	/** A separate metadata row per clip avoids conflicts with note autosaves. */
	public function request() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read access is checked against both person and interview below.
		$clip = isset( $_GET['familypedia_audio'] ) && is_string( $_GET['familypedia_audio'] ) ? sanitize_key( $_GET['familypedia_audio'] ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Recording writes verify a subject-specific nonce below.
		$recording = isset( $_POST['familypedia_recording'] );
		if ( ! $clip && ! $recording ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- Identifiers only; authorization and write nonce checked below.
		$input = $recording ? $_POST : $_GET;
		$person_id = absint( $input['person_id'] ?? 0 );
		$session_id = absint( $input['session_id'] ?? $input['session'] ?? 0 );
		if ( ! Interview::session( $person_id, $session_id ) ) {
			wp_die( esc_html__( 'This recording is unavailable.', 'family-wiki' ), '', array( 'response' => 403 ) );
		}
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			wp_send_json_error( array( 'message' => __( 'Audio recording requires OpenSSL on the server.', 'family-wiki' ) ), 503 );
		}
		// Persist independently of authentication salts so password/security rotations keep recordings playable.
		if ( ! get_option( 'familypedia_audio_key' ) ) {
			add_option( 'familypedia_audio_key', bin2hex( random_bytes( 32 ) ), '', false );
		}
		$key = hex2bin( get_option( 'familypedia_audio_key' ) );
		$uploads = wp_upload_dir();
		if ( $recording ) {
			check_admin_referer( Interview::ACTION . '_' . $person_id );
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Uploaded bytes validated by size and detected MIME before encryption; filenames are never used.
			$file = $_FILES['recording'] ?? array();
			if ( ! isset( $file['tmp_name'], $file['error'] ) || UPLOAD_ERR_OK !== $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) || filesize( $file['tmp_name'] ) > min( wp_max_upload_size(), 25 * MB_IN_BYTES ) ) {
				wp_send_json_error( array( 'message' => __( 'The recording could not be received or exceeds the recording size limit.', 'family-wiki' ) ), 400 );
			}
			$mime = ( new \finfo( FILEINFO_MIME_TYPE ) )->file( $file['tmp_name'] );
			if ( ! in_array( $mime, array( 'audio/webm', 'video/webm', 'audio/ogg', 'video/ogg', 'application/ogg', 'audio/mp4', 'video/mp4', 'audio/x-m4a' ), true ) ) {
				wp_send_json_error( array( 'message' => __( 'This recording format is unsupported.', 'family-wiki' ) ), 400 );
			}
			$id = wp_generate_uuid4();
			$iv = random_bytes( 12 );
			$tag = '';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local uploaded recording only.
			$encrypted = openssl_encrypt( file_get_contents( $file['tmp_name'] ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
			$directory = $uploads['basedir'] . '/familypedia-recordings';
			$path = $directory . '/' . $id . '.bin';
			// Files are encrypted even if served directly by the web server.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Binary encrypted local storage.
			if ( false === $encrypted || ! empty( $uploads['error'] ) || ! wp_mkdir_p( $directory ) || false === file_put_contents( $path, $iv . $tag . $encrypted, LOCK_EX ) ) {
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
				wp_delete_file( $path );
				wp_send_json_error( array( 'message' => __( 'Could not save recording details. Keep your local copy and retry.', 'family-wiki' ) ), 500 );
			}
			wp_send_json_success( array( 'url' => add_query_arg( 'person_id', $person_id, self::url( $person_id, $session_id, $id ) ) ) );
		}
		foreach ( get_post_meta( $session_id, self::META, false ) as $row ) {
			if ( $row['id'] !== $clip ) {
				continue;
			}
			$path = $uploads['basedir'] . '/familypedia-recordings/' . $row['id'] . '.bin';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local encrypted recording, never a user-supplied path.
			$bytes = is_file( $path ) ? file_get_contents( $path ) : false;
			$audio = false !== $bytes && strlen( $bytes ) >= 28 ? openssl_decrypt( substr( $bytes, 28 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr( $bytes, 0, 12 ), substr( $bytes, 12, 16 ) ) : false;
			if ( false === $audio ) {
				break;
			}
			nocache_headers();
			header( 'Content-Type: ' . $row['mime'] );
			header( 'X-Content-Type-Options: nosniff' );
			header( 'Content-Length: ' . strlen( $audio ) );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Decrypted audio bytes, delivered with validated media type.
			echo $audio;
			exit;
		}
		wp_die( esc_html__( 'Recording not found.', 'family-wiki' ), '', array( 'response' => 404 ) );
	}
}
