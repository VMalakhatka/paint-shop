<?php
if (!defined('ABSPATH')) exit;

const LPS_PRODUCT_ANALYTICS_PAGE = 'lps-product-analytics';
const LPS_PRODUCT_ANALYTICS_NONCE = 'lps_product_analytics';
const LPS_PRODUCT_ANALYTICS_PRESETS_META = 'lps_product_analytics_filter_presets';
const LPS_PRODUCT_ANALYTICS_CAPABILITIES_PATH = '/admin/folio/product-analytics/capabilities';
const LPS_PRODUCT_ANALYTICS_QUERY_PATH = '/admin/folio/product-analytics/query';

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
    $js_path = dirname(__DIR__) . '/assets/product-analytics-v4.js';

    wp_enqueue_style(
        'lps-product-analytics',
        plugins_url('assets/product-analytics.css', $plugin_file),
        [],
        @filemtime($css_path) ?: '1.0'
    );
    wp_enqueue_script(
        'lps-product-analytics',
        plugins_url('assets/product-analytics-v4.js', $plugin_file),
        [],
        @filemtime($js_path) ?: '1.0',
        true
    );

    $currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'UAH';
    wp_localize_script('lps-product-analytics', 'LPS_PRODUCT_ANALYTICS', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce(LPS_PRODUCT_ANALYTICS_NONCE),
        'scenarioNonce' => wp_create_nonce(LPS_ANALYTICS_SCENARIOS_NONCE),
        'scenarioUrl' => admin_url('admin.php?page=' . LPS_ANALYTICS_SCENARIOS_PAGE),
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
        'supplier' => __('Current supplier', 'lavka-price-sync'),
        'sales90' => __('Commercial sales total, 90 days', 'lavka-price-sync'),
        'sales365' => __('Commercial sales total, 365 days', 'lavka-price-sync'),
        'regularSales365' => __('Regular demand, 12 months', 'lavka-price-sync'),
        'oneOffSales365' => __('One-off sales, 12 months', 'lavka-price-sync'),
        'turns' => __('Inventory turns', 'lavka-price-sync'),
        'gmroi' => __('GMROI', 'lavka-price-sync'),
        'coverage' => __('Coverage, days', 'lavka-price-sync'),
        'lastSale' => __('Last sale', 'lavka-price-sync'),
        'lastRegularSale' => __('Last regular sale', 'lavka-price-sync'),
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
        'documentDate' => __('Document date', 'lavka-price-sync'),
        'documentNumber' => __('Document number', 'lavka-price-sync'),
        'documentType' => __('Document type', 'lavka-price-sync'),
        'operationKind' => __('Folio operation kind', 'lavka-price-sync'),
        'movementClass' => __('Movement class', 'lavka-price-sync'),
        'stockDirection' => __('Stock direction', 'lavka-price-sync'),
        'signedQuantity' => __('Signed quantity', 'lavka-price-sync'),
        'saleAmount' => __('Sale amount', 'lavka-price-sync'),
        'accountingValue' => __('Accounting value', 'lavka-price-sync'),
        'demandMode' => __('Demand mode', 'lavka-price-sync'),
        'paymentTerms' => __('Payment terms', 'lavka-price-sync'),
        'customerSegment' => __('Customer segment', 'lavka-price-sync'),
        'counterparty' => __('Counterparty', 'lavka-price-sync'),
        'accounted' => __('Included in accounting', 'lavka-price-sync'),
        'returnFlag' => __('Return document', 'lavka-price-sync'),
        'allOperationKinds' => __('All operation kinds', 'lavka-price-sync'),
        'allDocumentTypes' => __('All document types', 'lavka-price-sync'),
        'yes' => __('Yes', 'lavka-price-sync'),
        'no' => __('No', 'lavka-price-sync'),
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
        'regularSales' => __('Regular sales quantity', 'lavka-price-sync'),
        'regularRevenue' => __('Regular sales revenue', 'lavka-price-sync'),
        'regularCogs' => __('Regular sales cost', 'lavka-price-sync'),
        'regularGrossProfit' => __('Regular gross profit', 'lavka-price-sync'),
        'oneOffSales' => __('One-off sales quantity', 'lavka-price-sync'),
        'oneOffRevenue' => __('One-off sales revenue', 'lavka-price-sync'),
        'oneOffCogs' => __('One-off sales cost', 'lavka-price-sync'),
        'oneOffGrossProfit' => __('One-off gross profit', 'lavka-price-sync'),
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
        'suppliersLoadFailed' => __('Suppliers could not be loaded.', 'lavka-price-sync'),
        /* translators: 1: products with a supplier, 2: products without a supplier, 3: distinct supplier values. */
        'supplierStats' => __('Assigned: %1$s · missing: %2$s · supplier values: %3$s', 'lavka-price-sync'),
        'supplierServiceCode' => __('Service code / requires verification', 'lavka-price-sync'),
        'schemaUpgradeRequired' => __('This warehouse uses analytics schema v1. Rebuild its snapshot with schema v2 to use suppliers, regular demand and movements.', 'lavka-price-sync'),
        'movementRows' => __('movement rows', 'lavka-price-sync'),
        'noMovements' => __('No movements match the selected filters.', 'lavka-price-sync'),
        'selectScenario' => __('Use temporary filters without a scenario', 'lavka-price-sync'),
        'scenarioApplied' => __('The analytics scenario has been applied to both registries.', 'lavka-price-sync'),
        'scenarioModified' => __('Temporary changes are applied. The saved scenario has not been changed.', 'lavka-price-sync'),
        'scenarioUnavailable' => __('The saved warehouse for this scenario is not available.', 'lavka-price-sync'),
        'scenarioProducts' => __('Product conditions', 'lavka-price-sync'),
        'scenarioMovements' => __('Movement conditions', 'lavka-price-sync'),
        'scenarioAllValues' => __('No additional conditions', 'lavka-price-sync'),
        'scenarioSavedUnavailableValue' => __('Saved value is not available in the current snapshot', 'lavka-price-sync'),
        'schemaV4Required' => __('Analytics schema v4 is required. Rebuild the selected Folio snapshots.', 'lavka-price-sync'),
        'selectWarehouses' => __('Select one or more Folio warehouses.', 'lavka-price-sync'),
        'capabilitiesLoading' => __('Loading supported filters and dictionaries...', 'lavka-price-sync'),
        'capabilitiesFailed' => __('Supported analytics filters could not be loaded.', 'lavka-price-sync'),
        'queryRunning' => __('Building the report from active snapshots...', 'lavka-price-sync'),
        'unsupportedSource' => __('The source for this filter has not been confirmed yet.', 'lavka-price-sync'),
        'include' => __('Include selected values', 'lavka-price-sync'),
        'exclude' => __('Exclude selected values', 'lavka-price-sync'),
        'any' => __('Do not apply this condition', 'lavka-price-sync'),
        'selectedValues' => __('Selected values', 'lavka-price-sync'),
        'searchSkuGtin' => __('SKU, product name or primary GTIN', 'lavka-price-sync'),
        'exactSkus' => __('Exact SKUs', 'lavka-price-sync'),
        'exactGtins' => __('Exact primary GTINs', 'lavka-price-sync'),
        'periodFrom' => __('Period from', 'lavka-price-sync'),
        'periodTo' => __('Period through', 'lavka-price-sync'),
        'abcBasis' => __('ABC basis', 'lavka-price-sync'),
        'includeReturns' => __('Include returns in the period report', 'lavka-price-sync'),
        'sortBy' => __('Sort by', 'lavka-price-sync'),
        'sortDirection' => __('Sort direction', 'lavka-price-sync'),
        'ascending' => __('Ascending', 'lavka-price-sync'),
        'descending' => __('Descending', 'lavka-price-sync'),
        'applyReport' => __('Build report', 'lavka-price-sync'),
        'clearFilters' => __('Clear filters', 'lavka-price-sync'),
        'previousPage' => __('Previous page', 'lavka-price-sync'),
        'nextPage' => __('Next page', 'lavka-price-sync'),
        'wholeSelection' => __('Totals for the whole filtered selection', 'lavka-price-sync'),
        'productCount' => __('Products found', 'lavka-price-sync'),
        'warehouseRows' => __('Warehouse rows', 'lavka-price-sync'),
        'soldUnits' => __('Sold units', 'lavka-price-sync'),
        'salesRevenue' => __('Sales revenue', 'lavka-price-sync'),
        'salesCogs' => __('Cost of sales', 'lavka-price-sync'),
        'averageInventoryValue' => __('Average inventory value', 'lavka-price-sync'),
        'grossMarginPercent' => __('Gross margin, %', 'lavka-price-sync'),
        'abcClass' => __('ABC class', 'lavka-price-sync'),
        'gtin' => __('Primary GTIN', 'lavka-price-sync'),
        'warehouses' => __('Warehouses', 'lavka-price-sync'),
        'networkPolicy' => __('Network order policy', 'lavka-price-sync'),
        'transitStock' => __('Stock in transit', 'lavka-price-sync'),
        'localOrderPolicy' => __('Warehouse order policy', 'lavka-price-sync'),
        'planningQuantity' => __('Confirmed quantity for planning', 'lavka-price-sync'),
        'unknownValue' => __('Not confirmed', 'lavka-price-sync'),
        'notReady' => __('Snapshot is not ready', 'lavka-price-sync'),
        'blocked' => __('Ordering is blocked', 'lavka-price-sync'),
        'allowed' => __('Ordering is allowed', 'lavka-price-sync'),
        'doNotOrder' => __('Do not order for this warehouse', 'lavka-price-sync'),
        'forecastOnly' => __('Order by forecast only', 'lavka-price-sync'),
        'forecastPlusMinimum' => __('Forecast plus minimum reserve', 'lavka-price-sync'),
        'unlimitedMaximum' => __('No maximum stock limit', 'lavka-price-sync'),
        'maximumLimit' => __('Maximum future stock', 'lavka-price-sync'),
        'supplierOriginConfirmed' => __('Supplier origin confirmed', 'lavka-price-sync'),
        'transitSuppliers' => __('Confirmed inbound suppliers', 'lavka-price-sync'),
        'minimumOrderAndPackage' => __('Minimum order quantity / package quantity', 'lavka-price-sync'),
        'minimumAndMaximumStock' => __('Minimum / maximum stock', 'lavka-price-sync'),
        'legacyScenario' => __('legacy scenario', 'lavka-price-sync'),
        'filterLabels' => [
            'groups' => __('Product groups', 'lavka-price-sync'),
            'groupLevel1' => __('Product group, level 1', 'lavka-price-sync'),
            'groupLevel2' => __('Product group, level 2', 'lavka-price-sync'),
            'groupLevel3' => __('Product group, level 3', 'lavka-price-sync'),
            'groupLevel4' => __('Product group, level 4', 'lavka-price-sync'),
            'groupLevel5' => __('Product group, level 5', 'lavka-price-sync'),
            'groupLevel6' => __('Product group, level 6', 'lavka-price-sync'),
            'departments' => __('Departments', 'lavka-price-sync'),
            'productTypes' => __('Product types', 'lavka-price-sync'),
            'units' => __('Units of measure', 'lavka-price-sync'),
            'currentSuppliers' => __('Current product suppliers', 'lavka-price-sync'),
            'supplierStates' => __('Supplier assignment states', 'lavka-price-sync'),
            'operationKinds' => __('Folio operation kinds', 'lavka-price-sync'),
            'movementClasses' => __('Movement classes', 'lavka-price-sync'),
            'demandModes' => __('Demand modes', 'lavka-price-sync'),
            'documentTypes' => __('Document types', 'lavka-price-sync'),
            'stockDirections' => __('Stock directions', 'lavka-price-sync'),
            'paymentTerms' => __('Payment terms', 'lavka-price-sync'),
            'customerSegments' => __('Customer segments', 'lavka-price-sync'),
            'counterparties' => __('Document counterparties', 'lavka-price-sync'),
            'organizationTypes' => __('Organization types', 'lavka-price-sync'),
        ],
        'transitLabels' => [
            'CONFIRMED_SUPPLIER_ORIGIN' => __('Confirmed stock in transit', 'lavka-price-sync'),
            'NO_IN_TRANSIT_STOCK' => __('No stock in transit', 'lavka-price-sync'),
            'NEGATIVE_TRANSIT_STOCK' => __('Negative transit stock: data error', 'lavka-price-sync'),
            'OPENING_BALANCE_UNATTRIBUTED' => __('Opening transit balance has no confirmed origin', 'lavka-price-sync'),
            'NO_CONFIRMED_INBOUND' => __('Transit stock has no confirmed supplier receipt', 'lavka-price-sync'),
            'MIXED_ORIGIN' => __('Transit stock has mixed origin', 'lavka-price-sync'),
            'SKU_NOT_PRESENT' => __('SKU is absent from the transit warehouse', 'lavka-price-sync'),
            'SNAPSHOT_NOT_READY' => __('Transit snapshot is not ready', 'lavka-price-sync'),
            'ANALYTICS_SCHEMA_TOO_OLD' => __('Transit snapshot must be rebuilt', 'lavka-price-sync'),
        ],
        'statusLabels' => [
            'HEALTHY' => __('Healthy', 'lavka-price-sync'),
            'STOCKOUT' => __('Stockout', 'lavka-price-sync'),
            'DEAD_STOCK' => __('Dead stock', 'lavka-price-sync'),
            'OVERSTOCK' => __('Overstock', 'lavka-price-sync'),
            'LOW_MARGIN' => __('Negative gross profit, 3 months', 'lavka-price-sync'),
            'DEMAND_FADING' => __('Demand fading', 'lavka-price-sync'),
            'ONE_OFF_ONLY_STOCK' => __('Stock supported only by one-off sales', 'lavka-price-sync'),
            'DATA_ISSUE' => __('Data issue', 'lavka-price-sync'),
            'NEW' => __('New product', 'lavka-price-sync'),
            'UNVERIFIED' => __('Unverified', 'lavka-price-sync'),
            'VERIFIED' => __('Verified', 'lavka-price-sync'),
            'DIRTY' => __('Changed after verification', 'lavka-price-sync'),
            'FAILED' => __('Verification failed', 'lavka-price-sync'),
            'REMOVED' => __('Removed from Folio', 'lavka-price-sync'),
            'CURRENT' => __('Current assignment', 'lavka-price-sync'),
            'MISSING' => __('Missing assignment', 'lavka-price-sync'),
            'SALE' => __('Sale', 'lavka-price-sync'),
            'CUSTOMER_RETURN' => __('Customer return', 'lavka-price-sync'),
            'SUPPLIER_RETURN' => __('Supplier return', 'lavka-price-sync'),
            'PURCHASE_RECEIPT' => __('Purchase receipt', 'lavka-price-sync'),
            'OTHER_RECEIPT' => __('Other receipt', 'lavka-price-sync'),
            'INTERNAL_RECEIPT' => __('Internal receipt', 'lavka-price-sync'),
            'INTERNAL_EXPENSE' => __('Internal expense', 'lavka-price-sync'),
            'TRANSFER_IN' => __('Transfer in', 'lavka-price-sync'),
            'TRANSFER_OUT' => __('Transfer out', 'lavka-price-sync'),
            'ASSEMBLY_INPUT' => __('Assembly input', 'lavka-price-sync'),
            'ASSEMBLY_OUTPUT' => __('Assembly output', 'lavka-price-sync'),
            'INVENTORY_CORRECTION_IN' => __('Inventory correction in', 'lavka-price-sync'),
            'INVENTORY_CORRECTION_OUT' => __('Inventory correction out', 'lavka-price-sync'),
            'DEFECT_IN' => __('Defect receipt', 'lavka-price-sync'),
            'DEFECT_OUT' => __('Defect expense', 'lavka-price-sync'),
            'INTERNAL_USE_IN' => __('Internal-use receipt', 'lavka-price-sync'),
            'INTERNAL_USE_OUT' => __('Internal-use expense', 'lavka-price-sync'),
            'MARKETING_IN' => __('Marketing receipt', 'lavka-price-sync'),
            'MARKETING_OUT' => __('Marketing expense', 'lavka-price-sync'),
            'RESERVATION' => __('Reservation', 'lavka-price-sync'),
            'OTHER_EXPENSE' => __('Other expense', 'lavka-price-sync'),
            'UNCLASSIFIED' => __('Unclassified movement', 'lavka-price-sync'),
            'REGULAR' => __('Regular demand', 'lavka-price-sync'),
            'ONE_OFF_ORDER' => __('One-off order', 'lavka-price-sync'),
            'NOT_APPLICABLE' => __('Not applicable', 'lavka-price-sync'),
            'PREPAYMENT' => __('Prepayment', 'lavka-price-sync'),
            'DEFERRED_30' => __('Deferred payment, 30 days', 'lavka-price-sync'),
            'DEFERRED_60' => __('Deferred payment, 60 days', 'lavka-price-sync'),
            'DEFERRED_90' => __('Deferred payment, 90 days', 'lavka-price-sync'),
            'DEFERRED_180' => __('Deferred payment, 180 days', 'lavka-price-sync'),
            'ON_FACT' => __('Payment on fact', 'lavka-price-sync'),
            'NOT_SPECIFIED' => __('Not specified', 'lavka-price-sync'),
            'RETAIL' => __('Retail customer', 'lavka-price-sync'),
            'NON_RETAIL' => __('Non-retail customer', 'lavka-price-sync'),
            'UNKNOWN' => __('Unknown', 'lavka-price-sync'),
            'IN' => __('Incoming', 'lavka-price-sync'),
            'OUT' => __('Outgoing', 'lavka-price-sync'),
            'NONE' => __('No stock direction', 'lavka-price-sync'),
        ],
    ];
}

