<?php
/**
 * Append-only audit log for destructive operations.
 *
 * Records every destructive operation that actually executed: command, args,
 * dry-run preview shown to the user, result summary, timestamp, WP user.
 * Surfaces in WP admin → WPVibe → Approval Log so users can see what their
 * AI assistant has done to their site.
 *
 * Rows are never updated. Only the oldest are deleted, past the most recent
 * 1,000 (filter wpvibe_audit_log_max_rows); uninstall.php drops the table.
 *
 * Decisions (approved / declined / expired) live in the Worker's
 * pending_operations D1 table — the plugin only knows about executions it
 * actually ran. A future endpoint can post-back declines if richer surfacing
 * is needed.
 */

defined( 'ABSPATH' ) || exit;

class WPVibe_Audit_Log {

	const TABLE          = 'wpvibe_audit_log';
	// 1.1: re-checks once on sites that recorded 1.0 without the table being there (#1624).
	const SCHEMA_VERSION = '1.1';
	const OPTION_VERSION = 'wpvibe_audit_log_schema';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Create / upgrade the audit table. Safe to call on every load — dbDelta
	 * is idempotent. Cheap check via wp_options short-circuits when current.
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

		// id, user_id, operation, command, params, dry_run, result, created_at.
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			operation VARCHAR(64) NOT NULL,
			command TEXT NOT NULL,
			params_json LONGTEXT NULL,
			dry_run_json LONGTEXT NULL,
			result_summary TEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_user_created (user_id, created_at),
			KEY idx_operation (operation),
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
	 * table gone clears it (run()), and from then on nothing touches the table until it exists again.
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
	 * Database errors are never printed (WP_DEBUG shows them in the page or inside REST JSON). When the query failed
	 * because the table is gone, the flag is cleared and the table created again; if that works the query runs once
	 * more, so the entry isn't lost.
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

	/**
	 * Record an executed destructive operation. Call from any code path that
	 * runs a destructive op (post-approval). Best-effort — failures are
	 * swallowed so the operation result isn't blocked by audit-log issues.
	 *
	 * @param array $args operation, command, params, dry_run, result_summary
	 */
	public static function log_execution( $args ) {
		$table    = self::table_name();
		$bypassed = class_exists( 'WPVibe_Approval_Bypass' ) && WPVibe_Approval_Bypass::$request_bypassed;
		$row      = array(
			'user_id'        => get_current_user_id(),
			'operation'      => substr( (string) ( $args['operation'] ?? 'unknown' ), 0, 64 ),
			'command'        => (string) ( $args['command'] ?? '' ) . ( $bypassed ? ' [no approval: bypass on]' : '' ),
			'params_json'    => isset( $args['params'] ) ? wp_json_encode( $args['params'] ) : null,
			'dry_run_json'   => isset( $args['dry_run'] ) ? wp_json_encode( $args['dry_run'] ) : null,
			// Cut by character, not byte: a cut inside a multibyte character is invalid UTF-8, which wpdb refuses to insert,
			// and the whole row would be lost (a failed run's error now lands here, #756).
			'result_summary' => isset( $args['result_summary'] ) ? mb_substr( (string) $args['result_summary'], 0, 500 ) : null,
		);
		$inserted = self::run(
			function ( $wpdb ) use ( $table, $row ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				return $wpdb->insert( $table, $row, array( '%d', '%s', '%s', '%s', '%s', '%s' ) );
			},
			false
		);

		$logged = false !== $inserted;
		if ( $logged ) {
			self::trim_to_max_rows();
		}
		self::announce( $args, $bypassed, $logged );
		return $logged;
	}

	/**
	 * Tell other plugins (security logs, activity monitors) that WPVibe ran an
	 * operation (#880). Fires even when the row failed to save, so a listener
	 * still sees it. A listener that throws never fails the operation.
	 *
	 * @param array $args     as passed to log_execution().
	 * @param bool  $bypassed approvals were skipped for this request (bypass on).
	 * @param bool  $logged   the row reached WPVibe's own audit log.
	 */
	private static function announce( $args, $bypassed, $logged ) {
		$entry = array(
			'operation'         => (string) ( $args['operation'] ?? 'unknown' ),
			'command'           => (string) ( $args['command'] ?? '' ),
			'params'            => $args['params'] ?? null,
			'dry_run'           => $args['dry_run'] ?? null,
			'result_summary'    => isset( $args['result_summary'] ) ? (string) $args['result_summary'] : null,
			'user_id'           => get_current_user_id(),
			'approval_bypassed' => $bypassed,
		);
		try {
			/**
			 * Fires after WPVibe runs a destructive operation (post-approval, or with approvals bypassed).
			 *
			 * @param array $entry  operation, command, params, dry_run (the preview the user approved),
			 *                      result_summary, user_id, approval_bypassed.
			 * @param bool  $logged whether the row was saved to WPVibe's audit log.
			 */
			do_action( 'wpvibe_operation_executed', $entry, $logged );
		} catch ( Throwable $e ) {
			// A third-party listener's error is not the operation's error.
			unset( $e );
		}
	}

	/**
	 * Lazy prune: keep at most N rows, where N is filterable. Two cheap queries
	 * per insert — destructive ops are rare so the overhead is negligible.
	 * Return early when under the cap (OFFSET past the end returns NULL).
	 */
	private static function trim_to_max_rows() {
		$max = (int) apply_filters( 'wpvibe_audit_log_max_rows', 1000 );
		if ( $max <= 0 ) {
			return;
		}

		$table  = self::table_name();
		$cutoff = self::run(
			function ( $wpdb ) use ( $table, $max ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				return $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d", $max - 1 ) );
			},
			null
		);
		if ( ! $cutoff ) {
			return;
		}
		self::run(
			function ( $wpdb ) use ( $table, $cutoff ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				return $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id < %d", $cutoff ) );
			},
			false
		);
	}

	public static function get_recent( $limit = 50, $offset = 0 ) {
		$limit  = max( 1, min( 200, (int) $limit ) );
		$offset = max( 0, (int) $offset );
		$table  = self::table_name();
		$rows   = self::run(
			function ( $wpdb ) use ( $table, $limit, $offset ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset ) );
			},
			array()
		);
		return is_array( $rows ) ? $rows : array();
	}

	public static function get_by_id( $id ) {
		$id    = (int) $id;
		$table = self::table_name();
		return self::run(
			function ( $wpdb ) use ( $table, $id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
			},
			null
		);
	}

	public static function count() {
		$table = self::table_name();
		return (int) self::run(
			function ( $wpdb ) use ( $table ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				return $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
			},
			0
		);
	}
}
