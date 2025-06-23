<?php
/**
 * Manages .htaccess rewrite rules for image serving.
 *
 * @package    Image_Converter_Optimizer
 * @subpackage Image_Converter_Optimizer/includes
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ICO_Htaccess {

    /**
     * Retrieves the .htaccess rewrite rules for WebP and AVIF serving.
     *
     * @return string The .htaccess rules.
     */
    private static function get_rules() {
        $upload_dir = wp_upload_dir();
        $webp_relative_dir = str_replace( get_home_path(), '', $upload_dir['basedir'] ) . '/webp-converted';
        $avif_relative_dir = str_replace( get_home_path(), '', $upload_dir['basedir'] ) . '/avif-converted';

        // Use standard strings with newlines instead of heredoc
        $rules = "<IfModule mod_rewrite.c>\n" .
            "RewriteEngine On\n" .
            "RewriteBase /\n\n" .
            "# Serve AVIF if browser supports it and AVIF version exists\n" .
            "RewriteCond %{HTTP_ACCEPT} image/avif\n" .
            "RewriteCond %{DOCUMENT_ROOT}{$avif_relative_dir}/$1.$2.avif -f\n" .
            "RewriteRule ^(wp-content/uploads/.*)\\.(jpe?g|png)$ {$avif_relative_dir}/$1.$2.avif [T=image/avif,E=avif:1,L]\n\n" .
            "# Serve WebP if browser supports it, AVIF was NOT served, and WebP version exists\n" .
            "RewriteCond %{HTTP_ACCEPT} image/webp\n" .
            "RewriteCond %{DOCUMENT_ROOT}{$webp_relative_dir}/$1.$2.webp -f\n" .
            "RewriteCond %{ENV:avif} !1\n" .
            "RewriteRule ^(wp-content/uploads/.*)\\.(jpe?g|png)$ {$webp_relative_dir}/$1.$2.webp [T=image/webp,L]\n\n" .
            "<IfModule mod_headers.c>\n" .
            "Header append Vary Accept env=REDIRECT_ACCEPT\n" .
            "</IfModule>\n" .
            "</IfModule>";

        return $rules;
    }

    /**
     * Adds the plugin's .htaccess rules to the main .htaccess file.
     * Uses WordPress's insert_with_markers for safe management, which is WP_Filesystem aware.
     */
    public static function add_rules() {
        // Ensure WP_Filesystem is loaded before using it or WP_Filesystem-aware functions.
        require_once( ABSPATH . 'wp-admin/includes/file.php' );
        WP_Filesystem();
        global $wp_filesystem;

        $htaccess_file = get_home_path() . '.htaccess';
        // Use WP_Filesystem::exists and is_writable
        if ( $wp_filesystem->exists( $htaccess_file ) && $wp_filesystem->is_writable( $htaccess_file ) ) {
            $rules_array = explode( "\n", self::get_rules() );
            insert_with_markers( $htaccess_file, 'Image Converter Optimizer', $rules_array );
        } else {
            error_log( 'ICO Error: .htaccess file not found or not writable at: ' . $htaccess_file );
        }
    }

    /**
     * Removes the plugin's .htaccess rules from the main .htaccess file.
     * Uses WordPress's insert_with_markers for safe removal, which is WP_Filesystem aware.
     */
    public static function remove_rules() {
        // Ensure WP_Filesystem is loaded.
        require_once( ABSPATH . 'wp-admin/includes/file.php' );
        WP_Filesystem();
        global $wp_filesystem;

        $htaccess_file = get_home_path() . '.htaccess';
        // Use WP_Filesystem::exists and is_writable
        if ( $wp_filesystem->exists( $htaccess_file ) && $wp_filesystem->is_writable( $htaccess_file ) ) {
            insert_with_markers( $htaccess_file, 'Image Converter Optimizer', '' );
        } else {
            error_log( 'ICO Error: .htaccess file not found or not writable at: ' . $htaccess_file );
        }
    }
}