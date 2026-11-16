<?php
// ============================================================
// includes/dashboard_ui.php  -  small pieces of HTML shared by the dashboards
// ============================================================

require_once __DIR__ . '/dashboard_data.php';

function dh($s): string { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }

/** DaisyUI colour name for the tones the dashboards use (alerts). */
function dashTone(string $tone): string
{
    return ['danger' => 'error', 'secondary' => 'neutral'][$tone] ?? $tone;
}

/**
 * A row of figures (DaisyUI stats). Each item: ['label' => ..., 'value' => ..., 'href' => ?, 'trend' => dashTrend() or null,
 * 'upIsGood' => bool, 'hint' => ?]. Numbers only: no icons.
 */
function dashStats(array $items): string
{
    $h = '<div class="stats stats-vertical mb-6 w-full rounded-box border border-base-300 sm:stats-horizontal [&>.stat]:border-solid [&>.stat]:border-base-300 sm:[&>.stat]:border-b-0">';
    foreach ($items as $it) {
        $desc = [];
        if (!empty($it['trend'])) {
            $tr = $it['trend'];
            if ($tr['pct'] === null) {
                $desc[] = 'None last month';
            } elseif ($tr['dir'] === 'flat') {
                $desc[] = 'Same as last month';
            } else {
                $good = ($tr['dir'] === 'up') === ($it['upIsGood'] ?? true);
                $desc[] = '<span class="' . ($good ? 'text-success' : 'text-error') . '">' . ($tr['pct'] > 0 ? '+' : '') . (int)$tr['pct'] . '%</span> vs last month';
            }
        }
        if (($it['hint'] ?? '') !== '') { $desc[] = dh($it['hint']); }
        $inner = '<div class="stat-title whitespace-normal">' . dh($it['label']) . '</div>'
               . '<div class="stat-value text-3xl font-medium tabular-nums">' . dh($it['value']) . '</div>'
               . ($desc ? '<div class="stat-desc whitespace-normal">' . implode('. ', $desc) . '</div>' : '');
        $h .= !empty($it['href'])
            ? '<a class="stat transition-colors hover:bg-base-200" href="' . dh($it['href']) . '">' . $inner . '</a>'
            : '<div class="stat">' . $inner . '</div>';
    }
    return $h . '</div>';
}

/** Where a request is on its way to Confirmed, as one line of text (current step in green). */
function dashStepper(string $status): string
{
    $steps = ['pending' => 'Pending', 'approved' => 'Approved', 'assigned' => 'Driver Assigned', 'processed' => 'Confirmed'];
    $keys = array_keys($steps);
    $at = array_search($status, $keys, true);
    if ($at === false) { $at = $status === 'completed' ? count($keys) : -1; }
    $h = '<ol class="mt-1 flex flex-wrap items-center gap-x-1.5 text-xs" aria-label="Progress">';
    foreach ($keys as $i => $k) {
        $cls = $i === $at ? 'font-medium text-primary' : ($i < $at ? 'text-base-content/70' : 'text-base-content/40');
        $h .= ($i ? '<li aria-hidden="true" class="text-base-content/30">&rsaquo;</li>' : '')
            . '<li class="' . $cls . '"' . ($i === $at ? ' aria-current="step"' : '') . '>' . dh($steps[$k]) . '</li>';
    }
    return $h . '</ol>';
}

/** The heading of a dashboard: the day as the title, then at most one primary and one secondary action (HTML). */
function dashHeader(string $title, string $actions = ''): string
{
    return '<div class="mb-6 flex flex-wrap items-center justify-between gap-4">'
         . '<h1 class="text-2xl font-medium">' . dh($title) . '</h1>'
         . ($actions !== '' ? '<div class="flex flex-wrap gap-2">' . $actions . '</div>' : '')
         . '</div>';
}

/** An empty state: the plain fact, nothing else. */
function dashEmpty(string $text): string
{
    return '<p class="dash-empty">' . dh($text) . '</p>';
}

/** Counts as a ranked list with a thin bar each (instead of a many-coloured doughnut). $rows: label => count */
function dashRanked(array $rows): string
{
    arsort($rows);
    $max = max(1, ...array_values($rows ?: [0]));
    $h = '<ul class="space-y-3">';
    foreach ($rows as $label => $n) {
        $h .= '<li><div class="flex justify-between text-sm"><span>' . dh($label) . '</span><span class="font-mono">' . (int)$n . '</span></div>'
            . '<div class="mt-1 h-1.5 rounded-full bg-base-200"><div class="h-full rounded-full bg-base-content/30" style="width:' . round($n / $max * 100) . '%"></div></div></li>';
    }
    return $h . '</ul>';
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
    for ($hr = $from; $hr <= $to; $hr++) {
        $x = ($hr - $from) / ($to - $from) * 100;
        $shift = $hr === $from ? '' : ($hr === $to ? '-translate-x-full' : '-translate-x-1/2');
        $hours .= '<span class="absolute ' . $shift . ' font-mono text-[0.6875rem] text-base-content/50" style="left:' . $x . '%">' . sprintf('%02d', $hr % 24) . '</span>';
    }
    $lines = '';
    for ($hr = $from + 1; $hr < $to; $hr++) {
        $lines .= '<span class="absolute inset-y-0 w-px bg-base-200" style="left:' . (($hr - $from) / ($to - $from) * 100) . '%"></span>';
    }
    $now = '';
    if ($nowTs > $t0 && $nowTs < $t0 + $span) {
        $now = '<span class="absolute inset-y-0 w-px bg-base-content" style="left:' . $pos($nowTs) . '%"></span>'
             . '<span class="absolute -top-5 -translate-x-1/2 rounded-sm bg-base-content px-1 font-mono text-[0.6875rem] text-base-100" style="left:' . $pos($nowTs) . '%">' . date('H:i') . '</span>';
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
        Chart.defaults.font.family = '"IBM Plex Sans", system-ui, sans-serif';
        Chart.defaults.color = '#737373';
        Chart.defaults.borderColor = '#f4f4f5';
        var datasets = c.datasets.map(function (d) {
            var color = d.color || '#0b5d3b';
            var set = { label: d.label, data: d.data, borderWidth: round ? 2 : (c.type === 'line' ? 3 : 0), borderRadius: c.type === 'bar' ? 4 : 0 };
            if (round) { set.backgroundColor = d.colors; }
            else { set.backgroundColor = c.type === 'line' ? color + '33' : color; set.borderColor = color; }
            if (c.type === 'line') { set.fill = false; set.tension = 0; set.pointRadius = 3; set.borderWidth = 2; set.pointBackgroundColor = color; }
            return set;
        });
        var options = { responsive: true, maintainAspectRatio: false, animation: false,
            plugins: { legend: { display: round || datasets.length > 1, position: round && !narrow ? 'right' : 'bottom', labels: { boxWidth: 12, usePointStyle: true } } } };
        if (round) { options.cutout = '62%'; }
        else {
            options.indexAxis = horizontal ? 'y' : 'x';
            options.scales = { x: { stacked: stacked, grid: { display: horizontal } }, y: { stacked: stacked, beginAtZero: true, ticks: { precision: 0 } } };
            if (horizontal && narrow) {        // long names would be cut off on a phone: shorten them
                options.scales.y.ticks.callback = function (v) { var l = this.getLabelForValue(v); return l.length > 15 ? l.slice(0, 14) + '…' : l; };
            }
        }
        new Chart(el, { type: c.type, data: { labels: c.labels, datasets: datasets }, options: options });
    });
})();
</script>
<?php
}
