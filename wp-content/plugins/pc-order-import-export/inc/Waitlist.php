<?php
namespace PaintCore\PCOE;

defined('ABSPATH') || exit;

/** Explicit demand and in-account availability notices. No Folio writes. Email is handled by WaitlistMail. */
final class Waitlist
{
    public const ENDPOINT = 'waiting-list';

    public static function hooks(): void {
        add_action('init', [self::class, 'endpoint']);
        WaitlistMail::hooks();
        add_action('template_redirect', static function () {
            if (is_account_page() && is_wc_endpoint_url(self::ENDPOINT)) nocache_headers();
        });
        add_filter('woocommerce_get_query_vars', static function ($vars) {
            $vars[self::ENDPOINT] = self::ENDPOINT;
            return $vars;
        });
        add_filter('woocommerce_account_menu_items', static function ($items) {
            if (self::allowed()) {
                $logout = $items['customer-logout'] ?? null;
                unset($items['customer-logout']);
                $items[self::ENDPOINT] = __('Waiting list', 'pc-order-import-export');
                if ($logout !== null) $items['customer-logout'] = $logout;
            }
            return $items;
        });
        add_action('woocommerce_account_' . self::ENDPOINT . '_endpoint', [self::class, 'render']);
        add_filter('woocommerce_endpoint_' . self::ENDPOINT . '_title', static fn() => __('Waiting list', 'pc-order-import-export'));
        add_action('woocommerce_account_dashboard', [self::class, 'dashboard_notice']);
        add_action('woocommerce_single_product_summary', [self::class, 'product_link'], 35);
        add_action('woocommerce_order_details_after_order_table', [self::class, 'draft_form'], 25);
        add_action('admin_post_pcoe_waitlist', [self::class, 'handle']);
        add_action('admin_post_pcoe_waitlist_setup', [self::class, 'setup']);
        add_action('deleted_user', static function ($user_id) {
            if ((int) get_option('pcoe_waitlist_schema', 0) !== 1) return;
            global $wpdb;
            $wpdb->delete(WaitlistStore::table(), ['user_id' => (int) $user_id], ['%d']);
        });
        add_action('admin_menu', static function () {
            add_submenu_page('woocommerce', __('Waiting list', 'pc-order-import-export'), __('Waiting list', 'pc-order-import-export'), 'manage_woocommerce', 'pcoe-waitlist', [self::class, 'settings']);
        });
        add_action('wp_enqueue_scripts', static function () {
            if (!self::allowed()) return;
            wp_enqueue_style('pcoe-waitlist', PCOE_URL . 'assets/waitlist.css', [], '2');
            wp_enqueue_script('pcoe-waitlist', PCOE_URL . 'assets/waitlist.js', [], '1', true);
        });
    }

    public static function enabled(): bool {
        return (int) get_option('pcoe_waitlist_schema', 0) === 1 && get_option('pcoe_waitlist_enabled', 'no') === 'yes';
    }

    public static function allowed(): bool {
        return is_user_logged_in() && self::customer_allowed(get_current_user_id());
    }

    /** Empty pilot configuration denies everyone, including direct POST requests. */
    public static function customer_allowed(int $user_id): bool {
        if (!self::enabled() || $user_id <= 0 || $user_id !== (int) get_option('pcoe_waitlist_pilot_user_id', 0)) return false;
        $user = get_user_by('id', $user_id);
        return $user instanceof \WP_User && function_exists('pc_wholesale_customer_can_access') && pc_wholesale_customer_can_access($user);
    }

    public static function endpoint(): void {
        if (self::enabled()) add_rewrite_endpoint(self::ENDPOINT, EP_ROOT | EP_PAGES);
    }

