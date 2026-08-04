<?php
/**
 * WP-CLI emulator: db query/tables/prefix and search-replace.
 *
 * Extracted from class-wpvibe-cli.php (mechanical split; no behavior change).
 */

defined( 'ABSPATH' ) || exit;

trait WPVibe_CLI_Db {
	/** Set true when replace_in_value hits a __PHP_Incomplete_Class; the row is skipped. */
	private $sr_incomplete = false;
	private $sr_skipped_serialized = 0;
	private $sr_timed_out = false;



	// ------------------------------------------------------------------
	// DB Query Handler (SELECT only)
	// ------------------------------------------------------------------

	private function handle_db_query( $positional, $flags ) {
		global $wpdb;

		$sql = trim( implode( ' ', $positional ) );
		if ( empty( $sql ) ) {
			return $this->error_result( __( 'SQL query required. Example: db query "SELECT * FROM {prefix}posts LIMIT 10"', 'vibe-ai' ) );
		}

		// Replace {prefix} placeholder with actual table prefix.
		$sql = str_replace( '{prefix}', $wpdb->prefix, $sql );

		// MySQL executable comments (/*!...*/) run at the server despite being
		// stripped by the validator below, so they could smuggle a blocked
		// keyword past it. No legitimate query here needs them; reject outright.
		if ( false !== strpos( $sql, '/*!' ) ) {
			return $this->error_result( __( 'Executable MySQL comments (/*! ... */) are not allowed.', 'vibe-ai' ) );
		}

		// Validate: SELECT only.
		// Strip SQL comments to prevent keyword bypass.
		$stripped = preg_replace( '/--.*$/m', '', $sql );
		$stripped = preg_replace( '/\/\*.*?\*\//s', '', $stripped );
		$normalized = preg_replace( '/\s+/', ' ', strtoupper( trim( $stripped ) ) );

		$is_select = ( strpos( $normalized, 'SELECT' ) === 0 );
		// EXPLAIN is read-only only for SELECT plans (EXPLAIN ANALYZE executes the statement).
		$is_schema_read = (bool) preg_match( '/^(DESCRIBE|DESC|SHOW|EXPLAIN SELECT)\b/', $normalized );

		// SELECT-only path (the common case for auto-execute).
		if ( ! $is_select && ! $is_schema_read && ! $this->skip_destructive ) {
			// classify_destructive should have caught this; defense-in-depth.
			return $this->error_result( __( 'Mutating SQL requires explicit approval. Only SELECT and schema reads (DESCRIBE, SHOW) auto-execute.', 'vibe-ai' ) );
		}

		if ( $is_select ) {
			$blocked = array(
				'INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'TRUNCATE',
				'CREATE', 'GRANT', 'REVOKE', 'EXEC', 'EXECUTE',
				'RENAME', 'REPLACE', 'LOAD', 'OUTFILE', 'DUMPFILE',
			);
			foreach ( $blocked as $keyword ) {
				if ( preg_match( '/\b' . $keyword . '\b/', $normalized ) ) {
					/* translators: %s: SQL keyword */
					return $this->error_result( sprintf( __( 'Blocked SQL keyword in SELECT: %s.', 'vibe-ai' ), $keyword ) );
				}
			}
		}

		// Multi-statement guard applies to both SELECT and mutating paths.
		if ( preg_match( '/;\s*\S/', $sql ) ) {
			return $this->error_result( __( 'Multiple SQL statements are not allowed.', 'vibe-ai' ) );
		}

		if ( $is_select || $is_schema_read ) {
			if ( preg_match( '/\bINTO\s+(OUTFILE|DUMPFILE|@)/i', $normalized ) ) {
				return $this->error_result( __( 'SELECT INTO is not allowed.', 'vibe-ai' ) );
			}

			if ( preg_match( '/\bFOR\s+(UPDATE|SHARE)\b/', $normalized ) ) {
				return $this->error_result( __( 'FOR UPDATE/SHARE is not allowed.', 'vibe-ai' ) );
			}

			$sql = rtrim( $sql, '; ' );
			// Enforce LIMIT on SELECT; DESCRIBE/SHOW don't accept LIMIT and return bounded schema rows.
			if ( $is_select ) {
				$default_limit = 100;
				if ( ! empty( $flags['limit'] ) && is_numeric( $flags['limit'] ) ) {
					$default_limit = min( (int) $flags['limit'], 1000 );
				}
				if ( preg_match( '/\bLIMIT\s+(\d+)/i', $sql, $m ) ) {
					$sql = preg_replace_callback( '/\bLIMIT\s+(\d+)/i', function ( $m ) {
						return 'LIMIT ' . min( (int) $m[1], 1000 );
					}, $sql );
				} else {
					$sql .= ' LIMIT ' . $default_limit;
				}
			}

			// Execute SELECT.
			/*
			 * Raw SQL justification: This handler accepts user-provided SELECT queries
			 * for database inspection. $wpdb->prepare() cannot be used because the full
			 * SQL structure is dynamic. Security is enforced via SELECT-only validation,
			 * blocked keyword list, comment stripping, INTO/FOR UPDATE prevention,
			 * multi-statement prevention, and automatic LIMIT enforcement.
			 */
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$results = $wpdb->get_results( $sql, ARRAY_A ); // nosemgrep: direct-db-query
			if ( $wpdb->last_error ) {
				/* translators: %s: SQL error message */
				return $this->error_result( sprintf( __( 'SQL error: %s', 'vibe-ai' ), $wpdb->last_error ) );
			}

			$output = array(
				'table_prefix'  => $wpdb->prefix,
				'rows_returned' => count( $results ),
				'results'       => $results,
			);

			return array(
				'exit_code' => 0,
				'stdout'    => wp_json_encode( $output, JSON_PRETTY_PRINT ),
				'stderr'    => '',
			);
		}

		// Mutating path — only reachable when skip_destructive is true (caller is run_approved).
		// Use $wpdb->query() which returns affected row count for INSERT/UPDATE/DELETE.
		$sql = rtrim( $sql, '; ' );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$affected = $wpdb->query( $sql ); // nosemgrep: direct-db-query
		if ( false === $affected || $wpdb->last_error ) {
			/* translators: %s: SQL error message */
			return $this->error_result( sprintf( __( 'SQL error: %s', 'vibe-ai' ), $wpdb->last_error ) );
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => sprintf(
				/* translators: 1: number of rows affected */
				_n( 'DB query executed (%d row affected)', 'DB query executed (%d rows affected)', (int) $affected, 'vibe-ai' ),
				(int) $affected
			),
			'action_label' => 'Refresh',
		) );

