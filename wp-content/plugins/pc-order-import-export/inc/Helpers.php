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
        // убираем BOM, если он есть
        $s = preg_replace('/^\xEF\xBB\xBF/u', '', $s);

        $s = trim(mb_strtolower($s, 'UTF-8'));
        $s = str_replace(['ё','–','—','-','.’','’','“','”'], ['е','-','-','-','','','',''], $s);
        $s = str_replace(['…','.’','.'], ['','',''], $s);
        $s = preg_replace('~\s+~u',' ', $s);
        return $s;
    }

    /** Транслируемые синонимы заголовков CSV (локализуемые + железное ядро EN) */
    public static function header_synonyms(): array {
        // 0) ЖЁСТКОЕ ЯДРО (EN/INTL) — работает всегда, без i18n
        $hard = [
            'sku'   => ['sku','article','part number','part #','part#','mpn','model','model sku'],
            'gtin'  => ['gtin','ean','ean13','ean-13','ean8','ean-8','upc','barcode','bar code','qr','isbn','jan'],
            'qty'   => ['qty','qnt','quantity','q-ty','count','pcs','pieces'],
            // важно: не добавляем сюда "amount/subtotal/total", чтобы не путать с общей суммой
            'price' => ['price','unit price','unitprice','cost'],
        ];

        // 1) Локализуемые дополнения (через _x) — можно расширять в .po
        $sku  = array_merge($hard['sku'], [
            _x('SKU',         'CSV header: SKU',   'pc-order-import-export'),
            _x('Article',     'CSV header: SKU',   'pc-order-import-export'),
            _x('Артикул',     'CSV header: SKU',   'pc-order-import-export'),
            _x('Артикль',     'CSV header: SKU',   'pc-order-import-export'),
            _x('Код',         'CSV header: SKU',   'pc-order-import-export'),
            _x('Код товара',  'CSV header: SKU',   'pc-order-import-export'),
            _x('Код продукту','CSV header: SKU',   'pc-order-import-export'),
        ]);

        $gtin = array_merge($hard['gtin'], [
            _x('Barcode',     'CSV header: GTIN',  'pc-order-import-export'),
            _x('Штрих код',   'CSV header: GTIN',  'pc-order-import-export'),
            _x('Штрих-код',   'CSV header: GTIN',  'pc-order-import-export'),
            _x('Штрихкод',    'CSV header: GTIN',  'pc-order-import-export'),
            // опечатки:
            _x('Шрих код',    'CSV header: GTIN',  'pc-order-import-export'),
            _x('Шрих-код',    'CSV header: GTIN',  'pc-order-import-export'),
            _x('Шрихкод',     'CSV header: GTIN',  'pc-order-import-export'),
        ]);

        $qty  = array_merge($hard['qty'], [
            _x('Количество',  'CSV header: QTY',   'pc-order-import-export'),
            _x('К-во',        'CSV header: QTY',   'pc-order-import-export'),
            _x('К-сть',       'CSV header: QTY',   'pc-order-import-export'),
            _x('Кількість',   'CSV header: QTY',   'pc-order-import-export'),
            _x('шт',          'CSV header: QTY',   'pc-order-import-export'),
            _x('pcs',         'CSV header: QTY',   'pc-order-import-export'),
            _x('pieces',      'CSV header: QTY',   'pc-order-import-export'),
        ]);

        $price= array_merge($hard['price'], [
            _x('Цена',        'CSV header: PRICE', 'pc-order-import-export'),
            _x('Вартість',    'CSV header: PRICE', 'pc-order-import-export'),
            _x('Цiна',        'CSV header: PRICE', 'pc-order-import-export'),
            _x('Ціна',        'CSV header: PRICE', 'pc-order-import-export'),
            _x('Ціна за одиницю','CSV header: PRICE', 'pc-order-import-export'),
        ]);

        // 2) Нормализуем как и заголовок файла
        $norm = function(array $arr){
            return array_values(array_unique(array_map([__CLASS__,'norm'], $arr)));
        };

        $map = [
            'sku'   => $norm($sku),
            'gtin'  => $norm($gtin),
            'qty'   => $norm($qty),
            'price' => $norm($price),
        ];

        // 3) Даём возможность проекту/темам расширять список
        return apply_filters('pcoe_header_synonyms', $map);
    }

