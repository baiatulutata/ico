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

// Ensure WP-CLI is running.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    return;
}

class ICO_CLI {

    /**
     * Registers all the CLI commands for the plugin.
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
     */
    public function status( $args, $assoc_args ) {
        $total_images = ICO_Db::get_total_images_count();
        if ( is_wp_error( $total_images ) ) {
            WP_CLI::error( $total_images->get_error_message() );
        }

        $converted_images = ICO_Db::get_converted_images_count();
        if ( is_wp_error( $converted_images ) ) {
            WP_CLI::error( $converted_images->get_error_message() );
        }

        $unconverted_count = $total_images - $converted_images;
        $percentage = ( $total_images > 0 ) ? ( $converted_images / $total_images ) * 100 : 0;

        WP_CLI::line( WP_CLI::colorize( '%YImage Conversion Status:%n' ) );
        WP_CLI::line( "--------------------------" );
        WP_CLI::line( "Total Images in Media Library: " . $total_images );
        WP_CLI::line( "Converted Images: " . $converted_images );
        WP_CLI::line( "Unconverted Images: " . $unconverted_count );
        WP_CLI::line( "Completion: " . round( $percentage, 2 ) . "%" );
        WP_CLI::line( "--------------------------" );

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
     * ## EXAMPLES
     *
     * wp ico convert 123
     * wp ico convert 456 --force
     *
     */
    public function convert( $args, $assoc_args ) {
        $id = $args[0];
        $force = isset( $assoc_args['force'] );

        if ( ! is_numeric( $id ) ) {
            WP_CLI::error( "Please provide a valid numeric attachment ID." );
        }

        $id = absint( $id );
        $attachment = get_post( $id );

        if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
            WP_CLI::error( "No attachment found with ID {$id}." );
        }

        if ( get_post_meta( $id, '_ico_converted_status', true ) === 'complete' && ! $force ) {
            WP_CLI::warning( "Attachment {$id} has already been converted. Use --force to reconvert." );
            return;
        }

        WP_CLI::line( "Converting attachment ID {$id}..." );

        $options = get_option( ICO_SETTINGS_SLUG, [] );
        $webp_quality = $options['webp_quality'] ?? 82;
        $avif_quality = $options['avif_quality'] ?? 50;

        // Delete old status to allow reconversion
        if ($force) {
            delete_post_meta($id, '_ico_converted_status');
            // Optionally, delete old files here
        }

        $progress = WP_CLI\Utils\make_progress_bar( 'Converting formats', 2 );

        // Convert to WebP
        if ( ICO_Compatibility::supports_webp() ) {
            $webp_result = ICO_Converter::convert_image( $id, 'webp', $webp_quality );
            if ( is_wp_error( $webp_result ) ) {
                WP_CLI::warning( "WebP conversion failed: " . $webp_result->get_error_message() );
            } else {
                WP_CLI::line( "Successfully converted to WebP." );
            }
        } else {
            WP_CLI::warning( "WebP not supported on the server, skipping." );
        }
        $progress->tick();

        // Convert to AVIF
        if ( ICO_Compatibility::supports_avif() ) {
            $avif_result = ICO_Converter::convert_image( $id, 'avif', $avif_quality );
            if ( is_wp_error( $avif_result ) ) {
                WP_CLI::warning( "AVIF conversion failed: " . $avif_result->get_error_message() );
            } else {
                WP_CLI::line( "Successfully converted to AVIF." );
            }
        } else {
            WP_CLI::warning( "AVIF not supported on the server, skipping." );
        }
        $progress->tick();

        // Mark as complete
        update_post_meta( $id, '_ico_converted_status', 'complete' );

        $progress->finish();
        WP_CLI::success( "Conversion process finished for attachment ID {$id}." );
    }
}