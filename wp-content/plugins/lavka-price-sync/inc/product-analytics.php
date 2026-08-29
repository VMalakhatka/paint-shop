<?php
if (!defined('ABSPATH')) exit;

const LPS_PRODUCT_ANALYTICS_PAGE = 'lps-product-analytics';
const LPS_PRODUCT_ANALYTICS_NONCE = 'lps_product_analytics';

add_action('admin_menu', function (): void {
    add_submenu_page(
        'lps-main',
        __('Folio product analytics', 'lavka-price-sync'),
        __('Product analytics', 'lavka-price-sync'),
        LPS_CAP,
        LPS_PRODUCT_ANALYTICS_PAGE,
        'lps_render_product_analytics_page'
    );
}, 21);

add_action('admin_enqueue_scripts', function (): void {
    $page = sanitize_key(wp_unslash($_GET['page'] ?? ''));
    if ($page !== LPS_PRODUCT_ANALYTICS_PAGE) return;

    $plugin_file = dirname(__DIR__) . '/lavka-price-sync.php';
    $css_path = dirname(__DIR__) . '/assets/product-analytics.css';
    $js_path = dirname(__DIR__) . '/assets/product-analytics.js';

    wp_enqueue_style(
        'lps-product-analytics',
        plugins_url('assets/product-analytics.css', $plugin_file),
        [],
        @filemtime($css_path) ?: '1.0'
    );
    wp_enqueue_script(
        'lps-product-analytics',
        plugins_url('assets/product-analytics.js', $plugin_file),
        [],
        @filemtime($js_path) ?: '1.0',
        true
    );

    $currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'UAH';
    wp_localize_script('lps-product-analytics', 'LPS_PRODUCT_ANALYTICS', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce(LPS_PRODUCT_ANALYTICS_NONCE),
        'currency' => $currency,
        'locale' => str_replace('_', '-', determine_locale()),
        'i18n' => lps_product_analytics_i18n(),
    ]);
});

/**
 * User-facing strings are kept in PHP so they remain discoverable by WP i18n.
 */
