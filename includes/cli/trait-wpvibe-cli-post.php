<?php
/**
 * WP-CLI emulator: post/meta/term handlers plus media and comment reads.
 *
 * Extracted from class-wpvibe-cli.php (mechanical split; no behavior change).
 */

defined( 'ABSPATH' ) || exit;

trait WPVibe_CLI_Post {


	private function handle_post_list( $positional, $flags ) {
		$post_type = $flags['post_type'] ?? 'post';
		if ( is_string( $post_type ) && strpos( $post_type, ',' ) !== false ) {
			$post_type = array_filter( wp_parse_list( $post_type ) );
		}
		$args = array(
			'post_type'      => $post_type,
			'post_status'    => $flags['post_status'] ?? 'any',
			'posts_per_page' => isset( $flags['posts_per_page'] ) ? min( (int) $flags['posts_per_page'], 100 ) : 20,
			'orderby'        => $flags['orderby'] ?? 'date',
			'order'          => $flags['order'] ?? 'DESC',
		);
		// Silently ignoring targeting flags is dangerous when a listing feeds a
		// bulk operation, so honor the common ones and reject the rest.
		if ( isset( $flags['s'] ) )        $args['s']        = (string) $flags['s'];
		if ( isset( $flags['author'] ) )   $args['author']   = (int) $flags['author'];
		if ( isset( $flags['year'] ) )     $args['year']     = (int) $flags['year'];
		if ( isset( $flags['monthnum'] ) ) $args['monthnum'] = (int) $flags['monthnum'];
		$format_reject = $this->reject_unsupported_format( 'post list', $flags );
		if ( $format_reject ) {
			return $format_reject;
		}
		$known = array( 'post_type', 'post_status', 'posts_per_page', 'orderby', 'order', 's', 'author', 'year', 'monthnum', 'fields', 'format' );
		foreach ( array_keys( $flags ) as $flag ) {
			if ( ! in_array( $flag, $known, true ) ) {
				/* translators: %s: the unsupported flag name */
				return $this->error_result( sprintf( __( 'post list does not support --%s here. Supported filters: s, author, year, monthnum, post_type, post_status, posts_per_page, orderby, order. For richer queries use the REST API (/wp/v2/posts).', 'vibe-ai' ), $flag ) );
			}
		}
		$posts   = get_posts( $args );
		$results = array();
		foreach ( $posts as $post ) {
			$results[] = array(
				'ID'          => $post->ID,
				'post_title'  => $post->post_title,
				'post_name'   => $post->post_name,
				'post_status' => $post->post_status,
				'post_type'   => $post->post_type,
				'post_date'   => $post->post_date,
			);
		}
		return $this->success_result(
			$this->format_rows( $results, $flags, 'ID' ),
			$this->truncation_notice( $results, (int) $args['posts_per_page'], 'post list' )
		);
	}


	private function handle_post_get( $positional, $flags ) {
		if ( empty( $positional[0] ) ) {
			return $this->error_result( __( 'Post ID required.', 'vibe-ai' ) );
		}
		$post = get_post( (int) $positional[0] );
		if ( ! $post ) {
			/* translators: %s: post ID */
			return $this->error_result( sprintf( __( 'Post %s not found.', 'vibe-ai' ), $positional[0] ) );
		}

		$has_explicit_fields = ! empty( $flags['fields'] );

		$content = $post->post_content;
		if ( ! $has_explicit_fields && strlen( $content ) > 500 ) {
			$content = mb_substr( $content, 0, 500 ) . "\n[truncated — use --fields=post_content for full content]";
		}

		$content_filtered = $post->post_content_filtered;
		if ( ! $has_explicit_fields && strlen( $content_filtered ) > 500 ) {
			$content_filtered = mb_substr( $content_filtered, 0, 500 ) . "\n[truncated — use --fields=post_content_filtered for full content]";
		}

		$data = array(
			'ID'                    => $post->ID,
			'post_title'            => $post->post_title,
			'post_name'             => $post->post_name,
			'post_status'           => $post->post_status,
			'post_type'             => $post->post_type,
			'post_date'             => $post->post_date,
			'post_modified'         => $post->post_modified,
			'post_author'           => $post->post_author,
			'post_excerpt'          => $post->post_excerpt,
			'post_content'          => $content,
			'post_content_filtered' => $content_filtered,
			'post_parent'           => $post->post_parent,
			'menu_order'            => $post->menu_order,
			'comment_status'        => $post->comment_status,
			'post_mime_type'        => $post->post_mime_type,
			'guid'                  => $post->guid,
			'comment_count'         => $post->comment_count,
		);

		return $this->success_result( $this->filter_fields( array( $data ), $flags )[0] ?? $data );
	}


