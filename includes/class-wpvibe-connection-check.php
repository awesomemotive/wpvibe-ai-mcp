<?php

defined( 'ABSPATH' ) || exit;

class WPVibe_Connection_Check {
	const CHALLENGE = 'wpvibe_connection_check_challenge';
	const COOLDOWN = 'wpvibe_connection_check_cooldown';
	const NONCE = 'wpvibe_connection_check';
	const ENDPOINT = 'https://mcp.wpvibe.ai/connection/preflight';
	const SNAPSHOT = 'wpvibe_connection_check_snapshot';

	public static function last_result() {
		$snapshot = get_option( self::SNAPSHOT, array() );
		return is_array( $snapshot ) && ( $snapshot['site'] ?? '' ) === site_url() && ( $snapshot['user'] ?? 0 ) === get_current_user_id() && in_array( $snapshot['state'] ?? '', array( 'done', 'attention' ), true ) && ! empty( $snapshot['checkedAt'] ) && isset( $snapshot['reports'] ) && is_array( $snapshot['reports'] ) ? $snapshot : null;
	}

	public static function register() {
		add_action( 'wp_ajax_wpvibe_connection_prepare', array( __CLASS__, 'ajax_prepare' ) );
		add_action( 'wp_ajax_wpvibe_connection_remote', array( __CLASS__, 'ajax_remote' ) );
		add_action( 'wp_ajax_wpvibe_connection_state', array( __CLASS__, 'ajax_state' ) );
		add_action( 'wp_ajax_wpvibe_connection_self', array( __CLASS__, 'ajax_self' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route( 'wpvibe/v1', '/connection-check-challenge', array(
			// Public by design: WPVibe's servers read it without credentials. It answers only the random challenge of a check an
			// Administrator started in the last two minutes, and 404 otherwise.
			'methods' => 'GET', 'callback' => array( __CLASS__, 'challenge_response' ), 'permission_callback' => '__return_true',
		) );
	}

	public static function challenge_response() {
		// LiteSpeed Cache ignores Cache-Control on REST responses and would serve an older run's challenge.
		do_action( 'litespeed_control_set_nocache', 'wpvibe connection check' );
		$active = get_transient( self::CHALLENGE );
		if ( ! is_array( $active ) || empty( $active['expires'] ) || $active['expires'] <= time() ) {
			return new WP_Error( 'rest_no_route', 'No connection check is running.', array( 'status' => 404 ) );
		}
		$response = new WP_REST_Response( array( 'challenge' => $active['challenge'], 'plugin' => 'wpvibe' ) );
		$response->header( 'Cache-Control', 'no-store, private, max-age=0' );
		return $response;
	}

	public static function authorized( $nonce ) {
		return current_user_can( 'manage_options' ) && is_string( $nonce ) && wp_verify_nonce( $nonce, self::NONCE );
	}

	private static function send( $result ) {
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			$payload = array( 'message' => $result->get_error_message() );
			if ( isset( $data['retry_after'] ) ) { $payload['retry_after'] = (int) $data['retry_after']; }
			wp_send_json_error( $payload, isset( $data['status'] ) ? $data['status'] : 400 );
		}
		wp_send_json_success( $result );
	}

	public static function state( $nonce ) {
		if ( ! self::authorized( $nonce ) ) {
			return new WP_Error( 'forbidden', __( 'Not allowed.', 'vibe-ai' ), array( 'status' => 403 ) );
		}
		$record  = WPVibe_Connection_Status::current_observation();
		$ai_read = WPVibe_Connection_Status::current_ai_read();
		return array(
			'auth_state'       => $record ? $record['state'] : 'not_checked',
			'auth_observed_at' => $record ? (int) $record['observed_at'] : 0,
			'ai_observed_at'   => $ai_read ? (int) $ai_read['observed_at'] : 0,
			'ai_client'        => $ai_read && ! empty( $ai_read['client'] ) ? $ai_read['client'] : '',
		);
	}

	// Loopback probes: the site calls itself, so they stay out of the network-free local report and run as their own phase.
	public static function self_report( $nonce, $challenge ) {
		if ( ! self::authorized( $nonce ) ) {
			return new WP_Error( 'forbidden', __( 'Not allowed.', 'vibe-ai' ), array( 'status' => 403 ) );
		}
		$pending = get_transient( self::CHALLENGE );
		if ( ! is_array( $pending ) || ! is_string( $challenge ) || ! hash_equals( $pending['challenge'], $challenge ) || (int) $pending['user'] !== get_current_user_id() ) {
			return new WP_Error( 'no_check', __( 'Run Check site connectivity again.', 'vibe-ai' ), array( 'status' => 400 ) );
		}
		$report = array( 'site' => site_url(), 'checkedAt' => gmdate( 'c' ), 'checks' => array(
			array_merge( array( 'stage' => 'REST API from this site' ), self::rest_gate_probe() ),
			array_merge( array( 'stage' => 'Authorization header' ), self::auth_header_loopback() ),
		), 'evidence' => array() );
		// The loopback above just told WordPress's REST side apart from this wp-admin request (#1602): say so when they differ.
		$local = array_column( isset( $pending['report']['checks'] ) ? $pending['report']['checks'] : array(), 'status', 'stage' );
		// The loopback wrote the note in another process: a persistent object cache still holds this request's older copy.
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( WPVibe_App_Password_Policy::REST_OFF, 'options' );
		if ( 'passed' === ( $local['Application Password availability'] ?? '' ) && WPVibe_App_Password_Policy::rest_reported_off() ) {
			$report['checks'][] = array_merge( array( 'stage' => 'Application Passwords for REST requests', 'status' => 'failed' ), WPVibe_App_Password_Policy::rest_off_text() );
		}
		$pending['self'] = $report;
		set_transient( self::CHALLENGE, $pending, max( 1, (int) $pending['expires'] - time() ) );
		return $report;
	}

	public static function ajax_self() {
		self::send( self::self_report( self::post_field( 'nonce' ), self::post_field( 'challenge' ) ) );
	}

	public static function ajax_state() {
		self::send( self::state( self::post_field( 'nonce' ) ) );
	}

	public static function ajax_prepare() {
		self::send( self::prepare( self::post_field( 'nonce' ) ) );
	}

	public static function ajax_remote() {
		self::send( self::remote( self::post_field( 'nonce' ), self::post_field( 'challenge' ) ) );
	}

	// The nonce and the challenge are hex strings; sanitizing leaves them unchanged and turns anything else into ''.
	private static function post_field( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- reads the nonce itself; every caller passes it to authorized(), which verifies it before anything runs.
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
	}

	public static function prepare( $nonce ) {
		if ( ! self::authorized( $nonce ) ) {
			return new WP_Error( 'forbidden', __( 'Refresh this page and sign in as a WordPress administrator to run the check.', 'vibe-ai' ), array( 'status' => 403 ) );
		}
		$remaining = (int) get_transient( self::COOLDOWN ) - time();
		if ( $remaining > 0 ) {
			return new WP_Error( 'check_limited', __( 'Another administrator ran this check less than a minute ago. Their results are on their screen; you can run yours when the countdown ends.', 'vibe-ai' ), array( 'status' => 429, 'retry_after' => min( 60, $remaining ) ) );
		}
		$challenge = bin2hex( random_bytes( 32 ) );
		set_transient( self::COOLDOWN, time() + 60, 60 );
		$report = self::local_report();
		set_transient( self::CHALLENGE, array( 'challenge' => $challenge, 'expires' => time() + 120, 'user' => get_current_user_id(), 'used' => false, 'report' => $report ), 120 );
		return array( 'challenge' => $challenge, 'site_url' => site_url(), 'report' => $report );
	}

	const LOOPBACK = 'wpvibe_connection_loopback';

	// The REST index probe's one-time token: its request may note that WordPress turns Application Passwords off for REST (#1681).
	const PROBE = 'wpvibe_connection_probe';

	public static function loopback_token_valid( $token ) {
		$expected = get_transient( self::LOOPBACK );
		return is_string( $expected ) && '' !== $expected && hash_equals( $expected, $token );
	}

	public static function probe_token_valid( $token ) {
		$expected = get_transient( self::PROBE );
		return is_string( $expected ) && '' !== $expected && hash_equals( $expected, (string) $token );
	}

	// A host that strips Authorization breaks every app-password request; the fallback header is what WPVibe uses then.
	// The token travels under its own scheme: a Basic credential for a made-up user turns into a 401 on sites whose security plugin resolves the user early.
	public static function auth_header_loopback() {
		$token = bin2hex( random_bytes( 16 ) );
		set_transient( self::LOOPBACK, $token, 30 );
		$response = wp_remote_get( add_query_arg( 'wpvibe_loopback', '1', rest_url( 'wpvibe/v1/ping' ) ), array(
			'timeout'   => 8,
			'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			'headers'   => array(
				'Authorization'          => 'WPVibe-Loopback ' . $token,
				'X-WPVibe-Authorization' => 'WPVibe-Loopback ' . $token,
				'X-WPVibe-Loopback'      => $token,
			),
		) );
		delete_transient( self::LOOPBACK );
		if ( is_wp_error( $response ) ) {
			return array( 'status' => 'unknown', 'summary' => __( 'This site could not make a request to itself, so the Authorization header could not be tested here.', 'vibe-ai' ), 'next' => '' );
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$seen = is_array( $body ) && isset( $body['auth_headers_seen'] ) && is_array( $body['auth_headers_seen'] ) ? $body['auth_headers_seen'] : null;
		if ( ! $seen ) {
			/* translators: %d: HTTP status code. */
			return array( 'status' => 'unknown', 'summary' => sprintf( __( 'The loopback request returned HTTP %d without the plugin echo, so the Authorization header could not be tested here.', 'vibe-ai' ), (int) wp_remote_retrieve_response_code( $response ) ), 'next' => '' );
		}
		if ( ! empty( $seen['authorization'] ) ) {
			return array( 'status' => 'passed', 'summary' => __( 'The Authorization header arrives at WordPress on this host. This shows only that the header gets through, not that WordPress accepts the Application Password in it.', 'vibe-ai' ), 'next' => '' );
		}
		if ( ! empty( $seen['fallback'] ) ) {
			return array( 'status' => 'limited', 'summary' => __( 'This host strips the standard Authorization header before it reaches WordPress. WPVibe sends its own fallback header, which does arrive, so the connection can still work.', 'vibe-ai' ), 'next' => __( 'No action needed. If a connection fails later, ask your host to pass the Authorization header through to PHP.', 'vibe-ai' ) );
		}
		return array( 'status' => 'failed', 'summary' => __( 'This host strips both the Authorization header and the WPVibe fallback header before they reach WordPress.', 'vibe-ai' ), 'next' => __( 'Ask your host to pass the Authorization header through to PHP (on Apache, a SetEnvIf or RewriteRule for HTTP_AUTHORIZATION). WPVibe cannot connect until one of these headers arrives.', 'vibe-ai' ) );
	}

	// Staging gates and REST-disabling plugins are the two most common site-side blockers in support; both are visible from inside the site.
	public static function rest_gate_probe() {
		// X-WPVibe: the index request is a WPVibe REST request, so it notes whether WordPress turns Application Passwords off for those (#1602).
		// It carries no credentials, so a one-time token shows the note it writes is this check's own (#1681).
		$token = bin2hex( random_bytes( 16 ) );
		set_transient( self::PROBE, $token, 30 );
		$response = wp_remote_get( rest_url(), array( 'timeout' => 8, 'sslverify' => apply_filters( 'https_local_ssl_verify', false ), 'headers' => array( 'X-WPVibe-Loopback-Probe' => $token, 'X-WPVibe' => '1' ) ) );
		delete_transient( self::PROBE );
		if ( is_wp_error( $response ) ) {
			return array( 'status' => 'unknown', 'summary' => __( 'This site could not make a request to its own REST API, so a password gate could not be tested here.', 'vibe-ai' ), 'next' => '' );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$www  = wp_remote_retrieve_header( $response, 'www-authenticate' );
		$www  = is_array( $www ) ? implode( ', ', $www ) : (string) $www;
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 401 === $code && false !== stripos( $www, 'basic' ) ) {
			return array( 'status' => 'failed', 'summary' => __( 'A password gate in front of WordPress (staging protection or HTTP Basic Auth) blocks the REST API before WordPress runs.', 'vibe-ai' ), 'next' => __( 'Ask your host to let the WPVibe relay IP address through the password gate (it is listed at https://wpvibe.ai/docs/firewalls-and-wpvibe-ip/), or turn the gate off while WPVibe is connected. WPVibe cannot send the gate password itself.', 'vibe-ai' ) );
		}
		if ( is_array( $body ) && isset( $body['code'] ) && in_array( $body['code'], array( 'rest_disabled', 'rest_forbidden', 'rest_cannot_access', 'rest_login_required', 'rest_not_logged_in' ), true ) ) {
			$blocker = self::rest_blocker();
			/* translators: %s: name of the plugin that turns the REST API off. */
			return array( 'status' => 'limited', 'summary' => $blocker ? sprintf( __( 'The %s plugin turns off the WordPress REST API for logged-out requests. WPVibe signs in with an Application Password, so its requests are logged in and usually pass.', 'vibe-ai' ), $blocker['name'] ) : __( 'A plugin or setting turns off the WordPress REST API for logged-out requests. WPVibe signs in with an Application Password, so its requests are logged in and usually pass.', 'vibe-ai' ), 'next' => __( 'No action needed yet. If Step 3 fails with a REST API or 401/403 error, allow the wp/v2 and wpvibe/v1 routes for logged-in users in that plugin, then run the check again.', 'vibe-ai' ) );
		}
		if ( 200 === $code && is_array( $body ) && isset( $body['namespaces'] ) ) {
			$missing = array_diff( array( 'wp/v2', 'wpvibe/v1' ), (array) $body['namespaces'] );
			if ( $missing ) {
				/* translators: %s: comma-separated list of REST namespaces. */
				return array( 'status' => 'failed', 'summary' => sprintf( __( 'The REST API answers, but these routes are missing: %s.', 'vibe-ai' ), implode( ', ', $missing ) ), 'next' => __( 'Another plugin is removing REST routes. Find the plugin that hides REST routes or namespaces and allow wp/v2 and wpvibe/v1.', 'vibe-ai' ) );
			}
			return array( 'status' => 'passed', 'summary' => __( 'The REST API answers from inside this site with the routes WPVibe needs.', 'vibe-ai' ), 'next' => '' );
		}
		$raw = (string) wp_remote_retrieve_body( $response );
		if ( null === $body && '' !== trim( $raw ) ) {
			$page = self::maintenance_page_owner( $raw );
			/* translators: 1: HTTP status code, 2: name of the plugin in coming soon or maintenance mode. */
			return array( 'status' => 'failed', 'summary' => $page ? sprintf( __( 'The REST API returned a web page instead of JSON (HTTP %1$d). %2$s is in coming soon or maintenance mode and is answering instead of the WordPress API.', 'vibe-ai' ), $code, $page )
				/* translators: %d: HTTP status code. */
				: sprintf( __( 'The REST API returned a web page instead of JSON (HTTP %d). A coming soon page, maintenance mode, or firewall page is answering instead of the WordPress API.', 'vibe-ai' ), $code ), 'next' => $page ? __( 'Exclude the REST API from that mode, or turn the mode off while connecting. Then run the check again.', 'vibe-ai' ) : __( 'Turn off maintenance or coming soon mode for REST requests, or ask your host which page is answering /wp-json/. Then run the check again.', 'vibe-ai' ) );
		}
		/* translators: %d: HTTP status code. */
		return array( 'status' => 'unknown', 'summary' => sprintf( __( 'The REST API returned HTTP %d to a request from this site. The remote check below tells you whether WPVibe can reach it.', 'vibe-ai' ), $code ), 'next' => '' );
	}

	// A mode switched on is not proof its page answered: name SeedProd only when the response carries its
	// page's markup, else the generic wording (#715). A 503 with Retry-After is no proof either: WordPress's
	// own maintenance mode and many hosts send one too.
	private static function maintenance_page_owner( $raw ) {
		$seedprod = get_option( 'seedprod_settings' );
		if ( is_string( $seedprod ) ) {
			$seedprod = json_decode( $seedprod, true );
		}
		if ( ! is_array( $seedprod ) || ( empty( $seedprod['enable_coming_soon_mode'] ) && empty( $seedprod['enable_maintenance_mode'] ) ) ) {
			return null;
		}
		return false !== stripos( (string) $raw, 'seedprod' ) ? 'SeedProd' : null;
	}

	// Many well-behaved plugins hook these filters, so only name a plugin that exists to turn REST off.
	private static function rest_blocker() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$known = array(
			'disable-json-api/disable-json-api.php'      => 'Disable REST API',
			'disable-wp-rest-api/disable-wp-rest-api.php' => 'Disable WP REST API',
			'wp-rest-api-controller/wp-rest-api-controller.php' => 'WP REST API Controller',
		);
		foreach ( $known as $basename => $name ) {
			if ( is_plugin_active( $basename ) ) {
				return array( 'name' => $name, 'basename' => $basename );
			}
		}
		if ( class_exists( 'ITSEC_Modules' ) && method_exists( 'ITSEC_Modules', 'is_active' ) && ITSEC_Modules::is_active( 'wordpress-tweaks' ) && method_exists( 'ITSEC_Modules', 'get_setting' ) && 'restricted-access' === ITSEC_Modules::get_setting( 'wordpress-tweaks', 'rest_api' ) ) {
			return array( 'name' => 'Solid Security', 'basename' => 'better-wp-security/better-wp-security.php' );
		}
		return null;
	}

