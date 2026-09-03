<?php
namespace PaintCore\PCOE;

defined('ABSPATH') || exit;

class DraftFolioWorkflow
{
    private const OPTION_WAREHOUSE_ID = 'pcoe_folio_non_accounting_warehouse_id';
    private const TRANSIENT_PREFIX = 'pcoe_draft_folio_preview_';
    private const PREVIEW_TTL = 30 * MINUTE_IN_SECONDS;

    public static function hooks(): void
    {
        add_action('admin_post_pcoe_draft_folio_preview', [self::class, 'handle_preview']);
        add_action('admin_post_pcoe_draft_folio_apply', [self::class, 'handle_apply']);
    }

    public static function default_warehouse_id(): int
    {
        $warehouse_id = defined('PCOE_FOLIO_NON_ACCOUNTING_WAREHOUSE_ID')
            ? (int) PCOE_FOLIO_NON_ACCOUNTING_WAREHOUSE_ID
            : (int) get_option(self::OPTION_WAREHOUSE_ID, 0);

        return max(0, (int) apply_filters('pcoe_folio_non_accounting_warehouse_id', $warehouse_id));
    }

    public static function render(\WC_Order $order): void
    {
        if (!$order->has_status('pc-draft') || !self::can_access($order)) {
            return;
        }

        $warehouse_id = self::default_warehouse_id();
        $warehouse_label = $warehouse_id > 0 ? self::warehouse_label($warehouse_id) : '';
        $has_documents = function_exists('pc_folio_order_has_saved_documents')
            && pc_folio_order_has_saved_documents($order);
        $token = isset($_GET['pcoe_folio_preview'])
            ? sanitize_key(wp_unslash($_GET['pcoe_folio_preview']))
            : '';

        echo '<section id="pcoe-draft-folio" class="pcoe-draft-folio">';
        echo '<h2>' . esc_html__('Draft processing', 'pc-order-import-export') . '</h2>';

        if ($warehouse_id <= 0) {
            echo '<div class="woocommerce-error" role="alert">'
                . esc_html__('The Folio warehouse for non-accounting drafts is not configured. Ask a manager to select it in Lavka settings.', 'pc-order-import-export')
                . '</div></section>';
            return;
        }

        if ($has_documents) {
            echo '<div class="woocommerce-info">'
                . esc_html__('A Folio document is already linked to this draft. Repeated creation is blocked.', 'pc-order-import-export')
                . '</div></section>';
            return;
        }

        if ($token !== '') {
            self::render_preview($order, $token);
        }

        $action = admin_url('admin-post.php');
        $nonce = wp_create_nonce('pcoe_draft_folio_preview_' . $order->get_id());

        echo '<p class="pcoe-draft-folio__warehouse">';
        /* translators: %s: customer-facing Folio warehouse name. */
        echo wp_kses_post(sprintf(
            __('Non-accounting Folio warehouse: %s', 'pc-order-import-export'),
            '<strong>' . esc_html($warehouse_label) . '</strong>'
        ));
        echo '</p>';

        echo '<div class="pcoe-draft-folio__actions">';
        self::render_preview_form(
            $action,
            $nonce,
            (int) $order->get_id(),
            'partial_to_cart',
            __('Load available quantities into the cart', 'pc-order-import-export'),
            __('Available quantities will replace the current cart. The unavailable remainder will stay in this draft and will be prepared as a non-accounting Folio document.', 'pc-order-import-export')
        );
        self::render_preview_form(
            $action,
            $nonce,
            (int) $order->get_id(),
            'whole_draft',
            __('Send the entire draft to a non-accounting Folio document', 'pc-order-import-export'),
            __('The cart will not change. The whole draft will be prepared in Folio without accounting stock movement.', 'pc-order-import-export')
        );
        echo '</div>';
        echo '<script>(function(){document.querySelectorAll("#pcoe-draft-folio form").forEach(function(form){form.addEventListener("submit",function(){var button=form.querySelector("button[type=submit]");if(!button||button.disabled){return;}button.disabled=true;button.textContent=' . wp_json_encode(__('Processing…', 'pc-order-import-export')) . ';});});}());</script>';
        echo '</section>';
    }

    private static function render_preview_form(
        string $action,
        string $nonce,
        int $order_id,
        string $mode,
        string $button,
        string $description
    ): void {
        echo '<form class="pcoe-draft-folio__action" action="' . esc_url($action) . '" method="post">';
        echo '<input type="hidden" name="action" value="pcoe_draft_folio_preview">';
        echo '<input type="hidden" name="order_id" value="' . esc_attr((string) $order_id) . '">';
        echo '<input type="hidden" name="mode" value="' . esc_attr($mode) . '">';
        echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '">';
        echo '<p>' . esc_html($description) . '</p>';
        echo '<button type="submit" class="button">' . esc_html($button) . '</button>';
        echo '</form>';
    }

