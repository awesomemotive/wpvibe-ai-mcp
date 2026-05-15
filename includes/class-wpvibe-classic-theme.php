<?php
/**
 * Creates a minimal classic WordPress theme from scratch.
 *
 * Scaffolds style.css, index.php, and functions.php as a draft theme.
 * The live site is never touched — the AI edits the draft and the user publishes when ready.
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

		// Delete any existing draft first.
		$existing_draft = get_option( 'wpvibe_draft_theme' );
		if ( $existing_draft ) {
			$draft = new WPVibe_Draft_Theme();
			$draft->delete();
		}

		$slug      = sanitize_title( $theme_name );

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

		if ( is_dir( $theme_dir ) || is_dir( $draft_dir ) ) {
			/* translators: %s: theme slug */
			return new WP_Error( 'exists', sprintf( __( 'Theme \'%s\' already exists.', 'vibe-ai' ), $slug ), array( 'status' => 409 ) );
		}

		// Create the draft directory. Clear PHP's stat cache first — some environments
		// (e.g. WordPress Studio) cache filesystem state between requests.
		clearstatcache( true, $draft_dir );
		clearstatcache( true, $theme_dir );

		if ( is_dir( $draft_dir ) ) {
			// Leftover from a previous attempt — reuse it.
		} elseif ( ! wp_mkdir_p( $draft_dir ) && ! is_dir( $draft_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Fallback for environments where wp_mkdir_p() fails.
			if ( ! @mkdir( $draft_dir, 0755, true ) && ! is_dir( $draft_dir ) ) {
				/* translators: %s: directory path */
				return new WP_Error( 'mkdir_failed', sprintf( __( 'Could not create draft directory: %s', 'vibe-ai' ), $draft_dir ), array( 'status' => 500 ) );
			}
		}

		$this->write_style_css( $draft_dir, $theme_name, $slug, $description );
		$this->write_index_php( $draft_dir );
		$this->write_functions_php( $draft_dir, $slug );

		// Validate all PHP files before committing.
		$syntax = $this->validate_php_files( $draft_dir );
		if ( is_wp_error( $syntax ) ) {
			$draft_theme = new WPVibe_Draft_Theme();
			$draft_theme->delete_directory_public( $draft_dir );
			return $syntax;
		}

		// Set as the active draft — file editing tools now target this draft.
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
			'message'     => sprintf( __( 'Theme \'%1$s\' created as draft. The live site is unchanged. Preview: %2$s. Use write_file to add header.php, footer.php, etc. Use publish_draft_theme when ready to go live.', 'vibe-ai' ), $theme_name, $preview_url ),
		) );
	}

	private function write_style_css( $dir, $name, $slug, $description ) {
		$desc = $description ?: 'A custom classic WordPress theme.';
		$css  = "/*\n";
		$css .= "Theme Name: {$name}\n";
		$css .= "Text Domain: {$slug}\n";
		$css .= "Description: {$desc}\n";
		$css .= "Version: 1.0.0\n";
		$css .= "Requires at least: 6.0\n";
		$css .= "Requires PHP: 7.4\n";
		$css .= "License: GPL-2.0-or-later\n";
		$css .= "*/\n\n";
		$css .= "body {\n";
		$css .= "\tmargin: 0;\n";
		$css .= "\tfont-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;\n";
		$css .= "\tline-height: 1.6;\n";
		$css .= "\tcolor: #333;\n";
		$css .= "}\n\n";
		$css .= ".site-content {\n";
		$css .= "\tmax-width: 800px;\n";
		$css .= "\tmargin: 0 auto;\n";
		$css .= "\tpadding: 20px;\n";
		$css .= "}\n";

		$fs = wpvibe_fs();
		if ( $fs ) {
			$fs->put_contents( $dir . '/style.css', $css, FS_CHMOD_FILE );
		}
	}

	private function write_index_php( $dir ) {
		$php = "<?php get_header(); ?>\n";
		$php .= "\n";
		$php .= "<main class=\"site-content\">\n";
		$php .= "<?php if ( have_posts() ) : ?>\n";
		$php .= "\t<?php while ( have_posts() ) : the_post(); ?>\n";
		$php .= "\t\t<article id=\"post-<?php the_ID(); ?>\" <?php post_class(); ?>>\n";
		$php .= "\t\t\t<h2><a href=\"<?php the_permalink(); ?>\"><?php the_title(); ?></a></h2>\n";
		$php .= "\t\t\t<div class=\"entry-content\">\n";
		$php .= "\t\t\t\t<?php the_content(); ?>\n";
		$php .= "\t\t\t</div>\n";
		$php .= "\t\t</article>\n";
		$php .= "\t<?php endwhile; ?>\n";
		$php .= "<?php else : ?>\n";
		$php .= "\t<p><?php esc_html_e( 'No posts found.' ); ?></p>\n";
		$php .= "<?php endif; ?>\n";
		$php .= "</main>\n";
		$php .= "\n";
		$php .= "<?php get_footer(); ?>";
		$fs = wpvibe_fs();
		if ( $fs ) {
			$fs->put_contents( $dir . '/index.php', $php, FS_CHMOD_FILE );
		}
	}

	private function write_functions_php( $dir, $slug ) {
		$prefix = str_replace( '-', '_', $slug );
		$php = "<?php\n";
		$php .= "/**\n";
		$php .= " * Theme functions.\n";
		$php .= " */\n";
		$php .= "\n";
		$php .= "function {$prefix}_setup() {\n";
		$php .= "\tadd_theme_support( 'title-tag' );\n";
		$php .= "\tadd_theme_support( 'post-thumbnails' );\n";
		$php .= "\tadd_theme_support( 'html5', array( 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption' ) );\n";
		$php .= "}\n";
		$php .= "add_action( 'after_setup_theme', '{$prefix}_setup' );\n";
		$php .= "\n";
		$php .= "function {$prefix}_scripts() {\n";
		$php .= "\twp_enqueue_style( '{$slug}-style', get_stylesheet_uri(), array(), wp_get_theme()->get( 'Version' ) );\n";
		$php .= "}\n";
		$php .= "add_action( 'wp_enqueue_scripts', '{$prefix}_scripts' );";
		$fs = wpvibe_fs();
		if ( $fs ) {
			$fs->put_contents( $dir . '/functions.php', $php, FS_CHMOD_FILE );
		}
	}

	/**
	 * Validate all PHP files in a directory using the tokenizer.
	 *
	 * @param string $dir Directory to check.
	 * @return true|WP_Error
	 */
	private function validate_php_files( $dir ) {
		$files = glob( $dir . '/*.php' );
		if ( ! $files ) {
			return true;
		}

		$fs = wpvibe_fs();
		if ( ! $fs ) {
			return true;
		}

		foreach ( $files as $file ) {
			$content = $fs->get_contents( $file );
			if ( false === $content ) {
				continue;
			}
			$result = wpvibe_check_php_syntax( $content, basename( $file ) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}
}
