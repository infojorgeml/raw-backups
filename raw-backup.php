<?php
/**
 * Plugin Name: RAW Backup
 * Description: Simple site migrations — export and import full-site backups as raw ZIP archives, compatible with the WordPress Studio backup format.
 * Version: 0.3.0
 * Author: Jorge Muñoz
 * License: GPL-2.0-or-later
 * Text Domain: raw-backup
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RAW_BACKUP_VERSION', '0.3.0' );
define( 'RAW_BACKUP_FILE', __FILE__ );
define( 'RAW_BACKUP_DIR', plugin_dir_path( __FILE__ ) );
define( 'RAW_BACKUP_BASENAME', plugin_basename( __FILE__ ) );

// Admin-only plugin: nothing is loaded on the frontend. WP-CLI also gets
// the core classes so exports/imports can be scripted.
if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	require_once RAW_BACKUP_DIR . 'includes/functions.php';
	require_once RAW_BACKUP_DIR . 'includes/class-raw-backup-progress.php';
	require_once RAW_BACKUP_DIR . 'includes/class-raw-backup-db.php';
	require_once RAW_BACKUP_DIR . 'includes/class-raw-backup-exporter.php';
	require_once RAW_BACKUP_DIR . 'includes/class-raw-backup-importer.php';
}

if ( is_admin() ) {
	require_once RAW_BACKUP_DIR . 'includes/class-raw-backup-admin.php';
	Raw_Backup_Admin::init();
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once RAW_BACKUP_DIR . 'includes/class-raw-backup-cli.php';
	WP_CLI::add_command( 'raw-backup', 'Raw_Backup_CLI' );
}
