<?php
/*
  Plugin Name: PC Cart Guard (debug)
  Description: Trim/remove cart items based on stock availability. Shows "Write-off…" + mini stock panel in cart. Clamps qty in real time. Extended logging.
  Author: Volodymyr Malakhatka
  Text Domain: pc-cart-guard
  Domain Path: /languages
*/

if (!defined('ABSPATH')) exit;

/* ================== DEBUG ================== */

// нужен калькулятор / планировщик
if (!function_exists('pc_calc_plan_for')) {
    // файл, где у тебя калькулятор текущего плана
    require_once WP_PLUGIN_DIR . '/paint-core/inc/header-allocation-switcher.php';
}
// на всякий случай — отключаем легаси-вывод мест списания
add_filter('pc_disable_legacy_cart_locations', '__return_true');

if (!defined('PC_CART_GUARD_DEBUG')) {
    // Вкл/выкл детальное логирование
    define('PC_CART_GUARD_DEBUG', false);
}

// Тумблеры отображения (можно переопределить фильтрами)
// if (!has_filter('pc_cart_show_plan'))        add_filter('pc_cart_show_plan',        '__return_true');  // "Write-off: Odesa — 3, ..."
if (!has_filter('pc_cart_show_stock_panel')) add_filter('pc_cart_show_stock_panel', '__return_true');  // зелёная мини-панель
add_filter('pc_cart_show_plan', '__return_false'); // скрыть строку плана над зелёной

function pc_cg_log($msg){
    if (!PC_CART_GUARD_DEBUG) return;
    $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
    if (is_array($msg) || is_object($msg)) $msg = print_r($msg, true);
    error_log('pc(cart): ' . $msg . ' | url=' . $uri);
}

/**
 * Рендер строки «Write-off: …»
 */
function pc_cartguard_render_plan_html(WC_Product $product, int $qty): string {
    // пробуем реальный план
    if (function_exists('pc_calc_plan_for')) {
        // ожидается массив вида [term_id => qty] под текущие настройки списания
        $plan = pc_calc_plan_for($product, $qty, 'cart');
        if (is_array($plan) && $plan) {
            $parts = [];

            foreach ($plan as $term_id => $q) {
                $q = (int) $q;
                if ($q <= 0) continue;

                // название склада
                $name = '';
                if (function_exists('pc_term_name')) {
                    $name = (string) pc_term_name((int) $term_id);
                } else {
                    // фолбек: берём имя терма напрямую
                    $t = get_term((int) $term_id);
                    $name = ($t && !is_wp_error($t)) ? $t->name : ('#'.$term_id);
                }

                $parts[] = esc_html($name) . ' — ' . $q;
            }

            if (!empty($parts)) {
                return '<div class="pc-cart-plan" style="margin:.25rem 0 .15rem;color:#333">'
                     . esc_html__('Write-off:', 'pc-cart-guard') . ' '
                     . '<strong>' . implode(', ', $parts) . '</strong>'
                     . '</div>';
            }
        }
    }

    // === ФОЛБЕК: если калькулятора нет/вернул пусто — оставляем прежнее поведение ===
    $loc_title = '';
    if (function_exists('pc_build_stock_view')) {
        $view = pc_build_stock_view($product);
        if (!empty($view['preferred_name'])) {
            $loc_title = (string) $view['preferred_name'];
        }
    } elseif (function_exists('slu_current_location_title')) {
        $loc_title = (string) slu_current_location_title();
    }

    return '<div class="pc-cart-plan" style="margin:.25rem 0 .15rem;color:#333">'
         . esc_html__('Write-off:', 'pc-cart-guard') . ' '
         . '<strong>' . esc_html($loc_title ?: '—') . ' — ' . intval($qty) . '</strong>'
         . '</div>';
}

/* ================== CORE LIMITS ================== */

