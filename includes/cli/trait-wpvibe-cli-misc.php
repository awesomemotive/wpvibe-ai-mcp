<?php
/**
 * WP-CLI emulator: user reads/deletes, menus, rewrite, cron, maintenance, leftovers.
 *
 * Extracted from class-wpvibe-cli.php (mechanical split; no behavior change).
 */

defined( 'ABSPATH' ) || exit;

trait WPVibe_CLI_Misc {


	private function handle_user_list( $positional, $flags ) {
		$reject = $this->reject_unknown_flags( 'user list', $flags, array( 'role', 'number', 'fields', 'format' ), array(
			'search'  => __( 'Filter the returned rows yourself, or use rest_api GET /wp/v2/users?search=<term>.', 'vibe-ai' ),
			'include' => __( 'Fetch the ids individually with `user get`, or use rest_api GET /wp/v2/users?include=<ids>.', 'vibe-ai' ),
			'exclude' => __( 'Filter the returned rows yourself, or use rest_api GET /wp/v2/users?exclude=<ids>.', 'vibe-ai' ),
			'orderby' => __( 'Rows come back ordered by ID; sort them yourself, or use rest_api GET /wp/v2/users?orderby=<field>.', 'vibe-ai' ),
			'field'   => __( 'Use --fields=<name> and read that column from the rows.', 'vibe-ai' ),
			'network' => __( 'Multisite network scoping is not emulated; this lists users of the current site only.', 'vibe-ai' ),
		) );
		if ( $reject ) {
			return $reject;
		}
		$format_reject = $this->reject_unsupported_format( 'user list', $flags );
		if ( $format_reject ) {
			return $format_reject;
		}
		// Default caps the row count; say so, because a silently truncated list
		// feeding a bulk operation targets the wrong set.
		$args = array( 'number' => 100 );
		if ( isset( $flags['role'] ) )   $args['role']   = $flags['role'];
		if ( isset( $flags['number'] ) ) $args['number'] = min( (int) $flags['number'], 1000 );
		$users   = get_users( $args );
		$results = array();
		foreach ( $users as $user ) {
			$results[] = array(
				'ID'              => $user->ID,
				'user_login'      => $user->user_login,
				'display_name'    => $user->display_name,
				'user_email'      => $user->user_email,
				'roles'           => implode( ',', $user->roles ),
				'user_registered' => $user->user_registered,
			);
		}
		return $this->success_result(
			$this->format_rows( $results, $flags, 'ID' ),
			$this->truncation_notice( $results, (int) $args['number'], 'user list' )
		);
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
		// Not the raw option: core strips the legacy array_version key and
		// applies the sidebars_widgets filter (what the theme actually renders).
		$sidebars = wp_get_sidebars_widgets();
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


	private function handle_user_delete( $positional, $flags ) {
		if ( empty( $positional[0] ) ) {
			return $this->error_result( __( 'User identifier required. Usage: user delete <id|login|email> [<id>...] [--reassign=<user>]', 'vibe-ai' ) );
		}

		$reassign = null;
		if ( ! empty( $flags['reassign'] ) ) {
			$ra = $this->resolve_user( $flags['reassign'] );
			if ( ! $ra ) {
				/* translators: %s: user identifier */
				return $this->error_result( sprintf( __( 'Reassign target \'%s\' not found.', 'vibe-ai' ), $flags['reassign'] ) );
			}
			$reassign = $ra->ID;
		}

		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}

		// Lockout protection: never delete the last administrator.
		$admin_ids = array_map( 'intval', (array) get_users( array( 'role' => 'administrator', 'fields' => 'ID', 'number' => -1 ) ) );
		$admins_remaining = count( $admin_ids );

		$idents  = $positional;
		$results = array();
		$ok      = 0;
		foreach ( $idents as $ident ) {
			$user = $this->resolve_user( $ident );
			if ( ! $user ) {
				$results[] = array( 'target' => $ident, 'status' => 'error', 'error' => 'not found' );
				continue;
			}
			if ( in_array( (int) $user->ID, $admin_ids, true ) ) {
				if ( $admins_remaining <= 1 ) {
					$results[] = array( 'target' => $user->user_login, 'id' => $user->ID, 'status' => 'error', 'error' => __( 'refused: this is the last administrator (lockout protection)', 'vibe-ai' ) );
					continue;
				}
				$admins_remaining--;
			}
			if ( wp_delete_user( $user->ID, $reassign ) ) {
				$ok++;
				$results[] = array( 'target' => $user->user_login, 'id' => $user->ID, 'status' => 'deleted' );
			} else {
				$results[] = array( 'target' => $user->user_login, 'id' => $user->ID, 'status' => 'error', 'error' => 'delete failed' );
			}
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => count( $idents ) > 1 ? "Users deleted: {$ok}/" . count( $idents ) : "User deleted: {$results[0]['target']}",
			'action_label' => 'Manage Users',
			'admin_url'    => admin_url( 'users.php' ),
		) );

