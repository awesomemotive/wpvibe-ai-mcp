<?php
/**
 * WP-CLI emulator: shared response shaping and flag validation helpers.
 *
 * Extracted from class-wpvibe-cli.php (mechanical split; no behavior change).
 */

defined( 'ABSPATH' ) || exit;

trait WPVibe_CLI_Helpers {


	// $notice rides on stderr, where real WP-CLI puts warnings, so a caller
	// parsing stdout sees the same shape it always did.
	private function success_result( $data, $notice = '' ) {
		return array(
			'exit_code' => 0,
			'stdout'    => wp_json_encode( $data, JSON_PRETTY_PRINT ),
			'stderr'    => $notice,
		);
	}


	/** Warning text when a listing hit its row cap, so a bulk op is not driven off a partial set. */
	private function truncation_notice( $rows, $limit, $command, $paging_hint = '' ) {
		if ( count( $rows ) < $limit ) {
			return '';
		}
		/* translators: 1: row limit, 2: command */
		return sprintf( __( 'Results truncated at %1$d rows. More may exist, so do NOT treat this as the complete set for a bulk operation. Narrow the query, or raise the limit where `%2$s` supports it.', 'vibe-ai' ), $limit, $command ) . ( '' !== $paging_hint ? ' ' . $paging_hint : '' );
	}


	/**
	 * --offset and --paged (upstream names) for the list commands. Both is a
	 * contradiction WP_Query resolves silently (offset wins), so refuse it.
	 * Returns query args, or an error result.
	 */
	private function paging_args( $command, $flags, $per_page ) {
		$has_offset = isset( $flags['offset'] );
		$has_paged  = isset( $flags['paged'] );
		if ( $has_offset && $has_paged ) {
			/* translators: %s: command name */
			return $this->error_result( sprintf( __( '`%s`: pass either --offset or --paged, not both.', 'vibe-ai' ), $command ) );
		}
		if ( $has_offset ) {
			if ( ! is_numeric( $flags['offset'] ) || (int) $flags['offset'] < 0 ) {
				return $this->error_result( __( '--offset must be a non-negative integer.', 'vibe-ai' ) );
			}
			return array( 'offset' => (int) $flags['offset'] );
		}
		if ( $has_paged ) {
			if ( ! is_numeric( $flags['paged'] ) || (int) $flags['paged'] < 1 ) {
				return $this->error_result( __( '--paged must be a positive integer (1 = first page).', 'vibe-ai' ) );
			}
			return array( 'offset' => ( (int) $flags['paged'] - 1 ) * max( 1, $per_page ) );
		}
		return array();
	}

	private function next_page_hint( $flags, $per_page ) {
		$paged = isset( $flags['paged'] ) ? (int) $flags['paged'] : ( isset( $flags['offset'] ) ? (int) floor( (int) $flags['offset'] / max( 1, $per_page ) ) + 1 : 1 );
		/* translators: %d: next page number */
		return sprintf( __( 'Fetch the next page with --paged=%d (or --offset=<n>).', 'vibe-ai' ), $paged + 1 );
	}


	/**
	 * Positive integer IDs from positional args, deduped, order-preserved.
	 * Not wp_parse_id_list(): its absint() turns "-5"/garbage into
	 * real-looking IDs, and these feed post delete — invalid tokens must
	 * drop, not mutate.
	 */
	private function positional_ids( $positional ) {
		$ids = array();
		foreach ( (array) $positional as $p ) {
			if ( is_numeric( $p ) && (int) $p > 0 ) {
				$ids[] = (int) $p;
			}
		}
		return array_values( array_unique( $ids ) );
	}


