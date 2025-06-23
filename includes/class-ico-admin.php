<?php

class ICO_Admin {

    public static function add_admin_menu() {
        add_management_page(
            __( 'Image Converter', 'image-converter-optimizer' ),
            __( 'Image Converter', 'image-converter-optimizer' ),
            'manage_options',
            'ico-dashboard',
            array( __CLASS__, 'display_dashboard_page' )
        );
        add_submenu_page(
            'ico-dashboard',
            __( 'Dashboard', 'image-converter-optimizer' ),
            __( 'Dashboard', 'image-converter-optimizer' ),
            'manage_options',
            'ico-dashboard',
            array( __CLASS__, 'display_dashboard_page' )
        );
        add_submenu_page(
            'ico-dashboard',
            __( 'Bulk Conversion', 'image-converter-optimizer' ),
            __( 'Bulk Conversion', 'image-converter-optimizer' ),
            'manage_options',
            'ico-bulk-conversion',
            array( __CLASS__, 'display_bulk_conversion_page' )
        );
        add_submenu_page(
            'ico-dashboard',
            __( 'Settings', 'image-converter-optimizer' ),
            __( 'Settings', 'image-converter-optimizer' ),
            'manage_options',
            'ico-settings',
            array( __CLASS__, 'display_settings_page' )
        );
        add_submenu_page(
            'ico-dashboard',
            __( 'Logs', 'image-converter-optimizer' ),
            __( 'Logs', 'image-converter-optimizer' ),
            'manage_options',
            'ico-logs',
            array( __CLASS__, 'display_logs_page' )
        );
    }

    public static function display_dashboard_page() { include_once ICO_PLUGIN_DIR . 'templates/admin-dashboard.php'; }
    public static function display_bulk_conversion_page() { include_once ICO_PLUGIN_DIR . 'templates/admin-bulk-conversion.php'; }
    public static function display_settings_page() { include_once ICO_PLUGIN_DIR . 'templates/admin-settings.php'; }
    public static function display_logs_page() { include_once ICO_PLUGIN_DIR . 'templates/admin-logs.php'; }

    public static function enqueue_scripts( $hook ) {
        if ( strpos( $hook, 'ico-' ) === false ) {
            return;
        }

        wp_enqueue_style( 'ico-admin-style', ICO_PLUGIN_URL . 'assets/css/admin-style.css', array(), ICO_VERSION );
        wp_enqueue_script( 'ico-admin-script', ICO_PLUGIN_URL . 'assets/js/admin-script.js', array( 'jquery' ), ICO_VERSION, true );

        wp_localize_script( 'ico-admin-script', 'ico_ajax_obj', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'rest_url' => esc_url_raw( rest_url() ),
            'nonce'    => wp_create_nonce( 'wp_rest' ),
        ) );
    }

    public static function register_settings() {
        register_setting( ICO_SETTINGS_SLUG, ICO_SETTINGS_SLUG, array( 'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ) ) );

        add_settings_section( 'ico_quality_section', 'Conversion Quality', null, 'ico-settings' );
        add_settings_field( 'webp_quality', 'WebP Quality', array( __CLASS__, 'render_quality_field' ), 'ico-settings', 'ico_quality_section', array( 'name' => 'webp_quality', 'label' => 'WebP Quality' ) );
        add_settings_field( 'avif_quality', 'AVIF Quality', array( __CLASS__, 'render_quality_field' ), 'ico-settings', 'ico_quality_section', array( 'name' => 'avif_quality', 'label' => 'AVIF Quality' ) );

        // NEW: Conditional Conversion Settings Section
        add_settings_section( 'ico_conditional_conversion_section', 'Conditional Conversion', null, 'ico-settings' );
        add_settings_field(
            'conditional_conversion_enabled',
            'Enable Conditional Conversion',
            array( __CLASS__, 'render_checkbox_field' ),
            'ico-settings',
            'ico_conditional_conversion_section',
            array( 'name' => 'conditional_conversion_enabled', 'label' => 'Skip conversion if file is larger or savings are too low.' )
        );
        add_settings_field(
            'min_savings_percentage',
            'Minimum Savings Percentage',
            array( __CLASS__, 'render_number_field' ),
            'ico-settings',
            'ico_conditional_conversion_section',
            array( 'name' => 'min_savings_percentage', 'label' => 'Only save if converted file is at least X% smaller than original (0-100).' )
        );
    }

    public static function render_quality_field( $args ) {
        $options = get_option( ICO_SETTINGS_SLUG );
        $value = isset( $options[ $args['name'] ] ) ? absint($options[ $args['name'] ]) : 80;
        echo "<input type='number' name='" . ICO_SETTINGS_SLUG . "[{$args['name']}]' value='{$value}' min='1' max='100' />";
        echo "<p class='description'>Set the quality for {$args['label']} images (1-100).</p>";
    }

    // NEW: Render Checkbox Field
    public static function render_checkbox_field( $args ) {
        $options = get_option( ICO_SETTINGS_SLUG );
        $checked = isset( $options[ $args['name'] ] ) && $options[ $args['name'] ];
        echo "<input type='checkbox' name='" . ICO_SETTINGS_SLUG . "[{$args['name']}]' value='1' " . checked( 1, $checked, false ) . " />";
        echo "<label for='" . ICO_SETTINGS_SLUG . "[{$args['name']}]'>" . esc_html( $args['label'] ) . "</label>";
    }

    // NEW: Render Number Field
    public static function render_number_field( $args ) {
        $options = get_option( ICO_SETTINGS_SLUG );
        $value = isset( $options[ $args['name'] ] ) ? (float) $options[ $args['name'] ] : 0;
        echo "<input type='number' name='" . ICO_SETTINGS_SLUG . "[{$args['name']}]' value='{$value}' min='0' max='100' step='0.1' />%";
        echo "<p class='description'>" . esc_html( $args['label'] ) . "</p>";
    }


    public static function sanitize_settings( $input ) {
        $sanitized_input = array();

        // Preserve existing options not explicitly set on this form submission if needed
        $old_options = get_option(ICO_SETTINGS_SLUG, array());
        $sanitized_input = array_merge($old_options, $sanitized_input);

        if ( isset( $input['webp_quality'] ) ) {
            $sanitized_input['webp_quality'] = absint( $input['webp_quality'] );
        }
        if ( isset( $input['avif_quality'] ) ) {
            $sanitized_input['avif_quality'] = absint( $input['avif_quality'] );
        }

        // NEW: Sanitize Conditional Conversion settings
        $sanitized_input['conditional_conversion_enabled'] = isset( $input['conditional_conversion_enabled'] ) ? true : false;
        if ( isset( $input['min_savings_percentage'] ) ) {
            $sanitized_input['min_savings_percentage'] = floatval( $input['min_savings_percentage'] );
            if ($sanitized_input['min_savings_percentage'] < 0) $sanitized_input['min_savings_percentage'] = 0;
            if ($sanitized_input['min_savings_percentage'] > 100) $sanitized_input['min_savings_percentage'] = 100;
        }

        return $sanitized_input;
    }


}