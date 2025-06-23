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
        // Get relative paths from document root for Nginx rules
        // Nginx paths are typically relative to the server root, or absolute
        // Here, we provide them relative to the WordPress root, assuming wp-content/uploads path structure
        $webp_relative_dir = str_replace( ABSPATH, '/', $upload_dir['basedir'] ) . '/webp-converted';
        $avif_relative_dir = str_replace( ABSPATH, '/', $upload_dir['basedir'] ) . '/avif-converted';

        $rules = <<<EOT
# BEGIN Image Converter Optimizer
location ~* ^/wp-content/uploads/(.*)\.(png|jpe?g)$ {
    # Ensure Vary header is set for Accept for correct caching by CDNs/proxies
    add_header Vary Accept;

    # Set paths for potential WebP and AVIF files
    set \$avif_path "{$avif_relative_dir}/\$1.\$2.avif";
    set \$webp_path "{$webp_relative_dir}/\$1.\$2.webp";

    # Try serving AVIF if browser supports it and AVIF file exists
    if (\$http_accept ~* "image/avif") {
        try_files \$avif_path \$uri;
    }

    # Try serving WebP if browser supports it and WebP file exists
    if (\$http_accept ~* "image/webp") {
        try_files \$webp_path \$uri;
    }

    # Fallback to original image if neither AVIF nor WebP are served
    try_files \$uri =404;
}
# END Image Converter Optimizer
EOT;

        return $rules;
    }
}