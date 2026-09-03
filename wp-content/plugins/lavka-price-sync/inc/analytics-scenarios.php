<?php
if (!defined('ABSPATH')) exit;

const LPS_ANALYTICS_SCENARIOS_PAGE = 'lps-analytics-scenarios';
const LPS_ANALYTICS_SCENARIOS_NONCE = 'lps_analytics_scenarios';
const LPS_ANALYTICS_SCENARIOS_DB_VERSION = 1;
const LPS_ANALYTICS_SCENARIOS_DB_OPTION = 'lps_analytics_scenarios_db_version';
const LPS_ANALYTICS_SCENARIOS_MIGRATION_META = 'lps_analytics_scenarios_migration_v1';

function lps_analytics_scenarios_tables(): array {
    global $wpdb;
    return [
        'scenario' => $wpdb->prefix . 'lps_analytics_scenarios',
        'revision' => $wpdb->prefix . 'lps_analytics_scenario_revisions',
    ];
}

function lps_analytics_scenarios_install(): void {
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    global $wpdb;
    $tables = lps_analytics_scenarios_tables();
    $charset = $wpdb->get_charset_collate();

    dbDelta("CREATE TABLE {$tables['scenario']} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        scenario_uuid CHAR(36) NOT NULL,
        name VARCHAR(191) NOT NULL,
        description TEXT NULL,
        visibility VARCHAR(20) NOT NULL DEFAULT 'shared',
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        schema_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
        profile_json LONGTEXT NOT NULL,
        version INT UNSIGNED NOT NULL DEFAULT 1,
        legacy_key VARCHAR(191) NULL,
        created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
        updated_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY scenario_uuid (scenario_uuid),
        UNIQUE KEY legacy_key (legacy_key),
        KEY visibility_status (visibility,status),
        KEY created_by (created_by)
    ) {$charset};");

    dbDelta("CREATE TABLE {$tables['revision']} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        scenario_id BIGINT UNSIGNED NOT NULL,
        version INT UNSIGNED NOT NULL,
        name VARCHAR(191) NOT NULL,
        description TEXT NULL,
        visibility VARCHAR(20) NOT NULL,
        status VARCHAR(20) NOT NULL,
        schema_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
        profile_json LONGTEXT NOT NULL,
        changed_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
        changed_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY scenario_version (scenario_id,version),
        KEY changed_at (changed_at)
    ) {$charset};");

    update_option(LPS_ANALYTICS_SCENARIOS_DB_OPTION, LPS_ANALYTICS_SCENARIOS_DB_VERSION, false);
}

add_action('admin_init', static function (): void {
    if ((int)get_option(LPS_ANALYTICS_SCENARIOS_DB_OPTION, 0) < LPS_ANALYTICS_SCENARIOS_DB_VERSION) {
        lps_analytics_scenarios_install();
    }
});

function lps_analytics_scenario_product_keys(): array {
    return [
        'search', 'health', 'verification', 'alertCode', 'alertStatus', 'severity', 'sales',
        'supplierMode', 'supplierQuality', 'availableSign', 'accountingPriceMode',
        'inventoryMin', 'inventoryMax', 'physicalMin', 'physicalMax', 'reservedMin', 'reservedMax',
        'availableMin', 'availableMax', 'demandPeriod', 'regularDemand', 'oneOffDemand',
        'financePeriod', 'revenueMin', 'revenueMax', 'profitMin', 'profitMax',
        'averageCapitalMin', 'averageCapitalMax', 'marginMin', 'marginMax',
        'turnsMin', 'turnsMax', 'gmroiMin', 'gmroiMax', 'coverageMin', 'coverageMax',
        'lastSaleFrom', 'lastSaleTo', 'lastRegularSaleFrom', 'lastRegularSaleTo',
        'lastReceiptFrom', 'lastReceiptTo', 'firstMovementFrom', 'firstMovementTo',
        'lastMovementFrom', 'lastMovementTo', 'alertFirstSeenFrom', 'alertFirstSeenTo',
        'alertLastSeenFrom', 'alertLastSeenTo', 'perPage', 'view', 'sort', 'direction',
    ];
}

function lps_analytics_scenario_movement_keys(): array {
    return [
        'documentDateFrom', 'documentDateTo', 'movementSku', 'documentNumber', 'documentType',
        'operationKind', 'movementClass', 'stockDirection', 'demandMode', 'paymentTerms',
        'customerSegment', 'accounted', 'returnFlag', 'affectsStock', 'affectsFinancialSales',
        'affectsPlanningDemand', 'counterparty', 'movementSupplier', 'movementPerPage',
    ];
}

function lps_analytics_scenario_default_profile(): array {
    return [
        'schemaVersion' => 1,
        'context' => [
            'sourceDatabase' => '',
            'warehouseIds' => [],
        ],
        'products' => [
            'supplierMode' => 'ANY',
            'supplierValues' => [],
            'supplierQuality' => 'ANY',
            'availableSign' => 'ANY',
            'accountingPriceMode' => 'ANY',
            'demandPeriod' => '365',
            'regularDemand' => 'ANY',
            'oneOffDemand' => 'ANY',
            'financePeriod' => '365',
            'alertStatus' => 'ANY',
            'perPage' => '50',
            'view' => 'all',
            'sort' => 'inventory_value',
            'direction' => 'DESC',
        ],
        'movements' => [
            'movementPerPage' => '50',
        ],
        'presentation' => [
            'activeTab' => 'products',
        ],
    ];
}

function lps_analytics_scenario_sanitize_values($values, int $limit = 100): array {
    if (is_string($values)) {
        $values = preg_split('/[\r\n]+/', $values) ?: [];
    }
    if (!is_array($values)) return [];

    $clean = array_map(
        static fn($value): string => trim(sanitize_text_field((string)$value)),
        $values
    );
    return array_slice(array_values(array_unique(array_filter(
        $clean,
        static fn(string $value): bool => $value !== ''
    ))), 0, $limit);
}

function lps_analytics_scenario_default_profile_v4(): array {
    return [
        'schemaVersion' => 4,
        'context' => ['sourceDatabase' => 'Paint_Ua', 'warehouseIds' => []],
        'period' => ['from' => '', 'to' => ''],
        'productFilters' => [],
        'movementFilters' => [],
        'calculation' => ['abcBasis' => 'GROSS_PROFIT', 'includeReturns' => true],
        'page' => ['size' => 50],
        'sort' => [['field' => 'grossProfit', 'direction' => 'DESC']],
        'presentation' => ['activeTab' => 'products'],
    ];
}

function lps_analytics_scenario_sanitize_selection($selection, int $limit = 500): array {
    if (!is_array($selection)) return ['mode' => 'ANY', 'values' => []];
    $mode = strtoupper(sanitize_text_field((string)($selection['mode'] ?? 'ANY')));
    if (!in_array($mode, ['ANY', 'INCLUDE', 'EXCLUDE'], true)) $mode = 'ANY';
    $values = lps_analytics_scenario_sanitize_values($selection['values'] ?? [], $limit);
    if (!$values) $mode = 'ANY';
    return ['mode' => $mode, 'values' => $values];
}

