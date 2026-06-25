<?php
/**
 * WPVibe Fields registry.
 *
 * Lightweight wrapper over native WordPress APIs (register_post_meta,
 * register_setting, add_meta_box, Settings API). Themes declare editable
 * surfaces via wpvibe_field_register() / wpvibe_setting_register(); the
 * registry stores the configs and surfaces them to the meta-box dispatcher
 * and the settings page renderer.
 *
 * No data layer of its own — meta lives in {prefix}postmeta, settings in
 * {prefix}options. The plugin can be deactivated without losing data; only
 * the admin UI disappears.
 */

defined( 'ABSPATH' ) || exit;

class WPVibe_Fields {

	private static $instance;

	/** ['post_type' => ['key' => $config, ...]] */
	private $fields = array();

	/** ['post_type' => ['group_id' => $config, ...]] */
	private $groups = array();

	/** ['key' => $config] */
	private $settings = array();

	/** Default group id when a field omits 'group'. */
	const DEFAULT_GROUP = 'wpvibe';

	/** Recognized field types. */
	const TYPES = array(
		'text', 'textarea', 'number', 'email', 'url', 'date', 'checkbox',
		'color', 'image', 'gallery', 'wysiwyg', 'post_select', 'repeater',
	);

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Register an editable field on a post type.
	 *
	 * @param string $post_type Post type slug.
	 * @param string $key       Meta key (also the form input name).
	 * @param array  $config    Field config; see plan for full shape.
	 * @return true|WP_Error
	 */
	public function register_field( $post_type, $key, $config ) {
		if ( empty( $post_type ) || ! is_string( $post_type ) ) {
			return new WP_Error( 'invalid_post_type', __( 'Post type required.', 'vibe-ai' ) );
		}
		if ( empty( $key ) || ! is_string( $key ) ) {
			return new WP_Error( 'invalid_key', __( 'Field key required.', 'vibe-ai' ) );
		}
		$config = $this->normalize_field_config( $config );
		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$this->fields[ $post_type ][ $key ] = $config;

		// Hook into register_post_meta so the field is REST-exposed for the MCP
		// and sanitized on out-of-band writes (wp-cli post meta update, etc.).
		// ALSO add 'custom-fields' post type support: register_post_meta with
		// show_in_rest=true is NOT enough on its own. If the CPT does not
		// declare 'custom-fields' support, WP_REST_Posts_Controller silently
		// drops the meta field from responses AND never invokes update_value
		// for meta in request bodies — POST /wp/v2/<cpt>/<id> with meta returns
		// 200 OK with no error and no write. Built-in 'post' and 'page' already
		// support custom-fields; CPTs don't unless they opt in. Auto-adding it
		// removes a sharp WP gotcha AI-built themes would otherwise hit.
		// Defer until the 'init' action if we're called too early.
		if ( did_action( 'init' ) ) {
			$this->register_post_meta_for( $post_type, $key, $config );
			add_post_type_support( $post_type, 'custom-fields' );
		} else {
			add_action( 'init', function () use ( $post_type, $key, $config ) {
				$this->register_post_meta_for( $post_type, $key, $config );
				add_post_type_support( $post_type, 'custom-fields' );
			}, 11 );
		}

		return true;
	}

	/**
	 * Register a global editable site setting.
	 *
	 * @param string $key
	 * @param array  $config
	 * @return true|WP_Error
	 */
	public function register_setting( $key, $config ) {
		if ( empty( $key ) || ! is_string( $key ) ) {
			return new WP_Error( 'invalid_key', __( 'Setting key required.', 'vibe-ai' ) );
		}
		$config = $this->normalize_setting_config( $config );
		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$this->settings[ $key ] = $config;
		// The actual register_setting() call runs on admin_init from WPVibe_Settings.
		return true;
	}

	/**
	 * Register a meta-box group on a post type.
	 *
	 * @param string $post_type
	 * @param string $group_id
	 * @param array  $config Keys: title, context (normal|side|advanced), priority.
	 * @return true|WP_Error
	 */
	public function register_group( $post_type, $group_id, $config ) {
		if ( empty( $post_type ) || empty( $group_id ) ) {
			return new WP_Error( 'invalid_args', __( 'Post type and group id required.', 'vibe-ai' ) );
		}
		$this->groups[ $post_type ][ $group_id ] = array(
			'title'    => isset( $config['title'] ) ? (string) $config['title'] : ucfirst( $group_id ),
			'context'  => isset( $config['context'] ) ? (string) $config['context'] : 'normal',
			'priority' => isset( $config['priority'] ) ? (string) $config['priority'] : 'default',
		);
		return true;
	}

	/**
	 * @param string $post_type
	 * @return array key => config
	 */
	public function get_fields( $post_type ) {
		return isset( $this->fields[ $post_type ] ) ? $this->fields[ $post_type ] : array();
	}

