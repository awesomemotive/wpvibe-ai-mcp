<?php
/**
 * WP-CLI emulator: user reads/deletes, menus, rewrite, cron, maintenance, leftovers.
 *
 * Extracted from class-wpvibe-cli.php (mechanical split; no behavior change).
 */

defined( 'ABSPATH' ) || exit;

trait WPVibe_CLI_Misc {


	private function handle_user_list( $positional, $flags ) {
		$reject = $this->reject_unknown_flags( 'user list', $flags, array( 'role', 'number', 'fields', 'format' ), array(
			'search'  => __( 'Filter the returned rows yourself, or use rest_api GET /wp/v2/users?search=<term>.', 'vibe-ai' ),
			'include' => __( 'Fetch the ids individually with `user get`, or use rest_api GET /wp/v2/users?include=<ids>.', 'vibe-ai' ),
			'exclude' => __( 'Filter the returned rows yourself, or use rest_api GET /wp/v2/users?exclude=<ids>.', 'vibe-ai' ),
			'orderby' => __( 'Rows come back ordered by ID; sort them yourself, or use rest_api GET /wp/v2/users?orderby=<field>.', 'vibe-ai' ),
			'field'   => __( 'Use --fields=<name> and read that column from the rows.', 'vibe-ai' ),
			'network' => __( 'Multisite network scoping is not emulated; this lists users of the current site only.', 'vibe-ai' ),
		) );
		if ( $reject ) {
			return $reject;
		}
		$format_reject = $this->reject_unsupported_format( 'user list', $flags );
		if ( $format_reject ) {
			return $format_reject;
		}
		// Default caps the row count; say so, because a silently truncated list
		// feeding a bulk operation targets the wrong set.
		$args = array( 'number' => 100 );
		if ( isset( $flags['role'] ) )   $args['role']   = $flags['role'];
		if ( isset( $flags['number'] ) ) $args['number'] = min( (int) $flags['number'], 1000 );
		$users   = get_users( $args );
		$results = array();
		foreach ( $users as $user ) {
			$results[] = array(
				'ID'              => $user->ID,
				'user_login'      => $user->user_login,
				'display_name'    => $user->display_name,
				'user_email'      => $user->user_email,
				'roles'           => implode( ',', $user->roles ),
				'user_registered' => $user->user_registered,
			);
		}
		return $this->success_result(
			$this->format_rows( $results, $flags, 'ID' ),
			$this->truncation_notice( $results, (int) $args['number'], 'user list' )
		);
	}


	private function handle_menu_list( $positional, $flags ) {
		$menus   = wp_get_nav_menus();
		$results = array();
		foreach ( $menus as $menu ) {
			$results[] = array(
				'term_id' => $menu->term_id,
				'name'    => $menu->name,
				'slug'    => $menu->slug,
				'count'   => $menu->count,
			);
		}
		return $this->success_result( $results );
	}


