<?php
/**
 * WP-CLI emulator: tokenization and command resolution.
 *
 * Extracted from class-wpvibe-cli.php (mechanical split; no behavior change).
 */

defined( 'ABSPATH' ) || exit;

trait WPVibe_CLI_Parse {

	/**
	 * Split tokens after the command key into positional args and flags.
	 * Hyphenated flag names normalize to underscored (per-page → per_page).
	 * Shared by dispatch() and classify_destructive() — the approval preview
	 * must see exactly the args the handler will receive.
	 */
	private function split_tokens( $tokens, $key_length ) {
		$positional = array();
		$flags      = array();
		$skip       = 0;
		foreach ( $tokens as $token ) {
			if ( strpos( $token, '--' ) === 0 ) {
				$stripped = substr( $token, 2 );
				if ( strpos( $stripped, '=' ) !== false ) {
					list( $k, $v ) = explode( '=', $stripped, 2 );
					$flags[ str_replace( '-', '_', $k ) ] = $v;
				} else {
					$flags[ str_replace( '-', '_', $stripped ) ] = true;
				}
			} else {
				if ( $skip < $key_length ) {
					$skip++;
					continue;
				}
				$positional[] = $token;
			}
		}
		return array( $positional, $flags );
	}


	// ------------------------------------------------------------------
	// Parsing & Validation
	// ------------------------------------------------------------------

	/**
	 * Scan for shell metacharacters OUTSIDE quoted spans. Quoted argument
	 * values are data handed to native PHP handlers (no shell ever runs), so a
	 * pipe inside an SEO title or a semicolon inside serialized meta is
	 * legitimate; the same characters in command STRUCTURE still get blocked.
	 * The quote state machine MUST mirror tokenize() exactly: a string the
	 * gate reads as quoted but the tokenizer reads as unquoted (or vice
	 * versa) would be a gate/parser mismatch an attacker could aim at.
	 *
	 * @return string|null The blocked character/sequence, or null when clean.
	 */
	private function find_unquoted_shell_char( $input, $skip_angle_brackets ) {
		$in_quote   = false;
		$quote_char = '';
		$len        = strlen( $input );
		for ( $i = 0; $i < $len; $i++ ) {
			$char = $input[ $i ];
			if ( $in_quote ) {
				if ( $char === $quote_char ) {
					$in_quote = false;
				}
				continue;
			}
			if ( '"' === $char || "'" === $char ) {
				$in_quote   = true;
				$quote_char = $char;
				continue;
			}
			if ( ';' === $char || '`' === $char || '|' === $char || "\n" === $char || "\r" === $char ) {
				return $char;
			}
			if ( '&' === $char && $i + 1 < $len && '&' === $input[ $i + 1 ] ) {
				return '&&';
			}
			if ( '$' === $char && $i + 1 < $len && '(' === $input[ $i + 1 ] ) {
				return '$(';
			}
			if ( ( '<' === $char || '>' === $char ) && ! $skip_angle_brackets ) {
				return $char;
			}
		}
		return null;
	}


	private function tokenize( $input ) {
		$tokens   = array();
		$current  = '';
		$in_quote = false;
		$quote_char = '';
		$len = strlen( $input );

		for ( $i = 0; $i < $len; $i++ ) {
			$char = $input[ $i ];
			if ( $in_quote ) {
				if ( $char === $quote_char ) {
					$in_quote = false;
				} else {
					$current .= $char;
				}
			} elseif ( $char === '"' || $char === "'" ) {
				$in_quote   = true;
				$quote_char = $char;
			} elseif ( $char === ' ' || $char === "\t" ) {
				if ( '' !== $current ) {
					$tokens[] = $current;
					$current  = '';
				}
			} else {
				$current .= $char;
			}
		}
		if ( '' !== $current ) {
			$tokens[] = $current;
		}
		return $tokens;
	}


	private function get_positional( $tokens ) {
		$positional = array();
		foreach ( $tokens as $token ) {
			if ( strpos( $token, '-' ) !== 0 ) {
				$positional[] = $token;
			}
		}
		return $positional;
	}


	private function resolve_command( $tokens ) {
		$positional = $this->get_positional( $tokens );

		for ( $len = min( 3, count( $positional ) ); $len >= 1; $len-- ) {
			$key = implode( ' ', array_slice( $positional, 0, $len ) );
			if ( isset( self::ALLOWLIST[ $key ] ) ) {
				return array( 'meta' => self::ALLOWLIST[ $key ], 'key_length' => $len );
			}
		}

		$base    = $positional[0] ?? '';
		if ( in_array( $base, array( 'eval', 'eval-file' ), true ) ) {
			/* translators: %s: command name */
			return new WP_Error( 'command_blocked', sprintf( __( '"%s" is blocked for security: this emulator never executes arbitrary PHP, so do not retry it in another form. To add PHP that should run on the site (hooks, filters, small features), use the code_snippet tool instead; it creates a WPCode snippet the user reviews and approves. For one-off reads or data changes, use the supported commands listed by `help`.', 'vibe-ai' ), $base ), WPVibe_Error_Contract::data( 'not_supported', false, array( 'status' => 403 ) ) );
		}
		$blocked = array( 'shell', 'core', 'config', 'package', 'server', 'site' );
		if ( in_array( $base, $blocked, true ) ) {
			/* translators: 1: command name, 2: the same command name */
			return new WP_Error( 'command_blocked', sprintf( __( '"%1$s" commands are blocked for security. Individually supported subcommands (if any) are listed by `help %2$s`; run `help` for the full catalog.', 'vibe-ai' ), $base, $base ), WPVibe_Error_Contract::data( 'not_supported', false, array( 'status' => 403 ) ) );
		}

		/* translators: %s: command name */
		return new WP_Error( 'command_not_allowed', sprintf( __( 'Command "%s" is not in the allowlist. Run `help` for the full supported-command catalog — the capability may exist under different syntax.', 'vibe-ai' ), implode( ' ', array_slice( $positional, 0, 2 ) ) ), WPVibe_Error_Contract::data( 'not_supported', false, array( 'status' => 403 ) ) );
	}


	/**
	 * Drop WP-CLI global flags that the emulator never supports (site selection,
	 * remote shells, etc.). $keep_flags is a per-command exception list: only
	 * the exact blocked flag names in that list survive (e.g. cache purge keeps
	 * --url / --url= as a surgical-purge target, not as WP-CLI --url site context).
	 *
	 * @param array $tokens     Tokenized command.
	 * @param array $keep_flags Blocked-flag names to retain (e.g. array( '--url' )).
	 * @return array
	 */
	private function strip_blocked_flags( $tokens, $keep_flags = array() ) {
		$cleaned = array();
		foreach ( $tokens as $token ) {
			$blocked = false;
			foreach ( self::BLOCKED_FLAGS as $flag ) {
				if ( $token === $flag || strpos( $token, $flag . '=' ) === 0 ) {
					if ( ! in_array( $flag, $keep_flags, true ) ) {
						$blocked = true;
					}
					break;
				}
			}
			if ( ! $blocked ) {
				$cleaned[] = $token;
			}
		}
		return $cleaned;
	}

}
