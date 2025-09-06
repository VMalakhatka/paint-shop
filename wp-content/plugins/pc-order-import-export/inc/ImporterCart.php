<?php
namespace PaintCore\PCOE;

use WC_Product;
use WC;

defined('ABSPATH') || exit;

class ImporterCart
{
    /**
     * AJAX handler: імпорт у кошик з CSV/XLS(X)
     * action: pcoe_import_cart
     * nonce:  pcoe_import_cart
     */
    public static function handle()
    {
        if ( ! isset($_POST['_wpnonce']) || ! wp_verify_nonce($_POST['_wpnonce'], 'pcoe_import_cart') ) {
            wp_send_json_error(['msg' => 'Bad nonce'], 403);
        }
        if ( ! WC()->cart ) {
            wp_send_json_error(['msg' => 'Cart not available'], 400);
        }
        if (empty($_FILES['file']['tmp_name'])) {
            wp_send_json_error(['msg' => 'Файл не передано'], 400);
        }

        $tmp  = (string) $_FILES['file']['tmp_name'];
        $name = strtolower((string)($_FILES['file']['name'] ?? ''));

        // Прочитати файл у масив рядків
        [$rows, $err] = self::read_rows($tmp, $name);
        if ($err) wp_send_json_error(['msg' => $err], 400);
        if (!$rows) wp_send_json_error(['msg' => 'Порожній файл або невірний формат'], 400);

        // Мапінг колонок за заголовком
        $start = 0;
        $map   = ['sku'=>0,'qty'=>1,'gtin'=>null,'price'=>null];
        $h     = array_map('strval', (array)$rows[0]);
        $normH = array_map([Helpers::class, 'norm'], $h);
        if (in_array('sku', $normH, true) || in_array('gtin', $normH, true)) {
            $cm   = Helpers::build_colmap($h); // повертає sku|gtin|qty|price
            $map  = array_merge($map, $cm);
            $start = 1;
        }

        $report = []; // масив рядків-логів
        $added = 0; $skipped = 0;

        for ($i = $start; $i < count($rows); $i++) {
            $r = (array) $rows[$i];

            $sku  = self::safe_val($r, $map['sku']);
            $gtin = self::safe_val($r, $map['gtin']);
            $qty  = Helpers::parse_qty(self::safe_val($r, $map['qty']));
            $priceRaw = self::safe_val($r, $map['price']);

            if ($qty <= 0) {
                $skipped++;
                $report[] = self::logRow($i+1, 'skip', 'Кількість ≤ 0');
                continue;
            }

            $pid = 0;
            if ($sku !== '')  $pid = wc_get_product_id_by_sku($sku);
            if (!$pid && $gtin !== '') $pid = Helpers::find_product_by_gtin($gtin);

            if (!$pid) {
                $skipped++;
                $report[] = self::logRow($i+1, 'error', 'Не знайдено товар за SKU/GTIN', compact('sku','gtin'));
                continue;
            }

            $product = wc_get_product($pid);
            if (!$product instanceof WC_Product) {
                $skipped++;
                $report[] = self::logRow($i+1, 'error', 'Товар недоступний', compact('pid'));
                continue;
            }

            // Мін/макс з продукту
            $minq = max(1, (int)$product->get_min_purchase_quantity());
            if ($qty < $minq) $qty = (float)$minq;
            $maxq = (int)$product->get_max_purchase_quantity();
            if ($maxq > 0 && $qty > $maxq) $qty = (float)$maxq;

            // Додаємо
            $ok = WC()->cart->add_to_cart($pid, $qty);
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
            'report_html' => self::render_report($report),
        ]);
    }

    /** Прочитати CSV або XLS/XLSX у масив рядків */
    protected static function read_rows(string $tmp, string $name): array
    {
        $rows = [];

        if (preg_match('~\.(xlsx|xls)$~i', $name)) {
            if (class_exists('\\PhpOffice\\PhpSpreadsheet\\IOFactory')) {
                try {
                    $xls = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp);
                    $sheet = $xls->getActiveSheet();
                    foreach ($sheet->toArray(null, true, true, false) as $r) {
                        // toArray вже повертає плоскі значення
                        $rows[] = $r;
                    }
                    return [$rows, null];
                } catch (\Throwable $e) {
                    return [[], 'Не вдалося прочитати XLS(X): '.$e->getMessage()];
                }
            } else {
                return [[], 'Підтримка XLSX недоступна на цьому сервері. Використайте CSV.'];
            }
        }

        // CSV: спроба вгадати роздільник
        $sep = ';';
        $peek = @file_get_contents($tmp, false, null, 0, 2048);
        if ($peek && substr_count($peek, ',') > substr_count($peek, ';')) $sep = ',';
        if (($fh = @fopen($tmp, 'r')) !== false) {
            while (($r = fgetcsv($fh, 0, $sep)) !== false) {
                if ($r === [null] || $r === false) continue;
                $rows[] = $r;
            }
            fclose($fh);
            return [$rows, null];
        }
        return [[], 'Не вдалося прочитати файл'];
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

    /** HTML звіту по рядках */
    protected static function render_report(array $rows): string
    {
        if (!$rows) return '';
        ob_start(); ?>
        <div class="pcoe-report" style="margin-top:8px">
          <table style="width:100%;max-width:800px;border-collapse:collapse;font-size:12px">
            <thead>
              <tr>
                <th style="text-align:left;border-bottom:1px solid #eee;padding:4px 6px">Рядок</th>
                <th style="text-align:left;border-bottom:1px solid #eee;padding:4px 6px">Статус</th>
                <th style="text-align:left;border-bottom:1px solid #eee;padding:4px 6px">Повідомлення</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $r): ?>
              <tr>
                <td style="padding:4px 6px;border-bottom:1px solid #f5f5f5"><?php echo (int)$r['line']; ?></td>
                <td style="padding:4px 6px;border-bottom:1px solid #f5f5f5">
                    <?php echo esc_html($r['type']); ?>
                </td>
                <td style="padding:4px 6px;border-bottom:1px solid #f5f5f5">
                    <?php echo esc_html($r['msg']); ?>
                    <?php if (!empty($r['extra'])): ?>
                        <small style="opacity:.75"> — <?php echo esc_html(json_encode($r['extra'], JSON_UNESCAPED_UNICODE)); ?></small>
                    <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php
        return ob_get_clean();
    }
}