	private function handle_menu_create( $positional, $flags ) {
		$reject = $this->reject_unknown_flags( 'menu create', $flags, array( 'porcelain' ) );
		if ( $reject ) {
			return $reject;
		}
		$name = isset( $positional[0] ) ? trim( (string) $positional[0] ) : '';
		if ( '' === $name ) {
			return $this->error_result( __( 'Usage: menu create <menu-name> [--porcelain]', 'vibe-ai' ) );
		}
		$menu_id = wp_create_nav_menu( $name );
		if ( is_wp_error( $menu_id ) ) {
			return $this->error_result( $menu_id->get_error_message() );
		}
		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Menu created: {$name}",
			'action_label' => 'Refresh',
		) );
		if ( ! empty( $flags['porcelain'] ) ) {
			return $this->success_result( (int) $menu_id );
		}
		return $this->success_result( array(
			/* translators: 1: menu name, 2: menu ID */
			'message' => sprintf( __( 'Created menu "%1$s" (ID %2$d).', 'vibe-ai' ), $name, (int) $menu_id ),
			'menu_id' => (int) $menu_id,
		) );
	}


	/**
	 * Shared handler for `menu item add-custom|add-post|add-term`. Mirrors
	 * WP-CLI's Menu_Item_Command: builds the menu-item-* arg array and calls
	 * wp_update_nav_menu_item(), which applies esc_url_raw to the URL. The
	 * title is NOT sanitized downstream (kses is skipped for unfiltered_html
	 * admins, and Walker_Nav_Menu renders titles unescaped), so every title
	 * path here must sanitize_text_field before it reaches core.
	 */
	private function handle_menu_item_add( $positional, $flags ) {
		$sub    = $this->current_command;
		$known  = array( 'description', 'target', 'classes', 'parent_id', 'position', 'porcelain' );
		if ( 'menu item add-custom' === $sub ) {
			$reject = $this->reject_unknown_flags( $sub, $flags, $known, array(
				'title' => __( 'For add-custom the title is the second positional argument: menu item add-custom <menu> <title> <link>.', 'vibe-ai' ),
				'link'  => __( 'The link is the third positional argument: menu item add-custom <menu> <title> <link>.', 'vibe-ai' ),
			) );
		} else {
			$reject = $this->reject_unknown_flags( $sub, $flags, array_merge( $known, array( 'title' ) ) );
		}
		if ( $reject ) {
			return $reject;
		}
		$menu = isset( $positional[0] ) ? (string) $positional[0] : '';
		if ( '' === trim( $menu ) ) {
			return $this->error_result( __( 'Usage: menu item add-custom <menu> <title> <link> | add-post <menu> <post-id> | add-term <menu> <taxonomy> <term-id>', 'vibe-ai' ) );
		}
		$menu_obj = wp_get_nav_menu_object( $menu );
		if ( ! $menu_obj ) {
			/* translators: %s: menu name/slug/ID */
			return $this->error_result( sprintf( __( 'Menu "%s" not found. Run `menu list` to see menus, or create it first with `menu create`.', 'vibe-ai' ), $menu ) );
		}

		$item = array( 'menu-item-status' => 'publish' );
		if ( isset( $flags['description'] ) ) {
			$item['menu-item-description'] = sanitize_text_field( (string) $flags['description'] );
		}
		if ( isset( $flags['target'] ) ) {
			$item['menu-item-target'] = '_blank' === $flags['target'] ? '_blank' : '';
		}
		if ( isset( $flags['classes'] ) ) {
			$item['menu-item-classes'] = implode( ' ', array_map( 'sanitize_html_class', preg_split( '/\s+/', (string) $flags['classes'] ) ) );
		}
		if ( isset( $flags['parent_id'] ) ) {
			$item['menu-item-parent-id'] = (int) $flags['parent_id'];
		}
		if ( isset( $flags['position'] ) ) {
			$item['menu-item-position'] = (int) $flags['position'];
		}

		if ( 'menu item add-custom' === $sub ) {
			$title = isset( $positional[1] ) ? (string) $positional[1] : '';
			$link  = isset( $positional[2] ) ? (string) $positional[2] : '';
			if ( '' === trim( $title ) || '' === trim( $link ) ) {
				return $this->error_result( __( 'Usage: menu item add-custom <menu> <title> <link>', 'vibe-ai' ) );
			}
			$item['menu-item-type']  = 'custom';
			$item['menu-item-title'] = sanitize_text_field( $title );
			$item['menu-item-url']   = $link; // wp_update_nav_menu_item runs esc_url_raw on this.
		} elseif ( 'menu item add-post' === $sub ) {
			$post_id = isset( $positional[1] ) ? (int) $positional[1] : 0;
			$post    = $post_id ? get_post( $post_id ) : null;
			if ( ! $post ) {
				/* translators: %s: post ID */
				return $this->error_result( sprintf( __( 'Usage: menu item add-post <menu> <post-id> (post %s not found).', 'vibe-ai' ), $positional[1] ?? '' ) );
			}
			$item['menu-item-type']      = 'post_type';
			$item['menu-item-object']    = $post->post_type;
			$item['menu-item-object-id'] = $post_id;
			if ( isset( $flags['title'] ) ) {
				$item['menu-item-title'] = sanitize_text_field( (string) $flags['title'] );
			}
		} else { // menu item add-term
			$taxonomy = isset( $positional[1] ) ? (string) $positional[1] : '';
			$term_id  = isset( $positional[2] ) ? (int) $positional[2] : 0;
			$tax      = $taxonomy ? get_taxonomy( $taxonomy ) : false;
			if ( ! $tax ) {
				/* translators: %s: taxonomy slug */
				return $this->error_result( sprintf( __( 'Taxonomy "%s" does not exist. Run `taxonomy list`.', 'vibe-ai' ), $taxonomy ) );
			}
			if ( $term_id <= 0 || ! get_term_by( 'id', $term_id, $taxonomy ) ) {
				/* translators: 1: term ID, 2: taxonomy slug */
				return $this->error_result( sprintf( __( 'Term %1$s not found in taxonomy "%2$s". Run `term list %2$s`.', 'vibe-ai' ), $positional[2] ?? '', $taxonomy ) );
			}
			$item['menu-item-type']      = 'taxonomy';
			$item['menu-item-object']    = $taxonomy;
			$item['menu-item-object-id'] = $term_id;
			if ( isset( $flags['title'] ) ) {
				$item['menu-item-title'] = sanitize_text_field( (string) $flags['title'] );
			}
		}

		$item_id = wp_update_nav_menu_item( (int) $menu_obj->term_id, 0, $item );
		if ( is_wp_error( $item_id ) ) {
			return $this->error_result( $item_id->get_error_message() );
		}
		// Upstream recalculates sibling positions on insertion at a taken slot
		// (menu-item.feature); without this two items share a menu_order.
		if ( isset( $flags['position'] ) && (int) $flags['position'] > 0 ) {
			$this->place_menu_item( (int) $menu_obj->term_id, (int) $item_id, (int) $flags['position'] );
		}
		// wp-admin fires this on every menu save and cache engines purge on it;
		// wp_update_nav_menu_item alone never does (real WP-CLI shares that
		// staleness bug — deliberate divergence).
		do_action( 'wp_update_nav_menu', (int) $menu_obj->term_id );
		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Menu item added to {$menu_obj->name}",
			'action_label' => 'Refresh',
		) );
		if ( ! empty( $flags['porcelain'] ) ) {
			return $this->success_result( (int) $item_id );
		}
		return $this->success_result( array(
			/* translators: 1: menu name, 2: menu item ID */
			'message'      => sprintf( __( 'Added item to menu "%1$s" (item ID %2$d).', 'vibe-ai' ), $menu_obj->name, (int) $item_id ),
			'menu_item_id' => (int) $item_id,
		) );
	}


	private function handle_menu_location_assign( $positional, $flags ) {
		$reject = $this->reject_unknown_flags( 'menu location assign', $flags, array() );
		if ( $reject ) {
			return $reject;
		}
		$menu     = isset( $positional[0] ) ? (string) $positional[0] : '';
		$location = isset( $positional[1] ) ? (string) $positional[1] : '';
		if ( '' === trim( $menu ) || '' === trim( $location ) ) {
			return $this->error_result( __( 'Usage: menu location assign <menu> <location>', 'vibe-ai' ) );
		}
		$menu_obj = wp_get_nav_menu_object( $menu );
		if ( ! $menu_obj ) {
			/* translators: %s: menu name/slug/ID */
			return $this->error_result( sprintf( __( 'Menu "%s" not found. Run `menu list`.', 'vibe-ai' ), $menu ) );
		}
		$registered = get_registered_nav_menus();
		if ( ! isset( $registered[ $location ] ) ) {
			$known = ! empty( $registered ) ? implode( ', ', array_keys( $registered ) ) : __( '(the active theme registers none)', 'vibe-ai' );
			/* translators: 1: location slug, 2: registered location slugs */
			return $this->error_result( sprintf( __( 'Location "%1$s" is not registered by the active theme. Registered locations: %2$s. Run `menu location list`.', 'vibe-ai' ), $location, $known ) );
		}
		$locations = get_nav_menu_locations();
		if ( ! is_array( $locations ) ) {
			$locations = array();
		}
		$locations[ $location ] = (int) $menu_obj->term_id;
		set_theme_mod( 'nav_menu_locations', $locations );
		do_action( 'wp_update_nav_menu', (int) $menu_obj->term_id );
		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Menu '{$menu_obj->name}' assigned to '{$location}'",
			'action_label' => 'Refresh',
		) );
		return $this->success_result( array(
			/* translators: 1: menu name, 2: location slug */
			'message' => sprintf( __( 'Assigned menu "%1$s" to theme location "%2$s".', 'vibe-ai' ), $menu_obj->name, $location ),
		) );
	}


	private function handle_menu_item_update( $positional, $flags ) {
		$reject = $this->reject_unknown_flags( 'menu item update', $flags, array( 'title', 'link', 'description', 'target', 'classes', 'parent_id', 'position' ), array(
			'attr_title' => __( 'The title attribute is outside the emulated subset; edit it in wp-admin (Appearance > Menus).', 'vibe-ai' ),
			'xfn'        => __( 'XFN relationships are outside the emulated subset; edit them in wp-admin (Appearance > Menus).', 'vibe-ai' ),
		) );
		if ( $reject ) {
			return $reject;
		}
		$id = isset( $positional[0] ) ? (int) $positional[0] : 0;
		if ( $id <= 0 ) {
			return $this->error_result( __( 'Usage: menu item update <db-id> [--title=<title>] [--link=<url>] [--description=<text>] [--target=<_blank>] [--classes=<classes>] [--parent-id=<id>] [--position=<n>]', 'vibe-ai' ) );
		}
		$post = get_post( $id );
		if ( ! $post || 'nav_menu_item' !== $post->post_type ) {
			// Upstream Menu_Item_Command::get error text and exit code.
			return $this->error_result( __( 'Invalid menu item.', 'vibe-ai' ) );
		}
		$terms    = get_the_terms( $id, 'nav_menu' );
		$menu_obj = ( is_array( $terms ) && ! empty( $terms ) && ! is_wp_error( $terms ) ) ? wp_get_nav_menu_object( (int) $terms[0]->term_id ) : false;
		if ( ! $menu_obj ) {
			// Orphaned item: passing menu 0 into core "succeeds" while assigning
			// the item to no menu — a wrong-success. Upstream errors here too.
			return $this->error_result( __( 'Invalid menu.', 'vibe-ai' ) );
		}
		// _menu_item_type comes from meta, NOT wp_setup_nav_menu_item — missing
		// it rewrites a post/term item as `custom`, breaking the link silently.
		$type  = (string) get_post_meta( $id, '_menu_item_type', true );
		$setup = wp_setup_nav_menu_item( $post );
		// Core blanks every menu-item-* field not passed (the #28138 quirk), so
		// current values are the defaults and flags override. Position 0 makes
		// core append (#28140), so the current order maps to at least 1.
		$current_position = ( 0 === (int) $setup->menu_order ) ? 1 : (int) $setup->menu_order;
		$item = array(
			'menu-item-status'      => $setup->post_status ? $setup->post_status : 'publish',
			'menu-item-type'        => '' !== $type ? $type : (string) $setup->type,
			'menu-item-object'      => (string) $setup->object,
			'menu-item-object-id'   => (int) $setup->object_id,
			'menu-item-parent-id'   => (int) $setup->menu_item_parent,
			'menu-item-position'    => $current_position,
			'menu-item-title'       => (string) $setup->title,
			'menu-item-url'         => (string) $setup->url,
			'menu-item-description' => (string) $setup->description,
			'menu-item-attr-title'  => (string) $setup->attr_title,
			'menu-item-target'      => (string) $setup->target,
			'menu-item-classes'     => implode( ' ', (array) $setup->classes ),
			'menu-item-xfn'         => (string) $setup->xfn,
		);
		$prior = array( 'title' => $item['menu-item-title'], 'url' => $item['menu-item-url'] );
		if ( isset( $flags['title'] ) ) {
			$item['menu-item-title'] = sanitize_text_field( (string) $flags['title'] );
		}
		if ( isset( $flags['link'] ) ) {
			$item['menu-item-url'] = (string) $flags['link']; // wp_update_nav_menu_item runs esc_url_raw on this.
		}
		if ( isset( $flags['description'] ) ) {
			$item['menu-item-description'] = sanitize_text_field( (string) $flags['description'] );
		}
		if ( isset( $flags['target'] ) ) {
			$item['menu-item-target'] = '_blank' === $flags['target'] ? '_blank' : '';
		}
		if ( isset( $flags['classes'] ) ) {
			$item['menu-item-classes'] = implode( ' ', array_map( 'sanitize_html_class', preg_split( '/\s+/', (string) $flags['classes'] ) ) );
		}
		if ( isset( $flags['parent_id'] ) ) {
			$item['menu-item-parent-id'] = (int) $flags['parent_id'];
		}
		$result = wp_update_nav_menu_item( (int) $menu_obj->term_id, $id, $item );
		if ( is_wp_error( $result ) ) {
			return $this->error_result( $result->get_error_message() );
		}
		if ( isset( $flags['position'] ) && (int) $flags['position'] > 0 ) {
			$this->place_menu_item( (int) $menu_obj->term_id, $id, (int) $flags['position'] );
		}
		do_action( 'wp_update_nav_menu', (int) $menu_obj->term_id );
		$url_changed = $item['menu-item-url'] !== $prior['url'];
		WPVibe_Change_Tracker::mark( array(
			// A repointed link is invisible in the nav (same title, new
			// destination) — the mark is where the old->new URL stays visible.
			'summary'      => $url_changed
				? "Menu item link changed: {$prior['url']} -> {$item['menu-item-url']}"
				: "Menu item updated in {$menu_obj->name}",
			'action_label' => 'Refresh',
		) );
		return $this->success_result( array(
			'message' => __( 'Menu item updated.', 'vibe-ai' ),
			'prior'   => $prior,
		) );
	}


	private function handle_menu_item_delete( $positional, $flags ) {
		$reject = $this->reject_unknown_flags( 'menu item delete', $flags, array() );
		if ( $reject ) {
			return $reject;
		}
		if ( empty( $positional ) ) {
			return $this->error_result( __( 'Usage: menu item delete <db-id> [<db-id>...]', 'vibe-ai' ) );
		}
		$count         = 0;
		$errors        = 0;
		$warnings      = array();
		$deleted_names = array();
		$menus_touched = array();
		foreach ( $positional as $arg ) {
			$id   = (int) $arg;
			$post = $id > 0 ? get_post( $id ) : null;
			// Title read pre-delete (the row is gone afterwards), recorded only on success.
			$title = ( $post && 'nav_menu_item' === $post->post_type )
				? (string) ( wp_setup_nav_menu_item( $post )->title ?? '' ) . ' (' . $id . ')'
				: '';
			// Upstream force-deletes whatever post the ID names. This type check
			// is the security boundary: without it, `menu item delete <page-id>`
			// is an ungated permanent delete of any post, bypassing the
			// `post delete --force` approval gate.
			if ( ! $post || 'nav_menu_item' !== $post->post_type ) {
				/* translators: %s: menu item ID */
				$warnings[] = sprintf( __( 'Couldn\'t delete menu item %s: not a menu item.', 'vibe-ai' ), $arg );
				$errors++;
				continue;
			}
			$terms        = get_the_terms( $id, 'nav_menu' );
			$menu_term_id = ( is_array( $terms ) && ! empty( $terms ) && ! is_wp_error( $terms ) ) ? (int) $terms[0]->term_id : 0;
			$parent_id    = (int) get_post_meta( $id, '_menu_item_menu_item_parent', true );
			$result       = wp_delete_post( $id, true );
			if ( ! $result ) {
				/* translators: %s: menu item ID */
				$warnings[] = sprintf( __( 'Couldn\'t delete menu item %s.', 'vibe-ai' ), $arg );
				$errors++;
				continue;
			}
			$count++;
			$deleted_names[] = $title;
			// Reparent children to the grandparent (upstream parity: the
			// subtree survives the middle item's deletion).
			$children = get_posts( array(
				'post_type'   => 'nav_menu_item',
				'numberposts' => -1,
				'post_status' => 'any',
				'fields'      => 'ids',
				'meta_key'    => '_menu_item_menu_item_parent',
				'meta_value'  => (string) $id,
			) );
			foreach ( (array) $children as $child_id ) {
				update_post_meta( (int) $child_id, '_menu_item_menu_item_parent', $parent_id );
				clean_post_cache( (int) $child_id );
			}
			if ( $menu_term_id ) {
				$menus_touched[ $menu_term_id ] = true;
			}
		}
		foreach ( array_keys( $menus_touched ) as $mid ) {
			$this->renumber_menu_items( $this->menu_sibling_ids( $mid ) );
			do_action( 'wp_update_nav_menu', $mid );
		}
		if ( $count > 0 ) {
			WPVibe_Change_Tracker::mark( array(
				'summary'      => "Menu items deleted: {$count}",
				'action_label' => 'Refresh',
			) );
		}
		$notice = implode( "\n", $warnings );
		if ( $errors > 0 ) {
			// Force-deletes are permanent; the failing batch still names what it destroyed.
			$destroyed = $deleted_names
				/* translators: %s: list of deleted menu items */
				? "\n" . sprintf( __( 'Deleted before the failure: %s.', 'vibe-ai' ), implode( ', ', $deleted_names ) )
				: '';
			/* translators: 1: success count, 2: total */
			return $this->error_result( trim( sprintf( __( 'Only deleted %1$d of %2$d menu items.', 'vibe-ai' ), $count, count( $positional ) ) . $destroyed . "\n" . $notice ) );
		}
		return $this->success_result( array(
			/* translators: 1: success count, 2: total */
			'message' => sprintf( __( 'Deleted %1$d of %2$d menu items.', 'vibe-ai' ), $count, count( $positional ) ),
		), $notice );
	}


	/**
	 * IDs of a menu's items in menu_order. Filters the nav_menu tax by term_id —
	 * upstream filters term_taxonomy_id with a term_id value, which reorders the
	 * wrong menu on sites where the IDs diverge. Deliberate divergence.
	 */
	private function menu_sibling_ids( $menu_term_id ) {
		$ids = get_posts( array(
			'post_type'   => 'nav_menu_item',
			'numberposts' => -1,
			'orderby'     => 'menu_order',
			'order'       => 'ASC',
			'post_status' => 'any',
			'fields'      => 'ids',
			'tax_query'   => array(
				array(
					'taxonomy' => 'nav_menu',
					'field'    => 'term_id',
					'terms'    => (int) $menu_term_id,
				),
			),
		) );
		return array_map( 'intval', (array) $ids );
	}


	/** Splice an item into a menu at a 1-indexed position (clamped) and renumber. */
	private function place_menu_item( $menu_term_id, $item_id, $position ) {
		$ids      = array_values( array_diff( $this->menu_sibling_ids( $menu_term_id ), array( (int) $item_id ) ) );
		$position = max( 1, min( (int) $position, count( $ids ) + 1 ) );
		array_splice( $ids, $position - 1, 0, array( (int) $item_id ) );
		$this->renumber_menu_items( $ids );
	}


	/** Write contiguous 1..N menu_order following the given ID order, touching only changed rows. */
	private function renumber_menu_items( $ordered_ids ) {
		foreach ( array_values( (array) $ordered_ids ) as $idx => $id ) {
			$target = $idx + 1;
			$post   = get_post( $id );
			if ( $post && (int) $post->menu_order !== $target ) {
				wp_update_post( array( 'ID' => (int) $id, 'menu_order' => $target ) );
			}
		}
	}


	private function handle_widget_list( $positional, $flags ) {
		global $wp_registered_sidebars;
		// Not the raw option: core strips the legacy array_version key and
		// applies the sidebars_widgets filter (what the theme actually renders).
		$sidebars = wp_get_sidebars_widgets();
		$results  = array();
		foreach ( $sidebars as $sidebar_id => $widgets ) {
			if ( 'wp_inactive_widgets' === $sidebar_id ) continue;
			$name = isset( $wp_registered_sidebars[ $sidebar_id ] ) ? $wp_registered_sidebars[ $sidebar_id ]['name'] : $sidebar_id;
			$results[] = array(
				'sidebar_id' => $sidebar_id,
				'name'       => $name,
				'widgets'    => $widgets ?: array(),
			);
		}
		return $this->success_result( $results );
	}


	private function handle_sidebar_list( $positional, $flags ) {
		global $wp_registered_sidebars;
		$results = array();
		if ( $wp_registered_sidebars ) {
			foreach ( $wp_registered_sidebars as $id => $sidebar ) {
				$results[] = array(
					'id'          => $id,
					'name'        => $sidebar['name'],
					'description' => $sidebar['description'] ?? '',
				);
			}
		}
		return $this->success_result( $this->filter_fields( $results, $flags ) );
	}


	private function handle_rewrite_list( $positional, $flags ) {
		global $wp_rewrite;
		$rules   = $wp_rewrite->rules ?: array();
		$results = array();
		foreach ( $rules as $pattern => $query ) {
			$results[] = array( 'match' => $pattern, 'query' => $query );
		}
		return $this->success_result( $results );
	}


	private function handle_cron_event_list( $positional, $flags ) {
		$crons   = _get_cron_array();
		$results = array();
		if ( $crons ) {
			foreach ( $crons as $timestamp => $hooks ) {
				foreach ( $hooks as $hook => $events ) {
					foreach ( $events as $key => $event ) {
						$results[] = array(
							'hook'      => $hook,
							'next_run'  => gmdate( 'Y-m-d H:i:s', $timestamp ),
							'schedule'  => $event['schedule'] ?: 'once',
							'interval'  => $event['interval'] ?? null,
						);
					}
				}
			}
		}
		return $this->success_result( $results );
	}


	private function handle_user_delete( $positional, $flags ) {
		if ( empty( $positional[0] ) ) {
			return $this->error_result( __( 'User identifier required. Usage: user delete <id|login|email> [<id>...] [--reassign=<user>]', 'vibe-ai' ) );
		}

		$reassign = null;
		if ( ! empty( $flags['reassign'] ) ) {
			$ra = $this->resolve_user( $flags['reassign'] );
			if ( ! $ra ) {
				/* translators: %s: user identifier */
				return $this->error_result( sprintf( __( 'Reassign target \'%s\' not found.', 'vibe-ai' ), $flags['reassign'] ) );
			}
			$reassign = $ra->ID;
		}

		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}

		// Lockout protection: never delete the last administrator.
		$admin_ids = array_map( 'intval', (array) get_users( array( 'role' => 'administrator', 'fields' => 'ID', 'number' => -1 ) ) );
		$admins_remaining = count( $admin_ids );

		$idents  = $positional;
		$results = array();
		$ok      = 0;
		foreach ( $idents as $ident ) {
			$user = $this->resolve_user( $ident );
			if ( ! $user ) {
				$results[] = array( 'target' => $ident, 'status' => 'error', 'error' => 'not found' );
				continue;
			}
			if ( in_array( (int) $user->ID, $admin_ids, true ) ) {
				if ( $admins_remaining <= 1 ) {
					$results[] = array( 'target' => $user->user_login, 'id' => $user->ID, 'status' => 'error', 'error' => __( 'refused: this is the last administrator (lockout protection)', 'vibe-ai' ) );
					continue;
				}
				$admins_remaining--;
			}
			if ( wp_delete_user( $user->ID, $reassign ) ) {
				$ok++;
				$results[] = array( 'target' => $user->user_login, 'id' => $user->ID, 'status' => 'deleted' );
			} else {
				$results[] = array( 'target' => $user->user_login, 'id' => $user->ID, 'status' => 'error', 'error' => 'delete failed' );
			}
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => count( $idents ) > 1 ? "Users deleted: {$ok}/" . count( $idents ) : "User deleted: {$results[0]['target']}",
			'action_label' => 'Manage Users',
			'admin_url'    => admin_url( 'users.php' ),
		) );

		if ( 1 === count( $idents ) ) {
			$only = $results[0];
			if ( 'error' === $only['status'] ) {
				/* translators: 1: user identifier, 2: error message */
				return $this->error_result( sprintf( __( 'User \'%1$s\': %2$s', 'vibe-ai' ), $only['target'], $only['error'] ) );
			}
			return $this->success_result( array(
				/* translators: 1: user login, 2: user ID */
				'message'       => sprintf( __( 'Deleted user \'%1$s\' (#%2$d).', 'vibe-ai' ), $only['target'], $only['id'] ),
				'reassigned_to' => $reassign,
			) );
		}

		return $this->success_result( array(
			/* translators: 1: success count, 2: total */
			'message'       => sprintf( __( 'Deleted %1$d of %2$d users.', 'vibe-ai' ), $ok, count( $idents ) ),
			'succeeded'     => $ok,
			'total'         => count( $idents ),
			'reassigned_to' => $reassign,
			'results'       => $results,
		) );
	}


	private function handle_rewrite_flush( $positional, $flags ) {
		flush_rewrite_rules();
		WPVibe_Change_Tracker::mark( array(
			'summary'      => 'Rewrite rules flushed',
			'action_label' => 'Refresh',
		) );
		return $this->success_result( array( 'message' => __( 'Rewrite rules flushed.', 'vibe-ai' ) ) );
	}


	private function handle_rewrite_structure( $positional, $flags ) {
		global $wp_rewrite;
		$reject = $this->reject_unknown_flags( 'rewrite structure', $flags, array( 'category_base', 'tag_base', 'hard' ) );
		if ( $reject ) {
			return $reject;
		}
		$structure = isset( $positional[0] ) ? (string) $positional[0] : '';
		if ( '' === $structure ) {
			return $this->error_result( __( 'Usage: rewrite structure <permastruct> [--category-base=<base>] [--tag-base=<base>] [--hard]', 'vibe-ai' ) );
		}
		if ( ! $this->skip_destructive ) {
			// classify_destructive should have gated this; defense-in-depth.
			return $this->error_result( __( 'rewrite structure requires explicit approval.', 'vibe-ai' ) );
		}
		$wp_rewrite->set_permalink_structure( $structure );
		if ( isset( $flags['category_base'] ) ) {
			$wp_rewrite->set_category_base( (string) $flags['category_base'] );
		}
		if ( isset( $flags['tag_base'] ) ) {
			$wp_rewrite->set_tag_base( (string) $flags['tag_base'] );
		}
		// --hard also rewrites .htaccess; soft flush otherwise. Matches upstream,
		// which shells out to `rewrite flush [--hard]` after setting structure.
		// The .htaccess writer (save_mod_rewrite_rules) lives in wp-admin, which
		// the REST context doesn't bootstrap — load it so --hard isn't a silent
		// soft flush that still reports success.
		$hard = ! empty( $flags['hard'] );
		if ( $hard && ! function_exists( 'save_mod_rewrite_rules' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
		flush_rewrite_rules( $hard );
		WPVibe_Change_Tracker::mark( array(
			'summary'      => 'Permalink structure updated',
			'action_label' => 'Refresh',
		) );
		// Upstream parity: multisite can't generate .htaccess, warn but exit 0.
		$notice = ( $hard && is_multisite() )
			? __( "WordPress can't generate .htaccess file for a multisite install.", 'vibe-ai' )
			: '';
		return $this->success_result( array(
			/* translators: %s: permalink structure */
			'message'   => sprintf( __( 'Permalink structure set to "%s" and rewrite rules flushed.', 'vibe-ai' ), $structure ),
			'structure' => $structure,
		), $notice );
	}


	private function handle_not_implemented( $positional, $flags ) {
		return $this->error_result( __( 'This command is not yet implemented via native dispatch. Use the WordPress admin dashboard.', 'vibe-ai' ) );
	}



	private function handle_menu_item_list( $positional, $flags ) {
		if ( empty( $positional[0] ) ) {
			return $this->error_result( __( 'Menu required. Usage: menu item list <menu> (accepts id, slug, or name)', 'vibe-ai' ) );
		}
		$menu  = is_numeric( $positional[0] ) ? (int) $positional[0] : $positional[0];
		$items = wp_get_nav_menu_items( $menu );
		if ( false === $items ) {
			/* translators: %s: menu identifier */
			return $this->error_result( sprintf( __( 'Menu \'%s\' not found. Run `menu list` to see available menus.', 'vibe-ai' ), $positional[0] ) );
		}
		$results = array();
		foreach ( $items as $item ) {
			$results[] = array(
				'db_id'     => (int) $item->ID,
				'type'      => $item->type,
				'object'    => $item->object,
				'object_id' => (int) $item->object_id,
				'title'     => $item->title,
				'link'      => $item->url,
				'position'  => (int) $item->menu_order,
				'parent'    => (int) $item->menu_item_parent,
			);
		}
		return $this->success_result( $this->filter_fields( $results, $flags ) );
	}


	private function handle_user_get( $positional, $flags ) {
		if ( empty( $positional[0] ) ) {
			return $this->error_result( __( 'User identifier required. Usage: user get <id|login|email>', 'vibe-ai' ) );
		}
		$ident = $positional[0];
		$user  = is_numeric( $ident )
			? get_user_by( 'id', (int) $ident )
			: ( is_email( $ident ) ? get_user_by( 'email', $ident ) : get_user_by( 'login', $ident ) );
		if ( ! $user ) {
			/* translators: %s: user identifier */
			return $this->error_result( sprintf( __( 'User \'%s\' not found.', 'vibe-ai' ), $ident ) );
		}
		$data = array(
			'ID'              => (int) $user->ID,
			'user_login'      => $user->user_login,
			'display_name'    => $user->display_name,
			'user_email'      => $user->user_email,
			'user_registered' => $user->user_registered,
			'user_nicename'   => $user->user_nicename,
			'user_url'        => $user->user_url,
			'roles'           => implode( ',', (array) $user->roles ),
		);
		return $this->success_result( $this->filter_fields( array( $data ), $flags )[0] ?? $data );
	}


	private function handle_cron_test( $positional, $flags ) {
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			return $this->error_result( __( 'The DISABLE_WP_CRON constant is set to true. WP-Cron spawning is disabled — scheduled events only run if a system cron hits wp-cron.php directly.', 'vibe-ai' ) );
		}
		$notes = array();
		if ( defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON ) {
			$notes[] = __( 'The ALTERNATE_WP_CRON constant is set to true; cron runs via a redirect fallback rather than the HTTP spawn tested here.', 'vibe-ai' );
		}
		$doing    = sprintf( '%.22F', microtime( true ) );
		$url      = add_query_arg( 'doing_wp_cron', $doing, site_url( 'wp-cron.php' ) );
		$response = wp_remote_post( $url, array(
			'timeout'   => 10,
			'blocking'  => true,
			'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
		) );
		if ( is_wp_error( $response ) ) {
			/* translators: %s: HTTP error message */
			return $this->error_result( sprintf( __( 'WP-Cron spawn failed: %s. The site cannot reach its own wp-cron.php (loopback requests blocked?), so scheduled events will not run on traffic.', 'vibe-ai' ), $response->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 300 ) {
			/* translators: %d: HTTP status code */
			return $this->error_result( sprintf( __( 'WP-Cron spawn returned HTTP %d (expected 200). Scheduled events may not be running.', 'vibe-ai' ), $code ) );
		}
		return $this->success_result( array(
			'message'           => __( 'WP-Cron spawning is working as expected.', 'vibe-ai' ),
			'spawn_status_code' => $code,
			'notes'             => $notes,
		) );
	}


	private function handle_maintenance_mode_status( $positional, $flags ) {
		// Real `wp maintenance-mode status` only checks the core .maintenance
		// file. Plugin-based maintenance is the kind users actually have, and
		// the drop-in decides what visitors see — so this reports all three.
		$core    = $this->core_maintenance_state( ABSPATH . '.maintenance' );
		$drop_in = array(
			'present' => file_exists( WP_CONTENT_DIR . '/maintenance.php' ),
			'path'    => 'wp-content/maintenance.php',
			'note'    => __( 'Custom maintenance page, served whenever core maintenance mode is active.', 'vibe-ai' ),
		);
		$plugins = $this->detect_maintenance_plugins();

		$plugin_enabled = array();
		foreach ( $plugins as $p ) {
			if ( ! empty( $p['enabled'] ) ) {
				$plugin_enabled[] = $p['name'];
			}
		}

		$active = $core['active'] || ! empty( $plugin_enabled );
		if ( $core['active'] ) {
			$message = __( 'Maintenance mode is active (core .maintenance file). Visitors and REST requests get a maintenance page instead of normal responses.', 'vibe-ai' );
		} elseif ( ! empty( $plugin_enabled ) ) {
			/* translators: %s: plugin names */
			$message = sprintf( __( 'Maintenance/coming-soon mode is active via plugin: %s. The site answers frontend requests with the plugin\'s holding page.', 'vibe-ai' ), implode( ', ', $plugin_enabled ) );
		} else {
			$message = __( 'Maintenance mode is not active.', 'vibe-ai' );
		}

		return $this->success_result( array(
			'active'                   => $active,
			'core_maintenance_file'    => $core,
			'maintenance_page_drop_in' => $drop_in,
			'maintenance_plugins'      => $plugins,
			'message'                  => $message,
		) );
	}


	/**
	 * Effective state of the core .maintenance file. Core ignores the file once
	 * $upgrading is more than 10 minutes old, so presence alone is not "active".
	 */
	private function core_maintenance_state( $path ) {
		$state = array( 'present' => file_exists( $path ), 'active' => false );
		if ( ! $state['present'] ) {
			return $state;
		}
		$contents = (string) file_get_contents( $path );
		if ( preg_match( '/\$upgrading\s*=\s*(\d+)/', $contents, $m ) ) {
			$started             = (int) $m[1];
			$state['started_at'] = gmdate( 'Y-m-d H:i:s', $started );
			$state['active']     = ( time() - $started ) < 600;
			if ( ! $state['active'] ) {
				$state['note'] = __( 'The .maintenance file is present but its timestamp is older than 10 minutes, so core ignores it. A stuck file usually means an update died mid-flight; it is safe to delete.', 'vibe-ai' );
			}
		} else {
			$state['note'] = __( 'No parseable $upgrading timestamp in the .maintenance file; core treats that as expired (not in maintenance).', 'vibe-ai' );
		}
		return $state;
	}


	/**
	 * Known maintenance/coming-soon plugins with their enable state where the
	 * option schema is known; enabled=null means "active but state unreadable".
	 */
	private function detect_maintenance_plugins() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$found = array();

		foreach ( array( 'coming-soon/coming-soon.php', 'seedprod-pro/seedprod-pro.php' ) as $file ) {
			if ( is_plugin_active( $file ) ) {
				$settings = json_decode( (string) get_option( 'seedprod_settings' ), true );
				$modes    = array();
				if ( ! empty( $settings['enable_coming_soon_mode'] ) ) {
					$modes[] = 'coming_soon';
				}
				if ( ! empty( $settings['enable_maintenance_mode'] ) ) {
					$modes[] = 'maintenance';
				}
				$found[] = array( 'plugin' => $file, 'name' => 'SeedProd', 'enabled' => ! empty( $modes ), 'modes' => $modes );
			}
		}

		if ( is_plugin_active( 'wp-maintenance-mode/wp-maintenance-mode.php' ) ) {
			$settings = get_option( 'wpmm_settings' );
			$found[]  = array(
				'plugin'  => 'wp-maintenance-mode/wp-maintenance-mode.php',
				'name'    => 'LightStart (WP Maintenance Mode)',
				'enabled' => ! empty( $settings['general']['status'] ),
			);
		}

		if ( is_plugin_active( 'under-construction-page/under-construction.php' ) ) {
			$options = get_option( 'ucp_options' );
			$found[] = array(
				'plugin'  => 'under-construction-page/under-construction.php',
				'name'    => 'Under Construction',
				'enabled' => ! empty( $options['status'] ),
			);
		}

		if ( is_plugin_active( 'cmp-coming-soon-maintenance/niteo-cmp.php' ) ) {
			$found[] = array(
				'plugin'  => 'cmp-coming-soon-maintenance/niteo-cmp.php',
				'name'    => 'CMP Coming Soon & Maintenance',
				'enabled' => ( '1' === (string) get_option( 'niteoCS_status' ) ),
			);
		}

		if ( is_plugin_active( 'maintenance/maintenance.php' ) ) {
			$found[] = array(
				'plugin'  => 'maintenance/maintenance.php',
				'name'    => 'Maintenance',
				'enabled' => null,
				'note'    => __( 'Plugin is active; enable state is not readable from options — check its settings page.', 'vibe-ai' ),
			);
		}

		return $found;
	}


	private function handle_cron_event_run( $positional, $flags ) {
		if ( empty( $positional ) ) {
			return $this->error_result( __( 'Hook required. Usage: cron event run <hook> [<hook>...]', 'vibe-ai' ) );
		}
		$crons   = _get_cron_array() ?: array();
		$results = array();
		$ok      = 0;
		foreach ( $positional as $hook ) {
			$instances = array();
			foreach ( $crons as $timestamp => $hooks ) {
				if ( isset( $hooks[ $hook ] ) ) {
					foreach ( $hooks[ $hook ] as $event ) {
						$instances[] = $event;
					}
				}
			}
			if ( empty( $instances ) ) {
				$results[] = array( 'hook' => $hook, 'status' => 'error', 'error' => __( 'no scheduled events for this hook', 'vibe-ai' ) );
				continue;
			}
			$executed = 0;
			$error    = null;
			foreach ( $instances as $event ) {
				try {
					do_action_ref_array( $hook, isset( $event['args'] ) ? (array) $event['args'] : array() );
					$executed++;
				} catch ( \Throwable $e ) {
					$error = $e->getMessage();
					break;
				}
			}
			if ( null !== $error ) {
				/* translators: %s: error message */
				$results[] = array( 'hook' => $hook, 'status' => 'error', 'executed' => $executed, 'error' => sprintf( __( 'callback threw: %s', 'vibe-ai' ), $error ) );
				continue;
			}
			$ok++;
			$results[] = array( 'hook' => $hook, 'status' => 'executed', 'executed' => $executed );
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Cron hook(s) run: {$ok}/" . count( $positional ),
			'action_label' => 'Refresh',
		) );

		return $this->success_result( array(
			/* translators: 1: success count, 2: total */
			'message' => sprintf( __( 'Executed %1$d of %2$d hook(s).', 'vibe-ai' ), $ok, count( $positional ) ),
			'results' => $results,
		) );
	}


	private function handle_cron_event_delete( $positional, $flags ) {
		if ( empty( $positional ) ) {
			return $this->error_result( __( 'Hook required. Usage: cron event delete <hook> [<hook>...]', 'vibe-ai' ) );
		}
		$results = array();
		$total   = 0;
		foreach ( $positional as $hook ) {
			$removed = wp_unschedule_hook( $hook );
			if ( false === $removed || is_wp_error( $removed ) ) {
				$results[] = array( 'hook' => $hook, 'status' => 'error', 'error' => __( 'unschedule failed', 'vibe-ai' ) );
				continue;
			}
			$total    += (int) $removed;
			$results[] = array( 'hook' => $hook, 'status' => 'deleted', 'events_removed' => (int) $removed );
		}

		WPVibe_Change_Tracker::mark( array(
			'summary'      => "Cron events deleted: {$total}",
			'action_label' => 'Refresh',
		) );

		return $this->success_result( array(
			/* translators: %d: number of events removed */
			'message' => sprintf( __( 'Removed %d scheduled event(s).', 'vibe-ai' ), $total ),
			'results' => $results,
		) );
	}


	private function resolve_user( $ident ) {
		return is_numeric( $ident )
			? get_user_by( 'id', (int) $ident )
			: ( is_email( $ident ) ? get_user_by( 'email', $ident ) : get_user_by( 'login', $ident ) );
	}


	private function handle_menu_location_list( $positional, $flags ) {
		$assigned = get_nav_menu_locations();
		$results  = array();
		foreach ( get_registered_nav_menus() as $location => $description ) {
			$menu      = ! empty( $assigned[ $location ] ) ? wp_get_nav_menu_object( $assigned[ $location ] ) : null;
			$results[] = array(
				'location'      => $location,
				'description'   => $description,
				'assigned_menu' => $menu ? $menu->name : '',
			);
		}
		return $this->success_result( $results );
	}

}
