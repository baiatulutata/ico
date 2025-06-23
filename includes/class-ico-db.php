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

// Define a cache group for plugin's database queries.
if ( ! defined( 'ICO_CACHE_GROUP' ) ) {
    define( 'ICO_CACHE_GROUP', 'ico_db_cache' );
}

class ICO_Db {

    /**
     * Creates the custom database table for conversion logs if it doesn't exist.
     */
    public static function create_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ico_conversion_logs';
        $charset_collate = $wpdb->get_charset_collate();

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
     *
     * @param int            $attachment_id    The ID of the attachment.
     * @param string         $format           The target format ('webp' or 'avif').
     * @param array|WP_Error $converter_result The result from ICO_Converter::convert_image.
     * @return bool True on success, false on failure to log.
     */
    public static function log_conversion( $attachment_id, $format, $converter_result ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ico_conversion_logs';

        $status = 'failed';
        $log_message = '';
        $total_original_size = 0;
        $total_converted_size = 0;
        $total_savings = 0;

        if ( is_wp_error( $converter_result ) ) {
            $status = 'failed';
            $log_message = $converter_result->get_error_message();
        } else if ( is_array( $converter_result ) && ! empty( $converter_result ) ) {
            $any_successful_conversion = false;
            $any_skipped_by_size = false;
            $any_skipped_by_exists = false;
            $any_failed_size = false;

            foreach ( $converter_result as $size_data ) {
                if (isset($size_data['status'])) {
                    if ($size_data['status'] === 'success') {
                        $any_successful_conversion = true;
                        $total_original_size += $size_data['original_size'];
                        $total_converted_size += $size_data['converted_size'];
                        $total_savings += $size_data['savings'];
                    } elseif ($size_data['status'] === 'skipped_size') {
                        $any_skipped_by_size = true;
                        $total_original_size += $size_data['original_size'];
                    } elseif ($size_data['status'] === 'skipped_exists') {
                        $any_skipped_by_exists = true;
                        $total_original_size += $size_data['original_size'];
                        $total_converted_size += $size_data['converted_size'];
                        $total_savings += $size_data['savings'];
                    } elseif ($size_data['status'] === 'failed') {
                        $any_failed_size = true;
                    }
                }
            }

            if ( $any_successful_conversion ) {
                $status = 'success';
                $log_message = 'Successfully converted ' . count(array_filter($converter_result, function($s){ return isset($s['status']) && $s['status'] === 'success'; })) . ' sizes.';
            } elseif ( $any_skipped_by_size ) {
                $status = 'skipped_size';
                $log_message = 'Conversion skipped for some sizes due to larger file size or insufficient savings.';
            } elseif ( $any_skipped_by_exists && !$any_successful_conversion && !$any_failed_size ) {
                $status = 'skipped_exists';
                $log_message = 'Conversion skipped for some sizes as they already existed.';
            } elseif ( $any_failed_size ) {
                $status = 'failed';
                $log_message = 'Conversion failed for one or more image sizes.';
            } else {
                $status = 'failed';
                $log_message = 'No sizes converted successfully or skipped due to specific reasons.';
            }

        } else {
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

        $existing_log_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table_name} WHERE attachment_id = %d AND format = %s ORDER BY conversion_date DESC LIMIT 1",
                $attachment_id,
                $format
            )
        );

        $result = false;
        if ( $existing_log_id ) {
            $result = $wpdb->update( $table_name, $data, array( 'id' => $existing_log_id ), $format_types, array( '%d' ) );
        } else {
            $result = $wpdb->insert( $table_name, $data, $format_types );
        }

        self::invalidate_dashboard_caches( $attachment_id );

        return (bool) $result;
    }


    /**
     * Gets the total count of images in the media library.
     *
     * @return int Total images count.
     */
    public static function get_total_images_count() {
        $cache_key = 'ico_total_images_count';
        $count = wp_cache_get( $cache_key, ICO_CACHE_GROUP );

        if ( false === $count ) {
            $count_posts = wp_count_posts( 'attachment' );
            $count = isset( $count_posts->inherit ) ? $count_posts->inherit : 0;
            wp_cache_set( $cache_key, $count, ICO_CACHE_GROUP, MINUTE_IN_SECONDS * 5 );
        }
        return absint( $count );
    }

    /**
     * Gets the count of images that have successfully converted to WebP.
     *
     * @return int
     */
    public static function get_webp_converted_count() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ico_conversion_logs';
        $cache_key = 'ico_webp_converted_count';
        $count = wp_cache_get( $cache_key, ICO_CACHE_GROUP );

        if ( false === $count ) {
            // Pass raw SQL and arguments directly to get_var. It handles prepare internally.
            $count = $wpdb->get_var(
                $wpdb->prepare( "SELECT COUNT(DISTINCT attachment_id) FROM {$table_name} WHERE format = %s AND status = %s", 'webp', 'success' )
            );
            wp_cache_set( $cache_key, $count, ICO_CACHE_GROUP, MINUTE_IN_SECONDS * 5 );
        }
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
        $cache_key = 'ico_avif_converted_count';
        $count = wp_cache_get( $cache_key, ICO_CACHE_GROUP );

        if ( false === $count ) {
            // Pass raw SQL and arguments directly to get_var. It handles prepare internally.
            $count = $wpdb->get_var(
                $wpdb->prepare( "SELECT COUNT(DISTINCT attachment_id) FROM {$table_name} WHERE format = %s AND status = %s", 'avif', 'success' )
            );
            wp_cache_set( $cache_key, $count, ICO_CACHE_GROUP, MINUTE_IN_SECONDS * 5 );
        }
        return absint( $count );
    }

    /**
     * Gets the count of images that have been successfully converted for at least one format.
     *
     * @return int
     */
    public static function get_converted_images_count() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ico_conversion_logs';
        $cache_key = 'ico_overall_converted_count';
        $count = wp_cache_get( $cache_key, ICO_CACHE_GROUP );

        if ( false === $count ) {
            // Pass raw SQL and arguments directly to get_var. It handles prepare internally.
            $count = $wpdb->get_var(
                $wpdb->prepare( "SELECT COUNT(DISTINCT attachment_id) FROM {$table_name} WHERE status = %s", 'success' )
            );
            wp_cache_set( $cache_key, $count, ICO_CACHE_GROUP, MINUTE_IN_SECONDS * 5 );
        }
        return absint( $count );
    }

    /**
     * Retrieves a paginated list of media attachments with their conversion status.
     * This method is heavily cached.
     *
     * @param int $per_page Number of items per page.
     * @param int $page     Current page number.
     * @return array A list of image data including conversion status.
     */
    public static function get_media_with_conversion_status( $per_page = 20, $page = 1 ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ico_conversion_logs';

        $cache_key = 'ico_media_status_' . md5( $per_page . '_' . $page );
        $cached_data = wp_cache_get( $cache_key, ICO_CACHE_GROUP );

        if ( false !== $cached_data ) {
            return $cached_data;
        }

        $attachments_query = new WP_Query( array(
            'post_type'      => 'attachment',
            'post_mime_type' => 'image',
            'post_status'    => 'inherit',
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'DESC',
            'no_found_rows'  => false,
        ) );

        $image_ids = $attachments_query->posts;
        $images_data = [];

        if ( ! empty( $image_ids ) ) {
            // Prepare placeholders for the IN clause securely.
            $placeholders = implode( ', ', array_fill( 0, count( $image_ids ), '%d' ) );
            // Arguments for the prepare call. $image_ids contains absint'd IDs.
            $query_args_for_prepare = array_merge( $image_ids, $image_ids ); // For two IN clauses


            // Pass the raw SQL and arguments directly to get_results. It handles prepare internally.
            $results = $wpdb->get_results(
                $wpdb->prepare( "SELECT 
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
                            WHERE attachment_id IN ({$placeholders}) 
                            GROUP BY attachment_id, format
                        ) AS latest_logs
                        ON l.attachment_id = latest_logs.attachment_id 
                        AND l.format = latest_logs.format 
                        AND l.conversion_date = latest_logs.max_date
                        WHERE l.attachment_id IN ({$placeholders})", ...$query_args_for_prepare ), // Use argument unpacking (PHP 5.6+)
                ARRAY_A
            );

            $conversion_status = [];
            foreach ( $results as $row ) {
                $attachment_id = $row['attachment_id'];
                $format = $row['format'];

                if ( ! isset( $conversion_status[ $attachment_id ] ) ) {
                    $conversion_status[ $attachment_id ] = [
                        'webp_status' => 'pending', 'webp_size' => 'N/A',
                        'avif_status' => 'pending', 'avif_size' => 'N/A',
                    ];
                }

                $conversion_status[ $attachment_id ][ $format . '_status' ] = $row['status'];
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
                $original_size = 'N/A';
                // Check for file existence using native PHP file_exists for original file, as it's not managed by WP_Filesystem here.
                if ( $original_file_path && file_exists( $original_file_path ) ) {
                    $original_size = size_format( filesize( $original_file_path ), 2 );
                }

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

        $response_data = [
            'images'      => $images_data,
            'total_pages' => $attachments_query->max_num_pages,
            'total_images' => $attachments_query->found_posts,
        ];

        wp_cache_set( $cache_key, $response_data, ICO_CACHE_GROUP, MINUTE_IN_SECONDS * 1 ); // Cache for 1 minute for dashboard
        return $response_data;
    }

    /**
     * Gets a batch of unprocessed images for bulk conversion.
     * Uses WP_Query, which handles caching internally for post queries.
     *
     * @param int $limit The number of images (attachment IDs) to fetch in this batch.
     * @return WP_Query A WP_Query object containing the unprocessed attachment IDs.
     */
    public static function get_unprocessed_images_for_bulk( $limit = 25 ) {
        // This method uses WP_Query, which is the recommended high-level function
        // and handles its own SQL preparation and object caching.
        $args = array(
            'post_type'      => 'attachment',
            'post_mime_type' => 'image',
            'post_status'    => 'inherit',
            'posts_per_page' => $limit,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'meta_query'     => array(
                'relation' => 'OR',
                array(
                    'key'     => '_ico_converted_status',
                    'compare' => 'NOT EXISTS',
                ),
                array(
                    'key'     => '_ico_converted_status',
                    'value'   => 'complete',
                    'compare' => '!=',
                ),
            ),
        );
        return new WP_Query( $args );
    }

    /**
     * Gets the latest conversion status for a specific attachment ID and format from the logs table.
     * This method is cached.
     *
     * @param int    $attachment_id The ID of the attachment.
     * @param string $format        The format ('webp' or 'avif').
     * @return string The status (e.g., 'success', 'failed', 'pending', 'skipped_size', 'skipped_exists') or 'pending' if no log entry exists.
     */
    public static function get_latest_conversion_status_for_attachment_format( $attachment_id, $format ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ico_conversion_logs';
        $cache_key = 'ico_latest_status_' . $attachment_id . '_' . $format;
        $status = wp_cache_get( $cache_key, ICO_CACHE_GROUP );

        if ( false === $status ) {
            // Pass raw SQL and arguments directly to get_var. It handles prepare internally.
            $status = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT status FROM {$table_name} WHERE attachment_id = %d AND format = %s ORDER BY conversion_date DESC LIMIT 1",
                    $attachment_id,
                    $format
                )
            );
            $status = $status ? $status : 'pending';
            wp_cache_set( $cache_key, $status, ICO_CACHE_GROUP, MINUTE_IN_SECONDS * 5 );
        }
        return $status;
    }

    /**
     * Clears all entries from the conversion logs table.
     * This is a direct database call as there's no high-level WP function for TRUNCATE.
     * WordPress Coding Standards (WordPress.DB.DirectDatabaseQuery) often flag TRUNCATE,
     * but it's often used for full table resets in plugins where `DELETE` is too slow.
     *
     * @return int|false The number of deleted rows on success, false on error.
     */
    public static function clear_all_converted_data() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ico_conversion_logs';

        // This is a direct query and will be flagged by linters, but it's efficient for clearing tables.
        // No WP_Cache operations apply to TRUNCATE, but we invalidate relevant caches afterward.
        $deleted_rows = $wpdb->query( "TRUNCATE TABLE $table_name" );
        if ( false === $deleted_rows ) {
            error_log( 'ICO Error: Failed to truncate conversion logs table: ' . $wpdb->last_error );
        }

        self::invalidate_all_caches();

        return $deleted_rows;
    }

    /**
     * Clears the '_ico_converted_status' meta key from all attachment posts.
     * Uses delete_post_meta_by_key() which is the recommended WP function.
     *
     * @return int|false The number of deleted meta rows on success, false on error.
     */
    public static function clear_attachment_conversion_meta() {
        $meta_key = '_ico_converted_status';

        $deleted_meta = delete_post_meta_by_key( $meta_key );

        if ( false === $deleted_meta ) {
            error_log( 'ICO Error: Failed to delete attachment conversion meta.' );
        }

        self::invalidate_dashboard_caches();

        return $deleted_meta;
    }

    /**
     * Invalidates all caches related to the plugin's dashboard statistics and image list.
     *
     * @param int|null $attachment_id Optional: Invalidate specific attachment caches.
     */
    public static function invalidate_dashboard_caches( $attachment_id = null ) {
        // Invalidate overall counts
        wp_cache_delete( 'ico_total_images_count', ICO_CACHE_GROUP );
        wp_cache_delete( 'ico_webp_converted_count', ICO_CACHE_GROUP );
        wp_cache_delete( 'ico_avif_converted_count', ICO_CACHE_GROUP );
        wp_cache_delete( 'ico_overall_converted_count', ICO_CACHE_GROUP );

        // If a specific attachment ID is known, delete its individual status cache.
        if ( $attachment_id ) {
            wp_cache_delete( 'ico_latest_status_' . $attachment_id . '_webp', ICO_CACHE_GROUP );
            wp_cache_delete( 'ico_latest_status_' . $attachment_id . '_avif', ICO_CACHE_GROUP );
        }

        // To invalidate ico_media_status_XXXXX for paginated results, you'd typically need to know all the keys,
        // or flush the entire group if an external object cache (Redis/Memcached) is configured and supports group flushing.
        // For general WordPress object cache (non-persistent), these page-specific caches expire in 1 minute, which is acceptable for dashboard data.
    }

    /**
     * Invalidates all caches associated with the plugin.
     * Called after major data changes like full clear.
     */
    public static function invalidate_all_caches() {
        self::invalidate_dashboard_caches();
    }
}