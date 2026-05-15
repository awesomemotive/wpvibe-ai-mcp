<?php
/**
 * REST API endpoint registration for WPVibe Connect.
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
			return new WP_Error( 'blocked_url', __( 'Only http(s) URLs are allowed.', 'vibe-ai' ), array( 'status' => 403 ) );
		}
		if ( empty( $parts['host'] ) ) {
			return new WP_Error( 'blocked_url', __( 'URL host is missing.', 'vibe-ai' ), array( 'status' => 403 ) );
		}

		$host = $parts['host'];

		// If the host is already an IP literal, validate it directly.
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			if ( ! self::ip_is_public( $host ) ) {
				return new WP_Error( 'blocked_url', __( 'URL resolves to a non-public address.', 'vibe-ai' ), array( 'status' => 403 ) );
			}
			return true;
		}

		// Resolve every A + AAAA record; reject if *any* is non-public.
		$records = @dns_get_record( $host, DNS_A + DNS_AAAA );
		if ( empty( $records ) ) {
			// Fall back to IPv4 only. If that still fails, block.
			$v4 = @gethostbynamel( $host );
			if ( empty( $v4 ) ) {
				return new WP_Error( 'blocked_url', __( 'URL host could not be resolved.', 'vibe-ai' ), array( 'status' => 403 ) );
			}
			foreach ( $v4 as $ip ) {
				if ( ! self::ip_is_public( $ip ) ) {
					return new WP_Error( 'blocked_url', __( 'URL resolves to a non-public address.', 'vibe-ai' ), array( 'status' => 403 ) );
				}
			}
			return true;
		}

		foreach ( $records as $record ) {
			$ip = '';
			if ( isset( $record['ip'] ) ) {
				$ip = $record['ip'];
			} elseif ( isset( $record['ipv6'] ) ) {
				$ip = $record['ipv6'];
			}
			if ( $ip && ! self::ip_is_public( $ip ) ) {
				return new WP_Error( 'blocked_url', __( 'URL resolves to a non-public address.', 'vibe-ai' ), array( 'status' => 403 ) );
			}
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
				return current_user_can( 'upload_files' );
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
	}

	// ------------------------------------------------------------------
	// Permission Callbacks — mapped to WordPress capabilities
	// ------------------------------------------------------------------

	/**
	 * Site info and theme management — edit_theme_options.
	 * Matches WP core /wp/v2/themes permission model.
	 */
	public function can_manage_themes() {
		return current_user_can( 'edit_theme_options' );
	}

	/**
	 * Live reload notifications — edit_posts capability.
	 * Covers Admins, Editors, Authors, and Contributors.
	 */
	public function can_edit_posts() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Read theme files — edit_themes capability.
	 * Same capability WordPress requires for the Theme File Editor.
	 */
	public function can_read_themes() {
		return current_user_can( 'edit_themes' );
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
				array( 'status' => 403 )
			);
		}

		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			return new WP_Error(
				'file_mods_disabled',
				__( 'File modifications are disabled on this site (DISALLOW_FILE_MODS is set).', 'vibe-ai' ),
				array( 'status' => 403 )
			);
		}

		return current_user_can( 'edit_themes' );
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

		if ( ! $can_edit ) {
			return false;
		}

		return current_user_can( 'switch_themes' );
	}

	/**
	 * Sanitize a CLI command string without HTML-encoding angle brackets.
	 *
	 * sanitize_text_field() converts < to &lt; which injects a semicolon
	 * that trips the shell-char blocklist in WPVibe_CLI::run().
	 */
	public function sanitize_cli_command( $value ) {
		$value = wp_check_invalid_utf8( $value );
		$value = wp_strip_all_tags( $value );
		$value = preg_replace( '/[\r\n\t ]+/', ' ', $value );
		return trim( $value );
	}

	/**
	 * WP-CLI — baseline manage_options check.
	 * Per-command capability checks happen in the handler.
	 */
	public function can_run_cli() {
		return current_user_can( 'manage_options' );
	}

	// ------------------------------------------------------------------
	// Site Info
	// ------------------------------------------------------------------

	public function get_site_info() {
		$theme = wp_get_theme();

		// Check WP-CLI availability.
		$cli = new WPVibe_CLI();
		$cli_status = $cli->check_availability();

		return rest_ensure_response( array(
			'site_name'    => get_bloginfo( 'name' ),
			'wp_version'   => get_bloginfo( 'version' ),
			'php_version'  => phpversion(),
			'active_theme' => array(
				'name'       => $theme->get( 'Name' ),
				'stylesheet' => get_stylesheet(),
			),
			'plugins'        => array_keys( get_plugins() ),
			'themes'         => array_keys( wp_get_themes() ),
			'wp_cli_available' => $cli_status['available'],
			'wp_cli_version'   => $cli_status['version'] ?? null,
		) );
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

	public function cli_status() {
		$cli = new WPVibe_CLI();
		return rest_ensure_response( $cli->check_availability() );
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
			return new WP_Error( 'invalid_url', __( 'Image URL is required.', 'vibe-ai' ), array( 'status' => 400 ) );
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

		// Download the image to a temp file.
		$tmp = download_url( $url, 30 );

		remove_filter( 'http_request_redirection_url', $redirect_guard, 10 );
		if ( is_wp_error( $tmp ) ) {
			return new WP_Error(
				'download_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to download image: %s', 'vibe-ai' ),
					$tmp->get_error_message()
				),
				array( 'status' => 500 )
			);
		}

		// Extract filename from URL.
		$url_path = wp_parse_url( $url, PHP_URL_PATH );
		$filename = $title ? sanitize_file_name( $title ) : basename( $url_path );

		// Ensure the file has an extension.
		if ( ! pathinfo( $filename, PATHINFO_EXTENSION ) ) {
			$mime = mime_content_type( $tmp );
			$ext  = array(
				'image/jpeg' => '.jpg',
				'image/png'  => '.png',
				'image/gif'  => '.gif',
				'image/webp' => '.webp',
				'image/svg+xml' => '.svg',
			);
			$filename .= isset( $ext[ $mime ] ) ? $ext[ $mime ] : '.jpg';
		}

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
				array( 'status' => 500 )
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
			return new WP_Error( 'invalid_url', __( 'URL is required.', 'vibe-ai' ), array( 'status' => 400 ) );
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
