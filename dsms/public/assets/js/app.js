// DSMS shared JavaScript (vanilla JS only - no framework).
// Step 1: nothing is needed yet. Later steps add the confirm dialogs and the
// small helpers used by the request form and the calendar.
document.addEventListener('DOMContentLoaded', function () {
    // Any element with data-confirm asks before it runs (used for cancel / reject buttons).
    document.querySelectorAll('[data-confirm]').forEach(function (el) {
        el.addEventListener('click', function (event) {
            if (!window.confirm(el.getAttribute('data-confirm'))) {
                event.preventDefault();
            }
        });
    });
});
