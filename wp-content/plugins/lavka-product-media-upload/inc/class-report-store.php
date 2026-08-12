<?php

namespace Lavka\ProductMediaUpload;

if (!defined('ABSPATH')) {
    exit;
}

final class ReportStore
{
    private const TTL = 2 * DAY_IN_SECONDS;

    public static function store(array $report): array
    {
        $token = str_replace('-', '', wp_generate_uuid4());
        set_transient(self::key($token), [
            'user_id' => get_current_user_id(),
            'created_at' => current_time('mysql'),
            'report' => $report,
        ], self::TTL);

        return [
            'token' => $token,
            // This URL is returned as JSON and assigned directly by JavaScript.
            // Building query arguments explicitly avoids wp_nonce_url() encoding
            // separators as "&amp;", which would turn token into "amp;token".
            'url' => add_query_arg([
                'action' => 'lpmu_download_report',
                'token' => $token,
                '_wpnonce' => wp_create_nonce('lpmu_report_' . $token),
            ], admin_url('admin-post.php')),
        ];
    }

    public static function get(string $token): ?array
    {
        $stored = get_transient(self::key($token));
        if (!is_array($stored) || (int) ($stored['user_id'] ?? 0) !== get_current_user_id()) {
            return null;
        }

        return (array) ($stored['report'] ?? []);
    }

    public static function to_csv(array $report): string
    {
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, [
            __('Batch ID', 'lavka-product-media-upload'),
            __('Manifest hash', 'lavka-product-media-upload'),
            __('Row', 'lavka-product-media-upload'),
            __('Status', 'lavka-product-media-upload'),
            __('SKU', 'lavka-product-media-upload'),
            __('Barcode', 'lavka-product-media-upload'),
            __('Source file', 'lavka-product-media-upload'),
            __('Canonical file', 'lavka-product-media-upload'),
            __('Format', 'lavka-product-media-upload'),
            __('MIME', 'lavka-product-media-upload'),
            __('Color space', 'lavka-product-media-upload'),
            __('Width', 'lavka-product-media-upload'),
            __('Height', 'lavka-product-media-upload'),
            __('Bytes', 'lavka-product-media-upload'),
            __('SHA-256', 'lavka-product-media-upload'),
            __('Role', 'lavka-product-media-upload'),
            __('Position', 'lavka-product-media-upload'),
            __('Product ID', 'lavka-product-media-upload'),
            __('Product name', 'lavka-product-media-upload'),
            __('Product type', 'lavka-product-media-upload'),
            __('Attachment ID', 'lavka-product-media-upload'),
            __('URL', 'lavka-product-media-upload'),
            __('S3 key', 'lavka-product-media-upload'),
            __('Workflow stage', 'lavka-product-media-upload'),
            __('Folio operation', 'lavka-product-media-upload'),
            __('Folio status', 'lavka-product-media-upload'),
            __('Folio request ID', 'lavka-product-media-upload'),
            __('Errors', 'lavka-product-media-upload'),
            __('Warnings', 'lavka-product-media-upload'),
            __('Technical details', 'lavka-product-media-upload'),
        ]);

        foreach ((array) ($report['rows'] ?? []) as $row) {
            fputcsv($stream, [
                (string) ($report['batch_id'] ?? ''),
                (string) ($report['manifest_hash'] ?? ''),
                (string) ($row['row_number'] ?? ''),
                (string) ($row['status'] ?? ''),
                (string) ($row['sku'] ?? ''),
                (string) ($row['barcode'] ?? ''),
                (string) ($row['source_file'] ?? ''),
                (string) ($row['canonical_file'] ?? ''),
                (string) ($row['format'] ?? ''),
                (string) ($row['mime'] ?? ''),
                (string) ($row['color_space'] ?? ''),
                (string) ($row['width'] ?? ''),
                (string) ($row['height'] ?? ''),
                (string) ($row['file_size'] ?? ''),
                (string) ($row['sha256'] ?? ''),
                (string) ($row['role'] ?? ''),
                (string) ($row['position'] ?? ''),
                (string) ($row['product_id'] ?? ''),
                (string) ($row['product_name'] ?? ''),
                (string) ($row['product_type'] ?? ''),
                (string) ($row['attachment_id'] ?? ''),
                (string) ($row['url'] ?? ''),
                (string) ($row['s3_key'] ?? ''),
                (string) ($row['workflow_stage'] ?? ''),
                (string) ($row['folio_operation'] ?? ''),
                (string) ($row['folio_status'] ?? ''),
                (string) ($row['folio_external_request_id'] ?? ''),
                implode(' | ', (array) ($row['errors'] ?? [])),
                implode(' | ', (array) ($row['warnings'] ?? [])),
                (string) ($row['technical'] ?? ''),
            ]);
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        return (string) $csv;
    }

    private static function key(string $token): string
    {
        return 'lpmu_report_' . md5($token);
    }
}
