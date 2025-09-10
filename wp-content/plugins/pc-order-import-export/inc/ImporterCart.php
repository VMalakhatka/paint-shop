<?php
namespace PaintCore\PCOE;

use WC;

defined('ABSPATH') || exit;

class ImporterCart
{
    /**
     * AJAX: імпорт у кошик з CSV/XLS(X)
     * action: pcoe_import_cart
     * nonce:  pcoe_import_cart
     */
    public static function handle()
    {
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'pcoe_import_cart')) {
            wp_send_json_error(['msg' => 'Bad nonce'], 403);
        }
        if (!function_exists('WC') || !WC()->cart) {
            wp_send_json_error(['msg' => 'Cart not available'], 400);
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

        $report = [];
        $added = 0; $skipped = 0;

        // 3) построчно додаємо до кошика
        for ($i = $start; $i < count($rows); $i++) {
            $r = (array) $rows[$i];

            $sku = self::safe_val($r, $map['sku']);
            $gtin = self::safe_val($r, $map['gtin']);
            $qty  = wc_stock_amount( Helpers::parse_qty(self::safe_val($r, $map['qty'])) );

            if ($qty <= 0) {
                $skipped++;
                $report[] = self::logRow($i+1, 'skip', 'Кількість ≤ 0');
                continue;
            }

            // SKU/GTIN → product_id
            $pid = Helpers::resolve_product_id($sku, $gtin);
            if (!$pid) {
                $skipped++;
                $report[] = self::logRow($i+1, 'error', 'Не знайдено товар за SKU/GTIN', compact('sku','gtin'));
                continue;
            }

            $product = wc_get_product($pid);
            if (!($product instanceof \WC_Product)) {
                $skipped++;
                $report[] = self::logRow($i+1, 'error', 'Товар недоступний', compact('pid'));
                continue;
            }

            // Мін/макс з продукту
            $minq = max(1, (int) $product->get_min_purchase_quantity());
            if ($qty < $minq) $qty = (float) $minq;
            $maxq = (int) $product->get_max_purchase_quantity();
            if ($maxq > 0 && $qty > $maxq) $qty = (float) $maxq;

            $ok = WC()->cart->add_to_cart((int)$pid, $qty);
            if ($ok) {
                $added++;
                $report[] = self::logRow($i+1, 'ok', 'Додано', [
                    'name' => $product->get_name(),
                    'sku'  => $product->get_sku() ?: $sku,
                    'qty'  => $qty,
                ]);
            } else {
                $skipped++;
                $report[] = self::logRow($i+1, 'error', 'Не вдалося додати до кошика', [
                    'sku' => $product->get_sku() ?: $sku,
                ]);
            }
        }

        wp_send_json_success([
            'added'       => $added,
            'skipped'     => $skipped,
            'report_html' => Helpers::render_report($report), // ← спільний рендер
        ]);
    }

    /** Безпечне отримання колонки з рядка */
    protected static function safe_val(array $row, $idx): string
    {
        if ($idx === null || $idx === false) return '';
        return isset($row[$idx]) ? trim((string)$row[$idx]) : '';
    }

    /** Елемент звіту */
    protected static function logRow(int $line, string $type, string $msg, array $extra = []): array
    {
        return ['line'=>$line,'type'=>$type,'msg'=>$msg,'extra'=>$extra];
    }
}