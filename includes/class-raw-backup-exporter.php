<?php
/**
 * Builds a full-site backup ZIP in the WordPress Studio format:
 *
 *   meta.json
 *   wp-config.php          (credential-free template)
 *   sql/studio-backup-db-export-<ts>.sql
 *   wp-content/...
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Raw_Backup_Exporter {

	/**
	 * Create a backup ZIP in the backups directory.
	 *
	 * @param string $label      Optional filename label, e.g. 'pre-import'.
	 * @param array  $win        Optional progress window array( start, end ).
	 * @param string $msg_prefix Optional prefix for progress messages.
	 * @return string|WP_Error Absolute path to the ZIP.
	 */
	public static function run( $label = '', $win = null, $msg_prefix = '' ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'rawbk_no_zip', 'The PHP zip extension (ZipArchive) is not available.' );
		}

		@set_time_limit( 0 );
		wp_raise_memory_limit( 'admin' );

		$win = $win ? $win : array( 0, 100 );
		Raw_Backup_Progress::update(
			Raw_Backup_Progress::scale( $win, 1 ),
			$msg_prefix . __( 'Preparing backup…', 'raw-backup' ),
			true
		);

		$timestamp = wp_date( 'Y-m-d-H-i-s' );
		$slug      = sanitize_title( get_bloginfo( 'name' ) );
		$slug      = $slug ? $slug : 'site';
		$label     = $label ? $label . '-' : '';
		$basename  = "raw-backup-{$label}{$slug}-{$timestamp}";

		$backups_dir = rawbk_backups_dir();
		$staging     = $backups_dir . '/' . $basename;
		$zip_path    = $backups_dir . '/' . $basename . '.zip';

		if ( ! wp_mkdir_p( $staging . '/sql' ) ) {
			return new WP_Error( 'rawbk_staging', 'Could not create the staging directory.' );
		}

		// 1) Generated files: meta.json, wp-config.php template, DB dump.
		file_put_contents(
			$staging . '/meta.json',
			wp_json_encode( self::build_meta(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
		);
		file_put_contents( $staging . '/wp-config.php', self::wp_config_template() );

		$sql_file = $staging . "/sql/studio-backup-db-export-{$timestamp}.sql";
		$dumped   = Raw_Backup_DB::dump_to_file(
			$sql_file,
			array( Raw_Backup_Progress::scale( $win, 3 ), Raw_Backup_Progress::scale( $win, 38 ) ),
			$msg_prefix
		);
		if ( is_wp_error( $dumped ) ) {
			rawbk_rrmdir( $staging );
			return $dumped;
		}

		// 2) Zip: generated files + live wp-content.
		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			rawbk_rrmdir( $staging );
			return new WP_Error( 'rawbk_zip_open', 'Could not create the ZIP file.' );
		}

		$zip->addFile( $staging . '/meta.json', 'meta.json' );
		$zip->addFile( $staging . '/wp-config.php', 'wp-config.php' );
		$zip->addFile( $sql_file, 'sql/' . basename( $sql_file ) );

		$added = self::add_wp_content(
			$zip,
			array( Raw_Backup_Progress::scale( $win, 38 ), Raw_Backup_Progress::scale( $win, 45 ) ),
			$msg_prefix
		);
		if ( is_wp_error( $added ) ) {
			$zip->close();
			@unlink( $zip_path );
			rawbk_rrmdir( $staging );
			return $added;
		}

		// Compression happens inside close(); report it for real where the
		// libzip progress callback is available (PHP 8.0+).
		$compress_msg = $msg_prefix . __( 'Compressing archive…', 'raw-backup' );
		if ( method_exists( $zip, 'registerProgressCallback' ) ) {
			$zip->registerProgressCallback(
				0.005,
				function ( $ratio ) use ( $win, $compress_msg ) {
					Raw_Backup_Progress::update(
						Raw_Backup_Progress::scale( $win, 45 + 53 * $ratio ),
						$compress_msg
					);
				}
			);
		} else {
			Raw_Backup_Progress::update( Raw_Backup_Progress::scale( $win, 60 ), $compress_msg, true );
		}

		if ( ! $zip->close() ) {
			rawbk_rrmdir( $staging );
			return new WP_Error( 'rawbk_zip_close', 'Could not finalize the ZIP file.' );
		}
		rawbk_rrmdir( $staging );
		Raw_Backup_Progress::update(
			Raw_Backup_Progress::scale( $win, 99 ),
			$msg_prefix . __( 'Backup file created.', 'raw-backup' ),
			true
		);

		return $zip_path;
	}

	/**
	 * Add the live wp-content tree to the ZIP, skipping environment-specific
	 * and self-referential paths.
	 *
	 * @param ZipArchive $zip        Open archive.
	 * @param array      $win        Optional progress window array( start, end ).
	 * @param string     $msg_prefix Optional prefix for progress messages.
	 * @return true|WP_Error
	 */
	private static function add_wp_content( ZipArchive $zip, $win = null, $msg_prefix = '' ) {
		$content_dir = untrailingslashit( WP_CONTENT_DIR );

		$excluded_dirs = array(
			rawbk_backups_dir(),                        // Our own backups (recursion!).
			$content_dir . '/database',                 // Studio's SQLite database.
			$content_dir . '/cache',
			$content_dir . '/upgrade',
			$content_dir . '/upgrade-temp-backup',
		);
		$excluded_files = array(
			$content_dir . '/db.php',                   // Environment-specific drop-ins.
			$content_dir . '/object-cache.php',
			$content_dir . '/advanced-cache.php',
			$content_dir . '/debug.log',
		);

		$iterator = new RecursiveIteratorIterator(
			new RecursiveCallbackFilterIterator(
				new RecursiveDirectoryIterator( $content_dir, FilesystemIterator::SKIP_DOTS ),
				function ( $current ) use ( $excluded_dirs, $excluded_files ) {
					$path = $current->getPathname();
					$name = $current->getFilename();
					if ( $current->isLink() || '.DS_Store' === $name || '.git' === $name ) {
						return false;
					}
					if ( $current->isDir() ) {
						return ! in_array( $path, $excluded_dirs, true );
					}
					return ! in_array( $path, $excluded_files, true )
						&& 'sqlite' !== strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
				}
			),
			RecursiveIteratorIterator::SELF_FIRST
		);

		if ( $win ) {
			Raw_Backup_Progress::update(
				Raw_Backup_Progress::scale( $win, 0 ),
				$msg_prefix . __( 'Collecting files…', 'raw-backup' ),
				true
			);
		}

		$base_len = strlen( $content_dir ) + 1;
		$dirs     = array();
		$files    = array();
		foreach ( $iterator as $item ) {
			$relative = 'wp-content/' . substr( $item->getPathname(), $base_len );
			if ( $item->isDir() ) {
				$dirs[] = $relative;
			} else {
				$files[ $item->getPathname() ] = $relative;
			}
		}

		foreach ( $dirs as $relative ) {
			$zip->addEmptyDir( $relative );
		}

		$total = max( 1, count( $files ) );
		$done  = 0;
		foreach ( $files as $path => $relative ) {
			if ( ! $zip->addFile( $path, $relative ) ) {
				return new WP_Error( 'rawbk_zip_add', sprintf( 'Could not add file to ZIP: %s', $relative ) );
			}
			$done++;
			if ( $win && 0 === $done % 200 ) {
				Raw_Backup_Progress::update(
					Raw_Backup_Progress::scale( $win, 100 * $done / $total ),
					$msg_prefix . sprintf(
						/* translators: 1: files added, 2: total files */
						__( 'Adding files (%1$s of %2$s)…', 'raw-backup' ),
						number_format_i18n( $done ),
						number_format_i18n( $total )
					)
				);
			}
		}

		return true;
	}

	/**
	 * meta.json contents, matching the keys Studio writes.
	 */
	private static function build_meta() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = array();
		foreach ( get_plugins() as $file => $data ) {
			$slug      = dirname( $file );
			$slug      = ( '.' === $slug ) ? basename( $file, '.php' ) : $slug;
			$plugins[] = array(
				'name'    => $slug,
				'status'  => is_plugin_active( $file ) ? 'active' : 'inactive',
				'version' => isset( $data['Version'] ) ? $data['Version'] : '',
			);
		}

		$themes = array();
		$active = get_stylesheet();
		foreach ( wp_get_themes() as $slug => $theme ) {
			$themes[] = array(
				'name'    => $slug,
				'status'  => ( $slug === $active ) ? 'active' : 'inactive',
				'version' => $theme->get( 'Version' ) ? $theme->get( 'Version' ) : '',
			);
		}

		return array(
			'siteUrl'          => home_url(),
			'phpVersion'       => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
			'wordpressVersion' => get_bloginfo( 'version' ),
			'plugins'          => $plugins,
			'themes'           => $themes,
		);
	}

	/**
	 * Credential-free wp-config.php template, like the one Studio ships in
	 * its backups. Real credentials and salts are never exported.
	 */
	private static function wp_config_template() {
		return <<<'PHP'
<?php
/**
 * The base configuration for WordPress
 *
 * This file was generated by the RAW Backup plugin. Fill in the database
 * settings and salts for the destination environment.
 *
 * @package WordPress
 */

// ** Database settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define( 'DB_NAME', 'wordpress' );

/** Database username */
define( 'DB_USER', 'username_here' );

/** Database password */
define( 'DB_PASSWORD', 'password_here' );

/** Database hostname */
define( 'DB_HOST', 'localhost' );

/** Database charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8mb4' );

/** The database collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', '' );

/**#@+
 * Authentication unique keys and salts.
 *
 * You can generate these using the
 * {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}.
 */
define( 'AUTH_KEY',         'put your unique phrase here' );
define( 'SECURE_AUTH_KEY',  'put your unique phrase here' );
define( 'LOGGED_IN_KEY',    'put your unique phrase here' );
define( 'NONCE_KEY',        'put your unique phrase here' );
define( 'AUTH_SALT',        'put your unique phrase here' );
define( 'SECURE_AUTH_SALT', 'put your unique phrase here' );
define( 'LOGGED_IN_SALT',   'put your unique phrase here' );
define( 'NONCE_SALT',       'put your unique phrase here' );

/**#@-*/

/**
 * WordPress database table prefix.
 */
$table_prefix = 'wp_';

/**
 * For developers: WordPress debugging mode.
 */
define( 'WP_DEBUG', false );

/* Add any custom values between this line and the "stop editing" line. */



/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';

PHP;
	}
}
