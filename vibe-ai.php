<?php
/**
 * Plugin Name: WPVibe – Connect Your Site to Claude, ChatGPT & AI Assistants
 * Description: Connect any AI assistant to your WordPress site. Manage content, edit themes, and automate site tasks with Claude, ChatGPT, Cursor & more via MCP.
 * Version: 1.7.1
 * Author: SeedProd
 * Author URI: https://wpvibe.ai
 * License: GPL-2.0-or-later
 * Text Domain: vibe-ai
 * Domain Path: /languages/
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

defined( 'ABSPATH' ) || exit;

define( 'WPVIBE_VERSION', '1.7.1' );
define( 'WPVIBE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPVIBE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Core includes.
require_once WPVIBE_PLUGIN_DIR . 'includes/class-wpvibe-error-contract.php';
require_once WPVIBE_PLUGIN_DIR . 'includes/class-wpvibe-rest.php';
require_once WPVIBE_PLUGIN_DIR . 'includes/class-wpvibe-elementor.php';
require_once WPVIBE_PLUGIN_DIR . 'includes/class-wpvibe-file-ops.php';
require_once WPVIBE_PLUGIN_DIR . 'includes/class-wpvibe-content-ops.php';
require_once WPVIBE_PLUGIN_DIR . 'includes/class-wpvibe-draft-theme.php';
require_once WPVIBE_PLUGIN_DIR . 'includes/class-wpvibe-preview.php';
require_once WPVIBE_PLUGIN_DIR . 'includes/class-wpvibe-cli.php';
require_once WPVIBE_PLUGIN_DIR . 'includes/class-wpvibe-change-tracker.php';
require_once WPVIBE_PLUGIN_DIR . 'includes/class-wpvibe-live-reload.php';
require_once WPVIBE_PLUGIN_DIR . 'includes/class-wpvibe-classic-theme.php';
require_once WPVIBE_PLUGIN_DIR . 'includes/class-wpvibe-admin.php';
require_once WPVIBE_PLUGIN_DIR . 'includes/class-wpvibe-ping.php';
require_once WPVIBE_PLUGIN_DIR . 'includes/class-wpvibe-review-notice.php';
require_once WPVIBE_PLUGIN_DIR . 'includes/class-wpvibe-audit-log.php';
require_once WPVIBE_PLUGIN_DIR . 'includes/class-wpvibe-timing.php';
require_once WPVIBE_PLUGIN_DIR . 'includes/fields/class-wpvibe-fields.php';
require_once WPVIBE_PLUGIN_DIR . 'includes/fields/class-wpvibe-field-renderers.php';
require_once WPVIBE_PLUGIN_DIR . 'includes/fields/class-wpvibe-meta-boxes.php';
require_once WPVIBE_PLUGIN_DIR . 'includes/fields/class-wpvibe-settings.php';
require_once WPVIBE_PLUGIN_DIR . 'includes/fields/class-wpvibe-edit-affordance.php';
require_once WPVIBE_PLUGIN_DIR . 'includes/fields/class-wpvibe-post-sidebar.php';

/**
 * Initialize and return the WP_Filesystem instance.
 *
 * Wraps the standard bootstrap so callers can use get_contents / put_contents
 * instead of direct PHP file functions. Returns false if initialization fails.
 *
 * @return WP_Filesystem_Base|false
 */
function wpvibe_fs() {
	global $wp_filesystem;
	if ( ! empty( $wp_filesystem ) ) {
		return $wp_filesystem;
	}
	if ( ! function_exists( 'WP_Filesystem' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	if ( ! WP_Filesystem() ) {
		return false;
	}
	return $wp_filesystem;
}

/**
 * Validate PHP source syntax without executing it.
 *
 * Uses the tokenizer with TOKEN_PARSE so PHP throws ParseError/CompileError
 * on invalid syntax. Runs in-process — no shell exec or temp binaries.
 *
 * @param string $source PHP source code.
 * @param string $label  Label for the error message (e.g. basename).
 * @return true|WP_Error
 */
function wpvibe_check_php_syntax( $source, $label = '' ) {
	try {
		// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Suppress E_COMPILE_WARNING; we rely on the thrown ParseError below.
		@token_get_all( $source, TOKEN_PARSE );
	} catch ( \Error $e ) {
		return new WP_Error(
			'php_syntax',
			sprintf(
				/* translators: 1: file label, 2: error details */
				__( 'Syntax error in %1$s: %2$s', 'vibe-ai' ),
				'' !== $label ? $label : __( 'file', 'vibe-ai' ),
				$e->getMessage()
			),
			WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 422 ) )
		);
	}
	return true;
}

