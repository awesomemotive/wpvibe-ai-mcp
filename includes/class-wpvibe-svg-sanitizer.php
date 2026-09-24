<?php
/**
 * SVG sanitizer for draft-theme writes and upload_media (issue #543).
 *
 * An SVG served from the site's own domain is a stored-XSS vector (script,
 * on* handlers, javascript: links, foreignObject HTML), so every SVG WPVibe
 * writes goes through two passes and only the cleaned markup is stored:
 *
 * 1. enshrined/svg-sanitize (the library behind the Safe SVG plugin), vendored
 *    under third-party/svg-sanitize at 1.0.0 with its namespace prefixed to
 *    WPVibe\Vendor\enshrined\svgSanitize so it cannot clash with Safe SVG or
 *    any other plugin loading the same library. To update it: copy the new
 *    release's src/ (without svg-scanner.php) and LICENSE, re-run the prefix
 *    rewrite on `namespace`/`use`/`\enshrined` and re-add the ABSPATH guard.
 * 2. A strict WPVibe pass over the library output: it removes any script-capable
 *    element, on* attribute, href/src that is not a local #fragment or an inline
 *    raster data:image, and CSS that can fetch or execute. It is a backstop that
 *    holds even if the library's allowlists loosen in a future release.
 *
 * Input that cannot be parsed, declares entities, is over the size limit, or has
 * nothing drawable left after cleaning is refused, never stored as-is.
 */

defined( 'ABSPATH' ) || exit;

class WPVibe_SVG_Sanitizer {

	/** Largest SVG accepted, in bytes. Logos and icons are a few KB. */
	const MAX_BYTES = 524288;

	/** Elements that can run script, embed documents, or rewrite links; removed wherever they appear. */
	const FORBIDDEN_ELEMENTS = array( 'script', 'foreignobject', 'iframe', 'frame', 'object', 'embed', 'applet', 'handler', 'listener', 'set', 'animate', 'audio', 'video', 'canvas', 'meta', 'link', 'base', 'form', 'input', 'button', 'textarea', 'select' );

	/** Elements that may carry an inline raster data:image href. */
	const RASTER_HREF_ELEMENTS = array( 'image', 'feimage', 'pattern' );

	/** Elements that draw nothing on their own. */
	const NON_DRAWING_ELEMENTS = array( 'title', 'desc', 'metadata', 'style', 'defs' );

	const SVG_NS = 'http://www.w3.org/2000/svg';

	const RASTER_DATA_URI = '#^data:image/(png|jpe?g|gif|webp);base64,[a-z0-9+/]+=*$#i';