	private function handle_post_meta_get( $positional, $flags ) {
		if ( empty( $positional[0] ) ) {
			return $this->error_result( __( 'Post ID required. Usage: post meta get <id> [<key>]', 'vibe-ai' ) );
		}

		$post_id = (int) $positional[0];
		$post    = get_post( $post_id );
		if ( ! $post ) {
			/* translators: %s: post ID */
			return $this->error_result( sprintf( __( 'Post %s not found.', 'vibe-ai' ), $positional[0] ) );
		}

		// Single key mode.
		if ( ! empty( $positional[1] ) ) {
			$value = get_post_meta( $post_id, $positional[1], true );
			return array(
				'exit_code' => 0,
				'stdout'    => is_scalar( $value ) ? (string) $value : wp_json_encode( $value, JSON_PRETTY_PRINT ),
				'stderr'    => '',
			);
		}

		// All meta mode. One row per postmeta row, keyed post_id/meta_key/meta_value to
		// match real WP-CLI: collapsing a multi-value key into one entry hid how many
		// rows exist, which is what `post meta delete <id> <key> <value>` operates on.
		// Values stay raw — upstream emits the serialized string too, unmodified.
		$meta    = get_post_meta( $post_id );
		$results = array();
		foreach ( $meta as $key => $values ) {
			// Hide internal meta unless --all flag is set.
			if ( empty( $flags['all'] ) && strpos( $key, '_' ) === 0 ) {
				continue;
			}
			foreach ( (array) $values as $value ) {
				$results[] = array(
					'post_id'    => $post_id,
					'meta_key'   => $key,
					'meta_value' => $value,
				);
			}
		}

		return $this->success_result( $results );
	}


	private function handle_taxonomy_list( $positional, $flags ) {
		$taxonomies = get_taxonomies( array(), 'objects' );
		$results    = array();
		foreach ( $taxonomies as $slug => $tax ) {
			if ( isset( $flags['public'] ) && (bool) $flags['public'] !== $tax->public ) {
				continue;
			}
			$results[] = array(
				'name'         => $slug,
				'label'        => $tax->label,
				'public'       => $tax->public,
				'hierarchical' => $tax->hierarchical,
				'object_type'  => $tax->object_type,
			);
		}
		return $this->success_result( $this->filter_fields( $results, $flags ) );
	}


	private function handle_term_list( $positional, $flags ) {
		// Accept taxonomy as positional (real WP-CLI) or --taxonomy flag (AI compat).
		$taxonomy = $positional[0] ?? $flags['taxonomy'] ?? '';
		if ( empty( $taxonomy ) ) {
			return $this->error_result( __( 'Taxonomy required. Usage: term list <taxonomy> or term list --taxonomy=category', 'vibe-ai' ) );
		}
		if ( ! taxonomy_exists( $taxonomy ) ) {
			/* translators: %s: taxonomy name */
			return $this->error_result( sprintf( __( 'Taxonomy \'%s\' not found.', 'vibe-ai' ), $taxonomy ) );
		}

		$args = array(
			'taxonomy'   => $taxonomy,
			'number'     => isset( $flags['number'] ) ? min( (int) $flags['number'], 500 ) : 100,
			'hide_empty' => isset( $flags['hide_empty'] ) ? (bool) $flags['hide_empty'] : false,
			'orderby'    => $flags['orderby'] ?? 'name',
			'order'      => $flags['order'] ?? 'ASC',
		);
		if ( isset( $flags['search'] ) ) {
			$args['search'] = $flags['search'];
		}
		if ( isset( $flags['parent'] ) ) {
			$args['parent'] = (int) $flags['parent'];
		}

		$terms   = get_terms( $args );
		if ( is_wp_error( $terms ) ) {
			return $this->error_result( $terms->get_error_message() );
		}

		$results = array();
		foreach ( $terms as $term ) {
			$results[] = array(
				'term_id'     => $term->term_id,
				'name'        => $term->name,
				'slug'        => $term->slug,
				'description' => $term->description,
				'count'       => $term->count,
				'parent'      => $term->parent,
			);
		}

		return $this->success_result( $this->filter_fields( $results, $flags ) );
	}


