<?php

defined( 'ABSPATH' ) || exit;

class WPVibe_Content_Files {

	const EXTENSIONS = array( 'log', 'php', 'css', 'js', 'json', 'txt', 'html' );
	const MAX_BYTES = 65536;
	const MAX_ENTRIES = 5000;
	const MAX_SECONDS = 2;

	private function error( $code, $message, $status = 403 ) {
		return new WP_Error( $code, $message, WPVibe_Error_Contract::data( 403 === $status ? 'security_gate' : 'filesystem', false, array( 'status' => $status ) ) );
	}

	private function resolve( $path, $directory = false ) {
		if ( ! is_string( $path ) || strlen( $path ) > 1024 || preg_match( '/[\x00-\x1f\x7f\\\\:]/', $path ) || ( '' !== $path && '/' === $path[0] ) ) {
			return $this->error( 'path_traversal', 'Use a relative path inside wp-content.' );
		}
		$parts = '' === $path ? array() : explode( '/', $path );
		foreach ( $parts as $part ) {
			if ( '' === $part || '.' === $part || '..' === $part || 'uploads' === strtolower( $part ) || 'wp-config.php' === strtolower( $part ) || '.' === $part[0] ) {
				return $this->error( 'forbidden_path', 'This path is excluded from wp-content reads.' );
			}
		}
		$base = realpath( WP_CONTENT_DIR );
		if ( false === $base ) {
			return $this->error( 'content_missing', 'The wp-content directory is unavailable.', 404 );
		}
		$full = $base;
		foreach ( $parts as $part ) {
			$full .= '/' . $part;
			if ( is_link( $full ) ) {
				return $this->error( 'forbidden_path', 'Symbolic links are excluded from wp-content reads.' );
			}
		}
		$real = realpath( $full );
		if ( false === $real ) {
			return $this->error( 'not_found', 'The requested wp-content path was not found.', 404 );
		}
		if ( $real !== $base && strpos( $real, $base . '/' ) !== 0 ) {
			return $this->error( 'path_traversal', 'The resolved path is outside wp-content.' );
		}
		$uploads = wp_get_upload_dir();
		$upload_dir = isset( $uploads['basedir'] ) ? realpath( $uploads['basedir'] ) : false;
		if ( $upload_dir && ( $real === $upload_dir || strpos( $real, $upload_dir . '/' ) === 0 ) ) {
			return $this->error( 'forbidden_path', 'Uploads are excluded from wp-content reads.' );
		}
		if ( $directory ) {
			return is_dir( $real ) && is_readable( $real ) ? $real : $this->error( 'not_directory', 'The requested directory is not readable.', 400 );
		}
		if ( ! in_array( strtolower( pathinfo( $real, PATHINFO_EXTENSION ) ), self::EXTENSIONS, true ) ) {
			return $this->error( 'forbidden_ext', 'Allowed wp-content file types: .log, .php, .css, .js, .json, .txt, .html.' );
		}
		$stat = lstat( $real );
		if ( ! is_file( $real ) || ! is_readable( $real ) || ! $stat || $stat['nlink'] > 1 ) {
			return $this->error( 'forbidden_path', 'Only readable regular files without hard links are supported.' );
		}
		return $real;
	}

	// Before WP 6.9, wp_check_invalid_utf8( $text, true ) hands a bad byte to iconv, which returns false with a notice; a log with one latin-1 byte would read as empty.
	private static function scrub_utf8( $text ) {
		if ( '' === $text || 1 === @preg_match( '/^./us', $text ) ) {
			return $text;
		}
		if ( function_exists( 'wp_scrub_utf8' ) ) {
			return (string) wp_scrub_utf8( $text );
		}
		if ( function_exists( 'mb_convert_encoding' ) ) {
			return (string) mb_convert_encoding( $text, 'UTF-8', 'UTF-8' );
		}
		if ( function_exists( 'iconv' ) ) {
			$out = @iconv( 'UTF-8', 'UTF-8//IGNORE', $text );
			if ( is_string( $out ) ) {
				return $out;
			}
		}
		return preg_replace( '/[\x80-\xFF]+/', '?', $text );
	}

