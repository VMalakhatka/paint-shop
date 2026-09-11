<?php
if (!defined('ABSPATH')) exit;

const LPS_PURCHASE_PAGE = 'lps-purchase-planning';
const LPS_PURCHASE_NONCE = 'lps_purchase_planning';
const LPS_PURCHASE_MAX_SKUS = 10000;

function lps_purchase_i18n(): array {
    return [
        'supplierPrices' => __('Supplier price list information', 'lavka-price-sync'),
        'supplierPriceLabels' => function_exists('lps_sp_labels') ? lps_sp_labels() : [],
        'title' => __('Supplier order preview', 'lavka-price-sync'),
        'receivingWarehouse' => __('Receiving warehouse', 'lavka-price-sync'),
        'leadTimeDays' => __('Lead time, days', 'lavka-price-sync'),
        'targetDays' => __('Stock after arrival, days', 'lavka-price-sync'),
        'safetyDays' => __('Safety stock, days', 'lavka-price-sync'),
        'loading' => __('Building purchase preview...', 'lavka-price-sync'),
        'loaded' => __('SKU loaded', 'lavka-price-sync'),
        'complete' => __('Preview complete. No orders or transfers were created.', 'lavka-price-sync'),
        'incomplete' => __('Preview incomplete. Export is unavailable. Start a new calculation.', 'lavka-price-sync'),
        'error' => __('Purchase preview could not be calculated.', 'lavka-price-sync'),
        'review' => __('Review required', 'lavka-price-sync'),
        'ready' => __('Preview ready', 'lavka-price-sync'),
        'group' => __('Combined warehouse', 'lavka-price-sync'),
        'sku' => __('SKU', 'lavka-price-sync'), 'product' => __('Product', 'lavka-price-sync'),
        'available' => __('Available quantity', 'lavka-price-sync'),
        'physical' => __('Physical quantity', 'lavka-price-sync'),
        'sales' => __('Regular sales quantity', 'lavka-price-sync'),
        'returns' => __('Returns', 'lavka-price-sync'),
        'coverage' => __('Stock coverage, days', 'lavka-price-sync'),
        'target' => __('Target stock', 'lavka-price-sync'),
        'need' => __('Need before expected receipts', 'lavka-price-sync'),
        'transfer' => __('Suggested transfers between groups', 'lavka-price-sync'),
        'purchase' => __('Suggested purchase quantity', 'lavka-price-sync'),
        'quantity' => __('Manager quantity', 'lavka-price-sync'),
        'final' => __('Preview quantity after review', 'lavka-price-sync'),
        'reason' => __('Reason for adjustment', 'lavka-price-sync'),
        'details' => __('Supply inputs and review', 'lavka-price-sync'),
        'inTransit' => __('Confirmed transit allocated to this group', 'lavka-price-sync'),
        'openOrders' => __('Other confirmed incoming orders, excluding transit', 'lavka-price-sync'),
        'respectPack' => __('Respect pack quantity', 'lavka-price-sync'),
        'packHelp' => __('Without pack rounding, the supplier minimum order still applies. Pack discounts are not calculated.', 'lavka-price-sync'),
        'pack' => __('Supplier pack quantity', 'lavka-price-sync'),
        'moq' => __('Supplier minimum order quantity', 'lavka-price-sync'),
        'transitPool' => __('Transport warehouse stock available to network planning', 'lavka-price-sync'),
        'supplierTransit' => __('Stock in transit from supplier', 'lavka-price-sync'),
        'networkConsistency' => __('Combined network snapshot', 'lavka-price-sync'),
        'networkRecommendation' => __('Backend recommendation', 'lavka-price-sync'),
        'transitWarehouses' => __('Transport warehouses', 'lavka-price-sync'),
        'transitStatus' => __('Transit data status', 'lavka-price-sync'),
        'receiptsReviewed' => __('I checked receipt dates and destinations, and excluded transit quantities from other incoming orders.', 'lavka-price-sync'),
        'transitLabels' => (function_exists('lps_product_analytics_i18n') ? lps_product_analytics_i18n()['transitLabels'] : []) + [
            'TRANSIT_DISABLED' => __('Disabled', 'lavka-price-sync'),
            'TRANSIT_SOURCE_MISMATCH' => __('Review required', 'lavka-price-sync'),
        ],
        'apply' => __('Recalculate this SKU preview', 'lavka-price-sync'),
        'previous' => __('Previous', 'lavka-price-sync'), 'next' => __('Next', 'lavka-price-sync'),
        'issues' => [
            'INCOMPLETE_WAREHOUSE_DATA' => __('Warehouse metrics or stock policy are incomplete. Missing data is not zero stock.', 'lavka-price-sync'),
            'DESTINATION_POLICY_BLOCKED' => __('The receiving warehouse stock policy does not allow this purchase.', 'lavka-price-sync'),
            'NETWORK_POLICY_NOT_CONFIRMED' => __('Network purchase permission is missing or blocked.', 'lavka-price-sync'),
            'PROFIT_REVIEW_REQUIRED' => __('Profit is negative or unknown. A purchase recommendation requires review.', 'lavka-price-sync'),
            'SUPPLY_INPUTS_REQUIRED' => __('Confirm transit, other incoming orders, supplier pack and minimum order quantity. Enter zero only when confirmed.', 'lavka-price-sync'),
            'TRANSIT_OVERALLOCATED' => __('The same transit stock was allocated more than once: group allocations exceed the confirmed total.', 'lavka-price-sync'),
            'TRANSIT_SOURCE_MISMATCH' => __('Java returned a different transport warehouse set. Update the backend for the transport warehouses selected in Lavka settings.', 'lavka-price-sync'),
            'TRANSIT_NOT_CONFIRMED' => __('Transit stock or its supplier origin is not confirmed. Manual input cannot replace missing source data.', 'lavka-price-sync'),
            'NETWORK_TRANSIT_NOT_READY' => __('Transport warehouse stock is visible, but the combined network snapshot is not confirmed. The purchase recommendation is blocked.', 'lavka-price-sync'),
            'TRANSIT_CONTRACT_OUTDATED' => __('The Java transit calculation must be updated to version 3.', 'lavka-price-sync'),
            'TRANSIT_DESTINATION_OVERLAP' => __('A transport warehouse is also a purchase destination group member. Remove the overlap to avoid counting stock twice.', 'lavka-price-sync'),
            'RECEIPTS_REVIEW_REQUIRED' => __('Confirm that transit and other incoming orders do not contain the same quantities, and check arrival dates and destinations.', 'lavka-price-sync'),
            'DESTINATION_MAXIMUM_EXCEEDED' => __('The resulting receipt exceeds the receiving warehouse stock limit.', 'lavka-price-sync'),
            'PACK_OR_MOQ_VIOLATION' => __('The quantity does not respect the supplier pack or minimum order.', 'lavka-price-sync'),
            'MANAGER_REASON_REQUIRED' => __('Enter a reason for the manager adjustment.', 'lavka-price-sync'),
            'SUPPLIER_NOT_CONFIRMED' => __('A single current supplier must be confirmed for this SKU.', 'lavka-price-sync'),
        ],
    ];
}

