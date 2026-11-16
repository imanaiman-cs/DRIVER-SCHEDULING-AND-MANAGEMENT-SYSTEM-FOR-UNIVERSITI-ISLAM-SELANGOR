<?php
// ============================================================
// includes/dashboard_ui.php  -  small pieces of HTML shared by the dashboards
// ============================================================

require_once __DIR__ . '/dashboard_data.php';

function dh($s): string { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }

/** DaisyUI colour name for the tones the dashboards use. */
function dashTone(string $tone): string
{
    return ['danger' => 'error', 'secondary' => 'neutral'][$tone] ?? $tone;
}

/** A KPI tile (DaisyUI stat). $trend comes from dashTrend() (or null). $upIsGood: is "up" good news? */
function dashKpi(string $label, $value, string $icon, string $tone, ?string $href = null, ?array $trend = null, bool $upIsGood = true, string $hint = ''): string
{
    $t = '';
    if ($trend !== null) {
        $up = $trend['dir'] === 'up'; $flat = $trend['dir'] === 'flat';
        $cls = $flat ? 'badge-ghost' : (($up === $upIsGood) ? 'badge-soft badge-success' : 'badge-soft badge-error');
        $arrow = $flat ? 'minus' : ($up ? 'arrow-up' : 'arrow-down');
        $t = '<span class="badge badge-sm ' . $cls . '" title="Compared with last month"><i class="fas fa-' . $arrow . ' text-[0.65rem]"></i>'
           . ($trend['pct'] === null ? 'new' : abs($trend['pct']) . '%') . '</span>';
    }
    $colour = dashTone($tone) === 'neutral' ? 'text-base-content/40' : 'text-' . dashTone($tone);
    $inner = '<div class="stat">'
           . '<div class="stat-figure ' . $colour . '"><i class="fas fa-' . dh($icon) . ' text-xl"></i></div>'
           . '<div class="stat-title whitespace-normal">' . dh($label) . '</div>'
           . '<div class="stat-value flex items-center gap-2 text-3xl">' . dh($value) . $t . '</div>'
           . ($hint !== '' ? '<div class="stat-desc whitespace-normal">' . dh($hint) . '</div>' : '')
           . '</div>';
    $box = 'stats h-full w-full rounded-box border border-base-300 bg-base-100';
    return $href ? '<a class="' . $box . ' transition-colors hover:border-primary/50" href="' . dh($href) . '">' . $inner . '</a>'
                 : '<div class="' . $box . '">' . $inner . '</div>';
}

/** The four steps of a request (DaisyUI steps), with the current one marked. */
function dashStepper(string $status): string
{
    $steps = ['pending' => 'Pending', 'approved' => 'Approved', 'assigned' => 'Driver Assigned', 'processed' => 'Confirmed'];
    $keys = array_keys($steps);
    $at = array_search($status, $keys, true);
    if ($at === false) { $at = $status === 'completed' ? count($keys) : -1; }
    $h = '<ul class="steps steps-xs mt-3 w-full text-xs">';
    foreach ($keys as $i => $k) {
        $h .= '<li class="step' . ($i <= $at ? ' step-primary' : '') . '"' . ($i === $at ? ' aria-current="step"' : '') . '>' . dh($steps[$k]) . '</li>';
    }
    return $h . '</ul>';
}

/** "Good morning" / "Good afternoon" / "Good evening" (Malaysia time). */
function dashGreeting(): string
{
    $h = (int)date('G');
    return $h < 12 ? 'Good morning' : ($h < 18 ? 'Good afternoon' : 'Good evening');
}

/** The page heading of a dashboard: greeting, a line of context and the main actions (HTML of buttons). */
function dashHeader(string $name, array $context, string $actions = ''): string
{
    return '<div class="mb-6 flex flex-wrap items-end justify-between gap-4">'
         . '<div><h1 class="text-2xl font-semibold">' . dh(dashGreeting() . ', ' . $name) . '</h1>'
         . '<p class="mt-1 text-sm text-base-content/60">' . implode(' &middot; ', array_map('dh', array_filter($context, fn($c) => (string)$c !== ''))) . '</p></div>'
         . ($actions !== '' ? '<div class="flex flex-wrap gap-2">' . $actions . '</div>' : '')
         . '</div>';
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
        Chart.defaults.font.family = 'Inter, system-ui, sans-serif';
        Chart.defaults.color = '#5f6b65';
        Chart.defaults.borderColor = '#e4e8e6';
        var datasets = c.datasets.map(function (d) {
            var color = d.color || '#0b5d3b';
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
