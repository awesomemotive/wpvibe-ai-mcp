<?php
/**
 * White-label mode for agency-managed sites.
 *
 * When the wpvibe_hide_from_admins option is truthy, every WPVibe surface
 * in wp-admin is suppressed for every user: admin menu, dashboard widget,
 * Plugins-row entry, post-edit sidebar, update listing, and Site Health
 * plugin entries. The site keeps working through the MCP connection; the
 * agency manages it via AI.
 *
 * Rails: enabling requires an active WPVibe connection, the plugin enrolls
 * itself in core auto-updates while hidden (there is no visible Plugins row
 * to update from, and it cannot update itself over its own connection), and
 * hiding self-reverts after 30 days without an authenticated WPVibe request
 * so abandoned sites always resurface.
 *
 * @package WPVibe
 */

defined( 'ABSPATH' ) || exit;

class WPVibe_White_Label {

	const OPTION = 'wpvibe_hide_from_admins';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'all_plugins', array( $this, 'filter_plugins_list' ) );
		add_filter( 'site_transient_update_plugins', array( $this, 'filter_update_listing' ) );
		add_filter( 'debug_information', array( $this, 'filter_site_health' ) );
		add_action( 'admin_init', array( $this, 'maybe_auto_unhide' ), 5 );
		add_action( 'add_option_' . self::OPTION, array( $this, 'on_option_added' ), 10, 2 );
		add_action( 'update_option_' . self::OPTION, array( $this, 'on_option_updated' ), 10, 2 );
	}

	/**
	 * Whether WPVibe surfaces should be hidden on this request.
	 *
	 * Never hides in network admin: the option is per-site, and network
	 * admins must always be able to see and manage the plugin.
	 *
	 * @return bool
	 */
	public static function is_hidden() {
		$hidden = self::truthy( get_option( self::OPTION ) );
		if ( $hidden && function_exists( 'is_network_admin' ) && is_network_admin() ) {
			$hidden = false;
		}
		return (bool) apply_filters( 'wpvibe_hide_from_admins', $hidden );
	}

	/**
	 * Shared truthiness rule for the option value, so "0", "no", "off",
	 * and "false" disable regardless of which write path stored them.
	 *
	 * @param mixed $value
	 * @return bool
	 */
	public static function truthy( $value ) {
		if ( is_string( $value ) ) {
			return ! in_array( strtolower( trim( $value ) ), array( '', '0', 'no', 'off', 'false' ), true );
		}
		return ! empty( $value );
	}

	/**
	 * Connected = an authenticated WPVibe request landed within 30 days.
	 * Same rule the admin page status badge uses.
	 *
	 * @return bool
	 */
	public static function site_is_connected() {
		$last_active = (int) get_option( 'wpvibe_last_active', 0 );
		return $last_active > 0 && ( time() - $last_active ) < 30 * DAY_IN_SECONDS;
	}

	/**
	 * Remove the WPVibe row from the plugins.php list table. wp-admin only:
	 * WP-CLI applies the all_plugins filter too, and there the row must stay
	 * visible — shell access is a documented recovery path, and hiding it
	 * would break `wp plugin update vibe-ai` on hidden sites.
	 *
	 * @param array $plugins
	 * @return array
	 */
	public function filter_plugins_list( $plugins ) {
		if ( is_admin() && ! wp_doing_cron() && self::is_hidden() && defined( 'WPVIBE_PLUGIN_BASENAME' ) ) {
			unset( $plugins[ WPVIBE_PLUGIN_BASENAME ] );
		}
		return $plugins;
	}

	/**
	 * Strip WPVibe from admin-screen update listings (update-core.php, the
	 * update count bubble). Cron is deliberately untouched — the auto-updater
	 * must still see the update or hidden sites go stale.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	public function filter_update_listing( $value ) {
		if ( ! is_object( $value ) || ! defined( 'WPVIBE_PLUGIN_BASENAME' ) ) {
			return $value;
		}
		if ( ! is_admin() || wp_doing_cron() || ! self::is_hidden() ) {
			return $value;
		}
		foreach ( array( 'response', 'no_update' ) as $bucket ) {
			if ( isset( $value->{$bucket}[ WPVIBE_PLUGIN_BASENAME ] ) ) {
				unset( $value->{$bucket}[ WPVIBE_PLUGIN_BASENAME ] );
			}
		}
		return $value;
	}

	/**
	 * Drop WPVibe plugin entries from Site Health -> Info.
	 *
	 * @param array $info
	 * @return array
	 */
	public function filter_site_health( $info ) {
		if ( ! self::is_hidden() ) {
			return $info;
		}
		foreach ( array( 'wp-plugins-active', 'wp-plugins-inactive' ) as $section ) {
			if ( empty( $info[ $section ]['fields'] ) || ! is_array( $info[ $section ]['fields'] ) ) {
				continue;
			}
			foreach ( $info[ $section ]['fields'] as $key => $field ) {
				$label = isset( $field['label'] ) ? (string) $field['label'] : '';
				if ( false !== stripos( (string) $key, 'wpvibe' ) || false !== stripos( $label, 'wpvibe' ) ) {
					unset( $info[ $section ]['fields'][ $key ] );
				}
			}
		}
		return $info;
	}

	/**
	 * Self-revert when the site has been disconnected for 30 days, so an
	 * abandoned or orphaned site always gets its Plugins row back.
	 */
	public function maybe_auto_unhide() {
		if ( ! self::truthy( get_option( self::OPTION ) ) ) {
			return;
		}
		if ( ! self::site_is_connected() ) {
			delete_option( self::OPTION );
		}
	}

	public function on_option_added( $option, $value ) {
		$this->sync_auto_updates( $value );
	}

	public function on_option_updated( $old_value, $value ) {
		$this->sync_auto_updates( $value );
	}

	/**
	 * Enroll the plugin in core auto-updates when hiding is enabled. With no
	 * Plugins row and no self-update over its own connection, core cron is
	 * the only concealed path that keeps a hidden plugin current.
	 *
	 * @param mixed $value New option value.
	 */
	private function sync_auto_updates( $value ) {
		if ( ! self::truthy( $value ) || ! defined( 'WPVIBE_PLUGIN_BASENAME' ) ) {
			return;
		}
		$auto = (array) get_option( 'auto_update_plugins', array() );
		if ( ! in_array( WPVIBE_PLUGIN_BASENAME, $auto, true ) ) {
			$auto[] = WPVIBE_PLUGIN_BASENAME;
			update_option( 'auto_update_plugins', $auto );
		}
	}
}
