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
	/** Options search-replace may rewrite after approval (a domain migration); every other protected option is skipped. */
	private static $sr_address_options = array( 'siteurl', 'home' );
	/** Identity tables resolve_search_replace_tables dropped from scope. */
	private $sr_protected_tables = array();



	// ------------------------------------------------------------------
	// DB Query Handler (SELECT only)
	// ------------------------------------------------------------------

	/**
	 * The statement text for `db query`. The tokenizer strips quote marks and
	 * rejoins tokens with spaces, which turns `SELECT 1 "UNION SELECT SLEEP(1)"`
	 * into live SQL (#397). When the command came through execute(), the raw
	 * statement captured there wins; direct handler calls (tests, internal
	 * callers) still fall back to the positional join.
	 */
	private function db_query_statement( $positional ) {
		if ( null !== $this->db_query_raw ) {
			return $this->db_query_raw;
		}
		return trim( implode( ' ', $positional ) );
	}


	/**
	 * Raw statement from the command text after `db query`, byte for byte.
	 * Trailing/leading `--flag[=value]` tokens are dropped (db query takes only
	 * --limit). A statement wrapped whole in one pair of quotes, the documented
	 * form, is unwrapped with shell semantics (`\"` and `\\` inside double
	 * quotes; the `'\''` apostrophe idiom inside single quotes). Anything else
	 * is handed to MySQL exactly as typed, so a quote mark that is not a
	 * wrapper is a quote mark to MySQL too, never command structure.
	 */
	private function db_query_raw_statement( $rest ) {
		$rest = trim( (string) $rest );
		$flag = '--[a-z][\w-]*(?:=(?:"[^"]*"|\'[^\']*\'|\S+))?';
		$rest = trim( preg_replace( '/^(?:' . $flag . '\s+)+/i', '', $rest ) );
		$rest = trim( preg_replace( '/(?:\s+' . $flag . ')+$/i', '', $rest ) );
		if ( strlen( $rest ) < 2 ) {
			return $rest;
		}
		$q = $rest[0];
		if ( '"' === $q && preg_match( '/^"((?:[^"\\\\]|\\\\.)*)"$/s', $rest, $m ) ) {
			return preg_replace( '/\\\\(["\\\\])/', '$1', $m[1] );
		}
		if ( "'" === $q ) {
			$joined = str_replace( "'\\''", "\x00", $rest );
			if ( preg_match( "/^'([^']*)'$/s", $joined, $m ) ) {
				return str_replace( "\x00", "'", $m[1] );
			}
		}
		return $rest;
	}


	/**
	 * The whole db query gate. A statement that starts with SELECT, SHOW,
	 * DESCRIBE, DESC or EXPLAIN SELECT is a read: MySQL will not let it write,
	 * so its text is not scanned for write words (#385: a write word inside a
	 * quoted literal is not a write). EXPLAIN alone is not enough: EXPLAIN
	 * ANALYZE executes the statement it explains, UPDATE and DELETE included.
	 * A read that calls SLEEP, BENCHMARK, GET_LOCK or RELEASE_LOCK can tie up
	 * the database, so it is held for approval (#397). Everything else is a
	 * write and is held for approval as before. WITH is not a read marker:
	 * MySQL 8 allows `WITH ... UPDATE`.
	 *
	 * @return array{0: 'read'|'slow'|'write', 1: string} kind and the first keyword.
	 */
	private function sql_verdict( $sql ) {
		$upper = strtoupper( trim( $this->strip_sql_comments_for_validation( $sql ) ) );
		if ( ! preg_match( '/^(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN\s+SELECT)\b/', $upper, $m ) ) {
			preg_match( '/^([A-Z_]+)/', $upper, $w );
			return array( 'write', isset( $w[1] ) ? $w[1] : 'SQL' );
		}
		if ( preg_match( '/\b(SLEEP|BENCHMARK|GET_LOCK|RELEASE_LOCK)\s*\(/', $this->sql_scan_text( $sql ), $s ) ) {
			return array( 'slow', $s[1] );
		}
		return array( 'read', preg_replace( '/\s+.*/s', '', $m[1] ) );
	}


	/**
	 * Uppercased text for keyword scans: the raw statement and the
	 * comment-stripped, whitespace-collapsed copy, one after the other. MySQL
	 * treats a comment as whitespace, so `SLEEP/**\/(1)` and `FOR/**\/UPDATE`
	 * run as written; scanning both copies means neither a comment nor a quote
	 * trick hides a keyword, at the cost of over-refusing a content query that
	 * literally contains one.
	 */
	private function sql_scan_text( $sql ) {
		return strtoupper( $sql ) . "\n" . $this->normalize_sql_for_gate( $sql );
	}


	/**
	 * Best-effort per-session statement timeout for the read path, so a slow
	 * read cannot hold a connection past 30 s. MySQL 5.7.8+ reads
	 * MAX_EXECUTION_TIME (ms); MariaDB reads max_statement_time (s). A server
	 * that knows neither returns an error we swallow. 0 resets.
	 */
	private function db_query_read_timeout( $seconds ) {
		global $wpdb;
		$mariadb = method_exists( $wpdb, 'db_server_info' ) && false !== stripos( (string) $wpdb->db_server_info(), 'mariadb' );
		$set     = $mariadb
			? 'SET SESSION max_statement_time=' . (int) $seconds
			: 'SET SESSION MAX_EXECUTION_TIME=' . ( (int) $seconds * 1000 );
		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $set ); // nosemgrep: direct-db-query
		$wpdb->suppress_errors( $suppress );
		$wpdb->last_error = '';
	}


	private function handle_db_query( $positional, $flags ) {
		global $wpdb;

		$sql = $this->db_query_statement( $positional );
		if ( '' === $sql ) {
			return $this->error_result( __( 'SQL query required. Example: db query "SELECT * FROM {prefix}posts LIMIT 10"', 'vibe-ai' ) );
		}

		// Replace {prefix} placeholder with actual table prefix.
		$sql = str_replace( '{prefix}', $wpdb->prefix, $sql );

		// One statement at a time (a trailing semicolon is fine).
		if ( preg_match( '/;\s*\S/', $sql ) ) {
			return $this->error_result( __( 'Multiple SQL statements are not allowed. Run one statement at a time.', 'vibe-ai' ) );
		}

		// MySQL (/*!...*/) and MariaDB (/*M!...*/) executable comments run at
		// the server; no legitimate query here needs them.
		if ( preg_match( '#/\*[Mm]?!#', $sql ) ) {
			return $this->error_result( __( 'Executable MySQL comments (/*! ... */) are not allowed.', 'vibe-ai' ) );
		}

		// File access (INTO OUTFILE/DUMPFILE, LOAD_FILE) is never legitimate on
		// either path. Scanned on the RAW text and on the comment-stripped copy
		// (INTO/**/OUTFILE), so neither a comment nor a quote game hides it; a
		// content query literally containing this text over-refuses, acceptably.
		$scan = $this->sql_scan_text( $sql );
		if ( preg_match( '/\bINTO\s+(?:OUTFILE|DUMPFILE)\b/', $scan ) || preg_match( '/\bLOAD_FILE\s*\(/', $scan ) ) {
			return $this->error_result( __( 'File-access SQL (INTO OUTFILE / DUMPFILE, LOAD_FILE) is not allowed.', 'vibe-ai' ) );
		}

		list( $kind, $keyword ) = $this->sql_verdict( $sql );
		$is_select = ( 'SELECT' === $keyword );

		// classify_destructive holds writes and slow reads for approval; this is
		// defense in depth for a direct call.
		if ( 'write' === $kind && ! $this->skip_destructive ) {
			return $this->error_result( __( 'Mutating SQL requires explicit approval. Only SELECT and schema reads (DESCRIBE, SHOW, EXPLAIN) auto-execute.', 'vibe-ai' ) );
		}
		if ( 'slow' === $kind && ! $this->skip_destructive ) {
			/* translators: %s: SQL function name */
			return $this->error_result( sprintf( __( 'SQL that calls %s() can tie up the database and needs explicit approval.', 'vibe-ai' ), $keyword ) );
		}

		if ( 'write' === $kind ) {
			// Identity/privilege state is unapprovable by design: approval-gated
			// SQL runs with no WP-level guardrails, so one approved statement
			// against these targets is a site-takeover primitive (siteurl,
			// active_plugins, wp_capabilities, the users table).
			$privileged = $this->privileged_sql_target_error( $sql );
			if ( $privileged ) {
				return $privileged;
			}
		} else {
			// A read that locks rows or writes a variable/file is not a read.
			if ( preg_match( '/\bINTO\s+@/', $scan ) ) {
				return $this->error_result( __( 'SELECT INTO is not allowed.', 'vibe-ai' ) );
			}
			if ( preg_match( '/\bFOR\s+(UPDATE|SHARE)\b/', $scan ) || preg_match( '/\bLOCK\s+IN\s+SHARE\s+MODE\b/', $scan ) ) {
				return $this->error_result( __( 'FOR UPDATE/SHARE is not allowed.', 'vibe-ai' ) );
			}

			$sql = rtrim( $sql, '; ' );
			// Enforce LIMIT on SELECT; DESCRIBE/SHOW/EXPLAIN don't accept LIMIT and
			// return bounded rows. Appended on a new line so a trailing `-- note`
			// comment cannot swallow it.
			if ( $is_select ) {
				$default_limit = 100;
				if ( ! empty( $flags['limit'] ) && is_numeric( $flags['limit'] ) ) {
					$default_limit = min( (int) $flags['limit'], 1000 );
				}
				if ( preg_match( '/\bLIMIT\s+(\d+)/i', $sql ) ) {
					$sql = preg_replace_callback( '/\bLIMIT\s+(\d+)/i', function ( $m ) {
						return 'LIMIT ' . min( (int) $m[1], 1000 );
					}, $sql );
				} else {
					$sql .= "\nLIMIT " . $default_limit;
				}
			}

			/*
			 * Raw SQL justification: this handler accepts user-provided read
			 * statements for database inspection; $wpdb->prepare() cannot be used
			 * because the whole statement is dynamic. Only SELECT/SHOW/DESCRIBE/
			 * EXPLAIN-led statements reach here, single statement, no file access,
			 * no row locks, LIMIT enforced, 30 s statement timeout.
			 */
			$this->db_query_read_timeout( 30 );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$results = $wpdb->get_results( $sql, ARRAY_A ); // nosemgrep: direct-db-query
			$error   = $wpdb->last_error;
			$this->db_query_read_timeout( 0 );
			if ( $error ) {
				/* translators: %s: SQL error message */
				return $this->error_result( sprintf( __( 'SQL error: %s', 'vibe-ai' ), $error ) );
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

	/**
	 * Hard-refuse mutating SQL that names a protected identity table anywhere
	 * in the statement, even on the approved path: the users, usermeta and
	 * options tables (per-site and multisite forms), the network tables, and
	 * the WPVibe audit log. Judged on the table, not on which column, row id,
	 * LIKE, CONCAT or hex literal the statement uses, so no spelling of the
	 * row gets past it. Identifiers are read with a quote-aware scan (string
	 * literals are content, not tables), so prose that mentions a table name
	 * inside a value is unaffected. Reads never reach this.
	 */
	private function privileged_sql_target_error( $sql ) {
		$normalized = $this->normalize_sql_for_gate( $sql );
		if ( preg_match( '/^\s*DROP\s+(?:DATABASE|SCHEMA)\b/', $normalized ) ) {
			return $this->error_result( __( 'Refused: DROP DATABASE removes every table on the site, including accounts, settings and the WPVibe audit log. It is blocked even with approval.', 'vibe-ai' ) );
		}
		// Dynamic SQL runs text the table scan cannot see.
		if ( preg_match( '/^\s*(?:EXECUTE|PREPARE|DEALLOCATE)\b/', $normalized ) ) {
			return $this->error_result( __( 'Refused: dynamic SQL (PREPARE / EXECUTE / EXECUTE IMMEDIATE) is not allowed. Run the statement itself.', 'vibe-ai' ) );
		}
		// Stored routines and events run a body the table scan never sees at CALL time.
		if ( preg_match( '/^\s*(?:CALL\b|CREATE\s+(?:OR\s+REPLACE\s+)?(?:DEFINER\s*=\s*\S+\s+)?(?:AGGREGATE\s+)?(?:PROCEDURE|FUNCTION|EVENT)\b|ALTER\s+EVENT\b)/', $normalized ) ) {
			return $this->error_result( __( 'Refused: stored procedures, functions and events (CREATE PROCEDURE / FUNCTION / EVENT, CALL) are not allowed through db query. Run the statement itself.', 'vibe-ai' ) );
		}
		$tables = $this->sql_protected_tables_named( $sql );
		if ( empty( $tables ) ) {
			return null;
		}
		if ( in_array( 'options', $tables, true ) ) {
			// Builder design-system options have their own diff-previewing path.
			$normalized = $this->normalize_sql_for_gate( $sql );
			foreach ( WPVibe_CLI::GATED_OPTIONS as $name ) {
				if ( $this->sql_names_option( $normalized, $name ) ) {
					return $this->error_result( sprintf(
						/* translators: %1$s: option key */
						__( 'Refused: this raw SQL writes the builder option \'%1$s\' as an opaque blob, bypassing its validation and the leaf-level change preview the user reviews. Use run_wp_cli `option update %1$s \'<json text>\' --format=plaintext` to rewrite the whole value, or `option patch update %1$s <key-path> <value>` to change one key; both show the user the exact change for approval.', 'vibe-ai' ),
						$name
					) );
				}
			}
		}
		return $this->privileged_refusal( $tables[0] );
	}

	/**
	 * Protected tables the statement can write, as short keys (users,
	 * usermeta, options, sitemeta, site, blogs, audit_log). For UPDATE,
	 * DELETE, INSERT and REPLACE that is every table in the target clause
	 * (before SET, before WHERE, after INTO), so a subquery that only reads
	 * the users table in a WHERE stays allowed; for anything else (DDL,
	 * CREATE VIEW/TRIGGER, LOAD DATA, WITH ...) it is every table named.
	 * Scanned twice, with and without backslash escapes in literals, so a
	 * server running NO_BACKSLASH_ESCAPES cannot turn a "literal" into live
	 * identifiers; a double-quoted literal counts as a name (ANSI_QUOTES).
	 */
	private function sql_protected_tables_named( $sql ) {
		global $wpdb;
		$sql   = str_ireplace( '{prefix}', (string) $wpdb->prefix, (string) $sql );
		$found = array();
		foreach ( array( true, false ) as $backslash ) {
			foreach ( $this->sql_write_target_zone( $this->sql_identifier_tokens( $sql, $backslash ) ) as $token ) {
				$key = 'p' === $token[0] ? null : $this->protected_table_key( $token[1] );
				if ( null !== $key && ! in_array( $key, $found, true ) ) {
					$found[] = $key;
				}
			}
		}
		return $found;
	}

	/** The tokens of the statement's write-target clause (see sql_protected_tables_named). */
	private function sql_write_target_zone( $tokens ) {
		$first = '';
		foreach ( $tokens as $t ) {
			if ( 'w' === $t[0] ) {
				$first = strtoupper( $t[1] );
				break;
			}
			if ( 'p' !== $t[0] || '(' !== $t[1] ) {
				return $tokens;
			}
		}
		// WITH ...: the CTE bodies only read; judge the statement they feed.
		if ( 'WITH' === $first ) {
			$depth = 0;
			$prev  = null;
			foreach ( $tokens as $idx => $t ) {
				if ( 'p' === $t[0] && '(' === $t[1] ) {
					$depth++;
				} elseif ( 'p' === $t[0] && ')' === $t[1] ) {
					$depth = max( 0, $depth - 1 );
				} elseif ( 0 === $depth && 'w' === $t[0] && ( null === $prev || '.' !== $prev[1] ) ) {
					$w = strtoupper( $t[1] );
					if ( 'SELECT' === $w ) {
						return array();
					}
					if ( in_array( $w, array( 'UPDATE', 'DELETE', 'INSERT', 'REPLACE' ), true ) ) {
						return $this->sql_write_target_zone( array_slice( $tokens, $idx ) );
					}
				}
				$prev = $t;
			}
			return $tokens;
		}
		// Table maintenance (CHECK/ANALYZE/... TABLE) reads or rebuilds; it never
		// changes a row. MariaDB's ANALYZE UPDATE/DELETE runs the statement, so
		// only the TABLE form is exempt.
		if ( in_array( $first, array( 'CHECK', 'CHECKSUM', 'ANALYZE', 'OPTIMIZE', 'REPAIR' ), true ) ) {
			$words = array();
			foreach ( $tokens as $t ) {
				if ( 'w' === $t[0] && count( $words ) < 3 ) {
					$words[] = strtoupper( $t[1] );
				}
			}
			$form = array_slice( $words, 1 );
			if ( isset( $form[0] ) && ( 'TABLE' === $form[0] || ( in_array( $form[0], array( 'NO_WRITE_TO_BINLOG', 'LOCAL' ), true ) && isset( $form[1] ) && 'TABLE' === $form[1] ) ) ) {
				return array();
			}
			return $tokens;
		}
		// CREATE TABLE ... SELECT / LIKE only reads its source; views, triggers
		// and routines fall through to every table named.
		if ( 'CREATE' === $first ) {
			$zone  = array();
			$after = false;
			$seen  = 0;
			foreach ( $tokens as $t ) {
				$word = 'w' === $t[0] ? strtoupper( $t[1] ) : null;
				if ( ! $after ) {
					if ( null !== $word && ++$seen > 3 ) {
						return $tokens;
					}
					$after = 'TABLE' === $word;
					continue;
				}
				if ( ( 'p' === $t[0] && '(' === $t[1] ) || in_array( $word, array( 'AS', 'SELECT', 'LIKE', 'IGNORE', 'REPLACE', 'WITH' ), true ) ) {
					return $zone;
				}
				$zone[] = $t;
			}
			return $after ? $zone : $tokens;
		}
		$stops = array(
			'UPDATE'  => array( 'SET' ),
			'DELETE'  => array( 'WHERE', 'ORDER', 'LIMIT', 'RETURNING' ),
			'INSERT'  => array( 'VALUES', 'VALUE', 'SELECT', 'SET', 'TABLE', 'WITH', 'PARTITION', '(' ),
			'REPLACE' => array( 'VALUES', 'VALUE', 'SELECT', 'SET', 'TABLE', 'WITH', 'PARTITION', '(' ),
		);
		if ( ! isset( $stops[ $first ] ) ) {
			return $tokens;
		}
		$zone    = array();
		$depth   = 0;
		$started = false;
				$count   = count( $tokens );
		for ( $idx = 0; $idx < $count; $idx++ ) {
			$t = $tokens[ $idx ];
			if ( ! $started ) {
				$started = 'w' === $t[0] && strtoupper( $t[1] ) === $first;
				continue;
			}
			$qualified = $idx > 0 && 'p' === $tokens[ $idx - 1 ][0] && '.' === $tokens[ $idx - 1 ][1];
			// After a dot a keyword is a column name (x.set, x.where), never a clause.
			$word = 'w' === $t[0] && ! $qualified ? strtoupper( $t[1] ) : ( 'p' === $t[0] && '.' !== $t[1] ? $t[1] : null );
			if ( 0 === $depth && null !== $word && in_array( $word, $stops[ $first ], true ) ) {
				return $zone;
			}
			// A derived table, (SELECT ...) or (WITH ...), is read-only in MySQL.
			if ( 'p' === $t[0] && '(' === $t[1] && isset( $tokens[ $idx + 1 ] ) && 'w' === $tokens[ $idx + 1 ][0] && in_array( strtoupper( $tokens[ $idx + 1 ][1] ), array( 'SELECT', 'WITH' ), true ) ) {
				for ( $inner = 0; $idx < $count; $idx++ ) {
					if ( 'p' === $tokens[ $idx ][0] && '(' === $tokens[ $idx ][1] ) {
						$inner++;
					} elseif ( 'p' === $tokens[ $idx ][0] && ')' === $tokens[ $idx ][1] && 0 === --$inner ) {
						break;
					}
				}
				continue;
			}
			if ( 'p' === $t[0] && '(' === $t[1] ) {
				$depth++;
			} elseif ( 'p' === $t[0] && ')' === $t[1] ) {
				$depth = max( 0, $depth - 1 );
			}
			$zone[] = $t;
		}
		return $zone;
	}

	/** Short key (users, usermeta, options, sitemeta, site, blogs, audit_log) when $name is a protected table of this install, else null. */
	private function protected_table_key( $name ) {
		global $wpdb;
		// CUSTOM_USER_TABLE / CUSTOM_USER_META_TABLE and other remaps: the live mapping wins.
		foreach ( array( 'users', 'usermeta', 'options', 'sitemeta', 'site', 'blogs' ) as $prop ) {
			// A schema-qualified mapping (shared.accounts) is compared on its table part; tokens arrive split at the dot.
			$mapped = ! empty( $wpdb->$prop ) ? str_replace( '`', '', (string) $wpdb->$prop ) : '';
			$mapped = false !== strrpos( $mapped, '.' ) ? substr( $mapped, strrpos( $mapped, '.' ) + 1 ) : $mapped;
			if ( '' !== $mapped && 0 === strcasecmp( $mapped, (string) $name ) ) {
				return $prop;
			}
		}
		$prefixes = array_unique( array_filter( array( (string) $wpdb->prefix, isset( $wpdb->base_prefix ) ? (string) $wpdb->base_prefix : '', 'wp_' ), 'strlen' ) );
		$prefix   = '(?:' . implode( '|', array_map( function ( $p ) {
			return preg_quote( $p, '/' );
		}, $prefixes ) ) . ')';
		if ( ! preg_match( '/^' . $prefix . '(?:\d+_)?(users|usermeta|options|sitemeta|site|blogs|wpvibe_audit_log)$/i', (string) $name, $m ) ) {
			return null;
		}
		$key = strtolower( $m[1] );
		return 'wpvibe_audit_log' === $key ? 'audit_log' : $key;
	}

	/**
	 * Tokens MySQL would see, as [type, text]: w bare word, q `backticked`
	 * name (`` unescaped), d double-quoted literal, p parenthesis; string
	 * literals and comments are skipped. An executable comment's body is
	 * scanned as code.
	 */
	private function sql_identifier_tokens( $sql, $backslash_escapes ) {
		$tokens = array();
		$len    = strlen( $sql );
		$i      = 0;
		while ( $i < $len ) {
			$c    = $sql[ $i ];
			$next = $i + 1 < $len ? $sql[ $i + 1 ] : '';
			if ( "'" === $c || '"' === $c || '`' === $c ) {
				$buf = '';
				$i++;
				while ( $i < $len ) {
					$ch = $sql[ $i ];
					if ( $backslash_escapes && '\\' === $ch && '`' !== $c ) {
						$buf .= $i + 1 < $len ? $sql[ $i + 1 ] : '';
						$i   += 2;
						continue;
					}
					if ( $ch === $c ) {
						if ( $i + 1 < $len && $sql[ $i + 1 ] === $c ) {
							$buf .= $c;
							$i   += 2;
							continue;
						}
						break;
					}
					$buf .= $ch;
					$i++;
				}
				$i++;
				if ( "'" !== $c ) {
					$tokens[] = array( '`' === $c ? 'q' : 'd', $buf );
				}
				continue;
			}
			if ( '#' === $c || ( '-' === $c && '-' === $next && ( $i + 2 >= $len || ctype_space( $sql[ $i + 2 ] ) ) ) ) {
				$eol = strpos( $sql, "\n", $i );
				$i   = false === $eol ? $len : $eol + 1;
				continue;
			}
			if ( '/' === $c && '*' === $next ) {
				if ( preg_match( '/\G\/\*[Mm]?!/', $sql, $em, 0, $i ) ) {
					$i += strlen( $em[0] );
					continue;
				}
				$end = strpos( $sql, '*/', $i + 2 );
				$i   = false === $end ? $len : $end + 2;
				continue;
			}
			if ( preg_match( '/[A-Za-z0-9_$\x80-\xff]/', $c ) ) {
				$start = $i;
				while ( $i < $len && preg_match( '/[A-Za-z0-9_$\x80-\xff]/', $sql[ $i ] ) ) {
					$i++;
				}
				$tokens[] = array( 'w', substr( $sql, $start, $i - $start ) );
				continue;
			}
			if ( '(' === $c || ')' === $c || '.' === $c ) {
				$tokens[] = array( 'p', $c );
			}
			$i++;
		}
		return $tokens;
	}

	/**
	 * True when the normalized (uppercased) SQL names this option in a quoted
	 * literal. Only picks the builder-option guidance; the refusal itself is
	 * table-based and does not depend on it.
	 */
	private function sql_names_option( $normalized, $name ) {
		$prefix = WPVibe_CLI::option_list_prefix( $name );
		if ( null !== $prefix ) {
			return (bool) preg_match( '/["\']\s*' . preg_quote( strtoupper( $prefix ), '/' ) . '/', $normalized );
		}
		return (bool) preg_match( '/["\']\s*' . preg_quote( strtoupper( (string) $name ), '/' ) . '\s*["\']/', $normalized );
	}


	/**
	 * Strip SQL comments from the VALIDATION copy only. Execution always uses the
	 * original $sql, so real comment-bearing content (Gutenberg block markup,
	 * CSS comments, hex colors) is never altered. Shared by handle_db_query and
	 * classify_destructive so their keyword views cannot desync. Each comment
	 * becomes a SPACE (not empty) so tokens cannot fuse past a word-boundary
	 * guard, and the grammar matches MySQL so the validation copy agrees with
	 * what the server runs: a double-dash starts a comment only when followed by
	 * whitespace or end (so a no-space double-dash stays as arithmetic and any
	 * keyword after it is still seen), a hash runs to line end, and a slash-star
	 * block is removed. Quote-blind (the limitation #59 is about), so it can
	 * over-strip a comment token that is really inside a value; harmless, because
	 * it only mangles the copy we validate, never what we execute, and the one
	 * visible effect is a rare over-refusal (e.g. the no-WHERE options guard on a
	 * hex-color value before the WHERE). Server-executed comments are rejected
	 * upstream, before this runs.
	 */
	private function strip_sql_comments_for_validation( $sql ) {
		$s = preg_replace( '/--(?=\s|$)[^\n]*/m', ' ', (string) $sql );
		$s = preg_replace( '/#[^\n]*/', ' ', $s );
		return preg_replace( '#/\*.*?\*/#s', ' ', $s );
	}


	/** Case-fold + whitespace-collapse the comment-stripped copy for the gate checks. */
	private function normalize_sql_for_gate( $sql ) {
		return preg_replace( '/\s+/', ' ', strtoupper( trim( $this->strip_sql_comments_for_validation( $sql ) ) ) );
	}


	private function privileged_refusal( $table ) {
		$guidance = array(
			'users'     => __( 'the users table. Use `user update <id> --user_email=... / --display_name=...`, `user set-role`, or `user create` instead; they carry the approval and last-admin checks raw SQL skips.', 'vibe-ai' ),
			'usermeta'  => __( 'the usermeta table. Use `user meta update <id> <key> <value>` for ordinary user meta, and `user set-role` / `user add-cap` for roles and capabilities.', 'vibe-ai' ),
			'options'   => __( 'the options table. Use `option update <name> <value>` or `option patch update <name> <key-path> <value>` instead; ordinary options apply without an approval prompt.', 'vibe-ai' ),
			'sitemeta'  => __( 'the network options table (sitemeta). Change network settings in Network Admin instead.', 'vibe-ai' ),
			'site'      => __( 'the network table (site). Change network settings in Network Admin instead.', 'vibe-ai' ),
			'blogs'     => __( 'the network sites table (blogs). Change a site\'s address in Network Admin > Sites instead.', 'vibe-ai' ),
			'audit_log' => __( 'the WPVibe audit log. The log is append-only and cannot be edited or cleared through SQL.', 'vibe-ai' ),
		);
		return $this->error_result( sprintf(
			/* translators: %s: the protected table and the command to use instead */
			__( 'Refused: this SQL writes to %s Raw SQL writes to this table are blocked even with approval, whatever column, row id or expression the statement uses. Reads (SELECT) still work.', 'vibe-ai' ),
			isset( $guidance[ $table ] ) ? $guidance[ $table ] : $table
		) );
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

		list( $skip_columns, $include_columns, $guid_skipped ) = $this->search_replace_column_filters( $flags );

		$protected = $this->search_replace_protected_option_refusal( $tables, $skip_columns, $include_columns, $old, $new );
		if ( $protected ) {
			return $protected;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( $this->detached ? 0 : 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		// Inside a REST request keep a hard budget and report completed vs
		// remaining tables so the AI can re-run scoped; a detached run has the
		// whole background process to itself.
		$deadline = $this->detached ? PHP_INT_MAX : microtime( true ) + 240;

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
		$address = $this->search_replace_address_warnings( $old );
		if ( $address ) {
			$data['site_address_note'] = implode( ' ', $address );
		}
		$protected_rows = $this->search_replace_protected_rows_matching( $tables, $old );
		if ( $protected_rows ) {
			$data['protected_options_skipped'] = $protected_rows;
			$data['protected_note']            = $this->search_replace_protected_note( $protected_rows );
		}
		if ( ! empty( $this->sr_protected_tables ) ) {
			$data['protected_tables_skipped'] = $this->sr_protected_tables;
		}
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

		// Identity tables are never rewritten; the options table stays in scope
		// with its protected rows filtered out per row (search_replace_row_guard).
		$protected = array();
		$tables    = array_values( array_filter( $tables, function ( $t ) use ( &$protected ) {
			$key = $this->protected_table_key( $t );
			if ( null === $key || 'options' === $key ) {
				return true;
			}
			$protected[] = $t;
			return false;
		} ) );
		$this->sr_protected_tables = $protected;

		if ( empty( $tables ) ) {
			if ( ! empty( $protected ) ) {
				return new WP_Error( 'protected_tables', sprintf(
					/* translators: %s: table names */
					__( 'Refused: search-replace never rewrites %s (accounts, roles and capabilities, network settings, or the WPVibe audit log). Use `user update`, `user meta update` or `user set-role` for account changes. Nothing was changed.', 'vibe-ai' ),
					implode( ', ', $protected )
				), WPVibe_Error_Contract::data( 'not_allowed', false ) );
			}
			return new WP_Error( 'no_tables', __( 'No tables in scope for search-replace.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'not_found', false ) );
		}
		return $tables;
	}

	/**
	 * SQL appended to an options-table row match so protected options
	 * (BLOCKED_OPTIONS and this site's <prefix>user_roles) are never
	 * rewritten by search-replace, value or name. '' for other tables.
	 */
	private function search_replace_row_guard( $table ) {
		global $wpdb;
		if ( 'options' !== $this->protected_table_key( $table ) ) {
			return '';
		}
		$exact = array();
		$like  = array( '%' . $wpdb->esc_like( '_user_roles' ) );
		foreach ( WPVibe_CLI::BLOCKED_OPTIONS as $name ) {
			// The site address stays migratable: approved, with the preview saying so.
			if ( in_array( $name, self::$sr_address_options, true ) ) {
				continue;
			}
			$prefix = WPVibe_CLI::option_list_prefix( $name );
			if ( null !== $prefix ) {
				$like[] = $wpdb->esc_like( $prefix ) . '%';
			} else {
				$exact[] = $wpdb->prepare( '%s', $name );
			}
		}
		$parts = array( '`option_name` NOT IN (' . implode( ', ', $exact ) . ')' );
		foreach ( $like as $pattern ) {
			$parts[] = '`option_name`' . $wpdb->prepare( ' NOT LIKE %s', $pattern );
		}
		return ' AND ' . implode( ' AND ', $parts );
	}

	/** Protected option rows in scope whose value contains the needle: skipped, and named in the result so the change is not silently partial. */
	private function search_replace_protected_rows_matching( $tables, $old ) {
		global $wpdb;
		$names = array();
		foreach ( $tables as $table ) {
			$guard = $this->search_replace_row_guard( $table );
			if ( '' === $guard ) {
				continue;
			}
			$old_json = $this->json_encode_strip_quotes( $old );
			$match    = '`option_value`' . $wpdb->prepare( ' LIKE BINARY %s', '%' . $wpdb->esc_like( $old ) . '%' );
			if ( $old_json !== $old ) {
				$match = '( ' . $match . ' OR `option_value`' . $wpdb->prepare( ' LIKE BINARY %s', '%' . $wpdb->esc_like( $old_json ) . '%' ) . ' )';
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_col( 'SELECT `option_name` FROM ' . $this->esc_sql_ident( $table ) . ' WHERE ' . $match . ' AND NOT (1' . $guard . ') LIMIT 20' ); // nosemgrep: direct-db-query
			foreach ( (array) $rows as $name ) {
				$names[] = count( $tables ) > 1 ? $table . '.' . $name : (string) $name;
			}
		}
		return $names;
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
			$where = 'WHERE ' . $match . $this->search_replace_row_guard( $table );
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


	/**
	 * Column filters shared by execution and the approval preview, so the
	 * preview counts exactly the columns the replace will touch. user_pass is
	 * never rewritable (a needle inside a bcrypt hash would lock the user out;
	 * the skip test runs before the include test so --include_columns cannot
	 * reopen it); guid is skipped unless --include-guids or an explicit include.
	 */
	private function search_replace_column_filters( $flags ) {
		$skip_columns    = array_filter( wp_parse_list( (string) ( $flags['skip_columns'] ?? '' ) ) );
		$include_columns = array_filter( wp_parse_list( (string) ( $flags['include_columns'] ?? '' ) ) );
		$skip_columns[]  = 'user_pass';
		$guid_skipped    = false;
		if ( empty( $flags['include_guids'] ) && ! in_array( 'guid', $include_columns, true ) ) {
			$skip_columns[] = 'guid';
			$guid_skipped   = true;
		}
		return array( $skip_columns, $include_columns, $guid_skipped );
	}

	private function search_replace_column_in_scope( $table, $col, $skip_columns, $include_columns ) {
		if ( in_array( $col, $skip_columns, true ) || in_array( "$table.$col", $skip_columns, true ) ) {
			return false;
		}
		if ( ! empty( $include_columns ) && ! in_array( $col, $include_columns, true ) && ! in_array( "$table.$col", $include_columns, true ) ) {
			return false;
		}
		return true;
	}

	/** Refuse (before any write) an option_name rewrite that moves a row into or out of a BLOCKED_OPTIONS name. */
	private function search_replace_protected_option_refusal( $tables, $skip_columns, $include_columns, $old, $new ) {
		global $wpdb;
		$old_json = $this->json_encode_strip_quotes( $old );
		$new_json = $this->json_encode_strip_quotes( $new );
		foreach ( $tables as $table ) {
			if ( ( 'options' !== $this->protected_table_key( $table ) && ! preg_match( '/options$/i', (string) $table ) ) || ! $this->search_replace_column_in_scope( $table, 'option_name', $skip_columns, $include_columns ) ) {
				continue;
			}
			list( , $text_columns ) = $this->table_columns( $table );
			if ( ! in_array( 'option_name', $text_columns, true ) ) {
				continue;
			}
			$match = '`option_name`' . $wpdb->prepare( ' LIKE BINARY %s', '%' . $wpdb->esc_like( $old ) . '%' );
			if ( $old_json !== $old ) {
				$match .= ' OR `option_name`' . $wpdb->prepare( ' LIKE BINARY %s', '%' . $wpdb->esc_like( $old_json ) . '%' );
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$names = $wpdb->get_col( 'SELECT `option_name` FROM ' . $this->esc_sql_ident( $table ) . ' WHERE ' . $match ); // nosemgrep: direct-db-query
			foreach ( (array) $names as $name ) {
				$name                = (string) $name;
				$this->sr_incomplete = false;
				$renamed             = $this->replace_in_value( $name, $old, $new, $old_json, $new_json );
				$this->sr_incomplete = false;
				if ( ! is_string( $renamed ) || $renamed === $name ) {
					continue;
				}
				$protected = null !== WPVibe_CLI::match_option_name( $name, WPVibe_CLI::BLOCKED_OPTIONS )
					|| null !== WPVibe_CLI::match_option_name( $renamed, WPVibe_CLI::BLOCKED_OPTIONS )
					|| WPVibe_CLI::option_name_not_printable_ascii( $renamed );
				if ( $protected ) {
					return $this->error_result( sprintf(
						/* translators: 1: table name, 2: current option name, 3: option name after the replacement */
						__( 'Refused: this search-replace would rename the option "%2$s" to "%3$s" in %1$s. Renaming an option into or out of a name WPVibe protects (site identity, active plugins, auth keys, WPVibe connection and hand-off state) is blocked even with approval, and so is renaming one to a non-ASCII name. Nothing was changed. To replace only the values, re-run with --skip-columns=option_name, or use a more specific search string.', 'vibe-ai' ),
						$table,
						$name,
						$renamed
					) );
				}
			}
		}
		return null;
	}

	private function search_replace_protected_note( $names ) {
		return sprintf(
			/* translators: %s: option names */
			__( 'Not changed: %s. These options are protected (admin email, roles, active plugins, security keys, WPVibe connection state), so search-replace skips them even though they contain the search string. If the admin email should change, the user changes it in wp-admin > Settings > General; tell the user this rather than retrying.', 'vibe-ai' ),
			implode( ', ', $names )
		);
	}

	/** One line per site-address option (siteurl, home) this replacement rewrites. */
	private function search_replace_address_warnings( $old ) {
		$warnings = array();
		foreach ( self::$sr_address_options as $opt ) {
			$val = get_option( $opt );
			if ( is_string( $val ) && '' !== $val && false !== strpos( $val, $old ) ) {
				$warnings[] = sprintf(
					/* translators: 1: option name, 2: current value */
					__( 'This changes the site\'s address: the "%1$s" option (currently "%2$s") will be rewritten. The site will load at the new address, everyone is logged out, and the WPVibe connection must be reconnected if the stored site URL no longer matches. Only approve if this is an intentional domain migration.', 'vibe-ai' ),
					$opt,
					$val
				);
			}
		}
		return $warnings;
	}

	/**
	 * Row-count preview per table for the approval card. Mirrors execution:
	 * the same column filters, and the JSON-escaped variant of the needle
	 * (a URL inside Elementor's _elementor_data is stored with escaped
	 * slashes; execution replaces it, so the preview must count it). When the
	 * only matches are a few posts rows, the post ids ride along so the
	 * Worker can name the exact content/edit calls instead of a raw replace.
	 */
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

		list( $skip_columns, $include_columns, ) = $this->search_replace_column_filters( $flags );
		$old_json = $this->json_encode_strip_quotes( $old );

		$deadline      = microtime( true ) + 15;
		$cap           = 1000;
		$counts        = array();
		$not_previewed = 0;
		$where_by_table = array();
		foreach ( $tables as $table ) {
			if ( microtime( true ) > $deadline ) {
				$not_previewed++;
				continue;
			}
			list( , $text_columns ) = $this->table_columns( $table );
			$conds = array();
			foreach ( $text_columns as $col ) {
				if ( ! $this->search_replace_column_in_scope( $table, $col, $skip_columns, $include_columns ) ) {
					continue;
				}
				$col_sql = $this->esc_sql_ident( $col );
				$cond    = $col_sql . $wpdb->prepare( ' LIKE BINARY %s', '%' . $wpdb->esc_like( $old ) . '%' );
				if ( $old_json !== $old ) {
					$cond = '( ' . $cond . ' OR ' . $col_sql . $wpdb->prepare( ' LIKE BINARY %s', '%' . $wpdb->esc_like( $old_json ) . '%' ) . ' )';
				}
				$conds[] = $cond;
			}
			if ( empty( $conds ) ) {
				continue;
			}
			$where = '( ' . implode( ' OR ', $conds ) . ' )' . $this->search_replace_row_guard( $table );
			$sql   = 'SELECT COUNT(*) FROM (SELECT 1 FROM ' . $this->esc_sql_ident( $table ) . ' WHERE ' . $where . ' LIMIT ' . ( $cap + 1 ) . ') AS subq';
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$n = $wpdb->get_var( $sql ); // nosemgrep: direct-db-query
			if ( null === $n || ! empty( $wpdb->last_error ) ) {
				continue;
			}
			$n = (int) $n;
			if ( $n > 0 ) {
				$counts[ $table ]         = ( $n > $cap ) ? $cap . '+' : $n;
				$where_by_table[ $table ] = $where;
			}
		}

		$preview['tables_in_scope']          = count( $tables );
		$preview['matching_rows_per_table']  = $counts;
		// Few posts rows and nothing else: name them, so the reviewer (and the
		// Worker's nudge) can route the edit through content/edit instead.
		$posts_table = $wpdb->prefix . 'posts';
		if ( 0 === $not_previewed && 1 === count( $counts ) && isset( $counts[ $posts_table ] ) && is_int( $counts[ $posts_table ] ) && $counts[ $posts_table ] <= 3 ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$ids = $wpdb->get_col( 'SELECT ID FROM ' . $this->esc_sql_ident( $posts_table ) . ' WHERE ' . $where_by_table[ $posts_table ] . ' LIMIT 4' ); // nosemgrep: direct-db-query
			if ( is_array( $ids ) && ! empty( $ids ) && empty( $wpdb->last_error ) ) {
				$preview['matching_post_ids'] = array_map( 'intval', array_slice( $ids, 0, 3 ) );
			}
		}
		if ( $not_previewed > 0 ) {
			/* translators: %d: table count */
			$preview['preview_truncated'] = sprintf( __( '%d table(s) not scanned for the preview (time budget); they will still be processed on execution.', 'vibe-ai' ), $not_previewed );
		}

		$warnings = $this->search_replace_address_warnings( $old );
		$skipped  = $this->search_replace_protected_rows_matching( $tables, $old );
		if ( $skipped ) {
			$warnings[] = $this->search_replace_protected_note( $skipped );
		}
		if ( $warnings ) {
			$preview['warnings'] = $warnings;
		}
		if ( ! empty( $this->sr_protected_tables ) ) {
			$preview['protected_tables_skipped'] = $this->sr_protected_tables;
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
