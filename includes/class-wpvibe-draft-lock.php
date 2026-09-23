<?php
/** Serialize WPVibe theme mutations across sites sharing a theme root. */
defined( 'ABSPATH' ) || exit;

class WPVibe_Draft_Lock {
	private static $held = array();

	const LOCK_FILE = '.wpvibe-draft.lock';

	/** Seconds a theme operation waits for another one to finish before returning draft_busy. */
	const WAIT_SECONDS = 5;

	public static function run( $callback ) {
		$root = realpath( get_theme_root() );
		if ( false === $root ) {
			return self::error( 'theme_root_missing', get_theme_root() );
		}
		if ( isset( self::$held[ $root ] ) ) {
			return call_user_func( $callback );
		}
		$path = $root . '/' . self::LOCK_FILE;
		// Never unlink this file: replacing its inode would allow two owners.
		if ( is_link( $path ) ) {
			return self::error( 'lock_file_symlink', $root );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = @fopen( $path, 'c' );
		if ( ! $handle ) {
			return self::error( 'lock_file_unwritable', $root );
		}
		// Parallel edits on one draft queue briefly instead of the loser failing outright.
		$wait     = max( 0.0, min( 10.0, (float) apply_filters( 'wpvibe_draft_lock_wait', self::WAIT_SECONDS ) ) );
		$deadline = microtime( true ) + $wait;
		while ( ! flock( $handle, LOCK_EX | LOCK_NB, $would_block ) ) {
			// A refusal that is not contention (NFS without lockd, ENOLCK) will not clear by waiting.
			if ( ! $would_block ) {
				fclose( $handle );
				return self::error( 'lock_unsupported', $root );
			}
			if ( microtime( true ) >= $deadline ) {
				fclose( $handle );
				return self::error( 'lock_held', $root, $wait );
			}
			usleep( 50000 );
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

	/** The themes folder as the site owner knows it: relative to the WordPress root when it sits inside it. */
	private static function display_dir( $root ) {
		$root = str_replace( '\\', '/', (string) $root );
		// $root is a realpath, so compare against ABSPATH's realpath too (symlinked installs).
		foreach ( array_unique( array( ABSPATH, (string) realpath( ABSPATH ) ) ) as $abs ) {
			$base = rtrim( str_replace( '\\', '/', $abs ), '/' ) . '/';
			if ( '/' !== $base && 0 === strpos( $root, $base ) ) {
				return substr( $root, strlen( $base ) );
			}
		}
		return $root;
	}

	private static function error( $reason, $root, $waited = 0 ) {
		$dir  = self::display_dir( $root );
		$file = $dir . '/' . self::LOCK_FILE;
		switch ( $reason ) {
			case 'lock_held':
				/* translators: %s: seconds waited. */
				$message = sprintf( __( 'Another theme operation on this site (or on a site that shares its themes folder) was still running after %s seconds, so this one did not start. No changes were made. Retry once the other operation finishes.', 'vibe-ai' ), round( $waited, 1 ) );
				$cause   = 'filesystem';
				$retry   = true;
				break;
			case 'lock_unsupported':
				/* translators: %s: lock file path. */
				$message = sprintf( __( 'The server refused a file lock on %s. Theme file edits, draft themes and classic themes need file locking, which some network storage does not provide. No changes were made. Ask the host to enable file locking for the themes folder; retrying will not help until then.', 'vibe-ai' ), $file );
				$cause   = 'host_environment';
				$retry   = false;
				break;
			case 'lock_file_symlink':
				/* translators: %s: lock file path. */
				$message = sprintf( __( 'WPVibe\'s theme lock file, %s, is a symbolic link, so WPVibe will not use it. No changes were made. Remove the link (the link only, not what it points to) so WPVibe can create a normal file there, then retry.', 'vibe-ai' ), $file );
				$cause   = 'host_environment';
				$retry   = false;
				break;
			case 'theme_root_missing':
				/* translators: %s: themes folder. */
				$message = sprintf( __( 'The themes folder (%s) could not be found, so WPVibe could not lock it for a theme operation. No changes were made.', 'vibe-ai' ), $dir );
				$cause   = 'host_environment';
				$retry   = false;
				break;
			default:
				/* translators: 1: lock file path, 2: themes folder. */
				$message = sprintf( __( 'WPVibe could not create or open its theme lock file, %1$s. The web server needs write access to %2$s (and to the lock file, if it already exists). No changes were made. Fix the permissions, then retry.', 'vibe-ai' ), $file, $dir );
				$cause   = 'host_environment';
				$retry   = false;
		}
		return new WP_Error( 'draft_busy', $message, WPVibe_Error_Contract::data( $cause, $retry, array( 'status' => 409, 'reason' => $reason, 'lock_file' => $file ) ) );
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
		$draft  = get_option( 'wpvibe_draft_theme' );
		$source = get_option( 'wpvibe_draft_source' );
		$data   = array( 'status' => 409, 'draft_slug' => $draft, 'source_slug' => $source );
		// "Continue editing the current draft" is a dead end when the draft itself is what every draft operation refuses.
		if ( self::valid_slug( $draft ) && $draft === get_option( 'stylesheet' ) ) {
			$data['reason'] = 'draft_is_active_theme';
			/* translators: 1: draft theme folder, 2: original theme folder. */
			return new WP_Error( 'draft_conflict', sprintf( __( 'The draft theme (%1$s) is the active theme on this site, so WPVibe will not edit, preview, publish or delete it as a draft. Nothing was changed. Under Appearance > Themes, activate the theme the draft was copied from (%2$s), then continue editing the draft and publish it through WPVibe.', 'vibe-ai' ), $draft, self::valid_slug( $source ) ? $source : __( 'the original theme', 'vibe-ai' ) ), WPVibe_Error_Contract::data( 'invalid_input', false, $data ) );
		}
		return new WP_Error( 'draft_conflict', __( 'Existing draft state was preserved. Continue editing the current draft. To start a different theme, first save a separate backup and explicitly choose whether to publish or discard the current draft. Missing draft files or an untracked draft directory require recovery before creating another draft.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'invalid_input', false, $data ) );
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
