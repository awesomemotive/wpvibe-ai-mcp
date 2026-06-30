<?php
/**
 * Targeted database content edits for WPVibe.
 *
 * The DB twin of WPVibe_File_Ops::edit: applies a match-once str_replace to a
 * single post field, post meta value, or option so large content never has to
 * round-trip through the AI's context. Same contract as file edit — old_content
 * must match exactly once, or you get no_match / multiple_matches back.
 *
 * Capability + correctness only (no billing). The MCP gates which users reach
 * this; the plugin just enforces WP capabilities and refuses unsafe edits.
 */

defined( 'ABSPATH' ) || exit;

class WPVibe_Content_Ops {

	/** Post columns this tool is allowed to patch. Other columns are scalars or structural. */
	const EDITABLE_POST_FIELDS = array( 'post_content', 'post_excerpt', 'post_title' );

	// ------------------------------------------------------------------
	// Normalization — ported from the MCP file tools' normalize.ts so a
	// content edit applied via rest_api gets the same resilience to Claude's
	// curly quotes / sanitized tokens that edit_file gets MCP-side.
	// ------------------------------------------------------------------

	/** Curly quotes → straight ASCII (UTF-8 byte sequences). */
	private static function normalize_quotes( $str ) {
		return strtr( $str, array(
			"\xE2\x80\x98" => "'",
			"\xE2\x80\x99" => "'",
			"\xE2\x80\x9C" => '"',
			"\xE2\x80\x9D" => '"',
		) );
	}

	/** Reverse the XML-token sanitization Claude's API applies to its own output. */
	private static function desanitize( $str ) {
		return strtr( $str, array(
			'<fnr>'         => '<function_results>',
			'<n>'           => '<name>',
			'</n>'          => '</name>',
			'<o>'           => '<output>',
			'</o>'          => '</output>',
			'<e>'           => '<error>',
			'</e>'          => '</error>',
			'<s>'           => '<system>',
			'</s>'          => '</system>',
			'<r>'           => '<result>',
			'</r>'          => '</result>',
			'< META_START >' => '<META_START>',
			'< META_END >'  => '<META_END>',
			'< EOT >'       => '<EOT>',
			'< META >'      => '<META>',
			'< SOS >'       => '<SOS>',
			"\n\nH:"        => "\n\nHuman:",
			"\n\nA:"        => "\n\nAssistant:",
		) );
	}

	/** Strip trailing spaces/tabs from each line, preserving line endings. */
	private static function strip_trailing_whitespace( $str ) {
		return preg_replace( '/[ \t]+(?=\r\n|\r|\n|$)/', '', $str );
	}

	// ------------------------------------------------------------------
	// Pure replacement logic — no WordPress calls, unit-testable in isolation.
	// ------------------------------------------------------------------