    public static function settings(): void {
        if (!current_user_can('manage_woocommerce')) return;
        echo '<div class="wrap"><h1>' . esc_html__('Waiting list', 'pc-order-import-export') . '</h1>';
        echo '<p>' . esc_html__('Customers can track products and drafts. Pilot email notifications require both the email service switch and customer consent.', 'pc-order-import-export') . '</p>';
        echo '<p>' . esc_html__('Enabling creates private waiting-list storage. Disabling preserves subscriptions and stops background emails. Folio is not changed.', 'pc-order-import-export') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('pcoe_waitlist_setup');
        echo '<input type="hidden" name="action" value="pcoe_waitlist_setup">';
        $pilot = get_user_by('id', (int) get_option('pcoe_waitlist_pilot_user_id', 0));
        echo '<p><label>' . esc_html__('Pilot customer email', 'pc-order-import-export') . ' <input type="email" name="pilot_email" value="' . esc_attr($pilot ? $pilot->user_email : '') . '"></label></p>';
        echo '<p>' . esc_html__('Only this wholesale customer can access the waiting list. An empty pilot selection allows nobody.', 'pc-order-import-export') . '</p>';
        echo '<label><input type="checkbox" name="enabled" value="1" ' . checked(self::enabled(), true, false) . '> ' . esc_html__('Enable waiting list', 'pc-order-import-export') . '</label>';
        echo '<p><label><input type="checkbox" name="mail_enabled" value="1" ' . checked(WaitlistMail::enabled(), true, false) . '> ' . esc_html__('Enable background email for the pilot customer', 'pc-order-import-export') . '</label></p>';
        submit_button();
        echo '</form>';
        WaitlistMail::settings();
        echo '</div>';
    }