function lps_render_product_analytics_v4_page(): void {
    $today = wp_date('Y-m-d');
    $period_from = wp_date('Y-m-d', strtotime('-12 months +1 day', current_time('timestamp')));
    ?>
    <div class="wrap lps-pa lps-pa-v4" id="lps-product-analytics" data-analytics-schema="4">
        <div class="lps-pa-heading">
            <div>
                <h1><?php echo esc_html__('Folio product analytics', 'lavka-price-sync'); ?></h1>
                <p class="description"><?php echo esc_html__('Read-only multi-warehouse stock, movement and profitability analytics from active Folio snapshots.', 'lavka-price-sync'); ?></p>
            </div>
            <div class="lps-pa-snapshot" id="lps-pa-snapshot"></div>
        </div>

        <div class="notice notice-info inline lps-pa-boundaries">
            <p><strong><?php echo esc_html__('Reporting rules', 'lavka-price-sync'); ?></strong></p>
            <p><?php echo esc_html__('Current stock is not changed by movement filters. Totals and financial ratios are calculated by the backend for the whole filtered selection.', 'lavka-price-sync'); ?></p>
        </div>

        <section class="lps-pa-scope-panel">
            <input type="hidden" id="lps-pa-source" value="Paint_Ua">
            <label for="lps-pa-warehouses"><strong><?php echo esc_html__('Folio warehouses', 'lavka-price-sync'); ?></strong></label>
            <select id="lps-pa-warehouses" multiple size="6" disabled aria-describedby="lps-pa-warehouse-help"></select>
            <p class="description" id="lps-pa-warehouse-help"><?php echo esc_html__('Select every warehouse that must be combined in one report. Capabilities are refreshed when this selection changes.', 'lavka-price-sync'); ?></p>
            <div class="lps-pa-toolbar">
                <button type="button" class="button" id="lps-pa-reload"><?php echo esc_html__('Reload capabilities', 'lavka-price-sync'); ?></button>
                <span class="spinner" id="lps-pa-spinner"></span>
            </div>
        </section>

        <div id="lps-pa-message" class="lps-pa-message" hidden aria-live="polite"></div>
        <div id="lps-pa-capability-warnings" class="lps-pa-warning-list" hidden></div>

        <section class="lps-pa-scenarios" aria-label="<?php echo esc_attr__('Folio analytics scenario', 'lavka-price-sync'); ?>">
            <div class="lps-pa-scenario-picker">
                <label for="lps-pa-scenario-select"><strong><?php echo esc_html__('Analytics scenario', 'lavka-price-sync'); ?></strong></label>
                <select id="lps-pa-scenario-select">
                    <option value=""><?php echo esc_html__('Use temporary filters without a scenario', 'lavka-price-sync'); ?></option>
                </select>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=' . LPS_ANALYTICS_SCENARIOS_PAGE)); ?>"><?php echo esc_html__('Manage scenarios', 'lavka-price-sync'); ?></a>
                <span id="lps-pa-scenario-status" class="description" aria-live="polite"></span>
            </div>
            <div id="lps-pa-scenario-summary" class="lps-pa-scenario-summary" hidden></div>
        </section>

        <form id="lps-pa-v4-filters" class="lps-pa-v4-form">
            <section class="lps-pa-v4-primary">
                <label>
                    <span><?php echo esc_html__('SKU, product name or primary GTIN', 'lavka-price-sync'); ?></span>
                    <input type="search" id="lps-pa-search" maxlength="200" autocomplete="off">
                </label>
                <label>
                    <span><?php echo esc_html__('Period from', 'lavka-price-sync'); ?></span>
                    <input type="date" id="lps-pa-period-from" value="<?php echo esc_attr($period_from); ?>" required>
                </label>
                <label>
                    <span><?php echo esc_html__('Period through', 'lavka-price-sync'); ?></span>
                    <input type="date" id="lps-pa-period-to" value="<?php echo esc_attr($today); ?>" required>
                </label>
                <label>
                    <span><?php echo esc_html__('ABC basis', 'lavka-price-sync'); ?></span>
                    <select id="lps-pa-abc-basis">
                        <option value="GROSS_PROFIT"><?php echo esc_html__('Gross profit', 'lavka-price-sync'); ?></option>
                        <option value="REVENUE"><?php echo esc_html__('Revenue', 'lavka-price-sync'); ?></option>
                        <option value="SOLD_UNITS"><?php echo esc_html__('Sold units', 'lavka-price-sync'); ?></option>
                    </select>
                </label>
                <label>
                    <span><?php echo esc_html__('Sort by', 'lavka-price-sync'); ?></span>
                    <select id="lps-pa-sort-field">
                        <option value="grossProfit"><?php echo esc_html__('Gross profit', 'lavka-price-sync'); ?></option>
                        <option value="salesRevenue"><?php echo esc_html__('Sales revenue', 'lavka-price-sync'); ?></option>
                        <option value="soldUnits"><?php echo esc_html__('Sold units', 'lavka-price-sync'); ?></option>
                        <option value="inventoryValue"><?php echo esc_html__('Capital in stock', 'lavka-price-sync'); ?></option>
                        <option value="averageInventoryValue"><?php echo esc_html__('Average inventory value', 'lavka-price-sync'); ?></option>
                        <option value="physicalQuantity"><?php echo esc_html__('Physical quantity', 'lavka-price-sync'); ?></option>
                        <option value="sku"><?php echo esc_html__('SKU', 'lavka-price-sync'); ?></option>
                        <option value="productName"><?php echo esc_html__('Product', 'lavka-price-sync'); ?></option>
                    </select>
                </label>
                <label>
                    <span><?php echo esc_html__('Sort direction', 'lavka-price-sync'); ?></span>
                    <select id="lps-pa-sort-direction">
                        <option value="DESC"><?php echo esc_html__('Descending', 'lavka-price-sync'); ?></option>
                        <option value="ASC"><?php echo esc_html__('Ascending', 'lavka-price-sync'); ?></option>
                    </select>
                </label>
                <label>
                    <span><?php echo esc_html__('Rows per page', 'lavka-price-sync'); ?></span>
                    <select id="lps-pa-page-size"><option value="20">20</option><option value="50" selected>50</option><option value="100">100</option><option value="250">250</option></select>
                </label>
                <label class="lps-pa-checkbox">
                    <input type="checkbox" id="lps-pa-include-returns" checked>
                    <span><?php echo esc_html__('Include returns in the period report', 'lavka-price-sync'); ?></span>
                </label>
            </section>

            <details class="lps-pa-filter-editor" open>
                <summary><?php echo esc_html__('Product conditions', 'lavka-price-sync'); ?></summary>
                <div class="lps-pa-v4-filter-grid" id="lps-pa-product-filter-grid">
                    <div class="lps-pa-text-selection" data-lps-selection="skus" data-lps-section="product">
                        <label><span><?php echo esc_html__('Exact SKUs', 'lavka-price-sync'); ?></span><textarea rows="4" placeholder="SKU-1&#10;SKU-2"></textarea></label>
                        <select class="lps-pa-mode"><option value="ANY"><?php echo esc_html__('Do not apply this condition', 'lavka-price-sync'); ?></option><option value="INCLUDE"><?php echo esc_html__('Include selected values', 'lavka-price-sync'); ?></option><option value="EXCLUDE"><?php echo esc_html__('Exclude selected values', 'lavka-price-sync'); ?></option></select>
                    </div>
                    <div class="lps-pa-text-selection" data-lps-selection="barcodes" data-lps-section="product">
                        <label><span><?php echo esc_html__('Exact primary GTINs', 'lavka-price-sync'); ?></span><textarea rows="4" placeholder="4820000000000"></textarea></label>
                        <select class="lps-pa-mode"><option value="ANY"><?php echo esc_html__('Do not apply this condition', 'lavka-price-sync'); ?></option><option value="INCLUDE"><?php echo esc_html__('Include selected values', 'lavka-price-sync'); ?></option><option value="EXCLUDE"><?php echo esc_html__('Exclude selected values', 'lavka-price-sync'); ?></option></select>
                    </div>
                </div>
            </details>

            <details class="lps-pa-filter-editor">
                <summary><?php echo esc_html__('Movement conditions', 'lavka-price-sync'); ?></summary>
                <p class="description lps-pa-filter-note"><?php echo esc_html__('These conditions change period sales and profitability metrics only. They do not change current stock.', 'lavka-price-sync'); ?></p>
                <div class="lps-pa-v4-filter-grid" id="lps-pa-movement-filter-grid"></div>
            </details>

            <div class="lps-pa-filter-actions lps-pa-v4-actions">
                <button type="submit" class="button button-primary button-large" id="lps-pa-build"><?php echo esc_html__('Build report', 'lavka-price-sync'); ?></button>
                <span class="spinner lps-pa-build-spinner" id="lps-pa-build-spinner" aria-hidden="true"></span>
                <span class="lps-pa-build-status" id="lps-pa-build-status" role="status" aria-live="polite"></span>
                <button type="button" class="button" id="lps-pa-reset"><?php echo esc_html__('Clear filters', 'lavka-price-sync'); ?></button>
            </div>
        </form>

        <nav class="nav-tab-wrapper lps-pa-tabs" aria-label="<?php echo esc_attr__('Analytics section', 'lavka-price-sync'); ?>">
            <button type="button" class="nav-tab nav-tab-active" data-lps-pa-tab="products"><?php echo esc_html__('Products', 'lavka-price-sync'); ?></button>
            <button type="button" class="nav-tab" data-lps-pa-tab="movements"><?php echo esc_html__('Product movements', 'lavka-price-sync'); ?></button>
        </nav>

        <section id="lps-pa-summary" class="lps-pa-summary" aria-label="<?php echo esc_attr__('Totals for the whole filtered selection', 'lavka-price-sync'); ?>"></section>
        <div id="lps-pa-query-warnings" class="lps-pa-warning-list" hidden></div>

        <section class="lps-pa-registry" data-lps-pa-panel="products">
            <div class="lps-pa-table-meta" id="lps-pa-table-meta"></div>
            <div class="lps-pa-table-scroll">
                <table class="widefat striped" id="lps-pa-products"><thead><tr id="lps-pa-products-head"></tr></thead><tbody></tbody></table>
            </div>
            <div class="tablenav bottom lps-pa-pagination" id="lps-pa-pagination"></div>
        </section>

        <section class="lps-pa-registry" data-lps-pa-panel="movements" hidden>
            <div class="notice notice-info inline"><p><?php echo esc_html__('This view shows period movement and financial metrics aggregated by product. Current stock remains the same as in the Products view.', 'lavka-price-sync'); ?></p></div>
            <div class="lps-pa-table-meta" id="lps-pa-movements-meta"></div>
            <div class="lps-pa-table-scroll">
                <table class="widefat striped" id="lps-pa-movements"><thead><tr id="lps-pa-movements-head"></tr></thead><tbody></tbody></table>
            </div>
            <div class="tablenav bottom lps-pa-pagination" id="lps-pa-movements-pagination"></div>
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

function lps_render_product_analytics_page(): void {
    if (!current_user_can(LPS_CAP)) return;
    lps_render_product_analytics_v4_page();
    return;
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
        <p class="description lps-pa-summary-boundary"><?php echo esc_html__('The summary cards show the whole selected warehouse before scenario filters. Scenario conditions apply to the product and movement registries below.', 'lavka-price-sync'); ?></p>
        <section id="lps-pa-summary" class="lps-pa-summary" aria-label="<?php echo esc_attr__('Warehouse-wide summary', 'lavka-price-sync'); ?>"></section>

        <section class="lps-pa-scenarios" aria-label="<?php echo esc_attr__('Folio analytics scenario', 'lavka-price-sync'); ?>">
            <div class="lps-pa-scenario-picker">
                <label for="lps-pa-scenario-select"><strong><?php echo esc_html__('Analytics scenario', 'lavka-price-sync'); ?></strong></label>
                <select id="lps-pa-scenario-select">
                    <option value=""><?php echo esc_html__('Use temporary filters without a scenario', 'lavka-price-sync'); ?></option>
                </select>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=' . LPS_ANALYTICS_SCENARIOS_PAGE)); ?>"><?php echo esc_html__('Manage scenarios', 'lavka-price-sync'); ?></a>
                <span id="lps-pa-scenario-status" class="description" aria-live="polite"></span>
            </div>
            <div id="lps-pa-scenario-summary" class="lps-pa-scenario-summary" hidden></div>
        </section>

        <nav class="nav-tab-wrapper lps-pa-tabs" aria-label="<?php echo esc_attr__('Analytics section', 'lavka-price-sync'); ?>">
            <button type="button" class="nav-tab nav-tab-active" data-lps-pa-tab="products"><?php echo esc_html__('Products', 'lavka-price-sync'); ?></button>
            <button type="button" class="nav-tab" data-lps-pa-tab="movements"><?php echo esc_html__('Product movements', 'lavka-price-sync'); ?></button>
        </nav>

        <section id="lps-pa-products-panel" data-lps-pa-panel="products">

        <nav class="lps-pa-views" aria-label="<?php echo esc_attr__('Saved analytics views', 'lavka-price-sync'); ?>">
            <?php
            $views = [
                'all' => __('All products', 'lavka-price-sync'),
                'data_issues' => __('Data issues', 'lavka-price-sync'),
                'stockout' => __('Stockout', 'lavka-price-sync'),
                'dead_stock' => __('Dead stock', 'lavka-price-sync'),
                'overstock' => __('Overstock', 'lavka-price-sync'),
                'low_margin' => __('Negative gross profit, 3 months', 'lavka-price-sync'),
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

        <details class="lps-pa-filter-editor">
            <summary><?php echo esc_html__('Temporary product filter overrides', 'lavka-price-sync'); ?></summary>
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
                    <option value="LOW_MARGIN"><?php echo esc_html__('Negative gross profit, 3 months', 'lavka-price-sync'); ?></option>
                    <option value="DEMAND_FADING"><?php echo esc_html__('Demand fading', 'lavka-price-sync'); ?></option>
                    <option value="DATA_ISSUE"><?php echo esc_html__('Data issue', 'lavka-price-sync'); ?></option>
                    <option value="NEW"><?php echo esc_html__('New product', 'lavka-price-sync'); ?></option>
                    <option value="ONE_OFF_ONLY_STOCK"><?php echo esc_html__('Stock supported only by one-off sales', 'lavka-price-sync'); ?></option>
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
                    <option value="LOW_MARGIN"><?php echo esc_html__('Negative gross profit, 3 months', 'lavka-price-sync'); ?></option>
                    <option value="OVERSTOCK"><?php echo esc_html__('Overstock', 'lavka-price-sync'); ?></option>
                    <option value="DEAD_STOCK"><?php echo esc_html__('Dead stock', 'lavka-price-sync'); ?></option>
                    <option value="DEMAND_FADING"><?php echo esc_html__('Demand fading', 'lavka-price-sync'); ?></option>
                    <option value="ONE_OFF_ONLY_STOCK"><?php echo esc_html__('Stock supported only by one-off sales', 'lavka-price-sync'); ?></option>
                </select>
            </label>
            <label>
                <span><?php echo esc_html__('Alert status', 'lavka-price-sync'); ?></span>
                <select name="alertStatus"><option value="ANY"><?php echo esc_html__('Active and resolved alerts', 'lavka-price-sync'); ?></option><option value="ACTIVE"><?php echo esc_html__('Active alerts', 'lavka-price-sync'); ?></option><option value="RESOLVED"><?php echo esc_html__('Resolved alerts', 'lavka-price-sync'); ?></option></select>
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
                <span><?php echo esc_html__('Commercial sales total', 'lavka-price-sync'); ?></span>
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
                <div class="lps-pa-supplier-meta" id="lps-pa-supplier-meta" aria-live="polite"></div>
            </div>
            <label>
                <span><?php echo esc_html__('Supplier data quality', 'lavka-price-sync'); ?></span>
                <select name="supplierQuality">
                    <option value="ANY"><?php echo esc_html__('Any supplier assignment', 'lavka-price-sync'); ?></option>
                    <option value="CURRENT"><?php echo esc_html__('Supplier assigned', 'lavka-price-sync'); ?></option>
                    <option value="MISSING"><?php echo esc_html__('Supplier missing', 'lavka-price-sync'); ?></option>
                    <option value="REVIEW"><?php echo esc_html__('Service code / requires verification', 'lavka-price-sync'); ?></option>
                </select>
            </label>
            <label>
                <span><?php echo esc_html__('Available stock sign', 'lavka-price-sync'); ?></span>
                <select name="availableSign">
                    <option value="ANY"><?php echo esc_html__('Any available stock', 'lavka-price-sync'); ?></option>
                    <option value="NON_POSITIVE"><?php echo esc_html__('Zero or negative', 'lavka-price-sync'); ?></option>
                    <option value="ZERO"><?php echo esc_html__('Exactly zero', 'lavka-price-sync'); ?></option>
                    <option value="POSITIVE"><?php echo esc_html__('Positive', 'lavka-price-sync'); ?></option>
                </select>
            </label>
            <label>
                <span><?php echo esc_html__('Accounting price', 'lavka-price-sync'); ?></span>
                <select name="accountingPriceMode">
                    <option value="ANY"><?php echo esc_html__('Any accounting price', 'lavka-price-sync'); ?></option>
                    <option value="ZERO"><?php echo esc_html__('Zero accounting price', 'lavka-price-sync'); ?></option>
                    <option value="POSITIVE"><?php echo esc_html__('Positive accounting price', 'lavka-price-sync'); ?></option>
                </select>
            </label>
            <details class="lps-pa-filter-section">
                <summary><?php echo esc_html__('Stock and capital filters', 'lavka-price-sync'); ?></summary>
                <div class="lps-pa-filter-grid">
                    <?php
                    $quantity_ranges = [
                        ['physicalMin', __('Physical quantity from', 'lavka-price-sync')],
                        ['physicalMax', __('Physical quantity through', 'lavka-price-sync')],
                        ['reservedMin', __('Reserved quantity from', 'lavka-price-sync')],
                        ['reservedMax', __('Reserved quantity through', 'lavka-price-sync')],
                        ['availableMin', __('Available quantity from', 'lavka-price-sync')],
                        ['availableMax', __('Available quantity through', 'lavka-price-sync')],
                    ];
                    foreach ($quantity_ranges as [$name, $label]):
                    ?>
                        <label><span><?php echo esc_html($label); ?></span><input type="number" name="<?php echo esc_attr($name); ?>" step="0.0001"></label>
                    <?php endforeach; ?>
                </div>
            </details>
            <details class="lps-pa-filter-section">
                <summary><?php echo esc_html__('Demand filters', 'lavka-price-sync'); ?></summary>
                <div class="lps-pa-filter-grid">
                    <label>
                        <span><?php echo esc_html__('Demand period', 'lavka-price-sync'); ?></span>
                        <select name="demandPeriod">
                            <option value="30"><?php echo esc_html__('Current month', 'lavka-price-sync'); ?></option>
                            <option value="90"><?php echo esc_html__('3 months', 'lavka-price-sync'); ?></option>
                            <option value="365" selected><?php echo esc_html__('12 months', 'lavka-price-sync'); ?></option>
                            <option value="730"><?php echo esc_html__('24 months', 'lavka-price-sync'); ?></option>
                        </select>
                    </label>
                    <label>
                        <span><?php echo esc_html__('Regular demand', 'lavka-price-sync'); ?></span>
                        <select name="regularDemand">
                            <option value="ANY"><?php echo esc_html__('Any regular demand', 'lavka-price-sync'); ?></option>
                            <option value="WITH"><?php echo esc_html__('With regular demand', 'lavka-price-sync'); ?></option>
                            <option value="WITHOUT"><?php echo esc_html__('Without regular demand', 'lavka-price-sync'); ?></option>
                        </select>
                    </label>
                    <label>
                        <span><?php echo esc_html__('One-off sales', 'lavka-price-sync'); ?></span>
                        <select name="oneOffDemand">
                            <option value="ANY"><?php echo esc_html__('Any one-off sales', 'lavka-price-sync'); ?></option>
                            <option value="WITH"><?php echo esc_html__('With one-off sales', 'lavka-price-sync'); ?></option>
                            <option value="WITHOUT"><?php echo esc_html__('Without one-off sales', 'lavka-price-sync'); ?></option>
                            <option value="ONLY"><?php echo esc_html__('Only one-off sales, no regular demand', 'lavka-price-sync'); ?></option>
                        </select>
                    </label>
                </div>
            </details>
            <label>
                <span><?php echo esc_html__('Minimum capital', 'lavka-price-sync'); ?></span>
                <input type="number" name="inventoryMin" min="0" step="0.01">
            </label>
            <label>
                <span><?php echo esc_html__('Maximum capital', 'lavka-price-sync'); ?></span>
                <input type="number" name="inventoryMax" min="0" step="0.01">
            </label>
            <details class="lps-pa-filter-section">
                <summary><?php echo esc_html__('Financial efficiency filters', 'lavka-price-sync'); ?></summary>
                <div class="lps-pa-filter-grid">
                    <label>
                        <span><?php echo esc_html__('Financial period', 'lavka-price-sync'); ?></span>
                        <select name="financePeriod"><option value="90"><?php echo esc_html__('3 months', 'lavka-price-sync'); ?></option><option value="365" selected><?php echo esc_html__('12 months', 'lavka-price-sync'); ?></option></select>
                    </label>
                    <?php
                    $financial_ranges = [
                        ['revenueMin', __('Revenue from', 'lavka-price-sync')],
                        ['revenueMax', __('Revenue through', 'lavka-price-sync')],
                        ['profitMin', __('Gross profit from', 'lavka-price-sync')],
                        ['profitMax', __('Gross profit through', 'lavka-price-sync')],
                        ['averageCapitalMin', __('Average capital from', 'lavka-price-sync')],
                        ['averageCapitalMax', __('Average capital through', 'lavka-price-sync')],
                        ['marginMin', __('Gross margin from, %', 'lavka-price-sync')],
                        ['marginMax', __('Gross margin through, %', 'lavka-price-sync')],
                        ['turnsMin', __('Inventory turns from', 'lavka-price-sync')],
                        ['turnsMax', __('Inventory turns through', 'lavka-price-sync')],
                        ['gmroiMin', __('GMROI from', 'lavka-price-sync')],
                        ['gmroiMax', __('GMROI through', 'lavka-price-sync')],
                        ['coverageMin', __('Coverage from, days', 'lavka-price-sync')],
                        ['coverageMax', __('Coverage through, days', 'lavka-price-sync')],
                    ];
                    foreach ($financial_ranges as [$name, $label]):
                    ?>
                        <label><span><?php echo esc_html($label); ?></span><input type="number" name="<?php echo esc_attr($name); ?>" step="0.01"></label>
                    <?php endforeach; ?>
                </div>
            </details>
            <label>
                <span><?php echo esc_html__('Last sale from', 'lavka-price-sync'); ?></span>
                <input type="date" name="lastSaleFrom">
            </label>
            <label>
                <span><?php echo esc_html__('Last sale through', 'lavka-price-sync'); ?></span>
                <input type="date" name="lastSaleTo">
            </label>
            <details class="lps-pa-filter-section">
                <summary><?php echo esc_html__('Additional date filters', 'lavka-price-sync'); ?></summary>
                <div class="lps-pa-filter-grid">
                    <?php
                    $date_ranges = [
                        ['lastRegularSaleFrom', __('Last regular sale from', 'lavka-price-sync')],
                        ['lastRegularSaleTo', __('Last regular sale through', 'lavka-price-sync')],
                        ['lastReceiptFrom', __('Last receipt from', 'lavka-price-sync')],
                        ['lastReceiptTo', __('Last receipt through', 'lavka-price-sync')],
                        ['firstMovementFrom', __('First movement from', 'lavka-price-sync')],
                        ['firstMovementTo', __('First movement through', 'lavka-price-sync')],
                        ['lastMovementFrom', __('Last movement from', 'lavka-price-sync')],
                        ['lastMovementTo', __('Last movement through', 'lavka-price-sync')],
                        ['alertFirstSeenFrom', __('Alert first seen from', 'lavka-price-sync')],
                        ['alertFirstSeenTo', __('Alert first seen through', 'lavka-price-sync')],
                        ['alertLastSeenFrom', __('Alert last confirmed from', 'lavka-price-sync')],
                        ['alertLastSeenTo', __('Alert last confirmed through', 'lavka-price-sync')],
                    ];
                    foreach ($date_ranges as [$name, $label]):
                    ?>
                        <label><span><?php echo esc_html($label); ?></span><input type="date" name="<?php echo esc_attr($name); ?>"></label>
                    <?php endforeach; ?>
                </div>
            </details>
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
        </details>

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
        </section>

        <section id="lps-pa-movements-panel" data-lps-pa-panel="movements" hidden>
            <div class="notice notice-info inline">
                <p><?php echo esc_html__('This registry shows published Folio movement facts. Filtering is performed server-side and does not recalculate the product summary cards above.', 'lavka-price-sync'); ?></p>
            </div>
            <details class="lps-pa-filter-editor">
                <summary><?php echo esc_html__('Temporary movement filter overrides', 'lavka-price-sync'); ?></summary>
            <form id="lps-pa-movement-filters" class="lps-pa-filters lps-pa-movement-filters">
                <?php $movement_labels = lps_product_analytics_i18n()['statusLabels']; ?>
                <label><span><?php echo esc_html__('Document date from', 'lavka-price-sync'); ?></span><input type="date" name="documentDateFrom"></label>
                <label><span><?php echo esc_html__('Document date through', 'lavka-price-sync'); ?></span><input type="date" name="documentDateTo"></label>
                <label><span><?php echo esc_html__('SKU', 'lavka-price-sync'); ?></span><input type="search" name="movementSku"></label>
                <label><span><?php echo esc_html__('Document number', 'lavka-price-sync'); ?></span><input type="search" name="documentNumber"></label>
                <label>
                    <span><?php echo esc_html__('Document type', 'lavka-price-sync'); ?></span>
                    <select name="documentType" id="lps-pa-document-type"><option value=""><?php echo esc_html__('All document types', 'lavka-price-sync'); ?></option></select>
                </label>
                <label>
                    <span><?php echo esc_html__('Folio operation kind', 'lavka-price-sync'); ?></span>
                    <select name="operationKind" id="lps-pa-operation-kind"><option value=""><?php echo esc_html__('All operation kinds', 'lavka-price-sync'); ?></option></select>
                </label>
                <label>
                    <span><?php echo esc_html__('Movement class', 'lavka-price-sync'); ?></span>
                    <select name="movementClass">
                        <option value=""><?php echo esc_html__('All movement classes', 'lavka-price-sync'); ?></option>
                        <?php foreach (['SALE','CUSTOMER_RETURN','SUPPLIER_RETURN','PURCHASE_RECEIPT','OTHER_RECEIPT','INTERNAL_RECEIPT','INTERNAL_EXPENSE','TRANSFER_IN','TRANSFER_OUT','ASSEMBLY_INPUT','ASSEMBLY_OUTPUT','INVENTORY_CORRECTION_IN','INVENTORY_CORRECTION_OUT','DEFECT_IN','DEFECT_OUT','INTERNAL_USE_IN','INTERNAL_USE_OUT','MARKETING_IN','MARKETING_OUT','RESERVATION','OTHER_EXPENSE','UNCLASSIFIED'] as $value): ?>
                            <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($movement_labels[$value] ?? $value); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <?php
                $movement_selects = [
                    'stockDirection' => [__('Stock direction', 'lavka-price-sync'), ['IN','OUT','NONE']],
                    'demandMode' => [__('Demand mode', 'lavka-price-sync'), ['REGULAR','ONE_OFF_ORDER','NOT_APPLICABLE']],
                    'paymentTerms' => [__('Payment terms', 'lavka-price-sync'), ['PREPAYMENT','DEFERRED_30','DEFERRED_60','DEFERRED_90','DEFERRED_180','ON_FACT','NOT_SPECIFIED']],
                    'customerSegment' => [__('Customer segment', 'lavka-price-sync'), ['RETAIL','NON_RETAIL','UNKNOWN','NOT_APPLICABLE']],
                ];
                foreach ($movement_selects as $name => [$caption, $values]):
                ?>
                    <label><span><?php echo esc_html($caption); ?></span><select name="<?php echo esc_attr($name); ?>"><option value=""><?php echo esc_html__('All values', 'lavka-price-sync'); ?></option><?php foreach ($values as $value): ?><option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($movement_labels[$value] ?? $value); ?></option><?php endforeach; ?></select></label>
                <?php endforeach; ?>
                <?php
                $boolean_selects = [
                    'accounted' => __('Included in accounting', 'lavka-price-sync'),
                    'returnFlag' => __('Return document', 'lavka-price-sync'),
                    'affectsStock' => __('Affects stock', 'lavka-price-sync'),
                    'affectsFinancialSales' => __('Affects financial sales', 'lavka-price-sync'),
                    'affectsPlanningDemand' => __('Affects planning demand', 'lavka-price-sync'),
                ];
                foreach ($boolean_selects as $name => $caption):
                ?>
                    <label><span><?php echo esc_html($caption); ?></span><select name="<?php echo esc_attr($name); ?>"><option value=""><?php echo esc_html__('Any value', 'lavka-price-sync'); ?></option><option value="1"><?php echo esc_html__('Yes', 'lavka-price-sync'); ?></option><option value="0"><?php echo esc_html__('No', 'lavka-price-sync'); ?></option></select></label>
                <?php endforeach; ?>
                <label><span><?php echo esc_html__('Counterparty', 'lavka-price-sync'); ?></span><input type="search" name="counterparty"></label>
                <label><span><?php echo esc_html__('Current supplier', 'lavka-price-sync'); ?></span><input type="search" name="movementSupplier"></label>
                <label><span><?php echo esc_html__('Rows per page', 'lavka-price-sync'); ?></span><select name="movementPerPage"><option value="20">20</option><option value="50" selected>50</option><option value="100">100</option></select></label>
                <div class="lps-pa-filter-actions">
                    <button type="submit" class="button button-primary"><?php echo esc_html__('Apply movement filters', 'lavka-price-sync'); ?></button>
                    <button type="button" class="button" id="lps-pa-movement-reset"><?php echo esc_html__('Reset', 'lavka-price-sync'); ?></button>
                </div>
            </form>
            </details>
            <div class="lps-pa-table-meta" id="lps-pa-movements-meta"></div>
            <div class="lps-pa-table-scroll">
                <table class="widefat striped" id="lps-pa-movements"><thead><tr id="lps-pa-movements-head"></tr></thead><tbody></tbody></table>
            </div>
            <div class="tablenav bottom lps-pa-pagination" id="lps-pa-movements-pagination"></div>
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
        'movement' => 'folio_product_movement_fact',
    ];
}

function lps_product_analytics_missing_tables(bool $include_movement = false): array {
    global $wpdb;
    $missing = [];
    $tables = lps_product_analytics_tables();
    if (!$include_movement) unset($tables['movement']);
    foreach ($tables as $table) {
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        if ((string)$found !== $table) $missing[] = $table;
    }
    return $missing;
}

function lps_product_analytics_table_exists(string $table): bool {
    global $wpdb;
    return (string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
}

function lps_product_analytics_require_movement_table(): void {
    $missing = lps_product_analytics_missing_tables(true);
    if (!$missing) return;

    wp_send_json_error([
        'message' => __('The Folio movement facts have not been published to this WordPress database yet. Rebuild the snapshot with analytics schema v2.', 'lavka-price-sync'),
        'missingTables' => $missing,
    ], 503);
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
               g.analytics_schema_version, g.movement_fact_rows,
               g.started_at, g.completed_at, g.total_products,
               g.monthly_metric_rows, g.unverified_products, g.dirty_products,
               g.new_products, g.removed_products
          FROM {$t['generation']} g
          JOIN (
                SELECT source_database, warehouse_id, MAX(id) AS id
                  FROM {$t['generation']}
                 WHERE status = 'ACTIVE' AND completed_at IS NOT NULL
                 GROUP BY source_database, warehouse_id
          ) latest ON latest.id = g.id
         ORDER BY g.source_database, g.warehouse_id
    ";
    return $wpdb->get_results($sql, ARRAY_A) ?: [];
}

function lps_product_analytics_active_generation(string $source, int $warehouse): array {
    global $wpdb;
    $t = lps_product_analytics_tables();
    $generation = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$t['generation']}
          WHERE source_database=%s AND warehouse_id=%d
            AND status='ACTIVE' AND completed_at IS NOT NULL
          ORDER BY id DESC LIMIT 1",
        [$source, $warehouse]
    ), ARRAY_A);
    lps_product_analytics_database_error();
    if (!$generation) {
        wp_send_json_error(['message' => __('No completed active Folio product snapshot is available for this warehouse.', 'lavka-price-sync')], 409);
    }
    return $generation;
}

