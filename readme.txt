=== FirePunch | Instant AVIF & WebP images for ACF developers ===
Contributors: matteobruschetti
Tags: acf, webp, avif, images, performance
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 2.2.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatic and asynchronous AVIF & WebP image optimization for custom themes and ACF fields using native HTML5 picture tags.

== Description ==

**FirePunch** is a lightweight, 100% free plugin designed to optimize image management in WordPress by automatically converting them into next-generation **AVIF** and **WebP** formats.

This tool is built specifically for web developers working with custom themes and image fields (such as **ACF - Advanced Custom Fields** or **Gutenberg blocks**).

### How does it work?

https://www.youtube.com/watch?v=oEg8D7W3K1U

1. **Automatic conversion:** The plugin intercepts JPG and PNG media uploads and generates variants asynchronously in the background, ensuring zero impact on server performance during your daily workflow.
2. **Predictable URL Structure:** Converted AVIF and WebP variants are saved directly alongside the original media, making them instantly accessible by simply appending `.avif` or `.webp` to the original image URL. For example: `https://example.com/wp-content/uploads/immagine.jpg.webp` and `https://example.com/wp-content/uploads/immagine.jpg.avif`
3. **Effortless Theme Integration:**  Simply call them inside your custom theme using the standard WordPress function `wp_get_attachment_image()` or the plugin's dedicated helper function `wp_avif_img()`.
4. **Smart HTML5 Fallback:** The plugin automatically rewrites the frontend output into a robust `<picture>` tag. The browser will dynamically load the best possible format based on user compatibility, seamlessly prioritizing **AVIF**, falling back to **WebP**, and using the original **JPG** or **PNG** as the ultimate safety fallback.

### Key Features:
* **Asynchronous Background Processing:** Leverages Action Scheduler (or WP-Cron with a safety offset) to process images sequentially without locking up the server or triggering execution timeouts.
* **Smart Hybrid Engine:** Tries ImageMagick first for premium encoding and seamlessly falls back to GD when necessary, dynamically boosting memory limits to prevent crashes on large files.
* **Zero Disk I/O Abuse:** Caches the status of all available file variants directly inside the attachment metadata in the database. No endless file system lookups on every single page load.
* **Modern HTML5 Rewriting:** Utilizes the native `WP_HTML_Tag_Processor` (introduced in WP 6.2) to safely rewrite `<img>` tags into multi-source `<picture>` structures.
* **Automatic Cleanup:** When an image is deleted from the media library, all associated AVIF and WebP variants are permanently removed, featuring path traversal protection.
* **Zero ACF Dependencies:** Built 100% WordPress-native with absolutely no reliance on Advanced Custom Fields, making it perfectly compatible with any custom theme workflow.

== Installation ==

1. Upload the plugin folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.

== Developer Guide (Custom Themes & ACF) ==

This plugin offers total flexibility for custom theme development. You can display optimized images using three different approaches:

= 1. Native Automatic Integration =
If your theme already uses standard WordPress functions, you do not need to change a single line of code. The plugin automatically filters the output and injects the AVIF/WebP sources:

    <?php echo wp_get_attachment_image( $attachment_id, 'large' ); ?>

= 2. Using the Helper Function wp_avif_img() =
If you need granular control—such as stripping away default WordPress layout classes or easily overriding loading behavior—use the plugin's global helper function:

    <?php echo wp_avif_img( $attachment_id, $size, $attr ); ?>

* **$attachment_id** (int): The attachment ID of the image (Required).
* **$size** (string|array): The requested image size ('thumbnail', 'medium', 'large', 'full'). Default: 'full'.
* **$attr** (string|array): Simplifies attributes. You can pass 'lazy' or 'eager' directly as a string, or supply a custom HTML attribute array.

Practical Examples:

    // Standard output in 'large' size with native lazy loading
    echo wp_avif_img( $image_id, 'large', 'lazy' );

    // Advanced output with custom CSS classes and specific Alt text
    echo wp_avif_img( $image_id, 'full', array(
        'class' => 'hero-banner-image',
        'alt'   => 'Optimized hero cover background'
    ) );

= 3. Advanced Custom Fields (ACF) Integration =
When creating an Image field in ACF, leave the **Return Format** to **Array**. This approach allows you to dynamically extract the attachment ID for the optimization engine while safely preserving a native standard HTML fallback. In your template file, write:

`
<figure>
   <?php if($acf_img_field): ?>
      <?php if ( function_exists('wp_avif_img') ) {
         $img_id = $acf_img_field['ID'];
         echo wp_avif_img($img_id, 'large', 'lazy');
      } else { ?>
         <img loading="lazy" src="<?php echo esc_url($acf_img_field['url']); ?>" alt="<?php echo esc_attr( $acf_img_field['alt'] ); ?>" />
      <?php } ?>
   <?php endif; ?>
</figure>
`


== Frequently Asked Questions ==

= Is the plugin retroactive? Does it optimize existing media library images? =
Yes. To avoid crashing your server's CPU upon activation, the plugin does not run a massive bulk-generation script. Instead, it optimizes older images dynamically "on-the-fly" the first time a frontend user visits a page requesting them. Once generated, the variant metadata is cached in the DB, eliminating future file system checks.

= How do I verify if images are being served in AVIF or WebP? =
Visit your website's frontend, right-click on an image, and select "Inspect" from your browser developer tools. If you see your original <img> tag wrapped inside a modern <picture> block containing <source type="image/avif"> and <source type="image/webp"> tags, the plugin is working perfectly.

= What happens if my hosting server doesn't support AVIF? =
No crashes or errors will occur. The plugin performs environment capability checks. If your server environment only supports WebP, it will generate WebP variants exclusively. If the server libraries support neither next-gen format, it will perform a clean fallback to the original JPEG/PNG files.

= Does it automatically process images added via the Gutenberg Block Editor? =
Currently, this plugin focuses entirely on custom theme architecture, theme templates, and custom meta fields (like ACF). It does not scan or overwrite the textual content inside "the_content" block data, ensuring absolute structural speed and lightweight database performance.

= Will I lose my converted image files if I deactivate the plugin? =
No. Deactivating the plugin simply removes the frontend filters, returning your site to rendering the original JPEG/PNG files. All generated .webp and .avif files remain completely secure within your "/uploads/" directory to avoid accidental data loss, ready to be utilized again whenever you re-activate the plugin.

== Screenshots ==

1. Frontend HTML inspection showing the original img tag safely wrapped inside a next-gen picture tag with AVIF and WebP sources.
2. Mirror file variants successfully generated in the background inside the standard WordPress uploads folder.

== Changelog ==

= 2.2.2 =
* readme.txt fix

= 2.2.1 =
* readme.txt fix

= 2.2 =
* readme.txt YT video

= 2.1 =
* Added admin page to show current settings, plugin status and some debug information to diagnose issues.
* Fixed blurry header banner on desktop screens.
* Updated readme.txt documentation.

= 1.2 =
* Replaced the old regex parser with the native WP_HTML_Tag_Processor API for robust and safe HTML rewriting.
* Resolved a potential PHP Warning in the file cleanup method caused by manually deleted upload subdirectories.
* Optimized readme markup syntax for 100% compliance with the WordPress.org validator.

= 1.1 =
* Introduced the hybrid conversion workflow (Imagick + GD) featuring dynamic memory allocation adjustments for heavy PNG files.

= 1.0 =
* Initial plugin release.

== Upgrade Notice ==

= 1.2 =
This update introduces native core WP_HTML_Tag_Processor support, making HTML rewriting exponentially more stable and compatible with third-party plugins. Highly recommended update.