	/**
	 * Sanitize SVG markup.
	 *
	 * @param mixed $dirty Raw SVG markup.
	 * @return array{content:string,removed:int,changed:bool}|WP_Error
	 */
	public static function sanitize( $dirty ) {
		if ( ! is_string( $dirty ) || '' === trim( $dirty ) ) {
			return self::refuse( __( 'The SVG is empty.', 'vibe-ai' ) );
		}
		if ( strlen( $dirty ) > self::MAX_BYTES ) {
			/* translators: %d: size limit in KB */
			return self::refuse( sprintf( __( 'The SVG is larger than the %d KB limit for SVG files.', 'vibe-ai' ), (int) ( self::MAX_BYTES / 1024 ) ) );
		}
		$input = $dirty;
		if ( 0 === strncmp( $input, "\xEF\xBB\xBF", 3 ) ) {
			$input = substr( $input, 3 );
		}
		// UTF-16/32 would hide markup from the byte-level checks below; SVG on the web is UTF-8.
		if ( false !== strpos( $input, "\0" ) ) {
			return self::refuse( __( 'The SVG is not UTF-8 text.', 'vibe-ai' ) );
		}
		if ( ! preg_match( '//u', $input ) ) {
			return self::refuse( __( 'The SVG is not UTF-8 text.', 'vibe-ai' ) );
		}
		// A declared non-UTF-8 encoding (UTF-7, UTF-16...) makes libxml decode markup the byte checks never saw.
		// Checked anywhere, not just at the start: stripping PHP tags or a DOCTYPE can move a declaration to the front.
		preg_match_all( '/<\?xml\b[^>]*?\bencoding\s*=\s*["\']?([^"\'\s?>]*)/i', $input, $encodings );
		foreach ( $encodings[1] as $encoding ) {
			if ( ! in_array( strtolower( $encoding ), array( 'utf-8', 'utf8', 'us-ascii', 'ascii' ), true ) ) {
				return self::refuse( __( 'The SVG declares an encoding other than UTF-8.', 'vibe-ai' ) );
			}
		}
		// Entity declarations are how XXE and billion-laughs attacks start; no logo needs one.
		if ( false !== stripos( $input, '<!ENTITY' ) ) {
			return self::refuse( __( 'The SVG declares XML entities (<!ENTITY>), which WPVibe does not accept in SVG files.', 'vibe-ai' ) );
		}
		$declaration = self::declaration( $input );

		self::load_library();
		// Keep the author's whitespace and layout (the library pretty-prints by
		// default), so a clean file is stored byte-for-byte and edit_file matches.
		$library = new class() extends \WPVibe\Vendor\enshrined\svgSanitize\Sanitizer {
			protected function resetInternal() {
				parent::resetInternal();
				$this->xmlDocument->preserveWhiteSpace = true;
				$this->xmlDocument->formatOutput       = false;
			}
		};
		$library->removeRemoteReferences( true );
		$library->removeXMLTag( true );
		// Keep self-closing tags as written so later edit_file calls still match.
		$library->setXMLOptions( 0 );
		try {
			$clean = $library->sanitize( $input );
		} catch ( \WPVibe\Vendor\enshrined\svgSanitize\Exceptions\NestingException $e ) {
			return self::refuse( __( 'Its <use> references nest too deeply or loop.', 'vibe-ai' ) );
		} catch ( \Throwable $e ) {
			return self::refuse( __( 'It could not be processed as a single SVG document.', 'vibe-ai' ) );
		}
		if ( ! is_string( $clean ) || '' === trim( $clean ) ) {
			return self::refuse( __( 'The SVG is not well-formed XML, so it could not be checked.', 'vibe-ai' ) );
		}
		// Comments are dropped too, but they are not unsafe content, so they are not counted.
		$removed = 0;
		foreach ( (array) $library->getXmlIssues() as $issue ) {
			if ( false === strpos( (string) ( $issue['message'] ?? '' ), '#comment' ) ) {
				++$removed;
			}
		}
		// The library strips PHP tags before parsing without logging them.
		$removed += preg_match_all( '/<\?(=|php)/i', $input );

		$doc      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		// No LIBXML_NOENT / DTDLOAD: nothing is substituted or fetched.
		$loaded = $doc->loadXML( $clean, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $loaded || ! $doc->documentElement ) {
			return self::refuse( __( 'The SVG is not well-formed XML, so it could not be checked.', 'vibe-ai' ) );
		}
		$root = $doc->documentElement;
		if ( 'svg' !== strtolower( (string) $root->localName ) || ! self::is_svg_namespace( $root ) ) {
			/* translators: %s: root element name */
			return self::refuse( sprintf( __( 'The file is not an SVG image: its root element is <%s>, not <svg>.', 'vibe-ai' ), $root->localName ) );
		}
		foreach ( iterator_to_array( $doc->childNodes, false ) as $node ) {
			if ( $node !== $root ) {
				$doc->removeChild( $node );
				if ( ! ( $node instanceof DOMComment ) ) {
					++$removed;
				}
			}
		}

		$removed += self::enforce( $root );

		if ( ! self::has_drawable( $root ) ) {
			return self::refuse( __( 'Nothing drawable is left in the SVG after removing unsafe content.', 'vibe-ai' ) );
		}

		$out = $doc->saveXML( $root );
		if ( ! is_string( $out ) || '' === trim( $out ) ) {
			return self::refuse( __( 'The cleaned SVG could not be serialized.', 'vibe-ai' ) );
		}
		$out = $declaration . rtrim( $out ) . "\n";

		return array(
			'content' => $out,
			'removed' => $removed,
			'changed' => rtrim( str_replace( "\r\n", "\n", $dirty ) ) !== rtrim( $out ),
		);
	}

