<?php
/**
 * The live theme's backup from a draft publish, kept as a zip under uploads instead of a theme folder
 * (WordPress.org review 11-17, #1624: backups must not sit in a public place as executable files).
 *
 * One archive per theme, the newest; restoring one archives the theme it replaces first, so a restore is undone
 * by restoring again. The folder is closed to the web (.htaccess, an empty index.html), but nginx ignores .htaccess, so
 * each archive is also sealed (#1651): encrypted with a key kept in the database and bound to its own name (#1682),
 * useless to whoever downloads it, on any server. It is unpacked only to a private temporary file for a restore.
 *
 * Publish and restore move theme folders aside for a moment (<slug>-wpvibe-backup, -wpvibe-replaced, -wpvibe-restore).
 * A request that dies part way leaves one behind, and it can be the only copy of the theme, so nothing here deletes one
 * blindly: settle() puts the theme back when its folder is missing, keeps a leftover as an archive before removing it,
 * and runs under the draft lock on every admin load that finds one, and before every publish and restore.
 *
 * @package VibeAI
 */

defined( 'ABSPATH' ) || exit;

class WPVibe_Theme_Backup {

	const DIR    = 'vibe-ai';
	const LEGACY = '-wpvibe-backup';
	// What a publish (-wpvibe-backup) or a restore (-wpvibe-replaced, -wpvibe-restore) moves aside in the themes folder.
	const LEFTOVERS = array( '-wpvibe-backup', '-wpvibe-replaced', '-wpvibe-restore' );
	// A leftover is renamed to this (hidden from the themes list) before it is deleted, so a delete that stops part way
	// never leaves a partial theme under a name the next sweep would keep as a backup.
	const TRASH = '.wpvibe-trash-';
	// 1.24.0's run-once migration flag. The sweep that replaced it never reads it; uninstall deletes it.
	const MIGRATED = 'wpvibe_theme_backups_migrated';
	// Where a publish or restore is in its swap, so the sweep finishes or undoes one that died part way.
	const SWAP = 'wpvibe_theme_swap';
	// Set for a day after a sweep that left something it could not settle (a folder holding links, a full disk).
	const SWEEP_WAIT = 'wpvibe_theme_sweep_wait';
	// On a large network the sites using a theme are read over several admin loads; the progress is kept here.
	const SWEEP_SCAN = 'wpvibe_theme_sweep_scan';
	// 1.24.0 kept plain zips here (#1651); they are sealed into DIR and the folder goes.
	const PLAIN_DIR = 'wpvibe-backups';
	const KEY       = 'wpvibe_theme_backup_key';
	const EXT       = '.zip.enc';
	// Version 2 binds each archive to its name (#1682); version 1 (1.24.0, 1.24.1 release candidates) is still read.
	const MAGIC    = "WPVIBE-SEALED-2\n";
	const MAGIC_V1 = "WPVIBE-SEALED-1\n";
	const CHUNK    = 65536;
	// .kept archives (leftovers kept beside a complete backup) are pruned to the newest few per theme.
	const KEPT = 3;
	// libsodium's TAG_MESSAGE. WordPress's sodium_compat defines the PUSH, PULL, REKEY and FINAL tags but not this one, so a
	// host without the sodium extension would fail every archive over one chunk.
	const TAG_MESSAGE = 0;

	/** @var WP_Filesystem_Direct|null */
	private static $fs = null;

