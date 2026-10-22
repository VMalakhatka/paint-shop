const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const code = fs.readFileSync(require('node:path').join(__dirname, '../assets/category-entry.js'), 'utf8');
const key = 'psu-category-entry';
const destination = 'https://example.test/custom-category/paints/';
const intent = {url: destination, at: Date.now()};

function page(options = {}) {
    const values = new Map(options.pending === undefined ? [] : [[key, JSON.stringify(options.pending)]]);
    const events = new Map();
    const clicks = [];
    const frames = [];
    const scrolls = [];
    const listen = (type, callback) => events.set(type, [...(events.get(type) || []), callback]);
    const window = {
        location: {href: options.url || destination}, scrollY: options.scrollY || 0,
        matchMedia: () => ({matches: (options.width || 390) <= 767}),
        addEventListener: listen,
        removeEventListener: (type, callback) => events.set(type, (events.get(type) || []).filter(item => item !== callback)),
        scrollTo: value => scrolls.push(value),
    };
    const toolbar = options.toolbar ? {getBoundingClientRect: () => ({bottom: 32})} : null;
    const document = {
        readyState: options.complete ? 'complete' : 'loading',
        addEventListener: (type, callback) => { if (type === 'click') clicks.push(callback); },
        querySelector: () => options.category === false ? null : {getBoundingClientRect: () => ({top: 700})},
        getElementById: () => toolbar,
    };
    const storage = {
        getItem: name => { if (options.blocked) throw Error('Blocked'); return values.get(name) || null; },
        removeItem: name => values.delete(name),
        setItem: (name, value) => { if (options.blocked) throw Error('Blocked'); values.set(name, value); },
    };
    vm.runInNewContext(code, {
        window, document, sessionStorage: storage, URL, Date, Number,
        performance: {getEntriesByType: () => options.noNavigation ? [] : [{type: options.type || 'navigate'}]},
        getComputedStyle: () => ({position: options.toolbar || 'static'}),
        requestAnimationFrame: callback => frames.push(callback),
    });
    return {
        values, scrolls,
        event(type, value = {}) { for (const callback of [...(events.get(type) || [])]) callback(value); },
        flush() { while (frames.length) frames.shift()(); },
        click(options = {}) {
            const link = {
                href: options.url || destination, target: options.target || '',
                matches: () => options.category !== false,
                hasAttribute: name => name === 'download' && !!options.download,
            };
            const event = {button: 0, ...options, target: {closest: () => link}};
            clicks.forEach(callback => callback(event));
        },
    };
}

let checks = 0;
const check = (condition, label) => { assert(condition, label); checks++; };
const source = page({url: 'https://example.test/'});
source.click();
check(JSON.parse(source.values.get(key)).url === destination, 'Category URL comes from the real clicked link, independent of permalink base');
const arrival = page({pending: intent});
arrival.event('pageshow'); arrival.flush();
check(arrival.scrolls.length === 1 && arrival.scrolls[0].top === 688, 'A selected category starts 12px below the viewport edge');
check(!arrival.values.has(key), 'Selection is consumed before scrolling');

for (const options of [
    {width: 768}, {width: 1280}, {type: 'reload'}, {type: 'back_forward'},
    {pending: null}, {pending: {...intent, url: 'https://example.test/other/'}},
    {pending: {...intent, at: Date.now() - 31000}}, {pending: {...intent, at: Date.now() + 60000}},
    {pending: {...intent, at: 'bad'}}, {url: destination + '#details'},
    {category: false}, {scrollY: 250}, {noNavigation: true}, {blocked: true},
]) {
    const subject = page({pending: intent, ...options});
    subject.event('pageshow'); subject.flush();
    check(subject.scrolls.length === 0, 'Native scroll retained: ' + JSON.stringify(options));
}
for (const type of ['pointerdown', 'touchstart', 'wheel', 'keydown']) {
    const subject = page({pending: intent}); subject.event(type); subject.event('pageshow'); subject.flush();
    check(subject.scrolls.length === 0, 'User input cancels a delayed move: ' + type);
}
const restored = page({pending: intent}); restored.event('pageshow', {persisted: true}); restored.flush();
check(restored.scrolls.length === 0, 'BFCache restoration is left to the browser');
const interruptedFrame = page({pending: intent}); interruptedFrame.event('pageshow'); interruptedFrame.event('wheel'); interruptedFrame.flush();
check(interruptedFrame.scrolls.length === 0, 'Input between page show and the next frame also cancels scrolling');
for (const options of [
    {ctrlKey: true}, {metaKey: true}, {shiftKey: true}, {altKey: true}, {button: 1},
    {target: '_blank'}, {download: true}, {defaultPrevented: true}, {category: false},
    {url: destination + '#details'}, {url: 'https://other.test/category/'},
]) {
    const subject = page({url: 'https://example.test/'}); subject.click(options);
    check(!subject.values.has(key), 'Unrelated or modified click does not request scrolling: ' + JSON.stringify(options));
}
const same = page(); same.click(); check(!same.values.has(key), 'The current category does not request scrolling');
const desktop = page({url: 'https://example.test/', width: 768}); desktop.click();
check(!desktop.values.has(key), 'Tablet selection does not request mobile scrolling');
const fixed = page({pending: intent, toolbar: 'fixed'}); fixed.event('pageshow'); fixed.flush();
check(fixed.scrolls[0].top === 656, 'Fixed admin toolbar is kept above the heading');
const absolute = page({pending: intent, toolbar: 'absolute'}); absolute.event('pageshow'); absolute.flush();
check(absolute.scrolls[0].top === 688, 'Scrolling phone toolbar does not leave an extra gap');
console.log(JSON.stringify({passed: checks}));