	/**
	 * Apply the match-once str_replace. Returns the updated string or a WP_Error
	 * (empty_old / no_change / no_match / multiple_matches / not_text).
	 *
	 * old/new are normalized the same way edit_file normalizes them MCP-side;
	 * $current (the raw DB value) is matched verbatim — we never rewrite the
	 * stored value's own quote style, only the span being replaced.
	 *
	 * @param mixed  $current     Current stored value.
	 * @param string $old_content Exact text to find.
	 * @param string $new_content Replacement text.
	 * @param bool   $replace_all Replace every occurrence instead of requiring a unique match.
	 * @param bool   $whole_word  Match only whole-word occurrences (wraps old in \b…\b).
	 * @param int    $replaced    Out-param: number of occurrences replaced.
	 * @return string|WP_Error
	 */
	public function compute_replacement( $current, $old_content, $new_content, $replace_all = false, $whole_word = false, &$replaced = null ) {
		$replaced = 0;
		if ( ! is_string( $current ) ) {
			return new WP_Error( 'not_text', __( 'Stored value is not editable as text (it is an array or object). Edit it with rest_api or wp-cli instead.', 'vibe-ai' ), array( 'status' => 422 ) );
		}

		$old = self::normalize_quotes( self::desanitize( $old_content ) );
		$new = self::strip_trailing_whitespace( self::normalize_quotes( self::desanitize( $new_content ) ) );

		if ( '' === $old ) {
			return new WP_Error( 'empty_old', __( 'old_content cannot be empty.', 'vibe-ai' ), array( 'status' => 400 ) );
		}
		if ( $old === $new ) {
			return new WP_Error( 'no_change', __( 'old_content and new_content are identical — nothing to do.', 'vibe-ai' ), array( 'status' => 400 ) );
		}

		// Whole-word matching uses a fully-escaped literal between \b anchors, so
		// there are no user-controlled quantifiers/alternation: ReDoS-safe.
		if ( $whole_word ) {
			$pattern = '/\b' . preg_quote( $old, '/' ) . '\b/u';
			$count   = preg_match_all( $pattern, $current );
			if ( false === $count ) {
				return new WP_Error( 'match_failed', __( 'Whole-word match failed (the text may not be valid UTF-8). Try without whole_word.', 'vibe-ai' ), array( 'status' => 422 ) );
			}
			if ( 0 === $count ) {
				return new WP_Error( 'no_match', __( 'No whole-word match for old_content. Use content/search to locate the exact text, then retry.', 'vibe-ai' ), array( 'status' => 422 ) );
			}
			if ( ! $replace_all && $count > 1 ) {
				/* translators: %d: number of matching locations */
				return new WP_Error( 'multiple_matches', sprintf( __( 'old_content matches %d whole-word locations. Add surrounding context to target one, or set replace_all=true to change all of them.', 'vibe-ai' ), $count ), array( 'status' => 422 ) );
			}
			// Callback (not a replacement string) so $ and \ in new_content are literal.
			$updated = preg_replace_callback( $pattern, static function () use ( $new ) { return $new; }, $current, $replace_all ? -1 : 1, $replaced );
			if ( null === $updated ) {
				return new WP_Error( 'replace_failed', __( 'Replacement failed (PCRE limit or invalid encoding).', 'vibe-ai' ), array( 'status' => 422 ) );
			}
			return $updated;
		}

		$count = substr_count( $current, $old );
		if ( 0 === $count ) {
			return new WP_Error( 'no_match', __( 'old_content not found. Use content/search to locate the exact current text, then retry.', 'vibe-ai' ), array( 'status' => 422 ) );
		}
		if ( ! $replace_all && $count > 1 ) {
			/* translators: %d: number of matching locations */
			return new WP_Error( 'multiple_matches', sprintf( __( 'old_content matches %d locations. Add surrounding context to target one, or set replace_all=true to change all of them.', 'vibe-ai' ), $count ), array( 'status' => 422 ) );
		}

		$replaced = $replace_all ? $count : 1;
		return str_replace( $old, $new, $current );
	}

	/**
	 * Grep a single value for a pattern. Returns matching "lines" (split on \n)
	 * with one line of context each, so the AI can find an edit anchor without
	 * pulling the whole field into context. Pure — no WordPress calls.
	 *
	 * @return array{matches: array, truncated: bool, total_lines: int}
	 */
	public function find_matches( $content, $pattern, $case_sensitive = false, $max_results = 50 ) {
		$lines     = explode( "\n", (string) $content );
		$total     = count( $lines );
		$out       = array();
		$truncated = false;

		foreach ( $lines as $i => $line ) {
			if ( count( $out ) >= $max_results ) {
				$truncated = true;
				break;
			}
			$found = $case_sensitive
				? ( strpos( $line, $pattern ) !== false )
				: ( stripos( $line, $pattern ) !== false );
			if ( ! $found ) {
				continue;
			}
			$match = array(
				'line'    => $i + 1,
				'content' => mb_substr( $line, 0, 400 ),
			);
			if ( $i > 0 ) {
				$match['context_before'] = mb_substr( $lines[ $i - 1 ], 0, 200 );
			}
			if ( $i < $total - 1 ) {
				$match['context_after'] = mb_substr( $lines[ $i + 1 ], 0, 200 );
			}
			$out[] = $match;
		}

		return array(
			'matches'     => $out,
			'truncated'   => $truncated,
			'total_lines' => $total,
		);
	}

	// ------------------------------------------------------------------
	// Public entry points (WordPress-backed).
	// ------------------------------------------------------------------

