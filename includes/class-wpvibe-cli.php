<?php
/**
 * WP-CLI-compatible command interface backed by native WordPress PHP APIs.
 *
 * Accepts WP-CLI command syntax (e.g., "plugin list --status=active") and
 * dispatches to native WordPress functions. No proc_open, no wp-cli binary needed.
 *
 * Security model:
 * - Default-deny allowlist: only explicitly listed commands can run.
 * - Per-command WordPress capability checks.
 * - Respects DISALLOW_FILE_MODS for commands that modify files.
 * - DB queries restricted to SELECT only with LIMIT enforcement.
 * - Dangerous flags are stripped before dispatch.
 */

defined( 'ABSPATH' ) || exit;

class WPVibe_CLI {

	const ALLOWLIST = array(
		// Read tier
		'plugin list'      => array( 'tier' => 'read', 'cap' => 'activate_plugins' ),
		'plugin status'    => array( 'tier' => 'read', 'cap' => 'activate_plugins' ),
		'plugin search'    => array( 'tier' => 'read', 'cap' => 'install_plugins' ),
		'theme list'       => array( 'tier' => 'read', 'cap' => 'switch_themes' ),
		'theme status'     => array( 'tier' => 'read', 'cap' => 'switch_themes' ),
		'option get'       => array( 'tier' => 'read', 'cap' => 'manage_options' ),
		'option list'      => array( 'tier' => 'read', 'cap' => 'manage_options' ),
		'user list'        => array( 'tier' => 'read', 'cap' => 'list_users' ),
		'post list'        => array( 'tier' => 'read', 'cap' => 'edit_posts' ),
		'post get'         => array( 'tier' => 'read', 'cap' => 'edit_posts' ),
		'post meta get'    => array( 'tier' => 'read', 'cap' => 'edit_posts' ),
		'taxonomy list'    => array( 'tier' => 'read', 'cap' => 'edit_posts' ),
		'term list'        => array( 'tier' => 'read', 'cap' => 'manage_categories' ),
		'media list'       => array( 'tier' => 'read', 'cap' => 'upload_files' ),
		'comment list'     => array( 'tier' => 'read', 'cap' => 'moderate_comments' ),
		'comment count'    => array( 'tier' => 'read', 'cap' => 'moderate_comments' ),
		'menu list'        => array( 'tier' => 'read', 'cap' => 'edit_theme_options' ),
		'widget list'      => array( 'tier' => 'read', 'cap' => 'edit_theme_options' ),
		'sidebar list'     => array( 'tier' => 'read', 'cap' => 'edit_theme_options' ),
		'rewrite list'     => array( 'tier' => 'read', 'cap' => 'manage_options' ),
		'cache type'       => array( 'tier' => 'read', 'cap' => 'manage_options' ),
		'cron event list'  => array( 'tier' => 'read', 'cap' => 'manage_options' ),
		'db query'         => array( 'tier' => 'read', 'cap' => 'manage_options' ),

		// Write tier
		'theme activate'       => array( 'tier' => 'write', 'cap' => 'switch_themes' ),
		'plugin activate'      => array( 'tier' => 'write', 'cap' => 'activate_plugins' ),
		'plugin deactivate'    => array( 'tier' => 'write', 'cap' => 'activate_plugins' ),
		'plugin install'       => array( 'tier' => 'write', 'cap' => 'install_plugins', 'check_file_mods' => true ),
		'plugin update'        => array( 'tier' => 'write', 'cap' => 'update_plugins', 'check_file_mods' => true ),
		'option update'        => array( 'tier' => 'write', 'cap' => 'manage_options' ),
		'post create'          => array( 'tier' => 'write', 'cap' => 'edit_posts' ),
		'post update'          => array( 'tier' => 'write', 'cap' => 'edit_posts' ),
		'post delete'          => array( 'tier' => 'write', 'cap' => 'delete_posts' ),
		'post meta update'     => array( 'tier' => 'write', 'cap' => 'edit_posts' ),
		'post meta delete'     => array( 'tier' => 'write', 'cap' => 'edit_posts' ),
		'cache flush'          => array( 'tier' => 'write', 'cap' => 'manage_options' ),
		'rewrite flush'        => array( 'tier' => 'write', 'cap' => 'manage_options' ),
		'search-replace'       => array( 'tier' => 'write', 'cap' => 'manage_options' ),
	);

	const BLOCKED_OPTIONS = array(
		'siteurl',
		'home',
		'admin_email',
		'users_can_register',
		'default_role',
		'active_plugins',
		'template',
		'stylesheet',
		'db_version',
		'initial_db_version',
		'wp_user_roles',
		'cron',
		'recently_activated',
		'uninstall_plugins',
		'auto_update_plugins',
		'auto_update_themes',
		'auth_key',
		'secure_auth_key',
		'logged_in_key',
		'nonce_key',
		'auth_salt',
		'secure_auth_salt',
		'logged_in_salt',
		'nonce_salt',
	);

	const BLOCKED_FLAGS = array( '--require', '--exec', '--ssh', '--http', '--url', '--path', '--skip-plugins', '--skip-themes' );
	const SHELL_CHARS   = array( ';', '&&', '||', '|', '`', '$(', '>', '<', "\n", "\r" );

	/** Handler map: command key → method name. */
	const HANDLERS = array(
		'plugin list'       => 'handle_plugin_list',
		'plugin status'     => 'handle_plugin_status',
		'plugin search'     => 'handle_plugin_search',
		'theme list'        => 'handle_theme_list',
		'theme status'      => 'handle_theme_status',
		'option get'        => 'handle_option_get',
		'option list'       => 'handle_option_list',
		'option update'     => 'handle_option_update',
		'user list'         => 'handle_user_list',
		'post list'         => 'handle_post_list',
		'post get'          => 'handle_post_get',
		'post create'       => 'handle_post_create',
		'post update'       => 'handle_post_update',
		'post delete'       => 'handle_post_delete',
		'post meta get'     => 'handle_post_meta_get',
		'post meta update'  => 'handle_post_meta_update',
		'post meta delete'  => 'handle_post_meta_delete',
		'taxonomy list'     => 'handle_taxonomy_list',
		'term list'         => 'handle_term_list',
		'media list'        => 'handle_media_list',
		'comment list'      => 'handle_comment_list',
		'comment count'     => 'handle_comment_count',
		'menu list'         => 'handle_menu_list',
		'widget list'       => 'handle_widget_list',
		'sidebar list'      => 'handle_sidebar_list',
		'rewrite list'      => 'handle_rewrite_list',
		'rewrite flush'     => 'handle_rewrite_flush',
		'cache type'        => 'handle_cache_type',
		'cache flush'       => 'handle_cache_flush',
		'cron event list'   => 'handle_cron_event_list',
		'db query'          => 'handle_db_query',
		'theme activate'    => 'handle_theme_activate',
		'plugin activate'   => 'handle_plugin_activate',
		'plugin deactivate' => 'handle_plugin_deactivate',
		'plugin install'    => 'handle_plugin_install',
		'plugin update'     => 'handle_plugin_update',
		'search-replace'    => 'handle_not_implemented',
	);

