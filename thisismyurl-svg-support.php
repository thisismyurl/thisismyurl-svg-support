<?php
/**
 * Plugin Name:       SVG Support by thisismyurl.com
 * Plugin URI:        https://thisismyurl.com/thisismyurl-svg-support/
 * Description:       Safely enable SVG uploads in the WordPress Media Library with allowlist sanitization, MIME validation, per-role permissions, and a sandboxed admin preview.
 * Version:           0.6174.1641
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Christopher Ross
 * Author URI:        https://thisismyurl.com/
 * Donate link:       https://thisismyurl.com/donate/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       thisismyurl-svg-support
 * Domain Path:       /languages
 * GitHub Plugin URI: https://github.com/thisismyurl/thisismyurl-svg-support
 * Primary Branch:    main
 *
 * @package TIMU_SVG_Support
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-svg-sanitizer.php';

/**
 * Main plugin controller.
 */
class TIMU_SVG_Support {

	/**
	 * Option key holding plugin settings.
	 */
	public const OPTION_KEY = 'timu_svg_options';

	/**
	 * Capability that the per-role allowlist grants.
	 *
	 * Held by every role the admin selects on the settings screen. Checked in
	 * `wp_handle_upload_prefilter` to gate SVG uploads above and beyond core's
	 * `upload_files`.
	 */
	public const UPLOAD_CAP = 'upload_svg_files';