function lps_analytics_scenario_sanitize_profile_v4(array $profile): array {
    $context = is_array($profile['context'] ?? null) ? $profile['context'] : [];
    $source = sanitize_text_field((string)($context['sourceDatabase'] ?? 'Paint_Ua'));
    if (!preg_match('/^[A-Za-z0-9_]+$/', $source)) $source = 'Paint_Ua';
    $warehouses = is_array($context['warehouseIds'] ?? null) ? $context['warehouseIds'] : [];
    $warehouses = array_values(array_unique(array_filter(array_map('absint', $warehouses), static fn(int $id): bool => $id > 0)));
    sort($warehouses, SORT_NUMERIC);
    $warehouses = array_slice($warehouses, 0, 50);

    $period_in = is_array($profile['period'] ?? null) ? $profile['period'] : [];
    $from = sanitize_text_field((string)($period_in['from'] ?? ''));
    $to = sanitize_text_field((string)($period_in['to'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to = '';

    $product_in = is_array($profile['productFilters'] ?? null) ? $profile['productFilters'] : [];
    $movement_in = is_array($profile['movementFilters'] ?? null) ? $profile['movementFilters'] : [];
    $product = [];
    $search = sanitize_text_field((string)($product_in['search'] ?? ''));
    if ($search !== '') $product['search'] = function_exists('mb_substr') ? mb_substr($search, 0, 200) : substr($search, 0, 200);
    foreach ([
        'skus', 'groups', 'groupLevel1', 'groupLevel2', 'groupLevel3', 'groupLevel4',
        'groupLevel5', 'groupLevel6', 'departments', 'productTypes', 'units',
        'currentSuppliers', 'supplierStates', 'brands', 'barcodes',
    ] as $key) {
        $selection = lps_analytics_scenario_sanitize_selection($product_in[$key] ?? null);
        if ($selection['mode'] !== 'ANY') $product[$key] = $selection;
    }
    $movements = [];
    foreach ([
        'operationKinds', 'movementClasses', 'demandModes', 'documentTypes',
        'stockDirections', 'paymentTerms', 'customerSegments', 'counterparties',
        'organizationTypes', 'salesManagerCodes', 'sourceWarehouseIds', 'destinationWarehouseIds',
    ] as $key) {
        $selection = lps_analytics_scenario_sanitize_selection($movement_in[$key] ?? null);
        if ($selection['mode'] !== 'ANY') $movements[$key] = $selection;
    }

    $calculation_in = is_array($profile['calculation'] ?? null) ? $profile['calculation'] : [];
    $abc_basis = strtoupper(sanitize_text_field((string)($calculation_in['abcBasis'] ?? 'GROSS_PROFIT')));
    if (!in_array($abc_basis, ['REVENUE', 'GROSS_PROFIT', 'SOLD_UNITS'], true)) $abc_basis = 'GROSS_PROFIT';

    $page_in = is_array($profile['page'] ?? null) ? $profile['page'] : [];
    $page_size = max(1, min(500, absint($page_in['size'] ?? 50)));
    $allowed_sort = ['sku', 'productName', 'physicalQuantity', 'inventoryValue', 'soldUnits', 'salesRevenue', 'salesCogs', 'grossProfit', 'averageInventoryValue'];
    $sort = [];
    foreach (array_slice(is_array($profile['sort'] ?? null) ? $profile['sort'] : [], 0, 5) as $item) {
        if (!is_array($item)) continue;
        $field = sanitize_text_field((string)($item['field'] ?? ''));
        if (!in_array($field, $allowed_sort, true)) continue;
        $sort[] = ['field' => $field, 'direction' => strtoupper((string)($item['direction'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC'];
    }
    if (!$sort) $sort[] = ['field' => 'grossProfit', 'direction' => 'DESC'];
    $presentation = is_array($profile['presentation'] ?? null) ? $profile['presentation'] : [];

    return [
        'schemaVersion' => 4,
        'context' => ['sourceDatabase' => $source, 'warehouseIds' => $warehouses],
        'period' => ['from' => $from, 'to' => $to],
        'productFilters' => $product,
        'movementFilters' => $movements,
        'calculation' => [
            'abcBasis' => $abc_basis,
            'includeReturns' => !isset($calculation_in['includeReturns']) || rest_sanitize_boolean($calculation_in['includeReturns']),
        ],
        'page' => ['size' => $page_size],
        'sort' => $sort,
        'presentation' => ['activeTab' => sanitize_key((string)($presentation['activeTab'] ?? 'products')) === 'movements' ? 'movements' : 'products'],
    ];
}

function lps_analytics_scenario_sanitize_profile(array $profile): array {
    if ((int)($profile['schemaVersion'] ?? 1) >= 4 || isset($profile['productFilters']) || isset($profile['movementFilters'])) {
        return lps_analytics_scenario_sanitize_profile_v4($profile);
    }
    $default = lps_analytics_scenario_default_profile();
    $context = is_array($profile['context'] ?? null) ? $profile['context'] : [];
    $source = sanitize_text_field((string)($context['sourceDatabase'] ?? ''));
    $warehouses = is_array($context['warehouseIds'] ?? null) ? $context['warehouseIds'] : [];
    $warehouses = array_slice(array_values(array_unique(array_filter(array_map('absint', $warehouses)))), 0, 20);

    $products_in = is_array($profile['products'] ?? null) ? $profile['products'] : [];
    $products = [];
    foreach (lps_analytics_scenario_product_keys() as $key) {
        $products[$key] = sanitize_text_field((string)($products_in[$key] ?? ($default['products'][$key] ?? '')));
    }
    $products['supplierMode'] = in_array(strtoupper($products['supplierMode']), ['ANY', 'INCLUDE', 'EXCLUDE'], true)
        ? strtoupper($products['supplierMode'])
        : 'ANY';
    $products['supplierValues'] = lps_analytics_scenario_sanitize_values($products_in['supplierValues'] ?? []);
    if ($products['supplierMode'] !== 'ANY' && !$products['supplierValues']) {
        $products['supplierMode'] = 'ANY';
    }
    $products['direction'] = strtoupper($products['direction']) === 'ASC' ? 'ASC' : 'DESC';
    $products['perPage'] = in_array((string)$products['perPage'], ['20', '50', '100'], true) ? (string)$products['perPage'] : '50';

    $movements_in = is_array($profile['movements'] ?? null) ? $profile['movements'] : [];
    $movements = [];
    foreach (lps_analytics_scenario_movement_keys() as $key) {
        $movements[$key] = sanitize_text_field((string)($movements_in[$key] ?? ($default['movements'][$key] ?? '')));
    }
    $movements['movementPerPage'] = in_array((string)$movements['movementPerPage'], ['20', '50', '100'], true)
        ? (string)$movements['movementPerPage']
        : '50';

    $presentation = is_array($profile['presentation'] ?? null) ? $profile['presentation'] : [];
    $active_tab = sanitize_key((string)($presentation['activeTab'] ?? 'products'));

    return [
        'schemaVersion' => 1,
        'context' => [
            'sourceDatabase' => $source,
            'warehouseIds' => $warehouses,
        ],
        'products' => $products,
        'movements' => $movements,
        'presentation' => [
            'activeTab' => $active_tab === 'movements' ? 'movements' : 'products',
        ],
    ];
}

function lps_analytics_scenario_profile_from_legacy(array $state): array {
    $profile = lps_analytics_scenario_default_profile();
    $profile['context']['sourceDatabase'] = sanitize_text_field((string)($state['sourceDatabase'] ?? ''));
    $warehouse = absint($state['warehouseId'] ?? 0);
    $profile['context']['warehouseIds'] = $warehouse > 0 ? [$warehouse] : [];
    foreach (lps_analytics_scenario_product_keys() as $key) {
        if (array_key_exists($key, $state)) $profile['products'][$key] = $state[$key];
    }
    $profile['products']['supplierValues'] = lps_analytics_scenario_sanitize_values($state['supplierValues'] ?? []);
    return lps_analytics_scenario_sanitize_profile($profile);
}

function lps_analytics_scenario_decode_row(array $row): array {
    $profile = json_decode((string)($row['profile_json'] ?? ''), true);
    if (!is_array($profile)) $profile = lps_analytics_scenario_default_profile();
    $owner = get_userdata((int)($row['created_by'] ?? 0));
    $current_user = get_current_user_id();
    $visibility = (string)($row['visibility'] ?? 'shared');
    $can_edit = current_user_can('manage_options') || $visibility === 'shared' || (int)$row['created_by'] === $current_user;

    return [
        'id' => (int)$row['id'],
        'uuid' => (string)$row['scenario_uuid'],
        'name' => (string)$row['name'],
        'description' => (string)($row['description'] ?? ''),
        'visibility' => $visibility,
        'status' => (string)($row['status'] ?? 'active'),
        'schemaVersion' => (int)($row['schema_version'] ?? 1),
        'profile' => lps_analytics_scenario_sanitize_profile($profile),
        'version' => (int)($row['version'] ?? 1),
        'owner' => $owner ? $owner->display_name : __('Unknown user', 'lavka-price-sync'),
        'updatedAt' => (string)($row['updated_at'] ?? ''),
        'canEdit' => $can_edit,
    ];
}

function lps_analytics_scenarios_visible_where(bool $include_archived = false): array {
    $where = ['(visibility=%s OR created_by=%d)'];
    $args = ['shared', get_current_user_id()];
    if (!$include_archived) {
        $where[] = 'status=%s';
        $args[] = 'active';
    }
    return [implode(' AND ', $where), $args];
}

function lps_analytics_scenarios_list(bool $include_archived = false): array {
    global $wpdb;
    lps_analytics_scenarios_maybe_migrate_legacy();
    $tables = lps_analytics_scenarios_tables();
    [$where, $args] = lps_analytics_scenarios_visible_where($include_archived);
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$tables['scenario']} WHERE {$where} ORDER BY status='active' DESC, name ASC, id ASC",
        $args
    ), ARRAY_A) ?: [];
    return array_map('lps_analytics_scenario_decode_row', $rows);
}

function lps_analytics_scenario_row(int $scenario_id): ?array {
    global $wpdb;
    if ($scenario_id < 1) return null;
    $tables = lps_analytics_scenarios_tables();
    [$where, $args] = lps_analytics_scenarios_visible_where(true);
    array_unshift($args, $scenario_id);
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$tables['scenario']} WHERE id=%d AND {$where} LIMIT 1",
        $args
    ), ARRAY_A);
    return is_array($row) ? $row : null;
}

function lps_analytics_scenario_insert_revision(array $row): void {
    global $wpdb;
    $tables = lps_analytics_scenarios_tables();
    $ok = $wpdb->insert($tables['revision'], [
        'scenario_id' => (int)$row['id'],
        'version' => (int)$row['version'],
        'name' => (string)$row['name'],
        'description' => (string)($row['description'] ?? ''),
        'visibility' => (string)$row['visibility'],
        'status' => (string)$row['status'],
        'schema_version' => (int)$row['schema_version'],
        'profile_json' => (string)$row['profile_json'],
        'changed_by' => get_current_user_id(),
        'changed_at' => current_time('mysql'),
    ]);
    if ($ok === false) {
        throw new RuntimeException(__('The scenario revision could not be saved.', 'lavka-price-sync'));
    }
}

function lps_analytics_scenario_create(
    string $name,
    string $description,
    string $visibility,
    string $status,
    array $profile,
    ?string $legacy_key = null
): int {
    global $wpdb;
    $tables = lps_analytics_scenarios_tables();
    $now = current_time('mysql');
    $profile = lps_analytics_scenario_sanitize_profile($profile);
    $ok = $wpdb->insert($tables['scenario'], [
        'scenario_uuid' => wp_generate_uuid4(),
        'name' => $name,
        'description' => $description,
        'visibility' => $visibility,
        'status' => $status,
        'schema_version' => (int)$profile['schemaVersion'],
        'profile_json' => wp_json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'version' => 1,
        'legacy_key' => $legacy_key,
        'created_by' => get_current_user_id(),
        'updated_by' => get_current_user_id(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    if ($ok === false) {
        throw new RuntimeException(__('The analytics scenario could not be created.', 'lavka-price-sync'));
    }
    $scenario_id = (int)$wpdb->insert_id;
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tables['scenario']} WHERE id=%d", $scenario_id), ARRAY_A);
    if (!is_array($row)) throw new RuntimeException(__('The created scenario could not be read.', 'lavka-price-sync'));
    lps_analytics_scenario_insert_revision($row);
    return $scenario_id;
}

function lps_analytics_scenarios_maybe_migrate_legacy(): void {
    $user_id = get_current_user_id();
    if ($user_id < 1 || get_user_meta($user_id, LPS_ANALYTICS_SCENARIOS_MIGRATION_META, true)) return;
    if (!defined('LPS_PRODUCT_ANALYTICS_PRESETS_META')) return;

    $legacy = get_user_meta($user_id, LPS_PRODUCT_ANALYTICS_PRESETS_META, true);
    if (is_array($legacy)) {
        global $wpdb;
        $tables = lps_analytics_scenarios_tables();
        foreach ($legacy as $preset) {
            if (!is_array($preset) || empty($preset['id']) || empty($preset['name']) || !is_array($preset['state'] ?? null)) continue;
            $legacy_key = 'user:' . $user_id . ':preset:' . sanitize_key((string)$preset['id']);
            $exists = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$tables['scenario']} WHERE legacy_key=%s",
                $legacy_key
            ));
            if ($exists > 0) continue;
            lps_analytics_scenario_create(
                sanitize_text_field((string)$preset['name']),
                __('Imported from a personal product-analytics filter set.', 'lavka-price-sync'),
                'personal',
                'active',
                lps_analytics_scenario_profile_from_legacy($preset['state']),
                $legacy_key
            );
        }
    }
    update_user_meta($user_id, LPS_ANALYTICS_SCENARIOS_MIGRATION_META, current_time('mysql'));
}

