<?php
/*
Plugin Name: PC Wholesale Quick Order
Description: Табличний «швидкий заказ» для оптовиків + масове додавання в кошик.
Version: 1.3.2
Author: PaintCore
*/
if (!defined('ABSPATH')) exit;

/** ================= UI LABELS =================
 * Можно переопределить в wp-config.php:
 *   PCQO_LBL_ADD_ALL, PCQO_LBL_SORT, PCQO_LBL_BY_TITLE, PCQO_LBL_BY_SKU, PCQO_LBL_BY_PRICE,
 *   PCQO_LBL_TOGGLE_ASC, PCQO_LBL_TOGGLE_DESC,
 *   PCQO_LBL_HIDE0, PCQO_LBL_SHOW0,
 *   PCQO_LBL_SELECTED_SUMMARY, PCQO_LBL_NO_ITEMS,
 *   PCQO_LBL_NOT_ENOUGH_RIGHTS, PCQO_LBL_NOT_FOUND
 * Или фильтром: add_filter('pcqo_labels', fn($L)=>{ ...; return $L; });
 */
if (!defined('PCQO_LBL_ADD_ALL'))          define('PCQO_LBL_ADD_ALL', 'Додати все в кошик');
if (!defined('PCQO_LBL_SORT'))             define('PCQO_LBL_SORT', 'Сортувати:');
if (!defined('PCQO_LBL_BY_TITLE'))         define('PCQO_LBL_BY_TITLE', 'по назві');
if (!defined('PCQO_LBL_BY_SKU'))           define('PCQO_LBL_BY_SKU', 'по артиклю');
if (!defined('PCQO_LBL_BY_PRICE'))         define('PCQO_LBL_BY_PRICE', 'по ціні');
if (!defined('PCQO_LBL_TOGGLE_ASC'))       define('PCQO_LBL_TOGGLE_ASC', '⇅ ЗБІЛ');
if (!defined('PCQO_LBL_TOGGLE_DESC'))      define('PCQO_LBL_TOGGLE_DESC', '⇅ ЗМЕН');
if (!defined('PCQO_LBL_HIDE0'))            define('PCQO_LBL_HIDE0', 'Сховати 0');
if (!defined('PCQO_LBL_SHOW0'))            define('PCQO_LBL_SHOW0', 'Показати 0');
if (!defined('PCQO_LBL_SELECTED_SUMMARY')) define('PCQO_LBL_SELECTED_SUMMARY', 'Обрано позицій: %d, шт: %s');
if (!defined('PCQO_LBL_NO_ITEMS'))         define('PCQO_LBL_NO_ITEMS', 'Немає вибраних кількостей.');
if (!defined('PCQO_LBL_NOT_ENOUGH_RIGHTS'))define('PCQO_LBL_NOT_ENOUGH_RIGHTS', 'Недостатньо прав для швидкого замовлення.');
if (!defined('PCQO_LBL_NOT_FOUND'))        define('PCQO_LBL_NOT_FOUND', 'Товари не знайдені.');

function pcqo_labels(): array {
    $L = [
        'add_all'   => PCQO_LBL_ADD_ALL,
        'sort'      => PCQO_LBL_SORT,
        'by_title'  => PCQO_LBL_BY_TITLE,
        'by_sku'    => PCQO_LBL_BY_SKU,
        'by_price'  => PCQO_LBL_BY_PRICE,
        'asc'       => PCQO_LBL_TOGGLE_ASC,
        'desc'      => PCQO_LBL_TOGGLE_DESC,
        'hide0'     => PCQO_LBL_HIDE0,
        'show0'     => PCQO_LBL_SHOW0,
        'summary'   => PCQO_LBL_SELECTED_SUMMARY,
        'noitems'   => PCQO_LBL_NO_ITEMS,
        'norights'  => PCQO_LBL_NOT_ENOUGH_RIGHTS,
        'notfound'  => PCQO_LBL_NOT_FOUND,
    ];
    return apply_filters('pcqo_labels', $L);
}