function pc_cartguard_get_allowed_qty_for_cart(WC_Product $product, int $current_cart_qty): int {
    $maxQty = 0; 
    $src    = 'none';

    // 1) Пытаемся взять лимит через калькулятор плана (учтёт режим single/auto)
    if (function_exists('pc_calc_plan_for')) {
        // Просим «как максимум можно списать сейчас» — даём большой запрос
        $plan = pc_calc_plan_for($product, 999999, 'cart-cap'); // вернёт [term_id => qty]
        if (is_array($plan)) {
            $maxQty = array_sum(array_map('intval', $plan));
            $src    = 'pc_calc_plan_for(sum_plan='. $maxQty .')';
        }
    }

    // 2) Если калькулятора нет/вернул 0 — пробуем preferred из stock_view
    if ($maxQty <= 0 && function_exists('pc_build_stock_view')) {
        $view = pc_build_stock_view($product); // ожидаем preferred_qty, sum и т.д.
        if (isset($view['preferred_qty'])) {
            $maxQty = max(0, (int)$view['preferred_qty']);
            $src    = 'pc_build_stock_view(preferred_qty='.$maxQty.')';
        } else {
            $sum    = isset($view['sum']) ? (int)$view['sum'] : 0;
            $maxQty = max(0, $sum);
            $src    = 'pc_build_stock_view(sum='.$sum.')';
        }
    }

    // 3) Легаси-резервные способы
    if ($maxQty <= 0 && function_exists('slu_available_for_add')) {
        $addable = (int) slu_available_for_add($product);
        $maxQty  = max(0, $current_cart_qty + $addable);
        $src     = 'slu_available_for_add(addable='.$addable.'; cur='.$current_cart_qty.')';
    }
    if ($maxQty <= 0) {
        $stock  = (int) wc_stock_amount(get_post_meta($product->get_id(), '_stock', true));
        $maxQty = max(0, $stock);
        $src    = 'fallback_stock('.$stock.')';
    }

    // 4) Персональные лимиты товара
    $product_max = (int) $product->get_max_purchase_quantity();
    if ($product_max > 0) {
        $maxQty = min($maxQty, $product_max);
        $src .= '; cap_by_product_max='.$product_max;
    }

    pc_cg_log('allowed_qty pid='.$product->get_id().' result='.$maxQty.' via '.$src);
    return max(0, (int) $maxQty);
}

/* ================== ENFORCE LIMITS ================== */

function pc_cartguard_enforce_limits() {
    static $running = false;
    if ($running) return;
    if (!function_exists('WC')) return;
    $cart = WC()->cart;
    if (!$cart || empty($cart->get_cart())) { pc_cg_log('enforce: no cart or empty'); return; }

    $running = true;

    // FAILSAFE: режим "только выбранный склад", но склад ещё не выбран — не трогаем корзину
    if (function_exists('pc_get_alloc_pref')) {
        $pref = pc_get_alloc_pref();
        if (($pref['mode'] ?? 'auto') === 'single' && (int)($pref['term_id'] ?? 0) <= 0) {
            pc_cg_log('enforce: skip (single mode but no term selected)');
            $running = false;
            return;
        }
    }

    $changed_any = false;
    foreach ($cart->get_cart() as $key => $item) {
        if (empty($item['product_id'])) continue;

        $product = wc_get_product($item['product_id']);
        if (!$product || !$product->is_purchasable()) { pc_cg_log('enforce: skip non-purch pid='.(int)$item['product_id']); continue; }

        $current_qty = (int) $item['quantity'];
        $allowed_max = pc_cartguard_get_allowed_qty_for_cart($product, $current_qty);

        if ($allowed_max <= 0) {
            // удаляем позицию ТИХО, а сообщение — как notice (не error)
            $cart->remove_cart_item($key);
            wc_add_notice(
                sprintf(__('“%s” is temporarily unavailable at the selected write-off location.', 'pc-cart-guard'), $product->get_name()),
                'notice'
            );
            $changed_any = true;
            continue;
        }

        if ($current_qty > $allowed_max) {
            $cart->set_quantity($key, $allowed_max, false);
            wc_add_notice(
                sprintf(__('Quantity for “%1$s” adjusted to %2$d per stock availability.', 'pc-cart-guard'), $product->get_name(), $allowed_max),
                'notice'
            );
            $changed_any = true;
        }
    }

    if ($changed_any) {
        if (current_filter() !== 'woocommerce_before_calculate_totals') {
            $cart->calculate_totals();
            pc_cg_log('enforce: totals recalculated');
        }
    } else {
        pc_cg_log('enforce: nothing changed');
    }

    $running = false;
}
add_action('woocommerce_cart_loaded_from_session', 'pc_cartguard_enforce_limits', 10);
add_action('woocommerce_before_calculate_totals', 'pc_cartguard_enforce_limits', 20);

/* ================== AJAX ADJUST (optional) ================== */

