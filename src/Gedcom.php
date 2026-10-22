<?php
namespace Family_Wiki;

class Gedcom {
	const URL_PATH = 'import-export';
	const EXPORT_ACTION = 'familypedia_gedcom_export';
	const IMPORT_ACTION = 'familypedia_gedcom_import';
	const SELECT_ACTION = 'familypedia_gedcom_import_selected';
	const XREF_META = '_family_wiki_gedcom_xref';
	const IMPORT_TRANSIENT_PREFIX = 'familypedia_gedcom_import_';
	const RUN_TRANSIENT_PREFIX = 'familypedia_gedcom_run_';

	/**
	 * How much of the file one request takes on. Small enough that no single
	 * request is at risk of the timeout a whole family would run into, large
	 * enough that the file is not re-read hundreds of times on the way through.
	 */
	const BATCH_PEOPLE = 25;
	const BATCH_FAMILIES = 50;

	/**
	 * One at a time: unlike the batches above, each of these is an
	 * outbound request to wherever the file says an image lives, on a
	 * connection that has already shown itself capable of dropping
	 * mid-import. Keeping a request to exactly one image keeps whatever
	 * retrying it after a dropped connection would repeat as small as it
	 * can be.
	 */
	const BATCH_IMAGES = 1;

	const CONTENT_EXPORT_ACTION = 'familypedia_content_export';

	/**
	 * The meta key the content file uses to match a person, independent of
	 * XREF_META above: its value is whatever xref the paired GEDCOM export
	 * assigned that person in the same request.
	 */
	const CONTENT_META_KEY = '_gedcom_xref';

	/**
	 * The meta key a link between two exported people travels under: its
	 * value is the relative path the link was rewritten to, and the xref
	 * of the person it points to, joined with "|".
	 */
	const CONTENT_LINK_META_KEY = '_gedcom_link';

	/**
	 * The meta key on a content item for an additional page under a
	 * person — a chronology, a house, anything with no facts of its own
	 * for GEDCOM to carry — rather than a person's own text. Its value is
	 * the xref of the person the page belongs under.
	 */
	const CONTENT_RELATED_META_KEY = '_gedcom_related_of';

	/**
	 * The instance Main built, so that the app template renders the page
	 * through the object that already handled the request.
	 *
	 * @var Gedcom|null
	 */
	private static $instance;

	/**
	 * Per token: a companion content file's items keyed by xref, and
	 * whether it asked to download images — parsed once per request no
	 * matter how many people in a batch ask for it. False for a token with
	 * no content file.
	 */
	private $content_index_cache = array();

