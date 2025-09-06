<?php
/*
Plugin Name: PC Order & Cart Export
Description: Экспорт корзины и заказов в CSV/XLSX с выбором колонок (клиентский фронт) + режим: загальна / окремо по складах.
Version: 1.2.0
Author: PaintCore
*/

if (!defined('ABSPATH')) exit;

/* ==================== LABELS ==================== */
function pcoe_labels(): array {
    return [
        'btn_csv'        => 'Завантажити CSV',
        'btn_xlsx'       => 'Завантажити XLSX',
        'conf_toggle'    => 'Налаштувати експорт',
        'split_label'    => 'Формат позицій:',
        'split_agg'      => 'Загальна (у примітках «Списання: …»)',
        'split_per_loc'  => 'Окремо по кожному складу',

        'col_sku'        => 'Артикул',
        'col_gtin'       => 'GTIN',
        'col_name'       => 'Назва',
        'col_qty'        => 'К-сть',
        'col_price'      => 'Ціна',
        'col_total'      => 'Сума',
        'col_note'       => 'Примітка',

        'cart_section'   => 'Корзина',
        'order_section'  => 'Замовлення',
        'filename_cart'  => 'cart',
        'filename_order' => 'order',
        'xls_missing'    => 'XLSX недоступний (PhpSpreadsheet не знайдено) — збережено як CSV.',
    ];
}

/* ==================== GTIN helper ==================== */
function pcoe_gtin_meta_keys(): array {
    $keys = [
        '_global_unique_id',
        '_wpm_gtin_code',
        '_alg_ean',
        '_ean',
        '_sku_gtin',
    ];
    return apply_filters('pcoe_gtin_meta_keys', array_values(array_unique(array_filter($keys, 'strlen'))));
}

function pcoe_get_product_gtin(WC_Product $product): string {
    if (function_exists('pc_get_product_gtin')) {
        $v = (string) pc_get_product_gtin($product);
        if ($v !== '') return $v;
    }
    $read = function(int $post_id): string {
        foreach (pcoe_gtin_meta_keys() as $k) {
            $raw = get_post_meta($post_id, $k, true);
            if ($raw !== '' && $raw !== null) return (string) $raw;
        }
        return '';
    };
    $v = $read($product->get_id());
    if ($v !== '') return $v;

    if ($product->is_type('variation')) {
        $pid = (int) $product->get_parent_id();
        if ($pid) {
            $v = $read($pid);
            if ($v !== '') return $v;
        }
    }
    return '';
}

/* ==================== Колонки ==================== */
function pcoe_available_columns(): array {
    $L = pcoe_labels();
    return [
        'sku'   => $L['col_sku'],
        'gtin'  => $L['col_gtin'],
        'name'  => $L['col_name'],
        'qty'   => $L['col_qty'],
        'price' => $L['col_price'],
        'total' => $L['col_total'],
        'note'  => $L['col_note'],
    ];
}

/* ==================== Location helpers ==================== */
function pcoe_loc_name_by_id(int $term_id): string {
    if ($term_id <= 0) return '';
    $t = get_term($term_id, 'location');
    return ($t && !is_wp_error($t)) ? (string)$t->name : '';
}

function pcoe_primary_location_for_product(WC_Product $product): int {
    $pid = $product->get_id();
    $tid = (int) get_post_meta($pid, '_yoast_wpseo_primary_location', true);
    if (!$tid && $product->is_type('variation')) {
        $parent = $product->get_parent_id();
        if ($parent) $tid = (int) get_post_meta($parent, '_yoast_wpseo_primary_location', true);
    }
    return $tid ?: 0;
}

/* build note like: "Списання: Одеса × 1, Київ × 3" */
function pcoe_note_from_plan(array $plan): string {
    if (empty($plan)) return '';
    arsort($plan, SORT_NUMERIC);
    $parts = [];
    foreach ($plan as $tid=>$q) {
        $name = pcoe_loc_name_by_id((int)$tid);
        if ($name && $q>0) $parts[] = $name.' × '.(int)$q;
    }
    return $parts ? ('Списання: '.implode(', ', $parts)) : '';
}