	/**
	 * Fields grouped by group_id. Fields without a 'group' land in DEFAULT_GROUP.
	 *
	 * @param string $post_type
	 * @return array group_id => [ 'config' => array, 'fields' => [key => config, ...] ]
	 */
	public function get_grouped_fields( $post_type ) {
		$fields = $this->get_fields( $post_type );
		if ( empty( $fields ) ) {
			return array();
		}

		$groups = isset( $this->groups[ $post_type ] ) ? $this->groups[ $post_type ] : array();
		$out    = array();

		foreach ( $fields as $key => $config ) {
			$gid = isset( $config['group'] ) ? $config['group'] : self::DEFAULT_GROUP;
			if ( ! isset( $out[ $gid ] ) ) {
				$out[ $gid ] = array(
					'config' => isset( $groups[ $gid ] ) ? $groups[ $gid ] : array(
						'title'    => self::DEFAULT_GROUP === $gid ? __( 'Details', 'vibe-ai' ) : ucfirst( $gid ),
						'context'  => 'normal',
						'priority' => 'default',
					),
					'fields' => array(),
				);
			}
			$out[ $gid ]['fields'][ $key ] = $config;
		}

		return $out;
	}

	/** All post types that have at least one registered field. */
	public function get_post_types_with_fields() {
		return array_keys( $this->fields );
	}

	/** @return array key => config */
	public function get_settings() {
		return $this->settings;
	}

	/** @return array|null Single field config; null if not registered. */
	public function get_field( $post_type, $key ) {
		return isset( $this->fields[ $post_type ][ $key ] ) ? $this->fields[ $post_type ][ $key ] : null;
	}

	/** @return array|null Setting config; null if not registered. */
	public function get_setting( $key ) {
		return isset( $this->settings[ $key ] ) ? $this->settings[ $key ] : null;
	}

	// ------------------------------------------------------------------
	// Normalization
	// ------------------------------------------------------------------

	private function normalize_field_config( $config ) {
		if ( ! is_array( $config ) ) {
			return new WP_Error( 'invalid_config', __( 'Field config must be an array.', 'vibe-ai' ) );
		}
		$type = isset( $config['type'] ) ? $config['type'] : 'text';
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return new WP_Error(
				'invalid_type',
				/* translators: %s: type provided */
				sprintf( __( 'Unknown field type \'%s\'. Allowed: text, textarea, number, email, url, date, checkbox, color, image, gallery, wysiwyg, post_select, repeater.', 'vibe-ai' ), (string) $type )
			);
		}