	/** Shape a single- or multi-target entity op response consistently. */
	private function bulk_result( $action, $ok, $ids, $results, $noun = 'post' ) {
		$total = count( $ids );
		$label = ucfirst( $noun );
		if ( 1 === $total ) {
			$only = $results[0];
			if ( isset( $only['status'] ) && 'error' === $only['status'] ) {
				/* translators: 1: entity label, 2: ID, 3: error message */
				return $this->error_result( sprintf( __( '%1$s #%2$d: %3$s', 'vibe-ai' ), $label, $only['id'], $only['error'] ) );
			}
			/* translators: 1: entity label, 2: ID, 3: action taken */
			return $this->success_result( array( 'message' => sprintf( __( '%1$s #%2$d %3$s.', 'vibe-ai' ), $label, $ids[0], $action ) ) );
		}
		return $this->success_result( array(
			/* translators: 1: success count, 2: total, 3: plural noun, 4: action taken */
			'message'   => sprintf( __( '%1$d of %2$d %3$s %4$s.', 'vibe-ai' ), $ok, $total, $noun . 's', $action ),
			'succeeded' => $ok,
			'total'     => $total,
			'results'   => $results,
		) );
	}


	/**
	 * Decode --post_content_base64. Exists because plain --post_content is a
	 * silent corrupter when content mixes both quote types (the tokenizer
	 * strips the colliding quotes with exit_code 0); base64 side-steps quoting
	 * entirely and its alphabet can't trip the shell-char gate.
	 */
	private function decode_content_base64( $value ) {
		$decoded = base64_decode( (string) $value, true );
		if ( false === $decoded ) {
			return new WP_Error( 'bad_base64', __( 'Invalid base64 in --post_content_base64. Encode the exact content bytes as standard base64 (no URL-safe alphabet, no line breaks).', 'vibe-ai' ) );
		}
		// wp_insert_post/wp_update_post unslash; slash so backslashes survive.
		return wp_slash( $decoded );
	}


	/**
	 * WPCode snippet posts are writable only through the code_snippet approval
	 * flow (code + type + location reviewed together, written inactive) — a CLI
	 * write here would bypass that panel, and post_status could flip activation.
	 */
	private function wpcode_write_refusal() {
		return $this->error_result( __( 'WPCode snippets cannot be written through WP-CLI emulation. Use the code_snippet tool instead — it routes the code through the approval panel, writes the snippet disabled, and leaves activation to the human in wp-admin.', 'vibe-ai' ) );
	}


	/**
	 * The one secret-name rule, mirrored from the Worker's isSecretKey (src/lib/audit-redaction.ts):
	 * pass/auth only as a whole word part (bypass, author, passes stay), key only with a secret-naming
	 * prefix, so --meta_key and --key (identifiers) stay readable.
	 */
	private static function is_secret_name( $name ) {
		return 1 === preg_match( '/(^|[_-]|db|user)pass(wd|word|phrase)?([_-]|$)|password|passwd|pwd|secret|token|api[_-]?key|apikey|(private|license|licence|access|auth|encryption|signing|consumer|nonce|logged[_-]?in)[_-]?key|authorization|(^|[_-])auth([_-]|$)|credential|(^|[_-])salt$|cookie/i', (string) $name );
	}


	/**
	 * One scan for both uses, mirrored from the Worker's scanSql. Strict: literals become '?', and an open literal
	 * or `identifier`, or any comment opener (`-- `, `#`, `/*`: a quote inside a comment would throw the scan off),
	 * means the shape cannot be trusted (null). Lenient (scrubbing text about SQL that could not be shaped): every
	 * literal and comment body is collected, open ones to the end.
	 */
	private static function scan_sql( $sql, &$found, $lenient ) {
		$sql = (string) $sql;
		$out = '';
		$n   = strlen( $sql );
		for ( $i = 0; $i < $n; $i++ ) {
			$ch   = $sql[ $i ];
			$next = $i + 1 < $n ? $sql[ $i + 1 ] : '';
			$skip = '#' === $ch ? 1 : ( ( '-' === $ch && '-' === $next ) || ( '/' === $ch && '*' === $next ) ? 2 : 0 );
			if ( $skip ) {
				if ( ! $lenient ) {
					return null;
				}
				$close   = '/' === $ch ? strpos( $sql, '*/', $i + 2 ) : strpos( $sql, "\n", $i );
				$end     = false === $close ? $n : $close;
				$found[] = (string) substr( $sql, $i + $skip, $end - $i - $skip );
				$i       = $end;
				continue;
			}
			if ( "'" === $ch || '"' === $ch || '`' === $ch ) {
				$j      = $i + 1;
				$closed = false;
				while ( $j < $n ) {
					if ( '\\' === $sql[ $j ] && '`' !== $ch ) {
						$j += 2;
						continue;
					}
					if ( $sql[ $j ] === $ch ) {
						if ( $j + 1 < $n && $sql[ $j + 1 ] === $ch ) {
							$j += 2;
							continue;
						}
						$closed = true;
						break;
					}
					$j++;
				}
				if ( ! $closed && ! $lenient ) {
					return null;
				}
				if ( '`' === $ch ) {
					$out .= substr( $sql, $i, $j - $i + 1 );
				} else {
					$found[] = (string) substr( $sql, $i + 1, min( $j, $n ) - $i - 1 );
					$out    .= "'?'";
				}
				$i = $j;
				continue;
			}
			$out .= $ch;
		}
		return $out;
	}


