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
		$draft_slug  = $active_slug . '-wpvibe-draft';
		$theme_root  = get_theme_root();
		$source      = $theme_root . '/' . $active_slug;
		$dest        = $theme_root . '/' . $draft_slug;

		if ( ! is_dir( $source ) ) {
			return new WP_Error( 'no_theme', __( 'Active theme directory not found.', 'vibe-ai' ), array( 'status' => 404 ) );
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
				// Strip any pre-existing " (WPVibe Draft)" suffix so it doesn't accumulate across cycles.
				$source_name = preg_replace( '/(\s*\(WPVibe Draft\))+$/', '', wp_get_theme( $active_slug )->get( 'Name' ) );
				$style = preg_replace( '/^Theme Name:\s*.+$/m', 'Theme Name: ' . $source_name . ' (WPVibe Draft)', $style );
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
	public function publish() {
		$draft_slug  = get_option( 'wpvibe_draft_theme' );
		$source_slug = get_option( 'wpvibe_draft_source' );

		if ( ! $draft_slug || ! $source_slug ) {
			return new WP_Error( 'no_draft', __( 'No draft theme to publish.', 'vibe-ai' ), array( 'status' => 400 ) );
		}

		$theme_root = get_theme_root();
		$draft_dir  = $theme_root . '/' . $draft_slug;
		$live_dir   = $theme_root . '/' . $source_slug;
		$backup_dir = $theme_root . '/' . $source_slug . '-wpvibe-backup';

		if ( ! is_dir( $draft_dir ) ) {
			return new WP_Error( 'draft_missing', __( 'Draft theme directory not found.', 'vibe-ai' ), array( 'status' => 404 ) );
		}

		// Capture the clean original name BEFORE the live dir is overwritten; strips any accumulated draft suffix.
		$original_name = preg_replace( '/(\s*\(WPVibe Draft\))+$/', '', wp_get_theme( $source_slug )->get( 'Name' ) );

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
					array( 'status' => 500 )
				);
			}
		}

		// If the live theme directory is missing (a prior interrupted publish or
		// a manual delete left no source on disk), there is nothing to back up
		// and nothing to delete — proceed straight to the copy.
		if ( is_dir( $live_dir ) ) {
			$backup_result = $this->copy_directory( $live_dir, $backup_dir );
			if ( is_wp_error( $backup_result ) ) {
				return new WP_Error(
					'backup_failed',
					sprintf(
						/* translators: %s: error message */
						__( 'Could not back up the live theme before publishing: %s The live theme is intact; nothing was modified. Fix the underlying filesystem issue (permissions, disk space) and retry.', 'vibe-ai' ),
						$backup_result->get_error_message()
					),
					array( 'status' => 500 )
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
					array( 'status' => 500 )
				);
			}
		}

		// Move draft to live.
		$result = $this->copy_directory( $draft_dir, $live_dir );
		if ( is_wp_error( $result ) ) {
			// Rollback. If rollback itself fails the site is in an unrecoverable
			// state by automation; we have to tell the user precisely how to fix
			// it by hand.
			$rollback_result = $this->copy_directory( $backup_dir, $live_dir );
			if ( is_wp_error( $rollback_result ) ) {
				return new WP_Error(
					'publish_and_rollback_failed',
					sprintf(
						/* translators: 1: publish error, 2: rollback error, 3: backup dir, 4: live dir */
						__( 'Publish failed (%1$s) AND rollback failed (%2$s). The live theme directory may be empty or incomplete; a full copy of the previous theme is at \'%3$s\'. To recover, manually move \'%3$s\' to \'%4$s\'.', 'vibe-ai' ),
						$result->get_error_message(),
						$rollback_result->get_error_message(),
						$backup_dir,
						$live_dir
					),
					array( 'status' => 500 )
				);
			}
			$this->delete_directory( $backup_dir );
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
						array( 'status' => 500 )
					);
				}
			}
		}

		// Invalidate theme header cache so wp-admin shows the restored name immediately.
		wp_clean_themes_cache();

		// Ensure the original theme is active.
		switch_theme( $source_slug );

		// Cleanup.
		$this->delete_directory( $draft_dir );
		delete_option( 'wpvibe_draft_theme' );
		delete_option( 'wpvibe_draft_source' );

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
	 * Generate a preview URL for the draft theme.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function preview_url() {
		$draft_slug = get_option( 'wpvibe_draft_theme' );
		if ( ! $draft_slug ) {
			return new WP_Error( 'no_draft', __( 'No draft theme to preview.', 'vibe-ai' ), array( 'status' => 400 ) );
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
			return new WP_Error( 'no_draft', __( 'No draft theme to delete.', 'vibe-ai' ), array( 'status' => 400 ) );
		}

		$draft_dir = get_theme_root() . '/' . $draft_slug;
		if ( is_dir( $draft_dir ) ) {
			$this->delete_directory( $draft_dir );
		}

		delete_option( 'wpvibe_draft_theme' );
		delete_option( 'wpvibe_draft_source' );
		delete_option( 'wpvibe_preview_token' );
		delete_option( 'wpvibe_preview_token_issued' );

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
				return new WP_Error( 'copy_failed', sprintf( __( 'Could not create directory: %s', 'vibe-ai' ), $dst ), array( 'status' => 500 ) );
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
						array( 'status' => 500 )
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
					return new WP_Error( 'rmdir_failed', sprintf( __( 'Failed to remove directory: %s', 'vibe-ai' ), $item->getPathname() ), array( 'status' => 500 ) );
				}
			} else {
				wp_delete_file( $item->getPathname() );
				// wp_delete_file is void; verify by checking the file no longer exists.
				if ( file_exists( $item->getPathname() ) ) {
					/* translators: %s: file path */
					return new WP_Error( 'delete_failed', sprintf( __( 'Failed to delete file: %s', 'vibe-ai' ), $item->getPathname() ), array( 'status' => 500 ) );
				}
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- No WP alternative for rmdir().
		if ( ! @rmdir( $dir ) ) {
			/* translators: %s: directory path */
			return new WP_Error( 'rmdir_failed', sprintf( __( 'Failed to remove directory: %s', 'vibe-ai' ), $dir ), array( 'status' => 500 ) );
		}

		return true;
	}
}
