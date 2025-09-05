<?php
/*
Plugin Name: PC Wholesale Quick Order
Description: Табличний «швидкий заказ» для оптовиків + масове додавання в кошик.
Version: 1.2.0
Author: PaintCore
*/
if (!defined('ABSPATH')) exit;

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
    // Фолбэк
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

    $a = shortcode_atts([
        'cat'        => '',
        'per_page'   => 50,
        'show_stock' => 1,
        'role_only'  => '',
        'orderby'    => 'sku', // title|sku|date|price
        'order'      => 'ASC',
    ], $atts, 'pc_quick_order');
    $a['show_stock'] = (int) $a['show_stock'];

    // allow GET override for sort
    if (isset($_GET['orderby'])) {
        $g = strtolower(sanitize_text_field($_GET['orderby']));
        if (in_array($g, ['title','sku','date','price'], true)) $a['orderby'] = $g;
    }
    if (isset($_GET['order'])) {
        $ord = strtoupper(sanitize_text_field($_GET['order'])) === 'DESC' ? 'DESC' : 'ASC';
        $a['order'] = $ord;
    }

    // role gate (optional)
    if (!empty($a['role_only'])) {
        $need = array_map('trim', explode(',', $a['role_only']));
        $u = wp_get_current_user();
        if (!$u || empty($u->roles) || count(array_intersect($need, $u->roles)) === 0) {
            return '<p>Недостатньо прав для швидкого замовлення.</p>';
        }
    }

    // category source
    $cat_slugs = [];
    if (!empty($_GET['cat']))            $cat_slugs = array_filter(array_map('sanitize_title', preg_split('/[,\s]+/', (string)$_GET['cat'])));
    if (!$cat_slugs && !empty($a['cat']))$cat_slugs = array_filter(array_map('sanitize_title', preg_split('/[,\s]+/', (string)$a['cat'])));
    if (!$cat_slugs && is_product_category()){
        $term = get_queried_object(); if ($term && !is_wp_error($term)) $cat_slugs = [$term->slug];
    }

    // query
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

    // only simple
    $args['tax_query'][] = [
        'taxonomy' => 'product_type',
        'field'    => 'slug',
        'terms'    => ['simple'],
        'operator' => 'IN',
    ];
    // only with price
    $args['meta_query'][] = [
        'key'     => '_price',
        'value'   => '',
        'compare' => '!=',
    ];
    switch (strtolower($a['orderby'])) {
        case 'sku':   $args['meta_key'] = '_sku';   $args['orderby'] = 'meta_value';     $args['meta_type'] = 'CHAR'; break;
        case 'price': $args['meta_key'] = '_price'; $args['orderby'] = 'meta_value_num'; $args['ignore_sticky_posts'] = true; break;
        case 'date':  $args['orderby'] = 'date'; break;
        default:      $args['orderby'] = 'title';
    }
    if ($cat_slugs) {
        $args['tax_query'][] = [
            'taxonomy'         => 'product_cat',
            'field'            => 'slug',
            'terms'            => $cat_slugs,
            'operator'         => 'IN',
            'include_children' => true,
        ];
    }

    $q = new WP_Query($args);

    // pager
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

    if (!$q->have_posts()) return '<p>Товари не знайдені.</p>';

    // render
    $nonce = wp_create_nonce('pc_bulk_add');
    ob_start(); ?>
    <div class="pc-qo-wrap">
      <div class="pc-qo-toolbar" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <button type="button" class="button button-primary pc-qo-addall" data-nonce="<?php echo esc_attr($nonce); ?>">
          Добавить всё в корзину
        </button>
        <?php
            $base = remove_query_arg('qo_page');
            $mk = function($key, $order = null) use ($base, $a) {
                return esc_url(add_query_arg([
                    'orderby' => $key,
                    'order'   => $order ?: $a['order'],
                    'qo_page' => 1,
                ], $base));
            };
            $bold = function($key, $label) use ($a, $mk) {
                $url   = $mk($key);
                $isCur = ($a['orderby'] === $key);
                $style = $isCur ? ' style="font-weight:700;text-decoration:underline"' : '';
                return '<a href="'.$url.'"'.$style.'>'.$label.'</a>';
            };
            $nextOrder = ($a['order'] === 'ASC') ? 'DESC' : 'ASC';
            $label     = ($nextOrder === 'DESC') ? 'ЗБІЛ' : 'ЗМЕН';
            $toggleUrl = $mk($a['orderby'], $nextOrder);
        ?>
        <span style="opacity:.7">Сортувати:</span>
        <?= $bold('title','по назві'); ?> ·
        <?= $bold('sku','по артиклю'); ?> ·
        <?= $bold('price','по ціні'); ?>
        <a href="<?= $toggleUrl; ?>" style="margin-left:8px">⇅ <?= esc_html($label); ?></a>

       <label class="pc-qo-hide-zero" style="display:flex;align-items:center;gap:6px;margin-left:14px;cursor:pointer">
            <input type="checkbox" id="pcqo-hide-zero">
            <span>Сховати 0</span>
        </label>

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
          $cart_qty_map = ( WC()->cart ) ? WC()->cart->get_cart_item_quantities() : [];
          while ($q->have_posts()): $q->the_post();
              $product = wc_get_product(get_the_ID());
              if (!$product) continue;

              $pid        = $product->get_id();
              $price_html = $product->get_price_html();
              $sku        = $product->get_sku();

              /* у кошику */
              $in_cart_qty = function_exists('slu_cart_qty_for_product')
                  ? (int) slu_cart_qty_for_product($product)
                  : ((WC()->cart && isset($cart_qty_map[$pid])) ? (int)$cart_qty_map[$pid] : 0);

              /* доступно до додавання під обраний режим (single/manual/auto) */
              if (function_exists('pc_build_stock_view')) {
                  $view = pc_build_stock_view($product);
                  $base = (int)($view['sum'] ?? 0);
                  $available_for_add = max(0, $base - $in_cart_qty);
              } elseif (function_exists('slu_available_for_add')) {
                  $available_for_add = (int) slu_available_for_add($product);
              } else {
                  $available_for_add = max(0, (int)$product->get_stock_quantity() - (int)$in_cart_qty);
              }

              /* HTML блоку складів */
              $stock_html = '';
              if (function_exists('slu_render_stock_panel')) {
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
              if ((int)$available_for_add <= 0) $row_classes[] = 'is-zero';
              if ((int)$in_cart_qty > 0)        $row_classes[] = 'has-incart';
              ?>
              <tr class="<?php echo esc_attr(implode(' ', $row_classes)); ?>"
                  data-id="<?php echo esc_attr($pid); ?>"
                  data-available="<?php echo esc_attr($available_for_add); ?>"
                  data-incart="<?php echo esc_attr($in_cart_qty); ?>">
                <td class="pc-qo-title"><a href="<?php the_permalink(); ?>" target="_blank" rel="noopener"><?php the_title(); ?></a></td>
                <td class="pc-qo-sku"><?php echo esc_html($sku ?: '—'); ?></td>
                <td class="pc-qo-price"><?php echo $price_html ?: '—'; ?></td>
                <td class="pc-qo-incart"><?php echo (int)$in_cart_qty; ?></td>
                <?php if ( ! empty( $a['show_stock'] ) ): ?>
                  <td class="pc-qo-stockhint">
                    <?php echo $stock_html !== '' ? $stock_html : '<span class="muted">Загал.: '.esc_html($available_for_add).'</span>'; ?>
                  </td>
                <?php endif; ?>
                <td class="pc-qo-qty">
                  <input type="number"
                         min="0"
                         step="<?php echo esc_attr($step); ?>"
                         max="<?php echo esc_attr($available_for_add); ?>"
                         class="pc-qo-input"
                         <?php echo $available_for_add<=0?'disabled':''; ?>
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

/** SQL tweaks for our query */
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
            $in_cart = function_exists('slu_cart_qty_for_product')
                ? (int) slu_cart_qty_for_product($product)
                : 0;
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
    wc_clear_notices();
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
    // 1) Обязательно подключаем jQuery (иначе инлайн-скрипт не выведется)
    wp_enqueue_script('jquery');

    // 2) CSS
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

        /* мини-блок складів */
        .pc-qo-stockhint{width:260px; font-size:12px; line-height:1.25}
        .slu-stock-mini{color:#2e7d32; font-size:12px; line-height:1.25; margin:0}
        .slu-stock-mini div{margin:0 0 2px}
        .slu-stock-mini strong{font-weight:600; color:#333}
        .slu-stock-mini .is-preferred{font-weight:600}
        .slu-stock-mini .slu-nb{display:inline-flex; gap:.25em; white-space:nowrap}

        /* Сховати 0 + підсвітка */
        .pc-qo-table .pc-qo-row.is-hidden{ display:none !important; }
        .pc-qo-table .pc-qo-row.has-incart{ background:#f6f8fb; }
    ';
    wp_register_style('pc-qo-inline', false);
    wp_enqueue_style('pc-qo-inline');
    wp_add_inline_style('pc-qo-inline', $css);

    // 3) JS (чистая версия)
    wp_add_inline_script('jquery', "jQuery(function($){
        var HZ_KEY = 'pcqoHideZero';

        function applyHideZero(){
            var on = $('#pcqo-hide-zero').is(':checked');
            try { localStorage.setItem(HZ_KEY, on ? '1' : '0'); } catch(e){}
            $('.pc-qo-row.is-zero').toggleClass('is-hidden', on);
        }

        function recalcSummary(){
            var sum = 0, items = 0;
            $('.pc-qo-row:not(.is-hidden)').each(function(){
                var qty = parseFloat($(this).find('.pc-qo-input').val()||0);
                if(qty>0){ items++; sum += qty; }
            });
            $('.pc-qo-message').text( items ? ('Обрано позицій: '+items+', шт: '+sum) : '' );
        }

        // Инициализация чекбокса из localStorage
        (function(){
            var saved = null;
            try { saved = localStorage.getItem(HZ_KEY); } catch(e){}
            if(saved === '1'){ $('#pcqo-hide-zero').prop('checked', true); }
            applyHideZero();
        })();

        // События
        $(document).on('change', '#pcqo-hide-zero', function(){
            applyHideZero();
            recalcSummary();
        });

        $(document).on('input change', '.pc-qo-input', function(){
            var $row = $(this).closest('.pc-qo-row');
            var available = parseFloat($row.data('available')) || 0;
            var step = parseFloat($(this).attr('step')) || 1;
            var v = parseFloat($(this).val() || 0);
            if (v < 0) v = 0;
            if (available >= 0 && v > available) v = available;
            if (step > 0) v = Math.floor(v/step)*step;
            $(this).val(v || '');
            recalcSummary();
        });

        // Массовое добавление — только видимые строки
        $(document).on('click','.pc-qo-addall',function(){
            var $btn = $(this), nonce = $btn.data('nonce');
            var items = [];
            $('.pc-qo-row:not(.is-hidden)').each(function(){
                var id = parseInt($(this).data('id'),10);
                var available = parseFloat($(this).data('available')) || 0;
                var qty = parseFloat($(this).find('.pc-qo-input').val()||0);
                if(id>0 && qty>0){
                    if (available >= 0 && qty > available) qty = available;
                    if (qty>0) items.push({id:id, qty:qty});
                }
            });
            if(!items.length){ $('.pc-qo-message').text('Немає вибраних кількостей.'); return; }

            $btn.prop('disabled', true).text('Додаємо…');

            $.post('".admin_url('admin-ajax.php')."', {
                action: 'pc_bulk_add_to_cart',
                _ajax_nonce: nonce,
                items: items
            }, function(resp){
                if(resp && resp.success){
                    $('.pc-qo-message').text('Додано позицій: '+resp.data.added);
                    location.reload();
                }else{
                    $('.pc-qo-message').text('Помилка додавання.');
                    $btn.prop('disabled', false).text('Добавить всё в корзину');
                }
            });
        });

        // Стартовый пересчёт
        recalcSummary();
    });");
});