	/** SQL with every string literal ('...' or "...", backslash and doubled-quote escapes) replaced by '?'; null when a literal or `identifier` never closes or a comment is present. */
	private static function redact_sql_literals( $sql, &$found = array() ) {
		return self::scan_sql( $sql, $found, false );
	}


	/** The values a statement that could not be shaped may hold: its literals and comment bodies, read through a broken outer wrapper. Keywords and identifiers stay out. */
	private static function sql_values_of( $statement ) {
		$s = (string) $statement;
		$q = '' === $s ? '' : $s[0];
		if ( "'" === $q || '"' === $q ) {
			$s = (string) substr( $s, 1 );
			if ( '' !== $s && substr( $s, -1 ) === $q ) {
				$s = (string) substr( $s, 0, -1 );
			}
		}
		$found = array();
		self::scan_sql( $s, $found, true );
		return $found;
	}


	private static function json_names_secret( $value ) {
		if ( ! preg_match( '/^\s*[\[{]/', (string) $value ) ) {
			return false;
		}
		$decoded = json_decode( (string) $value, true );
		return is_array( $decoded ) && self::array_names_secret( $decoded, 0 );
	}


	private static function array_names_secret( $value, $depth ) {
		if ( $depth > 20 || ! is_array( $value ) ) {
			return false;
		}
		foreach ( $value as $k => $v ) {
			if ( self::is_secret_name( $k ) || self::array_names_secret( $v, $depth + 1 ) ) {
				return true;
			}
		}
		return false;
	}


