<?php
/*
Plugin Name: Stock Locations UI (archive + single + cart)
Description: Универсальный блок складов/остатков + план списания. Работает в карточке, каталоге и корзине/чекауте.
Version: 1.2.0
Author: PaintCore
*/
if (!defined('ABSPATH')) exit;

/** ========================= UI LABELS =========================
 *  Можна перевизначити у wp-config.php константами:
 *    SLU_LBL_FROM, SLU_LBL_OTHERS, SLU_LBL_TOTAL, SLU_LBL_ALLOCATION
 *  або фільтром: add_filter('slu_ui_labels', fn($L)=>...)
 */
if (!defined('SLU_LBL_FROM'))       define('SLU_LBL_FROM', 'Зі складу');
if (!defined('SLU_LBL_OTHERS'))     define('SLU_LBL_OTHERS', 'Інші скл.');
if (!defined('SLU_LBL_TOTAL'))      define('SLU_LBL_TOTAL', 'Загал.');
if (!defined('SLU_LBL_ALLOCATION')) define('SLU_LBL_ALLOCATION', 'Списання');

if (!function_exists('slu_labels')) {
    function slu_labels(): array {
        $labels = [
            'from'       => SLU_LBL_FROM,
            'others'     => SLU_LBL_OTHERS,
            'total'      => SLU_LBL_TOTAL,
            'allocation' => SLU_LBL_ALLOCATION,
        ];
        return apply_filters('slu_ui_labels', $labels);
    }
}

/** ========================= HELPERS ========================= */

if (!function_exists('slu_get_primary_location_term_id')) {
    function slu_get_primary_location_term_id($product_id){
        $pid = (int)$product_id; if(!$pid) return 0;
        $term_id = (int)get_post_meta($pid, '_yoast_wpseo_primary_location', true);
        if(!$term_id){
            $parent_id = (int)wp_get_post_parent_id($pid);
            if($parent_id){
                $term_id = (int)get_post_meta($parent_id, '_yoast_wpseo_primary_location', true);
            }
        }
        return $term_id ?: 0;
    }
}

if (!function_exists('slu_total_available_qty')) {
    /** сумма остатков по всем складам (с кешем в _stock, фолбэк — суммирование _stock_at_%) */
    function slu_total_available_qty(WC_Product $product){
        $pid = (int)$product->get_id();
        $sum = get_post_meta($pid, '_stock', true);
        if ($sum !== '' && $sum !== null) {
            return max(0, (int)round((float)$sum));
        }
        global $wpdb;
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta}
             WHERE post_id=%d AND meta_key LIKE %s",
            $pid, $wpdb->esc_like('_stock_at_').'%'
        ));
        $total = 0.0;
        foreach ((array)$rows as $v) $total += (float)$v;

        if ($total <= 0 && $product->is_type('variation')) {
            $parent_id = (int)$product->get_parent_id();
            if ($parent_id) {
                $rows = $wpdb->get_col($wpdb->prepare(
                    "SELECT meta_value FROM {$wpdb->postmeta}
                     WHERE post_id=%d AND meta_key LIKE %s",
                    $parent_id, $wpdb->esc_like('_stock_at_').'%'
                ));
                foreach ((array)$rows as $v) $total += (float)$v;
            }
        }
        return max(0, (int)round($total));
    }
}

// доступное к добавлению = общий остаток минус то, что уже в корзине
if (!function_exists('slu_available_for_add')) {
    function slu_available_for_add(WC_Product $product): int {
        $total   = slu_total_available_qty($product);
        $in_cart = slu_cart_qty_for_product($product);
        return max(0, (int)$total - (int)$in_cart);
    }
}

if (!function_exists('slu_cart_qty_for_product')) {
    /** сколько этого товара/вариации уже в корзине */
    function slu_cart_qty_for_product(WC_Product $product): int{
        $pid = (int)$product->get_id();
        $vid = $product->is_type('variation') ? $pid : 0;
        $sum = 0;
        if (WC()->cart) {
            foreach (WC()->cart->get_cart() as $item) {
                $p = (int)($item['product_id'] ?? 0);
                $v = (int)($item['variation_id'] ?? 0);
                if ($p === $pid && $v === $vid) $sum += (int)($item['quantity'] ?? 0);
            }
        }
        return $sum;
    }
}

