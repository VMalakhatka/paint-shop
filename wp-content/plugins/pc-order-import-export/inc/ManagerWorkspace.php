<?php
namespace PaintCore\PCOE;

defined('ABSPATH') || exit;

/** Manager identity authorizes commands; customer identity owns their data. */
class ManagerWorkspace
{
    public const PAGE = 'pcoe-customers';
    private const COMMAND = '_pcoe_manager_command';

    public static function hooks(): void {
        add_action('admin_menu', [self::class, 'menu'], 30);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
        add_action('wp_ajax_pcoe_manager', [self::class, 'ajax']);
        add_action('pc_folio_child_order_item_prepared', [self::class, 'stamp_child_plan'], 10, 3);
        foreach (['wp_ajax_pc_folio_order_create_java', 'wp_ajax_pc_folio_order_apply_saved_response',
            'wp_ajax_pc_folio_order_create_child_orders', 'admin_post_pcoe_draft_folio_apply'] as $hook) {
            add_action($hook, [self::class, 'legacy_lock'], 0);
        }
    }

    public static function menu(): void {
        add_submenu_page(function_exists('paint_core_lavka_admin_parent_slug') ? paint_core_lavka_admin_parent_slug() : 'woocommerce',
            __('Customer workspace', 'pc-order-import-export'), __('Customer workspace', 'pc-order-import-export'),
            'manage_woocommerce', self::PAGE, [self::class, 'render']);
    }

    public static function url(int $customer_id, int $order_id = 0): string {
        return add_query_arg(['page' => self::PAGE, 'customer_id' => $customer_id, 'order_id' => $order_id], admin_url('admin.php'));
    }

    public static function customer(int $id): \WP_User {
        if (!current_user_can('manage_woocommerce')) {
            throw new \RuntimeException(__('You do not have permission to perform this action.', 'pc-order-import-export'));
        }
        $user = get_userdata($id);
        if (!$user instanceof \WP_User || !array_intersect(['customer', 'opt', 'partner'], $user->roles)) {
            throw new \RuntimeException(__('Select a customer account.', 'pc-order-import-export'));
        }
        return $user;
    }

    public static function assets(): void {
        if (($_GET['page'] ?? '') !== self::PAGE || !current_user_can('manage_woocommerce')) return;
        wp_enqueue_style('pcoe-manager', PCOE_URL . 'assets/manager.css', [], filemtime(PCOE_DIR . '/assets/manager.css'));
        wp_enqueue_script('pcoe-manager', PCOE_URL . 'assets/manager.js', [], filemtime(PCOE_DIR . '/assets/manager.js'), true);
        wp_localize_script('pcoe-manager', 'pcoeManager', [
            'url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('pcoe_manager'),
            'busy' => __('Processing…', 'pc-order-import-export'),
            'saveFirst' => __('Save draft changes before continuing.', 'pc-order-import-export'),
            'error' => __('The request failed. Reload the workspace to check the saved result before trying again.', 'pc-order-import-export'),
        ]);
        try {
            $user = self::customer(absint($_GET['customer_id'] ?? 0));
            if (function_exists('pc_folio_documents_enqueue_assets')) pc_folio_documents_enqueue_assets($user->ID);
        } catch (\Throwable $e) { /* Customer has not been selected yet. */ }
    }

    /** Directory reads local Woo profiles and cached Folio names; no Folio request. */
    public static function directory(string $search, string $role, string $city, int $page): array {
        if (!current_user_can('manage_woocommerce')) throw new \RuntimeException('Forbidden');
        global $wpdb;
        $roles = ['customer', 'opt', 'partner'];
        $args = ['role__in' => in_array($role, $roles, true) ? [$role] : $roles,
            'number' => 25, 'paged' => max(1, $page), 'orderby' => ['display_name' => 'ASC', 'ID' => 'ASC']];
        $query = new \WP_User_Query();
        $filter = static function ($candidate) use ($query, $search, $city, $wpdb): void {
            if ($candidate !== $query) return;
            if ($search !== '') {
                $like = '%' . $wpdb->esc_like($search) . '%';
                $candidate->query_where .= $wpdb->prepare(" AND ({$wpdb->users}.display_name LIKE %s OR {$wpdb->users}.user_email LIKE %s OR {$wpdb->users}.user_login LIKE %s OR EXISTS (SELECT 1 FROM {$wpdb->usermeta} directory_search WHERE directory_search.user_id = {$wpdb->users}.ID AND directory_search.meta_key IN ('billing_company','first_name','last_name','_folio_partner_name','_folio_partner_short_name') AND directory_search.meta_value LIKE %s))", $like, $like, $like, $like);
            }
            if ($city !== '') $candidate->query_where .= $wpdb->prepare(" AND EXISTS (SELECT 1 FROM {$wpdb->usermeta} directory_city WHERE directory_city.user_id = {$wpdb->users}.ID AND directory_city.meta_key = 'billing_city' AND TRIM(directory_city.meta_value) = %s)", $city);
        };
        add_action('pre_user_query', $filter);
        try { $query->prepare_query($args); $query->query(); }
        finally { remove_action('pre_user_query', $filter); }
        $city_query = new \WP_User_Query(['role__in' => $roles, 'number' => 1, 'fields' => 'ID', 'count_total' => false]);
        $cities = $wpdb->get_col("SELECT DISTINCT TRIM(directory_city.meta_value) " . $city_query->query_from . " INNER JOIN {$wpdb->usermeta} directory_city ON directory_city.user_id = {$wpdb->users}.ID AND directory_city.meta_key = 'billing_city' " . $city_query->query_where . " AND TRIM(directory_city.meta_value) <> '' ORDER BY 1");
        return ['users' => $query->get_results(), 'total' => $query->get_total(), 'cities' => $cities];
    }

