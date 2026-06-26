<?php
if (!defined('ABSPATH')) exit;

function lps_logs_table(): string {
    global $wpdb; return $wpdb->prefix.'lps_price_logs';
}

/** Старт лога */
function lps_log_start(string $mode='manual'): int {
    global $wpdb;
    $wpdb->insert(lps_logs_table(), [
        'started_at'   => current_time('mysql'),
        'mode'         => $mode,
        'triggered_by' => get_current_user_id() ?: null,
    ], ['%s','%s','%d']);
    return (int)$wpdb->insert_id;
}

/** Финиш лога + опционально CSV путь */
function lps_log_finish(int $log_id, array $data): void {
    global $wpdb;
    $row = [
        'finished_at'   => current_time('mysql'),
        'ok'            => empty($data['ok']) ? 0 : 1,
        'total'         => (int)($data['total'] ?? 0),
        'updated_retail'=> (int)($data['updated_retail'] ?? 0),
        'updated_roles' => (int)($data['updated_roles'] ?? 0),
        'not_found'     => (int)($data['not_found'] ?? 0),
        'csv_path'      => !empty($data['csv_path']) ? (string)$data['csv_path'] : null,
        'sample_json'   => !empty($data['sample']) ? wp_json_encode($data['sample']) : null,
    ];
    $wpdb->update(lps_logs_table(), $row, ['id'=>$log_id],
        ['%s','%d','%d','%d','%d','%d','%s','%s'], ['%d']);
}

function lps_log_progress(int $log_id, array $data): void {
    global $wpdb;
    $row = [
        'total'         => (int)($data['total'] ?? 0),
        'updated_retail'=> (int)($data['updated_retail'] ?? 0),
        'updated_roles' => (int)($data['updated_roles'] ?? 0),
        'not_found'     => (int)($data['not_found'] ?? 0),
        'sample_json'   => !empty($data['sample']) ? wp_json_encode($data['sample']) : null,
    ];

    $wpdb->update(lps_logs_table(), $row, ['id'=>$log_id],
        ['%d','%d','%d','%d','%s'], ['%d']);
}

/** Сохранить CSV с не найденными SKU в uploads и вернуть путь */
function lps_save_not_found_csv(array $skus): ?string {
    if (!$skus) return null;
    $upload = wp_upload_dir();
    if (!empty($upload['error'])) return null;

    $dir = trailingslashit($upload['basedir']).'lps-logs/'.date('Y-m');
    wp_mkdir_p($dir);
    $file = $dir.'/not-found-'.date('Ymd-His').'.csv';

    $fh = fopen($file, 'w');
    if (!$fh) return null;
    // заголовок
    fputcsv($fh, ['sku']);
    foreach ($skus as $sku) fputcsv($fh, [ (string)$sku ]);
    fclose($fh);

    return $file;
}

function lps_render_logs_page(): void {
    if (!current_user_can(LPS_CAP)) {
        return;
    }

    global $wpdb;
    $table = lps_logs_table();
    $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY id DESC LIMIT 100", ARRAY_A);
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__('Price sync logs', 'lavka-price-sync'); ?></h1>

        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php echo esc_html__('ID', 'lavka-price-sync'); ?></th>
                    <th><?php echo esc_html__('Started', 'lavka-price-sync'); ?></th>
                    <th><?php echo esc_html__('Finished', 'lavka-price-sync'); ?></th>
                    <th><?php echo esc_html__('Mode', 'lavka-price-sync'); ?></th>
                    <th><?php echo esc_html__('Status', 'lavka-price-sync'); ?></th>
                    <th><?php echo esc_html__('Total', 'lavka-price-sync'); ?></th>
                    <th><?php echo esc_html__('Retail', 'lavka-price-sync'); ?></th>
                    <th><?php echo esc_html__('Roles', 'lavka-price-sync'); ?></th>
                    <th><?php echo esc_html__('Not found', 'lavka-price-sync'); ?></th>
                    <th><?php echo esc_html__('Progress', 'lavka-price-sync'); ?></th>
                    <th><?php echo esc_html__('CSV', 'lavka-price-sync'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows): ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?php echo (int) $row['id']; ?></td>
                            <td><?php echo esc_html((string) $row['started_at']); ?></td>
                            <td><?php echo esc_html((string) $row['finished_at']); ?></td>
                            <td><?php echo esc_html((string) $row['mode']); ?></td>
                            <td><?php echo !empty($row['ok']) ? esc_html__('OK', 'lavka-price-sync') : esc_html__('Error', 'lavka-price-sync'); ?></td>
                            <td><?php echo (int) $row['total']; ?></td>
                            <td><?php echo (int) $row['updated_retail']; ?></td>
                            <td><?php echo (int) $row['updated_roles']; ?></td>
                            <td><?php echo (int) $row['not_found']; ?></td>
                            <td><?php echo esc_html(lps_log_progress_label($row)); ?></td>
                            <td>
                                <?php if (!empty($row['csv_path'])): ?>
                                    <code><?php echo esc_html(basename((string) $row['csv_path'])); ?></code>
                                <?php else: ?>
                                    &mdash;
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="11"><?php echo esc_html__('No logs yet.', 'lavka-price-sync'); ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

function lps_log_progress_label(array $row): string {
    if (!empty($row['finished_at'])) {
        return __('Finished', 'lavka-price-sync');
    }

    $sample = json_decode((string)($row['sample_json'] ?? ''), true);
    if (!is_array($sample)) {
        return __('Started', 'lavka-price-sync');
    }

    $progress = $sample['progress'] ?? null;
    if (!is_array($progress)) {
        return __('Started', 'lavka-price-sync');
    }

    $page = (int)($progress['page'] ?? 0);
    $pages = (int)($progress['pages'] ?? 0);

    if ($page > 0 && $pages > 0) {
        return sprintf(
            /* translators: 1: processed page number, 2: total pages. */
            __('Page %1$d of %2$d', 'lavka-price-sync'),
            $page,
            $pages
        );
    }

    return __('Started', 'lavka-price-sync');
}