function lps_product_analytics_i18n(): array {
    return [
        'loading' => __('Loading product analytics...', 'lavka-price-sync'),
        'loadFailed' => __('Product analytics could not be loaded.', 'lavka-price-sync'),
        'noSnapshots' => __('No active Folio product snapshots are available yet.', 'lavka-price-sync'),
        'noProducts' => __('No products match the selected filters.', 'lavka-price-sync'),
        'selectWarehouse' => __('Select a warehouse', 'lavka-price-sync'),
        'warehouse' => __('Warehouse', 'lavka-price-sync'),
        'products' => __('Products', 'lavka-price-sync'),
        'physicalQuantity' => __('Physical quantity', 'lavka-price-sync'),
        'reservedQuantity' => __('Reserved quantity', 'lavka-price-sync'),
        'availableQuantity' => __('Available quantity', 'lavka-price-sync'),
        'accountingPrice' => __('Accounting price', 'lavka-price-sync'),
        'inventoryValue' => __('Capital in stock', 'lavka-price-sync'),
        'revenue365' => __('Revenue, 365 days', 'lavka-price-sync'),
        'grossProfit365' => __('Gross profit before returns, 365 days', 'lavka-price-sync'),
        'capitalWithoutSales' => __('Capital without sales', 'lavka-price-sync'),
        'riskCapital' => __('Capital in dead stock and overstock', 'lavka-price-sync'),
        'sku' => __('SKU', 'lavka-price-sync'),
        'product' => __('Product', 'lavka-price-sync'),
        'sales90' => __('Commercial sales total, 90 days', 'lavka-price-sync'),
        'sales365' => __('Commercial sales total, 365 days', 'lavka-price-sync'),
        'turns' => __('Inventory turns', 'lavka-price-sync'),
        'gmroi' => __('GMROI', 'lavka-price-sync'),
        'coverage' => __('Coverage, days', 'lavka-price-sync'),
        'lastSale' => __('Last sale', 'lavka-price-sync'),
        'lastReceipt' => __('Last receipt', 'lavka-price-sync'),
        'status' => __('Status', 'lavka-price-sync'),
        'verification' => __('Accounting-price verification', 'lavka-price-sync'),
        'alerts' => __('Alerts', 'lavka-price-sync'),
        'page' => __('Page', 'lavka-price-sync'),
        'of' => __('of', 'lavka-price-sync'),
        'details' => __('Product details', 'lavka-price-sync'),
        'currentState' => __('Current state', 'lavka-price-sync'),
        'monthlyHistory' => __('Monthly history', 'lavka-price-sync'),
        'warehouseComparison' => __('Warehouse comparison', 'lavka-price-sync'),
        'alertHistory' => __('Alert history', 'lavka-price-sync'),
        'changeHistory' => __('Snapshot changes', 'lavka-price-sync'),
        'month' => __('Month', 'lavka-price-sync'),
        'openingStock' => __('Opening stock', 'lavka-price-sync'),
        'closingStock' => __('Closing stock', 'lavka-price-sync'),
        'openingInventoryValue' => __('Opening inventory value', 'lavka-price-sync'),
        'closingInventoryValue' => __('Closing inventory value', 'lavka-price-sync'),
        'receipts' => __('Receipts', 'lavka-price-sync'),
        'receiptCost' => __('Receipt cost', 'lavka-price-sync'),
        'sales' => __('Commercial sales total', 'lavka-price-sync'),
        'revenue' => __('Revenue', 'lavka-price-sync'),
        'cogs' => __('Cost of sales', 'lavka-price-sync'),
        'grossProfit' => __('Gross profit before returns', 'lavka-price-sync'),
        'returns' => __('Returns', 'lavka-price-sync'),
        'returnRevenue' => __('Return revenue', 'lavka-price-sync'),
        'averageCapital' => __('Average capital', 'lavka-price-sync'),
        'sellThrough' => __('Sell-through', 'lavka-price-sync'),
        'possibleTransfer' => __('Possible cross-warehouse analysis', 'lavka-price-sync'),
        'possibleTransferHelp' => __('Another warehouse has available stock. A manager must check the safe transfer quantity.', 'lavka-price-sync'),
        'close' => __('Close', 'lavka-price-sync'),
        'retry' => __('Retry', 'lavka-price-sync'),
        'dataAsOf' => __('Active snapshot', 'lavka-price-sync'),
        'approximation' => __('Operational analytics: period metrics are built from monthly buckets.', 'lavka-price-sync'),
        'allCommercialSales' => __('Sales combine all confirmed external commercial channels.', 'lavka-price-sync'),
        'grossBeforeReturns' => __('Profit is gross profit before returns, not net or operating profit.', 'lavka-price-sync'),
        'noSuppliers' => __('No suppliers are available for this warehouse.', 'lavka-price-sync'),
        'statusLabels' => [
            'HEALTHY' => __('Healthy', 'lavka-price-sync'),
            'STOCKOUT' => __('Stockout', 'lavka-price-sync'),
            'DEAD_STOCK' => __('Dead stock', 'lavka-price-sync'),
            'OVERSTOCK' => __('Overstock', 'lavka-price-sync'),
            'LOW_MARGIN' => __('Low or negative margin', 'lavka-price-sync'),
            'DEMAND_FADING' => __('Demand fading', 'lavka-price-sync'),
            'DATA_ISSUE' => __('Data issue', 'lavka-price-sync'),
            'NEW' => __('New product', 'lavka-price-sync'),
            'UNVERIFIED' => __('Unverified', 'lavka-price-sync'),
            'VERIFIED' => __('Verified', 'lavka-price-sync'),
            'DIRTY' => __('Changed after verification', 'lavka-price-sync'),
            'FAILED' => __('Verification failed', 'lavka-price-sync'),
            'REMOVED' => __('Removed from Folio', 'lavka-price-sync'),
        ],
    ];
}

