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
			</style>

			<div class="rawbk-card">
				<h2><?php esc_html_e( 'Export', 'raw-backup' ); ?></h2>
				<p><?php esc_html_e( 'Creates a ZIP containing meta.json, a credential-free wp-config.php template, a full database dump (sql/) and the wp-content directory.', 'raw-backup' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="rawbk_export" />
					<?php wp_nonce_field( 'rawbk_export' ); ?>
					<?php submit_button( __( 'Create backup now', 'raw-backup' ), 'primary', 'submit', false ); ?>
				</form>
			</div>

			<div class="rawbk-card">
				<h2><?php esc_html_e( 'Backups', 'raw-backup' ); ?></h2>
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
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
											onsubmit="return confirm('<?php echo esc_js( __( 'Import this backup? The database and wp-content of THIS site will be replaced. A safety backup will be created first.', 'raw-backup' ) ); ?>');">
											<input type="hidden" name="action" value="rawbk_import" />
											<input type="hidden" name="rawbk_existing" value="<?php echo esc_attr( $backup['name'] ); ?>" />
											<input type="hidden" name="rawbk_confirm" value="1" />
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
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
					<input type="hidden" name="action" value="rawbk_import" />
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
		</div>
		<?php
	}

	private static function render_notices() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice codes.
		$code = isset( $_GET['rawbk'] ) ? sanitize_key( $_GET['rawbk'] ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$file = isset( $_GET['f'] ) ? sanitize_file_name( wp_unslash( $_GET['f'] ) ) : '';

		if ( 'exported' === $code ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s <code>%s</code></p></div>',
				esc_html__( 'Backup created:', 'raw-backup' ),
				esc_html( $file )
			);
		} elseif ( 'deleted' === $code ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'Backup deleted.', 'raw-backup' )
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

		$result = Raw_Backup_Exporter::run();
		if ( is_wp_error( $result ) ) {
			self::redirect_with_error( $result->get_error_message() );
		}
		self::redirect( array( 'rawbk' => 'exported', 'f' => basename( $result ) ) );
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

		// Safety net: back up the current site before overwriting it.
		$safety = Raw_Backup_Exporter::run( 'pre-import' );
		if ( is_wp_error( $safety ) ) {
			self::redirect_with_error(
				sprintf(
					/* translators: %s: error message */
					__( 'Import aborted — the pre-import safety backup failed: %s', 'raw-backup' ),
					$safety->get_error_message()
				)
			);
		}

		$result = Raw_Backup_Importer::run( $zip_path );

		// From here on the session may be gone; render the result directly.
		self::render_result_screen( $result, basename( $safety ), basename( $zip_path ) );
		exit;
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
