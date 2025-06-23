<?php

class ICO_Core {

    public function __construct() {
        $this->load_dependencies();
        $this->setup_hooks();
    }

    private function load_dependencies() {
        require_once ICO_PLUGIN_DIR . 'includes/class-ico-admin.php';
        require_once ICO_PLUGIN_DIR . 'includes/class-ico-converter.php';
        require_once ICO_PLUGIN_DIR . 'includes/class-ico-htaccess.php';
        require_once ICO_PLUGIN_DIR . 'includes/class-ico-nginx.php';
        require_once ICO_PLUGIN_DIR . 'includes/class-ico-background-process.php';
        require_once ICO_PLUGIN_DIR . 'includes/class-ico-rest-api.php';
        require_once ICO_PLUGIN_DIR . 'includes/class-ico-db.php';
        require_once ICO_PLUGIN_DIR . 'includes/class-ico-cli.php';
        require_once ICO_PLUGIN_DIR . 'includes/class-ico-compatibility.php';
    }

    private function setup_hooks() {
        add_action( 'admin_enqueue_scripts', array( 'ICO_Admin', 'enqueue_scripts' ) );
        add_action( 'admin_menu', array( 'ICO_Admin', 'add_admin_menu' ) );
        add_action( 'admin_init', array( 'ICO_Admin', 'register_settings' ) );

        // REST API
        add_action( 'rest_api_init', array( 'ICO_Rest_Api', 'register_routes' ) );

        // Background Process
        add_action( 'init', array( 'ICO_Background_Process', 'init' ) );

        // WP-CLI
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            ICO_CLI::register_commands();
        }
    }

    public static function activate() {
        // Create directories for converted images
        $upload_dir = wp_upload_dir();
        $webp_dir = $upload_dir['basedir'] . '/webp-converted';
        $avif_dir = $upload_dir['basedir'] . '/avif-converted';

        if ( ! is_dir( $webp_dir ) ) { wp_mkdir_p( $webp_dir ); }
        if ( ! is_dir( $avif_dir ) ) { wp_mkdir_p( $avif_dir ); }

        // Add .htaccess rules if on Apache
        if ( strpos( $_SERVER['SERVER_SOFTWARE'], 'Apache' ) !== false ) {
            ICO_Htaccess::add_rules();
        }

        // Create database table for logs/stats
        ICO_Db::create_table();

        // Set default options
        $defaults = array(
            'webp_quality' => 82,
            'avif_quality' => 50,
            'batch_size' => 25,
            'lazy_load' => true,
        );
        add_option( ICO_SETTINGS_SLUG, $defaults );

        flush_rewrite_rules();
    }

    public static function deactivate() {
        // Remove .htaccess rules
        if ( strpos( $_SERVER['SERVER_SOFTWARE'], 'Apache' ) !== false ) {
            ICO_Htaccess::remove_rules();
        }
        flush_rewrite_rules();

        // Clear scheduled cron jobs
        wp_clear_scheduled_hook('ico_cron_hook');
    }

    public function run() {
        // The plugin is now running
    }
}