(function($) {
    'use strict';

    let currentPage = 1;
    let totalPages = 1;
    let itemsPerPage = 20; // Default items per page for the image list

    $(document).ready(function() {
        // --- Unified Initialization for ALL Plugin Admin Pages that display progress or dashboard content ---
        // This block ensures the core status updating and polling is always set up.
        // It checks for the presence of the progress bar wrapper OR the main dashboard table.
        if ($('.ico-progress-bar-wrapper').length || $('.ico-dashboard-table').length) {
            // Immediately call to get the current status and update the progress bar/stats
            updateBulkConversionStatus();
            // Set up an interval for regular updates. 5 seconds is a good balance for active processes.
            setInterval(updateBulkConversionStatus, 5000);
        }

        // --- Dashboard Specific Initialization ---
        // This runs only if we are specifically on the dashboard page, to populate the image table.
        if ($('.ico-dashboard-table').length) {
            fetchDashboardData();
        }

        // --- Event Handlers for Image Table (Pagination, Single Conversion) ---

        // Pagination: Previous Page button
        $('#ico-prev-page').on('click', function() {
            if (currentPage > 1) {
                currentPage--;
                fetchDashboardData(); // Re-fetch data for the new page
            }
        });

        // Pagination: Next Page button
        $('#ico-next-page').on('click', function() {
            if (currentPage < totalPages) {
                currentPage++;
                fetchDashboardData(); // Re-fetch data for the new page
            }
        });

        // Items per page dropdown for the image table
        $('#ico-per-page').on('change', function() {
            itemsPerPage = parseInt($(this).val());
            currentPage = 1; // Reset to the first page when items per page changes
            fetchDashboardData(); // Re-fetch data with new per_page setting
        });

        // Handle single image conversion from the dashboard table
        $(document).on('click', '.ico-convert-single-btn', function() {
            const $button = $(this);
            const imageId = $button.data('id');
            const $row = $button.closest('tr'); // Get the entire row for localized updates

            $button.prop('disabled', true).text('Converting...');
            // Visually update status cells immediately to indicate "Converting..."
            $row.find('td:nth-child(3)').html('<span class="ico-status-pending">Converting...</span>'); // WebP Status Cell
            $row.find('td:nth-child(5)').html('<span class="ico-status-pending">Converting...</span>'); // AVIF Status Cell

            $.ajax({
                url: ico_ajax_obj.rest_url + 'ico/v1/convert-single/' + imageId,
                method: 'POST',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', ico_ajax_obj.nonce); // Set WP REST API nonce
                },
                success: function(response) {
                    console.log('Single conversion successful:', response);
                    // Re-fetch all dashboard data to ensure consistency across stats and table,
                    // as a single conversion affects overall counts and row status.
                    fetchDashboardData();
                    updateBulkConversionStatus(); // Also update the general stats right away
                },
                error: function(xhr) {
                    $button.text('Convert Now').prop('disabled', false); // Re-enable button on error
                    // Set status to failed and clear size info
                    $row.find('td:nth-child(3)').html('<span class="ico-status-failed">✗ Failed</span>'); // WebP Status
                    $row.find('td:nth-child(5)').html('<span class="ico-status-failed">✗ Failed</span>'); // AVIF Status
                    $row.find('td:nth-child(4)').text('N/A'); // WebP Size
                    $row.find('td:nth-child(6)').text('N/A'); // AVIF Size
                    console.error('Single conversion failed:', xhr.responseJSON ? xhr.responseJSON.message : xhr.statusText);
                    alert('Conversion failed for this image. Check console for details.');
                }
            });
        });

        // --- Event Handlers for Bulk Actions ---

        // Handle "Convert All Unconverted Images" button on the dashboard
        $('#ico-start-bulk-conversion-dashboard').on('click', function(e) {
            e.preventDefault();
            const $button = $(this);
            const $stopButton = $('#ico-stop-bulk-conversion-dashboard'); // Reference to stop button
            const $messageArea = $('.ico-status-message'); // Message area on the dashboard

            $button.prop('disabled', true).text('Starting Bulk Conversion...');
            $messageArea.removeClass('notice-success notice-error').addClass('notice-info').text('Bulk conversion process initiated...').show();

            $.ajax({
                url: ico_ajax_obj.rest_url + 'ico/v1/start-bulk', // API endpoint to start background process
                method: 'POST',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', ico_ajax_obj.nonce);
                },
                success: function(response) {
                    $button.text('Conversion in Progress...');
                    $stopButton.show().prop('disabled', false); // Show and enable stop button
                    $messageArea.removeClass('notice-info').addClass('notice-success').text(response.status).show();
                    // Crucial: Call update functions immediately after starting to show initial state
                    updateBulkConversionStatus();
                    fetchDashboardData(); // Refresh image list to show new 'pending' states
                },
                error: function() {
                    $button.prop('disabled', false).text('Convert All Unconverted Images');
                    $stopButton.hide(); // Hide stop button on start error
                    $messageArea.removeClass('notice-info').addClass('notice-error').text('An error occurred while trying to start bulk conversion.').show();
                }
            });
        });

        // Handle "Stop Bulk Conversion" button on the dashboard
        $('#ico-stop-bulk-conversion-dashboard').on('click', function(e) {
            e.preventDefault();
            const $button = $(this);
            const $startButton = $('#ico-start-bulk-conversion-dashboard'); // Reference to start button
            const $messageArea = $('.ico-status-message');

            $button.prop('disabled', true).text('Stopping...');
            $messageArea.removeClass('notice-success notice-error').addClass('notice-info').text('Attempting to stop bulk conversion...').show();

            $.ajax({
                url: ico_ajax_obj.rest_url + 'ico/v1/stop-bulk', // API endpoint to stop background process
                method: 'POST',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', ico_ajax_obj.nonce);
                },
                success: function(response) {
                    $button.hide(); // Hide stop button
                    $startButton.prop('disabled', false).text('Convert All Unconverted Images'); // Re-enable start button
                    $messageArea.removeClass('notice-info').addClass('notice-success').text(response.status).show();
                    updateBulkConversionStatus(); // Update status immediately
                    fetchDashboardData(); // Refresh list
                },
                error: function(xhr) {
                    $button.prop('disabled', false).text('Pause/Stop Conversion'); // Re-enable stop button on error
                    const errorMessage = xhr.responseJSON ? xhr.responseJSON.message : 'An unknown error occurred.';
                    $messageArea.removeClass('notice-info').addClass('notice-error').text('Error stopping conversion: ' + errorMessage).show();
                }
            });
        });


        // Handle "Clear All Converted Images & Logs" button in settings
        $('#ico-clear-converted-images').on('click', function(e) {
            e.preventDefault();
            const $button = $(this);
            const $messageArea = $('#ico-clear-status-message'); // Specific message area for clear action on settings page

            if (confirm('Are you absolutely sure you want to delete ALL converted WebP/AVIF images and clear ALL conversion logs? This action cannot be undone.')) {
                $button.prop('disabled', true).text('Clearing...');
                $messageArea.removeClass('notice-success notice-error').addClass('notice-info').text('Clearing converted data... This may take a moment.').show();

                $.ajax({
                    url: ico_ajax_obj.rest_url + 'ico/v1/clear-data', // API endpoint to clear data
                    method: 'POST',
                    beforeSend: function(xhr) {
                        xhr.setRequestHeader('X-WP-Nonce', ico_ajax_obj.nonce);
                    },
                    success: function(response) {
                        $messageArea.removeClass('notice-info').addClass('notice-success').text(response.message).show();
                        $button.text('Cleared!').prop('disabled', true); // Keep button disabled after success

                        // Force a full page reload after a short delay (2 seconds)
                        // This ensures all dashboard stats and table content are reset correctly.
                        setTimeout(function() {
                            window.location.reload();
                        }, 2000);
                    },
                    error: function(xhr) {
                        $button.text('Clear All Converted Images & Logs').prop('disabled', false); // Re-enable button on error
                        const errorMessage = xhr.responseJSON ? xhr.responseJSON.message : 'An unknown error occurred.';
                        $messageArea.removeClass('notice-info').addClass('notice-error').text('Error clearing data: ' + errorMessage).show();
                    }
                });
            }
        });
    }); // End of $(document).ready

    /**
     * Fetches general bulk conversion status from the backend
     * and updates the progress bar, dashboard statistics cards, AND button states.
     * This function is called on page load and periodically.
     */
    function updateBulkConversionStatus() {
        $.get(ico_ajax_obj.rest_url + 'ico/v1/status', function(response) {
            var totalImages = response.total;
            var webpConverted = response.webp_converted;
            var avifConverted = response.avif_converted;
            var unconvertedImages = response.unconverted;
            var isBulkRunning = response.is_bulk_running; // NEW: Get running status from API response

            // Determine total conversion tasks (each image ideally has 2 tasks: WebP & AVIF)
            var totalConversionTasks = totalImages * 2;
            var completedConversionTasks = webpConverted + avifConverted;
            var percentageTasks = totalConversionTasks > 0 ? (completedConversionTasks / totalConversionTasks) * 100 : 0;

            // Update numerical stats cards on the dashboard
            $('#ico-stat-total').text(totalImages);
            $('#ico-stat-webp').text(webpConverted);
            $('#ico-stat-avif').text(avifConverted);
            $('#ico-stat-unconverted').text(unconvertedImages);

            // Update the progress bar display
            $('.ico-progress-bar-inner').css('width', percentageTasks.toFixed(2) + '%');
            $('.ico-progress-text').text(completedConversionTasks + ' / ' + totalConversionTasks + ' conversions (' + percentageTasks.toFixed(2) + '%)');

            // NEW: Manage button states based on 'isBulkRunning' and unconverted images count
            const $startButton = $('#ico-start-bulk-conversion-dashboard');
            const $stopButton = $('#ico-stop-bulk-conversion-dashboard');

            if (isBulkRunning) {
                // If bulk conversion is running
                $startButton.prop('disabled', true).text('Conversion in Progress...');
                $stopButton.show().prop('disabled', false); // Show and enable stop button
            } else {
                // If bulk conversion is NOT running
                $stopButton.hide(); // Hide stop button
                // Only enable 'Start' button if there are unconverted images remaining
                if (unconvertedImages > 0) {
                    $startButton.prop('disabled', false).text('Convert All Unconverted Images');
                } else {
                    $startButton.prop('disabled', true).text('All Images Processed');
                }
            }

        }).fail(function(xhr) {
            console.error('Failed to fetch bulk conversion status:', xhr.status, xhr.statusText);
            // On API error, display error message and attempt to reset buttons to default state
            $('.ico-progress-text').text('Error loading progress. Please refresh.');
            $('#ico-start-bulk-conversion-dashboard').prop('disabled', false).text('Convert All Unconverted Images');
            $('#ico-stop-bulk-conversion-dashboard').hide();
        });
    }

    /**
     * Fetches detailed dashboard table data (image list with statuses).
     * Populates the table and updates pagination controls.
     * This function is called on page load and after certain actions (e.g., single convert).
     */
    function fetchDashboardData() {
        // Show a loading indicator while data is being fetched
        $('#ico-image-list').html('<tr><td colspan="7" style="text-align:center;">Loading images...</td></tr>');
        $.ajax({
            url: ico_ajax_obj.rest_url + 'ico/v1/dashboard-data',
            method: 'GET',
            data: {
                page: currentPage,
                per_page: itemsPerPage
            },
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', ico_ajax_obj.nonce);
            },
            success: function(response) {
                populateDashboardTable(response.images_list);
                totalPages = response.total_pages;
                $('#ico-current-page').text(currentPage);
                $('#ico-total-pages').text(totalPages);
                // Enable/disable pagination buttons based on current page
                $('#ico-prev-page').prop('disabled', currentPage === 1);
                $('#ico-next-page').prop('disabled', currentPage === totalPages);
                // After the detailed data is loaded, ensure general stats are also updated
                updateBulkConversionStatus();
            },
            error: function(xhr) {
                console.error('Failed to fetch dashboard data:', xhr.status, xhr.statusText);
                $('#ico-image-list').html('<tr><td colspan="7" style="text-align:center; color:red;">Failed to load images. Please check server logs.</td></tr>');
            }
        });
    }

    /**
     * Populates the dashboard table with image data received from the API.
     * @param {Array} images - Array of image objects from the API response.
     */
    function populateDashboardTable(images) {
        let tbody = $('#ico-image-list');
        tbody.empty(); // Clear existing rows

        if (images.length === 0) {
            tbody.append('<tr><td colspan="7" style="text-align:center;">No images found in your media library.</td></tr>');
            return;
        }

        images.forEach(image => {
            let webpStatusHtml = formatStatus(image.webp_status);
            let avifStatusHtml = formatStatus(image.avif_status);

            // Determine if the "Convert Now" button should be disabled.
            // It's disabled if both WebP and AVIF are successfully converted OR skipped (by size/existence).
            const convertButtonDisabled = (image.webp_status === 'success' || image.webp_status === 'skipped_exists' || image.webp_status === 'skipped_size') &&
            (image.avif_status === 'success' || image.avif_status === 'skipped_exists' || image.avif_status === 'skipped_size') ? 'disabled' : '';
            const convertButtonText = convertButtonDisabled ? 'All Converted' : 'Convert Now';

            tbody.append(`
                <tr>
                    <td>
                        <div class="ico-image-thumbnail">
                            <img src="${image.thumbnail_url}" alt="${image.title}" />
                            <span>${image.title}</span>
                        </div>
                    </td>
                    <td>${image.original_size}</td>
                    <td class="ico-status-cell">${webpStatusHtml}</td>
                    <td>${image.webp_size}</td>
                    <td class="ico-status-cell">${avifStatusHtml}</td>
                    <td>${image.avif_size}</td>
                    <td>
                        <button class="button ico-convert-single-btn" data-id="${image.id}" ${convertButtonDisabled}>${convertButtonText}</button>
                    </td>
                </tr>
            `);
        });
    }

    /**
     * Helper function to format conversion status strings into HTML with appropriate styling.
     * @param {string} status - The conversion status (e.g., 'success', 'failed', 'pending', 'skipped_size', 'skipped_exists').
     * @returns {string} HTML string for displaying the formatted status.
     */
    function formatStatus(status) {
        switch(status) {
            case 'success':
                return '<span class="ico-status-success">✓ Converted</span>';
            case 'failed':
                return '<span class="ico-status-failed">✗ Failed</span>';
            case 'skipped_exists': // Skipped because file already existed
                return '<span class="ico-status-skipped">⟳ Skipped (Exists)</span>';
            case 'skipped_size': // Skipped due to file size being larger or insufficient savings
                return '<span class="ico-status-skipped-size">⟳ Skipped (Size)</span>';
            case 'pending':
            default:
                return '<span class="ico-status-pending">Pending</span>';
        }
    }

})(jQuery);