/**
 * Bootstrap the plugin.
 */
function wpvibe_init() {
	WPVibe_REST::instance();
	WPVibe_Elementor::instance();
	WPVibe_Ping::instance();
	WPVibe_Preview::instance();
	WPVibe_Live_Reload::instance();
	WPVibe_Audit_Log::maybe_install();
	WPVibe_Timing::instance();
	WPVibe_Fields::instance();
	WPVibe_Edit_Affordance::instance();
	if ( is_admin() ) {
		WPVibe_Admin::instance();
		WPVibe_Meta_Boxes::instance();
		WPVibe_Settings::instance();
		WPVibe_Post_Sidebar::instance();
		// Review-request nudge is paused for now — feature lives in the MCP layer instead.
		// Uncomment to re-enable the in-plugin admin notice; class + assets remain intact.
		// WPVibe_Review_Notice::instance();
	}
}
add_action( 'plugins_loaded', 'wpvibe_init' );

/**
 * Enqueue the Tailwind browser CDN + plugin-served presets.css only when
 * the active stylesheet is the recorded draft. The CDN runtime is what
 * lets the AI iterate on a draft without a build step; presets.css fills
 * in typography + form resets the CDN doesn't ship. The live (compiled)
 * theme enqueues dist/styles.css from its own functions.php, so neither
 * asset is loaded outside draft mode.
 */
function wpvibe_enqueue_draft_assets() {
	$draft = get_option( 'wpvibe_draft_theme' );
	if ( ! $draft || get_stylesheet() !== $draft ) {
		return;
	}
	wp_enqueue_style(
		'wpvibe-presets',
		WPVIBE_PLUGIN_URL . 'assets/presets.css',
		array(),
		WPVIBE_VERSION
	);
	wp_enqueue_script(
		'wpvibe-tailwind-cdn',
		'https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4',
		array(),
		'4',
		array( 'strategy' => 'defer' )
	);
}
add_action( 'wp_enqueue_scripts', 'wpvibe_enqueue_draft_assets' );

/**
 * Register an editable field on a post type.
 *
 * Theme authors call this from functions.php to declare which custom fields
 * exist for a given post type. The WPVibe plugin handles the admin UI
 * (meta box rendering, save handling, sanitization) and REST exposure.
 *
 * Wrap in if ( function_exists( 'wpvibe_field_register' ) ) so the theme
 * degrades gracefully when the plugin is deactivated.
 *
 * @param string $post_type Post type slug (e.g. 'page', 'book').
 * @param string $key       Meta key.
 * @param array  $config    See WPVibe_Fields::register_field().
 * @return true|WP_Error
 */
function wpvibe_field_register( $post_type, $key, $config ) {
	return WPVibe_Fields::instance()->register_field( $post_type, $key, $config );
}

/**
 * Register a global editable site setting.
 *
 * Renders on Settings -> WPVibe. Stored in wp_options; readable via
 * get_option( $key ) anywhere in templates.
 *
 * @param string $key
 * @param array  $config See WPVibe_Fields::register_setting().
 * @return true|WP_Error
 */
function wpvibe_setting_register( $key, $config ) {
	return WPVibe_Fields::instance()->register_setting( $key, $config );
}

/**
 * Register a meta-box group on a post type.
 *
 * Optional. Fields whose 'group' config matches $group_id render inside
 * this meta box; fields without a group land in a single default box.
 *
 * @param string $post_type
 * @param string $group_id
 * @param array  $config Title, context (normal|side|advanced), priority.
 * @return true|WP_Error
 */
function wpvibe_field_group_register( $post_type, $group_id, $config ) {
	return WPVibe_Fields::instance()->register_group( $post_type, $group_id, $config );
}
