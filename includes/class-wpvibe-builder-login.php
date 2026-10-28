<?php
/**
 * One-time SeedProd builder auto-login for compile automation.
 *
 * SeedProd pages written through the REST/abilities path store JSON but not
 * the compiled HTML the front end serves; only a Save inside the Vue builder
 * writes it. The WPVibe Worker automates that Save with a headless browser,
 * which needs a wp-admin session. This class mints single-use, short-lived
 * login URLs whose destination is pinned server-side to the builder screen
 * for one specific page. Each URL signs in once, within two minutes. The
 * session it opens lasts ten minutes and is confined to that page's builder:
 * any other wp-admin screen, admin-post or admin-ajax call outside the
 * builder's own (or carrying a field the builder does not send), REST request,
 * or front-end write (a comment, a form post) signs it out and is refused
 * (#1084). Front-end reads run signed out.
 *
 * @package WPVibe
 */

defined( 'ABSPATH' ) || exit;

class WPVibe_Builder_Login {

	const QUERY_PARAM      = 'wpvibe_builder_login';
	const TOKEN_TTL        = 120;
	const RATE_LIMIT       = 30;
	const RATE_WINDOW      = 300;
	const TRANSIENT_PREFIX = 'wpvibe_bl_';
	const RATE_KEY         = 'wpvibe_bl_rate';
	const CACHE_GROUP      = 'wpvibe_builder_login';
	const SESSION_MARK     = 'wpvibe_builder';
	const SESSION_PAGE     = 'wpvibe_builder_page';
	// A compile holds its session for one job: 30 s to open the builder plus 90 s for
	// the Save (compile-service/server.js SP_NAV_TIMEOUT_MS, SP_SAVE_TIMEOUT_MS), inside
	// the Worker's 120 s call. Ten minutes is five times that; core's default is two days.
	const SESSION_TTL      = 600;

