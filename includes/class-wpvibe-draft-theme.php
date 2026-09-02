<?php
/**
 * Draft theme lifecycle management.
 *
 * Handles cloning the active theme into a sandboxed draft,
 * publishing the draft back to live, and cleanup.
 */

defined( 'ABSPATH' ) || exit;

class WPVibe_Draft_Theme {

	/**
	 * Commercial builder themes, by directory slug (lowercased on compare;
	 * Divi ships as wp-content/themes/Divi). Draft creation refuses these:
	 * publish overwrites the live theme directory in place, and the vendor's
	 * next theme update replaces that directory wholesale, silently wiping
	 * every edit. Child themes (divi-child etc.) never match and stay
	 * draftable — the vendor never updates them.
	 */
	const BUILDER_THEMES = array( 'divi', 'extra', 'avada', 'enfold', 'flatsome', 'betheme', 'bridge', 'salient', 'the7', 'x' );

	/**
	 * Create a draft theme by cloning the active theme.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function create() {
		$existing = get_option( 'wpvibe_draft_theme' );
		if ( $existing && is_dir( get_theme_root() . '/' . $existing ) ) {
			return rest_ensure_response( array(
				'status'     => 'exists',
				'draft_slug' => $existing,
				'message'    => __( 'A draft theme already exists. Delete it first or continue editing.', 'vibe-ai' ),
			) );
		}

		$active_slug = get_stylesheet();
		if ( in_array( strtolower( $active_slug ), self::BUILDER_THEMES, true ) ) {
			return new WP_Error(
				'builder_theme',
				sprintf(
					/* translators: %s: theme slug */
					__( 'Refused: the active theme \'%s\' is a commercial builder theme. Publishing a draft overwrites the live theme directory in place, and the theme\'s next vendor update replaces that directory wholesale, silently wiping every edit made this way. Make design changes in the builder\'s own settings UI; for custom CSS or template overrides, install and activate the vendor\'s child theme (then a draft of the child is safe), or build a separate theme with create_classic_theme.', 'vibe-ai' ),
					$active_slug
				),
				WPVibe_Error_Contract::data( 'not_supported', false, array( 'status' => 409 ) )
			);
		}
		$draft_slug  = $active_slug . '-wpvibe-draft';
		$theme_root  = get_theme_root();
		$source      = $theme_root . '/' . $active_slug;
		$dest        = $theme_root . '/' . $draft_slug;

		if ( ! is_dir( $source ) ) {
			return new WP_Error( 'no_theme', __( 'Active theme directory not found.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'not_found', false, array( 'status' => 404 ) ) );
		}

		// Clone the theme directory.
		$result = $this->copy_directory( $source, $dest );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Update the theme name in style.css so WP recognizes it.
		$style_path = $dest . '/style.css';
		$fs         = wpvibe_fs();
		if ( $fs && $fs->exists( $style_path ) ) {
			$style = $fs->get_contents( $style_path );
			if ( false !== $style ) {
				// Strip any pre-existing draft suffix so it doesn't accumulate across cycles.
				$source_name = preg_replace( '/(\s*\((?:WPVibe )?Draft\))+$/', '', wp_get_theme( $active_slug )->get( 'Name' ) );
				$suffix = WPVibe_White_Label::is_hidden() ? ' (Draft)' : ' (WPVibe Draft)';
				$style = preg_replace( '/^Theme Name:\s*.+$/m', 'Theme Name: ' . $source_name . $suffix, $style );
				$fs->put_contents( $style_path, $style, FS_CHMOD_FILE );
			}
		}

		// Store the draft theme slug and original theme for rollback.
		update_option( 'wpvibe_draft_theme', $draft_slug );
		update_option( 'wpvibe_draft_source', $active_slug );

		WPVibe_Change_Tracker::mark( array(
			'summary'      => 'Draft theme created',
			'action_label' => 'Preview Theme',
		) );

		return rest_ensure_response( array(
			'status'      => 'created',
			'draft_slug'  => $draft_slug,
			'source_slug' => $active_slug,
			'message'     => __( 'Draft theme created. File operations are now scoped to the draft.', 'vibe-ai' ),
		) );
	}

	/**
	 * Publish the draft theme — replace live theme files with draft.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	/** First candidate slug that is actually installed, or '' when none is.
	 * switch_theme() does no validation, so an unchecked slug white-screens the
	 * site just as surely as the fatal the rollback is undoing. */
	private static function first_existing_theme( array $candidates ) {
		foreach ( $candidates as $slug ) {
			$slug = (string) $slug;
			if ( '' === $slug ) {
				continue;
			}
			$theme = wp_get_theme( $slug );
			if ( $theme && $theme->exists() ) {
				return $slug;
			}
		}
		return '';
	}

	/** Put the backup back live and the published tree back into the draft slot. */
	private function rollback_publish( $live_dir, $backup_dir, $draft_dir, $swapped_by_rename, $source_slug, $had_live = true, $previous_active = '' ) {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- failures reported below.
		$draft_back = @rename( $live_dir, $draft_dir ) || ! is_wp_error( $this->copy_directory( $live_dir, $draft_dir ) );
		if ( $draft_back && is_dir( $live_dir ) ) {
			$this->delete_directory( $live_dir );
		}
		if ( ! $had_live ) {
			// A brand-new theme had no live copy to back up: the source slug's
			// directory was just moved into the draft slot, so re-activating it
			// would point the site at nothing. Go back to whatever was active
			// before the publish, and only to a theme that is really on disk.
			wp_clean_themes_cache();
			$target = self::first_existing_theme( array( $previous_active === $source_slug ? '' : $previous_active, WP_DEFAULT_THEME ) );
			if ( '' !== $target ) {
				switch_theme( $target );
			}
			if ( class_exists( 'WPVibe_CLI' ) ) {
				try {
					( new WPVibe_CLI() )->purge_all_caches();
				} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				}
			}
			if ( '' === $target ) {
				return __( 'no installed theme could be activated in its place, so the site is still on the failed theme. Install or restore a working theme from the host file manager.', 'vibe-ai' );
			}
			return $draft_back
				? sprintf( /* translators: %s: theme slug */ __( '\'%s\' is live again and the draft is intact for editing.', 'vibe-ai' ), $target )
				: sprintf( /* translators: %s: theme slug */ __( '\'%s\' is live again, but the draft could not be restored; recreate it.', 'vibe-ai' ), $target );
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$live_back = @rename( $backup_dir, $live_dir ) || ! is_wp_error( $this->copy_directory( $backup_dir, $live_dir ) );
		wp_clean_themes_cache();
		// Back to the theme the site was actually running: publishing activates
		// the source, so when the site was on a different theme a failed publish
		// must not leave that switch in place.
		$restore = self::first_existing_theme( array( $previous_active, $source_slug ) );
		if ( '' !== $restore ) {
			switch_theme( $restore );
		}
		if ( class_exists( 'WPVibe_CLI' ) ) {
			try {
				( new WPVibe_CLI() )->purge_all_caches();
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			}
		}
		if ( $live_back && $draft_back ) {
			return __( 'the previous theme is live again and the draft is intact for editing.', 'vibe-ai' );
		}
		if ( $live_back ) {
			return __( 'the previous theme is live again, but the draft could not be restored; recreate it from the live theme.', 'vibe-ai' );
		}
		return sprintf(
			/* translators: 1: backup dir, 2: live dir */
			__( 'RESTORE FAILED: the previous theme is at \'%1$s\'. Move it to \'%2$s\' by hand (SFTP or the host file manager) to recover the site.', 'vibe-ai' ),
			$backup_dir,
			$live_dir
		);
	}

	public function publish() {
		$draft_slug  = get_option( 'wpvibe_draft_theme' );
		$source_slug = get_option( 'wpvibe_draft_source' );

		if ( ! $draft_slug || ! $source_slug ) {
			return self::no_draft_error( __( 'No draft theme to publish.', 'vibe-ai' ) );
		}

		$theme_root = get_theme_root();
		$draft_dir  = $theme_root . '/' . $draft_slug;
		$live_dir   = $theme_root . '/' . $source_slug;
		$backup_dir = $theme_root . '/' . $source_slug . '-wpvibe-backup';

		if ( ! is_dir( $draft_dir ) ) {
			return new WP_Error( 'draft_missing', __( 'Draft theme directory not found.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'not_found', false, array( 'status' => 404 ) ) );
		}
		// Unfiltered: WPVibe_Preview filters 'stylesheet' to the draft slug when a
		// preview token is present, and rolling back onto the draft would
		// re-activate the very theme that just fataled.
		$previous_active = (string) get_option( 'stylesheet' );
		$had_live        = is_dir( $live_dir );

		// Capture the clean original name BEFORE the live dir is overwritten; strips any accumulated draft suffix.
		$original_name = preg_replace( '/(\s*\((?:WPVibe )?Draft\))+$/', '', wp_get_theme( $source_slug )->get( 'Name' ) );

		// Backup the current live theme. Each step must succeed before the
		// next; if backup fails we abort BEFORE touching the live theme.
		if ( is_dir( $backup_dir ) ) {
			$cleared = $this->delete_directory( $backup_dir );
			if ( is_wp_error( $cleared ) ) {
				return new WP_Error(
					'cleanup_failed',
					sprintf(
						/* translators: 1: directory path, 2: error message */
						__( 'Could not clear the previous backup directory \'%1$s\': %2$s Aborting publish; live theme untouched.', 'vibe-ai' ),
						$backup_dir,
						$cleared->get_error_message()
					),
					WPVibe_Error_Contract::data( 'filesystem', false, array( 'status' => 500 ) )
				);
			}
		}

		// If the live theme directory is missing (a prior interrupted publish or
		// a manual delete left no source on disk), there is nothing to back up
		// and nothing to delete — proceed straight to the swap.
		// Prefer rename() for both legs: it needs write permission only on the
		// themes PARENT directory, not recursive delete rights inside the live
		// theme (shared hosts with mixed file ownership fail delete_directory
		// but allow the rename), and the swap window is near-atomic.
		$swapped_by_rename = false;
		if ( is_dir( $live_dir ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- rename failure falls back to copy+delete below.
			if ( @rename( $live_dir, $backup_dir ) ) {
				$swapped_by_rename = true;
			} else {
				$backup_result = $this->copy_directory( $live_dir, $backup_dir );
				if ( is_wp_error( $backup_result ) ) {
					return new WP_Error(
						'backup_failed',
						sprintf(
							/* translators: %s: error message */
							__( 'Could not back up the live theme before publishing: %s The live theme is intact; nothing was modified. Fix the underlying filesystem issue (permissions, disk space) and retry.', 'vibe-ai' ),
							$backup_result->get_error_message()
						),
						WPVibe_Error_Contract::data( 'filesystem', false, array( 'status' => 500 ) )
					);
				}

				$deleted_live = $this->delete_directory( $live_dir );
				if ( is_wp_error( $deleted_live ) ) {
					return new WP_Error(
						'delete_live_failed',
						sprintf(
							/* translators: 1: live dir, 2: error message, 3: backup dir */
							__( 'Could not delete the live theme directory \'%1$s\' before replacing it: %2$s A complete backup is at \'%3$s\'. To recover, manually remove \'%1$s\' and rename the backup.', 'vibe-ai' ),
							$live_dir,
							$deleted_live->get_error_message(),
							$backup_dir
						),
						WPVibe_Error_Contract::data( 'filesystem', false, array( 'status' => 500 ) )
					);
				}
			}
		}

		// Move draft to live: rename first, copy as fallback.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- rename failure falls back to copy below.
		$result = @rename( $draft_dir, $live_dir ) ? true : $this->copy_directory( $draft_dir, $live_dir );
		if ( is_wp_error( $result ) ) {
			// Rollback. A rename-swap rolls back with the reverse rename (same
			// permission envelope as the swap that just succeeded); the copy
			// path rolls back by copying. If rollback itself fails the site is
			// in an unrecoverable state by automation; we have to tell the
			// user precisely how to fix it by hand.
			$rollback_ok = $swapped_by_rename
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- failure handled below.
				? @rename( $backup_dir, $live_dir )
				: ! is_wp_error( $this->copy_directory( $backup_dir, $live_dir ) );
			if ( ! $rollback_ok ) {
				return new WP_Error(
					'publish_and_rollback_failed',
					sprintf(
						/* translators: 1: publish error, 2: rollback error, 3: backup dir, 4: live dir */
						__( 'Publish failed (%1$s) AND rollback failed (%2$s). The live theme directory may be empty or incomplete; a full copy of the previous theme is at \'%3$s\'. To recover, manually move \'%3$s\' to \'%4$s\'.', 'vibe-ai' ),
						$result->get_error_message(),
						__( 'restoring the backup also failed', 'vibe-ai' ),
						$backup_dir,
						$live_dir
					),
					WPVibe_Error_Contract::data( 'filesystem', false, array( 'status' => 500 ) )
				);
			}
			if ( ! $swapped_by_rename ) {
				$this->delete_directory( $backup_dir );
			}
			return $result;
		}

		// Restore the original theme name in style.css.
		$style_path = $live_dir . '/style.css';
		$fs         = wpvibe_fs();
		if ( $fs && $fs->exists( $style_path ) ) {
			$style = $fs->get_contents( $style_path );
			if ( false !== $style ) {
				$style   = preg_replace( '/^Theme Name:\s*.+$/m', 'Theme Name: ' . $original_name, $style );
				$written = $fs->put_contents( $style_path, $style, FS_CHMOD_FILE );
				if ( ! $written ) {
					return new WP_Error(
						'name_restore_failed',
						sprintf(
							/* translators: 1: source slug, 2: original theme name */
							__( 'Published draft to \'%1$s\', but could not write style.css to restore the original theme name. The live theme is functional but will display \'%2$s (WPVibe Draft)\' instead of its original name in wp-admin. Edit style.css manually to fix.', 'vibe-ai' ),
							$source_slug,
							$original_name
						),
						WPVibe_Error_Contract::data( 'filesystem', false, array( 'status' => 500 ) )
					);
				}
			}
		}

		// Invalidate theme header cache so wp-admin shows the restored name immediately.
		wp_clean_themes_cache();

		// Ensure the original theme is active.
		switch_theme( $source_slug );

		// Publish replaced live templates in place; switch_theme's action only
		// reaches engines that hook it (LiteSpeed, WP Rocket). Flush the rest
		// too, or visitors keep the old theme's cached HTML.
		if ( class_exists( 'WPVibe_CLI' ) ) {
			try {
				( new WPVibe_CLI() )->purge_all_caches();
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- a cache-engine failure must never fail a completed publish.
			}
		}

		// A valid-but-fatal functions.php passes php -l and white-screens the
		// site; render once and put everything back if it does (#78).
		$health = WPVibe_PHP_Guard::render_health_check();
		if ( is_wp_error( $health ) ) {
			$rolled = $this->rollback_publish( $live_dir, $backup_dir, $draft_dir, $swapped_by_rename, $source_slug, $had_live, $previous_active );
			return new WP_Error(
				'publish_rolled_back',
				sprintf(
					/* translators: 1: what the front page returned, 2: rollback outcome */
					__( 'Published, then the site failed to render (%1$s), so the publish was rolled back: %2$s Fix the draft (a runtime fatal in functions.php is the usual cause: an undefined function, or a function or class declared twice) and publish again.', 'vibe-ai' ),
					$health->get_error_message(),
					$rolled
				),
				WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 422, 'render' => $health->get_error_message() ) )
			);
		}

		// Cleanup.
		$this->delete_directory( $draft_dir );
		delete_option( 'wpvibe_draft_theme' );
		delete_option( 'wpvibe_draft_source' );
		self::record_draft_event( 'published' );

		// The cookie still points at a now-deleted draft slug; clear it so
		// the next admin request doesn't re-enter a nonexistent preview.
		if ( class_exists( 'WPVibe_Preview' ) ) {
			WPVibe_Preview::clear_cookie();
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => 'Draft theme published',
			'action_label' => 'View Site',
			'url'          => home_url( '/' ),
			'admin_url'    => home_url( '/' ),
		) );

		return rest_ensure_response( array(
			'status'  => 'published',
			/* translators: 1: theme slug, 2: backup slug */
			'message' => sprintf( __( 'Draft published to \'%1$s\'. Backup saved as \'%2$s\'.', 'vibe-ai' ), $source_slug, $source_slug . '-wpvibe-backup' ),
		) );
	}

	/**
	 * Remember how the last draft ended so a later no_draft error can say
	 * WHY there is no draft (agents kept asking for previews of drafts they
	 * had just published or deleted, then looped on the bare message).
	 */
	public static function record_draft_event( $action ) {
		update_option( 'wpvibe_last_draft_event', array( 'action' => $action, 'at' => time() ), false );
	}

	/**
	 * Build a no_draft WP_Error whose message explains what happened to the
	 * last draft, when the plugin knows.
	 *
	 * @param string $base Base message ending in a period.
	 * @return WP_Error
	 */
	public static function no_draft_error( $base ) {
		$event = get_option( 'wpvibe_last_draft_event' );
		$extra = array( 'status' => 400 );
		if ( is_array( $event ) && ! empty( $event['action'] ) && ! empty( $event['at'] ) ) {
			$when = human_time_diff( (int) $event['at'], time() );
			$base .= 'published' === $event['action']
				/* translators: %s: human-readable time difference */
				? ' ' . sprintf( __( 'The last draft was published %s ago; those changes are already on the live site, so no draft is needed to view them.', 'vibe-ai' ), $when )
				/* translators: %s: human-readable time difference */
				: ' ' . sprintf( __( 'The last draft was deleted %s ago; its unpublished edits are gone. Only create a new draft if new edits are wanted.', 'vibe-ai' ), $when );
			$extra['last_draft_action'] = $event['action'];
			$extra['last_draft_at']     = (int) $event['at'];
		}
		return new WP_Error( 'no_draft', $base, WPVibe_Error_Contract::data( 'not_found', false, $extra ) );
	}

	/**
	 * Generate a preview URL for the draft theme.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function preview_url() {
		$draft_slug = get_option( 'wpvibe_draft_theme' );
		if ( ! $draft_slug ) {
			return self::no_draft_error( __( 'No draft theme to preview.', 'vibe-ai' ) );
		}

		// Reuse the existing token if it's still within its 24h TTL, otherwise
		// issue a fresh one. Keeps already-shared preview URLs stable until
		// they actually expire, while capping how long any leaked URL works.
		$token  = get_option( 'wpvibe_preview_token' );
		$issued = (int) get_option( 'wpvibe_preview_token_issued', 0 );
		if ( ! $token || ! $issued || ( time() - $issued ) > DAY_IN_SECONDS ) {
			$token = wp_generate_password( 32, false );
			update_option( 'wpvibe_preview_token', $token );
			update_option( 'wpvibe_preview_token_issued', time() );
		}

		$url = add_query_arg( 'wpvibe_preview', $token, home_url( '/' ) );

		return rest_ensure_response( array(
			'preview_url' => $url,
			'draft_slug'  => $draft_slug,
		) );
	}

	/**
	 * Delete the draft theme and clean up.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete() {
		$draft_slug = get_option( 'wpvibe_draft_theme' );
		if ( ! $draft_slug ) {
			return self::no_draft_error( __( 'No draft theme to delete.', 'vibe-ai' ) );
		}

		$draft_dir = get_theme_root() . '/' . $draft_slug;
		if ( is_dir( $draft_dir ) ) {
			$this->delete_directory( $draft_dir );
		}

		delete_option( 'wpvibe_draft_theme' );
		delete_option( 'wpvibe_draft_source' );
		delete_option( 'wpvibe_preview_token' );
		delete_option( 'wpvibe_preview_token_issued' );
		self::record_draft_event( 'deleted' );

		if ( class_exists( 'WPVibe_Preview' ) ) {
			WPVibe_Preview::clear_cookie();
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => 'Draft theme deleted',
			'action_label' => 'Refresh',
		) );

		return rest_ensure_response( array(
			'status'  => 'deleted',
			'message' => __( 'Draft theme removed.', 'vibe-ai' ),
		) );
	}

	/**
	 * Recursively copy a directory.
	 *
	 * @param string $src Source directory.
	 * @param string $dst Destination directory.
	 * @return true|WP_Error
	 */
	public function copy_directory_public( $src, $dst ) {
		return $this->copy_directory( $src, $dst );
	}

	/**
	 * @param string $src Source directory.
	 * @param string $dst Destination directory.
	 * @return true|WP_Error
	 */
	private function copy_directory( $src, $dst ) {
		if ( ! wp_mkdir_p( $dst ) && ! is_dir( $dst ) ) {
			// Fallback: wp_mkdir_p can fail in some environments (e.g. WordPress Studio).
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Fallback for environments where wp_mkdir_p() fails.
			if ( ! @mkdir( $dst, 0755, true ) && ! is_dir( $dst ) ) {
				/* translators: %s: directory path */
				return new WP_Error( 'copy_failed', sprintf( __( 'Could not create directory: %s', 'vibe-ai' ), $dst ), WPVibe_Error_Contract::data( 'filesystem', false, array( 'status' => 500 ) ) );
			}
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $src, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $item ) {
			$dest_path = $dst . '/' . $iterator->getSubPathName();
			if ( $item->isDir() ) {
				wp_mkdir_p( $dest_path );
			} else {
				if ( ! copy( $item->getPathname(), $dest_path ) ) {
					return new WP_Error(
						'copy_failed',
						sprintf(
							/* translators: %s: file path */
							__( 'Failed to copy file: %s', 'vibe-ai' ),
							$iterator->getSubPathName()
						),
						WPVibe_Error_Contract::data( 'filesystem', false, array( 'status' => 500 ) )
					);
				}
			}
		}

		return true;
	}

	/**
	 * Public wrapper for delete_directory.
	 */
	public function delete_directory_public( $dir ) {
		return $this->delete_directory( $dir );
	}

	/**
	 * Recursively delete a directory.
	 *
	 * @param string $dir Directory to delete.
	 */
	private function delete_directory( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return true;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( $item->isDir() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- No WP alternative for rmdir().
				if ( ! @rmdir( $item->getPathname() ) ) {
					/* translators: %s: directory path */
					return new WP_Error( 'rmdir_failed', sprintf( __( 'Failed to remove directory: %s', 'vibe-ai' ), $item->getPathname() ), WPVibe_Error_Contract::data( 'filesystem', false, array( 'status' => 500 ) ) );
				}
			} else {
				wp_delete_file( $item->getPathname() );
				// wp_delete_file is void; verify by checking the file no longer exists.
				if ( file_exists( $item->getPathname() ) ) {
					/* translators: %s: file path */
					return new WP_Error( 'delete_failed', sprintf( __( 'Failed to delete file: %s', 'vibe-ai' ), $item->getPathname() ), WPVibe_Error_Contract::data( 'filesystem', false, array( 'status' => 500 ) ) );
				}
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- No WP alternative for rmdir().
		if ( ! @rmdir( $dir ) ) {
			/* translators: %s: directory path */
			return new WP_Error( 'rmdir_failed', sprintf( __( 'Failed to remove directory: %s', 'vibe-ai' ), $dir ), WPVibe_Error_Contract::data( 'filesystem', false, array( 'status' => 500 ) ) );
		}

		return true;
	}
}
