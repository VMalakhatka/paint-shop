<?php
namespace PaintUX\Catalog;
defined('ABSPATH') || exit;


/**
 * WooCommerce: qty в листинге, кнопка с SVG‑иконкой и количеством (без перезагрузки),
 * скрыть “View cart” и галочку темы, мобильная qty, показ остатка + лимит по складу,
 * компоновка: qty и кнопка в один ряд.
 * ФИКС: количество передаём через событие `adding_to_cart`, чтобы не было «всегда 1».
 */

/* ==== НАСТРОЙКИ ==== */
add_action('init', function () {
    $GLOBALS['wc_loop_cfg'] = [
        // текст на кнопке: только (N) или пусто — сама иконка рисуется через CSS ::before
        'format_in_cart'  => '(%d)',
        'format_zero'     => '',
        'apply_on_single' => false, // менять текст на странице товара? (false = как у темы)
        'min'             => 1,
        'max'             => 99,    // 0 = без лимита
        'show_plus_minus' => true,
    ];
});

/* ==== Сколько этого товара уже в корзине ==== */
function wc_loop_get_in_cart_count( $product ) {
    if ( ! WC()->cart ) return 0;
    $pid = $product->get_id();
    $qty = 0;
    foreach ( WC()->cart->get_cart() as $item ) {
        if ( (int)$item['product_id'] === (int)$pid ) {
            $qty += (int)$item['quantity'];
        }
    }
    return $qty;
}

/* ==== Текст кнопки в каталоге ==== */
add_filter( 'woocommerce_product_add_to_cart_text', function( $text, $product ){
    $cfg = $GLOBALS['wc_loop_cfg'] ?? [];
    $qty = wc_loop_get_in_cart_count($product);
    return $qty > 0 ? sprintf($cfg['format_in_cart'], $qty) : ($cfg['format_zero'] ?? '');
}, 10, 2 );

/* ==== Текст кнопки на странице товара (если включено) ==== */
add_filter( 'woocommerce_product_single_add_to_cart_text', function( $text ){
    $cfg = $GLOBALS['wc_loop_cfg'] ?? [];
    if ( empty($cfg['apply_on_single']) ) return $text;
    global $product;
    $qty = wc_loop_get_in_cart_count($product);
    return $qty > 0 ? sprintf($cfg['format_in_cart'], $qty) : ($cfg['format_zero'] ?? '');
}, 10, 1 );

/* ==== Количество в листинге (перед кнопкой) ==== */
add_action('woocommerce_after_shop_loop_item', function () {
    global $product;
    if ( ! ($product instanceof \WC_Product) ) return;
    if ( ! $product->is_purchasable() || $product->is_sold_individually() ) return;
    if ( $product->is_type('variable') ) return;

    $cfg = $GLOBALS['wc_loop_cfg'] ?? [];
    $min = max(1, (int)($cfg['min'] ?? 1));
    $max = (int)($cfg['max'] ?? 0);
    $pid = $product->get_id();

    echo '<div class="loop-qty-wrap" data-product-id="'.esc_attr($pid).'">';
    if ( ! empty($cfg['show_plus_minus']) ) {
        echo '<button type="button" class="loop-qty-btn loop-qty-minus" aria-label="Minus">−</button>';
    }
    echo '<input type="text" inputmode="numeric" pattern="[0-9]*" class="input-text qty text loop-qty" '
        .'value="'.esc_attr($min).'" min="'.esc_attr($min).'" '
        .($max>0 ? 'max="'.esc_attr($max).'" ' : '')
        .'step="1" />';
    echo '<span class="loop-qty-view" aria-hidden="true">'.esc_html($min).'</span>';
    if ( ! empty($cfg['show_plus_minus']) ) {
        echo '<button type="button" class="loop-qty-btn loop-qty-plus" aria-label="Plus">+</button>';
    }
    echo '</div>';
}, 9);