/** ===== Helpers ===== */
function pcqo_current_location_slug(): string {
    if (!empty($_GET['location'])) {
        $first = explode(',', (string) $_GET['location']);
        return sanitize_title(trim($first[0]));
    }
    if (!empty($_COOKIE['psu_location'])) {
        return sanitize_title((string) $_COOKIE['psu_location']);
    }
    return '';
}
function pcqo_current_stock_mode(): string {
    if (!empty($_GET['stock_mode']))           $m = sanitize_key($_GET['stock_mode']);
    elseif (!empty($_COOKIE['psu_stock_mode']))$m = sanitize_key($_COOKIE['psu_stock_mode']);
    else $m = 'selected_only';
    return in_array($m, ['selected_only','prefer_selected','sum_all'], true) ? $m : 'selected_only';
}

/** Доступно к добавлению (через Stock Locations UI) */
function pcqo_available_qty_for_mode(int $product_id): int {
    $product = wc_get_product($product_id);
    if ($product && function_exists('slu_available_for_add')) {
        return max(0, (int) slu_available_for_add($product));
    }
    $stock  = (int) wc_stock_amount(get_post_meta($product_id, '_stock', true));
    $inCart = (WC()->cart) ? (int) (WC()->cart->get_cart_item_quantities()[$product_id] ?? 0) : 0;
    return max(0, $stock - $inCart);
}

/** Рендер блока складів (каталоговый компакт) */
function pcqo_render_stock_html(int $product_id): string {
    $product = wc_get_product($product_id);
    if ($product && function_exists('slu_render_stock_panel')) {
        return (string) slu_render_stock_panel($product, [
            'show_primary'     => true,
            'show_others'      => true,
            'show_total'       => true,
            'show_incart'      => false,
            'show_incart_plan' => false,
            'hide_when_zero'   => true,
            'wrap_class'       => 'slu-stock-mini',
        ]);
    }
    $total = (int) wc_stock_amount(get_post_meta($product_id, '_stock', true));
    return '<span class="muted">Загал.: '.esc_html($total).'</span>';
}

