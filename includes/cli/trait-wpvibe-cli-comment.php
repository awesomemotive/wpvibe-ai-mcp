<?php
/**
 * WP-CLI emulator: comment family (list, count, create, moderation, delete).
 *
 * Contract: wp-cli/entity-command features/comment.feature within our flag
 * subset. Documented divergences: `comment delete` without --force trashes
 * explicitly (core's wp_delete_comment hard-deletes spam and trashed comments
 * even without force, which would make spam cleanup ungated); `comment create`
 * fills the author from the connected user and refuses empty content; status
 * verbs report an already-in-state comment as unchanged instead of failing.
 */

defined( 'ABSPATH' ) || exit;

trait WPVibe_CLI_Comment {

	private static $comment_list_statuses = array( 'approve', 'hold', 'spam', 'trash', 'all', '0', '1' );


	private function handle_comment_list( $positional, $flags ) {
		$reject = $this->reject_unknown_flags( 'comment list', $flags, array(
			'status', 'post_id', 'number', 'offset', 'orderby', 'order', 'comment__in', 'type', 'fields', 'format',
		), array(
			'field'  => __( 'Use --format=ids for bare IDs or --fields=<list> to narrow columns.', 'vibe-ai' ),
			'search' => __( 'Comment search is not emulated; filter by --status or --post_id instead.', 'vibe-ai' ),
		) );
		if ( $reject ) {
			return $reject;
		}
		$reject = $this->reject_unsupported_format( 'comment list', $flags );
		if ( $reject ) {
			return $reject;
		}

		$args = array(
			'number' => isset( $flags['number'] ) ? max( 1, min( (int) $flags['number'], 100 ) ) : 20,
		);
		if ( isset( $flags['status'] ) ) {
			$status = (string) $flags['status'];
			if ( ! in_array( $status, self::$comment_list_statuses, true ) ) {
				/* translators: %s: the requested status */
				return $this->error_result( sprintf( __( 'Unknown comment status "%s". Use --status=approve|hold|spam|trash|all.', 'vibe-ai' ), $status ) );
			}
			$args['status'] = $status;
		}
		if ( isset( $flags['post_id'] ) ) {
			$args['post_id'] = (int) $flags['post_id'];
		}
		if ( isset( $flags['type'] ) ) {
			$args['type'] = $flags['type'];
		}
		if ( isset( $flags['offset'] ) ) {
			$args['offset'] = max( 0, (int) $flags['offset'] );
		}
		if ( isset( $flags['comment__in'] ) ) {
			$args['comment__in'] = $this->positional_ids( wp_parse_list( $flags['comment__in'] ) );
		}
		if ( isset( $flags['orderby'] ) ) {
			$orderby = (string) $flags['orderby'];
			if ( ! in_array( $orderby, array( 'comment_date', 'comment_date_gmt', 'comment_ID', 'comment_post_ID' ), true ) ) {
				return $this->error_result( __( 'Use --orderby=comment_date_gmt|comment_date|comment_ID|comment_post_ID.', 'vibe-ai' ) );
			}
			$args['orderby'] = $orderby;
		}
		if ( isset( $flags['order'] ) ) {
			$order = strtoupper( (string) $flags['order'] );
			if ( ! in_array( $order, array( 'ASC', 'DESC' ), true ) ) {
				return $this->error_result( __( 'Use --order=ASC|DESC.', 'vibe-ai' ) );
			}
			$args['order'] = $order;
		}

		$comments = get_comments( $args );
		$results  = array();
		foreach ( $comments as $comment ) {
			$content = $comment->comment_content;
			if ( strlen( $content ) > 200 ) {
				$content = mb_substr( $content, 0, 200 ) . '...[truncated]';
			}
			$results[] = array(
				'comment_ID'       => (int) $comment->comment_ID,
				'comment_author'   => $comment->comment_author,
				'comment_content'  => $content,
				'comment_date'     => $comment->comment_date,
				'comment_approved' => $comment->comment_approved,
				'comment_post_ID'  => (int) $comment->comment_post_ID,
				'comment_parent'   => (int) $comment->comment_parent,
			);
		}

		return $this->success_result( $this->format_rows( $results, $flags, 'comment_ID' ) );
	}


