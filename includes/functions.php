<?php
/**
 * Shared filesystem helpers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Directory where backups are stored (uploads/raw-backup), protected
 * against direct access and directory listing.
 *
 * @return string Absolute path, no trailing slash.
 */
function rawbk_backups_dir() {
	$uploads = wp_upload_dir();
	$dir     = trailingslashit( $uploads['basedir'] ) . 'raw-backup';

	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
	}
	if ( ! file_exists( $dir . '/.htaccess' ) ) {
		@file_put_contents( $dir . '/.htaccess', "Deny from all\n" );
	}
	if ( ! file_exists( $dir . '/index.php' ) ) {
		@file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" );
	}

	return $dir;
}

/**
 * Recursively delete a directory.
 *
 * @param string $dir Absolute path.
 */
function rawbk_rrmdir( $dir ) {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $items as $item ) {
		if ( $item->isDir() && ! $item->isLink() ) {
			@rmdir( $item->getPathname() );
		} else {
			@unlink( $item->getPathname() );
		}
	}
	@rmdir( $dir );
}

/**
 * Recursively copy a directory, skipping excluded source paths.
 *
 * @param string   $from    Source directory.
 * @param string   $to      Destination directory.
 * @param string[] $exclude Absolute source paths (files or dirs) to skip.
 * @return true|WP_Error
 */
function rawbk_copy_dir( $from, $to, $exclude = array() ) {
	$from = untrailingslashit( $from );
	$to   = untrailingslashit( $to );

	if ( in_array( $from, $exclude, true ) ) {
		return true;
	}
	if ( ! is_dir( $to ) && ! wp_mkdir_p( $to ) ) {
		return new WP_Error( 'rawbk_mkdir_failed', sprintf( 'Could not create directory: %s', $to ) );
	}

	$handle = opendir( $from );
	if ( ! $handle ) {
		return new WP_Error( 'rawbk_opendir_failed', sprintf( 'Could not read directory: %s', $from ) );
	}

	while ( false !== ( $entry = readdir( $handle ) ) ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}
		$src = $from . '/' . $entry;
		$dst = $to . '/' . $entry;

		if ( in_array( $src, $exclude, true ) || is_link( $src ) || '.DS_Store' === $entry ) {
			continue;
		}

		if ( is_dir( $src ) ) {
			$result = rawbk_copy_dir( $src, $dst, $exclude );
			if ( is_wp_error( $result ) ) {
				closedir( $handle );
				return $result;
			}
		} elseif ( ! copy( $src, $dst ) ) {
			closedir( $handle );
			return new WP_Error( 'rawbk_copy_failed', sprintf( 'Could not copy file: %s', $src ) );
		}
	}
	closedir( $handle );

	return true;
}
