<?php
namespace PaintCore\PCOE;

use WC_Product;

class Helpers {
    // Текстові мітки (1 місце для перекладів)
    public static function labels(): array {
        return [
            'btn_csv'       => __('Download CSV', 'pc-order-import-export'),
            'btn_xlsx'      => __('Download XLSX', 'pc-order-import-export'),
            'conf_toggle'   => __('Configure export', 'pc-order-import-export'),
            'split_label'   => __('Row format:', 'pc-order-import-export'),
            'split_agg'     => __('Aggregate (write "Allocation: ..." in Notes)', 'pc-order-import-export'),
            'split_per_loc' => __('Separate per location', 'pc-order-import-export'),
            'col_sku'       => __('SKU', 'pc-order-import-export'),
            'col_gtin'      => __('GTIN', 'pc-order-import-export'),
            'col_name'      => __('Name', 'pc-order-import-export'),
            'col_qty'       => __('Qty', 'pc-order-import-export'),
            'col_price'     => __('Price', 'pc-order-import-export'),
            'col_total'     => __('Total', 'pc-order-import-export'),
            'col_note'      => __('Notes', 'pc-order-import-export'),
            'filename_cart'  => __('cart', 'pc-order-import-export'),
            'filename_order' => __('order', 'pc-order-import-export'),
            'xls_missing'   => __('XLSX is unavailable (PhpSpreadsheet not found) - saved as CSV.', 'pc-order-import-export'),
        ];
    }

    public static function columns(): array {
        $L = self::labels();
        $cols = [
            'sku'   => $L['col_sku'],
            'gtin'  => $L['col_gtin'],
            'name'  => $L['col_name'],
            'qty'   => $L['col_qty'],
            'price' => $L['col_price'],
            'total' => $L['col_total'],
            'note'  => $L['col_note'],
        ];
        return apply_filters('pcoe_columns', $cols);
    }

    public static function gtin_meta_keys(): array {
        $keys = ['_global_unique_id','_wpm_gtin_code','_alg_ean','_ean','_sku_gtin'];
        return apply_filters('pcoe_gtin_meta_keys', $keys);
    }

    public static function product_gtin(WC_Product $product): string {
        // якщо є глобальний хелпер core — віддай йому
        if (function_exists('pc_get_product_gtin')) {
            $v = (string) pc_get_product_gtin($product);
            if ($v !== '') return $v;
        }
        $read = function($id){
            foreach (self::gtin_meta_keys() as $k){
                $raw = get_post_meta($id, $k, true);
                if ($raw !== '' && $raw !== null) return (string)$raw;
            }
            return '';
        };
        $v = $read($product->get_id());
        if ($v !== '') return $v;
        if ($product->is_type('variation') && $product->get_parent_id()){
            $v = $read($product->get_parent_id());
        }
        return (string)$v;
    }

    public static function find_product_by_gtin(string $gtin): int {
        foreach (self::gtin_meta_keys() as $k) {
            $q = new \WP_Query([
                'post_type'      => ['product','product_variation'],
                'post_status'    => 'publish',
                'fields'         => 'ids',
                'posts_per_page' => 1,
                'meta_query'     => [['key'=>$k,'value'=>$gtin,'compare'=>'=']],
            ]);
            if ($q->have_posts()) { $id = (int)$q->posts[0]; wp_reset_postdata(); return $id; }
            wp_reset_postdata();
        }
        return 0;
    }

