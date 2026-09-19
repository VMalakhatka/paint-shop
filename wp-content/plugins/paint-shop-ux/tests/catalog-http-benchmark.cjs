const fs = require('node:fs');
const assert = require('node:assert/strict');
const base = process.env.PSU_TEST_URL || 'http://paint.local';
assert.equal(new URL(base).hostname, 'paint.local', 'Local site only');
(async () => {
    const rows = [];
    let widget;
    for (const path of ['/', '/category/farbi-akrilovi/', '/my-account/', 'REST']) {
        const url = path === 'REST' ? `${base}/wp-json/paint-shop-ux/v1/categories?widget=${widget}&parent=0&locale=uk` : base + path;
        for (let run = 1; run <= 7; run++) {
            const start = performance.now();
            const response = await fetch(url, {headers:{'Accept-Encoding':'gzip, deflate, br'}, signal:AbortSignal.timeout(60000)});
            const ttfb = (performance.now() - start) / 1000;
            const body = await response.text();
            const total = (performance.now() - start) / 1000;
            assert.equal(response.status, 200);
            const menu = body.match(/<nav class="psu-category-menu"[\s\S]*?<\/nav>/)?.[0] || '';
            widget ||= menu.match(/data-widget="([^"]+)"/)?.[1];
            rows.push({path, run, ttfb, total, bytes:Buffer.byteLength(body), menuBytes:Buffer.byteLength(menu), links:(menu.match(/<a /g)||[]).length,
                contentEncoding:response.headers.get('content-encoding'), server:response.headers.get('server'), vary:response.headers.get('vary')});
        }
    }
    const stage = (process.argv[2] || 'current').replace(/[^a-z0-9-]/g, '');
    fs.writeFileSync(`/tmp/psu-http-${stage}.json`, JSON.stringify(rows, null, 2));
    for (const path of [...new Set(rows.map(r => r.path))]) {
        const batch = rows.filter(r=>r.path===path);
        const median = key => batch.map(r=>r[key]).sort((a,b)=>a-b)[3];
        console.log(JSON.stringify({path,ttfb:median('ttfb'),total:median('total'),bytes:median('bytes'),menuBytes:median('menuBytes'),links:median('links'),
            contentEncodings:[...new Set(batch.map(row=>row.contentEncoding))]}));
    }
})().catch(error=>{console.error(error);process.exitCode=1;});
