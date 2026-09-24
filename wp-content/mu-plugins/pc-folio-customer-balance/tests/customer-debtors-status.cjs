const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../assets/customer-debtors.js'), 'utf8');
const helpers = source.slice(source.indexOf('    function snapshotLabel('), source.indexOf('    function snapshotIsReady('));
const render = source.slice(source.indexOf('    function renderSnapshot('), source.indexOf('    function ajaxRequest('));
function renderStatus(snapshot) {
    const context = {
        pcFolioDebtors: {today: '2026-09-24', labels: {snapshot: {
            interrupted: 'interrupted', interruptedMessage: 'restart available', building: 'updating',
            buildingWithActive: 'updating old report', active: 'ready', readyMessage: 'ready',
        }}},
        snapshotRoot: {dataset: {}}, snapshotState: {}, snapshotDate: {}, snapshotCompleted: {},
        snapshotTotal: {}, refreshButton: {}, currentActiveGenerationId: null,
        loadAfterReady: false, reportRendered: false,
        dateText: value => value, dateTimeText: value => value, number: Number,
        formatIndexed: text => text, setSnapshotMessage: () => {},
        scheduleSnapshotCheck: () => {}, stopSnapshotPolling: () => {},
    };
    context.setReportAvailability = enabled => {context.reportEnabled = enabled;};
    vm.createContext(context);
    vm.runInContext(helpers + render, context);
    context.renderSnapshot(snapshot);
    return context;
}
const activeSnapshot = {generationId: 45, asOfDate: '2026-09-24', totalClients: 1702};
for (const active of [activeSnapshot, null]) {
    const result = renderStatus({status: 'BUILDING', running: false, building: {generationId: 46}, activeSnapshot: active});
    assert.equal(result.snapshotState.textContent, 'interrupted');
    assert.equal(result.refreshButton.disabled, false);
    assert.equal(result.reportEnabled, !!active);
}
for (const running of [true, undefined]) {
    const result = renderStatus({status: 'BUILDING', running, building: {generationId: 47}, activeSnapshot});
    assert.equal(result.snapshotState.textContent, 'updating');
    assert.equal(result.refreshButton.disabled, true);
    assert.equal(result.reportEnabled, true);
}
const ready = renderStatus({status: 'ACTIVE', running: false, activeSnapshot});
assert.equal(ready.snapshotState.textContent, 'ready');
assert.equal(ready.refreshButton.disabled, false);
console.log('Debt snapshot UI: 5 scenarios passed');
