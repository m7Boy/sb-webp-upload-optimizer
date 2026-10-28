<?php
/**
 * Plugin Name:       SB WebP Upload Optimizer
 * Plugin URI:        https://softwarebrewery.net
 * Description:       Converts JPEG, PNG, and HEIC uploads to WebP and limits the full-size image to a maximum edge length. Developed by Software Brewery.
 * Version:           1.0.1
 * Requires at least: 6.7
 * Requires PHP:      7.4
 * Author:            Software Brewery
 * Author URI:        https://softwarebrewery.net
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sb-webp-upload-optimizer
 * Domain Path:       /languages
 *
 * @package SB_WebP_Upload_Optimizer
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SB_WEBP_UPLOAD_OPTIMIZER_FILE', __FILE__ );
define( 'SB_WEBP_UPLOAD_OPTIMIZER_VERSION', '1.0.1' );
define( 'SB_WEBP_UPLOAD_OPTIMIZER_AUTHOR_URL', 'https://softwarebrewery.net' );

require_once __DIR__ . '/includes/class-settings.php';
require_once __DIR__ . '/includes/class-converter.php';
require_once __DIR__ . '/includes/class-admin.php';

/**
 * Boots the plugin.
 */
function sb_webp_upload_optimizer_boot(): void {
	$legacy = get_option( 'webp_upload_optimizer_settings', null );
	if ( is_array( $legacy ) && false === get_option( 'sb_webp_upload_optimizer_settings', false ) ) {
		update_option( 'sb_webp_upload_optimizer_settings', $legacy );
		delete_option( 'webp_upload_optimizer_settings' );
	}

	$settings  = new SB_WebP_Upload_Optimizer_Settings();
	$converter = new SB_WebP_Upload_Optimizer_Converter( $settings );
	$converter->register();

	if ( is_admin() ) {
		$admin = new SB_WebP_Upload_Optimizer_Admin( $settings, $converter );
		$admin->register();
	}
}
add_action( 'plugins_loaded', 'sb_webp_upload_optimizer_boot' );