add_action('wp_ajax_pc_cart_item_extras',        'pc_cart_item_extras');
add_action('wp_ajax_nopriv_pc_cart_item_extras', 'pc_cart_item_extras');

function pc_cart_item_extras(){
    if (!function_exists('WC')) wp_send_json_error(['msg'=>'no wc']);
    $cart = WC()->cart; if (!$cart) wp_send_json_error(['msg'=>'no cart']);

    $out = [];
    foreach ($cart->get_cart() as $item_key => $item){
        $product = $item['data'] ?? null; if (!$product instanceof WC_Product) continue;
        $qty     = (int) ($item['quantity'] ?? 0);

        $plan_html   = apply_filters('pc_cart_show_plan', true)        ? pc_cartguard_render_plan_html($product, $qty) : '';
        $stocks_html = '';

        if (apply_filters('pc_cart_show_stock_panel', true) && function_exists('slu_render_stock_panel')) {
            $panel = (string) slu_render_stock_panel($product, [
                'wrap_class'       => 'slu-stock-mini',
                'show_primary'     => true,
                'show_others'      => true,
                'show_total'       => true,
                'hide_when_zero'   => false,
                'show_incart'      => true,
                'show_incart_plan' => false,
            ]);
            $stocks_html = '<div class="pc-cart-stocks" style="margin:.15rem 0 0">'.$panel.'</div>';
        }

        $allowed = pc_cartguard_get_allowed_qty_for_cart($product, $qty);

        $out[] = [
            'product_id'   => $product->get_id(),
            'name'         => $product->get_name(),
            'permalink'    => $product->get_permalink(),
            'sku'          => $product->get_sku(),
            'plan_html'    => $plan_html,
            'stocks_html'  => $stocks_html,
            'allowed_max'  => (int) $allowed,
        ];
    }
    wp_send_json_success(['items'=>$out]);
}

add_action('wp_ajax_pc_cart_adjust',        'pc_cart_adjust_qty');
add_action('wp_ajax_nopriv_pc_cart_adjust', 'pc_cart_adjust_qty');

add_action('wp_ajax_pc_cart_update_item',        'pc_cartguard_update_item');
add_action('wp_ajax_nopriv_pc_cart_update_item', 'pc_cartguard_update_item');

/** Update one classic-cart row and return only the changed subtotal and totals. */
function pc_cartguard_update_item() {
    check_ajax_referer('pc_cart_update_item', 'nonce');

    if (!function_exists('WC') || !WC()->cart) {
        wp_send_json_error(['message' => __('Cart is unavailable.', 'pc-cart-guard')], 503);
    }

    $cart = WC()->cart;
    $cart_item_key = isset($_POST['cart_item_key'])
        ? sanitize_text_field(wp_unslash($_POST['cart_item_key']))
        : '';
    $requested_qty = isset($_POST['quantity']) ? max(0, (int) $_POST['quantity']) : -1;
    $cart_item = $cart_item_key !== '' ? $cart->get_cart_item($cart_item_key) : [];
    $product = $cart_item['data'] ?? null;

    if ($requested_qty < 0 || !$product instanceof WC_Product) {
        wp_send_json_error(['message' => __('Cart item was not found.', 'pc-cart-guard')], 404);
    }

    $old_qty = (int) ($cart_item['quantity'] ?? 0);
    $target_qty = $requested_qty;
    $allowed_max = pc_cartguard_get_allowed_qty_for_cart($product, $old_qty);

    if ($target_qty > $allowed_max) {
        $target_qty = $allowed_max;
        wc_add_notice(
            sprintf(__('Quantity for “%1$s” adjusted to %2$d per stock availability.', 'pc-cart-guard'), $product->get_name(), $allowed_max),
            'notice'
        );
    }

    if ($target_qty > 0) {
        $valid = apply_filters('woocommerce_update_cart_validation', true, $cart_item_key, $cart_item, $target_qty);
        if (!$valid) {
            ob_start();
            wc_print_notices();
            $notices = ob_get_clean();
            wp_send_json_error([
                'message' => __('The quantity could not be updated.', 'pc-cart-guard'),
                'notices' => $notices,
            ], 409);
        }

        $cart->set_quantity($cart_item_key, $target_qty, false);
        $cart->calculate_totals();
    } else {
        $cart->remove_cart_item($cart_item_key);
    }

    $cart->set_session();

    ob_start();
    woocommerce_cart_totals();
    $totals_html = ob_get_clean();

    ob_start();
    wc_print_notices();
    $notices_html = ob_get_clean();

    $subtotal_html = $target_qty > 0
        ? $cart->get_product_subtotal($product, $target_qty)
        : '';

    wp_send_json_success([
        'cart_item_key' => $cart_item_key,
        'quantity'      => $target_qty,
        'allowed_max'   => $allowed_max,
        'subtotal_html' => $subtotal_html,
        'totals_html'   => $totals_html,
        'notices_html'  => $notices_html,
        'cart_count'    => $cart->get_cart_contents_count(),
        'cart_empty'    => $cart->is_empty(),
        'cart_hash'     => $cart->get_cart_hash(),
    ]);
}