	/**
	 * The command's tokens with their secret values replaced, before they reach the Approval Log
	 * (and the wpvibe_operation_executed listeners). Same rule as the Worker's redactCommandSecrets
	 * (src/lib/wp-cli-gate.ts), #1050: store a command's shape, never its secret values.
	 * - a secret-named flag keeps its name, its value becomes <redacted> (`--x=v`, and `--x v`);
	 * - `db query`: the statement that ran (db_query_raw) with every string literal as '?';
	 * - positionals: `user check-password <user> <password>`, and the value of a secret-named
	 *   key (option/meta/config/transient/cache, option patch key-paths, a JSON value naming one);
	 * - quoting that never closed, or SQL whose literal never closes: a placeholder.
	 * $secrets collects every removed value, so text that echoes one (dry run, stderr) can be scrubbed.
	 */
	private function redact_command_secrets( $tokens, &$secrets = array() ) {
		$tokens = array_values( array_map( 'strval', (array) $tokens ) );
		$count  = count( $tokens );
		$pos    = array();
		// Positionals as split_tokens counts them for the handlers: anything not starting with '--' (`-sk_live_1` is a value).
		foreach ( $tokens as $i => $t ) {
			if ( 0 !== strpos( $t, '--' ) ) {
				$pos[] = $i;
			}
		}
		$words = array();
		foreach ( array_slice( $pos, 0, 2 ) as $i ) {
			if ( ! preg_match( '/^[a-z][a-z-]*$/i', $tokens[ $i ] ) ) {
				break;
			}
			$words[] = strtolower( $tokens[ $i ] );
		}
		$family = implode( ' ', $words );
		$unclosed = false === $this->command_quotes_closed;
		$out      = $tokens;
		$redact = function ( $i ) use ( &$out, &$secrets, $tokens ) {
			if ( isset( $tokens[ $i ] ) && self::REDACTED_VALUE !== $out[ $i ] ) {
				if ( '' !== $tokens[ $i ] ) {
					$secrets[] = $tokens[ $i ];
				}
				$out[ $i ] = self::REDACTED_VALUE;
			}
		};

		// `--meta_key=api_key --meta_value=x`: the key is an identifier, but the value it names is a secret.
		$meta_key_secret = false;
		foreach ( $tokens as $t ) {
			if ( preg_match( '/^--meta[-_]key=(.*)$/is', $t, $m ) && self::is_secret_name( $m[1] ) ) {
				$meta_key_secret = true;
			}
		}
		for ( $i = 0; $i < $count; $i++ ) {
			$t = $tokens[ $i ];
			if ( 0 !== strpos( $t, '--' ) ) {
				continue;
			}
			$eq   = strpos( $t, '=' );
			$name = false === $eq ? substr( $t, 2 ) : substr( $t, 2, $eq - 2 );
			if ( ! self::is_secret_name( $name ) && ! ( $meta_key_secret && preg_match( '/^meta[-_]value$/i', $name ) ) ) {
				continue;
			}
			if ( false !== $eq && $eq < strlen( $t ) - 1 ) {
				$secrets[] = substr( $t, $eq + 1 );
				$out[ $i ] = '--' . $name . '=' . self::REDACTED_VALUE;
				continue;
			}
			// `--x value` and `--x= value`: the next token is the value unless it is another `--` flag.
			if ( ! in_array( strtolower( $name ), array( 'show-password', 'show_password' ), true ) && $i + 1 < $count && 0 !== strpos( $tokens[ $i + 1 ], '--' ) ) {
				$redact( $i + 1 );
			}
		}

		$head = function ( $k ) use ( $pos, $tokens ) {
			$w = array();
			foreach ( array_slice( $pos, 0, $k ) as $i ) {
				$w[] = strtolower( $tokens[ $i ] );
			}
			return implode( ' ', $w );
		};
		if ( 'db query' === $head( 2 ) ) {
			// The statement as it ran: execute() reads it from the raw text, not from these tokens (#397).
			$statement = $this->db_query_statement( array_map( function ( $i ) use ( $tokens ) {
				return $tokens[ $i ];
			}, array_slice( $pos, 2 ) ) );
			// A flag value already removed (`--dbpass <value>` before the SQL) may sit at the head of the raw statement.
			$flag_values = array_values( array_filter( $secrets, function ( $v ) {
				return strlen( (string) $v ) >= 3;
			} ) );
			$broken      = '' !== $statement && ( "'" === $statement[0] || '"' === $statement[0] );
			$shape       = $broken ? null : self::redact_sql_literals( $statement, $secrets );
			if ( null === $shape ) {
				$secrets = array_merge( $secrets, self::sql_values_of( $statement ) );
			} elseif ( $flag_values ) {
				$shape = str_replace( $flag_values, self::REDACTED_VALUE, $shape );
			}
			$sql     = null === $shape ? self::UNPARSED_SQL : $shape;
			$sql_at  = array_slice( $pos, 2 );
			$kept    = array();
			$first   = true;
			foreach ( $out as $i => $t ) {
				if ( in_array( $i, $sql_at, true ) ) {
					if ( $first ) {
						$kept[] = $sql;
						$first  = false;
					}
					continue;
				}
				$kept[] = $t;
			}
			if ( $first && '' !== $statement ) {
				$kept[] = $sql;
			}
			return $kept;
		}
		if ( 'user check-password' === $head( 2 ) ) {
			foreach ( array_slice( $pos, 3 ) as $i ) {
				$redact( $i );
			}
		} elseif ( 'option patch' === $head( 2 ) && isset( $pos[2] ) && in_array( strtolower( $tokens[ $pos[2] ] ), array( 'insert', 'update' ), true ) && count( $pos ) >= 5 ) {
			$value_at = $pos[ count( $pos ) - 1 ];
			$secret   = self::json_names_secret( $tokens[ $value_at ] );
			foreach ( array_slice( $pos, 3, -1 ) as $i ) {
				$secret = $secret || self::is_secret_name( $tokens[ $i ] );
			}
			if ( $secret ) {
				$redact( $value_at );
			}
		} else {
			$key_at = isset( self::KEY_VALUE_COMMANDS[ $head( 3 ) ] ) ? self::KEY_VALUE_COMMANDS[ $head( 3 ) ] : ( isset( self::KEY_VALUE_COMMANDS[ $head( 2 ) ] ) ? self::KEY_VALUE_COMMANDS[ $head( 2 ) ] : null );
			if ( null !== $key_at && isset( $pos[ $key_at ], $pos[ $key_at + 1 ] ) ) {
				$key   = $tokens[ $pos[ $key_at ] ];
				$value = $tokens[ $pos[ $key_at + 1 ] ];
				if ( self::is_secret_name( $key ) || self::json_names_secret( $value ) ) {
					$redact( $pos[ $key_at + 1 ] );
				}
			}
		}
		if ( $unclosed ) {
			// Quoting never closed: a placeholder. The removed values, plus the open quote's text (the last token),
			// still go to $secrets so a dry run or stderr echo is scrubbed; nothing else, so the log stays readable.
			if ( $count > 0 && '' !== $tokens[ $count - 1 ] ) {
				$secrets[] = $tokens[ $count - 1 ];
			}
			return array_values( array_filter( array( $family, self::UNPARSED_COMMAND ) ) );
		}
		return $out;
	}


