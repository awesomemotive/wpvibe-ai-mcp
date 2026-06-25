<?php
/**
 * Per-request REST timing.
 *
 * Hooks the WordPress REST dispatch lifecycle to measure how long PHP
 * actually spent handling a /wp-json or ?rest_route= request, then emits
 * the result as an X-WPVibe-PHP-Time-Ms response header. The Worker
 * reads that header and surfaces it as the wp_php_ms field on
 * site_info's performance block, so the AI can tell users where their
 * latency is coming from when they ask "why is this slow?"
 *
 * Header is emitted on every REST response (not just /wpvibe/v1/), so
 * the Worker has timing data available for all /wp/v2/ calls too. The
 * overhead is one microtime() call per request and a 30-byte header.
 */

defined( 'ABSPATH' ) || exit;

class WPVibe_Timing {

	private static $instance = null;
	private static $start    = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'rest_pre_dispatch',  array( $this, 'mark_start' ), 1, 3 );
		add_filter( 'rest_post_dispatch', array( $this, 'emit_header' ), 999, 3 );
	}

	public function mark_start( $result, $server, $request ) {
		if ( null === self::$start ) {
			self::$start = microtime( true );
		}
		return $result;
	}

	public function emit_header( $response, $server, $request ) {
		if ( null === self::$start ) {
			return $response;
		}
		$ms = (int) round( ( microtime( true ) - self::$start ) * 1000 );
		if ( $response instanceof WP_REST_Response ) {
			$response->header( 'X-WPVibe-PHP-Time-Ms', (string) $ms );
		}
		return $response;
	}
}
