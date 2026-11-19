<?php
/**
 * Deferred out-of-band self-update for the WPVibe plugin (issue #92).
 *
 * `plugin update vibe-ai` cannot replace this plugin's files inline: the
 * process doing the swap is also the transport serving the request, and our
 * own code keeps running after the swap (CLI result, REST serialization,
 * audit log), so anything dying in that tail is an opaque 500 with no way to
 * know whether the update landed. The CLI handler only writes a state
 * option and hands the upgrade off: the same process runs it after releasing
 * the connection (fastcgi_finish_request or its LiteSpeed twin), or the site
 * posts a one-time token to itself. WP-Cron is never used (it does not fire
 * on some hosts); the legacy hook only serves events an older version left
 * queued. The outcome lands in the state option, which the Worker polls.
 *
 * It also owns the one shared read/write helper for the auto_update_plugins
 * SITE option, so the CLI verb and the white-label enrollment cannot drift
 * (multisite stores it as a network option; get_option misses it there).
 */

defined( 'ABSPATH' ) || exit;

class WPVibe_Self_Update {

	use WPVibe_Request_Detach;

	const OPTION    = 'wpvibe_self_update_state';
	/** Legacy (1.16.0-1.16.3) cron hook; only events those versions queued still arrive here. */
	const CRON_HOOK = 'wpvibe_self_update';

	/** Seconds a loopback token stays redeemable. */
	const TOKEN_TTL = 900;

	/** Seconds after which a scheduled/running state is treated as dead. */
	const STALE_AFTER = 600;

	private static $instance = null;