	public static function redact( $content ) {
		$keys = '(?:[a-z0-9_]*?(?:password|passwd|pwd|secret|token|api[_-]?key|access[_-]?key|private[_-]?key|authorization|cookie|db[_-]?(?:user|name|host))|[a-z0-9_]*_salt|(?:auth|secure_auth|logged_in|nonce)_key)';
		$patterns = array(
			// Keep quote context until redaction is done; line slicing comes afterwards.
			'~(\bdefine\s*\(\s*[\'\"]' . $keys . '[\'\"]\s*,\s*)([\'\"])(?:\\\\.|(?!\2)[\s\S])*?(?:\2|\z)~i' => '$1\'[REDACTED]\'',
			'~([\'\"]?' . $keys . '[\'\"]?\s*(?:=>|[:=])\s*)([\'\"])(?:\\\\.|(?!\2)[\s\S])*?(?:\2|\z)~i' => '$1\'[REDACTED]\'',
			'~(\b' . $keys . '\b[\'\"]?\s*(?:=>|[:=])\s*)[^,;&<>\r\n]+~i' => '$1[REDACTED]',
			'~\b(?:Authorization|Proxy-Authorization|Cookie|Set-Cookie)\s*:[^\r\n]*~i' => '[REDACTED HEADER]',
			'~\b(?:Bearer|Basic)\s+[a-z0-9+/_.=\~-]+~i' => '[REDACTED AUTH]',
			'~-----BEGIN [^-]*PRIVATE KEY-----[\s\S]*?(?:-----END [^-]*PRIVATE KEY-----|\z)~' => '[REDACTED PRIVATE KEY]',
			'~[a-z][a-z0-9+.-]*://[^\s/@:]+:[^\s/@]+@~i' => '[REDACTED CREDENTIALS]@',
			'~[a-z0-9.!#$%&\'*+/=?^_`{|}\~-]+@[a-z0-9.-]+\.[a-z]{2,}~i' => '[REDACTED EMAIL]',
			'~\b(?:sk|pk|rk)_(?:live_|test_|proj_)?[a-z0-9_-]{12,}\b|\b(?:gh[pousr]_|github_pat_)[a-z0-9_]{12,}\b|\bAKIA[A-Z0-9]{16}\b~i' => '[REDACTED KEY]',
			'~\beyJ[a-z0-9_-]+\.[a-z0-9_-]+\.[a-z0-9_-]+\b~i' => '[REDACTED TOKEN]',
		);
		foreach ( $patterns as $pattern => $replacement ) {
			$content = preg_replace( $pattern, $replacement, $content );
			if ( null === $content ) {
				return null;
			}
		}
		return $content;
	}

	public function read( $path, $start_line = null, $end_line = null ) {
		$full = $this->resolve( $path );
		if ( is_wp_error( $full ) ) {
			return $full;
		}
		$is_log = 'log' === strtolower( pathinfo( $full, PATHINFO_EXTENSION ) );
		if ( $is_log && ( null !== $start_line || null !== $end_line ) ) {
			return $this->error( 'invalid_range', 'Logs return a bounded tail. Omit start_line and end_line.', 400 );
		}
		$handle = @fopen( $full, 'rb' );
		if ( false === $handle ) {
			return $this->error( 'read_failed', 'The file could not be opened.', 500 );
		}
		try {
			$stat = fstat( $handle );
			clearstatcache( true, $full );
			$rechecked = $this->resolve( $path );
			$path_stat = is_wp_error( $rechecked ) ? false : lstat( $rechecked );
			if ( ! $stat || ! $path_stat || $rechecked !== $full || $stat['dev'] !== $path_stat['dev'] || $stat['ino'] !== $path_stat['ino'] || ( $stat['mode'] & 0170000 ) !== 0100000 || $stat['nlink'] > 1 ) {
				return $this->error( 'forbidden_path', 'Only regular files without hard links are supported.' );
			}
			$size = $stat['size'];
			$truncated = $size > self::MAX_BYTES;
			// Source is all-or-nothing so a range cannot lose preceding secret-key context.
			if ( ! $is_log && $truncated ) {
				return $this->error( 'file_too_large', 'This source file exceeds the 64 KiB wp-content read limit.', 413 );
			}
			$offset = $is_log ? max( 0, $size - self::MAX_BYTES ) : 0;
			if ( 0 !== fseek( $handle, $offset ) ) {
				return $this->error( 'read_failed', 'The file could not be positioned for reading.', 500 );
			}
			$content = stream_get_contents( $handle, self::MAX_BYTES );
			if ( false === $content ) {
				return $this->error( 'read_failed', 'The file could not be read.', 500 );
			}
		} finally {
			fclose( $handle );
		}
		if ( $offset > 0 ) {
			// A tail may begin inside a multiline secret: start at a new timestamped record.
			$record = '/^(?:\[(?:\d{2}-[A-Za-z]{3}-\d{4}|\d{4}-\d{2}-\d{2})[^\]\r\n]*\]|\d{4}-\d{2}-\d{2}[T ][0-9:]+)/m';
			$content = preg_match( $record, $content, $match, PREG_OFFSET_CAPTURE ) ? substr( $content, $match[0][1] ) : '';
		}
		$content = self::scrub_utf8( $content );
		$clean = self::redact( $content );
		if ( null === $clean ) {
			return $this->error( 'redaction_failed', 'The file could not be safely redacted.', 500 );
		}
		$redacted = $content !== $clean;
		$lines = explode( "\n", $clean );
		$start = null === $start_line ? 1 : (int) $start_line;
		$end = null === $end_line ? count( $lines ) : (int) $end_line;
		if ( $start < 1 || $end < $start ) {
			return $this->error( 'invalid_range', 'Use a positive start_line and an end_line at or after it.', 400 );
		}
		$clean = implode( "\n", array_slice( $lines, $start - 1, $end - $start + 1 ) );
		$recorded = WPVibe_Audit_Log::log_execution( array(
			'operation' => 'content_file_read',
			'command' => 'read_file',
			'params' => array( 'scope' => 'wp-content', 'path' => $path, 'start_line' => $start_line, 'end_line' => $end_line ),
			'result_summary' => sprintf( 'Read %d bytes; %s; redacted=%s', strlen( $clean ), $is_log ? 'log tail' : 'source', $redacted ? 'yes' : 'no' ),
		) );
		if ( ! $recorded ) {
			return $this->error( 'audit_failed', 'The read could not be recorded in the audit log. No file content was returned.', 500 );
		}
		return rest_ensure_response( array(
			'path' => $path, 'scope' => 'wp-content', 'editable' => false, 'content' => $clean,
			'redacted' => $redacted, 'truncated' => $truncated, 'tail' => $is_log,
			'file_bytes' => $size, 'max_bytes' => self::MAX_BYTES,
			'notice' => $truncated && '' === $clean ? 'No complete timestamped log record was found in the bounded tail.' : null,
			'start_line' => $start, 'end_line' => min( $end, count( $lines ) ),
			'total_lines' => count( $lines ), 'line_basis' => $is_log ? 'returned_tail' : 'redacted_file',
		) );
	}