    // нормалізатор колонки + мапінг заголовку
    public static function norm(string $s): string {
        $s = trim(mb_strtolower($s, 'UTF-8'));
        $s = str_replace(['ё','й','–','—','-','.’','’','“','”'], ['е','и','-','-','-','','','',''], $s);
        // унифицируем многоточия, точки, двойные пробелы
        $s = str_replace(['…','.’','.'], ['','',''], $s);
        $s = preg_replace('~\s+~u',' ', $s);
        return $s;
    }

public static function build_colmap(array $header): array {
    $syn = [
        'sku' => ['sku','артикул','артикль','код','код товара','код продукту'],
        'gtin'=> [
            'gtin','ean','upc',
            'штрих код','штрих-код','штрихкод',
            'шрих код','шрих-код','шрихкод', // <- опечатки
        ],
        'qty' => ['qty','quantity','количество','к-во','к-сть','кількість'],
        'price'=> ['price','цена','вартість','цiна','ціна'],
    ];

    $map = ['sku'=>null,'gtin'=>null,'qty'=>null,'price'=>null];
    $h   = array_map([__CLASS__,'norm'], $header);

    // точные совпадения
    foreach (['sku','gtin','qty','price'] as $k) {
        foreach ($syn[$k] as $want) {
            $pos = array_search($want, $h, true);
            if ($pos !== false) { $map[$k] = $pos; break; }
        }
    }

    // если GTIN не найден — мягкое правило: любая колонка, где встречается слово "штрих"
    if ($map['gtin'] === null) {
        foreach ($h as $i => $cell) {
            if (strpos($cell, 'штрих') !== false) { $map['gtin'] = $i; break; }
        }
    }

    // дефолты как раньше
    if ($map['qty'] === null) $map['qty'] = 1;
    if ($map['sku'] === null && $map['gtin'] === null) $map['sku'] = 0;

    // эвристика: если нет sku, а в первой колонке длинные цифры — считать её GTIN
    if ($map['gtin'] === null && $map['sku'] !== null) {
        $firstTitle = $h[$map['sku']] ?? '';
        $looksNumericId = preg_match('~^\d{8,16}$~', preg_replace('~\D+~','', (string)($header[($map['sku']??0)] ?? '')));
        if ($looksNumericId) {
            $map['gtin'] = $map['sku'];
            $map['sku']  = null;
        }
    }

    return apply_filters('pcoe_colmap', $map, $header);
}

    // парсер кількості (., ,; пробіли-тисячні)
    public static function parse_qty($raw): float {
        $s = trim((string)$raw);
        if ($s === '') return 0.0;
        $s = str_replace(["\xC2\xA0",' '], '', $s);
        if (strpos($s, ',') !== false && strpos($s, '.') === false) $s = str_replace(',', '.', $s);
        return (float)$s;
    }

    // локація + “Списання: …” (через фільтри, щоб не тягнути core напряму)
    public static function primary_location_id(WC_Product $product): int {
        $tid = (int) apply_filters('pcoe_primary_location_id', 0, $product);
        if ($tid) return $tid;
        // дефолт: meta від Yoast Primary (як у твоїй реалізації)
        $pid = $product->get_id();
        $tid = (int) get_post_meta($pid, '_yoast_wpseo_primary_location', true);
        if (!$tid && $product->is_type('variation') && $product->get_parent_id()){
            $tid = (int) get_post_meta($product->get_parent_id(), '_yoast_wpseo_primary_location', true);
        }
        return $tid ?: 0;
    }
    public static function loc_name_by_id(int $tid): string {
        $name = apply_filters('pcoe_location_name', '', $tid);
        if ($name !== '') return $name;
        $t = get_term($tid, 'location');
        return ($t && !is_wp_error($t)) ? $t->name : '';
    }
    public static function note_from_plan(array $plan): string {
        error_log('NOTE_FROM_PLAN input: ' . print_r($plan, true));
        if (!$plan) return '';
        arsort($plan, SORT_NUMERIC);
        $parts = [];
        foreach ($plan as $tid=>$q) {
            $n = self::loc_name_by_id((int)$tid);
            if ($n && $q>0) $parts[] = $n.' × '.(int)$q;
        }
        return $parts ? ('Списання: '.implode(', ', $parts)) : '';
    }