function lps_purchase_scenario_fields(): void {
    ?>
    <details class="lps-as-section" open>
        <summary><h2><?php echo esc_html__('Purchase planning', 'lavka-price-sync'); ?></h2></summary>
        <div class="lps-as-purchase-body">
            <p><label><input type="checkbox" id="lps-as-purchase-enabled"> <?php echo esc_html__('Enable supplier order preview', 'lavka-price-sync'); ?></label></p>
            <p><label><input type="checkbox" id="lps-as-purchase-transfers"> <?php echo esc_html__('Suggest transfers from surplus destination groups first', 'lavka-price-sync'); ?></label></p>
            <p><label><input type="checkbox" id="lps-as-purchase-pack" checked> <?php echo esc_html__('Respect pack quantity', 'lavka-price-sync'); ?></label></p>
            <p class="description"><?php echo esc_html__('Without pack rounding, the supplier minimum order still applies. Pack discounts are not calculated.', 'lavka-price-sync'); ?></p>
            <div id="lps-as-purchase-groups"></div>
            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=' . LPS_PURCHASE_PAGE)); ?>"><?php echo esc_html__('Open supplier order preview', 'lavka-price-sync'); ?></a>
        </div>
    </details>
    <?php
}

function lps_purchase_session_key(string $token): string {
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) throw new InvalidArgumentException(__('The preview has expired. Start a new calculation.', 'lavka-price-sync'));
    return 'lps_purchase_' . get_current_user_id() . '_' . $token;
}

