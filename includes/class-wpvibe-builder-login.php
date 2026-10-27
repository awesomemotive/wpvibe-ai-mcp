<?php
/**
 * One-time SeedProd builder auto-login for compile automation.
 *
 * SeedProd pages written through the REST/abilities path store JSON but not
 * the compiled HTML the front end serves; only a Save inside the Vue builder
 * writes it. The WPVibe Worker automates that Save with a headless browser,
 * which needs a wp-admin session. This class mints single-use, short-lived
 * login URLs whose destination is pinned server-side to the builder screen
 * for one specific page. Each URL signs in once, within two minutes. The
 * session it opens lasts ten minutes and is confined to that page's builder:
 * any other wp-admin screen, admin-post or admin-ajax call outside the
 * builder's own, REST request, or front-end write (a comment, a form post)
 * signs it out and is refused (#1084).
 *
 * @package WPVibe
 */

defined( 'ABSPATH' ) || exit;

class WPVibe_Builder_Login {

	const QUERY_PARAM      = 'wpvibe_builder_login';
	const TOKEN_TTL        = 120;
	const RATE_LIMIT       = 30;
	const RATE_WINDOW      = 300;
	const TRANSIENT_PREFIX = 'wpvibe_bl_';
	const RATE_KEY         = 'wpvibe_bl_rate';
	const CACHE_GROUP      = 'wpvibe_builder_login';
	const SESSION_MARK     = 'wpvibe_builder';
	const SESSION_PAGE     = 'wpvibe_builder_page';
	// A compile holds its session for one job: 30 s to open the builder plus 90 s for
	// the Save (compile-service/server.js SP_NAV_TIMEOUT_MS, SP_SAVE_TIMEOUT_MS), inside
	// the Worker's 120 s call. Ten minutes is five times that; core's default is two days.
	const SESSION_TTL      = 600;

	/**
	 * admin-ajax actions a builder session may call, after the seedprod_pro_ or
	 * seedprod_lite_ prefix: the Save the compile step clicks, and the read and
	 * render calls the builder's Vue app makes while it draws the page (SeedProd
	 * 6.20.10, public/{pro,lite}/vue-backend/js/index.js). Every other action the
	 * builder can call writes something besides this page (settings, add-ons,
	 * templates, media, license) or spends AI credits; the compile step never
	 * reaches those.
	 */
	const BUILDER_AJAX = array(
		'save_lpage',
		'get_revisions',
		'get_utc_offset',
		'get_namespaced_custom_css',
		'get_domain_mapping_domain',
		'get_site_logo',
		'get_template_part',
		'get_template_parts_list',
		'get_template_parts_result',
		'get_nested_navmenu',
		'render_shortcode',
		'render_templatetag',
		'render_postinfo_templatetags',
		'render_posts_block_preview',
		'render_wc_template_tags',
		'render_edd_template_tags',
		'render_gallery_shortcode',
		'render_basic_gallery_shortcode',
		'render_business_review_shortcode',
		'render_shortcode_wc_cart',
		'render_shortcode_wc_checkout',
		'render_shortcode_wc_custom_products_grid',
		'render_shortcode_edd_cart',
		'render_shortcode_edd_checkout',
		'render_shortcode_edd_downloads_grid',
		'render_shortcode_edd_buy_now_button',
		'edd_checkout_purchase_form',
		'get_wpform',
		'get_wpforms',
		'get_widget_wpforms',
		'get_widget_wpresults',
		'get_rafflepress',
		'get_rafflepress_code',
		'get_mypaykit',
		'get_mypaykit_code',
		'get_envira_galleries',
		'get_woocommerce_products',
		'get_woocommerce_product_taxonomy',
		'get_woocommerce_product_attributes',
		'get_woocommerce_product_attribute_terms',
		'get_edd_downloads',
		'get_edd_download_taxonomy',
		'get_all_post_types',
		'get_all_post_categories',
		'get_all_post_tags',
		'get_all_post_authors',
		'get_post_custom_keys_array',
	);

