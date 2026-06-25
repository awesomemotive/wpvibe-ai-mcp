<?php
/**
 * {{THEME_NAME}} footer.
 */
?>
<footer class="border-t border-secondary-200 mt-16">
	<div class="max-w-6xl mx-auto px-6 py-6 text-sm text-secondary-500">
		&copy; <?php echo esc_html( gmdate( 'Y' ) ); ?> <?php bloginfo( 'name' ); ?>
	</div>
</footer>

<?php wp_footer(); ?>
</body>
</html>
