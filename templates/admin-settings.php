<div class="wrap">
    <h1>Image Converter Settings</h1>
    <form method="post" action="options.php">
        <?php
        settings_fields(ICO_SETTINGS_SLUG);
        do_settings_sections('ico-settings');
        submit_button();
        ?>
    </form>

    <div class="ico-card">
        <h2>Server Configuration (for manual setup)</h2>
        <?php
        $nginx_rules = ICO_Nginx::get_rules();
        echo "<h3>For Nginx Servers:</h3>";
        echo "<textarea readonly rows='10' cols='100'>" . esc_textarea($nginx_rules) . "</textarea>";
        ?>
        <p class="description">Copy these rules to your Nginx configuration block for the site. Remember to reload Nginx after making changes.</p>
    </div>

    <div class="ico-card ico-danger-zone">
        <h2>Danger Zone</h2>
        <p><strong>Warning:</strong> This action will delete all converted WebP/AVIF image files and clear all conversion logs from the database. This cannot be undone.</p>
        <button id="ico-clear-converted-images" class="button button-danger">Clear All Converted Images & Logs</button>
        <p id="ico-clear-status-message" class="notice notice-info" style="display:none;"></p>
    </div>
</div>