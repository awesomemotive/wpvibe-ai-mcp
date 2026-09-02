<?php
/**
 * Runtime-fatal guards for written PHP (issue #78). php -l proves syntax and
 * nothing else; the two commonest fatals that pass it are redeclaring a
 * function or class that already exists and publishing a theme whose
 * functions.php fatals at load. The first is caught statically before the
 * write; the second by rendering the site after a publish and rolling back.
 */

defined( 'ABSPATH' ) || exit;

class WPVibe_PHP_Guard {

	/**
	 * Top-level function and class-like names a source declares, with the
	 * brace depth each sits at (0 = file scope). Methods, closures, `::class`
	 * and anonymous classes are not declarations. Namespaced files declare
	 * namespaced names, which never collide with globals, so they are skipped.
	 *
	 * @return array{functions: array<string,int>, classes: array<string,int>, guarded: string[]}
	 */
	public static function declared_symbols( $source ) {
		$out = array( 'functions' => array(), 'classes' => array(), 'guarded' => array() );
		if ( preg_match( '/\bnamespace\s+[A-Za-z_\\\\][A-Za-z0-9_\\\\]*\s*[;{]/', (string) $source ) ) {
			return $out;
		}
		try {
			// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
			$tokens = @token_get_all( (string) $source );
		} catch ( \Throwable $e ) {
			return $out;
		}
		if ( ! is_array( $tokens ) ) {
			return $out;
		}
		// Names inside function_exists()/class_exists() guards are conditional.
		if ( preg_match_all( '/\b(?:function_exists|class_exists|interface_exists|trait_exists)\s*\(\s*[\'"]\\\\?([A-Za-z_][A-Za-z0-9_]*)[\'"]/', (string) $source, $g ) ) {
			$out['guarded'] = array_map( 'strtolower', $g[1] );
		}

		$depth       = 0;
		$class_depth = null;
		$count       = count( $tokens );
		$next_name   = function ( $from ) use ( $tokens, $count ) {
			for ( $j = $from + 1; $j < $count; $j++ ) {
				$t = $tokens[ $j ];
				if ( is_array( $t ) && ( T_WHITESPACE === $t[0] || T_COMMENT === $t[0] || T_DOC_COMMENT === $t[0] ) ) {
					continue;
				}
				if ( '&' === $t || ( is_array( $t ) && defined( 'T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG' ) && T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG === $t[0] ) ) {
					continue;
				}
				return is_array( $t ) && T_STRING === $t[0] ? $t[1] : null;
			}
			return null;
		};
		$prev_meaningful = function ( $from ) use ( $tokens ) {
			for ( $j = $from - 1; $j >= 0; $j-- ) {
				$t = $tokens[ $j ];
				if ( is_array( $t ) && ( T_WHITESPACE === $t[0] || T_COMMENT === $t[0] || T_DOC_COMMENT === $t[0] ) ) {
					continue;
				}
				return $t;
			}
			return null;
		};

		for ( $i = 0; $i < $count; $i++ ) {
			$t = $tokens[ $i ];
			if ( '{' === $t || ( is_array( $t ) && in_array( $t[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) {
				$depth++;
				continue;
			}
			if ( '}' === $t ) {
				$depth--;
				if ( null !== $class_depth && $depth < $class_depth ) {
					$class_depth = null;
				}
				continue;
			}
			if ( ! is_array( $t ) ) {
				continue;
			}
			$class_like = array( T_CLASS, T_INTERFACE, T_TRAIT );
			if ( defined( 'T_ENUM' ) ) {
				$class_like[] = T_ENUM;
			}
			if ( in_array( $t[0], $class_like, true ) ) {
				$prev = $prev_meaningful( $i );
				if ( is_array( $prev ) && T_DOUBLE_COLON === $prev[0] ) {
					continue; // Foo::class
				}
				$anonymous = is_array( $prev ) && T_NEW === $prev[0];
				$name      = $anonymous ? null : $next_name( $i );
				if ( null === $class_depth ) {
					// An anonymous class body still holds methods, not functions.
					if ( null !== $name ) {
						$out['classes'][ $name ] = $depth;
					}
					$class_depth = $depth + 1;
				}
				continue;
			}
			if ( T_FUNCTION === $t[0] && null === $class_depth ) {
				$name = $next_name( $i );
				if ( null !== $name ) {
					$out['functions'][ $name ] = $depth;
				}
			}
		}
		return $out;
	}

	/**
	 * true, or a WP_Error naming the symbols that already exist in the running
	 * site and are not declared by the current version of the target file
	 * (rewriting a loaded functions.php keeps its own functions). Conditional
	 * declarations under a matching *_exists() guard are allowed.
	 */
	public static function check_redeclarations( $source, $target_path = '' ) {
		$declared = self::declared_symbols( $source );
		$own      = array( 'functions' => array(), 'classes' => array() );
		if ( '' !== $target_path && is_readable( $target_path ) ) {
			$current = file_get_contents( $target_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false !== $current ) {
				$own = self::declared_symbols( $current );
			}
		}
		$own_functions = array_map( 'strtolower', array_keys( $own['functions'] ) );
		$own_classes   = array_map( 'strtolower', array_keys( $own['classes'] ) );
		$conflicts     = array();
		foreach ( array_keys( $declared['functions'] ) as $name ) {
			$lc = strtolower( $name );
			if ( in_array( $lc, $declared['guarded'], true ) || in_array( $lc, $own_functions, true ) ) {
				continue;
			}
			if ( function_exists( $name ) ) {
				$conflicts[] = "function {$name}()";
			}
		}
		foreach ( array_keys( $declared['classes'] ) as $name ) {
			$lc = strtolower( $name );
			if ( in_array( $lc, $declared['guarded'], true ) || in_array( $lc, $own_classes, true ) ) {
				continue;
			}
			if ( class_exists( $name, false ) || interface_exists( $name, false ) || trait_exists( $name, false ) ) {
				$conflicts[] = "class {$name}";
			}
		}
		if ( empty( $conflicts ) ) {
			return true;
		}
		return new WP_Error(
			'php_redeclare',
			sprintf(
				/* translators: %s: comma-separated symbol list */
				__( 'Refused: this file would fatal the site on load because it declares %s, which already exist (in WordPress core, the active theme, or a plugin). Rename them, or wrap each in an if ( ! function_exists() ) / if ( ! class_exists() ) guard. Nothing was written.', 'vibe-ai' ),
				implode( ', ', $conflicts )
			),
			WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 422, 'symbols' => $conflicts ) )
		);
	}

	/** Pure: does a front-page fetch look like the site is fataling? */
	public static function render_looks_fatal( $status, $body ) {
		$status = (int) $status;
		if ( $status >= 500 && $status < 600 ) {
			return true;
		}
		$body = (string) $body;
		return (bool) preg_match( '/There has been a critical error on this website|<b>Fatal error<\/b>|PHP Fatal error:|Uncaught (?:Error|Exception)/i', substr( $body, 0, 20000 ) );
	}

	/**
	 * Fetch the front page after a publish. Returns true (healthy), a
	 * WP_Error (fatal seen), or null when the site could not be reached at
	 * all (loopback blocked), which is not evidence either way.
	 */
	public static function render_health_check() {
		if ( function_exists( 'opcache_get_status' ) ) {
			sleep( 2 ); // opcache.revalidate_freq masks a just-broken file for ~2s.
		}
		$url      = add_query_arg( 'wpvibe_health', wp_rand( 1000, 999999 ), home_url( '/' ) );
		$response = wp_remote_get( $url, array(
			'timeout'     => 20,
			'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
			'redirection' => 3,
			'headers'     => array( 'Cache-Control' => 'no-cache', 'X-WPVibe-Health' => '1' ),
		) );
		if ( is_wp_error( $response ) ) {
			return null;
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );
		if ( ! self::render_looks_fatal( $status, $body ) ) {
			return true;
		}
		$excerpt = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( substr( $body, 0, 4000 ) ) ) );
		if ( preg_match( '/(Fatal error:.{0,240}|Uncaught (?:Error|Exception).{0,240})/i', $excerpt, $m ) ) {
			$excerpt = $m[1];
		} else {
			$excerpt = substr( $excerpt, 0, 240 );
		}
		return new WP_Error( 'render_fatal', sprintf( 'HTTP %d: %s', $status, $excerpt ), array( 'status' => $status ) );
	}
}
