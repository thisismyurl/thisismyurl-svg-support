<?php
/**
 * Author:      Christopher Ross
 * Author URI:  https://thisismyurl.com/
 * Plugin Name: SVG Support by thisismyurl.com
 * Plugin URI:  https://thisismyurl.com/thisismyurl-svg-support/
 * Donate link: https://thisismyurl.com/donate/
 * Description: Safely enable SVG uploads and management in the WordPress Media Library.
 * Version:     1.251229
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Update URI: https://github.com/thisismyurl/thisismyurl-svg-support
 * GitHub Plugin URI: https://github.com/thisismyurl/thisismyurl-svg-support
 * Primary Branch: main
 * 
 * Text Domain: thisismyurl-svg-support
 * License:     GPL2
 * 
 * 
 * * @package TIMU_SVG_Support
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TIMU_SVG_Support {

	public function __construct() {

		add_filter( 'upload_mimes', array( $this, 'add_svg_mime_types' ) );
		add_action( 'admin_head', array( $this, 'fix_svg_media_library_display' ) );
		add_action( 'admin_init', array( $this, 'register_svg_settings' ) );
		add_action( 'admin_menu', array( $this, 'create_svg_tools_page' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'add_plugin_action_links' ) );

		// Hook to set defaults only when the plugin is first activated.
		register_activation_hook( __FILE__, array( $this, 'activate_plugin_defaults' ) );
	}

	/**
	 * Activate Plugin Defaults:
	 * This only runs ONCE when the plugin is activated.
	 */
	public function activate_plugin_defaults() {
		// Only set the default if the option doesn't exist at all.
		if ( false === get_option( 'timu_svg_options' ) ) {
			update_option( 'timu_svg_options', array( 'enabled' => 1 ) );
		}
	}

	public function add_plugin_action_links( $links ) {
		$custom_links = array(
			'<a href="' . admin_url( 'tools.php?page=thisismyurl-svg-support' ) . '">' . esc_html__( 'Settings', 'thisismyurl-svg-support' ) . '</a>',
			'<a href="https://thisismyurl.com/donate/" target="_blank" style="color: #2271b1; font-weight: bold;">' . esc_html__( 'Donate', 'thisismyurl-svg-support' ) . '</a>',
		);
		return array_merge( $custom_links, $links );
	}

	public function add_svg_mime_types( $mimes ) {
		$options = get_option( 'timu_svg_options' );

		// We check if 'enabled' is exactly 1. 
		// If the option is missing (rare), we treat it as 0 to avoid "forcing" it on.
		$is_enabled = isset( $options['enabled'] ) && 1 == $options['enabled'];

		if ( $is_enabled ) {
			$mimes['svg']  = 'image/svg+xml';
			$mimes['svgz'] = 'image/svg+xml';
		}
		return $mimes;
	}

	public function fix_svg_media_library_display() {
		echo '<style>
			.thumbnail img[src$=".svg"], [data-name="view-attachment"] .details img[src$=".svg"] {
				width: 100% !important;
				height: auto !important;
			}
		</style>';
	}

	public function register_svg_settings() {
		register_setting( 
			'timu_svg_settings_group', 
			'timu_svg_options', 
			array(
				'sanitize_callback' => array( $this, 'sanitize_svg_options' ),
			)
		);
	}

	public function sanitize_svg_options( $input ) {
		$new_input = array();
		// If the checkbox is unchecked, it won't be in the $_POST at all.
		$new_input['enabled'] = isset( $input['enabled'] ) ? 1 : 0;
		return $new_input;
	}

	public function create_svg_tools_page() {
		add_management_page(
			__( 'SVG Support Settings', 'thisismyurl-svg-support' ),
			__( 'SVG Support', 'thisismyurl-svg-support' ),
			'manage_options',
			'thisismyurl-svg-support',
			array( $this, 'render_svg_admin_ui' )
		);
	}

	public function render_svg_admin_ui() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$options = get_option( 'timu_svg_options' );
		// Ensure we have a default for the UI if it's never been saved.
		$current_val = isset( $options['enabled'] ) ? $options['enabled'] : 0;
		?>
		<div class="wrap">
			<h1>
				<?php esc_html_e( 'SVG Support', 'thisismyurl-svg-support' ); ?>
				<span style="font-size: 0.5em; font-weight: normal; vertical-align: middle; margin-left: 10px; color: #646970;">
					<?php printf( 
						esc_html__( 'by %s', 'thisismyurl-svg-support' ), 
						'<a href="https://thisismyurl.com/" target="_blank" style="text-decoration: none; color: inherit;">thisismyurl.com</a>' 
					); ?>
				</span>
			</h1>
			
			<div id="poststuff">
				<div id="post-body" class="metabox-holder columns-2">
					<div id="post-body-content">
						<div class="postbox">
							<div class="inside">
								<form method="post" action="options.php">
									<?php settings_fields( 'timu_svg_settings_group' ); ?>
									<table class="form-table">
										<tr>
											<th scope="row"><?php esc_html_e( 'Enable SVG Uploads', 'thisismyurl-svg-support' ); ?></th>
											<td>
												<input type="checkbox" id="timu_svg_enabled" name="timu_svg_options[enabled]" value="1" <?php checked( 1, $current_val ); ?> />
												<label for="timu_svg_enabled"><?php esc_html_e( 'Allow .svg files to be uploaded to the Media Library.', 'thisismyurl-svg-support' ); ?></label>
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
								<p><?php esc_html_e( 'SVG files are XML-based. This plugin enables these files while ensuring thumbnails appear in the dashboard.', 'thisismyurl-svg-support' ); ?></p>
								<hr />
								<p><a href="https://thisismyurl.com/donate/" class="button button-secondary" target="_blank"><?php esc_html_e( 'Donate to Development', 'thisismyurl-svg-support' ); ?></a></p>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}

new TIMU_SVG_Support();

add_action( 'plugins_loaded', function() {
    $updater_path = plugin_dir_path( __FILE__ ) . 'updater.php';
    if ( file_exists( $updater_path ) ) {
        require_once $updater_path;
        if ( class_exists( 'FWO_GitHub_Updater' ) ) {
            new FWO_GitHub_Updater( array(
                'slug'               => 'thisismyurl-svg-support',
                'proper_folder_name' => 'thisismyurl-svg-support',
                'api_url'            => 'https://api.github.com/repos/thisismyurl/thisismyurl-svg-support/releases/latest',
                'github_url'         => 'https://github.com/thisismyurl/thisismyurl-svg-support',
                'plugin_file'        => __FILE__,
            ) );
        }
    }
});