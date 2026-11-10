<?php
/**
 * What broke a failing site and whose code was running (#1337): the last PHP fatals with their owner,
 * memory checkpoints and an environment snapshot. Names and numbers only: no stack traces, arguments,
 * request data or stored values. WPVibe reads it with its own signature, so it works when signed-in requests fail.
 */
defined( 'ABSPATH' ) || exit;

class WPVibe_Diagnostics {
	const OPTION  = 'wpvibe_diagnostics_fatals';
	const ROUTE   = '/wpvibe/v1/diagnostics';
	const SUBJECT = 'diagnostics-v1';
	const KEEP    = 5;
	const MAX_AGE = 7 * DAY_IN_SECONDS;
	const FATALS  = array( E_ERROR => 'E_ERROR', E_PARSE => 'E_PARSE', E_CORE_ERROR => 'E_CORE_ERROR', E_COMPILE_ERROR => 'E_COMPILE_ERROR', E_USER_ERROR => 'E_USER_ERROR', E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR' );

	private static $after_plugins_loaded = null;

	// PHP's own messages name functions, classes, types and sizes, never values. Any other message (a plugin's own
	// trigger_error or throw, an exception) is kept as its type or class only: it can hold whatever the code put in it.
	const ENGINE_MESSAGES = array(
		'/^Call to undefined (?:function|method) [\w\\\\:]+\(\)$/',
		'/^Call to a member function \w+\(\) on (?:null|bool|array|string|int|float)$/',
		'/^Call to (?:private|protected) method [\w\\\\:]+\(\) from (?:global scope|scope [\w\\\\]+)$/',
		'/^(?:Class|Interface|Trait|Enum) ["\']?[\w\\\\]+["\']? not found$/',
		'/^Undefined constant ["\']?[\w\\\\:]+["\']?$/',
		'/^Allowed memory size of \d+ bytes exhausted(?: at \S+)? \(tried to allocate \d+ bytes\)$/',
		'/^Out of memory \(allocated \d+\)(?: at \S+)? \(tried to allocate \d+ bytes\)$/',
		'/^Maximum execution time of \d+ seconds? exceeded$/',
		'/^Maximum function nesting level of \'?\d+\'? reached, aborting!$/',
		'/^Cannot redeclare [\w\\\\:]+\(\)(?: \(previously declared in \S+\))?$/',
		'/^Cannot declare (?:class|interface|trait) [\w\\\\]+, because the name is already in use$/',
		'/^[\w\\\\:]+\(\): Argument #\d+ \(\$\w+\) must be of type [\w\\\\|?]+, [\w\\\\]+ given(?:, called in \S+ on line \d+(?: and defined)?)?$/',
		'/^[\w\\\\:]+\(\): Return value must be of type [\w\\\\|?]+, [\w\\\\]+ returned$/',
		'/^Too few arguments to function [\w\\\\:]+\(\), \d+ passed(?: in \S+ on line \d+)? and (?:at least|exactly) \d+ expected$/',
		'/^Unsupported operand types: [\w\\\\]+ [-+*\/%.] [\w\\\\]+$/',
		'/^Object of class [\w\\\\]+ could not be converted to string$/',
		'/^Cannot access (?:private|protected) property [\w\\\\:$]+$/',
		'/^Failed opening required \S+$/',
		'/^syntax error, unexpected [\w-]+(?: "[^"]{0,40}")?(?:, expecting [^"\']+)?$/',
	);

	protected static function verify_proof( $request ) { return WPVibe_Op_Proof::verify( $request, self::ROUTE, self::SUBJECT ); }

	// Registered when the plugin file loads: a fatal while an earlier plugin (alphabetically before vibe-ai) or a
	// mu-plugin is still loading happens before this runs, so it is not recorded.
	public static function register() {
		register_shutdown_function( array( __CLASS__, 'on_shutdown' ) );
		// Core's error page filter records the fatal too, in case a php-error.php drop-in or another shutdown function exits first.
		add_filter( 'wp_php_error_message', array( __CLASS__, 'on_error_page' ), 1, 2 );
		add_action( 'plugins_loaded', array( __CLASS__, 'mark_plugins_loaded' ), PHP_INT_MAX );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function mark_plugins_loaded() {
		self::$after_plugins_loaded = memory_get_usage();
	}

	public static function routes() {
		register_rest_route( 'wpvibe/v1', '/diagnostics', array(
			'methods' => 'POST', 'callback' => array( __CLASS__, 'read' ), 'permission_callback' => array( __CLASS__, 'authorize' ),
		) );
	}

	// The Worker's signature is the only credential: this route exists for sites whose Application Password requests fail.
	public static function authorize( $request ) {
		return static::verify_proof( $request );
	}

	public static function read( $request ) {
		$allowed = static::authorize( $request );
		if ( true !== $allowed ) {
			return $allowed;
		}
		return array(
			'fatals'      => self::recent(),
			'memory'      => array( 'limit' => (string) ini_get( 'memory_limit' ), 'after_plugins_loaded' => self::$after_plugins_loaded, 'peak' => memory_get_peak_usage() ),
			'environment' => self::environment(),
		);
	}

	public static function on_shutdown() {
		self::capture( error_get_last() );
	}

	public static function on_error_page( $message, $error ) {
		self::capture( $error );
		return $message;
	}

	private static function capture( $error ) {
		// The site's own limit is what gets recorded, read before any raise below.
		$before = (string) ini_get( 'memory_limit' );
		// Out of memory, saving the record needs a little room of its own; the limit only ever goes up.
		$limit = wp_convert_hr_to_bytes( $before );
		$room  = memory_get_usage( true ) + 8 * 1024 * 1024;
		if ( is_array( $error ) && false !== strpos( (string) ( $error['message'] ?? '' ), 'Allowed memory size' ) && $limit > 0 && $room > $limit ) {
			@ini_set( 'memory_limit', (string) $room ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		self::record( $error, $before );
	}

	public static function record( $error, $memory_limit = null ) {
		$types = self::FATALS;
		if ( ! is_array( $error ) || ! isset( $types[ $error['type'] ?? 0 ] ) ) {
			return;
		}
		try {
			$file    = (string) ( $error['file'] ?? '' );
			$message = self::message( (string) ( $error['message'] ?? '' ), $types[ $error['type'] ] );
			$line    = (int) ( $error['line'] ?? 0 );
			$minutes = 1;
			$fatals  = array();
			// A fatal on every request is one entry, written at most once a minute: `minutes` counts the minutes it was seen in.
			foreach ( self::recent() as $fatal ) {
				if ( $fatal['message'] === $message && $fatal['file'] === self::relative( $file ) && $fatal['line'] === $line ) {
					if ( (int) $fatal['at'] > time() - MINUTE_IN_SECONDS ) {
						return;
					}
					$minutes += (int) ( $fatal['minutes'] ?? 1 );
					continue;
				}
				$fatals[] = $fatal;
			}
			array_unshift( $fatals, array(
				'type'                 => $types[ $error['type'] ],
				'message'              => $message,
				'file'                 => self::relative( $file ),
				'line'                 => $line,
				'owner'                => self::owner( $file ),
				'at'                   => time(),
				'minutes'              => $minutes,
				'peak_memory'          => memory_get_peak_usage(),
				'after_plugins_loaded' => self::$after_plugins_loaded,
				'memory_limit'         => null !== $memory_limit ? $memory_limit : (string) ini_get( 'memory_limit' ),
			) );
			update_option( self::OPTION, array_slice( $fatals, 0, self::KEEP ), false );
		} catch ( Throwable $e ) {
			// A diagnostic must never add a second failure to a request that already died.
			unset( $e );
		}
	}

	public static function recent() {
		$fatals = get_option( self::OPTION, array() );
		return array_values( array_filter( is_array( $fatals ) ? $fatals : array(), function ( $fatal ) {
			return is_array( $fatal ) && (int) ( $fatal['at'] ?? 0 ) > time() - self::MAX_AGE;
		} ) );
	}

	// The first line only (PHP's stack trace carries call arguments), and only when it is one of PHP's own messages.
	private static function message( $message, $type ) {
		$message = (string) strtok( substr( $message, 0, 2000 ), "\n" );
		$message = preg_replace( '/^PHP /', '', $message );
		$prefix  = '';
		if ( preg_match( '/^Uncaught ([\w\\\\]+)(?:: (.*))?$/', $message, $m ) ) {
			// An exception's message is whatever the throwing code put in it; PHP raises only Error and its subclasses itself.
			$engine = class_exists( $m[1], false ) && ( 'error' === strtolower( ltrim( $m[1], '\\' ) ) || is_subclass_of( $m[1], 'Error' ) );
			if ( ! $engine || ! isset( $m[2] ) ) {
				return 'Uncaught ' . $m[1];
			}
			$prefix  = 'Uncaught ' . $m[1] . ': ';
			$message = $m[2];
		}
		$message = preg_replace( '/ in \/\S+:\d+$/', '', $message );
		$message = preg_replace( "/ \\(include_path='[^']*'\\)$/", '', $message );
		$known   = 'E_USER_ERROR' !== $type;
		if ( $known ) {
			$known = false;
			foreach ( self::ENGINE_MESSAGES as $pattern ) {
				$known = $known || 1 === preg_match( $pattern, $message );
			}
		}
		if ( ! $known ) {
			return '' !== $prefix ? rtrim( $prefix, ': ' ) : $type;
		}
		$message = preg_replace_callback( '#(?<=^|[\s\'"(])/[^\s\'"(),:]+#', function ( $m ) { return self::relative( $m[0] ); }, $message );
		$message = $prefix . $message;
		return function_exists( 'mb_substr' ) ? mb_substr( $message, 0, 200 ) : substr( $message, 0, 200 );
	}

	private static function theme_root() {
		return function_exists( 'get_theme_root' ) ? get_theme_root() : WP_CONTENT_DIR . '/themes';
	}

	// Relative to the folder it came from, never the server path; a file outside WordPress shows its name alone.
	public static function relative( $file ) {
		$file = wp_normalize_path( $file );
		// WordPress.com (Atomic) keeps core and its managed plugins and themes outside ABSPATH, by version, and PHP reports resolved paths.
		if ( preg_match( '#^/wordpress/core/[^/]+/(.+)$#', $file, $m ) ) {
			return $m[1];
		}
		if ( preg_match( '#^/wordpress/(plugins|themes)/([^/]+)/[^/]+/(.+)$#', $file, $m ) ) {
			return $m[1] . '/' . $m[2] . '/' . $m[3];
		}
		foreach ( array( WP_PLUGIN_DIR => 'plugins/', WPMU_PLUGIN_DIR => 'mu-plugins/', self::theme_root() => 'themes/', ABSPATH => '' ) as $root => $label ) {
			foreach ( array_unique( array( $root, (string) realpath( $root ) ) ) as $path ) {
				$path = trailingslashit( wp_normalize_path( $path ) );
				if ( '/' !== $path && 0 === strpos( $file, $path ) ) {
					return $label . substr( $file, strlen( $path ) );
				}
			}
		}
		return basename( $file );
	}

	public static function owner( $file ) {
		$relative = self::relative( $file );
		if ( preg_match( '#^(plugins|themes)/([^/]+)/#', $relative, $m ) ) {
			return ( 'plugins' === $m[1] ? 'plugin:' : 'theme:' ) . $m[2];
		}
		if ( preg_match( '#^mu-plugins/([^/]+)#', $relative, $m ) ) {
			return 'mu-plugin:' . $m[1];
		}
		return preg_match( '#^(wp-includes|wp-admin)/#', $relative ) ? 'core' : 'other';
	}

	private static function environment() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$installed = get_plugins();
		$active    = array_merge( (array) get_option( 'active_plugins', array() ), is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) : array() );
		$plugins   = array();
		foreach ( array_unique( $active ) as $basename ) {
			$plugins[ dirname( $basename ) === '.' ? basename( $basename, '.php' ) : dirname( $basename ) ] = (string) ( $installed[ $basename ]['Version'] ?? '' );
		}
		$edge = WPVibe_Connection_Check::edge_owner();
		return array(
			'wordpress'     => get_bloginfo( 'version' ),
			'php'           => PHP_VERSION,
			'wpvibe'        => WPVIBE_VERSION,
			'host'          => defined( 'IS_ATOMIC' ) || defined( 'ATOMIC_SITE_ID' ) ? 'WordPress.com (Atomic)' : ( 'host' === $edge['owner'] ? $edge['name'] : '' ),
			'plugins'       => $plugins,
			// Paused plugins exist only inside a recovery-mode session, which a signed request never has. WordPress
			// emails the admin a recovery link when a fatal would pause a plugin, and keeps when it last did.
			'recovery_email_at' => (int) get_option( 'recovery_mode_email_last_sent', 0 ) ?: null,
		);
	}

	// A row for the admin page's connection report.
	public static function check_row() {
		$fatals = self::recent();
		if ( ! $fatals ) {
			return array( 'stage' => 'PHP fatal errors', 'status' => 'passed', 'summary' => __( 'No PHP fatal errors recorded on this site in the last 7 days.', 'vibe-ai' ) );
		}
		$last = $fatals[0];
		/* translators: 1: time ago, 2: plugin or theme, 3: error message, 4: file and line */
		$summary = sprintf( __( 'WordPress stopped with a PHP fatal error in the last 7 days. The latest, %1$s ago, came from %2$s: %3$s (%4$s).', 'vibe-ai' ), human_time_diff( (int) $last['at'], time() ), $last['owner'], $last['message'], $last['file'] . ':' . $last['line'] );
		return array( 'stage' => 'PHP fatal errors', 'status' => 'limited', 'summary' => $summary, 'next' => __( 'If WPVibe requests fail with a critical error, this is the likely cause: update or deactivate that plugin or theme, or include this report when contacting support@wpvibe.ai.', 'vibe-ai' ) );
	}
}
