<?php
/**
 * Plugin Name: PC Cart Guard (debug)
 * Description: Обрізає/видаляє позиції кошика за доступністю складу. Показує «Списання…» + міні-склади у кошику. Клампить qty у реальному часі. З розширеним логуванням.
 * Author: PaintCore
 */

if (!defined('ABSPATH')) exit;

/* ================== DEBUG ================== */

if (!defined('PC_CART_GUARD_DEBUG')) {
    // Увімк./вимк. детальне логування тут:
    define('PC_CART_GUARD_DEBUG', true);
}

function pc_cg_log($msg){
    if (!PC_CART_GUARD_DEBUG) return;
    $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
    if (is_array($msg) || is_object($msg)) $msg = print_r($msg, true);
    error_log('pc(cart): ' . $msg . ' | url=' . $uri);
}

/* ================== CORE LIMITS ================== */

function pc_cartguard_get_allowed_qty_for_cart(WC_Product $product, int $current_cart_qty): int {
    $maxQty = 0; $src = 'none';

    if (function_exists('pc_build_stock_view')) {
        $view   = pc_build_stock_view($product);            // ['sum'=>..., 'preferred_name'=>...]
        $sum    = isset($view['sum']) ? (int) $view['sum'] : 0;
        $maxQty = max(0, $sum);
        $src    = 'pc_build_stock_view(sum='.$sum.')';
    } elseif (function_exists('slu_available_for_add')) {
        $addable = (int) slu_available_for_add($product);
        $maxQty  = max(0, $current_cart_qty + $addable);
        $src     = 'slu_available_for_add(addable='.$addable.'; cur='.$current_cart_qty.')';
    } else {
        $stock  = (int) wc_stock_amount(get_post_meta($product->get_id(), '_stock', true));
        $maxQty = max(0, $stock);
        $src    = 'fallback_stock('.$stock.')';
    }

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
    if (!function_exists('WC')) return;
    $cart = WC()->cart;
    if (!$cart || empty($cart->get_cart())) { pc_cg_log('enforce: no cart or empty'); return; }

     // ⚠️ FAILSAFE: режим "только выбранный склад", но склад ещё не выбран — не трогаем корзину
    if (function_exists('pc_get_alloc_pref')) {
        $pref = pc_get_alloc_pref();
        if (($pref['mode'] ?? 'auto') === 'single' && (int)($pref['term_id'] ?? 0) <= 0) {
            pc_cg_log('enforce: skip (single mode but no term selected)');
            return;
        }
    }

    $changed_any = false;
    if (function_exists('wc_clear_notices')) wc_clear_notices();
    foreach ($cart->get_cart() as $key => $item) {
        if (empty($item['product_id'])) continue;

        $product = wc_get_product($item['product_id']);
        if (!$product || !$product->is_purchasable()) { pc_cg_log('enforce: skip non-purch pid='.(int)$item['product_id']); continue; }

        $current_qty = (int) $item['quantity'];
        $allowed_max = pc_cartguard_get_allowed_qty_for_cart($product, $current_qty);

        if ($allowed_max <= 0) {
            // удаляем позицию ТИХО, а сообщение - как notice (не error)
            $cart->remove_cart_item($key);
            wc_add_notice(
            sprintf(__('«%s» тимчасово недоступний на обраній локації списання.', 'woocommerce'), $product->get_name()),
            'notice'
            );
            $changed_any = true;
            continue;
        }

        if ($current_qty > $allowed_max) {
            $cart->set_quantity($key, $allowed_max, true);
            wc_add_notice(
            sprintf(__('Кількість для «%1$s» скориговано до %2$d згідно доступності на складі.', 'woocommerce'), $product->get_name(), $allowed_max),
            'notice'
            );
            $changed_any = true;
        }
    }

    if ($changed_any) {
        $cart->calculate_totals();
        pc_cg_log('enforce: totals recalculated');
    } else {
        pc_cg_log('enforce: nothing changed');
    }
}
add_action('woocommerce_cart_loaded_from_session', 'pc_cartguard_enforce_limits', 20);
add_action('woocommerce_before_calculate_totals', 'pc_cartguard_enforce_limits', 20);

