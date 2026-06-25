<?php
/**
 * WP Admin notice asking active users for a WordPress.org review.
 *
 * Surfaces on every wp-admin page once the site has been:
 *   - installed for at least WPVIBE_REVIEW_DAYS_THRESHOLD days, AND
 *   - actually used (wpvibe_last_active is set, written by the MCP on every
 *     authenticated request)
 *
 * Dismissal is sticky:
 *   - "Leave a review" / "Already did" → permanently dismissed
 *   - "Maybe later"                    → snoozed for WPVIBE_REVIEW_SNOOZE_DAYS
 *
 * MCP-side nudge is intentionally NOT removed — it may still catch users who
 * never visit wp-admin. This is the reliable surface; the MCP one is bonus.
 *
 * @package WPVibe
 */

defined( 'ABSPATH' ) || exit;

class WPVibe_Review_Notice {

	const DAYS_THRESHOLD = 7;
	const SNOOZE_DAYS    = 30;
	const REVIEW_URL     = 'https://wordpress.org/support/plugin/vibe-ai/reviews/#new-post';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_init', array( $this, 'maybe_seed_install_timestamp' ) );
		add_action( 'admin_notices', array( $this, 'maybe_render_notice' ) );
		add_action( 'wp_ajax_wpvibe_dismiss_review', array( $this, 'ajax_dismiss' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Set wpvibe_review_eligible_since on first admin page-load after upgrading
	 * to a version that includes the review notice. The countdown to the review
	 * prompt always starts FROM THIS VERSION — upgraders never see the notice
	 * immediately after updating, even if they've been WPVibe users for months.
	 *
	 * Also cleans up the legacy `wpvibe_installed_at` option name from early
	 * dev versions.
	 */
	public function maybe_seed_install_timestamp() {
		if ( ! get_option( 'wpvibe_review_eligible_since' ) ) {
			add_option( 'wpvibe_review_eligible_since', time() );
			delete_option( 'wpvibe_installed_at' );
		}
	}

	/**
	 * True when we should show the notice on the current admin page.
	 */
	private function should_show() {
		if ( wp_doing_ajax() || is_network_admin() ) {
			return false;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		// Skip on the WPVibe admin page itself — the page uses a negative top
		// margin for a full-bleed design that overlaps standard admin notices,
		// and nagging for a review on top of the product's setup screen is
		// poor UX anyway.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && 'toplevel_page_vibe-ai' === $screen->id ) {
			return false;
		}

		$status = get_option( 'wpvibe_review_notice_status', '' );
		if ( 'dismissed' === $status ) {
			return false;
		}
		if ( is_numeric( $status ) && (int) $status > time() ) {
			// Still in snooze window.
			return false;
		}

		$eligible_since = (int) get_option( 'wpvibe_review_eligible_since', 0 );
		if ( ! $eligible_since || ( time() - $eligible_since ) < self::DAYS_THRESHOLD * DAY_IN_SECONDS ) {
			return false;
		}

		$last_active = (int) get_option( 'wpvibe_last_active', 0 );
		if ( ! $last_active ) {
			// Plugin installed but never actually used via MCP — don't ask.
			return false;
		}

		return true;
	}

	public function maybe_render_notice() {
		if ( ! $this->should_show() ) {
			return;
		}

		$nonce = wp_create_nonce( 'wpvibe_dismiss_review' );
		?>
		<div class="notice notice-info wpvibe-review-notice" data-wpvibe-review-nonce="<?php echo esc_attr( $nonce ); ?>">
			<div class="wpvibe-review-notice__body">
				<div class="wpvibe-review-notice__icon" aria-hidden="true">
					<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#fbbf24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
						<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
					</svg>
				</div>
				<div class="wpvibe-review-notice__copy">
					<p class="wpvibe-review-notice__title"><?php esc_html_e( 'Enjoying WPVibe? Help others find it.', 'vibe-ai' ); ?></p>
					<p class="wpvibe-review-notice__text"><?php esc_html_e( 'You\'ve been using WPVibe for over a week. If it\'s saving you time, a quick 5-star review on WordPress.org would mean a lot and helps other folks discover it.', 'vibe-ai' ); ?></p>
					<p class="wpvibe-review-notice__actions">
						<a href="<?php echo esc_url( self::REVIEW_URL ); ?>" target="_blank" rel="noopener" class="button button-primary wpvibe-review-action" data-wpvibe-review-action="dismissed"><?php esc_html_e( 'Leave a review', 'vibe-ai' ); ?></a>
						<button type="button" class="button button-secondary wpvibe-review-action" data-wpvibe-review-action="dismissed"><?php esc_html_e( 'I already did', 'vibe-ai' ); ?></button>
						<button type="button" class="button-link wpvibe-review-action" data-wpvibe-review-action="snoozed"><?php esc_html_e( 'Maybe later', 'vibe-ai' ); ?></button>
					</p>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX handler for the three dismiss actions.
	 *
	 * Expected params:
	 *   action     = wpvibe_dismiss_review
	 *   _wpnonce   = nonce from data-wpvibe-review-nonce
	 *   status     = 'dismissed' | 'snoozed'
	 */
	public function ajax_dismiss() {
		check_ajax_referer( 'wpvibe_dismiss_review' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}

		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';

		if ( 'dismissed' === $status ) {
			update_option( 'wpvibe_review_notice_status', 'dismissed', false );
		} elseif ( 'snoozed' === $status ) {
			$snooze_until = time() + self::SNOOZE_DAYS * DAY_IN_SECONDS;
			update_option( 'wpvibe_review_notice_status', (string) $snooze_until, false );
		} else {
			wp_send_json_error( array( 'message' => 'Invalid status' ), 400 );
		}

		wp_send_json_success();
	}

	public function enqueue_assets( $hook ) {
		// Load the tiny dismiss script on every admin page since the notice can appear anywhere.
		if ( ! $this->should_show() ) {
			return;
		}

		$css_path = WPVIBE_PLUGIN_DIR . 'assets/css/review-notice.css';
		$js_path  = WPVIBE_PLUGIN_DIR . 'assets/js/review-notice.js';
		$css_ver  = file_exists( $css_path ) ? (string) filemtime( $css_path ) : WPVIBE_VERSION;
		$js_ver   = file_exists( $js_path ) ? (string) filemtime( $js_path ) : WPVIBE_VERSION;

		wp_enqueue_style(
			'vibe-ai-review-notice',
			WPVIBE_PLUGIN_URL . 'assets/css/review-notice.css',
			array(),
			$css_ver
		);

		wp_enqueue_script(
			'vibe-ai-review-notice',
			WPVIBE_PLUGIN_URL . 'assets/js/review-notice.js',
			array(),
			$js_ver,
			true
		);

		wp_localize_script( 'vibe-ai-review-notice', 'wpvibeReviewNotice', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		) );
	}
}
