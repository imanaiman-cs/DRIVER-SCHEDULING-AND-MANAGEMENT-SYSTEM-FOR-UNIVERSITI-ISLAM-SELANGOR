/* ============================================================
   Schedule forms: disable drivers and vehicles that are already
   booked in the chosen time slot, instead of warning afterwards.

   Needs on the page: #trip_date, #start_time, #end_time and the pickers
   #driver_id / #vehicle_id (plus .team-driver / .team-vehicle rows).
   Config (window.SCHEDULE_BUSY):
     url        endpoint (ajax/get_busy_resources.php)
     excludeId  schedule being edited (0 when creating)
     shareWith  schedule whose job may share a vehicle (0 if none)
   Other scripts can read window.UIS_BUSY = { drivers:Set, vehicles:Set }
   and listen for the "busy:updated" event.
   ============================================================ */
(function () {
    'use strict';
    var cfg = window.SCHEDULE_BUSY;
    if (!cfg) { return; }

    window.UIS_BUSY = { drivers: new Set(), vehicles: new Set() };
    var timer = null;
    var seq = 0;

    function el(id) { return document.getElementById(id); }

    function pickers(kind) {
        var list = [];
        var first = el(kind === 'driver' ? 'driver_id' : 'vehicle_id');
        if (first) { list.push(first); }
        document.querySelectorAll(kind === 'driver' ? '.team-driver' : '.team-vehicle').forEach(function (s) { list.push(s); });
        return list;
    }

    function apply(kind, busySet) {
        var cleared = [];
        pickers(kind).forEach(function (sel) {
            Array.prototype.forEach.call(sel.options, function (opt) {
                if (opt.value === '') { return; }
                if (opt.dataset.label === undefined) { opt.dataset.label = opt.textContent; }
                var busy = busySet.has(opt.value);
                opt.textContent = busy ? opt.dataset.label.trim() + '  (busy at this time)' : opt.dataset.label;
                opt.setAttribute('data-busy', busy ? '1' : '0');
                // Duplicates inside one job are greyed by the page itself; keep that when not busy
                if (busy) { opt.disabled = true; } else if (!opt.hasAttribute('data-dup')) { opt.disabled = false; }
            });
            var chosen = sel.options[sel.selectedIndex];
            if (chosen && chosen.value !== '' && chosen.getAttribute('data-busy') === '1') {
                cleared.push(chosen.dataset.label.split(' — ')[0].replace(/^★\s*(Recommended:\s*)?/, '').trim());
                sel.value = '';
                sel.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
        return cleared;
    }

    function notify(names, kind) {
        var box = el('busyNotice');
        if (!box) { return; }
        if (!names.length) { box.style.display = 'none'; box.textContent = ''; return; }
        box.textContent = (names.join(', ')) + (names.length > 1 ? ' are' : ' is') + ' already booked at this time, so the ' + kind + ' choice was cleared.';
        box.style.display = '';
    }

    function refresh() {
        var date = el('trip_date') && el('trip_date').value;
        var st   = el('start_time') && el('start_time').value;
        var en   = el('end_time') && el('end_time').value;
        var mine = ++seq;

        if (!date || !st || !en || en <= st) {
            window.UIS_BUSY.drivers = new Set();
            window.UIS_BUSY.vehicles = new Set();
            apply('driver', window.UIS_BUSY.drivers);
            apply('vehicle', window.UIS_BUSY.vehicles);
            document.dispatchEvent(new Event('busy:updated'));
            return;
        }

        var body = new URLSearchParams({
            trip_date: date, start_time: st, end_time: en,
            exclude_id: cfg.excludeId || 0, share_with: cfg.shareWith || 0
        });
        fetch(cfg.url, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (mine !== seq || !res || !res.success) { return; }
                window.UIS_BUSY.drivers  = new Set(res.driver_ids.map(String));
                window.UIS_BUSY.vehicles = new Set(res.vehicle_ids.map(String));
                var gone = apply('driver', window.UIS_BUSY.drivers);
                var goneV = apply('vehicle', window.UIS_BUSY.vehicles);
                notify(gone.length ? gone : goneV, gone.length ? 'driver' : 'vehicle');
                document.dispatchEvent(new Event('busy:updated'));
            })
            .catch(function () { /* the server still checks on save */ });
    }

    function schedule() { clearTimeout(timer); timer = setTimeout(refresh, 250); }

    ['trip_date', 'start_time', 'end_time'].forEach(function (id) {
        var e = el(id);
        if (e) { e.addEventListener('change', schedule); e.addEventListener('input', schedule); }
    });
    document.addEventListener('DOMContentLoaded', refresh);
    if (document.readyState !== 'loading') { refresh(); }
})();