/* ================== AJAX ADJUST (optional) ================== */

add_action('wp_ajax_pc_cart_item_extras',        'pc_cart_item_extras');
add_action('wp_ajax_nopriv_pc_cart_item_extras', 'pc_cart_item_extras');

function pc_cart_item_extras(){
    if (!function_exists('WC')) wp_send_json_error(['msg'=>'no wc']);

    $cart = WC()->cart;
    if (!$cart) wp_send_json_error(['msg'=>'no cart']);

    $out = [];
    foreach ($cart->get_cart() as $item_key => $item){
        $product = isset($item['data']) ? $item['data'] : null;
        if (!$product instanceof WC_Product) continue;

        $pid = $product->get_id();
        $qty = (int) ($item['quantity'] ?? 0);

        // План списання
        $loc_title = '';
        if (function_exists('pc_build_stock_view')) {
            $view = pc_build_stock_view($product);
            if (!empty($view['preferred_name'])) $loc_title = (string) $view['preferred_name'];
        } elseif (function_exists('slu_current_location_title')) {
            $loc_title = (string) slu_current_location_title();
        }
        $plan_html = '<div class="pc-cart-plan" style="margin:.25rem 0 .15rem;color:#333">'
                   . esc_html__('Списання:', 'woocommerce') . ' '
                   . '<strong>'.esc_html($loc_title ?: '—').' — '.intval($qty).'</strong>'
                   . '</div>';



        $stocks_html = '';

        if (function_exists('slu_render_stock_panel')) {
            $panel = (string) slu_render_stock_panel($product, [
                'wrap_class'       => 'slu-stock-mini',
                'show_primary'     => true,
                'show_others'      => true,
                'show_total'       => true,
                'hide_when_zero'   => false,
                'show_incart'      => true,
                'show_incart_plan' => true,
            ]);
            $stocks_html = '<div class="pc-cart-stocks" style="margin:.15rem 0 0">'.$panel.'</div>';
        }

        // ВОТ ЭТОГО НЕ ХВАТАЛО:
        $allowed = pc_cartguard_get_allowed_qty_for_cart($product, $qty);

            $out[] = [
                'product_id'   => $pid,
                'name'         => $product->get_name(),
                'permalink'    => $product->get_permalink(),
                'sku'       => $product->get_sku(),       // (опционально, если полезно)
                'plan_html'    => $plan_html,
                'stocks_html'  => $stocks_html,
                'allowed_max'  => (int) $allowed,
            ]; 
    }

    wp_send_json_success(['items'=>$out]);
}

add_action('wp_ajax_pc_cart_adjust',        'pc_cart_adjust_qty');
add_action('wp_ajax_nopriv_pc_cart_adjust', 'pc_cart_adjust_qty');

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
    ';
    wp_register_style('pc-cart-inline', false);
    wp_enqueue_style('pc-cart-inline');
    wp_add_inline_style('pc-cart-inline', $css);

    // JS
    wp_enqueue_script('jquery');

    // (1) Кламп qty — Classic + Blocks
