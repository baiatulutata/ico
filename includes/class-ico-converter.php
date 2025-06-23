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
        if ( ! in_array( $format, array( 'webp', 'avif' ) ) ) {
            return new WP_Error( 'ico_invalid_format', 'Invalid format specified.' );
        }

        $file_path = get_attached_file( $attachment_id ); // Path to the full-size original image.
        if ( ! $file_path || ! file_exists( $file_path ) ) {
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
        // Base directory for converted images (e.g., /wp-content/uploads/webp-converted)
        $converted_dir_base = $upload_dir['basedir'] . '/' . $format . '-converted';

        $metadata = wp_get_attachment_metadata( $attachment_id );
        if ( ! $metadata || ! isset( $metadata['file'] ) ) {
            return new WP_Error( 'ico_no_metadata', 'Could not retrieve image metadata for attachment ID ' . $attachment_id . '.' );
        }

        $converted_files = []; // Stores results for each size processed
        $original_upload_path = dirname( $file_path ); // Directory of the original image and its sizes

        // Prepare list of all image sizes to process (including 'full')
        $images_to_process = [
            'full' => [
                'file' => basename( $file_path ),
                'path' => $file_path, // Full path to the original file
            ],
        ];
        if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
            foreach ( $metadata['sizes'] as $size_name => $size_data ) {
                if ( ! empty( $size_data['file'] ) ) {
                    $images_to_process[ $size_name ] = [
                        'file' => $size_data['file'],
                        'path' => trailingslashit( $original_upload_path ) . $size_data['file'], // Full path for intermediate sizes
                    ];
                }
            }
        }

        // Get conditional conversion settings from plugin options
        $ico_options = get_option(ICO_SETTINGS_SLUG);
        $conditional_conversion_enabled = isset($ico_options['conditional_conversion_enabled']) && $ico_options['conditional_conversion_enabled'];
        $min_savings_percentage = isset($ico_options['min_savings_percentage']) ? (float) $ico_options['min_savings_percentage'] : 0;

        foreach ( $images_to_process as $size_name => $image_info ) {
            $original_filepath = $image_info['path'];

            // Ensure the original file for this size actually exists
            if ( ! file_exists( $original_filepath ) ) {
                $converted_files[ $size_name ] = [
                    'status' => 'failed',
                    'message' => "Original file missing for size '{$size_name}': " . $original_filepath
                ];
                continue; // Skip to the next size
            }

            // Construct the path for the new converted image
            // E.g., /wp-content/uploads/webp-converted/2025/06/my-image-150x150.jpg.webp
            $relative_path_segment = str_replace( $upload_dir['basedir'], '', $original_filepath );
            $new_file_path = $converted_dir_base . $relative_path_segment . '.' . $format;

            // Ensure the directory for the new converted image exists
            $new_file_dir = dirname( $new_file_path );
            if ( ! is_dir( $new_file_dir ) ) {
                wp_mkdir_p( $new_file_dir );
            }

            // Check if the converted file already exists to avoid redundant conversions
            if ( file_exists( $new_file_path ) ) {
                $original_size = filesize( $original_filepath );
                $converted_size = filesize( $new_file_path );
                $converted_files[ $size_name ] = [
                    'path'          => $new_file_path,
                    'original_size' => $original_size,
                    'converted_size' => $converted_size,
                    'savings'       => $original_size - $converted_size,
                    'status'        => 'skipped_exists', // Specific status for logging
                ];
                continue; // Skip to the next size
            }

            // Get WordPress image editor instance
            $editor = wp_get_image_editor( $original_filepath );
            if ( is_wp_error( $editor ) ) {
                $converted_files[ $size_name ] = [
                    'status' => 'failed',
                    'message' => $editor->get_error_message() . " for size '{$size_name}'"
                ];
                continue;
            }

            $editor->set_quality( $quality ); // Set quality before saving
            $saved = $editor->save( $new_file_path, 'image/' . $format ); // Attempt to save the converted image

            if ( ! is_wp_error( $saved ) ) {
                $original_size = filesize( $original_filepath );
                $converted_size = filesize( $saved['path'] );

                // --- CONDITIONAL CONVERSION LOGIC (NEW) ---
                $is_larger = $converted_size >= $original_size; // Is the converted file size equal to or larger?
                $actual_savings_percentage = ($original_size > 0) ? (($original_size - $converted_size) / $original_size) * 100 : 0;
                $below_threshold = $actual_savings_percentage < $min_savings_percentage; // Is savings below the set minimum?

                if ($conditional_conversion_enabled && ($is_larger || $below_threshold)) {
                    // Converted file is larger or savings are too small, so delete it.
                    unlink($saved['path']); // Delete the file we just created
                    $converted_files[ $size_name ] = [
                        'path'          => '', // No valid converted path saved
                        'original_size' => $original_size,
                        'converted_size' => 0, // No converted size saved as file was discarded
                        'savings'       => 0,
                        'status'        => 'skipped_size', // Specific status for logging
                        'message'       => "Skipped: Converted file is larger or savings ({$actual_savings_percentage}%) below {$min_savings_percentage}% threshold.",
                    ];
                    continue; // Skip to the next size, don't log as success
                }
                // --- END CONDITIONAL CONVERSION LOGIC ---

                // If not skipped, it's a success
                $converted_files[ $size_name ] = [
                    'path'          => $saved['path'],
                    'original_size' => $original_size,
                    'converted_size' => $converted_size,
                    'savings'       => $original_size - $converted_size,
                    'status'        => 'success',
                ];
            } else {
                // Image editor failed to save the file
                $converted_files[ $size_name ] = [
                    'status' => 'failed',
                    'message' => $saved->get_error_message() . " for size '{$size_name}'"
                ];
            }
        }

        // Return the array of detailed conversion results for each size to the caller (e.g., ICO_Db::log_conversion).
        // This array will be processed by log_conversion to determine the overall status for the attachment/format.
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
        $upload_dir = wp_upload_dir();
        $webp_dir = $upload_dir['basedir'] . '/webp-converted';
        $avif_dir = $upload_dir['basedir'] . '/avif-converted';

        $deleted_count = 0;

        // Anonymous function to recursively delete a directory
        $delete_recursive = function( $dir ) use ( &$delete_recursive ) {
            if ( ! file_exists( $dir ) || ! is_dir( $dir ) ) {
                return true; // Directory doesn't exist, nothing to delete.
            }
            // Use RecursiveDirectoryIterator for robust deletion of contents
            $it = new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS );
            $files = new RecursiveIteratorIterator( $it, RecursiveIteratorIterator::CHILD_FIRST );
            foreach( $files as $file ) {
                if ( $file->isDir() ) {
                    @rmdir( $file->getRealPath() ); // @ to suppress warnings on non-empty dirs (though CHILD_FIRST should prevent)
                } else {
                    @unlink( $file->getRealPath() ); // @ to suppress warnings if file is locked/permission denied
                }
            }
            return @rmdir( $dir ); // @ to suppress warnings if root dir is locked
        };

        // Try to delete and recreate webp directory
        if ( $delete_recursive( $webp_dir ) ) {
            if ( wp_mkdir_p( $webp_dir ) ) { // Recreate it
                $deleted_count++;
            } else {
                error_log( 'ICO Error: Failed to recreate WebP directory after deletion: ' . $webp_dir );
                return false; // Indicate partial failure
            }
        } else {
            error_log( 'ICO Error: Failed to delete WebP converted directory: ' . $webp_dir );
            return false; // Indicate failure
        }

        // Try to delete and recreate avif directory
        if ( $delete_recursive( $avif_dir ) ) {
            if ( wp_mkdir_p( $avif_dir ) ) { // Recreate it
                $deleted_count++;
            } else {
                error_log( 'ICO Error: Failed to recreate AVIF directory after deletion: ' . $avif_dir );
                return false; // Indicate partial failure
            }
        } else {
            error_log( 'ICO Error: Failed to delete AVIF converted directory: ' . $avif_dir );
            return false; // Indicate failure
        }

        return $deleted_count;
    }
}