		if ( 1 === count( $idents ) ) {
			$only = $results[0];
			if ( 'error' === $only['status'] ) {
				/* translators: 1: user identifier, 2: error message */
				return $this->error_result( sprintf( __( 'User \'%1$s\': %2$s', 'vibe-ai' ), $only['target'], $only['error'] ) );
			}
			return $this->success_result( array(
				/* translators: 1: user login, 2: user ID */
				'message'       => sprintf( __( 'Deleted user \'%1$s\' (#%2$d).', 'vibe-ai' ), $only['target'], $only['id'] ),
				'reassigned_to' => $reassign,
			) );
		}

		return $this->success_result( array(
			/* translators: 1: success count, 2: total */
			'message'       => sprintf( __( 'Deleted %1$d of %2$d users.', 'vibe-ai' ), $ok, count( $idents ) ),
			'succeeded'     => $ok,
			'total'         => count( $idents ),
			'reassigned_to' => $reassign,
			'results'       => $results,
		) );
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



	private function handle_menu_item_list( $positional, $flags ) {
		if ( empty( $positional[0] ) ) {
			return $this->error_result( __( 'Menu required. Usage: menu item list <menu> (accepts id, slug, or name)', 'vibe-ai' ) );
		}
		$menu  = is_numeric( $positional[0] ) ? (int) $positional[0] : $positional[0];
		$items = wp_get_nav_menu_items( $menu );
		if ( false === $items ) {
			/* translators: %s: menu identifier */
			return $this->error_result( sprintf( __( 'Menu \'%s\' not found. Run `menu list` to see available menus.', 'vibe-ai' ), $positional[0] ) );
		}
		$results = array();
		foreach ( $items as $item ) {
			$results[] = array(
				'db_id'     => (int) $item->ID,
				'type'      => $item->type,
				'object'    => $item->object,
				'object_id' => (int) $item->object_id,
				'title'     => $item->title,
				'link'      => $item->url,
				'position'  => (int) $item->menu_order,
				'parent'    => (int) $item->menu_item_parent,
			);
		}
		return $this->success_result( $this->filter_fields( $results, $flags ) );
	}


	private function handle_user_get( $positional, $flags ) {
		if ( empty( $positional[0] ) ) {
			return $this->error_result( __( 'User identifier required. Usage: user get <id|login|email>', 'vibe-ai' ) );
		}
		$ident = $positional[0];
		$user  = is_numeric( $ident )
			? get_user_by( 'id', (int) $ident )
			: ( is_email( $ident ) ? get_user_by( 'email', $ident ) : get_user_by( 'login', $ident ) );
		if ( ! $user ) {
			/* translators: %s: user identifier */
			return $this->error_result( sprintf( __( 'User \'%s\' not found.', 'vibe-ai' ), $ident ) );
		}
		$data = array(
			'ID'              => (int) $user->ID,
			'user_login'      => $user->user_login,
			'display_name'    => $user->display_name,
			'user_email'      => $user->user_email,
			'user_registered' => $user->user_registered,
			'user_nicename'   => $user->user_nicename,
			'user_url'        => $user->user_url,
			'roles'           => implode( ',', (array) $user->roles ),
		);
		return $this->success_result( $this->filter_fields( array( $data ), $flags )[0] ?? $data );
	}


