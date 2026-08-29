<?php
/**
 * A real error on wp-admin/authorize-application.php when Approve cannot reach REST.
 *
 * WordPress's auth-app.js POSTs /wp/v2/users/me/application-passwords and, when the
 * answer is not JSON (a firewall HTML page, a 404 page, status 0 from an extension),
 * appends an EMPTY red notice. The user sees nothing; support gets a screenshot of a
 * blank bar. This enqueues a small script on WPVibe's own authorize requests that
 * (1) replaces that empty notice with what actually came back and the two fixes, from
 * the failure path itself (the core `wp_application_passwords_approve_app_request_error`
 * hook), and (2) runs a warn-only pre-flight GET of the same route before Approve.
 *
 * Advisory only: it never disables or intercepts the form. Gated to our app_id so
 * other applications' authorize pages are untouched. Kill switch: the
 * WPVIBE_DISABLE_AUTHORIZE_NOTICE constant or the wpvibe_authorize_notice filter.
 *
 * @package WPVibe
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPVibe_Authorize_Notice {

	/** The app_id connect_site puts on every authorize link. */
	const APP_ID = '01965f3a-7b2d-7000-8000-000000000001';

	/** Where the Approve-failure beacon may go (the Worker validates the state it carries). */
	const BEACON_HOSTS = array( 'mcp.wpvibe.ai' );

	const DOCS_URL = 'https://wpvibe.ai/docs/connect-a-wordpress-site/';

	public static function register() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Pure: load only on the authorize page, only for a WPVibe request, unless disabled.
	 *
	 * @param string $hook    admin_enqueue_scripts hook suffix.
	 * @param array  $request $_REQUEST snapshot.
	 * @return bool
	 */
	public static function should_load( $hook, $request ) {
		if ( 'authorize-application.php' !== $hook ) {
			return false;
		}
		$app_id = isset( $request['app_id'] ) && is_string( $request['app_id'] ) ? $request['app_id'] : '';
		if ( self::APP_ID !== $app_id ) {
			return false;
		}
		if ( defined( 'WPVIBE_DISABLE_AUTHORIZE_NOTICE' ) && WPVIBE_DISABLE_AUTHORIZE_NOTICE ) {
			return false;
		}
		return (bool) apply_filters( 'wpvibe_authorize_notice', true );
	}

	/**
	 * Pure: what the script gets. Strings and an allowlist only; no server paths, no plugin list.
	 *
	 * @return array
	 */
	public static function settings() {
		$hosts = self::normalize_hosts( apply_filters( 'wpvibe_authorize_beacon_hosts', self::BEACON_HOSTS ) );
		return array(
			'docsUrl'      => self::DOCS_URL,
			'supportEmail' => 'support@wpvibe.ai',
			'beaconHosts'  => $hosts,
			'i18n'         => array(
				'title'     => __( 'Approve could not reach this site\'s REST API', 'vibe-ai' ),
				/* translators: %s: what the request returned (e.g. "an HTML page (status 403)"). */
				'came_back' => __( 'The request WordPress makes to create the application password came back as %s. WordPress needs a JSON answer from /wp/v2/users/me/application-passwords to finish.', 'vibe-ai' ),
				/* translators: %s: vendor or plugin name detected in the response. */
				'marker'    => __( 'The response looks like it came from %s.', 'vibe-ai' ),
				'step1'     => __( 'Open this link in a private (incognito) window with browser extensions off, log in as an administrator, and click Approve once.', 'vibe-ai' ),
				'step2'     => __( 'If it fails the same way, allow the REST API for logged-in administrators in your security plugin, or ask your host to allow /wp-json/wp/v2/users/ for admins.', 'vibe-ai' ),
				'docs'      => __( 'Connection guide', 'vibe-ai' ),
				/* translators: %s: support email address. */
				'support'   => __( 'Still stuck? Email %s with the text of this notice.', 'vibe-ai' ),
				'preflight' => __( 'Heads up before you click Approve:', 'vibe-ai' ),
				'html'      => __( 'an HTML page', 'vibe-ai' ),
				'empty'     => __( 'an empty response', 'vibe-ai' ),
				'network'   => __( 'no response at all (blocked in the browser or by the network)', 'vibe-ai' ),
				/* translators: %d: HTTP status code. */
				'status'    => __( '(status %d)', 'vibe-ai' ),
			),
		);
	}

	/**
	 * Pure: hostnames only (lowercase, no scheme/path), empties dropped.
	 *
	 * @param mixed $hosts Filter output.
	 * @return string[]
	 */
	public static function normalize_hosts( $hosts ) {
		$out = array();
		foreach ( (array) $hosts as $h ) {
			$h = strtolower( trim( (string) $h ) );
			if ( '' !== $h && preg_match( '/^(?=.*[a-z])[a-z0-9.-]+$/', $h ) ) { // a hostname, not a number, scheme or path
				$out[] = $h;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * admin_enqueue_scripts.
	 *
	 * @param string $hook Hook suffix.
	 */
	public static function enqueue( $hook ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only gate on which app is asking; core validates the form.
		if ( ! self::should_load( $hook, $_REQUEST ) ) {
			return;
		}
		wp_enqueue_script(
			'wpvibe-authorize-preflight',
			WPVIBE_PLUGIN_URL . 'assets/js/authorize-preflight.js',
			array( 'jquery', 'wp-hooks', 'wp-api-request', 'auth-app' ),
			WPVIBE_VERSION,
			true
		);
		wp_localize_script( 'wpvibe-authorize-preflight', 'wpvibeAuthorize', self::settings() );
	}
}
