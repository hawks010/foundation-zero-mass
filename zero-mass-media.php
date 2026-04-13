<?php
/**
 * Plugin Name:       Foundation: Zero Mass
 * Plugin URI:        https://inkfire.co.uk
 * Description:       Advanced image optimization with LQIP, smart WebP/AVIF conversion, and accessibility features.
 * Version:           8.1.3
 * Author:            Sonny x Inkfire
 * Author URI:        https://inkfire.co.uk
 * License:           GPLv2 or later
 * Text Domain:       zero-mass-media
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Update URI:        https://github.com/hawks010/foundation-zero-mass
 */

defined('ABSPATH') || exit;

// Constants
define('ZMM_VERSION', '8.1.3');
define('ZMM_FILE', __FILE__);
define('ZMM_PATH', plugin_dir_path(ZMM_FILE));
define('ZMM_URL', plugin_dir_url(ZMM_FILE));
define('ZMM_SETTINGS_SLUG', 'foundation-zero-mass');
define('ZMM_QUEUE_HOOK', 'zmm_process_queue');
define('ZMM_VERIFY_HOOK', 'zmm_daily_verification');
define('ZMM_BACKUP_HOOK', 'zmm_backup_cleanup');

require_once ZMM_PATH . 'includes/class-github-updater.php';

final class Zero_Mass_Media {
    
