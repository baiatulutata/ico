<?php
// If uninstall.php is not called by WordPress, die.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    die;
}

// Ensure WP_Filesystem is loaded for file operations.
require_once( ABSPATH . 'wp-admin/includes/file.php' );
WP_Filesystem();
global $wp_filesystem;

// Clear any scheduled cron jobs associated with the plugin.
wp_clear_scheduled_hook( 'ico_cron_hook' );

// Delete plugin options.
delete_option( 'ico_settings' ); // Your main plugin settings
delete_site_option( 'ico_settings' ); // For multisite

// Delete custom database table.
global $wpdb;
$table_name = $wpdb->prefix . 'ico_conversion_logs';
$wpdb->query( "DROP TABLE IF EXISTS $table_name" );

// Delete conversion status post meta from attachments.
delete_post_meta_by_key( '_ico_converted_status' );

// Remove .htaccess rules.
// This requires ICO_Htaccess class. Ensure it's available or call its logic directly.
// Given it's an uninstall script, it's safer to directly include and call.
require_once plugin_dir_path( __FILE__ ) . 'includes/class-ico-htaccess.php';
ICO_Htaccess::remove_rules(); // This method internally uses insert_with_markers, which is WP_Filesystem aware.
flush_rewrite_rules();

// Delete converted image directories (webp-converted/, avif-converted/).
// Use WP_Filesystem for this as well.
$upload_dir = wp_upload_dir();
$webp_dir = $upload_dir['basedir'] . '/webp-converted';
$avif_dir = $upload_dir['basedir'] . '/avif-converted';

if ( $wp_filesystem->exists( $webp_dir ) ) {
    $wp_filesystem->rmdir( $webp_dir, true ); // true for recursive deletion
}
if ( $wp_filesystem->exists( $avif_dir ) ) {
    $wp_filesystem->rmdir( $avif_dir, true ); // true for recursive deletion
}