function lps_render_product_analytics_page(): void {
    if (!current_user_can(LPS_CAP)) return;
    ?>
    <div class="wrap lps-pa" id="lps-product-analytics">
        <div class="lps-pa-heading">
            <div>
                <h1><?php echo esc_html__('Folio product analytics', 'lavka-price-sync'); ?></h1>
                <p class="description"><?php echo esc_html__('Read-only stock, capital and commercial-sales analytics from published Folio snapshots.', 'lavka-price-sync'); ?></p>
            </div>
            <div class="lps-pa-snapshot" id="lps-pa-snapshot"></div>
        </div>

        <div class="notice notice-info inline lps-pa-boundaries">
            <p><strong><?php echo esc_html__('Current reporting boundaries', 'lavka-price-sync'); ?></strong></p>
            <p><?php echo esc_html__('Sales are commercial sales total. Profit is gross profit before returns. Coverage is an analytical signal, not a purchase or transfer order.', 'lavka-price-sync'); ?></p>
        </div>

        <div class="lps-pa-toolbar">
            <label for="lps-pa-scope"><strong><?php echo esc_html__('Folio warehouse', 'lavka-price-sync'); ?></strong></label>
            <select id="lps-pa-scope" disabled>
                <option value=""><?php echo esc_html__('Loading snapshots...', 'lavka-price-sync'); ?></option>
            </select>
            <button type="button" class="button" id="lps-pa-reload"><?php echo esc_html__('Reload report', 'lavka-price-sync'); ?></button>
            <span class="spinner" id="lps-pa-spinner"></span>
        </div>

        <div id="lps-pa-message" class="lps-pa-message" hidden></div>
        <section id="lps-pa-summary" class="lps-pa-summary" aria-label="<?php echo esc_attr__('Warehouse summary', 'lavka-price-sync'); ?>"></section>

        <nav class="lps-pa-views" aria-label="<?php echo esc_attr__('Saved analytics views', 'lavka-price-sync'); ?>">
            <?php
            $views = [
                'all' => __('All products', 'lavka-price-sync'),
                'data_issues' => __('Data issues', 'lavka-price-sync'),
                'stockout' => __('Stockout', 'lavka-price-sync'),
                'dead_stock' => __('Dead stock', 'lavka-price-sync'),
                'overstock' => __('Overstock', 'lavka-price-sync'),
                'low_margin' => __('Low margin', 'lavka-price-sync'),
                'demand_fading' => __('Demand fading', 'lavka-price-sync'),
                'capital_no_sales' => __('Capital without sales', 'lavka-price-sync'),
                'leaders_revenue' => __('Revenue leaders', 'lavka-price-sync'),
                'leaders_profit' => __('Profit leaders', 'lavka-price-sync'),
                'capital_efficiency' => __('Capital efficiency', 'lavka-price-sync'),
            ];
            foreach ($views as $key => $label):
            ?>
                <button type="button" class="button<?php echo $key === 'all' ? ' button-primary' : ''; ?>" data-lps-pa-view="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></button>
            <?php endforeach; ?>
        </nav>

        <form id="lps-pa-filters" class="lps-pa-filters">
            <label>
                <span><?php echo esc_html__('SKU or product name', 'lavka-price-sync'); ?></span>
                <input type="search" name="search" autocomplete="off">
            </label>
            <label>
                <span><?php echo esc_html__('Economic status', 'lavka-price-sync'); ?></span>
                <select name="health">
                    <option value=""><?php echo esc_html__('All statuses', 'lavka-price-sync'); ?></option>
                    <option value="HEALTHY"><?php echo esc_html__('Healthy', 'lavka-price-sync'); ?></option>
                    <option value="STOCKOUT"><?php echo esc_html__('Stockout', 'lavka-price-sync'); ?></option>
                    <option value="DEAD_STOCK"><?php echo esc_html__('Dead stock', 'lavka-price-sync'); ?></option>
                    <option value="OVERSTOCK"><?php echo esc_html__('Overstock', 'lavka-price-sync'); ?></option>
                    <option value="LOW_MARGIN"><?php echo esc_html__('Low or negative margin', 'lavka-price-sync'); ?></option>
                    <option value="DEMAND_FADING"><?php echo esc_html__('Demand fading', 'lavka-price-sync'); ?></option>
                    <option value="DATA_ISSUE"><?php echo esc_html__('Data issue', 'lavka-price-sync'); ?></option>
                    <option value="NEW"><?php echo esc_html__('New product', 'lavka-price-sync'); ?></option>
                </select>
            </label>
            <label>
                <span><?php echo esc_html__('Verification state', 'lavka-price-sync'); ?></span>
                <select name="verification">
                    <option value=""><?php echo esc_html__('All states', 'lavka-price-sync'); ?></option>
                    <option value="UNVERIFIED"><?php echo esc_html__('Unverified', 'lavka-price-sync'); ?></option>
                    <option value="VERIFIED"><?php echo esc_html__('Verified', 'lavka-price-sync'); ?></option>
                    <option value="DIRTY"><?php echo esc_html__('Changed after verification', 'lavka-price-sync'); ?></option>
                    <option value="NEW"><?php echo esc_html__('New product', 'lavka-price-sync'); ?></option>
                    <option value="FAILED"><?php echo esc_html__('Verification failed', 'lavka-price-sync'); ?></option>
                </select>
            </label>
            <label>
                <span><?php echo esc_html__('Active alert', 'lavka-price-sync'); ?></span>
                <select name="alertCode">
                    <option value=""><?php echo esc_html__('All alerts', 'lavka-price-sync'); ?></option>
                    <option value="DATA_ISSUE"><?php echo esc_html__('Data issue', 'lavka-price-sync'); ?></option>
                    <option value="STOCKOUT"><?php echo esc_html__('Stockout', 'lavka-price-sync'); ?></option>
                    <option value="LOW_MARGIN"><?php echo esc_html__('Low or negative margin', 'lavka-price-sync'); ?></option>
                    <option value="OVERSTOCK"><?php echo esc_html__('Overstock', 'lavka-price-sync'); ?></option>
                    <option value="DEAD_STOCK"><?php echo esc_html__('Dead stock', 'lavka-price-sync'); ?></option>
                    <option value="DEMAND_FADING"><?php echo esc_html__('Demand fading', 'lavka-price-sync'); ?></option>
                </select>
            </label>
            <label>
                <span><?php echo esc_html__('Alert severity', 'lavka-price-sync'); ?></span>
                <select name="severity">
                    <option value=""><?php echo esc_html__('All severities', 'lavka-price-sync'); ?></option>
                    <option value="ERROR"><?php echo esc_html__('Error', 'lavka-price-sync'); ?></option>
                    <option value="HIGH"><?php echo esc_html__('High', 'lavka-price-sync'); ?></option>
                    <option value="MEDIUM"><?php echo esc_html__('Medium', 'lavka-price-sync'); ?></option>
                    <option value="LOW"><?php echo esc_html__('Low', 'lavka-price-sync'); ?></option>
                </select>
            </label>
            <label>
                <span><?php echo esc_html__('Sales, 365 days', 'lavka-price-sync'); ?></span>
                <select name="sales">
                    <option value=""><?php echo esc_html__('Any sales', 'lavka-price-sync'); ?></option>
                    <option value="with"><?php echo esc_html__('With sales', 'lavka-price-sync'); ?></option>
                    <option value="without"><?php echo esc_html__('Without sales', 'lavka-price-sync'); ?></option>
                </select>
            </label>
            <div class="lps-pa-supplier-filter">
                <label>
                    <span><?php echo esc_html__('Current supplier filter', 'lavka-price-sync'); ?></span>
                    <select name="supplierMode" id="lps-pa-supplier-mode">
                        <option value="ANY"><?php echo esc_html__('Do not filter by supplier', 'lavka-price-sync'); ?></option>
                        <option value="INCLUDE"><?php echo esc_html__('Include selected suppliers', 'lavka-price-sync'); ?></option>
                        <option value="EXCLUDE"><?php echo esc_html__('Exclude selected suppliers', 'lavka-price-sync'); ?></option>
                    </select>
                </label>
                <label>
                    <span><?php echo esc_html__('Suppliers', 'lavka-price-sync'); ?></span>
                    <select name="supplierValues[]" id="lps-pa-suppliers" multiple size="4" disabled></select>
                </label>
                <small><?php echo esc_html__('Select one or more suppliers. The mode controls whether they are included in or excluded from the product registry.', 'lavka-price-sync'); ?></small>
            </div>
            <label>
                <span><?php echo esc_html__('Minimum capital', 'lavka-price-sync'); ?></span>
                <input type="number" name="inventoryMin" min="0" step="0.01">
            </label>
            <label>
                <span><?php echo esc_html__('Maximum capital', 'lavka-price-sync'); ?></span>
                <input type="number" name="inventoryMax" min="0" step="0.01">
            </label>
            <label>
                <span><?php echo esc_html__('Last sale from', 'lavka-price-sync'); ?></span>
                <input type="date" name="lastSaleFrom">
            </label>
            <label>
                <span><?php echo esc_html__('Last sale through', 'lavka-price-sync'); ?></span>
                <input type="date" name="lastSaleTo">
            </label>
            <label>
                <span><?php echo esc_html__('Rows per page', 'lavka-price-sync'); ?></span>
                <select name="perPage">
                    <option value="20">20</option>
                    <option value="50" selected>50</option>
                    <option value="100">100</option>
                </select>
            </label>
            <div class="lps-pa-filter-actions">
                <button type="submit" class="button button-primary"><?php echo esc_html__('Apply filters', 'lavka-price-sync'); ?></button>
                <button type="button" class="button" id="lps-pa-reset"><?php echo esc_html__('Reset', 'lavka-price-sync'); ?></button>
            </div>
        </form>

        <section class="lps-pa-registry">
            <div class="lps-pa-table-meta" id="lps-pa-table-meta"></div>
            <div class="lps-pa-table-scroll">
                <table class="widefat striped" id="lps-pa-products">
                    <thead><tr id="lps-pa-products-head"></tr></thead>
                    <tbody></tbody>
                </table>
            </div>
            <div class="tablenav bottom lps-pa-pagination" id="lps-pa-pagination"></div>
        </section>

        <aside class="lps-pa-detail" id="lps-pa-detail" hidden aria-labelledby="lps-pa-detail-title">
            <div class="lps-pa-detail-backdrop" data-lps-pa-close></div>
            <div class="lps-pa-detail-panel" role="dialog" aria-modal="true">
                <button type="button" class="button lps-pa-detail-close" data-lps-pa-close><?php echo esc_html__('Close', 'lavka-price-sync'); ?></button>
                <div id="lps-pa-detail-content"></div>
            </div>
        </aside>
    </div>
    <?php
}

