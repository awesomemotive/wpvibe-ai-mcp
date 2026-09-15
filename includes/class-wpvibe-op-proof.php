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
	const PENDING_MINTER = 'wpvibe_op_proof_pending_minter';
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

	public static function awaiting_confirmation() {
		if ( self::provisioned() ) { return false; }
		$pending = get_option( self::PENDING_MINTER, array() );
		return is_array( $pending ) && ! empty( $pending['expires'] ) && (int) $pending['expires'] > time();
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
		$previous = get_option( self::OPTION, false );
		$minter = get_option( self::MINTER, false );
		$had_key = is_string( $previous ) && preg_match( '/^[a-f0-9]{64}$/', $previous );
		if ( $had_key ) {
			$ok = self::verify( $request, '/wpvibe/v1/op-proof/key', $raw );
			if ( true !== $ok ) {
				return new WP_Error( 'wpvibe_op_proof_rotation_denied', __( 'A proof key is already set; rotating it needs a proof signed with the current key. Reconnect the site from WPVibe to reset it.', 'vibe-ai' ), array( 'status' => 403 ) );
			}
		} else {
			// After a reconnect, only the credential that reconnect minted may seed the key.
			if ( false !== $minter && '' !== (string) $minter && (string) $minter !== self::$auth_uuid && ! self::request_carries( $minter ) ) {
				return new WP_Error( 'wpvibe_op_proof_minter_mismatch', __( 'The first proof key after a reconnect must arrive under the application password that reconnect created. Approve the connection again from the WPVibe connect link in your browser; entering credentials by hand does not issue a new key.', 'vibe-ai' ), array( 'status' => 403 ) );
			}
		}
		$changed = self::portable_storage() ? self::store_key_portable( $raw, $previous, $minter ) : self::store_key_atomic( $raw, $previous, $minter );
		if ( 1 !== $changed ) {
			return new WP_Error( 'wpvibe_op_proof_provision_conflict', __( 'The connection changed while provisioning its proof key. Retry the connection check.', 'vibe-ai' ), array( 'status' => 409 ) );
		}
		self::clear_key_cache();
		update_option( self::REQUIRED, 1, false );
		// A key set under this approval settles it; the staged uuid must not stay usable for a later rotation.
		delete_option( self::PENDING_MINTER );
		if ( ! $had_key && false !== $minter ) {
			self::consume_minter( $minter, $raw );
			self::clear_key_cache();
		}
		return true;
	}

	/** SQLite installs (Studio, Playground, the SQLite integration plugin) cannot run the multi-table statements below. */
	private static function portable_storage() {
		global $wpdb;
		if ( ( defined( 'DB_ENGINE' ) && 'sqlite' === strtolower( (string) DB_ENGINE ) ) || ( defined( 'DATABASE_TYPE' ) && 'sqlite' === strtolower( (string) DATABASE_TYPE ) ) ) {
			return true;
		}
		if ( class_exists( 'WP_SQLite_DB' ) && $wpdb instanceof WP_SQLite_DB ) {
			return true;
		}
		return (bool) apply_filters( 'wpvibe_op_proof_portable_storage', false );
	}

	/** A raw statement the engine rejected outright (not merely zero rows) hands over to the option API. */
	private static function rejected( $result ) {
		global $wpdb;
		return false === $result && '' !== (string) $wpdb->last_error;
	}

	private static function minter_matches( $minter ) {
		$current = get_option( self::MINTER, false );
		return false === $minter ? false === $current : false !== $current && maybe_serialize( $current ) === maybe_serialize( $minter );
	}

	private static function store_key_atomic( $raw, $previous, $minter ) {
		global $wpdb;
		$minter_present = false === $minter ? 0 : 1;
		$minter_guard = '((%d = 0 AND minter.option_id IS NULL) OR (%d = 1 AND BINARY minter.option_value = %s))';
		$join = "LEFT JOIN {$wpdb->options} AS minter ON minter.option_name = %s";
		if ( false === $previous ) {
			$changed = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name,option_value,autoload) SELECT %s,%s,'no' FROM (SELECT 1) AS seed LEFT JOIN {$wpdb->options} AS active_key ON active_key.option_name = %s {$join} WHERE active_key.option_id IS NULL AND {$minter_guard}", self::OPTION, $raw, self::OPTION, self::MINTER, $minter_present, $minter_present, maybe_serialize( $minter ) ) );
		} elseif ( $raw === $previous ) {
			$present = $wpdb->get_var( $wpdb->prepare( "SELECT active_key.option_value FROM {$wpdb->options} AS active_key {$join} WHERE active_key.option_name = %s AND BINARY active_key.option_value = %s AND {$minter_guard}", self::MINTER, self::OPTION, $raw, $minter_present, $minter_present, maybe_serialize( $minter ) ) );
			$changed = '' !== (string) $wpdb->last_error && null === $present ? false : ( $raw === $present ? 1 : 0 );
		} else {
			$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} AS active_key {$join} SET active_key.option_value = %s WHERE active_key.option_name = %s AND BINARY active_key.option_value = %s AND {$minter_guard}", self::MINTER, $raw, self::OPTION, maybe_serialize( $previous ), $minter_present, $minter_present, maybe_serialize( $minter ) ) );
		}
		return self::rejected( $changed ) ? self::store_key_portable( $raw, $previous, $minter ) : $changed;
	}

	private static function store_key_portable( $raw, $previous, $minter ) {
		self::clear_key_cache();
		if ( ! self::minter_matches( $minter ) ) {
			return 0;
		}
		if ( false === $previous ) {
			if ( ! add_option( self::OPTION, $raw, '', false ) ) {
				return 0;
			}
			if ( ! self::minter_matches( $minter ) ) {
				delete_option( self::OPTION );
				return 0;
			}
			return 1;
		}
		$current = get_option( self::OPTION, false );
		if ( $raw === $previous ) {
			return $current === $raw ? 1 : 0;
		}
		return $current === $previous && update_option( self::OPTION, $raw, false ) ? 1 : 0;
	}

	private static function consume_minter( $minter, $raw ) {
		global $wpdb;
		if ( ! self::portable_storage() ) {
			$result = $wpdb->query( $wpdb->prepare( "DELETE minter FROM {$wpdb->options} AS minter INNER JOIN {$wpdb->options} AS active_key ON active_key.option_name = %s WHERE minter.option_name = %s AND BINARY minter.option_value = %s AND BINARY active_key.option_value = %s", self::OPTION, self::MINTER, maybe_serialize( $minter ), $raw ) );
			if ( ! self::rejected( $result ) ) {
				return;
			}
		}
		self::clear_key_cache();
		if ( get_option( self::OPTION, false ) === $raw && self::minter_matches( $minter ) ) {
			delete_option( self::MINTER );
		}
	}

	private static function clear_key_cache() {
		foreach ( array( self::OPTION, self::MINTER, self::PENDING_MINTER, 'alloptions', 'notoptions' ) as $option ) {
			wp_cache_delete( $option, 'options' );
		}
	}

	/**
	 * The authorize flow issues a fresh connection; the next Worker contact
	 * re-provisions. The requirement flag stays: between reset and that first
	 * contact the approved routes refuse rather than fall back to bare auth.
	 */
	public static function reset( $minter_uuid = '' ) {
		delete_option( self::PENDING_MINTER );
		if ( self::provisioned() ) {
			update_option( self::REQUIRED, 1, false );
		}
		if ( '' !== (string) $minter_uuid ) {
			update_option( self::MINTER, (string) $minter_uuid, false );
			self::delete_key_for_minter( (string) $minter_uuid );
			self::clear_key_cache();
		} else {
			delete_option( self::OPTION );
			delete_option( self::MINTER );
		}
	}

	private static function delete_key_for_minter( $minter_uuid ) {
		global $wpdb;
		if ( ! self::portable_storage() ) {
			$result = $wpdb->query( $wpdb->prepare( "DELETE active_key FROM {$wpdb->options} AS active_key INNER JOIN {$wpdb->options} AS minter ON minter.option_name = %s WHERE active_key.option_name = %s AND BINARY minter.option_value = %s", self::MINTER, self::OPTION, $minter_uuid ) );
			if ( ! self::rejected( $result ) ) {
				return;
			}
		}
		self::clear_key_cache();
		if ( (string) get_option( self::MINTER, '' ) === $minter_uuid ) {
			delete_option( self::OPTION );
		}
	}

	public static function stage_minter( $uuid ) {
		update_option( self::PENDING_MINTER, array( 'uuid' => (string) $uuid, 'user_id' => get_current_user_id(), 'expires' => time() + DAY_IN_SECONDS ), false );
	}

	/** A cookie-authorized replacement can recover a lost key without disabling the old key while pending. */
	public static function activate_staged_key( $request ) {
		$pending = get_option( self::PENDING_MINTER, array() );
		$uuid = (string) $request->get_param( 'uuid' );
		$key = (string) $request->get_param( 'key' );
		if ( ! current_user_can( 'manage_options' ) || ! is_array( $pending ) || empty( $pending['uuid'] ) || $uuid !== $pending['uuid'] || (int) $pending['user_id'] !== get_current_user_id() || (int) $pending['expires'] < time() || ( self::$auth_uuid !== $uuid && ! self::request_carries( $uuid ) ) ) {
			return new WP_Error( 'wpvibe_op_proof_activation_denied', __( 'This key activation requires the application password from the latest browser-approved connection.', 'vibe-ai' ), array( 'status' => 403 ) );
		}
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $key ) ) {
			return new WP_Error( 'wpvibe_op_proof_bad_key', __( 'The proof key must be 64 hex characters.', 'vibe-ai' ), array( 'status' => 400 ) );
		}
		global $wpdb;
		$previous_key = self::key();
		if ( '' === $previous_key ) {
			return new WP_Error( 'wpvibe_op_proof_activation_denied', __( 'No active key exists. Provision the key using the newly approved application password.', 'vibe-ai' ), array( 'status' => 409 ) );
		}
		$changed = self::portable_storage() ? false : $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} AS active_key INNER JOIN {$wpdb->options} AS staged ON staged.option_name = %s SET active_key.option_value = %s WHERE active_key.option_name = %s AND BINARY active_key.option_value = %s AND BINARY staged.option_value = %s", self::PENDING_MINTER, $key, self::OPTION, $previous_key, maybe_serialize( $pending ) ) );
		if ( self::portable_storage() || self::rejected( $changed ) ) {
			self::clear_key_cache();
			$changed = get_option( self::OPTION, false ) === $previous_key && maybe_serialize( get_option( self::PENDING_MINTER, array() ) ) === maybe_serialize( $pending ) && update_option( self::OPTION, $key, false ) ? 1 : 0;
		}
		if ( 1 !== $changed ) {
			return new WP_Error( 'wpvibe_op_proof_activation_conflict', __( 'The connection changed during key activation. Approve a fresh connection.', 'vibe-ai' ), array( 'status' => 409 ) );
		}
		self::clear_key_cache();
		update_option( self::REQUIRED, 1, false );
		$result = self::portable_storage() ? false : $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND BINARY option_value = %s", self::PENDING_MINTER, maybe_serialize( $pending ) ) );
		if ( self::portable_storage() || self::rejected( $result ) ) {
			self::clear_key_cache();
			if ( maybe_serialize( get_option( self::PENDING_MINTER, array() ) ) === maybe_serialize( $pending ) ) {
				delete_option( self::PENDING_MINTER );
			}
		}
		self::clear_key_cache();
		return true;
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