	// Local-only hosts are a support-visible dead end: the cloud can never reach them, so say so before the remote check tries.
	private static function address_row( $site ) {
		$host = strtolower( (string) wp_parse_url( $site, PHP_URL_HOST ) );
		$local = in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true )
			|| preg_match( '/\.(local|test|localhost|internal|lan|home)$/', $host )
			|| ( filter_var( $host, FILTER_VALIDATE_IP ) && ! filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) );
		if ( $local ) {
			/* translators: %s: the WordPress Address (site URL). */
			return array( 'stage' => 'WordPress address', 'status' => 'failed', 'summary' => sprintf( __( 'The WordPress address %s is a local or private address that the internet cannot reach.', 'vibe-ai' ), $site ), 'next' => __( 'WPVibe runs in the cloud and needs a public https:// address. For a local site, expose it with a tunnel (for example ngrok or Cloudflare Tunnel) and set the WordPress Address to the tunnel URL, or connect a staging copy instead.', 'vibe-ai' ) );
		}
		/* translators: 1: the WordPress Address (site URL), 2: the Site Address (home URL). */
		return array( 'stage' => 'WordPress address', 'status' => 'passed', 'summary' => sprintf( __( 'Checking the WordPress installation at %1$s. Public homepage: %2$s. Different addresses can be valid for subdirectory installations.', 'vibe-ai' ), $site, home_url() ) );
	}

	// Who owns the edge in front of this site: the Worker cannot tell a customer's Cloudflare from a host's, but the site can.
	public static function edge_owner() {
		$host = strtolower( (string) wp_parse_url( site_url(), PHP_URL_HOST ) );
		$hosts = array(
			'WP Engine' => array( '/\.(wpenginepowered|wpengine)\.com$/', 'WPE_APIKEY', 'wpengine-common' ),
			'Kinsta'    => array( '/\.kinsta\.cloud$/', 'KINSTAMU_VERSION', 'kinsta-mu-plugins' ),
			'Flywheel'  => array( '/\.flywheelsites\.com$/', 'FLYWHEEL_PLUGIN_DIR', 'flywheel' ),
			'Pantheon'  => array( '/\.pantheonsite\.io$/', 'PANTHEON_ENVIRONMENT', 'pantheon' ),
			'Cloudways' => array( '/\.cloudwaysapps\.com$/', 'CLOUDWAYS_ENV', 'cloudways' ),
		);
		foreach ( $hosts as $name => $sig ) {
			if ( preg_match( $sig[0], $host ) || defined( $sig[1] ) || is_dir( WPMU_PLUGIN_DIR . '/' . $sig[2] ) ) {
				return array( 'owner' => 'host', 'name' => $name );
			}
		}
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( is_plugin_active( 'cloudflare/cloudflare.php' ) || '' !== (string) get_option( 'cloudflare_api_key', '' ) ) {
			return array( 'owner' => 'customer', 'name' => 'Cloudflare' );
		}
		return array( 'owner' => 'unknown', 'name' => '' );
	}

	public static function local_report() {
		$site = site_url();
		$https = 'https' === wp_parse_url( $site, PHP_URL_SCHEME );
		$can_create = current_user_can( 'create_app_password', get_current_user_id() );
		$is_admin   = current_user_can( 'manage_options' ) && ( ! is_multisite() || is_super_admin() );
		$here      = WPVibe_App_Password_Policy::available_for_wpvibe( wp_get_current_user() );
		// On here, but off for WPVibe's REST requests (#1602): a block that applies to the REST API only.
		$rest_off  = $here && WPVibe_App_Password_Policy::rest_reported_off();
		$available = $here && ! $rest_off;
		$blocker   = $here ? null : WPVibe_App_Password_Policy::blocker();
		$checks = array(
			array( 'stage' => 'Plugin installed', 'status' => 'passed', 'summary' => __( 'WPVibe is active on this WordPress installation.', 'vibe-ai' ) ),
			array( 'stage' => 'WordPress HTTPS', 'status' => $https ? 'passed' : 'failed', 'summary' => $https ? __( 'The WordPress address uses HTTPS.', 'vibe-ai' ) : __( 'The WordPress address does not use HTTPS.', 'vibe-ai' ), 'next' => $https ? '' : __( 'Ask your host to enable a valid HTTPS certificate and confirm the WordPress Address in Settings > General before connecting.', 'vibe-ai' ) ),
			/* translators: %s: name of the plugin that turns Application Passwords off. */
			array( 'stage' => 'Application Password availability', 'status' => $available ? 'passed' : 'failed', 'summary' => $available ? __( 'Application Passwords are available for your current WordPress account. No credential has been tested.', 'vibe-ai' ) : ( $blocker ? sprintf( __( 'Application Passwords are turned off by the %s plugin.', 'vibe-ai' ), $blocker['name'] ) : __( 'WordPress reports that Application Passwords are unavailable for your current account.', 'vibe-ai' ) ),
				/* translators: %s: name of the plugin that turns Application Passwords off. */
				'next' => $available ? '' : ( $blocker ? sprintf( __( 'Turn that setting off in %s, or use Allow for WPVibe below to permit Application Passwords for WPVibe requests only. Then run the check again.', 'vibe-ai' ), $blocker['name'] ) : __( 'Check Users > Profile > Application Passwords. If that section is missing, check HTTPS and which security setting disables Application Passwords, or use Allow for WPVibe below. Then run the check again.', 'vibe-ai' ) ) ),
			array( 'stage' => 'Current WordPress account', 'status' => $can_create && $is_admin ? 'passed' : 'failed', 'summary' => $can_create && $is_admin ? __( 'This account is an administrator and can create an Application Password. The account authorized later may have different permissions.', 'vibe-ai' ) : ( ! $can_create ? __( 'This account is not allowed to create an Application Password.', 'vibe-ai' ) : __( 'This account can create an Application Password but is not an administrator here, so your AI would get limited access.', 'vibe-ai' ) ), 'next' => $can_create && $is_admin ? '' : ( ! $can_create ? __( 'Ask your site administrator to review this account’s Application Password permissions, or authorize using an account permitted to create one.', 'vibe-ai' ) : __( 'Sign in as an administrator of this site before connecting. On a multisite network, use a super admin.', 'vibe-ai' ) ) ),
			self::address_row( $site ),
			WPVibe_Diagnostics::check_row(),
		);
		if ( $rest_off ) {
			foreach ( $checks as &$row ) {
				if ( 'Application Password availability' === $row['stage'] ) {
					$row = array_merge( $row, WPVibe_App_Password_Policy::rest_off_text() );
				}
			}
			unset( $row );
		}
		// Core's authorize page wp_die()s with a bare 501 under HTTP Basic Auth, before our beacon can load; this admin request sees the same credentials it would.
		if ( function_exists( 'wp_is_site_protected_by_basic_auth' ) && wp_is_site_protected_by_basic_auth( 'front' ) ) {
			$checks[] = self::second_login_row();
		}
		$checks = array_merge( $checks, array(
			array( 'stage' => 'Authenticated site access', 'status' => 'not_checked', 'summary' => __( 'This connectivity check does not use saved credentials. If already connected, use Check authorized access to test them. For a new connection, connect the site in Step 3.', 'vibe-ai' ) ),
			array( 'stage' => 'AI connection', 'status' => 'not_checked', 'summary' => __( 'Confirmed once your AI reads the site through WPVibe in Step 4.', 'vibe-ai' ) ),
		) );
		return array( 'site' => $site, 'checkedAt' => gmdate( 'c' ), 'checks' => $checks, 'evidence' => array(), 'edge' => self::edge_owner() );
	}

	/**
	 * The Second login row, naming where core's answer came from (#1295). Core reads PHP_AUTH_*, which it fills from an
	 * arriving Basic Authorization header before plugins load, then lets the wp_is_site_protected_by_basic_auth filter
	 * change the answer. AUTH_TYPE is set only when the web server itself checked the login (Apache, LiteSpeed).
	 */
	private static function second_login_row() {
		$refuses = __( 'While WordPress reports a second login, it refuses to approve Application Passwords, so Step 3 would stop at "Cannot Authorize Application".', 'vibe-ai' );
		$host    = __( 'Turn off the password protection on this site or its wp-admin folder (your host may call it directory privacy or password-protected directories), then run the check again and connect in Step 3. If the protection covers only wp-admin, you can turn it back on after connecting.', 'vibe-ai' );
		$sent    = ! empty( $_SERVER['PHP_AUTH_USER'] ) || ! empty( $_SERVER['PHP_AUTH_PW'] );
		$checked = isset( $_SERVER['AUTH_TYPE'] ) && 'basic' === strtolower( sanitize_text_field( wp_unslash( $_SERVER['AUTH_TYPE'] ) ) );
		if ( ! $sent ) {
			$summary = __( 'This page carried no second login, but a plugin or custom code on this site tells WordPress the site is behind one (the wp_is_site_protected_by_basic_auth filter).', 'vibe-ai' );
			$next    = __( 'Find the plugin or custom code that uses the wp_is_site_protected_by_basic_auth filter (often a staging, maintenance or security plugin, or an mu-plugin) and turn it off while you connect in Step 3, then run the check again.', 'vibe-ai' );
		} elseif ( $checked ) {
			$summary = __( 'The web server checked a second username and password (HTTP Basic Authentication) before WordPress loaded.', 'vibe-ai' );
			$next    = $host;
		} else {
			$summary = __( 'This page arrived with a second username and password (HTTP Basic Authentication). Usually that is password protection at your host, but it can also be a login your browser saved for this site earlier.', 'vibe-ai' );
			$next    = $host . ' ' . __( 'If you know of no such protection, run the check in a private window: if it passes there, your browser had a saved login, so quit the browser completely (not just its windows) and connect again.', 'vibe-ai' );
		}
		return array( 'stage' => 'Second login (HTTP Basic Authentication)', 'status' => 'failed', 'summary' => $summary . ' ' . $refuses, 'next' => $next );
	}

	public static function remote( $nonce, $challenge ) {
		if ( ! self::authorized( $nonce ) ) {
			return new WP_Error( 'forbidden', __( 'Refresh this page and sign in as a WordPress administrator to run the check.', 'vibe-ai' ), array( 'status' => 403 ) );
		}
		$active = get_transient( self::CHALLENGE );
		if ( ! is_string( $challenge ) || ! is_array( $active ) || $active['expires'] <= time() || $active['user'] !== get_current_user_id() || ! empty( $active['used'] ) || ! hash_equals( $active['challenge'], $challenge ) ) {
			return new WP_Error( 'check_expired', __( 'This check expired or already ran. Wait one minute, then run a new connection check.', 'vibe-ai' ), array( 'status' => 409 ) );
		}
		$active['used'] = true;
		set_transient( self::CHALLENGE, $active, max( 1, $active['expires'] - time() ) );
		$response = wp_remote_post( self::ENDPOINT, array(
			'timeout' => 25, 'redirection' => 0, 'limit_response_size' => 262144,
			'headers' => array( 'Content-Type' => 'application/json', 'Accept' => 'application/json' ),
			'body' => wp_json_encode( array( 'site_url' => site_url(), 'challenge' => $challenge ) ),
		) );
		$report = self::remote_report( $response, $challenge );
		if ( isset( $active['report'] ) && $active['report']['site'] === site_url() ) {
			$local   = ! empty( $active['self']['checks'] ) ? array( $active['report'], $active['self'] ) : array( $active['report'] );
			$blocked = false;
			foreach ( $local as $part ) {
				$blocked = $blocked || in_array( 'failed', array_column( $part['checks'], 'status' ), true );
			}
			if ( ! empty( $report['cooldown'] ) && ! $blocked ) { return $report; }
			$reached = false;
			foreach ( $report['checks'] ?? array() as $check ) {
				if ( 'WPVibe server reaching this installation' === $check['stage'] && 'passed' === $check['status'] ) { $reached = true; }
			}
			$reports = ! empty( $report['cooldown'] ) ? $local : array_merge( $local, array( $report ) );
			update_option( self::SNAPSHOT, array( 'site' => site_url(), 'user' => get_current_user_id(), 'checkedAt' => $report['checkedAt'] ?? $active['report']['checkedAt'], 'state' => ! $blocked && $reached ? 'done' : 'attention', 'reports' => $reports ), false );
		}
		return $report;
	}

	public static function remote_report( $response, $challenge = '' ) {
		$status = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		if ( 429 === $status ) {
			return array( 'cooldown' => true, 'retry_after' => 60 );
		}
		$body = is_wp_error( $response ) ? '' : wp_remote_retrieve_body( $response );
		$report = strlen( $body ) < 262144 ? json_decode( $body, true ) : null;
		$service_error = is_array( $report ) && isset( $report['error'] ) && is_string( $report['error'] ) && in_array( $status, array( 400, 429, 503 ), true ) ? self::clean_text( $report['error'], $challenge ) : '';
		if ( $status < 200 || $status >= 300 || ! is_array( $report ) || ! isset( $report['checks'] ) || ! is_array( $report['checks'] ) || empty( $report['checks'] ) ) {
			return array( 'site' => site_url(), 'checkedAt' => gmdate( 'c' ), 'checks' => array( array(
				'stage' => 'Remote readiness check', 'status' => 'unknown',
				/* translators: %d: HTTP status code. */
				'summary' => $status ? sprintf( __( 'The remote checker did not return a usable report (HTTP %d). Your local results are still available.', 'vibe-ai' ), $status ) : __( 'WordPress could not receive a response from the WPVibe checker. Your local results are still available.', 'vibe-ai' ),
				'next' => '' !== $service_error ? $service_error : ( 429 === $status ? __( 'Wait one minute and retry. Repeated checks can be rate limited.', 'vibe-ai' ) : __( 'Retry once after a minute. If it repeats, send this report to support@wpvibe.ai and ask your host to inspect outbound HTTPS to mcp.wpvibe.ai. This result does not identify whether the cause is hosting, networking, or WPVibe.', 'vibe-ai' ) ),
			) ), 'evidence' => array() );
		}
		$clean = array( 'site' => site_url(), 'checkedAt' => gmdate( 'c' ), 'checks' => array(), 'evidence' => array() );
		foreach ( array_slice( $report['checks'], 0, 30 ) as $check ) {
			if ( ! is_array( $check ) || ! isset( $check['status'] ) || ! in_array( $check['status'], array( 'passed', 'limited', 'failed', 'unknown', 'not_checked' ), true ) ) { continue; }
			$row = array( 'status' => $check['status'] );
			foreach ( array( 'stage', 'summary', 'next', 'via', 'rule', 'supportDetails' ) as $key ) {
				if ( isset( $check[ $key ] ) && is_string( $check[ $key ] ) ) { $row[ $key ] = self::clean_text( $check[ $key ], $challenge, 'supportDetails' === $key ? 6000 : 1200 ); }
			}
			if ( isset( $row['stage'], $row['summary'] ) ) { $clean['checks'][] = $row; }
		}
		foreach ( array_slice( isset( $report['evidence'] ) && is_array( $report['evidence'] ) ? $report['evidence'] : array(), 0, 30 ) as $item ) {
			if ( ! is_array( $item ) ) { continue; }
			$row = array();
			foreach ( array( 'at', 'via', 'method', 'url', 'status', 'trace', 'server', 'durationMs', 'authenticated', 'outcome' ) as $key ) {
				if ( isset( $item[ $key ] ) && is_scalar( $item[ $key ] ) ) { $row[ $key ] = is_string( $item[ $key ] ) ? self::clean_text( $item[ $key ], $challenge ) : $item[ $key ]; }
			}
			$clean['evidence'][] = $row;
		}
		foreach ( array( 'note', 'report' ) as $key ) {
			if ( isset( $report[ $key ] ) && is_string( $report[ $key ] ) ) { $clean[ $key ] = self::clean_text( $report[ $key ], $challenge, 'report' === $key ? 60000 : 4000 ); }
		}
		if ( empty( $clean['checks'] ) ) { return self::remote_report( new WP_Error( 'invalid_report' ) ); }
		return $clean;
	}

	private static function clean_text( $value, $challenge, $limit = 4000 ) {
		$value = '' !== $challenge ? str_replace( $challenge, '[redacted]', $value ) : $value;
		return substr( wp_strip_all_tags( $value ), 0, $limit );
	}
}
