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
	// 1.1: re-checks once on sites that recorded 1.0 without the table being there (#1624).
	const SCHEMA_VERSION = '1.1';
	const OPTION_VERSION = 'wpvibe_op_receipts_schema';

	const MAX_SUMMARY = 16384;
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
		// A create the server refused is tried again, but at most hourly: this runs on every request.
		if ( get_transient( self::OPTION_VERSION . '_retry' ) ) {
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

		// Recorded as installed only once the table exists, and silent with WP_DEBUG on (WordPress.org review 11-17, #1624).
		$suppress = $wpdb->suppress_errors( true );
		dbDelta( $sql );
		$exists = self::table_exists();
		$wpdb->suppress_errors( $suppress );
		if ( ! $exists ) {
			set_transient( self::OPTION_VERSION . '_retry', 1, HOUR_IN_SECONDS );
			return;
		}
		update_option( self::OPTION_VERSION, self::SCHEMA_VERSION );
	}

	/**
	 * Whether the table can be used: its schema is recorded as installed. The option is autoloaded, so this costs no
	 * query. A site cloned or migrated without its custom tables still carries the flag; the first query that finds the
	 * table gone clears it (run()). While not ready every request behaves as if it had no receipt, which is what the
	 * Worker already handles when an insert fails.
	 */
	public static function ready() {
		return get_option( self::OPTION_VERSION ) === self::SCHEMA_VERSION;
	}

	/** Case-insensitive: a server with lower_case_table_names lists a mixed-case prefix in lower case. */
	private static function table_exists() {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return 0 === strcasecmp( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ), $table );
	}

	/**
	 * Every query on the table goes through here (#1624). Skipped, returning $fallback, while the table isn't ready.
	 * Database errors are never printed (WP_DEBUG shows them inside REST JSON, and record_started() reads a receipt on
	 * every request carrying an op id). When the query failed because the table is gone, the flag is cleared and the
	 * table created again; if that works the query runs once more.
	 *
	 * @param callable $query    function ( wpdb $wpdb ) that runs one query and returns its result.
	 * @param mixed    $fallback what the caller gets when the table can't be used.
	 */
	private static function run( $query, $fallback ) {
		if ( ! self::ready() ) {
			return $fallback;
		}
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		// Restored even when a query throws (strict mysqli reporting), so wpdb errors aren't left hidden for the request.
		try {
			$wpdb->last_error = '';
			$result           = $query( $wpdb );
			if ( '' !== (string) $wpdb->last_error && ! self::table_exists() ) {
				delete_option( self::OPTION_VERSION );
				delete_transient( self::OPTION_VERSION . '_retry' );
				self::maybe_install();
				$wpdb->last_error = '';
				$result           = self::ready() ? $query( $wpdb ) : $fallback;
			}
		} finally {
			$wpdb->suppress_errors( $suppress );
		}
		return $result;
	}

	public static function sanitize_op_id( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}
		// op_<hex> from the approval flow; fj_<hex>.<seq>[:a<n>][:b<n>][:r<n>]
		// from the fleet runner (attempt, drain batch, resend). Max 63 chars.
		return preg_match( '/^(op|fj)_[a-z0-9]{1,32}(\.[0-9]{1,9})?(:[a-z][0-9]{1,4}){0,3}$/', $value ) ? $value : '';
	}

	public static function get_receipt( $op_id ) {
		$op_id = self::sanitize_op_id( $op_id );
		if ( '' === $op_id ) {
			return null;
		}
		$table = self::table_name();
		$row   = self::run(
			function ( $wpdb ) use ( $table, $op_id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE op_id = %s", $op_id ), ARRAY_A );
			},
			null
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
				self::$started_ops[ $op_id ] = 'new';
				return $response;
			}
			// The op id is the primary key: an insert that failed with a row there now lost the claim to a concurrent
			// request, which runs the op (#1630). With no row, the table is in trouble and this runs as if unrecorded.
			$existing = self::get_receipt( $op_id );
			if ( null === $existing ) {
				return $response;
			}
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

		// Stale 'started': the earlier run never recorded an outcome. It may have died, applied, or still be going, so the
		// op never runs a second time (#1630); its receipt stays the earlier run's.
		return new WP_Error(
			'wpvibe_op_outcome_unknown',
			__( 'This operation started on this site over 2 minutes ago and never recorded an outcome, so it may or may not have run. Check its effect on the site before requesting it again.', 'vibe-ai' ),
			array(
				'status'  => 409,
				'receipt' => self::receipt_payload( $existing ),
			)
		);
	}

	/**
	 * A request that hands its op to a background process must not complete
	 * the receipt itself (the 202 is not the outcome); the process does.
	 */
	public static function defer( $op_id ) {
		unset( self::$started_ops[ self::sanitize_op_id( $op_id ) ] );
	}

	public static function complete_detached( $op_id, $status, $summary ) {
		$op_id = self::sanitize_op_id( $op_id );
		if ( '' === $op_id ) {
			return;
		}
		self::complete( $op_id, (int) $status, substr( (string) $summary, 0, self::MAX_SUMMARY ) );
	}

	public function record_completed( $response, $handler, $request ) {
		$op_id = self::request_op_id( $request );
		if ( '' === $op_id || empty( self::$started_ops[ $op_id ] ) ) {
			return $response;
		}
		$fresh = 'new' === self::$started_ops[ $op_id ];
		unset( self::$started_ops[ $op_id ] );
		// A refused bypass claim ran nothing and the Worker re-opens the same op for a human, so the op id must stay unused.
		// Only the request that inserted the row releases it: a receipt it found already there stays as it was.
		if ( $fresh && is_wp_error( $response ) && 'wpvibe_bypass_off' === $response->get_error_code() ) {
			self::release( $op_id );
			return $response;
		}
		self::complete( $op_id, self::response_status( $response ), self::summarize( $response ) );
		return $response;
	}

	private static function release( $op_id ) {
		self::run(
			function ( $wpdb ) use ( $op_id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				return $wpdb->delete( self::table_name(), array( 'op_id' => $op_id, 'state' => 'started' ), array( '%s', '%s' ) );
			},
			false
		);
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

		$row    = array(
			'op_id'      => $op_id,
			'state'      => 'started',
			'route'      => substr( $route, 0, self::MAX_ROUTE ),
			'created_at' => self::now(),
		);
		$result = self::run(
			function ( $wpdb ) use ( $row ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				return $wpdb->insert( self::table_name(), $row, array( '%s', '%s', '%s', '%s' ) );
			},
			false
		);
		return false !== $result;
	}

	private static function complete( $op_id, $status, $summary ) {
		$row = array(
			'state'          => 'completed',
			'http_status'    => $status,
			'result_summary' => $summary,
			'finished_at'    => self::now(),
		);
		self::run(
			function ( $wpdb ) use ( $row, $op_id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				return $wpdb->update( self::table_name(), $row, array( 'op_id' => $op_id, 'state' => 'started' ), array( '%s', '%d', '%s', '%s' ), array( '%s', '%s' ) );
			},
			false
		);
	}

	/**
	 * Lazy TTL prune on the write path, mirroring the audit log's prune-on-insert
	 * cadence. Receipts are only written for approved ops, so this is rare.
	 */
	private static function cleanup() {
		$table  = self::table_name();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::TTL_SECONDS );
		self::run(
			function ( $wpdb ) use ( $table, $cutoff ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				return $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) );
			},
			false
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
