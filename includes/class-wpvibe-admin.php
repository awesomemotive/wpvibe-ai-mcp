<?php
/**
 * Admin page for Vibe AI.
 *
 * @package VibeAI
 */

defined( 'ABSPATH' ) || exit;

class WPVibe_Admin {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_init', array( $this, 'maybe_redirect_after_activation' ) );
		register_activation_hook( WPVIBE_PLUGIN_DIR . 'vibe-ai.php', array( $this, 'on_activate' ) );
	}

	/**
	 * Set transient on activation so we can redirect.
	 */
	public function on_activate() {
		set_transient( 'wpvibe_activation_redirect', true, 30 );
	}

	/**
	 * Redirect to admin page after activation.
	 */
	public function maybe_redirect_after_activation() {
		if ( ! get_transient( 'wpvibe_activation_redirect' ) ) {
			return;
		}
		delete_transient( 'wpvibe_activation_redirect' );

		// Don't redirect on bulk activate or network admin.
		if ( wp_doing_ajax() || is_network_admin() || isset( $_GET['activate-multi'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=vibe-ai' ) );
		exit;
	}

	/**
	 * Register top-level admin menu.
	 */
	public function add_menu() {
		add_menu_page(
			__( 'Vibe AI', 'vibe-ai' ),
			__( 'Vibe AI', 'vibe-ai' ),
			'manage_options',
			'vibe-ai',
			array( $this, 'render_page' ),
			$this->get_menu_icon(),
			59
		);
	}

	/**
	 * Base64-encoded SVG for the admin menu icon.
	 */
	private function get_menu_icon() {
		$svg = '<svg viewBox="0 0 72 72" fill="none" xmlns="http://www.w3.org/2000/svg">'
			. '<path d="M36 4L40 28L64 32L40 36L36 60L32 36L8 32L32 28L36 4Z" fill="black"/>'
			. '</svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	/**
	 * Enqueue admin CSS/JS only on our page.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'toplevel_page_vibe-ai' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'vibe-ai-admin',
			WPVIBE_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			WPVIBE_VERSION
		);
	}

	/**
	 * Check if site is connected to WPVibe.
	 * Connected = received an authenticated WPVibe request within the last 30 days.
	 */
	private function is_connected() {
		$last_active = (int) get_option( 'wpvibe_last_active', 0 );
		return $last_active > 0 && ( time() - $last_active ) < 30 * DAY_IN_SECONDS;
	}

	/**
	 * Render the admin page.
	 */
	public function render_page() {
		$connected = $this->is_connected();
		$site_url  = site_url();
		?>
		<div class="wpvibe-admin-wrap">
			<div class="wpvibe-admin-page">

				<!-- Logo -->
				<div class="wpvibe-logo">
					<svg viewBox="0 0 72 72" fill="none" class="wpvibe-logo-svg">
						<defs>
							<linearGradient id="wpvibeLogoGrad" x1="0" y1="0" x2="72" y2="72" gradientUnits="userSpaceOnUse">
								<stop stop-color="#60a5fa"/>
								<stop offset="1" stop-color="#2563eb"/>
							</linearGradient>
							<path id="wpvibeOrbitPath" d="M 54.01 17.99 A 22 12 -35 1 1 17.99 50.01 A 22 12 -35 1 1 54.01 17.99" fill="none"/>
						</defs>
						<ellipse cx="36" cy="34" rx="22" ry="12" stroke="url(#wpvibeLogoGrad)" stroke-width="2" fill="none" opacity="0.4" transform="rotate(-35 36 34)"/>
						<path d="M36 4L40 28L64 32L40 36L36 60L32 36L8 32L32 28L36 4Z" fill="url(#wpvibeLogoGrad)"/>
						<circle r="4" fill="#60a5fa">
							<animateMotion dur="4s" repeatCount="indefinite">
								<mpath href="#wpvibeOrbitPath"/>
							</animateMotion>
						</circle>
						<circle r="3.5" fill="#2563eb">
							<animateMotion dur="7s" repeatCount="indefinite">
								<mpath href="#wpvibeOrbitPath"/>
							</animateMotion>
						</circle>
					</svg>
					<div class="wpvibe-logo-text">
						<span>WPVibe</span>
						<small><?php esc_html_e( 'by SeedProd', 'vibe-ai' ); ?></small>
					</div>
				</div>

				<!-- Status badge -->
				<div class="wpvibe-status <?php echo $connected ? 'wpvibe-status--connected' : 'wpvibe-status--disconnected'; ?>">
					<span class="wpvibe-status-dot"></span>
					<?php
					if ( $connected ) {
						esc_html_e( 'Connected', 'vibe-ai' );
					} else {
						esc_html_e( 'Not Connected', 'vibe-ai' );
					}
					?>
				</div>

				<!-- Headline -->
				<h1 class="wpvibe-headline">
					<?php esc_html_e( 'Your AI just learned WordPress.', 'vibe-ai' ); ?>
				</h1>
				<p class="wpvibe-subheadline">
					<?php esc_html_e( 'Connect this site to WPVibe to manage content, edit themes, and build pages using AI assistants like Claude, ChatGPT, and Cursor.', 'vibe-ai' ); ?>
				</p>

				<!-- CTA -->
				<div class="wpvibe-cta">
					<?php if ( $connected ) : ?>
						<a href="https://wpvibe.ai/app/" class="wpvibe-btn wpvibe-btn--primary" target="_blank" rel="noopener">
							<?php esc_html_e( 'Open WPVibe', 'vibe-ai' ); ?>
						</a>
					<?php else : ?>
						<a href="https://mcp.wpvibe.ai/?connect=<?php echo esc_attr( rawurlencode( $site_url ) ); ?>" class="wpvibe-btn wpvibe-btn--primary" target="_blank" rel="noopener">
							<?php esc_html_e( 'Connect to WPVibe', 'vibe-ai' ); ?>
						</a>
					<?php endif; ?>
				</div>

				<!-- Steps -->
				<div class="wpvibe-steps">
					<div class="wpvibe-step wpvibe-step--done">
						<div class="wpvibe-step-num">&#10003;</div>
						<div class="wpvibe-step-content">
							<strong><?php esc_html_e( 'Install Vibe AI Plugin', 'vibe-ai' ); ?></strong>
							<span><?php esc_html_e( 'You\'re here, plugin is active.', 'vibe-ai' ); ?></span>
						</div>
					</div>
					<div class="wpvibe-step">
						<div class="wpvibe-step-num">2</div>
						<div class="wpvibe-step-content">
							<strong><?php esc_html_e( 'Connect the MCP to Your AI', 'vibe-ai' ); ?></strong>
							<span><?php esc_html_e( 'Click the button above to get the MCP server URL and setup instructions for Claude, ChatGPT, and Cursor.', 'vibe-ai' ); ?></span>
						</div>
					</div>
					<div class="wpvibe-step <?php echo $connected ? 'wpvibe-step--done' : ''; ?>">
						<div class="wpvibe-step-num"><?php echo $connected ? '&#10003;' : '3'; ?></div>
						<div class="wpvibe-step-content">
							<strong><?php esc_html_e( 'Connect This Site via Your AI', 'vibe-ai' ); ?></strong>
							<span>
								<?php if ( $connected ) : ?>
									<?php esc_html_e( 'Site is connected and ready.', 'vibe-ai' ); ?>
								<?php else : ?>
									<?php esc_html_e( 'In your AI chat, ask it to connect this WordPress site to WPVibe.', 'vibe-ai' ); ?>
								<?php endif; ?>
							</span>
						</div>
					</div>
				</div>

				<!-- Footer links -->
				<div class="wpvibe-footer">
					<a href="https://wpvibe.ai" target="_blank" rel="noopener"><?php esc_html_e( 'wpvibe.ai', 'vibe-ai' ); ?></a>
					<span class="wpvibe-footer-sep">&middot;</span>
					<a href="https://wpvibe.ai/docs/" target="_blank" rel="noopener"><?php esc_html_e( 'Documentation', 'vibe-ai' ); ?></a>
					<span class="wpvibe-footer-sep">&middot;</span>
					<a href="https://wpvibe.ai/support/" target="_blank" rel="noopener"><?php esc_html_e( 'Support', 'vibe-ai' ); ?></a>
				</div>

			</div>
		</div>
		<?php
	}
}
