<?php
/**
 * Structured error-contract fields for WP_Error responses.
 *
 * The plugin reports facts; the MCP Worker interprets them. Every WP_Error
 * the plugin returns carries these fields in its data array so the Worker
 * never has to guess (or probe) what happened:
 *
 * - cause:         why the operation failed (enum below)
 * - retry_ok:      whether retrying the SAME call unchanged can ever succeed
 * - user_is_admin: whether the authenticated user has manage_options
 *
 * Cause enum:
 * - capability_role         user genuinely lacks a role capability
 * - capability_cpt_mapping  a custom post type's capability mapping denied the
 *                           check (fails even Administrators)
 * - meta_protected          protected meta key (underscore/auth callback)
 * - not_supported           outside the supported surface by design (allowlist)
 * - security_gate           blocked by an anti-injection/sandbox rule
 * - approval_flow           needs human approval/confirmation, then retryable
 * - invalid_input           malformed or missing caller input
 * - not_found               the referenced resource does not exist
 * - wp_core                 WordPress core refused the operation
 * - filesystem              file read/write/copy failed
 * - host_environment        hosting configuration blocks it (DISALLOW_FILE_*,
 *                           disabled functions, chroot, permissions)
 *
 * @package WPVibe
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPVibe_Error_Contract {

	/**
	 * Build a WP_Error data array carrying the structured contract fields.
	 *
	 * @param string $cause    One of the cause enum values above.
	 * @param bool   $retry_ok Whether retrying the same call unchanged can succeed.
	 * @param array  $extra    Existing data fields (status, capability, ...). Kept as-is.
	 * @return array
	 */
	public static function data( $cause, $retry_ok, array $extra = array() ) {
		return array_merge(
			$extra,
			array(
				'cause'         => $cause,
				'retry_ok'      => (bool) $retry_ok,
				'user_is_admin' => is_user_logged_in() && current_user_can( 'manage_options' ),
			),
			self::auth_diagnostics()
		);
	}

	/**
	 * Why authentication produced no user, as facts rather than inference.
	 *
	 * WordPress emits the same 401 codes whether the server stripped the
	 * Authorization header, Application Passwords are unavailable (they need
	 * SSL, so a proxy that does not set HTTP_X_FORWARDED_PROTO silently
	 * disables them), or a security plugin short-circuited the auth filters.
	 * The remedies are different and mutually useless, and none of it is
	 * distinguishable from outside the site, so report it instead.
	 *
	 * Attached only when nobody authenticated; on an ordinary 403 the caller is
	 * logged in and these would be noise.
	 *
	 * @return array
	 */
	public static function auth_diagnostics() {
		if ( is_user_logged_in() ) {
			return array();
		}
		return array(
			// Constants are set in vibe-ai.php before anything can rewrite
			// $_SERVER, so these describe what the server delivered.
			'auth_header_seen'        => defined( 'WPVIBE_AUTH_HEADER_SEEN' ) ? (bool) WPVIBE_AUTH_HEADER_SEEN : null,
			'auth_fallback_seen'      => defined( 'WPVIBE_AUTH_FALLBACK_SEEN' ) ? (bool) WPVIBE_AUTH_FALLBACK_SEEN : null,
			'auth_fallback_applied'   => defined( 'WPVIBE_AUTH_FALLBACK_APPLIED' ) ? (bool) WPVIBE_AUTH_FALLBACK_APPLIED : null,
			'app_passwords_available' => function_exists( 'wp_is_application_passwords_available' ) ? (bool) wp_is_application_passwords_available() : null,
			'is_ssl'                  => is_ssl(),
		);
	}
}
