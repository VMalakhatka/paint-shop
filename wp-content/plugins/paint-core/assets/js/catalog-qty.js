/* ultra-min catalog-qty.js — без перехватов клика */
(function($){

  /* ===== (N) на кнопках — простая карта ===== */
  var cartMap = {};
  function getMax($wrap){
  return parseInt($wrap.attr('data-max') || $wrap.data('max') || '0', 10) || 0;
}
function setMax($wrap, newMax){
  newMax = Math.max(0, parseInt(newMax,10) || 0);
  $wrap.attr('data-max', newMax).data('max', newMax);
  var $inp  = $wrap.find('.loop-qty');
  var $view = $wrap.find('.loop-qty-view');
  var min   = parseInt($inp.attr('min')||'1',10)||1;

  if(newMax <= 0){
    // выключаем qty и прячем возможность добавлять
    $wrap.addClass('is-disabled');
    $inp.prop('disabled', true).val('0');
    if($view.length) $view.text('0');
    // по желанию: заблокировать кнопку
    var $btn = $wrap.closest('.product,li.product').find('.add_to_cart_button').first();
    $btn.prop('disabled', true).addClass('disabled');
    return;
  }

  // активный режим
  $wrap.removeClass('is-disabled');
  $inp.prop('disabled', false).attr('max', String(newMax));

  // если текущее значение > max — подрежем
  var cur = parseInt(String($inp.val()||'').replace(/\D+/g,''),10) || min;
  if(cur > newMax){ cur = newMax; $inp.val(String(cur)); }
  if($view.length) $view.text(String(cur));
}
  function readQtyFromButton($btn){
    var m = ($btn.text()||'').match(/\((\d+)\)/);
    return m ? parseInt(m[1],10)||0 : 0;
  }
  function rebuildMap(){
    cartMap = {};
    $('.products .add_to_cart_button').each(function(){
      var $b=$(this), pid=parseInt($b.data('product_id'),10);
      if(!pid) return;
      var q=readQtyFromButton($b); if(q>0) cartMap[pid]=q;
    });
  }
  function applyMap(){
    $('.products .add_to_cart_button').each(function(){
      var $b=$(this), pid=parseInt($b.data('product_id'),10);
      var q=cartMap[pid]||0; $b.text(q>0?'('+q+')':'');
    });
  }
  function initButtons(){ rebuildMap(); applyMap(); }

  /* ===== qty <-> view + data-quantity на кнопке ===== */
  function clampVal(n,min,max){
    n = isNaN(n)?min:n;
    n = Math.max(min, n);
    if(max>0) n = Math.min(max, n);
    return n;
  }
  function setBtnQty($wrap, q){
    var $btn = $wrap.closest('.product,li.product').find('.add_to_cart_button').first();
    if($btn.length){ $btn.attr('data-quantity', q).data('quantity', q); }
  }
  function syncView($wrap){
    var $inp  = $wrap.find('.loop-qty');
    var $view = $wrap.find('.loop-qty-view');
    if($view.length){
      var raw = String($inp.val()||'').replace(/\D+/g,'');
      if(raw===''){ raw='1'; $inp.val('1'); }
      $view.text(raw);
    }
  }

  // +/- кнопки
  $(document).on('click','.loop-qty-btn',function(){
    var $wrap=$(this).closest('.loop-qty-wrap');
    var $inp =$wrap.find('.loop-qty');
    var val  = parseInt(String($inp.val()||'').replace(/\D+/g,''),10)||1;
    var min  = parseInt($inp.attr('min')||'1',10)||1;
    var max  = parseInt($inp.attr('max')||'0',10)||0;

    if($(this).hasClass('loop-qty-plus'))  val++;
    if($(this).hasClass('loop-qty-minus')) val--;

    val = clampVal(val,min,max);
    $inp.val(String(val)).trigger('change');
    syncView($wrap);
    setBtnQty($wrap, val);
  });

  // ручной ввод
  $(document).on('input change','.loop-qty',function(){
    var $wrap=$(this).closest('.loop-qty-wrap');
    var min  = parseInt(this.min||'1',10)||1;
    var max  = parseInt(this.max||'0',10)||0;
    var val  = parseInt(String($(this).val()||'').replace(/\D+/g,''),10)||min;

    val = clampVal(val,min,max);
    $(this).val(String(val));
    syncView($wrap);
    setBtnQty($wrap, val);
  });

  /* ===== Подставляем количество в payload перед отправкой ===== */
  $(document.body).on('adding_to_cart', function(e, $button, data){
    var q = parseInt(String($button.attr('data-quantity')||'1').replace(/\D+/g,''),10) || 1;
    data.quantity = q; // важно: Woo прочитает это и не будет "всегда 1"
  });

  // Обновим "(N)" только для этого товара
  $(document.body).on('added_to_cart', function(e, fragments, cart_hash, $button){
    if(!$button || !$button.length) return;
    $button.siblings('a.added_to_cart.wc-forward').remove();
    $button.removeClass('added');

    var pid = parseInt($button.data('product_id'),10);
    var add = parseInt($button.attr('data-quantity')||'1',10)||1;

    var current = cartMap[pid];
    if(typeof current==='undefined'){
      var $any = $('.products .add_to_cart_button').filter(function(){
        return parseInt($(this).data('product_id'),10)===pid;
      }).first();
      current = readQtyFromButton($any);
    }
    cartMap[pid] = (current||0)+add;

    // ... уже есть: cartMap[pid] = (current||0)+add;

    $('.products .product, li.product').each(function(){
      var $p = $(this);
      var $b = $p.find('.add_to_cart_button').first();
      if(parseInt($b.data('product_id'),10) !== pid) return;

      // уменьшить per-card лимит
      var $wrap = $p.find('.loop-qty-wrap').first();
      if($wrap.length){
        var oldMax = getMax($wrap);
        var newMax = oldMax - add;
        setMax($wrap, newMax);
      }

      // обновить текст на всех кнопках этого товара
      $b.text(cartMap[pid] > 0 ? '('+cartMap[pid]+')' : '');
    });
  });

  /* ===== Инициализация ===== */
  function boot(){
    $('.loop-qty-wrap').each(function(){
      var $w=$(this), $inp=$w.find('.loop-qty');
      var min=parseInt($inp.attr('min')||'1',10)||1;
      var max=parseInt($inp.attr('max')||'0',10)||0;
      var val=parseInt(String($inp.val()||'').replace(/\D+/g,''),10)||min;
      val = clampVal(val,min,max);
      $inp.val(String(val));
      syncView($w);
      setBtnQty($w, val);
      var maxNow = getMax($w);
      if(maxNow <= 0){
        setMax($w, 0);
      }
    });
    initButtons();
  }
  $(document).ready(boot);
  $(document.body).on('wc_fragments_loaded wc_fragments_refreshed', boot);

})(jQuery);
/* === Склейка qty и кнопки в один ряд (wrap) === */
(function($){
  function wrapBuyRows(){
    $('.products .product, li.product').each(function(){
      var $p   = $(this);
      if ($p.find('.loop-buy-row').length) return;

      var $qty = $p.find('.loop-qty-wrap').first();
      var $btn = $p.find(
        // разные варианты кнопок у тем/плагинов
        '.add_to_cart_button, [data-product_id].ajax_add_to_cart, [data-product_id].button, a.button, button.button'
      ).first();

      if ($qty.length && $btn.length){
        $qty.add($btn).wrapAll('<div class="loop-buy-row"></div>');
      }
    });
  }

  // первичный запуск и после всех Woo событиях с фрагментами
  $(document).ready(wrapBuyRows);
  $(document.body).on('wc_fragments_loaded wc_fragments_refreshed added_to_cart updated_wc_div', wrapBuyRows);
})(jQuery);