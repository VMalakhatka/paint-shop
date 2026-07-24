<?php
/*
Plugin Name: PC Guest Customer Register
Description: Converts WooCommerce guest checkout orders into customer users and assigns the default Folio internet client mapping.
Author: PaintCore
Version: 1.0.0
Text Domain: pc-guest-customer-register
Domain Path: /languages
*/

if (!defined('ABSPATH')) {
    exit;
}

const PC_GUEST_CUSTOMER_ROLE = 'customer';
const PC_GUEST_CUSTOMER_FOLIO_ID = 'ИНЕТ КЛ';
const PC_GUEST_CUSTOMER_FOLIO_NAME = 'Интернет клиент';
const PC_GUEST_CUSTOMER_FOLIO_TYPE = 'К';

if (!function_exists('pc_guest_customer_assign_default_folio_client')) {
    /**
     * Assign the default Folio internet client mapping if the user has no mapping yet.
     */
    function pc_guest_customer_assign_default_folio_client(int $user_id): void
    {
        if ($user_id <= 0 || (string) get_user_meta($user_id, '_folio_partner_id', true) !== '') {
            return;
        }

        update_user_meta($user_id, '_folio_partner_id', PC_GUEST_CUSTOMER_FOLIO_ID);
        update_user_meta($user_id, '_folio_partner_short_name', PC_GUEST_CUSTOMER_FOLIO_ID);
        update_user_meta($user_id, '_folio_partner_name', PC_GUEST_CUSTOMER_FOLIO_NAME);
        update_user_meta($user_id, '_folio_partner_type', PC_GUEST_CUSTOMER_FOLIO_TYPE);
    }
}

if (!function_exists('pc_guest_customer_assign_default_folio_client_on_register')) {
    /**
     * Assign the default Folio mapping to newly registered Woo customers only.
     */
    function pc_guest_customer_assign_default_folio_client_on_register(int $user_id): void
    {
        $user = get_user_by('id', $user_id);
        if (!($user instanceof \WP_User) || !in_array('customer', (array) $user->roles, true)) {
            return;
        }

        pc_guest_customer_assign_default_folio_client($user_id);
    }
}
add_action('user_register', 'pc_guest_customer_assign_default_folio_client_on_register', 20, 1);

if (!function_exists('pc_guest_customer_normalize_phone')) {
    /**
     * Normalize a phone number for a best-effort lookup.
     */
    function pc_guest_customer_normalize_phone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?: '';
    }
}

if (!function_exists('pc_guest_customer_username_from_order')) {
    /**
     * Build a stable unique username from billing email or phone.
     */
    function pc_guest_customer_username_from_order(\WC_Order $order): string
    {
        $email = sanitize_email($order->get_billing_email());
        $base = $email !== ''
            ? sanitize_user(substr($email, 0, (int) strpos($email, '@')), true)
            : 'client-' . pc_guest_customer_normalize_phone((string) $order->get_billing_phone());

        if ($base === '' || $base === 'client-') {
            $base = 'client-order-' . (int) $order->get_id();
        }

        $username = $base;
        $i = 2;
        while (username_exists($username)) {
            $username = $base . '-' . $i;
            $i++;
        }

        return $username;
    }
}

if (!function_exists('pc_guest_customer_find_user_by_phone')) {
    /**
     * Find a user by billing phone meta after normalizing digits.
     */
    function pc_guest_customer_find_user_by_phone(string $phone): int
    {
        $normalized = pc_guest_customer_normalize_phone($phone);
        if ($normalized === '') {
            return 0;
        }

        $users = get_users([
            'number'     => 20,
            'fields'     => ['ID'],
            'meta_query' => [
                'relation' => 'OR',
                [
                    'key'     => 'billing_phone',
                    'value'   => $normalized,
                    'compare' => 'LIKE',
                ],
                [
                    'key'     => 'phone',
                    'value'   => $normalized,
                    'compare' => 'LIKE',
                ],
            ],
        ]);

        foreach ($users as $user) {
            $user_id = (int) $user->ID;
            $billing_phone = pc_guest_customer_normalize_phone((string) get_user_meta($user_id, 'billing_phone', true));
            $phone_meta = pc_guest_customer_normalize_phone((string) get_user_meta($user_id, 'phone', true));
            if ($billing_phone === $normalized || $phone_meta === $normalized) {
                return $user_id;
            }
        }

        return 0;
    }
}

