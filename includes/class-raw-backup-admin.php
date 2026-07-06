<?php
/**
 * Admin UI: Tools → RAW Backup.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Raw_Backup_Admin {

	const CAP  = 'manage_options';
	const PAGE = 'raw-backup';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_rawbk_export', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_rawbk_import', array( __CLASS__, 'handle_import' ) );
		add_action( 'admin_post_rawbk_download', array( __CLASS__, 'handle_download' ) );
		add_action( 'admin_post_rawbk_delete', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_rawbk_settings', array( __CLASS__, 'handle_settings' ) );
		add_action( 'wp_ajax_rawbk_progress', array( __CLASS__, 'handle_progress' ) );
	}

	public static function register_menu() {
		add_management_page(
			__( 'RAW Backup', 'raw-backup' ),
			__( 'RAW Backup', 'raw-backup' ),
			self::CAP,
			self::PAGE,
			array( __CLASS__, 'render_page' )
		);
	}

	/* ---------------------------------------------------------------------
	 * Page
	 * ------------------------------------------------------------------ */

	public static function render_page() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'raw-backup' ) );
		}

		$backups = self::list_backups();
		self::render_notices();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'RAW Backup', 'raw-backup' ); ?></h1>
			<p><?php esc_html_e( 'Export and import full-site backups (database + wp-content) as raw ZIP archives, compatible with the WordPress Studio backup format.', 'raw-backup' ); ?></p>

			<style>
				.rawbk-card { background: #fff; border: 1px solid #c3c4c7; box-shadow: 0 1px 1px rgba(0,0,0,.04); padding: 16px 20px 20px; margin-top: 16px; max-width: 900px; }
				.rawbk-card h2 { margin-top: 0; }
				.rawbk-warning { color: #b32d2e; }
				.rawbk-actions form { display: inline; }
				.rawbk-table td, .rawbk-table th { vertical-align: middle; }
				.rawbk-retention { margin: 0 0 14px; padding-bottom: 12px; border-bottom: 1px solid #f0f0f1; }
				.rawbk-retention label { margin-right: 8px; }
				.rawbk-retention .description { margin-top: 6px; }
				#rawbk-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.55); z-index: 100000; align-items: center; justify-content: center; }
				.rawbk-modal { background: #fff; border-radius: 4px; padding: 24px 28px; width: min(440px, 90vw); box-shadow: 0 3px 30px rgba(0,0,0,.3); }
				.rawbk-modal h2 { margin: 0 0 14px; }
				.rawbk-bar { background: #dcdcde; border-radius: 3px; height: 18px; overflow: hidden; }
				.rawbk-bar-fill { background: #2271b1; height: 100%; width: 0; border-radius: 3px; transition: width .5s ease;
					background-image: linear-gradient(45deg, rgba(255,255,255,.18) 25%, transparent 25%, transparent 50%, rgba(255,255,255,.18) 50%, rgba(255,255,255,.18) 75%, transparent 75%);
					background-size: 24px 24px; animation: rawbk-stripes 1s linear infinite; }
				@keyframes rawbk-stripes { from { background-position: 0 0; } to { background-position: 24px 0; } }
				.rawbk-bar-meta { display: flex; justify-content: space-between; gap: 12px; margin-top: 8px; font-size: 13px; color: #50575e; }
				#rawbk-bar-msg { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
			</style>

			<div class="rawbk-card">
				<h2><?php esc_html_e( 'Export', 'raw-backup' ); ?></h2>
				<p><?php esc_html_e( 'Creates a ZIP containing meta.json, a credential-free wp-config.php template, a full database dump (sql/) and the wp-content directory.', 'raw-backup' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-rawbk="export">
					<input type="hidden" name="action" value="rawbk_export" />
					<input type="hidden" name="rawbk_job" value="" />
					<?php wp_nonce_field( 'rawbk_export' ); ?>
					<?php submit_button( __( 'Create backup now', 'raw-backup' ), 'primary', 'submit', false ); ?>
				</form>
			</div>

			<div class="rawbk-card">
				<h2><?php esc_html_e( 'Backups', 'raw-backup' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rawbk-retention">
					<input type="hidden" name="action" value="rawbk_settings" />
					<?php wp_nonce_field( 'rawbk_settings' ); ?>
					<label>
						<?php esc_html_e( 'Keep only the last', 'raw-backup' ); ?>
						<input type="number" name="rawbk_keep" min="0" max="100" step="1"
							value="<?php echo esc_attr( rawbk_retention_limit() ); ?>" style="width:70px;" />
						<?php esc_html_e( 'backups', 'raw-backup' ); ?>
					</label>
					<?php submit_button( __( 'Save', 'raw-backup' ), 'small', 'submit', false ); ?>
					<p class="description"><?php esc_html_e( 'Older backups are deleted automatically when a new one is created. Use 0 to keep everything. The file being imported is never deleted.', 'raw-backup' ); ?></p>
				</form>
				<?php if ( empty( $backups ) ) : ?>
					<p><?php esc_html_e( 'No backups yet.', 'raw-backup' ); ?></p>
				<?php else : ?>
					<table class="widefat striped rawbk-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'File', 'raw-backup' ); ?></th>
								<th><?php esc_html_e( 'Size', 'raw-backup' ); ?></th>
								<th><?php esc_html_e( 'Date', 'raw-backup' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'raw-backup' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $backups as $backup ) : ?>
								<tr>
									<td><code><?php echo esc_html( $backup['name'] ); ?></code></td>
									<td><?php echo esc_html( size_format( $backup['size'] ) ); ?></td>
									<td><?php echo esc_html( date_i18n( 'Y-m-d H:i', $backup['mtime'] ) ); ?></td>
									<td class="rawbk-actions">
										<a class="button button-small" href="<?php echo esc_url( self::action_url( 'rawbk_download', $backup['name'] ) ); ?>">
											<?php esc_html_e( 'Download', 'raw-backup' ); ?>
										</a>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-rawbk="import"
											data-confirm="<?php echo esc_attr__( 'Import this backup? The database and wp-content of THIS site will be replaced. A safety backup will be created first.', 'raw-backup' ); ?>">
											<input type="hidden" name="action" value="rawbk_import" />
											<input type="hidden" name="rawbk_existing" value="<?php echo esc_attr( $backup['name'] ); ?>" />
											<input type="hidden" name="rawbk_confirm" value="1" />
											<input type="hidden" name="rawbk_job" value="" />
											<?php wp_nonce_field( 'rawbk_import' ); ?>
											<button type="submit" class="button button-small"><?php esc_html_e( 'Import', 'raw-backup' ); ?></button>
										</form>
										<a class="button button-small" style="color:#b32d2e;"
											href="<?php echo esc_url( self::action_url( 'rawbk_delete', $backup['name'] ) ); ?>"
											onclick="return confirm('<?php echo esc_js( __( 'Delete this backup file?', 'raw-backup' ) ); ?>');">
											<?php esc_html_e( 'Delete', 'raw-backup' ); ?>
										</a>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<div class="rawbk-card">
				<h2><?php esc_html_e( 'Import', 'raw-backup' ); ?></h2>
				<p class="rawbk-warning">
					<strong><?php esc_html_e( 'Warning:', 'raw-backup' ); ?></strong>
					<?php esc_html_e( 'Importing replaces the database and wp-content of this site. A safety backup of the current site is created automatically first. Your session will end — you will need to log in again with the credentials of the imported site.', 'raw-backup' ); ?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" data-rawbk="import">
					<input type="hidden" name="action" value="rawbk_import" />
					<input type="hidden" name="rawbk_job" value="" />
					<?php wp_nonce_field( 'rawbk_import' ); ?>
					<p>
						<input type="file" name="rawbk_zip" accept=".zip" required />
						<span class="description">
							<?php
							printf(
								/* translators: %s: maximum upload size */
								esc_html__( 'Maximum upload size: %s. Larger files can be placed in uploads/raw-backup/ and imported from the list above.', 'raw-backup' ),
								esc_html( size_format( wp_max_upload_size() ) )
							);
							?>
						</span>
					</p>
					<p>
						<label>
							<input type="checkbox" name="rawbk_confirm" value="1" required />
							<?php esc_html_e( 'I understand this will overwrite this site.', 'raw-backup' ); ?>
						</label>
					</p>
					<?php submit_button( __( 'Import backup', 'raw-backup' ), 'primary', 'submit', false ); ?>
				</form>
			</div>

			<div id="rawbk-overlay">
				<div class="rawbk-modal">
					<h2 id="rawbk-overlay-title"></h2>
					<div class="rawbk-bar"><div class="rawbk-bar-fill" id="rawbk-bar-fill"></div></div>
					<div class="rawbk-bar-meta">
						<span id="rawbk-bar-msg"><?php esc_html_e( 'Starting…', 'raw-backup' ); ?></span>
						<span id="rawbk-bar-pct">0%</span>
					</div>
					<p class="description"><?php esc_html_e( 'Do not close this tab.', 'raw-backup' ); ?></p>
				</div>
			</div>
		</div>

		<script>
		( function () {
			var cfg = {
				ajaxUrl:    <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
				nonce:      <?php echo wp_json_encode( wp_create_nonce( 'rawbk_progress' ) ); ?>,
				tExport:    <?php echo wp_json_encode( __( 'Creating backup…', 'raw-backup' ) ); ?>,
				tImport:    <?php echo wp_json_encode( __( 'Importing backup…', 'raw-backup' ) ); ?>,
				finishing:  <?php echo wp_json_encode( __( 'Finishing… (the site is being switched over)', 'raw-backup' ) ); ?>,
				uploading:  <?php echo wp_json_encode( __( 'Uploading file… (%1$s MB of %2$s MB)', 'raw-backup' ) ); ?>,
				uploadFail: <?php echo wp_json_encode( __( 'Upload failed. Check your connection and try again.', 'raw-backup' ) ); ?>
			};
			var fillEl = document.getElementById( 'rawbk-bar-fill' ),
				msgEl  = document.getElementById( 'rawbk-bar-msg' ),
				pctEl  = document.getElementById( 'rawbk-bar-pct' ),
				lastPct = 0, failCount = 0, pollBase = 0, pollSpan = 100, pollTimer = null;

			function setBar( percent, message ) {
				lastPct = Math.max( lastPct, Math.min( 100, percent ) );
				fillEl.style.width = lastPct + '%';
				pctEl.textContent  = Math.round( lastPct ) + '%';
				if ( message ) { msgEl.textContent = message; }
			}

			function poll( job ) {
				var url = cfg.ajaxUrl + '?action=rawbk_progress&nonce=' + encodeURIComponent( cfg.nonce ) +
					'&job=' + encodeURIComponent( job ) + '&_=' + Date.now();
				fetch( url, { credentials: 'same-origin' } )
					.then( function ( res ) {
						if ( ! res.ok ) { throw new Error( 'http' ); }
						return res.json();
					} )
					.then( function ( data ) {
						failCount = 0;
						if ( data && typeof data.percent === 'number' ) {
							setBar( pollBase + data.percent * pollSpan / 100, data.message || null );
						}
					} )
					.catch( function () {
						// During an import the login session dies near the end;
						// polls start failing while the server finishes up.
						failCount++;
						if ( failCount > 3 && lastPct > 40 ) {
							msgEl.textContent = cfg.finishing;
						}
					} );
			}

			function startPolling( job ) {
				pollTimer = window.setInterval( function () { poll( job ); }, 900 );
			}

			Array.prototype.forEach.call( document.querySelectorAll( 'form[data-rawbk]' ), function ( form ) {
				form.addEventListener( 'submit', function ( event ) {
					var confirmText = form.getAttribute( 'data-confirm' );
					if ( confirmText && ! window.confirm( confirmText ) ) {
						event.preventDefault();
						return;
					}
					var job = 'j' + Date.now().toString( 36 ) + Math.random().toString( 36 ).slice( 2, 10 );
					form.querySelector( 'input[name="rawbk_job"]' ).value = job;

					document.getElementById( 'rawbk-overlay-title' ).textContent =
						form.getAttribute( 'data-rawbk' ) === 'export' ? cfg.tExport : cfg.tImport;
					document.getElementById( 'rawbk-overlay' ).style.display = 'flex';

					var fileInput = form.querySelector( 'input[type="file"][name="rawbk_zip"]' );
					var useXhr    = fileInput && fileInput.files && fileInput.files.length &&
						window.FormData && window.XMLHttpRequest;

					if ( ! useXhr ) {
						startPolling( job );
						return; // Normal navigation submit.
					}

					// Upload via XHR so the upload itself has real progress:
					// upload maps to 0–30% of the bar, server work to 30–100%.
					event.preventDefault();
					pollBase = 30;
					pollSpan = 70;

					var xhr = new XMLHttpRequest();
					// NOT form.action: the hidden input named "action" that
					// admin-post.php requires shadows the form's action
					// property and would coerce to "[object HTMLInputElement]".
					xhr.open( 'POST', form.getAttribute( 'action' ) );
					xhr.upload.onprogress = function ( ev ) {
						if ( ev.lengthComputable ) {
							var mb = function ( n ) { return ( n / 1048576 ).toFixed( 1 ); };
							setBar(
								30 * ev.loaded / ev.total,
								cfg.uploading.replace( '%1$s', mb( ev.loaded ) ).replace( '%2$s', mb( ev.total ) )
							);
						}
					};
					xhr.onload = function () {
						if ( pollTimer ) { window.clearInterval( pollTimer ); }
						if ( xhr.status < 200 || xhr.status >= 400 ) {
							msgEl.textContent = cfg.uploadFail + ' (HTTP ' + xhr.status + ')';
							return;
						}
						// The response is the final result screen (or an error
						// page); swap the document for it, like a navigation.
						document.open();
						document.write( xhr.responseText );
						document.close();
					};
					xhr.onerror = function () {
						if ( pollTimer ) { window.clearInterval( pollTimer ); }
						msgEl.textContent = cfg.uploadFail;
					};
					xhr.send( new FormData( form ) );
					startPolling( job );
				} );
			} );
		} )();
		</script>
		<?php
	}

	private static function render_notices() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice codes.
		$code = isset( $_GET['rawbk'] ) ? sanitize_key( $_GET['rawbk'] ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$file = isset( $_GET['f'] ) ? sanitize_file_name( wp_unslash( $_GET['f'] ) ) : '';

		if ( 'exported' === $code && $file ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s <code>%s</code> <a class="button button-small" href="%s">%s</a></p></div>',
				esc_html__( 'Backup created:', 'raw-backup' ),
				esc_html( $file ),
				esc_url( self::action_url( 'rawbk_download', $file ) ),
				esc_html__( 'Download', 'raw-backup' )
			);
		} elseif ( 'deleted' === $code ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'Backup deleted.', 'raw-backup' )
			);
		} elseif ( 'settings' === $code ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'Settings saved.', 'raw-backup' )
			);
		}

		$error = get_transient( 'rawbk_error_' . get_current_user_id() );
		if ( $error ) {
			delete_transient( 'rawbk_error_' . get_current_user_id() );
			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html( $error )
			);
		}
	}

	/* ---------------------------------------------------------------------
	 * Handlers
	 * ------------------------------------------------------------------ */

	public static function handle_export() {
		self::guard( 'rawbk_export' );
		self::begin_progress();

		$result = Raw_Backup_Exporter::run();
		if ( is_wp_error( $result ) ) {
			Raw_Backup_Progress::fail( $result->get_error_message() );
			self::redirect_with_error( $result->get_error_message() );
		}
		rawbk_apply_retention();
		Raw_Backup_Progress::done( __( 'Backup created.', 'raw-backup' ) );
		self::redirect( array( 'rawbk' => 'exported', 'f' => basename( $result ) ) );
	}

	public static function handle_settings() {
		self::guard( 'rawbk_settings' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() ran check_admin_referer().
		$keep = isset( $_POST['rawbk_keep'] ) ? absint( wp_unslash( $_POST['rawbk_keep'] ) ) : 5;
		update_option( 'rawbk_keep_backups', min( 100, $keep ) );
		self::redirect( array( 'rawbk' => 'settings' ) );
	}

	public static function handle_import() {
		self::guard( 'rawbk_import' );

		if ( empty( $_POST['rawbk_confirm'] ) ) {
			self::redirect_with_error( __( 'Import not confirmed.', 'raw-backup' ) );
		}

		// Resolve the source ZIP: an existing backup or a fresh upload.
		$zip_path = '';
		if ( ! empty( $_POST['rawbk_existing'] ) ) {
			$zip_path = self::resolve_backup_file( $_POST['rawbk_existing'] );
			if ( ! $zip_path ) {
				self::redirect_with_error( __( 'The selected backup file was not found.', 'raw-backup' ) );
			}
		} elseif ( ! empty( $_FILES['rawbk_zip']['name'] ) ) {
			$zip_path = self::accept_upload();
			if ( is_wp_error( $zip_path ) ) {
				self::redirect_with_error( $zip_path->get_error_message() );
			}
		} else {
			self::redirect_with_error( __( 'No backup file was provided.', 'raw-backup' ) );
		}

		self::begin_progress();

		// Safety net: back up the current site before overwriting it.
		$safety = Raw_Backup_Exporter::run(
			'pre-import',
			array( 0, 22 ),
			__( 'Safety backup: ', 'raw-backup' )
		);
		if ( is_wp_error( $safety ) ) {
			Raw_Backup_Progress::fail( $safety->get_error_message() );
			self::redirect_with_error(
				sprintf(
					/* translators: %s: error message */
					__( 'Import aborted — the pre-import safety backup failed: %s', 'raw-backup' ),
					$safety->get_error_message()
				)
			);
		}

		$result = Raw_Backup_Importer::run( $zip_path, array( 22, 100 ) );

		if ( is_wp_error( $result ) ) {
			Raw_Backup_Progress::fail( $result->get_error_message() );
		} else {
			// Never delete the ZIP we just imported, whatever its age.
			rawbk_apply_retention( array( $zip_path ) );
			Raw_Backup_Progress::done( __( 'Import complete.', 'raw-backup' ) );
		}

		// From here on the session may be gone; render the result directly.
		self::render_result_screen( $result, basename( $safety ), basename( $zip_path ) );
		exit;
	}

	/**
	 * AJAX endpoint polled by the admin page while a job runs.
	 */
	public static function handle_progress() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json( array(), 403 );
		}
		check_ajax_referer( 'rawbk_progress', 'nonce' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified above.
		$job  = isset( $_GET['job'] ) ? sanitize_key( wp_unslash( $_GET['job'] ) ) : '';
		$data = Raw_Backup_Progress::read( $job );
		wp_send_json( $data ? $data : array() );
	}

	/**
	 * Start progress tracking when the submitting form carried a job id.
	 */
	private static function begin_progress() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- caller ran check_admin_referer().
		$job = isset( $_POST['rawbk_job'] ) ? sanitize_key( wp_unslash( $_POST['rawbk_job'] ) ) : '';
		if ( $job ) {
			Raw_Backup_Progress::begin( $job );
		}
	}

	public static function handle_download() {
		self::guard( 'rawbk_download' );

		$path = isset( $_GET['f'] ) ? self::resolve_backup_file( $_GET['f'] ) : false;
		if ( ! $path ) {
			self::redirect_with_error( __( 'The requested backup file was not found.', 'raw-backup' ) );
		}

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . basename( $path ) . '"' );
		header( 'Content-Length: ' . (string) filesize( $path ) );

		$fh = fopen( $path, 'rb' );
		while ( $fh && ! feof( $fh ) ) {
			echo fread( $fh, 1048576 ); // phpcs:ignore WordPress.Security.EscapeOutput -- binary stream.
			flush();
		}
		if ( $fh ) {
			fclose( $fh );
		}
		exit;
	}

	public static function handle_delete() {
		self::guard( 'rawbk_delete' );

		$path = isset( $_GET['f'] ) ? self::resolve_backup_file( $_GET['f'] ) : false;
		if ( ! $path ) {
			self::redirect_with_error( __( 'The requested backup file was not found.', 'raw-backup' ) );
		}
		unlink( $path );
		self::redirect( array( 'rawbk' => 'deleted' ) );
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	private static function guard( $action ) {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'raw-backup' ) );
		}
		check_admin_referer( $action );
	}

	private static function list_backups() {
		$backups = array();
		foreach ( glob( rawbk_backups_dir() . '/*.zip' ) ?: array() as $file ) {
			$backups[] = array(
				'name'  => basename( $file ),
				'size'  => (int) filesize( $file ),
				'mtime' => (int) filemtime( $file ),
			);
		}
		usort(
			$backups,
			function ( $a, $b ) {
				return $b['mtime'] <=> $a['mtime'];
			}
		);
		return $backups;
	}

	/**
	 * Validate a user-supplied backup filename and return its full path,
	 * or false. Only plain .zip filenames inside the backups dir pass.
	 */
	private static function resolve_backup_file( $name ) {
		$name = sanitize_file_name( wp_unslash( (string) $name ) );
		if ( '' === $name || '.zip' !== substr( $name, -4 ) || basename( $name ) !== $name ) {
			return false;
		}
		$path = rawbk_backups_dir() . '/' . $name;
		return is_file( $path ) ? $path : false;
	}

	/**
	 * Move an uploaded ZIP into the backups directory.
	 *
	 * @return string|WP_Error Destination path.
	 */
	private static function accept_upload() {
		if ( ! isset( $_FILES['rawbk_zip']['error'] ) || UPLOAD_ERR_OK !== (int) $_FILES['rawbk_zip']['error'] ) {
			return new WP_Error( 'rawbk_upload', __( 'The upload failed. The file may exceed the server upload limit.', 'raw-backup' ) );
		}
		$tmp_name = (string) $_FILES['rawbk_zip']['tmp_name'];
		$name     = sanitize_file_name( (string) $_FILES['rawbk_zip']['name'] );

		if ( ! is_uploaded_file( $tmp_name ) || '.zip' !== strtolower( substr( $name, -4 ) ) ) {
			return new WP_Error( 'rawbk_upload', __( 'Only .zip files can be imported.', 'raw-backup' ) );
		}
		// ZIP magic bytes.
		$fh    = fopen( $tmp_name, 'rb' );
		$magic = $fh ? fread( $fh, 4 ) : '';
		if ( $fh ) {
			fclose( $fh );
		}
		if ( 0 !== strpos( (string) $magic, "PK\x03\x04" ) ) {
			return new WP_Error( 'rawbk_upload', __( 'The uploaded file is not a valid ZIP archive.', 'raw-backup' ) );
		}

		$dest = rawbk_backups_dir() . '/import-' . wp_date( 'Y-m-d-H-i-s' ) . '-' . $name;
		if ( ! move_uploaded_file( $tmp_name, $dest ) ) {
			return new WP_Error( 'rawbk_upload', __( 'Could not move the uploaded file into the backups directory.', 'raw-backup' ) );
		}
		return $dest;
	}

	private static function action_url( $action, $file ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => $action,
					'f'      => rawurlencode( $file ),
				),
				admin_url( 'admin-post.php' )
			),
			$action
		);
	}

	private static function redirect( $args = array() ) {
		wp_safe_redirect( add_query_arg( $args, admin_url( 'tools.php?page=' . self::PAGE ) ) );
		exit;
	}

	private static function redirect_with_error( $message ) {
		set_transient( 'rawbk_error_' . get_current_user_id(), $message, 120 );
		self::redirect();
	}

	/**
	 * Final screen after an import. Rendered directly (no redirect): the
	 * import replaces users and sessions, so the current login is gone.
	 */
	private static function render_result_screen( $result, $safety_file, $imported_file ) {
		$ok       = ! is_wp_error( $result );
		$login    = $ok && ! empty( $result['url_to'] ) ? $result['url_to'] . '/wp-login.php' : wp_login_url();
		$title    = $ok ? __( 'Import complete', 'raw-backup' ) : __( 'Import failed', 'raw-backup' );

		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!DOCTYPE html>
<html>
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title><?php echo esc_html( $title ); ?></title>
	<style>
		body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f0f0f1; color: #1d2327; display: flex; justify-content: center; padding: 48px 16px; }
		.box { background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 32px 40px; max-width: 640px; }
		h1 { font-size: 22px; margin-top: 0; }
		.ok { color: #00a32a; } .err { color: #b32d2e; }
		code { background: #f0f0f1; padding: 2px 5px; border-radius: 3px; }
		ul { line-height: 1.9; }
		.button { display: inline-block; background: #2271b1; color: #fff; text-decoration: none; padding: 8px 18px; border-radius: 3px; margin-top: 12px; }
	</style>
</head>
<body>
	<div class="box">
		<h1 class="<?php echo $ok ? 'ok' : 'err'; ?>"><?php echo esc_html( ( $ok ? '✔ ' : '✖ ' ) . $title ); ?></h1>
		<?php if ( $ok ) : ?>
			<ul>
				<li><?php esc_html_e( 'Imported backup:', 'raw-backup' ); ?> <code><?php echo esc_html( $imported_file ); ?></code></li>
				<?php if ( $result['files_copied'] ) : ?>
					<li><?php esc_html_e( 'wp-content files were replaced.', 'raw-backup' ); ?></li>
				<?php endif; ?>
				<li>
					<?php
					printf(
						/* translators: 1: executed statements, 2: failed statements */
						esc_html__( 'Database: %1$d statements executed, %2$d failed.', 'raw-backup' ),
						(int) $result['db_statements'],
						(int) $result['db_failed']
					);
					?>
				</li>
				<?php if ( $result['url_from'] && $result['url_from'] !== $result['url_to'] ) : ?>
					<li>
						<?php
						printf(
							/* translators: 1: old URL, 2: new URL, 3: rows updated */
							esc_html__( 'URLs rewritten: %1$s → %2$s (%3$d rows).', 'raw-backup' ),
							esc_html( $result['url_from'] ),
							esc_html( $result['url_to'] ),
							(int) $result['urls_replaced']
						);
						?>
					</li>
				<?php endif; ?>
				<li><?php esc_html_e( 'Safety backup of the previous site:', 'raw-backup' ); ?> <code><?php echo esc_html( $safety_file ); ?></code></li>
			</ul>
			<?php if ( ! empty( $result['db_errors'] ) ) : ?>
				<p class="err"><strong><?php esc_html_e( 'Non-fatal errors:', 'raw-backup' ); ?></strong></p>
				<ul><?php foreach ( $result['db_errors'] as $err ) : ?><li><code><?php echo esc_html( $err ); ?></code></li><?php endforeach; ?></ul>
			<?php endif; ?>
			<p><?php esc_html_e( 'Your session has ended. Log in again using the credentials of the imported site.', 'raw-backup' ); ?></p>
			<a class="button" href="<?php echo esc_url( $login ); ?>"><?php esc_html_e( 'Go to login', 'raw-backup' ); ?></a>
		<?php else : ?>
			<p><?php echo esc_html( $result->get_error_message() ); ?></p>
			<p>
				<?php
				printf(
					/* translators: %s: safety backup filename */
					esc_html__( 'A safety backup of the site as it was before the attempt exists at uploads/raw-backup/%s — it can be restored from the RAW Backup screen or manually.', 'raw-backup' ),
					esc_html( $safety_file )
				);
				?>
			</p>
			<a class="button" href="<?php echo esc_url( admin_url( 'tools.php?page=' . self::PAGE ) ); ?>"><?php esc_html_e( 'Back to RAW Backup', 'raw-backup' ); ?></a>
		<?php endif; ?>
	</div>
</body>
</html>
		<?php
	}
}
