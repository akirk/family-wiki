<?php
namespace Family_Wiki;

class GEDCOM {
	const MENU_SLUG = 'family-wiki-gedcom';
	const XREF_META = '_family_wiki_gedcom_xref';
	const IMPORT_TRANSIENT_PREFIX = 'family_wiki_gedcom_import_';

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
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="family_wiki_gedcom_export" />
				<?php wp_nonce_field( 'family_wiki_gedcom_export' ); ?>
				<?php submit_button( __( 'Download GEDCOM', 'family-wiki' ), 'primary', 'submit', false ); ?>
			</form>
			<hr />
			<h2><?php esc_html_e( 'Import', 'family-wiki' ); ?></h2>
			<p><?php esc_html_e( 'GEDCOM import is available from the standard WordPress import screen.', 'family-wiki' ); ?></p>
			<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?import=family-wiki-gedcom' ) ); ?>"><?php esc_html_e( 'Open Importer', 'family-wiki' ); ?></a></p>
		</div>
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
				<div class="notice notice-success"><p><?php echo esc_html( sprintf( __( 'GEDCOM import complete. Created %1$d pages and updated %2$d pages.', 'family-wiki' ), $imported, $updated ) ); ?></p></div>
			<?php endif; ?>
			<?php if ( $error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $this->error_message( $error ) ); ?></p></div>
			<?php endif; ?>
			<?php if ( $review ) : ?>
				<?php $this->render_import_review( $review ); ?>
			<?php else : ?>
				<p><?php esc_html_e( 'Upload a GEDCOM file, review the people in it, and choose which entries or descendant subtrees to import. Existing pages are matched by prior GEDCOM xref first, then by page title.', 'family-wiki' ); ?></p>
				<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin.php?import=family-wiki-gedcom&noheader=true' ) ); ?>">
					<?php wp_nonce_field( 'family_wiki_gedcom_import' ); ?>
					<input type="hidden" name="family_wiki_gedcom_step" value="upload" />
					<p><input type="file" name="gedcom" accept=".ged,.gedcom,text/plain" required /></p>
					<?php submit_button( __( 'Upload and review GEDCOM', 'family-wiki' ), 'primary', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_import_review( $token ) {
		$contents = get_transient( self::IMPORT_TRANSIENT_PREFIX . $token );
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

		$people      = $this->review_people( $records );
		$total       = count( $people );
		$family_count = empty( $records['FAM'] ) ? 0 : count( $records['FAM'] );
		?>
		<section class="family-wiki-gedcom-review">
			<h2><?php esc_html_e( 'Review GEDCOM import', 'family-wiki' ); ?></h2>
			<p><?php echo esc_html( sprintf( __( 'Found %1$d people and %2$d family records. Choose entries below, or use a person row to select that person and all known descendants.', 'family-wiki' ), $total, $family_count ) ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="family_wiki_gedcom_step" value="selected" />
				<input type="hidden" name="family_wiki_review" value="<?php echo esc_attr( $token ); ?>" />
				<?php wp_nonce_field( 'family_wiki_gedcom_import_selected' ); ?>
				<p>
					<button type="button" class="button" data-family-wiki-gedcom-select-all><?php esc_html_e( 'Select all', 'family-wiki' ); ?></button>
					<button type="button" class="button" data-family-wiki-gedcom-clear><?php esc_html_e( 'Clear selection', 'family-wiki' ); ?></button>
				</p>
				<table class="widefat striped">
					<thead>
						<tr>
							<th scope="col" class="check-column"><span class="screen-reader-text"><?php esc_html_e( 'Import', 'family-wiki' ); ?></span></th>
							<th scope="col"><?php esc_html_e( 'Person', 'family-wiki' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Birth', 'family-wiki' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Death', 'family-wiki' ); ?></th>
							<th scope="col"><?php esc_html_e( 'GEDCOM ID', 'family-wiki' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Subtree', 'family-wiki' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $people as $person ) : ?>
							<tr>
								<th scope="row" class="check-column">
									<input type="checkbox" name="family_wiki_people[]" value="<?php echo esc_attr( $person['xref'] ); ?>" data-family-wiki-gedcom-person="<?php echo esc_attr( $person['xref'] ); ?>" checked />
								</th>
								<td><strong><?php echo esc_html( $person['name'] ); ?></strong></td>
								<td><?php echo esc_html( $person['birth'] ); ?></td>
								<td><?php echo esc_html( $person['death'] ); ?></td>
								<td><code><?php echo esc_html( $person['xref'] ); ?></code></td>
								<td>
									<?php if ( ! empty( $person['descendants'] ) ) : ?>
										<button type="button" class="button button-small" data-family-wiki-gedcom-descendants="<?php echo esc_attr( implode( ',', array_merge( array( $person['xref'] ), $person['descendants'] ) ) ); ?>"><?php esc_html_e( 'Select subtree', 'family-wiki' ); ?></button>
									<?php else : ?>
										<span aria-hidden="true">-</span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php submit_button( __( 'Import selected people', 'family-wiki' ) ); ?>
			</form>
			<script>
				(function () {
					var review = document.querySelector('.family-wiki-gedcom-review');
					if (!review) {
						return;
					}
					function boxes() {
						return review.querySelectorAll('[data-family-wiki-gedcom-person]');
					}
					review.addEventListener('click', function (event) {
						var selectAll = event.target.closest('[data-family-wiki-gedcom-select-all]');
						var clear = event.target.closest('[data-family-wiki-gedcom-clear]');
						var descendants = event.target.closest('[data-family-wiki-gedcom-descendants]');
						if (selectAll || clear) {
							Array.prototype.forEach.call(boxes(), function (box) {
								box.checked = !!selectAll;
							});
							return;
						}
						if (!descendants) {
							return;
						}
						descendants.getAttribute('data-family-wiki-gedcom-descendants').split(',').forEach(function (xref) {
							var box = review.querySelector('[data-family-wiki-gedcom-person="' + xref + '"]');
							if (box) {
								box.checked = true;
							}
						});
					});
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

		$token = wp_generate_password( 20, false, false );
		set_transient( self::IMPORT_TRANSIENT_PREFIX . $token, $contents, HOUR_IN_SECONDS );

		wp_safe_redirect( add_query_arg( 'family_wiki_review', $token, $redirect ) );
		exit;
	}

	public function import_selected() {
		if ( ! current_user_can( 'import' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to import GEDCOM data.', 'family-wiki' ) );
		}
		check_admin_referer( 'family_wiki_gedcom_import_selected' );

		$redirect = admin_url( 'admin.php?import=family-wiki-gedcom' );
		$token    = isset( $_POST['family_wiki_review'] ) ? sanitize_key( wp_unslash( $_POST['family_wiki_review'] ) ) : '';
		$contents = $token ? get_transient( self::IMPORT_TRANSIENT_PREFIX . $token ) : false;
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

		delete_transient( self::IMPORT_TRANSIENT_PREFIX . $token );
		wp_safe_redirect(
			add_query_arg(
				array(
					'family_wiki_imported' => $result['created'],
					'family_wiki_updated'  => $result['updated'],
				),
				$redirect
			)
		);
		exit;
	}

	public function export_string() {
		$people = $this->get_export_people();
		$ids    = array();
		$used   = array();
		foreach ( $people as $person ) {
			$ids[ $person->ID ] = $this->person_xref( $person, $used );
		}

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

		foreach ( $records['INDI'] as $xref => $record ) {
			$title   = $this->gedcom_name_to_title( $this->first_value( $record, 'NAME' ) );
			$post_id = $this->find_person_post( $xref, $title );
			$data    = array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => $title ? $title : $xref,
				'post_content' => '[name_with_bio]',
			);

			if ( $post_id ) {
				$data['ID'] = $post_id;
				unset( $data['post_content'] );
				$result = wp_update_post( wp_slash( $data ), true );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$updated++;
			} else {
				$result = wp_insert_post( wp_slash( $data ), true );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$post_id = $result;
				$created++;
			}

			$id_map[ $xref ] = $post_id;
			update_post_meta( $post_id, self::XREF_META, $xref );
			$this->import_individual_fields( $post_id, $record );
		}

		$this->import_family_links( isset( $records['FAM'] ) ? $records['FAM'] : array(), $id_map );
		Calendar::flush_dates_cache();
		Front_Page::flush_cache();

		return array(
			'created' => $created,
			'updated' => $updated,
		);
	}

	private function get_export_people() {
		return get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
	}

	private function person_xref( $person, &$used ) {
		$xref = 'I' . $this->gedcom_slug_id( get_page_uri( $person->ID ) );
		if ( empty( $used[ $xref ] ) ) {
			$used[ $xref ] = true;
			return $xref;
		}

		$xref = 'I' . $this->gedcom_slug_id( get_page_uri( $person->ID ) . '-' . $person->ID );
		$used[ $xref ] = true;

		return $xref;
	}

	private function person_reference_numbers( $post_id, $xref ) {
		$references = array( $xref );
		$local_slug = trim( strtolower( get_page_uri( $post_id ) ), '/' );

		foreach ( Cross_Wiki::get_sites() as $site ) {
			$site_id = empty( $site['site_id'] ) ? 0 : absint( $site['site_id'] );
			if ( ! $site_id || empty( $site['slugs'] ) || ! is_array( $site['slugs'] ) || empty( $site['slugs'][ $local_slug ] ) ) {
				continue;
			}

			$remote_slug = trim( strtolower( $site['slugs'][ $local_slug ] ), '/' );
			if ( $this->remote_page_exists( $site_id, $remote_slug ) ) {
				$references[] = 'I' . $this->gedcom_slug_id( $remote_slug );
			}
		}

		$references = array_unique( array_filter( $references ) );
		sort( $references, SORT_STRING );

		return $references;
	}

	private function gedcom_slug_id( $slug ) {
		$slug = trim( strtolower( $slug ), '/' );
		$slug = preg_replace( '/[^a-z0-9]+/', '_', $slug );
		$slug = trim( $slug, '_' );

		return $slug ? strtoupper( $slug ) : 'PAGE';
	}

	private function remote_page_exists( $site_id, $slug ) {
		if ( ! function_exists( 'is_multisite' ) || ! is_multisite() || ! $site_id || ! $slug ) {
			return false;
		}

		switch_to_blog( $site_id );
		$page = get_page_by_path( $slug, OBJECT, 'page' );
		$exists = $page && 'publish' === $page->post_status;
		restore_current_blog();

		return $exists;
	}

	private function export_individual( $person, $xref, $families ) {
		$lines = array(
			'0 @' . $xref . '@ INDI',
			'1 NAME ' . $this->format_gedcom_name( get_the_title( $person ) ),
		);
		foreach ( $this->person_reference_numbers( $person->ID, $xref ) as $reference ) {
			$lines[] = '1 REFN ' . $reference;
		}

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
		$people      = array();

		foreach ( $records['INDI'] as $xref => $record ) {
			$birth = $this->event_values( $record, 'BIRT' );
			$death = $this->event_values( $record, 'DEAT' );
			$people[] = array(
				'xref'        => $xref,
				'name'        => $this->gedcom_name_to_title( $this->first_value( $record, 'NAME' ) ),
				'birth'       => $this->review_event_label( $birth ),
				'death'       => $this->review_event_label( $death ),
				'descendants' => isset( $descendants[ $xref ] ) ? $descendants[ $xref ] : array(),
			);
		}

		usort( $people, array( $this, 'sort_review_people' ) );

		return $people;
	}

	private function sort_review_people( $a, $b ) {
		return strcasecmp( $a['name'], $b['name'] );
	}

	private function descendants_by_person( $families ) {
		$children_by_parent = array();
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
			}
		}

		$descendants = array();
		foreach ( array_keys( $children_by_parent ) as $xref ) {
			$descendants[ $xref ] = array_values( $this->collect_descendants( $xref, $children_by_parent ) );
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
			$parts[] = $event['approximate'] ? sprintf( __( 'about %s', 'family-wiki' ), $event['date'] ) : $event['date'];
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

	private function find_person_post( $xref, $title ) {
		$posts = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => self::XREF_META,
				'meta_value'     => $xref,
			)
		);
		if ( ! empty( $posts ) ) {
			return (int) $posts[0];
		}
		if ( ! $title ) {
			return 0;
		}

		$page = get_page_by_title( $title, OBJECT, 'page' );
		return $page ? (int) $page->ID : 0;
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
			'empty_file'     => __( 'The uploaded GEDCOM file was empty.', 'family-wiki' ),
			'no_individuals' => __( 'The GEDCOM file does not contain individual records.', 'family-wiki' ),
			'no_selection'   => __( 'Please select at least one GEDCOM person to import.', 'family-wiki' ),
			'review_expired' => __( 'The GEDCOM import review expired. Please upload the file again.', 'family-wiki' ),
		);

		return isset( $messages[ $error ] ) ? $messages[ $error ] : __( 'The GEDCOM import failed.', 'family-wiki' );
	}
}
