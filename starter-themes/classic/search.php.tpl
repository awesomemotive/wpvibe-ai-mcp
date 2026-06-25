<?php
/**
 * Search results template.
 */
?>
<?php get_header(); ?>

<main class="flex-1">
	<header class="max-w-4xl mx-auto px-6 pt-16 pb-8 border-b border-secondary-200">
		<p class="text-sm uppercase tracking-wider text-secondary-600">
			<?php esc_html_e( 'Search results', 'vibe-ai' ); ?>
		</p>
		<h1 class="mt-2 text-3xl md:text-4xl font-bold tracking-tight text-neutral">
			<?php
			/* translators: %s: search query */
			printf( esc_html__( 'Results for "%s"', 'vibe-ai' ), '<em class="not-italic">' . esc_html( get_search_query() ) . '</em>' );
			?>
		</h1>
		<div class="mt-6 max-w-md">
			<?php get_search_form(); ?>
		</div>
	</header>

	<div class="max-w-4xl mx-auto px-6 py-12">
		<?php if ( have_posts() ) : ?>
			<div class="grid gap-10">
				<?php while ( have_posts() ) : the_post(); ?>
					<article class="border-b border-secondary-200 pb-8 last:border-b-0">
						<h2 class="text-xl md:text-2xl font-semibold mb-2">
							<a href="<?php the_permalink(); ?>" class="text-neutral hover:text-primary-500">
								<?php the_title(); ?>
							</a>
						</h2>
						<div class="prose text-secondary-700">
							<?php the_excerpt(); ?>
						</div>
					</article>
				<?php endwhile; ?>
			</div>

			<nav class="mt-12 flex justify-between text-sm">
				<?php echo get_previous_posts_link( '&larr; ' . esc_html__( 'Newer results', 'vibe-ai' ) ); ?>
				<?php echo get_next_posts_link( esc_html__( 'Older results', 'vibe-ai' ) . ' &rarr;' ); ?>
			</nav>
		<?php else : ?>
			<p class="text-secondary-600">
				<?php esc_html_e( 'No matches. Try different keywords.', 'vibe-ai' ); ?>
			</p>
		<?php endif; ?>
	</div>
</main>

<?php get_footer(); ?>