function lps_purchase_session(string $token): array {
    $state = get_transient(lps_purchase_session_key($token));
    if (!is_array($state)) throw new InvalidArgumentException(__('The preview has expired. Start a new calculation.', 'lavka-price-sync'));
    if (($state['previewVersion'] ?? 0) !== 2) throw new InvalidArgumentException(__('Start a new preview with corrected free stock snapshots (schema 6).', 'lavka-price-sync'));
    if (($state['transitContractVersion'] ?? 0) !== 3 || ($state['transitWarehouseIds'] ?? null) !== lps_purchase_transit_warehouses()) {
        throw new InvalidArgumentException(__('Transport warehouse settings changed. Start a new preview.', 'lavka-price-sync'));
    }
    $row = lps_analytics_scenario_row((int)$state['scenario']['id']);
    if (!$row || (int)$row['version'] !== (int)$state['scenario']['version'] || $row['status'] !== 'active'
        || $state['groupsRevision'] !== lavka_get_global_warehouse_groups_revision()) {
        throw new InvalidArgumentException(__('The scenario or warehouse groups changed. Start a new preview.', 'lavka-price-sync'));
    }
    return $state;
}

function lps_purchase_start(int $id, int $version): array {
    $row = lps_analytics_scenario_row($id);
    if (!$row) throw new InvalidArgumentException(__('The selected analytics scenario was not found or is not available to you.', 'lavka-price-sync'));
    $scenario = lps_analytics_scenario_decode_row($row);
    if ($scenario['version'] !== $version || $scenario['status'] !== 'active' || $scenario['schemaVersion'] !== 4) {
        throw new InvalidArgumentException(__('The scenario changed or is not active. Reload the page.', 'lavka-price-sync'));
    }
    $profile = $scenario['profile'];
    if (empty($profile['calculation']['includeReturns'])) throw new InvalidArgumentException(__('Enable returns in the analytics scenario before building a purchase preview.', 'lavka-price-sync'));
    $groups = lps_purchase_resolve_groups($profile, function_exists('lavka_get_global_warehouse_groups') ? lavka_get_global_warehouse_groups() : []);
    $transit_ids = lps_purchase_transit_warehouses();
    if (array_intersect($profile['context']['warehouseIds'], $transit_ids)) {
        throw new InvalidArgumentException(lps_purchase_i18n()['issues']['TRANSIT_DESTINATION_OVERLAP']);
    }
    foreach ($groups as $group) {
        if (array_intersect($group['warehouseIds'], $transit_ids)) {
            throw new InvalidArgumentException(lps_purchase_i18n()['issues']['TRANSIT_DESTINATION_OVERLAP']);
        }
    }
    $days = lps_purchase_period_days($profile['period']);
    $query = lps_product_analytics_v4_sanitize_query([
        'sourceDatabase' => $profile['context']['sourceDatabase'], 'warehouseIds' => $profile['context']['warehouseIds'],
        'period' => $profile['period'], 'productFilters' => $profile['productFilters'], 'movementFilters' => $profile['movementFilters'],
        'calculation' => $profile['calculation'], 'sort' => [['field' => 'sku', 'direction' => 'ASC']], 'page' => ['size' => 100],
    ]);
    $token = bin2hex(random_bytes(16));
    $state = ['previewVersion' => 2, 'scenario' => ['id' => $scenario['id'], 'uuid' => $scenario['uuid'], 'name' => $scenario['name'], 'version' => $scenario['version']],
        'supplierPriceVersions' => function_exists('lps_sp_active_versions') ? lps_sp_active_versions($profile['context']['sourceDatabase']) : [],
        'query' => $query, 'groups' => $groups, 'groupsRevision' => lavka_get_global_warehouse_groups_revision(),
        'transitWarehouseIds' => $transit_ids, 'transitGenerationId' => null, 'transitContractVersion' => 3,
        'periodDays' => $days, 'allowTransfers' => $profile['purchasePlanning']['allowTransfers'],
        'rows' => [], 'edits' => [], 'page' => 0, 'cursor' => null, 'seenCursors' => [], 'complete' => false,
        'context' => null, 'createdAt' => wp_date('Y-m-d H:i:s')];
    set_transient(lps_purchase_session_key($token), $state, 2 * HOUR_IN_SECONDS);
    return ['token' => $token, 'scenario' => $state['scenario'], 'groups' => $groups, 'query' => $query, 'groupsRevision' => $state['groupsRevision'],
        'transitWarehouseIds' => $transit_ids];
}

