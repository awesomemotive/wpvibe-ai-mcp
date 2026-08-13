<?php
/**
 * WP-CLI emulator: theme * handlers.
 *
 * Extracted from class-wpvibe-cli.php (mechanical split; no behavior change).
 */

defined( 'ABSPATH' ) || exit;

trait WPVibe_CLI_Theme {


	private function handle_theme_list( $positional, $flags ) {
		// Update availability mirrors handle_plugin_list: refresh from WP.org
		// only when the caller asks for update info or the cache is empty.
		// Theme update responses are arrays keyed by stylesheet (plugins: objects).
		$wants_updates = isset( $flags['update'] )
			|| ( ! empty( $flags['fields'] ) && preg_match( '/\bupdate(_version)?\b/', $flags['fields'] ) );
		$update_cache = get_site_transient( 'update_themes' );
		if ( $wants_updates || ! is_object( $update_cache ) || empty( $update_cache->checked ) ) {
			wp_update_themes();
			$update_cache = get_site_transient( 'update_themes' );
		}
		$responses = ( is_object( $update_cache ) && ! empty( $update_cache->response ) ) ? $update_cache->response : array();

		$themes      = wp_get_themes();
		$active_slug = get_stylesheet();
		$results     = array();
		foreach ( $themes as $slug => $theme ) {
			$status = ( $slug === $active_slug ) ? 'active' : 'inactive';
			if ( isset( $flags['status'] ) && $flags['status'] !== $status ) {
				continue;
			}
			$has_update = isset( $responses[ $slug ]['new_version'] );
			if ( isset( $flags['update'] ) && 'available' === $flags['update'] && ! $has_update ) {
				continue;
			}
			$results[] = array(
				'name'           => $theme->get( 'Name' ),
				'status'         => $status,
				'version'        => $theme->get( 'Version' ),
				'update'         => $has_update ? 'available' : 'none',
				'update_version' => $has_update ? $responses[ $slug ]['new_version'] : '',
				'slug'           => $slug,
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

		// switch_theme() is void; verify by reading back the active stylesheet.
		// Theme requirement validation (WP version, PHP version, parent theme) can
		// silently no-op the switch on some WP versions.
		if ( get_stylesheet() !== $positional[0] ) {
			return $this->error_result(
				sprintf(
					/* translators: 1: requested theme slug, 2: actual active theme */
					__( 'switch_theme(\'%1$s\') did not take effect. Active stylesheet is still \'%2$s\'. The theme may not meet WP/PHP version requirements, may be missing a parent theme, or may have been rejected by the theme validator.', 'vibe-ai' ),
					$positional[0],
					get_stylesheet()
				)
			);
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Theme activated: {$positional[0]}",
			'action_label' => 'View Site',
			'url'          => home_url( '/' ),
			'admin_url'    => home_url( '/' ),
		) );
		/* translators: %s: theme name */
		return $this->success_result( array( 'message' => sprintf( __( 'Switched to theme \'%s\'.', 'vibe-ai' ), $theme->get( 'Name' ) ) ) );
	}


	private function handle_theme_get( $positional, $flags ) {
		if ( empty( $positional[0] ) ) {
			return $this->error_result( __( 'Theme slug required. Usage: theme get <slug>', 'vibe-ai' ) );
		}
		$theme = wp_get_theme( $positional[0] );
		if ( ! $theme->exists() ) {
			/* translators: %s: theme slug */
			return $this->error_result( sprintf( __( 'Theme \'%s\' not found.', 'vibe-ai' ), $positional[0] ) );
		}
		$parent = $theme->parent();
		$data   = array(
			'name'           => $theme->get( 'Name' ),
			'version'        => $theme->get( 'Version' ),
			'status'         => ( get_stylesheet() === $positional[0] ) ? 'active' : 'inactive',
			'parent_theme'   => $parent ? $parent->get( 'Name' ) : '',
			'template'       => $theme->get_template(),
			'stylesheet'     => $theme->get_stylesheet(),
			'template_dir'   => $theme->get_template_directory(),
			'stylesheet_dir' => $theme->get_stylesheet_directory(),
			'description'    => $theme->get( 'Description' ),
			'author'         => wp_strip_all_tags( $theme->get( 'Author' ) ),
			'tags'           => (array) $theme->get( 'Tags' ),
		);
		return $this->success_result( $this->filter_fields( array( $data ), $flags )[0] ?? $data );
	}


	private function handle_theme_install( $positional, $flags, $confirm_write = false ) {
		if ( empty( $positional[0] ) ) {
			return $this->error_result( __( 'Theme slug required.', 'vibe-ai' ) );
		}
		$slug = sanitize_key( $positional[0] );

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';

		$api_args = array( 'slug' => $slug, 'fields' => array( 'sections' => false ) );
		if ( ! empty( $flags['version'] ) ) {
			$api_args['version'] = $flags['version'];
		}
		$api = themes_api( 'theme_information', $api_args );
		if ( is_wp_error( $api ) ) {
			return $this->error_result( $api->get_error_message() );
		}

		if ( ! $confirm_write ) {
			return array(
				'exit_code'             => 0,
				'stdout'                => wp_json_encode( array(
					'name'          => $api->name,
					'slug'          => $api->slug,
					'version'       => $api->version,
					'requires'      => $api->requires ?? '',
					'requires_php'  => $api->requires_php ?? '',
					'rating'        => $api->rating ?? 0,
					'download_link' => $api->download_link,
				), JSON_PRETTY_PRINT ),
				'stderr'                => '',
				'requires_confirmation' => true,
				'message'               => sprintf(
					/* translators: 1: theme name, 2: theme version */
					__( 'Ready to install %1$s v%2$s. Call again with confirm_write=true to proceed.', 'vibe-ai' ),
					$api->name,
					$api->version
				),
			);
		}

		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		global $wpdb;
		if ( empty( $wpdb->dbname ) ) {
			// SQLite-integration sites (Studio, Playground) leave dbname empty.
			$wpdb->dbname = 'wordpress';
		}

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Theme_Upgrader( $skin );
		try {
			$result = $upgrader->install( $api->download_link );
		} catch ( \Throwable $e ) {
			$skin_messages = $skin->get_upgrade_messages();
			return $this->error_result(
				sprintf(
					/* translators: 1: theme name, 2: error message, 3: upgrader messages */
					__( 'Install of %1$s threw a fatal error: %2$s%3$s', 'vibe-ai' ),
					$api->name,
					$e->getMessage(),
					$skin_messages ? ' Upgrader log: ' . implode( ' / ', $skin_messages ) : ''
				)
			);
		}
		if ( is_wp_error( $result ) ) {
			return $this->error_result( $result->get_error_message() );
		}
		if ( ! $result ) {
			$messages = $skin->get_upgrade_messages();
			return $this->error_result( __( 'Install failed.', 'vibe-ai' ) . ( $messages ? ' Upgrader log: ' . implode( ' / ', $messages ) : '' ) );
		}

		$activated = false;
		if ( ! empty( $flags['activate'] ) ) {
			$theme      = $upgrader->theme_info();
			$stylesheet = $theme ? $theme->get_stylesheet() : $slug;
			switch_theme( $stylesheet );
			$activated = ( get_stylesheet() === $stylesheet );
			if ( ! $activated ) {
				return $this->error_result(
					sprintf(
						/* translators: 1: theme name, 2: theme version */
						__( 'Installed %1$s v%2$s, but activation did not take effect. The theme may not meet WP/PHP requirements. Activate it manually or run `theme activate`.', 'vibe-ai' ),
						$api->name,
						$api->version
					)
				);
			}
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Theme installed: {$slug}" . ( $activated ? ' (activated)' : '' ),
			'action_label' => 'Manage Themes',
			'admin_url'    => admin_url( 'themes.php' ),
		) );

		/* translators: 1: theme name, 2: theme version */
		$msg = sprintf( __( 'Installed %1$s v%2$s.', 'vibe-ai' ), $api->name, $api->version );
		if ( $activated ) {
			$msg .= ' ' . __( 'Theme activated.', 'vibe-ai' );
		}
		return $this->success_result( array( 'message' => $msg ) );
	}


