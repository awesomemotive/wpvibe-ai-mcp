<?php
/** Worker-only signing secrets; this installation stores public verification material only. */
defined( 'ABSPATH' ) || exit;

class WPVibe_Op_Proof_V2 {
	const HEADER = 'x_wpvibe_op_proof_v2';
	const MAX_TTL = 300;
	const PUBLIC_KEYS = array( 'wpvibe-2026-09' => 'OHRBXR8-KUfiZbwG9dlBppJsfFiX58eA5kq_AAP4mhw' );

	public static function audience() {
		$parts = wp_parse_url( site_url() );
		return strtolower( $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . rtrim( $parts['path'] ?? '', '/' );
	}

	public static function verify( $request, $route, $subject ) {
		$proof = (string) $request->get_header( self::HEADER );
		$op_id = WPVibe_Op_Receipts::sanitize_op_id( $request->get_header( 'x_wpvibe_op_id' ) );
		if ( '' === $op_id || ! preg_match( '/^v2\.([a-zA-Z0-9_-]{1,64})\.([0-9]{1,12})\.([a-zA-Z0-9_-]{86})$/D', $proof, $m ) ) {
			return self::error( 'missing', __( 'This operation requires a current WPVibe-signed approval. Keep the site connected and use the WPVibe approval flow.', 'vibe-ai' ) );
		}
		$exp = (int) $m[2];
		if ( $exp < time() || $exp > time() + self::MAX_TTL ) {
			return self::error( 'expired', __( 'The operation approval expired. Request a fresh approval in WPVibe.', 'vibe-ai' ) );
		}
		if ( ! isset( static::PUBLIC_KEYS[ $m[1] ] ) ) {
			return self::error( 'unknown_key', __( 'The signing key is not recognized. Update WPVibe and check the connection again.', 'vibe-ai' ) );
		}
		// Connection observations authenticate with the Worker signature itself. All executable
		// routes also bind the signature to the application password WP authenticated.
		if ( '/wpvibe/v1/connection-status' === $route ) {
			$credential = '-';
		} else {
			$password = preg_replace( '/[^a-z0-9]/i', '', (string) ( $_SERVER['PHP_AUTH_PW'] ?? '' ) );
			if ( '' === $password ) { return self::error( 'invalid', __( 'The approved connection credential is missing.', 'vibe-ai' ) ); }
			$credential = hash( 'sha256', $password );
		}
		$message = implode( "\n", array( 'wpvibe-op-proof-v2', $m[1], static::audience(), $credential, $op_id, (string) $route, hash( 'sha256', (string) $subject ), $exp ) );
		$signature = base64_decode( strtr( $m[3], '-_', '+/' ), true );
		$public_key = base64_decode( strtr( static::PUBLIC_KEYS[ $m[1] ], '-_', '+/' ), true );
		try {
			if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
				$compat = ABSPATH . WPINC . '/sodium_compat/autoload.php';
				if ( is_file( $compat ) ) { require_once $compat; }
			}
			$valid = false;
			if ( is_string( $signature ) && 64 === strlen( $signature ) && is_string( $public_key ) && 32 === strlen( $public_key ) ) {
				if ( function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
					$valid = sodium_crypto_sign_verify_detached( $signature, $message, $public_key );
				} elseif ( class_exists( 'ParagonIE_Sodium_Compat' ) ) {
					$valid = ParagonIE_Sodium_Compat::crypto_sign_verify_detached( $signature, $message, $public_key );
				}
			}
		} catch ( Throwable $error ) { $valid = false; }
		return $valid ? true : self::error( 'invalid', __( 'The signed approval does not match this site, connection, or operation. Keep the existing connection and send the failed check to WPVibe support.', 'vibe-ai' ) );
	}

	private static function error( $code, $message ) {
		return new WP_Error( 'wpvibe_op_proof_' . $code, $message, array( 'status' => 403 ) );
	}
}
