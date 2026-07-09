<?php
/**
 * WP-CLI-compatible command interface backed by native WordPress PHP APIs.
 *
 * Accepts WP-CLI command syntax (e.g., "plugin list --status=active") and
 * dispatches to native WordPress functions. No proc_open, no wp-cli binary needed.
 *
 * Security model:
 * - Commands must have a registered handler in HANDLERS (no arbitrary shell).
 * - Per-command WordPress capability checks.
 * - Respects DISALLOW_FILE_MODS for commands that modify files.
 * - BLOCKED_OPTIONS are HARD-BLOCKED (never approval-gated) — siteurl,
 *   active_plugins, auth_key, etc. No legitimate AI workflow needs to delete those.
 * - Destructive operations (db query with mutating SQL, plugin uninstall,
 *   user delete, --force bypassing trash) return WP_Error('approval_required')
 *   with a dry-run preview. The Worker surfaces an approval URL and re-invokes
 *   via run_approved() after the user confirms in their browser.
 * - DB SELECT queries restricted with LIMIT enforcement.
 * - Dangerous flags are stripped before dispatch.
 */

defined( 'ABSPATH' ) || exit;

class WPVibe_CLI {

	/** Set when the Worker calls run_approved(); allows handlers to proceed past destructive gates. */
	private $skip_destructive = false;

	/** Resolved allowlist key of the command being dispatched (for handlers shared across aliases). */
	private $current_command = '';

	const ALLOWLIST = array(
		// Read tier
		'plugin list'      => array( 'tier' => 'read', 'cap' => 'activate_plugins' ),
		'plugin status'    => array( 'tier' => 'read', 'cap' => 'activate_plugins' ),
		'plugin search'    => array( 'tier' => 'read', 'cap' => 'install_plugins' ),
		'theme list'       => array( 'tier' => 'read', 'cap' => 'switch_themes' ),
		'theme status'     => array( 'tier' => 'read', 'cap' => 'switch_themes' ),
		'option get'       => array( 'tier' => 'read', 'cap' => 'manage_options' ),
		'option list'      => array( 'tier' => 'read', 'cap' => 'manage_options' ),
		'user list'        => array( 'tier' => 'read', 'cap' => 'list_users' ),
		'post list'        => array( 'tier' => 'read', 'cap' => 'edit_posts' ),
		'post get'         => array( 'tier' => 'read', 'cap' => 'edit_posts' ),
		'post meta get'    => array( 'tier' => 'read', 'cap' => 'edit_posts' ),
		'post meta list'   => array( 'tier' => 'read', 'cap' => 'edit_posts' ),
		'taxonomy list'    => array( 'tier' => 'read', 'cap' => 'edit_posts' ),
		'term list'        => array( 'tier' => 'read', 'cap' => 'manage_categories' ),
		'media list'       => array( 'tier' => 'read', 'cap' => 'upload_files' ),
		'comment list'     => array( 'tier' => 'read', 'cap' => 'moderate_comments' ),
		'comment count'    => array( 'tier' => 'read', 'cap' => 'moderate_comments' ),
		'menu list'        => array( 'tier' => 'read', 'cap' => 'edit_theme_options' ),
		'widget list'      => array( 'tier' => 'read', 'cap' => 'edit_theme_options' ),
		'sidebar list'     => array( 'tier' => 'read', 'cap' => 'edit_theme_options' ),
		'rewrite list'     => array( 'tier' => 'read', 'cap' => 'manage_options' ),
		'cache type'       => array( 'tier' => 'read', 'cap' => 'manage_options' ),
		'cron event list'  => array( 'tier' => 'read', 'cap' => 'manage_options' ),
		'db query'         => array( 'tier' => 'read', 'cap' => 'manage_options' ),
		'db tables'        => array( 'tier' => 'read', 'cap' => 'manage_options' ),
		'db prefix'        => array( 'tier' => 'read', 'cap' => 'manage_options' ),
		'core version'     => array( 'tier' => 'read', 'cap' => 'manage_options' ),
		'core check-update' => array( 'tier' => 'read', 'cap' => 'update_core' ),
		// Checksum verification is read-only; listed here so it resolves before
		// the blocked "core" base-command check in resolve_command().
		'core verify-checksums'   => array( 'tier' => 'read', 'cap' => 'manage_options' ),
		'plugin verify-checksums' => array( 'tier' => 'read', 'cap' => 'activate_plugins' ),
		'plugin get'       => array( 'tier' => 'read', 'cap' => 'activate_plugins' ),
		// Both spellings arrive from AIs in the wild.
		'post-type list'   => array( 'tier' => 'read', 'cap' => 'edit_posts' ),
		'post type list'   => array( 'tier' => 'read', 'cap' => 'edit_posts' ),
		'menu location list' => array( 'tier' => 'read', 'cap' => 'edit_theme_options' ),
		'theme mod list'   => array( 'tier' => 'read', 'cap' => 'edit_theme_options' ),
		// Discoverability: pure metadata, lowest-privilege cap. `help` turns
		// failed command guessing into discovery; `cli version`/`cli info`
		// answer the "what environment is this?" probe with emulator identity.
		'help'             => array( 'tier' => 'read', 'cap' => 'read' ),
		'cli version'      => array( 'tier' => 'read', 'cap' => 'read' ),
		'cli info'         => array( 'tier' => 'read', 'cap' => 'read' ),
		// Resolves before the blocked "config" base check, same as checksums.
		'config get'       => array( 'tier' => 'read', 'cap' => 'manage_options' ),
		// Role/cap definitions are code-level metadata, not user data — the
		// subscriber-level cap makes permission diagnostics work exactly when
		// the connected account is the one missing capabilities.
		'cap list'         => array( 'tier' => 'read', 'cap' => 'read' ),
		'role list'        => array( 'tier' => 'read', 'cap' => 'read' ),
		'maintenance-mode status' => array( 'tier' => 'read', 'cap' => 'manage_options' ),
		'media image-size' => array( 'tier' => 'read', 'cap' => 'upload_files' ),
		'transient get'    => array( 'tier' => 'read', 'cap' => 'manage_options' ),
		'menu item list'   => array( 'tier' => 'read', 'cap' => 'edit_theme_options' ),
		'user get'         => array( 'tier' => 'read', 'cap' => 'list_users' ),
		'theme get'        => array( 'tier' => 'read', 'cap' => 'switch_themes' ),
		'cron test'        => array( 'tier' => 'read', 'cap' => 'manage_options' ),

		// Write tier
		'theme activate'       => array( 'tier' => 'write', 'cap' => 'switch_themes' ),
		'plugin activate'      => array( 'tier' => 'write', 'cap' => 'activate_plugins' ),
		'plugin deactivate'    => array( 'tier' => 'write', 'cap' => 'activate_plugins' ),
		'plugin install'       => array( 'tier' => 'write', 'cap' => 'install_plugins', 'check_file_mods' => true ),
		'plugin update'        => array( 'tier' => 'write', 'cap' => 'update_plugins', 'check_file_mods' => true ),
		'plugin uninstall'     => array( 'tier' => 'write', 'cap' => 'delete_plugins', 'check_file_mods' => true, 'destructive' => true, 'bulk' => array( 'label' => 'plugin' ) ),
		'option update'        => array( 'tier' => 'write', 'cap' => 'manage_options' ),
		'option add'           => array( 'tier' => 'write', 'cap' => 'manage_options' ),
		'option delete'        => array( 'tier' => 'write', 'cap' => 'manage_options' ),
		'transient delete'     => array( 'tier' => 'write', 'cap' => 'manage_options' ),
		'transient list'       => array( 'tier' => 'read',  'cap' => 'manage_options' ),
		'user delete'          => array( 'tier' => 'write', 'cap' => 'delete_users', 'destructive' => true, 'bulk' => array( 'label' => 'user' ) ),
		'post create'          => array( 'tier' => 'write', 'cap' => 'edit_posts' ),
		'post update'          => array( 'tier' => 'write', 'cap' => 'edit_posts' ),
		'post delete'          => array( 'tier' => 'write', 'cap' => 'delete_posts', 'bulk' => array( 'label' => 'post' ) ),
		'post meta update'     => array( 'tier' => 'write', 'cap' => 'edit_posts' ),
		'post meta delete'     => array( 'tier' => 'write', 'cap' => 'edit_posts' ),
		'cache flush'          => array( 'tier' => 'write', 'cap' => 'manage_options' ),
		'cache purge'          => array( 'tier' => 'write', 'cap' => 'manage_options' ),
		// Third-party cache verbs AIs guess first — aliases of `cache purge`
		// scoped to the named plugin (the largest rejected-command cluster).
		'litespeed-purge'      => array( 'tier' => 'write', 'cap' => 'manage_options' ),
		'elementor flush-css'  => array( 'tier' => 'write', 'cap' => 'manage_options' ),
		'elementor flush_css'  => array( 'tier' => 'write', 'cap' => 'manage_options' ),
		'rocket clean'         => array( 'tier' => 'write', 'cap' => 'manage_options' ),
		'sg purge'             => array( 'tier' => 'write', 'cap' => 'manage_options' ),
		'super-cache flush'    => array( 'tier' => 'write', 'cap' => 'manage_options' ),
		'w3-total-cache flush' => array( 'tier' => 'write', 'cap' => 'manage_options' ),
		'breeze purge'         => array( 'tier' => 'write', 'cap' => 'manage_options' ),
		'option patch'         => array( 'tier' => 'write', 'cap' => 'manage_options' ),
		'rewrite flush'        => array( 'tier' => 'write', 'cap' => 'manage_options' ),
		'search-replace'       => array( 'tier' => 'write', 'cap' => 'manage_options' ),
		// Gate-era writes (2026-07: the 1.5.0 approval gate makes these safe).
		'cron event run'    => array( 'tier' => 'write', 'cap' => 'manage_options', 'destructive' => true, 'bulk' => array( 'label' => 'cron_hook' ) ),
		'cron event delete' => array( 'tier' => 'write', 'cap' => 'manage_options', 'destructive' => true, 'bulk' => array( 'label' => 'cron_hook' ) ),
		'theme install'     => array( 'tier' => 'write', 'cap' => 'install_themes', 'check_file_mods' => true ),
		'theme update'      => array( 'tier' => 'write', 'cap' => 'update_themes', 'check_file_mods' => true ),
		'theme delete'      => array( 'tier' => 'write', 'cap' => 'delete_themes', 'check_file_mods' => true, 'destructive' => true, 'bulk' => array( 'label' => 'theme' ) ),
		// Role & capability definitions: core REST has no endpoint for these
		// (the gap role-editor plugins fill). All gated + lockout-protected.
		'cap add'           => array( 'tier' => 'write', 'cap' => 'manage_options', 'destructive' => true ),
		'cap remove'        => array( 'tier' => 'write', 'cap' => 'manage_options', 'destructive' => true ),
		'role create'       => array( 'tier' => 'write', 'cap' => 'manage_options', 'destructive' => true ),
		'role delete'       => array( 'tier' => 'write', 'cap' => 'manage_options', 'destructive' => true ),
		'role reset'        => array( 'tier' => 'write', 'cap' => 'manage_options', 'destructive' => true ),
		'user add-cap'      => array( 'tier' => 'write', 'cap' => 'promote_users', 'destructive' => true ),
		'user remove-cap'   => array( 'tier' => 'write', 'cap' => 'promote_users', 'destructive' => true ),
	);

	/** Administrator-equivalent power: allowed, but the approval preview flags them. */
	const HIGH_RISK_CAPS = array(
		'manage_options',
		'edit_users',
		'promote_users',
		'delete_users',
		'activate_plugins',
		'edit_files',
		'edit_plugins',
		'edit_themes',
		'unfiltered_html',
		'unfiltered_upload',
		'update_core',
	);

	/** WP core default capabilities (populate_roles). Never removable from the administrator role. */
	const CORE_ADMIN_CAPS = array(
		'activate_plugins', 'create_users', 'customize', 'delete_others_pages', 'delete_others_posts',
		'delete_pages', 'delete_plugins', 'delete_posts', 'delete_private_pages', 'delete_private_posts',
		'delete_published_pages', 'delete_published_posts', 'delete_site', 'delete_themes', 'delete_users',
		'edit_dashboard', 'edit_files', 'edit_others_pages', 'edit_others_posts', 'edit_pages',
		'edit_plugins', 'edit_posts', 'edit_private_pages', 'edit_private_posts', 'edit_published_pages',
		'edit_published_posts', 'edit_theme_options', 'edit_themes', 'edit_users', 'export', 'import',
		'install_plugins', 'install_themes', 'list_users', 'manage_categories', 'manage_links',
		'manage_options', 'moderate_comments', 'promote_users', 'publish_pages', 'publish_posts',
		'read', 'read_private_pages', 'read_private_posts', 'remove_users', 'switch_themes',
		'unfiltered_html', 'unfiltered_upload', 'update_core', 'update_plugins', 'update_themes',
		'upload_files',
	);

	const DEFAULT_ROLES = array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' );

	// Write-blocked options that are safe to READ (security audits need them).
	const READABLE_BLOCKED_OPTIONS = array(
		'users_can_register',
		'default_role',
	);

	const BLOCKED_OPTIONS = array(
		'siteurl',
		'home',
		'admin_email',
		'users_can_register',
		'default_role',
		'active_plugins',
		'template',
		'stylesheet',
		'db_version',
		'initial_db_version',
		'wp_user_roles',
		'cron',
		'recently_activated',
		'uninstall_plugins',
		'auto_update_plugins',
		'auto_update_themes',
		'auth_key',
		'secure_auth_key',
		'logged_in_key',
		'nonce_key',
		'auth_salt',
		'secure_auth_salt',
		'logged_in_salt',
		'nonce_salt',
	);

	const BLOCKED_FLAGS = array( '--require', '--exec', '--ssh', '--http', '--url', '--path', '--skip-plugins', '--skip-themes' );
	const SHELL_CHARS   = array( ';', '&&', '||', '|', '`', '$(', '>', '<', "\n", "\r" );

	/** Handler map: command key → method name. */
	const HANDLERS = array(
		'plugin list'       => 'handle_plugin_list',
		'plugin status'     => 'handle_plugin_status',
		'plugin search'     => 'handle_plugin_search',
		'theme list'        => 'handle_theme_list',
		'theme status'      => 'handle_theme_status',
		'option get'        => 'handle_option_get',
		'option list'       => 'handle_option_list',
		'option update'     => 'handle_option_update',
		'user list'         => 'handle_user_list',
		'post list'         => 'handle_post_list',
		'post get'          => 'handle_post_get',
		'post create'       => 'handle_post_create',
		'post update'       => 'handle_post_update',
		'post delete'       => 'handle_post_delete',
		'post meta get'     => 'handle_post_meta_get',
		'post meta list'    => 'handle_post_meta_get',
		'post meta update'  => 'handle_post_meta_update',
		'post meta delete'  => 'handle_post_meta_delete',
		'taxonomy list'     => 'handle_taxonomy_list',
		'term list'         => 'handle_term_list',
		'media list'        => 'handle_media_list',
		'comment list'      => 'handle_comment_list',
		'comment count'     => 'handle_comment_count',
		'menu list'         => 'handle_menu_list',
		'widget list'       => 'handle_widget_list',
		'sidebar list'      => 'handle_sidebar_list',
		'rewrite list'      => 'handle_rewrite_list',
		'rewrite flush'     => 'handle_rewrite_flush',
		'cache type'        => 'handle_cache_type',
		'cache flush'       => 'handle_cache_flush',
		'cron event list'   => 'handle_cron_event_list',
		'db query'          => 'handle_db_query',
		'theme activate'    => 'handle_theme_activate',
		'plugin activate'   => 'handle_plugin_activate',
		'plugin deactivate' => 'handle_plugin_deactivate',
		'plugin install'    => 'handle_plugin_install',
		'plugin update'     => 'handle_plugin_update',
		'plugin uninstall'  => 'handle_plugin_uninstall',
		'option add'        => 'handle_option_add',
		'option delete'     => 'handle_option_delete',
		'transient delete'  => 'handle_transient_delete',
		'transient list'    => 'handle_transient_list',
		'user delete'       => 'handle_user_delete',
		'search-replace'    => 'handle_search_replace',
		'db tables'         => 'handle_db_tables',
		'db prefix'         => 'handle_db_prefix',
		'core version'      => 'handle_core_version',
		'core check-update' => 'handle_core_check_update',
		'core verify-checksums'   => 'handle_core_verify_checksums',
		'plugin verify-checksums' => 'handle_plugin_verify_checksums',
		'plugin get'        => 'handle_plugin_status',
		'post-type list'    => 'handle_post_type_list',
		'post type list'    => 'handle_post_type_list',
		'menu location list' => 'handle_menu_location_list',
		'theme mod list'    => 'handle_theme_mod_list',
		'help'              => 'handle_help',
		'cli version'       => 'handle_cli_version',
		'cli info'          => 'handle_cli_version',
		'cap list'          => 'handle_cap_list',
		'role list'         => 'handle_role_list',
		'maintenance-mode status' => 'handle_maintenance_mode_status',
		'media image-size'  => 'handle_media_image_size',
		'transient get'     => 'handle_transient_get',
		'menu item list'    => 'handle_menu_item_list',
		'user get'          => 'handle_user_get',
		'theme get'         => 'handle_theme_get',
		'cron test'         => 'handle_cron_test',
		'cron event run'    => 'handle_cron_event_run',
		'cron event delete' => 'handle_cron_event_delete',
		'theme install'     => 'handle_theme_install',
		'theme update'      => 'handle_theme_update',
		'theme delete'      => 'handle_theme_delete',
		'config get'        => 'handle_config_get',
		'option patch'      => 'handle_option_patch',
		'cache purge'       => 'handle_cache_purge',
		'litespeed-purge'      => 'handle_cache_purge',
		'elementor flush-css'  => 'handle_cache_purge',
		'elementor flush_css'  => 'handle_cache_purge',
		'rocket clean'         => 'handle_cache_purge',
		'sg purge'             => 'handle_cache_purge',
		'super-cache flush'    => 'handle_cache_purge',
		'w3-total-cache flush' => 'handle_cache_purge',
		'breeze purge'         => 'handle_cache_purge',
		'cap add'           => 'handle_cap_add',
		'cap remove'        => 'handle_cap_remove',
		'role create'       => 'handle_role_create',
		'role delete'       => 'handle_role_delete',
		'role reset'        => 'handle_role_reset',
		'user add-cap'      => 'handle_user_add_cap',
		'user remove-cap'   => 'handle_user_remove_cap',
	);

	const EMULATOR_NAME = 'WPVibe CLI emulation (vibe-ai plugin)';

	/** One-line usage per allowlisted command; the `help` catalog is generated from this. */
	const USAGE = array(
		'plugin list'             => 'plugin list [--status=<active|inactive>] [--update=available] [--fields=<fields>]',
		'plugin status'           => 'plugin status <slug>',
		'plugin search'           => 'plugin search <term> [--per_page=<n>] [--page=<n>]',
		'plugin get'              => 'plugin get <slug>',
		'plugin verify-checksums' => 'plugin verify-checksums [<slug>...] [--all] [--strict]',
		'theme list'              => 'theme list [--status=<active|inactive>] [--fields=<fields>]',
		'theme status'            => 'theme status <slug>',
		'theme mod list'          => 'theme mod list',
		'option get'              => 'option get <key>',
		'option list'             => 'option list [--search=<pattern>] [--autoload=<on|off>]',
		'user list'               => 'user list [--role=<role>] [--number=<n>]',
		'post list'               => 'post list [--post_type=<type>] [--post_status=<status>] [--posts_per_page=<n>] [--s=<search>] [--author=<id>] [--year=<yyyy>] [--monthnum=<1-12>]',
		'post get'                => 'post get <id> [--fields=<fields>]',
		'post meta get'           => 'post meta get <id> [<key>] [--all]',
		'post meta list'          => 'post meta list <id> [--all]',
		'taxonomy list'           => 'taxonomy list [--public=<bool>]',
		'term list'               => 'term list <taxonomy> [--number=<n>] [--search=<term>]',
		'media list'              => 'media list [--post_mime_type=<type>] [--posts_per_page=<n>]',
		'comment list'            => 'comment list [--status=<status>] [--post_id=<id>] [--number=<n>]',
		'comment count'           => 'comment count [<post-id>]',
		'menu list'               => 'menu list',
		'menu location list'      => 'menu location list',
		'widget list'             => 'widget list',
		'sidebar list'            => 'sidebar list',
		'rewrite list'            => 'rewrite list',
		'cache type'              => 'cache type',
		'cron event list'         => 'cron event list',
		'db query'                => 'db query "<sql>" [--limit=<n>] (SELECT runs directly; writes need approval)',
		'db tables'               => 'db tables',
		'db prefix'               => 'db prefix',
		'core version'            => 'core version [--extra]',
		'core check-update'       => 'core check-update',
		'core verify-checksums'   => 'core verify-checksums [--include-root] [--exclude=<files>] [--version=<version>] [--locale=<locale>]',
		'post-type list'          => 'post-type list [--fields=<fields>]',
		'post type list'          => 'post type list [--fields=<fields>]',
		'transient list'          => 'transient list [--search=<pattern>]',
		'help'                    => 'help [<command>]',
		'cli version'             => 'cli version',
		'cli info'                => 'cli info',
		'cap list'                => 'cap list <role> [--show-grant]',
		'role list'               => 'role list',
		'maintenance-mode status' => 'maintenance-mode status',
		'media image-size'        => 'media image-size',
		'transient get'           => 'transient get <key> [--network]',
		'menu item list'          => 'menu item list <menu>',
		'user get'                => 'user get <id|login|email> [--fields=<fields>]',
		'theme get'               => 'theme get <slug> [--fields=<fields>]',
		'cron test'               => 'cron test',
		'theme activate'          => 'theme activate <slug>',
		'plugin activate'         => 'plugin activate <slug>',
		'plugin deactivate'       => 'plugin deactivate <slug>',
		'plugin install'          => 'plugin install <slug> [--version=<version>] [--activate]',
		'plugin update'           => 'plugin update <slug>',
		'plugin uninstall'        => 'plugin uninstall <slug> [<slug>...]',
		'option update'           => 'option update <key> <value>',
		'option add'              => 'option add <key> <value> [--autoload=<yes|no>]',
		'option delete'           => 'option delete <key>',
		'transient delete'        => 'transient delete <name> | --all | --expired',
		'user delete'             => 'user delete <id|login|email> [<id>...] [--reassign=<user>]',
		'post create'             => 'post create --post_title=<title> [--post_content=<content>] [--post_status=<status>] [--post_type=<type>] [--post_date=<Y-m-d H:i:s>]',
		'post update'             => 'post update <id> [<id>...] [--post_title=<title>] [--post_content=<content>] [--post_status=<status>]',
		'post delete'             => 'post delete <id> [<id>...] [--force]',
		'post meta update'        => 'post meta update <id> <key> <value> [--force]',
		'post meta delete'        => 'post meta delete <id> <key> [--force]',
		'cache flush'             => 'cache flush',
		'cache purge'             => 'cache purge (detects the installed cache plugin and purges it, plus the object cache)',
		'litespeed-purge'         => 'litespeed-purge [all] (alias of cache purge, scoped to LiteSpeed Cache)',
		'elementor flush-css'     => 'elementor flush-css (alias of cache purge, scoped to the Elementor CSS cache)',
		'elementor flush_css'     => 'elementor flush_css (alias of cache purge, scoped to the Elementor CSS cache)',
		'rocket clean'            => 'rocket clean (alias of cache purge, scoped to WP Rocket)',
		'sg purge'                => 'sg purge (alias of cache purge, scoped to SG Optimizer)',
		'super-cache flush'       => 'super-cache flush (alias of cache purge, scoped to WP Super Cache)',
		'w3-total-cache flush'    => 'w3-total-cache flush [all] (alias of cache purge, scoped to W3 Total Cache)',
		'breeze purge'            => 'breeze purge (alias of cache purge, scoped to Breeze)',
		'config get'              => 'config get <constant> (credentials/keys/salts are blocked; table_prefix supported)',
		'option patch'            => 'option patch <insert|update|delete> <option> <key-path>... [<value>]',
		'rewrite flush'           => 'rewrite flush',
		'cron event run'          => 'cron event run <hook> [<hook>...]',
		'cron event delete'       => 'cron event delete <hook> [<hook>...]',
		'theme install'           => 'theme install <slug> [--version=<version>] [--activate]',
		'theme update'            => 'theme update <slug>',
		'theme delete'            => 'theme delete <slug> [<slug>...]',
		'cap add'                 => 'cap add <role> <cap>... [--grant=<true|false>]',
		'cap remove'              => 'cap remove <role> <cap>...',
		'role create'             => 'role create <role-key> <role-name> [--clone=<role>]',
		'role delete'             => 'role delete <role-key>',
		'role reset'              => 'role reset <role-key>... | --all',
		'user add-cap'            => 'user add-cap <id|login|email> <cap>',
		'user remove-cap'         => 'user remove-cap <id|login|email> <cap>',
		'search-replace'          => 'search-replace <old> <new> [<table>...] [--dry-run] [--skip-tables=<tables>] [--skip-columns=<cols>] [--include-guids] [--all-tables]',
	);

	/**
	 * Run a WP-CLI-style command via native PHP dispatch.
	 *
	 * AI-facing entry point. Destructive commands (db query mutations, user
	 * delete, plugin uninstall, --force bypassing trash) return approval_required
	 * with a dry-run preview. The Worker handles the browser approval flow and
	 * re-invokes via run_approved() once the user confirms.
	 */
	public function run( $command, $confirm_write = false ) {
		return $this->execute( $command, $confirm_write, false );
	}

	/**
	 * Run a WP-CLI-style command, skipping the destructive check.
	 *
	 * Worker-facing entry point. Called from the /cli/run-approved REST endpoint
	 * after browser-side session verification. Trust comes from App Password
	 * auth — the AI cannot reach this endpoint via the MCP tool surface
	 * (run_wp_cli's schema does not expose an "approved" flag, and the Worker
	 * controls all plugin API calls).
	 */
	public function run_approved( $command, $confirm_write = false ) {
		return $this->execute( $command, $confirm_write, true );
	}

