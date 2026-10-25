<?php
/** Process-only fixture: fake key, all network and mail intercepted even during WP bootstrap. */
$fixture='wp-content/plugins/paint-nova-poshta-multishipping/tests/registry-local.php';
if (PHP_SAPI!=='cli' || !in_array('eval-file',$_SERVER['argv'],true) || !in_array($fixture,$_SERVER['argv'],true)
    || realpath(getcwd())!==realpath(dirname(__DIR__,4)) || !is_file(getcwd().'/wp-config.local.php')) throw new RuntimeException('Local registry fixture only');
define('PNPM_NOVA_POSHTA_API_KEY','synthetic-registry-test-key');
$GLOBALS['wp_filter']['pre_http_request'][PHP_INT_MAX][]=['function'=>static fn()=>new WP_Error('blocked','HTTP blocked'),'accepted_args'=>3];
$GLOBALS['wp_filter']['pre_wp_mail'][PHP_INT_MAX][]=['function'=>static fn()=>false,'accepted_args'=>2];
