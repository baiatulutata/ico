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
        register_rest_route( 'ico/v1', '/status', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'get_status' ),
            'permission_callback' => '__return_true', // Public access for status, but sensitive actions should require capability
        ) );
        register_rest_route( 'ico/v1', '/start-bulk', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'start_bulk_conversion' ),
            'permission_callback' => function () { return current_user_can( 'manage_options' ); },
        ) );
        register_rest_route( 'ico/v1', '/stop-bulk', array( // NEW ENDPOINT
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'stop_bulk_conversion' ),
            'permission_callback' => function () { return current_user_can( 'manage_options' ); },
        ) );
        register_rest_route( 'ico/v1', '/dashboard-data', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'get_dashboard_data' ),
            'permission_callback' => function () { return current_user_can( 'manage_options' ); },
            'args'                => [
                'per_page' => [
                    'type'     => 'integer',
                    'default'  => 20,
                    'minimum'  => 1,
                    'maximum'  => 100,
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
        register_rest_route( 'ico/v1', '/convert-single/(?P<id>\d+)', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'convert_single_image_api' ),
            'permission_callback' => function () { return current_user_can( 'manage_options' ); },
            'args'                => [
                'id' => [
                    'sanitize_callback' => 'absint',
                    'validate_callback' => function($param, $request, $key) {
                        return is_numeric( $param );
                    },
                ],
            ],
        ) );
        register_rest_route( 'ico/v1', '/clear-data', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'clear_converted_data_api' ),
            'permission_callback' => function () { return current_user_can( 'manage_options' ); },
        ) );
    }
    /**
     * Gets overall conversion status (total, converted images).
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function get_status( WP_REST_Request $request ) {
        $total_images     = ICO_Db::get_total_images_count();
        $webp_converted   = ICO_Db::get_webp_converted_count();
        $avif_converted   = ICO_Db::get_avif_converted_count();
        $unconverted      = $total_images - ICO_Db::get_converted_images_count();
        $is_bulk_running  = (bool) wp_next_scheduled( 'ico_cron_hook' ); // Check if cron is scheduled

        return new WP_REST_Response( array(
            'total'       => $total_images,
            'webp_converted' => $webp_converted,
            'avif_converted' => $avif_converted,
            'unconverted' => $unconverted,
            'is_bulk_running' => $is_bulk_running, // NEW: report running status
        ), 200 );
    }

    /**
     * Starts the background bulk conversion process.
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function start_bulk_conversion( WP_REST_Request $request ) {
        if ( wp_next_scheduled( 'ico_cron_hook' ) ) {
            return new WP_REST_Response( array( 'status' => 'Bulk conversion is already running.' ), 200 );
        }
        ICO_Background_Process::start();
        return new WP_REST_Response( array( 'status' => 'Bulk conversion started.' ), 200 );
    }

    /**
     * Stops the background bulk conversion process. (NEW METHOD)
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function stop_bulk_conversion( WP_REST_Request $request ) {
        ICO_Background_Process::stop();
        // Check if it actually stopped
        if ( ! wp_next_scheduled( 'ico_cron_hook' ) ) {
            return new WP_REST_Response( array( 'status' => 'Bulk conversion stopped successfully.' ), 200 );
        } else {
            return new WP_REST_Response( array( 'status' => 'Failed to stop bulk conversion.', 'error' => true ), 500 );
        }
    }
    /**
     * Retrieves data for the dashboard, including stats and paginated image list.
     * @param WP_REST_Request $request
     * @return WP_REST_Response
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
     * Converts a single image via API.
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function convert_single_image_api( WP_REST_Request $request ) {
        $attachment_id = (int) $request->get_param( 'id' );

        if ( ! $attachment_id ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Invalid attachment ID.' ], 400 );
        }

        $options = get_option( ICO_SETTINGS_SLUG, [] );
        $webp_quality = $options['webp_quality'] ?? 82;
        $avif_quality = $options['avif_quality'] ?? 50;

        $results = [];
        $has_converted = false;

        // Try WebP conversion
        if ( ICO_Compatibility::supports_webp() ) {
            $webp_result = ICO_Converter::convert_image( $attachment_id, 'webp', $webp_quality );
            ICO_Db::log_conversion( $attachment_id, 'webp', $webp_result );
            if ( ! is_wp_error( $webp_result ) && !empty($webp_result) ) {
                $results['webp'] = 'success';
                $has_converted = true;
            } else {
                $results['webp'] = 'failed';
                $results['webp_message'] = is_wp_error($webp_result) ? $webp_result->get_error_message() : 'Unknown error';
            }
        } else {
            $results['webp'] = 'skipped (no support)';
        }

        // Try AVIF conversion
        if ( ICO_Compatibility::supports_avif() ) {
            $avif_result = ICO_Converter::convert_image( $attachment_id, 'avif', $avif_quality );
            ICO_Db::log_conversion( $attachment_id, 'avif', $avif_result );
            if ( ! is_wp_error( $avif_result ) && !empty($avif_result) ) {
                $results['avif'] = 'success';
                $has_converted = true;
            } else {
                $results['avif'] = 'failed';
                $results['avif_message'] = is_wp_error($avif_result) ? $avif_result->get_error_message() : 'Unknown error';
            }
        } else {
            $results['avif'] = 'skipped (no support)';
        }
        error_log('API - Single convert for ID: ' . $attachment_id);
        error_log('API - WebP result: ' . print_r($webp_result, true));
        error_log('API - AVIF result: ' . print_r($avif_result, true));
        if ( $has_converted ) {
            update_post_meta( $attachment_id, '_ico_converted_status', 'complete' );
            return new WP_REST_Response( [ 'success' => true, 'message' => 'Image converted successfully.', 'results' => $results ], 200 );
        } else {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Image conversion failed for both formats or no support.', 'results' => $results ], 500 );
        }
    }
    /**
     * Clears all converted image files and conversion logs.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function clear_converted_data_api( WP_REST_Request $request ) {
        // Delete files first
        $deleted_files_count = ICO_Converter::delete_all_converted_files();

        // Then clear database logs
        $deleted_logs_count = ICO_Db::clear_all_converted_data();

        // Also clear conversion status meta from attachments
        ICO_Db::clear_attachment_conversion_meta();

        if ( $deleted_files_count === false || $deleted_logs_count === false ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Failed to clear some data. Check logs.' ], 500 );
        }

        return new WP_REST_Response( [
            'success' => true,
            'message' => "Successfully cleared {$deleted_files_count} converted files and {$deleted_logs_count} conversion logs.",
            'files_deleted' => $deleted_files_count,
            'logs_deleted' => $deleted_logs_count,
        ], 200 );
    }
}