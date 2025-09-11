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

/* ============================ ЕДИНАЯ ТОЧКА РАСЧЁТА ============================ */

/**
 * ЕДИНАЯ точка расчёта плана списания под текущие предпочтения пользователя.
 * Возвращает массив вида [term_id => qty, ...]
 */
function pc_build_alloc_plan(\WC_Product $product, int $need): array {
    $need = max(0, (int)$need);
    if ($need === 0) return [];
    if ( ! function_exists('slu_collect_location_stocks_for_product')) return [];

    $pref = function_exists('pc_get_alloc_pref') ? pc_get_alloc_pref() : ['mode'=>'auto','term_id'=>0];
    $mode = $pref['mode'] ?? 'auto';
    $sel  = (int)($pref['term_id'] ?? 0);

    // Собираем все остатки по локациям: [term_id => ['qty'=>...], ...]
    $all = (array) slu_collect_location_stocks_for_product($product);

    // Режим: single — только выбранный склад, без добора
    if ($mode === 'single') {
        if ($sel && isset($all[$sel])) {
            $take = min($need, max(0, (int)($all[$sel]['qty'] ?? 0)));
            return $take > 0 ? [ $sel => $take ] : [];
        }
        return [];
    }

    // Составим порядок локаций
    $ordered = [];

    // manual: первым — выбранный склад (если есть)
    if ($mode === 'manual' && $sel && isset($all[$sel])) {
        $ordered[$sel] = $all[$sel];
        unset($all[$sel]);
    }

    // затем — primary
    if (function_exists('slu_get_primary_location_term_id')) {
        $primary = (int) slu_get_primary_location_term_id($product->get_id());
        if ($primary && isset($all[$primary])) {
            $ordered[$primary] = $all[$primary];
            unset($all[$primary]);
        }
    }

    // остальные — по убыванию остатков
    uasort($all, function($a,$b){ return (int)($b['qty'] ?? 0) <=> (int)($a['qty'] ?? 0); });
    $ordered += $all;

    // Жадно набираем до need
    $res=[]; $left=$need;
    foreach ($ordered as $tid => $row) {
        if ($left<=0) break;
        $q = min($left, max(0,(int)($row['qty'] ?? 0)));
        if ($q>0){ $res[(int)$tid] = $q; $left -= $q; }
    }
    return $res;
}

/**
 * Публичный калькулятор плана для товара: даём шанс внешним фильтрам,
 * затем — наш единый расчётчик.
 */
function pc_calc_plan_for(\WC_Product $product, int $qty): array {
    $qty = max(0, (int)$qty);
    if ($qty === 0) return [];
    // 1) внешний фильтр (кто-то может переопределить всё целиком)
    $from_filter = (array) apply_filters('slu_allocation_plan', [], $product, $qty, 'frontend-preview');
    if (!empty($from_filter)) return $from_filter;

    // 2) централизованный расчёт
    return pc_build_alloc_plan($product, $qty);
}

/* ============================ UI в шапке ============================ */

