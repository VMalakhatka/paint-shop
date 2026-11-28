(function (w) {
    'use strict';
    if (!w.lavkaAjaxJsonEndpoint || w.lavkaAttachAjaxJson) return;
    const endpoint = new URL(w.lavkaAjaxJsonEndpoint, w.location.href);
    const cartUrls = new Set((w.lavkaAjaxJsonCartEndpoints || []).filter(Boolean)
        .map(url => new URL(url, w.location.href).href));
    const notice = '<!-- GetPageSpeed unlicensed notice -->\n' +
        '<div id="gps-license-footer" style="position:fixed;bottom:0;left:0;right:0;z-index:2147483647;box-sizing:border-box;width:100%;background:#1f2937;color:#e5e7eb;font:13px/1.5 Tahoma,Verdana,Arial,sans-serif;text-align:center;padding:6px 12px;opacity:.96">Patched PHP for CentOS 7 by GetPageSpeed - free for personal and evaluation use. <a href="https://www.getpagespeed.com/repo-subscribe#ultra-annual" style="color:#7dd3fc;text-decoration:underline">Commercial use requires a subscription</a></div>';

    function isAjax(url) {
        if (!url) return false;
        try {
            const target = new URL(url, w.location.href);
            return target.origin === w.location.origin &&
                (target.pathname === endpoint.pathname || cartUrls.has(target.href));
        } catch (_) { return false; }
    }
    function isJson(type) {
        return /^application\/json\s*(?:;|$)/i.test(type || '');
    }
    function jsonPart(raw) {
        const index = raw.lastIndexOf(notice);
        // Accept only this exact trailer on a new line, followed by JSON whitespace.
        if (index > 0 && raw[index - 1] === '\n' && /^[\t\n\r ]*$/.test(raw.slice(index + notice.length))) {
            return raw.slice(0, index);
        }
        return raw;
    }

    if (w.Response && typeof w.Response.prototype.json === 'function') {
        const nativeJson = w.Response.prototype.json;
        w.Response.prototype.json = function () {
            if (!isAjax(this.url) || !isJson(this.headers.get('content-type'))) {
                return nativeJson.call(this);
            }
            return this.text().then(raw => JSON.parse(jsonPart(raw)));
        };
    }

    const attached = new WeakSet();
    w.lavkaAttachAjaxJson = function ($) {
        if (!$ || !$.ajaxPrefilter || attached.has($)) return;
        attached.add($);
        $.ajaxPrefilter(function (options, original, xhr) {
            if (!isAjax(options.url) || options.crossDomain) return;
            const convert = options.converters && options.converters['text json'];
            if (convert !== JSON.parse) return;
            options.converters['text json'] = raw =>
                convert(isJson(xhr.getResponseHeader('Content-Type')) ? jsonPart(raw) : raw);
        });
    };
    w.lavkaAttachAjaxJson(w.jQuery);
})(window);