if (!function_exists('slu_collect_location_stocks_for_product')) {
    /** собрать “імʼя — qty” по всіх складах, привʼязаних до товару */
    function slu_collect_location_stocks_for_product(WC_Product $product): array{
        $result   = [];
        $term_ids = wp_get_object_terms($product->get_id(), 'location', ['fields'=>'ids','hide_empty'=>false]);
        if (is_wp_error($term_ids)) $term_ids = [];

        if (empty($term_ids)) {
            $term_ids = get_terms(['taxonomy'=>'location','fields'=>'ids','hide_empty'=>false]);
            if (is_wp_error($term_ids)) $term_ids = [];
        }
        if (empty($term_ids)) return $result;

        $is_var    = $product->is_type('variation');
        $parent_id = $is_var ? (int)$product->get_parent_id() : 0;

        foreach ($term_ids as $tid) {
            $tid = (int)$tid;
            $meta_key = '_stock_at_'.$tid;
            $qty = get_post_meta($product->get_id(), $meta_key, true);
            if (($qty === '' || $qty === null) && $is_var && $parent_id) {
                $qty = get_post_meta($parent_id, $meta_key, true);
            }
            $qty = ($qty === '' || $qty === null) ? null : (int)$qty;
            if ($qty === null) continue;

            $term = get_term($tid, 'location');
            if (!$term || is_wp_error($term)) continue;

            $result[$tid] = ['name'=>$term->name, 'qty'=>$qty];
        }
        return $result;
    }
}

if (!function_exists('slu_render_other_locations_line')) {
    function slu_render_other_locations_line(WC_Product $product): string{
        $primary = slu_get_primary_location_term_id($product->get_id());
        $all     = slu_collect_location_stocks_for_product($product);
        if (empty($all)) return '';
        $parts = [];
        foreach ($all as $tid=>$row) {
            if ($primary && (int)$tid === (int)$primary) continue;
            if ((int)$row['qty'] <= 0) continue;
            $parts[] = esc_html($row['name']).' — '.(int)$row['qty'];
        }
        return $parts ? implode(', ', $parts) : '';
    }
}

if (!function_exists('slu_render_primary_location_line')) {
    function slu_render_primary_location_line(WC_Product $product): string{
        $primary = slu_get_primary_location_term_id($product->get_id());
        if (!$primary) return '';
        $meta_key = '_stock_at_'.(int)$primary;
        $qty = get_post_meta($product->get_id(), $meta_key, true);
        if (($qty === '' || $qty === null) && $product->is_type('variation')) {
            $parent_id = $product->get_parent_id();
            if ($parent_id) $qty = get_post_meta($parent_id, $meta_key, true);
        }
        $qty  = ($qty === '' || $qty === null) ? null : (int)$qty;
        $term = get_term($primary, 'location');
        if (!$term || is_wp_error($term)) return '';
        return esc_html($term->name).( $qty !== null ? ' — '.(int)$qty : '' );
    }
}

// Збираємо й впорядковуємо локації під обраний режим
function pc_build_stock_view(WC_Product $product): array {
    if (!function_exists('slu_collect_location_stocks_for_product')) {
        return ['mode'=>'auto','preferred'=>null,'primary'=>null,'ordered'=>[], 'sum'=>0];
    }
    $all = (array) slu_collect_location_stocks_for_product($product);

    // ховаємо нульові склади
    $all = array_filter($all, static function($row){
        return (int)($row['qty'] ?? 0) > 0;
    });

    $sum = 0; foreach ($all as $row) $sum += (int)($row['qty'] ?? 0);

    $pref = function_exists('pc_get_alloc_pref') ? pc_get_alloc_pref() : ['mode'=>'auto','term_id'=>0];
    $mode = $pref['mode'];
    $sel  = (int)($pref['term_id'] ?? 0);

    $primary = function_exists('slu_get_primary_location_term_id') ? (int) slu_get_primary_location_term_id($product->get_id()) : 0;

    // single: тільки вибраний склад
    if ($mode === 'single') {
        $only = [];
        if ($sel && isset($all[$sel])) {
            $only[$sel] = $all[$sel];
        }
        return [
            'mode'      => 'single',
            'preferred' => $sel ?: null,
            'primary'   => $primary ?: null,
            'ordered'   => $only,
            'sum'       => isset($only[$sel]['qty']) ? (int)$only[$sel]['qty'] : 0
        ];
    }

    // auto/manual
    $ordered = [];
    if ($mode === 'manual' && $sel && isset($all[$sel])) {
        $ordered[$sel] = $all[$sel]; unset($all[$sel]);
    }
    if ($primary && isset($all[$primary])) {
        $ordered[$primary] = $all[$primary]; unset($all[$primary]);
    }
    uasort($all, function($a,$b){ return (int)($b['qty'] ?? 0) <=> (int)($a['qty'] ?? 0); });
    $ordered += $all;

    return ['mode'=>$mode,'preferred'=>$sel?:null,'primary'=>$primary?:null,'ordered'=>$ordered,'sum'=>(int)$sum];
}