function lps_product_analytics_filter_options(string $source, int $warehouse): array {
    global $wpdb;
    $t = lps_product_analytics_tables();
    $generation = lps_product_analytics_active_generation($source, $warehouse);
    $schema_version = (int)($generation['analytics_schema_version'] ?? 1);
    if ($schema_version < 2) {
        return [
            'generationId' => (int)$generation['id'],
            'analyticsSchemaVersion' => $schema_version,
            'supplierStats' => ['assignedProducts' => 0, 'missingProducts' => 0, 'distinctSuppliers' => 0],
            'suppliers' => [],
        ];
    }
    $supplier_stats = $wpdb->get_row($wpdb->prepare(
        "SELECT
                COALESCE(SUM(CASE WHEN m.supplier_state='CURRENT' THEN 1 ELSE 0 END),0) AS assigned_products,
                COALESCE(SUM(CASE WHEN m.supplier_state='MISSING' THEN 1 ELSE 0 END),0) AS missing_products,
                COUNT(DISTINCT CASE
                    WHEN m.current_supplier IS NOT NULL AND TRIM(m.current_supplier)<>''
                    THEN TRIM(m.current_supplier) END) AS distinct_suppliers
           FROM {$t['current']} m
           JOIN {$t['item']} i
             ON i.source_database=m.source_database AND i.warehouse_id=m.warehouse_id AND i.sku=m.sku
          WHERE m.generation_id=%d AND m.source_database=%s AND m.warehouse_id=%d
            AND i.present_in_folio=1",
        [(int)$generation['id'], $source, $warehouse]
    ), ARRAY_A) ?: [];
    lps_product_analytics_database_error();

    $supplier_rows = $wpdb->get_results($wpdb->prepare(
        "SELECT TRIM(m.current_supplier) AS supplier_value,
                COUNT(*) AS products,
                CASE WHEN TRIM(m.current_supplier)='1' THEN 'REVIEW' ELSE 'CURRENT' END AS supplier_state
           FROM {$t['current']} m
           JOIN {$t['item']} i
             ON i.source_database=m.source_database AND i.warehouse_id=m.warehouse_id AND i.sku=m.sku
          WHERE m.generation_id=%d AND m.source_database=%s AND m.warehouse_id=%d
            AND i.present_in_folio=1
            AND m.current_supplier IS NOT NULL AND TRIM(m.current_supplier)<>''
          GROUP BY TRIM(m.current_supplier)
          ORDER BY TRIM(m.current_supplier)",
        [(int)$generation['id'], $source, $warehouse]
    ), ARRAY_A) ?: [];
    lps_product_analytics_database_error();

    $movement_available = lps_product_analytics_table_exists($t['movement']);
    $operation_kinds = [];
    $document_types = [];
    if ($movement_available) {
        $operation_kinds = $wpdb->get_results($wpdb->prepare(
            "SELECT operation_kind AS option_value, COUNT(*) AS movements
               FROM {$t['movement']}
              WHERE generation_id=%d AND source_database=%s AND warehouse_id=%d
                AND operation_kind IS NOT NULL AND TRIM(operation_kind)<>''
              GROUP BY operation_kind ORDER BY operation_kind",
            [(int)$generation['id'], $source, $warehouse]
        ), ARRAY_A) ?: [];
        lps_product_analytics_database_error();

        $document_types = $wpdb->get_results($wpdb->prepare(
            "SELECT document_type AS option_value, COUNT(*) AS movements
               FROM {$t['movement']}
              WHERE generation_id=%d AND source_database=%s AND warehouse_id=%d
                AND document_type IS NOT NULL AND TRIM(document_type)<>''
              GROUP BY document_type ORDER BY document_type",
            [(int)$generation['id'], $source, $warehouse]
        ), ARRAY_A) ?: [];
        lps_product_analytics_database_error();
    }

    return [
        'generationId' => (int)$generation['id'],
        'analyticsSchemaVersion' => $schema_version,
        'supplierStats' => [
            'assignedProducts' => (int)($supplier_stats['assigned_products'] ?? 0),
            'missingProducts' => (int)($supplier_stats['missing_products'] ?? 0),
            'distinctSuppliers' => (int)($supplier_stats['distinct_suppliers'] ?? 0),
        ],
        'suppliers' => array_map(static fn(array $row): array => [
            'value' => (string)$row['supplier_value'],
            'products' => (int)$row['products'],
            'state' => (string)$row['supplier_state'],
        ], $supplier_rows),
        'movementOptions' => [
            'available' => $movement_available,
            'operationKinds' => array_map(static fn(array $row): array => [
                'value' => (string)$row['option_value'],
                'movements' => (int)$row['movements'],
            ], $operation_kinds),
            'documentTypes' => array_map(static fn(array $row): array => [
                'value' => (string)$row['option_value'],
                'movements' => (int)$row['movements'],
            ], $document_types),
        ],
    ];
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

function lps_product_analytics_post_value(string $key): string {
    return trim(sanitize_text_field(wp_unslash($_POST[$key] ?? '')));
}

function lps_product_analytics_add_numeric_range(array &$where, array &$args, string $column, string $min_key, string $max_key): void {
    $min = lps_product_analytics_post_value($min_key);
    $max = lps_product_analytics_post_value($max_key);
    if ($min !== '' && is_numeric($min)) {
        $where[] = "{$column}>=%f";
        $args[] = (float)$min;
    }
    if ($max !== '' && is_numeric($max)) {
        $where[] = "{$column}<=%f";
        $args[] = (float)$max;
    }
}

function lps_product_analytics_add_date_range(array &$where, array &$args, string $column, string $from_key, string $to_key): void {
    $from = lps_product_analytics_post_value($from_key);
    $to = lps_product_analytics_post_value($to_key);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $where[] = "{$column}>=%s";
        $args[] = $from;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $where[] = "{$column}<=%s";
        $args[] = $to;
    }
}

