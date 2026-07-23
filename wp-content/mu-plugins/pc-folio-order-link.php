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

        return [
            'preview_only'   => true,
            'schema_version' => 'folio-order-preview/v1',
            'source'         => 'woo_order',
            'intent'         => 'create_or_update_folio_documents',
            'split_strategy' => 'java_by_allocations_and_folio_warehouse_priority',
            'woo_order'      => [
                'id'       => (int) $order->get_id(),
                'number'   => (string) $order->get_order_number(),
                'status'   => (string) $order->get_status(),
                'currency' => (string) $order->get_currency(),
                'total'    => (float) $order->get_total(),
            ],
            'folio_client'   => pc_folio_get_order_customer_link($order),
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
        echo '<p class="description">' . esc_html__('Preview only. This JSON is not sent to Folio yet.', 'pc-folio-order-link') . '</p>';
        printf(
            '<textarea class="widefat code" rows="18" readonly>%s</textarea>',
            esc_textarea(wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
        );
    }
}

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