function lps_product_analytics_tables(): array {
    return [
        'generation' => 'folio_product_snapshot_generation',
        'item' => 'folio_product_snapshot_item',
        'change' => 'folio_product_snapshot_change',
        'current' => 'folio_product_metric_current',
        'monthly' => 'folio_product_metric_monthly',
        'alert' => 'folio_product_metric_alert',
    ];
}

function lps_product_analytics_missing_tables(): array {
    global $wpdb;
    $missing = [];
    foreach (lps_product_analytics_tables() as $table) {
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        if ((string)$found !== $table) $missing[] = $table;
    }
    return $missing;
}

function lps_product_analytics_scope(): array {
    $source = sanitize_text_field(wp_unslash($_POST['sourceDatabase'] ?? ''));
    $warehouse = absint($_POST['warehouseId'] ?? 0);
    if ($source === '' || $warehouse < 1) {
        wp_send_json_error(['message' => __('A Folio source database and warehouse are required.', 'lavka-price-sync')], 400);
    }
    return [$source, $warehouse];
}

function lps_product_analytics_prepare(string $sql, array $args): string {
    global $wpdb;
    return $args ? $wpdb->prepare($sql, $args) : $sql;
}

function lps_product_analytics_scopes(): array {
    global $wpdb;
    $t = lps_product_analytics_tables();
    $sql = "
        SELECT g.id, g.source_database, g.warehouse_id, g.horizon_months,
               g.started_at, g.completed_at, g.total_products,
               g.monthly_metric_rows, g.unverified_products, g.dirty_products,
               g.new_products, g.removed_products
          FROM {$t['generation']} g
          JOIN (
                SELECT source_database, warehouse_id, MAX(id) AS id
                  FROM {$t['generation']}
                 WHERE status = 'ACTIVE'
                 GROUP BY source_database, warehouse_id
          ) latest ON latest.id = g.id
         ORDER BY g.source_database, g.warehouse_id
    ";
    return $wpdb->get_results($sql, ARRAY_A) ?: [];
}

