<?php
// Standalone V14 journal query regression: no WordPress bootstrap or live database.
if (PHP_SAPI !== 'cli') exit;
define('ABSPATH', __DIR__);
define('ARRAY_A', 'ARRAY_A');

function add_action(...$args) {}
function __($value, $domain = '') { return $value; }
function absint($value): int { return abs((int)$value); }
function sanitize_key($value): string { return strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$value)); }
function sanitize_text_field($value): string { return trim((string)$value); }
function sanitize_textarea_field($value): string { return (string)$value; }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }

$wpdb = new class {
    public bool $tableReady = true;
    public array $rows = [];
    public array $prepared = [];
    public string $lastQuery = '';
    public function esc_like($value) { return addcslashes((string)$value, '_%\\'); }
    public function prepare($sql, ...$args) {
        $this->prepared[] = [$sql, $args];
        foreach ($args as $arg) {
            $replacement = is_int($arg) ? (string)$arg : "'" . str_replace("'", "''", (string)$arg) . "'";
            $sql = preg_replace('/%[sd]/', $replacement, $sql, 1);
        }
        return $sql;
    }
    public function get_var($sql) { return $this->tableReady ? 'folio_accounting_price_diagnostic' : null; }
    public function get_results($sql, $output = null) { $this->lastQuery = $sql; return $this->rows; }
};

require __DIR__ . '/../inc/accounting-price-diagnostics.php';

function check($condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$wpdb->tableReady = false;
$missing = lps_accounting_price_diagnostic_query(['sourceDatabase' => 'Paint_Ua', 'warehouseId' => 5]);
check($missing['ok'] && !$missing['available'], 'Absent V14 must degrade gracefully.');

$long = str_repeat('Long diagnostic payload ', 350);
$wpdb->tableReady = true;
$wpdb->rows = [[
    'id' => 91,
    'job_id' => 'job-91',
    'source_database' => 'Paint_Ua',
    'warehouse_id' => 5,
    'sku' => '=DANGEROUS-SKU',
    'preview_only' => 0,
    'error_code' => 'ACCOUNTING_PRICE_DIVIDE_BY_ZERO',
    'message' => 'Divide by zero',
    'diagnostics_json' => json_encode(['stage' => 'FOLIO_PROCEDURE_CALL', 'rollbackConfirmed' => true, 'long' => $long]),
    'created_at' => '2026-09-07 09:00:00',
]];
$report = lps_accounting_price_diagnostic_query([
    'sourceDatabase' => 'Paint_Ua',
    'warehouseId' => 5,
    'sku' => '=DANGEROUS-SKU',
    'jobId' => 'job-91',
    'mode' => 'apply',
    'dateFrom' => '2026-09-01',
    'dateTo' => '2026-09-07',
], 100, 20);
check($report['available'] && count($report['items']) === 1, 'Filtered V14 journal row must be returned.');
check($report['items'][0]['details']['long'] === $long, 'Long diagnostic JSON must remain intact.');
check(str_contains($wpdb->lastQuery, "source_database = 'Paint_Ua'"), 'Database isolation must be present in the prepared query.');
check(str_contains($wpdb->lastQuery, 'warehouse_id = 5'), 'Warehouse isolation must be present in the prepared query.');
check(str_contains($wpdb->lastQuery, "sku = '=DANGEROUS-SKU'"), 'SKU filter must be exact.');
check(str_contains($wpdb->lastQuery, "job_id = 'job-91'"), 'Job filter must be exact.');
check(str_contains($wpdb->lastQuery, 'preview_only = 0'), 'Apply/preview mode must be filterable.');
check(str_contains($wpdb->lastQuery, 'id < 100') && str_contains($wpdb->lastQuery, 'ORDER BY id DESC'), 'Journal pagination must use descending ID keysets.');
check(count($wpdb->prepared) >= 2, 'Table detection and journal query must both be prepared.');
check(lps_accounting_price_diagnostic_csv_value('=DANGEROUS-SKU') === "'=DANGEROUS-SKU", 'CSV formulas must be neutralized.');

$negative_export = lps_accounting_price_diagnostic_export_values([
    'id' => 92, 'createdAt' => '2026-09-07 14:22:00', 'sourceDatabase' => 'Paint_Ua',
    'warehouseId' => 5, 'sku' => 'ТП-0001', 'previewOnly' => true, 'jobId' => 'negative-job',
    'errorCode' => 'NEGATIVE_CHRONOLOGICAL_STOCK', 'message' => 'negative',
    'details' => [
        'stage' => 'JAVA_CHRONOLOGY_PREFLIGHT', 'initialQuantity' => 0,
        'quantityBefore' => 27, 'quantityAfter' => -23, 'shortageQuantity' => 23,
        'movementPosition' => 230, 'movementCount' => 234,
        'triggerMovementConfirmed' => true, 'businessRootCauseConfirmed' => false,
        'operation' => ['documentType' => 'Р', 'documentNumber' => 62, 'documentId' => 754,
            'documentDate' => '2026-07-23T00:00:00', 'recno' => 899, 'kind' => 'EXPENSE', 'quantity' => 50],
        'currentState' => ['physicalQuantity' => 102, 'availableQuantity' => 102,
            'accountingQuantity' => 202, 'accountingPrice' => 1.5],
    ],
]);
check($negative_export['Document No.'] === 62 && $negative_export['Shortage'] === 23,
    'Diagnostic exports must expose document and shortage as dedicated columns.');
check($negative_export['Initial quantity'] === 0 && $negative_export['Business root cause confirmed'] === 'No',
    'Diagnostic exports must preserve zero and explicit false values.');

$wpdb->rows = [];
for ($id = 120; $id >= 100; $id--) {
    $wpdb->rows[] = [
        'id' => $id,
        'job_id' => $id === 120 ? 'new-run' : 'older-run',
        'source_database' => 'Paint_Ua',
        'warehouse_id' => 5,
        'sku' => 'SKU-' . $id,
        'preview_only' => 0,
        'error_code' => 'ACCOUNTING_PRICE_DIVIDE_BY_ZERO',
        'message' => 'diagnostic',
        'diagnostics_json' => '{}',
        'created_at' => '2026-09-07 09:00:00',
    ];
}
$page = lps_accounting_price_diagnostic_query(['sourceDatabase' => 'Paint_Ua', 'warehouseId' => 5], 0, 20);
check($page['hasMore'] && count($page['items']) === 20 && $page['nextBeforeId'] === 101, 'A full page must expose the descending ID cursor.');
check($page['items'][0]['jobId'] === 'new-run' && $page['items'][1]['jobId'] === 'older-run', 'A new run must not hide older persistent history.');

echo "PASS: V14 absence, prepared isolation filters, keyset paging, long JSON and CSV safety\n";
