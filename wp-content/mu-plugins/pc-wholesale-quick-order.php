<?php
/*
Plugin Name: PC Wholesale Quick Order
Description: Табличный «быстрый заказ» для оптовиков + массовое добавление в корзину.
Version: 1.0.1
Author: PaintCore
*/
if (!defined('ABSPATH')) exit;
// ===== Текущий склад (slug) из GET или cookie =====
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

// ===== Текущий режим отображения остатков =====
// значения: selected_only | prefer_selected | sum_all
function pcqo_current_stock_mode(): string {
    if (!empty($_GET['stock_mode'])) {
        $m = sanitize_key($_GET['stock_mode']);
    } elseif (!empty($_COOKIE['psu_stock_mode'])) {
        $m = sanitize_key($_COOKIE['psu_stock_mode']);
    } else {
        $m = 'selected_only'; // по умолчанию: "Только выбранный склад"
    }
    if (!in_array($m, ['selected_only','prefer_selected','sum_all'], true)) {
        $m = 'selected_only';
    }
    return $m;
}

// ===== Человеческие названия складов =====
function pcqo_location_labels(): array {
    $map = [
        'kyiv'  => 'Київ Олімпійський',
        'odesa' => 'Одеса',
    ];
    // позволяем переопределить снаружи
    return apply_filters('pcqo_location_labels', $map);
}

// ===== Читаем ВСЕ остатки: общие и покладовые =====
// вернёт:
// ['_total' => 12, 'kyiv'=>7, 'odesa'=>5, ...]
function pcqo_get_location_stocks(int $product_id): array {
    $out = ['_total' => (int) wc_stock_amount(get_post_meta($product_id, '_stock', true))];

    // пробегаем все мета-ключи товара и собираем _stock_{slug}
    $meta = get_post_meta($product_id);
    foreach ($meta as $key => $vals) {
        if (strpos($key, '_stock_') === 0) {
            $slug = substr($key, strlen('_stock_'));
            $qty  = (int) wc_stock_amount($vals[0] ?? 0);
            if ($slug !== '' && $qty > -1) {
                $out[$slug] = $qty;
            }
        }
    }
    return $out;
}

// ===== Сколько можно ДОБАВИТЬ сейчас по текущему режиму =====
function pcqo_available_qty_for_mode(int $product_id): int {
    $mode = pcqo_current_stock_mode();
    $loc  = pcqo_current_location_slug();
    $all  = pcqo_get_location_stocks($product_id);

    // Поварим базовый остаток для режима
    $base_available = 0;
    if ($mode === 'sum_all') {
        // сумма по всем локациям (если нет покладовых — берём общий)
        if (count($all) > 1) {
            $sum = 0;
            foreach ($all as $k => $v) {
                if ($k === '_total') continue;
                $sum += max(0, (int)$v);
            }
            $base_available = max($sum, (int)($all['_total'] ?? 0));
        } else {
            $base_available = (int)($all['_total'] ?? 0);
        }
    } elseif ($mode === 'prefer_selected') {
        // сначала выбранный склад, если 0 — позволяем брать с других
        $sel = ($loc !== '' && isset($all[$loc])) ? (int)$all[$loc] : 0;
        if ($sel > 0) {
            $base_available = $sel;
        } else {
            // суммируем остальные (или общий, если покладовых нет)
            if (count($all) > 1) {
                $sum = 0;
                foreach ($all as $k => $v) {
                    if ($k === '_total' || $k === $loc) continue;
                    $sum += max(0, (int)$v);
                }
                $base_available = max($sum, (int)($all['_total'] ?? 0));
            } else {
                $base_available = (int)($all['_total'] ?? 0);
            }
        }
    } else { // selected_only
        $base_available = ($loc !== '' && isset($all[$loc]))
            ? (int)$all[$loc]
            : (int)($all['_total'] ?? 0); // если локация не выбрана — падаем на общий
    }

    // вычитаем уже в корзине
    $in_cart_map = (WC()->cart) ? WC()->cart->get_cart_item_quantities() : [];
    $in_cart     = (int)($in_cart_map[$product_id] ?? 0);

    return max(0, (int)$base_available - $in_cart);
}

