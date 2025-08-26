(function($){
  function clamp(n, min, max){
    n = parseInt(n,10); if (isNaN(n)) n = min;
    if (n < min) n = min;
    if (max > 0 && n > max) n = max;
    return n;
  }

  function reduceMaxQty($form, added){
    var $qty = $form.find('input.qty');
    if (!$qty.length) return;

    var min = parseInt($qty.attr('min')||'1',10)||1;
    var max = parseInt($qty.attr('max')||'0',10)||0;

    if (max > 0){
      var newMax = Math.max(0, max - added);
      $qty.attr('max', String(newMax));
      if (newMax === 0){
        $qty.val('0').prop('disabled', true)
            .css({opacity:1, visibility:'visible', display:'inline-block'});
        $form.find('.single_add_to_cart_button').prop('disabled', true).addClass('disabled');
      } else {
        var cur = clamp($qty.val(), min, newMax);
        $qty.val(String(cur)).prop('disabled', false);
        $form.find('.single_add_to_cart_button').prop('disabled', false).removeClass('disabled');
      }
    }
  }

  // Клампим ввод qty на PDP
  function bindClamp(){
    var $qty = $('form.cart input.qty');
    $qty.css({opacity:1, visibility:'visible', display:'inline-block'});
    $(document).off('.pcPdpQty');
    $(document).on('input.pcPdpQty change.pcPdpQty', 'form.cart input.qty', function(){
      var min = parseInt(this.min||'1',10)||1;
      var max = parseInt(this.max||'0',10)||0;
      var v   = clamp($(this).val(), min, max);
      $(this).val(String(v));
    });
  }

    // ✅ сохраняем <strong> и устойчиво обновляем число
    function bumpInCartOnPage(added){
        var $strong = $('.slu-stock-box').find('strong:contains("В корзине")').first();
        if (!$strong.length) return;

        var $row = $strong.closest('div');
        // текущее число берём из конца текста/HTML
        var current = 0;
        var t = $row.text();
        var m = t.match(/(\d+)\s*$/);
        if (m) current = parseInt(m[1],10) || 0;

        var next = current + (parseInt(added,10) || 0);
        // ВОЗВРАЩАЕМ разметку со <strong>
        $row.html('<strong>В корзине</strong>: ' + next);
    }

  // Перехват submit на PDP
  $(document).on('submit', 'form.cart', function(e){
    var $form = $(this);
    var $btn  = $form.find('[type="submit"].single_add_to_cart_button');

    if ($btn.hasClass('ajax_add_to_cart')) return; // тема уже сама делает AJAX

    e.preventDefault();

    var product_id  = parseInt($form.find('[name="add-to-cart"]').val() || $btn.val(), 10) || 0;
    var quantity    = parseInt($form.find('input.qty').val() || '1', 10) || 1;
    var variation_id= parseInt($form.find('input[name="variation_id"]').val() || '0', 10) || 0;

    if (!product_id){ $form.off('submit').trigger('submit'); return; }

    $btn.prop('disabled', true).addClass('loading');

    var data = $form.serializeArray();
    data.push({name:'product_id', value:product_id});
    data.push({name:'quantity',   value:quantity});
    if (variation_id) data.push({name:'variation_id', value:variation_id});

    $.ajax({
      type:'POST',
      url: (window.wc_add_to_cart_params && window.wc_add_to_cart_params.wc_ajax_url)
             ? wc_add_to_cart_params.wc_ajax_url.replace('%%endpoint%%','add_to_cart')
             : (window.location.origin + '/?wc-ajax=add_to_cart'),
      data: $.param(data),
      success: function(resp){
        if (resp && resp.fragments){
          $(document.body).trigger('added_to_cart', [resp.fragments, resp.cart_hash, $btn]);
          $(document.body).trigger('wc_fragments_refreshed');
        }
        // локально: уменьшаем max и обновляем "В корзине: N"
        reduceMaxQty($form, quantity);
        bumpInCartOnPage(quantity);
      },
      error: function(){
        // бэкап — обычный сабмит
        $form.off('submit').trigger('submit');
      },
      complete: function(){
        $btn.prop('disabled', false).removeClass('loading');
      }
    });
  });

  // первичная инициализация
  $(function(){
    bindClamp();
  });
  // на случай перерисовок фрагментов
  $(document.body).on('wc_fragments_loaded wc_fragments_refreshed', bindClamp);

})(jQuery);