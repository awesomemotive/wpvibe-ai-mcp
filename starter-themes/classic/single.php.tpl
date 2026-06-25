<?php
/**
 * Single post template — renders one blog post.
 *
 * Sparse on purpose. Refine the markup, spacing, and tokens to match the
 * theme's design once the brand voice is established.
 */
?>
<?php get_header(); ?>

<main class="flex-1">
	<?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>
		<article class="max-w-3xl mx-auto px-6 py-16">
			<header class="mb-10">
				<h1 class="text-4xl md:text-5xl font-bold tracking-tight text-neutral">
					<?php the_title(); ?>
				</h1>
				<p class="mt-3 text-sm uppercase tracking-wider text-secondary-600">
					<time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time>
					<?php if ( get_the_author() ) : ?>
						<span class="mx-2">&middot;</span>
						<span><?php the_author(); ?></span>
					<?php endif; ?>
				</p>
			</header>

			<?php if ( has_post_thumbnail() ) : ?>
				<figure class="mb-10 -mx-6 md:mx-0">
					<?php the_post_thumbnail( 'large', array( 'class' => 'w-full h-auto' ) ); ?>
				</figure>
			<?php endif; ?>

			<div class="prose max-w-none">
				<?php the_content(); ?>
			</div>
		</article>

		<nav class="max-w-3xl mx-auto px-6 pb-16 flex justify-between gap-8 text-sm">
			<?php
			$prev_post = get_previous_post();
			$next_post = get_next_post();
			?>
			<?php if ( $prev_post ) : ?>
				<a href="<?php echo esc_url( get_permalink( $prev_post ) ); ?>" class="text-secondary-600 hover:text-primary-500">
					&larr; <?php echo esc_html( get_the_title( $prev_post ) ); ?>
				</a>
			<?php else : ?><span></span><?php endif; ?>
			<?php if ( $next_post ) : ?>
				<a href="<?php echo esc_url( get_permalink( $next_post ) ); ?>" class="text-secondary-600 hover:text-primary-500 text-right">
					<?php echo esc_html( get_the_title( $next_post ) ); ?> &rarr;
				</a>
			<?php endif; ?>
		</nav>

		<?php if ( comments_open() || get_comments_number() ) : ?>
			<div class="max-w-3xl mx-auto px-6 pb-16">
				<?php comments_template(); ?>
			</div>
		<?php endif; ?>
	<?php endwhile; endif; ?>
</main>

<?php get_footer(); ?>