	/**
	 * Builder calls that name the page being edited, and the field that carries it.
	 * When present it must be the page the link was minted for; Save requires it.
	 * The render previews carry no page id (they take a shortcode, tag or query).
	 */
	const PAGE_PARAMS = array(
		'save_lpage'                 => 'lpage_id',
		'get_revisions'              => 'lpage_id',
		'get_domain_mapping_domain'  => 'lpage_id',
		'get_template_part'          => 'post_id',
		'get_post_custom_keys_array' => 'id',
	);

	/**
	 * Taken from a builder session for the rest of an allowed request. unfiltered_html
	 * stays: SeedProd's Save refuses without it (app/lpage.php, save_lpage).
	 */
	const STRIPPED_CAPS = array(
		'unfiltered_upload',
		'edit_files',
		'edit_plugins',
		'edit_themes',
		'install_plugins',
		'upload_plugins',
		'install_themes',
		'upload_themes',
		'update_plugins',
		'update_themes',
		'update_core',
		'activate_plugins',
		'delete_plugins',
		'delete_themes',
		'switch_themes',
		'create_users',
		'promote_users',
		'edit_users',
		'delete_users',
	);

	/** Every cookie a session can sign in with; wp-admin reads auth/secure_auth, REST and nonces read logged_in. */
	const COOKIE_SCHEMES = array( 'auth', 'secure_auth', 'logged_in' );

	private static $instance = null;

	/**
	 * Which claim path the last consume() took: 'options_row' or
	 * 'object_cache'. For tests and diagnostics only.
	 *
	 * @var string|null
	 */
	public $last_claim_path = null;

