<?php
// ============================================================
// includes/dashboard_ui.php  -  small pieces of HTML shared by the dashboards
// ============================================================

require_once __DIR__ . '/dashboard_data.php';

function dh($s): string { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }

/** A KPI card. $trend comes from dashTrend() (or null). $good: is "up" good news? */
function dashKpi(string $label, $value, string $icon, string $tone, ?string $href = null, ?array $trend = null, bool $upIsGood = true, string $hint = ''): string
{
    $t = '';
    if ($trend !== null) {
        $up = $trend['dir'] === 'up'; $flat = $trend['dir'] === 'flat';
        $cls = $flat ? 'text-base-content/60' : (($up === $upIsGood) ? 'text-success' : 'text-error');
        $arrow = $flat ? 'minus' : ($up ? 'arrow-trend-up' : 'arrow-trend-down');
        $t = '<span class="dash-trend ' . $cls . '" title="Compared with last month"><i class="fas fa-' . $arrow . '"></i> ' . ($trend['pct'] === null ? 'new' : ($trend['pct'] > 0 ? '+' : '') . $trend['pct'] . '%') . '</span>';
    }
    $inner = '<div class="dash-kpi dash-kpi-' . dh($tone) . '"><div class="dash-kpi-icon"><i class="fas fa-' . dh($icon) . '"></i></div>'
           . '<div class="dash-kpi-body"><div class="dash-kpi-value">' . dh($value) . ' ' . $t . '</div><div class="dash-kpi-label">' . dh($label) . '</div>'
           . ($hint !== '' ? '<div class="dash-kpi-hint">' . dh($hint) . '</div>' : '') . '</div></div>';
    return $href ? '<a class="block h-full no-underline" href="' . dh($href) . '">' . $inner . '</a>' : $inner;
}

/** The four steps of a request, with the current one marked. */
function dashStepper(string $status): string
{
    $steps = ['pending' => 'Pending', 'approved' => 'Approved', 'assigned' => 'Driver Assigned', 'processed' => 'Confirmed'];
    $keys = array_keys($steps);
    $at = array_search($status, $keys, true);
    if ($at === false) { $at = $status === 'completed' ? count($keys) : -1; }
    $h = '<ol class="dash-steps">';
    foreach ($keys as $i => $k) {
        $cls = $i < $at ? 'done' : ($i === $at ? 'now' : '');
        $h .= '<li class="' . $cls . '"><span class="dot"></span><span class="lbl">' . dh($status === 'pending' && $k === 'assigned' ? $steps[$k] : $steps[$k]) . '</span></li>';
    }
    return $h . '</ol>';
}

/** Up to two initials of a name, for the small avatar circles. */
function dashInitials(string $name): string
{
    $parts = array_values(array_filter(preg_split('/\s+/', trim($name)), fn($w) => !in_array(strtolower($w), ['bin', 'binti', 'a/l', 'a/p'], true)));
    return strtoupper(substr($parts[0] ?? '?', 0, 1) . substr($parts[1] ?? '', 0, 1));
}

/** "Good morning" / "Good afternoon" / "Good evening" (Malaysia time). */
function dashGreeting(): string
{
    $h = (int)date('G');
    return $h < 12 ? 'Good morning' : ($h < 18 ? 'Good afternoon' : 'Good evening');
}

/** The small rounded labels in the hero banner: [[icon, text], ...] */
function dashChips(array $chips): string
{
    $h = '';
    foreach ($chips as [$icon, $text]) { $h .= '<span class="dash-chip"><i class="fas fa-' . dh($icon) . '"></i>' . dh($text) . '</span>'; }
    return $h;
}

/** A friendly empty state. */
function dashEmpty(string $text, string $icon = 'mug-hot'): string
{
    return '<div class="dash-empty"><i class="fas fa-' . dh($icon) . '"></i>' . dh($text) . '</div>';
}

/** DaisyUI colour name for the alert tones. */
function dashTone(string $tone): string
{
    return ['danger' => 'error', 'secondary' => 'neutral'][$tone] ?? $tone;
}

/**
 * Today's dispatch board: one row per vehicle across the working day, with its trips and internal tasks as blocks.
 * Busy vehicles first; a vehicle with no blocks is free. Filled = Confirmed, outlined = waiting for the Head of Admin,
 * grey = internal task or already finished.
 */
