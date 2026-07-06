=== RAW Backup ===
Contributors: jorgemunoz
Tags: backup, migration, export, import
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.3.1
License: GPLv2 or later

Simple site migrations: export and import full-site backups as raw ZIP archives, compatible with the WordPress Studio backup format.

== Description ==

RAW Backup creates and restores full-site backups in the same raw layout WordPress Studio uses for its local exports:

* `meta.json` — site URL, PHP/WP versions, plugin and theme inventory.
* `wp-config.php` — credential-free template (real credentials and salts are never exported).
* `sql/` — full database dump (DROP + CREATE + INSERT), table prefix normalized to `wp_`.
* `wp-content/` — plugins, themes, uploads and everything else in wp-content.

Because the format matches Studio's, backups are interchangeable: a ZIP exported here can be dragged into WordPress Studio, and a Studio export can be imported here.

**Import behavior**

* A safety backup of the current site is created automatically before every import.
* `wp-content` is merged over the existing one (existing extra plugins/themes are kept but will be inactive if the imported database does not list them).
* The database is replaced, then source URLs are rewritten to this site's URLs (serialized data handled safely).
* Environment-specific files are never touched: drop-ins (`db.php`, `object-cache.php`, `advanced-cache.php`), Studio's SQLite `database/` directory and this plugin itself.
* After a database import your login session ends — log in again with the credentials of the imported site.

**Notes and limits**

* Single-site installs only (multisite is not supported).
* Backups are stored in `wp-content/uploads/raw-backup/`, protected from direct access on Apache via `.htaccess`. On nginx, deny access to that path in the server config. Backups contain the full database — treat the files as sensitive.
* Very large sites may hit PHP time/memory limits; the process raises them where the host allows it.
* Deactivating or uninstalling the plugin never deletes your backup files.

**Progress reporting**

Exports and imports show a live progress bar. The progress is real, not simulated: the running job writes its state (current table, files added, bytes of SQL processed, compression ratio) to a small file that the page polls once per second. Near the end of an import the login session ends, so the bar switches to a generic "finishing" state until the result screen loads.

**Retention**

Only the most recent backups are kept (5 by default, configurable on the RAW Backup screen, 0 = unlimited). Older ZIPs are deleted automatically when a new backup is created; the file being imported is never deleted.

**WP-CLI**

* `wp raw-backup export [--label=<label>] [--porcelain]`
* `wp raw-backup import <zip> [--skip-safety-backup] [--yes]`

The CLI path has no PHP request timeouts, so prefer it for very large sites and automation.

== Changelog ==

= 0.3.1 =
* Fix: uploaded imports broke in 0.3.0 — the XHR upload posted to "[object HTMLInputElement]" because the hidden `action` input required by admin-post.php shadows the form's `action` property in JavaScript. The upload URL is now read with `getAttribute()`, and HTTP errors during upload show a message instead of a broken page.

= 0.3.0 =
* Automatic backup retention (keep the last N, default 5, 0 = unlimited) with a one-line setting.
* WP-CLI commands: `wp raw-backup export` and `wp raw-backup import`.
* Real upload progress for imported ZIPs (XHR upload phase mapped to 0–30% of the bar).
* The "Backup created" notice now includes a direct Download button.

= 0.2.1 =
* Run ANALYZE TABLE on all tables after an import so database tools (e.g. phpMyAdmin on MySQL 8, which caches statistics for 24h) show real table sizes and row counts immediately.

= 0.2.0 =
* Real progress bars for export and import (per-table dump progress, per-file copy progress, byte-accurate SQL import progress and libzip compression progress on PHP 8+).

= 0.1.0 =
* Initial release: raw ZIP export/import in the WordPress Studio backup format, automatic pre-import safety backup, serialized-safe URL rewriting and table prefix normalization.
