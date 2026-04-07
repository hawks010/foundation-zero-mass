<?php

namespace FoundationZeroMass;

if (!defined('ABSPATH') || !defined('WP_CLI') || !WP_CLI) {
    exit;
}

/**
 * WP-CLI commands for Foundation: Zero Mass.
 */
final class WP_CLI_Command {
    /**
     * Print a compact media optimization report.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format. Supports table or json.
     *
     * ## EXAMPLES
     *
     *     wp zeromass report
     *     wp zeromass report --format=json
     */
    public function report($args, $assoc_args) {
        $format = $assoc_args['format'] ?? 'table';
        $report = \Zero_Mass_Media::get_instance()->get_gold_standard_report(10);

        if ('json' === $format) {
            \WP_CLI::line(wp_json_encode($report, JSON_PRETTY_PRINT));
            return;
        }

        \WP_CLI\Utils\format_items('table', [[
            'total_images' => $report['total_images'],
            'missing_alt' => $report['missing_alt'],
            'oversized_files' => $report['oversized_files'],
            'missing_modern_formats' => $report['missing_modern_formats'],
            'lcp_candidates' => $report['lcp_candidates'],
        ]], ['total_images', 'missing_alt', 'oversized_files', 'missing_modern_formats', 'lcp_candidates']);
    }

    /**
     * Queue unprocessed images for background optimization.
     *
     * ## OPTIONS
     *
     * [--limit=<limit>]
     * : Maximum images to queue. Defaults to all unprocessed images.
     */
    public function queue($args, $assoc_args) {
        $limit = isset($assoc_args['limit']) ? (int) $assoc_args['limit'] : -1;
        $queued = \Zero_Mass_Media::get_instance()->queue_all_unprocessed($limit);
        \WP_CLI::success(sprintf('Queued %d image(s).', $queued));
    }

    /**
     * Process the current background queue once.
     */
    public function process_queue() {
        \Zero_Mass_Media::get_instance()->run_processing_queue();
        $queue = \Zero_Mass_Media::get_instance()->get_queue_report();
        \WP_CLI::success(sprintf('Queue run complete. Remaining queued: %d. Failed: %d.', $queue['queued'], $queue['failed']));
    }

    /**
     * Optimize a single image attachment.
     *
     * ## OPTIONS
     *
     * <attachment-id>
     * : Image attachment ID to optimize.
     */
    public function optimize($args) {
        $attachment_id = absint($args[0] ?? 0);
        if (!$attachment_id) {
            \WP_CLI::error('Please provide a valid attachment ID.');
        }

        $result = \Zero_Mass_Media::get_instance()->process_attachment($attachment_id);
        if (is_wp_error($result)) {
            \WP_CLI::error($result->get_error_message());
        }

        \WP_CLI::success($result['message'] ?? 'Image processed.');
    }

    /**
     * Restore a single optimized image from its original backup.
     *
     * ## OPTIONS
     *
     * <attachment-id>
     * : Image attachment ID to restore.
     */
    public function restore($args) {
        $attachment_id = absint($args[0] ?? 0);
        if (!$attachment_id) {
            \WP_CLI::error('Please provide a valid attachment ID.');
        }

        $result = \Zero_Mass_Media::get_instance()->restore_attachment($attachment_id);
        if (is_wp_error($result)) {
            \WP_CLI::error($result->get_error_message());
        }

        \WP_CLI::success('Original image restored.');
    }
}