/** ===== Шорткод [pc_quick_order] ===== */
add_shortcode('pc_quick_order', function($atts){
    if (!function_exists('wc_get_product')) return '';
    $L = pcqo_labels();

    $a = shortcode_atts([
        'cat'        => '',
        'per_page'   => 50,
        'show_stock' => 1,
        'role_only'  => '',
        'orderby'    => 'sku', // title|sku|date|price
        'order'      => 'ASC',
    ], $atts, 'pc_quick_order');
    $a['show_stock'] = (int) $a['show_stock'];

    if (isset($_GET['orderby'])) {
        $g = strtolower(sanitize_text_field($_GET['orderby']));
        if (in_array($g, ['title','sku','date','price'], true)) $a['orderby'] = $g;
    }
    if (isset($_GET['order'])) {
        $ord = strtoupper(sanitize_text_field($_GET['order'])) === 'DESC' ? 'DESC' : 'ASC';
        $a['order'] = $ord;
    }

    if (!empty($a['role_only'])) {
        $need = array_map('trim', explode(',', $a['role_only']));
        $u = wp_get_current_user();
        if (!$u || empty($u->roles) || count(array_intersect($need, $u->roles)) === 0) {
            return '<p>'.esc_html($L['norights']).'</p>';
        }
    }

    $cat_slugs = [];
    if (!empty($_GET['cat']))            $cat_slugs = array_filter(array_map('sanitize_title', preg_split('/[,\s]+/', (string)$_GET['cat'])));
    if (!$cat_slugs && !empty($a['cat']))$cat_slugs = array_filter(array_map('sanitize_title', preg_split('/[,\s]+/', (string)$a['cat'])));
    if (!$cat_slugs && is_product_category()){
        $term = get_queried_object(); if ($term && !is_wp_error($term)) $cat_slugs = [$term->slug];
    }

    $paged = isset($_GET['qo_page']) ? max(1, (int)$_GET['qo_page']) : 1;
    $order = (strtoupper($a['order']) === 'DESC') ? 'DESC' : 'ASC';

    $args = [
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => max(1, (int)$a['per_page']),
        'paged'          => $paged,
        'order'          => $order,
        'no_found_rows'  => false,
        'ignore_sticky_posts' => 1,
        'pc_qo'          => 1,
        'tax_query'      => [],
        'meta_query'     => [],
    ];
    $args['tax_query'][] = [
        'taxonomy' => 'product_type','field'=>'slug','terms'=>['simple'],'operator'=>'IN',
    ];
    $args['meta_query'][] = ['key'=>'_price','value'=>'','compare'=>'!='];
    switch (strtolower($a['orderby'])) {
        case 'sku':   $args['meta_key'] = '_sku';   $args['orderby'] = 'meta_value';     $args['meta_type'] = 'CHAR'; break;
        case 'price': $args['meta_key'] = '_price'; $args['orderby'] = 'meta_value_num'; $args['ignore_sticky_posts'] = true; break;
        case 'date':  $args['orderby'] = 'date'; break;
        default:      $args['orderby'] = 'title';
    }
    if ($cat_slugs) {
        $args['tax_query'][] = [
            'taxonomy'=>'product_cat','field'=>'slug','terms'=>$cat_slugs,
            'operator'=>'IN','include_children'=>true,
        ];
    }

    $q = new WP_Query($args);

    $build_pager = function(WP_Query $q, int $paged): string {
        if ((int)$q->max_num_pages <= 1) return '';
        $base = remove_query_arg('qo_page');
        $out  = '<nav class="pc-qo-pager" style="margin:12px 0;display:flex;gap:8px;flex-wrap:wrap">';
        for ($i=1; $i <= (int)$q->max_num_pages; $i++) {
            $link = add_query_arg('qo_page', $i, $base);
            $cur  = ($i === $paged) ? ' style="font-weight:700;text-decoration:underline"' : '';
            $out .= '<a href="'.esc_url($link).'"'.$cur.'>'.esc_html($i).'</a> ';
        }
        $out .= '</nav>';
        return $out;
    };
    $pager_html = $build_pager($q, $paged);

    /* ==== Breadcrumbs for Quick Order ==== */
    $pcqo_render_breadcrumbs = function(array $cat_slugs){
    $post = get_post();
    $base = $post ? get_permalink($post) : home_url('/');

    echo '<nav class="woocommerce-breadcrumb" style="margin:6px 0 12px">';
    // Прибрали «Головна»
    echo '<a href="'.esc_url($base).'">Список товару</a>';

    // Якщо вибрана рівно одна категорія — показуємо її предків + її саму
    if (count($cat_slugs) === 1) {
        $slug = $cat_slugs[0];
        $term = get_term_by('slug', $slug, 'product_cat');
        if ($term && !is_wp_error($term)) {
            $anc = array_reverse(get_ancestors($term->term_id, 'product_cat'));
            foreach ($anc as $aid) {
                $t = get_term($aid, 'product_cat');
                if ($t && !is_wp_error($t)) {
                    echo ' <span class="breadcrumb-delimiter">→</span> ';
                    echo '<a href="'.esc_url(add_query_arg('cat', $t->slug, $base)).'">'.esc_html($t->name).'</a>';
                }
            }
            echo ' <span class="breadcrumb-delimiter">→</span> ';
            echo '<a href="'.esc_url(add_query_arg('cat', $term->slug, $base)).'">'.esc_html($term->name).'</a>';
        }
    }
    echo '</nav>';
};
/* ==== /Breadcrumbs ==== */

$hz_on = ( isset($_GET['hide_zero']) && $_GET['hide_zero'] === '1' );
$nonce = wp_create_nonce('pc_bulk_add');

ob_start(); ?>
<div class="pc-qo-wrap">

    <?php
    // WooCommerce notices завжди зверху
    if ( function_exists('woocommerce_output_all_notices') ) {
        woocommerce_output_all_notices();
    } elseif ( function_exists('wc_print_notices') ) {
        wc_print_notices();
    }

    // Крихти (Breadcrumbs)
    $pcqo_render_breadcrumbs($cat_slugs);

    // Якщо немає товарів — показуємо повідомлення та завершуємо
    if ( ! $q->have_posts() ) : ?>
        <p><?php echo esc_html( $L['notfound'] ); ?></p>
</div>
<?php
        return ob_get_clean();
    endif;
    ?>

    <div class="pc-qo-toolbar" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <button type="button" class="button button-primary pc-qo-addall" data-nonce="<?php echo esc_attr($nonce); ?>">
            <?php echo esc_html($L['add_all']); ?>
        </button>
        <?php
        $base = remove_query_arg(['qo_page']);
        $mk = function($key, $order = null) use ($base, $a) {
            return esc_url( add_query_arg( ['orderby'=>$key, 'order'=>$order?:$a['order'], 'qo_page'=>1], $base ) );
        };
        $bold = function($key, $label) use ($a, $mk) {
            $url   = $mk($key);
            $isCur = ($a['orderby'] === $key);
            $style = $isCur ? ' style="font-weight:700;text-decoration:underline"' : '';
            return '<a href="'.$url.'"'.$style.'>'.$label.'</a>';
        };
        $nextOrder = ($a['order'] === 'ASC') ? 'DESC' : 'ASC';
        $toggleUrl = $mk($a['orderby'], $nextOrder);
        $toggleLbl = ($nextOrder === 'DESC') ? $L['asc'] : $L['desc'];

        $hz_url  = esc_url( add_query_arg(['hide_zero'=>$hz_on ? '0':'1', 'qo_page'=>1], $base) );
        $hz_text = $hz_on ? $L['show0'] : $L['hide0'];
        ?>
        <span style="opacity:.7"><?php echo esc_html($L['sort']); ?></span>
        <?= $bold('title', esc_html($L['by_title'])); ?> ·
        <?= $bold('sku',   esc_html($L['by_sku']));   ?> ·
        <?= $bold('price', esc_html($L['by_price'])); ?>
        <a href="<?= $toggleUrl; ?>" style="margin-left:8px"><?= esc_html($toggleLbl); ?></a>
        <a href="<?= $hz_url; ?>" class="pc-qo-hz-link" style="margin-left:14px"><?= esc_html($hz_text); ?></a>
        <span class="pc-qo-message" style="margin-left:auto;"></span>
    </div>

    <?php echo $pager_html; ?>

    <div class="pc-qo-table-wrap">
        <table class="pc-qo-table">
            <thead>
                <tr>
                    <th style="width:44%;">Товар</th>
                    <th>Артикль</th>
                    <th>Ціна</th>
                    <th class="pc-qo-incart">У кошику</th>
                    <?php if ( ! empty( $a['show_stock'] ) ): ?>
                        <th title="Остатки по режиму">Наявність</th>
                    <?php endif; ?>
                    <th class="pc-qo-qty">К-сть</th>
                </tr>
            </thead>
            <tbody>
            <?php
            // про всяк випадок — повертаємо покажчик на початок
            $q->rewind_posts();

            $cart_qty_map = ( WC()->cart ) ? WC()->cart->get_cart_item_quantities() : [];
            while ( $q->have_posts() ) : $q->the_post();
                $product = wc_get_product( get_the_ID() );
                if ( ! $product ) continue;

                $pid        = $product->get_id();
                $price_html = $product->get_price_html();
                $sku        = $product->get_sku();

                $in_cart_qty = function_exists('slu_cart_qty_for_product')
                    ? (int) slu_cart_qty_for_product($product)
                    : ( (WC()->cart && isset($cart_qty_map[$pid])) ? (int) $cart_qty_map[$pid] : 0 );

                if ( function_exists('pc_build_stock_view') ) {
                    $view = pc_build_stock_view($product);
                    $base = (int) ($view['sum'] ?? 0);
                    $available_for_add = max(0, $base - $in_cart_qty);
                } elseif ( function_exists('slu_available_for_add') ) {
                    $available_for_add = (int) slu_available_for_add($product);
                } else {
                    $available_for_add = max(0, (int) $product->get_stock_quantity() - (int) $in_cart_qty);
                }

                // ховаємо лише якщо немає що додавати І в кошику теж 0
                if ( $hz_on && $available_for_add <= 0 && $in_cart_qty <= 0 ) continue;

                $stock_html = '';
                if ( function_exists('slu_render_stock_panel') ) {
                    $stock_html = slu_render_stock_panel($product, [
                        'wrap_class'       => 'slu-stock-mini',
                        'show_primary'     => true,
                        'show_others'      => true,
                        'show_total'       => true,
                        'hide_when_zero'   => true,
                        'show_incart'      => false,
                        'show_incart_plan' => false,
                    ]);
                }

                $step = max(1, (int) $product->get_min_purchase_quantity());
                $row_classes = ['pc-qo-row'];
                if ( (int) $available_for_add <= 0 ) $row_classes[] = 'is-zero';
                if ( (int) $in_cart_qty > 0 )        $row_classes[] = 'has-incart';
                ?>
                <tr class="<?php echo esc_attr( implode(' ', $row_classes) ); ?>"
                    data-id="<?php echo esc_attr($pid); ?>"
                    data-available="<?php echo esc_attr($available_for_add); ?>"
                    data-incart="<?php echo esc_attr($in_cart_qty); ?>">
                    <td class="pc-qo-title"><a href="<?php the_permalink(); ?>" target="_blank" rel="noopener"><?php the_title(); ?></a></td>
                    <td class="pc-qo-sku"><?php echo esc_html($sku ?: '—'); ?></td>
                    <td class="pc-qo-price"><?php echo $price_html ?: '—'; ?></td>
                    <td class="pc-qo-incart"><?php echo (int) $in_cart_qty; ?></td>
                    <?php if ( ! empty( $a['show_stock'] ) ): ?>
                        <td class="pc-qo-stockhint">
                            <?php echo $stock_html !== '' ? $stock_html : '<span class="muted">Загал.: '.esc_html($available_for_add).'</span>'; ?>
                        </td>
                    <?php endif; ?>
                    <td class="pc-qo-qty">
                        <input type="number"
                            min="<?php echo - (int) $in_cart_qty; ?>"
                            step="<?php echo esc_attr($step); ?>"
                            max="<?php echo esc_attr($available_for_add); ?>"
                            class="pc-qo-input"
                            <?php echo ($available_for_add<=0 && $in_cart_qty<=0) ? 'disabled' : ''; ?>
                            placeholder="0">
                    </td>
                </tr>
            <?php endwhile; wp_reset_postdata(); ?>
            </tbody>
        </table>
    </div>

    <?php echo $pager_html; ?>
</div>
<?php
return ob_get_clean();
});

