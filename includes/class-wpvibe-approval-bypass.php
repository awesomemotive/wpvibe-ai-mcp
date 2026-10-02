<?php
/**
 * "Dangerously bypass approvals": a per-site opt-in that lets WPVibe run
 * operations without an approval prompt. Only an administrator's own wp-admin
 * form, in a cookie-authenticated browser session, can turn it on; every
 * WPVibe path (CLI, SQL, content edits, REST, an application password) is
 * refused a write that would turn it on.
 *
 * @package WPVibe
 */

defined( 'ABSPATH' ) || exit;

class WPVibe_Approval_Bypass {

	const OPTION = 'wpvibe_bypass_approvals';
	const HEADER = 'X-WPVibe-Bypass-Approvals';
	const ACTION = 'wpvibe_bypass_approvals';
	/** The only routes a bypass-claim proof may execute on. */
	const PROOF_ROUTES = array( '/wpvibe/v1/cli/run-approved', '/wpvibe/v1/code-snippet' );
	/** Operations a bypass claim never runs: turning white label on hides the Approval Log, so a human approves it every time. */
	const ALWAYS_ASK = array( 'white_label_enable' );

	private static $instance   = null;
	private static $admin_save = false;
	/** Set once this request's operation proof was accepted on the bypass claim. */
	public static $request_bypassed = false;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'sanitize_option_' . self::OPTION, array( __CLASS__, 'guard_value' ), PHP_INT_MAX, 1 );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'emit_header' ), 999, 1 );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_notice' ) );
	}

	private static function value_on( $value ) {
		return is_array( $value ) && ! empty( $value['enabled'] );
	}

	/** @return true|WP_Error A bypass-claimed run of an ALWAYS_ASK operation is refused like a site with bypass off, so the Worker falls back to an approval. */
	public static function allows( $operation ) {
		if ( ! self::$request_bypassed || ! in_array( (string) $operation, self::ALWAYS_ASK, true ) ) {
			return true;
		}
		return new WP_Error( 'wpvibe_bypass_off', __( 'Not run: turning on white label mode always needs an approval, even with "Dangerously bypass approvals" on. Nothing was changed.', 'vibe-ai' ), array( 'status' => 403 ) );
	}

	public static function is_on() {
		return self::value_on( get_option( self::OPTION, '' ) );
	}

	/** Who turned it on and when, or null while it is off. */
	public static function details() {
		$value = get_option( self::OPTION, '' );
		return self::value_on( $value ) ? $value : null;
	}

	/**
	 * sanitize_option runs inside every update_option/add_option, so this is the
	 * one gate for all PHP writers (REST settings, abilities, other plugins).
	 * Turning it off always passes; turning it on passes only from the admin form.
	 */
	public static function guard_value( $value ) {
		if ( ! self::value_on( $value ) ) {
			return '';
		}
		if ( self::$admin_save ) {
			return $value;
		}
		$current = get_option( self::OPTION, '' );
		return self::value_on( $current ) ? $current : '';
	}

	/** Reported on every authenticated REST answer, so the Worker learns the state from the calls it already makes. */
	public static function emit_header( $response ) {
		if ( is_object( $response ) && method_exists( $response, 'header' ) && is_user_logged_in() ) {
			$response->header( self::HEADER, self::is_on() ? '1' : '0' );
		}
		return $response;
	}

	/** A browser login, never an application password (the AI's own credential). */
	private static function is_cookie_session() {
		if ( did_action( 'application_password_did_authenticate' ) ) {
			return false;
		}
		if ( function_exists( 'rest_get_authenticated_app_password' ) && rest_get_authenticated_app_password() ) {
			return false;
		}
		$uid = function_exists( 'wp_validate_auth_cookie' ) ? wp_validate_auth_cookie( '', 'logged_in' ) : false;
		return $uid && (int) $uid === get_current_user_id();
	}

	/** @return true|WP_Error */
	public static function save_from_request( $post ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'wpvibe_bypass_forbidden', __( 'You do not have permission to do this.', 'vibe-ai' ), array( 'status' => 403 ) );
		}
		$nonce = isset( $post['_wpnonce'] ) ? (string) wp_unslash( $post['_wpnonce'] ) : '';
		if ( ! wp_verify_nonce( $nonce, self::ACTION ) ) {
			return new WP_Error( 'wpvibe_bypass_nonce', __( 'This form has expired. Reload the WPVibe page and try again.', 'vibe-ai' ), array( 'status' => 403 ) );
		}
		if ( ! self::is_cookie_session() ) {
			return new WP_Error( 'wpvibe_bypass_session', __( 'This setting can only be changed from wp-admin while signed in to this site in your browser.', 'vibe-ai' ), array( 'status' => 403 ) );
		}
		$enable = ! empty( $post['enabled'] );
		// On multisite a subsite admin must not switch off approvals for a super admin who works there.
		if ( $enable && is_multisite() && ! is_super_admin() ) {
			return new WP_Error( 'wpvibe_bypass_forbidden', __( 'On a multisite network, only a super admin can turn on Dangerously bypass approvals.', 'vibe-ai' ), array( 'status' => 403 ) );
		}
		$user   = wp_get_current_user();
		$login  = is_object( $user ) && isset( $user->user_login ) ? (string) $user->user_login : '';
		if ( $enable ) {
			self::$admin_save = true;
			try {
				update_option( self::OPTION, array( 'enabled' => true, 'user_id' => get_current_user_id(), 'user_login' => $login, 'enabled_at' => time() ), true );
			} finally {
				self::$admin_save = false;
			}
		} else {
			delete_option( self::OPTION );
		}
		if ( class_exists( 'WPVibe_Audit_Log' ) ) {
			WPVibe_Audit_Log::log_execution( array(
				'operation'      => $enable ? 'approval_bypass:on' : 'approval_bypass:off',
				'command'        => $enable ? 'wp-admin: Dangerously bypass approvals turned on' : 'wp-admin: Dangerously bypass approvals turned off',
				'result_summary' => sprintf( 'by %s', $login ),
			) );
		}
		return true;
	}

	public static function handle_save() {
		$result = self::save_from_request( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in save_from_request().
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), 403 );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=vibe-ai#wpvibe-bypass-approvals' ) );
		exit;
	}

	public static function render_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$details = self::details();
		if ( ! $details ) {
			return;
		}
		$by   = isset( $details['user_login'] ) ? (string) $details['user_login'] : '';
		$when = isset( $details['enabled_at'] ) ? gmdate( 'Y-m-d H:i', (int) $details['enabled_at'] ) . ' UTC' : '';
		printf(
			'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
			esc_html__( 'WPVibe is bypassing approvals on this site.', 'vibe-ai' ),
			esc_html( sprintf(
				/* translators: 1: username, 2: date and time */
				__( 'AI changes run without an approval prompt, including deletes, SQL, code and user changes. Turned on by %1$s at %2$s.', 'vibe-ai' ),
				$by,
				$when
			) ),
			esc_url( admin_url( 'admin.php?page=vibe-ai#wpvibe-bypass-approvals' ) ),
			esc_html__( 'Turn it off', 'vibe-ai' )
		);
	}

	public static function settings_html() {
		$on = self::is_on();
		ob_start();
		?>
		<div class="wpvibe-white-label" id="wpvibe-bypass-approvals">
			<strong><?php esc_html_e( 'Approvals', 'vibe-ai' ); ?></strong>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
				<?php wp_nonce_field( self::ACTION ); ?>
				<p><label><input type="checkbox" name="enabled" value="1"<?php checked( $on ); ?> /> <?php esc_html_e( 'Dangerously bypass approvals', 'vibe-ai' ); ?></label></p>
				<p class="wpvibe-white-label-warning"><?php esc_html_e( 'When this is on, WPVibe runs every AI change on this site with no approval prompt: deletes, raw SQL, code snippets, user and role changes, all of it. Anything the AI reads on this site, such as reviews, comments or form entries, could steer it into making those changes. Use it for unattended or bulk work you trust, and turn it off when you are done.', 'vibe-ai' ); ?></p>
				<button type="submit" class="wpvibe-btn wpvibe-btn--secondary"><?php esc_html_e( 'Save', 'vibe-ai' ); ?></button>
			</form>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
