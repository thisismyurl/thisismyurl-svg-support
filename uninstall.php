<?php
/**
 * Uninstaller for SVG Support by thisismyurl.com.
 *
 * Removes plugin options, the sanitization-failure log, and revokes the
 * `upload_svg_files` capability from every role.
 *
 * @package TIMU_SVG_Support
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( false === strpos( WP_UNINSTALL_PLUGIN, 'thisismyurl-svg-support' ) ) {
	return;
}

delete_option( 'timu_svg_options' );
delete_option( 'timu_svg_failure_log' );

// Strip the granted capability from every role. We do not load the main
// plugin file (which would bootstrap hooks); inline the role walk.
if ( function_exists( 'wp_roles' ) ) {
	$timu_svg_roles = wp_roles();
	foreach ( array_keys( $timu_svg_roles->roles ) as $timu_svg_role_slug ) {
		$timu_svg_role = get_role( $timu_svg_role_slug );
		if ( $timu_svg_role && $timu_svg_role->has_cap( 'upload_svg_files' ) ) {
			$timu_svg_role->remove_cap( 'upload_svg_files' );
		}
	}
	unset( $timu_svg_role, $timu_svg_role_slug, $timu_svg_roles );
}
