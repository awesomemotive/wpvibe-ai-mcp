<?php
/**
 * Post-edit sidebar for WPVibe-built themes.
 *
 * Renders a meta box in the right rail of every post edit screen when
 * the active theme declares itself with a "WPVibe: yes" header in
 * style.css. Content adapts to two checks:
 *
 *   1. Are any fields registered (via wpvibe_field_register) for the
 *      post type being edited?
 *   2. Does any admin have an Application Password whose name contains
 *      "WPVibe" (i.e., an MCP client is paired with the site)?
 *
 * The four combinations produce four sidebar states. Anchor links to
 * registered fields reuse the #wpvibe-field-{key} convention; the
 * edit-focus.js hashchange listener handles the scroll + focus + flash.
 */

defined( 'ABSPATH' ) || exit;

class WPVibe_Post_Sidebar {

	const META_BOX_ID = 'wpvibe-sidebar';
	const CONNECT_URL = 'https://mcp.wpvibe.ai/';

	private static $instance;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'extra_theme_headers', array( $this, 'register_theme_header' ) );
		add_action( 'add_meta_boxes',      array( $this, 'maybe_register_meta_box' ) );
	}

	/**
	 * Register the "WPVibe" theme header so wp_get_theme()->get('WPVibe')
	 * surfaces whatever the theme's style.css declares.
	 */
	public function register_theme_header( $headers ) {
		$headers['WPVibe'] = 'WPVibe';
		return $headers;
	}

	/**
	 * Add the sidebar meta box on all public post types when the active
	 * theme has opted in via the WPVibe header.
	 */
	public function maybe_register_meta_box() {
		if ( ! $this->is_wpvibe_theme() ) {
			return;
		}
		$post_types = get_post_types( array( 'public' => true ), 'names' );
		foreach ( $post_types as $post_type ) {
			add_meta_box(
				self::META_BOX_ID,
				__( 'WPVibe AI', 'vibe-ai' ),
				array( $this, 'render' ),
				$post_type,
				'side',
				'high'
			);
		}
	}

	public function render( $post ) {
		$fields    = WPVibe_Fields::instance()->get_fields( $post->post_type );
		$connected = self::is_mcp_connected();

		echo '<div class="wpvibe-sidebar">';

		// Top badge.
		echo '<div class="wpvibe-sidebar-badge">';
		echo '<span class="wpvibe-sidebar-icon" aria-hidden="true">';
		echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="14" height="14" fill="currentColor"><path d="M12 2 4 6v6c0 5 3.5 9.5 8 10 4.5-.5 8-5 8-10V6l-8-4zm0 2.2 6 3v4.8c0 4-2.6 7.4-6 8-3.4-.6-6-4-6-8V7.2l6-3z"/></svg>';
		echo '</span>';
		echo '<span>' . esc_html__( 'Built with WPVibe AI', 'vibe-ai' ) . '</span>';
		echo '</div>';

		// Field list or empty state.
		if ( ! empty( $fields ) ) {
			echo '<div class="wpvibe-sidebar-fields">';
			echo '<p class="wpvibe-sidebar-fields-title">' . esc_html__( 'Editable on this page', 'vibe-ai' ) . '</p>';
			echo '<ul>';
			foreach ( $fields as $key => $config ) {
				$label = $config['label'] ? $config['label'] : $key;
				printf(
					'<li><a href="#wpvibe-field-%1$s">%2$s</a></li>',
					esc_attr( $key ),
					esc_html( $label )
				);
			}
			echo '</ul>';
			echo '</div>';
		} else {
			echo '<div class="wpvibe-sidebar-empty">';
			echo '<p>' . esc_html__( 'No editable fields on this page yet.', 'vibe-ai' ) . '</p>';
			if ( $connected ) {
				echo '<p>' . esc_html__( 'Ask your AI assistant to register editable fields for this post type.', 'vibe-ai' ) . '</p>';
			} else {
				echo '<p>' . esc_html__( 'Connect an AI client below, then ask it to register editable fields.', 'vibe-ai' ) . '</p>';
			}
			echo '</div>';
		}

		// MCP status / Connect CTA.
		if ( $connected ) {
			echo '<div class="wpvibe-sidebar-mcp wpvibe-sidebar-mcp-connected">';
			echo '<span class="wpvibe-sidebar-dot" aria-hidden="true"></span>';
			echo '<span>' . esc_html__( 'Connected to WPVibe MCP', 'vibe-ai' ) . '</span>';
			echo '</div>';
		} else {
			$connect_url = self::CONNECT_URL . '?site=' . rawurlencode( home_url() );
			echo '<div class="wpvibe-sidebar-mcp">';
			printf(
				'<a class="button button-primary" href="%1$s" target="_blank" rel="noopener">%2$s</a>',
				esc_url( $connect_url ),
				esc_html__( 'Connect Claude / ChatGPT', 'vibe-ai' )
			);
			echo '<p class="wpvibe-sidebar-mcp-help">' . esc_html__( 'Pair an AI client to edit this site by chat.', 'vibe-ai' ) . '</p>';
			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Detect a WPVibe-built theme via the "WPVibe" style.css header.
	 *
	 * @return bool
	 */
	public static function is_wpvibe_theme() {
		$value = wp_get_theme()->get( 'WPVibe' );
		return ! empty( $value );
	}

	/**
	 * Check whether any admin has an Application Password whose name
	 * contains "WPVibe" — our proxy for "an MCP client is paired."
	 *
	 * Cached per-request because the render callback may indirectly
	 * trigger it more than once.
	 *
	 * @return bool
	 */
	public static function is_mcp_connected() {
		static $cached = null;
		if ( null !== $cached ) {
			return $cached;
		}
		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			$cached = false;
			return false;
		}
		$cached = false;
		$admins = get_users( array(
			'role__in' => array( 'administrator' ),
			'fields'   => 'ID',
		) );
		foreach ( $admins as $user_id ) {
			$passwords = WP_Application_Passwords::get_user_application_passwords( $user_id );
			if ( ! is_array( $passwords ) ) {
				continue;
			}
			foreach ( $passwords as $pw ) {
				$name = isset( $pw['name'] ) ? $pw['name'] : '';
				if ( false !== stripos( $name, 'WPVibe' ) ) {
					$cached = true;
					return true;
				}
			}
		}
		return false;
	}
}