    public static function render(): void {
        if (!current_user_can('manage_woocommerce')) wp_die(esc_html__('You do not have permission to perform this action.', 'pc-order-import-export'));
        require PCOE_DIR . '/inc/manager-view.php';
    }

    /** Read-only price scope. No authentication/session switch or callbacks that write orders. */
    public static function customer_price(\WC_Product $product, \WP_User $customer): string {
        $previous = $GLOBALS['current_user'] ?? null;
        $previous_customer = WC()->customer;
        $allow = static function () { return true; };
        try {
            $GLOBALS['current_user'] = $customer;
            WC()->customer = new \WC_Customer($customer->ID);
            add_filter('rp_customer_price_context', $allow);
            $price = $product->get_price();
            if ($price === '' || !is_numeric($price) || !is_finite((float) $price) || (float) $price < 0) {
                throw new \RuntimeException(__('A product has no valid customer price.', 'pc-order-import-export'));
            }
            return wc_format_decimal(wc_get_price_excluding_tax($product, ['price' => $price]), wc_get_price_decimals());
        } finally {
            $GLOBALS['current_user'] = $previous;
            WC()->customer = $previous_customer;
            remove_filter('rp_customer_price_context', $allow);
        }
    }

    public static function order(int $id, int $customer_id): \WC_Order {
        $order = wc_get_order($id);
        if (!$order instanceof \WC_Order || (int) $order->get_customer_id() !== $customer_id) {
            throw new \RuntimeException(__('The order does not belong to the selected customer.', 'pc-order-import-export'));
        }
        return $order;
    }

    public static function command(\WC_Order $order): array {
        $value = $order->get_meta(self::COMMAND, true);
        return is_array($value) ? $value : [];
    }

    public static function editable(\WC_Order $order): bool {
        return $order->has_status('pc-draft') && !$order->get_meta(self::COMMAND, true)
            && !pc_folio_order_has_saved_documents($order) && !$order->get_parent_id();
    }

    private static function require_editable(\WC_Order $order): void {
        if (!self::editable($order)) throw new \RuntimeException(__('This draft is locked. Check its Folio result or create a new copy.', 'pc-order-import-export'));
    }

    public static function revision(\WC_Order $order): string {
        $lines = [];
        foreach ($order->get_items() as $id => $item) $lines[$id] = [$item->get_product_id(), $item->get_variation_id(), $item->get_quantity(), $item->get_total(), $item->get_subtotal()];
        return hash('sha256', wp_json_encode([$order->get_customer_id(), $order->get_status(), $order->get_address('billing'),
            $order->get_address('shipping'), $order->get_customer_note(), $order->get_meta('_pc_draft_title'), $lines]));
    }

    public static function audit(\WC_Order $order, string $action): void {
        $actor = get_current_user_id();
        if (!$order->get_meta('_pcoe_manager_created_by')) $order->update_meta_data('_pcoe_manager_created_by', $actor);
        $order->update_meta_data('_pcoe_manager_updated_by', $actor);
        $order->add_order_note($action, false, true);
        $order->save();
    }

    private static function new_draft(\WP_User $user): \WC_Order {
        $order = wc_create_order(['status' => 'pc-draft', 'customer_id' => $user->ID, 'created_via' => 'pcoe-manager']);
        if (!$order instanceof \WC_Order) throw new \RuntimeException(__('Failed to create draft order.', 'pc-order-import-export'));
        $customer = new \WC_Customer($user->ID);
        $order->set_address($customer->get_billing('edit'), 'billing');
        $order->set_address($customer->get_shipping('edit'), 'shipping');
        return $order;
    }

