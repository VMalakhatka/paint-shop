<?php
namespace PaintCore\PCOE;

defined('ABSPATH') || exit;

/** Customer planning lists. Never relax Woo cart/checkout stock validation. */
final class CatalogDraft
{
    private static bool $rendered = false;

    public static function hooks(): void
    {
        add_filter('pcoe_catalog_draft_mode', [self::class, 'active']);
        add_action('wp_ajax_pcoe_catalog_draft', [self::class, 'ajax']);
        add_action('woocommerce_before_main_content', [self::class, 'toolbar'], 15);
        add_action('woocommerce_before_shop_loop', [self::class, 'toolbar'], 5);
        add_action('pcoe_catalog_toolbar', [self::class, 'toolbar']);
        add_action('generate_before_main_content', [self::class, 'toolbar']);
        add_filter('woocommerce_loop_add_to_cart_link', [self::class, 'loop_button'], 1000, 2);
        add_action('wp', [self::class, 'single_setup'], 100);
        add_action('wp_enqueue_scripts', [self::class, 'assets'], 120);
        add_filter('body_class', static function ($classes) {
            if (self::active() && !is_cart() && !is_checkout()) $classes[] = 'pcoe-draft-mode';
            return $classes;
        });
        add_filter('option_woocommerce_hide_out_of_stock_items', static function ($value) {
            $suggest = wp_doing_ajax() && ($_REQUEST['action'] ?? '') === 'psu_catalog_suggest';
            return self::active() && ($suggest || (!is_admin() && !is_cart() && !is_checkout())) ? 'no' : $value;
        });
        add_action('woocommerce_view_order', [self::class, 'editor'], 5);
    }

    public static function active(): bool
    {
        return get_current_user_id() > 0 && get_user_meta(get_current_user_id(), '_pcoe_catalog_mode', true) === 'draft';
    }

    public static function editable($order): bool
    {
        return $order instanceof \WC_Order && get_current_user_id() > 0
            && (int) $order->get_customer_id() === get_current_user_id()
            && function_exists('pc_folio_order_has_saved_documents')
            && ManagerWorkspace::editable($order);
    }

    public static function eligible($product): bool
    {
        if (!$product instanceof \WC_Product || !in_array($product->get_type(), ['simple', 'variable', 'variation'], true)
            || $product->get_status() !== 'publish') return false;
        $parent = $product->is_type('variation') ? wc_get_product($product->get_parent_id()) : $product;
        if (!$parent || $parent->get_status() !== 'publish' || $parent->get_catalog_visibility() === 'hidden'
            || post_password_required($parent->get_id())) return false;
        if (!apply_filters('woocommerce_product_is_visible', true, $parent->get_id())) return false;
        if (class_exists('PSU_Category_Menu')) {
            $index = \PSU_Category_Menu::index();
            if (is_wp_error($index)) return false;
            $categories = $parent->get_category_ids();
            if ($categories && !array_diff($categories, array_keys($index['blocked']))) return false;
        }
        return true;
    }

    /** Quantities are whole units, as in the catalogue; no inventory-based ceiling. */
    public static function quantity($value, bool $allow_zero = false): int
    {
        if (!is_scalar($value) || !preg_match('/^\d{1,9}$/D', (string) $value)
            || (!$allow_zero && (int) $value === 0)) {
            throw new \RuntimeException(__('Enter a positive whole quantity.', 'pc-order-import-export'));
        }
        return (int) $value;
    }

    public static function assets(): void
    {
        if (!is_user_logged_in() || is_admin()) return;
        if (!(is_woocommerce() || is_account_page() || is_front_page()
            || has_shortcode((string) get_post_field('post_content', get_queried_object_id()), 'pc_quick_order'))) return;
        wp_enqueue_style('pcoe-catalog-draft', PCOE_URL . 'assets/catalog-draft.css', [], filemtime(PCOE_DIR . '/assets/catalog-draft.css'));
        wp_enqueue_script('pcoe-catalog-draft', PCOE_URL . 'assets/catalog-draft.js', [], filemtime(PCOE_DIR . '/assets/catalog-draft.js'), true);
        wp_localize_script('pcoe-catalog-draft', 'pcoeCatalogDraft', [
            'url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('pcoe_catalog_draft'),
            'selected' => (int) get_user_meta(get_current_user_id(), '_pcoe_catalog_draft', true),
            'saving' => __('Saving…', 'pc-order-import-export'),
            'unknown' => __('Could not confirm the save. Open the draft and check it before trying again.', 'pc-order-import-export'),
            'select' => __('Select or create a draft first.', 'pc-order-import-export'),
            'saved' => __('Saved to draft.', 'pc-order-import-export'),
        ]);
    }

