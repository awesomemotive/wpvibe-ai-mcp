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
		foreach ( self::revision_fields( $post ) as $field ) {
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

	/**
	 * The fields core revisions, as core's _wp_post_revision_fields() returns them (a function marked private, so
	 * not called here): title, content and excerpt, plus what plugins add through core's filter of the same name,
	 * minus the fields core protects.
	 *
	 * @param WP_Post $post
	 * @return string[] post field names.
	 */
	private static function revision_fields( $post ) {
		$fields = array( 'post_title' => 'Title', 'post_content' => 'Content', 'post_excerpt' => 'Excerpt' );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's filter.
		$fields = apply_filters( '_wp_post_revision_fields', $fields, get_post( $post->ID, ARRAY_A ) );
		if ( ! is_array( $fields ) ) {
			return array();
		}
		// As core does after the filter: fields WordPress uses internally can never be revisioned.
		foreach ( array( 'ID', 'post_name', 'post_parent', 'post_date', 'post_date_gmt', 'post_status', 'post_type', 'comment_count', 'post_author' ) as $protect ) {
			unset( $fields[ $protect ] );
		}
		return array_keys( $fields );
	}

	/**
	 * A revision of $post as it is now, carrying copies of $meta_keys (#1489): a page builder's save keeps one before it
	 * overwrites a published layout, since core only revisions the new state. Null when revisions are off for the post or
	 * WordPress refused one. update_metadata, because update_post_meta on a revision writes to its parent.
	 */
	public static function snapshot( $post, array $meta_keys ) {
		$revision_id = wp_revisions_enabled( $post ) ? _wp_put_post_revision( $post ) : 0;
		if ( ! $revision_id || is_wp_error( $revision_id ) ) {
			return null;
		}
		foreach ( $meta_keys as $key ) {
			$value = get_post_meta( $post->ID, $key, true );
			if ( '' !== $value && array() !== $value ) {
				update_metadata( 'post', (int) $revision_id, $key, wp_slash( $value ) );
			}
		}
		return (int) $revision_id;
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
