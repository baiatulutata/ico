<div class="wrap">
    <h1>Image Converter Dashboard</h1>

    <div class="ico-dashboard-header">
        <p>Welcome to your Image Converter & Optimizer Dashboard. Here you can see the overall conversion progress and manage your settings.</p>
        <div class="ico-dashboard-actions">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=ico-settings' ) ); ?>" class="button button-secondary">Go to Settings</a>
            <div class="ico-bulk-action-buttons">
                <button id="ico-start-bulk-conversion-dashboard" class="button button-primary button-hero" disabled>Convert All Unconverted Images</button>
                <button id="ico-stop-bulk-conversion-dashboard" class="button button-secondary button-hero" style="display:none;">Pause/Stop Conversion</button>
            </div>
        </div>
    </div>

    <div class="ico-stats-grid">
        <div class="ico-stat-card">
            <h3>Total Images</h3>
            <p id="ico-stat-total" class="ico-stat-value">...</p>
        </div>
        <div class="ico-stat-card">
            <h3>WebP Converted</h3>
            <p id="ico-stat-webp" class="ico-stat-value">...</p>
        </div>
        <div class="ico-stat-card">
            <h3>AVIF Converted</h3>
            <p id="ico-stat-avif" class="ico-stat-value">...</p>
        </div>
        <div class="ico-stat-card">
            <h3>Unconverted</h3>
            <p id="ico-stat-unconverted" class="ico-stat-value">...</p>
        </div>
    </div>

    <hr>

    <h2>Bulk Conversion Progress</h2>
    <div id="ico-bulk-conversion-status" class="ico-card">
        <div class="ico-progress-bar-wrapper">
            <div class="ico-progress-bar-inner"></div>
        </div>
        <p class="ico-progress-text">Loading status...</p>
        <p class="ico-status-message notice" style="display:none; margin-top:10px;"></p>
    </div>

    <hr>

    <h2>Image Conversion Status</h2>
    <p>Below is a list of your media files and their current conversion status. You can convert individual images or check their details.</p>

    <div class="ico-table-controls">
        <label for="ico-per-page">Items per page:</label>
        <select id="ico-per-page">
            <option value="10">10</option>
            <option value="20" selected>20</option>
            <option value="50">50</option>
        </select>
        <div class="ico-pagination">
            <button id="ico-prev-page" class="button" disabled>&laquo; Previous</button>
            <span id="ico-current-page">1</span> / <span id="ico-total-pages">1</span>
            <button id="ico-next-page" class="button" disabled>Next &raquo;</button>
        </div>
    </div>

    <table class="wp-list-table widefat fixed striped ico-dashboard-table">
        <thead>
        <tr>
            <th>Image</th>
            <th>Original Size</th>
            <th>WebP Status</th>
            <th>WebP Size</th>
            <th>AVIF Status</th>
            <th>AVIF Size</th>
            <th>Actions</th>
        </tr>
        </thead>
        <tbody id="ico-image-list">
        <tr><td colspan="7" style="text-align:center;">Loading images...</td></tr>
        </tbody>
    </table>
</div>