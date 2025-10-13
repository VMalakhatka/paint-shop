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