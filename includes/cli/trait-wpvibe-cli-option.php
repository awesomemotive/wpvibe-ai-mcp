<?php
/**
 * WP-CLI emulator: option and transient handlers.
 *
 * Extracted from class-wpvibe-cli.php (mechanical split; no behavior change).
 */

defined( 'ABSPATH' ) || exit;

trait WPVibe_CLI_Option {


	private function handle_option_get( $positional, $flags ) {
		if ( empty( $positional[0] ) ) {
			return $this->error_result( __( 'Option key required.', 'vibe-ai' ) );
		}

		if ( null !== self::match_option_name( $positional[0], self::READABLE_BLOCKED_OPTIONS ) ) {
			$value = get_option( $positional[0], null );
			return $this->success_result( array( 'value' => $value ) );
		}

		if ( null !== self::match_option_name( $positional[0], self::BLOCKED_OPTIONS ) ) {
			return $this->error_result(
				sprintf(
					/* translators: %s: option key */
					__( 'Option \'%s\' is blocked for security.', 'vibe-ai' ),
					$positional[0]
				)
			);
		}

		// Collation-independent backstop: match_option_name canonicalizes a
		// non-ASCII disguised name via a DB round-trip, which returns "not
		// blocked" if that query fails (DB blip) — reads would then leak a
		// padded auth salt (BLOCKED_OPTIONS holds auth_key + the salts). The
		// write handlers already run this; reads must too. get_option resolves
		// the padded name to the real row regardless, so refuse it up front.
		$boundary = $this->reject_bad_option_name( $positional[0] );
		if ( $boundary ) {
			return $boundary;
		}

		$value = get_option( $positional[0], null );
		if ( null === $value ) {
			/* translators: %s: option key */
			return $this->error_result( sprintf( __( 'Option \'%s\' not found.', 'vibe-ai' ), $positional[0] ) );
		}
		$note = '';
		if ( is_string( $value ) ) {
			$trimmed = trim( $value );
			if ( '' !== $trimmed && ( '[' === $trimmed[0] || '{' === $trimmed[0] ) && null !== json_decode( $trimmed, true ) ) {
				$note = __( 'Note: this value is a STRING containing JSON text (a scalar), not a decoded array/object. The owning plugin decodes it itself. To modify it, edit the JSON text and write it back as a string: option update <key> <json-text> --format=plaintext.', 'vibe-ai' );
			} elseif ( false !== strpos( $value, "\n" ) ) {
				$note = __( 'Note: this value is ONE newline-delimited string (a scalar), not an array or list. To modify it, edit the string and write the whole string back with option update.', 'vibe-ai' );
			}
		}
		return array(
			'exit_code' => 0,
			'stdout'    => is_scalar( $value ) ? (string) $value : wp_json_encode( $value, JSON_PRETTY_PRINT ),
			'stderr'    => $note,
		);
	}


	private function handle_option_pluck( $positional, $flags ) {
		if ( count( $positional ) < 2 ) {
			return $this->error_result( __( 'Usage: option pluck <option> <key-path>...', 'vibe-ai' ) );
		}

		$key = $positional[0];
		if ( null === self::match_option_name( $key, self::READABLE_BLOCKED_OPTIONS ) && null !== self::match_option_name( $key, self::BLOCKED_OPTIONS ) ) {
			return $this->error_result(
				sprintf(
					/* translators: %s: option key */
					__( 'Option \'%s\' is blocked for security.', 'vibe-ai' ),
					$key
				)
			);
		}

		// Collation-independent backstop (see handle_option_get): the non-ASCII
		// canonicalization above fails open on a DB blip, so refuse a disguised
		// name before get_option resolves it to a padded salt row.
		$boundary = $this->reject_bad_option_name( $key );
		if ( $boundary ) {
			return $boundary;
		}

		$value = get_option( $key, null );
		if ( null === $value ) {
			/* translators: %s: option key */
			return $this->error_result( sprintf( __( 'Option \'%s\' not found.', 'vibe-ai' ), $key ) );
		}

		$node  = $value;
		$trail = '';
		foreach ( array_slice( $positional, 1 ) as $segment ) {
			$seg_key = is_numeric( $segment ) ? (int) $segment : $segment;
			$trail   = '' === $trail ? (string) $segment : $trail . '.' . $segment;
			if ( is_object( $node ) && property_exists( $node, (string) $seg_key ) ) {
				$node = $node->{$seg_key};
			} elseif ( is_array( $node ) && array_key_exists( $seg_key, $node ) ) {
				$node = $node[ $seg_key ];
			} else {
				/* translators: %s: key path */
				return $this->error_result( sprintf( __( 'No data exists at key path \'%s\'.', 'vibe-ai' ), $trail ) );
			}
		}

		return array(
			'exit_code' => 0,
			'stdout'    => is_scalar( $node ) ? (string) $node : wp_json_encode( $node, JSON_PRETTY_PRINT ),
			'stderr'    => '',
		);
	}


