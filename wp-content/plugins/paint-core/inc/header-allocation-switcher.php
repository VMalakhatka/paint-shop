<?php
// inc/header-allocation-switcher.php
defined('ABSPATH') || exit;

/**
 * Переключатель стратегии списания (в шапке рядом с логотипом)
 * + хранение и применение «плана списания» для позиций корзины.
 *
 * Режимы:
 *  - auto: стандартное распределение
 *  - manual: приоритет выбранного склада, далее primary и убывание
 *  - single: списывать только с выбранного склада, без добора
 */

/* ============================ Helpers ============================ */

/** Прочитать предпочтение пользователя */
function pc_get_alloc_pref(): array {
    $pref = [];
    if (function_exists('WC') && WC() && WC()->session) {
        $pref = (array) WC()->session->get('pc_alloc_pref', []);
    }
    if (empty($pref) && !empty($_COOKIE['pc_alloc_pref'])) {
        $try = json_decode(stripslashes($_COOKIE['pc_alloc_pref']), true);
        if (is_array($try)) $pref = $try;
    }
    $allowed = ['auto', 'manual', 'single']; // single = только выбранный склад
    $mode    = in_array(($pref['mode'] ?? 'auto'), $allowed, true) ? $pref['mode'] : 'auto';
    $termId  = max(0, (int)($pref['term_id'] ?? 0));
    return ['mode' => $mode, 'term_id' => $termId];
}

/** Сохранить предпочтение пользователя */
function pc_set_alloc_pref(array $pref): void {
    $allowed = ['auto', 'manual', 'single'];
    $mode    = in_array(($pref['mode'] ?? 'auto'), $allowed, true) ? $pref['mode'] : 'auto';
    $termId  = max(0, (int)($pref['term_id'] ?? 0));
    $val     = ['mode' => $mode, 'term_id' => $termId];

    if (function_exists('WC') && WC() && WC()->session) {
        WC()->session->set('pc_alloc_pref', $val);
    }
    setcookie('pc_alloc_pref', wp_json_encode($val), time() + 30 * DAY_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN ?: '');
}

/* ============================ AJAX: set pref ============================ */

add_action('wp_ajax_pc_set_alloc_pref', 'pc_ajax_set_alloc_pref');
add_action('wp_ajax_nopriv_pc_set_alloc_pref', 'pc_ajax_set_alloc_pref');
function pc_ajax_set_alloc_pref() {
    check_ajax_referer('pc_alloc_nonce', 'nonce');
    $mode   = isset($_POST['mode']) ? sanitize_text_field(wp_unslash($_POST['mode'])) : 'auto';
    $termId = isset($_POST['term_id']) ? (int) $_POST['term_id'] : 0;
    pc_set_alloc_pref(['mode' => $mode, 'term_id' => $termId]);
    wp_send_json_success(pc_get_alloc_pref());
}

/* ============================ UI в шапке ============================ */

