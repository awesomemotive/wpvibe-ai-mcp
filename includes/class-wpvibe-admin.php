<?php
/**
 * Admin page for the WPVibe plugin (slug "vibe-ai" on WordPress.org).
 *
 * @package WPVibe
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
		add_action( 'admin_post_wpvibe_white_label', array( $this, 'handle_white_label_save' ) );
		add_action( 'admin_post_wpvibe_app_passwords', array( $this, 'handle_app_password_policy' ) );
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

		if ( WPVibe_White_Label::is_hidden() ) {
			return;
		}

		// Don't redirect on bulk activate or network admin.
		if ( wp_doing_ajax() || is_network_admin() || isset( $_GET['activate-multi'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=vibe-ai' ) );
		exit;
	}

	/**
	 * Enable white label mode from the admin page. Enable-only: once hidden
	 * this page no longer exists, so disabling happens via the AI or WP-CLI.
	 */
	public function handle_app_password_policy() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'vibe-ai' ), 403 );
		}
		check_admin_referer( 'wpvibe_app_passwords' );
		update_option( WPVibe_App_Password_Policy::OPTION, empty( $_POST['disable'] ), false );
		delete_transient( WPVibe_Connection_Check::COOLDOWN );
		wp_safe_redirect( admin_url( 'admin.php?page=vibe-ai&wpvibe_recheck=1' ) );
		exit;
	}

	public function handle_white_label_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'vibe-ai' ), 403 );
		}
		check_admin_referer( 'wpvibe_white_label' );

		if ( ! WPVibe_White_Label::site_is_connected() ) {
			wp_die( esc_html__( 'White label mode requires an active WPVibe connection. Connect the site first.', 'vibe-ai' ), 400 );
		}

		update_option( WPVibe_White_Label::OPTION, 1 );
		wp_safe_redirect( admin_url() );
		exit;
	}

	/**
	 * Register top-level admin menu.
	 */
	public function add_menu() {
		if ( WPVibe_White_Label::is_hidden() ) {
			return;
		}
		add_menu_page(
			__( 'WPVibe', 'vibe-ai' ),
			__( 'WPVibe', 'vibe-ai' ),
			'manage_options',
			'vibe-ai',
			array( $this, 'render_page' ),
			$this->get_menu_icon(),
			59
		);

		add_submenu_page(
			'vibe-ai',
			__( 'Approval Log', 'vibe-ai' ),
			__( 'Approval Log', 'vibe-ai' ),
			'manage_options',
			'vibe-ai-activity',
			array( $this, 'render_activity_page' )
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
		$ours = array( 'toplevel_page_vibe-ai', 'vibe-ai_page_vibe-ai-activity' );
		if ( ! in_array( $hook, $ours, true ) ) {
			return;
		}

		$css_path = WPVIBE_PLUGIN_DIR . 'assets/css/admin.css';
		$js_path  = WPVIBE_PLUGIN_DIR . 'assets/js/admin.js';
		$css_ver  = file_exists( $css_path ) ? (string) filemtime( $css_path ) : WPVIBE_VERSION;
		$js_ver   = file_exists( $js_path ) ? (string) filemtime( $js_path ) : WPVIBE_VERSION;

		wp_enqueue_style(
			'vibe-ai-admin',
			WPVIBE_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			$css_ver
		);

		wp_enqueue_script(
			'vibe-ai-admin',
			WPVIBE_PLUGIN_URL . 'assets/js/admin.js',
			array(),
			$js_ver,
			true
		);
	}

	/**
	 * Append our standard UTM params to a wpvibe.ai / mcp.wpvibe.ai URL.
	 * Pattern matches readme.txt's wprepo convention; here source is the
	 * in-product admin page so we can split analytics by surface.
	 */
	private function utm( $url, $content, $medium = 'link' ) {
		$sep = ( false === strpos( $url, '?' ) ) ? '?' : '&';
		return $url . $sep . http_build_query( array(
			'utm_source'   => 'wpadmin',
			'utm_medium'   => $medium,
			'utm_campaign' => 'plugin_admin',
			'utm_content'  => $content,
		) );
	}

	/**
	 * Check if site is connected to WPVibe.
	 * Connected = received an authenticated WPVibe request within the last 30 days.
	 */
	private function is_connected() {
		return WPVibe_White_Label::site_is_connected();
	}

	/**
	 * Render the admin page.
	 */
	public function render_page() {
		$auth_record   = WPVibe_Connection_Status::current_observation();
		$auth_state    = $auth_record ? $auth_record['state'] : 'not_checked';
		$authorized    = in_array( $auth_state, array( 'verified', 'limited' ), true );
		$ai_read       = WPVibe_Connection_Status::current_ai_read();
		$connected     = $this->is_connected();
		$check_result  = WPVibe_Connection_Check::last_result();
		$legacy_live   = ! $authorized && 0 !== strpos( $auth_state, 'failing:' ) && ! WPVibe_Op_Proof::awaiting_confirmation() && $connected;
		$last_active   = (int) get_option( 'wpvibe_last_active', 0 );
		// Approval itself makes an authenticated read; only activity after that moment shows the AI is using the site.
		$active        = $connected && 0 !== strpos( $auth_state, 'failing:' ) && ( ! $auth_record || $last_active > (int) floor( $auth_record['observed_at'] / 1000 ) + 60 );
		$check_state   = $check_result ? $check_result['state'] : ( $authorized || $legacy_live || WPVibe_Op_Proof::awaiting_confirmation() ? '' : 'current' );
		$site_url      = site_url();
		$https         = 'https' === wp_parse_url( $site_url, PHP_URL_SCHEME );
		$app_pw_ok     = WPVibe_App_Password_Policy::available_for_wpvibe( wp_get_current_user() );
		$app_pw_block  = $app_pw_ok ? null : WPVibe_App_Password_Policy::blocker();
		$app_pw_allow  = WPVibe_App_Password_Policy::enabled();
		$mcp_url       = 'https://mcp.wpvibe.ai/mcp';
		$connect_cta   = $this->utm( 'https://wpvibe.ai/docs/ai-client-setup/', 'cta_connect', 'cta' );
		$chatgpt_app   = WPVibe_Dashboard_Widget::CHATGPT_APP_URL;
		$footer_home   = $this->utm( 'https://wpvibe.ai/', 'footer_home' );
		$footer_docs   = $this->utm( 'https://wpvibe.ai/docs/', 'footer_docs' );
		$footer_supp   = $this->utm( 'https://wpvibe.ai/support/', 'footer_support' );
		$footer_security = $this->utm( 'https://wpvibe.ai/security/', 'footer_security' );
		$footer_dpa    = $this->utm( 'https://wpvibe.ai/dpa/', 'footer_dpa' );
		$ai_prompt     = sprintf( __( 'Connect my site at %s', 'vibe-ai' ), $site_url );
		$verify_prompt = sprintf( __( 'Use WPVibe to read my site at %s (the site_info tool). Tell me the site name and address it returns. If it fails, show me the error and what to do next. Do not change anything.', 'vibe-ai' ), $site_url );
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
				<div class="wpvibe-status <?php echo 'verified' === $auth_state || $legacy_live ? 'wpvibe-status--connected' : ( 0 === strpos( $auth_state, 'failing:' ) ? 'wpvibe-status--disconnected' : ( 'limited' === $auth_state ? 'wpvibe-status--limited' : 'wpvibe-status--neutral' ) ); ?>">
					<span class="wpvibe-status-dot"></span>
					<?php
					echo esc_html( WPVibe_Connection_Status::badge() );
					?>
				</div>

				<!-- Headline -->
				<h1 class="wpvibe-headline">
					<?php esc_html_e( 'Your AI just learned WordPress.', 'vibe-ai' ); ?>
				</h1>
				<p class="wpvibe-subheadline">
					<?php esc_html_e( 'Connect this site to WPVibe to manage content, edit themes, and build pages using AI assistants like Claude, ChatGPT, and Cursor.', 'vibe-ai' ); ?>
				</p>

				<?php if ( ! $https ) : ?>
				<div class="wpvibe-https-notice" role="alert">
					<strong><?php esc_html_e( 'This site needs HTTPS before it can connect.', 'vibe-ai' ); ?></strong>
					<p><?php echo esc_html( sprintf( __( 'Your WordPress Address is %s. WPVibe only connects to https:// sites, so it can never send your login over an unencrypted link. Ask your host to turn on HTTPS, update the WordPress Address and Site Address in Settings > General to https://, then come back to this page.', 'vibe-ai' ), $site_url ) ); ?></p>
				</div>
				<?php endif; ?>

				<!-- Steps -->
				<div class="wpvibe-steps">
					<div class="wpvibe-step wpvibe-step--done">
						<div class="wpvibe-step-num">&#10003;</div>
						<div class="wpvibe-step-content">
							<strong><?php esc_html_e( 'Install the WPVibe plugin', 'vibe-ai' ); ?></strong>
							<span><?php esc_html_e( 'You\'re here, plugin is active.', 'vibe-ai' ); ?></span>
						</div>
					</div>
					<div id="wpvibe-step-2" class="wpvibe-step<?php echo $check_state ? ' wpvibe-step--' . esc_attr( $check_state ) : ''; ?>">
						<div class="wpvibe-step-num" id="wpvibe-step-number-2"><?php echo 'done' === $check_state ? '&#10003;' : '2'; ?></div>
						<div class="wpvibe-step-content" id="wpvibe-connection-check" data-last-check="<?php echo esc_attr( wp_json_encode( $check_result ) ); ?>" data-cooldown="<?php echo (int) max( 0, (int) get_transient( WPVibe_Connection_Check::COOLDOWN ) - time() ); ?>" data-auth-state="<?php echo esc_attr( $auth_state ); ?>" data-ai-observed-at="<?php echo esc_attr( $ai_read ? $ai_read['observed_at'] : '' ); ?>" data-ai-client="<?php echo esc_attr( $ai_read && ! empty( $ai_read['client'] ) ? $ai_read['client'] : '' ); ?>" data-auth-observed-at="<?php echo esc_attr( $auth_record ? $auth_record['observed_at'] : '' ); ?>" data-site-url="<?php echo esc_attr( $site_url ); ?>" data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php', 'relative' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( WPVibe_Connection_Check::NONCE ) ); ?>">
							<strong><?php esc_html_e( 'Check your connection', 'vibe-ai' ); ?></strong>
							<span><?php echo $authorized || $legacy_live ? esc_html__( 'Optional once connected. Run it if your AI reports a connection problem.', 'vibe-ai' ) : esc_html__( 'Can your site reach WPVibe, and can WPVibe reach your site?', 'vibe-ai' ); ?></span>
							<button type="button" class="wpvibe-btn wpvibe-btn--primary" id="wpvibe-run-check"<?php echo $https ? '' : ' disabled'; ?>><?php esc_html_e( 'Check site connectivity', 'vibe-ai' ); ?></button>
							<small id="wpvibe-check-retry-status" aria-live="polite"></small>
							<small class="wpvibe-step-hint" id="wpvibe-check-privacy-hint"<?php echo $check_result ? ' hidden' : ''; ?>><?php esc_html_e( 'Sends your site address and a temporary verification code to WPVibe. No passwords are sent and no security settings are changed.', 'vibe-ai' ); ?></small>
							<p id="wpvibe-check-progress" role="status" aria-live="polite"></p>
							<?php if ( $https && ( ! $app_pw_ok || $app_pw_allow ) ) : ?>
							<div class="wpvibe-fix-card<?php echo $app_pw_ok ? ' wpvibe-fix-card--ok' : ''; ?>">
								<?php if ( ! $app_pw_ok && ! is_ssl() ) : ?>
								<strong><?php esc_html_e( 'WordPress does not see this site as HTTPS.', 'vibe-ai' ); ?></strong>
								<p><?php esc_html_e( 'Your address uses https://, but a proxy or CDN terminates SSL before WordPress, so WordPress turns Application Passwords off. Add this line to wp-config.php above the "That\'s all, stop editing" comment, then run the check again:', 'vibe-ai' ); ?></p>
								<div class="wpvibe-copy-row">
									<code class="wpvibe-copy-text">if ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) &amp;&amp; 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO'] ) { $_SERVER['HTTPS'] = 'on'; }</code>
									<button type="button" class="wpvibe-copy-btn" data-wpvibe-copy="if ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) &amp;&amp; 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO'] ) { $_SERVER['HTTPS'] = 'on'; }"><?php esc_html_e( 'Copy', 'vibe-ai' ); ?></button>
								</div>
								<?php elseif ( ! $app_pw_ok ) : ?>
								<strong><?php echo $app_pw_block ? esc_html( sprintf( __( 'Application Passwords are turned off by %s.', 'vibe-ai' ), $app_pw_block['name'] ) ) : esc_html__( 'Application Passwords are turned off on this site.', 'vibe-ai' ); ?></strong>
								<p><?php echo $app_pw_block ? esc_html( sprintf( __( 'WPVibe connects with a WordPress Application Password. Turn that off in %1$s (%2$s), or allow Application Passwords for WPVibe only: approving a WPVibe connection, and requests that identify as WPVibe from accounts that authorized one. Everything else stays under your security plugin\'s policy.', 'vibe-ai' ), $app_pw_block['name'], isset( $app_pw_block['setting'] ) ? $app_pw_block['setting'] : __( 'its settings', 'vibe-ai' ) ) ) : esc_html__( 'WPVibe connects with a WordPress Application Password. Allow them for WPVibe only: requests that identify as WPVibe, from accounts that authorized WPVibe. Everything else stays under your security settings.', 'vibe-ai' ); ?></p>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wpvibe-inline-form">
									<input type="hidden" name="action" value="wpvibe_app_passwords" />
									<?php wp_nonce_field( 'wpvibe_app_passwords' ); ?>
									<button type="submit" class="wpvibe-btn wpvibe-btn--primary wpvibe-btn--compact"><?php esc_html_e( 'Allow for WPVibe', 'vibe-ai' ); ?></button>
								</form>
								<?php else : ?>
								<strong><?php esc_html_e( 'Application Passwords are allowed for WPVibe requests.', 'vibe-ai' ); ?></strong>
								<p><?php esc_html_e( 'Your security plugin still controls every other request. Turn this off if you no longer use WPVibe on this site.', 'vibe-ai' ); ?></p>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wpvibe-inline-form">
									<input type="hidden" name="action" value="wpvibe_app_passwords" />
									<input type="hidden" name="disable" value="1" />
									<?php wp_nonce_field( 'wpvibe_app_passwords' ); ?>
									<button type="submit" class="wpvibe-btn wpvibe-btn--secondary"><?php esc_html_e( 'Stop allowing for WPVibe', 'vibe-ai' ); ?></button>
								</form>
								<?php endif; ?>
							</div>
							<?php endif; ?>
							<div id="wpvibe-connection-overview"></div>
							<div id="wpvibe-check-results"></div>
							<div id="wpvibe-check-report-actions" hidden>
								<button type="button" class="wpvibe-copy-btn" id="wpvibe-copy-report"><?php esc_html_e( 'Copy report to clipboard', 'vibe-ai' ); ?></button>
								<button type="button" class="wpvibe-copy-btn" id="wpvibe-download-report"><?php esc_html_e( 'Download report', 'vibe-ai' ); ?></button>
							</div>

						</div>
					</div>
					<?php
					$failing_auth     = 0 === strpos( $auth_state, 'failing:' );
					$awaiting         = ! $authorized && ! $failing_auth && WPVibe_Op_Proof::awaiting_confirmation();
					$legacy_connected = ! $authorized && ! $failing_auth && ! $awaiting && $connected;
					$setup_collapsed  = $authorized || $failing_auth || $legacy_connected || $awaiting;
					?>
					<div id="wpvibe-step-3" class="wpvibe-step<?php echo 'verified' === $auth_state || $legacy_connected ? ' wpvibe-step--done' : ( 'limited' === $auth_state || $failing_auth ? ' wpvibe-step--attention' : '' ); ?>" data-awaiting="<?php echo $awaiting ? '1' : '0'; ?>" data-legacy="<?php echo $legacy_connected ? '1' : '0'; ?>" data-active="<?php echo $active ? '1' : '0'; ?>">
						<div class="wpvibe-step-num" id="wpvibe-step-number-3"><?php echo 'verified' === $auth_state || $legacy_connected ? '&#10003;' : '3'; ?></div>
						<div class="wpvibe-step-content">
							<strong id="wpvibe-step-title-3"><?php
							if ( 'verified' === $auth_state ) {
								esc_html_e( 'Site connected', 'vibe-ai' );
							} elseif ( 'limited' === $auth_state ) {
								esc_html_e( 'Site connected with limited permissions', 'vibe-ai' );
							} elseif ( $failing_auth ) {
								esc_html_e( 'WordPress access needs attention', 'vibe-ai' );
							} elseif ( $awaiting ) {
								esc_html_e( 'Approved, waiting for confirmation', 'vibe-ai' );
							} elseif ( $legacy_connected ) {
								esc_html_e( 'Site connected', 'vibe-ai' );
							} else {
								esc_html_e( 'Add WPVibe to your AI and connect this site', 'vibe-ai' );
							}
							?></strong>
							<div id="wpvibe-authorization-status"></div>
							<?php if ( $awaiting ) : ?>
							<span><?php esc_html_e( 'WordPress approved the connection, but WPVibe could not confirm access in time. Run Step 4; a successful read confirms it and turns both steps green.', 'vibe-ai' ); ?></span>
							<?php elseif ( $legacy_connected ) : ?>
							<span><?php echo esc_html( sprintf( __( 'Your AI used this site %s ago. No reconnect is needed unless your AI reports an error.', 'vibe-ai' ), human_time_diff( $last_active ) ) ); ?></span>
							<?php elseif ( $failing_auth ) : ?>
							<span><?php esc_html_e( 'Your saved connection is kept. Use Check authorized access to see what failed and the next step.', 'vibe-ai' ); ?></span>
							<?php endif; ?>
							<?php if ( $setup_collapsed ) : ?>
							<p class="wpvibe-troubleshooting-link">
								<a class="wpvibe-btn wpvibe-btn--secondary" href="<?php echo esc_url( WPVibe_Connection_Status::check_url() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Check authorized access', 'vibe-ai' ); ?></a>
								<br><small><?php esc_html_e( 'Tests whether WPVibe can still reach this site with the access you approved. Requires WPVibe sign-in.', 'vibe-ai' ); ?></small>
							</p>
							<details class="wpvibe-check-details">
								<summary><?php esc_html_e( 'Reconnect or connect from another AI client', 'vibe-ai' ); ?></summary>
							<?php endif; ?>
							<span class="wpvibe-step-label wpvibe-step-label--first"><strong><?php esc_html_e( 'A.', 'vibe-ai' ); ?></strong> <?php esc_html_e( 'Add WPVibe to your AI. Already have it? Skip to B.', 'vibe-ai' ); ?></span>
							<div class="wpvibe-client-links">
								<a class="wpvibe-btn wpvibe-btn--secondary" href="<?php echo esc_url( $chatgpt_app ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Add to ChatGPT', 'vibe-ai' ); ?></a>
								<a class="wpvibe-btn wpvibe-btn--secondary" href="<?php echo esc_url( $connect_cta ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Claude setup guide', 'vibe-ai' ); ?></a>
								<a class="wpvibe-btn wpvibe-btn--secondary" href="<?php echo esc_url( $connect_cta ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Cursor and other clients', 'vibe-ai' ); ?></a>
							</div>
							<span class="wpvibe-step-hint"><?php esc_html_e( 'WPVibe has an official ChatGPT app. Other clients add it as a custom connector with the address below. Sign in with your WPVibe account when the client asks.', 'vibe-ai' ); ?></span>
							<span class="wpvibe-step-label"><?php esc_html_e( 'MCP server address:', 'vibe-ai' ); ?></span>
							<div class="wpvibe-copy-row">
								<code class="wpvibe-copy-text"><?php echo esc_html( $mcp_url ); ?></code>
								<button type="button" class="wpvibe-copy-btn" data-wpvibe-copy="<?php echo esc_attr( $mcp_url ); ?>"><?php esc_html_e( 'Copy', 'vibe-ai' ); ?></button>
							</div>
							<?php if ( $https ) : ?>
							<span class="wpvibe-step-label wpvibe-step-label--part"><strong><?php esc_html_e( 'B.', 'vibe-ai' ); ?></strong> <?php esc_html_e( 'Paste this into your AI to connect this site.', 'vibe-ai' ); ?></span>
							<div class="wpvibe-copy-row">
								<code class="wpvibe-copy-text"><?php echo esc_html( $ai_prompt ); ?></code>
								<button type="button" class="wpvibe-copy-btn" data-wpvibe-copy="<?php echo esc_attr( $ai_prompt ); ?>" data-wpvibe-watch="auth"><?php esc_html_e( 'Copy', 'vibe-ai' ); ?></button>
							</div>
							<span class="wpvibe-step-hint" id="wpvibe-watch-auth"><?php esc_html_e( 'Your AI replies with a link. Open it and approve access while logged in to this site. This step turns green once you approve.', 'vibe-ai' ); ?></span>
							<?php else : ?>
							<span><?php esc_html_e( 'Connecting is available once this site uses HTTPS. See the notice above.', 'vibe-ai' ); ?></span>
							<?php endif; ?>
							<?php if ( ! $setup_collapsed ) : ?>
							<?php if ( WPVibe_Connection_Status::has_connection_history() ) : ?>
							<p class="wpvibe-troubleshooting-link">
								<?php esc_html_e( 'Already authorized but having trouble?', 'vibe-ai' ); ?>
								<br><a class="wpvibe-btn wpvibe-btn--secondary" href="<?php echo esc_url( WPVibe_Connection_Status::check_url() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Check authorized access', 'vibe-ai' ); ?></a>
							</p>
							<?php endif; ?>
							<?php else : ?>
							</details>
							<?php endif; ?>
						</div>
					</div>
					<?php $step4_done = $ai_read || $active; ?>
					<div id="wpvibe-step-4" class="wpvibe-step<?php echo $step4_done ? ' wpvibe-step--done' : ( $authorized || $awaiting ? ' wpvibe-step--current' : '' ); ?>"<?php echo ( $authorized || $awaiting ) && ! $step4_done ? ' aria-current="step"' : ''; ?>>
						<div class="wpvibe-step-num" id="wpvibe-step-number-4"><?php echo $step4_done ? '&#10003;' : '4'; ?></div>
						<div class="wpvibe-step-content">
							<strong id="wpvibe-step-title-4"><?php echo $step4_done ? esc_html__( 'Your AI has used this site', 'vibe-ai' ) : esc_html__( 'Confirm your AI can read this site', 'vibe-ai' ); ?></strong>
							<?php if ( $ai_read ) : ?>
							<span><?php echo esc_html( sprintf( __( 'Confirmed at %s UTC.', 'vibe-ai' ), gmdate( 'Y-m-d H:i:s', (int) floor( $ai_read['observed_at'] / 1000 ) ) ) ); ?><?php if ( ! empty( $ai_read['client'] ) ) : ?> <?php echo esc_html( sprintf( __( 'Client: %s.', 'vibe-ai' ), $ai_read['client'] ) ); ?><?php endif; ?></span>
							<?php elseif ( $step4_done ) : ?>
							<span><?php echo esc_html( sprintf( __( 'Your AI used this site %s ago.', 'vibe-ai' ), human_time_diff( $last_active ) ) ); ?></span>
							<?php endif; ?>
							<?php if ( $step4_done ) : ?>
							<details class="wpvibe-check-details">
								<summary><?php esc_html_e( 'Test another AI client', 'vibe-ai' ); ?></summary>
							<?php endif; ?>
							<span><?php esc_html_e( 'Paste this into your AI. This step turns green when your AI answers with your site name and address.', 'vibe-ai' ); ?></span>
							<div class="wpvibe-copy-row">
								<code class="wpvibe-copy-text"><?php echo esc_html( $verify_prompt ); ?></code>
								<button type="button" class="wpvibe-copy-btn" data-wpvibe-copy="<?php echo esc_attr( $verify_prompt ); ?>" data-wpvibe-watch="ai"><?php esc_html_e( 'Copy', 'vibe-ai' ); ?></button>
							</div>
							<span class="wpvibe-step-hint" id="wpvibe-watch-ai"></span>
							<?php if ( $step4_done ) : ?>
							</details>
							<?php else : ?>
							<span class="wpvibe-step-hint"><?php esc_html_e( 'If your AI says it has no WPVibe tool, go back to Step 3. If the tool returns an error, follow its next step, then run Step 2 again. Still stuck? Send the error and the Step 2 report to support@wpvibe.ai.', 'vibe-ai' ); ?></span>
							<?php endif; ?>
						</div>
					</div>
				</div>

				<!-- White label -->
				<?php if ( $connected || $authorized ) : ?>
				<div class="wpvibe-white-label">
					<strong><?php esc_html_e( 'White label', 'vibe-ai' ); ?></strong>
					<p><?php esc_html_e( 'Hide WPVibe everywhere in this WordPress dashboard: the admin menu, dashboard widget, Plugins list entry, and editor sidebar. For agencies managing this site for a client. The site stays connected and fully manageable through your AI.', 'vibe-ai' ); ?></p>
					<p class="wpvibe-white-label-warning"><?php esc_html_e( 'Once hidden, this page is gone too. To bring WPVibe back, ask your AI to disable white label mode (or delete the wpvibe_hide_from_admins option via WP-CLI). If the site goes 30 days without WPVibe activity, the plugin reappears on its own. WordPress auto-updates are turned on for WPVibe so it stays current while hidden.', 'vibe-ai' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="wpvibe_white_label" />
						<input type="hidden" name="enable" value="1" />
						<?php wp_nonce_field( 'wpvibe_white_label' ); ?>
							<button type="submit" class="wpvibe-btn wpvibe-btn--secondary" onclick="return confirm( '<?php echo esc_js( __( 'Hide WPVibe from this WordPress dashboard for all users?', 'vibe-ai' ) ); ?>' );">
								<?php esc_html_e( 'Hide WPVibe from wp-admin', 'vibe-ai' ); ?>
							</button>
					</form>
				</div>
				<?php endif; ?>

				<?php if ( $connected ) : ?>
					<?php echo WPVibe_Uninstall_Notice::settings_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped piecewise in settings_html(). ?>
				<?php endif; ?>

				<!-- Footer links -->
				<div class="wpvibe-footer">
					<a href="<?php echo esc_url( $footer_home ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'wpvibe.ai', 'vibe-ai' ); ?></a>
					<span class="wpvibe-footer-sep">&middot;</span>
					<a href="<?php echo esc_url( $footer_docs ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Documentation', 'vibe-ai' ); ?></a>
					<span class="wpvibe-footer-sep">&middot;</span>
					<a href="<?php echo esc_url( $footer_supp ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Support', 'vibe-ai' ); ?></a>
					<span class="wpvibe-footer-sep">&middot;</span>
					<a href="<?php echo esc_url( $footer_security ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Security', 'vibe-ai' ); ?></a>
					<span class="wpvibe-footer-sep">&middot;</span>
					<a href="<?php echo esc_url( $footer_dpa ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'DPA', 'vibe-ai' ); ?></a>
				</div>

			</div>
		</div>
		<?php
	}

	/**
	 * Render the Approval Log page.
	 *
	 * Lists every destructive operation WPVibe has actually executed on this
	 * site (post-approval). Append-only by design — there's no delete/edit UI.
	 * Drill-down shows the dry-run preview the user saw before approving, plus
	 * the post-execution result summary.
	 */
	public function render_activity_page() {
		// Defense-in-depth: WP core already enforces the menu cap before serving
		// this page, but a direct check inside the callback prevents future
		// refactors / hooks from accidentally exposing the data.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'vibe-ai' ), 403 );
		}

		$entry_id = isset( $_GET['entry'] ) ? (int) $_GET['entry'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $entry_id > 0 ) {
			$this->render_activity_detail( $entry_id );
			return;
		}

		$page    = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per     = 50;
		$offset  = ( $page - 1 ) * $per;
		$total   = WPVibe_Audit_Log::count();
		$entries = WPVibe_Audit_Log::get_recent( $per, $offset );
		$pages   = max( 1, (int) ceil( $total / $per ) );
		$base    = admin_url( 'admin.php?page=vibe-ai-activity' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Approval Log', 'vibe-ai' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Every destructive operation WPVibe has executed on this site after your explicit approval. Append-only — entries cannot be modified or deleted from the dashboard.', 'vibe-ai' ); ?>
			</p>

			<?php if ( empty( $entries ) ) : ?>
				<div class="notice notice-info" style="margin-top:1em;">
					<p><?php esc_html_e( 'No destructive operations have been executed yet.', 'vibe-ai' ); ?></p>
				</div>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped" style="margin-top:1em;">
					<thead>
						<tr>
							<th style="width:160px;"><?php esc_html_e( 'When', 'vibe-ai' ); ?></th>
							<th style="width:140px;"><?php esc_html_e( 'User', 'vibe-ai' ); ?></th>
							<th style="width:200px;"><?php esc_html_e( 'Operation', 'vibe-ai' ); ?></th>
							<th><?php esc_html_e( 'Command', 'vibe-ai' ); ?></th>
							<th><?php esc_html_e( 'Result', 'vibe-ai' ); ?></th>
							<th style="width:60px;"></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $entries as $row ) :
							$user_obj   = $row->user_id ? get_user_by( 'id', (int) $row->user_id ) : null;
							$detail_url = add_query_arg( 'entry', (int) $row->id, $base );
							?>
							<tr>
								<td><?php echo esc_html( mysql2date( 'Y-m-d H:i', $row->created_at ) ); ?></td>
								<td><?php echo $user_obj ? esc_html( $user_obj->user_login ) : esc_html__( '(unknown)', 'vibe-ai' ); ?></td>
								<td><code><?php echo esc_html( $row->operation ); ?></code></td>
								<td><code><?php echo esc_html( $row->command ); ?></code></td>
								<td><?php echo esc_html( $row->result_summary ?: '' ); ?></td>
								<td>
									<a href="<?php echo esc_url( $detail_url ); ?>" class="button button-small">
										<?php esc_html_e( 'View', 'vibe-ai' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php if ( $pages > 1 ) : ?>
					<div class="tablenav" style="margin-top:1em;">
						<div class="tablenav-pages">
							<?php
							echo paginate_links( array(
								'base'    => add_query_arg( 'paged', '%#%', $base ),
								'format'  => '',
								'current' => $page,
								'total'   => $pages,
							) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							?>
						</div>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_activity_detail( $id ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'vibe-ai' ), 403 );
		}

		$entry = WPVibe_Audit_Log::get_by_id( $id );
		if ( ! $entry ) {
			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'Approval Log', 'vibe-ai' ); ?></h1>
				<div class="notice notice-error"><p><?php esc_html_e( 'Entry not found.', 'vibe-ai' ); ?></p></div>
				<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=vibe-ai-activity' ) ); ?>">&larr; <?php esc_html_e( 'Back to Approval Log', 'vibe-ai' ); ?></a></p>
			</div>
			<?php
			return;
		}

		$user_obj = $entry->user_id ? get_user_by( 'id', (int) $entry->user_id ) : null;
		$params   = $entry->params_json ? json_decode( $entry->params_json, true ) : null;
		$dry_run  = $entry->dry_run_json ? json_decode( $entry->dry_run_json, true ) : null;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Approval Log Entry', 'vibe-ai' ); ?></h1>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=vibe-ai-activity' ) ); ?>">&larr; <?php esc_html_e( 'Back to Approval Log', 'vibe-ai' ); ?></a></p>

			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'When', 'vibe-ai' ); ?></th>
					<td><?php echo esc_html( $entry->created_at ); ?> UTC</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'User', 'vibe-ai' ); ?></th>
					<td><?php echo $user_obj ? esc_html( $user_obj->user_login . ' (' . $user_obj->user_email . ')' ) : esc_html__( '(unknown)', 'vibe-ai' ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Operation', 'vibe-ai' ); ?></th>
					<td><code><?php echo esc_html( $entry->operation ); ?></code></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Command', 'vibe-ai' ); ?></th>
					<td><code><?php echo esc_html( $entry->command ); ?></code></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Result', 'vibe-ai' ); ?></th>
					<td><?php echo esc_html( $entry->result_summary ?: '' ); ?></td>
				</tr>
				<?php if ( $dry_run ) : ?>
					<tr>
						<th><?php esc_html_e( 'Dry-run preview (what the user saw)', 'vibe-ai' ); ?></th>
						<td><pre style="background:#f6f7f7;padding:1em;overflow:auto;"><?php echo esc_html( wp_json_encode( $dry_run, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></pre></td>
					</tr>
				<?php endif; ?>
				<?php if ( $params ) : ?>
					<tr>
						<th><?php esc_html_e( 'Params', 'vibe-ai' ); ?></th>
						<td><pre style="background:#f6f7f7;padding:1em;overflow:auto;"><?php echo esc_html( wp_json_encode( $params, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></pre></td>
					</tr>
				<?php endif; ?>
			</table>
		</div>
		<?php
	}
}