	private function handle_comment_count( $positional, $flags ) {
		$post_id = ! empty( $positional[0] ) ? (int) $positional[0] : 0;
		$counts  = wp_count_comments( $post_id );

		return $this->success_result( array(
			'approved'            => (int) $counts->approved,
			'awaiting_moderation' => (int) $counts->moderated,
			'spam'                => (int) $counts->spam,
			'trash'               => (int) $counts->trash,
			'total_comments'      => (int) $counts->total_comments,
		) );
	}


	private function handle_comment_create( $positional, $flags ) {
		$reject = $this->reject_unknown_flags( 'comment create', $flags, array(
			'comment_post_ID', 'comment_content', 'comment_content_base64', 'comment_parent',
			'comment_author', 'comment_author_email', 'comment_author_url', 'comment_approved', 'porcelain',
		), array(
			'comment_date'      => __( 'Backdating comments is not emulated.', 'vibe-ai' ),
			'comment_date_gmt'  => __( 'Backdating comments is not emulated.', 'vibe-ai' ),
			'comment_author_IP' => __( 'The author IP is not settable; the comment is attributed to the connected user.', 'vibe-ai' ),
			'comment_agent'     => __( 'The user agent is not settable.', 'vibe-ai' ),
			'comment_type'      => __( 'Only regular comments are created (no pingbacks or trackbacks).', 'vibe-ai' ),
			'comment_karma'     => __( 'Comment karma is not emulated.', 'vibe-ai' ),
			'user_id'           => __( 'The comment is attributed to the connected user; --user_id is not settable.', 'vibe-ai' ),
		) );
		if ( $reject ) {
			return $reject;
		}

		$post_id = isset( $flags['comment_post_ID'] ) ? (int) $flags['comment_post_ID'] : 0;
		if ( $post_id <= 0 ) {
			return $this->error_result( __( 'Usage: comment create --comment_post_ID=<id> --comment_content=<text> [--comment_parent=<id>] [--porcelain]', 'vibe-ai' ) );
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			/* translators: %d: post ID */
			return $this->error_result( sprintf( __( "Can't find post %d.", 'vibe-ai' ), $post_id ) );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'insufficient_cap', __( 'You do not have the required capability (edit_post) to comment on this post through WPVibe.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'capability_role', false, array( 'status' => 403, 'capability' => 'edit_post' ) ) );
		}

		$parent = isset( $flags['comment_parent'] ) ? (int) $flags['comment_parent'] : 0;
		if ( $parent > 0 ) {
			$parent_comment = get_comment( $parent );
			if ( ! $parent_comment ) {
				/* translators: %d: comment ID */
				return $this->error_result( sprintf( __( 'Parent comment %d not found.', 'vibe-ai' ), $parent ) );
			}
			if ( (int) $parent_comment->comment_post_ID !== $post_id ) {
				/* translators: 1: parent comment ID, 2: the post it belongs to, 3: the requested post */
				return $this->error_result( sprintf( __( 'Parent comment %1$d belongs to post %2$d, not post %3$d.', 'vibe-ai' ), $parent, (int) $parent_comment->comment_post_ID, $post_id ) );
			}
		}

		if ( isset( $flags['comment_content_base64'] ) ) {
			$content = $this->decode_content_base64( $flags['comment_content_base64'] );
			if ( is_wp_error( $content ) ) {
				return $this->error_result( str_replace( '--post_content_base64', '--comment_content_base64', $content->get_error_message() ) );
			}
		} else {
			$content = wp_slash( (string) ( $flags['comment_content'] ?? '' ) );
		}
		if ( '' === trim( wp_unslash( $content ) ) ) {
			return $this->error_result( __( 'Comment content required (--comment_content or --comment_content_base64).', 'vibe-ai' ) );
		}
		// wp_insert_comment skips the kses pipeline core runs for comment authors
		// who lack unfiltered_html (multisite non-super-admins, DISALLOW_UNFILTERED_HTML).
		if ( ! current_user_can( 'unfiltered_html' ) ) {
			$content = wp_slash( wp_kses_data( wp_unslash( $content ) ) );
		}

		$approved = '1';
		if ( isset( $flags['comment_approved'] ) ) {
			$map = array( '1' => '1', 'approve' => '1', '0' => '0', 'hold' => '0', 'spam' => 'spam' );
			$key = (string) $flags['comment_approved'];
			if ( ! isset( $map[ $key ] ) ) {
				return $this->error_result( __( 'Use --comment_approved=1|0 (or approve|hold|spam).', 'vibe-ai' ) );
			}
			$approved = $map[ $key ];
		}

		$user = wp_get_current_user();
		$data = array(
			'comment_post_ID'      => $post_id,
			'comment_content'      => $content,
			'comment_parent'       => $parent,
			'comment_author'       => isset( $flags['comment_author'] ) ? (string) $flags['comment_author'] : (string) $user->display_name,
			'comment_author_email' => isset( $flags['comment_author_email'] ) ? (string) $flags['comment_author_email'] : (string) $user->user_email,
			'comment_author_url'   => isset( $flags['comment_author_url'] ) ? (string) $flags['comment_author_url'] : (string) $user->user_url,
			'comment_approved'     => $approved,
			'comment_type'         => 'comment',
			'user_id'              => (int) $user->ID,
		);

		$id = wp_insert_comment( $data );
		if ( ! $id ) {
			return $this->error_result( __( 'Could not create comment.', 'vibe-ai' ) );
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => $parent > 0 ? "Comment reply posted: #{$id}" : "Comment created: #{$id}",
			'action_label' => 'View Comments',
			'admin_url'    => admin_url( 'edit-comments.php' ),
		) );

		if ( ! empty( $flags['porcelain'] ) ) {
			return $this->success_result( (int) $id );
		}
		return $this->success_result( array(
			/* translators: %d: comment ID */
			'message'          => sprintf( __( 'Created comment %d.', 'vibe-ai' ), $id ),
			'comment_ID'       => (int) $id,
			'comment_post_ID'  => $post_id,
			'comment_parent'   => $parent,
			'comment_approved' => $approved,
		) );
	}


