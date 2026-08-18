<?php
namespace Family_Wiki;

class GEDCOM {
	const MENU_SLUG = 'family-wiki-gedcom';
	const XREF_META = '_family_wiki_gedcom_xref';
	const IMPORT_TRANSIENT_PREFIX = 'family_wiki_gedcom_import_';
	const RUN_TRANSIENT_PREFIX = 'family_wiki_gedcom_run_';

	/**
	 * How much of the file one request takes on. Small enough that no single
	 * request is at risk of the timeout a whole family would run into, large
	 * enough that the file is not re-read hundreds of times on the way through.
	 */
	const BATCH_PEOPLE = 25;
	const BATCH_FAMILIES = 50;

	const CONTENT_EXPORT_ACTION = 'family_wiki_content_export';

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

	private $nav_menu_auto_add_priority = false;

	/**
	 * Per token: a companion content file's items keyed by xref, and
	 * whether it asked to download images — parsed once per request no
	 * matter how many people in a batch ask for it. False for a token with
	 * no companion file.
	 */
	private $content_index_cache = array();

	private $field_keys = array(
		'alive'                    => 'field_65aa3e2cb6f44',
		'sex'                      => 'field_65aa445779733',
		'born_as'                  => 'field_65aa46c03a9a6',
		'citizenships'             => 'field_66c0000000001',
		'birth_date'               => 'field_65aa3a29619a5',
		'exact_birth_date_unknown' => 'field_65aa3dcfa1872',
		'birth_place'              => 'field_65aa3c4715bc9',
		'death_date'               => 'field_65aa3ab91bcf7',
		'exact_death_date_unknown' => 'field_65aa3e03b6f43',
		'death_place'              => 'field_65aa3c5c15bca',
		'father'                   => 'field_65aa3c2015bc7',
		'father_name'              => 'field_65aa46933a9a4',
		'mother'                   => 'field_65aa3c3d15bc8',
		'mother_name'              => 'field_65aa46ad3a9a5',
		'children'                 => 'field_65aa4406f02a5',
		'marriages'                => 'field_66b8f90100005',
		'spouse'                   => 'field_66b8f90100001',
		'spouse_name'              => 'field_66b8f90100002',
		'marriage_date'            => 'field_66b8f90100003',
		'marriage_place'           => 'field_66b8f90100004',
	);

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_importer' ) );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar_menu' ), 81 );
		add_action( 'admin_post_family_wiki_gedcom_export', array( $this, 'export_download' ) );
		add_action( 'admin_post_' . self::CONTENT_EXPORT_ACTION, array( $this, 'export_content_download' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	public static function can_import() {
		return current_user_can( 'import' );
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
			'family-wiki/v1',
			'/import',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'can_import' ),
				'callback'            => array( $this, 'rest_import_start' ),
			)
		);

		register_rest_route(
			'family-wiki/v1',
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
			return new \WP_Error( 'family_wiki_review_expired', $this->error_message( 'review_expired' ), array( 'status' => 400 ) );
		}

		$records = $this->parse_records( $contents );
		if ( empty( $records['INDI'] ) ) {
			return new \WP_Error( 'family_wiki_no_individuals', $this->error_message( 'no_individuals' ), array( 'status' => 400 ) );
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
				return new \WP_Error( 'family_wiki_no_selection', $this->error_message( 'no_selection' ), array( 'status' => 400 ) );
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
		);

		// Lets a companion content file uploaded alongside this one join the
		// run, so each person's text lands right after the person themselves.
		$state = $this->add_content_to_run_state( $state, $token );

		if ( ! set_transient( self::RUN_TRANSIENT_PREFIX . $run, $state, HOUR_IN_SECONDS ) ) {
			return new \WP_Error( 'family_wiki_run_failed', $this->error_message( 'store_failed' ), array( 'status' => 500 ) );
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
			return new \WP_Error( 'family_wiki_run_expired', __( 'The import stopped before it finished. Please upload the file again.', 'family-wiki' ), array( 'status' => 400 ) );
		}

		$contents = $this->get_import_file( $state['token'] );
		if ( false === $contents ) {
			return new \WP_Error( 'family_wiki_review_expired', $this->error_message( 'review_expired' ), array( 'status' => 400 ) );
		}

		$records = $this->parse_records( $contents );

		$this->suspend_nav_menu_auto_add();
		if ( 'families' === $state['stage'] ) {
			$state = $this->import_families_batch( $state, $records );
		} else {
			$state = $this->import_people_batch( $state, $records );
		}
		$this->restore_nav_menu_auto_add();

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

			// A companion content file, if the run has one, applies this
			// person's text right after the person themselves — the progress
			// bar is one person at a time either way.
			$state = $this->apply_content_to_person( $state, $xref, $person['id'] );
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
		Calendar::flush_dates_cache();
		Front_Page::flush_cache();

		// Every person this run touched now has a page, so a content file's
		// links to them can be resolved — while it is still parked, before
		// it is cleaned up below.
		if ( ! empty( $state['content'] ) ) {
			$this->resolve_content_links( $state['token'], $state['id_map'] );
		}

		// Cleans up the content file too, if there was one: it shares this
		// same token's one transient and temp files with the GEDCOM file.
		$this->delete_import_file( $state['token'] );
		delete_transient( self::RUN_TRANSIENT_PREFIX . $run );

		$message = sprintf(
			// translators: %1$d is a number of pages created, %2$d a number updated.
			__( 'GEDCOM import complete. Created %1$d pages and updated %2$d pages.', 'family-wiki' ),
			$state['created'],
			$state['updated']
		);

		// A companion content file, if the run had one, adds its own summary
		// sentence and its own counts to the redirect.
		$message    = $this->add_content_to_finish_message( $message, $state );
		$query_args = $this->add_content_to_finish_query_args(
			array(
				'family_wiki_imported' => $state['created'],
				'family_wiki_updated'  => $state['updated'],
			),
			$state
		);

		return array(
			'done'     => true,
			'stage'    => 'done',
			'created'  => $state['created'],
			'updated'  => $state['updated'],
			'message'  => $message,
			'redirect' => add_query_arg( $query_args, admin_url( 'admin.php?import=family-wiki-gedcom' ) ),
		);
	}

	private function run_progress( $state ) {
		return array(
			'done'     => false,
			'stage'    => $state['stage'],
			'position' => (int) $state['cursor'],
			'total'    => 'families' === $state['stage'] ? count( $state['families'] ) : count( $state['xrefs'] ),
			'created'  => $state['created'],
			'updated'  => $state['updated'],
		);
	}

	public function admin_menu() {
		add_management_page(
			__( 'Family Wiki', 'family-wiki' ),
			__( 'Family Wiki', 'family-wiki' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Family Wiki', 'family-wiki' ); ?></h1>
			<h2><?php esc_html_e( 'Export', 'family-wiki' ); ?></h2>
			<p><?php esc_html_e( 'Download published wiki pages as a GEDCOM file.', 'family-wiki' ); ?></p>
			<form class="family-wiki-download-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="family_wiki_gedcom_export" />
				<?php wp_nonce_field( 'family_wiki_gedcom_export' ); ?>
				<?php submit_button( __( 'Download GEDCOM', 'family-wiki' ), 'primary', 'submit', false ); ?>
				<span class="family-wiki-download-check" aria-hidden="true" hidden>&#10003;</span>
			</form>
			<?php $this->render_content_export_button(); ?>
			<hr />
			<h2><?php esc_html_e( 'Import', 'family-wiki' ); ?></h2>
			<?php $this->render_upload_form(); ?>
			<style>
				.family-wiki-download-form {
					align-items: center;
					display: inline-flex;
				}

				.family-wiki-download-check {
					color: #008a20;
					font-weight: 600;
					margin-left: 0.5em;
				}
			</style>
			<script>
				(function () {
					document.querySelectorAll( '.family-wiki-download-form' ).forEach( function ( form ) {
						form.addEventListener( 'submit', function () {
							var check = form.querySelector( '.family-wiki-download-check' );
							if ( check ) {
								check.hidden = false;
							}
						} );
					} );
				}());
			</script>
		</div>
		<?php
	}

	/**
	 * The upload form, shown both here and on the standard import screen.
	 */
	private function render_upload_form() {
		?>
		<p><?php esc_html_e( 'Upload a GEDCOM file, review the people in it, and choose which entries or descendant subtrees to import. Existing pages are matched by prior GEDCOM xref first, then by page title.', 'family-wiki' ); ?></p>
		<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin.php?import=family-wiki-gedcom&noheader=true' ) ); ?>">
			<?php wp_nonce_field( 'family_wiki_gedcom_import' ); ?>
			<input type="hidden" name="family_wiki_gedcom_step" value="upload" />
			<p>
				<label>
					<?php esc_html_e( 'GEDCOM file', 'family-wiki' ); ?><br />
					<input type="file" name="gedcom" accept=".ged,.gedcom,text/plain" required />
				</label>
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
			<?php $this->render_content_field(); ?>
			<?php submit_button( __( 'Upload and review GEDCOM', 'family-wiki' ), 'primary', 'submit', false ); ?>
		</form>
		<?php
	}

	public function register_importer() {
		if ( ! function_exists( 'register_importer' ) ) {
			return;
		}

		register_importer(
			'family-wiki-gedcom',
			__( 'Family Wiki', 'family-wiki' ),
			__( 'Import people and family relationships from a GEDCOM file into Family Wiki pages.', 'family-wiki' ),
			array( $this, 'render_importer' )
		);
	}

	public function admin_bar_menu( \WP_Admin_Bar $wp_admin_bar ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$wp_admin_bar->add_node(
			array(
				'id'     => 'family-wiki-import-export',
				'parent' => Calendar::MENU_ID,
				'title'  => __( 'Import / Export', 'family-wiki' ),
				'href'   => admin_url( 'tools.php?page=' . self::MENU_SLUG ),
			)
		);
	}

	public function render_importer() {
		if ( ! current_user_can( 'import' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to import GEDCOM data.', 'family-wiki' ) );
		}

		if ( ! empty( $_POST['family_wiki_gedcom_step'] ) ) {
			$step = sanitize_key( wp_unslash( $_POST['family_wiki_gedcom_step'] ) );
			if ( 'upload' === $step ) {
				$this->import_upload();
			} elseif ( 'selected' === $step ) {
				$this->import_selected();
			}
		}

		$imported = isset( $_GET['family_wiki_imported'] ) ? absint( $_GET['family_wiki_imported'] ) : null;
		$updated  = isset( $_GET['family_wiki_updated'] ) ? absint( $_GET['family_wiki_updated'] ) : null;
		$error    = isset( $_GET['family_wiki_error'] ) ? sanitize_key( $_GET['family_wiki_error'] ) : '';
		$review   = isset( $_GET['family_wiki_review'] ) ? sanitize_key( $_GET['family_wiki_review'] ) : '';
		?>
		<div class="wrap">
			<h2><?php esc_html_e( 'Import Family Wiki', 'family-wiki' ); ?></h2>
			<?php if ( null !== $imported && null !== $updated ) : ?>
				<div class="notice notice-success">
					<p>
						<?php
						echo esc_html(
							sprintf(
								// translators: %1$d is a number of created pages, %2$d a number of updated pages.
								__( 'GEDCOM import complete. Created %1$d pages and updated %2$d pages.', 'family-wiki' ),
								$imported,
								$updated
							)
						);
						?>
					</p>
				</div>
			<?php endif; ?>
			<?php if ( $error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $this->error_message( $error ) ); ?></p></div>
			<?php endif; ?>
			<?php if ( $review ) : ?>
				<?php $this->render_import_review( $review ); ?>
			<?php else : ?>
				<?php $this->render_upload_form(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_import_review( $token ) {
		$contents = $this->get_import_file( $token );
		if ( false === $contents ) {
			?>
			<div class="notice notice-error"><p><?php echo esc_html( $this->error_message( 'review_expired' ) ); ?></p></div>
			<?php
			return;
		}

		$records = $this->parse_records( $contents );
		if ( empty( $records['INDI'] ) ) {
			?>
			<div class="notice notice-error"><p><?php echo esc_html( $this->error_message( 'no_individuals' ) ); ?></p></div>
			<?php
			return;
		}

		$families     = empty( $records['FAM'] ) ? array() : $records['FAM'];
		$people       = $this->review_people( $records );
		$total        = count( $people );
		$family_count = count( $families );
		$tree_data    = $this->review_tree_data( $families, $people );
		?>
		<section class="family-wiki-gedcom-review">
			<h2><?php esc_html_e( 'Review GEDCOM import', 'family-wiki' ); ?></h2>
			<?php
			$connected = 0;
			$matched   = 0;
			foreach ( $people as $person ) {
				if ( $person['wiki_hits'] ) {
					++$connected;
				}
				if ( $person['match_id'] ) {
					++$matched;
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
				?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?import=family-wiki-gedcom&noheader=true' ) ); ?>" data-family-wiki-gedcom-form>
				<input type="hidden" name="family_wiki_gedcom_step" value="selected" />
				<input type="hidden" name="family_wiki_review" value="<?php echo esc_attr( $token ); ?>" />
				<?php wp_nonce_field( 'family_wiki_gedcom_import_selected' ); ?>
				<p class="family-wiki-gedcom-review__views">
					<button type="button" class="button button-primary" data-family-wiki-gedcom-view="connected">
						<?php
						echo esc_html(
							sprintf(
								// translators: %d is a number of people.
								__( 'Connects to your wiki (%d)', 'family-wiki' ),
								$connected
							)
						);
						?>
					</button>
					<button type="button" class="button" data-family-wiki-gedcom-view="all">
						<?php
						echo esc_html(
							sprintf(
								// translators: %d is a number of people.
								__( 'All people (%d)', 'family-wiki' ),
								$total
							)
						);
						?>
					</button>
					<input type="search" class="regular-text" data-family-wiki-gedcom-filter placeholder="<?php esc_attr_e( 'Filter by name', 'family-wiki' ); ?>" />
				</p>
				<p>
					<button type="button" class="button" data-family-wiki-gedcom-select-all><?php esc_html_e( 'Select everyone shown', 'family-wiki' ); ?></button>
					<button type="button" class="button" data-family-wiki-gedcom-clear><?php esc_html_e( 'Clear selection', 'family-wiki' ); ?></button>
				</p>
				<table class="widefat striped">
					<thead>
						<tr>
							<th scope="col" class="check-column"><span class="screen-reader-text"><?php esc_html_e( 'Import', 'family-wiki' ); ?></span></th>
							<th scope="col"><?php esc_html_e( 'Person', 'family-wiki' ); ?></th>
							<th scope="col"><a href="#" data-family-wiki-gedcom-sort="wiki"><?php esc_html_e( 'On your wiki', 'family-wiki' ); ?></a></th>
							<th scope="col"><a href="#" data-family-wiki-gedcom-sort="subtree"><?php esc_html_e( 'Subtree', 'family-wiki' ); ?></a></th>
							<th scope="col"><?php esc_html_e( 'Birth', 'family-wiki' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Death', 'family-wiki' ); ?></th>
							<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Select subtree', 'family-wiki' ); ?></span></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $people as $person ) : ?>
							<tr
								data-family-wiki-gedcom-row
								data-name="<?php echo esc_attr( strtolower( $person['name'] ) ); ?>"
								data-wiki="<?php echo esc_attr( $person['wiki_hits'] ); ?>"
								data-subtree="<?php echo esc_attr( $person['count'] ); ?>"
								<?php echo $person['wiki_hits'] ? 'data-connected="1"' : ''; ?>
							>
								<th scope="row" class="check-column">
									<input type="checkbox" name="family_wiki_people[]" value="<?php echo esc_attr( $person['xref'] ); ?>" data-family-wiki-gedcom-person="<?php echo esc_attr( $person['xref'] ); ?>" />
								</th>
								<td>
									<strong><?php echo esc_html( $person['name'] ); ?></strong>
									<?php if ( $person['match_id'] ) : ?>
										<br />
										<span class="description">
											<?php
											echo esc_html(
												sprintf(
													// translators: %s is a page title.
													__( 'updates “%s”', 'family-wiki' ),
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
								<td>
									<?php if ( ! empty( $person['descendants'] ) ) : ?>
										<button type="button" class="button button-small" data-family-wiki-gedcom-descendants="<?php echo esc_attr( implode( ',', array_merge( array( $person['xref'] ), $person['descendants'] ) ) ); ?>">
											<?php
											echo esc_html(
												sprintf(
													// translators: %d is a number of people in a descendant subtree.
													__( 'Select subtree (%d)', 'family-wiki' ),
													$person['count'] + 1
												)
											);
											?>
										</button>
									<?php else : ?>
										<span aria-hidden="true">-</span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<div
					class="family-wiki-gedcom-tree"
					data-family-wiki-gedcom-drop-label="<?php esc_attr_e( 'Drop', 'family-wiki' ); ?>"
					data-family-wiki-gedcom-branch-label="<?php esc_attr_e( 'Drop branch', 'family-wiki' ); ?>"
					data-family-wiki-gedcom-toggle-label="<?php esc_attr_e( 'Show or hide this branch', 'family-wiki' ); ?>"
				>
					<h3><?php esc_html_e( 'Selected people', 'family-wiki' ); ?></h3>
					<p class="description" data-family-wiki-gedcom-tree-empty><?php esc_html_e( 'Tick people above and the branches you picked are drawn here, so you can drop the ones you did not mean to take.', 'family-wiki' ); ?></p>
					<ul class="family-wiki-gedcom-tree__list" data-family-wiki-gedcom-tree-list></ul>
					<p class="description" data-family-wiki-gedcom-tree-more hidden>
						<?php
						echo esc_html(
							sprintf(
								// translators: %d is a number of people left out of a drawing of the selection.
								__( '%d more selected people are not drawn here.', 'family-wiki' ),
								0
							)
						);
						?>
					</p>
				</div>
				<p>
					<strong data-family-wiki-gedcom-count>
						<?php
						echo esc_html(
							sprintf(
								// translators: %1$d is a number of selected people, %2$d the total.
								__( '%1$d of %2$d selected', 'family-wiki' ),
								0,
								$total
							)
						);
						?>
					</strong>
				</p>
				<div class="family-wiki-gedcom-progress" data-family-wiki-gedcom-progress hidden>
					<progress data-family-wiki-gedcom-progress-bar max="100" value="0"></progress>
					<p role="status" data-family-wiki-gedcom-progress-text></p>
				</div>
				<?php submit_button( __( 'Import selected people', 'family-wiki' ) ); ?>
			</form>
			<script type="application/json" id="family-wiki-gedcom-import-data">
				<?php
				echo wp_json_encode(
					array(
						'endpoint' => rest_url( 'family-wiki/v1/import' ),
						'nonce'    => wp_create_nonce( 'wp_rest' ),
						'token'    => $token,
						'l10n'     => array(
							'starting' => __( 'Reading the file…', 'family-wiki' ),
							// translators: %1$s is a number of people done, %2$s the total.
							'people'   => __( 'Importing people: %1$s of %2$s', 'family-wiki' ),
							// translators: %1$s is a number of family records done, %2$s the total.
							'families' => __( 'Linking families: %1$s of %2$s', 'family-wiki' ),
							// translators: %s is an error message.
							'failed'   => __( 'The import stopped: %s', 'family-wiki' ),
						),
					),
					JSON_HEX_TAG | JSON_HEX_AMP
				);
				?>
			</script>
			<style>
				.family-wiki-gedcom-tree__list,
				.family-wiki-gedcom-tree__list ul {
					list-style: none;
					margin: 0;
					padding: 0;
				}

				.family-wiki-gedcom-tree__list ul {
					border-left: 1px solid rgba(127, 127, 127, 0.3);
					margin-left: 0.4em;
					padding-left: 1.1em;
				}

				.family-wiki-gedcom-tree__list li {
					line-height: 1.6;
					margin: 0.15em 0;
				}

				.family-wiki-gedcom-tree__line--branch [data-family-wiki-gedcom-node] {
					cursor: pointer;
				}

				.family-wiki-gedcom-tree__years {
					color: rgba(127, 127, 127, 0.9);
					white-space: nowrap;
				}

				/*
				 * Two classes deep, because .wp-core-ui .button-link zeroes the
				 * margin and pins the text left, and would win against one.
				 */
				.family-wiki-gedcom-tree .family-wiki-gedcom-tree__mark {
					display: inline-block;
					text-align: center;
					width: 1.4em;
				}

				.family-wiki-gedcom-tree .family-wiki-gedcom-tree__toggle {
					color: inherit;
					font-size: 1.1em;
					line-height: 1;
					text-decoration: none;
					vertical-align: baseline;
				}

				.family-wiki-gedcom-tree .family-wiki-gedcom-tree__drop {
					margin-left: 0.8em;
				}

				.family-wiki-gedcom-progress progress {
					width: 100%;
				}

				.family-wiki-gedcom-progress--failed progress {
					accent-color: #d63638;
				}
			</style>
			<script type="application/json" id="family-wiki-gedcom-tree-data"><?php echo wp_json_encode( $tree_data, JSON_HEX_TAG | JSON_HEX_AMP ); ?></script>
			<script>
				(function () {
					var review = document.querySelector('.family-wiki-gedcom-review');
					if (!review) {
						return;
					}

					var body = review.querySelector('tbody');
					var counter = review.querySelector('[data-family-wiki-gedcom-count]');
					var countTemplate = counter ? counter.textContent.trim() : '';
					var rows = Array.prototype.slice.call(review.querySelectorAll('[data-family-wiki-gedcom-row]'));
					var view = 'connected';
					var needle = '';
					var sortKey = '';
					var sortDescending = true;

					var tree = JSON.parse(document.getElementById('family-wiki-gedcom-tree-data').textContent);
					var treeList = review.querySelector('[data-family-wiki-gedcom-tree-list]');
					var treeEmpty = review.querySelector('[data-family-wiki-gedcom-tree-empty]');
					var treeMore = review.querySelector('[data-family-wiki-gedcom-tree-more]');
					var moreTemplate = treeMore.textContent.trim();
					var treeBox = review.querySelector('.family-wiki-gedcom-tree');
					var dropLabel = treeBox.getAttribute('data-family-wiki-gedcom-drop-label');
					var branchLabel = treeBox.getAttribute('data-family-wiki-gedcom-branch-label');
					var toggleLabel = treeBox.getAttribute('data-family-wiki-gedcom-toggle-label');
					// Everything starts folded, so picking a branch reads as the few
					// lines it hangs from rather than a wall of names. Kept outside
					// the drawing, which starts over on every tick, so a branch
					// opened up stays open while the selection changes.
					var opened = Object.create(null);
					// Far past what anyone reads. Drawing every node of a whole-file
					// selection would only make ticking a box slow.
					var TREE_LIMIT = 400;

					// Xrefs come out of the file, so they are never spliced into a
					// selector: every lookup goes through these maps instead.
					var boxes = Object.create(null);
					rows.forEach(function (row) {
						var box = boxOf(row);
						if (box) {
							boxes[box.value] = box;
						}
					});

					var parentsOf = Object.create(null);
					Object.keys(tree).forEach(function (xref) {
						(tree[xref].children || []).forEach(function (child) {
							parentsOf[child] = parentsOf[child] || [];
							parentsOf[child].push(xref);
						});
					});

					function boxOf(row) {
						return row.querySelector('[data-family-wiki-gedcom-person]');
					}

					function visible(row) {
						return row.style.display !== 'none';
					}

					function apply() {
						rows.forEach(function (row) {
							var inView = view === 'all' || row.hasAttribute('data-connected');
							var matches = !needle || row.getAttribute('data-name').indexOf(needle) !== -1;
							row.style.display = inView && matches ? '' : 'none';
						});
					}

					function refreshCount() {
						if (!counter) {
							return;
						}
						var selected = rows.filter(function (row) {
							var box = boxOf(row);
							return box && box.checked;
						}).length;
						// Replace the leading number in the rendered, translated string.
						counter.textContent = countTemplate.replace(/\d+/, String(selected));
					}

					function refresh() {
						refreshCount();
						renderTree();
					}

					function person(xref) {
						return tree[xref] || { name: xref };
					}

					function yearRange(entry) {
						if (entry.birth && entry.death) {
							return entry.birth + '–' + entry.death;
						}
						if (entry.birth) {
							return '*' + entry.birth;
						}
						if (entry.death) {
							return '†' + entry.death;
						}

						return '';
					}

					function nameOf(xref) {
						var entry = person(xref);
						var node = document.createElement('span');
						node.setAttribute('data-family-wiki-gedcom-node', xref);
						node.appendChild(document.createTextNode(entry.name));

						var years = yearRange(entry);
						if (years) {
							var dates = document.createElement('span');
							dates.className = 'family-wiki-gedcom-tree__years';
							dates.textContent = ' (' + years + ')';
							node.appendChild(dates);
						}

						return node;
					}

					function byBirth(a, b) {
						// Undated people sort last rather than first, where a missing
						// year would read as the oldest child of every household.
						var left = person(a).birth || '9999';
						var right = person(b).birth || '9999';
						if (left === right) {
							return person(a).name.localeCompare(person(b).name);
						}

						return left < right ? -1 : 1;
					}

					function householdChildren(xref, partners) {
						var seen = Object.create(null);
						var children = [];
						[xref].concat(partners).forEach(function (parent) {
							(person(parent).children || []).forEach(function (child) {
								if (!seen[child]) {
									seen[child] = true;
									children.push(child);
								}
							});
						});

						return children;
					}

					/**
					 * Who a line's drop button takes away: the branch hanging under
					 * it, and only once nothing hangs there the couple on the line
					 * itself. Taking the line along with its branch would carry off
					 * people who married in, and with them whichever of their own
					 * brothers and sisters were drawn beneath them.
					 */
					function dropTargets(item) {
						var branch = item.querySelector('ul');

						return (branch || item.querySelector('.family-wiki-gedcom-tree__line'))
							.querySelectorAll('[data-family-wiki-gedcom-node]');
					}

					function toggleFor(xref, list) {
						var toggle = document.createElement('button');
						toggle.type = 'button';
						toggle.className = 'button-link family-wiki-gedcom-tree__mark family-wiki-gedcom-tree__toggle';
						toggle.setAttribute('data-family-wiki-gedcom-toggle', xref);
						toggle.setAttribute('aria-label', toggleLabel);
						setOpen(toggle, list, !!opened[xref]);

						return toggle;
					}

					function fold(toggle) {
						var xref = toggle.getAttribute('data-family-wiki-gedcom-toggle');
						opened[xref] = !opened[xref];
						setOpen(toggle, toggle.closest('li').querySelector('ul'), opened[xref]);
					}

					function setOpen(toggle, list, open) {
						// The people below stay in the page while folded away: the
						// count on the drop button, and what it drops, are the branch
						// itself and not the part of it that happens to be in view.
						list.hidden = !open;
						toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
						toggle.textContent = open ? '▼' : '►';
					}

					function renderPerson(xref, state) {
						if (state.drawn[xref] || state.left < 1) {
							return null;
						}
						state.drawn[xref] = true;
						state.left -= 1;

						var item = document.createElement('li');
						var line = document.createElement('div');
						line.className = 'family-wiki-gedcom-tree__line';
						line.appendChild(nameOf(xref));

						// The people they married, on the same line: a couple splits
						// their children between them, so drawing them apart would
						// show every household twice with half a family each.
						var partners = (person(xref).partners || []).filter(function (partner) {
							return state.selected[partner] && !state.drawn[partner];
						});
						partners.forEach(function (partner) {
							state.drawn[partner] = true;
							state.left -= 1;
							line.appendChild(document.createTextNode(' & '));
							line.appendChild(nameOf(partner));
						});

						item.appendChild(line);

						var children = householdChildren(xref, partners).filter(function (child) {
							return state.selected[child] && !state.drawn[child];
						}).sort(byBirth);

						if (children.length) {
							var list = document.createElement('ul');
							children.forEach(function (child) {
								var childItem = renderPerson(child, state);
								if (childItem) {
									list.appendChild(childItem);
								}
							});
							if (list.childNodes.length) {
								item.appendChild(list);
								line.insertBefore(toggleFor(xref, list), line.firstChild);
							}
						}

						// A line with nobody under it still gives up the width, so the
						// names down a generation stay under one another.
						if (!item.querySelector('ul')) {
							var spacer = document.createElement('span');
							spacer.className = 'family-wiki-gedcom-tree__mark';
							spacer.setAttribute('aria-hidden', 'true');
							line.insertBefore(spacer, line.firstChild);
						}

						// Only now is it known whether anyone hangs off this line, which
						// is what the button offers to take away.
						var branch = item.querySelector('ul');
						if (branch) {
							line.classList.add('family-wiki-gedcom-tree__line--branch');
						}

						var drop = document.createElement('button');
						drop.type = 'button';
						drop.className = 'button-link family-wiki-gedcom-tree__drop';
						drop.setAttribute('data-family-wiki-gedcom-drop', '');
						drop.textContent = (branch ? branchLabel : dropLabel)
							+ ' (' + dropTargets(item).length + ')';
						line.appendChild(drop);

						return item;
					}

					function renderTree() {
						var state = {
							selected: Object.create(null),
							drawn: Object.create(null),
							left: TREE_LIMIT
						};
						var total = 0;
						rows.forEach(function (row) {
							var box = boxOf(row);
							if (box && box.checked) {
								state.selected[box.value] = true;
								total += 1;
							}
						});

						treeList.textContent = '';
						treeEmpty.hidden = total > 0;
						treeMore.hidden = true;
						if (!total) {
							return;
						}

						// Start from everyone whose parents are staying behind. Those
						// are the tops of the branches that were picked, which is not
						// where the file itself starts.
						Object.keys(state.selected).filter(function (xref) {
							return (parentsOf[xref] || []).every(function (parent) {
								return !state.selected[parent];
							});
						}).sort(byBirth).forEach(function (xref) {
							var item = renderPerson(xref, state);
							if (item) {
								treeList.appendChild(item);
							}
						});

						// Whatever the branches did not reach still has to show up, or
						// people would be imported that the tree never accounted for.
						Object.keys(state.selected).forEach(function (xref) {
							var item = renderPerson(xref, state);
							if (item) {
								treeList.appendChild(item);
							}
						});

						var missing = total - Object.keys(state.drawn).length;
						treeMore.hidden = missing < 1;
						if (missing > 0) {
							treeMore.textContent = moreTemplate.replace(/\d+/, String(missing));
						}
					}

					function sort(key) {
						if (sortKey === key) {
							sortDescending = !sortDescending;
						} else {
							sortKey = key;
							sortDescending = true;
						}
						var attribute = key === 'wiki' ? 'data-wiki' : 'data-subtree';
						rows.sort(function (a, b) {
							var difference = parseInt(a.getAttribute(attribute), 10) - parseInt(b.getAttribute(attribute), 10);
							return sortDescending ? -difference : difference;
						});
						rows.forEach(function (row) {
							body.appendChild(row);
						});
					}

					review.addEventListener('click', function (event) {
						var viewButton = event.target.closest('[data-family-wiki-gedcom-view]');
						if (viewButton) {
							view = viewButton.getAttribute('data-family-wiki-gedcom-view');
							review.querySelectorAll('[data-family-wiki-gedcom-view]').forEach(function (button) {
								button.classList.toggle('button-primary', button === viewButton);
							});
							apply();
							return;
						}

						var sortLink = event.target.closest('[data-family-wiki-gedcom-sort]');
						if (sortLink) {
							event.preventDefault();
							sort(sortLink.getAttribute('data-family-wiki-gedcom-sort'));
							return;
						}

						var selectAll = event.target.closest('[data-family-wiki-gedcom-select-all]');
						var clear = event.target.closest('[data-family-wiki-gedcom-clear]');
						if (selectAll || clear) {
							rows.forEach(function (row) {
								var box = boxOf(row);
								if (!box) {
									return;
								}
								// "Select everyone shown" respects the current view and filter.
								if (clear) {
									box.checked = false;
								} else if (visible(row)) {
									box.checked = true;
								}
							});
							refresh();
							return;
						}

						var descendants = event.target.closest('[data-family-wiki-gedcom-descendants]');
						if (descendants) {
							descendants.getAttribute('data-family-wiki-gedcom-descendants').split(',').forEach(function (xref) {
								if (boxes[xref]) {
									boxes[xref].checked = true;
								}
							});
							refresh();
							return;
						}

						// Folding changes nothing about the selection, so it moves the
						// one branch rather than drawing the whole tree again.
						var toggle = event.target.closest('[data-family-wiki-gedcom-toggle]');
						if (toggle) {
							fold(toggle);
							return;
						}

						// The names are a far bigger target than the arrow beside
						// them, and fold the same branch. On a line with nobody
						// underneath there is nothing to fold, so they do nothing.
						var named = event.target.closest('[data-family-wiki-gedcom-node]');
						if (named) {
							var owner = named.closest('li').querySelector('[data-family-wiki-gedcom-toggle]');
							if (owner) {
								fold(owner);
							}
							return;
						}

						var drop = event.target.closest('[data-family-wiki-gedcom-drop]');
						if (drop) {
							dropTargets(drop.closest('li')).forEach(function (node) {
								var xref = node.getAttribute('data-family-wiki-gedcom-node');
								if (boxes[xref]) {
									boxes[xref].checked = false;
								}
							});
							refresh();
							return;
						}

						if (event.target.closest('[data-family-wiki-gedcom-person]')) {
							refresh();
						}
					});

					review.addEventListener('input', function (event) {
						if (!event.target.closest('[data-family-wiki-gedcom-filter]')) {
							return;
						}
						needle = event.target.value.trim().toLowerCase();
						apply();
					});

					apply();
					refresh();
				}());
			</script>
			<script>
				/*
				 * Importing, a batch at a time.
				 *
				 * A whole family is more work than one request should carry, and a
				 * form post that sits there for a minute looks the same as one that
				 * has died. The same file is walked in pieces instead, and each piece
				 * says how far it has got. Without fetch the form posts itself, which
				 * still works.
				 */
				(function () {
					var review = document.querySelector('.family-wiki-gedcom-review');
					if (!review) {
						return;
					}

					var form = review.querySelector('[data-family-wiki-gedcom-form]');
					var progress = review.querySelector('[data-family-wiki-gedcom-progress]');
					var bar = progress ? progress.querySelector('[data-family-wiki-gedcom-progress-bar]') : null;
					var text = progress ? progress.querySelector('[data-family-wiki-gedcom-progress-text]') : null;
					var dataEl = document.getElementById('family-wiki-gedcom-import-data');
					var settings = dataEl ? JSON.parse(dataEl.textContent) : {};
					var l10n = settings.l10n || {};

					if (!form || !progress || !settings.endpoint || !window.fetch) {
						return;
					}

					form.addEventListener('submit', function (event) {
						var selected = Array.prototype.slice
							.call(form.querySelectorAll('[data-family-wiki-gedcom-person]:checked'))
							.map(function (box) {
								return box.value;
							});

						// Nothing ticked is a mistake the server already words well, and
						// letting the form post keeps that wording in one place.
						if (!selected.length) {
							return;
						}

						event.preventDefault();
						start(selected);
					});

					function send(url, payload) {
						return fetch(url, {
							method: 'POST',
							credentials: 'same-origin',
							headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': settings.nonce },
							body: JSON.stringify(payload || {})
						}).then(function (response) {
							return response.json().then(function (data) {
								if (!response.ok) {
									throw new Error(data && data.message ? data.message : response.statusText);
								}

								return data;
							});
						});
					}

					function start(selected) {
						progress.hidden = false;
						progress.classList.remove('family-wiki-gedcom-progress--failed');
						working(true);
						say(l10n.starting, 0);

						send(settings.endpoint, { token: settings.token, selected: selected })
							.then(function (started) {
								return step(started.run);
							})
							.catch(failed);
					}

					function step(run) {
						return send(settings.endpoint + '/' + run).then(function (state) {
							if (state.done) {
								say(state.message, 100);
								window.location = state.redirect;
								return;
							}

							say(
								(state.stage === 'families' ? l10n.families : l10n.people)
									.replace('%1$s', state.position)
									.replace('%2$s', state.total),
								state.total ? Math.round((state.position / state.total) * 100) : 0
							);

							return step(run);
						});
					}

					function say(message, percent) {
						text.textContent = message;
						bar.value = percent;
					}

					function failed(error) {
						say(l10n.failed.replace('%s', error.message), 0);
						progress.classList.add('family-wiki-gedcom-progress--failed');
						working(false);
					}

					/**
					 * While a run is going, the buttons that would start a second one are
					 * out of reach: the file is walked by cursor, and two walks would
					 * import it twice.
					 */
					function working(busy) {
						Array.prototype.slice.call(form.querySelectorAll('button, input')).forEach(function (control) {
							control.disabled = busy;
						});
					}
				}());
			</script>
		</section>
		<?php
	}

	public function export_download() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to export GEDCOM data.', 'family-wiki' ) );
		}
		check_admin_referer( 'family_wiki_gedcom_export' );

		$filename = sanitize_file_name( wp_parse_url( home_url(), PHP_URL_HOST ) . '-family-wiki-' . current_time( 'Ymd-His' ) . '.ged' );

		nocache_headers();
		header( 'Content-Type: text/plain; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		echo $this->export_string();
		exit;
	}

	public function import_upload() {
		if ( ! current_user_can( 'import' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to import GEDCOM data.', 'family-wiki' ) );
		}
		check_admin_referer( 'family_wiki_gedcom_import' );

		$redirect = admin_url( 'admin.php?import=family-wiki-gedcom' );

		// Report why the upload did not arrive, rather than asking for a file that was chosen.
		$upload_error = isset( $_FILES['gedcom']['error'] ) ? (int) $_FILES['gedcom']['error'] : UPLOAD_ERR_NO_FILE;
		if ( UPLOAD_ERR_OK !== $upload_error ) {
			$error = in_array( $upload_error, array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ? 'file_too_large' : 'upload_failed';
			if ( UPLOAD_ERR_NO_FILE === $upload_error ) {
				$error = 'missing_file';
			}
			wp_safe_redirect( add_query_arg( 'family_wiki_error', $error, $redirect ) );
			exit;
		}

		if ( empty( $_FILES['gedcom']['tmp_name'] ) || ! is_uploaded_file( $_FILES['gedcom']['tmp_name'] ) ) {
			wp_safe_redirect( add_query_arg( 'family_wiki_error', 'missing_file', $redirect ) );
			exit;
		}

		$contents = file_get_contents( $_FILES['gedcom']['tmp_name'] );
		if ( false === $contents || '' === trim( $contents ) ) {
			wp_safe_redirect( add_query_arg( 'family_wiki_error', 'empty_file', $redirect ) );
			exit;
		}

		$records = $this->parse_records( $contents );
		if ( empty( $records['INDI'] ) ) {
			wp_safe_redirect( add_query_arg( 'family_wiki_error', 'no_individuals', $redirect ) );
			exit;
		}

		// The token travels back through sanitize_key(), which lowercases it, so
		// only generate lowercase tokens: on a case sensitive database a mixed
		// case token would no longer find its own transient.
		$token = strtolower( wp_generate_password( 32, false, false ) );
		if ( ! $this->store_import_file( $token, $contents ) ) {
			// Say so here, rather than letting the review screen report the file as expired.
			wp_safe_redirect( add_query_arg( 'family_wiki_error', 'store_failed', $redirect ) );
			exit;
		}

		// Lets a companion content file uploaded in the same form park itself
		// under this same token, so it can join the import once it starts.
		$this->park_content_file( $token );

		wp_safe_redirect( add_query_arg( 'family_wiki_review', $token, $redirect ) );
		exit;
	}

	/**
	 * Park the uploaded file between the upload and the selection request.
	 *
	 * The files themselves stay on disk and only their location goes into
	 * the transient: a GEDCOM is easily hundreds of kilobytes, which is more
	 * than belongs in an option row, and more than some databases will
	 * accept there. One transient covers both the GEDCOM file and its
	 * companion content file, since they always arrive and leave together.
	 */
	private function store_import_file( $token, $contents ) {
		$path = $this->write_temp_file( 'family-wiki-gedcom-' . $token, $contents );

		return $path && $this->save_import_state( $token, array( 'gedcom' => $path ) );
	}

	/**
	 * Park a companion content file uploaded alongside a GEDCOM file, under
	 * the same token. Silently does nothing when no content file came
	 * along, or it failed: it is optional, and should never be the reason a
	 * GEDCOM import fails.
	 */
	private function park_content_file( $token ) {
		if ( empty( $_FILES['content']['tmp_name'] ) || UPLOAD_ERR_OK !== (int) $_FILES['content']['error'] || ! is_uploaded_file( $_FILES['content']['tmp_name'] ) ) {
			return;
		}

		$contents = file_get_contents( $_FILES['content']['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $contents || '' === trim( $contents ) || is_wp_error( $this->parse_content_xml( $contents ) ) ) {
			return;
		}

		$path = $this->write_temp_file( 'family-wiki-content-' . $token, $contents );
		if ( ! $path ) {
			return;
		}

		$this->save_import_state(
			$token,
			array(
				'content'         => $path,
				'download_images' => ! empty( $_POST['download_images'] ),
			)
		);
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
	 * file and a companion content file share one transient even though
	 * they arrive as two separate temp files, possibly in two requests.
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
		if ( ! current_user_can( 'import' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to import GEDCOM data.', 'family-wiki' ) );
		}
		check_admin_referer( 'family_wiki_gedcom_import_selected' );

		$redirect = admin_url( 'admin.php?import=family-wiki-gedcom' );
		$token    = isset( $_POST['family_wiki_review'] ) ? sanitize_key( wp_unslash( $_POST['family_wiki_review'] ) ) : '';
		$contents = $token ? $this->get_import_file( $token ) : false;
		if ( false === $contents ) {
			wp_safe_redirect( add_query_arg( 'family_wiki_error', 'review_expired', $redirect ) );
			exit;
		}

		$selected = isset( $_POST['family_wiki_people'] ) && is_array( $_POST['family_wiki_people'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['family_wiki_people'] ) ) : array();
		$result   = $this->import_string( $contents, $selected );
		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg( 'family_wiki_error', $result->get_error_code(), add_query_arg( 'family_wiki_review', $token, $redirect ) ) );
			exit;
		}

		// Lets a companion content file uploaded alongside this one apply
		// itself now, while it is still parked: the no-JS path has no
		// per-person step for it to ride along with, so it goes in as one
		// whole-file pass, once, before the files are cleaned up.
		$query_args = $this->add_content_to_import_extra_args(
			array(
				'family_wiki_imported' => $result['created'],
				'family_wiki_updated'  => $result['updated'],
			),
			$token,
			$result['ids']
		);

		$this->delete_import_file( $token );

		wp_safe_redirect( add_query_arg( $query_args, $redirect ) );
		exit;
	}

	public function export_string() {
		$people = $this->get_export_people();
		$ids    = $this->export_xref_map( $people );

		$families = $this->get_export_families( $people, $ids );
		$lines    = array(
			'0 HEAD',
			'1 SOUR Family Wiki',
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

		$this->suspend_nav_menu_auto_add();

		foreach ( $records['INDI'] as $xref => $record ) {
			$person = $this->import_person( $xref, $record, $index, $claimed );
			if ( is_wp_error( $person ) ) {
				$this->restore_nav_menu_auto_add();
				return $person;
			}

			$id_map[ $xref ] = $person['id'];
			if ( $person['existed'] ) {
				++$updated;
			} else {
				++$created;
			}
		}

		$this->restore_nav_menu_auto_add();

		$this->import_family_links( isset( $records['FAM'] ) ? $records['FAM'] : array(), $id_map );
		Calendar::flush_dates_cache();
		Front_Page::flush_cache();

		return array(
			'created' => $created,
			'updated' => $updated,
			'ids'     => $id_map,
		);
	}

	/**
	 * Create or update the one page a GEDCOM individual record becomes.
	 * Split out from import_string() so a batched import can call it a
	 * person at a time.
	 *
	 * @return array|\WP_Error array( 'id' => int, 'existed' => bool ).
	 */
	private function import_person( $xref, $record, $index, &$claimed ) {
		$title   = $this->gedcom_name_to_title( $this->first_value( $record, 'NAME' ) );
		$post_id = $this->find_person_post( $xref, $title, $index, $claimed, $this->gedcom_birth_year( $record ) );
		$existed = (bool) $post_id;
		$data    = array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => $title ? $title : $xref,
			'post_content' => '',
		);

		if ( $existed ) {
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

	private function suspend_nav_menu_auto_add() {
		$this->nav_menu_auto_add_priority = has_action( 'transition_post_status', '_wp_auto_add_pages_to_menu' );

		if ( false !== $this->nav_menu_auto_add_priority ) {
			remove_action( 'transition_post_status', '_wp_auto_add_pages_to_menu', $this->nav_menu_auto_add_priority );
		}
	}

	private function restore_nav_menu_auto_add() {
		if ( false !== $this->nav_menu_auto_add_priority ) {
			add_action( 'transition_post_status', '_wp_auto_add_pages_to_menu', $this->nav_menu_auto_add_priority, 3 );
			$this->nav_menu_auto_add_priority = false;
		}
	}

	/**
	 * Assign each exported person an xref, in the order they will appear in
	 * the GEDCOM. Shared with export_content_string(), so a person gets the
	 * same xref in both files.
	 */
	private function export_xref_map( $people ) {
		$ids = array();
		$i   = 1;
		foreach ( $people as $person ) {
			$ids[ $person->ID ] = 'I' . $i++;
		}

		return $ids;
	}

	public function get_export_people() {
		$pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		return array_values( array_filter( $pages, array( $this, 'has_person_data' ) ) );
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
				$key = ( $father ? $father : 0 ) . ':' . ( $mother ? $mother : 0 );
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
			'xref'  => array(),
			'title' => array(),
		);

		$pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		foreach ( $pages as $page_id ) {
			$title = strtolower( trim( get_the_title( $page_id ) ) );
			// Every page with this title, not just the first: several people in a
			// GEDCOM commonly share a name, and each needs its own page.
			if ( $title ) {
				$index['title'][ $title ][] = $page_id;
			}

			$xref = get_post_meta( $page_id, self::XREF_META, true );
			if ( $xref ) {
				$index['xref'][ $xref ] = $page_id;
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
					'marriage_date'  => $event['date'] ? str_replace( '-', '', $event['date'] ) : '',
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
		if ( function_exists( 'get_field' ) ) {
			return get_field( $field, $post_id );
		}

		return get_post_meta( $post_id, $field, true );
	}

	private function update_field( $field, $value, $post_id ) {
		if ( function_exists( 'update_field' ) ) {
			update_field( isset( $this->field_keys[ $field ] ) ? $this->field_keys[ $field ] : $field, $value, $post_id );
			return;
		}

		update_post_meta( $post_id, $field, $value );
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
	 * The content file: the text written on a page, which GEDCOM has no
	 * room for. It always rides along with a GEDCOM file, uploaded in the
	 * same form and applied to each person right after the person
	 * themselves, matched by the same xref. It only ever updates a page
	 * that already exists; it never creates one.
	 * ------------------------------------------------------------------
	 */

	/**
	 * Grouped with the GEDCOM download button, not a section of its own.
	 */
	private function render_content_export_button() {
		?>
		<p><?php esc_html_e( 'The content file carries the page text GEDCOM has no room for, including any additional pages under a person.', 'family-wiki' ); ?></p>
		<form class="family-wiki-download-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::CONTENT_EXPORT_ACTION ); ?>" />
			<?php wp_nonce_field( self::CONTENT_EXPORT_ACTION ); ?>
			<?php submit_button( __( 'Download Content', 'family-wiki' ), 'primary', 'submit', false ); ?>
			<span class="family-wiki-download-check" aria-hidden="true" hidden>&#10003;</span>
		</form>
		<?php
	}

	/**
	 * The optional content field inside the GEDCOM upload form, for
	 * importing both together in one step.
	 */
	private function render_content_field() {
		?>
		<p>
			<label>
				<?php esc_html_e( 'Content file (optional)', 'family-wiki' ); ?><br />
				<input type="file" name="content" accept=".xml,text/xml" />
			</label>
		</p>
		<p>
			<label>
				<input type="checkbox" name="download_images" value="1" />
				<?php esc_html_e( 'Also download images into the media library and use them as the page photo', 'family-wiki' ); ?>
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

		$by_xref = array();
		foreach ( $xml->channel->item as $item ) {
			$wp_fields = $item->children( 'http://wordpress.org/export/1.2/' );
			foreach ( $wp_fields->postmeta as $meta ) {
				$meta_fields = $meta->children( 'http://wordpress.org/export/1.2/' );
				if ( self::CONTENT_META_KEY === (string) $meta_fields->meta_key ) {
					$by_xref[ (string) $meta_fields->meta_value ] = $item;
					break;
				}
			}
		}

		$this->content_index_cache[ $token ] = array(
			'by_xref'         => $by_xref,
			'download_images' => $this->content_download_images_requested( $token ),
		);

		return $this->content_index_cache[ $token ];
	}

	/**
	 * If this run's token has a content file, give the run somewhere to
	 * keep its counts as people are imported.
	 */
	private function add_content_to_run_state( $state, $token ) {
		if ( ! $this->content_index( $token ) ) {
			return $state;
		}

		$state['content'] = array(
			'updated' => 0,
			'images'  => 0,
		);

		return $state;
	}

	/**
	 * Applies one person's text right after the person themselves, using
	 * the post that was just resolved directly — there is nothing left to
	 * match, since this is the exact page that xref just became.
	 */
	private function apply_content_to_person( $state, $xref, $post_id ) {
		if ( empty( $state['content'] ) || empty( $state['token'] ) ) {
			return $state;
		}

		$index = $this->content_index( $state['token'] );
		if ( ! $index || ! isset( $index['by_xref'][ $xref ] ) ) {
			return $state;
		}

		$result = $this->apply_content_item_to_post( $index['by_xref'][ $xref ], $post_id, $index['download_images'] );
		++$state['content']['updated'];
		$state['content']['images'] += $result['images'];

		return $state;
	}

	private function add_content_to_finish_message( $message, $state ) {
		if ( empty( $state['content'] ) ) {
			return $message;
		}

		$message .= ' ' . sprintf(
			// translators: %d is a number of pages whose text was applied.
			__( 'Applied text from the content file to %d of them.', 'family-wiki' ),
			$state['content']['updated']
		);

		if ( $state['content']['images'] ) {
			$message .= ' ' . sprintf(
				// translators: %d is a number of downloaded images.
				__( 'Downloaded %d images into the media library.', 'family-wiki' ),
				$state['content']['images']
			);
		}

		return $message;
	}

	private function add_content_to_finish_query_args( $args, $state ) {
		if ( empty( $state['content'] ) ) {
			return $args;
		}

		return array_merge(
			$args,
			array(
				'family_wiki_content_updated' => $state['content']['updated'],
				'family_wiki_content_images'  => $state['content']['images'],
			)
		);
	}

	/**
	 * The no-JS path has no per-person step for a content file to ride
	 * along with, so it goes in as one whole-file pass instead, matched by
	 * xref or title the same way import_string() matches GEDCOM entries.
	 * Called while the file is still parked, before delete_import_file().
	 *
	 * @param array  $args    Extra query args to redirect with.
	 * @param string $token   The review token this import used.
	 * @param array  $id_map  Xref => post ID, from this same import_string() call.
	 */
	private function add_content_to_import_extra_args( $args, $token, $id_map ) {
		$contents = $this->get_content_file( $token );
		if ( false === $contents ) {
			return $args;
		}

		$result = $this->apply_content( $contents, $this->content_download_images_requested( $token ) );
		if ( is_wp_error( $result ) ) {
			return $args;
		}

		$this->resolve_content_links( $token, $id_map );

		return array_merge(
			$args,
			array(
				'family_wiki_content_updated' => $result['updated'],
				'family_wiki_content_images'  => $result['images'],
			)
		);
	}

	public function export_content_download() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to export content.', 'family-wiki' ) );
		}
		check_admin_referer( self::CONTENT_EXPORT_ACTION );

		$filename = sanitize_file_name( wp_parse_url( home_url(), PHP_URL_HOST ) . '-family-wiki-content-' . current_time( 'Ymd-His' ) . '.xml' );

		nocache_headers();
		header( 'Content-Type: text/xml; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		echo $this->export_content_string();
		exit;
	}

	private function export_content_string() {
		$people    = $this->get_export_people();
		$ids       = $this->export_xref_map( $people );
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
	 * @param \WP_Post $post       The person or additional page being exported.
	 * @param array    $link_keys  Post ID => the key a link to it should
	 *                             record, covering people and additional
	 *                             pages alike.
	 * @param array    $meta_pairs Meta key => value to identify this item.
	 * @param string   $post_name  The item's own slug, when it needs one to
	 *                             be found again on re-import.
	 */
	private function export_content_item_lines( $post, $link_keys, $meta_pairs, $post_name = '' ) {
		$links   = array();
		$content = $this->normalize_wiki_links( $post->post_content );
		$content = $this->relativize_images( $content );
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
	 * @param array      $ids    Post ID => xref, from export_xref_map().
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
				'post_type'      => 'page',
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
	 * This site's own image URLs referenced in a page's text, so the
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
	 * Some pages still carry links in the shape a MediaWiki site generates,
	 * left over from an earlier migration — including a "red link" pointing
	 * at an edit screen for a page that had not been written yet
	 * (index.php?title=X&action=edit&redlink=1). Rewritten here into a
	 * plain same-site link by slugified title, so relativize_links() below
	 * resolves it exactly like any other internal link — to another
	 * exported person if there is one, or otherwise to an inert relative
	 * link — instead of every consumer of this file having to understand
	 * MediaWiki's own URL shape.
	 */
	private function normalize_wiki_links( $content ) {
		return preg_replace_callback(
			'/href=(["\'])(?:[^"\']*\/)?index\.php\?[^"\']*\btitle=([^&"\']+)[^"\']*\1/i',
			function ( $matches ) {
				$title = sanitize_title_with_dashes( str_replace( '_', ' ', urldecode( $matches[2] ) ) );

				return 'href=' . $matches[1] . home_url( '/' . $title ) . $matches[1];
			},
			$content
		);
	}

	/**
	 * Strips this site's own domain from same-site image references in a
	 * page's text, so the file does not silently hotlink a private wiki's
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
	 * Strips this site's own domain from same-site links in a page's text,
	 * the same reason relativize_images() does it for photos. Where a link
	 * points to another page in this same export, its path and the xref it
	 * points to are collected into $links by reference, so the importer can
	 * put back a working link once it knows where that page landed there —
	 * a page's own URL structure is not something a content file can know
	 * in advance, especially crossing between two different plugins.
	 */
	private function relativize_links( $content, $ids, &$links ) {
		$home = home_url();

		return preg_replace_callback(
			'/(<a\s[^>]*href=["\'])' . preg_quote( $home, '/' ) . '([^"\']*)(["\'])/i',
			function ( $matches ) use ( $home, $ids, &$links ) {
				$path    = $matches[2];
				$post_id = url_to_postid( $home . $path );
				if ( $post_id && isset( $ids[ $post_id ] ) ) {
					$links[ $path ] = $ids[ $post_id ];
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
	private function resolve_content_links( $token, $id_map ) {
		$index = $this->content_index( $token );
		if ( ! $index ) {
			return;
		}

		foreach ( $id_map as $xref => $post_id ) {
			if ( ! isset( $index['by_xref'][ $xref ] ) ) {
				continue;
			}

			$links = $this->content_links_for_item( $index['by_xref'][ $xref ] );
			if ( ! $links ) {
				continue;
			}

			$replacements = array();
			foreach ( $links as $path => $target_xref ) {
				if ( isset( $id_map[ $target_xref ] ) ) {
					$replacements[ $path ] = get_permalink( $id_map[ $target_xref ] );
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
	 * Apply a content file to the pages it matches. Never creates a page
	 * and never touches anything but post_content — and, when asked, a
	 * page's photo. Used for the no-JS whole-file path only; a batched
	 * GEDCOM import applies each item as its own person is resolved.
	 *
	 * @param string $contents        The content file.
	 * @param bool   $download_images Whether to fetch each matched page's
	 *                                image into the media library and set
	 *                                it as the page's photo. Off by default:
	 *                                this is the one part of the file that
	 *                                makes an outbound request, to whatever
	 *                                URL the file names.
	 */
	private function apply_content( $contents, $download_images = false ) {
		$xml = $this->parse_content_xml( $contents );
		if ( is_wp_error( $xml ) ) {
			return $xml;
		}

		$index   = $this->existing_page_index();
		$updated = 0;
		$skipped = 0;
		$images  = 0;

		foreach ( $xml->channel->item as $item ) {
			$result = $this->apply_content_item( $item, $index, $download_images );
			if ( $result['matched'] ) {
				++$updated;
			} else {
				++$skipped;
			}
			$images += $result['images'];
		}

		return array(
			'updated' => $updated,
			'skipped' => $skipped,
			'images'  => $images,
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
			return new \WP_Error( 'invalid_file', __( 'This does not look like a Family Wiki content file.', 'family-wiki' ), array( 'status' => 400 ) );
		}

		return $xml;
	}

	/**
	 * Apply one <item> to the page it matches.
	 *
	 * @return array array( 'matched' => bool, 'images' => int ).
	 */
	private function apply_content_item( $item, $index, $download_images ) {
		$wp_fields = $item->children( 'http://wordpress.org/export/1.2/' );
		$title     = trim( (string) $item->title );

		$post_id = $this->match_content_post( $wp_fields, $title, $index );
		if ( ! $post_id ) {
			return array(
				'matched' => false,
				'images'  => 0,
			);
		}

		return array_merge(
			array( 'matched' => true ),
			$this->apply_content_item_to_post( $item, $post_id, $download_images )
		);
	}

	/**
	 * Apply one <item> to a page already known to be its match — the person
	 * a GEDCOM import just resolved, when a content file rides along with
	 * it. Split out from apply_content_item() since there is nothing left
	 * to match there.
	 *
	 * @return array array( 'images' => int ).
	 */
	private function apply_content_item_to_post( $item, $post_id, $download_images ) {
		$wp_fields  = $item->children( 'http://wordpress.org/export/1.2/' );
		$content_ns = $item->children( 'http://purl.org/rss/1.0/modules/content/' );
		$content    = isset( $content_ns->encoded ) ? (string) $content_ns->encoded : '';
		$image_url  = isset( $wp_fields->attachment_url ) ? trim( (string) $wp_fields->attachment_url ) : '';
		$images     = 0;

		if ( $download_images ) {
			if ( $image_url ) {
				$attachment_id = $this->sideload_image( $image_url, $post_id );
				if ( $attachment_id ) {
					set_post_thumbnail( $post_id, $attachment_id );
					++$images;
				}
			}

			foreach ( $wp_fields->content_image_url as $node ) {
				$url = trim( (string) $node );
				if ( ! $url ) {
					continue;
				}
				$attachment_id = $this->sideload_image( $url, $post_id );
				if ( $attachment_id ) {
					$content = $this->replace_image_reference( $content, $url, wp_get_attachment_url( $attachment_id ) );
					++$images;
				}
			}
		}

		wp_update_post(
			wp_slash(
				array(
					'ID'           => $post_id,
					'post_content' => $content,
				)
			)
		);

		return array( 'images' => $images );
	}

	/**
	 * Fetch an image into the media library, attached to the given page.
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
	 * The page a content entry belongs on: its xref first, an unambiguous
	 * exact title match otherwise. A title shared by more than one page is
	 * left alone rather than guessed at.
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
