<?php
/**
 * WP-CLI emulator: plugin * handlers and plugin path helpers.
 *
 * Extracted from class-wpvibe-cli.php (mechanical split; no behavior change).
 */

defined( 'ABSPATH' ) || exit;

trait WPVibe_CLI_Plugin {


	// ------------------------------------------------------------------
	// Read Handlers
	// ------------------------------------------------------------------

	private function handle_plugin_list( $positional, $flags ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Populate update availability. Refresh from WP.org when the caller asks
		// for update info (--update filter or update/update_version in --fields) or
		// the cache is empty; otherwise use the cached result to avoid a network hit.
		$wants_updates = isset( $flags['update'] )
			|| ( ! empty( $flags['fields'] ) && preg_match( '/\bupdate(_version)?\b/', $flags['fields'] ) );
		$update_cache = get_site_transient( 'update_plugins' );
		if ( $wants_updates || ! is_object( $update_cache ) || empty( $update_cache->checked ) ) {
			wp_update_plugins();
			$update_cache = get_site_transient( 'update_plugins' );
		}
		$responses = ( is_object( $update_cache ) && ! empty( $update_cache->response ) ) ? $update_cache->response : array();

		$all     = get_plugins();
		$results = array();
		foreach ( $all as $file => $data ) {
			$active = is_plugin_active( $file );
			$status = $active ? 'active' : 'inactive';
			if ( isset( $flags['status'] ) && $flags['status'] !== $status ) {
				continue;
			}
			$has_update = isset( $responses[ $file ] ) && ! empty( $responses[ $file ]->new_version );
			if ( isset( $flags['update'] ) && 'available' === $flags['update'] && ! $has_update ) {
				continue;
			}
			$results[] = array(
				'name'           => $data['Name'],
				'status'         => $status,
				'version'        => $data['Version'],
				'update'         => $has_update ? 'available' : 'none',
				'update_version' => $has_update ? $responses[ $file ]->new_version : '',
				'file'           => $file,
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

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		try {
			deactivate_plugins( $file );
		} catch ( \Throwable $e ) {
			return $this->error_result(
				sprintf(
					/* translators: 1: plugin slug, 2: error message */
					__( 'Deactivation of \'%1$s\' threw a fatal: %2$s The plugin\'s deactivation hook errored.', 'vibe-ai' ),
					$positional[0],
					$e->getMessage()
				)
			);
		}

		// Verify the deactivation took effect.
		if ( is_plugin_active( $file ) ) {
			return $this->error_result(
				sprintf(
					/* translators: %s: plugin slug */
					__( 'Plugin \'%s\' is still active after deactivate. A deactivation hook may have re-activated it, or the plugin is network-activated on a multisite install.', 'vibe-ai' ),
					$positional[0]
				)
			);
		}

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

		$reject = $this->reject_unknown_flags( 'plugin install', $flags, array( 'version', 'activate', 'force' ), array(
			'activate_network' => __( 'Multisite network activation is not emulated.', 'vibe-ai' ),
			'insecure'         => __( 'Disabling release-signature checks is not emulated.', 'vibe-ai' ),
		) );
		if ( $reject ) {
			return $reject;
		}

		// The force-overwrite path is approval-gated in classify_destructive();
		// the browser dry-run already previews the version change, so an
		// approved run executes without a second confirmation round-trip.
		$confirm_write = $confirm_write || $this->skip_destructive;

		$state         = $this->plugin_install_replace_state( $positional[0], $flags );
		$slug          = $state['slug'];
		$existing_file = $state['file'];

		// Self-guard on every install path, not just update: a force-replace of
		// vibe-ai deletes the code serving this request (opaque 500, never
		// completes), and the already-installed guidance below must not
		// recommend a --force that would do the same.
		if ( 'vibe-ai' === $slug || ( $existing_file && plugin_basename( WPVIBE_PLUGIN_DIR . 'vibe-ai.php' ) === $existing_file ) ) {
			return $this->error_result( __( 'WPVibe cannot replace its own files over its own connection (the request would delete the code serving it). Update or reinstall WPVibe from the Plugins screen in wp-admin.', 'vibe-ai' ) );
		}

		if ( $existing_file && empty( $flags['force'] ) ) {
			$version = isset( $state['installed']['Version'] ) ? $state['installed']['Version'] : '?';
			return $this->error_result( sprintf(
				/* translators: 1: plugin slug, 2: installed version */
				__( 'Plugin \'%1$s\' is already installed (v%2$s). Use `plugin update %1$s` to update it, or `plugin install %1$s --version=<version> --force` to replace it with a specific version (rollback/downgrade; replacing an existing install needs approval).', 'vibe-ai' ),
				$slug,
				$version
			) );
		}

		// Canonical admin-context bootstrap. Plugin_Upgrader is meant to run
		// from wp-admin, so calling it from REST/CLI needs the same includes
		// that wp-admin loads first.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';

		$api_fields = array(
			'short_description' => true,
			'sections'          => false,
			'icons'             => false,
			'banners'           => false,
		);
		$api_args = array( 'slug' => $slug, 'fields' => $api_fields );

		$api = plugins_api( 'plugin_information', $api_args );
		if ( is_wp_error( $api ) ) {
			return $this->error_result( $api->get_error_message() );
		}

		// plugins_api ignores a version argument and always describes the latest
		// release, so a pinned version must rewrite the download URL to the
		// versioned zip and verify it exists (WP-CLI's alter_api_response).
		$requested = ! empty( $flags['version'] ) ? trim( (string) $flags['version'] ) : '';
		if ( 'dev' === $requested || 'trunk' === $requested ) {
			return $this->error_result( __( 'Installing development/trunk builds is not emulated; name a released version, e.g. --version=1.2.3.', 'vibe-ai' ) );
		}
		// The version is interpolated into the download URL path; a slash or query
		// char would address a different file on downloads.wordpress.org.
		if ( '' !== $requested && ! preg_match( '/^[A-Za-z0-9._-]+$/', $requested ) ) {
			return $this->error_result( sprintf(
				/* translators: %s: the rejected version string */
				__( '"%s" is not a valid plugin version string.', 'vibe-ai' ),
				$requested
			) );
		}
		if ( '' !== $requested && $requested !== $api->version ) {
			$base = str_replace( $api->slug . '.' . $api->version . '.zip', '', $api->download_link );
			if ( $base === $api->download_link ) {
				$base = 'https://downloads.wordpress.org/plugin/';
			}
			$versioned_link = $base . $api->slug . '.' . $requested . '.zip';
			$head           = wp_remote_head( $versioned_link, array( 'timeout' => 10, 'redirection' => 3 ) );
			$head_code      = is_wp_error( $head ) ? 0 : (int) wp_remote_retrieve_response_code( $head );
			if ( 200 !== $head_code ) {
				return $this->error_result( sprintf(
					/* translators: 1: requested version, 2: HTTP status or transport error */
					__( "Can't find the requested plugin's version %1\$s in the WordPress.org plugin repository (%2\$s). Available versions are listed on the plugin's page under Advanced View.", 'vibe-ai' ),
					$requested,
					is_wp_error( $head ) ? $head->get_error_message() : 'HTTP ' . $head_code
				) );
			}
			$api->download_link = $versioned_link;
			$api->version       = $requested;
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

		// SQLite-integration sites (WordPress Studio, Playground) often don't
		// define DB_NAME in wp-config, so $wpdb->dbname is '' and the SQLite
		// driver bails when the upgrader triggers an information_schema query.
		// Any non-empty label is fine; SQLite doesn't use it as a real db name.
		global $wpdb;
		if ( empty( $wpdb->dbname ) ) {
			$wpdb->dbname = 'wordpress';
		}

		$replacing   = $state['replacing'];
		$old_version = null;
		$was_active  = false;
		if ( $replacing && $existing_file ) {
			$old_version = isset( $state['installed']['Version'] ) ? $state['installed']['Version'] : null;
			$was_active  = $state['active'];
		}

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );

		try {
			// overwrite_package makes core clear the destination instead of
			// aborting on an existing directory (same end state as WP-CLI's
			// DestructivePluginUpgrader) — the emulated rollback/downgrade path.
			$result = $upgrader->install( $api->download_link, array( 'overwrite_package' => ! empty( $flags['force'] ) ) );
		} catch ( \Throwable $e ) {
			$skin_messages = $skin->get_upgrade_messages();
			return $this->error_result(
				sprintf(
					/* translators: 1: plugin name, 2: error message, 3: upgrader messages */
					__( 'Install of %1$s threw a fatal error: %2$s%3$s', 'vibe-ai' ),
					$api->name,
					$e->getMessage(),
					$skin_messages ? ' Upgrader log: ' . implode( ' / ', $skin_messages ) : ''
				)
			);
		}

		if ( is_wp_error( $result ) ) {
			$skin_messages = $skin->get_upgrade_messages();
			return $this->error_result(
				$result->get_error_message() . ( $skin_messages ? ' Upgrader log: ' . implode( ' / ', $skin_messages ) : '' )
			);
		}
		if ( ! $result ) {
			$messages = $skin->get_upgrade_messages();
			return $this->error_result( __( 'Install failed.', 'vibe-ai' ) . ( $messages ? ' Upgrader log: ' . implode( ' / ', $messages ) : '' ) );
		}

		// Optionally activate (matches real WP-CLI --activate flag).
		// Activation errors must surface to the caller: a failed activation
		// hook (e.g. plugin's dbDelta incompatible with SQLite) leaves the
		// plugin installed but inactive, and silently reporting success would
		// mislead the AI.
		$activated        = false;
		$activation_error = null;
		if ( ! empty( $flags['activate'] ) ) {
			$plugin_file = $upgrader->plugin_info();
			if ( ! $plugin_file ) {
				$activation_error = __( 'Could not determine the installed plugin file path.', 'vibe-ai' );
			} else {
				try {
					$activate_result = activate_plugin( $plugin_file );
				} catch ( \Throwable $e ) {
					$activate_result = new WP_Error( 'activation_fatal', $e->getMessage(), WPVibe_Error_Contract::data( 'wp_core', false ) );
				}
				if ( is_wp_error( $activate_result ) ) {
					$activation_error = $activate_result->get_error_message();
				} else {
					$activated = true;
				}
			}
		}

		// If --activate was requested but activation failed, report as a
		// failed install. The plugin file is on disk but the user did not get
		// the outcome they asked for.
		if ( ! empty( $flags['activate'] ) && ! $activated ) {
			return $this->error_result(
				sprintf(
					/* translators: 1: plugin name, 2: plugin version, 3: activation error */
					__( 'Installed %1$s v%2$s, but activation failed: %3$s The plugin is on disk but inactive. Activate it manually via wp-admin, or check whether the plugin is compatible with this environment (e.g. SQLite vs MySQL).', 'vibe-ai' ),
					$api->name,
					$api->version,
					$activation_error ?: __( 'unknown error', 'vibe-ai' )
				)
			);
		}

		// Post-condition, not assumption: replacing an active plugin keeps it
		// active only while the main file path is unchanged. If the new version
		// renamed it, active_plugins holds a stale path — re-activate the new one.
		$active_note = '';
		if ( $replacing && $was_active ) {
			$new_file = $upgrader->plugin_info();
			if ( $new_file && $new_file !== $existing_file ) {
				// clear_destination only empties the zip's target directory, so a
				// top-level single-file plugin (hello.php) survives the replace.
				// Drop its active_plugins entry BEFORE activating the new file, or
				// both copies load and a duplicate-function fatal takes the site down.
				deactivate_plugins( $existing_file, true );
				try {
					$reactivate = activate_plugin( $new_file );
				} catch ( \Throwable $e ) {
					$reactivate = new WP_Error( 'activation_fatal', $e->getMessage() );
				}
				$active_note = is_wp_error( $reactivate )
					/* translators: %s: activation error */
					? ' ' . sprintf( __( 'The plugin was active, its main file changed in this version, and re-activation failed: %s Activate it manually in wp-admin.', 'vibe-ai' ), $reactivate->get_error_message() )
					: ' ' . __( 'The plugin remains active (main file changed; re-activated).', 'vibe-ai' );
			} else {
				$active_note = ' ' . __( 'The plugin remains active.', 'vibe-ai' );
			}
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => $replacing
				? "Plugin replaced: {$slug} v" . ( $old_version ?: '?' ) . " -> v{$api->version}"
				: "Plugin installed: {$slug}" . ( $activated ? ' (activated)' : '' ),
			'action_label' => 'Manage Plugins',
			'admin_url'    => admin_url( 'plugins.php' ),
		) );

		if ( $replacing ) {
			$msg = sprintf(
				/* translators: 1: plugin name, 2: previously installed version, 3: newly installed version */
				__( 'Replaced %1$s v%2$s with v%3$s.', 'vibe-ai' ),
				$api->name,
				$old_version ?: '?',
				$api->version
			) . $active_note;
		} else {
			$msg = sprintf(
				/* translators: 1: plugin name, 2: plugin version */
				__( 'Installed %1$s v%2$s.', 'vibe-ai' ),
				$api->name,
				$api->version
			);
		}
		if ( $activated ) {
			$msg .= ' ' . __( 'Plugin activated.', 'vibe-ai' );
		}

		return $this->success_result( array( 'message' => $msg ) );
	}


	private function handle_plugin_update( $positional, $flags, $confirm_write = false ) {
		$update_all = ! empty( $flags['all'] );
		$dry_run    = ! empty( $flags['dry_run'] );

		if ( empty( $positional ) && ! $update_all ) {
			return $this->error_result( __( 'Plugin slug required. Usage: plugin update <slug>... | --all [--exclude=<slugs>] [--dry-run]', 'vibe-ai' ) );
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all       = get_plugins();
		$self_file = plugin_basename( WPVIBE_PLUGIN_DIR . 'vibe-ai.php' );
		$single    = ! $update_all && 1 === count( $positional );

		if ( $single ) {
			$file = $this->resolve_plugin_file( $positional[0] );
			if ( ! $file ) {
				/* translators: %s: plugin slug */
				return $this->error_result( sprintf( __( 'Plugin \'%s\' not found.', 'vibe-ai' ), $positional[0] ) );
			}

			// Self-update replaces this plugin's files while they serve the request: fatals with a 500 and never applies.
			if ( $self_file === $file ) {
				return $this->error_result( __( 'WPVibe cannot update itself over its own connection (the update would replace the plugin files serving this request). Update it from the Plugins screen in wp-admin, or enable auto-updates for it there.', 'vibe-ai' ) );
			}
		}

		// Checked after the self-update guard so `plugin update vibe-ai --version=x`
		// still reports the self-update refusal, which is the more useful message.
		$reject = $this->reject_unknown_flags( 'plugin update', $flags, array( 'all', 'exclude', 'dry_run' ), array(
			'version' => __( 'Version pinning on update is not emulated; this always installs the latest available version. To install a specific version (rollback or downgrade), run `plugin install <slug> --version=<version> --force` — replacing an existing install pauses for browser approval.', 'vibe-ai' ),
			'minor'   => __( 'Constraining to minor releases is not emulated; this always installs the latest available version.', 'vibe-ai' ),
			'patch'   => __( 'Constraining to patch releases is not emulated; this always installs the latest available version.', 'vibe-ai' ),
		) );
		if ( $reject ) {
			return $reject;
		}

		wp_update_plugins();
		$update_data = get_site_transient( 'update_plugins' );
		$available   = ( is_object( $update_data ) && ! empty( $update_data->response ) ) ? $update_data->response : array();

		$self_skip_reason = __( 'WPVibe cannot update itself over its own connection. Update it from the Plugins screen in wp-admin.', 'vibe-ai' );
		$targets          = array();
		$skipped          = array();

		if ( $single ) {
			if ( ! isset( $available[ $file ] ) ) {
				return $this->error_result( __( 'No update available for this plugin.', 'vibe-ai' ) );
			}
			$targets[ $file ] = $available[ $file ];
		} elseif ( $update_all ) {
			$exclude = array_filter( array_map( 'trim', explode( ',', (string) ( $flags['exclude'] ?? '' ) ) ) );
			foreach ( $available as $file => $update ) {
				if ( ! isset( $all[ $file ] ) ) {
					continue;
				}
				$slug = ( ! empty( $update->slug ) ) ? $update->slug : dirname( $file );
				if ( in_array( $slug, $exclude, true ) || in_array( dirname( $file ), $exclude, true ) ) {
					$skipped[] = array( 'name' => $slug, 'status' => 'skipped', 'reason' => 'excluded' );
					continue;
				}
				if ( $self_file === $file ) {
					$skipped[] = array( 'name' => $slug, 'status' => 'skipped', 'reason' => $self_skip_reason );
					continue;
				}
				$targets[ $file ] = $update;
			}
		} else {
			foreach ( $positional as $slug ) {
				$file = $this->resolve_plugin_file( $slug );
				if ( ! $file ) {
					$skipped[] = array( 'name' => $slug, 'status' => 'error', 'reason' => 'not found' );
				} elseif ( $self_file === $file ) {
					$skipped[] = array( 'name' => $slug, 'status' => 'skipped', 'reason' => $self_skip_reason );
				} elseif ( ! isset( $available[ $file ] ) ) {
					$skipped[] = array( 'name' => $slug, 'status' => 'skipped', 'reason' => 'no update available' );
				} else {
					$targets[ $file ] = $available[ $file ];
				}
			}
		}

		$preview = array();
		foreach ( $targets as $file => $update ) {
			$preview[] = array(
				'name'            => $all[ $file ]['Name'],
				'current_version' => $all[ $file ]['Version'],
				'new_version'     => $update->new_version,
				'slug'            => ( ! empty( $update->slug ) ) ? $update->slug : dirname( $file ),
			);
		}

		if ( $dry_run ) {
			return $this->success_result( array( 'dry_run' => true, 'updates_available' => $preview, 'skipped' => $skipped ) );
		}

		if ( empty( $targets ) ) {
			return $this->success_result( array( 'message' => __( 'No plugin updates available.', 'vibe-ai' ), 'skipped' => $skipped ) );
		}

		// Phase 1: Return info and require confirmation.
		if ( ! $confirm_write ) {
			if ( $single ) {
				return array(
					'exit_code'             => 0,
					'stdout'                => wp_json_encode( $preview[0], JSON_PRETTY_PRINT ),
					'stderr'                => '',
					'requires_confirmation' => true,
					'message'               => sprintf(
						/* translators: 1: plugin name, 2: current version, 3: new version */
						__( 'Ready to update %1$s from %2$s to %3$s. Call again with confirm_write=true to proceed.', 'vibe-ai' ),
						$preview[0]['name'],
						$preview[0]['current_version'],
						$preview[0]['new_version']
					),
				);
			}
			return array(
				'exit_code'             => 0,
				'stdout'                => wp_json_encode( array( 'updates' => $preview, 'skipped' => $skipped ), JSON_PRETTY_PRINT ),
				'stderr'                => '',
				'requires_confirmation' => true,
				'message'               => sprintf(
					/* translators: 1: number of plugins, 2: comma-separated plugin names */
					__( 'Ready to update %1$d plugins: %2$s. Call again with confirm_write=true to proceed.', 'vibe-ai' ),
					count( $preview ),
					implode( ', ', array_column( $preview, 'name' ) )
				),
			);
		}

		// Phase 2: Actual update.
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );

		if ( $single ) {
			$file = array_keys( $targets )[0];
			// upgrade() silently deactivates the plugin (deactivate_plugin_before_upgrade) and
			// leaves reactivation to the caller; bulk_upgrade() preserves activation, which is
			// why core's own AJAX updater uses it for single plugins too.
			$was_active = is_plugin_active( $file );
			$bulk       = $upgrader->bulk_upgrade( array( $file ) );
			$result     = ( is_array( $bulk ) && isset( $bulk[ $file ] ) ) ? $bulk[ $file ] : null;

			if ( is_wp_error( $result ) ) {
				return $this->error_result( $result->get_error_message() );
			}
			if ( empty( $result ) ) {
				return $this->error_result( __( 'Update failed. The plugin files were not replaced; the installed version is unchanged.', 'vibe-ai' ) );
			}

			WPVibe_Change_Tracker::mark( array(
				'summary'      => "Plugin updated: {$positional[0]}",
				'action_label' => 'Manage Plugins',
				'admin_url'    => admin_url( 'plugins.php' ),
			) );

			$is_active = is_plugin_active( $file );
			$payload   = array(
				'message' => sprintf(
					/* translators: 1: plugin name, 2: new version */
					__( 'Updated %1$s to v%2$s.', 'vibe-ai' ),
					$preview[0]['name'],
					$preview[0]['new_version']
				),
				'status'  => $is_active ? 'active' : 'inactive',
			);
			if ( $was_active && ! $is_active ) {
				$payload['warning'] = __( 'The plugin was active before this update but is inactive now. Reactivate it with `plugin activate <slug>` and check the site for fatal errors.', 'vibe-ai' );
			}
			return $this->success_result( $payload );
		}

		// Same path as wp-admin's bulk update (maintenance mode around the run).
		$bulk    = $upgrader->bulk_upgrade( array_keys( $targets ) );
		$results = array();
		$updated = array();
		foreach ( $targets as $file => $update ) {
			$row = array(
				'name'        => $all[ $file ]['Name'],
				'old_version' => $all[ $file ]['Version'],
				'new_version' => $update->new_version,
			);
			$result = is_array( $bulk ) && isset( $bulk[ $file ] ) ? $bulk[ $file ] : null;
			if ( is_wp_error( $result ) ) {
				$row['status'] = 'error: ' . $result->get_error_message();
			} elseif ( empty( $result ) ) {
				$row['status'] = 'error: update failed';
			} else {
				$row['status'] = 'updated';
				$updated[]     = ( ! empty( $update->slug ) ) ? $update->slug : dirname( $file );
			}
			$results[] = $row;
		}

		if ( $updated ) {
			WPVibe_Change_Tracker::mark( array(
				'summary'      => 'Plugins updated: ' . implode( ', ', $updated ),
				'action_label' => 'Manage Plugins',
				'admin_url'    => admin_url( 'plugins.php' ),
			) );
		}

		$payload = array(
			/* translators: 1: updated count, 2: total count */
			'message' => sprintf( __( 'Updated %1$d of %2$d plugins.', 'vibe-ai' ), count( $updated ), count( $targets ) ),
			'results' => $results,
		);
		if ( $skipped ) {
			$payload['skipped'] = $skipped;
		}
		if ( count( $updated ) === count( $targets ) ) {
			return $this->success_result( $payload );
		}
		return array(
			'exit_code' => 1,
			'stdout'    => wp_json_encode( $payload, JSON_PRETTY_PRINT ),
			'stderr'    => __( 'Some plugin updates failed.', 'vibe-ai' ),
		);
	}


	private function handle_plugin_uninstall( $positional, $flags ) {
		if ( empty( $positional[0] ) ) {
			return $this->error_result( __( 'Plugin slug required. Usage: plugin uninstall <slug> [<slug>...]', 'vibe-ai' ) );
		}

		if ( ! function_exists( 'delete_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$slugs   = $positional;
		$results = array();
		$ok      = 0;
		foreach ( $slugs as $slug ) {
			$file = $this->resolve_plugin_file( $slug );
			if ( ! $file ) {
				$results[] = array( 'target' => $slug, 'status' => 'error', 'error' => 'not found' );
				continue;
			}
			if ( is_plugin_active( $file ) ) {
				deactivate_plugins( $file );
			}
			$result = delete_plugins( array( $file ) );
			if ( is_wp_error( $result ) ) {
				$results[] = array( 'target' => $slug, 'status' => 'error', 'error' => $result->get_error_message() );
				continue;
			}
			if ( false === $result ) {
				$results[] = array( 'target' => $slug, 'status' => 'error', 'error' => 'filesystem error' );
				continue;
			}
			$ok++;
			$results[] = array( 'target' => $slug, 'status' => 'uninstalled' );
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => count( $slugs ) > 1 ? "Plugins uninstalled: {$ok}/" . count( $slugs ) : "Plugin uninstalled: {$slugs[0]}",
			'action_label' => 'Manage Plugins',
			'admin_url'    => admin_url( 'plugins.php' ),
		) );

		if ( 1 === count( $slugs ) ) {
			$only = $results[0];
			if ( 'error' === $only['status'] ) {
				/* translators: 1: plugin slug, 2: error message */
				return $this->error_result( sprintf( __( 'Plugin \'%1$s\': %2$s', 'vibe-ai' ), $only['target'], $only['error'] ) );
			}
			/* translators: %s: plugin slug */
			return $this->success_result( array( 'message' => sprintf( __( 'Plugin \'%s\' uninstalled.', 'vibe-ai' ), $slugs[0] ) ) );
		}

		return $this->success_result( array(
			/* translators: 1: success count, 2: total */
			'message'   => sprintf( __( 'Uninstalled %1$d of %2$d plugins.', 'vibe-ai' ), $ok, count( $slugs ) ),
			'succeeded' => $ok,
			'total'     => count( $slugs ),
			'results'   => $results,
		) );
	}


	private function handle_plugin_verify_checksums( $positional, $flags ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all   = get_plugins();
		$slugs = array();
		if ( isset( $flags['all'] ) ) {
			foreach ( $all as $file => $data ) {
				$slugs[ $this->plugin_slug_from_file( $file ) ] = $file;
			}
		} else {
			if ( empty( $positional ) ) {
				return $this->error_result( __( 'Plugin slug required (or pass --all).', 'vibe-ai' ) );
			}
			foreach ( $positional as $slug ) {
				$file = $this->resolve_plugin_file( $slug );
				if ( ! $file ) {
					/* translators: %s: plugin slug */
					return $this->error_result( sprintf( __( 'Plugin \'%s\' not found.', 'vibe-ai' ), $slug ) );
				}
				$slugs[ $this->plugin_slug_from_file( $file ) ] = $file;
			}
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$results = array();
		foreach ( $slugs as $slug => $file ) {
			$version  = $all[ $file ]['Version'] ?? '';
			// Plugin checksums live on downloads.wordpress.org, not api.wordpress.org.
			$response = wp_remote_get(
				'https://downloads.wordpress.org/plugin-checksums/' . rawurlencode( $slug ) . '/' . rawurlencode( $version ) . '.json',
				array( 'timeout' => 15 )
			);
			$body  = is_wp_error( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );
			$files = is_array( $body ) && ! empty( $body['files'] ) && is_array( $body['files'] ) ? $body['files'] : null;
			if ( ! $files ) {
				$results[] = array(
					'plugin'   => $slug,
					'version'  => $version,
					'verified' => null,
					'message'  => __( 'Checksums not available (not hosted on WordPress.org, or a premium/custom plugin).', 'vibe-ai' ),
				);
				continue;
			}

			$is_single  = ( '.' === dirname( $file ) );
			$root       = trailingslashit( WP_PLUGIN_DIR ) . ( $is_single ? '' : trailingslashit( dirname( $file ) ) );
			$strict     = ! empty( $flags['strict'] );
			$mismatched = array();
			$missing    = array();
			foreach ( $files as $relpath => $hashes ) {
				// WP.org regenerates readmes after release, so legitimate installs
				// diff there; real WP-CLI skips them unless --strict.
				if ( ! $strict && $this->is_soft_change_file( $relpath ) ) {
					continue;
				}
				$expected = isset( $hashes['md5'] ) ? (array) $hashes['md5'] : array();
				if ( empty( $expected ) ) {
					continue;
				}
				$path = $root . $relpath;
				if ( ! file_exists( $path ) ) {
					$missing[] = $relpath;
					continue;
				}
				if ( ! in_array( md5_file( $path ), $expected, true ) ) {
					$mismatched[] = $relpath;
				}
			}

			// Files on disk but absent from the manifest ("File was added" in
			// real WP-CLI) — added files are the classic malware injection shape.
			$added = array();
			$disk  = $is_single ? array( basename( $file ) ) : $this->collect_files_recursive( $root, null );
			if ( is_array( $disk ) ) {
				foreach ( $disk as $relpath ) {
					if ( ! array_key_exists( $relpath, $files ) ) {
						$added[] = $relpath;
					}
				}
				sort( $added );
			}

			$verified  = empty( $mismatched ) && empty( $missing ) && empty( $added );
			$results[] = array(
				'plugin'     => $slug,
				'version'    => $version,
				'verified'   => $verified,
				'mismatched' => $this->cap_file_list( $mismatched ),
				'missing'    => $this->cap_file_list( $missing ),
				'added'      => $this->cap_file_list( $added ),
			);
		}
		return $this->success_result( $results );
	}


	private function plugin_slug_from_file( $file ) {
		$dir = dirname( $file );
		return '.' === $dir ? basename( $file, '.php' ) : $dir;
	}


	/**
	 * The one derivation of "does plugin install --force replace an existing
	 * install". Both the approval gate and the install handler consume this;
	 * computing it twice let the two conditions drift (issue #30).
	 */
	private function plugin_install_replace_state( $slug_raw, $flags ) {
		$slug  = sanitize_key( $slug_raw );
		$state = array(
			'slug'       => $slug,
			'file'       => null,
			'dir_exists' => false,
			'replacing'  => false,
			'installed'  => array(),
			'active'     => false,
		);
		// An empty sanitized slug would make the dir check test the plugins root.
		if ( '' === $slug ) {
			return $state;
		}
		$file          = $this->resolve_plugin_file( $slug );
		$state['file'] = $file;
		// clear_destination deletes the slug directory even when it is not a
		// get_plugins()-parseable install (broken/partial dir) — still a replace.
		$state['dir_exists'] = defined( 'WP_PLUGIN_DIR' ) && is_dir( trailingslashit( WP_PLUGIN_DIR ) . $slug );
		$state['replacing']  = ! empty( $flags['force'] ) && ( $file || $state['dir_exists'] );
		if ( $file ) {
			$all                = get_plugins();
			$state['installed'] = isset( $all[ $file ] ) ? $all[ $file ] : array();
			$state['active']    = is_plugin_active( $file );
		}
		return $state;
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
