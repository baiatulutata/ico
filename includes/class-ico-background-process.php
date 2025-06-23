<?php
/**
 * Handles background processing for bulk image conversions using WP-Cron.
 *
 * @package    Image_Converter_Optimizer
 * @subpackage Image_Converter_Optimizer/includes
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ICO_Background_Process {

    /**
     * Initializes background process hooks.
     */
    public static function init() {
        add_action('ico_cron_hook', array(__CLASS__, 'process_batch'));
        // Ensure WP-Cron interval for 'every_minute' is registered
        add_filter( 'cron_schedules', array( __CLASS__, 'add_custom_cron_intervals' ) );
    }

    /**
     * Adds a custom 'every_minute' cron interval.
     *
     * @param array $schedules Existing cron schedules.
     * @return array Modified schedules.
     */
    public static function add_custom_cron_intervals( $schedules ) {
        $schedules['every_minute'] = array(
            'interval' => MINUTE_IN_SECONDS, // 60 seconds
            'display'  => __( 'Every Minute', 'image-converter-optimizer' ),
        );
        return $schedules;
    }

    /**
     * Starts the bulk conversion process by scheduling the WP-Cron event.
     */
    public static function start() {
        error_log('ICO_Background_Process: start() called.');
        if (!wp_next_scheduled('ico_cron_hook')) {
            wp_schedule_event(time(), 'every_minute', 'ico_cron_hook');
            error_log('ICO_Background_Process: ico_cron_hook scheduled for every_minute.');
        } else {
            error_log('ICO_Background_Process: ico_cron_hook already scheduled.');
        }
    }

    /**
     * Stops the bulk conversion process by clearing the WP-Cron event.
     */
    public static function stop() {
        $timestamp = wp_next_scheduled('ico_cron_hook');
        if ( $timestamp ) {
            wp_clear_scheduled_hook('ico_cron_hook'); // No args needed if not passing custom args
            error_log('ICO_Background_Process: ico_cron_hook cleared.');
        } else {
            error_log('ICO_Background_Process: ico_cron_hook not found to clear.');
        }
    }

    /**
     * Processes a batch of unprocessed images for conversion.
     * This method is triggered by the 'ico_cron_hook' WP-Cron event.
     */
    public static function process_batch() {
        error_log('ICO_Background_Process: process_batch() started.');
        $options = get_option(ICO_SETTINGS_SLUG);
        $batch_size = isset($options['batch_size']) ? (int) $options['batch_size'] : 25; // Cast to int

        error_log('ICO_Background_Process: Attempting to fetch ' . $batch_size . ' unprocessed images.');

        // Corrected line: Call to ICO_Db::get_unprocessed_images_for_bulk()
        $unprocessed_images_query = ICO_Db::get_unprocessed_images_for_bulk( $batch_size );
        $unprocessed = $unprocessed_images_query->posts;

        if ( empty( $unprocessed ) ) {
            error_log('ICO_Background_Process: No more images to process. Stopping cron.');
            self::stop();
            return;
        }

        $webp_quality = isset($options['webp_quality']) ? (int) $options['webp_quality'] : 82;
        $avif_quality = isset($options['avif_quality']) ? (int) $options['avif_quality'] : 50;

        $processed_count = 0;
        foreach ( $unprocessed as $image_id ) {
            error_log('ICO_Background_Process: Processing image ID: ' . $image_id);

            // Convert to WebP
            $result_webp = ICO_Converter::convert_image( $image_id, 'webp', $webp_quality );
            ICO_Db::log_conversion( $image_id, 'webp', $result_webp );
            if ( is_wp_error( $result_webp ) ) {
                error_log('ICO_Background_Process: WebP conversion FAILED for ' . $image_id . ': ' . $result_webp->get_error_message());
            } else {
                error_log('ICO_Background_Process: WebP conversion result for ' . $image_id . ': Success');
            }

            // Convert to AVIF
            $result_avif = ICO_Converter::convert_image( $image_id, 'avif', $avif_quality );
            ICO_Db::log_conversion( $image_id, 'avif', $result_avif );
            if ( is_wp_error( $result_avif ) ) {
                error_log('ICO_Background_Process: AVIF conversion FAILED for ' . $image_id . ': ' . $result_avif->get_error_message());
            } else {
                error_log('ICO_Background_Process: AVIF conversion result for ' . $image_id . ': Success');
            }

            // Mark as completely processed only if both formats successfully handled (or skipped)
            $webp_status = ICO_Db::get_latest_conversion_status_for_attachment_format($image_id, 'webp');
            $avif_status = ICO_Db::get_latest_conversion_status_for_attachment_format($image_id, 'avif');

            if (($webp_status === 'success' || $webp_status === 'skipped') && ($avif_status === 'success' || $avif_status === 'skipped')) {
                update_post_meta($image_id, '_ico_converted_status', 'complete');
                $processed_count++;
                error_log('ICO_Background_Process: Image ID ' . $image_id . ' marked as complete.');
            } else {
                // If one or both failed, mark as partial_failure or just update the individual format logs
                // For simplicity, we just mark as incomplete if not fully converted/skipped
                update_post_meta($image_id, '_ico_converted_status', 'incomplete'); // Using 'incomplete' to denote not fully done
                error_log('ICO_Background_Process: Image ID ' . $image_id . ' marked as incomplete.');
            }
        }
        error_log('ICO_Background_Process: Batch processed ' . $processed_count . ' images out of ' . count($unprocessed) . ' in this batch.');
        error_log('ICO_Background_Process: process_batch() finished.');

        // Re-schedule cron for next batch if there are potentially more images
        // This is important: if cron stops, but there are more images, it won't restart automatically.
        // We ensure it's still scheduled until get_unprocessed_images_for_bulk returns empty.
        if ( $unprocessed_images_query->found_posts > $processed_count ) {
            if ( ! wp_next_scheduled( 'ico_cron_hook' ) ) {
                wp_schedule_event( time() + MINUTE_IN_SECONDS, 'every_minute', 'ico_cron_hook' );
                error_log('ICO_Background_Process: Re-scheduled cron for next batch.');
            }
        } else {
            self::stop(); // All done
        }
    }
}