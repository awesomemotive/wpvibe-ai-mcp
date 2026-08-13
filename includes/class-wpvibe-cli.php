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

require_once __DIR__ . '/cli/trait-wpvibe-cli-parse.php';
require_once __DIR__ . '/cli/trait-wpvibe-cli-helpers.php';
require_once __DIR__ . '/cli/trait-wpvibe-cli-security.php';
require_once __DIR__ . '/cli/trait-wpvibe-cli-plugin.php';
require_once __DIR__ . '/cli/trait-wpvibe-cli-theme.php';
require_once __DIR__ . '/cli/trait-wpvibe-cli-post.php';
require_once __DIR__ . '/cli/trait-wpvibe-cli-option.php';
require_once __DIR__ . '/cli/trait-wpvibe-cli-db.php';
require_once __DIR__ . '/cli/trait-wpvibe-cli-cache.php';
require_once __DIR__ . '/cli/trait-wpvibe-cli-roles.php';
require_once __DIR__ . '/cli/trait-wpvibe-cli-core.php';
require_once __DIR__ . '/cli/trait-wpvibe-cli-misc.php';


class WPVibe_CLI {

	use WPVibe_CLI_Parse;
	use WPVibe_CLI_Helpers;
	use WPVibe_CLI_Security;
	use WPVibe_CLI_Plugin;
	use WPVibe_CLI_Theme;
	use WPVibe_CLI_Post;
	use WPVibe_CLI_Option;
	use WPVibe_CLI_Db;
	use WPVibe_CLI_Cache;
	use WPVibe_CLI_Roles;
	use WPVibe_CLI_Core;
	use WPVibe_CLI_Misc;


	/** Set when the Worker calls run_approved(); allows handlers to proceed past destructive gates. */
	private $skip_destructive = false;
	private $approved_state   = null;

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
		'option pluck'     => array( 'tier' => 'read', 'cap' => 'manage_options' ),
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
		'post meta add'        => array( 'tier' => 'write', 'cap' => 'edit_posts' ),
		'post meta delete'     => array( 'tier' => 'write', 'cap' => 'edit_posts' ),
		'post term set'        => array( 'tier' => 'write', 'cap' => 'edit_posts' ),
		'post term add'        => array( 'tier' => 'write', 'cap' => 'edit_posts' ),
		'post term remove'     => array( 'tier' => 'write', 'cap' => 'edit_posts' ),
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
		// Navigation, terms, theme mods, permalinks — thin wrappers over the same
		// core functions WP-CLI calls (wp_create_nav_menu, wp_update_nav_menu_item,
		// wp_insert_term, set_theme_mod, $wp_rewrite). The coarse cap here is the
		// entry gate; term create additionally checks the taxonomy's own edit cap
		// in its handler. rewrite structure is approval-gated in classify_destructive.
		'menu create'          => array( 'tier' => 'write', 'cap' => 'edit_theme_options' ),
		'menu item add-custom' => array( 'tier' => 'write', 'cap' => 'edit_theme_options' ),
		'menu item add-post'   => array( 'tier' => 'write', 'cap' => 'edit_theme_options' ),
		'menu item add-term'   => array( 'tier' => 'write', 'cap' => 'edit_theme_options' ),
		'menu location assign' => array( 'tier' => 'write', 'cap' => 'edit_theme_options' ),
		'term create'          => array( 'tier' => 'write', 'cap' => 'manage_categories' ),
		'term update'          => array( 'tier' => 'write', 'cap' => 'manage_categories' ),
		// term delete is approval-gated via a dedicated classify_destructive
		// branch (terms have no trash), so no generic destructive meta here.
		'term delete'          => array( 'tier' => 'write', 'cap' => 'manage_categories' ),
		'menu item update'     => array( 'tier' => 'write', 'cap' => 'edit_theme_options' ),
		// Deliberately ungated (2026-08 adversarial plan review) despite the
		// permanent delete: one nav row, type-guarded to nav_menu_item in the
		// handler, Change Tracker mark. Not an oversight vs post delete --force.
		'menu item delete'     => array( 'tier' => 'write', 'cap' => 'edit_theme_options' ),
		'theme mod set'        => array( 'tier' => 'write', 'cap' => 'edit_theme_options' ),
		'rewrite structure'    => array( 'tier' => 'write', 'cap' => 'manage_options' ),
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
		// User account & meta writes (issue #37). Approval for password/email/role
		// elevation comes from a dedicated classify_destructive branch (conditional
		// on the field/role), NOT the generic destructive meta flag — gating every
		// user write would recreate approval fatigue. Role assignment additionally
		// checks promote_users inside the handler (term-create precedent).
		'user create'       => array( 'tier' => 'write', 'cap' => 'create_users' ),
		'user update'       => array( 'tier' => 'write', 'cap' => 'edit_users' ),
		'user set-role'     => array( 'tier' => 'write', 'cap' => 'promote_users' ),
		'user add-role'     => array( 'tier' => 'write', 'cap' => 'promote_users' ),
		'user remove-role'  => array( 'tier' => 'write', 'cap' => 'promote_users' ),
		'user meta get'     => array( 'tier' => 'read',  'cap' => 'list_users' ),
		'user meta list'    => array( 'tier' => 'read',  'cap' => 'list_users' ),
		'user meta add'     => array( 'tier' => 'write', 'cap' => 'edit_users' ),
		'user meta update'  => array( 'tier' => 'write', 'cap' => 'edit_users' ),
		'user meta delete'  => array( 'tier' => 'write', 'cap' => 'edit_users' ),
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

