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
	private function truncation_notice( $rows, $limit, $command ) {
		if ( count( $rows ) < $limit ) {
			return '';
		}
		/* translators: 1: row limit, 2: command */
		return sprintf( __( 'Results truncated at %1$d rows. More may exist, so do NOT treat this as the complete set for a bulk operation. Narrow the query, or raise the limit where `%2$s` supports it.', 'vibe-ai' ), $limit, $command );
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


	/** Shape a single- or multi-target post op response consistently. */
	private function bulk_result( $action, $ok, $ids, $results ) {
		$total = count( $ids );
		if ( 1 === $total ) {
			$only = $results[0];
			if ( isset( $only['status'] ) && 'error' === $only['status'] ) {
				/* translators: 1: post ID, 2: error message */
				return $this->error_result( sprintf( __( 'Post #%1$d: %2$s', 'vibe-ai' ), $only['id'], $only['error'] ) );
			}
			/* translators: 1: post ID, 2: action taken */
			return $this->success_result( array( 'message' => sprintf( __( 'Post #%1$d %2$s.', 'vibe-ai' ), $ids[0], $action ) ) );
		}
		return $this->success_result( array(
			/* translators: 1: success count, 2: total, 3: action taken */
			'message'   => sprintf( __( '%1$d of %2$d posts %3$s.', 'vibe-ai' ), $ok, $total, $action ),
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
	 * Strip credential values from the raw token list before it is persisted to
	 * the Approval Log. `user update --user_pass=x` would otherwise write the
	 * cleartext password into an admin-readable row. Handles both the `=value`
	 * token and (defensively) a bare `--user_pass` followed by a value token,
	 * though the parser only accepts the `=` form for a live write.
	 */
	private function redact_sensitive_flags( $tokens ) {
		$tokens = array_values( (array) $tokens );
		$out    = array();
		$count  = count( $tokens );
		for ( $i = 0; $i < $count; $i++ ) {
			$t = $tokens[ $i ];
			if ( is_string( $t ) && preg_match( '/^--user[-_]pass=/i', $t ) ) {
				$out[] = '--user_pass=<redacted>';
				continue;
			}
			if ( is_string( $t ) && preg_match( '/^--user[-_]pass$/i', $t ) ) {
				$out[] = '--user_pass';
				if ( $i + 1 < $count && is_string( $tokens[ $i + 1 ] ) && 0 !== strpos( $tokens[ $i + 1 ], '-' ) ) {
					$out[] = '<redacted>';
					$i++;
				}
				continue;
			}
			$out[] = $t;
		}
		return $out;
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
