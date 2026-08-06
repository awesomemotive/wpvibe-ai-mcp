<?php
/**
 * Bricks REST endpoints.
 *
 * Bricks stores a layout as postmeta `_bricks_page_content_2`: a PHP-serialized
 * flat array of element objects, rendered only when `_bricks_editor_mode` is
 * `bricks`, with CSS written to per-post files when the site uses external
 * file loading. No generic write path is safe: content/edit refuses serialized
 * values, raw SQL corrupts s:N: length headers, and Bricks' own bricks/v1 REST
 * namespace has no page writer — so this endpoint is the only way an AI caller
 * can build a Bricks layout.
 *
 * REST-specific traps this endpoint exists to absorb (verified against 2.3.9):
 * Bricks' sanitize_post_meta_* filter no-ops outside admin-ajax, so
 * security_check_elements_before_save() must be called explicitly here; its
 * update_post_metadata filter silently hard-blocks writes for users without
 * builder access (pre-check + surface a 403 instead); and CSS file generation
 * gates on render_with_bricks(), which is false until the editor-mode meta is
 * written — so the write order content -> editor mode -> wp_update_post is
 * mandatory, not stylistic.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPVibe_Bricks {

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

		register_rest_route( $ns, '/bricks/save-page', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'save_page' ),
			'permission_callback' => array( $this, 'can_edit_posts' ),
			'args'                => array(
				'id'        => array( 'type' => 'integer', 'required' => false, 'sanitize_callback' => 'absint' ),
				'title'     => array( 'type' => 'string',  'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
				'post_type' => array( 'type' => 'string',  'default'  => 'page', 'sanitize_callback' => 'sanitize_key' ),
				'status'    => array( 'type' => 'string',  'default'  => 'publish', 'enum' => array( 'publish', 'draft', 'pending', 'private' ) ),
				'elements'  => array( 'type' => 'array',   'required' => true ),
			),
		) );

		register_rest_route( $ns, '/bricks/get-page', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_page' ),
			'permission_callback' => array( $this, 'can_edit_posts' ),
			'args'                => array(
				'id' => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
			),
		) );

		register_rest_route( $ns, '/bricks/elements', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'list_elements' ),
			'permission_callback' => array( $this, 'can_edit_posts' ),
			'args'                => array(),
		) );
	}

	public function can_edit_posts() {
		return current_user_can( 'edit_posts' );
	}

	// ------------------------------------------------------------------
	// save-page
	// ------------------------------------------------------------------

	public function save_page( WP_REST_Request $request ) {
		$result = $this->do_save( array(
			'id'        => $request->get_param( 'id' ),
			'title'     => $request->get_param( 'title' ),
			'post_type' => $request->get_param( 'post_type' ),
			'status'    => $request->get_param( 'status' ),
			'elements'  => $request->get_param( 'elements' ),
		) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * REST-independent save core (unit-testable).
	 *
	 * @param array $args id|title|post_type|status|elements.
	 * @return array|WP_Error { id, url, edit_url, warnings } on success.
	 */
	public function do_save( array $args ) {
		$check = $this->bricks_check();
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$post_type = $args['post_type'] ?? 'page';
		$status    = $args['status'] ?? 'publish';
		$id        = absint( $args['id'] ?? 0 );
		$title     = $args['title'] ?? '';
		$elements  = $args['elements'] ?? null;
		$warnings  = array();

		if ( empty( $elements ) || ! is_array( $elements ) ) {
			return new WP_Error( 'invalid_input', __( '`elements` is required: a non-empty flat array of {id, name, parent, children, settings} objects.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 400 ) ) );
		}

		$valid = $this->validate_elements( $elements, $warnings );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		// A CLI/automated theme activation never fires after_switch_theme, so
		// Bricks' defaults (postTypes => [page]) are missing and pages count as
		// UNSUPPORTED: no builder button, and in file mode the per-post CSS is
		// generated but never enqueued. When the settings option is absent
		// entirely, repair with Bricks' own idempotent seeder (add_option —
		// never overwrites). When the option exists without postTypes, do NOT
		// touch the user's settings object — warn instead.
		if ( is_callable( array( '\Bricks\Database', 'get_setting' ) )
			&& false === \Bricks\Database::get_setting( 'postTypes' ) ) {
			$option_key = $this->db_key( 'BRICKS_DB_GLOBAL_SETTINGS', 'bricks_global_settings' );
			if ( false === get_option( $option_key ) && is_callable( array( '\Bricks\Admin', 'set_default_settings' ) ) ) {
				\Bricks\Admin::set_default_settings();
				$warnings[] = __( 'Bricks default settings were missing (theme was activated without a wp-admin visit), which disables the builder and CSS enqueueing for every post type. Initialized them with Bricks\' own defaults.', 'vibe-ai' );
			} else {
				$warnings[] = __( 'Bricks has no supported post types configured, so "Edit with Bricks" is unavailable and (in external-file CSS mode) per-post CSS will not be enqueued. Enable post types under Bricks → Settings → Post Types.', 'vibe-ai' );
			}
		}

		// Bricks only offers the builder for enabled post types (default: page
		// only). Content still renders, but the user couldn't open it in Bricks.
		$enabled = $this->bricks_setting( 'postTypes', array( 'page' ) );
		if ( is_array( $enabled ) && ! in_array( $post_type, $enabled, true ) ) {
			$warnings[] = sprintf(
				/* translators: 1: post type slug 2: comma-separated enabled types */
				__( 'Bricks is not enabled for the "%1$s" post type (enabled: %2$s), so the page will render but "Edit with Bricks" will not appear for it. Enable the type under Bricks → Settings → Post Types if the user wants to edit it visually.', 'vibe-ai' ),
				$post_type,
				implode( ', ', $enabled )
			);
		}

		$action = $id ? 'update' : 'create';
		$caps   = $this->check_post_caps( $post_type, $action, $id, $status );
		if ( is_wp_error( $caps ) ) {
			return $caps;
		}

		if ( $id && ! get_post( $id ) ) {
			return new WP_Error( 'not_found', sprintf(
				/* translators: %d: post ID */
				__( 'Post #%d does not exist.', 'vibe-ai' ),
				$id
			), WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 404 ) ) );
		}

		$created_id = 0;
		if ( ! $id ) {
			$id = wp_insert_post( array(
				'post_type'   => $post_type,
				'post_status' => 'draft',
				'post_title'  => $title ?: __( 'Untitled', 'vibe-ai' ),
			), true );
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			$created_id = $id;
		}

		// Bricks' update_post_metadata filter returns false (a silent no-op
		// write) for users its builder-access check rejects — pre-check so the
		// caller gets a real 403 instead of a page that never updates.
		if ( is_callable( array( '\Bricks\Capabilities', 'current_user_can_use_builder' ) )
			&& ! \Bricks\Capabilities::current_user_can_use_builder( $id ) ) {
			if ( $created_id > 0 ) {
				wp_delete_post( $created_id, true );
			}
			return new WP_Error( 'bricks_no_builder_access', __( 'The connected account does not have Bricks builder access for this post, so Bricks blocks the layout write. Connect as an administrator or grant the role builder access in Bricks → Settings → Builder Access.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'capability_role', false, array( 'status' => 403 ) ) );
		}

		$result = $this->write_layout( $id, $elements, $status, $title, $created_id, $warnings );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'id'       => $id,
			'url'      => get_permalink( $id ),
			'edit_url' => $this->builder_edit_url( $id ),
			'warnings' => $warnings,
		);
	}

	/**
	 * The verified write composition. Order is load-bearing: content meta,
	 * then editor mode, then wp_update_post — Bricks' save_post CSS hook
	 * bails while editor mode is still `wordpress`.
	 */
	private function write_layout( $id, array $elements, $status, $title, $created_id, array &$warnings ) {
		// Bricks registers element controls (the settings -> CSS maps) on the
		// 'wp' hook, which never fires in REST. Load them BEFORE any write:
		// wp_update_post below fires Bricks' own save_post CSS generation, and
		// without controls it writes a file with every custom style missing.
		if ( is_callable( array( '\Bricks\Elements', 'load_elements' ) ) ) {
			\Bricks\Elements::load_elements();
		}

		// Bricks' own ajax pipeline hands the security check wp_slash'd input
		// (its decode() re-slashes after json_decode) and writes the slashed
		// result; update_metadata unslashes on the way in.
		$slashed = wp_slash( $elements );

		if ( is_callable( array( '\Bricks\Helpers', 'security_check_elements_before_save' ) ) ) {
			$checked = \Bricks\Helpers::security_check_elements_before_save( $slashed, $id, 'content' );
			// Restricted users get the OLD elements back on a count mismatch —
			// [] on a new page. Writing that would silently produce an empty
			// page (and flip editor mode off), so fail loudly instead.
			if ( ! is_array( $checked ) || count( $checked ) !== count( $slashed ) ) {
				if ( $created_id > 0 ) {
					wp_delete_post( $created_id, true );
				}
				return new WP_Error( 'bricks_security_rejected', __( 'Bricks\' element security check rejected this save for the connected account (it strips or reverts elements for users without code-execution permission). Remove code/svg/query-editor settings and {echo:...} dynamic tags, or connect as an administrator.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'capability_role', false, array( 'status' => 403 ) ) );
			}
			if ( $checked !== $slashed ) {
				$warnings[] = __( 'Bricks\' security check modified the layout before saving (it strips code-execution settings, query-editor values, and {echo:...} dynamic tags for accounts without code permission). Fetch the page back with get-page to see what was stored.', 'vibe-ai' );
			}
			$slashed = $checked;
		}

		update_post_meta( $id, $this->db_key( 'BRICKS_DB_PAGE_CONTENT', '_bricks_page_content_2' ), $slashed );

		// Verify the write landed (the metadata filter can still no-op it)
		// BEFORE flipping editor mode — Bricks' own recompute would write
		// `wordpress` here and silently disable rendering.
		$stored = get_post_meta( $id, $this->db_key( 'BRICKS_DB_PAGE_CONTENT', '_bricks_page_content_2' ), true );
		if ( empty( $stored ) || ! is_array( $stored ) ) {
			if ( $created_id > 0 ) {
				wp_delete_post( $created_id, true );
			}
			return new WP_Error( 'bricks_write_blocked', __( 'Bricks blocked the layout meta write (no data was stored). This usually means the connected account lacks builder access for the post type.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'capability_role', false, array( 'status' => 403 ) ) );
		}

		update_post_meta( $id, $this->db_key( 'BRICKS_DB_EDITOR_MODE', '_bricks_editor_mode' ), 'bricks' );

		$update = array( 'ID' => $id, 'post_status' => $status );
		if ( $created_id === 0 && $title ) {
			$update['post_title'] = $title;
		}
		wp_update_post( $update );

		// File mode: wp_update_post above fired Bricks' own save_post CSS
		// generation (correct now that elements are loaded — see the top of
		// this method). Do NOT call generate_post_css_file again here: Bricks
		// dedupes per element id within a request, so a second pass generates
		// EMPTY css and deletes the freshly written file. Just verify.
		if ( 'file' === $this->bricks_setting( 'cssLoading', false ) ) {
			$upload = wp_upload_dir();
			$file   = trailingslashit( $upload['basedir'] ) . 'bricks/css/post-' . $id . '.min.css';
			if ( ! file_exists( $file ) ) {
				$warnings[] = __( 'Bricks runs in external-file CSS mode but no per-post CSS file was generated for this save. The page may render unstyled; re-saving once in the Bricks builder will regenerate it.', 'vibe-ai' );
			}
		}

		return true;
	}

	/**
	 * Rejects arrays Bricks would store and then silently break on: missing
	 * id/name, duplicate or non-string ids, dangling parents, unregistered
	 * element names. Repairs the shapes Bricks itself always writes
	 * (settings key present, parent 0 at root).
	 */
	private function validate_elements( array &$elements, array &$warnings ) {
		$ids = array();
		foreach ( $elements as $i => $el ) {
			if ( ! is_array( $el ) ) {
				return $this->invalid( sprintf( 'elements[%d] is not an object.', $i ) );
			}
			$el_id = $el['id'] ?? null;
			if ( ! is_string( $el_id ) || '' === $el_id ) {
				return $this->invalid( sprintf( 'elements[%d] needs a string `id` (6 lowercase hex chars, e.g. "a3f9c2" — include at least one letter).', $i ) );
			}
			if ( isset( $ids[ $el_id ] ) ) {
				return $this->invalid( sprintf( 'Duplicate element id "%s" — ids must be unique within the page.', $el_id ) );
			}
			$ids[ $el_id ] = true;
			if ( empty( $el['name'] ) || ! is_string( $el['name'] ) ) {
				return $this->invalid( sprintf( 'Element "%s" needs a `name` (the element slug, e.g. "section", "heading").', $el_id ) );
			}
		}

		$registry = class_exists( '\Bricks\Elements' ) ? \Bricks\Elements::$elements : array();
		foreach ( $elements as $i => &$el ) {
			if ( $registry && ! isset( $registry[ $el['name'] ] ) ) {
				return new WP_Error( 'unknown_element', sprintf(
					/* translators: 1: element id 2: element name */
					__( 'Element "%1$s" uses name "%2$s", which is not registered on this site — Bricks would render it as nothing. GET /wpvibe/v1/bricks/elements lists the valid names.', 'vibe-ai' ),
					$el['id'],
					$el['name']
				), WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 400 ) ) );
			}
			if ( ! array_key_exists( 'parent', $el ) ) {
				$el['parent'] = 0;
			}
			if ( $el['parent'] && ! isset( $ids[ $el['parent'] ] ) ) {
				return $this->invalid( sprintf( 'Element "%s" references parent "%s", which is not in `elements` — Bricks would drop it from the layout silently.', $el['id'], $el['parent'] ) );
			}
			if ( isset( $el['children'] ) ) {
				if ( ! is_array( $el['children'] ) ) {
					return $this->invalid( sprintf( 'Element "%s" has a non-array `children` value.', $el['id'] ) );
				}
				foreach ( $el['children'] as $child_id ) {
					if ( ! isset( $ids[ $child_id ] ) ) {
						return $this->invalid( sprintf( 'Element "%s" lists child "%s", which is not in `elements`.', $el['id'], (string) $child_id ) );
					}
				}
			}
			if ( ! array_key_exists( 'settings', $el ) || ! is_array( $el['settings'] ) ) {
				$el['settings'] = array();
			}
			// Components need ids present in the bricks_components option; a
			// stray cid makes the front end skip the element entirely.
			if ( isset( $el['cid'] ) ) {
				unset( $el['cid'] );
				$warnings[] = sprintf(
					/* translators: %s: element id */
					__( 'Removed `cid` from element "%s" — component references are not supported through this endpoint.', 'vibe-ai' ),
					$el['id']
				);
			}
		}
		unset( $el );
		return true;
	}

	// ------------------------------------------------------------------
	// get-page (read-modify-write source)
	// ------------------------------------------------------------------

	public function get_page( WP_REST_Request $request ) {
		$check = $this->bricks_check();
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$id = absint( $request->get_param( 'id' ) );
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'forbidden', sprintf(
				/* translators: %d: post ID */
				__( 'You do not have permission to edit post #%d.', 'vibe-ai' ),
				$id
			), WPVibe_Error_Contract::data( 'capability_cpt_mapping', false, array( 'status' => 403 ) ) );
		}
		$post = get_post( $id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', sprintf(
				/* translators: %d: post ID */
				__( 'Post #%d does not exist.', 'vibe-ai' ),
				$id
			), WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 404 ) ) );
		}

		// Direct meta read on purpose: Helpers::get_bricks_data() prefers an
		// active TEMPLATE's elements, which would hand back the wrong layout
		// for read-modify-write.
		$elements = get_post_meta( $id, $this->db_key( 'BRICKS_DB_PAGE_CONTENT', '_bricks_page_content_2' ), true );
		if ( empty( $elements ) || ! is_array( $elements ) ) {
			return new WP_Error( 'bricks_no_content', sprintf(
				/* translators: %d: post ID */
				__( 'Post #%d has no Bricks layout. Build it with POST /wpvibe/v1/bricks/save-page.', 'vibe-ai' ),
				$id
			), WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 404 ) ) );
		}

		return rest_ensure_response( array(
			'id'          => $id,
			'title'       => get_the_title( $post ),
			'status'      => $post->post_status ?? '',
			'editor_mode' => get_post_meta( $id, $this->db_key( 'BRICKS_DB_EDITOR_MODE', '_bricks_editor_mode' ), true ),
			'elements'    => array_values( $elements ),
			'edit_url'    => $this->builder_edit_url( $id ),
		) );
	}

	// ------------------------------------------------------------------
	// Discovery
	// ------------------------------------------------------------------

	public function list_elements() {
		$check = $this->bricks_check();
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$out = array();
		if ( class_exists( '\Bricks\Elements' ) ) {
			foreach ( \Bricks\Elements::$elements as $name => $el ) {
				// Registry labels are '' for core elements (only filled when
				// registered without a name) — derive a readable one.
				$label = ! empty( $el['label'] ) ? $el['label'] : ucwords( str_replace( '-', ' ', $name ) );
				$row   = array( 'name' => $name, 'label' => $label );
				if ( isset( $el['class'] ) && class_exists( $el['class'] ) ) {
					$vars            = get_class_vars( $el['class'] );
					$row['nestable'] = ! empty( $vars['nestable'] );
				}
				$out[] = $row;
			}
		}
		usort( $out, function ( $a, $b ) {
			return strcmp( $a['name'], $b['name'] );
		} );
		return rest_ensure_response( array( 'elements' => $out ) );
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

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
		} elseif ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'forbidden', sprintf(
				/* translators: %d: post ID */
				__( 'You do not have permission to edit post #%d.', 'vibe-ai' ),
				$post_id
			), WPVibe_Error_Contract::data( 'capability_cpt_mapping', false, array( 'status' => 403 ) ) );
		}
		if ( in_array( $new_status, array( 'publish', 'private', 'future' ), true ) && ! current_user_can( $pt_obj->cap->publish_posts ) ) {
			return new WP_Error( 'forbidden_publish', sprintf(
				/* translators: %s: post type label */
				__( 'You do not have permission to publish %s.', 'vibe-ai' ),
				$pt_obj->labels->name
			), WPVibe_Error_Contract::data( 'capability_role', false, array( 'status' => 403 ) ) );
		}
		return true;
	}

	private function invalid( $message ) {
		return new WP_Error( 'invalid_input', $message, WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 400 ) ) );
	}

	private function db_key( $constant, $fallback ) {
		return defined( $constant ) ? constant( $constant ) : $fallback;
	}

	private function bricks_setting( $key, $default_value ) {
		if ( is_callable( array( '\Bricks\Database', 'get_setting' ) ) ) {
			$value = \Bricks\Database::get_setting( $key, $default_value );
			return false === $value ? $default_value : $value;
		}
		return $default_value;
	}

	private function builder_edit_url( $id ) {
		if ( is_callable( array( '\Bricks\Helpers', 'get_builder_edit_link' ) ) ) {
			return \Bricks\Helpers::get_builder_edit_link( $id );
		}
		return add_query_arg( 'bricks', 'run', get_permalink( $id ) );
	}

	protected function bricks_check() {
		if ( ! class_exists( '\Bricks\Database' ) ) {
			return new WP_Error( 'bricks_inactive', __( 'The Bricks theme is not active on this site.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'not_supported', false, array( 'status' => 404 ) ) );
		}
		return true;
	}
}
