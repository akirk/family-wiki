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

	/** Rename or permanently remove a recording owned by this interview. */
	public static function manage( $person_id, $session_id, $id, $operation, $name = '' ) {
		if ( ! Interview::session( $person_id, $session_id ) || ! self::url( $person_id, $session_id, $id ) || ! current_user_can( 'delete' === $operation ? 'delete_post' : 'edit_post', $id ) ) {
			return new \WP_Error( 'permission', __( 'This recording is unavailable.', 'family-wiki' ) );
		}
		$rows = get_post_meta( $session_id, self::META, false );
		foreach ( $rows as $row ) {
			if ( (int) $row['id'] !== $id ) {
				continue;
			}
			if ( 'rename' === $operation ) {
				$name = sanitize_text_field( $name );
				if ( '' === $name ) {
					return new \WP_Error( 'name', __( 'Enter a recording name.', 'family-wiki' ) );
				}
				return wp_update_post(
					wp_slash(
						array(
							'ID'         => $id,
							'post_title' => $name,
						)
					),
					true
				);
			}
			if ( 'delete' === $operation ) {
				if ( ! wp_delete_attachment( $id, true ) ) {
					return new \WP_Error( 'delete', __( 'Could not delete the recording.', 'family-wiki' ) );
				}
				delete_post_meta( $session_id, self::META, $row );
				return true;
			}
		}
		return new \WP_Error( 'recording', __( 'Recording not found.', 'family-wiki' ) );
	}

	/** Validate timeline entries against the interview's own questions. */
	public static function markers( $session_id, $json ) {
		$markers = is_string( $json ) ? json_decode( $json, true ) : null;
		$data = get_post_meta( $session_id, Interview::META, true );
		$questions = array();
		foreach ( $data['catalogue'] ?? Interview::legacy_catalogue() as $group ) {
			$questions = array_merge( $questions, $group['questions'] );
		}
		if ( ! is_array( $markers ) || count( $markers ) > 1000 ) {
			return new \WP_Error( 'markers', __( 'Invalid recording timeline.', 'family-wiki' ) );
		}
		$clean = array();
		$previous = 0;
		foreach ( $markers as $marker ) {
			if ( ! is_array( $marker ) || ! isset( $marker['question'], $marker['time'] ) || ! is_string( $marker['question'] ) || ! isset( $questions[ $marker['question'] ] ) || ! is_numeric( $marker['time'] ) || ! is_finite( (float) $marker['time'] ) || $marker['time'] < $previous || $marker['time'] > 604800 ) {
				return new \WP_Error( 'markers', __( 'Invalid recording timeline.', 'family-wiki' ) );
			}
			$previous = (float) $marker['time'];
			$clean[] = array(
				'question' => $marker['question'],
				'time'     => $previous,
				'label'    => $questions[ $marker['question'] ][0],
			);
		}
		return $clean;
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
		$operation = isset( $input['recording_op'] ) && is_string( $input['recording_op'] ) ? sanitize_key( $input['recording_op'] ) : 'save';
		$name = isset( $input['recording_name'] ) && is_string( $input['recording_name'] ) ? sanitize_text_field( wp_unslash( $input['recording_name'] ) ) : '';
		if ( in_array( $operation, array( 'rename', 'delete' ), true ) ) {
			$result = self::manage( $person_id, $session_id, absint( $input['recording_id'] ?? 0 ), $operation, $name );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
			}
			wp_send_json_success();
		}

		$markers = self::markers( $session_id, isset( $input['markers'] ) && is_string( $input['markers'] ) ? wp_unslash( $input['markers'] ) : '[]' );
		if ( is_wp_error( $markers ) ) {
			wp_send_json_error( array( 'message' => $markers->get_error_message() ), 400 );
		}

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
		$id = media_handle_upload( 'recording', $session_id, array( 'post_title' => $name ?: __( 'Interview recording', 'family-wiki' ) ) );
		if ( is_wp_error( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not store the recording. Download your local copy before leaving.', 'family-wiki' ) ), 500 );
		}
		$question = isset( $input['question'] ) && is_string( $input['question'] ) ? sanitize_text_field( wp_unslash( $input['question'] ) ) : '';
		$row = array(
			'id'       => $id,
			'mime'     => $mime,
			'date'     => current_time( 'mysql' ),
			'question' => $question,
			'markers'  => $markers,
		);
		if ( ! add_post_meta( $session_id, self::META, wp_slash( $row ) ) ) {
			wp_delete_attachment( $id, true );
			wp_send_json_error( array( 'message' => __( 'Could not save recording details. Keep your local copy and retry.', 'family-wiki' ) ), 500 );
		}
		wp_send_json_success(
			array(
				'url'  => self::url( $person_id, $session_id, $id ),
				'id'   => $id,
				'name' => get_the_title( $id ),
			)
		);
	}
}
