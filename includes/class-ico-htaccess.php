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
        // Get relative paths from document root for .htaccess rules
        $webp_relative_dir = str_replace( get_home_path(), '', $upload_dir['basedir'] ) . '/webp-converted';
        $avif_relative_dir = str_replace( get_home_path(), '', $upload_dir['basedir'] ) . '/avif-converted';
        // Note: The $upload_base variable was not actually used in the rules.

        // These rules prioritize AVIF, then WebP, then fall back to original JPEG/PNG.
        // They use 'T' flag to set Content-Type and 'E' flag to set environment variable.
        $rules = <<<EOT
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteBase /

# Serve AVIF if browser supports it and AVIF version exists
RewriteCond %{HTTP_ACCEPT} image/avif
RewriteCond %{DOCUMENT_ROOT}{$avif_relative_dir}/$1.$2.avif -f
RewriteRule ^(wp-content/uploads/.*)\.(jpe?g|png)$ {$avif_relative_dir}/$1.$2.avif [T=image/avif,E=avif:1,L]

# Serve WebP if browser supports it, AVIF was NOT served, and WebP version exists
RewriteCond %{HTTP_ACCEPT} image/webp
RewriteCond %{DOCUMENT_ROOT}{$webp_relative_dir}/$1.$2.webp -f
RewriteCond %{ENV:avif} !1
RewriteRule ^(wp-content/uploads/.*)\.(jpe?g|png)$ {$webp_relative_dir}/$1.$2.webp [T=image/webp,L]

<IfModule mod_headers.c>
# Add Vary header for Accept to ensure correct caching for different image formats
Header append Vary Accept env=REDIRECT_ACCEPT
</IfModule>
</IfModule>
EOT;

        return $rules;
    }

    /**
     * Adds the plugin's .htaccess rules to the main .htaccess file.
     * Uses WordPress's insert_with_markers for safe management.
     */
    public static function add_rules() {
        $htaccess_file = get_home_path() . '.htaccess';
        if ( file_exists( $htaccess_file ) && is_writable( $htaccess_file ) ) {
            $rules = self::get_rules();
            // insert_with_markers adds unique BEGIN/END markers to manage the block
            insert_with_markers( $htaccess_file, 'Image Converter Optimizer', explode( "\n", $rules ) );
        } else {
            error_log( 'ICO Error: .htaccess file not found or not writable at: ' . $htaccess_file );
        }
    }

    /**
     * Removes the plugin's .htaccess rules from the main .htaccess file.
     * Uses WordPress's insert_with_markers for safe removal.
     */
    public static function remove_rules() {
        $htaccess_file = get_home_path() . '.htaccess';
        if ( file_exists( $htaccess_file ) && is_writable( $htaccess_file ) ) {
            // Passing an empty array removes the block marked by 'Image Converter Optimizer'
            insert_with_markers( $htaccess_file, 'Image Converter Optimizer', '' );
        } else {
            error_log( 'ICO Error: .htaccess file not found or not writable at: ' . $htaccess_file );
        }
    }
}