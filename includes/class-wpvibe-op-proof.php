<?php
/**
 * Worker-signed per-operation proof for the approval-only routes.
 *
 * cli/run-approved and code-snippet execute what a human approved in the
 * Worker's browser flow. They authenticate with the same application password
 * the model's own tools hold, so nothing plugin-side told a Worker call from a
 * model-shaped request. Once the Worker provisions a per-site key, every call
 * to those routes must carry an HMAC over the op id, the route, a hash of the
 * exact payload, and an expiry. The key never leaves the Worker and this site.
 *
 * Unprovisioned sites keep the legacy behavior (bare app-password auth) so
 * older Workers and fresh connections keep working; the Worker provisions on
 * first contact with a plugin that ships this class.
 */

defined( 'ABSPATH' ) || exit;

class WPVibe_Op_Proof {

	const OPTION   = 'wpvibe_op_proof_key';
	const HEADER   = 'x_wpvibe_op_proof';
	/** Seconds a proof's expiry may sit in the future (clock skew + relay time). */
	const MAX_TTL = 3600;

	public static function key() {
		$key = get_option( self::OPTION, '' );
		return is_string( $key ) && preg_match( '/^[a-f0-9]{64}$/', $key ) ? $key : '';
	}

	public static function provisioned() {
		return '' !== self::key();
	}

	/**
	 * Store a key. A first key needs only manage_options (the connection's
	 * trust level today); replacing a live key needs a proof under the old one,
	 * so a leaked application password cannot swap in a key it controls.
	 */
	public static function set_key( $request ) {
		$raw = is_object( $request ) && method_exists( $request, 'get_param' ) ? (string) $request->get_param( 'key' ) : '';
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $raw ) ) {
			return new WP_Error( 'wpvibe_op_proof_bad_key', __( 'The proof key must be 64 hex characters.', 'vibe-ai' ), array( 'status' => 400 ) );
		}
		if ( self::provisioned() ) {
			$ok = self::verify( $request, '/wpvibe/v1/op-proof/key', $raw );
			if ( true !== $ok ) {
				return new WP_Error( 'wpvibe_op_proof_rotation_denied', __( 'A proof key is already set; rotating it needs a proof signed with the current key. Reconnect the site from WPVibe to reset it.', 'vibe-ai' ), array( 'status' => 403 ) );
			}
		}
		update_option( self::OPTION, $raw, false );
		return true;
	}

	/** The authorize flow issues a fresh connection; the next Worker contact re-provisions. */
	public static function reset() {
		delete_option( self::OPTION );
	}

	/**
	 * true, or a WP_Error the permission callback returns. Message the Worker
	 * signs: op_id LF route LF sha256(subject) LF exp; header v1.<exp>.<hex>.
	 */
	public static function verify( $request, $route, $subject ) {
		$key = self::key();
		if ( '' === $key ) {
			return true;
		}
		$get = function ( $name ) use ( $request ) {
			return is_object( $request ) && method_exists( $request, 'get_header' ) ? (string) $request->get_header( $name ) : '';
		};
		$op_id = WPVibe_Op_Receipts::sanitize_op_id( $get( 'x_wpvibe_op_id' ) );
		$proof = $get( self::HEADER );
		if ( '' === $op_id || ! preg_match( '/^v1\.([0-9]{1,12})\.([a-f0-9]{64})$/', $proof, $m ) ) {
			return new WP_Error( 'wpvibe_op_proof_missing', __( 'This route executes approved operations and needs a WPVibe-signed operation proof. It is not callable directly; run the operation through the WPVibe tools so the approval flow signs it.', 'vibe-ai' ), array( 'status' => 403 ) );
		}
		$exp = (int) $m[1];
		if ( $exp < time() || $exp > time() + self::MAX_TTL ) {
			return new WP_Error( 'wpvibe_op_proof_expired', __( 'The operation proof has expired; ask WPVibe to run the operation again.', 'vibe-ai' ), array( 'status' => 403 ) );
		}
		$message  = $op_id . "\n" . (string) $route . "\n" . hash( 'sha256', (string) $subject ) . "\n" . $exp;
		$expected = hash_hmac( 'sha256', $message, hex2bin( $key ) );
		if ( ! hash_equals( $expected, $m[2] ) ) {
			return new WP_Error( 'wpvibe_op_proof_invalid', __( 'The operation proof does not match this request. If the site was restored or reconnected, reconnect it from WPVibe so a fresh key is issued.', 'vibe-ai' ), array( 'status' => 403 ) );
		}
		return true;
	}
}