wp_add_inline_script('jquery', <<<'JS'
jQuery(function($){
  var QTY_SEL = '.pc-cart-qty, .quantity .qty';

  // Кламп для классики
  function clamp($inp){
    var max  = parseFloat($inp.attr('max'))  || Infinity;
    var min  = parseFloat($inp.attr('min'))  || 0;
    var step = parseFloat($inp.attr('step')) || 1;
    var v    = String($inp.val()||'').trim().replace(',', '.');
    var n    = parseFloat(v); if(!isFinite(n)) n = min;
    n = Math.max(min, Math.min(max, n));
    if (step>0) n = Math.floor(n/step)*step;
    $inp.val(n);
    return n;
  }

function requestClassicUpdate(){
  var $form = $('form.woocommerce-cart-form');
  var $btn  = $form.find('button[name="update_cart"]');
  if ($btn.length){
    $btn.prop('disabled', false).trigger('click');
  } else if ($form.length) {
    // фоллбек: принудительно добавим флажок и отправим форму
    if (!$form.find('input[name="update_cart"]').length){
      $('<input type="hidden" name="update_cart" value="1">').appendTo($form);
    }
    $form.trigger('submit');
  }
}

  // qty клампим и обновляем форму
  $(document).on('input change', QTY_SEL, function(){ clamp($(this)); });
  var t=null;
  $(document).on('blur', QTY_SEL, function(){
    if ($('form.woocommerce-cart-form').length){
      clearTimeout(t); t=setTimeout(requestClassicUpdate, 60);
    }
  });
  $(document).on('click', '.quantity .plus, .quantity .minus', function(){
    var $inp = $(this).closest('.quantity').find('input.qty');
    setTimeout(function(){ clamp($inp); requestClassicUpdate(); }, 0);
  });
  $(document.body).on('updated_wc_div wc_fragments_refreshed', function(){
    $(QTY_SEL).each(function(){ clamp($(this)); });
  });
  $(QTY_SEL).each(function(){ clamp($(this)); });

    // === Принудительный апдейт корзины при смене ЛОКАЦИИ/РЕЖИМА списания
    // Покрываем <select>, <input>, <a> — и на change, и на click.
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
 * Вивід під назвою позиції: «Списання…» + міні-склади.
 * Робимо через ФІЛЬТР — він гарантовано в cart/cart.php
 */
add_filter('woocommerce_cart_item_name', function ($name, $cart_item, $cart_item_key) {
    $product = isset($cart_item['data']) ? $cart_item['data'] : null;
    if (!$product instanceof WC_Product) return $name;

    $pid = $product->get_id();
    $qty = (int) ($cart_item['quantity'] ?? 0);

    $has_slu_panel = function_exists('slu_render_stock_panel') ? '1' : '0';
    $has_view      = function_exists('pc_build_stock_view')     ? '1' : '0';
    pc_cg_log("filter cart_item_name: pid=$pid qty=$qty has_slu_panel=$has_slu_panel has_view=$has_view");

    // План списання
    $loc_title = '';
    if (function_exists('pc_build_stock_view')) {
        $view = pc_build_stock_view($product);
        if (!empty($view['preferred_name'])) $loc_title = (string) $view['preferred_name'];
        pc_cg_log('plan: preferred_name='.($view['preferred_name'] ?? ''));
    }
    if ($loc_title === '' && function_exists('slu_current_location_title')) {
        $loc_title = (string) slu_current_location_title();
        pc_cg_log('plan: fallback current_location='.$loc_title);
    }

    $plan_html = '<div class="pc-cart-plan" style="margin:.25rem 0 .15rem;color:#333">'
               . esc_html__('Списання:', 'woocommerce') . ' '
               . '<strong>' . esc_html($loc_title ?: '—') . ' — ' . intval($qty) . '</strong>'
               . '</div>';

    // Міні-склади
    $stocks_html = '';
    if (function_exists('slu_render_stock_panel')) {
        $panel = (string) slu_render_stock_panel($product, [
            'wrap_class'       => 'slu-stock-mini',
            'show_primary'     => true,
            'show_others'      => true,
            'show_total'       => true,
            'hide_when_zero'   => false,
            'show_incart'      => true,
            'show_incart_plan' => true,
        ]);
        $stocks_html = '<div class="pc-cart-stocks" style="margin:.15rem 0 0">'.$panel.'</div>';
        pc_cg_log('stocks: html_len='.strlen($panel));
    } else {
        pc_cg_log('stocks: slu_render_stock_panel not exists at this point');
    }

    return $name . $plan_html . $stocks_html;
}, 20, 3);

/**
 * Малюємо qty-інпут із правильними max/step + data-allowed (для JS).
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
         . 'data-allowed="'.esc_attr($max).'" />'
         . '</div>';
}, 20, 3);