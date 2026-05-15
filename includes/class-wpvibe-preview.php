<?php
/**
 * Draft theme preview — swaps the active theme for requests
 * that include a valid wpvibe_preview token.
 *
 * Also injects:
 * - A preview banner so the user knows they're viewing the draft.
 * - JavaScript to rewrite local links so navigation stays in preview mode.
 */

defined( 'ABSPATH' ) || exit;

class WPVibe_Preview {

	private static $instance = null;
	private $preview_token   = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'template', array( $this, 'swap_template' ) );
		add_filter( 'stylesheet', array( $this, 'swap_stylesheet' ) );

		// Enqueue banner styles/script and render banner only on preview requests.
		if ( $this->get_preview_slug() ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Preview token verified via hash_equals in get_preview_slug().
			$this->preview_token = isset( $_GET['wpvibe_preview'] ) ? sanitize_text_field( wp_unslash( $_GET['wpvibe_preview'] ) ) : '';
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_preview_assets' ) );
			add_action( 'wp_footer', array( $this, 'render_preview_banner' ), 9999 );
		}
	}

	/**
	 * Check if the current request is a valid preview.
	 *
	 * @return string|false Draft theme slug or false.
	 */
	private function get_preview_slug() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotValidated
		if ( ! isset( $_GET['wpvibe_preview'] ) || empty( $_GET['wpvibe_preview'] ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Preview token verified via hash_equals, not nonce.
		$input  = sanitize_text_field( wp_unslash( $_GET['wpvibe_preview'] ) );
		$token  = get_option( 'wpvibe_preview_token' );
		$issued = (int) get_option( 'wpvibe_preview_token_issued', 0 );
		if ( ! $token || ! hash_equals( $token, $input ) ) {
			return false;
		}
		// Tokens expire 24h after issue. Anyone who learns the URL can't use it forever.
		if ( $issued > 0 && ( time() - $issued ) > DAY_IN_SECONDS ) {
			return false;
		}

		$draft_slug = get_option( 'wpvibe_draft_theme' );
		if ( ! $draft_slug || ! is_dir( get_theme_root() . '/' . $draft_slug ) ) {
			return false;
		}

		return $draft_slug;
	}

	public function swap_template( $template ) {
		$slug = $this->get_preview_slug();
		return $slug ? $slug : $template;
	}

	public function swap_stylesheet( $stylesheet ) {
		$slug = $this->get_preview_slug();
		return $slug ? $slug : $stylesheet;
	}

	/**
	 * Enqueue preview banner CSS and link-rewriter JS.
	 */
	public function enqueue_preview_assets() {
		wp_enqueue_style(
			'wpvibe-preview-banner',
			WPVIBE_PLUGIN_URL . 'assets/css/preview-banner.css',
			array(),
			WPVIBE_VERSION
		);

		wp_enqueue_script(
			'wpvibe-preview-banner',
			WPVIBE_PLUGIN_URL . 'assets/js/preview-banner.js',
			array(),
			WPVIBE_VERSION,
			true
		);

		wp_localize_script(
			'wpvibe-preview-banner',
			'wpvibePreview',
			array(
				'token' => $this->preview_token,
				'param' => 'wpvibe_preview',
			)
		);
	}

	/**
	 * Render the preview banner HTML in the footer.
	 */
	public function render_preview_banner() {
		$live_url = esc_url( home_url( '/' ) );
		?>
		<div id="wpvibe-preview-banner">
			<span class="wpvibe-badge">
				<span class="wpvibe-dot"></span>
				<?php esc_html_e( 'WPVibe Draft Preview', 'vibe-ai' ); ?>
			</span>
			<span class="wpvibe-info">
				<?php esc_html_e( 'Changes are only visible to you. The live site is unaffected.', 'vibe-ai' ); ?>
			</span>
			<a href="<?php echo esc_url( $live_url ); ?>" class="wpvibe-btn wpvibe-btn-live"><?php esc_html_e( 'View Live Site', 'vibe-ai' ); ?></a>
		</div>
		<?php
	}
}
