<?php
if (PHP_SAPI !== 'cli') exit;
// Run with wp --skip-plugins --skip-themes eval-file .../tests/local-locks.php.
// Read-only except connection-scoped advisory locks; never accesses payment APIs.
if (get_option('siteurl') !== 'http://paint.local') {
    throw new RuntimeException('Local verification only.');
}
require_once __DIR__ . '/../src/Store.php';
require_once __DIR__ . '/../../pc-checkbox-fiscalization/src/Infrastructure/OperationRepository.php';
$first = $GLOBALS['wpdb'];
$second = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
$second->set_prefix($first->prefix);
$pairs = [
    [new Paint\WayForPayCheckbox\Store(), 987654321],
    [new Paint\CheckboxFiscalization\Infrastructure\OperationRepository(), 'offline-lock-test'],
];
$checks = 0;
try {
    foreach ($pairs as [$repository, $key]) {
        $GLOBALS['wpdb'] = $first;
        if (!$repository->lock($key)) throw new RuntimeException('First lock failed');
        $GLOBALS['wpdb'] = $second;
        if ($repository->lock($key)) throw new RuntimeException('Concurrent lock incorrectly succeeded');
        $GLOBALS['wpdb'] = $first;
        $repository->unlock($key);
        $GLOBALS['wpdb'] = $second;
        if (!$repository->lock($key)) throw new RuntimeException('Released lock not available');
        $repository->unlock($key);
        $checks++;
    }
    $GLOBALS['wpdb'] = $first;
    $table = $first->prefix . 'pc_wfp_receipts';
    if ($first->get_var($first->prepare('SHOW TABLES LIKE %s', $first->esc_like($table))) !== $table) {
        throw new RuntimeException('Bridge table missing');
    }
    echo "Verified $checks two-connection database locks and installed bridge table. No fiscal calls.\n";
} finally {
    foreach ($pairs as [$repository, $key]) {
        $GLOBALS['wpdb'] = $first; $repository->unlock($key);
        $GLOBALS['wpdb'] = $second; $repository->unlock($key);
    }
    $second->close();
    $GLOBALS['wpdb'] = $first;
}
