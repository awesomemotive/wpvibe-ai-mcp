<?php
/**
 * Enqueues the live reload polling script for logged-in users.
 *
 * Frontend pages: auto-reload when WPVibe makes changes.
 * wp-admin pages: toast notification with action button.
 * Permissions are enforced by the REST endpoint, not the script.
 */

defined( 'ABSPATH' ) || exit;

class WPVibe_Live_Reload {

	// Frontend page-builder edit sessions: auto-reload/navigation there
	// destroys the user's editing session mid-boot (reads as an endless
	// builder spinner while an MCP session is active).
	const BUILDER_EDIT_PARAMS = array( 'et_fb', 'fl_builder', 'elementor-preview', 'bricks', 'breakdance', 'breakdance_iframe', 'oxygen', 'ct_builder', 'vc_editable', 'brizy-edit', 'brizy-edit-iframe', 'fb-edit' );

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		// Fallback: error pages (e.g., "not allowed") don't fire admin_footer.
		// Enqueue a minimal polling script in admin_head so the user isn't stuck.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_head_fallback' ) );
	}

	/**
	 * Whether the request is a frontend page-builder edit session.
	 *
	 * @param array $get $_GET snapshot.
	 * @return bool
	 */
	public static function is_builder_edit_request( array $get ) {
		$params = apply_filters( 'wpvibe_builder_edit_params', self::BUILDER_EDIT_PARAMS );
		foreach ( (array) $params as $param ) {
			if ( isset( $get[ $param ] ) ) {
				return true;
			}
		}
		// Elementor's editor is an admin screen (post.php?action=elementor);
		// the same-post reload() branch would destroy the session.
		if ( isset( $get['action'] ) && 'elementor' === $get['action'] ) {
			return true;
		}
		return false;
	}

	/**
	 * Check if the current user should get the live reload script.
	 *
	 * @return bool
	 */
	private function should_load() {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading URL params, not processing form.
		if ( self::is_builder_edit_request( $_GET ) ) {
			return false;
		}

		// Only inject when WPVibe has been active recently (transient exists).
		$changes = get_transient( WPVibe_Change_Tracker::TRANSIENT_KEY );
		if ( empty( $changes ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Enqueue live reload script and styles.
	 */
	public function enqueue_scripts() {
		if ( ! $this->should_load() ) {
			return;
		}

		wp_enqueue_style(
			'wpvibe-live-reload',
			WPVIBE_PLUGIN_URL . 'assets/css/live-reload.css',
			array(),
			WPVIBE_VERSION
		);

		wp_enqueue_script(
			'wpvibe-live-reload',
			WPVIBE_PLUGIN_URL . 'assets/js/live-reload.js',
			array(),
			WPVIBE_VERSION,
			true // Load in footer.
		);

		// Detect current post ID for same-post auto-refresh in admin.
		$post_id = 0;
		if ( is_admin() ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading URL param, not processing form.
			$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		}

		wp_localize_script( 'wpvibe-live-reload', 'wpvibeLiveReload', array(
			'endpoint' => esc_url( rest_url( 'wpvibe/v1/last-change' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'isAdmin'  => is_admin() ? '1' : '',
			'postId'   => (string) $post_id,
			'userId'   => (string) get_current_user_id(),
		) );
	}

	/**
	 * Minimal fallback for admin pages that don't fire admin_footer (e.g., error pages).
	 * Only handles navigation so the user isn't stuck on an error page.
	 * Enqueued to load in the document head.
	 */
	public function enqueue_head_fallback() {
		if ( ! $this->should_load() ) {
			return;
		}

		wp_enqueue_script(
			'wpvibe-live-reload-fallback',
			WPVIBE_PLUGIN_URL . 'assets/js/live-reload-fallback.js',
			array(),
			WPVIBE_VERSION,
			false // Load in head so it fires even when admin_footer doesn't.
		);

		wp_localize_script(
			'wpvibe-live-reload-fallback',
			'wpvibeLiveReloadFallback',
			array(
				'endpoint' => esc_url( rest_url( 'wpvibe/v1/last-change' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'userId'   => (string) get_current_user_id(),
			)
		);
	}
}