/* ==================== UI (Cart) ==================== */
add_action('woocommerce_after_cart_totals', function () {
    if (!WC()->cart) return;

    $nonce = wp_create_nonce('pcoe_export');
    $cols  = pcoe_available_columns();
    $L     = pcoe_labels();

    echo '<div class="pcoe-export" style="margin-top:16px">';
      echo '<div style="display:flex;gap:10px;flex-wrap:wrap;margin:8px 0">';
        echo '<a class="button" href="'.esc_url(admin_url('admin-ajax.php?action=pcoe_export&type=cart&fmt=csv&_wpnonce='.$nonce)).'">'.$L['btn_csv'].'</a>';
        echo '<a class="button" href="'.esc_url(admin_url('admin-ajax.php?action=pcoe_export&type=cart&fmt=xlsx&_wpnonce='.$nonce)).'">'.$L['btn_xlsx'].'</a>';
        echo '<label style="display:flex;align-items:center;gap:6px;margin-left:auto">';
          echo '<span>'.$L['split_label'].'</span>';
          echo '<select class="pcoe-split" data-scope="cart">';
            echo '<option value="agg">'.$L['split_agg'].'</option>';
            echo '<option value="per_loc">'.$L['split_per_loc'].'</option>';
          echo '</select>';
        echo '</label>';
      echo '</div>';

      echo '<details style="margin:8px 0"><summary>'.$L['conf_toggle'].'</summary>';
        echo '<div class="pcoe-cols" data-scope="cart" style="display:flex;gap:14px;flex-wrap:wrap;margin:10px 0">';
          foreach ($cols as $key=>$label) {
              echo '<label style="display:flex;gap:6px;align-items:center"><input type="checkbox" class="pcoe-col" value="'.esc_attr($key).'"> '.esc_html($label).'</label>';
          }
        echo '</div>';
      echo '</details>';
    echo '</div>';
});

/* ==================== UI (Order page) ==================== */
add_action('woocommerce_order_details_after_order_table', function ($order) {
    if (!$order instanceof WC_Order) return;

    $nonce = wp_create_nonce('pcoe_export');
    $cols  = pcoe_available_columns();
    $L     = pcoe_labels();

    echo '<div class="pcoe-export" style="margin-top:16px">';
      echo '<div style="display:flex;gap:10px;flex-wrap:wrap;margin:8px 0">';
        $base = admin_url('admin-ajax.php?action=pcoe_export&type=order&order_id='.$order->get_id().'&_wpnonce='.$nonce);
        echo '<a class="button" href="'.esc_url($base.'&fmt=csv').'">'.$L['btn_csv'].'</a>';
        echo '<a class="button" href="'.esc_url($base.'&fmt=xlsx').'">'.$L['btn_xlsx'].'</a>';
        echo '<label style="display:flex;align-items:center;gap:6px;margin-left:auto">';
          echo '<span>'.$L['split_label'].'</span>';
          echo '<select class="pcoe-split" data-scope="order">';
            echo '<option value="agg">'.$L['split_agg'].'</option>';
            echo '<option value="per_loc">'.$L['split_per_loc'].'</option>';
          echo '</select>';
        echo '</label>';
      echo '</div>';

      echo '<details style="margin:8px 0"><summary>'.$L['conf_toggle'].'</summary>';
        echo '<div class="pcoe-cols" data-scope="order" style="display:flex;gap:14px;flex-wrap:wrap;margin:10px 0">';
          foreach ($cols as $key=>$label) {
              echo '<label style="display:flex;gap:6px;align-items:center"><input type="checkbox" class="pcoe-col" value="'.esc_attr($key).'"> '.esc_html($label).'</label>';
          }
        echo '</div>';
      echo '</details>';
    echo '</div>';
});