function lps_analytics_scenario_request_payload(): array {
    $name = trim(sanitize_text_field(wp_unslash($_POST['name'] ?? '')));
    if ($name === '') {
        wp_send_json_error(['message' => __('Enter a scenario name.', 'lavka-price-sync')], 400);
    }
    $name = function_exists('mb_substr') ? mb_substr($name, 0, 191) : substr($name, 0, 191);
    $description = sanitize_textarea_field(wp_unslash($_POST['description'] ?? ''));
    $visibility = sanitize_key(wp_unslash($_POST['visibility'] ?? 'shared')) === 'personal' ? 'personal' : 'shared';
    $status = sanitize_key(wp_unslash($_POST['status'] ?? 'active')) === 'archived' ? 'archived' : 'active';
    $decoded = json_decode((string)wp_unslash($_POST['profileJson'] ?? ''), true);
    if (!is_array($decoded)) {
        wp_send_json_error(['message' => __('The scenario data is invalid.', 'lavka-price-sync')], 400);
    }
    $profile = lps_analytics_scenario_sanitize_profile($decoded);
    $source = (string)$profile['context']['sourceDatabase'];
    $warehouses = $profile['context']['warehouseIds'];
    if ($source === '' || !$warehouses) {
        wp_send_json_error(['message' => __('Select one or more available Folio warehouses.', 'lavka-price-sync')], 400);
    }
    if (function_exists('lps_accounting_prices_warehouse_directory')) {
        $directory = lps_accounting_prices_warehouse_directory();
        $allowed_ids = array_map(static fn(array $item): int => (int)($item['id'] ?? 0), (array)($directory['items'] ?? []));
        if (empty($directory['ok']) || array_diff($warehouses, $allowed_ids)) {
            wp_send_json_error(['message' => __('One or more selected Folio warehouses are not available.', 'lavka-price-sync')], 409);
        }
    }

    return compact('name', 'description', 'visibility', 'status', 'profile');
}

function lps_analytics_scenario_save(): array {
    global $wpdb;
    $tables = lps_analytics_scenarios_tables();
    $payload = lps_analytics_scenario_request_payload();
    $scenario_id = absint($_POST['scenarioId'] ?? 0);
    $expected_version = absint($_POST['expectedVersion'] ?? 0);

    $wpdb->query('START TRANSACTION');
    try {
        if ($scenario_id < 1) {
            $scenario_id = lps_analytics_scenario_create(
                $payload['name'],
                $payload['description'],
                $payload['visibility'],
                $payload['status'],
                $payload['profile']
            );
        } else {
            $row = lps_analytics_scenario_row($scenario_id);
            if (!$row) throw new RuntimeException(__('The analytics scenario was not found.', 'lavka-price-sync'));
            $decoded = lps_analytics_scenario_decode_row($row);
            if (empty($decoded['canEdit'])) throw new RuntimeException(__('You cannot edit this analytics scenario.', 'lavka-price-sync'));
            if ($expected_version < 1 || (int)$row['version'] !== $expected_version) {
                $wpdb->query('ROLLBACK');
                wp_send_json_error(['message' => __('This scenario was changed by another user. Reload it before saving.', 'lavka-price-sync')], 409);
            }
            $next_version = $expected_version + 1;
            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$tables['scenario']}
                    SET name=%s, description=%s, visibility=%s, status=%s, schema_version=%d,
                        profile_json=%s, version=%d, updated_by=%d, updated_at=%s
                  WHERE id=%d AND version=%d",
                [
                    $payload['name'], $payload['description'], $payload['visibility'], $payload['status'], (int)$payload['profile']['schemaVersion'],
                    wp_json_encode($payload['profile'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $next_version, get_current_user_id(), current_time('mysql'), $scenario_id, $expected_version,
                ]
            ));
            if ($updated !== 1) throw new RuntimeException(__('The analytics scenario could not be updated.', 'lavka-price-sync'));
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tables['scenario']} WHERE id=%d", $scenario_id), ARRAY_A);
            if (!is_array($row)) throw new RuntimeException(__('The updated scenario could not be read.', 'lavka-price-sync'));
            lps_analytics_scenario_insert_revision($row);
        }
        $wpdb->query('COMMIT');
    } catch (Throwable $error) {
        $wpdb->query('ROLLBACK');
        wp_send_json_error(['message' => $error->getMessage()], 500);
    }

    return [
        'items' => lps_analytics_scenarios_list(true),
        'selectedId' => $scenario_id,
    ];
}

function lps_analytics_scenario_duplicate(): array {
    $scenario_id = absint($_POST['scenarioId'] ?? 0);
    $row = lps_analytics_scenario_row($scenario_id);
    if (!$row) wp_send_json_error(['message' => __('The analytics scenario was not found.', 'lavka-price-sync')], 404);
    $scenario = lps_analytics_scenario_decode_row($row);
    $name = sprintf(
        /* translators: %s: original scenario name. */
        __('Copy of %s', 'lavka-price-sync'),
        $scenario['name']
    );
    try {
        $new_id = lps_analytics_scenario_create(
            $name,
            $scenario['description'],
            'personal',
            'active',
            $scenario['profile']
        );
    } catch (Throwable $error) {
        wp_send_json_error(['message' => $error->getMessage()], 500);
    }
    return ['items' => lps_analytics_scenarios_list(true), 'selectedId' => $new_id];
}

