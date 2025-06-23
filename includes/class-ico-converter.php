<?php
/**
 * Handles image conversion logic (WebP and AVIF) using GD and ImageMagick.
 *
 * @package    Image_Converter_Optimizer
 * @subpackage Image_Converter_Optimizer/includes
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ICO_Converter {

    /**
     * Converts a WordPress attachment (all its generated sizes) to the specified format.
     *
     * @param int    $attachment_id The ID of the WordPress attachment.
     * @param string $format        The target format ('webp' or 'avif').
     * @param int    $quality       Compression quality (1-100).
     * @return array|WP_Error An array of results for each converted size, or WP_Error on failure.
     */
    public static function convert_image( $attachment_id, $format = 'webp', $quality = 82 ) {
        // Ensure WP_Filesystem is loaded for file operations.
        require_once( ABSPATH . 'wp-admin/includes/file.php' );
        WP_Filesystem();
        global $wp_filesystem;

        if ( ! in_array( $format, array( 'webp', 'avif' ) ) ) {
            return new WP_Error( 'ico_invalid_format', 'Invalid format specified.' );
        }

        $file_path = get_attached_file( $attachment_id ); // Path to the full-size original image.
        // Use WP_Filesystem::exists
        if ( ! $file_path || ! $wp_filesystem->exists( $file_path ) ) {
            return new WP_Error( 'ico_file_not_found', 'Original image file not found for attachment ID ' . $attachment_id . '.' );
        }

        // Check server support for the target format
        if ( 'webp' === $format && ! ICO_Compatibility::supports_webp() ) {
            return new WP_Error('ico_no_webp_support', 'WebP is not supported on this server. Conversion skipped.');
        }
        if ( 'avif' === $format && ! ICO_Compatibility::supports_avif() ) {
            return new WP_Error('ico_no_avif_support', 'AVIF is not supported on this server. Conversion skipped.');
        }

        $upload_dir = wp_upload_dir();
        $converted_dir_base = $upload_dir['basedir'] . '/' . $format . '-converted';

        $metadata = wp_get_attachment_metadata( $attachment_id );
        if ( ! $metadata || ! isset( $metadata['file'] ) ) {
            return new WP_Error( 'ico_no_metadata', 'Could not retrieve image metadata for attachment ID ' . $attachment_id . '.' );
        }

        $converted_files = [];
        $original_upload_path = dirname( $file_path );

        $images_to_process = [
            'full' => [
                'file' => basename( $file_path ),
                'path' => $file_path,
            ],
        ];
        if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
            foreach ( $metadata['sizes'] as $size_name => $size_data ) {
                if ( ! empty( $size_data['file'] ) ) {
                    $images_to_process[ $size_name ] = [
                        'file' => $size_data['file'],
                        'path' => trailingslashit( $original_upload_path ) . $size_data['file'],
                    ];
                }
            }
        }

        // Get conditional conversion settings
        $ico_options = get_option(ICO_SETTINGS_SLUG);
        $conditional_conversion_enabled = isset($ico_options['conditional_conversion_enabled']) && $ico_options['conditional_conversion_enabled'];
        $min_savings_percentage = isset($ico_options['min_savings_percentage']) ? (float) $ico_options['min_savings_percentage'] : 0;

        foreach ( $images_to_process as $size_name => $image_info ) {
            $original_filepath = $image_info['path'];

            // Use WP_Filesystem::exists
            if ( ! $wp_filesystem->exists( $original_filepath ) ) {
                $converted_files[ $size_name ] = [
                    'status' => 'failed',
                    'message' => "Original file missing for size '{$size_name}': " . $original_filepath
                ];
                continue;
            }

            $relative_path_segment = str_replace( $upload_dir['basedir'], '', $original_filepath );
            $new_file_path = $converted_dir_base . $relative_path_segment . '.' . $format;

            $new_file_dir = dirname( $new_file_path );
            // Use WP_Filesystem::mkdir
            if ( ! $wp_filesystem->is_dir( $new_file_dir ) ) {
                $wp_filesystem->mkdir( $new_file_dir, 0755 );
            }

            // Use WP_Filesystem::exists
            if ( $wp_filesystem->exists( $new_file_path ) ) {
                $original_size = $wp_filesystem->size( $original_filepath ); // Use WP_Filesystem::size
                $converted_size = $wp_filesystem->size( $new_file_path );   // Use WP_Filesystem::size
                $converted_files[ $size_name ] = [
                    'path'          => $new_file_path,
                    'original_size' => $original_size,
                    'converted_size' => $converted_size,
                    'savings'       => $original_size - $converted_size,
                    'status'        => 'skipped_exists',
                ];
                continue;
            }

            $editor = wp_get_image_editor( $original_filepath ); // wp_get_image_editor is WP_Filesystem aware internally
            if ( is_wp_error( $editor ) ) {
                $converted_files[ $size_name ] = [
                    'status' => 'failed',
                    'message' => $editor->get_error_message() . " for size '{$size_name}'"
                ];
                continue;
            }

            $editor->set_quality( $quality );
            $saved = $editor->save( $new_file_path, 'image/' . $format ); // wp_image_editor::save also uses WP_Filesystem internally

            if ( ! is_wp_error( $saved ) ) {
                $original_size = $wp_filesystem->size( $original_filepath ); // Use WP_Filesystem::size
                $converted_size = $wp_filesystem->size( $saved['path'] );   // Use WP_Filesystem::size

                $is_larger = $converted_size >= $original_size;
                $actual_savings_percentage = ($original_size > 0) ? (($original_size - $converted_size) / $original_size) * 100 : 0;
                $below_threshold = $actual_savings_percentage < $min_savings_percentage;

                if ($conditional_conversion_enabled && ($is_larger || $below_threshold)) {
                    // Use WP_Filesystem::delete to remove the file
                    $wp_filesystem->delete($saved['path']);
                    $converted_files[ $size_name ] = [
                        'path'          => '',
                        'original_size' => $original_size,
                        'converted_size' => 0,
                        'savings'       => 0,
                        'status'        => 'skipped_size',
                        'message'       => "Skipped: Converted file is larger or savings ({$actual_savings_percentage}%) below {$min_savings_percentage}% threshold.",
                    ];
                    continue;
                }

                $converted_files[ $size_name ] = [
                    'path'          => $saved['path'],
                    'original_size' => $original_size,
                    'converted_size' => $converted_size,
                    'savings'       => $original_size - $converted_size,
                    'status'        => 'success',
                ];
            } else {
                $converted_files[ $size_name ] = [
                    'status' => 'failed',
                    'message' => $saved->get_error_message() . " for size '{$size_name}'"
                ];
            }
        }

        return $converted_files;
    }

    /**
     * Checks if the GD library is installed and supports WebP.
     *
     * @return bool
     */
    public static function supports_webp() {
        return ICO_Compatibility::supports_webp();
    }

    /**
     * Checks if the ImageMagick extension is installed and supports AVIF.
     *
     * @return bool
     */
    public static function supports_avif() {
        return ICO_Compatibility::supports_avif();
    }

    /**
     * Deletes all converted WebP and AVIF files by removing and recreating
     * their respective directories.
     *
     * @return int|false Number of directories cleared (0, 1, or 2) on success, false on error.
     */
    public static function delete_all_converted_files() {
        // Ensure WP_Filesystem is loaded for file operations.
        require_once( ABSPATH . 'wp-admin/includes/file.php' );
        WP_Filesystem();
        global $wp_filesystem;

        $upload_dir = wp_upload_dir();
        $webp_dir = $upload_dir['basedir'] . '/webp-converted';
        $avif_dir = $upload_dir['basedir'] . '/avif-converted';

        $deleted_count = 0;

        // Use WP_Filesystem::rmdir( $path, $recursive = false )
        // It's recommended to check for directory existence first
        if ( $wp_filesystem->is_dir( $webp_dir ) ) {
            if ( $wp_filesystem->rmdir( $webp_dir, true ) ) { // true for recursive deletion
                $deleted_count++;
            } else {
                error_log( 'ICO Error: Failed to delete WebP converted directory: ' . $webp_dir );
                return false;
            }
        }
        // Always try to recreate the directory, even if it didn't exist or deletion failed (to ensure it's there)
        if ( ! $wp_filesystem->is_dir( $webp_dir ) ) {
            if ( ! $wp_filesystem->mkdir( $webp_dir, 0755 ) ) {
                error_log( 'ICO Error: Failed to recreate WebP directory after deletion: ' . $webp_dir );
                return false;
            }
        }


        if ( $wp_filesystem->is_dir( $avif_dir ) ) {
            if ( $wp_filesystem->rmdir( $avif_dir, true ) ) { // true for recursive deletion
                $deleted_count++;
            } else {
                error_log( 'ICO Error: Failed to delete AVIF converted directory: ' . $avif_dir );
                return false;
            }
        }
        if ( ! $wp_filesystem->is_dir( $avif_dir ) ) {
            if ( ! $wp_filesystem->mkdir( $avif_dir, 0755 ) ) {
                error_log( 'ICO Error: Failed to recreate AVIF directory after deletion: ' . $avif_dir );
                return false;
            }
        }

        return $deleted_count;
    }
}