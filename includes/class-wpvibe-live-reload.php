<?php
/**
 * Enqueues the live reload polling script for logged-in users.
 *
 * Frontend pages: auto-reload when WPVibe makes changes.
 * wp-admin pages: toast notification with action button.
 * Permissions are enforced by the REST endpoint, not the script.
 */

defined( 'ABSPATH' ) || exit;

class WPVibe_Live_Reload {

	// Frontend page-builder edit sessions: auto-reload/navigation there
	// destroys the user's editing session mid-boot (reads as an endless
	// builder spinner while an MCP session is active).
	const BUILDER_EDIT_PARAMS = array( 'et_fb', 'fl_builder', 'elementor-preview', 'bricks', 'breakdance', 'breakdance_iframe', 'oxygen', 'ct_builder', 'vc_editable', 'brizy-edit', 'brizy-edit-iframe', 'fb-edit' );

	// Per-user opt-out from the WPVibe page (#1206): a signed-in front-end tab otherwise jumps to each post the AI edits.
	const USER_META = 'wpvibe_live_reload_off';
	const ACTION    = 'wpvibe_live_reload';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		// Fallback: error pages (e.g., "not allowed") don't fire admin_footer.
		// Enqueue a minimal polling script in admin_head so the user isn't stuck.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_head_fallback' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_save' ) );
		// The WPVibe page needs manage_options; every user who can connect an AI can reach their own profile.
		add_action( 'personal_options', array( __CLASS__, 'profile_field' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save_profile_field' ) );
	}

	private static function white_labelled() {
		return class_exists( 'WPVibe_White_Label' ) && WPVibe_White_Label::is_hidden();
	}

	/** The same switch on the user's own Profile page. Hidden, and so left as set, while white label hides WPVibe. */
	public static function profile_field( $user ) {
		if ( self::white_labelled() || ! is_object( $user ) || (int) $user->ID !== get_current_user_id() ) {
			return;
		}
		?>
		<tr class="wpvibe-live-reload-wrap">
			<th scope="row"><?php esc_html_e( 'WPVibe live reload', 'vibe-ai' ); ?></th>
			<td><label for="wpvibe_live_reload_off"><input type="checkbox" name="wpvibe_live_reload_off" id="wpvibe_live_reload_off" value="1"<?php echo self::is_off_for( $user->ID ) ? ' checked="checked"' : ''; ?> /> <?php esc_html_e( 'Turn off live reload: my browser stays on the page I am viewing while my AI edits the site.', 'vibe-ai' ); ?></label></td>
		</tr>
		<?php
	}

	/** Runs inside core's own profile save, after its update-user_{id} nonce and edit_user checks; only ever the user's own meta. */
	public static function save_profile_field( $user_id ) {
		if ( self::white_labelled() || (int) $user_id !== get_current_user_id() || ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- core verified update-user_{id} before personal_options_update.
		if ( empty( $_POST['wpvibe_live_reload_off'] ) ) {
			delete_user_meta( (int) $user_id, self::USER_META );
		} else {
			update_user_meta( (int) $user_id, self::USER_META, '1' );
		}
	}

	/** Whether this user turned live reload off for themselves. */
	public static function is_off_for( $user_id ) {
		return '1' === (string) get_user_meta( (int) $user_id, self::USER_META, true );
	}

	/**
	 * Saves the signed-in user's own choice; it never touches another user's.
	 *
	 * @return true|WP_Error
	 */
	public static function save_from_request( $post ) {
		if ( ! current_user_can( 'read' ) ) {
			return new WP_Error( 'wpvibe_live_reload_forbidden', __( 'You do not have permission to do this.', 'vibe-ai' ), array( 'status' => 403 ) );
		}
		$nonce = isset( $post['_wpnonce'] ) ? (string) wp_unslash( $post['_wpnonce'] ) : '';
		if ( ! wp_verify_nonce( $nonce, self::ACTION ) ) {
			return new WP_Error( 'wpvibe_live_reload_nonce', __( 'This form has expired. Reload the WPVibe page and try again.', 'vibe-ai' ), array( 'status' => 403 ) );
		}
		if ( empty( $post['off'] ) ) {
			delete_user_meta( get_current_user_id(), self::USER_META );
		} else {
			update_user_meta( get_current_user_id(), self::USER_META, '1' );
		}
		return true;
	}

	public static function handle_save() {
		$result = self::save_from_request( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in save_from_request().
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), 403 );
		}
		// While white label hides it, the WPVibe page is not registered: go back where the form was (#887's rule).
		$back = self::white_labelled() ? wp_get_referer() : '';
		wp_safe_redirect( $back ? $back : ( self::white_labelled() ? admin_url() : admin_url( 'admin.php?page=vibe-ai#wpvibe-live-reload' ) ) );
		exit;
	}

