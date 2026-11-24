<?php
// ============================================================
// includes/dashboard_ui.php  -  small pieces of HTML shared by the dashboards
// ============================================================


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

/** The steps of a request, with the current one marked. */
function dashStepper(array $r): string
{
    $steps = $r['driver_option'] === 'with_driver'
        ? ['pending' => $r['purpose_type'] === 'personal' ? 'Pending' : 'Pending', 'hod_approved' => 'Approved by HoD', 'driver_assigned' => 'Driver Assigned', 'approved' => 'Approved']
        : ['pending' => 'Pending', 'hod_approved' => 'Approved by HoD', 'approved' => 'Approved'];
    if ($r['purpose_type'] === 'personal') { unset($steps['hod_approved']); }
    $keys = array_keys($steps);
    $at = array_search($r['status'], $keys, true);
    if ($at === false) { $at = $r['status'] === 'completed' ? count($keys) : -1; }
    $h = '<ol class="dash-steps">';
    foreach ($keys as $i => $k) {
        $cls = $i < $at ? 'done' : ($i === $at ? 'now' : '');
        $h .= '<li class="' . $cls . '"><span class="dot"></span><span class="lbl">' . dh($steps[$k]) . '</span></li>';
    }
    return $h . '</ol>';
}

/** The name to greet with: "Norliza binti Abdul Rahman" -> "Norliza". */
function dashShortName(string $full): string
{
    return explode(' bin ', explode(' binti ', trim($full))[0])[0];
}

/** The top of every dashboard: greeting on the left, up to two pill buttons on the right ([label, href, icon, primary?]). */
function dashHeader(array $buttons, string $sub = ''): string
{
    $h = '<div class="mb-6 flex flex-wrap items-end justify-between gap-4" data-dashboard><div>';
    if ($sub !== '') { $h .= '<p class="text-sm text-base-content/65">' . dh($sub) . '</p>'; }
    $h .= '<h1>' . dashGreeting() . ', ' . dh(dashShortName($_SESSION['full_name'] ?? '')) . '</h1></div><div class="flex flex-wrap items-center gap-2">';
    foreach ($buttons as [$label, $href, $icon, $primary]) {
        $h .= '<a class="btn' . ($primary ? ' btn-neutral' : '') . '" href="' . dh($href) . '"><i class="fas fa-' . dh($icon) . '"></i>' . dh($label) . '</a>';
    }
    return $h . '</div></div>';
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
    // A short spoken summary for screen readers; the full numbers are in the table below the chart
    $summary = [];
    foreach ($labels as $i => $l) { $summary[] = $l . ' ' . implode('/', array_map(fn($d) => (string)($d['data'][$i] ?? ''), $datasets)); }
    $aria = implode(', ', array_column($datasets, 'label')) . ': ' . implode(', ', array_slice($summary, 0, 12)) . (count($summary) > 12 ? ' …' : '');
    $h = '<div class="dash-chart" style="height:' . (int)($opts['height'] ?? 240) . 'px"><canvas id="' . dh($id) . '" role="img" aria-label="' . dh($aria) . '" data-chart="' . dh(json_encode($cfg, JSON_UNESCAPED_UNICODE)) . '"></canvas>' . $centre . '</div>';
    $h .= '<div class="dash-chart-fallback sr-only"><table class="table text-sm"><caption class="sr-only">Chart data</caption><tbody>';
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
    // the light palette, swapped for brighter tones that read on the dark theme
    var DARK = { '#0b5d3b': '#10b981', '#13a86a': '#34d399', '#1d4ed8': '#60a5fa', '#64748b': '#cbd5e1', '#101814': '#e7eeea' };
    function tone(c) { return document.documentElement.getAttribute('data-theme') === 'uis' ? (DARK[c] || c) : c; }
    function draw() {
    var light = document.documentElement.getAttribute('data-theme') === 'uis-light';
    canvases.forEach(function (el) {
        var old = typeof Chart !== 'undefined' && Chart.getChart(el); if (old) { old.destroy(); }
        var wrap = el.closest('.dash-chart');
        if (typeof Chart === 'undefined') {            // no chart library: show the numbers instead
            wrap.classList.add('hidden');
            var t = wrap.nextElementSibling;
            if (t) { t.classList.remove('sr-only'); }
            return;
        }
        Chart.defaults.color = light ? 'rgba(19,34,27,.75)' : 'rgba(230,239,233,.75)'; Chart.defaults.borderColor = light ? 'rgba(19,34,27,.08)' : 'rgba(255,255,255,.08)';
        var c = JSON.parse(el.getAttribute('data-chart'));
        var o = c.opts || {};
        var calm = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        var narrow = window.innerWidth < 576, stacked = !!o.stacked, horizontal = !!o.horizontal, round = c.type === 'doughnut';
        var datasets = c.datasets.map(function (d) {
            var color = tone(d.color || '#15804f');
            if (d.colors) { d = Object.assign({}, d, { colors: d.colors.map(tone) }); }
            var set = { label: d.label, data: d.data, borderWidth: round ? 2 : (c.type === 'line' ? 3 : 0), borderRadius: c.type === 'bar' ? 4 : 0 };
            if (round) { set.backgroundColor = d.colors; }
            else { set.backgroundColor = c.type === 'line' ? color + '33' : (d.colors || color); set.borderColor = d.colors || color; }
            if (c.type === 'line') { set.fill = true; set.tension = 0.35; set.pointRadius = 4; set.pointBackgroundColor = color; }
            return set;
        });
        var options = { responsive: true, maintainAspectRatio: false, animation: calm ? false : { duration: 400 },
            plugins: { legend: { display: round || datasets.length > 1, position: round && !narrow ? 'right' : 'bottom', labels: { boxWidth: 12, usePointStyle: true } } } };
        if (round) { options.cutout = '62%'; }
        else {
            options.indexAxis = horizontal ? 'y' : 'x';
            options.scales = { x: { stacked: stacked, grid: { display: horizontal }, beginAtZero: true, ticks: horizontal ? { precision: 0 } : {} }, y: { stacked: stacked, beginAtZero: true, ticks: { precision: 0 } } };
            if (horizontal) {                   // long names would be cut off in a narrow card: shorten them
                options.scales.y.ticks.callback = function (v) { var l = this.getLabelForValue(v); return l.length > 18 ? l.slice(0, 17) + '…' : l; };
            }
        }
        new Chart(el, { type: c.type, data: { labels: c.labels, datasets: datasets }, options: options });
    });
    }
    draw();
    document.addEventListener('uis-theme', draw);
})();
</script>
<?php
}
