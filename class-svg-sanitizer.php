<?php
/**
 * SVG Sanitizer — delegates to enshrined/svg-sanitize (allowlist-based).
 *
 * Replaces the prior self-rolled denylist sanitizer (CVE-class XSS bypasses;
 * see GHSA-wmc2-4458-vm72). Allowlist semantics are the only defensible
 * posture for SVG sanitization at this scope.
 *
 * @package TIMU_SVG_Support
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Composer autoloader — bundled in vendor/.
$timu_svg_autoload = __DIR__ . '/vendor/autoload.php';
if ( file_exists( $timu_svg_autoload ) ) {
	require_once $timu_svg_autoload;
}

use enshrined\svgSanitize\Sanitizer;

/**
 * Thin wrapper over enshrined/svg-sanitize that handles WordPress filesystem
 * I/O, .svgz (gzip) round-tripping, and a hard byte ceiling to mitigate
 * billion-laughs / decompression-bomb classes.
 */
class TIMU_SVG_Sanitizer {

	/**
	 * Hard upper bound on the decompressed SVG payload, in bytes.
	 *
	 * 5 MB ceiling caps decompression-bomb risk on .svgz inputs.
	 */
	private const MAX_BYTES = 5242880;

	/**
	 * Last sanitization issues from the underlying library, for logging.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private static $last_issues = array();

	/**
	 * Last failure reason, for logging by callers.
	 *
	 * @var string
	 */
	private static $last_error = '';

	/**
	 * Sanitize an SVG file in place.
	 *
	 * @param string $path Absolute path to the SVG (or SVGZ) on disk.
	 * @return bool True on success, false on rejection or I/O failure.
	 */
	public static function sanitize_file( string $path ): bool {
		self::$last_issues = array();
		self::$last_error  = '';

		if ( ! is_readable( $path ) ) {
			self::$last_error = 'unreadable';
			return false;
		}

		$size = filesize( $path );
		if ( false === $size || $size > self::MAX_BYTES ) {
			self::$last_error = 'oversize';
			return false;
		}

		$wp_filesystem = self::get_filesystem();
		if ( ! $wp_filesystem ) {
			self::$last_error = 'filesystem-unavailable';
			return false;
		}

		$contents = $wp_filesystem->get_contents( $path );
		if ( false === $contents || '' === $contents ) {
			self::$last_error = 'empty-or-unreadable';
			return false;
		}

		$is_gzip = 0 === strncmp( $contents, "\x1f\x8b\x08", 3 );
		if ( $is_gzip ) {
			if ( ! function_exists( 'gzdecode' ) ) {
				self::$last_error = 'gzip-unsupported';
				return false;
			}
			$decoded = gzdecode( $contents );
			if ( false === $decoded || strlen( $decoded ) > self::MAX_BYTES ) {
				self::$last_error = 'gzip-decode-failed-or-oversize';
				return false;
			}
			$contents = $decoded;
		}

		$sanitized = self::sanitize_string( $contents );
		if ( false === $sanitized || '' === $sanitized ) {
			return false;
		}

		if ( $is_gzip ) {
			$sanitized = gzencode( $sanitized );
			if ( false === $sanitized ) {
				self::$last_error = 'gzip-encode-failed';
				return false;
			}
		}

		$written = $wp_filesystem->put_contents( $path, $sanitized, FS_CHMOD_FILE );
		if ( ! $written ) {
			self::$last_error = 'write-failed';
			return false;
		}

		return true;
	}

	/**
	 * Sanitize an SVG string. Returns the sanitized SVG, or false on failure.
	 *
	 * The hardening posture is identical whether or not minification runs:
	 * the same allowlist, the same `removeRemoteReferences( true )`. Minify
	 * only collapses whitespace, comments, and redundant metadata in the
	 * already-sanitized output to cut file weight. The upload path leaves it
	 * off (default) so on-upload behaviour is unchanged; the Optimize tab
	 * passes true.
	 *
	 * @param string $svg    Raw SVG markup.
	 * @param bool   $minify Whether to minify the sanitized output. Default false.
	 * @return string|false
	 */
	public static function sanitize_string( string $svg, bool $minify = false ) {
		if ( ! class_exists( Sanitizer::class ) ) {
			self::$last_error = 'sanitizer-library-missing';
			return false;
		}

		$sanitizer = new Sanitizer();
		$sanitizer->minify( $minify );
		$sanitizer->removeRemoteReferences( true );

		$clean = $sanitizer->sanitize( $svg );

		if ( false === $clean || '' === $clean ) {
			self::$last_error = 'sanitize-rejected';
			return false;
		}

		// Capture issues (XML parse warnings + cleaned items) for downstream logging.
		if ( method_exists( $sanitizer, 'getXmlIssues' ) ) {
			self::$last_issues = (array) $sanitizer->getXmlIssues();
		}

		return $clean;
	}

	/**
	 * Last error code from a failed sanitize_file()/sanitize_string() call.
	 *
	 * @return string
	 */
	public static function get_last_error(): string {
		return self::$last_error;
	}

	/**
	 * Last sanitization issues (XML parse warnings, etc.) from the underlying
	 * library. Useful for the sanitization-failure log.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_last_issues(): array {
		return self::$last_issues;
	}

	/**
	 * Bootstrap WP_Filesystem (direct transport) for in-place file rewrites.
	 * On the upload prefilter path the file is in the PHP tmp dir, which is
	 * always direct-writable; so we force the 'direct' method.
	 *
	 * @return WP_Filesystem_Base|false
	 */
	private static function get_filesystem() {
		global $wp_filesystem;

		if ( $wp_filesystem instanceof WP_Filesystem_Base ) {
			return $wp_filesystem;
		}

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		// Force direct transport — prefilter runs synchronously on upload, in-process.
		add_filter( 'filesystem_method', array( __CLASS__, 'force_direct_filesystem' ) );
		$ready = WP_Filesystem();
		remove_filter( 'filesystem_method', array( __CLASS__, 'force_direct_filesystem' ) );

		if ( ! $ready || ! $wp_filesystem instanceof WP_Filesystem_Base ) {
			return false;
		}

		return $wp_filesystem;
	}

	/**
	 * Filter callback to pin WP_Filesystem to the direct transport during
	 * the upload prefilter pass.
	 *
	 * @return string
	 */
	public static function force_direct_filesystem(): string {
		return 'direct';
	}
}
