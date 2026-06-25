<?php
/**
 * Page template — generic page (About, Contact, Services, etc.).
 *
 * The home page uses front-page.php; this is the fallback for any other page.
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
	<?php endwhile; endif; ?>
</main>

<?php get_footer(); ?>
