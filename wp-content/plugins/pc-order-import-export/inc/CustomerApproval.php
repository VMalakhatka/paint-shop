<?php
namespace PaintCore\PCOE;

defined('ABSPATH') || exit;

/** Customer acknowledgement only: never checkout, payment, stock or Folio writes. */
class CustomerApproval {
    const TYPE = 'pcoe-approval';
    const PAGE = 'pcoe-approvals';

    public static function hooks(): void {
        add_action('init', [self::class, 'register']);
        add_action('admin_menu', [self::class, 'menu'], 41);
        add_action('admin_post_pcoe_approval_request', [self::class, 'request']);
        add_action('admin_post_pcoe_approval_confirm', [self::class, 'confirm']);
        add_action('woocommerce_account_dashboard', [self::class, 'account']);
        add_action('woocommerce_order_details_after_order_table', [self::class, 'order_link']);
        add_action('woocommerce_admin_order_data_after_order_details', [self::class, 'manager_link']);
    }

    public static function register(): void {
        register_post_type(self::TYPE, ['public' => false, 'show_ui' => false, 'show_in_rest' => false,
            'rewrite' => false, 'query_var' => false, 'supports' => []]);
    }

    public static function menu(): void {
        add_submenu_page(function_exists('paint_core_lavka_admin_parent_slug') ? paint_core_lavka_admin_parent_slug() : 'woocommerce',
            __('Customer confirmations', 'pc-order-import-export'), __('Customer confirmations', 'pc-order-import-export'),
            'manage_woocommerce', self::PAGE, [self::class, 'admin']);
    }

    public static function url(array $args = []): string {
        return add_query_arg(array_merge(['page' => self::PAGE], $args), admin_url('admin.php'));
    }

    public static function customer_url(int $id): string {
        return add_query_arg('pcoe_approval', $id, wc_get_page_permalink('myaccount'));
    }

    public static function manager_link($order): void {
        if (!$order instanceof \WC_Order || !current_user_can('manage_woocommerce') || !$order->get_customer_id()) return;
        echo '<p><a class="button" href="' . esc_url(self::url(['order_id' => $order->get_id()])) . '">' . esc_html__('Customer confirmation', 'pc-order-import-export') . '</a></p>';
    }

    public static function order_link($order): void {
        if (!$order instanceof \WC_Order || (int) $order->get_customer_id() !== get_current_user_id()) return;
        $post = self::find(['customer_id' => (int) $order->get_customer_id(), 'order_id' => $order->get_id()]);
        if ($post) echo '<p><a class="button" href="' . esc_url(self::customer_url($post->ID)) . '">' . esc_html__('Review and confirm order', 'pc-order-import-export') . '</a></p>';
    }

    private static function fail(string $message): void { throw new \RuntimeException($message); }
    private static function unavailable(): void { self::fail(__('The order is unavailable for confirmation.', 'pc-order-import-export')); }

    /** Do not accept a posted customer identity from the customer confirmation route. */
    public static function authorize_customer(int $owner, int $actor): bool {
        return $owner > 0 && $actor > 0 && $owner === $actor;
    }

    public static function key(array $source): string {
        return hash('sha256', wp_json_encode([(int) $source['customer_id'], !empty($source['order_id'])
            ? ['order', (int) $source['order_id']] : ['folio', $source['type'], (int) $source['document_id']]]));
    }

    public static function revision(array $snapshot): string {
        // Presentation language must not change the business revision.
        unset($snapshot['title'], $snapshot['warehouse']);
        if (isset($snapshot['documents'])) {
            $snapshot['documents'] = array_map([self::class, 'revision'], $snapshot['documents']);
        }
        return hash('sha256', wp_json_encode($snapshot));
    }

    private static function find(array $source) {
        $posts = get_posts(['post_type' => self::TYPE, 'post_status' => 'private', 'name' => self::key($source), 'numberposts' => 1]);
        return $posts[0] ?? null;
    }

    private static function data($post): array {
        $data = json_decode($post->post_content, true);
        if (!is_array($data) || !isset($data['source'], $data['snapshot'], $data['revision'], $data['status'])) self::unavailable();
        return $data;
    }