    /** Per-order connection lock also covers the outbound command and Woo finalization. */
    private static function lock(string $name, callable $callback) {
        global $wpdb;
        $name = self::lock_name($name);
        if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $name)) !== '1') {
            throw new \RuntimeException(__('Another operation is running. Reload the workspace shortly.', 'pc-order-import-export'));
        }
        try { return $callback(); }
        finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name)); }
    }

    private static function lock_name(string $name): string {
        global $wpdb;
        return 'pcoe:' . substr(hash('sha256', $wpdb->prefix . $name), 0, 48);
    }

    /** Existing authenticated mutation routes share the manager order lock until request shutdown. */
    public static function legacy_lock(): void {
        $id = absint($_POST['order_id'] ?? 0);
        if (!$id && isset($_POST['payload'])) {
            $payload = json_decode(wp_unslash($_POST['payload']), true);
            $id = is_array($payload) ? absint($payload['woo_order']['id'] ?? 0) : 0;
        }
        $order = $id ? wc_get_order($id) : false;
        if (!$order || (!current_user_can('manage_woocommerce') && (!$order->get_customer_id() || (int) $order->get_customer_id() !== get_current_user_id()))) return;
        global $wpdb;
        $name = self::lock_name('order:' . $id);
        if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $name)) !== '1') {
            $message = __('Another operation is running. Reload the workspace shortly.', 'pc-order-import-export');
            if (wp_doing_ajax()) wp_send_json_error(['message' => $message], 409);
            wp_die(esc_html($message), '', ['response' => 409]);
        }
        register_shutdown_function(static function () use ($wpdb, $name): void {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
        });
    }

    public static function ajax(): void {
        if (!current_user_can('manage_woocommerce')) wp_send_json_error(['message' => __('You do not have permission to perform this action.', 'pc-order-import-export')], 403);
        check_ajax_referer('pcoe_manager', 'manager_nonce');
        try {
            $user = self::customer(absint($_POST['customer_id'] ?? 0));
            $operation = sanitize_key($_POST['operation'] ?? '');
            if (in_array($operation, ['pc_folio_customer_documents', 'pc_folio_customer_document_detail'], true)) {
                $context = pc_folio_balance_user_context($user->ID, false);
                if (!$context) throw new \RuntimeException(__('The customer is not linked to Folio.', 'pc-order-import-export'));
                add_filter('pc_folio_documents_request_context', static function () use ($context) { return $context; });
                if ($operation === 'pc_folio_customer_documents') pc_folio_documents_ajax_list();
                else pc_folio_documents_ajax_detail();
                return;
            }
            $id = absint($_POST['order_id'] ?? 0);
            $request_key = sanitize_text_field(wp_unslash($_POST['request_key'] ?? ''));
            if (!$id && !preg_match('/^[a-f0-9-]{36}$/i', $request_key)) {
                throw new \RuntimeException(__('Reload the workspace before creating a draft.', 'pc-order-import-export'));
            }
            $result = self::lock($id ? 'order:' . $id : 'new:' . get_current_user_id() . ':' . $request_key,
                static function () use ($operation, $user, $id, $request_key) {
                    $cache_key = 'pcoe_new_' . hash('sha256', get_current_user_id() . ':' . $user->ID . ':' . $request_key);
                    if (!$id && ($previous = get_transient($cache_key))) return $previous;
                    if (!$id) {
                        $result = self::create($operation, $user);
                        set_transient($cache_key, $result, DAY_IN_SECONDS);
                        return $result;
                    }
                    $order = self::order($id, $user->ID);
                    if ($operation === 'apply') return self::apply($order, $user);
                    if ($operation === 'copy') return self::copy($order, $user);
                    self::require_editable($order);
                    if (!hash_equals(self::revision($order), (string) ($_POST['revision'] ?? ''))) {
                        throw new \RuntimeException(__('The draft changed in another window. Reload it before continuing.', 'pc-order-import-export'));
                    }
                    if ($operation === 'save') return self::save($order, $user);
                    if ($operation === 'preview') return self::preview($order, $user);
                    throw new \RuntimeException(__('Unknown workspace action.', 'pc-order-import-export'));
                });
            wp_send_json_success($result);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()], 409);
        }
    }

    private static function create(string $operation, \WP_User $user): array {
        if ($operation === 'pc_folio_customer_document_repeat') {
            if (($_POST['target'] ?? '') !== 'draft') throw new \RuntimeException(__('Use a customer draft in the manager workspace.', 'pc-order-import-export'));
            $type = strtoupper(sanitize_key($_POST['document_type'] ?? ''));
            if (!in_array($type, ['ACCOUNT', 'EXPENSE'], true)) throw new \RuntimeException(__('Select a valid Folio document.', 'pc-order-import-export'));
            $context = pc_folio_balance_user_context($user->ID, false);
            if (!$context) throw new \RuntimeException(__('The customer is not linked to Folio.', 'pc-order-import-export'));
            $data = pc_folio_documents_fetch_detail($context, $type, absint($_POST['document_id'] ?? 0));
            if (is_wp_error($data)) throw new \RuntimeException($data->get_error_message());
            [$candidates, $skipped] = pc_folio_documents_repeat_candidates($data['document'], pc_folio_documents_selected_indexes(), 'draft');
            // Resolve all prices before creating a persistent draft.
            $prices = [];
            foreach ($candidates as $candidate) $prices[$candidate['product']->get_id()] = self::customer_price($candidate['product'], $user);
            $result = pc_folio_documents_create_repeat_draft($context, $data['document'], $candidates, $skipped);
            if (is_wp_error($result)) throw new \RuntimeException($result->get_error_message());
            $order = $result['order'];
            foreach ($order->get_items() as $item) {
                $total = (float) $prices[$item->get_product()->get_id()] * $item->get_quantity();
                $item->set_subtotal($total); $item->set_total($total); $item->save();
            }
            $order->calculate_totals(false);
            self::audit($order, __('Manager copied Folio items into the customer draft.', 'pc-order-import-export'));
            return ['result' => ['target' => 'draft', 'added' => $result['added'], 'skipped' => $result['skipped'], 'url' => self::url($user->ID, $order->get_id())]];
        }
        if (!in_array($operation, ['new', 'import'], true)) throw new \RuntimeException(__('Unknown workspace action.', 'pc-order-import-export'));
        $prepared = []; $report = '';
        if ($operation === 'import') {
            $file = $_FILES['file'] ?? [];
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '') || ($file['size'] ?? 0) > 10 * MB_IN_BYTES) {
                throw new \RuntimeException(__('Upload a CSV or Excel file up to 10 MB.', 'pc-order-import-export'));
            }
            $name = strtolower($file['name']);
            if (!in_array(pathinfo($name, PATHINFO_EXTENSION), ['csv', 'xls', 'xlsx'], true)) throw new \RuntimeException(__('Upload a CSV or Excel file up to 10 MB.', 'pc-order-import-export'));
            [$rows, $err] = Helpers::read_rows($file['tmp_name'], $name);
            if ($err || !$rows || count($rows) > 2001) throw new \RuntimeException($err ?: __('Use a file with 1 to 2000 product rows.', 'pc-order-import-export'));
            [$map, $start] = Helpers::detect_colmap_and_start($rows);
            $res = Helpers::process_rows_with_adder($rows, $map, $start, [
                'allow_price' => true, 'ok_label' => __('Added to draft', 'pc-order-import-export'),
                'adder' => static function (\WC_Product $product, float $qty, ?float $price, array &$extra) use (&$prepared, $user): bool {
                    if ($qty <= 0 || !is_finite($qty) || floor($qty) !== $qty) { $extra['reason'] = 'invalid_quantity'; return false; }
                    $prepared[] = [$product, $qty, $price !== null && is_finite($price) && $price >= 0 ? $price : (float) self::customer_price($product, $user)];
                    return true;
                },
            ]);
            $report = Helpers::render_report($res['report']);
            if (!$prepared) return ['report_html' => $report, 'message' => __('No rows were imported. Correct the file and try again.', 'pc-order-import-export')];
        }
        $order = self::new_draft($user);
        foreach ($prepared as [$product, $qty, $price]) $order->add_product($product, $qty, ['subtotal' => $qty * $price, 'total' => $qty * $price]);
        $order->update_meta_data('_pc_draft_title', sanitize_text_field(wp_unslash($_POST['title'] ?? '')));
        $order->calculate_totals(false);
        self::audit($order, __('Manager created a customer draft.', 'pc-order-import-export'));
        return ['url' => self::url($user->ID, $order->get_id()), 'report_html' => $report, 'message' => __('The customer draft was saved.', 'pc-order-import-export')];
    }

    private static function copy(\WC_Order $source, \WP_User $user): array {
        // Copy is itself idempotent per explicit request; source accounting metadata is never copied.
        $key = sanitize_text_field($_POST['request_key'] ?? '');
        if (!preg_match('/^[a-f0-9-]{36}$/i', $key)) throw new \RuntimeException(__('Reload the workspace before creating a draft.', 'pc-order-import-export'));
        $cache = 'pcoe_copy_' . hash('sha256', get_current_user_id() . ':' . $source->get_id() . ':' . $key);
        if ($url = get_transient($cache)) return ['url' => $url];
        $rows = [];
        foreach ($source->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) throw new \RuntimeException(__('A source product is no longer available. Copy selected Folio items instead.', 'pc-order-import-export'));
            $rows[] = [$product, $item->get_quantity(), self::customer_price($product, $user)];
        }
        $order = self::new_draft($user);
        foreach ($rows as [$product, $qty, $price]) $order->add_product($product, $qty, ['subtotal' => $qty * (float) $price, 'total' => $qty * (float) $price]);
        $order->update_meta_data('_pcoe_source_order_id', $source->get_id());
        $order->update_meta_data('_pc_draft_title', $source->get_meta('_pc_draft_title'));
        $order->calculate_totals(false);
        self::audit($order, __('Manager created a new copy using current customer prices.', 'pc-order-import-export'));
        $url = self::url($user->ID, $order->get_id()); set_transient($cache, $url, DAY_IN_SECONDS);
        return ['url' => $url];
    }

    private static function save(\WC_Order $order, \WP_User $user): array {
        $quantities = isset($_POST['quantities_json']) ? json_decode(wp_unslash($_POST['quantities_json']), true) : (array) ($_POST['quantity'] ?? []);
        if (!is_array($quantities)) throw new \RuntimeException(__('Enter whole quantities from 0 to 1000000.', 'pc-order-import-export'));
        $items = $order->get_items();
        $updates = [];
        foreach ($quantities as $id => $raw) {
            $qty = filter_var($raw, FILTER_VALIDATE_FLOAT);
            if (!isset($items[$id]) || $qty === false || !is_finite($qty) || $qty < 0 || floor($qty) !== $qty || $qty > 1000000) {
                throw new \RuntimeException(__('Enter whole quantities from 0 to 1000000.', 'pc-order-import-export'));
            }
            $updates[$id] = $qty;
        }
        $sku = trim(sanitize_text_field(wp_unslash($_POST['sku'] ?? '')));
        $product = $sku !== '' ? wc_get_product(wc_get_product_id_by_sku($sku)) : false;
        $add_qty = absint($_POST['add_quantity'] ?? 1);
        if ($sku !== '' && (!$product || !$add_qty || $add_qty > 1000000 || $product->is_type('variable'))) throw new \RuntimeException(__('Enter a valid product SKU and quantity.', 'pc-order-import-export'));
        $price = $product ? self::customer_price($product, $user) : 0;
        foreach ($updates as $id => $qty) {
            $item = $items[$id];
            if (!$qty) { $order->remove_item($id); continue; }
            $unit = $item->get_quantity() > 0 ? (float) $item->get_total() / $item->get_quantity() : 0;
            $item->set_quantity($qty); $item->set_subtotal($unit * $qty); $item->set_total($unit * $qty);
            $item->delete_meta_data('_pc_alloc_plan'); $item->save();
        }
        if ($product) $order->add_product($product, $add_qty, ['subtotal' => $add_qty * (float) $price, 'total' => $add_qty * (float) $price]);
        $order->update_meta_data('_pc_draft_title', sanitize_text_field(wp_unslash($_POST['title'] ?? '')));
        $order->set_customer_note(sanitize_textarea_field(wp_unslash($_POST['note'] ?? '')));
        $order->calculate_totals(false);
        self::audit($order, __('Manager updated the customer draft.', 'pc-order-import-export'));
        return ['url' => self::url($user->ID, $order->get_id())];
    }

    public static function payload(\WC_Order $order, \WP_User $user, string $mode, array $preference, string $key): array {
        if (!function_exists('pc_folio_build_order_preview_payload') || !function_exists('pc_build_alloc_plan')) throw new \RuntimeException(__('Folio order integration is unavailable.', 'pc-order-import-export'));
        if (!in_array($mode, ['accounts', 'non_accounting'], true) || !in_array($preference['mode'], ['auto', 'manual', 'single'], true)) throw new \RuntimeException(__('Select a valid warehouse mode.', 'pc-order-import-export'));
        $terms = get_terms(['taxonomy' => 'location', 'hide_empty' => false]);
        if (is_wp_error($terms)) throw new \RuntimeException(__('Warehouses are unavailable.', 'pc-order-import-export'));
        $locations = [];
        foreach ($terms as $term) {
            $warehouses = pc_folio_get_location_warehouses_for_preview($term->term_id);
            if ($warehouses) $locations[$term->term_id] = ['term' => $term, 'warehouses' => $warehouses];
        }
        if ($preference['mode'] !== 'auto' && !isset($locations[$preference['term_id']])) throw new \RuntimeException(__('Select a warehouse linked to Folio.', 'pc-order-import-export'));
        $payload = pc_folio_build_order_preview_payload($order);
        if (empty($payload['folio_client']['short_name'])) throw new \RuntimeException(__('The customer is not linked to Folio.', 'pc-order-import-export'));
        $payload['folio_account_header']['externalRequestId'] = $key;
        $payload['folio_account_header']['documentDate'] = current_time('Y-m-d') . 'T00:00:00';
        $payload['folio_account_header']['controlDate'] = wp_date('Y-m-d', time() + 3 * DAY_IN_SECONDS);
        $payload['folio_account_header']['accountingEnabled'] = $mode === 'accounts';
        $payload['woo_order']['status'] = $mode === 'accounts' ? 'on-hold' : 'pc-draft';
        $payload['woo_order']['total'] = 0;
        $used = []; $requested = [];
        $warehouse = DraftFolioWorkflow::default_warehouse_id();
        if ($mode === 'non_accounting') {
            if (!$warehouse) throw new \RuntimeException(__('Select the non-accounting Folio warehouse in Lavka settings.', 'pc-order-import-export'));
            $payload['split_strategy'] = 'single_non_accounting_warehouse';
            $payload['folio_account_header']['warehouseId'] = $warehouse;
            $payload['folio_account_header']['sourceInfo'] = 'нет на складе';
        }
        foreach ($payload['items'] as &$line) {
            $product = wc_get_product($line['product_id']);
            $qty = (float) $line['quantity'];
            if (!$product || !$line['sku'] || $product->is_type('variable') || $qty <= 0 || floor($qty) !== $qty) throw new \RuntimeException(__('Each line needs an existing product SKU and a positive whole quantity.', 'pc-order-import-export'));
            $line['unit_price'] = (float) self::customer_price($product, $user);
            $line['subtotal'] = $line['total'] = round($qty * $line['unit_price'], wc_get_price_decimals());
            $payload['woo_order']['total'] += $line['total'];
            $line['allocations'] = [];
            if ($mode === 'non_accounting') {
                $line['allocations'][] = ['woo_location_id' => 0, 'woo_location_slug' => 'non-accounting', 'woo_location_name' => pc_folio_warehouse_label($warehouse),
                    'quantity' => $qty, 'allocation_source' => 'pcoe_manager', 'folio_warehouses' => [['id' => (string) $warehouse, 'priority' => 0]]];
                continue;
            }
            $pid = $product->get_id();
            $requested[$pid] = ($requested[$pid] ?? 0) + (int) $qty;
            $cumulative = pc_build_alloc_plan($product, $requested[$pid], $preference);
            $plan = [];
            foreach ($cumulative as $tid => $q) {
                $delta = $q - ($used[$pid][$tid] ?? 0);
                if ($delta > 0) $plan[$tid] = $delta;
            }
            $used[$pid] = $cumulative;
            $remainder = $qty - array_sum($plan);
            if ($remainder > 0) {
                // Java owns the final availability check and the non-accounting shortage document.
                $fallback = $preference['mode'] !== 'auto' ? $preference['term_id'] : (array_key_first($plan) ?: array_key_first($locations));
                if (!$fallback) throw new \RuntimeException(__('Select a warehouse linked to Folio.', 'pc-order-import-export'));
                $plan[$fallback] = ($plan[$fallback] ?? 0) + $remainder;
            }
            foreach ($plan as $tid => $quantity) {
                if (!isset($locations[$tid])) throw new \RuntimeException(__('Select a warehouse linked to Folio.', 'pc-order-import-export'));
                $location = $locations[$tid];
                $line['allocations'][] = ['woo_location_id' => $tid, 'woo_location_slug' => $location['term']->slug,
                    'woo_location_name' => $location['term']->name, 'quantity' => $quantity,
                    'allocation_source' => 'pcoe_manager', 'folio_warehouses' => $location['warehouses']];
            }
        }
        unset($line);
        $errors = pc_folio_validate_order_payload_for_create($payload);
        if ($errors) throw new \RuntimeException(implode(' ', $errors));
        return $payload;
    }

    /** Validate business evidence before accepting a response, including aggregate conservation. */
    public static function validate_response(array $data, array $payload, bool $preview): void {
        $error = __('Folio returned an unexpected result. Check the saved operation before continuing.', 'pc-order-import-export');
        if (($data['ok'] ?? null) !== true || ($data['preview_only'] ?? null) !== $preview || (int) ($data['woo_order_id'] ?? 0) !== (int) $payload['woo_order']['id'] || !empty($data['errors']) || empty($data['documents'])) throw new \RuntimeException($error);
        $expected = []; $actual = []; $allowed_warehouses = [];
        foreach ($payload['items'] as $line) {
            $key = $line['sku'] . ':' . wc_format_decimal($line['unit_price'], 4);
            $expected[$key] = ($expected[$key] ?? 0) + $line['quantity'];
            foreach ($line['allocations'] as $allocation) foreach ($allocation['folio_warehouses'] as $warehouse) $allowed_warehouses[$key][(int) $warehouse['id']] = true;
        }
        foreach ($data['documents'] as $doc) {
            if (!str_starts_with((string) ($doc['source_external_request_id'] ?? ''), $payload['folio_account_header']['externalRequestId'] . ':')) throw new \RuntimeException($error);
            if (empty($doc['items']) || empty($doc['folio_warehouse_id']) || !is_bool($doc['accounting_enabled'] ?? null)
                || (!$preview && (empty($doc['document_id']) || empty($doc['document_number'])))) throw new \RuntimeException($error);
            if (!$payload['folio_account_header']['accountingEnabled'] && ($doc['accounting_enabled'] || (int) $doc['folio_warehouse_id'] !== (int) $payload['folio_account_header']['warehouseId'])) throw new \RuntimeException($error);
            foreach ($doc['items'] as $line) {
                $qty = (float) ($line['quantity'] ?? 0); $price = (float) ($line['price'] ?? -1);
                if ($qty <= 0 || $price < 0 || abs((float) ($line['amount'] ?? -1) - $qty * $price) > 0.02) throw new \RuntimeException($error);
                $key = ($line['sku'] ?? '') . ':' . wc_format_decimal($price, 4);
                $actual[$key] = ($actual[$key] ?? 0) + $qty;
                if ($doc['accounting_enabled'] && empty($allowed_warehouses[$key][(int) $doc['folio_warehouse_id']])) throw new \RuntimeException($error);
            }
        }
        ksort($expected); ksort($actual);
        if (array_keys($expected) !== array_keys($actual)) throw new \RuntimeException($error);
        foreach ($expected as $key => $qty) if (abs($qty - $actual[$key]) > 0.000001) throw new \RuntimeException($error);
    }

    private static function send(array $payload, bool $preview, ?callable $observe = null): array {
        $payload['preview_only'] = $preview;
        $response = pc_folio_order_link_java_post('/admin/folio/order-accounts', $payload, ['timeout' => 180]);
        if (is_wp_error($response)) throw new \RuntimeException(__('Folio did not confirm the result. Reload the workspace and check the operation status.', 'pc-order-import-export'));
        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($observe && is_array($data)) $observe($data);
        if ($code < 200 || $code >= 300 || !is_array($data)) throw new \RuntimeException(__('Folio did not confirm the result. Reload the workspace and check the operation status.', 'pc-order-import-export'));
        self::validate_response($data, $payload, $preview);
        return $data;
    }

    private static function preview(\WC_Order $order, \WP_User $user): array {
        $mode = sanitize_key($_POST['mode'] ?? 'accounts');
        $preference = ['mode' => sanitize_key($_POST['warehouse_mode'] ?? 'auto'), 'term_id' => absint($_POST['warehouse_id'] ?? 0)];
        $token = wp_generate_uuid4();
        $payload = self::payload($order, $user, $mode, $preference, $token);
        $response = self::send($payload, true);
        set_transient('pcoe_manager_preview_' . $token, ['actor' => get_current_user_id(), 'customer' => $user->ID,
            'order' => $order->get_id(), 'revision' => self::revision($order), 'mode' => $mode, 'preference' => $preference,
            'payload' => $payload, 'response' => $response], 15 * MINUTE_IN_SECONDS);
        ob_start(); self::documents_table($response); $html = ob_get_clean();
        return ['preview_html' => $html, 'token' => $token, 'message' => __('Preview only. No Folio documents have been created. Confirm after checking quantities, prices and warehouses.', 'pc-order-import-export')];
    }

    private static function apply(\WC_Order $order, \WP_User $user): array {
        if (($_POST['confirmation'] ?? '') !== '1') throw new \RuntimeException(__('Confirm the reviewed operation first.', 'pc-order-import-export'));
        $token = sanitize_text_field($_POST['token'] ?? '');
        $command = self::command($order);
        if ($command) {
            if (($command['token'] ?? '') === $token && ($command['status'] ?? '') === 'complete') return ['url' => self::url($user->ID, $order->get_id())];
            throw new \RuntimeException(__('This operation was already submitted. Check the saved result; automatic resubmission is blocked.', 'pc-order-import-export'));
        }
        self::require_editable($order);
        $preview = get_transient('pcoe_manager_preview_' . $token);
        if (!$preview || $preview['actor'] !== get_current_user_id() || $preview['customer'] !== $user->ID || $preview['order'] !== $order->get_id()
            || !hash_equals($preview['revision'], self::revision($order))) throw new \RuntimeException(__('The preview expired or the draft changed. Create a new preview.', 'pc-order-import-export'));
        $fresh = self::payload($order, $user, $preview['mode'], $preview['preference'], $token);
        if (wp_json_encode($fresh) !== wp_json_encode($preview['payload'])) throw new \RuntimeException(__('Customer prices or stock allocation changed. Create a new preview.', 'pc-order-import-export'));
        $command = ['token' => $token, 'status' => 'sending', 'actor' => get_current_user_id(), 'customer' => $user->ID, 'started_at' => current_time('mysql'), 'payload' => $fresh];
        $order->update_meta_data(self::COMMAND, $command); $order->save();
        try {
            // Never retry this POST automatically, including after an invalid/partial response.
            $response = self::send($fresh, false, static function (array $observed) use ($order, &$command): void {
                $command['observed_response'] = $observed;
                $order->update_meta_data(self::COMMAND, $command); $order->save();
            });
            $command['status'] = 'folio_saved'; $command['response'] = $response;
            $order->update_meta_data(self::COMMAND, $command); $order->save();
            pc_folio_save_order_java_response($order, $response, $fresh);
            foreach ($fresh['items'] as $line) {
                $item = $order->get_item($line['order_item_id']);
                $item->set_subtotal($line['subtotal']); $item->set_total($line['total']);
                $plan = [];
                foreach ($line['allocations'] as $allocation) if ($allocation['woo_location_id']) $plan[$allocation['woo_location_id']] = $allocation['quantity'];
                if (count($response['documents']) === 1 && $response['documents'][0]['accounting_enabled']) {
                    $plan = self::result_plan($fresh, $line['sku'], $line['quantity'], (int) $response['documents'][0]['folio_warehouse_id']);
                }
                $item->update_meta_data('_pc_alloc_plan', $plan); $item->save();
            }
            $order->calculate_totals(false);
            if ($preview['mode'] === 'accounts') {
                $applied = pc_folio_apply_saved_response_to_order($order);
                if (empty($applied['ok'])) throw new \RuntimeException(__('Folio was saved, but Woo order finalization needs review.', 'pc-order-import-export'));
                if (($applied['status'] ?? '') === 'ready_to_split') {
                    $split = pc_folio_create_child_orders_from_saved_response($order);
                    if (empty($split['ok'])) throw new \RuntimeException(__('Folio was saved, but Woo order finalization needs review.', 'pc-order-import-export'));
                    foreach (($split['child_order_ids'] ?? []) as $child_id) self::audit(wc_get_order($child_id), __('Created from the manager workspace.', 'pc-order-import-export'));
                }
            }
            $command['status'] = 'complete'; $order->update_meta_data(self::COMMAND, $command);
            self::audit($order, __('Manager confirmed Folio documents for this customer.', 'pc-order-import-export'));
            delete_transient('pcoe_manager_preview_' . $token);
            return ['url' => self::url($user->ID, $order->get_id())];
        } catch (\Throwable $e) {
            $command['status'] = isset($command['response']) ? 'needs_review' : 'unknown';
            $order->update_meta_data(self::COMMAND, $command); $order->save();
            throw new \RuntimeException(__('The operation needs review. Do not submit it again. Check the saved Folio documents and operation ID.', 'pc-order-import-export'));
        }
    }

    /** Child stock reduction must use its Folio warehouse, never the manager cart preference. */
    public static function stamp_child_plan(\WC_Order $parent, \WC_Order_Item_Product $item, array $line): void {
        $command = self::command($parent);
        if (!$command || ($line['allocation_status'] ?? '') !== 'allocated') return;
        $plan = self::result_plan((array) ($command['payload'] ?? []), (string) ($line['sku'] ?? ''),
            (float) ($line['quantity'] ?? 0), (int) ($line['folio_warehouse_id'] ?? 0));
        $item->update_meta_data('_pc_alloc_plan', $plan);
    }

    private static function result_plan(array $payload, string $sku, float $quantity, int $warehouse): array {
        $plan = []; $left = $quantity;
        foreach (($payload['items'] ?? []) as $source) {
            if ($source['sku'] !== $sku) continue;
            foreach ($source['allocations'] as $allocation) {
                $ids = array_map('intval', array_column($allocation['folio_warehouses'], 'id'));
                if (!in_array($warehouse, $ids, true) || !$allocation['woo_location_id']) continue;
                $take = min($left, (float) $allocation['quantity']);
                if ($take <= 0) continue;
                $tid = $allocation['woo_location_id']; $plan[$tid] = ($plan[$tid] ?? 0) + $take; $left -= $take;
            }
        }
        if ($left > 0.000001) throw new \RuntimeException(__('The Folio warehouse cannot be matched to the prepared stock allocation.', 'pc-order-import-export'));
        return $plan;
    }

    public static function documents_table(array $response): void {
        echo '<div class="pcoe-scroll"><table class="widefat striped"><thead><tr><th>' . esc_html__('Folio warehouse', 'pc-order-import-export') . '</th><th>' . esc_html__('Document', 'pc-order-import-export') . '</th><th>' . esc_html__('Items and amounts', 'pc-order-import-export') . '</th></tr></thead><tbody>';
        foreach (($response['documents'] ?? []) as $doc) {
            echo '<tr><td>' . esc_html(pc_folio_warehouse_label($doc['folio_warehouse_id'] ?? '')) . '</td><td>'
                . esc_html(($doc['accounting_enabled'] ?? false) ? __('Account with reservation', 'pc-order-import-export') : __('Non-accounting document', 'pc-order-import-export'));
            if (empty($response['preview_only'])) echo ' #' . esc_html($doc['document_number'] ?? '');
            echo '</td><td>';
            foreach (($doc['items'] ?? []) as $line) echo '<div>' . esc_html(($line['sku'] ?? '') . ' × ' . ($line['quantity'] ?? '') . ' · ' . ($line['price'] ?? '') . ' = ' . ($line['amount'] ?? '')) . '</div>';
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
}