		return array(
			'exit_code' => 0,
			'stdout'    => wp_json_encode( array(
				'table_prefix'  => $wpdb->prefix,
				'affected_rows' => (int) $affected,
			), JSON_PRETTY_PRINT ),
			'stderr'    => '',
			// COMMAND_META has db query as 'read'-tiered (because it was originally
			// SELECT-only). Override to 'write' on the mutating execution path so
			// the response label matches reality.
			'tier'      => 'write',
		);
	}


	private function handle_search_replace( $positional, $flags ) {
		global $wpdb;

		if ( ! empty( $flags['regex'] ) ) {
			return $this->error_result( __( '--regex is not supported by the WPVibe emulation. Use a literal search string.', 'vibe-ai' ) );
		}
		if ( ! empty( $flags['export'] ) || ! empty( $flags['log'] ) || ! empty( $flags['network'] ) ) {
			return $this->error_result( __( '--export, --log, and --network are not supported by the WPVibe emulation.', 'vibe-ai' ) );
		}
		if ( count( $positional ) < 2 ) {
			return $this->error_result( __( 'Usage: search-replace <old> <new> [<table>...] [--dry-run]', 'vibe-ai' ) );
		}
		$old = $positional[0];
		$new = $positional[1];
		if ( '' === $old ) {
			return $this->error_result( __( 'The <old> search string cannot be empty.', 'vibe-ai' ) );
		}
		if ( $old === $new ) {
			return $this->error_result( __( 'Replacement value is identical to search value; nothing to do.', 'vibe-ai' ) );
		}

		$dry_run = ! empty( $flags['dry_run'] );
		if ( ! $dry_run && ! $this->skip_destructive ) {
			// classify_destructive should have caught this; defense-in-depth.
			return $this->error_result( __( 'search-replace requires explicit approval. Run with --dry-run to preview.', 'vibe-ai' ) );
		}

		$tables = $this->resolve_search_replace_tables( array_slice( $positional, 2 ), $flags );
		if ( is_wp_error( $tables ) ) {
			return $this->error_result( $tables->get_error_message() );
		}

		$skip_columns    = array_filter( wp_parse_list( (string) ( $flags['skip_columns'] ?? '' ) ) );
		$include_columns = array_filter( wp_parse_list( (string) ( $flags['include_columns'] ?? '' ) ) );

		// Never rewrite password hashes. A search string that happens to appear
		// inside a bcrypt hash would corrupt it and lock that user out with no way
		// back. Upstream hard-appends this too, and unlike guid it is not
		// overridable: the skip test below runs before the include test, so even
		// an explicit --include_columns=user_pass yields zero columns rather than
		// re-opening the column.
		$skip_columns[] = 'user_pass';

		$guid_skipped    = false;
		if ( empty( $flags['include_guids'] ) && ! in_array( 'guid', $include_columns, true ) ) {
			// WP best practice: GUIDs are permanent identifiers, not URLs.
			$skip_columns[] = 'guid';
			$guid_skipped   = true;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		// We run inside a REST request, not a shell: keep a hard budget and
		// report completed vs remaining tables so the AI can re-run scoped.
		$deadline = microtime( true ) + 240;

		$this->sr_skipped_serialized = 0;
		$this->sr_timed_out          = false;
		$report    = array();
		$total     = 0;
		$completed = array();
		$remaining = array();

		foreach ( $tables as $i => $table ) {
			if ( microtime( true ) > $deadline ) {
				$this->sr_timed_out = true;
			}
			if ( $this->sr_timed_out ) {
				$remaining = array_slice( $tables, $i );
				break;
			}
			list( $primary_keys, $text_columns ) = $this->table_columns( $table );
			if ( empty( $primary_keys ) ) {
				$report[] = array( 'table' => $table, 'column' => '', 'count' => 0, 'note' => __( 'Skipped: no primary key.', 'vibe-ai' ) );
				$completed[] = $table;
				continue;
			}
			foreach ( $text_columns as $col ) {
				if ( in_array( $col, $skip_columns, true ) || in_array( "$table.$col", $skip_columns, true ) ) {
					continue;
				}
				if ( ! empty( $include_columns ) && ! in_array( $col, $include_columns, true ) && ! in_array( "$table.$col", $include_columns, true ) ) {
					continue;
				}
				$count = $this->search_replace_column( $table, $col, $primary_keys, $old, $new, $dry_run, $deadline );
				if ( $count > 0 ) {
					$report[] = array( 'table' => $table, 'column' => $col, 'count' => $count );
				}
				$total += $count;
				if ( $this->sr_timed_out ) {
					break;
				}
			}
			if ( $this->sr_timed_out ) {
				$remaining = array_slice( $tables, $i );
				break;
			}
			$completed[] = $table;
		}

		if ( ! $dry_run && $total > 0 ) {
			WPVibe_Change_Tracker::mark( array(
				'summary'      => "search-replace: {$total} replacement(s)",
				'action_label' => 'View Site',
				'url'          => home_url( '/' ),
			) );
		}

		$message = $dry_run
			/* translators: %d: replacement count */
			? sprintf( __( '%d replacement(s) to be made.', 'vibe-ai' ), $total )
			/* translators: %d: replacement count */
			: sprintf( __( 'Made %d replacement(s).', 'vibe-ai' ), $total );
		if ( ! $dry_run && $total > 0 && function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
			$message .= ' ' . __( 'A persistent object cache is active — run `cache flush` so stale values are not served.', 'vibe-ai' );
		}

		$data = array(
			'dry_run'      => $dry_run,
			'total'        => $total,
			'report'       => $report,
			'message'      => $message,
		);
		if ( $guid_skipped ) {
			$data['guid_note'] = __( 'The guid column was skipped (WordPress best practice). Pass --include-guids to replace inside GUIDs too.', 'vibe-ai' );
		}
		if ( $this->sr_skipped_serialized > 0 ) {
			/* translators: %d: skipped row count */
			$data['skipped_serialized_rows'] = $this->sr_skipped_serialized;
			$data['skipped_serialized_note'] = __( 'Rows whose serialized data references PHP classes that are not loadable were skipped to avoid corruption.', 'vibe-ai' );
		}
		if ( $this->sr_timed_out ) {
			$data['timed_out']        = true;
			$data['tables_completed'] = $completed;
			$data['tables_remaining'] = $remaining;
			$data['note']             = __( 'Time budget exceeded. Re-run the same command scoped to the remaining tables to finish.', 'vibe-ai' );
		}

		$result = $this->success_result( $data );
		if ( $dry_run ) {
			$result['tier'] = 'read';
		}
		return $result;
	}


	private function resolve_search_replace_tables( $table_args, $flags ) {
		global $wpdb;
		$all = $wpdb->get_col( 'SHOW TABLES' );
		if ( ! is_array( $all ) ) {
			$all = array();
		}
		if ( ! empty( $table_args ) ) {
			$resolved = array();
			foreach ( $table_args as $arg ) {
				$arg = str_replace( '{prefix}', $wpdb->prefix, $arg );
				if ( false !== strpos( $arg, '*' ) || false !== strpos( $arg, '?' ) ) {
					$matched = array();
					foreach ( $all as $t ) {
						if ( fnmatch( $arg, $t ) ) {
							$matched[] = $t;
						}
					}
					if ( empty( $matched ) ) {
						/* translators: %s: table pattern */
						return new WP_Error( 'no_tables', sprintf( __( 'No tables match "%s".', 'vibe-ai' ), $arg ), WPVibe_Error_Contract::data( 'not_found', false ) );
					}
					$resolved = array_merge( $resolved, $matched );
				} elseif ( in_array( $arg, $all, true ) ) {
					$resolved[] = $arg;
				} else {
					/* translators: %s: table name */
					return new WP_Error( 'no_table', sprintf( __( 'Table "%s" does not exist.', 'vibe-ai' ), $arg ), WPVibe_Error_Contract::data( 'not_found', false ) );
				}
			}
			$tables = array_values( array_unique( $resolved ) );
		} elseif ( ! empty( $flags['all_tables'] ) ) {
			$tables = $all;
		} else {
			$tables = array();
			foreach ( $all as $t ) {
				if ( 0 === strpos( $t, $wpdb->prefix ) ) {
					$tables[] = $t;
				}
			}
		}

		$skip_tables = array_filter( wp_parse_list( (string) ( $flags['skip_tables'] ?? '' ) ) );
		if ( $skip_tables ) {
			$tables = array_values( array_filter( $tables, function ( $t ) use ( $skip_tables ) {
				foreach ( $skip_tables as $skip ) {
					if ( $t === $skip || fnmatch( $skip, $t ) ) {
						return false;
					}
				}
				return true;
			} ) );
		}

		if ( empty( $tables ) ) {
			return new WP_Error( 'no_tables', __( 'No tables in scope for search-replace.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'not_found', false ) );
		}
		return $tables;
	}


	/** DESCRIBE a table: [primary key columns, text-family columns (char/varchar/text)]. */
	private function table_columns( $table ) {
		global $wpdb;
		$primary = array();
		$text    = array();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results( 'DESCRIBE ' . $this->esc_sql_ident( $table ) ); // nosemgrep: direct-db-query
		foreach ( (array) $results as $col ) {
			if ( isset( $col->Key ) && 'PRI' === $col->Key ) {
				$primary[] = $col->Field;
			}
			if ( isset( $col->Type ) && ( false !== stripos( $col->Type, 'char' ) || false !== stripos( $col->Type, 'text' ) ) ) {
				$text[] = $col->Field;
			}
		}
		return array( $primary, $text );
	}


	/**
	 * Replace within one table column, chunked by primary key so large tables
	 * never load whole. Mirrors wp-cli's php_handle_col (the --precise path —
	 * always serialized-safe, never blind SQL UPDATE).
	 */
	private function search_replace_column( $table, $col, $primary_keys, $old, $new, $dry_run, $deadline ) {
		global $wpdb;

		$count     = 0;
		$table_sql = $this->esc_sql_ident( $table );
		$col_sql   = $this->esc_sql_ident( $col );
		$old_json  = $this->json_encode_strip_quotes( $old );
		$new_json  = $this->json_encode_strip_quotes( $new );

		$match = $col_sql . $wpdb->prepare( ' LIKE BINARY %s', '%' . $wpdb->esc_like( $old ) . '%' );
		if ( $old_json !== $old ) {
			$match = '( ' . $match . ' OR ' . $col_sql . $wpdb->prepare( ' LIKE BINARY %s', '%' . $wpdb->esc_like( $old_json ) . '%' ) . ' )';
		}

		$single_pk = ( 1 === count( $primary_keys ) );
		$pk_sql    = implode( ', ', array_map( array( $this, 'esc_sql_ident' ), $primary_keys ) );
		$chunk     = 1000;
		$last_key  = null;
		$passes    = 0;

		while ( true ) {
			if ( microtime( true ) > $deadline ) {
				$this->sr_timed_out = true;
				break;
			}
			$where = 'WHERE ' . $match;
			if ( $single_pk && null !== $last_key ) {
				$where .= ' AND ' . $pk_sql . ' > ' . $this->esc_sql_value( $last_key );
			}
			$order = $single_pk ? " ORDER BY {$pk_sql} ASC" : '';
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results( "SELECT {$pk_sql} FROM {$table_sql} {$where}{$order} LIMIT {$chunk}" ); // nosemgrep: direct-db-query
			if ( empty( $rows ) ) {
				break;
			}

			$count_before = $count;
			foreach ( $rows as $keys ) {
				$where_parts = array();
				foreach ( (array) $keys as $k => $v ) {
					$where_parts[] = $this->esc_sql_ident( $k ) . ' = ' . $this->esc_sql_value( $v );
				}
				$where_row = implode( ' AND ', $where_parts );
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$value = $wpdb->get_var( "SELECT {$col_sql} FROM {$table_sql} WHERE {$where_row}" ); // nosemgrep: direct-db-query
				if ( null === $value || '' === $value ) {
					continue;
				}
				$this->sr_incomplete = false;
				$replaced            = $this->replace_in_value( $value, $old, $new, $old_json, $new_json );
				if ( $this->sr_incomplete ) {
					$this->sr_skipped_serialized++;
					continue;
				}
				if ( $replaced === $value || gettype( $replaced ) !== gettype( $value ) ) {
					continue;
				}
				if ( $dry_run ) {
					$count++;
					continue;
				}
				$update_where = array();
				foreach ( (array) $keys as $k => $v ) {
					$update_where[ $k ] = $v;
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$ok = $wpdb->update( $table, array( $col => $replaced ), $update_where );
				if ( false !== $ok ) {
					$count++;
				}
			}

			if ( $single_pk ) {
				$last_row = end( $rows );
				$pk_name  = $primary_keys[0];
				$last_key = $last_row->{$pk_name};
				continue;
			}

			// Composite PK: live runs converge because replaced rows stop
			// matching the LIKE. Dry runs would loop forever, so single capped
			// pass; live runs bail when a pass makes no progress.
			if ( $dry_run || $count === $count_before || ++$passes > 500 ) {
				break;
			}
		}

		return $count;
	}


	private function replace_in_value( $data, $old, $new, $old_json, $new_json, $depth = 0 ) {
		if ( $depth > 64 ) {
			return $data;
		}
		if ( is_string( $data ) ) {
			if ( 'b:0;' === trim( $data ) ) {
				return $data;
			}
			$unserialized = false;
			if ( function_exists( 'is_serialized' ) && is_serialized( $data ) ) {
				$error_level = error_reporting();
				error_reporting( $error_level & ~E_NOTICE & ~E_WARNING ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
				// stdClass only: WordPress uses it everywhere (theme mods, widget
				// data); arbitrary classes would deserialize as side effects.
				$unserialized = @unserialize( $data, array( 'allowed_classes' => array( 'stdClass' ) ) ); // phpcs:ignore
				error_reporting( $error_level ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			}
			if ( false !== $unserialized ) {
				$inner = $this->replace_in_value( $unserialized, $old, $new, $old_json, $new_json, $depth + 1 );
				if ( $this->sr_incomplete ) {
					return $data;
				}
				return serialize( $inner ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
			}
			$data = str_replace( $old, $new, $data );
			if ( $old_json !== $old ) {
				// Raw JSON in the DB (font data, block attrs) stores escaped slashes.
				$data = str_replace( $old_json, $new_json, $data );
			}
			return $data;
		}
		if ( is_array( $data ) ) {
			foreach ( $data as $k => $v ) {
				$data[ $k ] = $this->replace_in_value( $v, $old, $new, $old_json, $new_json, $depth + 1 );
			}
			return $data;
		}
		if ( $data instanceof \__PHP_Incomplete_Class ) {
			$this->sr_incomplete = true;
			return $data;
		}
		if ( is_object( $data ) ) {
			foreach ( get_object_vars( $data ) as $k => $v ) {
				$data->$k = $this->replace_in_value( $v, $old, $new, $old_json, $new_json, $depth + 1 );
			}
			return $data;
		}
		return $data;
	}


	private function build_search_replace_dry_run( $old, $new, $table_args, $flags ) {
		global $wpdb;
		$preview = array(
			'command' => 'wp search-replace',
			'old'     => $old,
			'new'     => $new,
		);

		$tables = $this->resolve_search_replace_tables( $table_args, $flags );
		if ( is_wp_error( $tables ) ) {
			$preview['note'] = $tables->get_error_message();
			return $preview;
		}

		$deadline      = microtime( true ) + 15;
		$cap           = 1000;
		$counts        = array();
		$not_previewed = 0;
		foreach ( $tables as $table ) {
			if ( microtime( true ) > $deadline ) {
				$not_previewed++;
				continue;
			}
			list( , $text_columns ) = $this->table_columns( $table );
			if ( empty( $text_columns ) ) {
				continue;
			}
			$conds = array();
			foreach ( $text_columns as $col ) {
				$conds[] = $this->esc_sql_ident( $col ) . $wpdb->prepare( ' LIKE BINARY %s', '%' . $wpdb->esc_like( $old ) . '%' );
			}
			$sql = 'SELECT COUNT(*) FROM (SELECT 1 FROM ' . $this->esc_sql_ident( $table ) . ' WHERE ' . implode( ' OR ', $conds ) . ' LIMIT ' . ( $cap + 1 ) . ') AS subq';
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$n = $wpdb->get_var( $sql ); // nosemgrep: direct-db-query
			if ( null === $n || ! empty( $wpdb->last_error ) ) {
				continue;
			}
			$n = (int) $n;
			if ( $n > 0 ) {
				$counts[ $table ] = ( $n > $cap ) ? $cap . '+' : $n;
			}
		}

		$preview['tables_in_scope']          = count( $tables );
		$preview['matching_rows_per_table']  = $counts;
		if ( $not_previewed > 0 ) {
			/* translators: %d: table count */
			$preview['preview_truncated'] = sprintf( __( '%d table(s) not scanned for the preview (time budget); they will still be processed on execution.', 'vibe-ai' ), $not_previewed );
		}

		$warnings = array();
		foreach ( array( 'siteurl', 'home' ) as $opt ) {
			$val = get_option( $opt );
			if ( is_string( $val ) && '' !== $val && false !== strpos( $val, $old ) ) {
				/* translators: 1: option name, 2: current value */
				$warnings[] = sprintf( __( 'This replacement will change the "%1$s" option (currently "%2$s"). Changing the site URL can break the WPVibe connection itself (the stored site URL will no longer match) and logs everyone out. Only approve if this is an intentional migration.', 'vibe-ai' ), $opt, $val );
			}
		}
		if ( $warnings ) {
			$preview['warnings'] = $warnings;
		}
		if ( empty( $flags['include_guids'] ) ) {
			$preview['guid_note'] = __( 'The guid column is skipped by default (WordPress best practice). Pass --include-guids to replace inside GUIDs too.', 'vibe-ai' );
		}
		$preview['note'] = __( 'Counts are rows containing the search string per table, not total replacements. Serialized values are handled safely at execution. Tip: run with --dry-run first for an exact replacement count.', 'vibe-ai' );
		return $preview;
	}


	/** Backtick-escape a MySQL identifier (doubling embedded backticks). */
	private function esc_sql_ident( $ident ) {
		return '`' . str_replace( '`', '``', $ident ) . '`';
	}


	/**
	 * Quote a value for use in WHERE against a primary key. Deliberately
	 * diverges from upstream WP-CLI (which passes numeric-looking values as
	 * bare literals): on a string PK, `pk = 0123` compares numerically and
	 * matches '123' too — the row loop then reads one row's content and
	 * writes it into another. Quoted constants cast once on int columns and
	 * still use the index, so always quoting costs nothing.
	 */
	private function esc_sql_value( $value ) {
		return "'" . esc_sql( (string) $value ) . "'";
	}


	/** JSON-encoded form of a string without the surrounding quotes ("a/b" → "a\/b"). */
	private function json_encode_strip_quotes( $str ) {
		$encoded = json_encode( $str ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		return false !== $encoded ? substr( $encoded, 1, -1 ) : $str;
	}


	private function handle_db_tables( $positional, $flags ) {
		global $wpdb;
		$tables = $wpdb->get_col( 'SHOW TABLES' );
		return $this->success_result( is_array( $tables ) ? $tables : array() );
	}


	private function handle_db_prefix( $positional, $flags ) {
		global $wpdb;
		return $this->success_result( array( 'prefix' => $wpdb->prefix ) );
	}

}