    private static $instance;
    private $options;
    private $compression_attempts = [];
    private $admin_page_hook = '';

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->options = $this->get_options();
        $this->add_hooks();
    }

    private function get_default_options() {
        return [
            'auto_process_on_upload' => '1',
            'process_backlog_via_cron' => '1',
            'use_picture_tags' => '1',
            'keep_original_backup' => '1',
            'auto_generate_alt' => '1',
            'overall_quality' => 'recommended',
            'compression_profile' => 'balanced',
            'max_width' => 1920,
            'max_height' => 1920,
            'quality_guard_min_saving' => 3,
            'oversized_file_threshold_mb' => 1,
            'auto_queue_oversized_uploads' => '1',
            'enable_lqip' => '1',
            'enable_lcp_preload' => '1',
            'enable_builder_audit' => '1',
            'protect_brand_assets' => '1',
            'exclude_attachment_ids' => '',
            'exclude_filename_patterns' => 'logo,brand,icon,qr,badge,signature,ico',
            'exclude_mime_types' => 'image/svg+xml',
            'queue_batch_size' => 3,
            'queue_schedule' => 'zmm_every_fifteen_minutes',
            'cron_schedule' => 'daily',
            'backup_cleanup_days' => 30,
        ];
    }

    private function get_options() {
        $saved = get_option('zmm_settings', []);
        $saved = is_array($saved) ? $saved : [];

        return wp_parse_args($saved, $this->get_default_options());
    }

    public function add_hooks() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        // This action is added to clean up the duplicate submenu link created by add_menu_page.
        add_action('admin_head', [$this, 'remove_duplicate_submenu_link']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_init', [$this, 'check_dependencies']);
        add_action('admin_notices', [$this, 'check_format_support_and_warn']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_filter('cron_schedules', [$this, 'register_cron_schedules']);
        add_action('add_attachment', [$this, 'handle_new_attachment']);
        add_filter('manage_media_columns', [$this, 'add_media_library_columns']);
        add_action('manage_media_custom_column', [$this, 'render_media_library_columns'], 10, 2);
        add_action('attachment_submitbox_misc_actions', [$this, 'add_actions_to_edit_media_screen']);
        add_action('wp_ajax_zmm_process_single_image', [$this, 'ajax_process_single_image']);
        add_action('wp_ajax_zmm_bulk_process', [$this, 'ajax_bulk_process']);
        add_action('wp_ajax_zmm_bulk_generate_alt', [$this, 'ajax_bulk_generate_alt']);
        add_action('wp_ajax_zmm_check_status', [$this, 'ajax_check_status']);
        add_action('wp_ajax_zmm_manual_backup_cleanup', [$this, 'ajax_manual_backup_cleanup']);
        add_action('wp_ajax_zmm_reset_all_status', [$this, 'ajax_reset_all_status']);
        add_action('wp_ajax_zmm_save_settings', [$this, 'ajax_save_settings']);
        add_action('wp_ajax_zmm_get_dashboard_data', [$this, 'ajax_get_dashboard_data']);
        add_action('wp_ajax_zmm_queue_all_unprocessed', [$this, 'ajax_queue_all_unprocessed']);
        add_action('init', [$this, 'setup_cron_schedule']);
        add_action(ZMM_QUEUE_HOOK, [$this, 'run_processing_queue']);
        add_action(ZMM_VERIFY_HOOK, [$this, 'run_daily_verification']);
        add_action(ZMM_BACKUP_HOOK, [$this, 'run_backup_cleanup']);
        add_filter('the_content', [$this, 'replace_images_with_picture_tags'], 20);
        add_action('wp_footer', [$this, 'add_lazy_load_script']);
        add_action('wp_head', [$this, 'print_lcp_preload_hint'], 1);
        add_filter('wp_get_attachment_image_attributes', [$this, 'adjust_lcp_image_attributes'], 10, 3);
        add_filter('attachment_fields_to_edit', [$this, 'add_decorative_field'], 10, 2);
        add_filter('attachment_fields_to_save', [$this, 'save_decorative_field'], 10, 2);
        add_filter('attachment_fields_to_edit', [$this, 'add_actions_to_grid_view_modal'], 11, 2);
        if (defined('WP_CLI') && WP_CLI) {
            add_action('cli_init', [$this, 'register_wp_cli_commands']);
            if (class_exists('WP_CLI')) {
                $this->register_wp_cli_commands();
            }
        }
    }

    public static function activate() {
        $instance = self::get_instance();
        $instance->options = $instance->get_options();
        $instance->setup_cron_schedule();
    }

    public static function deactivate() {
        wp_clear_scheduled_hook(ZMM_QUEUE_HOOK);
        wp_clear_scheduled_hook(ZMM_VERIFY_HOOK);
        wp_clear_scheduled_hook(ZMM_BACKUP_HOOK);
    }

    public function add_admin_menu() {
        global $admin_page_hooks;
    
        // This is the standardized shared parent slug for ALL Foundation plugins.
        $parent_slug = 'foundation-by-inkfire';
    
        // Check if the parent menu has already been registered by another Foundation plugin.
        if (empty($admin_page_hooks[$parent_slug])) {
            add_menu_page(
                __('Foundation by Inkfire', 'zero-mass-media'), // Page Title
                __('Foundation', 'zero-mass-media'),           // Menu Title
                'manage_options',                              // Capability
                $parent_slug,                                  // The shared parent slug
                [$this, 'render_main_page'],                   // Callback for the main welcome page
                'dashicons-hammer',                            // Icon
                12                                             // Position
            );
        }
    
        // Add this plugin's specific page ("Zero Mass") as a submenu.
        $this->admin_page_hook = add_submenu_page(
            $parent_slug,
            __('Foundation: Zero Mass', 'zero-mass-media'),
            __('Zero Mass', 'zero-mass-media'),
            'manage_options',
            ZMM_SETTINGS_SLUG, // This plugin's unique slug
            [$this, 'render_settings_page']
        );
    }

    /**
     * Removes the redundant "Foundation" submenu link that WordPress creates by default.
     * This leaves a clean menu with only the plugin pages.
     */
    public function remove_duplicate_submenu_link()
    {
        $parent_slug = 'foundation-by-inkfire';
        remove_submenu_page($parent_slug, $parent_slug);
    }

    /**
     * Renders the main welcome/landing page for the entire Foundation suite.
     */
    public function render_main_page()
    {
        echo '<div class="wrap"><h1>Foundation Series by Inkfire</h1><p>A suite of modular, minimal tools for clean, performant WordPress sites.</p></div>';
    }

    public function render_settings_page() {
        echo '<div class="wrap foundation-admin-wrap zmm-settings-wrap">';
        echo '<div id="foundation-admin-app"><p>' . esc_html__('Loading Foundation shell...', 'zero-mass-media') . '</p></div>';
        echo '<template id="foundation-zero-mass-workspace"><div id="zmm-admin-app"></div></template>';
        echo '<noscript><p>' . esc_html__('Foundation: Zero Mass now uses a JavaScript-powered admin app. Please enable JavaScript to manage settings.', 'zero-mass-media') . '</p></noscript>';
        echo '</div>';
    }

    public function register_settings() {
        register_setting('zmm_options_group', 'zmm_settings', [$this, 'sanitize_settings']);
        
        add_settings_section('zmm_performance_settings_section', __('Performance & Optimization', 'zero-mass-media'), null, ZMM_SETTINGS_SLUG);
        add_settings_field('auto_process_on_upload', __('Automatic Processing', 'zero-mass-media'), [$this, 'render_checkbox_field'], ZMM_SETTINGS_SLUG, 'zmm_performance_settings_section', ['name' => 'auto_process_on_upload', 'label' => 'Automatically process new image uploads']);
        add_settings_field('enable_lqip', __('Lazy Load with Placeholders (LQIP)', 'zero-mass-media'), [$this, 'render_checkbox_field'], ZMM_SETTINGS_SLUG, 'zmm_performance_settings_section', ['name' => 'enable_lqip', 'label' => 'Enable LQIP to improve perceived speed and reduce layout shift.']);
        add_settings_field('use_picture_tags', __('Front-End Display', 'zero-mass-media'), [$this, 'render_checkbox_field'], ZMM_SETTINGS_SLUG, 'zmm_performance_settings_section', ['name' => 'use_picture_tags', 'label' => 'Serve images using <picture> tags for modern formats']);
        
        add_settings_section('zmm_accessibility_section', __('Accessibility', 'zero-mass-media'), null, ZMM_SETTINGS_SLUG);
        add_settings_field('auto_generate_alt', __('Automatic Alt Text', 'zero-mass-media'), [$this, 'render_checkbox_field'], ZMM_SETTINGS_SLUG, 'zmm_accessibility_section', ['name' => 'auto_generate_alt', 'label' => 'Automatically generate contextual alt text for new uploads from filename and post title.']);

        add_settings_section('zmm_backup_settings_section', __('Backup & Maintenance', 'zero-mass-media'), null, ZMM_SETTINGS_SLUG);
        add_settings_field('keep_original_backup', __('Backup Original Images', 'zero-mass-media'), [$this, 'render_checkbox_field'], ZMM_SETTINGS_SLUG, 'zmm_backup_settings_section', ['name' => 'keep_original_backup', 'label' => 'Keep a backup for the "Restore Original" feature.']);
        add_settings_field('backup_cleanup_days', __('Backup Cleanup', 'zero-mass-media'), [$this, 'render_number_field'], ZMM_SETTINGS_SLUG, 'zmm_backup_settings_section', ['name' => 'backup_cleanup_days', 'description' => 'Automatically delete backup files older than this many days (0 to disable).']);
        add_settings_field('cron_schedule', __('Maintenance Schedule', 'zero-mass-media'), [$this, 'render_cron_schedule_dropdown'], ZMM_SETTINGS_SLUG, 'zmm_backup_settings_section');

        add_settings_section('zmm_quality_settings_section', __('Quality & Sizing', 'zero-mass-media'), null, ZMM_SETTINGS_SLUG);
        add_settings_field('overall_quality', __('Overall Quality', 'zero-mass-media'), [$this, 'render_quality_dropdown'], ZMM_SETTINGS_SLUG, 'zmm_quality_settings_section');
        add_settings_field('max_dimensions', __('Max Image Dimensions', 'zero-mass-media'), [$this, 'render_dimension_fields'], ZMM_SETTINGS_SLUG, 'zmm_quality_settings_section');
    }

    public function render_checkbox_field($args) {
        $name = $args['name'];
        $label = $args['label'] ?? '';
        $description = $args['description'] ?? '';
        $checked = $this->options[$name] ?? '0';
        ?>
        <label for="<?php echo esc_attr($name); ?>">
            <input type="checkbox" name="zmm_settings[<?php echo esc_attr($name); ?>]" id="<?php echo esc_attr($name); ?>" value="1" <?php checked($checked, '1'); ?>>
            <?php echo wp_kses_post($label); ?>
        </label>
        <?php if ($description): ?><p class="description"><?php echo wp_kses_post($description); ?></p><?php endif;
    }

    public function render_dimension_fields() {
        $width = $this->options['max_width'] ?? 1920;
        $height = $this->options['max_height'] ?? 1920;
        ?>
        <input type="number" name="zmm_settings[max_width]" value="<?php echo esc_attr($width); ?>" class="small-text"> x
        <input type="number" name="zmm_settings[max_height]" value="<?php echo esc_attr($height); ?>" class="small-text"> px
        <p class="description">Images exceeding these dimensions will be resized automatically.</p>
        <?php
    }

    public function render_number_field($args) {
        $name = $args['name'];
        $description = $args['description'] ?? '';
        $value = $this->options[$name] ?? 30;
        ?>
        <input type="number" name="zmm_settings[<?php echo esc_attr($name); ?>]" value="<?php echo esc_attr($value); ?>" class="small-text" min="0">
        <?php if ($description): ?><p class="description"><?php echo esc_html($description); ?></p><?php endif;
    }

    public function render_quality_dropdown() {
        $current_quality = $this->options['overall_quality'] ?? 'recommended';
        ?>
        <select name="zmm_settings[overall_quality]" id="zmm_overall_quality">
            <option value="recommended" <?php selected($current_quality, 'recommended'); ?>><?php _e('Recommended (Best Balance)', 'zero-mass-media'); ?></option>
            <option value="high" <?php selected($current_quality, 'high'); ?>><?php _e('High (Better Visuals, Larger Files)', 'zero-mass-media'); ?></option>
            <option value="highest" <?php selected($current_quality, 'highest'); ?>><?php _e('Highest (Maximum Quality, Largest Files)', 'zero-mass-media'); ?></option>
        </select>
        <p class="description"><?php _e('Select a quality level. The plugin will intelligently apply the best compression settings for each format.', 'zero-mass-media'); ?></p>
        <?php
    }

    public function render_cron_schedule_dropdown() {
        $current_schedule = $this->options['cron_schedule'] ?? 'daily';
        ?>
        <select name="zmm_settings[cron_schedule]" id="zmm_cron_schedule">
            <option value="hourly" <?php selected($current_schedule, 'hourly'); ?>><?php _e('Hourly', 'zero-mass-media'); ?></option>
            <option value="daily" <?php selected($current_schedule, 'daily'); ?>><?php _e('Daily', 'zero-mass-media'); ?></option>
            <option value="weekly" <?php selected($current_schedule, 'weekly'); ?>><?php _e('Weekly', 'zero-mass-media'); ?></option>
        </select>
        <p class="description"><?php _e('How often to run background maintenance tasks like file verification and backup cleanup.', 'zero-mass-media'); ?></p>
        <?php
    }

    public function sanitize_settings($input) {
        $input = is_array($input) ? $input : [];
        $defaults = $this->get_default_options();

        $checkbox_fields = [
            'auto_process_on_upload',
            'process_backlog_via_cron',
            'use_picture_tags',
            'keep_original_backup',
            'auto_generate_alt',
            'enable_lqip',
            'enable_lcp_preload',
            'enable_builder_audit',
            'protect_brand_assets',
            'auto_queue_oversized_uploads',
        ];

        $new_input = $defaults;

        foreach ($checkbox_fields as $field) {
            $new_input[$field] = !empty($input[$field]) ? '1' : '0';
        }

        $new_input['overall_quality'] = in_array(
            $input['overall_quality'] ?? '',
            ['recommended', 'high', 'highest'],
            true
        ) ? $input['overall_quality'] : $defaults['overall_quality'];

        $new_input['compression_profile'] = in_array(
            $input['compression_profile'] ?? '',
            ['balanced', 'maximum_performance', 'brand_quality', 'accessibility_low_bandwidth'],
            true
        ) ? $input['compression_profile'] : $defaults['compression_profile'];

        $new_input['cron_schedule'] = in_array(
            $input['cron_schedule'] ?? '',
            ['hourly', 'daily', 'weekly'],
            true
        ) ? $input['cron_schedule'] : $defaults['cron_schedule'];

        $new_input['queue_schedule'] = in_array(
            $input['queue_schedule'] ?? '',
            ['zmm_every_fifteen_minutes', 'zmm_every_thirty_minutes', 'hourly', 'twicedaily', 'daily'],
            true
        ) ? $input['queue_schedule'] : $defaults['queue_schedule'];

        $new_input['max_width'] = max(320, absint($input['max_width'] ?? $defaults['max_width']));
        $new_input['max_height'] = max(320, absint($input['max_height'] ?? $defaults['max_height']));
        $new_input['quality_guard_min_saving'] = max(0, min(50, absint($input['quality_guard_min_saving'] ?? $defaults['quality_guard_min_saving'])));
        $new_input['oversized_file_threshold_mb'] = max(1, min(20, absint($input['oversized_file_threshold_mb'] ?? $defaults['oversized_file_threshold_mb'])));
        $new_input['backup_cleanup_days'] = absint($input['backup_cleanup_days'] ?? $defaults['backup_cleanup_days']);
        $new_input['queue_batch_size'] = max(1, min(25, absint($input['queue_batch_size'] ?? $defaults['queue_batch_size'])));
        $new_input['exclude_attachment_ids'] = sanitize_text_field(wp_unslash($input['exclude_attachment_ids'] ?? $defaults['exclude_attachment_ids']));
        $new_input['exclude_filename_patterns'] = sanitize_text_field(wp_unslash($input['exclude_filename_patterns'] ?? $defaults['exclude_filename_patterns']));
        $new_input['exclude_mime_types'] = sanitize_text_field(wp_unslash($input['exclude_mime_types'] ?? $defaults['exclude_mime_types']));

        return $new_input;
    }

    public function register_cron_schedules($schedules) {
        $schedules['zmm_every_fifteen_minutes'] = [
            'interval' => 15 * MINUTE_IN_SECONDS,
            'display'  => __('Every 15 minutes', 'zero-mass-media'),
        ];
        $schedules['zmm_every_thirty_minutes'] = [
            'interval' => 30 * MINUTE_IN_SECONDS,
            'display'  => __('Every 30 minutes', 'zero-mass-media'),
        ];

        return $schedules;
    }

    private function get_image_attachment_query_args($extra_args = []) {
        return array_merge([
            'post_type'              => 'attachment',
            'post_status'            => 'inherit',
            'post_mime_type'         => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
            'fields'                 => 'ids',
            'cache_results'          => false,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ], $extra_args);
    }

    private function count_attachment_query($extra_args = []) {
        $query = new WP_Query($this->get_image_attachment_query_args(array_merge([
            'posts_per_page' => 1,
        ], $extra_args)));

        return (int) $query->found_posts;
    }

    private function parse_csv_setting($value) {
        if (!is_string($value) || '' === trim($value)) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }

    private function get_excluded_attachment_ids() {
        return array_values(array_filter(array_map('absint', $this->parse_csv_setting($this->options['exclude_attachment_ids'] ?? ''))));
    }

    private function get_excluded_mime_types() {
        return array_map('strtolower', $this->parse_csv_setting($this->options['exclude_mime_types'] ?? ''));
    }

    private function get_excluded_filename_patterns() {
        return array_map('strtolower', $this->parse_csv_setting($this->options['exclude_filename_patterns'] ?? ''));
    }

    private function get_oversized_threshold_bytes() {
        return max(1, absint($this->options['oversized_file_threshold_mb'] ?? 1)) * MB_IN_BYTES;
    }

    private function get_attachment_file_size($attachment_id) {
        $filepath = get_attached_file($attachment_id);
        return ($filepath && file_exists($filepath)) ? (int) filesize($filepath) : 0;
    }

    private function is_attachment_over_size_threshold($attachment_id) {
        return $this->get_attachment_file_size($attachment_id) > $this->get_oversized_threshold_bytes();
    }

    private function is_likely_brand_asset($attachment_id) {
        $filepath = get_attached_file($attachment_id);
        $name = strtolower($filepath ? wp_basename($filepath) : get_the_title($attachment_id));

        foreach (['logo', 'brand', 'icon', 'badge', 'qr', 'signature', 'ico'] as $needle) {
            if (false !== strpos($name, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function is_attachment_excluded($attachment_id) {
        $attachment_id = absint($attachment_id);
        if (!$attachment_id) {
            return false;
        }

        if (in_array($attachment_id, $this->get_excluded_attachment_ids(), true)) {
            return __('Excluded by attachment ID.', 'zero-mass-media');
        }

        $mime_type = strtolower((string) get_post_mime_type($attachment_id));
        if ($mime_type && in_array($mime_type, $this->get_excluded_mime_types(), true)) {
            return __('Excluded by MIME type.', 'zero-mass-media');
        }

        $filepath = get_attached_file($attachment_id);
        $filename = strtolower($filepath ? wp_basename($filepath) : get_the_title($attachment_id));
        foreach ($this->get_excluded_filename_patterns() as $pattern) {
            if ('' !== $pattern && false !== strpos($filename, $pattern)) {
                return sprintf(__('Excluded by filename pattern: %s', 'zero-mass-media'), $pattern);
            }
        }

        if (!empty($this->options['protect_brand_assets']) && $this->is_likely_brand_asset($attachment_id)) {
            return __('Protected brand/logo asset.', 'zero-mass-media');
        }

        return false;
    }

    private function get_modern_format_paths($attachment_id) {
        $filepath = get_attached_file($attachment_id);
        if (!$filepath) {
            return ['webp' => '', 'avif' => ''];
        }

        $base_path = pathinfo($filepath, PATHINFO_DIRNAME) . '/' . pathinfo($filepath, PATHINFO_FILENAME);
        return [
            'webp' => $base_path . '.webp',
            'avif' => $base_path . '.avif',
        ];
    }

    private function get_featured_image_ids() {
        global $wpdb;

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value REGEXP '^[0-9]+$'",
                '_thumbnail_id'
            )
        );

        return array_map('absint', $ids);
    }

    private function get_builder_usage_summary() {
        if (empty($this->options['enable_builder_audit'])) {
            return ['elementor_documents' => 0, 'divi_documents' => 0];
        }

        global $wpdb;

        return [
            'elementor_documents' => (int) $wpdb->get_var("SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_data' AND meta_value <> ''"),
            'divi_documents'      => (int) $wpdb->get_var("SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = '_et_pb_use_builder' AND meta_value = 'on'"),
        ];
    }

    public function get_gold_standard_report($limit = 8) {
        $query = new WP_Query($this->get_image_attachment_query_args([
            'posts_per_page' => -1,
        ]));

        $featured_ids = $this->get_featured_image_ids();
        $oversized_threshold = $this->get_oversized_threshold_bytes();
        $report = [
            'total_images' => 0,
            'missing_alt' => 0,
            'oversized_files' => 0,
            'oversized_pending' => 0,
            'oversized_threshold' => $oversized_threshold,
            'oversized_dimensions' => 0,
            'missing_modern_formats' => 0,
            'excluded_assets' => 0,
            'lcp_candidates' => count($featured_ids),
            'builder_usage' => $this->get_builder_usage_summary(),
            'top_offenders' => [],
        ];

        foreach ($query->posts as $attachment_id) {
            $report['total_images']++;

            $filepath = get_attached_file($attachment_id);
            $filesize = ($filepath && file_exists($filepath)) ? filesize($filepath) : 0;
            $dimensions = $filepath ? @getimagesize($filepath) : false;
            $alt_text = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
            $format_paths = $this->get_modern_format_paths($attachment_id);
            $is_excluded = $this->is_attachment_excluded($attachment_id);
            $issues = [];

            if ($is_excluded) {
                $report['excluded_assets']++;
                $issues[] = $is_excluded;
            }

            if ('' === trim((string) $alt_text) && !get_post_meta($attachment_id, '_zmm_is_decorative', true)) {
                $report['missing_alt']++;
                $issues[] = __('Missing alt text.', 'zero-mass-media');
            }

            if ($filesize > $oversized_threshold) {
                $report['oversized_files']++;
                if (!$is_excluded && !get_post_meta($attachment_id, '_zmm_processed', true)) {
                    $report['oversized_pending']++;
                }
                $issues[] = sprintf(__('File is over %sMB.', 'zero-mass-media'), absint($this->options['oversized_file_threshold_mb'] ?? 1));
            }

            if ($dimensions && ($dimensions[0] > (int) $this->options['max_width'] || $dimensions[1] > (int) $this->options['max_height'])) {
                $report['oversized_dimensions']++;
                $issues[] = __('Dimensions exceed the current profile bounds.', 'zero-mass-media');
            }

            if (!$is_excluded && !file_exists($format_paths['webp']) && !file_exists($format_paths['avif'])) {
                $report['missing_modern_formats']++;
                $issues[] = __('No WebP or AVIF alternative found.', 'zero-mass-media');
            }

            if (!empty($issues)) {
                $report['top_offenders'][] = [
                    'id' => $attachment_id,
                    'title' => get_the_title($attachment_id),
                    'filename' => $filepath ? wp_basename($filepath) : '',
                    'size' => $filesize,
                    'issues' => array_slice($issues, 0, 3),
                ];
            }
        }

        usort($report['top_offenders'], function ($a, $b) {
            return $b['size'] <=> $a['size'];
        });
        $report['top_offenders'] = array_slice($report['top_offenders'], 0, max(1, absint($limit)));

        return $report;
    }

    private function get_queue_summary() {
        return [
            'queued'      => $this->count_attachment_query([
                'meta_query' => [[
                    'key'   => '_zmm_queue_status',
                    'value' => 'queued',
                ]],
            ]),
            'processing'  => $this->count_attachment_query([
                'meta_query' => [[
                    'key'   => '_zmm_queue_status',
                    'value' => 'processing',
                ]],
            ]),
            'failed'      => $this->count_attachment_query([
                'meta_query' => [[
                    'key'   => '_zmm_queue_status',
                    'value' => 'failed',
                ]],
            ]),
            'unprocessed' => $this->count_attachment_query([
                'meta_query' => [[
                    'key'     => '_zmm_processed',
                    'compare' => 'NOT EXISTS',
                ]],
            ]),
        ];
    }

    private function get_dashboard_payload() {
        return [
            'settings' => $this->options,
            'stats'    => $this->get_library_stats(),
            'queue'    => $this->get_queue_summary(),
            'audit'    => $this->get_gold_standard_report(),
            'support'  => [
                'webp' => wp_image_editor_supports(['mime_type' => 'image/webp']),
                'avif' => wp_image_editor_supports(['mime_type' => 'image/avif']),
            ],
        ];
    }

    private function get_shell_config() {
        $payload = $this->get_dashboard_payload();
        $stats = $payload['stats'];
        $queue = $payload['queue'];
        $audit = $payload['audit'];

        return [
            'plugin' => 'zero-mass',
            'rootId' => 'foundation-admin-app',
            'eyebrow' => __('Foundation command centre', 'zero-mass-media'),
            'title' => __('Foundation: Zero Mass', 'zero-mass-media'),
            'description' => __('The image optimisation React app now runs inside the shared Foundation shell while the existing AJAX actions and zmm_settings storage remain unchanged.', 'zero-mass-media'),
            'badge' => 'v' . ZMM_VERSION,
            'themeStorageKey' => 'foundation-zero-mass-theme',
            'actions' => [
                [
                    'label' => __('Queue unprocessed images', 'zero-mass-media'),
                    'href' => admin_url('admin.php?page=' . ZMM_SETTINGS_SLUG),
                    'variant' => 'solid',
                ],
                [
                    'label' => __('GitHub backup', 'zero-mass-media'),
                    'href' => 'https://github.com/hawks010/foundation-zero-mass',
                    'target' => '_blank',
                    'variant' => 'ghost',
                ],
            ],
            'metrics' => [
                [
                    'label' => __('Library size', 'zero-mass-media'),
                    'value' => size_format((int) ($stats['current_size'] ?? 0)),
                    'meta' => __('Current optimized media footprint.', 'zero-mass-media'),
                ],
                [
                    'label' => __('Space saved', 'zero-mass-media'),
                    'value' => size_format((int) ($stats['total_savings'] ?? 0)),
                    'meta' => __('Estimated savings from optimized assets.', 'zero-mass-media'),
                    'tone' => 'accent',
                ],
                [
                    'label' => __('Queue', 'zero-mass-media'),
                    'value' => number_format_i18n((int) ($queue['queued'] ?? 0)),
                    'meta' => __('Images waiting for background optimization.', 'zero-mass-media'),
                ],
                [
                    'label' => __('Audit issues', 'zero-mass-media'),
                    'value' => number_format_i18n((int) ($audit['missing_alt'] ?? 0) + (int) ($audit['oversized_files'] ?? 0) + (int) ($audit['missing_modern_formats'] ?? 0)),
                    'meta' => __('Accessibility, size, and modern-format findings.', 'zero-mass-media'),
                    'tone' => 'danger',
                ],
            ],
            'sections' => [
                [
                    'id' => 'zero-mass-workspace',
                    'navLabel' => __('Workspace', 'zero-mass-media'),
                    'eyebrow' => __('React workspace', 'zero-mass-media'),
                    'title' => __('Optimisation, audit, and queue controls', 'zero-mass-media'),
                    'description' => __('This is the existing production Zero Mass React app mounted inside the unified Foundation frame.', 'zero-mass-media'),
                    'templateId' => 'foundation-zero-mass-workspace',
                ],
            ],
        ];
    }

    public function ajax_get_dashboard_data() {
        check_ajax_referer('zmm-ajax-nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'zero-mass-media')], 403);
        }

        $this->options = $this->get_options();
        wp_send_json_success($this->get_dashboard_payload());
    }

    public function ajax_save_settings() {
        check_ajax_referer('zmm-ajax-nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'zero-mass-media')], 403);
        }

        $settings = wp_unslash($_POST['settings'] ?? []);
        if (is_string($settings)) {
            $decoded = json_decode($settings, true);
            $settings = is_array($decoded) ? $decoded : [];
        }
        $sanitized = $this->sanitize_settings($settings);

        update_option('zmm_settings', $sanitized);
        $this->options = $sanitized;
        $this->setup_cron_schedule();

        wp_send_json_success($this->get_dashboard_payload());
    }

    public function ajax_queue_all_unprocessed() {
        check_ajax_referer('zmm-ajax-nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'zero-mass-media')], 403);
        }

        $query = new WP_Query($this->get_image_attachment_query_args([
            'posts_per_page' => -1,
            'meta_query'     => [[
                'key'     => '_zmm_processed',
                'compare' => 'NOT EXISTS',
            ]],
        ]));

        $queued = 0;
        foreach ($query->posts as $attachment_id) {
            if ($this->is_attachment_excluded($attachment_id)) {
                continue;
            }
            if ($this->queue_attachment_for_processing($attachment_id, 'bulk')) {
                $queued++;
            }
        }

        $this->schedule_queue_runner();

        wp_send_json_success([
            'message' => sprintf(
                _n('%d image queued for background optimization.', '%d images queued for background optimization.', $queued, 'zero-mass-media'),
                $queued
            ),
            'queue' => $this->get_queue_summary(),
        ]);
    }

    public function enqueue_admin_assets($hook) {
        $is_settings_page = $hook === $this->admin_page_hook || strpos($hook, ZMM_SETTINGS_SLUG) !== false;
        $is_media_tools_page = in_array($hook, ['media-new.php', 'upload.php', 'post.php', 'post-new.php'], true);

        if ($is_settings_page) {
            wp_enqueue_style('foundation-admin-shell', ZMM_URL . 'assets/admin/foundation-admin-shell.css', [], ZMM_VERSION);
            wp_enqueue_style('zmm-admin-app-css', ZMM_URL . 'assets/admin-app.css', ['foundation-admin-shell'], ZMM_VERSION);
            wp_enqueue_script('foundation-admin-shell', ZMM_URL . 'assets/admin/foundation-admin-shell.js', ['wp-element'], ZMM_VERSION, true);
            wp_add_inline_script(
                'foundation-admin-shell',
                'window.foundationAdminShellData = ' . wp_json_encode($this->get_shell_config()) . ';',
                'before'
            );
            wp_enqueue_script('zmm-admin-app-js', ZMM_URL . 'assets/admin-app.js', ['foundation-admin-shell'], ZMM_VERSION, true);
            wp_localize_script('zmm-admin-app-js', 'zmmAdmin', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('zmm-ajax-nonce'),
            ]);
        }

        if ($is_media_tools_page) {
            wp_enqueue_style('zmm-admin-css', ZMM_URL . 'admin.css', [], ZMM_VERSION);
            wp_enqueue_script('zmm-admin-js', ZMM_URL . 'admin.js', ['jquery'], ZMM_VERSION, true);

            wp_localize_script('zmm-admin-js', 'zmm_ajax', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('zmm-ajax-nonce'),
                'i18n'     => [
                    'processing' => __('Processing...', 'zero-mass-media'),
                    'optimizing' => __('Smartly Optimizing...', 'zero-mass-media'),
                    'optimized'  => __('Smartly Optimized', 'zero-mass-media'),
                    'error' => __('An error occurred.', 'zero-mass-media'),
                    'complete' => __('Bulk processing complete!', 'zero-mass-media'),
                    'restore' => __('Restore', 'zero-mass-media')
                ]
            ]);

            // Add inline CSS for the progress bar animation and new UI elements.
            $inline_css = "
                @keyframes zmm-progress-indeterminate {
                    from { background-position: 2rem 0; }
                    to { background-position: 0 0; }
                }
                .zmm-actions-cell .zmm-action-buttons, .compat-field-zmm_grid_actions .zmm-action-buttons {
                    display: flex;
                    flex-direction: column;
                    align-items: stretch; /* Make buttons full width */
                    gap: 8px;
                    width: 100%;
                    max-width: 220px; /* Control max width on large screens */
                }
                .zmm-styled-button {
                    display: inline-flex !important;
                    align-items: center;
                    justify-content: space-between;
                    padding: 5px 5px 5px 25px; /* Updated padding */
                    border-radius: 20px; /* Rounded corners */
                    font-weight: 600;
                    font-size: 12px;
                    line-height: 1;
                    border: none;
                    color: #fff;
                    cursor: pointer;
                    transition: all 0.2s ease;
                    text-align: left;
                    position: relative;
                    overflow: hidden;
                }
                .zmm-styled-button:hover {
                    transform: translateY(-1px);
                    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                }
                .zmm-styled-button .zmm-score-badge {
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    width: 24px;
                    height: 24px;
                    border-radius: 50%;
                    background-color: #fff;
                    margin-left: 8px;
                    font-size: 12px;
                    color: #333;
                }
                .zmm-a11y-button.good { background-color: #4caf50; }
                .zmm-a11y-button.average { background-color: #ff9800; }
                .zmm-a11y-button.poor { background-color: #d63638; }

                .zmm-action-btn.zmm-styled-button {
                    background-color: #2271b1; /* WordPress Blue */
                    justify-content: center;
                    padding: 8px 15px;
                }
                .zmm-processed-btn.zmm-styled-button {
                    background-color: #f0f0f1 !important;
                    color: #444 !important;
                    cursor: default !important;
                    justify-content: center;
                    padding: 8px 15px;
                }
                .zmm-processed-btn .dashicons {
                    color: #4caf50;
                }
                 .zmm-styled-button.zmm-processing > * {
                    opacity: 0.5;
                }
                .zmm-styled-button.zmm-processing::before {
                    content: '';
                    position: absolute;
                    top: 0;
                    left: 0;
                    width: 100%;
                    height: 100%;
                    background-image: linear-gradient(
                        45deg, 
                        rgba(255, 255, 255, .15) 25%, 
                        transparent 25%, 
                        transparent 50%, 
                        rgba(255, 255, 255, .15) 50%, 
                        rgba(255, 255, 255, .15) 75%, 
                        transparent 75%, 
                        transparent
                    );
                    background-size: 2rem 2rem;
                    animation: zmm-progress-indeterminate 1s linear infinite;
                }
                .compat-field-zmm_grid_actions .label { display: none; }
                .compat-field-zmm_grid_actions .field { width: 100%; }
                .attachment-info + .compat-field-zmm_grid_actions {
                    margin-top: 15px;
                    padding-top: 15px;
                    border-top: 1px solid #ddd;
                }
                .compat-field-zmm_grid_actions h4 {
                    display: none;
                }
            ";
            wp_add_inline_style('zmm-admin-css', $inline_css);

            $inline_js = "
                jQuery(document).ready(function($) {
                    // Unbind the old click handler from admin.js to prevent it from running
                    $(document).off('click', '.zmm-action-btn');
                    $(document).off('click', '.zmm-restore-btn');

                    // Define the new, improved handleAction function
                    function newHandleAction(button) {
                        if (button.hasClass('zmm-processing')) {
                            return;
                        }

                        const action = button.data('action');
                        const cell = button.closest('.zmm-actions-cell, .compat-field-zmm_grid_actions');
                        const feedback = cell.find('.zmm-feedback');
                        const originalHtml = button.html(); // Save original HTML content

                        button.addClass('zmm-processing').prop('disabled', true);
                        feedback.text('').removeClass('error success');

                        $.post(zmm_ajax.ajax_url, {
                            action: 'zmm_process_single_image',
                            nonce: zmm_ajax.nonce,
                            task: action,
                            id: button.data('id')
                        })
                        .done(function(response) {
                            if (response.success) {
                                feedback.text(response.data.message).addClass('success');
                                if (action === 'compress' || action === 'restore') {
                                    setTimeout(() => location.reload(), 1500);
                                } else {
                                     button.removeClass('zmm-processing').prop('disabled', false).html(originalHtml);
                                }
                            } else {
                                feedback.text(response.data.message).addClass('error');
                                button.removeClass('zmm-processing').prop('disabled', false).html(originalHtml);
                            }
                        })
                        .fail(function(jqXHR) {
                            const errorMsg = jqXHR.responseJSON?.data?.message || zmm_ajax.i18n.error;
                            feedback.text(errorMsg).addClass('error');
                            button.removeClass('zmm-processing').prop('disabled', false).html(originalHtml);
                        });
                    }

                    // Bind the new click handler
                    $(document).on('click', '.zmm-action-btn', function(e) {
                        e.preventDefault();
                        newHandleAction($(this));
                    });

                    // Move ZMM buttons in the grid view modal
                    if (typeof wp !== 'undefined' && wp.media) {
                        wp.media.events.on('attachment:details:render', function() {
                            setTimeout(function() {
                                const zmmField = jQuery('.compat-field-zmm_grid_actions');
                                const attachmentInfo = jQuery('.attachment-info');
                                if (zmmField.length && attachmentInfo.length) {
                                    if (!attachmentInfo.next().is('.compat-field-zmm_grid_actions')) {
                                         zmmField.insertAfter(attachmentInfo);
                                    }
                                }
                            }, 50);
                        });
                    }
                });
            ";
            wp_add_inline_script('zmm-admin-js', $inline_js);
        }
    }
    
    public function handle_new_attachment($attachment_id) {
        if (!wp_attachment_is_image($attachment_id)) {
            return;
        }

        $this->options = $this->get_options();
        $is_oversized_upload = $this->is_attachment_over_size_threshold($attachment_id);
        $exclusion_reason = $this->is_attachment_excluded($attachment_id);

        if ($is_oversized_upload) {
            update_post_meta($attachment_id, '_zmm_large_file_detected', 1);
            update_post_meta($attachment_id, '_zmm_large_file_size', $this->get_attachment_file_size($attachment_id));
            update_post_meta($attachment_id, '_zmm_large_file_threshold', $this->get_oversized_threshold_bytes());
            update_post_meta($attachment_id, '_zmm_large_file_detected_at', time());

            if ($exclusion_reason) {
                update_post_meta($attachment_id, '_zmm_large_file_status', 'excluded');
                update_post_meta($attachment_id, '_zmm_excluded_reason', $exclusion_reason);
            } elseif (!empty($this->options['auto_queue_oversized_uploads']) && !empty($this->options['process_backlog_via_cron'])) {
                if ($this->queue_attachment_for_processing($attachment_id, 'oversized-upload')) {
                    update_post_meta($attachment_id, '_zmm_large_file_status', 'queued');
                    $this->schedule_queue_runner(15);
                }
            } else {
                update_post_meta($attachment_id, '_zmm_large_file_status', 'detected');
            }
        }

        if (!empty($this->options['auto_process_on_upload'])) {
            if (!empty($this->options['process_backlog_via_cron'])) {
                $this->queue_attachment_for_processing($attachment_id, 'upload');
                $this->schedule_queue_runner(30);
            } else {
                $this->process_image_compression($attachment_id);
            }
        }

        if (!empty($this->options['auto_generate_alt'])) {
            $this->process_text_generation($attachment_id);
        }
    }

    public function add_media_library_columns($columns) {
        $columns['zmm_actions'] = __('Foundation: Zero Mass', 'zero-mass-media');
        return $columns;
    }

    public function render_media_library_columns($column_name, $attachment_id) {
        if ($column_name === 'zmm_actions') {
            if (!wp_attachment_is_image($attachment_id)) return;

            $is_processed = get_post_meta($attachment_id, '_zmm_processed', true);
            $saved_percent = get_post_meta($attachment_id, '_zmm_saved_percent', true);
            $has_backup = get_post_meta($attachment_id, '_zmm_backup_path', true);

            echo '<div class="zmm-actions-cell" data-attachment-id="' . esc_attr($attachment_id) . '">';
            echo '<div class="zmm-action-buttons">';

            // Main Action Buttons
            if ($is_processed) {
                $message = "Optimized (" . esc_html($saved_percent) . "% saved)";
                echo '<button class="zmm-styled-button zmm-processed-btn" disabled><span class="dashicons dashicons-yes-alt"></span>&nbsp;' . esc_html($message) . '</button>';
                if ($has_backup) {
                     echo '<button class="zmm-styled-button zmm-action-btn" data-action="restore" data-id="' . esc_attr($attachment_id) . '">' . esc_html__('Restore', 'zero-mass-media') . '</button>';
                }
            } else {
                echo '<button class="zmm-styled-button zmm-action-btn" data-action="compress" data-id="' . esc_attr($attachment_id) . '">' . esc_html__('Process Image', 'zero-mass-media') . '</button>';
            }
            echo '<button class="zmm-styled-button zmm-action-btn" data-action="alt" data-id="' . esc_attr($attachment_id) . '">' . esc_html__('Generate Alt', 'zero-mass-media') . '</button>';

            // A11y Score Button
            $score_data = $this->calculate_a11y_score($attachment_id);
            $score = $score_data['score'];
            $issues = $score_data['issues'];
            $class = 'good';
            if ($score < 80) $class = 'average';
            if ($score < 50) $class = 'poor';

            echo '<button class="zmm-styled-button zmm-a11y-button ' . esc_attr($class) . '" title="' . esc_attr(implode("\n", $issues)) . '">';
            echo '<span>' . esc_html__('A11y Score', 'zero-mass-media') . '</span>';
            echo '<span class="zmm-score-badge">' . esc_html($score) . '</span>';
            echo '</button>';

            echo '</div>'; // End .zmm-action-buttons
            echo '<div class="zmm-feedback"></div>';
            echo '</div>'; // End .zmm-actions-cell
        }
    }

    public function add_actions_to_edit_media_screen($post) {
        if ($post->post_type !== 'attachment' || !wp_attachment_is_image($post->ID)) return;
        
        $attachment_id = $post->ID;
        $is_processed = get_post_meta($attachment_id, '_zmm_processed', true);
        $saved_percent = get_post_meta($attachment_id, '_zmm_saved_percent', true);
        $has_backup = get_post_meta($attachment_id, '_zmm_backup_path', true);
        
        echo '<div class="misc-pub-section zmm-edit-media-actions" data-attachment-id="' . esc_attr($attachment_id) . '">';
        echo '<h4>' . __('Foundation: Zero Mass', 'zero-mass-media') . '</h4>';
        echo '<div class="zmm-action-buttons">';
        if ($is_processed) {
            $message = "Smartly Optimized (" . esc_html($saved_percent) . "% saved)";
            echo '<button class="button button-small zmm-processed-btn" disabled><span class="dashicons dashicons-yes-alt"></span> ' . esc_html($message) . '</button>';
            if ($has_backup) {
                 echo '<button class="button button-small zmm-action-btn" data-action="restore" data-id="' . esc_attr($attachment_id) . '">' . esc_html__('Restore', 'zero-mass-media') . '</button>';
            }
        } else {
            echo '<button class="button button-small zmm-action-btn" data-action="compress" data-id="' . esc_attr($attachment_id) . '">' . esc_html__('Process Image', 'zero-mass-media') . '</button>';
        }

        echo '<button class="button button-small zmm-action-btn" data-action="alt" data-id="' . esc_attr($attachment_id) . '">' . esc_html__('Generate Alt', 'zero-mass-media') . '</button>';
        
        echo '</div>';
        echo '<div class="zmm-progress-container" style="display: none;"><div class="zmm-progress-bar"></div><div class="zmm-progress-text"></div></div>';
        echo '<div class="zmm-feedback"></div>';
        echo '</div>';
    }

    public function add_actions_to_grid_view_modal($form_fields, $post) {
        if (!wp_attachment_is_image($post->ID)) {
            return $form_fields;
        }

        ob_start();

        $attachment_id = $post->ID;
        $is_processed = get_post_meta($attachment_id, '_zmm_processed', true);
        $saved_percent = get_post_meta($attachment_id, '_zmm_saved_percent', true);
        $has_backup = get_post_meta($attachment_id, '_zmm_backup_path', true);

        echo '<div class="zmm-actions-cell" data-attachment-id="' . esc_attr($attachment_id) . '">';
        echo '<h4>' . __('Foundation: Zero Mass', 'zero-mass-media') . '</h4>';
        echo '<div class="zmm-action-buttons">';

        // Main Action Buttons
        if ($is_processed) {
            $message = "Optimized (" . esc_html($saved_percent) . "% saved)";
            echo '<button class="zmm-styled-button zmm-processed-btn" disabled><span class="dashicons dashicons-yes-alt"></span>&nbsp;' . esc_html($message) . '</button>';
            if ($has_backup) {
                 echo '<button class="zmm-styled-button zmm-action-btn" data-action="restore" data-id="' . esc_attr($attachment_id) . '">' . esc_html__('Restore', 'zero-mass-media') . '</button>';
            }
        } else {
            echo '<button class="zmm-styled-button zmm-action-btn" data-action="compress" data-id="' . esc_attr($attachment_id) . '">' . esc_html__('Process Image', 'zero-mass-media') . '</button>';
        }
        echo '<button class="zmm-styled-button zmm-action-btn" data-action="alt" data-id="' . esc_attr($attachment_id) . '">' . esc_html__('Generate Alt', 'zero-mass-media') . '</button>';

        // A11y Score Button
        $score_data = $this->calculate_a11y_score($attachment_id);
        $score = $score_data['score'];
        $issues = $score_data['issues'];
        $class = 'good';
        if ($score < 80) $class = 'average';
        if ($score < 50) $class = 'poor';

        echo '<button class="zmm-styled-button zmm-a11y-button ' . esc_attr($class) . '" title="' . esc_attr(implode("\n", $issues)) . '">';
        echo '<span>' . esc_html__('A11y Score', 'zero-mass-media') . '</span>';
        echo '<span class="zmm-score-badge">' . esc_html($score) . '</span>';
        echo '</button>';

        echo '</div>'; // End .zmm-action-buttons
        echo '<div class="zmm-feedback"></div>';
        echo '</div>'; // End .zmm-actions-cell

        $html = ob_get_clean();

        $form_fields['zmm_grid_actions'] = [
            'label' => '',
            'input' => 'html',
            'html'  => $html,
        ];

        return $form_fields;
    }

    public function ajax_process_single_image() {
        check_ajax_referer('zmm-ajax-nonce', 'nonce');
        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => __('Permission denied.', 'zero-mass-media')]);
        }

        $task = sanitize_key($_POST['task']);
        $id = intval($_POST['id']);

        if (empty($task) || $id <= 0) {
            wp_send_json_error(['message' => __('Invalid request.', 'zero-mass-media')]);
        }

        switch ($task) {
            case 'compress':
                $result = $this->process_image_compression($id);
                if (is_wp_error($result)) {
                    wp_send_json_error(['message' => $result->get_error_message()]);
                } else {
                    wp_send_json_success($result);
                }
                break;
            case 'alt':
                $result = $this->process_text_generation($id);
                 if (is_wp_error($result)) {
                    wp_send_json_error(['message' => $result->get_error_message()]);
                } else {
                    wp_send_json_success(['message' => 'Alt text generated!', 'data' => $result]);
                }
                break;
            case 'restore':
                $result = $this->restore_original_image($id);
                if (is_wp_error($result)) {
                    wp_send_json_error(['message' => $result->get_error_message()]);
                } else {
                    wp_send_json_success(['message' => 'Original image restored.']);
                }
                break;
            default:
                wp_send_json_error(['message' => __('Invalid request or task specified.', 'zero-mass-media')]);
        }
    }

    public function ajax_bulk_process() {
        check_ajax_referer('zmm-ajax-nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied.']);
        }

        $action = sanitize_key($_POST['bulk_action']);

        if ($action === 'get_unprocessed_images') {
            $query = new WP_Query([
                'post_type' => 'attachment',
                'post_status' => 'inherit',
                'posts_per_page' => -1,
                'fields' => 'ids',
                'meta_query' => [
                    [
                        'key' => '_zmm_processed',
                        'compare' => 'NOT EXISTS'
                    ]
                ],
                'post_mime_type' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
                'cache_results'  => false,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            ]);
            wp_send_json_success(['ids' => $query->posts]);
        } elseif ($action === 'process_batch') {
            $id = intval($_POST['id']);
            $result = $this->process_image_compression($id);
            if (is_wp_error($result)) {
                wp_send_json_error(['message' => "ID {$id}: " . $result->get_error_message()]);
            } else {
                wp_send_json_success(['message' => "ID {$id} processed."]);
            }
        }
    }

    public function ajax_bulk_generate_alt() {
        check_ajax_referer('zmm-ajax-nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied.']);
        }

        $action = sanitize_key($_POST['bulk_action']);

        if ($action === 'get_images_without_alt') {
            $query = new WP_Query(['post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_query' => ['relation' => 'AND', ['key' => '_wp_attachment_image_alt', 'value' => '', 'compare' => '='], ['key' => '_zmm_is_decorative', 'compare' => 'NOT EXISTS']], 'post_mime_type' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp']]);
            wp_send_json_success(['ids' => $query->posts]);
        } elseif ($action === 'process_alt_batch') {
            $id = intval($_POST['id']);
            $result = $this->process_text_generation($id);
            if (is_wp_error($result)) {
                wp_send_json_error(['message' => "ID {$id}: " . $result->get_error_message()]);
            } else {
                wp_send_json_success(['message' => "ID {$id} alt text generated."]);
            }
        }
    }

    public function ajax_check_status() {
        check_ajax_referer('zmm-ajax-nonce', 'nonce');
        if (!current_user_can('upload_files')) {
            wp_send_json_error(['status' => 'error', 'message' => 'Permission denied.']);
        }

        $id = intval($_POST['id']);
        if ($id <= 0) {
            wp_send_json_error(['status' => 'error', 'message' => 'Invalid ID.']);
        }

        if (get_post_meta($id, '_zmm_processed', true)) {
            $saved_percent = get_post_meta($id, '_zmm_saved_percent', true);
            $message = "Smartly Optimized (" . esc_html($saved_percent) . "% saved)";
            wp_send_json_success(['status' => 'processed', 'message' => $message]);
        } else {
            wp_send_json_success(['status' => 'pending']);
        }
    }
    
    public function ajax_manual_backup_cleanup() {
        check_ajax_referer('zmm-ajax-nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied.']);
        }
        $count = $this->run_backup_cleanup(true);
        wp_send_json_success(['message' => sprintf(__('%d old backup files were cleaned up.', 'zero-mass-media'), $count)]);
    }

    public function ajax_reset_all_status() {
        check_ajax_referer('zmm-ajax-nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied.']);
        }

        global $wpdb;
        $deleted_count = $wpdb->delete($wpdb->postmeta, ['meta_key' => '_zmm_processed']);
        $wpdb->delete($wpdb->postmeta, ['meta_key' => '_zmm_bytes_saved']);
        
        delete_transient('zmm_library_stats');
        wp_cache_flush();

        if (false === $deleted_count) {
            wp_send_json_error(['message' => 'Failed to reset statuses due to a database error.']);
        }

        wp_send_json_success(['message' => sprintf(__('%d image optimization statuses have been reset.', 'zero-mass-media'), $deleted_count)]);
    }

    private function get_quality_settings() {
        $profile = $this->options['compression_profile'] ?? 'balanced';
        switch ($profile) {
            case 'maximum_performance':
                return ['jpeg' => 76, 'webp' => 74, 'avif' => 66];
            case 'brand_quality':
                return ['jpeg' => 92, 'webp' => 90, 'avif' => 86];
            case 'accessibility_low_bandwidth':
                return ['jpeg' => 72, 'webp' => 70, 'avif' => 62];
            case 'balanced':
                return ['jpeg' => 82, 'webp' => 80, 'avif' => 75];
        }

        $level = $this->options['overall_quality'] ?? 'recommended';
        switch ($level) {
            case 'high': return ['jpeg' => 90, 'webp' => 88, 'avif' => 85];
            case 'highest': return ['jpeg' => 95, 'webp' => 93, 'avif' => 90];
            default: return ['jpeg' => 82, 'webp' => 80, 'avif' => 75];
        }
    }

    private function get_library_stats() {
        $stats = get_transient('zmm_library_stats');
        if (false !== $stats) {
            return $stats;
        }

        global $wpdb;
        $total_savings = (int) $wpdb->get_var("SELECT SUM(meta_value) FROM $wpdb->postmeta WHERE meta_key = '_zmm_bytes_saved'");

        $current_size = 0;
        $attachments = get_posts([
            'post_type' => 'attachment',
            'post_mime_type' => 'image',
            'numberposts' => -1,
            'post_status' => 'any',
            'fields' => 'ids'
        ]);

        foreach ($attachments as $attachment_id) {
            $filepath = get_attached_file($attachment_id);
            if ($filepath && file_exists($filepath)) {
                $current_size += filesize($filepath);
            }
        }
        
        $stats = [
            'total_savings' => $total_savings,
            'current_size' => $current_size,
            'original_size' => $current_size + $total_savings
        ];
        
        set_transient('zmm_library_stats', $stats, 12 * HOUR_IN_SECONDS);
        return $stats;
    }

    private function calculate_a11y_score($attachment_id) {
        $score = 100;
        $issues = [];
        $alt_text = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
        $is_decorative = get_post_meta($attachment_id, '_zmm_is_decorative', true);
        $filepath = get_attached_file($attachment_id);
        $filename = $filepath ? pathinfo($filepath, PATHINFO_FILENAME) : '';

        if (empty($alt_text) && !$is_decorative) {
            $score -= 40;
            $issues[] = 'Missing alt text.';
        }

        if (!empty($alt_text)) {
            if (mb_strlen($alt_text) > 150) {
                $score -= 15;
                $issues[] = 'Alt text is too long (>150 chars).';
            }
            if ($filename && strcasecmp($alt_text, $filename) == 0) {
                $score -= 20;
                $issues[] = 'Alt text is the same as the filename.';
            }
        }
        
        if (empty($issues)) $issues[] = 'Looks good!';
        return ['score' => max(0, $score), 'issues' => $issues];
    }

    private function process_image_compression($attachment_id) {
        if (!wp_attachment_is_image($attachment_id)) {
            return new WP_Error('not_an_image', __('The specified attachment is not an image.', 'zero-mass-media'));
        }

        $exclusion_reason = $this->is_attachment_excluded($attachment_id);
        if ($exclusion_reason) {
            update_post_meta($attachment_id, '_zmm_excluded_reason', $exclusion_reason);
            update_post_meta($attachment_id, '_zmm_queue_status', 'complete');
            return [
                'message'       => sprintf(__('Skipped: %s', 'zero-mass-media'), $exclusion_reason),
                'saved_percent' => 0,
                'can_restore'   => false,
            ];
        }

        $mime_type = get_post_mime_type($attachment_id);

        // Handle already optimized formats gracefully
        if (in_array($mime_type, ['image/svg+xml', 'image/avif', 'image/webp'])) {
            update_post_meta($attachment_id, '_zmm_processed', true);
            update_post_meta($attachment_id, '_zmm_saved_percent', 0);
            update_post_meta($attachment_id, '_zmm_bytes_saved', 0);
            update_post_meta($attachment_id, '_zmm_queue_status', 'complete');
            
            $format_name = strtoupper(str_replace('image/', '', $mime_type));
            
            return [
                'message'       => sprintf(__('%s is already an optimized format. (0%% saved)', 'zero-mass-media'), $format_name),
                'saved_percent' => 0,
                'can_restore'   => false, // No backup is created for these
            ];
        }

        if (!isset($this->compression_attempts[$attachment_id])) $this->compression_attempts[$attachment_id] = 0;
        $this->compression_attempts[$attachment_id]++;
        if ($this->compression_attempts[$attachment_id] > 3) return new WP_Error('max_attempts', __('Maximum processing attempts reached.', 'zero-mass-media'));
        
        $original_filepath = get_attached_file($attachment_id);
        if (!$original_filepath || !file_exists($original_filepath)) return new WP_Error('file_missing', __('Original file not found on server.', 'zero-mass-media'));
        if (!is_writable(dirname($original_filepath))) return new WP_Error('permission_denied', __('Uploads directory is not writable.', 'zero-mass-media'));
        
        if (!empty($this->options['keep_original_backup'])) {
            $this->create_backup($original_filepath, $attachment_id);
        }
        
        $result = $this->process_image_formats($original_filepath, $attachment_id);
        if (is_wp_error($result)) {
            $this->log_error($result->get_error_message(), $attachment_id);
            return $result;
        }
        
        $this->verify_processed_files($original_filepath, $attachment_id);
        update_post_meta($attachment_id, '_zmm_last_verified', time());
        if (!empty($this->options['enable_lqip'])) $this->generate_lqip($original_filepath, $attachment_id);
        
        delete_transient('zmm_library_stats');
        unset($this->compression_attempts[$attachment_id]);
        return $result;
    }

    private function create_backup($filepath, $attachment_id) {
        if(get_post_meta($attachment_id, '_zmm_backup_path', true)) return true;
        $backup_path = pathinfo($filepath, PATHINFO_DIRNAME) . '/' . pathinfo($filepath, PATHINFO_FILENAME) . '-zmmoriginal.' . pathinfo($filepath, PATHINFO_EXTENSION);
        if (!@copy($filepath, $backup_path)) return new WP_Error('backup_failed', __('Could not create backup file.', 'zero-mass-media'));
        update_post_meta($attachment_id, '_zmm_backup_path', $backup_path);
        return $backup_path;
    }

    private function process_image_formats($original_path, $attachment_id) {
        $original_size = filesize($original_path);
        $quality = $this->get_quality_settings();
        $editor = wp_get_image_editor($original_path);
        if (is_wp_error($editor)) return $editor;

        $original_dimensions = @getimagesize($original_path);
        $editor->resize(intval($this->options['max_width']), intval($this->options['max_height']), false);
        $editor->set_quality($quality['jpeg']);
        $candidate_path = trailingslashit(pathinfo($original_path, PATHINFO_DIRNAME)) .
            wp_unique_filename(
                pathinfo($original_path, PATHINFO_DIRNAME),
                pathinfo($original_path, PATHINFO_FILENAME) . '-zmm-work.' . pathinfo($original_path, PATHINFO_EXTENSION)
            );
        $saved = $editor->save($candidate_path);
        if (is_wp_error($saved)) return $saved;

        clearstatcache();
        $candidate_size = file_exists($candidate_path) ? filesize($candidate_path) : $original_size;
        $candidate_dimensions = @getimagesize($candidate_path);
        $was_resized = !empty($original_dimensions) && !empty($candidate_dimensions)
            && (
                $candidate_dimensions[0] < $original_dimensions[0] ||
                $candidate_dimensions[1] < $original_dimensions[1]
            );
        $candidate_saved_percent = $original_size > 0 ? (($original_size - $candidate_size) / $original_size) * 100 : 0;
        $passes_quality_guard = $candidate_saved_percent >= (float) ($this->options['quality_guard_min_saving'] ?? 3);

        if (($candidate_size < $original_size && $passes_quality_guard) || $was_resized) {
            @rename($candidate_path, $original_path);
        } elseif (file_exists($candidate_path)) {
            @unlink($candidate_path);
        }

        clearstatcache();
        $compressed_size = filesize($original_path);
        $this->generate_smart_alternative($original_path, 'webp', $quality['webp'], $compressed_size, $attachment_id);
        $this->generate_smart_alternative($original_path, 'avif', $quality['avif'], $compressed_size, $attachment_id);
        wp_update_attachment_metadata($attachment_id, wp_generate_attachment_metadata($attachment_id, $original_path));

        $space_saved = $original_size > 0 ? max(0, $original_size - $compressed_size) : 0;
        $saved_percent = ($original_size > 0) ? round(($space_saved / $original_size) * 100) : 0;
        
        update_post_meta($attachment_id, '_zmm_processed', true);
        update_post_meta($attachment_id, '_zmm_saved_percent', $saved_percent);
        update_post_meta($attachment_id, '_zmm_bytes_saved', $space_saved);
        if (get_post_meta($attachment_id, '_zmm_large_file_detected', true)) {
            update_post_meta($attachment_id, '_zmm_large_file_status', $compressed_size > $this->get_oversized_threshold_bytes() ? 'still-large' : 'optimized');
            update_post_meta($attachment_id, '_zmm_large_file_size_after', $compressed_size);
        }
        
        return [
            'message' => "Smartly Optimized! Saved {$saved_percent}%",
            'saved_percent' => $saved_percent,
            'can_restore' => !empty($this->options['keep_original_backup']) && get_post_meta($attachment_id, '_zmm_backup_path', true)
        ];
    }

    private function generate_smart_alternative($original_path, $format, $quality, $compare_size, $attachment_id) {
        if (!wp_image_editor_supports(['mime_type' => "image/$format"])) return;
        $editor = wp_get_image_editor($original_path);
        if (is_wp_error($editor)) return;

        $editor->set_quality($quality);
        $filename = pathinfo($original_path, PATHINFO_FILENAME);
        $dirname = pathinfo($original_path, PATHINFO_DIRNAME);
        $new_path = $dirname . '/' . $filename . '.' . $format;
        
        if (file_exists($new_path)) @unlink($new_path);
        $saved = $editor->save($new_path, "image/$format");

        if (!is_wp_error($saved) && file_exists($saved['path'])) {
            if (filesize($saved['path']) < $compare_size) {
                update_post_meta($attachment_id, "_zmm_{$format}_exists", true);
            } else {
                @unlink($saved['path']);
                delete_post_meta($attachment_id, "_zmm_{$format}_exists");
            }
        } else {
             delete_post_meta($attachment_id, "_zmm_{$format}_exists");
        }
    }

    private function generate_lqip($filepath, $attachment_id) {
        $editor = wp_get_image_editor($filepath);
        if (is_wp_error($editor)) return;

        $editor->resize(20, null, false);
        $temp_file = wp_tempnam('lqip-');
        $saved = $editor->save($temp_file);

        if (!is_wp_error($saved) && file_exists($saved['path'])) {
            $image_data = file_get_contents($saved['path']);
            $base64 = 'data:' . esc_attr($saved['mime-type']) . ';base64,' . base64_encode($image_data);
            update_post_meta($attachment_id, '_zmm_lqip_placeholder', $base64);
            @unlink($saved['path']);
        }
    }

    private function verify_processed_files($original_path, $attachment_id) {
        $errors = [];
        if (!file_exists($original_path) || filesize($original_path) === 0) $errors[] = __('Original file verification failed', 'zero-mass-media');
        
        $dirname = pathinfo($original_path, PATHINFO_DIRNAME);
        $filename = pathinfo($original_path, PATHINFO_FILENAME);

        // Verify WebP
        $webp_path = $dirname . '/' . $filename . '.webp';
        if (get_post_meta($attachment_id, '_zmm_webp_exists', true) && (!file_exists($webp_path) || filesize($webp_path) === 0)) {
            $errors[] = __('WebP file verification failed', 'zero-mass-media');
            delete_post_meta($attachment_id, '_zmm_webp_exists');
        }

        // Verify AVIF
        $avif_path = $dirname . '/' . $filename . '.avif';
        if (get_post_meta($attachment_id, '_zmm_avif_exists', true) && (!file_exists($avif_path) || filesize($avif_path) === 0)) {
            $errors[] = __('AVIF file verification failed', 'zero-mass-media');
            delete_post_meta($attachment_id, '_zmm_avif_exists');
        }

        return empty($errors) ? true : new WP_Error('verification_failed', implode(', ', $errors));
    }

    private function restore_original_image($attachment_id) {
        $backup_path = get_post_meta($attachment_id, '_zmm_backup_path', true);
        if (empty($backup_path) || !file_exists($backup_path)) return new WP_Error('no_backup', 'No backup file found for this image.');

        $original_path = get_attached_file($attachment_id);
        $filename = pathinfo($original_path, PATHINFO_FILENAME);
        $dirname = trailingslashit(pathinfo($original_path, PATHINFO_DIRNAME));

        if (get_post_meta($attachment_id, '_zmm_webp_exists', true)) @unlink($dirname . $filename . '.webp');
        if (get_post_meta($attachment_id, '_zmm_avif_exists', true)) @unlink($dirname . $filename . '.avif');

        if (!@rename($backup_path, $original_path)) return new WP_Error('restore_failed', 'Could not move backup file back to original location.');
        
        wp_update_attachment_metadata($attachment_id, wp_generate_attachment_metadata($attachment_id, $original_path));
        
        $meta_keys = ['_zmm_processed', '_zmm_saved_percent', '_zmm_bytes_saved', '_zmm_backup_path', '_zmm_webp_exists', '_zmm_avif_exists', '_zmm_last_verified', '_zmm_lqip_placeholder'];
        foreach($meta_keys as $key) {
            delete_post_meta($attachment_id, $key);
        }

        delete_transient('zmm_library_stats');
        return true;
    }

    public function replace_images_with_picture_tags($content) {
        if (is_feed() || is_admin() || (function_exists('is_embed') && is_embed())) return $content;
        preg_match_all('/<img[^>]+>/i', $content, $matches);
        if (empty($matches[0])) return $content;

        foreach ($matches[0] as $img_tag) {
            if (strpos($img_tag, 'skip-zmm') !== false) continue;
            if (preg_match('/wp-image-([0-9]+)/i', $img_tag, $class_match)) {
                $attachment_id = absint($class_match[1]);
                if ($attachment_id && get_post_meta($attachment_id, '_zmm_processed', true)) {
                    if (get_post_meta($attachment_id, '_zmm_is_decorative', true)) {
                        $img_tag = preg_replace('/alt="[^"]*"/', 'alt=""', $img_tag);
                    }
                    $lqip_placeholder = get_post_meta($attachment_id, '_zmm_lqip_placeholder', true);
                    $picture_tag = $this->generate_picture_tag($attachment_id, $img_tag, $lqip_placeholder);
                    if ($picture_tag) $content = str_replace($img_tag, $picture_tag, $content);
                }
            }
        }
        return $content;
    }

    private function generate_picture_tag($attachment_id, $img_tag, $lqip_placeholder = null) {
        clearstatcache();
        
        $upload_dir_info = wp_get_upload_dir();
        $upload_dir_path = $upload_dir_info['basedir'];

        preg_match('/src="([^"]+)"/i', $img_tag, $src_match);
        if (empty($src_match[1])) {
            return $img_tag;
        }
        $image_url = $src_match[1];

        $relative_path = str_replace($upload_dir_info['baseurl'], '', $image_url);
        $full_image_path = $upload_dir_path . $relative_path;

        if (!file_exists($full_image_path)) {
            return $img_tag;
        }

        $base_url = pathinfo($image_url, PATHINFO_DIRNAME) . '/' . pathinfo($image_url, PATHINFO_FILENAME);
        $base_path = pathinfo($full_image_path, PATHINFO_DIRNAME) . '/' . pathinfo($full_image_path, PATHINFO_FILENAME);

        $avif_path = $base_path . '.avif';
        $webp_path = $base_path . '.webp';

        $sources = '';
        if (file_exists($avif_path)) {
            $sources .= '<source srcset="' . esc_url($base_url . '.avif') . '" type="image/avif">';
        }
        if (file_exists($webp_path)) {
            $sources .= '<source srcset="' . esc_url($base_url . '.webp') . '" type="image/webp">';
        }

        if (empty($sources)) {
            return $img_tag;
        }
        
        $lazy_load = !empty($lqip_placeholder) && !empty($this->options['enable_lqip']);
        if ($lazy_load) {
            $img_tag = str_replace('<img ', '<img loading="lazy" class="zmm-lazy" ', $img_tag);
            $img_tag = preg_replace('/src="([^"]+)"/', 'src="' . esc_url($lqip_placeholder) . '" data-src="$1"', $img_tag);
            $sources = preg_replace('/srcset="([^"]+)"/', 'data-srcset="$1"', $sources);
        }
        return '<picture>' . $sources . $img_tag . '</picture>';
    }

    public function add_lazy_load_script() {
        if (empty($this->options['enable_lqip'])) return;
        ?>
        <script id="zmm-lazy-load-script">(function(){document.addEventListener("DOMContentLoaded",function(){if("IntersectionObserver"in window){let e=new IntersectionObserver(function(t,o){t.forEach(function(t){if(t.isIntersecting){let n=t.target,i=n.parentElement;"PICTURE"===i.tagName&&i.querySelectorAll("source[data-srcset]").forEach(function(e){e.srcset=e.dataset.srcset}),n.src=n.dataset.src,n.classList.remove("zmm-lazy"),e.unobserve(n)}})});[].slice.call(document.querySelectorAll("img.zmm-lazy")).forEach(function(t){e.observe(t)})}})})()</script>
        <?php
    }

    private function get_likely_lcp_attachment_id() {
        if (is_singular() && has_post_thumbnail()) {
            return (int) get_post_thumbnail_id();
        }

        return 0;
    }

    public function print_lcp_preload_hint() {
        if (is_admin() || empty($this->options['enable_lcp_preload'])) {
            return;
        }

        $attachment_id = $this->get_likely_lcp_attachment_id();
        if (!$attachment_id || $this->is_attachment_excluded($attachment_id)) {
            return;
        }

        $src = wp_get_attachment_image_src($attachment_id, 'full');
        if (empty($src[0])) {
            return;
        }

        printf(
            '<link rel="preload" as="image" href="%s" fetchpriority="high" data-zero-mass-lcp="1">' . "\n",
            esc_url($src[0])
        );
    }

    public function adjust_lcp_image_attributes($attr, $attachment, $size) {
        if (empty($this->options['enable_lcp_preload']) || !($attachment instanceof WP_Post)) {
            return $attr;
        }

        if ((int) $attachment->ID === $this->get_likely_lcp_attachment_id()) {
            $attr['fetchpriority'] = 'high';
            $attr['loading'] = 'eager';
            $attr['decoding'] = 'async';
            $attr['data-zero-mass-lcp'] = '1';
        }

        return $attr;
    }

    public function process_attachment($attachment_id) {
        return $this->process_image_compression(absint($attachment_id));
    }

    public function restore_attachment($attachment_id) {
        return $this->restore_original_image(absint($attachment_id));
    }

    public function queue_all_unprocessed($limit = -1) {
        $query = new WP_Query($this->get_image_attachment_query_args([
            'posts_per_page' => (int) $limit,
            'meta_query'     => [[
                'key'     => '_zmm_processed',
                'compare' => 'NOT EXISTS',
            ]],
        ]));

        $queued = 0;
        foreach ($query->posts as $attachment_id) {
            if ($this->is_attachment_excluded($attachment_id)) {
                continue;
            }
            if ($this->queue_attachment_for_processing($attachment_id, 'wp-cli')) {
                $queued++;
            }
        }

        if ($queued > 0) {
            $this->schedule_queue_runner();
        }

        return $queued;
    }

    public function get_queue_report() {
        return $this->get_queue_summary();
    }

    public function add_decorative_field($form_fields, $post) {
        $is_decorative = get_post_meta($post->ID, '_zmm_is_decorative', true);
        $form_fields['zmm_is_decorative'] = ['label' => __('Decorative Image', 'zero-mass-media'), 'input' => 'html', 'html' => '<label for="attachments-' . $post->ID . '-zmm_is_decorative"><input type="checkbox" id="attachments-' . $post->ID . '-zmm_is_decorative" name="attachments[' . $post->ID . '][zmm_is_decorative]" value="1" ' . checked($is_decorative, 1, false) . ' /> Check this if the image is purely decorative and does not need alt text.</label>', 'helps' => __('This will set an empty alt tag (alt="") so screen readers ignore it.', 'zero-mass-media')];
        return $form_fields;
    }

    public function save_decorative_field($post, $attachment) {
        if (isset($attachment['zmm_is_decorative'])) update_post_meta($post['ID'], '_zmm_is_decorative', 1);
        else delete_post_meta($post['ID'], '_zmm_is_decorative');
        return $post;
    }

    private function queue_attachment_for_processing($attachment_id, $source = 'manual') {
        if (!wp_attachment_is_image($attachment_id) || get_post_meta($attachment_id, '_zmm_processed', true)) {
            return false;
        }

        $existing_status = get_post_meta($attachment_id, '_zmm_queue_status', true);
        if (in_array($existing_status, ['queued', 'processing'], true)) {
            return false;
        }

        update_post_meta($attachment_id, '_zmm_queue_status', 'queued');
        update_post_meta($attachment_id, '_zmm_queue_source', sanitize_key($source));
        update_post_meta($attachment_id, '_zmm_queue_queued_at', time());

        return true;
    }

    private function has_queued_attachments() {
        return $this->count_attachment_query([
            'meta_query' => [[
                'key'   => '_zmm_queue_status',
                'value' => 'queued',
            ]],
        ]) > 0;
    }

    private function schedule_queue_runner($delay = 60) {
        if (empty($this->options['process_backlog_via_cron']) || wp_next_scheduled(ZMM_QUEUE_HOOK)) {
            return;
        }

        wp_schedule_single_event(time() + max(15, absint($delay)), ZMM_QUEUE_HOOK);
    }

    public function setup_cron_schedule($new_schedule = null) {
        $this->options = $this->get_options();
        $maintenance_schedule = $new_schedule ?: ($this->options['cron_schedule'] ?? 'daily');
        $queue_schedule = $this->options['queue_schedule'] ?? 'zmm_every_fifteen_minutes';

        $verification_event = wp_get_scheduled_event(ZMM_VERIFY_HOOK);
        if (!$verification_event || $verification_event->schedule !== $maintenance_schedule) {
            if ($verification_event) {
                wp_clear_scheduled_hook(ZMM_VERIFY_HOOK);
            }
            wp_schedule_event(time(), $maintenance_schedule, ZMM_VERIFY_HOOK);
        }

        $backup_event = wp_get_scheduled_event(ZMM_BACKUP_HOOK);
        if (!$backup_event || $backup_event->schedule !== $maintenance_schedule) {
            if ($backup_event) {
                wp_clear_scheduled_hook(ZMM_BACKUP_HOOK);
            }
            wp_schedule_event(time(), $maintenance_schedule, ZMM_BACKUP_HOOK);
        }

        if (empty($this->options['process_backlog_via_cron'])) {
            wp_clear_scheduled_hook(ZMM_QUEUE_HOOK);
            return;
        }

        $queue_event = wp_get_scheduled_event(ZMM_QUEUE_HOOK);
        if (!$queue_event || $queue_event->schedule !== $queue_schedule) {
            if ($queue_event) {
                wp_clear_scheduled_hook(ZMM_QUEUE_HOOK);
            }
            wp_schedule_event(time(), $queue_schedule, ZMM_QUEUE_HOOK);
        }

    }

    public function run_processing_queue() {
        $this->options = $this->get_options();
        if (empty($this->options['process_backlog_via_cron'])) {
            return;
        }

        $batch_size = max(1, min(25, absint($this->options['queue_batch_size'] ?? 3)));
        $query = new WP_Query($this->get_image_attachment_query_args([
            'posts_per_page' => $batch_size,
            'meta_key'       => '_zmm_queue_queued_at',
            'orderby'        => 'meta_value_num',
            'order'          => 'ASC',
            'meta_query'     => [[
                'key'   => '_zmm_queue_status',
                'value' => 'queued',
            ]],
        ]));

        foreach ($query->posts as $attachment_id) {
            update_post_meta($attachment_id, '_zmm_queue_status', 'processing');
            update_post_meta($attachment_id, '_zmm_queue_started_at', time());

            $result = $this->process_image_compression($attachment_id);
            if (is_wp_error($result)) {
                update_post_meta($attachment_id, '_zmm_queue_status', 'failed');
                update_post_meta($attachment_id, '_zmm_queue_last_error', $result->get_error_message());
                update_post_meta(
                    $attachment_id,
                    '_zmm_queue_attempts',
                    absint(get_post_meta($attachment_id, '_zmm_queue_attempts', true)) + 1
                );
                continue;
            }

            update_post_meta($attachment_id, '_zmm_queue_status', 'complete');
            delete_post_meta($attachment_id, '_zmm_queue_last_error');
        }

        if ($this->has_queued_attachments()) {
            $this->schedule_queue_runner();
        }
    }

    public function run_daily_verification() {
        $query = new WP_Query(['post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => 100, 'fields' => 'ids', 'meta_query' => [['key' => '_zmm_processed', 'compare' => 'EXISTS']]]);
        foreach ($query->posts as $attachment_id) {
            $filepath = get_attached_file($attachment_id);
            if ($filepath && file_exists($filepath)) {
                if (is_wp_error($this->verify_processed_files($filepath, $attachment_id))) {
                    $this->log_error('Daily Verification Failed for attachment ' . $attachment_id);
                } else {
                    update_post_meta($attachment_id, '_zmm_last_verified', time());
                }
            }
        }
    }

    public function run_backup_cleanup($manual_run = false) {
        $cleanup_days = (int) ($this->options['backup_cleanup_days'] ?? 30);
        if ($cleanup_days <= 0) return 0;
        $query = new WP_Query(['post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => 100, 'fields' => 'ids', 'meta_query' => [['key' => '_zmm_backup_path', 'compare' => 'EXISTS']]]);
        $deleted_count = 0;
        foreach ($query->posts as $attachment_id) {
            $backup_path = get_post_meta($attachment_id, '_zmm_backup_path', true);
            if ($backup_path && file_exists($backup_path)) {
                if (filemtime($backup_path) < strtotime("-{$cleanup_days} days")) {
                    if (@unlink($backup_path)) {
                        delete_post_meta($attachment_id, '_zmm_backup_path');
                        $deleted_count++;
                    }
                }
            } else if ($backup_path) {
                delete_post_meta($attachment_id, '_zmm_backup_path');
            }
        }
        return $deleted_count;
    }

    private function log_error($error, $attachment_id = null) {
        if (defined('WP_DEBUG') && WP_DEBUG === true) {
            $log_entry = date('[Y-m-d H:i:s] ');
            if ($attachment_id) $log_entry .= "Attachment $attachment_id: ";
            $log_entry .= $error . "\n";
            error_log($log_entry);
        }
    }

    public function check_dependencies() {
        if (did_action('admin_notices')) return;
        if (!extension_loaded('imagick') && !extension_loaded('gd')) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>';
                echo __('<strong>Foundation: Zero Mass Warning:</strong> Neither the Imagick nor GD image processing libraries are installed on your server. This plugin requires one of them to function. Please contact your hosting provider.', 'zero-mass-media');
                echo '</p></div>';
            });
        }
    }

    public function register_wp_cli_commands() {
        static $registered = false;

        if ($registered) {
            return;
        }

        $cli_file = ZMM_PATH . 'includes/class-wp-cli-command.php';
        if (!file_exists($cli_file) || !class_exists('WP_CLI')) {
            return;
        }

        require_once $cli_file;
        \WP_CLI::add_command('zeromass', 'FoundationZeroMass\\WP_CLI_Command');
        $registered = true;
    }

    /**
     * Checks for WebP and AVIF support and displays an admin notice if not supported.
     */
    public function check_format_support_and_warn() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $missing_formats = [];
        if (!wp_image_editor_supports(['mime_type' => 'image/webp'])) {
            $missing_formats[] = 'WebP';
        }
        if (!wp_image_editor_supports(['mime_type' => 'image/avif'])) {
            $missing_formats[] = 'AVIF';
        }

        if (!empty($missing_formats)) {
            $formats_list = implode(' and ', $missing_formats);
            ?>
            <div class="notice notice-warning is-dismissible">
                <p>
                    <?php
                    printf(
                        esc_html__('Your server is not configured to support %s image generation. To use all features of the Foundation: Zero Mass plugin, please contact your hosting provider and ask them to enable support for these formats in the PHP Imagick or GD library.', 'zero-mass-media'),
                        '<strong>' . esc_html($formats_list) . '</strong>'
                    );
                    ?>
                </p>
            </div>
            <?php
        }
    }
    
    private function process_text_generation($attachment_id) {
        try {
            $file_path = get_attached_file($attachment_id);
            if (!$file_path) {
                throw new Exception('File not found for processing.');
            }

            $filename = pathinfo($file_path, PATHINFO_FILENAME);
            $clean_filename = trim((string) preg_replace('/\s+/', ' ', preg_replace('/[\s_-]+/', ' ', $filename)));
            $clean_filename = preg_replace('/\b\d{2,4}x\d{2,4}\b/i', '', $clean_filename);
            $clean_filename = preg_replace('/\b(?:img|image|photo|screenshot|untitled)\b/i', '', $clean_filename);
            $clean_filename = trim((string) preg_replace('/\s+/', ' ', $clean_filename));
            $clean_filename = $clean_filename ? ucwords(strtolower($clean_filename)) : '';
            
            if (empty($clean_filename)) {
                 throw new Exception('Could not generate text from an empty or generic filename.');
            }

            $output = [];
            
            $parent_id = wp_get_post_parent_id($attachment_id);
            $alt_text = $clean_filename;
            
            if ($parent_id && ($parent_title = get_the_title($parent_id))) {
                if (stripos($clean_filename, $parent_title) === false && stripos($parent_title, $clean_filename) === false) {
                    $alt_text = sprintf('%s for %s', $clean_filename, $parent_title);
                } else {
                    $alt_text = $clean_filename;
                }
            }
            
            update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt_text);
            $output['alt'] = $alt_text;

            return $output;

        } catch (Exception $e) {
            $this->log_error("Internal text generation failed: " . $e->getMessage(), $attachment_id);
            return new WP_Error('generation_failed', $e->getMessage());
        }
    }
}

register_activation_hook(ZMM_FILE, ['Zero_Mass_Media', 'activate']);
register_deactivation_hook(ZMM_FILE, ['Zero_Mass_Media', 'deactivate']);
\FoundationZeroMass\Github_Updater::instance();
Zero_Mass_Media::get_instance();
