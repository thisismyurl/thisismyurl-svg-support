<?php
/**
 * SVG Sanitizer — strips script, event handlers, javascript: URIs, and other XSS vectors
 * from uploaded SVG files before they reach the Media Library.
 *
 * Defensive sanitizer: blocks the obvious XSS surface. For a comprehensive whitelist-based
 * approach, swap in enshrined/svg-sanitize via Composer.
 *
 * @package TIMU_SVG_Support
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TIMU_SVG_Sanitizer {

	private const DISALLOWED_ELEMENTS = array(
		'script',
		'iframe',
		'foreignObject',
		'object',
		'embed',
		'animate',
		'animateMotion',
		'animateTransform',
		'set',
		'use',
		'handler',
		'listener',
	);

	private const URI_ATTRS = array( 'href', 'xlink:href', 'src', 'action', 'formaction' );

	private const MAX_BYTES = 5242880; // 5 MB upper bound to mitigate zip-bomb / billion-laughs.

	public static function sanitize_file( string $path ): bool {
		$size = filesize( $path );
		if ( false === $size || $size > self::MAX_BYTES ) {
			return false;
		}

		$contents = file_get_contents( $path );
		if ( false === $contents ) {
			return false;
		}

		$is_gzip = substr( $contents, 0, 3 ) === "\x1f\x8b\x08";
		if ( $is_gzip ) {
			$decoded = @gzdecode( $contents );
			if ( false === $decoded || strlen( $decoded ) > self::MAX_BYTES ) {
				return false;
			}
			$contents = $decoded;
		}

		$sanitized = self::sanitize_string( $contents );
		if ( false === $sanitized ) {
			return false;
		}

		if ( $is_gzip ) {
			$sanitized = gzencode( $sanitized );
			if ( false === $sanitized ) {
				return false;
			}
		}

		return false !== file_put_contents( $path, $sanitized ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	public static function sanitize_string( string $svg ) {
		$svg = preg_replace( '/<!ENTITY[^>]*>/i', '', $svg );
		$svg = preg_replace( '/<!DOCTYPE[^>]*>/i', '', $svg );

		$prev = libxml_use_internal_errors( true );
		$dom  = new DOMDocument();
		$ok   = $dom->loadXML( $svg, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		if ( ! $ok || ! $dom->documentElement ) {
			return false;
		}

		if ( strtolower( $dom->documentElement->nodeName ) !== 'svg' ) {
			return false;
		}

		foreach ( self::DISALLOWED_ELEMENTS as $tag ) {
			$nodes = $dom->getElementsByTagName( $tag );
			for ( $i = $nodes->length - 1; $i >= 0; $i-- ) {
				$node = $nodes->item( $i );
				if ( $node && $node->parentNode ) {
					$node->parentNode->removeChild( $node );
				}
			}
		}

		self::sanitize_element_attributes( $dom->documentElement );

		$out = $dom->saveXML();
		return false === $out ? false : $out;
	}

	private static function sanitize_element_attributes( DOMElement $element ): void {
		$to_remove = array();
		if ( $element->hasAttributes() ) {
			foreach ( $element->attributes as $attr ) {
				$qualified  = strtolower( $attr->nodeName );
				$local_name = strtolower( $attr->localName );
				$value      = (string) $attr->value;

				if ( 0 === strpos( $local_name, 'on' ) ) {
					$to_remove[] = $attr;
					continue;
				}

				if ( in_array( $qualified, self::URI_ATTRS, true ) || 'href' === $local_name ) {
					if ( preg_match( '/^\s*(javascript|vbscript|livescript|mocha)\s*:/i', $value ) ) {
						$to_remove[] = $attr;
						continue;
					}
					if ( preg_match( '/^\s*data\s*:/i', $value )
						&& ! preg_match( '/^\s*data:image\/(png|jpe?g|gif|webp)/i', $value ) ) {
						$to_remove[] = $attr;
						continue;
					}
				}

				if ( 'style' === $local_name
					&& preg_match( '/expression\s*\(|behaviou?r\s*:|@import|url\s*\(\s*["\']?\s*javascript:|javascript:/i', $value ) ) {
					$to_remove[] = $attr;
				}
			}
		}

		foreach ( $to_remove as $attr ) {
			$element->removeAttributeNode( $attr );
		}

		foreach ( iterator_to_array( $element->childNodes ) as $child ) {
			if ( $child instanceof DOMElement ) {
				self::sanitize_element_attributes( $child );
			}
		}
	}
}
