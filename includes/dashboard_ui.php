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
        $cls = $flat ? 'text-muted' : (($up === $upIsGood) ? 'text-success' : 'text-danger');
        $arrow = $flat ? 'minus' : ($up ? 'arrow-trend-up' : 'arrow-trend-down');
        $t = '<span class="dash-trend ' . $cls . '" title="Compared with last month"><i class="fas fa-' . $arrow . '"></i> ' . ($trend['pct'] === null ? 'new' : ($trend['pct'] > 0 ? '+' : '') . $trend['pct'] . '%') . '</span>';
    }
    $inner = '<div class="dash-kpi dash-kpi-' . dh($tone) . '"><div class="dash-kpi-icon"><i class="fas fa-' . dh($icon) . '"></i></div>'
           . '<div class="dash-kpi-body"><div class="dash-kpi-value">' . dh($value) . ' ' . $t . '</div><div class="dash-kpi-label">' . dh($label) . '</div>'
           . ($hint !== '' ? '<div class="dash-kpi-hint">' . dh($hint) . '</div>' : '') . '</div></div>';
    return $href ? '<a class="text-decoration-none" href="' . dh($href) . '">' . $inner . '</a>' : $inner;
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
    $h .= '<table class="table table-sm small mb-0 dash-chart-fallback d-none"><tbody>';
    foreach ($labels as $i => $l) {
        $h .= '<tr><td>' . dh($l) . '</td>';
        foreach ($datasets as $d) { $h .= '<td class="text-end">' . dh($d['data'][$i] ?? '') . '</td>'; }
        $h .= '</tr>';
    }
    return $h . '</tbody></table>';
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
            wrap.classList.add('d-none');
            var t = wrap.nextElementSibling;
            if (t) { t.classList.remove('d-none'); }
            return;
        }
        var c = JSON.parse(el.getAttribute('data-chart'));
        var o = c.opts || {};
        var narrow = window.innerWidth < 576, stacked = !!o.stacked, horizontal = !!o.horizontal, round = c.type === 'doughnut';
        var datasets = c.datasets.map(function (d) {
            var color = d.color || '#198754';
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
