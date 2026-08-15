<?php

defined('ABSPATH') || exit;

const PC_FOLIO_DOCUMENTS_ENDPOINT = 'folio-documents';

function pc_folio_documents_register_endpoint(): void {
    add_rewrite_endpoint(PC_FOLIO_DOCUMENTS_ENDPOINT, EP_ROOT | EP_PAGES);
}
add_action('init', 'pc_folio_documents_register_endpoint');

function pc_folio_documents_query_vars(array $query_vars): array {
    $query_vars[PC_FOLIO_DOCUMENTS_ENDPOINT] = PC_FOLIO_DOCUMENTS_ENDPOINT;
    return $query_vars;
}
add_filter('woocommerce_get_query_vars', 'pc_folio_documents_query_vars');

function pc_folio_documents_account_menu(array $items): array {
    if (!pc_folio_balance_user_context()) {
        return $items;
    }

    $balance = $items[PC_FOLIO_BALANCE_ENDPOINT] ?? null;
    $logout = $items['customer-logout'] ?? null;
    unset($items[PC_FOLIO_BALANCE_ENDPOINT], $items['customer-logout']);
    $items[PC_FOLIO_DOCUMENTS_ENDPOINT] = __('Folio documents', 'pc-folio-customer-balance');
    if ($balance !== null) {
        $items[PC_FOLIO_BALANCE_ENDPOINT] = $balance;
    }
    if ($logout !== null) {
        $items['customer-logout'] = $logout;
    }
    return $items;
}
add_filter('woocommerce_account_menu_items', 'pc_folio_documents_account_menu', 90);

function pc_folio_documents_is_endpoint(): bool {
    return function_exists('is_wc_endpoint_url') && is_wc_endpoint_url(PC_FOLIO_DOCUMENTS_ENDPOINT);
}

function pc_folio_documents_enqueue_assets(): void {
    $context = pc_folio_balance_user_context();
    if (!pc_folio_documents_is_endpoint() || !$context) {
        return;
    }

    $base_url = content_url('/mu-plugins/pc-folio-customer-balance/assets/');
    wp_enqueue_style('pc-folio-customer-documents', $base_url . 'customer-documents.css', [], PC_FOLIO_BALANCE_VERSION);
    wp_enqueue_script('pc-folio-customer-documents', $base_url . 'customer-documents.js', [], PC_FOLIO_BALANCE_VERSION, true);
    wp_localize_script('pc-folio-customer-documents', 'pcFolioDocuments', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('pc_folio_customer_documents'),
        'today'   => current_time('Y-m-d'),
        'labels'  => [
            'loading'       => __('Folio documents are being loaded...', 'pc-folio-customer-balance'),
            'detailsLoading'=> __('Document details are being loaded...', 'pc-folio-customer-balance'),
            'requestFailed' => __('Folio documents could not be loaded. Please try again later.', 'pc-folio-customer-balance'),
            'empty'         => __('No documents were found for the selected period.', 'pc-folio-customer-balance'),
            'requestId'     => __('Request ID: %s', 'pc-folio-customer-balance'),
            'activeOnly'    => __('Only active Folio documents are included. Archived documents are not shown yet.', 'pc-folio-customer-balance'),
            'currency'      => __('UAH', 'pc-folio-customer-balance'),
            'yes'           => __('Yes', 'pc-folio-customer-balance'),
            'no'            => __('No', 'pc-folio-customer-balance'),
            'notSpecified'  => __('Not specified', 'pc-folio-customer-balance'),
            'accounted'     => __('Included', 'pc-folio-customer-balance'),
            'notAccounted'  => __('Not included', 'pc-folio-customer-balance'),
            'productMissing'=> __('The product is not available on the site.', 'pc-folio-customer-balance'),
            'stockEmpty'    => __('Out of stock', 'pc-folio-customer-balance'),
            'stockTotal'    => __('Total stock: %s', 'pc-folio-customer-balance'),
            'cartSuccess'   => __('The selected items were added to the cart.', 'pc-folio-customer-balance'),
            'draftSuccess'  => __('A draft order was created from the selected items.', 'pc-folio-customer-balance'),
            'partialSuccess'=> __('Some items could not be added. Review the list below.', 'pc-folio-customer-balance'),
            'selectItems'   => __('Select at least one item.', 'pc-folio-customer-balance'),
            'selectAll'     => __('Select all available items', 'pc-folio-customer-balance'),
            'selectionHelp' => __('All available items are selected by default. Clear the items you do not want to add.', 'pc-folio-customer-balance'),
            'types' => [
                'ACCOUNT' => __('Account', 'pc-folio-customer-balance'),
                'EXPENSE' => __('Expense invoice', 'pc-folio-customer-balance'),
                'PAYMENT' => __('Payment', 'pc-folio-customer-balance'),
            ],
            'fields' => [
                'documentTypeLabel'    => __('Document type', 'pc-folio-customer-balance'),
                'documentNumber'       => __('Document number', 'pc-folio-customer-balance'),
                'documentNumberSuffix' => __('Document number suffix', 'pc-folio-customer-balance'),
                'documentDate'         => __('Document date', 'pc-folio-customer-balance'),
                'totalAmount'          => __('Total amount', 'pc-folio-customer-balance'),
                'warehouseLabel'       => __('Folio warehouse', 'pc-folio-customer-balance'),
                'operationKind'        => __('Operation kind', 'pc-folio-customer-balance'),
                'accounted'            => __('Included in accounting', 'pc-folio-customer-balance'),
                'nonCash'              => __('Non-cash payment', 'pc-folio-customer-balance'),
                'returnDocument'       => __('Return document', 'pc-folio-customer-balance'),
                'allocatedAmount'      => __('Allocated amount', 'pc-folio-customer-balance'),
                'lineCount'            => __('Number of items', 'pc-folio-customer-balance'),
                'canRepeatOrder'       => __('Can be repeated as an order', 'pc-folio-customer-balance'),
                'information'          => __('Information', 'pc-folio-customer-balance'),
                'payerCity'            => __('Customer city', 'pc-folio-customer-balance'),
                'directorName'         => __('Director', 'pc-folio-customer-balance'),
                'accountantName'       => __('Accountant', 'pc-folio-customer-balance'),
                'payerPhone'           => __('Customer phone', 'pc-folio-customer-balance'),
                'deliveryInfo'         => __('Delivery information', 'pc-folio-customer-balance'),
            ],
            'repeatReasons' => [
                'PAYMENT_NOT_REPEATABLE'       => __('A payment cannot be repeated as an order.', 'pc-folio-customer-balance'),
                'RETURN_DOCUMENT'              => __('A return document cannot be repeated as a regular order.', 'pc-folio-customer-balance'),
                'NO_REPEATABLE_ITEMS'          => __('The document has no items available for repeat order.', 'pc-folio-customer-balance'),
                'DOCUMENT_TYPE_NOT_REPEATABLE' => __('This document type cannot be repeated.', 'pc-folio-customer-balance'),
            ],
        ],
    ]);
}
add_action('wp_enqueue_scripts', 'pc_folio_documents_enqueue_assets');