function pc_cart_adjust_qty() {
    check_ajax_referer('pc_cart_adj', 'nonce');

    $pid   = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
    $delta = isset($_POST['qty'])        ? (int) $_POST['qty']        : 0;

    pc_cg_log('ajax adjust: pid='.$pid.' delta='.$delta);

    if (!$pid || $delta === 0 || !function_exists('WC')) {
        pc_cg_log('ajax adjust: bad params');
        wp_send_json_error(['msg' => 'bad params']);
    }

    $cart = WC()->cart;
    if (!$cart) {
        pc_cg_log('ajax adjust: no cart');
        wp_send_json_error(['msg' => 'no cart']);
    }

    $product = wc_get_product($pid);
    if (!$product || !$product->is_purchasable()) {
        pc_cg_log('ajax adjust: no product');
        wp_send_json_error(['msg' => 'no product']);
    }

    $current_qty = 0; $item_key = null;
    foreach ($cart->get_cart() as $key => $item) {
        if ((int) $item['product_id'] === $pid) { $current_qty = (int) $item['quantity']; $item_key = $key; break; }
    }

    $allowed_max = pc_cartguard_get_allowed_qty_for_cart($product, $current_qty);
    $target = $current_qty + $delta;
    if ($target < 0)              $target = 0;
    if ($target > $allowed_max)   $target = $allowed_max;

    pc_cg_log('ajax adjust: cur='.$current_qty.' -> target='.$target.' (allowed='.$allowed_max.') item_key='.(string)$item_key);

    if ($target <= 0) {
        if ($item_key) $cart->remove_cart_item($item_key);
    } else {
        if ($item_key) $cart->set_quantity($item_key, $target, true);
        else           $cart->add_to_cart($pid, $target);
    }

    $cart->calculate_totals();
    pc_cg_log('ajax adjust: totals recalculated');

    wp_send_json_success([
        'new_qty'     => $target,
        'allowed_max' => $allowed_max,
    ]);
}

/* ================== PAGE CONTEXT & QUEUE ================== */

add_action('template_redirect', function () {
    pc_cg_log('template_redirect: is_cart='.(function_exists('is_cart') && is_cart() ? '1' : '0'));
});