function lps_product_analytics_presets(): array {
    $presets = get_user_meta(get_current_user_id(), LPS_PRODUCT_ANALYTICS_PRESETS_META, true);
    if (!is_array($presets)) return [];

    $items = [];
    foreach ($presets as $preset) {
        if (!is_array($preset) || empty($preset['id']) || empty($preset['name']) || !is_array($preset['state'] ?? null)) continue;
        $items[] = [
            'id' => sanitize_key((string)$preset['id']),
            'name' => sanitize_text_field((string)$preset['name']),
            'state' => lps_product_analytics_sanitize_preset_state($preset['state']),
        ];
    }
    usort($items, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
    return $items;
}

function lps_product_analytics_sanitize_preset_state(array $state): array {
    $scalar_keys = [
        'sourceDatabase', 'search', 'health', 'verification', 'alertCode', 'alertStatus', 'severity',
        'sales', 'supplierMode', 'supplierQuality', 'availableSign', 'accountingPriceMode',
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
    $clean = [];
    foreach ($scalar_keys as $key) {
        $clean[$key] = sanitize_text_field((string)($state[$key] ?? ''));
    }
    $clean['warehouseId'] = absint($state['warehouseId'] ?? 0);
    $clean['supplierMode'] = in_array(strtoupper($clean['supplierMode']), ['ANY', 'INCLUDE', 'EXCLUDE'], true)
        ? strtoupper($clean['supplierMode'])
        : 'ANY';
    $supplier_values = preg_split('/[\r\n]+/', (string)($state['supplierValues'] ?? '')) ?: [];
    $supplier_values = array_values(array_unique(array_filter(array_map(
        static fn($value): string => trim(sanitize_text_field($value)),
        $supplier_values
    ), static fn(string $value): bool => $value !== '')));
    $clean['supplierValues'] = implode("\n", array_slice($supplier_values, 0, 100));
    $clean['direction'] = strtoupper($clean['direction']) === 'ASC' ? 'ASC' : 'DESC';
    return $clean;
}

function lps_product_analytics_save_preset(): array {
    $name = trim(sanitize_text_field(wp_unslash($_POST['presetName'] ?? '')));
    if ($name === '') {
        wp_send_json_error(['message' => __('Enter a name for the filter set.', 'lavka-price-sync')], 400);
    }
    $name = function_exists('mb_substr') ? mb_substr($name, 0, 80) : substr($name, 0, 80);

    $decoded = json_decode((string)wp_unslash($_POST['presetState'] ?? ''), true);
    if (!is_array($decoded)) {
        wp_send_json_error(['message' => __('The filter set data is invalid.', 'lavka-price-sync')], 400);
    }
    $state = lps_product_analytics_sanitize_preset_state($decoded);
    $id = sanitize_key(wp_unslash($_POST['presetId'] ?? ''));
    $presets = lps_product_analytics_presets();

    $updated = false;
    foreach ($presets as &$preset) {
        if (($id !== '' && $preset['id'] === $id) || strcasecmp($preset['name'], $name) === 0) {
            $preset['name'] = $name;
            $preset['state'] = $state;
            $id = $preset['id'];
            $updated = true;
            break;
        }
    }
    unset($preset);

    if (!$updated) {
        if (count($presets) >= 30) {
            wp_send_json_error(['message' => __('No more than 30 saved filter sets are allowed.', 'lavka-price-sync')], 400);
        }
        $id = str_replace('-', '', wp_generate_uuid4());
        $presets[] = ['id' => $id, 'name' => $name, 'state' => $state];
    }

    update_user_meta(get_current_user_id(), LPS_PRODUCT_ANALYTICS_PRESETS_META, $presets);
    return ['items' => lps_product_analytics_presets(), 'selectedId' => $id];
}

function lps_product_analytics_delete_preset(): array {
    $id = sanitize_key(wp_unslash($_POST['presetId'] ?? ''));
    $presets = array_values(array_filter(
        lps_product_analytics_presets(),
        static fn(array $preset): bool => $preset['id'] !== $id
    ));
    update_user_meta(get_current_user_id(), LPS_PRODUCT_ANALYTICS_PRESETS_META, $presets);
    return ['items' => lps_product_analytics_presets()];
}

function lps_product_analytics_summary(string $source, int $warehouse): array {
    global $wpdb;
    $t = lps_product_analytics_tables();
    $scope = [$source, $warehouse];
    $generation = lps_product_analytics_active_generation($source, $warehouse);

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
          WHERE m.source_database=%s AND m.warehouse_id=%d AND m.generation_id=%d AND i.present_in_folio=1",
        [$source, $warehouse, (int)$generation['id']]
    ), ARRAY_A) ?: [];

    $alerts = $wpdb->get_results($wpdb->prepare(
        "SELECT alert_code, severity, COUNT(*) AS products
           FROM {$t['alert']}
          WHERE source_database=%s AND warehouse_id=%d AND generation_id=%d AND status='ACTIVE'
          GROUP BY alert_code, severity
          ORDER BY FIELD(severity,'ERROR','HIGH','MEDIUM','LOW'), alert_code",
        [$source, $warehouse, (int)$generation['id']]
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
    $generation = lps_product_analytics_active_generation($source, $warehouse);
    if ((int)($generation['analytics_schema_version'] ?? 1) < 2) {
        wp_send_json_error(['message' => __('This snapshot must be rebuilt with analytics schema v2.', 'lavka-price-sync')], 409);
    }
    $allowed_health = ['HEALTHY','STOCKOUT','DEAD_STOCK','OVERSTOCK','LOW_MARGIN','DEMAND_FADING','DATA_ISSUE','NEW','ONE_OFF_ONLY_STOCK'];
    $allowed_verification = ['UNVERIFIED','VERIFIED','DIRTY','NEW','FAILED','REMOVED'];
    $allowed_alerts = ['DATA_ISSUE','STOCKOUT','LOW_MARGIN','OVERSTOCK','DEAD_STOCK','DEMAND_FADING','ONE_OFF_ONLY_STOCK'];
    $allowed_severities = ['ERROR','HIGH','MEDIUM','LOW'];
    $allowed_sorts = [
        'sku' => 'm.sku', 'product_name' => 'm.product_name',
        'inventory_value' => 'm.inventory_value', 'sold_units_365d' => 'm.sold_units_365d',
        'sold_units_90d' => 'm.sold_units_90d',
        'revenue_365d' => 'm.revenue_365d', 'gross_profit_365d' => 'm.gross_profit_365d',
        'inventory_turns_365d' => 'm.inventory_turns_365d', 'gmroi_365d' => 'm.gmroi_365d',
        'coverage_days' => 'm.coverage_days', 'last_sale_date' => 'm.last_sale_date',
        'last_regular_sale_date' => 'm.last_regular_sale_date', 'last_receipt_date' => 'm.last_receipt_date',
        'current_supplier' => 'm.current_supplier', 'physical_quantity' => 'm.physical_quantity',
        'reserved_quantity' => 'm.reserved_quantity', 'available_quantity' => 'm.available_quantity',
        'accounting_price' => 'm.accounting_price', 'regular_sold_units_365d' => 'm.regular_sold_units_365d',
        'one_off_sold_units_365d' => 'm.one_off_sold_units_365d',
        'health_status' => 'm.health_status', 'verification_state' => 'i.verification_state',
    ];

    $search = trim(sanitize_text_field(wp_unslash($_POST['search'] ?? '')));
    $health = strtoupper(sanitize_key(wp_unslash($_POST['health'] ?? '')));
    $verification = strtoupper(sanitize_key(wp_unslash($_POST['verification'] ?? '')));
    $alert = strtoupper(sanitize_key(wp_unslash($_POST['alertCode'] ?? '')));
    $severity = strtoupper(sanitize_key(wp_unslash($_POST['severity'] ?? '')));
    $alert_status = strtoupper(sanitize_key(wp_unslash($_POST['alertStatus'] ?? 'ANY')));
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
    $supplier_quality = strtoupper(sanitize_key(wp_unslash($_POST['supplierQuality'] ?? 'ANY')));
    $available_sign = strtoupper(sanitize_key(wp_unslash($_POST['availableSign'] ?? 'ANY')));
    $accounting_price_mode = strtoupper(sanitize_key(wp_unslash($_POST['accountingPriceMode'] ?? 'ANY')));
    $demand_period = (int)($_POST['demandPeriod'] ?? 365);
    if (!in_array($demand_period, [30, 90, 365, 730], true)) $demand_period = 365;
    $regular_demand = strtoupper(sanitize_key(wp_unslash($_POST['regularDemand'] ?? 'ANY')));
    $one_off_demand = strtoupper(sanitize_key(wp_unslash($_POST['oneOffDemand'] ?? 'ANY')));
    $finance_period = (int)($_POST['financePeriod'] ?? 365) === 90 ? 90 : 365;
    [$supplier_mode, $supplier_values] = lps_product_analytics_supplier_filter();

    $view_rules = [
        'data_issues' => ['alert' => 'DATA_ISSUE', 'sort' => 'inventory_value', 'direction' => 'DESC'],
        'stockout' => ['alert' => 'STOCKOUT', 'sort' => 'gross_profit_365d', 'direction' => 'DESC'],
        'dead_stock' => ['alert' => 'DEAD_STOCK', 'sort' => 'inventory_value', 'direction' => 'DESC'],
        'overstock' => ['alert' => 'OVERSTOCK', 'sort' => 'inventory_value', 'direction' => 'DESC'],
        'low_margin' => ['alert' => 'LOW_MARGIN', 'sort' => 'gross_profit_365d', 'direction' => 'ASC'],
        'demand_fading' => ['alert' => 'DEMAND_FADING', 'sort' => 'inventory_value', 'direction' => 'DESC'],
        'capital_no_sales' => ['regular_demand' => 'WITHOUT', 'requires_inventory' => true, 'sort' => 'inventory_value', 'direction' => 'DESC'],
        'leaders_revenue' => ['sort' => 'revenue_365d', 'direction' => 'DESC'],
        'leaders_profit' => ['sort' => 'gross_profit_365d', 'direction' => 'DESC'],
        'capital_efficiency' => ['sort' => 'gmroi_365d', 'direction' => 'ASC'],
    ];
    if (isset($view_rules[$view])) {
        $rule = $view_rules[$view];
        $alert = $rule['alert'] ?? $alert;
        $sales = $rule['sales'] ?? $sales;
        $regular_demand = $rule['regular_demand'] ?? $regular_demand;
        $sort = $rule['sort'];
        $direction = $rule['direction'];
    }

    $where = ['m.source_database=%s', 'm.warehouse_id=%d', 'm.generation_id=%d', 'i.present_in_folio=1'];
    $args = [$source, $warehouse, (int)$generation['id']];
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
    $sales_column = "m.sold_units_{$demand_period}d";
    if ($sales === 'with') $where[] = "{$sales_column}>0";
    if ($sales === 'without') $where[] = "{$sales_column}=0";
    if (!empty($view_rules[$view]['requires_inventory'])) $where[] = 'm.inventory_value>0';
    $regular_column = "m.regular_sold_units_{$demand_period}d";
    $one_off_column = "m.one_off_sold_units_{$demand_period}d";
    if ($regular_demand === 'WITH') $where[] = "{$regular_column}>0";
    if ($regular_demand === 'WITHOUT') $where[] = "{$regular_column}=0";
    if ($one_off_demand === 'WITH') $where[] = "{$one_off_column}>0";
    if ($one_off_demand === 'WITHOUT') $where[] = "{$one_off_column}=0";
    if ($one_off_demand === 'ONLY') $where[] = "{$one_off_column}>0 AND {$regular_column}=0";
    if ($inventory_min_raw !== '' && is_numeric($inventory_min_raw)) {
        $where[] = 'm.inventory_value>=%f'; $args[] = max(0, (float)$inventory_min_raw);
    }
    if ($inventory_max_raw !== '' && is_numeric($inventory_max_raw)) {
        $where[] = 'm.inventory_value<=%f'; $args[] = max(0, (float)$inventory_max_raw);
    }
    lps_product_analytics_add_numeric_range($where, $args, 'm.physical_quantity', 'physicalMin', 'physicalMax');
    lps_product_analytics_add_numeric_range($where, $args, 'm.reserved_quantity', 'reservedMin', 'reservedMax');
    lps_product_analytics_add_numeric_range($where, $args, 'm.available_quantity', 'availableMin', 'availableMax');
    $revenue_column = "m.revenue_{$finance_period}d";
    $profit_column = "m.gross_profit_{$finance_period}d";
    $average_capital_column = "m.average_inventory_{$finance_period}d";
    lps_product_analytics_add_numeric_range($where, $args, $revenue_column, 'revenueMin', 'revenueMax');
    lps_product_analytics_add_numeric_range($where, $args, $profit_column, 'profitMin', 'profitMax');
    lps_product_analytics_add_numeric_range($where, $args, $average_capital_column, 'averageCapitalMin', 'averageCapitalMax');
    lps_product_analytics_add_numeric_range($where, $args, 'm.inventory_turns_365d', 'turnsMin', 'turnsMax');
    lps_product_analytics_add_numeric_range($where, $args, 'm.gmroi_365d', 'gmroiMin', 'gmroiMax');
    lps_product_analytics_add_numeric_range($where, $args, 'm.coverage_days', 'coverageMin', 'coverageMax');
    lps_product_analytics_add_numeric_range(
        $where,
        $args,
        "(CASE WHEN {$revenue_column}>0 THEN ({$profit_column}/{$revenue_column})*100 ELSE NULL END)",
        'marginMin',
        'marginMax'
    );
    if ($available_sign === 'NON_POSITIVE') $where[] = 'm.available_quantity<=0';
    if ($available_sign === 'ZERO') $where[] = 'm.available_quantity=0';
    if ($available_sign === 'POSITIVE') $where[] = 'm.available_quantity>0';
    if ($accounting_price_mode === 'ZERO') $where[] = 'm.accounting_price=0';
    if ($accounting_price_mode === 'POSITIVE') $where[] = 'm.accounting_price>0';
    if ($supplier_quality === 'CURRENT') $where[] = "m.supplier_state='CURRENT' AND COALESCE(TRIM(m.current_supplier),'')<>'1'";
    if ($supplier_quality === 'MISSING') $where[] = "m.supplier_state='MISSING'";
    if ($supplier_quality === 'REVIEW') $where[] = "TRIM(COALESCE(m.current_supplier,''))='1'";
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $last_sale_from)) {
        $where[] = 'm.last_sale_date>=%s'; $args[] = $last_sale_from;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $last_sale_to)) {
        $where[] = 'm.last_sale_date<=%s'; $args[] = $last_sale_to;
    }
    lps_product_analytics_add_date_range($where, $args, 'm.last_regular_sale_date', 'lastRegularSaleFrom', 'lastRegularSaleTo');
    lps_product_analytics_add_date_range($where, $args, 'm.last_receipt_date', 'lastReceiptFrom', 'lastReceiptTo');
    lps_product_analytics_add_date_range($where, $args, 'i.first_movement_date', 'firstMovementFrom', 'firstMovementTo');
    lps_product_analytics_add_date_range($where, $args, 'i.last_movement_date', 'lastMovementFrom', 'lastMovementTo');
    if ($supplier_mode !== 'ANY' && $supplier_values) {
        $placeholders = implode(',', array_fill(0, count($supplier_values), '%s'));
        $where[] = $supplier_mode === 'INCLUDE'
            ? "TRIM(m.current_supplier) IN ({$placeholders})"
            : "COALESCE(TRIM(m.current_supplier),'') NOT IN ({$placeholders})";
        array_push($args, ...$supplier_values);
    }
    $alert_filters = [];
    $alert_args = [];
    if (in_array($alert_status, ['ACTIVE', 'RESOLVED'], true)) {
        $alert_filters[] = 'af.status=%s';
        $alert_args[] = $alert_status;
    }
    if (in_array($alert, $allowed_alerts, true)) {
        $alert_filters[] = 'af.alert_code=%s'; $alert_args[] = $alert;
    }
    if (in_array($severity, $allowed_severities, true)) {
        $alert_filters[] = 'af.severity=%s'; $alert_args[] = $severity;
    }
    $alert_date_ranges = [
        ['af.first_seen_at', 'alertFirstSeenFrom', 'alertFirstSeenTo'],
        ['af.last_seen_at', 'alertLastSeenFrom', 'alertLastSeenTo'],
    ];
    foreach ($alert_date_ranges as [$column, $from_key, $to_key]) {
        lps_product_analytics_add_date_range($alert_filters, $alert_args, $column, $from_key, $to_key);
    }
    if ($alert_filters) {
        $where[] = "EXISTS (SELECT 1 FROM {$t['alert']} af WHERE af.source_database=m.source_database AND af.warehouse_id=m.warehouse_id AND af.sku=m.sku AND af.generation_id=m.generation_id AND " . implode(' AND ', $alert_filters) . ')';
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
                  FROM {$t['alert']} WHERE status='ACTIVE' AND generation_id=" . (int)$generation['id'] . "
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

function lps_product_analytics_movements(string $source, int $warehouse): array {
    global $wpdb;
    $t = lps_product_analytics_tables();
    $generation = lps_product_analytics_active_generation($source, $warehouse);
    if ((int)($generation['analytics_schema_version'] ?? 1) < 2) {
        wp_send_json_error(['message' => __('This snapshot must be rebuilt with analytics schema v2.', 'lavka-price-sync')], 409);
    }
    lps_product_analytics_require_movement_table();

    $where = ['f.source_database=%s', 'f.warehouse_id=%d', 'f.generation_id=%d'];
    $args = [$source, $warehouse, (int)$generation['id']];
    lps_product_analytics_add_date_range($where, $args, 'f.document_date', 'documentDateFrom', 'documentDateTo');

    $sku = lps_product_analytics_post_value('movementSku');
    if ($sku !== '') {
        $where[] = 'f.sku LIKE %s';
        $args[] = '%' . $wpdb->esc_like($sku) . '%';
    }
    $document_number = lps_product_analytics_post_value('documentNumber');
    if ($document_number !== '') {
        $where[] = 'CAST(f.document_number AS CHAR) LIKE %s';
        $args[] = '%' . $wpdb->esc_like($document_number) . '%';
    }

    $enum_filters = [
        'documentType' => ['f.document_type', null],
        'operationKind' => ['f.operation_kind', null],
        'movementClass' => ['f.movement_class', ['SALE','CUSTOMER_RETURN','SUPPLIER_RETURN','PURCHASE_RECEIPT','OTHER_RECEIPT','INTERNAL_RECEIPT','INTERNAL_EXPENSE','TRANSFER_IN','TRANSFER_OUT','ASSEMBLY_INPUT','ASSEMBLY_OUTPUT','INVENTORY_CORRECTION_IN','INVENTORY_CORRECTION_OUT','DEFECT_IN','DEFECT_OUT','INTERNAL_USE_IN','INTERNAL_USE_OUT','MARKETING_IN','MARKETING_OUT','RESERVATION','OTHER_EXPENSE','UNCLASSIFIED']],
        'stockDirection' => ['f.stock_direction', ['IN','OUT','NONE']],
        'demandMode' => ['f.demand_mode', ['REGULAR','ONE_OFF_ORDER','NOT_APPLICABLE']],
        'paymentTerms' => ['f.payment_terms', ['PREPAYMENT','DEFERRED_30','DEFERRED_60','DEFERRED_90','DEFERRED_180','ON_FACT','NOT_SPECIFIED']],
        'customerSegment' => ['f.customer_segment', ['RETAIL','NON_RETAIL','UNKNOWN','NOT_APPLICABLE']],
    ];
    foreach ($enum_filters as $key => [$column, $allowed]) {
        $value = lps_product_analytics_post_value($key);
        if ($value === '' || ($allowed !== null && !in_array($value, $allowed, true))) continue;
        $where[] = "{$column}=%s";
        $args[] = $value;
    }

    $boolean_filters = [
        'accounted' => 'f.accounted',
        'returnFlag' => 'f.return_flag',
        'affectsStock' => 'f.affects_stock',
        'affectsFinancialSales' => 'f.affects_financial_sales',
        'affectsPlanningDemand' => 'f.affects_planning_demand',
    ];
    foreach ($boolean_filters as $key => $column) {
        $value = lps_product_analytics_post_value($key);
        if (!in_array($value, ['0', '1'], true)) continue;
        $where[] = "{$column}=%d";
        $args[] = (int)$value;
    }

    $counterparty = lps_product_analytics_post_value('counterparty');
    if ($counterparty !== '') {
        $like = '%' . $wpdb->esc_like($counterparty) . '%';
        $where[] = '(f.counterparty_short_name LIKE %s OR f.counterparty_name LIKE %s)';
        $args[] = $like;
        $args[] = $like;
    }
    $supplier = lps_product_analytics_post_value('movementSupplier');
    if ($supplier !== '') {
        $where[] = 'f.current_supplier LIKE %s';
        $args[] = '%' . $wpdb->esc_like($supplier) . '%';
    }

    $per_page = absint($_POST['movementPerPage'] ?? 50);
    if (!in_array($per_page, [20, 50, 100], true)) $per_page = 50;
    $page = max(1, absint($_POST['movementPage'] ?? 1));
    $where_sql = implode(' AND ', $where);
    $total = (int)$wpdb->get_var(lps_product_analytics_prepare(
        "SELECT COUNT(*) FROM {$t['movement']} f WHERE {$where_sql}",
        $args
    ));
    lps_product_analytics_database_error();
    $pages = max(1, (int)ceil($total / $per_page));
    $page = min($page, $pages);
    $offset = ($page - 1) * $per_page;

    $items = $wpdb->get_results(lps_product_analytics_prepare(
        "SELECT f.movement_recno, f.document_id, f.document_number, f.document_date,
                f.sku, f.quantity, f.signed_quantity, f.sale_amount, f.accounting_value,
                f.document_type, f.operation_kind, f.movement_class, f.stock_direction, f.demand_mode,
                f.payment_terms, f.customer_segment, f.accounted, f.return_flag,
                f.counterparty_short_name, f.counterparty_name, f.current_supplier,
                f.supplier_state, f.affects_stock, f.affects_financial_sales,
                f.affects_planning_demand
           FROM {$t['movement']} f
          WHERE {$where_sql}
          ORDER BY f.document_date DESC, f.movement_recno DESC
          LIMIT %d OFFSET %d",
        array_merge($args, [$per_page, $offset])
    ), ARRAY_A) ?: [];
    lps_product_analytics_database_error();

    return compact('items', 'total', 'page', 'pages', 'per_page');
}

function lps_product_analytics_product(string $source, int $warehouse): array {
    global $wpdb;
    $t = lps_product_analytics_tables();
    $generation = lps_product_analytics_active_generation($source, $warehouse);
    $sku = trim(sanitize_text_field(wp_unslash($_POST['sku'] ?? '')));
    if ($sku === '') wp_send_json_error(['message' => __('A product SKU is required.', 'lavka-price-sync')], 400);

    $current = $wpdb->get_row($wpdb->prepare(
        "SELECT m.*, i.verification_state, i.present_in_folio, i.first_seen_at, i.last_seen_at, i.last_observed_at, i.last_error
           FROM {$t['current']} m JOIN {$t['item']} i
             ON i.source_database=m.source_database AND i.warehouse_id=m.warehouse_id AND i.sku=m.sku
          WHERE m.source_database=%s AND m.warehouse_id=%d AND m.generation_id=%d AND m.sku=%s LIMIT 1",
        [$source, $warehouse, (int)$generation['id'], $sku]
    ), ARRAY_A);
    if (!$current) wp_send_json_error(['message' => __('The requested product was not found in this snapshot.', 'lavka-price-sync')], 404);

    $monthly = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$t['monthly']} WHERE source_database=%s AND warehouse_id=%d AND generation_id=%d AND sku=%s ORDER BY month_start ASC",
        [$source, $warehouse, (int)$generation['id'], $sku]
    ), ARRAY_A) ?: [];
    $alerts = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$t['alert']} WHERE source_database=%s AND warehouse_id=%d AND generation_id=%d AND sku=%s ORDER BY status='ACTIVE' DESC, last_seen_at DESC",
        [$source, $warehouse, (int)$generation['id'], $sku]
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

