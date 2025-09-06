<?php
namespace PaintCore\PCOE;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class Exporter {
    public static function handle(){
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'pcoe_export')) {
            wp_die('Bad nonce', '', ['response'=>403]);
        }

        $type  = sanitize_key($_GET['type'] ?? 'cart');        // cart|order
        $fmt   = sanitize_key($_GET['fmt']  ?? 'csv');         // csv|xlsx
        $cols  = array_filter(array_map('sanitize_key', explode(',', (string)($_GET['cols'] ?? ''))));
        $split = (($_GET['split'] ?? 'agg') === 'per_loc') ? 'per_loc' : 'agg';

        $avail  = Helpers::columns();
        if (!$cols) $cols = ['sku','name','qty','price','total'];
        $cols   = array_values(array_intersect(array_keys($avail), $cols));   // валидация
        $header = array_map(fn($k) => $avail[$k], $cols);

        $L        = Helpers::labels();
        $filename = $L['filename_cart'].'-'.date('Ymd-His');

        if ($type === 'order') {
            $order_id = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
            if (!$order_id) wp_die('No order', '', ['response'=>400]);

            $order = wc_get_order($order_id);
            if (!$order) wp_die('Order not found', '', ['response'=>404]);

            // доступ: владелец заказа или manage_woocommerce
            if (!current_user_can('manage_woocommerce')) {
                if ((int)$order->get_user_id() !== (int)get_current_user_id()) {
                    wp_die('Forbidden', '', ['response'=>403]);
                }
            }
            $rows     = self::extract_from_order($order_id, $cols, $split);
            $filename = $L['filename_order'].'-'.$order->get_order_number().'-'.date('Ymd-His');
        } else {
            $rows = self::extract_from_cart($cols, $split);
        }

        if ($fmt === 'xlsx') { self::send_xlsx($header, $rows, $filename); }
        else                 { self::send_csv($header, $rows, $filename);  }
    }

    /* -------------------- источники данных -------------------- */

    public static function extract_from_cart(array $want, string $split): array {
        $rows = [];
        if (!function_exists('WC') || !WC()->cart) return $rows;

        foreach (WC()->cart->get_cart() as $it) {
            $p = $it['data'] ?? null;
            if (!$p instanceof \WC_Product) continue;

            $sku   = $p->get_sku();
            $gtin  = Helpers::product_gtin($p);
            $name  = html_entity_decode(wp_strip_all_tags($p->get_name()), ENT_QUOTES, 'UTF-8');
            $qty   = (float)($it['quantity'] ?? 0);
            $price = (float) wc_get_price_excluding_tax($p);

            // план списання — через фільтр (core може підкласти), або primary
            $plan = apply_filters('pcoe_cart_allocation_plan', [], $p, (int)$qty);
            if (!$plan && $qty > 0) {
                $tid = Helpers::primary_location_id($p);
                if ($tid) $plan = [$tid => (int)$qty];
            }

            if ($split === 'per_loc' && $plan) {
                foreach ($plan as $tid => $q) {
                    $q = (int)$q; if ($q <= 0) continue;
                    $rows[] = array_intersect_key([
                        'sku'   => $sku,
                        'gtin'  => $gtin,
                        'name'  => $name,
                        'qty'   => $q,
                        'price' => wc_format_decimal($price, 2),
                        'total' => wc_format_decimal($price * $q, 2),
                        'note'  => 'Склад: ' . Helpers::loc_name_by_id((int)$tid),
                    ], array_flip($want));
                }
            } else {
                $rows[] = array_intersect_key([
                    'sku'   => $sku,
                    'gtin'  => $gtin,
                    'name'  => $name,
                    'qty'   => $qty,
                    'price' => wc_format_decimal($price, 2),
                    'total' => wc_format_decimal($price * $qty, 2),
                    'note'  => Helpers::note_from_plan($plan),
                ], array_flip($want));
            }
        }
        return $rows;
    }

    public static function extract_from_order(int $order_id, array $want, string $split): array {
        $rows  = [];
        $order = wc_get_order($order_id);
        if (!$order) return $rows;

        foreach ($order->get_items() as $item) {
            $p    = $item->get_product();
            $name = html_entity_decode(wp_strip_all_tags($item->get_name()), ENT_QUOTES, 'UTF-8');

            $sku = '';
            if ($p) {
                $sku = (string)$p->get_sku();
                if (!$sku && $p->is_type('variation')) {
                    $parent = wc_get_product($p->get_parent_id());
                    if ($parent) $sku = (string)$parent->get_sku();
                }
            }

            $gtin = $p ? Helpers::product_gtin($p) : '';
            $qty  = (float)$item->get_quantity();
            $unit = (float)$order->get_item_subtotal($item, false, false);

            // план списання з мети або fallback
            $plan = $item->get_meta('_pc_stock_breakdown', true);
            if (!is_array($plan)) {
                $try  = json_decode((string)$plan, true);
                $plan = is_array($try) ? $try : [];
            }
            if (!$plan) {
                $tid = (int)$item->get_meta('_stock_location_id');
                if ($tid) $plan = [$tid => (int)$qty];
            }
            if (!$plan && $p instanceof \WC_Product) {
                $tid = Helpers::primary_location_id($p);
                if ($tid) $plan = [$tid => (int)$qty];
            }

            if ($split === 'per_loc' && $plan) {
                foreach ($plan as $tid => $q) {
                    $q = (int)$q; if ($q <= 0) continue;
                    $rows[] = array_intersect_key([
                        'sku'   => $sku,
                        'gtin'  => $gtin,
                        'name'  => $name,
                        'qty'   => $q,
                        'price' => wc_format_decimal($unit, 2),
                        'total' => wc_format_decimal($unit * $q, 2),
                        'note'  => 'Склад: ' . Helpers::loc_name_by_id((int)$tid),
                    ], array_flip($want));
                }
            } else {
                $rows[] = array_intersect_key([
                    'sku'   => $sku,
                    'gtin'  => $gtin,
                    'name'  => $name,
                    'qty'   => $qty,
                    'price' => wc_format_decimal($unit, 2),
                    'total' => wc_format_decimal($unit * $qty, 2),
                    'note'  => Helpers::note_from_plan((array)$plan),
                ], array_flip($want));
            }
        }
        return $rows;
    }

    /* -------------------- отправка файлов -------------------- */

    protected static function send_csv(array $header, array $rows, string $name): void {
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="'.$name.'.csv"');

        $out = fopen('php://output', 'w');
        // UTF-8 BOM для Excel
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

        fputcsv($out, $header, ';');
        foreach ($rows as $r) {
            fputcsv($out, array_values($r), ';');
        }
        fclose($out);
        exit;
    }

    protected static function send_xlsx(array $header, array $rows, string $name): void {
        // Если PhpSpreadsheet недоступен — отдаём CSV и выходим
        if (!class_exists(Spreadsheet::class)) {
            $L    = Helpers::labels();
            $rows = array_map(function($r) use ($L){
                $r['_info'] = $L['xls_missing'];
                return $r;
            }, $rows);
            self::send_csv(array_merge($header, ['_info']), $rows, $name);
            return;
        }

        $s  = new Spreadsheet();
        $sh = $s->getActiveSheet();

        $c = 1;
        foreach ($header as $h) {
            $sh->setCellValueByColumnAndRow($c++, 1, $h);
        }

        $rnum = 2;
        foreach ($rows as $row) {
            $c = 1;
            foreach ($row as $v) {
                $sh->setCellValueByColumnAndRow($c++, $rnum, $v);
            }
            $rnum++;
        }

        foreach (range(1, count($header)) as $i) {
            $sh->getColumnDimensionByColumn($i)->setAutoSize(true);
        }

        nocache_headers();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="'.$name.'.xlsx"');

        (new Xlsx($s))->save('php://output');
        exit;
    }
}