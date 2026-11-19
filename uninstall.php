<?php
/**
 * Uninstall the WPVibe plugin (slug "vibe-ai" on WordPress.org).
 *
 * Removes the plugin's options, transients, scheduled events, tables and theme backups, on every site of a multisite
 * network, and the folders it left in the themes folder: a theme that lost its folder to an interrupted publish gets it
 * back first, and a folder any site runs on as its theme stays.
 *
 * @package WPVibe
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( array( 'error-contract' => 'WPVibe_Error_Contract', 'draft-lock' => 'WPVibe_Draft_Lock', 'theme-backup' => 'WPVibe_Theme_Backup', 'draft-theme' => 'WPVibe_Draft_Theme' ) as $wpvibe_file => $wpvibe_class ) {
	if ( ! class_exists( $wpvibe_class ) ) {
		require_once __DIR__ . "/includes/class-wpvibe-$wpvibe_file.php";
	}
}

/** Everything WPVibe keeps for the current site. */
$wpvibe_uninstall_site = function () {
	global $wpdb;
	$options = array(
		'wpvibe_draft_theme',
		'wpvibe_draft_source',
		'wpvibe_preview_token',
		'wpvibe_preview_token_issued',
		'wpvibe_last_active',
		'wpvibe_last_active_site',
		'wpvibe_connection_check_snapshot',
		'wpvibe_diagnostics_fatals',
		'wpvibe_allow_app_passwords',
		'wpvibe_rest_app_passwords_off',
		'wpvibe_installed_at',
		'wpvibe_review_eligible_since',
		'wpvibe_review_notice_status',
		'wpvibe_audit_log_schema',
		'wpvibe_op_receipts_schema',
		'wpvibe_recent_activity',
		'wpvibe_hide_from_admins',
		'wpvibe_connection_status',
		'wpvibe_authorization_pending_at',
		'wpvibe_op_proof_key',
		'wpvibe_op_proof_required',
		'wpvibe_op_proof_minter',
		'wpvibe_op_proof_pending_minter',
		'wpvibe_bypass_approvals',
		'wpvibe_self_update_state',
		'wpvibe_last_draft_event',
	);
	foreach ( $options as $option ) {
		delete_option( $option );
	}
	// Each detached operation's record (wpvibe_detached_<id>) and wpvibe_detached_purged_for.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a prefix match; uninstall runs once.
	foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'wpvibe_detached_' ) . '%' ) ) as $option ) {
		delete_option( $option );
	}

	// The audit log and op-receipts tables (class-wpvibe-audit-log.php, class-wpvibe-op-receipts.php).
	foreach ( array( 'wpvibe_audit_log', 'wpvibe_op_receipts' ) as $table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- dropping the plugin's own table; the name is the site prefix and a constant.
		$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $wpdb->prefix . $table ) . '`' );
	}

	delete_transient( 'wpvibe_last_change' );
	delete_transient( 'wpvibe_activation_redirect' );
	delete_transient( 'wpvibe_widget_feed' );
	delete_transient( 'wpvibe_widget_feed_error' );
	wp_clear_scheduled_hook( 'wpvibe_detached_op' );
	wp_clear_scheduled_hook( 'wpvibe_self_update' );

	// Theme backups from draft publishes, kept as archives under this site's uploads (#1624), and their key.
	WPVibe_Theme_Backup::remove_all();
};

// Every site's theme and parent: on multisite the themes folder is the network's, and a leftover a site runs on stays.
$wpvibe_sites  = is_multisite() ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( 0 );
$wpvibe_in_use = array();
foreach ( $wpvibe_sites as $wpvibe_site ) {
	$wpvibe_in_use[] = (string) ( $wpvibe_site ? get_blog_option( $wpvibe_site, 'stylesheet' ) : get_option( 'stylesheet' ) );
	$wpvibe_in_use[] = (string) ( $wpvibe_site ? get_blog_option( $wpvibe_site, 'template' ) : get_option( 'template' ) );
}

// Before the archives go: an interrupted publish or restore is put right from them.
WPVibe_Theme_Backup::uninstall_folders( $wpvibe_in_use );

foreach ( $wpvibe_sites as $wpvibe_site ) {
	if ( $wpvibe_site ) {
		switch_to_blog( $wpvibe_site );
	}
	$wpvibe_uninstall_site();
	if ( $wpvibe_site ) {
		restore_current_blog();
	}
}

// Each user's own live reload opt-out (#1206); user meta is the network's.
delete_metadata( 'user', 0, 'wpvibe_live_reload_off', '', true );

// Drop our auto-update enrollment (white label adds it) now that the plugin is gone; a network option on multisite.
$wpvibe_auto = get_site_option( 'auto_update_plugins' );
if ( is_array( $wpvibe_auto ) && in_array( 'vibe-ai/vibe-ai.php', $wpvibe_auto, true ) ) {
	update_site_option( 'auto_update_plugins', array_values( array_diff( $wpvibe_auto, array( 'vibe-ai/vibe-ai.php' ) ) ) );
}