function lps_purchase_page(string $token, int $page): array {
    $state = lps_purchase_session($token);
    if ($state['complete'] || $state['page'] !== $page) throw new InvalidArgumentException(__('The preview page changed. Start a new calculation.', 'lavka-price-sync'));
    $query = $state['query'];
    $query['page']['cursor'] = $state['cursor'];
    $body = lps_product_analytics_v4_request_java(LPS_PRODUCT_ANALYTICS_QUERY_PATH, $query);
    if (is_wp_error($body)) throw new RuntimeException($body->get_error_message());
    if (!isset($body['rows'], $body['context'], $body['totals']['productCount']) || !is_array($body['rows']) || !empty($body['errors'])) {
        throw new RuntimeException(__('The analytics response is incomplete. No purchase preview was accepted.', 'lavka-price-sync'));
    }
    $context = $body['context'];
    $ids = array_map('intval', array_column((array)($context['warehouses'] ?? []), 'id'));
    $expected_ids = $query['warehouseIds'];
    sort($ids); sort($expected_ids);
    $generations = array_column((array)($context['warehouses'] ?? []), 'generationId');
    if (($context['analyticsSchemaVersion'] ?? 0) < 6) {
        throw new RuntimeException(__('Start a new preview with corrected free stock snapshots (schema 6).', 'lavka-price-sync'));
    }
    if ($ids !== $expected_ids
        || count($generations) !== count($expected_ids) || count(array_filter($generations, static fn($id) => (int)$id > 0)) !== count($expected_ids)
        || ($context['periodFrom'] ?? '') !== $query['period']['from'] || ($context['periodTo'] ?? '') !== $query['period']['to']) {
        throw new RuntimeException(__('The analytics response is incomplete. No purchase preview was accepted.', 'lavka-price-sync'));
    }
    if ($state['context'] !== null && wp_json_encode($state['context']) !== wp_json_encode($body['context'])) {
        throw new RuntimeException(__('Snapshot generations changed while loading. Start a new preview.', 'lavka-price-sync'));
    }
    $state['context'] = $body['context'];
    if ((int)$body['totals']['productCount'] > LPS_PURCHASE_MAX_SKUS) throw new RuntimeException(__('The preview exceeds 10000 SKU. Narrow the scenario filters.', 'lavka-price-sync'));
    $results = [];
    foreach ($body['rows'] as $row) {
        $sku = (string)($row['sku'] ?? '');
        if ($sku === '' || isset($state['rows'][$sku])) throw new RuntimeException(__('The analytics response contains missing or repeated SKU.', 'lavka-price-sync'));
        $row['supplierPrices'] = function_exists('lps_sp_product') ? lps_sp_product($sku, (array)($row['dimensions']['currentSuppliers'] ?? []), $state['supplierPriceVersions'] ?? []) : [];
        $state['rows'][$sku] = $row;
        $result = lps_purchase_calculate($row, $state['groups'], $state['periodDays'], $state['allowTransfers'], [], $state['transitWarehouseIds']);
        if ($state['transitWarehouseIds'] && $result['transitPool'] !== null) {
            $generation = array_column($result['transitSources'], 'generationId', 'warehouseId');
            ksort($generation, SORT_NUMERIC);
            if ($state['transitGenerationId'] !== null && $state['transitGenerationId'] !== $generation) {
                throw new RuntimeException(__('Snapshot generations changed while loading. Start a new preview.', 'lavka-price-sync'));
            }
            $state['transitGenerationId'] = $generation;
        }
        $results[] = $result;
    }
    $next = (string)($body['nextCursor'] ?? '');
    if ($next !== '' && (isset($state['seenCursors'][$next]) || !$body['rows'])) throw new RuntimeException(__('The analytics service returned a repeated page cursor.', 'lavka-price-sync'));
    if (count($state['rows']) > LPS_PURCHASE_MAX_SKUS) throw new RuntimeException(__('The preview exceeds 10000 SKU. Narrow the scenario filters.', 'lavka-price-sync'));
    $state['seenCursors'][$next] = true;
    $state['cursor'] = $next;
    $state['complete'] = $next === '';
    if ($state['complete'] && count($state['rows']) !== (int)$body['totals']['productCount']) throw new RuntimeException(__('The analytics response is incomplete. No purchase preview was accepted.', 'lavka-price-sync'));
    $state['page']++;
    set_transient(lps_purchase_session_key($token), $state, 2 * HOUR_IN_SECONDS);
    return ['items' => $results, 'loaded' => count($state['rows']), 'total' => (int)$body['totals']['productCount'],
        'page' => $state['page'], 'complete' => $state['complete'], 'warnings' => $body['warnings'] ?? [], 'context' => $state['context']];
}