	private function handle_theme_update( $positional, $flags, $confirm_write = false ) {
		if ( empty( $positional[0] ) ) {
			return $this->error_result( __( 'Theme slug required.', 'vibe-ai' ) );
		}
		$slug  = $positional[0];
		$theme = wp_get_theme( $slug );
		if ( ! $theme->exists() ) {
			/* translators: %s: theme slug */
			return $this->error_result( sprintf( __( 'Theme \'%s\' not found.', 'vibe-ai' ), $slug ) );
		}

		wp_update_themes();
		$update_data = get_site_transient( 'update_themes' );
		if ( ! isset( $update_data->response[ $slug ] ) ) {
			return $this->error_result( __( 'No update available for this theme.', 'vibe-ai' ) );
		}
		// Theme update entries are arrays (unlike plugins, which are objects).
		$update = (array) $update_data->response[ $slug ];

		if ( ! $confirm_write ) {
			return array(
				'exit_code'             => 0,
				'stdout'                => wp_json_encode( array(
					'name'            => $theme->get( 'Name' ),
					'current_version' => $theme->get( 'Version' ),
					'new_version'     => $update['new_version'] ?? '',
				), JSON_PRETTY_PRINT ),
				'stderr'                => '',
				'requires_confirmation' => true,
				'message'               => sprintf(
					/* translators: 1: theme name, 2: current version, 3: new version */
					__( 'Ready to update %1$s from %2$s to %3$s. Call again with confirm_write=true to proceed.', 'vibe-ai' ),
					$theme->get( 'Name' ),
					$theme->get( 'Version' ),
					$update['new_version'] ?? ''
				),
			);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Theme_Upgrader( $skin );
		$result   = $upgrader->upgrade( $slug );
		if ( is_wp_error( $result ) ) {
			return $this->error_result( $result->get_error_message() );
		}
		if ( ! $result ) {
			$messages = $skin->get_upgrade_messages();
			return $this->error_result( __( 'Update failed.', 'vibe-ai' ) . ( $messages ? ' Upgrader log: ' . implode( ' / ', $messages ) : '' ) );
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Theme updated: {$slug}",
			'action_label' => 'Manage Themes',
			'admin_url'    => admin_url( 'themes.php' ),
		) );

		return $this->success_result( array(
			'message' => sprintf(
				/* translators: 1: theme name, 2: new version */
				__( 'Updated %1$s to v%2$s.', 'vibe-ai' ),
				$theme->get( 'Name' ),
				$update['new_version'] ?? ''
			),
		) );
	}