function dashBoard(mysqli $conn): string
{
    $items = dashToday($conn);
    $vehicles = dashRows($conn, "SELECT plate_number, vehicle_type, brand, model, status FROM vehicles WHERE status <> 'inactive' ORDER BY plate_number");
    $today = date('Y-m-d');
    $nowTs = time();

    // The window: 07:00 to 19:00, widened to whole hours when something starts earlier or ends later
    $from = 7; $to = 19;
    foreach ($items as $it) {
        $s = strtotime($it['start']); $e = strtotime($it['end']);
        $from = min($from, date('Y-m-d', $s) < $today ? 0 : (int)date('G', $s));
        $to = max($to, date('Y-m-d', $e) > $today ? 24 : (int)ceil(((int)date('G', $e) * 60 + (int)date('i', $e)) / 60));
    }
    $t0 = strtotime("$today $from:00"); $span = ($to - $from) * 3600;
    $pos = fn(int $ts) => max(0, min(100, ($ts - $t0) / $span * 100));

    $rows = [];
    foreach ($vehicles as $v) { $rows[$v['plate_number']] = ['v' => $v, 'items' => []]; }
    foreach ($items as $it) {
        $key = $it['vehicle'] !== '' && isset($rows[$it['vehicle']]) ? $it['vehicle'] : '';
        if ($key === '' && !isset($rows[''])) { $rows[''] = ['v' => null, 'items' => []]; }
        $rows[$key]['items'][] = $it;
    }
    uasort($rows, fn($a, $b) => (count($b['items']) > 0) <=> (count($a['items']) > 0));

    $hours = '';
    $nowX = ($nowTs > $t0 && $nowTs < $t0 + $span) ? $pos($nowTs) : null;
    for ($hr = $from; $hr <= $to; $hr++) {
        $x = ($hr - $from) / ($to - $from) * 100;
        if ($nowX !== null && abs($x - $nowX) < 6) { continue; }   // the "now" label sits here; don't print an hour under it
        $shift = $hr === $from ? '' : ($hr === $to ? '-translate-x-full' : '-translate-x-1/2');
        $hours .= '<span class="absolute ' . $shift . ' font-mono text-[0.6875rem] text-base-content/50" style="left:' . $x . '%">' . sprintf('%02d', $hr % 24) . '</span>';
    }
    $lines = '';
    for ($hr = $from + 1; $hr < $to; $hr++) {
        $lines .= '<span class="absolute inset-y-0 w-px bg-base-200" style="left:' . (($hr - $from) / ($to - $from) * 100) . '%"></span>';
    }
    $now = '';
    if ($nowTs > $t0 && $nowTs < $t0 + $span) {
        $now = '<span class="absolute inset-y-0 w-px bg-primary" style="left:' . $pos($nowTs) . '%"></span>'
             . '<span class="absolute -top-6 z-[1] -translate-x-1/2 rounded bg-primary px-1.5 py-px text-[0.6875rem] font-semibold tabular-nums text-primary-content" style="left:' . $pos($nowTs) . '%">' . date('H:i') . '</span>';
    }

    $h = '<div class="overflow-x-auto"><div class="min-w-[44rem]">'
       . '<div class="grid grid-cols-[11rem_1fr]"><div></div><div class="relative mb-2 h-4">' . $hours . '</div></div>'
       . '<div class="relative grid grid-cols-[11rem_1fr]">'
       . '<div class="pointer-events-none absolute inset-y-0 right-0 left-[11rem]">' . $lines . $now . '</div>';
    foreach ($rows as $key => $r) {
        $v = $r['v'];
        $label = $v ? plate($v['plate_number']) . '<span class="mt-0.5 block truncate text-xs text-base-content/60">'
                    . dh($v['status'] === 'maintenance' ? 'In maintenance' : trim($v['vehicle_type'] . ' · ' . trim(($v['brand'] ?? '') . ' ' . ($v['model'] ?? '')), ' ·')) . '</span>'
                    : '<span class="text-sm text-base-content/60">No vehicle</span>';
        $blocks = '';
        foreach ($r['items'] as $it) {
            $s = strtotime($it['start']); $e = strtotime($it['end']);
            $left = $pos($s); $width = max(1.5, $pos($e) - $left);
            $done = $e < $nowTs;
            $internal = $it['kind'] === 'Internal';
            $waiting = !$internal && $it['status'] === 'assigned';
            $style = $done || $internal ? 'bg-base-200 text-base-content/60' : ($waiting ? 'border border-primary/50 bg-base-100' : 'bg-primary/10');
            $tip = date('H:i', $s) . '–' . date('H:i', $e) . ' ' . $it['title'] . ($it['who'] ? ', ' . $it['who'] : '')
                 . ($internal ? ' (internal task)' : ($it['status'] ? ' (' . requestStatusLabel($it['status']) . ')' : ''));
            $blocks .= '<div class="absolute inset-y-1 overflow-hidden rounded-field px-2 py-1 text-xs leading-tight ' . $style . '" style="left:' . $left . '%;width:' . $width . '%" title="' . dh($tip) . '">'
                     . '<span class="block truncate"><span class="font-mono">' . date('H:i', $s) . '</span> <span class="font-medium">' . dh($it['title']) . '</span></span>'
                     . '<span class="block truncate text-base-content/60">' . dh($it['who']) . '</span></div>';
        }
        $track = $v && $v['status'] === 'maintenance' ? 'bg-base-200' : '';
        $h .= '<div class="flex h-14 flex-col items-start justify-center border-t border-base-200 pe-3">' . $label . '</div>'
            . '<div class="relative h-14 border-t border-base-200 ' . $track . '">' . $blocks . '</div>';
    }
    $h .= '</div></div></div>';
    $h .= '<p class="mt-3 text-xs text-base-content/60">Green: Confirmed. Outlined: waiting for the Head of Admin. Grey: internal task or finished. An empty row is a free vehicle.</p>';
    return $h;
}

