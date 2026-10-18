<?php
/** CLI-process-only constants. Never include from WordPress or deploy configuration. */
$fixture = 'wp-content/plugins/paint-nova-poshta-multishipping/tests/shipment-lifecycle-local.php';
if (PHP_SAPI !== 'cli' || !in_array('eval-file', $_SERVER['argv'], true) || !in_array($fixture, $_SERVER['argv'], true)
    || realpath(getcwd()) !== realpath(dirname(__DIR__, 4)) || !is_file(getcwd().'/wp-config.local.php')) {
    throw new RuntimeException('Only the named local intercepted fixture may load this bootstrap');
}
define('PNPM_ALLOW_REAL_TTN', true);
define('PNPM_NOVA_POSHTA_API_KEY', 'synthetic-test-key');
// Also block transports during WordPress bootstrap, before the fixture installs its responses.
$GLOBALS['wp_filter']['pre_http_request'][PHP_INT_MAX][] = ['function'=>static fn()=>new WP_Error('fixture_boot','Fixture bootstrap blocks HTTP'),'accepted_args'=>3];
$GLOBALS['wp_filter']['pre_wp_mail'][PHP_INT_MAX][] = ['function'=>static fn()=>false,'accepted_args'=>2];