function lps_purchase_adjust(string $token, string $sku, array $input): array {
    $state = lps_purchase_session($token);
    if (!$state['complete'] || !isset($state['rows'][$sku])) throw new InvalidArgumentException(__('Complete the preview before adjusting quantities.', 'lavka-price-sync'));
    $edits = [];
    foreach ($state['groups'] as $group) {
        $source = is_array($input[$group['code']] ?? null) ? $input[$group['code']] : [];
        foreach (['inTransit', 'openOrders', 'pack', 'moq', 'quantity'] as $field) {
            $value = $source[$field] ?? null;
            $number = lps_purchase_number($value, $field === 'pack' ? 0.000001 : 0);
            if ($value !== null && $value !== '' && $number === null) throw new InvalidArgumentException(__('Enter valid non-negative quantities and a positive pack quantity.', 'lavka-price-sync'));
            $edits[$group['code']][$field] = $number;
        }
        $edits[$group['code']]['respectPack'] = ($source['respectPack'] ?? $group['respectPack'] ?? true) !== false;
        $edits[$group['code']]['reason'] = sanitize_text_field((string)($source['reason'] ?? ''));
        $edits[$group['code']]['receiptsReviewed'] = ($source['receiptsReviewed'] ?? false) === true;
    }
    $state['edits'][$sku] = $edits;
    $result = lps_purchase_calculate($state['rows'][$sku], $state['groups'], $state['periodDays'], $state['allowTransfers'], $edits, $state['transitWarehouseIds']);
    set_transient(lps_purchase_session_key($token), $state, 2 * HOUR_IN_SECONDS);
    return ['item' => $result];
}

