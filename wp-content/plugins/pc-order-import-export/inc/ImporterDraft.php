<?php
namespace PaintCore\PCOE;

use WC_Order;
use WC_Product;

defined('ABSPATH') || exit;

// гарантируем наличие нужных хелперов
if (!function_exists('\\PaintCore\\Stock\\pc_compute_and_stamp_item_plan')
    || !function_exists('\\PaintCore\\Stock\\stamp_item_plan')) {
    require_once WP_PLUGIN_DIR . '/paint-core/inc/order-allocator.php';
}

// Калькулятор плана (верная проверка с неймспейсом)
if (!function_exists('\\PaintCore\\Stock\\pc_calc_plan_for')) {
    require_once WP_PLUGIN_DIR . '/paint-core/inc/header-allocation-switcher.php';
}

class ImporterDraft
{
    /** Зарегистрировать статус заказа «Черновик (импорт)» */
    public static function register_status()
    {
        add_action('init', function () {
            register_post_status('wc-pc-draft', [
                'label'                     => __('Draft (import)', 'pc-order-import-export'),
                'public'                    => false,
                'exclude_from_search'       => true,
                'show_in_admin_all_list'    => true,
                'show_in_admin_status_list' => true,
                'label_count'               => _n_noop(
                    'Draft (import) <span class="count">(%s)</span>',
                    'Draft (import) <span class="count">(%s)</span>',
                    'pc-order-import-export'
                ),
            ]);
        });

        add_filter('wc_order_statuses', function ($st) {
            $new = [];
            foreach ($st as $k => $label) {
                $new[$k] = $label;
                if ($k === 'wc-pending') {
                    $new['wc-pc-draft'] = __('Draft (import)', 'pc-order-import-export');
                }
            }
            if (!isset($new['wc-pc-draft'])) {
                $new['wc-pc-draft'] = __('Draft (import)', 'pc-order-import-export');
            }
            return $new;
        });
    }

    /** Отключить все письма для статуса pc-draft */
    public static function mute_emails_for_drafts()
    {
        $ids = [
            'new_order','cancelled_order','failed_order',
            'customer_on_hold_order','customer_processing_order',
            'customer_completed_order','customer_refunded_order',
            'customer_invoice','customer_note',
        ];
        foreach ($ids as $eid) {
            add_filter("woocommerce_email_enabled_{$eid}", function ($enabled, $order) {
                if ($order instanceof WC_Order) {
                    // WooCommerce хранит статус как 'pc-draft' без префикса 'wc-'
                    if ($order->has_status('pc-draft') || $order->get_status() === 'pc-draft') {
                        return false;
                    }
                }
                return $enabled;
            }, 10, 2);
        }
    }

    /**
     * AJAX: импорт в ЧЕРНОВИК заказа
     * action: pcoe_import_order_draft
     * nonce:  pcoe_import_draft
     */
    public static function handle()
    {
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'pcoe_import_draft')) {
            wp_send_json_error(['msg' => esc_html__('Security check failed (bad nonce).', 'pc-order-import-export')], 403);
        }
        if (empty($_FILES['file']['tmp_name'])) {
            wp_send_json_error(['msg' => esc_html__('No file was uploaded.', 'pc-order-import-export')], 400);
        }

        $tmp  = (string) $_FILES['file']['tmp_name'];
        $name = strtolower((string)($_FILES['file']['name'] ?? ''));

        [$rows, $err] = Helpers::read_rows($tmp, $name);
        if ($err)  wp_send_json_error(['msg' => $err], 400);
        if (!$rows) wp_send_json_error(['msg' => esc_html__('Empty file or invalid format.', 'pc-order-import-export')], 400);

        [$map, $start] = Helpers::detect_colmap_and_start($rows);

        // создаём черновик
        $order = wc_create_order([
            'status'      => 'wc-pc-draft',
            'customer_id' => get_current_user_id() ?: 0,
        ]);
        if (!($order instanceof \WC_Order)) {
            wp_send_json_error(['msg' => esc_html__('Failed to create draft order.', 'pc-order-import-export')], 500);
        }

        // adder для черновика: добавляем позиции и сразу штампуем план списания
        $adder = function(\WC_Product $product, float $qty, ?float $price, array &$errExtra) use ($order): bool {
            $item_data = [];
            if ($price !== null) {
                $item_data['subtotal'] = (float)$price * (float)$qty;
                $item_data['total']    = (float)$price * (float)$qty;
            }
            try {
                $item_id = $order->add_product($product, $qty, $item_data);
                if (!$item_id) return true;

                $item = $order->get_item($item_id);
                if ($item instanceof \WC_Order_Item_Product) {
                    // Единая точка: посчитать и проштамповать
                    \PaintCore\Stock\pc_compute_and_stamp_item_plan(
                        $item,
                        'import-draft',
                        [
                            // 'preferred_location' => 3942, // пример: фиксировать приоритетный склад
                        ]
                    );
                    $item->save();

                    // DEBUG-лог для проверки меты (при необходимости можно удалить)
                    $plan_meta = $item->get_meta('_pc_alloc_plan', true);
                    error_log('pc(plan): ctx=import-draft pid=' . $product->get_id() .
                        ' qty=' . (int)$qty . ' plan=' . json_encode($plan_meta, JSON_UNESCAPED_UNICODE));
                }
                return true;

            } catch (\Throwable $e) {
                $errExtra['ex']  = $e->getMessage();
                $errExtra['pid'] = (int)$product->get_id();
                return false;
            }
        };

        $res = Helpers::process_rows_with_adder($rows, $map, $start, [
            'allow_price' => true,                                                // черновик может принимать цену из файла
            'ok_label'    => esc_html__('Added to draft', 'pc-order-import-export'),
            'adder'       => $adder,
        ]);

        try { $order->calculate_totals(false); } catch (\Throwable $e) {}

        // ссылки + заголовок
        $order_id = $order->get_id();
        $links = [
            'edit' => (current_user_can('edit_shop_orders') ? admin_url('post.php?post='.$order_id.'&action=edit') : ''),
            'view' => self::customer_view_link($order),
        ];

        $title = isset($_POST['title']) ? sanitize_text_field((string)$_POST['title']) : '';
        if ($title === '' && $name !== '') {
            $title = preg_replace('~\.(csv|xlsx?|xls)$~i', '', basename($name));
        }
        if ($title !== '') {
            $order->update_meta_data('_pc_draft_title', $title);
            $order->save();
        }

        wp_send_json_success([
            'order_id'    => $order_id,
            'imported'    => $res['ok'],
            'skipped'     => $res['skipped'],
            'report_html' => Helpers::render_report($res['report']),
            'links'       => $links,
        ]);
    }

    protected static function customer_view_link(WC_Order $order): string
    {
        $uid = get_current_user_id();
        if ($uid && (int)$order->get_user_id() === (int)$uid) {
            $base = wc_get_page_permalink('myaccount');
            if ($base) return wc_get_endpoint_url('view-order', $order->get_id(), $base);
        }
        return '';
    }
}