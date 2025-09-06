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
                    if ($order->has_status('pc-draft')) return false;
                    if ($order->get_status() === 'pc-draft') return false;
                }
                return $enabled;
            }, 10, 2);
        }
    }

    /**
     * AJAX handler: імпорт у ЧЕРНЕТКУ замовлення
     * action: pcoe_import_order_draft
     * nonce:  pcoe_import_draft
     */
    public static function handle()
    {
        if ( ! isset($_POST['_wpnonce']) || ! wp_verify_nonce($_POST['_wpnonce'], 'pcoe_import_draft') ) {
            wp_send_json_error(['msg' => 'Bad nonce'], 403);
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

        // Мапінг колонок
        $start = 0;
        $map   = ['sku'=>0,'qty'=>1,'gtin'=>null,'price'=>null];

        $h     = array_map('strval', (array)$rows[0]);                // сирі заголовки
        $cm    = Helpers::build_colmap($h);                           // шукаємо "Артикул/К-сть/..." тощо
        $has_header = isset($cm['sku']) || isset($cm['gtin']) || isset($cm['qty']) || isset($cm['price']);

        if ($has_header) {
            $map   = array_merge($map, $cm);
            $start = 1;
        } else {
            // Додатковий «рятувальний» детект: якщо у першому рядку багато нетеоретичних чисел — це теж шапка
            $nonNumeric = 0;
            foreach ($h as $cell) {
                $cell = trim((string)$cell);
                $cell2 = str_replace([","," ","\xC2\xA0"], ['.','',''], $cell);
                if ($cell === '' || !is_numeric($cell2)) $nonNumeric++;
            }
            if ($nonNumeric >= 2) { // виглядає як шапка
                $map   = array_merge($map, $cm);
                $start = 1;
            }
        }

        // Створити порожню чернетку (без емейлів і без списань складу)
        $args = [
            'status'      => 'wc-pc-draft',
            'customer_id' => get_current_user_id() ?: 0,
        ];
        $order = wc_create_order($args);
        if (!($order instanceof WC_Order)) {
            wp_send_json_error(['msg' => 'Не вдалося створити чернетку замовлення'], 500);
        }

        $report = [];
        $imported = 0; $skipped = 0;

        for ($i = $start; $i < count($rows); $i++) {
            $r = (array) $rows[$i];

            $sku   = self::safe_val($r, $map['sku']);
            $gtin  = self::safe_val($r, $map['gtin']);
            $qty   = Helpers::parse_qty(self::safe_val($r, $map['qty']));
            $price = self::parse_price(self::safe_val($r, $map['price']));

            $pid = 0;
            if ($sku !== '')  $pid = wc_get_product_id_by_sku($sku);
            if (!$pid && $gtin !== '') $pid = Helpers::find_product_by_gtin($gtin);

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

            // Чернетка: не обмежуємо мін/макс/залишок.
            // Лише нормалізуємо кількість під крок WooCommerce і відсікаємо нуль/від’ємні.
            $qty = wc_stock_amount($qty);
            if ($qty <= 0) {
                $skipped++;
                $report[] = self::logRow($i+1,'skip','Кількість ≤ 0');
                continue;
            }

            // Додати позицію (subtotal/total — якщо є ціна у файлі; інакше Woo візьме з продукту)
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

        // Порахувати тотали (без доставки/податків як є)
        try { $order->calculate_totals(false); } catch (\Throwable $e) {}

        // Посилання
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
            'report_html' => self::render_report($report),
            'links'       => $links,
        ]);
    }

    /** Прочитати CSV/XLS(X) у масив рядків */
    protected static function read_rows(string $tmp, string $name): array
    {
        $rows = [];
        if (preg_match('~\.(xlsx|xls)$~i', $name)) {
            if (class_exists('\\PhpOffice\\PhpSpreadsheet\\IOFactory')) {
                try{
                    $xls = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp);
                    $sheet = $xls->getActiveSheet();
                    foreach ($sheet->toArray(null, true, true, false) as $r) {
                        $rows[] = $r;
                    }
                    return [$rows, null];
                } catch (\Throwable $e){
                    return [[], 'Не вдалося прочитати XLS(X): '.$e->getMessage()];
                }
            } else {
                return [[], 'Підтримка XLSX недоступна на цьому сервері. Використайте CSV.'];
            }
        }

        // CSV
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

    protected static function safe_val(array $row, $idx): string
    {
        if ($idx === null || $idx === false) return '';
        return isset($row[$idx]) ? trim((string)$row[$idx]) : '';
    }

    protected static function parse_price(string $raw): ?float
    {
        $raw = trim($raw);
        if ($raw === '' || $raw === '-') return null;
        // коми → крапки; прибрати тисячні
        $s = str_replace(["\xC2\xA0",' '], '', $raw);
        if (strpos($s, ',') !== false && strpos($s, '.') === false) $s = str_replace(',', '.', $s);
        if (!is_numeric($s)) return null;
        return (float)$s;
    }

    protected static function logRow(int $line, string $type, string $msg, array $extra = []): array
    {
        return ['line'=>$line,'type'=>$type,'msg'=>$msg,'extra'=>$extra];
    }

    protected static function render_report(array $rows): string
    {
        if (!$rows) return '';
        ob_start(); ?>
        <div class="pcoe-report" style="margin-top:8px">
          <table style="width:100%;max-width:900px;border-collapse:collapse;font-size:12px">
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

    /** Посилання "Переглянути замовлення" для клієнта (якщо залогінений власник) */
    protected static function customer_view_link(WC_Order $order): string
    {
        $uid = get_current_user_id();
        if ($uid && (int)$order->get_user_id() === (int)$uid) {
            $base = wc_get_page_permalink('myaccount');
            if ($base) {
                return wc_get_endpoint_url('view-order', $order->get_id(), $base);
            }
        }
        return '';
    }
}