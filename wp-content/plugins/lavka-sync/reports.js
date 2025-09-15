(function(){
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
    tb.innerHTML = rows.map(r => (
      `<tr>
        <td>${(r.sku ?? '').toString().replace(/</g,'&lt;')}</td>
        <td>${(r.name ?? '').toString().replace(/</g,'&lt;')}</td>
        <td style="text-align:right;">${(r.qty ?? '').toString()}</td>
        <td style="text-align:right;">${(r.price ?? '').toString()}</td>
      </tr>`
    )).join('') || '<tr><td colspan="4">Нет данных</td></tr>';
  }

  function renderChart(labels, data) {
    const ctx = document.getElementById('salesChart').getContext('2d');
    if (window.__lavkaChart) window.__lavkaChart.destroy();
    window.__lavkaChart = new Chart(ctx, {
      type: 'bar',
      data: {
        labels,
        datasets: [{ label: 'Остаток', data }]
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
      if (tbody) tbody.innerHTML = '<tr><td colspan="4">Загрузка...</td></tr>';

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