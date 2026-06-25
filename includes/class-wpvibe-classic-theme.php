<?php
/**
 * Creates a new classic WordPress theme from scratch.
 *
 * Copies the bundled starter theme (starter-themes/classic/) into a draft
 * directory with token substitution. The live site is never touched; the AI
 * edits the draft and the user publishes when ready.
 */

defined( 'ABSPATH' ) || exit;

class WPVibe_Classic_Theme {

	/**
	 * Create a new classic theme.
	 *
	 * @param string $theme_name  Human-readable theme name.
	 * @param string $description Optional theme description.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create( $theme_name, $description = '' ) {
		if ( empty( $theme_name ) ) {
			return new WP_Error( 'invalid_args', __( 'Theme name is required.', 'vibe-ai' ), array( 'status' => 400 ) );
		}

		$starter_dir = WPVIBE_PLUGIN_DIR . 'starter-themes/classic';
		if ( ! is_dir( $starter_dir ) ) {
			return new WP_Error( 'no_starter', __( 'Starter theme files are missing from the plugin.', 'vibe-ai' ), array( 'status' => 500 ) );
		}

		// Delete any existing draft first.
		$existing_draft = get_option( 'wpvibe_draft_theme' );
		if ( $existing_draft ) {
			$draft = new WPVibe_Draft_Theme();
			$draft->delete();
		}

		$slug = sanitize_title( $theme_name );

		// Ensure the slug produces a valid PHP function prefix.
		$prefix = str_replace( '-', '_', $slug );
		if ( ! preg_match( '/^[a-z_][a-z0-9_]*$/', $prefix ) ) {
			return new WP_Error(
				'invalid_theme_name',
				__( 'Theme name must start with a letter and contain only letters, numbers, hyphens, and spaces.', 'vibe-ai' ),
				array( 'status' => 400 )
			);
		}

		$theme_root = get_theme_root();
		$theme_dir  = $theme_root . '/' . $slug;
		$draft_slug = $slug . '-wpvibe-draft';
		$draft_dir  = $theme_root . '/' . $draft_slug;

		// Some environments (e.g. WordPress Studio) cache filesystem state between requests.
		clearstatcache( true, $draft_dir );
		clearstatcache( true, $theme_dir );

		if ( is_dir( $theme_dir ) || is_dir( $draft_dir ) ) {
			/* translators: %s: theme slug */
			return new WP_Error( 'exists', sprintf( __( 'Theme \'%s\' already exists.', 'vibe-ai' ), $slug ), array( 'status' => 409 ) );
		}

		if ( ! wp_mkdir_p( $draft_dir ) && ! is_dir( $draft_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Fallback for environments where wp_mkdir_p() fails.
			if ( ! @mkdir( $draft_dir, 0755, true ) && ! is_dir( $draft_dir ) ) {
				/* translators: %s: directory path */
				return new WP_Error( 'mkdir_failed', sprintf( __( 'Could not create draft directory: %s', 'vibe-ai' ), $draft_dir ), array( 'status' => 500 ) );
			}
		}

		$tokens = array(
			'{{THEME_NAME}}'        => $theme_name,
			'{{THEME_SLUG}}'        => $slug,
			'{{THEME_DESCRIPTION}}' => $description ? $description : 'A custom classic WordPress theme.',
			'{{FUNCTION_PREFIX}}'   => $prefix,
		);

		$copied = $this->copy_starter( $starter_dir, $draft_dir, $tokens );
		if ( is_wp_error( $copied ) ) {
			$cleanup = new WPVibe_Draft_Theme();
			$cleanup->delete_directory_public( $draft_dir );
			return $copied;
		}

		$valid = $this->validate_draft( $draft_dir );
		if ( is_wp_error( $valid ) ) {
			$cleanup = new WPVibe_Draft_Theme();
			$cleanup->delete_directory_public( $draft_dir );
			return $valid;
		}

		// Set as the active draft, so file-editing tools target this draft.
		update_option( 'wpvibe_draft_theme', $draft_slug );
		update_option( 'wpvibe_draft_source', $slug );

		// Generate a preview token so live reload can redirect to the preview immediately.
		$token = wp_generate_password( 32, false );
		update_option( 'wpvibe_preview_token', $token );
		update_option( 'wpvibe_preview_token_issued', time() );
		$preview_url = add_query_arg( 'wpvibe_preview', $token, home_url( '/' ) );

		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Classic theme created: {$slug}",
			'action_label' => 'Preview Theme',
		) );

		return rest_ensure_response( array(
			'status'      => 'created',
			'theme_slug'  => $slug,
			'draft_slug'  => $draft_slug,
			'preview_url' => $preview_url,
			/* translators: 1: theme name, 2: preview URL */
			'message'     => sprintf( __( 'Theme \'%1$s\' created as draft. The live site is unchanged. Refine style.css, header.php, footer.php, and index.php for your design. Preview: %2$s. Use publish_draft_theme when ready to go live.', 'vibe-ai' ), $theme_name, $preview_url ),
		) );
	}

	/**
	 * Copy a starter theme directory into the draft, substituting tokens.
	 * Files ending in ".tpl" are written with that suffix removed (PHP starter
	 * files ship non-executable so the WP.org plugin scanner skips them).
	 *
	 * @param string $src    Starter directory.
	 * @param string $dst    Draft directory.
	 * @param array  $tokens Replacement map.
	 * @return true|WP_Error
	 */
	private function copy_starter( $src, $dst, $tokens ) {
		$fs = wpvibe_fs();
		if ( ! $fs ) {
			return new WP_Error( 'fs_unavailable', __( 'Filesystem is unavailable.', 'vibe-ai' ), array( 'status' => 500 ) );
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $src, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $item ) {
			$rel = $iterator->getSubPathName();

			if ( $item->isDir() ) {
				$target = $dst . '/' . $rel;
				if ( ! wp_mkdir_p( $target ) && ! is_dir( $target ) ) {
					/* translators: %s: directory path */
					return new WP_Error( 'mkdir_failed', sprintf( __( 'Could not create directory \'%s\' in the scaffold. Check filesystem permissions on the theme root.', 'vibe-ai' ), $target ), array( 'status' => 500 ) );
				}
				continue;
			}

			$content = $fs->get_contents( $item->getPathname() );
			if ( false === $content ) {
				/* translators: %s: file path */
				return new WP_Error( 'read_failed', sprintf( __( 'Could not read starter file: %s', 'vibe-ai' ), $rel ), array( 'status' => 500 ) );
			}

			$content  = strtr( $content, $tokens );
			$dest_rel = preg_replace( '/\.tpl$/', '', $rel );
			$dest     = $dst . '/' . $dest_rel;

			$dir = dirname( $dest );
			if ( ! is_dir( $dir ) ) {
				if ( ! wp_mkdir_p( $dir ) && ! is_dir( $dir ) ) {
					/* translators: %s: directory path */
					return new WP_Error( 'mkdir_failed', sprintf( __( 'Could not create directory \'%s\' for scaffold file. Check filesystem permissions.', 'vibe-ai' ), $dir ), array( 'status' => 500 ) );
				}
			}

			if ( ! $fs->put_contents( $dest, $content, FS_CHMOD_FILE ) ) {
				/* translators: %s: file path */
				return new WP_Error( 'write_failed', sprintf( __( 'Could not write theme file: %s', 'vibe-ai' ), $dest_rel ), array( 'status' => 500 ) );
			}
		}

		return true;
	}

	/**
	 * Validate scaffolded PHP syntax.
	 *
	 * @param string $dir Draft directory.
	 * @return true|WP_Error
	 */
	private function validate_draft( $dir ) {
		$fs = wpvibe_fs();
		if ( ! $fs ) {
			return true;
		}

		$php_files = glob( $dir . '/*.php' );
		if ( $php_files ) {
			foreach ( $php_files as $file ) {
				$content = $fs->get_contents( $file );
				if ( false === $content ) {
					continue;
				}
				$result = wpvibe_check_php_syntax( $content, basename( $file ) );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
		}

		return true;
	}
}