add_action('wp_ajax_lps_purchase_planning', static function (): void {
    if (!current_user_can(LPS_CAP)) wp_send_json_error(['message' => __('Access denied.', 'lavka-price-sync')], 403);
    check_ajax_referer(LPS_PURCHASE_NONCE);
    $input = json_decode((string)wp_unslash($_POST['payload'] ?? '{}'), true);
    if (!is_array($input)) wp_send_json_error(['message' => __('Invalid request.', 'lavka-price-sync')], 400);
    try {
        switch (sanitize_key(wp_unslash($_POST['operation'] ?? ''))) {
            case 'start': $result = lps_purchase_start(absint($input['scenarioId'] ?? 0), absint($input['version'] ?? 0)); break;
            case 'page': $result = lps_purchase_page((string)($input['token'] ?? ''), absint($input['page'] ?? 0)); break;
            case 'adjust': $result = lps_purchase_adjust((string)($input['token'] ?? ''), (string)($input['sku'] ?? ''), (array)($input['groups'] ?? [])); break;
            default: throw new InvalidArgumentException(__('Invalid request.', 'lavka-price-sync'));
        }
        wp_send_json_success($result);
    } catch (InvalidArgumentException $error) {
        wp_send_json_error(['message' => $error->getMessage()], 409);
    } catch (RuntimeException $error) {
        wp_send_json_error(['message' => $error->getMessage()], 502);
    }
});

add_action('admin_menu', static function (): void {
    add_submenu_page(function_exists('paint_core_lavka_admin_parent_slug') ? paint_core_lavka_admin_parent_slug() : 'lps-main',
        __('Supplier order preview', 'lavka-price-sync'), __('Supplier order preview', 'lavka-price-sync'), LPS_CAP, LPS_PURCHASE_PAGE, 'lps_purchase_render');
}, 20);

add_action('admin_enqueue_scripts', static function (): void {
    if (sanitize_key(wp_unslash($_GET['page'] ?? '')) !== LPS_PURCHASE_PAGE) return;
    $base = dirname(__DIR__);
    wp_enqueue_style('lps-purchase-planning', plugins_url('assets/purchase-planning.css', $base . '/lavka-price-sync.php'), [], filemtime($base . '/assets/purchase-planning.css'));
    wp_enqueue_script('lps-purchase-planning', plugins_url('assets/purchase-planning.js', $base . '/lavka-price-sync.php'), [], filemtime($base . '/assets/purchase-planning.js'), true);
    wp_localize_script('lps-purchase-planning', 'LPS_PURCHASE', ['ajaxUrl' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce(LPS_PURCHASE_NONCE),
        'exportUrl' => admin_url('admin-post.php'), 'locale' => str_replace('_', '-', determine_locale()), 'i18n' => lps_purchase_i18n()]);
});

