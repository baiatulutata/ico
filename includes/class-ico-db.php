<?php
/**
 * Handles database interactions for Image Converter & Optimizer, including logging and data retrieval.
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

        // SQL statement for creating the table. Removed comments that caused dbDelta issues.
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

        error_log('ICO_Db: create_table() called. Table definition attempted.');
    }

    /**
     * Logs a conversion attempt (success, failure, or skipped).
     * This method logs or updates the *overall* status for a specific format and attachment,
     * aggregating results from all sizes.
     *
     * @param int            $attachment_id    The ID of the attachment.
     * @param string         $format           The target format ('webp' or 'avif').
     * @param array|WP_Error $converter_result The result from ICO_Converter::convert_image (array of size data or WP_Error).
     * @return bool True on success, false on failure to log.
     */
    public static function log_conversion( $attachment_id, $format, $converter_result ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ico_conversion_logs';

        $status = 'failed'; // Default status
        $log_message = '';
        $total_original_size = 0;
        $total_converted_size = 0;
        $total_savings = 0;

        if ( is_wp_error( $converter_result ) ) {
            $status = 'failed';
            $log_message = $converter_result->get_error_message();
        } else if ( is_array( $converter_result ) && ! empty( $converter_result ) ) {
            $any_successful_conversion = false; // Was any size actually converted this run?
            $any_skipped_by_size = false; // Was any size skipped due to size?
            $any_skipped_by_exists = false; // Was any size skipped due to existing?
            $any_failed_size = false; // Was any size explicitly failed during iteration?

            foreach ( $converter_result as $size_data ) {
                if (isset($size_data['status'])) {
                    if ($size_data['status'] === 'success') {
                        $any_successful_conversion = true;
                        $total_original_size += $size_data['original_size'];
                        $total_converted_size += $size_data['converted_size'];
                        $total_savings += $size_data['savings'];
                    } elseif ($size_data['status'] === 'skipped_size') {
                        $any_skipped_by_size = true;
                        $total_original_size += $size_data['original_size']; // Still count original size for overall stats
                    } elseif ($size_data['status'] === 'skipped_exists') {
                        $any_skipped_by_exists = true;
                        $total_original_size += $size_data['original_size'];
                        $total_converted_size += $size_data['converted_size']; // Use size of existing converted file
                        $total_savings += $size_data['savings']; // Use savings of existing converted file
                    } elseif ($size_data['status'] === 'failed') {
                        $any_failed_size = true;
                    }
                }
            }

            // Determine the overall status for this format/attachment combination
            if ( $any_successful_conversion ) {
                $status = 'success';
                $log_message = 'Successfully converted ' . count(array_filter($converter_result, function($s){ return isset($s['status']) && $s['status'] === 'success'; })) . ' sizes.';
            } elseif ( $any_skipped_by_size ) {
                $status = 'skipped_size';
                $log_message = 'Conversion skipped for some sizes due to larger file size or insufficient savings.';
            } elseif ( $any_skipped_by_exists && !$any_successful_conversion && !$any_failed_size ) {
                // Only mark as 'skipped_exists' if no new conversions or failures happened
                $status = 'skipped_exists';
                $log_message = 'Conversion skipped for some sizes as they already existed.';
            } elseif ( $any_failed_size ) {
                $status = 'failed';
                $log_message = 'Conversion failed for one or more image sizes.';
            } else {
                // Fallback for cases where no specific success/skip reason is determined
                $status = 'failed';
                $log_message = 'No sizes converted successfully or skipped due to specific reasons.';
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

        $format_types = array( '%d', '%s', '%d', '%d', '%d', '%s', '%s', '%s' );

        // Check if a previous log entry for this attachment_id and format exists
        // We update the most recent one if it exists, otherwise insert a new one.
        $existing_log_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table_name} WHERE attachment_id = %d AND format = %s ORDER BY conversion_date DESC LIMIT 1",
                $attachment_id,
                $format
            )
        );

        if ( $existing_log_id ) {
            $updated = $wpdb->update( $table_name, $data, array( 'id' => $existing_log_id ), $format_types, array( '%d' ) );
            return (bool) $updated;
        } else {
            $inserted = $wpdb->insert( $table_name, $data, $format_types );
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

                // Initialize if not set for this attachment
                if ( ! isset( $conversion_status[ $attachment_id ] ) ) {
                    $conversion_status[ $attachment_id ] = [
                        'webp_status' => 'pending', 'webp_size' => 'N/A',
                        'avif_status' => 'pending', 'avif_size' => 'N/A',
                    ];
                }

                // Update based on the latest log entry's status
                $conversion_status[ $attachment_id ][ $format . '_status' ] = $row['status'];
                // Only show size if conversion was successful or skipped_exists (meaning a file exists)
                if ( in_array($row['status'], ['success', 'skipped_exists']) ) {
                    $conversion_status[ $attachment_id ][ $format . '_size' ] = size_format( $row['converted_size_total'], 2 );
                } else {
                    $conversion_status[ $attachment_id ][ $format . '_size' ] = 'N/A';
                }
            }

            foreach ( $image_ids as $id ) {
                $image_title = get_the_title( $id );
                $image_src = wp_get_attachment_image_src( $id, 'thumbnail' );
                $original_file_path = get_attached_file( $id );
                $original_size = file_exists($original_file_path) ? size_format( filesize( $original_file_path ), 2 ) : 'N/A';

                // Retrieve status from the aggregated conversion_status array, defaulting to 'pending'
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
     * Gets a batch of unprocessed images for bulk conversion.
     * An image is considered "unprocessed" if its `_ico_converted_status` post meta is
     * missing or not set to 'complete'. This allows re-processing of failed/incomplete attempts.
     *
     * @param int $limit The number of images (attachment IDs) to fetch in this batch.
     * @return WP_Query A WP_Query object containing the unprocessed attachment IDs.
     */
    public static function get_unprocessed_images_for_bulk( $limit = 25 ) {
        $args = array(
            'post_type'      => 'attachment',
            'post_mime_type' => 'image', // Only get image attachments
            'post_status'    => 'inherit', // Default status for attachments
            'posts_per_page' => $limit,
            'fields'         => 'ids', // Only retrieve IDs for performance
            'orderby'        => 'ID', // Order by ID for consistent batching
            'order'          => 'ASC',
            'meta_query'     => array(
                'relation' => 'OR', // Images should match either condition
                array(
                    'key'     => '_ico_converted_status',
                    'compare' => 'NOT EXISTS', // Image has never been touched by the plugin
                ),
                array(
                    'key'     => '_ico_converted_status',
                    'value'   => 'complete',
                    'compare' => '!=',         // Image has been processed, but not marked 'complete'
                    // This catches 'pending', 'incomplete', 'failed', 'partial_failure', etc.
                ),
            ),
        );
        return new WP_Query( $args );
    }

    /**
     * Gets the latest conversion status for a specific attachment ID and format from the logs table.
     *
     * @param int    $attachment_id The ID of the attachment.
     * @param string $format        The format ('webp' or 'avif').
     * @return string The status (e.g., 'success', 'failed', 'pending', 'skipped_size', 'skipped_exists') or 'pending' if no log entry exists.
     */
    public static function get_latest_conversion_status_for_attachment_format( $attachment_id, $format ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ico_conversion_logs';

        $status = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT status FROM {$table_name} WHERE attachment_id = %d AND format = %s ORDER BY conversion_date DESC LIMIT 1",
                $attachment_id,
                $format
            )
        );
        return $status ? $status : 'pending'; // Default to 'pending' if no log entry is found
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
     * This ensures images are considered "unconverted" in the backend after a clear
     * or if you want to force a re-process.
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