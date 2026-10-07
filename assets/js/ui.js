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
