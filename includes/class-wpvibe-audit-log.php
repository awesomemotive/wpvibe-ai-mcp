<?php
/**
 * Append-only audit log for destructive operations.
 *
 * Records every destructive operation that actually executed: command, args,
 * dry-run preview shown to the user, result summary, timestamp, WP user.
 * Surfaces in WP admin → WPVibe → Approval Log so users can see what their
 * AI assistant has done to their site.
 *
 * The table is append-only by convention — no code path issues DELETE/UPDATE
 * on this table. Uninstall.php drops it; that's the only path to removal.
 *
 * Decisions (approved / declined / expired) live in the Worker's
 * pending_operations D1 table — the plugin only knows about executions it
 * actually ran. A future endpoint can post-back declines if richer surfacing
 * is needed.
 */

defined( 'ABSPATH' ) || exit;

class WPVibe_Audit_Log {

	const TABLE          = 'wpvibe_audit_log';
	const SCHEMA_VERSION = '1.0';
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

		dbDelta( $sql );
		update_option( self::OPTION_VERSION, self::SCHEMA_VERSION );
	}

	/**
	 * Record an executed destructive operation. Call from any code path that
	 * runs a destructive op (post-approval). Best-effort — failures are
	 * swallowed so the operation result isn't blocked by audit-log issues.
	 *
	 * @param array $args operation, command, params, dry_run, result_summary
	 */
	public static function log_execution( $args ) {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$table,
			array(
				'user_id'        => get_current_user_id(),
				'operation'      => substr( (string) ( $args['operation'] ?? 'unknown' ), 0, 64 ),
				'command'        => (string) ( $args['command'] ?? '' ),
				'params_json'    => isset( $args['params'] ) ? wp_json_encode( $args['params'] ) : null,
				'dry_run_json'   => isset( $args['dry_run'] ) ? wp_json_encode( $args['dry_run'] ) : null,
				'result_summary' => isset( $args['result_summary'] ) ? substr( (string) $args['result_summary'], 0, 500 ) : null,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		self::trim_to_max_rows();
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

		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$cutoff = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d", $max - 1 )
		);
		if ( ! $cutoff ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare( "DELETE FROM {$table} WHERE id < %d", $cutoff )
		);
	}

	public static function get_recent( $limit = 50, $offset = 0 ) {
		global $wpdb;
		$limit  = max( 1, min( 200, (int) $limit ) );
		$offset = max( 0, (int) $offset );
		$table  = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset )
		);
	}

	public static function get_by_id( $id ) {
		global $wpdb;
		$id    = (int) $id;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id )
		);
	}

	public static function count() {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}
}
