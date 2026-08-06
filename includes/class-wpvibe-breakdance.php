<?php
/**
 * Breakdance REST endpoints.
 *
 * Breakdance stores a layout as postmeta `_breakdance_data`: a JSON envelope
 * whose `tree_json_string` value is itself a JSON string of the element tree,
 * written through Breakdance's own encoder (wp_slash + wp_json_encode). No
 * generic write path can produce that double-encoded format — CLI/REST meta
 * writes land as PHP-serialized arrays that Breakdance's decoder rejects — and
 * a raw tree overwrite leaves the on-disk CSS cache stale (missing cache keys
 * regenerate lazily; stale keys do not). Breakdance exposes no REST/abilities
 * write surface of its own (its builder saves over admin-ajax), so this
 * endpoint is the only way an AI caller can build a Breakdance layout.
 *
 * The save composition replicates Breakdance\Data\save_document() rather than
 * calling it: since 2.8.x it takes 10 positional args and unconditionally
 * overwrites futurelayer meta we must leave alone. Its three effective steps
 * are set_meta -> wp_update_post (revision + modified bump) -> generateCacheForPost,
 * then the breakdance_after_save_document action (whose core listener runs
 * clean_post_cache).
 *
 * Callbacks gate on Breakdance being active and return not_supported (404)
 * otherwise. Writes additionally require Breakdance builder permission
 * (hasMinimumPermission('edit')) because element properties are a code-execution
 * surface (PHP Code Block / dynamic shortcodes eval at render); do NOT weaken
 * check_builder_permission() to a bare WP cap.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPVibe_Breakdance {

	private static $instance = null;

	// Gates the builder UI upstream (BREAKDANCE_BANNED_POST_TYPES); saving to
	// these would create pages Breakdance refuses to open.
	const BANNED_POST_TYPES = array( 'product', 'attachment' );

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

		register_rest_route( $ns, '/breakdance/save-page', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'save_page' ),
			'permission_callback' => array( $this, 'can_edit_posts' ),
			'args'                => array(
				'id'               => array( 'type' => 'integer', 'required' => false, 'sanitize_callback' => 'absint' ),
				'title'            => array( 'type' => 'string',  'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
				'post_type'        => array( 'type' => 'string',  'default'  => 'page', 'sanitize_callback' => 'sanitize_key' ),
				'status'           => array( 'type' => 'string',  'default'  => 'publish', 'enum' => array( 'publish', 'draft', 'pending', 'private' ) ),
				'tree'             => array( 'type' => 'object',  'required' => false ),
				'tree_json_string' => array( 'type' => 'string',  'required' => false ),
				'template'         => array( 'type' => 'string',  'required' => false, 'enum' => array( 'blank_canvas', 'theme' ) ),
			),
		) );

		register_rest_route( $ns, '/breakdance/get-page', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_page' ),
			'permission_callback' => array( $this, 'can_edit_posts' ),
			'args'                => array(
				'id' => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
			),
		) );

		register_rest_route( $ns, '/breakdance/elements', array(
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
			'id'               => $request->get_param( 'id' ),
			'title'            => $request->get_param( 'title' ),
			'post_type'        => $request->get_param( 'post_type' ),
			'status'           => $request->get_param( 'status' ),
			'tree'             => $request->get_param( 'tree' ),
			'tree_json_string' => $request->get_param( 'tree_json_string' ),
			'template'         => $request->get_param( 'template' ),
		) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * REST-independent save core (unit-testable): validates, creates/updates the
	 * post, writes the tree through Breakdance's encoder, regenerates CSS.
	 *
	 * @param array $args id|title|post_type|status|tree|tree_json_string.
	 * @return array|WP_Error { id, url, edit_url, warnings } on success.
	 */
	public function do_save( array $args ) {
		$check = $this->breakdance_check();
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$perm = $this->check_builder_permission();
		if ( is_wp_error( $perm ) ) {
			return $perm;
		}

		$post_type = $args['post_type'] ?? 'page';
		$status    = $args['status'] ?? 'publish';
		$id        = absint( $args['id'] ?? 0 );
		$title     = $args['title'] ?? '';
		$warnings  = array();

		if ( in_array( $post_type, self::BANNED_POST_TYPES, true ) ) {
			return new WP_Error( 'breakdance_post_type_banned', sprintf(
				/* translators: %s: post type slug */
				__( 'Breakdance does not support the "%s" post type (its builder refuses to open it). Use a page or another supported type.', 'vibe-ai' ),
				$post_type
			), WPVibe_Error_Contract::data( 'not_supported', false, array( 'status' => 400 ) ) );
		}

		$tree = $this->normalize_tree_input( $args );
		if ( is_wp_error( $tree ) ) {
			return $tree;
		}

		$valid = $this->validate_tree( $tree, $warnings );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		// The builder's loader codec only accepts {root, _nextNodeId, status:
		// "exported"} (its own serializer always writes exactly that) — anything
		// else and the editor refuses the whole document with an io-ts error.
		$next = isset( $tree['_nextNodeId'] ) && is_numeric( $tree['_nextNodeId'] ) ? (int) $tree['_nextNodeId'] : 0;
		$tree['_nextNodeId'] = max( $next, $this->max_node_id( $tree['root'] ) + 1 );
		$tree['status']      = 'exported';

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

		// Breakdance's template_include renders full-bleed (no theme title/
		// container) only when the page uses its blank-canvas template.
		$template = $args['template'] ?? null;
		if ( 'blank_canvas' === $template ) {
			update_post_meta( $id, '_wp_page_template', 'breakdance_blank_canvas' );
		} elseif ( 'theme' === $template ) {
			delete_post_meta( $id, '_wp_page_template' );
		}

		$result = $this->write_tree( $id, $tree, $status, $title, $created_id, $warnings );
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
	 * The verified save composition (mirrors Breakdance\Data\save_document
	 * minus the metas we must not clobber). Wrapped so a fatal anywhere in
	 * Breakdance's pipeline cleans up a just-created orphan post instead of
	 * leaving a broken page.
	 */
	private function write_tree( $id, array $tree, $status, $title, $created_id, array &$warnings ) {
		try {
			\Breakdance\Data\set_meta( $id, $this->meta_prefix() . 'data', array(
				'tree_json_string' => wp_json_encode( $tree ),
			) );

			$update = array( 'ID' => $id, 'post_status' => $status );
			if ( $created_id === 0 && $title ) {
				$update['post_title'] = $title;
			}
			wp_update_post( $update );

			$cache = \Breakdance\Render\generateCacheForPost( $id );
			if ( array() === $cache ) {
				// Empty cache means Breakdance found nothing renderable; the page
				// will re-run generation on every view until the tree has content.
				$warnings[] = __( 'Breakdance generated no CSS for this save — the tree may have no renderable elements. Verify the page on its front-end URL.', 'vibe-ai' );
			}

			// Fires Breakdance's own listener (clean_post_cache) + third parties.
			do_action( 'breakdance_after_save_document', $id );

			return true;
		} catch ( \Throwable $e ) {
			if ( $created_id > 0 ) {
				wp_delete_post( $created_id, true );
			}
			return new WP_Error( 'breakdance_save_fatal', sprintf(
				/* translators: 1: exception message 2: cleanup note */
				__( 'Breakdance threw during save: %1$s. %2$s', 'vibe-ai' ),
				$e->getMessage(),
				$created_id > 0 ? __( 'The partially created page was deleted; fix the data and retry.', 'vibe-ai' ) : __( 'The existing page kept its previous saved data.', 'vibe-ai' )
			), WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 400 ) ) );
		}
	}

	/** Accepts `tree` (object) or `tree_json_string` (string); returns the decoded tree array. */
	private function normalize_tree_input( array $args ) {
		$tree = $args['tree'] ?? null;
		if ( null === $tree && isset( $args['tree_json_string'] ) && is_string( $args['tree_json_string'] ) ) {
			$tree = json_decode( $args['tree_json_string'], true );
			if ( ! is_array( $tree ) || JSON_ERROR_NONE !== json_last_error() ) {
				return new WP_Error( 'invalid_input', __( '`tree_json_string` is not valid JSON.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 400 ) ) );
			}
		}
		if ( empty( $tree ) || ! is_array( $tree ) ) {
			return new WP_Error( 'invalid_input', __( 'A `tree` object (or `tree_json_string`) is required. Minimum shape: {"root":{"id":1,"data":{"type":"root","properties":{}},"children":[...]}}.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 400 ) ) );
		}
		return $tree;
	}

	/**
	 * Rejects trees Breakdance would accept and then break on: is_valid_tree()
	 * only checks the root envelope, but the renderer reads every node's
	 * data.type (silently swapped to MissingElement when unregistered) and
	 * accesses data.properties unguarded (PHP error). Missing `properties` is
	 * repaired in place — the builder itself writes `[]` there.
	 */
	private function validate_tree( array &$tree, array &$warnings ) {
		$outer_valid = function_exists( '\Breakdance\Data\is_valid_tree' )
			? \Breakdance\Data\is_valid_tree( $tree )
			: ( isset( $tree['root'] ) && is_array( $tree['root'] )
				&& array_key_exists( 'id', $tree['root'] )
				&& array_key_exists( 'data', $tree['root'] )
				&& array_key_exists( 'children', $tree['root'] ) );
		if ( ! $outer_valid ) {
			return new WP_Error( 'invalid_input', __( 'The tree failed Breakdance validation: `root` must be an object with `id`, `data`, and `children` keys.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 400 ) ) );
		}

		$free_mode  = function_exists( '\Breakdance\Subscription\isFreeMode' ) && \Breakdance\Subscription\isFreeMode();
		$pro_used   = array();
		$repaired   = array();
		$node_check = $this->validate_node( $tree['root'], 'root', $free_mode, $pro_used, $repaired );
		if ( is_wp_error( $node_check ) ) {
			return $node_check;
		}

		if ( $repaired ) {
			$warnings[] = sprintf(
				/* translators: %s: comma-separated node ids */
				__( 'Node(s) %s were missing `data.properties`; an empty properties object was added (Breakdance requires the key).', 'vibe-ai' ),
				implode( ', ', array_unique( $repaired ) )
			);
		}
		if ( $pro_used ) {
			$warnings[] = sprintf(
				/* translators: %s: comma-separated element class names */
				__( 'This site runs Breakdance Free, but the tree uses Pro-only element(s): %s. They will render with an upgrade notice instead of real content until the site has a Pro license.', 'vibe-ai' ),
				implode( ', ', array_unique( $pro_used ) )
			);
		}
		return true;
	}

	private function validate_node( array &$node, $path, $free_mode, array &$pro_used, array &$repaired ) {
		if ( ! array_key_exists( 'id', $node ) || ! isset( $node['data'] ) || ! is_array( $node['data'] ) ) {
			return new WP_Error( 'invalid_input', sprintf(
				/* translators: %s: node path */
				__( 'Node at %s must have `id` and a `data` object.', 'vibe-ai' ),
				$path
			), WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 400 ) ) );
		}

		$type = $node['data']['type'] ?? null;
		if ( ! is_string( $type ) || '' === $type ) {
			return new WP_Error( 'invalid_input', sprintf(
				/* translators: %s: node path */
				__( 'Node at %s is missing `data.type` (the element PHP class name, e.g. "EssentialElements\\\\Heading").', 'vibe-ai' ),
				$path
			), WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 400 ) ) );
		}

		if ( ! array_key_exists( 'properties', $node['data'] ) ) {
			$node['data']['properties'] = array();
			$repaired[]                 = (string) $node['id'];
		}
		// Empty properties must encode as {} — the editor's codec rejects [].
		if ( is_array( $node['data']['properties'] ) && array() === $node['data']['properties'] ) {
			$node['data']['properties'] = new stdClass();
		}

		if ( 'root' !== $type ) {
			if ( ! class_exists( $type ) || ! is_subclass_of( $type, '\Breakdance\Elements\Element' ) ) {
				return new WP_Error( 'unknown_element', sprintf(
					/* translators: 1: node path 2: element class */
					__( 'Node at %1$s uses element type "%2$s", which is not registered on this site — Breakdance would render it as a "missing element" placeholder. Element types are PHP class names; GET /wpvibe/v1/breakdance/elements lists the valid ones.', 'vibe-ai' ),
					$path,
					$type
				), WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 400 ) ) );
			}
			if ( $free_mode && $this->is_pro_only( $type ) ) {
				$pro_used[] = $type;
			}
		}

		// The editor's node codec REQUIRES children (the front-end renderer alone
		// tolerates omission); [] is the correct leaf value.
		if ( ! isset( $node['children'] ) ) {
			$node['children'] = array();
		}
		if ( ! is_array( $node['children'] ) ) {
			return new WP_Error( 'invalid_input', sprintf(
				/* translators: %s: node path */
				__( 'Node at %s has a non-array `children` value.', 'vibe-ai' ),
				$path
			), WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 400 ) ) );
		}
		foreach ( $node['children'] as $i => &$child ) {
			if ( ! is_array( $child ) ) {
				return new WP_Error( 'invalid_input', sprintf(
					/* translators: %s: node path */
					__( 'Child %s is not an object.', 'vibe-ai' ),
					$path . '.children[' . $i . ']'
				), WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 400 ) ) );
			}
			$deep = $this->validate_node( $child, $path . '.children[' . $i . ']', $free_mode, $pro_used, $repaired );
			if ( is_wp_error( $deep ) ) {
				return $deep;
			}
		}
		unset( $child );
		return true;
	}

	private function max_node_id( array $node ) {
		$max = is_numeric( $node['id'] ?? null ) ? (int) $node['id'] : 0;
		foreach ( ( $node['children'] ?? array() ) as $child ) {
			if ( is_array( $child ) ) {
				$max = max( $max, $this->max_node_id( $child ) );
			}
		}
		return $max;
	}

	private function is_pro_only( $type ) {
		if ( ! is_callable( array( $type, 'settings' ) ) ) {
			return false;
		}
		$settings = $type::settings();
		return is_array( $settings ) && ! empty( $settings['proOnly'] );
	}

	// ------------------------------------------------------------------
	// get-page (read-modify-write source)
	// ------------------------------------------------------------------

	public function get_page( WP_REST_Request $request ) {
		$check = $this->breakdance_check();
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

		// A blank builder insert stores {"tree_json_string":""}, so key existence
		// is not "is a Breakdance page" — only a decodable non-empty tree is.
		$tree_json = \Breakdance\Data\get_meta( $id, $this->meta_prefix() . 'data', 'tree_json_string' );
		$tree      = is_string( $tree_json ) && '' !== $tree_json ? json_decode( $tree_json, true ) : null;
		if ( ! is_array( $tree ) ) {
			return new WP_Error( 'breakdance_no_tree', sprintf(
				/* translators: %d: post ID */
				__( 'Post #%d has no Breakdance layout (not a Breakdance page, or a blank one). Build it with POST /wpvibe/v1/breakdance/save-page.', 'vibe-ai' ),
				$id
			), WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 404 ) ) );
		}

		return rest_ensure_response( array(
			'id'       => $id,
			'title'    => get_the_title( $post ),
			'status'   => $post->post_status ?? '',
			// Full envelope (root + _nextNodeId/status/... siblings): edit and POST
			// back verbatim as `tree` so the builder's bookkeeping keys survive.
			'tree'     => $tree,
			'edit_url' => $this->builder_edit_url( $id ),
		) );
	}

	// ------------------------------------------------------------------
	// Discovery
	// ------------------------------------------------------------------

	public function list_elements() {
		$check = $this->breakdance_check();
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$free_mode = function_exists( '\Breakdance\Subscription\isFreeMode' ) && \Breakdance\Subscription\isFreeMode();
		$out       = array();
		foreach ( get_declared_classes() as $class ) {
			if ( ! is_subclass_of( $class, '\Breakdance\Elements\Element' ) ) {
				continue;
			}
			if ( 'EssentialElements\MissingElement' === $class ) {
				continue;
			}
			$out[] = array(
				'type'     => $class,
				'pro_only' => $this->is_pro_only( $class ),
			);
		}
		usort( $out, function ( $a, $b ) {
			return strcmp( $a['type'], $b['type'] );
		} );
		return rest_ensure_response( array(
			'free_mode' => $free_mode,
			'elements'  => $out,
		) );
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Breakdance element properties are a code-execution surface: the PHP Code
	 * Block and dynamic shortcodes run eval() during cache generation, and the
	 * Text templates emit their content unescaped. Breakdance itself defaults
	 * every non-administrator to "none" and gates its own save on
	 * hasMinimumPermission('edit'); this endpoint must enforce the same
	 * boundary, or an edit_posts-only user (e.g. a Contributor) could store
	 * code that runs during cache generation. Fails CLOSED when Breakdance's
	 * permission API is unavailable.
	 */
	private function check_builder_permission() {
		if ( ! function_exists( 'Breakdance\Permissions\hasMinimumPermission' )
			|| ! \Breakdance\Permissions\hasMinimumPermission( 'edit' ) ) {
			return new WP_Error( 'breakdance_no_builder_access', __( 'The connected account does not have Breakdance builder access, so the layout write is refused. Connect as an administrator, or grant the role Breakdance access under Breakdance, Settings, Permissions.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'capability_role', false, array( 'status' => 403 ) ) );
		}
		return true;
	}

	/**
	 * Route-level permission_callback only confirms edit_posts. This enforces
	 * per-post-type create/edit/publish caps so a Contributor can't create a
	 * published (or private/future) page through the endpoint.
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
		if ( in_array( $new_status, array( 'publish', 'private', 'future' ), true ) && ! current_user_can( $pt_obj->cap->publish_posts ) ) {
			return new WP_Error( 'forbidden_publish', sprintf(
				/* translators: %s: post type label */
				__( 'You do not have permission to publish %s.', 'vibe-ai' ),
				$pt_obj->labels->name
			), WPVibe_Error_Contract::data( 'capability_role', false, array( 'status' => 403 ) ) );
		}
		return true;
	}

	/** `_breakdance_` normally, `_oxygen_` when this build runs in Oxygen mode. */
	private function meta_prefix() {
		if ( function_exists( '\Breakdance\BreakdanceOxygen\Strings\__bdox' ) ) {
			$prefix = \Breakdance\BreakdanceOxygen\Strings\__bdox( '_meta_prefix' );
			if ( is_string( $prefix ) && '' !== $prefix ) {
				return $prefix;
			}
		}
		return '_breakdance_';
	}

	private function builder_edit_url( $id ) {
		if ( function_exists( '\Breakdance\Admin\get_builder_loader_url' ) ) {
			return \Breakdance\Admin\get_builder_loader_url( (string) $id );
		}
		return admin_url( 'post.php?post=' . $id . '&action=edit' );
	}

	protected function breakdance_check() {
		if ( ! function_exists( '\Breakdance\Data\set_meta' ) || ! function_exists( '\Breakdance\Render\generateCacheForPost' ) ) {
			return new WP_Error( 'breakdance_inactive', __( 'Breakdance is not active on this site.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'not_supported', false, array( 'status' => 404 ) ) );
		}
		return true;
	}
}
