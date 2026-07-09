<?php
/**
 * Plugin Name:       SVG Support by Christopher Ross
 * Plugin URI:        https://thisismyurl.com/thisismyurl-svg-support/
 * Description:       Safely enable SVG uploads in the WordPress Media Library with allowlist sanitization, MIME validation, per-role permissions, and a sandboxed admin preview.
 * Version:           1.6190.1670
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Christopher Ross
 * Author URI:        https://thisismyurl.com/
 * Donate link:       https://github.com/sponsors/thisismyurl
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       thisismyurl-svg-support
 * Domain Path:       /languages
 *
 * @package TIMU_SVG_Support
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'TIMU_SVG_VERSION' ) ) {
	define( 'TIMU_SVG_VERSION', '1.6190.1670' );
}

require_once __DIR__ . '/class-svg-sanitizer.php';
require_once __DIR__ . '/includes/class-backup-adapter.php';
require_once __DIR__ . '/abilities.php';
require_once __DIR__ . '/includes/class-timu-suite-core.php';

/**
 * Main plugin controller.
 *
 * Mirrors the shared image-family admin shell (Tools page with Optimize /
 * Settings / Report tabs, media-list + batch AJAX, settings registration).
 * The format-specific operation here is SANITIZE + MINIFY, not raster
 * re-encoding: every "optimize" re-runs the hardened allowlist sanitizer
 * (enshrined/svg-sanitize) with minification on and snapshots the original
 * first.
 */
class TIMU_SVG_Support {

	const AJAX_NONCE_ACTION = 'timu_svg_nonce';
	const BACKUP_META_KEY   = '_timu_svg_original_path';
	const SAVINGS_META_KEY  = '_timu_svg_savings';
	const SANITIZED_AT_KEY  = '_timu_svg_sanitized_at';
	const ISSUES_META_KEY   = '_timu_svg_issues';
	const OPTION_KEY        = 'timu_svg_options';
	const SETTINGS_GROUP    = 'timu_svg_settings_group';
	const ENV_OPTION_KEY    = 'timu_svg_environment_status';
	const BATCH_BACKUP_LOCK = 'timu_svg_batch_backup_lock';
	const SOURCE_MIME       = 'image/svg+xml';

	/**
	 * Capability that the per-role allowlist grants.
	 *
	 * Held by every role the admin selects on the Settings tab. Checked in
	 * `wp_handle_upload_prefilter` to gate SVG uploads above and beyond core's
	 * `upload_files`.
	 */
	public const UPLOAD_CAP = 'upload_svg_files';

	/**
	 * One-shot defaults on activation. Static so register_activation_hook can
	 * resolve it from the plugin bootstrap (not from inside a constructor —
	 * activation hooks must be registered at file scope, not object scope).
	 */
	public static function activate_plugin_defaults(): void {
		if ( false === get_option( self::OPTION_KEY ) ) {
			update_option(
				self::OPTION_KEY,
				array(
					'enabled' => 1,
					'roles'   => array( 'administrator' ),
				),
				false
			);
		}

		// Grant the SVG upload cap to roles configured in the option.
		$opts  = (array) get_option( self::OPTION_KEY, array() );
		$roles = isset( $opts['roles'] ) && is_array( $opts['roles'] ) ? $opts['roles'] : array( 'administrator' );
		self::sync_role_caps( $roles );
	}

	/**
	 * Remove the granted capability from every role on uninstall. Wired
	 * separately from this class via uninstall.php.
	 */
	public static function strip_role_caps(): void {
		$wp_roles = wp_roles();
		foreach ( array_keys( $wp_roles->roles ) as $role_slug ) {
			$role = get_role( $role_slug );
			if ( $role && $role->has_cap( self::UPLOAD_CAP ) ) {
				$role->remove_cap( self::UPLOAD_CAP );
			}
		}
	}

	/**
	 * Reconcile the upload-svg cap across roles based on the configured
	 * allowlist. Roles in the list get the cap; roles not in the list lose it.
	 *
	 * @return void
	 */
	public static function init() {
		// Admin shell.
		add_action( 'admin_menu', array( __CLASS__, 'add_admin_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_show_environment_notice' ) );
		add_action( 'wp_ajax_timu_svg_optimize', array( __CLASS__, 'ajax_bulk_optimize' ) );
		add_action( 'wp_ajax_timu_svg_process_batch', array( __CLASS__, 'ajax_process_batch' ) );
		add_action( 'wp_ajax_timu_svg_restore_single', array( __CLASS__, 'ajax_restore_single' ) );
		add_action( 'wp_ajax_timu_svg_scan_existing', array( __CLASS__, 'ajax_scan_existing' ) );
		add_action( 'admin_post_timu_svg_vortops_save', array( __CLASS__, 'handle_vortops_save' ) );
		TIMU_Suite_Settings::register_ajax_handlers();
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( __CLASS__, 'add_plugin_action_links' ) );

