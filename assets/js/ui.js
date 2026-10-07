/* ============================================================
   UIS Driver Scheduling & Management System
   assets/js/ui.js  –  Shared popup dialogs

   Replaces the browser's native confirm()/alert() boxes with
   dialogs styled to match the system (built on SweetAlert2).

   JavaScript:
     UIS.confirm({ title, text, confirmText, cancelText, tone, icon })
         -> Promise<boolean>
     UIS.alert(message, { title, tone, buttonText })
         -> Promise<void>
     tone: 'primary' (green) | 'danger' (red) | 'warning' (amber)

   Markup (no JavaScript needed):
     <a href="logout.php"
        data-confirm="You will need to sign in again."
        data-confirm-title="Log out?"
        data-confirm-button="Log Out"
        data-confirm-tone="danger"
        data-confirm-icon="fa-right-from-bracket">Log out</a>
     Also works on <button type="submit"> inside a form.
   ============================================================ */
(function () {
    'use strict';

    var DEFAULT_ICON = {
        primary: 'fa-circle-question',
        danger:  'fa-triangle-exclamation',
        warning: 'fa-circle-exclamation'
    };

    function swalReady() {
        return typeof window.Swal !== 'undefined';
    }

    function toneOf(value) {
        return DEFAULT_ICON[value] ? value : 'primary';
    }

    function classes(tone) {
        return {
            popup:         'uis-swal',
            icon:          'uis-swal-icon uis-swal-icon--' + tone,
            confirmButton: 'uis-btn uis-btn--' + tone,
            cancelButton:  'uis-btn uis-btn--ghost',
            actions:       'uis-swal-actions'
        };
    }

    function confirmDialog(options) {
        var o = Object.assign({
            title:       'Are you sure?',
            text:        '',
            confirmText: 'Yes, continue',
            cancelText:  'Cancel',
            tone:        'primary',
            icon:        null
        }, options || {});
        o.tone = toneOf(o.tone);

        if (!swalReady()) {
            // Library failed to load (e.g. offline): fall back to the native box.
            return Promise.resolve(window.confirm(o.title + (o.text ? '\n\n' + o.text : '')));
        }

        var icon = o.icon || DEFAULT_ICON[o.tone];
        return window.Swal.fire({
            title:             o.title,
            text:              o.text,
            icon:              'question',
            iconHtml:          '<i class="fas ' + icon + '" aria-hidden="true"></i>',
            showCancelButton:  true,
            confirmButtonText: o.confirmText,
            cancelButtonText:  o.cancelText,
            reverseButtons:    true,
            focusCancel:       o.tone === 'danger',
            buttonsStyling:    false,
            customClass:       classes(o.tone)
        }).then(function (result) {
            return result.isConfirmed === true;
        });
    }

    function alertDialog(message, options) {
        var o = Object.assign({
            title:      'Notice',
            tone:       'primary',
            buttonText: 'OK',
            icon:       null
        }, options || {});
        o.tone = toneOf(o.tone);

        if (!swalReady()) {
            window.alert(message);
            return Promise.resolve();
        }

        var icon = o.icon || (o.tone === 'danger' ? 'fa-circle-xmark' : (o.tone === 'warning' ? DEFAULT_ICON.warning : 'fa-circle-info'));
        return window.Swal.fire({
            title:             o.title,
            text:              message,
            icon:              'info',
            iconHtml:          '<i class="fas ' + icon + '" aria-hidden="true"></i>',
            confirmButtonText: o.buttonText,
            buttonsStyling:    false,
            customClass:       classes(o.tone)
        }).then(function () { /* resolved when dismissed */ });
    }

    window.UIS = window.UIS || {};
    window.UIS.confirm = confirmDialog;
    window.UIS.alert   = alertDialog;

    // ── Declarative confirmation: [data-confirm] ────────────────
    document.addEventListener('click', function (event) {
        var el = event.target.closest ? event.target.closest('[data-confirm]') : null;
        if (!el || el.getAttribute('data-confirm-passed') === '1') {
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        confirmDialog({
            title:       el.getAttribute('data-confirm-title')  || 'Are you sure?',
            text:        el.getAttribute('data-confirm')        || '',
            confirmText: el.getAttribute('data-confirm-button') || 'Yes, continue',
            cancelText:  el.getAttribute('data-confirm-cancel') || 'Cancel',
            tone:        el.getAttribute('data-confirm-tone')   || 'primary',
            icon:        el.getAttribute('data-confirm-icon')
        }).then(function (confirmed) {
            if (!confirmed) {
                return;
            }
            if (el.tagName === 'A' && el.href) {
                if (el.target === '_blank') {
                    window.open(el.href, '_blank', 'noopener');
                } else {
                    window.location.href = el.href;
                }
            } else if (el.form && (el.type === 'submit' || el.tagName === 'BUTTON')) {
                if (typeof el.form.requestSubmit === 'function') {
                    el.form.requestSubmit(el);
                } else {
                    el.form.submit();
                }
            } else {
                el.setAttribute('data-confirm-passed', '1');
                el.click();
                el.removeAttribute('data-confirm-passed');
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
        var main = document.querySelector('main') || document.querySelector('.main-content');
        if (main) {
            if (!main.id) { main.id = 'mainContent'; }
            main.setAttribute('tabindex', '-1');
            var skip = document.querySelector('.skip-link');
            if (skip && main.id !== 'mainContent') { skip.setAttribute('href', '#' + main.id); }
        }
        document.querySelectorAll('.sidebar-item.active > .sidebar-link, .sidebar-sublink.active')
            .forEach(function (el) { el.setAttribute('aria-current', 'page'); });
    });

    // ── Error recovery: focus the first problem on page load ────
    ready(function () {
        var invalid = document.querySelector('.is-invalid');
        if (invalid) {
            invalid.scrollIntoView({ block: 'center', behavior: 'smooth' });
            if (typeof invalid.focus === 'function') { invalid.focus({ preventScroll: true }); }
            return;
        }
        var alertBox = document.querySelector('.alert-danger');
        if (alertBox) {
            alertBox.setAttribute('tabindex', '-1');
            alertBox.scrollIntoView({ block: 'center', behavior: 'smooth' });
            alertBox.focus({ preventScroll: true });
        }
    });

    // ── Feedback without clutter: success notices fade away ─────
    ready(function () {
        document.querySelectorAll('.alert-success.alert-dismissible').forEach(function (box) {
            setTimeout(function () {
                if (!document.body.contains(box)) { return; }
                if (window.bootstrap && window.bootstrap.Alert) {
                    window.bootstrap.Alert.getOrCreateInstance(box).close();
                } else {
                    box.style.display = 'none';
                }
            }, 7000);
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
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>' +
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
        document.querySelectorAll('input[type="search"], .dataTables_filter input, input[placeholder*="earch"]')
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
    window.addEventListener('beforeunload', function (event) {
        var dirty = false;
        document.querySelectorAll('form').forEach(function (f) { if (f.dirty) { dirty = true; } });
        if (dirty) {
            event.preventDefault();
            event.returnValue = '';
        }
    });
})();
