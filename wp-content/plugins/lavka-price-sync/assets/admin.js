/* assets/admin.js */
(function(){
  const wrap = document.getElementById('lps-mapping-wrap');
  if (!wrap) return;

  const AJAX_URL = wrap.dataset.ajax || (window.LPS_ADMIN && LPS_ADMIN.ajaxUrl) || window.ajaxurl;
  const NONCE    = wrap.dataset.nonce || (window.LPS_ADMIN && LPS_ADMIN.nonce) || '';
  const status   = document.getElementById('lps-mapping-status');
  const btnSave  = document.getElementById('lps-mapping-save');

  console.log('[LPS] Mapping init. AJAX_URL=', AJAX_URL, ' NONCE=', NONCE);

  async function post(action, payload){
    const f = new FormData();
    f.append('action', action);
    f.append('_wpnonce', NONCE);
    if (payload && payload.map && typeof payload.map === 'object') {
      // отправляем как map[slug]=code
      Object.entries(payload.map).forEach(([role, code])=>{
        f.append(`map[${role}]`, code);
      });
    } else if (payload) {
      Object.entries(payload).forEach(([k,v])=>{
        if (Array.isArray(v)) v.forEach(x=>f.append(k+'[]', x));
        else f.append(k, v);
      });
    }

    console.log('[LPS] POST', action, 'payload=', payload);
    const r = await fetch(AJAX_URL, {method:'POST', credentials:'same-origin', body:f});
    const ct = (r.headers.get('content-type')||'').toLowerCase();
    if (!ct.includes('application/json')) {
      const raw = await r.text();
      console.error('[LPS] non-JSON response', r.status, r.statusText, raw);
      throw new Error(`HTTP ${r.status} ${r.statusText}`);
    }
    const j = await r.json();
    console.log('[LPS] response', action, j);
    return j;
  }

  // Инициализация — тянем контракты и сохранённый маппинг
  (async function init(){
    try{
      status.textContent = (window.LPS_I18N && LPS_I18N.loading) || 'Loading…';
      const [ctr, mp] = await Promise.all([
        post('lps_get_contracts', {}),
        post('lps_get_mapping',  {})
      ]);
      if (!ctr?.success) throw new Error('contracts: ' + (ctr?.data?.error || 'unknown'));
      if (!mp?.success)  throw new Error('mapping: '   + (mp?.data?.error  || 'unknown'));

      const items = Array.isArray(ctr.data.items) ? ctr.data.items : [];
      const map   = (mp.data && mp.data.map) || {};

      // Заполняем <select class="lps-contract" data-lps-role="slug">
      document.querySelectorAll('select.lps-contract[data-lps-role]').forEach(sel=>{
        const role = sel.dataset.lpsRole;
        sel.innerHTML = '';
        const opt0 = document.createElement('option');
        opt0.value = '';
        opt0.textContent = '— Select contract —';
        sel.appendChild(opt0);

        items.forEach(c=>{
          const v = c.code || '';
          const label = c.code + (c.name && c.name !== c.code ? ` — ${c.name}` : '');
          const opt = document.createElement('option');
          opt.value = v;
          opt.textContent = label;
          sel.appendChild(opt);
        });

        // автоселект сохранённого
        if (map[role]) sel.value = map[role];
      });

      status.textContent = '';
    } catch(e){
      console.error('[LPS] Mapping init error:', e);
      status.textContent = (window.LPS_I18N && LPS_I18N.neterr) || 'Network error';
      status.title = e.message || String(e);
    }
  })();

  // Сохранение
  btnSave?.addEventListener('click', async ()=>{
    try{
      status.textContent = (window.LPS_I18N && LPS_I18N.saving) || 'Saving…';
      const map = {};
      document.querySelectorAll('select.lps-contract[data-lps-role]').forEach(sel=>{
        const role = sel.dataset.lpsRole;
        const val  = sel.value || '';
        // можно сохранять и пустые? — нет, сервер отфильтрует, но отправим как есть
        map[role] = val;
      });

      const j = await post('lps_save_mapping', { map });
      if (j?.success) {
        status.textContent = (window.LPS_I18N && LPS_I18N.saved) || 'Saved';

        // Дополнительно сразу проставим то, что сервер реально принял:
        const saved = (j.data && j.data.saved) || {};
        document.querySelectorAll('select.lps-contract[data-lps-role]').forEach(sel=>{
          const role = sel.dataset.lpsRole;
          if (saved[role]) sel.value = saved[role];
        });
      } else {
        status.textContent = ((window.LPS_I18N && LPS_I18N.error) || 'Error:') + ' ' + (j?.data?.error || 'unknown');
      }
    } catch(e){
      console.error('[LPS] Mapping save error:', e);
      status.textContent = (window.LPS_I18N && LPS_I18N.neterr) || 'Network error';
      status.title = e.message || String(e);
    }
  });
})();