	private function handle_comment_approve( $positional, $flags ) {
		return $this->comment_status_verb( 'comment approve', $positional, $flags, 'approve' );
	}

	private function handle_comment_unapprove( $positional, $flags ) {
		return $this->comment_status_verb( 'comment unapprove', $positional, $flags, 'hold' );
	}

	private function handle_comment_spam( $positional, $flags ) {
		return $this->comment_status_verb( 'comment spam', $positional, $flags, 'spam' );
	}

	private function handle_comment_unspam( $positional, $flags ) {
		return $this->comment_status_verb( 'comment unspam', $positional, $flags, 'unspam' );
	}

	private function handle_comment_trash( $positional, $flags ) {
		return $this->comment_status_verb( 'comment trash', $positional, $flags, 'trash' );
	}

	private function handle_comment_untrash( $positional, $flags ) {
		return $this->comment_status_verb( 'comment untrash', $positional, $flags, 'untrash' );
	}


	/**
	 * One loop for the six moderation verbs. Each transition goes through the
	 * same core function wp-admin's row actions call, so plugin hooks
	 * (Akismet, notification plugins) fire exactly as they do from the UI.
	 */
	private function comment_status_verb( $command, $positional, $flags, $transition ) {
		$reject = $this->reject_unknown_flags( $command, $flags, array() );
		if ( $reject ) {
			return $reject;
		}
		$ids = $this->positional_ids( $positional );
		if ( empty( $ids ) ) {
			/* translators: %s: command name */
			return $this->error_result( sprintf( __( 'Comment ID required. Usage: %s <id> [<id>...]', 'vibe-ai' ), $command ) );
		}

		$labels = array(
			'approve' => array( 'approved', 'approved' ),
			'hold'    => array( 'unapproved', 'unapproved' ),
			'spam'    => array( 'marked as spam', 'spam' ),
			'unspam'  => array( 'unspammed', 'approved' ),
			'trash'   => array( 'trashed', 'trash' ),
			'untrash' => array( 'untrashed', 'approved' ),
		);
		list( $action, $target_state ) = $labels[ $transition ];

		$results = array();
		$ok      = 0;
		foreach ( $ids as $id ) {
			$comment = get_comment( $id );
			if ( ! $comment ) {
				$results[] = array( 'id' => $id, 'status' => 'error', 'error' => 'not found' );
				continue;
			}
			$current = wp_get_comment_status( $comment );
			$already = ( 'unspam' === $transition || 'untrash' === $transition )
				? ( $current !== ( 'unspam' === $transition ? 'spam' : 'trash' ) )
				: ( $current === $target_state );
			if ( $already ) {
				$ok++;
				$results[] = array( 'id' => $id, 'status' => 'unchanged', 'note' => sprintf( 'already %s', $current ) );
				continue;
			}
			switch ( $transition ) {
				case 'spam':
					$res = wp_spam_comment( $comment );
					break;
				case 'unspam':
					$res = wp_unspam_comment( $comment );
					break;
				case 'trash':
					$res = wp_trash_comment( $comment );
					// Without a trash, core deletes outright (same as the wp-admin Trash link).
					if ( $res && ! EMPTY_TRASH_DAYS ) {
						$action = 'permanently deleted (this site has no comment trash)';
					}
					break;
				case 'untrash':
					$res = wp_untrash_comment( $comment );
					break;
				default:
					$res = wp_set_comment_status( $comment, $transition, true );
			}
			if ( is_wp_error( $res ) ) {
				$results[] = array( 'id' => $id, 'status' => 'error', 'error' => $res->get_error_message() );
				continue;
			}
			if ( ! $res ) {
				$results[] = array( 'id' => $id, 'status' => 'error', 'error' => 'Could not update comment status.' );
				continue;
			}
			$ok++;
			$results[] = array( 'id' => $id, 'status' => $action );
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => count( $ids ) > 1 ? "Comments {$action}: {$ok}/" . count( $ids ) : "Comment {$action}: #{$ids[0]}",
			'action_label' => 'View Comments',
			'admin_url'    => admin_url( 'edit-comments.php' ),
		) );