	/** The WPVibe page's card: one button that switches the signed-in user's live reload. */
	public static function settings_html() {
		$off = self::is_off_for( get_current_user_id() );
		ob_start();
		?>
		<div class="wpvibe-white-label" id="wpvibe-live-reload">
			<strong><?php esc_html_e( 'Live reload', 'vibe-ai' ); ?></strong>
			<p><?php echo $off
				? esc_html__( 'Off for you: your browser stays on the page you are viewing while your AI edits the site. Reload the page yourself to see a change.', 'vibe-ai' )
				: esc_html__( 'While your AI edits this site, your signed-in browser follows along: the front end opens each post it changes, and wp-admin shows a notice. Turn it off if the jumping gets in your way. This is just for you.', 'vibe-ai' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
				<?php wp_nonce_field( self::ACTION ); ?>
				<?php if ( ! $off ) : ?>
				<input type="hidden" name="off" value="1" />
				<?php endif; ?>
				<button type="submit" class="wpvibe-btn wpvibe-btn--secondary"><?php echo $off ? esc_html__( 'Turn live reload back on', 'vibe-ai' ) : esc_html__( 'Turn off live reload', 'vibe-ai' ); ?></button>
			</form>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Whether the request is a frontend page-builder edit session.
	 *
	 * @param array $get $_GET snapshot.
	 * @return bool
	 */
	public static function is_builder_edit_request( array $get ) {
		$params = apply_filters( 'wpvibe_builder_edit_params', self::BUILDER_EDIT_PARAMS );
		foreach ( (array) $params as $param ) {
			if ( isset( $get[ $param ] ) ) {
				return true;
			}
		}
		// Elementor's editor is an admin screen (post.php?action=elementor);
		// the same-post reload() branch would destroy the session.
		if ( isset( $get['action'] ) && 'elementor' === $get['action'] ) {
			return true;
		}
		return false;
	}

	/**
	 * Check if the current user should get the live reload script.
	 *
	 * @return bool
	 */
	private function should_load() {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		if ( self::is_off_for( get_current_user_id() ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading URL params, not processing form.
		if ( self::is_builder_edit_request( $_GET ) ) {
			return false;
		}

		// Only inject when WPVibe has been active recently (transient exists).
		$changes = get_transient( WPVibe_Change_Tracker::TRANSIENT_KEY );
		if ( empty( $changes ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Enqueue live reload script and styles.
	 */
	public function enqueue_scripts() {
		if ( ! $this->should_load() ) {
			return;
		}

		wp_enqueue_style(
			'wpvibe-live-reload',
			WPVIBE_PLUGIN_URL . 'assets/css/live-reload.css',
			array(),
			WPVIBE_VERSION
		);

		$this->enqueue_poller();
		wp_enqueue_script(
			'wpvibe-live-reload',
			WPVIBE_PLUGIN_URL . 'assets/js/live-reload.js',
			array( 'wpvibe-live-reload-poller' ),
			WPVIBE_VERSION,
			true // Load in footer.
		);

		// Detect current post ID for same-post auto-refresh in admin.
		$post_id = 0;
		if ( is_admin() ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading URL param, not processing form.
			$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		}

		wp_localize_script( 'wpvibe-live-reload', 'wpvibeLiveReload', array(
			'endpoint' => esc_url( rest_url( 'wpvibe/v1/last-change' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'isAdmin'  => is_admin() ? '1' : '',
			'postId'   => (string) $post_id,
			'userId'   => (string) get_current_user_id(),
		) );
	}

	/**
	 * Minimal fallback for admin pages that don't fire admin_footer (e.g., error pages).
	 * Only handles navigation so the user isn't stuck on an error page.
	 * Enqueued to load in the document head.
	 */
	public function enqueue_head_fallback() {
		if ( ! $this->should_load() ) {
			return;
		}

		$this->enqueue_poller();
		wp_enqueue_script(
			'wpvibe-live-reload-fallback',
			WPVIBE_PLUGIN_URL . 'assets/js/live-reload-fallback.js',
			array( 'wpvibe-live-reload-poller' ),
			WPVIBE_VERSION,
			false // Load in head so it fires even when admin_footer doesn't.
		);

		wp_localize_script(
			'wpvibe-live-reload-fallback',
			'wpvibeLiveReloadFallback',
			array(
				'endpoint' => esc_url( rest_url( 'wpvibe/v1/last-change' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'userId'   => (string) get_current_user_id(),
			)
		);
	}

	private function enqueue_poller() {
		wp_enqueue_script( 'wpvibe-live-reload-poller', WPVIBE_PLUGIN_URL . 'assets/js/live-reload-poller.js', array(), WPVIBE_VERSION, false );
	}
}
