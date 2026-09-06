<?php
// Pure global-settings fixtures; no WordPress DB or Java calls.
if (PHP_SAPI !== 'cli') exit;
define('ABSPATH', __DIR__);
function add_action(...$args) {}
function add_filter(...$args) {}
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
require __DIR__ . '/../../lavka-sync/inc/warehouse-map.php';
function check($value, $message) { if (!$value) throw new RuntimeException($message); }
check(lavka_get_transit_warehouse_ids() === [9], 'Missing option preserves existing source');
$options[LAVKA_TRANSIT_WAREHOUSES_OPTION] = [];
check(lavka_get_transit_warehouse_ids() === [], 'Explicit empty selection must not revert to warehouse 9');
$options[LAVKA_TRANSIT_WAREHOUSES_OPTION] = ['10', '9', 10, '-1', 0, 1.5, 'D09', 'foo', [], true, 999999999999];
check(lavka_get_transit_warehouse_ids() === [9, 10], 'Normalize stable positive IDs and deduplicate deterministically');
echo "PASS: transport setting default, explicit disable, stable IDs and validation\n";