function lps_analytics_scenario_archive(): array {
    global $wpdb;
    $tables = lps_analytics_scenarios_tables();
    $scenario_id = absint($_POST['scenarioId'] ?? 0);
    $expected_version = absint($_POST['expectedVersion'] ?? 0);
    $row = lps_analytics_scenario_row($scenario_id);
    if (!$row) wp_send_json_error(['message' => __('The analytics scenario was not found.', 'lavka-price-sync')], 404);
    $scenario = lps_analytics_scenario_decode_row($row);
    if (empty($scenario['canEdit'])) wp_send_json_error(['message' => __('You cannot edit this analytics scenario.', 'lavka-price-sync')], 403);
    if ((int)$row['version'] !== $expected_version) {
        wp_send_json_error(['message' => __('This scenario was changed by another user. Reload it before archiving.', 'lavka-price-sync')], 409);
    }

    $wpdb->query('START TRANSACTION');
    try {
        $next_version = $expected_version + 1;
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$tables['scenario']} SET status='archived', version=%d, updated_by=%d, updated_at=%s WHERE id=%d AND version=%d",
            [$next_version, get_current_user_id(), current_time('mysql'), $scenario_id, $expected_version]
        ));
        if ($updated !== 1) throw new RuntimeException(__('The analytics scenario could not be archived.', 'lavka-price-sync'));
        $updated_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tables['scenario']} WHERE id=%d", $scenario_id), ARRAY_A);
        if (!is_array($updated_row)) throw new RuntimeException(__('The archived scenario could not be read.', 'lavka-price-sync'));
        lps_analytics_scenario_insert_revision($updated_row);
        $wpdb->query('COMMIT');
    } catch (Throwable $error) {
        $wpdb->query('ROLLBACK');
        wp_send_json_error(['message' => $error->getMessage()], 500);
    }
    return ['items' => lps_analytics_scenarios_list(true)];
}

function lps_analytics_scenario_revisions(): array {
    global $wpdb;
    $tables = lps_analytics_scenarios_tables();
    $scenario_id = absint($_POST['scenarioId'] ?? 0);
    if (!lps_analytics_scenario_row($scenario_id)) {
        wp_send_json_error(['message' => __('The analytics scenario was not found.', 'lavka-price-sync')], 404);
    }
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT version, changed_by, changed_at FROM {$tables['revision']} WHERE scenario_id=%d ORDER BY version DESC LIMIT 30",
        $scenario_id
    ), ARRAY_A) ?: [];
    return ['items' => array_map(static function (array $row): array {
        $user = get_userdata((int)$row['changed_by']);
        return [
            'version' => (int)$row['version'],
            'changedBy' => $user ? $user->display_name : __('Unknown user', 'lavka-price-sync'),
            'changedAt' => (string)$row['changed_at'],
        ];
    }, $rows)];
}

function lps_analytics_scenarios_ajax(): void {
    if (!current_user_can(LPS_CAP)) {
        wp_send_json_error(['message' => __('You do not have permission to manage analytics scenarios.', 'lavka-price-sync')], 403);
    }
    check_ajax_referer(LPS_ANALYTICS_SCENARIOS_NONCE);
    $operation = sanitize_key(wp_unslash($_POST['operation'] ?? ''));

    switch ($operation) {
        case 'list':
            $data = ['items' => lps_analytics_scenarios_list(!empty($_POST['includeArchived']))];
            break;
        case 'bootstrap':
            $directory = function_exists('lps_accounting_prices_warehouse_directory')
                ? lps_accounting_prices_warehouse_directory()
                : ['ok' => false, 'items' => [], 'message' => __('The warehouse directory is unavailable.', 'lavka-price-sync')];
            $data = [
                'items' => lps_analytics_scenarios_list(true),
                'sourceDatabase' => function_exists('lps_product_analytics_v4_source_database') ? lps_product_analytics_v4_source_database() : 'Paint_Ua',
                'warehouses' => array_values((array)($directory['items'] ?? [])),
                'warehouseDirectoryReady' => !empty($directory['ok']),
                'warehouseDirectoryMessage' => sanitize_text_field((string)($directory['message'] ?? '')),
            ];
            break;
        case 'v4_capabilities':
            if (!function_exists('lps_product_analytics_v4_payload') || !function_exists('lps_product_analytics_v4_send_java')) {
                wp_send_json_error(['message' => __('Product analytics is not available.', 'lavka-price-sync')], 503);
            }
            $payload = lps_product_analytics_v4_payload();
            $source = sanitize_text_field((string)($payload['sourceDatabase'] ?? 'Paint_Ua'));
            $warehouse_ids = lps_product_analytics_v4_warehouse_ids($payload['warehouseIds'] ?? []);
            if ($source === '' || !$warehouse_ids) {
                wp_send_json_error(['message' => __('Select one or more Folio warehouses.', 'lavka-price-sync')], 400);
            }
            lps_product_analytics_v4_send_java(LPS_PRODUCT_ANALYTICS_CAPABILITIES_PATH, [
                'sourceDatabase' => $source,
                'warehouseIds' => $warehouse_ids,
            ]);
            break;
        case 'scope_options':
            if (!function_exists('lps_product_analytics_filter_options')) {
                wp_send_json_error(['message' => __('Product analytics is not available.', 'lavka-price-sync')], 503);
            }
            [$source, $warehouse] = lps_product_analytics_scope();
            $data = lps_product_analytics_filter_options($source, $warehouse);
            break;
        case 'save':
            $data = lps_analytics_scenario_save();
            break;
        case 'duplicate':
            $data = lps_analytics_scenario_duplicate();
            break;
        case 'archive':
            $data = lps_analytics_scenario_archive();
            break;
        case 'revisions':
            $data = lps_analytics_scenario_revisions();
            break;
        default:
            wp_send_json_error(['message' => __('Unsupported analytics-scenario operation.', 'lavka-price-sync')], 400);
    }
    wp_send_json_success($data);
}
add_action('wp_ajax_lps_analytics_scenarios', 'lps_analytics_scenarios_ajax');

add_action('admin_menu', static function (): void {
    add_submenu_page(
        function_exists('paint_core_lavka_admin_parent_slug') ? paint_core_lavka_admin_parent_slug() : 'lps-main',
        __('Folio analytics scenarios', 'lavka-price-sync'),
        __('Analytics scenarios', 'lavka-price-sync'),
        LPS_CAP,
        LPS_ANALYTICS_SCENARIOS_PAGE,
        'lps_render_analytics_scenarios_page'
    );
}, 20);

add_action('admin_enqueue_scripts', static function (): void {
    $page = sanitize_key(wp_unslash($_GET['page'] ?? ''));
    if ($page !== LPS_ANALYTICS_SCENARIOS_PAGE) return;

    $plugin_file = dirname(__DIR__) . '/lavka-price-sync.php';
    $css_path = dirname(__DIR__) . '/assets/analytics-scenarios.css';
    $js_path = dirname(__DIR__) . '/assets/analytics-scenarios-v4.js';
    wp_enqueue_style(
        'lps-analytics-scenarios',
        plugins_url('assets/analytics-scenarios.css', $plugin_file),
        [],
        @filemtime($css_path) ?: '1.0'
    );
    wp_enqueue_script(
        'lps-analytics-scenarios',
        plugins_url('assets/analytics-scenarios-v4.js', $plugin_file),
        [],
        @filemtime($js_path) ?: '1.0',
        true
    );
    wp_localize_script('lps-analytics-scenarios', 'LPS_ANALYTICS_SCENARIOS', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce(LPS_ANALYTICS_SCENARIOS_NONCE),
        'analyticsUrl' => admin_url('admin.php?page=' . LPS_PRODUCT_ANALYTICS_PAGE),
        'locale' => str_replace('_', '-', determine_locale()),
        'i18n' => [
            'loading' => __('Loading analytics scenarios...', 'lavka-price-sync'),
            'loadFailed' => __('Analytics scenarios could not be loaded.', 'lavka-price-sync'),
            'newScenario' => __('New scenario', 'lavka-price-sync'),
            'saved' => __('The analytics scenario has been saved.', 'lavka-price-sync'),
            'archived' => __('The analytics scenario has been archived.', 'lavka-price-sync'),
            'confirmArchive' => __('Archive this analytics scenario?', 'lavka-price-sync'),
            'selectScenario' => __('Select a scenario to edit.', 'lavka-price-sync'),
            'noScenarios' => __('No analytics scenarios have been created yet.', 'lavka-price-sync'),
            'noScope' => __('Select an available Folio warehouse.', 'lavka-price-sync'),
            'scopeLoadFailed' => __('Warehouse filter options could not be loaded.', 'lavka-price-sync'),
            'savedUnavailableValue' => __('Saved value is not available in the current snapshot', 'lavka-price-sync'),
            'active' => __('Active', 'lavka-price-sync'),
            'archivedStatus' => __('Archived', 'lavka-price-sync'),
            'shared' => __('Shared', 'lavka-price-sync'),
            'personal' => __('Personal', 'lavka-price-sync'),
            'version' => __('Version', 'lavka-price-sync'),
            'selectWarehouses' => __('Select one or more Folio warehouses.', 'lavka-price-sync'),
            'capabilitiesLoading' => __('Loading supported filters and dictionaries...', 'lavka-price-sync'),
            'capabilitiesFailed' => __('Supported analytics filters could not be loaded.', 'lavka-price-sync'),
            'any' => __('Do not apply this condition', 'lavka-price-sync'),
            'include' => __('Include selected values', 'lavka-price-sync'),
            'exclude' => __('Exclude selected values', 'lavka-price-sync'),
        ],
        'analyticsI18n' => function_exists('lps_product_analytics_i18n') ? lps_product_analytics_i18n() : [],
    ]);
});

