<?php
/**
 * Meta-box dispatcher.
 *
 * Reads registered fields from WPVibe_Fields, adds one meta box per group
 * per post type, renders fields inside via WPVibe_Field_Renderers, and
 * persists submitted values on save_post. Also conditionally enqueues the
 * picker JS/CSS (only on screens that have fields registered).
 */

defined( 'ABSPATH' ) || exit;

class WPVibe_Meta_Boxes {

	private static $instance;
	const NONCE_ACTION = 'wpvibe_fields_save';
	const NONCE_NAME   = '_wpvibe_fields_nonce';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ), 10, 2 );
		add_action( 'save_post', array( $this, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * @param string  $post_type
	 * @param WP_Post $post
	 */
	public function register_meta_boxes( $post_type, $post ) {
		$grouped = WPVibe_Fields::instance()->get_grouped_fields( $post_type );
		if ( empty( $grouped ) ) {
			return;
		}

		foreach ( $grouped as $group_id => $group ) {
			$box_id = 'wpvibe-fields-' . $group_id;
			add_meta_box(
				$box_id,
				$group['config']['title'],
				array( $this, 'render_group' ),
				$post_type,
				$group['config']['context'],
				$group['config']['priority'],
				array( 'group_id' => $group_id, 'fields' => $group['fields'] )
			);
		}
	}

	/**
	 * Render the contents of a single grouped meta box.
	 *
	 * @param WP_Post $post
	 * @param array   $box  add_meta_box's box arg; 'args' contains group_id + fields.
	 */
	public function render_group( $post, $box ) {
		$fields = isset( $box['args']['fields'] ) ? $box['args']['fields'] : array();
		if ( empty( $fields ) ) {
			return;
		}

		// Single nonce per post, not per box; multiple boxes on the same post
		// share the nonce so save() only needs to verify once.
		static $nonce_rendered = false;
		if ( ! $nonce_rendered ) {
			wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
			$nonce_rendered = true;
		}

		echo '<style>.wpvibe-field-row{margin:12px 0}.wpvibe-field-row > label{display:block;font-weight:600;margin-bottom:4px}</style>';

		foreach ( $fields as $key => $config ) {
			$value = get_post_meta( $post->ID, $key, true );
			if ( '' === $value && '' !== $config['default'] && ! ( is_array( $config['default'] ) && empty( $config['default'] ) ) ) {
				$value = $config['default'];
			}
			WPVibe_Field_Renderers::render( $key, $value, $config, 'meta' );
		}
	}

	/**
	 * @param int     $post_id
	 * @param WP_Post $post
	 */
	public function save( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			return; // No fields on this post type, or save didn't come from our meta box.
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$fields = WPVibe_Fields::instance()->get_fields( $post->post_type );
		if ( empty( $fields ) ) {
			return;
		}

		foreach ( $fields as $key => $config ) {
			if ( ! array_key_exists( $key, $_POST ) ) {
				// Checkbox fields submit a hidden 0 sibling so they are always
				// present; if the key is genuinely missing, leave the existing
				// value alone (don't clobber on partial form submits).
				continue;
			}
			$raw   = wp_unslash( $_POST[ $key ] );
			$clean = WPVibe_Fields::instance()->sanitize_field( $config, $raw );
			update_post_meta( $post_id, $key, $clean );
		}
	}

	/**
	 * Conditionally enqueue picker JS/CSS. Only loads on post edit screens
	 * for post types that have registered fields, or on the WPVibe settings
	 * page when at least one complex-type setting is registered.
	 *
	 * @param string $hook
	 */
	public function enqueue_assets( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$needs_assets = false;

		if ( 'post' === $screen->base ) {
			$needs_assets = ! empty( WPVibe_Fields::instance()->get_fields( $screen->post_type ) );
		}

		if ( ! $needs_assets && 'settings_page_wpvibe' === $screen->id ) {
			$settings = WPVibe_Fields::instance()->get_settings();
			foreach ( $settings as $setting ) {
				if ( in_array( $setting['type'], array( 'image', 'color', 'wysiwyg' ), true ) ) {
					$needs_assets = true;
					break;
				}
			}
		}

		if ( ! $needs_assets ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
		wp_enqueue_script( 'jquery-ui-sortable' );

		$version = defined( 'WPVIBE_VERSION' ) ? WPVIBE_VERSION : false;
		wp_enqueue_style(
			'wpvibe-fields-admin',
			WPVIBE_PLUGIN_URL . 'assets/fields/admin.css',
			array(),
			$version
		);
		wp_enqueue_script(
			'wpvibe-fields-admin',
			WPVIBE_PLUGIN_URL . 'assets/fields/admin.js',
			array( 'jquery', 'wp-color-picker', 'jquery-ui-sortable' ),
			$version,
			true
		);
	}
}
