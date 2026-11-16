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

/**
 * The heading of a dashboard: the day as the title ("Today, Monday, 16 November 2026"), then at most one primary and one secondary
 * action (HTML). "Today" and the date are separate text nodes so both are translated (includes/i18n.php).
 */
function dashHeader(string $actions = ''): string
{
    return '<header class="mb-6 flex flex-wrap items-center justify-between gap-4" data-dashboard>'
         . '<h1 class="text-2xl font-medium"><span>Today</span>, <time datetime="' . date('Y-m-d') . '">' . dh(date('l, j F Y')) . '</time></h1>'
         . ($actions !== '' ? '<div class="flex flex-wrap gap-2">' . $actions . '</div>' : '')
         . '</header>';
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

/* The dispatch board of the admin dashboard is dispatchBoardHtml() in includes/schedule_view.php (one board, with day navigation). */

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