	private function handle_theme_delete( $positional, $flags ) {
		if ( empty( $positional[0] ) ) {
			return $this->error_result( __( 'Theme slug required. Usage: theme delete <slug> [<slug>...]', 'vibe-ai' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/theme.php';

		$results = array();
		$ok      = 0;
		foreach ( $positional as $slug ) {
			$theme = wp_get_theme( $slug );
			if ( ! $theme->exists() ) {
				$results[] = array( 'target' => $slug, 'status' => 'error', 'error' => 'not found' );
				continue;
			}
			if ( get_stylesheet() === $slug ) {
				$results[] = array( 'target' => $slug, 'status' => 'error', 'error' => __( 'cannot delete the active theme', 'vibe-ai' ) );
				continue;
			}
			if ( get_template() === $slug ) {
				$results[] = array( 'target' => $slug, 'status' => 'error', 'error' => __( 'cannot delete the parent of the active child theme', 'vibe-ai' ) );
				continue;
			}
			$result = delete_theme( $slug );
			if ( is_wp_error( $result ) ) {
				$results[] = array( 'target' => $slug, 'status' => 'error', 'error' => $result->get_error_message() );
				continue;
			}
			if ( false === $result ) {
				$results[] = array( 'target' => $slug, 'status' => 'error', 'error' => 'filesystem error' );
				continue;
			}
			$ok++;
			$results[] = array( 'target' => $slug, 'status' => 'deleted' );
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => count( $positional ) > 1 ? "Themes deleted: {$ok}/" . count( $positional ) : "Theme deleted: {$positional[0]}",
			'action_label' => 'Manage Themes',
			'admin_url'    => admin_url( 'themes.php' ),
		) );

		if ( 1 === count( $positional ) ) {
			$only = $results[0];
			if ( 'error' === $only['status'] ) {
				/* translators: 1: theme slug, 2: error message */
				return $this->error_result( sprintf( __( 'Theme \'%1$s\': %2$s', 'vibe-ai' ), $only['target'], $only['error'] ) );
			}
			/* translators: %s: theme slug */
			return $this->success_result( array( 'message' => sprintf( __( 'Theme \'%s\' deleted.', 'vibe-ai' ), $positional[0] ) ) );
		}

		return $this->success_result( array(
			/* translators: 1: success count, 2: total */
			'message'   => sprintf( __( 'Deleted %1$d of %2$d themes.', 'vibe-ai' ), $ok, count( $positional ) ),
			'succeeded' => $ok,
			'total'     => count( $positional ),
			'results'   => $results,
		) );
	}


	private function handle_theme_mod_list( $positional, $flags ) {
		$mods    = get_theme_mods();
		$results = array();
		foreach ( (array) $mods as $key => $value ) {
			$results[] = array(
				'key'   => (string) $key,
				'value' => is_scalar( $value ) ? (string) $value : wp_json_encode( $value ),
			);
		}
		return $this->success_result( $results );
	}


	private function handle_theme_mod_set( $positional, $flags ) {
		$reject = $this->reject_unknown_flags( 'theme mod set', $flags, array() );
		if ( $reject ) {
			return $reject;
		}
		$mod = isset( $positional[0] ) ? (string) $positional[0] : '';
		if ( '' === $mod || ! isset( $positional[1] ) ) {
			return $this->error_result( __( 'Usage: theme mod set <mod> <value>', 'vibe-ai' ) );
		}
		if ( in_array( $mod, self::THEME_MOD_OPTION_KEYS, true ) ) {
			// set_theme_mod() on these writes where nothing reads: exit-0 no-op.
			/* translators: 1: key name, 2: key name */
			return $this->error_result( sprintf( __( '"%1$s" is a core option, not a theme mod; setting it as a theme mod would report success while changing nothing. Use `option update %2$s <value>` instead.', 'vibe-ai' ), $mod, $mod ) );
		}
		if ( ! in_array( $mod, self::THEME_MOD_SET_ALLOWED, true ) ) {
			/* translators: 1: theme mod name, 2: allowed mod names */
			return $this->error_result( sprintf( __( 'theme mod "%1$s" cannot be set via AI. Only scalar, non-markup mods are allowed: %2$s. Theme mods that hold HTML or scripts (header/footer code, custom HTML) must go through the code_snippet tool, which routes code through the approval panel; other options can be changed in the WordPress Customizer.', 'vibe-ai' ), $mod, implode( ', ', self::THEME_MOD_SET_ALLOWED ) ) );
		}
		$clean = $this->sanitize_theme_mod_value( $mod, (string) $positional[1] );
		if ( null === $clean ) {
			// Coercing bad input into a plausible value ("blank" -> "ba") is a
			// stored wrong-success the AI cannot detect; refuse instead.
			/* translators: 1: theme mod name, 2: expected format */
			return $this->error_result( sprintf( __( 'Invalid value for theme mod "%1$s". Expected %2$s.', 'vibe-ai' ), $mod, $this->theme_mod_expected_format( $mod ) ) );
		}
		set_theme_mod( $mod, $clean );
		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Theme mod set: {$mod}",
			'action_label' => 'Refresh',
		) );
		return $this->success_result( array(
			/* translators: 1: theme mod name, 2: stored value */
			'message' => sprintf( __( 'Theme mod %1$s set to %2$s.', 'vibe-ai' ), $mod, (string) $clean ),
		) );
	}