function pc_render_alloc_control() {
    static $printed = false;
    if ($printed) return; // рисуем один раз
    $printed = true;

    $terms = get_terms(['taxonomy' => 'location', 'hide_empty' => false]);
    if (is_wp_error($terms) || empty($terms)) return;

    $pref   = pc_get_alloc_pref();
    $mode   = $pref['mode'];
    $curId  = (int) $pref['term_id'];
    $nonce  = wp_create_nonce('pc_alloc_nonce');
    $ajax_u = admin_url('admin-ajax.php');
    ?>
    <div class="pc-alloc" role="group" aria-label="<?php echo esc_attr__('Списание', 'woocommerce'); ?>">
      <small><?php echo esc_html__('Списание:', 'woocommerce'); ?></small>

      <select class="pc-alloc-mode" aria-label="<?php echo esc_attr__('Режим списания', 'woocommerce'); ?>">
        <option value="auto"   <?php selected($mode, 'auto');   ?>><?php echo esc_html__('Авто', 'woocommerce'); ?></option>
        <option value="manual" <?php selected($mode, 'manual'); ?>><?php echo esc_html__('С приоритетом выбранного', 'woocommerce'); ?></option>
        <option value="single" <?php selected($mode, 'single'); ?>><?php echo esc_html__('Только выбранный склад', 'woocommerce'); ?></option>
      </select>

      <select class="pc-alloc-term" aria-label="<?php echo esc_attr__('Склад', 'woocommerce'); ?>"
              <?php disabled($mode === 'auto'); ?>>
        <option value="0"><?php echo esc_html__('— склад —', 'woocommerce'); ?></option>
        <?php foreach ($terms as $t): ?>
          <option value="<?php echo (int) $t->term_id; ?>" <?php selected($curId, (int) $t->term_id); ?>>
            <?php echo esc_html($t->name); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <script>
    (function($){
      var nonce = <?php echo wp_json_encode($nonce); ?>;
      var ajaxu = <?php echo wp_json_encode($ajax_u); ?>;

      function savePref(mode, term_id){
        return $.post(ajaxu, { action:'pc_set_alloc_pref', nonce:nonce, mode:mode, term_id:term_id });
      }
      function refreshUI(){
        $(document.body).trigger('wc_fragment_refresh');
      }

      $(document).on('change', '.pc-alloc-mode', function(){
        var mode  = this.value;
        var $term = $('.pc-alloc-term');
        // склад нужен только в manual/single
        $term.prop('disabled', (mode === 'auto'));
        var tid = (mode === 'auto') ? 0 : (parseInt($term.val(),10) || 0);
        savePref(mode, tid).done(function(){
          $.post(ajaxu, {action:'pc_recalc_alloc_plans'}).always(refreshUI);
        });
      });

      $(document).on('change', '.pc-alloc-term', function(){
        var tid  = parseInt(this.value, 10) || 0;
        var mode = $('.pc-alloc-mode').val();
        if (mode === 'auto') { mode = 'manual'; $('.pc-alloc-mode').val('manual'); }
        savePref(mode, tid).done(function(){
          $.post(ajaxu, {action:'pc_recalc_alloc_plans'}).always(refreshUI);
        });
      });
    })(jQuery);
    </script>
    <?php
}

/**
 * Не лезем в разметку темы: рендерим контрол в футере и переносим в .site-branding
 */
add_action('wp_footer', function () {
    echo '<div id="pc-alloc-mount" style="display:none">';
    pc_render_alloc_control();
    echo '</div>';
    ?>
    <script>
    (function($){
      $(function(){
        var $branding = $('.site-branding'); // контейнер с "paint"
        var $ctrl     = $('#pc-alloc-mount .pc-alloc');
        if ($branding.length && $ctrl.length) {
          $ctrl.appendTo($branding).show();
        }
      });
    })(jQuery);
    </script>
    <?php
}, 99);

/* ============================ Пересчёт и хранение «плана» для корзины ============================ */

/** Посчитать план списания конкретного товара под qty */
function pc_calc_plan_for(\WC_Product $product, int $qty): array {
    $qty = max(0, $qty);
    if ($qty === 0) return [];
    // отдаём в общий фильтр (ниже мы его переупорядочим по преференции)
    return (array) apply_filters('slu_allocation_plan', [], $product, $qty, 'frontend-preview');
}

/** Пересчитать планы для всех позиций корзины */
function pc_recalc_alloc_plans_for_cart(): void {
    if (!function_exists('WC') || !WC() || !WC()->cart) return;
    foreach (WC()->cart->get_cart() as $key => $item) {
        $prod = $item['data'] ?? null;
        $qty  = (int)($item['quantity'] ?? 0);
        if ($prod instanceof \WC_Product && $qty > 0) {
            WC()->cart->cart_contents[$key]['pc_alloc_plan'] = pc_calc_plan_for($prod, $qty);
        }
    }
    WC()->cart->set_session();
}

