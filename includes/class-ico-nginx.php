<?php
class ICO_Nginx {
    public static function get_rules() {
        $upload_dir = wp_upload_dir();
        $webp_dir = str_replace( get_home_path(), '', $upload_dir['basedir'] ) . '/webp-converted';
        $avif_dir = str_replace( get_home_path(), '', $upload_dir['basedir'] ) . '/avif-converted';
        $upload_base = str_replace( get_home_path(), '', $upload_dir['basedir'] );

        $rules = '
# BEGIN Image Converter Optimizer
location ~* ^/wp-content/uploads/(.*)\.(png|jpe?g)$ {
    add_header Vary Accept;
    set $avif_path /wp-content/avif-converted/$1.$2.avif;
    set $webp_path /wp-content/webp-converted/$1.$2.webp;

    if ($http_accept ~* "image/avif") {
        try_files $avif_path $uri =404;
    }

    if ($http_accept ~* "image/webp") {
        try_files $webp_path $uri =404;
    }
}
# END Image Converter Optimizer';
        return $rules;
    }
}