function lps_analytics_scenario_select(string $name, string $label, array $options, string $attributes): void {
    ?>
    <label>
        <span><?php echo esc_html($label); ?></span>
        <select name="<?php echo esc_attr($name); ?>" <?php echo $attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> >
            <?php foreach ($options as $value => $caption): ?>
                <option value="<?php echo esc_attr((string)$value); ?>"><?php echo esc_html($caption); ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <?php
}

function lps_analytics_scenario_input(string $name, string $label, string $type, string $attributes, string $step = ''): void {
    ?>
    <label>
        <span><?php echo esc_html($label); ?></span>
        <input type="<?php echo esc_attr($type); ?>" name="<?php echo esc_attr($name); ?>"<?php echo $step !== '' ? ' step="' . esc_attr($step) . '"' : ''; ?> <?php echo $attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
    </label>
    <?php
}

function lps_render_analytics_scenario_product_fields(): void {
    lps_analytics_scenario_input('product_search', __('SKU or product name', 'lavka-price-sync'), 'search', 'data-scenario-product="search"');
    lps_analytics_scenario_select('product_health', __('Economic status', 'lavka-price-sync'), [
        '' => __('All statuses', 'lavka-price-sync'), 'HEALTHY' => __('Healthy', 'lavka-price-sync'),
        'STOCKOUT' => __('Stockout', 'lavka-price-sync'), 'DEAD_STOCK' => __('Dead stock', 'lavka-price-sync'),
        'OVERSTOCK' => __('Overstock', 'lavka-price-sync'), 'LOW_MARGIN' => __('Negative gross profit, 3 months', 'lavka-price-sync'),
        'DEMAND_FADING' => __('Demand fading', 'lavka-price-sync'), 'DATA_ISSUE' => __('Data issue', 'lavka-price-sync'),
        'NEW' => __('New product', 'lavka-price-sync'), 'ONE_OFF_ONLY_STOCK' => __('Stock supported only by one-off sales', 'lavka-price-sync'),
    ], 'data-scenario-product="health"');
    lps_analytics_scenario_select('product_verification', __('Verification state', 'lavka-price-sync'), [
        '' => __('All states', 'lavka-price-sync'), 'UNVERIFIED' => __('Unverified', 'lavka-price-sync'),
        'VERIFIED' => __('Verified', 'lavka-price-sync'), 'DIRTY' => __('Changed after verification', 'lavka-price-sync'),
        'NEW' => __('New product', 'lavka-price-sync'), 'FAILED' => __('Verification failed', 'lavka-price-sync'),
    ], 'data-scenario-product="verification"');
    lps_analytics_scenario_select('product_sales', __('Commercial sales total', 'lavka-price-sync'), [
        '' => __('Any sales', 'lavka-price-sync'), 'with' => __('With sales', 'lavka-price-sync'), 'without' => __('Without sales', 'lavka-price-sync'),
    ], 'data-scenario-product="sales"');
    lps_analytics_scenario_select('product_alert_code', __('Active alert', 'lavka-price-sync'), [
        '' => __('All alerts', 'lavka-price-sync'), 'DATA_ISSUE' => __('Data issue', 'lavka-price-sync'),
        'STOCKOUT' => __('Stockout', 'lavka-price-sync'), 'LOW_MARGIN' => __('Negative gross profit, 3 months', 'lavka-price-sync'),
        'OVERSTOCK' => __('Overstock', 'lavka-price-sync'), 'DEAD_STOCK' => __('Dead stock', 'lavka-price-sync'),
        'DEMAND_FADING' => __('Demand fading', 'lavka-price-sync'), 'ONE_OFF_ONLY_STOCK' => __('Stock supported only by one-off sales', 'lavka-price-sync'),
    ], 'data-scenario-product="alertCode"');
    lps_analytics_scenario_select('product_alert_status', __('Alert status', 'lavka-price-sync'), [
        'ANY' => __('Active and resolved alerts', 'lavka-price-sync'), 'ACTIVE' => __('Active alerts', 'lavka-price-sync'),
        'RESOLVED' => __('Resolved alerts', 'lavka-price-sync'),
    ], 'data-scenario-product="alertStatus"');
    lps_analytics_scenario_select('product_severity', __('Alert severity', 'lavka-price-sync'), [
        '' => __('All severities', 'lavka-price-sync'), 'ERROR' => __('Error', 'lavka-price-sync'),
        'HIGH' => __('High', 'lavka-price-sync'), 'MEDIUM' => __('Medium', 'lavka-price-sync'), 'LOW' => __('Low', 'lavka-price-sync'),
    ], 'data-scenario-product="severity"');
    lps_analytics_scenario_select('product_supplier_mode', __('Current supplier filter', 'lavka-price-sync'), [
        'ANY' => __('Do not filter by supplier', 'lavka-price-sync'),
        'INCLUDE' => __('Include selected suppliers', 'lavka-price-sync'),
        'EXCLUDE' => __('Exclude selected suppliers', 'lavka-price-sync'),
    ], 'id="lps-as-supplier-mode" data-scenario-product="supplierMode"');
    ?>
    <label class="lps-as-wide">
        <span><?php echo esc_html__('Suppliers', 'lavka-price-sync'); ?></span>
        <select id="lps-as-suppliers" multiple size="7" data-scenario-product="supplierValues" disabled></select>
    </label>
    <?php
    lps_analytics_scenario_select('product_supplier_quality', __('Supplier data quality', 'lavka-price-sync'), [
        'ANY' => __('Any supplier assignment', 'lavka-price-sync'), 'CURRENT' => __('Supplier assigned', 'lavka-price-sync'),
        'MISSING' => __('Supplier missing', 'lavka-price-sync'), 'REVIEW' => __('Service code / requires verification', 'lavka-price-sync'),
    ], 'data-scenario-product="supplierQuality"');
    lps_analytics_scenario_select('product_available_sign', __('Available stock sign', 'lavka-price-sync'), [
        'ANY' => __('Any available stock', 'lavka-price-sync'), 'NON_POSITIVE' => __('Zero or negative', 'lavka-price-sync'),
        'ZERO' => __('Exactly zero', 'lavka-price-sync'), 'POSITIVE' => __('Positive', 'lavka-price-sync'),
    ], 'data-scenario-product="availableSign"');
    lps_analytics_scenario_select('product_accounting_price', __('Accounting price', 'lavka-price-sync'), [
        'ANY' => __('Any accounting price', 'lavka-price-sync'), 'ZERO' => __('Zero accounting price', 'lavka-price-sync'),
        'POSITIVE' => __('Positive accounting price', 'lavka-price-sync'),
    ], 'data-scenario-product="accountingPriceMode"');
    lps_analytics_scenario_select('product_demand_period', __('Demand period', 'lavka-price-sync'), [
        '30' => __('Current month', 'lavka-price-sync'), '90' => __('3 months', 'lavka-price-sync'),
        '365' => __('12 months', 'lavka-price-sync'), '730' => __('24 months', 'lavka-price-sync'),
    ], 'data-scenario-product="demandPeriod"');
    lps_analytics_scenario_select('product_regular_demand', __('Regular demand', 'lavka-price-sync'), [
        'ANY' => __('Any regular demand', 'lavka-price-sync'), 'WITH' => __('With regular demand', 'lavka-price-sync'),
        'WITHOUT' => __('Without regular demand', 'lavka-price-sync'),
    ], 'data-scenario-product="regularDemand"');
    lps_analytics_scenario_select('product_one_off', __('One-off sales', 'lavka-price-sync'), [
        'ANY' => __('Any one-off sales', 'lavka-price-sync'), 'WITH' => __('With one-off sales', 'lavka-price-sync'),
        'WITHOUT' => __('Without one-off sales', 'lavka-price-sync'), 'ONLY' => __('Only one-off sales, no regular demand', 'lavka-price-sync'),
    ], 'data-scenario-product="oneOffDemand"');

    $numeric = [
        'physicalMin' => __('Physical quantity from', 'lavka-price-sync'), 'physicalMax' => __('Physical quantity through', 'lavka-price-sync'),
        'reservedMin' => __('Reserved quantity from', 'lavka-price-sync'), 'reservedMax' => __('Reserved quantity through', 'lavka-price-sync'),
        'availableMin' => __('Available quantity from', 'lavka-price-sync'), 'availableMax' => __('Available quantity through', 'lavka-price-sync'),
        'inventoryMin' => __('Minimum capital', 'lavka-price-sync'), 'inventoryMax' => __('Maximum capital', 'lavka-price-sync'),
        'revenueMin' => __('Revenue from', 'lavka-price-sync'), 'revenueMax' => __('Revenue through', 'lavka-price-sync'),
        'profitMin' => __('Gross profit from', 'lavka-price-sync'), 'profitMax' => __('Gross profit through', 'lavka-price-sync'),
        'averageCapitalMin' => __('Average capital from', 'lavka-price-sync'), 'averageCapitalMax' => __('Average capital through', 'lavka-price-sync'),
        'marginMin' => __('Gross margin from, %', 'lavka-price-sync'), 'marginMax' => __('Gross margin through, %', 'lavka-price-sync'),
        'turnsMin' => __('Inventory turns from', 'lavka-price-sync'), 'turnsMax' => __('Inventory turns through', 'lavka-price-sync'),
        'gmroiMin' => __('GMROI from', 'lavka-price-sync'), 'gmroiMax' => __('GMROI through', 'lavka-price-sync'),
        'coverageMin' => __('Stock coverage from, days', 'lavka-price-sync'), 'coverageMax' => __('Stock coverage through, days', 'lavka-price-sync'),
    ];
    foreach ($numeric as $key => $label) {
        lps_analytics_scenario_input('product_' . $key, $label, 'number', 'data-scenario-product="' . esc_attr($key) . '"', '0.01');
    }
    lps_analytics_scenario_select('product_finance_period', __('Financial period', 'lavka-price-sync'), [
        '90' => __('3 months', 'lavka-price-sync'), '365' => __('12 months', 'lavka-price-sync'),
    ], 'data-scenario-product="financePeriod"');

    $dates = [
        'lastSaleFrom' => __('Last sale from', 'lavka-price-sync'), 'lastSaleTo' => __('Last sale through', 'lavka-price-sync'),
        'lastRegularSaleFrom' => __('Last regular sale from', 'lavka-price-sync'), 'lastRegularSaleTo' => __('Last regular sale through', 'lavka-price-sync'),
        'lastReceiptFrom' => __('Last receipt from', 'lavka-price-sync'), 'lastReceiptTo' => __('Last receipt through', 'lavka-price-sync'),
        'firstMovementFrom' => __('First movement from', 'lavka-price-sync'), 'firstMovementTo' => __('First movement through', 'lavka-price-sync'),
        'lastMovementFrom' => __('Last movement from', 'lavka-price-sync'), 'lastMovementTo' => __('Last movement through', 'lavka-price-sync'),
        'alertFirstSeenFrom' => __('Alert first seen from', 'lavka-price-sync'), 'alertFirstSeenTo' => __('Alert first seen through', 'lavka-price-sync'),
        'alertLastSeenFrom' => __('Alert last confirmed from', 'lavka-price-sync'), 'alertLastSeenTo' => __('Alert last confirmed through', 'lavka-price-sync'),
    ];
    foreach ($dates as $key => $label) {
        lps_analytics_scenario_input('product_' . $key, $label, 'date', 'data-scenario-product="' . esc_attr($key) . '"');
    }
    lps_analytics_scenario_select('product_view', __('Product report view', 'lavka-price-sync'), [
        'all' => __('All products', 'lavka-price-sync'), 'data_issues' => __('Data issues', 'lavka-price-sync'),
        'stockout' => __('Stockout', 'lavka-price-sync'), 'dead_stock' => __('Dead stock', 'lavka-price-sync'),
        'overstock' => __('Overstock', 'lavka-price-sync'), 'low_margin' => __('Negative gross profit, 3 months', 'lavka-price-sync'),
        'demand_fading' => __('Demand fading', 'lavka-price-sync'), 'capital_no_sales' => __('Capital without sales', 'lavka-price-sync'),
        'leaders_revenue' => __('Revenue leaders', 'lavka-price-sync'), 'leaders_profit' => __('Profit leaders', 'lavka-price-sync'),
        'capital_efficiency' => __('Capital efficiency', 'lavka-price-sync'),
    ], 'data-scenario-product="view"');
    lps_analytics_scenario_select('product_sort', __('Product sort', 'lavka-price-sync'), [
        'inventory_value' => __('Capital in stock', 'lavka-price-sync'), 'sku' => __('SKU', 'lavka-price-sync'),
        'product_name' => __('Product', 'lavka-price-sync'), 'current_supplier' => __('Current supplier', 'lavka-price-sync'),
        'available_quantity' => __('Available quantity', 'lavka-price-sync'), 'revenue_365d' => __('Revenue, 365 days', 'lavka-price-sync'),
        'gross_profit_365d' => __('Gross profit before returns, 365 days', 'lavka-price-sync'),
    ], 'data-scenario-product="sort"');
    lps_analytics_scenario_select('product_direction', __('Sort direction', 'lavka-price-sync'), [
        'DESC' => __('Descending', 'lavka-price-sync'), 'ASC' => __('Ascending', 'lavka-price-sync'),
    ], 'data-scenario-product="direction"');
    lps_analytics_scenario_select('product_per_page', __('Rows per page', 'lavka-price-sync'), ['20' => '20', '50' => '50', '100' => '100'], 'data-scenario-product="perPage"');
}

