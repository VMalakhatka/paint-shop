(function(){
  const i18n = (window.LavkaReports && window.LavkaReports.i18n) || {};

  function text(key, fallback) {
    return i18n[key] || fallback;
  }

  async function loadData(params) {
    const form = new FormData();
    form.append('action','lavka_reports_data');
    form.append('_wpnonce', LavkaReports.nonce);
    form.append('supplier', params.supplier);
    form.append('stockId', params.stockId);
    const resp = await fetch(LavkaReports.ajaxUrl, { method:'POST', credentials:'same-origin', body: form });
    return await resp.json();
  }

  function renderTable(rows) {
    const tb = document.querySelector('#reportTable tbody');
    if (!tb) return;

    tb.textContent = '';

    if (!rows.length) {
      const tr = document.createElement('tr');
      const td = document.createElement('td');
      td.colSpan = 4;
      td.textContent = text('noData', 'No data');
      tr.appendChild(td);
      tb.appendChild(tr);
      return;
    }

    rows.forEach(r => {
      const tr = document.createElement('tr');
      const values = [
        r.sku ?? '',
        r.name ?? '',
        r.qty ?? '',
        r.price ?? ''
      ];

      values.forEach((value, index) => {
        const td = document.createElement('td');
        td.textContent = value.toString();
        if (index >= 2) td.style.textAlign = 'right';
        tr.appendChild(td);
      });

      tb.appendChild(tr);
    });
  }

  function renderChart(labels, data) {
    const ctx = document.getElementById('salesChart').getContext('2d');
    if (window.__lavkaChart) window.__lavkaChart.destroy();
    window.__lavkaChart = new Chart(ctx, {
      type: 'bar',
      data: {
        labels,
        datasets: [{ label: text('stockLabel', 'Stock'), data }]
      },
      options: {
        responsive: true,
        plugins: { legend: { display: true } },
        scales: { y: { beginAtZero: true } }
      }
    });
  }

  async function init() {
    const form = document.getElementById('filters');
    if (!form) return;

    async function run(e){
      if (e) e.preventDefault();
      const supplier = form.supplier.value.trim();
      const stockId  = form.stockId.value.trim();
      const tbody = document.querySelector('#reportTable tbody');
      if (tbody) {
        tbody.textContent = '';
        const tr = document.createElement('tr');
        const td = document.createElement('td');
        td.colSpan = 4;
        td.textContent = text('loading', 'Loading...');
        tr.appendChild(td);
        tbody.appendChild(tr);
      }

      try {
        const json = await loadData({supplier, stockId});
        if (!json || !json.success) {
          renderTable([]);
          return;
        }
        const data = json.data || {};
        const preview = Array.isArray(data.preview) ? data.preview : [];

        renderTable(preview);

        // Топ-20 по остатку
        const top = preview
          .map(x => ({ label: (x.sku || '').toString(), qty: Number(x.qty || 0) }))
          .sort((a,b)=>b.qty-a.qty)
          .slice(0,20);

        renderChart(top.map(x=>x.label), top.map(x=>x.qty));
      } catch (e) {
        console.error(e);
        renderTable([]);
      }
    }

    form.addEventListener('submit', run);
    run();
  }
  document.addEventListener('DOMContentLoaded', init);
})();