	/**
	 * @param string $type        post | meta | option
	 * @param array  $args        post: {post_id, field}; meta: {post_id, key}; option: {name}
	 * @param string $old_content Exact text to find.
	 * @param string $new_content Replacement text.
	 * @param bool   $replace_all Replace every occurrence instead of requiring a unique match.
	 * @param bool   $whole_word  Match only whole-word occurrences.
	 * @return WP_REST_Response|WP_Error
	 */
	public function edit( $type, $args, $old_content, $new_content, $replace_all = false, $whole_word = false ) {
		$current = $this->load( $type, $args );
		if ( is_wp_error( $current ) ) {
			return $current;
		}

		// str_replace inside a PHP-serialized string corrupts its s:N: length
		// prefixes. Post columns are always raw; meta/option are auto-unserialized
		// on read, so a serialized string here means it was stored escaped.
		if ( in_array( $type, array( 'meta', 'option' ), true ) && is_string( $current ) && is_serialized( $current ) ) {
			return new WP_Error( 'serialized_value', __( 'This value is PHP-serialized; a text replace would corrupt it. Edit it with rest_api or wp-cli instead.', 'vibe-ai' ), array( 'status' => 422 ) );
		}

		$replaced = 0;
		$updated  = $this->compute_replacement( $current, $old_content, $new_content, (bool) $replace_all, (bool) $whole_word, $replaced );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		$stored = $this->store( $type, $args, $updated );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		$label = $this->target_label( $type, $args );

		WPVibe_Audit_Log::log_execution( array(
			'operation'      => 'content edit',
			'command'        => $label,
			'result_summary' => sprintf( 'edited; replaced %d; new length %d', $replaced, strlen( $updated ) ),
		) );

		if ( 'post' === $type ) {
			$post_id = (int) $args['post_id'];
			WPVibe_Change_Tracker::mark( array(
				'summary'   => "Content edited: {$label}",
				'post_id'   => $post_id,
				'admin_url' => get_edit_post_link( $post_id, 'raw' ),
				'url'       => get_permalink( $post_id ),
			) );
		} else {
			WPVibe_Change_Tracker::mark( array( 'summary' => "Content edited: {$label}" ) );
		}

		return rest_ensure_response( array(
			'target'   => $label,
			'status'   => 'edited',
			'message'  => __( 'Content updated successfully.', 'vibe-ai' ),
			'replaced' => $replaced,
			'bytes'    => strlen( $updated ),
		) );
	}

	/**
	 * @param string $type           post | meta | option
	 * @param array  $args           Same shape as edit().
	 * @param string $pattern        Substring to search for.
	 * @param bool   $case_sensitive Case-sensitive match.
	 * @param int    $max_results    Cap on returned matches.
	 * @return WP_REST_Response|WP_Error
	 */
	public function search( $type, $args, $pattern, $case_sensitive = false, $max_results = 50 ) {
		if ( '' === (string) $pattern ) {
			return new WP_Error( 'empty_pattern', __( 'Search pattern cannot be empty.', 'vibe-ai' ), array( 'status' => 400 ) );
		}

		$current = $this->load( $type, $args );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		if ( ! is_string( $current ) ) {
			return new WP_Error( 'not_text', __( 'Stored value is not searchable as text (it is an array or object).', 'vibe-ai' ), array( 'status' => 422 ) );
		}

		$result = $this->find_matches( $current, $pattern, (bool) $case_sensitive, max( 1, (int) $max_results ) );

		return rest_ensure_response( array(
			'target'        => $this->target_label( $type, $args ),
			'pattern'       => $pattern,
			'matches'       => $result['matches'],
			'total_matches' => count( $result['matches'] ),
			'total_lines'   => $result['total_lines'],
			'truncated'     => $result['truncated'],
		) );
	}

	// ------------------------------------------------------------------
	// Internal: load / store / labels.
	// ------------------------------------------------------------------

