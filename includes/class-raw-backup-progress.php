<?php
/**
 * File-based progress reporting for long-running export/import jobs.
 *
 * State lives in a small JSON file (not the database) so it stays readable
 * while an import is dropping and recreating tables. Writers call update()
 * with absolute percentages; when no job was started every call is a no-op,
 * so exporter/importer keep working from WP-CLI without any setup.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Raw_Backup_Progress {

	/** Minimum seconds between file writes (unless forced). */
	const WRITE_INTERVAL = 0.3;

	private static $file       = null;
	private static $last_write = 0;
	private static $last       = array(
		'percent' => 0.0,
		'message' => '',
	);

	/**
	 * Start tracking a job. Invalid/empty ids disable tracking silently.
	 *
	 * @param string $job Job id from the client, [a-z0-9]{4,40}.
	 */
	public static function begin( $job ) {
		$job = self::sanitize_job( $job );
		if ( ! $job ) {
			return;
		}
		self::cleanup_old();
		self::$file = rawbk_backups_dir() . '/progress-' . $job . '.json';
		self::write( 0, __( 'Starting…', 'raw-backup' ) );
	}

	public static function active() {
		return null !== self::$file;
	}

	/**
	 * Report progress. No-op when no job is active.
	 *
	 * @param float       $percent Absolute percent, 0–100.
	 * @param string|null $message Status line; null keeps the previous one.
	 * @param bool        $force   Bypass the write throttle.
	 */
	public static function update( $percent, $message = null, $force = false ) {
		if ( ! self::active() ) {
			return;
		}
		$percent = max( 0.0, min( 100.0, (float) $percent ) );
		$message = ( null === $message ) ? self::$last['message'] : $message;

		$throttled = ( microtime( true ) - self::$last_write ) < self::WRITE_INTERVAL;
		if ( ! $force && $throttled && $message === self::$last['message'] ) {
			return;
		}
		self::write( $percent, $message );
	}

	public static function done( $message ) {
		if ( self::active() ) {
			self::write( 100, $message, array( 'done' => true ) );
		}
	}

	public static function fail( $message ) {
		if ( self::active() ) {
			self::write( self::$last['percent'], $message, array( 'error' => true ) );
		}
	}

	/**
	 * Map a local 0–100 value into an absolute [start, end] window.
	 *
	 * @param array $win   array( start, end ).
	 * @param float $local Local percent within the window.
	 * @return float Absolute percent.
	 */
	public static function scale( $win, $local ) {
		return $win[0] + ( $win[1] - $win[0] ) * max( 0.0, min( 100.0, (float) $local ) ) / 100;
	}

	/**
	 * Read a job's progress state (AJAX side).
	 *
	 * @param string $job Job id.
	 * @return array|null
	 */
	public static function read( $job ) {
		$job = self::sanitize_job( $job );
		if ( ! $job ) {
			return null;
		}
		$file = rawbk_backups_dir() . '/progress-' . $job . '.json';
		if ( ! is_file( $file ) ) {
			return null;
		}
		$data = json_decode( (string) file_get_contents( $file ), true );
		return is_array( $data ) ? $data : null;
	}

	private static function write( $percent, $message, $extra = array() ) {
		self::$last       = array(
			'percent' => (float) $percent,
			'message' => (string) $message,
		);
		self::$last_write = microtime( true );

		$data = array_merge(
			array(
				'percent' => round( (float) $percent, 1 ),
				'message' => (string) $message,
				'ts'      => time(),
			),
			$extra
		);
		// Write-then-rename so pollers never read a half-written file.
		$tmp = self::$file . '.tmp';
		if ( false !== @file_put_contents( $tmp, wp_json_encode( $data ) ) ) {
			@rename( $tmp, self::$file );
		}
	}

	private static function sanitize_job( $job ) {
		$job = strtolower( (string) $job );
		return preg_match( '/^[a-z0-9]{4,40}$/', $job ) ? $job : '';
	}

	private static function cleanup_old() {
		foreach ( glob( rawbk_backups_dir() . '/progress-*.json*' ) ?: array() as $file ) {
			if ( @filemtime( $file ) < time() - DAY_IN_SECONDS ) {
				@unlink( $file );
			}
		}
	}
}
