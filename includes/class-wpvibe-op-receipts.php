<?php
/**
 * Execution receipts for Worker-approved operations.
 *
 * The MCP Worker stamps X-WPVibe-Op-Id on the single mutation request it
 * issues after a browser approval. If the browser disconnects mid-flight the
 * Worker loses the outcome while WordPress usually finishes the write, so the
 * Worker cannot tell "never executed" from "executed, answer lost" — and must
 * never blind-replay a mutation. This class records what actually happened on
 * the WordPress side so the Worker can reconcile instead of guess:
 *
 *   - 'started'   at rest_request_before_callbacks
 *   - 'completed' at rest_request_after_callbacks (fires for WP_Error too)
 *   - a replayed op-id is short-circuited with 409 + the stored receipt
 *   - GET /wpvibe/v1/op-receipt/<op_id> reads a receipt back
 *
 * rest_request_before_callbacks fires BEFORE permission_callback, so the
 * 'started' write is gated on authentication only. A permission denial simply
 * records completed + 403, which the Worker reads as "reached the site, was
 * rejected, did not apply".
 */

defined( 'ABSPATH' ) || exit;

class WPVibe_Op_Receipts {

	const TABLE          = 'wpvibe_op_receipts';
	const SCHEMA_VERSION = '1.0';
	const OPTION_VERSION = 'wpvibe_op_receipts_schema';

	const MAX_SUMMARY  = 2048;
	const MAX_ROUTE    = 191;
	const IN_PROGRESS  = 120;
	const TTL_SECONDS  = 604800;

	private static $instance = null;

