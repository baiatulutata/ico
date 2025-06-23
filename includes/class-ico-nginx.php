<?php
/**
 * Generates Nginx rewrite rules for image serving.
 *
 * @package    Image_Converter_Optimizer
 * @subpackage Image_Converter_Optimizer/includes
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ICO_Nginx {

    /**
     * Generates Nginx configuration rules for WebP and AVIF serving.
     * These rules need to be manually added to the Nginx server block.
     *
     * @return string The Nginx rules.
     */
    public static function get_rules() {
        $upload_dir = wp_upload_dir();
        $webp_relative_dir = str_replace( ABSPATH, '/', $upload_dir['basedir'] ) . '/webp-converted';
        $avif_relative_dir = str_replace( ABSPATH, '/', $upload_dir['basedir'] ) . '/avif-converted';

        // Use standard strings with newlines instead of heredoc
        $rules = "# BEGIN Image Converter Optimizer\n" .
            "location ~* ^/wp-content/uploads/(.*)\\.(png|jpe?g)$ {\n" .
            "    add_header Vary Accept;\n" .
            "    set \$avif_path \"" . esc_attr($avif_relative_dir) . "/\$1.\$2.avif\";\n" .
            "    set \$webp_path \"" . esc_attr($webp_relative_dir) . "/\$1.\$2.webp\";\n\n" .
            "    if (\$http_accept ~* \"image/avif\") {\n" .
            "        try_files \$avif_path \$uri =404;\n" .
            "    }\n\n" .
            "    if (\$http_accept ~* \"image/webp\") {\n" .
            "        try_files \$webp_path \$uri =404;\n" .
            "    }\n\n" .
            "    try_files \$uri =404;\n" .
            "}\n" .
            "# END Image Converter Optimizer";

        return $rules;
    }
}