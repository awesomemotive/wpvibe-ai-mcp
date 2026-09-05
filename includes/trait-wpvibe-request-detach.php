<?php
/**
 * Shared hand-off mechanics for work that must outlive the HTTP request:
 * release the connection and keep running (PHP-FPM, LiteSpeed), or hand the
 * job to the site itself over a one-time token. Never WP-Cron.
 */

defined( 'ABSPATH' ) || exit;

trait WPVibe_Request_Detach {

	/** The function that ends the HTTP response while PHP keeps running, or null. */
	protected function finish_request_function() {
		foreach ( array( 'fastcgi_finish_request', 'litespeed_finish_request' ) as $fn ) {
			if ( function_exists( $fn ) ) {
				return $fn;
			}
		}
		return null;
	}

	/** Push every buffer to the client so the finish primitive ends a complete body. */
	protected function flush_output() {
		while ( ob_get_level() > 0 ) {
			@ob_end_flush(); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		flush();
	}

	/**
	 * Serve a WP_REST_Response with an exact length and end the connection;
	 * the caller keeps running afterwards. For use from rest_pre_serve_request.
	 */
	protected function release_response( $result ) {
		// Before anything is written: a write to a closed socket with this off ends the script.
		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}
		$body = (string) wp_json_encode( $result->get_data() );
		if ( ! headers_sent() ) {
			header( 'Content-Length: ' . strlen( $body ) );
			header( 'Connection: close' );
		}
		if ( function_exists( 'apache_setenv' ) ) {
			@apache_setenv( 'no-gzip', '1' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$this->flush_output();
		$finish = $this->finish_request_function();
		if ( null !== $finish ) {
			$finish();
		}
	}

	/** Can the site reach its own REST API? Cached for an hour: the answer is a property of the host. */
	protected function probe_loopback() {
		$cached = get_transient( 'wpvibe_loopback_ok' );
		if ( '1' === $cached || '0' === $cached ) {
			return '1' === $cached;
		}
		$probe = wp_remote_get( rest_url( 'wpvibe/v1/self-update/health' ), array(
			'timeout'   => 3,
			'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
		) );
		$code = is_wp_error( $probe ) ? 0 : (int) wp_remote_retrieve_response_code( $probe );
		$ok   = $code >= 200 && $code < 300;
		set_transient( 'wpvibe_loopback_ok', $ok ? '1' : '0', HOUR_IN_SECONDS );
		return $ok;
	}

	/** Fire-and-forget POST to one of our own routes. Store the token's hash before calling this. */
	protected function post_loopback( $route, array $body ) {
		wp_remote_post( rest_url( $route ), array(
			'blocking'  => false,
			'timeout'   => 2,
			'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			'body'      => $body,
		) );
	}
}
