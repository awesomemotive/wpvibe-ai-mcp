<?php
/**
 * Opt-in: allow WordPress Application Passwords for WPVibe requests only, even when a
 * security plugin turns them off site-wide. Scoped to the approval flow started from a
 * WPVibe connect link and to REST requests that carry the WPVibe header.
 *
 * @package WPVibe
 */

defined( 'ABSPATH' ) || exit;

class WPVibe_App_Password_Policy {

	const OPTION = 'wpvibe_allow_app_passwords';

	private static $forced = false;

	public static function init() {
		if ( ! self::enabled() ) {
			return;
		}
		add_filter( 'wp_is_application_passwords_available', array( __CLASS__, 'allow' ), PHP_INT_MAX );
		add_filter( 'wp_is_application_passwords_available_for_user', array( __CLASS__, 'allow_for_user' ), PHP_INT_MAX, 2 );
	}

	public static function enabled() {
		return (bool) get_option( self::OPTION, false );
	}

	public static function allow( $available ) {
		if ( $available || ! self::enabled() ) {
			return $available;
		}
		return is_ssl() && self::wpvibe_request() ? true : $available;
	}

	// The header alone is public; the account must also hold a password WPVibe minted, or be the one approving one on our authorize page.
	public static function allow_for_user( $available, $user ) {
		if ( $available || ! self::enabled() || ! is_ssl() || ! self::wpvibe_request() ) {
			return $available;
		}
		if ( self::$forced || self::on_wpvibe_authorize_page() ) {
			return true;
		}
		$user_id = $user instanceof WP_User ? $user->ID : (int) $user;
		if ( ! $user_id || ! class_exists( 'WP_Application_Passwords' ) ) {
			return $available;
		}
		foreach ( (array) WP_Application_Passwords::get_user_application_passwords( $user_id ) as $item ) {
			if ( isset( $item['name'] ) && 0 === strpos( (string) $item['name'], 'WPVibe' ) ) {
				return true;
			}
		}
		return $available;
	}

	private static function on_wpvibe_authorize_page() {
		$script = isset( $_SERVER['SCRIPT_NAME'] ) ? basename( wp_unslash( $_SERVER['SCRIPT_NAME'] ) ) : '';
		$app    = isset( $_REQUEST['app_name'] ) ? wp_unslash( $_REQUEST['app_name'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only match against our own app name
		return 'authorize-application.php' === $script && is_string( $app ) && 0 === strpos( $app, 'WPVibe' );
	}

	/** Evaluate availability the way a WPVibe request would see it. */
	public static function available_for_wpvibe( $user ) {
		self::$forced = true;
		try {
			return function_exists( 'wp_is_application_passwords_available_for_user' ) && wp_is_application_passwords_available_for_user( $user );
		} finally {
			self::$forced = false;
		}
	}

	private static function wpvibe_request() {
		if ( self::$forced ) {
			return true;
		}
		if ( isset( $_SERVER['HTTP_X_WPVIBE'] ) && '1' === $_SERVER['HTTP_X_WPVIBE'] ) {
			return true;
		}
		if ( isset( $_SERVER['HTTP_X_WPVIBE_AUTHORIZATION'] ) ) {
			return true;
		}
		$script = isset( $_SERVER['SCRIPT_NAME'] ) ? basename( wp_unslash( $_SERVER['SCRIPT_NAME'] ) ) : '';
		if ( 'authorize-application.php' === $script ) {
			$app = isset( $_REQUEST['app_name'] ) ? wp_unslash( $_REQUEST['app_name'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only match against our own app name
			if ( is_string( $app ) && 0 === strpos( $app, 'WPVibe' ) ) {
				return true;
			}
		}
		return false;
	}

	/** Name the plugin whose filter turns Application Passwords off, when one can be identified. */
	public static function blocker() {
		$known = self::known_blocker();
		if ( $known ) {
			return $known;
		}
		return self::plugin_hooking( array( 'wp_is_application_passwords_available', 'wp_is_application_passwords_available_for_user' ) );
	}

	/** First third-party plugin with a callback on any of the given hooks. */
	public static function plugin_hooking( $hooks ) {
		global $wp_filter;
		foreach ( $hooks as $hook ) {
			if ( empty( $wp_filter[ $hook ] ) || ! ( $wp_filter[ $hook ] instanceof WP_Hook ) ) {
				continue;
			}
			foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$file = self::callback_file( $callback['function'] );
					if ( ! $file || false === strpos( $file, WP_PLUGIN_DIR ) || false !== strpos( $file, WPVIBE_PLUGIN_DIR ) ) {
						continue;
					}
					$plugin = self::plugin_for_file( $file );
					if ( $plugin ) {
						return $plugin;
					}
				}
			}
		}
		return null;
	}

	// Plugins that disable with core's __return_false leave no file to attribute, so check their settings directly.
	private static function known_blocker() {
		if ( class_exists( 'wfConfig' ) && method_exists( 'wfConfig', 'get' ) && wfConfig::get( 'loginSec_disableApplicationPasswords' ) ) {
			return array( 'name' => 'Wordfence', 'basename' => 'wordfence/wordfence.php', 'setting' => __( 'Login Security > Settings > Disable WordPress application passwords', 'vibe-ai' ) );
		}
		if ( class_exists( 'ITSEC_Modules' ) && method_exists( 'ITSEC_Modules', 'get_setting' ) && ITSEC_Modules::get_setting( 'wordpress-tweaks', 'disable_application_passwords' ) ) {
			return array( 'name' => 'Solid Security', 'basename' => 'better-wp-security/better-wp-security.php', 'setting' => __( 'Settings > WordPress Tweaks > Disable Application Passwords', 'vibe-ai' ) );
		}
		return null;
	}

	private static function callback_file( $function ) {
		try {
			if ( is_string( $function ) && function_exists( $function ) ) {
				$ref = new ReflectionFunction( $function );
			} elseif ( $function instanceof Closure ) {
				$ref = new ReflectionFunction( $function );
			} elseif ( is_array( $function ) && 2 === count( $function ) ) {
				$ref = new ReflectionMethod( $function[0], $function[1] );
			} else {
				return null;
			}
			return $ref->getFileName() ?: null;
		} catch ( Throwable $e ) {
			return null;
		}
	}

	private static function plugin_for_file( $file ) {
		$relative = ltrim( str_replace( WP_PLUGIN_DIR, '', $file ), '/\\' );
		$dir      = strtok( $relative, '/\\' );
		if ( ! $dir ) {
			return null;
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( get_plugins() as $basename => $data ) {
			if ( 0 === strpos( $basename, $dir . '/' ) ) {
				return array( 'name' => $data['Name'], 'basename' => $basename );
			}
		}
		return null;
	}
}