function lps_product_analytics_filter_options(string $source, int $warehouse): array {
    global $wpdb;
    $t = lps_product_analytics_tables();
    $suppliers = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT TRIM(current_supplier)
           FROM {$t['current']}
          WHERE source_database=%s AND warehouse_id=%d
            AND current_supplier IS NOT NULL AND TRIM(current_supplier)<>''
          ORDER BY TRIM(current_supplier)",
        [$source, $warehouse]
    )) ?: [];

    return ['suppliers' => array_values(array_map('strval', $suppliers))];
}

function lps_product_analytics_supplier_filter(): array {
    $mode = strtoupper(sanitize_key(wp_unslash($_POST['supplierMode'] ?? 'ANY')));
    if (!in_array($mode, ['INCLUDE', 'EXCLUDE'], true)) $mode = 'ANY';

    $raw = (string)wp_unslash($_POST['supplierValues'] ?? '');
    $values = preg_split('/[\r\n]+/', $raw) ?: [];
    $values = array_values(array_unique(array_filter(array_map(
        static fn($value): string => trim(sanitize_text_field($value)),
        $values
    ), static fn(string $value): bool => $value !== '')));

    return [$mode, array_slice($values, 0, 100)];
}

function lps_product_analytics_summary(string $source, int $warehouse): array {
    global $wpdb;
    $t = lps_product_analytics_tables();
    $scope = [$source, $warehouse];
    $generation = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$t['generation']} WHERE source_database=%s AND warehouse_id=%d AND status='ACTIVE' ORDER BY id DESC LIMIT 1",
        $scope
    ), ARRAY_A);

    $totals = $wpdb->get_row($wpdb->prepare(
        "SELECT COUNT(*) AS products,
                COALESCE(SUM(m.physical_quantity),0) AS physical_quantity,
                COALESCE(SUM(m.reserved_quantity),0) AS reserved_quantity,
                COALESCE(SUM(m.available_quantity),0) AS available_quantity,
                COALESCE(SUM(m.inventory_value),0) AS inventory_value,
                COALESCE(SUM(m.revenue_365d),0) AS revenue_365d,
                COALESCE(SUM(m.gross_profit_365d),0) AS gross_profit_365d,
                COALESCE(SUM(CASE WHEN m.sold_units_365d=0 AND m.inventory_value>0 THEN m.inventory_value ELSE 0 END),0) AS capital_without_sales,
                COALESCE(SUM(CASE WHEN m.health_status IN ('DEAD_STOCK','OVERSTOCK') THEN m.inventory_value ELSE 0 END),0) AS risk_capital
           FROM {$t['current']} m
           JOIN {$t['item']} i
             ON i.source_database=m.source_database AND i.warehouse_id=m.warehouse_id AND i.sku=m.sku
          WHERE m.source_database=%s AND m.warehouse_id=%d AND i.present_in_folio=1",
        $scope
    ), ARRAY_A) ?: [];

    $alerts = $wpdb->get_results($wpdb->prepare(
        "SELECT alert_code, severity, COUNT(*) AS products
           FROM {$t['alert']}
          WHERE source_database=%s AND warehouse_id=%d AND status='ACTIVE'
          GROUP BY alert_code, severity
          ORDER BY FIELD(severity,'ERROR','HIGH','MEDIUM','LOW'), alert_code",
        $scope
    ), ARRAY_A) ?: [];

    $verification = $wpdb->get_results($wpdb->prepare(
        "SELECT verification_state, COUNT(*) AS products
           FROM {$t['item']}
          WHERE source_database=%s AND warehouse_id=%d AND present_in_folio=1
          GROUP BY verification_state ORDER BY verification_state",
        $scope
    ), ARRAY_A) ?: [];

    return compact('generation', 'totals', 'alerts', 'verification');
}

