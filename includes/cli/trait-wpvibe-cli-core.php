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
		$note = $this->core_update_filter_note();
		if ( empty( $available ) ) {
			$data = array( 'message' => __( 'WordPress is at the latest version.', 'vibe-ai' ) );
			if ( $note ) {
				$data['note'] = $note;
			}
			return $this->success_result( $data );
		}
		return $this->success_result( $available, (string) $note );
	}


	/**
	 * Approval-gated core update. Classification already re-verified the
	 * current/new versions against the approved snapshot (drift check), so this
	 * runs only what the user reviewed.
	 */
	private function handle_core_update( $positional, $flags, $confirm_write = false ) {
		$err = $this->core_update_flag_error( $flags );
		if ( $err ) {
			return $err;
		}

		// Preflight BEFORE any download: on FTP/SSH-credential filesystems the
		// upgrader would fetch the zip and then fail to write a single file.
		if ( ! function_exists( 'get_filesystem_method' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( 'direct' !== get_filesystem_method() ) {
			return $this->error_result( __( 'This site\'s filesystem needs FTP/SSH credentials that WordPress does not have in this context, so core files cannot be written. Update from wp-admin > Updates, where WordPress can prompt for credentials.', 'vibe-ai' ) );
		}

		if ( get_option( 'core_updater.lock' ) ) {
			return $this->error_result( __( 'Another update is currently in progress. You may need to run \'option delete core_updater.lock\' after verifying another update isn\'t actually running (that delete pauses for approval).', 'vibe-ai' ) );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		global $wp_version;
		$resolved = $this->resolve_core_update_offer( $flags );
		if ( ! $resolved['offer'] ) {
			if ( 'latest_minor' === $resolved['note'] ) {
				return $this->success_result( array( 'message' => __( 'WordPress is at the latest minor release.', 'vibe-ai' ) ) );
			}
			if ( 'version_unavailable' === $resolved['note'] ) {
				return $this->error_result( sprintf(
					/* translators: %s: requested version */
					__( 'Version %s is not offered by WordPress.org for this site, so it cannot be installed here. Run `core check-update` for the offered versions, or use wp-admin > Updates for anything else.', 'vibe-ai' ),
					$resolved['requested']
				) );
			}
			/* translators: %s: current WordPress version */
			$data = array( 'message' => sprintf( __( 'WordPress is up to date at version %s.', 'vibe-ai' ), $wp_version ) );
			$note = $this->core_update_filter_note();
			if ( $note ) {
				$data['note'] = $note;
			}
			return $this->success_result( $data );
		}

		$offer = $resolved['offer'];
		$old   = (string) $wp_version;

		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Core_Upgrader( $skin );
		$thrown   = null;
		$result   = null;
		try {
			$result = $upgrader->upgrade( $offer );
		} catch ( \Throwable $e ) {
			$thrown = $e;
		}

		// A dead update can leave .maintenance behind, taking the whole site
		// down until someone deletes it by hand; clean it up on every outcome.
		$maintenance_note = null;
		if ( file_exists( ABSPATH . '.maintenance' ) ) {
			@unlink( ABSPATH . '.maintenance' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$maintenance_note = __( 'A leftover .maintenance file was found after the update attempt and removed, so the site is not stuck in maintenance mode.', 'vibe-ai' );
		}

		$skin_messages = $skin->get_upgrade_messages();
		$log           = $skin_messages ? ' Upgrader log: ' . implode( ' / ', $skin_messages ) : '';
		$tail          = $maintenance_note ? ' ' . $maintenance_note : '';

		if ( $thrown ) {
			/* translators: %s: error message */
			return $this->error_result( sprintf( __( 'Core update threw a fatal error: %s', 'vibe-ai' ), $thrown->getMessage() ) . $log . $tail );
		}
		if ( is_wp_error( $result ) ) {
			if ( 'up_to_date' === $result->get_error_code() ) {
				/* translators: %s: current WordPress version */
				return $this->success_result( array( 'message' => sprintf( __( 'WordPress is up to date at version %s.', 'vibe-ai' ), $old ) ) );
			}
			return $this->error_result( $result->get_error_message() . $log . $tail );
		}
		if ( ! $result ) {
			return $this->error_result( __( 'Core update failed.', 'vibe-ai' ) . $log . $tail );
		}

		$new = is_string( $result ) ? $result : (string) $offer->current;
		WPVibe_Change_Tracker::mark( array(
			'summary'      => "WordPress core updated: {$old} -> {$new}",
			'action_label' => 'Updates',
			'admin_url'    => admin_url( 'update-core.php' ),
		) );

		$data = array(
			/* translators: 1: old version, 2: new version */
			'message'    => sprintf( __( 'Updated WordPress %1$s -> %2$s.', 'vibe-ai' ), $old, $new ),
			'next_steps' => __( 'Run `core update-db` to apply database migrations, then `core verify-checksums` to confirm file integrity.', 'vibe-ai' ),
		);
		if ( $maintenance_note ) {
			$data['note'] = $maintenance_note;
		}
		if ( is_multisite() ) {
			$data['multisite_note'] = __( 'This is a multisite install: core updates here are not covered by WPVibe testing on multisite. Visit wp-admin > Updates on the network admin and verify each site afterwards.', 'vibe-ai' );
		}
		return $this->success_result( $data );
	}


	private function handle_core_update_db( $positional, $flags ) {
		$reject = $this->reject_unknown_flags( 'core update-db', $flags, array(), array(
			'network' => __( 'Multisite network-wide DB upgrade is not emulated; run it per site from wp-admin.', 'vibe-ai' ),
		) );
		if ( $reject ) {
			return $reject;
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$before = (string) get_option( 'db_version' );
		wp_upgrade();
		$after = (string) get_option( 'db_version' );
		if ( $before === $after ) {
			/* translators: %s: database version */
			return $this->success_result( array( 'message' => sprintf( __( 'WordPress database is already at the latest version (%s).', 'vibe-ai' ), $after ) ) );
		}
		WPVibe_Change_Tracker::mark( array(
			'summary'      => "WordPress database upgraded: {$before} -> {$after}",
			'action_label' => 'Updates',
			'admin_url'    => admin_url( 'update-core.php' ),
		) );
		/* translators: 1: old db version, 2: new db version */
		return $this->success_result( array( 'message' => sprintf( __( 'WordPress database upgraded from %1$s to %2$s.', 'vibe-ai' ), $before, $after ) ) );
	}


	/**
	 * Flag validation shared by classify_destructive and the handler, so a run
	 * that will refuse can never burn an approval click first.
	 */
	private function core_update_flag_error( $flags ) {
		$reject = $this->reject_unknown_flags( 'core update', $flags, array( 'minor', 'version' ), array(
			'force'    => __( 'Reinstalls and downgrades are not emulated; use wp-admin > Updates.', 'vibe-ai' ),
			'locale'   => __( 'Locale switching is not emulated; use wp-admin > Updates.', 'vibe-ai' ),
			'network'  => __( 'Multisite network flags are not emulated; use wp-admin > Updates.', 'vibe-ai' ),
			'insecure' => __( 'Disabling release-signature checks is not emulated.', 'vibe-ai' ),
		) );
		if ( $reject ) {
			return $reject;
		}
		if ( isset( $flags['minor'] ) && isset( $flags['version'] ) ) {
			return $this->error_result( __( '--minor and --version are mutually exclusive; pass one or the other.', 'vibe-ai' ) );
		}
		if ( isset( $flags['version'] ) ) {
			if ( true === $flags['version'] || '' === trim( (string) $flags['version'] ) ) {
				return $this->error_result( __( '--version requires a value, e.g. --version=6.7.2.', 'vibe-ai' ) );
			}
			global $wp_version;
			$requested = trim( (string) $flags['version'] );
			if ( version_compare( $requested, (string) $wp_version, '<' ) ) {
				return $this->error_result( sprintf(
					/* translators: 1: current version, 2: requested version */
					__( 'WordPress is at %1$s; %2$s is older. Downgrading core is not supported through WPVibe; use wp-admin > Updates.', 'vibe-ai' ),
					$wp_version,
					$requested
				) );
			}
		}
		return null;
	}


	/**
	 * Resolve the update offer core update would install, the SAME way for the
	 * approval classifier and the handler (classify/handler parity rule).
	 * Returns array{offer: object|null, note: string, requested?: string}.
	 */
	private function resolve_core_update_offer( $flags ) {
		global $wp_version;
		if ( ! function_exists( 'get_core_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}
		wp_version_check();
		$offers = array();
		foreach ( (array) get_core_updates() as $u ) {
			if ( is_object( $u ) && isset( $u->response ) && 'latest' !== $u->response && ! empty( $u->current ) ) {
				$offers[] = $u;
			}
		}
		$requested = ( isset( $flags['version'] ) && true !== $flags['version'] ) ? trim( (string) $flags['version'] ) : '';
		if ( '' !== $requested ) {
			if ( version_compare( $requested, (string) $wp_version, '==' ) ) {
				return array( 'offer' => null, 'note' => 'up_to_date' );
			}
			foreach ( $offers as $u ) {
				if ( (string) $u->current === $requested ) {
					return array( 'offer' => $u, 'note' => '' );
				}
			}
			return array( 'offer' => null, 'note' => 'version_unavailable', 'requested' => $requested );
		}
		if ( isset( $flags['minor'] ) ) {
			$branch = implode( '.', array_slice( explode( '.', (string) $wp_version ), 0, 2 ) );
			foreach ( $offers as $u ) {
				$offer_branch = implode( '.', array_slice( explode( '.', (string) $u->current ), 0, 2 ) );
				if ( $offer_branch === $branch && version_compare( (string) $u->current, (string) $wp_version, '>' ) ) {
					return array( 'offer' => $u, 'note' => '' );
				}
			}
			return array( 'offer' => null, 'note' => 'latest_minor' );
		}
		$best = null;
		foreach ( $offers as $u ) {
			if ( version_compare( (string) $u->current, (string) $wp_version, '>' )
				&& ( ! $best || version_compare( (string) $u->current, (string) $best->current, '>' ) ) ) {
				$best = $u;
			}
		}
		return $best ? array( 'offer' => $best, 'note' => '' ) : array( 'offer' => null, 'note' => 'up_to_date' );
	}


	/**
	 * Hidden-updates honesty: security plugins (am-site-security et al.) filter
	 * the core update transient, so "no update available" may not reflect
	 * wordpress.org. Never blocks anything; only stops the emulator from
	 * laundering a filtered feed into a confident "you're current".
	 */
	private function core_update_filter_note() {
		global $wp_filter;
		foreach ( array( 'pre_site_transient_update_core', 'site_transient_update_core' ) as $hook ) {
			$hooked = isset( $wp_filter[ $hook ] ) ? $wp_filter[ $hook ] : null;
			$groups = ( is_object( $hooked ) && isset( $hooked->callbacks ) ) ? $hooked->callbacks : ( is_array( $hooked ) ? $hooked : array() );
			foreach ( (array) $groups as $callbacks ) {
				foreach ( (array) $callbacks as $cb ) {
					$file = $this->callback_source_file( isset( $cb['function'] ) ? $cb['function'] : null );
					if ( ! $file || ! defined( 'WP_PLUGIN_DIR' ) ) {
						continue;
					}
					// realpath both sides: Reflection resolves symlinks, and
					// symlinked plugin dirs (Composer, /tmp) are common.
					$root        = realpath( WP_PLUGIN_DIR );
					$plugin_root = wp_normalize_path( trailingslashit( false !== $root ? $root : WP_PLUGIN_DIR ) );
					$real        = realpath( $file );
					$file        = wp_normalize_path( false !== $real ? $real : $file );
					if ( 0 !== strpos( $file, $plugin_root ) ) {
						continue;
					}
					$rel = substr( $file, strlen( $plugin_root ) );
					$dir = strstr( $rel, '/', true );
					$dir = false === $dir ? $rel : $dir;
					return sprintf(
						/* translators: %s: plugin directory name */
						__( 'Note: another plugin (%s) filters WordPress core update data on this site, so "no update available" may not reflect wordpress.org. Verify on wp-admin > Updates or compare `core version` against wordpress.org.', 'vibe-ai' ),
						$dir
					);
				}
			}
		}
		return null;
	}


	private function callback_source_file( $function ) {
		try {
			if ( is_string( $function ) && function_exists( $function ) ) {
				return ( new ReflectionFunction( $function ) )->getFileName();
			}
			if ( $function instanceof Closure ) {
				return ( new ReflectionFunction( $function ) )->getFileName();
			}
			if ( is_array( $function ) && 2 === count( $function ) ) {
				return ( new ReflectionMethod( $function[0], $function[1] ) )->getFileName();
			}
			if ( is_object( $function ) && method_exists( $function, '__invoke' ) ) {
				return ( new ReflectionMethod( $function, '__invoke' ) )->getFileName();
			}
		} catch ( \Throwable $e ) {
			return null;
		}
		return null;
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