	/** The builder session's user while an allowed request runs; its caps are trimmed. */
	private $confined_user_id = 0;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'maybe_consume' ), 1 );
		// init, not admin_init: admin.php runs admin_menu callbacks and screen-option
		// saves before admin_init, and plugins handle their own requests on init.
		add_action( 'init', array( $this, 'confine_request' ), PHP_INT_MIN );
		// Comments skip kses for unfiltered_html and auto-approve for moderators.
		add_action( 'pre_comment_on_post', array( $this, 'refuse_comment' ), 0 );
		// After core's cookie nonce check (100), so an anonymous request stays anonymous.
		add_filter( 'rest_authentication_errors', array( $this, 'confine_rest' ), 110 );
		// Before core's handler (1).
		add_action( 'wp_ajax_heartbeat', array( $this, 'confine_heartbeat' ), 0 );
	}

	/**
	 * The builder admin-page slug differs between SeedProd Pro and Lite.
	 * Null when no SeedProd builder is available.
	 */
	public static function builder_page_slug() {
		if ( function_exists( 'seedprod_pro_builder_page' ) ) {
			return 'seedprod_pro_builder';
		}
		if ( function_exists( 'seedprod_lite_builder_page' ) ) {
			return 'seedprod_lite_builder';
		}
		return null;
	}

	/**
	 * Same capability SeedProd requires for its builder screen.
	 */
	public static function required_capability() {
		return apply_filters( 'seedprod_builder_menu_capability', 'edit_others_posts' );
	}

	public static function builder_url( $post_id ) {
		return admin_url( 'admin.php?page=' . self::builder_page_slug() . '&id=' . (int) $post_id );
	}

	/**
	 * Landing pages save as post_type `page` flagged by `_seedprod_page`;
	 * only theme templates and mode pages use the `seedprod` CPT.
	 */
	public static function is_seedprod_page( $post_id ) {
		$type = get_post_type( $post_id );
		if ( 'seedprod' === $type ) {
			return true;
		}
		return 'page' === $type && (bool) get_post_meta( (int) $post_id, '_seedprod_page', true );
	}

	/**
	 * REST handler: mint a login URL for one SeedProd page.
	 * Route + permission callback live in WPVibe_REST.
	 */
	public function mint( $request ) {
		if ( ! self::builder_page_slug() ) {
			return new WP_Error(
				'seedprod_missing',
				__( 'SeedProd is not active on this site, so there is no builder to open.', 'vibe-ai' ),
				WPVibe_Error_Contract::data( 'not_supported', false, array( 'status' => 501 ) )
			);
		}

		$post_id = (int) $request->get_param( 'page_id' );
		if ( $post_id <= 0 || ! self::is_seedprod_page( $post_id ) ) {
			return new WP_Error(
				'not_seedprod_page',
				__( 'That id is not a SeedProd page, so a builder login cannot be minted for it.', 'vibe-ai' ),
				WPVibe_Error_Contract::data( 'not_found', false, array( 'status' => 404 ) )
			);
		}

		$count = (int) get_transient( self::RATE_KEY );
		if ( $count >= self::RATE_LIMIT ) {
			return new WP_Error(
				'builder_login_rate_limited',
				__( 'Too many builder login links were requested in a short period. Wait a few minutes and try again.', 'vibe-ai' ),
				WPVibe_Error_Contract::data( 'security_gate', true, array( 'status' => 429 ) )
			);
		}
		set_transient( self::RATE_KEY, $count + 1, self::RATE_WINDOW );

		$token = $this->generate_token();
		set_transient(
			self::TRANSIENT_PREFIX . hash( 'sha256', $token ),
			array(
				'user_id' => get_current_user_id(),
				'post_id' => $post_id,
				'expires' => time() + self::TOKEN_TTL,
			),
			self::TOKEN_TTL
		);

		if ( class_exists( 'WPVibe_Audit_Log' ) ) {
			WPVibe_Audit_Log::log_execution(
				array(
					'operation'      => 'builder_login_mint',
					'command'        => 'page_id=' . $post_id,
					'result_summary' => 'login URL minted, ttl ' . self::TOKEN_TTL . 's',
				)
			);
		}

		return rest_ensure_response(
			array(
				'login_url'   => add_query_arg( self::QUERY_PARAM, rawurlencode( $token ), home_url( '/' ) ),
				'builder_url' => self::builder_url( $post_id ),
				'expires_in'  => self::TOKEN_TTL,
			)
		);
	}

	protected function generate_token() {
		return wp_generate_password( 43, false, false );
	}

	/**
	 * Validate and burn a token. Pure with respect to auth side effects so
	 * tests can exercise every branch; maybe_consume() applies the results.
	 *
	 * @return array|WP_Error { user_id, post_id, redirect } on success.
	 */
	public function consume( $token, $now = null ) {
		$now    = null === $now ? time() : (int) $now;
		$key    = self::TRANSIENT_PREFIX . hash( 'sha256', (string) $token );
		$record = get_transient( $key );

		if ( ! is_array( $record ) || empty( $record['user_id'] ) || empty( $record['post_id'] ) ) {
			return new WP_Error( 'builder_login_invalid', 'invalid' );
		}
		// Single-use: claim before validating. Two requests can both read the
		// record; only the one whose claim succeeds may redeem it.
		if ( ! $this->claim( $key ) ) {
			return new WP_Error( 'builder_login_invalid', 'already used' );
		}
		if ( empty( $record['expires'] ) || $now > (int) $record['expires'] ) {
			return new WP_Error( 'builder_login_expired', 'expired' );
		}
		if ( ! self::builder_page_slug() ) {
			return new WP_Error( 'builder_login_no_builder', 'seedprod inactive' );
		}
		$user = get_user_by( 'id', (int) $record['user_id'] );
		if ( ! $user || ! user_can( $user, self::required_capability() ) ) {
			return new WP_Error( 'builder_login_forbidden', 'capability revoked' );
		}

		return array(
			'user_id'  => (int) $record['user_id'],
			'post_id'  => (int) $record['post_id'],
			'redirect' => self::builder_url( $record['post_id'] ),
		);
	}

	/**
	 * Burn a token exactly once, even under concurrent requests. Returns true
	 * only for the one caller that wins.
	 *
	 * Without a persistent object cache the transient is a row in wp_options,
	 * and a DELETE reports how many rows it removed: 1 wins, 0 means another
	 * request already took it. With a persistent object cache the transient
	 * never reaches wp_options, so wp_cache_add of a one-shot key is the lock:
	 * add succeeds only for the first caller. The lock's winner must still find
	 * the token in the cache backend (forced read, past the in-request copy)
	 * and must be the one whose delete removes it: if a flush or eviction drops
	 * the lock while another request holds it, a second add can succeed, but
	 * the backend deletes the key for only one of them.
	 */
	protected function claim( $key ) {
		if ( wp_using_ext_object_cache() ) {
			$this->last_claim_path = 'object_cache';
			if ( ! wp_cache_add( 'consumed_' . $key, 1, self::CACHE_GROUP, self::TOKEN_TTL ) ) {
				return false;
			}
			$still_there = wp_cache_get( $key, 'transient', true );
			return false !== $still_there && (bool) delete_transient( $key );
		}

		global $wpdb;
		$this->last_claim_path = 'options_row';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The affected-row count is the atomic single-use check; caches are cleared below.
		$rows = $wpdb->delete( $wpdb->options, array( 'option_name' => '_transient_' . $key ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $wpdb->options, array( 'option_name' => '_transient_timeout_' . $key ) );
		wp_cache_delete( '_transient_' . $key, 'options' );
		wp_cache_delete( '_transient_timeout_' . $key, 'options' );
		return 1 === $rows;
	}

	/** True when the current browser session was opened by a builder sign-in link. */
	public static function is_builder_session() {
		return null !== self::builder_session();
	}

	/** The current session's record when a builder sign-in link opened it, else null. */
	private static function builder_session() {
		$found = self::builder_sessions();
		return $found ? $found[0]['session'] : null;
	}

	/**
	 * Builder sessions behind any auth cookie the request carries. Every scheme is
	 * checked, not only the logged_in cookie wp_get_session_token() reads: a client
	 * that drops logged_in still signs in to wp-admin with the auth cookie alone.
	 *
	 * @return array[] { user_id, token, session } for each tagged session.
	 */
	private static function builder_sessions() {
		if ( ! function_exists( 'wp_parse_auth_cookie' ) || ! class_exists( 'WP_Session_Tokens' ) ) {
			return array();
		}
		$found = array();
		foreach ( self::COOKIE_SCHEMES as $scheme ) {
			$cookie = wp_parse_auth_cookie( '', $scheme );
			if ( empty( $cookie['token'] ) ) {
				continue;
			}
			$user_id = (int) wp_validate_auth_cookie( '', $scheme );
			if ( $user_id <= 0 ) {
				continue;
			}
			$session = WP_Session_Tokens::get_instance( $user_id )->get( $cookie['token'] );
			if ( is_array( $session ) && ! empty( $session[ self::SESSION_MARK ] ) ) {
				$found[] = array( 'user_id' => $user_id, 'token' => $cookie['token'], 'session' => $session );
			}
		}
		return $found;
	}

	/**
	 * What a builder session may load in wp-admin: the builder screen for the page
	 * the link was minted for, exactly as the compile step opens it, and the
	 * builder's own admin-ajax calls (Save only for that page).
	 */
	public static function admin_request_allowed( $pagenow, $method, $get, $post, $page_id ) {
		if ( $page_id <= 0 ) {
			return false;
		}
		if ( 'admin.php' === $pagenow ) {
			$slug = self::builder_page_slug();
			return in_array( $method, array( 'GET', 'HEAD' ), true )
				&& null !== $slug
				&& array() === array_diff( array_keys( $get ), array( 'page', 'id' ) )
				&& isset( $get['page'], $get['id'] )
				&& $slug === $get['page']
				&& (string) $page_id === $get['id'];
		}
		if ( 'admin-ajax.php' !== $pagenow ) {
			return false;
		}
		// Core reads $_REQUEST, which WordPress rebuilds as GET overlaid by POST.
		$action = isset( $post['action'] ) ? $post['action'] : ( isset( $get['action'] ) ? $get['action'] : '' );
		if ( ! is_string( $action ) ) {
			return false;
		}
		// wp-auth-check, which SeedProd keeps on the builder screen, polls heartbeat with no
		// payload (core answers it on heartbeat_send). A payload drives other handlers:
		// post nonces, autosaves, locks. confine_heartbeat() strips the rest.
		if ( 'heartbeat' === $action ) {
			return empty( $post['data'] );
		}
		if ( ! preg_match( '/^seedprod_(?:pro|lite)_([a-z_]+)$/', $action, $m ) || ! in_array( $m[1], self::BUILDER_AJAX, true ) ) {
			return false;
		}
		if ( ! isset( self::PAGE_PARAMS[ $m[1] ] ) ) {
			return true;
		}
		$field = self::PAGE_PARAMS[ $m[1] ];
		$sent  = isset( $post[ $field ] ) ? $post[ $field ] : ( isset( $get[ $field ] ) ? $get[ $field ] : null );
		if ( null === $sent ) {
			return 'save_lpage' !== $m[1];
		}
		return (string) $page_id === $sent;
	}

	/**
	 * init: a builder session reaches nothing in wp-admin beyond the builder, and on
	 * the front end only reads (GET/HEAD: the sign-in link, previews). A front-end
	 * write (wp-comments-post.php, any plugin's form handler) signs it out.
	 */
	public function confine_request() {
		$session = self::builder_session();
		if ( null === $session ) {
			return;
		}
		global $pagenow;
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( is_admin() ) {
			$page_id = isset( $session[ self::SESSION_PAGE ] ) ? (int) $session[ self::SESSION_PAGE ] : 0;
			// phpcs:ignore WordPress.Security.NonceVerification -- Read-only allowlist check; the builder's own handlers verify their nonces.
			$allowed = self::admin_request_allowed( (string) $pagenow, $method, wp_unslash( $_GET ), wp_unslash( $_POST ), $page_id );
		} else {
			$allowed = in_array( $method, array( 'GET', 'HEAD' ), true );
		}
		if ( $allowed ) {
			$this->confined_user_id = get_current_user_id();
			add_filter( 'user_has_cap', array( $this, 'limit_caps' ), 10, 4 );
			return;
		}
		// The action or page in the log tells a refused builder call from a probe.
		$what = isset( $_REQUEST['action'] ) ? $_REQUEST['action'] : ( isset( $_GET['page'] ) ? $_GET['page'] : '' ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		self::refuse( trim( (string) $pagenow . ' ' . $method . ' ' . ( is_string( $what ) ? sanitize_key( $what ) : '' ) ) );
	}

	/** pre_comment_on_post: never a comment from a builder session, whatever reached here. */
	public function refuse_comment() {
		if ( null !== self::builder_session() ) {
			self::refuse( 'wp-comments-post.php' );
		}
	}

	/** user_has_cap, added only for an allowed builder request: no code, plugin, theme or user caps. */
	public function limit_caps( $allcaps, $caps, $args, $user ) {
		if ( $this->confined_user_id > 0 && is_object( $user ) && isset( $user->ID ) && (int) $user->ID === $this->confined_user_id ) {
			foreach ( self::STRIPPED_CAPS as $cap ) {
				$allcaps[ $cap ] = false;
			}
		}
		return $allcaps;
	}

	private static function refuse( $where ) {
		self::end_session( $where );
		wp_die(
			esc_html__( 'This WPVibe builder session can only open the SeedProd builder, so it has been signed out.', 'vibe-ai' ),
			'',
			array( 'response' => 403 )
		);
	}

	/** rest_authentication_errors: the builder makes no REST calls, so a builder session gets none. */
	public function confine_rest( $result ) {
		if ( is_wp_error( $result ) || ! is_user_logged_in() ) {
			return $result;
		}
		if ( did_action( 'application_password_did_authenticate' ) || ( function_exists( 'rest_get_authenticated_app_password' ) && rest_get_authenticated_app_password() ) ) {
			return $result;
		}
		if ( null === self::builder_session() ) {
			return $result;
		}
		self::end_session( 'rest' );
		return new WP_Error(
			'wpvibe_builder_session',
			__( 'This WPVibe builder session can only open the SeedProd builder, so it has been signed out.', 'vibe-ai' ),
			array( 'status' => 403 )
		);
	}

	/** wp_ajax_heartbeat, before core's handler: a builder session's heartbeat runs core's auth check only. */
	public function confine_heartbeat() {
		if ( null === self::builder_session() ) {
			return;
		}
		remove_all_filters( 'heartbeat_received' );
		remove_all_filters( 'wp_refresh_nonces' );
		remove_all_filters( 'heartbeat_send' );
		remove_all_actions( 'heartbeat_tick' );
		add_filter( 'heartbeat_send', 'wp_auth_check' );
	}

	/** Destroy the builder session's tokens so a refused request also ends it. */
	private static function end_session( $where ) {
		if ( class_exists( 'WPVibe_Audit_Log' ) ) {
			WPVibe_Audit_Log::log_execution(
				array(
					'operation'      => 'builder_session_refused',
					'command'        => $where,
					'result_summary' => 'builder session signed out',
				)
			);
		}

		foreach ( self::builder_sessions() as $found ) {
			WP_Session_Tokens::get_instance( $found['user_id'] )->destroy( $found['token'] );
		}
		wp_clear_auth_cookie();
		wp_set_current_user( 0 );
	}

	/** Sign in for one page's builder: a marked session that lasts SESSION_TTL, not two days. */
	public static function open_session( $user_id, $page_id ) {
		$mark = static function ( $info ) use ( $page_id ) {
			$info[ self::SESSION_MARK ] = true;
			$info[ self::SESSION_PAGE ] = (int) $page_id;
			return $info;
		};
		$ttl  = static function () {
			return self::SESSION_TTL;
		};
		add_filter( 'attach_session_information', $mark );
		add_filter( 'auth_cookie_expiration', $ttl, PHP_INT_MAX );
		wp_set_auth_cookie( $user_id, false, is_ssl() );
		remove_filter( 'auth_cookie_expiration', $ttl, PHP_INT_MAX );
		remove_filter( 'attach_session_information', $mark );
	}

	public function maybe_consume() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Single-use token validated via hashed transient lookup in consume().
		if ( empty( $_GET[ self::QUERY_PARAM ] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token  = sanitize_text_field( wp_unslash( $_GET[ self::QUERY_PARAM ] ) );
		$result = $this->consume( $token );

		if ( is_wp_error( $result ) ) {
			// One generic message for every failure branch: no oracle for probing.
			wp_die(
				esc_html__( 'This WPVibe builder sign-in link is invalid or has expired. Links are single-use and expire after two minutes; request a fresh one.', 'vibe-ai' ),
				'',
				array( 'response' => 403 )
			);
		}

		wp_set_current_user( $result['user_id'] );
		// The link is minted with an application password, so the session it opens is
		// marked: it can open this page's builder and nothing else in wp-admin (#1084;
		// confine_admin(), confine_rest()), never the form that only a real browser login
		// may submit (security audit 10-20, #6; WPVibe_Approval_Bypass::is_cookie_session()).
		self::open_session( $result['user_id'], (int) $result['post_id'] );

		if ( class_exists( 'WPVibe_Audit_Log' ) ) {
			WPVibe_Audit_Log::log_execution(
				array(
					'operation'      => 'builder_login',
					'command'        => 'page_id=' . $result['post_id'],
					'result_summary' => 'builder session opened',
				)
			);
		}

		wp_safe_redirect( $result['redirect'] );
		exit;
	}
}
