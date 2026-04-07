=== Foundation: Zero Mass ===
Contributors: inkfire
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 8.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Advanced media optimization for Foundation sites with safer background processing, modern image delivery, and accessibility-focused alt text support.

== Description ==

Foundation: Zero Mass helps WordPress sites optimize media without turning the upload flow into a bottleneck.

Features include:

* background image optimization queue
* safer original file replacement logic
* WebP and AVIF generation when beneficial
* optional original backups and restore support
* concise alt text generation
* compression profiles and quality guard
* large file watchdog for oversized uploads
* protected brand asset and exclusion rules
* LCP image hints for likely featured hero images
* Elementor/Divi-aware audit signals
* WP-CLI commands for reporting, queueing, optimization, and restore
* React-powered admin dashboard

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate **Foundation: Zero Mass** in WordPress.
3. Open **Foundation > Zero Mass** to configure optimization and queue behavior.

== Changelog ==

= 8.1.1 =
* Added Large File Watchdog to flag and auto-queue uploads above the configurable oversized file threshold.
* Added oversized pending counts and dynamic threshold labels to the admin audit.

= 8.1.0 =
* Added compression profiles for balanced, performance, brand-quality, and low-bandwidth workflows.
* Added quality guard to skip low-value rewrites unless a minimum saving threshold is met.
* Added protected brand asset and exclusion rules for filenames, attachment IDs, and MIME types.
* Added gold-standard audit report for missing alt text, large files, missing modern formats, LCP candidates, and builder usage.
* Added LCP preload/fetch-priority hints for likely featured images.
* Added WP-CLI commands: report, queue, process-queue, optimize, and restore.

= 8.0.0 =
* Added queue-aware cron processing for backlog optimization.
* Added React/Tailwind admin settings shell.
* Improved compression safety by comparing work-file output before replacing originals.
* Improved alt text generation to avoid filler phrasing.
* Added GitHub updater bootstrap for Foundation release delivery.