    private static function read(int $id) {
        $post = get_post($id);
        if (!$post || $post->post_type !== self::TYPE || $post->post_status !== 'private') self::unavailable();
        return $post;
    }

    private static function locked(string $key, callable $work) {
        global $wpdb;
        $name = 'pcoe-approval:' . substr(hash('sha256', $wpdb->prefix . $key), 0, 44);
        if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $name)) !== '1') {
            self::fail(__('Another operation is running. Reload the page and try again.', 'pc-order-import-export'));
        }
        try { return $work(); }
        finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name)); }
    }

    private static function save(array $data, int $id = 0): int {
        $result = wp_insert_post(wp_slash(['ID' => $id, 'post_type' => self::TYPE, 'post_status' => 'private',
            'post_author' => $data['source']['customer_id'], 'post_name' => self::key($data['source']),
            'post_title' => $data['snapshot']['title'], 'post_content' => wp_json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)]), true);
        if (is_wp_error($result)) self::fail(__('The confirmation could not be saved. Please try again.', 'pc-order-import-export'));
        return (int) $result;
    }

    private static function source_from_request(): array {
        $source = ['order_id' => absint($_REQUEST['order_id'] ?? 0), 'customer_id' => absint($_REQUEST['customer_id'] ?? 0),
            'type' => sanitize_key($_REQUEST['document_type'] ?? $_REQUEST['type'] ?? ''), 'document_id' => absint($_REQUEST['document_id'] ?? 0)];
        $source['type'] = strtoupper($source['type']);
        if ($source['order_id']) {
            $order = wc_get_order($source['order_id']);
            if (!$order || !$order->get_customer_id()) self::unavailable();
            $source['customer_id'] = (int) $order->get_customer_id();
        } elseif ($source['type'] === 'ACCOUNT' && $source['document_id']) {
            // A linked Woo order is the canonical confirmation target.
            $orders = wc_get_orders(['customer_id' => $source['customer_id'], 'limit' => 2,
                'meta_query' => [['key' => '_folio_document_id', 'value' => (string) $source['document_id']]]]);
            if (count($orders) > 1) self::fail(__('Several orders reference this document. Open the required website order.', 'pc-order-import-export'));
            if ($orders) $source['order_id'] = $orders[0]->get_id();
        } else self::unavailable();
        $customer = get_userdata($source['customer_id']);
        if (!$customer || !function_exists('pc_wholesale_customer_can_access')
            || !pc_wholesale_customer_can_access($customer)) self::unavailable();
        return $source;
    }

    private static function folio(array $source, int $id): array {
        if (!function_exists('pc_folio_documents_fetch_detail')) self::unavailable();
        $context = pc_folio_balance_user_context($source['customer_id'], false);
        if (!$context) self::unavailable();
        $response = pc_folio_documents_fetch_detail($context, 'ACCOUNT', $id, false);
        if (is_wp_error($response)) self::fail($response->get_error_message());
        $doc = $response['document'];
        if (($doc['documentType'] ?? '') !== 'ACCOUNT' || !empty($doc['returnDocument']) || empty($doc['items'])
            || (int) ($doc['documentId'] ?? 0) !== $id || !is_numeric($doc['totalAmount'] ?? null)) self::unavailable();
        $items = [];
        foreach ($doc['items'] as $item) {
            foreach (['quantity', 'price', 'amount'] as $field) if (!is_numeric($item[$field] ?? null)) self::unavailable();
            $items[] = ['sku' => (string) ($item['sku'] ?? ''), 'name' => (string) ($item['name'] ?? ''),
                'quantity' => (string) $item['quantity'], 'price' => (string) $item['price'], 'amount' => (string) $item['amount']];
        }
        return ['title' => __('Folio account', 'pc-order-import-export') . ' ' . ($doc['documentNumber'] ?? $id),
            'id' => $id, 'partner' => $context['short_name'], 'warehouseId' => (string) ($doc['warehouseId'] ?? ''), 'warehouse' => $doc['warehouseLabel'] ?? '',
            'total' => (string) $doc['totalAmount'], 'currency' => 'UAH', 'items' => $items,
            'information' => pc_folio_documents_information($doc)];
    }

    public static function snapshot(array $source): array {
        $customer = get_userdata((int) $source['customer_id']);
        if (!$customer || !pc_wholesale_customer_can_access($customer)) self::unavailable();
        if (empty($source['order_id'])) return self::folio($source, (int) $source['document_id']);
        $order = wc_get_order($source['order_id']);
        if (!$order || (int) $order->get_customer_id() !== (int) $source['customer_id']
            || $order->has_status(['cancelled', 'refunded', 'failed', 'completed'])) self::unavailable();
        $items = [];
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $qty = $item->get_quantity();
            $amount = (float) $item->get_total() + (float) $item->get_total_tax();
            $items[] = ['sku' => $product ? $product->get_sku() : '', 'name' => $item->get_name(),
                'quantity' => (string) $qty, 'price' => $qty ? (string) ($amount / $qty) : '0', 'amount' => (string) $amount];
        }
        if (!$items) self::unavailable();
        $documents = [];
        $id = absint($order->get_meta('_folio_document_id'));
        if ($id) $documents[$id] = self::folio($source, $id);
        if (function_exists('pc_folio_get_order_documents_result')) {
            foreach ((pc_folio_get_order_documents_result($order)['documents'] ?? []) as $doc) {
                $id = absint($doc['document_id'] ?? $doc['documentId'] ?? 0);
                if ($id && !isset($documents[$id])) $documents[$id] = self::folio($source, $id);
            }
        }
        ksort($documents);
        return ['title' => __('Website order', 'pc-order-import-export') . ' #' . $order->get_order_number(),
            'items' => $items, 'total' => $order->get_total(), 'currency' => $order->get_currency(),
            'shipping' => $order->get_shipping_total(), 'fees' => $order->get_total_fees(),
            'address' => $order->get_address('shipping'), 'note' => $order->get_customer_note(),
            'documents' => array_values($documents)];
    }

    public static function choices(): array {
        $payment = [];
        foreach (WC()->payment_gateways()->payment_gateways() as $id => $gateway) {
            if ($gateway->enabled === 'yes') $payment[$id] = wp_strip_all_tags($gateway->get_title());
        }
        $delivery = [];
        $zones = \WC_Shipping_Zones::get_zones();
        $zones[] = ['zone_id' => 0, 'zone_name' => __('Other locations', 'pc-order-import-export')];
        foreach ($zones as $zone) {
            foreach ((new \WC_Shipping_Zone($zone['zone_id']))->get_shipping_methods(true) as $method) {
                $delivery[$method->get_rate_id()] = wp_strip_all_tags($zone['zone_name'] . ': ' . $method->get_title());
            }
        }
        return ['payment' => $payment, 'delivery' => $delivery];
    }

    public static function preferences(array $input, array $choices): array {
        $result = [];
        foreach (['payment', 'delivery'] as $key) {
            $id = (string) ($input[$key] ?? '');
            if (!isset($choices[$key][$id])) self::fail(__('Select delivery and payment from the available options.', 'pc-order-import-export'));
            $result[$key] = ['id' => $id, 'label' => $choices[$key][$id]];
        }
        foreach (['recipient', 'phone', 'destination'] as $key) {
            $text = trim(sanitize_textarea_field((string) ($input[$key] ?? '')));
            if ($text === '' || mb_strlen($text) > 500) self::fail(__('Enter recipient, phone and delivery details (up to 500 characters each).', 'pc-order-import-export'));
            $result[$key] = $text;
        }
        return $result;
    }

    public static function request(): void {
        try {
            if (!current_user_can('manage_woocommerce')) self::unavailable();
            check_admin_referer('pcoe_approval_request');
            $source = self::source_from_request();
            $id = self::locked(self::key($source), function () use ($source) {
                $snapshot = self::snapshot($source);
                $revision = self::revision($snapshot);
                if (!hash_equals($revision, (string) ($_POST['revision'] ?? ''))) self::fail(__('The order changed. Review it again before continuing.', 'pc-order-import-export'));
                $post = self::find($source);
                $old = $post ? self::data($post) : [];
                if (($old['revision'] ?? '') === $revision) return $post->ID;
                $history = $old['history'] ?? [];
                if ($old) { unset($old['history']); $history[] = $old; }
                return self::save(['source' => $source, 'snapshot' => $snapshot, 'revision' => $revision,
                    'status' => 'pending', 'requested_by' => get_current_user_id(), 'requested_at' => gmdate('c'),
                    'history' => $history], $post ? $post->ID : 0);
            });
            wp_safe_redirect(self::url(['approval_id' => $id])); exit;
        } catch (\Throwable $e) { wp_die(esc_html($e->getMessage()), '', ['response' => 409]); }
    }

    public static function confirm(): void {
        try {
            if (!get_current_user_id()) self::unavailable();
            check_admin_referer('pcoe_approval_confirm');
            $id = absint($_POST['approval_id'] ?? 0);
            $post = self::read($id);
            if (!self::authorize_customer((int) $post->post_author, get_current_user_id())) self::unavailable();
            $initial = self::data($post);
            self::locked(self::key($initial['source']), function () use ($id) {
                clean_post_cache($id);
                $post = self::read($id);
                $data = self::data($post);
                if (!self::authorize_customer((int) $post->post_author, get_current_user_id())
                    || (int) $data['source']['customer_id'] !== get_current_user_id()) self::unavailable();
                $revision = self::revision(self::snapshot($data['source']));
                $updated = self::confirmed($data, get_current_user_id(), $revision, wp_unslash($_POST), self::choices());
                if ($updated === $data) return;
                $data = $updated;
                self::save($data, $id);
            });
            wp_safe_redirect(self::customer_url($id)); exit;
        } catch (\Throwable $e) { wp_die(esc_html($e->getMessage()), '', ['response' => 409]); }
    }

    /** Pure transition, with customer identity and an exact reviewed revision. */
    public static function confirmed(array $data, int $actor, string $fresh_revision, array $input, array $choices): array {
        if (!self::authorize_customer((int) $data['source']['customer_id'], $actor)) self::unavailable();
        if (!hash_equals($data['revision'], $fresh_revision) || !hash_equals($fresh_revision, (string) ($input['revision'] ?? ''))) {
            self::fail(__('The order changed. Ask the manager to send the updated version for confirmation.', 'pc-order-import-export'));
        }
        if ($data['status'] === 'confirmed') return $data;
        if ($data['status'] !== 'pending') self::unavailable();
        if (($input['consent'] ?? '') !== '1') self::fail(__('Confirm that you have checked the order and selected conditions.', 'pc-order-import-export'));
        $data['preferences'] = self::preferences($input, $choices);
        $data['status'] = 'confirmed'; $data['confirmed_by'] = $actor; $data['confirmed_at'] = gmdate('c');
        $data['consent_version'] = 1;
        return $data;
    }

    private static function table(array $snapshot): void {
        echo '<h3>' . esc_html($snapshot['title']) . '</h3><div style="overflow-x:auto"><table class="shop_table widefat"><thead><tr>';
        foreach (['SKU', 'Product', 'Quantity', 'Price', 'Amount'] as $label) echo '<th>' . esc_html__($label, 'pc-order-import-export') . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($snapshot['items'] as $item) {
            echo '<tr>'; foreach (['sku', 'name', 'quantity', 'price', 'amount'] as $field) echo '<td>' . esc_html($item[$field]) . '</td>'; echo '</tr>';
        }
        echo '</tbody></table></div><p><strong>' . esc_html__('Total', 'pc-order-import-export') . ': ' . esc_html($snapshot['total'] . ' ' . $snapshot['currency']) . '</strong></p>';
        if (!empty($snapshot['warehouse'])) echo '<p>' . esc_html($snapshot['warehouse']) . '</p>';
        if (!empty($snapshot['information'])) echo '<p>' . esc_html($snapshot['information']) . '</p>';
        if (!empty($snapshot['note'])) echo '<p>' . esc_html($snapshot['note']) . '</p>';
        foreach ($snapshot['documents'] ?? [] as $document) self::table($document);
    }

    private static function details($post, bool $manager): void {
        $data = self::data($post);
        if (!$manager && !self::authorize_customer((int) $data['source']['customer_id'], get_current_user_id())) self::unavailable();
        $check_error = false;
        try { $fresh = self::revision(self::snapshot($data['source'])) === $data['revision']; }
        catch (\Throwable $e) { $fresh = false; $check_error = true; }
        if ($manager) {
            $customer = get_userdata((int) $post->post_author);
            echo '<p>' . esc_html($customer ? $customer->display_name : '#' . $post->post_author) . '</p>';
        }
        if ($check_error) echo '<p role="alert">' . esc_html__('The current version could not be checked. Saved details are shown; confirmation is unavailable until verification succeeds.', 'pc-order-import-export') . '</p>';
        self::table($data['snapshot']);
        echo '<p><strong>' . esc_html(!$fresh ? ($check_error ? __('Verification required', 'pc-order-import-export') : __('Changed: new confirmation required', 'pc-order-import-export')) : ($data['status'] === 'confirmed' ? __('Confirmed by customer', 'pc-order-import-export') : __('Awaiting customer confirmation', 'pc-order-import-export'))) . '</strong></p>';
        if (!empty($data['confirmed_at'])) {
            echo '<p>' . esc_html($data['confirmed_at']) . '</p><dl>';
            foreach ($data['preferences'] as $key => $value) echo '<dt>' . esc_html(self::field_label($key)) . '</dt><dd>' . esc_html(is_array($value) ? $value['label'] : $value) . '</dd>';
            echo '</dl>';
        }
        if ($manager) {
            echo '<p>' . esc_html__('Customer account link (login required):', 'pc-order-import-export') . ' <a href="' . esc_url(self::customer_url($post->ID)) . '">' . esc_html(self::customer_url($post->ID)) . '</a></p>';
            echo '<p><a class="button" href="' . esc_url(self::url($data['source'])) . '">' . esc_html__('Review current version', 'pc-order-import-export') . '</a></p>';
            return;
        }
        if ($check_error) return;
        if (!$fresh) { echo '<p>' . esc_html__('The order changed. Ask the manager to send the updated version for confirmation.', 'pc-order-import-export') . '</p>'; return; }
        if ($data['status'] === 'confirmed') return;
        $choices = self::choices();
        if (!$choices['payment'] || !$choices['delivery']) { echo '<p>' . esc_html__('Delivery or payment options are unavailable. Contact the manager.', 'pc-order-import-export') . '</p>'; return; }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('pcoe_approval_confirm');
        foreach (['action' => 'pcoe_approval_confirm', 'approval_id' => $post->ID, 'revision' => $data['revision']] as $key => $value) self::hidden($key, $value);
        foreach ($choices as $key => $options) {
            echo '<p><label>' . esc_html(self::field_label($key)) . '<br><select name="' . esc_attr($key) . '" required><option value="">—</option>';
            foreach ($options as $value => $label) echo '<option value="' . esc_attr($value) . '">' . esc_html($label) . '</option>';
            echo '</select></label></p>';
        }
        foreach (['recipient', 'phone', 'destination'] as $key) {
            echo '<p><label>' . esc_html(self::field_label($key)) . '<br><textarea name="' . esc_attr($key) . '" rows="2" maxlength="500" required style="width:100%"></textarea></label></p>';
        }
        echo '<p>' . esc_html__('Delivery and payment are saved for the manager. Delivery availability and cost will be agreed separately; this does not make a payment or start shipment.', 'pc-order-import-export') . '</p>';
        echo '<p><label><input type="checkbox" name="consent" value="1" required> ' . esc_html__('I have checked the products, quantities, prices, delivery and payment preferences and confirm this order.', 'pc-order-import-export') . '</label></p>';
        echo '<button type="submit" class="button">' . esc_html__('Confirm order', 'pc-order-import-export') . '</button></form>';
    }

    private static function field_label(string $key): string {
        $labels = ['payment' => __('Payment preference', 'pc-order-import-export'), 'delivery' => __('Delivery preference', 'pc-order-import-export'),
            'recipient' => __('Recipient', 'pc-order-import-export'), 'phone' => __('Phone', 'pc-order-import-export'),
            'destination' => __('City, branch/address or pickup location; specify warehouses if destinations differ', 'pc-order-import-export')];
        return $labels[$key] ?? $key;
    }

    private static function hidden(string $key, $value): void { echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '">'; }

    public static function account(): void {
        if (!function_exists('pc_wholesale_customer_can_access') || !pc_wholesale_customer_can_access()) return;
        echo '<section><h2>' . esc_html__('Orders for confirmation', 'pc-order-import-export') . '</h2>';
        try {
            $id = absint($_GET['pcoe_approval'] ?? 0);
            if ($id) {
                $post = self::read($id);
                if (!self::authorize_customer((int) $post->post_author, get_current_user_id())) self::unavailable();
                self::details($post, false);
            } else self::listing(false);
        } catch (\Throwable $e) { echo '<p role="alert">' . esc_html($e->getMessage()) . '</p>'; }
        echo '</section>';
    }

    private static function listing(bool $manager): void {
        $page = max(1, absint($_GET['approval_page'] ?? 1));
        $args = ['post_type' => self::TYPE, 'post_status' => 'private', 'posts_per_page' => 20, 'paged' => $page];
        if (!$manager) $args['author'] = get_current_user_id();
        $query = new \WP_Query($args);
        if (!$query->posts) echo '<p>' . esc_html__('No orders awaiting confirmation.', 'pc-order-import-export') . '</p>';
        echo '<ul>';
        foreach ($query->posts as $post) {
            $data = self::data($post);
            $user = $manager ? get_userdata($post->post_author) : null;
            $url = $manager ? self::url(['approval_id' => $post->ID]) : self::customer_url($post->ID);
            echo '<li><a href="' . esc_url($url) . '">' . esc_html(($user ? $user->display_name . ' — ' : '') . $post->post_title) . '</a> — ' . esc_html($data['status'] === 'confirmed' ? __('Confirmation recorded; open to check current version', 'pc-order-import-export') : __('Awaiting customer confirmation', 'pc-order-import-export')) . '</li>';
        }
        echo '</ul>';
        for ($i = 1; $i <= $query->max_num_pages; $i++) echo ' <a href="' . esc_url(add_query_arg('approval_page', $i, $manager ? self::url() : wc_get_page_permalink('myaccount'))) . '">' . esc_html($i) . '</a> ';
    }

    public static function admin(): void {
        if (!current_user_can('manage_woocommerce')) wp_die('Forbidden');
        echo '<div class="wrap"><h1>' . esc_html__('Customer confirmations', 'pc-order-import-export') . '</h1>';
        try {
            if (!empty($_GET['approval_id'])) self::details(self::read(absint($_GET['approval_id'])), true);
            elseif (!empty($_GET['order_id']) || !empty($_GET['document_id'])) {
                $source = self::source_from_request(); $snapshot = self::snapshot($source);
                echo '<p>' . esc_html(get_userdata($source['customer_id'])->display_name) . '</p>';
                self::table($snapshot);
                if ($post = self::find($source)) echo '<p><a href="' . esc_url(self::url(['approval_id' => $post->ID])) . '">' . esc_html__('View saved confirmation', 'pc-order-import-export') . '</a></p>';
                echo '<p>' . esc_html__('The request appears in the customer account. No email is sent automatically.', 'pc-order-import-export') . '</p>';
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'; wp_nonce_field('pcoe_approval_request');
                foreach (array_merge($source, ['document_type' => $source['type'], 'action' => 'pcoe_approval_request', 'revision' => self::revision($snapshot)]) as $key => $value) self::hidden($key, $value);
                echo '<button class="button button-primary">' . esc_html__('Send for customer confirmation', 'pc-order-import-export') . '</button></form>';
            } else self::listing(true);
        } catch (\Throwable $e) { echo '<p role="alert">' . esc_html($e->getMessage()) . '</p>'; }
        echo '</div>';
    }
}