	/**
	 * HIGH_RISK_CAPS members excluded from the user-write approval GATE (they
	 * still drive the preview's high_risk_capabilities warning): Editor holds
	 * these on single-site, so gating on them would turn routine editor creation
	 * into an approval click. The gate predicate is derived as HIGH_RISK_CAPS
	 * minus this list (admin_equivalent_caps()), so a cap added to
	 * HIGH_RISK_CAPS automatically gates unless deliberately listed here.
	 */
	const UNGATED_EDITOR_CAPS = array(
		'unfiltered_html',
		'unfiltered_upload',
	);

	/**
	 * User-meta keys that ARE the capability/session system's storage. Writing
	 * them via `user meta` would grant roles or forge sessions around every cap
	 * check, so they are refused unconditionally — no --force escape, refused
	 * even on the approved path. Matching is case-insensitive (wp_usermeta
	 * meta_key collation is _ci) and by suffix so cross-blog/custom-prefix
	 * variants (wp_5_capabilities, customprefix_user_level) are all caught.
	 */
	const USER_META_HARD_DENY_PATTERNS = array(
		'/(^|_)capabilities$/',
		'/(^|_)user_level$/',
	);
	/**
	 * Credential-bearing user-meta keys: writes hard-denied AND values withheld
	 * on read. One constant feeds both checks so the deny and withhold lists
	 * cannot drift apart.
	 */
	const USER_META_CREDENTIAL_KEYS = array(
		'session_tokens',
		'_application_passwords',
	);
	/**
	 * Key patterns whose VALUES are withheld on read (write stays allowed):
	 * plugin-stored secrets — 2FA/TOTP seeds, backup codes, API keys — must not
	 * enter the model transcript. Best-effort by naming convention; matched
	 * against the lowercased key. A false positive only hides a value the user
	 * can still see in wp-admin, so patterns lean broad.
	 */
	const USER_META_SECRET_VALUE_PATTERNS = array(
		'/secret/',
		'/totp/',
		'/two[_-]?factor/',
		'/2fa/',
		'/backup[_-]?codes/',
		'/api[_-]?(key|token)/',
		'/private[_-]?key/',
		'/password/',
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
		'option pluck'      => 'handle_option_pluck',
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
		'post meta add'     => 'handle_post_meta_add',
		'post meta delete'  => 'handle_post_meta_delete',
		'post term set'     => 'handle_post_term_set',
		'post term add'     => 'handle_post_term_add',
		'post term remove'  => 'handle_post_term_remove',
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
		'user create'       => 'handle_user_create',
		'user update'       => 'handle_user_update',
		'user set-role'     => 'handle_user_set_role',
		'user add-role'     => 'handle_user_add_role',
		'user remove-role'  => 'handle_user_remove_role',
		'user meta get'     => 'handle_user_meta_get',
		'user meta list'    => 'handle_user_meta_list',
		'user meta add'     => 'handle_user_meta_add',
		'user meta update'  => 'handle_user_meta_update',
		'user meta delete'  => 'handle_user_meta_delete',
		'menu create'          => 'handle_menu_create',
		'menu item add-custom' => 'handle_menu_item_add',
		'menu item add-post'   => 'handle_menu_item_add',
		'menu item add-term'   => 'handle_menu_item_add',
		'menu location assign' => 'handle_menu_location_assign',
		'term create'          => 'handle_term_create',
		'term update'          => 'handle_term_update',
		'term delete'          => 'handle_term_delete',
		'menu item update'     => 'handle_menu_item_update',
		'menu item delete'     => 'handle_menu_item_delete',
		'theme mod set'        => 'handle_theme_mod_set',
		'rewrite structure'    => 'handle_rewrite_structure',
	);

