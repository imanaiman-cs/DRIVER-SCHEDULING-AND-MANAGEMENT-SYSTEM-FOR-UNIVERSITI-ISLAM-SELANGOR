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
    .cal-bar { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; }
    .cal-title { font-weight: 700; font-size: 1.15rem; min-width: 11rem; text-align: center; }
    .cal-month { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); border-left: 1px solid #dee2e6; border-top: 1px solid #dee2e6; background: #fff; }
    .cal-month .cal-dow { padding: .35rem .25rem; font-size: .75rem; font-weight: 600; text-align: center; background: #f1f5f3; border-right: 1px solid #dee2e6; border-bottom: 1px solid #dee2e6; }
    .cal-cell { min-height: 7rem; padding: .2rem; border-right: 1px solid #dee2e6; border-bottom: 1px solid #dee2e6; overflow: hidden; cursor: pointer; }
    .cal-cell.other { background: #f8f9fa; color: #9aa5a0; }
    .cal-cell.today { background: #e8f5ee; }
    .cal-cell .cal-num { font-size: .8rem; font-weight: 600; }
    .cal-chip { display: block; width: 100%; text-align: left; border: 0; border-radius: .25rem; padding: .05rem .3rem; margin-top: .15rem; font-size: .72rem; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .cal-chip.trip { background: #198754; } .cal-chip.internal { background: #0d6efd; } .cal-chip.welfare { background: #6f42c1; }
    .cal-more { font-size: .7rem; color: #555; margin-top: .1rem; }
    .cal-dots { display: none; flex-wrap: wrap; gap: 2px; margin-top: .25rem; }
    .cal-dots i { width: 8px; height: 8px; border-radius: 50%; display: inline-block; }
    .cal-dots i.trip { background: #198754; } .cal-dots i.internal { background: #0d6efd; } .cal-dots i.welfare { background: #6f42c1; }
    .cal-week { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: .5rem; }
    .cal-daycol { background: #fff; border: 1px solid #dee2e6; border-radius: .5rem; padding: .5rem; min-width: 0; }
    .cal-daycol.today { border-color: #198754; box-shadow: 0 0 0 1px #198754 inset; }
    .cal-daycol h6 { font-size: .85rem; margin-bottom: .4rem; }
    .cal-item { display: block; width: 100%; text-align: left; border: 0; border-left: 4px solid; background: #f6f8f7; border-radius: .25rem; padding: .35rem .5rem; margin-bottom: .35rem; font-size: .82rem; }
    .cal-item.trip { border-color: #198754; } .cal-item.internal { border-color: #0d6efd; } .cal-item.welfare { border-color: #6f42c1; }
    .cal-item .t { font-weight: 600; } .cal-item .s { color: #555; font-size: .76rem; }
    .cal-day .cal-item { font-size: .95rem; padding: .6rem .75rem; }
    .cal-legend span { display: inline-flex; align-items: center; gap: .3rem; margin-right: .9rem; font-size: .8rem; }
    .cal-legend i { width: 10px; height: 10px; border-radius: 2px; display: inline-block; }
    @media (max-width: 767.98px) {
        .cal-week { grid-template-columns: 1fr; }
        .cal-cell { min-height: 3.6rem; }
        .cal-cell .cal-chip, .cal-cell .cal-more { display: none; }
        .cal-cell .cal-dots { display: flex; }
        .cal-title { order: -1; flex-basis: 100%; }
    }
</style>

    <h4 class="fw-bold mb-1"><i class="fas fa-calendar-days text-success me-2"></i>Schedule Calendar</h4>
    <p class="text-muted small mb-3">Trips (request tasks), internal tasks and welfare duty together. Click an item for its details.</p>

    <div class="card border-0 shadow-sm mb-3"><div class="card-body">
        <div class="cal-bar mb-2">
            <div class="btn-group" role="group" aria-label="Move">
                <button class="btn btn-sm btn-outline-success" id="calPrev" aria-label="Previous"><i class="fas fa-chevron-left"></i></button>
                <button class="btn btn-sm btn-outline-success" id="calToday">Today</button>
                <button class="btn btn-sm btn-outline-success" id="calNext" aria-label="Next"><i class="fas fa-chevron-right"></i></button>
            </div>
            <div class="cal-title" id="calTitle" aria-live="polite"></div>
            <div class="btn-group ms-md-auto" role="group" aria-label="View">
                <button class="btn btn-sm btn-outline-success" data-view="day">Day</button>
                <button class="btn btn-sm btn-outline-success" data-view="week">Week</button>
                <button class="btn btn-sm btn-outline-success active" data-view="month">Month</button>
            </div>
        </div>
        <div class="row g-2">
            <div class="col-6 col-md-3"><label class="visually-hidden" for="calDriver">Driver</label>
                <select class="form-select form-select-sm" id="calDriver"><option value="">All drivers</option>
                    <?php foreach ($drivers as $d): ?><option value="<?= (int)$d['driver_id'] ?>"><?= $h($d['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-6 col-md-3"><label class="visually-hidden" for="calVehicle">Vehicle</label>
                <select class="form-select form-select-sm" id="calVehicle"><option value="">All vehicles</option>
                    <?php foreach ($vehicles as $v): ?><option value="<?= (int)$v['vehicle_id'] ?>"><?= $h(vehicleText($v['plate_number'], $v['brand'], $v['model'])) ?></option><?php endforeach; ?></select></div>
            <div class="col-12 col-md-6 cal-legend d-flex align-items-center">
                <span><i style="background:#198754"></i>Trip</span><span><i style="background:#0d6efd"></i>Internal task</span><span><i style="background:#6f42c1"></i>Welfare duty</span>
            </div>
        </div>
    </div></div>

    <div id="calBody" aria-busy="false"></div>
    <div id="calMsg" class="text-muted small mt-2"></div>

    <div class="modal fade" id="calModal" tabindex="-1" aria-labelledby="calModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">
            <div class="modal-header"><h5 class="modal-title" id="calModalTitle"></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body"><dl class="row mb-0" id="calModalBody"></dl></div>
            <div class="modal-footer"><a class="btn btn-outline-secondary btn-sm d-none" id="calSheet" target="_blank" rel="noopener"><i class="fas fa-print me-1"></i>Task sheet</a>
                <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Close</button></div>
        </div></div>
    </div>

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
            if (!list.length) { col.appendChild(el('div', 'text-muted small', 'Nothing scheduled')); }
            list.forEach(function (ev) { col.appendChild(item(ev)); });
            grid.appendChild(col);
        }
        body.replaceChildren(grid);
    }
    function renderDay() {
        var wrap = el('div', 'cal-day'), list = eventsOn(cursor);
        if (!list.length) { wrap.appendChild(el('div', 'alert alert-light border', 'Nothing is scheduled on this day.')); }
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
        document.querySelectorAll('[data-view]').forEach(function (b) { b.classList.toggle('active', b.dataset.view === v); });
        load();
    }
    function move(n) {
        if (view === 'month') { cursor = new Date(cursor.getFullYear(), cursor.getMonth() + n, 1); }
        else { cursor = addDays(cursor, n * (view === 'week' ? 7 : 1)); }
        load();
    }

    var modal = null;
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
        rows.forEach(function (r) { if (!r[1]) { return; } dl.appendChild(el('dt', 'col-4 col-sm-3', r[0])); dl.appendChild(el('dd', 'col-8 col-sm-9', r[1])); });
        var sheet = document.getElementById('calSheet');
        if (ev.schedule_id) { sheet.href = 'task_sheet.php?id=' + ev.schedule_id; sheet.classList.remove('d-none'); } else { sheet.classList.add('d-none'); }
        modal = modal || new bootstrap.Modal(document.getElementById('calModal'));
        modal.show();
    }

    document.getElementById('calPrev').addEventListener('click', function () { move(-1); });
    document.getElementById('calNext').addEventListener('click', function () { move(1); });
    document.getElementById('calToday').addEventListener('click', function () { cursor = new Date(); cursor.setHours(0, 0, 0, 0); load(); });
    document.querySelectorAll('[data-view]').forEach(function (b) { b.addEventListener('click', function () { setView(b.dataset.view); }); });
    document.getElementById('calDriver').addEventListener('change', load);
    document.getElementById('calVehicle').addEventListener('change', load);
    if (window.matchMedia('(max-width: 767.98px)').matches) { view = 'day'; document.querySelectorAll('[data-view]').forEach(function (b) { b.classList.toggle('active', b.dataset.view === 'day'); }); }
    load();
});
</script>
<?php adminPageEnd(); ?>
