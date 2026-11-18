/* ============================================================
   UIS Driver Scheduling & Management System
   assets/js/ui.js  –  Shared dialogs (DaisyUI <dialog class="modal">)

   Replaces the browser's native confirm()/alert()/prompt() boxes.

   JavaScript:
     UIS.confirm({ title, text, confirmText, cancelText, tone })   -> Promise<boolean>
     UIS.alert(message, { title, tone, buttonText })                -> Promise<void>
     UIS.askReason({ title, text, label, placeholder, confirmText, cancelText, tone, minLength })
                                                                    -> Promise<string|null>
     tone: 'primary' (green) | 'danger' (red) | 'warning' (amber)

   Markup (no JavaScript needed):
     <a href="logout.php"
        data-confirm="You will need to sign in again."
        data-confirm-title="Log out?"
        data-confirm-button="Log Out"
        data-confirm-tone="danger">Log out</a>
     Also works on <button type="submit"> inside a form.
   ============================================================ */
(function () {
    'use strict';

    var BUTTON = { primary: 'btn-primary', danger: 'btn-error', warning: 'btn-warning' };

    function toneOf(value) {
        return BUTTON[value] ? value : 'primary';
    }

    function el(tag, cls, text) {
        var e = document.createElement(tag);
        if (cls) { e.className = cls; }
        if (text) { e.textContent = text; }
        return e;
    }

    var supported = typeof window.HTMLDialogElement === 'function';

    /**
     * One dialog for every case. o: { title, text, confirmText, cancelText (null = no cancel), tone, input: { label, placeholder, minLength } }
     * Resolves to true/false, or to the typed text / null when o.input is set.
     */
    function openDialog(o) {
        return new Promise(function (resolve) {
            var dialog = el('dialog', 'modal');
            var box = el('div', 'modal-box max-w-md');
            var titleId = 'uisDialog' + Date.now();
            var h = el('h3', 'text-lg font-medium', o.title);
            h.id = titleId;
            dialog.setAttribute('aria-labelledby', titleId);
            box.appendChild(h);
            if (o.text) { box.appendChild(el('p', 'mt-2 text-sm text-base-content/70', o.text)); }

            var field = null, error = null;
            if (o.input) {
                var label = el('label', 'mt-4 mb-1 block text-sm font-medium', o.input.label);
                field = el('textarea', 'textarea w-full');
                field.id = titleId + 'Input';
                label.setAttribute('for', field.id);
                field.rows = 3;
                field.maxLength = 500;
                field.placeholder = o.input.placeholder || '';
                error = el('p', 'mt-1 hidden text-xs text-error');
                box.appendChild(label); box.appendChild(field); box.appendChild(error);
            }

            var actions = el('div', 'modal-action');
            var cancel = null;
            if (o.cancelText !== null) {
                cancel = el('button', 'btn btn-ghost', o.cancelText);
                cancel.type = 'button';
                actions.appendChild(cancel);
            }
            var ok = el('button', 'btn ' + BUTTON[o.tone], o.confirmText);
            ok.type = 'button';
            actions.appendChild(ok);
            box.appendChild(actions);
            dialog.appendChild(box);

            // Clicking outside the box closes it, like Cancel
            var backdrop = el('form', 'modal-backdrop');
            backdrop.method = 'dialog';
            var closeBtn = el('button', '', 'Close');
            backdrop.appendChild(closeBtn);
            dialog.appendChild(backdrop);

            var result = o.input ? null : false;
            function finish(value) { result = value; dialog.close(); }
            ok.addEventListener('click', function () {
                if (!o.input) { finish(true); return; }
                var v = field.value.trim();
                if (v.length < (o.input.minLength || 0)) {
                    error.textContent = 'Please write the reason (at least ' + o.input.minLength + ' characters).';
                    error.classList.remove('hidden');
                    field.classList.add('textarea-error');
                    field.focus();
                    return;
                }
                finish(v);
            });
            if (cancel) { cancel.addEventListener('click', function () { dialog.close(); }); }
            dialog.addEventListener('close', function () {
                dialog.remove();
                resolve(o.cancelText === null && !o.input ? undefined : result);
            });

            document.body.appendChild(dialog);
            dialog.showModal();
            (field || (o.tone === 'danger' && cancel) || ok).focus();
        });
    }

    function confirmDialog(options) {
        var o = Object.assign({ title: 'Are you sure?', text: '', confirmText: 'Yes, continue', cancelText: 'Cancel', tone: 'primary' }, options || {});
        o.tone = toneOf(o.tone);
        if (!supported) { return Promise.resolve(window.confirm(o.title + (o.text ? '\n\n' + o.text : ''))); }
        return openDialog(o);
    }

    function alertDialog(message, options) {
        var o = Object.assign({ title: 'Notice', tone: 'primary', buttonText: 'OK' }, options || {});
        if (!supported) { window.alert(message); return Promise.resolve(); }
        return openDialog({ title: o.title, text: message, confirmText: o.buttonText, cancelText: null, tone: toneOf(o.tone) });
    }

    // Asks for a written reason (cancel / reject need one). Resolves to the text, or null if dismissed.
    function askReason(options) {
        var o = Object.assign({ title: 'Reason required', text: '', label: 'Reason', placeholder: 'Write the reason here', confirmText: 'Continue', cancelText: 'Back', tone: 'danger', minLength: 3 }, options || {});
        o.tone = toneOf(o.tone);
        if (!supported) {
            var typed = window.prompt(o.title + (o.text ? '\n' + o.text : '') + '\n' + o.label);
            return Promise.resolve(typed && typed.trim().length >= o.minLength ? typed.trim() : null);
        }
        return openDialog({ title: o.title, text: o.text, confirmText: o.confirmText, cancelText: o.cancelText, tone: o.tone,
                            input: { label: o.label, placeholder: o.placeholder, minLength: o.minLength } });
    }

    window.UIS = window.UIS || {};
    window.UIS.askReason = askReason;
    window.UIS.confirm = confirmDialog;
    window.UIS.alert   = alertDialog;

    // ── Declarative confirmation: [data-confirm] ────────────────
    document.addEventListener('click', function (event) {
        var target = event.target.closest ? event.target.closest('[data-confirm]') : null;
        if (!target || target.getAttribute('data-confirm-passed') === '1') {
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        confirmDialog({
            title:       target.getAttribute('data-confirm-title')  || 'Are you sure?',
            text:        target.getAttribute('data-confirm')        || '',
            confirmText: target.getAttribute('data-confirm-button') || 'Yes, continue',
            cancelText:  target.getAttribute('data-confirm-cancel') || 'Cancel',
            tone:        target.getAttribute('data-confirm-tone')   || 'primary'
        }).then(function (confirmed) {
            if (!confirmed) {
                return;
            }
            if (target.tagName === 'A' && target.href) {
                if (target.target === '_blank') {
                    window.open(target.href, '_blank', 'noopener');
                } else {
                    window.location.href = target.href;
                }
            } else if (target.form && (target.type === 'submit' || target.tagName === 'BUTTON')) {
                if (typeof target.form.requestSubmit === 'function') {
                    target.form.requestSubmit(target);
                } else {
                    target.form.submit();
                }
            } else {
                target.setAttribute('data-confirm-passed', '1');
                target.click();
                target.removeAttribute('data-confirm-passed');
            }
        });
    }, true);
})();


/* ============================================================
   Global usability behaviours (HCI)
   - Visibility of status : submit buttons show "Please wait…" and
                            block double submission
   - Error recovery       : first invalid field / error is focused
   - Efficiency           : press "/" to jump to the page's search box
   - Error prevention     : warn before leaving a half-filled form
   - Accessibility        : skip link target, aria-current on the
                            active menu item
   ============================================================ */
(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    // ── Accessibility landmarks ─────────────────────────────────
    ready(function () {
        var main = document.querySelector('main');
        if (main) {
            if (!main.id) { main.id = 'mainContent'; }
            main.setAttribute('tabindex', '-1');
            var skip = document.querySelector('.skip-link');
            if (skip && main.id !== 'mainContent') { skip.setAttribute('href', '#' + main.id); }
        }
    });

    // ── Scrollable regions (wide tables, the vehicle board) can be reached and scrolled with the keyboard ──
    function labelScrollers() {
        document.querySelectorAll('main .overflow-x-auto').forEach(function (box) {
            var scrolls = box.scrollWidth > box.clientWidth + 1;
            if (box.closest('nav, [role]') && box.getAttribute('role') !== 'region') { return; }   // tab bars and other widgets keep their own role
            if (scrolls && !box.hasAttribute('tabindex')) {
                var card = box.closest('section, .card, .dash-card, .e-card');
                var heading = card ? card.querySelector('h1, h2, h3') : null;
                box.setAttribute('tabindex', '0');
                box.setAttribute('role', 'region');
                box.setAttribute('aria-label', (heading ? heading.textContent.trim() : 'Table') + ' (scrolls sideways)');
            } else if (!scrolls && box.getAttribute('role') === 'region') {
                box.removeAttribute('tabindex'); box.removeAttribute('role'); box.removeAttribute('aria-label');
            }
        });
    }
    ready(labelScrollers);
    window.addEventListener('resize', function () { clearTimeout(labelScrollers.t); labelScrollers.t = setTimeout(labelScrollers, 200); });

    // ── Inline errors are announced with their field ─────────────
    ready(function () {
        var n = 0;
        document.querySelectorAll('.input-error, .select-error, .textarea-error, .file-input-error').forEach(function (field) {
            var err = field.nextElementSibling;
            if (!err || !err.classList.contains('text-error')) { return; }
            if (!err.id) { err.id = 'fieldError' + (++n); }
            field.setAttribute('aria-invalid', 'true');
            var by = (field.getAttribute('aria-describedby') || '').split(' ').filter(Boolean);
            if (by.indexOf(err.id) === -1) { by.unshift(err.id); field.setAttribute('aria-describedby', by.join(' ')); }
        });
    });

    // ── Error recovery: focus the first problem on page load ────
    ready(function () {
        var invalid = document.querySelector('.input-error, .select-error, .textarea-error');
        if (invalid) {
            invalid.scrollIntoView({ block: 'center', behavior: 'smooth' });
            if (typeof invalid.focus === 'function') { invalid.focus({ preventScroll: true }); }
            return;
        }
        var alertBox = document.querySelector('.alert-error');
        if (alertBox) {
            alertBox.setAttribute('tabindex', '-1');
            alertBox.scrollIntoView({ block: 'center', behavior: 'smooth' });
            alertBox.focus({ preventScroll: true });
        }
    });

    // ── Feedback without clutter: success notices fade away ─────
    ready(function () {
        document.querySelectorAll('main > .alert-success').forEach(function (box) {
            setTimeout(function () { box.remove(); }, 7000);
        });
    });

    // ── Visibility of status: block double submit, show progress ─
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form || form.tagName !== 'FORM' || form.hasAttribute('data-no-loading')) { return; }
        if ((form.getAttribute('method') || 'get').toLowerCase() !== 'post') { return; }

        if (form.getAttribute('data-submitting') === '1') {
            event.preventDefault();               // second click while the first is still processing
            return;
        }
        if (event.defaultPrevented) { return; }   // handled by AJAX / failed validation

        form.setAttribute('data-submitting', '1');
        form.dirty = false;

        var btn = event.submitter || form.querySelector('[type="submit"]');
        if (!btn || btn.tagName !== 'BUTTON') { return; }

        // Disabling after the event keeps the button's own name/value in the request.
        setTimeout(function () {
            btn.setAttribute('data-original-html', btn.innerHTML);
            btn.style.minWidth = btn.offsetWidth + 'px';
            btn.disabled = true;
            btn.setAttribute('aria-busy', 'true');
            btn.innerHTML = '<span class="loading loading-spinner loading-sm" aria-hidden="true"></span>' +
                            (btn.getAttribute('data-loading-text') || 'Please wait…');
        }, 0);
    });

    // Back/forward cache: restore buttons when the page is shown again
    window.addEventListener('pageshow', function () {
        document.querySelectorAll('form[data-submitting="1"]').forEach(function (f) {
            f.removeAttribute('data-submitting');
        });
        document.querySelectorAll('button[data-original-html]').forEach(function (b) {
            b.innerHTML = b.getAttribute('data-original-html');
            b.removeAttribute('data-original-html');
            b.removeAttribute('aria-busy');
            b.disabled = false;
        });
    });

    // ── Efficiency: "/" focuses the search box ──────────────────
    document.addEventListener('keydown', function (event) {
        if (event.key !== '/' || event.ctrlKey || event.metaKey || event.altKey) { return; }
        var t = event.target, tag = t && t.tagName;
        if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || (t && t.isContentEditable)) { return; }
        var box = null;
        document.querySelectorAll('input[type="search"], input[placeholder*="earch"]')
            .forEach(function (el) { if (!box && el.offsetParent !== null) { box = el; } });
        if (box) { event.preventDefault(); box.focus(); box.select(); }
    });

    // ── Error prevention: warn before losing typed data ─────────
    ready(function () {
        document.querySelectorAll('form').forEach(function (form) {
            if ((form.getAttribute('method') || 'get').toLowerCase() !== 'post') { return; }
            if (form.hasAttribute('data-no-unsaved')) { return; }
            var fields = form.querySelectorAll('input:not([type="hidden"]):not([type="submit"]):not([type="button"]), select, textarea');
            if (fields.length < 4) { return; }            // short forms (chat, status) are not worth a prompt
            form.addEventListener('input',  function () { form.dirty = true; });
            form.addEventListener('change', function () { form.dirty = true; });
        });
    });
    function hasUnsavedForm() {
        var dirty = false;
        document.querySelectorAll('form').forEach(function (f) { if (f.dirty) { dirty = true; } });
        return dirty;
    }

    // In-site navigation (sidebar, breadcrumbs, Cancel…) gets the styled dialog.
    document.addEventListener('click', function (event) {
        if (event.defaultPrevented || event.button !== 0) { return; }
        if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) { return; }
        var link = event.target.closest ? event.target.closest('a[href]') : null;
        if (!link || link.hasAttribute('data-confirm') || link.hasAttribute('download')) { return; }
        var href = link.getAttribute('href') || '';
        if (href === '' || href.charAt(0) === '#' || /^(javascript|mailto|tel):/i.test(href)) { return; }
        if (link.target && link.target !== '_self') { return; }
        if (!hasUnsavedForm()) { return; }

        event.preventDefault();
        event.stopPropagation();
        window.UIS.confirm({
            title: 'Leave this page?',
            text: 'You have unsaved changes. If you leave now, the information you entered will be lost.',
            confirmText: 'Leave page',
            cancelText: 'Stay on page',
            tone: 'warning'
        }).then(function (ok) {
            if (!ok) { return; }
            document.querySelectorAll('form').forEach(function (f) { f.dirty = false; });
            window.location.href = link.href;
        });
    }, true);

    // Reload via keyboard (F5, Ctrl/Cmd+R) is also routed through the styled dialog.
    document.addEventListener('keydown', function (event) {
        var key = (event.key || '').toLowerCase();
        var isReload = key === 'f5' || ((event.ctrlKey || event.metaKey) && key === 'r');
        if (!isReload || event.altKey || !hasUnsavedForm()) { return; }
        event.preventDefault();
        var hard = event.shiftKey || event.ctrlKey && key === 'f5';
        window.UIS.confirm({
            title: 'Reload this page?',
            text: 'You have unsaved changes. If you reload now, the information you entered will be lost.',
            confirmText: 'Reload page',
            cancelText: 'Stay on page',
            tone: 'warning'
        }).then(function (ok) {
            if (!ok) { return; }
            document.querySelectorAll('form').forEach(function (f) { f.dirty = false; });
            window.location.reload(hard);
        });
    });

    // Fallback for closing the tab, refreshing or typing a URL: the browser
    // draws this dialog itself, so its look cannot be changed from the page.
    window.addEventListener('beforeunload', function (event) {
        if (hasUnsavedForm()) {
            event.preventDefault();
            event.returnValue = '';
        }
    });
})();
