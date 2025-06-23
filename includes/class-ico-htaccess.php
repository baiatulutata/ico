<?php

class ICO_Htaccess {

    public static function add_rules() {
        $htaccess_file = get_home_path() . '.htaccess';
        if ( is_writable( $htaccess_file ) ) {
            $rules = self::get_rules();
            insert_with_markers( $htaccess_file, 'Image Converter Optimizer', $rules );
        }
    }

    public static function remove_rules() {
        $htaccess_file = get_home_path() . '.htaccess';
        if ( is_writable( $htaccess_file ) ) {
            insert_with_markers( $htaccess_file, 'Image Converter Optimizer', '' );
        }
    }

    private static function get_rules() {
        $upload_dir = wp_upload_dir();
        $webp_dir = str_replace( get_home_path(), '', $upload_dir['basedir'] ) . '/webp-converted';
        $avif_dir = str_replace( get_home_path(), '', $upload_dir['basedir'] ) . '/avif-converted';
        $upload_base = str_replace( get_home_path(), '', $upload_dir['basedir'] );

        $rules = <<<EOT
<IfModule mod_rewrite.c>
RewriteEngine On
# Serve AVIF if supported
RewriteCond %{HTTP_ACCEPT} image/avif
RewriteCond %{DOCUMENT_ROOT}/{$avif_dir}/$1.avif -f
RewriteRule ^(wp-content/uploads/.*)\.(jpe?g|png)$ /{$avif_dir}/$1.$2.avif [T=image/avif,E=avif:1,L]

# Serve WebP if supported and AVIF was not served
RewriteCond %{HTTP_ACCEPT} image/webp
RewriteCond %{DOCUMENT_ROOT}/{$webp_dir}/$1.webp -f
RewriteCond %{ENV:avif} !1
RewriteRule ^(wp-content/uploads/.*)\.(jpe?g|png)$ /{$webp_dir}/$1.$2.webp [T=image/webp,L]

<IfModule mod_headers.c>
Header append Vary Accept env=REDIRECT_ACCEPT
</IfModule>
</IfModule>
EOT;

        return $rules;
    }
}