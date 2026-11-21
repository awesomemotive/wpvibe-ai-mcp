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
		$known = array( 'post_type', 'post_status', 'posts_per_page', 'orderby', 'order', 's', 'author', 'year', 'monthnum', 'offset', 'paged', 'fields', 'format' );
		foreach ( array_keys( $flags ) as $flag ) {
			if ( ! in_array( $flag, $known, true ) ) {
				/* translators: %s: the unsupported flag name */
				return $this->error_result( sprintf( __( 'post list does not support --%s here. Supported filters: s, author, year, monthnum, post_type, post_status, posts_per_page, offset, paged, orderby, order. For richer queries use the REST API (/wp/v2/posts).', 'vibe-ai' ), $flag ) );
			}
		}
		$paging = $this->paging_args( 'post list', $flags, (int) $args['posts_per_page'] );
		if ( is_array( $paging ) && isset( $paging['exit_code'] ) ) {
			return $paging;
		}
		$args    = array_merge( $args, $paging );
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
			$this->truncation_notice( $results, (int) $args['posts_per_page'], 'post list', $this->next_page_hint( $flags, (int) $args['posts_per_page'] ) )
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

		$allowed = $this->meta_read_permission( 'post', $post_id, empty( $positional[1] ) ? null : (string) $positional[1] );
		if ( true !== $allowed ) {
			return $allowed;
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
		$hidden  = 0;
		foreach ( $meta as $key => $values ) {
			// Hide internal meta unless --all flag is set.
			if ( empty( $flags['all'] ) && strpos( $key, '_' ) === 0 ) {
				continue;
			}
			if ( ! $this->meta_key_readable( 'post', $post_id, (string) $key ) ) {
				$hidden++;
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

		return $this->success_result( $results, self::meta_hidden_note( $hidden ) );
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

	private function handle_term_get( $positional, $flags ) {
		$reject = $this->reject_unknown_flags( 'term get', $flags, array( 'by', 'field', 'fields', 'format' ) );
		if ( $reject ) {
			return $reject;
		}
		$format = isset( $flags['format'] ) ? strtolower( (string) $flags['format'] ) : '';
		if ( '' !== $format && 'json' !== $format ) {
			/* translators: %s: requested format */
			return $this->error_result( sprintf( __( '`term get` returns JSON; --format=%s is not emulated. Use --field=<field> or --fields=<list> to narrow the output.', 'vibe-ai' ), $format ) );
		}
		$taxonomy = isset( $positional[0] ) ? (string) $positional[0] : '';
		$ident    = isset( $positional[1] ) ? (string) $positional[1] : '';
		if ( '' === $taxonomy || '' === $ident ) {
			return $this->error_result( __( 'Usage: term get <taxonomy> <term> [--by=id|slug] [--field=<field>] [--fields=<fields>]', 'vibe-ai' ) );
		}
		$extra = $this->too_many_positionals( $positional, 2 );
		if ( $extra ) {
			return $extra;
		}
		if ( ! get_taxonomy( $taxonomy ) ) {
			/* translators: %s: taxonomy slug */
			return $this->error_result( sprintf( __( 'Taxonomy %s doesn\'t exist. Run `taxonomy list` to see registered taxonomies.', 'vibe-ai' ), $taxonomy ) );
		}
		$term = $this->resolve_term( $taxonomy, $ident, $flags );
		if ( is_array( $term ) ) {
			return $term;
		}
		if ( ! $term ) {
			return $this->error_result( __( 'Term doesn\'t exist.', 'vibe-ai' ) );
		}
		$row        = $this->term_row( $term );
		$link       = get_term_link( $term );
		$row['url'] = is_wp_error( $link ) ? '' : (string) $link;
		if ( isset( $flags['field'] ) ) {
			$field = (string) $flags['field'];
			if ( ! array_key_exists( $field, $row ) ) {
				/* translators: 1: field name, 2: supported field list */
				return $this->error_result( sprintf( __( 'Invalid field: %1$s. Supported fields: %2$s.', 'vibe-ai' ), $field, implode( ', ', array_keys( $row ) ) ) );
			}
			if ( 'json' === $format ) {
				return $this->success_result( $row[ $field ] );
			}
			return array( 'exit_code' => 0, 'stdout' => (string) $row[ $field ], 'stderr' => '' );
		}
		return $this->success_result( $this->filter_fields( array( $row ), $flags )[0] );
	}


	private function handle_post_term_list( $positional, $flags ) {
		$reject = $this->reject_unknown_flags( 'post term list', $flags, array( 'field', 'fields', 'format' ) );
		if ( ! $reject ) {
			$reject = $this->reject_unsupported_format( 'post term list', $flags );
		}
		if ( $reject ) {
			return $reject;
		}
		if ( count( $positional ) < 2 ) {
			return $this->error_result( __( 'Usage: post term list <id> <taxonomy>... [--field=<field>] [--fields=<fields>] [--format=json|ids|count]', 'vibe-ai' ) );
		}
		$post = get_post( (int) $positional[0] );
		if ( ! $post ) {
			/* translators: %s: post ID */
			return $this->error_result( sprintf( __( 'Could not find the post with ID %s.', 'vibe-ai' ), $positional[0] ) );
		}
		$taxonomies = array_values( array_unique( array_map( 'strval', array_slice( $positional, 1 ) ) ) );
		foreach ( $taxonomies as $taxonomy ) {
			if ( ! is_object_in_taxonomy( $post->post_type, $taxonomy ) ) {
				/* translators: 1: taxonomy slug, 2: post type */
				return $this->error_result( sprintf( __( 'Invalid taxonomy %1$s. It is not registered for post type \'%2$s\'; run `taxonomy list` to see registered taxonomies.', 'vibe-ai' ), $taxonomy, $post->post_type ) );
			}
		}
		$terms = wp_get_object_terms( (int) $post->ID, $taxonomies );
		if ( is_wp_error( $terms ) ) {
			return $this->error_result( $terms->get_error_message() );
		}
		$rows = array();
		foreach ( (array) $terms as $term ) {
			$rows[] = $this->term_row( $term );
		}
		$format = isset( $flags['format'] ) ? strtolower( (string) $flags['format'] ) : '';
		if ( 'ids' === $format || 'count' === $format ) {
			return $this->success_result( $this->format_rows( $rows, $flags, 'term_id' ) );
		}
		if ( isset( $flags['field'] ) ) {
			$field  = (string) $flags['field'];
			$fields = array_keys( $this->term_row( (object) array() ) );
			if ( ! in_array( $field, $fields, true ) ) {
				/* translators: 1: field name, 2: supported field list */
				return $this->error_result( sprintf( __( 'Invalid field: %1$s. Supported fields: %2$s.', 'vibe-ai' ), $field, implode( ', ', $fields ) ) );
			}
			return $this->success_result( array_column( $rows, $field ) );
		}
		if ( empty( $flags['fields'] ) ) {
			// Upstream's default columns; the rest are opt-in through --fields.
			$flags['fields'] = 'term_id,name,slug,taxonomy';
		}
		return $this->success_result( $this->filter_fields( $rows, $flags ) );
	}


	/** WP_Term as a plain row, ints cast (core hands back numeric strings from some cache paths). */
	private function term_row( $term ) {
		return array(
			'term_id'          => (int) ( $term->term_id ?? 0 ),
			'name'             => (string) ( $term->name ?? '' ),
			'slug'             => (string) ( $term->slug ?? '' ),
			'term_group'       => (int) ( $term->term_group ?? 0 ),
			'term_taxonomy_id' => (int) ( $term->term_taxonomy_id ?? 0 ),
			'taxonomy'         => (string) ( $term->taxonomy ?? '' ),
			'description'      => (string) ( $term->description ?? '' ),
			'parent'           => (int) ( $term->parent ?? 0 ),
			'count'            => (int) ( $term->count ?? 0 ),
		);
	}


	/** Term meta is keyed by bare term ID (upstream check_object_id). Returns WP_Term, or an error_result array. */
	private function term_meta_term( $ident ) {
		$term = is_numeric( $ident ) ? get_term( (int) $ident ) : null;
		if ( $term && ! is_wp_error( $term ) ) {
			return $term;
		}
		/* translators: %s: term ID */
		$message = sprintf( __( 'Could not find the term with ID %s.', 'vibe-ai' ), $ident );
		if ( ! is_numeric( $ident ) ) {
			$message .= ' ' . __( 'term meta takes a numeric term ID; look it up with `term get <taxonomy> <slug> --by=slug --field=term_id`.', 'vibe-ai' );
		}
		return $this->error_result( $message );
	}


	private function handle_term_meta_get( $positional, $flags ) {
		if ( count( $positional ) < 2 ) {
			return $this->handle_term_meta_list( $positional, $flags );
		}
		$reject = $this->reject_unknown_flags( 'term meta get', $flags, array( 'format' ) );
		if ( $reject ) {
			return $reject;
		}
		$format = isset( $flags['format'] ) ? strtolower( (string) $flags['format'] ) : '';
		if ( '' !== $format && 'json' !== $format ) {
			/* translators: %s: requested format */
			return $this->error_result( sprintf( __( '`term meta get` prints a text value as text and an array as JSON; --format=%s is not emulated. Use --format=json for JSON of any value.', 'vibe-ai' ), $format ) );
		}
		$extra = $this->too_many_positionals( $positional, 2 );
		if ( $extra ) {
			return $extra;
		}
		$term = $this->term_meta_term( $positional[0] );
		if ( is_array( $term ) ) {
			return $term;
		}
		$key     = (string) $positional[1];
		$allowed = $this->meta_read_permission( 'term', (int) $term->term_id, $key );
		if ( true !== $allowed ) {
			return $allowed;
		}
		$value = get_term_meta( (int) $term->term_id, $key, true );
		if ( '' === $value ) {
			// Upstream exits 1 with no output; say why.
			/* translators: 1: meta key, 2: term ID */
			return $this->error_result( sprintf( __( 'No \'%1$s\' meta found for term %2$d.', 'vibe-ai' ), $key, (int) $term->term_id ) );
		}
		if ( 'json' === $format ) {
			return array( 'exit_code' => 0, 'stdout' => wp_json_encode( $value ), 'stderr' => '' );
		}
		return array(
			'exit_code' => 0,
			'stdout'    => is_scalar( $value ) ? (string) $value : wp_json_encode( $value, JSON_PRETTY_PRINT ),
			'stderr'    => '',
		);
	}


	private function handle_term_meta_list( $positional, $flags ) {
		$sorted = __( 'Rows come back in storage order; sort them yourself.', 'vibe-ai' );
		$reject = $this->reject_unknown_flags( 'term meta list', $flags, array( 'keys', 'fields', 'format', 'unserialize' ), array(
			'orderby' => $sorted,
			'order'   => $sorted,
		) );
		if ( $reject ) {
			return $reject;
		}
		$format = isset( $flags['format'] ) ? strtolower( (string) $flags['format'] ) : '';
		if ( '' !== $format && 'json' !== $format && 'count' !== $format ) {
			/* translators: %s: requested format */
			return $this->error_result( sprintf( __( '`term meta list` supports --format=json|count; --format=%s is not emulated. Use --fields=<list> to narrow the columns instead.', 'vibe-ai' ), $format ) );
		}
		if ( ! isset( $positional[0] ) || '' === (string) $positional[0] ) {
			return $this->error_result( __( 'Usage: term meta list <term-id> [--keys=<key,key>]', 'vibe-ai' ) );
		}
		$extra = $this->too_many_positionals( $positional, 1 );
		if ( $extra ) {
			return $extra;
		}
		$term = $this->term_meta_term( $positional[0] );
		if ( is_array( $term ) ) {
			return $term;
		}
		$term_id = (int) $term->term_id;
		$allowed = $this->meta_read_permission( 'term', $term_id );
		if ( true !== $allowed ) {
			return $allowed;
		}
		$only    = isset( $flags['keys'] ) ? array_filter( array_map( 'trim', explode( ',', (string) $flags['keys'] ) ) ) : array();
		$rows    = array();
		$hidden  = 0;
		// One row per termmeta row, values raw like upstream unless --unserialize.
		foreach ( (array) get_term_meta( $term_id ) as $key => $values ) {
			if ( $only && ! in_array( (string) $key, $only, true ) ) {
				continue;
			}
			if ( ! $this->meta_key_readable( 'term', $term_id, (string) $key ) ) {
				$hidden++;
				continue;
			}
			foreach ( (array) $values as $value ) {
				$rows[] = array(
					'term_id'    => $term_id,
					'meta_key'   => (string) $key,
					'meta_value' => empty( $flags['unserialize'] ) ? $value : maybe_unserialize( (string) $value ),
				);
			}
		}
		if ( 'count' === $format ) {
			return $this->success_result( count( $rows ), self::meta_hidden_note( $hidden ) );
		}
		return $this->success_result( $this->filter_fields( $rows, $flags ), self::meta_hidden_note( $hidden ) );
	}


	private function handle_term_meta_update( $positional, $flags ) {
		return $this->term_meta_write( 'update', $positional, $flags );
	}


	private function handle_term_meta_add( $positional, $flags ) {
		return $this->term_meta_write( 'add', $positional, $flags );
	}


	private function handle_term_meta_delete( $positional, $flags ) {
		return $this->term_meta_write( 'delete', $positional, $flags );
	}


	/** Shared worker for term meta update/add/delete over the core term-meta functions, mirroring the post meta handlers. */
	private function term_meta_write( $mode, $positional, $flags ) {
		$known  = ( 'delete' === $mode ) ? array( 'force' ) : array( 'force', 'format' );
		$reject = $this->reject_unknown_flags( 'term meta ' . $mode, $flags, $known, array(
			'all' => __( 'Deleting every meta row of a term at once is not emulated. List the keys with `term meta list <term-id>` and delete them by name.', 'vibe-ai' ),
		) );
		if ( $reject ) {
			return $reject;
		}
		if ( count( $positional ) < ( 'delete' === $mode ? 2 : 3 ) ) {
			$usage = ( 'delete' === $mode )
				? __( 'Usage: term meta delete <term-id> <key> [<value>]', 'vibe-ai' )
				/* translators: %s: subcommand */
				: sprintf( __( 'Usage: term meta %s <term-id> <key> <value>', 'vibe-ai' ), $mode );
			return $this->error_result( $usage );
		}
		$extra = $this->too_many_positionals( $positional, 3 );
		if ( $extra ) {
			return $extra;
		}
		$term = $this->term_meta_term( $positional[0] );
		if ( is_array( $term ) ) {
			return $term;
		}
		$term_id = (int) $term->term_id;
		// The key as core stores it: update_metadata() and friends unslash it (#1617).
		$key     = wp_unslash( (string) $positional[1] );
		$allowed = $this->meta_write_permission( 'term', 'update' === $mode ? 'edit' : $mode, $term_id, $key, ! empty( $flags['force'] ) );
		if ( true !== $allowed ) {
			return $allowed;
		}
		// edit_term maps to the term's own taxonomy edit cap, which core's REST term meta writes check too.
		if ( ! current_user_can( 'edit_term', $term_id ) ) {
			return new WP_Error( 'insufficient_cap', __( 'You do not have the required capability (edit_term) to change this term\'s meta.', 'vibe-ai' ), WPVibe_Error_Contract::data( 'capability_role', false, array( 'status' => 403, 'capability' => 'edit_term' ) ) );
		}

		$notice = '';
		if ( 'delete' === $mode ) {
			// The optional value matches a row as given, like post meta delete.
			$value = isset( $positional[2] ) ? (string) $positional[2] : '';
			// delete_metadata() unslashes the value it matches on.
			if ( ! delete_term_meta( $term_id, wp_slash( $key ), wp_slash( $value ) ) ) {
				/* translators: 1: meta key, 2: term ID */
				return $this->error_result( sprintf( __( 'Failed to delete \'%1$s\' meta from term %2$d (key or value not found).', 'vibe-ai' ), $key, $term_id ) );
			}
			/* translators: 1: meta key, 2: term ID */
			$message = sprintf( __( 'Deleted meta \'%1$s\' from term %2$d.', 'vibe-ai' ), $key, $term_id );
		} else {
			// Same value rules as post and user meta (#590): unflagged JSON follows the stored shape.
			$stored = metadata_exists( 'term', $term_id, $key ) ? get_term_meta( $term_id, $key, true ) : null;
			$parsed = $this->parse_meta_value( $positional[2], $flags, $key, $stored );
			if ( isset( $parsed['error'] ) ) {
				return $this->error_result( $parsed['error'] );
			}
			$value  = $parsed['value'];
			$notice = $parsed['notice'] ?? '';
			if ( 'update' === $mode && get_term_meta( $term_id, $key, true ) === $value ) {
				// Core returns false for an unchanged value; upstream reports it as success with no write.
				return $this->success_result( array(
					/* translators: 1: meta key, 2: term ID */
					'message' => sprintf( __( 'Value passed for \'%1$s\' on term %2$d is unchanged.', 'vibe-ai' ), $key, $term_id ),
				) );
			}
			// update_metadata()/add_metadata() unslash on the way in.
			$result = ( 'update' === $mode )
				? update_term_meta( $term_id, wp_slash( $key ), wp_slash( $value ) )
				: add_term_meta( $term_id, wp_slash( $key ), wp_slash( $value ) );
			if ( is_wp_error( $result ) ) {
				return $this->error_result( $result->get_error_message() );
			}
			if ( false === $result ) {
				/* translators: 1: subcommand, 2: meta key, 3: term ID */
				return $this->error_result( sprintf( __( 'Failed to %1$s \'%2$s\' meta on term %3$d.', 'vibe-ai' ), $mode, $key, $term_id ) );
			}
			$message = ( 'update' === $mode )
				/* translators: 1: meta key, 2: term ID */
				? sprintf( __( 'Updated meta \'%1$s\' on term %2$d.', 'vibe-ai' ), $key, $term_id )
				/* translators: 1: meta key, 2: term ID */
				: sprintf( __( 'Added meta \'%1$s\' on term %2$d.', 'vibe-ai' ), $key, $term_id );
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Term meta {$mode}: #{$term_id} → {$key}",
			'action_label' => 'Refresh',
		) );
		return $this->success_result( array( 'message' => $message ), $notice );
	}


	private function handle_term_create( $positional, $flags ) {
		$reject = $this->reject_unknown_flags( 'term create', $flags, array( 'slug', 'description', 'parent', 'porcelain' ) );
		if ( $reject ) {
			return $reject;
		}
		$taxonomy = isset( $positional[0] ) ? (string) $positional[0] : '';
		$name     = isset( $positional[1] ) ? trim( (string) $positional[1] ) : '';
		if ( '' === $taxonomy || '' === $name ) {
			return $this->error_result( __( 'Usage: term create <taxonomy> <term> [--slug=<slug>] [--description=<text>] [--parent=<term-id>] [--porcelain]', 'vibe-ai' ) );
		}
		if ( 'nav_menu' === $taxonomy ) {
			return $this->error_result( __( 'Navigation menus are managed with the menu commands (`menu create`, `menu item add-custom`), not term commands: a bare nav_menu term has no items and no theme location.', 'vibe-ai' ) );
		}
		$tax = get_taxonomy( $taxonomy );
		if ( ! $tax ) {
			/* translators: %s: taxonomy slug */
			return $this->error_result( sprintf( __( 'Taxonomy "%s" does not exist. Run `taxonomy list` to see registered taxonomies.', 'vibe-ai' ), $taxonomy ) );
		}
		if ( isset( $flags['parent'] ) && empty( $tax->hierarchical ) ) {
			// Same wrong-success guard as term update: core stores the parent
			// invisibly on flat taxonomies.
			/* translators: %s: taxonomy slug */
			return $this->error_result( sprintf( __( '--parent has no effect: taxonomy "%s" is not hierarchical, and WordPress stores the value invisibly. Remove the flag.', 'vibe-ai' ), $taxonomy ) );
		}
		// The allowlist cap (manage_categories) is the coarse entry gate; the
		// taxonomy's own edit_terms cap is authoritative (custom taxonomies
		// define their own), matching what core's REST term controller checks.
		if ( ! current_user_can( $tax->cap->edit_terms ) ) {
			/* translators: %s: WordPress capability name */
			return new WP_Error( 'insufficient_cap', sprintf( __( 'You do not have the required capability (%s) to create terms in this taxonomy.', 'vibe-ai' ), $tax->cap->edit_terms ), WPVibe_Error_Contract::data( 'capability_role', false, array( 'status' => 403, 'capability' => $tax->cap->edit_terms ) ) );
		}
		$args = array();
		if ( isset( $flags['slug'] ) ) {
			$args['slug'] = sanitize_title( (string) $flags['slug'] );
		}
		if ( isset( $flags['description'] ) ) {
			$args['description'] = sanitize_text_field( (string) $flags['description'] );
		}
		if ( isset( $flags['parent'] ) ) {
			$args['parent'] = (int) $flags['parent'];
		}
		$result = wp_insert_term( wp_slash( $name ), $taxonomy, wp_slash( $args ) );
		if ( is_wp_error( $result ) ) {
			return $this->error_result( $result->get_error_message() );
		}
		$term_id = is_array( $result ) ? (int) ( $result['term_id'] ?? 0 ) : (int) $result;
		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Term created: {$name} ({$taxonomy})",
			'action_label' => 'Refresh',
		) );
		if ( ! empty( $flags['porcelain'] ) ) {
			return $this->success_result( $term_id );
		}
		return $this->success_result( array(
			/* translators: 1: term name, 2: taxonomy slug, 3: term ID */
			'message' => sprintf( __( 'Created term "%1$s" in "%2$s" (term ID %3$d).', 'vibe-ai' ), $name, $taxonomy, $term_id ),
			'term_id' => $term_id,
		) );
	}


	private function handle_term_update( $positional, $flags ) {
		$reject = $this->reject_unknown_flags( 'term update', $flags, array( 'by', 'name', 'slug', 'description', 'parent' ) );
		if ( $reject ) {
			return $reject;
		}
		$taxonomy = isset( $positional[0] ) ? (string) $positional[0] : '';
		$ident    = isset( $positional[1] ) ? (string) $positional[1] : '';
		if ( '' === $taxonomy || '' === $ident ) {
			return $this->error_result( __( 'Usage: term update <taxonomy> <term> [--by=<id|slug>] [--name=<name>] [--slug=<slug>] [--description=<text>] [--parent=<term-id>]', 'vibe-ai' ) );
		}
		if ( 'nav_menu' === $taxonomy ) {
			return $this->error_result( __( 'Navigation menus are managed with the menu commands (`menu list`, `menu item update`), not term commands: nav menus carry item posts and theme locations that term writes would leave inconsistent.', 'vibe-ai' ) );
		}
		$tax = get_taxonomy( $taxonomy );
		if ( ! $tax ) {
			/* translators: %s: taxonomy slug */
			return $this->error_result( sprintf( __( 'Taxonomy "%s" does not exist. Run `taxonomy list` to see registered taxonomies.', 'vibe-ai' ), $taxonomy ) );
		}
		if ( ! current_user_can( $tax->cap->edit_terms ) ) {
			/* translators: %s: WordPress capability name */
			return new WP_Error( 'insufficient_cap', sprintf( __( 'You do not have the required capability (%s) to edit terms in this taxonomy.', 'vibe-ai' ), $tax->cap->edit_terms ), WPVibe_Error_Contract::data( 'capability_role', false, array( 'status' => 403, 'capability' => $tax->cap->edit_terms ) ) );
		}
		$term = $this->resolve_term( $taxonomy, $ident, $flags );
		if ( is_array( $term ) ) {
			return $term; // error_result from the resolver
		}
		if ( ! $term ) {
			// Upstream Term_Command::update error text and exit code.
			return $this->error_result( __( 'Term doesn\'t exist.', 'vibe-ai' ) );
		}
		$args = array();
		if ( isset( $flags['name'] ) ) {
			$args['name'] = (string) $flags['name'];
		}
		if ( isset( $flags['slug'] ) ) {
			$args['slug'] = sanitize_title( (string) $flags['slug'] );
		}
		if ( isset( $flags['description'] ) ) {
			// Same strip as term create: descriptions echo on archive pages.
			$args['description'] = sanitize_text_field( (string) $flags['description'] );
		}
		if ( isset( $flags['parent'] ) ) {
			if ( empty( $tax->hierarchical ) ) {
				/* translators: %s: taxonomy slug */
				return $this->error_result( sprintf( __( '--parent has no effect: taxonomy "%s" is not hierarchical, and WordPress stores the value invisibly. Remove the flag.', 'vibe-ai' ), $taxonomy ) );
			}
			$args['parent'] = (int) $flags['parent'];
		}
		if ( empty( $args ) ) {
			return $this->error_result( __( 'Nothing to update: pass at least one of --name, --slug, --description, --parent.', 'vibe-ai' ) );
		}
		$prior  = array( 'name' => (string) $term->name, 'slug' => (string) $term->slug );
		$result = wp_update_term( (int) $term->term_id, $taxonomy, wp_slash( $args ) );
		if ( is_wp_error( $result ) ) {
			return $this->error_result( $result->get_error_message() );
		}
		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Term updated: {$prior['name']} ({$taxonomy})",
			'action_label' => 'Refresh',
		) );
		$data = array(
			'message' => __( 'Term updated.', 'vibe-ai' ),
			'term_id' => (int) $term->term_id,
			'prior'   => $prior,
		);
		if ( isset( $args['slug'] ) && $args['slug'] !== $prior['slug'] ) {
			$data['note'] = __( 'The slug changed, so the term\'s archive URL moved. Old links 404 unless a redirect is added.', 'vibe-ai' );
		}
		return $this->success_result( $data );
	}


	private function handle_term_delete( $positional, $flags ) {
		$reject = $this->reject_unknown_flags( 'term delete', $flags, array( 'by' ) );
		if ( $reject ) {
			return $reject;
		}
		$taxonomy = isset( $positional[0] ) ? (string) $positional[0] : '';
		$targets  = array_slice( $positional, 1 );
		if ( '' === $taxonomy || empty( $targets ) ) {
			return $this->error_result( __( 'Usage: term delete <taxonomy> <term>... [--by=<id|slug>]', 'vibe-ai' ) );
		}
		if ( 'nav_menu' === $taxonomy ) {
			return $this->error_result( __( 'Navigation menus cannot be deleted through term commands: deleting the nav_menu term strands its menu-item posts and leaves stale theme locations. Delete the menu in wp-admin (Appearance > Menus).', 'vibe-ai' ) );
		}
		$tax = get_taxonomy( $taxonomy );
		if ( ! $tax ) {
			/* translators: %s: taxonomy slug */
			return $this->error_result( sprintf( __( 'Taxonomy "%s" does not exist. Run `taxonomy list` to see registered taxonomies.', 'vibe-ai' ), $taxonomy ) );
		}
		if ( ! current_user_can( $tax->cap->delete_terms ) ) {
			/* translators: %s: WordPress capability name */
			return new WP_Error( 'insufficient_cap', sprintf( __( 'You do not have the required capability (%s) to delete terms in this taxonomy.', 'vibe-ai' ), $tax->cap->delete_terms ), WPVibe_Error_Contract::data( 'capability_role', false, array( 'status' => 403, 'capability' => $tax->cap->delete_terms ) ) );
		}
		if ( ! $this->skip_destructive ) {
			// classify_destructive should have gated this; defense-in-depth.
			return $this->error_result( __( 'term delete requires explicit approval.', 'vibe-ai' ) );
		}
		$deleted   = array();
		$warnings  = array();
		$successes = 0;
		$errors    = 0;
		foreach ( $targets as $ident ) {
			$term = $this->resolve_term( $taxonomy, (string) $ident, $flags );
			if ( is_array( $term ) ) {
				return $term; // non-numeric without --by=slug: refuse the whole batch loudly
			}
			if ( ! $term ) {
				// Upstream semantics: a missing term is a warning, not an error —
				// the batch still reports success (term.feature "already deleted").
				/* translators: 1: taxonomy slug, 2: term identifier */
				$warnings[] = sprintf( __( '%1$s %2$s doesn\'t exist.', 'vibe-ai' ), $taxonomy, $ident );
				continue;
			}
			if ( $this->is_default_term( $taxonomy, (int) $term->term_id ) ) {
				// Core returns 0 here and upstream misreports "doesn't exist";
				// we refuse honestly and count it as a real failure.
				/* translators: 1: term name, 2: taxonomy slug */
				$warnings[] = sprintf( __( '"%1$s" is the default term of "%2$s" and cannot be deleted. Change the default first (e.g. option update default_category <id>).', 'vibe-ai' ), $term->name, $taxonomy );
				$errors++;
				continue;
			}
			$result = wp_delete_term( (int) $term->term_id, $taxonomy );
			if ( is_wp_error( $result ) ) {
				$warnings[] = $result->get_error_message();
				$errors++;
			} elseif ( $result ) {
				$deleted[] = array( 'term_id' => (int) $term->term_id, 'name' => (string) $term->name );
				$successes++;
			} else {
				/* translators: 1: taxonomy slug, 2: term identifier */
				$warnings[] = sprintf( __( '%1$s %2$s doesn\'t exist.', 'vibe-ai' ), $taxonomy, $ident );
			}
		}
		if ( $successes > 0 ) {
			WPVibe_Change_Tracker::mark( array(
				'summary'      => "Terms deleted: {$successes} ({$taxonomy})",
				'action_label' => 'Refresh',
			) );
		}
		$notice = implode( "\n", $warnings );
		if ( $errors > 0 ) {
			// Deletions are permanent; a failing batch must still name what it
			// destroyed or the AI cannot report it without re-querying.
			$destroyed = $deleted
				? "\n" . sprintf(
					/* translators: %s: list of deleted terms */
					__( 'Deleted before the failure: %s.', 'vibe-ai' ),
					implode( ', ', array_map( static function ( $d ) {
						return $d['name'] . ' (' . $d['term_id'] . ')';
					}, $deleted ) )
				)
				: '';
			/* translators: 1: success count, 2: total */
			return $this->error_result( trim( sprintf( __( 'Only deleted %1$d of %2$d terms.', 'vibe-ai' ), $successes, count( $targets ) ) . $destroyed . "\n" . $notice ) );
		}
		return $this->success_result( array(
			/* translators: 1: success count, 2: total */
			'message' => sprintf( __( 'Deleted %1$d of %2$d terms.', 'vibe-ai' ), $successes, count( $targets ) ),
			'deleted' => $deleted,
		), $notice );
	}


	/**
	 * Resolve a term identifier the way upstream's --by contract reads:
	 * --by=slug resolves by slug; the default is id. A non-numeric identifier
	 * without --by=slug is refused loudly (upstream casts it to 0 and reports
	 * exit-0 "already deleted" — a wrong-success an AI caller can't detect).
	 * Returns WP_Term|false, or an error_result array for the refusal.
	 */
	private function resolve_term( $taxonomy, $ident, $flags ) {
		$by = isset( $flags['by'] ) ? (string) $flags['by'] : 'id';
		if ( ! in_array( $by, array( 'id', 'slug' ), true ) ) {
			return $this->error_result( __( '--by accepts "id" or "slug".', 'vibe-ai' ) );
		}
		if ( 'slug' === $by ) {
			return get_term_by( 'slug', $ident, $taxonomy );
		}
		if ( ! is_numeric( $ident ) ) {
			/* translators: %s: term identifier */
			return $this->error_result( sprintf( __( '"%s" is not a numeric term ID. Pass --by=slug to address terms by slug.', 'vibe-ai' ), $ident ) );
		}
		return get_term_by( 'id', (int) $ident, $taxonomy );
	}


	/** Core refuses to delete a taxonomy's default term (returns 0, easy to misread). */
	private function is_default_term( $taxonomy, $term_id ) {
		$default = (int) get_option( 'default_term_' . $taxonomy, 0 );
		if ( 'category' === $taxonomy ) {
			$default = (int) get_option( 'default_category', $default );
		}
		return $default > 0 && $default === $term_id;
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
		// The post type's own capabilities decide for every role, with no manage_options fallback
		// (WordPress.org review 11-17, #1624).
		if ( 'create' === $action ) {
			if ( ! current_user_can( $pt_obj->cap->create_posts ) ) {
				return new WP_Error( 'forbidden', sprintf(
					/* translators: %s: post type label */
					__( 'You do not have permission to create %s.', 'vibe-ai' ),
					$pt_obj->labels->name
				), WPVibe_Error_Contract::data( 'capability_cpt_mapping', false, array( 'status' => 403, 'post_type' => $post_type, 'capability' => $pt_obj->cap->create_posts ) ) );
			}
		} else {
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return new WP_Error( 'forbidden', sprintf(
					/* translators: %d: post ID */
					__( 'You do not have permission to edit post #%d.', 'vibe-ai' ),
					$post_id
				), WPVibe_Error_Contract::data( 'capability_cpt_mapping', false, array( 'status' => 403, 'post_type' => $post_type, 'capability' => 'edit_post' ) ) );
			}
			if ( 'delete' === $action && ! current_user_can( 'delete_post', $post_id ) ) {
				return new WP_Error( 'forbidden', sprintf(
					/* translators: %d: post ID */
					__( 'You do not have permission to delete post #%d.', 'vibe-ai' ),
					$post_id
				), WPVibe_Error_Contract::data( 'capability_cpt_mapping', false, array( 'status' => 403, 'post_type' => $post_type, 'capability' => 'delete_post' ) ) );
			}
		}
		if ( 'publish' === $new_status && ! current_user_can( $pt_obj->cap->publish_posts ) ) {
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
			'porcelain', 'meta_input',
		), $this->post_write_unsupported_flags( true ) );
		if ( $reject ) {
			return $reject;
		}
		$meta_input = $this->parse_meta_input( $flags );
		if ( isset( $meta_input['refusal'] ) ) {
			return $meta_input['refusal'];
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

		if ( WPVibe_Code_Snippet::is_code_post_type( $args['post_type'] ) ) {
			return $this->wpcode_write_refusal();
		}
		if ( isset( $meta_input['meta'] ) && 'revision' === $args['post_type'] ) {
			return $this->meta_input_revision_refusal();
		}

		$cap_check = $this->check_post_caps( $args['post_type'], 'create', null, $args['post_status'] );
		if ( is_wp_error( $cap_check ) ) {
			return $cap_check;
		}
		if ( isset( $meta_input['meta'] ) ) {
			$meta_cap = $this->meta_input_permission( 0, $args['post_type'], $meta_input['meta'] );
			if ( true !== $meta_cap ) {
				return $meta_cap;
			}
		}

		// Core writes meta_input through update_post_meta before save_post and the publish hooks, as upstream WP-CLI does;
		// update_metadata() unslashes, so the values go in slashed like `post meta update`'s.
		if ( isset( $meta_input['meta'] ) ) {
			$args['meta_input'] = wp_slash( $meta_input['meta'] );
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
			array_merge( $updatable, array( 'post_content_base64', 'meta_input' ) ),
			$this->post_write_unsupported_flags( false )
		);
		if ( $reject ) {
			return $reject;
		}
		$meta_input = $this->parse_meta_input( $flags );
		if ( isset( $meta_input['refusal'] ) ) {
			return $meta_input['refusal'];
		}
		// Meta keeps no revisions, so a post update with --meta_input takes one post: post fields alone stay bulk.
		if ( isset( $meta_input['meta'] ) && count( $ids ) > 1 ) {
			return $this->error_result( __( '--meta_input updates one post at a time, since meta has no revisions to undo a bulk write. Run `post update <id> --meta_input=...` once per post.', 'vibe-ai' ) );
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
		if ( isset( $meta_input['meta'] ) ) {
			$fields['meta_input'] = wp_slash( $meta_input['meta'] );
		}
		if ( empty( $fields ) ) {
			return $this->error_result( __( 'No fields to update. Use flags like --post_title, --post_content, --post_status, --meta_input.', 'vibe-ai' ) );
		}
		if ( isset( $fields['post_type'] ) && WPVibe_Code_Snippet::is_code_post_type( $fields['post_type'] ) ) {
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
			if ( WPVibe_Code_Snippet::is_code_post( $post ) ) {
				$results[] = array( 'id' => $post_id, 'status' => 'error', 'error' => 'WPCode snippet — use the code_snippet tool' );
				continue;
			}
			if ( isset( $fields['meta_input'] ) && in_array( 'revision', array( $post->post_type, $fields['post_type'] ?? '' ), true ) ) {
				return $this->meta_input_revision_refusal();
			}
			$cap_check = $this->check_post_caps( $post->post_type, 'update', $post_id, $fields['post_status'] ?? null );
			if ( is_wp_error( $cap_check ) ) {
				$results[] = array( 'id' => $post_id, 'status' => 'error', 'error' => $cap_check->get_error_message() );
				continue;
			}
			if ( isset( $meta_input['meta'] ) ) {
				$meta_cap = $this->meta_input_permission( $post_id, $post->post_type, $meta_input['meta'] );
				if ( true !== $meta_cap ) {
					return $meta_cap;
				}
			}
			$res = wp_update_post( WPVibe_Content_Ops::keep_page_template( array_merge( array( 'ID' => $post_id ), $fields ) ), true );
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

		$result = $this->bulk_result( 'updated', $ok, $ids, $results );
		$note   = WPVibe_Revision_Guard::revisions_off_note( $ids );
		if ( 0 === $result['exit_code'] && '' !== $note ) {
			$result['stderr'] = trim( $result['stderr'] . "\n" . $note );
		}
		return $result;
	}


	private function handle_post_delete( $positional, $flags ) {
		$ids = $this->positional_ids( $positional );
		if ( empty( $ids ) ) {
			return $this->error_result( __( 'Post ID required. Usage: post delete <id> [<id>...] [--force]', 'vibe-ai' ) );
		}

		$force   = ! empty( $flags['force'] );
		$results = array();
		$ok      = 0;
		$deleted = 0;
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
			// With trash off (EMPTY_TRASH_DAYS 0) core's wp_trash_post() deletes for good, like wp-admin's Trash link (#1536).
			$gone = $force || ! EMPTY_TRASH_DAYS || ! get_post( $post_id );
			$deleted += $gone ? 1 : 0;
			$results[] = array( 'id' => $post_id, 'status' => $gone ? 'deleted' : 'trashed' );
		}

		$action = ( $force || ( $deleted && EMPTY_TRASH_DAYS ) ) ? __( 'permanently deleted', 'vibe-ai' )
			: ( $deleted ? __( 'permanently deleted (this site has no trash)', 'vibe-ai' ) : __( 'trashed', 'vibe-ai' ) );
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
		$extra = $this->too_many_positionals( $positional, 3 );
		if ( $extra ) {
			return $extra;
		}

		$post_id = (int) $positional[0];
		$post    = get_post( $post_id );
		if ( ! $post ) {
			/* translators: %s: post ID */
			return $this->error_result( sprintf( __( 'Post %s not found.', 'vibe-ai' ), $positional[0] ) );
		}
		if ( WPVibe_Code_Snippet::is_code_post( $post ) ) {
			return $this->wpcode_write_refusal();
		}

		// The key as core stores it (update_metadata() unslashes it, so `\_secret` lands as `_secret`): every check and
		// the write use this one key.
		$key = wp_unslash( (string) $positional[1] );
		$allowed = $this->meta_write_permission( 'post', 'edit', $post_id, $key, ! empty( $flags['force'] ) );
		if ( true !== $allowed ) {
			return $allowed;
		}

		$cap_check = $this->check_post_caps( $post->post_type, 'update', $post_id );
		if ( is_wp_error( $cap_check ) ) {
			return $cap_check;
		}

		$stored = metadata_exists( 'post', $post_id, $key ) ? get_post_meta( $post_id, $key, true ) : null;
		$parsed = $this->parse_meta_value( $positional[2], $flags, $key, $stored );
		if ( isset( $parsed['error'] ) ) {
			return $this->error_result( $parsed['error'] );
		}
		$value = $parsed['value'];

		// update_metadata() unslashes the key and the value on the way in, as WP-CLI's own handler accounts for.
		update_post_meta( $post_id, wp_slash( $key ), wp_slash( $value ) );

		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Post meta updated: #{$post_id} → {$key}",
			'action_label' => 'Refresh',
		) );

		/* translators: 1: meta key, 2: post ID */
		return $this->success_result( array( 'message' => sprintf( __( 'Updated meta \'%1$s\' on post #%2$d.', 'vibe-ai' ), $key, $post_id ) ), $parsed['notice'] ?? '' );
	}


	// Unflagged JSON follows the stored shape (null = no row; '' has none): plugins differ on JSON text vs array.
	private function parse_meta_value( $raw, $flags, $key, $stored ) {
		$raw    = (string) $raw;
		// Post, term and user meta writes all parse here: a value carrying a read's mask is never stored (#1624).
		if ( self::holds_secret_mask( $raw ) ) {
			return array( 'error' => self::secret_mask_meta_refusal( $key ) );
		}
		$format = isset( $flags['format'] ) ? strtolower( (string) $flags['format'] ) : '';
		if ( '' !== $format && ! in_array( $format, array( 'json', 'plaintext' ), true ) ) {
			return array( 'error' => __( 'Unknown --format. Use --format=json for structured values or --format=plaintext for literal strings.', 'vibe-ai' ) );
		}
		if ( 'json' === $format ) {
			$decoded = json_decode( $raw, true );
			if ( null === $decoded && 'null' !== trim( $raw ) ) {
				return array( 'error' => __( 'Value is not valid JSON. Fix the JSON, or drop --format=json to store the text as a plain string.', 'vibe-ai' ) );
			}
			return array( 'value' => $decoded );
		}
		if ( is_serialized( trim( $raw ) ) ) {
			/* translators: %s: meta key */
			return array( 'error' => sprintf( __( 'Value for \'%s\' looks like a PHP-serialized string. WordPress serializes values automatically, so writing this would store the serialized text inside another serialized string. Write the structured value with --format=json instead.', 'vibe-ai' ), $key ) );
		}
		$json = json_decode( $raw, true );
		if ( 'plaintext' === $format || ! is_array( $json ) ) {
			return array( 'value' => $raw );
		}
		if ( null === $stored || '' === $stored || is_array( $stored ) || is_object( $stored ) ) {
			return array(
				'value'  => $json,
				/* translators: %s: meta key */
				'notice' => sprintf( __( 'Stored \'%s\' as an array (decoded JSON). To store the JSON text instead, re-run with --format=plaintext.', 'vibe-ai' ), $key ),
			);
		}
		return array(
			'value'  => $raw,
			/* translators: %s: meta key */
			'notice' => sprintf( __( 'Stored \'%s\' as JSON text, because its current value is text. To store an array instead, re-run with --format=json.', 'vibe-ai' ), $key ),
		);
	}

	private function handle_post_meta_add( $positional, $flags ) {
		if ( count( $positional ) < 3 ) {
			return $this->error_result( __( 'Usage: post meta add <post_id> <key> <value>', 'vibe-ai' ) );
		}
		$extra = $this->too_many_positionals( $positional, 3 );
		if ( $extra ) {
			return $extra;
		}

		$post_id = (int) $positional[0];
		$post    = get_post( $post_id );
		if ( ! $post ) {
			/* translators: %s: post ID */
			return $this->error_result( sprintf( __( 'Post %s not found.', 'vibe-ai' ), $positional[0] ) );
		}
		if ( WPVibe_Code_Snippet::is_code_post( $post ) ) {
			return $this->wpcode_write_refusal();
		}

		// The key as core stores it: add_metadata() unslashes it (#1617).
		$key     = wp_unslash( (string) $positional[1] );
		$allowed = $this->meta_write_permission( 'post', 'add', $post_id, $key, ! empty( $flags['force'] ) );
		if ( true !== $allowed ) {
			return $allowed;
		}

		$cap_check = $this->check_post_caps( $post->post_type, 'update', $post_id );
		if ( is_wp_error( $cap_check ) ) {
			return $cap_check;
		}

		$stored = metadata_exists( 'post', $post_id, $key ) ? get_post_meta( $post_id, $key, true ) : null;
		$parsed = $this->parse_meta_value( $positional[2], $flags, $key, $stored );
		if ( isset( $parsed['error'] ) ) {
			return $this->error_result( $parsed['error'] );
		}
		$value = $parsed['value'];

		add_post_meta( $post_id, wp_slash( $key ), wp_slash( $value ) );

		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Post meta added: #{$post_id} → {$key}",
			'action_label' => 'Refresh',
		) );

		/* translators: 1: meta key, 2: post ID */
		return $this->success_result( array( 'message' => sprintf( __( 'Added meta \'%1$s\' on post #%2$d.', 'vibe-ai' ), $key, $post_id ) ), $parsed['notice'] ?? '' );
	}


	/**
	 * --meta_input as upstream takes it: a JSON object of key => value (#1219), checked key by key before anything is
	 * written, with the same rails as `post meta update`. A protected key refuses the whole command with that command's
	 * own refusal (the fleet stop-loss groups on its first sentence), and a PHP-serialized string is refused as there.
	 *
	 * @return array Empty without the flag; else 'meta' (the keys and values) or 'refusal' (a result to return).
	 */
	private function parse_meta_input( $flags ) {
		if ( ! isset( $flags['meta_input'] ) ) {
			return array();
		}
		$meta = json_decode( (string) $flags['meta_input'], true );
		if ( ! is_array( $meta ) || array() === $meta || array_keys( $meta ) === range( 0, count( $meta ) - 1 ) ) {
			return array( 'refusal' => $this->error_result( __( '--meta_input takes a JSON object of meta keys and values, for example --meta_input=\'{"price":"19"}\'.', 'vibe-ai' ) ) );
		}
		foreach ( $meta as $key => $value ) {
			$key = (string) $key;
			if ( '' === $key ) {
				return array( 'refusal' => $this->error_result( __( '--meta_input has an empty meta key.', 'vibe-ai' ) ) );
			}
			// Checked as core stores it: update_metadata() unslashes the key, so `\_secret` lands as `_secret`.
			if ( is_protected_meta( wp_unslash( $key ), 'post' ) ) {
				$refusal           = $this->protected_meta_refusal( wp_unslash( $key ) );
				$refusal['stderr'] .= ' ' . __( 'Nothing was written: --meta_input takes no protected keys, so handle this key on its own with `post meta update` as the note above says, and pass the other keys in --meta_input.', 'vibe-ai' );
				return array( 'refusal' => $refusal );
			}
			if ( self::holds_secret_mask( $value ) ) {
				return array( 'refusal' => $this->error_result( self::secret_mask_meta_refusal( $key ) ) );
			}
			if ( is_string( $value ) && is_serialized( trim( $value ) ) ) {
				/* translators: %s: meta key */
				return array( 'refusal' => $this->error_result( sprintf( __( 'Value for \'%s\' looks like a PHP-serialized string. WordPress serializes values automatically, so writing this would store the serialized text inside another serialized string. Pass the structured value as JSON inside --meta_input instead.', 'vibe-ai' ), $key ) ) );
			}
		}
		return array( 'meta' => $meta );
	}

	/**
	 * --meta_input writes through wp_insert_post(), which checks no meta capability, so each key gets the check
	 * `post meta update` makes (#1624). parse_meta_input() already refused protected keys. On an existing post that is
	 * edit_post_meta for the key; a new post has no ID to ask about yet, so a key whose plugin registered its own auth
	 * callback is refused there and written with `post meta update` once the post exists, where that callback runs.
	 *
	 * @return true|array|WP_Error
	 */
	private function meta_input_permission( $post_id, $post_type, array $meta ) {
		foreach ( array_keys( $meta ) as $key ) {
			$key = wp_unslash( (string) $key );
			if ( $post_id ) {
				$allowed = $this->meta_write_permission( 'post', 'edit', $post_id, $key, false );
				if ( true !== $allowed ) {
					return $allowed;
				}
				continue;
			}
			if ( WPVibe_Content_Ops::hook_has_owner_auth( "auth_post_meta_{$key}" ) || WPVibe_Content_Ops::hook_has_owner_auth( "auth_post_meta_{$key}_for_{$post_type}" ) || WPVibe_Content_Ops::hook_has_owner_auth( "auth_post_{$post_type}_meta_{$key}" ) ) {
				return $this->error_result( sprintf(
					/* translators: %s: meta key */
					__( 'Nothing was written: the plugin that registered meta key \'%s\' checks who may edit it on each post, and a new post has no ID to check yet. Create the post without that key in --meta_input, then set it with `post meta update <id> <key> <value>`.', 'vibe-ai' ),
					$key
				) );
			}
		}
		return true;
	}

	/** Core's update_post_meta() writes a revision's meta to its parent, which the revision's checks never covered. */
	private function meta_input_revision_refusal() {
		return $this->error_result( __( '--meta_input can\'t write to a revision: WordPress stores a revision\'s meta on its parent post. Write it on the parent instead, with `post update <parent id> --meta_input=...`.', 'vibe-ai' ) );
	}

	/** The SEO plugin a protected post meta key belongs to, by its key prefix, or null (#864). */
	private static function seo_meta_owner( $key ) {
		$owners = array( 'rank_math_' => 'Rank Math', '_yoast_wpseo_' => 'Yoast SEO', '_seopress_' => 'SEOPress', '_aioseo_' => 'All in One SEO' );
		foreach ( $owners as $prefix => $owner ) {
			if ( 0 === strpos( (string) $key, $prefix ) ) {
				return $owner;
			}
		}
		return null;
	}

	/**
	 * The refusal for a protected meta key written without --force. Its first sentence stays as is: the Worker's
	 * fleet stop-loss groups refusals on it. On a post meta write, an SEO plugin's key also says whose field it is
	 * and how to write it, so the AI does not drop the user's SEO change (#864).
	 */
	private function protected_meta_refusal( $key, $seo_hint = true ) {
		/* translators: %s: meta key */
		$message = sprintf( __( 'Meta key \'%s\' is a protected/internal key. Use --force to override.', 'vibe-ai' ), $key );
		$owner   = $seo_hint ? self::seo_meta_owner( $key ) : null;
		if ( 'All in One SEO' === $owner ) {
			$message .= ' ' . __( 'It is an All in One SEO key, but All in One SEO 4 and later read SEO data from their own table, not this meta key, so writing it changes nothing on the page. Load the seo-edit skill for All in One SEO\'s write path instead of retrying with --force.', 'vibe-ai' );
		} elseif ( $owner ) {
			/* translators: %s: SEO plugin name */
			$message .= ' ' . sprintf( __( 'It is %s\'s SEO field, not WordPress internals: to change it as the user asked, resend the same command with --force.', 'vibe-ai' ), $owner );
			if ( 'Rank Math' === $owner ) {
				$message .= ' ' . __( 'That writes the meta directly, skipping Rank Math\'s sanitizer, so send plain text; an empty value stores an empty string instead of deleting the key.', 'vibe-ai' );
			}
		}
		return $this->error_result( $message );
	}

	/**
	 * Whether the plugin that registered this meta key gave it its own permission check (an auth_callback it supplied,
	 * or a hand-added auth filter), under any of the three names core's map_meta_cap() applies. Core's own default
	 * ('__return_false' / '__return_true' for a key registered without one, as Yoast SEO's are) does not count.
	 *
	 * @param string $type 'post', 'term' or 'user'.
	 */
	private static function meta_has_auth_filter( $type, $object_id, $key ) {
		if ( WPVibe_Content_Ops::hook_has_owner_auth( "auth_{$type}_meta_{$key}" ) ) {
			return true;
		}
		$subtype = self::meta_object_subtype( $type, $object_id );
		return '' !== $subtype && ( WPVibe_Content_Ops::hook_has_owner_auth( "auth_{$type}_meta_{$key}_for_{$subtype}" ) || WPVibe_Content_Ops::hook_has_owner_auth( "auth_{$type}_{$subtype}_meta_{$key}" ) );
	}

	/** The object subtype core keys meta registration on: the post type, the taxonomy, or 'user'. */
	private static function meta_object_subtype( $type, $object_id ) {
		if ( function_exists( 'get_object_subtype' ) ) {
			return (string) get_object_subtype( $type, (int) $object_id );
		}
		if ( 'post' === $type ) {
			return (string) get_post_type( (int) $object_id );
		}
		if ( 'term' === $type ) {
			$term = get_term( (int) $object_id );
			return is_object( $term ) && isset( $term->taxonomy ) ? (string) $term->taxonomy : '';
		}
		return 'user';
	}

	/** Builder layout keys: the builder filters their HTML on its own save, so a raw write needs unfiltered_html. */
	private static function is_builder_layout_meta( $key ) {
		return (bool) preg_match( '/^(?:_elementor_data|_elementor_page_settings|_bricks_page_(?:content|header|footer)(?:_\d+)?|_fl_builder_(?:data|draft)(?:_settings)?|_breakdance_data|_oxygen_data)$/', (string) $key );
	}

	/**
	 * Reads of post, term and user meta check the object and the key, as WordPress decides who sees them (#1624):
	 * - the object: read_post on the post; edit_term on the term and edit_user on the user, since WordPress shows their
	 *   meta only on those edit screens;
	 * - a protected key: where its plugin registered an auth callback, that callback's answer (the same meta capability
	 *   writes check); otherwise edit rights on the object, the edit screen where plugins show their own fields. Never
	 *   stricter than wp-admin, and manage_options never stands in for the object.
	 *
	 * @param string      $type 'post', 'term' or 'user'.
	 * @param string|null $key  Null checks the object only (a list, which then filters keys with meta_key_readable()).
	 * @return true|WP_Error
	 */
	private function meta_read_permission( $type, $object_id, $key = null ) {
		$object_cap = 'post' === $type ? 'read_post' : "edit_{$type}";
		if ( ! current_user_can( $object_cap, (int) $object_id ) ) {
			return $this->meta_read_error( $type, $object_id, $object_cap, '' );
		}
		if ( null !== $key && ! $this->meta_key_readable( $type, $object_id, (string) $key ) ) {
			return $this->meta_read_error( $type, $object_id, self::meta_has_auth_filter( $type, $object_id, (string) $key ) ? "edit_{$type}_meta" : "edit_{$type}", (string) $key );
		}
		return true;
	}

	/** The key half of meta_read_permission(), for an object the account may already read. */
	private function meta_key_readable( $type, $object_id, $key ) {
		if ( ! is_protected_meta( $key, $type ) ) {
			return true;
		}
		return self::meta_has_auth_filter( $type, $object_id, $key )
			? current_user_can( "edit_{$type}_meta", (int) $object_id, $key )
			: current_user_can( "edit_{$type}", (int) $object_id );
	}

	private function meta_read_error( $type, $object_id, $capability, $key ) {
		$message = '' === $key
			/* translators: 1: WordPress capability, 2: object type (post, term or user), 3: object ID */
			? sprintf( __( 'You do not have the required capability (%1$s) to read the meta of %2$s %3$d.', 'vibe-ai' ), $capability, $type, (int) $object_id )
			/* translators: 1: WordPress capability, 2: meta key, 3: object type (post, term or user), 4: object ID */
			: sprintf( __( 'You do not have the required capability (%1$s) to read meta \'%2$s\' on %3$s %4$d: it is a protected key, readable by accounts that can edit this %3$s, or where its plugin\'s own permission check allows it.', 'vibe-ai' ), $capability, $key, $type, (int) $object_id );
		return new WP_Error( 'insufficient_cap', $message, WPVibe_Error_Contract::data( 'capability_cpt_mapping', false, array( 'status' => 403, 'capability' => $capability ) ) );
	}

	/** Note for a meta list that left out keys this account may not read. */
	private static function meta_hidden_note( $hidden ) {
		/* translators: %d: number of meta keys */
		return $hidden ? sprintf( _n( '%d protected key was left out: this account may not read it.', '%d protected keys were left out: this account may not read them.', $hidden, 'vibe-ai' ), $hidden ) : '';
	}

	/**
	 * The one permission rule for `post meta`, `term meta` and `user meta` writes and post --meta_input (WordPress.org
	 * review 11-17, #1624). The key is the one core will store (already unslashed).
	 * - Without --force: WordPress's own meta capability for this object and key (edit_post_meta, add_post_meta,
	 *   delete_post_meta and the term and user equivalents), as core's REST meta writes check it. A protected key needs
	 *   --force on top.
	 * - With --force on a protected key: when the owning plugin registered an auth callback, its answer stands and
	 *   --force does not pass it; otherwise manage_options plus edit rights on this very object. manage_options never
	 *   stands in for the object's own capability.
	 * - A builder layout key written raw needs unfiltered_html, forced or not: the builder's own save filters its HTML.
	 *
	 * @param string $type   'post', 'term' or 'user'.
	 * @param string $action 'edit', 'add' or 'delete'.
	 * @return true|array|WP_Error True, or the refusal to return.
	 */
	private function meta_write_permission( $type, $action, $object_id, $key, $force ) {
		$object_id = (int) $object_id;
		$key       = (string) $key;
		$cap       = "{$action}_{$type}_meta";
		$protected = is_protected_meta( $key, $type );
		$owned     = self::meta_has_auth_filter( $type, $object_id, $key );
		$denied    = function ( $capability ) use ( $type, $object_id, $key ) {
			return new WP_Error( 'insufficient_cap', sprintf(
				/* translators: 1: WordPress capability, 2: meta key, 3: object type (post, term or user), 4: object ID */
				__( 'You do not have the required capability (%1$s) to change meta \'%2$s\' on %3$s %4$d.', 'vibe-ai' ),
				$capability, $key, $type, $object_id
			), WPVibe_Error_Contract::data( 'capability_cpt_mapping', false, array( 'status' => 403, 'capability' => $capability ) ) );
		};

		if ( 'post' === $type && self::is_builder_layout_meta( $key ) && ! current_user_can( 'unfiltered_html' ) ) {
			return $this->error_result( sprintf(
				/* translators: %s: meta key */
				__( 'Refused: \'%s\' is a page builder\'s layout, and the connected account lacks the unfiltered_html capability, so the builder filters the HTML it may save. A raw meta write would skip that filter. Save the layout through the builder\'s own route instead (elementor/save-page, bricks/save-page, beaver/save-page or breakdance/save-page).', 'vibe-ai' ),
				$key
			) );
		}

		// Post meta: WordPress's rule, with ACF's exception for its administrators, as content/edit (#1751).
		$can_meta = function () use ( $type, $cap, $object_id, $key ) {
			return 'post' === $type ? WPVibe_Content_Ops::can_post_meta( $cap, $object_id, $key ) : current_user_can( $cap, $object_id, $key );
		};

		if ( $protected && $owned && ! $can_meta() ) {
			return $this->error_result( sprintf(
				/* translators: %s: meta key */
				__( 'Refused: the plugin that registered meta key \'%s\' refused this edit for the connected account (its auth callback said no). That answer stands for every role, and --force does not pass it. Write the value through that plugin\'s own path (an ability via discover_abilities, or its REST namespace).', 'vibe-ai' ),
				$key
			) );
		}
		if ( $protected && ! $owned && ! current_user_can( 'manage_options' ) ) {
			return $this->error_result( sprintf(
				/* translators: %s: meta key */
				__( 'Refused: meta key \'%s\' is protected (internal), and only an Administrator connection can write a protected key that its plugin registered no permission check for (with --force). Write it through the plugin that owns it (an ability via discover_abilities, or its REST namespace).', 'vibe-ai' ),
				$key
			) );
		}
		if ( $protected && ! $force ) {
			return $this->protected_meta_refusal( $key, 'post' === $type && 'delete' !== $action );
		}
		if ( $protected && ! $owned ) {
			// --force by an Administrator: the object's own edit capability still decides.
			$object_cap = "edit_{$type}";
			return current_user_can( $object_cap, $object_id ) ? true : $denied( $object_cap );
		}
		if ( $can_meta() ) {
			return true;
		}
		if ( 'post' === $type && WPVibe_Content_Ops::acf_guards_meta_key( $object_id, $key ) && current_user_can( 'edit_post', $object_id ) ) {
			return $this->error_result( sprintf(
				/* translators: %s: meta key */
				__( 'Refused: \'%s\' holds an Advanced Custom Fields field.', 'vibe-ai' ),
				$key
			) . ' ' . WPVibe_Content_Ops::acf_refusal_note() );
		}
		return $denied( $cap );
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
		if ( WPVibe_Code_Snippet::is_code_post( $post ) ) {
			return $this->wpcode_write_refusal();
		}

		// The key as core matches it: delete_metadata() unslashes it (#1617).
		$key     = wp_unslash( (string) $positional[1] );
		$allowed = $this->meta_write_permission( 'post', 'delete', $post_id, $key, ! empty( $flags['force'] ) );
		if ( true !== $allowed ) {
			return $allowed;
		}

		$cap_check = $this->check_post_caps( $post->post_type, 'update', $post_id );
		if ( is_wp_error( $cap_check ) ) {
			return $cap_check;
		}

		// Optional third positional = meta value: delete only the matching row of a multi-value meta.
		$value = isset( $positional[2] ) ? $positional[2] : '';
		delete_post_meta( $post_id, wp_slash( $key ), $value );

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
		if ( WPVibe_Code_Snippet::is_code_post( $post ) ) {
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