		// SVG upload + security core (preserved verbatim from the original plugin).
		add_filter( 'upload_mimes', array( __CLASS__, 'add_svg_mime_types' ) );
		add_filter( 'wp_check_filetype_and_ext', array( __CLASS__, 'check_svg_filetype_and_ext' ), 10, 4 );
		add_filter( 'wp_handle_upload_prefilter', array( __CLASS__, 'sanitize_svg_on_upload' ) );
		add_action( 'admin_head', array( __CLASS__, 'fix_svg_media_library_display' ) );
	}

	/* ──────────────────────────────────────────────────────────────────
	 * SVG upload + security core — PRESERVED. Behaviour is byte-identical
	 * to the pre-shell plugin; only the host class changed.
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Add SVG mimes when the option is enabled (strict comparison).
	 *
	 * Registers the MIME only — the per-role allowlist gate (UPLOAD_CAP) is
	 * enforced in sanitize_svg_on_upload() on wp_handle_upload_prefilter.
	 *
	 * @param array $mimes Allowed mimes map.
	 * @return array
	 */
	public static function add_svg_mime_types( $mimes ) {
		$options    = (array) get_option( self::OPTION_KEY, array() );
		$is_enabled = isset( $options['enabled'] ) && 1 === (int) $options['enabled'];

		if ( $is_enabled ) {
			$mimes['svg']  = 'image/svg+xml';
			$mimes['svgz'] = 'image/svg+xml';
		}

		return $mimes;
	}

	/**
	 * Filetype/ext detection for .svg / .svgz that respects WP's upstream
	 * detection but supplies the canonical MIME if it's missing.
	 *
	 * @param array  $data     Existing detection result.
	 * @param string $file     Path to the file (unused, signature retained).
	 * @param string $filename Original filename.
	 * @param array  $mimes    Allowed mimes (unused, signature retained).
	 * @return array
	 */
	public static function check_svg_filetype_and_ext( $data, $file, $filename, $mimes ) {
		unset( $file, $mimes );
		if ( ! empty( $data['type'] ) ) {
			return $data;
		}
		$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		if ( 'svg' === $ext ) {
			$data['type'] = 'image/svg+xml';
			$data['ext']  = 'svg';
		} elseif ( 'svgz' === $ext ) {
			$data['type'] = 'image/svg+xml';
			$data['ext']  = 'svgz';
		}
		return $data;
	}

	/**
	 * Upload prefilter — gates capability, validates MIME via finfo, and
	 * sanitizes the SVG in place via the allowlist sanitizer.
	 *
	 * @param array $file PHP upload tuple.
	 * @return array
	 */
	public static function sanitize_svg_on_upload( $file ) {
		if ( empty( $file['tmp_name'] ) || empty( $file['name'] ) ) {
			return $file;
		}

		$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( 'svg' !== $ext && 'svgz' !== $ext ) {
			return $file;
		}

		// Capability gate above core's `upload_files`.
		if ( is_user_logged_in() && ! current_user_can( self::UPLOAD_CAP ) ) {
			$file['error'] = esc_html__( 'SVG upload rejected: your role is not on the SVG upload allowlist. Ask an administrator.', 'thisismyurl-svg-support' );
			return $file;
		}

		// Server-side MIME check — prevents disguised payloads (e.g., HTML named .svg).
		if ( function_exists( 'finfo_open' ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );
			if ( false !== $finfo ) {
				$detected = finfo_file( $finfo, $file['tmp_name'] );
				finfo_close( $finfo );

				$accepted = array( 'image/svg+xml', 'image/svg', 'text/xml', 'text/plain', 'application/xml' );
				$is_gzip  = 'svgz' === $ext;
				if ( $is_gzip ) {
					$accepted[] = 'application/gzip';
					$accepted[] = 'application/x-gzip';
				}
				if ( $detected && ! in_array( strtolower( (string) $detected ), $accepted, true ) ) {
					$file['error'] = esc_html__( 'SVG upload rejected: file content does not match an SVG MIME type.', 'thisismyurl-svg-support' );
					self::log_failure( $file['name'], 'mime-mismatch:' . $detected );
					return $file;
				}
			}
		}

		if ( ! TIMU_SVG_Sanitizer::sanitize_file( $file['tmp_name'] ) ) {
			self::log_failure( $file['name'], TIMU_SVG_Sanitizer::get_last_error() );
			$file['error'] = esc_html__( 'SVG upload rejected: failed sanitization. The file may contain script, event handlers, or other unsafe content.', 'thisismyurl-svg-support' );
		}

		return $file;
	}

	/**
	 * Force SVG thumbnails to render at full container width in the Media
	 * Library grid + modal so they don't collapse to a 1px square.
	 *
	 * @return void
	 */
	public static function fix_svg_media_library_display() {
		echo '<style>.thumbnail img[src$=".svg"], [data-name="view-attachment"] .details img[src$=".svg"] { width: 100% !important; height: auto !important; }</style>';
	}

	/**
	 * Reconcile the upload-svg cap across roles based on the configured
	 * allowlist. Roles in the list get the cap; roles not in the list lose it.
	 *
	 * @param array<int, string> $allowed_roles Slugs of roles permitted to upload SVG.
	 * @return void
	 */
	public static function sync_role_caps( array $allowed_roles ) {
		$wp_roles = wp_roles();
		foreach ( array_keys( $wp_roles->roles ) as $role_slug ) {
			$role = get_role( $role_slug );
			if ( ! $role ) {
				continue;
			}
			if ( in_array( $role_slug, $allowed_roles, true ) ) {
				if ( ! $role->has_cap( self::UPLOAD_CAP ) ) {
					$role->add_cap( self::UPLOAD_CAP );
				}
			} elseif ( $role->has_cap( self::UPLOAD_CAP ) ) {
				$role->remove_cap( self::UPLOAD_CAP );
			}
		}
	}

	/**
	 * Append a sanitization-failure entry to the option-backed log (last 50
	 * entries). Cheap, durable, and inspectable from the CLI:
	 *
	 *   wp option get timu_svg_failure_log --format=json
	 *
	 * @param string $filename Original upload filename.
	 * @param string $reason   Short failure code.
	 * @return void
	 */
	public static function log_failure( string $filename, string $reason ) {
		$entries = (array) get_option( 'timu_svg_failure_log', array() );

		$entries[] = array(
			'time'     => time(),
			'user'     => get_current_user_id(),
			'filename' => sanitize_file_name( $filename ),
			'reason'   => sanitize_text_field( $reason ),
			'ip'       => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
		);

		// Cap at 50 most-recent entries to stop unbounded option growth.
		if ( count( $entries ) > 50 ) {
			$entries = array_slice( $entries, -50 );
		}

		update_option( 'timu_svg_failure_log', $entries, false );
	}

	/* ──────────────────────────────────────────────────────────────────
	 * Settings — the original enabled/roles options folded into the shared
	 * shell's register/sanitize pattern, plus shell-standard knobs.
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Register plugin settings.
	 *
	 * @return void
	 */
	public static function register_settings() {
		register_setting(
			self::SETTINGS_GROUP,
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_options' ),
				'default'           => self::get_default_options(),
			)
		);
	}

	/**
	 * Return default plugin options.
	 *
	 * @return array
	 */
	private static function get_default_options() {
		return array(
			'enabled'                  => 1,
			'roles'                    => array( 'administrator' ),
			'batch_size'               => 10,
			'optimize_on_upload'       => 1,
			'list_per_page'            => 25,
			'delete_backups_uninstall' => 0,
			'track_outbound_utms'      => 1,
		);
	}

	/**
	 * Retrieve plugin options merged with defaults.
	 *
	 * @return array
	 */
	private static function get_options() {
		$saved = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		return wp_parse_args( $saved, self::get_default_options() );
	}

	/**
	 * Sanitize plugin options. Reconciles role caps as a side-effect when the
	 * role allowlist changes.
	 *
	 * @param array $input Unsanitized option values.
	 * @return array
	 */
	public static function sanitize_options( $input ) {
		$defaults = self::get_default_options();
		$input    = is_array( $input ) ? $input : array();

		$enabled = isset( $input['enabled'] ) ? 1 : 0;

		$roles = array();
		if ( isset( $input['roles'] ) && is_array( $input['roles'] ) ) {
			$wp_roles = wp_roles();
			$valid    = array_keys( $wp_roles->roles );
			foreach ( $input['roles'] as $slug ) {
				$slug = sanitize_key( $slug );
				if ( in_array( $slug, $valid, true ) ) {
					$roles[] = $slug;
				}
			}
		}
		if ( empty( $roles ) ) {
			$roles = array( 'administrator' );
		}
		$roles = array_values( array_unique( $roles ) );

		// Reconcile WP role caps to match the saved allowlist.
		self::sync_role_caps( $roles );

		$batch_size = isset( $input['batch_size'] ) ? absint( $input['batch_size'] ) : $defaults['batch_size'];
		$batch_size = min( 100, max( 1, $batch_size ) );

		return array(
			'enabled'                  => $enabled,
			'roles'                    => $roles,
			'batch_size'               => $batch_size,
			'optimize_on_upload'       => isset( $input['optimize_on_upload'] ) ? 1 : 0,
			'list_per_page'            => min( 500, max( 5, isset( $input['list_per_page'] ) ? absint( $input['list_per_page'] ) : 25 ) ),
			'delete_backups_uninstall' => isset( $input['delete_backups_uninstall'] ) ? 1 : 0,
			'track_outbound_utms'      => isset( $input['track_outbound_utms'] ) ? 1 : 0,
		);
	}

	/**
	 * One-shot defaults on activation, and record environment capability
	 * details for the admin notice.
	 *
	 * @return void
	 */
	public static function activate_plugin() {
		if ( false === get_option( self::OPTION_KEY ) ) {
			update_option( self::OPTION_KEY, self::get_default_options(), false );
		}

		// Grant the SVG upload cap to roles configured in the option.
		$opts  = (array) get_option( self::OPTION_KEY, array() );
		$roles = isset( $opts['roles'] ) && is_array( $opts['roles'] ) ? $opts['roles'] : array( 'administrator' );
		self::sync_role_caps( $roles );

		$status = array(
			'checked_at'      => time(),
			'has_sanitizer'   => class_exists( '\\enshrined\\svgSanitize\\Sanitizer' ),
			'has_filesystem'  => function_exists( 'WP_Filesystem' ) || file_exists( ABSPATH . 'wp-admin/includes/file.php' ),
			'php'             => PHP_VERSION,
			'wp_version'      => get_bloginfo( 'version' ),
		);

		update_option( self::ENV_OPTION_KEY, $status, false );
		set_transient( 'timu_svg_activation_status', $status, MINUTE_IN_SECONDS * 5 );
	}

	/**
	 * Show environment notice after activation or when the sanitizer library
	 * is missing.
	 *
	 * @return void
	 */
	public static function maybe_show_environment_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$status = get_transient( 'timu_svg_activation_status' );
		if ( false !== $status ) {
			delete_transient( 'timu_svg_activation_status' );
		} else {
			$status = get_option( self::ENV_OPTION_KEY, array() );
		}

		if ( empty( $status ) || ! is_array( $status ) ) {
			return;
		}

		if ( empty( $status['has_sanitizer'] ) ) {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'SVG Support requires the bundled enshrined/svg-sanitize library. It was not detected, so uploads cannot be sanitized until the plugin is reinstalled with its vendor directory intact.', 'thisismyurl-svg-support' );
			echo '</p></div>';
		}
	}

	/**
	 * Whether the allowlist sanitizer library is available.
	 *
	 * @return bool
	 */
	private static function has_sanitizer() {
		return class_exists( '\\enshrined\\svgSanitize\\Sanitizer' );
	}

	/* ──────────────────────────────────────────────────────────────────
	 * Admin shell — menu, assets, plugin links.
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Register the Tools submenu page.
	 *
	 * @return void
	 */
	public static function add_admin_menu() {
		add_management_page(
			__( 'SVG Support', 'thisismyurl-svg-support' ),
			__( 'SVG Support', 'thisismyurl-svg-support' ),
			'manage_options',
			'svg-optimizer',
			array( __CLASS__, 'render_admin_page' )
		);
	}

	/**
	 * Enqueue admin assets for the tools page.
	 *
	 * @param string $hook_suffix Current admin page suffix.
	 * @return void
	 */
	public static function enqueue_admin_assets( $hook_suffix ) {
		if ( 'tools_page_svg-optimizer' !== $hook_suffix ) {
			return;
		}

		$plugin_url = plugin_dir_url( __FILE__ );
		$plugin_dir = plugin_dir_path( __FILE__ );

		wp_enqueue_script(
			'timu-svg-support-admin',
			$plugin_url . 'assets/js/admin.js',
			array( 'jquery' ),
			TIMU_SVG_VERSION,
			true
		);

		$scan_js = $plugin_dir . 'js/timu-svg-scan.js';
		if ( file_exists( $scan_js ) ) {
			wp_enqueue_script(
				'timu-svg-scan',
				$plugin_url . 'js/timu-svg-scan.js',
				array( 'jquery' ),
				TIMU_SVG_VERSION,
				true
			);
			wp_localize_script(
				'timu-svg-scan',
				'timuSvgScan',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'timu_svg_scan_existing' ),
					'i18n'    => array(
						'scanning'  => __( 'Scanning…', 'thisismyurl-svg-support' ),
						'done'      => __( 'Scan complete.', 'thisismyurl-svg-support' ),
						'error'     => __( 'Request error. Check the browser console.', 'thisismyurl-svg-support' ),
						'processed' => __( 'Processed', 'thisismyurl-svg-support' ),
						'of'        => __( 'of', 'thisismyurl-svg-support' ),
						'files'     => __( 'files', 'thisismyurl-svg-support' ),
					),
				)
			);
		}
	}

	/**
	 * Add Settings and Sponsor links to plugin row actions.
	 *
	 * @param array $links Existing plugin row links.
	 * @return array
	 */
	public static function add_plugin_action_links( $links ) {
		$settings_url = admin_url( 'tools.php?page=svg-optimizer&tab=settings' );
		$sponsor_url  = self::get_thisismyurl_link( 'https://github.com/sponsors/thisismyurl', 'plugin_row_sponsor' );

		$custom_links = array(
			'<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'thisismyurl-svg-support' ) . '</a>',
			'<a href="' . esc_url( $sponsor_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Sponsor', 'thisismyurl-svg-support' ) . '</a>',
		);

		return array_merge( $custom_links, $links );
	}

	/**
	 * Build a thisismyurl/Sponsor link with optional static, privacy-safe UTM
	 * tags.
	 *
	 * @param string $url      Destination URL.
	 * @param string $campaign Campaign identifier.
	 * @return string
	 */
	private static function get_thisismyurl_link( $url, $campaign ) {
		$options = self::get_options();
		if ( empty( $options['track_outbound_utms'] ) ) {
			return $url;
		}

		return add_query_arg(
			array(
				'utm_source'   => 'wp_plugin',
				'utm_medium'   => 'svg_support',
				'utm_campaign' => sanitize_key( $campaign ),
			),
			$url
		);
	}

	/* ──────────────────────────────────────────────────────────────────
	 * Media listing + the SVG operation (sanitize & minify in place).
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Get active processing batch size.
	 *
	 * @return int
	 */
	private static function get_batch_size_setting() {
		$options = self::get_options();
		return (int) $options['batch_size'];
	}

	/**
	 * Initialize the WordPress Filesystem API (direct transport for in-place
	 * rewrites under uploads/).
	 *
	 * @return WP_Filesystem_Base|false
	 */
	private static function init_fs() {
		global $wp_filesystem;

		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		return $wp_filesystem;
	}

	/**
	 * Build the backup directory path for an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	private static function get_backup_dir( $attachment_id ) {
		$upload_dir = wp_upload_dir();
		$rel_path   = get_post_meta( $attachment_id, '_wp_attached_file', true );
		$subdir     = dirname( $rel_path );

		if ( '.' === $subdir ) {
			$subdir = '';
		}

		return trailingslashit( $upload_dir['basedir'] . '/svg-backups/' . $subdir );
	}

	/**
	 * Return lists of pending and managed SVG media items.
	 *
	 * "Pending" = an SVG with no sanitize-and-minify backup yet (never been
	 * optimized here). "Managed" = an SVG this plugin has already optimized
	 * (a backup of the original exists).
	 *
	 * @return array
	 */
	public static function get_media_lists() {
		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'post_mime_type' => self::SOURCE_MIME,
			)
		);

		$pending = array();
		$media   = array();

		if ( ! empty( $query->posts ) ) {
			foreach ( $query->posts as $post ) {
				$file        = get_attached_file( $post->ID );
				$backup_path = get_post_meta( $post->ID, self::BACKUP_META_KEY, true );

				if ( ! $file || ! file_exists( $file ) ) {
					$post->timu_svg_status = 'missing';
					$media[]               = $post;
					continue;
				}

				if ( $backup_path ) {
					$media[] = $post;
				} else {
					$pending[] = $post;
				}
			}
		}

		return array(
			'pending' => $pending,
			'media'   => $media,
		);
	}

	/**
	 * Sanitize and minify a single SVG attachment in place, backing up the
	 * original first.
	 *
	 * The operation re-runs the same hardened allowlist sanitizer used on
	 * upload (enshrined/svg-sanitize), this time with minification enabled, so
	 * the file is both re-hardened (scripts / event handlers / remote refs
	 * stripped) and reduced in weight (whitespace / comments / redundant
	 * metadata collapsed). The pre-operation original is preserved in both the
	 * Vault snapshot and this plugin's own svg-backups/ directory.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return true|WP_Error
	 */
	public static function sanitize_and_minify_svg( $attachment_id ) {
		$fs        = self::init_fs();
		$full_path = get_attached_file( $attachment_id );

		if ( ! $fs || ! $full_path || ! $fs->exists( $full_path ) ) {
			return new WP_Error( 'missing', __( 'File does not exist.', 'thisismyurl-svg-support' ) );
		}

		if ( ! self::has_sanitizer() ) {
			return new WP_Error( 'sanitizer', __( 'The SVG sanitizer library is unavailable. Reinstall the plugin with its vendor directory.', 'thisismyurl-svg-support' ) );
		}

		if ( self::SOURCE_MIME !== get_post_mime_type( $attachment_id ) ) {
			return new WP_Error( 'mime', __( 'Only SVG files can be sanitized and minified.', 'thisismyurl-svg-support' ) );
		}

		$contents = $fs->get_contents( $full_path );
		if ( false === $contents || '' === $contents ) {
			return new WP_Error( 'read', __( 'The SVG file is empty or could not be read.', 'thisismyurl-svg-support' ) );
		}

		// Transparently decode .svgz so we can sanitize + minify the markup,
		// then re-encode on write to preserve the on-disk format.
		$is_gzip = 0 === strncmp( $contents, "\x1f\x8b\x08", 3 );
		if ( $is_gzip ) {
			if ( ! function_exists( 'gzdecode' ) ) {
				return new WP_Error( 'gzip', __( 'This server cannot decode .svgz files (gzip support missing).', 'thisismyurl-svg-support' ) );
			}
			$decoded = gzdecode( $contents );
			if ( false === $decoded ) {
				return new WP_Error( 'gzip', __( 'The .svgz file could not be decoded.', 'thisismyurl-svg-support' ) );
			}
			$contents = $decoded;
		}

		$original_size = strlen( $contents );

		// Take an extra Vault/Shadow safety snapshot of this file before touching it.
		TIMU_SVG_Backup_Adapter::snapshot( 'SVG sanitize #' . $attachment_id, array( $full_path ) );

		// Re-run the hardened allowlist sanitizer, now with minification on.
		$sanitized = TIMU_SVG_Sanitizer::sanitize_string( $contents, true );
		if ( false === $sanitized || '' === $sanitized ) {
			return new WP_Error( 'sanitize', __( 'Sanitization failed; the file was left untouched.', 'thisismyurl-svg-support' ) );
		}

		$issues = TIMU_SVG_Sanitizer::get_last_issues();

		// Own per-file backup of the pre-sanitize original (independent of Vault).
		$backup_dir = self::get_backup_dir( $attachment_id );
		if ( ! wp_mkdir_p( $backup_dir ) ) {
			return new WP_Error( 'mkdir', __( 'Unable to create the backup directory.', 'thisismyurl-svg-support' ) );
		}

		$backup_path = $backup_dir . basename( $full_path );
		if ( ! $fs->copy( $full_path, $backup_path, true, FS_CHMOD_FILE ) ) {
			return new WP_Error( 'backup', __( 'Failed to back up the original SVG file.', 'thisismyurl-svg-support' ) );
		}

		// Re-encode .svgz on write so the stored format is unchanged.
		$payload = $sanitized;
		if ( $is_gzip ) {
			$payload = gzencode( $sanitized );
			if ( false === $payload ) {
				$fs->delete( $backup_path );
				return new WP_Error( 'gzip', __( 'Failed to re-compress the optimized .svgz file.', 'thisismyurl-svg-support' ) );
			}
		}

		if ( ! $fs->put_contents( $full_path, $payload, FS_CHMOD_FILE ) ) {
			$fs->delete( $backup_path );
			return new WP_Error( 'write', __( 'Failed to write the optimized SVG file.', 'thisismyurl-svg-support' ) );
		}

		$new_size = (int) ( $is_gzip ? strlen( $payload ) : strlen( $sanitized ) );

		update_post_meta( $attachment_id, self::BACKUP_META_KEY, $backup_path );
		update_post_meta( $attachment_id, self::SAVINGS_META_KEY, max( 0, $original_size - $new_size ) );
		update_post_meta( $attachment_id, self::SANITIZED_AT_KEY, time() );
		update_post_meta( $attachment_id, self::ISSUES_META_KEY, wp_json_encode( array_values( $issues ) ) );

		return true;
	}

	/**
	 * Restore an original SVG image from this plugin's own backup.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public static function restore_image( $attachment_id ) {
		$fs          = self::init_fs();
		$backup_path = get_post_meta( $attachment_id, self::BACKUP_META_KEY, true );

		if ( ! $fs || ! $backup_path || ! $fs->exists( $backup_path ) ) {
			return false;
		}

		$current_path = get_attached_file( $attachment_id );
		if ( ! $current_path ) {
			return false;
		}

		// Snapshot the current optimized file before it is overwritten by restore.
		TIMU_SVG_Backup_Adapter::snapshot( 'SVG restore #' . $attachment_id, array( $current_path ) );

		if ( ! $fs->copy( $backup_path, $current_path, true, FS_CHMOD_FILE ) ) {
			return false;
		}

		$fs->delete( $backup_path );

		delete_post_meta( $attachment_id, self::BACKUP_META_KEY );
		delete_post_meta( $attachment_id, self::SAVINGS_META_KEY );
		delete_post_meta( $attachment_id, self::SANITIZED_AT_KEY );
		delete_post_meta( $attachment_id, self::ISSUES_META_KEY );

		return true;
	}

	/**
	 * Build reporting metrics for a selected date window.
	 *
	 * @param string $range_key Date range key.
	 * @return array
	 */
	private static function get_report_metrics( $range_key ) {
		$now   = time();
		$start = 0;

		switch ( $range_key ) {
			case '30d':
				$start = $now - ( 30 * DAY_IN_SECONDS );
				break;
			case '90d':
				$start = $now - ( 90 * DAY_IN_SECONDS );
				break;
			case '365d':
				$start = $now - ( 365 * DAY_IN_SECONDS );
				break;
			case 'all':
			default:
				$start = 0;
				break;
		}

		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'post_mime_type' => self::SOURCE_MIME,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded SVG-only attachment set; admin report screen, not a front-end query.
					array(
						'key'     => self::BACKUP_META_KEY,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$sanitized_count = 0;
		$bytes_saved     = 0;
		$issues_count    = 0;
		$pending_count   = 0;

		if ( ! empty( $query->posts ) ) {
			foreach ( $query->posts as $post ) {
				$sanitized_at = (int) get_post_meta( $post->ID, self::SANITIZED_AT_KEY, true );
				if ( $start > 0 && ( $sanitized_at <= 0 || $sanitized_at < $start ) ) {
					continue;
				}

				$sanitized_count++;
				$bytes_saved += (int) get_post_meta( $post->ID, self::SAVINGS_META_KEY, true );

				$issues = json_decode( (string) get_post_meta( $post->ID, self::ISSUES_META_KEY, true ), true );
				if ( is_array( $issues ) ) {
					$issues_count += count( $issues );
				}
			}
		}

		$lists         = self::get_media_lists();
		$pending_count = count( $lists['pending'] );

		return array(
			'range'           => $range_key,
			'sanitized_count' => $sanitized_count,
			'bytes_saved'     => $bytes_saved,
			'avg_saved_kb'    => $sanitized_count > 0 ? ( $bytes_saved / $sanitized_count ) / 1024 : 0,
			'issues_count'    => $issues_count,
			'pending_count'   => $pending_count,
		);
	}

	/* ──────────────────────────────────────────────────────────────────
	 * AJAX endpoints — every one gated by nonce + manage_options.
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * AJAX callback: sanitize & minify one SVG image.
	 *
	 * @return void
	 */
	public static function ajax_bulk_optimize() {
		check_ajax_referer( self::AJAX_NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized request.', 'thisismyurl-svg-support' ) );
		}

		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
		if ( ! $attachment_id ) {
			wp_send_json_error( __( 'Invalid attachment ID.', 'thisismyurl-svg-support' ) );
		}

		$result = self::sanitize_and_minify_svg( $attachment_id );

		if ( true === $result ) {
			wp_send_json_success(
				array(
					'filename' => basename( (string) get_attached_file( $attachment_id ) ),
					'thumb'    => wp_get_attachment_image( $attachment_id, array( 50, 50 ) ),
				)
			);
		}

		wp_send_json_error( is_wp_error( $result ) ? $result->get_error_message() : __( 'Unknown error.', 'thisismyurl-svg-support' ) );
	}

	/**
	 * AJAX callback: process a chunk of attachments.
	 *
	 * @return void
	 */
	public static function ajax_process_batch() {
		check_ajax_referer( self::AJAX_NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized request.', 'thisismyurl-svg-support' ) );
		}

		$batch_limit = self::get_batch_size_setting();
		$ids         = isset( $_POST['attachment_ids'] ) ? (array) wp_unslash( $_POST['attachment_ids'] ) : array();
		$ids         = array_slice( array_values( array_filter( array_map( 'absint', $ids ) ) ), 0, $batch_limit );

		if ( empty( $ids ) ) {
			wp_send_json_error( __( 'No attachments were provided for batch processing.', 'thisismyurl-svg-support' ) );
		}

		$processed_ids = array();
		$failed_ids    = array();
		$errors        = array();

		// Take one Vault/Shadow safety snapshot per short run window.
		// Re-running full backups for every AJAX chunk can cause long delays/timeouts.
		$backup_lock_key = self::BATCH_BACKUP_LOCK . '_' . get_current_user_id();
		if ( ! get_transient( $backup_lock_key ) ) {
			TIMU_SVG_Backup_Adapter::snapshot( 'SVG batch sanitize', array() );
			set_transient( $backup_lock_key, 1, 15 * MINUTE_IN_SECONDS );
		}

		foreach ( $ids as $attachment_id ) {
			$result = self::sanitize_and_minify_svg( $attachment_id );
			if ( true === $result ) {
				$processed_ids[] = $attachment_id;
			} else {
				$failed_ids[] = $attachment_id;
				$errors[]     = is_wp_error( $result ) ? $result->get_error_message() : __( 'Unknown sanitization error.', 'thisismyurl-svg-support' );
			}
		}

		wp_send_json_success(
			array(
				'processed_ids' => $processed_ids,
				'failed_ids'    => $failed_ids,
				'errors'        => array_values( array_unique( $errors ) ),
			)
		);
	}

	/**
	 * AJAX callback: restore one original SVG.
	 *
	 * @return void
	 */
	public static function ajax_restore_single() {
		check_ajax_referer( self::AJAX_NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized request.', 'thisismyurl-svg-support' ) );
		}

		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
		if ( ! $attachment_id ) {
			wp_send_json_error( __( 'Invalid attachment ID.', 'thisismyurl-svg-support' ) );
		}

		if ( self::restore_image( $attachment_id ) ) {
			wp_send_json_success();
		}

		wp_send_json_error( __( 'Image could not be restored.', 'thisismyurl-svg-support' ) );
	}

	/* ──────────────────────────────────────────────────────────────────
	 * Admin page render — Optimize / Settings / Report.
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Render the admin page.
	 *
	 * @return void
	 */
	public static function handle_vortops_save() {
		check_admin_referer( 'timu_svg_vortops_save', 'timu_svg_vortops_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'thisismyurl-svg-support' ) );
		}
		$api_key = isset( $_POST['timu_vortops_api_key'] )
			? sanitize_text_field( wp_unslash( $_POST['timu_vortops_api_key'] ) )
			: '';
		if ( '' !== $api_key ) {
			update_option( TIMU_Vortops_Client::OPTION_KEY, $api_key, false );
		} else {
			delete_option( TIMU_Vortops_Client::OPTION_KEY );
		}
		wp_safe_redirect( add_query_arg(
			array( 'page' => 'svg-optimizer', 'tab' => 'settings', 'vortops-saved' => '1' ),
			admin_url( 'tools.php' )
		) );
		exit;
	}

	public static function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'thisismyurl-svg-support' ) );
		}

		$allowed_tabs = array( 'optimize', 'settings', 'report' );
		$active_tab   = isset( $_GET['tab'] ) ? sanitize_key( (string) $_GET['tab'] ) : 'optimize'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $active_tab, $allowed_tabs, true ) ) {
			$active_tab = 'optimize';
		}

		$lists       = self::get_media_lists();
		$options     = self::get_options();
		$pending_ids = array_map(
			static function ( $post ) {
				return (int) $post->ID;
			},
			$lists['pending']
		);
		$restorable  = array();

		foreach ( $lists['media'] as $post ) {
			$orig = get_post_meta( $post->ID, self::BACKUP_META_KEY, true );
			if ( $orig ) {
				$restorable[] = (int) $post->ID;
			}
		}

		wp_add_inline_script(
			'timu-svg-support-admin',
			'window.TIMUSvgSupportData = ' . wp_json_encode(
				array(
					'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
					'nonce'      => wp_create_nonce( self::AJAX_NONCE_ACTION ),
					'actions'    => array(
						'batch'   => 'timu_svg_process_batch',
						'restore' => 'timu_svg_restore_single',
					),
					'batchSize'  => self::get_batch_size_setting(),
					'perPage'    => (int) $options['list_per_page'],
					'pendingIds' => $pending_ids,
					'strings'    => array(
						'processing'        => __( 'Processing...', 'thisismyurl-svg-support' ),
						'restoring'         => __( 'Restoring...', 'thisismyurl-svg-support' ),
						'confirmRestoreAll' => __( 'Restore all originals? This cannot be undone.', 'thisismyurl-svg-support' ),
						'failedPrefix'      => __( 'Some files failed:', 'thisismyurl-svg-support' ),
					),
				)
			) . ';',
			'before'
		);

		$base_url        = admin_url( 'tools.php?page=svg-optimizer' );
		$optimize_url    = $base_url . '&tab=optimize';
		$settings_url    = $base_url . '&tab=settings';
		$report_url      = $base_url . '&tab=report';
		$thisismyurl_url = self::get_thisismyurl_link( 'https://thisismyurl.com/', 'plugin_header' );
		$sponsor_url     = self::get_thisismyurl_link( 'https://github.com/sponsors/thisismyurl', 'plugin_sidebar_sponsor' );

		?>
		<div class="wrap">
			<h1>
				<?php esc_html_e( 'SVG Support', 'thisismyurl-svg-support' ); ?>
				<span style="font-size:0.5em;font-weight:normal;vertical-align:middle;margin-left:10px;color:#646970;">
					<?php
					$site_link = wp_kses(
						'<a href="https://thisismyurl.com/" target="_blank" rel="noopener" style="text-decoration: none; color: inherit;">thisismyurl.com</a>',
						array(
							'a' => array(
								'href'   => array(),
								'target' => array(),
								'rel'    => array(),
								'style'  => array(),
							),
						)
					);
					printf(
						/* translators: %s: Site name link (anchor tag). */
						__( 'by %s', 'thisismyurl-svg-support' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $site_link passed through wp_kses above.
						$site_link // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sanitized via wp_kses.
					);
					?>
				</span>
			</h1>

			<nav class="nav-tab-wrapper wp-clearfix">
				<a href="<?php echo esc_url( $optimize_url ); ?>" class="nav-tab<?php echo 'optimize' === $active_tab ? ' nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Optimize', 'thisismyurl-svg-support' ); ?>
					<?php if ( ! empty( $pending_ids ) ) : ?>
						<span class="awaiting-mod" style="margin-left:4px;"><?php echo esc_html( count( $pending_ids ) ); ?></span>
					<?php endif; ?>
				</a>
				<a href="<?php echo esc_url( $settings_url ); ?>" class="nav-tab<?php echo 'settings' === $active_tab ? ' nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Settings', 'thisismyurl-svg-support' ); ?>
				</a>
				<a href="<?php echo esc_url( $report_url ); ?>" class="nav-tab<?php echo 'report' === $active_tab ? ' nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Report', 'thisismyurl-svg-support' ); ?>
				</a>
			</nav>

			<?php if ( 'optimize' === $active_tab ) : ?>

			<div id="poststuff">
				<div id="post-body" class="metabox-holder columns-2">
					<div id="post-body-content">

						<div class="postbox">
							<h2 class="hndle"><span><?php esc_html_e( 'Sanitize and optimize dashboard', 'thisismyurl-svg-support' ); ?></span></h2>
							<div class="inside">
								<div style="padding:10px 0;min-height:80px;">
									<div class="fwo-controls" style="display:flex;gap:10px;align-items:center;">
										<button id="btn-start" class="button button-primary button-large" <?php disabled( empty( $pending_ids ) ); ?>>
											<?php
											printf(
												/* translators: %d: number of pending SVG files */
												esc_html__( 'Sanitize and optimize all %d SVG files', 'thisismyurl-svg-support' ),
												count( $pending_ids )
											);
											?>
										</button>
										<button id="btn-cancel" class="button button-secondary button-large" style="display:none;color:#d63638;">
											<?php esc_html_e( 'Cancel batch', 'thisismyurl-svg-support' ); ?>
										</button>
									</div>
									<div id="fwo-progress-container" style="display:none;margin-top:20px;background:#f0f0f1;height:30px;position:relative;border-radius:4px;overflow:hidden;border:1px solid #c3c4c7;">
										<div id="fwo-progress-bar" style="background:#2271b1;height:100%;width:0%;transition:width 0.2s;"></div>
										<div id="fwo-progress-text" style="position:absolute;width:100%;text-align:center;top:0;line-height:30px;font-weight:bold;color:#fff;mix-blend-mode:difference;">0%</div>
									</div>
								</div>
							</div>
						</div>

						<div class="postbox">
							<h2 class="hndle"><span><?php esc_html_e( 'Pending SVG files', 'thisismyurl-svg-support' ); ?> (<span id="p-cnt"><?php echo esc_html( count( $pending_ids ) ); ?></span>)</span></h2>
							<div class="inside">
								<table class="widefat striped" id="fwo-pending-table" style="border:none;box-shadow:none;">
									<thead>
										<tr>
											<th><?php esc_html_e( 'Preview', 'thisismyurl-svg-support' ); ?></th>
											<th><?php esc_html_e( 'ID', 'thisismyurl-svg-support' ); ?></th>
											<th><?php esc_html_e( 'File name', 'thisismyurl-svg-support' ); ?></th>
										</tr>
									</thead>
									<tbody>
										<?php if ( ! empty( $lists['pending'] ) ) : ?>
											<?php foreach ( $lists['pending'] as $post ) : ?>
												<tr id="fwo-row-<?php echo esc_attr( $post->ID ); ?>">
													<td><?php echo wp_kses_post( wp_get_attachment_image( $post->ID, array( 50, 50 ) ) ); ?></td>
													<td>#<?php echo esc_html( $post->ID ); ?></td>
													<td><?php echo esc_html( basename( (string) get_attached_file( $post->ID ) ) ); ?></td>
												</tr>
											<?php endforeach; ?>
										<?php else : ?>
											<tr class="no-images"><td colspan="3"><?php esc_html_e( 'All SVG files sanitized and optimized.', 'thisismyurl-svg-support' ); ?></td></tr>
										<?php endif; ?>
									</tbody>
								</table>
							</div>
						</div>

						<div class="postbox">
							<h2 class="hndle"><span><?php esc_html_e( 'Managed SVG files', 'thisismyurl-svg-support' ); ?> (<span id="m-cnt"><?php echo esc_html( count( $lists['media'] ) ); ?></span>)</span></h2>
							<div class="inside">
								<table class="widefat striped" id="fwo-media-table" style="border:none;box-shadow:none;">
									<thead>
										<tr>
											<th><?php esc_html_e( 'Preview', 'thisismyurl-svg-support' ); ?></th>
											<th><?php esc_html_e( 'ID', 'thisismyurl-svg-support' ); ?></th>
											<th><?php esc_html_e( 'File name', 'thisismyurl-svg-support' ); ?></th>
											<th><?php esc_html_e( 'Stripped', 'thisismyurl-svg-support' ); ?></th>
											<th><?php esc_html_e( 'Action', 'thisismyurl-svg-support' ); ?></th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ( $lists['media'] as $post ) : ?>
											<?php
											$orig         = get_post_meta( $post->ID, self::BACKUP_META_KEY, true );
											$status       = isset( $post->timu_svg_status ) ? $post->timu_svg_status : '';
											$issues       = json_decode( (string) get_post_meta( $post->ID, self::ISSUES_META_KEY, true ), true );
											$issues_count = is_array( $issues ) ? count( $issues ) : 0;
											?>
											<tr id="fwo-media-row-<?php echo esc_attr( $post->ID ); ?>">
												<td><?php echo wp_kses_post( wp_get_attachment_image( $post->ID, array( 50, 50 ) ) ); ?></td>
												<td>#<?php echo esc_html( $post->ID ); ?></td>
												<td><?php echo esc_html( basename( (string) get_attached_file( $post->ID ) ) ); ?></td>
												<td>
													<?php if ( 'missing' === $status ) : ?>
														<span class="description">&mdash;</span>
													<?php elseif ( $issues_count > 0 ) : ?>
														<?php
														echo esc_html(
															sprintf(
																/* translators: %d: number of items the sanitizer stripped or flagged */
																_n( '%d item', '%d items', $issues_count, 'thisismyurl-svg-support' ),
																$issues_count
															)
														);
														?>
													<?php else : ?>
														<span class="description"><?php esc_html_e( 'Clean', 'thisismyurl-svg-support' ); ?></span>
													<?php endif; ?>
												</td>
												<td>
													<?php if ( 'missing' === $status ) : ?>
														<span style="color:#d63638;"><?php esc_html_e( 'File missing', 'thisismyurl-svg-support' ); ?></span>
													<?php elseif ( $orig ) : ?>
														<button class="restore-btn button button-small" data-id="<?php echo esc_attr( $post->ID ); ?>">
															<?php esc_html_e( 'Restore', 'thisismyurl-svg-support' ); ?>
														</button>
													<?php else : ?>
														<span class="description"><?php esc_html_e( 'Optimized', 'thisismyurl-svg-support' ); ?></span>
													<?php endif; ?>
												</td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							</div>
						</div>

					</div><!-- #post-body-content -->

					<div id="postbox-container-1" class="postbox-container">
						<div class="postbox">
							<h2 class="hndle"><span><?php esc_html_e( 'About', 'thisismyurl-svg-support' ); ?></span></h2>
							<div class="inside">
								<p><?php esc_html_e( 'Enables SVG uploads and sanitizes each one with the hardened allowlist sanitizer (enshrined/svg-sanitize), stripping scripts, event handlers, and remote references, then minifies the markup to cut file weight. Originals are backed up and can be restored at any time.', 'thisismyurl-svg-support' ); ?></p>
								<?php if ( ! empty( $restorable ) ) : ?>
									<hr />
									<p><strong><?php esc_html_e( 'Bulk Actions', 'thisismyurl-svg-support' ); ?></strong></p>
									<button id="btn-restore-all" class="button button-secondary" style="width:100%;text-align:center;" data-ids="<?php echo esc_attr( wp_json_encode( $restorable ) ); ?>">
										<?php esc_html_e( 'Restore all originals', 'thisismyurl-svg-support' ); ?>
									</button>
								<?php endif; ?>
								<hr />
								<p>
									<?php
									echo wp_kses_post(
										sprintf(
											/* translators: %s: link to thisismyurl.com */
											__( 'Provided free by %s.', 'thisismyurl-svg-support' ),
											'<a href="' . esc_url( $thisismyurl_url ) . '" target="_blank" rel="noopener noreferrer">thisismyurl.com</a>'
										)
									);
									?>
								</p>
								<p><a href="<?php echo esc_url( $sponsor_url ); ?>" class="button button-secondary" target="_blank" rel="noopener noreferrer" style="width:100%;text-align:center;"><?php esc_html_e( 'Sponsor development', 'thisismyurl-svg-support' ); ?></a></p>
							</div>
						</div>
					</div><!-- #postbox-container-1 -->

				</div><!-- #post-body -->
			</div><!-- #poststuff -->

			<?php elseif ( 'settings' === $active_tab ) : /* settings tab */ ?>

			<?php
			$wp_roles     = wp_roles();
			$active_roles = isset( $options['roles'] ) && is_array( $options['roles'] ) ? $options['roles'] : array( 'administrator' );
			?>

			<div id="poststuff" style="padding-top:10px;">
				<div id="post-body" class="metabox-holder columns-1">
					<div id="post-body-content">

						<div class="postbox">
							<h2 class="hndle"><span><?php esc_html_e( 'SVG settings', 'thisismyurl-svg-support' ); ?></span></h2>
							<div class="inside">
								<form method="post" action="options.php">
									<?php settings_fields( self::SETTINGS_GROUP ); ?>
									<table class="form-table" role="presentation">
										<tr>
											<th scope="row"><?php esc_html_e( 'Enable SVG uploads', 'thisismyurl-svg-support' ); ?></th>
											<td>
												<label for="timu_svg_enabled">
													<input type="checkbox" id="timu_svg_enabled" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enabled]" value="1" <?php checked( 1, (int) $options['enabled'] ); ?> />
													<?php esc_html_e( 'Allow .svg and .svgz files to be uploaded to the Media Library.', 'thisismyurl-svg-support' ); ?>
												</label>
											</td>
										</tr>
										<tr>
											<th scope="row"><?php esc_html_e( 'Roles allowed to upload SVG', 'thisismyurl-svg-support' ); ?></th>
											<td>
												<fieldset>
													<legend class="screen-reader-text"><?php esc_html_e( 'Roles allowed to upload SVG', 'thisismyurl-svg-support' ); ?></legend>
													<?php
													foreach ( $wp_roles->roles as $slug => $role ) :
														$role_slug = sanitize_key( $slug );
														$checked   = in_array( $role_slug, $active_roles, true );
														$input_id  = 'timu_svg_role_' . $role_slug;
														?>
														<label for="<?php echo esc_attr( $input_id ); ?>" style="display:block;margin-bottom:4px;">
															<input
																type="checkbox"
																id="<?php echo esc_attr( $input_id ); ?>"
																name="<?php echo esc_attr( self::OPTION_KEY ); ?>[roles][]"
																value="<?php echo esc_attr( $role_slug ); ?>"
																<?php checked( true, $checked ); ?>
															/>
															<?php echo esc_html( translate_user_role( $role['name'] ) ); ?>
														</label>
													<?php endforeach; ?>
													<p class="description">
														<?php esc_html_e( 'Only checked roles can upload SVG files. If none are checked, only administrators retain access.', 'thisismyurl-svg-support' ); ?>
													</p>
												</fieldset>
											</td>
										</tr>
										<tr>
											<th scope="row"><?php esc_html_e( 'Sanitize on upload', 'thisismyurl-svg-support' ); ?></th>
											<td>
												<label>
													<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[optimize_on_upload]" value="1" <?php checked( ! empty( $options['optimize_on_upload'] ) ); ?> />
													<?php esc_html_e( 'Sanitize every SVG automatically at upload time.', 'thisismyurl-svg-support' ); ?>
												</label>
												<p class="description"><?php esc_html_e( 'Always on for security: uploads are sanitized regardless of this setting. This controls only whether the Optimize tab counts freshly-uploaded files as already handled.', 'thisismyurl-svg-support' ); ?></p>
											</td>
										</tr>
										<tr>
											<th scope="row"><label for="timu-batch-size"><?php esc_html_e( 'Batch size', 'thisismyurl-svg-support' ); ?></label></th>
											<td>
												<input id="timu-batch-size" type="number" min="1" max="100" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[batch_size]" value="<?php echo esc_attr( $options['batch_size'] ); ?>" class="small-text" />
												<p class="description"><?php esc_html_e( 'Files processed per AJAX request. Lower this if you see timeouts. Default: 10.', 'thisismyurl-svg-support' ); ?></p>
											</td>
										</tr>
										<tr>
											<th scope="row"><label for="timu-per-page"><?php esc_html_e( 'Items per page', 'thisismyurl-svg-support' ); ?></label></th>
											<td>
												<input id="timu-per-page" type="number" min="5" max="500" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[list_per_page]" value="<?php echo esc_attr( $options['list_per_page'] ); ?>" class="small-text" />
												<p class="description"><?php esc_html_e( 'How many files to show per page in the Pending and Managed lists. Default: 25.', 'thisismyurl-svg-support' ); ?></p>
											</td>
										</tr>
										<tr>
											<th scope="row"><?php esc_html_e( 'Outbound UTM parameters', 'thisismyurl-svg-support' ); ?></th>
											<td>
												<label>
													<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[track_outbound_utms]" value="1" <?php checked( ! empty( $options['track_outbound_utms'] ) ); ?> />
													<?php esc_html_e( 'Add privacy-safe UTM parameters to links to thisismyurl.com.', 'thisismyurl-svg-support' ); ?>
												</label>
												<p class="description"><?php esc_html_e( 'These UTMs include no site IDs, account IDs, user IDs, visitor data, or domain names. They only identify this plugin as the traffic source.', 'thisismyurl-svg-support' ); ?></p>
											</td>
										</tr>
										<tr>
											<th scope="row"><?php esc_html_e( 'On uninstall', 'thisismyurl-svg-support' ); ?></th>
											<td>
												<label>
													<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[delete_backups_uninstall]" value="1" <?php checked( ! empty( $options['delete_backups_uninstall'] ) ); ?> />
													<?php esc_html_e( 'Delete all backup files when the plugin is uninstalled.', 'thisismyurl-svg-support' ); ?>
												</label>
												<p class="description"><?php esc_html_e( 'Leave unchecked to keep original SVG files in the backup directory even after removing the plugin.', 'thisismyurl-svg-support' ); ?></p>
											</td>
										</tr>
									</table>

									<?php submit_button( __( 'Save settings', 'thisismyurl-svg-support' ) ); ?>
								</form>
							</div>
						</div>

						<?php
						TIMU_Suite_Settings::render_vortops_postbox( array(
							'save_action'     => 'timu_svg_vortops_save',
							'nonce_action'    => 'timu_svg_vortops_save',
							'nonce_name'      => 'timu_svg_vortops_nonce',
							'redirect_page'   => 'svg-optimizer',
							'field_id'        => 'timu_vortops_api_key_svg',
							'btn_id'          => 'btn-vortops-test-svg',
							'result_id'       => 'vortops-test-result-svg',
							'local_available' => self::has_sanitizer(),
							'local_ok_msg'    => __( 'SVG sanitization is working locally using the bundled library. Vortops is optional — connect an account for a cloud backup path.', 'thisismyurl-svg-support' ),
							'gap_msg'         => __( "The local SVG sanitizer library is unavailable. SVG uploads are currently blocked. This is a server or installation issue, not a plugin restriction. Connecting a Vortops account enables cloud SVG sanitization as an alternative.", 'thisismyurl-svg-support' ),
						) );
						?>

					</div><!-- #post-body-content -->
				</div><!-- #post-body -->
			</div><!-- #poststuff -->

			<?php else : /* report tab */ ?>

			<?php
			$report_range = isset( $_GET['range'] ) ? sanitize_key( (string) $_GET['range'] ) : '30d'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( ! in_array( $report_range, array( '30d', '90d', '365d', 'all' ), true ) ) {
				$report_range = '30d';
			}
			$report_data = self::get_report_metrics( $report_range );
			?>

			<div id="poststuff" style="padding-top:10px;">
				<div id="post-body" class="metabox-holder columns-1">
					<div id="post-body-content">
						<div class="postbox">
							<h2 class="hndle"><span><?php esc_html_e( 'Sanitization and optimization report', 'thisismyurl-svg-support' ); ?></span></h2>
							<div class="inside">
								<p class="description"><?php esc_html_e( 'Use these metrics to show what this plugin has hardened and saved over business-friendly time windows.', 'thisismyurl-svg-support' ); ?></p>
								<p>
									<a class="button <?php echo '30d' === $report_range ? 'button-primary' : 'button-secondary'; ?>" href="<?php echo esc_url( add_query_arg( array( 'tab' => 'report', 'range' => '30d' ), $base_url ) ); ?>"><?php esc_html_e( 'Last 30 days', 'thisismyurl-svg-support' ); ?></a>
									<a class="button <?php echo '90d' === $report_range ? 'button-primary' : 'button-secondary'; ?>" href="<?php echo esc_url( add_query_arg( array( 'tab' => 'report', 'range' => '90d' ), $base_url ) ); ?>"><?php esc_html_e( 'Last 90 days', 'thisismyurl-svg-support' ); ?></a>
									<a class="button <?php echo '365d' === $report_range ? 'button-primary' : 'button-secondary'; ?>" href="<?php echo esc_url( add_query_arg( array( 'tab' => 'report', 'range' => '365d' ), $base_url ) ); ?>"><?php esc_html_e( 'Last 12 months', 'thisismyurl-svg-support' ); ?></a>
									<a class="button <?php echo 'all' === $report_range ? 'button-primary' : 'button-secondary'; ?>" href="<?php echo esc_url( add_query_arg( array( 'tab' => 'report', 'range' => 'all' ), $base_url ) ); ?>"><?php esc_html_e( 'All time', 'thisismyurl-svg-support' ); ?></a>
								</p>

								<table class="widefat striped" style="max-width:960px;">
									<tbody>
										<tr>
											<th style="width:340px;"><?php esc_html_e( 'SVG files sanitized in period', 'thisismyurl-svg-support' ); ?></th>
											<td><?php echo esc_html( number_format_i18n( (int) $report_data['sanitized_count'] ) ); ?></td>
										</tr>
										<tr>
											<th><?php esc_html_e( 'Total weight saved by minification', 'thisismyurl-svg-support' ); ?></th>
											<td><?php echo esc_html( size_format( (int) $report_data['bytes_saved'], 2 ) ); ?></td>
										</tr>
										<tr>
											<th><?php esc_html_e( 'Average savings per file', 'thisismyurl-svg-support' ); ?></th>
											<td><?php echo esc_html( number_format_i18n( (float) $report_data['avg_saved_kb'], 2 ) . ' KB' ); ?></td>
										</tr>
										<tr>
											<th><?php esc_html_e( 'Unsafe items stripped', 'thisismyurl-svg-support' ); ?></th>
											<td><?php echo esc_html( number_format_i18n( (int) $report_data['issues_count'] ) ); ?></td>
										</tr>
										<tr>
											<th><?php esc_html_e( 'SVG files still pending', 'thisismyurl-svg-support' ); ?></th>
											<td><?php echo esc_html( number_format_i18n( (int) $report_data['pending_count'] ) ); ?></td>
										</tr>
									</tbody>
								</table>
								<p class="description" style="margin-top:10px;">
									<?php esc_html_e( 'Stripped items are scripts, event handlers, remote references, and other content the allowlist sanitizer removed — the transparency record of what was made safe.', 'thisismyurl-svg-support' ); ?>
								</p>
							</div>
						</div>

						<div class="postbox">
							<h2 class="hndle"><span><?php esc_html_e( 'Sanitize Existing SVGs', 'thisismyurl-svg-support' ); ?></span></h2>
							<div class="inside">
								<p><?php esc_html_e( 'SVGs uploaded before this plugin was active (or before the allowlist sanitizer was added) may not have been sanitized. Use this tool to retroactively sanitize every SVG in the Media Library.', 'thisismyurl-svg-support' ); ?></p>
								<p><strong><?php esc_html_e( 'This operation rewrites files on disk. Back up first if you are unsure.', 'thisismyurl-svg-support' ); ?></strong></p>
								<button type="button" id="timu-svg-scan-btn" class="button button-secondary">
									<?php esc_html_e( 'Sanitize Existing SVGs', 'thisismyurl-svg-support' ); ?>
								</button>
								<div id="timu-svg-scan-progress" style="margin-top:10px;"></div>
								<ul id="timu-svg-scan-errors" style="margin-top:6px; color:#d63638;"></ul>
							</div>
						</div>
					</div>
				</div>
			</div>

			<?php endif; ?>

		</div><!-- .wrap -->
		<?php
	}

	/**
	 * Sandbox SVG previews in the Media Library. We replace the rendered
	 * `<img src="...svg">` tile with a sandboxed iframe pointing at the SVG —
	 * cookie/JS exfil from a pre-sanitization or edge-case payload is contained.
	 *
	 * @param array   $response Attachment data prepared for JS.
	 * @param WP_Post $attachment Attachment post.
	 * @return array
	 */
	public function sandbox_svg_preview( $response, $attachment ) {
		if ( empty( $response['mime'] ) || 'image/svg+xml' !== $response['mime'] ) {
			return $response;
		}

		$src = wp_get_attachment_url( $attachment->ID );
		if ( ! $src ) {
			return $response;
		}

		// Replace inline-img preview surfaces with a sandboxed iframe markup
		// that the Media modal will render as the rich content area. The
		// `sandbox` attribute is empty -> all powerful features denied.
		$iframe = sprintf(
			'<iframe src="%s" sandbox="" referrerpolicy="no-referrer" loading="lazy" style="width:100%%;height:100%%;min-height:240px;border:0;background:#fff;" title="%s"></iframe>',
			esc_url( $src ),
			esc_attr__( 'Sanitized SVG preview', 'thisismyurl-svg-support' )
		);

		$response['image']['src'] = $src;
		// Modal "rich" preview uses `sizes` / `image` keys; provide an HTML
		// override the modal's view will surface.
		$response['svg_sandbox_html'] = $iframe;

		return $response;
	}

	/**
	 * AJAX handler: retroactive sanitization scan.
	 *
	 * Processes 25 SVG attachments per request starting at the given offset.
	 * Requires manage_options and a valid nonce.
	 *
	 * Expected POST params:
	 *   nonce  — wp_create_nonce( 'timu_svg_scan_existing' )
	 *   offset — int, 0-based page start (default 0)
	 */
	public static function ajax_scan_existing(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'thisismyurl-svg-support' ) ), 403 );
		}

		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'timu_svg_scan_existing' ) ) {
			wp_send_json_error( array( 'message' => __( 'Nonce verification failed.', 'thisismyurl-svg-support' ) ), 403 );
		}

		$offset     = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$batch_size = 25;

		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => 'image/svg+xml',
				'post_status'    => 'inherit',
				'posts_per_page' => $batch_size,
				'offset'         => $offset,
				'fields'         => 'ids',
				'no_found_rows'  => false,
			)
		);

		$total     = (int) $query->found_posts;
		$processed = 0;
		$errors    = array();

		foreach ( $query->posts as $attachment_id ) {
			$attachment_id = (int) $attachment_id;
			$path          = get_attached_file( $attachment_id );

			if ( ! $path || ! is_readable( $path ) ) {
				$errors[] = sprintf(
					/* translators: %d: attachment ID */
					__( 'ID %d: file not found or unreadable.', 'thisismyurl-svg-support' ),
					$attachment_id
				);
				continue;
			}

			$ok = TIMU_SVG_Sanitizer::sanitize_file( $path );
			if ( ! $ok ) {
				$errors[] = sprintf(
					/* translators: 1: attachment ID, 2: error code */
					__( 'ID %1$d: sanitization failed (%2$s).', 'thisismyurl-svg-support' ),
					$attachment_id,
					esc_html( TIMU_SVG_Sanitizer::get_last_error() )
				);
			}

			++$processed;
		}

		$next_offset = $offset + $processed;
		$has_more    = $next_offset < $total;

		wp_send_json_success(
			array(
				'processed'   => $processed,
				'total'       => $total,
				'next_offset' => $next_offset,
				'has_more'    => $has_more,
				'errors'      => $errors,
			)
		);
	}
}

