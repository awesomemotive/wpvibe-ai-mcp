<?php
/**
 * WPCode snippet bridge.
 *
 * Creates/updates snippets through WPCode's own snippet class — WPCode is the
 * execution engine; this plugin contains zero eval. Two invariants, server
 * enforced: (1) the snippet is written INACTIVE on create, and an update never
 * raises activation (a request `active` field is ignored entirely — it is
 * never read); markup updates preserve the human's on/off state, while updates
 * to server-executed types (php/universal) always land disabled; (2) activation
 * happens only in wp-admin, by the human, where WPCode runs its own
 * fatal-error check.
 *
 * Dormant writes (the /code-snippet/dormant route) skip the approval panel,
 * because the human's enable in wp-admin is the review. The plugin, not the
 * Worker, decides at execution whether a write qualifies: a create within the
 * per-site cap, or an update to a snippet WPVibe created whose code nobody
 * changed since, that is disabled now, keeping its code type. Anything else is
 * refused with snippet_review_required and goes through the approval panel.
 *
 * @package WPVibe
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPVibe_Code_Snippet {

	const LITE_TYPES = array( 'php', 'html', 'js', 'css', 'universal', 'text' );
	const PRO_TYPES  = array( 'blocks', 'scss' );

	// Fallback when WPCode's auto-insert registry can't be introspected
	// (verified against WPCode Lite 2.3.7 includes/auto-insert/*.php).
	const KNOWN_LITE_LOCATIONS = array(
		'everywhere', 'frontend_only', 'admin_only', 'frontend_cl', 'on_demand',
		'site_wide_header', 'site_wide_body', 'site_wide_footer',
		'before_post', 'after_post', 'before_content', 'after_content',
		'before_paragraph', 'after_paragraph',
		'before_excerpt', 'after_excerpt', 'between_posts',
		'archive_before_post', 'archive_after_post',
		'admin_head', 'admin_footer',
	);

	// Post types whose content WPCode executes. Writes go only through the
	// code_snippet approval flow; every other WPVibe write path refuses them.
	const CODE_POST_TYPES = array( 'wpcode' );

	// WPCode options holding executable output: the active-snippet cache it runs
	// from and the site-wide header/body/footer scripts. Kept in BLOCKED_OPTIONS
	// (write-blocked) and READABLE_BLOCKED_OPTIONS (reads stay open).
	const STORAGE_OPTIONS = array( 'wpcode_snippets', 'ihaf_insert_*' );

	// sha256(id LF code) of the code WPVibe last wrote to a snippet it created. Other WPVibe
	// routes refuse meta writes on wpcode posts, so only this class sets it; a human edit breaks the match.
	const MARKER_META = '_wpvibe_snippet';
	// Unix time of a dormant create, for the per-site cap.
	const DORMANT_CREATED_META = '_wpvibe_dormant_created';
	// WPCode tag shown in its snippet list, so the human can see which snippets the AI wrote.
	const MARKER_TAG = 'wpvibe';

	const DORMANT_CREATE_CAP    = 10;
	const DORMANT_CREATE_WINDOW = 1800;

	public static function write_refusal_text() {
		return __( 'WPCode snippets and their storage cannot be written through this route. Use the code_snippet tool instead: it routes the code through the approval panel, writes the snippet disabled, and leaves activation to the human in wp-admin.', 'vibe-ai' );
	}

	public static function is_code_post_type( $post_type ) {
		// wp_insert_post runs post_type through sanitize_key, so 'WPCode' or 'wp code' lands as wpcode.
		return in_array( sanitize_key( (string) $post_type ), self::CODE_POST_TYPES, true );
	}

	public static function is_storage_option( $name ) {
		return null !== WPVibe_CLI::match_option_name( (string) $name, self::STORAGE_OPTIONS );
	}

	/** A code-storage post, or a revision of one: core redirects a revision's meta writes to its parent. */
	public static function is_code_post( $post ) {
		if ( ! is_object( $post ) || ! isset( $post->post_type ) ) {
			return false;
		}
		if ( 'revision' === $post->post_type && ! empty( $post->post_parent ) ) {
			$post = get_post( (int) $post->post_parent );
			return is_object( $post ) && isset( $post->post_type ) && self::is_code_post_type( $post->post_type );
		}
		return self::is_code_post_type( $post->post_type );
	}

	/**
	 * Refusal for a write that targets a code-storage post, by the requested
	 * post type or by the existing post's real type. Null when the write may go on.
	 *
	 * @return WP_Error|null
	 */
	public static function code_post_write_error( $post_type = '', $post_id = 0 ) {
		$hit = self::is_code_post_type( $post_type );
		if ( ! $hit && $post_id ) {
			$hit = self::is_code_post( get_post( (int) $post_id ) );
		}
		if ( ! $hit ) {
			return null;
		}
		return new WP_Error( 'code_post_refused', self::write_refusal_text(), WPVibe_Error_Contract::data( 'security_gate', false, array( 'status' => 403 ) ) );
	}

	public static function code_marker( $id, $code ) {
		return hash( 'sha256', (int) $id . "\n" . (string) $code );
	}

	/** Created by WPVibe, and its stored code is still exactly what WPVibe last wrote. */
	public static function wpvibe_owns_code( $post ) {
		if ( ! is_object( $post ) || empty( $post->ID ) ) {
			return false;
		}
		$marker = (string) get_post_meta( (int) $post->ID, self::MARKER_META, true );
		return '' !== $marker && hash_equals( $marker, self::code_marker( $post->ID, (string) ( $post->post_content ?? '' ) ) );
	}

	/** Dormant creates on this site in the last window, trashed ones included (trash needs no approval). */
	public static function recent_dormant_creates() {
		$ids = get_posts( array(
			'post_type'        => self::CODE_POST_TYPES[0],
			'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
			'fields'           => 'ids',
			'posts_per_page'   => self::DORMANT_CREATE_CAP,
			'no_found_rows'    => true,
			'cache_results'    => false,
			'suppress_filters' => true,
			'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => self::DORMANT_CREATED_META,
					'value'   => time() - self::DORMANT_CREATE_WINDOW,
					'compare' => '>=',
					'type'    => 'NUMERIC',
				),
			),
		) );
		return is_array( $ids ) ? count( $ids ) : 0;
	}

	/** MySQL named lock serializing dormant creates on this site, so parallel calls cannot overrun the cap. */
	private static function create_lock_name() {
		global $wpdb;
		return 'wpvibe_snippet_create_' . substr( md5( (string) $wpdb->dbname . '|' . (string) $wpdb->prefix ), 0, 16 );
	}

	private static function acquire_create_lock() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', self::create_lock_name() ) );
	}

	private static function release_create_lock() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::create_lock_name() ) );
	}

	public static function existing_code_type( $post ) {
		if ( ! class_exists( 'WPCode_Snippet' ) ) {
			return '';
		}
		$snippet = new WPCode_Snippet( (int) $post->ID );
		return method_exists( $snippet, 'get_code_type' ) ? (string) $snippet->get_code_type() : '';
	}

	/**
	 * Why a dormant write must go through the approval panel instead, or null
	 * when it qualifies. Read at execution, so a snippet the human enabled or
	 * edited after the AI's last write never qualifies.
	 *
	 * @return WP_Error|null
	 */
	public static function dormant_refusal( $type, $existing = null ) {
		if ( null === $existing ) {
			$reason = self::recent_dormant_creates() >= self::DORMANT_CREATE_CAP ? 'create_cap' : null;
		} elseif ( 'draft' !== $existing->post_status ) {
			$reason = 'not_disabled';
		} elseif ( ! self::wpvibe_owns_code( $existing ) ) {
			$reason = 'not_wpvibe_code';
		} elseif ( self::existing_code_type( $existing ) !== $type ) {
			$reason = 'type_change';
		} else {
			$reason = null;
		}
		return null === $reason ? null : self::review_error( $reason );
	}

	private static function review_error( $reason ) {
		$why = array(
			'create_cap'      => __( 'the per-site limit on snippets created without review in 30 minutes was reached', 'vibe-ai' ),
			'busy'            => __( 'another snippet was being created on this site at the same moment', 'vibe-ai' ),
			'not_disabled'    => __( 'the snippet is enabled (or not a plain disabled snippet)', 'vibe-ai' ),
			'not_wpvibe_code' => __( 'the snippet was not written by WPVibe, or its code changed since WPVibe wrote it', 'vibe-ai' ),
			'type_change'     => __( 'the edit changes the snippet\'s code type', 'vibe-ai' ),
		);
		return new WP_Error(
			'snippet_review_required',
			/* translators: %s: reason */
			sprintf( __( 'Nothing was written: this snippet write needs the user\'s approval because %s.', 'vibe-ai' ), $why[ $reason ] ),
			WPVibe_Error_Contract::data( 'approval_flow', false, array( 'status' => 409, 'reason' => $reason ) )
		);
	}

	// WPCode requires this class only under is_admin(), but its always-loaded logger calls it statically on REST.
	public static function load_wpcode_file_cache() {
		if ( is_admin() || class_exists( 'WPCode_File_Cache', false ) || ! defined( 'WPCODE_PLUGIN_PATH' ) ) {
			return false;
		}
		$file = WPCODE_PLUGIN_PATH . 'includes/class-wpcode-file-cache.php';
		if ( ! is_readable( $file ) ) {
			return false;
		}
		require_once $file;
		return class_exists( 'WPCode_File_Cache', false );
	}

	/**
	 * WPCode_Snippet is WPCode's internal class, not a versioned contract —
	 * feature-detect the shape we drive and fail closed if it drifted.
	 */
	public static function wpcode_available() {
		return class_exists( 'WPCode_Snippet' ) && method_exists( 'WPCode_Snippet', 'save' );
	}

	/**
	 * Location slugs valid on THIS site: WPCode's registered auto-insert
	 * locations at runtime, unioned with the known Lite set as a floor.
	 */
	public static function registered_locations() {
		$locations = array();
		if ( function_exists( 'wpcode' ) && isset( wpcode()->auto_insert ) && is_object( wpcode()->auto_insert ) && method_exists( wpcode()->auto_insert, 'get_types' ) ) {
			foreach ( (array) wpcode()->auto_insert->get_types() as $type ) {
				if ( ! is_object( $type ) ) {
					continue;
				}
				$locs = method_exists( $type, 'get_locations' ) ? $type->get_locations() : ( isset( $type->locations ) ? $type->locations : array() );
				foreach ( array_keys( (array) $locs ) as $slug ) {
					if ( is_string( $slug ) && '' !== $slug ) {
						$locations[] = $slug;
					}
				}
			}
		}
		return array_values( array_unique( array_merge( $locations, self::KNOWN_LITE_LOCATIONS ) ) );
	}

	/**
	 * Validate the request and build the WPCode_Snippet constructor data.
	 * Pure of WPCode itself so the activation invariant is unit-testable.
	 *
	 * @param array        $params   action, id, code, title, code_type, location, insert_method.
	 * @param WP_Post|null $existing The snippet post being updated (null on create).
	 * @return array|WP_Error
	 */
	public static function snippet_data( array $params, $existing = null ) {
		$type = sanitize_key( (string) ( $params['code_type'] ?? '' ) );
		if ( in_array( $type, self::PRO_TYPES, true ) ) {
			return new WP_Error(
				'invalid_snippet_type',
				sprintf(
					/* translators: 1: code type, 2: valid types */
					__( 'Code type "%1$s" requires WPCode Pro and is not supported. Valid types: %2$s.', 'vibe-ai' ),
					$type,
					implode( ', ', self::LITE_TYPES )
				),
				WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 422 ) )
			);
		}
		if ( ! in_array( $type, self::LITE_TYPES, true ) ) {
			return new WP_Error(
				'invalid_snippet_type',
				sprintf(
					/* translators: 1: code type, 2: valid types */
					__( 'Unknown code_type "%1$s". Valid types: %2$s.', 'vibe-ai' ),
					$type,
					implode( ', ', self::LITE_TYPES )
				),
				WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 422 ) )
			);
		}

		$location = sanitize_key( (string) ( $params['location'] ?? '' ) );
		$valid    = self::registered_locations();
		if ( ! in_array( $location, $valid, true ) ) {
			return new WP_Error(
				'invalid_snippet_location',
				sprintf(
					/* translators: 1: location slug, 2: valid slugs */
					__( 'Unknown location "%1$s". Locations registered on this site: %2$s.', 'vibe-ai' ),
					$location,
					implode( ', ', $valid )
				),
				WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 422 ) )
			);
		}

		$code = (string) ( $params['code'] ?? '' );
		if ( '' === trim( $code ) ) {
			return new WP_Error(
				'invalid_snippet_code',
				__( 'Snippet code is empty.', 'vibe-ai' ),
				WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 422 ) )
			);
		}
		// Rejected rather than stripped: any server-side mutation of the code
		// would break the Worker's stored-bytes fidelity assertion.
		if ( 'php' === $type && preg_match( '/^\s*<\?(php\b|=)?/i', $code ) ) {
			return new WP_Error(
				'invalid_snippet_code',
				__( 'PHP snippets must not include the <?php opening tag — WPCode adds the execution context. Resubmit the code without it.', 'vibe-ai' ),
				WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 422 ) )
			);
		}

		$data = array(
			'title'       => sanitize_text_field( (string) ( $params['title'] ?? '' ) ),
			'code'        => $code,
			'code_type'   => $type,
			'location'    => $location,
			'auto_insert' => ( isset( $params['insert_method'] ) && 'shortcode' === $params['insert_method'] ) ? 0 : 1,
			'active'      => false,
		);
		if ( '' === $data['title'] ) {
			return new WP_Error(
				'invalid_snippet_code',
				__( 'Snippet title is required.', 'vibe-ai' ),
				WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 422 ) )
			);
		}

		if ( null !== $existing ) {
			$data['id'] = (int) $existing->ID;
			// Preserve the human's activation for markup types, never raise it.
			// Server-executed types go back to disabled on update: WPCode's
			// activation check evals $this->code pre-unslash (it only unslashes
			// when its own admin $_POST is present), so re-activating here would
			// fail on any quoted code — and a fresh human enable re-runs that
			// check on the stored form anyway, which is the safer loop.
			$data['active'] = ( 'publish' === $existing->post_status )
				&& ! in_array( $type, array( 'php', 'universal' ), true );
		}

		return $data;
	}

	/**
	 * POST /wpvibe/v1/code-snippet: called by the Worker only AFTER the user
	 * approved the exact code + type + location in the browser panel.
	 * POST /wpvibe/v1/code-snippet/dormant ($dormant): no approval; the write
	 * must qualify under dormant_refusal() and always lands disabled.
	 */
	public static function handle( $request, $dormant = false ) {
		if ( ! self::wpcode_available() ) {
			$installed = function_exists( 'wpcode' ) || defined( 'WPCODE_VERSION' );
			return new WP_Error(
				'wpcode_missing',
				$installed
					? __( 'WPCode is installed but its snippet API is not available on this version. Ask the user to update WPCode.', 'vibe-ai' )
					: __( 'The WPCode plugin is not installed or not active on this site.', 'vibe-ai' ),
				WPVibe_Error_Contract::data( 'not_supported', false, array( 'status' => 501 ) )
			);
		}

		$action   = 'update' === $request->get_param( 'action' ) ? 'update' : 'create';
		$existing = null;
		if ( 'update' === $action ) {
			$id_param = (int) $request->get_param( 'id' );
			if ( $id_param <= 0 ) {
				return new WP_Error(
					'invalid_snippet_code',
					__( 'action "update" requires the id of an existing snippet.', 'vibe-ai' ),
					WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 422 ) )
				);
			}
			$existing = get_post( $id_param );
			if ( ! $existing || 'wpcode' !== $existing->post_type ) {
				return new WP_Error(
					'not_found',
					sprintf(
						/* translators: %d: snippet ID */
						__( 'No WPCode snippet with id %d.', 'vibe-ai' ),
						$id_param
					),
					WPVibe_Error_Contract::data( 'not_found', false, array( 'status' => 404 ) )
				);
			}
		}

		$params = array(
			'code'          => (string) $request->get_param( 'code' ),
			'title'         => (string) $request->get_param( 'title' ),
			'code_type'     => (string) $request->get_param( 'code_type' ),
			'location'      => (string) $request->get_param( 'location' ),
			'insert_method' => (string) $request->get_param( 'insert_method' ),
		);
		$data = self::snippet_data( $params, $existing );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		// Parse-check server-executed types at write time so a syntax error
		// surfaces here instead of at the human's activation click. WPCode's
		// execute-once activation check remains the backstop.
		if ( in_array( $data['code_type'], array( 'php', 'universal' ), true ) && function_exists( 'wpvibe_check_php_syntax' ) ) {
			$source = 'php' === $data['code_type'] ? "<?php\n" . $data['code'] : $data['code'];
			$lint   = wpvibe_check_php_syntax( $source, 'snippet' );
			if ( is_wp_error( $lint ) ) {
				return $lint;
			}
		}

		$create_locked = false;
		if ( $dormant ) {
			if ( null === $existing ) {
				if ( ! self::acquire_create_lock() ) {
					return self::review_error( 'busy' );
				}
				$create_locked = true;
			} else {
				// Re-read right before the checks: the human may have enabled or edited it since the request began.
				clean_post_cache( (int) $existing->ID );
				$existing = get_post( (int) $existing->ID );
				if ( ! $existing || ! self::is_code_post_type( $existing->post_type ) ) {
					return self::review_error( 'not_disabled' );
				}
			}
			try {
				$result = self::dormant_refusal( $data['code_type'], $existing );
				if ( ! $result ) {
					$data['active'] = false;
					$result         = self::save( $data, $existing, true );
				}
			} finally {
				if ( $create_locked ) {
					self::release_create_lock();
				}
			}
			return $result;
		}
		return self::save( $data, $existing, false );
	}

	/**
	 * Write through WPCode, then mark, verify, and describe the result.
	 *
	 * @return array|WP_Error
	 */
	private static function save( array $data, $existing, $dormant ) {
		$action         = null === $existing ? 'create' : 'update';
		$wpvibe_created = null === $existing || '' !== (string) get_post_meta( (int) $existing->ID, self::MARKER_META, true );
		if ( null === $existing ) {
			$data['tags'] = array( self::MARKER_TAG );
		}

		// wp_insert_post() unslashes; WPCode passes our fields through as-is
		// (its own admin feeds it slashed $_POST), so slash to round-trip
		// backslashes intact. The Worker's fidelity assertion verifies.
		$save          = $data;
		$save['title'] = wp_slash( $save['title'] );
		$save['code']  = wp_slash( $save['code'] );

		$snippet = new WPCode_Snippet( $save );
		$id      = (int) $snippet->save();
		if ( $id <= 0 ) {
			return new WP_Error(
				'wpcode_save_failed',
				__( 'WPCode did not save the snippet.', 'vibe-ai' ),
				WPVibe_Error_Contract::data( 'wp_core', false, array( 'status' => 500 ) )
			);
		}

		$saved    = new WPCode_Snippet( $id );
		$active   = method_exists( $saved, 'is_active' ) ? (bool) $saved->is_active() : ( 'publish' === get_post_status( $id ) );
		$stored   = (string) get_post_field( 'post_content', $id, 'raw' );

		// Signed over the code WPVibe sent, and only when that is what landed: a concurrent human save leaves it unowned.
		if ( $wpvibe_created && $stored === $data['code'] ) {
			update_post_meta( $id, self::MARKER_META, self::code_marker( $id, $data['code'] ) );
		} elseif ( $wpvibe_created ) {
			delete_post_meta( $id, self::MARKER_META );
		}
		if ( $dormant && null === $existing ) {
			update_post_meta( $id, self::DORMANT_CREATED_META, time() );
		}
		if ( $dormant && $active ) {
			// Unreachable while WPCode honours active=false; fail closed if a filter or version ever flips it.
			wp_update_post( array( 'ID' => $id, 'post_status' => 'draft' ) );
			return new WP_Error(
				'wpcode_save_failed',
				__( 'WPCode saved the snippet as enabled, so WPVibe turned it off again. Ask the user to review it in wp-admin.', 'vibe-ai' ),
				WPVibe_Error_Contract::data( 'wp_core', false, array( 'status' => 500 ) )
			);
		}
		$edit_url = method_exists( $saved, 'get_edit_url' )
			? $saved->get_edit_url()
			: admin_url( 'admin.php?page=wpcode-snippet-manager&snippet_id=' . $id );

		$was_active = null !== $existing && 'publish' === $existing->post_status;

		if ( class_exists( 'WPVibe_Audit_Log' ) ) {
			WPVibe_Audit_Log::log_execution( array(
				'operation'      => 'code_snippet_' . $action . ':' . $id,
				'command'        => sprintf( 'code_snippet %s #%d (%s @ %s)%s', $action, $id, $data['code_type'], $data['location'], $dormant ? ' without approval, saved disabled' : '' ),
				'params'         => array(
					'title'         => $data['title'],
					'code_type'     => $data['code_type'],
					'location'      => $data['location'],
					'insert_method' => $data['auto_insert'] ? 'auto' : 'shortcode',
					'code_bytes'    => strlen( $data['code'] ),
				),
				'result_summary' => $active ? 'updated (activation preserved: on)' : ( $was_active ? 'updated (deactivated pending human re-enable)' : 'saved inactive' ),
			) );
		}

		if ( $active ) {
			$next_step = __( 'The snippet was updated and stays enabled (the human enabled it previously).', 'vibe-ai' );
		} elseif ( $was_active ) {
			$next_step = __( 'This update turned the snippet OFF: edited server-executed code must pass WPCode\'s activation check under a fresh human enable. Give the user the enable_url so they can re-activate it in wp-admin.', 'vibe-ai' );
		} else {
			$next_step = __( 'The snippet is saved but OFF. Give the user the enable_url — they review and activate it in wp-admin (WPCode runs a fatal-error check when they do).', 'vibe-ai' );
		}
		if ( $dormant ) {
			$next_step = __( 'The snippet is saved but OFF and nothing runs yet: it was written without an approval prompt because enabling it is the user\'s review. Give the user the enable_url and tell them to read the code and enable it in wp-admin themselves. You cannot enable it.', 'vibe-ai' );
		}

		return rest_ensure_response( array(
			'id'          => $id,
			'action'      => $action,
			'active'      => $active,
			'enable_url'  => $edit_url,
			'stored_code' => $stored,
			'reviewed'    => ! $dormant,
			'shortcode'   => $data['auto_insert'] ? null : sprintf( '[wpcode id="%d"]', $id ),
			'next_step'   => $next_step,
		) );
	}
}
