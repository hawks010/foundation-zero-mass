=== Foundation: Zero Mass ===
Contributors: inkfire
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 8.0.0
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
* React-powered admin dashboard

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate **Foundation: Zero Mass** in WordPress.
3. Open **Foundation > Zero Mass** to configure optimization and queue behavior.

== Changelog ==

= 8.0.0 =
* Added queue-aware cron processing for backlog optimization.
* Added React/Tailwind admin settings shell.
* Improved compression safety by comparing work-file output before replacing originals.
* Improved alt text generation to avoid filler phrasing.
* Added GitHub updater bootstrap for Foundation release delivery.