/* ==== Остаток сразу после цены (добавляем data-stock) ==== */
add_action( 'woocommerce_after_shop_loop_item_title', function () {
    global $product;
    if ( ! $product instanceof \WC_Product ) return;

    if ( $product->is_in_stock() ) {
        if ( $product->managing_stock() ) {
            $qty = (int) $product->get_stock_quantity();
            if ( $qty > 0 ) {
                echo '<span class="loop-stock-top in" data-stock="'.esc_attr($qty).'">На складе: ' . esc_html( $qty ) . '</span>';
            } else {
                echo '<span class="loop-stock-top pre" data-stock="0">Под заказ</span>';
            }
        } else {
            echo '<span class="loop-stock-top in" data-stock="">В наличии</span>'; // пусто = без лимита
        }
    } else {
        echo '<span class="loop-stock-top out" data-stock="0">Нет в наличии</span>';
    }
}, 11);

/* ==== Стили + JS ==== */
add_action('wp_footer', function () {
    if ( is_admin() ) return; ?>
<style>
/* ===== Base qty UI ===== */
.loop-qty-wrap{display:flex;align-items:center;gap:.35rem;margin-bottom:.45rem}
.loop-qty{
  width:48px;height:28px;font-size:15px;line-height:28px;
  text-align:center;padding:0;margin:0;border:1px solid #000;
  background:#fff !important;color:#000 !important;-webkit-text-fill-color:#000 !important;
  caret-color:#000;
}
.loop-qty-btn{border:none;background:transparent;color:#000;font-size:17px;padding:0 .3rem;cursor:pointer;height:28px;display:flex;align-items:center;justify-content:center}
.loop-qty-view{display:none}

/* Кнопка (рамка/фон темы оставляем, но центруем содержимое) */
.products .add_to_cart_button,
.products .added_to_cart{border:1px solid #8B4513 !important;box-sizing:border-box;background:#ede9ef}
.products .add_to_cart_button.added:after{content:none !important}
.added_to_cart.wc-forward{display:none!important}

/* Цвета */
.products .woocommerce-loop-product__title{color:#8B4513!important}
.products .price,.products .woocommerce-Price-amount{color:#000!important}

/* Остаток рядом с ценой */
.loop-stock-top{display:inline-block;margin-left:8px;font-size:12px;vertical-align:baseline;white-space:nowrap}
.loop-stock-top.in{color:#2e7d32}.loop-stock-top.pre{color:#8B4513}.loop-stock-top.out{color:#c62828}
@media (max-width:430px){.loop-stock-top{display:block;margin-left:0;margin-top:2px}}

/* Планшеты */
@media (max-width:768px){
  .loop-qty-wrap{gap:.25rem;margin-bottom:.35rem}
  .loop-qty{width:40px;height:24px;font-size:13px;line-height:24px}
  .loop-qty-btn{font-size:15px;height:24px;padding:0 .25rem}
}

/* Мобилки: показываем span, скрываем input */
@media (max-width:430px){
  .loop-qty-wrap{gap:.10rem;align-items:center}
  .loop-qty{display:none !important}
  .loop-qty-view{display:inline-block;width:auto;min-width:14px;text-align:center;font-size:12px;line-height:20px;color:#000;border:1px solid #000;background:#fff;height:20px;margin:0;padding:0 1px}
  .loop-qty-btn{font-size:12px;height:20px;padding:0 .1rem}
}
@media (max-width:360px){
  .loop-qty-view{font-size:11px;line-height:18px;height:18px;min-width:12px}
  .loop-qty-btn{font-size:11px;height:18px}
}

/* Заблокированная кнопка */
.products .add_to_cart_button.disabled,
.products .add_to_cart_button:disabled{opacity:.55;cursor:not-allowed}

/* ===== Ряд покупки: qty + кнопка ===== */
.loop-buy-row{display:flex;align-items:center;gap:.5rem;margin-top:.35rem}
.loop-buy-row .loop-qty-wrap{margin-bottom:0}

/* Кнопка в ряду: жёсткая центровка + зазор под знак (N) */
.loop-buy-row .add_to_cart_button,
.loop-qty-wrap + .add_to_cart_button,
.loop-buy-row [data-product_id].button,
.loop-qty-wrap + [data-product_id].button,
.loop-buy-row [data-product_id].ajax_add_to_cart,
.loop-qty-wrap + [data-product_id].ajax_add_to_cart,
.loop-buy-row a.button,
.loop-qty-wrap + a.button,
.loop-buy-row button.button,
.loop-qty-wrap + button.button{
  display:inline-flex !important;
  align-items:center !important;
  justify-content:center !important;
  height:28px !important;
  line-height:1 !important;
  padding:0 .75rem !important;
  white-space:nowrap;
  gap:.40rem; /* расстояние между иконкой и "(N)" */
}

/* SVG‑иконка тележки через ::before (берёт цвет из currentColor) */
.loop-buy-row .add_to_cart_button::before,
.loop-qty-wrap + .add_to_cart_button::before,
.loop-buy-row [data-product_id].button::before,
.loop-qty-wrap + [data-product_id].button::before,
.loop-buy-row [data-product_id].ajax_add_to_cart::before,
.loop-qty-wrap + [data-product_id].ajax_add_to_cart::before,
.loop-buy-row a.button::before,
.loop-qty-wrap + a.button::before,
.loop-buy-row button.button::before,
.loop-qty-wrap + button.button::before{
  content:"";
  display:inline-block;
  width:1.1em;height:1.1em;
  background:no-repeat center/contain
    url("data:image/svg+xml;utf8,\
<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='1.8' stroke-linecap='round' stroke-linejoin='round'>\
<circle cx='9' cy='20' r='1.5'/>\
<circle cx='19' cy='20' r='1.5'/>\
<path d='M2 3h3l2.5 12h11l2-9H6'/>\
</svg>");
}

.add_to_cart_button.disabled::before,
.add_to_cart_button:disabled::before{opacity:.6}

@media (max-width:430px){
  .loop-buy-row{gap:.35rem}
  .loop-buy-row .add_to_cart_button,
  .loop-qty-wrap + .add_to_cart_button,
  .loop-buy-row [data-product_id].button,
  .loop-qty-wrap + [data-product_id].button,
  .loop-buy-row [data-product_id].ajax_add_to_cart,
  .loop-qty-wrap + [data-product_id].ajax_add_to_cart,
  .loop-buy-row a.button,
  .loop-qty-wrap + a.button,
  .loop-buy-row button.button,
  .loop-qty-wrap + button.button{
    height:20px !important;line-height:1 !important;padding:0 .55rem !important;font-size:14px;gap:.35rem
  }
  .loop-buy-row .add_to_cart_button::before,
  .loop-qty-wrap + .add_to_cart_button::before,
  .loop-buy-row [data-product_id].button::before,
  .loop-qty-wrap + [data-product_id].button::before,
  .loop-buy-row [data-product_id].ajax_add_to_cart::before,
  .loop-qty-wrap + [data-product_id].ajax_add_to_cart::before,
  .loop-buy-row a.button::before,
  .loop-qty-wrap + a.button::before,
  .loop-buy-row button.button::before,
  .loop-qty-wrap + button.button::before{
    width:1em;height:1em
  }
}
</style>

<script>
(function($){
  /* ===== Стабильная логика обновления текста кнопки ===== */
  var cartMap = {};

  function readQtyFromButton($btn){
    // теперь на кнопке "(N)" или пусто
    var m = ($btn.text()||'').match(/\((\d+)\)/);
    return m ? parseInt(m[1],10)||0 : 0;
  }

  function rebuildMapFromButtons(){
    cartMap = {};
    $('.products .add_to_cart_button').each(function(){
      var $b = $(this);
      var pid = parseInt($b.data('product_id'),10);
      if(!pid) return;
      var q = readQtyFromButton($b);
      if(q>0) cartMap[pid] = q;
    });
  }

  function applyMapToButtons(){
    $('.products .add_to_cart_button').each(function(){
      var $b  = $(this);
      var pid = parseInt($b.data('product_id'),10);
      var q   = cartMap[pid] || 0;
      $b.text(q > 0 ? '(' + q + ')' : ''); // только число или пусто
    });
  }

  function initButtons(){
    rebuildMapFromButtons();
    applyMapToButtons();
  }

  function syncQtyView($wrap){
    var $inp  = $wrap.find('.loop-qty');
    var $view = $wrap.find('.loop-qty-view');
    if(!$view.length) return;
    var raw = ($inp.val()||'').replace(/\D+/g,''); if(raw===''){ raw='1'; $inp.val('1'); }
    $view.text(raw);
  }

  // +/- и ручной ввод (как у тебя)
  $(document).on('click', '.loop-qty-btn', function(){
    var $wrap = $(this).closest('.loop-qty-wrap');
    var $inp  = $wrap.find('.loop-qty');
    var val   = parseInt(($inp.val()||'').replace(/\D+/g,''),10) || 1;
    var min   = parseInt($inp.attr('min')||'1',10) || 1;
    var max   = parseInt($inp.attr('max')||'0',10) || 0;
    if ($(this).hasClass('loop-qty-plus'))   val++;
    if ($(this).hasClass('loop-qty-minus'))  val--;
    if (val < min) val = min;
    if (max>0 && val > max) val = max;
    $inp.val(String(val)).trigger('change');
    syncQtyView($wrap);
  });

  $(document).on('input', '.loop-qty', function(){
    var $inp = $(this), $wrap = $inp.closest('.loop-qty-wrap');
    var raw  = ($inp.val()||'').replace(/\D+/g,'');
    if(raw === '') { $inp.val(''); syncQtyView($wrap); return; }
    var n = parseInt(raw,10) || 0;
    var min = parseInt($inp.attr('min')||'1',10) || 1;
    var max = parseInt($inp.attr('max')||'0',10) || 0;
    if(n < min) n = min;
    if(max>0 && n > max) n = max;
    $inp.val(String(n)); syncQtyView($wrap);
  });

  /* === ФИКС: подставляем правильное количество ДО отправки (Woo AJAX) === */
  $(document.body).on('adding_to_cart', function(e, $button, data){
    var $wrap = $button.closest('.product, li.product, .wc-block-grid__product');
    var $qty  = $wrap.find('.loop-qty').first();
    var val   = ($qty.val()||$wrap.find('.loop-qty-view').first().text()||'1').replace(/\D+/g,'');
    var q     = parseInt(val,10) || 1;
    data.quantity = q;                              // для ajax
    $button.attr('data-quantity', q).data('quantity', q); // для совместимости с темами
  });

  // После AJAX-добавления — обновляем только текст “(N)”
  $(document.body).on('added_to_cart', function(e, fragments, cart_hash, $button){
    if($button && $button.length){
      $button.siblings('a.added_to_cart.wc-forward').remove();
      $button.removeClass('added');

      var pid = parseInt($button.data('product_id'),10);
      var add = parseInt($button.attr('data-quantity')||'1',10) || 1;

      var current = cartMap[pid];
      if(typeof current === 'undefined'){
        var $any = $('.products .add_to_cart_button').filter(function(){
          return parseInt($(this).data('product_id'),10) === pid;
        }).first();
        current = readQtyFromButton($any);
      }
      cartMap[pid] = (current||0) + add;

      $('.products .add_to_cart_button').each(function(){
        var $b = $(this);
        if(parseInt($b.data('product_id'),10) === pid){
          $b.text(cartMap[pid] > 0 ? '(' + cartMap[pid] + ')' : '');
        }
      });
    }
  });

  $(document.body).on('wc_fragments_refreshed wc_fragments_loaded', function(){
    $('.loop-qty-wrap').each(function(){ syncQtyView($(this)); });
    initButtons();
  });

  $(document).ready(function(){
    $('.loop-qty-wrap').each(function(){ syncQtyView($(this)); });
    initButtons();
  });
})(jQuery);

/* ===== Лимит по складу (как у тебя) ===== */
(function($){
  function getPid($prod){ var $b = $prod.find('.add_to_cart_button').first(); return parseInt($b.data('product_id'),10) || 0; }
  function getStock($prod){
    var s = $prod.find('.loop-stock-top').attr('data-stock');
    if (s === undefined || s === null || s === '') return null; // безлимит
    var n = parseInt(String(s).replace(/\D+/g,''),10);
    return isNaN(n) ? null : n;
  }
  function getInCart(pid){
    var $any = $('.products .add_to_cart_button').filter(function(){ return parseInt($(this).data('product_id'),10) === pid; }).first();
    var m = ($any.text()||'').match(/\((\d+)\)/);
    return m ? (parseInt(m[1],10)||0) : 0;
  }
  function applyLimit($prod){
    var pid   = getPid($prod); if(!pid) return;
    var total = getStock($prod);
    var left  = (total === null) ? null : Math.max(0, total - getInCart(pid));

    var $qty  = $prod.find('.loop-qty').first();
    var $view = $prod.find('.loop-qty-view').first();
    var $plus = $prod.find('.loop-qty-plus');
    var $minus= $prod.find('.loop-qty-minus');
    var $btn  = $prod.find('.add_to_cart_button');

    if (left === null){
      $qty.removeAttr('max'); $plus.prop('disabled', false);
      $btn.prop('disabled', false).removeClass('disabled');
      return;
    }
    var maxVal = Math.max(1, left); $qty.attr('max', maxVal);

    var val = parseInt(($qty.val()||'1').replace(/\D+/g,''),10) || 1;
    if (val > maxVal){ val = maxVal; $qty.val(val).trigger('change'); if ($view.length) $view.text(String(val)); }

    var noLeft = left <= 0;
    $plus.prop('disabled', noLeft);
    $btn.prop('disabled', noLeft).toggleClass('disabled', noLeft);
    // минус никогда не блокируем (минимум = 1)
    if($minus.length){ $minus.prop('disabled', false); }
  }
  function applyAll(){ $('.products .product, li.product').each(function(){ applyLimit($(this)); }); }

  $(document).ready(applyAll);
  $(document).on('click', '.loop-qty-btn', function(){ applyLimit($(this).closest('.product, li.product')); });
  $(document).on('input change', '.loop-qty', function(){ applyLimit($(this).closest('.product, li.product')); });

  // при попытке добавить больше остатка — мягко подрежем число до остатка
  $(document).on('click', '.add_to_cart_button', function(e){
    var $prod = $(this).closest('.product, li.product');
    var total = getStock($prod);
    if (total === null) return;

    var left  = Math.max(0, total - getInCart(getPid($prod)));
    var $qty  = $prod.find('.loop-qty').first();
    var q     = parseInt(($qty.val()||'1').replace(/\D+/g,''),10) || 1;

    if (left <= 0){ e.preventDefault(); applyLimit($prod); return false; }
    if (q > left){
      $qty.val(left).trigger('change');
      var $view = $prod.find('.loop-qty-view').first(); if ($view.length) $view.text(String(left));
      $(this).attr('data-quantity', left).data('quantity', left);
    }
  });

  $(document.body).on('added_to_cart wc_fragments_refreshed wc_fragments_loaded', applyAll);
})(jQuery);

/* ==== Склейка qty и кнопки сразу в один ряд ==== */
(function($){
  function wrapBuyRows(){
    $('.products .product, li.product').each(function(){
      var $p = $(this);
      if ($p.find('.loop-buy-row').length) return;

      var $qty = $p.find('.loop-qty-wrap').first();
      var $btn = $p.find(
        '.add_to_cart_button, ' +
        '[data-product_id].ajax_add_to_cart, ' +
        '[data-product_id].button, ' +
        'a.button, button.button'
      ).first();

      if ($qty.length && $btn.length){
        $qty.add($btn).wrapAll('<div class="loop-buy-row"></div>');
      }
    });
  }
  $(document).ready(wrapBuyRows);
  $(document.body).on('wc_fragments_loaded wc_fragments_refreshed', wrapBuyRows);
})(jQuery);
</script>
<?php
});