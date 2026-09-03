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
	/** Set once a key has ever been stored; reset() leaves it, so a missing key fails closed. */
	const REQUIRED = 'wpvibe_op_proof_required';
	/** The application password the last authorize minted: the only credential that may seed the next key. */
	const MINTER   = 'wpvibe_op_proof_minter';
	const HEADER   = 'x_wpvibe_op_proof';
	/** Seconds a proof's expiry may sit in the future (clock skew + relay time). */
	const MAX_TTL = 3600;

	/** uuid of the application password that authenticated this request, when one did. */
	private static $auth_uuid = '';

	public static function register() {
		// Priority 1 clears the note before core authenticates, so a persistent PHP worker never carries it over.
		add_filter( 'determine_current_user', array( __CLASS__, 'forget_authenticated' ), 1 );
		add_action( 'application_password_did_authenticate', array( __CLASS__, 'note_authenticated' ), 10, 2 );
	}

	public static function forget_authenticated( $user ) {
		self::$auth_uuid = '';
		return $user;
	}

	public static function note_authenticated( $user, $item ) {
		self::$auth_uuid = is_array( $item ) && isset( $item['uuid'] ) ? (string) $item['uuid'] : '';
	}

	/** Whether this request's own Basic credential is the application password with this uuid. */
	private static function request_carries( $uuid ) {
		if ( '' === (string) $uuid || ! isset( $_SERVER['PHP_AUTH_PW'] ) || ! class_exists( 'WP_Application_Passwords' ) ) {
			return false;
		}
		$password = preg_replace( '/[^a-z\d]/i', '', (string) $_SERVER['PHP_AUTH_PW'] );
		if ( '' === $password || ! method_exists( 'WP_Application_Passwords', 'get_user_application_password' ) || ! method_exists( 'WP_Application_Passwords', 'check_password' ) ) {
			return false;
		}
		$item = WP_Application_Passwords::get_user_application_password( get_current_user_id(), (string) $uuid );
		return is_array( $item ) && ! empty( $item['password'] ) && WP_Application_Passwords::check_password( $password, $item['password'] );
	}

	public static function key() {
		$key = get_option( self::OPTION, '' );
		return is_string( $key ) && preg_match( '/^[a-f0-9]{64}$/', $key ) ? $key : '';
	}

	public static function provisioned() {
		return '' !== self::key();
	}

	public static function required() {
		return (bool) get_option( self::REQUIRED, false );
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
		} else {
			// After a reconnect, only the credential that reconnect minted may seed the key.
			$minter = (string) get_option( self::MINTER, '' );
			if ( '' !== $minter && $minter !== self::$auth_uuid && ! self::request_carries( $minter ) ) {
				return new WP_Error( 'wpvibe_op_proof_minter_mismatch', __( 'The first proof key after a reconnect must arrive under the application password that reconnect created. Approve the connection again from the WPVibe connect link in your browser; entering credentials by hand does not issue a new key.', 'vibe-ai' ), array( 'status' => 403 ) );
			}
		}
		update_option( self::OPTION, $raw, false );
		update_option( self::REQUIRED, 1, false );
		delete_option( self::MINTER );
		return true;
	}

	/**
	 * The authorize flow issues a fresh connection; the next Worker contact
	 * re-provisions. The requirement flag stays: between reset and that first
	 * contact the approved routes refuse rather than fall back to bare auth.
	 */
	public static function reset( $minter_uuid = '' ) {
		if ( self::provisioned() ) {
			update_option( self::REQUIRED, 1, false );
		}
		delete_option( self::OPTION );
		if ( '' !== (string) $minter_uuid ) {
			update_option( self::MINTER, (string) $minter_uuid, false );
		} else {
			delete_option( self::MINTER );
		}
	}

	/**
	 * true, or a WP_Error the permission callback returns. Message the Worker
	 * signs: op_id LF route LF sha256(subject) LF exp; header v1.<exp>.<hex>.
	 */
	public static function verify( $request, $route, $subject ) {
		$key = self::key();
		// Sites provisioned before the flag existed learn it on their first signed call.
		if ( '' !== $key && ! self::required() ) {
			update_option( self::REQUIRED, 1, false );
		}
		if ( '' === $key ) {
			if ( self::required() ) {
				return new WP_Error( 'wpvibe_op_proof_unprovisioned', __( 'This site once held a WPVibe operation proof key and now has none, so approved operations are refused until the key is issued again. Approve the connection again from the WPVibe connect link in your browser; that connection provisions a key on first contact. Entering credentials by hand does not.', 'vibe-ai' ), array( 'status' => 403 ) );
			}
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
