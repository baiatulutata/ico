<?php
/**
 * Admin interface, menus, settings, and script enqueuing.
 *
 * @package    Image_Converter_Optimizer
 * @subpackage Image_Converter_Optimizer/includes
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ICO_Admin {

    /**
     * Adds the plugin's admin menu and submenus under "Tools".
     */
    public static function add_admin_menu() {
        add_management_page(
            __( 'Image Converter', 'image-converter-optimizer' ), // Page title
            __( 'Image Converter', 'image-converter-optimizer' ), // Menu title
            'manage_options', // Capability required
            'ico-dashboard', // Menu slug
            array( __CLASS__, 'display_dashboard_page' ) // Callback function
        );

        // Submenu for Dashboard (main entry)
        add_submenu_page(
            'ico-dashboard',
            __( 'Dashboard', 'image-converter-optimizer' ),
            __( 'Dashboard', 'image-converter-optimizer' ),
            'manage_options',
            'ico-dashboard',
            array( __CLASS__, 'display_dashboard_page' )
        );

        // Submenu for Bulk Conversion (if you want a separate page later, currently dashboard covers)
        // add_submenu_page(
        //     'ico-dashboard',
        //     __( 'Bulk Conversion', 'image-converter-optimizer' ),
        //     __( 'Bulk Conversion', 'image-converter-optimizer' ),
        //     'manage_options',
        //     'ico-bulk-conversion',
        //     array( __CLASS__, 'display_bulk_conversion_page' )
        // );

        // Submenu for Settings
        add_submenu_page(
            'ico-dashboard',
            __( 'Settings', 'image-converter-optimizer' ),
            __( 'Settings', 'image-converter-optimizer' ),
            'manage_options',
            'ico-settings',
            array( __CLASS__, 'display_settings_page' )
        );

        // Submenu for Logs (placeholder)
        add_submenu_page(
            'ico-dashboard',
            __( 'Conversion Logs', 'image-converter-optimizer' ),
            __( 'Logs', 'image-converter-optimizer' ),
            'manage_options',
            'ico-logs',
            array( __CLASS__, 'display_logs_page' )
        );
    }

    /**
     * Renders the Dashboard page template.
     */
    public static function display_dashboard_page() {
        include_once ICO_PLUGIN_DIR . 'templates/admin-dashboard.php';
    }

    /**
     * Renders the Bulk Conversion page template (currently combined with dashboard).
     */
    public static function display_bulk_conversion_page() {
        // If you uncommented the bulk conversion submenu, define its template here.
        // For now, it's conceptually part of the dashboard.
        // include_once ICO_PLUGIN_DIR . 'templates/admin-bulk-conversion.php';
    }

    /**
     * Renders the Settings page template.
     */
    public static function display_settings_page() {
        include_once ICO_PLUGIN_DIR . 'templates/admin-settings.php';
    }

    /**
     * Renders the Logs page template (placeholder).
     */
    public static function display_logs_page() {
        // Future feature: detailed conversion logs.
        include_once ICO_PLUGIN_DIR . 'templates/admin-logs.php';
    }

    /**
     * Enqueues admin-specific styles and scripts.
     *
     * @param string $hook The current admin page hook.
     */
    public static function enqueue_scripts( $hook ) {
        // Only enqueue on our plugin pages (identified by slug prefix)
        if ( strpos( $hook, 'ico-' ) === false && strpos( $hook, '_page_ico-' ) === false ) {
            return;
        }

        wp_enqueue_style( 'ico-admin-style', ICO_PLUGIN_URL . 'assets/css/admin-style.css', array(), ICO_VERSION );
        wp_enqueue_script( 'ico-admin-script', ICO_PLUGIN_URL . 'assets/js/admin-script.js', array( 'jquery' ), ICO_VERSION, true );

        // Localize script with AJAX URL and Nonce for REST API calls
        wp_localize_script( 'ico-admin-script', 'ico_ajax_obj', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ), // For legacy AJAX (not used much now)
            'rest_url' => esc_url_raw( rest_url() ),     // WordPress REST API base URL
            'nonce'    => wp_create_nonce( 'wp_rest' ),  // Nonce for REST API authentication
        ) );
    }

    /**
     * Registers plugin settings using the WordPress Settings API.
     */
    public static function register_settings() {
        // Register the main settings group for the plugin
        register_setting(
            ICO_SETTINGS_SLUG, // Option group name
            ICO_SETTINGS_SLUG, // Option name (will store all settings as an array)
            array( 'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ) ) // Sanitization callback
        );

        // Section for Conversion Quality settings
        add_settings_section(
            'ico_quality_section', // ID
            __( 'Conversion Quality', 'image-converter-optimizer' ), // Title
            null, // Callback (no description needed)
            'ico-settings' // Page slug where section appears
        );
        add_settings_field(
            'webp_quality', // ID of the field
            __( 'WebP Quality', 'image-converter-optimizer' ), // Label
            array( __CLASS__, 'render_number_field' ), // Callback to render field
            'ico-settings', // Page slug
            'ico_quality_section', // Section ID
            array( 'name' => 'webp_quality', 'label' => __( 'WebP Quality (1-100)', 'image-converter-optimizer' ), 'min' => 1, 'max' => 100, 'step' => 1 ) // Args for callback
        );
        add_settings_field(
            'avif_quality', // ID
            __( 'AVIF Quality', 'image-converter-optimizer' ), // Label
            array( __CLASS__, 'render_number_field' ), // Callback
            'ico-settings', // Page slug
            'ico_quality_section', // Section ID
            array( 'name' => 'avif_quality', 'label' => __( 'AVIF Quality (1-100)', 'image-converter-optimizer' ), 'min' => 1, 'max' => 100, 'step' => 1 ) // Args
        );

        // Section for Conditional Conversion settings
        add_settings_section(
            'ico_conditional_conversion_section',
            __( 'Conditional Conversion', 'image-converter-optimizer' ),
            null,
            'ico-settings'
        );
        add_settings_field(
            'conditional_conversion_enabled',
            __( 'Enable Conditional Conversion', 'image-converter-optimizer' ),
            array( __CLASS__, 'render_checkbox_field' ),
            'ico-settings',
            'ico_conditional_conversion_section',
            array( 'name' => 'conditional_conversion_enabled', 'label' => __( 'Skip conversion if file is larger or savings are too low.', 'image-converter-optimizer' ) )
        );
        add_settings_field(
            'min_savings_percentage',
            __( 'Minimum Savings Percentage', 'image-converter-optimizer' ),
            array( __CLASS__, 'render_number_field' ),
            'ico-settings',
            'ico_conditional_conversion_section',
            array( 'name' => 'min_savings_percentage', 'label' => __( 'Only save if converted file is at least X% smaller than original (0-100).', 'image-converter-optimizer' ), 'min' => 0, 'max' => 100, 'step' => 0.1 )
        );
    }

    /**
     * Renders a number input field for settings.
     *
     * @param array $args Arguments for the field.
     */
    public static function render_number_field( $args ) {
        $options = get_option( ICO_SETTINGS_SLUG );
        $value = isset( $options[ $args['name'] ] ) ? (float) $options[ $args['name'] ] : ( $args['name'] === 'min_savings_percentage' ? 0 : 80 ); // Default for quality vs savings
        $min = isset($args['min']) ? 'min="' . esc_attr($args['min']) . '"' : '';
        $max = isset($args['max']) ? 'max="' . esc_attr($args['max']) . '"' : '';
        $step = isset($args['step']) ? 'step="' . esc_attr($args['step']) . '"' : '';

        echo "<input type='number' id='" . esc_attr( $args['name'] ) . "' name='" . esc_attr( ICO_SETTINGS_SLUG ) . "[" . esc_attr( $args['name'] ) . "]' value='" . esc_attr( $value ) . "' {$min} {$max} {$step} />";
        if ($args['name'] === 'min_savings_percentage') {
            echo "%";
        }
        echo "<p class='description'>" . esc_html( $args['label'] ) . "</p>";
    }

    /**
     * Renders a checkbox field for settings.
     *
     * @param array $args Arguments for the field.
     */
    public static function render_checkbox_field( $args ) {
        $options = get_option( ICO_SETTINGS_SLUG );
        $checked = isset( $options[ $args['name'] ] ) && $options[ $args['name'] ];
        echo "<input type='checkbox' id='" . esc_attr( $args['name'] ) . "' name='" . esc_attr( ICO_SETTINGS_SLUG ) . "[" . esc_attr( $args['name'] ) . "]' value='1' " . checked( 1, $checked, false ) . " />";
        echo "<label for='" . esc_attr( $args['name'] ) . "'>" . esc_html( $args['label'] ) . "</label>";
    }

    /**
     * Sanitizes plugin settings on save.
     *
     * @param array $input The raw input from the settings form.
     * @return array The sanitized input.
     */
    public static function sanitize_settings( $input ) {
        $sanitized_input = array();

        // Retrieve current options to preserve those not submitted via the current form
        $old_options = get_option(ICO_SETTINGS_SLUG, array());
        $sanitized_input = array_merge($old_options, $sanitized_input); // Start with old options

        // Sanitize quality settings
        if ( isset( $input['webp_quality'] ) ) {
            $sanitized_input['webp_quality'] = absint( $input['webp_quality'] );
            if ($sanitized_input['webp_quality'] < 1) $sanitized_input['webp_quality'] = 1;
            if ($sanitized_input['webp_quality'] > 100) $sanitized_input['webp_quality'] = 100;
        }
        if ( isset( $input['avif_quality'] ) ) {
            $sanitized_input['avif_quality'] = absint( $input['avif_quality'] );
            if ($sanitized_input['avif_quality'] < 1) $sanitized_input['avif_quality'] = 1;
            if ($sanitized_input['avif_quality'] > 100) $sanitized_input['avif_quality'] = 100;
        }

        // Sanitize Conditional Conversion settings
        $sanitized_input['conditional_conversion_enabled'] = isset( $input['conditional_conversion_enabled'] ) ? true : false;
        if ( isset( $input['min_savings_percentage'] ) ) {
            $sanitized_input['min_savings_percentage'] = floatval( $input['min_savings_percentage'] );
            if ($sanitized_input['min_savings_percentage'] < 0) $sanitized_input['min_savings_percentage'] = 0;
            if ($sanitized_input['min_savings_percentage'] > 100) $sanitized_input['min_savings_percentage'] = 100;
        }

        return $sanitized_input;
    }
}