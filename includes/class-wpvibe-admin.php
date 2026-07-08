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
		$last_active = (int) get_option( 'wpvibe_last_active', 0 );
		return $last_active > 0 && ( time() - $last_active ) < 30 * DAY_IN_SECONDS;
	}

	/**
	 * Render the admin page.
	 */
	public function render_page() {
		$connected     = $this->is_connected();
		$site_url      = site_url();
		$mcp_url       = 'https://mcp.wpvibe.ai/mcp';
		$connect_cta   = $this->utm( 'https://mcp.wpvibe.ai/?connect=' . rawurlencode( $site_url ), 'cta_connect', 'cta' );
		$app_cta       = $this->utm( 'https://wpvibe.ai/app/', 'cta_open_app', 'cta' );
		$footer_home   = $this->utm( 'https://wpvibe.ai/', 'footer_home' );
		$footer_docs   = $this->utm( 'https://wpvibe.ai/docs/', 'footer_docs' );
		$footer_supp   = $this->utm( 'https://wpvibe.ai/support/', 'footer_support' );
		$ai_prompt     = sprintf( __( 'Connect my site at %s', 'vibe-ai' ), $site_url );
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
						<a href="<?php echo esc_url( $app_cta ); ?>" class="wpvibe-btn wpvibe-btn--primary" target="_blank" rel="noopener">
							<?php esc_html_e( 'Open WPVibe', 'vibe-ai' ); ?>
						</a>
					<?php else : ?>
						<a href="<?php echo esc_url( $connect_cta ); ?>" class="wpvibe-btn wpvibe-btn--primary" target="_blank" rel="noopener">
							<?php esc_html_e( 'Get Setup Instructions', 'vibe-ai' ); ?>
						</a>
					<?php endif; ?>
				</div>

				<!-- Steps -->
				<div class="wpvibe-steps">
					<div class="wpvibe-step wpvibe-step--done">
						<div class="wpvibe-step-num">&#10003;</div>
						<div class="wpvibe-step-content">
							<strong><?php esc_html_e( 'Install the WPVibe plugin', 'vibe-ai' ); ?></strong>
							<span><?php esc_html_e( 'You\'re here, plugin is active.', 'vibe-ai' ); ?></span>
						</div>
					</div>
					<div class="wpvibe-step">
						<div class="wpvibe-step-num">2</div>
						<div class="wpvibe-step-content">
							<strong><?php esc_html_e( 'Add the MCP server URL to your AI client', 'vibe-ai' ); ?></strong>
							<span><?php esc_html_e( 'Paste this URL into Claude, ChatGPT, Cursor, or any MCP-compatible AI client:', 'vibe-ai' ); ?></span>
							<div class="wpvibe-copy-row">
								<code class="wpvibe-copy-text"><?php echo esc_html( $mcp_url ); ?></code>
								<button type="button" class="wpvibe-copy-btn" data-wpvibe-copy="<?php echo esc_attr( $mcp_url ); ?>">
									<?php esc_html_e( 'Copy', 'vibe-ai' ); ?>
								</button>
							</div>
							<a href="<?php echo esc_url( $connect_cta ); ?>" target="_blank" rel="noopener" class="wpvibe-inline-link">
								<?php esc_html_e( 'See per-client setup instructions &rarr;', 'vibe-ai' ); ?>
							</a>
						</div>
					</div>
					<div class="wpvibe-step <?php echo $connected ? 'wpvibe-step--done' : ''; ?>">
						<div class="wpvibe-step-num"><?php echo $connected ? '&#10003;' : '3'; ?></div>
						<div class="wpvibe-step-content">
							<strong><?php esc_html_e( 'Tell your AI to connect this site', 'vibe-ai' ); ?></strong>
							<?php if ( $connected ) : ?>
								<span><?php esc_html_e( 'Site is connected and ready.', 'vibe-ai' ); ?></span>
							<?php else : ?>
								<span><?php esc_html_e( 'In your AI chat, paste this prompt:', 'vibe-ai' ); ?></span>
								<div class="wpvibe-copy-row">
									<code class="wpvibe-copy-text"><?php echo esc_html( $ai_prompt ); ?></code>
									<button type="button" class="wpvibe-copy-btn" data-wpvibe-copy="<?php echo esc_attr( $ai_prompt ); ?>">
										<?php esc_html_e( 'Copy', 'vibe-ai' ); ?>
									</button>
								</div>
								<span class="wpvibe-step-hint"><?php esc_html_e( 'Your AI will return a one-click authorization link. Approve it and you\'re connected.', 'vibe-ai' ); ?></span>
							<?php endif; ?>
						</div>
					</div>
				</div>

				<!-- Footer links -->
				<div class="wpvibe-footer">
					<a href="<?php echo esc_url( $footer_home ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'wpvibe.ai', 'vibe-ai' ); ?></a>
					<span class="wpvibe-footer-sep">&middot;</span>
					<a href="<?php echo esc_url( $footer_docs ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Documentation', 'vibe-ai' ); ?></a>
					<span class="wpvibe-footer-sep">&middot;</span>
					<a href="<?php echo esc_url( $footer_supp ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Support', 'vibe-ai' ); ?></a>
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