	/**
	 * admin-ajax actions a builder session may call, after the seedprod_pro_ or
	 * seedprod_lite_ prefix: the Save the compile step clicks, and the read and
	 * render calls the builder's Vue app makes while it draws the page. Every other
	 * action the builder can call writes something besides this page (settings,
	 * add-ons, templates, media, license) or spends AI credits; the compile step
	 * never reaches those.
	 *
	 * Each entry lists the exact fields the builder sends (SeedProd 6.20.10, Pro and
	 * Lite: public/{pro,lite}/vue-backend/js/index.js, and the nonce URLs in
	 * resources/views/builder.php). The query always carries action and _wpnonce.
	 * A field outside the list refuses the request: other plugins' init, admin_init
	 * and wp_loaded handlers read $_POST and $_GET too.
	 *
	 * - get / post: the query and body field names beyond action and _wpnonce. A
	 *   body is form-encoded (qs.stringify), so nested settings arrive as one
	 *   top-level field.
	 * - text_in_query: the bundle pastes block text (a divider, a button label, a
	 *   link) into the query unencoded, so an & in that text makes stray keys.
	 *   Unlisted query keys are dropped for these calls instead of refused.
	 * - json_body: the body is a JSON string the handler reads from php://input
	 *   (only key: options). Form fields PHP parsed out of it are dropped.
	 */
	const BUILDER_AJAX = array(
		'save_lpage'                               => array( 'post' => array( 'save_type', 'lpage_id', 'lpage_name', 'lpage_slug', 'lpage_html', 'lpage_post_status', 'settings', 'personalization_preferences' ) ),
		'get_revisions'                            => array( 'post' => array( 'lpage_id' ) ),
		'get_utc_offset'                           => array( 'post' => array( 'ends', 'ends_time', 'timezone' ) ),
		'get_namespaced_custom_css'                => array( 'post' => array( 'css' ) ),
		'get_domain_mapping_domain'                => array( 'post' => array( 'lpage_id' ) ),
		'get_site_logo'                            => array(),
		'get_template_part'                        => array( 'post' => array( 'type' ) ),
		'get_template_parts_list'                  => array(),
		'get_template_parts_result'                => array( 'get' => array( 'parts_id' ) ),
		'get_nested_navmenu'                       => array(
			'get'           => array( 'navmenu_name', 'divider', 'layout' ),
			'text_in_query' => true,
		),
		'render_shortcode'                         => array( 'post' => array( 'shortcode' ) ),
		'render_templatetag'                       => array( 'post' => array( 'templatetag', 'renderscripts' ) ),
		'render_postinfo_templatetags'             => array( 'post' => array( 'items', 'spaceBetween', 'divider', 'showIcons' ) ),
		'render_posts_block_preview'               => array(
			'get'           => array( 'query_type', 'query_by_post_type', 'post_type', 'order', 'orderby', 'query_by_category', 'cat', 'query_by_tags', 'tags', 'query_by_authors', 'authors', 'manual_query', 'posts_per_page', 'show_featured_image', 'show_title', 'title_html_tag', 'show_meta_options', 'show_author_meta', 'show_date_meta', 'show_date_modified_meta', 'show_time_meta', 'show_comment_count_meta', 'meta_separator', 'show_excerpt', 'excerpt_length', 'show_read_more', 'read_more_text', 'columns', 'skin', 'skinlayout', 'imageposition', 'avatar', 'badge', 'badgetaxonomy', 'pagination', 'slidetoshow', 'autoplay', 'speed' ),
			'text_in_query' => true,
		),
		'render_wc_template_tags'                  => array( 'post' => array( 'wc_template_tag', 'divider' ) ),
		'render_edd_template_tags'                 => array( 'post' => array( 'edd_template_tag' ) ),
		'render_gallery_shortcode'                 => array(
			'get'           => array( 'galleryType', 'galleryLayout', 'galleryimgid', 'galleryLink', 'colSpacing', 'customurl' ),
			'post'          => array( 'items', 'settings' ),
			'text_in_query' => true,
		),
		'render_basic_gallery_shortcode'           => array(
			'get'  => array( 'lightboxEffect' ),
			'post' => array( 'items', 'settings' ),
		),
		'render_business_review_shortcode'         => array( 'post' => array( 'settings' ) ),
		'render_shortcode_wc_cart'                 => array(),
		'render_shortcode_wc_checkout'             => array(),
		'render_shortcode_wc_custom_products_grid' => array(
			'get'           => array( 'cols', 'paginate', 'limit', 'orderby', 'order', 'ids', 'skus', 'category', 'tag', 'cat_operator', 'tag_operator', 'attribute', 'terms', 'terms_operator', 'visibility', 'product_group', 'source' ),
			'text_in_query' => true,
		),
		'render_shortcode_edd_cart'                => array(),
		'render_shortcode_edd_checkout'            => array(),
		'render_shortcode_edd_downloads_grid'      => array( 'get' => array( 'category', 'exclude_category', 'tags', 'exclude_tags', 'relation', 'number', 'price', 'excerpt', 'full_content', 'buy_button', 'columns', 'thumbnails', 'order_by', 'order', 'ids', 'pagination' ) ),
		'render_shortcode_edd_buy_now_button'      => array(
			'get'           => array( 'product_id', 'direct_to_checkout', 'show_price', 'btn_txt', 'before_icon', 'after_icon' ),
			'text_in_query' => true,
		),
		'edd_checkout_purchase_form'               => array(),
		'get_wpform'                               => array( 'get' => array( 'form_id', 'form_title', 'form_description' ) ),
		'get_wpforms'                              => array(),
		'get_widget_wpforms'                       => array(
			'get'       => array( 'block_type', 'base_id' ),
			'json_body' => true,
		),
		'get_widget_wpresults'                     => array(
			'get'       => array( 'block_type' ),
			'json_body' => true,
		),
		'get_rafflepress'                          => array(),
		'get_rafflepress_code'                     => array( 'get' => array( 'form_id', 'form_title', 'form_description' ) ),
		'get_mypaykit'                             => array(),
		'get_mypaykit_code'                        => array( 'get' => array( 'form_id' ) ),
		'get_envira_galleries'                     => array( 'get' => array( 'type' ) ),
		'get_woocommerce_products'                 => array(),
		'get_woocommerce_product_taxonomy'         => array( 'get' => array( 'taxonomy' ) ),
		'get_woocommerce_product_attributes'       => array(),
		'get_woocommerce_product_attribute_terms'  => array( 'get' => array( 'attribute' ) ),
		'get_edd_downloads'                        => array(),
		'get_edd_download_taxonomy'                => array( 'get' => array( 'taxonomy' ) ),
		'get_all_post_types'                       => array(),
		'get_all_post_categories'                  => array(),
		'get_all_post_tags'                        => array(),
		'get_all_post_authors'                     => array(),
		'get_post_custom_keys_array'               => array( 'post' => array( 'id' ) ),
	);

