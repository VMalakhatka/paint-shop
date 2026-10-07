const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(require('node:path').join(__dirname, '../assets/accounting-price-campaign.js'), 'utf8');
const start = source.indexOf('  function renderWarnings(');
const end = source.indexOf('  function showWarehouseState(', start);
assert(start >= 0 && end > start);
const context = vm.createContext({
  t: { warningReport: 'Diagnostics', reviewHelp: 'Review the original job' },
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
