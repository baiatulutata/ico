<?php
// if uninstall.php is not called by WordPress, die
if (!defined('WP_UNINSTALL_PLUGIN')) {
    die;
}

// Clear any scheduled cron jobs
$timestamp = wp_next_scheduled('ico_cron_hook');
wp_unschedule_event($timestamp, 'ico_cron_hook');

// Remove options
delete_option('ico_settings');

// Note: This example does not remove the converted images or the htaccess rules
// to prevent accidental data loss. A production plugin might offer this as an option.