    public static function toolbar(): void
    {
        if (self::$rendered || !is_user_logged_in() || is_cart() || is_checkout() || is_account_page()) return;
        if (!(is_woocommerce() || is_front_page() || has_shortcode((string) get_post_field('post_content', get_queried_object_id()), 'pc_quick_order'))) return;
        self::$rendered = true;
        $active = self::active();
        echo '<section class="pcoe-catalog-toolbar" aria-label="' . esc_attr__('Shopping mode', 'pc-order-import-export') . '">';
        echo '<div class="pcoe-mode-switch">';
        foreach (['purchase' => __('Purchase', 'pc-order-import-export'), 'draft' => __('Draft', 'pc-order-import-export')] as $mode => $label) {
            echo '<button type="button" data-pcoe-mode="' . esc_attr($mode) . '" aria-pressed="' . (($mode === 'draft') === $active ? 'true' : 'false') . '">' . esc_html($label) . '</button>';
        }
        echo '</div>';
        if ($active) {
            $selected = (int) get_user_meta(get_current_user_id(), '_pcoe_catalog_draft', true);
            $orders = wc_get_orders(['customer_id' => get_current_user_id(), 'status' => 'pc-draft', 'limit' => -1, 'orderby' => 'date', 'order' => 'DESC']);
            echo '<label class="pcoe-draft-picker">' . esc_html__('Selected draft', 'pc-order-import-export') . '<select data-pcoe-draft><option value="">' . esc_html__('Select a draft', 'pc-order-import-export') . '</option>';
            $current = null;
            foreach ($orders as $order) {
                if (!self::editable($order)) continue;
                if ($selected === $order->get_id()) $current = $order;
                echo '<option value="' . (int) $order->get_id() . '" ' . selected($selected, $order->get_id(), false) . '>#' . (int) $order->get_id() . ' ' . esc_html($order->get_meta('_pc_draft_title')) . '</option>';
            }
            echo '</select></label>';
            if ($current) echo '<a data-pcoe-open href="' . esc_url($current->get_view_order_url()) . '">' . esc_html__('Open draft', 'pc-order-import-export') . '</a>';
            echo '<details class="pcoe-new-draft"><summary>' . esc_html__('New draft', 'pc-order-import-export') . '</summary><form data-pcoe-create><label>' . esc_html__('Draft title', 'pc-order-import-export') . '<input name="title" maxlength="120" required></label><button type="submit">' . esc_html__('Create draft', 'pc-order-import-export') . '</button></form></details>';
            echo '<p class="pcoe-draft-notice">' . esc_html__('Draft: no stock reservation. Prices and availability are checked when ordering.', 'pc-order-import-export') . '</p>';
        }
        DraftHelp::render();
        echo '<p class="pcoe-draft-status" role="status" aria-live="polite"></p></section>';
    }

    public static function loop_button($html, $product): string
    {
        return self::active() ? self::product_form($product, false) : $html;
    }

