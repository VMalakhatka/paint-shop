<?php
/** Regression: a no-argument WordPress action supplies an empty placeholder. */
if (!defined('WP_CLI') || !WP_CLI || wp_parse_url(home_url(), PHP_URL_HOST) !== 'paint.local') {
    throw new RuntimeException('Run only with WP-CLI on paint.local.');
}
add_filter('pre_http_request', static fn() => new WP_Error('test_http_blocked', 'External requests disabled in this test'), PHP_INT_MAX);
$registration = $GLOBALS['wp_filter']['wp_enqueue_scripts']->callbacks[10]['pc_folio_documents_enqueue_assets'] ?? null;
if (!$registration) throw new RuntimeException('Document assets hook missing');
$hook = new WP_Hook();
$hook->add_filter('pcoe_frontend_regression', $registration['function'], 10, $registration['accepted_args']);
$hook->do_action(['']);

$script = file_get_contents(WP_CONTENT_DIR . '/mu-plugins/pc-folio-customer-balance/assets/customer-documents.js');
if (!is_string($script)
    || strpos($script, 'var managerCustomerId = Number.parseInt(pcFolioDocuments.managerCustomerId, 10);') === false
    || strpos($script, "body.set('action', managerMode ? 'pcoe_manager' : action);") === false
    || strpos($script, 'pcFolioDocuments.managerCustomerId ?') !== false) {
    throw new RuntimeException('Customer document requests must not treat the localized string "0" as manager mode.');
}
WP_CLI::success('Frontend document assets tolerate the WordPress empty action argument.');
