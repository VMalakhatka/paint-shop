// Node-only DOM/fetch boundary tests. No browser, site, API or financial writes.
const {readFileSync} = require('node:fs');
const {runInNewContext} = require('node:vm');
const assert = require('node:assert/strict');
const source = readFileSync(require('node:path').join(__dirname, '../assets/receipt-status.js'), 'utf8');
let checks = 0;
function fixture() {
    let nextId = 0;
    const timers = new Map();
    const content = {innerHTML: 'OLD', pending: true, setAttribute() {}, querySelector() { return this.pending ? {} : null; }};
    const button = {disabled: false, addEventListener(type, fn) { this.click = fn; }};
    const note = {textContent: ''};
    const panel = {dataset: {order: '123', nonce: 'fixture', url: '/admin-ajax.php', failure: 'STALE'},
        querySelector(s) {return ({'.pc-wfp-receipt-content': content, '.pc-wfp-receipt-refresh': button, '.pc-wfp-receipt-refresh-note': note})[s];}};
    const document = {hidden: false, querySelectorAll() {return [panel];}};
    const requests = [];
    let handler = async () => ({ok: true, json: async () => ({success: true, data: {html: 'NEW'}})});
    runInNewContext(source, {document, URLSearchParams, AbortController,
        fetch: (...args) => {requests.push(args); return handler(...args);},
        setTimeout: (fn, delay) => {const id = ++nextId; timers.set(id, {fn, delay}); return id;},
        clearTimeout: id => timers.delete(id)});
    return {document, content, button, note, requests, timers, setHandler: fn => handler = fn,
        async tick() {const entry = [...timers].find(([, v]) => v.delay === 15000); assert.ok(entry); timers.delete(entry[0]); await entry[1].fn();}};
}
function check(value) {assert.ok(value); checks++;}
(async () => {
    const f = fixture();
    check(f.requests.length === 0); // Render alone does not call any service.
    f.document.hidden = true;
    await f.tick(); check(f.requests.length === 0);
    f.document.hidden = false;
    await f.tick(); check(f.requests.length === 1 && f.content.innerHTML === 'NEW');
    const options = f.requests[0][1];
    check(options.body.get('action') === 'pc_wfp_receipt_status' && options.body.get('order_id') === '123');
    check(options.credentials === 'same-origin' && options.cache === 'no-store');
    f.setHandler(async () => {throw new Error('offline');});
    await f.tick(); check(f.content.innerHTML === 'NEW' && f.note.textContent === 'STALE' && !f.button.disabled);
    f.setHandler(async () => ({ok: false, json: async () => ({success: false})}));
    await f.tick(); check(f.content.innerHTML === 'NEW' && f.note.textContent === 'STALE');
    f.setHandler(async () => {f.content.pending = false; return {ok: true, json: async () => ({success: true, data: {html: 'DONE'}})};});
    await f.tick(); check(f.content.innerHTML === 'DONE' && f.timers.size === 0 && f.note.textContent === '');
    await f.button.click(); check(f.content.innerHTML === 'DONE'); // Refresh remains available, never order submit.
    const limited = fixture();
    for (let n = 0; n < 40; n++) await limited.tick();
    check(limited.requests.length === 40 && limited.timers.size === 0);
    await limited.button.click(); check(limited.requests.length === 41 && limited.timers.size === 1);
    const busy = fixture(); let resolve;
    busy.setHandler(() => new Promise(r => {resolve = r;}));
    const first = busy.button.click(); await busy.button.click();
    check(busy.requests.length === 1 && busy.button.disabled);
    resolve({ok: true, json: async () => ({success: true, data: {html: 'NEW'}})});
    await first;
    check(!busy.button.disabled);
    console.log(`Passed ${checks} receipt status JS checks.`);
})();
