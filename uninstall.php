<?php
/**
 * Removes plugin options when the plugin is deleted from the dashboard.
 *
 * @package SB_WebP_Upload_Optimizer
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'sb_webp_upload_optimizer_settings' );
delete_option( 'webp_upload_optimizer_settings' );
delete_transient( 'sb_webp_upload_optimizer_bulk_skip' );
delete_transient( 'webp_upload_optimizer_bulk_skip' );