	/**
	 * Bootstrap hook registrations.
	 */
	public function register_hooks(): void {
		add_filter( 'upload_mimes', array( $this, 'add_svg_mime_types' ) );
		add_filter( 'wp_check_filetype_and_ext', array( $this, 'check_svg_filetype_and_ext' ), 10, 4 );
		add_filter( 'wp_handle_upload_prefilter', array( $this, 'sanitize_svg_on_upload' ) );
		add_action( 'admin_head', array( $this, 'fix_svg_media_library_display' ) );
		add_action( 'admin_init', array( $this, 'register_svg_settings' ) );
		add_action( 'admin_menu', array( $this, 'create_svg_settings_page' ) );
		add_filter( 'wp_prepare_attachment_for_js', array( $this, 'sandbox_svg_preview' ), 10, 2 );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'add_plugin_action_links' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'wp_ajax_timu_svg_scan_existing', array( $this, 'ajax_scan_existing' ) );
	}

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
	 * @param array<int, string> $allowed_roles Slugs of roles permitted to upload SVG.
	 */
	public static function sync_role_caps( array $allowed_roles ): void {
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
	 * Filetype/ext detection for .svg / .svgz that respects WP's upstream
	 * detection but supplies the canonical MIME if it's missing.
	 *
	 * @param array  $data     Existing detection result.
	 * @param string $file     Path to the file (unused, signature retained).
	 * @param string $filename Original filename.
	 * @param array  $mimes    Allowed mimes (unused, signature retained).
	 * @return array
	 */
	public function check_svg_filetype_and_ext( $data, $file, $filename, $mimes ) {
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
	public function sanitize_svg_on_upload( $file ) {
		if ( empty( $file['tmp_name'] ) || empty( $file['name'] ) ) {
			return $file;
		}

		$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( 'svg' !== $ext && 'svgz' !== $ext ) {
			return $file;
		}

		// Capability gate above core's `upload_files` — README claim now true.
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
	 * Append a sanitization-failure entry to the in-memory option-backed log
	 * (last 50 entries). Cheap, durable, and inspectable from the CLI:
	 *
	 *   wp option get timu_svg_failure_log --format=json
	 *
	 * @param string $filename Original upload filename.
	 * @param string $reason   Short failure code.
	 */
	public static function log_failure( string $filename, string $reason ): void {
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

	/**
	 * Plugin row action links.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function add_plugin_action_links( $links ) {
		$custom = array(
			'<a href="' . esc_url( admin_url( 'options-general.php?page=thisismyurl-svg-support' ) ) . '">' . esc_html__( 'Settings', 'thisismyurl-svg-support' ) . '</a>',
			'<a href="https://thisismyurl.com/donate/" target="_blank" rel="noopener" style="color: #2271b1; font-weight: bold;">' . esc_html__( 'Donate', 'thisismyurl-svg-support' ) . '</a>',
		);
		return array_merge( $custom, $links );
	}

	/**
	 * Add SVG mimes when the option is enabled (strict comparison).
	 *
	 * @param array $mimes Allowed mimes map.
	 * @return array
	 */
	public function add_svg_mime_types( $mimes ) {
		$options    = (array) get_option( self::OPTION_KEY, array() );
		$is_enabled = isset( $options['enabled'] ) && 1 === (int) $options['enabled'];

		if ( $is_enabled ) {
			$mimes['svg']  = 'image/svg+xml';
			$mimes['svgz'] = 'image/svg+xml';
		}
		return $mimes;
	}

	/**
	 * Force SVG thumbnails to render at full container width in the Media
	 * Library grid + modal so they don't collapse to a 1px square.
	 */
	public function fix_svg_media_library_display(): void {
		echo '<style>.thumbnail img[src$=".svg"], [data-name="view-attachment"] .details img[src$=".svg"] { width: 100% !important; height: auto !important; }</style>';
	}

	/**
	 * Register settings + sanitizer.
	 */
	public function register_svg_settings(): void {
		register_setting(
			'timu_svg_settings_group',
			self::OPTION_KEY,
			array(
				'sanitize_callback' => array( $this, 'sanitize_svg_options' ),
				'default'           => array(
					'enabled' => 1,
					'roles'   => array( 'administrator' ),
				),
			)
		);
	}

	/**
	 * Sanitize the option payload. Reconciles role caps as a side-effect when
	 * the role allowlist changes.
	 *
	 * @param array $input Raw option input from the settings form.
	 * @return array
	 */
	public function sanitize_svg_options( $input ) {
		$new = array();

		$new['enabled'] = ( is_array( $input ) && isset( $input['enabled'] ) ) ? 1 : 0;

		$roles = array();
		if ( is_array( $input ) && isset( $input['roles'] ) && is_array( $input['roles'] ) ) {
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
		$new['roles'] = array_values( array_unique( $roles ) );

		// Reconcile WP role caps to match the saved allowlist.
		self::sync_role_caps( $new['roles'] );

		return $new;
	}

	/**
	 * Mount the settings page under Settings > SVG Support (matches the README
	 * promise; the prior tools.php location was a documentation/code mismatch).
	 */
	public function create_svg_settings_page(): void {
		add_options_page(
			__( 'SVG Support Settings', 'thisismyurl-svg-support' ),
			__( 'SVG Support', 'thisismyurl-svg-support' ),
			'manage_options',
			'thisismyurl-svg-support',
			array( $this, 'render_svg_admin_ui' )
		);
	}

	/**
	 * Settings UI.
	 */
	public function render_svg_admin_ui(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$options      = (array) get_option( self::OPTION_KEY, array() );
		$enabled_val  = isset( $options['enabled'] ) ? (int) $options['enabled'] : 0;
		$active_roles = isset( $options['roles'] ) && is_array( $options['roles'] ) ? $options['roles'] : array( 'administrator' );
		$wp_roles     = wp_roles();
		?>
		<div class="wrap">
			<h1>
				<?php esc_html_e( 'SVG Support', 'thisismyurl-svg-support' ); ?>
				<span style="font-size: 0.5em; font-weight: normal; vertical-align: middle; margin-left: 10px; color: #646970;">
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

			<div id="poststuff">
				<div id="post-body" class="metabox-holder columns-2">
					<div id="post-body-content">
						<div class="postbox">
							<div class="inside">
								<form method="post" action="options.php">
									<?php settings_fields( 'timu_svg_settings_group' ); ?>
									<table class="form-table" role="presentation">
										<tr>
											<th scope="row"><?php esc_html_e( 'Enable SVG Uploads', 'thisismyurl-svg-support' ); ?></th>
											<td>
												<label for="timu_svg_enabled">
													<input type="checkbox" id="timu_svg_enabled" name="timu_svg_options[enabled]" value="1" <?php checked( 1, $enabled_val ); ?> />
													<?php esc_html_e( 'Allow .svg files to be uploaded to the Media Library.', 'thisismyurl-svg-support' ); ?>
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
														<label for="<?php echo esc_attr( $input_id ); ?>" style="display:block; margin-bottom:4px;">
															<input
																type="checkbox"
																id="<?php echo esc_attr( $input_id ); ?>"
																name="timu_svg_options[roles][]"
																value="<?php echo esc_attr( $role_slug ); ?>"
																<?php checked( true, $checked ); ?>
															/>
															<?php echo esc_html( translate_user_role( $role['name'] ) ); ?>
														</label>
													<?php endforeach; ?>
													<p class="description">
														<?php esc_html_e( 'Only checked roles can upload SVG files. If none are checked, only Administrators retain access.', 'thisismyurl-svg-support' ); ?>
													</p>
												</fieldset>
											</td>
										</tr>
									</table>
									<?php submit_button( __( 'Save SVG Settings', 'thisismyurl-svg-support' ) ); ?>
								</form>
							</div>
						</div>
					</div>

					<div id="postbox-container-1" class="postbox-container">
						<div class="postbox">
							<h2 class="hndle"><span><?php esc_html_e( 'Documentation', 'thisismyurl-svg-support' ); ?></span></h2>
							<div class="inside">
								<p><?php esc_html_e( 'SVG files are XML-based and can carry executable content. Uploads are sanitized via an allowlist (enshrined/svg-sanitize) before being stored. Admin previews are sandboxed.', 'thisismyurl-svg-support' ); ?></p>
								<hr />
								<p>
									<a href="https://thisismyurl.com/donate/" class="button button-secondary" target="_blank" rel="noopener">
										<?php esc_html_e( 'Donate to Development', 'thisismyurl-svg-support' ); ?>
									</a>
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
		</div>
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
	 * Enqueue admin JS for the SVG scan feature — only on our settings page.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_admin_assets( string $hook_suffix ): void {
		if ( 'settings_page_thisismyurl-svg-support' !== $hook_suffix ) {
			return;
		}

		$plugin_url = plugin_dir_url( __FILE__ );
		$plugin_dir = plugin_dir_path( __FILE__ );
		$js_path    = $plugin_dir . 'js/timu-svg-scan.js';

		if ( ! file_exists( $js_path ) ) {
			return;
		}

		wp_enqueue_script(
			'timu-svg-scan',
			$plugin_url . 'js/timu-svg-scan.js',
			array( 'jquery' ),
			'0.6174.1641',
			true
		);

		wp_localize_script(
			'timu-svg-scan',
			'timuSvgScan',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'timu_svg_scan_existing' ),
				'i18n'      => array(
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
	public function ajax_scan_existing(): void {
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
register_activation_hook( __FILE__, array( 'TIMU_SVG_Support', 'activate_plugin_defaults' ) );

/**
 * Bootstrap the plugin once WP is loaded enough for translate_user_role().
 */
add_action(
	'plugins_loaded',
	static function () {
		load_plugin_textdomain( 'thisismyurl-svg-support', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
		( new TIMU_SVG_Support() )->register_hooks();

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
