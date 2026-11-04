<?php
/**
 * A WPVibe draft theme is a working copy, never a theme to activate (#878): once active, every draft tool refuses
 * with a 409 and the site runs on the copy. The WP-CLI path already refuses activating one (1.19.2); this covers
 * wp-admin: no Activate or Live Preview on the Themes screens, and a switch to a draft from anywhere else goes
 * straight back to the theme the site was on, with a notice saying how to preview and publish it instead.
 *
 * @package WPVibe
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPVibe_Draft_Activation_Guard {

	const NOTICE_TRANSIENT = 'wpvibe_draft_activation_';

	public static function register() {
		add_filter( 'wp_prepare_themes_for_js', array( __CLASS__, 'themes_for_js' ) );
		add_filter( 'theme_action_links', array( __CLASS__, 'theme_action_links' ), 10, 2 );
		add_action( 'switch_theme', array( __CLASS__, 'on_switch_theme' ), 10, 3 );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
	}

	/** The registered draft, or any '<source>-wpvibe-draft' folder, as the WP-CLI guard decides. */
	public static function is_draft( $stylesheet ) {
		$slug  = strtolower( rtrim( trim( (string) $stylesheet ), '/\\' ) );
		$draft = strtolower( (string) get_option( 'wpvibe_draft_theme', '' ) );
		return '' !== $slug && ( ( '' !== $draft && $draft === $slug ) || '-wpvibe-draft' === substr( $slug, -13 ) );
	}

	/** Themes screen (themes.php): a draft keeps its other actions, but not Activate or Live Preview. */
	public static function themes_for_js( $themes ) {
		foreach ( (array) $themes as $slug => $theme ) {
			if ( is_array( $theme ) && self::is_draft( $theme['id'] ?? $slug ) && isset( $theme['actions'] ) && is_array( $theme['actions'] ) ) {
				unset( $themes[ $slug ]['actions']['activate'], $themes[ $slug ]['actions']['customize'] );
			}
		}
		return $themes;
	}

	/** WP_Themes_List_Table's links (core no longer renders that table, but plugins and older admin screens can). */
	public static function theme_action_links( $actions, $theme ) {
		if ( is_array( $actions ) && is_object( $theme ) && method_exists( $theme, 'get_stylesheet' ) && self::is_draft( $theme->get_stylesheet() ) ) {
			unset( $actions['activate'], $actions['preview'] );
		}
		return $actions;
	}

	/**
	 * switch_theme fires after the switch. WPVibe itself only ever switches to a source or the previous theme, so a
	 * draft here came from outside WPVibe: put the previous theme back.
	 */
	public static function on_switch_theme( $new_name, $new_theme, $old_theme = null ) {
		if ( ! is_object( $new_theme ) || ! method_exists( $new_theme, 'get_stylesheet' ) || ! self::is_draft( $new_theme->get_stylesheet() ) ) {
			return;
		}
		$previous = is_object( $old_theme ) && method_exists( $old_theme, 'get_stylesheet' ) ? (string) $old_theme->get_stylesheet() : '';
		// Never back to another draft, or to a theme whose folder is gone.
		if ( '' === $previous || self::is_draft( $previous ) || ( method_exists( $old_theme, 'exists' ) && ! $old_theme->exists() ) ) {
			return;
		}
		switch_theme( $previous );
		// Net, nothing switched: without this, the next load fires after_switch_theme as if the previous theme were new.
		delete_option( 'theme_switched' );
		$user = get_current_user_id();
		if ( $user > 0 ) {
			set_transient( self::NOTICE_TRANSIENT . $user, array( 'draft' => (string) $new_theme->get_stylesheet(), 'previous' => $previous ), 300 );
		}
	}

	public static function admin_notice() {
		$user = get_current_user_id();
		if ( $user <= 0 ) {
			return;
		}
		$note = get_transient( self::NOTICE_TRANSIENT . $user );
		if ( ! is_array( $note ) || empty( $note['draft'] ) || empty( $note['previous'] ) ) {
			return;
		}
		delete_transient( self::NOTICE_TRANSIENT . $user );
		$text = sprintf(
			/* translators: 1: draft theme folder, 2: the theme the site is back on */
			__( '"%1$s" is a WPVibe draft, a working copy rather than a theme, so it cannot be activated: an active draft is served without its compiled CSS and blocks every draft change. The site is back on "%2$s". To see the draft, ask your AI for a preview link (get_preview_url); to put it live, ask it to publish the draft (publish_draft_theme).', 'vibe-ai' ),
			$note['draft'],
			$note['previous']
		);
		echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
	}
}
