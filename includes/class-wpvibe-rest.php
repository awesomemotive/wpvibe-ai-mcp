<?php
/**
 * REST API endpoint registration for WPVibe.
 */

defined( 'ABSPATH' ) || exit;

class WPVibe_REST {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Sanitize file content — validates string type only.
	 * Must NOT strip HTML/newlines as these contain source code.
	 */
	public static function sanitize_file_content( $value ) {
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Resolve a sideload filename that ends in a real image extension.
	 *
	 * A title like "CleanShot 2026-06-29 at 14.45.58@2x" makes pathinfo() read
	 * "58@2x" (from the time) as the extension, so trusting *any* non-empty
	 * extension lets a bogus one through and media_handle_sideload() rejects the
	 * upload as a disallowed file type. Only trust a known image extension;
	 * otherwise append the correct one from the file's actual mime. SVG is
	 * admin-only since it can carry script.
	 *
	 * @param string $filename       Candidate filename (already sanitized).
	 * @param string $detected_mime  Real mime of the file (wp_get_image_mime/fileinfo).
	 * @param bool   $allow_svg      Whether the current user may upload SVG.
	 * @return string Filename guaranteed to end in a real image extension.
	 */
	public static function ensure_image_extension( $filename, $detected_mime, $allow_svg ) {
		$known = array( 'jpg', 'jpeg', 'png', 'gif', 'webp' );
		if ( $allow_svg ) {
			$known[] = 'svg';
		}
		// Trust the parsed extension only if it is already a real image extension;
		// a dotted title ("…14.45.58@2x") yields a bogus "58@2x" that pathinfo()
		// reports as an extension but wp_check_filetype_and_ext() rejects.
		$current = strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) );
		if ( in_array( $current, $known, true ) ) {
			return $filename;
		}
		// Otherwise append the proper extension for the real mime, via WP's own
		// mime->extension map. SVG is admin-only (it can carry script).
		$ext = $detected_mime ? wp_get_default_extension_for_mime_type( $detected_mime ) : '';
		if ( ! $ext || ( 'svg' === $ext && ! $allow_svg ) ) {
			$ext = 'jpg';
		}
		return $filename . '.' . $ext;
	}

	/**
	 * Validate that a URL is safe to fetch from a public server context.
	 *
	 * Rejects non-HTTP(S) schemes and hosts that resolve (IPv4 or IPv6) to any
	 * private/loopback/link-local/reserved range. Mitigates SSRF to cloud
	 * metadata endpoints (169.254.169.254, fd00::/8), loopback, RFC1918, etc.
	 * Pair with the http_request_redirection_url filter to also catch
	 * 302-to-internal-address.
	 *
	 * @param string $url URL to validate.
	 * @return true|WP_Error
	 */
	public static function validate_public_http_url( $url ) {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['scheme'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return new WP_Error( 'blocked_url', __( 'Only http(s) URLs are allowed.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'security_gate', false, array( 'status' => 403 ) ) );
		}
		if ( empty( $parts['host'] ) ) {
			return new WP_Error( 'blocked_url', __( 'URL host is missing.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'security_gate', false, array( 'status' => 403 ) ) );
		}

		$host = $parts['host'];

		// If the host is already an IP literal, validate it directly.
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			if ( ! self::ip_is_public( $host ) ) {
				return new WP_Error( 'blocked_url', __( 'URL resolves to a non-public address.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'security_gate', false, array( 'status' => 403 ) ) );
			}
			return true;
		}

		// Resolve every A + AAAA record; reject if the host can't be resolved or
		// *any* answer is non-public. Same resolver used to pin the download.
		if ( empty( self::resolve_public_ips( $host ) ) ) {
			return new WP_Error( 'blocked_url', __( 'URL host could not be resolved or resolves to a non-public address.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'security_gate', false, array( 'status' => 403 ) ) );
		}

		return true;
	}

	private static function ip_is_public( $ip ) {
		return (bool) filter_var(
			$ip,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);
	}

	/**
	 * Resolve a host to its public A/AAAA addresses. Returns an empty array if
	 * the host can't be resolved or any answer is non-public — callers pin to
	 * these IPs and must fail closed on an empty result.
	 */
	public static function resolve_public_ips( $host ) {
		$ips     = array();
		$records = @dns_get_record( $host, DNS_A + DNS_AAAA );
		if ( ! empty( $records ) ) {
			foreach ( $records as $record ) {
				if ( ! empty( $record['ip'] ) ) {
					$ips[] = $record['ip'];
				} elseif ( ! empty( $record['ipv6'] ) ) {
					$ips[] = $record['ipv6'];
				}
			}
		}
		if ( empty( $ips ) ) {
			$v4 = @gethostbynamel( $host );
			if ( ! empty( $v4 ) ) {
				$ips = $v4;
			}
		}

		$public = array();
		foreach ( $ips as $ip ) {
			if ( ! self::ip_is_public( $ip ) ) {
				return array();
			}
			$public[] = $ip;
		}
		return $public;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'rest_post_dispatch', array( $this, 'track_rest_changes' ), 10, 3 );
	}

	public function register_routes() {
		$namespace = 'wpvibe/v1';

		// Site info — requires edit_theme_options (matches WP core /wp/v2/themes).
		register_rest_route( $namespace, '/site-info', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_site_info' ),
			'permission_callback' => array( $this, 'can_manage_themes' ),
			'args'                => array(),
		) );

		// Diagnostic — list REST-exposed meta registered for a post type.
		// Useful when WP silently drops meta from a /wp/v2/<cpt>/<id> write
		// because the key isn't registered with show_in_rest=true.
		register_rest_route( $namespace, '/registered-meta', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_registered_meta' ),
			'permission_callback' => array( $this, 'can_manage_themes' ),
			'args'                => array(
				'post_type' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_key',
				),
			),
		) );

		// --- File read operations (edit_themes capability) ---

		register_rest_route( $namespace, '/file/read', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'read_file' ),
			'permission_callback' => array( $this, 'can_read_themes' ),
			'args'                => array(
				'path' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
				'start_line' => array(
					'type'              => 'integer',
					'required'          => false,
					'sanitize_callback' => 'absint',
				),
				'end_line' => array(
					'type'              => 'integer',
					'required'          => false,
					'sanitize_callback' => 'absint',
				),
			),
		) );

		register_rest_route( $namespace, '/file/list', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'list_files' ),
			'permission_callback' => array( $this, 'can_read_themes' ),
			'args'                => array(
				'pattern' => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		register_rest_route( $namespace, '/file/search', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'search_files' ),
			'permission_callback' => array( $this, 'can_read_themes' ),
			'args'                => array(
				'pattern' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
				'case_sensitive' => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'extensions' => array(
					'type'              => 'array',
					'required'          => false,
					'sanitize_callback' => function( $value ) {
						return is_array( $value ) ? array_map( 'sanitize_file_name', $value ) : array();
					},
				),
				'max_results' => array(
					'type'              => 'integer',
					'default'           => 100,
					'sanitize_callback' => 'absint',
				),
			),
		) );

		register_rest_route( $namespace, '/file/outline', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'file_outline' ),
			'permission_callback' => array( $this, 'can_read_themes' ),
			'args'                => array(
				'path' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		// --- File write operations (edit_themes + DISALLOW_FILE_EDIT check) ---

		register_rest_route( $namespace, '/file/edit', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'edit_file' ),
			'permission_callback' => array( $this, 'can_edit_themes' ),
			'args'                => array(
				'path' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
				'old_content' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => array( 'WPVibe_REST', 'sanitize_file_content' ),
				),
				'new_content' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => array( 'WPVibe_REST', 'sanitize_file_content' ),
				),
			),
		) );

		register_rest_route( $namespace, '/file/write', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'write_file' ),
			'permission_callback' => array( $this, 'can_edit_themes' ),
			'args'                => array(
				'path' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
				'content' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => array( 'WPVibe_REST', 'sanitize_file_content' ),
				),
			),
		) );

		register_rest_route( $namespace, '/file/delete', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'delete_file' ),
			'permission_callback' => array( $this, 'can_edit_themes' ),
			'args'                => array(
				'path' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		// --- Database content operations (surgical str_replace on posts/meta/options) ---

		register_rest_route( $namespace, '/content/edit', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'edit_content' ),
			'permission_callback' => array( $this, 'can_edit_content' ),
			'args'                => array(
				'target_type' => array(
					'type'              => 'string',
					'required'          => true,
					'enum'              => array( 'post', 'meta', 'option' ),
					'sanitize_callback' => 'sanitize_key',
				),
				'post_id'     => array( 'type' => 'integer', 'required' => false ),
				'field'       => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_key' ),
				'meta_key'    => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
				'option_name' => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
				'old_content' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => array( 'WPVibe_REST', 'sanitize_file_content' ) ),
				'new_content' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => array( 'WPVibe_REST', 'sanitize_file_content' ) ),
				'replace_all' => array( 'type' => 'boolean', 'required' => false, 'default' => false ),
				'whole_word'  => array( 'type' => 'boolean', 'required' => false, 'default' => false ),
			),
		) );

		register_rest_route( $namespace, '/content/search', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'search_content' ),
			'permission_callback' => array( $this, 'can_edit_content' ),
			'args'                => array(
				'target_type'    => array(
					'type'              => 'string',
					'required'          => true,
					'enum'              => array( 'post', 'meta', 'option' ),
					'sanitize_callback' => 'sanitize_key',
				),
				'post_id'        => array( 'type' => 'integer', 'required' => false ),
				'field'          => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_key' ),
				'meta_key'       => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
				'option_name'    => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
				'pattern'        => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => array( 'WPVibe_REST', 'sanitize_file_content' ) ),
				'case_sensitive' => array( 'type' => 'boolean', 'required' => false ),
				'max_results'    => array( 'type' => 'integer', 'required' => false ),
			),
		) );

		// --- Draft theme lifecycle ---

		register_rest_route( $namespace, '/draft-theme', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'create_draft_theme' ),
			'permission_callback' => array( $this, 'can_edit_themes' ),
			'args'                => array(),
		) );

		register_rest_route( $namespace, '/draft-theme/publish', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'publish_draft_theme' ),
			'permission_callback' => array( $this, 'can_publish_theme' ),
			'args'                => array(),
		) );

		register_rest_route( $namespace, '/draft-theme/preview', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_preview_url' ),
			'permission_callback' => array( $this, 'can_read_themes' ),
			'args'                => array(),
		) );

		register_rest_route( $namespace, '/draft-theme', array(
			'methods'             => 'DELETE',
			'callback'            => array( $this, 'delete_draft_theme' ),
			'permission_callback' => array( $this, 'can_edit_themes' ),
			'args'                => array(),
		) );

		// POST alias: many hardened hosts block the DELETE method at the server.
		register_rest_route( $namespace, '/draft-theme/delete', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'delete_draft_theme' ),
			'permission_callback' => array( $this, 'can_edit_themes' ),
			'args'                => array(),
		) );

		// --- WP-CLI ---

		register_rest_route( $namespace, '/cli/run', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'run_cli' ),
			'permission_callback' => array( $this, 'can_run_cli' ),
			'args'                => array(
				'command' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => array( $this, 'sanitize_cli_command' ),
				),
				'confirm_write' => array(
					'type'    => 'boolean',
					'default' => false,
				),
			),
		) );

		register_rest_route( $namespace, '/audit-log', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'audit_log_list' ),
			'permission_callback' => array( $this, 'can_manage_options' ),
			'args'                => array(
				'limit'  => array( 'type' => 'integer', 'default' => 50 ),
				'offset' => array( 'type' => 'integer', 'default' => 0 ),
			),
		) );

		// Append-only audit log writer. Called by the Worker after browser
		// approval executes a destructive REST op (CLI path writes via the
		// PHP audit-log class directly). Trust comes from App Password auth —
		// the AI's MCP tool surface doesn't expose this path.
		register_rest_route( $namespace, '/audit-log/record', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'audit_log_record' ),
			'permission_callback' => array( $this, 'can_manage_options' ),
			'args'                => array(
				'operation'      => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				'command'        => array( 'type' => 'string', 'required' => true ),
				'params'         => array( 'type' => 'string', 'required' => false ),
				'dry_run'        => array( 'type' => 'string', 'required' => false ),
				'result_summary' => array( 'type' => 'string', 'required' => false ),
			),
		) );

		register_rest_route( $namespace, '/cli/run-approved', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'run_cli_approved' ),
			'permission_callback' => array( $this, 'can_run_cli' ),
			'args'                => array(
				'command' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => array( $this, 'sanitize_cli_command' ),
				),
				'confirm_write' => array(
					'type'    => 'boolean',
					'default' => false,
				),
			),
		) );

		register_rest_route( $namespace, '/cli/status', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'cli_status' ),
			'permission_callback' => array( $this, 'can_manage_themes' ),
			'args'                => array(),
		) );

		// --- Rendered HTML ---

		register_rest_route( $namespace, '/rendered-html', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'get_rendered_html' ),
			'permission_callback' => array( $this, 'can_manage_themes' ),
			'args'                => array(
				'path' => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		// --- Classic Theme Creation ---

		register_rest_route( $namespace, '/create-classic-theme', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'create_classic_theme' ),
			'permission_callback' => array( $this, 'can_edit_themes' ),
			'args'                => array(
				'theme_name' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
				'description' => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		// --- Media Upload ---

		register_rest_route( $namespace, '/upload-media', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'upload_media' ),
			'permission_callback' => function () {
				return current_user_can( 'upload_files' ) ? true : $this->missing_capability_error( 'upload_files' );
			},
			'args'                => array(
				'url' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'esc_url_raw',
				),
				'title' => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
				),
				'alt_text' => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
				),
				'post_id' => array(
					'type'              => 'integer',
					'required'          => false,
					'sanitize_callback' => 'absint',
				),
			),
		) );

		// --- Live Reload ---

		register_rest_route( $namespace, '/last-change', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_last_change' ),
			'permission_callback' => array( $this, 'can_edit_posts' ),
			'args'                => array(
				'since' => array(
					'type'    => 'number',
					'default' => 0,
				),
			),
		) );

		register_rest_route( $namespace, '/navigate', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'navigate' ),
			'permission_callback' => array( $this, 'can_manage_themes' ),
			'args'                => array(
				'url' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'esc_url_raw',
				),
			),
		) );

		// Elementor endpoints (list_widgets, get_schema, save_page, save_template)
		// live in class-wpvibe-elementor.php — registered via its own rest_api_init hook.
	}

	// ------------------------------------------------------------------
	// Permission Callbacks — mapped to WordPress capabilities
	// ------------------------------------------------------------------

	/**
	 * Build a WP_Error naming the missing capability, instead of returning a
	 * bare `false` (WordPress's generic "Sorry, you are not allowed to do
	 * that" 403/401 tells the AI nothing it can act on).
	 */
	private function missing_capability_error( $capability, $post_id = 0 ) {
		$data      = array( 'status' => rest_authorization_required_code(), 'capability' => $capability );
		$post_type = $post_id > 0 ? get_post_type( $post_id ) : '';
		if ( $post_type ) {
			// Admins pass can_edit_content's manage_options fallback, so only
			// sub-admin accounts can reach this error and the reconnect advice
			// is accurate for them.
			$data['post_type'] = $post_type;
			return new WP_Error(
				'wpvibe_missing_capability',
				sprintf(
					/* translators: 1: WordPress capability name, 2: post ID, 3: post type slug */
					__( 'The connected account failed the "%1$s" check for post #%2$d (post type "%3$s"). Post types can carry custom capabilities, so accounts below Administrator may be blocked here even when they can edit regular posts. Reconnect with an Administrator account for access.', 'vibe-ai' ),
					$capability,
					$post_id,
					$post_type
				),
				WPVibe_Error_Contract::data( 'capability_cpt_mapping', false, $data )
			);
		}
		return new WP_Error(
			'wpvibe_missing_capability',
			sprintf(
				/* translators: %s: WordPress capability name, e.g. edit_theme_options */
				__( 'This action requires the WordPress capability "%s", which the connected account does not have. Administrators have it by default — reconnect with an account that has this capability for full access.', 'vibe-ai' ),
				$capability
			),
			WPVibe_Error_Contract::data( 'capability_role', false, $data )
		);
	}

	/**
	 * Site info and theme management — edit_theme_options.
	 * Matches WP core /wp/v2/themes permission model.
	 */
	public function can_manage_themes() {
		return current_user_can( 'edit_theme_options' ) ? true : $this->missing_capability_error( 'edit_theme_options' );
	}

	/**
	 * Cleanup endpoint + audit log read — manage_options capability.
	 * Same gate as wp-cli option/transient operations.
	 */
	public function can_manage_options() {
		return current_user_can( 'manage_options' ) ? true : $this->missing_capability_error( 'manage_options' );
	}

	/**
	 * Live reload notifications — edit_posts capability.
	 * Covers Admins, Editors, Authors, and Contributors.
	 */
	public function can_edit_posts() {
		return current_user_can( 'edit_posts' ) ? true : $this->missing_capability_error( 'edit_posts' );
	}

	/**
	 * Read theme files — edit_themes capability.
	 * Same capability WordPress requires for the Theme File Editor.
	 */
	public function can_read_themes() {
		return current_user_can( 'edit_themes' ) ? true : $this->missing_capability_error( 'edit_themes' );
	}

	/**
	 * Write/edit/delete theme files — edit_themes + respects DISALLOW_FILE_EDIT.
	 * WordPress uses this constant to lock down the Theme/Plugin File Editor.
	 * Managed hosts often set this. We must respect it.
	 */
	public function can_edit_themes() {
		if ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) {
			return new WP_Error(
				'file_edit_disabled',
				__( 'File editing is disabled on this site (DISALLOW_FILE_EDIT is set).', 'vibe-ai' ),
				WPVibe_Error_Contract::data( 'host_environment', false, array( 'status' => 403 ) )
			);
		}

		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			return new WP_Error(
				'file_mods_disabled',
				__( 'File modifications are disabled on this site (DISALLOW_FILE_MODS is set).', 'vibe-ai' ),
				WPVibe_Error_Contract::data( 'host_environment', false, array( 'status' => 403 ) )
			);
		}

		return current_user_can( 'edit_themes' ) ? true : $this->missing_capability_error( 'edit_themes' );
	}

	/**
	 * Publish draft theme — requires both edit_themes and switch_themes.
	 * Publishing replaces the live theme files and re-activates the theme.
	 */
	public function can_publish_theme() {
		$can_edit = $this->can_edit_themes();
		if ( is_wp_error( $can_edit ) ) {
			return $can_edit;
		}

		return current_user_can( 'switch_themes' ) ? true : $this->missing_capability_error( 'switch_themes' );
	}

	/**
	 * Sanitize a CLI command string without HTML-encoding angle brackets.
	 *
	 * sanitize_text_field() converts < to &lt; which injects a semicolon
	 * that trips the shell-char blocklist in WPVibe_CLI::run().
	 */
	public function sanitize_cli_command( $value ) {
		// No tag stripping: it silently corrupted values ("<b>x</b>" stored as "x",
		// script blocks vanished with their contents). The executor's SHELL_CHARS
		// check rejects < and > loudly instead, and every surface that echoes a
		// command escapes it.
		$value = wp_check_invalid_utf8( $value );
		$value = preg_replace( '/[\r\n\t ]+/', ' ', $value );
		return trim( $value );
	}

	/**
	 * WP-CLI — baseline manage_options check.
	 * Per-command capability checks happen in the handler.
	 */
	public function can_run_cli() {
		return current_user_can( 'manage_options' ) ? true : $this->missing_capability_error( 'manage_options' );
	}

	/**
	 * Content edit/search — capability depends on the target. Options carry
	 * site-wide config (and can hold secrets), so they need manage_options;
	 * post + meta edits require edit-access to the specific post.
	 */
	public function can_edit_content( $request ) {
		$type = $request->get_param( 'target_type' );
		if ( 'option' === $type ) {
			return current_user_can( 'manage_options' ) ? true : $this->missing_capability_error( 'manage_options' );
		}
		$post_id = (int) $request->get_param( 'post_id' );
		if ( $post_id > 0 ) {
			// manage_options fallback: CPT capability mappings (WPForms, LMS
			// plugins) fail edit_post even for admins. Post types with an
			// explicit do_not_allow edit cap stay fenced for everyone.
			if ( current_user_can( 'edit_post', $post_id ) || $this->admin_content_override( $post_id ) ) {
				return true;
			}
			return $this->missing_capability_error( 'edit_post', $post_id );
		}
		return current_user_can( 'edit_posts' ) ? true : $this->missing_capability_error( 'edit_posts' );
	}

	private function admin_content_override( $post_id ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		$pt_obj = get_post_type_object( get_post_type( $post_id ) );
		return $pt_obj && 'do_not_allow' !== $pt_obj->cap->edit_posts;
	}

	// ------------------------------------------------------------------
	// Site Info
	// ------------------------------------------------------------------

	/**
	 * Return the REST-registered meta keys for a post type, with each key's
	 * REST schema and sanitize/auth callback presence. Diagnoses the
	 * common "POST /wp/v2/<cpt>/<id> with meta silently drops meta" bug
	 * by surfacing which keys are actually visible to the REST controller.
	 */
	public function get_registered_meta( $request ) {
		$post_type = $request->get_param( 'post_type' );

		if ( ! post_type_exists( $post_type ) ) {
			return new WP_Error(
				'unknown_post_type',
				/* translators: %s: post type slug */
				sprintf( __( 'Post type \'%s\' is not registered. Confirm register_post_type() ran and rest_api_init has fired.', 'vibe-ai' ), $post_type ),
				WPVibe_Error_Contract::data( 'not_found', false, array( 'status' => 404 ) )
			);
		}

		$pt_object       = get_post_type_object( $post_type );
		$pt_show_in_rest = $pt_object && ! empty( $pt_object->show_in_rest );
		$supports_cf     = post_type_supports( $post_type, 'custom-fields' );

		$registered = function_exists( 'get_registered_meta_keys' )
			? get_registered_meta_keys( 'post', $post_type )
			: array();

		$result = array();
		foreach ( $registered as $key => $args ) {
			$result[ $key ] = array(
				'type'         => isset( $args['type'] ) ? $args['type'] : 'string',
				'single'       => ! empty( $args['single'] ),
				'show_in_rest' => ! empty( $args['show_in_rest'] ),
				'has_default'  => isset( $args['default'] ),
				'has_sanitize' => ! empty( $args['sanitize_callback'] ),
				'has_auth'     => ! empty( $args['auth_callback'] ),
			);
		}

		// Both flags must be true for REST writes to /wp/v2/<cpt>/<id> with
		// meta in the body to actually persist. show_in_rest on the CPT
		// gates REST routing; custom-fields support gates meta exposure.
		$rest_meta_writable = $pt_show_in_rest && $supports_cf;

		return rest_ensure_response( array(
			'post_type'                   => $post_type,
			'post_type_show_in_rest'      => $pt_show_in_rest,
			'post_type_supports_meta'     => $supports_cf,
			'rest_meta_writable'          => $rest_meta_writable,
			'registered_meta_count'       => count( $result ),
			'registered_meta'             => $result,
			'gotcha_note'                 => $rest_meta_writable
				? null
				: 'REST meta writes will silently drop unregistered keys. To fix: either call wpvibe_field_register() (auto-adds custom-fields support) or add `add_post_type_support( \'' . $post_type . '\', \'custom-fields\' )` in your theme.',
		) );
	}

	/**
	 * Capability flags the MCP keys off to decide whether a route is available
	 * before steering the AI to it. Prefer adding a flag here over forcing the
	 * MCP to compare WPVIBE_VERSION strings — flags are forward-compatible.
	 */
	public static function feature_flags() {
		return array( 'content_edit', 'content_search' );
	}

	public function get_site_info() {
		$theme     = wp_get_theme();
		$theme_dir = $theme->get_stylesheet_directory();

		// Standard WordPress template files we surface in the inventory. Allowlist
		// keeps the response stable regardless of what else is in the theme dir.
		$known_templates = array(
			'index.php', 'header.php', 'footer.php', 'functions.php', 'style.css',
			'front-page.php', 'home.php', 'single.php', 'page.php',
			'archive.php', '404.php', 'search.php', 'comments.php',
		);
		// The "minimal complete WP theme" floor — what every content-bearing site needs.
		$floor_templates = array( 'single.php', 'page.php', 'archive.php', '404.php', 'search.php' );

		$templates_present = array();
		foreach ( $known_templates as $tpl ) {
			if ( file_exists( $theme_dir . '/' . $tpl ) ) {
				$templates_present[] = $tpl;
			}
		}
		$templates_missing = array_values( array_diff( $floor_templates, $templates_present ) );

		// Check WP-CLI availability.
		$cli = new WPVibe_CLI();
		$cli_status = $cli->check_availability();

		// Connect-time capability preflight: the MCP reads this right after
		// OAuth to warn about limited accounts, and error recovery hints tell
		// the AI to check it before suggesting a reconnect.
		$user = wp_get_current_user();

		return rest_ensure_response( array(
			'connected_user' => array(
				'login' => $user->user_login,
				'roles' => array_values( $user->roles ),
				'caps'  => array(
					'manage_options'     => current_user_can( 'manage_options' ),
					'edit_theme_options' => current_user_can( 'edit_theme_options' ),
					'edit_posts'         => current_user_can( 'edit_posts' ),
					'publish_posts'      => current_user_can( 'publish_posts' ),
					'upload_files'       => current_user_can( 'upload_files' ),
					'activate_plugins'   => current_user_can( 'activate_plugins' ),
				),
			),
			'site_name'    => get_bloginfo( 'name' ),
			'wp_version'   => get_bloginfo( 'version' ),
			'php_version'  => phpversion(),
			'wpvibe_plugin_version' => defined( 'WPVIBE_VERSION' ) ? WPVIBE_VERSION : '',
			'features'     => self::feature_flags(),
			'active_theme' => array(
				'name'              => $theme->get( 'Name' ),
				'stylesheet'        => get_stylesheet(),
				'version'           => $theme->get( 'Version' ),
				'wpvibe_authored'   => 'yes' === strtolower( trim( (string) $theme->get( 'WPVibe' ) ) ),
				'uses_tailwind'     => file_exists( $theme_dir . '/theme.css' ),
				'templates_present' => $templates_present,
				'templates_missing' => $templates_missing,
			),
			'plugins'        => array_keys( get_plugins() ),
			'themes'         => array_keys( wp_get_themes() ),
			'wp_cli_available' => $cli_status['available'],
			'wp_cli_version'   => $cli_status['version'] ?? null,
		) );
	}

	// ------------------------------------------------------------------
	// Content Operations (delegated to WPVibe_Content_Ops)
	// ------------------------------------------------------------------

	/** Build the {type, args} pair the content ops expect from request params. */
	private function content_target( $request ) {
		$type = $request->get_param( 'target_type' );
		switch ( $type ) {
			case 'post':
				return array( $type, array( 'post_id' => (int) $request->get_param( 'post_id' ), 'field' => $request->get_param( 'field' ) ) );
			case 'meta':
				return array( $type, array( 'post_id' => (int) $request->get_param( 'post_id' ), 'key' => $request->get_param( 'meta_key' ) ) );
			case 'option':
				return array( $type, array( 'name' => $request->get_param( 'option_name' ) ) );
			default:
				return array( $type, array() );
		}
	}

	public function edit_content( $request ) {
		list( $type, $args ) = $this->content_target( $request );
		$content_ops = new WPVibe_Content_Ops();
		return $content_ops->edit(
			$type,
			$args,
			$request->get_param( 'old_content' ),
			$request->get_param( 'new_content' ),
			(bool) $request->get_param( 'replace_all' ),
			(bool) $request->get_param( 'whole_word' )
		);
	}

	public function search_content( $request ) {
		list( $type, $args ) = $this->content_target( $request );
		$max = $request->get_param( 'max_results' );
		$content_ops = new WPVibe_Content_Ops();
		return $content_ops->search(
			$type,
			$args,
			$request->get_param( 'pattern' ),
			(bool) $request->get_param( 'case_sensitive' ),
			null === $max ? 50 : (int) $max
		);
	}

	// ------------------------------------------------------------------
	// File Operations (delegated to WPVibe_File_Ops)
	// ------------------------------------------------------------------

	public function read_file( $request ) {
		$path       = sanitize_text_field( $request->get_param( 'path' ) );
		$start_line = $request->get_param( 'start_line' );
		$end_line   = $request->get_param( 'end_line' );

		$file_ops = new WPVibe_File_Ops();
		return $file_ops->read( $path, $start_line, $end_line );
	}

	public function edit_file( $request ) {
		$path        = sanitize_text_field( $request->get_param( 'path' ) );
		$old_content = $request->get_param( 'old_content' );
		$new_content = $request->get_param( 'new_content' );

		$file_ops = new WPVibe_File_Ops();
		return $file_ops->edit( $path, $old_content, $new_content );
	}

	public function write_file( $request ) {
		$path    = sanitize_text_field( $request->get_param( 'path' ) );
		$content = $request->get_param( 'content' );

		$file_ops = new WPVibe_File_Ops();
		return $file_ops->write( $path, $content );
	}

	public function delete_file( $request ) {
		$path = sanitize_text_field( $request->get_param( 'path' ) );

		$file_ops = new WPVibe_File_Ops();
		return $file_ops->delete( $path );
	}

	public function list_files( $request ) {
		$pattern = $request->get_param( 'pattern' );

		$file_ops = new WPVibe_File_Ops();
		return $file_ops->list_files( $pattern );
	}

	public function search_files( $request ) {
		$pattern        = $request->get_param( 'pattern' );
		$case_sensitive = (bool) $request->get_param( 'case_sensitive' );
		$extensions     = $request->get_param( 'extensions' );
		$max_results    = $request->get_param( 'max_results' );

		$file_ops = new WPVibe_File_Ops();
		return $file_ops->search_files( $pattern, $case_sensitive, $extensions, $max_results ? (int) $max_results : 100 );
	}

	public function file_outline( $request ) {
		$path = sanitize_text_field( $request->get_param( 'path' ) );

		$file_ops = new WPVibe_File_Ops();
		return $file_ops->outline( $path );
	}

	// ------------------------------------------------------------------
	// Draft Theme (delegated to WPVibe_Draft_Theme)
	// ------------------------------------------------------------------

	public function create_draft_theme() {
		$draft = new WPVibe_Draft_Theme();
		return $draft->create();
	}

	public function publish_draft_theme() {
		$draft = new WPVibe_Draft_Theme();
		return $draft->publish();
	}

	public function get_preview_url() {
		$draft = new WPVibe_Draft_Theme();
		return $draft->preview_url();
	}

	public function delete_draft_theme() {
		$draft = new WPVibe_Draft_Theme();
		return $draft->delete();
	}

	// ------------------------------------------------------------------
	// WP-CLI (delegated to WPVibe_CLI)
	// ------------------------------------------------------------------

	public function run_cli( $request ) {
		$command       = $request->get_param( 'command' );
		$confirm_write = (bool) $request->get_param( 'confirm_write' );

		$cli = new WPVibe_CLI();
		return $cli->run( $command, $confirm_write );
	}

	/**
	 * Destructive-execute endpoint. Called by the Worker AFTER the user
	 * approves the operation in their browser. Skips the destructive
	 * classifier; otherwise identical to run_cli. Trust comes from App
	 * Password auth — the AI cannot reach this endpoint via the MCP tool
	 * surface (run_wp_cli's schema does not expose this path, and the
	 * Worker controls all plugin API calls).
	 */
	public function run_cli_approved( $request ) {
		$command       = $request->get_param( 'command' );
		$confirm_write = (bool) $request->get_param( 'confirm_write' );

		$cli = new WPVibe_CLI();
		return $cli->run_approved( $command, $confirm_write );
	}

	public function cli_status() {
		$cli = new WPVibe_CLI();
		return rest_ensure_response( $cli->check_availability() );
	}

	// ------------------------------------------------------------------
	// Audit log
	// ------------------------------------------------------------------

	public function audit_log_list( $request ) {
		$limit  = (int) $request->get_param( 'limit' );
		$offset = (int) $request->get_param( 'offset' );
		return rest_ensure_response( array(
			'total'   => WPVibe_Audit_Log::count(),
			'entries' => WPVibe_Audit_Log::get_recent( $limit, $offset ),
		) );
	}

	public function audit_log_record( $request ) {
		$params_json  = (string) ( $request->get_param( 'params' ) ?? '' );
		$dry_run_json = (string) ( $request->get_param( 'dry_run' ) ?? '' );

		// Params/dry_run arrive as JSON strings from the Worker; decode for
		// the log_execution shape which expects PHP arrays.
		$params  = '' !== $params_json ? json_decode( $params_json, true ) : null;
		$dry_run = '' !== $dry_run_json ? json_decode( $dry_run_json, true ) : null;

		WPVibe_Audit_Log::log_execution( array(
			'operation'      => (string) $request->get_param( 'operation' ),
			'command'        => (string) $request->get_param( 'command' ),
			'params'         => $params,
			'dry_run'        => $dry_run,
			'result_summary' => (string) ( $request->get_param( 'result_summary' ) ?? '' ),
		) );
		return rest_ensure_response( array( 'recorded' => true ) );
	}

	// ------------------------------------------------------------------
	// Rendered HTML (localhost fallback for get_page_html)
	// ------------------------------------------------------------------

	public function get_rendered_html( $request ) {
		$path = sanitize_text_field( $request->get_param( 'path' ) ?: '/' );

		// Build the full URL including any preview token.
		$url = home_url( $path );
		$token = get_option( 'wpvibe_preview_token' );
		if ( $token ) {
			$url = add_query_arg( 'wpvibe_preview', $token, $url );
		}

		$response = wp_remote_get( $url, array(
			'timeout'   => 15,
			'sslverify' => true,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$html = wp_remote_retrieve_body( $response );

		return rest_ensure_response( array(
			'html' => $html,
			'url'  => $url,
		) );
	}

	// ------------------------------------------------------------------
	// Media Upload
	// ------------------------------------------------------------------

	/**
	 * Download an image from a URL and add it to the WordPress media library.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload_media( $request ) {
		$url      = esc_url_raw( $request->get_param( 'url' ) );
		$title    = sanitize_text_field( $request->get_param( 'title' ) ?: '' );
		$alt_text = sanitize_text_field( $request->get_param( 'alt_text' ) ?: '' );
		$post_id  = absint( $request->get_param( 'post_id' ) ?: 0 );

		if ( empty( $url ) ) {
			return new WP_Error( 'invalid_url', __( 'Image URL is required.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 400 ) ) );
		}

		// SSRF: validate scheme, host, and all resolved addresses (IPv4 + IPv6)
		// against private/loopback/link-local/reserved ranges.
		$safety = self::validate_public_http_url( $url );
		if ( is_wp_error( $safety ) ) {
			return $safety;
		}

		// Load required WordPress media functions.
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// Re-validate every redirect hop — a public URL can 302 to 169.254.169.254.
		$redirect_guard = function ( $redirect_url ) {
			$check = WPVibe_REST::validate_public_http_url( $redirect_url );
			if ( is_wp_error( $check ) ) {
				return 0;
			}
			return $redirect_url;
		};
		add_filter( 'http_request_redirection_url', $redirect_guard, 10, 1 );

		// Pin the connection to the validated public IPs so a DNS rebind can't
		// swap in an internal address between validation and fetch. WP's curl
		// transport disables CURLOPT_FOLLOWLOCATION and re-fires http_api_curl
		// per redirect hop, so each hop re-pins. Streams transport (no hook)
		// falls back to validate_public_http_url + the redirect guard above.
		$pin_dns = function ( $handle, $args, $request_url ) {
			$host = wp_parse_url( $request_url, PHP_URL_HOST );
			if ( ! $host || filter_var( $host, FILTER_VALIDATE_IP ) ) {
				return;
			}
			$scheme = strtolower( (string) wp_parse_url( $request_url, PHP_URL_SCHEME ) );
			$port   = wp_parse_url( $request_url, PHP_URL_PORT );
			if ( ! $port ) {
				$port = ( 'https' === $scheme ) ? 443 : 80;
			}
			$ips = WPVibe_REST::resolve_public_ips( $host );
			// Fail closed: with no validated public IP, pin to TEST-NET-1 (RFC 5737, unroutable).
			$target = $ips ? $ips[0] : '192.0.2.1';
			curl_setopt( $handle, CURLOPT_RESOLVE, array( "{$host}:{$port}:{$target}" ) );
		};
		add_action( 'http_api_curl', $pin_dns, 10, 3 );

		// Download the image to a temp file.
		$tmp = download_url( $url, 30 );

		remove_action( 'http_api_curl', $pin_dns, 10 );
		remove_filter( 'http_request_redirection_url', $redirect_guard, 10 );
		if ( is_wp_error( $tmp ) ) {
			return new WP_Error(
				'download_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to download image: %s', 'vibe-ai' ),
					$tmp->get_error_message()
				),
				WPVibe_Error_Contract::data( 'wp_core', true, array( 'status' => 500 ) )
			);
		}

		// Resolve a filename that ends in a real image extension. A title such as
		// "…14.45.58@2x" makes pathinfo() read "58@2x" as the extension, so we
		// validate against known image types and fall back to the file's actual
		// mime — otherwise media_handle_sideload() rejects it as a bad file type.
		// SVG stays admin-only (it can carry script: XSS risk in the media list).
		$url_path      = wp_parse_url( $url, PHP_URL_PATH );
		$filename      = $title ? sanitize_file_name( $title ) : basename( (string) $url_path );
		$detected_mime = wp_get_image_mime( $tmp );
		if ( ! $detected_mime && function_exists( 'mime_content_type' ) ) {
			$detected_mime = mime_content_type( $tmp );
		}
		$filename = self::ensure_image_extension( $filename, $detected_mime, current_user_can( 'manage_options' ) );

		$file_array = array(
			'name'     => $filename,
			'tmp_name' => $tmp,
		);

		// Sideload into the media library.
		$attachment_id = media_handle_sideload( $file_array, $post_id, $title );

		// Clean up temp file if sideload failed.
		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $tmp );
			return new WP_Error(
				'upload_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to upload image: %s', 'vibe-ai' ),
					$attachment_id->get_error_message()
				),
				WPVibe_Error_Contract::data( 'filesystem', false, array( 'status' => 500 ) )
			);
		}

		// Set alt text if provided.
		if ( $alt_text ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );
		}

		// Set title if provided.
		if ( $title ) {
			wp_update_post( array(
				'ID'         => $attachment_id,
				'post_title' => $title,
			) );
		}

		$attachment_url = wp_get_attachment_url( $attachment_id );
		$metadata       = wp_get_attachment_metadata( $attachment_id );

		return rest_ensure_response( array(
			'attachment_id' => $attachment_id,
			'url'           => $attachment_url,
			'title'         => get_the_title( $attachment_id ),
			'alt_text'      => get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			'width'         => ! empty( $metadata['width'] ) ? $metadata['width'] : null,
			'height'        => ! empty( $metadata['height'] ) ? $metadata['height'] : null,
			'mime_type'     => get_post_mime_type( $attachment_id ),
		) );
	}

	// ------------------------------------------------------------------
	// Classic Theme Creation
	// ------------------------------------------------------------------

	public function create_classic_theme( $request ) {
		$theme_name  = sanitize_text_field( $request->get_param( 'theme_name' ) );
		$description = sanitize_text_field( $request->get_param( 'description' ) ?: '' );

		$creator = new WPVibe_Classic_Theme();
		return $creator->create( $theme_name, $description );
	}

	// ------------------------------------------------------------------
	// Live Reload
	// ------------------------------------------------------------------

	public function get_last_change( $request ) {
		$since = (float) $request->get_param( 'since' );

		if ( $since > 0 ) {
			return rest_ensure_response( array(
				'changes' => WPVibe_Change_Tracker::get_since( $since ),
			) );
		}

		// Legacy: return single latest change (same format as before).
		return rest_ensure_response( WPVibe_Change_Tracker::get() );
	}

	public function navigate( $request ) {
		$url = esc_url_raw( $request->get_param( 'url' ) );
		if ( empty( $url ) ) {
			return new WP_Error( 'invalid_url', __( 'URL is required.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 400 ) ) );
		}
		WPVibe_Change_Tracker::mark( array(
			'summary' => __( 'Navigate', 'vibe-ai' ),
			'url'     => $url,
			'force'   => true,
		) );
		return rest_ensure_response( array( 'navigating' => $url ) );
	}

	// ------------------------------------------------------------------
	// REST API Change Detection (replaces MCP-side markChange callback)
	// ------------------------------------------------------------------

	/**
	 * Detect REST API write operations and mark them for live reload.
	 * Auto-detects post/page permalinks for browser navigation.
	 *
	 * @param WP_REST_Response $response The response object.
	 * @param WP_REST_Server   $server   The REST server.
	 * @param WP_REST_Request  $request  The request object.
	 * @return WP_REST_Response Unmodified response.
	 */
	public function track_rest_changes( $response, $server, $request ) {
		// Record last WPVibe activity for the admin "Connected" indicator.
		// Gate on a capability that only trusted site roles carry so a lower-
		// privilege user can't flip the badge to "Connected" by spoofing the header.
		if (
			$request->get_header( 'x_wpvibe' ) === '1'
			&& $response->get_status() < 400
			&& ( current_user_can( 'edit_theme_options' ) || current_user_can( 'edit_posts' ) )
		) {
			$last = (int) get_option( 'wpvibe_last_active', 0 );
			if ( time() - $last > 3600 ) {
				update_option( 'wpvibe_last_active', time(), false );
			}
		}

		$method = $request->get_method();

		// Only track write operations that succeeded.
		if ( in_array( $method, array( 'GET', 'OPTIONS', 'HEAD' ), true ) || $response->get_status() >= 400 ) {
			return $response;
		}

		// Only track requests from the WPVibe MCP server (identified by custom header).
		if ( $request->get_header( 'x_wpvibe' ) !== '1' ) {
			return $response;
		}

		// Skip our own wpvibe endpoints — they call mark() directly.
		$route = $request->get_route();
		if ( strpos( $route, '/wpvibe/v1/' ) === 0 ) {
			return $response;
		}

		// Skip autosave — WordPress fires these on the edit screen and would cause reload loops.
		if ( strpos( $route, '/autosaves' ) !== false ) {
			return $response;
		}

		// Dynamic post type detection — handles posts, pages, products, custom types.
		$post_types = get_post_types( array( 'show_in_rest' => true ), 'objects' );
		$matched_pt = null;
		$post_id    = 0;

		foreach ( $post_types as $pt ) {
			$base = $pt->rest_base ?: $pt->name;
			if ( preg_match( "#/wp/v2/{$base}(?:/(\d+))?#", $route, $matches ) ) {
				$matched_pt = $pt;
				$post_id    = ! empty( $matches[1] ) ? (int) $matches[1] : 0;
				break;
			}
		}

		if ( $matched_pt ) {
			$status = $response->get_status();
			$data   = $response->get_data();
			$singular = $matched_pt->labels->singular_name; // "Post", "Page", "Product", etc.

			// Get post ID from response if not in URL.
			if ( ! $post_id && ! empty( $data['id'] ) ) {
				$post_id = (int) $data['id'];
			}

			// Summary.
			if ( 201 === $status ) {
				$summary = sprintf( 'New %s created', strtolower( $singular ) );
			} elseif ( 'DELETE' === $method ) {
				$summary = $singular . ' trashed';
			} else {
				$summary = $singular . ' updated';
			}

			// Append title.
			if ( $post_id && 'attachment' !== $matched_pt->name ) {
				$title = '';
				if ( ! empty( $data['title']['rendered'] ) ) {
					$title = wp_strip_all_tags( $data['title']['rendered'] );
				} elseif ( ! empty( $data['title']['raw'] ) ) {
					$title = $data['title']['raw'];
				} else {
					$title = get_the_title( $post_id );
				}
				if ( $title ) {
					$summary .= ': ' . $title;
				}
			}

			// Build URLs and label based on post status.
			$url       = '';
			$admin_url = '';
			$label     = 'Refresh';

			if ( $post_id && 'attachment' !== $matched_pt->name ) {
				$post_status = get_post_status( $post_id );
				$edit_link   = admin_url( "post.php?post={$post_id}&action=edit" );

				if ( 'trash' === $post_status ) {
					$admin_url = admin_url( 'edit.php?post_status=trash&post_type=' . rawurlencode( $matched_pt->name ) );
					$label     = 'View Trash';
				} elseif ( 'publish' === $post_status ) {
					$url       = get_permalink( $post_id );
					$admin_url = $url; // Published — view makes sense in admin too
					$label     = 'View ' . $singular;
				} else {
					$url       = get_preview_post_link( $post_id );
					$admin_url = $edit_link;
					$label     = ( 201 === $status ) ? 'Edit ' . $singular : 'Preview ' . $singular;
				}
			}

			WPVibe_Change_Tracker::mark( array(
				'summary'      => $summary,
				'action_label' => $label,
				'url'          => $url,
				'admin_url'    => $admin_url,
				'post_id'      => $post_id,
			) );
		} elseif ( preg_match( '#/wp/v2/settings#', $route ) ) {
			WPVibe_Change_Tracker::mark( array(
				'summary'      => __( 'Site settings updated', 'vibe-ai' ),
				'action_label' => 'Refresh',
			) );
		} else {
			WPVibe_Change_Tracker::mark( array(
				'summary'      => sprintf( '%s %s', $method, $route ),
				'action_label' => 'Refresh',
			) );
		}

		return $response;
	}
}