function lps_product_analytics_products(string $source, int $warehouse): array {
    global $wpdb;
    $t = lps_product_analytics_tables();
    $allowed_health = ['HEALTHY','STOCKOUT','DEAD_STOCK','OVERSTOCK','LOW_MARGIN','DEMAND_FADING','DATA_ISSUE','NEW'];
    $allowed_verification = ['UNVERIFIED','VERIFIED','DIRTY','NEW','FAILED','REMOVED'];
    $allowed_alerts = ['DATA_ISSUE','STOCKOUT','LOW_MARGIN','OVERSTOCK','DEAD_STOCK','DEMAND_FADING'];
    $allowed_severities = ['ERROR','HIGH','MEDIUM','LOW'];
    $allowed_sorts = [
        'sku' => 'm.sku', 'product_name' => 'm.product_name',
        'inventory_value' => 'm.inventory_value', 'sold_units_365d' => 'm.sold_units_365d',
        'revenue_365d' => 'm.revenue_365d', 'gross_profit_365d' => 'm.gross_profit_365d',
        'inventory_turns_365d' => 'm.inventory_turns_365d', 'gmroi_365d' => 'm.gmroi_365d',
        'coverage_days' => 'm.coverage_days', 'last_sale_date' => 'm.last_sale_date',
    ];

    $search = trim(sanitize_text_field(wp_unslash($_POST['search'] ?? '')));
    $health = strtoupper(sanitize_key(wp_unslash($_POST['health'] ?? '')));
    $verification = strtoupper(sanitize_key(wp_unslash($_POST['verification'] ?? '')));
    $alert = strtoupper(sanitize_key(wp_unslash($_POST['alertCode'] ?? '')));
    $severity = strtoupper(sanitize_key(wp_unslash($_POST['severity'] ?? '')));
    $sales = sanitize_key(wp_unslash($_POST['sales'] ?? ''));
    $view = sanitize_key(wp_unslash($_POST['view'] ?? 'all'));
    $sort = sanitize_key(wp_unslash($_POST['sort'] ?? 'inventory_value'));
    $direction = strtoupper(sanitize_key(wp_unslash($_POST['direction'] ?? 'DESC'))) === 'ASC' ? 'ASC' : 'DESC';
    $page = max(1, absint($_POST['page'] ?? 1));
    $per_page = absint($_POST['perPage'] ?? 50);
    if (!in_array($per_page, [20, 50, 100], true)) $per_page = 50;
    $inventory_min_raw = trim((string)wp_unslash($_POST['inventoryMin'] ?? ''));
    $inventory_max_raw = trim((string)wp_unslash($_POST['inventoryMax'] ?? ''));
    $last_sale_from = sanitize_text_field(wp_unslash($_POST['lastSaleFrom'] ?? ''));
    $last_sale_to = sanitize_text_field(wp_unslash($_POST['lastSaleTo'] ?? ''));
    [$supplier_mode, $supplier_values] = lps_product_analytics_supplier_filter();

    $view_rules = [
        'data_issues' => ['alert' => 'DATA_ISSUE', 'sort' => 'inventory_value', 'direction' => 'DESC'],
        'stockout' => ['alert' => 'STOCKOUT', 'sort' => 'gross_profit_365d', 'direction' => 'DESC'],
        'dead_stock' => ['alert' => 'DEAD_STOCK', 'sort' => 'inventory_value', 'direction' => 'DESC'],
        'overstock' => ['alert' => 'OVERSTOCK', 'sort' => 'inventory_value', 'direction' => 'DESC'],
        'low_margin' => ['alert' => 'LOW_MARGIN', 'sort' => 'gross_profit_365d', 'direction' => 'ASC'],
        'demand_fading' => ['alert' => 'DEMAND_FADING', 'sort' => 'inventory_value', 'direction' => 'DESC'],
        'capital_no_sales' => ['sales' => 'without', 'sort' => 'inventory_value', 'direction' => 'DESC'],
        'leaders_revenue' => ['sort' => 'revenue_365d', 'direction' => 'DESC'],
        'leaders_profit' => ['sort' => 'gross_profit_365d', 'direction' => 'DESC'],
        'capital_efficiency' => ['sort' => 'gmroi_365d', 'direction' => 'ASC'],
    ];
    if (isset($view_rules[$view])) {
        $rule = $view_rules[$view];
        $alert = $rule['alert'] ?? $alert;
        $sales = $rule['sales'] ?? $sales;
        $sort = $rule['sort'];
        $direction = $rule['direction'];
    }

    $where = ['m.source_database=%s', 'm.warehouse_id=%d', 'i.present_in_folio=1'];
    $args = [$source, $warehouse];
    if ($search !== '') {
        $like = '%' . $wpdb->esc_like($search) . '%';
        $where[] = '(m.sku LIKE %s OR m.product_name LIKE %s)';
        $args[] = $like; $args[] = $like;
    }
    if (in_array($health, $allowed_health, true)) {
        $where[] = 'm.health_status=%s'; $args[] = $health;
    }
    if (in_array($verification, $allowed_verification, true)) {
        $where[] = 'i.verification_state=%s'; $args[] = $verification;
    }
    if ($sales === 'with') $where[] = 'm.sold_units_365d>0';
    if ($sales === 'without') $where[] = 'm.sold_units_365d=0 AND m.inventory_value>0';
    if ($inventory_min_raw !== '' && is_numeric($inventory_min_raw)) {
        $where[] = 'm.inventory_value>=%f'; $args[] = max(0, (float)$inventory_min_raw);
    }
    if ($inventory_max_raw !== '' && is_numeric($inventory_max_raw)) {
        $where[] = 'm.inventory_value<=%f'; $args[] = max(0, (float)$inventory_max_raw);
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $last_sale_from)) {
        $where[] = 'm.last_sale_date>=%s'; $args[] = $last_sale_from;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $last_sale_to)) {
        $where[] = 'm.last_sale_date<=%s'; $args[] = $last_sale_to;
    }
    if ($supplier_mode !== 'ANY' && $supplier_values) {
        $placeholders = implode(',', array_fill(0, count($supplier_values), '%s'));
        $where[] = $supplier_mode === 'INCLUDE'
            ? "m.current_supplier IN ({$placeholders})"
            : "COALESCE(m.current_supplier,'') NOT IN ({$placeholders})";
        array_push($args, ...$supplier_values);
    }
    $alert_filters = ["af.status='ACTIVE'"];
    $alert_args = [];
    if (in_array($alert, $allowed_alerts, true)) {
        $alert_filters[] = 'af.alert_code=%s'; $alert_args[] = $alert;
    }
    if (in_array($severity, $allowed_severities, true)) {
        $alert_filters[] = 'af.severity=%s'; $alert_args[] = $severity;
    }
    if ($alert_args) {
        $where[] = "EXISTS (SELECT 1 FROM {$t['alert']} af WHERE af.source_database=m.source_database AND af.warehouse_id=m.warehouse_id AND af.sku=m.sku AND " . implode(' AND ', $alert_filters) . ')';
        array_push($args, ...$alert_args);
    }

    $where_sql = implode(' AND ', $where);
    $sort_sql = $allowed_sorts[$sort] ?? $allowed_sorts['inventory_value'];
    $order_sql = $view === 'capital_efficiency'
        ? 'm.gmroi_365d IS NULL ASC, m.gmroi_365d ASC, m.inventory_value DESC, m.sku ASC'
        : "{$sort_sql} {$direction}, m.sku ASC";
    $count_sql = "SELECT COUNT(*) FROM {$t['current']} m JOIN {$t['item']} i ON i.source_database=m.source_database AND i.warehouse_id=m.warehouse_id AND i.sku=m.sku WHERE {$where_sql}";
    $total = (int)$wpdb->get_var(lps_product_analytics_prepare($count_sql, $args));
    $pages = max(1, (int)ceil($total / $per_page));
    $page = min($page, $pages);
    $offset = ($page - 1) * $per_page;

    $list_sql = "
        SELECT m.*, i.verification_state, i.last_error,
               COALESCE(aa.active_alerts,'') AS active_alerts
          FROM {$t['current']} m
          JOIN {$t['item']} i
            ON i.source_database=m.source_database AND i.warehouse_id=m.warehouse_id AND i.sku=m.sku
          LEFT JOIN (
                SELECT source_database, warehouse_id, sku,
                       GROUP_CONCAT(CONCAT(alert_code, ':', severity) ORDER BY severity, alert_code SEPARATOR ',') AS active_alerts
                  FROM {$t['alert']} WHERE status='ACTIVE'
                 GROUP BY source_database, warehouse_id, sku
          ) aa ON aa.source_database=m.source_database AND aa.warehouse_id=m.warehouse_id AND aa.sku=m.sku
         WHERE {$where_sql}
         ORDER BY {$order_sql}
         LIMIT %d OFFSET %d
    ";
    $list_args = array_merge($args, [$per_page, $offset]);
    $items = $wpdb->get_results(lps_product_analytics_prepare($list_sql, $list_args), ARRAY_A) ?: [];
    return compact('items', 'total', 'page', 'pages', 'per_page', 'sort', 'direction', 'view');
}