function lps_purchase_render(): void {
    if (!current_user_can(LPS_CAP)) return;
    ?>
    <div class="wrap lps-purchase" id="lps-purchase">
        <h1><?php echo esc_html__('Supplier order preview', 'lavka-price-sync'); ?></h1>
        <p class="notice notice-info inline"><?php echo esc_html__('Preview only. Demand uses regular observed sales; returns are shown separately. Lost sales during stockouts are not estimated. No Folio documents, WooCommerce orders or stock reservations are created.', 'lavka-price-sync'); ?></p>
        <form id="lps-purchase-form" class="lps-purchase-toolbar">
            <label><?php echo esc_html__('Analytics scenario', 'lavka-price-sync'); ?> <select id="lps-purchase-scenario" required><option value="">—</option>
                <?php foreach (lps_analytics_scenarios_list() as $scenario): if (empty($scenario['profile']['purchasePlanning']['enabled']) || $scenario['status'] !== 'active') continue; ?>
                    <option value="<?php echo esc_attr($scenario['id']); ?>" data-version="<?php echo esc_attr($scenario['version']); ?>"><?php echo esc_html($scenario['name'] . ' · v' . $scenario['version']); ?></option>
                <?php endforeach; ?>
            </select></label>
            <button class="button button-primary" type="submit" id="lps-purchase-start"><?php echo esc_html__('Calculate preview', 'lavka-price-sync'); ?></button>
            <button class="button" type="button" id="lps-purchase-cancel" disabled><?php echo esc_html__('Cancel', 'lavka-price-sync'); ?></button>
            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=' . LPS_ANALYTICS_SCENARIOS_PAGE)); ?>"><?php echo esc_html__('Manage scenarios', 'lavka-price-sync'); ?></a>
            <span class="spinner" id="lps-purchase-spinner"></span>
        </form>
        <div id="lps-purchase-message" role="status" aria-live="polite"></div>
        <div id="lps-purchase-context"></div>
        <details><summary><?php echo esc_html__('Report parameters', 'lavka-price-sync'); ?></summary><pre id="lps-purchase-parameters"></pre></details>
        <details id="lps-purchase-warnings" hidden><summary><?php echo esc_html__('Source warnings', 'lavka-price-sync'); ?></summary><pre></pre></details>
        <div class="lps-purchase-toolbar">
            <button class="button" type="button" data-export="csv" disabled><?php echo esc_html__('Export CSV', 'lavka-price-sync'); ?></button>
            <button class="button" type="button" data-export="xlsx" disabled><?php echo esc_html__('Export XLSX', 'lavka-price-sync'); ?></button>
            <button class="button" id="lps-purchase-prev" disabled type="button"><?php echo esc_html__('Previous', 'lavka-price-sync'); ?></button>
            <span id="lps-purchase-page"></span>
            <button class="button" id="lps-purchase-next" disabled type="button"><?php echo esc_html__('Next', 'lavka-price-sync'); ?></button>
        </div>
        <div id="lps-purchase-results"></div>
    </div>
    <?php
}