/* ==================== JS glue (localStorage + URL) ==================== */
add_action('wp_enqueue_scripts', function () {
    $L = pcoe_labels();
    wp_add_inline_script('jquery', "
    jQuery(function($){
        var KEY_COLS='pcoeCols', KEY_SPLIT='pcoeSplit';
        function readCols(scope){ try{ var all=JSON.parse(localStorage.getItem(KEY_COLS)||'{}'); return all[scope]||[]; }catch(e){ return []; } }
        function writeCols(scope, arr){ try{ var all=JSON.parse(localStorage.getItem(KEY_COLS)||'{}'); all[scope]=arr; localStorage.setItem(KEY_COLS, JSON.stringify(all)); }catch(e){} }
        function readSplit(scope){ try{ var all=JSON.parse(localStorage.getItem(KEY_SPLIT)||'{}'); return all[scope]||'agg'; }catch(e){ return 'agg'; } }
        function writeSplit(scope, val){ try{ var all=JSON.parse(localStorage.getItem(KEY_SPLIT)||'{}'); all[scope]=val; localStorage.setItem(KEY_SPLIT, JSON.stringify(all)); }catch(e){} }

        // restore checkboxes
        $('.pcoe-cols').each(function(){
            var scope = $(this).data('scope');
            var sel = readCols(scope);
            if (sel.length){
                $(this).find('input.pcoe-col').each(function(){
                    if (sel.indexOf($(this).val())!==-1) $(this).prop('checked', true);
                });
            } else {
                $(this).find('input.pcoe-col[value=\"sku\"],input.pcoe-col[value=\"name\"],input.pcoe-col[value=\"qty\"],input.pcoe-col[value=\"price\"],input.pcoe-col[value=\"total\"]').prop('checked', true);
            }
        });

        // restore split select
        $('.pcoe-split').each(function(){
            var scope = $(this).data('scope');
            $(this).val(readSplit(scope));
        });

        // persist
        $(document).on('change','.pcoe-col',function(){
            var wrap = $(this).closest('.pcoe-cols');
            var scope = wrap.data('scope');
            var arr = [];
            wrap.find('input.pcoe-col:checked').each(function(){ arr.push($(this).val()); });
            writeCols(scope, arr);
        });
        $(document).on('change','.pcoe-split',function(){
            var scope = $(this).data('scope');
            writeSplit(scope, $(this).val());
        });

        // append params to export links
        $(document).on('click','.pcoe-export a.button',function(){
            var box = $(this).closest('.pcoe-export');
            var colsWrap = box.find('.pcoe-cols'); var scope = colsWrap.data('scope');
            var cols=[]; colsWrap.find('input.pcoe-col:checked').each(function(){ cols.push($(this).val()); });
            if(!cols.length){ cols=['sku','name','qty','price','total']; }
            var splitSel = box.find('.pcoe-split'); var split = splitSel.val() || 'agg';
            var href = new URL(this.href);
            href.searchParams.set('cols', cols.join(','));
            href.searchParams.set('split', split);
            this.href = href.toString();
        });
    });
    ");
});

/* ==================== DATA: Cart / Order ==================== */
function pcoe_extract_from_cart(array $want_cols, string $split): array {
    $rows = [];
    if (!WC()->cart) return $rows;

    foreach (WC()->cart->get_cart() as $item) {
        $product = $item['data'] ?? null;
        if (!$product instanceof WC_Product) continue;

        $sku   = $product->get_sku();
        $gtin  = pcoe_get_product_gtin($product);
        $name  = html_entity_decode( wp_strip_all_tags($product->get_name()), ENT_QUOTES, 'UTF-8' );
        $qty   = (float)($item['quantity'] ?? 0);
        $price = (float) wc_get_price_excluding_tax($product);
        $note  = '';

        // план списання по складах для корзини — рахуємо на льоту, якщо доступно
        $plan = [];
        if (function_exists('slu_get_allocation_plan')) {
            $plan = (array) slu_get_allocation_plan($product, (int)$qty);
        }
        if (!$plan) {
            // fallback: увесь обсяг на primary
            $tid = pcoe_primary_location_for_product($product);
            if ($tid && $qty>0) $plan = [ $tid => (int)$qty ];
        }

        if ($split === 'per_loc' && $plan) {
            foreach ($plan as $tid=>$q) {
                $q = (int)$q; if ($q<=0) continue;
                $row = [
                    'sku'   => $sku,
                    'gtin'  => $gtin,
                    'name'  => $name,
                    'qty'   => $q,
                    'price' => wc_format_decimal($price, 2),
                    'total' => wc_format_decimal($price * $q, 2),
                    'note'  => 'Склад: '.pcoe_loc_name_by_id((int)$tid),
                ];
                $rows[] = array_intersect_key($row, array_flip($want_cols));
            }
        } else {
            // aggregate + note «Списання: …»
            $row = [
                'sku'   => $sku,
                'gtin'  => $gtin,
                'name'  => $name,
                'qty'   => $qty,
                'price' => wc_format_decimal($price, 2),
                'total' => wc_format_decimal($price * $qty, 2),
                'note'  => pcoe_note_from_plan($plan),
            ];
            $rows[] = array_intersect_key($row, array_flip($want_cols));
        }
    }
    return $rows;
}

function pcoe_extract_from_order(int $order_id, array $want_cols, string $split): array {
    $rows = [];
    $order = wc_get_order($order_id);
    if (!$order) return $rows;

    foreach ($order->get_items() as $item) {
        /** @var WC_Order_Item_Product $item */
        $product = $item->get_product();
        $name  = html_entity_decode( wp_strip_all_tags($item->get_name()), ENT_QUOTES, 'UTF-8' );

        // SKU (fallback на батька)
        $sku = '';
        if ($product) {
            $sku = (string)$product->get_sku();
            if (!$sku && $product->is_type('variation')) {
                $parent = wc_get_product($product->get_parent_id());
                if ($parent) $sku = (string)$parent->get_sku();
            }
        }

        $gtin = $product ? pcoe_get_product_gtin($product) : '';
        $qty_total  = (float) $item->get_quantity();
        $unit_price = (float) $order->get_item_subtotal( $item, false, false );

        // читаємо план множинного списання з мети _pc_stock_breakdown (або fallback)
        $plan = $item->get_meta('_pc_stock_breakdown', true);
        if (!is_array($plan)) {
            $try = json_decode((string)$plan, true);
            $plan = is_array($try) ? $try : [];
        }
        if (!$plan) {
            // як у листах: пробуємо _stock_location_id/_stock_location_slug
            $tid = (int) $item->get_meta('_stock_location_id');
            if ($tid) { $plan = [$tid => (int)$qty_total]; }
        }
        if (!$plan && $product instanceof WC_Product) {
            $tid = pcoe_primary_location_for_product($product);
            if ($tid) $plan = [$tid => (int)$qty_total];
        }

        if ($split === 'per_loc' && $plan) {
            foreach ($plan as $tid=>$q) {
                $q = (int)$q; if ($q<=0) continue;
                $row = [
                    'sku'   => $sku,
                    'gtin'  => $gtin,
                    'name'  => $name,
                    'qty'   => $q,
                    'price' => wc_format_decimal($unit_price, 2),
                    'total' => wc_format_decimal($unit_price * $q, 2),
                    'note'  => 'Склад: '.pcoe_loc_name_by_id((int)$tid),
                ];
                $rows[] = array_intersect_key($row, array_flip($want_cols));
            }
        } else {
            $row = [
                'sku'   => $sku,
                'gtin'  => $gtin,
                'name'  => $name,
                'qty'   => $qty_total,
                'price' => wc_format_decimal($unit_price, 2),
                'total' => wc_format_decimal($unit_price * $qty_total, 2),
                'note'  => pcoe_note_from_plan((array)$plan),
            ];
            $rows[] = array_intersect_key($row, array_flip($want_cols));
        }
    }
    return $rows;
}

/* ==================== EXPORT: CSV / XLSX ==================== */
function pcoe_send_csv(array $header, array $rows, string $filename_base){
    nocache_headers();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="'.$filename_base.'.csv"');
    $out = fopen('php://output', 'w');
    // BOM для Excel
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out, $header, ';');
    foreach ($rows as $r) {
        $line = [];
        foreach ($r as $v) { $line[] = (string)$v; }
        fputcsv($out, $line, ';');
    }
    fclose($out);
    exit;
}