add_action('wp_enqueue_scripts', function () {
    if (!function_exists('is_cart') || !is_cart()) return;

    // Логи по wc-cart-fragments
    $was_reg = wp_script_is('wc-cart-fragments', 'registered') ? '1' : '0';
    $was_enq = wp_script_is('wc-cart-fragments', 'enqueued')   ? '1' : '0';
    pc_cg_log('enqueue(before): wc-cart-fragments registered='.$was_reg.' enqueued='.$was_enq);

    wp_dequeue_script('wc-cart-fragments');
    wp_deregister_script('wc-cart-fragments');

    $now_reg = wp_script_is('wc-cart-fragments', 'registered') ? '1' : '0';
    $now_enq = wp_script_is('wc-cart-fragments', 'enqueued')   ? '1' : '0';
    pc_cg_log('enqueue(after): wc-cart-fragments registered='.$now_reg.' enqueued='.$now_enq);

    // CSS мини-складов
    $css = '
      .slu-stock-mini{color:#2e7d32;font-size:12px;line-height:1.25;margin:.15rem 0}
      .slu-stock-mini div{margin:0 0 2px}
      .slu-stock-mini strong{font-weight:600;color:#333}
      .slu-stock-mini .is-preferred{font-weight:600}
      .slu-stock-mini .slu-nb{display:inline-flex;gap:.25em;white-space:nowrap}
      .cart_item.pc-cart-item-updating{opacity:.6;pointer-events:none}
      .pc-cart-item-status{display:block;margin-top:.25rem;font-size:12px;color:#333}
    ';
    wp_register_style('pc-cart-inline', false);
    wp_enqueue_style('pc-cart-inline');
    wp_add_inline_style('pc-cart-inline', $css);

    // JS
    wp_enqueue_script('jquery');

    wp_add_inline_script(
        'jquery',
        'window.pcCartGuardUpdate = ' . wp_json_encode([
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('pc_cart_update_item'),
            'updating' => __('Updating cart…', 'pc-cart-guard'),
        ]) . ';',
        'before'
    );

    // (1) Кламп qty — Classic + Blocks
    wp_add_inline_script('jquery', <<<'JS'
jQuery(function($){
  var QTY_SEL = '.pc-cart-qty, .quantity .qty';
  var config = window.pcCartGuardUpdate || {};
  var pending = {};
  var running = false;
  var timers = {};

  function clamp($inp){
    var max  = parseFloat($inp.attr('max'));
    var min  = parseFloat($inp.attr('min'));
    var step = parseFloat($inp.attr('step'));
    if (!isFinite(max)) max = Infinity;
    if (!isFinite(min)) min = 0;
    if (!isFinite(step) || step <= 0) step = 1;
    var n = parseFloat(String($inp.val() || '').trim().replace(',', '.'));
    if (!isFinite(n)) n = min;
    n = Math.max(min, Math.min(max, n));
    n = Math.floor(n / step) * step;
    $inp.val(n);
    return n;
  }

  function requestClassicUpdate(){
    var $form = $('form.woocommerce-cart-form');
    var $btn  = $form.find('button[name="update_cart"]');
    if ($btn.length){
      $btn.prop('disabled', false).trigger('click');
    } else if ($form.length) {
      if (!$form.find('input[name="update_cart"]').length){
        $('<input type="hidden" name="update_cart" value="1">').appendTo($form);
      }
      $form.trigger('submit');
    }
  }

  function itemKey($input){
    var direct = String($input.data('cart-item-key') || '');
    if (direct) return direct;
    var match = String($input.attr('name') || '').match(/^cart\[([^\]]+)\]\[qty\]$/);
    return match ? match[1] : '';
  }

  function showNotices(html){
    if (!html) return;
    var $wrap = $('.woocommerce-notices-wrapper:first');
    if ($wrap.length) $wrap.html(html);
  }

  function finishNext(){
    $('.pc-cart-item-status').remove();
    $('.cart_item.pc-cart-item-updating').removeClass('pc-cart-item-updating').removeAttr('aria-busy');
    running = false;
    processNext();
  }

  function processNext(){
    if (running) return;
    var keys = Object.keys(pending);
    if (!keys.length) return;

    var key = keys[0];
    var job = pending[key];
    delete pending[key];
    running = true;

    var $input = job.input;
    var $row = $input.closest('.cart_item');
    $row.addClass('pc-cart-item-updating').attr('aria-busy', 'true');
    $input.siblings('.pc-cart-item-status').remove();
    $('<span class="pc-cart-item-status" role="status"></span>')
      .text(config.updating || '')
      .insertAfter($input);

    $.ajax({
      type: 'POST',
      url: config.ajaxUrl,
      dataType: 'json',
      data: {
        action: 'pc_cart_update_item',
        nonce: config.nonce,
        cart_item_key: key,
        quantity: job.quantity
      }
    }).done(function(response){
      if (!response || !response.success || !response.data){
        if (response && response.data && response.data.notices) showNotices(response.data.notices);
        requestClassicUpdate();
        return;
      }

      var data = response.data;
      showNotices(data.notices_html);
      if (data.cart_empty){
        window.location.reload();
        return;
      }

      if (parseInt(data.quantity, 10) <= 0){
        $row.remove();
      } else {
        $input.val(data.quantity)
          .attr('max', data.allowed_max)
          .attr('data-current-qty', data.quantity);
        $row.find('.product-subtotal').html(data.subtotal_html);
        $row.removeClass('pc-cart-item-updating').removeAttr('aria-busy');
      }

      if (data.totals_html){
        $('.cart_totals').replaceWith(data.totals_html);
      }
      $(document.body).trigger('updated_cart_totals');
      $(document.body).trigger('updated_wc_div');
    }).fail(function(xhr){
      if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.notices){
        showNotices(xhr.responseJSON.data.notices);
      }
      requestClassicUpdate();
    }).always(finishNext);
  }

  function queueUpdate($input, quantity, delay){
    var key = itemKey($input);
    if (!key || !config.ajaxUrl || !config.nonce){
      requestClassicUpdate();
      return;
    }

    clearTimeout(timers[key]);
    timers[key] = setTimeout(function(){
      pending[key] = {
        input: $input,
        quantity: Math.max(0, parseInt(quantity, 10) || 0)
      };
      processNext();
    }, delay || 0);
  }

  $(document).on('input change', QTY_SEL, function(){
    var $input = $(this);
    queueUpdate($input, clamp($input), 450);
  });
  $(document).on('blur', QTY_SEL, function(){
    var $input = $(this);
    queueUpdate($input, clamp($input), 0);
  });
  $(document).on('click', '.quantity .plus, .quantity .minus', function(){
    var $input = $(this).closest('.quantity').find('input.qty');
    setTimeout(function(){ queueUpdate($input, clamp($input), 120); }, 0);
  });

  document.addEventListener('click', function(event){
    var link = event.target.closest && event.target.closest('.woocommerce-cart-form .product-remove > a');
    if (!link) return;
    var $row = $(link).closest('.cart_item');
    var $input = $row.find(QTY_SEL).first();
    if (!$input.length) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    queueUpdate($input, 0, 0);
  }, true);

  $(document.body).on('updated_wc_div wc_fragments_refreshed', function(){
    $(QTY_SEL).each(function(){ clamp($(this)); });
  });
  $(QTY_SEL).each(function(){ clamp($(this)); });

  // === Принудительный апдейт корзины при смене ЛОКАЦИИ/РЕЖИМА списания
  var LOC_SEL = [
    '[name="slu_location"]',
    '[name="slu_mode"]',
    '.pc-location-switcher select',
    '.pc-location-switcher input',
    '.slu-location-switch select',
    '.slu-location-switch input',
    '#pc-slu-switch select',
    '#pc-slu-switch input',
    '.pc-slu-select',
    '.slu-location-switch a'
  ].join(',');

  function pcDebounce(fn, ms){ var t; return function(){ clearTimeout(t); t=setTimeout(fn, ms||120); }; }
  var triggerUpdate = pcDebounce(requestClassicUpdate, 120);

  $(document).on('change click', LOC_SEL, function(){
    // чуть подождём, чтобы плагин успел записать куку/сессию локации
    console.log('location/mode change -> update');
    triggerUpdate();
  });
});
JS
);
}, 9999);

/* Додатковий захват — якщо тема пізно підкидає фрагменти */
add_action('wp_print_scripts', function(){
    if (!function_exists('is_cart') || !is_cart()) return;
    if (wp_script_is('wc-cart-fragments', 'enqueued')) {
        pc_cg_log('wp_print_scripts: wc-cart-fragments WAS enqueued — removing late');
        wp_dequeue_script('wc-cart-fragments');
        wp_deregister_script('wc-cart-fragments');
    }
}, 9999);

/* ================== CART UI HOOKS ================== */

/**
 * Вывод под названием позиции: «Write-off…» + мини-склады (один раз, в конце цепочки).
 */
add_filter('woocommerce_cart_item_name', function ($name, $cart_item, $cart_item_key) {
    $product = $cart_item['data'] ?? null;
    if (!$product instanceof WC_Product) return $name;

    $qty = (int) ($cart_item['quantity'] ?? 0);

    // 1) Сносим любые уже добавленные планы (и наши прежние)
    $clean = (string) $name;
    // блоки с классом pc-cart-plan
    $clean = preg_replace('~<div\s+class=(["\']).*?\bpc-cart-plan\b.*?\1[\s\S]*?</div>~iu', '', $clean);
    // подстраховка: “Списан(ня|ие): …” без класса
    $clean = preg_replace('~\s*(Списан(?:ня|ие)\s*:\s*.*?)(<br\s*/?>|</p>|</div>|$)~iu', '$2', $clean);

    // 2) Наш вывод
    $html = '';

    // — строка ПЛАНА
    if (apply_filters('pc_cart_show_plan', true)) {
        $html .= pc_cartguard_render_plan_html($product, $qty);
    }

    // — зелёная мини-панель складов
    if (apply_filters('pc_cart_show_stock_panel', true) && function_exists('slu_render_stock_panel')) {
        $panel = (string) slu_render_stock_panel($product, [
            'wrap_class'       => 'slu-stock-mini',
            'show_primary'     => true,
            'show_others'      => true,
            'show_total'       => true,
            'hide_when_zero'   => false,
            'show_incart'      => true,
            'show_incart_plan' => false, // строку плана рисуем сами
        ]);
        $html .= '<div class="pc-cart-stocks" style="margin:.15rem 0 0">'.$panel.'</div>';
    }

    return $clean . $html;
}, 9999, 3);

/**
 * Рендер qty-инпута с корректными max/step + data-allowed.
 */
add_filter('woocommerce_cart_item_quantity', function ($product_quantity, $cart_item_key, $cart_item) {
    $product = isset($cart_item['data']) ? $cart_item['data'] : null;
    if (!$product instanceof WC_Product) return $product_quantity;

    $current = (int) $cart_item['quantity'];
    $max     = pc_cartguard_get_allowed_qty_for_cart($product, $current);
    $step    = max(1, (int) $product->get_min_purchase_quantity());
    $name    = "cart[{$cart_item_key}][qty]";

    pc_cg_log('render qty: pid='.$product->get_id().' cur='.$current.' max='.$max.' step='.$step);

    return '<div class="quantity">'
         . '<input type="number" class="input-text qty text pc-cart-qty" '
         . 'name="'.esc_attr($name).'" value="'.esc_attr($current).'" '
         . 'min="0" max="'.esc_attr($max).'" step="'.esc_attr($step).'" '
         . 'data-allowed="'.esc_attr($max).'" data-current-qty="'.esc_attr($current).'" '
         . 'data-cart-item-key="'.esc_attr($cart_item_key).'" />'
         . '</div>';
}, 20, 3);

/* Диагностика наличия/управления остатками (оставим как есть) */
add_action('woocommerce_check_cart_items', function () {
    foreach (WC()->cart->get_cart() as $ci) {
        if (empty($ci['data']) || ! $ci['data'] instanceof WC_Product) continue;
        $p     = $ci['data'];
        $want  = (int)$ci['quantity'];
        $stock = $p->get_stock_quantity();
        $ms    = $p->get_manage_stock() ? 'on' : 'off';
        $avail = function_exists('slu_available_for_add') ? (int)slu_available_for_add($p) : (int)$stock;

        pc_cg_log(sprintf('[CHK] %s | want=%d | woo_stock=%s | manage=%s | our_avail=%d',
            $p->get_sku() ?: $p->get_id(), $want, var_export($stock,true), $ms, $avail
        ));
    }
}, 1);

/* ==== Жёсткие запреты автосписаний (как было) ==== */
add_filter('pre_option_woocommerce_hold_stock_minutes', function(){ return ''; });
add_action('init', function () {
    remove_action('woocommerce_checkout_order_processed', 'wc_maybe_reduce_stock_levels', 10);
});
add_filter('pre_option_woocommerce_hold_stock_minutes', '__return_empty_string', 9999);
add_filter('woocommerce_can_reduce_order_stock', '__return_false', 9999);
remove_action('woocommerce_checkout_order_processed', 'wc_maybe_reduce_stock_levels', 10);
remove_action('woocommerce_payment_complete',          'wc_maybe_reduce_stock_levels', 10);
remove_action('woocommerce_order_status_processing',   'wc_maybe_reduce_stock_levels', 10);
remove_action('woocommerce_order_status_completed',    'wc_maybe_reduce_stock_levels', 10);

/* Барьер: на фронте запретить записи _stock/_stock_status/_stock_at_* (кроме «наших окон») */
add_filter('update_post_metadata', function($check,$post_id,$key,$val){
    if ($key!=='_stock' && $key!=='_stock_status' && strpos($key,'_stock_at_')!==0) return $check;
    if (is_admin() || (defined('DOING_CRON') && DOING_CRON)) return $check;
    if (did_action('woocommerce_order_status_processing') || did_action('woocommerce_order_status_completed')) return $check;
    pc_cg_log(sprintf('[STOCK-BARRIER] blocked write: post=%d key=%s new=%s url=%s',
        (int)$post_id,$key,is_scalar($val)?$val:json_encode($val),$_SERVER['REQUEST_URI']??''));
    return false;
}, 9999, 4);