    private static function render_preview(\WC_Order $order, string $token): void
    {
        $preview = self::get_preview($token);
        if (!$preview || (int) ($preview['order_id'] ?? 0) !== (int) $order->get_id()) {
            return;
        }

        $mode = (string) ($preview['mode'] ?? '');
        $analysis = isset($preview['analysis']) && is_array($preview['analysis'])
            ? $preview['analysis']
            : [];
        $rows = isset($analysis['rows']) && is_array($analysis['rows']) ? $analysis['rows'] : [];
        $last_error = trim((string) ($preview['last_error'] ?? ''));

        echo '<div class="pcoe-draft-folio__preview">';
        echo '<h3>' . esc_html__('Preview without recording', 'pc-order-import-export') . '</h3>';

        if ($last_error !== '') {
            echo '<div class="woocommerce-error" role="alert">' . esc_html($last_error) . '</div>';
        }

        echo '<table class="shop_table shop_table_responsive">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Product', 'pc-order-import-export') . '</th>';
        echo '<th>' . esc_html__('Requested', 'pc-order-import-export') . '</th>';
        echo '<th>' . esc_html__('To cart', 'pc-order-import-export') . '</th>';
        echo '<th>' . esc_html__('To non-accounting Folio document', 'pc-order-import-export') . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            echo '<tr>';
            echo '<td><strong>' . esc_html((string) ($row['sku'] ?? '')) . '</strong><br>'
                . esc_html((string) ($row['name'] ?? '')) . '</td>';
            echo '<td>' . esc_html(wc_format_localized_decimal((float) ($row['requested'] ?? 0))) . '</td>';
            echo '<td>' . esc_html(wc_format_localized_decimal((float) ($row['loadable'] ?? 0))) . '</td>';
            echo '<td>' . esc_html(wc_format_localized_decimal((float) ($row['unavailable'] ?? 0))) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        $documents = isset($preview['response']['documents']) && is_array($preview['response']['documents'])
            ? $preview['response']['documents']
            : [];
        if ($documents) {
            echo '<p><strong>' . esc_html__('Folio preview:', 'pc-order-import-export') . '</strong> ';
            $labels = [];
            foreach ($documents as $document) {
                if (!is_array($document)) {
                    continue;
                }
                $number = (string) ($document['document_number'] ?? ($document['documentNumber'] ?? ''));
                $labels[] = sprintf(
                    '%s, %s',
                    $number !== '' ? '#' . $number : __('without number', 'pc-order-import-export'),
                    self::warehouse_label((int) ($document['folio_warehouse_id'] ?? ($document['warehouseId'] ?? 0)))
                );
            }
            echo esc_html(implode('; ', $labels)) . '</p>';
        } elseif ($mode === 'partial_to_cart') {
            echo '<p>' . esc_html__('All requested quantities are currently available. No non-accounting Folio document will be created.', 'pc-order-import-export') . '</p>';
        }