	/**
	 * Theme mods safe to set via AI: scalar values a theme reads as data, never
	 * as markup echoed into the page. Anything not here is refused — a general
	 * set_theme_mod() is a stored-XSS sink because many themes print mods (header
	 * scripts, footer HTML) unescaped, and the CLI gate passes quoted markup as
	 * data. Script/HTML slots must go through code_snippet's approval panel.
	 */
	const THEME_MOD_SET_ALLOWED = array(
		'custom_logo',            // attachment ID (int)
		'background_color',       // hex, no #
		'header_textcolor',       // hex, or 'blank' to hide header text
		'background_image',       // URL (esc_url_raw'd)
		'header_image',           // URL
		'custom_css_post_id',     // post ID (int)
	);

	// Keys AIs guess as theme mods that are actually core OPTIONS: set_theme_mod
	// would write where nothing reads (exit-0 no-op). Redirected to option update.
	const THEME_MOD_OPTION_KEYS = array( 'page_for_posts', 'page_on_front', 'show_on_front' );

	const EMULATOR_NAME = 'WPVibe CLI emulation (vibe-ai plugin)';

	/** One-line usage per allowlisted command; the `help` catalog is generated from this. */
	const USAGE = array(
		'plugin list'             => 'plugin list [--status=<active|inactive>] [--update=available] [--fields=<fields>]',
		'plugin status'           => 'plugin status <slug>',
		'plugin search'           => 'plugin search <term> [--per_page=<n>] [--page=<n>]',
		'plugin get'              => 'plugin get <slug>',
		'plugin verify-checksums' => 'plugin verify-checksums [<slug>...] [--all] [--strict]',
		'theme list'              => 'theme list [--status=<active|inactive>] [--update=available] [--fields=<fields>]',
		'theme status'            => 'theme status <slug>',
		'theme mod list'          => 'theme mod list',
		'option get'              => 'option get <key>',
		'option pluck'            => 'option pluck <option> <key-path>... (read one nested key without fetching the whole option)',
		'option list'             => 'option list [--search=<pattern>] [--autoload=<on|off>] [--transients] [--format=json|ids|count] (transients are excluded unless --transients; capped at 100 rows)',
		'user list'               => 'user list [--role=<role>] [--number=<n>] [--format=json|ids|count] (defaults to 100 rows, max 1000)',
		'post list'               => 'post list [--post_type=<type>] [--post_status=<status>] [--posts_per_page=<n>] [--s=<search>] [--author=<id>] [--year=<yyyy>] [--monthnum=<1-12>] [--format=json|ids|count] (defaults to 20 rows, max 100)',
		'post get'                => 'post get <id> [--fields=<fields>]',
		'post meta get'           => 'post meta get <id> [<key>] [--all]',
		'post meta list'          => 'post meta list <id> [--all]',
		'post term set'           => 'post term set <id> <taxonomy> <term>... [--by=slug|id] (replaces all terms in the taxonomy; terms must already exist)',
		'post term add'           => 'post term add <id> <taxonomy> <term>... [--by=slug|id] (appends; terms must already exist)',
		'post term remove'        => 'post term remove <id> <taxonomy> <term>... [--by=slug|id] | --all',
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
		'transient get'           => 'transient get <key>',
		'menu item list'          => 'menu item list <menu>',
		'user get'                => 'user get <id|login|email> [--fields=<fields>]',
		'theme get'               => 'theme get <slug> [--fields=<fields>]',
		'cron test'               => 'cron test',
		'theme activate'          => 'theme activate <slug>',
		'plugin activate'         => 'plugin activate <slug>',
		'plugin deactivate'       => 'plugin deactivate <slug>',
		'plugin install'          => 'plugin install <slug> [--version=<version>] [--activate] [--force] (--force replaces an existing install, e.g. rollback to an older version; needs approval)',
		'plugin update'           => 'plugin update <slug>... | --all [--exclude=<slugs>] [--dry-run]',
		'plugin uninstall'        => 'plugin uninstall <slug> [<slug>...]',
		'option update'           => 'option update <key> <value> [--format=json|plaintext] (values are plain strings; pass --format=json for arrays/objects)',
		'option add'              => 'option add <key> <value> [--format=json|plaintext] [--autoload=<yes|no>]',
		'option delete'           => 'option delete <key> [<key>...]',
		'transient delete'        => 'transient delete <name> | --all | --expired',
		'user delete'             => 'user delete <id|login|email> [<id>...] [--reassign=<user>]',
		'post create'             => 'post create --post_title=<title> [--post_content=<content>] [--post_content_base64=<b64>] [--post_status=<status>] [--post_type=<type>] [--post_date=<Y-m-d H:i:s>] [--porcelain] (use base64 when content mixes single AND double quotes; plain --post_content silently drops colliding quotes; --porcelain returns just the new post ID)',
		'post update'             => 'post update <id> [<id>...] [--post_title=<title>] [--post_content=<content>] [--post_content_base64=<b64>] [--post_status=<status>] (use base64 when content mixes single AND double quotes)',
		'post delete'             => 'post delete <id> [<id>...] [--force]',
		'post meta update'        => 'post meta update <id> <key> <value> [--force] (replaces all rows of the key)',
		'post meta add'           => 'post meta add <id> <key> <value> [--force] (appends a row; use for multi-value metas)',
		'post meta delete'        => 'post meta delete <id> <key> [<value>] [--force] (with <value>: removes only the matching row)',
		'cache flush'             => 'cache flush',
		'cache purge'             => 'cache purge [--url=<url>[,<url>...]] [--skip=<target>[,...]] (detects the installed cache plugin and purges it, plus the object cache; --url purges just those URLs on engines with a URL API, others flush fully; --skip omits named targets, e.g. cloudflare,object)',
		'litespeed-purge'         => 'litespeed-purge [all] (alias of cache purge, scoped to LiteSpeed Cache)',
		'elementor flush-css'     => 'elementor flush-css [--regenerate] (purges the Elementor CSS cache; --regenerate rebuilds all CSS files immediately)',
		'elementor flush_css'     => 'elementor flush_css (alias of cache purge, scoped to the Elementor CSS cache)',
		'rocket clean'            => 'rocket clean (alias of cache purge, scoped to WP Rocket)',
		'sg purge'                => 'sg purge (alias of cache purge, scoped to SG Optimizer)',
		'super-cache flush'       => 'super-cache flush (alias of cache purge, scoped to WP Super Cache)',
		'w3-total-cache flush'    => 'w3-total-cache flush [all] (alias of cache purge, scoped to W3 Total Cache)',
		'breeze purge'            => 'breeze purge (alias of cache purge, scoped to Breeze)',
		'config get'              => 'config get <constant> (credentials/keys/salts are blocked; table_prefix supported)',
		'option patch'            => 'option patch <insert|update|delete> <option> <key-path>... [<value>] [--format=json|plaintext]',
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
		'user create'             => 'user create <user-login> <user-email> [--role=<role>] [--user_pass=<password>] [--display_name=<name>] [--first_name=<name>] [--last_name=<name>] [--user_url=<url>] [--send-email] [--porcelain] (omit --user_pass to auto-generate a hidden password and use --send-email to send the user a set-password link; --user_pass=<pw> is a last resort since it enters the raw command; creating an admin-capable role needs approval)',
		'user update'             => 'user update <id|login|email>... [--user_email=<email>] [--role=<role>] [--display_name=<name>] [--first_name=<name>] [--last_name=<name>] [--user_url=<url>] [--user_pass=<password>] [--skip-email] (password, email, and admin role changes need approval; prefer letting the user reset their own password over --user_pass)',
		'user set-role'           => 'user set-role <id|login|email> [<role>] (replaces all roles; granting or removing admin access needs approval)',
		'user add-role'           => 'user add-role <id|login|email> <role> [<role>...] (adds roles; granting an admin-capable role needs approval)',
		'user remove-role'        => 'user remove-role <id|login|email> <role> [<role>...] (removing admin access needs approval; the last admin is protected)',
		'user meta get'           => 'user meta get <id|login|email> <key>',
		'user meta list'          => 'user meta list <id|login|email> [--keys=<key,key>]',
		'user meta add'           => 'user meta add <id|login|email> <key> <value> [--force] (appends a row; --force overrides protected _-prefixed keys)',
		'user meta update'        => 'user meta update <id|login|email> <key> <value> [--force] (replaces the key; capability/session keys are never writable)',
		'user meta delete'        => 'user meta delete <id|login|email> <key> [<value>] [--force] (with <value>: removes only the matching row)',
		'search-replace'          => 'search-replace <old> <new> [<table>...] [--dry-run] [--skip-tables=<tables>] [--skip-columns=<cols>] [--include-guids] [--all-tables]',
		'menu create'             => 'menu create <menu-name> [--porcelain]',
		'menu item add-custom'    => 'menu item add-custom <menu> <title> <link> [--description=<text>] [--target=<_blank>] [--classes=<classes>] [--parent-id=<id>] [--position=<n>] [--porcelain]',
		'menu item add-post'      => 'menu item add-post <menu> <post-id> [--title=<title>] [--description=<text>] [--parent-id=<id>] [--position=<n>] [--porcelain]',
		'menu item add-term'      => 'menu item add-term <menu> <taxonomy> <term-id> [--title=<title>] [--description=<text>] [--parent-id=<id>] [--position=<n>] [--porcelain]',
		'menu location assign'    => 'menu location assign <menu> <location> (location must be registered by the active theme; see `menu location list`)',
		'term create'             => 'term create <taxonomy> <term> [--slug=<slug>] [--description=<text>] [--parent=<term-id>] [--porcelain]',
		'term update'             => 'term update <taxonomy> <term> [--by=<id|slug>] [--name=<name>] [--slug=<slug>] [--description=<text>] [--parent=<term-id>]',
		'term delete'             => 'term delete <taxonomy> <term>... [--by=<id|slug>] (permanent, no trash; requires approval)',
		'menu item update'        => 'menu item update <db-id> [--title=<title>] [--link=<url>] [--description=<text>] [--target=<_blank>] [--classes=<classes>] [--parent-id=<id>] [--position=<n>]',
		'menu item delete'        => 'menu item delete <db-id> [<db-id>...]',
		'theme mod set'           => 'theme mod set <mod> <value> (scalar mods only: custom_logo, background_color, header_textcolor, background_image, header_image, custom_css_post_id; HTML/script mods must use the code_snippet tool; homepage settings are options, use `option update page_on_front <id>` etc.)',
		'rewrite structure'       => 'rewrite structure <permastruct> [--category-base=<base>] [--tag-base=<base>] [--hard] (changes every URL on the site; requires approval)',
	);

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
	public function run_approved( $command, $confirm_write = false, $approved_state = null ) {
		// Snapshot of what the human approved (operation + dry_run), sent by
		// Workers that support the drift check. Only genuinely absent fails
		// open (older worker); present-but-unreadable refuses, or a worker
		// serialization bug would silently disable the check fleet-wide.
		if ( is_string( $approved_state ) && '' !== $approved_state ) {
			$decoded = json_decode( $approved_state, true );
			if ( ! is_array( $decoded ) ) {
				return new WP_Error(
					'approved_state_unreadable',
					__( 'Not run: the approval snapshot attached to this command could not be read, so the safety check comparing the site against what the user approved cannot run. This indicates a WPVibe server fault, not a problem with the site. Re-run the command; report it if this repeats.', 'vibe-ai' ),
					WPVibe_Error_Contract::data( 'approval_flow', false, array( 'status' => 400 ) )
				);
			}
			$this->approved_state = $decoded;
		}
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

		// Core's upgrader/delete functions assume wp-admin's filesystem bootstrap, which wp-admin pre-loads but the REST context does not.
		if ( ! empty( $meta['check_file_mods'] ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}

		// command_key from original tokens (flags are not positionals). Cache
		// purge family keeps --url= as a *command-local* flag; global --url
		// (WP-CLI site selection) stays blocked for every other command.
		$command_key = implode( ' ', array_slice( $this->get_positional( $tokens ), 0, $key_length ) );
		$keep_flags  = (
			isset( self::HANDLERS[ $command_key ] )
			&& 'handle_cache_purge' === self::HANDLERS[ $command_key ]
		) ? array( '--url' ) : array();
		$args        = $this->strip_blocked_flags( $tokens, $keep_flags );

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

		// Approve-to-execute drift check (issue #30): the approval was granted
		// against a state snapshot; refuse if the site no longer matches it.
		// Reuses the classification computed above — never classify twice, the
		// dry-run builders run preview SQL.
		if ( $skip_destructive ) {
			$drift = $this->check_approved_state_drift( $destructive );
			if ( is_wp_error( $drift ) ) {
				return $drift;
			}
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
				'params'         => array( 'positional' => $this->redact_sensitive_flags( $args ), 'key_length' => $key_length ),
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

	private function dispatch( $tokens, $key_length, $command_key, $confirm_write = false ) {
		if ( ! isset( self::HANDLERS[ $command_key ] ) ) {
			/* translators: %s: command key */
			return $this->error_result( sprintf( __( 'No handler for: %s', 'vibe-ai' ), $command_key ) );
		}

		list( $positional, $flags ) = $this->split_tokens( $tokens, $key_length );

		$this->current_command = $command_key;
		$handler               = self::HANDLERS[ $command_key ];
		return $this->{$handler}( $positional, $flags, $confirm_write );
	}
}