function lps_product_analytics_product(string $source, int $warehouse): array {
    global $wpdb;
    $t = lps_product_analytics_tables();
    $sku = trim(sanitize_text_field(wp_unslash($_POST['sku'] ?? '')));
    if ($sku === '') wp_send_json_error(['message' => __('A product SKU is required.', 'lavka-price-sync')], 400);

    $current = $wpdb->get_row($wpdb->prepare(
        "SELECT m.*, i.verification_state, i.present_in_folio, i.first_seen_at, i.last_seen_at, i.last_observed_at, i.last_error
           FROM {$t['current']} m JOIN {$t['item']} i
             ON i.source_database=m.source_database AND i.warehouse_id=m.warehouse_id AND i.sku=m.sku
          WHERE m.source_database=%s AND m.warehouse_id=%d AND m.sku=%s LIMIT 1",
        [$source, $warehouse, $sku]
    ), ARRAY_A);
    if (!$current) wp_send_json_error(['message' => __('The requested product was not found in this snapshot.', 'lavka-price-sync')], 404);

    $monthly = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$t['monthly']} WHERE source_database=%s AND warehouse_id=%d AND sku=%s ORDER BY month_start ASC",
        [$source, $warehouse, $sku]
    ), ARRAY_A) ?: [];
    $alerts = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$t['alert']} WHERE source_database=%s AND warehouse_id=%d AND sku=%s ORDER BY status='ACTIVE' DESC, last_seen_at DESC",
        [$source, $warehouse, $sku]
    ), ARRAY_A) ?: [];
    $changes = $wpdb->get_results($wpdb->prepare(
        "SELECT c.change_type, c.detected_at, c.generation_id
           FROM {$t['change']} c
          WHERE c.source_database=%s AND c.warehouse_id=%d AND c.sku=%s
          ORDER BY c.detected_at DESC LIMIT 50",
        [$source, $warehouse, $sku]
    ), ARRAY_A) ?: [];
    $warehouses = $wpdb->get_results($wpdb->prepare(
        "SELECT m.warehouse_id, m.sku, m.product_name, m.physical_quantity,
                m.reserved_quantity, m.available_quantity, m.inventory_value,
                m.sold_units_90d, m.sold_units_365d, m.coverage_days,
                m.health_status, i.verification_state
           FROM {$t['current']} m
           JOIN {$t['item']} i
             ON i.source_database=m.source_database AND i.warehouse_id=m.warehouse_id AND i.sku=m.sku
           JOIN {$t['generation']} g ON g.id=m.generation_id AND g.status='ACTIVE'
          WHERE m.source_database=%s AND m.sku=%s AND i.present_in_folio=1
          ORDER BY m.warehouse_id",
        [$source, $sku]
    ), ARRAY_A) ?: [];

    return compact('current', 'monthly', 'alerts', 'changes', 'warehouses');
}