	public function list_files( $pattern = null, $directory = '' ) {
		$full = $this->resolve( $directory, true );
		if ( is_wp_error( $full ) ) {
			return $full;
		}
		$stack = array( array( $full, $directory ) );
		$files = array();
		$visited = 0;
		$truncated = false;
		$deadline = microtime( true ) + self::MAX_SECONDS;
		try {
			while ( $stack ) {
				list( $dir, $relative_dir ) = array_pop( $stack );
				foreach ( new DirectoryIterator( $dir ) as $item ) {
					if ( ++$visited > self::MAX_ENTRIES || microtime( true ) > $deadline ) {
						$truncated = true;
						break 2;
					}
					if ( $item->isDot() || $item->isLink() ) {
						continue;
					}
					$path = ( '' === $relative_dir ? '' : $relative_dir . '/' ) . $item->getFilename();
					$is_dir = $item->isDir();
					if ( is_wp_error( $this->resolve( $path, $is_dir ) ) ) {
						continue;
					}
					if ( $is_dir ) {
						$stack[] = array( $item->getPathname(), $path );
					} elseif ( ! $pattern || fnmatch( $pattern, $path, FNM_PATHNAME | FNM_CASEFOLD ) ) {
						$files[] = array( 'path' => $path, 'type' => 'file', 'size' => $item->getSize(), 'extension' => strtolower( $item->getExtension() ) );
					}
				}
			}
		} catch ( UnexpectedValueException $e ) {
			return $this->error( 'list_failed', 'A wp-content directory could not be read. Narrow the directory and retry.', 500 );
		}
		usort( $files, function ( $a, $b ) { return strcmp( $a['path'], $b['path'] ); } );
		if ( ! WPVibe_Audit_Log::log_execution( array(
			'operation' => 'content_file_list',
			'command' => 'list_files',
			'params' => array( 'scope' => 'wp-content', 'directory' => $directory, 'pattern' => $pattern ),
			'result_summary' => sprintf( 'Listed %d files; truncated=%s', count( $files ), $truncated ? 'yes' : 'no' ),
		) ) ) {
			return $this->error( 'audit_failed', 'The listing could not be recorded in the audit log. No listing was returned.', 500 );
		}
		return rest_ensure_response( array( 'scope' => 'wp-content', 'editable' => false, 'files' => $files, 'total_files' => count( $files ), 'truncated' => $truncated ) );
	}
}
