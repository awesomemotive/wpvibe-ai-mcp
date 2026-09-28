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
 *
 * On wp-admin the cookie only applies to the screens the field API needs
 * (see ADMIN_SCREENS). Everywhere else in wp-admin runs the live theme, so a
 * broken draft functions.php can never lock the admin out of Plugins, Themes
 * or the dashboard (#604).
 */

defined( 'ABSPATH' ) || exit;

class WPVibe_Preview {

	const COOKIE_NAME = 'wpvibe_preview';
	const STOP_PARAM  = 'wpvibe_stop_preview';

	/**
	 * wp-admin screens where the cookie still swaps in the draft theme: the
	 * post editor (meta boxes render, save_post persists draft-defined
	 * fields) and the WPVibe settings page plus its options.php save.
	 */
	const ADMIN_SCREENS = array( 'post.php', 'post-new.php', 'options-general.php', 'options.php' );

	private static $instance = null;
	private $preview_token   = null;

	// get_preview_slug() runs on every template/stylesheet filter call. A host
	// hook that reads the theme while we check the capability would re-enter
	// it (#604), so it resolves once per request and bails while resolving.
	private static $resolving = false;
	private $resolved         = null;

	// The template/stylesheet filters fire many times per request; resolve the
	// parent once instead of re-reading style.css on each.
	private $template_slug_cache = array();

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
	 * The cookie path requires an authenticated admin with edit_themes, so a
	 * stolen cookie alone is inert. The token is checked first: a stale or
	 * forged cookie never reaches current_user_can().
	 *
	 * @return string|false Draft theme slug or false.
	 */
	private function get_preview_slug() {
		if ( self::$resolving ) {
			return false; // Re-entered from inside our own capability check.
		}
		if ( is_admin() && ! in_array( self::admin_screen(), self::ADMIN_SCREENS, true ) ) {
			return false;
		}
		// End Preview runs on the live theme, so it works even when the
		// draft's functions.php fatals.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET[ self::STOP_PARAM ] ) ) {
			return false;
		}

		$from_cookie = false;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Verified via hash_equals below.
		if ( ! empty( $_GET[ self::COOKIE_NAME ] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$input = sanitize_text_field( wp_unslash( $_GET[ self::COOKIE_NAME ] ) );
		} elseif ( ! empty( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			$input       = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) );
			$from_cookie = true;
		} else {
			return false;
		}

		$key = ( $from_cookie ? 'cookie:' : 'query:' ) . $input;
		if ( null !== $this->resolved && $this->resolved['key'] === $key ) {
			return $this->resolved['slug'];
		}

		self::$resolving = true;
		try {
			$slug = $this->resolve_preview_slug( $input, $from_cookie );
		} finally {
			self::$resolving = false;
		}
		$this->resolved = array(
			'key'  => $key,
			'slug' => $slug,
		);
		return $slug;
	}

	/**
	 * @param string $input       Token from the query string or cookie.
	 * @param bool   $from_cookie Whether $input came from the cookie.
	 * @return string|false
	 */
	private function resolve_preview_slug( $input, $from_cookie ) {
		$token  = get_option( 'wpvibe_preview_token' );
		$issued = (int) get_option( 'wpvibe_preview_token_issued', 0 );
		// Tokens expire 24h after issue. Anyone who learns the URL can't use it forever.
		$valid = $token && hash_equals( (string) $token, $input )
			&& ! ( $issued > 0 && ( time() - $issued ) > DAY_IN_SECONDS );
		if ( ! $valid ) {
			if ( $from_cookie ) {
				self::clear_cookie(); // Dead cookie; stop the browser sending it.
			}
			return false;
		}

		$draft_slug = get_option( 'wpvibe_draft_theme' );
		if ( ! $draft_slug || ! is_dir( get_theme_root() . '/' . $draft_slug ) ) {
			return false;
		}

		if ( $from_cookie && ! current_user_can( 'edit_themes' ) ) {
			return false;
		}

		return $draft_slug;
	}

	/** @return string The wp-admin file being served, e.g. "post.php". */
	private static function admin_screen() {
		return isset( $GLOBALS['pagenow'] ) ? (string) $GLOBALS['pagenow'] : '';
	}

	public function swap_template( $template ) {
		$slug = $this->get_preview_slug();
		if ( ! $slug ) {
			return $template;
		}
		return $this->draft_template_slug( $slug );
	}

	public function swap_stylesheet( $stylesheet ) {
		$slug = $this->get_preview_slug();
		return $slug ? $slug : $stylesheet;
	}

	/**
	 * A draft cloned from a child theme must keep the PARENT as `template`.
	 * Pointing both filters at the draft makes WP treat it as standalone, so
	 * the parent's functions.php never loads and parent templates fatal on the
	 * parent's own helpers. Read via get_file_data rather than wp_get_theme:
	 * this runs inside the `template` filter, which wp_get_theme re-enters.
	 *
	 * @param string $draft_slug Draft theme slug.
	 * @return string Slug to use for `template`.
	 */
	private function draft_template_slug( $draft_slug ) {
		if ( isset( $this->template_slug_cache[ $draft_slug ] ) ) {
			return $this->template_slug_cache[ $draft_slug ];
		}

		$resolved = $draft_slug;
		$style    = get_theme_root( $draft_slug ) . '/' . $draft_slug . '/style.css';

		if ( is_readable( $style ) ) {
			$data   = get_file_data( $style, array( 'Template' => 'Template' ) );
			$parent = isset( $data['Template'] ) ? trim( $data['Template'] ) : '';

			// A theme slug is a single directory name. The draft's style.css is
			// writable through the file tools, so refuse separators outright
			// rather than let a Template: header steer the resolved path.
			$safe = '' !== $parent && $parent === basename( $parent ) && ! preg_match( '#[/\\\\]|\.\.#', $parent );

			// Parent may be registered under a different theme root than the child.
			if ( $safe && $parent !== $draft_slug && is_dir( get_theme_root( $parent ) . '/' . $parent ) ) {
				$resolved = $parent;
			}
		}

		$this->template_slug_cache[ $draft_slug ] = $resolved;
		return $resolved;
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
		$this->resolved               = null;
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
	 * delete, and plugin deactivation paths.
	 */
	public static function clear_cookie() {
		// Forget the cached slug and drop the cookie for the rest of this
		// request even when the Set-Cookie header can no longer be sent.
		if ( null !== self::$instance ) {
			self::$instance->resolved = null;
		}
		unset( $_COOKIE[ self::COOKIE_NAME ] );
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
	}

	/**
	 * Plugin deactivation: expire the cookie in the deactivating browser and
	 * retire the token, so a cookie left in any other browser (or one this
	 * response couldn't reach, e.g. WP-CLI) is rejected on reactivation
	 * before any capability check. get_preview_url mints a fresh token.
	 */
	public static function deactivate() {
		delete_option( 'wpvibe_preview_token' );
		delete_option( 'wpvibe_preview_token_issued' );
		self::clear_cookie();
	}

	/**
	 * Enqueue the Tailwind browser CDN + plugin-served presets.css only while a
	 * Tailwind draft is the active stylesheet. The CDN runtime lets the AI
	 * iterate on a WPVibe theme without a build step; presets.css fills in the
	 * typography + form resets the CDN doesn't ship. The live theme enqueues
	 * its compiled dist/styles.css itself, so neither asset loads outside
	 * draft mode.
	 *
	 * A draft cloned from any other theme (Astra, Divi, a block theme) gets
	 * neither. The runtime turns every class name that happens to match a
	 * Tailwind utility into CSS and adds its preflight reset, so WooCommerce's
	 * `columns-4` wrapper became `columns: 4` and squeezed Astra's product grid
	 * into a quarter of the page (#508).
	 */
	public static function enqueue_draft_assets() {
		$draft = get_option( 'wpvibe_draft_theme' );
		if ( ! $draft || get_stylesheet() !== $draft ) {
			return;
		}
		// A child draft of a WPVibe theme inherits the parent's Tailwind
		// templates; in draft mode get_template() is that resolved parent.
		if ( ! self::is_tailwind_theme( $draft ) && ! self::is_tailwind_theme( get_template() ) ) {
			return;
		}
		wp_enqueue_style(
			'wpvibe-presets',
			WPVIBE_PLUGIN_URL . 'assets/presets.css',
			array(),
			WPVIBE_VERSION
		);
		wp_enqueue_script(
			'wpvibe-tailwind-cdn',
			'https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4',
			array(),
			'4',
			array( 'strategy' => 'defer' )
		);
	}

	/**
	 * A Tailwind theme keeps its design tokens in theme.css at the theme root.
	 * This is the same test the publish step uses to decide whether to compile
	 * dist/styles.css, so the preview and the published theme agree.
	 *
	 * @param string $slug Theme directory name.
	 * @return bool
	 */
	public static function is_tailwind_theme( $slug ) {
		return is_file( get_theme_root( $slug ) . '/' . $slug . '/theme.css' );
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