function pc_folio_documents_render_endpoint(): void {
    $context = pc_folio_balance_user_context();
    if (!$context) {
        echo '<p class="woocommerce-error">' . esc_html__('This report is not available for your account.', 'pc-folio-customer-balance') . '</p>';
        return;
    }

    $date_to = current_time('Y-m-d');
    $date_from = current_datetime()->modify('-1 month')->format('Y-m-d');
    ?>
    <section class="pc-folio-documents"
        data-pc-folio-documents
        data-view-label="<?php echo esc_attr__('View', 'pc-folio-customer-balance'); ?>"
        data-types-required="<?php echo esc_attr__('Select at least one document type.', 'pc-folio-customer-balance'); ?>"
        data-requisites-label="<?php echo esc_attr__('Document requisites', 'pc-folio-customer-balance'); ?>"
        data-additional-label="<?php echo esc_attr__('Additional requisites', 'pc-folio-customer-balance'); ?>"
        data-items-label="<?php echo esc_attr__('Document items', 'pc-folio-customer-balance'); ?>"
        data-line-label="<?php echo esc_attr__('Line', 'pc-folio-customer-balance'); ?>"
        data-sku-label="<?php echo esc_attr__('SKU', 'pc-folio-customer-balance'); ?>"
        data-name-label="<?php echo esc_attr__('Name', 'pc-folio-customer-balance'); ?>"
        data-requested-quantity-label="<?php echo esc_attr__('Requested quantity', 'pc-folio-customer-balance'); ?>"
        data-quantity-label="<?php echo esc_attr__('Quantity', 'pc-folio-customer-balance'); ?>"
        data-repeatable-label="<?php echo esc_attr__('Available for repeat order', 'pc-folio-customer-balance'); ?>"
        data-current-stock-label="<?php echo esc_attr__('Current stock by warehouse', 'pc-folio-customer-balance'); ?>"
        data-product-label="<?php echo esc_attr__('Product', 'pc-folio-customer-balance'); ?>"
        data-select-label="<?php echo esc_attr__('Select', 'pc-folio-customer-balance'); ?>"
        data-price-label="<?php echo esc_attr__('Historical price', 'pc-folio-customer-balance'); ?>"
        data-amount-label="<?php echo esc_attr__('Amount', 'pc-folio-customer-balance'); ?>"
        data-payments-label="<?php echo esc_attr__('Linked payments', 'pc-folio-customer-balance'); ?>"
        data-allocations-label="<?php echo esc_attr__('Payment allocations', 'pc-folio-customer-balance'); ?>"
        data-note-label="<?php echo esc_attr__('Note', 'pc-folio-customer-balance'); ?>"
        data-type-label="<?php echo esc_attr__('Type', 'pc-folio-customer-balance'); ?>"
        data-repeat-items-label="<?php echo esc_attr__('Items available for repeat order', 'pc-folio-customer-balance'); ?>"
        data-repeat-notice="<?php echo esc_attr__('Historical prices are shown for reference only. A future repeat order will use current WooCommerce prices.', 'pc-folio-customer-balance'); ?>"
        data-repeat-unavailable="<?php echo esc_attr__('This document cannot be repeated as an order.', 'pc-folio-customer-balance'); ?>"
        data-historical-price-label="<?php echo esc_attr__('Historical price', 'pc-folio-customer-balance'); ?>"
        data-add-cart-label="<?php echo esc_attr__('Add selected items to cart', 'pc-folio-customer-balance'); ?>"
        data-add-draft-label="<?php echo esc_attr__('Save selected items as draft order', 'pc-folio-customer-balance'); ?>"
        data-open-cart-label="<?php echo esc_attr__('Open cart', 'pc-folio-customer-balance'); ?>"
        data-open-draft-label="<?php echo esc_attr__('Open draft order', 'pc-folio-customer-balance'); ?>"
        data-payment-requisites-label="<?php echo esc_attr__('Payment requisites', 'pc-folio-customer-balance'); ?>"
        data-payment-requisites-empty="<?php echo esc_attr__('Payment requisites have not yet been provided by Folio.', 'pc-folio-customer-balance'); ?>"
        aria-labelledby="pc-folio-documents-title">
        <header class="pc-folio-documents__header">
            <div>
                <h2 id="pc-folio-documents-title"><?php esc_html_e('Folio documents', 'pc-folio-customer-balance'); ?></h2>
                <p><strong><?php echo esc_html($context['name'] !== '' ? $context['name'] : $context['short_name']); ?></strong> <code><?php echo esc_html($context['short_name']); ?></code></p>
            </div>
        </header>

        <form class="pc-folio-documents__filters" data-pc-documents-form>
            <label><span><?php esc_html_e('Period start', 'pc-folio-customer-balance'); ?></span><input type="date" name="date_from" value="<?php echo esc_attr($date_from); ?>" max="<?php echo esc_attr($date_to); ?>" required></label>
            <label><span><?php esc_html_e('Period end', 'pc-folio-customer-balance'); ?></span><input type="date" name="date_to" value="<?php echo esc_attr($date_to); ?>" max="<?php echo esc_attr($date_to); ?>" required></label>
            <fieldset>
                <legend><?php esc_html_e('Document types', 'pc-folio-customer-balance'); ?></legend>
                <label><input type="checkbox" name="types" value="ACCOUNT" checked> <?php esc_html_e('Accounts', 'pc-folio-customer-balance'); ?></label>
                <label><input type="checkbox" name="types" value="EXPENSE" checked> <?php esc_html_e('Expense documents', 'pc-folio-customer-balance'); ?></label>
                <label><input type="checkbox" name="types" value="PAYMENT" checked> <?php esc_html_e('Payments', 'pc-folio-customer-balance'); ?></label>
            </fieldset>
            <label><span><?php esc_html_e('Rows per page', 'pc-folio-customer-balance'); ?></span><select name="limit"><option>25</option><option selected>50</option><option>100</option></select></label>
            <button type="submit" class="button"><?php esc_html_e('Show documents', 'pc-folio-customer-balance'); ?></button>
        </form>

        <div class="pc-folio-documents__status" data-pc-documents-status role="status" aria-live="polite"></div>
        <div class="pc-folio-documents__warning" data-pc-documents-warning hidden></div>
        <div class="pc-folio-documents__table-wrap" data-pc-documents-table-wrap hidden>
            <table class="pc-folio-documents__table">
                <thead><tr>
                    <th><?php esc_html_e('Type', 'pc-folio-customer-balance'); ?></th>
                    <th><?php esc_html_e('Document number', 'pc-folio-customer-balance'); ?></th>
                    <th><?php esc_html_e('Date', 'pc-folio-customer-balance'); ?></th>
                    <th><?php esc_html_e('Amount', 'pc-folio-customer-balance'); ?></th>
                    <th><?php esc_html_e('Folio warehouse', 'pc-folio-customer-balance'); ?></th>
                    <th><?php esc_html_e('Accounting', 'pc-folio-customer-balance'); ?></th>
                    <th><?php esc_html_e('Information', 'pc-folio-customer-balance'); ?></th>
                    <th><?php esc_html_e('Items', 'pc-folio-customer-balance'); ?></th>
                    <th><?php esc_html_e('Actions', 'pc-folio-customer-balance'); ?></th>
                </tr></thead>
                <tbody data-pc-documents-rows></tbody>
            </table>
        </div>
        <nav class="pc-folio-documents__pagination" data-pc-documents-pagination hidden aria-label="<?php esc_attr_e('Document pages', 'pc-folio-customer-balance'); ?>">
            <button type="button" class="button" data-pc-documents-prev><?php esc_html_e('Previous page', 'pc-folio-customer-balance'); ?></button>
            <span data-pc-documents-page></span>
            <button type="button" class="button" data-pc-documents-next><?php esc_html_e('Next page', 'pc-folio-customer-balance'); ?></button>
        </nav>

        <section class="pc-folio-documents__detail" data-pc-document-detail hidden aria-labelledby="pc-folio-document-detail-title">
            <div class="pc-folio-documents__detail-header">
                <h3 id="pc-folio-document-detail-title" data-pc-document-detail-title></h3>
                <button type="button" class="button" data-pc-document-close><?php esc_html_e('Close details', 'pc-folio-customer-balance'); ?></button>
            </div>
            <div data-pc-document-detail-content></div>
        </section>
    </section>
    <?php
}
add_action('woocommerce_account_' . PC_FOLIO_DOCUMENTS_ENDPOINT . '_endpoint', 'pc_folio_documents_render_endpoint');