	private function execute( $command, $confirm_write, $skip_destructive ) {
		$this->skip_destructive = (bool) $skip_destructive;
		$command = trim( $command );
		if ( strpos( $command, 'wp ' ) === 0 ) {
			$command = substr( $command, 3 );
		}

		// db query needs < and > for SQL comparisons — skip those chars for that command.
		$is_db_query = ( strpos( $command, 'db query' ) === 0 );
		$blocked_char = $this->find_unquoted_shell_char( $command, $is_db_query );
		if ( null !== $blocked_char ) {
			$hint = ( '<' === $blocked_char || '>' === $blocked_char )
				? ' ' . __( 'To write values containing HTML or scripts, use the content editing tools instead of a CLI command.', 'vibe-ai' )
				: '';
			/* translators: %s: the blocked character */
			return new WP_Error( 'shell_chars', sprintf( __( 'Command contains disallowed character: %s', 'vibe-ai' ), $blocked_char ) . $hint, WPVibe_Error_Contract::data( 'security_gate', false, array( 'status' => 400 ) ) );
		}

		$tokens = $this->tokenize( $command );
		if ( empty( $tokens ) ) {
			return new WP_Error( 'empty_command', __( 'No command provided.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 400 ) ) );
		}

		$resolved = $this->resolve_command( $tokens );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$meta       = $resolved['meta'];
		$key_length = $resolved['key_length'];

		if ( ! current_user_can( $meta['cap'] ) ) {
			/* translators: %s: WordPress capability name */
			return new WP_Error( 'insufficient_cap', sprintf( __( 'You do not have the required capability (%s).', 'vibe-ai' ), $meta['cap'] ), WPVibe_Error_Contract::data( 'capability_role', false, array( 'status' => 403, 'capability' => $meta['cap'] ) ) );
		}

		if ( ! empty( $meta['check_file_mods'] ) && defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			return new WP_Error( 'file_mods_disabled', __( 'File modifications are disabled (DISALLOW_FILE_MODS).', 'vibe-ai' ), WPVibe_Error_Contract::data( 'host_environment', false, array( 'status' => 403 ) ) );
		}

		$args        = $this->strip_blocked_flags( $tokens );
		$command_key = implode( ' ', array_slice( $this->get_positional( $tokens ), 0, $key_length ) );

		// Classify destructive on every path. When !skip_destructive, the
		// classification triggers approval_required. When skip_destructive
		// (post-approval execution), we keep the classification so the audit
		// log can record the dry-run preview alongside the result.
		$destructive = $this->classify_destructive( $command_key, $meta, $args, $key_length );
		if ( $destructive && ! $skip_destructive ) {
			return new WP_Error(
				'approval_required',
				$destructive['reason'],
				WPVibe_Error_Contract::data( 'approval_flow', true, array(
					'status'    => 409,
					'operation' => $destructive['operation'],
					'dry_run'   => $destructive['dry_run'],
					'command'   => 'wp ' . $command_key,
				) )
			);
		}

		// Dispatch to native handler.
		$start  = microtime( true );
		$result = $this->dispatch( $args, $key_length, $command_key, $confirm_write );
		$elapsed = (int) ( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Append-only audit log for destructive operations. Only writes on the
		// run_approved path so the audit log records actually-executed destructive
		// ops, not every command. Failures are swallowed inside log_execution.
		if ( $this->skip_destructive && $destructive && empty( $result['requires_confirmation'] ) ) {
			WPVibe_Audit_Log::log_execution( array(
				'operation'      => $destructive['operation'],
				'command'        => 'wp ' . $command_key,
				'params'         => array( 'positional' => $args, 'key_length' => $key_length ),
				'dry_run'        => $destructive['dry_run'],
				'result_summary' => isset( $result['stdout'] ) ? mb_substr( (string) $result['stdout'], 0, 500 ) : '',
			) );
		}

		$response = array(
			'command'           => 'wp ' . $command_key,
			// Handler may override the tier when the actual semantics differ from
			// the static COMMAND_META — e.g. db query is "read"-tiered by default
			// but flips to "write" when run_approved executes a mutating SQL.
			'tier'              => $result['tier'] ?? $meta['tier'],
			'exit_code'         => $result['exit_code'],
			'stdout'            => $result['stdout'],
			'stderr'            => $result['stderr'],
			'execution_time_ms' => $elapsed,
		);

		if ( ! empty( $result['requires_confirmation'] ) ) {
			$response['requires_confirmation'] = true;
			$response['message']               = $result['message'];
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Always available — native PHP, no external dependencies.
	 */
	public function check_availability() {
		return array(
			'available' => true,
			'method'    => 'native',
		);
	}

	// ------------------------------------------------------------------
	// Destructive classifier
	// ------------------------------------------------------------------

	/**
	 * Detect whether a command needs explicit human approval before execution.
	 * Returns null when safe to auto-execute, or an array{reason, operation, dry_run}
	 * the Worker wraps into an approval URL.
	 *
	 * The list is intentionally narrow — most operations auto-execute. See
	 * PRICING.md / the destructive-actions plan for the full rationale.
	 */
	private function classify_destructive( $command_key, $meta, $tokens, $key_length ) {
		// Separate positional args + flags so we can inspect both.
		$positional = array();
		$flags      = array();
		$skip       = 0;
		foreach ( $tokens as $token ) {
			if ( strpos( $token, '--' ) === 0 ) {
				$stripped = substr( $token, 2 );
				if ( strpos( $stripped, '=' ) !== false ) {
					list( $k, $v ) = explode( '=', $stripped, 2 );
					$flags[ str_replace( '-', '_', $k ) ] = $v;
				} else {
					$flags[ str_replace( '-', '_', $stripped ) ] = true;
				}
			} else {
				if ( $skip < $key_length ) {
					$skip++;
					continue;
				}
				$positional[] = $token;
			}
		}

		// Gate on irreversibility, not count. Reversible ops run freely at any
		// scale — a trash (post delete) is restorable, and post update keeps a
		// WordPress revision. Only irreversible ops confirm: user delete and
		// plugin uninstall (no trash analog), and post delete --force (bypasses
		// trash, permanent). When an irreversible op names several targets,
		// enumerate them so one approval shows the full list. Three explicit IDs
		// is not "bulk" — the trigger is permanence, not how many.
		$force_delete = ( 'post delete' === $command_key && ! empty( $flags['force'] ) );
		if ( ( ! empty( $meta['destructive'] ) || $force_delete ) && ! empty( $meta['bulk'] ) ) {
			$offset  = isset( $meta['bulk']['offset'] ) ? (int) $meta['bulk']['offset'] : 0;
			$targets = array_slice( $positional, $offset );
			if ( count( $targets ) > 1 ) {
				// Force-delete shares an operation prefix across single + bulk so a
				// session bypass (post_delete_force:*) covers both forms.
				$prefix = $force_delete ? 'post_delete_force' : $command_key;
				$reason = $force_delete
					/* translators: %d: number of posts */
					? sprintf( __( 'Permanently deletes %d posts, bypassing trash — they cannot be restored. Review the list before approving.', 'vibe-ai' ), count( $targets ) )
					/* translators: 1: command, 2: target count */
					: sprintf( __( 'Permanently affects %2$d targets via "%1$s" and cannot be undone. Review the list before approving.', 'vibe-ai' ), $command_key, count( $targets ) );
				return array(
					'operation' => $prefix . ':bulk:' . implode( ',', $targets ),
					'reason'    => $reason,
					'dry_run'   => $this->build_bulk_dry_run( $command_key, $meta['bulk'], $targets, $flags ),
				);
			}
		}

		// Single-target unconditionally-destructive: user delete, plugin uninstall.
		if ( ! empty( $meta['destructive'] ) ) {
			return array(
				'operation' => $command_key . ':' . ( $positional[0] ?? '?' ),
				'reason'    => $this->reason_for_command( $command_key ),
				'dry_run'   => $this->build_dry_run( $command_key, $positional, $flags ),
			);
		}

		// search-replace rewrites content in place across tables. --dry-run is a
		// pure read and runs freely; the live run needs approval with a
		// match-count preview.
		if ( 'search-replace' === $command_key ) {
			if ( ! empty( $flags['dry_run'] ) ) {
				return null;
			}
			$old = $positional[0] ?? '';
			if ( '' === $old || ! isset( $positional[1] ) ) {
				return null; // Handler will return a usage error.
			}
			$new = $positional[1];
			return array(
				'operation' => 'search_replace:' . $old . '=>' . $new,
				'reason'    => __( 'search-replace rewrites database content in place, table by table. It handles serialized data safely, but the change is irreversible without a backup. Review the per-table match counts before approving.', 'vibe-ai' ),
				'dry_run'   => $this->build_search_replace_dry_run( $old, $new, array_slice( $positional, 2 ), $flags ),
			);
		}

		// db query: mutating SQL needs approval. Bare-word verbs, plus REPLACE
		// matched only as a statement so the REPLACE() string function inside a
		// read-only SELECT is not misread as a write.
		if ( 'db query' === $command_key ) {
			$sql = trim( implode( ' ', $positional ) );
			if ( '' === $sql ) {
				return null; // Handler will return a usage error.
			}
			$stripped   = preg_replace( '/--.*$/m', '', $sql );
			$stripped   = preg_replace( '/\/\*.*?\*\//s', '', $stripped );
			$normalized = preg_replace( '/\s+/', ' ', strtoupper( trim( $stripped ) ) );
			$mutating   = array( 'DELETE', 'UPDATE', 'DROP', 'TRUNCATE', 'ALTER', 'INSERT', 'CREATE', 'RENAME', 'GRANT', 'REVOKE' );
			$matched    = null;
			foreach ( $mutating as $kw ) {
				if ( preg_match( '/\b' . $kw . '\b/', $normalized ) ) {
					$matched = $kw;
					break;
				}
			}
			if ( null === $matched && preg_match( '/\bREPLACE\s+(?:LOW_PRIORITY\s+|DELAYED\s+)?INTO\b/', $normalized ) ) {
				$matched = 'REPLACE';
			}
			if ( null !== $matched ) {
				return array(
					'operation' => 'db_query_' . strtolower( $matched ),
					'reason'    => sprintf(
						/* translators: %s: SQL keyword */
						__( 'Mutating SQL (%s) bypasses all plugin safety. Direct DB writes need explicit approval.', 'vibe-ai' ),
						$matched
					),
					'dry_run'   => $this->build_db_query_dry_run( $matched, $sql, $normalized ),
				);
			}
			return null;
		}

		// Options have no trash: deleting one permanently destroys whatever
		// configuration lived in it. AI temp state (wpvibe_task_*) and
		// transient rows stay approval-free so the hygiene cleanup loop
		// doesn't drown the user; one bypass approval covers option delete:*.
		if ( 'option delete' === $command_key ) {
			$key = $positional[0] ?? '';
			if ( '' === $key
				|| 0 === strpos( $key, 'wpvibe_task_' )
				|| 0 === strpos( $key, '_transient_' )
				|| 0 === strpos( $key, '_site_transient_' ) ) {
				return null;
			}
			return array(
				'operation' => 'option delete:' . $key,
				'reason'    => __( 'Options are deleted permanently (WordPress has no trash for them), and a plugin\'s entire configuration can live in a single option. Review the value preview before approving.', 'vibe-ai' ),
				'dry_run'   => $this->build_option_delete_dry_run( $key ),
			);
		}

		// Bulk transient wipes — `wp transient delete --all` clears every
		// transient including licensing tokens, refresh tokens, cached API
		// responses, etc. Recovery is impossible. Same threat profile as a
		// destructive option op even though the cap is just manage_options.
		if ( 'transient delete' === $command_key && ( ! empty( $flags['all'] ) || ! empty( $flags['expired'] ) ) ) {
			$scope = ! empty( $flags['all'] ) ? 'all' : 'expired';
			return array(
				'operation' => 'transient_delete_' . $scope,
				'reason'    => 'all' === $scope
					? __( '--all wipes every transient on the site, including license tokens, refresh tokens, cached API responses, and any per-plugin state stored as a transient. Cannot be undone.', 'vibe-ai' )
					: __( '--expired removes every transient WP considers expired. Usually safe (these are caches) but the operation is unbounded — call it out so the user sees what is going.', 'vibe-ai' ),
				'dry_run'   => array(
					'command' => 'wp transient delete --' . $scope,
					'note'    => 'all' === $scope
						? __( 'Every wp_options row whose name starts with _transient_ or _site_transient_ is deleted.', 'vibe-ai' )
						: __( 'Every transient whose expiration timestamp is in the past is deleted.', 'vibe-ai' ),
				),
			);
		}

		// --force flag bypassing trash (post delete --force).
		if ( ! empty( $flags['force'] ) && 'post delete' === $command_key ) {
			$target = $positional[0] ?? '?';
			return array(
				'operation' => 'post_delete_force:' . $target,
				'reason'    => __( '--force bypasses trash and permanently deletes content. The post cannot be restored.', 'vibe-ai' ),
				'dry_run'   => array(
					'command'   => 'wp post delete --force',
					'target_id' => $target,
					'note'      => __( 'Without --force, the post would move to trash and be restorable. With --force, it is permanently deleted.', 'vibe-ai' ),
				),
			);
		}

		return null;
	}

	private function reason_for_command( $command_key ) {
		$reasons = array(
			'user delete'       => __( 'User deletion removes the account permanently. Authored content references are fragile and reassignment requires manual care.', 'vibe-ai' ),
			'plugin uninstall'  => __( 'Plugin uninstall removes the plugin from the filesystem (different from deactivate). Plugin data and settings are typically lost.', 'vibe-ai' ),
			'cron event run'    => __( 'Runs the hook\'s scheduled callbacks immediately. Cron callbacks can do anything the owning plugin can do (send emails, hit APIs, modify data).', 'vibe-ai' ),
			'cron event delete' => __( 'Removes every scheduled instance of this hook. If the owning plugin depends on it, its background work silently stops until something reschedules it.', 'vibe-ai' ),
			'theme delete'      => __( 'Theme delete removes the theme from the filesystem. Any customizations inside the theme folder are lost.', 'vibe-ai' ),
			'cap add'           => __( 'Grants capabilities to every user with this role. Capabilities are the WordPress security boundary — review the literal grant below.', 'vibe-ai' ),
			'cap remove'        => __( 'Removes capabilities from every user with this role and can lock people out of workflows they rely on.', 'vibe-ai' ),
			'role create'       => __( 'Creates a new role definition. Cloned capabilities take effect for anyone later assigned this role.', 'vibe-ai' ),
			'role delete'       => __( 'Deletes the role definition. Users currently holding it are left with no role until reassigned.', 'vibe-ai' ),
			'role reset'        => __( 'Resets the role to its WordPress-default capabilities: custom grants are removed and removed defaults restored.', 'vibe-ai' ),
			'user add-cap'      => __( 'Grants a capability directly to one user, on top of what their role provides.', 'vibe-ai' ),
			'user remove-cap'   => __( 'Removes a capability granted directly to this user (role-derived capabilities are unaffected).', 'vibe-ai' ),
		);
		return $reasons[ $command_key ] ?? __( 'This operation is classified as destructive and requires explicit approval.', 'vibe-ai' );
	}

	private function build_dry_run( $command_key, $positional, $flags ) {
		if ( 'user delete' === $command_key ) {
			$user = ! empty( $positional[0] ) ? get_user_by( is_numeric( $positional[0] ) ? 'id' : 'login', $positional[0] ) : null;
			if ( ! $user ) {
				return array( 'target' => $positional[0] ?? '?', 'note' => __( 'User not found — execution will fail.', 'vibe-ai' ) );
			}
			$post_count = (int) count_user_posts( $user->ID );
			return array(
				'target'         => $user->user_login,
				'user_id'        => $user->ID,
				'email'          => $user->user_email,
				'roles'          => $user->roles,
				'authored_posts' => $post_count,
				'reassign_to'    => $flags['reassign'] ?? null,
			);
		}
		if ( 'plugin uninstall' === $command_key ) {
			$slug = $positional[0] ?? '?';
			$file = $this->resolve_plugin_file( $slug );
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$all = get_plugins();
			if ( ! $file || ! isset( $all[ $file ] ) ) {
				return array( 'target' => $slug, 'note' => __( 'Plugin not found — execution will fail.', 'vibe-ai' ) );
			}
			return array(
				'target'   => $slug,
				'name'     => $all[ $file ]['Name'],
				'version'  => $all[ $file ]['Version'],
				'active'   => is_plugin_active( $file ),
				'file'     => $file,
			);
		}
		if ( 'cron event run' === $command_key || 'cron event delete' === $command_key ) {
			return $this->describe_target( 'cron_hook', $positional[0] ?? '?' );
		}
		if ( 'theme delete' === $command_key ) {
			return $this->describe_target( 'theme', $positional[0] ?? '?' );
		}
		if ( in_array( $command_key, array( 'cap add', 'cap remove', 'role create', 'role delete', 'role reset', 'user add-cap', 'user remove-cap' ), true ) ) {
			return $this->build_role_cap_dry_run( $command_key, $positional, $flags );
		}
		return array( 'command' => $command_key, 'positional' => $positional, 'flags' => $flags );
	}

	/** Approval preview for role/capability edits: the literal grant, spelled out. */
	private function build_role_cap_dry_run( $command_key, $positional, $flags ) {
		$dry = array( 'command' => 'wp ' . $command_key );

		if ( 'cap add' === $command_key || 'cap remove' === $command_key ) {
			$role = $positional[0] ?? '?';
			$caps = array_slice( $positional, 1 );
			$dry['role']         = $role;
			$dry['capabilities'] = $caps;
			$dry['summary']      = 'cap add' === $command_key
				/* translators: 1: capability list, 2: role */
				? sprintf( __( 'Add %1$s to role `%2$s`.', 'vibe-ai' ), '`' . implode( '`, `', $caps ) . '`', $role )
				/* translators: 1: capability list, 2: role */
				: sprintf( __( 'Remove %1$s from role `%2$s`.', 'vibe-ai' ), '`' . implode( '`, `', $caps ) . '`', $role );
			$high = array_values( array_intersect( $caps, self::HIGH_RISK_CAPS ) );
			if ( $high && 'cap add' === $command_key ) {
				$dry['high_risk_capabilities'] = $high;
				/* translators: %s: capability list */
				$dry['warning'] = sprintf( __( '%s grant administrator-equivalent power. A user with these capabilities can take over the site.', 'vibe-ai' ), '`' . implode( '`, `', $high ) . '`' );
			}
			if ( 'cap remove' === $command_key && 'administrator' === $role && array_intersect( $caps, self::CORE_ADMIN_CAPS ) ) {
				$dry['warning'] = __( 'Removing core capabilities from the administrator role is refused at execution (lockout protection).', 'vibe-ai' );
			}
			return $dry;
		}

		if ( 'role create' === $command_key ) {
			$dry['role_key']  = $positional[0] ?? '?';
			$dry['role_name'] = $positional[1] ?? '';
			if ( ! empty( $flags['clone'] ) ) {
				$dry['clone_from'] = $flags['clone'];
				$src               = get_role( $flags['clone'] );
				$dry['cloned_capability_count'] = $src ? count( array_filter( $src->capabilities ) ) : null;
			}
			/* translators: %s: role key */
			$dry['summary'] = sprintf( __( 'Create role `%s`.', 'vibe-ai' ), $dry['role_key'] );
			return $dry;
		}

		if ( 'role delete' === $command_key || 'role reset' === $command_key ) {
			$targets = ! empty( $flags['all'] ) && 'role reset' === $command_key ? self::DEFAULT_ROLES : $positional;
			$dry['roles'] = array();
			foreach ( $targets as $role_key ) {
				$role_obj = get_role( $role_key );
				$entry    = array( 'role' => $role_key, 'exists' => (bool) $role_obj );
				if ( $role_obj ) {
					$entry['capability_count'] = count( array_filter( $role_obj->capabilities ) );
					$users                     = count_users();
					$entry['user_count']       = isset( $users['avail_roles'][ $role_key ] ) ? (int) $users['avail_roles'][ $role_key ] : 0;
				}
				$dry['roles'][] = $entry;
			}
			if ( 'role delete' === $command_key && in_array( 'administrator', $targets, true ) ) {
				$dry['warning'] = __( 'Deleting the administrator role is refused at execution (lockout protection).', 'vibe-ai' );
			}
			if ( 'role delete' === $command_key ) {
				$dry['note'] = __( 'Users holding a deleted role are left with no role until reassigned.', 'vibe-ai' );
			}
			return $dry;
		}

		// user add-cap / user remove-cap
		$dry = array_merge( $dry, $this->describe_target( 'user', $positional[0] ?? '?' ) );
		$cap = $positional[1] ?? '?';
		$dry['capability'] = $cap;
		$dry['summary']    = 'user add-cap' === $command_key
			/* translators: 1: capability, 2: user */
			? sprintf( __( 'Grant `%1$s` directly to user `%2$s`.', 'vibe-ai' ), $cap, $positional[0] ?? '?' )
			/* translators: 1: capability, 2: user */
			: sprintf( __( 'Remove the direct `%1$s` grant from user `%2$s`.', 'vibe-ai' ), $cap, $positional[0] ?? '?' );
		if ( 'user add-cap' === $command_key && in_array( $cap, self::HIGH_RISK_CAPS, true ) ) {
			$dry['high_risk_capabilities'] = array( $cap );
			/* translators: %s: capability */
			$dry['warning'] = sprintf( __( '`%s` grants administrator-equivalent power.', 'vibe-ai' ), $cap );
		}
		return $dry;
	}

	/**
	 * Build the enumerated preview for a bulk op. Generic across target types
	 * (post / user / plugin); the per-target labeling lives in describe_target.
	 * Capped so a 5,000-id bulk doesn't produce a 5,000-row preview.
	 */
	private function build_bulk_dry_run( $command_key, $bulk_meta, $targets, $flags ) {
		$type = isset( $bulk_meta['label'] ) ? $bulk_meta['label'] : 'item';
		$cap  = 100;
		$enum = array();
		foreach ( array_slice( $targets, 0, $cap ) as $t ) {
			$enum[] = $this->describe_target( $type, $t );
		}

		$dry = array(
			'command'           => 'wp ' . $command_key . ( ! empty( $flags['force'] ) ? ' --force' : '' ),
			'count'             => count( $targets ),
			'targets'           => $enum,
			'targets_truncated' => count( $targets ) > $cap,
		);

		if ( 'post delete' === $command_key ) {
			$dry['note'] = ! empty( $flags['force'] )
				? __( '--force permanently deletes these posts (no trash, not restorable).', 'vibe-ai' )
				: __( 'Posts move to trash and remain restorable.', 'vibe-ai' );
		} elseif ( 'post update' === $command_key ) {
			$changes = array();
			foreach ( array( 'post_title', 'post_content', 'post_status', 'post_excerpt', 'post_name', 'post_parent', 'menu_order', 'comment_status', 'post_type' ) as $field ) {
				if ( isset( $flags[ $field ] ) ) {
					$changes[ $field ] = $flags[ $field ];
				}
			}
			$dry['changes'] = $changes;
		} elseif ( 'user delete' === $command_key && ! empty( $flags['reassign'] ) ) {
			$dry['reassign_to'] = $flags['reassign'];
		}

		return $dry;
	}

	/** Resolve a single bulk target to a human-reviewable descriptor by type. */
	private function describe_target( $type, $t ) {
		switch ( $type ) {
			case 'post':
				$post = get_post( (int) $t );
				return $post
					? array( 'id' => (int) $t, 'title' => get_the_title( $post ), 'type' => $post->post_type, 'status' => $post->post_status )
					: array( 'id' => (int) $t, 'note' => __( 'not found', 'vibe-ai' ) );
			case 'user':
				$user = is_numeric( $t )
					? get_user_by( 'id', (int) $t )
					: ( is_email( $t ) ? get_user_by( 'email', $t ) : get_user_by( 'login', $t ) );
				return $user
					? array( 'target' => $user->user_login, 'id' => (int) $user->ID, 'email' => $user->user_email, 'roles' => $user->roles, 'authored_posts' => (int) count_user_posts( $user->ID ) )
					: array( 'target' => $t, 'note' => __( 'not found', 'vibe-ai' ) );
			case 'plugin':
				$file = $this->resolve_plugin_file( $t );
				if ( ! function_exists( 'get_plugins' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}
				$all = get_plugins();
				return ( $file && isset( $all[ $file ] ) )
					? array( 'target' => $t, 'name' => $all[ $file ]['Name'], 'version' => $all[ $file ]['Version'], 'active' => is_plugin_active( $file ) )
					: array( 'target' => $t, 'note' => __( 'not found', 'vibe-ai' ) );
			case 'cron_hook':
				$instances = 0;
				$next      = null;
				$crons     = function_exists( '_get_cron_array' ) ? _get_cron_array() : array();
				foreach ( (array) $crons as $timestamp => $hooks ) {
					if ( isset( $hooks[ $t ] ) ) {
						$instances += count( $hooks[ $t ] );
						if ( null === $next ) {
							$next = gmdate( 'Y-m-d H:i:s', (int) $timestamp );
						}
					}
				}
				return $instances > 0
					? array( 'target' => $t, 'scheduled_instances' => $instances, 'next_run' => $next )
					: array( 'target' => $t, 'note' => __( 'no scheduled events for this hook', 'vibe-ai' ) );
			case 'theme':
				$theme = wp_get_theme( $t );
				if ( ! $theme->exists() ) {
					return array( 'target' => $t, 'note' => __( 'not found', 'vibe-ai' ) );
				}
				$desc = array( 'target' => $t, 'name' => $theme->get( 'Name' ), 'version' => $theme->get( 'Version' ), 'active' => ( get_stylesheet() === $t ) );
				if ( ! $desc['active'] && get_template() === $t ) {
					$desc['note'] = __( 'PARENT of the active child theme — deleting it breaks the site. Execution will refuse.', 'vibe-ai' );
				}
				return $desc;
			default:
				return array( 'target' => $t );
		}
	}

	/** Approval preview for option delete: what's in it, how big, and whether execution will refuse anyway. */
	private function build_option_delete_dry_run( $key ) {
		$dry   = array( 'command' => 'wp option delete', 'option' => $key );
		$value = get_option( $key, null );
		if ( null === $value ) {
			$dry['note'] = __( 'Option not found — execution will fail.', 'vibe-ai' );
			return $dry;
		}
		$str                     = is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );
		$dry['value_type']       = strtolower( gettype( $value ) );
		$dry['value_size_chars'] = strlen( $str );
		$dry['value_preview']    = mb_substr( $str, 0, 200 ) . ( strlen( $str ) > 200 ? '… [truncated]' : '' );
		if ( in_array( $key, self::BLOCKED_OPTIONS, true ) ) {
			$dry['warning'] = __( 'This option is permanently protected by WPVibe; execution will refuse even after approval.', 'vibe-ai' );
		}
		return $dry;
	}

	private function build_db_query_dry_run( $keyword, $sql, $normalized ) {
		global $wpdb;
		// Resolve {prefix} placeholder so the regex parsers below can find the
		// actual table name. handle_db_query does the same substitution at
		// execute time; we mirror it here so the dry-run preview shows the
		// row count + sample the user is about to mutate.
		$sql = str_replace( '{prefix}', $wpdb->prefix, $sql );
		$preview = array(
			'sql'        => $sql,
			'operation'  => $keyword,
			'table_prefix' => $wpdb->prefix,
		);

		// The WHERE-remainder below is interpolated into preview SQL we execute
		// here (pre-approval). Mirror handle_db_query's stacked-statement guard
		// so a `; second statement` cannot ride in: since the quote-aware gate
		// stopped blocking `;` inside quoted values, this builder can no longer
		// rely on that flat backstop. A stacked statement skips the preview
		// (execution still applies its own guard); it is not silently run.
		if ( preg_match( '/;\s*\S/', $sql ) ) {
			$preview['note'] = __( 'Affected-row preview skipped: the statement could not be safely parsed for preview.', 'vibe-ai' );
			return $preview;
		}

		// Cap counting at this many rows so we don't lock up sites with millions
		// of rows. The subquery LIMIT bounds the scan; outer COUNT(*) returns
		// at most $cap + 1, letting us show "$cap+" instead of a blocking count.
		$cap = 1000;

		// For DELETE/UPDATE we can count affected rows by translating the WHERE.
		if ( 'DELETE' === $keyword && preg_match( '/^DELETE\s+FROM\s+([`\w]+)(.*)$/i', trim( $sql ), $m ) ) {
			$table = trim( $m[1], '`' );
			$rest  = trim( rtrim( $m[2], '; ' ) );
			$count_sql = "SELECT COUNT(*) FROM (SELECT 1 FROM `{$table}` {$rest} LIMIT " . ( $cap + 1 ) . ") AS subq";
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = $wpdb->get_var( $count_sql ); // nosemgrep: direct-db-query
			if ( null !== $count && empty( $wpdb->last_error ) ) {
				$n = (int) $count;
				$preview['affected_count'] = min( $n, $cap );
				if ( $n > $cap ) {
					$preview['affected_count_truncated'] = true;
					/* translators: %d: row-count cap */
					$preview['affected_count_note']      = sprintf( __( 'Count truncated at %d to avoid scanning very large tables; actual affected rows may be higher.', 'vibe-ai' ), $cap );
				}
				$sample_sql = "SELECT * FROM `{$table}` {$rest} LIMIT 5";
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$sample = $wpdb->get_results( $sample_sql, ARRAY_A ); // nosemgrep: direct-db-query
				if ( $sample && empty( $wpdb->last_error ) ) {
					$preview['sample_rows'] = $this->trim_sample_rows( $sample );
				}
			} else {
				$preview['note'] = __( 'Could not preview affected rows (SQL parse failure). Execution will attempt the literal DELETE.', 'vibe-ai' );
			}
		}

		if ( 'UPDATE' === $keyword && preg_match( '/^UPDATE\s+([`\w]+)\s+SET\s+.+?(\s+WHERE\s+.*)?$/is', trim( $sql ), $m ) ) {
			$table = trim( $m[1], '`' );
			$where = isset( $m[2] ) ? trim( rtrim( $m[2], '; ' ) ) : '';
			$count_sql = "SELECT COUNT(*) FROM (SELECT 1 FROM `{$table}` {$where} LIMIT " . ( $cap + 1 ) . ") AS subq";
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = $wpdb->get_var( $count_sql ); // nosemgrep: direct-db-query
			if ( null !== $count && empty( $wpdb->last_error ) ) {
				$n = (int) $count;
				$preview['affected_count'] = min( $n, $cap );
				if ( $n > $cap ) {
					$preview['affected_count_truncated'] = true;
					/* translators: %d: row-count cap */
					$preview['affected_count_note']      = sprintf( __( 'Count truncated at %d to avoid scanning very large tables; actual affected rows may be higher.', 'vibe-ai' ), $cap );
				}
				// Show which rows will change (current values) so the approval is
				// reviewable by content, not just by count — same as the DELETE branch.
				$sample_sql = "SELECT * FROM `{$table}` {$where} LIMIT 5";
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$sample = $wpdb->get_results( $sample_sql, ARRAY_A ); // nosemgrep: direct-db-query
				if ( $sample && empty( $wpdb->last_error ) ) {
					$preview['sample_rows'] = $this->trim_sample_rows( $sample );
				}
			} else {
				$preview['note'] = __( 'Could not preview affected rows (SQL parse failure). Execution will attempt the literal UPDATE.', 'vibe-ai' );
			}
		}

		return $preview;
	}

	/**
	 * Truncate long string values in dry-run sample rows so a preview of a wide
	 * table (e.g. wp_posts.post_content, wp_options.option_value) stays readable
	 * instead of dumping full bodies. Table-agnostic: trims any string cell over
	 * the cap, leaving short identifying columns (ID, title, status) intact.
	 *
	 * @param array $rows Rows from $wpdb->get_results( ..., ARRAY_A ).
	 * @param int   $max  Max characters per string cell.
	 * @return array
	 */
	private function trim_sample_rows( $rows, $max = 200 ) {
		if ( ! is_array( $rows ) ) {
			return $rows;
		}
		foreach ( $rows as &$row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			foreach ( $row as $key => $val ) {
				if ( is_string( $val ) && mb_strlen( $val ) > $max ) {
					/* translators: %d: total character count of the truncated value */
					$row[ $key ] = mb_substr( $val, 0, $max ) . sprintf( __( '... [truncated, %d chars total]', 'vibe-ai' ), mb_strlen( $val ) );
				}
			}
		}
		unset( $row );
		return $rows;
	}

	// ------------------------------------------------------------------
	// Dispatch
	// ------------------------------------------------------------------

	private function dispatch( $tokens, $key_length, $command_key, $confirm_write = false ) {
		if ( ! isset( self::HANDLERS[ $command_key ] ) ) {
			/* translators: %s: command key */
			return $this->error_result( sprintf( __( 'No handler for: %s', 'vibe-ai' ), $command_key ) );
		}

		// Separate positional args and flags after the command key.
		$positional = array();
		$flags      = array();
		$skip       = 0;

		foreach ( $tokens as $token ) {
			if ( strpos( $token, '--' ) === 0 ) {
				$stripped = substr( $token, 2 );
				if ( strpos( $stripped, '=' ) !== false ) {
					list( $k, $v ) = explode( '=', $stripped, 2 );
					// Normalize hyphenated flags to underscored (e.g., per-page → per_page).
					$flags[ str_replace( '-', '_', $k ) ] = $v;
				} else {
					$flags[ str_replace( '-', '_', $stripped ) ] = true;
				}
			} else {
				if ( $skip < $key_length ) {
					$skip++;
					continue;
				}
				$positional[] = $token;
			}
		}

		$this->current_command = $command_key;
		$handler               = self::HANDLERS[ $command_key ];
		return $this->{$handler}( $positional, $flags, $confirm_write );
	}

	// ------------------------------------------------------------------
	// Read Handlers
	// ------------------------------------------------------------------

	private function handle_plugin_list( $positional, $flags ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Populate update availability. Refresh from WP.org when the caller asks
		// for update info (--update filter or update/update_version in --fields) or
		// the cache is empty; otherwise use the cached result to avoid a network hit.
		$wants_updates = isset( $flags['update'] )
			|| ( ! empty( $flags['fields'] ) && preg_match( '/\bupdate(_version)?\b/', $flags['fields'] ) );
		$update_cache = get_site_transient( 'update_plugins' );
		if ( $wants_updates || ! is_object( $update_cache ) || empty( $update_cache->checked ) ) {
			wp_update_plugins();
			$update_cache = get_site_transient( 'update_plugins' );
		}
		$responses = ( is_object( $update_cache ) && ! empty( $update_cache->response ) ) ? $update_cache->response : array();

		$all     = get_plugins();
		$results = array();
		foreach ( $all as $file => $data ) {
			$active = is_plugin_active( $file );
			$status = $active ? 'active' : 'inactive';
			if ( isset( $flags['status'] ) && $flags['status'] !== $status ) {
				continue;
			}
			$has_update = isset( $responses[ $file ] ) && ! empty( $responses[ $file ]->new_version );
			if ( isset( $flags['update'] ) && 'available' === $flags['update'] && ! $has_update ) {
				continue;
			}
			$results[] = array(
				'name'           => $data['Name'],
				'status'         => $status,
				'version'        => $data['Version'],
				'update'         => $has_update ? 'available' : 'none',
				'update_version' => $has_update ? $responses[ $file ]->new_version : '',
				'file'           => $file,
			);
		}
		return $this->success_result( $this->filter_fields( $results, $flags ) );
	}

	private function handle_plugin_status( $positional, $flags ) {
		if ( empty( $positional[0] ) ) {
			return $this->error_result( __( 'Plugin slug required.', 'vibe-ai' ) );
		}
		$file = $this->resolve_plugin_file( $positional[0] );
		if ( ! $file ) {
			/* translators: %s: plugin slug */
			return $this->error_result( sprintf( __( 'Plugin \'%s\' not found.', 'vibe-ai' ), $positional[0] ) );
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all  = get_plugins();
		$data = $all[ $file ];
		return $this->success_result( array(
			'name'    => $data['Name'],
			'status'  => is_plugin_active( $file ) ? 'active' : 'inactive',
			'version' => $data['Version'],
			'file'    => $file,
			'author'  => $data['AuthorName'] ?? '',
			'description' => $data['Description'] ?? '',
		) );
	}

	private function handle_plugin_search( $positional, $flags ) {
		if ( empty( $positional[0] ) ) {
			return $this->error_result( __( 'Search term required. Example: plugin search "contact form"', 'vibe-ai' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';

		$args = array(
			'search'   => implode( ' ', $positional ),
			'per_page' => min( (int) ( $flags['per_page'] ?? 10 ), 30 ),
			'page'     => (int) ( $flags['page'] ?? 1 ),
			'fields'   => array(
				'short_description' => true,
				'icons'             => false,
				'banners'           => false,
				'compatibility'     => false,
			),
		);

		$api = plugins_api( 'query_plugins', $args );
		if ( is_wp_error( $api ) ) {
			return $this->error_result( $api->get_error_message() );
		}

		$results = array();
		foreach ( $api->plugins as $plugin ) {
			$results[] = array(
				'name'              => $plugin->name,
				'slug'              => $plugin->slug,
				'version'           => $plugin->version,
				'author'            => wp_strip_all_tags( $plugin->author ),
				'rating'            => $plugin->rating,
				'active_installs'   => $plugin->active_installs,
				'short_description' => $plugin->short_description,
			);
		}

		return $this->success_result( $this->filter_fields( $results, $flags ) );
	}

	private function handle_theme_list( $positional, $flags ) {
		$themes      = wp_get_themes();
		$active_slug = get_stylesheet();
		$results     = array();
		foreach ( $themes as $slug => $theme ) {
			$status = ( $slug === $active_slug ) ? 'active' : 'inactive';
			if ( isset( $flags['status'] ) && $flags['status'] !== $status ) {
				continue;
			}
			$results[] = array(
				'name'    => $theme->get( 'Name' ),
				'status'  => $status,
				'version' => $theme->get( 'Version' ),
				'slug'    => $slug,
			);
		}
		return $this->success_result( $this->filter_fields( $results, $flags ) );
	}

	private function handle_theme_status( $positional, $flags ) {
		if ( empty( $positional[0] ) ) {
			return $this->error_result( __( 'Theme slug required.', 'vibe-ai' ) );
		}
		$theme = wp_get_theme( $positional[0] );
		if ( ! $theme->exists() ) {
			/* translators: %s: theme slug */
			return $this->error_result( sprintf( __( 'Theme \'%s\' not found.', 'vibe-ai' ), $positional[0] ) );
		}
		return $this->success_result( array(
			'name'    => $theme->get( 'Name' ),
			'status'  => ( get_stylesheet() === $positional[0] ) ? 'active' : 'inactive',
			'version' => $theme->get( 'Version' ),
			'author'  => $theme->get( 'Author' ),
			'slug'    => $positional[0],
		) );
	}

	private function handle_option_get( $positional, $flags ) {
		if ( empty( $positional[0] ) ) {
			return $this->error_result( __( 'Option key required.', 'vibe-ai' ) );
		}

		if ( in_array( $positional[0], self::READABLE_BLOCKED_OPTIONS, true ) ) {
			$value = get_option( $positional[0], null );
			return $this->success_result( array( 'value' => $value ) );
		}

		if ( in_array( $positional[0], self::BLOCKED_OPTIONS, true ) ) {
			return $this->error_result(
				sprintf(
					/* translators: %s: option key */
					__( 'Option \'%s\' is blocked for security.', 'vibe-ai' ),
					$positional[0]
				)
			);
		}

		$value = get_option( $positional[0], null );
		if ( null === $value ) {
			/* translators: %s: option key */
			return $this->error_result( sprintf( __( 'Option \'%s\' not found.', 'vibe-ai' ), $positional[0] ) );
		}
		return array(
			'exit_code' => 0,
			'stdout'    => is_scalar( $value ) ? (string) $value : wp_json_encode( $value, JSON_PRETTY_PRINT ),
			'stderr'    => '',
		);
	}

	private function handle_option_list( $positional, $flags ) {
		global $wpdb;

		$search = isset( $flags['search'] ) ? $flags['search'] : '%';
		// Convert WP-CLI wildcard syntax (* and ?) to SQL LIKE syntax (% and _).
		$search = str_replace( array( '*', '?' ), array( '%', '_' ), $search );

		$has_autoload = isset( $flags['autoload'] );

		/*
		 * Raw SQL justification: Dynamic LIKE pattern from user input; prepared via $wpdb->prepare().
		 * Only reads from the options table; no writes. Two separate queries to avoid interpolation.
		 */
		if ( $has_autoload ) {
			// WP 6.6+ stores autoload as on/off/auto-on/auto-off/auto alongside legacy yes/no.
			// Filter by exclusion so unknown future values default to autoloaded, matching core.
			$wants_on = ( 'on' === $flags['autoload'] || 'yes' === $flags['autoload'] );
			$not_on   = array( 'no', 'off', 'auto-off' );
			$operator = $wants_on ? 'NOT IN' : 'IN';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND autoload {$operator} ( %s, %s, %s ) ORDER BY option_name LIMIT 100",
					array_merge( array( $search ), $not_on )
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name LIMIT 100",
					$search
				),
				ARRAY_A
			);
		}

		$results = array();
		foreach ( $rows as $row ) {
			if ( in_array( $row['option_name'], self::BLOCKED_OPTIONS, true ) ) {
				continue;
			}
			if ( strlen( $row['option_value'] ) > 200 ) {
				$row['option_value'] = substr( $row['option_value'], 0, 200 ) . '...[truncated]';
			}
			$results[] = $row;
		}

		return $this->success_result( $this->filter_fields( $results, $flags ) );
	}

	private function handle_user_list( $positional, $flags ) {
		$args = array( 'number' => 100 );
		if ( isset( $flags['role'] ) )   $args['role']   = $flags['role'];
		if ( isset( $flags['number'] ) ) $args['number'] = min( (int) $flags['number'], 1000 );
		$users   = get_users( $args );
		$results = array();
		foreach ( $users as $user ) {
			$results[] = array(
				'ID'              => $user->ID,
				'user_login'      => $user->user_login,
				'display_name'    => $user->display_name,
				'user_email'      => $user->user_email,
				'roles'           => implode( ',', $user->roles ),
				'user_registered' => $user->user_registered,
			);
		}
		return $this->success_result( $this->filter_fields( $results, $flags ) );
	}

	private function handle_post_list( $positional, $flags ) {
		$post_type = $flags['post_type'] ?? 'post';
		if ( is_string( $post_type ) && strpos( $post_type, ',' ) !== false ) {
			$post_type = array_filter( array_map( 'trim', explode( ',', $post_type ) ) );
		}
		$args = array(
			'post_type'      => $post_type,
			'post_status'    => $flags['post_status'] ?? 'any',
			'posts_per_page' => isset( $flags['posts_per_page'] ) ? min( (int) $flags['posts_per_page'], 100 ) : 20,
			'orderby'        => $flags['orderby'] ?? 'date',
			'order'          => $flags['order'] ?? 'DESC',
		);
		// Silently ignoring targeting flags is dangerous when a listing feeds a
		// bulk operation, so honor the common ones and reject the rest.
		if ( isset( $flags['s'] ) )        $args['s']        = (string) $flags['s'];
		if ( isset( $flags['author'] ) )   $args['author']   = (int) $flags['author'];
		if ( isset( $flags['year'] ) )     $args['year']     = (int) $flags['year'];
		if ( isset( $flags['monthnum'] ) ) $args['monthnum'] = (int) $flags['monthnum'];
		$known = array( 'post_type', 'post_status', 'posts_per_page', 'orderby', 'order', 's', 'author', 'year', 'monthnum', 'fields', 'format' );
		foreach ( array_keys( $flags ) as $flag ) {
			if ( ! in_array( $flag, $known, true ) ) {
				/* translators: %s: the unsupported flag name */
				return $this->error_result( sprintf( __( 'post list does not support --%s here. Supported filters: s, author, year, monthnum, post_type, post_status, posts_per_page, orderby, order. For richer queries use the REST API (/wp/v2/posts).', 'vibe-ai' ), $flag ) );
			}
		}
		$posts   = get_posts( $args );
		$results = array();
		foreach ( $posts as $post ) {
			$results[] = array(
				'ID'          => $post->ID,
				'post_title'  => $post->post_title,
				'post_name'   => $post->post_name,
				'post_status' => $post->post_status,
				'post_type'   => $post->post_type,
				'post_date'   => $post->post_date,
			);
		}
		return $this->success_result( $this->filter_fields( $results, $flags ) );
	}

	private function handle_post_get( $positional, $flags ) {
		if ( empty( $positional[0] ) ) {
			return $this->error_result( __( 'Post ID required.', 'vibe-ai' ) );
		}
		$post = get_post( (int) $positional[0] );
		if ( ! $post ) {
			/* translators: %s: post ID */
			return $this->error_result( sprintf( __( 'Post %s not found.', 'vibe-ai' ), $positional[0] ) );
		}

		$has_explicit_fields = ! empty( $flags['fields'] );

		$content = $post->post_content;
		if ( ! $has_explicit_fields && strlen( $content ) > 500 ) {
			$content = substr( $content, 0, 500 ) . "\n[truncated — use --fields=post_content for full content]";
		}

		$content_filtered = $post->post_content_filtered;
		if ( ! $has_explicit_fields && strlen( $content_filtered ) > 500 ) {
			$content_filtered = substr( $content_filtered, 0, 500 ) . "\n[truncated — use --fields=post_content_filtered for full content]";
		}

		$data = array(
			'ID'                    => $post->ID,
			'post_title'            => $post->post_title,
			'post_name'             => $post->post_name,
			'post_status'           => $post->post_status,
			'post_type'             => $post->post_type,
			'post_date'             => $post->post_date,
			'post_modified'         => $post->post_modified,
			'post_author'           => $post->post_author,
			'post_excerpt'          => $post->post_excerpt,
			'post_content'          => $content,
			'post_content_filtered' => $content_filtered,
			'post_parent'           => $post->post_parent,
			'menu_order'            => $post->menu_order,
			'comment_status'        => $post->comment_status,
			'post_mime_type'        => $post->post_mime_type,
			'guid'                  => $post->guid,
			'comment_count'         => $post->comment_count,
		);

		return $this->success_result( $this->filter_fields( array( $data ), $flags )[0] ?? $data );
	}

	private function handle_post_meta_get( $positional, $flags ) {
		if ( empty( $positional[0] ) ) {
			return $this->error_result( __( 'Post ID required. Usage: post meta get <id> [<key>]', 'vibe-ai' ) );
		}

		$post_id = (int) $positional[0];
		$post    = get_post( $post_id );
		if ( ! $post ) {
			/* translators: %s: post ID */
			return $this->error_result( sprintf( __( 'Post %s not found.', 'vibe-ai' ), $positional[0] ) );
		}

		// Single key mode.
		if ( ! empty( $positional[1] ) ) {
			$value = get_post_meta( $post_id, $positional[1], true );
			return array(
				'exit_code' => 0,
				'stdout'    => is_scalar( $value ) ? (string) $value : wp_json_encode( $value, JSON_PRETTY_PRINT ),
				'stderr'    => '',
			);
		}

		// All meta mode.
		$meta    = get_post_meta( $post_id );
		$results = array();
		foreach ( $meta as $key => $values ) {
			// Hide internal meta unless --all flag is set.
			if ( empty( $flags['all'] ) && strpos( $key, '_' ) === 0 ) {
				continue;
			}
			$results[] = array(
				'key'   => $key,
				'value' => count( $values ) === 1 ? $values[0] : $values,
			);
		}

		return $this->success_result( $results );
	}

	private function handle_taxonomy_list( $positional, $flags ) {
		$taxonomies = get_taxonomies( array(), 'objects' );
		$results    = array();
		foreach ( $taxonomies as $slug => $tax ) {
			if ( isset( $flags['public'] ) && (bool) $flags['public'] !== $tax->public ) {
				continue;
			}
			$results[] = array(
				'name'         => $slug,
				'label'        => $tax->label,
				'public'       => $tax->public,
				'hierarchical' => $tax->hierarchical,
				'object_type'  => $tax->object_type,
			);
		}
		return $this->success_result( $this->filter_fields( $results, $flags ) );
	}

	private function handle_term_list( $positional, $flags ) {
		// Accept taxonomy as positional (real WP-CLI) or --taxonomy flag (AI compat).
		$taxonomy = $positional[0] ?? $flags['taxonomy'] ?? '';
		if ( empty( $taxonomy ) ) {
			return $this->error_result( __( 'Taxonomy required. Usage: term list <taxonomy> or term list --taxonomy=category', 'vibe-ai' ) );
		}
		if ( ! taxonomy_exists( $taxonomy ) ) {
			/* translators: %s: taxonomy name */
			return $this->error_result( sprintf( __( 'Taxonomy \'%s\' not found.', 'vibe-ai' ), $taxonomy ) );
		}

		$args = array(
			'taxonomy'   => $taxonomy,
			'number'     => isset( $flags['number'] ) ? min( (int) $flags['number'], 500 ) : 100,
			'hide_empty' => isset( $flags['hide_empty'] ) ? (bool) $flags['hide_empty'] : false,
			'orderby'    => $flags['orderby'] ?? 'name',
			'order'      => $flags['order'] ?? 'ASC',
		);
		if ( isset( $flags['search'] ) ) {
			$args['search'] = $flags['search'];
		}
		if ( isset( $flags['parent'] ) ) {
			$args['parent'] = (int) $flags['parent'];
		}

		$terms   = get_terms( $args );
		if ( is_wp_error( $terms ) ) {
			return $this->error_result( $terms->get_error_message() );
		}

		$results = array();
		foreach ( $terms as $term ) {
			$results[] = array(
				'term_id'     => $term->term_id,
				'name'        => $term->name,
				'slug'        => $term->slug,
				'description' => $term->description,
				'count'       => $term->count,
				'parent'      => $term->parent,
			);
		}

		return $this->success_result( $this->filter_fields( $results, $flags ) );
	}

	private function handle_media_list( $positional, $flags ) {
		// Not a real WP-CLI command — maps to get_posts(type=attachment) for AI convenience.
		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => isset( $flags['posts_per_page'] ) ? min( (int) $flags['posts_per_page'], 100 ) : 20,
			'orderby'        => $flags['orderby'] ?? 'date',
			'order'          => $flags['order'] ?? 'DESC',
		);
		if ( isset( $flags['post_mime_type'] ) ) {
			$args['post_mime_type'] = $flags['post_mime_type'];
		}

		$posts   = get_posts( $args );
		$results = array();
		foreach ( $posts as $post ) {
			$results[] = array(
				'ID'             => $post->ID,
				'post_title'     => $post->post_title,
				'post_mime_type' => $post->post_mime_type,
				'guid'           => $post->guid,
				'post_date'      => $post->post_date,
			);
		}

		return $this->success_result( $this->filter_fields( $results, $flags ) );
	}

	private function handle_comment_list( $positional, $flags ) {
		$args = array(
			'number' => isset( $flags['number'] ) ? min( (int) $flags['number'], 100 ) : 20,
		);
		if ( isset( $flags['status'] ) )  $args['status']  = $flags['status'];
		if ( isset( $flags['post_id'] ) ) $args['post_id'] = (int) $flags['post_id'];
		if ( isset( $flags['type'] ) )    $args['type']    = $flags['type'];

		$comments = get_comments( $args );
		$results  = array();
		foreach ( $comments as $comment ) {
			$content = $comment->comment_content;
			if ( strlen( $content ) > 200 ) {
				$content = substr( $content, 0, 200 ) . '...[truncated]';
			}
			$results[] = array(
				'comment_ID'      => $comment->comment_ID,
				'comment_author'  => $comment->comment_author,
				'comment_content' => $content,
				'comment_date'    => $comment->comment_date,
				'comment_approved' => $comment->comment_approved,
				'comment_post_ID' => $comment->comment_post_ID,
			);
		}

		return $this->success_result( $this->filter_fields( $results, $flags ) );
	}

	private function handle_comment_count( $positional, $flags ) {
		$post_id = ! empty( $positional[0] ) ? (int) $positional[0] : 0;
		$counts  = wp_count_comments( $post_id );

		return $this->success_result( array(
			'approved'            => $counts->approved,
			'awaiting_moderation' => $counts->moderated,
			'spam'                => $counts->spam,
			'trash'               => $counts->trash,
			'total_comments'      => $counts->total_comments,
		) );
	}

	private function handle_menu_list( $positional, $flags ) {
		$menus   = wp_get_nav_menus();
		$results = array();
		foreach ( $menus as $menu ) {
			$results[] = array(
				'term_id' => $menu->term_id,
				'name'    => $menu->name,
				'slug'    => $menu->slug,
				'count'   => $menu->count,
			);
		}
		return $this->success_result( $results );
	}

	private function handle_widget_list( $positional, $flags ) {
		global $wp_registered_sidebars;
		$sidebars = get_option( 'sidebars_widgets', array() );
		$results  = array();
		foreach ( $sidebars as $sidebar_id => $widgets ) {
			if ( 'wp_inactive_widgets' === $sidebar_id ) continue;
			$name = isset( $wp_registered_sidebars[ $sidebar_id ] ) ? $wp_registered_sidebars[ $sidebar_id ]['name'] : $sidebar_id;
			$results[] = array(
				'sidebar_id' => $sidebar_id,
				'name'       => $name,
				'widgets'    => $widgets ?: array(),
			);
		}
		return $this->success_result( $results );
	}

	private function handle_sidebar_list( $positional, $flags ) {
		global $wp_registered_sidebars;
		$results = array();
		if ( $wp_registered_sidebars ) {
			foreach ( $wp_registered_sidebars as $id => $sidebar ) {
				$results[] = array(
					'id'          => $id,
					'name'        => $sidebar['name'],
					'description' => $sidebar['description'] ?? '',
				);
			}
		}
		return $this->success_result( $this->filter_fields( $results, $flags ) );
	}

	private function handle_rewrite_list( $positional, $flags ) {
		global $wp_rewrite;
		$rules   = $wp_rewrite->rules ?: array();
		$results = array();
		foreach ( $rules as $pattern => $query ) {
			$results[] = array( 'match' => $pattern, 'query' => $query );
		}
		return $this->success_result( $results );
	}

	private function handle_cache_type( $positional, $flags ) {
		return $this->success_result( array(
			'object_cache' => wp_using_ext_object_cache() ? 'external' : 'default',
			'drop_in'      => file_exists( WP_CONTENT_DIR . '/object-cache.php' ),
		) );
	}

	private function handle_cron_event_list( $positional, $flags ) {
		$crons   = _get_cron_array();
		$results = array();
		if ( $crons ) {
			foreach ( $crons as $timestamp => $hooks ) {
				foreach ( $hooks as $hook => $events ) {
					foreach ( $events as $key => $event ) {
						$results[] = array(
							'hook'      => $hook,
							'next_run'  => gmdate( 'Y-m-d H:i:s', $timestamp ),
							'schedule'  => $event['schedule'] ?: 'once',
							'interval'  => $event['interval'] ?? null,
						);
					}
				}
			}
		}
		return $this->success_result( $results );
	}

	// ------------------------------------------------------------------
	// DB Query Handler (SELECT only)
	// ------------------------------------------------------------------

	private function handle_db_query( $positional, $flags ) {
		global $wpdb;

		$sql = trim( implode( ' ', $positional ) );
		if ( empty( $sql ) ) {
			return $this->error_result( __( 'SQL query required. Example: db query "SELECT * FROM {prefix}posts LIMIT 10"', 'vibe-ai' ) );
		}

		// Replace {prefix} placeholder with actual table prefix.
		$sql = str_replace( '{prefix}', $wpdb->prefix, $sql );

		// Validate: SELECT only.
		// Strip SQL comments to prevent keyword bypass.
		$stripped = preg_replace( '/--.*$/m', '', $sql );
		$stripped = preg_replace( '/\/\*.*?\*\//s', '', $stripped );
		$normalized = preg_replace( '/\s+/', ' ', strtoupper( trim( $stripped ) ) );

		$is_select = ( strpos( $normalized, 'SELECT' ) === 0 );

		// SELECT-only path (the common case for auto-execute).
		if ( ! $is_select && ! $this->skip_destructive ) {
			// classify_destructive should have caught this; defense-in-depth.
			return $this->error_result( __( 'Mutating SQL requires explicit approval. Only SELECT queries auto-execute.', 'vibe-ai' ) );
		}

		if ( $is_select ) {
			$blocked = array(
				'INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'TRUNCATE',
				'CREATE', 'GRANT', 'REVOKE', 'EXEC', 'EXECUTE',
				'RENAME', 'REPLACE', 'LOAD', 'OUTFILE', 'DUMPFILE',
			);
			foreach ( $blocked as $keyword ) {
				if ( preg_match( '/\b' . $keyword . '\b/', $normalized ) ) {
					/* translators: %s: SQL keyword */
					return $this->error_result( sprintf( __( 'Blocked SQL keyword in SELECT: %s.', 'vibe-ai' ), $keyword ) );
				}
			}
		}

		// Multi-statement guard applies to both SELECT and mutating paths.
		if ( preg_match( '/;\s*\S/', $sql ) ) {
			return $this->error_result( __( 'Multiple SQL statements are not allowed.', 'vibe-ai' ) );
		}

		if ( $is_select ) {
			if ( preg_match( '/\bINTO\s+(OUTFILE|DUMPFILE|@)/i', $normalized ) ) {
				return $this->error_result( __( 'SELECT INTO is not allowed.', 'vibe-ai' ) );
			}

			if ( preg_match( '/\bFOR\s+(UPDATE|SHARE)\b/', $normalized ) ) {
				return $this->error_result( __( 'FOR UPDATE/SHARE is not allowed.', 'vibe-ai' ) );
			}

			// Enforce LIMIT. --limit flag overrides default (capped at 1000).
			$default_limit = 100;
			if ( ! empty( $flags['limit'] ) && is_numeric( $flags['limit'] ) ) {
				$default_limit = min( (int) $flags['limit'], 1000 );
			}
			$sql = rtrim( $sql, '; ' );
			if ( preg_match( '/\bLIMIT\s+(\d+)/i', $sql, $m ) ) {
				$sql = preg_replace_callback( '/\bLIMIT\s+(\d+)/i', function ( $m ) {
					return 'LIMIT ' . min( (int) $m[1], 1000 );
				}, $sql );
			} else {
				$sql .= ' LIMIT ' . $default_limit;
			}

			// Execute SELECT.
			/*
			 * Raw SQL justification: This handler accepts user-provided SELECT queries
			 * for database inspection. $wpdb->prepare() cannot be used because the full
			 * SQL structure is dynamic. Security is enforced via SELECT-only validation,
			 * blocked keyword list, comment stripping, INTO/FOR UPDATE prevention,
			 * multi-statement prevention, and automatic LIMIT enforcement.
			 */
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$results = $wpdb->get_results( $sql, ARRAY_A ); // nosemgrep: direct-db-query
			if ( $wpdb->last_error ) {
				/* translators: %s: SQL error message */
				return $this->error_result( sprintf( __( 'SQL error: %s', 'vibe-ai' ), $wpdb->last_error ) );
			}

			$output = array(
				'table_prefix'  => $wpdb->prefix,
				'rows_returned' => count( $results ),
				'results'       => $results,
			);

			return array(
				'exit_code' => 0,
				'stdout'    => wp_json_encode( $output, JSON_PRETTY_PRINT ),
				'stderr'    => '',
			);
		}

		// Mutating path — only reachable when skip_destructive is true (caller is run_approved).
		// Use $wpdb->query() which returns affected row count for INSERT/UPDATE/DELETE.
		$sql = rtrim( $sql, '; ' );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$affected = $wpdb->query( $sql ); // nosemgrep: direct-db-query
		if ( false === $affected || $wpdb->last_error ) {
			/* translators: %s: SQL error message */
			return $this->error_result( sprintf( __( 'SQL error: %s', 'vibe-ai' ), $wpdb->last_error ) );
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => sprintf(
				/* translators: 1: number of rows affected */
				_n( 'DB query executed (%d row affected)', 'DB query executed (%d rows affected)', (int) $affected, 'vibe-ai' ),
				(int) $affected
			),
			'action_label' => 'Refresh',
		) );

		return array(
			'exit_code' => 0,
			'stdout'    => wp_json_encode( array(
				'table_prefix'  => $wpdb->prefix,
				'affected_rows' => (int) $affected,
			), JSON_PRETTY_PRINT ),
			'stderr'    => '',
			// COMMAND_META has db query as 'read'-tiered (because it was originally
			// SELECT-only). Override to 'write' on the mutating execution path so
			// the response label matches reality.
			'tier'      => 'write',
		);
	}

	// ------------------------------------------------------------------
	// Write Handlers
	// ------------------------------------------------------------------

	private function handle_theme_activate( $positional, $flags ) {
		if ( empty( $positional[0] ) ) {
			return $this->error_result( __( 'Theme slug required.', 'vibe-ai' ) );
		}
		$theme = wp_get_theme( $positional[0] );
		if ( ! $theme->exists() ) {
			/* translators: %s: theme slug */
			return $this->error_result( sprintf( __( 'Theme \'%s\' not found.', 'vibe-ai' ), $positional[0] ) );
		}
		switch_theme( $positional[0] );

		// switch_theme() is void; verify by reading back the active stylesheet.
		// Theme requirement validation (WP version, PHP version, parent theme) can
		// silently no-op the switch on some WP versions.
		if ( get_stylesheet() !== $positional[0] ) {
			return $this->error_result(
				sprintf(
					/* translators: 1: requested theme slug, 2: actual active theme */
					__( 'switch_theme(\'%1$s\') did not take effect. Active stylesheet is still \'%2$s\'. The theme may not meet WP/PHP version requirements, may be missing a parent theme, or may have been rejected by the theme validator.', 'vibe-ai' ),
					$positional[0],
					get_stylesheet()
				)
			);
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Theme activated: {$positional[0]}",
			'action_label' => 'View Site',
			'url'          => home_url( '/' ),
			'admin_url'    => home_url( '/' ),
		) );
		/* translators: %s: theme name */
		return $this->success_result( array( 'message' => sprintf( __( 'Switched to theme \'%s\'.', 'vibe-ai' ), $theme->get( 'Name' ) ) ) );
	}

	private function handle_plugin_activate( $positional, $flags ) {
		if ( empty( $positional[0] ) ) {
			return $this->error_result( __( 'Plugin slug required.', 'vibe-ai' ) );
		}
		$file = $this->resolve_plugin_file( $positional[0] );
		if ( ! $file ) {
			/* translators: %s: plugin slug */
			return $this->error_result( sprintf( __( 'Plugin \'%s\' not found.', 'vibe-ai' ), $positional[0] ) );
		}
		$result = activate_plugin( $file );
		if ( is_wp_error( $result ) ) {
			return $this->error_result( $result->get_error_message() );
		}
		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Plugin activated: {$positional[0]}",
			'action_label' => 'Refresh',
		) );
		/* translators: %s: plugin slug */
		return $this->success_result( array( 'message' => sprintf( __( 'Plugin \'%s\' activated.', 'vibe-ai' ), $positional[0] ) ) );
	}

	private function handle_plugin_deactivate( $positional, $flags ) {
		if ( empty( $positional[0] ) ) {
			return $this->error_result( __( 'Plugin slug required.', 'vibe-ai' ) );
		}
		$file = $this->resolve_plugin_file( $positional[0] );
		if ( ! $file ) {
			/* translators: %s: plugin slug */
			return $this->error_result( sprintf( __( 'Plugin \'%s\' not found.', 'vibe-ai' ), $positional[0] ) );
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		try {
			deactivate_plugins( $file );
		} catch ( \Throwable $e ) {
			return $this->error_result(
				sprintf(
					/* translators: 1: plugin slug, 2: error message */
					__( 'Deactivation of \'%1$s\' threw a fatal: %2$s The plugin\'s deactivation hook errored.', 'vibe-ai' ),
					$positional[0],
					$e->getMessage()
				)
			);
		}

		// Verify the deactivation took effect.
		if ( is_plugin_active( $file ) ) {
			return $this->error_result(
				sprintf(
					/* translators: %s: plugin slug */
					__( 'Plugin \'%s\' is still active after deactivate. A deactivation hook may have re-activated it, or the plugin is network-activated on a multisite install.', 'vibe-ai' ),
					$positional[0]
				)
			);
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Plugin deactivated: {$positional[0]}",
			'action_label' => 'Refresh',
		) );
		/* translators: %s: plugin slug */
		return $this->success_result( array( 'message' => sprintf( __( 'Plugin \'%s\' deactivated.', 'vibe-ai' ), $positional[0] ) ) );
	}

	private function handle_plugin_install( $positional, $flags, $confirm_write = false ) {
		if ( empty( $positional[0] ) ) {
			return $this->error_result( __( 'Plugin slug required.', 'vibe-ai' ) );
		}
		$slug = sanitize_key( $positional[0] );

		// Canonical admin-context bootstrap. Plugin_Upgrader is meant to run
		// from wp-admin, so calling it from REST/CLI needs the same includes
		// that wp-admin loads first.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';

		$api_fields = array(
			'short_description' => true,
			'sections'          => false,
			'icons'             => false,
			'banners'           => false,
		);
		$api_args = array( 'slug' => $slug, 'fields' => $api_fields );
		if ( ! empty( $flags['version'] ) ) {
			$api_args['version'] = $flags['version'];
		}

		$api = plugins_api( 'plugin_information', $api_args );
		if ( is_wp_error( $api ) ) {
			return $this->error_result( $api->get_error_message() );
		}

		// Phase 1: Return info and require confirmation.
		if ( ! $confirm_write ) {
			return array(
				'exit_code'             => 0,
				'stdout'                => wp_json_encode( array(
					'name'            => $api->name,
					'slug'            => $api->slug,
					'version'         => $api->version,
					'author'          => wp_strip_all_tags( $api->author ),
					'requires'        => $api->requires ?? '',
					'tested'          => $api->tested ?? '',
					'rating'          => $api->rating,
					'active_installs' => $api->active_installs,
					'download_link'   => $api->download_link,
				), JSON_PRETTY_PRINT ),
				'stderr'                => '',
				'requires_confirmation' => true,
				'message'               => sprintf(
					/* translators: 1: plugin name, 2: plugin version */
					__( 'Ready to install %1$s v%2$s. Call again with confirm_write=true to proceed.', 'vibe-ai' ),
					$api->name,
					$api->version
				),
			);
		}

		// Phase 2: Actual install.
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		// SQLite-integration sites (WordPress Studio, Playground) often don't
		// define DB_NAME in wp-config, so $wpdb->dbname is '' and the SQLite
		// driver bails when the upgrader triggers an information_schema query.
		// Any non-empty label is fine; SQLite doesn't use it as a real db name.
		global $wpdb;
		if ( empty( $wpdb->dbname ) ) {
			$wpdb->dbname = 'wordpress';
		}

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );

		try {
			$result = $upgrader->install( $api->download_link );
		} catch ( \Throwable $e ) {
			$skin_messages = $skin->get_upgrade_messages();
			return $this->error_result(
				sprintf(
					/* translators: 1: plugin name, 2: error message, 3: upgrader messages */
					__( 'Install of %1$s threw a fatal error: %2$s%3$s', 'vibe-ai' ),
					$api->name,
					$e->getMessage(),
					$skin_messages ? ' Upgrader log: ' . implode( ' / ', $skin_messages ) : ''
				)
			);
		}

		if ( is_wp_error( $result ) ) {
			$skin_messages = $skin->get_upgrade_messages();
			return $this->error_result(
				$result->get_error_message() . ( $skin_messages ? ' Upgrader log: ' . implode( ' / ', $skin_messages ) : '' )
			);
		}
		if ( ! $result ) {
			$messages = $skin->get_upgrade_messages();
			return $this->error_result( __( 'Install failed.', 'vibe-ai' ) . ( $messages ? ' Upgrader log: ' . implode( ' / ', $messages ) : '' ) );
		}

		// Optionally activate (matches real WP-CLI --activate flag).
		// Activation errors must surface to the caller: a failed activation
		// hook (e.g. plugin's dbDelta incompatible with SQLite) leaves the
		// plugin installed but inactive, and silently reporting success would
		// mislead the AI.
		$activated        = false;
		$activation_error = null;
		if ( ! empty( $flags['activate'] ) ) {
			$plugin_file = $upgrader->plugin_info();
			if ( ! $plugin_file ) {
				$activation_error = __( 'Could not determine the installed plugin file path.', 'vibe-ai' );
			} else {
				try {
					$activate_result = activate_plugin( $plugin_file );
				} catch ( \Throwable $e ) {
					$activate_result = new WP_Error( 'activation_fatal', $e->getMessage(), WPVibe_Error_Contract::data( 'wp_core', false ) );
				}
				if ( is_wp_error( $activate_result ) ) {
					$activation_error = $activate_result->get_error_message();
				} else {
					$activated = true;
				}
			}
		}

		// If --activate was requested but activation failed, report as a
		// failed install. The plugin file is on disk but the user did not get
		// the outcome they asked for.
		if ( ! empty( $flags['activate'] ) && ! $activated ) {
			return $this->error_result(
				sprintf(
					/* translators: 1: plugin name, 2: plugin version, 3: activation error */
					__( 'Installed %1$s v%2$s, but activation failed: %3$s The plugin is on disk but inactive. Activate it manually via wp-admin, or check whether the plugin is compatible with this environment (e.g. SQLite vs MySQL).', 'vibe-ai' ),
					$api->name,
					$api->version,
					$activation_error ?: __( 'unknown error', 'vibe-ai' )
				)
			);
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Plugin installed: {$slug}" . ( $activated ? ' (activated)' : '' ),
			'action_label' => 'Manage Plugins',
			'admin_url'    => admin_url( 'plugins.php' ),
		) );

		$msg = sprintf(
			/* translators: 1: plugin name, 2: plugin version */
			__( 'Installed %1$s v%2$s.', 'vibe-ai' ),
			$api->name,
			$api->version
		);
		if ( $activated ) {
			$msg .= ' ' . __( 'Plugin activated.', 'vibe-ai' );
		}

		return $this->success_result( array( 'message' => $msg ) );
	}

