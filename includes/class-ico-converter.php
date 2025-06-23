<?php

class ICO_Converter {

    public static function convert_image( $attachment_id, $format = 'webp', $quality = 82 ) {
        if ( ! in_array( $format, array( 'webp', 'avif' ) ) ) {
            return new WP_Error( 'invalid_format', 'Invalid format specified.' );
        }

        $file_path = get_attached_file( $attachment_id ); // This gets the path to the full-size original image.
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
        $original_upload_path = dirname( $file_path ); // Path to the directory where original image and its sizes reside.

        // Add the full-size image to the list of images to process
        $images_to_process = [
            'full' => [
                'file' => basename( $file_path ),
                'path' => $file_path, // Full path to the original file
            ],
        ];

        // Add all intermediate sizes to the list
        if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
            foreach ( $metadata['sizes'] as $size_name => $size_data ) {
                if ( ! empty( $size_data['file'] ) ) {
                    $images_to_process[ $size_name ] = [
                        'file' => $size_data['file'],
                        'path' => trailingslashit( $original_upload_path ) . $size_data['file'], // Construct full path for intermediate sizes
                    ];
                }
            }
        }

        foreach ( $images_to_process as $size_name => $image_info ) {
            $original_filepath = $image_info['path'];

            // Ensure the original file actually exists before attempting conversion
            if ( ! file_exists( $original_filepath ) ) {
                ICO_Db::log_conversion( $attachment_id, $format, new WP_Error('file_missing_for_size', "Original file missing for size '{$size_name}': " . $original_filepath) );
                continue; // Skip to the next size
            }

            // Construct the path for the new converted image
            // E.g., /wp-content/uploads/webp-converted/2025/06/my-image.jpg.webp
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
                    'skipped'       => true, // Mark as skipped
                ];
                continue; // Skip conversion if already exists
            }

            $editor = wp_get_image_editor( $original_filepath );
            if ( is_wp_error( $editor ) ) {
                ICO_Db::log_conversion( $attachment_id, $format, new WP_Error('image_editor_error', $editor->get_error_message() . " for size '{$size_name}'") );
                continue; // Skip to the next size
            }

            $editor->set_quality( $quality );
            $saved = $editor->save( $new_file_path, 'image/' . $format );

            if ( ! is_wp_error( $saved ) ) {
                $original_size = filesize( $original_filepath );
                $converted_size = filesize( $saved['path'] );
                $converted_files[ $size_name ] = [
                    'path'          => $saved['path'],
                    'original_size' => $original_size,
                    'converted_size' => $converted_size,
                    'savings'       => $original_size - $converted_size,
                ];
            } else {
                ICO_Db::log_conversion( $attachment_id, $format, new WP_Error('image_save_error', $saved->get_error_message() . " for size '{$size_name}'") );
            }
        }

        // If no files were converted successfully, return an error.
        if ( empty( $converted_files ) ) {
            return new WP_Error( 'no_files_converted', 'No image sizes could be converted for this attachment.' );
        }

        return $converted_files;
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