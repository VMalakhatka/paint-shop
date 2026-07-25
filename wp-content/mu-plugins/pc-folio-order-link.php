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

        $order->add_order_note(__('Folio account response was saved. Child Woo orders were not created yet.', 'pc-folio-order-link'));
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
            'sourceInfo'          => $source_info !== '' ? $source_info : 'Интернет заказ сайт',
            'additionalInfo'      => sprintf('Woo order #%s, ordered at %s', $order->get_order_number(), $ordered_at),
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

        echo '<hr>';
        echo '<p><strong>' . esc_html__('Split status', 'pc-folio-order-link') . '</strong><br>';
        echo '<code>' . esc_html($split_status !== '' ? $split_status : __('not set', 'pc-folio-order-link')) . '</code></p>';

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
                        throw new Error(data && data.message ? data.message : (previewOnly ? '<?php echo esc_js(__('Java preview request failed.', 'pc-folio-order-link')); ?>' : '<?php echo esc_js(__('Java create request failed.', 'pc-folio-order-link')); ?>'));
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
                            throw new Error(data && data.message ? data.message : '<?php echo esc_js(__('Saved Folio response could not be loaded.', 'pc-folio-order-link')); ?>');
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
                            throw new Error(data && data.message ? data.message : '<?php echo esc_js(__('Saved Folio response could not be applied.', 'pc-folio-order-link')); ?>');
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

        $payload['preview_only'] = false;

        $resp = pc_folio_order_link_java_post('/admin/folio/order-accounts', $payload, [
            'timeout' => 180,
        ]);
        if (is_wp_error($resp)) {
            wp_send_json_error(['message' => $resp->get_error_message()], 500);
        }

        $code = (int) wp_remote_retrieve_response_code($resp);
        $raw = (string) wp_remote_retrieve_body($resp);
        $data = json_decode($raw, true);

        if ($code < 200 || $code >= 300) {
            wp_send_json_error([
                'message' => sprintf('Java create HTTP %d', $code),
                'raw'     => $raw,
            ], $code);
        }

        if (!is_array($data)) {
            wp_send_json_error([
                'message' => __('Invalid Java create response.', 'pc-folio-order-link'),
                'raw'     => $raw,
            ], 500);
        }

        if (empty($data['ok'])) {
            pc_folio_set_order_documents_result($order, $data);
            $order->add_order_note(__('Folio account creation failed. Java response was saved for review.', 'pc-folio-order-link'));
            wp_send_json_error([
                'message'  => __('Java response is not OK.', 'pc-folio-order-link'),
                'response' => $data,
                'raw'      => wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ], 500);
        }

        pc_folio_save_order_java_response($order, $data, $payload);

        wp_send_json_success([
            'response' => $data,
            'raw'      => wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
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