function pcoe_send_xlsx(array $header, array $rows, string $filename_base){
    if (!class_exists('\\PhpOffice\\PhpSpreadsheet\\Spreadsheet')) {
        $L = pcoe_labels();
        pcoe_send_csv(array_merge($header, ['_info']), array_map(function($r) use ($L){
            $r['_info'] = $L['xls_missing'];
            return $r;
        }, $rows), $filename_base);
        return;
    }
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $col = 1;
    foreach ($header as $h) $sheet->setCellValueByColumnAndRow($col++, 1, $h);
    $rnum = 2;
    foreach ($rows as $r) {
        $col = 1;
        foreach ($r as $v) $sheet->setCellValueByColumnAndRow($col++, $rnum, $v);
        $rnum++;
    }
    foreach (range(1, count($header)) as $i) { $sheet->getColumnDimensionByColumn($i)->setAutoSize(true); }
    nocache_headers();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="'.$filename_base.'.xlsx"');
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

/* ==================== AJAX HANDLER ==================== */
add_action('wp_ajax_pcoe_export', 'pcoe_export_handler');
add_action('wp_ajax_nopriv_pcoe_export', 'pcoe_export_handler');

function pcoe_export_handler(){
    if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'pcoe_export')) {
        wp_die('Bad nonce', '', ['response'=>403]);
    }
    $type  = sanitize_key($_GET['type'] ?? 'cart');       // cart|order
    $fmt   = sanitize_key($_GET['fmt']  ?? 'csv');        // csv|xlsx
    $cols  = array_filter(array_map('sanitize_key', explode(',', (string)($_GET['cols'] ?? ''))));
    $split = ($_GET['split'] ?? 'agg') === 'per_loc' ? 'per_loc' : 'agg';

    $avail = pcoe_available_columns();
    if (!$cols) $cols = ['sku','name','qty','price','total'];
    $cols = array_values(array_intersect(array_keys($avail), $cols));
    $header = array_map(function($k) use ($avail){ return $avail[$k]; }, $cols);

    $L = pcoe_labels();
    $filename = $L['filename_cart'].'-'.date('Ymd-His');

    if ($type === 'order') {
        $order_id = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
        if (!$order_id) wp_die('No order', '', ['response'=>400]);
        $order = wc_get_order($order_id);
        if (!$order) wp_die('Order not found', '', ['response'=>404]);
        if (!current_user_can('manage_woocommerce')) {
            $current = get_current_user_id();
            if ((int)$order->get_user_id() !== (int)$current) wp_die('Forbidden', '', ['response'=>403]);
        }
        $rows = pcoe_extract_from_order($order_id, $cols, $split);
        $filename = $L['filename_order'].'-'.$order->get_order_number().'-'.date('Ymd-His');
    } else {
        $rows = pcoe_extract_from_cart($cols, $split);
    }

    if ($fmt === 'xlsx') pcoe_send_xlsx($header, $rows, $filename);
    else                 pcoe_send_csv($header, $rows, $filename);
}