/* ===== Run page (manual sync) ===== */
(function(){
  const btnListed = document.getElementById('lps-sync-listed');
  const btnAll    = document.getElementById('lps-sync-all');
  if (!btnListed && !btnAll) return;

  // Общий AJAX URL — берём из кнопок, затем из LPS_ADMIN, затем window.ajaxurl
  const AJAX_URL =
    (btnListed && btnListed.dataset.url) ||
    (btnAll    && btnAll.dataset.url)    ||
    (window.LPS_ADMIN && LPS_ADMIN.ajaxUrl) ||
    window.ajaxurl;

  // Универсальная обёртка POST (передаём nonce ТРЕТЬИМ аргументом!)
  async function postAjax(action, payload, nonce){
    const f = new FormData();
    f.append('action', action);
    if (nonce) f.append('_wpnonce', nonce);
    Object.entries(payload || {}).forEach(([k,v])=>{
      if (Array.isArray(v)) v.forEach(x=>f.append(k+'[]', x));
      else f.append(k, v);
    });
    const r  = await fetch(AJAX_URL, { method:'POST', credentials:'same-origin', body:f });
    const ct = (r.headers.get('content-type')||'').toLowerCase();
    const body = ct.includes('application/json') ? await r.json() : await r.text();

    if (!ct.includes('application/json')) {
      throw new Error(`HTTP ${r.status} ${r.statusText}. Body: ${String(body).slice(0,300)}`);
    }
    if (!body || body.success !== true) {
      const msg = (body && body.data && (body.data.error || body.data.message)) || 'Server error';
      throw new Error(msg);
    }
    return body.data;
  }

  // Рендер небольшой таблицы результатов (пригодится для списка)
  function renderListedTable(boxEl, data) {
    const items = Array.isArray(data.items) ? data.items
                : Array.isArray(data.results) ? data.results : [];

    // собрать роли из prices/role_prices
    const roleSet = new Set();
    items.forEach(it=>{
      const p = it.prices || it.role_prices || {};
      Object.keys(p||{}).forEach(k=>roleSet.add(k));
    });
    const roles = Array.from(roleSet);

    const thead = `
      <thead>
        <tr>
          <th>SKU</th>
          <th>Retail</th>
          ${roles.map(r=>`<th>${r}</th>`).join('')}
          <th>Found</th>
        </tr>
      </thead>`;

    const rows = items.map(it=>{
      const sku    = it.sku || '';
      const retail = (it.price ?? it.retail ?? '');
      const prices = it.prices || it.role_prices || {};
      const found  = (typeof it.found === 'boolean') ? it.found : true;
      return `
        <tr>
          <td><code>${sku}</code></td>
          <td>${retail !== '' ? String(retail) : ''}</td>
          ${roles.map(r=>{
            const v = (prices && r in prices) ? prices[r] : '';
            return `<td>${v !== '' ? String(v) : ''}</td>`;
          }).join('')}
          <td style="text-align:center;">${found ? '✓' : '—'}</td>
        </tr>`;
    }).join('');

    const missing = Array.isArray(data.missing) ? data.missing : [];
    const missingHtml = missing.length
      ? `<p style="margin-top:8px"><strong>Not found (${missing.length}):</strong> ${missing.map(s=>`<code>${s}</code>`).join(', ')}</p>`
      : '';

    boxEl.innerHTML = `
      <table class="widefat striped" style="max-width:1000px">
        ${thead}
        <tbody>${rows || `<tr><td colspan="${roles.length+3}">No data</td></tr>`}</tbody>
      </table>
      ${missingHtml}`;
  }

  const I18N = window.LPS_I18N || {};

  /* ---- Sync listed SKUs ---- */
  if (btnListed) {
    const status = document.getElementById('lps-listed-status');
    const ta     = document.getElementById('lps-skus');
    const box    = document.getElementById('lps-listed-box');

    btnListed.addEventListener('click', async ()=>{
      try{
        const raw = (ta?.value || '').trim();
        if (!raw) { status.textContent = I18N.enter_skus || 'Enter one or more SKUs'; return; }

        // Разделители: только \n \r ; | и запятая → превращаем в запятые, режем по запятой.
        // Пробелы внутри SKU сохраняются.
        const skus = Array.from(new Set(
          raw.replace(/[\r\n;|]+/g, ',')
             .split(',')
             .map(s => s.trim())
             .filter(Boolean)
        ));
        if (!skus.length) { status.textContent = I18N.enter_skus || 'Enter one or more SKUs'; return; }

        status.textContent = I18N.loading || 'Loading…';
        if (box) box.innerHTML = '';

        // ВАЖНО: у listed — свой nonce из data-nonce
        const data = await postAjax('lps_run_prices_listed', { skus }, btnListed.dataset.nonce);

        status.textContent =
          `${I18N.done||'Done'} — retail: ${data.updated_retail||0}, roles: ${data.updated_roles||0}, `+
          `${I18N.not_found||'Not found'}: ${data.not_found||0}`;

        if (box) renderListedTable(box, data);
      } catch(e){
        console.error('[LPS] listed error:', e);
        status.textContent = `${I18N.error||'Error'}: ${e.message||e}`;
      }
    });
  }

  /* ---- Sync ALL (paged) ---- */
  if (btnAll) {
    const status  = document.getElementById('lps-all-status');
    const batchEl = document.getElementById('lps-batch');

    btnAll.addEventListener('click', async ()=>{
      let page = 0, pages = 0;
      let totals = { retail:0, roles:0, nf:0 };
      const batch = Math.max(50, Math.min(2000, parseInt(batchEl?.value,10) || 500));
      const t0 = performance.now();

      try{
        status.textContent = (I18N.loading || 'Loading…') + ` (page 1)`;

        while (true) {
          // У ALL — свой nonce
          const data = await postAjax('lps_run_prices_all_page', { page, batch }, btnAll.dataset.nonce);

          pages = data.pages || pages;
          totals.retail += data.updated_retail || 0;
          totals.roles  += data.updated_roles  || 0;
          totals.nf     += data.not_found      || 0;

          page = (data.page || 0) + 1;

          if (page >= (data.pages || 0)) {
            const ms = Math.round(performance.now() - t0);
            status.textContent =
              `${I18N.done||'Done'}: pages ${data.pages||0}, retail ${totals.retail}, roles ${totals.roles}, ` +
              `${I18N.not_found||'Not found'} ${totals.nf} — ${ms} ms`;
            break;
          } else {
            status.textContent = `${I18N.page||'Page'} ${page+1} ${I18N.of||'of'} ${data.pages}`;
            await new Promise(r => setTimeout(r, 50)); // чуть отпустим сервер
          }
        }
      } catch(e){
        console.error('[LPS] ALL error:', e);
        status.textContent = `${I18N.error||'Error'}: ${e.message||e}`;
      }
    });
  }
})();

