<?php
/**
 * Uninstall cleanup: remove the plugin's options.
 *
 * Backup archives in uploads/raw-backup/ are intentionally preserved —
 * they are the user's data, and deleting backups on uninstall would be
 * exactly the wrong moment to lose them.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'rawbk_keep_backups' );
