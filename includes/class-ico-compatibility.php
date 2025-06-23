<?php
/**
 * Checks for server compatibility and requirements for the plugin.
 *
 * @package    Image_Converter_Optimizer
 * @subpackage Image_Converter_Optimizer/includes
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ICO_Compatibility {

    /**
     * Get a list of all compatibility checks and their statuses.
     *
     * @return array An associative array of checks, statuses, and messages.
     */
    public static function get_all_checks() {
        return [
            'php_version' => [
                'label'    => 'PHP Version',
                'check'    => self::check_php_version(),
                'required' => '7.4+',
                'current'  => PHP_VERSION,
                'message'  => 'A modern PHP version is recommended for performance and security.',
            ],
            'gd_installed' => [
                'label'    => 'GD Library',
                'check'    => self::is_gd_installed(),
                'required' => 'Installed',
                'current'  => self::is_gd_installed() ? 'Installed' : 'Not Installed',
                'message'  => 'The GD library is required for image manipulation.',
            ],
            'webp_support' => [
                'label'    => 'WebP Support (in GD)',
                'check'    => self::supports_webp(),
                'required' => 'Enabled',
                'current'  => self::supports_webp() ? 'Enabled' : 'Disabled',
                'message'  => 'WebP support is needed to create .webp images. This is standard in most modern PHP/GD installations.',
            ],
            'imagemagick_installed' => [
                'label'    => 'ImageMagick Extension',
                'check'    => self::is_imagemagick_installed(),
                'required' => 'Installed',
                'current'  => self::is_imagemagick_installed() ? 'Installed' : 'Not Installed',
                'message'  => 'ImageMagick is the recommended library for the best quality and format support, including AVIF.',
            ],
            'avif_support' => [
                'label'    => 'AVIF Support (in ImageMagick)',
                'check'    => self::supports_avif(),
                'required' => 'Enabled',
                'current'  => self::supports_avif() ? 'Enabled' : 'Disabled',
                'message'  => 'AVIF support is required to create .avif images. This requires a recent version of ImageMagick compiled with AVIF support.',
            ],
            'htaccess_writable' => [
                'label'    => '.htaccess Writable',
                'check'    => self::is_htaccess_writable(),
                'required' => 'Writable',
                'current'  => self::is_htaccess_writable() ? 'Writable' : 'Not Writable',
                'message'  => 'A writable .htaccess file is needed for automatic serving rules on Apache servers. If not writable, you must add the rules manually.',
            ],
            'upload_dirs_writable' => [
                'label'    => 'Upload Directories',
                'check'    => self::are_upload_dirs_writable(),
                'required' => 'Writable',
                'current'  => self::are_upload_dirs_writable() ? 'Writable' : 'Not Writable',
                'message'  => 'The plugin needs to create `webp-converted` and `avif-converted` directories inside `wp-content/uploads`.',
            ],
        ];
    }

    /**
     * Checks if the PHP version is sufficient.
     * @return bool
     */
    public static function check_php_version() {
        return version_compare( PHP_VERSION, '7.4', '>=' );
    }

    /**
     * Checks if the GD library extension is installed.
     * @return bool
     */
    public static function is_gd_installed() {
        return extension_loaded( 'gd' ) && function_exists( 'gd_info' );
    }

    /**
     * Checks if the installed GD library has WebP support.
     * @return bool
     */
    public static function supports_webp() {
        if ( ! self::is_gd_installed() ) {
            return false;
        }
        $gd_info = gd_info();
        return isset( $gd_info['WebP Support'] ) && $gd_info['WebP Support'];
    }

    /**
     * Checks if the ImageMagick extension is installed.
     * @return bool
     */
    public static function is_imagemagick_installed() {
        return extension_loaded( 'imagick' ) && class_exists( 'Imagick' );
    }

    /**
     * Checks if the installed ImageMagick library has AVIF support.
     * @return bool
     */
    public static function supports_avif() {
        if ( ! self::is_imagemagick_installed() ) {
            return false;
        }
        // Imagick::queryFormats() returns an array of supported formats.
        return in_array( 'AVIF', Imagick::queryFormats() );
    }

    /**
     * Checks if the .htaccess file exists and is writable.
     * This is only relevant for Apache servers.
     * @return bool
     */
    public static function is_htaccess_writable() {
        // This check is not relevant for Nginx or IIS.
        if ( strpos( $_SERVER['SERVER_SOFTWARE'], 'Apache' ) === false ) {
            return true; // Not applicable, so we don't block on it.
        }
        $htaccess_file = get_home_path() . '.htaccess';
        return is_writable( $htaccess_file );
    }

    /**
     * Checks if the required directories in wp-content/uploads can be created.
     * @return bool
     */
    public static function are_upload_dirs_writable() {
        $upload_dir = wp_upload_dir();
        return is_writable( $upload_dir['basedir'] );
    }
}