if (!function_exists('pc_guest_customer_create_from_order')) {
    /**
     * Create a Woo customer user from order billing data.
     */
    function pc_guest_customer_create_from_order(\WC_Order $order): int
    {
        $email = sanitize_email($order->get_billing_email());
        if ($email === '' || !is_email($email)) {
            return 0;
        }

        $user_id = wp_insert_user([
            'user_login'   => pc_guest_customer_username_from_order($order),
            'user_pass'    => wp_generate_password(24, true),
            'user_email'   => $email,
            'first_name'   => sanitize_text_field($order->get_billing_first_name()),
            'last_name'    => sanitize_text_field($order->get_billing_last_name()),
            'display_name' => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()) ?: $email,
            'role'         => PC_GUEST_CUSTOMER_ROLE,
        ]);

        return is_wp_error($user_id) ? 0 : (int) $user_id;
    }
}

if (!function_exists('pc_guest_customer_sync_user_meta_from_order')) {
    /**
     * Copy billing and shipping fields from the order to the user profile.
     */
    function pc_guest_customer_sync_user_meta_from_order(int $user_id, \WC_Order $order): void
    {
        $fields = [
            'billing_first_name'  => $order->get_billing_first_name(),
            'billing_last_name'   => $order->get_billing_last_name(),
            'billing_company'     => $order->get_billing_company(),
            'billing_address_1'   => $order->get_billing_address_1(),
            'billing_address_2'   => $order->get_billing_address_2(),
            'billing_city'        => $order->get_billing_city(),
            'billing_state'       => $order->get_billing_state(),
            'billing_postcode'    => $order->get_billing_postcode(),
            'billing_country'     => $order->get_billing_country(),
            'billing_email'       => $order->get_billing_email(),
            'billing_phone'       => $order->get_billing_phone(),
            'shipping_first_name' => $order->get_shipping_first_name(),
            'shipping_last_name'  => $order->get_shipping_last_name(),
            'shipping_company'    => $order->get_shipping_company(),
            'shipping_address_1'  => $order->get_shipping_address_1(),
            'shipping_address_2'  => $order->get_shipping_address_2(),
            'shipping_city'       => $order->get_shipping_city(),
            'shipping_state'      => $order->get_shipping_state(),
            'shipping_postcode'   => $order->get_shipping_postcode(),
            'shipping_country'    => $order->get_shipping_country(),
        ];

        foreach ($fields as $key => $value) {
            $value = sanitize_text_field((string) $value);
            if ($value !== '') {
                update_user_meta($user_id, $key, $value);
            }
        }

        update_user_meta($user_id, '_pc_guest_customer_last_order_id', (int) $order->get_id());
        update_user_meta($user_id, '_pc_guest_customer_last_seen_at', current_time('mysql'));
    }
}

if (!function_exists('pc_guest_customer_attach_order')) {
    /**
     * Attach a guest checkout order to an existing or newly created Internet client.
     */
    function pc_guest_customer_attach_order(int $order_id): void
    {
        if (!function_exists('wc_get_order')) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order || (int) $order->get_customer_id() > 0) {
            return;
        }

        $email = sanitize_email($order->get_billing_email());
        $user_id = $email !== '' ? (int) email_exists($email) : 0;

        if (!$user_id) {
            $user_id = pc_guest_customer_find_user_by_phone((string) $order->get_billing_phone());
        }

        if (!$user_id) {
            $user_id = pc_guest_customer_create_from_order($order);
        }

        if (!$user_id) {
            $order->update_meta_data('_pc_guest_customer_register_status', 'skipped');
            $order->update_meta_data('_pc_guest_customer_register_reason', 'missing_valid_email');
            $order->save();
            return;
        }

        $user = get_user_by('id', $user_id);
        if ($user instanceof \WP_User && empty(array_intersect($user->roles, ['administrator', 'shop_manager', 'lavka_manager', 'partner', 'opt', 'opt_osn', 'schule']))) {
            $user->set_role(PC_GUEST_CUSTOMER_ROLE);
        }

        pc_guest_customer_assign_default_folio_client($user_id);
        pc_guest_customer_sync_user_meta_from_order($user_id, $order);

        $order->set_customer_id($user_id);
        $order->update_meta_data('_pc_guest_customer_register_status', 'attached');
        $order->update_meta_data('_pc_guest_customer_user_id', $user_id);
        $order->save();
    }
}
add_action('woocommerce_checkout_order_processed', 'pc_guest_customer_attach_order', 20, 1);