	/** The heartbeat wp-auth-check sends with an empty queue (wp-includes/js/heartbeat.js, ajaxData); no data field. */
	const HEARTBEAT_FIELDS = array( 'action', '_nonce', 'interval', 'screen_id', 'has_focus' );

	/**
	 * Builder calls that name the page being edited, and the field that carries it.
	 * When present it must be the page the link was minted for; Save requires it.
	 */
	const PAGE_PARAMS = array(
		'save_lpage'                 => 'lpage_id',
		'get_revisions'              => 'lpage_id',
		'get_domain_mapping_domain'  => 'lpage_id',
		'get_post_custom_keys_array' => 'id',
	);

	/**
	 * Taken from a builder session for the rest of an allowed wp-admin request, through
	 * map_meta_cap (which binds a multisite Super Admin too) and user_has_cap.
	 * unfiltered_html stays: SeedProd's Save refuses without it (app/lpage.php, save_lpage).
	 */
	const STRIPPED_CAPS = array(
		'unfiltered_upload',
		'edit_files',
		'edit_plugins',
		'edit_themes',
		'install_plugins',
		'upload_plugins',
		'install_themes',
		'upload_themes',
		'update_plugins',
		'update_themes',
		'update_core',
		'activate_plugins',
		'delete_plugins',
		'delete_themes',
		'switch_themes',
		'create_users',
		'promote_users',
		'edit_users',
		'delete_users',
		'install_languages',
		'update_languages',
		'resume_plugins',
		'resume_themes',
		// What only a Super Admin holds.
		'manage_network',
		'manage_sites',
		'manage_network_users',
		'manage_network_plugins',
		'manage_network_themes',
		'manage_network_options',
		'upgrade_network',
		'setup_network',
		'create_sites',
		'delete_sites',
	);

	/**
	 * Also taken for an allowed admin-ajax call: no SeedProd builder handler checks them
	 * (each checks edit_others_posts, unfiltered_html for Save, or nothing). The builder
	 * screen keeps them: it is the session's first wp-admin request, where SeedProd's own
	 * admin_init upgrade step can migrate a license key behind manage_options
	 * (app/bootstrap.php seedprod_pro_upgrade, app/backwards/backwards_compatibility.php),
	 * and where a site's own "keep non-admins out of wp-admin" code would bounce the builder.
	 */
	const AJAX_STRIPPED_CAPS = array( 'manage_options', 'edit_theme_options' );

	/** Every cookie a session can sign in with; wp-admin reads auth/secure_auth, REST and nonces read logged_in. */
	const COOKIE_SCHEMES = array( 'auth', 'secure_auth', 'logged_in' );

	private static $instance = null;

	/**
	 * Which claim path the last consume() took: 'options_row' or
	 * 'object_cache'. For tests and diagnostics only.
	 *
	 * @var string|null
	 */
	public $last_claim_path = null;

	/** The builder session's user while an allowed request runs; its caps are trimmed. */
	private $confined_user_id = 0;

