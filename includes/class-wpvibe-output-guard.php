<?php
/**
 * Keeps WPVibe REST bodies clean JSON when site code prints during a request.
 *
 * Output printed while a /wpvibe/v1/ callback runs (a failing query with
 * $wpdb->show_errors on, a builder rendering CSS on save_post, a PHP notice)
 * is withheld and reported in a stray_output field instead of landing in front
 * of the JSON. Output printed after the response is served (shutdown hooks) is
 * discarded, since the body is already complete. Nothing is ever lost: more
 * than KEEP_BYTES of noise, a fatal error, or exit() inside a callback sends
 * the output through exactly as it would without the guard.
 */

defined( 'ABSPATH' ) || exit;

class WPVibe_Output_Guard {

	const ROUTE_PREFIX  = '/wpvibe/v1/';
	const EXCERPT_BYTES = 500;
	const KEEP_BYTES    = 65536;
	const CHUNK_BYTES   = 8192;

	private static $instance = null;

	private $request  = null;
	private $level    = 0;
	private $captured = '';
	private $released = false;
	private $armed    = false;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'rest_request_before_callbacks', array( $this, 'start' ), 0, 3 );
		add_filter( 'rest_request_after_callbacks', array( $this, 'finish' ), PHP_INT_MAX, 3 );
		add_filter( 'rest_pre_serve_request', array( $this, 'arm_trailing_discard' ), 0, 4 );
	}

	public static function is_own_route( $request ) {
		if ( ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) {
			return false;
		}
		$route = '/' . ltrim( (string) $request->get_route(), '/' );
		return 0 === stripos( rtrim( $route, '/' ) . '/', self::ROUTE_PREFIX );
	}

	/**
	 * Capture buffer handler. Chunks written during the callback are withheld, up to KEEP_BYTES.
	 * Past that, or once finish() lets go, or when the buffer ends any other way (a fatal,
	 * exit(), another plugin flushing every buffer), it hands everything back like a plain buffer.
	 */
	public function capture( $buffer, $phase ) {
		if ( $phase & PHP_OUTPUT_HANDLER_CLEAN ) {
			return '';
		}
		if ( ! $this->released && ! ( $phase & PHP_OUTPUT_HANDLER_FINAL ) && strlen( $this->captured ) + strlen( $buffer ) <= self::KEEP_BYTES ) {
			$this->captured .= $buffer;
			return '';
		}
		$this->released = true;
		$out            = $this->captured . $buffer;
		$this->captured = '';
		return $out;
	}

	/** Output after the response is served; a fatal in a shutdown hook still shows, as before. */
	public function discard( $buffer, $phase ) {
		return self::fatal_pending() ? $buffer : '';
	}

	private static function fatal_pending() {
		$error = error_get_last();
		return is_array( $error ) && ( $error['type'] & ( E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR ) );
	}

	public function start( $response, $handler, $request ) {
		// Nested dispatches of our routes report into the outer request's capture.
		if ( null !== $this->request || ! self::is_own_route( $request ) ) {
			return $response;
		}
		// An earlier capture left under a foreign buffer still holds output it must hand back.
		if ( in_array( __CLASS__ . '::capture', ob_list_handlers(), true ) ) {
			return $response;
		}
		if ( ob_start( array( $this, 'capture' ), self::CHUNK_BYTES ) ) {
			$this->request  = $request;
			$this->level    = ob_get_level();
			$this->captured = '';
			$this->released = false;
		}
		return $response;
	}

	public function finish( $response, $handler, $request ) {
		if ( null === $this->request || $request !== $this->request ) {
			return $response;
		}
		$level         = $this->level;
		$this->request = null;
		$this->level   = 0;

		// A buffer below ours means someone flushed every buffer mid-callback and ours already let go.
		if ( ob_get_level() < $level ) {
			return $response;
		}
		// A buffer above ours belongs to code that may still close it: leave the stack, and let the
		// JSON echoed later pass through ours untouched.
		if ( ob_get_level() !== $level || __CLASS__ . '::capture' !== $this->top_handler() ) {
			$this->released = true;
			return $response;
		}
		if ( $this->released ) {
			ob_end_flush();
			return $response;
		}
		$this->captured .= (string) ob_get_contents();
		ob_end_clean();

		return $this->attach( $response );
	}

	/** After the body is echoed, a shutdown hook that prints would append to it. */
	public function arm_trailing_discard( $served, $result = null, $request = null, $server = null ) {
		if ( ! $this->armed && self::is_own_route( $request ) ) {
			$this->armed = true;
			add_action( 'shutdown', array( $this, 'open_discard' ), PHP_INT_MIN );
			// Core's wp_ob_end_flush_all() runs on shutdown at priority 1 and ends every buffer.
			add_action( 'shutdown', array( $this, 'open_discard' ), 1 );
		}
		return $served;
	}

	public function open_discard() {
		if ( __CLASS__ . '::discard' !== $this->top_handler() ) {
			ob_start( array( $this, 'discard' ), self::CHUNK_BYTES );
		}
	}

	private function top_handler() {
		$handlers = ob_list_handlers();
		return empty( $handlers ) ? '' : (string) end( $handlers );
	}

	private function attach( $response ) {
		if ( '' === $this->captured ) {
			return $response;
		}
		$note = array(
			'bytes'   => strlen( $this->captured ),
			'excerpt' => self::excerpt( $this->captured ),
		);
		$this->captured = '';

		if ( is_wp_error( $response ) ) {
			$data = $response->get_error_data();
			if ( is_array( $data ) || null === $data || '' === $data ) {
				$response->add_data( array_merge( (array) $data, array( 'stray_output' => $note ) ) );
			}
			return $response;
		}
		if ( is_object( $response ) && method_exists( $response, 'get_data' ) && method_exists( $response, 'set_data' ) ) {
			$data = $response->get_data();
			if ( self::is_map( $data ) ) {
				$data['stray_output'] = $note;
				$response->set_data( $data );
			}
			return $response;
		}
		if ( self::is_map( $response ) ) {
			$response['stray_output'] = $note;
		}
		return $response;
	}

	private static function is_map( $value ) {
		return is_array( $value ) && ! empty( $value ) && array_keys( $value ) !== range( 0, count( $value ) - 1 );
	}

	/** Readable text of what was printed: tags and style/script bodies dropped, whitespace collapsed. */
	public static function excerpt( $raw ) {
		$text = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $raw ) ) );
		if ( '' === $text ) {
			$text = trim( (string) preg_replace( '/\s+/', ' ', (string) $raw ) );
		}
		if ( strlen( $text ) > self::EXCERPT_BYTES ) {
			$text = function_exists( 'mb_strcut' ) ? mb_strcut( $text, 0, self::EXCERPT_BYTES, 'UTF-8' ) : substr( $text, 0, self::EXCERPT_BYTES );
		}
		if ( function_exists( 'wp_check_invalid_utf8' ) ) {
			$text = wp_check_invalid_utf8( $text, true );
		}
		return $text;
	}
}