function lps_render_analytics_scenario_movement_fields(): void {
    lps_analytics_scenario_input('movement_date_from', __('Document date from', 'lavka-price-sync'), 'date', 'data-scenario-movement="documentDateFrom"');
    lps_analytics_scenario_input('movement_date_to', __('Document date through', 'lavka-price-sync'), 'date', 'data-scenario-movement="documentDateTo"');
    lps_analytics_scenario_input('movement_sku', __('SKU', 'lavka-price-sync'), 'search', 'data-scenario-movement="movementSku"');
    lps_analytics_scenario_input('movement_document_number', __('Document number', 'lavka-price-sync'), 'search', 'data-scenario-movement="documentNumber"');
    lps_analytics_scenario_select('movement_document_type', __('Document type', 'lavka-price-sync'), ['' => __('All document types', 'lavka-price-sync')], 'id="lps-as-document-type" data-scenario-movement="documentType"');
    lps_analytics_scenario_select('movement_operation_kind', __('Folio operation kind', 'lavka-price-sync'), ['' => __('All operation kinds', 'lavka-price-sync')], 'id="lps-as-operation-kind" data-scenario-movement="operationKind"');

    $labels = lps_product_analytics_i18n()['statusLabels'];
    $class_options = ['' => __('All movement classes', 'lavka-price-sync')];
    foreach (['SALE','CUSTOMER_RETURN','SUPPLIER_RETURN','PURCHASE_RECEIPT','OTHER_RECEIPT','INTERNAL_RECEIPT','INTERNAL_EXPENSE','TRANSFER_IN','TRANSFER_OUT','ASSEMBLY_INPUT','ASSEMBLY_OUTPUT','INVENTORY_CORRECTION_IN','INVENTORY_CORRECTION_OUT','DEFECT_IN','DEFECT_OUT','INTERNAL_USE_IN','INTERNAL_USE_OUT','MARKETING_IN','MARKETING_OUT','RESERVATION','OTHER_EXPENSE','UNCLASSIFIED'] as $value) {
        $class_options[$value] = $labels[$value] ?? $value;
    }
    lps_analytics_scenario_select('movement_class', __('Movement class', 'lavka-price-sync'), $class_options, 'data-scenario-movement="movementClass"');

    $classified = [
        'stockDirection' => [__('Stock direction', 'lavka-price-sync'), ['IN','OUT','NONE']],
        'demandMode' => [__('Demand mode', 'lavka-price-sync'), ['REGULAR','ONE_OFF_ORDER','NOT_APPLICABLE']],
        'paymentTerms' => [__('Payment terms', 'lavka-price-sync'), ['PREPAYMENT','DEFERRED_30','DEFERRED_60','DEFERRED_90','DEFERRED_180','ON_FACT','NOT_SPECIFIED']],
        'customerSegment' => [__('Customer segment', 'lavka-price-sync'), ['RETAIL','NON_RETAIL','UNKNOWN','NOT_APPLICABLE']],
    ];
    foreach ($classified as $key => [$caption, $values]) {
        $options = ['' => __('All values', 'lavka-price-sync')];
        foreach ($values as $value) $options[$value] = $labels[$value] ?? $value;
        lps_analytics_scenario_select('movement_' . $key, $caption, $options, 'data-scenario-movement="' . esc_attr($key) . '"');
    }

    $booleans = [
        'accounted' => __('Included in accounting', 'lavka-price-sync'), 'returnFlag' => __('Return document', 'lavka-price-sync'),
        'affectsStock' => __('Affects stock', 'lavka-price-sync'), 'affectsFinancialSales' => __('Affects financial sales', 'lavka-price-sync'),
        'affectsPlanningDemand' => __('Affects planning demand', 'lavka-price-sync'),
    ];
    foreach ($booleans as $key => $caption) {
        lps_analytics_scenario_select('movement_' . $key, $caption, [
            '' => __('Any value', 'lavka-price-sync'), '1' => __('Yes', 'lavka-price-sync'), '0' => __('No', 'lavka-price-sync'),
        ], 'data-scenario-movement="' . esc_attr($key) . '"');
    }
    lps_analytics_scenario_input('movement_counterparty', __('Counterparty', 'lavka-price-sync'), 'search', 'data-scenario-movement="counterparty"');
    lps_analytics_scenario_input('movement_supplier', __('Current supplier', 'lavka-price-sync'), 'search', 'data-scenario-movement="movementSupplier"');
    lps_analytics_scenario_select('movement_per_page', __('Rows per page', 'lavka-price-sync'), ['20' => '20', '50' => '50', '100' => '100'], 'data-scenario-movement="movementPerPage"');
}

