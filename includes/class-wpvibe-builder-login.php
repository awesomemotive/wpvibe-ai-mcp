<?php
/**
 * One-time SeedProd builder auto-login for compile automation.
 *
 * SeedProd pages written through the REST/abilities path store JSON but not
 * the compiled HTML the front end serves; only a Save inside the Vue builder
 * writes it. The WPVibe Worker automates that Save with a headless browser,
 * which needs a wp-admin session. This class mints single-use, short-lived
 * login URLs whose destination is pinned server-side to the builder screen
 * for one specific page. Each URL signs in once, within two minutes; the
 * session it opens is an ordinary session for the minting user.
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

	private static $instance = null;

	/**
	 * Which claim path the last consume() took: 'options_row' or
	 * 'object_cache'. For tests and diagnostics only.
	 *
	 * @var string|null
	 */
	public $last_claim_path = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'maybe_consume' ), 1 );
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
		wp_set_auth_cookie( $result['user_id'], false, is_ssl() );

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