	/** @return mixed|WP_Error Current stored value, or an error. */
	private function load( $type, $args ) {
		switch ( $type ) {
			case 'post':
				$post_id = (int) ( $args['post_id'] ?? 0 );
				$field   = (string) ( $args['field'] ?? '' );
				if ( ! in_array( $field, self::EDITABLE_POST_FIELDS, true ) ) {
					/* translators: %s: comma-separated field list */
					return new WP_Error( 'bad_field', sprintf( __( 'field must be one of: %s', 'vibe-ai' ), implode( ', ', self::EDITABLE_POST_FIELDS ) ), array( 'status' => 400 ) );
				}
				if ( ! get_post( $post_id ) ) {
					return new WP_Error( 'not_found', __( 'Post not found.', 'vibe-ai' ), array( 'status' => 404 ) );
				}
				return (string) get_post_field( $field, $post_id, 'raw' );

			case 'meta':
				$post_id = (int) ( $args['post_id'] ?? 0 );
				$key     = (string) ( $args['key'] ?? '' );
				if ( '' === $key ) {
					return new WP_Error( 'bad_key', __( 'meta_key is required.', 'vibe-ai' ), array( 'status' => 400 ) );
				}
				if ( ! get_post( $post_id ) ) {
					return new WP_Error( 'not_found', __( 'Post not found.', 'vibe-ai' ), array( 'status' => 404 ) );
				}
				// edit_post is post-level; protected/registered meta keys carry their
				// own auth boundary (protected-meta rule + auth_callback) that direct
				// get_post_meta() bypasses. edit_post_meta maps both via map_meta_cap.
				if ( ! current_user_can( 'edit_post_meta', $post_id, $key ) ) {
					return new WP_Error( 'meta_forbidden', __( 'You are not allowed to access this meta key.', 'vibe-ai' ), array( 'status' => 403 ) );
				}
				if ( ! metadata_exists( 'post', $post_id, $key ) ) {
					return new WP_Error( 'meta_not_found', __( 'Meta key not found on this post.', 'vibe-ai' ), array( 'status' => 404 ) );
				}
				return get_post_meta( $post_id, $key, true );

			case 'option':
				$name     = (string) ( $args['name'] ?? '' );
				if ( '' === $name ) {
					return new WP_Error( 'bad_option', __( 'option_name is required.', 'vibe-ai' ), array( 'status' => 400 ) );
				}
				$sentinel = '__wpvibe_option_missing__';
				$value    = get_option( $name, $sentinel );
				if ( $sentinel === $value ) {
					return new WP_Error( 'option_not_found', __( 'Option not found.', 'vibe-ai' ), array( 'status' => 404 ) );
				}
				return $value;

			default:
				return new WP_Error( 'bad_target', __( 'target_type must be post, meta, or option.', 'vibe-ai' ), array( 'status' => 400 ) );
		}
	}

	/** @return true|WP_Error */
	private function store( $type, $args, $updated ) {
		switch ( $type ) {
			case 'post':
				// wp_update_post expects slashed data; it unslashes internally.
				$res = wp_update_post( array(
					'ID'           => (int) $args['post_id'],
					$args['field'] => wp_slash( $updated ),
				), true );
				if ( is_wp_error( $res ) ) {
					return $res;
				}
				if ( 0 === $res ) {
					return new WP_Error( 'update_failed', __( 'Failed to update the post.', 'vibe-ai' ), array( 'status' => 500 ) );
				}
				return true;

			case 'meta':
				$post_id = (int) $args['post_id'];
				$key     = (string) $args['key'];
				// Same meta-level auth boundary as load(); edit_post alone is not it.
				if ( ! current_user_can( 'edit_post_meta', $post_id, $key ) ) {
					return new WP_Error( 'meta_forbidden', __( 'You are not allowed to edit this meta key.', 'vibe-ai' ), array( 'status' => 403 ) );
				}
				// update_metadata unslashes; slash to preserve backslashes.
				$ok = update_post_meta( $post_id, $key, wp_slash( $updated ) );
				if ( false === $ok && (string) get_post_meta( $post_id, $key, true ) !== (string) $updated ) {
					return new WP_Error( 'update_failed', __( 'Failed to update the meta value.', 'vibe-ai' ), array( 'status' => 500 ) );
				}
				return true;

			case 'option':
				// update_option does NOT unslash — store the value verbatim.
				$ok = update_option( (string) $args['name'], $updated );
				if ( false === $ok && (string) get_option( (string) $args['name'] ) !== (string) $updated ) {
					return new WP_Error( 'update_failed', __( 'Failed to update the option.', 'vibe-ai' ), array( 'status' => 500 ) );
				}
				return true;

			default:
				return new WP_Error( 'bad_target', __( 'target_type must be post, meta, or option.', 'vibe-ai' ), array( 'status' => 400 ) );
		}
	}

	private function target_label( $type, $args ) {
		switch ( $type ) {
			case 'post':
				return 'post ' . (int) ( $args['post_id'] ?? 0 ) . ' ' . (string) ( $args['field'] ?? '' );
			case 'meta':
				return 'post ' . (int) ( $args['post_id'] ?? 0 ) . ' meta:' . (string) ( $args['key'] ?? '' );
			case 'option':
				return 'option:' . (string) ( $args['name'] ?? '' );
			default:
				return (string) $type;
		}
	}
}
