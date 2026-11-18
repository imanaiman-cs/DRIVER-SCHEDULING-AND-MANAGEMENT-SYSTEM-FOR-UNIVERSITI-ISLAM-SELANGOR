/* ============================================================
   assets/js/i18n.js  –  Bahasa Melayu for text that JavaScript adds after the page loads
   (dialogs, the calendar, the Today card, live refresh). Loaded only when BM is chosen;
   the dictionary is lang/ms.php, printed into window.UIS_I18N by includes/i18n.php.
   ============================================================ */
(function () {
    'use strict';
    var D = window.UIS_I18N;
    if (!D) { return; }
    var exact = D.exact || {};
    var patterns = (D.patterns || []).map(function (p) { return [new RegExp(p[0], 'gu'), p[1]]; });
    var ATTRS = ['placeholder', 'title', 'aria-label', 'data-tip', 'data-confirm', 'data-confirm-title', 'data-confirm-button', 'data-loading-text', 'alt', 'data-label'];

    function translate(text) {
        if (Object.prototype.hasOwnProperty.call(exact, text)) { return exact[text]; }
        var m = text.match(/^(.*?)(\s*[*:])$/);
        if (m && Object.prototype.hasOwnProperty.call(exact, m[1])) { return exact[m[1]] + m[2]; }
        if (text.indexOf(' | ') > -1) { return text.split(' | ').map(translate).join(' | '); }
        var out = text;
        patterns.forEach(function (p) { p[0].lastIndex = 0; out = out.replace(p[0], p[1]); });
        return out;
    }
    window.uisT = translate;

    function raw(value) {
        var plain = value.replace(/\s+/g, ' ').trim();
        if (!plain || !/[A-Za-z]/.test(plain)) { return null; }
        var t = translate(plain);
        if (t === plain) { return null; }
        return value.match(/^\s*/)[0] + t + value.match(/\s*$/)[0];
    }

    function skip(el) { return !el || el.closest('script, style, textarea, [contenteditable], [data-no-translate]'); }

    function doText(node) {
        if (skip(node.parentElement)) { return; }
        var t = raw(node.nodeValue);
        if (t !== null) { node.nodeValue = t; }
    }
    function doAttrs(el) {
        if (skip(el)) { return; }
        ATTRS.forEach(function (a) {
            var v = el.getAttribute(a);
            if (v) { var t = raw(v); if (t !== null) { el.setAttribute(a, t); } }
        });
    }
    function doTree(root) {
        if (root.nodeType === 3) { doText(root); return; }
        if (root.nodeType !== 1) { return; }
        doAttrs(root);
        root.querySelectorAll('[' + ATTRS.join('],[') + ']').forEach(doAttrs);
        var w = document.createTreeWalker(root, NodeFilter.SHOW_TEXT), n;
        while ((n = w.nextNode())) { doText(n); }
    }

    new MutationObserver(function (list) {
        list.forEach(function (m) {
            if (m.type === 'childList') { m.addedNodes.forEach(doTree); }
            else if (m.type === 'characterData') { doText(m.target); }
            else if (m.type === 'attributes') { doAttrs(m.target); }
        });
    }).observe(document.documentElement, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ATTRS });

    // anything the page's own scripts wrote before this file ran
    doTree(document.body);
})();
