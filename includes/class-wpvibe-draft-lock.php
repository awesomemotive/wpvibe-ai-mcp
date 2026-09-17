<?php
/** Serialize WPVibe theme mutations across sites sharing a theme root. */
defined( 'ABSPATH' ) || exit;

class WPVibe_Draft_Lock {
	private static $held = array();

	public static function run( $callback ) {
		$root = realpath( get_theme_root() );
		if ( false === $root ) {
			return self::error();
		}
		if ( isset( self::$held[ $root ] ) ) {
			return call_user_func( $callback );
		}
		$path = $root . '/.wpvibe-draft.lock';
		// Never unlink this file: replacing its inode would allow two owners.
		if ( is_link( $path ) ) {
			return self::error();
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = @fopen( $path, 'c' );
		if ( ! $handle ) {
			return self::error();
		}
		if ( ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
			fclose( $handle );
			return self::error();
		}
		self::$held[ $root ] = true;
		try {
			// Another request may have committed after this request autoloaded options.
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			foreach ( array( 'wpvibe_draft_theme', 'wpvibe_draft_source', 'wpvibe_preview_token', 'wpvibe_preview_token_issued', 'stylesheet' ) as $key ) {
				wp_cache_delete( $key, 'options' );
			}
			return call_user_func( $callback );
		} finally {
			unset( self::$held[ $root ] );
			flock( $handle, LOCK_UN );
			fclose( $handle );
		}
	}

	private static function error() {
		return new WP_Error( 'draft_busy', __( 'Could not exclusively lock the theme directory. Another theme operation may be running, or the host may not support filesystem locks. No changes were made. Retry after the operation finishes.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'filesystem', true, array( 'status' => 409 ) ) );
	}

	public static function valid_slug( $slug ) {
		return is_string( $slug ) && '' !== $slug && '.' !== $slug && '..' !== $slug
			&& ! preg_match( '/[\\\\\/\x00]/', $slug );
	}

	/** Corrupt or externally changed metadata must never target the live theme. */
	public static function valid_draft() {
		$draft = get_option( 'wpvibe_draft_theme' );
		$source = get_option( 'wpvibe_draft_source' );
		return self::valid_slug( $draft ) && self::valid_slug( $source )
			&& $draft === $source . '-wpvibe-draft'
			&& $draft !== get_option( 'stylesheet' )
			&& ! is_link( get_theme_root() . '/' . $draft )
			&& ! is_link( get_theme_root() . '/' . $source );
	}

	public static function conflict() {
		return new WP_Error( 'draft_conflict', __( 'Existing draft state was preserved. Continue editing the current draft. To start a different theme, first save a separate backup and explicitly choose whether to publish or discard the current draft. Missing draft files or an untracked draft directory require recovery before creating another draft.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 409, 'draft_slug' => get_option( 'wpvibe_draft_theme' ), 'source_slug' => get_option( 'wpvibe_draft_source' ) ) ) );
	}

	/** Register source first; a failed write leaves a conflict, never an editable partial draft. */
	public static function register( $draft, $source ) {
		update_option( 'wpvibe_draft_source', $source );
		if ( get_option( 'wpvibe_draft_source' ) !== $source ) {
			return self::conflict();
		}
		update_option( 'wpvibe_draft_theme', $draft );
		if ( get_option( 'wpvibe_draft_theme' ) !== $draft ) {
			return self::conflict();
		}
		return true;
	}
}
