<?php
/**
 * WP-CLI emulator: destructive classification and approval dry-run previews.
 *
 * Extracted from class-wpvibe-cli.php (mechanical split; no behavior change).
 * Handlers may still re-check $this->skip_destructive; this trait is not the
 * entire approval path.
 */

defined( 'ABSPATH' ) || exit;

trait WPVibe_CLI_Security {


	// ------------------------------------------------------------------
	// Destructive classifier
	// ------------------------------------------------------------------

	/**
	 * Detect whether a command needs explicit human approval before execution.
	 * Returns null when safe to auto-execute, or an array{reason, operation, dry_run}
	 * the Worker wraps into an approval URL.
	 *
	 * The list is intentionally narrow — most operations auto-execute. See
	 * PRICING.md / the destructive-actions plan for the full rationale.
	 */
	private function classify_destructive( $command_key, $meta, $tokens, $key_length ) {
		// MUST parse exactly like dispatch() — the approval gate previews what
		// the handler will execute, so both use the one split_tokens().
		list( $positional, $flags ) = $this->split_tokens( $tokens, $key_length );

		// Gate on irreversibility, not count. Reversible ops run freely at any
		// scale — a trash (post delete) is restorable, and post update keeps a
		// WordPress revision. Only irreversible ops confirm: user delete and
		// plugin uninstall (no trash analog), and post delete --force (bypasses
		// trash, permanent). When an irreversible op names several targets,
		// enumerate them so one approval shows the full list. Three explicit IDs
		// is not "bulk" — the trigger is permanence, not how many.
		$force_delete = ( 'post delete' === $command_key && ! empty( $flags['force'] ) );
		if ( ( ! empty( $meta['destructive'] ) || $force_delete ) && ! empty( $meta['bulk'] ) ) {
			$offset  = isset( $meta['bulk']['offset'] ) ? (int) $meta['bulk']['offset'] : 0;
			$targets = array_slice( $positional, $offset );
			if ( count( $targets ) > 1 ) {
				// Force-delete shares an operation prefix across single + bulk so a
				// session bypass (post_delete_force:*) covers both forms.
				$prefix = $force_delete ? 'post_delete_force' : $command_key;
				$reason = $force_delete
					/* translators: %d: number of posts */
					? sprintf( __( 'Permanently deletes %d posts, bypassing trash — they cannot be restored. Review the list before approving.', 'vibe-ai' ), count( $targets ) )
					/* translators: 1: command, 2: target count */
					: sprintf( __( 'Permanently affects %2$d targets via "%1$s" and cannot be undone. Review the list before approving.', 'vibe-ai' ), $command_key, count( $targets ) );
				return array(
					'operation' => $prefix . ':bulk:' . implode( ',', $targets ),
					'reason'    => $reason,
					'dry_run'   => $this->build_bulk_dry_run( $command_key, $meta['bulk'], $targets, $flags ),
				);
			}
		}

		// Single-target unconditionally-destructive: user delete, plugin uninstall.
		if ( ! empty( $meta['destructive'] ) ) {
			return array(
				'operation' => $command_key . ':' . ( $positional[0] ?? '?' ),
				'reason'    => $this->reason_for_command( $command_key ),
				'dry_run'   => $this->build_dry_run( $command_key, $positional, $flags ),
			);
		}

		// search-replace rewrites content in place across tables. --dry-run is a
		// pure read and runs freely; the live run needs approval with a
		// match-count preview.
		if ( 'search-replace' === $command_key ) {
			if ( ! empty( $flags['dry_run'] ) ) {
				return null;
			}
			$old = $positional[0] ?? '';
			if ( '' === $old || ! isset( $positional[1] ) ) {
				return null; // Handler will return a usage error.
			}
			$new = $positional[1];
			return array(
				'operation' => 'search_replace:' . $old . '=>' . $new,
				'reason'    => __( 'search-replace rewrites database content in place, table by table. It handles serialized data safely, but the change is irreversible without a backup. Review the per-table match counts before approving.', 'vibe-ai' ),
				'dry_run'   => $this->build_search_replace_dry_run( $old, $new, array_slice( $positional, 2 ), $flags ),
			);
		}

		// plugin install --force replaces an existing install in place (the
		// emulated rollback/downgrade path). Fresh installs run freely; force
		// is only destructive when there are files to destroy.
		if ( 'plugin install' === $command_key && ! empty( $flags['force'] ) && ! empty( $positional[0] ) ) {
			$slug = sanitize_key( $positional[0] );
			$file = $this->resolve_plugin_file( $slug );
			// Gate on what clear_destination will actually delete (the slug
			// directory), not only on a get_plugins()-parseable install — a
			// broken/half-updated directory is the rollback case itself.
			$dir_exists = defined( 'WP_PLUGIN_DIR' ) && is_dir( trailingslashit( WP_PLUGIN_DIR ) . $slug );
			if ( $file || $dir_exists ) {
				if ( ! function_exists( 'get_plugins' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}
				$all       = get_plugins();
				$installed = ( $file && isset( $all[ $file ] ) ) ? $all[ $file ] : array();
				$dry_run   = array(
					'target'            => $slug,
					'name'              => isset( $installed['Name'] ) ? $installed['Name'] : $slug,
					'installed_version' => isset( $installed['Version'] ) ? $installed['Version'] : '?',
					'requested_version' => ! empty( $flags['version'] ) ? $flags['version'] : 'latest',
					'active'            => $file ? is_plugin_active( $file ) : false,
				);
				if ( ! $file ) {
					$dry_run['note'] = __( 'The existing directory is not a readable plugin (possibly a broken or partial install); its files will still be deleted and replaced.', 'vibe-ai' );
				}
				return array(
					'operation' => 'plugin_install_force:' . $slug,
					'reason'    => __( 'plugin install --force replaces the installed plugin files in place. Downgrading past a version that migrated its data can break the plugin, and any manual edits to its files are lost. Review the version change before approving.', 'vibe-ai' ),
					'dry_run'   => $dry_run,
				);
			}
		}

		// db query: mutating SQL needs approval. Bare-word verbs, plus REPLACE
		// matched only as a statement so the REPLACE() string function inside a
		// read-only SELECT is not misread as a write.
		if ( 'db query' === $command_key ) {
			$sql = trim( implode( ' ', $positional ) );
			if ( '' === $sql ) {
				return null; // Handler will return a usage error.
			}
			$stripped   = preg_replace( '/--.*$/m', '', $sql );
			$stripped   = preg_replace( '/\/\*.*?\*\//s', '', $stripped );
			$normalized = preg_replace( '/\s+/', ' ', strtoupper( trim( $stripped ) ) );
			$mutating   = array( 'DELETE', 'UPDATE', 'DROP', 'TRUNCATE', 'ALTER', 'INSERT', 'CREATE', 'RENAME', 'GRANT', 'REVOKE' );
			$matched    = null;
			foreach ( $mutating as $kw ) {
				if ( preg_match( '/\b' . $kw . '\b/', $normalized ) ) {
					$matched = $kw;
					break;
				}
			}
			if ( null === $matched && preg_match( '/\bREPLACE\s+(?:LOW_PRIORITY\s+|DELAYED\s+)?INTO\b/', $normalized ) ) {
				$matched = 'REPLACE';
			}
			if ( null !== $matched ) {
				return array(
					'operation' => 'db_query_' . strtolower( $matched ),
					'reason'    => sprintf(
						/* translators: %s: SQL keyword */
						__( 'Mutating SQL (%s) bypasses all plugin safety. Direct DB writes need explicit approval.', 'vibe-ai' ),
						$matched
					),
					'dry_run'   => $this->build_db_query_dry_run( $matched, $sql, $normalized ),
				);
			}
			return null;
		}

		// Enabling white label hides every WPVibe surface in wp-admin, including
		// the Approval Log. A prompt-injected assistant must not be able to
		// conceal the plugin (and its own audit trail) without a human signing
		// off. Disabling stays approval-free so recovery via AI is easy.
		if ( in_array( $command_key, array( 'option update', 'option add' ), true )
			&& class_exists( 'WPVibe_White_Label' )
			&& WPVibe_White_Label::OPTION === ( $positional[0] ?? '' )
			&& WPVibe_White_Label::truthy( $positional[1] ?? '' ) ) {
			return array(
				'operation' => 'white_label_enable',
				'reason'    => __( 'Hides every WPVibe surface in this WordPress dashboard for ALL users: admin menu, dashboard widget, Plugins list entry, editor sidebar, and the Approval Log. The site stays fully manageable through AI, and WordPress auto-updates are switched on for the plugin so it stays current while hidden. It unhides automatically if the site is disconnected for 30 days.', 'vibe-ai' ),
				'dry_run'   => array(
					'command' => 'wp option update ' . WPVibe_White_Label::OPTION . ' 1',
					'note'    => __( 'To undo later: set the option to 0 (no approval needed) or delete it via WP-CLI.', 'vibe-ai' ),
				),
			);
		}

		// Options have no trash: deleting one permanently destroys whatever
		// configuration lived in it. AI temp state (wpvibe_task_*) and
		// transient rows stay approval-free so the hygiene cleanup loop
		// doesn't drown the user; one bypass approval covers option delete:*.
		if ( 'option delete' === $command_key ) {
			// Every key is examined, not just the first: the handler deletes them
			// all, so exempting the list on $positional[0] would let an ordinary
			// option ride along behind a leading wpvibe_task_ key.
			$gated = array();
			foreach ( (array) $positional as $key ) {
				$key = (string) $key;
				// Deleting the white-label option UNhides the plugin — that's recovery, not destruction.
				if ( '' === $key
					|| 0 === strpos( $key, 'wpvibe_task_' )
					|| 0 === strpos( $key, '_transient_' )
					|| 0 === strpos( $key, '_site_transient_' )
					|| ( class_exists( 'WPVibe_White_Label' ) && WPVibe_White_Label::OPTION === $key ) ) {
					continue;
				}
				$gated[] = $key;
			}
			if ( empty( $gated ) ) {
				return null;
			}
			if ( 1 === count( $gated ) ) {
				return array(
					'operation' => 'option delete:' . $gated[0],
					'reason'    => __( 'Options are deleted permanently (WordPress has no trash for them), and a plugin\'s entire configuration can live in a single option. Review the value preview before approving.', 'vibe-ai' ),
					'dry_run'   => $this->build_option_delete_dry_run( $gated[0] ),
				);
			}
			return array(
				'operation' => 'option delete:bulk:' . implode( ',', $gated ),
				/* translators: %d: number of options */
				'reason'    => sprintf( __( 'Deletes %d options permanently (WordPress has no trash for them), and a plugin\'s entire configuration can live in a single option. Review each value preview before approving.', 'vibe-ai' ), count( $gated ) ),
				'dry_run'   => array(
					'command' => 'wp option delete',
					'count'   => count( $gated ),
					'targets' => array_map( array( $this, 'build_option_delete_dry_run' ), $gated ),
				),
			);
		}

		// `option patch delete` unsets a subtree of an option in place, which is
		// as permanent as deleting the option itself — same "options have no
		// trash" rationale as the branch above. insert/update overwrite a leaf
		// whose prior value the preview shows, so they stay approval-free.
		if ( 'option patch' === $command_key && 'delete' === ( $positional[0] ?? '' ) ) {
			$key  = $positional[1] ?? '';
			$path = array_slice( $positional, 2 );
			if ( '' === $key || empty( $path )
				|| 0 === strpos( $key, 'wpvibe_task_' )
				|| 0 === strpos( $key, '_transient_' )
				|| 0 === strpos( $key, '_site_transient_' ) ) {
				return null; // Handler returns a usage error, or AI temp state.
			}
			return array(
				'operation' => 'option patch delete:' . $key . ':' . implode( '.', $path ),
				'reason'    => __( 'Removes a key from inside an option permanently. WordPress has no trash for options, and the surrounding option keeps its other keys, so the loss is easy to miss. Review the value preview before approving.', 'vibe-ai' ),
				'dry_run'   => $this->build_option_patch_delete_dry_run( $key, $path ),
			);
		}

		// Bulk transient wipes — `wp transient delete --all` clears every
		// transient including licensing tokens, refresh tokens, cached API
		// responses, etc. Recovery is impossible. Same threat profile as a
		// destructive option op even though the cap is just manage_options.
		if ( 'transient delete' === $command_key && ( ! empty( $flags['all'] ) || ! empty( $flags['expired'] ) ) ) {
			$scope = ! empty( $flags['all'] ) ? 'all' : 'expired';
			return array(
				'operation' => 'transient_delete_' . $scope,
				'reason'    => 'all' === $scope
					? __( '--all wipes every transient on the site, including license tokens, refresh tokens, cached API responses, and any per-plugin state stored as a transient. Cannot be undone.', 'vibe-ai' )
					: __( '--expired removes every transient WP considers expired. Usually safe (these are caches) but the operation is unbounded — call it out so the user sees what is going.', 'vibe-ai' ),
				'dry_run'   => array(
					'command' => 'wp transient delete --' . $scope,
					'note'    => 'all' === $scope
						? __( 'Every wp_options row whose name starts with _transient_ is deleted. Site transients (_site_transient_ rows) are left untouched.', 'vibe-ai' )
						: __( 'Every transient whose expiration timestamp is in the past is deleted.', 'vibe-ai' ),
				),
			);
		}

		// On `post meta delete`, --force means the opposite of what it means on
		// `post delete`: it disables the is_protected_meta() rail rather than
		// bypassing trash. Either way it removes a safety net, and post meta is
		// not kept in revisions, so a builder's layout (_elementor_data et al)
		// is unrecoverable. Without a value argument every row for the key goes.
		// Only the all-rows wipe gates. A third positional names one exact value, so the
		// caller already had to know what they were removing, and `post meta update`
		// overwrites a value just as unrecoverably with no gate at all — prompting on the
		// narrow form mostly teaches people to approve routine work, which is how a gate
		// stops carrying signal. The handler's own is_protected_meta() rail is unchanged.
		if ( ! empty( $flags['force'] ) && 'post meta delete' === $command_key && ! isset( $positional[2] ) ) {
			$post_id = $positional[0] ?? '?';
			$meta_key = $positional[1] ?? '?';
			return array(
				'operation' => 'post_meta_delete_force:' . $post_id . ':' . $meta_key,
				/* translators: 1: meta key, 2: post ID */
				'reason'    => sprintf( __( '--force overrides the protected-meta guard and deletes every \'%1$s\' row on post %2$s. Post meta is not stored in revisions, so page-builder layouts and template settings cannot be restored afterwards.', 'vibe-ai' ), $meta_key, $post_id ),
				'dry_run'   => $this->build_post_meta_delete_dry_run( $post_id, $meta_key, null ),
			);
		}

		// --force flag bypassing trash (post delete --force).
		if ( ! empty( $flags['force'] ) && 'post delete' === $command_key ) {
			$target = $positional[0] ?? '?';
			return array(
				'operation' => 'post_delete_force:' . $target,
				'reason'    => __( '--force bypasses trash and permanently deletes content. The post cannot be restored.', 'vibe-ai' ),
				'dry_run'   => array(
					'command'   => 'wp post delete --force',
					'target_id' => $target,
					'note'      => __( 'Without --force, the post would move to trash and be restorable. With --force, it is permanently deleted.', 'vibe-ai' ),
				),
			);
		}

		return null;
	}


	private function reason_for_command( $command_key ) {
		$reasons = array(
			'user delete'       => __( 'User deletion removes the account permanently. Authored content references are fragile and reassignment requires manual care.', 'vibe-ai' ),
			'plugin uninstall'  => __( 'Plugin uninstall removes the plugin from the filesystem (different from deactivate). Plugin data and settings are typically lost.', 'vibe-ai' ),
			'cron event run'    => __( 'Runs the hook\'s scheduled callbacks immediately. Cron callbacks can do anything the owning plugin can do (send emails, hit APIs, modify data).', 'vibe-ai' ),
			'cron event delete' => __( 'Removes every scheduled instance of this hook. If the owning plugin depends on it, its background work silently stops until something reschedules it.', 'vibe-ai' ),
			'theme delete'      => __( 'Theme delete removes the theme from the filesystem. Any customizations inside the theme folder are lost.', 'vibe-ai' ),
			'cap add'           => __( 'Grants capabilities to every user with this role. Capabilities are the WordPress security boundary — review the literal grant below.', 'vibe-ai' ),
			'cap remove'        => __( 'Removes capabilities from every user with this role and can lock people out of workflows they rely on.', 'vibe-ai' ),
			'role create'       => __( 'Creates a new role definition. Cloned capabilities take effect for anyone later assigned this role.', 'vibe-ai' ),
			'role delete'       => __( 'Deletes the role definition. Users currently holding it are left with no role until reassigned.', 'vibe-ai' ),
			'role reset'        => __( 'Resets the role to its WordPress-default capabilities: custom grants are removed and removed defaults restored.', 'vibe-ai' ),
			'user add-cap'      => __( 'Grants a capability directly to one user, on top of what their role provides.', 'vibe-ai' ),
			'user remove-cap'   => __( 'Removes a capability granted directly to this user (role-derived capabilities are unaffected).', 'vibe-ai' ),
		);
		return $reasons[ $command_key ] ?? __( 'This operation is classified as destructive and requires explicit approval.', 'vibe-ai' );
	}


	private function build_dry_run( $command_key, $positional, $flags ) {
		if ( 'user delete' === $command_key ) {
			// Must resolve identically to the execution path (id|login|email via
			// resolve_user), or the preview says "will fail" and then deletes.
			$user = ! empty( $positional[0] ) ? $this->resolve_user( $positional[0] ) : null;
			if ( ! $user ) {
				return array( 'target' => $positional[0] ?? '?', 'note' => __( 'User not found — execution will fail.', 'vibe-ai' ) );
			}
			$post_count = (int) count_user_posts( $user->ID );
			return array(
				'target'         => $user->user_login,
				'user_id'        => $user->ID,
				'email'          => $user->user_email,
				'roles'          => $user->roles,
				'authored_posts' => $post_count,
				'reassign_to'    => $flags['reassign'] ?? null,
			);
		}
		if ( 'plugin uninstall' === $command_key ) {
			$slug = $positional[0] ?? '?';
			$file = $this->resolve_plugin_file( $slug );
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$all = get_plugins();
			if ( ! $file || ! isset( $all[ $file ] ) ) {
				return array( 'target' => $slug, 'note' => __( 'Plugin not found — execution will fail.', 'vibe-ai' ) );
			}
			return array(
				'target'   => $slug,
				'name'     => $all[ $file ]['Name'],
				'version'  => $all[ $file ]['Version'],
				'active'   => is_plugin_active( $file ),
				'file'     => $file,
			);
		}
		if ( 'cron event run' === $command_key || 'cron event delete' === $command_key ) {
			return $this->describe_target( 'cron_hook', $positional[0] ?? '?' );
		}
		if ( 'theme delete' === $command_key ) {
			return $this->describe_target( 'theme', $positional[0] ?? '?' );
		}
		if ( in_array( $command_key, array( 'cap add', 'cap remove', 'role create', 'role delete', 'role reset', 'user add-cap', 'user remove-cap' ), true ) ) {
			return $this->build_role_cap_dry_run( $command_key, $positional, $flags );
		}
		return array( 'command' => $command_key, 'positional' => $positional, 'flags' => $flags );
	}


	/** Approval preview for role/capability edits: the literal grant, spelled out. */
	private function build_role_cap_dry_run( $command_key, $positional, $flags ) {
		$dry = array( 'command' => 'wp ' . $command_key );

		if ( 'cap add' === $command_key || 'cap remove' === $command_key ) {
			$role = $positional[0] ?? '?';
			$caps = array_slice( $positional, 1 );
			$dry['role']         = $role;
			$dry['capabilities'] = $caps;
			$dry['summary']      = 'cap add' === $command_key
				/* translators: 1: capability list, 2: role */
				? sprintf( __( 'Add %1$s to role `%2$s`.', 'vibe-ai' ), '`' . implode( '`, `', $caps ) . '`', $role )
				/* translators: 1: capability list, 2: role */
				: sprintf( __( 'Remove %1$s from role `%2$s`.', 'vibe-ai' ), '`' . implode( '`, `', $caps ) . '`', $role );
			$high = array_values( array_intersect( $caps, self::HIGH_RISK_CAPS ) );
			if ( $high && 'cap add' === $command_key ) {
				$dry['high_risk_capabilities'] = $high;
				/* translators: %s: capability list */
				$dry['warning'] = sprintf( __( '%s grant administrator-equivalent power. A user with these capabilities can take over the site.', 'vibe-ai' ), '`' . implode( '`, `', $high ) . '`' );
			}
			if ( 'cap remove' === $command_key && 'administrator' === $role && array_intersect( $caps, self::CORE_ADMIN_CAPS ) ) {
				$dry['warning'] = __( 'Removing core capabilities from the administrator role is refused at execution (lockout protection).', 'vibe-ai' );
			}
			return $dry;
		}

		if ( 'role create' === $command_key ) {
			$dry['role_key']  = $positional[0] ?? '?';
			$dry['role_name'] = $positional[1] ?? '';
			if ( ! empty( $flags['clone'] ) ) {
				$dry['clone_from'] = $flags['clone'];
				$src               = get_role( $flags['clone'] );
				$dry['cloned_capability_count'] = $src ? count( array_filter( $src->capabilities ) ) : null;
			}
			/* translators: %s: role key */
			$dry['summary'] = sprintf( __( 'Create role `%s`.', 'vibe-ai' ), $dry['role_key'] );
			return $dry;
		}

		if ( 'role delete' === $command_key || 'role reset' === $command_key ) {
			$targets = ! empty( $flags['all'] ) && 'role reset' === $command_key ? self::DEFAULT_ROLES : $positional;
			$dry['roles'] = array();
			foreach ( $targets as $role_key ) {
				$role_obj = get_role( $role_key );
				$entry    = array( 'role' => $role_key, 'exists' => (bool) $role_obj );
				if ( $role_obj ) {
					$entry['capability_count'] = count( array_filter( $role_obj->capabilities ) );
					$users                     = count_users();
					$entry['user_count']       = isset( $users['avail_roles'][ $role_key ] ) ? (int) $users['avail_roles'][ $role_key ] : 0;
				}
				$dry['roles'][] = $entry;
			}
			if ( 'role delete' === $command_key && in_array( 'administrator', $targets, true ) ) {
				$dry['warning'] = __( 'Deleting the administrator role is refused at execution (lockout protection).', 'vibe-ai' );
			}
			if ( 'role delete' === $command_key ) {
				$dry['note'] = __( 'Users holding a deleted role are left with no role until reassigned.', 'vibe-ai' );
			}
			return $dry;
		}

		// user add-cap / user remove-cap
		$dry = array_merge( $dry, $this->describe_target( 'user', $positional[0] ?? '?' ) );
		$cap = $positional[1] ?? '?';
		$dry['capability'] = $cap;
		$dry['summary']    = 'user add-cap' === $command_key
			/* translators: 1: capability, 2: user */
			? sprintf( __( 'Grant `%1$s` directly to user `%2$s`.', 'vibe-ai' ), $cap, $positional[0] ?? '?' )
			/* translators: 1: capability, 2: user */
			: sprintf( __( 'Remove the direct `%1$s` grant from user `%2$s`.', 'vibe-ai' ), $cap, $positional[0] ?? '?' );
		if ( 'user add-cap' === $command_key && in_array( $cap, self::HIGH_RISK_CAPS, true ) ) {
			$dry['high_risk_capabilities'] = array( $cap );
			/* translators: %s: capability */
			$dry['warning'] = sprintf( __( '`%s` grants administrator-equivalent power.', 'vibe-ai' ), $cap );
		}
		return $dry;
	}


	/**
	 * Build the enumerated preview for a bulk op. Generic across target types
	 * (post / user / plugin); the per-target labeling lives in describe_target.
	 * Capped so a 5,000-id bulk doesn't produce a 5,000-row preview.
	 */
	private function build_bulk_dry_run( $command_key, $bulk_meta, $targets, $flags ) {
		$type = isset( $bulk_meta['label'] ) ? $bulk_meta['label'] : 'item';
		$cap  = 100;
		$enum = array();
		foreach ( array_slice( $targets, 0, $cap ) as $t ) {
			$enum[] = $this->describe_target( $type, $t );
		}

		$dry = array(
			'command'           => 'wp ' . $command_key . ( ! empty( $flags['force'] ) ? ' --force' : '' ),
			'count'             => count( $targets ),
			'targets'           => $enum,
			'targets_truncated' => count( $targets ) > $cap,
		);

		if ( 'post delete' === $command_key ) {
			$dry['note'] = ! empty( $flags['force'] )
				? __( '--force permanently deletes these posts (no trash, not restorable).', 'vibe-ai' )
				: __( 'Posts move to trash and remain restorable.', 'vibe-ai' );
		} elseif ( 'post update' === $command_key ) {
			$changes = array();
			foreach ( array( 'post_title', 'post_content', 'post_status', 'post_excerpt', 'post_name', 'post_parent', 'menu_order', 'comment_status', 'post_type' ) as $field ) {
				if ( isset( $flags[ $field ] ) ) {
					$changes[ $field ] = $flags[ $field ];
				}
			}
			$dry['changes'] = $changes;
		} elseif ( 'user delete' === $command_key && ! empty( $flags['reassign'] ) ) {
			$dry['reassign_to'] = $flags['reassign'];
		}

		return $dry;
	}


	/** Resolve a single bulk target to a human-reviewable descriptor by type. */
	private function describe_target( $type, $t ) {
		switch ( $type ) {
			case 'post':
				$post = get_post( (int) $t );
				return $post
					? array( 'id' => (int) $t, 'title' => get_the_title( $post ), 'type' => $post->post_type, 'status' => $post->post_status )
					: array( 'id' => (int) $t, 'note' => __( 'not found', 'vibe-ai' ) );
			case 'user':
				$user = is_numeric( $t )
					? get_user_by( 'id', (int) $t )
					: ( is_email( $t ) ? get_user_by( 'email', $t ) : get_user_by( 'login', $t ) );
				return $user
					? array( 'target' => $user->user_login, 'id' => (int) $user->ID, 'email' => $user->user_email, 'roles' => $user->roles, 'authored_posts' => (int) count_user_posts( $user->ID ) )
					: array( 'target' => $t, 'note' => __( 'not found', 'vibe-ai' ) );
			case 'plugin':
				$file = $this->resolve_plugin_file( $t );
				if ( ! function_exists( 'get_plugins' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}
				$all = get_plugins();
				return ( $file && isset( $all[ $file ] ) )
					? array( 'target' => $t, 'name' => $all[ $file ]['Name'], 'version' => $all[ $file ]['Version'], 'active' => is_plugin_active( $file ) )
					: array( 'target' => $t, 'note' => __( 'not found', 'vibe-ai' ) );
			case 'cron_hook':
				$instances = 0;
				$next      = null;
				$crons     = function_exists( '_get_cron_array' ) ? _get_cron_array() : array();
				foreach ( (array) $crons as $timestamp => $hooks ) {
					if ( isset( $hooks[ $t ] ) ) {
						$instances += count( $hooks[ $t ] );
						if ( null === $next ) {
							$next = gmdate( 'Y-m-d H:i:s', (int) $timestamp );
						}
					}
				}
				return $instances > 0
					? array( 'target' => $t, 'scheduled_instances' => $instances, 'next_run' => $next )
					: array( 'target' => $t, 'note' => __( 'no scheduled events for this hook', 'vibe-ai' ) );
			case 'theme':
				$theme = wp_get_theme( $t );
				if ( ! $theme->exists() ) {
					return array( 'target' => $t, 'note' => __( 'not found', 'vibe-ai' ) );
				}
				$desc = array( 'target' => $t, 'name' => $theme->get( 'Name' ), 'version' => $theme->get( 'Version' ), 'active' => ( get_stylesheet() === $t ) );
				if ( ! $desc['active'] && get_template() === $t ) {
					$desc['note'] = __( 'PARENT of the active child theme — deleting it breaks the site. Execution will refuse.', 'vibe-ai' );
				}
				return $desc;
			default:
				return array( 'target' => $t );
		}
	}


	/** Approval preview for option delete: what's in it, how big, and whether execution will refuse anyway. */
	private function build_option_delete_dry_run( $key ) {
		$dry   = array( 'command' => 'wp option delete', 'option' => $key );
		$value = get_option( $key, null );
		if ( null === $value ) {
			$dry['note'] = __( 'Option not found, so execution will fail.', 'vibe-ai' );
			return $dry;
		}
		$str                     = is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );
		$dry['value_type']       = strtolower( gettype( $value ) );
		$dry['value_size_chars'] = mb_strlen( $str );
		$dry['value_preview']    = mb_substr( $str, 0, 200 ) . ( strlen( $str ) > 200 ? '… [truncated]' : '' );
		if ( in_array( $key, self::BLOCKED_OPTIONS, true ) ) {
			$dry['warning'] = __( 'This option is permanently protected by WPVibe; execution will refuse even after approval.', 'vibe-ai' );
		}
		return $dry;
	}


	private function build_option_patch_delete_dry_run( $key, $path ) {
		$dry     = array( 'command' => 'wp option patch delete', 'option' => $key, 'key_path' => implode( '.', $path ) );
		$current = get_option( $key, null );
		if ( null === $current ) {
			$dry['note'] = __( 'Option not found, so execution will fail.', 'vibe-ai' );
			return $dry;
		}
		if ( ! is_array( $current ) && ! is_object( $current ) ) {
			$dry['note'] = __( 'Option is not an array or object, so execution will fail.', 'vibe-ai' );
			return $dry;
		}
		// Walk to the leaf so the preview shows what this key path actually drops.
		$node = $current;
		foreach ( $path as $segment ) {
			$seg = is_numeric( $segment ) ? (int) $segment : $segment;
			if ( is_object( $node ) ) {
				$node = isset( $node->$seg ) ? $node->$seg : null;
			} elseif ( is_array( $node ) && array_key_exists( $seg, $node ) ) {
				$node = $node[ $seg ];
			} else {
				$dry['note'] = __( 'Key path not found in this option, so execution will fail.', 'vibe-ai' );
				return $dry;
			}
			if ( null === $node ) {
				$dry['note'] = __( 'Key path not found in this option, so execution will fail.', 'vibe-ai' );
				return $dry;
			}
		}
		$str                     = is_scalar( $node ) ? (string) $node : (string) wp_json_encode( $node );
		$dry['value_type']       = strtolower( gettype( $node ) );
		$dry['value_size_chars'] = mb_strlen( $str );
		$dry['value_preview']    = mb_substr( $str, 0, 200 ) . ( strlen( $str ) > 200 ? '… [truncated]' : '' );
		if ( is_array( $node ) || is_object( $node ) ) {
			$dry['warning'] = __( 'This key path holds a nested structure; deleting it removes everything beneath it.', 'vibe-ai' );
		}
		if ( in_array( $key, self::BLOCKED_OPTIONS, true ) ) {
			$dry['warning'] = __( 'This option is permanently protected by WPVibe; execution will refuse even after approval.', 'vibe-ai' );
		}
		return $dry;
	}


	private function build_post_meta_delete_dry_run( $post_id, $meta_key, $value = null ) {
		$dry = array(
			'command'  => 'wp post meta delete --force',
			'post_id'  => $post_id,
			'meta_key' => $meta_key,
			'note'     => null === $value
				? __( 'No value argument: every row stored under this meta key is deleted.', 'vibe-ai' )
				: __( 'Value argument given: only rows matching that value are deleted.', 'vibe-ai' ),
		);
		if ( is_protected_meta( $meta_key, 'post' ) ) {
			$dry['protected'] = true;
			$dry['warning']   = __( 'This is a protected/internal meta key. Without --force the command would refuse; --force overrides that guard.', 'vibe-ai' );
		}
		$existing = get_post_meta( (int) $post_id, $meta_key, false );
		if ( is_array( $existing ) ) {
			$dry['row_count'] = count( $existing );
			if ( ! empty( $existing ) ) {
				$first                = $existing[0];
				$str                  = is_scalar( $first ) ? (string) $first : (string) wp_json_encode( $first );
				$dry['value_preview'] = mb_substr( $str, 0, 200 ) . ( strlen( $str ) > 200 ? '… [truncated]' : '' );
			}
		}
		return $dry;
	}


	private function build_db_query_dry_run( $keyword, $sql, $normalized ) {
		global $wpdb;
		// Resolve {prefix} placeholder so the regex parsers below can find the
		// actual table name. handle_db_query does the same substitution at
		// execute time; we mirror it here so the dry-run preview shows the
		// row count + sample the user is about to mutate.
		$sql = str_replace( '{prefix}', $wpdb->prefix, $sql );
		$preview = array(
			'sql'        => $sql,
			'operation'  => $keyword,
			'table_prefix' => $wpdb->prefix,
		);

		// The WHERE-remainder below is interpolated into preview SQL we execute
		// here (pre-approval). Mirror handle_db_query's stacked-statement guard
		// so a `; second statement` cannot ride in: since the quote-aware gate
		// stopped blocking `;` inside quoted values, this builder can no longer
		// rely on that flat backstop. A stacked statement skips the preview
		// (execution still applies its own guard); it is not silently run.
		if ( preg_match( '/;\s*\S/', $sql ) ) {
			$preview['note'] = __( 'Affected-row preview skipped: the statement could not be safely parsed for preview.', 'vibe-ai' );
			return $preview;
		}

		// Cap counting at this many rows so we don't lock up sites with millions
		// of rows. The subquery LIMIT bounds the scan; outer COUNT(*) returns
		// at most $cap + 1, letting us show "$cap+" instead of a blocking count.
		$cap = 1000;

		// For DELETE/UPDATE we can count affected rows by translating the WHERE.
		if ( 'DELETE' === $keyword && preg_match( '/^DELETE\s+FROM\s+([`\w]+)(.*)$/i', trim( $sql ), $m ) ) {
			$table = trim( $m[1], '`' );
			$rest  = trim( rtrim( $m[2], '; ' ) );
			$count_sql = "SELECT COUNT(*) FROM (SELECT 1 FROM `{$table}` {$rest} LIMIT " . ( $cap + 1 ) . ") AS subq";
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = $wpdb->get_var( $count_sql ); // nosemgrep: direct-db-query
			if ( null !== $count && empty( $wpdb->last_error ) ) {
				$n = (int) $count;
				$preview['affected_count'] = min( $n, $cap );
				if ( $n > $cap ) {
					$preview['affected_count_truncated'] = true;
					/* translators: %d: row-count cap */
					$preview['affected_count_note']      = sprintf( __( 'Count truncated at %d to avoid scanning very large tables; actual affected rows may be higher.', 'vibe-ai' ), $cap );
				}
				$sample_sql = "SELECT * FROM `{$table}` {$rest} LIMIT 5";
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$sample = $wpdb->get_results( $sample_sql, ARRAY_A ); // nosemgrep: direct-db-query
				if ( $sample && empty( $wpdb->last_error ) ) {
					$preview['sample_rows'] = $this->trim_sample_rows( $sample );
				}
			} else {
				$preview['note'] = __( 'Could not preview affected rows (SQL parse failure). Execution will attempt the literal DELETE.', 'vibe-ai' );
			}
		}

		if ( 'UPDATE' === $keyword && preg_match( '/^UPDATE\s+([`\w]+)\s+SET\s+.+?(\s+WHERE\s+.*)?$/is', trim( $sql ), $m ) ) {
			$table = trim( $m[1], '`' );
			$where = isset( $m[2] ) ? trim( rtrim( $m[2], '; ' ) ) : '';
			$count_sql = "SELECT COUNT(*) FROM (SELECT 1 FROM `{$table}` {$where} LIMIT " . ( $cap + 1 ) . ") AS subq";
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = $wpdb->get_var( $count_sql ); // nosemgrep: direct-db-query
			if ( null !== $count && empty( $wpdb->last_error ) ) {
				$n = (int) $count;
				$preview['affected_count'] = min( $n, $cap );
				if ( $n > $cap ) {
					$preview['affected_count_truncated'] = true;
					/* translators: %d: row-count cap */
					$preview['affected_count_note']      = sprintf( __( 'Count truncated at %d to avoid scanning very large tables; actual affected rows may be higher.', 'vibe-ai' ), $cap );
				}
				// Show which rows will change (current values) so the approval is
				// reviewable by content, not just by count — same as the DELETE branch.
				$sample_sql = "SELECT * FROM `{$table}` {$where} LIMIT 5";
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$sample = $wpdb->get_results( $sample_sql, ARRAY_A ); // nosemgrep: direct-db-query
				if ( $sample && empty( $wpdb->last_error ) ) {
					$preview['sample_rows'] = $this->trim_sample_rows( $sample );
				}
			} else {
				$preview['note'] = __( 'Could not preview affected rows (SQL parse failure). Execution will attempt the literal UPDATE.', 'vibe-ai' );
			}
		}

		return $preview;
	}


	/**
	 * Truncate long string values in dry-run sample rows so a preview of a wide
	 * table (e.g. wp_posts.post_content, wp_options.option_value) stays readable
	 * instead of dumping full bodies. Table-agnostic: trims any string cell over
	 * the cap, leaving short identifying columns (ID, title, status) intact.
	 *
	 * @param array $rows Rows from $wpdb->get_results( ..., ARRAY_A ).
	 * @param int   $max  Max characters per string cell.
	 * @return array
	 */
	private function trim_sample_rows( $rows, $max = 200 ) {
		if ( ! is_array( $rows ) ) {
			return $rows;
		}
		foreach ( $rows as &$row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			foreach ( $row as $key => $val ) {
				if ( is_string( $val ) && mb_strlen( $val ) > $max ) {
					/* translators: %d: total character count of the truncated value */
					$row[ $key ] = mb_substr( $val, 0, $max ) . sprintf( __( '... [truncated, %d chars total]', 'vibe-ai' ), mb_strlen( $val ) );
				}
			}
		}
		unset( $row );
		return $rows;
	}

}