function pc_folio_documents_request_context() {
    $context = pc_folio_balance_user_context();
    if (!$context) {
        return new WP_Error('forbidden', __('This report is not available for your account.', 'pc-folio-customer-balance'), ['status' => 403]);
    }
    if ((function_exists('mb_strlen') ? mb_strlen($context['short_name'], 'UTF-8') : strlen($context['short_name'])) > 8) {
        return new WP_Error('invalid_mapping', __('The Folio customer mapping is invalid. Please contact the manager.', 'pc-folio-customer-balance'), ['status' => 422]);
    }
    return $context;
}

function pc_folio_documents_java_get(string $path, array $query) {
    if (!function_exists('lps_java_get')) {
        return new WP_Error('folio_connection_unavailable', __('The Folio service connection is unavailable.', 'pc-folio-customer-balance'), ['status' => 503]);
    }
    $response = lps_java_get(add_query_arg($query, $path), ['timeout' => 160]);
    if (is_wp_error($response)) {
        return new WP_Error('folio_unavailable', __('The Folio service is temporarily unavailable.', 'pc-folio-customer-balance'), ['status' => 503]);
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    $data = json_decode((string) wp_remote_retrieve_body($response), true);
    if ($code < 200 || $code >= 300 || !is_array($data)) {
        $messages = [
            400 => __('The document request parameters were rejected by Folio.', 'pc-folio-customer-balance'),
            404 => __('The requested Folio document was not found.', 'pc-folio-customer-balance'),
            503 => __('The Folio service is temporarily unavailable.', 'pc-folio-customer-balance'),
        ];
        return new WP_Error('folio_documents_http_error', $messages[$code] ?? __('Folio documents could not be loaded. Please try again later.', 'pc-folio-customer-balance'), [
            'status' => $code >= 400 && $code < 600 ? $code : 502,
            'reqId' => is_array($data) ? sanitize_text_field((string) ($data['reqId'] ?? $data['requestId'] ?? '')) : '',
        ]);
    }
    if (($data['ok'] ?? false) !== true) {
        return new WP_Error('invalid_folio_documents', __('Folio returned an invalid document response.', 'pc-folio-customer-balance'), ['status' => 502]);
    }
    return $data;
}

function pc_folio_documents_validate_partner(array $data, array $context) {
    $response_short_name = trim((string) ($data['partner']['shortName'] ?? ''));
    if ($response_short_name === '' || $response_short_name !== $context['short_name']) {
        return new WP_Error('folio_customer_mismatch', __('Folio returned documents for a different customer.', 'pc-folio-customer-balance'), ['status' => 502]);
    }
    return true;
}

function pc_folio_documents_warehouse_label($warehouse_id, string $warehouse_name = ''): string {
    $warehouse_name = trim($warehouse_name);
    if ($warehouse_name !== '') {
        return $warehouse_name;
    }
    $warehouse_id = trim((string) $warehouse_id);
    if ($warehouse_id === '') {
        return '';
    }
    if (function_exists('pc_folio_warehouse_label')) {
        return pc_folio_warehouse_label($warehouse_id);
    }
    $labels = [
        '1'  => __('Kyiv', 'pc-folio-customer-balance'),
        '2'  => __('Kyiv workshop', 'pc-folio-customer-balance'),
        '5'  => __('Odesa', 'pc-folio-customer-balance'),
        '6'  => __('Odesa workshop', 'pc-folio-customer-balance'),
        '7'  => __('Kyiv wholesale', 'pc-folio-customer-balance'),
        '8'  => __('Kyiv wholesale workshop', 'pc-folio-customer-balance'),
        '9'  => __('Transport', 'pc-folio-customer-balance'),
        '15' => __('Odesa 15', 'pc-folio-customer-balance'),
        '20' => __('Odesa 20', 'pc-folio-customer-balance'),
    ];
    return $labels[$warehouse_id] ?? sprintf(__('Warehouse %s', 'pc-folio-customer-balance'), $warehouse_id);
}

function pc_folio_documents_information(array $document): string {
    foreach (['additionalInfo', 'additionalInformation', 'information', 'documentInfo', 'infoText', 'info'] as $key) {
        $value = trim((string) ($document[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

function pc_folio_documents_prepare_document(array $document, bool $with_products = false): array {
    $document['warehouseLabel'] = pc_folio_documents_warehouse_label(
        $document['warehouseId'] ?? '',
        (string) ($document['warehouseName'] ?? ($document['folioWarehouseName'] ?? ''))
    );
    $document['information'] = pc_folio_documents_information($document);
    if (!$with_products) {
        return $document;
    }

    foreach (['items', 'repeatOrder'] as $container) {
        $items = $container === 'repeatOrder'
            ? ($document['repeatOrder']['items'] ?? null)
            : ($document['items'] ?? null);
        if (!is_array($items)) {
            continue;
        }
        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $item['warehouseLabel'] = pc_folio_documents_warehouse_label(
                $item['warehouseId'] ?? '',
                (string) ($item['warehouseName'] ?? ($item['folioWarehouseName'] ?? ''))
            );
            $item['woo'] = pc_folio_documents_product_context((string) ($item['sku'] ?? ''));
            if ($container === 'repeatOrder') {
                $document['repeatOrder']['items'][$index] = $item;
            } else {
                $document['items'][$index] = $item;
            }
        }
    }
    return $document;
}

function pc_folio_documents_product_context(string $sku): array {
    static $cache = [];
    $sku = trim($sku);
    if (array_key_exists($sku, $cache)) {
        return $cache[$sku];
    }

    $product_id = $sku !== '' && function_exists('wc_get_product_id_by_sku') ? (int) wc_get_product_id_by_sku($sku) : 0;
    $product = $product_id > 0 ? wc_get_product($product_id) : false;
    if (!$product instanceof WC_Product) {
        return $cache[$sku] = [
            'found' => false,
            'productId' => 0,
            'url' => '',
            'purchasable' => false,
            'inStock' => false,
            'stockTotal' => 0,
            'locations' => [],
        ];
    }

    $locations = [];
    if (function_exists('slu_collect_location_stocks_for_product')) {
        $stock_rows = (array) slu_collect_location_stocks_for_product($product);
        if (function_exists('slu_order_location_stocks_by_global_priority')) {
            $stock_rows = slu_order_location_stocks_by_global_priority($stock_rows);
        }
        foreach ($stock_rows as $term_id => $row) {
            $locations[] = [
                'id' => (int) $term_id,
                'name' => sanitize_text_field((string) ($row['name'] ?? '')),
                'quantity' => (float) ($row['qty'] ?? 0),
            ];
        }
    }

    $stock_total = $product->get_stock_quantity();
    if ($stock_total === null && $locations) {
        $stock_total = array_sum(array_column($locations, 'quantity'));
    }
    return $cache[$sku] = [
        'found' => true,
        'productId' => $product->get_id(),
        'url' => esc_url_raw($product->get_permalink()),
        'purchasable' => $product->is_purchasable(),
        'inStock' => $product->is_in_stock(),
        'stockTotal' => $stock_total === null ? null : (float) $stock_total,
        'locations' => $locations,
    ];
}

function pc_folio_documents_fetch_detail(array $context, string $type, int $document_id) {
    $path = sprintf('/admin/folio/customer-documents/%s/%d', rawurlencode($type), $document_id);
    $data = pc_folio_documents_java_get($path, ['partnerShortName' => $context['short_name']]);
    if (is_wp_error($data)) {
        return $data;
    }
    $partner_check = pc_folio_documents_validate_partner($data, $context);
    if (is_wp_error($partner_check)) {
        return $partner_check;
    }
    if (!is_array($data['document'] ?? null)) {
        return new WP_Error('invalid_document_detail', __('Folio returned invalid document details.', 'pc-folio-customer-balance'), ['status' => 502]);
    }
    $data['document'] = pc_folio_documents_prepare_document($data['document'], true);
    return $data;
}

function pc_folio_documents_send_error(WP_Error $error): void {
    $data = $error->get_error_data();
    wp_send_json_error([
        'message' => $error->get_error_message(),
        'reqId' => is_array($data) ? (string) ($data['reqId'] ?? '') : '',
        'skipped' => is_array($data) && is_array($data['skipped'] ?? null) ? $data['skipped'] : [],
    ], is_array($data) ? (int) ($data['status'] ?? 502) : 502);
}

function pc_folio_documents_ajax_list(): void {
    check_ajax_referer('pc_folio_customer_documents');
    $context = pc_folio_documents_request_context();
    if (is_wp_error($context)) {
        pc_folio_documents_send_error($context);
    }

    $date_from = isset($_POST['date_from']) ? sanitize_text_field(wp_unslash($_POST['date_from'])) : '';
    $date_to = isset($_POST['date_to']) ? sanitize_text_field(wp_unslash($_POST['date_to'])) : '';
    if (!pc_folio_balance_valid_date($date_from)
        || !pc_folio_balance_valid_date($date_to)
        || $date_from > $date_to
        || $date_to > current_time('Y-m-d')) {
        wp_send_json_error(['message' => __('Enter a valid document period.', 'pc-folio-customer-balance')], 400);
    }
    $from = new DateTimeImmutable($date_from);
    $to = new DateTimeImmutable($date_to);
    if ((int) $from->diff($to)->format('%a') > 365) {
        wp_send_json_error(['message' => __('The document period must not exceed 366 days.', 'pc-folio-customer-balance')], 400);
    }

    $raw_types = isset($_POST['types']) ? sanitize_text_field(wp_unslash($_POST['types'])) : '';
    $types = array_values(array_unique(array_filter(explode(',', $raw_types))));
    if (!$types || array_diff($types, ['ACCOUNT', 'EXPENSE', 'PAYMENT'])) {
        wp_send_json_error(['message' => __('Select at least one valid document type.', 'pc-folio-customer-balance')], 400);
    }
    $limit = isset($_POST['limit']) ? (int) $_POST['limit'] : 50;
    if (!in_array($limit, [25, 50, 100], true)) {
        wp_send_json_error(['message' => __('Select a valid number of rows per page.', 'pc-folio-customer-balance')], 400);
    }
    $cursor = isset($_POST['cursor']) ? trim((string) wp_unslash($_POST['cursor'])) : '';
    if (strlen($cursor) > 2048) {
        wp_send_json_error(['message' => __('The document page cursor is invalid.', 'pc-folio-customer-balance')], 400);
    }

    $query = [
        'partnerShortName' => $context['short_name'],
        'dateFrom' => $date_from,
        'dateTo' => $date_to,
        'types' => implode(',', $types),
        'limit' => $limit,
    ];
    if ($cursor !== '') {
        $query['cursor'] = $cursor;
    }
    $data = pc_folio_documents_java_get('/admin/folio/customer-documents', $query);
    if (is_wp_error($data)) {
        pc_folio_documents_send_error($data);
    }
    $partner_check = pc_folio_documents_validate_partner($data, $context);
    if (is_wp_error($partner_check)) {
        pc_folio_documents_send_error($partner_check);
    }
    if (!is_array($data['documents'] ?? null)) {
        pc_folio_documents_send_error(new WP_Error('invalid_document_list', __('Folio returned an invalid document list.', 'pc-folio-customer-balance'), ['status' => 502]));
    }
    $data['documents'] = array_map(static function ($document): array {
        return pc_folio_documents_prepare_document(is_array($document) ? $document : []);
    }, $data['documents']);
    wp_send_json_success(['result' => $data]);
}
add_action('wp_ajax_pc_folio_customer_documents', 'pc_folio_documents_ajax_list');

function pc_folio_documents_ajax_detail(): void {
    check_ajax_referer('pc_folio_customer_documents');
    $context = pc_folio_documents_request_context();
    if (is_wp_error($context)) {
        pc_folio_documents_send_error($context);
    }
    $type = isset($_POST['document_type']) ? sanitize_key(wp_unslash($_POST['document_type'])) : '';
    $type = strtoupper($type);
    $document_id = isset($_POST['document_id']) ? (int) $_POST['document_id'] : 0;
    if (!in_array($type, ['ACCOUNT', 'EXPENSE', 'PAYMENT'], true) || $document_id <= 0) {
        wp_send_json_error(['message' => __('Select a valid Folio document.', 'pc-folio-customer-balance')], 400);
    }
    $data = pc_folio_documents_fetch_detail($context, $type, $document_id);
    if (is_wp_error($data)) {
        pc_folio_documents_send_error($data);
    }
    wp_send_json_success(['result' => $data]);
}
add_action('wp_ajax_pc_folio_customer_document_detail', 'pc_folio_documents_ajax_detail');

function pc_folio_documents_selected_indexes(): array {
    $raw = isset($_POST['selected_indexes']) ? json_decode((string) wp_unslash($_POST['selected_indexes']), true) : null;
    if (!is_array($raw)) {
        return [];
    }
    $indexes = [];
    foreach ($raw as $index) {
        if (is_numeric($index) && (int) $index >= 0) {
            $indexes[] = (int) $index;
        }
    }
    return array_values(array_unique($indexes));
}

function pc_folio_documents_repeat_candidates(array $document, array $selected_indexes, string $target): array {
    $repeat_order = is_array($document['repeatOrder'] ?? null) ? $document['repeatOrder'] : [];
    if (($repeat_order['allowed'] ?? false) !== true || !is_array($repeat_order['items'] ?? null)) {
        return [[], [[
            'sku' => '',
            'message' => __('This document cannot be repeated as an order.', 'pc-folio-customer-balance'),
        ]]];
    }

    $selected = array_fill_keys($selected_indexes, true);
    $candidates = [];
    $skipped = [];
    foreach ($repeat_order['items'] as $index => $item) {
        if (!isset($selected[(int) $index]) || !is_array($item)) {
            continue;
        }
        $sku = trim((string) ($item['sku'] ?? ''));
        $quantity = function_exists('wc_stock_amount')
            ? wc_stock_amount((float) ($item['quantity'] ?? 0))
            : (float) ($item['quantity'] ?? 0);
        $product_id = $sku !== '' ? (int) wc_get_product_id_by_sku($sku) : 0;
        $product = $product_id > 0 ? wc_get_product($product_id) : false;
        if (!$product instanceof WC_Product) {
            $skipped[] = ['sku' => $sku, 'message' => __('The product was not found on the site.', 'pc-folio-customer-balance')];
            continue;
        }
        if ($quantity <= 0) {
            $skipped[] = ['sku' => $sku, 'message' => __('The product is not available for ordering.', 'pc-folio-customer-balance')];
            continue;
        }
        if ($target === 'cart') {
            if (!$product->is_purchasable()) {
                $skipped[] = ['sku' => $sku, 'message' => __('The product is not available for ordering.', 'pc-folio-customer-balance')];
                continue;
            }
            $stock_context = pc_folio_documents_product_context($sku);
            $cart_quantity = function_exists('slu_cart_qty_for_product') ? (float) slu_cart_qty_for_product($product) : 0.0;
            $stock_total = $stock_context['stockTotal'] ?? null;
            $exceeds_location_stock = $stock_total !== null && ($quantity + $cart_quantity) > (float) $stock_total;
            if (!$product->is_in_stock()
                || $exceeds_location_stock
                || (method_exists($product, 'has_enough_stock') && !$product->has_enough_stock($quantity + $cart_quantity))) {
                $skipped[] = ['sku' => $sku, 'message' => __('There is not enough current stock for the requested quantity.', 'pc-folio-customer-balance')];
                continue;
            }
            $maximum = (float) $product->get_max_purchase_quantity();
            if ($maximum > 0 && $quantity > $maximum) {
                $skipped[] = ['sku' => $sku, 'message' => __('The requested quantity exceeds the current purchase limit.', 'pc-folio-customer-balance')];
                continue;
            }
        }
        $candidates[] = ['sku' => $sku, 'quantity' => $quantity, 'product' => $product];
    }
    return [$candidates, $skipped];
}

function pc_folio_documents_load_cart(): bool {
    if (!function_exists('WC')) {
        return false;
    }
    if ((!WC()->session || !WC()->cart) && function_exists('wc_load_cart')) {
        wc_load_cart();
    }
    if (!WC()->session || !WC()->cart) {
        return false;
    }
    WC()->session->set_customer_session_cookie(true);
    WC()->cart->get_cart();
    return true;
}

function pc_folio_documents_add_candidates_to_cart(array $candidates, array $skipped): array {
    if (!pc_folio_documents_load_cart()) {
        return [0, $skipped, new WP_Error('cart_unavailable', __('The cart is temporarily unavailable.', 'pc-folio-customer-balance'), ['status' => 503])];
    }
    $added = 0;
    foreach ($candidates as $candidate) {
        /** @var WC_Product $product */
        $product = $candidate['product'];
        $quantity = $candidate['quantity'];
        $product_id = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();
        $variation_id = $product->is_type('variation') ? $product->get_id() : 0;
        $variation = $product->is_type('variation') ? $product->get_variation_attributes() : [];
        $result = WC()->cart->add_to_cart($product_id, $quantity, $variation_id, $variation);
        if ($result) {
            $added++;
        } else {
            $skipped[] = ['sku' => $candidate['sku'], 'message' => __('WooCommerce rejected this item.', 'pc-folio-customer-balance')];
        }
    }
    return [$added, $skipped, null];
}

function pc_folio_documents_create_repeat_draft(array $context, array $document, array $candidates, array $skipped) {
    if (!function_exists('wc_create_order') || !isset(wc_get_order_statuses()['wc-pc-draft'])) {
        return new WP_Error('draft_unavailable', __('Draft orders are temporarily unavailable.', 'pc-folio-customer-balance'), ['status' => 503]);
    }
    if (!$candidates) {
        return new WP_Error('empty_draft', __('No selected items are currently available for a draft order.', 'pc-folio-customer-balance'), ['status' => 422, 'skipped' => $skipped]);
    }

    $order = wc_create_order(['status' => 'wc-pc-draft', 'customer_id' => (int) $context['user_id']]);
    if (!$order instanceof WC_Order) {
        return new WP_Error('draft_create_failed', __('The draft order could not be created.', 'pc-folio-customer-balance'), ['status' => 500]);
    }
    $customer = new WC_Customer((int) $context['user_id']);
    $order->set_address($customer->get_billing('edit'), 'billing');
    $order->set_address($customer->get_shipping('edit'), 'shipping');
    $order->set_created_via('folio-repeat');
    $added = 0;
    foreach ($candidates as $candidate) {
        /** @var WC_Product $product */
        $product = $candidate['product'];
        $item_id = $order->add_product($product, $candidate['quantity']);
        if (!$item_id) {
            $skipped[] = ['sku' => $candidate['sku'], 'message' => __('WooCommerce rejected this item.', 'pc-folio-customer-balance')];
            continue;
        }
        $added++;
        $item = $order->get_item($item_id);
        if ($item instanceof WC_Order_Item_Product && function_exists('PaintCore\\Stock\\pc_compute_and_stamp_item_plan')) {
            try {
                \PaintCore\Stock\pc_compute_and_stamp_item_plan($item, $product, (int) $candidate['quantity'], 'folio-repeat-draft');
                $item->save();
            } catch (Throwable $error) {
                // The draft itself is still valid; allocation will be recalculated later if necessary.
            }
        }
    }
    if ($added === 0) {
        $order->delete(true);
        return new WP_Error('empty_draft', __('No selected items are currently available for a draft order.', 'pc-folio-customer-balance'), ['status' => 422, 'skipped' => $skipped]);
    }
    $number = trim((string) ($document['documentNumber'] ?? '') . (string) ($document['documentNumberSuffix'] ?? ''));
    $order->update_meta_data('_pc_draft_title', sprintf(__('Repeat of Folio document %s', 'pc-folio-customer-balance'), $number));
    $order->update_meta_data('_folio_repeat_source_type', sanitize_key((string) ($document['documentType'] ?? '')));
    $order->update_meta_data('_folio_repeat_source_document_id', (int) ($document['documentId'] ?? 0));
    $order->update_meta_data('_folio_repeat_source_document_number', $number);
    $order->add_order_note(sprintf(__('Created from Folio document %s using current WooCommerce prices.', 'pc-folio-customer-balance'), $number), false, true);
    $order->calculate_totals(false);
    $order->save();
    return ['order' => $order, 'added' => $added, 'skipped' => $skipped];
}

function pc_folio_documents_ajax_repeat_action(): void {
    check_ajax_referer('pc_folio_customer_documents');
    $context = pc_folio_documents_request_context();
    if (is_wp_error($context)) {
        pc_folio_documents_send_error($context);
    }
    $type = strtoupper(isset($_POST['document_type']) ? sanitize_key(wp_unslash($_POST['document_type'])) : '');
    $document_id = isset($_POST['document_id']) ? (int) $_POST['document_id'] : 0;
    $target = isset($_POST['target']) ? sanitize_key(wp_unslash($_POST['target'])) : '';
    $selected_indexes = pc_folio_documents_selected_indexes();
    if (!in_array($type, ['ACCOUNT', 'EXPENSE'], true) || $document_id <= 0 || !in_array($target, ['cart', 'draft'], true) || !$selected_indexes) {
        wp_send_json_error(['message' => __('Select valid document items.', 'pc-folio-customer-balance')], 400);
    }

    $data = pc_folio_documents_fetch_detail($context, $type, $document_id);
    if (is_wp_error($data)) {
        pc_folio_documents_send_error($data);
    }
    [$candidates, $skipped] = pc_folio_documents_repeat_candidates($data['document'], $selected_indexes, $target);
    if ($target === 'cart') {
        [$added, $skipped, $error] = pc_folio_documents_add_candidates_to_cart($candidates, $skipped);
        if ($error instanceof WP_Error) {
            pc_folio_documents_send_error($error);
        }
        if ($added === 0) {
            wp_send_json_error(['message' => __('No selected items were added to the cart.', 'pc-folio-customer-balance'), 'skipped' => $skipped], 422);
        }
        wp_send_json_success(['result' => ['target' => 'cart', 'added' => $added, 'skipped' => $skipped, 'url' => wc_get_cart_url()]]);
    }

    $draft = pc_folio_documents_create_repeat_draft($context, $data['document'], $candidates, $skipped);
    if (is_wp_error($draft)) {
        pc_folio_documents_send_error($draft);
    }
    /** @var WC_Order $order */
    $order = $draft['order'];
    $url = wc_get_endpoint_url('view-order', $order->get_id(), wc_get_page_permalink('myaccount'));
    wp_send_json_success(['result' => [
        'target' => 'draft',
        'added' => (int) $draft['added'],
        'skipped' => $draft['skipped'],
        'orderId' => $order->get_id(),
        'url' => $url,
    ]]);
}
add_action('wp_ajax_pc_folio_customer_document_repeat', 'pc_folio_documents_ajax_repeat_action');
