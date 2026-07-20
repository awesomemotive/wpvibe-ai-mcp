<?php
/**
 * Beaver Builder REST endpoints.
 *
 * BB stores a layout as postmeta `_fl_builder_data`: a map of node_id => stdClass
 * (row -> column-group -> column -> module). No generic write path can produce
 * that object format (JSON meta writes store associative arrays, which BB's
 * clean_layout_data() drops, rendering an empty page), and BB exposes no layout
 * REST API of its own — so this endpoint is the only way an AI caller can build
 * a BB layout.
 *
 * The layout write is composed from FLBuilderModel's own methods rather than raw
 * update_post_meta, so we follow BB's serialize/slash/normalize pipeline instead
 * of freezing to today's meta format:
 *   verify_settings (kses gate) -> update_layout_data(draft) -> save_layout(publish)
 *
 * Callbacks gate on BB being active and return not_supported (404) otherwise.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPVibe_Beaver {

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

		register_rest_route( $ns, '/beaver/save-page', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'save_page' ),
			'permission_callback' => array( $this, 'can_edit_posts' ),
			'args'                => array(
				'id'                   => array( 'type' => 'integer', 'required' => false, 'sanitize_callback' => 'absint' ),
				'title'                => array( 'type' => 'string',  'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
				'post_type'            => array( 'type' => 'string',  'default'  => 'page', 'sanitize_callback' => 'sanitize_key' ),
				'status'               => array( 'type' => 'string',  'default'  => 'publish', 'sanitize_callback' => 'sanitize_key' ),
				'data'                 => array( 'type' => 'object',  'required' => true ),
				'theme_layout_type'    => array( 'type' => 'string',  'required' => false, 'sanitize_callback' => 'sanitize_key' ),
				'theme_layout_locations' => array( 'type' => 'array', 'required' => false ),
				'theme_layout_settings' => array( 'type' => 'object', 'required' => false ),
			),
		) );

		register_rest_route( $ns, '/beaver/modules', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'list_modules' ),
			'permission_callback' => array( $this, 'can_edit_posts' ),
			'args'                => array(),
		) );

		register_rest_route( $ns, '/beaver/schema', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_schema' ),
			'permission_callback' => array( $this, 'can_edit_posts' ),
			'args'                => array(
				'slug' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_key' ),
			),
		) );
	}

	public function can_edit_posts() {
		return current_user_can( 'edit_posts' );
	}

	// ------------------------------------------------------------------
	// save-page
	// ------------------------------------------------------------------

	public function save_page( WP_REST_Request $request ) {
		$check = $this->fl_builder_check();
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$post_type = $request->get_param( 'post_type' );
		$status    = $request->get_param( 'status' );
		$id        = $request->get_param( 'id' );
		$data      = $request->get_param( 'data' );

		if ( empty( $data ) || ! is_array( $data ) ) {
			return new WP_Error( 'invalid_input', __( 'The `data` node map is required and must be an object.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 400 ) ) );
		}

		// BB only renders layouts for post types enabled in its settings; writing to
		// a disabled type "succeeds" but renders a blank page. Fail loudly instead.
		$enabled = FLBuilderModel::get_post_types();
		if ( ! in_array( $post_type, $enabled, true ) ) {
			return new WP_Error( 'beaver_post_type_disabled', sprintf(
				/* translators: 1: post type slug 2: comma-separated enabled types */
				__( 'Beaver Builder is not enabled for the "%1$s" post type, so a layout saved to it would not render. Enable it under Beaver Builder → Settings → Post Types, or target one of: %2$s.', 'vibe-ai' ),
				$post_type,
				implode( ', ', $enabled )
			), WPVibe_Error_Contract::data( 'not_supported', false, array( 'status' => 400 ) ) );
		}

		$action    = $id ? 'update' : 'create';
		$caps      = $this->check_post_caps( $post_type, $action, $id, $status );
		if ( is_wp_error( $caps ) ) {
			return $caps;
		}

		// Security gate: replicate BB's own save_settings() kses behavior. Users
		// without unfiltered_html get every node's settings verified; a save that
		// would introduce unsafe HTML is rejected rather than sanitized silently,
		// because the html module renders its `html` setting with a raw echo.
		// (The code-field strip runs below, on the decoded object tree.)
		$restricted = ! FLBuilderModel::user_has_unfiltered_html();
		if ( $restricted ) {
			$unsafe = $this->find_unsafe_node( $data );
			if ( null !== $unsafe ) {
				return new WP_Error( 'forbidden_html', sprintf(
					/* translators: %s: node id */
					__( 'Node %s contains HTML your account is not permitted to save (missing the unfiltered_html capability). Remove scripts/unsafe markup, or have an administrator save it.', 'vibe-ai' ),
					$unsafe
				), WPVibe_Error_Contract::data( 'capability_role', false, array( 'status' => 403 ) ) );
			}
		}

		// BB's native shape is stdClass ONLY at the node and settings levels;
		// compound fields inside settings (typography, border, radius, repeaters)
		// must stay associative arrays or BB's CSS generator fatals on
		// "Cannot use object of type stdClass as array". So: decode fully
		// associative, then objectify exactly the two levels BB expects.
		$json  = wp_json_encode( $data );
		$draft = json_decode( $json, true );
		if ( ! is_array( $draft ) || JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error( 'invalid_input', __( 'The `data` node map is not valid JSON.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 400 ) ) );
		}
		foreach ( $draft as $key => $node ) {
			if ( is_array( $node ) ) {
				$obj           = (object) $node;
				$obj->settings = (object) ( is_array( $node['settings'] ?? null ) ? $node['settings'] : array() );
				$draft[ $key ] = $obj;
			}
		}
		$warnings = array();

		// Strip executable code fields (bb_js_code/code/js/css) for users without
		// unfiltered_html: they render as live front-end JS/CSS (stored-XSS vector).
		// Runs on the decoded object tree because $data arrives as associative arrays.
		if ( $restricted ) {
			$this->strip_code_fields( $draft, $warnings );
		}

		// A typo'd module slug or dangling parent id saves cleanly through BB's
		// pipeline and then renders as silent blank space — fail loudly instead.
		$valid = $this->validate_tree( $draft, $warnings );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$created_id = 0;
		if ( ! $id ) {
			$id = wp_insert_post( array(
				'post_type'   => $post_type,
				'post_status' => 'draft',
				'post_title'  => $request->get_param( 'title' ) ?: __( 'Untitled', 'vibe-ai' ),
			), true );
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			$created_id = $id;
		} elseif ( $request->get_param( 'title' ) ) {
			wp_update_post( array( 'ID' => $id, 'post_title' => $request->get_param( 'title' ) ) );
		}

		$result = $this->write_layout( $id, $draft, $status, $created_id, $warnings );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Themer layout metadata (fl-theme-layout CPT reuses _fl_builder_data).
		if ( 'fl-theme-layout' === $post_type ) {
			$this->apply_theme_layout_meta( $id, $request, $warnings );
		}

		return rest_ensure_response( array(
			'id'       => $id,
			'url'      => get_permalink( $id ),
			'edit_url' => admin_url( 'post.php?post=' . $id . '&action=edit' ),
			'warnings' => $warnings,
		) );
	}

	/**
	 * The verified save composition. Wrapped so a fatal anywhere in BB's pipeline
	 * cleans up a just-created orphan post instead of leaving a broken page.
	 */
	private function write_layout( $id, array $draft, $status, $created_id, array &$warnings ) {
		try {
			FLBuilderModel::update_layout_data( $draft, 'draft', $id );
			FLBuilderModel::set_post_id( $id );
			FLBuilderModel::save_layout( 'publish' === $status );
			FLBuilderModel::reset_post_id();
			return true;
		} catch ( \Throwable $e ) {
			FLBuilderModel::reset_post_id();
			if ( $created_id > 0 ) {
				wp_delete_post( $created_id, true );
			}
			return new WP_Error( 'beaver_save_fatal', sprintf(
				/* translators: 1: exception message 2: cleanup note */
				__( 'Beaver Builder threw during save: %1$s. %2$s', 'vibe-ai' ),
				$e->getMessage(),
				$created_id > 0 ? __( 'The partially created page was deleted; fix the data and retry.', 'vibe-ai' ) : __( 'The existing page kept its previous saved data.', 'vibe-ai' )
			), WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 400 ) ) );
		}
	}

	/** First node whose settings BB's kses gate would reject, or null if all safe. */
	private function find_unsafe_node( array $data ) {
		foreach ( $data as $node_id => $node ) {
			$settings = is_object( $node ) ? ( $node->settings ?? null ) : ( $node['settings'] ?? null );
			if ( null === $settings ) {
				continue;
			}
			if ( true !== FLBuilderModel::verify_settings( (object) $settings ) ) {
				return (string) $node_id;
			}
		}
		return null;
	}

	/**
	 * Strip executable code fields for users without unfiltered_html. Every
	 * such field is gated on unfiltered_html, matching BB core's own save path
	 * (save_settings strips bb_js_code, save_global_settings strips js, both on
	 * unfiltered_html, never on delete_others_posts). A multisite Editor has
	 * delete_others_posts but NOT unfiltered_html, so gating any of these on the
	 * former would let them persist executable JS/CSS past the multisite gate.
	 * Operates on the decoded object tree ($draft): every node is a stdClass with
	 * a stdClass `settings` (guaranteed by the coercion above), so mutating
	 * $node->settings mutates the tree in place.
	 */
	private function strip_code_fields( array $draft, array &$warnings ) {
		$stripped = array();
		foreach ( $draft as $node_id => $node ) {
			if ( ! is_object( $node ) || ! isset( $node->settings ) || ! is_object( $node->settings ) ) {
				continue;
			}
			$has_code = isset( $node->settings->bb_js_code ) || isset( $node->settings->code )
				|| isset( $node->settings->js ) || isset( $node->settings->css );
			if ( $has_code ) {
				unset( $node->settings->bb_js_code, $node->settings->code, $node->settings->js, $node->settings->css );
				$stripped[] = (string) $node_id;
			}
		}
		if ( $stripped ) {
			$warnings[] = sprintf(
				/* translators: %s: comma-separated node ids */
				__( 'Executable code fields (custom JS/CSS) were removed from node(s) %s because the connected account lacks the capability to save code.', 'vibe-ai' ),
				implode( ', ', array_unique( $stripped ) )
			);
		}
	}

	/**
	 * Reject nodes BB's pipeline would accept and then silently never render:
	 * dangling parent ids and unregistered module slugs. Non-column module
	 * parents render blank too, but BB tolerates some nesting, so only warn.
	 */
	private function validate_tree( array $draft, array &$warnings ) {
		FLBuilderModel::load_modules();
		foreach ( $draft as $node_id => $node ) {
			if ( ! is_object( $node ) ) {
				continue;
			}
			$parent = $node->parent ?? null;
			if ( null !== $parent && '' !== $parent && ! isset( $draft[ $parent ] ) ) {
				return new WP_Error( 'invalid_input', sprintf(
					/* translators: 1: node id 2: missing parent id */
					__( 'Node %1$s references parent "%2$s", which is not in `data` — Beaver Builder would drop it from the layout silently. Fix the parent id or add the missing node.', 'vibe-ai' ),
					$node_id,
					$parent
				), WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 400 ) ) );
			}
			if ( 'module' === ( $node->type ?? '' ) ) {
				$slug = isset( $node->settings->type ) ? (string) $node->settings->type : '';
				if ( ! isset( FLBuilderModel::$modules[ $slug ] ) ) {
					return new WP_Error( 'unknown_module', sprintf(
						/* translators: 1: node id 2: module slug */
						__( 'Node %1$s uses module type "%2$s", which is not registered on this site — it would render as empty space. GET /wpvibe/v1/beaver/modules lists the valid slugs.', 'vibe-ai' ),
						$node_id,
						$slug
					), WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 400 ) ) );
				}
				if ( null !== $parent && isset( $draft[ $parent ] ) && 'column' !== ( $draft[ $parent ]->type ?? '' ) ) {
					$warnings[] = sprintf(
						/* translators: 1: module node id 2: parent node type */
						__( 'Module %1$s is parented to a %2$s node; Beaver Builder only renders modules inside columns.', 'vibe-ai' ),
						$node_id,
						(string) ( $draft[ $parent ]->type ?? 'unknown' )
					);
				}
			}
		}
		return true;
	}

	private function apply_theme_layout_meta( $id, WP_REST_Request $request, array &$warnings ) {
		$type = $request->get_param( 'theme_layout_type' );
		if ( $type ) {
			update_post_meta( $id, '_fl_theme_layout_type', sanitize_key( $type ) );
		}

		// Location rules decide WHERE a Themer layout renders. BB matches the
		// current page against postmeta `_fl_theme_builder_locations` — an array
		// of location strings (e.g. "general:site" site-wide, "archive:post",
		// "general:404"). NOT `_fl_theme_layout_settings` (that is display config).
		// The value MUST be a real array: a scalar/serialized string fatals BB's
		// in_array() on every front-end request.
		$locations = $request->get_param( 'theme_layout_locations' );
		$clean     = array();
		if ( is_array( $locations ) ) {
			foreach ( $locations as $loc ) {
				if ( is_string( $loc ) && '' !== $loc ) {
					$clean[] = sanitize_text_field( $loc );
				}
			}
			if ( $clean ) {
				// Prefer BB's own setter over a raw meta write so we track its
				// storage format; fall back to the meta key it currently uses.
				if ( class_exists( 'FLThemeBuilderRulesLocation' ) && method_exists( 'FLThemeBuilderRulesLocation', 'update_saved' ) ) {
					FLThemeBuilderRulesLocation::update_saved( $id, $clean );
				} else {
					update_post_meta( $id, '_fl_theme_builder_locations', $clean );
				}
			}
		}

		$has_locations = ! empty( $clean );
		if ( ! $has_locations && in_array( $type, array( 'header', 'footer', 'singular', 'archive', '404', 'part' ), true ) ) {
			$warnings[] = __( 'No theme_layout_locations were provided, so this Themer layout will not render anywhere until location rules are set. For a site-wide header/footer pass ["general:site"]; set finer rules in Beaver Themer.', 'vibe-ai' );
		}

		// A theme that does not declare Themer header/footer support silently drops
		// the layout regardless of location rules — surface that instead of a blank.
		if ( in_array( $type, array( 'header', 'footer' ), true ) ) {
			$support_key = 'header' === $type ? 'fl-theme-builder-headers' : 'fl-theme-builder-footers';
			if ( ! current_theme_supports( $support_key ) ) {
				$warnings[] = sprintf(
					/* translators: 1: theme support flag 2: layout type */
					__( 'The active theme does not declare "%1$s" support, so this %2$s will not render on the front end. Use a Themer-compatible theme (Beaver Builder Theme, Astra, GeneratePress, Kadence, etc.).', 'vibe-ai' ),
					$support_key,
					$type
				);
			}
		}

		// Optional display config (width/spacing) — distinct from location rules.
		$settings = $request->get_param( 'theme_layout_settings' );
		if ( is_array( $settings ) || is_object( $settings ) ) {
			update_post_meta( $id, '_fl_theme_layout_settings', (object) $settings );
		}
	}

	// ------------------------------------------------------------------
	// Discovery
	// ------------------------------------------------------------------

	public function list_modules() {
		$check = $this->fl_builder_check();
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		FLBuilderModel::load_modules();
		$out = array();
		foreach ( FLBuilderModel::$modules as $slug => $module ) {
			$out[] = array( 'slug' => $slug, 'name' => $module->name ?? $slug );
		}
		return rest_ensure_response( array( 'modules' => $out ) );
	}

	public function get_schema( WP_REST_Request $request ) {
		$check = $this->fl_builder_check();
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		FLBuilderModel::load_modules();
		$slug = $request->get_param( 'slug' );
		if ( ! isset( FLBuilderModel::$modules[ $slug ] ) ) {
			return new WP_Error( 'unknown_module', sprintf(
				/* translators: %s: module slug */
				__( 'Unknown Beaver Builder module: %s', 'vibe-ai' ),
				$slug
			), WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 404 ) ) );
		}
		return rest_ensure_response( array(
			'slug'     => $slug,
			'defaults' => FLBuilderModel::get_module_defaults( $slug ),
		) );
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Route-level permission_callback only confirms edit_posts. This enforces
	 * per-post-type create/edit/publish caps so a Contributor can't create a
	 * published page through the endpoint.
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
		} elseif ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'forbidden', sprintf(
				/* translators: %d: post ID */
				__( 'You do not have permission to edit post #%d.', 'vibe-ai' ),
				$post_id
			), WPVibe_Error_Contract::data( 'capability_cpt_mapping', false, array( 'status' => 403 ) ) );
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

	private function fl_builder_check() {
		if ( ! class_exists( 'FLBuilderModel' ) ) {
			return new WP_Error( 'fl_builder_inactive', __( 'Beaver Builder is not active on this site.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'not_supported', false, array( 'status' => 404 ) ) );
		}
		return true;
	}
}