	/** Op-ids this PHP process wrote a 'started' row for; only these may complete. */
	private static $started_ops = array();

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'rest_request_before_callbacks', array( $this, 'record_started' ), 10, 3 );
		add_filter( 'rest_request_after_callbacks', array( $this, 'record_completed' ), 10, 3 );
	}

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Create / upgrade the receipts table. Safe on every load — dbDelta is
	 * idempotent and the wp_options check short-circuits when current.
	 */
	public static function maybe_install() {
		if ( get_option( self::OPTION_VERSION ) === self::SCHEMA_VERSION ) {
			return;
		}

		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		// Timestamps are written by PHP in UTC (see now()), never by MySQL —
		// freshness math happens in PHP and must not mix two clocks.
		$sql = "CREATE TABLE {$table} (
			op_id VARCHAR(64) NOT NULL,
			state VARCHAR(16) NOT NULL DEFAULT 'started',
			route VARCHAR(191) NULL,
			http_status SMALLINT UNSIGNED NULL,
			result_summary TEXT NULL,
			created_at DATETIME NOT NULL,
			finished_at DATETIME NULL,
			PRIMARY KEY (op_id),
			KEY idx_created (created_at)
		) {$charset};";

		dbDelta( $sql );
		update_option( self::OPTION_VERSION, self::SCHEMA_VERSION );
	}

	public static function sanitize_op_id( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}
		return preg_match( '/^op_[a-z0-9]{1,32}$/', $value ) ? $value : '';
	}

	public static function get_receipt( $op_id ) {
		$op_id = self::sanitize_op_id( $op_id );
		if ( '' === $op_id ) {
			return null;
		}
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE op_id = %s", $op_id ),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Wire-shape the Worker parses in two places: the lookup route body and the
	 * 409 replay error's data.receipt. Keep them identical.
	 */
	public static function receipt_payload( $row ) {
		return array(
			'op_id'          => (string) ( $row['op_id'] ?? '' ),
			'state'          => (string) ( $row['state'] ?? '' ),
			'route'          => isset( $row['route'] ) ? (string) $row['route'] : null,
			'http_status'    => isset( $row['http_status'] ) && null !== $row['http_status'] ? (int) $row['http_status'] : null,
			'result_summary' => isset( $row['result_summary'] ) && null !== $row['result_summary'] ? (string) $row['result_summary'] : null,
			'created_at'     => isset( $row['created_at'] ) ? (string) $row['created_at'] : null,
			'finished_at'    => isset( $row['finished_at'] ) && null !== $row['finished_at'] ? (string) $row['finished_at'] : null,
		);
	}

	public function record_started( $response, $handler, $request ) {
		if ( null !== $response ) {
			return $response;
		}

		$op_id = self::request_op_id( $request );
		if ( '' === $op_id || self::is_receipt_lookup( $request ) ) {
			return $response;
		}
		if ( get_current_user_id() <= 0 ) {
			return $response;
		}

		$route    = is_object( $request ) && method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '';
		$existing = self::get_receipt( $op_id );

		if ( null === $existing ) {
			if ( self::insert_started( $op_id, $route ) ) {
				self::$started_ops[ $op_id ] = true;
			}
			return $response;
		}

		// Receipt contents are manage_options-only. A lower-capability caller
		// gets no 409 and no ownership: their request runs its own permission
		// checks and leaves the stored receipt untouched.
		if ( ! current_user_can( 'manage_options' ) ) {
			return $response;
		}

		if ( 'completed' === $existing['state'] ) {
			return new WP_Error(
				'wpvibe_op_replayed',
				__( 'This operation already ran on this site; its recorded result is attached.', 'vibe-ai' ),
				array(
					'status'  => 409,
					'receipt' => self::receipt_payload( $existing ),
				)
			);
		}

		if ( self::is_fresh( $existing['created_at'] ) ) {
			return new WP_Error(
				'wpvibe_op_in_progress',
				__( 'This operation is already running on this site.', 'vibe-ai' ),
				array(
					'status'  => 409,
					'receipt' => self::receipt_payload( $existing ),
				)
			);
		}

		// Stale 'started': the earlier attempt died without completing, so this
		// request becomes the live one and the clock restarts.
		if ( self::restart_started( $op_id, $route ) ) {
			self::$started_ops[ $op_id ] = true;
		}
		return $response;
	}

	public function record_completed( $response, $handler, $request ) {
		$op_id = self::request_op_id( $request );
		if ( '' === $op_id || empty( self::$started_ops[ $op_id ] ) ) {
			return $response;
		}
		unset( self::$started_ops[ $op_id ] );
		self::complete( $op_id, self::response_status( $response ), self::summarize( $response ) );
		return $response;
	}

	private static function request_op_id( $request ) {
		if ( ! is_object( $request ) || ! method_exists( $request, 'get_header' ) ) {
			return '';
		}
		return self::sanitize_op_id( $request->get_header( 'x_wpvibe_op_id' ) );
	}

	/** Reconciliation lookups must never be 409'd by the machinery they read. */
	private static function is_receipt_lookup( $request ) {
		if ( ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) {
			return false;
		}
		$route = $request->get_route();
		return is_string( $route ) && 0 === strpos( $route, '/wpvibe/v1/op-receipt' );
	}

	private static function is_fresh( $created_at ) {
		$ts = strtotime( (string) $created_at . ' UTC' );
		return $ts && ( time() - $ts ) < self::IN_PROGRESS;
	}

	private static function now() {
		return gmdate( 'Y-m-d H:i:s' );
	}

	private static function insert_started( $op_id, $route ) {
		self::cleanup();

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->insert(
			self::table_name(),
			array(
				'op_id'      => $op_id,
				'state'      => 'started',
				'route'      => substr( $route, 0, self::MAX_ROUTE ),
				'created_at' => self::now(),
			),
			array( '%s', '%s', '%s', '%s' )
		);
		return false !== $result;
	}

	private static function restart_started( $op_id, $route ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			self::table_name(),
			array(
				'state'          => 'started',
				'route'          => substr( $route, 0, self::MAX_ROUTE ),
				'http_status'    => null,
				'result_summary' => null,
				'created_at'     => self::now(),
				'finished_at'    => null,
			),
			array( 'op_id' => $op_id ),
			array( '%s', '%s', '%d', '%s', '%s', '%s' ),
			array( '%s' )
		);
		return false !== $result;
	}

	private static function complete( $op_id, $status, $summary ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			self::table_name(),
			array(
				'state'          => 'completed',
				'http_status'    => $status,
				'result_summary' => $summary,
				'finished_at'    => self::now(),
			),
			array(
				'op_id' => $op_id,
				'state' => 'started',
			),
			array( '%s', '%d', '%s', '%s' ),
			array( '%s', '%s' )
		);
	}

	/**
	 * Lazy TTL prune on the write path, mirroring the audit log's prune-on-insert
	 * cadence. Receipts are only written for approved ops, so this is rare.
	 */
	private static function cleanup() {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE created_at < %s",
				gmdate( 'Y-m-d H:i:s', time() - self::TTL_SECONDS )
			)
		);
	}

	private static function response_status( $response ) {
		if ( is_wp_error( $response ) ) {
			$data = $response->get_error_data();
			return is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500;
		}
		if ( is_object( $response ) && method_exists( $response, 'get_status' ) ) {
			return (int) $response->get_status();
		}
		return 200;
	}

	private static function summarize( $response ) {
		if ( is_wp_error( $response ) ) {
			$summary = wp_json_encode( array(
				'code'    => $response->get_error_code(),
				'message' => $response->get_error_message(),
			) );
		} elseif ( is_object( $response ) && method_exists( $response, 'get_data' ) ) {
			$summary = wp_json_encode( $response->get_data() );
		} else {
			$summary = wp_json_encode( $response );
		}
		return substr( (string) $summary, 0, self::MAX_SUMMARY );
	}
}
