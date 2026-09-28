<?php
/**
 * Restores a credential the web server withheld from PHP.
 *
 * Apache does not hand Authorization to CGI/FastCGI unless CGIPassAuth is on
 * (RFC 3875 tells servers not to), and some LiteSpeed setups behave the same.
 * WordPress then treats WPVibe's authenticated REST calls as anonymous and
 * denies them, which reads like a permissions problem but is not one. WPVibe
 * sends the same credential under X-WPVibe-Authorization, which those servers
 * pass through untouched, and this puts it back where core expects it.
 *
 * No boundary moves: anyone who could send this header could already send
 * Authorization directly, and core validates the credential exactly as before.
 *
 * @package WPVibe
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPVibe_Auth_Fallback {

	const FALLBACK_KEY = 'HTTP_X_WPVIBE_AUTHORIZATION';

	/** Server variables core populates from a real Authorization header. */
	const REAL_KEYS = array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'PHP_AUTH_USER', 'PHP_AUTH_PW' );

	/**
	 * Whether any normal route delivered something credential-shaped.
	 *
	 * This is what the 401 diagnostics and the connectivity check report as
	 * "header seen": it records that the header arrived, not that it worked.
	 *
	 * @param array $server $_SERVER snapshot.
	 * @return bool
	 */
	public static function header_seen( array $server ) {
		foreach ( self::REAL_KEYS as $key ) {
			if ( ! empty( $server[ $key ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the server already supplied usable credentials.
	 *
	 * Core reads only PHP_AUTH_USER and PHP_AUTH_PW, filled in by the server or
	 * by wp_populate_basic_auth_from_authorization_header() before plugins load.
	 * An Authorization header core could not parse (comma-folded, another
	 * scheme) leaves them unset, so it must not block the fallback. Server-level
	 * htpasswd sets both, so protected staging sites are still left alone.
	 *
	 * @param array $server $_SERVER snapshot.
	 * @return bool
	 */
	public static function has_server_credentials( array $server ) {
		// isset(), as core checks: a password of "0" or "" is still the server's.
		return isset( $server['PHP_AUTH_USER'], $server['PHP_AUTH_PW'] );
	}

	/**
	 * Credentials to restore, or null when the fallback does not apply.
	 *
	 * Pure: no side effects, no WordPress dependencies, so the decision can be
	 * tested directly. The caller owns the $_SERVER mutation.
	 *
	 * @param array $server $_SERVER snapshot, already unslashed.
	 * @return array|null array( header, user, pass ) or null.
	 */
	public static function parse( array $server ) {
		if ( self::has_server_credentials( $server ) ) {
			return null;
		}
		if ( empty( $server[ self::FALLBACK_KEY ] ) || ! is_string( $server[ self::FALLBACK_KEY ] ) ) {
			return null;
		}
		$header = $server[ self::FALLBACK_KEY ];
		// Fails closed on the comma-folded duplicates some proxies produce, and
		// on anything that is not a well-formed Basic credential.
		if ( ! preg_match( '#^Basic [A-Za-z0-9+/=]+$#', $header ) ) {
			return null;
		}
		$decoded = base64_decode( substr( $header, 6 ), true );
		if ( ! is_string( $decoded ) || false === strpos( $decoded, ':' ) ) {
			return null;
		}
		// Application passwords contain spaces, and a password may itself
		// contain colons, so split on the first one only.
		list( $user, $pass ) = explode( ':', $decoded, 2 );
		if ( '' === $user || '' === $pass ) {
			return null;
		}
		return array(
			'header' => $header,
			'user'   => $user,
			'pass'   => $pass,
		);
	}

	/**
	 * Apply the fallback to the live request, and record what the server
	 * delivered so 401 diagnostics can report facts instead of inferring.
	 *
	 * Constants are set before any mutation below, so they describe the request
	 * as it arrived rather than what this method then put back.
	 */
	public static function apply() {
		$server = wp_unslash( $_SERVER ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		define( 'WPVIBE_AUTH_HEADER_SEEN', self::header_seen( $server ) );
		define( 'WPVIBE_AUTH_FALLBACK_SEEN', ! empty( $server[ self::FALLBACK_KEY ] ) );

		$applied = false;
		$creds   = self::parse( $server );
		if (
			$creds
			&& ! ( defined( 'WPVIBE_DISABLE_AUTH_FALLBACK' ) && WPVIBE_DISABLE_AUTH_FALLBACK )
			&& WPVibe_REST::application_password_is_api_request( false )
			&& apply_filters( 'wpvibe_allow_auth_fallback', true )
		) {
			// Core reads PHP_AUTH_* in wp_validate_application_password();
			// setting only HTTP_AUTHORIZATION would do nothing at all.
			$_SERVER['HTTP_AUTHORIZATION'] = $creds['header'];
			$_SERVER['PHP_AUTH_USER']      = $creds['user'];
			$_SERVER['PHP_AUTH_PW']        = $creds['pass'];
			$applied                       = true;
		}
		define( 'WPVIBE_AUTH_FALLBACK_APPLIED', $applied );
	}
}