    public static function single_setup(): void
    {
        if (!self::active() || !is_product()) return;
        remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30);
        add_action('woocommerce_single_product_summary', static function () {
            global $product;
            echo self::product_form($product, true);
        }, 30);
    }

    public static function product_form($product, bool $single = false): string
    {
        if (!self::eligible($product)) return '';
        if ($product->is_type('variable') && !$single) {
            return '<a class="button pcoe-choose-variant" href="' . esc_url($product->get_permalink()) . '">' . esc_html__('Choose a variant', 'pc-order-import-export') . '</a>';
        }
        $html = '<form class="pcoe-product-draft" data-product="' . (int) $product->get_id() . '">';
        if ($product->is_type('variable')) {
            foreach ($product->get_variation_attributes() as $attribute => $options) {
                $html .= '<label>' . esc_html(wc_attribute_label($attribute)) . '<select required name="attribute_' . esc_attr(sanitize_title($attribute)) . '"><option value="">' . esc_html__('Choose a variant', 'pc-order-import-export') . '</option>';
                foreach ($options as $option) {
                    $term = taxonomy_exists($attribute) ? get_term_by('slug', $option, $attribute) : null;
                    $html .= '<option value="' . esc_attr($option) . '">' . esc_html($term ? $term->name : $option) . '</option>';
                }
                $html .= '</select></label>';
            }
        }
        $html .= '<label>' . esc_html__('Quantity', 'pc-order-import-export') . '<input name="quantity" type="number" min="1" step="1" value="1" required inputmode="numeric"></label>';
        return $html . '<button type="submit">' . esc_html__('Add to draft', 'pc-order-import-export') . '</button><span class="pcoe-draft-status" role="status" aria-live="polite"></span></form>';
    }

    public static function editor($id): void
    {
        $order = wc_get_order($id);
        if (!self::editable($order)) return;
        echo '<details class="pcoe-draft-editor"><summary>' . esc_html__('Edit draft quantities', 'pc-order-import-export') . '</summary><form data-pcoe-update data-order="' . (int) $order->get_id() . '">';
        echo '<input type="hidden" name="revision" value="' . esc_attr(ManagerWorkspace::revision($order)) . '">';
        foreach ($order->get_items() as $item_id => $item) {
            echo '<label>' . esc_html($item->get_name()) . '<input type="number" name="items[' . (int) $item_id . ']" min="0" step="1" value="' . esc_attr($item->get_quantity()) . '" required></label>';
        }
        echo '<p>' . esc_html__('Set quantity to zero to remove an item.', 'pc-order-import-export') . '</p><button type="submit">' . esc_html__('Save quantities', 'pc-order-import-export') . '</button><span class="pcoe-draft-status" role="status"></span></form></details>';
    }

    public static function ajax(): void
    {
        if (!is_user_logged_in()) wp_send_json_error(['message' => __('Please sign in.', 'pc-order-import-export')], 403);
        check_ajax_referer('pcoe_catalog_draft', 'nonce');
        try {
            $result = self::execute(wp_unslash($_POST));
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e instanceof \RuntimeException ? $e->getMessage() : __('Could not save the draft.', 'pc-order-import-export')], 400);
        }
        wp_send_json_success($result);
    }

    public static function execute(array $data): array
    {
        if (!get_current_user_id()) throw new \RuntimeException(__('Please sign in.', 'pc-order-import-export'));
        $operation = sanitize_key($data['operation'] ?? '');
        if ($operation === 'mode') {
            if (!in_array($data['mode'] ?? '', ['purchase', 'draft'], true)) throw new \RuntimeException(__('Invalid draft request.', 'pc-order-import-export'));
            update_user_meta(get_current_user_id(), '_pcoe_catalog_mode', $data['mode']);
            return [];
        }
        $id = absint($data['order_id'] ?? 0);
        if ($operation === 'select') {
            if (!self::editable(wc_get_order($id))) throw new \RuntimeException(__('Select an editable draft belonging to your account.', 'pc-order-import-export'));
            update_user_meta(get_current_user_id(), '_pcoe_catalog_draft', $id);
            return [];
        }
        if (!in_array($operation, ['create', 'add', 'update'], true)) throw new \RuntimeException(__('Invalid draft request.', 'pc-order-import-export'));
        $key = $data['request_key'] ?? '';
        if (!is_string($key) || !preg_match('/^[a-zA-Z0-9-]{16,80}$/D', $key)) throw new \RuntimeException(__('Invalid draft request.', 'pc-order-import-export'));
        $fingerprint = hash('sha256', wp_json_encode([$operation, $id, $data['product_id'] ?? '', $data['attributes'] ?? [], $data['quantity'] ?? '', $data['items'] ?? [], $data['title'] ?? '']));
        // Same connection lock as ManagerWorkspace and the Folio processing handlers.
        global $wpdb;
        $lock = 'pcoe:' . substr(hash('sha256', $wpdb->prefix . ($operation === 'create' ? 'catalog-new:' . get_current_user_id() : 'order:' . $id)), 0, 48);
        if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock)) !== '1') throw new \RuntimeException(__('Another operation is running. Reload the workspace shortly.', 'pc-order-import-export'));
        try {
            wc_transaction_query('start');
            if ($operation === 'create') wp_cache_delete(get_current_user_id(), 'user_meta');
            $order = $operation === 'create' ? null : wc_get_order($id);
            if ($operation !== 'create' && !self::editable($order)) throw new \RuntimeException(__('Select an editable draft belonging to your account.', 'pc-order-import-export'));
            $receipt_key = '_pcoe_catalog_request_' . hash('sha256', $key);
            $receipt = $order ? $order->get_meta($receipt_key) : get_user_meta(get_current_user_id(), $receipt_key, true);
            if (is_array($receipt)) {
                if ($receipt['fingerprint'] !== $fingerprint) throw new \RuntimeException(__('Invalid draft request.', 'pc-order-import-export'));
                wc_transaction_query('commit');
                return $receipt['result'];
            }
            if ($operation === 'create') {
                $title = sanitize_text_field($data['title'] ?? '');
                if ($title === '' || mb_strlen($title) > 120) throw new \RuntimeException(__('Enter a draft title (up to 120 characters).', 'pc-order-import-export'));
                $order = new \WC_Order();
                $order->set_status('pc-draft');
                $order->set_customer_id(get_current_user_id());
                $order->set_created_via('pcoe-catalog');
                $customer = new \WC_Customer(get_current_user_id());
                $order->set_address($customer->get_billing('edit'), 'billing');
                $order->set_address($customer->get_shipping('edit'), 'shipping');
                $order->update_meta_data('_pc_draft_title', $title);
                $order->save();
            } elseif ($operation === 'add') {
                self::add($order, $data);
            } else {
                self::update($order, $data);
            }
            $order->calculate_totals(false);
            $order->save();
            $result = ['order_id' => $order->get_id(), 'url' => $order->get_view_order_url()];
            $receipt = ['fingerprint' => $fingerprint, 'result' => $result];
            if ($operation === 'create') update_user_meta(get_current_user_id(), $receipt_key, $receipt);
            else { $order->update_meta_data($receipt_key, $receipt); $order->save(); }
            update_user_meta(get_current_user_id(), '_pcoe_catalog_draft', $order->get_id());
            wc_transaction_query('commit');
            return $result;
        } catch (\Throwable $e) {
            wc_transaction_query('rollback');
            throw $e;
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    private static function add(\WC_Order $order, array $data): void
    {
        $product = wc_get_product(absint($data['product_id'] ?? 0));
        $attributes = [];
        if ($product && $product->is_type('variation') && in_array('', $product->get_attributes(), true)) {
            throw new \RuntimeException(__('Choose a variant', 'pc-order-import-export'));
        }
        if ($product && $product->is_type('variable')) {
            foreach ($product->get_variation_attributes() as $name => $options) {
                $key = 'attribute_' . sanitize_title($name);
                $value = $data['attributes'][$key] ?? '';
                if (!is_string($value) || $value === '' || !in_array($value, $options, true)) throw new \RuntimeException(__('Choose a variant', 'pc-order-import-export'));
                $attributes[$key] = $value;
            }
            $variation_id = \WC_Data_Store::load('product')->find_matching_product_variation($product, $attributes);
            $variation = wc_get_product($variation_id);
            if (!$variation || !$variation->is_type('variation') || $variation->get_parent_id() !== $product->get_id()
                ) throw new \RuntimeException(__('Choose a variant', 'pc-order-import-export'));
            $product = $variation;
        }
        if (!self::eligible($product) || $product->is_type('variable')) throw new \RuntimeException(__('This product is not available in the catalogue.', 'pc-order-import-export'));
        $quantity = self::quantity($data['quantity'] ?? '');
        $price = ManagerWorkspace::customer_price($product, wp_get_current_user());
        $item = null;
        foreach ($order->get_items() as $candidate) {
            $same_attributes = true;
            foreach ($attributes as $name => $value) {
                if ((string) $candidate->get_meta(substr($name, 10)) !== $value) $same_attributes = false;
            }
            if ($same_attributes && (int) ($candidate->get_variation_id() ?: $candidate->get_product_id()) === $product->get_id()) {
                $item = $candidate;
                break;
            }
        }
        $previous_quantity = $item ? (int) $item->get_quantity() : 0;
        if (!$item) {
            $item = new \WC_Order_Item_Product();
            $item->set_product($product);
            foreach ($attributes as $name => $value) $item->add_meta_data(substr($name, 10), $value, true);
            $order->add_item($item);
        }
        $quantity = self::quantity((string) ($quantity + $previous_quantity));
        $item->set_quantity($quantity);
        $item->set_subtotal((float) $price * $quantity);
        $item->set_total((float) $price * $quantity);
        $item->delete_meta_data('_pc_alloc_plan');
        $item->save();
    }

    private static function update(\WC_Order $order, array $data): void
    {
        if (!hash_equals(ManagerWorkspace::revision($order), (string) ($data['revision'] ?? ''))) {
            throw new \RuntimeException(__('The draft changed. Reload it before editing quantities.', 'pc-order-import-export'));
        }
        $quantities = $data['items'] ?? [];
        if (!is_array($quantities)) throw new \RuntimeException(__('Invalid draft request.', 'pc-order-import-export'));
        $items = $order->get_items();
        foreach ($quantities as $id => $value) {
            if (!isset($items[$id])) throw new \RuntimeException(__('Invalid draft request.', 'pc-order-import-export'));
            self::quantity($value, true);
        }
        foreach ($quantities as $id => $value) {
            $item = $items[$id];
            $qty = self::quantity($value, true);
            if (!$qty) { $order->remove_item($id); continue; }
            $unit = $item->get_quantity() > 0 ? (float) $item->get_total() / $item->get_quantity() : 0;
            $item->set_quantity($qty);
            $item->set_subtotal($unit * $qty);
            $item->set_total($unit * $qty);
            $item->delete_meta_data('_pc_alloc_plan');
            $item->save();
        }
    }
}