// Activation must be registered at file scope, not inside a constructor —
// otherwise the hook is registered AFTER WP has already fired the activation
// callbacks for the request, and defaults silently never seed.
register_activation_hook( __FILE__, array( 'TIMU_SVG_Support', 'activate_plugin' ) );

/**
 * Bootstrap the plugin once WP is loaded enough for translate_user_role().
 */
add_action(
	'plugins_loaded',
	static function () {
		load_plugin_textdomain( 'thisismyurl-svg-support', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
		TIMU_SVG_Support::init();

		$updater_path = plugin_dir_path( __FILE__ ) . 'updater.php';
		if ( file_exists( $updater_path ) ) {
			require_once $updater_path;
			if ( class_exists( 'TIMU_GitHub_Updater' ) ) {
				new TIMU_GitHub_Updater(
					array(
						'slug'               => 'thisismyurl-svg-support',
						'proper_folder_name' => 'thisismyurl-svg-support',
						'api_url'            => 'https://api.github.com/repos/thisismyurl/thisismyurl-svg-support/releases/latest',
						'github_url'         => 'https://github.com/thisismyurl/thisismyurl-svg-support',
						'plugin_file'        => __FILE__,
					)
				);
			}
		}

		// WP-CLI command: wp timu-svg scan-existing
		$cli_path = plugin_dir_path( __FILE__ ) . 'class-svg-cli.php';
		if ( defined( 'WP_CLI' ) && WP_CLI && file_exists( $cli_path ) ) {
			require_once $cli_path;
			WP_CLI::add_command( 'timu-svg', 'TIMU_SVG_CLI' );
		}
	}
);