/** SQL tweaks */
add_filter('posts_groupby', function($groupby, WP_Query $q){
    if ($q->get('pc_qo')) { global $wpdb; return "{$wpdb->posts}.ID"; }
    return $groupby;
}, 10, 2);
add_filter('posts_distinct', function($distinct, WP_Query $q){
    return $q->get('pc_qo') ? 'DISTINCT' : $distinct;
}, 10, 2);

/** AJAX: массовое добавление */
add_action('wp_ajax_pc_bulk_add_to_cart',        'pc_qo_bulk_add');
add_action('wp_ajax_nopriv_pc_bulk_add_to_cart', 'pc_qo_bulk_add');
function pc_qo_bulk_add(){
    check_ajax_referer('pc_bulk_add');
    if (empty($_POST['items']) || !is_array($_POST['items'])) wp_send_json_error(['msg'=>'Порожній запит']);

    $added = 0;
    foreach ($_POST['items'] as $row) {
        $pid = isset($row['id'])  ? (int)$row['id']  : 0;
        $qty = isset($row['qty']) ? (float)$row['qty'] : 0;
        if ($pid <= 0 || $qty <= 0) continue;

        $product = wc_get_product($pid); if (!$product) continue;

        if (function_exists('pc_build_stock_view')) {
            $view = pc_build_stock_view($product);
            $in_cart = function_exists('slu_cart_qty_for_product') ? (int) slu_cart_qty_for_product($product) : 0;
            $available = max(0, (int)($view['sum'] ?? 0) - $in_cart);
        } elseif (function_exists('slu_available_for_add')) {
            $available = (int) slu_available_for_add($product);
        } else {
            $available = max(0, (int) get_post_meta($pid,'_stock',true));
        }

        if ($qty > $available) $qty = $available;
        if ($qty <= 0) continue;

        $max_by_product = (int) $product->get_max_purchase_quantity();
        if ($max_by_product > 0 && $qty > $max_by_product) $qty = $max_by_product;
        if ($qty <= 0) continue;

        $res = WC()->cart->add_to_cart($pid, $qty);
        if ($res) $added++;
    }
    wp_send_json_success(['added'=>$added]);
}

