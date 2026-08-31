<?php
if (!defined('ABSPATH')) exit;

const LPS_PRODUCT_ANALYTICS_PAGE = 'lps-product-analytics';
const LPS_PRODUCT_ANALYTICS_NONCE = 'lps_product_analytics';
const LPS_PRODUCT_ANALYTICS_PRESETS_META = 'lps_product_analytics_filter_presets';

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
        'selectPreset' => __('Select a saved filter set', 'lavka-price-sync'),
        'presetSaved' => __('The filter set has been saved.', 'lavka-price-sync'),
        'presetDeleted' => __('The filter set has been deleted.', 'lavka-price-sync'),
        'presetNameRequired' => __('Enter a name for the filter set.', 'lavka-price-sync'),
        'confirmPresetDelete' => __('Delete the selected filter set?', 'lavka-price-sync'),
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

        <section class="lps-pa-presets" aria-label="<?php echo esc_attr__('Saved filter sets', 'lavka-price-sync'); ?>">
            <strong><?php echo esc_html__('Saved filter sets', 'lavka-price-sync'); ?></strong>
            <select id="lps-pa-preset-select" aria-label="<?php echo esc_attr__('Select a saved filter set', 'lavka-price-sync'); ?>">
                <option value=""><?php echo esc_html__('Select a saved filter set', 'lavka-price-sync'); ?></option>
            </select>
            <input type="text" id="lps-pa-preset-name" maxlength="80" placeholder="<?php echo esc_attr__('Filter set name', 'lavka-price-sync'); ?>">
            <button type="button" class="button button-primary" id="lps-pa-preset-save"><?php echo esc_html__('Save filter set', 'lavka-price-sync'); ?></button>
            <button type="button" class="button" id="lps-pa-preset-delete" disabled><?php echo esc_html__('Delete selected set', 'lavka-price-sync'); ?></button>
        </section>

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