	/**
	 * One sentence for a tool response describing what sanitizing did.
	 *
	 * @param array  $result  sanitize() result.
	 * @param string $context 'file' for draft-theme writes, 'media' for upload_media.
	 * @return string Empty when there is nothing worth reporting.
	 */
	public static function summary( $result, $context = 'file' ) {
		$media = 'media' === $context;
		if ( ! empty( $result['removed'] ) ) {
			$n = (int) $result['removed'];
			return $media
				/* translators: %d: number of removed elements or attributes */
				? sprintf( _n( 'Sanitized: removed %d unsafe element or attribute (script, event handler, external or script link) before adding the SVG to the Media Library.', 'Sanitized: removed %d unsafe elements or attributes (script, event handlers, external or script links) before adding the SVG to the Media Library.', $n, 'vibe-ai' ), $n )
				/* translators: %d: number of removed elements or attributes */
				: sprintf( _n( 'Sanitized: removed %d unsafe element or attribute (script, event handler, external or script link). The stored file differs from what was sent; read it back before using edit_file on it.', 'Sanitized: removed %d unsafe elements or attributes (script, event handlers, external or script links). The stored file differs from what was sent; read it back before using edit_file on it.', $n, 'vibe-ai' ), $n );
		}
		if ( ! empty( $result['changed'] ) && ! $media ) {
			return __( 'Sanitized: nothing unsafe was found, but the markup was re-serialized (for example quoting or namespace declarations), so read the file back before using edit_file on it.', 'vibe-ai' );
		}
		return '';
	}

	/**
	 * Whether bytes look like an SVG document (for upload_media type detection).
	 *
	 * @param string $bytes File contents or a prefix of them.
	 * @return bool
	 */
	public static function looks_like_svg( $bytes ) {
		// Root element must be <svg>: only a BOM, XML declaration, comments and a DOCTYPE may come first.
		return is_string( $bytes ) && (bool) preg_match( '/^(?:\xEF\xBB\xBF)?\s*(?:(?:<\?.*?\?>|<!--.*?-->|<!DOCTYPE[^>\[]*(?:\[.*?\])?\s*>)\s*)*<svg[\s>\/]/is', $bytes );
	}

	/**
	 * Pixel dimensions from width/height, else the viewBox.
	 *
	 * @param string $svg Sanitized SVG markup.
	 * @return array{0:int,1:int}|null
	 */
	public static function dimensions( $svg ) {
		$doc      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$loaded   = $doc->loadXML( (string) $svg, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $loaded || ! $doc->documentElement ) {
			return null;
		}
		$root   = $doc->documentElement;
		$width  = self::length( $root->getAttribute( 'width' ) );
		$height = self::length( $root->getAttribute( 'height' ) );
		if ( ( ! $width || ! $height ) && $root->hasAttribute( 'viewBox' ) ) {
			$box = preg_split( '/[\s,]+/', trim( $root->getAttribute( 'viewBox' ) ) );
			if ( 4 === count( $box ) && (float) $box[2] > 0 && (float) $box[3] > 0 ) {
				if ( $width && ! $height ) {
					$height = $width * (float) $box[3] / (float) $box[2];
				} elseif ( $height && ! $width ) {
					$width = $height * (float) $box[2] / (float) $box[3];
				} else {
					$width  = (float) $box[2];
					$height = (float) $box[3];
				}
			}
		}
		if ( ! $width || ! $height ) {
			return null;
		}
		return array( max( 1, (int) round( $width ) ), max( 1, (int) round( $height ) ) );
	}

