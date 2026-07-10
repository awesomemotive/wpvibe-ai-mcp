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
	 * POST /wpvibe/v1/code-snippet — called by the Worker only AFTER the user
	 * approved the exact code + type + location in the browser panel.
	 */
	public static function handle( $request ) {
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
		$edit_url = method_exists( $saved, 'get_edit_url' )
			? $saved->get_edit_url()
			: admin_url( 'admin.php?page=wpcode-snippet-manager&snippet_id=' . $id );

		$was_active = null !== $existing && 'publish' === $existing->post_status;

		if ( class_exists( 'WPVibe_Audit_Log' ) ) {
			WPVibe_Audit_Log::log_execution( array(
				'operation'      => 'code_snippet_' . $action . ':' . $id,
				'command'        => sprintf( 'code_snippet %s #%d (%s @ %s)', $action, $id, $data['code_type'], $data['location'] ),
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

		return rest_ensure_response( array(
			'id'          => $id,
			'action'      => $action,
			'active'      => $active,
			'enable_url'  => $edit_url,
			'stored_code' => (string) get_post_field( 'post_content', $id, 'raw' ),
			'shortcode'   => $data['auto_insert'] ? null : sprintf( '[wpcode id="%d"]', $id ),
			'next_step'   => $next_step,
		) );
	}
}
