<?php
/**
 * WP-CLI emulator: cap and role handlers, user-cap mutations.
 *
 * Extracted from class-wpvibe-cli.php (mechanical split; no behavior change).
 */

defined( 'ABSPATH' ) || exit;

trait WPVibe_CLI_Roles {


	private function handle_cap_list( $positional, $flags ) {
		if ( empty( $positional[0] ) ) {
			return $this->error_result( __( 'Role required. Usage: cap list <role> [--show-grant]', 'vibe-ai' ) );
		}
		$role = get_role( $positional[0] );
		if ( ! $role ) {
			$names = function_exists( 'wp_roles' ) ? implode( ', ', array_keys( wp_roles()->roles ) ) : '';
			/* translators: 1: role slug, 2: available role slugs */
			return $this->error_result( sprintf( __( 'Role \'%1$s\' not found. Available roles: %2$s', 'vibe-ai' ), $positional[0], $names ) );
		}
		$caps = (array) $role->capabilities;
		if ( empty( $flags['show_grant'] ) ) {
			$granted = array_keys( array_filter( $caps ) );
			sort( $granted );
			return $this->success_result( $granted );
		}
		ksort( $caps );
		$results = array();
		foreach ( $caps as $cap => $grant ) {
			$results[] = array( 'capability' => $cap, 'grant' => (bool) $grant );
		}
		return $this->success_result( $results );
	}


	private function handle_role_list( $positional, $flags ) {
		$results = array();
		foreach ( wp_roles()->roles as $slug => $def ) {
			$results[] = array(
				'name'             => $def['name'],
				'role'             => $slug,
				'capability_count' => count( array_filter( (array) ( $def['capabilities'] ?? array() ) ) ),
			);
		}
		return $this->success_result( $this->filter_fields( $results, $flags ) );
	}


	// ------------------------------------------------------------------
	// Role & capability editing (gated + lockout-protected)
	// ------------------------------------------------------------------

