<?php
/**
 * WP-CLI emulator: core version/checksums, config get, help, cli identity.
 *
 * Extracted from class-wpvibe-cli.php (mechanical split; no behavior change).
 */

defined( 'ABSPATH' ) || exit;

trait WPVibe_CLI_Core {


	private function handle_config_get( $positional, $flags ) {
		if ( empty( $positional[0] ) ) {
			return $this->error_result( __( 'Constant name required. Usage: config get <constant>', 'vibe-ai' ) );
		}
		$name = $positional[0];

		// Not a constant — special-cased via $wpdb (same value as `db prefix`).
		if ( 'table_prefix' === $name ) {
			global $wpdb;
			return array( 'exit_code' => 0, 'stdout' => $wpdb->prefix, 'stderr' => '' );
		}

		// Blocklist runs before the existence check so responses never leak
		// whether a secret is configured.
		$blocked_exact = array( 'DB_PASSWORD', 'DB_USER', 'DB_HOST' );
		if ( in_array( strtoupper( $name ), $blocked_exact, true ) || preg_match( '/KEY|SALT|SECRET|PASSWORD|TOKEN/i', $name ) ) {
			/* translators: %s: constant name */
			return $this->error_result( sprintf( __( 'Constant \'%s\' is blocked for security. Credentials, keys, salts, and secrets are never exposed to AI tools.', 'vibe-ai' ), $name ) );
		}

		if ( ! defined( $name ) ) {
			/* translators: %s: constant name */
			return $this->error_result( sprintf( __( 'The constant \'%s\' is not defined on this site.', 'vibe-ai' ), $name ) );
		}

		$value = constant( $name );
		return $this->success_result( array(
			'name'  => $name,
			'value' => $value,
			'type'  => strtolower( gettype( $value ) ),
		) );
	}


	private function handle_help( $positional, $flags ) {
		$filter   = implode( ' ', $positional );
		$commands = array();
		foreach ( self::ALLOWLIST as $key => $meta ) {
			if ( '' !== $filter && 0 !== strpos( $key, $filter ) ) {
				continue;
			}
			$entry = array(
				'command' => $key,
				'tier'    => $meta['tier'],
				'usage'   => self::USAGE[ $key ] ?? $key,
			);
			if ( ! empty( $meta['destructive'] ) ) {
				$entry['requires_approval'] = true;
			}
			$commands[] = $entry;
		}
		if ( '' !== $filter && empty( $commands ) ) {
			/* translators: %s: command name the user asked help for */
			return $this->error_result( sprintf( __( 'No supported command matches "%s". Run `help` with no arguments for the full catalog.', 'vibe-ai' ), $filter ) );
		}
		return $this->success_result( array(
			'emulator' => self::EMULATOR_NAME,
			'note'     => __( 'Native PHP dispatch with a security allowlist, not real WP-CLI. The commands below are the complete supported set; anything else is blocked. Write commands marked requires_approval pause for browser approval before executing.', 'vibe-ai' ),
			'commands' => $commands,
		) );
	}


	private function handle_cli_version( $positional, $flags ) {
		global $wp_version;
		return $this->success_result( array(
			'emulator'       => self::EMULATOR_NAME,
			'plugin_version' => defined( 'WPVIBE_VERSION' ) ? WPVIBE_VERSION : '',
			'note'           => __( 'Native PHP dispatch with a security allowlist, not real WP-CLI. Run `help` for the supported command catalog.', 'vibe-ai' ),
			'wp_version'     => $wp_version,
			'php_version'    => PHP_VERSION,
		) );
	}


	private function handle_core_version( $positional, $flags ) {
		global $wp_version;
		$data = array( 'version' => $wp_version );
		if ( isset( $flags['extra'] ) ) {
			global $wp_db_version;
			$data['db_version'] = $wp_db_version;
			$data['locale']     = get_locale();
			$data['php']        = PHP_VERSION;
		}
		return $this->success_result( $data );
	}


