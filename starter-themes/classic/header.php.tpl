<?php
/**
 * {{THEME_NAME}} header.
 */
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<?php get_template_part( 'template-parts/head' ); ?>
</head>

<body <?php body_class( 'bg-background text-neutral font-sans min-h-screen flex flex-col' ); ?>>
<?php wp_body_open(); ?>

<header class="border-b border-secondary-200">
	<div class="max-w-6xl mx-auto px-6 py-4 flex items-center justify-between">
		<a class="text-lg font-bold no-underline text-neutral hover:text-primary transition-colors" href="<?php echo esc_url( home_url( '/' ) ); ?>">
			<?php bloginfo( 'name' ); ?>
		</a>
		<?php
		wp_nav_menu( array(
			'theme_location'  => 'primary',
			'container'       => 'nav',
			'container_class' => 'flex gap-6 text-sm',
			'menu_class'      => 'flex gap-6 list-none m-0 p-0',
			'fallback_cb'     => false,
			'depth'           => 1,
		) );
		?>
	</div>
</header>
