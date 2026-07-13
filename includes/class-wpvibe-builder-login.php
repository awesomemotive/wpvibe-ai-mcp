<?php
/**
 * One-time SeedProd builder auto-login for compile automation.
 *
 * SeedProd pages written through the REST/abilities path store JSON but not
 * the compiled HTML the front end serves; only a Save inside the Vue builder
 * writes it. The WPVibe Worker automates that Save with a headless browser,
 * which needs a wp-admin session. This class mints single-use, short-lived
 * login URLs whose destination is pinned server-side to the builder screen
 * for one specific page, so a leaked URL is worth at most one builder visit
 * for a couple of minutes.
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

	private static $instance = null;

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
		// Single-use: burn before validating so a raced second request loses.
		delete_transient( $key );

		if ( ! is_array( $record ) || empty( $record['user_id'] ) || empty( $record['post_id'] ) ) {
			return new WP_Error( 'builder_login_invalid', 'invalid' );
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
