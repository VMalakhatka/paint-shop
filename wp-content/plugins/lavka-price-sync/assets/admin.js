/* assets/admin.js */
(function(){
  // Общий nonce и ajax url — положи их в data-атрибуты контейнеров или выдавай через wp_localize_script,
  // здесь читаем из глобальных переменных, если они есть.
  const AJAX_URL = window.ajaxurl || (window.LPS && LPS.ajaxUrl) || (window.wp && wp.ajax && wp.ajax.settings && wp.ajax.settings.url);
  const NONCE = window.LPS_ADMIN_NONCE || (window.LPS && LPS.nonce) || '';

  // Универсальный fetch с диагностикой
  async function post(action, payload){
    const f = new FormData();
    f.append('action', action);
    f.append('_wpnonce', NONCE);
    Object.entries(payload||{}).forEach(([k,v])=>{
      if (Array.isArray(v)) v.forEach(x=>f.append(k+'[]', x));
      else f.append(k, v);
    });

    const r = await fetch(AJAX_URL, { method:'POST', credentials:'same-origin', body:f });
    const ct = (r.headers.get('content-type')||'').toLowerCase();
    if (ct.includes('application/json')) {
      return r.json();
    } else {
      const raw = await r.text();
      throw new Error(`HTTP ${r.status} ${r.statusText}. Body: ${raw.slice(0,300)}`);
    }
  }

  // --- Mapping page wiring (если элементы есть) ---
  const mapWrap = document.getElementById('lps-mapping-wrap');
  if (mapWrap) {
    const rolesSel = document.getElementById('lps-roles');
    const contractsSel = document.getElementById('lps-contracts');
    const btnSave = document.getElementById('lps-mapping-save');
    const status = document.getElementById('lps-mapping-status');

    (async function init(){
      status.textContent = (window.LPS_I18N && LPS_I18N.loading) || 'Loading…';
      try{
        const [{data:mapRes}, {data:ctr}] = await Promise.all([
          post('lps_get_mapping', {}),
          post('lps_get_contracts', {})
        ]);
      }catch(e){
        // WP JSON success оболочка
      }
    })().catch(e=>{
      console.error(e);
      status.textContent = (window.LPS_I18N && LPS_I18N.neterr) || 'Network error';
    });

    btnSave && btnSave.addEventListener('click', async ()=>{
      try{
        status.textContent = (window.LPS_I18N && LPS_I18N.saving) || 'Saving…';
        const map = {};
        // собираем все <select data-role="slug">
        document.querySelectorAll('[data-lps-role]').forEach(sel=>{
          map[sel.dataset.lpsRole] = sel.value || '';
        });
        const j = await post('lps_save_mapping', {map});
                status.textContent = j?.success
          ? ((window.LPS_I18N && LPS_I18N.saved) || 'Saved')
          : (((window.LPS_I18N && LPS_I18N.error) || 'Error:') + ' ' + (j?.data?.error || 'unknown'));
      } catch (e) {
        console.error(e);
        status.textContent = (window.LPS_I18N && LPS_I18N.neterr) || 'Network error';
      }
    });

    // Инициализация таблицы маппинга
    (async function initFill(){
      status.textContent = (window.LPS_I18N && LPS_I18N.loading) || 'Loading…';
      try{
        const resMap = await post('lps_get_mapping', {});
        const resCtr = await post('lps_get_contracts', {});
        if (!resMap?.success || !resCtr?.success) {
          status.textContent = ((window.LPS_I18N && LPS_I18N.error) || 'Error:') + ' API';
          return;
        }

        const map = (resMap.data && resMap.data.map) || {};
        const roles = (resMap.data && resMap.data.roles) || [];
        const contracts = (resCtr.data && resCtr.data.items) || [];

        const tbody = document.getElementById('lps-mapping-body');
        if (!tbody) { status.textContent = 'No tbody'; return; }
        tbody.innerHTML = '';

        roles.forEach(r => {
          const tr  = document.createElement('tr');
          const td1 = document.createElement('td');
          const td2 = document.createElement('td');

          td1.innerHTML = `<strong>${r.name || r.slug}</strong><br><code>${r.slug}</code>`;

          const sel = document.createElement('select');
          sel.setAttribute('data-lps-role', r.slug);
          sel.className = 'lps-contract-select';

          const empty = document.createElement('option');
          empty.value = '';
          empty.textContent = '—';
          sel.appendChild(empty);

          (contracts || []).forEach(c => {
            const opt = document.createElement('option');
            opt.value = String(c.code || '');
            opt.textContent = `${c.code || ''} — ${c.name || ''}`.trim();
            if ((map[r.slug] || '') === opt.value) opt.selected = true;
            sel.appendChild(opt);
          });

          td2.appendChild(sel);
          tr.appendChild(td1);
          tr.appendChild(td2);
          tbody.appendChild(tr);
        });

        status.textContent = '';
      } catch(e){
        console.error(e);
        status.textContent = (window.LPS_I18N && LPS_I18N.neterr) || 'Network error';
      }
    })();
  }

  // --- Run page wiring ниже уже есть ---
})();