<?php
/**
 * 404 template — page-not-found.
 */
?>
<?php get_header(); ?>

<main class="flex-1">
	<section class="max-w-2xl mx-auto px-6 py-24 text-center">
		<p class="text-sm uppercase tracking-wider text-secondary-600">404</p>
		<h1 class="mt-3 text-4xl md:text-5xl font-bold tracking-tight text-neutral">
			<?php esc_html_e( "We can't find that page.", 'vibe-ai' ); ?>
		</h1>
		<p class="mt-4 text-secondary-700">
			<?php esc_html_e( 'It may have been moved, renamed, or never existed. Try a search or head back home.', 'vibe-ai' ); ?>
		</p>

		<div class="mt-10 max-w-md mx-auto">
			<?php get_search_form(); ?>
		</div>

		<p class="mt-8">
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="inline-flex items-center text-primary-500 hover:underline">
				&larr; <?php esc_html_e( 'Back to {{THEME_NAME}}', 'vibe-ai' ); ?>
			</a>
		</p>
	</section>
</main>

<?php get_footer(); ?>
