<?php

/**
 * Uninstaller for svg support
 * .
 * 
 * Updated: 1.251229
 * 
 */


if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( strpos( WP_UNINSTALL_PLUGIN, 'thisismyurl-svg-support' ) !== false ) {
    delete_option( 'timu_svg_options' );
}

