/* Local-only rendering: recipient data is never sent to a QR image service. */
(function () {
    'use strict';
    const ns = 'http://www.w3.org/2000/svg';
    function element(name, attributes) {
        const node = document.createElementNS(ns, name);
        Object.entries(attributes).forEach(([key, value]) => node.setAttribute(key, value));
        return node;
    }
    function render(container) {
        try {
            const url = container.dataset.paymentQr;
            if (!/^https:\/\/qr\.bank\.gov\.ua\/[A-Za-z0-9_-]+$/.test(url) || !window.qrcodegen) return;
            const lib = window.qrcodegen;
            // NBU format 002: symbol version 10–17, error correction Q/M, hryvnia
            // in a white circle of the diameter specified in annex 1.
            const segments = lib.QrSegment.makeSegments(url);
            let qr;
            try {
                qr = lib.QrCode.encodeSegments(segments, lib.QrCode.Ecc.QUARTILE, 10, 17, -1, false);
            } catch (_) {
                qr = lib.QrCode.encodeSegments(segments, lib.QrCode.Ecc.MEDIUM, 10, 17, -1, false);
            }
            const border = 4;
            const size = qr.size + border * 2;
            const svg = element('svg', {viewBox: `0 0 ${size} ${size}`, 'aria-hidden': 'true', 'shape-rendering': 'crispEdges'});
            svg.appendChild(element('rect', {width: size, height: size, fill: '#fff'}));
            const paths = [];
            for (let y = 0; y < qr.size; y++) {
                for (let x = 0; x < qr.size; x++) {
                    if (qr.getModule(x, y)) paths.push(`M${x + border},${y + border}h1v1h-1z`);
                }
            }
            svg.appendChild(element('path', {d: paths.join(''), fill: '#000'}));
            const diameter = qr.version === 10 ? 17 : qr.version <= 12 ? 19 : qr.version === 13 ? 21 : qr.version <= 15 ? 23 : 25;
            svg.appendChild(element('circle', {cx: size / 2, cy: size / 2, r: diameter / 2, fill: '#fff', 'shape-rendering': 'geometricPrecision'}));
            const sign = element('text', {x: size / 2, y: size / 2, dy: '.35em', 'text-anchor': 'middle', 'font-family': 'Arial, sans-serif', 'font-size': diameter - 4, fill: '#000', 'shape-rendering': 'geometricPrecision'});
            sign.textContent = '\u20b4';
            svg.appendChild(sign);
            container.replaceChildren(svg);
        } catch (_) {
            // Keep the readable fallback and bank link; never hide the requisites.
        }
    }
    function init() { document.querySelectorAll('[data-payment-qr]').forEach(render); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
}());