	/** Set while this process holds the auto_updater lock; what the shutdown recovery needs. */
	private $run_guard = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( self::CRON_HOOK, array( $this, 'run_self_update_cron' ) );
	}

	/** Core's automatic updater reads the SITE option; on single site this aliases get_option. */
	public static function auto_update_list() {
		return array_values( array_filter( (array) get_site_option( 'auto_update_plugins', array() ), 'is_string' ) );
	}

	public static function set_auto_update_list( $list ) {
		return update_site_option( 'auto_update_plugins', array_values( (array) $list ) );
	}

	public function register_routes() {
		// Registered here, not in class-wpvibe-rest.php's authed block: /health is
		// the loopback probe (must answer without auth), and /run's auth is the
		// one-time token, not an application password.
		register_rest_route( 'wpvibe/v1', '/self-update/health', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_ping_endpoint' ),
			// Public by design: the site's own loopback probe, answered with {"ok":true} and nothing else.
			'permission_callback' => '__return_true',
		) );
		register_rest_route( 'wpvibe/v1', '/self-update/run', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_run_endpoint' ),
			'permission_callback' => array( $this, 'can_run' ),
		) );
	}

	/**
	 * /self-update/run is the site calling itself, so its credential is the single-use token schedule() stored, checked
	 * here before the handler runs (WordPress.org review 11-17, #1624). This only verifies: the handler checks again and
	 * burns the token, since it is also called directly with an array.
	 */
	public function can_run( $request ) {
		return $this->token_valid( (string) $request->get_param( 'token' ) )
			? true
			: new WP_Error( 'wpvibe_self_update_forbidden', __( 'Forbidden.', 'vibe-ai' ), array( 'status' => 403 ) );
	}

	private function token_valid( $token ) {
		$state = get_option( self::OPTION, array() );
		return is_array( $state )
			&& 'scheduled' === ( $state['status'] ?? '' )
			&& 'loopback' === ( $state['method'] ?? '' )
			&& ! empty( $state['token_hash'] )
			&& '' !== $token
			&& hash_equals( (string) $state['token_hash'], hash( 'sha256', $token ) )
			&& ( time() - (int) ( $state['scheduled_at'] ?? 0 ) ) <= self::TOKEN_TTL;
	}

	public function handle_ping_endpoint() {
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * Schedule the self-update job. Returns the written state array, or a
	 * WP_Error whose message is safe to hand the AI verbatim.
	 *
	 * @param string $from_version   Currently installed version.
	 * @param string $to_version     Version the update offer carries.
	 * @param string $expect_version Optional --expect-version assert, re-checked at execution.
	 * @return array|WP_Error
	 */
	public function schedule_self_update( $from_version, $to_version, $expect_version = '' ) {
		if ( ! function_exists( 'get_filesystem_method' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( 'direct' !== get_filesystem_method() ) {
			return new WP_Error(
				'wpvibe_fs_credentials',
				__( 'This site\'s filesystem needs FTP/SSH credentials that WordPress does not have in this context, so plugin files cannot be written. Update WPVibe from the Plugins screen in wp-admin, where WordPress can prompt for credentials.', 'vibe-ai' )
			);
		}

		$state = get_option( self::OPTION, array() );
		if ( is_array( $state ) && in_array( $state['status'] ?? '', array( 'scheduled', 'running' ), true ) ) {
			$since = (int) ( $state['scheduled_at'] ?? $state['started_at'] ?? 0 );
			if ( $since > 0 && ( time() - $since ) < self::STALE_AFTER ) {
				return new WP_Error(
					'wpvibe_self_update_in_progress',
					sprintf(
						/* translators: 1: scheduled|running, 2: seconds since it was scheduled */
						__( 'A WPVibe self-update is already in progress (%1$s %2$d seconds ago). Check status with `option get wpvibe_self_update_state`.', 'vibe-ai' ),
						( $state['status'] ?? 'scheduled' ),
						time() - $since
					)
				);
			}
		}

		$new = array(
			'status'       => 'scheduled',
			'from_version' => (string) $from_version,
			'to_version'   => (string) $to_version,
			'scheduled_at' => time(),
		);
		if ( '' !== (string) $expect_version ) {
			$new['expect_version'] = (string) $expect_version;
		}

		if ( null !== $this->finish_request_function() ) {
			$new['method'] = 'request';
			update_option( self::OPTION, $new, false );
			add_filter( 'rest_pre_serve_request', function ( $served, $result ) {
				if ( $served ) {
					return $served;
				}
				$this->release_response( $result );
				if ( function_exists( 'set_time_limit' ) ) {
					@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				}
				$state = get_option( self::OPTION, array() );
				if ( is_array( $state ) && 'scheduled' === ( $state['status'] ?? '' ) && 'request' === ( $state['method'] ?? '' ) ) {
					$this->begin_running( $state );
					$this->run_self_update( $state );
				}
				return true;
			}, 0, 2 );
			return $new;
		}

		if ( ! $this->probe_loopback() ) {
			return new WP_Error(
				'wpvibe_self_update_unreachable',
				__( 'WPVibe cannot update itself on this site right now: PHP cannot release the connection early here and the site cannot reach its own REST API for a loopback run. Update it from the Plugins screen in wp-admin, or run `plugin auto-updates enable vibe-ai` so WordPress keeps it current automatically.', 'vibe-ai' )
			);
		}

		$raw               = bin2hex( random_bytes( 32 ) );
		$new['method']     = 'loopback';
		$new['token_hash'] = hash( 'sha256', $raw );
		update_option( self::OPTION, $new, false );
		$this->post_loopback( 'wpvibe/v1/self-update/run', array( 'token' => $raw ) );
		return $new;
	}

	/** Legacy delivery of an event a 1.16.0-1.16.3 install queued: fresh ones still run, stale ones expire. */
	public function run_self_update_cron() {
		$state = get_option( self::OPTION, array() );
		if ( ! is_array( $state ) || 'scheduled' !== ( $state['status'] ?? '' ) ) {
			return;
		}
		if ( ( time() - (int) ( $state['scheduled_at'] ?? 0 ) ) >= self::STALE_AFTER ) {
			$this->finish_failed( (string) ( $state['from_version'] ?? '' ), (string) ( $state['to_version'] ?? '' ), __( 'The update was queued for WP-Cron by an earlier plugin version and expired before it ran; nothing changed. Run `plugin update vibe-ai` again.', 'vibe-ai' ), false );
			return;
		}
		$this->begin_running( $state );
		$this->run_self_update( $state );
	}

	/**
	 * One-time-token entry point for DISABLE_WP_CRON sites. Wrong, expired, or
	 * replayed tokens get an information-free 403.
	 */
	public function handle_run_endpoint( $request ) {
		$token = '';
		if ( is_object( $request ) && method_exists( $request, 'get_param' ) ) {
			$token = (string) $request->get_param( 'token' );
		} elseif ( is_array( $request ) && isset( $request['token'] ) ) {
			$token = (string) $request['token'];
		}

		if ( ! $this->token_valid( $token ) ) {
			return new WP_Error( 'wpvibe_self_update_forbidden', __( 'Forbidden.', 'vibe-ai' ), array( 'status' => 403 ) );
		}
		$state = get_option( self::OPTION, array() );

		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$this->begin_running( $state );
		$this->run_self_update( $state );

		$response = rest_ensure_response( array( 'status' => 'accepted' ) );
		if ( is_object( $response ) && method_exists( $response, 'set_status' ) ) {
			$response->set_status( 202 );
		}
		return $response;
	}

	/** scheduled -> running. Dropping token_hash here burns the token (single use). */
	private function begin_running( $state ) {
		update_option( self::OPTION, array(
			'status'       => 'running',
			'from_version' => (string) ( $state['from_version'] ?? '' ),
			'to_version'   => (string) ( $state['to_version'] ?? '' ),
			'started_at'   => time(),
		), false );
	}

	/**
	 * The updater, shared by the cron callback and the token endpoint. Runs in
	 * a throwaway process: a fatal in the tail costs nothing, but catchable
	 * failures must still land in the state option.
	 */
	public function run_self_update( $state ) {
		$from   = (string) ( $state['from_version'] ?? ( defined( 'WPVIBE_VERSION' ) ? WPVIBE_VERSION : '' ) );
		$to     = (string) ( $state['to_version'] ?? '' );
		$expect = (string) ( $state['expect_version'] ?? '' );

		$was_active         = false;
		$was_network        = false;
		try {
			if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
				return $this->finish_failed( $from, $to, __( 'File modifications are disabled (DISALLOW_FILE_MODS).', 'vibe-ai' ), false );
			}
			if ( ! function_exists( 'get_filesystem_method' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			require_once ABSPATH . 'wp-admin/includes/misc.php';
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			if ( 'direct' !== get_filesystem_method() ) {
				return $this->finish_failed( $from, $to, __( 'The filesystem needs FTP/SSH credentials WordPress does not have.', 'vibe-ai' ), false );
			}

			wp_update_plugins();
			$update_data = get_site_transient( 'update_plugins' );
			$item        = ( is_object( $update_data ) && isset( $update_data->response[ WPVIBE_PLUGIN_BASENAME ] ) )
				? $update_data->response[ WPVIBE_PLUGIN_BASENAME ]
				: null;
			if ( ! $item || empty( $item->new_version ) ) {
				return $this->finish_failed( $from, $to, __( 'no update available', 'vibe-ai' ), false );
			}
			if ( '' !== $expect && (string) $item->new_version !== $expect ) {
				/* translators: 1: expected version, 2: offered version */
				return $this->finish_failed( $from, $to, sprintf( __( 'expected %1$s, offered %2$s', 'vibe-ai' ), $expect, $item->new_version ), false );
			}
			if ( ! isset( $item->plugin ) ) {
				$item->plugin = WPVIBE_PLUGIN_BASENAME;
			}

			// Core's wp_maybe_auto_update() loads admin.php too; the updater needs helpers REST and cron requests lack.
			require_once ABSPATH . 'wp-admin/includes/admin.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

			// Held by core's run(): keeps a core background update from overlapping or nesting in ours.
			if ( ! $this->auto_updater_lock( true ) ) {
				return $this->finish_failed( $from, $to, __( 'WordPress is running its own updates right now, so WPVibe did not start its update. Try again in a few minutes.', 'vibe-ai' ), false );
			}
			// Core ignores a marker older than 10 minutes, so an expired one is not someone else's live maintenance.
			$marker          = ABSPATH . '.maintenance';
			$this->run_guard = array(
				'maintenance_before' => file_exists( $marker ) && ( time() - (int) @filemtime( $marker ) ) < 10 * MINUTE_IN_SECONDS, // phpcs:ignore WordPress.PHP.NoSilencedErrors
				'from'               => $from,
				'to'                 => $to,
				'was_active'         => false,
				'was_network'        => false,
			);
			// A timeout, memory exhaustion or exit skips catch and finally.
			register_shutdown_function( function () {
				$this->recover_interrupted_run();
			} );

			// Plugin_Upgrader::upgrade() (which the automatic updater calls) silently
			// deactivates the plugin unless wp_doing_cron(), and this runs in-request
			// or by loopback, so WPVibe came back switched off on every site that
			// updated this way (1118f6). The update below runs as core's background
			// updates do instead; this state is only for the fallback after it.
			$was_active  = is_plugin_active( WPVIBE_PLUGIN_BASENAME );
			$was_network = is_multisite() && function_exists( 'is_plugin_active_for_network' )
				&& is_plugin_active_for_network( WPVIBE_PLUGIN_BASENAME );
			$this->run_guard['was_active']  = $was_active;
			$this->run_guard['was_network'] = $was_network;

			$result    = null;
			$used_auto = false;
			if ( class_exists( 'WP_Automatic_Updater' ) ) {
				$updater = new WP_Automatic_Updater();
				if ( ! $updater->is_disabled() && ! $updater->is_vcs_checkout( WP_PLUGIN_DIR ) ) {
					// Preferred: WP 6.6+ does a post-update fatal check on active
					// plugins and rolls back to the temp backup, which is exactly
					// the failure that would kill this connection for good.
					$used_auto = true;
					$only_self = function ( $update, $it = null ) {
						return is_object( $it ) && isset( $it->plugin ) && WPVIBE_PLUGIN_BASENAME === $it->plugin;
					};
					add_filter( 'auto_update_plugin', $only_self, PHP_INT_MAX, 2 );
					// As a core background update: the plugin stays active, so core's
					// post-update check loads the NEW code on a loopback to the home
					// page and rolls back on a fatal. Outside cron core deactivates
					// first, and that check then cannot see a WPVibe fatal at all.
					add_filter( 'wp_doing_cron', '__return_true', PHP_INT_MAX );
					// As WP_Automatic_Updater::run() does before calling update(): under
					// cron these upgrader_process_complete hooks start a nested auto-update
					// pass (wp_version_check -> wp_maybe_auto_update), which reinstalls
					// WPVibe, emails the admin and defeats the crash rollback.
					$quieted = $this->quiet_update_hooks();
					try {
						$result = $updater->update( 'plugin', $item );
					} finally {
						foreach ( $quieted as $hook ) {
							add_action( 'upgrader_process_complete', $hook[0], $hook[1], $hook[2] );
						}
						remove_filter( 'wp_doing_cron', '__return_true', PHP_INT_MAX );
						remove_filter( 'auto_update_plugin', $only_self, PHP_INT_MAX );
					}
				}
			}
			if ( ! $used_auto ) {
				$skin     = new Automatic_Upgrader_Skin();
				$upgrader = new Plugin_Upgrader( $skin );
				$bulk     = $upgrader->bulk_upgrade( array( WPVIBE_PLUGIN_BASENAME ) );
				$result   = ( is_array( $bulk ) && isset( $bulk[ WPVIBE_PLUGIN_BASENAME ] ) ) ? $bulk[ WPVIBE_PLUGIN_BASENAME ] : null;
			}

			// Post-conditions read fresh from disk: this process still runs the
			// old code, so WPVIBE_VERSION cannot tell whether files were swapped.
			$disk         = get_plugin_data( WP_PLUGIN_DIR . '/vibe-ai/vibe-ai.php', false, false );
			$disk_version = isset( $disk['Version'] ) ? (string) $disk['Version'] : '';
			$reactivation = $was_active ? $this->reactivate( $was_network ) : false;
			$reactivated  = true === $reactivation;
			$active       = is_plugin_active( WPVIBE_PLUGIN_BASENAME );
			$warning      = $this->reactivation_warning( $was_active, $active, $reactivation );

			// A WP_Error outranks the disk version: a failed rollback leaves the new files in place.
			if ( ! is_wp_error( $result ) && '' !== $disk_version && $disk_version !== $from ) {
				$success = array(
					'status'       => 'success',
					'from_version' => $from,
					'to_version'   => $disk_version,
					'finished_at'  => time(),
					'still_active' => (bool) $active,
					'reactivated'  => $reactivated,
				);
				if ( '' !== $warning ) {
					$success['warning'] = $warning;
				}
				update_option( self::OPTION, $success, false );
				WPVibe_Change_Tracker::mark( array(
					'summary'      => "WPVibe updated: {$from} -> {$disk_version}",
					'action_label' => 'Manage Plugins',
					'admin_url'    => admin_url( 'plugins.php' ),
				) );
				return true;
			}

			$code        = is_wp_error( $result ) ? (string) $result->get_error_code() : '';
			$rolled_back = 'plugin_update_fatal_error_rollback_successful' === $code;
			$error       = is_wp_error( $result )
				? $result->get_error_message()
				: __( 'The plugin files were not replaced; the installed version is unchanged.', 'vibe-ai' );
			if ( $rolled_back && $this->home_unreachable() ) {
				$error = __( 'WordPress restored the previous version because this site could not load its own home page to check the new one (the loopback request failed), not because the update itself is known to be broken. Update WPVibe from the Plugins screen in wp-admin, which does not run that check, or ask the host to allow the site to reach itself.', 'vibe-ai' );
			}
			$extra = array();
			if ( 'plugin_update_fatal_error_rollback_failed' === $code ) {
				$extra['rollback_failed'] = true;
				$extra['disk_version']    = $disk_version;
				/* translators: %s: version now on disk */
				$error .= ' ' . sprintf( __( 'The files on disk are now version %s; check that the site loads and that WPVibe is active on the Plugins screen.', 'vibe-ai' ), '' !== $disk_version ? $disk_version : '?' );
			}
			if ( '' !== $warning ) {
				$extra['warning'] = $warning;
			}
			return $this->finish_failed( $from, $to, $error, $rolled_back, $extra );
		} catch ( \Throwable $e ) {
			$this->remove_own_maintenance();
			$warning = $was_active ? $this->reactivate_after_failure( $was_network ) : '';
			return $this->finish_failed( $from, $to, $e->getMessage(), false, '' !== $warning ? array( 'warning' => $warning ) : array() );
		} finally {
			if ( null !== $this->run_guard ) {
				$this->run_guard = null;
				$this->auto_updater_lock( false );
			}
		}
	}

	/** Core keeps this lock in the main site's options; on multisite take it there, not on the calling subsite. */
	private function auto_updater_lock( $acquire ) {
		$switch = is_multisite() && ! is_main_site( null, get_main_network_id() );
		if ( $switch ) {
			switch_to_blog( get_main_site_id( get_main_network_id() ) );
		}
		try {
			return $acquire ? WP_Upgrader::create_lock( 'auto_updater' ) : WP_Upgrader::release_lock( 'auto_updater' );
		} finally {
			if ( $switch ) {
				restore_current_blog();
			}
		}
	}

	private function remove_own_maintenance() {
		if ( is_array( $this->run_guard ) && ! $this->run_guard['maintenance_before'] && file_exists( ABSPATH . '.maintenance' ) ) {
			wp_delete_file( ABSPATH . '.maintenance' );
		}
	}

	/** Shutdown after the process died mid-run: no throw, no finally, so undo what the run owns here. */
	private function recover_interrupted_run() {
		if ( null === $this->run_guard ) {
			return;
		}
		$guard = $this->run_guard;
		$this->remove_own_maintenance();
		$warning         = $guard['was_active'] ? $this->reactivate_after_failure( $guard['was_network'] ) : '';
		$this->run_guard = null;
		$this->auto_updater_lock( false );
		$state = get_option( self::OPTION, array() );
		if ( is_array( $state ) && 'running' === ( $state['status'] ?? '' ) ) {
			$this->finish_failed( $guard['from'], $guard['to'], __( 'The update process was stopped before it finished (a PHP time or memory limit, or a fatal error). Check the installed version with `plugin get vibe-ai` and make sure WPVibe is active on the Plugins screen.', 'vibe-ai' ), false, '' !== $warning ? array( 'warning' => $warning ) : array() );
		}
	}

	/** Switch WPVibe back on after a run that died or threw; returns the warning to record, if any. */
	private function reactivate_after_failure( $network_wide ) {
		try {
			$reactivation = $this->reactivate( $network_wide );
			return $this->reactivation_warning( true, is_plugin_active( WPVIBE_PLUGIN_BASENAME ), $reactivation );
		} catch ( \Throwable $e ) {
			return __( 'WPVibe may be switched off after the failed update. Check the Plugins screen and activate it if needed.', 'vibe-ai' );
		}
	}

	/** Same check core's has_fatal_error() makes: only a transport failure counts as unreachable. */
	private function home_unreachable() {
		$url = home_url( '/' );
		$res = wp_remote_get( $url, array(
			'timeout'   => 10,
			'sslverify' => apply_filters( 'https_local_ssl_verify', false, $url ),
		) );
		return is_wp_error( $res );
	}

	/**
	 * Detach the upgrader_process_complete hooks core's run() detaches
	 * (class-wp-automatic-updater.php run(), admin-filters.php 146-149).
	 * Returns what was attached, as [callback, priority, accepted_args], to restore.
	 */
	private function quiet_update_hooks() {
		$hooks   = array(
			array( array( 'Language_Pack_Upgrader', 'async_upgrade' ), 1 ),
			array( 'wp_version_check', 0 ),
			array( 'wp_update_plugins', 0 ),
			array( 'wp_update_themes', 0 ),
		);
		$removed = array();
		foreach ( $hooks as $hook ) {
			$priority = has_action( 'upgrader_process_complete', $hook[0] );
			if ( false !== $priority ) {
				remove_action( 'upgrader_process_complete', $hook[0], $priority );
				$removed[] = array( $hook[0], $priority, $hook[1] );
			}
		}
		return $removed;
	}

	/**
	 * Fallback only: the automatic updater runs as cron and never deactivates,
	 * and bulk_upgrade() does not either, so this should find WPVibe active. If
	 * something still dropped it, switch it back on silently (only the
	 * active_plugins option, no activation hooks). Nothing here test-loads the
	 * new code: include_once skips the already-loaded main file.
	 * True when it switched the plugin back on, false when nothing was needed,
	 * WP_Error when it could not.
	 */
	private function reactivate( $network_wide ) {
		if ( is_plugin_active( WPVIBE_PLUGIN_BASENAME ) ) {
			return false;
		}
		$res = activate_plugin( WPVIBE_PLUGIN_BASENAME, '', (bool) $network_wide, true );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return is_plugin_active( WPVIBE_PLUGIN_BASENAME )
			? true
			: new WP_Error( 'wpvibe_reactivate_failed', __( 'it is still not in the active plugins list', 'vibe-ai' ) );
	}

	/** Empty unless the fallback had to act: a silent switch-on skips activation hooks and never test-loads the new code. */
	private function reactivation_warning( $was_active, $active, $reactivation ) {
		if ( ! $was_active ) {
			return '';
		}
		if ( ! $active ) {
			$why = is_wp_error( $reactivation ) ? $reactivation->get_error_message() : __( 'unknown reason', 'vibe-ai' );
			/* translators: %s: why activation failed */
			return sprintf( __( 'WordPress switched WPVibe off during the update and it could not be switched back on (%s). Activate WPVibe from the Plugins screen in wp-admin.', 'vibe-ai' ), $why );
		}
		if ( true === $reactivation ) {
			return __( 'WordPress switched WPVibe off during the update; it was switched back on without running its activation step. If something looks wrong, deactivate and reactivate WPVibe from the Plugins screen.', 'vibe-ai' );
		}
		return '';
	}

	private function finish_failed( $from, $to, $error, $rolled_back, array $extra = array() ) {
		update_option( self::OPTION, array_merge( array(
			'status'       => 'failed',
			'from_version' => (string) $from,
			'to_version'   => (string) $to,
			'error'        => (string) $error,
			'rolled_back'  => (bool) $rolled_back,
			'finished_at'  => time(),
		), $extra ), false );
		return false;
	}
}