		return array(
			'type'        => $type,
			'label'       => isset( $config['label'] ) ? (string) $config['label'] : '',
			'description' => isset( $config['description'] ) ? (string) $config['description'] : '',
			'default'     => isset( $config['default'] ) ? $config['default'] : $this->default_for_type( $type ),
			'group'       => isset( $config['group'] ) ? (string) $config['group'] : null,
			'sub_fields'  => isset( $config['sub_fields'] ) && is_array( $config['sub_fields'] ) ? $config['sub_fields'] : array(),
			'options'     => isset( $config['options'] ) && is_array( $config['options'] ) ? $config['options'] : array(),
		);
	}

	private function normalize_setting_config( $config ) {
		if ( ! is_array( $config ) ) {
			return new WP_Error( 'invalid_config', __( 'Setting config must be an array.', 'vibe-ai' ) );
		}
		$type = isset( $config['type'] ) ? $config['type'] : 'text';
		// Settings page does not support repeater, gallery, or post_select (post-context only).
		$allowed = array( 'text', 'textarea', 'number', 'email', 'url', 'date', 'checkbox', 'color', 'image', 'wysiwyg' );
		if ( ! in_array( $type, $allowed, true ) ) {
			return new WP_Error(
				'invalid_type',
				/* translators: %s: type provided */
				sprintf( __( 'Setting type \'%s\' is not supported. Settings can be text, textarea, number, email, url, date, checkbox, color, image, or wysiwyg.', 'vibe-ai' ), (string) $type )
			);
		}
		return array(
			'type'        => $type,
			'label'       => isset( $config['label'] ) ? (string) $config['label'] : '',
			'description' => isset( $config['description'] ) ? (string) $config['description'] : '',
			'default'     => isset( $config['default'] ) ? $config['default'] : $this->default_for_type( $type ),
		);
	}

	private function default_for_type( $type ) {
		switch ( $type ) {
			case 'number':
			case 'checkbox':
			case 'image':
			case 'post_select':
				return 0;
			case 'gallery':
			case 'repeater':
				return array();
			default:
				return '';
		}
	}

	// ------------------------------------------------------------------
	// REST exposure via register_post_meta
	// ------------------------------------------------------------------

	private function register_post_meta_for( $post_type, $key, $config ) {
		$args = array(
			'type'              => $this->wp_meta_type( $config['type'] ),
			'single'            => true,
			'default'           => $config['default'],
			'sanitize_callback' => array( $this, 'sanitize_value_for_meta' ),
			// Non-protected meta defaults to __return_true on modern WP, but
			// some environments (and older WP versions) fall back to a more
			// restrictive default that silently blocks REST writes. Be explicit.
			'auth_callback'     => '__return_true',
			'show_in_rest'      => $this->rest_schema( $config ),
		);
		// register_post_meta does not pass extra context to the sanitize callback,
		// so stash the type via a closure-aware static map.
		$this->meta_type_lookup[ $post_type . ':' . $key ] = $config;
		register_post_meta( $post_type, $key, $args );
	}

	/** Lookup so sanitize_value_for_meta can find the type of the field being saved. */
	private $meta_type_lookup = array();

	/**
	 * Generic sanitizer. WordPress passes the meta key + post type via the
	 * filter context; we look up our config and dispatch to the type-specific
	 * sanitizer. Used both for REST writes and for in-form saves via the
	 * meta-box dispatcher.
	 *
	 * @param mixed  $value
	 * @param string $key       Meta key (passed by WP filter).
	 * @param string $type      Meta type (passed by WP filter).
	 * @return mixed
	 */
	public function sanitize_value_for_meta( $value, $key = '', $type = '' ) {
		// register_post_meta's sanitize_callback signature varies by WP version.
		// Most reliable approach: try to identify the field from our registry by
		// matching the key against fields on every post type with that key. If
		// ambiguous, fall back to a conservative text sanitize.
		$config = $this->find_field_by_key( $key );
		if ( null === $config ) {
			return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : $value;
		}
		return $this->sanitize_field( $config, $value );
	}

	private function find_field_by_key( $key ) {
		foreach ( $this->fields as $post_type => $fields ) {
			if ( isset( $fields[ $key ] ) ) {
				return $fields[ $key ];
			}
		}
		return null;
	}

	private function wp_meta_type( $field_type ) {
		switch ( $field_type ) {
			case 'number':
				return 'number';
			case 'checkbox':
			case 'image':
			case 'post_select':
				return 'integer';
			case 'gallery':
			case 'repeater':
				return 'array';
			default:
				return 'string';
		}
	}

	private function rest_schema( $config ) {
		switch ( $config['type'] ) {
			case 'gallery':
				return array(
					'schema' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'integer' ),
					),
				);
			case 'repeater':
				$properties = array();
				foreach ( $config['sub_fields'] as $sub_key => $sub_config ) {
					if ( ! is_array( $sub_config ) ) {
						$sub_config = array( 'type' => 'text' );
					}
					$properties[ $sub_key ] = array(
						'type' => $this->json_schema_type( isset( $sub_config['type'] ) ? $sub_config['type'] : 'text' ),
					);
				}
				return array(
					'schema' => array(
						'type'  => 'array',
						'items' => array(
							'type'                 => 'object',
							'properties'           => $properties,
							'additionalProperties' => false,
						),
					),
				);
			default:
				return true;
		}
	}

	private function json_schema_type( $field_type ) {
		switch ( $field_type ) {
			case 'number':
				return 'number';
			case 'checkbox':
				return 'boolean';
			default:
				return 'string';
		}
	}

	// ------------------------------------------------------------------
	// Sanitization (dispatched by type)
	// ------------------------------------------------------------------

	public function sanitize_field( $config, $raw ) {
		switch ( $config['type'] ) {
			case 'text':
				return sanitize_text_field( (string) $raw );
			case 'textarea':
				return sanitize_textarea_field( (string) $raw );
			case 'number':
				return is_numeric( $raw ) ? (float) $raw : 0;
			case 'email':
				return sanitize_email( (string) $raw );
			case 'url':
				return esc_url_raw( (string) $raw );
			case 'date':
				return sanitize_text_field( (string) $raw );
			case 'checkbox':
				return ( '1' === (string) $raw || true === $raw || 1 === $raw ) ? 1 : 0;
			case 'color':
				$sanitized = sanitize_hex_color( (string) $raw );
				return null === $sanitized ? '' : $sanitized;
			case 'image':
			case 'post_select':
				return absint( $raw );
			case 'wysiwyg':
				return wp_kses_post( (string) $raw );
			case 'gallery':
				if ( is_array( $raw ) ) {
					return array_values( array_filter( array_map( 'absint', $raw ) ) );
				}
				return array_values( array_filter( array_map( 'absint', explode( ',', (string) $raw ) ) ) );
			case 'repeater':
				return $this->sanitize_repeater( $raw, $config['sub_fields'] );
			default:
				return is_scalar( $raw ) ? sanitize_text_field( (string) $raw ) : $raw;
		}
	}

	private function sanitize_repeater( $raw, $sub_fields ) {
		if ( is_string( $raw ) ) {
			$raw = json_decode( $raw, true );
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$clean = array();
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$clean_row = array();
			foreach ( $sub_fields as $sub_key => $sub_config ) {
				if ( ! is_array( $sub_config ) ) {
					$sub_config = array( 'type' => 'text' );
				}
				$clean_row[ $sub_key ] = $this->sanitize_field( $sub_config, $row[ $sub_key ] ?? '' );
			}
			$clean[] = $clean_row;
		}
		return $clean;
	}
}
