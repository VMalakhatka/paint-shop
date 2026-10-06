<?php
// Offline selection contract: no WordPress bootstrap or live database.
define('ABSPATH', __DIR__);
function add_action(...$args) {}
require __DIR__ . '/../inc/accounting-price-campaign.php';
$wpdb = new class {
    public string $sql = '';
    public array $args = [];
    function prepare($sql, ...$args) { $this->sql = $sql; $this->args = $args; return $sql; }
    function get_col($sql) { return ['SKU-FAILED']; }
};
function check($ok, $message) { if (!$ok) throw new RuntimeException($message); }
foreach ([true, false] as $initial) {
    foreach (['', 'SKU-PREVIOUS'] as $cursor) {
        $result = lps_accounting_price_campaign_select_skus('fixture', 1, $cursor, 50, $initial);
        check($result === ['SKU-FAILED'], 'FAILED selection must be returned');
        check(str_contains($wpdb->sql, "'NEW','DIRTY','FAILED'"), 'Every campaign includes FAILED');
        check(str_contains($wpdb->sql, "'UNVERIFIED'") === $initial, 'Preserve initial-only UNVERIFIED mode');
        check(!str_contains($wpdb->sql, "'VERIFIED'") && !str_contains($wpdb->sql, "'REMOVED'"), 'Verified and absent products remain excluded');
        check(str_contains($wpdb->sql, 'present_in_folio = 1'), 'Only present products are retried');
        check(str_contains($wpdb->sql, 'sku > %s') === ($cursor !== ''), 'Keep forward-only batch cursor');
        check($wpdb->args === array_merge(['fixture', 1], $cursor !== '' ? [$cursor] : [], [50]), 'Preserve bound scope and limit');
    }
}
echo "PASS: FAILED in initial/regular runs, presence, cursor, scope and limits\n";