		return $this->bulk_result( $action, $ok, $ids, $results, 'comment' );
	}


	private function handle_comment_delete( $positional, $flags ) {
		$reject = $this->reject_unknown_flags( 'comment delete', $flags, array( 'force' ) );
		if ( $reject ) {
			return $reject;
		}
		$ids = $this->positional_ids( $positional );
		if ( empty( $ids ) ) {
			return $this->error_result( __( 'Comment ID required. Usage: comment delete <id> [<id>...] [--force]', 'vibe-ai' ) );
		}

		$force = ! empty( $flags['force'] );
		if ( $force && ! $this->skip_destructive ) {
			// classify_destructive should have gated this; defense-in-depth.
			return $this->error_result( __( 'comment delete --force requires explicit approval.', 'vibe-ai' ) );
		}

		$results = array();
		$ok      = 0;
		foreach ( $ids as $id ) {
			$comment = get_comment( $id );
			if ( ! $comment ) {
				$results[] = array( 'id' => $id, 'status' => 'error', 'error' => 'not found' );
				continue;
			}
			// Never wp_delete_comment( $id, false ): core hard-deletes spam and
			// trashed comments on that path, which would make spam cleanup an
			// ungated permanent delete.
			$res = $force ? wp_delete_comment( $comment, true ) : wp_trash_comment( $comment );
			if ( ! $res ) {
				$results[] = array( 'id' => $id, 'status' => 'error', 'error' => $force ? 'delete failed' : 'already in trash' );
				continue;
			}
			$ok++;
			$results[] = array( 'id' => $id, 'status' => ( $force || ! EMPTY_TRASH_DAYS ) ? 'deleted' : 'trashed' );
		}

		$action = ( $force || ! EMPTY_TRASH_DAYS ) ? __( 'permanently deleted', 'vibe-ai' ) : __( 'trashed', 'vibe-ai' );
		WPVibe_Change_Tracker::mark( array(
			'summary'      => count( $ids ) > 1 ? "Comments {$action}: {$ok}/" . count( $ids ) : "Comment {$action}: #{$ids[0]}",
			'action_label' => 'View Comments',
			'admin_url'    => admin_url( 'edit-comments.php' ),
		) );

		return $this->bulk_result( $action, $ok, $ids, $results, 'comment' );
	}

}
