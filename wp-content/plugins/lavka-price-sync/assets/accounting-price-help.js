(() => {
  'use strict';
  const root = document.getElementById('lps-accounting-prices');
  const config = window.lpsAccountingHelp;
  if (!root || !config) return;
  const mapping = [
    ['h1, .lps-ap-safety, .lps-ap-toolbar', 'start'],
    ['[data-lps-ap-tab="single"], [data-lps-ap-panel="single"]>h2, #lps-ap-single-preview, #lps-ap-single-apply', 'single'],
    ['[data-lps-ap-tab="full"], [data-lps-ap-panel="full"]>h2, #lps-ap-full-preview, #lps-ap-full-apply', 'warehouse'],
    ['[data-lps-ap-tab="campaign"], [data-lps-ap-panel="campaign"]>h2, #lps-ap-campaign-start, #lps-ap-campaign-stop, #lps-ap-cron-heading, .lps-ap-cron-form button[type="submit"]', 'campaign'],
    ['#lps-ap-warehouse-overview-heading, #lps-ap-warehouse-overview-refresh', 'overview'],
    ['.lps-ap-state-section>h3, .lps-ap-count-link', 'states'],
    ['.lps-ap-negative-explanation, .lps-ap-warehouse-diagnostics>h3, .lps-ap-warehouse-overview-actions, .lps-ap-row-actions', 'negative'],
    ['.lps-ap-persistent-diagnostics>h3, .lps-ap-diagnostic-filters button[type="submit"], a[href*="accounting_price_diagnostic_export"], a[href*="accounting_price_snapshot_report_export"], a[href*="accounting_price_campaign_export"]', 'excel'],
    ['.lps-ap-diagnostic-table th:last-child', 'columns'],
    ['#lps-ap-single-notice, #lps-ap-full-notice, #lps-ap-campaign-notice', 'errors']
  ];
  const anchors = new WeakMap();
  function scan() {
    for (const [selector, anchor] of mapping) for (const element of root.querySelectorAll(selector)) {
      // More specific entries override generic heading entries.
      let link = anchors.get(element);
      if (!link || !link.isConnected) {
        link = document.createElement('a');
        link.className = 'lps-ap-help-link';
        link.target = '_blank'; link.rel = 'noopener noreferrer';
        link.textContent = config.label; link.title = config.title;
        if (element.matches('[data-lps-ap-tab]')) {
          const group = document.createElement('span'); group.className = 'lps-ap-help-tab';
          element.before(group); group.append(element, link);
        } else if (element.matches('th, .lps-ap-row-actions, .lps-ap-warehouse-overview-actions, .lps-ap-result-notice')) {
          element.append(link);
        } else element.after(link);
        anchors.set(element, link);
      }
      const url = config.url + anchor;
      if (link.getAttribute('href') !== url) link.setAttribute('href', url);
    }
  }
  let queued = false;
  const observer = new MutationObserver(() => {
    if (queued) return;
    queued = true; queueMicrotask(() => { queued = false; observer.disconnect(); scan(); observer.observe(root, {childList:true,subtree:true}); });
  });
  scan(); observer.observe(root, {childList:true,subtree:true});
})();
