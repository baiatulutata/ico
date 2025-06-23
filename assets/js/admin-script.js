(function($) {
    'use strict';

    let currentPage = 1;
    let totalPages = 1;
    let itemsPerPage = 20; // Default

    $(document).ready(function() {
        // Only run dashboard specific JS on the dashboard page
        if ($('.ico-dashboard-table').length) {
            fetchDashboardData(); // Initial load for the dashboard table
            updateBulkConversionStatus(); // Initial status update for bulk conversion on dashboard
            setInterval(updateBulkConversionStatus, 15000); // Poll every 15 seconds for general stats
        }

        // Only run bulk conversion specific JS on the bulk conversion page
        // (Ensures this block doesn't conflict if #ico-start-bulk-conversion-dashboard exists)
        if ($('#ico-bulk-conversion-status').length && $('#ico-start-bulk-conversion-dashboard').length === 0) {
            updateBulkConversionStatus();
            setInterval(updateBulkConversionStatus, 5000); // Poll every 5 seconds
        }


        // Handle pagination buttons
        $('#ico-prev-page').on('click', function() {
            if (currentPage > 1) {
                currentPage--;
                fetchDashboardData();
            }
        });

        $('#ico-next-page').on('click', function() {
            if (currentPage < totalPages) {
                currentPage++;
                fetchDashboardData();
            }
        });

        // Handle items per page change
        $('#ico-per-page').on('change', function() {
            itemsPerPage = parseInt($(this).val());
            currentPage = 1; // Reset to first page
            fetchDashboardData();
        });

        // Handle single image conversion from dashboard
        $(document).on('click', '.ico-convert-single-btn', function() {
            const $button = $(this);
            const imageId = $button.data('id');
            const $row = $button.closest('tr'); // Get the entire row

            $button.prop('disabled', true).text('Converting...');
            // Visually update status cells immediately
            $row.find('td:nth-child(3)').html('<span class="ico-status-pending">Converting...</span>'); // WebP Status
            $row.find('td:nth-child(5)').html('<span class="ico-status-pending">Converting...</span>'); // AVIF Status

            $.ajax({
                url: ico_ajax_obj.rest_url + 'ico/v1/convert-single/' + imageId,
                method: 'POST',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', ico_ajax_obj.nonce);
                },
                success: function(response) {
                    console.log('Single conversion successful:', response);
                    // Re-fetch all dashboard data to ensure consistency across stats and table
                    fetchDashboardData();
                    updateBulkConversionStatus(); // Also update the general stats
                },
                error: function(xhr) {
                    $button.text('Convert Now').prop('disabled', false); // Re-enable button
                    // Set status to failed
                    $row.find('td:nth-child(3)').html('<span class="ico-status-failed">✗ Failed</span>'); // WebP Status
                    $row.find('td:nth-child(5)').html('<span class="ico-status-failed">✗ Failed</span>'); // AVIF Status
                    $row.find('td:nth-child(4)').text('N/A'); // WebP Size
                    $row.find('td:nth-child(6)').text('N/A'); // AVIF Size
                    console.error('Single conversion failed:', xhr.responseJSON ? xhr.responseJSON.message : xhr.statusText);
                    alert('Conversion failed for this image. Check console for details.');
                }
            });
        });

        // Handle "Convert All Unconverted Images" button on dashboard
        $('#ico-start-bulk-conversion-dashboard').on('click', function(e) {
            e.preventDefault();
            const $button = $(this);
            const $messageArea = $('.ico-status-message'); // Ensure this element exists on the dashboard

            $button.prop('disabled', true).text('Starting Bulk Conversion...');
            $messageArea.removeClass('notice-success notice-error').addClass('notice-info').text('Bulk conversion process initiated...').show();

            $.ajax({
                url: ico_ajax_obj.rest_url + 'ico/v1/start-bulk',
                method: 'POST',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', ico_ajax_obj.nonce);
                },
                success: function(response) {
                    $button.text('Conversion in Progress...');
                    $messageArea.removeClass('notice-info').addClass('notice-success').text(response.status).show();
                    updateBulkConversionStatus(); // Update status immediately after starting
                    fetchDashboardData(); // Refresh list to show initial pending
                },
                error: function() {
                    $button.prop('disabled', false).text('Convert All Unconverted Images');
                    $messageArea.removeClass('notice-info').addClass('notice-error').text('An error occurred while trying to start bulk conversion.').show();
                }
            });
        });

        // Handle "Clear All Converted Images & Logs" button in settings
        $('#ico-clear-converted-images').on('click', function(e) {
            e.preventDefault();
            const $button = $(this);
            const $messageArea = $('#ico-clear-status-message'); // Specific message area for clear action

            if (confirm('Are you absolutely sure you want to delete ALL converted WebP/AVIF images and clear ALL conversion logs? This action cannot be undone.')) {
                $button.prop('disabled', true).text('Clearing...');
                $messageArea.removeClass('notice-success notice-error').addClass('notice-info').text('Clearing converted data... This may take a moment.').show();

                $.ajax({
                    url: ico_ajax_obj.rest_url + 'ico/v1/clear-data',
                    method: 'POST',
                    beforeSend: function(xhr) {
                        xhr.setRequestHeader('X-WP-Nonce', ico_ajax_obj.nonce);
                    },
                    success: function(response) {
                        $messageArea.removeClass('notice-info').addClass('notice-success').text(response.message).show();
                        $button.text('Cleared!').prop('disabled', true); // Keep disabled until page refresh

                        // Force a full page reload or redirect after successful clear
                        // to ensure all statuses and counts are reset on dashboard/tables.
                        setTimeout(function() {
                            window.location.reload();
                        }, 2000);
                    },
                    error: function(xhr) {
                        $button.text('Clear All Converted Images & Logs').prop('disabled', false);
                        const errorMessage = xhr.responseJSON ? xhr.responseJSON.message : 'An unknown error occurred.';
                        $messageArea.removeClass('notice-info').addClass('notice-error').text('Error clearing data: ' + errorMessage).show();
                    }
                });
            }
        });
    });

    // Fetches general bulk conversion status and updates progress bar and dashboard stats
    function updateBulkConversionStatus() {
        $.get(ico_ajax_obj.rest_url + 'ico/v1/status', function(response) {
            var totalImages = response.total;
            var webpConverted = response.webp_converted;
            var avifConverted = response.avif_converted;
            var unconvertedImages = response.unconverted;

            // For the progress bar on bulk conversion, it's about total *tasks*. If each image has 2 tasks (webp, avif):
            var totalConversionTasks = totalImages * 2;
            var completedConversionTasks = webpConverted + avifConverted;
            var percentageTasks = totalConversionTasks > 0 ? (completedConversionTasks / totalConversionTasks) * 100 : 0;

            // Update stats cards on dashboard
            $('#ico-stat-total').text(totalImages);
            $('#ico-stat-webp').text(webpConverted);
            $('#ico-stat-avif').text(avifConverted);
            $('#ico-stat-unconverted').text(unconvertedImages);

            // Update progress bar on dashboard (and bulk conversion page if exists)
            $('.ico-progress-bar-inner').css('width', percentageTasks.toFixed(2) + '%');
            $('.ico-progress-text').text(completedConversionTasks + ' / ' + totalConversionTasks + ' conversions (' + percentageTasks.toFixed(2) + '%)');
        });
    }

    // Fetches detailed dashboard table data
    function fetchDashboardData() {
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
                $('#ico-prev-page').prop('disabled', currentPage === 1);
                $('#ico-next-page').prop('disabled', currentPage === totalPages);
                // Also update the general stats immediately after fetching dashboard data
                updateBulkConversionStatus();
            },
            error: function() {
                $('#ico-image-list').html('<tr><td colspan="7" style="text-align:center; color:red;">Failed to load images.</td></tr>');
            }
        });
    }

    // Populates the dashboard table with image data
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

            // Determine if the convert button should be enabled
            const convertButtonDisabled = (image.webp_status === 'success' || image.webp_status === 'skipped') &&
            (image.avif_status === 'success' || image.avif_status === 'skipped') ? 'disabled' : '';
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

    // Helper function to format status for display with appropriate styling
    function formatStatus(status) {
        switch(status) {
            case 'success':
                return '<span class="ico-status-success">✓ Converted</span>';
            case 'failed':
                return '<span class="ico-status-failed">✗ Failed</span>';
            case 'skipped':
                return '<span class="ico-status-skipped">⟳ Skipped</span>';
            case 'pending':
            default:
                return '<span class="ico-status-pending">Pending</span>';
        }
    }

})(jQuery);