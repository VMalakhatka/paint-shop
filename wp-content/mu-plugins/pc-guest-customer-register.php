<?php
/*
Plugin Name: PC Guest Customer Register
Description: Converts WooCommerce guest checkout orders into customer users and assigns the default Folio internet client mapping.
Author: PaintCore
Version: 1.1.0
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

if (!function_exists('pc_guest_customer_send_new_account_email')) {
    /**
     * Send WooCommerce's standard new account email with a set-password link.
     */
    function pc_guest_customer_send_new_account_email(int $user_id): bool
    {
        if ($user_id <= 0 || !function_exists('WC')) {
            return false;
        }

        $mailer = WC()->mailer();
        $emails = $mailer ? $mailer->get_emails() : [];
        if (empty($emails['WC_Email_Customer_New_Account'])) {
            return false;
        }

        $email = $emails['WC_Email_Customer_New_Account'];
        if (!is_object($email) || !method_exists($email, 'trigger')) {
            return false;
        }

        // trigger() has no return value. Observe the mail transport result instead.
        $sent = false;
        $observe = static function ($success, $id, $instance) use (&$sent, $email): void {
            if ($id === 'customer_new_account' && $instance === $email) $sent = (bool) $success;
        };
        add_action('woocommerce_email_sent', $observe, 10, 3);
        try {
            $email->trigger($user_id, '', true);
        } finally {
            remove_action('woocommerce_email_sent', $observe, 10);
        }
        return $sent;
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
     *
     * @param int|\WC_Order $order_or_id Woo order object or ID.
     */
    function pc_guest_customer_attach_order($order_or_id): void
    {
        if (!function_exists('wc_get_order')) {
            return;
        }

        $order = ($order_or_id instanceof \WC_Order) ? $order_or_id : wc_get_order((int) $order_or_id);
        if (!$order || (int) $order->get_customer_id() > 0) {
            return;
        }

        $email = sanitize_email($order->get_billing_email());
        $user_id = is_email($email) ? (int) email_exists($email) : 0;

        $created_user = false;
        if (!$user_id) {
            $user_id = pc_guest_customer_create_from_order($order);
            $created_user = $user_id > 0;
            // Another checkout may have registered this email in the meantime.
            if (!$user_id && is_email($email)) $user_id = (int) email_exists($email);
        }

        if (!$user_id) {
            $order->update_meta_data('_pc_guest_customer_register_status', 'skipped');
            $order->update_meta_data('_pc_guest_customer_register_reason', is_email($email) ? 'creation_failed' : 'missing_valid_email');
            $order->save();
            return;
        }

        // An email match associates this order only. It proves neither identity
        // nor permission to change an existing profile, role, mapping or session.
        if ($created_user) {
            pc_guest_customer_assign_default_folio_client($user_id);
            pc_guest_customer_sync_user_meta_from_order($user_id, $order);
        }

        $order->set_customer_id($user_id);
        $order->update_meta_data('_pc_guest_customer_register_status', 'attached');
        $order->update_meta_data('_pc_guest_customer_user_id', $user_id);
        $order->update_meta_data('_pc_guest_customer_account_created', $created_user ? 'yes' : 'no');
        // Persist the association before invoking the mailer; a mail failure must
        // never turn an already-created account into a duplicate checkout attempt.
        $order->save();
        if ($created_user) {
            try {
                $sent = pc_guest_customer_send_new_account_email($user_id);
            } catch (\Throwable $error) {
                $sent = false;
            }
            $order->update_meta_data('_pc_guest_customer_account_email_sent', $sent ? 'yes' : 'no');
            $order->add_order_note($sent
                ? 'Customer account created automatically; password setup email accepted by mail transport.'
                : 'Customer account created automatically; password setup email could not be sent.');
            $order->save();
        }
    }
}
add_action('woocommerce_checkout_order_processed', 'pc_guest_customer_attach_order', 20, 1);
add_action('woocommerce_store_api_checkout_order_processed', 'pc_guest_customer_attach_order', 20, 1);

/** Only the order owner or holder of its secret checkout key sees the result. */
add_action('woocommerce_thankyou', static function ($order_id): void {
    $order = wc_get_order($order_id);
    if (!$order || $order->get_meta('_pc_guest_customer_register_status') !== 'attached') return;
    $key = isset($_GET['key']) && is_string($_GET['key']) ? wc_clean(wp_unslash($_GET['key'])) : '';
    $owns = is_user_logged_in() && get_current_user_id() === (int) $order->get_customer_id();
    if (!$owns && ($key === '' || !hash_equals($order->get_order_key(), $key))) return;
    $created = $order->get_meta('_pc_guest_customer_account_created') === 'yes';
    echo '<div class="woocommerce-info pc-account-result" role="status">';
    echo esc_html($created
        ? __('Your account has been created using the email entered at checkout.', 'pc-guest-customer-register')
        : __('This order is linked to the account for the email entered at checkout. Sign in to view your orders.', 'pc-guest-customer-register'));
    if ($created) {
        echo ' ' . esc_html($order->get_meta('_pc_guest_customer_account_email_sent') === 'yes'
            ? __('Check your email for the password setup link, including your spam folder.', 'pc-guest-customer-register')
            : __('The password setup email could not be sent. Use password recovery to access your account.', 'pc-guest-customer-register'));
    }
    echo ' <a href="' . esc_url(wc_get_page_permalink('myaccount')) . '">' . esc_html__('My account', 'pc-guest-customer-register') . '</a>';
    echo ' · <a href="' . esc_url(wc_lostpassword_url()) . '">' . esc_html__('Set or recover password', 'pc-guest-customer-register') . '</a></div>';
}, 6);

if (defined('WP_CLI') && WP_CLI && !class_exists('PC_Guest_Customer_Register_CLI')) {
    /**
     * WP-CLI helper for manually attaching an existing guest order.
     */
    class PC_Guest_Customer_Register_CLI {
        /**
         * Attach/create a customer for an existing guest order.
         *
         * ## OPTIONS
         *
         * <order_id>
         * : Woo order ID.
         */
        public function attach($args): void
        {
            $order_id = isset($args[0]) ? absint($args[0]) : 0;
            if (!$order_id) {
                WP_CLI::error('Order ID is required.');
            }

            pc_guest_customer_attach_order($order_id);
            $order = wc_get_order($order_id);
            $user_id = $order ? (int) $order->get_customer_id() : 0;

            if ($user_id > 0) {
                WP_CLI::success(sprintf('Order %d attached to user %d.', $order_id, $user_id));
                return;
            }

            WP_CLI::warning(sprintf('Order %d was not attached.', $order_id));
        }
    }

    WP_CLI::add_command('pc guest-customer', 'PC_Guest_Customer_Register_CLI');
}