function lps_product_analytics_v4_source_database(): string {
    return sanitize_text_field((string)apply_filters('lps_product_analytics_source_database', 'Paint_Ua'));
}

function lps_product_analytics_v4_payload(): array {
    $raw = (string)wp_unslash($_POST['payloadJson'] ?? '');
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        wp_send_json_error(['message' => __('The product-analytics request is invalid.', 'lavka-price-sync')], 400);
    }
    return $payload;
}

function lps_product_analytics_v4_warehouse_ids($values): array {
    if (!is_array($values)) return [];
    $ids = array_map('absint', $values);
    $ids = array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));
    sort($ids, SORT_NUMERIC);
    return array_slice($ids, 0, 50);
}

function lps_product_analytics_v4_selection($value, int $limit = 500): ?array {
    if (!is_array($value)) return null;
    $mode = strtoupper(sanitize_key((string)($value['mode'] ?? 'ANY')));
    if (!in_array($mode, ['ANY', 'INCLUDE', 'EXCLUDE'], true)) $mode = 'ANY';
    $values = lps_analytics_scenario_sanitize_values($value['values'] ?? [], $limit);
    if (!$values || $mode === 'ANY') return ['mode' => 'ANY', 'values' => []];
    return ['mode' => $mode, 'values' => $values];
}