(function(){
  const btnListed = document.getElementById('lps-sync-listed');
  const boxListed = document.getElementById('lps-listed-box');
  const statusListed = document.getElementById('lps-listed-status');

  if (btnListed) {
    const AJAX_URL = btnListed.dataset.url || window.ajaxurl;
    const NONCE    = btnListed.dataset.nonce || '';

    async function postAjax(action, payload){
      const f = new FormData();
      f.append('action', action);
      f.append('_wpnonce', NONCE);
      Object.entries(payload||{}).forEach(([k,v])=>{
        if (Array.isArray(v)) v.forEach(x=>f.append(k+'[]', x));
        else f.append(k, v);
      });
      const r = await fetch(AJAX_URL, { method:'POST', credentials:'same-origin', body:f });
      const j = await r.json();
      if (!j || !j.success) throw new Error(j?.data?.error || 'Server error');
      return j.data;
    }

    btnListed.addEventListener('click', async ()=>{
      const ta = document.getElementById('lps-skus');
      const raw = (ta?.value || '');

      // ВАЖНО: заменяем \n и ; на запятые, а потом разделяем ТОЛЬКО по запятой
      const skus = Array.from(new Set(
        raw.replace(/[\r\n;|]+/g, ',')
           .split(',')
           .map(s => s.trim())
           .filter(Boolean)
      ));

      if (!skus.length) {
        statusListed.textContent = 'Нет артикулов';
        return;
      }

      statusListed.textContent = 'Синхронизирую…';
      boxListed.innerHTML = '';

      btnListed.disabled = true;
      try {
        const d = await postAjax('lps_run_prices_listed', { skus });
        // здесь отрисуй таблицу d.items так же, как у тебя сделано для ALL
        // (оставляю твой существующий рендер, он в зипе не включён)
        statusListed.textContent =
          `Готово — retail: ${d.updated_retail}, roles: ${d.updated_roles}, Not found: ${d.not_found}`;
      } catch(e){
        console.error(e);
        statusListed.textContent = 'Ошибка: ' + (e.message || e);
      }finally {
        btnListed.disabled = false;
      }
    });
  }
})();