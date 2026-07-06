<?php
/**
 * WP-CLI commands: `wp raw-backup export` and `wp raw-backup import`.
 * No PHP request timeouts here, so this is the right path for huge sites
 * and for scripting/automation.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Raw_Backup_CLI {

	/**
	 * Create a full-site backup ZIP (WordPress Studio compatible format).
	 *
	 * ## OPTIONS
	 *
	 * [--label=<label>]
	 * : Optional label added to the ZIP filename.
	 *
	 * [--porcelain]
	 * : Output only the path to the created ZIP.
	 *
	 * ## EXAMPLES
	 *
	 *     wp raw-backup export
	 *     wp raw-backup export --label=nightly --porcelain
	 */
	public function export( $args, $assoc_args ) {
		$label = isset( $assoc_args['label'] ) ? sanitize_file_name( $assoc_args['label'] ) : '';

		$zip = Raw_Backup_Exporter::run( $label );
		if ( is_wp_error( $zip ) ) {
			WP_CLI::error( $zip->get_error_message() );
		}

		$deleted = rawbk_apply_retention();

		if ( \WP_CLI\Utils\get_flag_value( $assoc_args, 'porcelain', false ) ) {
			WP_CLI::line( $zip );
			return;
		}
		WP_CLI::success( sprintf( 'Backup created: %s (%s)', $zip, size_format( filesize( $zip ) ) ) );
		if ( $deleted ) {
			WP_CLI::log( sprintf( 'Retention: deleted %d old backup(s): %s', count( $deleted ), implode( ', ', $deleted ) ) );
		}
	}

	/**
	 * Restore a backup ZIP into this site. DESTRUCTIVE: replaces the
	 * database and wp-content, then rewrites URLs to this site's URL.
	 *
	 * ## OPTIONS
	 *
	 * <zip>
	 * : Path to the backup ZIP, or a filename inside uploads/raw-backup/.
	 *
	 * [--skip-safety-backup]
	 * : Do not create the automatic pre-import backup of the current site.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp raw-backup import /path/to/backup.zip
	 *     wp raw-backup import raw-backup-mysite-2026-07-06-13-00-00.zip --yes
	 */
	public function import( $args, $assoc_args ) {
		$zip = $args[0];
		if ( ! is_file( $zip ) ) {
			$candidate = rawbk_backups_dir() . '/' . sanitize_file_name( basename( $zip ) );
			if ( is_file( $candidate ) ) {
				$zip = $candidate;
			}
		}
		if ( ! is_file( $zip ) || '.zip' !== strtolower( substr( $zip, -4 ) ) ) {
			WP_CLI::error( sprintf( 'Backup ZIP not found: %s', $args[0] ) );
		}
		$zip = realpath( $zip );

		WP_CLI::confirm(
			'This will REPLACE the database and wp-content of this site. Continue?',
			$assoc_args
		);

		if ( ! \WP_CLI\Utils\get_flag_value( $assoc_args, 'skip-safety-backup', false ) ) {
			WP_CLI::log( 'Creating pre-import safety backup…' );
			$safety = Raw_Backup_Exporter::run( 'pre-import' );
			if ( is_wp_error( $safety ) ) {
				WP_CLI::error( sprintf( 'Import aborted — the safety backup failed: %s', $safety->get_error_message() ) );
			}
			WP_CLI::log( sprintf( 'Safety backup: %s', basename( $safety ) ) );
		}

		WP_CLI::log( 'Importing…' );
		$result = Raw_Backup_Importer::run( $zip );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		rawbk_apply_retention( array( $zip ) );

		if ( $result['url_from'] && $result['url_from'] !== $result['url_to'] ) {
			WP_CLI::log( sprintf( 'URLs rewritten: %s -> %s (%d rows)', $result['url_from'], $result['url_to'], $result['urls_replaced'] ) );
		}
		if ( ! empty( $result['db_errors'] ) ) {
			foreach ( $result['db_errors'] as $error ) {
				WP_CLI::warning( $error );
			}
		}
		WP_CLI::success(
			sprintf(
				'Import complete: %d statements executed, %d failed. Sessions were replaced — log in with the imported site credentials.',
				$result['db_statements'],
				$result['db_failed']
			)
		);
	}
}