	/**
	 * A value bound for the Approval Log (dry run preview, result summary) with the command's secrets out:
	 * a secret-named field is redacted, an `sql` field keeps only its shape, and every string has each
	 * removed value (and its 200-character preview cut) replaced.
	 */
	private static function redact_stored_value( $value, $secrets, $depth = 0 ) {
		if ( $depth > 20 ) {
			return self::REDACTED_VALUE;
		}
		if ( is_string( $value ) ) {
			$needles = array();
			foreach ( array_unique( (array) $secrets ) as $s ) {
				$s = (string) $s;
				if ( strlen( $s ) >= 3 ) {
					$needles[] = $s;
					if ( mb_strlen( $s ) > 200 ) {
						$needles[] = mb_substr( $s, 0, 200 );
					}
				}
			}
			usort( $needles, function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			} );
			return '' === $value || empty( $needles ) ? $value : str_replace( $needles, self::REDACTED_VALUE, $value );
		}
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $k => $v ) {
				if ( is_string( $k ) && self::is_secret_name( $k ) && null !== $v && '' !== $v && ! is_bool( $v ) ) {
					$out[ $k ] = self::REDACTED_VALUE;
				} elseif ( 'sql' === $k && is_string( $v ) ) {
					$shape     = self::redact_sql_literals( $v );
					$out[ $k ] = null === $shape ? self::UNPARSED_SQL : self::redact_stored_value( $shape, $secrets, $depth + 1 );
				} else {
					$out[ $k ] = self::redact_stored_value( $v, $secrets, $depth + 1 );
				}
			}
			return $out;
		}
		return $value;
	}


	private function error_result( $message, $exit_code = 1 ) {
		return array(
			'exit_code' => $exit_code,
			'stdout'    => '',
			'stderr'    => $message,
		);
	}


	/**
	 * Refuse flags a handler does not implement. Accepting one and dropping it
	 * reports success for work that never happened, which is the single failure
	 * an AI caller cannot detect or recover from. $unsupported maps a known
	 * upstream WP-CLI flag to the alternative, so the refusal routes somewhere
	 * instead of just saying no.
	 */
	/** Upstream post create/update flags we do not emulate, each routed to the path that works. */
	private function post_write_unsupported_flags( $is_create ) {
		$shared = array(
			'meta_input'  => __( 'Create the post first, then set each key with `post meta update <id> <key> <value>`.', 'vibe-ai' ),
			'tax_input'   => __( 'Set terms after the write with `post term set <id> <taxonomy> <term>...`.', 'vibe-ai' ),
			'post_author' => __( 'Posts are authored as the connected WPVibe user. To change the author afterwards, use rest_api PUT /wp/v2/posts/<id> with {"author":<id>}.', 'vibe-ai' ),
		);
		if ( $is_create ) {
			return array_merge( $shared, array(
				'post_category' => __( 'Create the post first, then `post term set <id> category <term>...`.', 'vibe-ai' ),
				'tags_input'    => __( 'Create the post first, then `post term set <id> post_tag <term>...`.', 'vibe-ai' ),
				'from_post'     => __( 'Duplicating a post is not emulated: `post get <id>` the source, then pass the fields to `post create`.', 'vibe-ai' ),
				'post_date_gmt' => __( 'Pass --post_date with a site-local time; WordPress derives the GMT value.', 'vibe-ai' ),
			) );
		}
		return array_merge( $shared, array(
			'post_date'             => __( 'Changing the publish date on update is not emulated; use rest_api PUT /wp/v2/posts/<id> with {"date":"..."}.', 'vibe-ai' ),
			'defer_term_counting'   => __( 'Term-count deferral is a bulk-import optimization with no effect here.', 'vibe-ai' ),
		) );
	}


	/** Whether the caller may reach network-wide data and other sites' data: always on single site, Super Admins on multisite. */
	private function has_network_scope() {
		return ! is_multisite() || is_super_admin();
	}


	private function reject_unknown_flags( $command, $flags, $known, $unsupported = array() ) {
		foreach ( array_keys( $flags ) as $flag ) {
			if ( in_array( $flag, $known, true ) ) {
				continue;
			}
			if ( isset( $unsupported[ $flag ] ) ) {
				/* translators: 1: command, 2: flag name, 3: guidance */
				return $this->error_result( sprintf( __( '`%1$s` does not support --%2$s in the WPVibe emulation. %3$s', 'vibe-ai' ), $command, $flag, $unsupported[ $flag ] ) );
			}
			/* translators: 1: command, 2: flag name, 3: supported flag list */
			return $this->error_result( sprintf( __( '`%1$s` does not support --%2$s in the WPVibe emulation. Supported flags: %3$s.', 'vibe-ai' ), $command, $flag, implode( ', ', $known ) ) );
		}
		return null;
	}


	/**
	 * Formats we emulate for listings. json is the native shape; ids and count are
	 * implemented in format_rows(). Anything else (csv, table, yaml) would return a
	 * shape the caller did not ask for, so it is refused rather than ignored.
	 */
	private function reject_unsupported_format( $command, $flags ) {
		if ( ! isset( $flags['format'] ) ) {
			return null;
		}
		$format = strtolower( (string) $flags['format'] );
		if ( in_array( $format, array( 'json', 'ids', 'count' ), true ) ) {
			return null;
		}
		/* translators: 1: command, 2: requested format */
		return $this->error_result( sprintf( __( '`%1$s` supports --format=json|ids|count; --format=%2$s is not emulated. Use --fields=<list> to narrow the columns instead.', 'vibe-ai' ), $command, $format ) );
	}


	/**
	 * Apply --format=ids|count, else --fields. `post list --format=ids` feeding a
	 * bulk `post delete` is the documented workflow in the run_wp_cli tool
	 * description, so returning full rows here silently breaks it.
	 */
	private function format_rows( $results, $flags, $id_key ) {
		$format = isset( $flags['format'] ) ? strtolower( (string) $flags['format'] ) : '';
		if ( 'count' === $format ) {
			return count( $results );
		}
		if ( 'ids' === $format ) {
			return array_values( array_map( 'strval', array_column( $results, $id_key ) ) );
		}
		return $this->filter_fields( $results, $flags );
	}


	private function filter_fields( $results, $flags ) {
		if ( empty( $flags['fields'] ) || empty( $results ) ) {
			return $results;
		}
		$fields = array_filter( wp_parse_list( $flags['fields'] ) );
		return array_map( function ( $row ) use ( $fields ) {
			return array_intersect_key( $row, array_flip( $fields ) );
		}, $results );
	}

}
