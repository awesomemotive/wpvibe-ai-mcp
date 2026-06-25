<?php
/**
 * {{THEME_NAME}} front page.
 *
 * Renders for the home page. When WordPress has a static front page, the
 * hero heading + subheading are editable from that page in wp-admin.
 *
 * Replace the hero markup with something distinctive for the brand.
 * This template is intentionally sparse so the AI can refine it for
 * the user's actual design.
 */
?>
<?php get_header(); ?>
<?php
$wpvibe_front_page_id = (int) get_option( 'page_on_front' );
$wpvibe_hero_page_id  = ( $wpvibe_front_page_id && 'page' === get_post_type( $wpvibe_front_page_id ) ) ? $wpvibe_front_page_id : 0;
$wpvibe_hero_heading  = $wpvibe_hero_page_id ? get_post_meta( $wpvibe_hero_page_id, 'hero_heading', true ) : '';
$wpvibe_hero_subhead  = $wpvibe_hero_page_id ? get_post_meta( $wpvibe_hero_page_id, 'hero_subheading', true ) : '';
?>

<main class="flex-1">
	<section class="bg-gradient-to-br from-primary-50 to-background py-20">
		<div class="max-w-4xl mx-auto px-6 text-center">
			<h1 class="text-5xl font-bold tracking-tight text-neutral" <?php $wpvibe_hero_page_id && function_exists( 'wpvibe_edit_attr' ) && wpvibe_edit_attr( $wpvibe_hero_page_id, 'hero_heading' ); ?>>
				<?php echo esc_html( $wpvibe_hero_heading ?: 'Welcome to {{THEME_NAME}}.' ); ?>
			</h1>
			<p class="mt-4 text-xl text-secondary-600 max-w-2xl mx-auto" <?php $wpvibe_hero_page_id && function_exists( 'wpvibe_edit_attr' ) && wpvibe_edit_attr( $wpvibe_hero_page_id, 'hero_subheading' ); ?>>
				<?php echo esc_html( $wpvibe_hero_subhead ?: 'A fresh WordPress theme ready for your content.' ); ?>
			</p>
		</div>
	</section>

	<?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>
		<?php if ( get_the_content() ) : ?>
			<article class="prose max-w-3xl mx-auto px-6 py-16">
				<?php the_content(); ?>
			</article>
		<?php endif; ?>
	<?php endwhile; endif; ?>
</main>

<?php get_footer(); ?>
