<?php
/**
 * Detached execution for approved commands that cannot be chunked from the
 * Worker and can outlive the relay (a live search-replace, mutating SQL).
 *
 * Mirrors the self-update model: the REST request only records the job and
 * schedules a one-shot WP-Cron event (or a token-authenticated loopback when
 * cron is disabled), then answers 202. A throwaway process runs the command
 * as the approving user and writes the outcome into the op receipt, which the
 * Worker polls. The request that scheduled the job never completes the
 * receipt itself (WPVibe_Op_Receipts::defer).
 */

defined( 'ABSPATH' ) || exit;

class WPVibe_Detached_Ops {

	const CRON_HOOK     = 'wpvibe_detached_op';
	const OPTION_PREFIX = 'wpvibe_detached_';
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
	 * Record the job and hand it to a background process. Returns the 202
	 * body, or a WP_Error the request should answer with.
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
			'method'         => 'cron',
		);

		if ( ! $this->cron_disabled() ) {
			update_option( $name, $state, false );
			wp_schedule_single_event( time(), self::CRON_HOOK, array( (string) $op_id ) );
			if ( function_exists( 'spawn_cron' ) ) {
				spawn_cron();
			}
		} else {
			$probe = wp_remote_get( rest_url( 'wpvibe/v1/self-update/ping' ), array(
				'timeout'   => 10,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			) );
			$code  = is_wp_error( $probe ) ? 0 : (int) wp_remote_retrieve_response_code( $probe );
			if ( $code < 200 || $code >= 300 ) {
				return new WP_Error(
					'wpvibe_detach_unavailable',
					__( 'WP-Cron is disabled (DISABLE_WP_CRON) and the site cannot reach its own REST API, so this operation cannot run in the background here. It will run inline instead; long runs may exceed the connection window.', 'vibe-ai' ),
					array( 'status' => 503 )
				);
			}
			$raw                 = bin2hex( random_bytes( 32 ) );
			$state['method']     = 'loopback';
			$state['token_hash'] = hash( 'sha256', $raw );
			update_option( $name, $state, false );
			wp_remote_post( rest_url( 'wpvibe/v1/detached/run' ), array(
				'blocking'  => false,
				'timeout'   => 2,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				'body'      => array( 'op_id' => (string) $op_id, 'token' => $raw ),
			) );
		}

		WPVibe_Op_Receipts::defer( $op_id );

		return array(
			'status'  => 'scheduled',
			'op_id'   => (string) $op_id,
			'method'  => $state['method'],
			'message' => __( 'Running on the site in the background. The outcome lands in the operation receipt; poll it with check_approval_status.', 'vibe-ai' ),
		);
	}

	public function run_cron( $op_id ) {
		$this->run( WPVibe_Op_Receipts::sanitize_op_id( (string) $op_id ) );
	}

	/** One-time-token entry point for DISABLE_WP_CRON sites. */
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
	 * The runner, shared by the cron callback and the token endpoint. The
	 * process has no request user, so it becomes the approver after checking
	 * they still exist and still hold manage_options; every outcome, including
	 * a fatal, lands in the receipt.
	 */
	private function run( $op_id ) {
		if ( '' === $op_id ) {
			return;
		}
		$name  = self::option_name( $op_id );
		$state = get_option( $name, array() );
		if ( ! is_array( $state ) || 'scheduled' !== ( $state['status'] ?? '' ) ) {
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

	protected function cron_disabled() {
		return defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
	}
}
