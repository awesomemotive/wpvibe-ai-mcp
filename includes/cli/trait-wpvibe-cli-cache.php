<?php
/**
 * WP-CLI emulator: cache type/flush/purge and multi-engine purge targets.
 *
 * Extracted from class-wpvibe-cli.php (mechanical split; no behavior change).
 */

defined( 'ABSPATH' ) || exit;

trait WPVibe_CLI_Cache {


	private function handle_cache_type( $positional, $flags ) {
		return $this->success_result( array(
			'object_cache' => wp_using_ext_object_cache() ? 'external' : 'default',
			'drop_in'      => file_exists( WP_CONTENT_DIR . '/object-cache.php' ),
		) );
	}


	/**
	 * Detector table for the unified cache purge: each entry knows whether its
	 * plugin is present and how to call its own purge API ("own the brains,
	 * rent the muscle" — we orchestrate, never reimplement).
	 *
	 * Closures return true on success, false on generic failure, or a string
	 * (a failure reason shown to the caller). `purge_url` is optional; engines
	 * without it fall back to their flush-all `purge` in URL mode.
	 *
	 * Array order is the purge order and is load-bearing: origin caches first,
	 * Cloudflare (the CDN) last, so the CDN cannot re-cache stale origin HTML
	 * mid-sweep.
	 */
	private function cache_purge_targets() {
		return array(
			'litespeed'      => array(
				'name'      => 'LiteSpeed Cache',
				'active'    => defined( 'LSCWP_V' ) || is_plugin_active( 'litespeed-cache/litespeed-cache.php' ),
				'purge'     => function () {
					do_action( 'litespeed_purge_all' );
					return true;
				},
				'purge_url' => function ( $url ) {
					do_action( 'litespeed_purge_url', $url );
					return true;
				},
			),
			'elementor'      => array(
				'name'   => 'Elementor CSS cache',
				'active' => class_exists( '\Elementor\Plugin' ),
				'purge'  => function () {
					if ( isset( \Elementor\Plugin::$instance->files_manager ) ) {
						\Elementor\Plugin::$instance->files_manager->clear_cache();
						return true;
					}
					return false;
				},
			),
			'wp-rocket'      => array(
				'name'      => 'WP Rocket',
				'active'    => function_exists( 'rocket_clean_domain' ),
				'purge'     => function () {
					rocket_clean_domain();
					return true;
				},
				'purge_url' => function ( $url ) {
					if ( ! function_exists( 'rocket_clean_files' ) ) {
						return false;
					}
					rocket_clean_files( array( $url ) );
					return true;
				},
			),
			'sg-optimizer'   => array(
				'name'      => 'SG Optimizer (Speed Optimizer)',
				'active'    => function_exists( 'sg_cachepress_purge_cache' ),
				'purge'     => function () {
					return false !== sg_cachepress_purge_cache();
				},
				'purge_url' => function ( $url ) {
					// Same function as flush-all; URL-scoped when passed a URL.
					return false !== sg_cachepress_purge_cache( $url );
				},
			),
			'wp-super-cache' => array(
				'name'      => 'WP Super Cache',
				'active'    => function_exists( 'wp_cache_clear_cache' ),
				'purge'     => function () {
					wp_cache_clear_cache();
					return true;
				},
				'purge_url' => function ( $url ) {
					if ( ! function_exists( 'wpsc_delete_url_cache' ) ) {
						return false;
					}
					// wpsc_delete_url_cache() cannot purge the homepage (returns
					// false for the root path) — full flush is the safe stand-in.
					$path = (string) wp_parse_url( $url, PHP_URL_PATH );
					if ( '' === trim( $path, '/' ) ) {
						wp_cache_clear_cache();
						return true;
					}
					if ( false !== strpos( $url, '?' ) ) {
						return __( 'WP Super Cache cannot purge URLs with query strings.', 'vibe-ai' );
					}
					return false !== wpsc_delete_url_cache( $url );
				},
			),
			'w3-total-cache' => array(
				'name'      => 'W3 Total Cache',
				'active'    => function_exists( 'w3tc_flush_all' ),
				'purge'     => function () {
					w3tc_flush_all();
					return true;
				},
				'purge_url' => function ( $url ) {
					if ( ! function_exists( 'w3tc_flush_url' ) ) {
						return false;
					}
					w3tc_flush_url( $url );
					return true;
				},
			),
			'breeze'         => array(
				'name'   => 'Breeze',
				'active' => class_exists( 'Breeze_PurgeCache' ) || is_plugin_active( 'breeze/breeze.php' ),
				'purge'  => function () {
					do_action( 'breeze_clear_all_cache' );
					return true;
				},
			),
			'cloudflare'     => array(
				'name'   => 'Cloudflare',
				'active' => class_exists( '\CF\WordPress\Hooks' ) || is_plugin_active( 'cloudflare/cloudflare.php' ),
				'purge'  => function () {
					if ( ! class_exists( '\CF\WordPress\Hooks' ) ) {
						return false;
					}
					$hooks = new \CF\WordPress\Hooks();
					if ( ! method_exists( $hooks, 'purgeCacheEverything' ) ) {
						return false;
					}
					// purgeCacheEverything() returns void and silently no-ops unless
					// the plugin's Automatic Cache Management or APO toggle is on
					// (off by default) — reporting success there would be a lie.
					$gates     = array( 'isPluginSpecificCacheEnabled', 'isAutomaticPlatformOptimizationEnabled' );
					$checkable = false;
					$enabled   = false;
					foreach ( $gates as $gate ) {
						if ( is_callable( array( $hooks, $gate ) ) ) {
							$checkable = true;
							if ( $hooks->{$gate}() ) {
								$enabled = true;
								break;
							}
						}
					}
					if ( $checkable && ! $enabled ) {
						return __( 'the Cloudflare plugin is installed but its Automatic Cache Management setting is off, so it refuses purge requests. Enable it in Settings > Cloudflare, or purge from the Cloudflare dashboard.', 'vibe-ai' );
					}
					$hooks->purgeCacheEverything();
					return true;
				},
			),
		);
	}



