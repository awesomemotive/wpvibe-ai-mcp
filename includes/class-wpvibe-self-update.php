<?php
/**
 * Deferred out-of-band self-update for the WPVibe plugin (issue #92).
 *
 * `plugin update vibe-ai` cannot replace this plugin's files inline: the
 * process doing the swap is also the transport serving the request, and our
 * own code keeps running after the swap (CLI result, REST serialization,
 * audit log), so anything dying in that tail is an opaque 500 with no way to
 * know whether the update landed. This class mirrors core's own model
 * instead: the CLI handler only writes a state option and schedules a
 * one-shot job (WP-Cron, or a token-authenticated loopback when cron is
 * disabled); a throwaway process runs the upgrade and records the outcome in
 * the state option, which the Worker polls.
 *
 * It also owns the one shared read/write helper for the auto_update_plugins
 * SITE option, so the CLI verb and the white-label enrollment cannot drift
 * (multisite stores it as a network option; get_option misses it there).
 */

defined( 'ABSPATH' ) || exit;

class WPVibe_Self_Update {

	const OPTION    = 'wpvibe_self_update_state';
	const CRON_HOOK = 'wpvibe_self_update';

	/** Seconds a loopback token stays redeemable. */
	const TOKEN_TTL = 900;

	/** Seconds after which a scheduled/running state is treated as dead. */
	const STALE_AFTER = 600;

	private static $instance = null;

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
		// Registered here, not in class-wpvibe-rest.php's authed block: /ping is
		// the loopback probe (must answer without auth), and /run's auth is the
		// one-time token, not an application password.
		register_rest_route( 'wpvibe/v1', '/self-update/ping', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_ping_endpoint' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( 'wpvibe/v1', '/self-update/run', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_run_endpoint' ),
			'permission_callback' => '__return_true',
		) );
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

		if ( ! $this->cron_disabled() ) {
			$new['method'] = 'cron';
			update_option( self::OPTION, $new, false );
			wp_schedule_single_event( time(), self::CRON_HOOK );
			if ( function_exists( 'spawn_cron' ) ) {
				// Even if this loopback fails, a system cron or the next visitor
				// runs the event; the Worker's poll covers the wait.
				spawn_cron();
			}
			return $new;
		}

		// DISABLE_WP_CRON: prove the site can reach its own REST API before
		// promising an out-of-band run (probing wp-cron.php proves nothing here).
		$probe = wp_remote_get( rest_url( 'wpvibe/v1/self-update/ping' ), array(
			'timeout'   => 10,
			'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
		) );
		$code  = is_wp_error( $probe ) ? 0 : (int) wp_remote_retrieve_response_code( $probe );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'wpvibe_self_update_unreachable',
				__( 'WPVibe cannot update itself on this site right now: WP-Cron is disabled (DISABLE_WP_CRON) and the site cannot reach its own REST API for a loopback run. Update it from the Plugins screen in wp-admin, or run `plugin auto-updates enable vibe-ai` so WordPress keeps it current automatically.', 'vibe-ai' )
			);
		}

		$raw                = bin2hex( random_bytes( 32 ) );
		$new['method']      = 'loopback';
		$new['token_hash']  = hash( 'sha256', $raw );
		update_option( self::OPTION, $new, false );
		wp_remote_post( rest_url( 'wpvibe/v1/self-update/run' ), array(
			'blocking'  => false,
			'timeout'   => 2,
			'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			'body'      => array( 'token' => $raw ),
		) );
		return $new;
	}

	protected function cron_disabled() {
		return defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
	}

	public function run_self_update_cron() {
		$state = get_option( self::OPTION, array() );
		if ( ! is_array( $state ) || 'scheduled' !== ( $state['status'] ?? '' ) ) {
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

		$state = get_option( self::OPTION, array() );
		$valid = is_array( $state )
			&& 'scheduled' === ( $state['status'] ?? '' )
			&& 'loopback' === ( $state['method'] ?? '' )
			&& ! empty( $state['token_hash'] )
			&& '' !== $token
			&& hash_equals( (string) $state['token_hash'], hash( 'sha256', $token ) )
			&& ( time() - (int) ( $state['scheduled_at'] ?? 0 ) ) <= self::TOKEN_TTL;
		if ( ! $valid ) {
			return new WP_Error( 'wpvibe_self_update_forbidden', __( 'Forbidden.', 'vibe-ai' ), array( 'status' => 403 ) );
		}

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

			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

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
					try {
						$result = $updater->update( 'plugin', $item );
					} finally {
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
			$active       = is_plugin_active( WPVIBE_PLUGIN_BASENAME );

			if ( '' !== $disk_version && $disk_version !== $from ) {
				update_option( self::OPTION, array(
					'status'       => 'success',
					'from_version' => $from,
					'to_version'   => $disk_version,
					'finished_at'  => time(),
					'still_active' => (bool) $active,
				), false );
				WPVibe_Change_Tracker::mark( array(
					'summary'      => "WPVibe updated: {$from} -> {$disk_version}",
					'action_label' => 'Manage Plugins',
					'admin_url'    => admin_url( 'plugins.php' ),
				) );
				return true;
			}

			$rolled_back = $used_auto && is_wp_error( $result );
			$error       = is_wp_error( $result )
				? $result->get_error_message()
				: __( 'The plugin files were not replaced; the installed version is unchanged.', 'vibe-ai' );
			return $this->finish_failed( $from, $to, $error, $rolled_back );
		} catch ( \Throwable $e ) {
			return $this->finish_failed( $from, $to, $e->getMessage(), false );
		}
	}

	private function finish_failed( $from, $to, $error, $rolled_back ) {
		update_option( self::OPTION, array(
			'status'       => 'failed',
			'from_version' => (string) $from,
			'to_version'   => (string) $to,
			'error'        => (string) $error,
			'rolled_back'  => (bool) $rolled_back,
			'finished_at'  => time(),
		), false );
		return false;
	}
}
