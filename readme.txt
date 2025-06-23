=== Image Converter & Optimizer ===
Contributors: ionutbaldazar
Donate Link: https://woomag.ro
Tags: webp, avif, image optimization, performance
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0+
License URI: http://www.gnu.org/licenses/gpl-2.0.txt

A comprehensive plugin to convert images to WebP and AVIF, optimize delivery with .htaccess/Nginx rules, and provide extensive management tools.

== Description ==

**Image Converter & Optimizer** is a powerful WordPress plugin designed to automatically convert and serve your website's images in modern, highly optimized WebP and AVIF formats. By leveraging these next-generation image formats, your site will load faster, improve Core Web Vitals, and enhance overall user experience, leading to better SEO rankings and reduced bandwidth consumption.

**Main Features:**

* **Automatic .htaccess / Nginx Integration:** The plugin seamlessly integrates rewrite rules into your `.htaccess` file (Apache) or provides Nginx configurations to automatically serve WebP/AVIF versions when browsers support them.
* **Smart Serving Priority:**
    * AVIF images are served first to browsers that support them (highest priority).
    * WebP images are served as a fallback to browsers that support WebP but not AVIF.
    * Original images (JPG/PNG) are served if neither modern format is supported by the user's browser, ensuring universal compatibility.
* **Intuitive Admin Interface (under Tools → Image Converter):**
    * **Conversion Statistics Dashboard:** Get a clear overview of your image optimization progress, including total images, WebP converted, AVIF converted, and unconverted counts.
    * **Bulk Conversion Tool:** Easily process your entire media library to convert existing images with real-time progress tracking. Includes a "Convert All" and "Pause/Stop" button.
    * **Quality Control Settings:** Configure the compression quality (1-100) separately for WebP and AVIF formats to balance file size and visual fidelity.
    * **Manual Single Image Conversion:** Convert individual images directly from the dashboard table.
* **Performance & Optimization:**
    * **Background Processing (WP-Cron):** Image conversions run efficiently in the background using WordPress cron jobs, preventing timeouts and server overload during bulk operations.
    * **All Image Size Variants:** Ensures every size of your image (thumbnails, medium, large, etc., not just originals) is converted for complete optimization.
    * **Built-in Lazy Loading:** Automatically applies lazy loading for converted images (requires theme compatibility).
* **Smart Features:**
    * **Conditional Conversion:** Skip conversion if the WebP/AVIF output would be larger than the original image, or if the file size savings are below a configurable minimum percentage.
* **Technical Enhancements:**
    * **Server Compatibility Check:** Detects and warns about missing PHP extensions (GD, ImageMagick) or `.htaccess` writability.
    * **Clear Converted Data:** A "Danger Zone" option in settings to clear all converted files and logs, effectively reverting the optimization (use with caution!).
    * **WP-CLI Integration:** Command-line interface commands for developers to manage conversions and status.

**How It Works:**

1.  **Installation & Activation:** Upon activation, the plugin creates dedicated directories (`/wp-content/uploads/webp-converted/` and `/wp-content/uploads/avif-converted/`) and automatically adds necessary rewrite rules to your `.htaccess` file (for Apache servers).
2.  **Conversion:** Images are converted on upload (future feature) or via the bulk conversion tool. Each original image variant gets a corresponding WebP and/or AVIF version stored in the dedicated directories.
3.  **Serving:** When a user's browser requests an image, the `.htaccess` (or Nginx configuration) checks the browser's `Accept` header. If AVIF is supported, it serves the AVIF version. If not, it checks for WebP. If neither is supported, the original image is served, ensuring no broken images for any visitor.

**Requirements:**

* **WordPress:** 5.8 or higher.
* **PHP:** 7.4 or higher.
* **WebP Conversion:** GD library with WebP support (standard in most PHP installations).
* **AVIF Conversion:** ImageMagick PHP extension (may need to be installed separately, requires ImageMagick 7.0.10-53 or higher).
* **Writable .htaccess:** For automatic rule management on Apache. For Nginx, manual rule insertion is required.
* **Writable Uploads Directory:** For storing converted images.

Improve your site's speed, user experience, and SEO effortlessly with Image Converter & Optimizer!

== Installation ==

1.  **Upload:** Download the plugin ZIP file. Go to your WordPress admin area, navigate to `Plugins > Add New`, and click the "Upload Plugin" button. Select the downloaded ZIP file and click "Install Now".
2.  **Activate:** After installation, click "Activate Plugin".
3.  **Configure:**
    * Go to `Tools > Image Converter`.
    * Review the "Dashboard" for conversion status and server compatibility.
    * Go to "Settings" to adjust WebP/AVIF quality and conditional conversion parameters.
    * Use "Bulk Conversion" to optimize your existing media library.

== Frequently Asked Questions ==

= What are WebP and AVIF? =
WebP and AVIF are modern image formats that offer superior compression and quality compared to traditional JPEG and PNG formats. They result in significantly smaller file sizes, leading to faster website loading times, better user experience, and improved SEO.

= Why should I use this plugin? =
This plugin automates the complex process of converting images to WebP/AVIF and serving them efficiently. It ensures your site benefits from faster loading times and better performance metrics without manual effort, while maintaining compatibility for all browsers.

= What are the server requirements for this plugin? =
You need WordPress 5.8+ and PHP 7.4+. For WebP conversion, the GD library with WebP support is standard. For AVIF, the ImageMagick PHP extension (and a recent ImageMagick library) is required. A writable `.htaccess` file is needed for automatic rule integration on Apache.

= How does the bulk conversion work? =
The bulk conversion processes your existing media library images in batches using WordPress cron jobs. This means the conversion runs in the background without causing timeouts, even for large libraries. You can monitor progress on the dashboard.

= What if WebP/AVIF images are larger than the originals? =
The plugin includes a "Conditional Conversion" feature. If enabled in settings, it will compare the converted file size to the original. If the converted file is larger, or if the size savings are below a set percentage, the plugin will discard the converted file and continue serving the original, ensuring no negative impact on file size.

= Does it support CDN integration or lazy loading? =
The plugin is designed to work with most CDN services by leveraging standard WordPress image URL handling. It also includes built-in lazy loading for converted images, which can further boost performance.

= How can I revert converted images? =
The plugin provides a "Clear All Converted Images & Logs" option in the "Settings" under the "Danger Zone". This action will delete all generated WebP/AVIF files and clear conversion logs from the database, effectively reverting your site to serving original images. Use with caution!

== Screenshots ==

1.  Dashboard Overview with Stats.
2.  Bulk Conversion Progress.
3.  Settings Page for Quality and Conditional Conversion.
4.  Image Compatibility Check.
5.  Nginx Rules for Manual Setup.

== Changelog ==

= 1.0.0 =
* Initial Release.
* Automatic WebP/AVIF conversion for all image sizes.
* Smart `.htaccess` / Nginx serving rules.
* Admin Dashboard with conversion statistics.
* Bulk conversion with progress tracking.
* Quality settings for WebP and AVIF.
* Manual single image conversion.
* Conditional conversion: skip if output is larger or savings are too low.
* WP-CLI commands for status, start/stop bulk, and single conversion.
* Clear all converted images and logs functionality.
* Server compatibility checks.