	/**
	 * Run a WP-CLI-style command via native PHP dispatch.
	 */
	public function run( $command, $confirm_write = false ) {
		$command = trim( $command );
		if ( strpos( $command, 'wp ' ) === 0 ) {
			$command = substr( $command, 3 );
		}

		// db query needs < and > for SQL comparisons — skip those chars for that command.
		$is_db_query = ( strpos( $command, 'db query' ) === 0 );
		foreach ( self::SHELL_CHARS as $char ) {
			if ( $is_db_query && ( '<' === $char || '>' === $char ) ) {
				continue;
			}
			if ( strpos( $command, $char ) !== false ) {
				/* translators: %s: the blocked character */
				return new WP_Error( 'shell_chars', sprintf( __( 'Command contains disallowed character: %s', 'vibe-ai' ), $char ), array( 'status' => 400 ) );
			}
		}

		$tokens = $this->tokenize( $command );
		if ( empty( $tokens ) ) {
			return new WP_Error( 'empty_command', __( 'No command provided.', 'vibe-ai' ), array( 'status' => 400 ) );
		}

		$resolved = $this->resolve_command( $tokens );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$meta       = $resolved['meta'];
		$key_length = $resolved['key_length'];

		if ( ! current_user_can( $meta['cap'] ) ) {
			/* translators: %s: WordPress capability name */
			return new WP_Error( 'insufficient_cap', sprintf( __( 'You do not have the required capability (%s).', 'vibe-ai' ), $meta['cap'] ), array( 'status' => 403 ) );
		}

		if ( ! empty( $meta['check_file_mods'] ) && defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			return new WP_Error( 'file_mods_disabled', __( 'File modifications are disabled (DISALLOW_FILE_MODS).', 'vibe-ai' ), array( 'status' => 403 ) );
		}

		$args        = $this->strip_blocked_flags( $tokens );
		$command_key = implode( ' ', array_slice( $this->get_positional( $tokens ), 0, $key_length ) );

		// Dispatch to native handler.
		$start  = microtime( true );
		$result = $this->dispatch( $args, $key_length, $command_key, $confirm_write );
		$elapsed = (int) ( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$response = array(
			'command'           => 'wp ' . $command_key,
			'tier'              => $meta['tier'],
			'exit_code'         => $result['exit_code'],
			'stdout'            => $result['stdout'],
			'stderr'            => $result['stderr'],
			'execution_time_ms' => $elapsed,
		);

		if ( ! empty( $result['requires_confirmation'] ) ) {
			$response['requires_confirmation'] = true;
			$response['message']               = $result['message'];
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Always available — native PHP, no external dependencies.
	 */
	public function check_availability() {
		return array(
			'available' => true,
			'method'    => 'native',
		);
	}

	// ------------------------------------------------------------------
	// Dispatch
	// ------------------------------------------------------------------

	private function dispatch( $tokens, $key_length, $command_key, $confirm_write = false ) {
		if ( ! isset( self::HANDLERS[ $command_key ] ) ) {
			/* translators: %s: command key */
			return $this->error_result( sprintf( __( 'No handler for: %s', 'vibe-ai' ), $command_key ) );
		}

		// Separate positional args and flags after the command key.
		$positional = array();
		$flags      = array();
		$skip       = 0;

		foreach ( $tokens as $token ) {
			if ( strpos( $token, '--' ) === 0 ) {
				$stripped = substr( $token, 2 );
				if ( strpos( $stripped, '=' ) !== false ) {
					list( $k, $v ) = explode( '=', $stripped, 2 );
					// Normalize hyphenated flags to underscored (e.g., per-page → per_page).
					$flags[ str_replace( '-', '_', $k ) ] = $v;
				} else {
					$flags[ str_replace( '-', '_', $stripped ) ] = true;
				}
			} else {
				if ( $skip < $key_length ) {
					$skip++;
					continue;
				}
				$positional[] = $token;
			}
		}

		$handler = self::HANDLERS[ $command_key ];
		return $this->{$handler}( $positional, $flags, $confirm_write );
	}

	// ------------------------------------------------------------------
	// Read Handlers
	// ------------------------------------------------------------------

	private function handle_plugin_list( $positional, $flags ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all     = get_plugins();
		$results = array();
		foreach ( $all as $file => $data ) {
			$active = is_plugin_active( $file );
			$status = $active ? 'active' : 'inactive';
			if ( isset( $flags['status'] ) && $flags['status'] !== $status ) {
				continue;
			}
			$results[] = array(
				'name'    => $data['Name'],
				'status'  => $status,
				'version' => $data['Version'],
				'file'    => $file,
			);
		}
		return $this->success_result( $this->filter_fields( $results, $flags ) );
	}

	private function handle_plugin_status( $positional, $flags ) {
		if ( empty( $positional[0] ) ) {
			return $this->error_result( __( 'Plugin slug required.', 'vibe-ai' ) );
		}
		$file = $this->resolve_plugin_file( $positional[0] );
		if ( ! $file ) {
			/* translators: %s: plugin slug */
			return $this->error_result( sprintf( __( 'Plugin \'%s\' not found.', 'vibe-ai' ), $positional[0] ) );
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all  = get_plugins();
		$data = $all[ $file ];
		return $this->success_result( array(
			'name'    => $data['Name'],
			'status'  => is_plugin_active( $file ) ? 'active' : 'inactive',
			'version' => $data['Version'],
			'file'    => $file,
			'author'  => $data['AuthorName'] ?? '',
			'description' => $data['Description'] ?? '',
		) );
	}

	private function handle_plugin_search( $positional, $flags ) {
		if ( empty( $positional[0] ) ) {
			return $this->error_result( __( 'Search term required. Example: plugin search "contact form"', 'vibe-ai' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';

		$args = array(
			'search'   => implode( ' ', $positional ),
			'per_page' => min( (int) ( $flags['per_page'] ?? 10 ), 30 ),
			'page'     => (int) ( $flags['page'] ?? 1 ),
			'fields'   => array(
				'short_description' => true,
				'icons'             => false,
				'banners'           => false,
				'compatibility'     => false,
			),
		);

		$api = plugins_api( 'query_plugins', $args );
		if ( is_wp_error( $api ) ) {
			return $this->error_result( $api->get_error_message() );
		}

		$results = array();
		foreach ( $api->plugins as $plugin ) {
			$results[] = array(
				'name'              => $plugin->name,
				'slug'              => $plugin->slug,
				'version'           => $plugin->version,
				'author'            => wp_strip_all_tags( $plugin->author ),
				'rating'            => $plugin->rating,
				'active_installs'   => $plugin->active_installs,
				'short_description' => $plugin->short_description,
			);
		}

		return $this->success_result( $this->filter_fields( $results, $flags ) );
	}

	private function handle_theme_list( $positional, $flags ) {
		$themes      = wp_get_themes();
		$active_slug = get_stylesheet();
		$results     = array();
		foreach ( $themes as $slug => $theme ) {
			$status = ( $slug === $active_slug ) ? 'active' : 'inactive';
			if ( isset( $flags['status'] ) && $flags['status'] !== $status ) {
				continue;
			}
			$results[] = array(
				'name'    => $theme->get( 'Name' ),
				'status'  => $status,
				'version' => $theme->get( 'Version' ),
				'slug'    => $slug,
			);
		}
		return $this->success_result( $this->filter_fields( $results, $flags ) );
	}

	private function handle_theme_status( $positional, $flags ) {
		if ( empty( $positional[0] ) ) {
			return $this->error_result( __( 'Theme slug required.', 'vibe-ai' ) );
		}
		$theme = wp_get_theme( $positional[0] );
		if ( ! $theme->exists() ) {
			/* translators: %s: theme slug */
			return $this->error_result( sprintf( __( 'Theme \'%s\' not found.', 'vibe-ai' ), $positional[0] ) );
		}
		return $this->success_result( array(
			'name'    => $theme->get( 'Name' ),
			'status'  => ( get_stylesheet() === $positional[0] ) ? 'active' : 'inactive',
			'version' => $theme->get( 'Version' ),
			'author'  => $theme->get( 'Author' ),
			'slug'    => $positional[0],
		) );
	}

	private function handle_option_get( $positional, $flags ) {
		if ( empty( $positional[0] ) ) {
			return $this->error_result( __( 'Option key required.', 'vibe-ai' ) );
		}

		if ( in_array( $positional[0], self::BLOCKED_OPTIONS, true ) ) {
			return $this->error_result(
				sprintf(
					/* translators: %s: option key */
					__( 'Option \'%s\' is blocked for security.', 'vibe-ai' ),
					$positional[0]
				)
			);
		}

		$value = get_option( $positional[0], null );
		if ( null === $value ) {
			/* translators: %s: option key */
			return $this->error_result( sprintf( __( 'Option \'%s\' not found.', 'vibe-ai' ), $positional[0] ) );
		}
		return array(
			'exit_code' => 0,
			'stdout'    => is_scalar( $value ) ? (string) $value : wp_json_encode( $value, JSON_PRETTY_PRINT ),
			'stderr'    => '',
		);
	}

	private function handle_option_list( $positional, $flags ) {
		global $wpdb;

		$search = isset( $flags['search'] ) ? $flags['search'] : '%';
		// Convert WP-CLI wildcard syntax (* and ?) to SQL LIKE syntax (% and _).
		$search = str_replace( array( '*', '?' ), array( '%', '_' ), $search );

		$has_autoload = isset( $flags['autoload'] );

		/*
		 * Raw SQL justification: Dynamic LIKE pattern from user input; prepared via $wpdb->prepare().
		 * Only reads from the options table; no writes. Two separate queries to avoid interpolation.
		 */
		if ( $has_autoload ) {
			$autoload_val = ( 'on' === $flags['autoload'] || 'yes' === $flags['autoload'] ) ? 'yes' : 'no';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND autoload = %s ORDER BY option_name LIMIT 100",
					$search,
					$autoload_val
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name LIMIT 100",
					$search
				),
				ARRAY_A
			);
		}

		$results = array();
		foreach ( $rows as $row ) {
			if ( in_array( $row['option_name'], self::BLOCKED_OPTIONS, true ) ) {
				continue;
			}
			if ( strlen( $row['option_value'] ) > 200 ) {
				$row['option_value'] = substr( $row['option_value'], 0, 200 ) . '...[truncated]';
			}
			$results[] = $row;
		}

		return $this->success_result( $this->filter_fields( $results, $flags ) );
	}

	private function handle_user_list( $positional, $flags ) {
		$args = array( 'number' => 100 );
		if ( isset( $flags['role'] ) )   $args['role']   = $flags['role'];
		if ( isset( $flags['number'] ) ) $args['number'] = min( (int) $flags['number'], 1000 );
		$users   = get_users( $args );
		$results = array();
		foreach ( $users as $user ) {
			$results[] = array(
				'ID'           => $user->ID,
				'user_login'   => $user->user_login,
				'display_name' => $user->display_name,
				'user_email'   => $user->user_email,
				'roles'        => implode( ',', $user->roles ),
			);
		}
		return $this->success_result( $this->filter_fields( $results, $flags ) );
	}

	private function handle_post_list( $positional, $flags ) {
		$args = array(
			'post_type'      => $flags['post_type'] ?? 'post',
			'post_status'    => $flags['post_status'] ?? 'any',
			'posts_per_page' => isset( $flags['posts_per_page'] ) ? min( (int) $flags['posts_per_page'], 100 ) : 20,
			'orderby'        => $flags['orderby'] ?? 'date',
			'order'          => $flags['order'] ?? 'DESC',
		);
		$posts   = get_posts( $args );
		$results = array();
		foreach ( $posts as $post ) {
			$results[] = array(
				'ID'          => $post->ID,
				'post_title'  => $post->post_title,
				'post_name'   => $post->post_name,
				'post_status' => $post->post_status,
				'post_type'   => $post->post_type,
				'post_date'   => $post->post_date,
			);
		}
		return $this->success_result( $this->filter_fields( $results, $flags ) );
	}

	private function handle_post_get( $positional, $flags ) {
		if ( empty( $positional[0] ) ) {
			return $this->error_result( __( 'Post ID required.', 'vibe-ai' ) );
		}
		$post = get_post( (int) $positional[0] );
		if ( ! $post ) {
			/* translators: %s: post ID */
			return $this->error_result( sprintf( __( 'Post %s not found.', 'vibe-ai' ), $positional[0] ) );
		}

		$has_explicit_fields = ! empty( $flags['fields'] );

		$content = $post->post_content;
		if ( ! $has_explicit_fields && strlen( $content ) > 500 ) {
			$content = substr( $content, 0, 500 ) . "\n[truncated — use --fields=post_content for full content]";
		}

		$content_filtered = $post->post_content_filtered;
		if ( ! $has_explicit_fields && strlen( $content_filtered ) > 500 ) {
			$content_filtered = substr( $content_filtered, 0, 500 ) . "\n[truncated — use --fields=post_content_filtered for full content]";
		}

		$data = array(
			'ID'                    => $post->ID,
			'post_title'            => $post->post_title,
			'post_name'             => $post->post_name,
			'post_status'           => $post->post_status,
			'post_type'             => $post->post_type,
			'post_date'             => $post->post_date,
			'post_modified'         => $post->post_modified,
			'post_author'           => $post->post_author,
			'post_excerpt'          => $post->post_excerpt,
			'post_content'          => $content,
			'post_content_filtered' => $content_filtered,
			'post_parent'           => $post->post_parent,
			'menu_order'            => $post->menu_order,
			'comment_status'        => $post->comment_status,
			'post_mime_type'        => $post->post_mime_type,
			'guid'                  => $post->guid,
			'comment_count'         => $post->comment_count,
		);

		return $this->success_result( $this->filter_fields( array( $data ), $flags )[0] ?? $data );
	}

	private function handle_post_meta_get( $positional, $flags ) {
		if ( empty( $positional[0] ) ) {
			return $this->error_result( __( 'Post ID required. Usage: post meta get <id> [<key>]', 'vibe-ai' ) );
		}

		$post_id = (int) $positional[0];
		$post    = get_post( $post_id );
		if ( ! $post ) {
			/* translators: %s: post ID */
			return $this->error_result( sprintf( __( 'Post %s not found.', 'vibe-ai' ), $positional[0] ) );
		}

		// Single key mode.
		if ( ! empty( $positional[1] ) ) {
			$value = get_post_meta( $post_id, $positional[1], true );
			return array(
				'exit_code' => 0,
				'stdout'    => is_scalar( $value ) ? (string) $value : wp_json_encode( $value, JSON_PRETTY_PRINT ),
				'stderr'    => '',
			);
		}

		// All meta mode.
		$meta    = get_post_meta( $post_id );
		$results = array();
		foreach ( $meta as $key => $values ) {
			// Hide internal meta unless --all flag is set.
			if ( empty( $flags['all'] ) && strpos( $key, '_' ) === 0 ) {
				continue;
			}
			$results[] = array(
				'key'   => $key,
				'value' => count( $values ) === 1 ? $values[0] : $values,
			);
		}

		return $this->success_result( $results );
	}

	private function handle_taxonomy_list( $positional, $flags ) {
		$taxonomies = get_taxonomies( array(), 'objects' );
		$results    = array();
		foreach ( $taxonomies as $slug => $tax ) {
			if ( isset( $flags['public'] ) && (bool) $flags['public'] !== $tax->public ) {
				continue;
			}
			$results[] = array(
				'name'         => $slug,
				'label'        => $tax->label,
				'public'       => $tax->public,
				'hierarchical' => $tax->hierarchical,
				'object_type'  => $tax->object_type,
			);
		}
		return $this->success_result( $this->filter_fields( $results, $flags ) );
	}

	private function handle_term_list( $positional, $flags ) {
		// Accept taxonomy as positional (real WP-CLI) or --taxonomy flag (AI compat).
		$taxonomy = $positional[0] ?? $flags['taxonomy'] ?? '';
		if ( empty( $taxonomy ) ) {
			return $this->error_result( __( 'Taxonomy required. Usage: term list <taxonomy> or term list --taxonomy=category', 'vibe-ai' ) );
		}
		if ( ! taxonomy_exists( $taxonomy ) ) {
			/* translators: %s: taxonomy name */
			return $this->error_result( sprintf( __( 'Taxonomy \'%s\' not found.', 'vibe-ai' ), $taxonomy ) );
		}

		$args = array(
			'taxonomy'   => $taxonomy,
			'number'     => isset( $flags['number'] ) ? min( (int) $flags['number'], 500 ) : 100,
			'hide_empty' => isset( $flags['hide_empty'] ) ? (bool) $flags['hide_empty'] : false,
			'orderby'    => $flags['orderby'] ?? 'name',
			'order'      => $flags['order'] ?? 'ASC',
		);
		if ( isset( $flags['search'] ) ) {
			$args['search'] = $flags['search'];
		}
		if ( isset( $flags['parent'] ) ) {
			$args['parent'] = (int) $flags['parent'];
		}

		$terms   = get_terms( $args );
		if ( is_wp_error( $terms ) ) {
			return $this->error_result( $terms->get_error_message() );
		}

		$results = array();
		foreach ( $terms as $term ) {
			$results[] = array(
				'term_id'     => $term->term_id,
				'name'        => $term->name,
				'slug'        => $term->slug,
				'description' => $term->description,
				'count'       => $term->count,
				'parent'      => $term->parent,
			);
		}

		return $this->success_result( $this->filter_fields( $results, $flags ) );
	}

	private function handle_media_list( $positional, $flags ) {
		// Not a real WP-CLI command — maps to get_posts(type=attachment) for AI convenience.
		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => isset( $flags['posts_per_page'] ) ? min( (int) $flags['posts_per_page'], 100 ) : 20,
			'orderby'        => $flags['orderby'] ?? 'date',
			'order'          => $flags['order'] ?? 'DESC',
		);
		if ( isset( $flags['post_mime_type'] ) ) {
			$args['post_mime_type'] = $flags['post_mime_type'];
		}

		$posts   = get_posts( $args );
		$results = array();
		foreach ( $posts as $post ) {
			$results[] = array(
				'ID'             => $post->ID,
				'post_title'     => $post->post_title,
				'post_mime_type' => $post->post_mime_type,
				'guid'           => $post->guid,
				'post_date'      => $post->post_date,
			);
		}

		return $this->success_result( $this->filter_fields( $results, $flags ) );
	}

	private function handle_comment_list( $positional, $flags ) {
		$args = array(
			'number' => isset( $flags['number'] ) ? min( (int) $flags['number'], 100 ) : 20,
		);
		if ( isset( $flags['status'] ) )  $args['status']  = $flags['status'];
		if ( isset( $flags['post_id'] ) ) $args['post_id'] = (int) $flags['post_id'];
		if ( isset( $flags['type'] ) )    $args['type']    = $flags['type'];

		$comments = get_comments( $args );
		$results  = array();
		foreach ( $comments as $comment ) {
			$content = $comment->comment_content;
			if ( strlen( $content ) > 200 ) {
				$content = substr( $content, 0, 200 ) . '...[truncated]';
			}
			$results[] = array(
				'comment_ID'      => $comment->comment_ID,
				'comment_author'  => $comment->comment_author,
				'comment_content' => $content,
				'comment_date'    => $comment->comment_date,
				'comment_approved' => $comment->comment_approved,
				'comment_post_ID' => $comment->comment_post_ID,
			);
		}

		return $this->success_result( $this->filter_fields( $results, $flags ) );
	}

	private function handle_comment_count( $positional, $flags ) {
		$post_id = ! empty( $positional[0] ) ? (int) $positional[0] : 0;
		$counts  = wp_count_comments( $post_id );

		return $this->success_result( array(
			'approved'            => $counts->approved,
			'awaiting_moderation' => $counts->moderated,
			'spam'                => $counts->spam,
			'trash'               => $counts->trash,
			'total_comments'      => $counts->total_comments,
		) );
	}

	private function handle_menu_list( $positional, $flags ) {
		$menus   = wp_get_nav_menus();
		$results = array();
		foreach ( $menus as $menu ) {
			$results[] = array(
				'term_id' => $menu->term_id,
				'name'    => $menu->name,
				'slug'    => $menu->slug,
				'count'   => $menu->count,
			);
		}
		return $this->success_result( $results );
	}

	private function handle_widget_list( $positional, $flags ) {
		global $wp_registered_sidebars;
		$sidebars = get_option( 'sidebars_widgets', array() );
		$results  = array();
		foreach ( $sidebars as $sidebar_id => $widgets ) {
			if ( 'wp_inactive_widgets' === $sidebar_id ) continue;
			$name = isset( $wp_registered_sidebars[ $sidebar_id ] ) ? $wp_registered_sidebars[ $sidebar_id ]['name'] : $sidebar_id;
			$results[] = array(
				'sidebar_id' => $sidebar_id,
				'name'       => $name,
				'widgets'    => $widgets ?: array(),
			);
		}
		return $this->success_result( $results );
	}

	private function handle_sidebar_list( $positional, $flags ) {
		global $wp_registered_sidebars;
		$results = array();
		if ( $wp_registered_sidebars ) {
			foreach ( $wp_registered_sidebars as $id => $sidebar ) {
				$results[] = array(
					'id'          => $id,
					'name'        => $sidebar['name'],
					'description' => $sidebar['description'] ?? '',
				);
			}
		}
		return $this->success_result( $this->filter_fields( $results, $flags ) );
	}

	private function handle_rewrite_list( $positional, $flags ) {
		global $wp_rewrite;
		$rules   = $wp_rewrite->rules ?: array();
		$results = array();
		foreach ( $rules as $pattern => $query ) {
			$results[] = array( 'match' => $pattern, 'query' => $query );
		}
		return $this->success_result( $results );
	}

	private function handle_cache_type( $positional, $flags ) {
		return $this->success_result( array(
			'object_cache' => wp_using_ext_object_cache() ? 'external' : 'default',
			'drop_in'      => file_exists( WP_CONTENT_DIR . '/object-cache.php' ),
		) );
	}

	private function handle_cron_event_list( $positional, $flags ) {
		$crons   = _get_cron_array();
		$results = array();
		if ( $crons ) {
			foreach ( $crons as $timestamp => $hooks ) {
				foreach ( $hooks as $hook => $events ) {
					foreach ( $events as $key => $event ) {
						$results[] = array(
							'hook'      => $hook,
							'next_run'  => gmdate( 'Y-m-d H:i:s', $timestamp ),
							'schedule'  => $event['schedule'] ?: 'once',
							'interval'  => $event['interval'] ?? null,
						);
					}
				}
			}
		}
		return $this->success_result( $results );
	}

	// ------------------------------------------------------------------
	// DB Query Handler (SELECT only)
	// ------------------------------------------------------------------

	private function handle_db_query( $positional, $flags ) {
		global $wpdb;

		$sql = trim( implode( ' ', $positional ) );
		if ( empty( $sql ) ) {
			return $this->error_result( __( 'SQL query required. Example: db query "SELECT * FROM {prefix}posts LIMIT 10"', 'vibe-ai' ) );
		}

		// Replace {prefix} placeholder with actual table prefix.
		$sql = str_replace( '{prefix}', $wpdb->prefix, $sql );

		// Validate: SELECT only.
		// Strip SQL comments to prevent keyword bypass.
		$stripped = preg_replace( '/--.*$/m', '', $sql );
		$stripped = preg_replace( '/\/\*.*?\*\//s', '', $stripped );
		$normalized = preg_replace( '/\s+/', ' ', strtoupper( trim( $stripped ) ) );

		if ( strpos( $normalized, 'SELECT' ) !== 0 ) {
			return $this->error_result( __( 'Only SELECT queries are allowed.', 'vibe-ai' ) );
		}

		$blocked = array(
			'INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'TRUNCATE',
			'CREATE', 'GRANT', 'REVOKE', 'EXEC', 'EXECUTE',
			'RENAME', 'REPLACE', 'LOAD', 'OUTFILE', 'DUMPFILE',
		);
		foreach ( $blocked as $keyword ) {
			if ( preg_match( '/\b' . $keyword . '\b/', $normalized ) ) {
				/* translators: %s: SQL keyword */
				return $this->error_result( sprintf( __( 'Blocked SQL keyword: %s. Only SELECT queries are allowed.', 'vibe-ai' ), $keyword ) );
			}
		}

		if ( preg_match( '/\bINTO\s+(OUTFILE|DUMPFILE|@)/i', $normalized ) ) {
			return $this->error_result( __( 'SELECT INTO is not allowed.', 'vibe-ai' ) );
		}

		if ( preg_match( '/\bFOR\s+(UPDATE|SHARE)\b/', $normalized ) ) {
			return $this->error_result( __( 'FOR UPDATE/SHARE is not allowed.', 'vibe-ai' ) );
		}

		if ( preg_match( '/;\s*\S/', $sql ) ) {
			return $this->error_result( __( 'Multiple SQL statements are not allowed.', 'vibe-ai' ) );
		}

		// Enforce LIMIT. --limit flag overrides default (capped at 1000).
		$default_limit = 100;
		if ( ! empty( $flags['limit'] ) && is_numeric( $flags['limit'] ) ) {
			$default_limit = min( (int) $flags['limit'], 1000 );
		}
		$sql = rtrim( $sql, '; ' );
		if ( preg_match( '/\bLIMIT\s+(\d+)/i', $sql, $m ) ) {
			$sql = preg_replace_callback( '/\bLIMIT\s+(\d+)/i', function ( $m ) {
				return 'LIMIT ' . min( (int) $m[1], 1000 );
			}, $sql );
		} else {
			$sql .= ' LIMIT ' . $default_limit;
		}

		// Execute.
		/*
		 * Raw SQL justification: This handler accepts user-provided SELECT queries
		 * for database inspection. $wpdb->prepare() cannot be used because the full
		 * SQL structure is dynamic. Security is enforced via SELECT-only validation,
		 * blocked keyword list, comment stripping, INTO/FOR UPDATE prevention,
		 * multi-statement prevention, and automatic LIMIT enforcement.
		 */
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$results = $wpdb->get_results( $sql, ARRAY_A ); // nosemgrep: direct-db-query
		if ( $wpdb->last_error ) {
			/* translators: %s: SQL error message */
			return $this->error_result( sprintf( __( 'SQL error: %s', 'vibe-ai' ), $wpdb->last_error ) );
		}

		$output = array(
			'table_prefix'  => $wpdb->prefix,
			'rows_returned' => count( $results ),
			'results'       => $results,
		);

		return array(
			'exit_code' => 0,
			'stdout'    => wp_json_encode( $output, JSON_PRETTY_PRINT ),
			'stderr'    => '',
		);
	}

	// ------------------------------------------------------------------
	// Write Handlers
	// ------------------------------------------------------------------

	private function handle_theme_activate( $positional, $flags ) {
		if ( empty( $positional[0] ) ) {
			return $this->error_result( __( 'Theme slug required.', 'vibe-ai' ) );
		}
		$theme = wp_get_theme( $positional[0] );
		if ( ! $theme->exists() ) {
			/* translators: %s: theme slug */
			return $this->error_result( sprintf( __( 'Theme \'%s\' not found.', 'vibe-ai' ), $positional[0] ) );
		}
		switch_theme( $positional[0] );
		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Theme activated: {$positional[0]}",
			'action_label' => 'View Site',
			'url'          => home_url( '/' ),
			'admin_url'    => home_url( '/' ),
		) );
		/* translators: %s: theme name */
		return $this->success_result( array( 'message' => sprintf( __( 'Switched to theme \'%s\'.', 'vibe-ai' ), $theme->get( 'Name' ) ) ) );
	}

	private function handle_plugin_activate( $positional, $flags ) {
		if ( empty( $positional[0] ) ) {
			return $this->error_result( __( 'Plugin slug required.', 'vibe-ai' ) );
		}
		$file = $this->resolve_plugin_file( $positional[0] );
		if ( ! $file ) {
			/* translators: %s: plugin slug */
			return $this->error_result( sprintf( __( 'Plugin \'%s\' not found.', 'vibe-ai' ), $positional[0] ) );
		}
		$result = activate_plugin( $file );
		if ( is_wp_error( $result ) ) {
			return $this->error_result( $result->get_error_message() );
		}
		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Plugin activated: {$positional[0]}",
			'action_label' => 'Refresh',
		) );
		/* translators: %s: plugin slug */
		return $this->success_result( array( 'message' => sprintf( __( 'Plugin \'%s\' activated.', 'vibe-ai' ), $positional[0] ) ) );
	}