	/**
	 * The refusal every failure returns: clear, not retryable with the same bytes, with a way forward.
	 *
	 * @param string $reason Why the SVG was refused.
	 * @return WP_Error
	 */
	public static function refuse( $reason ) {
		return new WP_Error(
			'svg_rejected',
			sprintf(
				/* translators: %s: reason */
				__( 'SVG refused: %s Nothing was saved. Sending the same SVG again gets the same answer. Use a PNG or WebP image instead, or send simpler SVG markup (plain shapes, paths, gradients and text).', 'vibe-ai' ),
				$reason
			),
			WPVibe_Error_Contract::data( 'invalid_input', false, array( 'status' => 422 ) )
		);
	}

	/**
	 * The XML declaration to write back: the author's own when it is a plain UTF-8
	 * one (so a clean file round-trips unchanged), a canonical one when the input
	 * had any other declaration, none when it had none. Output is always UTF-8.
	 */
	private static function declaration( $input ) {
		if ( ! preg_match( '/^\s*<\?xml\s/i', $input ) ) {
			return '';
		}
		if ( preg_match( '/^\s*(<\?xml\s+version=(["\'])1\.[01]\2(?:\s+encoding=(["\'])utf-8\3)?(?:\s+standalone=(["\'])(?:yes|no)\4)?\s*\?>)(\r?\n)?/i', $input, $m ) ) {
			return $m[1] . "\n";
		}
		return '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
	}

	private static function load_library() {
		if ( class_exists( '\WPVibe\Vendor\enshrined\svgSanitize\Sanitizer', false ) ) {
			return;
		}
		$base = dirname( __DIR__ ) . '/third-party/svg-sanitize/src/';
		foreach ( array( 'data/AttributeInterface.php', 'data/TagInterface.php', 'data/AllowedAttributes.php', 'data/AllowedTags.php', 'data/XPath.php', 'Exceptions/NestingException.php', 'Helper.php', 'ElementReference/Usage.php', 'ElementReference/Subject.php', 'ElementReference/Resolver.php', 'Sanitizer.php' ) as $file ) {
			require_once $base . $file;
		}
	}

	/** Remove unsafe children and attributes below and on $el; returns how many were removed. */
	private static function enforce( DOMElement $el ) {
		$removed = 0;
		foreach ( iterator_to_array( $el->childNodes, false ) as $child ) {
			if ( $child instanceof DOMElement ) {
				if ( self::is_forbidden_element( $child ) ) {
					$el->removeChild( $child );
					++$removed;
					continue;
				}
				$removed += self::enforce( $child );
			} elseif ( $child instanceof DOMCdataSection ) {
				$el->replaceChild( $el->ownerDocument->createTextNode( $child->nodeValue ), $child );
			} elseif ( ! ( $child instanceof DOMText ) && ! ( $child instanceof DOMComment ) ) {
				// Processing instructions (xml-stylesheet), entity references and anything else.
				$el->removeChild( $child );
				++$removed;
			}
		}

		$local = strtolower( (string) $el->localName );
		foreach ( iterator_to_array( $el->attributes, false ) as $attr ) {
			if ( ! self::attribute_is_safe( $attr, $local ) ) {
				$el->removeAttributeNode( $attr );
				++$removed;
			}
		}
		return $removed;
	}

	/** SVG elements only: an XHTML (or any other) namespace would bring HTML semantics along. */
	private static function is_svg_namespace( DOMElement $el ) {
		return null === $el->namespaceURI || '' === $el->namespaceURI || self::SVG_NS === $el->namespaceURI;
	}

	private static function is_forbidden_element( DOMElement $el ) {
		if ( ! self::is_svg_namespace( $el ) ) {
			return true;
		}
		$local = strtolower( (string) $el->localName );
		if ( in_array( $local, self::FORBIDDEN_ELEMENTS, true ) ) {
			return true;
		}
		if ( 0 === strpos( $local, 'animate' ) ) {
			$target = strtolower( trim( $el->getAttribute( 'attributeName' ) ) );
			if ( false !== strpos( $target, 'href' ) || 0 === strpos( $target, 'on' ) || false !== strpos( $target, 'style' ) ) {
				return true;
			}
		}
		return 'style' === $local && ! self::css_is_safe( $el->textContent );
	}