// Раньше echo; теперь возвращаем строку — удобно для хуков и фильтров.
function pcoe_render_controls_html(string $scope, int $order_id = 0): string {
    if ($scope === 'cart' && !WC()->cart) return '';

    $nonce = wp_create_nonce('pcoe_export');
    $L     = pcoe_labels();
    $cols  = pcoe_available_columns();

    $base = admin_url('admin-ajax.php?action=pcoe_export&_wpnonce='.$nonce.'&type='.$scope);
    if ($scope === 'order' && $order_id) $base .= '&order_id='.$order_id;

    ob_start();
    ?>
    <div class="pcoe-export" style="margin-top:16px">
      <div style="display:flex;gap:10px;flex-wrap:wrap;margin:8px 0">
        <a class="button" href="<?php echo esc_url($base.'&fmt=csv'); ?>"><?php echo esc_html($L['btn_csv']); ?></a>
        <a class="button" href="<?php echo esc_url($base.'&fmt=xlsx'); ?>"><?php echo esc_html($L['btn_xlsx']); ?></a>

        <label style="display:flex;align-items:center;gap:6px;margin-left:auto">
          <span><?php echo esc_html($L['split_label']); ?></span>
          <select class="pcoe-split" data-scope="<?php echo esc_attr($scope); ?>">
            <option value="agg"><?php echo esc_html($L['split_agg']); ?></option>
            <option value="per_loc"><?php echo esc_html($L['split_per_loc']); ?></option>
          </select>
        </label>
      </div>

      <details style="margin:8px 0"><summary><?php echo esc_html($L['conf_toggle']); ?></summary>
        <div class="pcoe-cols" data-scope="<?php echo esc_attr($scope); ?>" style="display:flex;gap:14px;flex-wrap:wrap;margin:10px 0">
          <?php foreach ($cols as $key=>$label): ?>
            <label style="display:flex;gap:6px;align-items:center">
              <input type="checkbox" class="pcoe-col" value="<?php echo esc_attr($key); ?>"> <?php echo esc_html($label); ?>
            </label>
          <?php endforeach; ?>
        </div>
      </details>
    </div>
    <?php
    return ob_get_clean();
}