function pc_fmt_loc_line(array $row): string {
    $name = isset($row['name']) ? (string)$row['name'] : '';
    $qty  = isset($row['qty'])  ? (int)$row['qty']     : 0;
    return esc_html($name).' — '.esc_html($qty);
}

/** =================== ПЛАН СПИСАНИЯ =================== */

if (!function_exists('slu_get_allocation_plan')) {
    function slu_get_allocation_plan(WC_Product $product, int $need, string $strategy='primary_first'): array{
        $need = max(0,(int)$need);
        if ($need === 0) return [];
        $custom = apply_filters('slu_allocation_plan', null, $product, $need, $strategy);
        if (is_array($custom)) return $custom;

        $all = slu_collect_location_stocks_for_product($product);
        if (empty($all)) return [];

        $primary_id = (int)slu_get_primary_location_term_id($product->get_id());

        $ordered = [];
        if ($primary_id && isset($all[$primary_id])) {
            $ordered[$primary_id] = $all[$primary_id];
            unset($all[$primary_id]);
        }
        uasort($all, function($a,$b){ return (int)$b['qty'] <=> (int)$a['qty']; });
        $ordered += $all;

        $plan = []; $left = $need;
        foreach ($ordered as $tid=>$row) {
            if ($left <= 0) break;
            $take = min($left, max(0,(int)$row['qty']));
            if ($take > 0) { $plan[(int)$tid] = $take; $left -= $take; }
        }
        return $plan;
    }
}

if (!function_exists('slu_render_allocation_line')) {
    function slu_render_allocation_line(WC_Product $product, int $need): string{
        $plan = slu_get_allocation_plan($product, $need);
        if (empty($plan)) return '';
        $parts = [];
        foreach ($plan as $tid=>$qty) {
            $t = get_term((int)$tid, 'location');
            if ($t && !is_wp_error($t)) $parts[] = esc_html($t->name).' — '.(int)$qty;
        }
        return implode(', ', $parts);
    }
}

/** =================== ПАНЕЛЬ СКЛАДІВ (UI) =================== */

