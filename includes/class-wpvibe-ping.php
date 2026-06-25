<?php
/**
 * Unauthenticated ping endpoint for WPVibe MCP pre-flight probes.
 *
 * The MCP server calls GET /wpvibe/v1/ping before generating an OAuth magic link
 * so it can detect plugin presence + version without requiring the user to
 * authenticate first. Returns a deliberately minimal payload — anything richer
 * lives behind /site-info, which requires edit_theme_options.
 *
 * Without this endpoint, the MCP falls back to probing /site-info and reading
 * the 401-vs-404 response code to infer plugin presence. /ping is faster and
 * lets us surface plugin_version for staleness warnings.
 */

defined( 'ABSPATH' ) || exit;

class WPVibe_Ping {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
	}

	public function register_route() {
		register_rest_route( 'wpvibe/v1', '/ping', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'ping' ),
			'permission_callback' => '__return_true',
			'args'                => array(),
		) );
	}

	/**
	 * Return plugin + WordPress version. Deliberately omits PHP version and
	 * site metadata — keep the public surface tiny.
	 */
	public function ping() {
		return rest_ensure_response( array(
			'plugin'         => 'wpvibe',
			'plugin_version' => defined( 'WPVIBE_VERSION' ) ? WPVIBE_VERSION : '',
			'wp_version'     => get_bloginfo( 'version' ),
			'features'       => class_exists( 'WPVibe_REST' ) ? WPVibe_REST::feature_flags() : array(),
		) );
	}
}
