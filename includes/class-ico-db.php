<?php
/**
 * Handles database interactions for Image Converter & Optimizer.
 *
 * @package    Image_Converter_Optimizer
 * @subpackage Image_Converter_Optimizer/includes
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ICO_Db {

    /**
     * Creates the custom database table for conversion logs if it doesn't exist.
     */
    public static function create_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ico_conversion_logs';
        $charset_collate = $wpdb->get_charset_collate();

        // REMOVE ALL INLINE SQL COMMENTS and ensure conversion_date is NOT NULL or has a DEFAULT
        $sql = "CREATE TABLE $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            attachment_id bigint(20) unsigned NOT NULL,
            format varchar(10) NOT NULL,
            original_size_total bigint(20) unsigned DEFAULT 0,
            converted_size_total bigint(20) unsigned DEFAULT 0,
            savings_total int(11) DEFAULT 0,
            status varchar(20) NOT NULL,
            log_message text,
            conversion_date datetime NOT NULL,
            PRIMARY KEY (id),
            KEY attachment_id_format (attachment_id, format),
            KEY conversion_date (conversion_date),
            KEY status (status)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );

        // Add an error log to confirm dbDelta was called (optional, for debugging)
        error_log('ICO_Db: create_table() called. SQL: ' . $sql);
    }

    /**
     * Logs a conversion attempt (success, failure, or skipped).
     * This method now logs or updates the *overall* status for a specific format and attachment.
     *
     * @param int            $attachment_id The ID of the attachment.
     * @param string         $format        'webp' or 'avif'.
     * @param array|WP_Error $converter_result The result from ICO_Converter::convert_image (array of size data or WP_Error).
     * @return bool True on success, false on failure.
     */
    public static function log_conversion( $attachment_id, $format, $converter_result ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ico_conversion_logs';

        $status = 'failed'; // Default status if nothing else determines it
        $log_message = '';
        $total_original_size = 0;
        $total_converted_size = 0;
        $total_savings = 0;

        if ( is_wp_error( $converter_result ) ) {
            $status = 'failed';
            $log_message = $converter_result->get_error_message();
        } else if ( is_array( $converter_result ) && ! empty( $converter_result ) ) {
            $any_actual_conversion = false; // Tracks if any file was genuinely converted (not skipped)
            foreach ( $converter_result as $size_data ) {
                // Sum up sizes only for files that were actually converted, not just skipped
                if ( isset( $size_data['path'] ) && !isset($size_data['skipped']) ) {
                    $total_original_size += $size_data['original_size'];
                    $total_converted_size += $size_data['converted_size'];
                    $total_savings += $size_data['savings'];
                    $any_actual_conversion = true;
                }
            }

            if ( $any_actual_conversion ) {
                $status = 'success';
                $log_message = 'Successfully converted ' . count( $converter_result ) . ' image sizes.';
            } else {
                // If the converter returned data but no actual conversion happened (e.g., all were pre-existing)
                $status = 'skipped';
                $log_message = 'All image sizes for this format were already converted or could not be converted.';
            }
        } else {
            // Case where converter returns empty array or null, indicating nothing to convert or no eligible sizes
            $status = 'failed';
            $log_message = 'No valid image sizes found for conversion or converter returned empty result.';
        }

        $data = array(
            'attachment_id'       => $attachment_id,
            'format'              => $format,
            'original_size_total' => $total_original_size,
            'converted_size_total' => $total_converted_size,
            'savings_total'       => $total_savings,
            'status'              => $status,
            'log_message'         => $log_message,
            'conversion_date'     => current_time( 'mysql' ),
        );

        $format_types = array( '%d', '%s', '%d', '%d', '%d', '%s', '%s', '%s' ); // Matching $data keys

        // Check if a previous log entry for this attachment_id and format exists
        // We'll update the *most recent* one if it exists, otherwise insert a new one.
        // This is a simple upsert logic: find the latest entry for this attachment+format.
        $existing_log_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table_name} WHERE attachment_id = %d AND format = %s ORDER BY conversion_date DESC LIMIT 1",
                $attachment_id,
                $format
            )
        );

        if ( $existing_log_id ) {
            // Update the existing record with the new status and data
            $updated = $wpdb->update( $table_name, $data, array( 'id' => $existing_log_id ), $format_types, array( '%d' ) );
            // Log for debugging
            error_log('DB - Updated log for ID: ' . $attachment_id . ' format: ' . $format . ' status: ' . $status . ' (Log ID: ' . $existing_log_id . ')');
            error_log('DB - Data updated: ' . print_r($data, true));
            return (bool) $updated;
        } else {
            // Insert a new record
            $inserted = $wpdb->insert( $table_name, $data, $format_types );
            // Log for debugging
            error_log('DB - Inserted log for ID: ' . $attachment_id . ' format: ' . $format . ' status: ' . $status . ' (New Log ID: ' . $wpdb->insert_id . ')');
            error_log('DB - Data inserted: ' . print_r($data, true));
            return (bool) $inserted;
        }
    }


    /**
     * Gets the total count of images in the media library.
     *
     * @return int Total images count.
     */
    public static function get_total_images_count() {
        $count = wp_count_posts( 'attachment' );
        return isset( $count->inherit ) ? $count->inherit : 0; // 'inherit' status is for attachments
    }

    /**
     * Gets the count of images that have successfully converted to WebP.
     *
     * @return int
     */
    public static function get_webp_converted_count() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ico_conversion_logs';
        $count = $wpdb->get_var(
            "SELECT COUNT(DISTINCT attachment_id) FROM $table_name WHERE format = 'webp' AND status = 'success'"
        );
        return absint( $count );
    }

    /**
     * Gets the count of images that have successfully converted to AVIF.
     *
     * @return int
     */
    public static function get_avif_converted_count() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ico_conversion_logs';
        $count = $wpdb->get_var(
            "SELECT COUNT(DISTINCT attachment_id) FROM $table_name WHERE format = 'avif' AND status = 'success'"
        );
        return absint( $count );
    }

    /**
     * Gets the count of images that have been successfully converted for at least one format.
     * This is useful for overall "converted" count vs "unconverted".
     *
     * @return int
     */
    public static function get_converted_images_count() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ico_conversion_logs';

        // Count unique attachment IDs that have at least one 'success' conversion
        $count = $wpdb->get_var(
            "SELECT COUNT(DISTINCT attachment_id) FROM $table_name WHERE status = 'success'"
        );

        return absint( $count );
    }

    /**
     * Retrieves a paginated list of media attachments with their conversion status.
     *
     * @param int $per_page Number of items per page.
     * @param int $page     Current page number.
     * @return array A list of image data including conversion status.
     */
    public static function get_media_with_conversion_status( $per_page = 20, $page = 1 ) {
        global $wpdb;
        $offset = ( $page - 1 ) * $per_page;

        $attachments_query = new WP_Query( array(
            'post_type'      => 'attachment',
            'post_mime_type' => 'image',
            'post_status'    => 'inherit',
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'DESC',
            'no_found_rows'  => false, // We need found_posts for pagination
        ) );

        $image_ids = $attachments_query->posts;
        $images_data = [];

        if ( ! empty( $image_ids ) ) {
            $table_name = $wpdb->prefix . 'ico_conversion_logs';
            $ids_in = implode( ',', array_map( 'absint', $image_ids ) );

            // Fetch ALL latest conversion statuses for these images, regardless of success.
            $results = $wpdb->get_results(
                "SELECT 
                    l.attachment_id, 
                    l.format, 
                    l.status, 
                    l.original_size_total, 
                    l.converted_size_total 
                FROM {$table_name} l
                INNER JOIN (
                    SELECT 
                        attachment_id, 
                        format, 
                        MAX(conversion_date) as max_date 
                    FROM {$table_name} 
                    WHERE attachment_id IN ($ids_in) 
                    GROUP BY attachment_id, format
                ) AS latest_logs
                ON l.attachment_id = latest_logs.attachment_id 
                AND l.format = latest_logs.format 
                AND l.conversion_date = latest_logs.max_date
                WHERE l.attachment_id IN ($ids_in)", // Redundant WHERE but good for safety
                ARRAY_A
            );

            $conversion_status = [];
            foreach ( $results as $row ) {
                $attachment_id = $row['attachment_id'];
                $format = $row['format'];

                // Initialize if not set
                if ( ! isset( $conversion_status[ $attachment_id ] ) ) {
                    $conversion_status[ $attachment_id ] = [
                        'webp_status' => 'pending', 'webp_size' => 'N/A',
                        'avif_status' => 'pending', 'avif_size' => 'N/A',
                    ];
                }

                // Update based on the latest log entry's status
                $conversion_status[ $attachment_id ][ $format . '_status' ] = $row['status'];
                // Only show size if conversion was successful, otherwise 'N/A'
                $conversion_status[ $attachment_id ][ $format . '_size' ] = ($row['status'] === 'success') ? size_format( $row['converted_size_total'], 2 ) : 'N/A';
            }

            foreach ( $image_ids as $id ) {
                $image_title = get_the_title( $id );
                $image_src = wp_get_attachment_image_src( $id, 'thumbnail' );
                $original_file_path = get_attached_file( $id );
                $original_size = file_exists($original_file_path) ? size_format( filesize( $original_file_path ), 2 ) : 'N/A';

                // Retrieve status from the aggregated conversion_status array
                $current_webp_status = $conversion_status[ $id ]['webp_status'] ?? 'pending';
                $current_webp_size = $conversion_status[ $id ]['webp_size'] ?? 'N/A';
                $current_avif_status = $conversion_status[ $id ]['avif_status'] ?? 'pending';
                $current_avif_size = $conversion_status[ $id ]['avif_size'] ?? 'N/A';

                $images_data[] = [
                    'id'            => $id,
                    'title'         => $image_title,
                    'thumbnail_url' => $image_src ? $image_src[0] : '',
                    'original_size' => $original_size,
                    'webp_status'   => $current_webp_status,
                    'webp_size'     => $current_webp_size,
                    'avif_status'   => $current_avif_status,
                    'avif_size'     => $current_avif_size,
                ];
            }
        }

        return [
            'images'      => $images_data,
            'total_pages' => $attachments_query->max_num_pages,
            'total_images' => $attachments_query->found_posts,
        ];
    }
    /**
     * Clears all entries from the conversion logs table.
     *
     * @return int|false The number of deleted rows on success, false on error.
     */
    public static function clear_all_converted_data() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ico_conversion_logs';

        $deleted_rows = $wpdb->query( "TRUNCATE TABLE $table_name" ); // TRUNCATE is faster for full table clears

        if ( false === $deleted_rows ) {
            error_log( 'ICO Error: Failed to truncate conversion logs table: ' . $wpdb->last_error );
        }
        return $deleted_rows;
    }

    /**
     * Clears the '_ico_converted_status' meta key from all attachment posts.
     * This ensures images are considered "unconverted" in the backend after a clear.
     *
     * @return int|false The number of deleted meta rows on success, false on error.
     */
    public static function clear_attachment_conversion_meta() {
        global $wpdb;
        $meta_key = '_ico_converted_status';

        $deleted_meta = $wpdb->query(
            $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", $meta_key )
        );

        if ( false === $deleted_meta ) {
            error_log( 'ICO Error: Failed to delete attachment conversion meta: ' . $wpdb->last_error );
        }
        return $deleted_meta;
    }
}