if (!function_exists('slu_render_stock_panel')) {
    function slu_render_stock_panel( WC_Product $product, array $opts = [] ): string {
        $o = array_merge([
            'wrap_class'       => '',
            'show_primary'     => true,
            'show_others'      => true,
            'show_total'       => true,
            'show_incart'      => false,
            'show_incart_plan' => false,
            'hide_when_zero'   => false,
        ], $opts);

        $v = pc_build_stock_view($product);
        $L = slu_labels();

        // режим "тільки обраний склад"
        if ($v['mode'] === 'single') {
            if (empty($v['ordered'])) return '';
            $row = reset($v['ordered']);
            $onlyLine = pc_fmt_loc_line($row);

            ob_start(); ?>
            <div class="slu-stock-box <?= esc_attr($o['wrap_class']) ?>">
                <div>
                    <strong><?= esc_html($L['from']) ?>:</strong>
                    <?= $onlyLine ?>
                </div>
            </div>
            <?php
            return (string) ob_get_clean();
        }

        // auto/manual
        $firstHtml = '';
        $others    = [];
        foreach ($v['ordered'] as $tid => $row) {
            $q = (int)($row['qty'] ?? 0);
            if ($q <= 0) continue;
            $line = pc_fmt_loc_line($row);
            if ($firstHtml === '') $firstHtml = '<span class="is-preferred">'.$line.'</span>';
            else $others[] = $line;
        }

        if ($o['hide_when_zero'] && $firstHtml === '' && empty($others)) {
            return '';
        }

        ob_start(); ?>
        <div class="slu-stock-box <?= esc_attr($o['wrap_class']) ?>">
            <?php if ($firstHtml !== ''): ?>
                <div><strong><?= esc_html($L['from']) ?>:</strong> <?= $firstHtml ?></div>
            <?php endif; ?>

            <?php if (!empty($others)): ?>
                <div><strong><?= esc_html($L['others']) ?>:</strong> <?= implode(', ', $others) ?></div>
            <?php endif; ?>

            <div>
                <span class="slu-nb">
                    <strong><?= esc_html($L['total']) ?>:</strong>
                    <span class="slu-stock-total"><?= (int)$v['sum'] ?></span>
                </span>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}

/** =================== ВСТАВКИ В ШАБЛОНИ =================== */

/* PDP */
add_action('woocommerce_single_product_summary', function(){
    global $product;
    if (!($product instanceof WC_Product)) return;
    echo slu_render_stock_panel($product, [
        'show_primary'     => true,
        'show_others'      => true,
        'show_total'       => true,
        'show_incart'      => true,
        'show_incart_plan' => true,
        'hide_when_zero'   => false,
        'wrap_class'       => '',
    ]);
}, 25);

/* Каталог */
add_action('woocommerce_after_shop_loop_item_title', function(){
    global $product;
    if (!($product instanceof WC_Product)) return;
    echo slu_render_stock_panel($product, [
        'show_primary'     => true,
        'show_others'      => true,
        'show_total'       => true,
        'show_incart'      => false,
        'show_incart_plan' => false,
        'hide_when_zero'   => true,
        'wrap_class'       => 'slu-stock-mini',
    ]);
}, 11);

/** =================== КОРЗИНА / ЧЕКАУТ =================== */

if (!function_exists('slu_cart_allocation_row')) {
    function slu_cart_allocation_row($item_data, $cart_item){
        if (!is_array($item_data)) $item_data = [];
        $is_ctx = (function_exists('is_cart') && is_cart()) || (function_exists('is_checkout') && is_checkout());
        if (!$is_ctx) return $item_data;

        $product = $cart_item['data'] ?? null;
        if (!($product instanceof WC_Product)) return $item_data;

        $qty = max(0, (int)($cart_item['quantity'] ?? 0));
        if ($qty <= 0) return $item_data;

        $line = slu_render_allocation_line($product, $qty);
        if ($line !== '') {
            $L = slu_labels();
            $item_data[] = [
                'key'     => esc_html($L['allocation']),
                'value'   => $line,
                'display' => $line,
            ];
        }
        return $item_data;
    }
}
add_filter('woocommerce_get_item_data', 'slu_cart_allocation_row', 30, 2);

/** =================== (опц.) Шорткод для будь-яких місць =================== */
// [pc_stock_allocation product_id="43189" qty="3"]
add_shortcode('pc_stock_allocation', function($atts){
    $a = shortcode_atts(['product_id'=>0,'qty'=>0], $atts, 'pc_stock_allocation');
    $prod = wc_get_product((int)$a['product_id']);
    if (!($prod instanceof WC_Product)) return '';
    $qty  = max(0,(int)$a['qty']);
    $line = slu_render_allocation_line($prod, $qty);
    if (!$line) return '';
    $L = slu_labels();
    return '<div class="slu-stock-box slu-stock-mini"><strong>'.esc_html($L['allocation']).':</strong> '.esc_html($line).'</div>';
});

/* Woo stock HTML: показуємо стандартний лише коли товару немає */
add_filter('woocommerce_get_stock_html', function($html, $product){
    if (!($product instanceof WC_Product)) return $html;
    if ($product->is_in_stock()) return '';
    return $html;
}, 99, 2);

/** =================== CSS =================== */
add_action('wp_head', function(){
    echo '<style>
    .single-product .slu-stock-box{border:1px dashed #e0e0e0;padding:8px 10px;border-radius:6px;background:#fafafa;font-size:14px;color:#333;margin:10px 0 6px}
    .products .slu-stock-mini{border:0;padding:0;margin-top:6px;background:transparent;font-size:12px;line-height:1.25;color:#2e7d32}
    .products .slu-stock-mini div{margin:0 0 2px}
    .products .slu-stock-mini strong{color:#333;font-weight:600}
    @media (max-width:480px){.products .slu-stock-mini{font-size:11px;margin-top:4px}}
    .slu-nb{display:inline-flex;align-items:baseline;gap:.25em;white-space:nowrap}
    .slu-nb strong{display:inline;}
    .slu-nb .slu-stock-total{display:inline !important;}
    </style>';
});

/** =================== Компактор рядка «Інші скл.» =================== */
add_filter('slu_stock_panel_html', function($html, $product, $view, $opts){
    if (!isset($opts['wrap_class']) || strpos($opts['wrap_class'],'slu-stock-mini') === false) return $html;

    $L = function_exists('slu_labels') ? slu_labels() : ['others'=>'Інші скл.'];
    $needle = $L['others'];

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8" ?>'.$html);
    libxml_clear_errors();

    $xpath = new DOMXPath($doc);
    foreach ($xpath->query('//div[strong[contains(text(), "'.$needle.'")]]') as $div) {
        $text = trim($div->textContent);
        $parts = explode(':', $text, 2);
        $label = $parts[0].':';
        $list  = isset($parts[1]) ? trim($parts[1]) : '';

        $short = mb_strlen($list) > 40 ? mb_substr($list,0,40).'…' : $list;

        while ($div->firstChild) $div->removeChild($div->firstChild);
        $strong = $doc->createElement('strong', $label.' ');
        $div->appendChild($strong);
        $span = $doc->createElement('span', $short);
        $span->setAttribute('title', $list);
        $div->appendChild($span);
    }
    $body = $doc->getElementsByTagName('body')->item(0);
    return $doc->saveHTML($body->firstChild);
}, 10, 4);