	/**
	 * WordPress's direct filesystem, built here rather than through WP_Filesystem(): that returns false on hosts whose
	 * filesystem method is FTP or SSH without stored credentials, and a theme swap, or its rollback, must work there as
	 * rename() always did. Its move() is rename() for a folder, so a swap stays atomic and a symbolic link moves as the link.
	 *
	 * @return WP_Filesystem_Direct
	 */
	public static function fs() {
		if ( null === self::$fs ) {
			if ( ! class_exists( 'WP_Filesystem_Direct', false ) ) {
				require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
				require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
			}
			// What WP_Filesystem() defines; the class reads them when it sets a mode.
			if ( ! defined( 'FS_CHMOD_DIR' ) ) {
				define( 'FS_CHMOD_DIR', ( fileperms( ABSPATH ) & 0777 | 0755 ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- core's constant, defined as WP_Filesystem() does.
			}
			if ( ! defined( 'FS_CHMOD_FILE' ) ) {
				define( 'FS_CHMOD_FILE', ( ( file_exists( ABSPATH . 'index.php' ) ? fileperms( ABSPATH . 'index.php' ) : 0644 ) & 0777 | 0644 ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- core's constant, defined as WP_Filesystem() does.
			}
			self::$fs = new WP_Filesystem_Direct( null );
		}
		return self::$fs;
	}

	/** Move a folder, file or link to a path that must not exist yet ($overwrite replaces a file there). */
	public static function move( $from, $to, $overwrite = false ) {
		return (bool) self::fs()->move( $from, $to, $overwrite );
	}

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
		$fs     = self::fs();
		$guards = array(
			'index.html' => '',
			'.htaccess'  => "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
		);
		foreach ( $guards as $name => $body ) {
			if ( ! file_exists( "$dir/$name" ) && ! $fs->put_contents( "$dir/$name", $body, 0644 ) ) {
				return self::error( 'backup_dir_unavailable', __( 'Could not protect the theme backup folder under uploads.', 'vibe-ai' ) );
			}
		}
		// 1.24.0 wrote its guard as index.php: no PHP file of the plugin's own stays in uploads.
		if ( is_file( "$dir/index.php" ) && "<?php\n// Silence is golden.\n" === $fs->get_contents( "$dir/index.php" ) ) {
			wp_delete_file( "$dir/index.php" );
		}
		return $dir;
	}

	/**
	 * Zip a theme folder as the theme's newest backup. With $prune, its older archives go once this one is written;
	 * publish and restore prune only once they succeed (prune()), so the archive before survives a failed one.
	 *
	 * With $kept the archive is written under a .kept name instead: latest() never picks it and prune() never removes it.
	 *
	 * @return string|WP_Error The archive path; '' when the folder holds no files (nothing to keep).
	 */
	public static function archive( $theme_dir, $slug, $prune = true, $kept = false ) {
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
		if ( ! self::key( true ) ) {
			/* translators: %s: option name */
			return self::error( 'backup_failed', sprintf( __( 'WPVibe could not create or read the key it seals theme backups with (the option %s), so no backup was made.', 'vibe-ai' ), self::KEY ) );
		}
		$file  = sprintf( '%s/%s-%s-%s%s%s', $dir, $slug, gmdate( 'Ymd-His' ), bin2hex( random_bytes( 6 ) ), $kept ? '.kept' : '', self::EXT );
		$plain = self::temp_zip( $dir );
		$ok    = self::privately( function () use ( $plain, $files, $theme_dir ) {
			if ( class_exists( 'ZipArchive' ) ) {
				$zip = new ZipArchive();
				if ( true !== $zip->open( $plain, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
					return false;
				}
				$ok = true;
				foreach ( $files as $relative => $path ) {
					$ok = $ok && $zip->addFile( $path, $relative );
				}
				return $zip->close() && $ok;
			}
			require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
			$zip = new PclZip( $plain );
			return self::pclzip_ok( $zip->create( array_values( $files ), PCLZIP_OPT_REMOVE_PATH, $theme_dir ), count( $files ) );
		} );
		// Through a .part, so a request that dies mid-write never leaves a truncated archive latest() would pick.
		$ok   = $ok && is_file( $plain ) && self::fs()->chmod( $plain, 0600 );
		$part = $file . '.part-' . bin2hex( random_bytes( 6 ) );
		$ok   = $ok && is_file( $plain ) && 0 < filesize( $plain ) && self::seal( $plain, $part, self::bound_to( $file ) ) && self::move( $part, $file );
		wp_delete_file( $plain );
		if ( ! $ok ) {
			wp_delete_file( $part );
			wp_delete_file( $file );
			return self::error( 'backup_failed', __( 'Could not write the theme backup archive under uploads.', 'vibe-ai' ) );
		}
		if ( $prune && ! $kept ) {
			self::prune( $slug, $file );
		}
		return $file;
	}

	/** Drop every archive of the theme but $keep (once the publish or restore that wrote $keep succeeded). */
	public static function prune( $slug, $keep ) {
		foreach ( self::archives( $slug ) as $older ) {
			// Only in $keep's own folder: another site's archives are never pruned from here.
			if ( $older !== $keep && dirname( $older ) === dirname( $keep ) ) {
				wp_delete_file( $older );
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
	 * so restoring again undoes this. The active theme setting is not changed. The caller holds the draft lock.
	 *
	 * @return string|WP_Error The message for the caller.
	 */
	public static function restore( $slug ) {
		// Any folder name a publish accepts (WPVibe_Draft_Lock::valid_slug), but no hidden folder.
		if ( ! WPVibe_Draft_Lock::valid_slug( $slug ) || '.' === $slug[0] ) {
			return self::error( 'invalid_input', __( 'Theme slug required, as the folder name under wp-content/themes.', 'vibe-ai' ), 400 );
		}
		// A publish or restore that died part way on this theme is put right first.
		self::settle( $slug );
		$result = self::restore_swap( $slug );
		self::settle_swap();
		return $result;
	}

	private static function restore_swap( $slug ) {
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
		// What settle() could not put away (a site runs on it, or it holds links): never delete it.
		$left = self::files( $aside );
		if ( null === $left || $left ) {
			/* translators: 1: folder, 2: theme folder */
			return self::error( 'draft_conflict', sprintf( __( 'A previous restore left the theme at \'%1$s\', and WPVibe could not keep it as a backup (a site may use it as its theme, or it holds symbolic links), so nothing was restored. Rename it to \'%2$s\' with SFTP or the host file manager (remove the folder there first if it holds the wrong version), then retry.', 'vibe-ai' ), $aside, $live ), 409 );
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
			self::swap_begin( 'restore', $slug, $archived );
			if ( ! self::move( $live, $aside ) ) {
				self::remove( $staging );
				self::discard( $archived );
				self::swap_clear();
				return self::error( 'filesystem', __( 'Could not move the current theme folder aside, so nothing was restored.', 'vibe-ai' ) );
			}
			self::swap_phase( 'aside' );
		}
		if ( ! self::move( $staging, $live ) ) {
			$back = ! is_dir( $aside ) || self::move( $aside, $live );
			self::remove( $staging );
			if ( $back ) {
				// The current theme is live again.
				self::discard( $archived );
				self::swap_clear();
				return self::error( 'filesystem', __( 'Could not put the restored theme in place; the current theme was left as it was.', 'vibe-ai' ) );
			}
			/* translators: 1: folder the current theme is in, 2: theme folder, 3: theme slug */
			$message = sprintf( __( 'RESTORE FAILED: the current theme is at \'%1$s\'. WPVibe moves it back to \'%2$s\' the next time an administrator opens wp-admin, or before the next "theme restore-backup %3$s"; to bring the site back now, rename it with SFTP or the host file manager.', 'vibe-ai' ), $aside, $live, $slug );
			// Not moved back, the archive is the current theme's only other copy: keep it, under a name latest() never
			// picks, so a retry still restores the backup it was asked for and never prunes that one.
			if ( $archived ) {
				$kept = substr( $archived, 0, -strlen( self::EXT ) ) . '.kept' . self::EXT;
				if ( self::move( $archived, $kept ) ) {
					self::swap_note_archive( $kept );
					self::prune_kept( $slug, dirname( $kept ) );
				}
			}
			return self::error( 'filesystem', $message );
		}
		if ( $archived ) {
			self::swap_phase( 'live' );
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

	// ---------------------------------------------------------------------------------------------------------------
	// The swap record: where a publish or restore is, so one that dies part way is finished or undone (#1624).
	//
	// phase 'archived': the previous theme is whole in its own folder; anything in the aside folder is a copy of it,
	//                   and the archive is a copy of the live theme.
	// phase 'aside':    the previous theme is whole in the aside folder; the live folder is missing or a partial copy.
	// phase 'live':     the new theme is whole in the live folder; the previous one is in the aside folder and the archive.
	// ---------------------------------------------------------------------------------------------------------------

	/** Record that a publish or restore has written $archive and is about to move the live theme aside. */
	public static function swap_begin( $op, $slug, $archive, $draft = '' ) {
		update_site_option( self::SWAP, array(
			'op'      => 'restore' === $op ? 'restore' : 'publish',
			'slug'    => (string) $slug,
			'draft'   => (string) $draft,
			'archive' => (string) $archive,
			'root'    => (string) get_theme_root(),
			'phase'   => 'archived',
			'blog'    => get_current_blog_id(),
			'at'      => time(),
		) );
	}

	/**
	 * @param string $phase    See above.
	 * @param bool   $rollback   A publish is putting the previous theme back: from here a live folder is the new theme to
	 *                           move back into the draft slot, never a finished publish to keep.
	 * @param bool   $draft_back In a rollback: the draft slot holds the published tree whole, so the live folder is a copy.
	 */
	public static function swap_phase( $phase, $rollback = false, $draft_back = false ) {
		$record = self::swap_record();
		if ( $record ) {
			$record['phase'] = $phase;
			if ( $rollback ) {
				$record['rollback']   = true;
				$record['draft_back'] = (bool) $draft_back;
			}
			update_site_option( self::SWAP, $record );
		}
	}

	private static function swap_note_archive( $archive ) {
		$record = self::swap_record();
		if ( $record ) {
			$record['archive'] = (string) $archive;
			update_site_option( self::SWAP, $record );
		}
	}

	public static function swap_clear() {
		delete_site_option( self::SWAP );
	}

	/**
	 * The swap record. $fresh reads it as stored now, past this request's option cache (another request may have written
	 * it): only under the draft lock, since on a single site that drops the autoloaded options from the cache.
	 */
	private static function swap_record( $fresh = false ) {
		if ( ! $fresh ) {
			$record = get_site_option( self::SWAP );
		} elseif ( is_multisite() ) {
			$network = function_exists( 'get_current_network_id' ) ? get_current_network_id() : 1;
			wp_cache_delete( $network . ':' . self::SWAP, 'site-options' );
			wp_cache_delete( $network . ':notoptions', 'site-options' );
		} else {
			wp_cache_delete( self::SWAP, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			wp_cache_delete( 'alloptions', 'options' );
		}
		$record = $fresh ? get_site_option( self::SWAP ) : $record;
		if ( false === $record ) {
			return null;
		}
		$valid = self::valid_record( $record );
		if ( null === $valid ) {
			// Never acted on: the record names folders to move and an archive to delete, and an option can be written by
			// anything that writes options. Cleared, and kept (shortened) for whoever looks into it.
			self::swap_clear();
			update_option( self::SWAP . '_refused', array( 'at' => time(), 'record' => substr( (string) wp_json_encode( $record ), 0, 500 ) ), false );
			do_action( 'wpvibe_theme_swap_refused', $record );
		}
		return $valid;
	}

	/**
	 * The swap record, only when every path it leads to is WPVibe's own: a theme folder name for slug and draft, a
	 * registered theme root, and an archive named for that theme directly inside the archive folder of the site that
	 * wrote it. Null otherwise.
	 */
	private static function valid_record( $record ) {
		if ( ! is_array( $record ) ) {
			return null;
		}
		$record += array( 'op' => 'publish', 'draft' => '', 'archive' => '', 'root' => '', 'phase' => '', 'blog' => 0, 'rollback' => false, 'draft_back' => false );
		$slug    = $record['slug'] ?? null;
		if ( ! WPVibe_Draft_Lock::valid_slug( $slug ) || '.' === $slug[0] || ! in_array( $record['op'], array( 'publish', 'restore' ), true )
			|| ! in_array( $record['phase'], array( 'archived', 'aside', 'live' ), true ) || ! is_string( $record['archive'] ) || ! is_string( $record['root'] ) ) {
			return null;
		}
		if ( '' !== $record['draft'] && ( 'publish' !== $record['op'] || $slug . '-wpvibe-draft' !== $record['draft'] ) ) {
			return null;
		}
		$root  = realpath( $record['root'] );
		$roots = array_merge( array( get_theme_root() ), array_values( (array) ( $GLOBALS['wp_theme_directories'] ?? array() ) ) );
		if ( false === $root || ! in_array( $root, array_map( 'realpath', $roots ), true ) ) {
			return null;
		}
		if ( '' !== $record['archive'] ) {
			$blog = is_multisite() ? (int) $record['blog'] : 0;
			$dir  = $blog && $blog !== get_current_blog_id() ? self::on_blog( $blog, array( __CLASS__, 'archive_dir' ) ) : self::archive_dir();
			$real = '' === $dir ? false : realpath( $dir );
			if ( ! preg_match( '/^' . preg_quote( $slug, '/' ) . '-\d{8}-\d{6}-[a-f0-9]{12}(\.kept)?\.zip\.enc$/', basename( $record['archive'] ) )
				|| false === $real || realpath( dirname( $record['archive'] ) ) !== $real || is_link( $record['archive'] ) ) {
				return null;
			}
		}
		$record['rollback']   = (bool) $record['rollback'];
		$record['draft_back'] = (bool) $record['draft_back'];
		return $record;
	}

	/** Run $work on another site of the network. */
	private static function on_blog( $blog, $work ) {
		switch_to_blog( $blog );
		try {
			return call_user_func( $work );
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Finish or undo the publish or restore the swap record names, from its phase. The caller holds the draft lock, so
	 * no swap is running: the record is from a request that ended, normally or not.
	 *
	 * @return bool|string True when nothing is left to do, 'healed' when the previous theme was put back in place,
	 *                     false when the record stays for a later try.
	 */
	public static function settle_swap() {
		$record = self::swap_record( true );
		if ( ! $record ) {
			return true;
		}
		$root = get_theme_root();
		// Another themes folder's (a site with its own theme root): settled from there.
		if ( $record['root'] !== (string) $root ) {
			return true;
		}
		$slug  = $record['slug'];
		$live  = "$root/$slug";
		$aside = $live . ( 'restore' === $record['op'] ? '-wpvibe-replaced' : self::LEGACY );
		$draft = '' !== $record['draft'] && WPVibe_Draft_Lock::valid_slug( $record['draft'] ) ? "$root/{$record['draft']}" : '';
		$phase = $record['phase'];
		$held  = is_dir( $aside ) && ! is_link( $aside );
		if ( 'archived' === $phase && ! file_exists( $live ) && $held ) {
			$phase = 'aside';
		}
		// The new theme goes in by one rename, and only then is the record updated: a live folder with the draft (or a
		// restore's unpacked backup) gone is the new theme, whole. Not during a rollback, which was taking it out.
		if ( 'aside' === $phase && empty( $record['rollback'] ) && file_exists( $live ) && ( '' === $draft || ! file_exists( $draft ) ) ) {
			$phase = 'live';
		}
		$out = true;
		if ( 'aside' === $phase ) {
			if ( $held ) {
				// The previous theme is whole aside. The live folder is nothing, a partial copy (the draft is still whole),
				// or, in a rollback, the published draft itself, which goes back into the draft slot.
				if ( ! file_exists( $live ) && ! is_link( $live ) ) {
					$cleared = true;
				} elseif ( '' !== $draft && ! file_exists( $draft ) && ! is_link( $draft ) && ! is_link( $live ) ) {
					$cleared = self::move( $live, $draft );
					if ( $cleared && class_exists( 'WPVibe_Draft_Theme' ) ) {
						WPVibe_Draft_Theme::mark_draft( $draft );
					}
				} elseif ( ! empty( $record['rollback'] ) && empty( $record['draft_back'] ) ) {
					// The live folder may be the published tree's only whole copy: it is never deleted to make room.
					$cleared = false;
				} else {
					$cleared = self::trash( $live, true );
				}
				if ( $cleared && self::move( $aside, $live ) ) {
					// The archive is a copy of what is live again: as the newest it would make the next restore put the
					// live theme over itself and prune the real previous version.
					self::discard( $record['archive'] );
					wp_clean_themes_cache();
					$out = 'healed';
				} else {
					$out = false;
				}
			}
		} elseif ( 'live' === $phase ) {
			if ( file_exists( $aside ) || is_link( $aside ) ) {
				// The previous theme: the archive holds it, or it is archived now, before the folder goes.
				$kept = $record['archive'] && is_file( $record['archive'] ) ? true : self::archive( $aside, $slug, false );
				$out  = ! is_wp_error( $kept ) && self::trash( $aside );
			}
		} else {
			// Nothing moved: what is aside is a copy of the live theme, and so is the archive.
			$out = ( ! file_exists( $aside ) && ! is_link( $aside ) ) || self::trash( $aside, true );
			self::discard( $record['archive'] );
		}
		// A restore's unpacked backup goes either way: the archive it came from is still there.
		if ( 'restore' === $record['op'] ) {
			self::trash( "$root/$slug-wpvibe-restore", true );
		}
		if ( false !== $out ) {
			self::swap_clear();
		}
		return $out;
	}

	// ---------------------------------------------------------------------------------------------------------------
	// The sweep: folders a publish or restore left in the themes folder, from this version or an older one.
	// ---------------------------------------------------------------------------------------------------------------

	/** Hooked from vibe-ai.php. */
	public static function register() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_sweep' ) );
	}

	/**
	 * On an admin load (not admin-ajax, which answers anonymous visitors too) by someone who can change themes: a
	 * publish or restore that died part way is put right at once; other leftovers are settled at most daily after a run
	 * that could not settle them all, and on multisite only by a super admin, since the themes folder is the network's.
	 */
	public static function maybe_sweep() {
		if ( wp_doing_ajax() || ! current_user_can( 'switch_themes' ) ) {
			return;
		}
		// A plain zip or a .part a killed request left in the archive folder: nginx does not read its .htaccess.
		self::clear_stale( self::archive_dir() );
		$record  = self::swap_record();
		$folders = self::leftovers();
		if ( ! $record && ! $folders && ! glob( get_theme_root() . '/' . self::TRASH . '*' ) ) {
			return;
		}
		$all = ! is_multisite() || is_super_admin();
		// A theme whose folder is missing next to its leftover is a broken site: that is never left for tomorrow.
		$broken = false;
		foreach ( $folders as $path => $slug ) {
			$broken = $broken || ( '-wpvibe-restore' !== substr( $path, -15 ) && ! file_exists( get_theme_root() . '/' . $slug ) && ! is_link( get_theme_root() . '/' . $slug ) );
		}
		$waiting = (bool) get_site_transient( self::SWEEP_WAIT );
		if ( ! $record && ( ! $all || ( ! $broken && $waiting ) ) ) {
			return;
		}
		// While the sweep waits, only the cheap part runs: the swap record and the themes that lost their folder.
		$heal_only = $waiting;
		if ( $all && ! $waiting ) {
			// Set before the work: a sweep that dies on the time limit (sealing is slow without the sodium extension) is
			// not repeated on every admin load.
			set_site_transient( self::SWEEP_WAIT, 1, 15 * 60 );
		}
		$done = WPVibe_Draft_Lock::run( function () use ( $all, $heal_only ) {
			return $all ? self::settle( null, $heal_only ) : false !== self::settle_swap();
		}, 0 );
		if ( $heal_only || ! $all ) {
			return;
		}
		if ( false === $done ) {
			set_site_transient( self::SWEEP_WAIT, 1, DAY_IN_SECONDS );
		} else {
			// Done, busy (a publish is running) or a large network still being read: the next admin load goes on.
			delete_site_transient( self::SWEEP_WAIT );
		}
	}

	/**
	 * Settle the swap record, then the leftover folders: a theme whose folder is missing gets its leftover back; a
	 * leftover beside a live theme is kept as an archive, then removed; a restore's unpacked backup just goes (its archive
	 * is still there). A folder any site of the network runs on as its theme or parent is a theme now and stays. Also
	 * clears the hidden trash folders a delete left, and plain temporary zips a dead request left (#1682). The caller
	 * holds the draft lock.
	 *
	 * A leftover is decided by its name and place, so it is never just deleted: it is archived first. When the theme
	 * already has a backup, the leftover's archive is kept under a name a restore never picks (.kept) and no prune
	 * removes: a partial folder (a delete that stopped part way) must never replace a complete backup (#1691 review).
	 * On multisite these archives go to the main site's uploads, where a restore from any site looks too.
	 *
	 * @param string|null $only      Only this theme's leftovers (a publish or restore about to run on it); null for all.
	 * @param bool        $heal_only Only put back themes that lost their folder.
	 * @return bool|null Whether nothing is left that should go; null while a large network is still being read.
	 */
	public static function settle( $only = null, $heal_only = false ) {
		$record = self::swap_record( true );
		$done   = false !== self::settle_swap();
		$root   = get_theme_root();
		$in_use = null;
		foreach ( self::leftovers() as $path => $slug ) {
			if ( null !== $only && $slug !== $only ) {
				continue;
			}
			// A swap record that could not be settled still says where that theme is: its folders wait for it.
			if ( ! $done && $record && $record['slug'] === $slug ) {
				continue;
			}
			if ( null === $in_use ) {
				$in_use = self::in_use();
				if ( null === $in_use ) {
					// A large network not read through yet: nothing is moved or removed until every site was.
					return null;
				}
			}
			if ( in_array( basename( $path ), $in_use, true ) ) {
				$done = false;
				continue;
			}
			$live    = "$root/$slug";
			$restore = '-wpvibe-restore' === substr( $path, -15 );
			if ( ! $restore && ! file_exists( $live ) && ! is_link( $live ) ) {
				// The theme's only copy: it goes back in place.
				$moved = self::move( $path, $live );
				$done  = $moved && $done;
				if ( $moved ) {
					wp_clean_themes_cache();
				}
			} elseif ( $heal_only ) {
				continue;
			} elseif ( is_link( $path ) ) {
				// A link is removed, never followed.
				$done = self::remove( $path ) && $done;
			} else {
				$had  = (bool) self::latest( $slug ) || self::network_has_archive( $slug );
				$kept = self::on_main_site( function () use ( $path, $slug, $had ) {
					// The same files as an archive already kept: nothing new to keep, the folder just goes.
					if ( self::matches_an_archive( $path, $slug ) ) {
						return '';
					}
					$file = self::archive( $path, $slug, false, $had || self::latest( $slug ) );
					if ( is_string( $file ) && '' !== $file ) {
						self::prune_kept( $slug, dirname( $file ) );
					}
					return $file;
				} );
				if ( is_wp_error( $kept ) ) {
					// Links inside, or the archive could not be written: it stays, and is tried again later.
					$done = false;
				} elseif ( ! self::trash( $path ) ) {
					self::discard( $kept );
					$done = false;
				}
			}
		}
		if ( null === $only && ! $heal_only ) {
			foreach ( glob( $root . '/' . self::TRASH . '*' ) ?: array() as $trash ) {
				$done = self::remove( $trash ) && $done;
			}
			self::clear_stale( rtrim( get_temp_dir(), '/\\' ) );
			self::clear_stale( self::archive_dir() );
		}
		return $done;
	}

	/** Plain temporary zips and .part files a request that died left in $dir, after an hour (a running one may be writing). */
	private static function clear_stale( $dir ) {
		if ( '' === $dir ) {
			return;
		}
		foreach ( array_merge( glob( $dir . '/wpvibe-theme-*.zip' ) ?: array(), glob( $dir . '/*' . self::EXT . '.part-*' ) ?: array() ) as $stale ) {
			if ( filemtime( $stale ) < time() - HOUR_IN_SECONDS ) {
				wp_delete_file( $stale );
			}
		}
	}

	/** Keep the newest KEPT .kept archives of a theme in $dir. */
	private static function prune_kept( $slug, $dir ) {
		$kept = array();
		foreach ( glob( $dir . '/*.kept' . self::EXT ) ?: array() as $file ) {
			if ( preg_match( '/^' . preg_quote( $slug, '/' ) . '-\d{8}-\d{6}-[a-f0-9]{12}\.kept\.zip\.enc$/', basename( $file ) ) ) {
				$kept[] = $file;
			}
		}
		rsort( $kept );
		foreach ( array_slice( $kept, self::KEPT ) as $old ) {
			self::discard( $old );
		}
	}

	/** On a network, whether any site's archive folder holds a backup of the theme (the usual uploads layout). */
	private static function network_has_archive( $slug ) {
		if ( ! is_multisite() ) {
			return false;
		}
		$main = self::on_main_site( array( __CLASS__, 'archive_dir' ) );
		if ( '' === $main ) {
			return false;
		}
		$base = dirname( $main );
		foreach ( array_merge( glob( $base . '/' . self::DIR . '/*' . self::EXT ) ?: array(), glob( $base . '/sites/*/' . self::DIR . '/*' . self::EXT ) ?: array() ) as $file ) {
			if ( preg_match( '/^' . preg_quote( $slug, '/' ) . '-\d{8}-\d{6}-[a-f0-9]{12}\.zip\.enc$/', basename( $file ) ) ) {
				return true;
			}
		}
		return false;
	}

	/** Whether a folder holds exactly the files of one of the theme's archives in this site's folder (names, sizes, CRCs). */
	private static function matches_an_archive( $folder, $slug ) {
		$has_link = false;
		$files    = self::files( $folder, $has_link );
		$dir      = self::archive_dir();
		if ( ! $files || $has_link || '' === $dir || ! class_exists( 'ZipArchive' ) ) {
			return false;
		}
		$archives = array();
		foreach ( glob( $dir . '/*' . self::EXT ) ?: array() as $file ) {
			if ( preg_match( '/^' . preg_quote( $slug, '/' ) . '-\d{8}-\d{6}-[a-f0-9]{12}(\.kept)?\.zip\.enc$/', basename( $file ) ) ) {
				$archives[] = $file;
			}
		}
		rsort( $archives );
		foreach ( array_slice( $archives, 0, self::KEPT + 1 ) as $archive ) {
			$plain = self::temp_zip( $dir );
			$same  = false;
			if ( self::privately( function () use ( $archive, $plain ) {
				return self::unseal( $archive, $plain );
			} ) ) {
				$zip = new ZipArchive();
				if ( true === $zip->open( $plain ) ) {
					$same = $zip->numFiles === count( $files );
					foreach ( $files as $relative => $path ) {
						$stat = $same ? $zip->statName( $relative ) : false;
						$same = $same && is_array( $stat ) && filesize( $path ) === $stat['size'] && sprintf( '%08x', $stat['crc'] & 0xffffffff ) === hash_file( 'crc32b', $path );
					}
					$zip->close();
				}
			}
			wp_delete_file( $plain );
			if ( $same ) {
				return true;
			}
		}
		return false;
	}

	/** This site's archive folder (not created). */
	private static function archive_dir() {
		$uploads = wp_upload_dir( null, false );
		return empty( $uploads['basedir'] ) ? '' : rtrim( $uploads['basedir'], '/\\' ) . '/' . self::DIR;
	}

	/** Run $work on the network's main site: archives of the network's themes folder live there. */
	private static function on_main_site( $work ) {
		$switch = is_multisite() && ! is_main_site();
		if ( $switch ) {
			switch_to_blog( get_main_site_id() );
		}
		try {
			return call_user_func( $work );
		} finally {
			if ( $switch ) {
				restore_current_blog();
			}
		}
	}

	/** The main site's archive folder, when this is another site of a network; '' otherwise. */
	private static function main_archive_dir() {
		return is_multisite() && ! is_main_site() ? self::on_main_site( array( __CLASS__, 'archive_dir' ) ) : '';
	}

	/** The leftover folders (or links) in the themes folder, path => the theme they belong to. */
	private static function leftovers() {
		$out = array();
		foreach ( glob( get_theme_root() . '/*-wpvibe-*' ) ?: array() as $path ) {
			foreach ( self::LEFTOVERS as $suffix ) {
				$slug = substr( basename( $path ), 0, -strlen( $suffix ) );
				if ( substr( $path, -strlen( $suffix ) ) === $suffix && '' !== $slug && ( is_link( $path ) || is_dir( $path ) ) ) {
					$out[ $path ] = $slug;
					break;
				}
			}
		}
		return $out;
	}

	/**
	 * The theme folders a site runs on, as theme or parent: this site's, or on multisite every site's. Null while a
	 * large network is still being read (a few hundred sites per admin load).
	 *
	 * @return string[]|null
	 */
	private static function in_use() {
		$names = array_merge( array( (string) get_option( 'stylesheet' ), (string) get_option( 'template' ) ), self::parents() );
		if ( ! is_multisite() ) {
			return $names;
		}
		$scan  = wp_is_large_network() ? get_site_transient( self::SWEEP_SCAN ) : false;
		$valid = is_array( $scan ) && is_int( $scan['offset'] ?? null ) && 0 <= $scan['offset'] && is_array( $scan['names'] ?? null );
		$scan  = $valid ? array( 'offset' => $scan['offset'], 'names' => array_values( array_filter( $scan['names'], 'is_string' ) ) ) : array( 'offset' => 0, 'names' => array() );
		$batch = wp_is_large_network() ? 500 : 0;
		$ids   = get_sites( array( 'fields' => 'ids', 'number' => $batch, 'offset' => $scan['offset'] ) );
		foreach ( $ids as $id ) {
			$scan['names'][] = (string) get_blog_option( $id, 'stylesheet' );
			$scan['names'][] = (string) get_blog_option( $id, 'template' );
		}
		if ( $batch && count( $ids ) === $batch ) {
			$scan['offset']  += $batch;
			$scan['names']    = array_values( array_unique( $scan['names'] ) );
			set_site_transient( self::SWEEP_SCAN, $scan, DAY_IN_SECONDS );
			return null;
		}
		delete_site_transient( self::SWEEP_SCAN );
		return array_values( array_unique( array_merge( $names, $scan['names'] ) ) );
	}

	/** The parent every theme in the themes folder names (its Template header), active or not. */
	private static function parents() {
		$out = array();
		foreach ( glob( get_theme_root() . '/*/style.css' ) ?: array() as $css ) {
			$header = get_file_data( $css, array( 'Template' => 'Template' ) );
			if ( '' !== trim( (string) ( $header['Template'] ?? '' ) ) ) {
				$out[] = trim( $header['Template'] );
			}
		}
		return $out;
	}

	/**
	 * Uninstall: the folders WPVibe left in the themes folder go (leftovers, its own drafts, trash, the lock file), except
	 * that a theme which lost its folder gets its leftover back, and a folder any site runs on ($in_use) stays.
	 *
	 * @param string[] $in_use The stylesheet and template of every site.
	 */
	public static function uninstall_folders( array $in_use ) {
		$record = self::swap_record( true );
		// A swap that could not be put right leaves that theme's folders where they are: one may be its only copy.
		$stuck = false === self::settle_swap() && $record ? $record['slug'] : null;
		self::swap_clear();
		$root   = get_theme_root();
		$in_use = array_merge( $in_use, self::parents() );
		foreach ( self::leftovers() as $path => $slug ) {
			if ( $slug === $stuck || in_array( basename( $path ), $in_use, true ) ) {
				continue;
			}
			$live = "$root/$slug";
			if ( '-wpvibe-restore' !== substr( $path, -15 ) && ! file_exists( $live ) && ! is_link( $live ) ) {
				self::move( $path, $live );
			} else {
				self::remove( $path );
			}
		}
		// A '-wpvibe-draft' folder without WPVibe's marker or draft name is someone else's (#1085).
		foreach ( glob( $root . '/*-wpvibe-draft' ) ?: array() as $path ) {
			if ( ! in_array( basename( $path ), $in_use, true ) && ! is_link( $path ) && is_dir( $path ) && WPVibe_Draft_Theme::is_wpvibe_draft( $path ) ) {
				self::remove( $path );
			}
		}
		foreach ( glob( $root . '/' . self::TRASH . '*' ) ?: array() as $trash ) {
			self::remove( $trash );
		}
		// Only now no theme operation can run: the plugin is gone.
		self::remove( $root . '/' . WPVibe_Draft_Lock::LOCK_FILE );
		delete_site_transient( self::SWEEP_WAIT );
		delete_site_transient( self::SWEEP_SCAN );
	}

	/** Remove every backup archive and the folder (uninstall), and the key. */
	public static function remove_all() {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['basedir'] ) ) {
			self::remove( rtrim( $uploads['basedir'], '/\\' ) . '/' . self::DIR );
			self::remove( rtrim( $uploads['basedir'], '/\\' ) . '/' . self::PLAIN_DIR );
		}
		delete_option( self::KEY );
		delete_option( self::MIGRATED );
		delete_transient( self::MIGRATED . '_retry' );
		delete_transient( self::MIGRATED . '_plain_retry' );
	}

	/**
	 * Seal the plain zips 1.24.0 left under uploads/wpvibe-backups (#1651), then drop that folder. Runs in wp-admin,
	 * cron and WP-CLI, and before any archive lookup, so a restore finds them; a zip that can't be sealed stays.
	 */
	public static function migrate_plain( $any_request = false ) {
		if ( ! $any_request && ( ! is_admin() || wp_doing_ajax() ) && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		$uploads = wp_upload_dir( null, false );
		$old     = empty( $uploads['basedir'] ) ? '' : rtrim( $uploads['basedir'], '/\\' ) . '/' . self::PLAIN_DIR;
		if ( ! $old || ! is_dir( $old ) || is_link( $old ) || get_transient( self::MIGRATED . '_plain_retry' ) ) {
			return;
		}
		// Set before the work: without the sodium extension sealing is slow, so a request that dies mid-way waits an hour
		// before the next try, which carries on where it stopped (a sealed zip has left the old folder).
		set_transient( self::MIGRATED . '_plain_retry', 1, HOUR_IN_SECONDS );
		// Under the draft lock, which publish and restore hold, so no other request is writing an archive meanwhile.
		$done = WPVibe_Draft_Lock::run( function () use ( $old ) {
			$dir = self::dir();
			if ( is_wp_error( $dir ) ) {
				return false;
			}
			// A .part is only ever a write that died: nothing else writes one while the lock is held.
			foreach ( (array) glob( $dir . '/*.part-*' ) as $stale ) {
				wp_delete_file( $stale );
			}
			foreach ( (array) glob( $old . '/*.zip' ) as $zip ) {
				if ( ! preg_match( '/-\d{8}-\d{6}-[a-f0-9]{12}(\.kept)?\.zip$/', basename( $zip ) ) ) {
					continue;
				}
				$dest = $dir . '/' . substr( basename( $zip ), 0, -4 ) . self::EXT;
				$part = $dest . '.part-' . bin2hex( random_bytes( 6 ) );
				if ( ! self::seal( $zip, $part, self::bound_to( $dest ) ) || ! self::move( $part, $dest, true ) ) {
					wp_delete_file( $part );
					return false;
				}
				wp_delete_file( $zip );
			}
			// Only WPVibe's own guard files go: anything else in the folder keeps it, and the migration tries again later.
			wp_delete_file( $old . '/index.php' );
			wp_delete_file( $old . '/.htaccess' );
			return self::fs()->rmdir( $old );
		} );
		if ( true === $done ) {
			delete_transient( self::MIGRATED . '_plain_retry' );
		}
	}

	/**
	 * The theme's archives, newest first: this site's, or, on another site of a network that has none, the main site's
	 * (where the sweep keeps the network's leftovers).
	 */
	private static function archives( $slug ) {
		self::migrate_plain( true );
		$out = array();
		foreach ( array_filter( array( self::archive_dir(), self::main_archive_dir() ) ) as $dir ) {
			foreach ( (array) glob( $dir . '/*' . self::EXT ) as $file ) {
				if ( preg_match( '/^' . preg_quote( $slug, '/' ) . '-\d{8}-\d{6}-[a-f0-9]{12}\.zip\.enc$/', basename( $file ) ) ) {
					$out[] = $file;
				}
			}
			if ( $out ) {
				break;
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
		if ( $file && self::is_archive_file( $file ) ) {
			wp_delete_file( $file );
		}
	}

	/** Whether a path is a WPVibe theme archive: its name, a regular file, directly inside a vibe-ai archive folder. */
	private static function is_archive_file( $file ) {
		return is_string( $file ) && ! is_link( $file ) && is_file( $file )
			&& preg_match( '/^[^\/\\\\]+-\d{8}-\d{6}-[a-f0-9]{12}(\.kept)?\.zip\.enc$/', basename( $file ) )
			&& self::DIR === basename( (string) realpath( dirname( $file ) ) );
	}

	/** Unseal an archive to a private temporary zip, then unpack it into a new folder. */
	private static function extract( $sealed, $dest ) {
		$dir   = dirname( $sealed );
		$plain = self::temp_zip( $dir );
		// An archive in the main site's folder is sealed with the main site's key.
		$main  = '' !== self::main_archive_dir() && dirname( $sealed ) === self::main_archive_dir();
		$done  = self::privately( function () use ( $sealed, $plain, $main ) {
			return $main
				? self::on_main_site( function () use ( $sealed, $plain ) {
					return self::unseal( $sealed, $plain );
				} )
				: self::unseal( $sealed, $plain );
		} )
			? self::extract_zip( $plain, $dest )
			: self::error( 'restore_failed', __( 'Could not unlock the theme backup archive: it is damaged, or the key WPVibe keeps for it in the database is gone.', 'vibe-ai' ) );
		wp_delete_file( $plain );
		return $done;
	}

	/**
	 * A temporary path for the plain zip: the temp folder WordPress uses, unless that is inside the site's web root
	 * (WordPress falls back to wp-content when the system one isn't writable). Then the archive folder, closed to the web,
	 * holds it instead, under a 128-bit random name: refusing would stop every publish on such a host (#1682). Created
	 * readable by its owner only (privately()); removed when the request ends, and by the sweep if the request died.
	 */
	private static function temp_zip( $archive_dir ) {
		$tmp = rtrim( get_temp_dir(), '/\\' );
		if ( self::in_web_root( $tmp ) ) {
			$tmp = $archive_dir;
		}
		$path = $tmp . '/wpvibe-theme-' . bin2hex( random_bytes( 16 ) ) . '.zip';
		register_shutdown_function( function () use ( $path ) {
			if ( file_exists( $path ) ) {
				wp_delete_file( $path );
			}
		} );
		return $path;
	}

	/** Whether a folder is inside the WordPress root or wp-content, where the web server may hand files out. */
	private static function in_web_root( $dir ) {
		$real = realpath( $dir );
		if ( false === $real ) {
			return true;
		}
		$real = rtrim( str_replace( '\\', '/', $real ), '/' ) . '/';
		foreach ( array( ABSPATH, defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '' ) as $web ) {
			$web = '' === $web ? false : realpath( $web );
			if ( false !== $web && 0 === strpos( $real, rtrim( str_replace( '\\', '/', $web ), '/' ) . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	/** Run $write with files created readable by their owner only (the plain theme zip, #1682). */
	private static function privately( $write ) {
		$mask = umask( 0077 );
		try {
			return call_user_func( $write );
		} finally {
			umask( $mask );
		}
	}

	/** What a sealed archive is bound to: its own name, which carries the theme and when it was taken (#1682). */
	private static function bound_to( $file ) {
		return 'wpvibe-theme-backup:' . preg_replace( '/(\.kept)?\.zip\.enc$/', '', basename( $file ) );
	}

	/** The key archives are sealed with, created on first use; never replaced, since archives sealed with it would be lost. */
	private static function key( $create ) {
		$stored = (string) get_option( self::KEY, '' );
		if ( '' !== $stored || ! $create ) {
			return self::decode_key( $stored );
		}
		global $wpdb;
		// Not add_option, which overwrites on a duplicate: two requests creating the key at once must both end up with the
		// one stored, read back past any cache.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::KEY, bin2hex( sodium_crypto_secretstream_xchacha20poly1305_keygen() ) ) );
		$stored = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", self::KEY ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
		wp_cache_delete( self::KEY, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		// Nothing read back means no key: no backup is made rather than one sealed with a key that may not be the stored one.
		return self::decode_key( $stored );
	}

	private static function decode_key( $stored ) {
		$stored = (string) $stored;
		return 2 * SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES === strlen( $stored ) && ctype_xdigit( $stored ) ? hex2bin( $stored ) : null;
	}

	// The two stream functions below need file handles: chunked encryption cannot go through WP_Filesystem's whole-file
	// reads and writes without holding the whole theme in memory.

	/** Encrypt a file in chunks (libsodium secretstream; WordPress polyfills it where the extension is missing), bound to $bound. */
	private static function seal( $src, $dest, $bound ) {
		$key = self::key( true );
		$in  = $key ? @fopen( $src, 'rb' ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- chunked encryption needs a handle; a failure returns false.
		$out = $in ? @fopen( $dest, 'wb' ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- chunked encryption needs a handle; a failure returns false.
		$ok  = false;
		if ( $out ) {
			try {
				list( $state, $header ) = sodium_crypto_secretstream_xchacha20poly1305_init_push( $key );
				$ok    = self::write( $out, self::MAGIC . $header );
				$chunk = fread( $in, self::CHUNK ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- chunked encryption needs a handle.
				$ok    = $ok && false !== $chunk;
				do {
					// A failed read is an error, never the end of the file: a truncated archive must not seal as whole.
					$next  = feof( $in ) ? '' : fread( $in, self::CHUNK ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- chunked encryption needs a handle.
					$ok    = $ok && false !== $next && ( '' !== $next || feof( $in ) );
					$final = '' === $next;
					$ok    = $ok && self::write( $out, sodium_crypto_secretstream_xchacha20poly1305_push( $state, $chunk, $bound, $final ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : self::TAG_MESSAGE ) );
					$chunk = $next;
				} while ( $ok && ! $final );
			} catch ( \Throwable $e ) {
				$ok = false;
			}
		}
		$in && fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- chunked encryption needs a handle.
		$ok = $out && fclose( $out ) && $ok; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- chunked encryption needs a handle.
		if ( ! $ok && $out ) {
			wp_delete_file( $dest );
		}
		return $ok;
	}

	/**
	 * Decrypt a sealed archive; false when the key is gone, it was sealed under another name (renamed from another theme,
	 * or an older one put in a newer one's place, #1682), or any chunk fails to authenticate. Archives 1.24.0 sealed carry
	 * no name and are read as they were written.
	 */
	private static function unseal( $src, $dest, $bound = null ) {
		$bound = null === $bound ? self::bound_to( $src ) : $bound;
		$key   = self::key( false );
		$in  = $key ? @fopen( $src, 'rb' ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- chunked decryption needs a handle; a failure returns false.
		$out = $in ? @fopen( $dest, 'wb' ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- chunked decryption needs a handle; a failure returns false.
		$ok  = false;
		if ( $out ) {
			try {
				$head  = (string) fread( $in, strlen( self::MAGIC ) + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- chunked decryption needs a handle.
				$magic = substr( $head, 0, strlen( self::MAGIC ) );
				$bound = self::MAGIC_V1 === $magic ? '' : $bound;
				if ( in_array( $magic, array( self::MAGIC, self::MAGIC_V1 ), true ) && strlen( $head ) === strlen( self::MAGIC ) + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES ) {
					$state = sodium_crypto_secretstream_xchacha20poly1305_init_pull( substr( $head, strlen( self::MAGIC ) ), $key );
					$ok    = true;
					$final = false;
					while ( $ok && ! $final ) {
						$pulled = sodium_crypto_secretstream_xchacha20poly1305_pull( $state, (string) fread( $in, self::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES ), $bound ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- chunked decryption needs a handle.
						$ok     = is_array( $pulled ) && self::write( $out, $pulled[0] );
						$final  = $ok && SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL === $pulled[1];
					}
					// Nothing may follow the final chunk.
					$ok = $ok && '' === (string) fread( $in, 1 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- chunked decryption needs a handle.
				}
			} catch ( \Throwable $e ) {
				$ok = false;
			}
		}
		$in && fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- chunked decryption needs a handle.
		return $out && fclose( $out ) && $ok; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- chunked decryption needs a handle.
	}

	private static function write( $handle, $data ) {
		return strlen( $data ) === fwrite( $handle, $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- chunked encryption needs a handle.
	}

	/** Unzip an archive WPVibe wrote into a new folder, refusing any entry that would land outside it. */
	private static function extract_zip( $zip_file, $dest ) {
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
		return preg_match( '/-(\d{4})(\d{2})(\d{2})-(\d{2})(\d{2})(\d{2})-[a-f0-9]{12}\.zip\.enc$/', $file, $m )
			? "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}"
			: '?';
	}

	/**
	 * Take a folder out of the themes list at once (one rename to a hidden name), then delete it: a delete that stops
	 * part way leaves only the hidden folder, which the next sweep deletes, never a partial theme under its own name.
	 * Where the rename is refused, a $junk folder (a partial copy of a theme that is whole elsewhere) is deleted in place.
	 */
	private static function trash( $path, $junk = false ) {
		if ( is_link( $path ) || is_file( $path ) ) {
			return self::remove( $path );
		}
		if ( ! is_dir( $path ) ) {
			return true;
		}
		$trash = dirname( $path ) . '/' . self::TRASH . bin2hex( random_bytes( 6 ) );
		if ( ! self::move( $path, $trash ) ) {
			return $junk && self::remove( $path );
		}
		self::remove( $trash );
		return true;
	}

	/** Delete a folder WPVibe made, without following symbolic links out of it. */
	private static function remove( $path ) {
		if ( is_link( $path ) ) {
			// The link itself, as a file, whatever it points to.
			return self::fs()->delete( $path, false, 'f' ) || ! is_link( $path );
		}
		if ( is_file( $path ) ) {
			wp_delete_file( $path );
			return ! file_exists( $path );
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
		return self::fs()->rmdir( $path );
	}

	private static function error( $code, $message, $status = 500 ) {
		return new WP_Error( $code, $message, WPVibe_Error_Contract::data( 'filesystem', false, array( 'status' => $status ) ) );
	}
}
