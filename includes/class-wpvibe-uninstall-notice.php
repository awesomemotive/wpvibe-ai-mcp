<?php
/**
 * Reminder that removing the plugin does not disconnect the site.
 *
 * Deactivating or deleting WPVibe leaves the WordPress Application Password
 * and the connection at mcp.wpvibe.ai in place: a connected site keeps
 * answering over core REST without the plugin. The reminder lives in two
 * places: a confirm on the Deactivate link (the last moment the plugin can
 * speak) and a section on the WPVibe settings page. Nothing here revokes
 * anything or contacts WPVibe.
 *
 * @package WPVibe
 */

defined( 'ABSPATH' ) || exit;

class WPVibe_Uninstall_Notice {

	const SCRIPT_HANDLE = 'vibe-ai-uninstall-notice';
	const ACCOUNT_URL   = 'https://mcp.wpvibe.ai/';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_confirm' ) );
	}

	/**
	 * White label hides every WPVibe-branded surface, including this one.
	 *
	 * @return bool
	 */
	public static function should_show() {
		if ( ! defined( 'WPVIBE_PLUGIN_BASENAME' ) || WPVibe_White_Label::is_hidden() ) {
			return false;
		}
		return current_user_can( 'activate_plugins' );
	}

	/**
	 * @param string $hook Current admin page hook suffix.
	 * @return bool
	 */
	public static function should_enqueue( $hook ) {
		return 'plugins.php' === $hook && self::should_show();
	}

	public static function message() {
		return __( 'Deactivating or deleting WPVibe does not disconnect this site and does not revoke the WordPress Application Password it uses; connected AI assistants keep reaching the site through the WordPress REST API. To cut access, do both: revoke the WPVibe Application Password under Users > Profile > Application Passwords, and remove this site from your WPVibe account.', 'vibe-ai' );
	}

	public static function settings_html() {
		$profile = '<a href="' . esc_url( admin_url( 'profile.php#application-passwords-section' ) ) . '">' . esc_html__( 'Revoke the Application Password', 'vibe-ai' ) . '</a>';
		$account = '<a href="' . esc_url( self::ACCOUNT_URL ) . '" target="_blank" rel="noopener">' . esc_html__( 'Remove the site at mcp.wpvibe.ai', 'vibe-ai' ) . '</a>';

		return '<div class="wpvibe-disconnect">'
			. '<strong>' . esc_html__( 'Disconnecting this site', 'vibe-ai' ) . '</strong>'
			. '<p>' . esc_html( self::message() ) . '</p>'
			. '<p class="wpvibe-disconnect-links">' . $profile . ' &middot; ' . $account . '</p>'
			. '</div>';
	}

	/**
	 * Hooked to Deactivate, not Delete: core only offers Delete for an inactive
	 * plugin, and an inactive plugin's code never runs, so Deactivate is the last
	 * moment this notice can speak.
	 */
	public static function confirm_script() {
		$message = __( 'Deactivating WPVibe does not disconnect this site or revoke its WordPress Application Password; connected AI assistants keep access through the REST API. To cut access, revoke the password under Users > Profile > Application Passwords and remove the site from your WPVibe account. Deactivate anyway?', 'vibe-ai' );
		return '(function(){'
			. 'var row=document.querySelector(\'tr[data-plugin="' . WPVIBE_PLUGIN_BASENAME . '"]\');'
			. 'if(!row){return;}'
			. 'var link=row.querySelector(".row-actions .deactivate a");'
			. 'if(!link){return;}'
			. 'link.addEventListener("click",function(e){'
			. 'if(!window.confirm(' . wp_json_encode( $message ) . ')){e.preventDefault();e.stopImmediatePropagation();}'
			. '},true);'
			. '})();';
	}

	public function enqueue_confirm( $hook ) {
		if ( ! self::should_enqueue( $hook ) ) {
			return;
		}
		wp_register_script( self::SCRIPT_HANDLE, false, array(), WPVIBE_VERSION, true );
		wp_enqueue_script( self::SCRIPT_HANDLE );
		wp_add_inline_script( self::SCRIPT_HANDLE, self::confirm_script() );
	}
}
