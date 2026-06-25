<?php
/**
 * Archive template — date, category, tag, author, and taxonomy archives.
 *
 * Acts as the blog index (home.php fallback) on themes without a dedicated
 * home.php. Refine for the design once the user has an established layout.
 */
?>
<?php get_header(); ?>

<main class="flex-1">
	<header class="max-w-4xl mx-auto px-6 pt-16 pb-8 border-b border-secondary-200">
		<h1 class="text-3xl md:text-4xl font-bold tracking-tight text-neutral">
			<?php echo wp_kses_post( get_the_archive_title() ); ?>
		</h1>
		<?php $desc = get_the_archive_description(); if ( $desc ) : ?>
			<div class="mt-3 text-secondary-600 max-w-2xl">
				<?php echo wp_kses_post( $desc ); ?>
			</div>
		<?php endif; ?>
	</header>

	<div class="max-w-4xl mx-auto px-6 py-12">
		<?php if ( have_posts() ) : ?>
			<div class="grid gap-12">
				<?php while ( have_posts() ) : the_post(); ?>
					<article class="border-b border-secondary-200 pb-12 last:border-b-0">
						<p class="text-xs uppercase tracking-wider text-secondary-600 mb-2">
							<time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time>
						</p>
						<h2 class="text-2xl md:text-3xl font-semibold mb-3">
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
				<?php echo get_previous_posts_link( '&larr; ' . esc_html__( 'Newer posts', 'vibe-ai' ) ); ?>
				<?php echo get_next_posts_link( esc_html__( 'Older posts', 'vibe-ai' ) . ' &rarr;' ); ?>
			</nav>
		<?php else : ?>
			<p class="text-secondary-600"><?php esc_html_e( 'Nothing to show here yet.', 'vibe-ai' ); ?></p>
		<?php endif; ?>
	</div>
</main>

<?php get_footer(); ?>