	/** Flush-all across every detected engine + the object cache. Public for the draft-theme publish path. */
	public function purge_all_caches() {
		return $this->handle_cache_purge( array(), array() );
	}


	/** Success is `true` exactly; a string is a failure reason, false is generic failure. */
	private function run_purge_closure( $closure, ...$args ) {
		$ok = call_user_func( $closure, ...$args );
		if ( true === $ok ) {
			return true;
		}
		return is_string( $ok ) ? $ok : __( 'purge API unavailable', 'vibe-ai' );
	}


	private function handle_cache_purge( $positional, $flags ) {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$targets = $this->cache_purge_targets();

		// --url must be --url=<url>[,...]. Bare --url (boolean true) or empty
		// --url= used to fall through to a full purge with exit 0 — the same
		// silent-wrong-success class FlagFidelity polices.
		$urls = array();
		if ( array_key_exists( 'url', $flags ) ) {
			if ( ! is_string( $flags['url'] ) || '' === trim( $flags['url'] ) ) {
				return $this->error_result( __( 'Use --url=<url>[,<url>...] with at least one absolute http(s) URL. Bare --url or an empty value is not supported.', 'vibe-ai' ) );
			}
			$urls = array_values( array_filter( array_map( 'trim', wp_parse_list( $flags['url'] ) ) ) );
			if ( empty( $urls ) ) {
				return $this->error_result( __( 'Use --url=<url>[,<url>...] with at least one absolute http(s) URL. Bare --url or an empty value is not supported.', 'vibe-ai' ) );
			}
			foreach ( $urls as $url ) {
				$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
				if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
					/* translators: %s: rejected URL */
					return $this->error_result( sprintf( __( 'URL-scoped purge only accepts http(s) URLs; refused: %s', 'vibe-ai' ), $url ) );
				}
			}
		}
		$skip = array();
		if ( ! empty( $flags['skip'] ) && is_string( $flags['skip'] ) ) {
			$skip = array_values( array_filter( wp_parse_list( $flags['skip'] ) ) );
		}

