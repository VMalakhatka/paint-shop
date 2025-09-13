jQuery(function ($) {
  var AJAX = (window.pcoeVars && pcoeVars.ajaxUrl) || '/wp-admin/admin-ajax.php';
  var I18N  = (window.pcoeVars && pcoeVars.i18n)   || {};
  var KEY_COLS  = 'pcoeCols';
  var KEY_SPLIT = 'pcoeSplit';

  function readCols(scope){ try{ var all=JSON.parse(localStorage.getItem(KEY_COLS)||'{}'); return all[scope]||[]; }catch(e){ return []; } }
  function writeCols(scope, arr){ try{ var all=JSON.parse(localStorage.getItem(KEY_COLS)||'{}'); all[scope]=arr; localStorage.setItem(KEY_COLS, JSON.stringify(all)); }catch(e){} }
  function readSplit(scope){ try{ var all=JSON.parse(localStorage.getItem(KEY_SPLIT)||'{}'); return all[scope]||'agg'; }catch(e){ return 'agg'; } }
  function writeSplit(scope, val){ try{ var all=JSON.parse(localStorage.getItem(KEY_SPLIT)||'{}'); all[scope]=val; localStorage.setItem(KEY_SPLIT, JSON.stringify(all)); }catch(e){} }

  // Инициализация чекбоксов/сплита
  $('.pcoe-cols').each(function(){
    var scope = $(this).data('scope');
    var sel = readCols(scope);
    if (sel.length){
      $(this).find('input.pcoe-col').each(function(){
        if (sel.indexOf($(this).val()) !== -1) $(this).prop('checked', true);
      });
    } else {
      $(this).find('input.pcoe-col[value="sku"],input.pcoe-col[value="name"],input.pcoe-col[value="qty"],input.pcoe-col[value="price"],input.pcoe-col[value="total"]').prop('checked', true);
    }
  });

  $('.pcoe-split').each(function(){
    var scope = $(this).data('scope');
    $(this).val(readSplit(scope));
  });

  $(document).on('change','.pcoe-col',function(){
    var wrap  = $(this).closest('.pcoe-cols');
    var scope = wrap.data('scope');
    var arr = [];
    wrap.find('input.pcoe-col:checked').each(function(){ arr.push($(this).val()); });
    writeCols(scope, arr);
  });

  $(document).on('change','.pcoe-split',function(){
    var scope = $(this).data('scope');
    writeSplit(scope, $(this).val());
  });

  $(document).on('click','.pcoe-export a.button',function(){
    var box = $(this).closest('.pcoe-export');
    var colsWrap = box.find('.pcoe-cols'); var scope = colsWrap.data('scope');
    var cols=[]; colsWrap.find('input.pcoe-col:checked').each(function(){ cols.push($(this).val()); });
    if(!cols.length){ cols=['sku','name','qty','price','total']; }
    var splitSel = box.find('.pcoe-split'); var split = splitSel.val() || 'agg';
    var href = new URL(this.href);
    href.searchParams.set('cols', cols.join(','));
    href.searchParams.set('split', split);
    this.href = href.toString();
  });

  function ensureNoteChecked(scope){
    var wrap = $('.pcoe-cols[data-scope="'+scope+'"]');
    var note = wrap.find('input.pcoe-col[value="note"]');
    if (!note.prop('checked')) { note.prop('checked', true).trigger('change'); }
  }
  $('.pcoe-split').each(function(){ var scope=$(this).data('scope'); if($(this).val()==='per_loc') ensureNoteChecked(scope); });
  $(document).on('change','.pcoe-split',function(){ var scope=$(this).data('scope'); if($(this).val()==='per_loc') ensureNoteChecked(scope); });

  // === Импорт в корзину
  $(document).on('submit','#pcoe-import-form',function(e){
    e.preventDefault();
    var $f   = $(this), $msg = $f.find('.pcoe-import-msg');
    var fd = new FormData(this); fd.append('action','pcoe_import_cart');
    $msg.text(I18N.importing || 'Importing…');
    $.ajax({ url: AJAX, method: 'POST', data: fd, contentType: false, processData: false })
      .done(function(resp){
        if(resp && resp.success){
          $msg.text((I18N.added || 'Added')+': '+resp.data.added+', '+(I18N.skipped || 'Skipped')+': '+resp.data.skipped);
          if(resp.data.report_html){
            if (!$('.pcoe-import-report').length){
              $('<div class="pcoe-import-report" style="margin-top:10px"></div>').insertAfter($f.closest('div'));
            }
            $('.pcoe-import-report').html(resp.data.report_html);
          }
          setTimeout(function(){ window.location.reload(); }, 1200);
        } else {
          $msg.text((resp && resp.data && resp.data.msg) ? resp.data.msg : (I18N.import_error || 'Import error.'));
        }
      })
      .fail(function(){
        $msg.text(I18N.conn_error || 'Connection error.');
      });
    return false;
  });

    // === Імпорт у чернетку
    $(document).on('submit','#pcoe-import-draft-form',function(){
    var $f   = $(this), $msg = $f.find('.pcoe-import-draft-msg');
    var $box = $('.pcoe-import-draft-result');
    $msg.text(I18N.importing); $box.hide();

    var fd = new FormData(this);
    fd.append('action','pcoe_import_order_draft');
    fd.append('ignore_stock','1');          // <<< ВАЖНО: черновик НЕ режем по остаткам

    $.ajax({
        url: AJAX, method: 'POST', data: fd, contentType: false, processData: false
        })
      .done(function(resp){
        if(resp && resp.success){
          $msg.text((I18N.imported || 'Imported')+': '+resp.data.imported+', '+(I18N.skipped || 'Skipped')+': '+resp.data.skipped);

          var linksHtml='';
          if(resp.data.links){
            if(resp.data.links.edit){ linksHtml += '<a class="button" href="'+resp.data.links.edit+'" target="_blank" rel="noopener">'+(I18N.open_in_admin || 'Open in admin')+'</a> '; }
            if(resp.data.links.view){ linksHtml += '<a class="button" href="'+resp.data.links.view+'" target="_blank" rel="noopener">'+(I18N.view_order || 'View order')+'</a>'; }
          }
          $('.pcoe-import-draft-links').html(linksHtml||'');
          $('.pcoe-import-draft-report').html(resp.data.report_html||'');
          $box.show();

          var refreshLabel = (I18N.refresh_list || 'Оновити список');
          if (!$('.pcoe-refresh-list').length) {
            $('<button type="button" class="button button-primary pcoe-refresh-list" style="margin-left:8px"></button>')
                .text(refreshLabel)
                .appendTo('.pcoe-import-draft-links')
                .on('click', function(){ window.location.reload(); });
          }
        } else {
          $msg.text((resp && resp.data && resp.data.msg) ? resp.data.msg : (I18N.import_error || 'Import error.'));
        }
      })
      .fail(function(){
        $msg.text(I18N.conn_error || 'Connection error.');
      });

    return false;
  });
});