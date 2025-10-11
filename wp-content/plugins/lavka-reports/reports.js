(function($){
    function addWarehouseHidden(id, name){
        var idx = $('#lr-warehouses-selected input').length / 2;
        var wrap = $('#lr-warehouses-selected');
        wrap.append('<input type="hidden" name="lavka_reports_opts[warehouses]['+idx+'][id]" value="'+id+'" />');
        wrap.append('<input type="hidden" name="lavka_reports_opts[warehouses]['+idx+'][name]" value="'+$('<div>').text(name).html()+'" />');
        wrap.append('<div class="lr-chip" data-id="'+id+'">'+name+'</div>');
    }
    $(document).on('click','#lr-load-warehouses', function(){
      $.post(LavkaReports.ajax,{action:'lavr_ref_warehouses',nonce:LavkaReports.nonce},function(resp){
        if(!resp||!resp.success){ alert((resp&&resp.data&&resp.data.err)||LavkaReports.i18n.error); return; }
        var $sel = $('#lr-warehouses').empty();
        resp.data.items.forEach(function(i){ $('<option>').val(i.id).text(i.id+' — '+i.name).appendTo($sel); });
      });
    });
    $(document).on('click','#lr-clear-warehouses', function(){ $('#lr-warehouses').empty(); $('#lr-warehouses-selected').empty(); });
    $(document).on('dblclick change','#lr-warehouses', function(){
      var opts=$(this).find('option:selected');
      opts.each(function(){
        var id=$(this).val(), name=$(this).text().split(' — ').slice(1).join(' — ');
        if($('#lr-warehouses-selected .lr-chip[data-id="'+id+'"]').length) return;
        addWarehouseHidden(id,name);
      });
    });
  
        function addOpHidden(code, name){
            var idx = $('#lr-ops-selected input').length/2;
            var wrap = $('#lr-ops-selected');
            wrap.append('<input type="hidden" name="lavka_reports_opts[ops_exclude]['+idx+'][code]" value="'+$('<div>').text(code).html()+'">');
            wrap.append('<input type="hidden" name="lavka_reports_opts[ops_exclude]['+idx+'][name]" value="'+$('<div>').text(name).html()+'">');
            wrap.append('<div class="lr-chip" data-code="'+code+'">'+name+'</div>');
        }

        $('#lr-clear-ops').on('click', function(){
        $('#lr-ops').empty();
        $('#lr-ops-selected').empty();
        });

        $('#lr-load-ops').on('click', function(){
        $.post(LavkaReports.ajax, { action:'lavka_reports_ref_ops', nonce:LavkaReports.nonce }, function(resp){
            if(!resp || !resp.success){ alert((resp&&resp.data&&resp.data.err)||'Error'); return; }
            var $sel = $('#lr-ops').empty();
            resp.data.items.forEach(function(i){
            $('<option>').val(i.code).text(i.code+' — '+i.name).appendTo($sel);
            });
        });
        });

        $('#lr-ops').on('dblclick change', function(){
        var opts = $(this).find('option:selected');
        opts.each(function(){
            var code = $(this).val();
            var name = $(this).text().split(' — ').slice(1).join(' — ');
            if ($('#lr-ops-selected .lr-chip[data-code="'+code+'"]').length) return;
            addOpHidden(code, name);
        });
        });
    function lr_collectSelectedWarehouses(){
        var ids = [];
        // 1) основное: скрытые инпуты (чипы справа)
        var $inputs = $('#lr-warehouses-selected input[name^="lavka_reports_opts[warehouses]"][name$="[id]"]');
        $inputs.each(function(){
            var v = $(this).val(); // MSSQL code: D01, D02…
            if (v) ids.push(v);
        });

        // 2) запасной путь: если чипов нет — берём выделение из левого списка
        if (ids.length === 0) {
            $('#lr-warehouses option:selected').each(function(){
            var v = $(this).val();
            if (v) ids.push(v);
            });
        }
        return ids;
    }
function lr_collectSelectedOps(){
  var arr = [];
  // 1) основное: скрытые инпуты (чипы справа)
  $('#lr-ops-selected input[name^="lavka_reports_opts[ops_exclude]"][name$="[code]"]').each(function(){
    var v = $(this).val();
    if(v) arr.push(v);
  });
  // 2) запасной путь: если чипов нет — берём выделение из левого списка
  if (arr.length === 0) {
    $('#lr-ops option:selected').each(function(){
      var v = $(this).val();
      if (v) arr.push(v);
    });
  }
  return arr;
}
function lr_toCSV(rows){
  var lines = ['"SKU","Title","TotalQty"'];
  rows.forEach(function(r){
    var sku = (r.sku||'').replace(/"/g,'""');
    var title = (r.title||'').replace(/"/g,'""');
    var qty = r.totalQty!=null ? String(r.totalQty) : '';
    lines.push('"' + sku + '","' + title + '",' + qty);
  });
  return lines.join('\n');
}

    $(document).on('click', '#lr-run-report', function(){
    var from = $('input[name="lavka_reports_opts[period_from]"]').val();
    var to   = $('input[name="lavka_reports_opts[period_to]"]').val();
    var warehouses = lr_collectSelectedWarehouses();
    var ops = lr_collectSelectedOps();

    console.log('[LAVR] warehouses(collected)=', warehouses, 'ops(collected)=', ops);

    $('#lr-report-status').text('Running...');
    $('#lr-report-table').hide().find('tbody').empty();
    $('#lr-export-csv').prop('disabled', true);

    var payload = {
        action: 'lavr_no_movement',
        nonce: LavkaReports.nonce,
        from: from,
        to: to,
        warehouses: warehouses,
        ops: ops
    };
    console.log('[LAVR] AJAX URL:', LavkaReports.ajax);
    console.log('[LAVR] Payload:', payload);

    $.ajax({
        url: LavkaReports.ajax,
        method: 'POST',
        data: payload,
        dataType: 'json',
    })
    .done(function(resp){
        console.log('[LAVR] Response:', resp);
        if(!resp || !resp.success){
        alert((resp && resp.data && (resp.data.err || resp.data.debug)) || LavkaReports.i18n.error);
        $('#lr-report-status').text('');
        return;
        }
        var items = resp.data.items || [];
        var $tb = $('#lr-report-table').show().find('tbody').empty();
        items.forEach(function(i){
        var tr = $('<tr>');
        $('<td>').text(i.sku||'').appendTo(tr);
        $('<td>').text(i.title||'').appendTo(tr);
        $('<td style="text-align:right;">').text(i.totalQty!=null? i.totalQty : '').appendTo(tr);
        $tb.append(tr);
        });
        $('#lr-report-status').text('Rows: ' + items.length + (resp.data.last ? '' : ' (more pages possible)'));
        var csv = lr_toCSV(items);
        var blob = new Blob([csv], {type:'text/csv;charset=utf-8;'});
        var url = URL.createObjectURL(blob);
        $('#lr-export-csv').prop('disabled', items.length===0).off('click').on('click', function(){
        var a = document.createElement('a');
        a.href = url;
        a.download = 'no-movement.csv';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        });
    })
    .fail(function(jqXHR, textStatus, errorThrown){
        console.error('[LAVR] AJAX FAIL:', textStatus, errorThrown, jqXHR && jqXHR.responseText);
        alert('AJAX fail: ' + textStatus + ' — ' + (errorThrown||''));
        $('#lr-report-status').text('');
    });
       });
})(jQuery);