function lps_product_analytics_database_error(): void {
    global $wpdb;
    if ($wpdb->last_error === '') return;
    error_log('Lavka product analytics database error: ' . $wpdb->last_error);
    wp_send_json_error(['message' => __('The analytics database query failed. Check the server log.', 'lavka-price-sync')], 500);
}

function lps_product_analytics_ajax(): void {
    if (!current_user_can(LPS_CAP)) {
        wp_send_json_error(['message' => __('You do not have permission to view product analytics.', 'lavka-price-sync')], 403);
    }
    check_ajax_referer(LPS_PRODUCT_ANALYTICS_NONCE);
    $operation = sanitize_key(wp_unslash($_POST['operation'] ?? ''));
    $missing = lps_product_analytics_missing_tables();
    if ($missing) {
        wp_send_json_error([
            'message' => __('The Folio analytics tables have not been published to this WordPress database yet.', 'lavka-price-sync'),
            'missingTables' => $missing,
        ], 503);
    }

    if ($operation === 'scopes') {
        $data = ['items' => lps_product_analytics_scopes()];
    } else {
        [$source, $warehouse] = lps_product_analytics_scope();
        switch ($operation) {
            case 'summary': $data = lps_product_analytics_summary($source, $warehouse); break;
            case 'filter_options': $data = lps_product_analytics_filter_options($source, $warehouse); break;
            case 'products': $data = lps_product_analytics_products($source, $warehouse); break;
            case 'product': $data = lps_product_analytics_product($source, $warehouse); break;
            default:
                wp_send_json_error(['message' => __('Unsupported product-analytics operation.', 'lavka-price-sync')], 400);
        }
    }
    lps_product_analytics_database_error();
    wp_send_json_success($data);
}
add_action('wp_ajax_lps_product_analytics', 'lps_product_analytics_ajax');