	private function resolve_role_or_error( $role_key ) {
		if ( empty( $role_key ) ) {
			return new WP_Error( 'no_role', __( 'Role required.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'invalid_input', false ) );
		}
		$role = get_role( $role_key );
		if ( ! $role ) {
			$names = function_exists( 'wp_roles' ) ? implode( ', ', array_keys( wp_roles()->roles ) ) : '';
			/* translators: 1: role slug, 2: available role slugs */
			return new WP_Error( 'no_role', sprintf( __( 'Role \'%1$s\' not found. Available roles: %2$s', 'vibe-ai' ), $role_key, $names ), WPVibe_Error_Contract::data( 'not_found', false ) );
		}
		return $role;
	}


	private function handle_cap_add( $positional, $flags ) {
		if ( count( $positional ) < 2 ) {
			return $this->error_result( __( 'Usage: cap add <role> <cap>... [--grant=<true|false>]', 'vibe-ai' ) );
		}
		$role = $this->resolve_role_or_error( $positional[0] );
		if ( is_wp_error( $role ) ) {
			return $this->error_result( $role->get_error_message() );
		}
		$caps  = array_slice( $positional, 1 );
		$grant = true;
		if ( isset( $flags['grant'] ) ) {
			$grant = ! ( 'false' === $flags['grant'] || false === $flags['grant'] || '0' === $flags['grant'] );
		}

		$added   = 0;
		$skipped = array();
		foreach ( $caps as $cap ) {
			if ( $grant && $role->has_cap( $cap ) ) {
				$skipped[] = array( 'capability' => $cap, 'reason' => 'already granted' );
				continue;
			}
			if ( ! $grant && isset( $role->capabilities[ $cap ] ) && false === $role->capabilities[ $cap ] ) {
				$skipped[] = array( 'capability' => $cap, 'reason' => 'already denied' );
				continue;
			}
			$role->add_cap( $cap, $grant );
			$added++;
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Capabilities added to role: {$positional[0]}",
			'action_label' => 'Manage Users',
			'admin_url'    => admin_url( 'users.php' ),
		) );

		$data = array(
			/* translators: 1: count, 2: role */
			'message' => sprintf( __( 'Added %1$d capability(ies) to \'%2$s\' role%3$s.', 'vibe-ai' ), $added, $positional[0], $grant ? '' : ' ' . __( 'as false (denied)', 'vibe-ai' ) ),
			'added'   => $added,
		);
		if ( $skipped ) {
			$data['skipped'] = $skipped;
		}
		$high = array_values( array_intersect( $caps, self::HIGH_RISK_CAPS ) );
		if ( $high && $grant ) {
			$data['high_risk_capabilities'] = $high;
		}
		return $this->success_result( $data );
	}


	private function handle_cap_remove( $positional, $flags ) {
		if ( count( $positional ) < 2 ) {
			return $this->error_result( __( 'Usage: cap remove <role> <cap>...', 'vibe-ai' ) );
		}
		$role_key = $positional[0];
		$caps     = array_slice( $positional, 1 );

		// Lockout protection (real WP-CLI has none): the administrator role
		// keeps its core capabilities, or the AI could brick site management.
		if ( 'administrator' === $role_key ) {
			$core = array_values( array_intersect( $caps, self::CORE_ADMIN_CAPS ) );
			if ( $core ) {
				return $this->error_result(
					sprintf(
						/* translators: %s: capability list */
						__( 'Refused: removing core capabilities (%s) from the administrator role would lock administrators out of site management. Use `role reset administrator` if the role is already damaged.', 'vibe-ai' ),
						implode( ', ', $core )
					)
				);
			}
		}

		$role = $this->resolve_role_or_error( $role_key );
		if ( is_wp_error( $role ) ) {
			return $this->error_result( $role->get_error_message() );
		}

		$removed = 0;
		$skipped = array();
		foreach ( $caps as $cap ) {
			if ( ! isset( $role->capabilities[ $cap ] ) ) {
				$skipped[] = array( 'capability' => $cap, 'reason' => 'not set on this role' );
				continue;
			}
			$role->remove_cap( $cap );
			$removed++;
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Capabilities removed from role: {$role_key}",
			'action_label' => 'Manage Users',
			'admin_url'    => admin_url( 'users.php' ),
		) );

		$data = array(
			/* translators: 1: count, 2: role */
			'message' => sprintf( __( 'Removed %1$d capability(ies) from \'%2$s\' role.', 'vibe-ai' ), $removed, $role_key ),
			'removed' => $removed,
		);
		if ( $skipped ) {
			$data['skipped'] = $skipped;
		}
		return $this->success_result( $data );
	}


	private function handle_role_create( $positional, $flags ) {
		if ( count( $positional ) < 2 ) {
			return $this->error_result( __( 'Usage: role create <role-key> <role-name> [--clone=<role>]', 'vibe-ai' ) );
		}
		$role_key  = $positional[0];
		$role_name = $positional[1];
		if ( get_role( $role_key ) ) {
			/* translators: %s: role key */
			return $this->error_result( sprintf( __( 'Role \'%s\' already exists.', 'vibe-ai' ), $role_key ) );
		}

		$clone_caps = null;
		if ( ! empty( $flags['clone'] ) ) {
			$src = get_role( $flags['clone'] );
			if ( ! $src ) {
				/* translators: %s: role key */
				return $this->error_result( sprintf( __( 'Clone source role \'%s\' not found.', 'vibe-ai' ), $flags['clone'] ) );
			}
			$clone_caps = $src->capabilities;
		}

		$new_role = add_role( $role_key, $role_name );
		if ( ! $new_role ) {
			return $this->error_result( __( 'Role could not be created.', 'vibe-ai' ) );
		}
		if ( $clone_caps ) {
			// Unlike real WP-CLI (which grants everything true), preserve
			// grant=false denials from the source role.
			foreach ( $clone_caps as $cap => $grant ) {
				$new_role->add_cap( $cap, $grant );
			}
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Role created: {$role_key}",
			'action_label' => 'Manage Users',
			'admin_url'    => admin_url( 'users.php' ),
		) );

