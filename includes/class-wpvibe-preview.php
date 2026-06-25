<?php
/**
 * Draft theme preview — swaps the active theme for requests that present a
 * valid wpvibe_preview token, either in the URL or in a cookie.
 *
 * The cookie path is the load-bearing piece: without it, wp-admin URLs
 * (which have no preview query param) load the live theme, which means the
 * draft theme's functions.php — and the field registrations it contains —
 * never run. With the cookie, the draft theme is active everywhere the
 * authenticated admin navigates, so meta boxes for draft-defined fields
 * render correctly and save_post can persist them.
 */

defined( 'ABSPATH' ) || exit;

class WPVibe_Preview {

	const COOKIE_NAME = 'wpvibe_preview';
	const STOP_PARAM  = 'wpvibe_stop_preview';

	private static $instance = null;
	private $preview_token   = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'maybe_stop_preview' ), 1 );
		add_filter( 'template',   array( $this, 'swap_template' ) );
		add_filter( 'stylesheet', array( $this, 'swap_stylesheet' ) );

		// If the request presents a valid token in the URL, pin it to a
		// cookie so subsequent navigation (including wp-admin) stays in
		// preview without depending on every link carrying the token.
		add_action( 'init', array( $this, 'maybe_set_cookie' ), 2 );

		// Frontend-only banner + link rewriter. wp-admin still flips themes
		// via the cookie path; we just don't overlay banner chrome there.
		if ( ! is_admin() && $this->get_preview_slug() ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Token validated via hash_equals in get_preview_slug().
			$this->preview_token = isset( $_GET[ self::COOKIE_NAME ] )
				? sanitize_text_field( wp_unslash( $_GET[ self::COOKIE_NAME ] ) )
				: ( isset( $_COOKIE[ self::COOKIE_NAME ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) ) : '' );
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_preview_assets' ) );
			add_action( 'wp_footer', array( $this, 'render_preview_banner' ), 9999 );
		}
	}

	/**
	 * Resolve the active preview slug from query token OR cookie.
	 *
	 * The cookie path requires an authenticated admin with edit_themes —
	 * a stolen cookie alone is inert.
	 *
	 * @return string|false Draft theme slug or false.
	 */
	private function get_preview_slug() {
		$input = '';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Verified via hash_equals below.
		if ( ! empty( $_GET[ self::COOKIE_NAME ] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$input = sanitize_text_field( wp_unslash( $_GET[ self::COOKIE_NAME ] ) );
		} elseif ( ! empty( $_COOKIE[ self::COOKIE_NAME ] ) && current_user_can( 'edit_themes' ) ) {
			$input = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) );
		}

		if ( '' === $input ) {
			return false;
		}

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
	 * Set the preview cookie when a request arrives with a valid query
	 * token AND the cookie isn't already present (or differs). This pins
	 * draft mode so subsequent navigation stays in preview without every
	 * link carrying the token.
	 */
	public function maybe_set_cookie() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Token validated below.
		if ( empty( $_GET[ self::COOKIE_NAME ] ) ) {
			return;
		}
		// Only pin the cookie for users who could already see the draft via
		// wp-admin. A leaked URL handed to a logged-in low-priv user must not
		// give them persistent draft access — they can still view the single
		// page (token in URL), but the cookie won't follow them around.
		if ( ! current_user_can( 'edit_themes' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$query_token = sanitize_text_field( wp_unslash( $_GET[ self::COOKIE_NAME ] ) );

		$stored = get_option( 'wpvibe_preview_token' );
		if ( ! $stored || ! hash_equals( $stored, $query_token ) ) {
			return;
		}

		$existing = isset( $_COOKIE[ self::COOKIE_NAME ] )
			? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) )
			: '';
		if ( $existing === $query_token ) {
			return;
		}

		if ( headers_sent() ) {
			return; // Cannot set cookie after output started; next request will re-arm.
		}

		setcookie(
			self::COOKIE_NAME,
			$query_token,
			array(
				'expires'  => time() + DAY_IN_SECONDS,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
		// Make the cookie visible to code running later in this same request.
		$_COOKIE[ self::COOKIE_NAME ] = $query_token;
	}

	/**
	 * Handle the explicit "End Preview" action — clear the cookie and
	 * redirect back to the same URL without the stop param so the user
	 * lands on the page they were viewing, now rendered with the live theme.
	 */
	public function maybe_stop_preview() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Idempotent destructive op on session-only cookie.
		if ( empty( $_GET[ self::STOP_PARAM ] ) ) {
			return;
		}
		self::clear_cookie();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotValidated
		$path = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$path = remove_query_arg( array( self::STOP_PARAM, self::COOKIE_NAME ), $path );
		wp_safe_redirect( home_url( $path ) );
		exit;
	}

	/**
	 * Clear the preview cookie. Called by maybe_stop_preview, publish,
	 * and delete paths.
	 */
	public static function clear_cookie() {
		if ( headers_sent() ) {
			return;
		}
		setcookie(
			self::COOKIE_NAME,
			'',
			array(
				'expires'  => time() - HOUR_IN_SECONDS,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
		unset( $_COOKIE[ self::COOKIE_NAME ] );
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
				'param' => self::COOKIE_NAME,
			)
		);
	}

	/**
	 * Render the preview banner HTML in the footer.
	 */
	public function render_preview_banner() {
		$live_url = esc_url( home_url( '/' ) );
		$stop_url = esc_url( add_query_arg( self::STOP_PARAM, '1' ) );
		?>
		<div id="wpvibe-preview-banner">
			<span class="wpvibe-badge">
				<span class="wpvibe-dot"></span>
				<?php esc_html_e( 'WPVibe Draft Preview', 'vibe-ai' ); ?>
			</span>
			<span class="wpvibe-info">
				<?php esc_html_e( 'Changes are only visible to you. The live site is unaffected.', 'vibe-ai' ); ?>
			</span>
			<a href="<?php echo esc_url( $stop_url ); ?>" class="wpvibe-btn wpvibe-btn-stop" data-wpvibe-no-preview><?php esc_html_e( 'End Preview', 'vibe-ai' ); ?></a>
			<a href="<?php echo esc_url( $live_url ); ?>" class="wpvibe-btn wpvibe-btn-live" data-wpvibe-no-preview><?php esc_html_e( 'View Live Site', 'vibe-ai' ); ?></a>
		</div>
		<?php
	}
}