add_action('admin_post_lps_purchase_export', static function (): void {
    if (!current_user_can(LPS_CAP)) wp_die(esc_html__('Access denied.', 'lavka-price-sync'));
    check_admin_referer(LPS_PURCHASE_NONCE);
    try {
        $state = lps_purchase_session((string)wp_unslash($_POST['token'] ?? ''));
        if (!$state['complete']) throw new InvalidArgumentException(__('Complete the preview before exporting.', 'lavka-price-sync'));
        $t = lps_purchase_i18n();
        $keys = ['sku', 'product', 'group', 'receivingWarehouse', 'available', 'sales', 'returns', 'coverage', 'target', 'need', 'transfer', 'inTransit', 'openOrders', 'pack', 'respectPack', 'moq', 'purchase', 'quantity', 'final', 'reason'];
        $columns = array_map(static fn($key) => ['key' => $key, 'label' => $t[$key]], $keys);
        $columns[] = ['key' => 'supplierPrices', 'label' => $t['supplierPrices']];
        $columns[] = ['key' => 'status', 'label' => __('Status', 'lavka-price-sync')];
        $columns[] = ['key' => 'transitWarehouses', 'label' => $t['transitWarehouses']];
        $columns[] = ['key' => 'transitStatus', 'label' => $t['transitStatus']];
        $columns[] = ['key' => 'transitPool', 'label' => $t['transitPool']];
        $columns[] = ['key' => 'receiptsReviewed', 'label' => $t['receiptsReviewed']];
        $columns[] = ['key' => 'transitSource', 'label' => __('Transit source snapshot (JSON)', 'lavka-price-sync')];
        foreach (['supplier' => __('Current supplier', 'lavka-price-sync'), 'scenario' => __('Analytics scenario', 'lavka-price-sync'),
            'period' => __('Report period', 'lavka-price-sync'), 'leadTimeDays' => $t['leadTimeDays'], 'targetDays' => $t['targetDays'],
            'safetyDays' => $t['safetyDays'], 'groupsRevision' => __('Warehouse group revision', 'lavka-price-sync')] as $key => $label) {
            $columns[] = ['key' => $key, 'label' => $label];
        }
        $rows = [];
        foreach ($state['rows'] as $sku => $raw) {
            $calculated = lps_purchase_calculate($raw, $state['groups'], $state['periodDays'], $state['allowTransfers'], $state['edits'][$sku] ?? [], $state['transitWarehouseIds']);
            foreach ($calculated['groups'] as $group) {
                $record = ['sku' => (string)$sku, 'product' => $calculated['productName'], 'group' => $group['groupName'],
                    'receivingWarehouse' => $group['receivingWarehouseId'], 'available' => $group['available'], 'sales' => $group['regularSales'],
                    'returns' => $group['returns'], 'coverage' => $group['coverageDays'], 'target' => $group['target'], 'need' => $group['needBeforeReceipts'],
                    'transfer' => wp_json_encode($group['transfers'], JSON_UNESCAPED_UNICODE),
                    'supplierPrices' => wp_json_encode($raw['supplierPrices'] ?? [], JSON_UNESCAPED_UNICODE),
                    'respectPack' => $group['respectPack'] ? 'YES' : 'NO',
                    'purchase' => $group['recommendedQuantity'], 'quantity' => $group['managerQuantity'], 'final' => $group['finalQuantity'], 'reason' => $group['managerReason'],
                    'status' => $group['status'] . ($group['issues'] ? ': ' . implode(', ', $group['issues']) : '')];
                $record += $group['inputs'];
                $settings = array_values(array_filter($state['groups'], static fn($settings) => $settings['code'] === $group['groupCode']))[0];
                $record += ['supplier' => implode(', ', $calculated['supplier']), 'scenario' => $state['scenario']['name'] . ' v' . $state['scenario']['version'],
                    'period' => $state['query']['period']['from'] . ' - ' . $state['query']['period']['to'],
                    'leadTimeDays' => $settings['leadTimeDays'], 'targetDays' => $settings['targetDays'], 'safetyDays' => $settings['safetyDays'],
                    'groupsRevision' => $state['groupsRevision'], 'transitWarehouses' => implode(', ', $state['transitWarehouseIds']),
                    'transitStatus' => $calculated['transitStatus'], 'transitPool' => $calculated['transitPool'], 'receiptsReviewed' => $group['receiptsReviewed'] ? 'YES' : 'NO',
                    'transitSource' => wp_json_encode($raw['inTransitStock'] ?? null, JSON_UNESCAPED_UNICODE)];
                foreach ($record as &$value) if (is_string($value) && preg_match('/^[\s]*[=+@\-]/u', $value)) $value = "'" . $value;
                unset($value);
                $rows[] = $record;
            }
        }
        $metadata = lps_product_analytics_export_metadata($state['query'], []);
        $metadata['scenario'] = $state['scenario']['name'] . ' v' . $state['scenario']['version'];
        if (preg_match('/^[\s]*[=+@\-]/u', $metadata['scenario'])) $metadata['scenario'] = "'" . $metadata['scenario'];
        $metadata['warehouseGroupsRevision'] = $state['groupsRevision'];
        $metadata['transitWarehouseIds'] = wp_json_encode($state['transitWarehouseIds']);
        $metadata['transitGenerationId'] = $state['transitGenerationId'];
        $metadata['warehouseGroups'] = wp_json_encode($state['groups'], JSON_UNESCAPED_UNICODE);
        $metadata['generationId'] = wp_json_encode($state['context'], JSON_UNESCAPED_UNICODE);
        $filename = 'purchase-preview-' . gmdate('Ymd-His');
        if (($_POST['format'] ?? '') === 'xlsx') lps_product_analytics_export_xlsx($columns, $rows, $metadata, $filename . '.xlsx');
        lps_product_analytics_export_csv($columns, $rows, $filename . '.csv');
    } catch (InvalidArgumentException $error) { wp_die(esc_html($error->getMessage())); }
});