	private function handle_cron_test( $positional, $flags ) {
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			return $this->error_result( __( 'The DISABLE_WP_CRON constant is set to true. WP-Cron spawning is disabled — scheduled events only run if a system cron hits wp-cron.php directly.', 'vibe-ai' ) );
		}
		$notes = array();
		if ( defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON ) {
			$notes[] = __( 'The ALTERNATE_WP_CRON constant is set to true; cron runs via a redirect fallback rather than the HTTP spawn tested here.', 'vibe-ai' );
		}
		$doing    = sprintf( '%.22F', microtime( true ) );
		$url      = add_query_arg( 'doing_wp_cron', $doing, site_url( 'wp-cron.php' ) );
		$response = wp_remote_post( $url, array(
			'timeout'   => 10,
			'blocking'  => true,
			'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
		) );
		if ( is_wp_error( $response ) ) {
			/* translators: %s: HTTP error message */
			return $this->error_result( sprintf( __( 'WP-Cron spawn failed: %s. The site cannot reach its own wp-cron.php (loopback requests blocked?), so scheduled events will not run on traffic.', 'vibe-ai' ), $response->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 300 ) {
			/* translators: %d: HTTP status code */
			return $this->error_result( sprintf( __( 'WP-Cron spawn returned HTTP %d (expected 200). Scheduled events may not be running.', 'vibe-ai' ), $code ) );
		}
		return $this->success_result( array(
			'message'           => __( 'WP-Cron spawning is working as expected.', 'vibe-ai' ),
			'spawn_status_code' => $code,
			'notes'             => $notes,
		) );
	}


	private function handle_maintenance_mode_status( $positional, $flags ) {
		// Real `wp maintenance-mode status` only checks the core .maintenance
		// file. Plugin-based maintenance is the kind users actually have, and
		// the drop-in decides what visitors see — so this reports all three.
		$core    = $this->core_maintenance_state( ABSPATH . '.maintenance' );
		$drop_in = array(
			'present' => file_exists( WP_CONTENT_DIR . '/maintenance.php' ),
			'path'    => 'wp-content/maintenance.php',
			'note'    => __( 'Custom maintenance page, served whenever core maintenance mode is active.', 'vibe-ai' ),
		);
		$plugins = $this->detect_maintenance_plugins();

		$plugin_enabled = array();
		foreach ( $plugins as $p ) {
			if ( ! empty( $p['enabled'] ) ) {
				$plugin_enabled[] = $p['name'];
			}
		}

		$active = $core['active'] || ! empty( $plugin_enabled );
		if ( $core['active'] ) {
			$message = __( 'Maintenance mode is active (core .maintenance file). Visitors and REST requests get a maintenance page instead of normal responses.', 'vibe-ai' );
		} elseif ( ! empty( $plugin_enabled ) ) {
			/* translators: %s: plugin names */
			$message = sprintf( __( 'Maintenance/coming-soon mode is active via plugin: %s. The site answers frontend requests with the plugin\'s holding page.', 'vibe-ai' ), implode( ', ', $plugin_enabled ) );
		} else {
			$message = __( 'Maintenance mode is not active.', 'vibe-ai' );
		}

		return $this->success_result( array(
			'active'                   => $active,
			'core_maintenance_file'    => $core,
			'maintenance_page_drop_in' => $drop_in,
			'maintenance_plugins'      => $plugins,
			'message'                  => $message,
		) );
	}


	/**
	 * Effective state of the core .maintenance file. Core ignores the file once
	 * $upgrading is more than 10 minutes old, so presence alone is not "active".
	 */
	private function core_maintenance_state( $path ) {
		$state = array( 'present' => file_exists( $path ), 'active' => false );
		if ( ! $state['present'] ) {
			return $state;
		}
		$contents = (string) file_get_contents( $path );
		if ( preg_match( '/\$upgrading\s*=\s*(\d+)/', $contents, $m ) ) {
			$started             = (int) $m[1];
			$state['started_at'] = gmdate( 'Y-m-d H:i:s', $started );
			$state['active']     = ( time() - $started ) < 600;
			if ( ! $state['active'] ) {
				$state['note'] = __( 'The .maintenance file is present but its timestamp is older than 10 minutes, so core ignores it. A stuck file usually means an update died mid-flight; it is safe to delete.', 'vibe-ai' );
			}
		} else {
			$state['note'] = __( 'No parseable $upgrading timestamp in the .maintenance file; core treats that as expired (not in maintenance).', 'vibe-ai' );
		}
		return $state;
	}


	/**
	 * Known maintenance/coming-soon plugins with their enable state where the
	 * option schema is known; enabled=null means "active but state unreadable".
	 */
	private function detect_maintenance_plugins() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$found = array();

		foreach ( array( 'coming-soon/coming-soon.php', 'seedprod-pro/seedprod-pro.php' ) as $file ) {
			if ( is_plugin_active( $file ) ) {
				$settings = json_decode( (string) get_option( 'seedprod_settings' ), true );
				$modes    = array();
				if ( ! empty( $settings['enable_coming_soon_mode'] ) ) {
					$modes[] = 'coming_soon';
				}
				if ( ! empty( $settings['enable_maintenance_mode'] ) ) {
					$modes[] = 'maintenance';
				}
				$found[] = array( 'plugin' => $file, 'name' => 'SeedProd', 'enabled' => ! empty( $modes ), 'modes' => $modes );
			}
		}

		if ( is_plugin_active( 'wp-maintenance-mode/wp-maintenance-mode.php' ) ) {
			$settings = get_option( 'wpmm_settings' );
			$found[]  = array(
				'plugin'  => 'wp-maintenance-mode/wp-maintenance-mode.php',
				'name'    => 'LightStart (WP Maintenance Mode)',
				'enabled' => ! empty( $settings['general']['status'] ),
			);
		}

		if ( is_plugin_active( 'under-construction-page/under-construction.php' ) ) {
			$options = get_option( 'ucp_options' );
			$found[] = array(
				'plugin'  => 'under-construction-page/under-construction.php',
				'name'    => 'Under Construction',
				'enabled' => ! empty( $options['status'] ),
			);
		}

		if ( is_plugin_active( 'cmp-coming-soon-maintenance/niteo-cmp.php' ) ) {
			$found[] = array(
				'plugin'  => 'cmp-coming-soon-maintenance/niteo-cmp.php',
				'name'    => 'CMP Coming Soon & Maintenance',
				'enabled' => ( '1' === (string) get_option( 'niteoCS_status' ) ),
			);
		}

		if ( is_plugin_active( 'maintenance/maintenance.php' ) ) {
			$found[] = array(
				'plugin'  => 'maintenance/maintenance.php',
				'name'    => 'Maintenance',
				'enabled' => null,
				'note'    => __( 'Plugin is active; enable state is not readable from options — check its settings page.', 'vibe-ai' ),
			);
		}

		return $found;
	}


