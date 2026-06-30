<?php
/**
 * WP-CLI-compatible command interface backed by native WordPress PHP APIs.
 *
 * Accepts WP-CLI command syntax (e.g., "plugin list --status=active") and
 * dispatches to native WordPress functions. No proc_open, no wp-cli binary needed.
 *
 * Security model:
 * - Commands must have a registered handler in HANDLERS (no arbitrary shell).
 * - Per-command WordPress capability checks.
 * - Respects DISALLOW_FILE_MODS for commands that modify files.
 * - BLOCKED_OPTIONS are HARD-BLOCKED (never approval-gated) — siteurl,
 *   active_plugins, auth_key, etc. No legitimate AI workflow needs to delete those.
 * - Destructive operations (db query with mutating SQL, plugin uninstall,
 *   user delete, --force bypassing trash) return WP_Error('approval_required')
 *   with a dry-run preview. The Worker surfaces an approval URL and re-invokes
 *   via run_approved() after the user confirms in their browser.
 * - DB SELECT queries restricted with LIMIT enforcement.
 * - Dangerous flags are stripped before dispatch.
 */

defined( 'ABSPATH' ) || exit;

class WPVibe_CLI {

	/** Set when the Worker calls run_approved(); allows handlers to proceed past destructive gates. */
	private $skip_destructive = false;

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
		'post meta list'   => array( 'tier' => 'read', 'cap' => 'edit_posts' ),
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
		'plugin uninstall'     => array( 'tier' => 'write', 'cap' => 'delete_plugins', 'check_file_mods' => true, 'destructive' => true, 'bulk' => array( 'label' => 'plugin' ) ),
		'option update'        => array( 'tier' => 'write', 'cap' => 'manage_options' ),
		'option add'           => array( 'tier' => 'write', 'cap' => 'manage_options' ),
		'option delete'        => array( 'tier' => 'write', 'cap' => 'manage_options' ),
		'transient delete'     => array( 'tier' => 'write', 'cap' => 'manage_options' ),
		'transient list'       => array( 'tier' => 'read',  'cap' => 'manage_options' ),
		'user delete'          => array( 'tier' => 'write', 'cap' => 'delete_users', 'destructive' => true, 'bulk' => array( 'label' => 'user' ) ),
		'post create'          => array( 'tier' => 'write', 'cap' => 'edit_posts' ),
		'post update'          => array( 'tier' => 'write', 'cap' => 'edit_posts' ),
		'post delete'          => array( 'tier' => 'write', 'cap' => 'delete_posts', 'bulk' => array( 'label' => 'post' ) ),
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
		'post meta list'    => 'handle_post_meta_get',
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
		'plugin uninstall'  => 'handle_plugin_uninstall',
		'option add'        => 'handle_option_add',
		'option delete'     => 'handle_option_delete',
		'transient delete'  => 'handle_transient_delete',
		'transient list'    => 'handle_transient_list',
		'user delete'       => 'handle_user_delete',
		'search-replace'    => 'handle_not_implemented',
	);

	/**
	 * Run a WP-CLI-style command via native PHP dispatch.
	 *
	 * AI-facing entry point. Destructive commands (db query mutations, user
	 * delete, plugin uninstall, --force bypassing trash) return approval_required
	 * with a dry-run preview. The Worker handles the browser approval flow and
	 * re-invokes via run_approved() once the user confirms.
	 */
	public function run( $command, $confirm_write = false ) {
		return $this->execute( $command, $confirm_write, false );
	}

	/**
	 * Run a WP-CLI-style command, skipping the destructive check.
	 *
	 * Worker-facing entry point. Called from the /cli/run-approved REST endpoint
	 * after browser-side session verification. Trust comes from App Password
	 * auth — the AI cannot reach this endpoint via the MCP tool surface
	 * (run_wp_cli's schema does not expose an "approved" flag, and the Worker
	 * controls all plugin API calls).
	 */
	public function run_approved( $command, $confirm_write = false ) {
		return $this->execute( $command, $confirm_write, true );
	}

	private function execute( $command, $confirm_write, $skip_destructive ) {
		$this->skip_destructive = (bool) $skip_destructive;
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

		// Classify destructive on every path. When !skip_destructive, the
		// classification triggers approval_required. When skip_destructive
		// (post-approval execution), we keep the classification so the audit
		// log can record the dry-run preview alongside the result.
		$destructive = $this->classify_destructive( $command_key, $meta, $args, $key_length );
		if ( $destructive && ! $skip_destructive ) {
			return new WP_Error(
				'approval_required',
				$destructive['reason'],
				array(
					'status'    => 409,
					'operation' => $destructive['operation'],
					'dry_run'   => $destructive['dry_run'],
					'command'   => 'wp ' . $command_key,
				)
			);
		}

		// Dispatch to native handler.
		$start  = microtime( true );
		$result = $this->dispatch( $args, $key_length, $command_key, $confirm_write );
		$elapsed = (int) ( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Append-only audit log for destructive operations. Only writes on the
		// run_approved path so the audit log records actually-executed destructive
		// ops, not every command. Failures are swallowed inside log_execution.
		if ( $this->skip_destructive && $destructive && empty( $result['requires_confirmation'] ) ) {
			WPVibe_Audit_Log::log_execution( array(
				'operation'      => $destructive['operation'],
				'command'        => 'wp ' . $command_key,
				'params'         => array( 'positional' => $args, 'key_length' => $key_length ),
				'dry_run'        => $destructive['dry_run'],
				'result_summary' => isset( $result['stdout'] ) ? mb_substr( (string) $result['stdout'], 0, 500 ) : '',
			) );
		}

		$response = array(
			'command'           => 'wp ' . $command_key,
			// Handler may override the tier when the actual semantics differ from
			// the static COMMAND_META — e.g. db query is "read"-tiered by default
			// but flips to "write" when run_approved executes a mutating SQL.
			'tier'              => $result['tier'] ?? $meta['tier'],
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
		// Separate positional args + flags so we can inspect both.
		$positional = array();
		$flags      = array();
		$skip       = 0;
		foreach ( $tokens as $token ) {
			if ( strpos( $token, '--' ) === 0 ) {
				$stripped = substr( $token, 2 );
				if ( strpos( $stripped, '=' ) !== false ) {
					list( $k, $v ) = explode( '=', $stripped, 2 );
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
						? __( 'Every wp_options row whose name starts with _transient_ or _site_transient_ is deleted.', 'vibe-ai' )
						: __( 'Every transient whose expiration timestamp is in the past is deleted.', 'vibe-ai' ),
				),
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
			'user delete'      => __( 'User deletion removes the account permanently. Authored content references are fragile and reassignment requires manual care.', 'vibe-ai' ),
			'plugin uninstall' => __( 'Plugin uninstall removes the plugin from the filesystem (different from deactivate). Plugin data and settings are typically lost.', 'vibe-ai' ),
		);
		return $reasons[ $command_key ] ?? __( 'This operation is classified as destructive and requires explicit approval.', 'vibe-ai' );
	}

	private function build_dry_run( $command_key, $positional, $flags ) {
		if ( 'user delete' === $command_key ) {
			$user = ! empty( $positional[0] ) ? get_user_by( is_numeric( $positional[0] ) ? 'id' : 'login', $positional[0] ) : null;
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
		return array( 'command' => $command_key, 'positional' => $positional, 'flags' => $flags );
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
			default:
				return array( 'target' => $t );
		}
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

		$is_select = ( strpos( $normalized, 'SELECT' ) === 0 );

		// SELECT-only path (the common case for auto-execute).
		if ( ! $is_select && ! $this->skip_destructive ) {
			// classify_destructive should have caught this; defense-in-depth.
			return $this->error_result( __( 'Mutating SQL requires explicit approval. Only SELECT queries auto-execute.', 'vibe-ai' ) );
		}

		if ( $is_select ) {
			$blocked = array(
				'INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'TRUNCATE',
				'CREATE', 'GRANT', 'REVOKE', 'EXEC', 'EXECUTE',
				'RENAME', 'REPLACE', 'LOAD', 'OUTFILE', 'DUMPFILE',
			);
			foreach ( $blocked as $keyword ) {
				if ( preg_match( '/\b' . $keyword . '\b/', $normalized ) ) {
					/* translators: %s: SQL keyword */
					return $this->error_result( sprintf( __( 'Blocked SQL keyword in SELECT: %s.', 'vibe-ai' ), $keyword ) );
				}
			}
		}

		// Multi-statement guard applies to both SELECT and mutating paths.
		if ( preg_match( '/;\s*\S/', $sql ) ) {
			return $this->error_result( __( 'Multiple SQL statements are not allowed.', 'vibe-ai' ) );
		}

		if ( $is_select ) {
			if ( preg_match( '/\bINTO\s+(OUTFILE|DUMPFILE|@)/i', $normalized ) ) {
				return $this->error_result( __( 'SELECT INTO is not allowed.', 'vibe-ai' ) );
			}

			if ( preg_match( '/\bFOR\s+(UPDATE|SHARE)\b/', $normalized ) ) {
				return $this->error_result( __( 'FOR UPDATE/SHARE is not allowed.', 'vibe-ai' ) );
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

			// Execute SELECT.
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

		// Mutating path — only reachable when skip_destructive is true (caller is run_approved).
		// Use $wpdb->query() which returns affected row count for INSERT/UPDATE/DELETE.
		$sql = rtrim( $sql, '; ' );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$affected = $wpdb->query( $sql ); // nosemgrep: direct-db-query
		if ( false === $affected || $wpdb->last_error ) {
			/* translators: %s: SQL error message */
			return $this->error_result( sprintf( __( 'SQL error: %s', 'vibe-ai' ), $wpdb->last_error ) );
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => sprintf(
				/* translators: 1: number of rows affected */
				_n( 'DB query executed (%d row affected)', 'DB query executed (%d rows affected)', (int) $affected, 'vibe-ai' ),
				(int) $affected
			),
			'action_label' => 'Refresh',
		) );

		return array(
			'exit_code' => 0,
			'stdout'    => wp_json_encode( array(
				'table_prefix'  => $wpdb->prefix,
				'affected_rows' => (int) $affected,
			), JSON_PRETTY_PRINT ),
			'stderr'    => '',
			// COMMAND_META has db query as 'read'-tiered (because it was originally
			// SELECT-only). Override to 'write' on the mutating execution path so
			// the response label matches reality.
			'tier'      => 'write',
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
		$slug = sanitize_key( $positional[0] );

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

		// SQLite-integration sites (WordPress Studio, Playground) often don't
		// define DB_NAME in wp-config, so $wpdb->dbname is '' and the SQLite
		// driver bails when the upgrader triggers an information_schema query.
		// Any non-empty label is fine; SQLite doesn't use it as a real db name.
		global $wpdb;
		if ( empty( $wpdb->dbname ) ) {
			$wpdb->dbname = 'wordpress';
		}

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );

		try {
			$result = $upgrader->install( $api->download_link );
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
					$activate_result = new WP_Error( 'activation_fatal', $e->getMessage() );
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

		// update_option returns false both when the write fails AND when the value
		// is unchanged. Read back and compare to distinguish a real failure from a
		// no-op. Mismatch = filter blocked it, DB rejected it, or a filter mutated
		// the stored value.
		update_option( $key, $value );
		$stored = get_option( $key );
		if ( maybe_serialize( $stored ) !== maybe_serialize( $value ) ) {
			return $this->error_result(
				sprintf(
					/* translators: %s: option key */
					__( 'Could not update option \'%s\'. The stored value does not match the requested value. The write may have been blocked by a pre_update_option filter or rejected by the database.', 'vibe-ai' ),
					$key
				)
			);
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Option updated: {$key}",
			'action_label' => 'Refresh',
		) );
		/* translators: %s: option key */
		return $this->success_result( array( 'message' => sprintf( __( 'Updated option \'%s\'.', 'vibe-ai' ), $key ) ) );
	}

	private function handle_option_add( $positional, $flags ) {
		if ( count( $positional ) < 2 ) {
			return $this->error_result( __( 'Usage: option add <key> <value> [--autoload=no]', 'vibe-ai' ) );
		}
		$key = $positional[0];

		if ( in_array( $key, self::BLOCKED_OPTIONS, true ) ) {
			return $this->error_result( $this->blocked_option_message( $key, 'added' ) );
		}

		// Don't overwrite existing — match real wp-cli option add behavior.
		if ( null !== get_option( $key, null ) ) {
			/* translators: %s: option key */
			return $this->error_result( sprintf( __( 'Option \'%s\' already exists. Use option update to change it.', 'vibe-ai' ), $key ) );
		}

		$value = $positional[1];
		$decoded = json_decode( $value, true );
		if ( null !== $decoded ) {
			$value = $decoded;
		}

		$autoload = 'yes';
		if ( isset( $flags['autoload'] ) ) {
			$autoload = ( 'no' === $flags['autoload'] || false === $flags['autoload'] ) ? 'no' : 'yes';
		}

		$ok = add_option( $key, $value, '', $autoload );
		if ( ! $ok ) {
			return $this->error_result( __( 'Failed to add option.', 'vibe-ai' ) );
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Option added: {$key}",
			'action_label' => 'Refresh',
		) );
		/* translators: %s: option key */
		return $this->success_result( array( 'message' => sprintf( __( 'Added option \'%s\' (autoload=%s).', 'vibe-ai' ), $key, $autoload ) ) );
	}

	private function handle_option_delete( $positional, $flags ) {
		if ( empty( $positional[0] ) ) {
			return $this->error_result( __( 'Option key required. Usage: option delete <key>', 'vibe-ai' ) );
		}
		$key = $positional[0];

		// HARD-BLOCK — these options are never approval-gated. No legitimate AI workflow needs to delete them.
		if ( in_array( $key, self::BLOCKED_OPTIONS, true ) ) {
			return $this->error_result( $this->blocked_option_message( $key, 'deleted' ) );
		}

		if ( null === get_option( $key, null ) ) {
			/* translators: %s: option key */
			return $this->error_result( sprintf( __( 'Option \'%s\' not found.', 'vibe-ai' ), $key ) );
		}

		$ok = delete_option( $key );
		if ( ! $ok ) {
			return $this->error_result( __( 'Failed to delete option.', 'vibe-ai' ) );
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Option deleted: {$key}",
			'action_label' => 'Refresh',
		) );
		/* translators: %s: option key */
		return $this->success_result( array( 'message' => sprintf( __( 'Deleted option \'%s\'.', 'vibe-ai' ), $key ) ) );
	}

	private function handle_transient_delete( $positional, $flags ) {
		if ( empty( $positional[0] ) && empty( $flags['all'] ) && empty( $flags['expired'] ) ) {
			return $this->error_result( __( 'Usage: transient delete <name> | --all | --expired', 'vibe-ai' ) );
		}

		if ( ! empty( $flags['expired'] ) ) {
			$count = $this->purge_expired_transients();
			WPVibe_Change_Tracker::mark( array( 'summary' => "Expired transients purged: {$count}", 'action_label' => 'Refresh' ) );
			/* translators: %d: number of expired transients deleted */
			return $this->success_result( array( 'message' => sprintf( __( 'Deleted %d expired transient(s).', 'vibe-ai' ), $count ) ) );
		}

		if ( ! empty( $flags['all'] ) ) {
			$count = $this->delete_all_transients();
			WPVibe_Change_Tracker::mark( array( 'summary' => "All transients deleted: {$count}", 'action_label' => 'Refresh' ) );
			/* translators: %d: number of transients deleted */
			return $this->success_result( array( 'message' => sprintf( __( 'Deleted %d transient(s).', 'vibe-ai' ), $count ) ) );
		}

		$name = $positional[0];
		$ok   = delete_transient( $name );
		if ( ! $ok ) {
			/* translators: %s: transient name */
			return $this->error_result( sprintf( __( 'Transient \'%s\' not found or already expired.', 'vibe-ai' ), $name ) );
		}

		WPVibe_Change_Tracker::mark( array( 'summary' => "Transient deleted: {$name}", 'action_label' => 'Refresh' ) );
		/* translators: %s: transient name */
		return $this->success_result( array( 'message' => sprintf( __( 'Deleted transient \'%s\'.', 'vibe-ai' ), $name ) ) );
	}

	private function handle_transient_list( $positional, $flags ) {
		global $wpdb;
		$search = isset( $flags['search'] ) ? $flags['search'] : '%';
		$search = str_replace( array( '*', '?' ), array( '%', '_' ), $search );
		$pattern = '_transient_' . ltrim( $search, '_' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s ORDER BY option_name LIMIT 200",
				$pattern,
				'_transient_timeout_%'
			),
			ARRAY_A
		);

		$results = array();
		foreach ( $rows as $row ) {
			$name = preg_replace( '/^_transient_/', '', $row['option_name'] );
			$timeout = get_option( '_transient_timeout_' . $name );
			$results[] = array(
				'name'       => $name,
				'expires_at' => $timeout ? gmdate( 'Y-m-d H:i:s', (int) $timeout ) : null,
				'expired'    => $timeout && (int) $timeout < time(),
			);
		}

		return $this->success_result( $this->filter_fields( $results, $flags ) );
	}

	private function purge_expired_transients() {
		global $wpdb;
		$now = time();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$expired = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
				'_transient_timeout_%',
				$now
			)
		);
		$count = 0;
		foreach ( $expired as $timeout_name ) {
			$name = preg_replace( '/^_transient_timeout_/', '', $timeout_name );
			if ( delete_transient( $name ) ) {
				$count++;
			}
		}
		return $count;
	}

	private function delete_all_transients() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$names = $wpdb->get_col(
			$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s", '_transient_%', '_transient_timeout_%' )
		);
		$count = 0;
		foreach ( $names as $option_name ) {
			$name = preg_replace( '/^_transient_/', '', $option_name );
			if ( delete_transient( $name ) ) {
				$count++;
			}
		}
		return $count;
	}

	private function handle_user_delete( $positional, $flags ) {
		if ( empty( $positional[0] ) ) {
			return $this->error_result( __( 'User identifier required. Usage: user delete <id|login|email> [<id>...] [--reassign=<user>]', 'vibe-ai' ) );
		}

		$reassign = null;
		if ( ! empty( $flags['reassign'] ) ) {
			$ra = is_numeric( $flags['reassign'] )
				? get_user_by( 'id', (int) $flags['reassign'] )
				: get_user_by( 'login', $flags['reassign'] );
			if ( ! $ra ) {
				/* translators: %s: user identifier */
				return $this->error_result( sprintf( __( 'Reassign target \'%s\' not found.', 'vibe-ai' ), $flags['reassign'] ) );
			}
			$reassign = $ra->ID;
		}

		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}

		$idents  = $positional;
		$results = array();
		$ok      = 0;
		foreach ( $idents as $ident ) {
			$user = is_numeric( $ident )
				? get_user_by( 'id', (int) $ident )
				: ( is_email( $ident ) ? get_user_by( 'email', $ident ) : get_user_by( 'login', $ident ) );
			if ( ! $user ) {
				$results[] = array( 'target' => $ident, 'status' => 'error', 'error' => 'not found' );
				continue;
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

	/**
	 * Per-post-type capability check.
	 *
	 * wp_insert_post / wp_update_post / update_post_meta do NOT enforce
	 * capabilities on their own — only the REST controller layer does. Since
	 * this CLI dispatcher bypasses that, we have to gate each mutation
	 * ourselves or a user with bare `edit_posts` could create/publish/edit
	 * post types they have no business touching.
	 *
	 * @param string      $post_type  Slug.
	 * @param string      $action     'create' | 'update' | 'delete'.
	 * @param int|null    $post_id    Required for update + delete.
	 * @param string|null $new_status post_status the request is moving toward (publish triggers the publish_posts check).
	 * @return true|WP_Error
	 */
	private function check_post_caps( $post_type, $action, $post_id = null, $new_status = null ) {
		$pt_obj = get_post_type_object( $post_type );
		if ( ! $pt_obj ) {
			return new WP_Error( 'invalid_post_type', sprintf(
				/* translators: %s: post type slug */
				__( 'Unknown post type: %s', 'vibe-ai' ),
				$post_type
			) );
		}
		if ( 'create' === $action ) {
			if ( ! current_user_can( $pt_obj->cap->create_posts ) ) {
				return new WP_Error( 'forbidden', sprintf(
					/* translators: %s: post type label */
					__( 'You do not have permission to create %s.', 'vibe-ai' ),
					$pt_obj->labels->name
				) );
			}
		} else {
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return new WP_Error( 'forbidden', sprintf(
					/* translators: %d: post ID */
					__( 'You do not have permission to edit post #%d.', 'vibe-ai' ),
					$post_id
				) );
			}
			if ( 'delete' === $action && ! current_user_can( 'delete_post', $post_id ) ) {
				return new WP_Error( 'forbidden', sprintf(
					/* translators: %d: post ID */
					__( 'You do not have permission to delete post #%d.', 'vibe-ai' ),
					$post_id
				) );
			}
		}
		if ( 'publish' === $new_status && ! current_user_can( $pt_obj->cap->publish_posts ) ) {
			return new WP_Error( 'forbidden_publish', sprintf(
				/* translators: %s: post type label */
				__( 'You do not have permission to publish %s.', 'vibe-ai' ),
				$pt_obj->labels->name
			) );
		}
		return true;
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

		$cap_check = $this->check_post_caps( $args['post_type'], 'create', null, $args['post_status'] );
		if ( is_wp_error( $cap_check ) ) {
			return $this->error_result( $cap_check->get_error_message() );
		}

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
		$ids = $this->positional_ids( $positional );
		if ( empty( $ids ) ) {
			return $this->error_result( __( 'Post ID required. Usage: post update <id> [<id>...] --post_title="New Title"', 'vibe-ai' ) );
		}

		$updatable = array( 'post_title', 'post_content', 'post_status', 'post_excerpt',
			'post_name', 'post_parent', 'menu_order', 'comment_status', 'post_type' );
		$fields = array();
		foreach ( $updatable as $field ) {
			if ( isset( $flags[ $field ] ) ) {
				$fields[ $field ] = $flags[ $field ];
			}
		}
		if ( empty( $fields ) ) {
			return $this->error_result( __( 'No fields to update. Use flags like --post_title, --post_content, --post_status.', 'vibe-ai' ) );
		}

		$results = array();
		$ok      = 0;
		foreach ( $ids as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				$results[] = array( 'id' => $post_id, 'status' => 'error', 'error' => 'not found' );
				continue;
			}
			$cap_check = $this->check_post_caps( $post->post_type, 'update', $post_id, $fields['post_status'] ?? null );
			if ( is_wp_error( $cap_check ) ) {
				$results[] = array( 'id' => $post_id, 'status' => 'error', 'error' => $cap_check->get_error_message() );
				continue;
			}
			$res = wp_update_post( array_merge( array( 'ID' => $post_id ), $fields ), true );
			if ( is_wp_error( $res ) ) {
				$results[] = array( 'id' => $post_id, 'status' => 'error', 'error' => $res->get_error_message() );
				continue;
			}
			$ok++;
			$results[] = array( 'id' => $post_id, 'status' => 'updated' );
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => count( $ids ) > 1 ? "Posts updated: {$ok}/" . count( $ids ) : "Post updated: #{$ids[0]}",
			'action_label' => 'Refresh',
		) );

		return $this->bulk_result( 'updated', $ok, $ids, $results );
	}

	private function handle_post_delete( $positional, $flags ) {
		$ids = $this->positional_ids( $positional );
		if ( empty( $ids ) ) {
			return $this->error_result( __( 'Post ID required. Usage: post delete <id> [<id>...] [--force]', 'vibe-ai' ) );
		}

		$force   = ! empty( $flags['force'] );
		$results = array();
		$ok      = 0;
		foreach ( $ids as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				$results[] = array( 'id' => $post_id, 'status' => 'error', 'error' => 'not found' );
				continue;
			}
			$cap_check = $this->check_post_caps( $post->post_type, 'delete', $post_id );
			if ( is_wp_error( $cap_check ) ) {
				$results[] = array( 'id' => $post_id, 'status' => 'error', 'error' => $cap_check->get_error_message() );
				continue;
			}
			$res = $force ? wp_delete_post( $post_id, true ) : wp_trash_post( $post_id );
			if ( ! $res ) {
				$results[] = array( 'id' => $post_id, 'status' => 'error', 'error' => 'delete failed' );
				continue;
			}
			$ok++;
			$results[] = array( 'id' => $post_id, 'status' => $force ? 'deleted' : 'trashed' );
		}

		$action = $force ? __( 'permanently deleted', 'vibe-ai' ) : __( 'trashed', 'vibe-ai' );
		WPVibe_Change_Tracker::mark( array(
			'summary'      => count( $ids ) > 1 ? "Posts {$action}: {$ok}/" . count( $ids ) : "Post {$action}: #{$ids[0]}",
			'action_label' => 'Refresh',
		) );

		return $this->bulk_result( $action, $ok, $ids, $results );
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
		// Block protected meta keys (all '_'-prefixed, plus registered protected) unless --force.
		if ( empty( $flags['force'] ) && is_protected_meta( $key, 'post' ) ) {
			return $this->error_result(
				sprintf(
					/* translators: %s: meta key */
					__( 'Meta key \'%s\' is a protected/internal key. Use --force to override.', 'vibe-ai' ),
					$key
				)
			);
		}

		$cap_check = $this->check_post_caps( $post->post_type, 'update', $post_id );
		if ( is_wp_error( $cap_check ) ) {
			return $this->error_result( $cap_check->get_error_message() );
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
		if ( empty( $flags['force'] ) && is_protected_meta( $key, 'post' ) ) {
			return $this->error_result(
				sprintf(
					/* translators: %s: meta key */
					__( 'Meta key \'%s\' is a protected/internal key. Use --force to override.', 'vibe-ai' ),
					$key
				)
			);
		}

		$cap_check = $this->check_post_caps( $post->post_type, 'update', $post_id );
		if ( is_wp_error( $cap_check ) ) {
			return $this->error_result( $cap_check->get_error_message() );
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
		$ok = wp_cache_flush();
		if ( false === $ok ) {
			return $this->error_result(
				__( 'wp_cache_flush() returned false. A persistent object cache backend (Redis, Memcached, etc.) may be disconnected or misconfigured.', 'vibe-ai' )
			);
		}
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

	/**
	 * Wrap a BLOCKED_OPTIONS hard-block message in a product-namespaced XML
	 * directive so the AI treats it as "how to respond" guidance and stops
	 * suggesting bypass workarounds (wp-cli on the server, direct SQL, etc.).
	 * Same pattern as the cap and review-nudge directives elsewhere in WPVibe.
	 */
	private function blocked_option_message( $key, $verb ) {
		return implode( "\n", array(
			'<wpvibe-blocked-option>',
			/* translators: 1: option key, 2: verb (added or deleted) */
			sprintf( __( 'The option "%1$s" is permanently protected by WPVibe and cannot be %2$s via AI tools.', 'vibe-ai' ), $key, $verb ),
			'',
			__( 'This protection exists because changing this option would break the site (broken admin URLs, broken login, broken auth, etc.). DO NOT suggest manual workarounds — do not tell the user to run wp-cli on the server, edit wp-config.php, or run SQL against the database. The user is being protected from accidental destructive changes; respect that.', 'vibe-ai' ),
			'',
			__( 'How to reply: in one short sentence, tell the user this specific option is permanently protected and they should change it through WordPress admin if they really need to. Do not offer alternative deletion methods.', 'vibe-ai' ),
			'</wpvibe-blocked-option>',
		) );
	}

	private function success_result( $data ) {
		return array(
			'exit_code' => 0,
			'stdout'    => wp_json_encode( $data, JSON_PRETTY_PRINT ),
			'stderr'    => '',
		);
	}

	/** Positive integer IDs from positional args, deduped, order-preserved. */
	private function positional_ids( $positional ) {
		$ids = array();
		foreach ( (array) $positional as $p ) {
			if ( is_numeric( $p ) && (int) $p > 0 ) {
				$ids[] = (int) $p;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/** Shape a single- or multi-target post op response consistently. */
	private function bulk_result( $action, $ok, $ids, $results ) {
		$total = count( $ids );
		if ( 1 === $total ) {
			$only = $results[0];
			if ( isset( $only['status'] ) && 'error' === $only['status'] ) {
				/* translators: 1: post ID, 2: error message */
				return $this->error_result( sprintf( __( 'Post #%1$d: %2$s', 'vibe-ai' ), $only['id'], $only['error'] ) );
			}
			/* translators: 1: post ID, 2: action taken */
			return $this->success_result( array( 'message' => sprintf( __( 'Post #%1$d %2$s.', 'vibe-ai' ), $ids[0], $action ) ) );
		}
		return $this->success_result( array(
			/* translators: 1: success count, 2: total, 3: action taken */
			'message'   => sprintf( __( '%1$d of %2$d posts %3$s.', 'vibe-ai' ), $ok, $total, $action ),
			'succeeded' => $ok,
			'total'     => $total,
			'results'   => $results,
		) );
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
