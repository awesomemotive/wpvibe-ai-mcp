<?php
/**
 * Elementor REST endpoints.
 *
 * Read endpoints (schema discovery) and write endpoints (atomic page + template
 * save). All callbacks gate on Elementor being active; we load this file
 * unconditionally because the file load is cheap, but no route does anything
 * if Elementor isn't available — it returns 404 with elementor_inactive.
 *
 * Where possible we route through Elementor's own higher-level APIs
 * (Documents_Manager, Conditions_Manager) instead of touching post meta
 * directly, so we follow along when Elementor's internals evolve.
 *
 * Non-fatal failures (CSS regen, conditions cache regen) are collected into a
 * `warnings` array on the response rather than swallowed silently, so the AI
 * caller has a feedback loop.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPVibe_Elementor {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		$ns = 'wpvibe/v1';

		register_rest_route( $ns, '/elementor/widgets', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'list_widgets' ),
			'permission_callback' => array( $this, 'can_edit_posts' ),
			'args'                => array(),
		) );

		register_rest_route( $ns, '/elementor/schema', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_schema' ),
			'permission_callback' => array( $this, 'can_edit_posts' ),
			'args'                => array(
				'slug'   => array( 'type' => 'string', 'required' => true,  'sanitize_callback' => 'sanitize_key' ),
				'names'  => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
				'prefix' => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );

		register_rest_route( $ns, '/elementor/style-schema', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_style_schema' ),
			'permission_callback' => array( $this, 'can_edit_posts' ),
			'args'                => array(
				'names'  => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
				'prefix' => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );

		register_rest_route( $ns, '/elementor/save-page', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'save_page' ),
			'permission_callback' => array( $this, 'can_edit_posts' ),
			'args'                => array(
				'id'            => array( 'type' => 'integer', 'required' => false, 'sanitize_callback' => 'absint' ),
				'title'         => array( 'type' => 'string',  'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
				'post_type'     => array( 'type' => 'string',  'default'  => 'page', 'sanitize_callback' => 'sanitize_key' ),
				'status'        => array( 'type' => 'string',  'default'  => 'publish', 'sanitize_callback' => 'sanitize_key' ),
				'template_type' => array( 'type' => 'string',  'default'  => 'wp-page', 'sanitize_callback' => 'sanitize_key' ),
				'page_template' => array( 'type' => 'string',  'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
				'data'          => array( 'type' => 'array',   'required' => true ),
			),
		) );

		register_rest_route( $ns, '/elementor/save-template', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'save_template' ),
			'permission_callback' => array( $this, 'can_manage_options' ),
			'args'                => array(
				'id'         => array( 'type' => 'integer', 'required' => false, 'sanitize_callback' => 'absint' ),
				'title'      => array( 'type' => 'string',  'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
				'type'       => array( 'type' => 'string',  'required' => true,  'sanitize_callback' => 'sanitize_key' ),
				'status'     => array( 'type' => 'string',  'default'  => 'publish', 'sanitize_callback' => 'sanitize_key' ),
				'data'       => array( 'type' => 'array',   'required' => true ),
				'conditions' => array(
					'type'              => 'array',
					'default'           => array( 'include/general' ),
					'sanitize_callback' => array( $this, 'sanitize_conditions' ),
				),
			),
		) );
	}

	// ------------------------------------------------------------------
	// Permission callbacks
	// ------------------------------------------------------------------

	public function can_edit_posts() {
		return current_user_can( 'edit_posts' ) ? true : $this->missing_capability_error( 'edit_posts' );
	}

	public function can_manage_options() {
		return current_user_can( 'manage_options' ) ? true : $this->missing_capability_error( 'manage_options' );
	}

	/**
	 * Build a WP_Error naming the missing capability, instead of returning a
	 * bare `false` (WordPress's generic "Sorry, you are not allowed to do
	 * that" 403/401 tells the AI nothing it can act on).
	 */
	private function missing_capability_error( $capability ) {
		return new WP_Error(
			'wpvibe_missing_capability',
			sprintf(
				/* translators: %s: WordPress capability name, e.g. edit_posts */
				__( 'This action requires the WordPress capability "%s", which the connected account does not have. Administrators have it by default — reconnect with an account that has this capability for full access.', 'vibe-ai' ),
				$capability
			),
			WPVibe_Error_Contract::data( 'capability_role', false, array( 'status' => rest_authorization_required_code(), 'capability' => $capability ) )
		);
	}

	/**
	 * Per-post-type capability check.
	 *
	 * The route-level permission_callback only confirms `edit_posts` — that's
	 * insufficient for the writes here, because `documents->create()`,
	 * `wp_insert_post`, and `wp_update_post` do not enforce caps on their own.
	 * Without this guard a Contributor could create a published `page` despite
	 * lacking `edit_pages` / `publish_pages`.
	 *
	 * @param string      $post_type  Slug.
	 * @param string      $action     'create' | 'update'.
	 * @param int|null    $post_id    Required for update.
	 * @param string|null $new_status Status the request is moving toward; 'publish' triggers the publish-cap check.
	 * @return true|WP_Error
	 */
	private function check_post_caps( $post_type, $action, $post_id = null, $new_status = null ) {
		$pt_obj = get_post_type_object( $post_type );
		if ( ! $pt_obj ) {
			return new WP_Error( 'invalid_post_type', sprintf(
				/* translators: %s: post type slug */
				__( 'Unknown post type: %s', 'vibe-ai' ),
				$post_type
			), WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 400 ) ) );
		}
		if ( 'create' === $action ) {
			if ( ! current_user_can( $pt_obj->cap->create_posts ) ) {
				return new WP_Error( 'forbidden', sprintf(
					/* translators: %s: post type label */
					__( 'You do not have permission to create %s.', 'vibe-ai' ),
					$pt_obj->labels->name
				), WPVibe_Error_Contract::data( 'capability_role', false, array( 'status' => 403 ) ) );
			}
		} else {
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return new WP_Error( 'forbidden', sprintf(
					/* translators: %d: post ID */
					__( 'You do not have permission to edit post #%d.', 'vibe-ai' ),
					$post_id
				), WPVibe_Error_Contract::data( 'capability_cpt_mapping', false, array( 'status' => 403 ) ) );
			}
		}
		if ( 'publish' === $new_status && ! current_user_can( $pt_obj->cap->publish_posts ) ) {
			return new WP_Error( 'forbidden_publish', sprintf(
				/* translators: %s: post type label */
				__( 'You do not have permission to publish %s.', 'vibe-ai' ),
				$pt_obj->labels->name
			), WPVibe_Error_Contract::data( 'capability_role', false, array( 'status' => 403 ) ) );
		}
		return true;
	}

	// ------------------------------------------------------------------
	// Input sanitizers
	// ------------------------------------------------------------------

	/**
	 * Conditions are slash-joined strings: include/general, include/singular/post/in/12.
	 * Reject anything that doesn't match include|exclude/<scope>[/<sub_name>][/<sub_id>].
	 */
	public function sanitize_conditions( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$out = array();
		foreach ( $value as $entry ) {
			$entry = sanitize_text_field( (string) $entry );
			if ( preg_match( '#^(include|exclude)/[a-z0-9_-]+(/[a-z0-9_-]+){0,3}$#', $entry ) ) {
				$out[] = $entry;
			}
		}
		return $out;
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Run $fn; on exception push a warning onto $warnings. Returns $fn's value or null on failure.
	 * Used so the response always tells the AI WHICH non-fatal sub-step failed instead of swallowing it.
	 */
	/**
	 * Document::save with fatal containment. Elementor's save pipeline compiles
	 * atomic CSS inline (after_save hook); malformed v4 props (e.g. a bare number
	 * where a {$$type:"number"} transformable is required) throw a PHP Error there,
	 * AFTER the post row exists — without cleanup every failed save orphans a
	 * broken page. $created_id > 0 means this request created the post and it
	 * should be removed on failure.
	 */
	private function save_document( $document, array $data, int $created_id ) {
		try {
			return $document->save( array( 'elements' => $data ) );
		} catch ( \Throwable $e ) {
			if ( $created_id > 0 ) {
				wp_delete_post( $created_id, true );
			}
			return new WP_Error(
				'elementor_save_fatal',
				sprintf(
					/* translators: 1: exception message */
					__( 'Elementor threw a fatal during save (usually while compiling CSS from the element data): %s. Common cause on Elementor 4.x: an atomic style/settings value missing its {"$$type": ...} transformable wrapper (numbers inside the flex prop, for example, must be {"$$type":"number","value":N}). %s', 'vibe-ai' ),
					$e->getMessage(),
					$created_id > 0 ? __( 'The partially created page has been deleted; fix the data and retry.', 'vibe-ai' ) : __( 'The existing page kept its previous saved data.', 'vibe-ai' )
				),
				WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 400 ) )
			);
		}
	}

	private function try_step( array &$warnings, callable $fn, string $code, string $context = '' ) {
		try {
			return $fn();
		} catch ( \Throwable $e ) {
			$warnings[] = array(
				'code'    => $code,
				'message' => $e->getMessage(),
				'context' => $context,
			);
			return null;
		}
	}

	private function elementor_check() {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return new WP_Error( 'elementor_inactive', __( 'Elementor is not active on this site.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'not_supported', false, array( 'status' => 404 ) ) );
		}
		return true;
	}

	private function theme_builder_check() {
		if ( ! class_exists( '\ElementorPro\Modules\ThemeBuilder\Module' ) ) {
			return new WP_Error( 'theme_builder_inactive', __( 'Elementor Pro theme builder is not active on this site.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'not_supported', false, array( 'status' => 404 ) ) );
		}
		return true;
	}

	/**
	 * Convert API-friendly slash-strings ("include/general") into the array shape
	 * Elementor's Conditions_Manager::save_conditions expects.
	 */
	private function parse_conditions( array $strings ): array {
		$out = array();
		foreach ( $strings as $s ) {
			$parts = explode( '/', $s );
			$out[] = array(
				'type'     => $parts[0] ?? 'include',
				'name'     => $parts[1] ?? 'general',
				'sub_name' => $parts[2] ?? '',
				'sub_id'   => $parts[3] ?? '',
			);
		}
		return $out;
	}

	// ------------------------------------------------------------------
	// GET /elementor/widgets
	// ------------------------------------------------------------------

	public function list_widgets() {
		$check = $this->elementor_check();
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$out = array();

		foreach ( \Elementor\Plugin::$instance->widgets_manager->get_widget_types() as $slug => $widget ) {
			$out[] = array(
				'slug'       => $slug,
				'title'      => $widget->get_title(),
				'categories' => $widget->get_categories(),
				'is_pro'     => 0 === strpos( get_class( $widget ), 'ElementorPro\\' ),
				'kind'       => 'widget',
				'atomic'     => $this->is_atomic( $widget ),
			);
		}

		foreach ( \Elementor\Plugin::$instance->elements_manager->get_element_types() as $slug => $element ) {
			$atomic = $this->is_atomic( $element );
			if ( ! $atomic && ! in_array( $slug, array( 'container', 'section', 'column' ), true ) ) {
				continue;
			}
			$out[] = array(
				'slug'       => $slug,
				'title'      => $element->get_title(),
				'categories' => $atomic ? array( 'v4-elements', 'structural' ) : array( 'structural' ),
				'is_pro'     => false,
				'kind'       => 'element',
				'atomic'     => $atomic,
			);
		}

		return rest_ensure_response( array(
			'elementor_version' => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null,
			'count'             => count( $out ),
			'items'             => $out,
		) );
	}

	// ------------------------------------------------------------------
	// GET /elementor/schema
	// ------------------------------------------------------------------

	public function get_schema( $request ) {
		$check = $this->elementor_check();
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$slug    = $request->get_param( 'slug' );
		$element = \Elementor\Plugin::$instance->widgets_manager->get_widget_types( $slug );
		$kind    = 'widget';
		if ( ! $element ) {
			$element = \Elementor\Plugin::$instance->elements_manager->get_element_types( $slug );
			$kind    = 'element';
		}
		if ( ! $element ) {
			return new WP_Error( 'unknown_slug', sprintf( __( 'No widget or element registered for slug "%s".', 'vibe-ai' ), $slug ), WPVibe_Error_Contract::data( 'not_found', false, array( 'status' => 404 ) ) );
		}

		$ui_only_types = array( 'section', 'tab', 'heading', 'notice', 'raw_html', 'deprecated_notice', 'alert' );

		$names_param  = (string) $request->get_param( 'names' );
		$prefix_param = (string) $request->get_param( 'prefix' );

		$names = '' !== $names_param ? array_filter( array_map( 'trim', explode( ',', $names_param ) ) ) : array();
		$mode  = ! empty( $names ) ? 'targeted' : ( '' !== $prefix_param ? 'prefix' : 'discovery' );

		if ( $this->is_atomic( $element ) ) {
			$props = $this->serialize_prop_schema( $element::get_props_schema(), $mode, $names, $prefix_param );
			return rest_ensure_response( array(
				'slug'              => $slug,
				'kind'              => $kind,
				'title'             => $element->get_title(),
				'is_pro'            => 0 === strpos( get_class( $element ), 'ElementorPro\\' ),
				'atomic'            => true,
				'elementor_version' => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null,
				'mode'              => $mode,
				'count'             => count( $props ),
				'props'             => $props,
				'note'              => 'Atomic (v4) element. Settings values are transformable: {"$$type":"<type>","value":...} where <type> is each prop\'s type key. Visual styling does NOT go in settings — it goes in the element\'s `styles` local classes (see /elementor/style-schema for valid style props) referenced from the `classes` setting. See the elementor skill.',
			) );
		}

		$controls = array();
		foreach ( $element->get_controls() as $control ) {
			if ( empty( $control['name'] ) || empty( $control['type'] ) ) {
				continue;
			}
			if ( in_array( $control['type'], $ui_only_types, true ) ) {
				continue;
			}
			if ( 'targeted' === $mode && ! in_array( $control['name'], $names, true ) ) {
				continue;
			}
			if ( 'prefix' === $mode && 0 !== strpos( $control['name'], $prefix_param ) ) {
				continue;
			}

			$entry = array(
				'name' => $control['name'],
				'type' => $control['type'],
			);

			if ( 'discovery' !== $mode ) {
				if ( isset( $control['default'] ) && '' !== $control['default'] && array() !== $control['default'] ) {
					$entry['default'] = $control['default'];
				}
				if ( ! empty( $control['responsive'] ) ) {
					$entry['responsive'] = true;
				}
				if ( ! empty( $control['condition'] ) ) {
					$entry['condition'] = $control['condition'];
				}
				if ( ! empty( $control['conditions'] ) ) {
					$entry['conditions'] = $control['conditions'];
				}
				if ( ! empty( $control['options'] ) && is_array( $control['options'] ) ) {
					$entry['options'] = array_values( array_filter( array_keys( $control['options'] ), 'strlen' ) );
				}
				if ( ! empty( $control['groupPrefix'] ) ) {
					$entry['group_prefix'] = $control['groupPrefix'];
				} elseif ( ! empty( $control['group_prefix'] ) ) {
					$entry['group_prefix'] = $control['group_prefix'];
				}
			}

			$controls[] = $entry;
		}

		return rest_ensure_response( array(
			'slug'              => $slug,
			'kind'              => $kind,
			'title'             => $element->get_title(),
			'is_pro'            => 0 === strpos( get_class( $element ), 'ElementorPro\\' ),
			'elementor_version' => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null,
			'mode'              => $mode,
			'count'             => count( $controls ),
			'controls'          => $controls,
			'note'              => 'Returns atomic controls registered on the widget/element. Group-control sub-fields (typography_*, background_overlay_*, border_*, box_shadow_*, text_shadow_*) are NOT included — they are platform-level conventions; see the elementor skill for the activator pattern and field names.',
		) );
	}

	// ------------------------------------------------------------------
	// GET /elementor/style-schema
	// ------------------------------------------------------------------

	public function get_style_schema( $request ) {
		$check = $this->elementor_check();
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		if ( ! class_exists( '\Elementor\Modules\AtomicWidgets\Styles\Style_Schema' ) ) {
			return new WP_Error( 'atomic_unavailable', __( 'This Elementor version has no atomic style schema (requires Elementor 4.x).', 'vibe-ai' ), WPVibe_Error_Contract::data( 'not_supported', false, array( 'status' => 404 ) ) );
		}

		$names_param  = (string) $request->get_param( 'names' );
		$prefix_param = (string) $request->get_param( 'prefix' );
		$names        = '' !== $names_param ? array_filter( array_map( 'trim', explode( ',', $names_param ) ) ) : array();
		$mode         = ! empty( $names ) ? 'targeted' : ( '' !== $prefix_param ? 'prefix' : 'discovery' );

		$props = $this->serialize_prop_schema( \Elementor\Modules\AtomicWidgets\Styles\Style_Schema::get(), $mode, $names, $prefix_param );

		return rest_ensure_response( array(
			'elementor_version' => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null,
			'mode'              => $mode,
			'count'             => count( $props ),
			'props'             => $props,
			'note'              => 'Props allowed inside a style variant\'s `props` (local element styles and global classes). Values are transformable: {"$$type":"<type>","value":...}. Variants carry meta {breakpoint: desktop|tablet|mobile|..., state: null|hover|focus|active}.',
		) );
	}

	// ------------------------------------------------------------------
	// Atomic (v4) prop schema serialization
	// ------------------------------------------------------------------

	private function is_atomic( $element ) {
		return method_exists( $element, 'get_props_schema' );
	}

	private function serialize_prop_schema( array $schema, $mode, array $names, $prefix ) {
		$out = array();

		foreach ( $schema as $name => $prop ) {
			if ( 'targeted' === $mode && ! in_array( $name, $names, true ) ) {
				continue;
			}
			if ( 'prefix' === $mode && 0 !== strpos( $name, $prefix ) ) {
				continue;
			}

			$entry = array(
				'name' => $name,
				'type' => $prop::get_key(),
			);

			// Unions wrap the real prop types (e.g. string|dynamic); surface the members.
			if ( method_exists( $prop, 'get_prop_types' ) ) {
				$members            = $prop->get_prop_types();
				$entry['types']     = array_keys( $members );
				$primary            = reset( $members );
				if ( $primary ) {
					$prop = $primary;
				}
			}

			if ( 'discovery' !== $mode ) {
				$serialized = $prop->jsonSerialize();
				if ( isset( $serialized['kind'] ) ) {
					$entry['kind'] = $serialized['kind'];
				}
				if ( isset( $serialized['default'] ) && null !== $serialized['default'] ) {
					$entry['default'] = $serialized['default'];
				}
				$settings = isset( $serialized['settings'] ) ? (array) $serialized['settings'] : array();
				if ( ! empty( $settings['enum'] ) ) {
					$entry['options'] = array_values( $settings['enum'] );
				}
				if ( ! empty( $settings['required'] ) ) {
					$entry['required'] = true;
				}
				$meta = isset( $serialized['meta'] ) ? (array) $serialized['meta'] : array();
				if ( ! empty( $meta['description'] ) ) {
					$entry['description'] = $meta['description'];
				}
				if ( method_exists( $prop, 'get_shape' ) ) {
					$shape = array();
					foreach ( $prop->get_shape() as $key => $sub ) {
						$shape[ $key ] = $sub::get_key();
					}
					$entry['shape'] = $shape;
				}
			}

			$out[] = $entry;
		}

		return $out;
	}

	// ------------------------------------------------------------------
	// POST /elementor/save-page
	// ------------------------------------------------------------------

	public function save_page( $request ) {
		$check = $this->elementor_check();
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$id            = $request->get_param( 'id' );
		$title         = $request->get_param( 'title' );
		$post_type     = $request->get_param( 'post_type' );
		$status        = $request->get_param( 'status' );
		$template_type = $request->get_param( 'template_type' );
		$page_template = $request->get_param( 'page_template' );
		$data          = $request->get_param( 'data' );

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'invalid_data', __( 'The `data` parameter must be an array of root Elementor elements.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 400 ) ) );
		}

		$warnings = array();
		$document = null;

		if ( $id ) {
			$post = get_post( $id );
			if ( ! $post ) {
				return new WP_Error( 'not_found', __( 'Post not found.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'not_found', false, array( 'status' => 404 ) ) );
			}
			$cap_check = $this->check_post_caps( $post->post_type, 'update', $id, $status );
			if ( is_wp_error( $cap_check ) ) {
				return $cap_check;
			}
			$update = array( 'ID' => $id );
			if ( $title ) {
				$update['post_title'] = $title;
			}
			if ( $status ) {
				$update['post_status'] = $status;
			}
			if ( count( $update ) > 1 ) {
				wp_update_post( $update );
			}
			// Ensure mode is set in case caller is upgrading a non-Elementor post.
			update_post_meta( $id, '_elementor_edit_mode', 'builder' );
			update_post_meta( $id, '_elementor_template_type', $template_type );
			$document = \Elementor\Plugin::$instance->documents->get( $id, false );
		} else {
			if ( ! $title ) {
				return new WP_Error( 'missing_title', __( 'A `title` is required when creating a new page.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 400 ) ) );
			}
			$cap_check = $this->check_post_caps( $post_type, 'create', null, $status );
			if ( is_wp_error( $cap_check ) ) {
				return $cap_check;
			}
			// Elementor's documents->create() picks the right Document subclass for $template_type AND
			// sets up the post type + meta envelope in one call. Cleaner than wp_insert_post + meta.
			$document = \Elementor\Plugin::$instance->documents->create(
				$template_type,
				array(
					'post_title'  => $title,
					'post_status' => $status,
					'post_type'   => $post_type,
				)
			);
			if ( is_wp_error( $document ) ) {
				return $document;
			}
			if ( ! $document ) {
				return new WP_Error( 'document_create_failed', __( 'documents->create() returned null.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'wp_core', false, array( 'status' => 500 ) ) );
			}
			$id = $document->get_main_id();
		}

		if ( ! $document ) {
			return new WP_Error( 'document_init_failed', __( 'Could not initialize Elementor document.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'not_supported', false, array( 'status' => 500 ) ) );
		}

		// Document::save handles _elementor_data write, version stamp,
		// _elementor_element_cache invalidation, and CSS regen.
		$saved = $this->save_document( $document, $data, empty( $request['id'] ) ? $id : 0 );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		// Direct meta write, not Document::save(['settings'=>...]) — save_settings()
		// replaces _elementor_page_settings wholesale, wiping existing page settings on update.
		if ( $page_template ) {
			update_post_meta( $id, '_wp_page_template', $page_template );
			$known = array_unique( array_merge(
				array( 'default', 'elementor_header_footer', 'elementor_canvas' ),
				array_keys( wp_get_theme()->get_page_templates( get_post( $id ) ) )
			) );
			if ( ! in_array( $page_template, $known, true ) ) {
				$warnings[] = array(
					'code'    => 'unknown_page_template',
					'message' => sprintf( __( 'Page template "%s" is not registered by the active theme or Elementor; WordPress will render the default template instead.', 'vibe-ai' ), $page_template ),
					'context' => 'known: ' . implode( ', ', $known ),
				);
			}
		}

		// Explicit per-post CSS regen — Document::save sometimes loses the CSS
		// write in PHP-WASM environments. Cheap belt-and-suspenders that won't hurt elsewhere.
		$this->try_step(
			$warnings,
			function() use ( $id ) {
				\Elementor\Core\Files\CSS\Post::create( $id )->update();
			},
			'css_regen_failed',
			"post_id={$id}"
		);

		return rest_ensure_response( array(
			'id'       => $id,
			'view_url' => get_permalink( $id ),
			'edit_url' => admin_url( 'post.php?post=' . $id . '&action=elementor' ),
			'warnings' => $warnings,
		) );
	}

	// ------------------------------------------------------------------
	// POST /elementor/save-template
	// ------------------------------------------------------------------

	public function save_template( $request ) {
		$check = $this->elementor_check();
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$check = $this->theme_builder_check();
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$id         = $request->get_param( 'id' );
		$title      = $request->get_param( 'title' );
		$type       = $request->get_param( 'type' );
		$status     = $request->get_param( 'status' );
		$data       = $request->get_param( 'data' );
		$conditions = $request->get_param( 'conditions' );

		$valid_types = array( 'header', 'footer', 'single', 'single-page', 'single-post', 'archive', 'search-results', 'error-404', 'section', 'popup' );
		if ( ! in_array( $type, $valid_types, true ) ) {
			return new WP_Error(
				'invalid_type',
				sprintf( __( 'Template `type` must be one of: %s', 'vibe-ai' ), implode( ', ', $valid_types ) ),
				WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 400 ) )
			);
		}
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'invalid_data', __( 'The `data` parameter must be an array of root Elementor elements.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 400 ) ) );
		}

		$warnings = array();
		$document = null;

		if ( $id ) {
			$post = get_post( $id );
			if ( ! $post || 'elementor_library' !== $post->post_type ) {
				return new WP_Error( 'not_found', __( 'Template not found.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'not_found', false, array( 'status' => 404 ) ) );
			}
			$update = array( 'ID' => $id );
			if ( $title ) {
				$update['post_title'] = $title;
			}
			if ( $status ) {
				$update['post_status'] = $status;
			}
			if ( count( $update ) > 1 ) {
				wp_update_post( $update );
			}
			update_post_meta( $id, '_elementor_edit_mode', 'builder' );
			update_post_meta( $id, '_elementor_template_type', $type );
			wp_set_object_terms( $id, $type, 'elementor_library_type' );
			$document = \Elementor\Plugin::$instance->documents->get( $id, false );
		} else {
			if ( ! $title ) {
				return new WP_Error( 'missing_title', __( 'A `title` is required when creating a new template.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 400 ) ) );
			}
			$document = \Elementor\Plugin::$instance->documents->create(
				$type,
				array(
					'post_title'  => $title,
					'post_status' => $status,
				)
			);
			if ( is_wp_error( $document ) ) {
				return $document;
			}
			if ( ! $document ) {
				return new WP_Error( 'document_create_failed', __( 'documents->create() returned null.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'wp_core', false, array( 'status' => 500 ) ) );
			}
			$id = $document->get_main_id();
		}

		if ( ! $document ) {
			return new WP_Error( 'document_init_failed', __( 'Could not initialize Elementor document.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'not_supported', false, array( 'status' => 500 ) ) );
		}

		$saved = $this->save_document( $document, $data, empty( $request['id'] ) ? $id : 0 );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		// Belt-and-suspenders CSS regen.
		$this->try_step(
			$warnings,
			function() use ( $id ) {
				\Elementor\Core\Files\CSS\Post::create( $id )->update();
			},
			'css_regen_failed',
			"post_id={$id}"
		);

		// Use Conditions_Manager::save_conditions — handles meta write AND cache regen
		// in one call. Falls back to manual path if for some reason it isn't available.
		$this->try_step(
			$warnings,
			function() use ( $id, $conditions ) {
				$manager = \ElementorPro\Modules\ThemeBuilder\Module::instance()->get_conditions_manager();
				$manager->save_conditions( $id, $this->parse_conditions( $conditions ) );
			},
			'conditions_save_failed',
			"post_id={$id}"
		);

		return rest_ensure_response( array(
			'id'         => $id,
			'type'       => $type,
			'conditions' => $conditions,
			'edit_url'   => admin_url( 'post.php?post=' . $id . '&action=elementor' ),
			'warnings'   => $warnings,
		) );
	}
}