     /** Прочитати CSV/XLS(X) у масив рядків */
    public static function read_rows(string $tmp, string $name): array {
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

    /** Детект шапки + мапінг колонок (як у Draft) */
    public static function detect_colmap_and_start(array $rows): array {
        $map   = ['sku'=>0,'qty'=>1,'gtin'=>null,'price'=>null];
        $start = 0;

        if (empty($rows)) return [$map, $start];

        $h  = array_map('strval', (array)$rows[0]);  // сирі заголовки
        $cm = self::build_colmap($h);               // шукаємо локалізовані назви

        $has_header = isset($cm['sku']) || isset($cm['gtin']) || isset($cm['qty']) || isset($cm['price']);
        if ($has_header) {
            $map   = array_merge($map, $cm);
            $start = 1;
        } else {
            // fallback-евристика: якщо 1-й рядок виглядає як шапка
            $nonNumeric = 0;
            foreach ($h as $cell) {
                $cell  = trim((string)$cell);
                $cell2 = str_replace([","," ","\xC2\xA0"], ['.','',''], $cell);
                if ($cell === '' || !is_numeric($cell2)) $nonNumeric++;
            }
            if ($nonNumeric >= 2) {
                $map   = array_merge($map, $cm);
                $start = 1;
            }
        }
        return [$map, $start];
    }

    /** Пошук товару за SKU/GTIN */
    public static function resolve_product_id(string $sku, string $gtin): int {
        $pid = 0;
        if ($sku !== '')  $pid = wc_get_product_id_by_sku($sku);
        if (!$pid && $gtin !== '') $pid = self::find_product_by_gtin($gtin);
        return (int)$pid;
    }

    /** Парсер ціни (уніфікований з Draft) */
    public static function parse_price(string $raw): ?float {
        $raw = trim($raw);
        if ($raw === '' || $raw === '-') return null;
        $s = str_replace(["\xC2\xA0",' '], '', $raw);
        if (strpos($s, ',') !== false && strpos($s, '.') === false) $s = str_replace(',', '.', $s);
        if (!is_numeric($s)) return null;
        return (float)$s;
    }

        /** HTML звіту по рядках (спільний для Cart/Draft) */
    public static function render_report(array $rows): string {
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

    /**
     * Универсальная обработка строк импорта.
     * Мы сами лишь парсим sku/gtin/qty/price и находим продукт,
     * а реальное «добавление» делаем через переданный колбек $adder.
     *
     * @param array $rows
     * @param array $map     ['sku'=>..,'gtin'=>..,'qty'=>..,'price'=>..]
     * @param int   $start   индекс первой строки данных (после шапки)
     * @param array $opts    [
     *   'allow_price' => bool, // true для чернетки, false для кошика
     *   'ok_label'    => string, // текст для success (напр. "Додано" / "Додано у чернетку")
     *   'adder'       => callable(WC_Product $product, float $qty, ?float $price, array &$errExtra): bool
     * ]
     * @return array ['ok'=>int,'skipped'=>int,'report'=>array]
     */
    public static function process_rows_with_adder(array $rows, array $map, int $start, array $opts): array {
        $allowPrice = (bool)($opts['allow_price'] ?? false);
        $okLabel    = (string)($opts['ok_label'] ?? 'Додано');
        $adder      = $opts['adder'] ?? null;
        if (!is_callable($adder)) {
            return ['ok'=>0,'skipped'=>0,'report'=>[ self::logRow(0,'error','Внутрішня помилка: adder не заданий') ]];
        }

        $ok = 0; $skipped = 0; $report = [];

        for ($i = $start; $i < count($rows); $i++) {
            $r    = (array)$rows[$i];
            $sku  = self::safe_val($r, $map['sku']  ?? null);
            $gtin = self::safe_val($r, $map['gtin'] ?? null);
            $qty  = wc_stock_amount( self::parse_qty(self::safe_val($r, $map['qty'] ?? null)) );
            $price= $allowPrice ? self::parse_price(self::safe_val($r, $map['price'] ?? null)) : null;

            if ($qty <= 0) {
                $skipped++;
                $report[] = self::logRow($i+1, 'skip', 'Кількість ≤ 0');
                continue;
            }

            $pid = self::resolve_product_id($sku, $gtin);
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

            $errExtra = [];
            $okAdd = false;
            try {
                $okAdd = (bool) call_user_func($adder, $product, (float)$qty, $price, $errExtra);
            } catch (\Throwable $e) {
                $okAdd = false;
                $errExtra['ex'] = $e->getMessage();
            }

            if ($okAdd) {
                $ok++;
                $rowExtra = [
                    'name' => $product->get_name(),
                    'sku'  => $product->get_sku() ?: $sku,
                    'qty'  => $qty,
                ];
                if ($allowPrice) { $rowExtra['price'] = ($price !== null ? $price : '—'); }
                $report[] = self::logRow($i+1, 'ok', $okLabel, $rowExtra);
            } else {
                $skipped++;
                if (!$errExtra) { $errExtra = ['sku' => $product->get_sku() ?: $sku]; }
                $report[] = self::logRow($i+1, 'error', 'Не вдалося додати', $errExtra);
            }
        }

        return ['ok'=>$ok,'skipped'=>$skipped,'report'=>$report];
    }

    // Безпечне читання клітинки рядка за індексом
    public static function safe_val(array $row, $idx): string {
        if ($idx === null || $idx === false) return '';
        return isset($row[$idx]) ? trim((string)$row[$idx]) : '';
    }

    // Рядок звіту (для Cart/Draft)
    public static function logRow(int $line, string $type, string $msg, array $extra = []): array {
        return ['line' => $line, 'type' => $type, 'msg' => $msg, 'extra' => $extra];
    }
}