	private function handle_plugin_update( $positional, $flags, $confirm_write = false ) {
		if ( empty( $positional[0] ) ) {
			return $this->error_result( __( 'Plugin slug required.', 'vibe-ai' ) );
		}
		$file = $this->resolve_plugin_file( $positional[0] );
		if ( ! $file ) {
			/* translators: %s: plugin slug */
			return $this->error_result( sprintf( __( 'Plugin \'%s\' not found.', 'vibe-ai' ), $positional[0] ) );
		}

		// Self-update replaces this plugin's files while they serve the request: fatals with a 500 and never applies.
		if ( plugin_basename( WPVIBE_PLUGIN_DIR . 'vibe-ai.php' ) === $file ) {
			return $this->error_result( __( 'WPVibe cannot update itself over its own connection (the update would replace the plugin files serving this request). Update it from the Plugins screen in wp-admin, or enable auto-updates for it there.', 'vibe-ai' ) );
		}

		// Check for available update.
		wp_update_plugins();
		$update_data = get_site_transient( 'update_plugins' );
		if ( ! isset( $update_data->response[ $file ] ) ) {
			return $this->error_result( __( 'No update available for this plugin.', 'vibe-ai' ) );
		}
		$update = $update_data->response[ $file ];

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all = get_plugins();

		// Phase 1: Return info and require confirmation.
		if ( ! $confirm_write ) {
			return array(
				'exit_code'             => 0,
				'stdout'                => wp_json_encode( array(
					'name'            => $all[ $file ]['Name'],
					'current_version' => $all[ $file ]['Version'],
					'new_version'     => $update->new_version,
					'slug'            => $update->slug,
				), JSON_PRETTY_PRINT ),
				'stderr'                => '',
				'requires_confirmation' => true,
				'message'               => sprintf(
					/* translators: 1: plugin name, 2: current version, 3: new version */
					__( 'Ready to update %1$s from %2$s to %3$s. Call again with confirm_write=true to proceed.', 'vibe-ai' ),
					$all[ $file ]['Name'],
					$all[ $file ]['Version'],
					$update->new_version
				),
			);
		}

		// Phase 2: Actual update.
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = $upgrader->upgrade( $file );

		if ( is_wp_error( $result ) ) {
			return $this->error_result( $result->get_error_message() );
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Plugin updated: {$positional[0]}",
			'action_label' => 'Manage Plugins',
			'admin_url'    => admin_url( 'plugins.php' ),
		) );

