<?php
/**
 * {{THEME_NAME}} functions.
 */

function {{FUNCTION_PREFIX}}_setup() {
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'responsive-embeds' );
	add_theme_support( 'html5', array( 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption' ) );
	register_nav_menus( array(
		'primary' => __( 'Primary Menu', '{{THEME_SLUG}}' ),
	) );
}
add_action( 'after_setup_theme', '{{FUNCTION_PREFIX}}_setup' );

function {{FUNCTION_PREFIX}}_scripts() {
	wp_enqueue_style( '{{THEME_SLUG}}-style', get_stylesheet_uri(), array(), wp_get_theme()->get( 'Version' ) );
	wp_enqueue_script(
		'alpinejs',
		get_theme_file_uri( 'assets/js/alpine.min.js' ),
		array(),
		'3.15.12',
		array( 'strategy' => 'defer' )
	);

	// Compiled Tailwind output. Present only after publish; skip in draft
	// mode so the browser CDN can take over. filemtime() doubles as cache-bust.
	$dist     = get_stylesheet_directory() . '/dist/styles.css';
	$is_draft = get_option( 'wpvibe_draft_theme' ) === get_stylesheet();
	if ( ! $is_draft && file_exists( $dist ) ) {
		wp_enqueue_style(
			'{{THEME_SLUG}}-compiled',
			get_theme_file_uri( 'dist/styles.css' ),
			array(),
			filemtime( $dist )
		);
	}
}
add_action( 'wp_enqueue_scripts', '{{FUNCTION_PREFIX}}_scripts' );

/* ───────────────────────────────────────────────────────────────────────────
   Gutenberg integration — sync design tokens from theme.css.

   theme.css's @theme block is the single source of truth. Tailwind reads it
   on the frontend (inlined into a <style type="text/tailwindcss"> block by
   template-parts/head.php). This function parses the same file and registers
   the palette + font sizes with Gutenberg via add_theme_support(), so the
   block editor's color picker / font selector show the same tokens.

   No theme.json — single source of truth, zero drift.
   ─────────────────────────────────────────────────────────────────────────── */

function {{FUNCTION_PREFIX}}_editor_tokens() {
	$css_path = get_stylesheet_directory() . '/theme.css';
	if ( ! file_exists( $css_path ) ) {
		return;
	}
	$css = file_get_contents( $css_path );
	if ( false === $css || ! preg_match( '/@theme\s*\{([\s\S]+?)\}/', $css, $m ) ) {
		return;
	}
	$body = $m[1];

	// --color-{slug}: {value};   (skip numeric shades like primary-50, primary-500)
	$palette = array();
	if ( preg_match_all( '/--color-([a-z0-9_-]+?)\s*:\s*([^;]+);/i', $body, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $match ) {
			if ( preg_match( '/-\d+$/', $match[1] ) ) {
				continue;
			}
			$palette[] = array(
				'slug'  => $match[1],
				'name'  => ucwords( str_replace( '-', ' ', $match[1] ) ),
				'color' => trim( $match[2] ),
			);
		}
	}
	if ( $palette ) {
		add_theme_support( 'editor-color-palette', $palette );
	}

	// --text-{slug}: {value};
	$sizes = array();
	if ( preg_match_all( '/--text-([a-z0-9_-]+)\s*:\s*([^;]+);/i', $body, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $match ) {
			$sizes[] = array(
				'slug' => $match[1],
				'name' => strtoupper( $match[1] ),
				'size' => trim( $match[2] ),
			);
		}
	}
	if ( $sizes ) {
		add_theme_support( 'editor-font-sizes', $sizes );
	}

	// Editor stylesheet for prose styling inside the Gutenberg iframe.
	add_editor_style( 'editor.css' );

	// Lock users to the palette in the block editor color picker.
	add_theme_support( 'disable-custom-colors' );
}
add_action( 'after_setup_theme', '{{FUNCTION_PREFIX}}_editor_tokens', 20 );

/* ───────────────────────────────────────────────────────────────────────────
   Auto-create Home + Blog pages on first activation.

   WordPress defaults to blog-mode (homepage = latest posts). For ~80% of
   AI-built sites the user wants a static homepage with a separate blog
   page. On first activation we create the two pages (if they don't exist)
   and point the static-front-page settings at them. Skipped entirely if
   the user has already configured a static front page.
   ─────────────────────────────────────────────────────────────────────────── */

function {{FUNCTION_PREFIX}}_setup_pages() {
	if ( 'page' === get_option( 'show_on_front' ) && get_option( 'page_on_front' ) ) {
		return;
	}

	$home = get_page_by_path( 'home' );
	$home_id = $home ? (int) $home->ID : wp_insert_post( array(
		'post_title'  => 'Home',
		'post_name'   => 'home',
		'post_status' => 'publish',
		'post_type'   => 'page',
	) );

	$blog = get_page_by_path( 'blog' );
	$blog_id = $blog ? (int) $blog->ID : wp_insert_post( array(
		'post_title'  => 'Blog',
		'post_name'   => 'blog',
		'post_status' => 'publish',
		'post_type'   => 'page',
	) );

	if ( $home_id && ! is_wp_error( $home_id ) && $blog_id && ! is_wp_error( $blog_id ) ) {
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $home_id );
		update_option( 'page_for_posts', $blog_id );
	}
}
add_action( 'after_switch_theme', '{{FUNCTION_PREFIX}}_setup_pages' );

/* ───────────────────────────────────────────────────────────────────────────
   Editable surfaces (Settings -> WPVibe + per-page meta).

   Wrapped in function_exists so the theme degrades gracefully if the
   WPVibe plugin is deactivated. Read with get_option / get_post_meta
   anywhere in templates.
   ─────────────────────────────────────────────────────────────────────────── */

if ( function_exists( 'wpvibe_setting_register' ) ) {
	wpvibe_setting_register( '{{FUNCTION_PREFIX}}_tagline', array(
		'type'        => 'text',
		'label'       => 'Site tagline',
		'description' => 'Appears in the site header next to the brand name.',
		'default'     => '',
	) );
}

if ( function_exists( 'wpvibe_field_register' ) ) {
	wpvibe_field_register( 'page', 'hero_heading', array(
		'type'        => 'text',
		'label'       => 'Hero heading',
		'description' => 'Large headline at the top of the page. Shown on front-page.php.',
	) );
	wpvibe_field_register( 'page', 'hero_subheading', array(
		'type'        => 'textarea',
		'label'       => 'Hero subheading',
		'description' => 'Supporting text below the hero heading.',
	) );
}
