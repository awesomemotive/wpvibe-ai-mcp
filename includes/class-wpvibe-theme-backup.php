<?php
/**
 * The live theme's backup from a draft publish, kept as a zip under uploads instead of a theme folder
 * (WordPress.org review 11-17, #1624: backups must not sit in a public place as executable files).
 *
 * One archive per theme, the newest; restoring one archives the theme it replaces first, so a restore is undone
 * by restoring again. The folder is closed to the web (.htaccess, an index.php), and each name carries a random
 * part, since nginx ignores .htaccess.
 *
 * @package VibeAI
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged -- direct calls on WPVibe's own folders, as the draft swap does: rename must stay atomic, and every failure is checked.

class WPVibe_Theme_Backup {

	const DIR      = 'wpvibe-backups';
	const LEGACY   = '-wpvibe-backup';
	const MIGRATED = 'wpvibe_theme_backups_migrated';

	/** The archive folder under uploads, created and closed to the web on first use. */
	public static function dir() {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return self::error( 'backup_dir_unavailable', __( 'The uploads folder is not available, so the theme backup could not be stored.', 'vibe-ai' ) );
		}
		$dir = rtrim( $uploads['basedir'], '/\\' ) . '/' . self::DIR;
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return self::error( 'backup_dir_unavailable', __( 'Could not create the theme backup folder under uploads.', 'vibe-ai' ) );
		}
		$guards = array(
			'index.php' => "<?php\n// Silence is golden.\n",
			'.htaccess' => "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
		);
		foreach ( $guards as $name => $body ) {
			if ( ! file_exists( "$dir/$name" ) && false === @file_put_contents( "$dir/$name", $body ) ) {
				return self::error( 'backup_dir_unavailable', __( 'Could not protect the theme backup folder under uploads.', 'vibe-ai' ) );
			}
		}
		return $dir;
	}

	/**
	 * Zip a theme folder as the theme's newest backup. With $prune, its older archives go once this one is written;
	 * publish and restore prune only once they succeed (prune()), so the archive before survives a failed one.
	 *
	 * @return string|WP_Error The archive path; '' when the folder holds no files (nothing to keep).
	 */
	public static function archive( $theme_dir, $slug, $prune = true ) {
		$has_link = false;
		$files    = self::files( $theme_dir, $has_link );
		if ( null === $files ) {
			return self::error( 'backup_failed', __( 'The live theme folder could not be read, so no backup was made.', 'vibe-ai' ) );
		}
		// A zip can't hold a link faithfully, and the folder is deleted once archived: refuse rather than lose them.
		if ( $has_link ) {
			/* translators: %s: theme folder */
			return self::error( 'backup_has_links', sprintf( __( 'The theme folder \'%s\' holds symbolic links, which a zip backup cannot keep, so no backup was made.', 'vibe-ai' ), $theme_dir ) );
		}
		if ( ! $files ) {
			return '';
		}
		$dir = self::dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		$file = sprintf( '%s/%s-%s-%s.zip', $dir, $slug, gmdate( 'Ymd-His' ), bin2hex( random_bytes( 6 ) ) );
		$ok = false;
		if ( class_exists( 'ZipArchive' ) ) {
			$zip = new ZipArchive();
			if ( true === $zip->open( $file, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
				$ok = true;
				foreach ( $files as $relative => $path ) {
					$ok = $ok && $zip->addFile( $path, $relative );
				}
				$ok = $zip->close() && $ok;
			}
		} else {
			require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
			$zip = new PclZip( $file );
			$ok  = self::pclzip_ok( $zip->create( array_values( $files ), PCLZIP_OPT_REMOVE_PATH, $theme_dir ), count( $files ) );
		}
		if ( ! $ok || ! is_file( $file ) || 0 === filesize( $file ) ) {
			@unlink( $file );
			return self::error( 'backup_failed', __( 'Could not write the theme backup archive under uploads.', 'vibe-ai' ) );
		}
		if ( $prune ) {
			self::prune( $slug, $file );
		}
		return $file;
	}

	/** Drop every archive of the theme but $keep (once the publish or restore that wrote $keep succeeded). */
	public static function prune( $slug, $keep ) {
		foreach ( self::archives( $slug ) as $older ) {
			if ( $older !== $keep ) {
				@unlink( $older );
			}
		}
	}

	/** The theme's newest backup archive, or null. */
	public static function latest( $slug ) {
		$all = self::archives( $slug );
		return $all ? $all[0] : null;
	}

	/**
	 * Put the theme's newest backup back as the theme. The theme it replaces is archived first and becomes the backup,
	 * so restoring again undoes this. The active theme setting is not changed.
	 *
	 * @return string|WP_Error The message for the caller.
	 */
	public static function restore( $slug ) {
		// Any folder name a publish accepts (WPVibe_Draft_Lock::valid_slug), but no hidden folder.
		if ( ! WPVibe_Draft_Lock::valid_slug( $slug ) || '.' === $slug[0] ) {
			return self::error( 'invalid_input', __( 'Theme slug required, as the folder name under wp-content/themes.', 'vibe-ai' ), 400 );
		}
		$zip = self::latest( $slug );
		if ( ! $zip ) {
			/* translators: %s: theme slug */
			return self::error( 'not_found', sprintf( __( 'There is no WPVibe backup of the theme \'%s\'. A backup is made when a WPVibe draft is published over it.', 'vibe-ai' ), $slug ), 404 );
		}
		$root    = get_theme_root();
		$live    = "$root/$slug";
		$staging = "$root/$slug-wpvibe-restore";
		$aside   = "$root/$slug-wpvibe-replaced";
		foreach ( array( $live, $staging, $aside ) as $path ) {
			if ( is_link( $path ) || ( file_exists( $path ) && ! is_dir( $path ) ) ) {
				/* translators: %s: path */
				return self::error( 'draft_conflict', sprintf( __( '\'%s\' is not a theme folder WPVibe can replace, so nothing was restored.', 'vibe-ai' ), $path ), 409 );
			}
		}
		// A failed restore that couldn't move the current theme back left it here: never delete it.
		$left = self::files( $aside );
		if ( null === $left || $left ) {
			/* translators: 1: folder, 2: theme folder */
			return self::error( 'draft_conflict', sprintf( __( 'A previous restore left the theme at \'%1$s\', so nothing was restored. Rename it to \'%2$s\' with SFTP or the host file manager (remove the folder there first if it holds the wrong version), then retry.', 'vibe-ai' ), $aside, $live ), 409 );
		}
		self::remove( $staging );
		self::remove( $aside );
		$taken     = self::taken_at( $zip );
		$extracted = self::extract( $zip, $staging );
		if ( is_wp_error( $extracted ) ) {
			self::remove( $staging );
			return $extracted;
		}
		$archived = '';
		if ( is_dir( $live ) ) {
			// Not pruned yet: the archive being restored must outlive a failed swap.
			$archived = self::archive( $live, $slug, false );
			if ( is_wp_error( $archived ) ) {
				self::remove( $staging );
				return $archived;
			}
			if ( ! @rename( $live, $aside ) ) {
				self::remove( $staging );
				self::discard( $archived );
				return self::error( 'filesystem', __( 'Could not move the current theme folder aside, so nothing was restored.', 'vibe-ai' ) );
			}
		}
		if ( ! @rename( $staging, $live ) ) {
			$back = ! is_dir( $aside ) || @rename( $aside, $live );
			self::remove( $staging );
			if ( $back ) {
				// The current theme is live again.
				self::discard( $archived );
				return self::error( 'filesystem', __( 'Could not put the restored theme in place; the current theme was left as it was.', 'vibe-ai' ) );
			}
			/* translators: 1: folder the current theme is in, 2: theme folder */
			$message = sprintf( __( 'RESTORE FAILED: the current theme is at \'%1$s\'. Rename it to \'%2$s\' with SFTP or the host file manager to bring the site back.', 'vibe-ai' ), $aside, $live );
			// Not moved back, the archive is the current theme's only other copy: keep it, under a name latest() never
			// picks, so a retry still restores the backup it was asked for and never prunes that one.
			if ( $archived ) {
				$kept = substr( $archived, 0, -4 ) . '.kept.zip';
				$kept = @rename( $archived, $kept ) ? $kept : $archived;
				/* translators: %s: archive path */
				$message .= ' ' . sprintf( __( 'A zip of it is kept at \'%s\'.', 'vibe-ai' ), $kept );
			}
			return self::error( 'filesystem', $message );
		}
		self::remove( $aside );
		if ( $archived ) {
			self::prune( $slug, $archived );
		}
		wp_clean_themes_cache();
		if ( class_exists( 'WPVibe_CLI' ) ) {
			try {
				( new WPVibe_CLI() )->purge_all_caches();
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- a cache failure must not fail a done restore.
			}
		}
		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Theme restored from backup: {$slug}",
			'action_label' => 'View Site',
			'url'          => home_url( '/' ),
			'admin_url'    => home_url( '/' ),
		) );
		/* translators: 1: theme slug, 2: when the backup was taken (UTC) */
		return sprintf( __( 'Restored the theme \'%1$s\' from its backup taken %2$s UTC. The version it replaced is now the backup, so running "theme restore-backup %1$s" again undoes this.', 'vibe-ai' ), $slug, $taken );
	}

	/**
	 * Turn backup folders left by older versions into archives, once, under the draft lock so a publish's own rollback
	 * folder is never taken. A theme already archived keeps its archive (a retry after a partial delete must not replace
	 * it with a partial copy). The active theme and its parent stay themes, and so does a folder holding symbolic links,
	 * which a zip can't keep. Not on multisite, where other sites may run on these folders. Only in wp-admin (not
	 * admin-ajax), cron and WP-CLI, never on a visitor's request; the hourly retry flag is set before the work, so a run that dies waits too.
	 */
	public static function migrate_legacy() {
		if ( get_option( self::MIGRATED ) || get_transient( self::MIGRATED . '_retry' ) ) {
			return;
		}
		// admin-ajax.php counts as admin and answers anonymous visitors too.
		if ( ( ! is_admin() || wp_doing_ajax() ) && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		if ( is_multisite() ) {
			update_option( self::MIGRATED, 'multisite' );
			return;
		}
		// Most sites have none: done without taking the lock (which would create its file on every site).
		if ( ! self::legacy_folders() ) {
			update_option( self::MIGRATED, '1' );
			return;
		}
		set_transient( self::MIGRATED . '_retry', 1, HOUR_IN_SECONDS );
		$result = WPVibe_Draft_Lock::run( function () {
			$failed = false;
			foreach ( self::legacy_folders() as $path ) {
				$slug = substr( basename( $path ), 0, -strlen( self::LEGACY ) );
				$kept = self::latest( $slug ) ? true : self::archive( $path, $slug );
				if ( is_wp_error( $kept ) && 'backup_has_links' === $kept->get_error_code() ) {
					continue;
				}
				// '' is a folder with no files: nothing to keep, so it just goes.
				if ( is_wp_error( $kept ) || ! self::remove( $path ) ) {
					$failed = true;
				}
			}
			return ! $failed;
		} );
		if ( true === $result ) {
			update_option( self::MIGRATED, '1' );
			delete_transient( self::MIGRATED . '_retry' );
		}
	}

	/** The -wpvibe-backup folders older versions left, but the active theme, its parent and links. */
	private static function legacy_folders() {
		$in_use = array( (string) get_option( 'stylesheet' ), (string) get_option( 'template' ) );
		$out    = array();
		foreach ( glob( get_theme_root() . '/*' . self::LEGACY, GLOB_ONLYDIR ) ?: array() as $path ) {
			$name = basename( $path );
			if ( self::LEGACY !== $name && ! is_link( $path ) && ! in_array( $name, $in_use, true ) ) {
				$out[] = $path;
			}
		}
		return $out;
	}

	/** Remove every backup archive and the folder (uninstall). */
	public static function remove_all() {
		$uploads = wp_upload_dir( null, false );
		if ( empty( $uploads['basedir'] ) ) {
			return;
		}
		self::remove( rtrim( $uploads['basedir'], '/\\' ) . '/' . self::DIR );
	}

	/** The theme's archives, newest first. */
	private static function archives( $slug ) {
		$uploads = wp_upload_dir( null, false );
		if ( empty( $uploads['basedir'] ) ) {
			return array();
		}
		$dir = rtrim( $uploads['basedir'], '/\\' ) . '/' . self::DIR;
		$out = array();
		foreach ( (array) glob( $dir . '/*.zip' ) as $file ) {
			if ( preg_match( '/^' . preg_quote( $slug, '/' ) . '-\d{8}-\d{6}-[a-f0-9]{12}\.zip$/', basename( $file ) ) ) {
				$out[] = $file;
			}
		}
		rsort( $out );
		return $out;
	}

	/** Every regular file under a folder, keyed by its path inside it; symbolic links are left out and flagged in $has_link. Null when unreadable. */
	private static function files( $dir, &$has_link = false ) {
		$has_link = false;
		if ( ! is_dir( $dir ) || is_link( $dir ) ) {
			return array();
		}
		$out = array();
		try {
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
			foreach ( $it as $file ) {
				if ( $file->isLink() ) {
					$has_link = true;
					continue;
				}
				if ( ! $file->isFile() ) {
					continue;
				}
				$out[ str_replace( '\\', '/', substr( $file->getPathname(), strlen( $dir ) + 1 ) ) ] = $file->getPathname();
			}
		} catch ( \UnexpectedValueException $e ) {
			return null;
		}
		return $out;
	}

	/** Delete one archive this request wrote (a no-op for ''). */
	public static function discard( $file ) {
		if ( $file ) {
			@unlink( $file );
		}
	}

	/** Unzip an archive WPVibe wrote into a new folder, refusing any entry that would land outside it. */
	private static function extract( $zip_file, $dest ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
			$zip  = new PclZip( $zip_file );
			$list = $zip->listContent();
			if ( ! is_array( $list ) || ! self::safe_names( wp_list_pluck( $list, 'filename' ) ) || ! self::pclzip_ok( $zip->extract( PCLZIP_OPT_PATH, $dest ), count( $list ) ) ) {
				return self::error( 'restore_failed', __( 'Could not read the theme backup archive.', 'vibe-ai' ) );
			}
			return true;
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_file ) ) {
			return self::error( 'restore_failed', __( 'Could not read the theme backup archive.', 'vibe-ai' ) );
		}
		$names = array();
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$names[] = (string) $zip->getNameIndex( $i );
		}
		$ok = self::safe_names( $names ) && wp_mkdir_p( $dest ) && $zip->extractTo( $dest );
		$zip->close();
		return $ok ? true : self::error( 'restore_failed', __( 'Could not unpack the theme backup archive.', 'vibe-ai' ) );
	}

	/** PclZip returns 0 on failure, but an array even when it skipped entries (too long, write-protected): all must be ok. */
	private static function pclzip_ok( $entries, $expected ) {
		if ( ! is_array( $entries ) || count( $entries ) !== $expected ) {
			return false;
		}
		foreach ( $entries as $entry ) {
			if ( 'ok' !== ( $entry['status'] ?? '' ) ) {
				return false;
			}
		}
		return true;
	}

	private static function safe_names( array $names ) {
		foreach ( $names as $name ) {
			$name = str_replace( '\\', '/', (string) $name );
			if ( '' === $name || '/' === $name[0] || preg_match( '#(^|/)\.\.(/|$)|^[a-z]:#i', $name ) ) {
				return false;
			}
		}
		return (bool) $names;
	}

	/** When an archive was taken, from its name. */
	private static function taken_at( $file ) {
		return preg_match( '/-(\d{4})(\d{2})(\d{2})-(\d{2})(\d{2})(\d{2})-[a-f0-9]{12}\.zip$/', $file, $m )
			? "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}"
			: '?';
	}

	/** Delete a folder WPVibe made, without following symbolic links out of it. */
	private static function remove( $path ) {
		if ( is_link( $path ) || is_file( $path ) ) {
			return @unlink( $path );
		}
		if ( ! is_dir( $path ) ) {
			return true;
		}
		$entries = scandir( $path );
		if ( false === $entries ) {
			return false;
		}
		foreach ( $entries as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				self::remove( "$path/$entry" );
			}
		}
		return @rmdir( $path );
	}

	private static function error( $code, $message, $status = 500 ) {
		return new WP_Error( $code, $message, WPVibe_Error_Contract::data( 'filesystem', false, array( 'status' => $status ) ) );
	}
}
