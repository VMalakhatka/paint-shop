const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(require('node:path').join(__dirname, '../assets/accounting-price-campaign.js'), 'utf8');
const start = source.indexOf('  function negativeMovementDate(');
const end = source.indexOf('  function showWarehouseState(', start);
assert(start >= 0 && end > start);
const context = vm.createContext({
  t: { warningReport: 'Diagnostics', reviewHelp: 'Review the original job', sku: 'SKU', problemDate: 'Problem date', warehouse: 'Warehouse' },
  appendNegativeStockDiagnostic(cell, details) { cell.append({ tag: 'p', text: details.secretDetail }); },
  formatDateTime(value) { return value; },
  node(tag, className, text) {
    return { tag, className, text, children: [], open: false, events: {},
      append(...items) { this.children.push(...items); },
      addEventListener(name, fn) { this.events[name] = fn; } };
  }
});
vm.runInContext('let warningReportOpen=false;\n'+source.slice(start,end), context);
const first=vm.runInContext('renderWarnings([], false, false)', context);
assert.equal(first.tag,'details'); assert.equal(first.open,false);
assert.equal(first.children[0].tag,'summary');
first.open=true; first.events.toggle();
const refreshed=vm.runInContext('renderWarnings([{code:"PREVIOUS_WRITE_REQUIRES_REVIEW",message:"Review",details:{previousError:"Original lock error"}}], false, false)',context);
assert.equal(refreshed.open,true);
assert(JSON.stringify(refreshed).includes('Original lock error'));
refreshed.open=false; refreshed.events.toggle();
assert.equal(vm.runInContext('renderWarnings([], false, false)',context).open,false);
console.log('PASS: collapsed diagnostics, count, original error and state across polling');
const compact=vm.runInContext(`renderWarnings([{code:'NEGATIVE_CHRONOLOGICAL_STOCK',sku:'TEST-12',warehouseName:'Kyiv',recordedAt:'2026-10-08',details:{secretDetail:'Full movement details',operation:{documentDate:'2025-02-03T23:00:00Z'}}}],false,true,true)`,context);
assert.equal(compact.open,true);
const table=compact.children.find(n=>n.className==='lps-ap-table-scroll').children[0];
assert.deepEqual(table.children[0].children[0].children.map(n=>n.text),['SKU','Problem date','Warehouse']);
const cells=table.children[1].children[0].children;
assert.equal(cells.length,3);
assert.equal(cells[1].text,'03.02.2025');
assert.equal(cells[2].text,'Kyiv');
assert.equal(cells[0].children[0].tag,'details');
assert.equal(cells[0].children[0].open,false);
assert.equal(cells[0].children[0].children[0].text,'TEST-12');
assert(JSON.stringify(cells[0]).includes('Full movement details'));
assert.equal(vm.runInContext('negativeMovementDate({recordedAt:"2026-10-08"})',context),'—');
assert.equal(vm.runInContext('negativeMovementDate({problemDate:"2024-01-10"})',context),'10.01.2024');
assert.equal(vm.runInContext('negativeMovementDate({operationDate:"2023-11-09"})',context),'09.11.2023');
console.log('PASS: three-column negative report, collapsed details, movement date and missing-date fallback');