		return $this->success_result( array(
			'message' => sprintf(
				/* translators: 1: plugin name, 2: new version */
				__( 'Updated %1$s to v%2$s.', 'vibe-ai' ),
				$all[ $file ]['Name'],
				$update->new_version
			),
		) );
	}

	private function handle_option_update( $positional, $flags ) {
		if ( count( $positional ) < 2 ) {
			return $this->error_result( __( 'Usage: option update {key} {value}', 'vibe-ai' ) );
		}
		$key   = $positional[0];

		if ( in_array( $key, self::BLOCKED_OPTIONS, true ) ) {
			return $this->error_result(
				sprintf(
					/* translators: %s: option key */
					__( 'Option \'%s\' is blocked for security. Update it via wp-admin.', 'vibe-ai' ),
					$key
				)
			);
		}

		$value = $positional[1];
		// Auto-decode JSON values.
		$decoded = json_decode( $value, true );
		if ( null !== $decoded ) {
			$value = $decoded;
		}

		// update_option returns false both when the write fails AND when the value
		// is unchanged. Read back and compare to distinguish a real failure from a
		// no-op. Mismatch = filter blocked it, DB rejected it, or a filter mutated
		// the stored value. String-form compare: JSON auto-decode yields int/bool
		// while WP stores scalars as strings, and that type skew is not a failure.
		update_option( $key, $value );
		$stored = get_option( $key );
		if ( (string) maybe_serialize( $stored ) !== (string) maybe_serialize( $value ) ) {
			return $this->error_result(
				sprintf(
					/* translators: %s: option key */
					__( 'Could not update option \'%s\'. The stored value does not match the requested value. The write may have been blocked by a pre_update_option filter or rejected by the database.', 'vibe-ai' ),
					$key
				)
			);
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Option updated: {$key}",
			'action_label' => 'Refresh',
		) );
		/* translators: %s: option key */
		return $this->success_result( array( 'message' => sprintf( __( 'Updated option \'%s\'.', 'vibe-ai' ), $key ) ) );
	}

	private function handle_option_add( $positional, $flags ) {
		if ( count( $positional ) < 2 ) {
			return $this->error_result( __( 'Usage: option add <key> <value> [--autoload=no]', 'vibe-ai' ) );
		}
		$key = $positional[0];

		if ( in_array( $key, self::BLOCKED_OPTIONS, true ) ) {
			return $this->error_result( $this->blocked_option_message( $key, 'added' ) );
		}

		// Don't overwrite existing — match real wp-cli option add behavior.
		if ( null !== get_option( $key, null ) ) {
			/* translators: %s: option key */
			return $this->error_result( sprintf( __( 'Option \'%s\' already exists. Use option update to change it.', 'vibe-ai' ), $key ) );
		}

		$value = $positional[1];
		$decoded = json_decode( $value, true );
		if ( null !== $decoded ) {
			$value = $decoded;
		}

		$autoload = 'yes';
		if ( isset( $flags['autoload'] ) ) {
			$autoload = ( 'no' === $flags['autoload'] || false === $flags['autoload'] ) ? 'no' : 'yes';
		}

		$ok = add_option( $key, $value, '', $autoload );
		if ( ! $ok ) {
			return $this->error_result( __( 'Failed to add option.', 'vibe-ai' ) );
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Option added: {$key}",
			'action_label' => 'Refresh',
		) );
		/* translators: %s: option key */
		return $this->success_result( array( 'message' => sprintf( __( 'Added option \'%s\' (autoload=%s).', 'vibe-ai' ), $key, $autoload ) ) );
	}

	private function handle_option_delete( $positional, $flags ) {
		if ( empty( $positional[0] ) ) {
			return $this->error_result( __( 'Option key required. Usage: option delete <key>', 'vibe-ai' ) );
		}
		$key = $positional[0];

		// HARD-BLOCK — these options are never approval-gated. No legitimate AI workflow needs to delete them.
		if ( in_array( $key, self::BLOCKED_OPTIONS, true ) ) {
			return $this->error_result( $this->blocked_option_message( $key, 'deleted' ) );
		}

		if ( null === get_option( $key, null ) ) {
			/* translators: %s: option key */
			return $this->error_result( sprintf( __( 'Option \'%s\' not found.', 'vibe-ai' ), $key ) );
		}

		$ok = delete_option( $key );
		if ( ! $ok ) {
			return $this->error_result( __( 'Failed to delete option.', 'vibe-ai' ) );
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Option deleted: {$key}",
			'action_label' => 'Refresh',
		) );
		/* translators: %s: option key */
		return $this->success_result( array( 'message' => sprintf( __( 'Deleted option \'%s\'.', 'vibe-ai' ), $key ) ) );
	}

	private function handle_transient_delete( $positional, $flags ) {
		if ( empty( $positional[0] ) && empty( $flags['all'] ) && empty( $flags['expired'] ) ) {
			return $this->error_result( __( 'Usage: transient delete <name> | --all | --expired', 'vibe-ai' ) );
		}

		if ( ! empty( $flags['expired'] ) ) {
			$count = $this->purge_expired_transients();
			WPVibe_Change_Tracker::mark( array( 'summary' => "Expired transients purged: {$count}", 'action_label' => 'Refresh' ) );
			/* translators: %d: number of expired transients deleted */
			return $this->success_result( array( 'message' => sprintf( __( 'Deleted %d expired transient(s).', 'vibe-ai' ), $count ) ) );
		}

		if ( ! empty( $flags['all'] ) ) {
			$count = $this->delete_all_transients();
			WPVibe_Change_Tracker::mark( array( 'summary' => "All transients deleted: {$count}", 'action_label' => 'Refresh' ) );
			/* translators: %d: number of transients deleted */
			return $this->success_result( array( 'message' => sprintf( __( 'Deleted %d transient(s).', 'vibe-ai' ), $count ) ) );
		}

		$name = $positional[0];
		$ok   = delete_transient( $name );
		if ( ! $ok ) {
			/* translators: %s: transient name */
			return $this->error_result( sprintf( __( 'Transient \'%s\' not found or already expired.', 'vibe-ai' ), $name ) );
		}

		WPVibe_Change_Tracker::mark( array( 'summary' => "Transient deleted: {$name}", 'action_label' => 'Refresh' ) );
		/* translators: %s: transient name */
		return $this->success_result( array( 'message' => sprintf( __( 'Deleted transient \'%s\'.', 'vibe-ai' ), $name ) ) );
	}

	private function handle_transient_list( $positional, $flags ) {
		global $wpdb;
		$search = isset( $flags['search'] ) ? $flags['search'] : '%';
		$search = str_replace( array( '*', '?' ), array( '%', '_' ), $search );
		$pattern = '_transient_' . ltrim( $search, '_' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s ORDER BY option_name LIMIT 200",
				$pattern,
				'_transient_timeout_%'
			),
			ARRAY_A
		);

		$results = array();
		foreach ( $rows as $row ) {
			$name = preg_replace( '/^_transient_/', '', $row['option_name'] );
			$timeout = get_option( '_transient_timeout_' . $name );
			$results[] = array(
				'name'       => $name,
				'expires_at' => $timeout ? gmdate( 'Y-m-d H:i:s', (int) $timeout ) : null,
				'expired'    => $timeout && (int) $timeout < time(),
			);
		}

		return $this->success_result( $this->filter_fields( $results, $flags ) );
	}

	private function purge_expired_transients() {
		global $wpdb;
		$now = time();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$expired = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
				'_transient_timeout_%',
				$now
			)
		);
		$count = 0;
		foreach ( $expired as $timeout_name ) {
			$name = preg_replace( '/^_transient_timeout_/', '', $timeout_name );
			if ( delete_transient( $name ) ) {
				$count++;
			}
		}
		return $count;
	}

	private function delete_all_transients() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$names = $wpdb->get_col(
			$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s", '_transient_%', '_transient_timeout_%' )
		);
		$count = 0;
		foreach ( $names as $option_name ) {
			$name = preg_replace( '/^_transient_/', '', $option_name );
			if ( delete_transient( $name ) ) {
				$count++;
			}
		}
		return $count;
	}

	private function handle_user_delete( $positional, $flags ) {
		if ( empty( $positional[0] ) ) {
			return $this->error_result( __( 'User identifier required. Usage: user delete <id|login|email> [<id>...] [--reassign=<user>]', 'vibe-ai' ) );
		}

		$reassign = null;
		if ( ! empty( $flags['reassign'] ) ) {
			$ra = is_numeric( $flags['reassign'] )
				? get_user_by( 'id', (int) $flags['reassign'] )
				: get_user_by( 'login', $flags['reassign'] );
			if ( ! $ra ) {
				/* translators: %s: user identifier */
				return $this->error_result( sprintf( __( 'Reassign target \'%s\' not found.', 'vibe-ai' ), $flags['reassign'] ) );
			}
			$reassign = $ra->ID;
		}

		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}

		// Lockout protection: never delete the last administrator.
		$admin_ids = array_map( 'intval', (array) get_users( array( 'role' => 'administrator', 'fields' => 'ID', 'number' => -1 ) ) );
		$admins_remaining = count( $admin_ids );

		$idents  = $positional;
		$results = array();
		$ok      = 0;
		foreach ( $idents as $ident ) {
			$user = is_numeric( $ident )
				? get_user_by( 'id', (int) $ident )
				: ( is_email( $ident ) ? get_user_by( 'email', $ident ) : get_user_by( 'login', $ident ) );
			if ( ! $user ) {
				$results[] = array( 'target' => $ident, 'status' => 'error', 'error' => 'not found' );
				continue;
			}
			if ( in_array( (int) $user->ID, $admin_ids, true ) ) {
				if ( $admins_remaining <= 1 ) {
					$results[] = array( 'target' => $user->user_login, 'id' => $user->ID, 'status' => 'error', 'error' => __( 'refused: this is the last administrator (lockout protection)', 'vibe-ai' ) );
					continue;
				}
				$admins_remaining--;
			}
			if ( wp_delete_user( $user->ID, $reassign ) ) {
				$ok++;
				$results[] = array( 'target' => $user->user_login, 'id' => $user->ID, 'status' => 'deleted' );
			} else {
				$results[] = array( 'target' => $user->user_login, 'id' => $user->ID, 'status' => 'error', 'error' => 'delete failed' );
			}
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => count( $idents ) > 1 ? "Users deleted: {$ok}/" . count( $idents ) : "User deleted: {$results[0]['target']}",
			'action_label' => 'Manage Users',
			'admin_url'    => admin_url( 'users.php' ),
		) );

		if ( 1 === count( $idents ) ) {
			$only = $results[0];
			if ( 'error' === $only['status'] ) {
				/* translators: 1: user identifier, 2: error message */
				return $this->error_result( sprintf( __( 'User \'%1$s\': %2$s', 'vibe-ai' ), $only['target'], $only['error'] ) );
			}
			return $this->success_result( array(
				/* translators: 1: user login, 2: user ID */
				'message'       => sprintf( __( 'Deleted user \'%1$s\' (#%2$d).', 'vibe-ai' ), $only['target'], $only['id'] ),
				'reassigned_to' => $reassign,
			) );
		}

		return $this->success_result( array(
			/* translators: 1: success count, 2: total */
			'message'       => sprintf( __( 'Deleted %1$d of %2$d users.', 'vibe-ai' ), $ok, count( $idents ) ),
			'succeeded'     => $ok,
			'total'         => count( $idents ),
			'reassigned_to' => $reassign,
			'results'       => $results,
		) );
	}

	private function handle_plugin_uninstall( $positional, $flags ) {
		if ( empty( $positional[0] ) ) {
			return $this->error_result( __( 'Plugin slug required. Usage: plugin uninstall <slug> [<slug>...]', 'vibe-ai' ) );
		}

		if ( ! function_exists( 'delete_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$slugs   = $positional;
		$results = array();
		$ok      = 0;
		foreach ( $slugs as $slug ) {
			$file = $this->resolve_plugin_file( $slug );
			if ( ! $file ) {
				$results[] = array( 'target' => $slug, 'status' => 'error', 'error' => 'not found' );
				continue;
			}
			if ( is_plugin_active( $file ) ) {
				deactivate_plugins( $file );
			}
			$result = delete_plugins( array( $file ) );
			if ( is_wp_error( $result ) ) {
				$results[] = array( 'target' => $slug, 'status' => 'error', 'error' => $result->get_error_message() );
				continue;
			}
			if ( false === $result ) {
				$results[] = array( 'target' => $slug, 'status' => 'error', 'error' => 'filesystem error' );
				continue;
			}
			$ok++;
			$results[] = array( 'target' => $slug, 'status' => 'uninstalled' );
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => count( $slugs ) > 1 ? "Plugins uninstalled: {$ok}/" . count( $slugs ) : "Plugin uninstalled: {$slugs[0]}",
			'action_label' => 'Manage Plugins',
			'admin_url'    => admin_url( 'plugins.php' ),
		) );

		if ( 1 === count( $slugs ) ) {
			$only = $results[0];
			if ( 'error' === $only['status'] ) {
				/* translators: 1: plugin slug, 2: error message */
				return $this->error_result( sprintf( __( 'Plugin \'%1$s\': %2$s', 'vibe-ai' ), $only['target'], $only['error'] ) );
			}
			/* translators: %s: plugin slug */
			return $this->success_result( array( 'message' => sprintf( __( 'Plugin \'%s\' uninstalled.', 'vibe-ai' ), $slugs[0] ) ) );
		}

		return $this->success_result( array(
			/* translators: 1: success count, 2: total */
			'message'   => sprintf( __( 'Uninstalled %1$d of %2$d plugins.', 'vibe-ai' ), $ok, count( $slugs ) ),
			'succeeded' => $ok,
			'total'     => count( $slugs ),
			'results'   => $results,
		) );
	}

	/**
	 * Per-post-type capability check.
	 *
	 * wp_insert_post / wp_update_post / update_post_meta do NOT enforce
	 * capabilities on their own — only the REST controller layer does. Since
	 * this CLI dispatcher bypasses that, we have to gate each mutation
	 * ourselves or a user with bare `edit_posts` could create/publish/edit
	 * post types they have no business touching.
	 *
	 * @param string      $post_type  Slug.
	 * @param string      $action     'create' | 'update' | 'delete'.
	 * @param int|null    $post_id    Required for update + delete.
	 * @param string|null $new_status post_status the request is moving toward (publish triggers the publish_posts check).
	 * @return true|WP_Error
	 */
	private function check_post_caps( $post_type, $action, $post_id = null, $new_status = null ) {
		$pt_obj = get_post_type_object( $post_type );
		if ( ! $pt_obj ) {
			return new WP_Error( 'invalid_post_type', sprintf(
				/* translators: %s: post type slug */
				__( 'Unknown post type: %s', 'vibe-ai' ),
				$post_type
			), WPVibe_Error_Contract::data( 'invalid_input', false, array( 'post_type' => $post_type ) ) );
		}
		// manage_options fallback: CPTs with custom capability mappings fail the
		// per-post meta-cap checks below even for admins, who can already reach
		// the same rows through the approval-gated db query path. An explicit
		// 'do_not_allow' in the post type's caps is intentional (e.g. HPOS
		// orders) and stays enforced for everyone.
		$admin = current_user_can( 'manage_options' );
		if ( 'create' === $action ) {
			$allowed = current_user_can( $pt_obj->cap->create_posts )
				|| ( $admin && 'do_not_allow' !== $pt_obj->cap->create_posts );
			if ( ! $allowed ) {
				return new WP_Error( 'forbidden', sprintf(
					/* translators: %s: post type label */
					__( 'You do not have permission to create %s.', 'vibe-ai' ),
					$pt_obj->labels->name
				), WPVibe_Error_Contract::data( 'capability_cpt_mapping', false, array( 'status' => 403, 'post_type' => $post_type, 'capability' => $pt_obj->cap->create_posts ) ) );
			}
		} else {
			$edit_allowed = current_user_can( 'edit_post', $post_id )
				|| ( $admin && 'do_not_allow' !== $pt_obj->cap->edit_posts );
			if ( ! $edit_allowed ) {
				return new WP_Error( 'forbidden', sprintf(
					/* translators: %d: post ID */
					__( 'You do not have permission to edit post #%d.', 'vibe-ai' ),
					$post_id
				), WPVibe_Error_Contract::data( 'capability_cpt_mapping', false, array( 'status' => 403, 'post_type' => $post_type, 'capability' => 'edit_post' ) ) );
			}
			$delete_allowed = current_user_can( 'delete_post', $post_id )
				|| ( $admin && 'do_not_allow' !== $pt_obj->cap->delete_posts );
			if ( 'delete' === $action && ! $delete_allowed ) {
				return new WP_Error( 'forbidden', sprintf(
					/* translators: %d: post ID */
					__( 'You do not have permission to delete post #%d.', 'vibe-ai' ),
					$post_id
				), WPVibe_Error_Contract::data( 'capability_cpt_mapping', false, array( 'status' => 403, 'post_type' => $post_type, 'capability' => 'delete_post' ) ) );
			}
		}
		$publish_allowed = current_user_can( $pt_obj->cap->publish_posts )
			|| ( $admin && 'do_not_allow' !== $pt_obj->cap->publish_posts );
		if ( 'publish' === $new_status && ! $publish_allowed ) {
			return new WP_Error( 'forbidden_publish', sprintf(
				/* translators: %s: post type label */
				__( 'You do not have permission to publish %s.', 'vibe-ai' ),
				$pt_obj->labels->name
			), WPVibe_Error_Contract::data( 'capability_cpt_mapping', false, array( 'status' => 403, 'post_type' => $post_type, 'capability' => $pt_obj->cap->publish_posts ) ) );
		}
		return true;
	}

	private function handle_post_create( $positional, $flags ) {
		$args = array(
			'post_title'    => $flags['post_title'] ?? __( 'Untitled', 'vibe-ai' ),
			'post_content'  => $flags['post_content'] ?? '',
			'post_status'   => $flags['post_status'] ?? 'draft',
			'post_type'     => $flags['post_type'] ?? 'post',
			'post_excerpt'  => $flags['post_excerpt'] ?? '',
			'post_author'   => get_current_user_id(),
		);
		if ( isset( $flags['post_name'] ) )      $args['post_name']      = $flags['post_name'];
		if ( isset( $flags['post_parent'] ) )     $args['post_parent']    = (int) $flags['post_parent'];
		if ( isset( $flags['menu_order'] ) )      $args['menu_order']     = (int) $flags['menu_order'];
		if ( isset( $flags['comment_status'] ) )  $args['comment_status'] = $flags['comment_status'];
		if ( isset( $flags['post_date'] ) ) {
			if ( false === strtotime( $flags['post_date'] ) ) {
				return $this->error_result( __( 'Invalid --post_date. Use a site-local date like "2025-03-01 09:00:00".', 'vibe-ai' ) );
			}
			// Site-local, matching real WP-CLI; wp_insert_post derives post_date_gmt.
			$args['post_date'] = $flags['post_date'];
		}

		$cap_check = $this->check_post_caps( $args['post_type'], 'create', null, $args['post_status'] );
		if ( is_wp_error( $cap_check ) ) {
			return $cap_check;
		}

		$id = wp_insert_post( $args, true );
		if ( is_wp_error( $id ) ) {
			return $this->error_result( $id->get_error_message() );
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Post created: #{$id} ({$args['post_type']})",
			'action_label' => 'Edit Post',
			'admin_url'    => admin_url( "post.php?post={$id}&action=edit" ),
		) );

		return $this->success_result( array(
			'ID'      => $id,
			/* translators: 1: post type, 2: post ID */
			'message' => sprintf( __( 'Created %1$s #%2$d.', 'vibe-ai' ), $args['post_type'], $id ),
		) );
	}

	private function handle_post_update( $positional, $flags ) {
		$ids = $this->positional_ids( $positional );
		if ( empty( $ids ) ) {
			return $this->error_result( __( 'Post ID required. Usage: post update <id> [<id>...] --post_title="New Title"', 'vibe-ai' ) );
		}

		$updatable = array( 'post_title', 'post_content', 'post_status', 'post_excerpt',
			'post_name', 'post_parent', 'menu_order', 'comment_status', 'post_type' );
		$fields = array();
		foreach ( $updatable as $field ) {
			if ( isset( $flags[ $field ] ) ) {
				$fields[ $field ] = $flags[ $field ];
			}
		}
		if ( empty( $fields ) ) {
			return $this->error_result( __( 'No fields to update. Use flags like --post_title, --post_content, --post_status.', 'vibe-ai' ) );
		}

		$results = array();
		$ok      = 0;
		foreach ( $ids as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				$results[] = array( 'id' => $post_id, 'status' => 'error', 'error' => 'not found' );
				continue;
			}
			$cap_check = $this->check_post_caps( $post->post_type, 'update', $post_id, $fields['post_status'] ?? null );
			if ( is_wp_error( $cap_check ) ) {
				$results[] = array( 'id' => $post_id, 'status' => 'error', 'error' => $cap_check->get_error_message() );
				continue;
			}
			$res = wp_update_post( array_merge( array( 'ID' => $post_id ), $fields ), true );
			if ( is_wp_error( $res ) ) {
				$results[] = array( 'id' => $post_id, 'status' => 'error', 'error' => $res->get_error_message() );
				continue;
			}
			$ok++;
			$results[] = array( 'id' => $post_id, 'status' => 'updated' );
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => count( $ids ) > 1 ? "Posts updated: {$ok}/" . count( $ids ) : "Post updated: #{$ids[0]}",
			'action_label' => 'Refresh',
		) );

		return $this->bulk_result( 'updated', $ok, $ids, $results );
	}

	private function handle_post_delete( $positional, $flags ) {
		$ids = $this->positional_ids( $positional );
		if ( empty( $ids ) ) {
			return $this->error_result( __( 'Post ID required. Usage: post delete <id> [<id>...] [--force]', 'vibe-ai' ) );
		}

		$force   = ! empty( $flags['force'] );
		$results = array();
		$ok      = 0;
		foreach ( $ids as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				$results[] = array( 'id' => $post_id, 'status' => 'error', 'error' => 'not found' );
				continue;
			}
			$cap_check = $this->check_post_caps( $post->post_type, 'delete', $post_id );
			if ( is_wp_error( $cap_check ) ) {
				$results[] = array( 'id' => $post_id, 'status' => 'error', 'error' => $cap_check->get_error_message() );
				continue;
			}
			$res = $force ? wp_delete_post( $post_id, true ) : wp_trash_post( $post_id );
			if ( ! $res ) {
				$results[] = array( 'id' => $post_id, 'status' => 'error', 'error' => 'delete failed' );
				continue;
			}
			$ok++;
			$results[] = array( 'id' => $post_id, 'status' => $force ? 'deleted' : 'trashed' );
		}

		$action = $force ? __( 'permanently deleted', 'vibe-ai' ) : __( 'trashed', 'vibe-ai' );
		WPVibe_Change_Tracker::mark( array(
			'summary'      => count( $ids ) > 1 ? "Posts {$action}: {$ok}/" . count( $ids ) : "Post {$action}: #{$ids[0]}",
			'action_label' => 'Refresh',
		) );

		return $this->bulk_result( $action, $ok, $ids, $results );
	}

	private function handle_post_meta_update( $positional, $flags ) {
		if ( count( $positional ) < 3 ) {
			return $this->error_result( __( 'Usage: post meta update <post_id> <key> <value>', 'vibe-ai' ) );
		}

		$post_id = (int) $positional[0];
		$post    = get_post( $post_id );
		if ( ! $post ) {
			/* translators: %s: post ID */
			return $this->error_result( sprintf( __( 'Post %s not found.', 'vibe-ai' ), $positional[0] ) );
		}

		$key = $positional[1];
		// Block protected meta keys (all '_'-prefixed, plus registered protected) unless --force.
		if ( empty( $flags['force'] ) && is_protected_meta( $key, 'post' ) ) {
			return $this->error_result(
				sprintf(
					/* translators: %s: meta key */
					__( 'Meta key \'%s\' is a protected/internal key. Use --force to override.', 'vibe-ai' ),
					$key
				)
			);
		}

		$cap_check = $this->check_post_caps( $post->post_type, 'update', $post_id );
		if ( is_wp_error( $cap_check ) ) {
			return $cap_check;
		}

		$value = $positional[2];
		// Auto-decode JSON values.
		$decoded = json_decode( $value, true );
		if ( null !== $decoded ) {
			$value = $decoded;
		}

		update_post_meta( $post_id, $key, $value );

		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Post meta updated: #{$post_id} → {$key}",
			'action_label' => 'Refresh',
		) );

		/* translators: 1: meta key, 2: post ID */
		return $this->success_result( array( 'message' => sprintf( __( 'Updated meta \'%1$s\' on post #%2$d.', 'vibe-ai' ), $key, $post_id ) ) );
	}

	private function handle_post_meta_delete( $positional, $flags ) {
		if ( count( $positional ) < 2 ) {
			return $this->error_result( __( 'Usage: post meta delete <post_id> <key>', 'vibe-ai' ) );
		}

		$post_id = (int) $positional[0];
		$post    = get_post( $post_id );
		if ( ! $post ) {
			/* translators: %s: post ID */
			return $this->error_result( sprintf( __( 'Post %s not found.', 'vibe-ai' ), $positional[0] ) );
		}

		$key = $positional[1];
		if ( empty( $flags['force'] ) && is_protected_meta( $key, 'post' ) ) {
			return $this->error_result(
				sprintf(
					/* translators: %s: meta key */
					__( 'Meta key \'%s\' is a protected/internal key. Use --force to override.', 'vibe-ai' ),
					$key
				)
			);
		}

		$cap_check = $this->check_post_caps( $post->post_type, 'update', $post_id );
		if ( is_wp_error( $cap_check ) ) {
			return $cap_check;
		}

		delete_post_meta( $post_id, $key );

		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Post meta deleted: #{$post_id} → {$key}",
			'action_label' => 'Refresh',
		) );

		/* translators: 1: meta key, 2: post ID */
		return $this->success_result( array( 'message' => sprintf( __( 'Deleted meta \'%1$s\' from post #%2$d.', 'vibe-ai' ), $key, $post_id ) ) );
	}

	private function handle_config_get( $positional, $flags ) {
		if ( empty( $positional[0] ) ) {
			return $this->error_result( __( 'Constant name required. Usage: config get <constant>', 'vibe-ai' ) );
		}
		$name = $positional[0];

		// Not a constant — special-cased via $wpdb (same value as `db prefix`).
		if ( 'table_prefix' === $name ) {
			global $wpdb;
			return array( 'exit_code' => 0, 'stdout' => $wpdb->prefix, 'stderr' => '' );
		}

		// Blocklist runs before the existence check so responses never leak
		// whether a secret is configured.
		$blocked_exact = array( 'DB_PASSWORD', 'DB_USER', 'DB_HOST' );
		if ( in_array( strtoupper( $name ), $blocked_exact, true ) || preg_match( '/KEY|SALT|SECRET|PASSWORD|TOKEN/i', $name ) ) {
			/* translators: %s: constant name */
			return $this->error_result( sprintf( __( 'Constant \'%s\' is blocked for security. Credentials, keys, salts, and secrets are never exposed to AI tools.', 'vibe-ai' ), $name ) );
		}

		if ( ! defined( $name ) ) {
			/* translators: %s: constant name */
			return $this->error_result( sprintf( __( 'The constant \'%s\' is not defined on this site.', 'vibe-ai' ), $name ) );
		}

		$value = constant( $name );
		return $this->success_result( array(
			'name'  => $name,
			'value' => $value,
			'type'  => strtolower( gettype( $value ) ),
		) );
	}

	private function handle_option_patch( $positional, $flags ) {
		$action = $positional[0] ?? '';
		if ( ! in_array( $action, array( 'insert', 'update', 'delete' ), true ) || empty( $positional[1] ) ) {
			return $this->error_result( __( 'Usage: option patch <insert|update|delete> <option> <key-path>... [<value>]', 'vibe-ai' ) );
		}
		$key = $positional[1];
		if ( in_array( $key, self::BLOCKED_OPTIONS, true ) ) {
			return $this->error_result( $this->blocked_option_message( $key, 'patched' ) );
		}

		$rest  = array_slice( $positional, 2 );
		$value = null;
		if ( 'delete' === $action ) {
			$path = $rest;
		} else {
			if ( count( $rest ) < 2 ) {
				return $this->error_result( __( 'Both a key path and a value are required. Usage: option patch <insert|update> <option> <key-path>... <value>', 'vibe-ai' ) );
			}
			$value   = array_pop( $rest );
			$decoded = json_decode( $value, true );
			if ( null !== $decoded ) {
				$value = $decoded;
			}
			$path = $rest;
		}
		if ( empty( $path ) ) {
			return $this->error_result( __( 'Key path required.', 'vibe-ai' ) );
		}

		$current = get_option( $key, null );
		if ( null === $current ) {
			/* translators: %s: option key */
			return $this->error_result( sprintf( __( 'Option \'%s\' not found.', 'vibe-ai' ), $key ) );
		}
		if ( ! is_array( $current ) && ! is_object( $current ) ) {
			/* translators: %s: option key */
			return $this->error_result( sprintf( __( 'Option \'%s\' is not an array or object; use `option update` for scalar values.', 'vibe-ai' ), $key ) );
		}

		$error   = null;
		$patched = $this->patch_structure( $current, $path, $action, $value, $error );
		if ( null !== $error ) {
			return $this->error_result( $error );
		}

		update_option( $key, $patched );
		$stored = get_option( $key );
		if ( (string) maybe_serialize( $stored ) !== (string) maybe_serialize( $patched ) ) {
			/* translators: %s: option key */
			return $this->error_result( sprintf( __( 'Could not patch option \'%s\'. The stored value does not match; a pre_update_option filter may have rejected the write.', 'vibe-ai' ), $key ) );
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Option patched: {$key} → " . implode( '.', $path ),
			'action_label' => 'Refresh',
		) );

		/* translators: 1: action, 2: key path, 3: option key */
		return $this->success_result( array( 'message' => sprintf( __( 'Patched (%1$s) \'%2$s\' in option \'%3$s\'.', 'vibe-ai' ), $action, implode( '.', $path ), $key ) ) );
	}

	/** Walk a nested array/stdClass along $path and apply insert/update/delete at the leaf. */
	private function patch_structure( $data, $path, $action, $value, &$error, $trail = '' ) {
		$segment = $path[0];
		$seg_key = is_numeric( $segment ) ? (int) $segment : $segment;
		$rest    = array_slice( $path, 1 );
		$is_obj  = is_object( $data );
		$where   = '' === $trail ? (string) $segment : $trail . '.' . $segment;

		if ( ! is_array( $data ) && ! $is_obj ) {
			/* translators: %s: key path position */
			$error = sprintf( __( 'Cannot descend into a non-array value at \'%s\'.', 'vibe-ai' ), $trail ?: (string) $segment );
			return $data;
		}

		$exists = $is_obj ? property_exists( $data, (string) $seg_key ) : array_key_exists( $seg_key, $data );

		if ( empty( $rest ) ) {
			if ( 'insert' === $action && $exists ) {
				/* translators: %s: key path */
				$error = sprintf( __( 'Key \'%s\' already exists. Use `option patch update` to change it.', 'vibe-ai' ), $where );
				return $data;
			}
			if ( 'insert' !== $action && ! $exists ) {
				/* translators: %s: key path */
				$error = sprintf( __( 'No data exists at key path \'%s\'.', 'vibe-ai' ), $where );
				return $data;
			}
			if ( 'delete' === $action ) {
				if ( $is_obj ) {
					unset( $data->{$seg_key} );
				} else {
					unset( $data[ $seg_key ] );
				}
			} elseif ( $is_obj ) {
				$data->{$seg_key} = $value;
			} else {
				$data[ $seg_key ] = $value;
			}
			return $data;
		}

		if ( ! $exists ) {
			/* translators: %s: key path */
			$error = sprintf( __( 'No data exists at key path \'%s\'.', 'vibe-ai' ), $where );
			return $data;
		}
		if ( $is_obj ) {
			$data->{$seg_key} = $this->patch_structure( $data->{$seg_key}, $rest, $action, $value, $error, $where );
		} else {
			$data[ $seg_key ] = $this->patch_structure( $data[ $seg_key ], $rest, $action, $value, $error, $where );
		}
		return $data;
	}

	/**
	 * Detector table for the unified cache purge: each entry knows whether its
	 * plugin is present and how to call its own purge API ("own the brains,
	 * rent the muscle" — we orchestrate, never reimplement).
	 */
	private function cache_purge_targets() {
		return array(
			'litespeed'      => array(
				'name'   => 'LiteSpeed Cache',
				'active' => defined( 'LSCWP_V' ) || is_plugin_active( 'litespeed-cache/litespeed-cache.php' ),
				'purge'  => function () {
					do_action( 'litespeed_purge_all' );
					return true;
				},
			),
			'elementor'      => array(
				'name'   => 'Elementor CSS cache',
				'active' => class_exists( '\Elementor\Plugin' ),
				'purge'  => function () {
					if ( isset( \Elementor\Plugin::$instance->files_manager ) ) {
						\Elementor\Plugin::$instance->files_manager->clear_cache();
						return true;
					}
					return false;
				},
			),
			'wp-rocket'      => array(
				'name'   => 'WP Rocket',
				'active' => function_exists( 'rocket_clean_domain' ),
				'purge'  => function () {
					rocket_clean_domain();
					return true;
				},
			),
			'sg-optimizer'   => array(
				'name'   => 'SG Optimizer (Speed Optimizer)',
				'active' => function_exists( 'sg_cachepress_purge_cache' ),
				'purge'  => function () {
					return false !== sg_cachepress_purge_cache();
				},
			),
			'wp-super-cache' => array(
				'name'   => 'WP Super Cache',
				'active' => function_exists( 'wp_cache_clear_cache' ),
				'purge'  => function () {
					wp_cache_clear_cache();
					return true;
				},
			),
			'w3-total-cache' => array(
				'name'   => 'W3 Total Cache',
				'active' => function_exists( 'w3tc_flush_all' ),
				'purge'  => function () {
					w3tc_flush_all();
					return true;
				},
			),
			'breeze'         => array(
				'name'   => 'Breeze',
				'active' => class_exists( 'Breeze_PurgeCache' ) || is_plugin_active( 'breeze/breeze.php' ),
				'purge'  => function () {
					do_action( 'breeze_clear_all_cache' );
					return true;
				},
			),
		);
	}

	const CACHE_PURGE_ALIASES = array(
		'litespeed-purge'      => 'litespeed',
		'elementor flush-css'  => 'elementor',
		'elementor flush_css'  => 'elementor',
		'rocket clean'         => 'wp-rocket',
		'sg purge'             => 'sg-optimizer',
		'super-cache flush'    => 'wp-super-cache',
		'w3-total-cache flush' => 'w3-total-cache',
		'breeze purge'         => 'breeze',
	);

	private function handle_cache_purge( $positional, $flags ) {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$targets = $this->cache_purge_targets();

		// Third-party alias spellings purge only the plugin they name.
		$alias = self::CACHE_PURGE_ALIASES[ $this->current_command ] ?? null;
		if ( null !== $alias ) {
			$target = $targets[ $alias ];
			if ( ! $target['active'] ) {
				return $this->error_result(
					sprintf(
						/* translators: %s: cache plugin name */
						__( '%s is not active on this site. Run `cache purge` to purge whatever cache is installed, or `cache flush` for the object cache.', 'vibe-ai' ),
						$target['name']
					)
				);
			}
			try {
				$ok = call_user_func( $target['purge'] );
			} catch ( \Throwable $e ) {
				/* translators: 1: cache plugin name, 2: error message */
				return $this->error_result( sprintf( __( 'Purging %1$s failed: %2$s', 'vibe-ai' ), $target['name'], $e->getMessage() ) );
			}
			if ( ! $ok ) {
				/* translators: %s: cache plugin name */
				return $this->error_result( sprintf( __( 'Purging %s failed (its purge API was unavailable).', 'vibe-ai' ), $target['name'] ) );
			}
			WPVibe_Change_Tracker::mark( array( 'summary' => "Cache purged: {$target['name']}", 'action_label' => 'View Site', 'url' => home_url( '/' ) ) );
			/* translators: %s: cache plugin name */
			return $this->success_result( array( 'message' => sprintf( __( '%s cache purged.', 'vibe-ai' ), $target['name'] ) ) );
		}

		// `cache purge`: purge every detected cache plugin + the object cache.
		$purged = array();
		$failed = array();
		foreach ( $targets as $target ) {
			if ( ! $target['active'] ) {
				continue;
			}
			try {
				$ok = call_user_func( $target['purge'] );
			} catch ( \Throwable $e ) {
				$failed[] = array( 'name' => $target['name'], 'error' => $e->getMessage() );
				continue;
			}
			if ( $ok ) {
				$purged[] = $target['name'];
			} else {
				$failed[] = array( 'name' => $target['name'], 'error' => __( 'purge API unavailable', 'vibe-ai' ) );
			}
		}
		$object_cache_flushed = (bool) wp_cache_flush();

		if ( $purged ) {
			/* translators: %s: plugin names */
			$message = sprintf( __( 'Purged: %s. Object cache flushed.', 'vibe-ai' ), implode( ', ', $purged ) );
		} else {
			$message = __( 'No known cache plugin detected; flushed the WordPress object cache.', 'vibe-ai' );
		}

		WPVibe_Change_Tracker::mark( array( 'summary' => 'Caches purged', 'action_label' => 'View Site', 'url' => home_url( '/' ) ) );

		$data = array(
			'message'              => $message,
			'purged'               => $purged,
			'object_cache_flushed' => $object_cache_flushed,
		);
		if ( $failed ) {
			$data['failed'] = $failed;
		}
		return $this->success_result( $data );
	}

	private function handle_cache_flush( $positional, $flags ) {
		$ok = wp_cache_flush();
		if ( false === $ok ) {
			return $this->error_result(
				__( 'wp_cache_flush() returned false. A persistent object cache backend (Redis, Memcached, etc.) may be disconnected or misconfigured.', 'vibe-ai' )
			);
		}
		WPVibe_Change_Tracker::mark( array(
			'summary'      => 'Cache flushed',
			'action_label' => 'Refresh',
		) );
		return $this->success_result( array( 'message' => __( 'Object cache flushed.', 'vibe-ai' ) ) );
	}

	private function handle_rewrite_flush( $positional, $flags ) {
		flush_rewrite_rules();
		WPVibe_Change_Tracker::mark( array(
			'summary'      => 'Rewrite rules flushed',
			'action_label' => 'Refresh',
		) );
		return $this->success_result( array( 'message' => __( 'Rewrite rules flushed.', 'vibe-ai' ) ) );
	}

	private function handle_not_implemented( $positional, $flags ) {
		return $this->error_result( __( 'This command is not yet implemented via native dispatch. Use the WordPress admin dashboard.', 'vibe-ai' ) );
	}

	// ------------------------------------------------------------------
	// search-replace (serialized-data-aware, gated)
	// ------------------------------------------------------------------

	/** Set true when replace_in_value hits a __PHP_Incomplete_Class; the row is skipped. */
	private $sr_incomplete = false;
	private $sr_skipped_serialized = 0;
	private $sr_timed_out = false;

	private function handle_search_replace( $positional, $flags ) {
		global $wpdb;

		if ( ! empty( $flags['regex'] ) ) {
			return $this->error_result( __( '--regex is not supported by the WPVibe emulation. Use a literal search string.', 'vibe-ai' ) );
		}
		if ( ! empty( $flags['export'] ) || ! empty( $flags['log'] ) || ! empty( $flags['network'] ) ) {
			return $this->error_result( __( '--export, --log, and --network are not supported by the WPVibe emulation.', 'vibe-ai' ) );
		}
		if ( count( $positional ) < 2 ) {
			return $this->error_result( __( 'Usage: search-replace <old> <new> [<table>...] [--dry-run]', 'vibe-ai' ) );
		}
		$old = $positional[0];
		$new = $positional[1];
		if ( '' === $old ) {
			return $this->error_result( __( 'The <old> search string cannot be empty.', 'vibe-ai' ) );
		}
		if ( $old === $new ) {
			return $this->error_result( __( 'Replacement value is identical to search value; nothing to do.', 'vibe-ai' ) );
		}

		$dry_run = ! empty( $flags['dry_run'] );
		if ( ! $dry_run && ! $this->skip_destructive ) {
			// classify_destructive should have caught this; defense-in-depth.
			return $this->error_result( __( 'search-replace requires explicit approval. Run with --dry-run to preview.', 'vibe-ai' ) );
		}

		$tables = $this->resolve_search_replace_tables( array_slice( $positional, 2 ), $flags );
		if ( is_wp_error( $tables ) ) {
			return $this->error_result( $tables->get_error_message() );
		}

		$skip_columns    = array_filter( array_map( 'trim', explode( ',', (string) ( $flags['skip_columns'] ?? '' ) ) ) );
		$include_columns = array_filter( array_map( 'trim', explode( ',', (string) ( $flags['include_columns'] ?? '' ) ) ) );
		$guid_skipped    = false;
		if ( empty( $flags['include_guids'] ) && ! in_array( 'guid', $include_columns, true ) ) {
			// WP best practice: GUIDs are permanent identifiers, not URLs.
			$skip_columns[] = 'guid';
			$guid_skipped   = true;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		// We run inside a REST request, not a shell: keep a hard budget and
		// report completed vs remaining tables so the AI can re-run scoped.
		$deadline = microtime( true ) + 240;

		$this->sr_skipped_serialized = 0;
		$this->sr_timed_out          = false;
		$report    = array();
		$total     = 0;
		$completed = array();
		$remaining = array();

		foreach ( $tables as $i => $table ) {
			if ( microtime( true ) > $deadline ) {
				$this->sr_timed_out = true;
			}
			if ( $this->sr_timed_out ) {
				$remaining = array_slice( $tables, $i );
				break;
			}
			list( $primary_keys, $text_columns ) = $this->table_columns( $table );
			if ( empty( $primary_keys ) ) {
				$report[] = array( 'table' => $table, 'column' => '', 'count' => 0, 'note' => __( 'Skipped: no primary key.', 'vibe-ai' ) );
				$completed[] = $table;
				continue;
			}
			foreach ( $text_columns as $col ) {
				if ( in_array( $col, $skip_columns, true ) || in_array( "$table.$col", $skip_columns, true ) ) {
					continue;
				}
				if ( ! empty( $include_columns ) && ! in_array( $col, $include_columns, true ) && ! in_array( "$table.$col", $include_columns, true ) ) {
					continue;
				}
				$count = $this->search_replace_column( $table, $col, $primary_keys, $old, $new, $dry_run, $deadline );
				if ( $count > 0 ) {
					$report[] = array( 'table' => $table, 'column' => $col, 'count' => $count );
				}
				$total += $count;
				if ( $this->sr_timed_out ) {
					break;
				}
			}
			if ( $this->sr_timed_out ) {
				$remaining = array_slice( $tables, $i );
				break;
			}
			$completed[] = $table;
		}

		if ( ! $dry_run && $total > 0 ) {
			WPVibe_Change_Tracker::mark( array(
				'summary'      => "search-replace: {$total} replacement(s)",
				'action_label' => 'View Site',
				'url'          => home_url( '/' ),
			) );
		}

		$message = $dry_run
			/* translators: %d: replacement count */
			? sprintf( __( '%d replacement(s) to be made.', 'vibe-ai' ), $total )
			/* translators: %d: replacement count */
			: sprintf( __( 'Made %d replacement(s).', 'vibe-ai' ), $total );
		if ( ! $dry_run && $total > 0 && function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
			$message .= ' ' . __( 'A persistent object cache is active — run `cache flush` so stale values are not served.', 'vibe-ai' );
		}

		$data = array(
			'dry_run'      => $dry_run,
			'total'        => $total,
			'report'       => $report,
			'message'      => $message,
		);
		if ( $guid_skipped ) {
			$data['guid_note'] = __( 'The guid column was skipped (WordPress best practice). Pass --include-guids to replace inside GUIDs too.', 'vibe-ai' );
		}
		if ( $this->sr_skipped_serialized > 0 ) {
			/* translators: %d: skipped row count */
			$data['skipped_serialized_rows'] = $this->sr_skipped_serialized;
			$data['skipped_serialized_note'] = __( 'Rows whose serialized data references PHP classes that are not loadable were skipped to avoid corruption.', 'vibe-ai' );
		}
		if ( $this->sr_timed_out ) {
			$data['timed_out']        = true;
			$data['tables_completed'] = $completed;
			$data['tables_remaining'] = $remaining;
			$data['note']             = __( 'Time budget exceeded. Re-run the same command scoped to the remaining tables to finish.', 'vibe-ai' );
		}

		$result = $this->success_result( $data );
		if ( $dry_run ) {
			$result['tier'] = 'read';
		}
		return $result;
	}

	private function resolve_search_replace_tables( $table_args, $flags ) {
		global $wpdb;
		$all = $wpdb->get_col( 'SHOW TABLES' );
		if ( ! is_array( $all ) ) {
			$all = array();
		}
		if ( ! empty( $table_args ) ) {
			$resolved = array();
			foreach ( $table_args as $arg ) {
				$arg = str_replace( '{prefix}', $wpdb->prefix, $arg );
				if ( false !== strpos( $arg, '*' ) || false !== strpos( $arg, '?' ) ) {
					$matched = array();
					foreach ( $all as $t ) {
						if ( fnmatch( $arg, $t ) ) {
							$matched[] = $t;
						}
					}
					if ( empty( $matched ) ) {
						/* translators: %s: table pattern */
						return new WP_Error( 'no_tables', sprintf( __( 'No tables match "%s".', 'vibe-ai' ), $arg ), WPVibe_Error_Contract::data( 'not_found', false ) );
					}
					$resolved = array_merge( $resolved, $matched );
				} elseif ( in_array( $arg, $all, true ) ) {
					$resolved[] = $arg;
				} else {
					/* translators: %s: table name */
					return new WP_Error( 'no_table', sprintf( __( 'Table "%s" does not exist.', 'vibe-ai' ), $arg ), WPVibe_Error_Contract::data( 'not_found', false ) );
				}
			}
			$tables = array_values( array_unique( $resolved ) );
		} elseif ( ! empty( $flags['all_tables'] ) ) {
			$tables = $all;
		} else {
			$tables = array();
			foreach ( $all as $t ) {
				if ( 0 === strpos( $t, $wpdb->prefix ) ) {
					$tables[] = $t;
				}
			}
		}

		$skip_tables = array_filter( array_map( 'trim', explode( ',', (string) ( $flags['skip_tables'] ?? '' ) ) ) );
		if ( $skip_tables ) {
			$tables = array_values( array_filter( $tables, function ( $t ) use ( $skip_tables ) {
				foreach ( $skip_tables as $skip ) {
					if ( $t === $skip || fnmatch( $skip, $t ) ) {
						return false;
					}
				}
				return true;
			} ) );
		}

		if ( empty( $tables ) ) {
			return new WP_Error( 'no_tables', __( 'No tables in scope for search-replace.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'not_found', false ) );
		}
		return $tables;
	}

	/** DESCRIBE a table: [primary key columns, text-family columns (char/varchar/text)]. */
	private function table_columns( $table ) {
		global $wpdb;
		$primary = array();
		$text    = array();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results( 'DESCRIBE ' . $this->esc_sql_ident( $table ) ); // nosemgrep: direct-db-query
		foreach ( (array) $results as $col ) {
			if ( isset( $col->Key ) && 'PRI' === $col->Key ) {
				$primary[] = $col->Field;
			}
			if ( isset( $col->Type ) && ( false !== stripos( $col->Type, 'char' ) || false !== stripos( $col->Type, 'text' ) ) ) {
				$text[] = $col->Field;
			}
		}
		return array( $primary, $text );
	}

	/**
	 * Replace within one table column, chunked by primary key so large tables
	 * never load whole. Mirrors wp-cli's php_handle_col (the --precise path —
	 * always serialized-safe, never blind SQL UPDATE).
	 */
	private function search_replace_column( $table, $col, $primary_keys, $old, $new, $dry_run, $deadline ) {
		global $wpdb;

		$count     = 0;
		$table_sql = $this->esc_sql_ident( $table );
		$col_sql   = $this->esc_sql_ident( $col );
		$old_json  = $this->json_encode_strip_quotes( $old );
		$new_json  = $this->json_encode_strip_quotes( $new );

		$match = $col_sql . $wpdb->prepare( ' LIKE BINARY %s', '%' . $wpdb->esc_like( $old ) . '%' );
		if ( $old_json !== $old ) {
			$match = '( ' . $match . ' OR ' . $col_sql . $wpdb->prepare( ' LIKE BINARY %s', '%' . $wpdb->esc_like( $old_json ) . '%' ) . ' )';
		}

		$single_pk = ( 1 === count( $primary_keys ) );
		$pk_sql    = implode( ', ', array_map( array( $this, 'esc_sql_ident' ), $primary_keys ) );
		$chunk     = 1000;
		$last_key  = null;
		$passes    = 0;

		while ( true ) {
			if ( microtime( true ) > $deadline ) {
				$this->sr_timed_out = true;
				break;
			}
			$where = 'WHERE ' . $match;
			if ( $single_pk && null !== $last_key ) {
				$where .= ' AND ' . $pk_sql . ' > ' . $this->esc_sql_value( $last_key );
			}
			$order = $single_pk ? " ORDER BY {$pk_sql} ASC" : '';
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results( "SELECT {$pk_sql} FROM {$table_sql} {$where}{$order} LIMIT {$chunk}" ); // nosemgrep: direct-db-query
			if ( empty( $rows ) ) {
				break;
			}

			$count_before = $count;
			foreach ( $rows as $keys ) {
				$where_parts = array();
				foreach ( (array) $keys as $k => $v ) {
					$where_parts[] = $this->esc_sql_ident( $k ) . ' = ' . $this->esc_sql_value( $v );
				}
				$where_row = implode( ' AND ', $where_parts );
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$value = $wpdb->get_var( "SELECT {$col_sql} FROM {$table_sql} WHERE {$where_row}" ); // nosemgrep: direct-db-query
				if ( null === $value || '' === $value ) {
					continue;
				}
				$this->sr_incomplete = false;
				$replaced            = $this->replace_in_value( $value, $old, $new, $old_json, $new_json );
				if ( $this->sr_incomplete ) {
					$this->sr_skipped_serialized++;
					continue;
				}
				if ( $replaced === $value || gettype( $replaced ) !== gettype( $value ) ) {
					continue;
				}
				if ( $dry_run ) {
					$count++;
					continue;
				}
				$update_where = array();
				foreach ( (array) $keys as $k => $v ) {
					$update_where[ $k ] = $v;
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$ok = $wpdb->update( $table, array( $col => $replaced ), $update_where );
				if ( false !== $ok ) {
					$count++;
				}
			}

			if ( $single_pk ) {
				$last_row = end( $rows );
				$pk_name  = $primary_keys[0];
				$last_key = $last_row->{$pk_name};
				continue;
			}

			// Composite PK: live runs converge because replaced rows stop
			// matching the LIKE. Dry runs would loop forever, so single capped
			// pass; live runs bail when a pass makes no progress.
			if ( $dry_run || $count === $count_before || ++$passes > 500 ) {
				break;
			}
		}

		return $count;
	}

	private function replace_in_value( $data, $old, $new, $old_json, $new_json, $depth = 0 ) {
		if ( $depth > 64 ) {
			return $data;
		}
		if ( is_string( $data ) ) {
			if ( 'b:0;' === trim( $data ) ) {
				return $data;
			}
			$unserialized = false;
			if ( function_exists( 'is_serialized' ) && is_serialized( $data ) ) {
				$error_level = error_reporting();
				error_reporting( $error_level & ~E_NOTICE & ~E_WARNING ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
				// stdClass only: WordPress uses it everywhere (theme mods, widget
				// data); arbitrary classes would deserialize as side effects.
				$unserialized = @unserialize( $data, array( 'allowed_classes' => array( 'stdClass' ) ) ); // phpcs:ignore
				error_reporting( $error_level ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			}
			if ( false !== $unserialized ) {
				$inner = $this->replace_in_value( $unserialized, $old, $new, $old_json, $new_json, $depth + 1 );
				if ( $this->sr_incomplete ) {
					return $data;
				}
				return serialize( $inner ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
			}
			$data = str_replace( $old, $new, $data );
			if ( $old_json !== $old ) {
				// Raw JSON in the DB (font data, block attrs) stores escaped slashes.
				$data = str_replace( $old_json, $new_json, $data );
			}
			return $data;
		}
		if ( is_array( $data ) ) {
			foreach ( $data as $k => $v ) {
				$data[ $k ] = $this->replace_in_value( $v, $old, $new, $old_json, $new_json, $depth + 1 );
			}
			return $data;
		}
		if ( $data instanceof \__PHP_Incomplete_Class ) {
			$this->sr_incomplete = true;
			return $data;
		}
		if ( is_object( $data ) ) {
			foreach ( get_object_vars( $data ) as $k => $v ) {
				$data->$k = $this->replace_in_value( $v, $old, $new, $old_json, $new_json, $depth + 1 );
			}
			return $data;
		}
		return $data;
	}

	private function build_search_replace_dry_run( $old, $new, $table_args, $flags ) {
		global $wpdb;
		$preview = array(
			'command' => 'wp search-replace',
			'old'     => $old,
			'new'     => $new,
		);

		$tables = $this->resolve_search_replace_tables( $table_args, $flags );
		if ( is_wp_error( $tables ) ) {
			$preview['note'] = $tables->get_error_message();
			return $preview;
		}

		$deadline      = microtime( true ) + 15;
		$cap           = 1000;
		$counts        = array();
		$not_previewed = 0;
		foreach ( $tables as $table ) {
			if ( microtime( true ) > $deadline ) {
				$not_previewed++;
				continue;
			}
			list( , $text_columns ) = $this->table_columns( $table );
			if ( empty( $text_columns ) ) {
				continue;
			}
			$conds = array();
			foreach ( $text_columns as $col ) {
				$conds[] = $this->esc_sql_ident( $col ) . $wpdb->prepare( ' LIKE BINARY %s', '%' . $wpdb->esc_like( $old ) . '%' );
			}
			$sql = 'SELECT COUNT(*) FROM (SELECT 1 FROM ' . $this->esc_sql_ident( $table ) . ' WHERE ' . implode( ' OR ', $conds ) . ' LIMIT ' . ( $cap + 1 ) . ') AS subq';
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$n = $wpdb->get_var( $sql ); // nosemgrep: direct-db-query
			if ( null === $n || ! empty( $wpdb->last_error ) ) {
				continue;
			}
			$n = (int) $n;
			if ( $n > 0 ) {
				$counts[ $table ] = ( $n > $cap ) ? $cap . '+' : $n;
			}
		}

		$preview['tables_in_scope']          = count( $tables );
		$preview['matching_rows_per_table']  = $counts;
		if ( $not_previewed > 0 ) {
			/* translators: %d: table count */
			$preview['preview_truncated'] = sprintf( __( '%d table(s) not scanned for the preview (time budget); they will still be processed on execution.', 'vibe-ai' ), $not_previewed );
		}

		$warnings = array();
		foreach ( array( 'siteurl', 'home' ) as $opt ) {
			$val = get_option( $opt );
			if ( is_string( $val ) && '' !== $val && false !== strpos( $val, $old ) ) {
				/* translators: 1: option name, 2: current value */
				$warnings[] = sprintf( __( 'This replacement will change the "%1$s" option (currently "%2$s"). Changing the site URL can break the WPVibe connection itself (the stored site URL will no longer match) and logs everyone out. Only approve if this is an intentional migration.', 'vibe-ai' ), $opt, $val );
			}
		}
		if ( $warnings ) {
			$preview['warnings'] = $warnings;
		}
		if ( empty( $flags['include_guids'] ) ) {
			$preview['guid_note'] = __( 'The guid column is skipped by default (WordPress best practice). Pass --include-guids to replace inside GUIDs too.', 'vibe-ai' );
		}
		$preview['note'] = __( 'Counts are rows containing the search string per table, not total replacements. Serialized values are handled safely at execution. Tip: run with --dry-run first for an exact replacement count.', 'vibe-ai' );
		return $preview;
	}

	/** Backtick-escape a MySQL identifier (doubling embedded backticks). */
	private function esc_sql_ident( $ident ) {
		return '`' . str_replace( '`', '``', $ident ) . '`';
	}

	/** Quote a value for use in WHERE against a primary key; integers pass bare. */
	private function esc_sql_value( $value ) {
		if ( preg_match( '/^[+-]?[0-9]{1,20}$/', (string) $value ) ) {
			return (string) $value;
		}
		return "'" . esc_sql( (string) $value ) . "'";
	}

	/** JSON-encoded form of a string without the surrounding quotes ("a/b" → "a\/b"). */
	private function json_encode_strip_quotes( $str ) {
		$encoded = json_encode( $str ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		return false !== $encoded ? substr( $encoded, 1, -1 ) : $str;
	}

	private function handle_help( $positional, $flags ) {
		$filter   = implode( ' ', $positional );
		$commands = array();
		foreach ( self::ALLOWLIST as $key => $meta ) {
			if ( '' !== $filter && 0 !== strpos( $key, $filter ) ) {
				continue;
			}
			$entry = array(
				'command' => $key,
				'tier'    => $meta['tier'],
				'usage'   => self::USAGE[ $key ] ?? $key,
			);
			if ( ! empty( $meta['destructive'] ) ) {
				$entry['requires_approval'] = true;
			}
			$commands[] = $entry;
		}
		if ( '' !== $filter && empty( $commands ) ) {
			/* translators: %s: command name the user asked help for */
			return $this->error_result( sprintf( __( 'No supported command matches "%s". Run `help` with no arguments for the full catalog.', 'vibe-ai' ), $filter ) );
		}
		return $this->success_result( array(
			'emulator' => self::EMULATOR_NAME,
			'note'     => __( 'Native PHP dispatch with a security allowlist, not real WP-CLI. The commands below are the complete supported set; anything else is blocked. Write commands marked requires_approval pause for browser approval before executing.', 'vibe-ai' ),
			'commands' => $commands,
		) );
	}

	private function handle_cli_version( $positional, $flags ) {
		global $wp_version;
		return $this->success_result( array(
			'emulator'       => self::EMULATOR_NAME,
			'plugin_version' => defined( 'WPVIBE_VERSION' ) ? WPVIBE_VERSION : '',
			'note'           => __( 'Native PHP dispatch with a security allowlist, not real WP-CLI. Run `help` for the supported command catalog.', 'vibe-ai' ),
			'wp_version'     => $wp_version,
			'php_version'    => PHP_VERSION,
		) );
	}

	private function handle_cap_list( $positional, $flags ) {
		if ( empty( $positional[0] ) ) {
			return $this->error_result( __( 'Role required. Usage: cap list <role> [--show-grant]', 'vibe-ai' ) );
		}
		$role = get_role( $positional[0] );
		if ( ! $role ) {
			$names = function_exists( 'wp_roles' ) ? implode( ', ', array_keys( wp_roles()->roles ) ) : '';
			/* translators: 1: role slug, 2: available role slugs */
			return $this->error_result( sprintf( __( 'Role \'%1$s\' not found. Available roles: %2$s', 'vibe-ai' ), $positional[0], $names ) );
		}
		$caps = (array) $role->capabilities;
		if ( empty( $flags['show_grant'] ) ) {
			$granted = array_keys( array_filter( $caps ) );
			sort( $granted );
			return $this->success_result( $granted );
		}
		ksort( $caps );
		$results = array();
		foreach ( $caps as $cap => $grant ) {
			$results[] = array( 'capability' => $cap, 'grant' => (bool) $grant );
		}
		return $this->success_result( $results );
	}

	private function handle_role_list( $positional, $flags ) {
		$results = array();
		foreach ( wp_roles()->roles as $slug => $def ) {
			$results[] = array(
				'name'             => $def['name'],
				'role'             => $slug,
				'capability_count' => count( array_filter( (array) ( $def['capabilities'] ?? array() ) ) ),
			);
		}
		return $this->success_result( $this->filter_fields( $results, $flags ) );
	}

	private function handle_media_image_size( $positional, $flags ) {
		$results = array(
			array( 'name' => 'full', 'width' => null, 'height' => null, 'crop' => false ),
		);
		$sizes = function_exists( 'wp_get_registered_image_subsizes' ) ? wp_get_registered_image_subsizes() : array();
		foreach ( $sizes as $name => $size ) {
			$results[] = array(
				'name'   => $name,
				'width'  => (int) ( $size['width'] ?? 0 ),
				'height' => (int) ( $size['height'] ?? 0 ),
				'crop'   => ! empty( $size['crop'] ),
			);
		}
		return $this->success_result( $this->filter_fields( $results, $flags ) );
	}

	private function handle_transient_get( $positional, $flags ) {
		if ( empty( $positional[0] ) ) {
			return $this->error_result( __( 'Transient key required. Usage: transient get <key> [--network]', 'vibe-ai' ) );
		}
		$value = ! empty( $flags['network'] ) ? get_site_transient( $positional[0] ) : get_transient( $positional[0] );
		if ( false === $value ) {
			/* translators: %s: transient key */
			return $this->error_result( sprintf( __( 'Transient \'%s\' is not set (or has expired).', 'vibe-ai' ), $positional[0] ) );
		}
		return array(
			'exit_code' => 0,
			'stdout'    => is_scalar( $value ) ? (string) $value : wp_json_encode( $value, JSON_PRETTY_PRINT ),
			'stderr'    => '',
		);
	}

	private function handle_menu_item_list( $positional, $flags ) {
		if ( empty( $positional[0] ) ) {
			return $this->error_result( __( 'Menu required. Usage: menu item list <menu> (accepts id, slug, or name)', 'vibe-ai' ) );
		}
		$menu  = is_numeric( $positional[0] ) ? (int) $positional[0] : $positional[0];
		$items = wp_get_nav_menu_items( $menu );
		if ( false === $items ) {
			/* translators: %s: menu identifier */
			return $this->error_result( sprintf( __( 'Menu \'%s\' not found. Run `menu list` to see available menus.', 'vibe-ai' ), $positional[0] ) );
		}
		$results = array();
		foreach ( $items as $item ) {
			$results[] = array(
				'db_id'     => (int) $item->ID,
				'type'      => $item->type,
				'object'    => $item->object,
				'object_id' => (int) $item->object_id,
				'title'     => $item->title,
				'link'      => $item->url,
				'position'  => (int) $item->menu_order,
				'parent'    => (int) $item->menu_item_parent,
			);
		}
		return $this->success_result( $this->filter_fields( $results, $flags ) );
	}

	private function handle_user_get( $positional, $flags ) {
		if ( empty( $positional[0] ) ) {
			return $this->error_result( __( 'User identifier required. Usage: user get <id|login|email>', 'vibe-ai' ) );
		}
		$ident = $positional[0];
		$user  = is_numeric( $ident )
			? get_user_by( 'id', (int) $ident )
			: ( is_email( $ident ) ? get_user_by( 'email', $ident ) : get_user_by( 'login', $ident ) );
		if ( ! $user ) {
			/* translators: %s: user identifier */
			return $this->error_result( sprintf( __( 'User \'%s\' not found.', 'vibe-ai' ), $ident ) );
		}
		$data = array(
			'ID'              => (int) $user->ID,
			'user_login'      => $user->user_login,
			'display_name'    => $user->display_name,
			'user_email'      => $user->user_email,
			'user_registered' => $user->user_registered,
			'user_nicename'   => $user->user_nicename,
			'user_url'        => $user->user_url,
			'roles'           => implode( ',', (array) $user->roles ),
		);
		return $this->success_result( $this->filter_fields( array( $data ), $flags )[0] ?? $data );
	}

	private function handle_theme_get( $positional, $flags ) {
		if ( empty( $positional[0] ) ) {
			return $this->error_result( __( 'Theme slug required. Usage: theme get <slug>', 'vibe-ai' ) );
		}
		$theme = wp_get_theme( $positional[0] );
		if ( ! $theme->exists() ) {
			/* translators: %s: theme slug */
			return $this->error_result( sprintf( __( 'Theme \'%s\' not found.', 'vibe-ai' ), $positional[0] ) );
		}
		$parent = $theme->parent();
		$data   = array(
			'name'           => $theme->get( 'Name' ),
			'version'        => $theme->get( 'Version' ),
			'status'         => ( get_stylesheet() === $positional[0] ) ? 'active' : 'inactive',
			'parent_theme'   => $parent ? $parent->get( 'Name' ) : '',
			'template'       => $theme->get_template(),
			'stylesheet'     => $theme->get_stylesheet(),
			'template_dir'   => $theme->get_template_directory(),
			'stylesheet_dir' => $theme->get_stylesheet_directory(),
			'description'    => $theme->get( 'Description' ),
			'author'         => wp_strip_all_tags( $theme->get( 'Author' ) ),
			'tags'           => (array) $theme->get( 'Tags' ),
		);
		return $this->success_result( $this->filter_fields( array( $data ), $flags )[0] ?? $data );
	}

	private function handle_cron_test( $positional, $flags ) {
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			return $this->error_result( __( 'The DISABLE_WP_CRON constant is set to true. WP-Cron spawning is disabled — scheduled events only run if a system cron hits wp-cron.php directly.', 'vibe-ai' ) );
		}
		$notes = array();
		if ( defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON ) {
			$notes[] = __( 'The ALTERNATE_WP_CRON constant is set to true; cron runs via a redirect fallback rather than the HTTP spawn tested here.', 'vibe-ai' );
		}
		$doing    = sprintf( '%.22F', microtime( true ) );
		$url      = add_query_arg( 'doing_wp_cron', $doing, site_url( 'wp-cron.php' ) );
		$response = wp_remote_post( $url, array(
			'timeout'   => 10,
			'blocking'  => true,
			'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
		) );
		if ( is_wp_error( $response ) ) {
			/* translators: %s: HTTP error message */
			return $this->error_result( sprintf( __( 'WP-Cron spawn failed: %s. The site cannot reach its own wp-cron.php (loopback requests blocked?), so scheduled events will not run on traffic.', 'vibe-ai' ), $response->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 300 ) {
			/* translators: %d: HTTP status code */
			return $this->error_result( sprintf( __( 'WP-Cron spawn returned HTTP %d (expected 200). Scheduled events may not be running.', 'vibe-ai' ), $code ) );
		}
		return $this->success_result( array(
			'message'           => __( 'WP-Cron spawning is working as expected.', 'vibe-ai' ),
			'spawn_status_code' => $code,
			'notes'             => $notes,
		) );
	}

	private function handle_maintenance_mode_status( $positional, $flags ) {
		// Real `wp maintenance-mode status` only checks the core .maintenance
		// file. Plugin-based maintenance is the kind users actually have, and
		// the drop-in decides what visitors see — so this reports all three.
		$core    = $this->core_maintenance_state( ABSPATH . '.maintenance' );
		$drop_in = array(
			'present' => file_exists( WP_CONTENT_DIR . '/maintenance.php' ),
			'path'    => 'wp-content/maintenance.php',
			'note'    => __( 'Custom maintenance page, served whenever core maintenance mode is active.', 'vibe-ai' ),
		);
		$plugins = $this->detect_maintenance_plugins();

		$plugin_enabled = array();
		foreach ( $plugins as $p ) {
			if ( ! empty( $p['enabled'] ) ) {
				$plugin_enabled[] = $p['name'];
			}
		}

		$active = $core['active'] || ! empty( $plugin_enabled );
		if ( $core['active'] ) {
			$message = __( 'Maintenance mode is active (core .maintenance file). Visitors and REST requests get a maintenance page instead of normal responses.', 'vibe-ai' );
		} elseif ( ! empty( $plugin_enabled ) ) {
			/* translators: %s: plugin names */
			$message = sprintf( __( 'Maintenance/coming-soon mode is active via plugin: %s. The site answers frontend requests with the plugin\'s holding page.', 'vibe-ai' ), implode( ', ', $plugin_enabled ) );
		} else {
			$message = __( 'Maintenance mode is not active.', 'vibe-ai' );
		}

		return $this->success_result( array(
			'active'                   => $active,
			'core_maintenance_file'    => $core,
			'maintenance_page_drop_in' => $drop_in,
			'maintenance_plugins'      => $plugins,
			'message'                  => $message,
		) );
	}

	/**
	 * Effective state of the core .maintenance file. Core ignores the file once
	 * $upgrading is more than 10 minutes old, so presence alone is not "active".
	 */
	private function core_maintenance_state( $path ) {
		$state = array( 'present' => file_exists( $path ), 'active' => false );
		if ( ! $state['present'] ) {
			return $state;
		}
		$contents = (string) file_get_contents( $path );
		if ( preg_match( '/\$upgrading\s*=\s*(\d+)/', $contents, $m ) ) {
			$started             = (int) $m[1];
			$state['started_at'] = gmdate( 'Y-m-d H:i:s', $started );
			$state['active']     = ( time() - $started ) < 600;
			if ( ! $state['active'] ) {
				$state['note'] = __( 'The .maintenance file is present but its timestamp is older than 10 minutes, so core ignores it. A stuck file usually means an update died mid-flight; it is safe to delete.', 'vibe-ai' );
			}
		} else {
			$state['note'] = __( 'No parseable $upgrading timestamp in the .maintenance file; core treats that as expired (not in maintenance).', 'vibe-ai' );
		}
		return $state;
	}

	/**
	 * Known maintenance/coming-soon plugins with their enable state where the
	 * option schema is known; enabled=null means "active but state unreadable".
	 */
	private function detect_maintenance_plugins() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$found = array();

		foreach ( array( 'coming-soon/coming-soon.php', 'seedprod-pro/seedprod-pro.php' ) as $file ) {
			if ( is_plugin_active( $file ) ) {
				$settings = json_decode( (string) get_option( 'seedprod_settings' ), true );
				$modes    = array();
				if ( ! empty( $settings['enable_coming_soon_mode'] ) ) {
					$modes[] = 'coming_soon';
				}
				if ( ! empty( $settings['enable_maintenance_mode'] ) ) {
					$modes[] = 'maintenance';
				}
				$found[] = array( 'plugin' => $file, 'name' => 'SeedProd', 'enabled' => ! empty( $modes ), 'modes' => $modes );
			}
		}

		if ( is_plugin_active( 'wp-maintenance-mode/wp-maintenance-mode.php' ) ) {
			$settings = get_option( 'wpmm_settings' );
			$found[]  = array(
				'plugin'  => 'wp-maintenance-mode/wp-maintenance-mode.php',
				'name'    => 'LightStart (WP Maintenance Mode)',
				'enabled' => ! empty( $settings['general']['status'] ),
			);
		}

		if ( is_plugin_active( 'under-construction-page/under-construction.php' ) ) {
			$options = get_option( 'ucp_options' );
			$found[] = array(
				'plugin'  => 'under-construction-page/under-construction.php',
				'name'    => 'Under Construction',
				'enabled' => ! empty( $options['status'] ),
			);
		}

		if ( is_plugin_active( 'cmp-coming-soon-maintenance/niteo-cmp.php' ) ) {
			$found[] = array(
				'plugin'  => 'cmp-coming-soon-maintenance/niteo-cmp.php',
				'name'    => 'CMP Coming Soon & Maintenance',
				'enabled' => ( '1' === (string) get_option( 'niteoCS_status' ) ),
			);
		}

		if ( is_plugin_active( 'maintenance/maintenance.php' ) ) {
			$found[] = array(
				'plugin'  => 'maintenance/maintenance.php',
				'name'    => 'Maintenance',
				'enabled' => null,
				'note'    => __( 'Plugin is active; enable state is not readable from options — check its settings page.', 'vibe-ai' ),
			);
		}

		return $found;
	}

	private function handle_cron_event_run( $positional, $flags ) {
		if ( empty( $positional ) ) {
			return $this->error_result( __( 'Hook required. Usage: cron event run <hook> [<hook>...]', 'vibe-ai' ) );
		}
		$crons   = _get_cron_array() ?: array();
		$results = array();
		$ok      = 0;
		foreach ( $positional as $hook ) {
			$instances = array();
			foreach ( $crons as $timestamp => $hooks ) {
				if ( isset( $hooks[ $hook ] ) ) {
					foreach ( $hooks[ $hook ] as $event ) {
						$instances[] = $event;
					}
				}
			}
			if ( empty( $instances ) ) {
				$results[] = array( 'hook' => $hook, 'status' => 'error', 'error' => __( 'no scheduled events for this hook', 'vibe-ai' ) );
				continue;
			}
			$executed = 0;
			$error    = null;
			foreach ( $instances as $event ) {
				try {
					do_action_ref_array( $hook, isset( $event['args'] ) ? (array) $event['args'] : array() );
					$executed++;
				} catch ( \Throwable $e ) {
					$error = $e->getMessage();
					break;
				}
			}
			if ( null !== $error ) {
				/* translators: %s: error message */
				$results[] = array( 'hook' => $hook, 'status' => 'error', 'executed' => $executed, 'error' => sprintf( __( 'callback threw: %s', 'vibe-ai' ), $error ) );
				continue;
			}
			$ok++;
			$results[] = array( 'hook' => $hook, 'status' => 'executed', 'executed' => $executed );
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Cron hook(s) run: {$ok}/" . count( $positional ),
			'action_label' => 'Refresh',
		) );

		return $this->success_result( array(
			/* translators: 1: success count, 2: total */
			'message' => sprintf( __( 'Executed %1$d of %2$d hook(s).', 'vibe-ai' ), $ok, count( $positional ) ),
			'results' => $results,
		) );
	}

	private function handle_cron_event_delete( $positional, $flags ) {
		if ( empty( $positional ) ) {
			return $this->error_result( __( 'Hook required. Usage: cron event delete <hook> [<hook>...]', 'vibe-ai' ) );
		}
		$results = array();
		$total   = 0;
		foreach ( $positional as $hook ) {
			$removed = wp_unschedule_hook( $hook );
			if ( false === $removed || is_wp_error( $removed ) ) {
				$results[] = array( 'hook' => $hook, 'status' => 'error', 'error' => __( 'unschedule failed', 'vibe-ai' ) );
				continue;
			}
			$total    += (int) $removed;
			$results[] = array( 'hook' => $hook, 'status' => 'deleted', 'events_removed' => (int) $removed );
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Cron events deleted: {$total}",
			'action_label' => 'Refresh',
		) );

		return $this->success_result( array(
			/* translators: %d: number of events removed */
			'message' => sprintf( __( 'Removed %d scheduled event(s).', 'vibe-ai' ), $total ),
			'results' => $results,
		) );
	}

	private function handle_theme_install( $positional, $flags, $confirm_write = false ) {
		if ( empty( $positional[0] ) ) {
			return $this->error_result( __( 'Theme slug required.', 'vibe-ai' ) );
		}
		$slug = sanitize_key( $positional[0] );

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';

		$api_args = array( 'slug' => $slug, 'fields' => array( 'sections' => false ) );
		if ( ! empty( $flags['version'] ) ) {
			$api_args['version'] = $flags['version'];
		}
		$api = themes_api( 'theme_information', $api_args );
		if ( is_wp_error( $api ) ) {
			return $this->error_result( $api->get_error_message() );
		}

		if ( ! $confirm_write ) {
			return array(
				'exit_code'             => 0,
				'stdout'                => wp_json_encode( array(
					'name'          => $api->name,
					'slug'          => $api->slug,
					'version'       => $api->version,
					'requires'      => $api->requires ?? '',
					'requires_php'  => $api->requires_php ?? '',
					'rating'        => $api->rating ?? 0,
					'download_link' => $api->download_link,
				), JSON_PRETTY_PRINT ),
				'stderr'                => '',
				'requires_confirmation' => true,
				'message'               => sprintf(
					/* translators: 1: theme name, 2: theme version */
					__( 'Ready to install %1$s v%2$s. Call again with confirm_write=true to proceed.', 'vibe-ai' ),
					$api->name,
					$api->version
				),
			);
		}

		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		global $wpdb;
		if ( empty( $wpdb->dbname ) ) {
			// SQLite-integration sites (Studio, Playground) leave dbname empty.
			$wpdb->dbname = 'wordpress';
		}

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Theme_Upgrader( $skin );
		try {
			$result = $upgrader->install( $api->download_link );
		} catch ( \Throwable $e ) {
			$skin_messages = $skin->get_upgrade_messages();
			return $this->error_result(
				sprintf(
					/* translators: 1: theme name, 2: error message, 3: upgrader messages */
					__( 'Install of %1$s threw a fatal error: %2$s%3$s', 'vibe-ai' ),
					$api->name,
					$e->getMessage(),
					$skin_messages ? ' Upgrader log: ' . implode( ' / ', $skin_messages ) : ''
				)
			);
		}
		if ( is_wp_error( $result ) ) {
			return $this->error_result( $result->get_error_message() );
		}
		if ( ! $result ) {
			$messages = $skin->get_upgrade_messages();
			return $this->error_result( __( 'Install failed.', 'vibe-ai' ) . ( $messages ? ' Upgrader log: ' . implode( ' / ', $messages ) : '' ) );
		}

		$activated = false;
		if ( ! empty( $flags['activate'] ) ) {
			$theme      = $upgrader->theme_info();
			$stylesheet = $theme ? $theme->get_stylesheet() : $slug;
			switch_theme( $stylesheet );
			$activated = ( get_stylesheet() === $stylesheet );
			if ( ! $activated ) {
				return $this->error_result(
					sprintf(
						/* translators: 1: theme name, 2: theme version */
						__( 'Installed %1$s v%2$s, but activation did not take effect. The theme may not meet WP/PHP requirements. Activate it manually or run `theme activate`.', 'vibe-ai' ),
						$api->name,
						$api->version
					)
				);
			}
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Theme installed: {$slug}" . ( $activated ? ' (activated)' : '' ),
			'action_label' => 'Manage Themes',
			'admin_url'    => admin_url( 'themes.php' ),
		) );

		/* translators: 1: theme name, 2: theme version */
		$msg = sprintf( __( 'Installed %1$s v%2$s.', 'vibe-ai' ), $api->name, $api->version );
		if ( $activated ) {
			$msg .= ' ' . __( 'Theme activated.', 'vibe-ai' );
		}
		return $this->success_result( array( 'message' => $msg ) );
	}

	private function handle_theme_update( $positional, $flags, $confirm_write = false ) {
		if ( empty( $positional[0] ) ) {
			return $this->error_result( __( 'Theme slug required.', 'vibe-ai' ) );
		}
		$slug  = $positional[0];
		$theme = wp_get_theme( $slug );
		if ( ! $theme->exists() ) {
			/* translators: %s: theme slug */
			return $this->error_result( sprintf( __( 'Theme \'%s\' not found.', 'vibe-ai' ), $slug ) );
		}

		wp_update_themes();
		$update_data = get_site_transient( 'update_themes' );
		if ( ! isset( $update_data->response[ $slug ] ) ) {
			return $this->error_result( __( 'No update available for this theme.', 'vibe-ai' ) );
		}
		// Theme update entries are arrays (unlike plugins, which are objects).
		$update = (array) $update_data->response[ $slug ];

		if ( ! $confirm_write ) {
			return array(
				'exit_code'             => 0,
				'stdout'                => wp_json_encode( array(
					'name'            => $theme->get( 'Name' ),
					'current_version' => $theme->get( 'Version' ),
					'new_version'     => $update['new_version'] ?? '',
				), JSON_PRETTY_PRINT ),
				'stderr'                => '',
				'requires_confirmation' => true,
				'message'               => sprintf(
					/* translators: 1: theme name, 2: current version, 3: new version */
					__( 'Ready to update %1$s from %2$s to %3$s. Call again with confirm_write=true to proceed.', 'vibe-ai' ),
					$theme->get( 'Name' ),
					$theme->get( 'Version' ),
					$update['new_version'] ?? ''
				),
			);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Theme_Upgrader( $skin );
		$result   = $upgrader->upgrade( $slug );
		if ( is_wp_error( $result ) ) {
			return $this->error_result( $result->get_error_message() );
		}
		if ( ! $result ) {
			$messages = $skin->get_upgrade_messages();
			return $this->error_result( __( 'Update failed.', 'vibe-ai' ) . ( $messages ? ' Upgrader log: ' . implode( ' / ', $messages ) : '' ) );
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Theme updated: {$slug}",
			'action_label' => 'Manage Themes',
			'admin_url'    => admin_url( 'themes.php' ),
		) );

		return $this->success_result( array(
			'message' => sprintf(
				/* translators: 1: theme name, 2: new version */
				__( 'Updated %1$s to v%2$s.', 'vibe-ai' ),
				$theme->get( 'Name' ),
				$update['new_version'] ?? ''
			),
		) );
	}

	private function handle_theme_delete( $positional, $flags ) {
		if ( empty( $positional[0] ) ) {
			return $this->error_result( __( 'Theme slug required. Usage: theme delete <slug> [<slug>...]', 'vibe-ai' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/theme.php';

		$results = array();
		$ok      = 0;
		foreach ( $positional as $slug ) {
			$theme = wp_get_theme( $slug );
			if ( ! $theme->exists() ) {
				$results[] = array( 'target' => $slug, 'status' => 'error', 'error' => 'not found' );
				continue;
			}
			if ( get_stylesheet() === $slug ) {
				$results[] = array( 'target' => $slug, 'status' => 'error', 'error' => __( 'cannot delete the active theme', 'vibe-ai' ) );
				continue;
			}
			if ( get_template() === $slug ) {
				$results[] = array( 'target' => $slug, 'status' => 'error', 'error' => __( 'cannot delete the parent of the active child theme', 'vibe-ai' ) );
				continue;
			}
			$result = delete_theme( $slug );
			if ( is_wp_error( $result ) ) {
				$results[] = array( 'target' => $slug, 'status' => 'error', 'error' => $result->get_error_message() );
				continue;
			}
			if ( false === $result ) {
				$results[] = array( 'target' => $slug, 'status' => 'error', 'error' => 'filesystem error' );
				continue;
			}
			$ok++;
			$results[] = array( 'target' => $slug, 'status' => 'deleted' );
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => count( $positional ) > 1 ? "Themes deleted: {$ok}/" . count( $positional ) : "Theme deleted: {$positional[0]}",
			'action_label' => 'Manage Themes',
			'admin_url'    => admin_url( 'themes.php' ),
		) );

		if ( 1 === count( $positional ) ) {
			$only = $results[0];
			if ( 'error' === $only['status'] ) {
				/* translators: 1: theme slug, 2: error message */
				return $this->error_result( sprintf( __( 'Theme \'%1$s\': %2$s', 'vibe-ai' ), $only['target'], $only['error'] ) );
			}
			/* translators: %s: theme slug */
			return $this->success_result( array( 'message' => sprintf( __( 'Theme \'%s\' deleted.', 'vibe-ai' ), $positional[0] ) ) );
		}

		return $this->success_result( array(
			/* translators: 1: success count, 2: total */
			'message'   => sprintf( __( 'Deleted %1$d of %2$d themes.', 'vibe-ai' ), $ok, count( $positional ) ),
			'succeeded' => $ok,
			'total'     => count( $positional ),
			'results'   => $results,
		) );
	}

	// ------------------------------------------------------------------
	// Role & capability editing (gated + lockout-protected)
	// ------------------------------------------------------------------

	private function resolve_role_or_error( $role_key ) {
		if ( empty( $role_key ) ) {
			return new WP_Error( 'no_role', __( 'Role required.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'invalid_input', false ) );
		}
		$role = get_role( $role_key );
		if ( ! $role ) {
			$names = function_exists( 'wp_roles' ) ? implode( ', ', array_keys( wp_roles()->roles ) ) : '';
			/* translators: 1: role slug, 2: available role slugs */
			return new WP_Error( 'no_role', sprintf( __( 'Role \'%1$s\' not found. Available roles: %2$s', 'vibe-ai' ), $role_key, $names ), WPVibe_Error_Contract::data( 'not_found', false ) );
		}
		return $role;
	}

	private function handle_cap_add( $positional, $flags ) {
		if ( count( $positional ) < 2 ) {
			return $this->error_result( __( 'Usage: cap add <role> <cap>... [--grant=<true|false>]', 'vibe-ai' ) );
		}
		$role = $this->resolve_role_or_error( $positional[0] );
		if ( is_wp_error( $role ) ) {
			return $this->error_result( $role->get_error_message() );
		}
		$caps  = array_slice( $positional, 1 );
		$grant = true;
		if ( isset( $flags['grant'] ) ) {
			$grant = ! ( 'false' === $flags['grant'] || false === $flags['grant'] || '0' === $flags['grant'] );
		}

		$added   = 0;
		$skipped = array();
		foreach ( $caps as $cap ) {
			if ( $grant && $role->has_cap( $cap ) ) {
				$skipped[] = array( 'capability' => $cap, 'reason' => 'already granted' );
				continue;
			}
			if ( ! $grant && isset( $role->capabilities[ $cap ] ) && false === $role->capabilities[ $cap ] ) {
				$skipped[] = array( 'capability' => $cap, 'reason' => 'already denied' );
				continue;
			}
			$role->add_cap( $cap, $grant );
			$added++;
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Capabilities added to role: {$positional[0]}",
			'action_label' => 'Manage Users',
			'admin_url'    => admin_url( 'users.php' ),
		) );

		$data = array(
			/* translators: 1: count, 2: role */
			'message' => sprintf( __( 'Added %1$d capability(ies) to \'%2$s\' role%3$s.', 'vibe-ai' ), $added, $positional[0], $grant ? '' : ' ' . __( 'as false (denied)', 'vibe-ai' ) ),
			'added'   => $added,
		);
		if ( $skipped ) {
			$data['skipped'] = $skipped;
		}
		$high = array_values( array_intersect( $caps, self::HIGH_RISK_CAPS ) );
		if ( $high && $grant ) {
			$data['high_risk_capabilities'] = $high;
		}
		return $this->success_result( $data );
	}

	private function handle_cap_remove( $positional, $flags ) {
		if ( count( $positional ) < 2 ) {
			return $this->error_result( __( 'Usage: cap remove <role> <cap>...', 'vibe-ai' ) );
		}
		$role_key = $positional[0];
		$caps     = array_slice( $positional, 1 );

		// Lockout protection (real WP-CLI has none): the administrator role
		// keeps its core capabilities, or the AI could brick site management.
		if ( 'administrator' === $role_key ) {
			$core = array_values( array_intersect( $caps, self::CORE_ADMIN_CAPS ) );
			if ( $core ) {
				return $this->error_result(
					sprintf(
						/* translators: %s: capability list */
						__( 'Refused: removing core capabilities (%s) from the administrator role would lock administrators out of site management. Use `role reset administrator` if the role is already damaged.', 'vibe-ai' ),
						implode( ', ', $core )
					)
				);
			}
		}

		$role = $this->resolve_role_or_error( $role_key );
		if ( is_wp_error( $role ) ) {
			return $this->error_result( $role->get_error_message() );
		}

		$removed = 0;
		$skipped = array();
		foreach ( $caps as $cap ) {
			if ( ! isset( $role->capabilities[ $cap ] ) ) {
				$skipped[] = array( 'capability' => $cap, 'reason' => 'not set on this role' );
				continue;
			}
			$role->remove_cap( $cap );
			$removed++;
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Capabilities removed from role: {$role_key}",
			'action_label' => 'Manage Users',
			'admin_url'    => admin_url( 'users.php' ),
		) );

		$data = array(
			/* translators: 1: count, 2: role */
			'message' => sprintf( __( 'Removed %1$d capability(ies) from \'%2$s\' role.', 'vibe-ai' ), $removed, $role_key ),
			'removed' => $removed,
		);
		if ( $skipped ) {
			$data['skipped'] = $skipped;
		}
		return $this->success_result( $data );
	}

	private function handle_role_create( $positional, $flags ) {
		if ( count( $positional ) < 2 ) {
			return $this->error_result( __( 'Usage: role create <role-key> <role-name> [--clone=<role>]', 'vibe-ai' ) );
		}
		$role_key  = $positional[0];
		$role_name = $positional[1];
		if ( get_role( $role_key ) ) {
			/* translators: %s: role key */
			return $this->error_result( sprintf( __( 'Role \'%s\' already exists.', 'vibe-ai' ), $role_key ) );
		}

		$clone_caps = null;
		if ( ! empty( $flags['clone'] ) ) {
			$src = get_role( $flags['clone'] );
			if ( ! $src ) {
				/* translators: %s: role key */
				return $this->error_result( sprintf( __( 'Clone source role \'%s\' not found.', 'vibe-ai' ), $flags['clone'] ) );
			}
			$clone_caps = $src->capabilities;
		}

		$new_role = add_role( $role_key, $role_name );
		if ( ! $new_role ) {
			return $this->error_result( __( 'Role could not be created.', 'vibe-ai' ) );
		}
		if ( $clone_caps ) {
			// Unlike real WP-CLI (which grants everything true), preserve
			// grant=false denials from the source role.
			foreach ( $clone_caps as $cap => $grant ) {
				$new_role->add_cap( $cap, $grant );
			}
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Role created: {$role_key}",
			'action_label' => 'Manage Users',
			'admin_url'    => admin_url( 'users.php' ),
		) );

		$message = null !== $clone_caps
			/* translators: 1: role key, 2: source role */
			? sprintf( __( 'Role \'%1$s\' created. Cloned capabilities from \'%2$s\'.', 'vibe-ai' ), $role_key, $flags['clone'] )
			/* translators: %s: role key */
			: sprintf( __( 'Role \'%s\' created.', 'vibe-ai' ), $role_key );
		return $this->success_result( array( 'message' => $message ) );
	}

	private function handle_role_delete( $positional, $flags ) {
		if ( empty( $positional[0] ) ) {
			return $this->error_result( __( 'Usage: role delete <role-key>', 'vibe-ai' ) );
		}
		$role_key = $positional[0];
		if ( 'administrator' === $role_key ) {
			return $this->error_result( __( 'Refused: the administrator role cannot be deleted (lockout protection).', 'vibe-ai' ) );
		}
		if ( ! get_role( $role_key ) ) {
			/* translators: %s: role key */
			return $this->error_result( sprintf( __( 'Role \'%s\' not found.', 'vibe-ai' ), $role_key ) );
		}

		$users      = count_users();
		$user_count = isset( $users['avail_roles'][ $role_key ] ) ? (int) $users['avail_roles'][ $role_key ] : 0;

		remove_role( $role_key );
		if ( get_role( $role_key ) ) {
			/* translators: %s: role key */
			return $this->error_result( sprintf( __( 'Role \'%s\' could not be deleted.', 'vibe-ai' ), $role_key ) );
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Role deleted: {$role_key}",
			'action_label' => 'Manage Users',
			'admin_url'    => admin_url( 'users.php' ),
		) );

		$data = array(
			/* translators: %s: role key */
			'message' => sprintf( __( 'Role \'%s\' deleted.', 'vibe-ai' ), $role_key ),
		);
		if ( $user_count > 0 ) {
			/* translators: %d: user count */
			$data['note'] = sprintf( __( '%d user(s) held this role and now have no role. Reassign them via the users REST API or wp-admin.', 'vibe-ai' ), $user_count );
		}
		return $this->success_result( $data );
	}

	private function handle_role_reset( $positional, $flags ) {
		$all = ! empty( $flags['all'] );
		if ( ! $all && empty( $positional ) ) {
			return $this->error_result( __( 'Usage: role reset <role-key>... | --all', 'vibe-ai' ) );
		}
		if ( ! function_exists( 'populate_roles' ) ) {
			require_once ABSPATH . 'wp-admin/includes/schema.php';
		}

		$requested = $all ? self::DEFAULT_ROLES : array_values( array_unique( $positional ) );
		$targets   = array();
		$results   = array();
		foreach ( $requested as $role_key ) {
			if ( ! in_array( $role_key, self::DEFAULT_ROLES, true ) ) {
				// Real WP-CLI: custom roles are not affected by reset.
				$results[] = array( 'role' => $role_key, 'status' => 'skipped', 'note' => __( 'custom role, not affected by reset', 'vibe-ai' ) );
				continue;
			}
			$targets[] = $role_key;
		}
		if ( empty( $targets ) ) {
			return $this->error_result( __( 'Must specify a default role to reset (administrator, editor, author, contributor, subscriber).', 'vibe-ai' ) );
		}

		// populate_roles() recreates every missing default role, so remember
		// which defaults were deliberately absent and re-remove them after.
		$absent_defaults = array();
		foreach ( self::DEFAULT_ROLES as $role_key ) {
			if ( ! in_array( $role_key, $targets, true ) && ! get_role( $role_key ) ) {
				$absent_defaults[] = $role_key;
			}
		}

		$before = array();
		foreach ( $targets as $role_key ) {
			$role_obj            = get_role( $role_key );
			$before[ $role_key ] = $role_obj ? array_keys( array_filter( $role_obj->capabilities ) ) : array();
			remove_role( $role_key );
		}

		populate_roles();

		foreach ( $absent_defaults as $role_key ) {
			remove_role( $role_key );
		}

		foreach ( $targets as $role_key ) {
			$role_obj = get_role( $role_key );
			$after    = $role_obj ? array_keys( array_filter( $role_obj->capabilities ) ) : array();
			$restored = count( array_diff( $after, $before[ $role_key ] ) );
			$removed  = count( array_diff( $before[ $role_key ], $after ) );
			$results[] = array(
				'role'                  => $role_key,
				'status'                => 'reset',
				'capabilities_restored' => $restored,
				'capabilities_removed'  => $removed,
			);
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => 'Role(s) reset to WordPress defaults: ' . implode( ', ', $targets ),
			'action_label' => 'Manage Users',
			'admin_url'    => admin_url( 'users.php' ),
		) );

		return $this->success_result( array(
			/* translators: %d: role count */
			'message' => sprintf( __( 'Reset %d role(s) to WordPress defaults.', 'vibe-ai' ), count( $targets ) ),
			'results' => $results,
		) );
	}

	private function handle_user_add_cap( $positional, $flags ) {
		if ( count( $positional ) < 2 ) {
			return $this->error_result( __( 'Usage: user add-cap <id|login|email> <cap>', 'vibe-ai' ) );
		}
		$user = $this->resolve_user( $positional[0] );
		if ( ! $user ) {
			/* translators: %s: user identifier */
			return $this->error_result( sprintf( __( 'User \'%s\' not found.', 'vibe-ai' ), $positional[0] ) );
		}
		$cap = $positional[1];
		$user->add_cap( $cap );

		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Capability added to user: {$user->user_login} → {$cap}",
			'action_label' => 'Manage Users',
			'admin_url'    => admin_url( 'users.php' ),
		) );

		$data = array(
			/* translators: 1: capability, 2: user login */
			'message' => sprintf( __( 'Added \'%1$s\' capability for user \'%2$s\'.', 'vibe-ai' ), $cap, $user->user_login ),
		);
		if ( in_array( $cap, self::HIGH_RISK_CAPS, true ) ) {
			$data['high_risk_capabilities'] = array( $cap );
		}
		return $this->success_result( $data );
	}

	private function handle_user_remove_cap( $positional, $flags ) {
		if ( count( $positional ) < 2 ) {
			return $this->error_result( __( 'Usage: user remove-cap <id|login|email> <cap>', 'vibe-ai' ) );
		}
		$user = $this->resolve_user( $positional[0] );
		if ( ! $user ) {
			/* translators: %s: user identifier */
			return $this->error_result( sprintf( __( 'User \'%s\' not found.', 'vibe-ai' ), $positional[0] ) );
		}
		$cap = $positional[1];
		// Only individual grants are removable, matching real WP-CLI; caps that
		// come from a role are removed from the role via `cap remove`.
		if ( ! isset( $user->caps[ $cap ] ) ) {
			return $this->error_result(
				sprintf(
					/* translators: 1: capability, 2: user login */
					__( 'No direct \'%1$s\' capability on user \'%2$s\'. If it comes from their role, remove it from the role with `cap remove <role> %1$s`.', 'vibe-ai' ),
					$cap,
					$user->user_login
				)
			);
		}
		$user->remove_cap( $cap );

		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Capability removed from user: {$user->user_login} → {$cap}",
			'action_label' => 'Manage Users',
			'admin_url'    => admin_url( 'users.php' ),
		) );

		return $this->success_result( array(
			/* translators: 1: capability, 2: user login */
			'message' => sprintf( __( 'Removed \'%1$s\' cap for user \'%2$s\'.', 'vibe-ai' ), $cap, $user->user_login ),
		) );
	}

	private function resolve_user( $ident ) {
		return is_numeric( $ident )
			? get_user_by( 'id', (int) $ident )
			: ( is_email( $ident ) ? get_user_by( 'email', $ident ) : get_user_by( 'login', $ident ) );
	}

	// ------------------------------------------------------------------
	// Parsing & Validation
	// ------------------------------------------------------------------

	/**
	 * Scan for shell metacharacters OUTSIDE quoted spans. Quoted argument
	 * values are data handed to native PHP handlers (no shell ever runs), so a
	 * pipe inside an SEO title or a semicolon inside serialized meta is
	 * legitimate; the same characters in command STRUCTURE still get blocked.
	 * The quote state machine MUST mirror tokenize() exactly: a string the
	 * gate reads as quoted but the tokenizer reads as unquoted (or vice
	 * versa) would be a gate/parser mismatch an attacker could aim at.
	 *
	 * @return string|null The blocked character/sequence, or null when clean.
	 */
	private function find_unquoted_shell_char( $input, $skip_angle_brackets ) {
		$in_quote   = false;
		$quote_char = '';
		$len        = strlen( $input );
		for ( $i = 0; $i < $len; $i++ ) {
			$char = $input[ $i ];
			if ( $in_quote ) {
				if ( $char === $quote_char ) {
					$in_quote = false;
				}
				continue;
			}
			if ( '"' === $char || "'" === $char ) {
				$in_quote   = true;
				$quote_char = $char;
				continue;
			}
			if ( ';' === $char || '`' === $char || '|' === $char || "\n" === $char || "\r" === $char ) {
				return $char;
			}
			if ( '&' === $char && $i + 1 < $len && '&' === $input[ $i + 1 ] ) {
				return '&&';
			}
			if ( '$' === $char && $i + 1 < $len && '(' === $input[ $i + 1 ] ) {
				return '$(';
			}
			if ( ( '<' === $char || '>' === $char ) && ! $skip_angle_brackets ) {
				return $char;
			}
		}
		return null;
	}

	private function tokenize( $input ) {
		$tokens   = array();
		$current  = '';
		$in_quote = false;
		$quote_char = '';
		$len = strlen( $input );

		for ( $i = 0; $i < $len; $i++ ) {
			$char = $input[ $i ];
			if ( $in_quote ) {
				if ( $char === $quote_char ) {
					$in_quote = false;
				} else {
					$current .= $char;
				}
			} elseif ( $char === '"' || $char === "'" ) {
				$in_quote   = true;
				$quote_char = $char;
			} elseif ( $char === ' ' || $char === "\t" ) {
				if ( '' !== $current ) {
					$tokens[] = $current;
					$current  = '';
				}
			} else {
				$current .= $char;
			}
		}
		if ( '' !== $current ) {
			$tokens[] = $current;
		}
		return $tokens;
	}

	private function get_positional( $tokens ) {
		$positional = array();
		foreach ( $tokens as $token ) {
			if ( strpos( $token, '-' ) !== 0 ) {
				$positional[] = $token;
			}
		}
		return $positional;
	}

	private function resolve_command( $tokens ) {
		$positional = $this->get_positional( $tokens );

		for ( $len = min( 3, count( $positional ) ); $len >= 1; $len-- ) {
			$key = implode( ' ', array_slice( $positional, 0, $len ) );
			if ( isset( self::ALLOWLIST[ $key ] ) ) {
				return array( 'meta' => self::ALLOWLIST[ $key ], 'key_length' => $len );
			}
		}

		$base    = $positional[0] ?? '';
		$blocked = array( 'eval', 'eval-file', 'shell', 'core', 'config', 'package', 'server', 'site' );
		if ( in_array( $base, $blocked, true ) ) {
			/* translators: 1: command name, 2: the same command name */
			return new WP_Error( 'command_blocked', sprintf( __( '"%1$s" commands are blocked for security. Individually supported subcommands (if any) are listed by `help %2$s`; run `help` for the full catalog.', 'vibe-ai' ), $base, $base ), WPVibe_Error_Contract::data( 'not_supported', false, array( 'status' => 403 ) ) );
		}

		/* translators: %s: command name */
		return new WP_Error( 'command_not_allowed', sprintf( __( 'Command "%s" is not in the allowlist. Run `help` for the full supported-command catalog — the capability may exist under different syntax.', 'vibe-ai' ), implode( ' ', array_slice( $positional, 0, 2 ) ) ), WPVibe_Error_Contract::data( 'not_supported', false, array( 'status' => 403 ) ) );
	}

	private function strip_blocked_flags( $tokens ) {
		$cleaned = array();
		foreach ( $tokens as $token ) {
			$blocked = false;
			foreach ( self::BLOCKED_FLAGS as $flag ) {
				if ( $token === $flag || strpos( $token, $flag . '=' ) === 0 ) {
					$blocked = true;
					break;
				}
			}
			if ( ! $blocked ) {
				$cleaned[] = $token;
			}
		}
		return $cleaned;
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Wrap a BLOCKED_OPTIONS hard-block message in a product-namespaced XML
	 * directive so the AI treats it as "how to respond" guidance and stops
	 * suggesting bypass workarounds (wp-cli on the server, direct SQL, etc.).
	 * Same pattern as the cap and review-nudge directives elsewhere in WPVibe.
	 */
	private function blocked_option_message( $key, $verb ) {
		return implode( "\n", array(
			'<wpvibe-blocked-option>',
			/* translators: 1: option key, 2: verb (added or deleted) */
			sprintf( __( 'The option "%1$s" is permanently protected by WPVibe and cannot be %2$s via AI tools.', 'vibe-ai' ), $key, $verb ),
			'',
			__( 'This protection exists because changing this option would break the site (broken admin URLs, broken login, broken auth, etc.). DO NOT suggest manual workarounds — do not tell the user to run wp-cli on the server, edit wp-config.php, or run SQL against the database. The user is being protected from accidental destructive changes; respect that.', 'vibe-ai' ),
			'',
			__( 'How to reply: in one short sentence, tell the user this specific option is permanently protected and they should change it through WordPress admin if they really need to. Do not offer alternative deletion methods.', 'vibe-ai' ),
			'</wpvibe-blocked-option>',
		) );
	}

	private function handle_db_tables( $positional, $flags ) {
		global $wpdb;
		$tables = $wpdb->get_col( 'SHOW TABLES' );
		return $this->success_result( is_array( $tables ) ? $tables : array() );
	}

	private function handle_db_prefix( $positional, $flags ) {
		global $wpdb;
		return $this->success_result( array( 'prefix' => $wpdb->prefix ) );
	}

	private function handle_core_version( $positional, $flags ) {
		global $wp_version;
		$data = array( 'version' => $wp_version );
		if ( isset( $flags['extra'] ) ) {
			global $wp_db_version;
			$data['db_version'] = $wp_db_version;
			$data['locale']     = get_locale();
			$data['php']        = PHP_VERSION;
		}
		return $this->success_result( $data );
	}

	private function handle_core_check_update( $positional, $flags ) {
		if ( ! function_exists( 'get_core_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}
		wp_version_check();
		$updates   = get_core_updates();
		$available = array();
		foreach ( (array) $updates as $update ) {
			if ( ! isset( $update->response ) || 'latest' === $update->response ) {
				continue;
			}
			$available[] = array(
				'version'     => $update->current ?? '',
				'update_type' => $update->response,
				'locale'      => $update->locale ?? '',
			);
		}
		if ( empty( $available ) ) {
			return $this->success_result( array( 'message' => __( 'WordPress is at the latest version.', 'vibe-ai' ) ) );
		}
		return $this->success_result( $available );
	}

	private function handle_post_type_list( $positional, $flags ) {
		$results = array();
		foreach ( get_post_types( array(), 'objects' ) as $type ) {
			$results[] = array(
				'name'         => $type->name,
				'label'        => $type->label,
				'public'       => $type->public ? 'true' : 'false',
				'hierarchical' => $type->hierarchical ? 'true' : 'false',
				'description'  => $type->description,
			);
		}
		return $this->success_result( $this->filter_fields( $results, $flags ) );
	}

	private function handle_menu_location_list( $positional, $flags ) {
		$assigned = get_nav_menu_locations();
		$results  = array();
		foreach ( get_registered_nav_menus() as $location => $description ) {
			$menu      = ! empty( $assigned[ $location ] ) ? wp_get_nav_menu_object( $assigned[ $location ] ) : null;
			$results[] = array(
				'location'      => $location,
				'description'   => $description,
				'assigned_menu' => $menu ? $menu->name : '',
			);
		}
		return $this->success_result( $results );
	}

	private function handle_theme_mod_list( $positional, $flags ) {
		$mods    = get_theme_mods();
		$results = array();
		foreach ( (array) $mods as $key => $value ) {
			$results[] = array(
				'key'   => (string) $key,
				'value' => is_scalar( $value ) ? (string) $value : wp_json_encode( $value ),
			);
		}
		return $this->success_result( $results );
	}

	private function handle_core_verify_checksums( $positional, $flags ) {
		global $wp_version;
		$version      = ! empty( $flags['version'] ) ? $flags['version'] : $wp_version;
		$locale       = ! empty( $flags['locale'] ) ? $flags['locale'] : get_locale();
		$include_root = ! empty( $flags['include_root'] );
		$exclude      = ! empty( $flags['exclude'] ) ? array_map( 'trim', explode( ',', $flags['exclude'] ) ) : array();

		$checksums = $this->fetch_core_checksums( $version, $locale );
		if ( ! $checksums && 'en_US' !== $locale ) {
			// Localized packages often have no published checksums; en_US covers
			// everything except the translated readme/license files.
			$checksums = $this->fetch_core_checksums( $version, 'en_US' );
		}
		if ( ! $checksums ) {
			/* translators: 1: WordPress version, 2: locale */
			return $this->error_result( sprintf( __( 'Could not retrieve core checksums for WordPress %1$s (%2$s).', 'vibe-ai' ), $version, $locale ) );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$mismatched = array();
		$missing    = array();
		$checked    = 0;
		foreach ( $checksums as $file => $md5 ) {
			// wp-content ships in the zip but is expected to diverge (themes,
			// plugins, languages) — real WP-CLI skips it too.
			if ( 0 === strpos( $file, 'wp-content/' ) ) {
				continue;
			}
			if ( in_array( $file, $exclude, true ) ) {
				continue;
			}
			$path = ABSPATH . $file;
			$checked++;
			if ( ! file_exists( $path ) ) {
				$missing[] = $file;
				continue;
			}
			if ( md5_file( $path ) !== $md5 ) {
				$mismatched[] = $file;
			}
		}

		// Unknown files inside the core directories ("File should not exist" in
		// real WP-CLI) — the security-relevant half: malware more often adds
		// files than modifies them.
		$should_not_exist = array();
		$disk_files       = $this->collect_files_recursive( ABSPATH, function ( $rel ) use ( $include_root ) {
			return $this->core_checksum_in_scope( $rel, $include_root );
		} );
		if ( null !== $disk_files ) {
			$manifest = array();
			foreach ( array_keys( $checksums ) as $file ) {
				if ( $this->core_checksum_in_scope( $file, $include_root ) ) {
					$manifest[ $file ] = true;
				}
			}
			foreach ( $disk_files as $rel ) {
				if ( ! isset( $manifest[ $rel ] ) && ! in_array( $rel, $exclude, true ) ) {
					$should_not_exist[] = $rel;
				}
			}
			sort( $should_not_exist );
		}

		$verified = empty( $mismatched ) && empty( $missing );
		if ( ! $verified ) {
			$message = __( 'WordPress installation does NOT verify against checksums. Modified or missing core files can indicate a compromise — compare the listed files against a clean WordPress download before trusting this install.', 'vibe-ai' );
		} elseif ( ! empty( $should_not_exist ) ) {
			$message = __( 'Core files verify against checksums, but unknown files were found inside the core directories (should_not_exist). WordPress never adds extra files there — unexpected additions are a common malware pattern; identify or remove each one before trusting this install.', 'vibe-ai' );
		} else {
			$message = __( 'WordPress installation verifies against checksums.', 'vibe-ai' );
		}

		return $this->success_result( array(
			'verified'         => $verified,
			'wp_version'       => $version,
			'files_checked'    => $checked,
			'mismatched'       => $this->cap_file_list( $mismatched ),
			'missing'          => $this->cap_file_list( $missing ),
			'should_not_exist' => $this->cap_file_list( $should_not_exist ),
			'message'          => $message,
		) );
	}

	private function handle_plugin_verify_checksums( $positional, $flags ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all   = get_plugins();
		$slugs = array();
		if ( isset( $flags['all'] ) ) {
			foreach ( $all as $file => $data ) {
				$slugs[ $this->plugin_slug_from_file( $file ) ] = $file;
			}
		} else {
			if ( empty( $positional ) ) {
				return $this->error_result( __( 'Plugin slug required (or pass --all).', 'vibe-ai' ) );
			}
			foreach ( $positional as $slug ) {
				$file = $this->resolve_plugin_file( $slug );
				if ( ! $file ) {
					/* translators: %s: plugin slug */
					return $this->error_result( sprintf( __( 'Plugin \'%s\' not found.', 'vibe-ai' ), $slug ) );
				}
				$slugs[ $this->plugin_slug_from_file( $file ) ] = $file;
			}
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$results = array();
		foreach ( $slugs as $slug => $file ) {
			$version  = $all[ $file ]['Version'] ?? '';
			// Plugin checksums live on downloads.wordpress.org, not api.wordpress.org.
			$response = wp_remote_get(
				'https://downloads.wordpress.org/plugin-checksums/' . rawurlencode( $slug ) . '/' . rawurlencode( $version ) . '.json',
				array( 'timeout' => 15 )
			);
			$body  = is_wp_error( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );
			$files = is_array( $body ) && ! empty( $body['files'] ) && is_array( $body['files'] ) ? $body['files'] : null;
			if ( ! $files ) {
				$results[] = array(
					'plugin'   => $slug,
					'version'  => $version,
					'verified' => null,
					'message'  => __( 'Checksums not available (not hosted on WordPress.org, or a premium/custom plugin).', 'vibe-ai' ),
				);
				continue;
			}

			$is_single  = ( '.' === dirname( $file ) );
			$root       = trailingslashit( WP_PLUGIN_DIR ) . ( $is_single ? '' : trailingslashit( dirname( $file ) ) );
			$strict     = ! empty( $flags['strict'] );
			$mismatched = array();
			$missing    = array();
			foreach ( $files as $relpath => $hashes ) {
				// WP.org regenerates readmes after release, so legitimate installs
				// diff there; real WP-CLI skips them unless --strict.
				if ( ! $strict && $this->is_soft_change_file( $relpath ) ) {
					continue;
				}
				$expected = isset( $hashes['md5'] ) ? (array) $hashes['md5'] : array();
				if ( empty( $expected ) ) {
					continue;
				}
				$path = $root . $relpath;
				if ( ! file_exists( $path ) ) {
					$missing[] = $relpath;
					continue;
				}
				if ( ! in_array( md5_file( $path ), $expected, true ) ) {
					$mismatched[] = $relpath;
				}
			}

			// Files on disk but absent from the manifest ("File was added" in
			// real WP-CLI) — added files are the classic malware injection shape.
			$added = array();
			$disk  = $is_single ? array( basename( $file ) ) : $this->collect_files_recursive( $root, null );
			if ( is_array( $disk ) ) {
				foreach ( $disk as $relpath ) {
					if ( ! array_key_exists( $relpath, $files ) ) {
						$added[] = $relpath;
					}
				}
				sort( $added );
			}

			$verified  = empty( $mismatched ) && empty( $missing ) && empty( $added );
			$results[] = array(
				'plugin'     => $slug,
				'version'    => $version,
				'verified'   => $verified,
				'mismatched' => $this->cap_file_list( $mismatched ),
				'missing'    => $this->cap_file_list( $missing ),
				'added'      => $this->cap_file_list( $added ),
			);
		}
		return $this->success_result( $results );
	}

	/** Real WP-CLI's soft-change files: only strict mode flags plugin readme diffs. */
	private function is_soft_change_file( $file ) {
		return in_array( strtolower( $file ), array( 'readme.txt', 'readme.md' ), true );
	}

	/**
	 * Mirrors real WP-CLI's core-checksum scope: wp-admin/, wp-includes/, and
	 * root wp-* files (never wp-config.php). --include-root widens to the whole
	 * root except .htaccess, .maintenance, wp-config.php, and wp-content/.
	 */
	private function core_checksum_in_scope( $rel, $include_root ) {
		if ( $include_root ) {
			return 1 !== preg_match( '/^(\.htaccess$|\.maintenance$|wp-config\.php$|wp-content\/)/', $rel );
		}
		return 0 === strpos( $rel, 'wp-admin/' )
			|| 0 === strpos( $rel, 'wp-includes/' )
			|| 1 === preg_match( '/^wp-(?!config\.php)([^\/]*)$/', $rel );
	}

	/**
	 * Recursive file listing relative to $root. $filter prunes directories too
	 * (bare "wp-admin" must pass for traversal to descend, same as real WP-CLI).
	 * Returns null on filesystem errors so callers can skip the check quietly.
	 */
	private function collect_files_recursive( $root, $filter ) {
		$root   = rtrim( $root, '/\\' ) . '/';
		$filter = $filter ?: function () {
			return true;
		};
		$found  = array();
		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveCallbackFilterIterator(
					new RecursiveDirectoryIterator( $root, RecursiveDirectoryIterator::SKIP_DOTS ),
					function ( $current ) use ( $root, $filter ) {
						$rel = str_replace( '\\', '/', substr( $current->getPathname(), strlen( $root ) ) );
						return (bool) call_user_func( $filter, $rel );
					}
				),
				RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ( $iterator as $file_info ) {
				if ( $file_info->isFile() ) {
					$found[] = str_replace( '\\', '/', substr( $file_info->getPathname(), strlen( $root ) ) );
				}
			}
		} catch ( \Exception $e ) {
			return null;
		}
		return $found;
	}

	private function fetch_core_checksums( $version, $locale ) {
		$response = wp_remote_get(
			'https://api.wordpress.org/core/checksums/1.0/?' . http_build_query( array( 'version' => $version, 'locale' => $locale ) ),
			array( 'timeout' => 15 )
		);
		if ( is_wp_error( $response ) ) {
			return null;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return ( is_array( $body ) && ! empty( $body['checksums'] ) && is_array( $body['checksums'] ) ) ? $body['checksums'] : null;
	}

	private function plugin_slug_from_file( $file ) {
		$dir = dirname( $file );
		return '.' === $dir ? basename( $file, '.php' ) : $dir;
	}

	/** Bound checksum failure lists so a fully-compromised install can't blow up the response. */
	private function cap_file_list( $files, $limit = 50 ) {
		if ( count( $files ) <= $limit ) {
			return $files;
		}
		$capped   = array_slice( $files, 0, $limit );
		$capped[] = sprintf( '... and %d more', count( $files ) - $limit );
		return $capped;
	}

	private function success_result( $data ) {
		return array(
			'exit_code' => 0,
			'stdout'    => wp_json_encode( $data, JSON_PRETTY_PRINT ),
			'stderr'    => '',
		);
	}

	/** Positive integer IDs from positional args, deduped, order-preserved. */
	private function positional_ids( $positional ) {
		$ids = array();
		foreach ( (array) $positional as $p ) {
			if ( is_numeric( $p ) && (int) $p > 0 ) {
				$ids[] = (int) $p;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/** Shape a single- or multi-target post op response consistently. */
	private function bulk_result( $action, $ok, $ids, $results ) {
		$total = count( $ids );
		if ( 1 === $total ) {
			$only = $results[0];
			if ( isset( $only['status'] ) && 'error' === $only['status'] ) {
				/* translators: 1: post ID, 2: error message */
				return $this->error_result( sprintf( __( 'Post #%1$d: %2$s', 'vibe-ai' ), $only['id'], $only['error'] ) );
			}
			/* translators: 1: post ID, 2: action taken */
			return $this->success_result( array( 'message' => sprintf( __( 'Post #%1$d %2$s.', 'vibe-ai' ), $ids[0], $action ) ) );
		}
		return $this->success_result( array(
			/* translators: 1: success count, 2: total, 3: action taken */
			'message'   => sprintf( __( '%1$d of %2$d posts %3$s.', 'vibe-ai' ), $ok, $total, $action ),
			'succeeded' => $ok,
			'total'     => $total,
			'results'   => $results,
		) );
	}

	private function error_result( $message, $exit_code = 1 ) {
		return array(
			'exit_code' => $exit_code,
			'stdout'    => '',
			'stderr'    => $message,
		);
	}

	private function filter_fields( $results, $flags ) {
		if ( empty( $flags['fields'] ) || empty( $results ) ) {
			return $results;
		}
		$fields = array_map( 'trim', explode( ',', $flags['fields'] ) );
		return array_map( function ( $row ) use ( $fields ) {
			return array_intersect_key( $row, array_flip( $fields ) );
		}, $results );
	}

	private function resolve_plugin_file( $slug ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all = get_plugins();
		if ( isset( $all[ $slug ] ) ) {
			return $slug;
		}
		foreach ( $all as $file => $data ) {
			$dir = dirname( $file );
			if ( $dir === $slug ) {
				return $file;
			}
			if ( '.' === $dir && pathinfo( $file, PATHINFO_FILENAME ) === $slug ) {
				return $file;
			}
		}
		return null;
	}
}