	private function handle_plugin_deactivate( $positional, $flags ) {
		if ( empty( $positional[0] ) ) {
			return $this->error_result( __( 'Plugin slug required.', 'vibe-ai' ) );
		}
		$file = $this->resolve_plugin_file( $positional[0] );
		if ( ! $file ) {
			/* translators: %s: plugin slug */
			return $this->error_result( sprintf( __( 'Plugin \'%s\' not found.', 'vibe-ai' ), $positional[0] ) );
		}
		deactivate_plugins( $file );
		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Plugin deactivated: {$positional[0]}",
			'action_label' => 'Refresh',
		) );
		/* translators: %s: plugin slug */
		return $this->success_result( array( 'message' => sprintf( __( 'Plugin \'%s\' deactivated.', 'vibe-ai' ), $positional[0] ) ) );
	}

	private function handle_plugin_install( $positional, $flags, $confirm_write = false ) {
		if ( empty( $positional[0] ) ) {
			return $this->error_result( __( 'Plugin slug required.', 'vibe-ai' ) );
		}
		$slug = sanitize_key( $positional[0] );

		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';

		$api_fields = array(
			'short_description' => true,
			'sections'          => false,
			'icons'             => false,
			'banners'           => false,
		);
		$api_args = array( 'slug' => $slug, 'fields' => $api_fields );
		if ( ! empty( $flags['version'] ) ) {
			$api_args['version'] = $flags['version'];
		}

		$api = plugins_api( 'plugin_information', $api_args );
		if ( is_wp_error( $api ) ) {
			return $this->error_result( $api->get_error_message() );
		}

		// Phase 1: Return info and require confirmation.
		if ( ! $confirm_write ) {
			return array(
				'exit_code'             => 0,
				'stdout'                => wp_json_encode( array(
					'name'            => $api->name,
					'slug'            => $api->slug,
					'version'         => $api->version,
					'author'          => wp_strip_all_tags( $api->author ),
					'requires'        => $api->requires ?? '',
					'tested'          => $api->tested ?? '',
					'rating'          => $api->rating,
					'active_installs' => $api->active_installs,
					'download_link'   => $api->download_link,
				), JSON_PRETTY_PRINT ),
				'stderr'                => '',
				'requires_confirmation' => true,
				'message'               => sprintf(
					/* translators: 1: plugin name, 2: plugin version */
					__( 'Ready to install %1$s v%2$s. Call again with confirm_write=true to proceed.', 'vibe-ai' ),
					$api->name,
					$api->version
				),
			);
		}

		// Phase 2: Actual install.
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = $upgrader->install( $api->download_link );

		if ( is_wp_error( $result ) ) {
			return $this->error_result( $result->get_error_message() );
		}
		if ( ! $result ) {
			$messages = $skin->get_upgrade_messages();
			return $this->error_result( __( 'Install failed.', 'vibe-ai' ) . ' ' . implode( ' ', $messages ) );
		}

		// Optionally activate (matches real WP-CLI --activate flag).
		$activated = false;
		if ( ! empty( $flags['activate'] ) ) {
			$plugin_file = $upgrader->plugin_info();
			if ( $plugin_file ) {
				$activate_result = activate_plugin( $plugin_file );
				$activated       = ! is_wp_error( $activate_result );
			}
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Plugin installed: {$slug}" . ( $activated ? ' (activated)' : '' ),
			'action_label' => 'Manage Plugins',
			'admin_url'    => admin_url( 'plugins.php' ),
		) );

		$msg = sprintf(
			/* translators: 1: plugin name, 2: plugin version */
			__( 'Installed %1$s v%2$s.', 'vibe-ai' ),
			$api->name,
			$api->version
		);
		if ( $activated ) {
			$msg .= ' ' . __( 'Plugin activated.', 'vibe-ai' );
		}

		return $this->success_result( array( 'message' => $msg ) );
	}

	private function handle_plugin_update( $positional, $flags, $confirm_write = false ) {
		if ( empty( $positional[0] ) ) {
			return $this->error_result( __( 'Plugin slug required.', 'vibe-ai' ) );
		}
		$file = $this->resolve_plugin_file( $positional[0] );
		if ( ! $file ) {
			/* translators: %s: plugin slug */
			return $this->error_result( sprintf( __( 'Plugin \'%s\' not found.', 'vibe-ai' ), $positional[0] ) );
		}

		// Check for available update.
		wp_update_plugins();
		$update_data = get_site_transient( 'update_plugins' );
		if ( ! isset( $update_data->response[ $file ] ) ) {
			return $this->error_result( __( 'No update available for this plugin.', 'vibe-ai' ) );
		}
		$update = $update_data->response[ $file ];

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all = get_plugins();

		// Phase 1: Return info and require confirmation.
		if ( ! $confirm_write ) {
			return array(
				'exit_code'             => 0,
				'stdout'                => wp_json_encode( array(
					'name'            => $all[ $file ]['Name'],
					'current_version' => $all[ $file ]['Version'],
					'new_version'     => $update->new_version,
					'slug'            => $update->slug,
				), JSON_PRETTY_PRINT ),
				'stderr'                => '',
				'requires_confirmation' => true,
				'message'               => sprintf(
					/* translators: 1: plugin name, 2: current version, 3: new version */
					__( 'Ready to update %1$s from %2$s to %3$s. Call again with confirm_write=true to proceed.', 'vibe-ai' ),
					$all[ $file ]['Name'],
					$all[ $file ]['Version'],
					$update->new_version
				),
			);
		}

		// Phase 2: Actual update.
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = $upgrader->upgrade( $file );

		if ( is_wp_error( $result ) ) {
			return $this->error_result( $result->get_error_message() );
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Plugin updated: {$positional[0]}",
			'action_label' => 'Manage Plugins',
			'admin_url'    => admin_url( 'plugins.php' ),
		) );

		return $this->success_result( array(
			'message' => sprintf(
				/* translators: 1: plugin name, 2: new version */
				__( 'Updated %1$s to v%2$s.', 'vibe-ai' ),
				$all[ $file ]['Name'],
				$update->new_version
			),
		) );
	}

	private function handle_option_update( $positional, $flags ) {
		if ( count( $positional ) < 2 ) {
			return $this->error_result( __( 'Usage: option update {key} {value}', 'vibe-ai' ) );
		}
		$key   = $positional[0];

		if ( in_array( $key, self::BLOCKED_OPTIONS, true ) ) {
			return $this->error_result(
				sprintf(
					/* translators: %s: option key */
					__( 'Option \'%s\' is blocked for security. Update it via wp-admin.', 'vibe-ai' ),
					$key
				)
			);
		}

		$value = $positional[1];
		// Auto-decode JSON values.
		$decoded = json_decode( $value, true );
		if ( null !== $decoded ) {
			$value = $decoded;
		}
		update_option( $key, $value );
		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Option updated: {$key}",
			'action_label' => 'Refresh',
		) );
		/* translators: %s: option key */
		return $this->success_result( array( 'message' => sprintf( __( 'Updated option \'%s\'.', 'vibe-ai' ), $key ) ) );
	}

	private function handle_post_create( $positional, $flags ) {
		$args = array(
			'post_title'    => $flags['post_title'] ?? __( 'Untitled', 'vibe-ai' ),
			'post_content'  => $flags['post_content'] ?? '',
			'post_status'   => $flags['post_status'] ?? 'draft',
			'post_type'     => $flags['post_type'] ?? 'post',
			'post_excerpt'  => $flags['post_excerpt'] ?? '',
			'post_author'   => get_current_user_id(),
		);
		if ( isset( $flags['post_name'] ) )      $args['post_name']      = $flags['post_name'];
		if ( isset( $flags['post_parent'] ) )     $args['post_parent']    = (int) $flags['post_parent'];
		if ( isset( $flags['menu_order'] ) )      $args['menu_order']     = (int) $flags['menu_order'];
		if ( isset( $flags['comment_status'] ) )  $args['comment_status'] = $flags['comment_status'];

		$id = wp_insert_post( $args, true );
		if ( is_wp_error( $id ) ) {
			return $this->error_result( $id->get_error_message() );
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Post created: #{$id} ({$args['post_type']})",
			'action_label' => 'Edit Post',
			'admin_url'    => admin_url( "post.php?post={$id}&action=edit" ),
		) );

		return $this->success_result( array(
			'ID'      => $id,
			/* translators: 1: post type, 2: post ID */
			'message' => sprintf( __( 'Created %1$s #%2$d.', 'vibe-ai' ), $args['post_type'], $id ),
		) );
	}

	private function handle_post_update( $positional, $flags ) {
		if ( empty( $positional[0] ) ) {
			return $this->error_result( __( 'Post ID required. Usage: post update <id> --post_title="New Title"', 'vibe-ai' ) );
		}

		$post_id = (int) $positional[0];
		$post    = get_post( $post_id );
		if ( ! $post ) {
			/* translators: %s: post ID */
			return $this->error_result( sprintf( __( 'Post %s not found.', 'vibe-ai' ), $positional[0] ) );
		}

		$args = array( 'ID' => $post_id );
		$updatable = array( 'post_title', 'post_content', 'post_status', 'post_excerpt',
			'post_name', 'post_parent', 'menu_order', 'comment_status', 'post_type' );
		foreach ( $updatable as $field ) {
			if ( isset( $flags[ $field ] ) ) {
				$args[ $field ] = $flags[ $field ];
			}
		}

		if ( count( $args ) < 2 ) {
			return $this->error_result( __( 'No fields to update. Use flags like --post_title, --post_content, --post_status.', 'vibe-ai' ) );
		}

		$result = wp_update_post( $args, true );
		if ( is_wp_error( $result ) ) {
			return $this->error_result( $result->get_error_message() );
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Post updated: #{$post_id}",
			'action_label' => 'Edit Post',
			'admin_url'    => admin_url( "post.php?post={$post_id}&action=edit" ),
		) );

		/* translators: %d: post ID */
		return $this->success_result( array( 'message' => sprintf( __( 'Updated post #%d.', 'vibe-ai' ), $post_id ) ) );
	}

	private function handle_post_delete( $positional, $flags ) {
		if ( empty( $positional[0] ) ) {
			return $this->error_result( __( 'Post ID required.', 'vibe-ai' ) );
		}

		$post_id = (int) $positional[0];
		$post    = get_post( $post_id );
		if ( ! $post ) {
			/* translators: %s: post ID */
			return $this->error_result( sprintf( __( 'Post %s not found.', 'vibe-ai' ), $positional[0] ) );
		}

		$force = ! empty( $flags['force'] );
		if ( $force ) {
			$result = wp_delete_post( $post_id, true );
		} else {
			$result = wp_trash_post( $post_id );
		}

		if ( ! $result ) {
			return $this->error_result( __( 'Failed to delete post.', 'vibe-ai' ) );
		}

		$action = $force ? __( 'permanently deleted', 'vibe-ai' ) : __( 'trashed', 'vibe-ai' );
		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Post {$action}: #{$post_id}",
			'action_label' => 'Refresh',
		) );

		/* translators: 1: post ID, 2: action taken (trashed or permanently deleted) */
		return $this->success_result( array( 'message' => sprintf( __( 'Post #%1$d %2$s.', 'vibe-ai' ), $post_id, $action ) ) );
	}

	private function handle_post_meta_update( $positional, $flags ) {
		if ( count( $positional ) < 3 ) {
			return $this->error_result( __( 'Usage: post meta update <post_id> <key> <value>', 'vibe-ai' ) );
		}

		$post_id = (int) $positional[0];
		$post    = get_post( $post_id );
		if ( ! $post ) {
			/* translators: %s: post ID */
			return $this->error_result( sprintf( __( 'Post %s not found.', 'vibe-ai' ), $positional[0] ) );
		}

		$key = $positional[1];
		// Block internal WordPress meta keys unless --force.
		if ( empty( $flags['force'] ) && strpos( $key, '_wp_' ) === 0 ) {
			return $this->error_result(
				sprintf(
					/* translators: %s: meta key */
					__( 'Meta key \'%s\' is a WordPress internal key. Use --force to override.', 'vibe-ai' ),
					$key
				)
			);
		}

		$value = $positional[2];
		// Auto-decode JSON values.
		$decoded = json_decode( $value, true );
		if ( null !== $decoded ) {
			$value = $decoded;
		}

		update_post_meta( $post_id, $key, $value );

		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Post meta updated: #{$post_id} → {$key}",
			'action_label' => 'Refresh',
		) );

		/* translators: 1: meta key, 2: post ID */
		return $this->success_result( array( 'message' => sprintf( __( 'Updated meta \'%1$s\' on post #%2$d.', 'vibe-ai' ), $key, $post_id ) ) );
	}

	private function handle_post_meta_delete( $positional, $flags ) {
		if ( count( $positional ) < 2 ) {
			return $this->error_result( __( 'Usage: post meta delete <post_id> <key>', 'vibe-ai' ) );
		}

		$post_id = (int) $positional[0];
		$post    = get_post( $post_id );
		if ( ! $post ) {
			/* translators: %s: post ID */
			return $this->error_result( sprintf( __( 'Post %s not found.', 'vibe-ai' ), $positional[0] ) );
		}

		$key = $positional[1];
		if ( empty( $flags['force'] ) && strpos( $key, '_wp_' ) === 0 ) {
			return $this->error_result(
				sprintf(
					/* translators: %s: meta key */
					__( 'Meta key \'%s\' is a WordPress internal key. Use --force to override.', 'vibe-ai' ),
					$key
				)
			);
		}

		delete_post_meta( $post_id, $key );

		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Post meta deleted: #{$post_id} → {$key}",
			'action_label' => 'Refresh',
		) );

		/* translators: 1: meta key, 2: post ID */
		return $this->success_result( array( 'message' => sprintf( __( 'Deleted meta \'%1$s\' from post #%2$d.', 'vibe-ai' ), $key, $post_id ) ) );
	}

	private function handle_cache_flush( $positional, $flags ) {
		wp_cache_flush();
		WPVibe_Change_Tracker::mark( array(
			'summary'      => 'Cache flushed',
			'action_label' => 'Refresh',
		) );
		return $this->success_result( array( 'message' => __( 'Object cache flushed.', 'vibe-ai' ) ) );
	}

	private function handle_rewrite_flush( $positional, $flags ) {
		flush_rewrite_rules();
		WPVibe_Change_Tracker::mark( array(
			'summary'      => 'Rewrite rules flushed',
			'action_label' => 'Refresh',
		) );
		return $this->success_result( array( 'message' => __( 'Rewrite rules flushed.', 'vibe-ai' ) ) );
	}

	private function handle_not_implemented( $positional, $flags ) {
		return $this->error_result( __( 'This command is not yet implemented via native dispatch. Use the WordPress admin dashboard.', 'vibe-ai' ) );
	}

	// ------------------------------------------------------------------
	// Parsing & Validation
	// ------------------------------------------------------------------

	private function tokenize( $input ) {
		$tokens   = array();
		$current  = '';
		$in_quote = false;
		$quote_char = '';
		$len = strlen( $input );

		for ( $i = 0; $i < $len; $i++ ) {
			$char = $input[ $i ];
			if ( $in_quote ) {
				if ( $char === $quote_char ) {
					$in_quote = false;
				} else {
					$current .= $char;
				}
			} elseif ( $char === '"' || $char === "'" ) {
				$in_quote   = true;
				$quote_char = $char;
			} elseif ( $char === ' ' || $char === "\t" ) {
				if ( '' !== $current ) {
					$tokens[] = $current;
					$current  = '';
				}
			} else {
				$current .= $char;
			}
		}
		if ( '' !== $current ) {
			$tokens[] = $current;
		}
		return $tokens;
	}

	private function get_positional( $tokens ) {
		$positional = array();
		foreach ( $tokens as $token ) {
			if ( strpos( $token, '-' ) !== 0 ) {
				$positional[] = $token;
			}
		}
		return $positional;
	}

	private function resolve_command( $tokens ) {
		$positional = $this->get_positional( $tokens );

		for ( $len = min( 3, count( $positional ) ); $len >= 1; $len-- ) {
			$key = implode( ' ', array_slice( $positional, 0, $len ) );
			if ( isset( self::ALLOWLIST[ $key ] ) ) {
				return array( 'meta' => self::ALLOWLIST[ $key ], 'key_length' => $len );
			}
		}

		$base    = $positional[0] ?? '';
		$blocked = array( 'eval', 'eval-file', 'shell', 'core', 'config', 'package', 'server', 'site' );
		if ( in_array( $base, $blocked, true ) ) {
			/* translators: %s: command name */
			return new WP_Error( 'command_blocked', sprintf( __( '"%s" commands are blocked for security.', 'vibe-ai' ), $base ), array( 'status' => 403 ) );
		}

		/* translators: %s: command name */
		return new WP_Error( 'command_not_allowed', sprintf( __( 'Command "%s" is not in the allowlist.', 'vibe-ai' ), implode( ' ', array_slice( $positional, 0, 2 ) ) ), array( 'status' => 403 ) );
	}

	private function strip_blocked_flags( $tokens ) {
		$cleaned = array();
		foreach ( $tokens as $token ) {
			$blocked = false;
			foreach ( self::BLOCKED_FLAGS as $flag ) {
				if ( $token === $flag || strpos( $token, $flag . '=' ) === 0 ) {
					$blocked = true;
					break;
				}
			}
			if ( ! $blocked ) {
				$cleaned[] = $token;
			}
		}
		return $cleaned;
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	private function success_result( $data ) {
		return array(
			'exit_code' => 0,
			'stdout'    => wp_json_encode( $data, JSON_PRETTY_PRINT ),
			'stderr'    => '',
		);
	}

	private function error_result( $message, $exit_code = 1 ) {
		return array(
			'exit_code' => $exit_code,
			'stdout'    => '',
			'stderr'    => $message,
		);
	}

	private function filter_fields( $results, $flags ) {
		if ( empty( $flags['fields'] ) || empty( $results ) ) {
			return $results;
		}
		$fields = array_map( 'trim', explode( ',', $flags['fields'] ) );
		return array_map( function ( $row ) use ( $fields ) {
			return array_intersect_key( $row, array_flip( $fields ) );
		}, $results );
	}

	private function resolve_plugin_file( $slug ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all = get_plugins();
		if ( isset( $all[ $slug ] ) ) {
			return $slug;
		}
		foreach ( $all as $file => $data ) {
			$dir = dirname( $file );
			if ( $dir === $slug ) {
				return $file;
			}
			if ( '.' === $dir && pathinfo( $file, PATHINFO_FILENAME ) === $slug ) {
				return $file;
			}
		}
		return null;
	}
}