	private function handle_core_check_update( $positional, $flags ) {
		if ( ! function_exists( 'get_core_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}
		wp_version_check();
		$updates   = get_core_updates();
		$available = array();
		foreach ( (array) $updates as $update ) {
			if ( ! isset( $update->response ) || 'latest' === $update->response ) {
				continue;
			}
			$available[] = array(
				'version'     => $update->current ?? '',
				'update_type' => $update->response,
				'locale'      => $update->locale ?? '',
			);
		}
		if ( empty( $available ) ) {
			return $this->success_result( array( 'message' => __( 'WordPress is at the latest version.', 'vibe-ai' ) ) );
		}
		return $this->success_result( $available );
	}


	private function handle_core_verify_checksums( $positional, $flags ) {
		global $wp_version;
		$version      = ! empty( $flags['version'] ) ? $flags['version'] : $wp_version;
		$locale       = ! empty( $flags['locale'] ) ? $flags['locale'] : get_locale();
		$include_root = ! empty( $flags['include_root'] );
		$exclude      = ! empty( $flags['exclude'] ) ? array_filter( wp_parse_list( $flags['exclude'] ) ) : array();

		$checksums = $this->fetch_core_checksums( $version, $locale );
		if ( ! $checksums && 'en_US' !== $locale ) {
			// Localized packages often have no published checksums; en_US covers
			// everything except the translated readme/license files.
			$checksums = $this->fetch_core_checksums( $version, 'en_US' );
		}
		if ( ! $checksums ) {
			/* translators: 1: WordPress version, 2: locale */
			return $this->error_result( sprintf( __( 'Could not retrieve core checksums for WordPress %1$s (%2$s).', 'vibe-ai' ), $version, $locale ) );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$mismatched = array();
		$missing    = array();
		$checked    = 0;
		foreach ( $checksums as $file => $md5 ) {
			// wp-content ships in the zip but is expected to diverge (themes,
			// plugins, languages) — real WP-CLI skips it too.
			if ( 0 === strpos( $file, 'wp-content/' ) ) {
				continue;
			}
			if ( in_array( $file, $exclude, true ) ) {
				continue;
			}
			$path = ABSPATH . $file;
			$checked++;
			if ( ! file_exists( $path ) ) {
				$missing[] = $file;
				continue;
			}
			if ( md5_file( $path ) !== $md5 ) {
				$mismatched[] = $file;
			}
		}

		// Unknown files inside the core directories ("File should not exist" in
		// real WP-CLI) — the security-relevant half: malware more often adds
		// files than modifies them.
		$should_not_exist = array();
		$disk_files       = $this->collect_files_recursive( ABSPATH, function ( $rel ) use ( $include_root ) {
			return $this->core_checksum_in_scope( $rel, $include_root );
		} );
		if ( null !== $disk_files ) {
			$manifest = array();
			foreach ( array_keys( $checksums ) as $file ) {
				if ( $this->core_checksum_in_scope( $file, $include_root ) ) {
					$manifest[ $file ] = true;
				}
			}
			foreach ( $disk_files as $rel ) {
				if ( ! isset( $manifest[ $rel ] ) && ! in_array( $rel, $exclude, true ) ) {
					$should_not_exist[] = $rel;
				}
			}
			sort( $should_not_exist );
		}

		$verified = empty( $mismatched ) && empty( $missing );
		if ( ! $verified ) {
			$message = __( 'WordPress installation does NOT verify against checksums. Modified or missing core files can indicate a compromise — compare the listed files against a clean WordPress download before trusting this install.', 'vibe-ai' );
		} elseif ( ! empty( $should_not_exist ) ) {
			$message = __( 'Core files verify against checksums, but unknown files were found inside the core directories (should_not_exist). WordPress never adds extra files there — unexpected additions are a common malware pattern; identify or remove each one before trusting this install.', 'vibe-ai' );
		} else {
			$message = __( 'WordPress installation verifies against checksums.', 'vibe-ai' );
		}

		return $this->success_result( array(
			'verified'         => $verified,
			'wp_version'       => $version,
			'files_checked'    => $checked,
			'mismatched'       => $this->cap_file_list( $mismatched ),
			'missing'          => $this->cap_file_list( $missing ),
			'should_not_exist' => $this->cap_file_list( $should_not_exist ),
			'message'          => $message,
		) );
	}


	/** Real WP-CLI's soft-change files: only strict mode flags plugin readme diffs. */
	private function is_soft_change_file( $file ) {
		return in_array( strtolower( $file ), array( 'readme.txt', 'readme.md' ), true );
	}


	/**
	 * Mirrors real WP-CLI's core-checksum scope: wp-admin/, wp-includes/, and
	 * root wp-* files (never wp-config.php). --include-root widens to the whole
	 * root except .htaccess, .maintenance, wp-config.php, and wp-content/.
	 */
	private function core_checksum_in_scope( $rel, $include_root ) {
		if ( $include_root ) {
			return 1 !== preg_match( '/^(\.htaccess$|\.maintenance$|wp-config\.php$|wp-content\/)/', $rel );
		}
		return 0 === strpos( $rel, 'wp-admin/' )
			|| 0 === strpos( $rel, 'wp-includes/' )
			|| 1 === preg_match( '/^wp-(?!config\.php)([^\/]*)$/', $rel );
	}


	/**
	 * Recursive file listing relative to $root. $filter prunes directories too
	 * (bare "wp-admin" must pass for traversal to descend, same as real WP-CLI).
	 * Returns null on filesystem errors so callers can skip the check quietly.
	 */
	private function collect_files_recursive( $root, $filter ) {
		$root   = trailingslashit( $root );
		$filter = $filter ?: function () {
			return true;
		};
		$found  = array();
		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveCallbackFilterIterator(
					new RecursiveDirectoryIterator( $root, RecursiveDirectoryIterator::SKIP_DOTS ),
					function ( $current ) use ( $root, $filter ) {
						$rel = wp_normalize_path( substr( $current->getPathname(), strlen( $root ) ) );
						return (bool) call_user_func( $filter, $rel );
					}
				),
				RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ( $iterator as $file_info ) {
				if ( $file_info->isFile() ) {
					$found[] = wp_normalize_path( substr( $file_info->getPathname(), strlen( $root ) ) );
				}
			}
		} catch ( \Exception $e ) {
			return null;
		}
		return $found;
	}


	// Not core's get_core_checksums(): same endpoint, but core uses a 3s timeout
	// outside cron, which fails on exactly the slow shared hosts this runs on.
	private function fetch_core_checksums( $version, $locale ) {
		$response = wp_remote_get(
			'https://api.wordpress.org/core/checksums/1.0/?' . http_build_query( array( 'version' => $version, 'locale' => $locale ) ),
			array( 'timeout' => 15 )
		);
		if ( is_wp_error( $response ) ) {
			return null;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return ( is_array( $body ) && ! empty( $body['checksums'] ) && is_array( $body['checksums'] ) ) ? $body['checksums'] : null;
	}


	/** Bound checksum failure lists so a fully-compromised install can't blow up the response. */
	private function cap_file_list( $files, $limit = 50 ) {
		if ( count( $files ) <= $limit ) {
			return $files;
		}
		$capped   = array_slice( $files, 0, $limit );
		$capped[] = sprintf( '... and %d more', count( $files ) - $limit );
		return $capped;
	}

}
