<?php
/**
 * Saves a post's current title and content as a revision before a WPVibe
 * request first changes them (#684). Core only revisions the NEW state, so the
 * first edit to a never-revised post had no way back. With revisions off for
 * the post, nothing is saved and the response says the change can't be undone.
 */

defined( 'ABSPATH' ) || exit;

class WPVibe_Revision_Guard {

	// Internal or file-backed types where a content revision restores nothing useful.
	const SKIP_TYPES = array( 'revision', 'attachment', 'nav_menu_item', 'customize_changeset', 'oembed_cache', 'user_request' );

	/** @var array<int,true> posts already handled in this request. */
	private static $seen = array();

	/** @var array<int,true> posts this request changed while revisions are off for them. */
	private static $unrevisioned = array();

	public static function register() {
		add_action( 'pre_post_update', array( __CLASS__, 'on_pre_post_update' ), 1, 2 );
	}

	public static function reset() {
		self::$seen         = array();
		self::$unrevisioned = array();
	}

	/**
	 * @param int   $post_id Post being updated.
	 * @param array $data    Unslashed row about to be written.
	 */
	public static function on_pre_post_update( $post_id, $data ) {
		if ( ! WPVibe_Auth_Diagnostics::is_wpvibe_request() ) {
			return;
		}
		$post = get_post( (int) $post_id );
		if ( ! self::applies( $post ) || ! is_array( $data ) || isset( self::$seen[ (int) $post->ID ] ) ) {
			return;
		}
		foreach ( array_keys( _wp_post_revision_fields( $post ) ) as $field ) {
			if ( array_key_exists( $field, $data ) && (string) $data[ $field ] !== (string) $post->$field ) {
				self::$seen[ (int) $post->ID ] = true;
				if ( wp_revisions_enabled( $post ) ) {
					// The row still holds the old state here; core skips this when the newest revision already matches it.
					wp_save_post_revision( (int) $post->ID );
				} else {
					self::$unrevisioned[ (int) $post->ID ] = true;
				}
				return;
			}
		}
	}

	/** For posts this request changed with revisions off: the change can't be undone from WordPress. '' when none. */
	public static function revisions_off_note( $post_ids ) {
		$lines = array();
		foreach ( (array) $post_ids as $post_id ) {
			if ( ! empty( self::$unrevisioned[ (int) $post_id ] ) ) {
				/* translators: %d: post ID */
				$lines[] = sprintf( __( 'Post %d has revisions turned off, so WordPress kept no copy of its previous title and content: this change cannot be undone from revision history. Tell the user.', 'vibe-ai' ), (int) $post_id );
			}
		}
		return implode( "\n", $lines );
	}

	private static function applies( $post ) {
		if ( ! is_object( $post ) || empty( $post->ID ) || ! isset( $post->post_type ) ) {
			return false;
		}
		if ( in_array( $post->post_type, self::SKIP_TYPES, true ) || 'auto-draft' === ( $post->post_status ?? '' ) ) {
			return false;
		}
		return ! WPVibe_Code_Snippet::is_code_post_type( $post->post_type );
	}
}
