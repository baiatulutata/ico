<?php

class ICO_Converter {

    public static function convert_image( $attachment_id, $format = 'webp', $quality = 82 ) {
        if ( ! in_array( $format, array( 'webp', 'avif' ) ) ) {
            return new WP_Error( 'invalid_format', 'Invalid format specified.' );
        }

        $file_path = get_attached_file( $attachment_id ); // Path to the full-size original image.
        if ( ! $file_path || ! file_exists( $file_path ) ) {
            return new WP_Error( 'file_not_found', 'Original image file not found.' );
        }

        // Check server support
        if ( 'webp' === $format && ! ICO_Compatibility::supports_webp() ) {
            return new WP_Error('no_webp_support', 'WebP is not supported on this server.');
        }
        if ( 'avif' === $format && ! ICO_Compatibility::supports_avif() ) {
            return new WP_Error('no_avif_support', 'AVIF is not supported on this server.');
        }

        $upload_dir = wp_upload_dir();
        $converted_dir_base = $upload_dir['basedir'] . '/' . $format . '-converted';

        $metadata = wp_get_attachment_metadata( $attachment_id );
        if ( ! $metadata || ! isset( $metadata['file'] ) ) {
            return new WP_Error( 'no_metadata', 'Could not retrieve image metadata.' );
        }

        $converted_files = [];
        $original_upload_path = dirname( $file_path );

        // Add the full-size image to the list of images to process
        $images_to_process = [
            'full' => [
                'file' => basename( $file_path ),
                'path' => $file_path,
            ],
        ];

        // Add all intermediate sizes to the list
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
        $min_savings_percentage = isset($ico_options['min_savings_percentage']) ? (float) $ico_options['min_savings_percentage'] : 0; // Default to 0% if not set

        foreach ( $images_to_process as $size_name => $image_info ) {
            $original_filepath = $image_info['path'];

            if ( ! file_exists( $original_filepath ) ) {
                $converted_files[ $size_name ] = [
                    'status' => 'failed', // Mark as failed for this size
                    'message' => "Original file missing for size '{$size_name}': " . $original_filepath
                ];
                continue;
            }

            $relative_path_segment = str_replace( $upload_dir['basedir'], '', $original_filepath );
            $new_file_path = $converted_dir_base . $relative_path_segment . '.' . $format;

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

            $editor = wp_get_image_editor( $original_filepath );
            if ( is_wp_error( $editor ) ) {
                $converted_files[ $size_name ] = [
                    'status' => 'failed',
                    'message' => $editor->get_error_message() . " for size '{$size_name}'"
                ];
                continue;
            }

            $editor->set_quality( $quality );
            $saved = $editor->save( $new_file_path, 'image/' . $format );

            if ( ! is_wp_error( $saved ) ) {
                $original_size = filesize( $original_filepath );
                $converted_size = filesize( $saved['path'] );

                // --- CONDITIONAL CONVERSION LOGIC ---
                $is_larger = $converted_size >= $original_size;
                $actual_savings_percentage = ($original_size > 0) ? (($original_size - $converted_size) / $original_size) * 100 : 0;
                $below_threshold = $actual_savings_percentage < $min_savings_percentage;

                if ($conditional_conversion_enabled && ($is_larger || $below_threshold)) {
                    // Converted file is larger or savings are too small, delete it.
                    unlink($saved['path']); // Delete the file we just created
                    $converted_files[ $size_name ] = [
                        'path'          => '', // No converted path saved
                        'original_size' => $original_size,
                        'converted_size' => 0, // No converted size saved
                        'savings'       => 0,
                        'status'        => 'skipped_size', // Specific status for logging
                        'message'       => "Skipped: Converted file is larger or savings ({$actual_savings_percentage}%) below {$min_savings_percentage}% threshold.",
                    ];
                    continue;
                }
                // --- END CONDITIONAL CONVERSION LOGIC ---

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

        // Return the array of detailed conversion results for each size.
        // ICO_Db::log_conversion will then aggregate this.
        return $converted_files;
    }    /**
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

        // Function to recursively delete a directory
        $delete_recursive = function( $dir ) use ( &$delete_recursive ) {
            if ( ! file_exists( $dir ) || ! is_dir( $dir ) ) {
                return true;
            }
            $it = new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS );
            $files = new RecursiveIteratorIterator( $it, RecursiveIteratorIterator::CHILD_FIRST );
            foreach( $files as $file ) {
                if ( $file->isDir() ) {
                    rmdir( $file->getRealPath() );
                } else {
                    unlink( $file->getRealPath() );
                }
            }
            return rmdir( $dir );
        };

        // Try to delete and recreate webp directory
        if ( $delete_recursive( $webp_dir ) ) {
            if ( wp_mkdir_p( $webp_dir ) ) {
                $deleted_count++;
            } else {
                error_log( 'ICO Error: Failed to recreate WebP directory after deletion.' );
                return false; // Indicate partial failure
            }
        } else {
            error_log( 'ICO Error: Failed to delete WebP converted directory: ' . $webp_dir );
            return false; // Indicate failure
        }

        // Try to delete and recreate avif directory
        if ( $delete_recursive( $avif_dir ) ) {
            if ( wp_mkdir_p( $avif_dir ) ) {
                $deleted_count++;
            } else {
                error_log( 'ICO Error: Failed to recreate AVIF directory after deletion.' );
                return false; // Indicate partial failure
            }
        } else {
            error_log( 'ICO Error: Failed to delete AVIF converted directory: ' . $avif_dir );
            return false; // Indicate failure
        }

        return $deleted_count;
    }
}