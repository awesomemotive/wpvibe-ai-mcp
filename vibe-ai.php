<?php
/**
 * Plugin Name: Vibe AI – Connect Your Site to Claude, ChatGPT & AI Assistants
 * Description: Connect any AI assistant to your WordPress site. Manage content, edit themes, and automate site tasks with Claude, ChatGPT, Cursor & more via MCP.
 * Version: 1.2.3
 * Author: SeedProd
 * Author URI: https://wpvibe.ai
 * License: GPL-2.0-or-later
 * Text Domain: vibe-ai
 * Domain Path: /languages/
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

defined( 'ABSPATH' ) || exit;

define( 'WPVIBE_VERSION', '1.2.3' );
define( 'WPVIBE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPVIBE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Core includes.
require_once WPVIBE_PLUGIN_DIR . 'includes/class-wpvibe-rest.php';
require_once WPVIBE_PLUGIN_DIR . 'includes/class-wpvibe-file-ops.php';
require_once WPVIBE_PLUGIN_DIR . 'includes/class-wpvibe-draft-theme.php';
require_once WPVIBE_PLUGIN_DIR . 'includes/class-wpvibe-preview.php';
require_once WPVIBE_PLUGIN_DIR . 'includes/class-wpvibe-cli.php';
require_once WPVIBE_PLUGIN_DIR . 'includes/class-wpvibe-change-tracker.php';
require_once WPVIBE_PLUGIN_DIR . 'includes/class-wpvibe-live-reload.php';
require_once WPVIBE_PLUGIN_DIR . 'includes/class-wpvibe-classic-theme.php';
require_once WPVIBE_PLUGIN_DIR . 'includes/class-wpvibe-admin.php';

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
			array( 'status' => 422 )
		);
	}
	return true;
}

/**
 * Bootstrap the plugin.
 */
function wpvibe_init() {
	WPVibe_REST::instance();
	WPVibe_Preview::instance();
	WPVibe_Live_Reload::instance();
	if ( is_admin() ) {
		WPVibe_Admin::instance();
	}
}
add_action( 'plugins_loaded', 'wpvibe_init' );
