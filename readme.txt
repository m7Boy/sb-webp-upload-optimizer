=== SB WebP Upload Optimizer ===
Contributors: softwarebrewery
Tags: images, webp, performance, upload
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Author: Software Brewery
Author URI: https://softwarebrewery.net

Converts JPEG, PNG, and HEIC uploads to WebP and limits oversized originals. Developed by Software Brewery.

== Description ==

SB WebP Upload Optimizer runs when WordPress creates attachment metadata after an upload.

* JPEG, PNG, and HEIC/HEIF images are saved as WebP. GIF and SVG files are left unchanged.
* The full-size image is scaled so its longest side stays within a limit (2560 pixels by default).
* Intermediate sizes registered by the theme or by WordPress are written as WebP too.
* In the block editor on WordPress 7.1, the browser is told to encode those sizes as WebP as well.
* An optional tool converts images that are already in the Media Library, one file at a time.
* The original upload is kept unless you choose to delete it. Keeping it lets WordPress regenerate sizes later.

The server needs GD or Imagick with WebP support. HEIC files can be read on the server only when Imagick supports them. The block editor can convert HEIC in the browser before the file reaches PHP.

Developed by [Software Brewery](https://softwarebrewery.net).

== Installation ==

1. Copy the `sb-webp-upload-optimizer` folder into `wp-content/plugins/`.
2. Activate **SB WebP Upload Optimizer** from Plugins.
3. Open Settings → SB WebP Upload Optimizer to set quality, the maximum edge, and which formats to convert.

== Frequently Asked Questions ==

= Will this change images that are already uploaded? =

Only if you run **Convert existing images** on the settings screen. New uploads are converted automatically.

= Why is the original JPEG still on disk? =

WordPress keeps that file so image sizes can be rebuilt from the source. Enable **Delete the source file** only when you do not need that copy.

== Changelog ==

= 1.0.1 =
* Indexed-color PNG files no longer stop WebP conversion on servers that use GD.

= 1.0.0 =
* First release.