// ===== Рисуем HTML "как на витрине": Заказ со склада / Другие / Всего =====
function pcqo_render_stock_html(int $product_id): string {
    $loc   = pcqo_current_location_slug();
    $mode  = pcqo_current_stock_mode();
    $all   = pcqo_get_location_stocks($product_id);
    $names = pcqo_location_labels();

    // если нет покладовых мета — покажем просто "Всего: N"
    $has_locations = (count($all) > 1); // кроме _total

    $total = (int)($all['_total'] ?? 0);
    $sel_q = ($loc !== '' && isset($all[$loc])) ? (int)$all[$loc] : null;

    ob_start();
    echo '<div class="pcqo-stock-hint">';
    // строка "Заказ со склада"
    if ($sel_q !== null) {
        $label = $names[$loc] ?? ucfirst($loc);
        echo '<div class="pcqo-row"><span class="pcqo-muted">Заказ со склада:</span> '
           . esc_html($label) . ' — <strong>' . esc_html($sel_q) . '</strong></div>';
    }

    // Другие склады (если есть) — только с положительными остатками
    if ($has_locations) {
        $others = [];
        foreach ($all as $slug => $qty) {
            if ($slug === '_total' || $slug === $loc) continue;
            if ((int)$qty > 0) {
                $others[] = ($names[$slug] ?? ucfirst($slug)) . ' — ' . (int)$qty;
            }
        }
        if (!empty($others)) {
            echo '<div class="pcqo-row"><span class="pcqo-muted">Другие склады:</span> '
               . esc_html(implode(', ', $others)) . '</div>';
        }
    }

    // Всего
    echo '<div class="pcqo-row"><span class="pcqo-muted">Всего:</span> <strong>'
       . esc_html($total) . '</strong></div>';

    // Подсказка по режиму (необязательно)
    $mode_label = [
        'selected_only'   => 'Только выбранный склад',
        'prefer_selected' => 'С приоритетом выбранного складу',
        'sum_all'         => 'Сумма всех складов',
    ][$mode] ?? $mode;

    echo '<div class="pcqo-mode">'. esc_html($mode_label) .'</div>';
    echo '</div>';

    return trim(ob_get_clean());
}

add_filter('pcqo_location_labels', function($m){
  $m['kyiv']  = 'Київ Олімпійський';
  $m['odesa'] = 'Одеса';
  return $m;
});

/**
 * Шорткод [pc_quick_order cat="slug" per_page="50" show_stock="1"]
 */