		// Third-party alias spellings purge only the plugin they name.
		$alias = self::CACHE_PURGE_ALIASES[ $this->current_command ] ?? null;
		if ( null !== $alias ) {
			$target = $targets[ $alias ];
			if ( ! $target['active'] ) {
				return $this->error_result(
					sprintf(
						/* translators: %s: cache plugin name */
						__( '%s is not active on this site. Run `cache purge` to purge whatever cache is installed, or `cache flush` for the object cache.', 'vibe-ai' ),
						$target['name']
					)
				);
			}
			// Surgical --url= on an engine with no URL API must not silent full-flush.
			if ( $urls && empty( $target['purge_url'] ) ) {
				return $this->error_result(
					sprintf(
						/* translators: %s: cache plugin name */
						__( '%s does not support URL-scoped purge. Omit --url for a full purge of that engine, or use `cache purge --url=...` to hit page caches that support it.', 'vibe-ai' ),
						$target['name']
					)
				);
			}
			try {
				if ( $urls && ! empty( $target['purge_url'] ) ) {
					foreach ( $urls as $url ) {
						$ok = $this->run_purge_closure( $target['purge_url'], $url );
						if ( true !== $ok ) {
							/* translators: 1: cache plugin name, 2: URL, 3: reason */
							return $this->error_result( sprintf( __( 'Purging %1$s for %2$s failed: %3$s', 'vibe-ai' ), $target['name'], $url, $ok ) );
						}
					}
					WPVibe_Change_Tracker::mark( array( 'summary' => "Cache purged: {$target['name']}", 'action_label' => 'View Site', 'url' => home_url( '/' ) ) );
					return $this->success_result( array(
						/* translators: 1: cache plugin name, 2: URL count */
						'message' => sprintf( __( '%1$s cache purged for %2$d URL(s).', 'vibe-ai' ), $target['name'], count( $urls ) ),
						'urls'    => $urls,
					) );
				}
				$ok = $this->run_purge_closure( $target['purge'] );
			} catch ( \Throwable $e ) {
				/* translators: 1: cache plugin name, 2: error message */
				return $this->error_result( sprintf( __( 'Purging %1$s failed: %2$s', 'vibe-ai' ), $target['name'], $e->getMessage() ) );
			}
			if ( true !== $ok ) {
				/* translators: 1: cache plugin name, 2: reason */
				return $this->error_result( sprintf( __( 'Purging %1$s failed: %2$s', 'vibe-ai' ), $target['name'], $ok ) );
			}
			if ( 'elementor' === $alias && ! empty( $flags['regenerate'] ) ) {
				if ( ! class_exists( '\Elementor\Plugin' ) || ! isset( \Elementor\Plugin::$instance->files_manager ) ) {
					return $this->error_result( __( 'Elementor CSS cache purged, but Elementor is not loaded so CSS could not be regenerated.', 'vibe-ai' ) );
				}
				try {
					\Elementor\Plugin::$instance->files_manager->generate_css();
				} catch ( \Throwable $e ) {
					/* translators: %s: error message */
					return $this->error_result( sprintf( __( 'Elementor CSS cache purged, but regeneration failed: %s', 'vibe-ai' ), $e->getMessage() ) );
				}
				WPVibe_Change_Tracker::mark( array( 'summary' => 'Elementor CSS purged and regenerated', 'action_label' => 'View Site', 'url' => home_url( '/' ) ) );
				return $this->success_result( array( 'message' => __( 'Elementor CSS cache purged and regenerated.', 'vibe-ai' ) ) );
			}
			WPVibe_Change_Tracker::mark( array( 'summary' => "Cache purged: {$target['name']}", 'action_label' => 'View Site', 'url' => home_url( '/' ) ) );
			/* translators: %s: cache plugin name */
			return $this->success_result( array( 'message' => sprintf( __( '%s cache purged.', 'vibe-ai' ), $target['name'] ) ) );
		}