function lps_product_analytics_v4_sanitize_filter_map(array $input, array $selection_keys, bool $allow_search = false): array {
    $clean = [];
    if ($allow_search && isset($input['search'])) {
        $search = sanitize_text_field((string)$input['search']);
        if (function_exists('mb_substr')) $search = mb_substr($search, 0, 200);
        else $search = substr($search, 0, 200);
        if ($search !== '') $clean['search'] = $search;
    }
    foreach ($selection_keys as $key) {
        if (!array_key_exists($key, $input)) continue;
        $selection = lps_product_analytics_v4_selection($input[$key]);
        if ($selection !== null && $selection['mode'] !== 'ANY') $clean[$key] = $selection;
    }
    return $clean;
}

function lps_product_analytics_v4_sanitize_query(array $payload): array {
    $source = sanitize_text_field((string)($payload['sourceDatabase'] ?? lps_product_analytics_v4_source_database()));
    if (!preg_match('/^[A-Za-z0-9_]+$/', $source)) {
        wp_send_json_error(['message' => __('The Folio source database is invalid.', 'lavka-price-sync')], 400);
    }
    $warehouse_ids = lps_product_analytics_v4_warehouse_ids($payload['warehouseIds'] ?? []);
    if (!$warehouse_ids) {
        wp_send_json_error(['message' => __('Select one or more Folio warehouses.', 'lavka-price-sync')], 400);
    }

    $period = is_array($payload['period'] ?? null) ? $payload['period'] : [];
    $from = sanitize_text_field((string)($period['from'] ?? ''));
    $to = sanitize_text_field((string)($period['to'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        wp_send_json_error(['message' => __('Select a valid report period.', 'lavka-price-sync')], 400);
    }

    $product_input = is_array($payload['productFilters'] ?? null) ? $payload['productFilters'] : [];
    $movement_input = is_array($payload['movementFilters'] ?? null) ? $payload['movementFilters'] : [];
    $product_filters = lps_product_analytics_v4_sanitize_filter_map($product_input, [
        'skus', 'groups', 'groupLevel1', 'groupLevel2', 'groupLevel3', 'groupLevel4',
        'groupLevel5', 'groupLevel6', 'departments', 'productTypes', 'units',
        'currentSuppliers', 'supplierStates', 'brands', 'barcodes',
    ], true);
    $movement_filters = lps_product_analytics_v4_sanitize_filter_map($movement_input, [
        'operationKinds', 'movementClasses', 'demandModes', 'documentTypes',
        'stockDirections', 'paymentTerms', 'customerSegments', 'counterparties',
        'organizationTypes', 'salesManagerCodes', 'sourceWarehouseIds', 'destinationWarehouseIds',
    ]);

    $calculation_input = is_array($payload['calculation'] ?? null) ? $payload['calculation'] : [];
    $abc_basis = strtoupper(sanitize_key((string)($calculation_input['abcBasis'] ?? 'GROSS_PROFIT')));
    if (!in_array($abc_basis, ['REVENUE', 'GROSS_PROFIT', 'SOLD_UNITS'], true)) $abc_basis = 'GROSS_PROFIT';
    $calculation = [
        'abcBasis' => $abc_basis,
        'includeReturns' => !isset($calculation_input['includeReturns']) || rest_sanitize_boolean($calculation_input['includeReturns']),
    ];

    $page_input = is_array($payload['page'] ?? null) ? $payload['page'] : [];
    $page_size = max(1, min(500, absint($page_input['size'] ?? 50)));
    $cursor = sanitize_text_field((string)($page_input['cursor'] ?? ''));

    $sort_input = is_array($payload['sort'] ?? null) ? $payload['sort'] : [];
    $allowed_sort = ['sku', 'productName', 'physicalQuantity', 'inventoryValue', 'soldUnits', 'salesRevenue', 'salesCogs', 'grossProfit', 'averageInventoryValue'];
    $sort = [];
    foreach (array_slice($sort_input, 0, 5) as $item) {
        if (!is_array($item)) continue;
        $field = sanitize_text_field((string)($item['field'] ?? ''));
        if (!in_array($field, $allowed_sort, true)) continue;
        $sort[] = [
            'field' => $field,
            'direction' => strtoupper((string)($item['direction'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC',
        ];
    }
    if (!$sort) $sort[] = ['field' => 'grossProfit', 'direction' => 'DESC'];

    return [
        'sourceDatabase' => $source,
        'warehouseIds' => $warehouse_ids,
        'period' => ['from' => $from, 'to' => $to],
        'productFilters' => $product_filters ?: new stdClass(),
        'movementFilters' => $movement_filters ?: new stdClass(),
        'calculation' => $calculation,
        'page' => ['size' => $page_size, 'cursor' => $cursor !== '' ? $cursor : null],
        'sort' => $sort,
    ];
}

function lps_product_analytics_v4_send_java(string $path, array $payload): void {
    $options = lps_get_options();
    if (empty($options['java_base_url'])) {
        wp_send_json_error(['message' => __('Java Base URL is not configured.', 'lavka-price-sync')], 503);
    }
    $response = lps_java_post($path, $payload, ['timeout' => max(30, (int)($options['timeout'] ?? 160))]);
    if (is_wp_error($response)) {
        wp_send_json_error([
            'message' => $response->get_error_message(),
            'httpStatus' => 0,
        ], 502);
    }
    $http_status = (int)wp_remote_retrieve_response_code($response);
    $raw = (string)wp_remote_retrieve_body($response);
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        wp_send_json_error([
            'message' => __('The Folio analytics service returned an invalid response.', 'lavka-price-sync'),
            'httpStatus' => $http_status,
            'raw' => function_exists('mb_substr') ? mb_substr($raw, 0, 4000) : substr($raw, 0, 4000),
        ], 502);
    }
    if ($http_status < 200 || $http_status >= 300 || empty($body['ok'])) {
        $message = sanitize_text_field((string)($body['message'] ?? $body['title'] ?? $body['error'] ?? __('The Folio analytics request failed.', 'lavka-price-sync')));
        wp_send_json_error([
            'message' => $message,
            'code' => sanitize_text_field((string)($body['code'] ?? '')),
            'httpStatus' => $http_status,
            'body' => $body,
        ], in_array($http_status, [400, 403, 404, 409, 422, 429, 503], true) ? $http_status : 502);
    }
    wp_send_json_success($body);
}

function lps_product_analytics_v4_bootstrap(): array {
    $directory = function_exists('lps_accounting_prices_warehouse_directory')
        ? lps_accounting_prices_warehouse_directory()
        : ['ok' => false, 'items' => [], 'message' => __('The warehouse directory is unavailable.', 'lavka-price-sync')];
    return [
        'sourceDatabase' => lps_product_analytics_v4_source_database(),
        'warehouses' => array_values((array)($directory['items'] ?? [])),
        'warehouseDirectoryReady' => !empty($directory['ok']),
        'warehouseDirectoryMessage' => sanitize_text_field((string)($directory['message'] ?? '')),
        'scenarios' => function_exists('lps_analytics_scenarios_list') ? lps_analytics_scenarios_list(false) : [],
    ];
}

function lps_product_analytics_ajax(): void {
    if (!current_user_can(LPS_CAP)) {
        wp_send_json_error(['message' => __('You do not have permission to view product analytics.', 'lavka-price-sync')], 403);
    }
    check_ajax_referer(LPS_PRODUCT_ANALYTICS_NONCE);
    $operation = sanitize_key(wp_unslash($_POST['operation'] ?? ''));

    if ($operation === 'v4_bootstrap') {
        wp_send_json_success(lps_product_analytics_v4_bootstrap());
    }
    if ($operation === 'v4_capabilities') {
        $payload = lps_product_analytics_v4_payload();
        $source = sanitize_text_field((string)($payload['sourceDatabase'] ?? lps_product_analytics_v4_source_database()));
        $warehouse_ids = lps_product_analytics_v4_warehouse_ids($payload['warehouseIds'] ?? []);
        if ($source === '' || !$warehouse_ids) {
            wp_send_json_error(['message' => __('Select one or more Folio warehouses.', 'lavka-price-sync')], 400);
        }
        lps_product_analytics_v4_send_java(LPS_PRODUCT_ANALYTICS_CAPABILITIES_PATH, [
            'sourceDatabase' => $source,
            'warehouseIds' => $warehouse_ids,
        ]);
    }
    if ($operation === 'v4_query') {
        lps_product_analytics_v4_send_java(
            LPS_PRODUCT_ANALYTICS_QUERY_PATH,
            lps_product_analytics_v4_sanitize_query(lps_product_analytics_v4_payload())
        );
    }

    $missing = lps_product_analytics_missing_tables();
    if ($missing) {
        wp_send_json_error([
            'message' => __('The Folio analytics tables have not been published to this WordPress database yet.', 'lavka-price-sync'),
            'missingTables' => $missing,
        ], 503);
    }

    if ($operation === 'scopes') {
        $data = ['items' => lps_product_analytics_scopes()];
    } elseif ($operation === 'preset_list') {
        $data = ['items' => lps_product_analytics_presets()];
    } elseif ($operation === 'preset_save') {
        $data = lps_product_analytics_save_preset();
    } elseif ($operation === 'preset_delete') {
        $data = lps_product_analytics_delete_preset();
    } else {
        [$source, $warehouse] = lps_product_analytics_scope();
        switch ($operation) {
            case 'summary': $data = lps_product_analytics_summary($source, $warehouse); break;
            case 'filter_options': $data = lps_product_analytics_filter_options($source, $warehouse); break;
            case 'products': $data = lps_product_analytics_products($source, $warehouse); break;
            case 'movements': $data = lps_product_analytics_movements($source, $warehouse); break;
            case 'product': $data = lps_product_analytics_product($source, $warehouse); break;
            default:
                wp_send_json_error(['message' => __('Unsupported product-analytics operation.', 'lavka-price-sync')], 400);
        }
    }
    lps_product_analytics_database_error();
    wp_send_json_success($data);
}
add_action('wp_ajax_lps_product_analytics', 'lps_product_analytics_ajax');