add_shortcode('pc_quick_order', function($atts){
    if (!function_exists('wc_get_product')) return '';

    // 1) Атрибуты
    $a = shortcode_atts([
        'cat'        => '',
        'per_page'   => 50,
        'show_stock' => 1,
        'role_only'  => '',
        'orderby'    => 'sku', // title|sku|date|price
        'order'      => 'ASC',
    ], $atts, 'pc_quick_order');

    // привести show_stock к числу (0/1), чтобы не ломались проверки
    $a['show_stock'] = (int) $a['show_stock'];

    // переопределяем атрибуты из GET
    if (isset($_GET['orderby'])) {
        $g = strtolower(sanitize_text_field($_GET['orderby']));
        if (in_array($g, ['title','sku','date','price'], true)) {
            $a['orderby'] = $g;
        }
    }
    if (isset($_GET['order'])) {
        $ord = strtoupper(sanitize_text_field($_GET['order'])) === 'DESC' ? 'DESC' : 'ASC';
        $a['order'] = $ord;
    }

    // (опционально) ограничить по ролям
    if (!empty($a['role_only'])) {
        $need = array_map('trim', explode(',', $a['role_only']));
        $u = wp_get_current_user();
        if (!$u || empty($u->roles) || count(array_intersect($need, $u->roles)) === 0) {
            return '<p>Недостаточно прав для швидкого замовлення.</p>';
        }
    }

    // 2) Категория: из ?cat=..., из атрибута, либо из контекста архива
    $cat_slugs = [];
    if (!empty($_GET['cat'])) {
        $cat_slugs = array_filter(array_map('sanitize_title', preg_split('/[,\s]+/', (string)$_GET['cat'])));
    }
    if (!$cat_slugs && !empty($a['cat'])) {
        $cat_slugs = array_filter(array_map('sanitize_title', preg_split('/[,\s]+/', (string)$a['cat'])));
    }
    if (!$cat_slugs && is_product_category()) {
        $term = get_queried_object();
        if ($term && !is_wp_error($term)) $cat_slugs = [$term->slug];
    }

    // 3) Пагинация и сортировка
    $paged = isset($_GET['qo_page']) ? max(1, (int)$_GET['qo_page']) : 1;
    $order = (strtoupper($a['order']) === 'DESC') ? 'DESC' : 'ASC';

    $args = [
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => max(1, (int)$a['per_page']),
        'paged'          => $paged,
        'order'          => $order,
        'no_found_rows'  => false,      // корректный расчёт страниц
        'ignore_sticky_posts' => 1,
        'pc_qo'          => 1,          // "метка" запроса для наших хуков
        'tax_query'      => [],
        'meta_query'     => [],
    ];

    // СРАЗУ выбираем только "simple" (чтобы не отбрасывать позже и не рвать per_page)
    $args['tax_query'][] = [
        'taxonomy' => 'product_type',
        'field'    => 'slug',
        'terms'    => ['simple'],
        'operator' => 'IN',
    ];

    // Только товары, у которых есть цена (как правило, они purchasable)
    $args['meta_query'][] = [
        'key'     => '_price',
        'value'   => '',
        'compare' => '!=',
    ];

    switch (strtolower($a['orderby'])) {
        case 'sku':
            $args['meta_key']   = '_sku';
            $args['orderby']    = 'meta_value';
            $args['meta_type']  = 'CHAR';
            break;
        case 'price':
            $args['meta_key']   = '_price';
            $args['orderby']    = 'meta_value_num';
            $args['ignore_sticky_posts'] = true;
            break;
        case 'date':
            $args['orderby']    = 'date';
            break;
        default:
            $args['orderby']    = 'title';
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

    // Пейджер
    $build_pager = function(WP_Query $q, int $paged): string {
        if ((int)$q->max_num_pages <= 1) return '';
        $base = remove_query_arg('qo_page');
        $out  = '<nav class="pc-qo-pager" style="margin:12px 0;display:flex;gap:8px;flex-wrap:wrap">';
        for ($i = 1; $i <= (int)$q->max_num_pages; $i++) {
            $link = add_query_arg('qo_page', $i, $base);
            $cur  = ($i === $paged) ? ' style="font-weight:700;text-decoration:underline"' : '';
            $out .= '<a href="'.esc_url($link).'"'.$cur.'>'.esc_html($i).'</a> ';
        }
        $out .= '</nav>';
        return $out;
    };
    $pager_html = $build_pager($q, $paged);

    if (!$q->have_posts()) return '<p>Товари не знайдені.</p>';

    // 4) Рендер
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

                // «доступно» по текущему режиму (через вашу функцию/фолбэк)
                $available_for_add = pcqo_available_qty_for_mode($pid);

                // HTML блока остатков «как на витрине»
                $stock_html = pcqo_render_stock_html($pid);

              $step     = max(1, (int) $product->get_min_purchase_quantity());
              $disabled = ($available_for_add <= 0) ? 'disabled' : '';
          ?>
            <tr class="pc-qo-row" data-id="<?php echo esc_attr($pid); ?>" data-available="<?php echo esc_attr($available_for_add); ?>">
              <td class="pc-qo-title"><a href="<?php the_permalink(); ?>" target="_blank" rel="noopener"><?php the_title(); ?></a></td>
              <td class="pc-qo-sku"><?php echo esc_html($sku ?: '—'); ?></td>
              <td class="pc-qo-price"><?php echo $price_html ?: '—'; ?></td>

              <?php if ( ! empty( $a['show_stock'] ) ): ?>
                <td class="pc-qo-stockhint">
                    <?php
                        if ($stock_html !== '') {
                            echo $stock_html; // как в каталоге (Киев/Одеса/Всього и т.д.)
                        } else {
                            // простой фолбэк — если витринной функции нет
                            echo '<span class="muted">Доступно: ' . esc_html($available_for_add) . '</span>';
                        }
                    ?>
                    </td>
              <?php endif; ?>

              <td class="pc-qo-qty">
                <input type="number"
                    min="0"
                    step="<?php echo esc_attr(max(1,(int)$product->get_min_purchase_quantity())); ?>"
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

      <?php echo $pager_html; ?> <!-- пагинация снизу -->
    </div>
    <?php
    return ob_get_clean();
});

/* Убираем дубликаты и корректно считаем страницы только для нашего запроса */
add_filter('posts_groupby', function($groupby, WP_Query $q){
    if ($q->get('pc_qo')) {
        global $wpdb;
        return "{$wpdb->posts}.ID";
    }
    return $groupby;
}, 10, 2);

add_filter('posts_distinct', function($distinct, WP_Query $q){
    if ($q->get('pc_qo')) return 'DISTINCT';
    return $distinct;
}, 10, 2);

/* AJAX: массовое добавление */
add_action('wp_ajax_pc_bulk_add_to_cart',     'pc_qo_bulk_add');
add_action('wp_ajax_nopriv_pc_bulk_add_to_cart', 'pc_qo_bulk_add');
function pc_qo_bulk_add(){
    check_ajax_referer('pc_bulk_add');

    if (empty($_POST['items']) || !is_array($_POST['items'])) {
        wp_send_json_error(['msg'=>'Порожній запит']);
    }
    $added = 0;

    foreach ($_POST['items'] as $row) {
        $pid = isset($row['id'])  ? (int)$row['id']  : 0;
        $qty = isset($row['qty']) ? (float)$row['qty'] : 0;
        if ($pid <= 0 || $qty <= 0) continue;

        $product = wc_get_product($pid);
        if (!$product) continue;

       // Лимит по текущему режиму/складу (как на витрине)
        $available = pcqo_available_qty_for_mode($pid);
        if ($qty > $available) {
            $qty = $available;
        }
        if ($qty <= 0) continue;

        // (опц.) учтем персональный максимум товара
        $max_by_product = (int) $product->get_max_purchase_quantity();
        if ($max_by_product > 0 && $qty > $max_by_product) {
            $qty = $max_by_product;
        }
        if ($qty <= 0) continue;

        $res = WC()->cart->add_to_cart($pid, $qty);
        if ($res) $added++;
    }

    wc_clear_notices();
    wp_send_json_success(['added'=>$added]);
}

/* На странице со шорткодом переписываем ссылки категорий на ?cat=<slug> */
add_action('wp', function () {
    if (!is_page()) return;
    $post = get_post();
    if (!$post) return;
    if (!has_shortcode($post->post_content, 'pc_quick_order')) return;

    add_filter('term_link', function ($url, $term, $taxonomy) use ($post) {
        if ($taxonomy !== 'product_cat') return $url;
        return add_query_arg('cat', $term->slug, get_permalink($post));
    }, 10, 3);
});

/* Стили и JS */
add_action('wp_enqueue_scripts', function(){
    $css = '
        .pc-qo-wrap{font-size:12px}
        .pc-qo-table{width:100%; border-collapse:collapse; table-layout:fixed}
        .pc-qo-table th,.pc-qo-table td{
            padding:6px 8px; border-bottom:1px solid #eee;
            vertical-align:middle; line-height:1.2; font-size:12px;
        }
        .pc-qo-table thead th{background:#fafafa; font-weight:600}
        .pc-qo-title a{color:inherit; text-decoration:none; display:inline-block;
            max-width:100%; overflow:hidden; text-overflow:ellipsis; white-space:nowrap}

        .pc-qo-sku{width:140px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis}
        .pc-qo-price{width:90px; text-align:right; white-space:nowrap}

        .pc-qo-stock,.pc-qo-incart,.pc-qo-left{width:54px; text-align:right; white-space:nowrap}

        .pc-qo-table th.pc-qo-qty,.pc-qo-table td.pc-qo-qty{
            width:50px; max-width:50px; text-align:right; overflow:hidden; white-space:nowrap}

        .pc-qo-qty .pc-qo-input{width:100%; box-sizing:border-box; text-align:right;
            font-size:11px; padding:1px 4px; height:22px}
        .pc-qo-qty .pc-qo-input::-webkit-outer-spin-button,
        .pc-qo-qty .pc-qo-input::-webkit-inner-spin-button{ -webkit-appearance:none; margin:0 }
        .pc-qo-qty .pc-qo-input{ -moz-appearance:textfield }

        .pc-qo-toolbar{margin:6px 0 10px; font-size:12px}
        .pc-qo-pager{margin:10px 0; display:flex; gap:6px; flex-wrap:wrap}
        .pc-qo-stockhint{width:260px; font-size:11px; line-height:1.25}
        .pc-qo-table thead th{font-size:11px}
    ';
    wp_register_style('pc-qo-inline', false);
    wp_enqueue_style('pc-qo-inline');
    wp_add_inline_style('pc-qo-inline', $css);

    wp_add_inline_script('jquery', "
      jQuery(function($){
        // локальный лимит инпута
        $(document).on('input change', '.pc-qo-input', function(){
          var \$row = $(this).closest('.pc-qo-row');
          var available = parseFloat(\$row.data('available')) || 0;
          var step = parseFloat($(this).attr('step')) || 1;
          var v = parseFloat($(this).val() || 0);
          if (v < 0) v = 0;
          if (available >= 0 && v > available) v = available;
          if (step > 0) v = Math.floor(v/step)*step;
          $(this).val(v || '');
        });

        // массовое добавление
        $(document).on('click','.pc-qo-addall',function(){
          var \$btn = $(this), nonce = \$btn.data('nonce');
          var items = [];
          $('.pc-qo-row').each(function(){
            var id = parseInt($(this).data('id'),10);
            var available = parseFloat($(this).data('available')) || 0;
            var qty = parseFloat($(this).find('.pc-qo-input').val()||0);
            if(id>0 && qty>0){
              if (available >= 0 && qty > available) qty = available;
              if (qty>0) items.push({id:id, qty:qty});
            }
          });
          if(!items.length){ $('.pc-qo-message').text('Немає вибраних кількостей.'); return; }

          \$btn.prop('disabled', true).text('Додаємо…');

          $.post('". admin_url('admin-ajax.php') ."', {
            action: 'pc_bulk_add_to_cart',
            _ajax_nonce: nonce,
            items: items
          }, function(resp){
            if(resp && resp.success){
              $('.pc-qo-message').text('Додано позицій: '+resp.data.added);
              location.reload();
            }else{
              $('.pc-qo-message').text('Помилка додавання.');
              \$btn.prop('disabled', false).text('Добавить всё в корзину');
            }
          });
        });
      });
    ");
});