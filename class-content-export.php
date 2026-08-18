<?php
namespace Family_Wiki;

/**
 * A second, independent export/import alongside GEDCOM: the text written on
 * a page, which GEDCOM has no room for. Downloaded and uploaded the same
 * way as a GEDCOM file, and matched onto people a GEDCOM import already put
 * on the wiki — by the same xref where one is available, otherwise by an
 * unambiguous exact title match. It only ever updates a page that already
 * exists; it never creates one.
 */
class Content_Export {
	const EXPORT_ACTION = 'family_wiki_content_export';
	const IMPORT_ACTION = 'family_wiki_content_import';

	/**
	 * The meta key this format uses to match a person, independent of
	 * either plugin's own GEDCOM xref meta key: its value is whatever xref
	 * the paired GEDCOM export assigned that person in the same request.
	 */
	const META_KEY = '_gedcom_xref';

	private $gedcom;

	public function __construct( GEDCOM $gedcom ) {
		$this->gedcom = $gedcom;

		add_action( 'admin_post_' . self::EXPORT_ACTION, array( $this, 'export_download' ) );
		add_action( 'admin_post_' . self::IMPORT_ACTION, array( $this, 'import_upload' ) );
		add_action( 'family_wiki_gedcom_page_after_export', array( $this, 'render_export_button' ) );
		add_action( 'family_wiki_gedcom_page_after_import', array( $this, 'render_import_section' ) );
	}

	/**
	 * Grouped with the GEDCOM download button, not a section of its own.
	 */
	public function render_export_button() {
		?>
		<p><?php esc_html_e( 'The content file carries the page text GEDCOM has no room for.', 'family-wiki' ); ?></p>
		<form class="family-wiki-download-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::EXPORT_ACTION ); ?>" />
			<?php wp_nonce_field( self::EXPORT_ACTION ); ?>
			<?php submit_button( __( 'Download Content', 'family-wiki' ), 'primary', 'submit', false ); ?>
			<span class="family-wiki-download-check" aria-hidden="true" hidden>&#10003;</span>
		</form>
		<?php
	}