	private function handle_option_list( $positional, $flags ) {
		global $wpdb;

		$reject = $this->reject_unknown_flags( 'option list', $flags, array( 'search', 'autoload', 'transients', 'fields', 'format' ), array(
			'exclude-search' => __( 'Filter the returned rows yourself.', 'vibe-ai' ),
			'orderby'        => __( 'Rows come back ordered by option_name; sort them yourself.', 'vibe-ai' ),
			'field'          => __( 'Use --fields=<name> and read that column from the rows.', 'vibe-ai' ),
		) );
		if ( $reject ) {
			return $reject;
		}
		$format_reject = $this->reject_unsupported_format( 'option list', $flags );
		if ( $format_reject ) {
			return $format_reject;
		}

		// esc_like first so literal %/_ in the input stay literal, THEN convert
		// WP-CLI wildcard syntax (* and ?) to SQL LIKE syntax (% and _).
		$search = isset( $flags['search'] )
			? str_replace( array( '*', '?' ), array( '%', '_' ), $wpdb->esc_like( $flags['search'] ) )
			: '%';

		// Transients are cache rows, not configuration: listing them by default
		// buries real settings and invites a cleanup pass that deletes caches.
		// Matches upstream, which needs --transients to include them. The patterns
		// are literals, not input: backslash escapes the _ wildcard, and %% is a
		// literal % once prepare() has run over the surrounding query.
		$transient_filter = empty( $flags['transients'] )
			? " AND option_name NOT LIKE '\\_transient\\_%%' AND option_name NOT LIKE '\\_site\\_transient\\_%%'"
			: '';

		$has_autoload = isset( $flags['autoload'] );

		/*
		 * Raw SQL justification: Dynamic LIKE pattern from user input; prepared via $wpdb->prepare().
		 * Only reads from the options table; no writes. Two separate queries to avoid interpolation.
		 */
		if ( $has_autoload ) {
			// WP 6.6+ stores autoload as on/off/auto-on/auto-off/auto alongside legacy yes/no.
			// Filter by exclusion so unknown future values default to autoloaded, matching core.
			$wants_on = ( 'on' === $flags['autoload'] || 'yes' === $flags['autoload'] );
			$not_on   = array( 'no', 'off', 'auto-off' );
			$operator = $wants_on ? 'NOT IN' : 'IN';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s{$transient_filter} AND autoload {$operator} ( %s, %s, %s ) ORDER BY option_name LIMIT 100",
					array_merge( array( $search ), $not_on )
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s{$transient_filter} ORDER BY option_name LIMIT 100",
					$search
				),
				ARRAY_A
			);
		}

		$results = array();
		foreach ( $rows as $row ) {
			// Canonicalize rather than strict compare: a stored row name that
			// differs only by case from a blocked one must still be filtered out.
			if ( null !== self::match_option_name( $row['option_name'], self::BLOCKED_OPTIONS ) ) {
				continue;
			}
			if ( strlen( $row['option_value'] ) > 200 ) {
				$row['option_value'] = mb_substr( $row['option_value'], 0, 200 ) . '...[truncated]';
			}
			$results[] = $row;
		}

		// Notice keys off the raw row count: BLOCKED_OPTIONS filtering can drop
		// rows below the cap while more still matched in the database.
		return $this->success_result(
			$this->format_rows( $results, $flags, 'option_name' ),
			$this->truncation_notice( $rows, 100, 'option list' )
		);
	}


	/**
	 * WP-CLI parity: values are plain strings unless --format=json is passed.
	 * Returns array( 'value' => mixed ) or array( 'error' => string ).
	 */
	private function parse_option_value( $raw, $flags, $key ) {
		$raw    = (string) $raw;
		$format = isset( $flags['format'] ) ? strtolower( (string) $flags['format'] ) : '';
		if ( '' !== $format && ! in_array( $format, array( 'json', 'plaintext' ), true ) ) {
			return array( 'error' => __( 'Unknown --format. Use --format=json for structured values or --format=plaintext for literal strings.', 'vibe-ai' ) );
		}
		if ( 'json' === $format ) {
			$decoded = json_decode( $raw, true );
			if ( null === $decoded && 'null' !== trim( $raw ) ) {
				return array( 'error' => __( 'Value is not valid JSON. Fix the JSON, or drop --format=json to store the text as a plain string.', 'vibe-ai' ) );
			}
			return array( 'value' => $decoded );
		}
		if ( is_serialized( trim( $raw ) ) ) {
			/* translators: %s: option key */
			return array( 'error' => sprintf( __( 'Value for \'%s\' looks like a PHP-serialized string. WordPress serializes values automatically, so writing this would silently change the option\'s stored type and can fatally break the plugin that owns it. Read the option with `option get`, then write the structured value with --format=json (a JSON string like \'"a\\nb"\' stores a plain multi-line string).', 'vibe-ai' ), $key ) );
		}
		$json = json_decode( $raw, true );
		if ( 'plaintext' !== $format && is_array( $json ) ) {
			/* translators: %s: option key */
			return array( 'error' => sprintf( __( 'Value for \'%s\' parses as a JSON array/object. To store a structured value, re-run with --format=json. To store this exact text as a plain string, re-run with --format=plaintext. (JSON is no longer decoded automatically: silent decoding corrupted options that store delimited strings, e.g. cache-plugin exclude lists.)', 'vibe-ai' ), $key ) );
		}
		return array( 'value' => $raw );
	}


	private function handle_option_update( $positional, $flags ) {
		if ( count( $positional ) < 2 ) {
			return $this->error_result( __( 'Usage: option update {key} {value} [--format=json|plaintext]', 'vibe-ai' ) );
		}
		$key   = $positional[0];

		if ( null !== self::match_option_name( $key, self::BLOCKED_OPTIONS ) ) {
			return $this->error_result(
				sprintf(
					/* translators: %s: option key */
					__( 'Option \'%s\' is blocked for security. Update it via wp-admin.', 'vibe-ai' ),
					$key
				)
			);
		}

		$boundary = $this->reject_bad_option_name( $key );
		if ( $boundary ) {
			return $boundary;
		}

		$parsed = $this->parse_option_value( $positional[1], $flags, $key );
		if ( isset( $parsed['error'] ) ) {
			return $this->error_result( $parsed['error'] );
		}
		$value = $parsed['value'];

		// Type-shape guard: silently flipping an option between scalar and
		// array/object corrupts the owning plugin (LiteSpeed newline lists,
		// WP Fastest Cache serialized arrays) and can fatal the site.
		$existing = get_option( $key, null );
		if ( null !== $existing ) {
			$existing_structured = is_array( $existing ) || is_object( $existing );
			$new_structured      = is_array( $value ) || is_object( $value );
			if ( $existing_structured && ! $new_structured ) {
				/* translators: %s: option key */
				return $this->error_result( sprintf( __( 'Option \'%s\' currently stores an array/object; writing a scalar would change its stored type and can fatally break the plugin that owns it. Pass the full structure with --format=json, use `option patch` to change one key, or `option delete` then `option add` if the type change is intentional.', 'vibe-ai' ), $key ) );
			}
			if ( ! $existing_structured && $new_structured ) {
				/* translators: %s: option key */
				return $this->error_result( sprintf( __( 'Option \'%s\' currently stores a scalar (often JSON text or a delimited string the owning plugin parses itself, e.g. cache-plugin settings); writing a decoded array would change its stored type and can break or fatal the owning plugin. Write the string form instead: option update with --format=plaintext stores the literal text, and --format=json \'"a\\nb"\' stores a plain multi-line string. Use `option delete` then `option add` only if the type change is intentional.', 'vibe-ai' ), $key ) );
			}
		}

		// update_option returns false both when the write fails AND when the value
		// is unchanged. Read back and compare to distinguish a real failure from a
		// no-op. Mismatch = filter blocked it, DB rejected it, or a filter mutated
		// the stored value. String-form compare: --format=json yields int/bool
		// while WP stores scalars as strings, and that type skew is not a failure.
		update_option( $key, $value );
		$stored = get_option( $key );
		if ( (string) maybe_serialize( $stored ) !== (string) maybe_serialize( $value ) ) {
			return $this->error_result(
				sprintf(
					/* translators: %s: option key */
					__( 'Could not update option \'%s\'. The stored value does not match the requested value. The write may have been blocked by a pre_update_option filter or rejected by the database.', 'vibe-ai' ),
					$key
				)
			);
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Option updated: {$key}",
			'action_label' => 'Refresh',
		) );
		/* translators: %s: option key */
		return $this->success_result( array( 'message' => sprintf( __( 'Updated option \'%s\'.', 'vibe-ai' ), $key ) ) );
	}


	private function handle_option_add( $positional, $flags ) {
		if ( count( $positional ) < 2 ) {
			return $this->error_result( __( 'Usage: option add <key> <value> [--autoload=no]', 'vibe-ai' ) );
		}
		$key = $positional[0];

		if ( null !== self::match_option_name( $key, self::BLOCKED_OPTIONS ) ) {
			return $this->error_result( $this->blocked_option_message( $key, 'added' ) );
		}

		$boundary = $this->reject_bad_option_name( $key );
		if ( $boundary ) {
			return $boundary;
		}

		// Don't overwrite existing — match real wp-cli option add behavior.
		if ( null !== get_option( $key, null ) ) {
			/* translators: %s: option key */
			return $this->error_result( sprintf( __( 'Option \'%s\' already exists. Use option update to change it.', 'vibe-ai' ), $key ) );
		}

		$parsed = $this->parse_option_value( $positional[1], $flags, $key );
		if ( isset( $parsed['error'] ) ) {
			return $this->error_result( $parsed['error'] );
		}
		$value = $parsed['value'];

		$autoload = 'yes';
		if ( isset( $flags['autoload'] ) ) {
			$autoload = ( 'no' === $flags['autoload'] || false === $flags['autoload'] ) ? 'no' : 'yes';
		}

		$ok = add_option( $key, $value, '', $autoload );
		if ( ! $ok ) {
			return $this->error_result( __( 'Failed to add option.', 'vibe-ai' ) );
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Option added: {$key}",
			'action_label' => 'Refresh',
		) );
		/* translators: %s: option key */
		return $this->success_result( array( 'message' => sprintf( __( 'Added option \'%s\' (autoload=%s).', 'vibe-ai' ), $key, $autoload ) ) );
	}


	private function handle_option_delete( $positional, $flags ) {
		$keys = array_values( array_filter( (array) $positional, function ( $k ) {
			return '' !== (string) $k;
		} ) );
		if ( empty( $keys ) ) {
			return $this->error_result( __( 'Option key required. Usage: option delete <key> [<key>...]', 'vibe-ai' ) );
		}

		// HARD-BLOCK — these options are never approval-gated. No legitimate AI
		// workflow needs to delete them. Checked across the whole list before any
		// write, so a blocked key cannot ride along behind a permitted one.
		foreach ( $keys as $key ) {
			if ( null !== self::match_option_name( $key, self::BLOCKED_OPTIONS ) ) {
				return $this->error_result( $this->blocked_option_message( $key, 'deleted' ) );
			}
			$boundary = $this->reject_bad_option_name( $key );
			if ( $boundary ) {
				return $boundary;
			}
		}

		$results = array();
		$deleted = array();
		foreach ( $keys as $key ) {
			if ( null === get_option( $key, null ) ) {
				$results[] = array( 'option' => $key, 'status' => 'error', 'error' => 'not found' );
				continue;
			}
			if ( ! delete_option( $key ) ) {
				$results[] = array( 'option' => $key, 'status' => 'error', 'error' => 'delete failed' );
				continue;
			}
			$results[] = array( 'option' => $key, 'status' => 'deleted' );
			$deleted[] = $key;
		}

		if ( empty( $deleted ) ) {
			// Single key keeps the original message so existing callers still match on it.
			return 1 === count( $keys )
				/* translators: %s: option key */
				? $this->error_result( sprintf( __( 'Option \'%s\' not found.', 'vibe-ai' ), $keys[0] ) )
				: $this->error_result( __( 'No options were deleted.', 'vibe-ai' ) );
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => 'Options deleted: ' . implode( ', ', $deleted ),
			'action_label' => 'Refresh',
		) );

		if ( 1 === count( $keys ) ) {
			/* translators: %s: option key */
			return $this->success_result( array( 'message' => sprintf( __( 'Deleted option \'%s\'.', 'vibe-ai' ), $keys[0] ) ) );
		}
		return $this->success_result( array(
			/* translators: 1: number deleted, 2: number requested */
			'message' => sprintf( __( 'Deleted %1$d of %2$d options.', 'vibe-ai' ), count( $deleted ), count( $keys ) ),
			'results' => $results,
		) );
	}


	private function handle_transient_delete( $positional, $flags ) {
		$reject = $this->reject_unknown_flags( 'transient delete', $flags, array( 'all', 'expired' ), array(
			'network' => __( 'Site (network) transients are not emulated: this command only touches the current site\'s _transient_ rows. Delete a site transient with `option delete _site_transient_<name>`.', 'vibe-ai' ),
		) );
		if ( $reject ) {
			return $reject;
		}
		if ( empty( $positional[0] ) && empty( $flags['all'] ) && empty( $flags['expired'] ) ) {
			return $this->error_result( __( 'Usage: transient delete <name> | --all | --expired', 'vibe-ai' ) );
		}

		if ( ! empty( $flags['expired'] ) ) {
			$count = $this->purge_expired_transients();
			WPVibe_Change_Tracker::mark( array( 'summary' => "Expired transients purged: {$count}", 'action_label' => 'Refresh' ) );
			/* translators: %d: number of expired transients deleted */
			return $this->success_result( array( 'message' => sprintf( __( 'Deleted %d expired transient(s).', 'vibe-ai' ), $count ) ) );
		}

		if ( ! empty( $flags['all'] ) ) {
			$count = $this->delete_all_transients();
			WPVibe_Change_Tracker::mark( array( 'summary' => "All transients deleted: {$count}", 'action_label' => 'Refresh' ) );
			/* translators: %d: number of transients deleted */
			return $this->success_result( array( 'message' => sprintf( __( 'Deleted %d transient(s).', 'vibe-ai' ), $count ) ) );
		}

		$name = $positional[0];
		$ok   = delete_transient( $name );
		if ( ! $ok ) {
			/* translators: %s: transient name */
			return $this->error_result( sprintf( __( 'Transient \'%s\' not found or already expired.', 'vibe-ai' ), $name ) );
		}

		WPVibe_Change_Tracker::mark( array( 'summary' => "Transient deleted: {$name}", 'action_label' => 'Refresh' ) );
		/* translators: %s: transient name */
		return $this->success_result( array( 'message' => sprintf( __( 'Deleted transient \'%s\'.', 'vibe-ai' ), $name ) ) );
	}


	private function handle_transient_list( $positional, $flags ) {
		global $wpdb;
		// esc_like keeps literal %/_ literal (incl. the prefix's own underscores);
		// * and ? convert to SQL wildcards after.
		$search = isset( $flags['search'] )
			? str_replace( array( '*', '?' ), array( '%', '_' ), $wpdb->esc_like( ltrim( $flags['search'], '_' ) ) )
			: '%';
		$pattern = $wpdb->esc_like( '_transient_' ) . $search;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s ORDER BY option_name LIMIT 200",
				$pattern,
				$wpdb->esc_like( '_transient_timeout_' ) . '%'
			),
			ARRAY_A
		);

		$results = array();
		foreach ( $rows as $row ) {
			$name = preg_replace( '/^_transient_/', '', $row['option_name'] );
			$timeout = get_option( '_transient_timeout_' . $name );
			$results[] = array(
				'name'       => $name,
				'expires_at' => $timeout ? gmdate( 'Y-m-d H:i:s', (int) $timeout ) : null,
				'expired'    => $timeout && (int) $timeout < time(),
			);
		}

		return $this->success_result( $this->filter_fields( $results, $flags ) );
	}


	private function purge_expired_transients() {
		global $wpdb;
		$now = time();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$expired = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				$now
			)
		);
		$count = 0;
		foreach ( $expired as $timeout_name ) {
			$name = preg_replace( '/^_transient_timeout_/', '', $timeout_name );
			if ( delete_transient( $name ) ) {
				$count++;
			}
		}
		return $count;
	}


	private function delete_all_transients() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$names = $wpdb->get_col(
			$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s", $wpdb->esc_like( '_transient_' ) . '%', $wpdb->esc_like( '_transient_timeout_' ) . '%' )
		);
		$count = 0;
		foreach ( $names as $option_name ) {
			$name = preg_replace( '/^_transient_/', '', $option_name );
			if ( delete_transient( $name ) ) {
				$count++;
			}
		}
		return $count;
	}


	private function handle_option_patch( $positional, $flags ) {
		$action = $positional[0] ?? '';
		if ( ! in_array( $action, array( 'insert', 'update', 'delete' ), true ) || empty( $positional[1] ) ) {
			return $this->error_result( __( 'Usage: option patch <insert|update|delete> <option> <key-path>... [<value>]', 'vibe-ai' ) );
		}
		$key = $positional[1];
		if ( null !== self::match_option_name( $key, self::BLOCKED_OPTIONS ) ) {
			return $this->error_result( $this->blocked_option_message( $key, 'patched' ) );
		}
		$boundary = $this->reject_bad_option_name( $key );
		if ( $boundary ) {
			return $boundary;
		}

		$rest  = array_slice( $positional, 2 );
		$value = null;
		$raw   = null;
		if ( 'delete' === $action ) {
			$path = $rest;
		} else {
			if ( count( $rest ) < 2 ) {
				return $this->error_result( __( 'Both a key path and a value are required. Usage: option patch <insert|update> <option> <key-path>... <value>', 'vibe-ai' ) );
			}
			$raw  = array_pop( $rest );
			$path = $rest;
		}
		if ( empty( $path ) ) {
			return $this->error_result( __( 'Key path required.', 'vibe-ai' ) );
		}

		$current = get_option( $key, null );
		if ( null === $current ) {
			/* translators: %s: option key */
			return $this->error_result( sprintf( __( 'Option \'%s\' not found.', 'vibe-ai' ), $key ) );
		}
		if ( ! is_array( $current ) && ! is_object( $current ) ) {
			/* translators: %s: option key */
			return $this->error_result( sprintf( __( 'Option \'%s\' is not an array or object; use `option update` for scalar values.', 'vibe-ai' ), $key ) );
		}

		if ( 'delete' !== $action ) {
			// Only refuse JSON text when the write would otherwise land;
			// structurally doomed commands get their accurate error instead.
			if ( $this->patch_value_is_json_text( $raw, $flags ) && $this->patch_structurally_writable( $current, $path, $action ) ) {
				return $this->error_result( $this->patch_json_text_refusal( $key, $path, $action, $current ) );
			}
			$parsed = $this->parse_option_value( $raw, $flags, $key );
			if ( isset( $parsed['error'] ) ) {
				return $this->error_result( $parsed['error'] );
			}
			$value = $parsed['value'];
		}

		if ( 'update' === $action ) {
			$leaf_found = false;
			$leaf       = $this->option_leaf_at( $current, $path, $leaf_found );
			if ( $leaf_found && $this->patch_leaf_shape_conflict( $leaf, $value ) ) {
				return $this->error_result( sprintf(
					/* translators: 1: key path, 2: option key, 3: current shape, 4: new shape */
					__( 'Key path \'%1$s\' in option \'%2$s\' currently stores %3$s; writing %4$s would silently change its stored shape and can break the plugin that owns it. Write the same shape (--format=json for structures; for literal text use --format=plaintext, or a JSON-encoded string with --format=json when the text itself parses as JSON), or use `option patch delete` then `option patch insert` if the shape change is intentional.', 'vibe-ai' ),
					implode( '.', $path ),
					$key,
					( is_array( $leaf ) || is_object( $leaf ) ) ? __( 'an array/object', 'vibe-ai' ) : __( 'a scalar', 'vibe-ai' ),
					( is_array( $value ) || is_object( $value ) ) ? __( 'an array/object', 'vibe-ai' ) : __( 'a scalar', 'vibe-ai' )
				) );
			}
		}

		$error   = null;
		$patched = $this->patch_structure( $current, $path, $action, $value, $error );
		if ( null !== $error ) {
			return $this->error_result( $error );
		}

		update_option( $key, $patched );
		$stored = get_option( $key );
		if ( (string) maybe_serialize( $stored ) !== (string) maybe_serialize( $patched ) ) {
			/* translators: %s: option key */
			return $this->error_result( sprintf( __( 'Could not patch option \'%s\'. The stored value does not match; a pre_update_option filter may have rejected the write.', 'vibe-ai' ), $key ) );
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Option patched: {$key} → " . implode( '.', $path ),
			'action_label' => 'Refresh',
		) );

		/* translators: 1: action, 2: key path, 3: option key */
		return $this->success_result( array( 'message' => sprintf( __( 'Patched (%1$s) \'%2$s\' in option \'%3$s\'.', 'vibe-ai' ), $action, implode( '.', $path ), $key ) ) );
	}


	/**
	 * Walk a nested array/stdClass along a key path and return the leaf value.
	 * Mirrors patch_structure's segment resolution exactly (numeric segments
	 * cast to int) so the guard and classifier see the leaf the patch will hit.
	 */
	private function option_leaf_at( $data, $path, &$found ) {
		$found = true;
		$node  = $data;
		foreach ( $path as $segment ) {
			$seg = is_numeric( $segment ) ? (int) $segment : $segment;
			if ( is_object( $node ) && property_exists( $node, (string) $seg ) ) {
				$node = $node->{$seg};
			} elseif ( is_array( $node ) && array_key_exists( $seg, $node ) ) {
				$node = $node[ $seg ];
			} else {
				$found = false;
				return null;
			}
		}
		return $node;
	}


	/**
	 * Leaf-level twin of handle_option_update's type-shape guard. Upstream
	 * WP-CLI permits leaf type changes (entity-command's option-pluck-patch
	 * feature has no shape scenario); refusing non-empty flips is a deliberate
	 * divergence — a scalar silently replacing a builder preset subtree (or
	 * vice versa) corrupts the owning plugin the same way it does whole-option.
	 * Empty defaults ('', 0, false, null, []) are exempt: settings frameworks
	 * seed those and later write structures over them.
	 */
	private function patch_leaf_shape_conflict( $leaf, $value ) {
		if ( '' === $leaf || 0 === $leaf || false === $leaf || null === $leaf || array() === $leaf ) {
			return false;
		}
		$leaf_structured  = is_array( $leaf ) || is_object( $leaf );
		$value_structured = is_array( $value ) || is_object( $value );
		return $leaf_structured !== $value_structured;
	}


	/**
	 * `option patch` leaves live inside a decoded PHP array (the handler
	 * refuses scalar options), so a value that parses as a JSON object/array
	 * without --format=json is almost always a formatting mistake: stored as
	 * plaintext it becomes a string where the owning theme/plugin expects an
	 * array (fataled a live Astra site on PHP 8, issue #71). Deliberate parity
	 * divergence: upstream WP-CLI stores the literal string. The rare literal
	 * JSON-text leaf is still writable as a JSON-encoded string, which the
	 * refusal text explains.
	 */
	private function patch_value_is_json_text( $raw, $flags ) {
		$format = isset( $flags['format'] ) ? strtolower( (string) $flags['format'] ) : '';
		return in_array( $format, array( '', 'plaintext' ), true ) && is_array( json_decode( (string) $raw, true ) );
	}


	/** Mirrors patch_structure's structural checks so the JSON-text refusal
	 * only fires when the write would otherwise land (same walk the approval
	 * classifier uses). */
	private function patch_structurally_writable( $current, $path, $action ) {
		$leaf_found = false;
		$this->option_leaf_at( $current, $path, $leaf_found );
		if ( 'insert' !== $action ) {
			return $leaf_found;
		}
		if ( $leaf_found ) {
			return false;
		}
		if ( count( $path ) > 1 ) {
			$parent_found = false;
			$parent       = $this->option_leaf_at( $current, array_slice( $path, 0, -1 ), $parent_found );
			return $parent_found && ( is_array( $parent ) || is_object( $parent ) );
		}
		return true;
	}


	private function quote_path_for_guidance( $path ) {
		$out = array();
		foreach ( $path as $seg ) {
			$out[] = preg_match( '/\s/', (string) $seg ) ? "'" . $seg . "'" : (string) $seg;
		}
		return implode( ' ', $out );
	}


	private function patch_json_text_refusal( $key, $path, $action, $current ) {
		$leaf_found = false;
		$leaf       = $this->option_leaf_at( $current, $path, $leaf_found );
		$hatch      = __( 'If the owning plugin genuinely stores literal JSON text at this key, pass a JSON-encoded string with --format=json (single-quoted, e.g. \'"{\\"x\\":1}"\').', 'vibe-ai' );
		if ( 'update' === $action && $leaf_found && is_string( $leaf ) && is_array( json_decode( $leaf, true ) ) ) {
			// The leaf already stores literal JSON text; delete+insert would
			// decode it into an array, the exact corruption this guard exists
			// to prevent, so the encoded-string form is the fix here.
			$fix   = __( 'This key already stores literal JSON text, so keep it a string: pass the new text as a JSON-encoded string with --format=json (single-quoted, e.g. \'"{\\"x\\":1}"\'). A decoded structure here would corrupt the owning plugin\'s setting.', 'vibe-ai' );
			$hatch = '';
		} elseif ( 'update' === $action && $leaf_found && $this->patch_leaf_shape_conflict( $leaf, array() ) ) {
			// Reuse the shape guard's own predicate so this wording can never
			// recommend a --format=json retry the guard would then refuse.
			$fix = sprintf(
				/* translators: 1: option key, 2: space-separated key path */
				__( 'This key currently stores non-empty scalar text, so replace it explicitly: run `option patch delete %1$s %2$s`, then `option patch insert %1$s %2$s \'<json>\' --format=json`.', 'vibe-ai' ),
				$key,
				$this->quote_path_for_guidance( $path )
			);
		} else {
			$fix = __( 'Re-run the same command with --format=json to store the structure.', 'vibe-ai' );
		}
		$message = sprintf(
			/* translators: 1: key path, 2: option key, 3: corrective guidance */
			__( 'Key path \'%1$s\' in option \'%2$s\': the value parses as a JSON object/array, but \'%2$s\' is a decoded PHP array whose leaves are decoded structures, never JSON text. Stored as plaintext it becomes a string where the owning theme/plugin expects an array, which can fatally break the site. %3$s', 'vibe-ai' ),
			implode( '.', $path ),
			$key,
			$fix
		);
		return '' === $hatch ? $message : $message . ' ' . $hatch;
	}


	/** Walk a nested array/stdClass along $path and apply insert/update/delete at the leaf. */
	private function patch_structure( $data, $path, $action, $value, &$error, $trail = '' ) {
		$segment = $path[0];
		$seg_key = is_numeric( $segment ) ? (int) $segment : $segment;
		$rest    = array_slice( $path, 1 );
		$is_obj  = is_object( $data );
		$where   = '' === $trail ? (string) $segment : $trail . '.' . $segment;

		if ( ! is_array( $data ) && ! $is_obj ) {
			/* translators: %s: key path position */
			$error = sprintf( __( 'Cannot descend into a non-array value at \'%s\'.', 'vibe-ai' ), $trail ?: (string) $segment );
			return $data;
		}

		$exists = $is_obj ? property_exists( $data, (string) $seg_key ) : array_key_exists( $seg_key, $data );

		if ( empty( $rest ) ) {
			if ( 'insert' === $action && $exists ) {
				/* translators: %s: key path */
				$error = sprintf( __( 'Key \'%s\' already exists. Use `option patch update` to change it.', 'vibe-ai' ), $where );
				return $data;
			}
			if ( 'insert' !== $action && ! $exists ) {
				/* translators: %s: key path */
				$error = sprintf( __( 'No data exists at key path \'%s\'.', 'vibe-ai' ), $where );
				return $data;
			}
			if ( 'delete' === $action ) {
				if ( $is_obj ) {
					unset( $data->{$seg_key} );
				} else {
					unset( $data[ $seg_key ] );
				}
			} elseif ( $is_obj ) {
				$data->{$seg_key} = $value;
			} else {
				$data[ $seg_key ] = $value;
			}
			return $data;
		}

		if ( ! $exists ) {
			/* translators: %s: key path */
			$error = sprintf( __( 'No data exists at key path \'%s\'.', 'vibe-ai' ), $where );
			return $data;
		}
		if ( $is_obj ) {
			$data->{$seg_key} = $this->patch_structure( $data->{$seg_key}, $rest, $action, $value, $error, $where );
		} else {
			$data[ $seg_key ] = $this->patch_structure( $data[ $seg_key ], $rest, $action, $value, $error, $where );
		}
		return $data;
	}


	private function handle_transient_get( $positional, $flags ) {
		if ( empty( $positional[0] ) ) {
			return $this->error_result( __( 'Transient key required. Usage: transient get <key> [--network]', 'vibe-ai' ) );
		}
		$value = ! empty( $flags['network'] ) ? get_site_transient( $positional[0] ) : get_transient( $positional[0] );
		if ( false === $value ) {
			/* translators: %s: transient key */
			return $this->error_result( sprintf( __( 'Transient \'%s\' is not set (or has expired).', 'vibe-ai' ), $positional[0] ) );
		}
		return array(
			'exit_code' => 0,
			'stdout'    => is_scalar( $value ) ? (string) $value : wp_json_encode( $value, JSON_PRETTY_PRINT ),
			'stderr'    => '',
		);
	}


	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Wrap a BLOCKED_OPTIONS hard-block message in a product-namespaced XML
	 * directive so the AI treats it as "how to respond" guidance and stops
	 * suggesting bypass workarounds (wp-cli on the server, direct SQL, etc.).
	 * Same pattern as the cap and review-nudge directives elsewhere in WPVibe.
	 */
	/**
	 * Boundary refusal for a write to an option whose name carries non-ASCII
	 * bytes (evasion backstop, collation-independent). Returns error_result or
	 * null. Runs AFTER the BLOCKED check so a padded protected name still gets
	 * the specific "protected" message.
	 */
	private function reject_bad_option_name( $key ) {
		if ( ! WPVibe_CLI::option_name_not_printable_ascii( $key ) ) {
			return null;
		}
		return $this->error_result( sprintf(
			/* translators: %s: option key */
			__( 'Option name \'%s\' must be printable ASCII with no spaces. WordPress option names are plain slugs, and a padded or disguised name can resolve to a different (possibly protected) option than it appears to, so it is refused. Re-run with the exact option name.', 'vibe-ai' ),
			$key
		) );
	}


	private function blocked_option_message( $key, $verb ) {
		return implode( "\n", array(
			'<wpvibe-blocked-option>',
			/* translators: 1: option key, 2: verb (added or deleted) */
			sprintf( __( 'The option "%1$s" is permanently protected by WPVibe and cannot be %2$s via AI tools.', 'vibe-ai' ), $key, $verb ),
			'',
			__( 'This protection exists because changing this option would break the site (broken admin URLs, broken login, broken auth, etc.). DO NOT suggest manual workarounds — do not tell the user to run wp-cli on the server, edit wp-config.php, or run SQL against the database. The user is being protected from accidental destructive changes; respect that.', 'vibe-ai' ),
			'',
			__( 'How to reply: in one short sentence, tell the user this specific option is permanently protected and they should change it through WordPress admin if they really need to. Do not offer alternative deletion methods.', 'vibe-ai' ),
			'</wpvibe-blocked-option>',
		) );
	}

}
