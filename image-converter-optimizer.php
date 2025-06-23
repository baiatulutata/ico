<?php
/**
 * Plugin Name:       Image Converter & Optimizer
 * Description:       A comprehensive plugin to convert images to WebP and AVIF, optimize delivery with .htaccess/Nginx rules, and provide extensive management tools.
 * Version:           1.0.0
 * Author:            Ionut Baldazar
 * Author URI:        https://woomag.ro
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       image-converter-optimizer
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

define( 'ICO_VERSION', '1.0.0' );
define( 'ICO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ICO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ICO_SETTINGS_SLUG', 'ico_settings' );

/**
 * The core plugin class that orchestrates the entire plugin.
 */
require ICO_PLUGIN_DIR . 'includes/class-ico-core.php';

/**
 * Begins execution of the plugin.
 */
function run_image_converter_optimizer() {
    $plugin = new ICO_Core();
    $plugin->run();
}
run_image_converter_optimizer();

/**
 * Register activation and deactivation hooks.
 */
register_activation_hook( __FILE__, array( 'ICO_Core', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'ICO_Core', 'deactivate' ) );