function pc_render_alloc_control() {
    static $printed = false;
    if ($printed) return;
    $printed = true;

    $terms = get_terms(['taxonomy' => 'location', 'hide_empty' => false]);
    if (is_wp_error($terms) || empty($terms)) return;

    $pref    = pc_get_alloc_pref();
    $mode    = $pref['mode'];
    $curId   = (int) $pref['term_id'];
    $firstId = (int) ($terms[0]->term_id ?? 0);

    if ($mode !== 'auto' && $curId <= 0) {
        $curId = $firstId;
    }

    $nonce  = wp_create_nonce('pc_alloc_nonce');
    $ajax_u = admin_url('admin-ajax.php');
    ?>
    <div class="pc-alloc" role="group" aria-label="<?php echo esc_attr__( 'Allocation', 'paint-core' ); ?>">
      <small><?php echo esc_html__( 'Allocation:', 'paint-core' ); ?></small>

      <select id="pc-slu-mode" class="pc-alloc-mode" aria-label="<?php echo esc_attr__( 'Allocation mode', 'paint-core' ); ?>">
        <option value="auto"   <?php selected($mode, 'auto');   ?>><?php echo esc_html__( 'Auto', 'paint-core' ); ?></option>
        <option value="manual" <?php selected($mode, 'manual'); ?>><?php echo esc_html__( 'Preferred location first', 'paint-core' ); ?></option>
        <option value="single" <?php selected($mode, 'single'); ?>><?php echo esc_html__( 'Only selected location', 'paint-core' ); ?></option>
      </select>

      <select id="pc-slu-location" class="pc-alloc-term" aria-label="<?php echo esc_attr__( 'Location', 'paint-core' ); ?>"
              <?php disabled($mode === 'auto'); ?>>
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

        // Сильный пересчёт корзины/чекаута
        function strongCartRefresh(){
            var $b     = $('body');
            var isCart = $b.hasClass('woocommerce-cart');
            var isCO   = $b.hasClass('woocommerce-checkout');

            var recalc = $.post(ajaxu, {action:'pc_recalc_alloc_plans'});

            recalc.always(function(){
                if (isCart || isCO) {
                    $(document.body).trigger('wc_fragment_refresh');
                    if (isCO) $b.trigger('update_checkout');

                    if (isCart) {
                        var $form     = $('form.woocommerce-cart-form');
                        var hasItems  = $form.find('.cart_item').length > 0; // есть строки корзины?
                        if (!hasItems) return; // корзина пустая — ничего не отправляем, чтобы не терять кнопки (импорт и т.п.)

                        var $btn = $form.find('button[name="update_cart"]');
                        if ($btn.length) {
                            $btn.prop('disabled', false).trigger('click');
                        } else if ($form.length) {
                            if (!$form.find('input[name="update_cart"]').length){
                            $('<input type="hidden" name="update_cart" value="1">').appendTo($form);
                            }
                            $form.trigger('submit');
                        }
                    }
                } else {
                    window.location.reload();
                }
            });
        }

        function ensureTermForMode(mode){
            var $term = $('#pc-slu-location');
            if (mode === 'auto') {
                $term.prop('disabled', true);
                return 0;
            }
            $term.prop('disabled', false);
            var val = parseInt($term.val(), 10) || 0;
            if (!val) {
                var first = parseInt($term.find('option:first').val(), 10) || 0;
                if (first){ $term.val(first).trigger('change'); return first; }
            }
            return val;
        }

        // первичная синхронизация блокировки селекта склада
        $(function(){
            ensureTermForMode($('#pc-slu-mode').val() || 'auto');
        });

        // Смена режима
        $(document).on('change', '#pc-slu-mode', function(){
            var mode = this.value;
            var tid  = ensureTermForMode(mode);
            savePref(mode, tid).always(strongCartRefresh);
        });

        // Смена склада
        $(document).on('change', '#pc-slu-location', function(){
            var mode = $('#pc-slu-mode').val() || 'auto';
            var tid  = parseInt(this.value, 10) || 0;

            if (mode === 'auto') {
                mode = 'manual';
                $('#pc-slu-mode').val('manual');
            }
            savePref(mode, tid).always(strongCartRefresh);
        });

        // При ajax-перерисовках ещё раз блокируем/разблокируем селект
        $(document.body).on('wc_fragments_refreshed updated_wc_div cart-items-rendered', function(){
            ensureTermForMode($('#pc-slu-mode').val() || 'auto');
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
add_action('woocommerce_cart_loaded_from_session',         'pc_recalc_alloc_plans_for_cart', 20);
add_action('woocommerce_add_to_cart',                      'pc_recalc_alloc_plans_for_cart', 20, 0);
add_action('woocommerce_after_cart_item_quantity_update',  'pc_recalc_alloc_plans_for_cart', 20, 2);

// AJAX: пересчитать планы (дергаем из JS при смене селектов)
add_action('wp_ajax_pc_recalc_alloc_plans',      function(){ pc_recalc_alloc_plans_for_cart(); wp_send_json_success(); });
add_action('wp_ajax_nopriv_pc_recalc_alloc_plans', function(){ pc_recalc_alloc_plans_for_cart(); wp_send_json_success(); });

// При создании заказа переносим план в мету строки (оба ключа для совместимости)
add_action('woocommerce_checkout_create_order_line_item', function($item, $cart_item_key, $values){
    if (!empty($values['pc_alloc_plan']) && is_array($values['pc_alloc_plan'])) {
        $plan = [];
        foreach ($values['pc_alloc_plan'] as $tid => $q) {
            $tid = (int)$tid; $q = (int)$q;
            if ($tid > 0 && $q > 0) $plan[$tid] = $q;
        }
        if ($plan) {
            $item->add_meta_data('_pc_alloc_plan', $plan, true);      // наш новый ключ
            $item->add_meta_data('_pc_stock_breakdown', $plan, true); // совместимость
        }
    }
}, 10, 3);

/* ============================ Делегирующий фильтр ============================ */
/**
 * Если внешний фильтр уже что-то дал — уважаем; иначе — наш централизованный расчёт.
 */
add_filter('slu_allocation_plan', function($plan, $product, $need, $strategy){
    try {
        if ( ! ($product instanceof \WC_Product))  return is_array($plan) ? $plan : [];
        $need = max(0, (int)$need);
        if ($need === 0) return [];

        if (is_array($plan) && !empty($plan)) return $plan;

        return pc_build_alloc_plan($product, $need);
    } catch (\Throwable $e) {
        if (function_exists('error_log')) error_log('[alloc_plan] '.$e->getMessage());
        return is_array($plan) ? $plan : [];
    }
}, 10, 4);