		// `cache purge`: sweep every detected cache plugin (+ the object cache
		// unless --skip=object). With --url=, page caches purge just those URLs;
		// engines with no URL API fall back to their full flush (over-purging is
		// safe, staleness is not). Elementor CSS and the object cache are not
		// page caches and are left alone in URL mode.
		$purged  = array();
		$failed  = array();
		$skipped = array();
		foreach ( $targets as $id => $target ) {
			if ( ! $target['active'] ) {
				continue;
			}
			if ( in_array( $id, $skip, true ) ) {
				$skipped[] = $target['name'];
				continue;
			}
			if ( $urls && 'elementor' === $id ) {
				continue;
			}
			try {
				if ( $urls ) {
					if ( ! empty( $target['purge_url'] ) ) {
						$ok = true;
						foreach ( $urls as $url ) {
							$ok = $this->run_purge_closure( $target['purge_url'], $url );
							if ( true !== $ok ) {
								break;
							}
						}
						if ( true === $ok ) {
							$purged[] = $target['name'];
						} else {
							$failed[] = array( 'name' => $target['name'], 'error' => $ok );
						}
						continue;
					}
					$ok = $this->run_purge_closure( $target['purge'] );
					if ( true === $ok ) {
						$purged[] = $target['name'] . ' (no URL purge API; flushed fully)';
					} else {
						$failed[] = array( 'name' => $target['name'], 'error' => $ok );
					}
					continue;
				}
				$ok = $this->run_purge_closure( $target['purge'] );
			} catch ( \Throwable $e ) {
				$failed[] = array( 'name' => $target['name'], 'error' => $e->getMessage() );
				continue;
			}
			if ( true === $ok ) {
				$purged[] = $target['name'];
			} else {
				$failed[] = array( 'name' => $target['name'], 'error' => $ok );
			}
		}
		$object_cache_flushed = false;
		if ( ! $urls && ! in_array( 'object', $skip, true ) ) {
			$object_cache_flushed = (bool) wp_cache_flush();
		}

		if ( $urls ) {
			if ( $purged ) {
				/* translators: 1: plugin names, 2: URL count */
				$message = sprintf( __( 'Purged %1$s for %2$d URL(s).', 'vibe-ai' ), implode( ', ', $purged ), count( $urls ) );
			} elseif ( $failed ) {
				$detail = array();
				foreach ( $failed as $f ) {
					$detail[] = $f['name'] . ': ' . $f['error'];
				}
				/* translators: %s: per-engine failure reasons */
				return $this->error_result( sprintf( __( 'URL purge failed for every detected page cache. %s', 'vibe-ai' ), implode( '; ', $detail ) ) );
			} else {
				$message = __( 'No page-cache plugin detected; nothing to purge for those URLs.', 'vibe-ai' );
			}
		} elseif ( $purged ) {
			/* translators: %s: plugin names */
			$message = $object_cache_flushed
				? sprintf( __( 'Purged: %s. Object cache flushed.', 'vibe-ai' ), implode( ', ', $purged ) )
				: sprintf( __( 'Purged: %s.', 'vibe-ai' ), implode( ', ', $purged ) );
		} elseif ( $object_cache_flushed ) {
			$message = __( 'No known cache plugin detected; flushed the WordPress object cache.', 'vibe-ai' );
		} else {
			$message = __( 'Nothing purged (no cache plugin detected, object cache skipped).', 'vibe-ai' );
		}

		WPVibe_Change_Tracker::mark( array( 'summary' => 'Caches purged', 'action_label' => 'View Site', 'url' => home_url( '/' ) ) );

		$data = array(
			'message'              => $message,
			'purged'               => $purged,
			'object_cache_flushed' => $object_cache_flushed,
		);
		if ( $urls ) {
			$data['urls'] = $urls;
		}
		if ( $skipped ) {
			$data['skipped'] = $skipped;
		}
		if ( $failed ) {
			$data['failed'] = $failed;
		}
		return $this->success_result( $data );
	}


	private function handle_cache_flush( $positional, $flags ) {
		$ok = wp_cache_flush();
		if ( false === $ok ) {
			return $this->error_result(
				__( 'wp_cache_flush() returned false. A persistent object cache backend (Redis, Memcached, etc.) may be disconnected or misconfigured.', 'vibe-ai' )
			);
		}
		WPVibe_Change_Tracker::mark( array(
			'summary'      => 'Cache flushed',
			'action_label' => 'Refresh',
		) );
		return $this->success_result( array( 'message' => __( 'Object cache flushed.', 'vibe-ai' ) ) );
	}

}
