<?php
/**
 * Core plugin setup and hooks.
 *
 * @package    Image_Converter_Optimizer
 * @subpackage Image_Converter_Optimizer/includes
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ICO_Core {

    /**
     * Constructor for ICO_Core.
     */
    public function __construct() {
        $this->load_dependencies();
        $this->setup_hooks();
    }

    /**
     * Loads all necessary plugin files.
     */
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

    /**
     * Sets up all WordPress hooks (actions and filters).
     */
    private function setup_hooks() {
        // Admin-specific hooks
        add_action( 'admin_enqueue_scripts', array( 'ICO_Admin', 'enqueue_scripts' ) );
        add_action( 'admin_menu', array( 'ICO_Admin', 'add_admin_menu' ) );
        add_action( 'admin_init', array( 'ICO_Admin', 'register_settings' ) );

        // REST API initialization
        add_action( 'rest_api_init', array( 'ICO_Rest_Api', 'register_routes' ) );

        // Background Process (WP-Cron) initialization
        add_action( 'init', array( 'ICO_Background_Process', 'init' ) );

        // WP-CLI integration
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            ICO_CLI::register_commands();
        }
    }

    /**
     * Plugin activation logic.
     * Creates necessary directories, adds .htaccess rules, and sets default options.
     */
    /**
     * Plugin activation logic.
     * Creates necessary directories, adds .htaccess rules, and sets default options.
     */
    public static function activate() {
        // Ensure WP_Filesystem is loaded for file operations during activation.
        require_once( ABSPATH . 'wp-admin/includes/file.php' );
        WP_Filesystem();
        global $wp_filesystem;

        // Create directories for converted images
        $upload_dir = wp_upload_dir();
        $webp_dir = $upload_dir['basedir'] . '/webp-converted';
        $avif_dir = $upload_dir['basedir'] . '/avif-converted';

        // Use WP_Filesystem::mkdir for directory creation
        if ( ! $wp_filesystem->is_dir( $webp_dir ) ) {
            $wp_filesystem->mkdir( $webp_dir, 0755 ); // 0755 permissions
        }
        if ( ! $wp_filesystem->is_dir( $avif_dir ) ) {
            $wp_filesystem->mkdir( $avif_dir, 0755 ); // 0755 permissions
        }

        // Add .htaccess rules if on Apache
        // ICO_Htaccess::add_rules() internally uses insert_with_markers which is WP_Filesystem aware.
        if ( strpos( $_SERVER['SERVER_SOFTWARE'], 'Apache' ) !== false ) {
            ICO_Htaccess::add_rules();
        }

        // Create custom database table for logs/stats
        ICO_Db::create_table();

        // Set default options if they don't exist
        $defaults = array(
            'webp_quality' => 82,
            'avif_quality' => 50,
            'batch_size' => 25,
            'conditional_conversion_enabled' => true,
            'min_savings_percentage' => 5,
            'lazy_load' => true,
        );
        add_option( ICO_SETTINGS_SLUG, $defaults );

        // Flush rewrite rules to ensure .htaccess changes take effect
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation logic.
     * Removes .htaccess rules and clears scheduled cron jobs.
     */
    public static function deactivate() {
        // Remove .htaccess rules. ICO_Htaccess::remove_rules() is WP_Filesystem aware.
        if ( strpos( $_SERVER['SERVER_SOFTWARE'], 'Apache' ) !== false ) {
            ICO_Htaccess::remove_rules();
        }
        flush_rewrite_rules();

        // Clear scheduled cron jobs
        wp_clear_scheduled_hook('ico_cron_hook');
    }

    /**
     * Runs the plugin. (Can be extended for public-facing hooks)
     */
    public function run() {
        // Public-facing hooks would be defined here if needed
    }
}