<?php
/**
 * Draft theme lifecycle management.
 *
 * Handles cloning the active theme into a sandboxed draft,
 * publishing the draft back to live, and cleanup.
 */

defined( 'ABSPATH' ) || exit;

class WPVibe_Draft_Theme {

	/**
	 * Commercial builder themes, by directory slug (lowercased on compare;
	 * Divi ships as wp-content/themes/Divi). Draft creation refuses these:
	 * publish overwrites the live theme directory in place, and the vendor's
	 * next theme update replaces that directory wholesale, silently wiping
	 * every edit. Child themes (divi-child etc.) never match and stay
	 * draftable — the vendor never updates them.
	 */
	const BUILDER_THEMES = array( 'divi', 'extra', 'avada', 'enfold', 'flatsome', 'betheme', 'bridge', 'salient', 'the7', 'x' );

	// Saved Site Editor rows are keyed to a theme slug, not to its files, so a publish leaves them behind.
	const SITE_EDITOR_TYPES = array( 'wp_template', 'wp_template_part', 'wp_global_styles' );

	/** @return array[] Published Site Editor rows saved for a theme slug. */
	public static function saved_customizations( $slug ) {
		$posts = get_posts( array(
			'post_type'      => self::SITE_EDITOR_TYPES,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
			'tax_query'      => array( array( 'taxonomy' => 'wp_theme', 'field' => 'name', 'terms' => $slug ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- a handful of rows per theme.
		) );
		return array_map( function ( $p ) {
			return array( 'id' => (int) $p->ID, 'type' => $p->post_type, 'name' => $p->post_name, 'title' => $p->post_title );
		}, $posts );
	}

	public static function set_aside_slug( $slug ) {
		return $slug . '-wpvibe-set-aside';
	}

	/** Live rows that override what the draft provides (a file or a row saved during the preview), so the preview shows something else. */
	public static function clashing_customizations( $live_slug, $draft_slug ) {
		$draft_rows = self::saved_customizations( $draft_slug );
		$dirs       = self::template_dirs( $draft_slug );
		$shown      = self::shown_styles_row( $live_slug );
		return array_values( array_filter( self::saved_customizations( $live_slug ), function ( $row ) use ( $draft_rows, $dirs, $shown ) {
			// An older styles row shows nothing, so only the one core shows can override the draft.
			if ( 'wp_global_styles' === $row['type'] ) {
				return $row['id'] === $shown && self::has_styles( $row['id'] );
			}
			foreach ( $dirs[ $row['type'] ] as $dir ) {
				if ( is_file( $dir . '/' . $row['name'] . '.html' ) ) {
					return true;
				}
			}
			foreach ( $draft_rows as $draft_row ) {
				if ( $draft_row['type'] === $row['type'] && $draft_row['name'] === $row['name'] ) {
					return true;
				}
			}
			return false;
		} ) );
	}

	/** Core creates an empty published global-styles row whenever the editor or the active-theme REST query runs; only real styles clash. */
	private static function has_styles( $id ) {
		$post = get_post( $id );
		$data = $post ? json_decode( (string) $post->post_content, true ) : null;
		return is_array( $data ) && ( ! empty( $data['styles'] ) || ! empty( $data['settings'] ) );
	}

	/** Template folders of the draft and, for a child draft, of its parent; old folder names included. */
	private static function template_dirs( $draft_slug ) {
		$roots  = array( get_theme_root() . '/' . $draft_slug );
		$header = is_readable( $roots[0] . '/style.css' ) ? get_file_data( $roots[0] . '/style.css', array( 'Template' => 'Template' ) ) : array();
		$parent = trim( (string) ( $header['Template'] ?? '' ) );
		if ( '' !== $parent && $parent === basename( $parent ) && $parent !== $draft_slug ) {
			$roots[] = get_theme_root( $parent ) . '/' . $parent;
		}
		$dirs = array( 'wp_template' => array(), 'wp_template_part' => array() );
		foreach ( $roots as $root ) {
			array_push( $dirs['wp_template'], $root . '/templates', $root . '/block-templates' );
			array_push( $dirs['wp_template_part'], $root . '/parts', $root . '/block-template-parts' );
		}
		return $dirs;
	}

	private static function describe_rows( array $rows ) {
		$labels = array( 'wp_template' => 'template', 'wp_template_part' => 'template part' );
		$names  = array_map( function ( $row ) use ( $labels ) {
			return ( 'wp_global_styles' === $row['type'] ? 'global styles' : $row['name'] . ' ' . $labels[ $row['type'] ] ) . ' #' . $row['id'];
		}, array_slice( $rows, 0, 10 ) );
		return implode( ', ', $names ) . ( count( $rows ) > 10 ? sprintf( ', and %d more', count( $rows ) - 10 ) : '' );
	}

	/** WordPress.org offers its release to any folder of the same name unless Update URI points elsewhere. */
	public static function wporg_update_warning( $slug, $dir, $published = false ) {
		// No refresh when the transient is missing (a publish deletes it): that is a blocking request to api.wordpress.org.
		$updates = get_site_transient( 'update_themes' );
		if ( ! is_object( $updates ) || ! ( isset( $updates->response[ $slug ] ) || isset( $updates->no_update[ $slug ] ) ) ) {
			return null;
		}
		$header = is_readable( $dir . '/style.css' ) ? get_file_data( $dir . '/style.css', array( 'UpdateURI' => 'Update URI' ) ) : array();
		$uri    = trim( (string) ( $header['UpdateURI'] ?? '' ) );
		if ( '' !== $uri && ! preg_match( '#^(?:https?://)?(?:www\.)?(?:wordpress\.org|w\.org)(?:/|$)#i', $uri ) ) {
			return null;
		}
		return sprintf(
			/* translators: %s: theme folder name */
			$published
				? __( 'The folder name \'%s\' matches a theme that WordPress.org updates, so a WordPress.org update for that theme can replace these files. If this is now your own design, add the line "Update URI: false" to the style.css header in a new draft and publish it.', 'vibe-ai' )
				: __( 'The folder name \'%s\' matches a theme that WordPress.org updates, so after publish a WordPress.org update for that theme can replace these files. If this is now your own design, add the line "Update URI: false" to the draft\'s style.css header before you publish.', 'vibe-ai' ),
			$slug
		);
	}

	/**
	 * Create a draft theme by cloning the active theme.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function create() {
		return WPVibe_Draft_Lock::run( function () {
			return $this->create_locked();
		} );
	}

	private function create_locked() {
		$existing = get_option( 'wpvibe_draft_theme' );
		if ( $existing && ! WPVibe_Draft_Lock::valid_draft() ) {
			return WPVibe_Draft_Lock::conflict();
		}
		if ( $existing && is_dir( get_theme_root() . '/' . $existing ) ) {
			return rest_ensure_response( array(
				'status'     => 'exists',
				'draft_slug' => $existing,
				'message'    => __( 'A draft theme already exists. Continue editing it; creating a draft does not replace existing work.', 'vibe-ai' ),
			) );
		}

		$stale = '';
		if ( $existing || get_option( 'wpvibe_draft_source' ) ) {
			$stale = self::stale_record();
			if ( '' === $stale ) {
				$untracked = self::untracked_dir();
				if ( '' === $untracked ) {
					return WPVibe_Draft_Lock::conflict();
				}
				return self::adoptable( $untracked ) ? $this->adopt( $untracked ) : self::untracked_error( $untracked );
			}
		}

		$active_slug = (string) get_option( 'stylesheet' );
		if ( ! WPVibe_Draft_Lock::valid_slug( $active_slug ) || is_link( get_theme_root() . '/' . $active_slug ) ) {
			return WPVibe_Draft_Lock::conflict();
		}
		if ( in_array( strtolower( $active_slug ), self::BUILDER_THEMES, true ) ) {
			return new WP_Error(
				'builder_theme',
				sprintf(
					/* translators: %s: theme slug */
					__( 'Refused: the active theme \'%s\' is a commercial builder theme. Publishing a draft overwrites the live theme directory in place, and the theme\'s next vendor update replaces that directory wholesale, silently wiping every edit made this way. Make design changes in the builder\'s own settings UI; for custom CSS or template overrides, install and activate the vendor\'s child theme (then a draft of the child is safe), or build a separate theme with create_classic_theme.', 'vibe-ai' ),
					$active_slug
				),
				WPVibe_Error_Contract::data( 'not_supported', false, array( 'status' => 409 ) )
			);
		}
		$draft_slug  = $active_slug . '-wpvibe-draft';
		$theme_root  = get_theme_root();
		$source      = $theme_root . '/' . $active_slug;
		$dest        = $theme_root . '/' . $draft_slug;

		if ( ! is_dir( $source ) ) {
			return new WP_Error( 'no_theme', __( 'Active theme directory not found.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'not_found', false, array( 'status' => 404 ) ) );
		}

		if ( '' !== $stale ) {
			if ( ! self::clear_draft_records() ) {
				return WPVibe_Draft_Lock::conflict();
			}
			self::record_draft_event( 'stale_cleared' );
		}

		// Reserve exclusively; an orphan with content of its own belongs to the user.
		if ( ( file_exists( $dest ) || is_link( $dest ) ) && ! $this->clear_partial_copy( $source, $dest, $draft_slug ) ) {
			return $this->orphan_error( $draft_slug );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		if ( ! @mkdir( $dest, 0755 ) ) {
			return WPVibe_Draft_Lock::conflict();
		}

		try {
			$result = $this->copy_directory( $source, $dest );
		} catch ( \Throwable $e ) {
			// A site error handler that turns warnings into exceptions must not skip the cleanup.
			$result = new WP_Error(
				'copy_failed',
				sprintf(
					/* translators: %s: PHP error message */
					__( 'Copying the theme into the draft failed: %s', 'vibe-ai' ),
					self::site_relative( $e->getMessage() )
				),
				WPVibe_Error_Contract::data( 'filesystem', false, array( 'status' => 500 ) )
			);
		}
		if ( is_wp_error( $result ) ) {
			return $this->abandon_partial_dir( $dest, $result );
		}

		// Update the theme name in style.css so WP recognizes it.
		$style_path = $dest . '/style.css';
		$fs         = wpvibe_fs();
		if ( $fs && $fs->exists( $style_path ) ) {
			$style = $fs->get_contents( $style_path );
			if ( false !== $style ) {
				// Strip any pre-existing draft suffix so it doesn't accumulate across cycles.
				$source_name = preg_replace( '/(\s*\((?:WPVibe )?Draft\))+$/', '', wp_get_theme( $active_slug )->get( 'Name' ) );
				$suffix = WPVibe_White_Label::is_hidden() ? ' (Draft)' : ' (WPVibe Draft)';
				$style = preg_replace( '/^Theme Name:\s*.+$/m', 'Theme Name: ' . $source_name . $suffix, $style );
				$fs->put_contents( $style_path, $style, FS_CHMOD_FILE );
			}
		}

		// Store the draft theme slug and original theme for rollback.
		$registered = WPVibe_Draft_Lock::register( $draft_slug, $active_slug );
		if ( is_wp_error( $registered ) ) {
			return $registered;
		}

		list( $parked, $stuck ) = self::park_draft_rows( $draft_slug, $active_slug );

		WPVibe_Change_Tracker::mark( array(
			'summary'      => 'Draft theme created',
			'action_label' => 'Preview Theme',
		) );

		$message = __( 'Draft theme created. File operations are now scoped to the draft.', 'vibe-ai' );
		if ( '' !== $stale ) {
			$message = sprintf(
				/* translators: %s: theme directory name */
				__( 'A stale draft record for \'%s\' was cleared first: its folder was already gone, so no files were deleted and earlier unpublished draft file edits are not recoverable. The new draft\'s files are a fresh copy of the live theme.', 'vibe-ai' ),
				$stale
			) . ' ' . $message;
		}
		if ( $parked || $stuck ) {
			$message .= ' ' . self::parked_note( $parked, $stuck, $active_slug );
		}
		$response = array(
			'status'      => 'created',
			'draft_slug'  => $draft_slug,
			'source_slug' => $active_slug,
			'message'     => $message,
		);
		if ( '' !== $stale ) {
			$response['stale_record_cleared'] = $stale;
		}
		return rest_ensure_response( $response );
	}

	/** The recorded draft slug when nothing exists at its path, so clearing the record cannot lose files; '' otherwise. */
	private static function stale_record() {
		$draft  = get_option( 'wpvibe_draft_theme' );
		$source = get_option( 'wpvibe_draft_source' );
		if ( $draft ) {
			if ( ! WPVibe_Draft_Lock::valid_draft() ) {
				return '';
			}
			$slug = $draft;
		} elseif ( WPVibe_Draft_Lock::valid_slug( $source ) ) {
			$slug = $source . '-wpvibe-draft';
		} else {
			return '';
		}
		$path = get_theme_root() . '/' . $slug;
		clearstatcache( true, $path );
		if ( $slug === get_option( 'stylesheet' ) || $slug === get_option( 'template' ) || file_exists( $path ) || is_link( $path ) ) {
			return '';
		}
		return $slug;
	}

	/** '<source>-wpvibe-draft' when only the source record survives and a real directory sits at that path; '' otherwise. */
	private static function untracked_dir() {
		$source = get_option( 'wpvibe_draft_source' );
		if ( get_option( 'wpvibe_draft_theme' ) || ! WPVibe_Draft_Lock::valid_slug( $source ) ) {
			return '';
		}
		$slug = $source . '-wpvibe-draft';
		$path = get_theme_root() . '/' . $slug;
		clearstatcache( true, $path );
		if ( is_link( $path ) || ! is_dir( $path ) || $slug === get_option( 'stylesheet' ) || $slug === get_option( 'template' ) ) {
			return '';
		}
		return $slug;
	}

	/** The source record proves the folder is ours; it is taken back only when it is exactly what create would have made. */
	private static function adoptable( $slug ) {
		$root   = get_theme_root();
		$source = (string) get_option( 'wpvibe_draft_source' );
		return $source === get_option( 'stylesheet' )
			&& is_dir( $root . '/' . $source ) && ! is_link( $root . '/' . $source )
			&& is_file( $root . '/' . $slug . '/style.css' ) && ! is_link( $root . '/' . $slug . '/style.css' );
	}

	private function adopt( $slug ) {
		update_option( 'wpvibe_draft_theme', $slug );
		if ( get_option( 'wpvibe_draft_theme' ) !== $slug || ! WPVibe_Draft_Lock::valid_draft() ) {
			delete_option( 'wpvibe_draft_theme' );
			return WPVibe_Draft_Lock::conflict();
		}
		return rest_ensure_response( array(
			'status'      => 'adopted',
			'draft_slug'  => $slug,
			'source_slug' => (string) get_option( 'wpvibe_draft_source' ),
			'message'     => sprintf(
				/* translators: %s: theme directory name */
				__( 'Found the folder of an earlier WPVibe draft (\'%s\') whose record had been lost, and re-registered it as the draft. No files were changed. It keeps any unpublished edits from before, and it may predate recent changes to the live theme, so check it with the user before publishing. To start over from the live theme instead, delete_draft_theme deletes this folder and its edits.', 'vibe-ai' ),
				$slug
			),
		) );
	}

	private static function untracked_error( $slug ) {
		$source = (string) get_option( 'wpvibe_draft_source' );
		$message = sprintf(
			/* translators: 1: theme directory name, 2: theme directory name */
			__( 'The folder \'wp-content/themes/%1$s\' is left from an earlier WPVibe draft of \'%2$s\', but no draft is on record for it, so WPVibe left it untouched. It may hold unpublished edits. Ask the user whether to keep it. To discard it, remove or rename the folder with the host file manager or SFTP (copy anything worth keeping first); create_draft_theme then clears the leftover record and starts a fresh draft.', 'vibe-ai' ),
			$slug,
			$source
		);
		if ( $source !== get_option( 'stylesheet' ) && is_file( get_theme_root() . '/' . $slug . '/style.css' ) ) {
			$message .= ' ' . sprintf(
				/* translators: %s: theme directory name */
				__( 'To keep editing it instead, \'%s\' must be the active theme when create_draft_theme runs; it then re-registers the folder as the draft.', 'vibe-ai' ),
				$source
			);
		}
		return new WP_Error(
			'draft_conflict',
			$message,
			WPVibe_Error_Contract::data( 'invalid_input', false, array(
				'status'      => 409,
				'reason'      => 'untracked_draft_dir',
				'draft_slug'  => false,
				'source_slug' => $source,
				'draft_dir'   => $slug,
			) )
		);
	}

	private static function clear_draft_records() {
		foreach ( array( 'wpvibe_draft_theme', 'wpvibe_draft_source', 'wpvibe_preview_token', 'wpvibe_preview_token_issued' ) as $key ) {
			delete_option( $key );
		}
		if ( class_exists( 'WPVibe_Preview' ) ) {
			WPVibe_Preview::clear_cookie();
		}
		return ! get_option( 'wpvibe_draft_theme' ) && ! get_option( 'wpvibe_draft_source' );
	}

	/** A draft record whose folder is gone; the data lets the Worker name the exact recovery. */
	public static function missing_error() {
		$draft = (string) get_option( 'wpvibe_draft_theme' );
		return new WP_Error(
			'draft_missing',
			sprintf(
				/* translators: %s: theme directory name */
				__( 'Draft theme directory not found. The draft record points at \'%s\', but that folder is gone, so its unpublished edits cannot be recovered through WPVibe. Run create_draft_theme: it clears the stale record (no files are deleted) and starts a fresh draft from the live theme.', 'vibe-ai' ),
				$draft
			),
			WPVibe_Error_Contract::data( 'not_found', false, array(
				'status'      => 404,
				'reason'      => 'draft_folder_missing',
				'draft_slug'  => $draft,
				'source_slug' => (string) get_option( 'wpvibe_draft_source' ),
			) )
		);
	}

	/** Remove a leftover draft directory only when every file in it is a copy, or truncated copy, of the live theme. */
	private function clear_partial_copy( $source, $dest, $draft_slug ) {
		if ( is_link( $dest ) || ! is_dir( $dest ) || file_exists( $dest . '/style.css' ) || is_link( $dest . '/style.css' ) ) {
			return false;
		}
		$theme = wp_get_theme( $draft_slug );
		if ( $theme && $theme->exists() ) {
			return false;
		}
		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $dest, RecursiveDirectoryIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::SELF_FIRST
			);
			foreach ( $iterator as $item ) {
				$src_path = $source . '/' . $iterator->getSubPathName();
				if ( $item->isLink() || is_link( $src_path ) ) {
					return false;
				}
				if ( $item->isDir() ) {
					if ( ! is_dir( $src_path ) ) {
						return false;
					}
					continue;
				}
				if ( ! $item->isFile() || ! is_file( $src_path ) ) {
					return false;
				}
				if ( $item->getSize() > filesize( $src_path ) || ! $this->is_prefix_copy( $item->getPathname(), $src_path ) ) {
					return false;
				}
			}
		} catch ( \Exception $e ) {
			return false;
		}
		$this->delete_directory( $dest );
		clearstatcache( true, $dest );
		return ! file_exists( $dest ) && ! is_link( $dest );
	}

	private function is_prefix_copy( $copied_path, $source_path ) {
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$copied = @fopen( $copied_path, 'rb' );
		$source = @fopen( $source_path, 'rb' );
		$same   = false !== $copied && false !== $source;
		while ( $same && ! feof( $copied ) ) {
			$chunk = fread( $copied, 65536 );
			if ( false === $chunk ) {
				$same = false;
				break;
			}
			if ( '' === $chunk ) {
				continue;
			}
			$same = fread( $source, strlen( $chunk ) ) === $chunk;
		}
		if ( false !== $copied ) {
			fclose( $copied );
		}
		if ( false !== $source ) {
			fclose( $source );
		}
		// phpcs:enable
		return $same;
	}

	private function orphan_error( $draft_slug ) {
		return new WP_Error(
			'draft_orphan',
			sprintf(
				/* translators: %s: theme directory name */
				__( 'A leftover directory \'wp-content/themes/%s\' blocks creating the draft. It is not the draft on record, and WPVibe only removes a leftover that is an unmodified partial copy of the live theme, so it was left in place. Remove or rename it with the host file manager or SFTP (copy anything you want to keep first), then create the draft again.', 'vibe-ai' ),
				$draft_slug
			),
			WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 409, 'orphan_dir' => $draft_slug ) )
		);
	}

	/**
	 * Publish the draft theme — replace live theme files with draft.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	/** First candidate slug that is actually installed, or '' when none is.
	 * switch_theme() does no validation, so an unchecked slug white-screens the
	 * site just as surely as the fatal the rollback is undoing. */
	private static function first_existing_theme( array $candidates ) {
		foreach ( $candidates as $slug ) {
			$slug = (string) $slug;
			if ( '' === $slug ) {
				continue;
			}
			$theme = wp_get_theme( $slug );
			if ( $theme && $theme->exists() ) {
				return $slug;
			}
		}
		return '';
	}

	/** Put the backup back live and the published tree back into the draft slot. */
	private function rollback_publish( $live_dir, $backup_dir, $draft_dir, $swapped_by_rename, $source_slug, $had_live = true, $previous_active = '' ) {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- failures reported below.
		$draft_back = @rename( $live_dir, $draft_dir ) || ! is_wp_error( $this->copy_directory( $live_dir, $draft_dir ) );
		if ( $draft_back && is_dir( $live_dir ) ) {
			$this->delete_directory( $live_dir );
		}
		if ( ! $had_live ) {
			// A brand-new theme had no live copy to back up: the source slug's
			// directory was just moved into the draft slot, so re-activating it
			// would point the site at nothing. Go back to whatever was active
			// before the publish, and only to a theme that is really on disk.
			wp_clean_themes_cache();
			$target = self::first_existing_theme( array( $previous_active === $source_slug ? '' : $previous_active, WP_DEFAULT_THEME ) );
			if ( '' !== $target ) {
				switch_theme( $target );
			}
			if ( class_exists( 'WPVibe_CLI' ) ) {
				try {
					( new WPVibe_CLI() )->purge_all_caches();
				} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				}
			}
			if ( '' === $target ) {
				return __( 'no installed theme could be activated in its place, so the site is still on the failed theme. Install or restore a working theme from the host file manager.', 'vibe-ai' );
			}
			return $draft_back
				? sprintf( /* translators: %s: theme slug */ __( '\'%s\' is live again and the draft is intact for editing.', 'vibe-ai' ), $target )
				: sprintf( /* translators: %s: theme slug */ __( '\'%s\' is live again, but the draft could not be restored; recreate it.', 'vibe-ai' ), $target );
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$live_back = @rename( $backup_dir, $live_dir ) || ! is_wp_error( $this->copy_directory( $backup_dir, $live_dir ) );
		wp_clean_themes_cache();
		// Back to the theme the site was actually running: publishing activates
		// the source, so when the site was on a different theme a failed publish
		// must not leave that switch in place.
		$restore = self::first_existing_theme( array( $previous_active, $source_slug ) );
		if ( '' !== $restore ) {
			switch_theme( $restore );
		}
		if ( class_exists( 'WPVibe_CLI' ) ) {
			try {
				( new WPVibe_CLI() )->purge_all_caches();
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			}
		}
		if ( $live_back && $draft_back ) {
			return __( 'the previous theme is live again and the draft is intact for editing.', 'vibe-ai' );
		}
		if ( $live_back ) {
			return __( 'the previous theme is live again, but the draft could not be restored; recreate it from the live theme.', 'vibe-ai' );
		}
		return sprintf(
			/* translators: 1: backup dir, 2: live dir */
			__( 'RESTORE FAILED: the previous theme is at \'%1$s\'. Move it to \'%2$s\' by hand (SFTP or the host file manager) to recover the site.', 'vibe-ai' ),
			$backup_dir,
			$live_dir
		);
	}

	/** @param string|null $saved "keep" leaves clashing live Site Editor rows in place; anything else sets them aside. */
	public function publish( $saved = null ) {
		return WPVibe_Draft_Lock::run( function () use ( $saved ) {
			return $this->publish_locked( $saved );
		} );
	}

	private function publish_locked( $saved = null ) {
		if ( get_option( 'wpvibe_draft_theme' ) && ! WPVibe_Draft_Lock::valid_draft() ) {
			return WPVibe_Draft_Lock::conflict();
		}
		$draft_slug  = get_option( 'wpvibe_draft_theme' );
		$source_slug = get_option( 'wpvibe_draft_source' );

		if ( ! $draft_slug || ! $source_slug ) {
			return self::no_draft_error( __( 'No draft theme to publish.', 'vibe-ai' ) );
		}

		$theme_root = get_theme_root();
		$draft_dir  = $theme_root . '/' . $draft_slug;
		$live_dir   = $theme_root . '/' . $source_slug;
		$backup_dir = $theme_root . '/' . $source_slug . '-wpvibe-backup';

		if ( file_exists( $live_dir ) && ! is_dir( $live_dir ) ) {
			return WPVibe_Draft_Lock::conflict();
		}
		if ( is_link( $backup_dir ) || ( file_exists( $backup_dir ) && ! is_dir( $backup_dir ) ) ) {
			return WPVibe_Draft_Lock::conflict();
		}

		if ( ! is_dir( $draft_dir ) ) {
			return self::missing_error();
		}

		// The approved preview rendered without these rows, so setting them aside is the default; "keep" is an explicit choice.
		$clashing = self::clashing_customizations( $source_slug, $draft_slug );
		$saved    = 'keep' === $saved ? 'keep' : 'set_aside';

		// Read now: wp_clean_themes_cache() below deletes the update_themes transient this needs.
		$update_warning = self::wporg_update_warning( $source_slug, $draft_dir, true );

		// Unfiltered: WPVibe_Preview filters 'stylesheet' to the draft slug when a
		// preview token is present, and rolling back onto the draft would
		// re-activate the very theme that just fataled.
		$previous_active = (string) get_option( 'stylesheet' );
		$had_live        = is_dir( $live_dir );

		// Capture the clean original name BEFORE the live dir is overwritten; strips any accumulated draft suffix.
		$original_name = preg_replace( '/(\s*\((?:WPVibe )?Draft\))+$/', '', wp_get_theme( $had_live ? $source_slug : $draft_slug )->get( 'Name' ) );

		// Backup the current live theme. Each step must succeed before the
		// next; if backup fails we abort BEFORE touching the live theme.
		if ( ! $had_live && ( file_exists( $backup_dir ) || is_link( $backup_dir ) ) ) {
			return WPVibe_Draft_Lock::conflict();
		}
		if ( is_dir( $backup_dir ) ) {
			$cleared = $this->delete_directory( $backup_dir );
			if ( is_wp_error( $cleared ) ) {
				return new WP_Error(
					'cleanup_failed',
					sprintf(
						/* translators: 1: directory path, 2: error message */
						__( 'Could not clear the previous backup directory \'%1$s\': %2$s Aborting publish; live theme untouched.', 'vibe-ai' ),
						$backup_dir,
						$cleared->get_error_message()
					),
					WPVibe_Error_Contract::data( 'filesystem', false, array( 'status' => 500 ) )
				);
			}
		}

		// If the live theme directory is missing (a prior interrupted publish or
		// a manual delete left no source on disk), there is nothing to back up
		// and nothing to delete — proceed straight to the swap.
		// Prefer rename() for both legs: it needs write permission only on the
		// themes PARENT directory, not recursive delete rights inside the live
		// theme (shared hosts with mixed file ownership fail delete_directory
		// but allow the rename), and the swap window is near-atomic.
		$swapped_by_rename = false;
		if ( is_dir( $live_dir ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- rename failure falls back to copy+delete below.
			if ( @rename( $live_dir, $backup_dir ) ) {
				$swapped_by_rename = true;
			} else {
				$backup_result = $this->copy_directory( $live_dir, $backup_dir );
				if ( is_wp_error( $backup_result ) ) {
					return new WP_Error(
						'backup_failed',
						sprintf(
							/* translators: %s: error message */
							__( 'Could not back up the live theme before publishing: %s The live theme is intact; nothing was modified. Fix the underlying filesystem issue (permissions, disk space) and retry.', 'vibe-ai' ),
							$backup_result->get_error_message()
						),
						WPVibe_Error_Contract::data( 'filesystem', false, array( 'status' => 500 ) )
					);
				}

				$deleted_live = $this->delete_directory( $live_dir );
				if ( is_wp_error( $deleted_live ) ) {
					return new WP_Error(
						'delete_live_failed',
						sprintf(
							/* translators: 1: live dir, 2: error message, 3: backup dir */
							__( 'Could not delete the live theme directory \'%1$s\' before replacing it: %2$s A complete backup is at \'%3$s\'. To recover, manually remove \'%1$s\' and rename the backup.', 'vibe-ai' ),
							$live_dir,
							$deleted_live->get_error_message(),
							$backup_dir
						),
						WPVibe_Error_Contract::data( 'filesystem', false, array( 'status' => 500 ) )
					);
				}
			}
		}

		// Move draft to live: rename first, copy as fallback.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- rename failure falls back to copy below.
		$result = @rename( $draft_dir, $live_dir ) ? true : $this->copy_directory( $draft_dir, $live_dir );
		if ( is_wp_error( $result ) ) {
			// Rollback. A rename-swap rolls back with the reverse rename (same
			// permission envelope as the swap that just succeeded); the copy
			// path rolls back by copying. If rollback itself fails the site is
			// in an unrecoverable state by automation; we have to tell the
			// user precisely how to fix it by hand.
			$rollback_ok = $swapped_by_rename
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- failure handled below.
				? @rename( $backup_dir, $live_dir )
				: ! is_wp_error( $this->copy_directory( $backup_dir, $live_dir ) );
			if ( ! $rollback_ok ) {
				return new WP_Error(
					'publish_and_rollback_failed',
					sprintf(
						/* translators: 1: publish error, 2: rollback error, 3: backup dir, 4: live dir */
						__( 'Publish failed (%1$s) AND rollback failed (%2$s). The live theme directory may be empty or incomplete; a full copy of the previous theme is at \'%3$s\'. To recover, manually move \'%3$s\' to \'%4$s\'.', 'vibe-ai' ),
						$result->get_error_message(),
						__( 'restoring the backup also failed', 'vibe-ai' ),
						$backup_dir,
						$live_dir
					),
					WPVibe_Error_Contract::data( 'filesystem', false, array( 'status' => 500 ) )
				);
			}
			if ( ! $swapped_by_rename ) {
				$this->delete_directory( $backup_dir );
			}
			return $result;
		}

		// Restore the original theme name in style.css.
		$style_path = $live_dir . '/style.css';
		$fs         = wpvibe_fs();
		if ( $fs && $fs->exists( $style_path ) ) {
			$style = $fs->get_contents( $style_path );
			if ( false !== $style ) {
				$style   = preg_replace( '/^Theme Name:\s*.+$/m', 'Theme Name: ' . $original_name, $style );
				$written = $fs->put_contents( $style_path, $style, FS_CHMOD_FILE );
				if ( ! $written ) {
					return new WP_Error(
						'name_restore_failed',
						sprintf(
							/* translators: 1: source slug, 2: original theme name */
							__( 'Published draft to \'%1$s\', but could not write style.css to restore the original theme name. The live theme is functional but will display \'%2$s (WPVibe Draft)\' instead of its original name in wp-admin. Edit style.css manually to fix.', 'vibe-ai' ),
							$source_slug,
							$original_name
						),
						WPVibe_Error_Contract::data( 'filesystem', false, array( 'status' => 500 ) )
					);
				}
			}
		}

		// Invalidate theme header cache so wp-admin shows the restored name immediately.
		wp_clean_themes_cache();

		// Ensure the original theme is active.
		switch_theme( $source_slug );

		// Before the purge and the health check, so both see the final rows and a failure can undo them.
		$this->settle_log = array();
		try {
			$settled = $this->settle_saved_customizations( $source_slug, $draft_slug, $clashing, $saved );
		} catch ( \Throwable $e ) {
			$unmoved = $this->unsettle();
			$rolled  = $this->rollback_publish( $live_dir, $backup_dir, $draft_dir, $swapped_by_rename, $source_slug, $had_live, $previous_active );
			return new WP_Error(
				'publish_rolled_back',
				$unmoved
					/* translators: 1: error message, 2: post IDs, 3: rollback outcome */
					? sprintf( __( 'Published, then moving the saved Site Editor customizations failed (%1$s). WPVibe could not put back these rows: %2$s. The publish was rolled back: %3$s', 'vibe-ai' ), $e->getMessage(), '#' . implode( ', #', $unmoved ), $rolled )
					/* translators: 1: error message, 2: rollback outcome */
					: sprintf( __( 'Published, then moving the saved Site Editor customizations failed (%1$s), so they were put back and the publish was rolled back: %2$s', 'vibe-ai' ), $e->getMessage(), $rolled ),
				WPVibe_Error_Contract::data( 'wp_core', true, array( 'status' => 500 ) )
			);
		}

		// Publish replaced live templates in place; switch_theme's action only
		// reaches engines that hook it (LiteSpeed, WP Rocket). Flush the rest
		// too, or visitors keep the old theme's cached HTML.
		if ( class_exists( 'WPVibe_CLI' ) ) {
			try {
				( new WPVibe_CLI() )->purge_all_caches();
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- a cache-engine failure must never fail a completed publish.
			}
		}

		// A valid-but-fatal functions.php passes php -l and white-screens the
		// site; render once and put everything back if it does (#78).
		$health = WPVibe_PHP_Guard::render_health_check();
		if ( is_wp_error( $health ) ) {
			$unmoved = $this->unsettle();
			$rolled  = $this->rollback_publish( $live_dir, $backup_dir, $draft_dir, $swapped_by_rename, $source_slug, $had_live, $previous_active );
			if ( $unmoved ) {
				/* translators: %s: post IDs */
				$rolled .= ' ' . sprintf( __( 'WPVibe could not put back these saved Site Editor rows: %s.', 'vibe-ai' ), '#' . implode( ', #', $unmoved ) );
			}
			return new WP_Error(
				'publish_rolled_back',
				sprintf(
					/* translators: 1: what the front page returned, 2: rollback outcome */
					__( 'Published, then the site failed to render (%1$s), so the publish was rolled back: %2$s Fix the draft (a runtime fatal in functions.php is the usual cause: an undefined function, or a function or class declared twice) and publish again.', 'vibe-ai' ),
					$health->get_error_message(),
					$rolled
				),
				WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 422, 'render' => $health->get_error_message() ) )
			);
		}

		// Cleanup.
		$this->delete_directory( $draft_dir );
		// The preview token too: an old preview link must not show the next draft before its leftover rows are parked.
		self::clear_draft_records();
		self::record_draft_event( 'published' );

		WPVibe_Change_Tracker::mark( array(
			'summary'      => 'Draft theme published',
			'action_label' => 'View Site',
			'url'          => home_url( '/' ),
			'admin_url'    => home_url( '/' ),
		) );

		$message = $had_live
			/* translators: 1: theme slug, 2: backup slug */
			? sprintf( __( 'Draft published to \'%1$s\'. Backup saved as \'%2$s\'.', 'vibe-ai' ), $source_slug, $source_slug . '-wpvibe-backup' )
			/* translators: %s: theme slug */
			: sprintf( __( 'New theme \'%s\' published. No existing theme directory was replaced; no theme backup was created.', 'vibe-ai' ), $source_slug );
		if ( $settled['set_aside'] ) {
			$message .= ' ' . sprintf(
				/* translators: 1: list of rows, 2: parking theme slug, 3: post id, 4: live theme slug */
				__( 'Set aside the live theme\'s saved Site Editor customizations that would override this draft (saved global styles, and templates or template parts the draft also has), so they no longer override the published theme: %1$s. They are kept under the theme name \'%2$s\', which no theme uses. To bring one back, run the WP-CLI command "post term set <id> wp_theme %4$s" (for example "post term set %3$d wp_theme %4$s"). If a customization carried over from the preview has the same name, first set that one aside with "post term set <its id> wp_theme %2$s".', 'vibe-ai' ),
				self::describe_rows( $settled['set_aside'] ),
				self::set_aside_slug( $source_slug ),
				$settled['set_aside'][0]['id'],
				$source_slug
			);
		}
		if ( $settled['kept'] ) {
			/* translators: %s: list of rows */
			$message .= ' ' . sprintf( __( 'Kept the live theme\'s saved Site Editor customizations, which override the published files: %s.', 'vibe-ai' ), self::describe_rows( $settled['kept'] ) );
		}
		if ( $settled['moved'] ) {
			/* translators: %s: list of rows */
			$message .= ' ' . sprintf( __( 'Carried the customizations saved during the preview over to the live theme: %s.', 'vibe-ai' ), self::describe_rows( $settled['moved'] ) );
		}
		if ( $settled['left'] ) {
			/* translators: %s: list of rows */
			$message .= ' ' . sprintf(
				/* translators: 1: list of rows, 2: parking theme slug */
				__( 'Set aside these customizations saved during the preview, because the live theme keeps its own saved version: %1$s. They are kept under the theme name \'%2$s\'.', 'vibe-ai' ),
				self::describe_rows( $settled['left'] ),
				self::set_aside_slug( $source_slug )
			);
		}
		if ( $update_warning ) {
			$message .= ' ' . $update_warning;
		}

		return rest_ensure_response( array_filter( array(
			'status'               => 'published',
			'message'              => $message,
			'saved_customizations' => array_filter( $settled ),
			'update_warning'       => $update_warning,
		) ) );
	}

	/** Rows saved during the preview go live; clashing live rows are parked or kept, as asked. */
	private function settle_saved_customizations( $live_slug, $draft_slug, array $clashing, $mode ) {
		$out    = array( 'set_aside' => array(), 'kept' => array(), 'moved' => array(), 'left' => array() );
		$park   = self::set_aside_slug( $live_slug );
		$styles = function ( $row, $slug ) {
			return 'wp_global_styles' === $row['type'] ? array( 'post_name' => 'wp-global-styles-' . rawurlencode( $slug ) ) : array();
		};
		$key    = function ( $row ) {
			return 'wp_global_styles' === $row['type'] ? $row['type'] : $row['type'] . '/' . $row['name'];
		};
		$taken  = array();
		$clash_ids = array_column( $clashing, 'id' );
		// Core reads the newest global-styles row for a slug, so an empty live one could hide the styles the preview showed.
		$empty_styles = array_filter( self::saved_customizations( $live_slug ), function ( $row ) use ( $clash_ids ) {
			return 'wp_global_styles' === $row['type'] && ! in_array( $row['id'], $clash_ids, true );
		} );
		// The other live styles rows show nothing now, but one of them becomes core's pick once the shown row leaves.
		$park_live_styles = function ( $styled_only ) use ( &$empty_styles, &$out, $park, $live_slug, $styles ) {
			foreach ( $empty_styles as $i => $other ) {
				$styled = self::has_styles( $other['id'] );
				if ( $styled_only && ! $styled ) {
					continue;
				}
				$this->retag( $other, $park, $live_slug, $styles( $other, $park ) );
				if ( $styled ) {
					$out['set_aside'][] = $other;
				}
				unset( $empty_styles[ $i ] );
			}
		};
		foreach ( $clashing as $row ) {
			if ( 'set_aside' === $mode ) {
				// Not the trash: wp-admin has no trash screen for these types, and trashing renames the slug.
				$this->retag( $row, $park, $live_slug, $styles( $row, $park ) );
				$out['set_aside'][] = $row;
				if ( 'wp_global_styles' === $row['type'] ) {
					$park_live_styles( true );
				}
			} else {
				$out['kept'][]         = $row;
				$taken[ $key( $row ) ] = true;
			}
		}
		$draft_rows = self::saved_customizations( $draft_slug );
		$shown      = self::shown_styles_row( $draft_slug );
		foreach ( $draft_rows as $row ) {
			// Only the styles row core shows for the draft is what the preview showed; an empty one would also hide the real live row.
			if ( 'wp_global_styles' === $row['type'] && ( $row['id'] !== $shown || ! self::has_styles( $row['id'] ) ) ) {
				$this->retag( $row, $park, $draft_slug, $styles( $row, $park ) );
				continue;
			}
			if ( isset( $taken[ $key( $row ) ] ) ) {
				// Parked as well, or the next draft under this slug would carry it over unseen.
				$this->retag( $row, $park, $draft_slug, $styles( $row, $park ) + self::live_part_refs( $row['id'], $draft_slug, $live_slug ) );
				$out['left'][] = $row;
				continue;
			}
			if ( 'wp_global_styles' === $row['type'] ) {
				$park_live_styles( false );
			}
			$this->retag( $row, $live_slug, $draft_slug, $styles( $row, $live_slug ) + self::live_part_refs( $row['id'], $draft_slug, $live_slug ) );
			$out['moved'][] = $row;
		}
		return $out;
	}

	// The styles row core itself shows for a theme, in core's own order, so a tie resolves the way the site does.
	private static function shown_styles_row( $slug ) {
		$data = WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( wp_get_theme( $slug ) );
		return (int) ( $data['ID'] ?? 0 );
	}

	private $settle_log = array();

	// Fields first: core's template slug filter renames a row whose new theme already has that name.
	private function retag( array $row, $slug, $from, array $fields ) {
		$undo = array( 'id' => $row['id'], 'slug' => $from, 'fields' => array() );
		if ( $fields ) {
			$post = get_post( $row['id'] );
			foreach ( array_keys( $fields ) as $field ) {
				$undo['fields'][ $field ] = $post ? $post->$field : '';
			}
			$updated = wp_update_post( wp_slash( array( 'ID' => $row['id'] ) + $fields ), true );
			// Logged only once something changed, so a rollback never names a row that did not move.
			if ( is_wp_error( $updated ) ) {
				throw new RuntimeException( sprintf( 'post #%d: %s', $row['id'], $updated->get_error_message() ) );
			}
		}
		$this->settle_log[] = $undo;
		if ( ! self::set_theme_term( $row['id'], $slug ) ) {
			self::set_theme_term( $row['id'], $from );
			throw new RuntimeException( sprintf( 'post #%d: could not move it to theme %s (its themes now: %s)', $row['id'], $slug, implode( ', ', self::theme_terms( $row['id'] ) ) ) );
		}
	}

	// Add, confirm, then drop the others: core does not check its relationship INSERT, so a failed write must leave the row where it was.
	private static function set_theme_term( $post_id, $slug ) {
		$added = wp_set_object_terms( $post_id, $slug, 'wp_theme', true );
		if ( is_wp_error( $added ) || ! in_array( $slug, self::theme_terms( $post_id ), true ) ) {
			return false;
		}
		$others = array_values( array_diff( self::theme_terms( $post_id ), array( $slug ) ) );
		if ( $others ) {
			$removed = wp_remove_object_terms( $post_id, $others, 'wp_theme' );
			if ( is_wp_error( $removed ) || ! $removed ) {
				return false;
			}
		}
		return array( $slug ) === self::theme_terms( $post_id );
	}

	// Rows saved under a draft slug outlive the draft; parked, so the next draft under that slug does not show them unseen.
	private static function park_draft_rows( $draft_slug, $live_slug ) {
		$park  = self::set_aside_slug( $live_slug );
		$moved = array();
		$stuck = array();
		foreach ( self::saved_customizations( $draft_slug ) as $row ) {
			$post   = get_post( $row['id'] );
			$fields = self::live_part_refs( $row['id'], $draft_slug, $live_slug );
			if ( 'wp_global_styles' === $row['type'] ) {
				$fields['post_name'] = 'wp-global-styles-' . rawurlencode( $park );
			}
			$old = array();
			foreach ( array_keys( $fields ) as $field ) {
				$old[ $field ] = $post ? $post->$field : '';
			}
			// Core's empty styles row is no customization worth naming.
			$named = 'wp_global_styles' !== $row['type'] || self::has_styles( $row['id'] );
			if ( $fields && is_wp_error( wp_update_post( wp_slash( array( 'ID' => $row['id'] ) + $fields ), true ) ) ) {
				if ( $named ) {
					$stuck[] = $row;
				}
				continue;
			}
			if ( ! self::set_theme_term( $row['id'], $park ) ) {
				self::set_theme_term( $row['id'], $draft_slug );
				if ( $old ) {
					wp_update_post( wp_slash( array( 'ID' => $row['id'] ) + $old ) );
				}
				if ( $named ) {
					$stuck[] = $row;
				}
				continue;
			}
			if ( $named ) {
				$moved[] = $row;
			}
		}
		return array( $moved, $stuck );
	}

	// Template-part blocks saved in the preview name the draft slug and render nothing once it is gone.
	private static function live_part_refs( $post_id, $draft_slug, $live_slug ) {
		$post    = get_post( $post_id );
		$content = $post ? (string) $post->post_content : '';
		// serialize_block_attributes() writes "--" as \u002d\u002d.
		$enc     = function ( $slug ) {
			return str_replace( '--', '\\u002d\\u002d', $slug );
		};
		$renamed = str_replace(
			array( '"theme":"' . $draft_slug . '"', '"theme":"' . $enc( $draft_slug ) . '"' ),
			array( '"theme":"' . $live_slug . '"', '"theme":"' . $enc( $live_slug ) . '"' ),
			$content
		);
		return $renamed !== $content ? array( 'post_content' => $renamed ) : array();
	}

	private static function parked_note( array $parked, array $stuck, $live_slug ) {
		$note = ! $parked ? '' : sprintf(
			/* translators: 1: list of rows, 2: parking theme slug, 3: live theme slug */
			__( 'Set aside the customizations saved during a draft preview: %1$s. They are kept under the theme name \'%2$s\', which no theme uses. To bring back a template or template part, run the WP-CLI command "post term set <id> wp_theme %3$s".', 'vibe-ai' ),
			self::describe_rows( $parked ),
			self::set_aside_slug( $live_slug ),
			$live_slug
		);
		if ( in_array( 'wp_global_styles', array_column( $parked, 'type' ), true ) ) {
			$note .= ' ' . __( 'A global styles row comes back the same way, and it replaces the live styles when it is newer than the live global styles row, because WordPress uses the newest one.', 'vibe-ai' );
		}
		if ( $stuck ) {
			/* translators: %s: list of rows */
			$note .= ' ' . sprintf( __( 'Could not set aside these customizations, which stay under the draft theme name, so a preview of a draft under that name shows them: %s.', 'vibe-ai' ), self::describe_rows( $stuck ) );
		}
		return trim( $note );
	}

	private static function theme_terms( $post_id ) {
		$names = wp_get_object_terms( $post_id, 'wp_theme', array( 'fields' => 'names' ) );
		return is_array( $names ) ? array_values( array_map( 'strval', $names ) ) : array();
	}

	/** @return int[] Rows that could not be put back. */
	private function unsettle() {
		$unmoved = array();
		foreach ( array_reverse( $this->settle_log ) as $undo ) {
			// Term back first, so the fields are written where the row's name is unique, as in retag().
			$ok = self::set_theme_term( $undo['id'], $undo['slug'] );
			if ( $undo['fields'] && is_wp_error( wp_update_post( wp_slash( array( 'ID' => $undo['id'] ) + $undo['fields'] ), true ) ) ) {
				$ok = false;
			}
			if ( ! $ok ) {
				$unmoved[] = (int) $undo['id'];
			}
		}
		$this->settle_log = array();
		return array_values( array_unique( $unmoved ) );
	}

	/**
	 * Remember how the last draft ended so a later no_draft error can say
	 * WHY there is no draft (agents kept asking for previews of drafts they
	 * had just published or deleted, then looped on the bare message).
	 */
	public static function record_draft_event( $action ) {
		update_option( 'wpvibe_last_draft_event', array( 'action' => $action, 'at' => time() ), false );
	}

	/**
	 * Build a no_draft WP_Error whose message explains what happened to the
	 * last draft, when the plugin knows.
	 *
	 * @param string $base Base message ending in a period.
	 * @return WP_Error
	 */
	public static function no_draft_error( $base ) {
		$event = get_option( 'wpvibe_last_draft_event' );
		$extra = array( 'status' => 400 );
		if ( is_array( $event ) && ! empty( $event['action'] ) && ! empty( $event['at'] ) ) {
			$when = human_time_diff( (int) $event['at'], time() );
			$base .= 'published' === $event['action']
				/* translators: %s: human-readable time difference */
				? ' ' . sprintf( __( 'The last draft was published %s ago; those changes are already on the live site, so no draft is needed to view them.', 'vibe-ai' ), $when )
				/* translators: %s: human-readable time difference */
				: ( 'stale_cleared' === $event['action']
					/* translators: %s: human-readable time difference */
					? ' ' . sprintf( __( 'A stale draft record was cleared %s ago because its folder was already gone; no files were deleted. Only create a new draft if new edits are wanted.', 'vibe-ai' ), $when )
					/* translators: %s: human-readable time difference */
					: ' ' . sprintf( __( 'The last draft was deleted %s ago; its unpublished edits are gone. Only create a new draft if new edits are wanted.', 'vibe-ai' ), $when ) );
			$extra['last_draft_action'] = $event['action'];
			$extra['last_draft_at']     = (int) $event['at'];
		}
		return new WP_Error( 'no_draft', $base, WPVibe_Error_Contract::data( 'not_found', false, $extra ) );
	}

	/**
	 * Generate a preview URL for the draft theme.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function preview_url() {
		return WPVibe_Draft_Lock::run( function () {
			return $this->preview_url_locked();
		} );
	}

	private function preview_url_locked() {
		if ( get_option( 'wpvibe_draft_theme' ) && ! WPVibe_Draft_Lock::valid_draft() ) {
			return WPVibe_Draft_Lock::conflict();
		}
		$draft_slug = get_option( 'wpvibe_draft_theme' );
		if ( ! $draft_slug ) {
			return self::no_draft_error( __( 'No draft theme to preview.', 'vibe-ai' ) );
		}
		if ( ! is_dir( get_theme_root() . '/' . $draft_slug ) ) {
			return self::missing_error();
		}

		// Reuse the existing token if it's still within its 24h TTL, otherwise
		// issue a fresh one. Keeps already-shared preview URLs stable until
		// they actually expire, while capping how long any leaked URL works.
		$token  = get_option( 'wpvibe_preview_token' );
		$issued = (int) get_option( 'wpvibe_preview_token_issued', 0 );
		if ( ! $token || ! $issued || ( time() - $issued ) > DAY_IN_SECONDS ) {
			$token = wp_generate_password( 32, false );
			update_option( 'wpvibe_preview_token', $token );
			update_option( 'wpvibe_preview_token_issued', time() );
		}

		$url = add_query_arg( 'wpvibe_preview', $token, home_url( '/' ) );

		$source = (string) get_option( 'wpvibe_draft_source' );
		return rest_ensure_response( array_filter( array(
			'preview_url'         => $url,
			'draft_slug'          => $draft_slug,
			'live_customizations' => '' !== $source ? self::clashing_customizations( $source, $draft_slug ) : array(),
			'update_warning'      => '' !== $source ? self::wporg_update_warning( $source, get_theme_root() . '/' . $draft_slug ) : null,
		) ) );
	}

	/**
	 * Delete the draft theme and clean up.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete() {
		return WPVibe_Draft_Lock::run( function () {
			return $this->delete_locked();
		} );
	}

	private function delete_locked() {
		if ( get_option( 'wpvibe_draft_theme' ) && ! WPVibe_Draft_Lock::valid_draft() ) {
			return WPVibe_Draft_Lock::conflict();
		}
		$draft_slug = get_option( 'wpvibe_draft_theme' );
		if ( ! $draft_slug ) {
			$stale = get_option( 'wpvibe_draft_source' ) ? self::stale_record() : '';
			if ( '' === $stale ) {
				$untracked = get_option( 'wpvibe_draft_source' ) ? self::untracked_dir() : '';
				return '' === $untracked
					? self::no_draft_error( __( 'No draft theme to delete.', 'vibe-ai' ) )
					: self::untracked_error( $untracked );
			}
			if ( ! self::clear_draft_records() ) {
				return WPVibe_Draft_Lock::conflict();
			}
			self::record_draft_event( 'stale_cleared' );
			return rest_ensure_response( array(
				'status'               => 'deleted',
				'stale_record_cleared' => $stale,
				'message'              => sprintf(
					/* translators: %s: theme directory name */
					__( 'Cleared a leftover draft record for \'%s\'. Its folder was already gone, so no files were deleted. create_draft_theme can start a new draft now.', 'vibe-ai' ),
					$stale
				),
			) );
		}

		$draft_dir = get_theme_root() . '/' . $draft_slug;
		$had_dir   = is_dir( $draft_dir );
		if ( $had_dir ) {
			$deleted = $this->delete_directory( $draft_dir );
			if ( is_wp_error( $deleted ) ) {
				return $deleted;
			}
		}

		$source = (string) get_option( 'wpvibe_draft_source' );
		list( $parked, $stuck ) = '' !== $source ? self::park_draft_rows( $draft_slug, $source ) : array( array(), array() );
		self::clear_draft_records();
		self::record_draft_event( 'deleted' );

		WPVibe_Change_Tracker::mark( array(
			'summary'      => 'Draft theme deleted',
			'action_label' => 'Refresh',
		) );

		$message = $had_dir
			? __( 'Draft theme removed.', 'vibe-ai' )
			: sprintf(
				/* translators: %s: theme directory name */
				__( 'Draft record cleared. Its folder \'%s\' was already gone, so no files were deleted.', 'vibe-ai' ),
				$draft_slug
			);
		if ( $parked || $stuck ) {
			$message .= ' ' . self::parked_note( $parked, $stuck, $source );
		}
		return rest_ensure_response( array(
			'status'  => 'deleted',
			'message' => $message,
		) );
	}

	/**
	 * Recursively copy a directory.
	 *
	 * @param string $src Source directory.
	 * @param string $dst Destination directory.
	 * @return true|WP_Error
	 */
	public function copy_directory_public( $src, $dst ) {
		return $this->copy_directory( $src, $dst );
	}

	/**
	 * @param string $src Source directory.
	 * @param string $dst Destination directory.
	 * @return true|WP_Error
	 */
	private function copy_directory( $src, $dst ) {
		if ( ! wp_mkdir_p( $dst ) && ! is_dir( $dst ) ) {
			// Fallback: wp_mkdir_p can fail in some environments (e.g. WordPress Studio).
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Fallback for environments where wp_mkdir_p() fails.
			if ( ! @mkdir( $dst, 0755, true ) && ! is_dir( $dst ) ) {
				/* translators: %s: directory path */
				return new WP_Error( 'copy_failed', sprintf( __( 'Could not create directory: %s', 'vibe-ai' ), $dst ), WPVibe_Error_Contract::data( 'filesystem', false, array( 'status' => 500 ) ) );
			}
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $src, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( $item->isLink() ) {
				return WPVibe_Draft_Lock::conflict();
			}
			$dest_path = $dst . '/' . $iterator->getSubPathName();
			if ( $item->isDir() ) {
				wp_mkdir_p( $dest_path );
			} else {
				error_clear_last();
				if ( ! $this->copy_file( $item->getPathname(), $dest_path ) ) {
					$php_error = error_get_last();
					return self::write_error(
						'copy_failed',
						$dest_path,
						is_array( $php_error ) ? (string) $php_error['message'] : '',
						function ( $path, $contents ) {
							return $this->put_file( $path, $contents );
						}
					);
				}
			}
		}

		return true;
	}

	/**
	 * Copy one file. A seam so tests can stand in for a host that refuses some writes.
	 *
	 * @return bool
	 */
	protected function copy_file( $from, $to ) {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the caller reads the reason from error_get_last().
		return @copy( $from, $to );
	}

	/**
	 * Write one file; the host-policy probe in write_error() uses it.
	 *
	 * @return bool
	 */
	protected function put_file( $path, $contents ) {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return false !== @file_put_contents( $path, $contents );
	}

	/**
	 * Build the error for a file copy or write that failed, keeping PHP's own reason.
	 *
	 * Some managed hosts (WP Engine among them) refuse to let PHP create .php
	 * files at all, while every other file type in the same folder writes fine.
	 * That is a host security rule, and permission advice sends the user the
	 * wrong way, so it gets its own code: a .php write refused with "Permission
	 * denied" in a folder that is writable and accepts a non-PHP probe file.
	 *
	 * @param string   $code      Error code for the ordinary failure (copy_failed, write_failed).
	 * @param string   $dest_path Absolute path that could not be written.
	 * @param string   $php_error PHP's message from error_get_last(), or ''.
	 * @param callable $probe     function( $path, $contents ): bool, writes a file.
	 * @return WP_Error
	 */
	public static function write_error( $code, $dest_path, $php_error, $probe ) {
		$rel    = self::site_relative( $dest_path );
		$reason = self::site_relative( (string) $php_error );
		if ( self::php_write_blocked( $dest_path, $rel, $reason, $probe ) ) {
			return new WP_Error(
				'php_write_blocked',
				sprintf(
					/* translators: 1: file path, 2: PHP error message */
					__( 'This host does not allow WordPress to create PHP files, so draft themes cannot be created on this site. Writing \'%1$s\' was refused (PHP reported: %2$s), while the same folder is writable and accepts other file types. That is a host security rule, used by WP Engine and some other managed hosts, not a file permissions problem: changing file or folder permissions will not help, and retrying fails the same way. Edit the theme with the host\'s own tools (its file manager, SFTP or Git) instead.', 'vibe-ai' ),
					$rel,
					$reason
				),
				WPVibe_Error_Contract::data( 'host_environment', false, array(
					'status' => 500,
					'reason' => 'php_file_creation_blocked',
					'path'   => $rel,
				) )
			);
		}
		$message = sprintf(
			/* translators: %s: file path */
			'write_failed' === $code ? __( 'Could not write theme file: %s', 'vibe-ai' ) : __( 'Failed to copy file: %s', 'vibe-ai' ),
			$rel
		);
		if ( '' !== $reason ) {
			/* translators: %s: PHP error message */
			$message .= ' ' . sprintf( __( '(PHP reported: %s)', 'vibe-ai' ), $reason );
		}
		return new WP_Error( $code, $message, WPVibe_Error_Contract::data( 'filesystem', false, array( 'status' => 500 ) ) );
	}

	/**
	 * A .php write refused as "Permission denied" where a non-PHP write in the same folder succeeds.
	 * PHP names the file it could not open, so a denial naming anything but the
	 * destination (an unreadable source file in copy()) is an ordinary failure.
	 */
	private static function php_write_blocked( $dest_path, $rel, $reason, $probe ) {
		if ( 'php' !== strtolower( pathinfo( $dest_path, PATHINFO_EXTENSION ) ) || false === stripos( $reason, 'permission denied' ) ) {
			return false;
		}
		if ( false === strpos( $reason, '(' . $rel . ')' ) ) {
			return false;
		}
		$dir = dirname( $dest_path );
		if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
			return false;
		}
		$probe_path = $dir . '/.wpvibe-write-probe-' . substr( md5( uniqid( '', true ) ), 0, 12 ) . '.txt';
		$written    = (bool) call_user_func( $probe, $probe_path, 'wpvibe write probe' );
		if ( file_exists( $probe_path ) ) {
			wp_delete_file( $probe_path );
		}
		return $written;
	}

	/** Absolute site paths in a message, shortened to start at wp-content/ (or the WordPress root). */
	public static function site_relative( $text ) {
		$text = (string) $text;
		$map  = array();
		$root = get_theme_root();
		if ( is_string( $root ) && strlen( $root ) > 1 ) {
			$map[ rtrim( $root, '/' ) . '/' ] = 'wp-content/themes/';
		}
		if ( defined( 'WP_CONTENT_DIR' ) && strlen( WP_CONTENT_DIR ) > 1 ) {
			$map[ rtrim( WP_CONTENT_DIR, '/' ) . '/' ] = 'wp-content/';
		}
		if ( defined( 'ABSPATH' ) && strlen( ABSPATH ) > 1 ) {
			$map[ rtrim( ABSPATH, '/' ) . '/' ] = '';
		}
		foreach ( $map as $abs => $short ) {
			$real = realpath( $abs );
			if ( false !== $real && strlen( $real ) > 1 ) {
				$text = str_replace( rtrim( $real, '/' ) . '/', $short, $text );
			}
			$text = str_replace( $abs, $short, $text );
		}
		return $text;
	}

	/**
	 * Remove a failed copy's folder, and say in the error whether that worked.
	 *
	 * @param string   $dir   Absolute path of the partial draft folder.
	 * @param WP_Error $error The failure that stopped the copy.
	 * @return WP_Error
	 */
	public function abandon_partial_dir( $dir, $error ) {
		$deleted = $this->delete_directory( $dir );
		clearstatcache( true, $dir );
		$removed = ! file_exists( $dir ) && ! is_link( $dir );
		$data    = $error->get_error_data();
		$data    = is_array( $data ) ? $data : array();
		$message = $error->get_error_message();
		$data['partial_dir_removed'] = $removed;
		if ( $removed ) {
			$message .= ' ' . __( 'The partial draft folder was removed, and the live theme is unchanged.', 'vibe-ai' );
		} else {
			$message .= ' ' . sprintf(
				/* translators: 1: folder path, 2: reason */
				__( 'The partial draft folder \'%1$s\' could not be removed (%2$s). Remove it with the host file manager or SFTP. The live theme is unchanged.', 'vibe-ai' ),
				self::site_relative( $dir ),
				is_wp_error( $deleted ) ? self::site_relative( $deleted->get_error_message() ) : __( 'it is still there', 'vibe-ai' )
			);
		}
		return new WP_Error( $error->get_error_code(), $message, $data );
	}

	/**
	 * Public wrapper for delete_directory.
	 */
	public function delete_directory_public( $dir ) {
		return $this->delete_directory( $dir );
	}

	/**
	 * Recursively delete a directory.
	 *
	 * @param string $dir Directory to delete.
	 */
	private function delete_directory( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return true;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( $item->isDir() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- No WP alternative for rmdir().
				if ( ! @rmdir( $item->getPathname() ) ) {
					/* translators: %s: directory path */
					return new WP_Error( 'rmdir_failed', sprintf( __( 'Failed to remove directory: %s', 'vibe-ai' ), $item->getPathname() ), WPVibe_Error_Contract::data( 'filesystem', false, array( 'status' => 500 ) ) );
				}
			} else {
				wp_delete_file( $item->getPathname() );
				// wp_delete_file is void; verify by checking the file no longer exists.
				if ( file_exists( $item->getPathname() ) ) {
					/* translators: %s: file path */
					return new WP_Error( 'delete_failed', sprintf( __( 'Failed to delete file: %s', 'vibe-ai' ), $item->getPathname() ), WPVibe_Error_Contract::data( 'filesystem', false, array( 'status' => 500 ) ) );
				}
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- No WP alternative for rmdir().
		if ( ! @rmdir( $dir ) ) {
			/* translators: %s: directory path */
			return new WP_Error( 'rmdir_failed', sprintf( __( 'Failed to remove directory: %s', 'vibe-ai' ), $dir ), WPVibe_Error_Contract::data( 'filesystem', false, array( 'status' => 500 ) ) );
		}

		return true;
	}
}