    public static function setup(): void {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !current_user_can('manage_woocommerce')) wp_die('Forbidden', '', ['response' => 403]);
        check_admin_referer('pcoe_waitlist_setup');
        if (isset($_POST['enabled']) && $_POST['enabled'] === '1') {
            $email = isset($_POST['pilot_email']) && is_string($_POST['pilot_email']) ? sanitize_email(wp_unslash($_POST['pilot_email'])) : '';
            $pilot = $email !== '' ? get_user_by('email', $email) : false;
            if (!$pilot instanceof \WP_User || !function_exists('pc_wholesale_customer_can_access') || !pc_wholesale_customer_can_access($pilot)) {
                wp_die(esc_html__('Select an existing wholesale customer for the pilot.', 'pc-order-import-export'), '', ['response' => 400]);
            }
            try { WaitlistStore::install(); } catch (\Throwable $e) {
                wp_die(esc_html__('Waiting-list storage is unavailable. No messages were sent.', 'pc-order-import-export'));
            }
            update_option('pcoe_waitlist_pilot_user_id', (int) $pilot->ID, false);
            update_option('pcoe_waitlist_enabled', 'yes', false);
            self::endpoint();
        } else {
            update_option('pcoe_waitlist_enabled', 'no', false);
        }
        update_option('pcoe_waitlist_mail_enabled', isset($_POST['enabled'], $_POST['mail_enabled']) && $_POST['mail_enabled'] === '1' ? 'yes' : 'no', false);
        WaitlistMail::schedule();
        flush_rewrite_rules(false);
        wp_safe_redirect(admin_url('admin.php?page=pcoe-waitlist'));
        exit;
    }

    public static function consent_field(): void {
        if (!self::allowed()) return;
        echo '<label class="pcoe-track-consent"><input type="checkbox" name="track_waitlist" value="1"> ' . esc_html__('Track this draft and show availability notices in my account', 'pc-order-import-export') . '</label>';
    }

    public static function product_link(): void {
        global $product;
        if (!self::allowed() || !$product instanceof \WC_Product) return;
        $url = wc_get_account_endpoint_url(self::ENDPOINT);
        if ($product->is_type('simple')) $url = add_query_arg('sku', $product->get_sku(), $url);
        echo '<p><a class="button" href="' . esc_url($url) . '">' . esc_html__('Add to waiting list', 'pc-order-import-export') . '</a></p>';
    }

    public static function dashboard_notice(): void {
        if (!self::allowed()) return;
        try {
            $state = WaitlistStore::read(get_current_user_id());
            $groups = WaitlistModel::group(self::effective_entries($state['entries']), time());
            $locations = PriceList::location_ids();
            foreach ($groups as $id => $group) {
                $product = wc_get_product($id);
                if (!$product instanceof \WC_Product || !PriceList::eligible($product) || !$product->is_purchasable() || $group['quantity'] <= self::cart_quantity($id)) continue;
                $stock = PriceList::stock($product, $locations);
                if ($stock !== null && $stock > 0) {
                    echo '<p class="woocommerce-info"><a href="' . esc_url(wc_get_account_endpoint_url(self::ENDPOINT)) . '">' . esc_html__('Some products in your waiting list are available. Review your requests.', 'pc-order-import-export') . '</a></p>';
                    break;
                }
            }
        } catch (\Throwable $e) {
            // No notice is preferable to a false availability assertion.
        }
    }

    private static function form_start(string $operation, int $revision): void {
        echo '<form class="pcoe-waitlist-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" data-pending="' . esc_attr__('Saving…', 'pc-order-import-export') . '">';
        wp_nonce_field('pcoe_waitlist');
        echo '<input type="hidden" name="action" value="pcoe_waitlist"><input type="hidden" name="operation" value="' . esc_attr($operation) . '"><input type="hidden" name="revision" value="' . esc_attr((string) $revision) . '">';
    }

    public static function draft_form(\WC_Order $order): void {
        if (!self::allowed() || !$order->has_status('pc-draft') || (int) $order->get_customer_id() !== get_current_user_id()) return;
        try { $state = WaitlistStore::read(get_current_user_id()); } catch (\Throwable $e) { return; }
        $tracked = (bool) array_filter($state['entries'], static fn($entry) => (int) ($entry['draft_id'] ?? 0) === (int) $order->get_id());
        echo '<section class="pcoe-waitlist"><h2>' . esc_html__('Draft tracking', 'pc-order-import-export') . '</h2>';
        self::form_start('track_draft', $state['revision']);
        echo '<input type="hidden" name="draft_id" value="' . esc_attr((string) $order->get_id()) . '">';
        echo '<label><input type="checkbox" name="track_waitlist" value="1" ' . checked($tracked, true, false) . '> ' . esc_html__('Track this draft and show availability notices in my account', 'pc-order-import-export') . '</label>';
        echo '<button class="button" type="submit">' . esc_html__('Save tracking preference', 'pc-order-import-export') . '</button></form></section>';
    }

    private static function subscribe_draft(array $state, \WC_Order $order, bool $track): array {
        $previous = $state['entries'];
        foreach ($state['entries'] as $key => $entry) {
            if ((int) ($entry['draft_id'] ?? 0) === (int) $order->get_id()) unset($state['entries'][$key]);
        }
        if ($track) foreach ($order->get_items('line_item') as $item_id => $item) {
            $product = $item->get_product();
            if (!$product instanceof \WC_Product || !PriceList::eligible($product)) continue;
            $qty = WaitlistModel::quantity((string) $item->get_quantity());
            if (!$qty) throw new \RuntimeException(__('Tracking currently supports whole quantities from 1 to 100000.', 'pc-order-import-export'));
            $key = 'draft:' . $order->get_id() . ':' . $item_id;
            $state['entries'][$key] = [
                'product_id' => $product->get_id(), 'quantity' => $qty, 'requested' => $qty,
                'created_at' => time(), 'status' => 'active', 'snooze_until' => 0,
                'draft_id' => $order->get_id(), 'item_id' => $item_id, 'reason' => 'explicit_draft',
            ];
            if (isset($previous[$key])) {
                foreach (['created_at', 'requested', 'intent'] as $field) {
                    if (isset($previous[$key][$field])) $state['entries'][$key][$field] = $previous[$key][$field];
                }
            }
        }
        if (count($state['entries']) > WaitlistModel::LIMIT) throw new \RuntimeException(__('The waiting list is full. Remove old entries first (limit: 200 sources).', 'pc-order-import-export'));
        return $state;
    }

    /** Called only after successful draft creation; never infer consent for a manager's customer. */
    public static function track_created(\WC_Order $order): void {
        if (!self::allowed() || (int) $order->get_customer_id() !== get_current_user_id() || ($_POST['track_waitlist'] ?? '') !== '1') return;
        try {
            $state = WaitlistStore::read(get_current_user_id());
            if (!empty($state['pending'])) throw new \RuntimeException();
            $state = self::subscribe_draft($state, $order, true);
            if (!WaitlistStore::save(get_current_user_id(), $state)) throw new \RuntimeException();
            $order->update_meta_data('_pcoe_track_waitlist', 'yes');
            $order->update_meta_data('_pcoe_track_consent_at', gmdate('c'));
            $order->save();
        } catch (\Throwable $e) {
            wc_add_notice(__('The draft was saved, but tracking was not enabled. Open the draft to enable it.', 'pc-order-import-export'), 'notice');
        }
    }

    /** Current draft remainder is authoritative; rendering never changes stored intent. */
    public static function effective_entries(array $entries, ?int $owner = null): array {
        $owner = $owner ?? get_current_user_id();
        $orders = [];
        foreach ($entries as &$entry) {
            if (empty($entry['draft_id'])) continue;
            $id = (int) $entry['draft_id'];
            if (!array_key_exists($id, $orders)) $orders[$id] = wc_get_order($id);
            $order = $orders[$id];
            $entry['quantity'] = 0;
            if (!$order instanceof \WC_Order || !$order->has_status('pc-draft') || (int) $order->get_customer_id() !== $owner) continue;
            $item = $order->get_item((int) $entry['item_id']);
            if ($item instanceof \WC_Order_Item_Product && (int) ($item->get_variation_id() ?: $item->get_product_id()) === (int) $entry['product_id']) {
                $entry['quantity'] = max(0, (int) $item->get_quantity());
            }
        }
        unset($entry);
        return $entries;
    }

    private static function cart_quantity(int $product_id): float {
        $quantity = 0.0;
        if (WC()->cart) foreach (WC()->cart->get_cart() as $item) {
            if ((int) ($item['variation_id'] ?: $item['product_id']) === $product_id) $quantity += (float) $item['quantity'];
        }
        return $quantity;
    }

    public static function render(): void {
        if (!self::allowed()) { echo '<p>' . esc_html__('Waiting list is not available for this account.', 'pc-order-import-export') . '</p>'; return; }
        try { $state = WaitlistStore::read(get_current_user_id()); } catch (\Throwable $e) {
            echo '<p role="alert">' . esc_html__('Waiting-list storage is unavailable. No messages were sent.', 'pc-order-import-export') . '</p>'; return;
        }
        echo '<section class="pcoe-waitlist"><h2>' . esc_html__('Waiting list', 'pc-order-import-export') . '</h2>';
        echo '<p>' . esc_html__('Availability is shared by all customers. This list does not reserve goods. Enable email notifications below to hear about available products without visiting the site.', 'pc-order-import-export') . '</p>';
        echo '<p>' . esc_html__('Already bought these goods elsewhere or through a manager? Remove the completed request. Folio purchases are not reconciled automatically yet.', 'pc-order-import-export') . '</p>';
        if (WaitlistMail::enabled()) {
            self::form_start('email_preferences', $state['revision']);
            echo '<label class="pcoe-email-consent"><input type="checkbox" name="email_enabled" value="1" ' . checked(!empty($state['email_enabled']), true, false) . '> ' . esc_html__('Email me when waiting-list products are available', 'pc-order-import-export') . '</label>';
            echo '<button class="button" type="submit">' . esc_html__('Save email preference', 'pc-order-import-export') . '</button></form>';
        }
        if (!empty($state['pending'])) {
            echo '<p role="alert">' . esc_html__('The previous action may have completed. Check your cart and drafts before continuing. It will not be repeated automatically.', 'pc-order-import-export') . '</p>';
            self::form_start('acknowledge', $state['revision']);
            echo '<button class="button" type="submit">' . esc_html__('I checked my cart and drafts — continue', 'pc-order-import-export') . '</button></form></section>';
            return;
        }
        self::form_start('add', $state['revision']);
        echo '<label>' . esc_html__('SKU', 'pc-order-import-export') . ' <input name="sku" maxlength="100" required value="' . esc_attr(isset($_GET['sku']) && is_string($_GET['sku']) ? sanitize_text_field(wp_unslash($_GET['sku'])) : '') . '"></label>';
        echo '<label>' . esc_html__('Quantity', 'pc-order-import-export') . ' <input type="number" name="quantity" min="1" max="100000" step="1" value="1" required></label>';
        echo '<button class="button" type="submit">' . esc_html__('Add to waiting list', 'pc-order-import-export') . '</button></form>';
        $groups = WaitlistModel::group(self::effective_entries($state['entries']), time());
        $locations = PriceList::location_ids();
        if (!$groups) echo '<p>' . esc_html__('No active requests. Add a product or enable draft tracking.', 'pc-order-import-export') . '</p>';
        foreach ($groups as $product_id => $group) {
            $product = wc_get_product($product_id);
            if (!$product instanceof \WC_Product || !PriceList::eligible($product)) {
                echo '<article class="pcoe-waitlist-card"><p>' . esc_html__('This product is no longer available in the catalogue.', 'pc-order-import-export') . '</p>';
                self::form_start('act', $state['revision']);
                echo '<input type="hidden" name="product_id" value="' . esc_attr((string) $product_id) . '"><button class="button" name="choice" value="remove" type="submit">' . esc_html__('Remove request', 'pc-order-import-export') . '</button></form></article>';
                continue;
            }
            $stock = PriceList::stock($product, $locations);
            $cart = self::cart_quantity($product_id);
            $remaining = max(0, $group['quantity'] - $cart);
            echo '<article class="pcoe-waitlist-card"><h3><a href="' . esc_url($product->get_permalink()) . '">' . esc_html($product->get_name()) . '</a></h3>';
            echo '<p>' . esc_html__('SKU', 'pc-order-import-export') . ': ' . esc_html($product->get_sku());
            $barcode = function_exists('psu_product_display_barcode') ? psu_product_display_barcode($product) : '';
            if ($barcode !== '') echo ' · ' . esc_html__('Barcode', 'pc-order-import-export') . ': ' . esc_html($barcode);
            echo '</p><p class="pcoe-waitlist-status">' . esc_html($stock === null ? __('Availability is not confirmed', 'pc-order-import-export') : ($stock > 0 ? __('Available now', 'pc-order-import-export') : __('Waiting for stock', 'pc-order-import-export')));
            if ($stock !== null && $stock > 0) echo ': ' . esc_html(wc_format_localized_decimal($stock));
            echo '</p><p>' . esc_html__('Waiting-list quantity', 'pc-order-import-export') . ': ' . esc_html((string) $group['quantity']) . ' · ' . esc_html__('Already in cart', 'pc-order-import-export') . ': ' . esc_html((string) $cart) . '</p><ul>';
            foreach ($group['sources'] as $source) {
                echo '<li>';
                if (!empty($source['draft_id'])) {
                    echo '<a href="' . esc_url(wc_get_endpoint_url('view-order', (int) $source['draft_id'], wc_get_page_permalink('myaccount'))) . '">' . esc_html__('Tracked draft', 'pc-order-import-export') . ' #' . esc_html((string) $source['draft_id']) . '</a>';
                } else echo esc_html__('Added to waiting list', 'pc-order-import-export');
                echo ' · ' . esc_html(wp_date(get_option('date_format'), $source['created_at'])) . '</li>';
            }
            echo '</ul>';
            self::form_start('act', $state['revision']);
            echo '<input type="hidden" name="product_id" value="' . esc_attr((string) $product_id) . '">';
            echo '<label>' . esc_html__('Quantity', 'pc-order-import-export') . ' <input type="number" name="quantity" min="1" max="100000" step="1" value="' . esc_attr((string) max(1, $remaining)) . '" required></label>';
            foreach (['cart' => __('Add to cart', 'pc-order-import-export'), 'draft' => __('Add to draft', 'pc-order-import-export'), 'snooze' => __('Remind me in 7 days', 'pc-order-import-export'), 'remove' => __('Remove request', 'pc-order-import-export')] as $action => $label) {
                $disabled = $action === 'cart' && ($stock === null || $stock <= 0 || $remaining <= 0 || !$product->is_purchasable());
                $skip_quantity = in_array($action, ['snooze', 'remove'], true) ? ' formnovalidate' : '';
                echo '<button class="button" type="submit" name="choice" value="' . esc_attr($action) . '" ' . disabled($disabled, true, false) . $skip_quantity . '>' . esc_html($label) . '</button>';
            }
            echo '</form></article>';
        }
        $paused = array_filter($state['entries'], static fn($entry) => (int) ($entry['snooze_until'] ?? 0) > time());
        if ($paused) {
            self::form_start('resume', $state['revision']);
            echo '<button class="button" type="submit">' . esc_html__('Show postponed requests now', 'pc-order-import-export') . '</button></form>';
        }
        if (array_filter(self::effective_entries($state['entries']), static fn($entry) => (int) $entry['quantity'] <= 0)) {
            self::form_start('clear_finished', $state['revision']);
            echo '<button class="button" type="submit">' . esc_html__('Remove completed draft requests', 'pc-order-import-export') . '</button></form>';
        }
        echo '<p>' . esc_html__('With email notifications enabled, background checks run about every 15 minutes, even when you do not visit the site. Postponing pauses notices for that request; removing it stops them. Stock is checked again when adding to the cart.', 'pc-order-import-export') . '</p></section>';
    }

    private static function finish(string $message, string $type = 'success'): void {
        wc_add_notice($message, $type);
        wp_safe_redirect(wc_get_account_endpoint_url(self::ENDPOINT));
        exit;
    }

    public static function handle(): void {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !self::allowed()) wp_die('Forbidden', '', ['response' => 403]);
        check_admin_referer('pcoe_waitlist');
        if ((!WC()->session || !WC()->cart) && function_exists('wc_load_cart')) wc_load_cart();
        $user = get_current_user_id();
        try {
            $state = WaitlistStore::read($user);
            if (!isset($_POST['revision']) || !is_scalar($_POST['revision']) || (string) $state['revision'] !== (string) $_POST['revision']) {
                throw new \RuntimeException(__('The list changed. Review it and try again.', 'pc-order-import-export'));
            }
            $operation = isset($_POST['operation']) && is_string($_POST['operation']) ? sanitize_key($_POST['operation']) : '';
            if (!empty($state['pending']) && !in_array($operation, ['acknowledge', 'email_preferences'], true)) throw new \RuntimeException(__('Check the previous action before continuing.', 'pc-order-import-export'));
            if ($operation === 'email_preferences') {
                $state['email_enabled'] = ($_POST['email_enabled'] ?? '') === '1';
                $state['email_consent_at'] = time();
            } elseif ($operation === 'acknowledge') {
                $state['pending'] = null;
            } elseif ($operation === 'track_draft') {
                $order = wc_get_order(absint($_POST['draft_id'] ?? 0));
                if (!$order instanceof \WC_Order || !$order->has_status('pc-draft') || (int) $order->get_customer_id() !== $user) throw new \RuntimeException(__('This draft is not available for your account.', 'pc-order-import-export'));
                $track = ($_POST['track_waitlist'] ?? '') === '1';
                $state = self::subscribe_draft($state, $order, $track);
            } elseif ($operation === 'resume') {
                foreach ($state['entries'] as &$entry) $entry['snooze_until'] = 0;
                unset($entry);
            } elseif ($operation === 'clear_finished') {
                foreach (self::effective_entries($state['entries']) as $key => $entry) {
                    if ((int) $entry['quantity'] <= 0) unset($state['entries'][$key]);
                }
            } elseif ($operation === 'add') {
                $sku = isset($_POST['sku']) && is_string($_POST['sku']) ? sanitize_text_field(wp_unslash($_POST['sku'])) : '';
                $product_id = $sku !== '' && strlen($sku) <= 100 ? wc_get_product_id_by_sku($sku) : 0;
                $product = $product_id ? wc_get_product($product_id) : false;
                $qty = WaitlistModel::quantity($_POST['quantity'] ?? '');
                if (!$qty || !$product instanceof \WC_Product || !PriceList::eligible($product)) throw new \RuntimeException(__('Enter a valid product SKU and a whole quantity from 1 to 100000. For a variable product, use the variation SKU.', 'pc-order-import-export'));
                $key = 'manual:' . $product_id;
                if (!isset($state['entries'][$key]) && count($state['entries']) >= WaitlistModel::LIMIT) throw new \RuntimeException(__('The waiting list is full. Remove old entries first (limit: 200 sources).', 'pc-order-import-export'));
                $state['entries'][$key] = ['product_id' => $product_id, 'quantity' => $qty, 'requested' => $qty, 'created_at' => time(), 'status' => 'active', 'snooze_until' => 0, 'reason' => 'explicit_waitlist', 'intent' => wp_generate_uuid4()];
            } elseif ($operation === 'act') {
                $product_id = absint($_POST['product_id'] ?? 0);
                $groups = WaitlistModel::group(self::effective_entries($state['entries']), time());
                if (!isset($groups[$product_id])) throw new \RuntimeException(__('This request is no longer active.', 'pc-order-import-export'));
                $choice = isset($_POST['choice']) && is_string($_POST['choice']) ? sanitize_key($_POST['choice']) : '';
                if (in_array($choice, ['remove', 'snooze'], true)) {
                    foreach ($state['entries'] as $key => &$entry) {
                        if ((int) $entry['product_id'] !== $product_id) continue;
                        if ($choice === 'remove') unset($state['entries'][$key]);
                        else $entry['snooze_until'] = time() + 7 * DAY_IN_SECONDS;
                    }
                    unset($entry);
                } elseif (in_array($choice, ['cart', 'draft'], true)) {
                    self::transfer($user, $state, $product_id, $groups[$product_id], $choice);
                    return;
                } else throw new \RuntimeException(__('Invalid action.', 'pc-order-import-export'));
            } else throw new \RuntimeException(__('Invalid action.', 'pc-order-import-export'));
            if (!WaitlistStore::save($user, $state)) throw new \RuntimeException(__('The list changed. Review it and try again.', 'pc-order-import-export'));
            if ($operation === 'track_draft') {
                $order->update_meta_data('_pcoe_track_waitlist', $track ? 'yes' : 'no');
                $order->update_meta_data('_pcoe_track_consent_at', gmdate('c'));
                $order->save();
            }
            self::finish(__('Waiting list updated.', 'pc-order-import-export'));
        } catch (\Throwable $e) {
            self::finish($e instanceof \RuntimeException && $e->getMessage() !== '' ? $e->getMessage() : __('Could not complete the action. Check your cart and drafts before trying again.', 'pc-order-import-export'), 'error');
        }
    }

    /** Claim state before the Woo mutation; a timeout leaves a visible pending receipt. */
    private static function transfer(int $user, array $state, int $product_id, array $group, string $target): void {
        $qty = WaitlistModel::quantity($_POST['quantity'] ?? '');
        $product = wc_get_product($product_id);
        if (!$qty || !$product instanceof \WC_Product || !PriceList::eligible($product)) throw new \RuntimeException(__('Invalid product or quantity.', 'pc-order-import-export'));
        $cart_qty = self::cart_quantity($product_id);
        if ($qty > max(0, $group['quantity'] - $cart_qty)) throw new \RuntimeException(__('The quantity exceeds the remaining request. Update the waiting list first.', 'pc-order-import-export'));
        if ($target === 'draft' && count($state['entries']) >= WaitlistModel::LIMIT) throw new \RuntimeException(__('The waiting list is full. Remove old entries first (limit: 200 sources).', 'pc-order-import-export'));
        if ($target === 'cart') {
            $stock = PriceList::stock($product, PriceList::location_ids());
            $minimum = (float) apply_filters('woocommerce_quantity_input_min', $product->get_min_purchase_quantity(), $product);
            $step = (float) apply_filters('woocommerce_quantity_input_step', 1, $product);
            $maximum = (float) $product->get_max_purchase_quantity();
            $plan = function_exists('pc_calc_plan_for') ? (array) pc_calc_plan_for($product, (int) ($qty + $cart_qty)) : [];
            if (!WC()->cart || !$product->is_purchasable() || !$product->is_in_stock() || $stock === null || $stock < $qty + $cart_qty || array_sum($plan) < $qty + $cart_qty || !$product->has_enough_stock($qty + $cart_qty) || $qty < $minimum || $step <= 0 || abs(($qty - $minimum) / $step - round(($qty - $minimum) / $step)) > 0.000001 || ($maximum > 0 && $qty + $cart_qty > $maximum)) {
                throw new \RuntimeException(__('Review the quantity, pack size and selected warehouse. The requested quantity cannot currently be added.', 'pc-order-import-export'));
            }
        }
        $state['pending'] = ['target' => $target, 'product_id' => $product_id, 'quantity' => $qty, 'created_at' => time()];
        if (!WaitlistStore::save($user, $state)) throw new \RuntimeException(__('The list changed. Review it and try again.', 'pc-order-import-export'));
        $state['revision']++;
        if ($target === 'cart') {
            if (!apply_filters('woocommerce_add_to_cart_validation', true, $product->is_type('variation') ? $product->get_parent_id() : $product_id, $qty, $product->is_type('variation') ? $product_id : 0, $product->is_type('variation') ? $product->get_variation_attributes() : [])) {
                throw new \RuntimeException(__('WooCommerce rejected the item. Check the cart before continuing.', 'pc-order-import-export'));
            }
            $key = WC()->cart->add_to_cart($product->is_type('variation') ? $product->get_parent_id() : $product_id, $qty, $product->is_type('variation') ? $product_id : 0, $product->is_type('variation') ? $product->get_variation_attributes() : []);
            if (!$key) throw new \RuntimeException(__('WooCommerce rejected the item. Check the cart before continuing.', 'pc-order-import-export'));
            WC()->cart->calculate_totals();
            WC()->cart->set_session();
        } else {
            // Existing source drafts are already the destination: do not create duplicate demand.
            foreach ($group['sources'] as $source) if (!empty($source['draft_id'])) {
                $state['pending'] = null;
                if (!WaitlistStore::save($user, $state)) throw new \RuntimeException();
                self::finish(__('This product is already in a tracked draft. Open the source draft shown in the list.', 'pc-order-import-export'), 'notice');
            }
            $draft = wc_create_order(['status' => 'wc-pc-draft', 'customer_id' => $user]);
            if (!$draft instanceof \WC_Order) throw new \RuntimeException();
            $draft->update_meta_data('_pcoe_waitlist_source', 'manual:' . $product_id);
            $draft->save();
            if (!$draft->add_product($product, $qty)) throw new \RuntimeException();
            $draft->calculate_totals(false);
            $draft->save();
            $state = self::subscribe_draft($state, $draft, true);
            // Transfer the selected quantity; retain any unselected manual remainder.
            $manual = 'manual:' . $product_id;
            if (isset($state['entries'][$manual])) {
                $intent = $state['entries'][$manual]['intent'] ?? $manual;
                foreach ($state['entries'] as &$entry) {
                    if ((int) ($entry['draft_id'] ?? 0) === (int) $draft->get_id()) $entry['intent'] = $intent;
                }
                unset($entry);
                $state['entries'][$manual]['quantity'] = max(0, $state['entries'][$manual]['quantity'] - $qty);
                if ($state['entries'][$manual]['quantity'] === 0) unset($state['entries'][$manual]);
            }
            $draft->update_meta_data('_pcoe_track_waitlist', 'yes');
            $draft->update_meta_data('_pcoe_track_consent_at', gmdate('c'));
            $draft->save();
        }
        $state['pending'] = null;
        if (!WaitlistStore::save($user, $state)) throw new \RuntimeException();
        self::finish($target === 'cart' ? __('Selected quantity added to cart. The request stays tracked until you remove it.', 'pc-order-import-export') : __('Draft created. Open it from the waiting list.', 'pc-order-import-export'));
    }
}