        $apply_label = $mode === 'partial_to_cart'
            ? __('Confirm cart and Folio remainder', 'pc-order-import-export')
            : __('Confirm non-accounting Folio document', 'pc-order-import-export');
        $can_confirm = empty($preview['cart_prepared']) || !empty($preview['payload']);
        if ($can_confirm) {
            echo '<form action="' . esc_url(admin_url('admin-post.php')) . '" method="post" class="pcoe-draft-folio__confirm">';
            echo '<input type="hidden" name="action" value="pcoe_draft_folio_apply">';
            echo '<input type="hidden" name="order_id" value="' . esc_attr((string) $order->get_id()) . '">';
            echo '<input type="hidden" name="token" value="' . esc_attr($token) . '">';
            echo '<input type="hidden" name="_wpnonce" value="' . esc_attr(wp_create_nonce('pcoe_draft_folio_apply_' . $token)) . '">';
            echo '<button type="submit" class="button alt">' . esc_html($apply_label) . '</button> ';
            echo '<a class="button" href="' . esc_url(self::order_url($order)) . '">' . esc_html__('Cancel', 'pc-order-import-export') . '</a>';
            echo '</form>';
        } else {
            echo '<p>' . esc_html__('The cart is already prepared. Run the whole-draft preview below to retry recording the remaining draft in Folio.', 'pc-order-import-export') . '</p>';
        }
        echo '</div>';
    }

    public static function handle_preview(): void
    {
        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        self::verify_request($order_id, 'pcoe_draft_folio_preview_' . $order_id);
        $order = self::require_draft($order_id);
        $mode = isset($_POST['mode']) ? sanitize_key(wp_unslash($_POST['mode'])) : '';
        if (!in_array($mode, ['partial_to_cart', 'whole_draft'], true)) {
            wp_die(esc_html__('Unknown draft processing mode.', 'pc-order-import-export'), '', ['response' => 400]);
        }

        if (function_exists('pc_folio_order_has_saved_documents') && pc_folio_order_has_saved_documents($order)) {
            self::redirect_notice($order, __('A Folio document is already linked to this draft. Repeated creation is blocked.', 'pc-order-import-export'), 'error');
        }

        $warehouse_id = self::default_warehouse_id();
        if ($warehouse_id <= 0) {
            self::redirect_notice($order, __('The Folio warehouse for non-accounting drafts is not configured.', 'pc-order-import-export'), 'error');
        }

        try {
            $analysis = self::analyse($order, $mode);
            if (empty($analysis['rows'])) {
                self::redirect_notice($order, __('The draft has no valid product lines.', 'pc-order-import-export'), 'error');
            }

            $payload = [];
            $response = [];
            if ((float) ($analysis['unavailable_total'] ?? 0) > 0) {
                $payload = self::build_payload($order, $analysis, $warehouse_id);
                $preview_result = self::send_payload($payload, true);
                if (empty($preview_result['ok'])) {
                    self::redirect_notice($order, (string) ($preview_result['message'] ?? __('Folio preview failed.', 'pc-order-import-export')), 'error');
                }
                $response = (array) ($preview_result['response'] ?? []);
            }
        } catch (\Throwable $exception) {
            self::redirect_notice(
                $order,
                /* translators: %s: technical error returned while preparing the preview. */
                sprintf(__('Could not prepare the preview: %s', 'pc-order-import-export'), $exception->getMessage()),
                'error'
            );
        }

        $token = strtolower(wp_generate_password(32, false, false));
        $preview = [
            'owner_id'      => get_current_user_id(),
            'order_id'      => (int) $order->get_id(),
            'mode'          => $mode,
            'warehouse_id'  => $warehouse_id,
            'analysis'      => $analysis,
            'fingerprint'   => self::fingerprint($order, $analysis, $warehouse_id),
            'order_lines_fingerprint' => self::order_lines_fingerprint($order),
            'payload'       => $payload,
            'response'      => $response,
            'cart_prepared' => false,
            'last_error'    => '',
            'created_at'    => time(),
        ];
        set_transient(self::TRANSIENT_PREFIX . $token, $preview, self::PREVIEW_TTL);

        self::redirect_notice($order, __('Preview is ready. Check the quantities and confirm the operation.', 'pc-order-import-export'), 'notice', $token);
    }

    public static function handle_apply(): void
    {
        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $token = isset($_POST['token']) ? sanitize_key(wp_unslash($_POST['token'])) : '';
        self::verify_request($order_id, 'pcoe_draft_folio_apply_' . $token);
        $order = self::require_draft($order_id);
        $preview = self::get_preview($token);
        if (!$preview || (int) ($preview['order_id'] ?? 0) !== $order_id) {
            self::redirect_notice($order, __('The preview has expired. Run it again before confirming.', 'pc-order-import-export'), 'error');
        }

        $expected_order_lines = (string) ($preview['order_lines_fingerprint'] ?? '');
        if ($expected_order_lines === '' || !hash_equals($expected_order_lines, self::order_lines_fingerprint($order))) {
            delete_transient(self::TRANSIENT_PREFIX . $token);
            self::redirect_notice($order, __('The draft lines changed after preview. Review and run the preview again.', 'pc-order-import-export'), 'error');
        }

        $mode = (string) ($preview['mode'] ?? '');
        $warehouse_id = (int) ($preview['warehouse_id'] ?? 0);
        try {
            $analysis = self::analyse($order, $mode);
            $fingerprint = self::fingerprint($order, $analysis, $warehouse_id);
        } catch (\Throwable $exception) {
            self::redirect_notice(
                $order,
                /* translators: %s: technical error returned while validating the draft. */
                sprintf(__('Could not verify the draft before confirmation: %s', 'pc-order-import-export'), $exception->getMessage()),
                'error',
                $token
            );
        }
        if (empty($preview['cart_prepared']) && !hash_equals((string) ($preview['fingerprint'] ?? ''), $fingerprint)) {
            delete_transient(self::TRANSIENT_PREFIX . $token);
            self::redirect_notice($order, __('The draft or stock allocation changed after preview. Review and run the preview again.', 'pc-order-import-export'), 'error');
        }

        if ($mode === 'partial_to_cart' && empty($preview['cart_prepared'])) {
            try {
                $actual = self::prepare_cart_and_remainder($order, $analysis);
            } catch (\Throwable $exception) {
                /* translators: %s: technical error returned while preparing the cart. */
                $preview['last_error'] = sprintf(
                    __('Could not prepare the cart: %s', 'pc-order-import-export'),
                    $exception->getMessage()
                );
                set_transient(self::TRANSIENT_PREFIX . $token, $preview, self::PREVIEW_TTL);
                self::redirect_notice(
                    $order,
                    __('The cart operation did not finish. Check the cart and the draft before trying again.', 'pc-order-import-export'),
                    'error',
                    $token
                );
            }
            $expected_remainder = self::quantity_map($analysis, 'unavailable');
            $actual_remainder = self::quantity_map($actual, 'unavailable');
            if ($expected_remainder !== $actual_remainder) {
                try {
                    if ((float) ($actual['unavailable_total'] ?? 0) <= 0) {
                        delete_transient(self::TRANSIENT_PREFIX . $token);
                        self::redirect_to_cart(__('All available quantities were loaded into the cart. No Folio remainder document is needed.', 'pc-order-import-export'));
                    }
                    $payload = self::build_payload($order, $actual, $warehouse_id);
                    $preview_result = self::send_payload($payload, true);
                    if (empty($preview_result['ok'])) {
                        throw new \RuntimeException((string) ($preview_result['message'] ?? __('Folio preview failed.', 'pc-order-import-export')));
                    }
                    $preview['cart_prepared'] = true;
                    $preview['analysis'] = $actual;
                    $preview['fingerprint'] = self::fingerprint($order, $actual, $warehouse_id);
                    $preview['order_lines_fingerprint'] = self::order_lines_fingerprint($order);
                    $preview['payload'] = $payload;
                    $preview['response'] = (array) ($preview_result['response'] ?? []);
                    $preview['last_error'] = '';
                    set_transient(self::TRANSIENT_PREFIX . $token, $preview, self::PREVIEW_TTL);
                } catch (\Throwable $exception) {
                    $preview['cart_prepared'] = true;
                    $preview['analysis'] = $actual;
                    $preview['order_lines_fingerprint'] = self::order_lines_fingerprint($order);
                    $preview['payload'] = [];
                    $preview['response'] = [];
                    /* translators: %s: technical error returned by the updated Folio preview. */
                    $preview['last_error'] = sprintf(
                        __('The cart was prepared, but the updated Folio preview failed: %s', 'pc-order-import-export'),
                        $exception->getMessage()
                    );
                    set_transient(self::TRANSIENT_PREFIX . $token, $preview, self::PREVIEW_TTL);
                }
                self::redirect_notice(
                    $order,
                    __('Some quantities changed while the cart was being prepared. The actual remainder stays in the draft. Review the updated preview before creating the Folio document.', 'pc-order-import-export'),
                    'notice',
                    $token
                );
            }

            $preview['cart_prepared'] = true;
            $preview['analysis'] = $actual;
            $preview['fingerprint'] = self::fingerprint($order, $actual, $warehouse_id);
            $preview['order_lines_fingerprint'] = self::order_lines_fingerprint($order);
            set_transient(self::TRANSIENT_PREFIX . $token, $preview, self::PREVIEW_TTL);

            if (empty($preview['payload'])) {
                delete_transient(self::TRANSIENT_PREFIX . $token);
                self::redirect_to_cart(__('The available quantities were loaded into the cart. Continue with the standard checkout.', 'pc-order-import-export'));
            }
        }

        $payload = isset($preview['payload']) && is_array($preview['payload']) ? $preview['payload'] : [];
        if (!$payload) {
            delete_transient(self::TRANSIENT_PREFIX . $token);
            self::redirect_notice($order, __('There is nothing to send to Folio.', 'pc-order-import-export'), 'notice');
        }

        if (function_exists('pc_folio_order_has_saved_documents') && pc_folio_order_has_saved_documents($order)) {
            delete_transient(self::TRANSIENT_PREFIX . $token);
            self::redirect_notice($order, __('A Folio document is already linked to this draft. Repeated creation is blocked.', 'pc-order-import-export'), 'error');
        }

        $apply_result = self::send_payload($payload, false);
        if (empty($apply_result['ok'])) {
            $preview['last_error'] = (string) ($apply_result['message'] ?? __('Folio document creation failed.', 'pc-order-import-export'));
            set_transient(self::TRANSIENT_PREFIX . $token, $preview, self::PREVIEW_TTL);
            self::redirect_notice(
                $order,
                __('The Woo draft is safe, but the Folio document was not confirmed. Review the error and confirm manually; no automatic retry will be made.', 'pc-order-import-export'),
                'error',
                $token
            );
        }

        try {
            self::save_response($order, (array) $apply_result['response'], $payload, $warehouse_id, $mode);
        } catch (\Throwable $exception) {
            /* translators: %s: technical error returned while saving the Folio document link. */
            $preview['last_error'] = sprintf(
                __('Folio accepted the document, but Woo could not save its link: %s', 'pc-order-import-export'),
                $exception->getMessage()
            );
            set_transient(self::TRANSIENT_PREFIX . $token, $preview, self::PREVIEW_TTL);
            self::redirect_notice(
                $order,
                __('Do not create another Folio document manually. Contact a manager and retry confirmation with the same preview.', 'pc-order-import-export'),
                'error',
                $token
            );
        }
        delete_transient(self::TRANSIENT_PREFIX . $token);

        if ($mode === 'partial_to_cart') {
            if (function_exists('WC') && WC()->cart && !WC()->cart->is_empty()) {
                self::redirect_to_cart(__('Available quantities are in the cart. The unavailable remainder stays in the draft and was recorded in a non-accounting Folio document.', 'pc-order-import-export'));
            }
            self::redirect_notice($order, __('No products were available for the cart. The full draft remains saved and was recorded in a non-accounting Folio document.', 'pc-order-import-export'), 'notice');
        }

        self::redirect_notice($order, __('The entire draft was recorded in a non-accounting Folio document. The cart was not changed.', 'pc-order-import-export'), 'success');
    }

    private static function analyse(\WC_Order $order, string $mode): array
    {
        $rows = [];
        $loadable_total = 0.0;
        $unavailable_total = 0.0;
        $cumulative_requested = [];
        $cumulative_plans = [];

        foreach ($order->get_items('line_item') as $item_id => $item) {
            if (!($item instanceof \WC_Order_Item_Product)) {
                continue;
            }
            $requested = max(0.0, (float) wc_stock_amount($item->get_quantity()));
            if ($requested <= 0) {
                continue;
            }

            $product = $item->get_product();
            $plan = [];
            $loadable = 0.0;
            if ($mode === 'partial_to_cart' && $product instanceof \WC_Product && $product->is_purchasable()) {
                $product_key = (int) $product->get_id();
                $already_requested = (int) ($cumulative_requested[$product_key] ?? 0);
                $cumulative_plan = self::normalise_plan(function_exists('pc_calc_plan_for')
                    ? (array) pc_calc_plan_for($product, $already_requested + (int) $requested)
                    : []);
                $plan = self::subtract_plan($cumulative_plan, (array) ($cumulative_plans[$product_key] ?? []));
                $loadable = min($requested, (float) array_sum($plan));
                $cumulative_requested[$product_key] = $already_requested + (int) $requested;
                $cumulative_plans[$product_key] = $cumulative_plan;
            }
            $unavailable = max(0.0, $requested - $loadable);

            $rows[(int) $item_id] = [
                'item_id'     => (int) $item_id,
                'product_id'  => $product instanceof \WC_Product ? (int) $product->get_id() : 0,
                'sku'         => $product instanceof \WC_Product ? (string) $product->get_sku() : '',
                'name'        => (string) $item->get_name(),
                'requested'   => $requested,
                'loadable'    => $loadable,
                'unavailable' => $unavailable,
                'plan'        => $plan,
            ];
            $loadable_total += $loadable;
            $unavailable_total += $unavailable;
        }

        return [
            'rows'              => $rows,
            'loadable_total'    => $loadable_total,
            'unavailable_total' => $unavailable_total,
        ];
    }

    private static function build_payload(\WC_Order $order, array $analysis, int $warehouse_id): array
    {
        if (!function_exists('pc_folio_build_order_preview_payload')) {
            throw new \RuntimeException(__('Folio order integration is unavailable.', 'pc-order-import-export'));
        }
        $payload = pc_folio_build_order_preview_payload($order);
        if (!$payload) {
            throw new \RuntimeException(__('Could not build the Folio draft payload.', 'pc-order-import-export'));
        }

        $selected = self::quantity_map($analysis, 'unavailable');
        $items = [];
        $total = 0.0;
        foreach ((array) ($payload['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $item_id = (int) ($item['order_item_id'] ?? 0);
            $quantity = (float) ($selected[$item_id] ?? 0);
            if ($quantity <= 0) {
                continue;
            }
            if (trim((string) ($item['sku'] ?? '')) === '') {
                /* translators: %s: draft line name. */
                throw new \RuntimeException(sprintf(
                    __('The draft line “%s” has no SKU and cannot be sent to Folio.', 'pc-order-import-export'),
                    (string) ($item['name'] ?? ('#' . $item_id))
                ));
            }
            $unit_price = max(0.0, (float) ($item['unit_price'] ?? 0));
            $line_total = $unit_price * $quantity;
            $item['quantity'] = $quantity;
            $item['subtotal'] = $line_total;
            $item['total'] = $line_total;
            $item['allocations'] = [[
                'woo_location_id'   => 0,
                'woo_location_slug' => 'non-accounting',
                'woo_location_name' => self::warehouse_label($warehouse_id),
                'quantity'          => $quantity,
                'allocation_source' => 'pcoe_draft_remainder',
                'folio_warehouses'  => [[
                    'id'       => (string) $warehouse_id,
                    'priority' => 0,
                ]],
            ]];
            $items[] = $item;
            $total += $line_total;
        }

        if (!$items) {
            throw new \RuntimeException(__('There are no draft quantities to send to Folio.', 'pc-order-import-export'));
        }

        $payload['preview_only'] = true;
        $payload['split_strategy'] = 'single_non_accounting_warehouse';
        $payload['items'] = $items;
        $payload['woo_order']['status'] = 'pc-draft';
        $payload['woo_order']['total'] = $total;
        $payload['folio_account_header']['externalRequestId'] = function_exists('wp_generate_uuid4')
            ? wp_generate_uuid4()
            : md5(uniqid('', true));
        $payload['folio_account_header']['warehouseId'] = $warehouse_id;
        $payload['folio_account_header']['accountingEnabled'] = false;
        $payload['folio_account_header']['sourceInfo'] = 'нет на складе';

        return $payload;
    }

    private static function send_payload(array $payload, bool $preview_only): array
    {
        if (!function_exists('pc_folio_order_link_java_post')) {
            return ['ok' => false, 'message' => __('Folio order integration is unavailable.', 'pc-order-import-export')];
        }
        $payload['preview_only'] = $preview_only;
        if (function_exists('pc_folio_validate_order_payload_for_create')) {
            $errors = pc_folio_validate_order_payload_for_create($payload);
            if ($errors) {
                return ['ok' => false, 'message' => implode(' ', $errors)];
            }
        }

        $response = pc_folio_order_link_java_post('/admin/folio/order-accounts', $payload, ['timeout' => 180]);
        if (is_wp_error($response)) {
            return ['ok' => false, 'message' => $response->get_error_message()];
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        $data = json_decode($raw, true);
        if ($code < 200 || $code >= 300 || !is_array($data)) {
            $details = is_array($data)
                ? (string) ($data['details'] ?? ($data['message'] ?? ($data['title'] ?? '')))
                : '';
            return [
                'ok'      => false,
                'message' => $details !== ''
                    /* translators: 1: HTTP status code, 2: Folio error details. */
                    ? sprintf(__('Folio service returned HTTP %1$d: %2$s', 'pc-order-import-export'), $code, $details)
                    /* translators: %d: HTTP status code. */
                    : sprintf(__('Folio service returned HTTP %d.', 'pc-order-import-export'), $code),
                'raw'     => $raw,
            ];
        }
        if (empty($data['ok']) || !empty($data['errors'])) {
            $message = (string) ($data['details'] ?? ($data['message'] ?? __('Folio rejected the operation.', 'pc-order-import-export')));
            return ['ok' => false, 'message' => $message, 'response' => $data, 'raw' => $raw];
        }

        $response_error = self::validate_folio_response($data, $payload);
        if ($response_error !== '') {
            return ['ok' => false, 'message' => $response_error, 'response' => $data, 'raw' => $raw];
        }

        return ['ok' => true, 'response' => $data, 'raw' => $raw];
    }

    private static function validate_folio_response(array $response, array $payload): string
    {
        $documents = isset($response['documents']) && is_array($response['documents'])
            ? array_values($response['documents'])
            : [];
        if (count($documents) !== 1 || !is_array($documents[0])) {
            return __('Folio did not return exactly one non-accounting document. The operation was blocked.', 'pc-order-import-export');
        }

        $document = $documents[0];
        $expected_warehouse_id = (int) ($payload['folio_account_header']['warehouseId'] ?? 0);
        $actual_warehouse_id = (int) ($document['folio_warehouse_id'] ?? ($document['warehouseId'] ?? 0));
        if ($expected_warehouse_id <= 0 || $actual_warehouse_id !== $expected_warehouse_id) {
            return __('Folio returned a document for an unexpected warehouse. The operation was blocked.', 'pc-order-import-export');
        }

        $has_accounting_flag = array_key_exists('accounting_enabled', $document)
            || array_key_exists('accountingEnabled', $document);
        $accounting_value = $document['accounting_enabled'] ?? ($document['accountingEnabled'] ?? null);
        $accounting_enabled = filter_var($accounting_value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if (!$has_accounting_flag || $accounting_enabled !== false) {
            return __('Folio did not explicitly confirm a non-accounting document. The operation was blocked.', 'pc-order-import-export');
        }

        return '';
    }

    private static function prepare_cart_and_remainder(\WC_Order $order, array $analysis): array
    {
        if (!function_exists('WC') || !WC()->cart) {
            throw new \RuntimeException(__('Cart is not available.', 'pc-order-import-export'));
        }
        WC()->cart->empty_cart();

        $actual = $analysis;
        $groups = [];
        foreach ((array) ($analysis['rows'] ?? []) as $item_id => $row) {
            if (!is_array($row)) {
                continue;
            }
            $loadable = (float) ($row['loadable'] ?? 0);
            $item = $order->get_item((int) $item_id);
            $product = $item instanceof \WC_Order_Item_Product ? $item->get_product() : null;
            if ($loadable <= 0 || !($product instanceof \WC_Product)) {
                continue;
            }

            $group_key = (string) $product->get_id();
            if (!isset($groups[$group_key])) {
                $groups[$group_key] = [
                    'product'  => $product,
                    'quantity' => 0.0,
                    'plan'     => [],
                    'item_ids' => [],
                ];
            }
            $groups[$group_key]['quantity'] += $loadable;
            $groups[$group_key]['plan'] = self::merge_plans(
                (array) $groups[$group_key]['plan'],
                (array) ($row['plan'] ?? [])
            );
            $groups[$group_key]['item_ids'][] = (int) $item_id;
        }

        $added_item_ids = [];
        $added_cart_plans = [];
        foreach ($groups as $group) {
            $product = $group['product'] ?? null;
            if (!($product instanceof \WC_Product)) {
                continue;
            }
            $product_id = $product->is_type('variation') ? (int) $product->get_parent_id() : (int) $product->get_id();
            $variation_id = $product->is_type('variation') ? (int) $product->get_id() : 0;
            $variation = $product->is_type('variation') ? $product->get_variation_attributes() : [];
            $plan = self::normalise_plan((array) ($group['plan'] ?? []));
            $cart_key = WC()->cart->add_to_cart(
                $product_id,
                (float) ($group['quantity'] ?? 0),
                $variation_id,
                $variation,
                ['pc_alloc_plan' => $plan]
            );
            if (!$cart_key) {
                continue;
            }
            $added_cart_plans[(string) $cart_key] = $plan;
            foreach ((array) ($group['item_ids'] ?? []) as $item_id) {
                $added_item_ids[(int) $item_id] = true;
            }
        }

        // The regular add-to-cart hook recalculates plans after every line.
        // Restore the exact cumulative plans calculated for this draft.
        foreach ($added_cart_plans as $cart_key => $plan) {
            if (isset(WC()->cart->cart_contents[$cart_key])) {
                WC()->cart->cart_contents[$cart_key]['pc_alloc_plan'] = $plan;
            }
        }

        foreach ((array) ($analysis['rows'] ?? []) as $item_id => $row) {
            if (!is_array($row)) {
                continue;
            }
            $requested = (float) ($row['requested'] ?? 0);
            $added = isset($added_item_ids[(int) $item_id]) ? (float) ($row['loadable'] ?? 0) : 0.0;

            $remainder = max(0.0, $requested - $added);
            $actual['rows'][(int) $item_id]['loadable'] = $added;
            $actual['rows'][(int) $item_id]['unavailable'] = $remainder;
            self::set_draft_item_quantity($order, (int) $item_id, $requested, $remainder);
        }

        $actual['loadable_total'] = array_sum(array_column($actual['rows'], 'loadable'));
        $actual['unavailable_total'] = array_sum(array_column($actual['rows'], 'unavailable'));
        $order->calculate_totals(false);
        $order->update_meta_data('_pcoe_cart_prepared_at', current_time('mysql'));
        /* translators: 1: quantity added to cart, 2: quantity left in draft. */
        $order->add_order_note(sprintf(
            __('Draft loaded into cart: %1$s units added, %2$s units kept in the draft.', 'pc-order-import-export'),
            wc_format_decimal((float) $actual['loadable_total']),
            wc_format_decimal((float) $actual['unavailable_total'])
        ));
        $order->save();
        WC()->cart->calculate_totals();
        WC()->cart->set_session();

        return $actual;
    }

    private static function set_draft_item_quantity(\WC_Order $order, int $item_id, float $requested, float $remainder): void
    {
        if ($remainder <= 0) {
            $order->remove_item($item_id);
            return;
        }
        $item = $order->get_item($item_id);
        if (!($item instanceof \WC_Order_Item_Product)) {
            return;
        }
        $ratio = $requested > 0 ? ($remainder / $requested) : 0;
        $item->set_quantity($remainder);
        $item->set_subtotal((float) $item->get_subtotal() * $ratio);
        $item->set_total((float) $item->get_total() * $ratio);
        $taxes = $item->get_taxes();
        foreach (['subtotal', 'total'] as $part) {
            foreach ((array) ($taxes[$part] ?? []) as $tax_id => $amount) {
                $taxes[$part][$tax_id] = (float) $amount * $ratio;
            }
        }
        $item->set_taxes($taxes);
        $item->delete_meta_data('_pc_alloc_plan');
        $item->save();
    }

    private static function save_response(
        \WC_Order $order,
        array $response,
        array $payload,
        int $warehouse_id,
        string $mode
    ): void {
        if (!function_exists('pc_folio_set_order_documents_result')) {
            throw new \RuntimeException(__('Could not save the Folio response on the draft.', 'pc-order-import-export'));
        }
        pc_folio_set_order_documents_result($order, $response);
        $documents = isset($response['documents']) && is_array($response['documents']) ? $response['documents'] : [];
        if (
            count($documents) === 1
            && is_array($documents[0])
            && function_exists('pc_folio_get_single_document_link')
            && function_exists('pc_folio_set_order_document_link')
        ) {
            $hash = hash('sha256', (string) wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            pc_folio_set_order_document_link($order, pc_folio_get_single_document_link($documents[0], $hash));
        }
        $order->update_meta_data('_pcoe_folio_non_accounting_warehouse_id', $warehouse_id);
        $order->update_meta_data('_pcoe_folio_non_accounting_mode', $mode);
        $order->update_meta_data('_pcoe_folio_non_accounting_created_at', current_time('mysql'));
        /* translators: %s: customer-facing Folio warehouse name. */
        $order->add_order_note(sprintf(
            __('A non-accounting Folio document was created on warehouse %s. The draft status was preserved.', 'pc-order-import-export'),
            self::warehouse_label($warehouse_id)
        ));
        $order->save();
    }

    private static function normalise_plan(array $plan): array
    {
        $normalised = [];
        foreach ($plan as $term_id => $quantity) {
            $term_id = (int) $term_id;
            $quantity = max(0, (int) $quantity);
            if ($term_id > 0 && $quantity > 0) {
                $normalised[$term_id] = $quantity;
            }
        }
        return $normalised;
    }

    private static function subtract_plan(array $plan, array $used): array
    {
        $remaining = [];
        foreach (self::normalise_plan($plan) as $term_id => $quantity) {
            $quantity -= (int) ($used[$term_id] ?? 0);
            if ($quantity > 0) {
                $remaining[$term_id] = $quantity;
            }
        }
        return $remaining;
    }

    private static function merge_plans(array $left, array $right): array
    {
        $merged = self::normalise_plan($left);
        foreach (self::normalise_plan($right) as $term_id => $quantity) {
            $merged[$term_id] = (int) ($merged[$term_id] ?? 0) + $quantity;
        }
        return $merged;
    }

    private static function quantity_map(array $analysis, string $field): array
    {
        $map = [];
        foreach ((array) ($analysis['rows'] ?? []) as $item_id => $row) {
            $quantity = is_array($row) ? (float) ($row[$field] ?? 0) : 0;
            if ($quantity > 0) {
                $map[(int) $item_id] = wc_format_decimal($quantity, 6);
            }
        }
        ksort($map, SORT_NUMERIC);
        return $map;
    }

    private static function fingerprint(\WC_Order $order, array $analysis, int $warehouse_id): string
    {
        $rows = [];
        foreach ((array) ($analysis['rows'] ?? []) as $item_id => $row) {
            $rows[(int) $item_id] = [
                'product_id'  => (int) ($row['product_id'] ?? 0),
                'requested'   => wc_format_decimal((float) ($row['requested'] ?? 0), 6),
                'loadable'    => wc_format_decimal((float) ($row['loadable'] ?? 0), 6),
                'unavailable' => wc_format_decimal((float) ($row['unavailable'] ?? 0), 6),
                'plan'        => (array) ($row['plan'] ?? []),
            ];
        }
        ksort($rows, SORT_NUMERIC);
        return hash('sha256', (string) wp_json_encode([
            'order_id'     => (int) $order->get_id(),
            'order_status' => (string) $order->get_status(),
            'warehouse_id' => $warehouse_id,
            'rows'         => $rows,
        ]));
    }

    private static function order_lines_fingerprint(\WC_Order $order): string
    {
        $rows = [];
        foreach ($order->get_items('line_item') as $item_id => $item) {
            if (!($item instanceof \WC_Order_Item_Product)) {
                continue;
            }
            $rows[(int) $item_id] = [
                'product_id' => (int) $item->get_product_id(),
                'variation_id' => (int) $item->get_variation_id(),
                'quantity' => wc_format_decimal((float) $item->get_quantity(), 6),
                'subtotal' => wc_format_decimal((float) $item->get_subtotal(), 6),
                'total' => wc_format_decimal((float) $item->get_total(), 6),
            ];
        }
        ksort($rows, SORT_NUMERIC);

        return hash('sha256', (string) wp_json_encode($rows));
    }

    private static function get_preview(string $token): array
    {
        if ($token === '') {
            return [];
        }
        $preview = get_transient(self::TRANSIENT_PREFIX . $token);
        if (!is_array($preview) || (int) ($preview['owner_id'] ?? 0) !== get_current_user_id()) {
            return [];
        }
        return $preview;
    }

    private static function require_draft(int $order_id): \WC_Order
    {
        $order = wc_get_order($order_id);
        if (!($order instanceof \WC_Order)) {
            wp_die(esc_html__('Order not found.', 'pc-order-import-export'), '', ['response' => 404]);
        }
        if (!self::can_access($order)) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'pc-order-import-export'), '', ['response' => 403]);
        }
        if (!$order->has_status('pc-draft')) {
            wp_die(esc_html__('Only a draft order can use this operation.', 'pc-order-import-export'), '', ['response' => 409]);
        }
        return $order;
    }

    private static function verify_request(int $order_id, string $action): void
    {
        if ($order_id <= 0 || !isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), $action)) {
            wp_die(esc_html__('Security check failed (bad nonce).', 'pc-order-import-export'), '', ['response' => 403]);
        }
    }

    private static function can_access(\WC_Order $order): bool
    {
        return current_user_can('manage_woocommerce')
            || ((int) $order->get_user_id() > 0 && (int) $order->get_user_id() === get_current_user_id());
    }

    private static function warehouse_label(int $warehouse_id): string
    {
        if ($warehouse_id <= 0) {
            return __('warehouse is not selected', 'pc-order-import-export');
        }
        if (function_exists('pc_folio_warehouse_label')) {
            return (string) pc_folio_warehouse_label((string) $warehouse_id);
        }
        /* translators: %d: Folio warehouse ID. */
        return sprintf(__('Folio warehouse %d', 'pc-order-import-export'), $warehouse_id);
    }

    private static function order_url(\WC_Order $order): string
    {
        if (current_user_can('manage_woocommerce')) {
            return (string) $order->get_edit_order_url();
        }
        return (string) $order->get_view_order_url();
    }

    private static function redirect_notice(
        \WC_Order $order,
        string $message,
        string $type = 'notice',
        string $token = ''
    ): void {
        if (function_exists('wc_add_notice')) {
            wc_add_notice($message, $type);
        }
        $url = self::order_url($order);
        if ($token !== '') {
            $url = add_query_arg('pcoe_folio_preview', $token, $url) . '#pcoe-draft-folio';
        }
        wp_safe_redirect($url);
        exit;
    }

    private static function redirect_to_cart(string $message): void
    {
        if (function_exists('wc_add_notice')) {
            wc_add_notice($message, 'success');
        }
        wp_safe_redirect(wc_get_cart_url() ?: home_url('/'));
        exit;
    }
}