function lps_render_analytics_scenarios_v4_page(): void {
    $today = wp_date('Y-m-d');
    $period_from = wp_date('Y-m-d', strtotime('-12 months +1 day', current_time('timestamp')));
    ?>
    <div class="wrap lps-as lps-as-v4" id="lps-analytics-scenarios" data-analytics-schema="4">
        <div class="lps-as-heading">
            <div>
                <h1><?php echo esc_html__('Folio analytics scenarios', 'lavka-price-sync'); ?></h1>
                <p class="description"><?php echo esc_html__('Save one reusable set of warehouses, product conditions, movement conditions and report parameters.', 'lavka-price-sync'); ?></p>
            </div>
            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=' . LPS_PRODUCT_ANALYTICS_PAGE)); ?>"><?php echo esc_html__('Open product analytics', 'lavka-price-sync'); ?></a>
        </div>

        <div id="lps-as-message" class="lps-as-message" hidden aria-live="polite"></div>
        <div class="notice notice-info inline">
            <p><?php echo esc_html__('Schema v4 scenarios support several warehouses. Only filters confirmed by the selected snapshots are stored and sent to reports.', 'lavka-price-sync'); ?></p>
        </div>

        <div class="lps-as-actions">
            <button type="button" class="button button-primary" id="lps-as-new"><?php echo esc_html__('Create scenario', 'lavka-price-sync'); ?></button>
            <button type="button" class="button" id="lps-as-duplicate" disabled><?php echo esc_html__('Create a copy', 'lavka-price-sync'); ?></button>
            <button type="button" class="button" id="lps-as-archive" disabled><?php echo esc_html__('Archive', 'lavka-price-sync'); ?></button>
            <span class="spinner" id="lps-as-spinner"></span>
        </div>

        <div class="lps-as-layout">
            <aside class="lps-as-list" aria-label="<?php echo esc_attr__('Analytics scenarios', 'lavka-price-sync'); ?>"><div id="lps-as-list"></div></aside>
            <main class="lps-as-editor">
                <form id="lps-as-form">
                    <input type="hidden" id="lps-as-id" value="">
                    <input type="hidden" id="lps-as-version" value="0">
                    <input type="hidden" id="lps-as-source" value="Paint_Ua">

                    <section class="lps-as-section">
                        <h2><?php echo esc_html__('Scenario', 'lavka-price-sync'); ?></h2>
                        <div class="lps-as-grid">
                            <label class="lps-as-wide"><span><?php echo esc_html__('Name', 'lavka-price-sync'); ?></span><input type="text" id="lps-as-name" maxlength="191" required></label>
                            <label class="lps-as-wide"><span><?php echo esc_html__('Description', 'lavka-price-sync'); ?></span><textarea id="lps-as-description" rows="3"></textarea></label>
                            <label><span><?php echo esc_html__('Access', 'lavka-price-sync'); ?></span><select id="lps-as-visibility"><option value="shared"><?php echo esc_html__('Shared with managers', 'lavka-price-sync'); ?></option><option value="personal"><?php echo esc_html__('Personal', 'lavka-price-sync'); ?></option></select></label>
                            <label><span><?php echo esc_html__('Status', 'lavka-price-sync'); ?></span><select id="lps-as-status"><option value="active"><?php echo esc_html__('Active', 'lavka-price-sync'); ?></option><option value="archived"><?php echo esc_html__('Archived', 'lavka-price-sync'); ?></option></select></label>
                            <label><span><?php echo esc_html__('Default registry', 'lavka-price-sync'); ?></span><select id="lps-as-active-tab"><option value="products"><?php echo esc_html__('Products', 'lavka-price-sync'); ?></option><option value="movements"><?php echo esc_html__('Product movements', 'lavka-price-sync'); ?></option></select></label>
                        </div>
                    </section>

                    <section class="lps-as-section">
                        <h2><?php echo esc_html__('Data scope and period', 'lavka-price-sync'); ?></h2>
                        <div class="lps-as-grid">
                            <label class="lps-as-wide"><span><?php echo esc_html__('Folio warehouses', 'lavka-price-sync'); ?></span><select id="lps-as-warehouses" multiple size="7" required disabled></select></label>
                            <label><span><?php echo esc_html__('Period from', 'lavka-price-sync'); ?></span><input type="date" id="lps-as-period-from" value="<?php echo esc_attr($period_from); ?>" required></label>
                            <label><span><?php echo esc_html__('Period through', 'lavka-price-sync'); ?></span><input type="date" id="lps-as-period-to" value="<?php echo esc_attr($today); ?>" required></label>
                            <label><span><?php echo esc_html__('ABC basis', 'lavka-price-sync'); ?></span><select id="lps-as-abc-basis"><option value="GROSS_PROFIT"><?php echo esc_html__('Gross profit', 'lavka-price-sync'); ?></option><option value="REVENUE"><?php echo esc_html__('Revenue', 'lavka-price-sync'); ?></option><option value="SOLD_UNITS"><?php echo esc_html__('Sold units', 'lavka-price-sync'); ?></option></select></label>
                            <label><span><?php echo esc_html__('Rows per page', 'lavka-price-sync'); ?></span><select id="lps-as-page-size"><option value="20">20</option><option value="50" selected>50</option><option value="100">100</option><option value="250">250</option></select></label>
                            <label><span><?php echo esc_html__('Sort by', 'lavka-price-sync'); ?></span><select id="lps-as-sort-field"><option value="grossProfit"><?php echo esc_html__('Gross profit', 'lavka-price-sync'); ?></option><option value="salesRevenue"><?php echo esc_html__('Sales revenue', 'lavka-price-sync'); ?></option><option value="soldUnits"><?php echo esc_html__('Sold units', 'lavka-price-sync'); ?></option><option value="inventoryValue"><?php echo esc_html__('Capital in stock', 'lavka-price-sync'); ?></option><option value="averageInventoryValue"><?php echo esc_html__('Average inventory value', 'lavka-price-sync'); ?></option><option value="physicalQuantity"><?php echo esc_html__('Physical quantity', 'lavka-price-sync'); ?></option><option value="sku"><?php echo esc_html__('SKU', 'lavka-price-sync'); ?></option><option value="productName"><?php echo esc_html__('Product', 'lavka-price-sync'); ?></option></select></label>
                            <label><span><?php echo esc_html__('Sort direction', 'lavka-price-sync'); ?></span><select id="lps-as-sort-direction"><option value="DESC"><?php echo esc_html__('Descending', 'lavka-price-sync'); ?></option><option value="ASC"><?php echo esc_html__('Ascending', 'lavka-price-sync'); ?></option></select></label>
                            <label class="lps-as-checkbox"><input type="checkbox" id="lps-as-include-returns" checked><span><?php echo esc_html__('Include returns in the period report', 'lavka-price-sync'); ?></span></label>
                        </div>
                        <p class="description" id="lps-as-scope-meta"></p>
                    </section>

                    <details class="lps-as-section" open>
                        <summary><h2><?php echo esc_html__('Product registry conditions', 'lavka-price-sync'); ?></h2></summary>
                        <div class="lps-as-grid lps-as-filter-grid" id="lps-as-product-filter-grid">
                            <label class="lps-as-wide"><span><?php echo esc_html__('SKU, product name or primary GTIN', 'lavka-price-sync'); ?></span><input type="search" id="lps-as-search" maxlength="200"></label>
                            <div class="lps-as-selection lps-as-text-selection" data-lps-as-selection="skus" data-lps-as-section="product"><label><span><?php echo esc_html__('Exact SKUs', 'lavka-price-sync'); ?></span><textarea rows="4"></textarea></label><select class="lps-as-mode"><option value="ANY"><?php echo esc_html__('Do not apply this condition', 'lavka-price-sync'); ?></option><option value="INCLUDE"><?php echo esc_html__('Include selected values', 'lavka-price-sync'); ?></option><option value="EXCLUDE"><?php echo esc_html__('Exclude selected values', 'lavka-price-sync'); ?></option></select></div>
                            <div class="lps-as-selection lps-as-text-selection" data-lps-as-selection="barcodes" data-lps-as-section="product"><label><span><?php echo esc_html__('Exact primary GTINs', 'lavka-price-sync'); ?></span><textarea rows="4"></textarea></label><select class="lps-as-mode"><option value="ANY"><?php echo esc_html__('Do not apply this condition', 'lavka-price-sync'); ?></option><option value="INCLUDE"><?php echo esc_html__('Include selected values', 'lavka-price-sync'); ?></option><option value="EXCLUDE"><?php echo esc_html__('Exclude selected values', 'lavka-price-sync'); ?></option></select></div>
                        </div>
                    </details>

                    <details class="lps-as-section" open>
                        <summary><h2><?php echo esc_html__('Movement registry conditions', 'lavka-price-sync'); ?></h2></summary>
                        <p class="description lps-as-filter-note"><?php echo esc_html__('Movement conditions change period metrics only and never change current stock.', 'lavka-price-sync'); ?></p>
                        <div class="lps-as-grid lps-as-filter-grid" id="lps-as-movement-filter-grid"></div>
                    </details>

                    <section class="lps-as-section lps-as-revisions" id="lps-as-revisions" hidden><h2><?php echo esc_html__('Revision history', 'lavka-price-sync'); ?></h2><div id="lps-as-revision-list"></div></section>
                    <div class="lps-as-savebar"><button type="submit" class="button button-primary button-large" id="lps-as-save"><?php echo esc_html__('Save scenario', 'lavka-price-sync'); ?></button><span class="description" id="lps-as-editor-meta"></span></div>
                </form>
            </main>
        </div>
    </div>
    <?php
}

