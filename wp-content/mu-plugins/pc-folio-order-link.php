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