// Поймаем одно из «низов» тоталов (на разных темах сработает хоть один раз).
add_action('woocommerce_after_cart_totals', function () {
    echo pcoe_render_controls_html('cart', 0);
});
add_action('woocommerce_cart_totals_after_shipping', function () {
    echo pcoe_render_controls_html('cart', 0);
});
add_action('woocommerce_proceed_to_checkout', function () {
    echo pcoe_render_controls_html('cart', 0);
});

add_filter('the_content', function ($content) {
    if (!is_cart()) return $content;

    // Якщо сторінка кошика зроблена блоком WooCommerce.
    global $post;
    if ($post && function_exists('has_block') && has_block('woocommerce/cart', $post)) {
        // Допишемо наш блок ПІСЛЯ всього контенту сторінки.
        $content .= pcoe_render_controls_html('cart', 0);
    }
    return $content;
});

add_action('wp_enqueue_scripts', function () {
    wp_enqueue_script('jquery');

    wp_add_inline_script('jquery', "
    jQuery(function($){
        var KEY_COLS='pcoeCols', KEY_SPLIT='pcoeSplit';
        function readCols(scope){ try{ var all=JSON.parse(localStorage.getItem(KEY_COLS)||'{}'); return all[scope]||[]; }catch(e){ return []; } }
        function writeCols(scope, arr){ try{ var all=JSON.parse(localStorage.getItem(KEY_COLS)||'{}'); all[scope]=arr; localStorage.setItem(KEY_COLS, JSON.stringify(all)); }catch(e){} }
        function readSplit(scope){ try{ var all=JSON.parse(localStorage.getItem(KEY_SPLIT)||'{}'); return all[scope]||'agg'; }catch(e){ return 'agg'; } }
        function writeSplit(scope, val){ try{ var all=JSON.parse(localStorage.getItem(KEY_SPLIT)||'{}'); all[scope]=val; localStorage.setItem(KEY_SPLIT, JSON.stringify(all)); }catch(e){} }

        // restore checkboxes
        $('.pcoe-cols').each(function(){
            var scope = $(this).data('scope');
            var sel = readCols(scope);
            if (sel.length){
                $(this).find('input.pcoe-col').each(function(){
                    if (sel.indexOf($(this).val())!==-1) $(this).prop('checked', true);
                });
            } else {
                $(this).find('input.pcoe-col[value=\"sku\"],input.pcoe-col[value=\"name\"],input.pcoe-col[value=\"qty\"],input.pcoe-col[value=\"price\"],input.pcoe-col[value=\"total\"]').prop('checked', true);
            }
        });

        // restore split select
        $('.pcoe-split').each(function(){
            var scope = $(this).data('scope');
            $(this).val(readSplit(scope));
        });

        // persist
        $(document).on('change','.pcoe-col',function(){
            var wrap = $(this).closest('.pcoe-cols');
            var scope = wrap.data('scope');
            var arr = [];
            wrap.find('input.pcoe-col:checked').each(function(){ arr.push($(this).val()); });
            writeCols(scope, arr);
        });
        $(document).on('change','.pcoe-split',function(){
            var scope = $(this).data('scope');
            writeSplit(scope, $(this).val());
        });

        // append params to export links
        $(document).on('click','.pcoe-export a.button',function(){
            var box = $(this).closest('.pcoe-export');
            var colsWrap = box.find('.pcoe-cols'); var scope = colsWrap.data('scope');
            var cols=[]; colsWrap.find('input.pcoe-col:checked').each(function(){ cols.push($(this).val()); });
            if(!cols.length){ cols=['sku','name','qty','price','total']; }
            var splitSel = box.find('.pcoe-split'); var split = splitSel.val() || 'agg';
            var href = new URL(this.href);
            href.searchParams.set('cols', cols.join(','));
            href.searchParams.set('split', split);
            this.href = href.toString();
        });

        // === авто-вмикати Примітку для режиму 'per_loc' ===
        function ensureNoteChecked(scope){
            var wrap = $('.pcoe-cols[data-scope=\"'+scope+'\"]');
            var note = wrap.find('input.pcoe-col[value=\"note\"]');
            if (!note.prop('checked')) {
                note.prop('checked', true).trigger('change');
            }
        }
        $('.pcoe-split').each(function(){
            var scope = $(this).data('scope');
            if ($(this).val()==='per_loc') ensureNoteChecked(scope);
        });
        $(document).on('change','.pcoe-split',function(){
            var scope = $(this).data('scope');
            if ($(this).val()==='per_loc') ensureNoteChecked(scope);
        });
    });
    ");
});

/* ==================== IMPORT to CART (CSV/XLS[X]) ==================== */

/** спроба знайти товар по GTIN (ключі як у нашому експорті) */
function pcoe_find_product_id_by_gtin(string $gtin): int {
    $keys = ['_global_unique_id','_wpm_gtin_code','_alg_ean','_ean','_sku_gtin'];
    foreach ($keys as $k) {
        $q = new WP_Query([
            'post_type'   => ['product','product_variation'],
            'post_status' => 'publish',
            'fields'      => 'ids',
            'posts_per_page' => 1,
            'meta_query'  => [
                ['key'=>$k,'value'=>$gtin,'compare'=>'='],
            ],
        ]);
        if ($q->have_posts()) {
            $id = (int)$q->posts[0];
            wp_reset_postdata();
            return $id;
        }
        wp_reset_postdata();
    }
    return 0;
}

/* === IMPORT UI: універсальний рендер + підключення на Cart і як fallback === */

/** HTML імпорту (повертає рядок) */
function pcoe_render_import_html(): string {
    if ( ! WC()->cart ) return '';
    $nonce = wp_create_nonce('pcoe_import_cart');
    ob_start(); ?>
    <div class="pcoe-import" style="margin-top:18px; padding-top:10px; border-top:1px dashed #e5e5e5">
        <details>
            <summary><strong>Імпорт у кошик</strong> (CSV / XLSX)</summary>
            <div style="margin-top:10px; display:flex; gap:12px; align-items:center; flex-wrap:wrap">
                <form id="pcoe-import-form" enctype="multipart/form-data" method="post" onsubmit="return false;">
                    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>">
                    <input type="file" name="file" accept=".csv,.xlsx,.xls" required>
                    <button type="submit" class="button">Імпортувати</button>
                    <span class="pcoe-import-msg" style="margin-left:8px; opacity:.8"></span>
                </form>
                <div style="font-size:12px; opacity:.8">
                    Формат CSV: <code>sku;qty</code> або <code>gtin;qty</code>. Розділювач <code>;</code> або <code>,</code>.
                </div>
            </div>
        </details>
    </div>
    <?php
    return ob_get_clean();
}

/** Вивід у класичні місця під тоталами кошика */
add_action('woocommerce_after_cart_totals', function () {
    echo pcoe_render_import_html();
});
add_action('woocommerce_cart_totals_after_shipping', function () {
    echo pcoe_render_import_html();
});
add_action('woocommerce_proceed_to_checkout', function () {
    echo pcoe_render_import_html();
});

/** Fallback для блочного кошика (дописуємо в контент сторінки) */
add_filter('the_content', function ($content) {
    if (!is_cart()) return $content;
    global $post;
    if ($post && function_exists('has_block') && has_block('woocommerce/cart', $post)) {
        $content .= pcoe_render_import_html();
    }
    return $content;
});

/** JS: відправка форми через AJAX і показ підсумку */
add_action('wp_enqueue_scripts', function () {
    if (!is_cart()) return;
    wp_add_inline_script('jquery', "
    jQuery(function($){
      $(document).on('submit','#pcoe-import-form',function(){
        var \$f = $(this), \$msg = \$f.find('.pcoe-import-msg');
        var fd = new FormData(this);
        fd.append('action','pcoe_import_cart');
        \$msg.text('Імпортуємо…');
        $.ajax({
          url: '".esc_js(admin_url('admin-ajax.php'))."',
          method:'POST',
          data: fd, contentType:false, processData:false,
          success: function(resp){
            if(resp && resp.success){
              \$msg.text('Додано позицій: '+resp.data.added+', пропущено: '+resp.data.skipped);
              // перезавантажити, щоб побачити кількості
              window.location.reload();
            }else{
              \$msg.text((resp && resp.data && resp.data.msg) ? resp.data.msg : 'Помилка імпорту.');
            }
          },
          error: function(){ \$msg.text('Помилка з\'єднання.'); }
        });
        return false;
      });
    });
    ");
});

/** AJAX: розбір файлу та додавання у кошик */
add_action('wp_ajax_pcoe_import_cart',        'pcoe_import_cart_handler');
add_action('wp_ajax_nopriv_pcoe_import_cart', 'pcoe_import_cart_handler');

function pcoe_import_cart_handler(){
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'pcoe_import_cart')) {
        wp_send_json_error(['msg'=>'Bad nonce'], 403);
    }
    if (!WC()->cart) wp_send_json_error(['msg'=>'Cart not available'], 400);
    if (empty($_FILES['file']['tmp_name'])) wp_send_json_error(['msg'=>'Файл не передано'], 400);

    $tmp = $_FILES['file']['tmp_name'];
    $name = strtolower((string)($_FILES['file']['name'] ?? ''));
    $rows = [];

    // 1) XLS/XLSX якщо є PhpSpreadsheet
    if (preg_match('~\\.(xlsx|xls)$~i', $name)) {
        if (class_exists('\\PhpOffice\\PhpSpreadsheet\\IOFactory')) {
            try{
                $xls = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp);
                $sheet = $xls->getActiveSheet();
                foreach ($sheet->toArray() as $r) {
                    $rows[] = $r;
                }
            }catch(\Throwable $e){
                wp_send_json_error(['msg'=>'Не вдалося прочитати XLSX: '.$e->getMessage()], 500);
            }
        } else {
            wp_send_json_error(['msg'=>'Підтримка XLSX недоступна на цьому сервері. Використайте CSV.'], 400);
        }
    } else {
        // 2) CSV (авто-визначення роздільника)
        $sep = ';';
        $peek = file_get_contents($tmp, false, null, 0, 2048);
        if ($peek && substr_count($peek, ',') > substr_count($peek, ';')) $sep = ',';
        if (($fh = fopen($tmp, 'r')) !== false) {
            while (($r = fgetcsv($fh, 0, $sep)) !== false) {
                if ($r === [null] || $r === false) continue;
                $rows[] = $r;
            }
            fclose($fh);
        }
    }

    if (!$rows) wp_send_json_error(['msg'=>'Порожній файл або невірний формат'], 400);

    // Мапінг колонок: шукаємо шапку
    $start = 0; $map = ['sku'=>0,'qty'=>1,'gtin'=>null];
    $h = array_map('strtolower', array_map('trim', (array)$rows[0]));
    if (in_array('sku', $h, true) || in_array('gtin', $h, true)) {
        $map = ['sku'=>array_search('sku',$h), 'qty'=>array_search('qty',$h), 'gtin'=>array_search('gtin',$h)];
        $start = 1;
    }

    $added = 0; $skipped = 0;
    for ($i=$start; $i<count($rows); $i++){
        $r = $rows[$i];

        $sku  = ($map['sku'] !== false && $map['sku'] !== null && isset($r[$map['sku']]))  ? trim((string)$r[$map['sku']])  : '';
        $gtin = ($map['gtin'] !== false && $map['gtin'] !== null && isset($r[$map['gtin']])) ? trim((string)$r[$map['gtin']]) : '';
        $qty  = ($map['qty'] !== false && $map['qty'] !== null && isset($r[$map['qty']]))  ? (float)$r[$map['qty']] : 0;

        if ($qty <= 0) { $skipped++; continue; }

        $pid = 0;
        if ($sku !== '')  $pid = wc_get_product_id_by_sku($sku);
        if (!$pid && $gtin !== '') $pid = pcoe_find_product_id_by_gtin($gtin);
        if (!$pid) { $skipped++; continue; }

        $product = wc_get_product($pid);
        if (!$product) { $skipped++; continue; }

        // обмеження на доступну кількість (мін/макс)
        $minq = max(1, (int)$product->get_min_purchase_quantity());
        if ($qty < $minq) $qty = $minq;
        $maxq = (int)$product->get_max_purchase_quantity();
        if ($maxq > 0 && $qty > $maxq) $qty = $maxq;

        // додаємо
        $res = WC()->cart->add_to_cart($pid, $qty);
        if ($res) $added++; else $skipped++;
    }

    wp_send_json_success(['added'=>$added, 'skipped'=>$skipped]);
}