/* assets/admin.js */
/* assets/admin.js */
(function(){
   const mapWrap = document.getElementById('lps-mapping-wrap');
   
  console.log('[LPS] admin.js loaded');
 
  if (!mapWrap) {
    console.log('[LPS] #lps-mapping-wrap not found on this page');
    return;
  }
  console.log('[LPS] mapping wrap found');
  const GLB = (window.LPS_ADMIN || {});
  const GLOBAL_AJAX_URL = GLB.ajaxUrl || window.ajaxurl;
  const GLOBAL_NONCE = GLB.nonce || '';

  async function postAjax(url, nonce, action, payload){
    const f = new FormData();
    f.append('action', action);
    f.append('_wpnonce', nonce);
    Object.entries(payload||{}).forEach(([k,v])=>{
      if (Array.isArray(v)) v.forEach(x=>f.append(k+'[]', x));
      else f.append(k, v);
    });

    const r = await fetch(url, { method:'POST', credentials:'same-origin', body:f });
    const ct = (r.headers.get('content-type') || '').toLowerCase();

    if (ct.includes('application/json')) {
      return r.json();
    } else {
      const raw = await r.text();
      throw new Error(`HTTP ${r.status} ${r.statusText}. Body: ${raw.slice(0,300)}`);
    }
  }

  // ===== Mapping =====
  if (mapWrap) {
    // Берём url/nonce c data-* (если есть), иначе из глобалей
    const AJAX_URL = mapWrap.dataset.ajax || GLOBAL_AJAX_URL;
    const NONCE    = mapWrap.dataset.nonce || GLOBAL_NONCE;

    const status = document.getElementById('lps-mapping-status');
    const saveBtn = document.getElementById('lps-mapping-save');

    // Инициализация: тянем контракты и текущий маппинг
    (async function init(){
      status.textContent = (window.LPS_I18N && LPS_I18N.loading) || 'Loading…';
      try{
        const [contractsRes, mappingRes] = await Promise.all([
          postAjax(AJAX_URL, NONCE, 'lps_get_contracts', {}),
          postAjax(AJAX_URL, NONCE, 'lps_get_mapping',  {})
        ]);

        if (!contractsRes?.success) throw new Error('contracts: ' + (contractsRes?.data?.error || 'unknown'));
        if (!mappingRes?.success)  throw new Error('mapping: '   + (mappingRes?.data?.error  || 'unknown'));

        const items = (contractsRes.data.items || []);
        const map   = (mappingRes.data.map || {});

        // Заполняем все <select class="lps-contract" data-lps-role="slug">
        document.querySelectorAll('select.lps-contract[data-lps-role]').forEach(sel=>{
          const role = sel.dataset.lpsRole;
          // очистка
          sel.innerHTML = '';
          const opt0 = document.createElement('option');
          opt0.value = '';
          opt0.textContent = '— Select contract —';
          sel.appendChild(opt0);

          items.forEach(c=>{
            const opt = document.createElement('option');
            opt.value = c.code || '';
            const label = c.code + (c.name && c.name !== c.code ? ` — ${c.name}` : '');
            opt.textContent = label;
            if ((map[role] || '') === opt.value) opt.selected = true;
            sel.appendChild(opt);
          });
        });

        status.textContent = '';
      } catch(e){
        console.error('Mapping init error:', e);
        status.textContent = (window.LPS_I18N && LPS_I18N.neterr) || 'Network error';
        status.title = e.message || String(e);
      }
    })();

    // Сохранение маппинга
    saveBtn && saveBtn.addEventListener('click', async ()=>{
      try{
        status.textContent = (window.LPS_I18N && LPS_I18N.saving) || 'Saving…';
        const map = {};
        document.querySelectorAll('select.lps-contract[data-lps-role]').forEach(sel=>{
          map[sel.dataset.lpsRole] = sel.value || '';
        });
        const res = await postAjax(AJAX_URL, NONCE, 'lps_save_mapping', { map });
        if (res?.success) {
          status.textContent = (window.LPS_I18N && LPS_I18N.saved) || 'Saved';
        } else {
          status.textContent = ((window.LPS_I18N && LPS_I18N.error) || 'Error:') + ' ' + (res?.data?.error || 'unknown');
        }
      } catch(e){
        console.error('Mapping save error:', e);
        status.textContent = (window.LPS_I18N && LPS_I18N.neterr) || 'Network error';
        status.title = e.message || String(e);
      }
    });
  }
})();