function lps_render_analytics_scenarios_page(): void {
    if (!current_user_can(LPS_CAP)) return;
    lps_render_analytics_scenarios_v4_page();
    return;
    ?>
    <div class="wrap lps-as" id="lps-analytics-scenarios">
        <div class="lps-as-heading">
            <div>
                <h1><?php echo esc_html__('Folio analytics scenarios', 'lavka-price-sync'); ?></h1>
                <p class="description"><?php echo esc_html__('Create reusable, versioned conditions for the product registry and the Folio movement registry.', 'lavka-price-sync'); ?></p>
            </div>
            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=' . LPS_PRODUCT_ANALYTICS_PAGE)); ?>"><?php echo esc_html__('Open product analytics', 'lavka-price-sync'); ?></a>
        </div>

        <div id="lps-as-message" class="lps-as-message" hidden></div>
        <div class="notice notice-info inline">
            <p><?php echo esc_html__('This stage applies one warehouse per report. The scenario format is versioned so multi-warehouse aggregation can be added without replacing saved scenarios.', 'lavka-price-sync'); ?></p>
        </div>

        <div class="lps-as-actions">
            <button type="button" class="button button-primary" id="lps-as-new"><?php echo esc_html__('Create scenario', 'lavka-price-sync'); ?></button>
            <button type="button" class="button" id="lps-as-duplicate" disabled><?php echo esc_html__('Create a copy', 'lavka-price-sync'); ?></button>
            <button type="button" class="button" id="lps-as-archive" disabled><?php echo esc_html__('Archive', 'lavka-price-sync'); ?></button>
            <span class="spinner" id="lps-as-spinner"></span>
        </div>

        <div class="lps-as-layout">
            <aside class="lps-as-list" aria-label="<?php echo esc_attr__('Analytics scenarios', 'lavka-price-sync'); ?>">
                <div id="lps-as-list"></div>
            </aside>

            <main class="lps-as-editor">
                <form id="lps-as-form">
                    <input type="hidden" id="lps-as-id" value="">
                    <input type="hidden" id="lps-as-version" value="0">

                    <section class="lps-as-section">
                        <h2><?php echo esc_html__('Scenario', 'lavka-price-sync'); ?></h2>
                        <div class="lps-as-grid">
                            <label class="lps-as-wide"><span><?php echo esc_html__('Name', 'lavka-price-sync'); ?></span><input type="text" id="lps-as-name" maxlength="191" required></label>
                            <label class="lps-as-wide"><span><?php echo esc_html__('Description', 'lavka-price-sync'); ?></span><textarea id="lps-as-description" rows="3"></textarea></label>
                            <label><span><?php echo esc_html__('Access', 'lavka-price-sync'); ?></span><select id="lps-as-visibility"><option value="shared"><?php echo esc_html__('Shared with managers', 'lavka-price-sync'); ?></option><option value="personal"><?php echo esc_html__('Personal', 'lavka-price-sync'); ?></option></select></label>
                            <label><span><?php echo esc_html__('Status', 'lavka-price-sync'); ?></span><select id="lps-as-status"><option value="active"><?php echo esc_html__('Active', 'lavka-price-sync'); ?></option><option value="archived"><?php echo esc_html__('Archived', 'lavka-price-sync'); ?></option></select></label>
                            <label><span><?php echo esc_html__('Default registry', 'lavka-price-sync'); ?></span><select id="lps-as-active-tab"><option value="products"><?php echo esc_html__('Products', 'lavka-price-sync'); ?></option><option value="movements"><?php echo esc_html__('Product movements', 'lavka-price-sync'); ?></option></select></label>
                        </div>
                    </section>

                    <section class="lps-as-section">
                        <h2><?php echo esc_html__('Data scope', 'lavka-price-sync'); ?></h2>
                        <div class="lps-as-grid">
                            <label class="lps-as-wide"><span><?php echo esc_html__('Folio warehouse', 'lavka-price-sync'); ?></span><select id="lps-as-scope" required><option value=""><?php echo esc_html__('Loading snapshots...', 'lavka-price-sync'); ?></option></select></label>
                        </div>
                        <p class="description" id="lps-as-scope-meta"></p>
                    </section>

                    <details class="lps-as-section" open>
                        <summary><h2><?php echo esc_html__('Product registry conditions', 'lavka-price-sync'); ?></h2></summary>
                        <div class="lps-as-grid lps-as-filter-grid"><?php lps_render_analytics_scenario_product_fields(); ?></div>
                    </details>

                    <details class="lps-as-section" open>
                        <summary><h2><?php echo esc_html__('Movement registry conditions', 'lavka-price-sync'); ?></h2></summary>
                        <div class="lps-as-grid lps-as-filter-grid"><?php lps_render_analytics_scenario_movement_fields(); ?></div>
                    </details>

                    <section class="lps-as-section lps-as-revisions" id="lps-as-revisions" hidden>
                        <h2><?php echo esc_html__('Revision history', 'lavka-price-sync'); ?></h2>
                        <div id="lps-as-revision-list"></div>
                    </section>

                    <div class="lps-as-savebar">
                        <button type="submit" class="button button-primary button-large" id="lps-as-save"><?php echo esc_html__('Save scenario', 'lavka-price-sync'); ?></button>
                        <span class="description" id="lps-as-editor-meta"></span>
                    </div>
                </form>
            </main>
        </div>
    </div>
    <?php
}
