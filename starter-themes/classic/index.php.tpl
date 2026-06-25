<?php get_header(); ?>

<main class="max-w-3xl mx-auto px-6 py-12 flex-1">
	<?php if ( have_posts() ) : ?>
		<?php while ( have_posts() ) : the_post(); ?>
			<article id="post-<?php the_ID(); ?>" <?php post_class( 'mb-12' ); ?>>
				<h2 class="text-2xl font-bold mb-2">
					<a class="no-underline text-neutral hover:text-primary transition-colors" href="<?php the_permalink(); ?>">
						<?php the_title(); ?>
					</a>
				</h2>
				<div class="text-sm text-secondary-500 mb-4">
					<time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time>
				</div>
				<div class="prose max-w-none">
					<?php the_excerpt(); ?>
				</div>
			</article>
		<?php endwhile; ?>

		<?php
		the_posts_pagination( array(
			'mid_size'  => 1,
			'prev_text' => '&larr; Older',
			'next_text' => 'Newer &rarr;',
		) );
		?>
	<?php else : ?>
		<p class="text-secondary-500"><?php esc_html_e( 'No posts found.', '{{THEME_SLUG}}' ); ?></p>
	<?php endif; ?>
</main>

<?php get_footer(); ?>