	private static function attribute_is_safe( DOMAttr $attr, $element ) {
		$name  = strtolower( (string) $attr->localName );
		$full  = strtolower( (string) $attr->nodeName );
		$value = (string) $attr->value;
		$flat  = strtolower( preg_replace( '/[\x00-\x20]+/', '', $value ) );

		if ( 0 === strpos( $name, 'on' ) || 'base' === $name || 'xml:base' === $full ) {
			return false;
		}
		if ( 'href' === $name || 'src' === $name || false !== strpos( $name, 'href' ) ) {
			if ( '' === $flat || '#' === $flat[0] ) {
				return true;
			}
			return in_array( $element, self::RASTER_HREF_ELEMENTS, true ) && (bool) preg_match( self::RASTER_DATA_URI, preg_replace( '/\s+/', '', $value ) );
		}
		if ( 'style' === $name ) {
			return self::css_is_safe( $value );
		}
		foreach ( array( 'javascript:', 'vbscript:', 'data:text', 'data:application', 'data:image/svg' ) as $scheme ) {
			if ( false !== strpos( $flat, $scheme ) ) {
				return false;
			}
		}
		// Presentation attributes (fill, stroke, filter, mask...) are CSS values: check all of
		// them, escapes included, not just ones spelling url( literally. aria-*/data-* are text.
		if ( 0 === strpos( $name, 'aria-' ) || 0 === strpos( $name, 'data-' ) || in_array( $name, array( 'id', 'class', 'lang' ), true ) ) {
			return true;
		}
		return self::css_is_safe( $value );
	}

	/**
	 * CSS may only reference #fragments and inline raster images, never fetch, import or run anything.
	 * Checked with comments stripped (a comment can split a keyword) and as written (a comment opener
	 * inside a quoted string would otherwise hide everything up to the next closer): both must pass.
	 */
	private static function css_is_safe( $css ) {
		$css = (string) $css;
		return self::css_text_is_safe( strtolower( (string) preg_replace( '#/\*.*?\*/#s', '', $css ) ) )
			&& self::css_text_is_safe( strtolower( $css ) );
	}

	private static function css_text_is_safe( $css ) {
		if ( false !== strpos( $css, '\\' ) ) {
			return false;
		}
		foreach ( array( '@import', 'expression(', 'javascript:', 'vbscript:', '-moz-binding', 'image-set(', 'src(' ) as $needle ) {
			if ( false !== strpos( $css, $needle ) ) {
				return false;
			}
		}
		if ( preg_match( '/(?<![a-z-])behavior\s*:/', $css ) ) {
			return false;
		}
		if ( preg_match( '/(?<![a-z-])image\s*\(/', $css ) ) {
			return false;
		}
		// Every url( must pair up with a target the pattern can read; anything else is unsafe.
		$opens = preg_match_all( '/url\s*\(/', $css );
		$pairs = preg_match_all( '/url\s*\(\s*([\'"]?)(.*?)\1\s*\)/s', $css, $m );
		if ( $opens !== $pairs ) {
			return false;
		}
		foreach ( $m[2] as $target ) {
			$target = trim( $target );
			if ( '' !== $target && '#' !== $target[0] && ! preg_match( self::RASTER_DATA_URI, preg_replace( '/\s+/', '', $target ) ) ) {
				return false;
			}
		}
		return true;
	}

	private static function has_drawable( DOMElement $root ) {
		foreach ( $root->getElementsByTagName( '*' ) as $el ) {
			if ( ! in_array( strtolower( (string) $el->localName ), self::NON_DRAWING_ELEMENTS, true ) ) {
				return true;
			}
		}
		return false;
	}

	private static function length( $value ) {
		if ( preg_match( '/^\s*([0-9]*\.?[0-9]+)\s*(px)?\s*$/i', (string) $value, $m ) ) {
			return (float) $m[1];
		}
		return 0.0;
	}
}