/** Переписываем ссылки категорий на страницу со шорткодом */
add_action('wp', function () {
    if (!is_page()) return;
    $post = get_post(); if (!$post) return;
    if (!has_shortcode($post->post_content, 'pc_quick_order')) return;

    add_filter('term_link', function ($url, $term, $taxonomy) use ($post) {
        if ($taxonomy !== 'product_cat') return $url;
        return add_query_arg('cat', $term->slug, get_permalink($post));
    }, 10, 3);
});

/** Стили и JS */
add_action('wp_enqueue_scripts', function(){
    wp_enqueue_script('jquery');

    $css = '
        .pc-qo-wrap{font-size:12px}
        .pc-qo-table{width:100%; border-collapse:collapse; table-layout:fixed}
        .pc-qo-table th,.pc-qo-table td{
            padding:6px 8px; border-bottom:1px solid #eee;
            vertical-align:middle; line-height:1.2; font-size:12px;
        }
        .pc-qo-table thead th{background:#fafafa; font-weight:600; position:sticky; top:0; z-index:1}
        .pc-qo-title a{color:inherit; text-decoration:none; display:inline-block;
            max-width:100%; overflow:hidden; text-overflow:ellipsis; white-space:nowrap}
        .pc-qo-sku{width:140px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis}
        .pc-qo-price{width:90px; text-align:right; white-space:nowrap}
        .pc-qo-incart{width:90px; text-align:right; white-space:nowrap}
        .pc-qo-table th.pc-qo-qty,.pc-qo-table td.pc-qo-qty{
            width:70px; max-width:70px; text-align:right; overflow:hidden; white-space:nowrap}
        .pc-qo-qty .pc-qo-input{width:100%; box-sizing:border-box; text-align:right;
            font-size:11px; padding:2px 6px; height:24px; border:1px solid #ddd; border-radius:6px}
        .pc-qo-qty .pc-qo-input:disabled{opacity:.35; background:#f7f7f7}
        .pc-qo-toolbar{margin:6px 0 10px; font-size:12px}
        .pc-qo-pager{margin:10px 0; display:flex; gap:6px; flex-wrap:wrap}
        .pc-qo-table-wrap{max-height:70vh; overflow:auto; border:1px solid #eee; border-radius:8px}
        .pc-qo-stockhint{width:260px; font-size:12px; line-height:1.25}
        .slu-stock-mini{color:#2e7d32; font-size:12px; line-height:1.25; margin:0}
        .slu-stock-mini div{margin:0 0 2px}
        .slu-stock-mini strong{font-weight:600; color:#333}
        .slu-stock-mini .is-preferred{font-weight:600}
        .slu-stock-mini .slu-nb{display:inline-flex; gap:.25em; white-space:nowrap}
        .pc-qo-table .pc-qo-row.has-incart{ background:#f6f8fb; }
    ';
    wp_register_style('pc-qo-inline', false);
    wp_enqueue_style('pc-qo-inline');
    wp_add_inline_style('pc-qo-inline', $css);

    $ajax_url = admin_url('admin-ajax.php');
    $L = pcqo_labels();

// Готуємо JS окремим nowdoc — $ у ньому не інтерполюється PHP-ом
$js = <<<'JS'
jQuery(function($){
    function clampQty($input){
        var $row      = $input.closest('.pc-qo-row');
        var available = parseFloat($row.data('available')) || 0; // скільки ще можна додати (+)
        var inCart    = parseFloat($row.data('incart'))    || 0; // скільки вже у кошику (для -)
        var step      = parseFloat($input.attr('step')) || 1;

        var raw = String($input.val() || '').trim().replace(',', '.');

        // дозволяємо тимчасове '-' під час набору (щоб можна було ввести -5)
        if (raw === '-') return 0;

        if (raw === '') { $input.val(''); return 0; }

        var v = parseFloat(raw);
        if (!isFinite(v)) v = 0;

        // запам’ятовуємо знак і квантуємо по кроку
        var sign = v < 0 ? -1 : (v > 0 ? 1 : 0);
        v = Math.abs(v);
        if (step > 0) v = Math.floor(v/step) * step;

        // обмеження: + не більше доступного, - модулем не більше ніж у кошику
        if (sign > 0) {
            if (available >= 0 && v > available) v = available;
        } else if (sign < 0) {
            if (inCart >= 0 && v > inCart) v = inCart;
        }

        v = sign * v;

        // якщо 0 — очищаємо (як і раніше)
        if (v === 0) { $input.val(''); return 0; }

        $input.val(v);
        return v;
    }

    function sprintf(fmt){
        var args = Array.prototype.slice.call(arguments,1), i=0;
        return fmt.replace(/%[ds]/g, function(){ return args[i++]; });
    }

    // Підставимо далі з PHP через json_encode
    var summaryTpl = __SUMMARY__;

    function recalcSummary(){
        var sum = 0, items = 0;
        $('.pc-qo-row').each(function(){
            var v = clampQty($(this).find('.pc-qo-input'));
            if (v > 0){ items++; sum += v; }
        });
        $('.pc-qo-message').text(items ? sprintf(summaryTpl, items, sum) : '');
    }

    // Всі типи ручного вводу
    $(document).on('input keyup blur change paste', '.pc-qo-input', function(){
        clampQty($(this));
        recalcSummary();
    });

    // КНОПКА: логіка як у стабільному — відправляємо тільки додатні
    // ... залишаємо все як є ДО обробника кліку .pc-qo-addall

    // КНОПКА: і мінуси, і плюси
    $(document).on('click', '.pc-qo-addall', function(){
        var $btn = $(this), nonce = $btn.data('nonce');
        var adds = [], subs = [];

        $('.pc-qo-row').each(function(){
            var id    = parseInt($(this).data('id'),10);
            var avail = parseFloat($(this).data('available')) || 0;
            var inCart= parseFloat($(this).data('incart'))    || 0;
            var $inp  = $(this).find('.pc-qo-input');
            var qty   = clampQty($inp); // може бути < 0

            if (!id || !qty) return;

            if (qty > 0){
                if (avail >= 0 && qty > avail) qty = avail;
                if (qty > 0) adds.push({id:id, qty:qty});
            } else if (qty < 0){
                // мінус обмежено clampQty по inCart, тож уже ОК
                subs.push({id:id, qty:qty}); // qty від’ємна!
            }
        });

        if (!adds.length && !subs.length){
            $('.pc-qo-message').text(__NOITEMS__);
            return;
        }

        $btn.prop('disabled', true).text('…');

        // 1) спочатку мінуси (pc_cart_adjust по одному)
        function runSubs(list){
            if (!list.length) return $.Deferred().resolve().promise();
            var dfd = $.Deferred();
            var i = 0;
            function next(){
                if (i >= list.length) { dfd.resolve(); return; }
                var it = list[i++];
                $.post(__AJAX_URL__, {
                    action: 'pc_cart_adjust',
                    nonce: __ADJ_NONCE__,
                    product_id: it.id,
                    qty: it.qty // від’ємна дельта
                }).always(next); // не зупиняємось на помилці; можна .fail() показати msg, якщо треба
            }
            next();
            return dfd.promise();
        }

        // 2) далі плюси — одним батчем
        function runAdds(list){
            if (!list.length) return $.Deferred().resolve().promise();
            var dfd = $.Deferred();
            $.post(__AJAX_URL__, {
                action: 'pc_bulk_add_to_cart',
                _ajax_nonce: nonce,
                items: list
            }, function(resp){
                if(resp && resp.success){
                    $('.pc-qo-message').text('Додано позицій: ' + resp.data.added);
                }else{
                    $('.pc-qo-message').text('Помилка додавання.');
                }
                dfd.resolve();
            }).fail(function(){
                $btn.prop('disabled', false).text(__ADDALL__);
                $('.pc-qo-message').text('Помилка зв\'язку (додавання).');
                dfd.resolve();
            });
            return dfd.promise();
        }

        // 3) ланцюжок: спершу subs, потім adds, потім reload
        runSubs(subs).then(function(){
            return runAdds(adds);
        }).then(function(){
            location.reload();
        });
    });

    recalcSummary();
});
JS;

    $adj_nonce = wp_create_nonce('pc_cart_adj');

    $js = str_replace(
        ['__SUMMARY__',              '__NOITEMS__',               '__AJAX_URL__',                        '__ADDALL__',            '__ADJ_NONCE__'],
        [json_encode($L['summary']), json_encode($L['noitems']),  json_encode(admin_url('admin-ajax.php')), json_encode($L['add_all']), json_encode($adj_nonce)],
        $js
    );

wp_add_inline_script('jquery', $js);
});