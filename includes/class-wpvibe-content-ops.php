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

	/** Short names the AI sends for the editable post fields. */
	const POST_FIELD_ALIASES = array(
		'content' => 'post_content',
		'excerpt' => 'post_excerpt',
		'title'   => 'post_title',
	);

	// ------------------------------------------------------------------
	// Normalization — ported from the MCP file tools' normalize.ts so a
	// content edit applied via rest_api gets the same resilience to Claude's
	// curly quotes / sanitized tokens that edit_file gets MCP-side.
	// ------------------------------------------------------------------

	const LEFT_SINGLE  = "\xE2\x80\x98";
	const RIGHT_SINGLE = "\xE2\x80\x99";
	const LEFT_DOUBLE  = "\xE2\x80\x9C";
	const RIGHT_DOUBLE = "\xE2\x80\x9D";

	/**
	 * preg_quote()d literal where every quote character, straight or curly,
	 * matches all of its equivalents — texturized prose stores curly quotes
	 * while the model usually sends straight ones, and vice versa.
	 */
	private static function quote_lenient_pattern( $str ) {
		$single = "(?:'|" . self::LEFT_SINGLE . '|' . self::RIGHT_SINGLE . ')';
		$double = '(?:"|' . self::LEFT_DOUBLE . '|' . self::RIGHT_DOUBLE . ')';
		return strtr( preg_quote( $str, '/' ), array(
			"'"                => $single,
			'"'                => $double,
			self::LEFT_SINGLE  => $single,
			self::RIGHT_SINGLE => $single,
			self::LEFT_DOUBLE  => $double,
			self::RIGHT_DOUBLE => $double,
		) );
	}

	/**
	 * Quote-lenient pattern plus HTML-entity bridges for text models read from
	 * rendered output. Stored HTML often has &amp; / &nbsp; while the model
	 * types & / space. preg_quote does not escape &; expand entities first.
	 */
	private static function match_lenient_pattern( $str ) {
		$p = self::quote_lenient_pattern( $str );
		// Longest entity first so bare & does not split &amp; / &nbsp;.
		$p = preg_replace_callback(
			'/&amp;|&nbsp;|&/',
			static function ( $m ) {
				if ( '&amp;' === $m[0] ) {
					return '(?:&amp;|&)';
				}
				if ( '&nbsp;' === $m[0] ) {
					return '(?:&nbsp;| |\xC2\xA0)';
				}
				return '(?:&amp;|&)';
			},
			$p
		);
		$p = str_replace( ' ', '(?: |&nbsp;|\xC2\xA0)', $p );
		return $p;
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
	 * old/new are desanitized the same way edit_file desanitizes them MCP-side.
	 * Matching is byte-exact first, then lenient: straight/curly quotes and
	 * common HTML entities (&amp;/&nbsp;) interchange with what the model
	 * types after reading rendered HTML. new_content is written verbatim;
	 * wptexturize re-curls straight quotes at render time, so display
	 * typography stays consistent without rewriting what was sent.
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
			return new WP_Error( 'not_text', __( 'Stored value is not editable as text (it is an array or object; content/edit only rewrites strings). For an option, change one key with run_wp_cli `option patch update <option> <key-path> <value>`, or replace the whole value with `option update <option> \'<json>\' --format=json`. For post meta, pass the whole structure as JSON with --format=json so it is stored as an array: `post meta update <id> <key> \'<json>\' --format=json` (a flat scalar value would collapse the array). For a page builder\'s layout, use that builder\'s save route instead.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'not_supported', false, array( 'status' => 422 ) ) );
		}

		$old = self::desanitize( $old_content );
		$new = self::strip_trailing_whitespace( self::desanitize( $new_content ) );

		if ( '' === $old ) {
			return new WP_Error( 'empty_old', __( 'old_content cannot be empty.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 400 ) ) );
		}
		if ( $old === $new ) {
			return new WP_Error( 'no_change', __( 'old_content and new_content are identical — nothing to do.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 400 ) ) );
		}

		// Callback (not a replacement string) so $ and \ in new_content are literal.
		$insert_cb = static function () use ( $new ) {
			return $new;
		};

		// Whole-word matching uses a fully-escaped literal between \b anchors, so
		// there are no user-controlled quantifiers/alternation: ReDoS-safe.
		if ( $whole_word ) {
			$pattern = '/\b' . self::match_lenient_pattern( $old ) . '\b/u';
			$count   = preg_match_all( $pattern, $current );
			if ( false === $count ) {
				return new WP_Error( 'match_failed', __( 'Whole-word match failed (the text may not be valid UTF-8). Try without whole_word.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 422 ) ) );
			}
			if ( 0 === $count ) {
				return new WP_Error( 'no_match', __( 'No whole-word match for old_content. Use content/search to locate the exact text, then retry.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 422 ) ) );
			}
			if ( ! $replace_all && $count > 1 ) {
				/* translators: %d: number of matching locations */
				return new WP_Error( 'multiple_matches', sprintf( __( 'old_content matches %d whole-word locations. Add surrounding context to target one, or set replace_all=true to change all of them.', 'vibe-ai' ), $count ), WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 422 ) ) );
			}
			$updated = preg_replace_callback( $pattern, $insert_cb, $current, $replace_all ? -1 : 1, $replaced );
			if ( null === $updated ) {
				return new WP_Error( 'replace_failed', __( 'Replacement failed (PCRE limit or invalid encoding).', 'vibe-ai' ), WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 422 ) ) );
			}
			return $updated;
		}

		// Byte-exact first: with mixed quote styles in the value, an exact
		// single match wins before leniency can call it ambiguous.
		$exact = substr_count( $current, $old );
		if ( ! $replace_all && 1 === $exact ) {
			$replaced = 1;
			return str_replace( $old, $new, $current );
		}
		if ( ! $replace_all && $exact > 1 ) {
			/* translators: %d: number of matching locations */
			return new WP_Error( 'multiple_matches', sprintf( __( 'old_content matches %d locations. Add surrounding context to target one, or set replace_all=true to change all of them.', 'vibe-ai' ), $exact ), WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 422 ) ) );
		}

		$pattern = '/' . self::match_lenient_pattern( $old ) . '/u';
		$count   = preg_match_all( $pattern, $current );
		if ( false === $count ) {
			// Stored value is not valid UTF-8; only byte-exact matching is possible.
			if ( 0 === $exact ) {
				return new WP_Error( 'no_match', __( 'old_content not found. Use content/search to locate the exact current text, then retry.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 422 ) ) );
			}
			$replaced = $exact;
			return str_replace( $old, $new, $current );
		}
		if ( 0 === $count ) {
			// Measured retry: the same text with different whitespace (a wrapped
			// line, a double space) is the commonest near-miss. One exact
			// whitespace-insensitive hit is applied; anything else stays a miss.
			$ws = self::whitespace_lenient_replace( $current, $old, $new, $replace_all );
			if ( null !== $ws ) {
				$replaced = $ws['replaced'];
				$this->last_match_mode = 'whitespace';
				return $ws['value'];
			}
			return new WP_Error( 'no_match', __( 'old_content not found in the stored value (even allowing for quote style and whitespace differences). Use content/search with a short distinctive pattern and paste its snippet verbatim, then retry.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 422 ) ) );
		}
		if ( ! $replace_all && $count > 1 ) {
			/* translators: %d: number of matching locations */
			return new WP_Error( 'multiple_matches', sprintf( __( 'old_content matches %d locations. Add surrounding context to target one, or set replace_all=true to change all of them.', 'vibe-ai' ), $count ), WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 422 ) ) );
		}

		$updated = preg_replace_callback( $pattern, $insert_cb, $current, $replace_all ? -1 : 1, $replaced );
		if ( null === $updated ) {
			return new WP_Error( 'replace_failed', __( 'Replacement failed (PCRE limit or invalid encoding).', 'vibe-ai' ), WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 422 ) ) );
		}
		return $updated;
	}

	/**
	 * Grep a single value for a pattern. Returns matching "lines" (split on \n)
	 * with one line of context each, so the AI can find an edit anchor without
	 * pulling the whole field into context. Pure — no WordPress calls.
	 *
	 * Matching uses the same desanitize + lenient rules as the edit path,
	 * so anything search returns is guaranteed findable by compute_replacement.
	 * Snippets are windowed around the match on long lines and carry explicit
	 * truncation flags; a silent cut used to feed models un-matchable text.
	 *
	 * @return array{matches: array, truncated: bool, total_lines: int}
	 */
	public function find_matches( $content, $pattern, $case_sensitive = false, $max_results = 50 ) {
		$lines     = explode( "\n", (string) $content );
		$total     = count( $lines );
		$out       = array();
		$truncated = false;

		$needle = self::desanitize( (string) $pattern );
		$regex  = '/' . self::match_lenient_pattern( $needle ) . '/u' . ( $case_sensitive ? '' : 'i' );

		foreach ( $lines as $i => $line ) {
			if ( count( $out ) >= $max_results ) {
				$truncated = true;
				break;
			}
			$byte_pos = false;
			$hit      = preg_match( $regex, $line, $m, PREG_OFFSET_CAPTURE );
			if ( 1 === $hit ) {
				$byte_pos = $m[0][1];
			} elseif ( false === $hit ) {
				// Not valid UTF-8; only byte-literal matching is possible.
				$byte_pos = $case_sensitive ? strpos( $line, $needle ) : stripos( $line, $needle );
			}
			if ( false === $byte_pos ) {
				continue;
			}

			$line_len = mb_strlen( $line );
			$char_pos = mb_strlen( substr( $line, 0, $byte_pos ) );

			$window_start = 0;
			if ( $line_len > 400 && $char_pos > 300 ) {
				$window_start = min( max( 0, $char_pos - 100 ), $line_len - 400 );
			}

			$match = array(
				'line'    => $i + 1,
				'content' => mb_substr( $line, $window_start, 400 ),
			);
			if ( $window_start > 0 ) {
				$match['snippet_starts_at_char'] = $window_start;
			}
			if ( $line_len > $window_start + 400 ) {
				$match['snippet_truncated'] = true;
			}
			if ( $line_len > 400 ) {
				$match['line_length'] = $line_len;
			}
			if ( $i > 0 ) {
				$match['context_before'] = mb_substr( $lines[ $i - 1 ], 0, 200 );
				if ( mb_strlen( $lines[ $i - 1 ] ) > 200 ) {
					$match['context_before_truncated'] = true;
				}
			}
			if ( $i < $total - 1 ) {
				$match['context_after'] = mb_substr( $lines[ $i + 1 ], 0, 200 );
				if ( mb_strlen( $lines[ $i + 1 ] ) > 200 ) {
					$match['context_after_truncated'] = true;
				}
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
		return $this->edit_many( $type, $args, array( array( 'old_content' => $old_content, 'new_content' => $new_content, 'replace_all' => $replace_all, 'whole_word' => $whole_word ) ), false );
	}

	/**
	 * Several edits to one target (issue #1397): applied in order to the value in
	 * memory, each with edit()'s rules and against the result of the one before,
	 * all or nothing, then one save (one revision for a post).
	 *
	 * @param string $type  post | meta | option
	 * @param array  $args  Same shape as edit().
	 * @param array  $edits List of {old_content, new_content, replace_all?, whole_word?}.
	 * @return WP_REST_Response|WP_Error
	 */
	public function edit_batch( $type, $args, $edits ) {
		return $this->edit_many( $type, $args, $edits, true );
	}

	private function edit_many( $type, $args, $edits, $batch ) {
		$args    = self::resolve_field_alias( $type, $args );
		$current = $this->load( $type, $args );
		if ( is_wp_error( $current ) ) {
			return $current;
		}

		// str_replace inside a PHP-serialized string corrupts its s:N: length
		// prefixes. Post columns are always raw; meta/option are auto-unserialized
		// on read, so a serialized string here means it was stored escaped.
		if ( in_array( $type, array( 'meta', 'option' ), true ) && is_string( $current ) && is_serialized( $current ) ) {
			return new WP_Error( 'serialized_value', __( 'This value is PHP-serialized; a text replace would corrupt it. A wp-cli `option` write also refuses serialized input, and a raw `post meta update` would double-serialize it, so neither is a safe fallback. If this is page-builder layout data (Beaver Builder, Bricks, Breakdance), load that builder\'s skill and use its WPVibe save endpoint, which round-trips serialization safely. Otherwise write it through the owning plugin\'s own settings UI or REST route, which serializes correctly.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'not_supported', false, array( 'status' => 422 ) ) );
		}

		$updated = $current;
		$applied = array();
		foreach ( array_values( $edits ) as $i => $e ) {
			$step = $this->apply_edit( $type, $args, $updated, $e['old_content'], $e['new_content'], ! empty( $e['replace_all'] ), ! empty( $e['whole_word'] ) );
			if ( is_wp_error( $step ) ) {
				return $batch ? self::name_edit_error( $step, $i, count( $edits ) ) : $step;
			}
			$updated   = $step['value'];
			$applied[] = $step;
		}
		$replaced    = array_sum( array_column( $applied, 'replaced' ) );
		$escaped_for = $batch ? null : $applied[0]['escaped_for'];

		$stored = $this->store( $type, $args, $updated );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		if ( 'meta' === $type ) {
			$this->invalidate_builder_caches( (int) ( $args['post_id'] ?? 0 ), (string) ( $args['key'] ?? '' ) );
		} elseif ( 'post' === $type ) {
			self::invalidate_divi_caches( (int) ( $args['post_id'] ?? 0 ) );
		}

		// Filters (kses, content_save_pre, security plugins) can silently mutate
		// the write; report the bytes that actually landed, not what was sent.
		$readback = $this->load( $type, $args );
		$verbatim = is_string( $readback ) ? ( $readback === $updated ) : null;
		$stored_len = is_string( $readback ) ? strlen( $readback ) : strlen( $updated );

		$label = $this->target_label( $type, $args );

		WPVibe_Audit_Log::log_execution( array(
			'operation'      => 'content edit',
			'command'        => $label,
			'result_summary' => sprintf( 'edited; replaced %d; new length %d', $replaced, $stored_len ),
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

		$response = array(
			'target'   => $label,
			'status'   => 'edited',
			'message'  => __( 'Content updated successfully.', 'vibe-ai' ),
			'replaced' => $replaced,
			'bytes'    => $stored_len,
		);
		if ( $batch ) {
			$response['edits'] = array_map( static function ( $a ) {
				return array_filter( array(
					'replaced'    => $a['replaced'],
					'match_mode'  => $a['whitespace'] ? 'whitespace_lenient' : null,
					'escaped_for' => $a['escaped_for'],
				), static function ( $v ) { return null !== $v; } );
			}, $applied );
			$notes = array();
			if ( in_array( true, array_column( $applied, 'whitespace' ), true ) ) {
				$notes[] = __( 'Some edits matched only after allowing whitespace differences (match_mode in edits); verify the result reads as intended.', 'vibe-ai' );
			}
			if ( array_filter( array_column( $applied, 'escaped_for' ) ) ) {
				$notes[] = __( 'Some edits sat inside a JSON value or block-comment attributes and were escaped for it (escaped_for in edits); the stored value still parses.', 'vibe-ai' );
			}
			$response['message'] = trim( sprintf(
				/* translators: %d: number of edits */
				_n( 'Content updated successfully: %d edit in one save.', 'Content updated successfully: %d edits in one save.', count( $applied ), 'vibe-ai' ),
				count( $applied )
			) . ' ' . implode( ' ', $notes ) );
		} elseif ( $applied[0]['whitespace'] ) {
			$response['match_mode'] = 'whitespace_lenient';
			$response['message']   .= ' ' . __( 'old_content matched only after allowing whitespace differences; verify the result reads as intended.', 'vibe-ai' );
		}
		if ( null !== $escaped_for ) {
			$response['escaped_for'] = $escaped_for;
			$response['message']    .= ' ' . ( 'json' === $escaped_for
				? __( 'The text sits inside a JSON value, so it was JSON-escaped (quotes, backslashes, newlines) before writing; the stored JSON still parses and decodes to the text you sent.', 'vibe-ai' )
				: __( 'The text sits inside block-comment attributes, so it was escaped the way WordPress serializes block attributes (\\u0022 for quotes, \\u002d\\u002d for --) before writing; the block still parses and its attribute decodes to the text you sent.', 'vibe-ai' ) );
		}
		$revisions_off = 'option' === $type ? '' : WPVibe_Revision_Guard::revisions_off_note( array( (int) ( $args['post_id'] ?? 0 ) ) );
		if ( '' !== $revisions_off ) {
			$response['revisions_off'] = $revisions_off;
		}
		if ( false === $verbatim ) {
			$response['stored_verbatim'] = false;
			$response['message']         = __( 'Content updated, but the site modified the saved value (a filter such as kses or a security plugin altered it). Your copy of this value is now stale; re-read it with content/search before making further edits.', 'vibe-ai' );
		}
		return rest_ensure_response( $response );
	}

	/** A batch edit's error keeps its code and data, and says which edit failed and that nothing was saved. */
	private static function name_edit_error( $error, $index, $total ) {
		$data = $error->get_error_data();
		$data = is_array( $data ) ? $data : array();
		$data['edit_index'] = $index;
		return new WP_Error( $error->get_error_code(), sprintf(
			/* translators: 1: 1-based edit number 2: number of edits 3: 0-based index 4: the edit's own error message */
			__( 'Edit %1$d of %2$d (edits[%3$d]) failed, so nothing was saved: %4$s', 'vibe-ai' ),
			$index + 1,
			$total,
			$index,
			$error->get_error_message()
		), $data );
	}

	/**
	 * One edit against $current, with no save: the match rules, the escaped retry
	 * for JSON and block attributes, and the corruption check.
	 *
	 * @return array|WP_Error {value, replaced, escaped_for, whitespace}
	 */
	private function apply_edit( $type, $args, $current, $old_content, $new_content, $replace_all, $whole_word ) {
		$this->last_match_mode = null;
		$replaced = 0;
		$updated  = $this->compute_replacement( $current, $old_content, $new_content, (bool) $replace_all, (bool) $whole_word, $replaced );

		// Text inside a JSON value or block-comment attributes must be escaped
		// for that context. When the raw edit misses or would corrupt the value,
		// retry once with the escaped form; the same corruption check decides.
		$context     = $this->escape_context( $type, $args, $current );
		$escaped_for = null;
		if ( is_wp_error( $updated ) ) {
			if ( null !== $context && 'no_match' === $updated->get_error_code() ) {
				$retry = $this->escaped_retry( $context, $type, $args, $current, $old_content, $new_content, (bool) $replace_all, (bool) $whole_word, true );
				if ( null !== $retry ) {
					list( $updated, $replaced ) = $retry;
					$escaped_for = $context;
				}
			}
		}
		if ( is_wp_error( $updated ) ) {
			if ( is_string( $current ) && in_array( $updated->get_error_code(), array( 'no_match', 'multiple_matches' ), true ) ) {
				$this->augment_match_error( $updated, $current, $old_content );
			}
			return $updated;
		}

		$corrupted = $this->corruption_error( $type, $args, $current, $updated );
		if ( null !== $corrupted ) {
			$retry = null === $context || null !== $escaped_for ? null : $this->escaped_retry( $context, $type, $args, $current, $old_content, $new_content, (bool) $replace_all, (bool) $whole_word, false );
			if ( null === $retry ) {
				return $corrupted;
			}
			list( $updated, $replaced ) = $retry;
			$escaped_for = $context;
		}

		return array( 'value' => $updated, 'replaced' => $replaced, 'escaped_for' => $escaped_for, 'whitespace' => 'whitespace' === $this->last_match_mode );
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
			return new WP_Error( 'empty_pattern', __( 'Search pattern cannot be empty.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 400 ) ) );
		}

		$args    = self::resolve_field_alias( $type, $args );
		$current = $this->load( $type, $args );
		// A meta key the post doesn't have simply holds no matches, and says so (#1063).
		$meta_missing = is_wp_error( $current ) && 'meta_not_found' === $current->get_error_code();
		if ( $meta_missing ) {
			$current = '';
		}
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		// Arrays/objects are searched as their JSON text (read-only; edit still refuses them).
		$stored_text = is_string( $current );
		if ( ! $stored_text ) {
			$current = wp_json_encode( $current, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( false === $current ) {
				return new WP_Error( 'not_text', __( 'Stored value is not searchable as text (it could not be encoded as JSON).', 'vibe-ai' ), WPVibe_Error_Contract::data( 'not_supported', false, array( 'status' => 422 ) ) );
			}
		}

		$result = $this->find_matches( $current, $pattern, (bool) $case_sensitive, max( 1, (int) $max_results ) );
		$mode   = null;
		// JSON meta stored with \uXXXX escapes (Elementor's _elementor_data) holds the text escaped, so a
		// miss retries with the pattern escaped the same way, as edit() does (#1063). Case folding can't
		// reach escaped non-ASCII letters (\u017b vs \u017c), so a case-insensitive retry folds ASCII only.
		if ( ! $result['matches'] && $stored_text && 'json' === $this->escape_context( $type, $args, $current ) ) {
			$escaped = self::escape_for_context( 'json', self::desanitize( (string) $pattern ), $current );
			if ( null !== $escaped && $escaped !== self::desanitize( (string) $pattern ) ) {
				$retry = $this->find_matches( $current, $escaped, (bool) $case_sensitive, max( 1, (int) $max_results ) );
				if ( $retry['matches'] ) {
					$result = $retry;
					$mode   = 'json_escaped';
				}
			}
		}

		$response = array(
			'target'        => $this->target_label( $type, $args ),
			'pattern'       => $pattern,
			'matches'       => $result['matches'],
			'total_matches' => count( $result['matches'] ),
			'total_lines'   => $result['total_lines'],
			'truncated'     => $result['truncated'],
		);
		if ( $mode ) {
			// content/edit takes the plain text; it escapes old and new content to match on its own.
			$response['match_mode'] = $mode;
		}
		if ( $meta_missing ) {
			$response['meta_key_missing'] = true;
		}
		return rest_ensure_response( $response );
	}

	// ------------------------------------------------------------------
	// Match-failure diagnostics: give the model the real nearby bytes so the
	// retry is informed instead of blind.
	// ------------------------------------------------------------------

	/** Mutates the WP_Error, merging candidates and value shape into its data. */
	/** Set when a whitespace-lenient match was the one applied. */
	private $last_match_mode = null;

	/**
	 * Collapse whitespace runs on both sides and match once. Returns the
	 * updated value and count, or null when there is no unique hit (or the
	 * needle is too short to be safe outside exact matching).
	 */
	public static function whitespace_lenient_replace( $current, $old, $new, $replace_all ) {
		$trimmed = trim( $old );
		if ( mb_strlen( $trimmed ) < 12 || ! preg_match( '/\s/', $old ) ) {
			return null;
		}
		$parts   = preg_split( '/\s+/u', $trimmed );
		$pattern = '/' . implode( '\s+', array_map( function ( $p ) {
			return preg_quote( $p, '/' );
		}, $parts ) ) . '/u';
		$count = preg_match_all( $pattern, $current );
		if ( ! $count || ( ! $replace_all && 1 !== $count ) ) {
			return null;
		}
		$updated = preg_replace_callback( $pattern, function () use ( $new ) {
			return $new;
		}, $current, $replace_all ? -1 : 1, $replaced );
		if ( null === $updated ) {
			return null;
		}
		return array( 'value' => $updated, 'replaced' => (int) $replaced );
	}

	private function augment_match_error( $error, $current, $old_content ) {
		$data = $error->get_error_data();
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		$data['value_length']      = strlen( $current );
		$data['value_single_line'] = ( false === strpos( $current, "\n" ) );

		$old = self::desanitize( (string) $old_content );
		if ( 'no_match' === $error->get_error_code() ) {
			$candidates = $this->nearest_candidates( $current, $old );
			if ( $candidates ) {
				$data['candidates'] = $candidates;
			}
		} else {
			$locations = $this->match_excerpts( $current, $old, 5 );
			if ( $locations ) {
				$data['match_locations'] = $locations;
			}
		}

		$error->add_data( $data );
	}

	/**
	 * Excerpts around partial-anchor hits of old_content. Anchors are short
	 * head/tail slices, matched leniently; plain substring scans, never edit
	 * distance, so multi-MB values stay cheap.
	 *
	 * @return array Excerpt strings, deduplicated, at most 3.
	 */
	private function nearest_candidates( $current, $old ) {
		$anchors  = array();
		$old_len  = mb_strlen( $old );
		$trimmed  = trim( $old );
		if ( '' !== $trimmed ) {
			// Progressively shorter head/tail slices: the divergence from the
			// stored text can sit inside the first slice tried. The middle slice
			// covers stitched attempts whose head AND tail are both wrong.
			$anchors[] = mb_substr( $trimmed, 0, 30 );
			$anchors[] = mb_substr( $trimmed, 0, 16 );
			if ( $old_len > 60 ) {
				$anchors[] = mb_substr( $trimmed, -30 );
				$anchors[] = mb_substr( $trimmed, -16 );
				$anchors[] = mb_substr( $trimmed, (int) ( $old_len * 0.4 ), 24 );
			}
		}

		$out    = array();
		$ranges = array();
		foreach ( $anchors as $anchor ) {
			if ( mb_strlen( $anchor ) < 8 ) {
				continue;
			}
			$pattern = '/' . self::match_lenient_pattern( $anchor ) . '/u';
			if ( 1 !== preg_match( $pattern, $current, $m, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}
			$byte_pos = $m[0][1];
			$overlaps = false;
			foreach ( $ranges as $seen ) {
				if ( abs( $seen - $byte_pos ) < 160 ) {
					$overlaps = true;
					break;
				}
			}
			if ( $overlaps ) {
				continue;
			}
			$ranges[] = $byte_pos;
			$char_pos = mb_strlen( substr( $current, 0, $byte_pos ) );
			$out[]    = mb_substr( $current, max( 0, $char_pos - 40 ), 200 );
			if ( count( $out ) >= 3 ) {
				break;
			}
		}
		return $out;
	}

	/** Excerpts around every lenient match of old_content, capped. */
	private function match_excerpts( $current, $old, $cap ) {
		$pattern = '/' . self::match_lenient_pattern( $old ) . '/u';
		if ( false === preg_match_all( $pattern, $current, $m, PREG_OFFSET_CAPTURE ) ) {
			return array();
		}
		$out = array();
		foreach ( $m[0] as $hit ) {
			$char_pos = mb_strlen( substr( $current, 0, $hit[1] ) );
			$out[]    = mb_substr( $current, max( 0, $char_pos - 40 ), mb_strlen( $old ) + 80 );
			if ( count( $out ) >= $cap ) {
				break;
			}
		}
		return $out;
	}

	// ------------------------------------------------------------------
	// Internal: load / store / labels.
	// ------------------------------------------------------------------

	/**
	 * Map a short post field name (content/title/excerpt) to its column. A meta key is unslashed once, since
	 * update_post_meta() unslashes the key it writes: every check and read then sees that key (#1621), and store()
	 * slashes it back for the write.
	 */
	private static function resolve_field_alias( $type, $args ) {
		if ( 'post' === $type && isset( self::POST_FIELD_ALIASES[ (string) ( $args['field'] ?? '' ) ] ) ) {
			$args['field'] = self::POST_FIELD_ALIASES[ (string) $args['field'] ];
		}
		if ( 'meta' === $type && isset( $args['key'] ) ) {
			$args['key'] = wp_unslash( (string) $args['key'] );
		}
		return $args;
	}

	/** @return mixed|WP_Error Current stored value, or an error. */
	private function load( $type, $args ) {
		switch ( $type ) {
			case 'post':
				$post_id = (int) ( $args['post_id'] ?? 0 );
				$field   = (string) ( $args['field'] ?? '' );
				if ( ! in_array( $field, self::EDITABLE_POST_FIELDS, true ) ) {
					/* translators: %s: comma-separated field list */
					return new WP_Error( 'bad_field', sprintf( __( 'field must be one of: %s', 'vibe-ai' ), implode( ', ', self::EDITABLE_POST_FIELDS ) ), WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 400 ) ) );
				}
				if ( ! get_post( $post_id ) ) {
					return new WP_Error( 'not_found', __( 'Post not found.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'not_found', false, array( 'status' => 404 ) ) );
				}
				return (string) get_post_field( $field, $post_id, 'raw' );

			case 'meta':
				$post_id = (int) ( $args['post_id'] ?? 0 );
				$key     = (string) ( $args['key'] ?? '' );
				if ( '' === $key ) {
					return new WP_Error( 'bad_key', __( 'meta_key is required.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 400 ) ) );
				}
				if ( ! get_post( $post_id ) ) {
					return new WP_Error( 'not_found', __( 'Post not found.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'not_found', false, array( 'status' => 404 ) ) );
				}
				// edit_post is post-level; protected/registered meta keys carry their
				// own auth boundary (protected-meta rule + auth_callback) that direct
				// get_post_meta() bypasses. edit_post_meta maps both via map_meta_cap,
				// with no override for any role (WordPress.org review 11-17, #1624) except
				// ACF's own guard for its administrators (can_post_meta, #1751).
				if ( ! self::can_post_meta( 'edit_post_meta', $post_id, $key ) ) {
					return $this->meta_forbidden_error( $post_id, $key );
				}
				if ( ! metadata_exists( 'post', $post_id, $key ) ) {
					return new WP_Error( 'meta_not_found', __( 'Meta key not found on this post.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'not_found', false, array( 'status' => 404 ) ) );
				}
				return get_post_meta( $post_id, $key, true );

			case 'option':
				$name     = (string) ( $args['name'] ?? '' );
				if ( '' === $name ) {
					return new WP_Error( 'bad_option', __( 'option_name is required.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 400 ) ) );
				}
				$blocked = self::blocked_option_error( $name, false );
				if ( $blocked ) {
					return $blocked;
				}
				$sentinel = '__wpvibe_option_missing__';
				$value    = get_option( $name, $sentinel );
				if ( $sentinel === $value ) {
					return new WP_Error( 'option_not_found', __( 'Option not found.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'not_found', false, array( 'status' => 404 ) ) );
				}
				return WPVibe_CLI::mask_secrets( $name, $value ); // Search and edit see what option get shows (#1624).

			default:
				return new WP_Error( 'bad_target', __( 'target_type must be post, meta, or option.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'not_supported', false, array( 'status' => 400 ) ) );
		}
	}

	/**
	 * Delta-based block-corruption guard (NOT full coverage): refuse a
	 * post_content edit that worsens any of three health signals versus the
	 * pre-edit value. (1) more parsed blocks with null attrs JSON — delimiter
	 * intact, JSON broken; (2) more INTENDED `wp:` openers core's strict parser
	 * can no longer parse — catches uppercase `WP:`, no-space `<!--wp:`, space
	 * `wp :`, and dropped-brace/space delimiter damage that makes a block vanish
	 * from parse_blocks (so signal 1 never sees it); (3) a wider opener/closer
	 * imbalance — catches a deleted closer or a closer flipped to an opener,
	 * where following siblings silently re-parent. A legitimate whole-block
	 * removal drops a matched opener AND closer together, so every signal nets
	 * zero. Scope is block-attribute JSON + delimiter-PREFIX integrity, not full
	 * corruption coverage: it does NOT catch semantically-wrong-but-well-formed
	 * markup, an edit that repairs one block while breaking another, or a
	 * SYMMETRIC delimiter rename (e.g. replace_all `wp:`->`wordpress:` mangles
	 * every opener and closer equally, netting zero across all three signals).
	 */
	private function content_blocks_broken_by_edit( $current, $updated ) {
		if ( ! function_exists( 'parse_blocks' ) || ! is_string( $current ) || ! is_string( $updated ) ) {
			return false;
		}
		if ( false === stripos( $updated, '<!--' ) && false === stripos( $current, '<!--' ) ) {
			return false;
		}
		$c = $this->block_health( $current );
		$u = $this->block_health( $updated );
		return $u['broken_attrs'] > $c['broken_attrs']
			|| $u['unparsed_openers'] > $c['unparsed_openers']
			|| $u['imbalance'] > $c['imbalance'];
	}


	/** Three corruption signals for a content string; parse_blocks is core's real parser in production. */
	private function block_health( $content ) {
		$blocks = parse_blocks( $content );
		// Intended delimiters, counted loosely + case-insensitively: openers/voids
		// (no leading slash) vs closers (leading slash). \s* so a dropped space
		// still counts as an intended delimiter.
		$loose_openers = (int) preg_match_all( '/<!--\s*wp\s*:/i', (string) $content );
		$loose_closers = (int) preg_match_all( '/<!--\s*\/\s*wp\s*:/i', (string) $content );
		// Self-closing (void) blocks are openers with no closer, so exclude them
		// from the balance or a legit void insertion reads as an imbalance.
		$loose_voids   = (int) preg_match_all( '/<!--\s*wp\s*:[^>]*\/\s*-->/i', (string) $content );
		$parsed        = $this->count_parsed_blocks( $blocks );
		return array(
			'broken_attrs'    => $this->count_broken_block_attrs( $blocks ),
			// Openers core's strict parser failed to recognize (uppercase, no
			// space, space-before-colon, broken delimiter body).
			'unparsed_openers' => max( 0, $loose_openers - $parsed ),
			// Paired-opener vs closer balance; a deleted closer or closer→opener
			// flip widens it, a whole-block removal keeps it level.
			'imbalance'       => abs( ( $loose_openers - $loose_voids ) - $loose_closers ),
		);
	}


	/** Parsed blocks whose delimiter matched but whose attrs JSON is null (invalid), recursive. */
	private function count_broken_block_attrs( $blocks ) {
		$broken = 0;
		foreach ( (array) $blocks as $block ) {
			$block = (array) $block;
			if ( ! empty( $block['blockName'] ) && null === ( $block['attrs'] ?? null ) ) {
				$broken++;
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$broken += $this->count_broken_block_attrs( $block['innerBlocks'] );
			}
		}
		return $broken;
	}


	/** Recursive count of blocks with a resolved name — i.e. opener delimiters core actually parsed. */
	private function count_parsed_blocks( $blocks ) {
		$n = 0;
		foreach ( (array) $blocks as $block ) {
			$block = (array) $block;
			if ( ! empty( $block['blockName'] ) ) {
				$n++;
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$n += $this->count_parsed_blocks( $block['innerBlocks'] );
			}
		}
		return $n;
	}


	/** True when the value parsed as a JSON object/array before the edit but no longer does after. */
	private function json_broken_by_edit( $current, $updated ) {
		if ( ! is_string( $current ) || ! is_string( $updated ) ) {
			return false;
		}
		$trimmed = ltrim( $current );
		if ( '' === $trimmed || ( '[' !== $trimmed[0] && '{' !== $trimmed[0] ) ) {
			return false;
		}
		if ( null === json_decode( $current ) ) {
			return false;
		}
		return null === json_decode( $updated );
	}

	/** The json_corrupted / block_json_corrupted refusal for this edit, or null when the result is intact. */
	private function corruption_error( $type, $args, $current, $updated ) {
		// A replace that breaks a JSON value (builder layouts like _elementor_data)
		// would silently destroy the whole structure on save.
		if ( in_array( $type, array( 'meta', 'option' ), true ) && $this->json_broken_by_edit( $current, $updated ) ) {
			return new WP_Error(
				'json_corrupted',
				__( 'This value is JSON and the replacement would corrupt it (the result no longer parses), even after JSON-escaping new_content. Inside JSON, quotes and slashes must stay escaped (\" and \/): take old_content verbatim from content/search and replace only text inside a string value. No change was made.', 'vibe-ai' ),
				WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 422 ) )
			);
		}

		if ( 'post' === $type && 'post_content' === (string) ( $args['field'] ?? '' ) && $this->content_blocks_broken_by_edit( $current, $updated ) ) {
			return new WP_Error(
				'block_json_corrupted',
				__( 'This edit would damage the block markup: after the replacement a block\'s JSON attributes no longer parse, or the `<!-- wp:` opening-delimiter prefix of a block is malformed (a dropped brace/space, a bad prefix, or an unbalanced closer). Inside a block comment (<!-- wp:... -->) the attribute JSON and the delimiter prefix must stay intact and escaped exactly as stored; take old_content verbatim from content/search and mirror its escaping in new_content. This checks block-attribute JSON and delimiter-prefix integrity; it does not verify that otherwise well-formed markup is semantically correct. No change was made.', 'vibe-ai' ),
				WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 422 ) )
			);
		}
		return null;
	}

	/** 'json' for a JSON meta/option value, 'block' for block-markup post_content, else null. */
	private function escape_context( $type, $args, $current ) {
		if ( ! is_string( $current ) ) {
			return null;
		}
		if ( in_array( $type, array( 'meta', 'option' ), true ) ) {
			$trimmed = ltrim( $current );
			if ( '' !== $trimmed && ( '[' === $trimmed[0] || '{' === $trimmed[0] ) && null !== json_decode( $current ) ) {
				return 'json';
			}
			return null;
		}
		if ( 'post' === $type && 'post_content' === (string) ( $args['field'] ?? '' ) && preg_match( '/<!--\s+wp:/', $current ) ) {
			return 'block';
		}
		return null;
	}

	/**
	 * Escape text as the inside of a JSON string for the given context. JSON
	 * mirrors the stored value's slash/unicode style; block matches core's
	 * serialize_block_attributes(). Null when the text cannot be encoded.
	 */
	private static function escape_for_context( $context, $text, $current ) {
		if ( 'block' === $context ) {
			$enc = json_encode( (string) $text, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( false === $enc ) {
				return null;
			}
			return str_replace(
				array( '--', '<', '>', '&', '\\"' ),
				array( '\\u002d\\u002d', '\\u003c', '\\u003e', '\\u0026', '\\u0022' ),
				substr( $enc, 1, -1 )
			);
		}
		$flags = 0;
		if ( false === strpos( $current, '\\/' ) && false !== strpos( $current, '/' ) ) {
			$flags |= JSON_UNESCAPED_SLASHES;
		}
		if ( preg_match( '/[\x80-\xff]/', $current ) ) {
			$flags |= JSON_UNESCAPED_UNICODE;
		}
		$enc = json_encode( (string) $text, $flags );
		return false === $enc ? null : substr( $enc, 1, -1 );
	}

	/**
	 * One retry with new_content (and, after a miss, old_content) escaped for
	 * its context. Returns array( updated, replaced ) when the escaped edit
	 * applies and passes the same corruption check, else null.
	 */
	private function escaped_retry( $context, $type, $args, $current, $old_content, $new_content, $replace_all, $whole_word, $escape_old ) {
		$old     = self::desanitize( (string) $old_content );
		$new     = self::strip_trailing_whitespace( self::desanitize( (string) $new_content ) );
		$esc_new = self::escape_for_context( $context, $new, $current );
		$esc_old = $escape_old ? self::escape_for_context( $context, $old, $current ) : $old;
		if ( null === $esc_new || null === $esc_old || ( $esc_new === $new && $esc_old === $old ) ) {
			return null;
		}
		if ( $escape_old && $esc_old === $old ) {
			return null; // Escaping old changes nothing; the miss stands.
		}

		// In block markup, escaped text belongs only inside a block's opening
		// comment; if any match lies in HTML, the escapes would show on the page.
		if ( 'block' === $context ) {
			$hits = preg_match_all( '/' . self::match_lenient_pattern( $esc_old ) . '/u', $current, $m, PREG_OFFSET_CAPTURE );
			if ( ! $hits ) {
				return null;
			}
			preg_match_all( '/<!--\s+wp:.*?-->/s', $current, $d, PREG_OFFSET_CAPTURE );
			foreach ( $m[0] as $hit ) {
				$start  = $hit[1];
				$end    = $start + strlen( $hit[0] );
				$inside = false;
				foreach ( $d[0] as $delim ) {
					if ( $start >= $delim[1] && $end <= $delim[1] + strlen( $delim[0] ) ) {
						$inside = true;
						break;
					}
				}
				if ( ! $inside ) {
					return null;
				}
			}
		}

		$this->last_match_mode = null;
		$replaced = 0;
		$updated  = $this->compute_replacement( $current, $esc_old, $esc_new, $replace_all, $whole_word, $replaced );
		if ( is_wp_error( $updated ) || null !== $this->corruption_error( $type, $args, $current, $updated ) ) {
			return null;
		}
		return array( $updated, $replaced );
	}

	/**
	 * Elementor renders layout from _elementor_data but styles from cached CSS;
	 * a data edit without a cache purge can leave the page visually stale.
	 */
	private function invalidate_builder_caches( $post_id, $key ) {
		if ( ! $post_id || ! in_array( $key, array( '_elementor_data', '_elementor_page_settings' ), true ) ) {
			return;
		}
		delete_post_meta( $post_id, '_elementor_css' );
		delete_post_meta( $post_id, '_elementor_element_cache' );
		if ( class_exists( '\\Elementor\\Core\\Files\\CSS\\Post' ) ) {
			try {
				\Elementor\Core\Files\CSS\Post::create( $post_id )->delete();
			} catch ( \Throwable $e ) {
				// Cache purge is best-effort; the content write already succeeded.
			}
		}
	}

	/**
	 * Core REST writes to Divi-built post types skip et_save_post the same way content/edit does.
	 * Theme Builder layouts are not in REST (show_in_rest false), so only content/edit reaches them.
	 */
	public static function register_divi_rest_hooks() {
		if ( ! function_exists( 'et_builder_get_builder_post_types' ) ) {
			return;
		}
		try {
			$types = (array) et_builder_get_builder_post_types();
		} catch ( \Throwable $e ) {
			return;
		}
		foreach ( array_unique( array_filter( $types, 'is_string' ) ) as $type ) {
			add_action( "rest_after_insert_{$type}", array( __CLASS__, 'on_divi_rest_insert' ) );
		}
	}

	public static function on_divi_rest_insert( $post ) {
		if ( is_object( $post ) && isset( $post->ID ) ) {
			self::invalidate_divi_caches( (int) $post->ID );
		}
	}

	/**
	 * Divi stales its static CSS on core save_post, but its excerpt cache and the
	 * pages using an edited Theme Builder layout clear only on its own et_save_post.
	 */
	public static function invalidate_divi_caches( $post_id ) {
		if ( ! $post_id ) {
			return;
		}
		foreach ( array( 'et_divi_save_post', 'et_theme_builder_clear_wp_post_cache' ) as $purge ) {
			if ( ! function_exists( $purge ) ) {
				continue;
			}
			try {
				$purge( $post_id );
			} catch ( \Throwable $e ) {
				// Cache purge is best-effort; the content write already succeeded.
			}
		}
	}

	// Under each name core's map_meta_cap() applies; register_meta() with an object_subtype (Elementor's _elementor_data) uses `_for_`.
	private function meta_has_auth_callback( $post_id, $key ) {
		if ( self::hook_has_owner_auth( "auth_post_meta_{$key}" ) ) {
			return true;
		}
		$post_type = get_post_type( $post_id );
		return $post_type && ( self::hook_has_owner_auth( "auth_post_meta_{$key}_for_{$post_type}" ) || self::hook_has_owner_auth( "auth_post_{$post_type}_meta_{$key}" ) );
	}

	/**
	 * current_user_can() for a post meta capability (edit_post_meta, add_post_meta or delete_post_meta), with one
	 * exception for ACF (#1751). ACF 6.8.11 adds do_not_allow to these capabilities for every key that holds an ACF
	 * field's value, whoever the user, so meta writers outside ACF's own save (core's Custom Fields box, XML-RPC) can't
	 * set fields ACF doesn't show that user. For an account with ACF's own admin capability (acf_get_setting(
	 * 'capability'), manage_options by default) and unfiltered_html, which sees and edits every field group, WordPress
	 * is asked again with only ACF's filter unhooked for that one check, and it is hooked again after. Every other
	 * refusal still stands: edit rights on the post, an owner's auth filter, any other map_meta_cap or user_has_cap
	 * filter. Every other account gets ACF's answer.
	 *
	 * @param string $cap 'edit_post_meta', 'add_post_meta' or 'delete_post_meta'.
	 */
	public static function can_post_meta( $cap, $post_id, $key ) {
		$post_id = (int) $post_id;
		$key     = (string) $key;
		if ( current_user_can( $cap, $post_id, $key ) ) {
			return true;
		}
		if ( ! self::acf_guards_meta_key( $post_id, $key ) || ! self::acf_admin_can_write() || self::acf_field_saves_elsewhere( $post_id, $key ) ) {
			return false;
		}
		$priority = has_filter( 'map_meta_cap', 'acf_map_post_meta_caps' );
		if ( ! is_int( $priority ) ) {
			return false;
		}
		remove_filter( 'map_meta_cap', 'acf_map_post_meta_caps', $priority );
		try {
			return current_user_can( $cap, $post_id, $key );
		} finally {
			add_filter( 'map_meta_cap', 'acf_map_post_meta_caps', $priority, 4 );
		}
	}

	/**
	 * Whether the ACF field behind this key keeps its value somewhere besides this meta row: a taxonomy field with Save
	 * Terms or Load Terms reads and writes the post's terms, so a meta write would report success and change nothing.
	 */
	public static function acf_field_saves_elsewhere( $post_id, $key ) {
		if ( ! function_exists( 'acf_get_field' ) ) {
			return false;
		}
		$reference = (string) get_post_meta( (int) $post_id, '_' . $key, true );
		$field     = 0 === strpos( $reference, 'field_' ) ? acf_get_field( $reference ) : null;
		return is_array( $field ) && 'taxonomy' === ( $field['type'] ?? '' ) && ( ! empty( $field['save_terms'] ) || ! empty( $field['load_terms'] ) );
	}

	/** Whether ACF 6.8.11's map_meta_cap guard refuses this post meta key: an ACF field's, not protected, not registered. */
	public static function acf_guards_meta_key( $post_id, $key ) {
		if ( ! function_exists( 'acf_map_post_meta_caps' ) || ! function_exists( '_acf_is_field_meta_key' ) || false === has_filter( 'map_meta_cap', 'acf_map_post_meta_caps' ) ) {
			return false;
		}
		$post_type = (string) get_post_type( (int) $post_id );
		if ( is_protected_meta( (string) $key, 'post' ) || registered_meta_key_exists( 'post', (string) $key, $post_type ) || registered_meta_key_exists( 'post', (string) $key ) ) {
			return false;
		}
		return (bool) _acf_is_field_meta_key( (int) $post_id, (string) $key );
	}

	/** ACF's admin capability plus unfiltered_html: the accounts that may write an ACF field outside ACF's own save. */
	private static function acf_admin_can_write() {
		$capability = function_exists( 'acf_get_setting' ) ? (string) acf_get_setting( 'capability' ) : '';
		return current_user_can( '' !== $capability ? $capability : 'manage_options' ) && current_user_can( 'unfiltered_html' );
	}

	/** The refusal's note for an ACF field this account may not write outside ACF. */
	public static function acf_refusal_note() {
		return __( 'Advanced Custom Fields 6.8.11 and later (and Secure Custom Fields) refuse direct changes to their fields outside ACF\'s own save, for every role. Through WPVibe only an account with ACF\'s admin capability and unfiltered_html can change one (on a single site an Administrator; on multisite a Super Admin), and never a taxonomy field that saves to the post\'s terms. Otherwise ask the user to make the change in the post\'s editor in wp-admin, where ACF saves its own fields, repeater and flexible-content rows included. Do not write the field through the REST acf object (one row sent replaces a whole repeater) or fall back to raw SQL.', 'vibe-ai' );
	}

	/**
	 * Whether a meta auth hook carries a check the owning plugin supplied. When a plugin registers a key with no
	 * auth_callback, core's register_meta() hooks '__return_false' (protected key) or '__return_true' itself (Yoast SEO
	 * registers its _yoast_wpseo_* keys that way): that is core's default, not the owner's answer (#1624). Shared by
	 * content/edit and the CLI meta commands.
	 */
	public static function hook_has_owner_auth( $hook ) {
		if ( ! has_filter( $hook ) ) {
			return false;
		}
		global $wp_filter;
		$hooked   = isset( $wp_filter[ $hook ] ) ? $wp_filter[ $hook ] : null;
		$priority = is_object( $hooked ) && isset( $hooked->callbacks ) ? $hooked->callbacks : ( is_array( $hooked ) ? $hooked : null );
		if ( ! is_array( $priority ) ) {
			return true; // Hooked, but out of sight: count it as the owner's check.
		}
		foreach ( $priority as $callbacks ) {
			foreach ( (array) $callbacks as $callback ) {
				$function = is_array( $callback ) && array_key_exists( 'function', $callback ) ? $callback['function'] : $callback;
				if ( ! in_array( $function, array( '__return_false', '__return_true' ), true ) ) {
					return true;
				}
			}
		}
		return false;
	}

	private function meta_forbidden_error( $post_id, $key ) {
		if ( $this->meta_has_auth_callback( $post_id, $key ) ) {
			return new WP_Error(
				'meta_forbidden',
				__( 'The plugin that registered this meta key refused the edit for the connected account (its auth callback said no), and that answer stands even for Administrators. Write the value through that plugin\'s own path (an ability via discover_abilities, or its REST namespace); if this is page-builder data, check that the builder lets this account edit this post. Do not fall back to raw SQL.', 'vibe-ai' ),
				WPVibe_Error_Contract::data( 'meta_protected', false, array( 'status' => 403, 'protected' => true ) )
			);
		}
		if ( is_protected_meta( $key, 'post' ) ) {
			$fallback = current_user_can( 'manage_options' )
				? __( 'If that plugin has no such path, `post meta get <id> <key>` reads it and `post meta update <id> <key> <value> --force` writes the whole new value.', 'vibe-ai' )
				: __( 'If that plugin has no such path, this needs an Administrator connection (for `post meta update --force`): run remove_site for this site, then ask the user to run connect_site and approve it while logged in as an Administrator (connect_site alone keeps the same login).', 'vibe-ai' );
			return new WP_Error(
				'meta_forbidden',
				__( 'This meta key is protected (underscore-prefixed). content/edit and content/search reach a protected key only where the plugin that owns the key allows it: WordPress\'s edit_post_meta check, the same for every role, Administrators included. For Elementor layout data on Elementor older than 3.27, use elementor/save-page. Otherwise read and write it through the plugin that owns the key (an ability via discover_abilities, or its REST namespace).', 'vibe-ai' ) . ' ' . $fallback . ' ' . __( 'Do not fall back to raw SQL.', 'vibe-ai' ),
				WPVibe_Error_Contract::data( 'meta_protected', false, array( 'status' => 403, 'protected' => true ) )
			);
		}
		if ( self::acf_guards_meta_key( $post_id, $key ) && current_user_can( 'edit_post', (int) $post_id ) ) {
			return new WP_Error(
				'meta_forbidden',
				self::acf_refusal_note(),
				WPVibe_Error_Contract::data( 'capability_cpt_mapping', false, array( 'status' => 403, 'protected' => false ) )
			);
		}
		return new WP_Error(
			'meta_forbidden',
			__( 'The connected account is not allowed to edit this meta key. Accounts below Administrator can be blocked on post types that carry custom capabilities. Reconnect with an Administrator account for access.', 'vibe-ai' ),
			WPVibe_Error_Contract::data( 'capability_cpt_mapping', false, array( 'status' => 403, 'protected' => false ) )
		);
	}

	// Core folds the stored template into every update and refuses the edit when the theme no longer offers it; an empty value skips that and leaves the meta untouched.
	public static function keep_page_template( array $postarr ) {
		return $postarr + array( 'page_template' => '' );
	}

	/** @return true|WP_Error */
	private function store( $type, $args, $updated ) {
		switch ( $type ) {
			case 'post':
				$code_post = WPVibe_Code_Snippet::code_post_write_error( '', (int) $args['post_id'] );
				if ( $code_post ) {
					return $code_post;
				}
				$stripped = self::kses_strip_error( $args['field'], $updated );
				if ( $stripped ) {
					return $stripped;
				}
				// wp_update_post expects slashed data; it unslashes internally.
				$res = wp_update_post( self::keep_page_template( array(
					'ID'           => (int) $args['post_id'],
					$args['field'] => wp_slash( $updated ),
				) ), true );
				if ( is_wp_error( $res ) ) {
					return $res;
				}
				if ( 0 === $res ) {
					return new WP_Error( 'update_failed', __( 'Failed to update the post.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'wp_core', false, array( 'status' => 500 ) ) );
				}
				return true;

			case 'meta':
				$post_id = (int) $args['post_id'];
				$key     = (string) $args['key'];
				$code_post = WPVibe_Code_Snippet::code_post_write_error( '', $post_id );
				if ( $code_post ) {
					return $code_post;
				}
				// Same meta-level auth boundary as load(); edit_post alone is not it.
				if ( ! self::can_post_meta( 'edit_post_meta', $post_id, $key ) ) {
					return $this->meta_forbidden_error( $post_id, $key );
				}
				// update_metadata unslashes the key and the value; slash both so the write lands on the key checked above.
				$ok = update_post_meta( $post_id, wp_slash( $key ), wp_slash( $updated ) );
				if ( false === $ok && (string) get_post_meta( $post_id, $key, true ) !== (string) $updated ) {
					return new WP_Error( 'update_failed', __( 'Failed to update the meta value.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'wp_core', false, array( 'status' => 500 ) ) );
				}
				return true;

			case 'option':
				$blocked = self::blocked_option_error( (string) $args['name'], true );
				if ( $blocked ) {
					return $blocked;
				}
				// load() masked any secret in the value; an edit that still carries the mask would store it over the secret.
				if ( WPVibe_CLI::holds_secret_mask( $updated ) ) {
					return new WP_Error( 'secret_masked', WPVibe_CLI::secret_mask_write_refusal( (string) $args['name'] ), WPVibe_Error_Contract::data( 'security_gate', false, array( 'status' => 403 ) ) );
				}
				// content/edit has no approval flow; without this, a gated
				// builder option stored as JSON text is writable click-free.
				// This branch is only reached for STRING values (arrays are
				// refused earlier as not_text), so the JSON-text rewrite path
				// is the one that works — patch refuses non-array options.
				if ( WPVibe_CLI::option_name_not_printable_ascii( (string) $args['name'] ) ) {
					return new WP_Error(
						'bad_option',
						__( 'Option name must be printable ASCII with no spaces. WordPress option names are plain slugs; a padded or disguised name can resolve to a different (possibly protected) option than it appears to. Re-run with the exact option name.', 'vibe-ai' ),
						WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 400 ) )
					);
				}
				$gated_name = WPVibe_CLI::match_option_name( (string) $args['name'], WPVibe_CLI::GATED_OPTIONS );
				if ( null !== $gated_name ) {
					return new WP_Error(
						'builder_option_gated',
						sprintf(
							/* translators: %1$s: option key */
							__( 'The option \'%1$s\' holds a page builder\'s site-wide settings or global presets, and writing it needs the user\'s approval. This value is stored as JSON text, so rewrite the whole value with run_wp_cli `option update %1$s \'<new json text>\' --format=plaintext` (that path shows the user the exact change for approval before writing). If the option were instead stored as a structured array, you would change one key with `option patch update %1$s <key-path> <value>`.', 'vibe-ai' ),
							$gated_name
						),
						WPVibe_Error_Contract::data( 'approval_flow', false, array( 'status' => 403 ) )
					);
				}
				// Turning on Angie's Super Admin mode needs the user's approval (#1595), which this route has no flow for.
				if ( null !== WPVibe_CLI::match_option_name( (string) $args['name'], array( 'angie_super_admin_enabled' ) ) && ! in_array( (string) $updated, array( '', '0' ), true ) ) {
					return new WP_Error(
						'angie_super_admin_gated',
						__( 'Turning on Angie\'s Super Admin mode needs the user\'s approval: it lets Angie read wp-config.php and run PHP. Use run_wp_cli `option update angie_super_admin_enabled 1`, which asks the user first, and only if the user asked for Super Admin mode.', 'vibe-ai' ),
						WPVibe_Error_Contract::data( 'approval_flow', false, array( 'status' => 403 ) )
					);
				}
				// update_option does NOT unslash — store the value verbatim.
				$ok = update_option( (string) $args['name'], $updated );
				if ( false === $ok && (string) get_option( (string) $args['name'] ) !== (string) $updated ) {
					return new WP_Error( 'update_failed', __( 'Failed to update the option.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'wp_core', false, array( 'status' => 500 ) ) );
				}
				return true;

			default:
				return new WP_Error( 'bad_target', __( 'target_type must be post, meta, or option.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'not_supported', false, array( 'status' => 400 ) ) );
		}
	}

	/**
	 * Core hooks wp_filter_post_kses on content_save_pre and excerpt_save_pre for
	 * a user without unfiltered_html (kses_init_filters), which drops script,
	 * style and other elements with no undo. Refuse such a save before it
	 * happens (#1064); a site that unhooked the filter saves as before.
	 */
	private static function kses_strip_error( $field, $updated ) {
		$hooks = array( 'post_content' => 'content_save_pre', 'post_excerpt' => 'excerpt_save_pre' );
		if ( ! isset( $hooks[ $field ] ) || ! function_exists( 'wp_filter_post_kses' ) || false === has_filter( $hooks[ $field ], 'wp_filter_post_kses' ) ) {
			return null;
		}
		$kept = array_count_values( self::tag_names( wp_unslash( wp_filter_post_kses( wp_slash( $updated ) ) ) ) );
		$lost = array();
		foreach ( array_count_values( self::tag_names( $updated ) ) as $name => $count ) {
			if ( ( $kept[ $name ] ?? 0 ) < $count ) {
				$lost[] = '<' . $name . '>';
			}
		}
		if ( ! $lost ) {
			return null;
		}
		return new WP_Error( 'kses_would_strip', sprintf(
			/* translators: %s: element names, e.g. <script>, <style> */
			__( 'Nothing was saved: WordPress would remove %s from this post on save, because it filters post HTML for the connected WordPress user (a user without the unfiltered_html capability; on multisite only super admins have it, and DISALLOW_UNFILTERED_HTML takes it from everyone). Tell the user; a user whose HTML WordPress does not filter has to make this edit, or the post stays as it is.', 'vibe-ai' ),
			implode( ', ', $lost )
		), WPVibe_Error_Contract::data( 'capability_role', false, array( 'status' => 403 ) ) );
	}

	/** Lowercased names of the opening tags in $html. */
	private static function tag_names( $html ) {
		preg_match_all( '/<([a-zA-Z][a-zA-Z0-9-]*)[\s\/>]/', (string) $html, $m );
		return array_map( 'strtolower', $m[1] );
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

	/**
	 * Enforce WPVibe_CLI's option blocklist on the content/edit and content/search
	 * routes, which reach update_option()/get_option() directly and so bypassed it.
	 * The list is hard-blocked in the CLI (approval cannot lift it) and carries the
	 * auth keys and salts plus default_role and users_can_register, so without this
	 * the same keys were writable and readable here. Reads follow the CLI's split
	 * (#1683): only SECRET_OPTIONS are unreadable, and load() masks secret fields.
	 *
	 * @param string $name     Option name.
	 * @param bool   $is_write True for a write, false for a read.
	 * @return WP_Error|null
	 */
	private static function blocked_option_error( $name, $is_write ) {
		// match_option_name (not in_array) so " siteurl " / "SITEURL" cannot
		// slip the block: WP trims and resolves case-insensitively to the row.
		$blocked = $is_write
			? null !== WPVibe_CLI::match_option_name( $name, WPVibe_CLI::BLOCKED_OPTIONS )
			: WPVibe_CLI::is_secret_option( $name );
		if ( ! $blocked ) {
			return null;
		}
		return new WP_Error(
			'blocked_option',
			sprintf(
				/* translators: %s: option key */
				__( 'Option \'%s\' is blocked for security.', 'vibe-ai' ),
				$name
			) . ( WPVibe_Code_Snippet::is_storage_option( $name ) ? ' ' . WPVibe_Code_Snippet::write_refusal_text() : '' ),
			WPVibe_Error_Contract::data( 'security_gate', false, array( 'status' => 403 ) )
		);
	}
}
