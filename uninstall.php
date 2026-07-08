<?php
/**
 * Uninstall the WPVibe plugin (slug "vibe-ai" on WordPress.org).
 *
 * Removes all plugin options, transients, and leftover draft/backup theme
 * directories on uninstall.
 *
 * @package WPVibe
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Remove plugin options.
delete_option( 'wpvibe_draft_theme' );
delete_option( 'wpvibe_draft_source' );
delete_option( 'wpvibe_preview_token' );
delete_option( 'wpvibe_preview_token_issued' );
delete_option( 'wpvibe_last_active' );
delete_option( 'wpvibe_installed_at' );
delete_option( 'wpvibe_review_eligible_since' );
delete_option( 'wpvibe_review_notice_status' );
delete_option( 'wpvibe_audit_log_schema' );

// Drop the audit log table created by class-wpvibe-audit-log.php.
global $wpdb;
$audit_table = $wpdb->prefix . 'wpvibe_audit_log';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$audit_table}" );

// Remove transients.
delete_transient( 'wpvibe_last_change' );
delete_transient( 'wpvibe_activation_redirect' );

// Remove any leftover draft / backup theme directories on disk.
if ( function_exists( 'get_theme_root' ) ) {
	$theme_root = get_theme_root();
	$suffixes   = array( '-wpvibe-draft', '-wpvibe-backup' );

	if ( is_dir( $theme_root ) ) {
		$entries = @scandir( $theme_root );
		if ( is_array( $entries ) ) {
			foreach ( $entries as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				foreach ( $suffixes as $suffix ) {
					if ( substr( $entry, -strlen( $suffix ) ) === $suffix ) {
						$path = $theme_root . '/' . $entry;
						if ( is_dir( $path ) ) {
							// Defensive: ensure we never step outside get_theme_root().
							$real_root = realpath( $theme_root );
							$real_path = realpath( $path );
							if ( $real_root && $real_path && 0 === strpos( $real_path, $real_root ) ) {
								// Recursive delete via native PHP (uninstall runs without
								// WP_Filesystem context).
								$it = new RecursiveIteratorIterator(
									new RecursiveDirectoryIterator( $real_path, RecursiveDirectoryIterator::SKIP_DOTS ),
									RecursiveIteratorIterator::CHILD_FIRST
								);
								foreach ( $it as $item ) {
									$item_path = $item->getPathname();
									if ( $item->isDir() ) {
										@rmdir( $item_path );
									} else {
										@unlink( $item_path );
									}
								}
								@rmdir( $real_path );
							}
						}
						break;
					}
				}
			}
		}
	}
}