	/**
	 * Grouped with the GEDCOM upload form, not a section of its own.
	 */
	public function render_import_section() {
		$updated = isset( $_GET['family_wiki_content_updated'] ) ? absint( $_GET['family_wiki_content_updated'] ) : null;
		$skipped = isset( $_GET['family_wiki_content_skipped'] ) ? absint( $_GET['family_wiki_content_skipped'] ) : 0;
		$images  = isset( $_GET['family_wiki_content_images'] ) ? absint( $_GET['family_wiki_content_images'] ) : 0;
		$error   = isset( $_GET['family_wiki_content_error'] ) ? sanitize_key( wp_unslash( $_GET['family_wiki_content_error'] ) ) : '';
		?>
		<?php if ( null !== $updated ) : ?>
			<div class="notice notice-success">
				<p>
					<?php
					$message = $skipped
						? sprintf(
							// translators: %1$d is a number of updated pages, %2$d a number of skipped entries.
							__( 'Content file applied. Updated %1$d pages; %2$d entries did not match a page and were skipped.', 'family-wiki' ),
							$updated,
							$skipped
						)
						: sprintf(
							// translators: %d is a number of updated pages.
							__( 'Content file applied. Updated %d pages.', 'family-wiki' ),
							$updated
						);
					if ( $images ) {
						$message .= ' ' . sprintf(
							// translators: %d is a number of downloaded images.
							__( 'Downloaded %d images into the media library.', 'family-wiki' ),
							$images
						);
					}
					echo esc_html( $message );
					?>
				</p>
			</div>
		<?php endif; ?>
		<?php if ( $error ) : ?>
			<div class="notice notice-error"><p><?php echo esc_html( $this->error_message( $error ) ); ?></p></div>
		<?php endif; ?>
		<p><?php esc_html_e( 'Upload a content file to fill in page text for people already on the wiki. It never creates a page on its own.', 'family-wiki' ); ?></p>
		<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::IMPORT_ACTION ); ?>" />
			<?php wp_nonce_field( self::IMPORT_ACTION ); ?>
			<p>
				<input type="file" name="content" accept=".xml,text/xml" required />
				<span class="description">
					<?php
					echo esc_html(
						sprintf(
							// translators: %s is a file size, for example 2 MB.
							__( 'Maximum size: %s', 'family-wiki' ),
							size_format( wp_max_upload_size() )
						)
					);
					?>
				</span>
			</p>
			<p>
				<label>
					<input type="checkbox" name="download_images" value="1" />
					<?php esc_html_e( 'Also download images into the media library and use them as the page photo', 'family-wiki' ); ?>
				</label>
			</p>
			<?php submit_button( __( 'Upload content', 'family-wiki' ), 'primary', 'submit', false ); ?>
		</form>
		<?php
	}

	public function export_download() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to export content.', 'family-wiki' ) );
		}
		check_admin_referer( self::EXPORT_ACTION );

		$filename = sanitize_file_name( wp_parse_url( home_url(), PHP_URL_HOST ) . '-family-wiki-content-' . current_time( 'Ymd-His' ) . '.xml' );

		nocache_headers();
		header( 'Content-Type: text/xml; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		echo $this->export_string();
		exit;
	}

	public function export_string() {
		$people = $this->gedcom->get_export_people();
		$ids    = $this->gedcom->export_xref_map( $people );

		$lines = array(
			'<?xml version="1.0" encoding="UTF-8" ?>',
			'<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:wp="http://wordpress.org/export/1.2/">',
			'<channel>',
		);

		foreach ( $people as $person ) {
			$lines[] = '<item>';
			$lines[] = '<title>' . $this->cdata( get_the_title( $person ) ) . '</title>';
			$lines[] = '<content:encoded>' . $this->cdata( $person->post_content ) . '</content:encoded>';
			if ( has_post_thumbnail( $person ) ) {
				$lines[] = '<wp:attachment_url>' . $this->cdata( wp_get_attachment_url( get_post_thumbnail_id( $person ) ) ) . '</wp:attachment_url>';
			}
			$lines[] = '<wp:postmeta>';
			$lines[] = '<wp:meta_key>' . $this->cdata( self::META_KEY ) . '</wp:meta_key>';
			$lines[] = '<wp:meta_value>' . $this->cdata( $ids[ $person->ID ] ) . '</wp:meta_value>';
			$lines[] = '</wp:postmeta>';
			$lines[] = '</item>';
		}

		$lines[] = '</channel>';
		$lines[] = '</rss>';

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * The same CDATA-splitting WordPress core's own WXR export uses, so a
	 * page whose text happens to contain "]]>" can't break the file.
	 */
	private function cdata( $value ) {
		return '<![CDATA[' . str_replace( ']]>', ']]]]><![CDATA[>', (string) $value ) . ']]>';
	}

	public function import_upload() {
		if ( ! current_user_can( 'import' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to import content.', 'family-wiki' ) );
		}
		check_admin_referer( self::IMPORT_ACTION );

		$redirect = admin_url( 'tools.php?page=' . GEDCOM::MENU_SLUG );

		$upload_error = isset( $_FILES['content']['error'] ) ? (int) $_FILES['content']['error'] : UPLOAD_ERR_NO_FILE;
		if ( UPLOAD_ERR_OK !== $upload_error ) {
			$error = in_array( $upload_error, array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ? 'file_too_large' : 'upload_failed';
			if ( UPLOAD_ERR_NO_FILE === $upload_error ) {
				$error = 'missing_file';
			}
			wp_safe_redirect( add_query_arg( 'family_wiki_content_error', $error, $redirect ) );
			exit;
		}

		if ( empty( $_FILES['content']['tmp_name'] ) || ! is_uploaded_file( $_FILES['content']['tmp_name'] ) ) {
			wp_safe_redirect( add_query_arg( 'family_wiki_content_error', 'missing_file', $redirect ) );
			exit;
		}

		$contents = file_get_contents( $_FILES['content']['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $contents || '' === trim( $contents ) ) {
			wp_safe_redirect( add_query_arg( 'family_wiki_content_error', 'empty_file', $redirect ) );
			exit;
		}

		$download_images = ! empty( $_POST['download_images'] );

		$result = $this->apply_content( $contents, $download_images );
		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg( 'family_wiki_content_error', $result->get_error_code(), $redirect ) );
			exit;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'family_wiki_content_updated' => $result['updated'],
					'family_wiki_content_skipped' => $result['skipped'],
					'family_wiki_content_images'  => $result['images'],
				),
				$redirect
			)
		);
		exit;
	}

	/**
	 * Apply an uploaded content file to the pages it matches. Never creates
	 * a page and never touches anything but post_content — and, when asked,
	 * a page's photo.
	 *
	 * @param string $contents        The uploaded content file.
	 * @param bool   $download_images Whether to fetch each matched page's
	 *                                image into the media library and set
	 *                                it as the page's photo. Off by default:
	 *                                this is the one part of the file that
	 *                                makes an outbound request, to whatever
	 *                                URL the file names.
	 */
	public function apply_content( $contents, $download_images = false ) {
		$previous_setting = libxml_use_internal_errors( true );
		$xml               = simplexml_load_string( $contents, 'SimpleXMLElement', LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous_setting );

		if ( false === $xml || ! isset( $xml->channel ) ) {
			return new \WP_Error( 'invalid_file', __( 'This does not look like a Family Wiki content file.', 'family-wiki' ) );
		}

		$index   = $this->gedcom->existing_page_index();
		$updated = 0;
		$skipped = 0;
		$images  = 0;

		foreach ( $xml->channel->item as $item ) {
			$wp_fields  = $item->children( 'http://wordpress.org/export/1.2/' );
			$content_ns = $item->children( 'http://purl.org/rss/1.0/modules/content/' );
			$title      = trim( (string) $item->title );
			$content    = isset( $content_ns->encoded ) ? (string) $content_ns->encoded : '';
			$image_url  = isset( $wp_fields->attachment_url ) ? trim( (string) $wp_fields->attachment_url ) : '';

			$post_id = $this->match_post( $wp_fields, $title, $index );
			if ( ! $post_id ) {
				++$skipped;
				continue;
			}

			wp_update_post(
				wp_slash(
					array(
						'ID'           => $post_id,
						'post_content' => $content,
					)
				)
			);
			++$updated;

			if ( $download_images && $image_url && $this->sideload_image( $image_url, $post_id ) ) {
				++$images;
			}
		}

		return array(
			'updated' => $updated,
			'skipped' => $skipped,
			'images'  => $images,
		);
	}

	/**
	 * Fetch a page's image into the media library and set it as the page's
	 * photo. Only ever called when the upload form's checkbox asked for it.
	 */
	private function sideload_image( $url, $post_id ) {
		if ( ! wp_http_validate_url( $url ) ) {
			return false;
		}

		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$attachment_id = media_sideload_image( $url, $post_id, null, 'id' );
		if ( is_wp_error( $attachment_id ) ) {
			return false;
		}

		set_post_thumbnail( $post_id, $attachment_id );

		return true;
	}

	/**
	 * The page a content entry belongs on: its xref first, an unambiguous
	 * exact title match otherwise. A title shared by more than one page is
	 * left alone rather than guessed at.
	 */
	private function match_post( $wp_fields, $title, $index ) {
		$xref = '';
		foreach ( $wp_fields->postmeta as $meta ) {
			$meta_fields = $meta->children( 'http://wordpress.org/export/1.2/' );
			if ( self::META_KEY === (string) $meta_fields->meta_key ) {
				$xref = (string) $meta_fields->meta_value;
				break;
			}
		}

		if ( $xref && isset( $index['xref'][ $xref ] ) ) {
			return (int) $index['xref'][ $xref ];
		}

		$key = strtolower( $title );
		if ( $key && ! empty( $index['title'][ $key ] ) && 1 === count( $index['title'][ $key ] ) ) {
			return (int) $index['title'][ $key ][0];
		}

		return 0;
	}

	private function error_message( $error ) {
		$messages = array(
			'missing_file'   => __( 'Please choose a content file to import.', 'family-wiki' ),
			'file_too_large' => sprintf(
				// translators: %s is a file size, for example 2 MB.
				__( 'The content file is larger than the maximum upload size of %s.', 'family-wiki' ),
				size_format( wp_max_upload_size() )
			),
			'upload_failed'  => __( 'The content file could not be uploaded.', 'family-wiki' ),
			'empty_file'     => __( 'The uploaded content file was empty.', 'family-wiki' ),
			'invalid_file'   => __( 'This does not look like a Family Wiki content file.', 'family-wiki' ),
		);

		return isset( $messages[ $error ] ) ? $messages[ $error ] : __( 'The content import failed.', 'family-wiki' );
	}
}
