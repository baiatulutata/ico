<?php
/**
 * WP-CLI commands for the Image Converter & Optimizer plugin.
 *
 * @package    Image_Converter_Optimizer
 * @subpackage Image_Converter_Optimizer/includes
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Ensure WP-CLI is running before defining commands.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    return;
}

class ICO_CLI {

    /**
     * Registers all the CLI commands for the plugin under the 'ico' namespace.
     */
    public static function register_commands() {
        WP_CLI::add_command( 'ico', 'ICO_CLI' );
    }

    /**
     * Checks the status of image conversions.
     *
     * Provides a summary of total images, converted images, and unconverted images.
     *
     * ## EXAMPLES
     *
     * wp ico status
     *
     * @since 1.0.0
     * @param array $args Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function status( $args, $assoc_args ) {
        $total_images = ICO_Db::get_total_images_count();
        if ( is_wp_error( $total_images ) ) {
            WP_CLI::error( $total_images->get_error_message() );
        }

        $webp_converted = ICO_Db::get_webp_converted_count();
        $avif_converted = ICO_Db::get_avif_converted_count();
        $overall_converted = ICO_Db::get_converted_images_count(); // Images completed for at least one format
        $unconverted_count = $total_images - $overall_converted;

        $total_conversion_tasks = $total_images * 2; // Each image has two tasks: WebP and AVIF
        $completed_conversion_tasks = $webp_converted + $avif_converted;
        $percentage_tasks = ( $total_conversion_tasks > 0 ) ? ( $completed_conversion_tasks / $total_conversion_tasks ) * 100 : 0;

        WP_CLI::line( WP_CLI::colorize( '%YImage Conversion Status:%n' ) );
        WP_CLI::line( "----------------------------------" );
        WP_CLI::line( "Total Images in Media Library: " . $total_images );
        WP_CLI::line( "WebP Converted: " . $webp_converted );
        WP_CLI::line( "AVIF Converted: " . $avif_converted );
        WP_CLI::line( "Unconverted Images: " . $unconverted_count );
        WP_CLI::line( "Overall Conversion Progress: " . round( $percentage_tasks, 2 ) . "%" );
        WP_CLI::line( "----------------------------------" );

        if ( wp_next_scheduled( 'ico_cron_hook' ) ) {
            WP_CLI::success( "Bulk conversion process is currently scheduled to run." );
        } else {
            WP_CLI::line( "Bulk conversion process is not currently running." );
        }
    }

    /**
     * Starts the background bulk conversion process.
     *
     * ## EXAMPLES
     *
     * wp ico bulk-start
     *
     * @since 1.0.0
     * @param array $args Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function bulk_start( $args, $assoc_args ) {
        if ( wp_next_scheduled( 'ico_cron_hook' ) ) {
            WP_CLI::warning( 'Bulk conversion process is already running.' );
            return;
        }

        WP_CLI::line( 'Starting bulk conversion process...' );
        ICO_Background_Process::start();

        if ( wp_next_scheduled( 'ico_cron_hook' ) ) {
            WP_CLI::success( 'Bulk conversion process has been started successfully. It will run in the background via WP-Cron.' );
        } else {
            WP_CLI::error( 'Failed to start the bulk conversion process.' );
        }
    }

    /**
     * Stops the background bulk conversion process.
     *
     * ## EXAMPLES
     *
     * wp ico bulk-stop
     *
     * @since 1.0.0
     * @param array $args Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function bulk_stop( $args, $assoc_args ) {
        if ( ! wp_next_scheduled( 'ico_cron_hook' ) ) {
            WP_CLI::warning( 'Bulk conversion process is not currently running.' );
            return;
        }

        WP_CLI::line( 'Stopping bulk conversion process...' );
        ICO_Background_Process::stop();

        if ( ! wp_next_scheduled( 'ico_cron_hook' ) ) {
            WP_CLI::success( 'Bulk conversion process has been stopped.' );
        } else {
            WP_CLI::error( 'Failed to stop the bulk conversion process.' );
        }
    }

    /**
     * Converts a single image by its attachment ID.
     *
     * ## OPTIONS
     *
     * <id>
     * : The ID of the attachment to convert.
     *
     * [--force]
     * : Force reconversion even if the image has already been converted.
     *
     * [--format=<format>]
     * : Specify a format to convert ('webp' or 'avif'). Default is both.
     *
     * ## EXAMPLES
     *
     * wp ico convert 123
     * wp ico convert 456 --force
     * wp ico convert 789 --format=webp
     *
     * @since 1.0.0
     * @param array $args Positional arguments (attachment ID).
     * @param array $assoc_args Associative arguments (--force, --format).
     */
    public function convert( $args, $assoc_args ) {
        $id = isset( $args[0] ) ? absint( $args[0] ) : 0;
        $force = isset( $assoc_args['force'] );
        $format_to_convert = isset( $assoc_args['format'] ) ? strtolower( $assoc_args['format'] ) : 'both';

        if ( ! $id ) {
            WP_CLI::error( "Please provide a valid numeric attachment ID." );
        }

        $attachment = get_post( $id );
        if ( ! $attachment || 'attachment' !== $attachment->post_type || ! wp_attachment_is_image( $id ) ) {
            WP_CLI::error( "No valid image attachment found with ID {$id}." );
        }

        $current_status = get_post_meta( $id, '_ico_converted_status', true );
        if ( $current_status === 'complete' && ! $force ) {
            WP_CLI::warning( "Attachment {$id} is already marked as 'complete'. Use --force to reconvert." );
            return;
        }

        WP_CLI::line( "Attempting conversion for attachment ID {$id}..." );

        $options = get_option( ICO_SETTINGS_SLUG, [] );
        $webp_quality = $options['webp_quality'] ?? 82;
        $avif_quality = $options['avif_quality'] ?? 50;

        // Reset overall status if forcing a reconversion
        if ($force) {
            delete_post_meta($id, '_ico_converted_status');
            // Optionally, delete old converted files for this attachment from directories
            // This would require a specific method in ICO_Converter or custom logic here.
        }

        $formats_processed = 0;
        $success_messages = [];
        $error_messages = [];

        // Convert to WebP
        if ( $format_to_convert === 'webp' || $format_to_convert === 'both' ) {
            WP_CLI::line( " - Converting to WebP..." );
            if ( ICO_Compatibility::supports_webp() ) {
                $result_webp = ICO_Converter::convert_image( $id, 'webp', $webp_quality );
                ICO_Db::log_conversion( $id, 'webp', $result_webp );
                if ( is_wp_error( $result_webp ) ) {
                    $error_messages[] = "WebP conversion failed: " . $result_webp->get_error_message();
                } else {
                    $log_status = ICO_Db::get_latest_conversion_status_for_attachment_format($id, 'webp');
                    $success_messages[] = "WebP conversion status: {$log_status}";
                    $formats_processed++;
                }
            } else {
                $error_messages[] = "WebP not supported on server, skipping WebP conversion.";
            }
        }

        // Convert to AVIF
        if ( $format_to_convert === 'avif' || $format_to_convert === 'both' ) {
            WP_CLI::line( " - Converting to AVIF..." );
            if ( ICO_Compatibility::supports_avif() ) {
                $result_avif = ICO_Converter::convert_image( $id, 'avif', $avif_quality );
                ICO_Db::log_conversion( $id, 'avif', $result_avif );
                if ( is_wp_error( $result_avif ) ) {
                    $error_messages[] = "AVIF conversion failed: " . $result_avif->get_error_message();
                } else {
                    $log_status = ICO_Db::get_latest_conversion_status_for_attachment_format($id, 'avif');
                    $success_messages[] = "AVIF conversion status: {$log_status}";
                    $formats_processed++;
                }
            } else {
                $error_messages[] = "AVIF not supported on server, skipping AVIF conversion.";
            }
        }

        // Update overall post meta status after attempting conversions
        $webp_log_status = ICO_Db::get_latest_conversion_status_for_attachment_format($id, 'webp');
        $avif_log_status = ICO_Db::get_latest_conversion_status_for_attachment_format($id, 'avif');

        if (($webp_log_status === 'success' || $webp_log_status === 'skipped_exists' || $webp_log_status === 'skipped_size') &&
            ($avif_log_status === 'success' || $avif_log_status === 'skipped_exists' || $avif_log_status === 'skipped_size')) {
            update_post_meta($id, '_ico_converted_status', 'complete');
            WP_CLI::success( "Attachment {$id} conversion complete (both formats processed)." );
        } else {
            update_post_meta($id, '_ico_converted_status', 'incomplete'); // Mark as incomplete if not fully done
            WP_CLI::warning( "Attachment {$id} conversion finished with incomplete status." );
        }

        // Output results
        foreach ( $success_messages as $msg ) {
            WP_CLI::success( $msg );
        }
        foreach ( $error_messages as $msg ) {
            WP_CLI::error( $msg );
        }

        if ( empty( $success_messages ) && empty( $error_messages ) ) {
            WP_CLI::warning( "No conversion attempts made for attachment ID {$id}." );
        }
    }

    /**
     * Clears all converted image files and conversion logs from the database.
     *
     * ## EXAMPLES
     *
     * wp ico clear-all
     *
     * @since 1.0.0
     * @param array $args Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function clear_all( $args, $assoc_args ) {
        WP_CLI::confirm( 'Are you sure you want to delete ALL converted WebP/AVIF images and clear ALL conversion logs? This cannot be undone.' );

        WP_CLI::line( 'Stopping any running bulk conversion process...' );
        ICO_Background_Process::stop();

        WP_CLI::line( 'Deleting converted files...' );
        $deleted_files_count = ICO_Converter::delete_all_converted_files();
        if ( $deleted_files_count === false ) {
            WP_CLI::warning( 'Failed to delete some converted files. Check server permissions.' );
        } else {
            WP_CLI::success( "Successfully deleted converted image directories. ({$deleted_files_count} directories processed)" );
        }

        WP_CLI::line( 'Clearing conversion logs from database...' );
        $deleted_logs_count = ICO_Db::clear_all_converted_data();
        if ( $deleted_logs_count === false ) {
            WP_CLI::warning( 'Failed to clear conversion logs from database.' );
        } else {
            WP_CLI::success( "Successfully cleared {$deleted_logs_count} conversion log entries." );
        }

        WP_CLI::line( 'Clearing attachment conversion metadata...' );
        $deleted_meta_count = ICO_Db::clear_attachment_conversion_meta();
        if ( $deleted_meta_count === false ) {
            WP_CLI::warning( 'Failed to clear attachment conversion metadata.' );
        } else {
            WP_CLI::success( "Successfully cleared {$deleted_meta_count} attachment meta keys." );
        }

        WP_CLI::success( 'All converted data and logs have been cleared.' );
    }
}