		$message = null !== $clone_caps
			/* translators: 1: role key, 2: source role */
			? sprintf( __( 'Role \'%1$s\' created. Cloned capabilities from \'%2$s\'.', 'vibe-ai' ), $role_key, $flags['clone'] )
			/* translators: %s: role key */
			: sprintf( __( 'Role \'%s\' created.', 'vibe-ai' ), $role_key );
		return $this->success_result( array( 'message' => $message ) );
	}


	private function handle_role_delete( $positional, $flags ) {
		if ( empty( $positional[0] ) ) {
			return $this->error_result( __( 'Usage: role delete <role-key>', 'vibe-ai' ) );
		}
		$role_key = $positional[0];
		if ( 'administrator' === $role_key ) {
			return $this->error_result( __( 'Refused: the administrator role cannot be deleted (lockout protection).', 'vibe-ai' ) );
		}
		if ( ! get_role( $role_key ) ) {
			/* translators: %s: role key */
			return $this->error_result( sprintf( __( 'Role \'%s\' not found.', 'vibe-ai' ), $role_key ) );
		}

		$users      = count_users();
		$user_count = isset( $users['avail_roles'][ $role_key ] ) ? (int) $users['avail_roles'][ $role_key ] : 0;

		remove_role( $role_key );
		if ( get_role( $role_key ) ) {
			/* translators: %s: role key */
			return $this->error_result( sprintf( __( 'Role \'%s\' could not be deleted.', 'vibe-ai' ), $role_key ) );
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Role deleted: {$role_key}",
			'action_label' => 'Manage Users',
			'admin_url'    => admin_url( 'users.php' ),
		) );

		$data = array(
			/* translators: %s: role key */
			'message' => sprintf( __( 'Role \'%s\' deleted.', 'vibe-ai' ), $role_key ),
		);
		if ( $user_count > 0 ) {
			/* translators: %d: user count */
			$data['note'] = sprintf( __( '%d user(s) held this role and now have no role. Reassign them via the users REST API or wp-admin.', 'vibe-ai' ), $user_count );
		}
		return $this->success_result( $data );
	}


	private function handle_role_reset( $positional, $flags ) {
		$all = ! empty( $flags['all'] );
		if ( ! $all && empty( $positional ) ) {
			return $this->error_result( __( 'Usage: role reset <role-key>... | --all', 'vibe-ai' ) );
		}
		if ( ! function_exists( 'populate_roles' ) ) {
			require_once ABSPATH . 'wp-admin/includes/schema.php';
		}

		$requested = $all ? self::DEFAULT_ROLES : array_values( array_unique( $positional ) );
		$targets   = array();
		$results   = array();
		foreach ( $requested as $role_key ) {
			if ( ! in_array( $role_key, self::DEFAULT_ROLES, true ) ) {
				// Real WP-CLI: custom roles are not affected by reset.
				$results[] = array( 'role' => $role_key, 'status' => 'skipped', 'note' => __( 'custom role, not affected by reset', 'vibe-ai' ) );
				continue;
			}
			$targets[] = $role_key;
		}
		if ( empty( $targets ) ) {
			return $this->error_result( __( 'Must specify a default role to reset (administrator, editor, author, contributor, subscriber).', 'vibe-ai' ) );
		}

		// populate_roles() recreates every missing default role, so remember
		// which defaults were deliberately absent and re-remove them after.
		$absent_defaults = array();
		foreach ( self::DEFAULT_ROLES as $role_key ) {
			if ( ! in_array( $role_key, $targets, true ) && ! get_role( $role_key ) ) {
				$absent_defaults[] = $role_key;
			}
		}

		$before = array();
		foreach ( $targets as $role_key ) {
			$role_obj            = get_role( $role_key );
			$before[ $role_key ] = $role_obj ? array_keys( array_filter( $role_obj->capabilities ) ) : array();
			remove_role( $role_key );
		}

		populate_roles();

		foreach ( $absent_defaults as $role_key ) {
			remove_role( $role_key );
		}

		foreach ( $targets as $role_key ) {
			$role_obj = get_role( $role_key );
			$after    = $role_obj ? array_keys( array_filter( $role_obj->capabilities ) ) : array();
			$restored = count( array_diff( $after, $before[ $role_key ] ) );
			$removed  = count( array_diff( $before[ $role_key ], $after ) );
			$results[] = array(
				'role'                  => $role_key,
				'status'                => 'reset',
				'capabilities_restored' => $restored,
				'capabilities_removed'  => $removed,
			);
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => 'Role(s) reset to WordPress defaults: ' . implode( ', ', $targets ),
			'action_label' => 'Manage Users',
			'admin_url'    => admin_url( 'users.php' ),
		) );

		return $this->success_result( array(
			/* translators: %d: role count */
			'message' => sprintf( __( 'Reset %d role(s) to WordPress defaults.', 'vibe-ai' ), count( $targets ) ),
			'results' => $results,
		) );
	}


	private function handle_user_add_cap( $positional, $flags ) {
		if ( count( $positional ) < 2 ) {
			return $this->error_result( __( 'Usage: user add-cap <id|login|email> <cap>', 'vibe-ai' ) );
		}
		$user = $this->resolve_user( $positional[0] );
		if ( ! $user ) {
			/* translators: %s: user identifier */
			return $this->error_result( sprintf( __( 'User \'%s\' not found.', 'vibe-ai' ), $positional[0] ) );
		}
		$cap = $positional[1];
		$user->add_cap( $cap );

		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Capability added to user: {$user->user_login} → {$cap}",
			'action_label' => 'Manage Users',
			'admin_url'    => admin_url( 'users.php' ),
		) );

		$data = array(
			/* translators: 1: capability, 2: user login */
			'message' => sprintf( __( 'Added \'%1$s\' capability for user \'%2$s\'.', 'vibe-ai' ), $cap, $user->user_login ),
		);
		if ( in_array( $cap, self::HIGH_RISK_CAPS, true ) ) {
			$data['high_risk_capabilities'] = array( $cap );
		}
		return $this->success_result( $data );
	}


	private function handle_user_remove_cap( $positional, $flags ) {
		if ( count( $positional ) < 2 ) {
			return $this->error_result( __( 'Usage: user remove-cap <id|login|email> <cap>', 'vibe-ai' ) );
		}
		$user = $this->resolve_user( $positional[0] );
		if ( ! $user ) {
			/* translators: %s: user identifier */
			return $this->error_result( sprintf( __( 'User \'%s\' not found.', 'vibe-ai' ), $positional[0] ) );
		}
		$cap = $positional[1];
		// Only individual grants are removable, matching real WP-CLI; caps that
		// come from a role are removed from the role via `cap remove`.
		if ( ! isset( $user->caps[ $cap ] ) ) {
			return $this->error_result(
				sprintf(
					/* translators: 1: capability, 2: user login */
					__( 'No direct \'%1$s\' capability on user \'%2$s\'. If it comes from their role, remove it from the role with `cap remove <role> %1$s`.', 'vibe-ai' ),
					$cap,
					$user->user_login
				)
			);
		}
		$user->remove_cap( $cap );

		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Capability removed from user: {$user->user_login} → {$cap}",
			'action_label' => 'Manage Users',
			'admin_url'    => admin_url( 'users.php' ),
		) );

		return $this->success_result( array(
			/* translators: 1: capability, 2: user login */
			'message' => sprintf( __( 'Removed \'%1$s\' cap for user \'%2$s\'.', 'vibe-ai' ), $cap, $user->user_login ),
		) );
	}

}
