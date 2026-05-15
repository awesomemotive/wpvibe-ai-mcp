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

		// Backup the current live theme.
		if ( is_dir( $backup_dir ) ) {
			$this->delete_directory( $backup_dir );
		}
		$this->copy_directory( $live_dir, $backup_dir );
		$this->delete_directory( $live_dir );

		// Move draft to live.
		$result = $this->copy_directory( $draft_dir, $live_dir );
		if ( is_wp_error( $result ) ) {
			// Rollback on failure.
			$this->copy_directory( $backup_dir, $live_dir );
			$this->delete_directory( $backup_dir );
			return $result;
		}

		// Restore the original theme name in style.css.
		$style_path = $live_dir . '/style.css';
		$fs         = wpvibe_fs();
		if ( $fs && $fs->exists( $style_path ) ) {
			$style = $fs->get_contents( $style_path );
			if ( false !== $style ) {
				$style = preg_replace( '/^Theme Name:\s*.+$/m', 'Theme Name: ' . $original_name, $style );
				$fs->put_contents( $style_path, $style, FS_CHMOD_FILE );
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
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( $item->isDir() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- No WP alternative for rmdir().
				rmdir( $item->getPathname() );
			} else {
				wp_delete_file( $item->getPathname() );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- No WP alternative for rmdir().
		rmdir( $dir );
	}
}
