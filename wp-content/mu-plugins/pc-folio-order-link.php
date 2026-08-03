<?php
/**
 * Plugin Name: PC Folio Order Link
 * Description: Stores and displays the Folio document link for WooCommerce orders.
 * Author: PaintCore
 * Version: 1.0.0
 * Text Domain: pc-folio-order-link
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('pc_folio_order_link_meta_keys')) {
    /**
     * Meta keys used to connect a WooCommerce order with a Folio document.
     */
    function pc_folio_order_link_meta_keys(): array
    {
        return [
            'document_id'           => '_folio_document_id',
            'document_number'       => '_folio_document_number',
            'document_type'         => '_folio_document_type',
            'document_status'       => '_folio_document_status',
            'document_created_at'   => '_folio_document_created_at',
            'document_payload_hash' => '_folio_document_payload_hash',
            'document_last_error'   => '_folio_document_last_error',
        ];
    }
}

if (!function_exists('pc_folio_order_link_can_manage')) {
    /**
     * Check whether the current admin user can preview Folio order actions.
     */
    function pc_folio_order_link_can_manage(): bool
    {
        return current_user_can('manage_woocommerce') || current_user_can('edit_shop_orders');
    }
}

if (!function_exists('pc_folio_order_link_java_post')) {
    /**
     * POST a JSON payload to the Java service using Lavka Total Sync settings.
     */
    function pc_folio_order_link_java_post(string $path, array $payload, array $args = [])
    {
        $options_key = defined('LTS_OPT') ? LTS_OPT : 'lts_options';
        $options = get_option($options_key, []);
        $options = is_array($options) ? $options : [];
        $base = rtrim((string) ($options['java_base_url'] ?? ($options['base_url'] ?? '')), '/');

        if ($base === '') {
            return new WP_Error('java_base_url_missing', __('Java Base URL is not configured.', 'pc-folio-order-link'));
        }

        $url = $base . '/' . ltrim($path, '/');
        $args = wp_parse_args($args, [
            'timeout' => max(30, (int) ($options['timeout'] ?? 120)),
            'headers' => [
                'X-Auth-Token' => (string) ($options['api_token'] ?? ''),
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return wp_remote_post($url, $args);
    }
}

if (!function_exists('pc_folio_order_documents_meta_keys')) {
    /**
     * Meta keys used for one Woo order mapped to multiple Folio documents.
     */
    function pc_folio_order_documents_meta_keys(): array
    {
        return [
            'documents_result' => '_folio_documents_result',
            'child_order_ids'  => '_folio_child_order_ids',
            'parent_order_id'  => '_folio_parent_order_id',
            'split_status'     => '_folio_split_status',
            'split_created_at' => '_folio_split_created_at',
        ];
    }
}

if (!function_exists('pc_folio_clean_meta_value')) {
    /**
     * Clean nested Folio response data before storing it in order meta.
     */
    function pc_folio_clean_meta_value($value)
    {
        if (is_array($value)) {
            $clean = [];
            foreach ($value as $key => $item) {
                $clean_key = is_int($key) ? $key : (string) $key;
                if ($clean_key === '' && !is_int($key)) {
                    continue;
                }
                $clean[$clean_key] = pc_folio_clean_meta_value($item);
            }
            return $clean;
        }

        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }

        return pc_folio_preview_text(sanitize_text_field((string) $value));
    }
}

if (!function_exists('pc_folio_get_order_documents_result')) {
    /**
     * Read the full Java/Folio split response stored on a Woo order.
     *
     * @param int|\WC_Order $order_or_id Order object or order ID.
     */
    function pc_folio_get_order_documents_result($order_or_id): array
    {
        $order = ($order_or_id instanceof \WC_Order) ? $order_or_id : wc_get_order($order_or_id);
        if (!$order) {
            return [];
        }

        $keys = pc_folio_order_documents_meta_keys();
        $value = $order->get_meta($keys['documents_result'], true);

        return is_array($value) ? $value : [];
    }
}

if (!function_exists('pc_folio_guess_split_status')) {
    /**
     * Derive a small status label from the Java/Folio response.
     */
    function pc_folio_guess_split_status(array $result): string
    {
        if (isset($result['ok']) && !$result['ok']) {
            return 'error';
        }

        if (!empty($result['errors'])) {
            return 'error';
        }

        $documents = isset($result['documents']) && is_array($result['documents'])
            ? $result['documents']
            : [];

        foreach ($documents as $document) {
            if (is_array($document) && (($document['document_type'] ?? '') === 'missing_stock_account')) {
                return 'partial';
            }
        }

        return $documents ? 'ready' : 'empty';
    }
}

if (!function_exists('pc_folio_is_missing_document')) {
    /**
     * Detect Java's non-accounting missing-stock document.
     */
    function pc_folio_is_missing_document(array $document): bool
    {
        return ($document['document_type'] ?? '') === 'missing_stock_account'
            || ($document['documentType'] ?? '') === 'missing_stock_account'
            || (array_key_exists('accounting_enabled', $document) && !$document['accounting_enabled'])
            || (array_key_exists('accountingEnabled', $document) && !$document['accountingEnabled']);
    }
}

if (!function_exists('pc_folio_document_created_at_text')) {
    /**
     * Convert Java document_created_at variants to a compact string for order meta.
     */
    function pc_folio_document_created_at_text($value): string
    {
        if (is_array($value)) {
            return implode('-', array_map('strval', $value));
        }

        return pc_folio_preview_text($value);
    }
}

if (!function_exists('pc_folio_get_single_real_document_link')) {
    /**
     * Return legacy single-document link fields when Java created exactly one real account.
     */
    function pc_folio_get_single_real_document_link(array $response, string $payload_hash = ''): array
    {
        $documents = isset($response['documents']) && is_array($response['documents'])
            ? $response['documents']
            : [];
        $real_documents = [];

        foreach ($documents as $document) {
            if (!is_array($document) || pc_folio_is_missing_document($document)) {
                continue;
            }
            $real_documents[] = $document;
        }

        if (count($documents) !== 1 || count($real_documents) !== 1) {
            return [];
        }

        $document = $real_documents[0];

        return [
            'document_id'           => (string) ($document['document_id'] ?? ($document['documentId'] ?? '')),
            'document_number'       => (string) ($document['document_number'] ?? ($document['documentNumber'] ?? '')),
            'document_type'         => (string) ($document['document_type'] ?? ($document['documentType'] ?? '')),
            'document_status'       => (string) ($document['document_status'] ?? ($document['documentStatus'] ?? '')),
            'document_created_at'   => pc_folio_document_created_at_text($document['document_created_at'] ?? ($document['documentCreatedAt'] ?? '')),
            'document_payload_hash' => $payload_hash,
            'document_last_error'   => '',
        ];
    }
}

if (!function_exists('pc_folio_get_single_document_link')) {
    /**
     * Return legacy link fields for any single Folio document, including missing-stock.
     */
    function pc_folio_get_single_document_link(array $document, string $payload_hash = ''): array
    {
        return [
            'document_id'           => (string) ($document['document_id'] ?? ($document['documentId'] ?? '')),
            'document_number'       => (string) ($document['document_number'] ?? ($document['documentNumber'] ?? '')),
            'document_type'         => (string) ($document['document_type'] ?? ($document['documentType'] ?? '')),
            'document_status'       => (string) ($document['document_status'] ?? ($document['documentStatus'] ?? '')),
            'document_created_at'   => pc_folio_document_created_at_text($document['document_created_at'] ?? ($document['documentCreatedAt'] ?? '')),
            'document_payload_hash' => $payload_hash,
            'document_last_error'   => '',
        ];
    }
}

if (!function_exists('pc_folio_order_has_saved_documents')) {
    /**
     * Check whether an order already has a Folio document result saved.
     */
    function pc_folio_order_has_saved_documents(\WC_Order $order): bool
    {
        $result = pc_folio_get_order_documents_result($order);
        $link = pc_folio_get_order_document_link($order);

        return !empty($result['documents'])
            || !empty($link['document_id'])
            || !empty($link['document_number']);
    }
}

if (!function_exists('pc_folio_save_order_java_response')) {
    /**
     * Save Java's real create response to Woo order meta without creating child orders.
     */
    function pc_folio_save_order_java_response(\WC_Order $order, array $response, array $payload): void
    {
        $payload_hash = hash('sha256', (string) wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        pc_folio_set_order_documents_result($order, $response);

        $single_link = pc_folio_get_single_real_document_link($response, $payload_hash);
        if ($single_link) {
            pc_folio_set_order_document_link($order, $single_link);
        }

        $order->add_order_note(__('Folio account response was saved on the Woo order.', 'pc-folio-order-link'));
    }
}

if (!function_exists('pc_folio_auto_checkout_enabled')) {
    /**
     * Allow production to disable automatic Folio creation with a constant or filter.
     */
    function pc_folio_auto_checkout_enabled(): bool
    {
        $enabled = !defined('PC_FOLIO_AUTO_CHECKOUT') || (bool) PC_FOLIO_AUTO_CHECKOUT;

        return (bool) apply_filters('pc_folio_auto_checkout_enabled', $enabled);
    }
}

if (!function_exists('pc_folio_clear_current_checkout_cart')) {
    /**
     * Clear the customer's active cart after Folio has accepted the checkout.
     */
    function pc_folio_clear_current_checkout_cart(): void
    {
        if (is_admin() && !wp_doing_ajax()) {
            return;
        }

        if (!function_exists('WC')) {
            return;
        }

        $woocommerce = WC();
        if (!is_object($woocommerce) || empty($woocommerce->cart) || !method_exists($woocommerce->cart, 'empty_cart')) {
            return;
        }

        if (method_exists($woocommerce->cart, 'is_empty') && $woocommerce->cart->is_empty()) {
            return;
        }

        $woocommerce->cart->empty_cart(true);
    }
}

if (!function_exists('pc_folio_order_should_go_to_account_orders')) {
    /**
     * Route completed Folio checkout flows to the account order list.
     */
    function pc_folio_order_should_go_to_account_orders(\WC_Order $order): bool
    {
        if (!pc_folio_auto_checkout_enabled()) {
            return false;
        }

        if (!is_user_logged_in()) {
            return false;
        }

        $current_user_id = get_current_user_id();
        if ((int) $order->get_user_id() !== $current_user_id && !current_user_can('manage_woocommerce')) {
            return false;
        }

        if ((string) $order->get_meta('_folio_auto_status', true) === 'success') {
            return true;
        }

        $keys = pc_folio_order_documents_meta_keys();
        $split_status = (string) $order->get_meta($keys['split_status'], true);
        if (in_array($split_status, ['ready', 'ready_to_split', 'split_created'], true)) {
            return true;
        }

        $child_order_ids = $order->get_meta($keys['child_order_ids'], true);
        if (is_array($child_order_ids) && array_filter(array_map('absint', $child_order_ids))) {
            return true;
        }

        return false;
    }
}

if (!function_exists('pc_folio_validate_order_payload_for_create')) {
    /**
     * Validate only the fields that would make Java reject an automatic checkout run.
     */
    function pc_folio_validate_order_payload_for_create(array $payload): array
    {
        $errors = [];
        $folio_client = isset($payload['folio_client']) && is_array($payload['folio_client'])
            ? $payload['folio_client']
            : [];
        if (trim((string) ($folio_client['id'] ?? ($folio_client['short_name'] ?? ''))) === '') {
            $errors[] = __('Folio client is not mapped for this Woo customer.', 'pc-folio-order-link');
        }

        $items = isset($payload['items']) && is_array($payload['items']) ? $payload['items'] : [];
        if (!$items) {
            $errors[] = __('Order has no line items for Folio.', 'pc-folio-order-link');
        }

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            $sku = trim((string) ($item['sku'] ?? ''));
            $label = $sku !== '' ? $sku : sprintf(__('item #%d', 'pc-folio-order-link'), $index + 1);
            $allocations = isset($item['allocations']) && is_array($item['allocations']) ? $item['allocations'] : [];
            if (!$allocations) {
                $errors[] = sprintf(__('No stock allocation found for %s.', 'pc-folio-order-link'), $label);
                continue;
            }

            foreach ($allocations as $allocation) {
                if (!is_array($allocation)) {
                    continue;
                }

                $warehouses = isset($allocation['folio_warehouses']) && is_array($allocation['folio_warehouses'])
                    ? $allocation['folio_warehouses']
                    : [];
                if (!$warehouses) {
                    $errors[] = sprintf(__('No Folio warehouse mapping found for %s.', 'pc-folio-order-link'), $label);
                    break;
                }
            }
        }

        return array_values(array_unique($errors));
    }
}

if (!function_exists('pc_folio_create_documents_for_order')) {
    /**
     * Create real Folio documents through Java and save the response on the Woo order.
     */
    function pc_folio_create_documents_for_order(\WC_Order $order, string $source = 'manual'): array
    {
        if (pc_folio_order_has_saved_documents($order)) {
            return [
                'ok'      => false,
                'message' => __('Folio documents are already saved for this order.', 'pc-folio-order-link'),
            ];
        }

        $payload = pc_folio_build_order_preview_payload($order);
        if (!$payload) {
            return [
                'ok'      => false,
                'message' => __('Could not build Folio order payload.', 'pc-folio-order-link'),
            ];
        }

        $payload['preview_only'] = false;
        $errors = pc_folio_validate_order_payload_for_create($payload);
        if ($errors) {
            return [
                'ok'      => false,
                'message' => implode(' ', $errors),
                'payload' => $payload,
            ];
        }

        $resp = pc_folio_order_link_java_post('/admin/folio/order-accounts', $payload, [
            'timeout' => 180,
        ]);
        if (is_wp_error($resp)) {
            return [
                'ok'      => false,
                'message' => $resp->get_error_message(),
                'payload' => $payload,
            ];
        }

        $code = (int) wp_remote_retrieve_response_code($resp);
        $raw = (string) wp_remote_retrieve_body($resp);
        $data = json_decode($raw, true);

        if ($code < 200 || $code >= 300) {
            $details = '';
            if (is_array($data)) {
                $details = (string) ($data['details'] ?? ($data['message'] ?? ($data['title'] ?? ($data['error'] ?? ''))));
            }
            $message = sprintf('Java create HTTP %d', $code);
            if ($details !== '') {
                $message .= ': ' . pc_folio_header_short_text($details, 220);
            }

            return [
                'ok'      => false,
                'message' => $message,
                'raw'     => $raw,
                'payload' => $payload,
            ];
        }

        if (!is_array($data)) {
            return [
                'ok'      => false,
                'message' => __('Invalid Java create response.', 'pc-folio-order-link'),
                'raw'     => $raw,
                'payload' => $payload,
            ];
        }

        if (empty($data['ok'])) {
            pc_folio_set_order_documents_result($order, $data);
            return [
                'ok'       => false,
                'message'  => __('Java response is not OK.', 'pc-folio-order-link'),
                'response' => $data,
                'raw'      => $raw,
                'payload'  => $payload,
            ];
        }

        pc_folio_save_order_java_response($order, $data, $payload);
        $order->add_order_note(sprintf(__('Folio account response was created automatically from %s.', 'pc-folio-order-link'), $source));

        return [
            'ok'       => true,
            'response' => $data,
            'raw'      => $raw,
            'payload'  => $payload,
        ];
    }
}

if (!function_exists('pc_folio_document_label')) {
    /**
     * Build a short human-readable Folio document label for order notes.
     */
    function pc_folio_document_label(array $document): string
    {
        $number = (string) ($document['document_number'] ?? ($document['documentNumber'] ?? ($document['document_id'] ?? ($document['documentId'] ?? ''))));
        $warehouse = (string) ($document['folio_warehouse_id'] ?? ($document['warehouseId'] ?? ''));
        $parts = [];

        if ($number !== '') {
            $parts[] = '#' . $number;
        }

        if ($warehouse !== '') {
            $parts[] = sprintf(__('warehouse %s', 'pc-folio-order-link'), $warehouse);
        }

        return $parts ? implode(', ', $parts) : __('without number', 'pc-folio-order-link');
    }
}

if (!function_exists('pc_folio_get_document_warehouse_id')) {
    /**
     * Read the Folio warehouse ID from a document response.
     */
    function pc_folio_get_document_warehouse_id(array $document): string
    {
        return (string) ($document['folio_warehouse_id'] ?? ($document['warehouseId'] ?? ''));
    }
}

if (!function_exists('pc_folio_set_order_customer_notice')) {
    /**
     * Store a future customer-facing Folio notice and mirror it as a private admin note.
     */
    function pc_folio_set_order_customer_notice(\WC_Order $order, string $notice): void
    {
        $notice = trim($notice);
        if ($notice === '') {
            return;
        }

        $order->update_meta_data('_folio_customer_notice', $notice);
        $order->add_order_note($notice, false);
    }
}

if (!function_exists('pc_folio_build_child_order_notice')) {
    /**
     * Build the future customer-facing notice for a Folio child order.
     */
    function pc_folio_build_child_order_notice(array $document): string
    {
        if (pc_folio_is_missing_document($document)) {
            return __('Product is currently unavailable. This part of the order was moved to waiting/preorder mode. A manager will contact the customer to confirm details.', 'pc-folio-order-link');
        }

        $warehouse_id = pc_folio_get_document_warehouse_id($document);
        if ($warehouse_id === '') {
            return __('Shipment will be prepared from the assigned Folio warehouse.', 'pc-folio-order-link');
        }

        return sprintf(__('Shipment will be prepared from Folio warehouse %s.', 'pc-folio-order-link'), $warehouse_id);
    }
}

if (!function_exists('pc_folio_build_parent_split_notice')) {
    /**
     * Build the future customer-facing notice for a split parent order.
     */
    function pc_folio_build_parent_split_notice(): string
    {
        return __('Order was split into multiple Folio accounts. Each account corresponds to a separate warehouse.', 'pc-folio-order-link');
    }
}

if (!function_exists('pc_folio_get_order_warehouse_id_for_customer')) {
    /**
     * Find the Folio warehouse ID for customer-facing order notices.
     */
    function pc_folio_get_order_warehouse_id_for_customer(\WC_Order $order): string
    {
        foreach ($order->get_items('line_item') as $item) {
            $warehouse_id = (string) $item->get_meta('_folio_warehouse_id', true);
            if ($warehouse_id !== '') {
                return $warehouse_id;
            }
        }

        $result = pc_folio_get_order_documents_result($order);
        $documents = isset($result['documents']) && is_array($result['documents']) ? $result['documents'] : [];
        foreach ($documents as $document) {
            if (!is_array($document)) {
                continue;
            }

            $warehouse_id = pc_folio_get_document_warehouse_id($document);
            if ($warehouse_id !== '') {
                return $warehouse_id;
            }
        }

        return '';
    }
}

if (!function_exists('pc_folio_get_order_customer_message')) {
    /**
     * Build a compact customer-facing status message for Folio order processing.
     */
    function pc_folio_get_order_customer_message(\WC_Order $order): array
    {
        $auto_status = (string) $order->get_meta('_folio_auto_status', true);
        if ($auto_status === 'error') {
            return [
                'type'    => 'error',
                'title'   => __('Order was saved, but Folio processing needs a manager check.', 'pc-folio-order-link'),
                'message' => __('Automatic Folio processing did not finish. A manager will check the order.', 'pc-folio-order-link'),
            ];
        }

        $notice = trim((string) $order->get_meta('_folio_customer_notice', true));
        $keys = pc_folio_order_documents_meta_keys();
        $child_order_ids = $order->get_meta($keys['child_order_ids'], true);
        $child_order_ids = is_array($child_order_ids) ? array_values(array_filter(array_map('absint', $child_order_ids))) : [];
        if ($child_order_ids) {
            return [
                'type'            => 'success',
                'title'           => __('Order was split into Folio warehouse accounts.', 'pc-folio-order-link'),
                'message'         => $notice !== '' ? $notice : pc_folio_build_parent_split_notice(),
                'child_order_ids' => $child_order_ids,
            ];
        }

        if ($notice !== '') {
            return [
                'type'    => pc_folio_is_missing_document(['document_type' => (string) $order->get_meta('_folio_document_type', true)]) ? 'notice' : 'success',
                'title'   => __('Folio order status', 'pc-folio-order-link'),
                'message' => $notice,
            ];
        }

        $link = pc_folio_get_order_document_link($order);
        if (($link['document_number'] ?? '') !== '') {
            return [
                'type'    => 'success',
                'title'   => __('Order was sent to Folio.', 'pc-folio-order-link'),
                'message' => sprintf(__('Folio account #%s was created for this order.', 'pc-folio-order-link'), $link['document_number']),
            ];
        }

        return [];
    }
}

if (!function_exists('pc_folio_render_customer_order_message')) {
    /**
     * Show Folio status on thank-you and my-account order detail pages.
     */
    function pc_folio_render_customer_order_message($order): void
    {
        if (!$order instanceof \WC_Order) {
            $order = wc_get_order($order);
        }
        if (!$order instanceof \WC_Order) {
            return;
        }

        static $rendered = [];
        $order_id = (int) $order->get_id();
        if (isset($rendered[$order_id])) {
            return;
        }
        $rendered[$order_id] = true;

        $message = pc_folio_get_order_customer_message($order);
        if (!$message) {
            return;
        }

        $classes = 'pc-folio-customer-notice pc-folio-customer-notice--' . sanitize_html_class($message['type'] ?? 'notice');
        echo '<section class="' . esc_attr($classes) . '">';
        echo '<h2>' . esc_html($message['title']) . '</h2>';
        echo '<p>' . esc_html($message['message']) . '</p>';

        $child_order_ids = isset($message['child_order_ids']) && is_array($message['child_order_ids'])
            ? array_values(array_filter(array_map('absint', $message['child_order_ids'])))
            : [];
        if (!$child_order_ids) {
            $warehouse_id = pc_folio_get_order_warehouse_id_for_customer($order);
            if ($warehouse_id !== '') {
                echo '<p class="pc-folio-customer-notice__warehouse">' . esc_html(sprintf(__('Folio warehouse: %s', 'pc-folio-order-link'), $warehouse_id)) . '</p>';
            }
        }

        if ($child_order_ids) {
            echo '<ul class="pc-folio-customer-notice__orders">';
            foreach ($child_order_ids as $child_order_id) {
                $child_order = wc_get_order($child_order_id);
                if (!$child_order instanceof \WC_Order) {
                    continue;
                }

                $child_label = sprintf(
                    __('Order #%1$s, %2$s', 'pc-folio-order-link'),
                    $child_order->get_order_number(),
                    wc_get_order_status_name($child_order->get_status())
                );
                $child_warehouse_id = pc_folio_get_order_warehouse_id_for_customer($child_order);
                if ($child_warehouse_id !== '') {
                    $child_label .= ' · ' . sprintf(__('warehouse %s', 'pc-folio-order-link'), $child_warehouse_id);
                }
                echo '<li><a href="' . esc_url($child_order->get_view_order_url()) . '">' . esc_html($child_label) . '</a></li>';
            }
            echo '</ul>';
        }

        echo '</section>';
    }
}

if (!function_exists('pc_folio_add_my_account_orders_column')) {
    /**
     * Add a compact Folio/warehouse column to the customer orders table.
     */
    function pc_folio_add_my_account_orders_column(array $columns): array
    {
        $new_columns = [];
        foreach ($columns as $key => $label) {
            $new_columns[$key] = $label;
            if ($key === 'order-status') {
                $new_columns['pc-folio'] = __('Folio', 'pc-folio-order-link');
            }
        }

        if (!isset($new_columns['pc-folio'])) {
            $new_columns['pc-folio'] = __('Folio', 'pc-folio-order-link');
        }

        return $new_columns;
    }
}

if (!function_exists('pc_folio_render_my_account_orders_column')) {
    /**
     * Render the compact Folio/warehouse status in the customer orders list.
     */
    function pc_folio_render_my_account_orders_column(\WC_Order $order): void
    {
        $message = pc_folio_get_order_customer_message($order);
        $warehouse_id = pc_folio_get_order_warehouse_id_for_customer($order);
        $link = pc_folio_get_order_document_link($order);
        $parts = [];

        if (($link['document_number'] ?? '') !== '') {
            $parts[] = '#' . $link['document_number'];
        }
        if ($warehouse_id !== '') {
            $parts[] = sprintf(__('warehouse %s', 'pc-folio-order-link'), $warehouse_id);
        }
        if (!$parts && !empty($message['child_order_ids'])) {
            $parts[] = sprintf(__('%d Folio orders', 'pc-folio-order-link'), count($message['child_order_ids']));
        }
        if (!$parts && (($message['type'] ?? '') === 'error')) {
            $parts[] = __('manager check', 'pc-folio-order-link');
        }

        echo $parts ? esc_html(implode(' · ', $parts)) : '&mdash;';
    }
}

if (!function_exists('pc_folio_render_checkout_processing_message')) {
    /**
     * Show a clear waiting message after the customer submits checkout.
     */
    function pc_folio_render_checkout_processing_message(): void
    {
        if (!function_exists('is_checkout') || !is_checkout() || (function_exists('is_order_received_page') && is_order_received_page())) {
            return;
        }
        ?>
        <script>
        (function(){
            var shown = false;
            function showProcessingMessage() {
                if (shown) {
                    return;
                }
                shown = true;
                var target = document.querySelector('form.checkout, .wc-block-checkout, .wp-block-woocommerce-checkout');
                if (!target) {
                    return;
                }
                var box = document.createElement('div');
                box.className = 'pc-folio-checkout-processing';
                box.textContent = '<?php echo esc_js(__('The order is being processed. Please wait: we are creating Folio account documents and checking warehouses.', 'pc-folio-order-link')); ?>';
                target.parentNode.insertBefore(box, target);
            }

            document.addEventListener('submit', function(event) {
                if (event.target && event.target.matches && event.target.matches('form.checkout')) {
                    showProcessingMessage();
                }
            }, true);

            document.addEventListener('click', function(event) {
                var button = event.target && event.target.closest
                    ? event.target.closest('button[name="woocommerce_checkout_place_order"], .wc-block-components-checkout-place-order-button')
                    : null;
                if (button) {
                    showProcessingMessage();
                }
            }, true);
        }());
        </script>
        <?php
    }
}

if (!function_exists('pc_folio_render_frontend_styles')) {
    /**
     * Print small frontend styles for Folio checkout and order notices.
     */
    function pc_folio_render_frontend_styles(): void
    {
        if (is_admin()) {
            return;
        }
        ?>
        <style>
            .pc-folio-checkout-processing {
                border-left: 4px solid #7f54b3;
                margin: 0 0 18px;
                padding: 12px 14px;
                background: #f6f1fb;
                color: #242228;
                font-size: 16px;
                line-height: 1.4;
            }
            .pc-folio-customer-notice {
                border: 1px solid #d7d7d7;
                border-left: 5px solid #7f54b3;
                margin: 0 0 24px;
                padding: 16px 18px;
                background: #fff;
            }
            .pc-folio-customer-notice--success { border-left-color: #2271b1; }
            .pc-folio-customer-notice--error { border-left-color: #b32d2e; }
            .pc-folio-customer-notice h2 {
                font-size: 22px;
                margin: 0 0 8px;
            }
            .pc-folio-customer-notice p {
                margin: 0 0 8px;
            }
            .pc-folio-customer-notice__warehouse {
                font-weight: 700;
                font-size: 18px;
            }
            .pc-folio-customer-notice__orders {
                margin: 8px 0 0 18px;
            }
        </style>
        <?php
    }
}

add_action('woocommerce_order_details_before_order_table', 'pc_folio_render_customer_order_message', 5);
add_filter('woocommerce_my_account_my_orders_columns', 'pc_folio_add_my_account_orders_column', 20);
add_action('woocommerce_my_account_my_orders_column_pc-folio', 'pc_folio_render_my_account_orders_column', 10);
add_action('wp_head', 'pc_folio_render_frontend_styles', 20);
add_action('wp_footer', 'pc_folio_render_checkout_processing_message', 20);

if (!function_exists('pc_folio_get_parent_split_order_status')) {
    /**
     * Use the visible local import draft status for split parent orders.
     */
    function pc_folio_get_parent_split_order_status(): string
    {
        return 'pc-draft';
    }
}

if (!function_exists('pc_folio_order_should_wait_for_online_payment')) {
    /**
     * Online gateways create the order before payment, so Folio must wait until Woo marks it paid.
     */
    function pc_folio_order_should_wait_for_online_payment(\WC_Order $order): bool
    {
        $payment_method = strtolower((string) $order->get_payment_method());
        $online_methods = (array) apply_filters('pc_folio_online_payment_methods', [
            'wayforpay',
        ]);

        if (!in_array($payment_method, array_map('strtolower', $online_methods), true)) {
            return false;
        }

        return !$order->is_paid();
    }
}

if (!function_exists('pc_folio_apply_saved_response_to_order')) {
    /**
     * Apply the saved Java response only as Woo meta/status preparation.
     *
     * This intentionally does not create child orders yet.
     */
    function pc_folio_apply_saved_response_to_order(\WC_Order $order): array
    {
        $result = pc_folio_get_order_documents_result($order);
        if (empty($result)) {
            return [
                'ok'      => false,
                'message' => __('No saved Folio response found for this order.', 'pc-folio-order-link'),
            ];
        }

        if (isset($result['ok']) && !$result['ok']) {
            return [
                'ok'      => false,
                'message' => __('Saved Folio response is not OK.', 'pc-folio-order-link'),
            ];
        }

        $documents = isset($result['documents']) && is_array($result['documents'])
            ? $result['documents']
            : [];
        if (!$documents) {
            return [
                'ok'      => false,
                'message' => __('Result: no Folio documents found in response.', 'pc-folio-order-link'),
            ];
        }

        $real_documents = [];
        $missing_documents = [];
        foreach ($documents as $document) {
            if (!is_array($document)) {
                continue;
            }

            if (pc_folio_is_missing_document($document)) {
                $missing_documents[] = $document;
            } else {
                $real_documents[] = $document;
            }
        }

        $keys = pc_folio_order_documents_meta_keys();
        if ((string) $order->get_meta($keys['split_status'], true) === 'ready_to_split') {
            return [
                'ok'      => true,
                'status'  => 'ready_to_split',
                'message' => __('Saved Folio response is already marked ready for split.', 'pc-folio-order-link'),
            ];
        }

        if (count($documents) === 1 && count($real_documents) === 1 && count($missing_documents) === 0) {
            $single_link = pc_folio_get_single_real_document_link($result);
            if (!$single_link) {
                return [
                    'ok'      => false,
                    'message' => __('Could not build Folio document link from saved response.', 'pc-folio-order-link'),
                ];
            }

            pc_folio_set_order_document_link($order, $single_link);
            if ($order->get_status() !== 'processing') {
                $order->update_status('processing', __('Saved Folio response applied: linked one Folio account.', 'pc-folio-order-link'));
            } else {
                $order->add_order_note(__('Saved Folio response applied: linked one Folio account.', 'pc-folio-order-link'));
            }

            return [
                'ok'      => true,
                'status'  => 'linked',
                'message' => sprintf(__('Saved Folio response applied. Linked document %s.', 'pc-folio-order-link'), pc_folio_document_label($real_documents[0])),
            ];
        }

        $order->update_meta_data($keys['split_status'], 'ready_to_split');
        $order->update_meta_data($keys['split_created_at'], current_time('mysql'));
        $order->save();

        $note_lines = [
            __('Saved Folio response marked ready for Woo split. Child orders were not created yet.', 'pc-folio-order-link'),
        ];

        foreach ($real_documents as $document) {
            $note_lines[] = sprintf(__('Real Folio account: %s', 'pc-folio-order-link'), pc_folio_document_label($document));
        }

        foreach ($missing_documents as $document) {
            $note_lines[] = sprintf(__('Missing-stock Folio account: %s', 'pc-folio-order-link'), pc_folio_document_label($document));
        }

        $order->add_order_note(implode("\n", $note_lines));

        return [
            'ok'      => true,
            'status'  => 'ready_to_split',
            'message' => __('Saved Folio response marked ready for split. No child orders were created yet.', 'pc-folio-order-link'),
        ];
    }
}

if (!function_exists('pc_folio_get_order_item_by_id')) {
    /**
     * Find a parent order line item by Woo order item ID.
     */
    function pc_folio_get_order_item_by_id(\WC_Order $order, int $item_id): ?\WC_Order_Item_Product
    {
        $item = $item_id > 0 ? $order->get_item($item_id) : false;

        return ($item instanceof \WC_Order_Item_Product) ? $item : null;
    }
}

if (!function_exists('pc_folio_copy_order_customer_data')) {
    /**
     * Copy customer, billing, shipping and payment identity to a child order.
     */
    function pc_folio_copy_order_customer_data(\WC_Order $source, \WC_Order $target): void
    {
        $target->set_customer_id((int) $source->get_customer_id());
        $target->set_currency($source->get_currency());
        $target->set_prices_include_tax($source->get_prices_include_tax());
        $target->set_payment_method($source->get_payment_method());
        $target->set_payment_method_title($source->get_payment_method_title());
        $target->set_customer_note($source->get_customer_note());
        $target->set_address($source->get_address('billing'), 'billing');
        $target->set_address($source->get_address('shipping'), 'shipping');
    }
}

if (!function_exists('pc_folio_create_child_order_item')) {
    /**
     * Add a Folio document item to a child Woo order.
     */
    function pc_folio_create_child_order_item(\WC_Order $parent_order, \WC_Order $child_order, array $folio_item): void
    {
        $source_item_id = (int) ($folio_item['order_item_id'] ?? ($folio_item['orderItemId'] ?? 0));
        $source_item = pc_folio_get_order_item_by_id($parent_order, $source_item_id);
        $sku = (string) ($folio_item['sku'] ?? '');
        $quantity = max(0, (float) ($folio_item['quantity'] ?? 0));
        $amount = (float) ($folio_item['amount'] ?? 0);
        $price = (float) ($folio_item['price'] ?? 0);
        $product = $source_item ? $source_item->get_product() : false;

        if (!$product && $sku !== '') {
            $product_id = wc_get_product_id_by_sku($sku);
            $product = $product_id ? wc_get_product($product_id) : false;
        }

        $order_item = new \WC_Order_Item_Product();
        if ($product) {
            $order_item->set_product($product);
        }
        if ($source_item) {
            $order_item->set_name($source_item->get_name());
            $order_item->set_tax_class($source_item->get_tax_class());
        } else {
            $order_item->set_name($sku !== '' ? $sku : __('Folio item', 'pc-folio-order-link'));
        }

        $order_item->set_quantity($quantity);
        $order_item->set_subtotal($amount);
        $order_item->set_total($amount);
        $order_item->set_subtotal_tax(0);
        $order_item->set_total_tax(0);
        $order_item->add_meta_data('_folio_source_order_item_id', $source_item_id, true);
        $order_item->add_meta_data('_folio_sku', $sku, true);
        $order_item->add_meta_data('_folio_price', $price, true);
        $order_item->add_meta_data('_folio_warehouse_id', (string) ($folio_item['folio_warehouse_id'] ?? ($folio_item['warehouseId'] ?? '')), true);
        $order_item->add_meta_data('_folio_allocation_status', (string) ($folio_item['allocation_status'] ?? ($folio_item['allocationStatus'] ?? '')), true);

        $child_order->add_item($order_item);
    }
}

if (!function_exists('pc_folio_create_child_order_from_document')) {
    /**
     * Create one Woo child order from one Folio document response.
     */
    function pc_folio_create_child_order_from_document(\WC_Order $parent_order, array $document): \WC_Order
    {
        $child_order = wc_create_order([
            'customer_id' => (int) $parent_order->get_customer_id(),
            'created_via' => 'pc-folio-split',
        ]);
        if (!$child_order instanceof \WC_Order) {
            throw new \RuntimeException(__('Could not create Woo child order.', 'pc-folio-order-link'));
        }

        if (method_exists($child_order, 'set_parent_id')) {
            $child_order->set_parent_id((int) $parent_order->get_id());
        }

        pc_folio_copy_order_customer_data($parent_order, $child_order);

        $items = isset($document['items']) && is_array($document['items']) ? $document['items'] : [];
        foreach ($items as $folio_item) {
            if (is_array($folio_item)) {
                pc_folio_create_child_order_item($parent_order, $child_order, $folio_item);
            }
        }

        $child_order->calculate_totals(false);
        $child_order->update_meta_data('_folio_split_from_order_id', (int) $parent_order->get_id());
        $child_order->update_meta_data('_folio_split_document_kind', pc_folio_is_missing_document($document) ? 'missing_stock' : 'account');
        pc_folio_set_order_document_link($child_order, pc_folio_get_single_document_link($document));
        pc_folio_set_order_customer_notice($child_order, pc_folio_build_child_order_notice($document));

        $status = pc_folio_is_missing_document($document) ? 'on-hold' : 'processing';
        $note = pc_folio_is_missing_document($document)
            ? __('Created from parent order as missing-stock Folio child order.', 'pc-folio-order-link')
            : __('Created from parent order as Folio child order.', 'pc-folio-order-link');
        $child_order->update_status($status, $note);
        $child_order->save();

        return $child_order;
    }
}

if (!function_exists('pc_folio_create_child_orders_from_saved_response')) {
    /**
     * Create Woo child orders from a saved Java/Folio split response.
     */
    function pc_folio_create_child_orders_from_saved_response(\WC_Order $parent_order): array
    {
        $keys = pc_folio_order_documents_meta_keys();
        if ((string) $parent_order->get_meta($keys['split_status'], true) !== 'ready_to_split') {
            return [
                'ok'      => false,
                'message' => __('Order is not marked ready for split.', 'pc-folio-order-link'),
            ];
        }

        $existing_child_ids = $parent_order->get_meta($keys['child_order_ids'], true);
        if (is_array($existing_child_ids) && array_filter($existing_child_ids)) {
            return [
                'ok'      => false,
                'message' => __('Woo child orders are already linked to this parent order.', 'pc-folio-order-link'),
            ];
        }

        $result = pc_folio_get_order_documents_result($parent_order);
        $documents = isset($result['documents']) && is_array($result['documents']) ? $result['documents'] : [];
        if (!$documents) {
            return [
                'ok'      => false,
                'message' => __('Result: no Folio documents found in response.', 'pc-folio-order-link'),
            ];
        }

        $child_order_ids = [];
        foreach ($documents as $document) {
            if (!is_array($document)) {
                continue;
            }

            $child_order = pc_folio_create_child_order_from_document($parent_order, $document);
            $child_order_ids[] = (int) $child_order->get_id();
        }

        pc_folio_set_parent_child_links($parent_order, $child_order_ids);
        $parent_order->update_meta_data($keys['split_status'], 'split_created');
        $parent_order->update_meta_data($keys['split_created_at'], current_time('mysql'));
        pc_folio_set_order_customer_notice($parent_order, pc_folio_build_parent_split_notice());
        $parent_order->save();
        $parent_split_status = pc_folio_get_parent_split_order_status();
        if ($parent_order->get_status() !== $parent_split_status) {
            $parent_order->update_status($parent_split_status, __('Parent order moved to draft after Folio split. Child order statuses were preserved.', 'pc-folio-order-link'));
        }
        $parent_order->add_order_note(sprintf(
            __('Created %d Woo child orders from saved Folio response. Parent order was moved to draft.', 'pc-folio-order-link'),
            count($child_order_ids)
        ));

        return [
            'ok'              => true,
            'status'          => 'split_created',
            'child_order_ids' => $child_order_ids,
            'message'         => sprintf(__('Created %d Woo child orders.', 'pc-folio-order-link'), count($child_order_ids)),
        ];
    }
}

if (!function_exists('pc_folio_auto_process_checkout_order')) {
    /**
     * Automatically run the verified manual Folio pipeline for a fresh checkout order.
     *
     * Classic checkout passes an order ID, while Store API checkout passes a WC_Order object.
     */
    function pc_folio_auto_process_checkout_order($order_or_id): void
    {
        if (!pc_folio_auto_checkout_enabled()) {
            return;
        }

        $order = ($order_or_id instanceof \WC_Order) ? $order_or_id : wc_get_order(absint($order_or_id));
        if (!$order instanceof \WC_Order) {
            return;
        }
        $order_id = (int) $order->get_id();

        if ((int) $order->get_parent_id() > 0 || $order->get_meta('_folio_split_from_order_id', true)) {
            return;
        }

        if (in_array($order->get_status(), ['trash', 'cancelled', 'refunded', 'failed', 'pc-draft'], true)) {
            return;
        }

        if (pc_folio_order_has_saved_documents($order)) {
            return;
        }

        if (pc_folio_order_should_wait_for_online_payment($order)) {
            $order->update_meta_data('_folio_auto_status', 'waiting_payment');
            $order->update_meta_data('_folio_auto_started_at', current_time('mysql'));
            $order->delete_meta_data('_folio_auto_error');
            $order->delete_meta_data('_folio_auto_error_raw');
            $order->add_order_note(__('Automatic Folio checkout processing is waiting for online payment confirmation.', 'pc-folio-order-link'));
            $order->save();
            return;
        }

        $auto_status = (string) $order->get_meta('_folio_auto_status', true);
        if (in_array($auto_status, ['running', 'success'], true)) {
            return;
        }

        $order->update_meta_data('_folio_auto_status', 'running');
        $order->update_meta_data('_folio_auto_started_at', current_time('mysql'));
        $order->delete_meta_data('_folio_auto_error');
        $order->delete_meta_data('_folio_auto_error_raw');
        $order->save();

        try {
            $create = pc_folio_create_documents_for_order($order, 'checkout');
            if (empty($create['ok'])) {
                if (!empty($create['raw'])) {
                    $order->update_meta_data('_folio_auto_error_raw', substr((string) $create['raw'], 0, 5000));
                    $order->save();
                }
                throw new \RuntimeException($create['message'] ?? __('Folio automatic creation failed.', 'pc-folio-order-link'));
            }

            $apply = pc_folio_apply_saved_response_to_order($order);
            if (empty($apply['ok'])) {
                throw new \RuntimeException($apply['message'] ?? __('Saved Folio response could not be applied.', 'pc-folio-order-link'));
            }

            $child_result = null;
            if (($apply['status'] ?? '') === 'ready_to_split') {
                $child_result = pc_folio_create_child_orders_from_saved_response($order);
                if (empty($child_result['ok'])) {
                    throw new \RuntimeException($child_result['message'] ?? __('Woo child orders could not be created.', 'pc-folio-order-link'));
                }
            }

            $order = wc_get_order($order_id);
            if ($order instanceof \WC_Order) {
                $order->update_meta_data('_folio_auto_status', 'success');
                $order->update_meta_data('_folio_auto_finished_at', current_time('mysql'));
                $order->delete_meta_data('_folio_auto_error');
                $order->delete_meta_data('_folio_auto_error_raw');
                $order->add_order_note(__('Automatic Folio checkout processing completed.', 'pc-folio-order-link'));
                if (is_array($child_result) && !empty($child_result['child_order_ids'])) {
                    $order->add_order_note(sprintf(
                        __('Automatic Folio split created child Woo orders: %s.', 'pc-folio-order-link'),
                        implode(', ', array_map('strval', (array) $child_result['child_order_ids']))
                    ));
                }
                $order->save();
                pc_folio_clear_current_checkout_cart();
            }
        } catch (\Throwable $e) {
            $order = wc_get_order($order_id);
            if ($order instanceof \WC_Order) {
                $order->update_meta_data('_folio_auto_status', 'error');
                $order->update_meta_data('_folio_auto_finished_at', current_time('mysql'));
                $order->update_meta_data('_folio_auto_error', $e->getMessage());
                $order->add_order_note(sprintf(__('Automatic Folio checkout processing failed: %s', 'pc-folio-order-link'), $e->getMessage()));
                $order->save();
            }
        }
    }
}

add_action('woocommerce_checkout_order_processed', 'pc_folio_auto_process_checkout_order', 90, 1);
add_action('woocommerce_store_api_checkout_order_processed', 'pc_folio_auto_process_checkout_order', 90, 1);
add_action('woocommerce_payment_complete', 'pc_folio_auto_process_checkout_order', 90, 1);
add_action('woocommerce_order_status_processing', 'pc_folio_auto_process_checkout_order', 90, 1);

add_filter('woocommerce_get_checkout_order_received_url', function ($url, $order) {
    if (!$order instanceof \WC_Order || !function_exists('wc_get_account_endpoint_url')) {
        return $url;
    }

    if (!pc_folio_order_should_go_to_account_orders($order)) {
        return $url;
    }

    return add_query_arg(
        [
            'folio_checkout_order' => (int) $order->get_id(),
        ],
        wc_get_account_endpoint_url('orders')
    );
}, 20, 2);

if (!function_exists('pc_folio_set_order_documents_result')) {
    /**
     * Store the full Java/Folio split response on a Woo order.
     *
     * This does not create child orders and does not change order status.
     *
     * @param int|\WC_Order $order_or_id Order object or order ID.
     */
    function pc_folio_set_order_documents_result($order_or_id, array $result): bool
    {
        $order = ($order_or_id instanceof \WC_Order) ? $order_or_id : wc_get_order($order_or_id);
        if (!$order) {
            return false;
        }

        $keys = pc_folio_order_documents_meta_keys();
        $clean = pc_folio_clean_meta_value($result);

        $order->update_meta_data($keys['documents_result'], $clean);
        $order->update_meta_data($keys['split_status'], pc_folio_guess_split_status($clean));
        $order->update_meta_data($keys['split_created_at'], current_time('mysql'));
        $order->save();

        return true;
    }
}

if (!function_exists('pc_folio_set_parent_child_links')) {
    /**
     * Link an existing parent order with existing child orders.
     *
     * This only writes meta. It does not create orders and does not change statuses.
     *
     * @param int|\WC_Order $parent_order_or_id Parent order object or ID.
     * @param int[]         $child_order_ids    Existing child Woo order IDs.
     */
    function pc_folio_set_parent_child_links($parent_order_or_id, array $child_order_ids): bool
    {
        $parent_order = ($parent_order_or_id instanceof \WC_Order) ? $parent_order_or_id : wc_get_order($parent_order_or_id);
        if (!$parent_order) {
            return false;
        }

        $parent_id = (int) $parent_order->get_id();
        $child_order_ids = array_values(array_unique(array_filter(array_map('absint', $child_order_ids))));
        $keys = pc_folio_order_documents_meta_keys();

        $parent_order->update_meta_data($keys['child_order_ids'], $child_order_ids);
        if ($child_order_ids) {
            $parent_order->update_meta_data($keys['split_status'], 'split');
        }
        $parent_order->save();

        foreach ($child_order_ids as $child_order_id) {
            $child_order = wc_get_order($child_order_id);
            if (!$child_order) {
                continue;
            }

            $child_order->update_meta_data($keys['parent_order_id'], $parent_id);
            $child_order->save();
        }

        return true;
    }
}

if (!function_exists('pc_folio_get_order_document_link')) {
    /**
     * Read the Folio document link from an order.
     *
     * @param int|\WC_Order $order_or_id Order object or order ID.
     */
    function pc_folio_get_order_document_link($order_or_id): array
    {
        $order = ($order_or_id instanceof \WC_Order) ? $order_or_id : wc_get_order($order_or_id);
        if (!$order) {
            return [];
        }

        $data = [];
        foreach (pc_folio_order_link_meta_keys() as $field => $meta_key) {
            $data[$field] = (string) $order->get_meta($meta_key, true);
        }

        return $data;
    }
}

if (!function_exists('pc_folio_set_order_document_link')) {
    /**
     * Save the Folio document link to an order.
     *
     * Empty values remove the corresponding meta key. Unknown fields are ignored.
     *
     * @param int|\WC_Order $order_or_id Order object or order ID.
     */
    function pc_folio_set_order_document_link($order_or_id, array $data): bool
    {
        $order = ($order_or_id instanceof \WC_Order) ? $order_or_id : wc_get_order($order_or_id);
        if (!$order) {
            return false;
        }

        foreach (pc_folio_order_link_meta_keys() as $field => $meta_key) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            $value = is_scalar($data[$field]) ? trim((string) $data[$field]) : '';
            if ($value === '') {
                $order->delete_meta_data($meta_key);
            } else {
                $order->update_meta_data($meta_key, $value);
            }
        }

        $order->save();
        return true;
    }
}

if (!function_exists('pc_folio_clear_order_document_link')) {
    /**
     * Remove all Folio document link meta from an order.
     *
     * @param int|\WC_Order $order_or_id Order object or order ID.
     */
    function pc_folio_clear_order_document_link($order_or_id): bool
    {
        $order = ($order_or_id instanceof \WC_Order) ? $order_or_id : wc_get_order($order_or_id);
        if (!$order) {
            return false;
        }

        foreach (pc_folio_order_link_meta_keys() as $meta_key) {
            $order->delete_meta_data($meta_key);
        }

        $order->save();
        return true;
    }
}

if (!function_exists('pc_folio_get_order_customer_link')) {
    /**
     * Read the Folio customer mapping for the order customer.
     */
    function pc_folio_get_order_customer_link(\WC_Order $order): array
    {
        $user_id = (int) $order->get_user_id();
        if ($user_id <= 0) {
            return [
                'user_id'    => 0,
                'id'         => '',
                'short_name' => '',
                'name'       => '',
                'type'       => '',
            ];
        }

        return [
            'user_id'    => $user_id,
            'id'         => (string) get_user_meta($user_id, '_folio_partner_id', true),
            'short_name' => (string) get_user_meta($user_id, '_folio_partner_short_name', true),
            'name'       => (string) get_user_meta($user_id, '_folio_partner_name', true),
            'type'       => (string) get_user_meta($user_id, '_folio_partner_type', true),
        ];
    }
}

if (!function_exists('pc_folio_get_location_warehouses_for_preview')) {
    /**
     * Read Folio warehouse priorities for a Woo location term.
     */
    function pc_folio_get_location_warehouses_for_preview(int $term_id): array
    {
        if ($term_id <= 0) {
            return [];
        }

        if (function_exists('lavka_get_location_folio_warehouses')) {
            return array_values((array) lavka_get_location_folio_warehouses($term_id));
        }

        $raw = get_term_meta($term_id, 'lavka_folio_warehouses', true);
        if (!is_array($raw)) {
            return [];
        }

        $items = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = isset($row['id']) ? trim((string) $row['id']) : '';
            $priority = isset($row['priority']) ? (int) $row['priority'] : 0;
            if ($id === '') {
                continue;
            }

            $items[] = [
                'id'       => $id,
                'priority' => $priority > 0 ? $priority : 100,
            ];
        }

        usort($items, static function ($a, $b) {
            return ((int) $a['priority']) <=> ((int) $b['priority']);
        });

        return $items;
    }
}

if (!function_exists('pc_folio_preview_text')) {
    /**
     * Normalize Woo strings for JSON preview: decode HTML entities and trim whitespace.
     */
    function pc_folio_preview_text($value): string
    {
        return trim(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}

if (!function_exists('pc_folio_header_short_text')) {
    /**
     * Keep Folio header fields inside legacy varchar limits without breaking UTF-8 text.
     */
    function pc_folio_header_short_text($value, int $max_length = 30): string
    {
        $text = preg_replace('/\s+/', ' ', pc_folio_preview_text($value));
        $text = trim((string) $text);

        if ($max_length <= 0 || $text === '') {
            return '';
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($text, 'UTF-8') > $max_length
                ? mb_substr($text, 0, $max_length, 'UTF-8')
                : $text;
        }

        return strlen($text) > $max_length ? substr($text, 0, $max_length) : $text;
    }
}

if (!function_exists('pc_folio_get_order_price_contract_type')) {
    /**
     * Resolve the Folio price contract from the customer's Woo role mapping.
     */
    function pc_folio_get_order_price_contract_type(\WC_Order $order): string
    {
        $user_id = (int) $order->get_user_id();
        if ($user_id <= 0 || !function_exists('get_userdata')) {
            return '';
        }

        $user = get_userdata($user_id);
        if (!$user || empty($user->roles) || !is_array($user->roles)) {
            return '';
        }

        $map = function_exists('lps_get_role_contract_map') ? (array) lps_get_role_contract_map() : [];
        foreach ($user->roles as $role) {
            $role = sanitize_key((string) $role);
            if ($role !== '' && isset($map[$role]) && $map[$role] !== '') {
                return pc_folio_preview_text($map[$role]);
            }
        }

        return '';
    }
}

if (!function_exists('pc_folio_build_delivery_info')) {
    /**
     * Build a human delivery/contact summary for the Folio account header preview.
     */
    function pc_folio_build_delivery_info(\WC_Order $order): string
    {
        $parts = [];

        $shipping_method = pc_folio_preview_text($order->get_shipping_method());
        if ($shipping_method !== '') {
            $parts[] = $shipping_method;
        }

        $city = pc_folio_preview_text($order->get_shipping_city() ?: $order->get_billing_city());
        if ($city !== '') {
            $parts[] = $city;
        }

        $address = trim(implode(' ', array_filter([
            pc_folio_preview_text($order->get_shipping_address_1() ?: $order->get_billing_address_1()),
            pc_folio_preview_text($order->get_shipping_address_2() ?: $order->get_billing_address_2()),
        ])));
        if ($address !== '') {
            $parts[] = $address;
        }

        $payment_method = pc_folio_preview_text($order->get_payment_method_title());
        if ($payment_method !== '') {
            $parts[] = $payment_method;
        }

        $phone = pc_folio_preview_text($order->get_billing_phone());
        if ($phone !== '') {
            $parts[] = sprintf('tel. %s', $phone);
        }

        return implode(', ', $parts);
    }
}

if (!function_exists('pc_folio_build_account_header_preview')) {
    /**
     * Build the extended Folio account header fields from Woo order data.
     */
    function pc_folio_build_account_header_preview(\WC_Order $order, array $folio_client): array
    {
        $now = time();
        $created = $order->get_date_created();
        $document_ts = $created ? (int) $created->getTimestamp() : $now;
        $ordered_at = $created ? $created->date_i18n('Y-m-d H:i:s') : wp_date('Y-m-d H:i:s', $now);
        $ordered_at_short = $created ? $created->date_i18n('Y-m-d H:i') : wp_date('Y-m-d H:i', $now);
        $payer_name = pc_folio_preview_text($folio_client['name'] ?? '');
        if ($payer_name === '') {
            $payer_name = trim(implode(' ', array_filter([
                pc_folio_preview_text($order->get_billing_first_name()),
                pc_folio_preview_text($order->get_billing_last_name()),
            ])));
        }
        $site_customer_name = trim(implode(' ', array_filter([
            pc_folio_preview_text($order->get_billing_first_name()),
            pc_folio_preview_text($order->get_billing_last_name()),
        ])));
        if ($site_customer_name === '') {
            $site_customer_name = pc_folio_preview_text($order->get_billing_email());
        }
        $site_name = pc_folio_preview_text(get_bloginfo('name'));
        $source_info = trim($site_name . ($site_customer_name !== '' ? ' + ' . $site_customer_name : ''));
        $source_info = pc_folio_header_short_text($source_info !== '' ? $source_info : 'Internet order');
        $additional_info = pc_folio_header_short_text(sprintf(
            'Int %s %s',
            $order->get_order_number(),
            $ordered_at_short
        ));

        return [
            'externalRequestId'   => function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : md5(uniqid('', true)),
            'documentNumber'      => '',
            'documentDate'        => wp_date('Y-m-d\T00:00:00', $document_ts),
            'controlDate'         => wp_date('Y-m-d', $document_ts + (3 * DAY_IN_SECONDS)),
            'warehouseId'         => null,
            'operationType'       => 'СЧЕТ',
            'folioOperationKind'  => '*ПРЕДОПЛАТ',
            'payerName'           => $payer_name,
            'receiverName'        => 'CLASSIC',
            'payerShortName'      => pc_folio_preview_text($folio_client['short_name'] ?? ($folio_client['id'] ?? '')),
            'folioUser'           => 'buh',
            'sourceInfo'          => $source_info,
            'additionalInfo'      => $additional_info,
            'priceContractType'   => pc_folio_get_order_price_contract_type($order),
            'notCash'             => true,
            'accountingEnabled'   => true,
            'returnFlag'          => false,
            'payerCity'           => pc_folio_preview_text($order->get_billing_city()),
            'directorName'        => '',
            'accountantName'      => '',
            'payerPhone'          => pc_folio_preview_text($order->get_billing_phone()),
            'deliveryInfo'        => pc_folio_build_delivery_info($order),
            'comment'             => sprintf('Woo order #%s, ordered at %s', $order->get_order_number(), $ordered_at),
        ];
    }
}

if (!function_exists('pc_folio_build_order_preview_payload')) {
    /**
     * Build a draft Folio payload from Woo order data without sending it anywhere.
     */
    function pc_folio_build_order_preview_payload($order_or_id): array
    {
        $order = ($order_or_id instanceof \WC_Order) ? $order_or_id : wc_get_order($order_or_id);
        if (!$order) {
            return [];
        }

        $items = [];
        foreach ($order->get_items('line_item') as $item_id => $item) {
            if (!($item instanceof \WC_Order_Item_Product)) {
                continue;
            }

            $product = $item->get_product();
            $plan = function_exists('pc_get_order_item_plan') ? (array) pc_get_order_item_plan($item) : [];
            $allocations = [];

            foreach ($plan as $term_id => $qty) {
                $term_id = (int) $term_id;
                $term = $term_id > 0 ? get_term($term_id, 'location') : null;
                $allocations[] = [
                    'woo_location_id'      => $term_id,
                    'woo_location_slug'    => ($term && !is_wp_error($term)) ? pc_folio_preview_text($term->slug) : '',
                    'woo_location_name'    => ($term && !is_wp_error($term)) ? pc_folio_preview_text($term->name) : '',
                    'quantity'             => (float) $qty,
                    'allocation_source'    => '_pc_alloc_plan',
                    'folio_warehouses'     => pc_folio_get_location_warehouses_for_preview($term_id),
                ];
            }

            $items[] = [
                'order_item_id' => (int) $item_id,
                'product_id'    => $product ? (int) $product->get_id() : 0,
                'sku'           => $product ? pc_folio_preview_text($product->get_sku()) : '',
                'name'          => pc_folio_preview_text($item->get_name()),
                'quantity'      => (float) $item->get_quantity(),
                'subtotal'      => (float) $item->get_subtotal(),
                'total'         => (float) $item->get_total(),
                'unit_price'    => (float) ((float) $item->get_quantity() > 0 ? ((float) $item->get_total() / (float) $item->get_quantity()) : 0),
                'allocations'   => $allocations,
            ];
        }

        $folio_client = pc_folio_get_order_customer_link($order);

        return [
            'preview_only'   => true,
            'schema_version' => 'folio-order-preview/v1',
            'source'         => 'woo_order',
            'intent'         => 'create_or_update_folio_documents',
            'split_strategy' => 'java_by_allocations_and_folio_warehouse_priority',
            'folio_account_header' => pc_folio_build_account_header_preview($order, $folio_client),
            'woo_order'      => [
                'id'       => (int) $order->get_id(),
                'number'   => (string) $order->get_order_number(),
                'status'   => (string) $order->get_status(),
                'currency' => (string) $order->get_currency(),
                'total'    => (float) $order->get_total(),
            ],
            'folio_client'   => $folio_client,
            'folio_document_link' => pc_folio_get_order_document_link($order),
            'billing'       => [
                'first_name' => pc_folio_preview_text($order->get_billing_first_name()),
                'last_name'  => pc_folio_preview_text($order->get_billing_last_name()),
                'company'    => pc_folio_preview_text($order->get_billing_company()),
                'phone'      => pc_folio_preview_text($order->get_billing_phone()),
                'email'      => pc_folio_preview_text($order->get_billing_email()),
                'city'       => pc_folio_preview_text($order->get_billing_city()),
                'address_1'  => pc_folio_preview_text($order->get_billing_address_1()),
                'address_2'  => pc_folio_preview_text($order->get_billing_address_2()),
            ],
            'items'         => $items,
        ];
    }
}

add_action('add_meta_boxes', function () {
    if (!function_exists('wc_get_order')) {
        return;
    }

    add_meta_box(
        'pc-folio-order-link',
        __('Folio document link', 'pc-folio-order-link'),
        'pc_folio_render_order_link_metabox',
        'shop_order',
        'side',
        'default'
    );

    add_meta_box(
        'pc-folio-order-link',
        __('Folio document link', 'pc-folio-order-link'),
        'pc_folio_render_order_link_metabox',
        'woocommerce_page_wc-orders',
        'side',
        'default'
    );

    add_meta_box(
        'pc-folio-order-preview',
        __('Folio JSON preview', 'pc-folio-order-link'),
        'pc_folio_render_order_preview_metabox',
        'shop_order',
        'normal',
        'default'
    );

    add_meta_box(
        'pc-folio-order-preview',
        __('Folio JSON preview', 'pc-folio-order-link'),
        'pc_folio_render_order_preview_metabox',
        'woocommerce_page_wc-orders',
        'normal',
        'default'
    );
});

if (!function_exists('pc_folio_render_order_link_metabox')) {
    /**
     * Render readonly/editable Folio link fields on the order edit screen.
     *
     * @param \WP_Post|\WC_Order $post_or_order_object Current order screen object.
     */
    function pc_folio_render_order_link_metabox($post_or_order_object): void
    {
        $order = ($post_or_order_object instanceof \WC_Order)
            ? $post_or_order_object
            : wc_get_order($post_or_order_object->ID ?? 0);

        if (!$order) {
            echo '<p>' . esc_html__('Order not found.', 'pc-folio-order-link') . '</p>';
            return;
        }

        wp_nonce_field('pc_folio_order_link_save', 'pc_folio_order_link_nonce');

        $values = pc_folio_get_order_document_link($order);
        $fields = [
            'document_id'           => __('Document ID', 'pc-folio-order-link'),
            'document_number'       => __('Document number', 'pc-folio-order-link'),
            'document_type'         => __('Document type', 'pc-folio-order-link'),
            'document_status'       => __('Document status', 'pc-folio-order-link'),
            'document_created_at'   => __('Created at', 'pc-folio-order-link'),
            'document_payload_hash' => __('Payload hash', 'pc-folio-order-link'),
        ];

        echo '<div class="pc-folio-order-link-fields">';
        foreach ($fields as $field => $label) {
            printf(
                '<p><label for="pc_folio_%1$s"><strong>%2$s</strong></label><br><input type="text" class="widefat" id="pc_folio_%1$s" name="pc_folio_order_link[%1$s]" value="%3$s"></p>',
                esc_attr($field),
                esc_html($label),
                esc_attr($values[$field] ?? '')
            );
        }

        printf(
            '<p><label for="pc_folio_document_last_error"><strong>%1$s</strong></label><br><textarea class="widefat" rows="3" id="pc_folio_document_last_error" name="pc_folio_order_link[document_last_error]">%2$s</textarea></p>',
            esc_html__('Last error', 'pc-folio-order-link'),
            esc_textarea($values['document_last_error'] ?? '')
        );
        echo '<p class="description">' . esc_html__('These fields only store the Woo order to Folio document connection. They do not send anything to Folio.', 'pc-folio-order-link') . '</p>';

        $document_keys = pc_folio_order_documents_meta_keys();
        $split_status = (string) $order->get_meta($document_keys['split_status'], true);
        $documents_result = pc_folio_get_order_documents_result($order);
        $documents = isset($documents_result['documents']) && is_array($documents_result['documents'])
            ? $documents_result['documents']
            : [];
        $child_order_ids = $order->get_meta($document_keys['child_order_ids'], true);
        $child_order_ids = is_array($child_order_ids) ? array_values(array_filter(array_map('absint', $child_order_ids))) : [];
        $parent_order_id = (int) $order->get_meta($document_keys['parent_order_id'], true);
        $split_from_order_id = (int) $order->get_meta('_folio_split_from_order_id', true);
        $source_parent_order_id = $parent_order_id > 0 ? $parent_order_id : $split_from_order_id;
        $split_document_kind = (string) $order->get_meta('_folio_split_document_kind', true);

        echo '<hr>';
        echo '<p><strong>' . esc_html__('Split status', 'pc-folio-order-link') . '</strong><br>';
        echo '<code>' . esc_html($split_status !== '' ? $split_status : __('not set', 'pc-folio-order-link')) . '</code></p>';

        if ($source_parent_order_id > 0) {
            $source_parent_order = wc_get_order($source_parent_order_id);
            $source_parent_label = $source_parent_order
                ? sprintf('#%1$s (%2$s)', $source_parent_order->get_order_number(), wc_get_order_status_name($source_parent_order->get_status()))
                : sprintf('#%d', $source_parent_order_id);
            $source_parent_url = $source_parent_order ? $source_parent_order->get_edit_order_url() : '';

            echo '<p><strong>' . esc_html__('Parent Woo order', 'pc-folio-order-link') . '</strong><br>';
            if ($source_parent_url !== '') {
                printf('<a href="%1$s">%2$s</a>', esc_url($source_parent_url), esc_html($source_parent_label));
            } else {
                echo esc_html($source_parent_label);
            }
            echo '<br><span class="description">' . esc_html__('This order was created from the parent order during Folio split.', 'pc-folio-order-link') . '</span></p>';
        }

        if ($split_document_kind !== '') {
            echo '<p><strong>' . esc_html__('Folio split document kind', 'pc-folio-order-link') . '</strong><br>';
            echo '<code>' . esc_html($split_document_kind) . '</code></p>';
        }

        $customer_notice = (string) $order->get_meta('_folio_customer_notice', true);
        if ($customer_notice !== '') {
            echo '<p><strong>' . esc_html__('Folio customer notice', 'pc-folio-order-link') . '</strong><br>';
            echo '<span class="description">' . esc_html($customer_notice) . '</span></p>';
        }

        echo '<p><strong>' . esc_html__('Saved Folio documents', 'pc-folio-order-link') . '</strong></p>';
        if ($documents) {
            echo '<ul style="margin-left:0;list-style:none">';
            foreach ($documents as $document) {
                if (!is_array($document)) {
                    continue;
                }

                $document_type = (string) ($document['document_type'] ?? ($document['documentType'] ?? ''));
                $document_status = (string) ($document['document_status'] ?? ($document['documentStatus'] ?? ''));
                $items = isset($document['items']) && is_array($document['items']) ? $document['items'] : [];
                $label = pc_folio_document_label($document);
                $kind = pc_folio_is_missing_document($document)
                    ? __('missing stock', 'pc-folio-order-link')
                    : __('account', 'pc-folio-order-link');

                printf(
                    '<li style="margin-bottom:8px"><strong>%1$s</strong><br><code>%2$s</code> · <code>%3$s</code> · %4$s<br><span class="description">%5$s</span></li>',
                    esc_html($label),
                    esc_html($document_type !== '' ? $document_type : $kind),
                    esc_html($document_status !== '' ? $document_status : '-'),
                    esc_html(sprintf(_n('%d item', '%d items', count($items), 'pc-folio-order-link'), count($items))),
                    esc_html(sprintf(__('Kind: %s', 'pc-folio-order-link'), $kind))
                );

                echo '<li style="margin:-4px 0 12px 12px">';
                echo '<strong>' . esc_html__('Future Woo child order items', 'pc-folio-order-link') . '</strong>';
                if ($items) {
                    echo '<ul style="margin:4px 0 0 0;list-style:none">';
                    foreach ($items as $item) {
                        if (!is_array($item)) {
                            continue;
                        }

                        $sku = (string) ($item['sku'] ?? '');
                        $quantity = (float) ($item['quantity'] ?? 0);
                        $price = (float) ($item['price'] ?? 0);
                        $amount = (float) ($item['amount'] ?? 0);
                        $allocation_status = (string) ($item['allocation_status'] ?? ($item['allocationStatus'] ?? ''));
                        $line = sprintf(
                            '%1$s × %2$s · %3$s %4$s · %5$s %6$s',
                            $sku !== '' ? $sku : __('without SKU', 'pc-folio-order-link'),
                            wc_format_decimal($quantity, 2),
                            __('price', 'pc-folio-order-link'),
                            wc_price($price, ['currency' => $order->get_currency()]),
                            __('amount', 'pc-folio-order-link'),
                            wc_price($amount, ['currency' => $order->get_currency()])
                        );

                        if ($allocation_status !== '') {
                            $line .= ' · ' . $allocation_status;
                        }

                        echo '<li><span class="description">' . wp_kses_post($line) . '</span></li>';
                    }
                    echo '</ul>';
                } else {
                    echo '<p class="description" style="margin:4px 0 0">' . esc_html__('No items in this Folio document.', 'pc-folio-order-link') . '</p>';
                }
                echo '</li>';
            }
            echo '</ul>';
        } else {
            echo '<p class="description">' . esc_html__('No saved Folio documents yet.', 'pc-folio-order-link') . '</p>';
        }

        echo '<p><strong>' . esc_html__('Linked Woo child orders', 'pc-folio-order-link') . '</strong></p>';
        if ($child_order_ids) {
            echo '<ul style="margin-left:0;list-style:none">';
            foreach ($child_order_ids as $child_order_id) {
                $child_order = wc_get_order($child_order_id);
                $label = $child_order
                    ? sprintf('#%1$s (%2$s)', $child_order->get_order_number(), wc_get_order_status_name($child_order->get_status()))
                    : sprintf('#%d', $child_order_id);
                $url = $child_order ? $child_order->get_edit_order_url() : '';

                echo '<li>';
                if ($url !== '') {
                    printf('<a href="%1$s">%2$s</a>', esc_url($url), esc_html($label));
                } else {
                    echo esc_html($label);
                }
                echo '</li>';
            }
            echo '</ul>';
        } else {
            echo '<p class="description">' . esc_html__('No linked Woo child orders yet.', 'pc-folio-order-link') . '</p>';
        }

        echo '</div>';
    }
}

if (!function_exists('pc_folio_render_order_preview_metabox')) {
    /**
     * Render the draft Folio JSON payload preview.
     *
     * @param \WP_Post|\WC_Order $post_or_order_object Current order screen object.
     */
    function pc_folio_render_order_preview_metabox($post_or_order_object): void
    {
        $order = ($post_or_order_object instanceof \WC_Order)
            ? $post_or_order_object
            : wc_get_order($post_or_order_object->ID ?? 0);

        if (!$order) {
            echo '<p>' . esc_html__('Order not found.', 'pc-folio-order-link') . '</p>';
            return;
        }

        $payload = pc_folio_build_order_preview_payload($order);
        $order_id = (int) $order->get_id();
        $saved_result = pc_folio_get_order_documents_result($order);
        $document_keys = pc_folio_order_documents_meta_keys();
        $split_status = (string) $order->get_meta($document_keys['split_status'], true);
        $child_order_ids = $order->get_meta($document_keys['child_order_ids'], true);
        $child_order_ids = is_array($child_order_ids) ? array_values(array_filter(array_map('absint', $child_order_ids))) : [];
        $can_create_children = $split_status === 'ready_to_split' && empty($child_order_ids);
        echo '<p class="description">' . esc_html__('Preview only. This JSON is not sent to Folio yet.', 'pc-folio-order-link') . '</p>';
        printf(
            '<textarea class="widefat code" rows="18" readonly id="pc-folio-order-preview-json">%s</textarea>',
            esc_textarea(wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
        );
        ?>
        <p>
            <button type="button" class="button button-secondary" id="pc-folio-send-preview-java">
                <?php echo esc_html__('Send preview to Java', 'pc-folio-order-link'); ?>
            </button>
            <button type="button" class="button button-primary" id="pc-folio-create-java">
                <?php echo esc_html__('Create Folio account(s)', 'pc-folio-order-link'); ?>
            </button>
            <span class="spinner" id="pc-folio-send-preview-spinner" style="float:none"></span>
        </p>
        <pre id="pc-folio-java-preview-response" style="display:none;background:#1d2327;color:#f0f0f1;border:1px solid #3c434a;padding:10px;white-space:pre-wrap"></pre>
        <hr>
        <h3><?php echo esc_html__('Saved Folio response', 'pc-folio-order-link'); ?></h3>
        <p class="description">
            <?php
            echo $saved_result
                ? esc_html__('A saved Java response exists for this order. Preview what Woo would do before applying it.', 'pc-folio-order-link')
                : esc_html__('No saved Java response exists for this order yet.', 'pc-folio-order-link');
            ?>
        </p>
        <p>
            <button type="button" class="button" id="pc-folio-preview-saved-response" <?php disabled(empty($saved_result)); ?>>
                <?php echo esc_html__('Preview saved response actions', 'pc-folio-order-link'); ?>
            </button>
            <button type="button" class="button button-primary" id="pc-folio-apply-saved-response" <?php disabled(empty($saved_result)); ?>>
                <?php echo esc_html__('Apply saved Folio response', 'pc-folio-order-link'); ?>
            </button>
            <button type="button" class="button button-primary" id="pc-folio-create-child-orders" <?php disabled(!$can_create_children); ?>>
                <?php echo esc_html__('Create Woo child orders', 'pc-folio-order-link'); ?>
            </button>
        </p>
        <hr>
        <h3><?php echo esc_html__('Java response simulator', 'pc-folio-order-link'); ?></h3>
        <p class="description"><?php echo esc_html__('Paste a Java response to preview what Woo would do. This does not save data, create orders, or change statuses.', 'pc-folio-order-link'); ?></p>
        <textarea class="widefat code" rows="10" id="pc-folio-response-preview" placeholder="<?php echo esc_attr__('Paste Java response JSON here...', 'pc-folio-order-link'); ?>"></textarea>
        <p>
            <button type="button" class="button" id="pc-folio-response-simulate">
                <?php echo esc_html__('Preview response actions', 'pc-folio-order-link'); ?>
            </button>
        </p>
        <pre id="pc-folio-response-simulation-result" style="display:none;background:#f6f7f7;border:1px solid #dcdcde;padding:10px;white-space:pre-wrap"></pre>
        <script>
        (function(){
            var previewJson = document.getElementById('pc-folio-order-preview-json');
            var sendButton = document.getElementById('pc-folio-send-preview-java');
            var createButton = document.getElementById('pc-folio-create-java');
            var savedButton = document.getElementById('pc-folio-preview-saved-response');
            var applySavedButton = document.getElementById('pc-folio-apply-saved-response');
            var createChildrenButton = document.getElementById('pc-folio-create-child-orders');
            var sendSpinner = document.getElementById('pc-folio-send-preview-spinner');
            var rawResponse = document.getElementById('pc-folio-java-preview-response');
            var button = document.getElementById('pc-folio-response-simulate');
            var input = document.getElementById('pc-folio-response-preview');
            var output = document.getElementById('pc-folio-response-simulation-result');
            var orderId = <?php echo (int) $order_id; ?>;
            if (!button || !input || !output) {
                return;
            }

            function asText(value) {
                return String(value == null ? '' : value);
            }

            function docLabel(doc) {
                var number = asText(doc.document_number || doc.documentNumber || doc.document_id || doc.documentId || '');
                var warehouse = asText(doc.folio_warehouse_id || doc.warehouseId || '');
                var parts = [];
                if (number) parts.push('#' + number);
                if (warehouse) parts.push('<?php echo esc_js(__('warehouse', 'pc-folio-order-link')); ?> ' + warehouse);
                return parts.length ? parts.join(', ') : '<?php echo esc_js(__('without number', 'pc-folio-order-link')); ?>';
            }

            function isMissingDoc(doc) {
                return asText(doc.document_type || doc.documentType) === 'missing_stock_account'
                    || doc.accounting_enabled === false
                    || doc.accountingEnabled === false;
            }

            function warningLabel(warning) {
                if (typeof warning === 'string') {
                    return warning;
                }
                if (!warning || typeof warning !== 'object') {
                    return asText(warning);
                }

                var parts = [];
                if (warning.code) parts.push(asText(warning.code));
                if (warning.message) parts.push(asText(warning.message));
                if (warning.details && warning.details.sku) parts.push('SKU ' + asText(warning.details.sku));
                if (warning.details && warning.details.missingQuantity) parts.push('<?php echo esc_js(__('missing quantity', 'pc-folio-order-link')); ?> ' + asText(warning.details.missingQuantity));

                return parts.length ? parts.join(' - ') : JSON.stringify(warning);
            }

            function ajaxErrorText(data, fallback) {
                var lines = [];
                if (data && data.message) {
                    lines.push(asText(data.message));
                } else {
                    lines.push(fallback);
                }

                if (data && data.raw) {
                    lines.push('');
                    lines.push('Raw response:');
                    lines.push(asText(data.raw));
                }

                if (data && data.response) {
                    lines.push('');
                    lines.push('Parsed response:');
                    lines.push(JSON.stringify(data.response, null, 2));
                }

                return lines.join("\n");
            }

            function simulate(data) {
                var docs = Array.isArray(data.documents) ? data.documents : [];
                var realDocs = docs.filter(function(doc){ return doc && !isMissingDoc(doc); });
                var missingDocs = docs.filter(function(doc){ return doc && isMissingDoc(doc); });
                var lines = [];

                lines.push('<?php echo esc_js(__('No changes would be applied. This is a simulation only.', 'pc-folio-order-link')); ?>');
                lines.push('');

                if (!data || data.ok === false) {
                    lines.push('<?php echo esc_js(__('Result: Java response is not OK. Woo would stop and show an error.', 'pc-folio-order-link')); ?>');
                    return lines;
                }

                if (!docs.length) {
                    lines.push('<?php echo esc_js(__('Result: no Folio documents found in response.', 'pc-folio-order-link')); ?>');
                    return lines;
                }

                if (realDocs.length === 1 && missingDocs.length === 0) {
                    lines.push('<?php echo esc_js(__('Plan: reuse the original Woo order.', 'pc-folio-order-link')); ?>');
                    lines.push('- <?php echo esc_js(__('Save Folio document link on the original order:', 'pc-folio-order-link')); ?> ' + docLabel(realDocs[0]));
                    lines.push('- <?php echo esc_js(__('Set original Woo order status to processing.', 'pc-folio-order-link')); ?>');
                    return lines;
                }

                lines.push('<?php echo esc_js(__('Plan: keep the original Woo order as parent/draft.', 'pc-folio-order-link')); ?>');

                realDocs.forEach(function(doc, index) {
                    lines.push('- <?php echo esc_js(__('Create child Woo order for real Folio account:', 'pc-folio-order-link')); ?> ' + docLabel(doc));
                    lines.push('  <?php echo esc_js(__('Child status:', 'pc-folio-order-link')); ?> processing');
                });

                missingDocs.forEach(function(doc) {
                    lines.push('- <?php echo esc_js(__('Create missing-stock draft/on-hold Woo order:', 'pc-folio-order-link')); ?> ' + docLabel(doc));
                    lines.push('  <?php echo esc_js(__('Reason:', 'pc-folio-order-link')); ?> missing_stock_account');
                });

                if (data.warnings && data.warnings.length) {
                    lines.push('');
                    lines.push('<?php echo esc_js(__('Warnings:', 'pc-folio-order-link')); ?>');
                    data.warnings.forEach(function(warning) {
                        lines.push('- ' + warningLabel(warning));
                    });
                }

                return lines;
            }

            function sendPayloadToJava(previewOnly) {
                var parsed;
                rawResponse.style.display = 'block';
                rawResponse.textContent = previewOnly
                    ? '<?php echo esc_js(__('Sending preview to Java...', 'pc-folio-order-link')); ?>'
                    : '<?php echo esc_js(__('Creating Folio account(s)...', 'pc-folio-order-link')); ?>';
                output.style.display = 'block';
                output.textContent = '';

                try {
                    parsed = JSON.parse(previewJson.value || '{}');
                } catch (err) {
                    rawResponse.textContent = '<?php echo esc_js(__('Invalid preview JSON:', 'pc-folio-order-link')); ?> ' + err.message;
                    return;
                }

                parsed.preview_only = !!previewOnly;

                if (!previewOnly && !window.confirm('<?php echo esc_js(__('Create real Folio account documents now? Woo will only save the Java response; child orders will not be created yet.', 'pc-folio-order-link')); ?>')) {
                    rawResponse.textContent = '<?php echo esc_js(__('Creation cancelled.', 'pc-folio-order-link')); ?>';
                    return;
                }

                if (sendButton) sendButton.disabled = true;
                if (createButton) createButton.disabled = true;
                if (sendSpinner) {
                    sendSpinner.classList.add('is-active');
                }

                var body = new URLSearchParams();
                body.set('action', previewOnly ? 'pc_folio_order_preview_java' : 'pc_folio_order_create_java');
                body.set('_ajax_nonce', previewOnly ? '<?php echo esc_js(wp_create_nonce('pc_folio_order_preview_java')); ?>' : '<?php echo esc_js(wp_create_nonce('pc_folio_order_create_java')); ?>');
                body.set('payload', JSON.stringify(parsed));

                fetch(ajaxurl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                    body: body.toString()
                })
                .then(function(resp){ return resp.json(); })
                .then(function(resp){
                    var data = resp && resp.success ? resp.data : (resp ? resp.data : null);
                    if (!resp || !resp.success) {
                        throw new Error(ajaxErrorText(data, previewOnly ? '<?php echo esc_js(__('Java preview request failed.', 'pc-folio-order-link')); ?>' : '<?php echo esc_js(__('Java create request failed.', 'pc-folio-order-link')); ?>'));
                    }

                    rawResponse.textContent = data.raw || JSON.stringify(data.response, null, 2);
                    input.value = JSON.stringify(data.response || {}, null, 2);
                    output.textContent = simulate(data.response || {}).join("\n");

                    if (!previewOnly && data.saved) {
                        output.textContent += "\n\n" + '<?php echo esc_js(__('Java response saved to Woo order meta.', 'pc-folio-order-link')); ?>';
                    }
                })
                .catch(function(err){
                    rawResponse.textContent = err.message || String(err);
                })
                .finally(function(){
                    if (sendButton) sendButton.disabled = false;
                    if (createButton) createButton.disabled = false;
                    if (sendSpinner) {
                        sendSpinner.classList.remove('is-active');
                    }
                });
            }

            button.addEventListener('click', function(){
                var parsed;
                output.style.display = 'block';
                try {
                    parsed = JSON.parse(input.value || '{}');
                } catch (err) {
                    output.textContent = '<?php echo esc_js(__('Invalid JSON:', 'pc-folio-order-link')); ?> ' + err.message;
                    return;
                }

                output.textContent = simulate(parsed).join("\n");
            });

            if (sendButton && previewJson && rawResponse) {
                sendButton.addEventListener('click', function(){
                    sendPayloadToJava(true);
                });
            }

            if (savedButton && rawResponse) {
                savedButton.addEventListener('click', function(){
                    rawResponse.style.display = 'block';
                    rawResponse.textContent = '<?php echo esc_js(__('Loading saved Folio response...', 'pc-folio-order-link')); ?>';
                    output.style.display = 'block';
                    output.textContent = '';
                    savedButton.disabled = true;

                    var body = new URLSearchParams();
                    body.set('action', 'pc_folio_order_saved_response_plan');
                    body.set('_ajax_nonce', '<?php echo esc_js(wp_create_nonce('pc_folio_order_saved_response_plan')); ?>');
                    body.set('order_id', String(orderId));

                    fetch(ajaxurl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                        body: body.toString()
                    })
                    .then(function(resp){ return resp.json(); })
                    .then(function(resp){
                        var data = resp && resp.success ? resp.data : (resp ? resp.data : null);
                        if (!resp || !resp.success) {
                            throw new Error(ajaxErrorText(data, '<?php echo esc_js(__('Saved Folio response could not be loaded.', 'pc-folio-order-link')); ?>'));
                        }

                        rawResponse.textContent = data.raw || JSON.stringify(data.response, null, 2);
                        input.value = JSON.stringify(data.response || {}, null, 2);
                        output.textContent = simulate(data.response || {}).join("\n");
                    })
                    .catch(function(err){
                        rawResponse.textContent = err.message || String(err);
                    })
                    .finally(function(){
                        savedButton.disabled = false;
                    });
                });
            }

            if (applySavedButton && rawResponse) {
                applySavedButton.addEventListener('click', function(){
                    if (!window.confirm('<?php echo esc_js(__('Apply the saved Folio response to Woo now? This will only save links/status markers; child orders will not be created yet.', 'pc-folio-order-link')); ?>')) {
                        return;
                    }

                    rawResponse.style.display = 'block';
                    rawResponse.textContent = '<?php echo esc_js(__('Applying saved Folio response...', 'pc-folio-order-link')); ?>';
                    output.style.display = 'block';
                    output.textContent = '';
                    applySavedButton.disabled = true;
                    if (savedButton) savedButton.disabled = true;

                    var body = new URLSearchParams();
                    body.set('action', 'pc_folio_order_apply_saved_response');
                    body.set('_ajax_nonce', '<?php echo esc_js(wp_create_nonce('pc_folio_order_apply_saved_response')); ?>');
                    body.set('order_id', String(orderId));

                    fetch(ajaxurl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                        body: body.toString()
                    })
                    .then(function(resp){ return resp.json(); })
                    .then(function(resp){
                        var data = resp && resp.success ? resp.data : (resp ? resp.data : null);
                        if (!resp || !resp.success) {
                            throw new Error(ajaxErrorText(data, '<?php echo esc_js(__('Saved Folio response could not be applied.', 'pc-folio-order-link')); ?>'));
                        }

                        rawResponse.textContent = data.raw || JSON.stringify(data.result, null, 2);
                        output.textContent = data.message || '<?php echo esc_js(__('Saved Folio response applied.', 'pc-folio-order-link')); ?>';
                    })
                    .catch(function(err){
                        rawResponse.textContent = err.message || String(err);
                    })
                    .finally(function(){
                        applySavedButton.disabled = false;
                        if (savedButton) savedButton.disabled = false;
                    });
                });
            }

            if (createChildrenButton && rawResponse) {
                createChildrenButton.addEventListener('click', function(){
                    if (!window.confirm('<?php echo esc_js(__('Create Woo child orders from the saved Folio response now? The parent order will be moved to draft.', 'pc-folio-order-link')); ?>')) {
                        return;
                    }

                    rawResponse.style.display = 'block';
                    rawResponse.textContent = '<?php echo esc_js(__('Creating Woo child orders...', 'pc-folio-order-link')); ?>';
                    output.style.display = 'block';
                    output.textContent = '';
                    createChildrenButton.disabled = true;

                    var body = new URLSearchParams();
                    body.set('action', 'pc_folio_order_create_child_orders');
                    body.set('_ajax_nonce', '<?php echo esc_js(wp_create_nonce('pc_folio_order_create_child_orders')); ?>');
                    body.set('order_id', String(orderId));

                    fetch(ajaxurl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                        body: body.toString()
                    })
                    .then(function(resp){ return resp.json(); })
                    .then(function(resp){
                        var data = resp && resp.success ? resp.data : (resp ? resp.data : null);
                        if (!resp || !resp.success) {
                            throw new Error(ajaxErrorText(data, '<?php echo esc_js(__('Woo child orders could not be created.', 'pc-folio-order-link')); ?>'));
                        }

                        rawResponse.textContent = data.raw || JSON.stringify(data.result, null, 2);
                        output.textContent = data.message || '<?php echo esc_js(__('Woo child orders created.', 'pc-folio-order-link')); ?>';
                        output.textContent += "\n" + '<?php echo esc_js(__('Reloading order page to show linked child orders...', 'pc-folio-order-link')); ?>';
                        window.setTimeout(function(){
                            window.location.reload();
                        }, 900);
                    })
                    .catch(function(err){
                        rawResponse.textContent = err.message || String(err);
                    });
                });
            }

            if (createButton && previewJson && rawResponse) {
                createButton.addEventListener('click', function(){
                    sendPayloadToJava(false);
                });
            }
        })();
        </script>
        <?php
    }
}

if (!function_exists('pc_folio_order_preview_java_ajax')) {
    /**
     * Send the current Woo order preview payload to Java in preview-only mode.
     */
    function pc_folio_order_preview_java_ajax(): void
    {
        if (!pc_folio_order_link_can_manage()) {
            wp_send_json_error(['message' => __('Forbidden.', 'pc-folio-order-link')], 403);
        }

        check_ajax_referer('pc_folio_order_preview_java');

        $raw_payload = isset($_POST['payload']) ? (string) wp_unslash($_POST['payload']) : '';
        $payload = json_decode($raw_payload, true);
        if (!is_array($payload)) {
            wp_send_json_error(['message' => __('Invalid preview JSON.', 'pc-folio-order-link')], 400);
        }

        $payload['preview_only'] = true;
        $order_id = (int) ($payload['woo_order']['id'] ?? 0);
        if ($order_id > 0 && !current_user_can('edit_shop_order', $order_id)) {
            wp_send_json_error(['message' => __('Forbidden.', 'pc-folio-order-link')], 403);
        }

        $resp = pc_folio_order_link_java_post('/admin/folio/order-accounts', $payload, [
            'timeout' => 120,
        ]);
        if (is_wp_error($resp)) {
            wp_send_json_error(['message' => $resp->get_error_message()], 500);
        }

        $code = (int) wp_remote_retrieve_response_code($resp);
        $raw = (string) wp_remote_retrieve_body($resp);
        $data = json_decode($raw, true);

        if ($code < 200 || $code >= 300) {
            wp_send_json_error([
                'message' => sprintf('Java preview HTTP %d', $code),
                'raw'     => $raw,
            ], $code);
        }

        if (!is_array($data)) {
            wp_send_json_error([
                'message' => __('Invalid Java preview response.', 'pc-folio-order-link'),
                'raw'     => $raw,
            ], 500);
        }

        wp_send_json_success([
            'response' => $data,
            'raw'      => wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }
}
add_action('wp_ajax_pc_folio_order_preview_java', 'pc_folio_order_preview_java_ajax');

if (!function_exists('pc_folio_order_create_java_ajax')) {
    /**
     * Create Folio account documents in Java and save the response on the Woo order.
     */
    function pc_folio_order_create_java_ajax(): void
    {
        if (!pc_folio_order_link_can_manage()) {
            wp_send_json_error(['message' => __('Forbidden.', 'pc-folio-order-link')], 403);
        }

        check_ajax_referer('pc_folio_order_create_java');

        $raw_payload = isset($_POST['payload']) ? (string) wp_unslash($_POST['payload']) : '';
        $payload = json_decode($raw_payload, true);
        if (!is_array($payload)) {
            wp_send_json_error(['message' => __('Invalid preview JSON.', 'pc-folio-order-link')], 400);
        }

        $order_id = (int) ($payload['woo_order']['id'] ?? 0);
        $order = $order_id > 0 ? wc_get_order($order_id) : false;
        if (!$order) {
            wp_send_json_error(['message' => __('Order not found.', 'pc-folio-order-link')], 404);
        }

        if (!current_user_can('edit_shop_order', $order_id)) {
            wp_send_json_error(['message' => __('Forbidden.', 'pc-folio-order-link')], 403);
        }

        if (pc_folio_order_has_saved_documents($order)) {
            wp_send_json_error(['message' => __('Folio documents are already saved for this order.', 'pc-folio-order-link')], 409);
        }

        $result = pc_folio_create_documents_for_order($order, 'admin');
        if (empty($result['ok'])) {
            wp_send_json_error([
                'message'  => $result['message'] ?? __('Java response is not OK.', 'pc-folio-order-link'),
                'response' => $result['response'] ?? null,
                'raw'      => $result['raw'] ?? '',
            ], 500);
        }

        $data = is_array($result['response'] ?? null) ? $result['response'] : [];

        wp_send_json_success([
            'response' => $data,
            'raw'      => (string) ($result['raw'] ?? wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'saved'    => true,
        ]);
    }
}
add_action('wp_ajax_pc_folio_order_create_java', 'pc_folio_order_create_java_ajax');

if (!function_exists('pc_folio_order_saved_response_plan_ajax')) {
    /**
     * Load the saved Java/Folio response so the admin UI can preview Woo actions.
     */
    function pc_folio_order_saved_response_plan_ajax(): void
    {
        if (!pc_folio_order_link_can_manage()) {
            wp_send_json_error(['message' => __('Forbidden.', 'pc-folio-order-link')], 403);
        }

        check_ajax_referer('pc_folio_order_saved_response_plan');

        $order_id = isset($_POST['order_id']) ? absint(wp_unslash($_POST['order_id'])) : 0;
        $order = $order_id > 0 ? wc_get_order($order_id) : false;
        if (!$order) {
            wp_send_json_error(['message' => __('Order not found.', 'pc-folio-order-link')], 404);
        }

        if (!current_user_can('edit_shop_order', $order_id)) {
            wp_send_json_error(['message' => __('Forbidden.', 'pc-folio-order-link')], 403);
        }

        $result = pc_folio_get_order_documents_result($order);
        if (empty($result)) {
            wp_send_json_error(['message' => __('No saved Folio response found for this order.', 'pc-folio-order-link')], 404);
        }

        wp_send_json_success([
            'response' => $result,
            'raw'      => wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }
}
add_action('wp_ajax_pc_folio_order_saved_response_plan', 'pc_folio_order_saved_response_plan_ajax');

if (!function_exists('pc_folio_order_apply_saved_response_ajax')) {
    /**
     * Apply the saved Java/Folio response without creating child Woo orders yet.
     */
    function pc_folio_order_apply_saved_response_ajax(): void
    {
        if (!pc_folio_order_link_can_manage()) {
            wp_send_json_error(['message' => __('Forbidden.', 'pc-folio-order-link')], 403);
        }

        check_ajax_referer('pc_folio_order_apply_saved_response');

        $order_id = isset($_POST['order_id']) ? absint(wp_unslash($_POST['order_id'])) : 0;
        $order = $order_id > 0 ? wc_get_order($order_id) : false;
        if (!$order) {
            wp_send_json_error(['message' => __('Order not found.', 'pc-folio-order-link')], 404);
        }

        if (!current_user_can('edit_shop_order', $order_id)) {
            wp_send_json_error(['message' => __('Forbidden.', 'pc-folio-order-link')], 403);
        }

        $result = pc_folio_apply_saved_response_to_order($order);
        if (empty($result['ok'])) {
            wp_send_json_error(['message' => $result['message'] ?? __('Saved Folio response could not be applied.', 'pc-folio-order-link')], 400);
        }

        wp_send_json_success([
            'result'  => $result,
            'message' => $result['message'] ?? __('Saved Folio response applied.', 'pc-folio-order-link'),
            'raw'     => wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }
}
add_action('wp_ajax_pc_folio_order_apply_saved_response', 'pc_folio_order_apply_saved_response_ajax');

if (!function_exists('pc_folio_order_create_child_orders_ajax')) {
    /**
     * Create Woo child orders from the saved Folio response.
     */
    function pc_folio_order_create_child_orders_ajax(): void
    {
        if (!pc_folio_order_link_can_manage()) {
            wp_send_json_error(['message' => __('Forbidden.', 'pc-folio-order-link')], 403);
        }

        check_ajax_referer('pc_folio_order_create_child_orders');

        $order_id = isset($_POST['order_id']) ? absint(wp_unslash($_POST['order_id'])) : 0;
        $order = $order_id > 0 ? wc_get_order($order_id) : false;
        if (!$order) {
            wp_send_json_error(['message' => __('Order not found.', 'pc-folio-order-link')], 404);
        }

        if (!current_user_can('edit_shop_order', $order_id)) {
            wp_send_json_error(['message' => __('Forbidden.', 'pc-folio-order-link')], 403);
        }

        try {
            $result = pc_folio_create_child_orders_from_saved_response($order);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()], 500);
        }

        if (empty($result['ok'])) {
            wp_send_json_error(['message' => $result['message'] ?? __('Woo child orders could not be created.', 'pc-folio-order-link')], 400);
        }

        wp_send_json_success([
            'result'  => $result,
            'message' => $result['message'] ?? __('Woo child orders created.', 'pc-folio-order-link'),
            'raw'     => wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }
}
add_action('wp_ajax_pc_folio_order_create_child_orders', 'pc_folio_order_create_child_orders_ajax');

add_action('woocommerce_process_shop_order_meta', function ($order_id) {
    if (!isset($_POST['pc_folio_order_link_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['pc_folio_order_link_nonce'])), 'pc_folio_order_link_save')) {
        return;
    }

    if (!current_user_can('edit_shop_order', $order_id)) {
        return;
    }

    $raw = isset($_POST['pc_folio_order_link']) && is_array($_POST['pc_folio_order_link'])
        ? wp_unslash($_POST['pc_folio_order_link'])
        : [];

    $data = [];
    foreach (array_keys(pc_folio_order_link_meta_keys()) as $field) {
        $data[$field] = isset($raw[$field]) && is_scalar($raw[$field])
            ? sanitize_text_field((string) $raw[$field])
            : '';
    }

    pc_folio_set_order_document_link((int) $order_id, $data);
}, 10, 1);