	private function handle_media_list( $positional, $flags ) {
		// Not a real WP-CLI command — maps to get_posts(type=attachment) for AI convenience.
		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => isset( $flags['posts_per_page'] ) ? min( (int) $flags['posts_per_page'], 100 ) : 20,
			'orderby'        => $flags['orderby'] ?? 'date',
			'order'          => $flags['order'] ?? 'DESC',
		);
		if ( isset( $flags['post_mime_type'] ) ) {
			$args['post_mime_type'] = $flags['post_mime_type'];
		}

		$posts   = get_posts( $args );
		$results = array();
		foreach ( $posts as $post ) {
			$results[] = array(
				'ID'             => $post->ID,
				'post_title'     => $post->post_title,
				'post_mime_type' => $post->post_mime_type,
				'guid'           => $post->guid,
				'post_date'      => $post->post_date,
			);
		}

		return $this->success_result( $this->filter_fields( $results, $flags ) );
	}


	private function handle_comment_list( $positional, $flags ) {
		$args = array(
			'number' => isset( $flags['number'] ) ? min( (int) $flags['number'], 100 ) : 20,
		);
		if ( isset( $flags['status'] ) )  $args['status']  = $flags['status'];
		if ( isset( $flags['post_id'] ) ) $args['post_id'] = (int) $flags['post_id'];
		if ( isset( $flags['type'] ) )    $args['type']    = $flags['type'];

		$comments = get_comments( $args );
		$results  = array();
		foreach ( $comments as $comment ) {
			$content = $comment->comment_content;
			if ( strlen( $content ) > 200 ) {
				$content = mb_substr( $content, 0, 200 ) . '...[truncated]';
			}
			$results[] = array(
				'comment_ID'      => $comment->comment_ID,
				'comment_author'  => $comment->comment_author,
				'comment_content' => $content,
				'comment_date'    => $comment->comment_date,
				'comment_approved' => $comment->comment_approved,
				'comment_post_ID' => $comment->comment_post_ID,
			);
		}

		return $this->success_result( $this->filter_fields( $results, $flags ) );
	}


	private function handle_comment_count( $positional, $flags ) {
		$post_id = ! empty( $positional[0] ) ? (int) $positional[0] : 0;
		$counts  = wp_count_comments( $post_id );

		return $this->success_result( array(
			'approved'            => $counts->approved,
			'awaiting_moderation' => $counts->moderated,
			'spam'                => $counts->spam,
			'trash'               => $counts->trash,
			'total_comments'      => $counts->total_comments,
		) );
	}


	/**
	 * Per-post-type capability check.
	 *
	 * wp_insert_post / wp_update_post / update_post_meta do NOT enforce
	 * capabilities on their own — only the REST controller layer does. Since
	 * this CLI dispatcher bypasses that, we have to gate each mutation
	 * ourselves or a user with bare `edit_posts` could create/publish/edit
	 * post types they have no business touching.
	 *
	 * @param string      $post_type  Slug.
	 * @param string      $action     'create' | 'update' | 'delete'.
	 * @param int|null    $post_id    Required for update + delete.
	 * @param string|null $new_status post_status the request is moving toward (publish triggers the publish_posts check).
	 * @return true|WP_Error
	 */
	private function check_post_caps( $post_type, $action, $post_id = null, $new_status = null ) {
		$pt_obj = get_post_type_object( $post_type );
		if ( ! $pt_obj ) {
			return new WP_Error( 'invalid_post_type', sprintf(
				/* translators: %s: post type slug */
				__( 'Unknown post type: %s', 'vibe-ai' ),
				$post_type
			), WPVibe_Error_Contract::data( 'invalid_input', false, array( 'post_type' => $post_type ) ) );
		}
		// manage_options fallback: CPTs with custom capability mappings fail the
		// per-post meta-cap checks below even for admins, who can already reach
		// the same rows through the approval-gated db query path. An explicit
		// 'do_not_allow' in the post type's caps is intentional (e.g. HPOS
		// orders) and stays enforced for everyone.
		$admin = current_user_can( 'manage_options' );
		if ( 'create' === $action ) {
			$allowed = current_user_can( $pt_obj->cap->create_posts )
				|| ( $admin && 'do_not_allow' !== $pt_obj->cap->create_posts );
			if ( ! $allowed ) {
				return new WP_Error( 'forbidden', sprintf(
					/* translators: %s: post type label */
					__( 'You do not have permission to create %s.', 'vibe-ai' ),
					$pt_obj->labels->name
				), WPVibe_Error_Contract::data( 'capability_cpt_mapping', false, array( 'status' => 403, 'post_type' => $post_type, 'capability' => $pt_obj->cap->create_posts ) ) );
			}
		} else {
			$edit_allowed = current_user_can( 'edit_post', $post_id )
				|| ( $admin && 'do_not_allow' !== $pt_obj->cap->edit_posts );
			if ( ! $edit_allowed ) {
				return new WP_Error( 'forbidden', sprintf(
					/* translators: %d: post ID */
					__( 'You do not have permission to edit post #%d.', 'vibe-ai' ),
					$post_id
				), WPVibe_Error_Contract::data( 'capability_cpt_mapping', false, array( 'status' => 403, 'post_type' => $post_type, 'capability' => 'edit_post' ) ) );
			}
			$delete_allowed = current_user_can( 'delete_post', $post_id )
				|| ( $admin && 'do_not_allow' !== $pt_obj->cap->delete_posts );
			if ( 'delete' === $action && ! $delete_allowed ) {
				return new WP_Error( 'forbidden', sprintf(
					/* translators: %d: post ID */
					__( 'You do not have permission to delete post #%d.', 'vibe-ai' ),
					$post_id
				), WPVibe_Error_Contract::data( 'capability_cpt_mapping', false, array( 'status' => 403, 'post_type' => $post_type, 'capability' => 'delete_post' ) ) );
			}
		}
		$publish_allowed = current_user_can( $pt_obj->cap->publish_posts )
			|| ( $admin && 'do_not_allow' !== $pt_obj->cap->publish_posts );
		if ( 'publish' === $new_status && ! $publish_allowed ) {
			return new WP_Error( 'forbidden_publish', sprintf(
				/* translators: %s: post type label */
				__( 'You do not have permission to publish %s.', 'vibe-ai' ),
				$pt_obj->labels->name
			), WPVibe_Error_Contract::data( 'capability_cpt_mapping', false, array( 'status' => 403, 'post_type' => $post_type, 'capability' => $pt_obj->cap->publish_posts ) ) );
		}
		return true;
	}


	private function handle_post_create( $positional, $flags ) {
		$reject = $this->reject_unknown_flags( 'post create', $flags, array(
			'post_title', 'post_content', 'post_content_base64', 'post_status', 'post_type',
			'post_excerpt', 'post_name', 'post_parent', 'menu_order', 'comment_status', 'post_date',
			'porcelain',
		), $this->post_write_unsupported_flags( true ) );
		if ( $reject ) {
			return $reject;
		}
		$args = array(
			'post_title'    => $flags['post_title'] ?? __( 'Untitled', 'vibe-ai' ),
			'post_content'  => $flags['post_content'] ?? '',
			'post_status'   => $flags['post_status'] ?? 'draft',
			'post_type'     => $flags['post_type'] ?? 'post',
			'post_excerpt'  => $flags['post_excerpt'] ?? '',
			'post_author'   => get_current_user_id(),
		);
		if ( isset( $flags['post_content_base64'] ) ) {
			$decoded = $this->decode_content_base64( $flags['post_content_base64'] );
			if ( is_wp_error( $decoded ) ) {
				return $this->error_result( $decoded->get_error_message() );
			}
			$args['post_content'] = $decoded;
		}
		if ( isset( $flags['post_name'] ) )      $args['post_name']      = $flags['post_name'];
		if ( isset( $flags['post_parent'] ) )     $args['post_parent']    = (int) $flags['post_parent'];
		if ( isset( $flags['menu_order'] ) )      $args['menu_order']     = (int) $flags['menu_order'];
		if ( isset( $flags['comment_status'] ) )  $args['comment_status'] = $flags['comment_status'];
		if ( isset( $flags['post_date'] ) ) {
			if ( false === strtotime( $flags['post_date'] ) ) {
				return $this->error_result( __( 'Invalid --post_date. Use a site-local date like "2025-03-01 09:00:00".', 'vibe-ai' ) );
			}
			// Site-local, matching real WP-CLI; wp_insert_post derives post_date_gmt.
			$args['post_date'] = $flags['post_date'];
		}

		if ( 'wpcode' === $args['post_type'] ) {
			return $this->wpcode_write_refusal();
		}

		$cap_check = $this->check_post_caps( $args['post_type'], 'create', null, $args['post_status'] );
		if ( is_wp_error( $cap_check ) ) {
			return $cap_check;
		}

		$id = wp_insert_post( $args, true );
		if ( is_wp_error( $id ) ) {
			return $this->error_result( $id->get_error_message() );
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Post created: #{$id} ({$args['post_type']})",
			'action_label' => 'Edit Post',
			'admin_url'    => admin_url( "post.php?post={$id}&action=edit" ),
		) );

		// --porcelain returns the bare ID, matching upstream. The Divi skill chains
		// on it, so this must stay machine-readable with nothing around it.
		if ( ! empty( $flags['porcelain'] ) ) {
			return $this->success_result( $id );
		}

		return $this->success_result( array(
			'ID'      => $id,
			/* translators: 1: post type, 2: post ID */
			'message' => sprintf( __( 'Created %1$s #%2$d.', 'vibe-ai' ), $args['post_type'], $id ),
		) );
	}


	private function handle_post_update( $positional, $flags ) {
		$ids = $this->positional_ids( $positional );
		if ( empty( $ids ) ) {
			return $this->error_result( __( 'Post ID required. Usage: post update <id> [<id>...] --post_title="New Title"', 'vibe-ai' ) );
		}

		$updatable = array( 'post_title', 'post_content', 'post_status', 'post_excerpt',
			'post_name', 'post_parent', 'menu_order', 'comment_status', 'post_type' );
		$reject = $this->reject_unknown_flags(
			'post update',
			$flags,
			array_merge( $updatable, array( 'post_content_base64' ) ),
			$this->post_write_unsupported_flags( false )
		);
		if ( $reject ) {
			return $reject;
		}
		$fields = array();
		foreach ( $updatable as $field ) {
			if ( isset( $flags[ $field ] ) ) {
				$fields[ $field ] = $flags[ $field ];
			}
		}
		if ( isset( $flags['post_content_base64'] ) ) {
			$decoded = $this->decode_content_base64( $flags['post_content_base64'] );
			if ( is_wp_error( $decoded ) ) {
				return $this->error_result( $decoded->get_error_message() );
			}
			$fields['post_content'] = $decoded;
		}
		if ( empty( $fields ) ) {
			return $this->error_result( __( 'No fields to update. Use flags like --post_title, --post_content, --post_status.', 'vibe-ai' ) );
		}
		if ( isset( $fields['post_type'] ) && 'wpcode' === $fields['post_type'] ) {
			return $this->wpcode_write_refusal();
		}

		$results = array();
		$ok      = 0;
		foreach ( $ids as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				$results[] = array( 'id' => $post_id, 'status' => 'error', 'error' => 'not found' );
				continue;
			}
			if ( 'wpcode' === $post->post_type ) {
				$results[] = array( 'id' => $post_id, 'status' => 'error', 'error' => 'WPCode snippet — use the code_snippet tool' );
				continue;
			}
			$cap_check = $this->check_post_caps( $post->post_type, 'update', $post_id, $fields['post_status'] ?? null );
			if ( is_wp_error( $cap_check ) ) {
				$results[] = array( 'id' => $post_id, 'status' => 'error', 'error' => $cap_check->get_error_message() );
				continue;
			}
			$res = wp_update_post( array_merge( array( 'ID' => $post_id ), $fields ), true );
			if ( is_wp_error( $res ) ) {
				$results[] = array( 'id' => $post_id, 'status' => 'error', 'error' => $res->get_error_message() );
				continue;
			}
			$ok++;
			$results[] = array( 'id' => $post_id, 'status' => 'updated' );
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => count( $ids ) > 1 ? "Posts updated: {$ok}/" . count( $ids ) : "Post updated: #{$ids[0]}",
			'action_label' => 'Refresh',
		) );

		return $this->bulk_result( 'updated', $ok, $ids, $results );
	}


	private function handle_post_delete( $positional, $flags ) {
		$ids = $this->positional_ids( $positional );
		if ( empty( $ids ) ) {
			return $this->error_result( __( 'Post ID required. Usage: post delete <id> [<id>...] [--force]', 'vibe-ai' ) );
		}

		$force   = ! empty( $flags['force'] );
		$results = array();
		$ok      = 0;
		foreach ( $ids as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				$results[] = array( 'id' => $post_id, 'status' => 'error', 'error' => 'not found' );
				continue;
			}
			$cap_check = $this->check_post_caps( $post->post_type, 'delete', $post_id );
			if ( is_wp_error( $cap_check ) ) {
				$results[] = array( 'id' => $post_id, 'status' => 'error', 'error' => $cap_check->get_error_message() );
				continue;
			}
			$res = $force ? wp_delete_post( $post_id, true ) : wp_trash_post( $post_id );
			if ( ! $res ) {
				$results[] = array( 'id' => $post_id, 'status' => 'error', 'error' => 'delete failed' );
				continue;
			}
			$ok++;
			$results[] = array( 'id' => $post_id, 'status' => $force ? 'deleted' : 'trashed' );
		}

		$action = $force ? __( 'permanently deleted', 'vibe-ai' ) : __( 'trashed', 'vibe-ai' );
		WPVibe_Change_Tracker::mark( array(
			'summary'      => count( $ids ) > 1 ? "Posts {$action}: {$ok}/" . count( $ids ) : "Post {$action}: #{$ids[0]}",
			'action_label' => 'Refresh',
		) );

		return $this->bulk_result( $action, $ok, $ids, $results );
	}


	private function handle_post_meta_update( $positional, $flags ) {
		if ( count( $positional ) < 3 ) {
			return $this->error_result( __( 'Usage: post meta update <post_id> <key> <value>', 'vibe-ai' ) );
		}

		$post_id = (int) $positional[0];
		$post    = get_post( $post_id );
		if ( ! $post ) {
			/* translators: %s: post ID */
			return $this->error_result( sprintf( __( 'Post %s not found.', 'vibe-ai' ), $positional[0] ) );
		}
		if ( 'wpcode' === $post->post_type ) {
			return $this->wpcode_write_refusal();
		}

		$key = $positional[1];
		// Block protected meta keys (all '_'-prefixed, plus registered protected) unless --force.
		if ( empty( $flags['force'] ) && is_protected_meta( $key, 'post' ) ) {
			return $this->error_result(
				sprintf(
					/* translators: %s: meta key */
					__( 'Meta key \'%s\' is a protected/internal key. Use --force to override.', 'vibe-ai' ),
					$key
				)
			);
		}

		$cap_check = $this->check_post_caps( $post->post_type, 'update', $post_id );
		if ( is_wp_error( $cap_check ) ) {
			return $cap_check;
		}

		$value = $positional[2];
		// Auto-decode JSON values.
		$decoded = json_decode( $value, true );
		if ( null !== $decoded ) {
			$value = $decoded;
		}

		update_post_meta( $post_id, $key, $value );

		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Post meta updated: #{$post_id} → {$key}",
			'action_label' => 'Refresh',
		) );

		/* translators: 1: meta key, 2: post ID */
		return $this->success_result( array( 'message' => sprintf( __( 'Updated meta \'%1$s\' on post #%2$d.', 'vibe-ai' ), $key, $post_id ) ) );
	}


	private function handle_post_meta_add( $positional, $flags ) {
		if ( count( $positional ) < 3 ) {
			return $this->error_result( __( 'Usage: post meta add <post_id> <key> <value>', 'vibe-ai' ) );
		}

		$post_id = (int) $positional[0];
		$post    = get_post( $post_id );
		if ( ! $post ) {
			/* translators: %s: post ID */
			return $this->error_result( sprintf( __( 'Post %s not found.', 'vibe-ai' ), $positional[0] ) );
		}
		if ( 'wpcode' === $post->post_type ) {
			return $this->wpcode_write_refusal();
		}

		$key = $positional[1];
		if ( empty( $flags['force'] ) && is_protected_meta( $key, 'post' ) ) {
			return $this->error_result(
				sprintf(
					/* translators: %s: meta key */
					__( 'Meta key \'%s\' is a protected/internal key. Use --force to override.', 'vibe-ai' ),
					$key
				)
			);
		}

		$cap_check = $this->check_post_caps( $post->post_type, 'update', $post_id );
		if ( is_wp_error( $cap_check ) ) {
			return $cap_check;
		}

		$value   = $positional[2];
		$decoded = json_decode( $value, true );
		if ( null !== $decoded ) {
			$value = $decoded;
		}

		add_post_meta( $post_id, $key, $value );

		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Post meta added: #{$post_id} → {$key}",
			'action_label' => 'Refresh',
		) );

		/* translators: 1: meta key, 2: post ID */
		return $this->success_result( array( 'message' => sprintf( __( 'Added meta \'%1$s\' on post #%2$d.', 'vibe-ai' ), $key, $post_id ) ) );
	}


	private function handle_post_meta_delete( $positional, $flags ) {
		if ( count( $positional ) < 2 ) {
			return $this->error_result( __( 'Usage: post meta delete <post_id> <key>', 'vibe-ai' ) );
		}

		$post_id = (int) $positional[0];
		$post    = get_post( $post_id );
		if ( ! $post ) {
			/* translators: %s: post ID */
			return $this->error_result( sprintf( __( 'Post %s not found.', 'vibe-ai' ), $positional[0] ) );
		}
		if ( 'wpcode' === $post->post_type ) {
			return $this->wpcode_write_refusal();
		}

		$key = $positional[1];
		if ( empty( $flags['force'] ) && is_protected_meta( $key, 'post' ) ) {
			return $this->error_result(
				sprintf(
					/* translators: %s: meta key */
					__( 'Meta key \'%s\' is a protected/internal key. Use --force to override.', 'vibe-ai' ),
					$key
				)
			);
		}

		$cap_check = $this->check_post_caps( $post->post_type, 'update', $post_id );
		if ( is_wp_error( $cap_check ) ) {
			return $cap_check;
		}

		// Optional third positional = meta value: delete only the matching row of a multi-value meta.
		$value = isset( $positional[2] ) ? $positional[2] : '';
		delete_post_meta( $post_id, $key, $value );

		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Post meta deleted: #{$post_id} → {$key}",
			'action_label' => 'Refresh',
		) );

		/* translators: 1: meta key, 2: post ID */
		return $this->success_result( array( 'message' => sprintf( __( 'Deleted meta \'%1$s\' from post #%2$d.', 'vibe-ai' ), $key, $post_id ) ) );
	}


	private function handle_post_term_set( $positional, $flags ) {
		return $this->post_term_write( 'set', $positional, $flags );
	}


	private function handle_post_term_add( $positional, $flags ) {
		return $this->post_term_write( 'add', $positional, $flags );
	}


	private function handle_post_term_remove( $positional, $flags ) {
		return $this->post_term_write( 'remove', $positional, $flags );
	}


	/**
	 * Shared worker for post term set/add/remove. Terms are resolved before
	 * writing (never auto-created): wp_set_object_terms() would silently create
	 * missing slugs and treat numeric strings as term IDs — both footguns.
	 */
	private function post_term_write( $mode, $positional, $flags ) {
		if ( count( $positional ) < 2 ) {
			/* translators: %s: subcommand (set/add/remove) */
			return $this->error_result( sprintf( __( 'Usage: post term %s <id> <taxonomy> <term>... [--by=slug|id]', 'vibe-ai' ), $mode ) );
		}

		$post_id = (int) $positional[0];
		$post    = get_post( $post_id );
		if ( ! $post ) {
			/* translators: %s: post ID */
			return $this->error_result( sprintf( __( 'Post %s not found.', 'vibe-ai' ), $positional[0] ) );
		}
		if ( 'wpcode' === $post->post_type ) {
			return $this->wpcode_write_refusal();
		}

		$taxonomy = $positional[1];
		$tax      = get_taxonomy( $taxonomy );
		if ( ! $tax ) {
			/* translators: %s: taxonomy slug */
			return $this->error_result( sprintf( __( 'Taxonomy \'%s\' does not exist. Run `taxonomy list` to see registered taxonomies.', 'vibe-ai' ), $taxonomy ) );
		}
		if ( ! is_object_in_taxonomy( $post->post_type, $taxonomy ) ) {
			/* translators: 1: taxonomy slug, 2: post type */
			return $this->error_result( sprintf( __( 'Taxonomy \'%1$s\' is not registered for post type \'%2$s\'.', 'vibe-ai' ), $taxonomy, $post->post_type ) );
		}

		$cap_check = $this->check_post_caps( $post->post_type, 'update', $post_id );
		if ( is_wp_error( $cap_check ) ) {
			return $cap_check;
		}
		if ( ! current_user_can( $tax->cap->assign_terms ) ) {
			/* translators: %s: WordPress capability name */
			return new WP_Error( 'insufficient_cap', sprintf( __( 'You do not have the required capability (%s) to assign terms in this taxonomy.', 'vibe-ai' ), $tax->cap->assign_terms ), WPVibe_Error_Contract::data( 'capability_role', false, array( 'status' => 403, 'capability' => $tax->cap->assign_terms ) ) );
		}

		if ( 'remove' === $mode && ! empty( $flags['all'] ) ) {
			$result = wp_set_object_terms( $post_id, array(), $taxonomy );
			if ( is_wp_error( $result ) ) {
				return $this->error_result( $result->get_error_message() );
			}
			WPVibe_Change_Tracker::mark( array(
				'summary'      => "Post terms cleared: #{$post_id} ({$taxonomy})",
				'action_label' => 'Refresh',
			) );
			/* translators: 1: taxonomy slug, 2: post ID */
			return $this->success_result( array( 'message' => sprintf( __( 'Removed all \'%1$s\' terms from post #%2$d.', 'vibe-ai' ), $taxonomy, $post_id ) ) );
		}

		$inputs = array_slice( $positional, 2 );
		if ( empty( $inputs ) ) {
			/* translators: %s: subcommand (set/add/remove) */
			return $this->error_result( sprintf( __( 'At least one term is required. Usage: post term %s <id> <taxonomy> <term>... [--by=slug|id]', 'vibe-ai' ), $mode ) );
		}

		$by = isset( $flags['by'] ) ? $flags['by'] : 'slug';
		if ( ! in_array( $by, array( 'slug', 'id' ), true ) ) {
			return $this->error_result( __( 'Invalid --by value. Use --by=slug (default) or --by=id.', 'vibe-ai' ) );
		}

		$term_ids = array();
		$missing  = array();
		foreach ( $inputs as $input ) {
			$term = 'id' === $by
				? get_term_by( 'id', (int) $input, $taxonomy )
				: get_term_by( 'slug', $input, $taxonomy );
			if ( $term ) {
				$term_ids[] = (int) $term->term_id;
			} else {
				$missing[] = $input;
			}
		}
		if ( ! empty( $missing ) ) {
			return $this->error_result(
				sprintf(
					/* translators: 1: taxonomy slug, 2: missing terms, 3: taxonomy slug */
					__( 'Term(s) not found in taxonomy \'%1$s\': %2$s. Run `term list %3$s` to see existing slugs, or create the term first via rest_api (POST /wp/v2/<rest_base>).', 'vibe-ai' ),
					$taxonomy,
					implode( ', ', $missing ),
					$taxonomy
				)
			);
		}

		if ( 'remove' === $mode ) {
			$result = wp_remove_object_terms( $post_id, $term_ids, $taxonomy );
		} else {
			$result = wp_set_object_terms( $post_id, $term_ids, $taxonomy, 'add' === $mode );
		}
		if ( is_wp_error( $result ) ) {
			return $this->error_result( $result->get_error_message() );
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Post terms {$mode}: #{$post_id} ({$taxonomy})",
			'action_label' => 'Refresh',
		) );

		$verbs   = array( 'set' => __( 'set', 'vibe-ai' ), 'add' => __( 'added', 'vibe-ai' ), 'remove' => __( 'removed', 'vibe-ai' ) );
		$current = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'slugs' ) );
		return $this->success_result( array(
			/* translators: 1: past-tense verb, 2: taxonomy slug, 3: post ID */
			'message' => sprintf( __( 'Terms %1$s in \'%2$s\' for post #%3$d.', 'vibe-ai' ), $verbs[ $mode ], $taxonomy, $post_id ),
			'terms'   => is_wp_error( $current ) ? array() : array_values( $current ),
		) );
	}


	private function handle_media_image_size( $positional, $flags ) {
		$results = array(
			array( 'name' => 'full', 'width' => null, 'height' => null, 'crop' => false ),
		);
		$sizes = function_exists( 'wp_get_registered_image_subsizes' ) ? wp_get_registered_image_subsizes() : array();
		foreach ( $sizes as $name => $size ) {
			$results[] = array(
				'name'   => $name,
				'width'  => (int) ( $size['width'] ?? 0 ),
				'height' => (int) ( $size['height'] ?? 0 ),
				'crop'   => ! empty( $size['crop'] ),
			);
		}
		return $this->success_result( $this->filter_fields( $results, $flags ) );
	}


	private function handle_post_type_list( $positional, $flags ) {
		$results = array();
		foreach ( get_post_types( array(), 'objects' ) as $type ) {
			$results[] = array(
				'name'         => $type->name,
				'label'        => $type->label,
				'public'       => $type->public ? 'true' : 'false',
				'hierarchical' => $type->hierarchical ? 'true' : 'false',
				'description'  => $type->description,
			);
		}
		return $this->success_result( $this->filter_fields( $results, $flags ) );
	}

}