add_action('woocommerce_cart_loaded_from_session',            'pc_recalc_alloc_plans_for_cart', 20);
add_action('woocommerce_add_to_cart',                        'pc_recalc_alloc_plans_for_cart', 20, 0);
add_action('woocommerce_after_cart_item_quantity_update',    'pc_recalc_alloc_plans_for_cart', 20, 2);

// AJAX: пересчитать планы (дергаем из JS при смене селектов)
add_action('wp_ajax_pc_recalc_alloc_plans',     function(){ pc_recalc_alloc_plans_for_cart(); wp_send_json_success(); });
add_action('wp_ajax_nopriv_pc_recalc_alloc_plans', function(){ pc_recalc_alloc_plans_for_cart(); wp_send_json_success(); });

// При создании заказа переносим план в мету строки
add_action('woocommerce_checkout_create_order_line_item', function($item, $cart_item_key, $values){
    if (!empty($values['pc_alloc_plan']) && is_array($values['pc_alloc_plan'])) {
        $item->add_meta_data('_pc_alloc_plan', $values['pc_alloc_plan'], true);
    }
}, 10, 3);

// ===== Переопределяем план списания с учётом выбранного режима/склада (safe) =====
add_filter('slu_allocation_plan', function($plan, $product, $need, $strategy){
    try {
        if ( ! ($product instanceof \WC_Product) ) {
            return is_array($plan) ? $plan : [];
        }

        $need = max(0, (int)$need);
        if ($need === 0) return [];

        // Защитные проверки на наличие функций
        if ( !function_exists('slu_collect_location_stocks_for_product') ) {
            return is_array($plan) ? $plan : [];
        }

        $pref = function_exists('pc_get_alloc_pref')
            ? pc_get_alloc_pref()
            : ['mode' => 'auto', 'term_id' => 0];

        $mode = $pref['mode'] ?? 'auto';
        $sel  = (int)($pref['term_id'] ?? 0);

        // Собираем остатки по локациям
        $all = (array) slu_collect_location_stocks_for_product($product);

        // Режим 3: "только выбранный склад"
        if ($mode === 'single') {
            if ($sel && isset($all[$sel])) {
                $available = max(0, (int)($all[$sel]['qty'] ?? 0));
                $take = min($need, $available);
                return $take > 0 ? [ $sel => $take ] : [];
            }
            // выбранного склада нет — ничего не списываем
            return [];
        }

        // Режим 2: "с приоритетом выбранного"
        if ($mode === 'manual' && $sel) {
            // Заказ с выбранного, остаток — как в базовом алгоритме
            $ordered = [];

            if (isset($all[$sel])) {
                $ordered[$sel] = $all[$sel];
                unset($all[$sel]);
            }

            if (function_exists('slu_get_primary_location_term_id')) {
                $primary = (int) slu_get_primary_location_term_id($product->get_id());
                if ($primary && isset($all[$primary])) {
                    $ordered[$primary] = $all[$primary];
                    unset($all[$primary]);
                }
            }

            uasort($all, function($a,$b){ return (int)($b['qty'] ?? 0) <=> (int)($a['qty'] ?? 0); });
            $ordered += $all;

            $res = []; $left = $need;
            foreach ($ordered as $tid => $row) {
                if ($left <= 0) break;
                $take = min($left, max(0, (int)($row['qty'] ?? 0)));
                if ($take > 0) { $res[(int)$tid] = $take; $left -= $take; }
            }
            return $res;
        }

        // Режим 1: "авто" — отдадим базовый алгоритм (или то, что уже притащил другой фильтр)
        return is_array($plan) ? $plan : null;

    } catch (\Throwable $e) {
        // Никаких фаталов на чекауте
        if (function_exists('error_log')) {
            error_log('[alloc_plan] '.$e->getMessage());
        }
        return is_array($plan) ? $plan : [];
    }
}, 10, 4);