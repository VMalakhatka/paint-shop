(function($){
  /* ===== Стабильная логика обновления текста кнопки ===== */
  var cartMap = {};

  function readQtyFromButton($btn){
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
      $b.text(q > 0 ? '(' + q + ')' : '');
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
    data.quantity = q;
    $button.attr('data-quantity', q).data('quantity', q);
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

/* ===== Лимит по складу ===== */
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
    if($minus.length){ $minus.prop('disabled', false); }
  }
  function applyAll(){ $('.products .product, li.product').each(function(){ applyLimit($(this)); }); }

  $(document).ready(applyAll);
  $(document).on('click', '.loop-qty-btn', function(){ applyLimit($(this).closest('.product, li.product')); });
  $(document).on('input change', '.loop-qty', function(){ applyLimit($(this).closest('.product, li.product')); });

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