	private function handle_cron_event_run( $positional, $flags ) {
		if ( empty( $positional ) ) {
			return $this->error_result( __( 'Hook required. Usage: cron event run <hook> [<hook>...]', 'vibe-ai' ) );
		}
		$crons   = _get_cron_array() ?: array();
		$results = array();
		$ok      = 0;
		foreach ( $positional as $hook ) {
			$instances = array();
			foreach ( $crons as $timestamp => $hooks ) {
				if ( isset( $hooks[ $hook ] ) ) {
					foreach ( $hooks[ $hook ] as $event ) {
						$instances[] = $event;
					}
				}
			}
			if ( empty( $instances ) ) {
				$results[] = array( 'hook' => $hook, 'status' => 'error', 'error' => __( 'no scheduled events for this hook', 'vibe-ai' ) );
				continue;
			}
			$executed = 0;
			$error    = null;
			foreach ( $instances as $event ) {
				try {
					do_action_ref_array( $hook, isset( $event['args'] ) ? (array) $event['args'] : array() );
					$executed++;
				} catch ( \Throwable $e ) {
					$error = $e->getMessage();
					break;
				}
			}
			if ( null !== $error ) {
				/* translators: %s: error message */
				$results[] = array( 'hook' => $hook, 'status' => 'error', 'executed' => $executed, 'error' => sprintf( __( 'callback threw: %s', 'vibe-ai' ), $error ) );
				continue;
			}
			$ok++;
			$results[] = array( 'hook' => $hook, 'status' => 'executed', 'executed' => $executed );
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Cron hook(s) run: {$ok}/" . count( $positional ),
			'action_label' => 'Refresh',
		) );

		return $this->success_result( array(
			/* translators: 1: success count, 2: total */
			'message' => sprintf( __( 'Executed %1$d of %2$d hook(s).', 'vibe-ai' ), $ok, count( $positional ) ),
			'results' => $results,
		) );
	}


	private function handle_cron_event_delete( $positional, $flags ) {
		if ( empty( $positional ) ) {
			return $this->error_result( __( 'Hook required. Usage: cron event delete <hook> [<hook>...]', 'vibe-ai' ) );
		}
		$results = array();
		$total   = 0;
		foreach ( $positional as $hook ) {
			$removed = wp_unschedule_hook( $hook );
			if ( false === $removed || is_wp_error( $removed ) ) {
				$results[] = array( 'hook' => $hook, 'status' => 'error', 'error' => __( 'unschedule failed', 'vibe-ai' ) );
				continue;
			}
			$total    += (int) $removed;
			$results[] = array( 'hook' => $hook, 'status' => 'deleted', 'events_removed' => (int) $removed );
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Cron events deleted: {$total}",
			'action_label' => 'Refresh',
		) );

		return $this->success_result( array(
			/* translators: %d: number of events removed */
			'message' => sprintf( __( 'Removed %d scheduled event(s).', 'vibe-ai' ), $total ),
			'results' => $results,
		) );
	}


	private function resolve_user( $ident ) {
		return is_numeric( $ident )
			? get_user_by( 'id', (int) $ident )
			: ( is_email( $ident ) ? get_user_by( 'email', $ident ) : get_user_by( 'login', $ident ) );
	}


	private function handle_menu_location_list( $positional, $flags ) {
		$assigned = get_nav_menu_locations();
		$results  = array();
		foreach ( get_registered_nav_menus() as $location => $description ) {
			$menu      = ! empty( $assigned[ $location ] ) ? wp_get_nav_menu_object( $assigned[ $location ] ) : null;
			$results[] = array(
				'location'      => $location,
				'description'   => $description,
				'assigned_menu' => $menu ? $menu->name : '',
			);
		}
		return $this->success_result( $results );
	}

}
