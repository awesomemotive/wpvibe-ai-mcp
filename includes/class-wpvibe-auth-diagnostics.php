<?php
/**
 * Says WHY WordPress refused an Application Password, as facts the Worker can act on.
 *
 * Core answers `incorrect_password` for two very different states: the stored hash
 * does not match, and the user has NO application passwords at all (the site never
 * kept the one it minted seconds ago: a staging copy sharing the live install's
 * user tables, a persistent object cache, a db/meta layer). The remedies are
 * opposite (reconnect vs. fix the install), and nothing outside the site can tell
 * them apart. Real case: two days and a hosting ticket spent on a stripped-header
 * theory for a site whose Application Passwords list was simply always empty.
 *
 * Core's app-password error carries no plugin contract, so this class enriches it:
 * the failed-auth action records the verdict for the login that presented the
 * credential, and a late rest_authentication_errors filter merges the contract
 * (cause, auth diagnostics, auth_reject_detail, install facts) into the WP_Error
 * that becomes the 401 body. Only the presenting login is ever inspected, and only
 * a count-derived verdict is reported, so this enumerates nothing.
 *
 * @package WPVibe
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPVibe_Auth_Diagnostics {

	/** Core app-password hard-fail codes (wp_authenticate_application_password). */
	const REJECT_CODES = array( 'incorrect_password', 'invalid_username', 'invalid_email' );

	/** Drop-ins that intercept caching or the database layer. */
	const DROPINS = array( 'object-cache.php', 'db.php', 'advanced-cache.php' );

	/** Verdict recorded by the failed-auth action for this request, or null. */
	private static $detail = null;

	public static function register() {
		add_action( 'application_password_failed_authentication', array( __CLASS__, 'on_failed_authentication' ) );
		// Core returns the stored app-password error from rest_authentication_errors at
		// priority 90 (rest_application_password_check_errors); merge after it.
		add_filter( 'rest_authentication_errors', array( __CLASS__, 'enrich_rest_error' ), PHP_INT_MAX );
	}

	/**
	 * Record why core refused the credential the request presented.
	 *
	 * @param WP_Error $error Core's rejection.
	 */
	public static function on_failed_authentication( $error ) {
		if ( ! is_wp_error( $error ) || ! in_array( $error->get_error_code(), self::REJECT_CODES, true ) ) {
			return;
		}
		self::$detail = self::reject_detail( self::presented_login() );
	}

	/**
	 * The login core evaluated: PHP_AUTH_USER, which the auth fallback may have restored.
	 *
	 * @param array|null $server $_SERVER (test seam).
	 * @return string
	 */
	public static function presented_login( $server = null ) {
		$server = null === $server ? $_SERVER : $server;
		$login  = $server['PHP_AUTH_USER'] ?? '';
		return is_string( $login ) ? $login : '';
	}

	/**
	 * 'no_app_passwords_for_user' when the login exists and holds no application
	 * passwords, 'hash_mismatch' when it holds some, null for an unknown login.
	 *
	 * Mirrors core's lookup order (login first, then email) so the verdict is about
	 * the same user core compared against.
	 *
	 * @param string $login Presented login.
	 * @return string|null
	 */
	public static function reject_detail( $login ) {
		if ( '' === $login || ! function_exists( 'get_user_by' ) || ! class_exists( 'WP_Application_Passwords' ) ) {
			return null;
		}
		// Core resolves login first, then email (wp_authenticate_application_password). When the
		// string is an email that also matches another account's login, the two disagree on which
		// user was compared, so report nothing rather than describe the wrong one.
		$user = get_user_by( 'login', $login );
		if ( is_email( $login ) ) {
			$by_email = get_user_by( 'email', $login );
			if ( $user && $by_email && (int) $user->ID !== (int) $by_email->ID ) {
				return null;
			}
			if ( ! $user ) {
				$user = $by_email;
			}
		}
		if ( ! $user || empty( $user->ID ) ) {
			return null;
		}
		$passwords = WP_Application_Passwords::get_user_application_passwords( (int) $user->ID );
		return empty( $passwords ) ? 'no_app_passwords_for_user' : 'hash_mismatch';
	}

	/**
	 * Install facts that explain a lost application password. Booleans and file
	 * names only; nothing about users or content.
	 *
	 * @param string|null $content_dir wp-content path (test seam; default WP_CONTENT_DIR).
	 * @return array
	 */
	public static function install_facts( $content_dir = null ) {
		$dir     = null !== $content_dir ? $content_dir : ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '' );
		$dropins = array();
		if ( '' !== $dir ) {
			foreach ( self::DROPINS as $file ) {
				if ( file_exists( rtrim( $dir, '/' ) . '/' . $file ) ) {
					$dropins[] = $file;
				}
			}
		}
		return array(
			'ext_object_cache'   => function_exists( 'wp_using_ext_object_cache' ) ? (bool) wp_using_ext_object_cache() : null,
			'custom_user_tables' => defined( 'CUSTOM_USER_TABLE' ) || defined( 'CUSTOM_USER_META_TABLE' ),
			'dropins'            => $dropins,
		);
	}

	/**
	 * rest_authentication_errors: merge the contract into core's rejection.
	 *
	 * @param WP_Error|true|null $result Current verdict.
	 * @return WP_Error|true|null
	 */
	public static function enrich_rest_error( $result ) {
		if ( ! is_wp_error( $result ) || ! in_array( $result->get_error_code(), self::REJECT_CODES, true ) ) {
			return $result;
		}
		// Only WPVibe's own requests get the contract; a scanner probing wp/v2 with a
		// stolen username sees core's plain 401. The Worker sends X-WPVibe on every call.
		if ( ! self::is_wpvibe_request() ) {
			return $result;
		}
		$detail       = self::$detail;
		self::$detail = null; // never let a verdict outlive its request in persistent runtimes
		return self::enrich( $result, $detail );
	}

	/**
	 * Whether the current request came from the WPVibe Worker.
	 *
	 * @param array|null $server $_SERVER (test seam).
	 * @return bool
	 */
	public static function is_wpvibe_request( $server = null ) {
		$server = null === $server ? $_SERVER : $server;
		if ( ! empty( $server['HTTP_X_WPVIBE'] ) ) {
			return true;
		}
		return defined( 'WPVIBE_AUTH_FALLBACK_SEEN' ) && WPVIBE_AUTH_FALLBACK_SEEN;
	}

	/**
	 * Attach the credential_rejected contract to a core rejection. Same WP_Error back.
	 *
	 * @param WP_Error    $error  Core's rejection (code kept as-is: the Worker keys on it).
	 * @param string|null $detail reject_detail() verdict.
	 * @return WP_Error
	 */
	public static function enrich( $error, $detail ) {
		$data = $error->get_error_data();
		if ( ! is_array( $data ) ) {
			$data = array( 'status' => 401 );
		}
		if ( ! isset( $data['status'] ) ) {
			$data['status'] = 401;
		}
		$data = array_merge(
			$data,
			array(
				'cause'         => 'credential_rejected',
				'retry_ok'      => false,
				'user_is_admin' => false,
			),
			WPVibe_Error_Contract::auth_diagnostics(),
			self::install_facts()
		);
		if ( null !== $detail ) {
			$data['auth_reject_detail'] = $detail;
		}
		$error->add_data( $data, $error->get_error_code() );
		return $error;
	}

	/** Test seam: forget the recorded verdict. */
	public static function reset() {
		self::$detail = null;
	}
}
