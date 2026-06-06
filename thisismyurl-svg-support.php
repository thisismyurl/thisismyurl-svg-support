<?php
/**
 * Plugin Name:       - SVG Support by Christopher Ross
 * Plugin URI:        https://thisismyurl.com/thisismyurl-svg-support/
 * Description:       Safely enable SVG uploads in the WordPress Media Library with allowlist sanitization, MIME validation, and per-role permissions.
 * Version:           1.6149.0734
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Christopher Ross
 * Author URI:        https://thisismyurl.com/
 * Donate link:       https://github.com/sponsors/thisismyurl
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       thisismyurl-svg-support
 * Domain Path:       /languages
 * Update URI:        https://github.com/thisismyurl/thisismyurl-svg-support
 * GitHub Plugin URI: https://github.com/thisismyurl/thisismyurl-svg-support
 * Primary Branch:    main
 *
 * @package TIMU_SVG_Support
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-svg-sanitizer.php';
require_once __DIR__ . '/abilities.php';

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
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'add_plugin_action_links' ) );
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
			'<a href="' . esc_url( 'https://github.com/sponsors/thisismyurl' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Sponsor', 'thisismyurl-svg-support' ) . '</a>',
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
		// Registers the MIME only — the per-role allowlist gate (UPLOAD_CAP) is
		// enforced in sanitize_svg_on_upload() on wp_handle_upload_prefilter.
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
					printf(
						/* translators: %s: Site name link. */
						esc_html__( 'by %s', 'thisismyurl-svg-support' ),
						'<a href="https://thisismyurl.com/" target="_blank" rel="noopener" style="text-decoration: none; color: inherit;">thisismyurl.com</a>'
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
								<p><?php esc_html_e( 'SVG files are XML-based and can carry executable content. Uploads are sanitized via an allowlist (enshrined/svg-sanitize) before being stored.', 'thisismyurl-svg-support' ); ?></p>
								<hr />
								<p>
									<a href="<?php echo esc_url( 'https://github.com/sponsors/thisismyurl' ); ?>" class="button button-secondary" target="_blank" rel="noopener">
										<?php esc_html_e( 'Sponsor development', 'thisismyurl-svg-support' ); ?>
									</a>
								</p>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
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

		require_once plugin_dir_path( __FILE__ ) . 'github-updater.php';
		\ThisIsMyURL\SVG\GitHubReleaseUpdater::boot(
			array(
				'plugin_file' => __FILE__,
				'slug'        => 'thisismyurl-svg-support',
				'repo'        => 'thisismyurl/thisismyurl-svg-support',
			)
		);
	}
);
