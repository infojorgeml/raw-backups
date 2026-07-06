<?php
/**
 * Restores a backup ZIP (RAW Backup / WordPress Studio format) into the
 * current site: replaces wp-content files, imports the database dump and
 * rewrites the source URLs to the current site's URLs.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Raw_Backup_Importer {

	/**
	 * Run a full import.
	 *
	 * @param string $zip_path Absolute path to the backup ZIP.
	 * @return array|WP_Error Summary of what was done.
	 */
	public static function run( $zip_path ) {
		global $wpdb;

		@set_time_limit( 0 );
		wp_raise_memory_limit( 'admin' );

		$summary = array(
			'files_copied'  => false,
			'db_statements' => 0,
			'db_failed'     => 0,
			'db_errors'     => array(),
			'urls_replaced' => 0,
			'url_from'      => '',
			'url_to'        => '',
		);

		// Capture everything we need from the *current* site before touching it.
		$target_home    = untrailingslashit( home_url() );
		$target_siteurl = untrailingslashit( site_url() );
		$target_prefix  = $wpdb->prefix;

		// 1) Extract.
		$tmp = rawbk_backups_dir() . '/tmp-import-' . strtolower( wp_generate_password( 8, false ) );
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();
		$unzipped = unzip_file( $zip_path, $tmp );
		if ( is_wp_error( $unzipped ) ) {
			rawbk_rrmdir( $tmp );
			return $unzipped;
		}

		$root = self::locate_root( $tmp );
		if ( ! $root ) {
			rawbk_rrmdir( $tmp );
			return new WP_Error(
				'rawbk_bad_format',
				'Unrecognized backup format: the ZIP must contain a wp-content/ directory and/or a sql/ directory with a .sql dump.'
			);
		}

		// 2) Files.
		if ( is_dir( $root . '/wp-content' ) ) {
			$copied = self::import_wp_content( $root . '/wp-content' );
			if ( is_wp_error( $copied ) ) {
				rawbk_rrmdir( $tmp );
				return $copied;
			}
			$summary['files_copied'] = true;
		}

		// 3) Database.
		$sql_files = glob( $root . '/sql/*.sql' );
		if ( $sql_files ) {
			sort( $sql_files );
			foreach ( $sql_files as $sql_file ) {
				$result = Raw_Backup_DB::import_file( $sql_file );
				if ( is_wp_error( $result ) ) {
					rawbk_rrmdir( $tmp );
					return $result;
				}
				$summary['db_statements'] += $result['executed'];
				$summary['db_failed']     += $result['failed'];
				$summary['db_errors']      = array_merge( $summary['db_errors'], $result['errors'] );
			}

			$renamed = Raw_Backup_DB::rename_prefix( $target_prefix );
			if ( is_wp_error( $renamed ) ) {
				rawbk_rrmdir( $tmp );
				return $renamed;
			}

			self::keep_self_active( $target_prefix );

			// 4) Rewrite URLs from the imported site to this site.
			$imported_home = self::imported_url_from_meta( $root );
			if ( ! $imported_home ) {
				$imported_home = self::get_imported_option( $target_prefix, 'home' );
			}
			$imported_siteurl = self::get_imported_option( $target_prefix, 'siteurl' );

			$pairs = array();
			if ( $imported_home && $imported_home !== $target_home ) {
				$pairs[ $imported_home ] = $target_home;
			}
			if ( $imported_siteurl && $imported_siteurl !== $target_siteurl && ! isset( $pairs[ $imported_siteurl ] ) ) {
				$pairs[ $imported_siteurl ] = $target_siteurl;
			}
			foreach ( $pairs as $from => $to ) {
				$summary['urls_replaced'] += Raw_Backup_DB::search_replace( $from, $to );
				// JSON-escaped variant (e.g. inside block attributes or LinkControl data).
				if ( false !== strpos( $from, '/' ) ) {
					$summary['urls_replaced'] += Raw_Backup_DB::search_replace(
						str_replace( '/', '\/', $from ),
						str_replace( '/', '\/', $to )
					);
				}
			}
			$summary['url_from'] = $imported_home ? $imported_home : '';
			$summary['url_to']   = $target_home;

			// Belt and braces: enforce the current URLs and let WP rebuild rewrites.
			$options_table = $target_prefix . 'options';
			$wpdb->query( $wpdb->prepare( "UPDATE `{$options_table}` SET option_value = %s WHERE option_name = 'home'", $target_home ) );
			$wpdb->query( $wpdb->prepare( "UPDATE `{$options_table}` SET option_value = %s WHERE option_name = 'siteurl'", $target_siteurl ) );
			$wpdb->query( "DELETE FROM `{$options_table}` WHERE option_name = 'rewrite_rules'" );

			wp_cache_flush();
		}

		rawbk_rrmdir( $tmp );

		return $summary;
	}

	/**
	 * Find the directory inside the extracted ZIP that holds the backup
	 * (it may be nested one level if the user zipped the folder itself).
	 */
	private static function locate_root( $tmp ) {
		$candidates = array( $tmp );
		foreach ( glob( $tmp . '/*', GLOB_ONLYDIR ) ?: array() as $dir ) {
			$candidates[] = $dir;
		}
		foreach ( $candidates as $dir ) {
			if ( is_dir( $dir . '/wp-content' ) || glob( $dir . '/sql/*.sql' ) ) {
				return $dir;
			}
		}
		return false;
	}

	/**
	 * Copy the backup's wp-content over the live one (merge + overwrite),
	 * skipping paths that must never be replaced at runtime.
	 *
	 * @param string $source Extracted wp-content directory.
	 * @return true|WP_Error
	 */
	private static function import_wp_content( $source ) {
		$source  = untrailingslashit( $source );
		$exclude = array(
			$source . '/plugins/' . dirname( RAW_BACKUP_BASENAME ), // Never overwrite the running plugin.
			$source . '/uploads/raw-backup',                        // Foreign backup archives.
			$source . '/database',                                  // Studio SQLite database.
			$source . '/db.php',                                    // Environment-specific drop-ins.
			$source . '/object-cache.php',
			$source . '/advanced-cache.php',
		);

		return rawbk_copy_dir( $source, untrailingslashit( WP_CONTENT_DIR ), $exclude );
	}

	/**
	 * Read an option straight from the freshly imported options table
	 * (object caches are stale at this point).
	 */
	private static function get_imported_option( $prefix, $name ) {
		global $wpdb;

		$value = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM `{$prefix}options` WHERE option_name = %s LIMIT 1", $name )
		);
		return $value ? untrailingslashit( trim( (string) $value ) ) : '';
	}

	/**
	 * Source site URL as recorded in meta.json, if present.
	 */
	private static function imported_url_from_meta( $root ) {
		if ( ! file_exists( $root . '/meta.json' ) ) {
			return '';
		}
		$meta = json_decode( (string) file_get_contents( $root . '/meta.json' ), true );
		if ( ! empty( $meta['siteUrl'] ) && is_string( $meta['siteUrl'] ) ) {
			return untrailingslashit( trim( $meta['siteUrl'] ) );
		}
		return '';
	}

	/**
	 * Make sure this plugin stays in active_plugins after the imported
	 * options land, so the user returns to a working admin page.
	 */
	private static function keep_self_active( $prefix ) {
		global $wpdb;

		$options_table = $prefix . 'options';
		$raw           = $wpdb->get_var(
			"SELECT option_value FROM `{$options_table}` WHERE option_name = 'active_plugins' LIMIT 1"
		);
		$active        = maybe_unserialize( (string) $raw );
		if ( ! is_array( $active ) ) {
			$active = array();
		}
		if ( ! in_array( RAW_BACKUP_BASENAME, $active, true ) ) {
			$active[] = RAW_BACKUP_BASENAME;
			sort( $active );
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE `{$options_table}` SET option_value = %s WHERE option_name = 'active_plugins'",
					serialize( array_values( $active ) ) // phpcs:ignore -- core stores this option serialized.
				)
			);
		}
	}
}
