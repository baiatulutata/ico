<?php
/**
 * REST API endpoints for Image Converter & Optimizer.
 *
 * @package    Image_Converter_Optimizer
 * @subpackage Image_Converter_Optimizer/includes
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ICO_Rest_Api {

    /**
     * Registers all REST API routes for the plugin.
     */
    public static function register_routes() {
        // Endpoint to get overall conversion status (used by dashboard stats and progress bar)
        register_rest_route( 'ico/v1', '/status', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'get_status' ),
            'permission_callback' => '__return_true', // Public access for status, but sensitive actions should require capability
        ) );

        // Endpoint to start the bulk conversion process
        register_rest_route( 'ico/v1', '/start-bulk', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'start_bulk_conversion' ),
            'permission_callback' => function () { return current_user_can( 'manage_options' ); }, // Only users with 'manage_options' can start
        ) );

        // Endpoint to stop the bulk conversion process (NEW)
        register_rest_route( 'ico/v1', '/stop-bulk', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'stop_bulk_conversion' ),
            'permission_callback' => function () { return current_user_can( 'manage_options' ); }, // Only users with 'manage_options' can stop
        ) );

        // Endpoint to get paginated dashboard data (image list)
        register_rest_route( 'ico/v1', '/dashboard-data', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'get_dashboard_data' ),
            'permission_callback' => function () { return current_user_can( 'manage_options' ); }, // Only admin can see dashboard data
            'args'                => [
                'per_page' => [
                    'type'     => 'integer',
                    'default'  => 20,
                    'minimum'  => 1,
                    'maximum'  => 100, // Limit to prevent excessive load
                    'sanitize_callback' => 'absint',
                ],
                'page'     => [
                    'type'     => 'integer',
                    'default'  => 1,
                    'minimum'  => 1,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ) );

        // Endpoint to convert a single image manually
        register_rest_route( 'ico/v1', '/convert-single/(?P<id>\d+)', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'convert_single_image_api' ),
            'permission_callback' => function () { return current_user_can( 'manage_options' ); }, // Only users with 'manage_options' can convert
            'args'                => [
                'id' => [
                    'sanitize_callback' => 'absint',
                    'validate_callback' => function($param) { return is_numeric( $param ); },
                ],
            ],
        ) );

        // Endpoint to clear all converted files and logs
        register_rest_route( 'ico/v1', '/clear-data', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'clear_converted_data_api' ),
            'permission_callback' => function () { return current_user_can( 'manage_options' ); }, // Highly sensitive, 'manage_options' required
        ) );
    }

    /**
     * Gets overall conversion status (total, converted images, and bulk running status).
     *
     * @param WP_REST_Request $request The REST API request object.
     * @return WP_REST_Response The response containing status data.
     */
    public static function get_status( WP_REST_Request $request ) {
        $total_images     = ICO_Db::get_total_images_count();
        $webp_converted   = ICO_Db::get_webp_converted_count();
        $avif_converted   = ICO_Db::get_avif_converted_count();
        $unconverted      = $total_images - ICO_Db::get_converted_images_count();
        $is_bulk_running  = (bool) wp_next_scheduled( 'ico_cron_hook' ); // Check if cron is scheduled

        return new WP_REST_Response( array(
            'total'           => $total_images,
            'webp_converted'  => $webp_converted,
            'avif_converted'  => $avif_converted,
            'unconverted'     => $unconverted,
            'is_bulk_running' => $is_bulk_running,
        ), 200 );
    }

    /**
     * Starts the background bulk conversion process.
     *
     * @param WP_REST_Request $request The REST API request object.
     * @return WP_REST_Response The response indicating success or failure.
     */
    public static function start_bulk_conversion( WP_REST_Request $request ) {
        if ( wp_next_scheduled( 'ico_cron_hook' ) ) {
            return new WP_REST_Response( array( 'status' => 'Bulk conversion is already running.' ), 200 );
        }
        ICO_Background_Process::start();
        return new WP_REST_Response( array( 'status' => 'Bulk conversion started.' ), 200 );
    }

    /**
     * Stops the background bulk conversion process.
     *
     * @param WP_REST_Request $request The REST API request object.
     * @return WP_REST_Response The response indicating success or failure.
     */
    public static function stop_bulk_conversion( WP_REST_Request $request ) {
        ICO_Background_Process::stop();
        // Check if it actually stopped by verifying if the cron hook is no longer scheduled
        if ( ! wp_next_scheduled( 'ico_cron_hook' ) ) {
            return new WP_REST_Response( array( 'status' => 'Bulk conversion stopped successfully.' ), 200 );
        } else {
            return new WP_REST_Response( array( 'status' => 'Failed to stop bulk conversion.', 'error' => true ), 500 );
        }
    }

    /**
     * Retrieves data for the dashboard, including stats and paginated image list.
     *
     * @param WP_REST_Request $request The REST API request object, including 'per_page' and 'page' parameters.
     * @return WP_REST_Response The response containing dashboard data.
     */
    public static function get_dashboard_data( WP_REST_Request $request ) {
        $per_page = $request->get_param( 'per_page' );
        $page     = $request->get_param( 'page' );

        $image_data = ICO_Db::get_media_with_conversion_status( $per_page, $page );
        $total_images = ICO_Db::get_total_images_count();
        $webp_converted   = ICO_Db::get_webp_converted_count();
        $avif_converted   = ICO_Db::get_avif_converted_count();
        $unconverted      = $total_images - ICO_Db::get_converted_images_count();

        return new WP_REST_Response( array(
            'stats' => [
                'total'        => $total_images,
                'webp_converted' => $webp_converted,
                'avif_converted' => $avif_converted,
                'unconverted'  => $unconverted,
            ],
            'images_list' => $image_data['images'],
            'total_pages' => $image_data['total_pages'],
        ), 200 );
    }

    /**
     * Converts a single image via API request.
     *
     * @param WP_REST_Request $request The REST API request object, containing the 'id' of the attachment.
     * @return WP_REST_Response The response indicating conversion success or failure for formats.
     */
    public static function convert_single_image_api( WP_REST_Request $request ) {
        $attachment_id = (int) $request->get_param( 'id' );

        if ( ! $attachment_id ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Invalid attachment ID provided.' ], 400 );
        }

        // Get quality settings from plugin options
        $options = get_option( ICO_SETTINGS_SLUG, [] );
        $webp_quality = $options['webp_quality'] ?? 82;
        $avif_quality = $options['avif_quality'] ?? 50;

        $results = [];
        $has_converted_any_format = false;

        // Try WebP conversion
        $webp_result = ICO_Converter::convert_image( $attachment_id, 'webp', $webp_quality );
        ICO_Db::log_conversion( $attachment_id, 'webp', $webp_result ); // Log the overall result for WebP
        if ( ! is_wp_error( $webp_result ) ) {
            $results['webp'] = 'success'; // Assuming success if not a WP_Error
            $has_converted_any_format = true;
        } else {
            $results['webp'] = 'failed';
            $results['webp_message'] = $webp_result->get_error_message();
        }

        // Try AVIF conversion
        $avif_result = ICO_Converter::convert_image( $attachment_id, 'avif', $avif_quality );
        ICO_Db::log_conversion( $attachment_id, 'avif', $avif_result ); // Log the overall result for AVIF
        if ( ! is_wp_error( $avif_result ) ) {
            $results['avif'] = 'success'; // Assuming success if not a WP_Error
            $has_converted_any_format = true;
        } else {
            $results['avif'] = 'failed';
            $results['avif_message'] = $avif_result->get_error_message();
        }

        // Update the post meta to reflect overall processing status
        $overall_status = 'incomplete';
        $webp_log_status = ICO_Db::get_latest_conversion_status_for_attachment_format($attachment_id, 'webp');
        $avif_log_status = ICO_Db::get_latest_conversion_status_for_attachment_format($attachment_id, 'avif');
        if (($webp_log_status === 'success' || $webp_log_status === 'skipped_exists' || $webp_log_status === 'skipped_size') &&
            ($avif_log_status === 'success' || $avif_log_status === 'skipped_exists' || $avif_log_status === 'skipped_size')) {
            $overall_status = 'complete';
        }
        update_post_meta($attachment_id, '_ico_converted_status', $overall_status);


        if ( $has_converted_any_format ) {
            return new WP_REST_Response( [ 'success' => true, 'message' => 'Image conversion attempted.', 'results' => $results, 'overall_status' => $overall_status ], 200 );
        } else {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Image conversion failed for all formats or no support.', 'results' => $results, 'overall_status' => $overall_status ], 500 );
        }
    }

    /**
     * Clears all converted image files and conversion logs.
     *
     * @param WP_REST_Request $request The REST API request object.
     * @return WP_REST_Response The response indicating success or failure of clearing data.
     */
    public static function clear_converted_data_api( WP_REST_Request $request ) {
        // Stop any running bulk conversion before clearing data
        ICO_Background_Process::stop();

        // Delete files first
        $deleted_files_count = ICO_Converter::delete_all_converted_files();

        // Then clear database logs
        $deleted_logs_count = ICO_Db::clear_all_converted_data();

        // Also clear conversion status meta from attachments
        $deleted_meta_count = ICO_Db::clear_attachment_conversion_meta();

        if ( $deleted_files_count === false || $deleted_logs_count === false || $deleted_meta_count === false ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Failed to clear some data. Check logs for details.' ], 500 );
        }

        return new WP_REST_Response( [
            'success' => true,
            'message' => "Successfully cleared {$deleted_files_count} converted directories (files) and {$deleted_logs_count} conversion logs. Attachment meta cleared: {$deleted_meta_count}.",
            'files_deleted' => $deleted_files_count,
            'logs_deleted' => $deleted_logs_count,
            'meta_deleted' => $deleted_meta_count,
        ], 200 );
    }
}