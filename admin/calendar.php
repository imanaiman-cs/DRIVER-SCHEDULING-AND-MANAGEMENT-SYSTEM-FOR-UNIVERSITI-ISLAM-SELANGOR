<?php
// ============================================================
// admin/calendar.php  -  Schedule calendar (SRS UC028)
// Request tasks, internal tasks and welfare duty together; day / week / month views;
// filter by driver or vehicle. Plain JavaScript (no CDN library), data from ajax/calendar_events.php.
// ============================================================
$current_page = 'calendar.php';
require_once '../config/database.php';
require_once '../includes/admin_page.php';
require_once '../includes/schedule_view.php';
requireAdmin();

$drivers  = $conn->query('SELECT driver_id, name FROM drivers ORDER BY name')->fetch_all(MYSQLI_ASSOC);
$vehicles = $conn->query('SELECT vehicle_id, plate_number, brand, model FROM vehicles ORDER BY plate_number')->fetch_all(MYSQLI_ASSOC);
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
adminPageStart('Schedule Calendar');
?>
<style>
    /* Trip = UIS green, internal task = grey (as on the dispatch board), welfare duty = UIS gold */
    .cal-bar { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; }
    .cal-title { font-weight: 500; font-size: 1.125rem; min-width: 11rem; text-align: center; }
    .cal-month { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); border-left: 1px solid var(--color-base-300); border-top: 1px solid var(--color-base-300); }
    .cal-month .cal-dow { padding: .375rem .25rem; font-size: .75rem; font-weight: 500; text-align: center; color: color-mix(in oklab, var(--color-base-content) 60%, transparent); border-right: 1px solid var(--color-base-300); border-bottom: 1px solid var(--color-base-300); }
    .cal-cell { min-height: 7rem; padding: .25rem; border-right: 1px solid var(--color-base-300); border-bottom: 1px solid var(--color-base-300); overflow: hidden; cursor: pointer; }
    .cal-cell:hover { background: var(--color-base-200); }
    .cal-cell.other { color: color-mix(in oklab, var(--color-base-content) 62%, transparent); }
    .cal-cell.today .cal-num { display: inline-grid; place-items: center; min-width: 1.5rem; height: 1.5rem; border-radius: 999px; background: var(--color-base-content); color: var(--color-base-100); }
    .cal-cell .cal-num { font-size: .8125rem; font-family: var(--font-mono); }
    .cal-chip { display: block; width: 100%; text-align: left; border: 0; border-radius: 4px; padding: 0 .375rem; margin-top: .25rem; font-size: .75rem; line-height: 1.25rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .cal-chip.trip, .cal-dots i.trip, .cal-key.trip { background: var(--color-primary); color: var(--color-primary-content); }
    .cal-chip.internal, .cal-dots i.internal, .cal-key.internal { background: var(--color-base-300); color: var(--color-base-content); }
    /* Welfare duty is on every day, so it stays a quiet tint; trips are the solid colour */
    .cal-chip.welfare, .cal-key.welfare { background: color-mix(in oklab, var(--color-secondary) 22%, var(--color-base-100)); color: var(--color-base-content); }
    .cal-dots i.welfare { background: var(--color-secondary); }
    .cal-more { font-size: .75rem; color: color-mix(in oklab, var(--color-base-content) 60%, transparent); margin-top: .125rem; }
    .cal-dots { display: none; flex-wrap: wrap; gap: 2px; margin-top: .25rem; }
    .cal-dots i { width: 8px; height: 8px; border-radius: 50%; display: inline-block; }
    .cal-week { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: .5rem; }
    .cal-daycol { border: 1px solid var(--color-base-300); border-radius: var(--radius-box); padding: .5rem; min-width: 0; }
    .cal-daycol.today { border-color: var(--color-base-content); }
    .cal-daycol h6 { font-size: .8125rem; font-weight: 500; margin-bottom: .5rem; }
    .cal-item { display: block; width: 100%; text-align: left; border: 0; border-left: 3px solid; background: var(--color-base-200); border-radius: 4px; padding: .375rem .5rem; margin-bottom: .375rem; font-size: .8125rem; }
    .cal-item:hover { background: var(--color-base-300); }
    .cal-item.trip { border-color: var(--color-primary); } .cal-item.internal { border-color: color-mix(in oklab, var(--color-base-content) 30%, transparent); } .cal-item.welfare { border-color: var(--color-secondary); }
    .cal-item .t { font-weight: 500; } .cal-item .s { color: color-mix(in oklab, var(--color-base-content) 60%, transparent); font-size: .75rem; }
    .cal-day .cal-item { font-size: .9375rem; padding: .625rem .75rem; }
    .cal-legend span { display: inline-flex; align-items: center; gap: .375rem; margin-right: 1rem; font-size: .8125rem; }
    .cal-key { width: 10px; height: 10px; border-radius: 2px; display: inline-block; }
    @media (max-width: 767.98px) {
        .cal-week { grid-template-columns: 1fr; }
        .cal-cell { min-height: 3.5rem; }
        .cal-cell .cal-chip, .cal-cell .cal-more { display: none; }
        .cal-cell .cal-dots { display: flex; }
        .cal-title { order: -1; flex-basis: 100%; }
    }
</style>

    <h1 class="text-2xl font-extrabold tracking-tight mb-1"><i class="fas fa-calendar-days me-2 text-[0.8em] text-primary"></i>Schedule Calendar</h1>
    <p class="text-base-content/60 text-sm mb-4">Trips (request tasks), internal tasks and welfare duty together. Click an item for its details.</p>

    <div class="card border border-base-300 bg-base-100 mb-4"><div class="card-body">
        <div class="cal-bar mb-2">
            <div class="join" role="group" aria-label="Move">
                <button class="btn btn-sm join-item" id="calPrev" aria-label="Previous"><i class="fas fa-chevron-left"></i></button>
                <button class="btn btn-sm join-item" id="calToday">Today</button>
                <button class="btn btn-sm join-item" id="calNext" aria-label="Next"><i class="fas fa-chevron-right"></i></button>
            </div>
            <div class="cal-title" id="calTitle" aria-live="polite"></div>
            <div class="join md:ms-auto" role="group" aria-label="View">
                <button class="btn btn-sm join-item" data-view="day">Day</button>
                <button class="btn btn-sm join-item" data-view="week">Week</button>
                <button class="btn btn-sm join-item btn-active" data-view="month">Month</button>
            </div>
        </div>
        <div class="grid-12 grid grid-cols-12 gap-2">
            <div class="col-span-6 md:col-span-3"><label class="sr-only" for="calDriver">Driver</label>
                <select class="select w-full select-sm" id="calDriver"><option value="">All drivers</option>
                    <?php foreach ($drivers as $d): ?><option value="<?= (int)$d['driver_id'] ?>"><?= $h($d['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-span-6 md:col-span-3"><label class="sr-only" for="calVehicle">Vehicle</label>
                <select class="select w-full select-sm" id="calVehicle"><option value="">All vehicles</option>
                    <?php foreach ($vehicles as $v): ?><option value="<?= (int)$v['vehicle_id'] ?>"><?= $h(vehicleText($v['plate_number'], $v['brand'], $v['model'])) ?></option><?php endforeach; ?></select></div>
            <div class="col-span-12 md:col-span-6 cal-legend flex items-center">
                <span><i class="cal-key trip"></i>Trip</span><span><i class="cal-key internal"></i>Internal task</span><span><i class="cal-key welfare"></i>Welfare duty</span>
            </div>
        </div>
    </div></div>

    <div id="calBody" aria-busy="false"></div>
    <div id="calMsg" class="text-base-content/60 text-sm mt-2"></div>

    <dialog class="modal" id="calModal" aria-labelledby="calModalTitle">
        <div class="modal-box">
            <h2 class="text-lg font-medium" id="calModalTitle"></h2>
            <dl class="mt-4 grid grid-cols-[9rem_1fr] gap-x-4 gap-y-2 text-sm" id="calModalBody"></dl>
            <form method="dialog" class="modal-action">
                <a class="btn btn-sm" id="calSheet" target="_blank" rel="noopener"><i class="fas fa-print"></i>Task sheet</a>
                <button class="btn btn-sm btn-primary">Close</button>
            </form>
        </div>
        <form method="dialog" class="modal-backdrop"><button>Close</button></form>
    </dialog>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var body = document.getElementById('calBody'), titleEl = document.getElementById('calTitle'), msg = document.getElementById('calMsg');
    var view = 'month', cursor = new Date(); cursor.setHours(0, 0, 0, 0);
    var events = [], seq = 0;
    var MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    var DAYS = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];

    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function ymd(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
    function addDays(d, n) { var x = new Date(d.getFullYear(), d.getMonth(), d.getDate() + n); return x; }
    function weekStart(d) { return addDays(d, -((d.getDay() + 6) % 7)); }          // Monday
    function parse(iso) { var p = iso.split(/[-T:]/); return new Date(+p[0], +p[1] - 1, +p[2], +p[3], +p[4], +p[5] || 0); }
    function hhmm(iso) { return iso.substr(11, 5); }
    function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text !== undefined) e.textContent = text; return e; }

    function range() {
        if (view === 'day') { return [cursor, addDays(cursor, 1)]; }
        if (view === 'week') { var w = weekStart(cursor); return [w, addDays(w, 7)]; }
        var first = new Date(cursor.getFullYear(), cursor.getMonth(), 1), s = weekStart(first);
        return [s, addDays(s, 42)];
    }
    function title() {
        if (view === 'month') { return MONTHS[cursor.getMonth()] + ' ' + cursor.getFullYear(); }
        if (view === 'day') { return DAYS[(cursor.getDay() + 6) % 7] + ', ' + cursor.getDate() + ' ' + MONTHS[cursor.getMonth()] + ' ' + cursor.getFullYear(); }
        var w = weekStart(cursor), e = addDays(w, 6);
        return w.getDate() + ' ' + MONTHS[w.getMonth()].substr(0, 3) + ' - ' + e.getDate() + ' ' + MONTHS[e.getMonth()].substr(0, 3) + ' ' + e.getFullYear();
    }

    function eventsOn(d) {
        var day = ymd(d), out = [];
        events.forEach(function (ev) {
            var last = new Date(parse(ev.end).getTime() - 1000);
            if (ev.start.substr(0, 10) <= day && ymd(last) >= day) { out.push(ev); }
        });
        out.sort(function (a, b) { return (a.kind === 'welfare' ? 0 : 1) - (b.kind === 'welfare' ? 0 : 1) || (a.start < b.start ? -1 : a.start > b.start ? 1 : 0); });
        return out;
    }
    function shortLabel(ev) {
        return (ev.kind === 'welfare' ? '' : hhmm(ev.start) + ' ') + ev.title.replace(' - request', '') + (ev.driver ? ' - ' + ev.driver.split(' ')[0] : '');
    }

    function item(ev) {
        var b = el('button', 'cal-item ' + ev.kind); b.type = 'button';
        b.appendChild(el('div', 't', (ev.kind === 'welfare' ? 'All day' : hhmm(ev.start) + ' - ' + hhmm(ev.end)) + '  ' + ev.title));
        var sub = [ev.driver, ev.vehicle, ev.destination].filter(Boolean).join(' | ');
        if (sub) { b.appendChild(el('div', 's', sub)); }
        b.addEventListener('click', function () { showDetails(ev); });
        return b;
    }

    function renderMonth() {
        var grid = el('div', 'cal-month'), r = range(), today = ymd(new Date());
        DAYS.forEach(function (n) { grid.appendChild(el('div', 'cal-dow', n)); });
        for (var i = 0; i < 42; i++) {
            var d = addDays(r[0], i), cell = el('div', 'cal-cell' + (d.getMonth() !== cursor.getMonth() ? ' other' : '') + (ymd(d) === today ? ' today' : ''));
            cell.appendChild(el('div', 'cal-num', d.getDate()));
            var list = eventsOn(d), dots = el('div', 'cal-dots');
            list.slice(0, 3).forEach(function (ev) {
                var c = el('button', 'cal-chip ' + ev.kind, shortLabel(ev)); c.type = 'button'; c.title = ev.title;
                c.addEventListener('click', function (e) { e.stopPropagation(); showDetails(ev); });
                cell.appendChild(c);
            });
            if (list.length > 3) { cell.appendChild(el('div', 'cal-more', '+' + (list.length - 3) + ' more')); }
            list.slice(0, 12).forEach(function (ev) { dots.appendChild(el('i', ev.kind)); });
            cell.appendChild(dots);
            (function (day) { cell.addEventListener('click', function () { cursor = day; setView('day'); }); })(d);
            grid.appendChild(cell);
        }
        body.replaceChildren(grid);
    }
    function renderWeek() {
        var grid = el('div', 'cal-week'), w = weekStart(cursor), today = ymd(new Date());
        for (var i = 0; i < 7; i++) {
            var d = addDays(w, i), col = el('div', 'cal-daycol' + (ymd(d) === today ? ' today' : ''));
            col.appendChild(el('h6', '', DAYS[i] + ' ' + d.getDate() + ' ' + MONTHS[d.getMonth()].substr(0, 3)));
            var list = eventsOn(d);
            if (!list.length) { col.appendChild(el('div', 'text-sm text-base-content/60', 'Nothing scheduled')); }
            list.forEach(function (ev) { col.appendChild(item(ev)); });
            grid.appendChild(col);
        }
        body.replaceChildren(grid);
    }
    function renderDay() {
        var wrap = el('div', 'cal-day'), list = eventsOn(cursor);
        if (!list.length) { wrap.appendChild(el('p', 'rounded-box border border-base-300 p-4 text-sm text-base-content/60', 'Nothing is scheduled on this day')); }
        list.forEach(function (ev) { wrap.appendChild(item(ev)); });
        body.replaceChildren(wrap);
    }
    function render() {
        titleEl.textContent = title();
        if (view === 'month') { renderMonth(); } else if (view === 'week') { renderWeek(); } else { renderDay(); }
        msg.textContent = events.length ? '' : 'No items in this period for the chosen filter.';
    }

    function load() {
        var r = range(), my = ++seq, q = new URLSearchParams({ start: ymd(r[0]), end: ymd(r[1]) });
        var dv = document.getElementById('calDriver').value, vv = document.getElementById('calVehicle').value;
        if (dv) { q.set('driver_id', dv); } if (vv) { q.set('vehicle_id', vv); }
        body.setAttribute('aria-busy', 'true');
        fetch('../ajax/calendar_events.php?' + q.toString(), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (j) { if (my !== seq) { return; } if (!j.success) { throw new Error(j.message); } events = j.events; render(); })
            .catch(function (e) { if (my === seq) { events = []; render(); msg.textContent = 'Could not load the schedule: ' + e.message; } })
            .finally(function () { body.setAttribute('aria-busy', 'false'); });
    }
    function setView(v) {
        view = v;
        document.querySelectorAll('[data-view]').forEach(function (b) { b.classList.toggle('btn-active', b.dataset.view === v); b.setAttribute('aria-pressed', b.dataset.view === v); });
        load();
    }
    function move(n) {
        if (view === 'month') { cursor = new Date(cursor.getFullYear(), cursor.getMonth() + n, 1); }
        else { cursor = addDays(cursor, n * (view === 'week' ? 7 : 1)); }
        load();
    }

    function showDetails(ev) {
        var dl = document.getElementById('calModalBody'); dl.replaceChildren();
        document.getElementById('calModalTitle').textContent = ev.title;
        var s = parse(ev.start), e = parse(ev.end);
        var when = ev.kind === 'welfare' ? s.getDate() + ' ' + MONTHS[s.getMonth()] + ' ' + s.getFullYear() + ', 12 am to 12 am (24 hours)'
                 : s.getDate() + ' ' + MONTHS[s.getMonth()] + ' ' + s.getFullYear() + ', ' + hhmm(ev.start) + ' - ' + hhmm(ev.end);
        var rows = [['Type', ev.leg], ['Request', ev.request_id ? '#' + ev.request_id + (ev.request_status ? ' (' + ev.request_status + ')' : '') : ''],
                    ['When', when], ['Driver', ev.driver], ['Vehicle', ev.vehicle],
                    ['Destination', ev.destination], ['Waiting place', ev.waiting], ['Purpose', ev.purpose], ['Officer', ev.officer]];
        if (ev.kind === 'welfare' && ev.purpose) { rows[7] = ['Replacement reason', ev.purpose]; }
        rows.forEach(function (r) { if (!r[1]) { return; } dl.appendChild(el('dt', 'text-base-content/60', r[0])); dl.appendChild(el('dd', '', r[1])); });
        var sheet = document.getElementById('calSheet');
        if (ev.schedule_id) { sheet.href = 'task_sheet.php?id=' + ev.schedule_id; sheet.style.display = ''; } else { sheet.style.display = 'none'; }
        document.getElementById('calModal').showModal();
    }

    document.getElementById('calPrev').addEventListener('click', function () { move(-1); });
    document.getElementById('calNext').addEventListener('click', function () { move(1); });
    document.getElementById('calToday').addEventListener('click', function () { cursor = new Date(); cursor.setHours(0, 0, 0, 0); load(); });
    document.querySelectorAll('[data-view]').forEach(function (b) { b.addEventListener('click', function () { setView(b.dataset.view); }); });
    document.getElementById('calDriver').addEventListener('change', load);
    document.getElementById('calVehicle').addEventListener('change', load);
    if (window.matchMedia('(max-width: 767.98px)').matches) { view = 'day'; document.querySelectorAll('[data-view]').forEach(function (b) { b.classList.toggle('btn-active', b.dataset.view === 'day'); b.setAttribute('aria-pressed', b.dataset.view === 'day'); }); }
    load();
});
</script>
<?php adminPageEnd(); ?>
