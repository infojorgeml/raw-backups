<?php
/**
 * Database dump, import and search-replace, all through $wpdb so it works
 * on MySQL/MariaDB and on WordPress Studio's SQLite translation layer alike.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Raw_Backup_DB {

	const ROW_BATCH = 500;

	/**
	 * Usermeta keys that carry the table prefix (core keys).
	 */
	const PREFIXED_META_KEYS = array(
		'capabilities',
		'user_level',
		'user-settings',
		'user-settings-time',
		'dashboard_quick_press_last_post_id',
		'persisted_preferences',
	);

	/**
	 * Dump all tables of the current install to a .sql file in the same
	 * format WordPress Studio exports (DROP + CREATE + one INSERT per row).
	 * Table names are normalized to the `wp_` prefix so the dump is portable.
	 *
	 * @param string $file       Destination .sql path.
	 * @param array  $win        Optional progress window array( start, end ).
	 * @param string $msg_prefix Optional prefix for progress messages.
	 * @return true|WP_Error
	 */
	public static function dump_to_file( $file, $win = null, $msg_prefix = '' ) {
		global $wpdb;

		$fh = fopen( $file, 'w' );
		if ( ! $fh ) {
			return new WP_Error( 'rawbk_dump_open', sprintf( 'Could not open %s for writing.', $file ) );
		}

		$prefix = $wpdb->prefix;
		$tables = $wpdb->get_col( 'SHOW TABLES' );
		$tables = array_values(
			array_filter(
				(array) $tables,
				function ( $table ) use ( $prefix ) {
					return 0 === strpos( $table, $prefix );
				}
			)
		);
		sort( $tables );

		if ( empty( $tables ) ) {
			fclose( $fh );
			return new WP_Error( 'rawbk_dump_no_tables', 'No tables found for the current prefix.' );
		}

		$table_total = count( $tables );
		foreach ( $tables as $table_index => $table ) {
			$normalized = 'wp_' . substr( $table, strlen( $prefix ) );

			if ( $win ) {
				Raw_Backup_Progress::update(
					Raw_Backup_Progress::scale( $win, 100 * $table_index / $table_total ),
					$msg_prefix . sprintf(
						/* translators: 1: table name, 2: current table number, 3: total tables */
						__( 'Exporting table %1$s (%2$d of %3$d)…', 'raw-backup' ),
						$normalized,
						$table_index + 1,
						$table_total
					)
				);
			}

			$create_row = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
			if ( empty( $create_row[1] ) ) {
				fclose( $fh );
				return new WP_Error(
					'rawbk_dump_create',
					sprintf( 'SHOW CREATE TABLE failed for %s: %s', $table, $wpdb->last_error )
				);
			}
			$create = $create_row[1];
			if ( $table !== $normalized ) {
				$create = str_replace( "`{$table}`", "`{$normalized}`", $create );
			}

			fwrite( $fh, "--\n-- Table structure for table `{$normalized}`\n--\n\n" );
			fwrite( $fh, "DROP TABLE IF EXISTS `{$normalized}`;\n" );
			fwrite( $fh, rtrim( $create, "; \n" ) . ";\n\n" );

			$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
			if ( ! $count ) {
				continue;
			}

			fwrite( $fh, "--\n-- Dumping data for table `{$normalized}`\n--\n\n" );

			$order  = self::primary_key_order_clause( $table );
			$offset = 0;
			while ( $offset < $count ) {
				$rows = $wpdb->get_results(
					"SELECT * FROM `{$table}`{$order} LIMIT " . self::ROW_BATCH . " OFFSET {$offset}",
					ARRAY_A
				);
				if ( empty( $rows ) ) {
					break;
				}
				foreach ( $rows as $row ) {
					$row    = self::normalize_prefixed_keys( $row, $normalized, $prefix, 'wp_' );
					$values = implode( ',', array_map( array( __CLASS__, 'escape_value' ), array_values( $row ) ) );
					fwrite( $fh, "INSERT INTO `{$normalized}` VALUES ({$values});\n" );
				}
				$offset += self::ROW_BATCH;

				if ( $win ) {
					$table_fraction = ( $table_index + min( 1, $offset / $count ) ) / $table_total;
					Raw_Backup_Progress::update( Raw_Backup_Progress::scale( $win, 100 * $table_fraction ) );
				}
			}
			fwrite( $fh, "\n" );
		}

		fclose( $fh );
		return true;
	}

	/**
	 * Execute a .sql dump file statement by statement. The parser handles
	 * multi-line statements, quoted strings with escapes and `--` comments.
	 *
	 * @param string $file Path to the .sql file.
	 * @param array  $win  Optional progress window array( start, end ).
	 * @return array|WP_Error { executed: int, failed: int, errors: string[] }
	 */
	public static function import_file( $file, $win = null ) {
		global $wpdb;

		$fh = fopen( $file, 'r' );
		if ( ! $fh ) {
			return new WP_Error( 'rawbk_import_open', sprintf( 'Could not open %s for reading.', $file ) );
		}
		$file_size = max( 1, (int) filesize( $file ) );
		$line_no   = 0;

		$stmt        = '';
		$in_str      = null;
		$executed    = 0;
		$failed      = 0;
		$struct_fail = 0;
		$errors      = array();
		$suppress    = $wpdb->suppress_errors( true );

		$run = function ( $sql ) use ( $wpdb, &$executed, &$failed, &$struct_fail, &$errors ) {
			if ( '' === $sql ) {
				return;
			}
			if ( false === $wpdb->query( $sql ) ) {
				$failed++;
				if ( preg_match( '/^\s*(DROP|CREATE|ALTER)\b/i', $sql ) ) {
					$struct_fail++;
				}
				if ( count( $errors ) < 5 ) {
					$errors[] = $wpdb->last_error . ' — ' . substr( preg_replace( '/\s+/', ' ', $sql ), 0, 120 );
				}
			} else {
				$executed++;
			}
		};

		while ( false !== ( $line = fgets( $fh ) ) ) {
			$line_no++;
			if ( $win && 0 === $line_no % 400 ) {
				Raw_Backup_Progress::update(
					Raw_Backup_Progress::scale( $win, 100 * ftell( $fh ) / $file_size ),
					__( 'Importing database…', 'raw-backup' )
				);
			}
			if ( null === $in_str && '' === trim( $stmt ) ) {
				$trimmed = ltrim( $line );
				if ( '' === trim( $line ) || 0 === strpos( $trimmed, '--' ) ) {
					continue;
				}
				// mysqldump conditional comments, e.g. /*!40101 ... */;
				if ( 0 === strpos( $trimmed, '/*' ) && false !== strpos( $trimmed, '*/' ) ) {
					continue;
				}
			}

			$len = strlen( $line );
			for ( $i = 0; $i < $len; $i++ ) {
				$char = $line[ $i ];

				if ( null !== $in_str ) {
					$stmt .= $char;
					if ( '\\' === $char && '`' !== $in_str ) {
						if ( $i + 1 < $len ) {
							$stmt .= $line[ $i + 1 ];
							$i++;
						}
						continue;
					}
					if ( $char === $in_str ) {
						$in_str = null;
					}
					continue;
				}

				if ( "'" === $char || '"' === $char || '`' === $char ) {
					$in_str = $char;
					$stmt  .= $char;
					continue;
				}
				if ( ';' === $char ) {
					$run( trim( $stmt ) );
					$stmt = '';
					continue;
				}
				$stmt .= $char;
			}
		}
		$run( trim( $stmt ) );
		fclose( $fh );

		$wpdb->suppress_errors( $suppress );

		if ( $struct_fail > 0 ) {
			return new WP_Error(
				'rawbk_import_struct',
				'Failed to create tables during import: ' . implode( ' | ', $errors )
			);
		}

		return array(
			'executed' => $executed,
			'failed'   => $failed,
			'errors'   => $errors,
		);
	}

	/**
	 * After importing a `wp_`-prefixed dump, rename tables to the target
	 * prefix and fix the core prefixed option/meta keys.
	 *
	 * @param string $to Target prefix (e.g. `abc_`).
	 * @return true|WP_Error
	 */
	public static function rename_prefix( $to ) {
		global $wpdb;

		if ( 'wp_' === $to ) {
			return true;
		}

		$tables = (array) $wpdb->get_col( 'SHOW TABLES' );
		foreach ( $tables as $table ) {
			if ( 0 !== strpos( $table, 'wp_' ) ) {
				continue;
			}
			$new = $to . substr( $table, 3 );
			$wpdb->query( "DROP TABLE IF EXISTS `{$new}`" );
			if ( false === $wpdb->query( "RENAME TABLE `{$table}` TO `{$new}`" ) ) {
				return new WP_Error(
					'rawbk_rename',
					sprintf( 'Could not rename table %1$s to %2$s: %3$s', $table, $new, $wpdb->last_error )
				);
			}
		}

		$options  = $to . 'options';
		$usermeta = $to . 'usermeta';
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$options}` SET option_name = %s WHERE option_name = 'wp_user_roles'",
				$to . 'user_roles'
			)
		);
		foreach ( self::PREFIXED_META_KEYS as $key ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE `{$usermeta}` SET meta_key = %s WHERE meta_key = %s",
					$to . $key,
					'wp_' . $key
				)
			);
		}

		return true;
	}

	/**
	 * Refresh table statistics after an import. MySQL 8 caches table stats
	 * for up to 24h (information_schema_stats_expiry), so freshly imported
	 * tables show "0 B" sizes in tools like phpMyAdmin until ANALYZE runs.
	 * Failures are ignored — statistics are a nice-to-have.
	 */
	public static function analyze_tables() {
		global $wpdb;

		$prefix = $wpdb->prefix;
		$tables = array_values(
			array_filter(
				(array) $wpdb->get_col( 'SHOW TABLES' ),
				function ( $table ) use ( $prefix ) {
					return 0 === strpos( $table, $prefix );
				}
			)
		);
		if ( empty( $tables ) ) {
			return;
		}

		$suppress = $wpdb->suppress_errors( true );
		// One statement for all tables; fall back to per-table on failure.
		$list = '`' . implode( '`, `', $tables ) . '`';
		if ( false === $wpdb->query( "ANALYZE TABLE {$list}" ) ) {
			foreach ( $tables as $table ) {
				$wpdb->query( "ANALYZE TABLE `{$table}`" );
			}
		}
		$wpdb->suppress_errors( $suppress );
	}

	/**
	 * Serialized-data-safe search & replace across all text columns of the
	 * current-prefix tables. Returns the number of updated rows.
	 *
	 * @param string $search  Needle.
	 * @param string $replace Replacement.
	 * @param array  $win     Optional progress window array( start, end ).
	 * @return int
	 */
	public static function search_replace( $search, $replace, $win = null ) {
		global $wpdb;

		if ( '' === $search || $search === $replace ) {
			return 0;
		}

		$updated = 0;
		$prefix  = $wpdb->prefix;
		$tables  = array_values(
			array_filter(
				(array) $wpdb->get_col( 'SHOW TABLES' ),
				function ( $table ) use ( $prefix ) {
					return 0 === strpos( $table, $prefix );
				}
			)
		);

		foreach ( $tables as $table_index => $table ) {
			if ( $win ) {
				Raw_Backup_Progress::update(
					Raw_Backup_Progress::scale( $win, 100 * $table_index / max( 1, count( $tables ) ) ),
					__( 'Rewriting URLs…', 'raw-backup' )
				);
			}

			$columns   = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A );
			$text_cols = array();
			$pk        = null;
			foreach ( (array) $columns as $col ) {
				if ( preg_match( '/char|text|blob|json/i', $col['Type'] ) ) {
					$text_cols[] = $col['Field'];
				}
				if ( 'PRI' === $col['Key'] ) {
					$pk = ( null === $pk ) ? $col['Field'] : false; // false = composite, unsupported.
				}
			}
			if ( empty( $text_cols ) || empty( $pk ) ) {
				continue;
			}

			$like  = '%' . $wpdb->esc_like( $search ) . '%';
			$where = implode(
				' OR ',
				array_map(
					function ( $col ) use ( $wpdb, $like ) {
						return $wpdb->prepare( '`' . $col . '` LIKE %s', $like );
					},
					$text_cols
				)
			);
			$select = '`' . $pk . '`, `' . implode( '`, `', $text_cols ) . '`';

			$last_id = null;
			while ( true ) {
				$page_where = $where;
				if ( null !== $last_id ) {
					$page_where = $wpdb->prepare( "`{$pk}` > %s AND ( {$page_where} )", $last_id );
				}
				$rows = $wpdb->get_results(
					"SELECT {$select} FROM `{$table}` WHERE {$page_where} ORDER BY `{$pk}` ASC LIMIT " . self::ROW_BATCH,
					ARRAY_A
				);
				if ( empty( $rows ) ) {
					break;
				}
				foreach ( $rows as $row ) {
					$last_id = $row[ $pk ];
					$changes = array();
					foreach ( $text_cols as $col ) {
						if ( null === $row[ $col ] || false === strpos( $row[ $col ], $search ) ) {
							continue;
						}
						$new = self::replace_in_value( $row[ $col ], $search, $replace );
						if ( $new !== $row[ $col ] ) {
							$changes[ $col ] = $new;
						}
					}
					if ( $changes ) {
						$wpdb->update( $table, $changes, array( $pk => $row[ $pk ] ) );
						$updated++;
					}
				}
				if ( count( $rows ) < self::ROW_BATCH ) {
					break;
				}
			}
		}

		return $updated;
	}

	/**
	 * Replace inside a value, unserializing first when needed so string
	 * lengths in serialized data stay correct.
	 */
	private static function replace_in_value( $value, $search, $replace ) {
		if ( is_serialized( $value ) ) {
			$data = @unserialize( trim( $value ) ); // phpcs:ignore -- same trust level as WP core reading options.
			if ( false !== $data || 'b:0;' === trim( $value ) ) {
				return serialize( self::deep_replace( $data, $search, $replace ) ); // phpcs:ignore
			}
		}
		return str_replace( $search, $replace, $value );
	}

	private static function deep_replace( $data, $search, $replace ) {
		if ( is_string( $data ) ) {
			if ( is_serialized( $data ) ) {
				return self::replace_in_value( $data, $search, $replace );
			}
			return str_replace( $search, $replace, $data );
		}
		if ( is_array( $data ) ) {
			foreach ( $data as $key => $value ) {
				$data[ $key ] = self::deep_replace( $value, $search, $replace );
			}
			return $data;
		}
		if ( is_object( $data ) && ! ( $data instanceof __PHP_Incomplete_Class ) ) {
			foreach ( get_object_vars( $data ) as $key => $value ) {
				$data->{$key} = self::deep_replace( $value, $search, $replace );
			}
			return $data;
		}
		return $data;
	}

	/**
	 * ORDER BY clause on the primary key for stable dump paging, when a
	 * primary key exists.
	 */
	private static function primary_key_order_clause( $table ) {
		global $wpdb;

		$keys = $wpdb->get_results( "SHOW KEYS FROM `{$table}`", ARRAY_A );
		$pk   = array();
		foreach ( (array) $keys as $key ) {
			if ( isset( $key['Key_name'] ) && 'PRIMARY' === $key['Key_name'] ) {
				$pk[ (int) $key['Seq_in_index'] ] = $key['Column_name'];
			}
		}
		if ( empty( $pk ) ) {
			return '';
		}
		ksort( $pk );
		return ' ORDER BY `' . implode( '`, `', $pk ) . '`';
	}

	/**
	 * When exporting from a non-`wp_` prefix, rewrite the core prefixed
	 * option/meta keys stored in row data.
	 */
	private static function normalize_prefixed_keys( $row, $normalized_table, $from_prefix, $to_prefix ) {
		if ( $from_prefix === $to_prefix ) {
			return $row;
		}
		if ( 'wp_options' === $normalized_table && isset( $row['option_name'] ) ) {
			if ( $row['option_name'] === $from_prefix . 'user_roles' ) {
				$row['option_name'] = $to_prefix . 'user_roles';
			}
		}
		if ( 'wp_usermeta' === $normalized_table && isset( $row['meta_key'] ) ) {
			foreach ( self::PREFIXED_META_KEYS as $key ) {
				if ( $row['meta_key'] === $from_prefix . $key ) {
					$row['meta_key'] = $to_prefix . $key;
					break;
				}
			}
		}
		return $row;
	}

	/**
	 * Escape a value for an INSERT statement (mysqldump-compatible).
	 */
	private static function escape_value( $value ) {
		if ( null === $value ) {
			return 'NULL';
		}
		$value = str_replace(
			array( '\\', "\0", "\n", "\r", "'", '"', "\x1a" ),
			array( '\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z' ),
			(string) $value
		);
		return "'{$value}'";
	}
}
