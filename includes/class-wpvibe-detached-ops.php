<?php
/**
 * Detached execution for approved commands that cannot be chunked from the
 * Worker and can outlive the relay (a live search-replace, mutating SQL).
 * The request records the job, answers 202, and keeps running it after
 * releasing the connection; without a finish primitive the site hands the
 * job to itself over a one-time token; without loopback the caller runs it
 * inline. The outcome lands in the op receipt, which the Worker polls; the
 * scheduling request never completes the receipt itself (WPVibe_Op_Receipts::defer).
 */

defined( 'ABSPATH' ) || exit;

class WPVibe_Detached_Ops {

	use WPVibe_Request_Detach;

	/** Legacy (1.16.0-1.16.3) cron hook; events that still fire are expired, never run. */
	const CRON_HOOK     = 'wpvibe_detached_op';
	const OPTION_PREFIX = 'wpvibe_detached_';
	const PURGED_OPTION = 'wpvibe_detached_purged_for';
	/** Seconds a loopback token stays redeemable. */
	const TOKEN_TTL = 900;
	/** Seconds after which a scheduled/running job is treated as dead. */
	const STALE_AFTER = 900;
	/** PHP time limit for the detached run. */
	const MAX_RUNTIME = 1800;

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	protected function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( self::CRON_HOOK, array( $this, 'run_cron' ) );
	}

	public function register_routes() {
		// Token-authenticated, not application-password: the caller is the site itself.
		register_rest_route( 'wpvibe/v1', '/detached/run', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_run_endpoint' ),
			'permission_callback' => '__return_true',
		) );
	}

	/** Only the shapes the Worker cannot chunk and that can run past the relay. */
	public static function is_detachable( $command ) {
		$cmd = trim( preg_replace( '/^wp\s+/', '', trim( (string) $command ) ) );
		if ( 0 === strpos( $cmd, 'search-replace' ) ) {
			return ! preg_match( '/\s--dry-run\b/', $cmd );
		}
		if ( 0 === strpos( $cmd, 'db query' ) ) {
			return (bool) preg_match( '/\b(DELETE|UPDATE|DROP|TRUNCATE|ALTER|INSERT|CREATE|RENAME|REPLACE)\b/i', substr( $cmd, 8 ) );
		}
		return false;
	}

	public static function option_name( $op_id ) {
		return self::OPTION_PREFIX . str_replace( array( '.', ':' ), '_', (string) $op_id );
	}

	/**
	 * Record the job and pick the hand-off. Returns the 202 body, or a
	 * WP_Error: 409 while the same op is live, 503 wpvibe_detach_unavailable
	 * when this site has no way to run it out of band (the caller runs inline).
	 */
	public function schedule( $op_id, $command, $confirm_write, $approved_state ) {
		$name     = self::option_name( $op_id );
		$existing = get_option( $name, array() );
		if ( is_array( $existing )
			&& in_array( $existing['status'] ?? '', array( 'scheduled', 'running' ), true )
			&& ( time() - (int) ( $existing['scheduled_at'] ?? 0 ) ) < self::STALE_AFTER ) {
			return new WP_Error(
				'wpvibe_op_in_progress',
				__( 'This operation is already running on the site in the background; poll its receipt instead of resubmitting.', 'vibe-ai' ),
				array( 'status' => 409, 'receipt' => WPVibe_Op_Receipts::receipt_payload( (array) WPVibe_Op_Receipts::get_receipt( $op_id ) ) )
			);
		}

		$state = array(
			'status'         => 'scheduled',
			'command'        => (string) $command,
			'confirm_write'  => (bool) $confirm_write,
			'approved_state' => is_string( $approved_state ) ? $approved_state : '',
			'user_id'        => (int) get_current_user_id(),
			'scheduled_at'   => time(),
			'method'         => 'request',
		);

		if ( null !== $this->finish_request_function() ) {
			update_option( $name, $state, false );
			$op_id = (string) $op_id;
			// Priority 0: ahead of anything else that might serve or buffer the body.
			add_filter( 'rest_pre_serve_request', function ( $served, $result ) use ( $op_id ) {
				if ( $served ) {
					return $served;
				}
				$this->release_response( $result );
				$this->run( $op_id );
				return true;
			}, 0, 2 );
		} else {
			if ( ! $this->probe_loopback() ) {
				return new WP_Error(
					'wpvibe_detach_unavailable',
					__( 'This site cannot run the operation in the background: PHP cannot release the connection early here and the site cannot reach its own REST API. It runs inline instead; a very long run may exceed the connection window.', 'vibe-ai' ),
					array( 'status' => 503 )
				);
			}
			$raw                 = bin2hex( random_bytes( 32 ) );
			$state['method']     = 'loopback';
			$state['token_hash'] = hash( 'sha256', $raw );
			update_option( $name, $state, false );
			$this->post_loopback( 'wpvibe/v1/detached/run', array( 'op_id' => (string) $op_id, 'token' => $raw ) );
		}

		WPVibe_Op_Receipts::defer( $op_id );

		return array(
			'status'  => 'scheduled',
			'op_id'   => (string) $op_id,
			'method'  => $state['method'],
			'option'  => $name,
			'message' => __( 'Running on the site in the background. The outcome lands in the operation receipt; poll it with check_approval_status.', 'vibe-ai' ),
		);
	}

	/** Legacy cron delivery from a 1.16.0-1.16.3 queue: expire it, never run it. */
	public function run_cron( $op_id ) {
		$op_id = WPVibe_Op_Receipts::sanitize_op_id( (string) $op_id );
		if ( '' === $op_id ) {
			return;
		}
		$name  = self::option_name( $op_id );
		$state = get_option( $name, array() );
		if ( is_array( $state ) && 'scheduled' === ( $state['status'] ?? '' ) ) {
			WPVibe_Op_Receipts::complete_detached( $op_id, 410, wp_json_encode( array(
				'code'    => 'detached_expired',
				'message' => __( 'This operation was queued for WP-Cron by an earlier plugin version and expired before it ran; nothing was executed. Resubmit it if it is still wanted.', 'vibe-ai' ),
			) ) );
		}
		delete_option( $name );
	}

	/** Once per plugin version: drop every hand-off an earlier version left behind, so nothing can run late. */
	public function maybe_purge_legacy_handoffs() {
		$version = defined( 'WPVIBE_VERSION' ) ? WPVIBE_VERSION : '';
		if ( '' === $version || get_option( self::PURGED_OPTION ) === $version ) {
			return 0;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$names   = (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::OPTION_PREFIX ) . '%' ) );
		$removed = 0;
		foreach ( $names as $name ) {
			if ( self::PURGED_OPTION === $name ) {
				continue;
			}
			$state = get_option( $name, null );
			if ( is_array( $state ) && 'running' === ( $state['status'] ?? '' ) && ( time() - (int) ( $state['started_at'] ?? 0 ) ) < self::STALE_AFTER ) {
				continue;
			}
			delete_option( $name );
			$removed++;
		}
		update_option( self::PURGED_OPTION, $version );
		return $removed;
	}

	/** One-time-token entry point for sites that cannot release a connection early. */
	public function handle_run_endpoint( $request ) {
		$param = function ( $key ) use ( $request ) {
			if ( is_object( $request ) && method_exists( $request, 'get_param' ) ) {
				return (string) $request->get_param( $key );
			}
			return is_array( $request ) && isset( $request[ $key ] ) ? (string) $request[ $key ] : '';
		};
		$op_id = WPVibe_Op_Receipts::sanitize_op_id( $param( 'op_id' ) );
		$token = $param( 'token' );
		$state = '' !== $op_id ? get_option( self::option_name( $op_id ), array() ) : array();
		$valid = is_array( $state )
			&& 'scheduled' === ( $state['status'] ?? '' )
			&& 'loopback' === ( $state['method'] ?? '' )
			&& ! empty( $state['token_hash'] )
			&& '' !== $token
			&& hash_equals( (string) $state['token_hash'], hash( 'sha256', $token ) )
			&& ( time() - (int) ( $state['scheduled_at'] ?? 0 ) ) <= self::TOKEN_TTL;
		if ( ! $valid ) {
			return new WP_Error( 'wpvibe_detached_forbidden', __( 'Forbidden.', 'vibe-ai' ), array( 'status' => 403 ) );
		}
		$this->run( $op_id );
		return new WP_REST_Response( array( 'status' => 'accepted' ), 202 );
	}

	/**
	 * The runner, shared by the in-request path and the token endpoint. It
	 * becomes the approver after checking they still exist and still hold
	 * manage_options; every outcome, including an uncatchable fatal, lands in
	 * the receipt.
	 */
	private function run( $op_id ) {
		if ( '' === $op_id ) {
			return;
		}
		$name  = self::option_name( $op_id );
		$state = get_option( $name, array() );
		if ( ! is_array( $state ) || 'defused' === ( $state['status'] ?? '' ) ) {
			// The Worker gave up on this op and neutralized the hand-off: nothing to run.
			delete_option( $name );
			return;
		}
		if ( 'scheduled' !== ( $state['status'] ?? '' ) ) {
			return;
		}
		// scheduled -> running burns the token (single use).
		unset( $state['token_hash'] );
		$state['status']     = 'running';
		$state['started_at'] = time();
		update_option( $name, $state, false );

		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( self::MAX_RUNTIME ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		// Fatals skip the catch below; complete() is a no-op once the receipt is done.
		register_shutdown_function( function () use ( $op_id, $name ) {
			$last = error_get_last();
			if ( ! is_array( $last ) || ! in_array( (int) $last['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR ), true ) ) {
				return;
			}
			WPVibe_Op_Receipts::complete_detached( $op_id, 500, wp_json_encode( array(
				'code'    => 'detached_fatal',
				'message' => (string) $last['message'],
			) ) );
			delete_option( $name );
		} );

		$uid  = (int) ( $state['user_id'] ?? 0 );
		$user = $uid > 0 ? get_userdata( $uid ) : false;
		if ( ! $user || ! user_can( $uid, 'manage_options' ) ) {
			WPVibe_Op_Receipts::complete_detached( $op_id, 403, wp_json_encode( array(
				'code'    => 'insufficient_cap',
				'message' => __( 'The approving user no longer exists or no longer has manage_options; nothing ran.', 'vibe-ai' ),
			) ) );
			delete_option( $name );
			return;
		}
		wp_set_current_user( $uid );

		try {
			$cli = new WPVibe_CLI();
			$cli->set_detached( true );
			$result = $cli->run_approved( (string) $state['command'], ! empty( $state['confirm_write'] ), (string) ( $state['approved_state'] ?? '' ) );
			if ( is_wp_error( $result ) ) {
				$data   = $result->get_error_data();
				$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500;
				WPVibe_Op_Receipts::complete_detached( $op_id, $status, wp_json_encode( array(
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				) ) );
			} else {
				WPVibe_Op_Receipts::complete_detached( $op_id, 200, wp_json_encode( $result ) );
			}
		} catch ( \Throwable $e ) {
			WPVibe_Op_Receipts::complete_detached( $op_id, 500, wp_json_encode( array(
				'code'    => 'detached_fatal',
				'message' => $e->getMessage(),
			) ) );
		}
		delete_option( $name );
	}
}
