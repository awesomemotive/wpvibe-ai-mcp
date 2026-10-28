<?php
/**
 * Abilities for MCP clients (#748).
 *
 * Core's REST controllers list and run only abilities whose `show_in_rest` is
 * true. A plugin can instead mark an ability for MCP agents (`meta.mcp.public`,
 * or `meta.public`), which the official MCP Adapter exposes and core REST hides
 * (Fluent Forms, Meta Box, Premium Addons, PixelYourSite). WPVibe is an MCP
 * client, so these routes list and run both kinds, and nothing else: an
 * ability that is neither REST- nor MCP-public is never listed, read or run.
 *
 * The ability's own permission callback stays the boundary: a run goes through
 * the same normalize, validate and permission steps as core's run controller,
 * then WP_Ability::execute(), which checks them again. Approval for
 * destructive abilities stays on the Worker, exactly as for core's run route.
 *
 * @package WPVibe
 */

defined( 'ABSPATH' ) || exit;

class WPVibe_Abilities {

	const MAX_PER_PAGE = 100;

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
		register_rest_route(
			'wpvibe/v1',
			'/abilities',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_abilities' ),
				'permission_callback' => array( $this, 'can_read' ),
			)
		);
		// Ability names contain slashes, so the run route is registered first and the name matches lazily, as in core.
		register_rest_route(
			'wpvibe/v1',
			'/abilities/(?P<name>[a-zA-Z0-9\-\/]+?)/run',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'run_ability' ),
				'permission_callback' => array( $this, 'can_read' ),
			)
		);
		register_rest_route(
			'wpvibe/v1',
			'/abilities/(?P<name>[a-zA-Z0-9\-\/]+?)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_ability' ),
				'permission_callback' => array( $this, 'can_read' ),
			)
		);
	}

	/** The same floor as core's abilities routes; each ability's permission callback decides the run. */
	public function can_read() {
		return current_user_can( 'read' );
	}

	/**
	 * The MCP Adapter's rule (McpAbilityExposure::is_meta_public, 0.7.1): an
	 * explicit `meta.mcp.public` wins, else `meta.public === true`; a malformed
	 * `meta.mcp` fails closed.
	 *
	 * @param mixed $meta Ability meta.
	 */
	public static function is_mcp_public( $meta ) {
		if ( ! is_array( $meta ) ) {
			return false;
		}
		$mcp = isset( $meta['mcp'] ) ? $meta['mcp'] : array();
		if ( ! is_array( $mcp ) ) {
			return false;
		}
		if ( isset( $mcp['public'] ) ) {
			return (bool) $mcp['public'];
		}
		return isset( $meta['public'] ) && true === $meta['public'];
	}

	/** Whether core REST serves the ability (core reads `show_in_rest` the same loose way). */
	public static function is_rest_public( $meta ) {
		return is_array( $meta ) && ! empty( $meta['show_in_rest'] );
	}

	/**
	 * Exposed here when core REST serves it or the MCP Adapter would. A REST-visible
	 * ability with `mcp.public` false stays listed: core REST already serves it to
	 * the same user, so hiding it here would only drop something that works today.
	 *
	 * @param mixed $meta Ability meta.
	 */
	public static function is_exposed( $meta ) {
		return self::is_rest_public( $meta ) || self::is_mcp_public( $meta );
	}

	/** @return WP_Ability|null The ability when it exists and is exposed. */
	private static function find( $name ) {
		if ( ! function_exists( 'wp_has_ability' ) || ! function_exists( 'wp_get_ability' ) ) {
			return null;
		}
		$name    = (string) $name;
		$ability = '' !== $name && wp_has_ability( $name ) ? wp_get_ability( $name ) : null;
		return $ability && self::is_exposed( $ability->get_meta() ) ? $ability : null;
	}

	private static function not_found() {
		// Core's code and status, so a caller handles both routes alike.
		return new WP_Error( 'rest_ability_not_found', __( 'Ability not found.', 'vibe-ai' ), array( 'status' => 404 ) );
	}

	private static function cannot_execute() {
		return new WP_Error( 'rest_ability_cannot_execute', __( 'Sorry, you are not allowed to execute this ability.', 'vibe-ai' ), array( 'status' => rest_authorization_required_code() ) );
	}

	private static function schema_for_client( $schema ) {
		return function_exists( 'wp_prepare_json_schema_for_client' ) && is_array( $schema ) ? wp_prepare_json_schema_for_client( $schema ) : $schema;
	}

	/** Core's item shape. */
	private static function item( $ability ) {
		return array(
			'name'          => $ability->get_name(),
			'label'         => $ability->get_label(),
			'description'   => $ability->get_description(),
			'category'      => $ability->get_category(),
			'input_schema'  => self::schema_for_client( $ability->get_input_schema() ),
			'output_schema' => self::schema_for_client( $ability->get_output_schema() ),
			'meta'          => $ability->get_meta(),
		);
	}

	public function list_abilities( $request ) {
		$abilities = array();
		// Core sanitizes the category with sanitize_key; the same here so both routes filter alike.
		$category = isset( $request['category'] ) ? sanitize_key( (string) $request['category'] ) : '';
		if ( function_exists( 'wp_get_abilities' ) ) {
			foreach ( wp_get_abilities() as $ability ) {
				if ( ! self::is_exposed( $ability->get_meta() ) ) {
					continue;
				}
				if ( '' !== $category && $category !== $ability->get_category() ) {
					continue;
				}
				$abilities[] = $ability;
			}
		}

		$page     = max( 1, isset( $request['page'] ) ? (int) $request['page'] : 1 );
		$per_page = isset( $request['per_page'] ) ? (int) $request['per_page'] : 50;
		$per_page = min( self::MAX_PER_PAGE, max( 1, $per_page ) );
		$total    = count( $abilities );

		$data = array();
		foreach ( array_slice( $abilities, ( $page - 1 ) * $per_page, $per_page ) as $ability ) {
			$data[] = self::item( $ability );
		}

		$response = new WP_REST_Response( $data, 200 );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) (int) ceil( $total / $per_page ) );
		return $response;
	}

	/** The name from the route only: `$request['name']` would let a body or query parameter name a different ability than the URL does. */
	private static function route_name( $request ) {
		$url = $request->get_url_params();
		return is_array( $url ) && isset( $url['name'] ) ? (string) $url['name'] : '';
	}

	public function get_ability( $request ) {
		$ability = self::find( self::route_name( $request ) );
		return $ability ? new WP_REST_Response( self::item( $ability ), 200 ) : self::not_found();
	}

	/** JSON body `{ "input": ... }`; a missing input stays null, the only value an ability without an input schema accepts. */
	public function run_ability( $request ) {
		$ability = self::find( self::route_name( $request ) );
		if ( ! $ability ) {
			return self::not_found();
		}
		// An ability reached only through its MCP flag keeps the MCP Adapter's site-wide capability for running abilities.
		if ( ! self::is_rest_public( $ability->get_meta() ) && ! current_user_can( (string) apply_filters( 'mcp_adapter_execute_ability_capability', 'read' ) ) ) {
			return self::cannot_execute();
		}
		$body  = $request->get_json_params();
		$input = self::coerce( is_array( $body ) && array_key_exists( 'input', $body ) ? $body['input'] : null, $ability );

		// Core's run controller order and statuses: the checks see normalized input, execute() gets the input as sent and normalizes it itself.
		$checked = $input;
		if ( method_exists( $ability, 'normalize_input' ) ) {
			$checked = $ability->normalize_input( $checked );
			if ( is_wp_error( $checked ) ) {
				return self::with_status( $checked, 400 );
			}
		}
		if ( method_exists( $ability, 'validate_input' ) ) {
			$valid = $ability->validate_input( $checked );
			if ( is_wp_error( $valid ) ) {
				return self::with_status( $valid, 400 );
			}
		}
		$allowed = $ability->check_permissions( $checked );
		if ( is_wp_error( $allowed ) ) {
			return self::with_status( $allowed, rest_authorization_required_code(), true );
		}
		if ( true !== $allowed ) {
			return self::cannot_execute();
		}

		$result = $ability->execute( $input );
		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
	}

	/** Core 7.1's run controller coerces valid input to its schema types ("7" for an integer becomes 7); the same here, and never for input that does not validate. */
	private static function coerce( $input, $ability ) {
		$schema = $ability->get_input_schema();
		if ( null === $input || empty( $schema ) || ! function_exists( 'rest_sanitize_value_from_schema' ) || ! method_exists( $ability, 'validate_input' ) ) {
			return $input;
		}
		if ( is_wp_error( $ability->validate_input( $input ) ) ) {
			return $input;
		}
		$sanitized = rest_sanitize_value_from_schema( $input, $schema, 'input' );
		return self::has_error( $sanitized ) ? $input : $sanitized;
	}

	private static function has_error( $value ) {
		if ( is_wp_error( $value ) ) {
			return true;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( self::has_error( $item ) ) {
					return true;
				}
			}
		}
		return false;
	}

	private static function with_status( $error, $status, $force = false ) {
		$data = $error->get_error_data();
		if ( $force || ! is_array( $data ) || ! isset( $data['status'] ) ) {
			$error->add_data( array_merge( is_array( $data ) ? $data : array(), array( 'status' => $status ) ) );
		}
		return $error;
	}
}
