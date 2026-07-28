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
	 * Matching is byte-exact first, then quote-lenient (straight and curly
	 * quotes match interchangeably) — texturized prose stores curly quotes the
	 * model rarely reproduces byte-for-byte. new_content is written verbatim;
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
			return new WP_Error( 'not_text', __( 'Stored value is not editable as text (it is an array or object). Edit it with rest_api or wp-cli instead.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'not_supported', false, array( 'status' => 422 ) ) );
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
			$pattern = '/\b' . self::quote_lenient_pattern( $old ) . '\b/u';
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

		$pattern = '/' . self::quote_lenient_pattern( $old ) . '/u';
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
			return new WP_Error( 'no_match', __( 'old_content not found. Use content/search to locate the exact current text, then retry.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 422 ) ) );
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
	 * Matching uses the same desanitize + quote-lenient rules as the edit path,
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
		$regex  = '/' . self::quote_lenient_pattern( $needle ) . '/u' . ( $case_sensitive ? '' : 'i' );

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
		$current = $this->load( $type, $args );
		if ( is_wp_error( $current ) ) {
			return $current;
		}

		// str_replace inside a PHP-serialized string corrupts its s:N: length
		// prefixes. Post columns are always raw; meta/option are auto-unserialized
		// on read, so a serialized string here means it was stored escaped.
		if ( in_array( $type, array( 'meta', 'option' ), true ) && is_string( $current ) && is_serialized( $current ) ) {
			return new WP_Error( 'serialized_value', __( 'This value is PHP-serialized; a text replace would corrupt it. Edit it with rest_api or wp-cli instead.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'not_supported', false, array( 'status' => 422 ) ) );
		}

		$replaced = 0;
		$updated  = $this->compute_replacement( $current, $old_content, $new_content, (bool) $replace_all, (bool) $whole_word, $replaced );
		if ( is_wp_error( $updated ) ) {
			if ( is_string( $current ) && in_array( $updated->get_error_code(), array( 'no_match', 'multiple_matches' ), true ) ) {
				$this->augment_match_error( $updated, $current, $old_content );
			}
			return $updated;
		}

		$stored = $this->store( $type, $args, $updated );
		if ( is_wp_error( $stored ) ) {
			return $stored;
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
		if ( false === $verbatim ) {
			$response['stored_verbatim'] = false;
			$response['message']         = __( 'Content updated, but the site modified the saved value (a filter such as kses or a security plugin altered it). Your copy of this value is now stale; re-read it with content/search before making further edits.', 'vibe-ai' );
		}
		return rest_ensure_response( $response );
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

		$current = $this->load( $type, $args );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		if ( ! is_string( $current ) ) {
			return new WP_Error( 'not_text', __( 'Stored value is not searchable as text (it is an array or object).', 'vibe-ai' ), WPVibe_Error_Contract::data( 'not_supported', false, array( 'status' => 422 ) ) );
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
	// Match-failure diagnostics: give the model the real nearby bytes so the
	// retry is informed instead of blind.
	// ------------------------------------------------------------------

	/** Mutates the WP_Error, merging candidates and value shape into its data. */
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
			$pattern = '/' . self::quote_lenient_pattern( $anchor ) . '/u';
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
		$pattern = '/' . self::quote_lenient_pattern( $old ) . '/u';
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
				// get_post_meta() bypasses. edit_post_meta maps both via map_meta_cap.
				if ( ! current_user_can( 'edit_post_meta', $post_id, $key ) && ! $this->admin_meta_override( $post_id, $key ) ) {
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
				return $value;

			default:
				return new WP_Error( 'bad_target', __( 'target_type must be post, meta, or option.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'not_supported', false, array( 'status' => 400 ) ) );
		}
	}

	/**
	 * edit_post_meta maps through edit_post, so CPT capability mappings fail it
	 * even for admins. The override never applies to protected keys, keys
	 * registered with an auth_callback (register_post_meta registers those
	 * under the subtype filter, which core checks too), or post types whose
	 * edit_posts cap is an explicit do_not_allow.
	 */
	private function admin_meta_override( $post_id, $key ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		if ( is_protected_meta( $key, 'post' ) ) {
			return false;
		}
		if ( $this->meta_has_auth_callback( $post_id, $key ) ) {
			return false;
		}
		$pt_obj = get_post_type_object( get_post_type( $post_id ) );
		if ( ! $pt_obj || 'do_not_allow' === $pt_obj->cap->edit_posts ) {
			return false;
		}
		return true;
	}

	private function meta_has_auth_callback( $post_id, $key ) {
		if ( has_filter( "auth_post_meta_{$key}" ) ) {
			return true;
		}
		$post_type = get_post_type( $post_id );
		return $post_type && has_filter( "auth_post_{$post_type}_meta_{$key}" );
	}

	private function meta_forbidden_error( $post_id, $key ) {
		if ( is_protected_meta( $key, 'post' ) || $this->meta_has_auth_callback( $post_id, $key ) ) {
			return new WP_Error(
				'meta_forbidden',
				__( 'This meta key is protected (underscore-prefixed or registered with an auth callback), a boundary that applies even to Administrators. Read it with WP-CLI "post meta list", or write it through the approval-gated db query path.', 'vibe-ai' ),
				WPVibe_Error_Contract::data( 'meta_protected', false, array( 'status' => 403, 'protected' => true ) )
			);
		}
		return new WP_Error(
			'meta_forbidden',
			__( 'The connected account is not allowed to edit this meta key. Accounts below Administrator can be blocked on post types that carry custom capabilities. Reconnect with an Administrator account for access.', 'vibe-ai' ),
			WPVibe_Error_Contract::data( 'capability_cpt_mapping', false, array( 'status' => 403, 'protected' => false ) )
		);
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
					return new WP_Error( 'update_failed', __( 'Failed to update the post.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'wp_core', false, array( 'status' => 500 ) ) );
				}
				return true;

			case 'meta':
				$post_id = (int) $args['post_id'];
				$key     = (string) $args['key'];
				// Same meta-level auth boundary as load(); edit_post alone is not it.
				if ( ! current_user_can( 'edit_post_meta', $post_id, $key ) && ! $this->admin_meta_override( $post_id, $key ) ) {
					return $this->meta_forbidden_error( $post_id, $key );
				}
				// update_metadata unslashes; slash to preserve backslashes.
				$ok = update_post_meta( $post_id, $key, wp_slash( $updated ) );
				if ( false === $ok && (string) get_post_meta( $post_id, $key, true ) !== (string) $updated ) {
					return new WP_Error( 'update_failed', __( 'Failed to update the meta value.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'wp_core', false, array( 'status' => 500 ) ) );
				}
				return true;

			case 'option':
				$blocked = self::blocked_option_error( (string) $args['name'], true );
				if ( $blocked ) {
					return $blocked;
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
	 * the same keys were writable and readable here. Reads follow the CLI's split:
	 * READABLE_BLOCKED_OPTIONS stay readable, everything else does not.
	 *
	 * @param string $name     Option name.
	 * @param bool   $is_write True for a write, false for a read.
	 * @return WP_Error|null
	 */
	private static function blocked_option_error( $name, $is_write ) {
		if ( ! in_array( $name, WPVibe_CLI::BLOCKED_OPTIONS, true ) ) {
			return null;
		}
		if ( ! $is_write && in_array( $name, WPVibe_CLI::READABLE_BLOCKED_OPTIONS, true ) ) {
			return null;
		}
		return new WP_Error(
			'blocked_option',
			sprintf(
				/* translators: %s: option key */
				__( 'Option \'%s\' is blocked for security.', 'vibe-ai' ),
				$name
			),
			WPVibe_Error_Contract::data( 'security_gate', false, array( 'status' => 403 ) )
		);
	}
}
