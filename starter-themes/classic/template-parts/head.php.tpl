<?php
/**
 * {{THEME_NAME}} <head> partial.
 *
 * Loaded by header.php via get_template_part( 'template-parts/head' ).
 *
 * The <style type="text/tailwindcss"> block is the Tailwind v4 token
 * source the browser CDN reads in draft preview. Do not remove it.
 * Mode switching is automatic:
 *   - Draft mode (active theme matches the draft option): the Vibe AI
 *     plugin enqueues the CDN runtime + plugin-served presets.css.
 *   - Live mode: functions.php enqueues dist/styles.css when present.
 */
?>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">

<?php if ( get_option( 'wpvibe_draft_theme' ) === get_stylesheet() ) : ?>
<style type="text/tailwindcss"><?php
	$theme_css_path = get_stylesheet_directory() . '/theme.css';
	if ( file_exists( $theme_css_path ) ) {
		readfile( $theme_css_path );
	}
?></style>
<?php endif; ?>

<?php wp_head(); ?>
