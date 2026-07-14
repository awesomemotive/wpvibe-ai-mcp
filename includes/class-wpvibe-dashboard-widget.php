<?php
/**
 * WPVibe dashboard widget.
 *
 * One state-driven widget on the wp-admin Dashboard: connect CTA for
 * not-connected sites, recent AI activity + cookbook recipe suggestions for
 * connected ones. Recipes come from a remote feed and are filtered locally
 * against the site's installed stack — nothing about the site leaves the
 * site (same posture as core's Events and News widget).
 */

defined( 'ABSPATH' ) || exit;

class WPVibe_Dashboard_Widget {

	const FEED_URL             = 'https://wpvibe.ai/wp-json/wpvibe/v1/widget-feed';
	const CHATGPT_APP_URL      = 'https://chatgpt.com/apps/wpvibe/asdk_app_6a244fb509e481918985fee76373b0f9';
	const FEED_TRANSIENT       = 'wpvibe_widget_feed';
	const FEED_ERROR_TRANSIENT = 'wpvibe_widget_feed_error';
	const FEED_TTL             = 43200; // 12 hours.
	const FEED_ERROR_TTL       = 3600;  // Negative cache: don't hammer a down endpoint.
	const RECIPE_COUNT         = 3;
	const PROMPT_MAX_CHARS     = 2000;

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_dashboard_setup', array( $this, 'register_widget' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function register_widget() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_add_dashboard_widget( 'wpvibe_dashboard', __( 'WPVibe', 'vibe-ai' ), array( $this, 'render' ) );
	}

	public function enqueue_assets( $hook ) {
		if ( 'index.php' !== $hook || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$css_path = WPVIBE_PLUGIN_DIR . 'assets/css/dashboard-widget.css';
		$js_path  = WPVIBE_PLUGIN_DIR . 'assets/js/admin.js';

		wp_enqueue_style(
			'vibe-ai-dashboard-widget',
			WPVIBE_PLUGIN_URL . 'assets/css/dashboard-widget.css',
			array(),
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : WPVIBE_VERSION
		);

		// Shared delegated copy-button handler (data-wpvibe-copy).
		wp_enqueue_script(
			'vibe-ai-admin',
			WPVIBE_PLUGIN_URL . 'assets/js/admin.js',
			array(),
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : WPVIBE_VERSION,
			true
		);
	}

	public function render() {
		if ( $this->is_connected() ) {
			$this->render_connected();
		} else {
			$this->render_disconnected();
		}
	}

	/**
	 * Same rule as WPVibe_Admin::is_connected() — that method is private, and
	 * the 30-day window must stay in lockstep with the admin page.
	 */
	private function is_connected() {
		$last_active = (int) get_option( 'wpvibe_last_active', 0 );
		return $last_active > 0 && ( time() - $last_active ) < 30 * DAY_IN_SECONDS;
	}

	private function render_disconnected() {
		$connect_cta = $this->utm( 'https://mcp.wpvibe.ai/?connect=' . rawurlencode( site_url() ), 'widget_cta_connect', 'cta' );
		$ai_prompt   = sprintf(
			/* translators: %s: site URL */
			__( 'Connect my site at %s', 'vibe-ai' ),
			site_url()
		);
		?>
		<div class="wpvibe-widget">
			<p class="wpvibe-widget-pitch"><?php esc_html_e( 'Manage this site by chatting with your AI. Nothing to learn, just ask.', 'vibe-ai' ); ?></p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $connect_cta ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Connect your AI', 'vibe-ai' ); ?></a>
			</p>
			<p class="wpvibe-widget-micro">
				<?php esc_html_e( 'Using ChatGPT?', 'vibe-ai' ); ?>
				<a href="<?php echo esc_url( self::CHATGPT_APP_URL ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Add WPVibe with one click', 'vibe-ai' ); ?></a>.
			</p>
			<div class="wpvibe-widget-copy-row">
				<span class="wpvibe-widget-copy-hint"><?php esc_html_e( 'Or paste into an AI that has WPVibe:', 'vibe-ai' ); ?></span>
				<code><?php echo esc_html( $ai_prompt ); ?></code>
				<button type="button" class="button button-small wpvibe-widget-copy" data-wpvibe-copy="<?php echo esc_attr( $ai_prompt ); ?>"><?php esc_html_e( 'Copy', 'vibe-ai' ); ?></button>
			</div>
			<?php $this->render_recipes( __( 'What people build with it', 'vibe-ai' ), false ); ?>
			<?php $this->render_footer( false ); ?>
		</div>
		<?php
	}

	private function render_connected() {
		$last_active = (int) get_option( 'wpvibe_last_active', 0 );
		$activity    = $this->recent_activity();
		// Recently-active + empty buffer is NOT idleness: the buffer is new in 1.9.0
		// and only writes on changes, so read-heavy sites live here permanently.
		// Re-engagement framing is reserved for genuinely stale connections.
		$stale = ( time() - $last_active ) > 7 * DAY_IN_SECONDS;
		?>
		<div class="wpvibe-widget">
			<p class="wpvibe-widget-status">
				<span class="wpvibe-widget-dot" aria-hidden="true"></span>
				<?php
				printf(
					/* translators: %s: human-readable time difference, e.g. "2 hours" */
					esc_html__( 'Connected · active %s ago', 'vibe-ai' ),
					esc_html( human_time_diff( $last_active ) )
				);
				?>
			</p>
			<?php if ( ! empty( $activity ) ) : ?>
				<h3 class="wpvibe-widget-heading"><?php esc_html_e( 'Recent activity', 'vibe-ai' ); ?></h3>
				<ul class="wpvibe-widget-activity">
					<?php foreach ( $activity as $item ) : ?>
						<li>
							<span class="wpvibe-widget-activity-summary"><?php echo esc_html( $item['summary'] ); ?></span>
							<span class="wpvibe-widget-activity-time"><?php echo esc_html( human_time_diff( $item['ts'] ) ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
				<?php $this->render_recipes( __( 'Recipes picked for this site', 'vibe-ai' ) ); ?>
			<?php elseif ( $stale ) : ?>
				<?php $this->render_recipes( __( 'Try this with your AI', 'vibe-ai' ) ); ?>
			<?php else : ?>
				<?php // Labeled empty state so "here" has a referent — otherwise the note reads as a subtitle for the recipes heading below it. ?>
				<h3 class="wpvibe-widget-heading"><?php esc_html_e( 'Recent activity', 'vibe-ai' ); ?></h3>
				<p class="wpvibe-widget-note"><?php esc_html_e( 'Changes your AI makes will show up here.', 'vibe-ai' ); ?></p>
				<?php $this->render_recipes( __( 'Recipes picked for this site', 'vibe-ai' ) ); ?>
			<?php endif; ?>
			<?php $this->render_footer( true ); ?>
		</div>
		<?php
	}

	/**
	 * Last 3 activity entries, newest first, scoped to well-formed rows.
	 */
	private function recent_activity() {
		$rows = get_option( 'wpvibe_recent_activity', array() );
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$rows = array_values( array_filter( $rows, function ( $r ) {
			return is_array( $r ) && ! empty( $r['summary'] ) && ! empty( $r['ts'] );
		} ) );
		return array_slice( array_reverse( $rows ), 0, 3 );
	}

	/**
	 * Recipes section. Hides silently when the feed is unavailable.
	 * $with_actions: copy buttons + microcopy (connected states); off in the
	 * disconnected state, where recipes are motivation content only.
	 */
	private function render_recipes( $heading, $with_actions = true ) {
		$recipes = $this->pick_recipes();
		if ( empty( $recipes ) ) {
			return;
		}
		?>
		<h3 class="wpvibe-widget-heading"><?php echo esc_html( $heading ); ?></h3>
		<ul class="wpvibe-widget-recipes">
			<?php foreach ( $recipes as $recipe ) : ?>
				<li>
					<span class="wpvibe-widget-recipe-title">
						<a href="<?php echo esc_url( $this->utm( $recipe['url'], 'widget_recipe', 'widget' ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $recipe['short_title'] ); ?></a>
						<?php if ( '' !== $recipe['badge'] ) : ?>
							<span class="wpvibe-widget-badge"><?php echo esc_html( $recipe['badge'] ); ?></span>
						<?php endif; ?>
					</span>
					<?php if ( $with_actions && '' !== $recipe['prompt'] ) : ?>
						<button type="button" class="button button-small wpvibe-widget-copy" data-wpvibe-copy="<?php echo esc_attr( $recipe['prompt'] ); ?>"><?php esc_html_e( 'Copy prompt', 'vibe-ai' ); ?></button>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php if ( $with_actions ) : ?>
			<p class="wpvibe-widget-micro"><?php esc_html_e( 'Prompts copy with the recipe link. Fill the [bracketed] parts, or let your AI ask.', 'vibe-ai' ); ?></p>
			<p class="wpvibe-widget-micro"><a href="<?php echo esc_url( $this->utm( 'https://wpvibe.ai/cookbook/', 'widget_recipes_all', 'widget' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Browse all recipes →', 'vibe-ai' ); ?></a></p>
		<?php endif; ?>
		<?php
	}

	private function render_footer( $connected ) {
		$links = array();
		if ( $connected ) {
			$links[] = '<a href="' . esc_url( admin_url( 'admin.php?page=vibe-ai-activity' ) ) . '">' . esc_html__( 'Approval log', 'vibe-ai' ) . '</a>';
		}
		$links[] = '<a href="' . esc_url( $this->utm( 'https://wpvibe.ai/docs/', 'widget_footer_docs', 'widget' ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Docs', 'vibe-ai' ) . '</a>';
		$links[] = '<a href="' . esc_url( $this->utm( 'https://wpvibe.ai/support/', 'widget_footer_help', 'widget' ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Help', 'vibe-ai' ) . '</a>';
		echo '<p class="wpvibe-widget-footer">' . implode( ' · ', $links ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- links escaped above.
	}

	/**
	 * Up to RECIPE_COUNT recipes: stack-matched first (max 2), generic fill,
	 * both rotated deterministically by day so the set changes without
	 * randomness. Returns sanitized rows ready to render.
	 */
	private function pick_recipes() {
		$feed = $this->get_feed();
		if ( null === $feed || empty( $feed['recipes'] ) ) {
			return array();
		}

		$detect  = isset( $feed['detect'] ) && is_array( $feed['detect'] ) ? $feed['detect'] : array();
		$stack   = $this->detect_stack( $detect );
		$matched = array();
		$generic = array();
		foreach ( $feed['recipes'] as $recipe ) {
			$clean = $this->sanitize_recipe( $recipe );
			if ( null === $clean ) {
				continue;
			}
			$hits = array_values( array_intersect( $clean['builders'], $stack ) );
			if ( empty( $clean['builders'] ) ) {
				$clean['badge'] = '';
				$generic[]      = $clean;
			} elseif ( ! empty( $hits ) ) {
				$clean['badge'] = $this->stack_label( $detect, $hits[0] );
				$matched[]      = $clean;
			}
			// Tagged recipes for plugins this site doesn't run are dropped.
		}

		$day   = (int) gmdate( 'z' );
		$picks = $this->rotate_slice( $matched, $day, min( 2, self::RECIPE_COUNT ) );
		$picks = array_merge( $picks, $this->rotate_slice( $generic, $day, self::RECIPE_COUNT - count( $picks ) ) );
		return $picks;
	}

	private function rotate_slice( $items, $offset, $count ) {
		$total = count( $items );
		if ( 0 === $total || $count <= 0 ) {
			return array();
		}
		$out = array();
		for ( $i = 0; $i < min( $count, $total ); $i++ ) {
			$out[] = $items[ ( $offset + $i ) % $total ];
		}
		return $out;
	}

	/**
	 * The feed is remote input even though we own it: reject anything that
	 * isn't shaped right or that links off wpvibe.ai.
	 */
	private function sanitize_recipe( $recipe ) {
		if ( ! is_array( $recipe ) || empty( $recipe['title'] ) || empty( $recipe['url'] ) ) {
			return null;
		}
		$host = wp_parse_url( (string) $recipe['url'], PHP_URL_HOST );
		if ( 'wpvibe.ai' !== $host || 0 !== strpos( (string) $recipe['url'], 'https://' ) ) {
			return null;
		}
		$builders = array();
		if ( isset( $recipe['builders'] ) && is_array( $recipe['builders'] ) ) {
			foreach ( $recipe['builders'] as $b ) {
				if ( is_string( $b ) && '' !== $b ) {
					$builders[] = sanitize_key( $b );
				}
			}
		}
		$title = sanitize_text_field( (string) $recipe['title'] );
		$short = isset( $recipe['short_title'] ) && is_string( $recipe['short_title'] ) ? sanitize_text_field( $recipe['short_title'] ) : '';
		return array(
			'title'       => $title,
			'short_title' => '' !== $short ? $short : $title,
			'url'         => (string) $recipe['url'],
			'builders'    => $builders,
			'prompt'      => isset( $recipe['prompt'] ) && is_string( $recipe['prompt'] ) ? substr( sanitize_textarea_field( $recipe['prompt'] ), 0, self::PROMPT_MAX_CHARS ) : '',
		);
	}

	/**
	 * Display label for a stack id, from the feed's detect map — the plugin
	 * itself knows no plugin names.
	 */
	private function stack_label( $detect, $id ) {
		if ( isset( $detect[ $id ]['label'] ) && is_string( $detect[ $id ]['label'] ) ) {
			return sanitize_text_field( $detect[ $id ]['label'] );
		}
		return ucwords( str_replace( '-', ' ', sanitize_key( $id ) ) );
	}

	/**
	 * Evaluate the feed's detect map against locally active plugins and the
	 * active theme. The map ships in the feed so the vocabulary can grow
	 * without a plugin release; this method never learns plugin names.
	 */
	private function detect_stack( $map ) {
		$dirs = array();
		foreach ( (array) get_option( 'active_plugins', array() ) as $path ) {
			$dirs[ strtolower( strtok( (string) $path, '/' ) ) ] = true;
		}
		if ( is_multisite() ) {
			foreach ( array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) as $path ) {
				$dirs[ strtolower( strtok( (string) $path, '/' ) ) ] = true;
			}
		}
		$theme = strtolower( get_stylesheet() );

		$stack = array();
		foreach ( $map as $id => $matcher ) {
			if ( ! is_array( $matcher ) ) {
				continue;
			}
			if ( $this->matcher_hits( $matcher, $dirs, $theme ) ) {
				$stack[] = sanitize_key( $id );
			}
		}
		return $stack;
	}

	private function matcher_hits( $matcher, $dirs, $theme ) {
		foreach ( (array) ( isset( $matcher['dirs'] ) ? $matcher['dirs'] : array() ) as $dir ) {
			if ( isset( $dirs[ strtolower( (string) $dir ) ] ) ) {
				return true;
			}
		}
		foreach ( (array) ( isset( $matcher['dirPrefixes'] ) ? $matcher['dirPrefixes'] : array() ) as $prefix ) {
			$prefix = strtolower( (string) $prefix );
			if ( '' === $prefix ) {
				continue;
			}
			foreach ( array_keys( $dirs ) as $dir ) {
				if ( 0 === strpos( $dir, $prefix ) ) {
					return true;
				}
			}
		}
		if ( '' !== $theme ) {
			foreach ( (array) ( isset( $matcher['themes'] ) ? $matcher['themes'] : array() ) as $t ) {
				if ( strtolower( (string) $t ) === $theme ) {
					return true;
				}
			}
			foreach ( (array) ( isset( $matcher['themePrefixes'] ) ? $matcher['themePrefixes'] : array() ) as $prefix ) {
				$prefix = strtolower( (string) $prefix );
				if ( '' !== $prefix && 0 === strpos( $theme, $prefix ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Cached feed fetch: 12h transient on success, 1h negative cache on any
	 * failure. Fetch happens inline on dashboard load only when expired.
	 */
	private function get_feed() {
		$cached = get_transient( self::FEED_TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		if ( false !== get_transient( self::FEED_ERROR_TRANSIENT ) ) {
			return null;
		}

		$response = wp_remote_get(
			self::FEED_URL,
			array(
				'timeout'    => 3,
				// WP's default UA embeds the site URL; this feed needs nothing identifying.
				'user-agent' => 'WPVibe/' . WPVIBE_VERSION,
			)
		);
		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$feed = ( ! is_wp_error( $response ) && 200 === $code && '' !== $body ) ? json_decode( $body, true ) : null;

		if ( ! is_array( $feed ) || ! isset( $feed['v'] ) || 1 !== (int) $feed['v'] || ! isset( $feed['recipes'] ) || ! is_array( $feed['recipes'] ) ) {
			set_transient( self::FEED_ERROR_TRANSIENT, 1, self::FEED_ERROR_TTL );
			return null;
		}

		set_transient( self::FEED_TRANSIENT, $feed, self::FEED_TTL );
		return $feed;
	}

	/**
	 * Same UTM convention as WPVibe_Admin::utm(), widget surface.
	 */
	private function utm( $url, $content, $medium = 'widget' ) {
		$sep = ( false === strpos( $url, '?' ) ) ? '?' : '&';
		return $url . $sep . http_build_query( array(
			'utm_source'   => 'wpadmin',
			'utm_medium'   => $medium,
			'utm_campaign' => 'plugin_admin',
			'utm_content'  => $content,
		) );
	}
}