	public function __construct() {
		if ( ! self::$instance ) {
			self::$instance = $this;
		}

		add_action( 'wp_loaded', array( $this, 'maybe_handle' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * The import, a batch per request.
	 *
	 * A family of any size takes longer than one page load should, and a form
	 * post that sits there for a minute is indistinguishable from one that has
	 * died. These walk the same file in pieces and say how far they have got. The
	 * form post below still works on its own, for whoever has no JavaScript.
	 */
	public function register_rest_routes() {
		register_rest_route(
			'familypedia/v1',
			'/import',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'can_import' ),
				'callback'            => array( $this, 'rest_import_start' ),
			)
		);

		register_rest_route(
			'familypedia/v1',
			'/import/(?P<run>[a-z0-9]{32})',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'can_import' ),
				'callback'            => array( $this, 'rest_import_step' ),
			)
		);
	}

	/**
	 * Work out what this import will do, and remember it under a run id.
	 */
	public function rest_import_start( $request ) {
		$token    = sanitize_key( (string) $request->get_param( 'token' ) );
		$contents = $token ? $this->get_import_file( $token ) : false;
		if ( false === $contents ) {
			return new \WP_Error( 'familypedia_review_expired', $this->error_message( 'review_expired' ), array( 'status' => 400 ) );
		}

		$records = $this->parse_records( $contents );
		if ( empty( $records['INDI'] ) ) {
			return new \WP_Error( 'familypedia_no_individuals', $this->error_message( 'no_individuals' ), array( 'status' => 400 ) );
		}

		$xrefs    = array_keys( $records['INDI'] );
		$selected = $request->get_param( 'selected' );

		// Null is everyone, which is what a request that names nobody asks for.
		if ( is_array( $selected ) ) {
			$wanted = array_fill_keys( array_map( 'sanitize_text_field', $selected ), true );
			$xrefs  = array_values(
				array_filter(
					$xrefs,
					function ( $xref ) use ( $wanted ) {
						return isset( $wanted[ $xref ] );
					}
				)
			);

			if ( empty( $xrefs ) ) {
				return new \WP_Error( 'familypedia_no_selection', $this->error_message( 'no_selection' ), array( 'status' => 400 ) );
			}
		}

		$run   = strtolower( wp_generate_password( 32, false, false ) );
		$state = array(
			'token'    => $token,
			'xrefs'    => $xrefs,
			'families' => empty( $records['FAM'] ) ? array() : array_keys( $records['FAM'] ),
			'stage'    => 'people',
			'cursor'   => 0,
			// Built once, as the form post builds it once, so that a page written
			// by this import is not matched against by a later entry.
			'index'    => $this->existing_page_index(),
			'id_map'   => array(),
			'claimed'  => array(),
			'created'  => 0,
			'updated'  => 0,
			'front'    => sanitize_text_field( (string) $request->get_param( 'front_page_roots' ) ),
		);

		// Asked here, on the review screen, rather than at upload time —
		// right where the import itself is about to start.
		$this->save_import_state( $token, array( 'download_images' => ! empty( $request->get_param( 'download_images' ) ) ) );

		// Lets a content file uploaded alongside this one join the run, so
		// each person's text lands right after the person themselves.
		$state = $this->add_content_to_run_state( $state, $token );

		if ( ! set_transient( self::RUN_TRANSIENT_PREFIX . $run, $state, HOUR_IN_SECONDS ) ) {
			return new \WP_Error( 'familypedia_run_failed', $this->error_message( 'store_failed' ), array( 'status' => 500 ) );
		}

		return array(
			'run'      => $run,
			'people'   => count( $state['xrefs'] ),
			'families' => count( $state['families'] ),
		);
	}

	/**
	 * Carry one run a batch further.
	 */
	public function rest_import_step( $request ) {
		$run   = sanitize_key( (string) $request->get_param( 'run' ) );
		$state = get_transient( self::RUN_TRANSIENT_PREFIX . $run );
		if ( ! is_array( $state ) ) {
			return new \WP_Error( 'familypedia_run_expired', __( 'The import stopped before it finished. Please upload the file again.', 'family-wiki' ), array( 'status' => 400 ) );
		}

		$contents = $this->get_import_file( $state['token'] );
		if ( false === $contents ) {
			return new \WP_Error( 'familypedia_review_expired', $this->error_message( 'review_expired' ), array( 'status' => 400 ) );
		}

		$records = $this->parse_records( $contents );

		if ( 'families' === $state['stage'] ) {
			$state = $this->import_families_batch( $state, $records );
		} elseif ( 'images' === $state['stage'] ) {
			$state = $this->import_images_batch( $state );
		} else {
			$state = $this->import_people_batch( $state, $records );
		}

		if ( is_wp_error( $state ) ) {
			// The run and the file are kept, so that it can be sent again.
			return $state;
		}

		if ( 'done' === $state['stage'] ) {
			return $this->finish_run( $run, $state );
		}

		set_transient( self::RUN_TRANSIENT_PREFIX . $run, $state, HOUR_IN_SECONDS );

		return $this->run_progress( $state );
	}

	private function import_people_batch( $state, $records ) {
		$total = count( $state['xrefs'] );
		$end   = min( $total, $state['cursor'] + self::BATCH_PEOPLE );

		for ( ; $state['cursor'] < $end; $state['cursor']++ ) {
			$xref = $state['xrefs'][ $state['cursor'] ];
			if ( ! isset( $records['INDI'][ $xref ] ) ) {
				continue;
			}

			$person = $this->import_person( $xref, $records['INDI'][ $xref ], $state['index'], $state['claimed'] );
			if ( is_wp_error( $person ) ) {
				return $person;
			}

			$state['id_map'][ $xref ] = $person['id'];

			if ( $person['existed'] ) {
				++$state['updated'];
			} else {
				++$state['created'];
			}

			// A content file, if the run has one, applies this person's
			// text right after the person themselves — the progress bar is
			// one person at a time either way.
			$state = $this->apply_content_to_person( $state, $xref, $person['id'] );
		}

		if ( $state['cursor'] >= $total ) {
			if ( ! empty( $state['content']['pending_images'] ) ) {
				$state['stage'] = 'images';
			} else {
				$state['stage'] = $state['families'] ? 'families' : 'done';
			}
			$state['cursor'] = 0;
		}

		return $state;
	}

	/**
	 * Downloads one image (BATCH_IMAGES worth, in practice always one) at
	 * a time from the queue apply_content_to_person() and
	 * apply_related_content_to_person() built up while creating every
	 * person and additional page — by the time this stage runs, every
	 * page any of them belongs to already exists, so a dropped
	 * connection here never risks recreating or duplicating a page,
	 * only re-attempting whichever single image was in flight.
	 */
	private function import_images_batch( $state ) {
		$pending = $state['content']['pending_images'];
		$total   = count( $pending );
		$end     = min( $total, $state['cursor'] + self::BATCH_IMAGES );

		for ( ; $state['cursor'] < $end; $state['cursor']++ ) {
			if ( $this->apply_pending_image( $pending[ $state['cursor'] ] ) ) {
				++$state['content']['images'];
			}
		}

		if ( $state['cursor'] >= $total ) {
			$state['stage']  = $state['families'] ? 'families' : 'done';
			$state['cursor'] = 0;
		}

		return $state;
	}

	/**
	 * Families are linked once everybody is in, and can be taken a slice at a
	 * time: each slice merges into what the last one wrote rather than replacing
	 * it, so the result is the same as doing them all at once.
	 */
	private function import_families_batch( $state, $records ) {
		$all   = isset( $records['FAM'] ) ? $records['FAM'] : array();
		$total = count( $state['families'] );
		$end   = min( $total, $state['cursor'] + self::BATCH_FAMILIES );
		$slice = array();

		for ( ; $state['cursor'] < $end; $state['cursor']++ ) {
			$xref = $state['families'][ $state['cursor'] ];
			if ( isset( $all[ $xref ] ) ) {
				$slice[ $xref ] = $all[ $xref ];
			}
		}

		$this->import_family_links( $slice, $state['id_map'] );

		if ( $state['cursor'] >= $total ) {
			$state['stage'] = 'done';
		}

		return $state;
	}

	private function finish_run( $run, $state ) {
		Main::flush_family_data_cache();

		// Every person this run touched now has a page, so a content
		// file's links to them can be resolved — while it is still
		// parked, before it is cleaned up below.
		if ( ! empty( $state['content'] ) ) {
			$this->resolve_content_links( $state['token'], $state['id_map'], isset( $state['related_id_map'] ) ? $state['related_id_map'] : array() );
			$this->resolve_related_parents( $state['id_map'], isset( $state['parent_of'] ) ? $state['parent_of'] : array() );
		}

		$this->delete_import_file( $state['token'] );
		delete_transient( self::RUN_TRANSIENT_PREFIX . $run );

		$message = sprintf(
			// translators: %1$d is a number of people created, %2$d a number updated.
			__( 'GEDCOM import complete. Created %1$d people and updated %2$d people.', 'family-wiki' ),
			$state['created'],
			$state['updated']
		);

		// A content file, if the run had one, adds its own summary sentence.
		if ( ! empty( $state['content'] ) ) {
			$message .= $this->content_summary_message( $state['content']['updated'], $state['content']['images'] );
		}

		$message .= $this->front_page_tree_notice( $state['front'], $state['id_map'] );

		Editor::set_notice( $message );

		return array(
			'done'     => true,
			'stage'    => 'done',
			'created'  => $state['created'],
			'updated'  => $state['updated'],
			'message'  => $message,
			// The people are in now, so the front page is where there is
			// something to see; the import form has nothing left to say.
			'redirect' => Front_Page::url(),
		);
	}

	private function run_progress( $state ) {
		if ( 'families' === $state['stage'] ) {
			$total = count( $state['families'] );
		} elseif ( 'images' === $state['stage'] ) {
			$total = count( $state['content']['pending_images'] );
		} else {
			$total = count( $state['xrefs'] );
		}

		return array(
			'done'     => false,
			'stage'    => $state['stage'],
			'position' => (int) $state['cursor'],
			'total'    => $total,
			'created'  => $state['created'],
			'updated'  => $state['updated'],
		);
	}

	public static function get_page_url() {
		return home_url( '/' . App::URL_PATH . '/' . self::URL_PATH . '/' );
	}

	/**
	 * Exporting hands out everyone's dates and places at once, which is the
	 * whole wiki in one file, so it stays with the people who administer it.
	 */
	public static function can_export() {
		return current_user_can( 'manage_options' );
	}

	public static function can_import() {
		return current_user_can( 'import' );
	}

	public static function can_use() {
		return self::can_export() || self::can_import();
	}

	/**
	 * The page body, for the app template.
	 */
	public static function render() {
		$gedcom = self::$instance ? self::$instance : new self();
		$gedcom->render_page();
	}

	/**
	 * Handle the forms before any template output, so that an import can
	 * redirect and an export can send its own headers.
	 */
	public function maybe_handle() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- every handler below checks its own nonce.
		$action = isset( $_POST['familypedia_action'] ) ? sanitize_key( wp_unslash( $_POST['familypedia_action'] ) ) : '';

		if ( self::EXPORT_ACTION === $action ) {
			$this->export_download();
		} elseif ( self::IMPORT_ACTION === $action ) {
			$this->import_upload();
		} elseif ( self::SELECT_ACTION === $action ) {
			$this->import_selected();
		} elseif ( self::CONTENT_EXPORT_ACTION === $action ) {
			$this->export_content_download();
		}
	}

	public function render_page() {
		wp_app_enqueue_script( 'familypedia-gedcom-download', Assets::url( 'gedcom-download.js' ), array(), Assets::version( 'gedcom-download.js' ), true, App::URL_PATH );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$review = isset( $_GET['familypedia_review'] ) ? sanitize_key( wp_unslash( $_GET['familypedia_review'] ) ) : '';

		if ( $review && self::can_import() ) {
			$this->render_import_review( $review );
			return;
		}

		// Import comes first: filling an empty wiki is what this page is
		// opened for, while an export is something to reach for now and then.
		if ( self::can_import() ) {
			?>
			<section class="familypedia-gedcom">
				<h2><?php esc_html_e( 'Import', 'family-wiki' ); ?></h2>
				<?php $this->render_upload_form(); ?>
			</section>
			<?php
		}

		if ( self::can_export() ) {
			?>
			<section class="familypedia-gedcom">
				<h2><?php esc_html_e( 'Export', 'family-wiki' ); ?></h2>
				<p><?php esc_html_e( 'Download the people on this wiki as a GEDCOM file.', 'family-wiki' ); ?></p>
				<form class="familypedia-download-form" method="post" action="<?php echo esc_url( self::get_page_url() ); ?>">
					<input type="hidden" name="familypedia_action" value="<?php echo esc_attr( self::EXPORT_ACTION ); ?>" />
					<?php wp_nonce_field( self::EXPORT_ACTION ); ?>
					<button type="submit" class="familypedia-button familypedia-button--primary"><?php esc_html_e( 'Download GEDCOM', 'family-wiki' ); ?></button>
					<span class="familypedia-download-check" aria-hidden="true" hidden>&#10003;</span>
				</form>
				<?php $this->render_content_export_button(); ?>
			</section>
			<?php
		}
	}

	private function render_upload_form() {
		?>
		<p><?php esc_html_e( 'Upload a GEDCOM file, review the people in it, and choose which entries or descendant subtrees to import. Existing people are matched by prior GEDCOM xref first, then by name.', 'family-wiki' ); ?></p>
		<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( self::get_page_url() ); ?>">
			<?php wp_nonce_field( self::IMPORT_ACTION ); ?>
			<input type="hidden" name="familypedia_action" value="<?php echo esc_attr( self::IMPORT_ACTION ); ?>" />
			<p>
				<input type="file" name="gedcom" accept=".ged,.gedcom,text/plain" required />
				<span class="familypedia-field__hint">
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
			<?php $this->render_content_field(); ?>
			<button type="submit" class="familypedia-button familypedia-button--primary"><?php esc_html_e( 'Upload and review GEDCOM', 'family-wiki' ); ?></button>
		</form>
		<?php
	}

	private function render_import_review( $token ) {
		$contents = $this->get_import_file( $token );
		if ( false === $contents ) {
			?>
			<p class="familypedia-notice familypedia-notice--error"><?php echo esc_html( $this->error_message( 'review_expired' ) ); ?></p>
			<?php
			$this->render_upload_form();
			return;
		}

		$records = $this->parse_records( $contents );
		if ( empty( $records['INDI'] ) ) {
			?>
			<p class="familypedia-notice familypedia-notice--error"><?php echo esc_html( $this->error_message( 'no_individuals' ) ); ?></p>
			<?php
			$this->render_upload_form();
			return;
		}

		$families     = empty( $records['FAM'] ) ? array() : $records['FAM'];
		$people       = $this->review_people( $records );
		$total        = count( $people );
		$family_count = count( $families );
		$tree_data    = $this->review_tree_data( $families, $people );

		// Which lines the front page's family trees are grown from. Every import
		// can add one; only the first import into an empty wiki arrives with one
		// already ticked.
		$front_roots = $this->front_page_tree_default() ? $this->first_branch( $people, $tree_data ) : '';
		$settings    = array(
			'endpoint' => rest_url( 'familypedia/v1/import' ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'token'    => $token,
			'l10n'     => array(
				'starting'   => __( 'Reading the file…', 'family-wiki' ),
				// translators: %1$s is a number of people done, %2$s the total.
				'people'     => __( 'Importing people: %1$s of %2$s', 'family-wiki' ),
				// translators: %1$s is a number of family records done, %2$s the total.
				'families'   => __( 'Linking families: %1$s of %2$s', 'family-wiki' ),
				// translators: %1$s is a number of images done, %2$s the total.
				'images'     => __( 'Downloading images: %1$s of %2$s', 'family-wiki' ),
				// translators: %s is an error message.
				'failed'     => __( 'The import stopped: %s', 'family-wiki' ),
				'uncheck'    => __( 'Uncheck branch', 'family-wiki' ),
				'check'      => __( 'Check branch', 'family-wiki' ),
				'toggle'     => __( 'Show or hide this branch', 'family-wiki' ),
				'front'      => __( 'Front page tree', 'family-wiki' ),
				'trashed'    => __( 'will be restored from trash', 'family-wiki' ),
				'importAll'  => array(
					// translators: %d is a number of people.
					'one'   => __( 'Import all %d person', 'family-wiki' ),
					// translators: %d is a number of people.
					'other' => __( 'Import all %d people', 'family-wiki' ),
				),
				'importSome' => array(
					// translators: %d is a number of people.
					'one'   => __( 'Import %d person', 'family-wiki' ),
					// translators: %d is a number of people.
					'other' => __( 'Import %d people', 'family-wiki' ),
				),
				'importNone' => __( 'Nobody is ticked', 'family-wiki' ),
			),
		);
		?>
		<section
			class="familypedia-gedcom-review"
			data-familypedia-gedcom-tree="<?php echo esc_attr( wp_json_encode( $tree_data, JSON_HEX_TAG | JSON_HEX_AMP ) ); ?>"
			data-familypedia-gedcom-settings="<?php echo esc_attr( wp_json_encode( $settings, JSON_HEX_TAG | JSON_HEX_AMP ) ); ?>"
		>
			<h2><?php esc_html_e( 'Review GEDCOM import', 'family-wiki' ); ?></h2>
			<?php
			$matched = 0;
			$trashed = 0;
			foreach ( $people as $person ) {
				if ( $person['match_id'] ) {
					++$matched;
				}
				if ( $person['match_trash'] ) {
					++$trashed;
				}
			}
			?>
			<p>
				<?php
				echo esc_html(
					sprintf(
						// translators: %1$d is a number of people, %2$d a number of family records, %3$d a number of people.
						__( 'Found %1$d people and %2$d family records. %3$d of them would land on a page that already exists.', 'family-wiki' ),
						$total,
						$family_count,
						$matched
					)
				);

				if ( $trashed ) {
					echo ' ';
					echo esc_html(
						sprintf(
							// translators: %d is a number of pages in the trash.
							_n(
								'%d of those pages is in the trash, and importing brings it back.',
								'%d of those pages are in the trash, and importing brings them back.',
								$trashed,
								'family-wiki'
							),
							$trashed
						)
					);
				}
				?>
			</p>
			<form method="post" action="<?php echo esc_url( self::get_page_url() ); ?>" data-familypedia-gedcom-form>
				<input type="hidden" name="familypedia_action" value="<?php echo esc_attr( self::SELECT_ACTION ); ?>" />
				<input type="hidden" name="familypedia_review" value="<?php echo esc_attr( $token ); ?>" />
				<?php wp_nonce_field( self::SELECT_ACTION ); ?>

				<?php if ( false !== $this->get_content_file( $token ) ) : ?>
					<p class="familypedia-field familypedia-field--check">
						<label>
							<input type="checkbox" name="download_images" value="1" data-familypedia-gedcom-download-images />
							<?php esc_html_e( 'Also download images into the media library and use them as the page photo', 'family-wiki' ); ?>
						</label>
					</p>
				<?php endif; ?>

				<?php
				/*
				 * Taking the whole file is the common case, and scrolling a tree of
				 * hundreds to say so is a poor way to spend an afternoon. The same
				 * button stands above and below the tree, saying the same thing: how
				 * many people are ticked, and that pressing it imports them.
				 */
				$import_label = sprintf(
					// translators: %d is a number of people.
					_n( 'Import all %d person', 'Import all %d people', $total, 'family-wiki' ),
					$total
				);
				?>
				<div class="familypedia-gedcom-review__actions">
					<button type="submit" class="familypedia-button familypedia-button--primary" data-familypedia-gedcom-submit><?php echo esc_html( $import_label ); ?></button>
					<span class="familypedia-field__hint"><?php esc_html_e( 'Untick anyone below to leave them out.', 'family-wiki' ); ?></span>
				</div>

				<?php
				/*
				 * Which branch leads the front page. The tree offers this on every
				 * line it draws; this carries the answer, and is what the page falls
				 * back to without script, where there are no lines to tick.
				 */
				?>
				<input type="hidden" name="familypedia_front_page_roots" value="<?php echo esc_attr( $front_roots ); ?>" data-familypedia-gedcom-front-roots />

				<div class="familypedia-gedcom-progress" data-familypedia-gedcom-progress hidden>
					<progress data-familypedia-gedcom-progress-bar max="100" value="0"></progress>
					<p class="familypedia-gedcom-progress__text" role="status" data-familypedia-gedcom-progress-text></p>
					<button type="button" class="familypedia-button" data-familypedia-gedcom-retry hidden><?php esc_html_e( 'Retry', 'family-wiki' ); ?></button>
				</div>

				<?php
				/*
				 * Everyone in the file, as a plain list. The tree below is what this
				 * page is picked through with, and it is drawn by script; without one
				 * this table is the only way to leave anybody out, so it stays in the
				 * page and the script hides it.
				 */
				?>
				<table class="familypedia-gedcom-review__table">
					<thead>
						<tr>
							<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Import', 'family-wiki' ); ?></span></th>
							<th scope="col"><?php esc_html_e( 'Person', 'family-wiki' ); ?></th>
							<th scope="col"><?php esc_html_e( 'On your wiki', 'family-wiki' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Subtree', 'family-wiki' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Birth', 'family-wiki' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Death', 'family-wiki' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $people as $person ) : ?>
							<tr data-familypedia-gedcom-row>
								<th scope="row">
									<input type="checkbox" name="familypedia_people[]" value="<?php echo esc_attr( $person['xref'] ); ?>" data-familypedia-gedcom-person="<?php echo esc_attr( $person['xref'] ); ?>" checked />
								</th>
								<td>
									<strong><?php echo esc_html( $person['name'] ); ?></strong>
									<?php if ( $person['match_id'] ) : ?>
										<br />
										<span class="familypedia-field__hint">
											<?php
											echo esc_html(
												sprintf(
													$person['match_trash']
														// translators: %s is a person's name.
														? __( 'restores “%s” from the trash', 'family-wiki' )
														// translators: %s is a person's name.
														: __( 'updates “%s”', 'family-wiki' ),
													get_the_title( $person['match_id'] )
												)
											);
											?>
										</span>
									<?php endif; ?>
								</td>
								<td><?php echo $person['wiki_hits'] ? esc_html( $person['wiki_hits'] ) : '<span aria-hidden="true">-</span>'; ?></td>
								<td><?php echo $person['count'] ? esc_html( $person['count'] ) : '<span aria-hidden="true">-</span>'; ?></td>
								<td><?php echo esc_html( $person['birth'] ); ?></td>
								<td><?php echo esc_html( $person['death'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php
				/*
				 * The wiki's own tree styles draw this, so the file looks like the
				 * pages it will become. Everyone starts ticked: a file is uploaded to
				 * be imported, and saying which few people to leave out is less work
				 * than picking hundreds one at a time.
				 */
				?>
				<div class="familypedia-gedcom-tree familypedia-tree" data-familypedia-gedcom-tree hidden>
					<p class="familypedia-field__hint"><?php esc_html_e( 'The box at the right of a line adds a family tree block for that branch to the front page.', 'family-wiki' ); ?></p>
					<ul class="familypedia-tree__list" data-familypedia-gedcom-tree-list></ul>
					<?php if ( $trashed ) : ?>
						<p class="familypedia-field__hint familypedia-gedcom-tree__footnote">
							<sup class="familypedia-gedcom-tree__note">1</sup>
							<?php esc_html_e( 'This person is already on a page that is in the trash. Importing takes it back out rather than writing a second one, so whatever pointed at it still does.', 'family-wiki' ); ?>
						</p>
					<?php endif; ?>
					<p class="familypedia-field__hint" data-familypedia-gedcom-tree-more hidden>
						<?php
						echo esc_html(
							sprintf(
								// translators: %d is a number of people left out of a drawing of the file.
								__( '%d more people are not drawn here, and are imported along with the rest.', 'family-wiki' ),
								0
							)
						);
						?>
					</p>
				</div>
				<?php
				/*
				 * How many are being left behind. What is being taken is on the
				 * button, and how many the file holds is the first line of the
				 * page, so this says the one thing neither of them does — and
				 * only while there is something to say.
				 */
				?>
				<p class="familypedia-field__hint" data-familypedia-gedcom-left-out hidden>
					<?php
					echo esc_html(
						sprintf(
							// translators: %d is a number of people in the file that are not ticked.
							__( 'Leaving out %d of them.', 'family-wiki' ),
							0
						)
					);
					?>
				</p>
				<p>
					<button type="submit" class="familypedia-button familypedia-button--primary" data-familypedia-gedcom-submit><?php echo esc_html( $import_label ); ?></button>
				</p>
			</form>
		</section>
		<?php
		wp_app_enqueue_script( 'familypedia-gedcom', Assets::url( 'gedcom.js' ), array(), Assets::version( 'gedcom.js' ), true, App::URL_PATH );
	}

	/**
	 * The line the front page tree is offered on: the first branch the review
	 * draws. The tree starts from the people the file gives no parents, earliest
	 * born first, and only a line with somebody under it is offered at all — so
	 * this is the topmost box on the page.
	 *
	 * @param array $people The review's people.
	 * @param array $tree   What was handed to the browser to draw.
	 */
	private function first_branch( $people, $tree ) {
		$has_parents = array();
		foreach ( $tree as $entry ) {
			foreach ( ( isset( $entry['children'] ) ? $entry['children'] : array() ) as $child ) {
				$has_parents[ $child ] = true;
			}
		}

		$tops = array();
		foreach ( $people as $person ) {
			if ( ! isset( $has_parents[ $person['xref'] ] ) && ! empty( $tree[ $person['xref'] ]['children'] ) ) {
				$tops[] = $person;
			}
		}

		usort( $tops, array( $this, 'sort_by_birth' ) );

		return $tops ? $tops[0]['xref'] : '';
	}

	/**
	 * The order the tree draws people in, matching the browser's: undated people
	 * last, where a missing year would otherwise read as the oldest of every
	 * household.
	 */
	private function sort_by_birth( $a, $b ) {
		$left  = $a['birth_year'] ? $a['birth_year'] : '9999';
		$right = $b['birth_year'] ? $b['birth_year'] : '9999';

		if ( $left === $right ) {
			return strcmp( $a['name'], $b['name'] );
		}

		return $left < $right ? -1 : 1;
	}

	/**
	 * Whether a front page tree is offered ticked. A wiki with nobody on it yet
	 * is one where this import is the whole family, and a family tree is what its
	 * front page wants to lead with. On a wiki that already has people, or a front
	 * page that already draws a tree, what leads it is a decision for them to
	 * make — the boxes are there either way, just empty.
	 */
	private function front_page_tree_default() {
		if ( Front_Page::has_tree() ) {
			return false;
		}

		return ! Person::get_all(
			array(
				'fields'         => 'ids',
				'posts_per_page' => 1,
			)
		);
	}

	public function export_download() {
		if ( ! self::can_export() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to export GEDCOM data.', 'family-wiki' ), 403 );
		}
		check_admin_referer( self::EXPORT_ACTION );

		$filename = sanitize_file_name( wp_parse_url( home_url(), PHP_URL_HOST ) . '-familypedia-' . current_time( 'Ymd-His' ) . '.ged' );

		nocache_headers();
		header( 'Content-Type: text/plain; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- This is the body of a text/plain GEDCOM download, not HTML; escaping it would corrupt the file.
		echo $this->export_string();
		exit;
	}

	public function import_upload() {
		if ( ! self::can_import() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to import GEDCOM data.', 'family-wiki' ), 403 );
		}
		check_admin_referer( self::IMPORT_ACTION );

		// Report why the upload did not arrive, rather than asking for a file that was chosen.
		$upload_error = isset( $_FILES['gedcom']['error'] ) ? (int) $_FILES['gedcom']['error'] : UPLOAD_ERR_NO_FILE;
		if ( UPLOAD_ERR_OK !== $upload_error ) {
			$error = in_array( $upload_error, array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ? 'file_too_large' : 'upload_failed';
			if ( UPLOAD_ERR_NO_FILE === $upload_error ) {
				$error = 'missing_file';
			}
			$this->fail( $error );
		}

		$tmp_name = isset( $_FILES['gedcom']['tmp_name'] ) ? sanitize_text_field( wp_unslash( $_FILES['gedcom']['tmp_name'] ) ) : '';
		if ( '' === $tmp_name || ! is_uploaded_file( $tmp_name ) ) {
			$this->fail( 'missing_file' );
		}

		$contents = file_get_contents( $tmp_name );
		if ( false === $contents || '' === trim( $contents ) ) {
			$this->fail( 'empty_file' );
		}

		$records = $this->parse_records( $contents );
		if ( empty( $records['INDI'] ) ) {
			$this->fail( 'no_individuals' );
		}

		// The token travels back through sanitize_key(), which lowercases it, so
		// only generate lowercase tokens: on a case sensitive database a mixed
		// case token would no longer find its own transient.
		$token = strtolower( wp_generate_password( 32, false, false ) );
		if ( ! $this->store_import_file( $token, $contents ) ) {
			// Say so here, rather than letting the review screen report the file as expired.
			$this->fail( 'store_failed' );
		}

		// Lets a content file uploaded in the same form park itself under
		// this same token, so it can join the import once it starts.
		$this->park_content_file( $token );

		wp_safe_redirect( add_query_arg( 'familypedia_review', $token, self::get_page_url() ) );
		exit;
	}

	/**
	 * Leave the reason on the page the form came from and go back to it.
	 *
	 * @param string $error A key of error_message().
	 * @param string $token The review being worked on, when there is one.
	 */
	private function fail( $error, $token = '' ) {
		Editor::set_notice( $this->error_message( $error ), 'error' );
		wp_safe_redirect( $token ? add_query_arg( 'familypedia_review', $token, self::get_page_url() ) : self::get_page_url() );
		exit;
	}

	/**
	 * Park the uploaded file between the upload and the selection request.
	 *
	 * The files themselves stay on disk and only their location goes into
	 * the transient: a GEDCOM is easily hundreds of kilobytes, which is more
	 * than belongs in an option row, and more than some databases will
	 * accept there. One transient covers both the GEDCOM file and its
	 * content file, since they always arrive and leave together.
	 */
	private function store_import_file( $token, $contents ) {
		$path = $this->write_temp_file( 'familypedia-gedcom-' . $token, $contents );

		return $path && $this->save_import_state( $token, array( 'gedcom' => $path ) );
	}

	/**
	 * Park a content file uploaded alongside a GEDCOM file, under the same
	 * token. Silently does nothing when no content file came along, or it
	 * failed: it is optional, and should never be the reason a GEDCOM
	 * import fails.
	 */
	private function park_content_file( $token ) {
		// The nonce was verified by import_upload(), the only caller.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$tmp_name     = isset( $_FILES['content']['tmp_name'] ) ? sanitize_text_field( wp_unslash( $_FILES['content']['tmp_name'] ) ) : '';
		$upload_error = isset( $_FILES['content']['error'] ) ? (int) $_FILES['content']['error'] : UPLOAD_ERR_NO_FILE;
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( '' === $tmp_name || UPLOAD_ERR_OK !== $upload_error || ! is_uploaded_file( $tmp_name ) ) {
			return;
		}

		$contents = file_get_contents( $tmp_name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $contents || '' === trim( $contents ) || is_wp_error( $this->parse_content_xml( $contents ) ) ) {
			return;
		}

		$path = $this->write_temp_file( 'familypedia-content-' . $token, $contents );
		if ( ! $path ) {
			return;
		}

		$this->save_import_state( $token, array( 'content' => $path ) );
	}

	private function write_temp_file( $prefix, $contents ) {
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$path = wp_tempnam( $prefix );
		if ( ! $path ) {
			return false;
		}

		if ( ! file_put_contents( $path, $contents ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			wp_delete_file( $path );
			return false;
		}

		return $path;
	}

	/**
	 * Merge into whatever's already parked under this token, so the GEDCOM
	 * file and a content file share one transient even though they arrive
	 * as two separate temp files, possibly in two requests.
	 */
	private function save_import_state( $token, $changes ) {
		$state = get_transient( self::IMPORT_TRANSIENT_PREFIX . $token );
		if ( ! is_array( $state ) ) {
			$state = array(
				'gedcom'          => '',
				'content'         => '',
				'download_images' => false,
			);
		}

		return set_transient( self::IMPORT_TRANSIENT_PREFIX . $token, array_merge( $state, $changes ), HOUR_IN_SECONDS );
	}

	private function import_state( $token ) {
		$state = get_transient( self::IMPORT_TRANSIENT_PREFIX . $token );

		return is_array( $state ) ? $state : array();
	}

	private function get_import_file( $token ) {
		$state = $this->import_state( $token );

		return empty( $state['gedcom'] ) ? false : $this->read_temp_file( $state['gedcom'] );
	}

	private function get_content_file( $token ) {
		$state = $this->import_state( $token );

		return empty( $state['content'] ) ? false : $this->read_temp_file( $state['content'] );
	}

	private function content_download_images_requested( $token ) {
		$state = $this->import_state( $token );

		return ! empty( $state['download_images'] );
	}

	private function read_temp_file( $path ) {
		// Only ever read back a file this class parked in the temp directory.
		if ( ! is_string( $path ) || 0 !== strpos( $path, get_temp_dir() ) || ! is_readable( $path ) ) {
			return false;
		}

		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		return ( false === $contents || '' === $contents ) ? false : $contents;
	}

	private function delete_import_file( $token ) {
		$state = $this->import_state( $token );
		delete_transient( self::IMPORT_TRANSIENT_PREFIX . $token );

		foreach ( array( 'gedcom', 'content' ) as $key ) {
			if ( ! empty( $state[ $key ] ) && 0 === strpos( $state[ $key ], get_temp_dir() ) && file_exists( $state[ $key ] ) ) {
				wp_delete_file( $state[ $key ] );
			}
		}
	}

	public function import_selected() {
		if ( ! self::can_import() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to import GEDCOM data.', 'family-wiki' ), 403 );
		}
		check_admin_referer( self::SELECT_ACTION );

		$token    = isset( $_POST['familypedia_review'] ) ? sanitize_key( wp_unslash( $_POST['familypedia_review'] ) ) : '';
		$contents = $token ? $this->get_import_file( $token ) : false;
		if ( false === $contents ) {
			$this->fail( 'review_expired' );
		}

		$selected = isset( $_POST['familypedia_people'] ) && is_array( $_POST['familypedia_people'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['familypedia_people'] ) ) : array();

		// Asked here, on the review screen, rather than at upload time —
		// right where the import itself is about to start.
		$this->save_import_state( $token, array( 'download_images' => ! empty( $_POST['download_images'] ) ) );

		$result = $this->import_string( $contents, $selected );
		if ( is_wp_error( $result ) ) {
			// The file is kept, so that the selection can be corrected and sent again.
			Editor::set_notice( $result->get_error_message(), 'error' );
			wp_safe_redirect( add_query_arg( 'familypedia_review', $token, self::get_page_url() ) );
			exit;
		}

		$notice = sprintf(
			// translators: %1$d is a number of people created, %2$d a number updated.
			__( 'GEDCOM import complete. Created %1$d people and updated %2$d people.', 'family-wiki' ),
			$result['created'],
			$result['updated']
		);

		// The no-JS path has no per-person step for a content file to ride
		// along with, so it goes in as one whole-file pass instead, while
		// it is still parked — before delete_import_file() below.
		$notice = $this->add_content_to_message( $notice, $token, $result['ids'] );

		$front   = isset( $_POST['familypedia_front_page_roots'] ) ? sanitize_text_field( wp_unslash( $_POST['familypedia_front_page_roots'] ) ) : '';
		$notice .= $this->front_page_tree_notice( $front, $result['ids'] );

		$this->delete_import_file( $token );

		Editor::set_notice( $notice );
		wp_safe_redirect( Front_Page::url() );
		exit;
	}

	public function export_string() {
		$people = $this->get_export_people();
		$ids    = $this->export_xrefs( $people );

		$families = $this->get_export_families( $people, $ids );
		$lines    = array(
			'0 HEAD',
			'1 SOUR Familypedia',
			'1 GEDC',
			'2 VERS 5.5.1',
			'2 FORM LINEAGE-LINKED',
			'1 CHAR UTF-8',
		);

		foreach ( $people as $person ) {
			$lines = array_merge( $lines, $this->export_individual( $person, $ids[ $person->ID ], $families ) );
		}

		$f = 1;
		foreach ( $families as $family ) {
			$family['xref'] = 'F' . $f++;
			$lines         = array_merge( $lines, $this->export_family( $family ) );
		}

		$lines[] = '0 TRLR';

		return implode( "\r\n", $lines ) . "\r\n";
	}

	public function import_string( $contents, $selected_xrefs = null ) {
		$records = $this->parse_records( $contents );
		if ( empty( $records['INDI'] ) ) {
			return new \WP_Error( 'no_individuals', __( 'The GEDCOM file does not contain individual records.', 'family-wiki' ) );
		}
		if ( is_array( $selected_xrefs ) ) {
			$selected_xrefs = array_fill_keys( array_map( 'sanitize_text_field', $selected_xrefs ), true );
			foreach ( array_keys( $records['INDI'] ) as $xref ) {
				if ( empty( $selected_xrefs[ $xref ] ) ) {
					unset( $records['INDI'][ $xref ] );
				}
			}
			if ( empty( $records['INDI'] ) ) {
				return new \WP_Error( 'no_selection', __( 'Please select at least one GEDCOM person to import.', 'family-wiki' ) );
			}
		}

		$created = 0;
		$updated = 0;
		$id_map  = array();
		$index   = $this->existing_page_index();
		$claimed = array();

		foreach ( $records['INDI'] as $xref => $record ) {
			$person = $this->import_person( $xref, $record, $index, $claimed );
			if ( is_wp_error( $person ) ) {
				return $person;
			}

			$id_map[ $xref ] = $person['id'];

			if ( $person['existed'] ) {
				++$updated;
			} else {
				++$created;
			}
		}

		$this->import_family_links( isset( $records['FAM'] ) ? $records['FAM'] : array(), $id_map );
		Main::flush_family_data_cache();

		return array(
			'created' => $created,
			'updated' => $updated,
			'ids'     => $id_map,
		);
	}

	/**
	 * Apply a content file on its own — the same whole-file pass
	 * add_content_to_message() runs a parked upload through, exposed here
	 * for anything that already has a content file in hand and a GEDCOM
	 * import's xrefs to match it against, without a token to park behind.
	 *
	 * @param string $contents        The content file, in the format
	 *                                 export_content_download() writes.
	 * @param bool   $download_images Whether to fetch each item's images
	 *                                 into the media library.
	 * @return array|\WP_Error See apply_content().
	 */
	public function apply_content_string( $contents, $download_images = false ) {
		return $this->apply_content( $contents, $download_images );
	}

	/**
	 * Lead the front page with the branches that were picked in the review, and
	 * say so. The choices are GEDCOM xrefs, which only become pages while the
	 * import runs: a person left out of the selection never gets one, and no tree
	 * is grown from them.
	 *
	 * @param string $xrefs  The people the trees are grown from, comma separated.
	 * @param array  $id_map GEDCOM xref => post ID, from the import that just ran.
	 * @return string What to add to the notice, empty when nothing was added.
	 */
	private function front_page_tree_notice( $xrefs, $id_map ) {
		$roots = array();
		foreach ( array_filter( explode( ',', (string) $xrefs ) ) as $xref ) {
			if ( ! empty( $id_map[ $xref ] ) ) {
				$roots[] = $id_map[ $xref ];
			}
		}

		$added = Front_Page::add_trees( $roots );
		if ( ! $added ) {
			return '';
		}

		$names = array_map( 'get_the_title', $added );

		return ' ' . sprintf(
			// translators: %s is a list of people's names.
			_n(
				'The family tree of %s is now on the front page.',
				'The family trees of %s are now on the front page.',
				count( $names ),
				'family-wiki'
			),
			wp_sprintf( '%l', $names )
		);
	}

	/**
	 * The GEDCOM xref to write for each person.
	 *
	 * A person who arrived in an earlier import keeps the xref they came with.
	 * Handing out fresh ones instead would mean this plugin's own export no
	 * longer round-trips: on the way back in, an entry whose xref matches
	 * nobody falls through to matching by name, and a person already carrying a
	 * different xref is deliberately not matched by name — so every previously
	 * imported person would be created a second time.
	 *
	 * Shared with export_content_string(), so a person gets the same xref
	 * in both files.
	 *
	 * @param \WP_Post[] $people People being exported.
	 * @return array Post ID => xref.
	 */
	private function export_xrefs( $people ) {
		$ids   = array();
		$taken = array();

		foreach ( $people as $person ) {
			$xref = preg_replace( '/[^A-Za-z0-9_.-]/', '', (string) get_post_meta( $person->ID, self::XREF_META, true ) );
			$xref = substr( $xref, 0, 20 );

			if ( '' === $xref || isset( $taken[ $xref ] ) ) {
				continue;
			}

			$ids[ $person->ID ] = $xref;
			$taken[ $xref ]     = true;
		}

		$i = 1;
		foreach ( $people as $person ) {
			if ( isset( $ids[ $person->ID ] ) ) {
				continue;
			}

			do {
				$xref = 'I' . $i++;
			} while ( isset( $taken[ $xref ] ) );

			$ids[ $person->ID ] = $xref;
			$taken[ $xref ]     = true;
		}

		return $ids;
	}

	private function get_export_people() {
		return array_values( array_filter( Person::get_all(), array( $this, 'has_person_data' ) ) );
	}

	private function has_person_data( $person ) {
		foreach ( array( 'sex', 'born_as', 'birth_date', 'birth_place', 'death_date', 'death_place', 'father', 'mother', 'children', 'marriages', 'spouse', 'spouse_name', 'marriage_date', 'marriage_place' ) as $field ) {
			$value = $this->get_field_value( $field, $person->ID );
			if ( ! empty( $value ) ) {
				return true;
			}
		}

		return false;
	}

	private function export_individual( $person, $xref, $families ) {
		$lines = array(
			'0 @' . $xref . '@ INDI',
			'1 NAME ' . $this->format_gedcom_name( get_the_title( $person ) ),
		);

		$sex = $this->get_field_value( 'sex', $person->ID );
		if ( $sex ) {
			$lines[] = '1 SEX ' . $this->format_sex( $sex );
		}
		$lines = array_merge( $lines, $this->export_event( 'BIRT', $this->get_field_value( 'birth_date', $person->ID ), $this->get_field_value( 'birth_place', $person->ID ), $this->get_field_value( 'exact_birth_date_unknown', $person->ID ) ) );
		$lines = array_merge( $lines, $this->export_event( 'DEAT', $this->get_field_value( 'death_date', $person->ID ), $this->get_field_value( 'death_place', $person->ID ), $this->get_field_value( 'exact_death_date_unknown', $person->ID ) ) );

		foreach ( $families as $family ) {
			if ( in_array( $person->ID, $family['children'], true ) ) {
				$lines[] = '1 FAMC @' . $family['xref_key'] . '@';
			}
			if ( $person->ID === $family['husband'] || $person->ID === $family['wife'] ) {
				$lines[] = '1 FAMS @' . $family['xref_key'] . '@';
			}
		}

		return $lines;
	}

	private function export_family( $family ) {
		$lines = array( '0 @' . $family['xref'] . '@ FAM' );
		if ( $family['husband'] ) {
			$lines[] = '1 HUSB @' . $family['person_xrefs'][ $family['husband'] ] . '@';
		}
		if ( $family['wife'] ) {
			$lines[] = '1 WIFE @' . $family['person_xrefs'][ $family['wife'] ] . '@';
		}
		foreach ( $family['children'] as $child_id ) {
			$lines[] = '1 CHIL @' . $family['person_xrefs'][ $child_id ] . '@';
		}
		if ( $family['marriage_date'] || $family['marriage_place'] ) {
			$lines = array_merge( $lines, $this->export_event( 'MARR', $family['marriage_date'], $family['marriage_place'], false ) );
		}

		return $lines;
	}

	private function get_export_families( $people, $ids ) {
		$families = array();
		foreach ( $people as $person ) {
			$father = $this->post_id_from_field( $this->get_field_value( 'father', $person->ID ) );
			$mother = $this->post_id_from_field( $this->get_field_value( 'mother', $person->ID ) );
			if ( $father || $mother ) {
				// The same key a marriage gets, so that a couple who are both
				// married and recorded as a child's parents produce one family
				// record rather than two: split across two records, a re-import
				// would overwrite the dated marriage with the undated one.
				$key = $this->family_key_for_couple( $father, $mother );
				if ( empty( $families[ $key ] ) ) {
					$families[ $key ] = $this->empty_family( $father, $mother, $ids );
				}
				$families[ $key ]['children'][ $person->ID ] = $person->ID;
			}

			$marriages = $this->get_field_value( 'marriages', $person->ID );
			if ( is_array( $marriages ) ) {
				foreach ( $marriages as $marriage ) {
					if ( empty( $marriage['spouse'] ) ) {
						continue;
					}
					$spouse = absint( $marriage['spouse'] );
					if ( empty( $ids[ $spouse ] ) ) {
						continue;
					}
					$key = $this->family_key_for_couple( $person->ID, $spouse );
					if ( empty( $families[ $key ] ) ) {
						$families[ $key ] = $this->empty_family_for_couple( $person->ID, $spouse, $ids );
					}
					if ( ! empty( $marriage['marriage_date'] ) ) {
						$families[ $key ]['marriage_date'] = $marriage['marriage_date'];
					}
					if ( ! empty( $marriage['marriage_place'] ) ) {
						$families[ $key ]['marriage_place'] = $marriage['marriage_place'];
					}
				}
			}
		}

		$i = 1;
		foreach ( $families as $key => $family ) {
			$families[ $key ]['children'] = array_values( $family['children'] );
			$families[ $key ]['xref_key'] = 'F' . $i++;
		}

		return array_values( $families );
	}

	private function empty_family( $father, $mother, $ids ) {
		return array(
			'husband'        => isset( $ids[ $father ] ) ? $father : 0,
			'wife'           => isset( $ids[ $mother ] ) ? $mother : 0,
			'children'       => array(),
			'marriage_date'  => '',
			'marriage_place' => '',
			'person_xrefs'   => $ids,
		);
	}

	private function empty_family_for_couple( $person_id, $spouse_id, $ids ) {
		$person_sex = $this->get_field_value( 'sex', $person_id );
		$spouse_sex = $this->get_field_value( 'sex', $spouse_id );
		$husband    = 0;
		$wife       = 0;
		if ( 'Female' === $person_sex || 'Male' === $spouse_sex ) {
			$wife    = $person_id;
			$husband = $spouse_id;
		} else {
			$husband = $person_id;
			$wife    = $spouse_id;
		}

		return $this->empty_family( $husband, $wife, $ids );
	}

	private function export_event( $tag, $date, $place, $approximate ) {
		$lines = array();
		if ( ! $date && ! $place ) {
			return $lines;
		}

		$lines[] = '1 ' . $tag;
		if ( $date ) {
			$lines[] = '2 DATE ' . ( $approximate ? 'ABT ' : '' ) . $this->format_gedcom_date( $date );
		}
		if ( $place ) {
			$lines[] = '2 PLAC ' . $this->clean_gedcom_value( $place );
		}

		return $lines;
	}

	private function review_people( $records ) {
		$descendants = $this->descendants_by_person( empty( $records['FAM'] ) ? array() : $records['FAM'] );
		$existing    = $this->existing_page_index();
		$names       = array();
		$matches     = array();

		// Which entries would land on a page that already exists. Resolved with
		// the function the import uses, in the order the import runs, so the
		// screen promises exactly what will happen: where two people share a
		// name, only the first is shown as updating a page.
		$claimed = array();
		foreach ( $records['INDI'] as $xref => $record ) {
			$name           = $this->gedcom_name_to_title( $this->first_value( $record, 'NAME' ) );
			$names[ $xref ] = $name;

			$post_id = $this->find_person_post( $xref, $name, $existing, $claimed, $this->gedcom_birth_year( $record ) );
			if ( $post_id ) {
				$matches[ $xref ]  = $post_id;
				$claimed[ $post_id ] = true;
			}
		}

		$people = array();
		foreach ( $records['INDI'] as $xref => $record ) {
			$birth   = $this->event_values( $record, 'BIRT' );
			$death   = $this->event_values( $record, 'DEAT' );
			$subtree = isset( $descendants[ $xref ] ) ? $descendants[ $xref ] : array();

			// How much of this person's subtree is already on the wiki. This is
			// what tells a branch worth importing from one that is only distantly
			// related, which a plain descendant count does not.
			$hits = isset( $matches[ $xref ] ) ? 1 : 0;
			foreach ( $subtree as $descendant ) {
				if ( isset( $matches[ $descendant ] ) ) {
					++$hits;
				}
			}

			$people[] = array(
				'xref'        => $xref,
				'name'        => $names[ $xref ],
				'birth'       => $this->review_event_label( $birth ),
				'death'       => $this->review_event_label( $death ),
				'birth_year'  => $this->gedcom_year( $birth ),
				'death_year'  => $this->gedcom_year( $death ),
				'descendants' => $subtree,
				'count'       => count( $subtree ),
				'wiki_hits'   => $hits,
				'match_id'    => isset( $matches[ $xref ] ) ? $matches[ $xref ] : 0,
				'match_trash' => isset( $matches[ $xref ] ) && ! empty( $existing['trashed'][ $matches[ $xref ] ] ),
			);
		}

		usort( $people, array( $this, 'sort_review_people' ) );

		return $people;
	}

	/**
	 * The shape of the file, small enough to hand to the browser: the tree of
	 * selected people is drawn there and redrawn on every tick, so it needs the
	 * links between people without another round trip. Names and years only —
	 * the table above it already carries the detail.
	 */
	private function review_tree_data( $families, $people ) {
		list( $children_by_parent, $partners_by_person ) = $this->family_links( $families );

		$data = array();
		foreach ( $people as $person ) {
			$xref  = $person['xref'];
			$entry = array( 'name' => $person['name'] );

			if ( $person['birth_year'] ) {
				$entry['birth'] = $person['birth_year'];
			}
			if ( $person['death_year'] ) {
				$entry['death'] = $person['death_year'];
			}
			if ( ! empty( $children_by_parent[ $xref ] ) ) {
				$entry['children'] = array_values( $children_by_parent[ $xref ] );
			}
			if ( ! empty( $partners_by_person[ $xref ] ) ) {
				$entry['partners'] = array_values( $partners_by_person[ $xref ] );
			}
			// Said on the line, because a page coming back out of the trash is
			// not what pressing import usually does.
			if ( $person['match_trash'] ) {
				$entry['trashed'] = true;
			}

			$data[ $xref ] = $entry;
		}

		return $data;
	}

	/**
	 * Pages that a GEDCOM entry could land on, looked up the same way the
	 * import does: by a previously stored xref first, then by title.
	 */
	private function existing_page_index() {
		$index = array(
			'xref'    => array(),
			'title'   => array(),
			'trashed' => array(),
		);

		$pages = get_posts(
			array(
				'post_type'      => Person::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'private', 'trash' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		foreach ( $pages as $page_id ) {
			$trashed = 'trash' === get_post_status( $page_id );

			/*
			 * A page in the trash is only ever claimed back by its xref, which is
			 * this file saying the page is this person. Matching one by name would
			 * let an entry that happens to share a name with something somebody
			 * deleted pull it out of the trash under a new life.
			 */
			$title = $trashed ? '' : strtolower( trim( get_the_title( $page_id ) ) );
			// Every page with this title, not just the first: several people in a
			// GEDCOM commonly share a name, and each needs its own page.
			if ( $title ) {
				$index['title'][ $title ][] = $page_id;
			}

			$xref = get_post_meta( $page_id, self::XREF_META, true );
			if ( ! $xref ) {
				continue;
			}

			// Where a trashed page and a live one carry the same xref, the one
			// still on the wiki is the person: the other is a copy left behind.
			if ( $trashed && isset( $index['xref'][ $xref ] ) ) {
				continue;
			}

			$index['xref'][ $xref ] = $page_id;
			if ( $trashed ) {
				$index['trashed'][ $page_id ] = true;
			}
		}

		return $index;
	}

	private function sort_review_people( $a, $b ) {
		// Entries that reach the existing wiki first, then the tighter subtree:
		// a small branch that is mostly already here beats a huge distant one.
		if ( $a['wiki_hits'] !== $b['wiki_hits'] ) {
			return $b['wiki_hits'] - $a['wiki_hits'];
		}

		if ( $a['wiki_hits'] && $a['count'] !== $b['count'] ) {
			return $a['count'] - $b['count'];
		}

		return strcasecmp( $a['name'], $b['name'] );
	}

	/**
	 * Who each person's children and partners are, read off the family records.
	 */
	private function family_links( $families ) {
		$children_by_parent = array();
		$partners_by_person = array();

		foreach ( $families as $family ) {
			$parents = array_filter(
				array(
					$this->first_pointer( $family, 'HUSB' ),
					$this->first_pointer( $family, 'WIFE' ),
				)
			);
			$children = $this->all_pointers( $family, 'CHIL' );
			foreach ( $parents as $parent ) {
				foreach ( $children as $child ) {
					$children_by_parent[ $parent ][ $child ] = $child;
				}
				foreach ( $parents as $other ) {
					if ( $other !== $parent ) {
						$partners_by_person[ $parent ][ $other ] = $other;
					}
				}
			}
		}

		return array( $children_by_parent, $partners_by_person );
	}

	private function descendants_by_person( $families ) {
		list( $children_by_parent, $partners_by_person ) = $this->family_links( $families );

		$descendants = array();
		foreach ( array_keys( $children_by_parent ) as $xref ) {
			$line = $this->collect_descendants( $xref, $children_by_parent );

			// Take the people they married along. A descendant line without the
			// spouses is half a family tree: every couple would show one partner
			// and a blank where the other belongs. Their ancestors are left out,
			// so this brings in the spouse and not their whole family as well.
			foreach ( array_merge( array( $xref ), array_keys( $line ) ) as $person ) {
				if ( empty( $partners_by_person[ $person ] ) ) {
					continue;
				}

				foreach ( $partners_by_person[ $person ] as $partner ) {
					if ( $partner !== $xref ) {
						$line[ $partner ] = $partner;
					}
				}
			}

			$descendants[ $xref ] = array_values( $line );
		}

		return $descendants;
	}

	private function collect_descendants( $xref, $children_by_parent, $seen = array() ) {
		if ( empty( $children_by_parent[ $xref ] ) ) {
			return $seen;
		}

		foreach ( $children_by_parent[ $xref ] as $child ) {
			if ( isset( $seen[ $child ] ) ) {
				continue;
			}
			$seen[ $child ] = $child;
			$seen = $this->collect_descendants( $child, $children_by_parent, $seen );
		}

		return $seen;
	}

	private function review_event_label( $event ) {
		$parts = array();
		if ( ! empty( $event['date'] ) ) {
			$parts[] = $event['approximate'] ? sprintf(
				// translators: %s is a date.
				__( 'about %s', 'family-wiki' ),
				$event['date']
			) : $event['date'];
		}
		if ( ! empty( $event['place'] ) ) {
			$parts[] = $event['place'];
		}

		return implode( ', ', $parts );
	}

	private function parse_records( $contents ) {
		$contents = str_replace( array( "\r\n", "\r" ), "\n", $contents );
		$lines    = explode( "\n", $contents );
		$records  = array();
		$current  = null;

		foreach ( $lines as $line ) {
			if ( ! preg_match( '/^(\d+)\s+(?:(@[^@]+@)\s+)?([A-Za-z0-9_]+)(?:\s+(.*))?$/', trim( $line ), $matches ) ) {
				continue;
			}

			$entry = array(
				'level' => (int) $matches[1],
				'tag'   => strtoupper( $matches[3] ),
				'value' => isset( $matches[4] ) ? $matches[4] : '',
			);

			if ( 0 === $entry['level'] && ! empty( $matches[2] ) ) {
				$current = array(
					'xref'  => trim( $matches[2], '@' ),
					'tag'   => $entry['tag'],
					'lines' => array(),
				);
				if ( empty( $records[ $current['tag'] ] ) ) {
					$records[ $current['tag'] ] = array();
				}
				$records[ $current['tag'] ][ $current['xref'] ] = array();
				continue;
			}

			if ( $current && $entry['level'] > 0 ) {
				$records[ $current['tag'] ][ $current['xref'] ][] = $entry;
			}
		}

		return $records;
	}

	private function import_individual_fields( $post_id, $record ) {
		$name = $this->gedcom_name_to_title( $this->first_value( $record, 'NAME' ) );
		if ( $name ) {
			$this->update_field( 'born_as', $name, $post_id );
		}

		$sex = $this->first_value( $record, 'SEX' );
		if ( $sex ) {
			$this->update_field( 'sex', $this->import_sex( $sex ), $post_id );
		}

		$birth = $this->event_values( $record, 'BIRT' );
		if ( $birth['date'] ) {
			$this->update_field( 'birth_date', $birth['date'], $post_id );
			$this->update_field( 'exact_birth_date_unknown', $birth['approximate'] ? 1 : 0, $post_id );
		}
		if ( $birth['place'] ) {
			$this->update_field( 'birth_place', $birth['place'], $post_id );
		}

		$death = $this->event_values( $record, 'DEAT' );
		if ( $death['date'] ) {
			$this->update_field( 'death_date', $death['date'], $post_id );
			$this->update_field( 'exact_death_date_unknown', $death['approximate'] ? 1 : 0, $post_id );
			$this->update_field( 'alive', 0, $post_id );
		} elseif ( $this->has_level_one_tag( $record, 'DEAT' ) ) {
			$this->update_field( 'alive', 0, $post_id );
		} else {
			$this->update_field( 'alive', 1, $post_id );
		}
		if ( $death['place'] ) {
			$this->update_field( 'death_place', $death['place'], $post_id );
		}
	}

	/**
	 * Create or update one person from their GEDCOM record.
	 *
	 * Both ways of importing go through here: the plain form post walks the file
	 * in one go, the app walks it a batch at a time, and neither wants its own
	 * idea of what importing a person means.
	 *
	 * @param string $xref    The person's GEDCOM xref.
	 * @param array  $record  Their parsed record.
	 * @param array  $index   Index of the people already on the wiki, as it stood
	 *                        when the import started. Not updated as people are
	 *                        created: a page written by this import is not a page
	 *                        a later entry should be matched against.
	 * @param array  $claimed Post IDs this import has already used, by reference.
	 * @return array|\WP_Error The post ID and whether it was already there.
	 */
	private function import_person( $xref, $record, $index, &$claimed ) {
		$title   = $this->gedcom_name_to_title( $this->first_value( $record, 'NAME' ) );
		$post_id = $this->find_person_post( $xref, $title, $index, $claimed, $this->gedcom_birth_year( $record ) );
		$existed = (bool) $post_id;
		$data    = array(
			'post_type'    => Person::POST_TYPE,
			'post_status'  => 'publish',
			'post_title'   => $title ? $title : $xref,
			'post_content' => '',
		);

		if ( $existed ) {
			/*
			 * A page this person was already on, in the trash. It comes back
			 * rather than being written a second time: the xref on it says it is
			 * this person, and everything pointing at it — the relations of
			 * people not in this file, links, the front page — is pointing at
			 * this page and not at a copy of it. Untrashing first is what gives
			 * the slug back, which the trash keeps hold of.
			 */
			if ( ! empty( $index['trashed'][ $post_id ] ) ) {
				wp_untrash_post( $post_id );
			}

			$data['ID'] = $post_id;
			unset( $data['post_content'] );
			$result = wp_update_post( wp_slash( $data ), true );
		} else {
			$result = wp_insert_post( wp_slash( $data ), true );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! $existed ) {
			$post_id = $result;
		}

		$claimed[ $post_id ] = true;
		update_post_meta( $post_id, self::XREF_META, $xref );
		$this->import_individual_fields( $post_id, $record );

		return array(
			'id'      => (int) $post_id,
			'existed' => $existed,
		);
	}

	private function import_family_links( $families, $id_map ) {
		$children_by_parent = array();
		$marriages_by_person = array();

		foreach ( $families as $family ) {
			$husband = $this->xref_post_id( $this->first_pointer( $family, 'HUSB' ), $id_map );
			$wife    = $this->xref_post_id( $this->first_pointer( $family, 'WIFE' ), $id_map );
			$event   = $this->event_values( $family, 'MARR' );
			$children = $this->all_pointers( $family, 'CHIL' );

			foreach ( $children as $child_xref ) {
				$child = $this->xref_post_id( $child_xref, $id_map );
				if ( ! $child ) {
					continue;
				}
				if ( $husband ) {
					$this->update_field( 'father', $husband, $child );
					$children_by_parent[ $husband ][ $child ] = $child;
				}
				if ( $wife ) {
					$this->update_field( 'mother', $wife, $child );
					$children_by_parent[ $wife ][ $child ] = $child;
				}
			}

			if ( $husband && $wife ) {
				$marriage = array(
					'spouse'         => $wife,
					'spouse_name'    => '',
					'marriage_date'  => $event['date'],
					'marriage_year'  => '',
					'marriage_place' => $event['place'],
					'ended_date'     => '',
					'ended_year'     => '',
					'ended_reason'   => '',
				);
				$marriages_by_person[ $husband ][ $wife ] = $marriage;
				$marriage['spouse'] = $husband;
				$marriages_by_person[ $wife ][ $husband ] = $marriage;
			}
		}

		foreach ( $children_by_parent as $parent_id => $children ) {
			$this->update_field( 'children', $this->merge_post_ids( $this->get_field_value( 'children', $parent_id ), array_values( $children ) ), $parent_id );
		}
		foreach ( $marriages_by_person as $person_id => $marriages ) {
			$this->update_field( 'marriages', $this->merge_marriages( $this->get_field_value( 'marriages', $person_id ), array_values( $marriages ) ), $person_id );
		}
	}

	private function merge_post_ids( $existing, $incoming ) {
		$merged = array();
		if ( ! is_array( $existing ) ) {
			$existing = $existing ? array( $existing ) : array();
		}
		foreach ( $existing as $value ) {
			$post_id = $this->post_id_from_field( $value );
			if ( $post_id ) {
				$merged[ $post_id ] = $post_id;
			}
		}
		foreach ( $incoming as $value ) {
			$post_id = $this->post_id_from_field( $value );
			if ( $post_id ) {
				$merged[ $post_id ] = $post_id;
			}
		}

		return array_values( $merged );
	}

	private function merge_marriages( $existing, $incoming ) {
		$merged = array();
		if ( is_array( $existing ) ) {
			foreach ( $existing as $marriage ) {
				if ( ! is_array( $marriage ) ) {
					continue;
				}
				$spouse = isset( $marriage['spouse'] ) ? $this->post_id_from_field( $marriage['spouse'] ) : 0;
				$key    = $spouse ? 'spouse:' . $spouse : 'name:' . ( isset( $marriage['spouse_name'] ) ? $marriage['spouse_name'] : count( $merged ) );
				$merged[ $key ] = $marriage;
			}
		}
		foreach ( $incoming as $marriage ) {
			if ( empty( $marriage['spouse'] ) ) {
				continue;
			}
			$merged[ 'spouse:' . absint( $marriage['spouse'] ) ] = $marriage;
		}

		return array_values( $merged );
	}

	private function first_value( $record, $tag ) {
		foreach ( $record as $line ) {
			if ( $tag === $line['tag'] ) {
				return $line['value'];
			}
		}

		return '';
	}

	private function has_level_one_tag( $record, $tag ) {
		foreach ( $record as $line ) {
			if ( 1 === $line['level'] && $tag === $line['tag'] ) {
				return true;
			}
		}

		return false;
	}

	private function first_pointer( $record, $tag ) {
		return trim( $this->first_value( $record, $tag ), '@' );
	}

	private function all_pointers( $record, $tag ) {
		$values = array();
		foreach ( $record as $line ) {
			if ( $tag === $line['tag'] ) {
				$values[] = trim( $line['value'], '@' );
			}
		}

		return $values;
	}

	private function event_values( $record, $tag ) {
		$event = array(
			'date'        => '',
			'place'       => '',
			'approximate' => false,
		);
		$in_event = false;
		foreach ( $record as $line ) {
			if ( 1 === $line['level'] ) {
				$in_event = $tag === $line['tag'];
				continue;
			}
			if ( ! $in_event || 2 !== $line['level'] ) {
				continue;
			}
			if ( 'DATE' === $line['tag'] ) {
				$parsed = $this->parse_gedcom_date( $line['value'] );
				$event['date']        = $parsed['date'];
				$event['approximate'] = $parsed['approximate'];
			} elseif ( 'PLAC' === $line['tag'] ) {
				$event['place'] = sanitize_text_field( $line['value'] );
			}
		}

		return $event;
	}

	private function parse_gedcom_date( $date ) {
		$date        = trim( strtoupper( $date ) );
		$approximate = false;
		if ( preg_match( '/^(ABT|ABOUT|CAL|EST)\s+(.+)$/', $date, $matches ) ) {
			$approximate = true;
			$date        = $matches[2];
		}

		$months = array_flip( $this->gedcom_months() );
		if ( preg_match( '/^(\d{1,2})\s+([A-Z]{3})\s+(\d{3,4})$/', $date, $matches ) && isset( $months[ $matches[2] ] ) ) {
			return array(
				'date'        => sprintf( '%04d-%02d-%02d', $matches[3], $months[ $matches[2] ], $matches[1] ),
				'approximate' => $approximate,
			);
		}
		if ( preg_match( '/^([A-Z]{3})\s+(\d{3,4})$/', $date, $matches ) && isset( $months[ $matches[1] ] ) ) {
			return array(
				'date'        => sprintf( '%04d-%02d-01', $matches[2], $months[ $matches[1] ] ),
				'approximate' => true,
			);
		}
		if ( preg_match( '/^(\d{3,4})$/', $date, $matches ) ) {
			return array(
				'date'        => sprintf( '%04d-01-01', $matches[1] ),
				'approximate' => true,
			);
		}

		return array(
			'date'        => '',
			'approximate' => $approximate,
		);
	}

	/**
	 * The page a GEDCOM entry belongs on, or 0 to create one.
	 *
	 * A stored xref is definitive. Falling back to the title is a guess, so it
	 * only accepts a page that no other entry has a stronger claim to: one that
	 * carries a different xref belongs to a different person, and one already
	 * taken during this import would otherwise be overwritten by every namesake
	 * that follows.
	 *
	 * Where a birth year is known on both sides it has to agree, so that the
	 * page for one Alexander Kirk is not overwritten by another one who merely
	 * shares the name.
	 *
	 * @param string $xref       The GEDCOM xref.
	 * @param string $title      The person's name.
	 * @param array  $index      Existing pages, from existing_page_index().
	 * @param array  $claimed    Page IDs already taken in this run, keyed by ID.
	 * @param string $birth_year The person's birth year, if known.
	 */
	private function find_person_post( $xref, $title, $index, $claimed = array(), $birth_year = '' ) {
		if ( isset( $index['xref'][ $xref ] ) ) {
			return (int) $index['xref'][ $xref ];
		}

		$key = strtolower( trim( (string) $title ) );
		if ( '' === $key || empty( $index['title'][ $key ] ) ) {
			return 0;
		}

		foreach ( $index['title'][ $key ] as $page_id ) {
			if ( isset( $claimed[ $page_id ] ) ) {
				continue;
			}

			$stored = get_post_meta( $page_id, self::XREF_META, true );
			if ( $stored && $stored !== $xref ) {
				continue;
			}

			if ( $birth_year ) {
				$page_year = substr( preg_replace( '/\D/', '', (string) get_post_meta( $page_id, 'birth_date', true ) ), 0, 4 );
				if ( $page_year && $page_year !== $birth_year ) {
					continue;
				}
			}

			return (int) $page_id;
		}

		return 0;
	}

	private function gedcom_birth_year( $record ) {
		return $this->gedcom_year( $this->event_values( $record, 'BIRT' ) );
	}

	private function gedcom_year( $event ) {
		return empty( $event['date'] ) ? '' : substr( preg_replace( '/\D/', '', $event['date'] ), 0, 4 );
	}

	private function get_field_value( $field, $post_id ) {
		return Person::field( $field, $post_id );
	}

	private function update_field( $field, $value, $post_id ) {
		Person::update( $field, $value, $post_id );
	}

	private function post_id_from_field( $value ) {
		if ( $value instanceof \WP_Post ) {
			return (int) $value->ID;
		}
		if ( is_numeric( $value ) ) {
			return (int) $value;
		}

		return 0;
	}

	private function xref_post_id( $xref, $id_map ) {
		return $xref && isset( $id_map[ $xref ] ) ? $id_map[ $xref ] : 0;
	}

	private function gedcom_name_to_title( $name ) {
		$name = str_replace( '/', '', $name );
		return trim( preg_replace( '/\s+/', ' ', $name ) );
	}

	private function format_gedcom_name( $name ) {
		$name  = $this->clean_gedcom_value( $name );
		$parts = preg_split( '/\s+/', $name );
		if ( count( $parts ) < 2 ) {
			return $name;
		}
		$surname = array_pop( $parts );

		return implode( ' ', $parts ) . ' /' . $surname . '/';
	}

	private function format_sex( $sex ) {
		if ( 'Male' === $sex ) {
			return 'M';
		}
		if ( 'Female' === $sex ) {
			return 'F';
		}

		return 'U';
	}

	private function import_sex( $sex ) {
		$sex = strtoupper( trim( $sex ) );
		if ( 'M' === $sex ) {
			return 'Male';
		}
		if ( 'F' === $sex ) {
			return 'Female';
		}

		return 'Unknown';
	}

	private function format_gedcom_date( $date ) {
		$date = trim( (string) $date );
		if ( preg_match( '/^(\d{4})-?(\d{2})-?(\d{2})$/', $date, $matches ) ) {
			$months = $this->gedcom_months();
			return (int) $matches[3] . ' ' . $months[ (int) $matches[2] ] . ' ' . $matches[1];
		}
		if ( preg_match( '/^\d{4}$/', $date ) ) {
			return $date;
		}

		return $this->clean_gedcom_value( $date );
	}

	private function gedcom_months() {
		return array(
			1  => 'JAN',
			2  => 'FEB',
			3  => 'MAR',
			4  => 'APR',
			5  => 'MAY',
			6  => 'JUN',
			7  => 'JUL',
			8  => 'AUG',
			9  => 'SEP',
			10 => 'OCT',
			11 => 'NOV',
			12 => 'DEC',
		);
	}

	private function clean_gedcom_value( $value ) {
		return trim( preg_replace( '/[\r\n]+/', ' ', wp_strip_all_tags( (string) $value ) ) );
	}

	private function family_key_for_couple( $person_id, $spouse_id ) {
		$ids = array( (int) $person_id, (int) $spouse_id );
		sort( $ids );

		return 'm:' . implode( ':', $ids );
	}

	private function error_message( $error ) {
		$messages = array(
			'missing_file'   => __( 'Please choose a GEDCOM file to import.', 'family-wiki' ),
			'file_too_large' => sprintf(
				// translators: %s is a file size, for example 2 MB.
				__( 'The GEDCOM file is larger than the maximum upload size of %s.', 'family-wiki' ),
				size_format( wp_max_upload_size() )
			),
			'upload_failed'  => __( 'The GEDCOM file could not be uploaded.', 'family-wiki' ),
			'store_failed'   => __( 'The GEDCOM file could not be stored for review.', 'family-wiki' ),
			'empty_file'     => __( 'The uploaded GEDCOM file was empty.', 'family-wiki' ),
			'no_individuals' => __( 'The GEDCOM file does not contain individual records.', 'family-wiki' ),
			'no_selection'   => __( 'Please select at least one GEDCOM person to import.', 'family-wiki' ),
			'review_expired' => __( 'The GEDCOM import review expired. Please upload the file again.', 'family-wiki' ),
		);

		return isset( $messages[ $error ] ) ? $messages[ $error ] : __( 'The GEDCOM import failed.', 'family-wiki' );
	}

	/*
	 * ------------------------------------------------------------------
	 * The content file: the text written on a person's page, which GEDCOM
	 * has no room for. It always rides along with a GEDCOM file, uploaded
	 * in the same form and applied to each person right after the person
	 * themselves, matched by the same xref. It only ever updates a person
	 * that already exists; it never creates one.
	 * ------------------------------------------------------------------
	 */

	/**
	 * Grouped with the GEDCOM download button, not a section of its own.
	 */
	private function render_content_export_button() {
		?>
		<p><?php esc_html_e( 'The content file carries the page text GEDCOM has no room for, including any additional pages under a person.', 'family-wiki' ); ?></p>
		<form class="familypedia-download-form" method="post" action="<?php echo esc_url( self::get_page_url() ); ?>">
			<input type="hidden" name="familypedia_action" value="<?php echo esc_attr( self::CONTENT_EXPORT_ACTION ); ?>" />
			<?php wp_nonce_field( self::CONTENT_EXPORT_ACTION ); ?>
			<button type="submit" class="familypedia-button familypedia-button--primary"><?php esc_html_e( 'Download Content', 'family-wiki' ); ?></button>
			<span class="familypedia-download-check" aria-hidden="true" hidden>&#10003;</span>
		</form>
		<?php
	}

	/**
	 * The optional content field inside the GEDCOM upload form, for
	 * importing both together in one step. Whether to also download
	 * images is asked on the review screen instead, right where the
	 * import itself starts, rather than here.
	 */
	private function render_content_field() {
		?>
		<p>
			<label>
				<?php esc_html_e( 'Content file (optional)', 'family-wiki' ); ?><br />
				<input type="file" name="content" accept=".xml,text/xml" />
			</label>
		</p>
		<?php
	}

	/**
	 * A content file's items keyed by its people's xref, and whether it
	 * asked to download images — parsed once per request no matter how many
	 * people ask for it during a batch.
	 *
	 * @return array|false array( 'by_xref' => [...], 'download_images' => bool ), or false for a token with no content file.
	 */
	private function content_index( $token ) {
		if ( array_key_exists( $token, $this->content_index_cache ) ) {
			return $this->content_index_cache[ $token ];
		}

		$contents = $this->get_content_file( $token );
		$xml      = false === $contents ? false : $this->parse_content_xml( $contents );

		if ( false === $contents || is_wp_error( $xml ) ) {
			$this->content_index_cache[ $token ] = false;
			return false;
		}

		$by_xref        = array();
		$by_related     = array();
		$by_related_key = array();

		foreach ( $xml->channel->item as $item ) {
			$xref = $this->item_meta( $item, self::CONTENT_META_KEY );
			if ( $xref ) {
				$by_xref[ $xref ] = $item;
				continue;
			}

			$related_of = $this->item_meta( $item, self::CONTENT_RELATED_META_KEY );
			if ( $related_of ) {
				$by_related[ $related_of ][] = $item;
				$by_related_key[ 'R:' . $related_of . ':' . $this->related_item_slug( $item ) ] = $item;
			}
		}

		$this->content_index_cache[ $token ] = array(
			'by_xref'         => $by_xref,
			'by_related'      => $by_related,
			'by_related_key'  => $by_related_key,
			'download_images' => $this->content_download_images_requested( $token ),
		);

		return $this->content_index_cache[ $token ];
	}

	/**
	 * One <wp:postmeta> value from an item, by its meta key. Only meta
	 * keys with a single value make sense to read this way; a content
	 * link, which an item can carry several of, is read separately by
	 * content_links_for_item().
	 */
	private function item_meta( $item, $meta_key ) {
		$wp_fields = $item->children( 'http://wordpress.org/export/1.2/' );
		foreach ( $wp_fields->postmeta as $meta ) {
			$meta_fields = $meta->children( 'http://wordpress.org/export/1.2/' );
			if ( $meta_key === (string) $meta_fields->meta_key ) {
				return (string) $meta_fields->meta_value;
			}
		}

		return '';
	}

	/**
	 * The slug an additional page's item carries, or one made from its
	 * title when the file has none — found the same way at export and
	 * import, so a page matches itself on the next round trip.
	 */
	private function related_item_slug( $item ) {
		$wp_fields = $item->children( 'http://wordpress.org/export/1.2/' );
		$slug      = isset( $wp_fields->post_name ) ? sanitize_title( (string) $wp_fields->post_name ) : '';

		return '' !== $slug ? $slug : sanitize_title( trim( (string) $item->title ) );
	}

	/**
	 * If this run's token has a content file, give the run somewhere to
	 * keep its counts as people are imported.
	 */
	private function add_content_to_run_state( $state, $token ) {
		if ( ! $this->content_index( $token ) ) {
			return $state;
		}

		$state['content']         = array(
			'updated'        => 0,
			'images'         => 0,
			'pending_images' => array(),
		);
		$state['related_id_map'] = array();
		$state['parent_of']      = array();

		return $state;
	}

	/**
	 * Applies one person's text right after the person themselves, using
	 * the post that was just resolved directly — there is nothing left to
	 * match, since this is the exact page that xref just became. Also
	 * creates or updates any additional page the content file carries
	 * under this same person, and remembers this person's own structural
	 * parent, if the file recorded one, for resolve_related_parents() to
	 * apply once every xref in this run has a post.
	 */
	private function apply_content_to_person( $state, $xref, $post_id ) {
		if ( empty( $state['content'] ) || empty( $state['token'] ) ) {
			return $state;
		}

		$index = $this->content_index( $state['token'] );
		if ( ! $index ) {
			return $state;
		}

		if ( isset( $index['by_xref'][ $xref ] ) ) {
			$item  = $index['by_xref'][ $xref ];
			$tasks = $this->apply_content_text_to_post( $item, $post_id, $index['download_images'] );
			++$state['content']['updated'];
			$state['content']['pending_images'] = array_merge( $state['content']['pending_images'], $tasks );

			$parent_xref = $this->item_meta( $item, self::CONTENT_RELATED_META_KEY );
			if ( $parent_xref ) {
				$state['parent_of'][ $post_id ] = $parent_xref;
			}
		}

		return $this->apply_related_content_to_person( $state, $index, $xref, $post_id );
	}

	/**
	 * Creates or updates one additional page for each item the content
	 * file carries under $parent_key, now that whatever that is — a
	 * person, or another additional page — has a post here to be its
	 * parent. Recurses into each one created, since an additional page
	 * can itself hold further additional pages nested under it.
	 *
	 * @param array  $state          The run's accumulated state.
	 * @param array  $index          content_index()'s result for this token.
	 * @param string $parent_key     The immediate parent's own key: an
	 *                                xref for a person, or an "R:…" key
	 *                                for an additional page.
	 * @param int    $parent_post_id The immediate parent's post.
	 */
	private function apply_related_content_to_person( $state, $index, $parent_key, $parent_post_id ) {
		if ( empty( $index['by_related'][ $parent_key ] ) ) {
			return $state;
		}

		foreach ( $index['by_related'][ $parent_key ] as $item ) {
			$child_id = $this->resolve_related_page( $item, $parent_post_id );
			if ( ! $child_id || is_wp_error( $child_id ) ) {
				continue;
			}

			$tasks = $this->apply_content_text_to_post( $item, $child_id, $index['download_images'] );
			++$state['content']['updated'];
			$state['content']['pending_images'] = array_merge( $state['content']['pending_images'], $tasks );

			$child_key = 'R:' . $parent_key . ':' . $this->related_item_slug( $item );
			$state['related_id_map'][ $child_key ] = $child_id;

			$state = $this->apply_related_content_to_person( $state, $index, $child_key, $child_id );
		}

		return $state;
	}

	/**
	 * The post an additional-page item belongs on: an existing one under
	 * the same parent with the same slug, so a re-import updates rather
	 * than duplicates, or a newly created one.
	 *
	 * @return int|\WP_Error Post ID, or an error from wp_insert_post().
	 */
	private function resolve_related_page( $item, $parent_post_id ) {
		$title = trim( (string) $item->title );
		$slug  = $this->related_item_slug( $item );

		$existing = get_posts(
			array(
				'post_type'      => Person::POST_TYPE,
				'post_parent'    => $parent_post_id,
				'name'           => $slug,
				'post_status'    => array( 'publish', 'draft', 'private', 'trash' ),
				'posts_per_page' => 1,
			)
		);

		if ( $existing ) {
			$post_id = (int) $existing[0]->ID;
			wp_update_post(
				wp_slash(
					array(
						'ID'          => $post_id,
						'post_title'  => $title,
						'post_status' => 'publish',
					)
				)
			);

			return $post_id;
		}

		return wp_insert_post(
			wp_slash(
				array(
					'post_type'   => Person::POST_TYPE,
					'post_parent' => $parent_post_id,
					'post_title'  => $title,
					'post_name'   => $slug,
					'post_status' => 'publish',
				)
			),
			true
		);
	}

	private function content_summary_message( $updated, $images ) {
		$message = ' ' . sprintf(
			// translators: %d is a number of people whose text was applied.
			__( 'Applied text from the content file to %d of them.', 'family-wiki' ),
			$updated
		);

		if ( $images ) {
			$message .= ' ' . sprintf(
				// translators: %d is a number of downloaded images.
				__( 'Downloaded %d images into the media library.', 'family-wiki' ),
				$images
			);
		}

		return $message;
	}

	/**
	 * The no-JS path has no per-person step for a content file to ride
	 * along with, so it goes in as one whole-file pass instead, matched by
	 * xref or title the same way import_string() matches GEDCOM entries.
	 * Called while the file is still parked, before delete_import_file().
	 *
	 * @param string $message The notice being built up.
	 * @param string $token   The review token this import used.
	 * @param array  $id_map  Xref => post ID, from this same import_string() call.
	 */
	private function add_content_to_message( $message, $token, $id_map ) {
		$contents = $this->get_content_file( $token );
		if ( false === $contents ) {
			return $message;
		}

		$result = $this->apply_content( $contents, $this->content_download_images_requested( $token ) );
		if ( is_wp_error( $result ) ) {
			return $message;
		}

		$this->resolve_content_links( $token, $id_map, $result['related_id_map'] );
		$this->resolve_related_parents( $id_map, $result['parent_of'] );

		return $message . $this->content_summary_message( $result['updated'], $result['images'] );
	}

	public function export_content_download() {
		if ( ! self::can_export() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to export content.', 'family-wiki' ), 403 );
		}
		check_admin_referer( self::CONTENT_EXPORT_ACTION );

		$filename = sanitize_file_name( wp_parse_url( home_url(), PHP_URL_HOST ) . '-familypedia-content-' . current_time( 'Ymd-His' ) . '.xml' );

		nocache_headers();
		header( 'Content-Type: text/xml; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- This is the body of a text/xml download, not HTML; every value in the document is already wrapped in CDATA by cdata(), and escaping it would corrupt the file.
		echo $this->export_content_string();
		exit;
	}

	private function export_content_string() {
		$people    = $this->get_export_people();
		$ids       = $this->export_xrefs( $people );
		$related   = $this->get_export_related_pages( $people, $ids );
		$link_keys = $ids;
		foreach ( $related as $entry ) {
			$link_keys[ $entry['post']->ID ] = $entry['key'];
		}

		$lines = array(
			'<?xml version="1.0" encoding="UTF-8" ?>',
			'<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:wp="http://wordpress.org/export/1.2/">',
			'<channel>',
		);

		foreach ( $people as $person ) {
			$meta_pairs  = array( self::CONTENT_META_KEY => $ids[ $person->ID ] );
			$parent_xref = $this->exported_parent_xref( $person, $ids );
			if ( $parent_xref ) {
				// A person can have facts of their own — and so a GEDCOM
				// individual of their own — while still sitting under
				// another exported page, the way a page hierarchy can
				// even where father/mother say nothing about it.
				$meta_pairs[ self::CONTENT_RELATED_META_KEY ] = $parent_xref;
			}

			$lines = array_merge( $lines, $this->export_content_item_lines( $person, $link_keys, $meta_pairs ) );
		}

		foreach ( $related as $entry ) {
			$lines = array_merge(
				$lines,
				$this->export_content_item_lines( $entry['post'], $link_keys, array( self::CONTENT_RELATED_META_KEY => $entry['parent_key'] ), $entry['post']->post_name )
			);
		}

		$lines[] = '</channel>';
		$lines[] = '</rss>';

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * One <item>'s worth of lines: a person's own text, or an additional
	 * page's — the two differ only in which postmeta says what the item
	 * is, and whether a slug travels with it for a page that has no xref
	 * of its own to be found by.
	 *
	 * @param \WP_Post $post      The person or additional page being exported.
	 * @param array    $link_keys Post ID => the key a link to it should
	 *                            record, covering people and additional
	 *                            pages alike.
	 * @param array    $meta_pairs Meta key => value to identify this item.
	 * @param string   $post_name  The item's own slug, when it needs one to
	 *                             be found again on re-import.
	 */
	private function export_content_item_lines( $post, $link_keys, $meta_pairs, $post_name = '' ) {
		$links   = array();
		$content = $this->relativize_images( $post->post_content );
		$content = $this->relativize_links( $content, $link_keys, $links );

		$lines = array( '<item>' );
		$lines[] = '<title>' . $this->cdata( get_the_title( $post ) ) . '</title>';
		if ( '' !== $post_name ) {
			$lines[] = '<wp:post_name>' . $this->cdata( $post_name ) . '</wp:post_name>';
		}
		$lines[] = '<content:encoded>' . $this->cdata( $content ) . '</content:encoded>';
		if ( has_post_thumbnail( $post ) ) {
			$lines[] = '<wp:attachment_url>' . $this->cdata( wp_get_attachment_url( get_post_thumbnail_id( $post ) ) ) . '</wp:attachment_url>';
		}
		foreach ( $this->content_image_urls( $post->post_content ) as $url ) {
			$lines[] = '<wp:content_image_url>' . $this->cdata( $url ) . '</wp:content_image_url>';
		}
		foreach ( $meta_pairs as $meta_key => $meta_value ) {
			$lines[] = '<wp:postmeta>';
			$lines[] = '<wp:meta_key>' . $this->cdata( $meta_key ) . '</wp:meta_key>';
			$lines[] = '<wp:meta_value>' . $this->cdata( $meta_value ) . '</wp:meta_value>';
			$lines[] = '</wp:postmeta>';
		}
		foreach ( $links as $path => $target_key ) {
			$lines[] = '<wp:postmeta>';
			$lines[] = '<wp:meta_key>' . $this->cdata( self::CONTENT_LINK_META_KEY ) . '</wp:meta_key>';
			$lines[] = '<wp:meta_value>' . $this->cdata( $path . '|' . $target_key ) . '</wp:meta_value>';
			$lines[] = '</wp:postmeta>';
		}
		$lines[] = '</item>';

		return $lines;
	}

	/**
	 * Additional pages under an exported person: pages with no facts of
	 * their own, so GEDCOM has no record of them, but with text this file
	 * can still carry. Walks the whole subtree beneath a person, not just
	 * their direct children, since an additional page can itself hold
	 * further additional pages nested under it.
	 *
	 * @param \WP_Post[] $people Exported people.
	 * @param array      $ids    Post ID => xref, from export_xrefs().
	 * @return array Each entry: 'post' the child WP_Post, 'parent_key'
	 *               its immediate parent's own key (a person's xref, or
	 *               another additional page's own key), and 'key' the
	 *               target key a link to it — or to a page nested under
	 *               it in turn — is recorded by.
	 */
	private function get_export_related_pages( $people, $ids ) {
		$related = array();

		foreach ( $people as $person ) {
			$this->collect_related_pages( $person->ID, $ids[ $person->ID ], $ids, $related );
		}

		return $related;
	}

	/**
	 * The recursive step behind get_export_related_pages(): every child
	 * of $parent_post_id that isn't independently exported as a person,
	 * tagged with $parent_key, then walked into for pages nested under
	 * it in turn — in parent-before-child order, since that is the order
	 * import needs to create them in.
	 *
	 * @param int    $parent_post_id The immediate parent to walk children of.
	 * @param string $parent_key     That parent's own key: an xref for a
	 *                                person, or an "R:…" key for an
	 *                                additional page.
	 * @param array  $ids            Post ID => xref, for people with facts.
	 * @param array  $related        Accumulator, built up by reference.
	 */
	private function collect_related_pages( $parent_post_id, $parent_key, $ids, &$related ) {
		$children = get_posts(
			array(
				'post_type'      => Person::POST_TYPE,
				'post_parent'    => $parent_post_id,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			)
		);

		foreach ( $children as $child ) {
			// Already exported in its own right as a person with facts:
			// its own structural parent, if any, is tagged directly onto
			// its own item by export_content_string() instead.
			if ( isset( $ids[ $child->ID ] ) ) {
				continue;
			}

			$key = 'R:' . $parent_key . ':' . $child->post_name;

			$related[] = array(
				'post'       => $child,
				'parent_key' => $parent_key,
				'key'        => $key,
			);

			$this->collect_related_pages( $child->ID, $key, $ids, $related );
		}
	}

	/**
	 * The xref of an exported post's own post_parent, when that parent
	 * was itself exported — the site's page hierarchy, which is a
	 * different relationship from anything GEDCOM's father/mother
	 * pointers describe, and is otherwise not carried anywhere.
	 */
	private function exported_parent_xref( $post, $ids ) {
		$parent_id = (int) $post->post_parent;

		return ( $parent_id && isset( $ids[ $parent_id ] ) ) ? $ids[ $parent_id ] : '';
	}

	/**
	 * The same CDATA-splitting WordPress core's own WXR export uses, so a
	 * page whose text happens to contain "]]>" can't break the file.
	 */
	private function cdata( $value ) {
		return '<![CDATA[' . str_replace( ']]>', ']]]]><![CDATA[>', (string) $value ) . ']]>';
	}

	/**
	 * This site's own image URLs referenced in a person's text, so the
	 * importer has something to fetch — listed separately, in full, because
	 * the text itself keeps only the path (see relativize_images()).
	 */
	private function content_image_urls( $content ) {
		if ( ! preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\']/i', $content, $matches ) ) {
			return array();
		}

		$home = home_url();
		$urls = array();
		foreach ( array_unique( $matches[1] ) as $url ) {
			if ( 0 === strpos( $url, $home ) ) {
				$urls[] = $url;
			}
		}

		return $urls;
	}

	/**
	 * Strips this site's own domain from same-site image references in a
	 * person's text, so the file does not silently hotlink a private wiki's
	 * photos into wherever it ends up — the path is only good for anything
	 * once the importer has actually downloaded the image.
	 */
	private function relativize_images( $content ) {
		return preg_replace_callback(
			'/(<img[^>]+src=["\'])' . preg_quote( home_url(), '/' ) . '([^"\']*)(["\'])/i',
			function ( $matches ) {
				return $matches[1] . $matches[2] . $matches[3];
			},
			$content
		);
	}

	/**
	 * Strips this site's own domain from same-site links in a person's
	 * text, the same reason relativize_images() does it for photos. Where
	 * a link points to another person in this same export, its path and
	 * the xref it points to are collected into $links by reference, so the
	 * importer can put back a working link once it knows where that
	 * person landed there — a person's own URL structure is not something
	 * a content file can know in advance, especially crossing between two
	 * different plugins.
	 */
	private function relativize_links( $content, $ids, &$links ) {
		$home = home_url();
		// People are not a public post type with rewrite rules — their URLs
		// are resolved by the app itself, not url_to_postid() — so the path
		// is handed to the same lookup the app's own routing uses.
		$prefix = '/' . App::URL_PATH . '/';

		return preg_replace_callback(
			'/(<a\s[^>]*href=["\'])' . preg_quote( $home, '/' ) . '([^"\']*)(["\'])/i',
			function ( $matches ) use ( $ids, $prefix, &$links ) {
				$path = $matches[2];
				if ( 0 === strpos( $path, $prefix ) ) {
					$person = Person::get_by_path( substr( $path, strlen( $prefix ) ) );
					if ( $person && isset( $ids[ $person->ID ] ) ) {
						$links[ $path ] = $ids[ $person->ID ];
					}
				}

				return $matches[1] . $path . $matches[3];
			},
			$content
		);
	}

	/**
	 * The links a content item's text had to other exported people, as
	 * path => the xref that link pointed to.
	 */
	private function content_links_for_item( $item ) {
		$wp_fields = $item->children( 'http://wordpress.org/export/1.2/' );
		$links     = array();

		foreach ( $wp_fields->postmeta as $meta ) {
			$meta_fields = $meta->children( 'http://wordpress.org/export/1.2/' );
			if ( self::CONTENT_LINK_META_KEY !== (string) $meta_fields->meta_key ) {
				continue;
			}

			$parts = explode( '|', (string) $meta_fields->meta_value, 2 );
			if ( 2 === count( $parts ) && '' !== $parts[0] ) {
				$links[ $parts[0] ] = $parts[1];
			}
		}

		return $links;
	}

	/**
	 * Puts working links back into the text of everyone this run touched
	 * who had one, now that every person in $id_map has a page here. Called
	 * while the content file is still parked — it reads the same file
	 * apply_content_to_person()/apply_content() already read from.
	 */
	private function resolve_content_links( $token, $id_map, $related_id_map = array() ) {
		$index = $this->content_index( $token );
		if ( ! $index ) {
			return;
		}

		// One lookup for every kind of link target: a plain GEDCOM xref
		// never collides with the "R:…" keys additional pages use.
		$targets = $id_map + $related_id_map;

		$items_by_post = array();
		foreach ( $id_map as $xref => $post_id ) {
			if ( isset( $index['by_xref'][ $xref ] ) ) {
				$items_by_post[ $post_id ] = $index['by_xref'][ $xref ];
			}
		}
		foreach ( $related_id_map as $key => $post_id ) {
			if ( isset( $index['by_related_key'][ $key ] ) ) {
				$items_by_post[ $post_id ] = $index['by_related_key'][ $key ];
			}
		}

		foreach ( $items_by_post as $post_id => $item ) {
			$links = $this->content_links_for_item( $item );
			if ( ! $links ) {
				continue;
			}

			$replacements = array();
			foreach ( $links as $path => $target_key ) {
				if ( isset( $targets[ $target_key ] ) ) {
					$replacements[ $path ] = get_permalink( $targets[ $target_key ] );
				}
			}

			if ( ! $replacements ) {
				continue;
			}

			$post = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}

			$new_content = str_replace( array_keys( $replacements ), array_values( $replacements ), $post->post_content );
			if ( $new_content !== $post->post_content ) {
				wp_update_post(
					wp_slash(
						array(
							'ID'           => $post_id,
							'post_content' => $new_content,
						)
					)
				);
			}
		}
	}

	/**
	 * Sets post_parent on a person who ended up with a GEDCOM individual
	 * of their own, but whose page the content file also recorded as
	 * sitting under another exported page — a page hierarchy is a
	 * separate thing from anything father/mother describes, and nothing
	 * else carries it across.
	 *
	 * @param array $id_map    Xref => post ID for this import.
	 * @param array $parent_of Post ID => the xref of that post's own
	 *                         structural parent, from apply_content_to_person()
	 *                         or apply_content().
	 */
	private function resolve_related_parents( $id_map, $parent_of ) {
		foreach ( $parent_of as $post_id => $parent_xref ) {
			if ( empty( $id_map[ $parent_xref ] ) ) {
				continue;
			}

			$parent_id = (int) $id_map[ $parent_xref ];
			$post      = get_post( $post_id );
			if ( $post && (int) $post->post_parent !== $parent_id ) {
				wp_update_post(
					wp_slash(
						array(
							'ID'          => $post_id,
							'post_parent' => $parent_id,
						)
					)
				);
			}
		}
	}

	/**
	 * Apply a content file to the people it matches. Never creates a
	 * person and never touches anything but post_content — and, when
	 * asked, a person's photo. Used for the no-JS whole-file path only; a
	 * batched GEDCOM import applies each item as its own person is
	 * resolved.
	 *
	 * @param string $contents        The content file.
	 * @param bool   $download_images Whether to fetch each matched person's
	 *                                image into the media library and set
	 *                                it as their photo. Off by default:
	 *                                this is the one part of the file that
	 *                                makes an outbound request, to whatever
	 *                                URL the file names.
	 */
	private function apply_content( $contents, $download_images = false ) {
		$xml = $this->parse_content_xml( $contents );
		if ( is_wp_error( $xml ) ) {
			return $xml;
		}

		$index          = $this->existing_page_index();
		$updated        = 0;
		$skipped        = 0;
		$images         = 0;
		$related_id_map = array();
		$parent_of      = array();

		foreach ( $xml->channel->item as $item ) {
			// An item with no xref of its own is an additional page,
			// handled in the second pass below, once every person it
			// could belong under already has a page.
			if ( ! $this->item_meta( $item, self::CONTENT_META_KEY ) ) {
				continue;
			}

			$result = $this->apply_content_item( $item, $index, $download_images );
			if ( $result['matched'] ) {
				++$updated;

				$parent_xref = $this->item_meta( $item, self::CONTENT_RELATED_META_KEY );
				if ( $parent_xref ) {
					$parent_of[ $result['post_id'] ] = $parent_xref;
				}
			} else {
				++$skipped;
			}
			$images += $result['images'];
		}

		// Additional pages, which can themselves hold further additional
		// pages nested under them. A page's own parent_key isn't
		// resolvable until that parent's post exists, so this keeps
		// making passes over whatever is left, resolving one more layer
		// each time, until a pass makes no progress at all — one layer
		// of nesting deep does not depend on the file listing a parent
		// before its children, even though both plugins' own export
		// always does.
		$pending = array();
		foreach ( $xml->channel->item as $item ) {
			if ( $this->item_meta( $item, self::CONTENT_META_KEY ) ) {
				continue;
			}

			$parent_key = $this->item_meta( $item, self::CONTENT_RELATED_META_KEY );
			if ( $parent_key ) {
				$pending[] = array( $item, $parent_key );
			}
		}

		$resolved = $index['xref'];
		$progress = true;
		while ( $pending && $progress ) {
			$progress = false;
			$next     = array();

			foreach ( $pending as $entry ) {
				list( $item, $parent_key ) = $entry;
				if ( empty( $resolved[ $parent_key ] ) ) {
					$next[] = $entry;
					continue;
				}

				$child_id = $this->resolve_related_page( $item, (int) $resolved[ $parent_key ] );
				if ( ! $child_id || is_wp_error( $child_id ) ) {
					continue;
				}

				$result = $this->apply_content_item_to_post( $item, $child_id, $download_images );
				++$updated;
				$images += $result['images'];

				$child_key                 = 'R:' . $parent_key . ':' . $this->related_item_slug( $item );
				$resolved[ $child_key ]    = $child_id;
				$related_id_map[ $child_key ] = $child_id;
				$progress                  = true;
			}

			$pending = $next;
		}
		// Whatever is left names a parent that never resolved — dropped
		// from the file, or not exported this time.
		$skipped += count( $pending );

		return array(
			'updated'        => $updated,
			'skipped'        => $skipped,
			'images'         => $images,
			'related_id_map' => $related_id_map,
			'parent_of'      => $parent_of,
		);
	}

	/**
	 * Parse a content file, the same way for the whole-file path and the
	 * per-person one.
	 *
	 * @return \SimpleXMLElement|\WP_Error
	 */
	private function parse_content_xml( $contents ) {
		$previous_setting = libxml_use_internal_errors( true );
		$xml               = simplexml_load_string( $contents, 'SimpleXMLElement', LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous_setting );

		if ( false === $xml || ! isset( $xml->channel ) ) {
			return new \WP_Error( 'invalid_file', __( 'This does not look like a content file.', 'family-wiki' ), array( 'status' => 400 ) );
		}

		return $xml;
	}

	/**
	 * Apply one <item> to the person it matches.
	 *
	 * @return array array( 'matched' => bool, 'post_id' => int, 'images' => int ).
	 */
	private function apply_content_item( $item, $index, $download_images ) {
		$wp_fields = $item->children( 'http://wordpress.org/export/1.2/' );
		$title     = trim( (string) $item->title );

		$post_id = $this->match_content_post( $wp_fields, $title, $index );
		if ( ! $post_id ) {
			return array(
				'matched' => false,
				'post_id' => 0,
				'images'  => 0,
			);
		}

		return array_merge(
			array(
				'matched' => true,
				'post_id' => $post_id,
			),
			$this->apply_content_item_to_post( $item, $post_id, $download_images )
		);
	}

	/**
	 * Apply one <item> to a person already known to be its match — the
	 * person a GEDCOM import just resolved, when a content file rides
	 * along with it. Split out from apply_content_item() since there is
	 * nothing left to match there.
	 *
	 * Downloads any images straight away: this is the no-JS whole-file
	 * path, which has no batching of its own to spread them across —
	 * the batched path instead calls apply_content_text_to_post()
	 * directly and works through the resulting tasks its own way, one
	 * image at a time.
	 *
	 * @return array array( 'images' => int ).
	 */
	private function apply_content_item_to_post( $item, $post_id, $download_images ) {
		$tasks  = $this->apply_content_text_to_post( $item, $post_id, $download_images );
		$images = 0;

		foreach ( $tasks as $task ) {
			if ( $this->apply_pending_image( $task ) ) {
				++$images;
			}
		}

		return array( 'images' => $images );
	}

	/**
	 * Applies an item's text to a post, without downloading anything.
	 * Images are only ever queued here, as a list of tasks — each an
	 * image download plus wherever it needs to be applied — for the
	 * caller to work through, so that a person with many photos cannot
	 * make a single request run any longer than one image download
	 * takes.
	 *
	 * @return array Pending tasks: each array( 'post_id', 'type' =>
	 *               'thumbnail'|'content', 'url' ).
	 */
	private function apply_content_text_to_post( $item, $post_id, $download_images ) {
		$wp_fields  = $item->children( 'http://wordpress.org/export/1.2/' );
		$content_ns = $item->children( 'http://purl.org/rss/1.0/modules/content/' );
		$content    = isset( $content_ns->encoded ) ? (string) $content_ns->encoded : '';

		wp_update_post(
			wp_slash(
				array(
					'ID'           => $post_id,
					'post_content' => $content,
				)
			)
		);

		if ( ! $download_images ) {
			return array();
		}

		$tasks     = array();
		$image_url = isset( $wp_fields->attachment_url ) ? trim( (string) $wp_fields->attachment_url ) : '';
		if ( $image_url ) {
			$tasks[] = array(
				'post_id' => $post_id,
				'type'    => 'thumbnail',
				'url'     => $image_url,
			);
		}

		foreach ( $wp_fields->content_image_url as $node ) {
			$url = trim( (string) $node );
			if ( $url ) {
				$tasks[] = array(
					'post_id' => $post_id,
					'type'    => 'content',
					'url'     => $url,
				);
			}
		}

		return $tasks;
	}

	/**
	 * Downloads one pending image and applies it — a thumbnail set as
	 * the post's photo, a content image put back into the post's text
	 * wherever it was referenced. What this whole staged process exists
	 * to spread across separate requests, one at a time.
	 *
	 * @return bool Whether an attachment was actually created.
	 */
	private function apply_pending_image( $task ) {
		$attachment_id = $this->sideload_image( $task['url'], $task['post_id'] );
		if ( ! $attachment_id ) {
			return false;
		}

		if ( 'thumbnail' === $task['type'] ) {
			set_post_thumbnail( $task['post_id'], $attachment_id );
			return true;
		}

		$post = get_post( $task['post_id'] );
		if ( ! $post ) {
			return false;
		}

		$new_content = $this->replace_image_reference( $post->post_content, $task['url'], wp_get_attachment_url( $attachment_id ) );
		if ( $new_content !== $post->post_content ) {
			wp_update_post(
				wp_slash(
					array(
						'ID'           => $task['post_id'],
						'post_content' => $new_content,
					)
				)
			);
		}

		return true;
	}

	/**
	 * Fetch an image into the media library, attached to the given person.
	 * Only ever called when the upload form's checkbox asked for it.
	 *
	 * @return int The new attachment's ID, or 0 on failure.
	 */
	private function sideload_image( $url, $post_id ) {
		if ( ! wp_http_validate_url( $url ) ) {
			return 0;
		}

		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$attachment_id = media_sideload_image( $url, $post_id, null, 'id' );

		return is_wp_error( $attachment_id ) ? 0 : $attachment_id;
	}

	/**
	 * The text keeps only the path an image lived at on the exporting site
	 * (see relativize_images()), not its domain — this puts back wherever
	 * the image now lives here, once it has been downloaded.
	 */
	private function replace_image_reference( $content, $original_url, $new_url ) {
		$path = wp_parse_url( $original_url, PHP_URL_PATH );
		if ( ! $path ) {
			return $content;
		}

		$query = wp_parse_url( $original_url, PHP_URL_QUERY );
		if ( $query ) {
			$path .= '?' . $query;
		}

		return str_replace( $path, $new_url, $content );
	}

	/**
	 * The person a content entry belongs to: their xref first, an
	 * unambiguous exact title match otherwise. A title shared by more than
	 * one person is left alone rather than guessed at.
	 */
	private function match_content_post( $wp_fields, $title, $index ) {
		$xref = '';
		foreach ( $wp_fields->postmeta as $meta ) {
			$meta_fields = $meta->children( 'http://wordpress.org/export/1.2/' );
			if ( self::CONTENT_META_KEY === (string) $meta_fields->meta_key ) {
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
}