/** Waiting time as a short text: "3 h", "2 days". */
function dashWaiting($hours): string
{
    $h = (int)$hours;
    return $h < 48 ? max(0, $h) . ' h' : intdiv($h, 24) . ' days';
}

/**
 * A chart. $type: doughnut|bar|line; $labels: string[]; $datasets: [['label'=>..., 'data'=>[...], 'color'=>'#hex'], ...].
 * A table with the same numbers is shown when the chart library cannot load (no internet).
 */
function dashChart(string $id, string $type, array $labels, array $datasets, array $opts = []): string
{
    $cfg = ['type' => $type, 'labels' => array_values($labels), 'datasets' => $datasets, 'opts' => $opts];
    $centre = isset($opts['centre']) ? '<div class="dash-chart-centre"><div><b>' . dh($opts['centre'][0]) . '</b><span>' . dh($opts['centre'][1]) . '</span></div></div>' : '';
    $h = '<div class="dash-chart" style="height:' . (int)($opts['height'] ?? 240) . 'px"><canvas id="' . dh($id) . '" data-chart="' . dh(json_encode($cfg, JSON_UNESCAPED_UNICODE)) . '"></canvas>' . $centre . '</div>';
    $h .= '<div class="dash-chart-fallback hidden"><table class="table text-sm"><tbody>';
    foreach ($labels as $i => $l) {
        $h .= '<tr><td>' . dh($l) . '</td>';
        foreach ($datasets as $d) { $h .= '<td class="text-end">' . dh($d['data'][$i] ?? '') . '</td>'; }
        $h .= '</tr>';
    }
    return $h . '</tbody></table></div>';
}

/** Loads Chart.js and draws every canvas made by dashChart(). Call once, at the end of the page content. */
function dashChartScript(): void
{
    ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
(function () {
    var canvases = document.querySelectorAll('canvas[data-chart]');
    canvases.forEach(function (el) {
        var wrap = el.closest('.dash-chart');
        if (typeof Chart === 'undefined') {            // no chart library: show the numbers instead
            wrap.classList.add('hidden');
            var t = wrap.nextElementSibling;
            if (t) { t.classList.remove('hidden'); }
            return;
        }
        var c = JSON.parse(el.getAttribute('data-chart'));
        var o = c.opts || {};
        var narrow = window.innerWidth < 576, stacked = !!o.stacked, horizontal = !!o.horizontal, round = c.type === 'doughnut';
        var datasets = c.datasets.map(function (d) {
            var color = d.color || '#15804f';
            var set = { label: d.label, data: d.data, borderWidth: round ? 2 : (c.type === 'line' ? 3 : 0), borderRadius: c.type === 'bar' ? 4 : 0 };
            if (round) { set.backgroundColor = d.colors; }
            else { set.backgroundColor = c.type === 'line' ? color + '33' : color; set.borderColor = color; }
            if (c.type === 'line') { set.fill = true; set.tension = 0.35; set.pointRadius = 4; set.pointBackgroundColor = color; }
            return set;
        });
        var options = { responsive: true, maintainAspectRatio: false, animation: { duration: 600 },
            plugins: { legend: { display: round || datasets.length > 1, position: round && !narrow ? 'right' : 'bottom', labels: { boxWidth: 12, usePointStyle: true } } } };
        if (round) { options.cutout = '62%'; }
        else {
            options.indexAxis = horizontal ? 'y' : 'x';
            options.scales = { x: { stacked: stacked, grid: { display: horizontal } }, y: { stacked: stacked, beginAtZero: true, ticks: { precision: 0 } } };
            if (horizontal) {                   // long names would be cut off in a narrow card: shorten them
                options.scales.y.ticks.callback = function (v) { var l = this.getLabelForValue(v); return l.length > 18 ? l.slice(0, 17) + '…' : l; };
            }
        }
        new Chart(el, { type: c.type, data: { labels: c.labels, datasets: datasets }, options: options });
    });
})();
</script>
<?php
}
