(function (w) {
    'use strict';
    if (w.lavkaAttachAjaxJson) return;
    const notice = '<!-- GetPageSpeed unlicensed notice -->\n' +
        '<div id="gps-license-footer" style="position:fixed;bottom:0;left:0;right:0;z-index:2147483647;box-sizing:border-box;width:100%;background:#1f2937;color:#e5e7eb;font:13px/1.5 Tahoma,Verdana,Arial,sans-serif;text-align:center;padding:6px 12px;opacity:.96">Patched PHP for CentOS 7 by GetPageSpeed - free for personal and evaluation use. <a href="https://www.getpagespeed.com/repo-subscribe#ultra-annual" style="color:#7dd3fc;text-decoration:underline">Commercial use requires a subscription</a></div>';

    function isSiteUrl(url) {
        if (!url) return false;
        try {
            const target = new URL(url, w.location.href);
            return /^https?:$/.test(target.protocol) && target.origin === w.location.origin;
        } catch (_) { return false; }
    }
    function isJson(type) {
        return /^application\/(?:[\w.+-]+\+)?json\s*(?:;|$)/i.test(type || '');
    }
    function jsonPart(raw) {
        const index = raw.lastIndexOf(notice);
        // Accept only this exact trailer on a new line, followed by JSON whitespace.
        if (index > 0 && raw[index - 1] === '\n' && /^[\t\n\r ]*$/.test(raw.slice(index + notice.length))) {
            return raw.slice(0, index);
        }
        return raw;
    }

    function validJsonText(raw) {
        const part = jsonPart(raw);
        if (part !== raw) {
            try { JSON.parse(part); return part; } catch (_) { /* Keep malformed input for diagnostics. */ }
        }
        return raw;
    }

    if (w.Response && typeof w.Response.prototype.json === 'function') {
        const {json: nativeJson, text: nativeText} = w.Response.prototype;
        const applies = response => isSiteUrl(response.url) && isJson(response.headers.get('content-type'));
        w.Response.prototype.json = function () {
            if (!applies(this)) return nativeJson.call(this);
            return nativeText.call(this).then(raw => JSON.parse(jsonPart(raw)));
        };
        w.Response.prototype.text = function () {
            const body = nativeText.call(this);
            return applies(this) ? body.then(validJsonText) : body;
        };
    }

    const attached = new WeakSet();
    w.lavkaAttachAjaxJson = function ($) {
        if (!$ || !$.ajaxPrefilter || attached.has($)) return;
        attached.add($);
        $.ajaxPrefilter(function (options, original, xhr) {
            if (options.crossDomain || !isSiteUrl(options.url) || typeof options.xhr !== 'function') return;
            const convert = options.converters && options.converters['text json'];
            if (convert !== JSON.parse) return;
            // jqXHR does not expose the final URL after redirects; use its native transport.
            const makeXhr = options.xhr;
            let transport;
            options.xhr = function () { return (transport = makeXhr.apply(this, arguments)); };
            options.converters['text json'] = raw =>
                convert(transport && isSiteUrl(transport.responseURL) && isJson(xhr.getResponseHeader('Content-Type')) ? jsonPart(raw) : raw);
        });
    };
    w.lavkaAttachAjaxJson(w.jQuery);
})(window);
