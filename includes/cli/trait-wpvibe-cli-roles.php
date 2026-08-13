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


	// ------------------------------------------------------------------
	// User account & meta writes (issue #37)
	// ------------------------------------------------------------------

	/** Gate predicate caps, derived from the preview list so the two cannot drift. */
	private function admin_equivalent_caps() {
		return array_diff( self::HIGH_RISK_CAPS, self::UNGATED_EDITOR_CAPS );
	}

	/** True when the role grants a takeover-grade cap (admin_equivalent_caps(), live get_role()). */
	private function role_is_admin_equivalent( $role_key ) {
		$role_key = (string) $role_key;
		if ( '' === $role_key ) {
			return false;
		}
		$role = get_role( $role_key );
		if ( ! $role ) {
			return false;
		}
		$granted = array_keys( array_filter( (array) $role->capabilities ) );
		return (bool) array_intersect( $granted, $this->admin_equivalent_caps() );
	}

	/** True when any role in the set is admin-equivalent. */
	private function roles_include_admin_equivalent( $roles ) {
		foreach ( (array) $roles as $r ) {
			if ( $this->role_is_admin_equivalent( $r ) ) {
				return true;
			}
		}
		return false;
	}

	/** HIGH_RISK caps a role grants, for the approval preview warning (superset of ADMIN_EQUIVALENT). */
	private function role_high_risk_caps( $role_key ) {
		$role = get_role( (string) $role_key );
		if ( ! $role ) {
			return array();
		}
		$granted = array_keys( array_filter( (array) $role->capabilities ) );
		return array_values( array_intersect( $granted, self::HIGH_RISK_CAPS ) );
	}

	/** Refuse account/role writes on multisite: their single-site core paths and lockout count don't hold there. */
	private function refuse_user_write_on_multisite( $command ) {
		if ( is_multisite() ) {
			/* translators: %s: command */
			return $this->error_result( sprintf( __( '`%s` is not emulated on multisite: user and role writes there use network-specific core paths and a different super-admin model. Manage this user in Network Admin > Users, or use rest_api.', 'vibe-ai' ), $command ) );
		}
		return null;
	}

	/**
	 * Resolve a user for a WRITE verb. Same id|login|email order as resolve_user,
	 * but a numeric identifier that ALSO matches a *different* account's login is
	 * refused as ambiguous, so a password/role write can never silently hit the
	 * wrong account. Returns WP_Error on ambiguity, WP_User, or null (not found).
	 */
	private function resolve_user_for_write( $ident ) {
		if ( is_numeric( $ident ) ) {
			$by_id    = get_user_by( 'id', (int) $ident );
			$by_login = get_user_by( 'login', (string) $ident );
			if ( $by_id && $by_login && (int) $by_id->ID !== (int) $by_login->ID ) {
				/* translators: %s: identifier */
				return new WP_Error( 'ambiguous_user', sprintf( __( 'Ambiguous user \'%s\': it matches both a user ID and a different account whose login is that number. Re-run with the login, or confirm the numeric ID with `user get` first.', 'vibe-ai' ), $ident ) );
			}
		}
		$user = $this->resolve_user( $ident );
		return $user ? $user : null;
	}

	/** Reject a sensitive flag passed without a value (the parser has no space-separated form). */
	private function require_flag_value( $command, $flags, $flag ) {
		if ( isset( $flags[ $flag ] ) && true === $flags[ $flag ] ) {
			return $this->error_result( sprintf(
				/* translators: 1: command, 2: flag */
				__( '`%1$s`: attach --%2$s\'s value with = (--%2$s=<value>). A space before the value is not supported and would be misread.', 'vibe-ai' ),
				$command, $flag
			) );
		}
		return null;
	}

	/** Hard-denied user-meta keys: the cap/session system's own storage. Case-insensitive, suffix-anchored. */
	private function is_hard_denied_user_meta( $key ) {
		$k = strtolower( (string) $key );
		foreach ( self::USER_META_CREDENTIAL_KEYS as $deny ) {
			if ( $k === strtolower( $deny ) ) {
				return true;
			}
		}
		// The regexes below anchor the cap/level keys on a `_` or start-of-string
		// boundary, which misses a table prefix that ends in a letter/digit
		// (e.g. $table_prefix = 'wp' => wpcapabilities). Match the exact runtime
		// keys first so no prefix shape can slip role/level storage through.
		global $wpdb;
		if ( isset( $wpdb ) ) {
			$prefix = strtolower( (string) ( method_exists( $wpdb, 'get_blog_prefix' ) ? $wpdb->get_blog_prefix() : ( $wpdb->prefix ?? '' ) ) );
			if ( '' !== $prefix && ( $k === $prefix . 'capabilities' || $k === $prefix . 'user_level' ) ) {
				return true;
			}
		}
		foreach ( self::USER_META_HARD_DENY_PATTERNS as $pattern ) {
			if ( preg_match( $pattern, $k ) ) {
				return true;
			}
		}
		return false;
	}

	/** User-meta keys whose (hash/secret) VALUE is withheld from read output. */
	private function is_withheld_user_meta( $key ) {
		$k = strtolower( (string) $key );
		foreach ( self::USER_META_CREDENTIAL_KEYS as $w ) {
			if ( $k === strtolower( $w ) ) {
				return true;
			}
		}
		foreach ( self::USER_META_SECRET_VALUE_PATTERNS as $pattern ) {
			if ( preg_match( $pattern, $k ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Hard lockout/self-demotion guard for set-role/add-role/remove-role.
	 * $new_roles is the user's role set AFTER the op. Refuses only when the op
	 * strips admin-equivalent access from the last administrator or the connected
	 * account. (Admin count is slug-based, mirroring handle_user_delete; the
	 * approval gate on any admin->non-admin change is the primary protection, so
	 * the custom-admin-role edge the slug count misses still faces a human.)
	 */
	private function guard_role_demotion( $user, $new_roles, $admin_ids = null, $already_demoted = 0 ) {
		$was_admin  = $this->roles_include_admin_equivalent( (array) $user->roles );
		$will_admin = $this->roles_include_admin_equivalent( (array) $new_roles );
		if ( ! $was_admin || $will_admin ) {
			return null;
		}
		if ( (int) $user->ID === (int) get_current_user_id() ) {
			return $this->error_result( __( 'Refused: this would remove admin access from the WPVibe-connected account. Have another administrator make this change.', 'vibe-ai' ) );
		}
		// Batch `user update` passes a pre-loop SNAPSHOT plus a count of admins
		// already demoted this call. Counting against the snapshot (not a live
		// re-query, which already excludes earlier demotions) avoids subtracting
		// the same demotion twice and wrongly refusing a 3+-admin batch.
		if ( null === $admin_ids ) {
			$admin_ids = array_map( 'intval', (array) get_users( array( 'role' => 'administrator', 'fields' => 'ID', 'number' => -1 ) ) );
		}
		if ( in_array( (int) $user->ID, (array) $admin_ids, true ) && ( count( (array) $admin_ids ) - (int) $already_demoted ) <= 1 ) {
			return $this->error_result( __( 'Refused: this is the last administrator (lockout protection). Create or promote another administrator first.', 'vibe-ai' ) );
		}
		return null;
	}


	private function handle_user_create( $positional, $flags ) {
		$ms = $this->refuse_user_write_on_multisite( 'user create' );
		if ( $ms ) {
			return $ms;
		}
		$known  = array( 'role', 'user_pass', 'display_name', 'first_name', 'last_name', 'user_url', 'porcelain', 'send_email' );
		$reject = $this->reject_unknown_flags( 'user create', $flags, $known, array(
			'user_registered' => __( 'The registration date is set to now; back-dating it is not emulated.', 'vibe-ai' ),
			'user_nicename'   => __( 'The nicename is derived from the login. Change it later with rest_api PUT /wp/v2/users/<id> {"slug":"..."}.', 'vibe-ai' ),
			'nickname'        => __( 'Set it after creating the user with `user meta update <user> nickname <value>`.', 'vibe-ai' ),
			'description'     => __( 'Set it after creating the user with `user meta update <user> description <value>`.', 'vibe-ai' ),
			'rich_editing'    => __( 'Editor preferences are per-user meta; set with `user meta update <user> rich_editing <value>`.', 'vibe-ai' ),
			'no_role'         => __( 'Creating a user with no role is not emulated. Use the lowest role (subscriber) instead.', 'vibe-ai' ),
			'network'         => __( 'Multisite network scoping is not emulated.', 'vibe-ai' ),
		) );
		if ( $reject ) {
			return $reject;
		}
		$vp = $this->require_flag_value( 'user create', $flags, 'user_pass' );
		if ( $vp ) {
			return $vp;
		}
		$vr = $this->require_flag_value( 'user create', $flags, 'role' );
		if ( $vr ) {
			return $vr;
		}

		$login = isset( $positional[0] ) ? trim( (string) $positional[0] ) : '';
		$email = isset( $positional[1] ) ? trim( (string) $positional[1] ) : '';
		if ( '' === $login || '' === $email ) {
			return $this->error_result( __( 'Usage: user create <user-login> <user-email> [--role=<role>] [--user_pass=<password>] [--display_name=<name>] [--first_name=<name>] [--last_name=<name>] [--user_url=<url>] [--send-email] [--porcelain]', 'vibe-ai' ) );
		}

		$role = ( isset( $flags['role'] ) && '' !== (string) $flags['role'] ) ? (string) $flags['role'] : (string) get_option( 'default_role' );
		if ( '' !== $role && ! get_role( $role ) ) {
			/* translators: %s: role slug */
			return $this->error_result( sprintf( __( 'Role doesn\'t exist: %s', 'vibe-ai' ), $role ) );
		}
		if ( '' !== $role ) {
			if ( ! current_user_can( 'promote_users' ) ) {
				return $this->error_result( __( 'Creating a user with a role requires the promote_users capability.', 'vibe-ai' ) );
			}
			if ( function_exists( 'get_editable_roles' ) ) {
				$editable = array_keys( (array) get_editable_roles() );
				if ( $editable && ! in_array( $role, $editable, true ) ) {
					/* translators: %s: role slug */
					return $this->error_result( sprintf( __( 'You are not allowed to assign the \'%s\' role.', 'vibe-ai' ), $role ) );
				}
			}
		}

		if ( username_exists( $login ) ) {
			/* translators: %s: login */
			return $this->error_result( sprintf( __( 'The \'%s\' username is already registered.', 'vibe-ai' ), $login ) );
		}
		if ( ! is_email( $email ) ) {
			/* translators: %s: email */
			return $this->error_result( sprintf( __( 'The \'%s\' email address is invalid.', 'vibe-ai' ), $email ) );
		}

		$generated = false;
		$pass      = ( isset( $flags['user_pass'] ) && '' !== (string) $flags['user_pass'] ) ? (string) $flags['user_pass'] : null;
		if ( null === $pass ) {
			$pass      = wp_generate_password( 24, true );
			$generated = true;
		}

		$userdata = array(
			'user_login' => $login,
			'user_email' => $email,
			'user_pass'  => $pass,
		);
		if ( '' !== $role ) {
			$userdata['role'] = $role;
		}
		if ( isset( $flags['display_name'] ) ) {
			$userdata['display_name'] = sanitize_text_field( (string) $flags['display_name'] );
		}
		if ( isset( $flags['first_name'] ) ) {
			$userdata['first_name'] = sanitize_text_field( (string) $flags['first_name'] );
		}
		if ( isset( $flags['last_name'] ) ) {
			$userdata['last_name'] = sanitize_text_field( (string) $flags['last_name'] );
		}
		if ( isset( $flags['user_url'] ) ) {
			$userdata['user_url'] = esc_url_raw( (string) $flags['user_url'] );
		}

		// Default: no email. --send-email sends core's new-user set-password notification.
		$send_email = ! empty( $flags['send_email'] );
		if ( ! $send_email ) {
			add_filter( 'send_password_change_email', '__return_false' );
			add_filter( 'send_email_change_email', '__return_false' );
		}
		$user_id = wp_insert_user( wp_slash( $userdata ) );
		if ( ! $send_email ) {
			remove_filter( 'send_password_change_email', '__return_false' );
			remove_filter( 'send_email_change_email', '__return_false' );
		}

		if ( is_wp_error( $user_id ) ) {
			return $this->error_result( $user_id->get_error_message() );
		}
		if ( ! $user_id ) {
			return $this->error_result( __( 'Unknown error creating new user.', 'vibe-ai' ) );
		}
		if ( $send_email && function_exists( 'wp_new_user_notification' ) ) {
			wp_new_user_notification( (int) $user_id, null, 'both' );
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => 'User created: ' . $login . ' (role: ' . ( '' !== $role ? $role : 'none' ) . ')',
			'action_label' => 'Manage Users',
			'admin_url'    => admin_url( 'users.php' ),
		) );

		if ( ! empty( $flags['porcelain'] ) ) {
			return $this->success_result( (int) $user_id );
		}
		$data = array(
			/* translators: 1: login, 2: user ID */
			'message' => sprintf( __( 'Created user \'%1$s\' (ID %2$d).', 'vibe-ai' ), $login, (int) $user_id ),
			'user_id' => (int) $user_id,
			'role'    => $role,
		);
		if ( $generated ) {
			$data['password']      = __( '(auto-generated; not shown)', 'vibe-ai' );
			$data['password_note'] = $send_email
				? __( 'A strong password was generated and a set-password email was sent to the user. It is not displayed here.', 'vibe-ai' )
				: __( 'A strong password was generated but is not displayed. To give the user a password, send them a reset link from wp-admin > Users (or pass --send-email on the next user you create). Do not invent or guess this password.', 'vibe-ai' );
		}
		$high = $this->role_high_risk_caps( $role );
		if ( $high ) {
			$data['high_risk_capabilities'] = $high;
		}
		return $this->success_result( $data );
	}


	private function handle_user_update( $positional, $flags ) {
		$ms = $this->refuse_user_write_on_multisite( 'user update' );
		if ( $ms ) {
			return $ms;
		}
		if ( empty( $positional ) ) {
			return $this->error_result( __( 'Usage: user update <id|login|email>... [--user_pass=<pw>] [--user_email=<email>] [--role=<role>] [--display_name=<name>] [--first_name=<name>] [--last_name=<name>] [--user_url=<url>] [--skip-email]', 'vibe-ai' ) );
		}
		$known  = array( 'user_pass', 'user_email', 'role', 'display_name', 'first_name', 'last_name', 'user_url', 'skip_email' );
		$reject = $this->reject_unknown_flags( 'user update', $flags, $known, array(
			'user_login'      => __( 'User logins can\'t be changed in WordPress. Remove this flag; the other fields still apply.', 'vibe-ai' ),
			'nickname'        => __( 'Set it with `user meta update <user> nickname <value>`.', 'vibe-ai' ),
			'description'     => __( 'Set it with `user meta update <user> description <value>`.', 'vibe-ai' ),
			'rich_editing'    => __( 'Set it with `user meta update <user> rich_editing <value>`.', 'vibe-ai' ),
			'user_nicename'   => __( 'Change it with rest_api PUT /wp/v2/users/<id> {"slug":"..."}.', 'vibe-ai' ),
			'user_registered' => __( 'The registration date is not editable through the emulator.', 'vibe-ai' ),
			'network'         => __( 'Multisite network scoping is not emulated.', 'vibe-ai' ),
		) );
		if ( $reject ) {
			return $reject;
		}
		foreach ( array( 'user_pass', 'user_email', 'role' ) as $vf ) {
			$vr = $this->require_flag_value( 'user update', $flags, $vf );
			if ( $vr ) {
				return $vr;
			}
		}

		$writable  = array( 'user_pass', 'user_email', 'role', 'display_name', 'first_name', 'last_name', 'user_url' );
		$has_field = false;
		foreach ( $writable as $f ) {
			if ( isset( $flags[ $f ] ) ) {
				$has_field = true;
				break;
			}
		}
		if ( ! $has_field ) {
			return $this->error_result( __( 'Need at least one field to update (e.g. --user_email, --role, --display_name).', 'vibe-ai' ) );
		}

		// Empty password on update is a silent credential downgrade (create treats
		// empty as "auto-generate"; update has no such fallback). Reject it.
		if ( isset( $flags['user_pass'] ) && '' === (string) $flags['user_pass'] ) {
			return $this->error_result( __( 'Refusing to set an empty password. Pass --user_pass=<password>, or omit the flag to leave the password unchanged.', 'vibe-ai' ) );
		}

		$role = null;
		if ( isset( $flags['role'] ) ) {
			$role = (string) $flags['role'];
			if ( ! get_role( $role ) ) {
				// Divergence from upstream (warn + forward): a bad role via set_role
				// unsets the real one and installs a junk cap, silently demoting even
				// a last admin at exit 0. Refuse hard instead.
				/* translators: %s: role slug */
				return $this->error_result( sprintf( __( 'Role doesn\'t exist: %s', 'vibe-ai' ), $role ) );
			}
			if ( ! current_user_can( 'promote_users' ) ) {
				return $this->error_result( __( 'Changing a user\'s role requires the promote_users capability.', 'vibe-ai' ) );
			}
		}

		$admin_ids       = array_map( 'intval', (array) get_users( array( 'role' => 'administrator', 'fields' => 'ID', 'number' => -1 ) ) );
		$demoted_admins  = 0;

		$results = array();
		$ok      = 0;
		foreach ( $positional as $ident ) {
			$user = $this->resolve_user_for_write( $ident );
			if ( is_wp_error( $user ) ) {
				$results[] = array( 'target' => $ident, 'status' => 'error', 'error' => $user->get_error_message() );
				continue;
			}
			if ( ! $user ) {
				$results[] = array( 'target' => $ident, 'status' => 'error', 'error' => __( 'not found', 'vibe-ai' ) );
				continue;
			}
			// Same lockout/self-demotion guard the sibling role verbs use, checked
			// against the pre-loop snapshot plus admins already demoted this call.
			if ( null !== $role ) {
				$guard = $this->guard_role_demotion( $user, array( $role ), $admin_ids, $demoted_admins );
				if ( $guard ) {
					$results[] = array( 'target' => $user->user_login, 'id' => (int) $user->ID, 'status' => 'error', 'error' => trim( (string) $guard['stderr'] ) );
					continue;
				}
			}

			$data = array( 'ID' => (int) $user->ID );
			if ( isset( $flags['user_pass'] ) ) {
				$data['user_pass'] = (string) $flags['user_pass'];
			}
			if ( isset( $flags['user_email'] ) ) {
				$data['user_email'] = (string) $flags['user_email'];
			}
			if ( null !== $role ) {
				$data['role'] = $role;
			}
			if ( isset( $flags['display_name'] ) ) {
				$data['display_name'] = sanitize_text_field( (string) $flags['display_name'] );
			}
			if ( isset( $flags['first_name'] ) ) {
				$data['first_name'] = sanitize_text_field( (string) $flags['first_name'] );
			}
			if ( isset( $flags['last_name'] ) ) {
				$data['last_name'] = sanitize_text_field( (string) $flags['last_name'] );
			}
			if ( isset( $flags['user_url'] ) ) {
				$data['user_url'] = esc_url_raw( (string) $flags['user_url'] );
			}

			$skip_email = ! empty( $flags['skip_email'] );
			if ( $skip_email ) {
				add_filter( 'send_password_change_email', '__return_false' );
				add_filter( 'send_email_change_email', '__return_false' );
			}
			$res = wp_update_user( wp_slash( $data ) );
			if ( $skip_email ) {
				remove_filter( 'send_password_change_email', '__return_false' );
				remove_filter( 'send_email_change_email', '__return_false' );
			}
			if ( is_wp_error( $res ) ) {
				$results[] = array( 'target' => $user->user_login, 'id' => (int) $user->ID, 'status' => 'error', 'error' => $res->get_error_message() );
				continue;
			}
			// Count the demotion only after the write succeeded: a failed row
			// must not shrink the budget and over-refuse a later target.
			if ( null !== $role && ! $this->role_is_admin_equivalent( $role ) && in_array( (int) $user->ID, $admin_ids, true ) ) {
				$demoted_admins++;
			}
			$ok++;
			$results[] = array( 'target' => $user->user_login, 'id' => (int) $user->ID, 'status' => 'updated' );
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => count( $positional ) > 1 ? 'Users updated: ' . $ok . '/' . count( $positional ) : ( isset( $results[0]['target'] ) ? 'User updated: ' . $results[0]['target'] : 'User update' ),
			'action_label' => 'Manage Users',
			'admin_url'    => admin_url( 'users.php' ),
		) );

		if ( 1 === count( $positional ) ) {
			$only = $results[0];
			if ( 'error' === $only['status'] ) {
				/* translators: 1: identifier, 2: error */
				return $this->error_result( sprintf( __( 'User \'%1$s\': %2$s', 'vibe-ai' ), $only['target'], $only['error'] ) );
			}
			$fields = array_values( array_filter( $writable, function ( $f ) use ( $flags ) {
				return isset( $flags[ $f ] );
			} ) );
			return $this->success_result( array(
				/* translators: 1: login, 2: user ID */
				'message' => sprintf( __( 'Updated user \'%1$s\' (#%2$d).', 'vibe-ai' ), $only['target'], $only['id'] ),
				'fields'  => $fields,
			) );
		}
		return $this->success_result( array(
			/* translators: 1: success count, 2: total */
			'message'   => sprintf( __( 'Updated %1$d of %2$d users.', 'vibe-ai' ), $ok, count( $positional ) ),
			'succeeded' => $ok,
			'total'     => count( $positional ),
			'results'   => $results,
		) );
	}


	private function handle_user_set_role( $positional, $flags ) {
		$ms = $this->refuse_user_write_on_multisite( 'user set-role' );
		if ( $ms ) {
			return $ms;
		}
		$reject = $this->reject_unknown_flags( 'user set-role', $flags, array() );
		if ( $reject ) {
			return $reject;
		}
		if ( empty( $positional[0] ) ) {
			return $this->error_result( __( 'Usage: user set-role <id|login|email> [<role>]', 'vibe-ai' ) );
		}
		$user = $this->resolve_user_for_write( $positional[0] );
		if ( is_wp_error( $user ) ) {
			return $this->error_result( $user->get_error_message() );
		}
		if ( ! $user ) {
			/* translators: %s: identifier */
			return $this->error_result( sprintf( __( 'User \'%s\' not found.', 'vibe-ai' ), $positional[0] ) );
		}
		$role = ( isset( $positional[1] ) && '' !== (string) $positional[1] ) ? (string) $positional[1] : (string) get_option( 'default_role' );
		if ( '' === $role ) {
			return $this->error_result( __( 'Role required: user set-role <user> <role>.', 'vibe-ai' ) );
		}
		if ( ! get_role( $role ) ) {
			/* translators: %s: role slug */
			return $this->error_result( sprintf( __( 'Role doesn\'t exist: %s', 'vibe-ai' ), $role ) );
		}

		$guard = $this->guard_role_demotion( $user, array( $role ) );
		if ( $guard ) {
			return $guard;
		}
		$user->set_role( $role );

		WPVibe_Change_Tracker::mark( array(
			'summary'      => 'User role set: ' . $user->user_login . ' -> ' . $role,
			'action_label' => 'Manage Users',
			'admin_url'    => admin_url( 'users.php' ),
		) );
		$data = array(
			/* translators: 1: login, 2: user ID, 3: role */
			'message' => sprintf( __( 'Set \'%1$s\' (#%2$d) role to \'%3$s\'.', 'vibe-ai' ), $user->user_login, (int) $user->ID, $role ),
		);
		$high = $this->role_high_risk_caps( $role );
		if ( $high ) {
			$data['high_risk_capabilities'] = $high;
		}
		return $this->success_result( $data );
	}


	private function handle_user_add_role( $positional, $flags ) {
		$ms = $this->refuse_user_write_on_multisite( 'user add-role' );
		if ( $ms ) {
			return $ms;
		}
		$reject = $this->reject_unknown_flags( 'user add-role', $flags, array() );
		if ( $reject ) {
			return $reject;
		}
		if ( count( $positional ) < 2 ) {
			return $this->error_result( __( 'Usage: user add-role <id|login|email> <role> [<role>...]', 'vibe-ai' ) );
		}
		$user = $this->resolve_user_for_write( $positional[0] );
		if ( is_wp_error( $user ) ) {
			return $this->error_result( $user->get_error_message() );
		}
		if ( ! $user ) {
			/* translators: %s: identifier */
			return $this->error_result( sprintf( __( 'User \'%s\' not found.', 'vibe-ai' ), $positional[0] ) );
		}
		$roles = array_slice( $positional, 1 );
		foreach ( $roles as $r ) {
			if ( ! get_role( $r ) ) {
				/* translators: %s: role slug */
				return $this->error_result( sprintf( __( 'Role doesn\'t exist: %s', 'vibe-ai' ), $r ) );
			}
		}
		foreach ( $roles as $r ) {
			$user->add_role( $r );
		}
		WPVibe_Change_Tracker::mark( array(
			'summary'      => 'User roles added: ' . $user->user_login . ' -> ' . implode( ',', $roles ),
			'action_label' => 'Manage Users',
			'admin_url'    => admin_url( 'users.php' ),
		) );
		$data = array(
			'message' => sprintf(
				/* translators: 1: role list, 2: login, 3: user ID */
				_n( 'Added \'%1$s\' role to user \'%2$s\' (#%3$d).', 'Added the \'%1$s\' roles to user \'%2$s\' (#%3$d).', count( $roles ), 'vibe-ai' ),
				implode( "', '", $roles ),
				$user->user_login,
				(int) $user->ID
			),
		);
		$high = array();
		foreach ( $roles as $r ) {
			$high = array_merge( $high, $this->role_high_risk_caps( $r ) );
		}
		$high = array_values( array_unique( $high ) );
		if ( $high ) {
			$data['high_risk_capabilities'] = $high;
		}
		return $this->success_result( $data );
	}


	private function handle_user_remove_role( $positional, $flags ) {
		$ms = $this->refuse_user_write_on_multisite( 'user remove-role' );
		if ( $ms ) {
			return $ms;
		}
		$reject = $this->reject_unknown_flags( 'user remove-role', $flags, array() );
		if ( $reject ) {
			return $reject;
		}
		if ( count( $positional ) < 2 ) {
			return $this->error_result( __( 'Usage: user remove-role <id|login|email> <role> [<role>...]. Removing every role at once (the no-argument form) is not emulated; strip roles in wp-admin if needed.', 'vibe-ai' ) );
		}
		$user = $this->resolve_user_for_write( $positional[0] );
		if ( is_wp_error( $user ) ) {
			return $this->error_result( $user->get_error_message() );
		}
		if ( ! $user ) {
			/* translators: %s: identifier */
			return $this->error_result( sprintf( __( 'User \'%s\' not found.', 'vibe-ai' ), $positional[0] ) );
		}
		$roles = array_slice( $positional, 1 );
		foreach ( $roles as $r ) {
			if ( ! get_role( $r ) ) {
				/* translators: %s: role slug */
				return $this->error_result( sprintf( __( 'Role doesn\'t exist: %s', 'vibe-ai' ), $r ) );
			}
		}
		$remaining = array_values( array_diff( (array) $user->roles, $roles ) );
		$guard     = $this->guard_role_demotion( $user, $remaining );
		if ( $guard ) {
			return $guard;
		}
		foreach ( $roles as $r ) {
			$user->remove_role( $r );
		}
		WPVibe_Change_Tracker::mark( array(
			'summary'      => 'User roles removed: ' . $user->user_login . ' -> ' . implode( ',', $roles ),
			'action_label' => 'Manage Users',
			'admin_url'    => admin_url( 'users.php' ),
		) );
		return $this->success_result( array(
			'message' => sprintf(
				/* translators: 1: role list, 2: login, 3: user ID */
				_n( 'Removed \'%1$s\' role from user \'%2$s\' (#%3$d).', 'Removed the \'%1$s\' roles from user \'%2$s\' (#%3$d).', count( $roles ), 'vibe-ai' ),
				implode( "', '", $roles ),
				$user->user_login,
				(int) $user->ID
			),
		) );
	}


	private function handle_user_meta_get( $positional, $flags ) {
		$reject = $this->reject_unknown_flags( 'user meta get', $flags, array(), array(
			'format' => __( 'The value is returned as JSON; drop --format.', 'vibe-ai' ),
		) );
		if ( $reject ) {
			return $reject;
		}
		if ( count( $positional ) < 2 ) {
			return $this->error_result( __( 'Usage: user meta get <id|login|email> <key>', 'vibe-ai' ) );
		}
		$user = $this->resolve_user( $positional[0] );
		if ( ! $user ) {
			/* translators: %s: identifier */
			return $this->error_result( sprintf( __( 'Invalid user ID, email or login: \'%s\'', 'vibe-ai' ), $positional[0] ) );
		}
		$key   = (string) $positional[1];
		$value = get_user_meta( (int) $user->ID, $key, true );
		if ( '' === $value || null === $value ) {
			/* translators: 1: key, 2: user ID */
			return $this->error_result( sprintf( __( 'No \'%1$s\' meta found for user %2$d.', 'vibe-ai' ), $key, (int) $user->ID ) );
		}
		if ( $this->is_withheld_user_meta( $key ) ) {
			return $this->success_result( array(
				'meta_key'       => $key,
				'value_withheld' => true,
				'note'           => __( 'This key stores credential or secret data (session hashes, application passwords, 2FA seeds, API keys); its value is withheld from output. View it in wp-admin if needed.', 'vibe-ai' ),
			) );
		}
		return $this->success_result( $value );
	}


	private function handle_user_meta_list( $positional, $flags ) {
		$reject = $this->reject_unknown_flags( 'user meta list', $flags, array( 'keys', 'fields', 'format' ), array(
			'orderby'     => __( 'Rows come back in storage order; sort them yourself.', 'vibe-ai' ),
			'order'       => __( 'Rows come back in storage order; sort them yourself.', 'vibe-ai' ),
			'unserialize' => __( 'Values are already unserialized in the output.', 'vibe-ai' ),
		) );
		if ( $reject ) {
			return $reject;
		}
		$fmt = $this->reject_unsupported_format( 'user meta list', $flags );
		if ( $fmt ) {
			return $fmt;
		}
		if ( empty( $positional[0] ) ) {
			return $this->error_result( __( 'Usage: user meta list <id|login|email> [--keys=<key,key>]', 'vibe-ai' ) );
		}
		$user = $this->resolve_user( $positional[0] );
		if ( ! $user ) {
			/* translators: %s: identifier */
			return $this->error_result( sprintf( __( 'Invalid user ID, email or login: \'%s\'', 'vibe-ai' ), $positional[0] ) );
		}
		$only = isset( $flags['keys'] ) ? array_filter( array_map( 'trim', explode( ',', (string) $flags['keys'] ) ) ) : array();
		$all  = get_user_meta( (int) $user->ID );
		$rows = array();
		foreach ( (array) $all as $key => $values ) {
			if ( $only && ! in_array( $key, $only, true ) ) {
				continue;
			}
			$withheld = $this->is_withheld_user_meta( $key );
			foreach ( (array) $values as $v ) {
				if ( $withheld ) {
					$mv = array( 'value_withheld' => true );
				} else {
					$mv = maybe_unserialize( $v );
					if ( is_string( $mv ) && mb_strlen( $mv ) > 500 ) {
						$mv = mb_substr( $mv, 0, 500 ) . '... [truncated]';
					}
				}
				$rows[] = array( 'user_id' => (int) $user->ID, 'meta_key' => $key, 'meta_value' => $mv );
			}
		}
		return $this->success_result( $this->filter_fields( $rows, $flags ) );
	}


	private function handle_user_meta_add( $positional, $flags ) {
		return $this->user_meta_write( 'add', $positional, $flags );
	}


	private function handle_user_meta_update( $positional, $flags ) {
		return $this->user_meta_write( 'update', $positional, $flags );
	}


	private function handle_user_meta_delete( $positional, $flags ) {
		return $this->user_meta_write( 'delete', $positional, $flags );
	}


	private function user_meta_write( $mode, $positional, $flags ) {
		// --all would wipe wp_capabilities (instant role loss); refuse it before
		// the arg-count check, since the --all form legitimately omits the key.
		if ( 'delete' === $mode && ! empty( $flags['all'] ) ) {
			return $this->error_result( __( 'Refused: `user meta delete --all` would delete every meta row for the user, including the capability and session keys that define their access. Delete specific keys by name instead.', 'vibe-ai' ) );
		}
		$reject = $this->reject_unknown_flags( 'user meta ' . $mode, $flags, array( 'force' ), array(
			'format' => __( 'Structured JSON values ({...}/[...]) are decoded automatically; drop --format.', 'vibe-ai' ),
		) );
		if ( $reject ) {
			return $reject;
		}
		$min = ( 'delete' === $mode ) ? 2 : 3;
		if ( count( $positional ) < $min ) {
			$usage = ( 'delete' === $mode )
				? __( 'Usage: user meta delete <id|login|email> <key> [<value>]', 'vibe-ai' )
				/* translators: %s: subcommand */
				: sprintf( __( 'Usage: user meta %s <id|login|email> <key> <value>', 'vibe-ai' ), $mode );
			return $this->error_result( $usage );
		}
		$user = $this->resolve_user_for_write( $positional[0] );
		if ( is_wp_error( $user ) ) {
			return $this->error_result( $user->get_error_message() );
		}
		if ( ! $user ) {
			/* translators: %s: identifier */
			return $this->error_result( sprintf( __( 'Invalid user ID, email or login: \'%s\'', 'vibe-ai' ), $positional[0] ) );
		}
		$key = (string) $positional[1];

		// Hard denylist FIRST, unconditional (no --force, refused even on the
		// approved path): these keys ARE the capability/session system's storage.
		// Writing wp_capabilities grants roles around every cap check; clobbering
		// session_tokens force-logs-out every session (incl. the human's) and can
		// extend an attacker's own. (A session write alone is not a full forgery:
		// the auth-cookie HMAC also needs the wp-config salts, which `config get`
		// blocks. It stays denied as a denial/persistence primitive.)
		if ( $this->is_hard_denied_user_meta( $key ) ) {
			/* translators: %s: meta key */
			return $this->error_result( sprintf( __( 'Refused: \'%s\' stores WordPress capabilities or session/credential data. Writing it via user meta would change roles or sessions around the permission system. Change roles with `user set-role`/`user add-role`/`user remove-role`; there is no --force override for this key.', 'vibe-ai' ), $key ) );
		}
		// Other protected keys (_-prefixed / registered): --force override, matching post meta.
		if ( empty( $flags['force'] ) && is_protected_meta( $key, 'user' ) ) {
			/* translators: %s: meta key */
			return $this->error_result( sprintf( __( 'Meta key \'%s\' is a protected/internal key. Use --force to override.', 'vibe-ai' ), $key ) );
		}

		if ( 'delete' === $mode ) {
			$value   = isset( $positional[2] ) ? $this->maybe_decode_meta_value( $positional[2] ) : '';
			$deleted = delete_user_meta( (int) $user->ID, $key, $value );
			if ( ! $deleted ) {
				/* translators: 1: key, 2: user ID */
				return $this->error_result( sprintf( __( 'Failed to delete \'%1$s\' meta for user %2$d (key or value not found).', 'vibe-ai' ), $key, (int) $user->ID ) );
			}
			$msg     = sprintf( /* translators: 1: key, 2: user ID */ __( 'Deleted \'%1$s\' meta from user %2$d.', 'vibe-ai' ), $key, (int) $user->ID );
			$summary = 'User meta deleted: #' . (int) $user->ID . ' -> ' . $key;
		} elseif ( 'add' === $mode ) {
			$value = $this->maybe_decode_meta_value( $positional[2] );
			$res   = add_user_meta( (int) $user->ID, $key, $value );
			if ( ! $res ) {
				/* translators: 1: key, 2: user ID */
				return $this->error_result( sprintf( __( 'Failed to add \'%1$s\' meta for user %2$d.', 'vibe-ai' ), $key, (int) $user->ID ) );
			}
			$msg     = sprintf( /* translators: 1: key, 2: user ID */ __( 'Added \'%1$s\' meta on user %2$d.', 'vibe-ai' ), $key, (int) $user->ID );
			$summary = 'User meta added: #' . (int) $user->ID . ' -> ' . $key;
		} else {
			$value   = $this->maybe_decode_meta_value( $positional[2] );
			$current = get_user_meta( (int) $user->ID, $key, true );
			if ( $current === $value ) {
				// Upstream short-circuits an unchanged value as success with no write.
				return $this->success_result( array(
					/* translators: 1: key, 2: user ID */
					'message' => sprintf( __( 'Value passed for \'%1$s\' on user %2$d is unchanged.', 'vibe-ai' ), $key, (int) $user->ID ),
				) );
			}
			if ( false === update_user_meta( (int) $user->ID, $key, $value ) ) {
				// A filter (or DB fault) short-circuited the write; do not claim success.
				/* translators: 1: key, 2: user ID */
				return $this->error_result( sprintf( __( 'Failed to update \'%1$s\' meta on user %2$d.', 'vibe-ai' ), $key, (int) $user->ID ) );
			}
			$msg     = sprintf( /* translators: 1: key, 2: user ID */ __( 'Updated \'%1$s\' meta on user %2$d.', 'vibe-ai' ), $key, (int) $user->ID );
			$summary = 'User meta updated: #' . (int) $user->ID . ' -> ' . $key;
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => $summary,
			'action_label' => 'Manage Users',
			'admin_url'    => admin_url( 'users.php' ),
		) );
		return $this->success_result( array( 'message' => $msg ) );
	}

}
