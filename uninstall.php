<?php
// If uninstall.php is not called by WordPress, die.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    die;
}

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
require_once plugin_dir_path( __FILE__ ) . 'includes/class-ico-htaccess.php';
ICO_Htaccess::remove_rules();
flush_rewrite_rules();

// Note: Deleting converted image files (webp-converted/, avif-converted/)
// is a destructive action and is typically not done automatically on uninstall
// to prevent accidental data loss. A user might manually revert them,
// but the "Clear All Converted Images & Logs" button in settings does this.