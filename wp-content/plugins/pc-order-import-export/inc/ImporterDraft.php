<?php
namespace PaintCore\PCOE;

use WC_Order;
use WC_Product;

defined('ABSPATH') || exit;

class ImporterDraft
{
   /** Зареєструвати статус замовлення "Чернетка (імпорт)" */
    public static function register_status()
    {
        add_action('init', function () {
            register_post_status('wc-pc-draft', [
                'label'                     => 'Чернетка (імпорт)',
                'public'                    => false,
                'exclude_from_search'       => true,
                'show_in_admin_all_list'    => true,
                'show_in_admin_status_list' => true,
                'label_count'               => _n_noop('Чернетка (імпорт) <span class="count">(%s)</span>', 'Чернетка (імпорт) <span class="count">(%s)</span>'),
            ]);
        });

        add_filter('wc_order_statuses', function ($st) {
            $new = [];
            foreach ($st as $k => $label) {
                $new[$k] = $label;
                if ($k === 'wc-pending') {
                    $new['wc-pc-draft'] = 'Чернетка (імпорт)';
                }
            }
            if (!isset($new['wc-pc-draft'])) {
                $new['wc-pc-draft'] = 'Чернетка (імпорт)';
            }
            return $new;
        });
    }

    /** Відрубити усі емейли для статусу pc-draft */
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
                    if ($order->has_status('pc-draft') || $order->get_status() === 'pc-draft') {
                        return false;
                    }
                }
                return $enabled;
            }, 10, 2);
        }
    }

    /**
     * AJAX: імпорт у ЧЕРНЕТКУ замовлення
     * action: pcoe_import_order_draft
     * nonce:  pcoe_import_draft
     */
    public static function handle()
    {
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'pcoe_import_draft')) {
            wp_send_json_error(['msg' => 'Bad nonce'], 403);
        }
        if (empty($_FILES['file']['tmp_name'])) {
            wp_send_json_error(['msg' => 'Файл не передано'], 400);
        }

        $tmp  = (string) $_FILES['file']['tmp_name'];
        $name = strtolower((string)($_FILES['file']['name'] ?? ''));

        // 1) читаємо файл (загальний хелпер)
        [$rows, $err] = Helpers::read_rows($tmp, $name);
        if ($err)  wp_send_json_error(['msg' => $err], 400);
        if (!$rows) wp_send_json_error(['msg' => 'Порожній файл або невірний формат'], 400);

        // 2) детект шапки + маппінг колонок
        [$map, $start] = Helpers::detect_colmap_and_start($rows);

        // 3) створюємо чернетку (без емейлів)
        $order = wc_create_order([
            'status'      => 'wc-pc-draft',
            'customer_id' => get_current_user_id() ?: 0,
        ]);
        if (!($order instanceof WC_Order)) {
            wp_send_json_error(['msg' => 'Не вдалося створити чернетку замовлення'], 500);
        }

        $report = [];
        $imported = 0; $skipped = 0;

        for ($i = $start; $i < count($rows); $i++) {
            $r = (array) $rows[$i];

            $sku   = self::safe_val($r, $map['sku']);
            $gtin  = self::safe_val($r, $map['gtin']);
            $qty   = wc_stock_amount( Helpers::parse_qty(self::safe_val($r, $map['qty'])) );
            $price = Helpers::parse_price( self::safe_val($r, $map['price']) );

            $pid = Helpers::resolve_product_id($sku, $gtin);
            if (!$pid) {
                $skipped++;
                $report[] = self::logRow($i+1,'error','Не знайдено товар за SKU/GTIN', compact('sku','gtin'));
                continue;
            }

            $product = wc_get_product($pid);
            if (!$product instanceof WC_Product) {
                $skipped++;
                $report[] = self::logRow($i+1,'error','Товар недоступний', compact('pid'));
                continue;
            }

            if ($qty <= 0) {
                $skipped++;
                $report[] = self::logRow($i+1,'skip','Кількість ≤ 0');
                continue;
            }

            $item_data = [];
            if ($price !== null) {
                $item_data['subtotal'] = (float)$price * (float)$qty;
                $item_data['total']    = (float)$price * (float)$qty;
            }

            try {
                $order->add_product($product, $qty, $item_data);
                $imported++;
                $report[] = self::logRow($i+1,'ok','Додано у чернетку', [
                    'name' => $product->get_name(),
                    'sku'  => $product->get_sku() ?: $sku,
                    'qty'  => $qty,
                    'price'=> ($price !== null ? $price : '—'),
                ]);
            } catch (\Throwable $e) {
                $skipped++;
                $report[] = self::logRow($i+1,'error','Помилка додавання: '.$e->getMessage());
            }
        }

        try { $order->calculate_totals(false); } catch (\Throwable $e) {}

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
            'imported'    => $imported,
            'skipped'     => $skipped,
            'report_html' => Helpers::render_report($report), // ← загальний рендер
            'links'       => $links,
        ]);
    }

    protected static function safe_val(array $row, $idx): string
    {
        if ($idx === null || $idx === false) return '';
        return isset($row[$idx]) ? trim((string)$row[$idx]) : '';
    }
    protected static function logRow(int $line, string $type, string $msg, array $extra = []): array
    {
        return ['line'=>$line,'type'=>$type,'msg'=>$msg,'extra'=>$extra];
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