	/** Validate a safelisted theme mod to its expected scalar shape; null = refuse. */
	private function sanitize_theme_mod_value( $mod, $value ) {
		$value = (string) $value;
		switch ( $mod ) {
			case 'custom_logo':
			case 'custom_css_post_id':
				return ctype_digit( $value ) ? (int) $value : null;
			case 'header_textcolor':
				// 'blank' is core's sentinel for hiding header text (display_header_text()).
				if ( 'blank' === $value ) {
					return 'blank';
				}
				// Fall through to hex validation.
			case 'background_color':
				return preg_match( '/^#?([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value ) ? ltrim( $value, '#' ) : null;
			case 'background_image':
			case 'header_image':
				$url = esc_url_raw( $value );
				return '' !== $url ? $url : null;
			default:
				return sanitize_text_field( $value );
		}
	}


	/** Human-readable expected format per safelisted mod, for the refusal text. */
	private function theme_mod_expected_format( $mod ) {
		switch ( $mod ) {
			case 'custom_logo':
			case 'custom_css_post_id':
				return __( 'a numeric attachment/post ID', 'vibe-ai' );
			case 'header_textcolor':
				return __( 'a 3- or 6-digit hex color (with or without #), or "blank" to hide header text', 'vibe-ai' );
			case 'background_color':
				return __( 'a 3- or 6-digit hex color, with or without #', 'vibe-ai' );
			case 'background_image':
			case 'header_image':
				return __( 'a valid http(s) URL', 'vibe-ai' );
			default:
				return __( 'a plain scalar value', 'vibe-ai' );
		}
	}

}