	/** The caps taken from that user for this request. */
	private $stripped_caps = array();

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'maybe_consume' ), 1 );
		// init, not admin_init: admin.php runs admin_menu callbacks and screen-option
		// saves before admin_init, and plugins handle their own requests on init.
		add_action( 'init', array( $this, 'confine_request' ), PHP_INT_MIN );
		// Comments skip kses for unfiltered_html and auto-approve for moderators.
		add_action( 'pre_comment_on_post', array( $this, 'refuse_comment' ), 0 );
		// After core's cookie nonce check (100), so an anonymous request stays anonymous.
		add_filter( 'rest_authentication_errors', array( $this, 'confine_rest' ), 110 );
		// Before core's handler (1).
		add_action( 'wp_ajax_heartbeat', array( $this, 'confine_heartbeat' ), 0 );
	}

	/**
	 * The builder admin-page slug differs between SeedProd Pro and Lite.
	 * Null when no SeedProd builder is available.
	 */
	public static function builder_page_slug() {
		if ( function_exists( 'seedprod_pro_builder_page' ) ) {
			return 'seedprod_pro_builder';
		}
		if ( function_exists( 'seedprod_lite_builder_page' ) ) {
			return 'seedprod_lite_builder';
		}
		return null;
	}

	/**
	 * Same capability SeedProd requires for its builder screen.
	 */
	public static function required_capability() {
		return apply_filters( 'seedprod_builder_menu_capability', 'edit_others_posts' );
	}

	public static function builder_url( $post_id ) {
		return admin_url( 'admin.php?page=' . self::builder_page_slug() . '&id=' . (int) $post_id );
	}

	/**
	 * Landing pages save as post_type `page` flagged by `_seedprod_page`;
	 * only theme templates and mode pages use the `seedprod` CPT.
	 */
	public static function is_seedprod_page( $post_id ) {
		$type = get_post_type( $post_id );
		if ( 'seedprod' === $type ) {
			return true;
		}
		return 'page' === $type && (bool) get_post_meta( (int) $post_id, '_seedprod_page', true );
	}

	/**
	 * REST handler: mint a login URL for one SeedProd page.
	 * Route + permission callback live in WPVibe_REST.
	 */
	public function mint( $request ) {
		if ( ! self::builder_page_slug() ) {
			return new WP_Error(
				'seedprod_missing',
				__( 'SeedProd is not active on this site, so there is no builder to open.', 'vibe-ai' ),
				WPVibe_Error_Contract::data( 'not_supported', false, array( 'status' => 501 ) )
			);
		}

		$post_id = (int) $request->get_param( 'page_id' );
		if ( $post_id <= 0 || ! self::is_seedprod_page( $post_id ) ) {
			return new WP_Error(
				'not_seedprod_page',
				__( 'That id is not a SeedProd page, so a builder login cannot be minted for it.', 'vibe-ai' ),
				WPVibe_Error_Contract::data( 'not_found', false, array( 'status' => 404 ) )
			);
		}

		$count = (int) get_transient( self::RATE_KEY );
		if ( $count >= self::RATE_LIMIT ) {
			return new WP_Error(
				'builder_login_rate_limited',
				__( 'Too many builder login links were requested in a short period. Wait a few minutes and try again.', 'vibe-ai' ),
				WPVibe_Error_Contract::data( 'security_gate', true, array( 'status' => 429 ) )
			);
		}
		set_transient( self::RATE_KEY, $count + 1, self::RATE_WINDOW );

		$token = $this->generate_token();
		set_transient(
			self::TRANSIENT_PREFIX . hash( 'sha256', $token ),
			array(
				'user_id' => get_current_user_id(),
				'post_id' => $post_id,
				'expires' => time() + self::TOKEN_TTL,
			),
			self::TOKEN_TTL
		);

		if ( class_exists( 'WPVibe_Audit_Log' ) ) {
			WPVibe_Audit_Log::log_execution(
				array(
					'operation'      => 'builder_login_mint',
					'command'        => 'page_id=' . $post_id,
					'result_summary' => 'login URL minted, ttl ' . self::TOKEN_TTL . 's',
				)
			);
		}

		return rest_ensure_response(
			array(
				'login_url'   => add_query_arg( self::QUERY_PARAM, rawurlencode( $token ), home_url( '/' ) ),
				'builder_url' => self::builder_url( $post_id ),
				'expires_in'  => self::TOKEN_TTL,
			)
		);
	}

	protected function generate_token() {
		return wp_generate_password( 43, false, false );
	}

	/**
	 * Validate and burn a token. Pure with respect to auth side effects so
	 * tests can exercise every branch; maybe_consume() applies the results.
	 *
	 * @return array|WP_Error { user_id, post_id, redirect } on success.
	 */
	public function consume( $token, $now = null ) {
		$now    = null === $now ? time() : (int) $now;
		$key    = self::TRANSIENT_PREFIX . hash( 'sha256', (string) $token );
		$record = get_transient( $key );

		if ( ! is_array( $record ) || empty( $record['user_id'] ) || empty( $record['post_id'] ) ) {
			return new WP_Error( 'builder_login_invalid', 'invalid' );
		}
		// Single-use: claim before validating. Two requests can both read the
		// record; only the one whose claim succeeds may redeem it.
		if ( ! $this->claim( $key ) ) {
			return new WP_Error( 'builder_login_invalid', 'already used' );
		}
		if ( empty( $record['expires'] ) || $now > (int) $record['expires'] ) {
			return new WP_Error( 'builder_login_expired', 'expired' );
		}
		if ( ! self::builder_page_slug() ) {
			return new WP_Error( 'builder_login_no_builder', 'seedprod inactive' );
		}
		$user = get_user_by( 'id', (int) $record['user_id'] );
		if ( ! $user || ! user_can( $user, self::required_capability() ) ) {
			return new WP_Error( 'builder_login_forbidden', 'capability revoked' );
		}

		return array(
			'user_id'  => (int) $record['user_id'],
			'post_id'  => (int) $record['post_id'],
			'redirect' => self::builder_url( $record['post_id'] ),
		);
	}

	/**
	 * Burn a token exactly once, even under concurrent requests. Returns true
	 * only for the one caller that wins.
	 *
	 * Without a persistent object cache the transient is a row in wp_options,
	 * and a DELETE reports how many rows it removed: 1 wins, 0 means another
	 * request already took it. With a persistent object cache the transient
	 * never reaches wp_options, so wp_cache_add of a one-shot key is the lock:
	 * add succeeds only for the first caller. The lock's winner must still find
	 * the token in the cache backend (forced read, past the in-request copy)
	 * and must be the one whose delete removes it: if a flush or eviction drops
	 * the lock while another request holds it, a second add can succeed, but
	 * the backend deletes the key for only one of them.
	 */
	protected function claim( $key ) {
		if ( wp_using_ext_object_cache() ) {
			$this->last_claim_path = 'object_cache';
			if ( ! wp_cache_add( 'consumed_' . $key, 1, self::CACHE_GROUP, self::TOKEN_TTL ) ) {
				return false;
			}
			$still_there = wp_cache_get( $key, 'transient', true );
			return false !== $still_there && (bool) delete_transient( $key );
		}

		global $wpdb;
		$this->last_claim_path = 'options_row';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The affected-row count is the atomic single-use check; caches are cleared below.
		$rows = $wpdb->delete( $wpdb->options, array( 'option_name' => '_transient_' . $key ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $wpdb->options, array( 'option_name' => '_transient_timeout_' . $key ) );
		wp_cache_delete( '_transient_' . $key, 'options' );
		wp_cache_delete( '_transient_timeout_' . $key, 'options' );
		return 1 === $rows;
	}

	/** True when the current browser session was opened by a builder sign-in link. */
	public static function is_builder_session() {
		return null !== self::builder_session();
	}

	/** The current session's record when a builder sign-in link opened it, else null. */
	private static function builder_session() {
		$found = self::builder_sessions();
		return $found ? $found[0]['session'] : null;
	}

	/**
	 * Builder sessions behind any auth cookie the request carries. Every scheme is
	 * checked, not only the logged_in cookie wp_get_session_token() reads: a client
	 * that drops logged_in still signs in to wp-admin with the auth cookie alone.
	 *
	 * @return array[] { user_id, token, session } for each tagged session.
	 */
	private static function builder_sessions() {
		if ( ! function_exists( 'wp_parse_auth_cookie' ) || ! class_exists( 'WP_Session_Tokens' ) ) {
			return array();
		}
		$found = array();
		foreach ( self::COOKIE_SCHEMES as $scheme ) {
			$cookie = wp_parse_auth_cookie( '', $scheme );
			if ( empty( $cookie['token'] ) ) {
				continue;
			}
			$user_id = (int) wp_validate_auth_cookie( '', $scheme );
			if ( $user_id <= 0 ) {
				continue;
			}
			$session = WP_Session_Tokens::get_instance( $user_id )->get( $cookie['token'] );
			if ( is_array( $session ) && ! empty( $session[ self::SESSION_MARK ] ) ) {
				$found[] = array( 'user_id' => $user_id, 'token' => $cookie['token'], 'session' => $session );
			}
		}
		return $found;
	}

	/**
	 * What a builder session may load in wp-admin: the builder screen for the page
	 * the link was minted for, exactly as the compile step opens it, and the
	 * builder's own admin-ajax calls with the fields the builder sends (Save only
	 * for that page). $body is the raw request body, read for a json_body call and
	 * for a POST that PHP parsed no form fields from.
	 */
	public static function admin_request_allowed( $pagenow, $method, $get, $post, $page_id, $body = '' ) {
		if ( $page_id <= 0 ) {
			return false;
		}
		if ( 'admin.php' === $pagenow ) {
			$slug = self::builder_page_slug();
			return in_array( $method, array( 'GET', 'HEAD' ), true )
				&& null !== $slug
				&& self::only_fields( $get, array( 'page', 'id' ) )
				&& isset( $get['page'], $get['id'] )
				&& $slug === $get['page']
				&& (string) $page_id === $get['id'];
		}
		if ( 'admin-ajax.php' !== $pagenow ) {
			return false;
		}
		// wp-auth-check, which SeedProd keeps on the builder screen, polls heartbeat with no
		// payload (core answers it on heartbeat_send). A payload drives other handlers:
		// post nonces, autosaves, locks. confine_heartbeat() strips the rest.
		if ( 'heartbeat' === self::ajax_action( $get, $post ) ) {
			return array() === $get && self::only_fields( $post, self::HEARTBEAT_FIELDS );
		}
		// The builder names its call in the query only. A body action would decide what the
		// check sees while core dispatches whatever $_REQUEST says after the fields are dropped.
		if ( isset( $post['action'] ) ) {
			return false;
		}
		$call = self::builder_call( $get );
		if ( null === $call ) {
			return false;
		}
		$spec = self::BUILDER_AJAX[ $call ];
		// The builder puts action and _wpnonce in the query, never in the body.
		if ( empty( $spec['text_in_query'] ) && ! self::only_fields( $get, self::query_fields( $call ) ) ) {
			return false;
		}
		if ( ! empty( $spec['json_body'] ) ) {
			if ( ! self::json_body_ok( $body, $post ) ) {
				return false;
			}
		} elseif ( ! self::only_fields( $post, isset( $spec['post'] ) ? $spec['post'] : array() ) ) {
			return false;
		} elseif ( array() === $post && '' !== $body ) {
			// A JSON or other non-form body: no field check could see what it carries.
			return false;
		}
		if ( ! isset( self::PAGE_PARAMS[ $call ] ) ) {
			return true;
		}
		$field = self::PAGE_PARAMS[ $call ];
		$sent  = isset( $post[ $field ] ) ? $post[ $field ] : ( isset( $get[ $field ] ) ? $get[ $field ] : null );
		if ( null === $sent ) {
			return 'save_lpage' !== $call;
		}
		return (string) $page_id === $sent;
	}

	/** Core reads $_REQUEST, which WordPress rebuilds as GET overlaid by POST. */
	private static function ajax_action( $get, $post ) {
		return isset( $post['action'] ) ? $post['action'] : ( isset( $get['action'] ) ? $get['action'] : '' );
	}

	/** The BUILDER_AJAX entry the query's action names (after seedprod_pro_ or seedprod_lite_), or null. */
	private static function builder_call( $get ) {
		$action = isset( $get['action'] ) ? $get['action'] : '';
		if ( is_string( $action ) && preg_match( '/^seedprod_(?:pro|lite)_([a-z_]+)$/', $action, $m ) && isset( self::BUILDER_AJAX[ $m[1] ] ) ) {
			return $m[1];
		}
		return null;
	}

	private static function query_fields( $call ) {
		$spec = self::BUILDER_AJAX[ $call ];
		return array_merge( array( 'action', '_wpnonce' ), isset( $spec['get'] ) ? $spec['get'] : array() );
	}

	/** True when every top-level field name is on the list. */
	private static function only_fields( $fields, $allowed ) {
		return is_array( $fields ) && array() === array_diff( array_map( 'strval', array_keys( $fields ) ), $allowed );
	}

	/**
	 * The widget calls' body: a JSON object whose only key is options, with no & in it,
	 * so PHP parsed it into one junk form field and nothing else (filter_input(INPUT_POST)
	 * included). An empty raw body must come with no form fields: a multipart body
	 * leaves php://input empty while PHP still parses its fields.
	 */
	private static function json_body_ok( $body, $post ) {
		if ( '' === $body ) {
			return array() === $post;
		}
		if ( false !== strpos( $body, '&' ) ) {
			return false;
		}
		$data = json_decode( $body, true );
		return is_array( $data ) && self::only_fields( $data, array( 'options' ) );
	}

	/**
	 * For an allowed call the builder can send stray fields with, take them out before
	 * other plugins' init and wp_loaded handlers read them: the unlisted query keys of a
	 * text_in_query call, and every form field PHP parsed out of a json_body call's JSON
	 * (the handler reads php://input and its query through filter_input).
	 */
	private static function drop_unsent_fields( $call ) {
		$spec = self::BUILDER_AJAX[ $call ];
		if ( empty( $spec['text_in_query'] ) && empty( $spec['json_body'] ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification -- Removes fields; the builder's handlers verify their nonces.
		if ( ! empty( $spec['text_in_query'] ) ) {
			$_GET = array_intersect_key( $_GET, array_flip( self::query_fields( $call ) ) );
		}
		if ( ! empty( $spec['json_body'] ) ) {
			$_POST = array();
		}
		$_REQUEST = array_merge( $_GET, $_POST );
		// phpcs:enable WordPress.Security.NonceVerification
	}

	/**
	 * init: a builder session reaches nothing in wp-admin beyond the builder. On the
	 * front end it reads as a signed-out visitor (GET/HEAD: no drafts, private posts or
	 * nonce-checked links), and a front-end write (wp-comments-post.php, any plugin's
	 * form handler) signs it out.
	 */
	public function confine_request() {
		$this->confined_user_id = 0;
		$this->stripped_caps    = array();
		$session                = self::builder_session();
		if ( null === $session ) {
			return;
		}
		global $pagenow;
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! is_admin() ) {
			// The compile step's only front-end request is the sign-in link, which carries no
			// session cookie yet. Anything else (a preview's iframe or image) needs no account.
			if ( in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
				wp_set_current_user( 0 );
				return;
			}
		} else {
			$page_id = isset( $session[ self::SESSION_PAGE ] ) ? (int) $session[ self::SESSION_PAGE ] : 0;
			$get     = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification -- Read-only allowlist check; the builder's own handlers verify their nonces.
			$post    = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
			$ajax    = 'admin-ajax.php' === (string) $pagenow;
			$call    = $ajax ? self::builder_call( $get ) : null;
			// The raw body only where the form fields can't tell what was sent: the widget
			// calls' JSON, and a POST PHP parsed no fields from (a JSON or other non-form body).
			$read    = null !== $call && ( ! empty( self::BUILDER_AJAX[ $call ]['json_body'] ) || ( array() === $post && ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) );
			$body    = $read ? (string) file_get_contents( 'php://input' ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			// No builder call uploads a file, and the compile opens the site's own wp-admin only.
			$elsewhere = ! empty( $_FILES ) || is_network_admin() || is_user_admin(); // phpcs:ignore WordPress.Security.NonceVerification
			if ( ! $elsewhere && self::admin_request_allowed( (string) $pagenow, $method, $get, $post, $page_id, $body ) ) {
				if ( null !== $call ) {
					self::drop_unsent_fields( $call );
				}
				$this->confined_user_id = get_current_user_id();
				$this->stripped_caps    = $ajax ? array_merge( self::STRIPPED_CAPS, self::AJAX_STRIPPED_CAPS ) : self::STRIPPED_CAPS;
				// map_meta_cap binds a multisite Super Admin, whose has_cap() returns before user_has_cap.
				add_filter( 'map_meta_cap', array( $this, 'map_caps' ), PHP_INT_MAX, 4 );
				add_filter( 'user_has_cap', array( $this, 'limit_caps' ), 10, 4 );
				return;
			}
		}
		// The action or page in the log tells a refused builder call from a probe.
		$what = isset( $_REQUEST['action'] ) ? $_REQUEST['action'] : ( isset( $_GET['page'] ) ? $_GET['page'] : '' ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		self::refuse( trim( (string) $pagenow . ' ' . $method . ' ' . ( is_string( $what ) ? sanitize_key( $what ) : '' ) ) );
	}

	/** pre_comment_on_post: never a comment from a builder session, whatever reached here. */
	public function refuse_comment() {
		if ( null !== self::builder_session() ) {
			self::refuse( 'wp-comments-post.php' );
		}
	}

	/**
	 * map_meta_cap, last, added only for an allowed builder request: a stripped cap, or a
	 * meta cap that maps to one (upload_plugins, customize, another user's edit_user), becomes
	 * do_not_allow. WP_User::has_cap() refuses do_not_allow even for a Super Admin.
	 */
	public function map_caps( $caps, $cap, $user_id, $args ) {
		if ( $this->confined_user_id > 0 && (int) $user_id === $this->confined_user_id
			&& array() !== array_intersect( array_merge( array( (string) $cap ), (array) $caps ), $this->stripped_caps ) ) {
			return array( 'do_not_allow' );
		}
		return $caps;
	}

	/** user_has_cap, added only for an allowed builder request: no code, plugin, theme or user caps. */
	public function limit_caps( $allcaps, $caps, $args, $user ) {
		if ( $this->confined_user_id > 0 && is_object( $user ) && isset( $user->ID ) && (int) $user->ID === $this->confined_user_id ) {
			foreach ( $this->stripped_caps as $cap ) {
				$allcaps[ $cap ] = false;
			}
		}
		return $allcaps;
	}

	private static function refuse( $where ) {
		self::end_session( $where );
		wp_die(
			esc_html__( 'This WPVibe builder session can only open the SeedProd builder, so it has been signed out.', 'vibe-ai' ),
			'',
			array( 'response' => 403 )
		);
	}

	/** rest_authentication_errors: the builder makes no REST calls, so a builder session gets none. */
	public function confine_rest( $result ) {
		if ( is_wp_error( $result ) || ! is_user_logged_in() ) {
			return $result;
		}
		if ( did_action( 'application_password_did_authenticate' ) || ( function_exists( 'rest_get_authenticated_app_password' ) && rest_get_authenticated_app_password() ) ) {
			return $result;
		}
		if ( null === self::builder_session() ) {
			return $result;
		}
		self::end_session( 'rest' );
		return new WP_Error(
			'wpvibe_builder_session',
			__( 'This WPVibe builder session can only open the SeedProd builder, so it has been signed out.', 'vibe-ai' ),
			array( 'status' => 403 )
		);
	}

	/** wp_ajax_heartbeat, before core's handler: a builder session's heartbeat runs core's auth check only. */
	public function confine_heartbeat() {
		if ( null === self::builder_session() ) {
			return;
		}
		remove_all_filters( 'heartbeat_received' );
		remove_all_filters( 'wp_refresh_nonces' );
		remove_all_filters( 'heartbeat_send' );
		remove_all_actions( 'heartbeat_tick' );
		add_filter( 'heartbeat_send', 'wp_auth_check' );
	}

	/** Destroy the builder session's tokens so a refused request also ends it. */
	private static function end_session( $where ) {
		if ( class_exists( 'WPVibe_Audit_Log' ) ) {
			WPVibe_Audit_Log::log_execution(
				array(
					'operation'      => 'builder_session_refused',
					'command'        => $where,
					'result_summary' => 'builder session signed out',
				)
			);
		}

		foreach ( self::builder_sessions() as $found ) {
			WP_Session_Tokens::get_instance( $found['user_id'] )->destroy( $found['token'] );
		}
		wp_clear_auth_cookie();
		wp_set_current_user( 0 );
	}

	/** Sign in for one page's builder: a marked session that lasts SESSION_TTL, not two days. */
	public static function open_session( $user_id, $page_id ) {
		$mark = static function ( $info ) use ( $page_id ) {
			$info[ self::SESSION_MARK ] = true;
			$info[ self::SESSION_PAGE ] = (int) $page_id;
			return $info;
		};
		$ttl  = static function () {
			return self::SESSION_TTL;
		};
		add_filter( 'attach_session_information', $mark );
		add_filter( 'auth_cookie_expiration', $ttl, PHP_INT_MAX );
		wp_set_auth_cookie( $user_id, false, is_ssl() );
		remove_filter( 'auth_cookie_expiration', $ttl, PHP_INT_MAX );
		remove_filter( 'attach_session_information', $mark );
	}

	public function maybe_consume() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Single-use token validated via hashed transient lookup in consume().
		if ( empty( $_GET[ self::QUERY_PARAM ] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token  = sanitize_text_field( wp_unslash( $_GET[ self::QUERY_PARAM ] ) );
		$result = $this->consume( $token );

		if ( is_wp_error( $result ) ) {
			// One generic message for every failure branch: no oracle for probing.
			wp_die(
				esc_html__( 'This WPVibe builder sign-in link is invalid or has expired. Links are single-use and expire after two minutes; request a fresh one.', 'vibe-ai' ),
				'',
				array( 'response' => 403 )
			);
		}

		wp_set_current_user( $result['user_id'] );
		// The link is minted with an application password, so the session it opens is
		// marked: it can open this page's builder and nothing else in wp-admin (#1084;
		// confine_admin(), confine_rest()), never the form that only a real browser login
		// may submit (security audit 10-20, #6; WPVibe_Approval_Bypass::is_cookie_session()).
		self::open_session( $result['user_id'], (int) $result['post_id'] );

		if ( class_exists( 'WPVibe_Audit_Log' ) ) {
			WPVibe_Audit_Log::log_execution(
				array(
					'operation'      => 'builder_login',
					'command'        => 'page_id=' . $result['post_id'],
					'result_summary' => 'builder session opened',
				)
			);
		}

		wp_safe_redirect( $result['redirect'] );
		exit;
	}
}