public static function build_colmap(array $header): array {
    // Брали «жёсткие» синонимы — теперь берём i18n-версию
    $syn = self::header_synonyms();

    $map = ['sku'=>null,'gtin'=>null,'qty'=>null,'price'=>null];
    $h   = array_map([__CLASS__,'norm'], $header);

    foreach (['sku','gtin','qty','price'] as $k) {
        foreach ($syn[$k] as $want) {
            $pos = array_search($want, $h, true);
            if ($pos !== false) { $map[$k] = $pos; break; }
        }
    }

    // если GTIN не найден — мягкое правило: любая колонка, где встречается слово "штрих"
    if ($map['gtin'] === null) {
        $needles = array_unique(array_merge(
            ['штрих','barcode'],
            array_filter(self::header_synonyms()['gtin'], fn($s) => mb_strlen($s, 'UTF-8') >= 5)
        ));
        foreach ($h as $i => $cell) {
            foreach ($needles as $needle) {
                if (mb_strpos($cell, $needle, 0, 'UTF-8') !== false) {
                    $map['gtin'] = $i; 
                    break 2;
                }
            }
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

        // --- NEW: автодетект кодировки для CSV и возможная перекодировка во временный файл
        $prepare_csv_path = function(string $path): string {
            $sample = @file_get_contents($path, false, null, 0, 65536);
            if ($sample === false) return $path;

            // частые кодировки: UTF-8, Windows-1251/1252, ISO-8859-1
            $enc = mb_detect_encoding($sample, ['UTF-8','Windows-1251','Windows-1252','ISO-8859-1'], true);
            if (!$enc) return $path;

            if ($enc !== 'UTF-8') {
                // перекодируем целиком в UTF-8 во временный файл
                $utf = @iconv($enc, 'UTF-8//IGNORE', file_get_contents($path));
                if ($utf !== false) {
                    $tmp_utf = wp_tempnam( 'pcoe-csv-utf8-' );
                    if ($tmp_utf && @file_put_contents($tmp_utf, $utf) !== false) {
                        return $tmp_utf;
                    }
                }
            }
            return $path;
        };

        // 1) XLSX/XLS — как и было
        if (preg_match('~\.(xlsx|xls)$~i', $name)) {
            if (class_exists('\\PhpOffice\\PhpSpreadsheet\\IOFactory')) {
                try {
                    $xls   = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp);
                    $sheet = $xls->getActiveSheet();
                    foreach ($sheet->toArray(null, true, true, false) as $r) {
                        $rows[] = $r;
                    }
                    return [$rows, null];
                } catch (\Throwable $e) {
                    return [[], sprintf(
                        __('Failed to read XLS(X): %s', 'pc-order-import-export'),
                        $e->getMessage()
                    )];
                }
            }
            return [[], __('XLSX support is unavailable on this server. Please use CSV.', 'pc-order-import-export')];
        }

        // 2) CSV — читаем через PhpSpreadsheet CSV reader (если доступен)
        if (class_exists('\\PhpOffice\\PhpSpreadsheet\\Reader\\Csv')) {
            try {
                $tmpCsv = $prepare_csv_path($tmp);  // <-- NEW
                $sample = @file_get_contents($tmpCsv, false, null, 0, 8192) ?: '';
                $delims = [',',';',"\t",'|'];
                $best = ';';
                $bestCnt = -1;
                foreach ($delims as $d) {
                    $c = substr_count($sample, $d);
                    if ($c > $bestCnt) { $bestCnt = $c; $best = $d; }
                }

                $reader = new \PhpOffice\PhpSpreadsheet\Reader\Csv();
                $reader->setDelimiter($best);
                $reader->setEnclosure('"');
                $reader->setEscapeCharacter('\\');

                // кодировка: пусть попробует понять сам, а мы подстрахуем UTF-8
                if (method_exists($reader, 'setInputEncoding')) {
                    // PhpSpreadsheet CSV читает как UTF-8 по умолчанию.
                    // Если нужен Windows-1251 — можно раскомментировать автодетект:
                    // $enc = mb_detect_encoding($sample, ['UTF-8','Windows-1251','Windows-1252','ISO-8859-1'], true) ?: 'UTF-8';
                    // $reader->setInputEncoding($enc);
                }

                $spreadsheet = $reader->load($tmpCsv);
                $sheet = $spreadsheet->getActiveSheet();
                foreach ($sheet->toArray(null, true, true, false) as $r) {
                    // фильтр «пустых» строк: всё пусто или массив из одного null
                    if ($r === [null] || $r === false) { continue; }
                    // нормализация NBSP тонких пробелов по всем ячейкам
                    foreach ($r as $i => $v) {
                        if ($v === null) continue;
                        $v = (string)$v;
                        // снять BOM, если прилип к первой ячейке
                        if ($i === 0 && isset($v[0]) && substr($v,0,3) === "\xEF\xBB\xBF") {
                            $v = substr($v,3);
                        }
                        $v = preg_replace('/\x{00A0}|\x{2007}|\x{202F}/u', ' ', $v);
                        $r[$i] = trim($v);
                    }
                    $rows[] = $r;
                }
                return [$rows, null];

            } catch (\Throwable $e) {
                // упали — мягко откатываемся на fgetcsv
            }
        }

        // 3) Fallback: нативный fgetcsv (как было, но с авто-разделителем и BOM-фикс)
        $tmpCsv = $prepare_csv_path($tmp); // <-- NEW
        $sep = ';';
        $peek = @file_get_contents($tmpCsv, false, null, 0, 2048);
        if ($peek) {
            $candidates = [',',';',"\t",'|'];
            $best = ';'; $bestCnt = -1;
            foreach ($candidates as $d) {
                $c = substr_count($peek, $d);
                if ($c > $bestCnt) { $bestCnt = $c; $best = $d; }
            }
            $sep = $best;
        }
        if (($fh = @fopen($tmpCsv, 'r')) !== false) {
            $rowIndex = 0;
            while (($r = fgetcsv($fh, 0, $sep)) !== false) {
                if ($r === [null] || $r === false) continue;
                if ($rowIndex === 0 && isset($r[0])) {
                    $r[0] = preg_replace('/^\xEF\xBB\xBF/u', '', (string)$r[0]);
                }
                foreach ($r as $i => $v) {
                    $v = (string)$v;
                    $v = preg_replace('/\x{00A0}|\x{2007}|\x{202F}/u', ' ', $v);
                    $r[$i] = trim($v);
                }
                $rows[] = $r;
                $rowIndex++;
            }
            fclose($fh);
            return [$rows, null];
        }

        return [[], __('Failed to read the file.', 'pc-order-import-export')];
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

    private static function strip_quotes(string $s): string {
        // срезаем только обрамляющие (“умные” тоже)
        return preg_replace('~^[\'"“”‚‛‘’]+|[\'"“”‚‛‘’]+$~u', '', $s);
    }

    private static function strip_weird_spaces(string $s): string {
        // NBSP / узкие пробелы → обычный пробел
        return preg_replace('/\x{00A0}|\x{2007}|\x{202F}/u', ' ', $s);
    }

    /** Пошук товару за SKU/GTIN */
    public static function resolve_product_id(string $sku, string $gtin): int {
        $sku  = trim($sku);
        // GTIN оставляем как есть, но очищаем от пробелов/разделителей
        $gtin = preg_replace('~\s+~u', '', (string)$gtin);

        // если GTIN есть — пробуем первым
        if ($gtin !== '') {
            $pid = self::find_product_by_gtin($gtin);
            if ($pid) return (int)$pid;
            // если по GTIN не нашли — пробуем SKU
        }

        if ($sku !== '') {
            $pid = wc_get_product_id_by_sku($sku);
            if ($pid) return (int)$pid;
        }

        return 0;
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
                <th style="text-align:left;border-bottom:1px solid #eee;padding:4px 6px"><?php echo esc_html__('Row', 'pc-order-import-export'); ?></th>
                <th style="text-align:left;border-bottom:1px solid #eee;padding:4px 6px"><?php echo esc_html__('Status', 'pc-order-import-export'); ?></th>
                <th style="text-align:left;border-bottom:1px solid #eee;padding:4px 6px"><?php echo esc_html__('Message', 'pc-order-import-export'); ?></th>
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
            return ['ok'=>0,'skipped'=>0,'report'=>[
                self::logRow(0,'error', __('Internal error: adder is not set', 'pc-order-import-export'))
            ]];
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
                $report[] = self::logRow($i+1, 'skip', __('Quantity ≤ 0', 'pc-order-import-export'));
                continue;
            }

            $pid = self::resolve_product_id($sku, $gtin);
            if (!$pid) {
                $skipped++;
                $report[] = self::logRow($i+1, 'error', __('Product not found by SKU/GTIN', 'pc-order-import-export'), compact('sku','gtin'));
                continue;
            }

            $product = wc_get_product($pid);
            if (!($product instanceof \WC_Product)) {
                $skipped++;
                $report[] = self::logRow($i+1, 'error', __('Product is unavailable', 'pc-order-import-export'), compact('pid'));
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
                $report[] = self::logRow($i+1, 'error', __('Failed to add', 'pc-order-import-export'), $errExtra);
            }
        }

        return ['ok'=>$ok,'skipped'=>$skipped,'report'=>$report];
    }

    // Безпечне читання клітинки рядка за індексом
    public static function safe_val(array $row, $idx): string {
        if ($idx === null || $idx === false) return '';
        if (!array_key_exists($idx, $row)) return '';

        $v = (string)$row[$idx];

        // снять BOM (если внезапно попал в першу клітинку)
        if (isset($v[0]) && substr($v, 0, 3) === "\xEF\xBB\xBF") {
            $v = substr($v, 3);
        }

        // унификация пробелов + срез обрамляющих кавычек
        $v = self::strip_weird_spaces(self::strip_quotes($v));

        // финальный trim
        return trim($v);
    }

    // Рядок звіту (для Cart/Draft)
    public static function logRow(int $line, string $type, string $msg, array $extra = []): array {
        return ['line' => $line, 'type' => $type, 'msg' => $msg, 'extra' => $extra];
    }
}