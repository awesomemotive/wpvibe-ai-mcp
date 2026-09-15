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
		// XSERVER's Command WAF 501s any URL containing "ping"; /health is the same payload under a name off that list.
		foreach ( array( '/ping', '/health' ) as $route ) {
			register_rest_route( 'wpvibe/v1', $route, array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'ping' ),
				'permission_callback' => '__return_true',
				'args'                => array(),
			) );
		}
	}

	/**
	 * Return plugin + WordPress version. Deliberately omits PHP version and
	 * site metadata — keep the public surface tiny.
	 */
	public function ping() {
		$payload = array(
			'plugin'         => 'wpvibe',
			'plugin_version' => defined( 'WPVIBE_VERSION' ) ? WPVIBE_VERSION : '',
			'wp_version'     => get_bloginfo( 'version' ),
			'features'       => class_exists( 'WPVibe_REST' ) ? WPVibe_REST::feature_flags() : array(),
		);
		$echo = $this->loopback_echo();
		if ( null !== $echo ) {
			$payload['auth_headers_seen'] = $echo;
		}
		return rest_ensure_response( $payload );
	}

	// Only the site's own connectivity check (holding the loopback token) learns which auth headers survive the host; values are never echoed.
	private function loopback_echo() {
		$token = isset( $_SERVER['HTTP_X_WPVIBE_LOOPBACK'] ) ? (string) $_SERVER['HTTP_X_WPVIBE_LOOPBACK'] : '';
		if ( '' === $token || ! class_exists( 'WPVibe_Connection_Check' ) || ! WPVibe_Connection_Check::loopback_token_valid( $token ) ) {
			return null;
		}
		// WPVibe_Auth_Fallback::apply() may have copied the fallback header into HTTP_AUTHORIZATION by now; the constants record what actually arrived.
		return array(
			'authorization' => defined( 'WPVIBE_AUTH_HEADER_SEEN' ) ? (bool) WPVIBE_AUTH_HEADER_SEEN : ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) || ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) || ! empty( $_SERVER['PHP_AUTH_USER'] ) ),
			'fallback'      => defined( 'WPVIBE_AUTH_FALLBACK_SEEN' ) ? (bool) WPVIBE_AUTH_FALLBACK_SEEN : ! empty( $_SERVER['HTTP_X_WPVIBE_AUTHORIZATION'] ),
		);
	}
}
