<?php
// ============================================================
// UIS Driver Scheduling and Management System
// admin/report_monthly.php  –  Monthly Schedule Report
// Universiti Islam Selangor (UIS)
// ============================================================

require_once '../config/database.php';
requireAdmin();

$page_title   = 'Monthly Schedule Report';
$current_page = 'report_monthly.php';

// ── Year selector ────────────────────────────────────────────
$current_year = (int)date('Y');
$selected_year = isset($_GET['year']) && (int)$_GET['year'] > 2000
    ? (int)$_GET['year']
    : $current_year;
$prev_year = $selected_year - 1;

// ── Available years from schedules ───────────────────────────
$years_result = $conn->query("SELECT DISTINCT YEAR(trip_date) AS y FROM schedules ORDER BY y DESC");
$available_years = [];
while ($yr = $years_result->fetch_assoc()) {
    $available_years[] = (int)$yr['y'];
}
// Always include current and selected
if (!in_array($current_year, $available_years)) $available_years[] = $current_year;
if (!in_array($selected_year, $available_years)) $available_years[] = $selected_year;
rsort($available_years);

// ── Monthly breakdown for selected year ──────────────────────
$stmt = $conn->prepare(
    "SELECT MONTH(trip_date) AS month_num,
            COUNT(*) AS total_scheduled,
            SUM(CASE WHEN status = 'completed'   THEN 1 ELSE 0 END) AS completed,
            SUM(CASE WHEN status = 'cancelled'   THEN 1 ELSE 0 END) AS cancelled,
            SUM(CASE WHEN status IN ('pending','approved','in_progress') THEN 1 ELSE 0 END) AS pending_count
     FROM schedules
     WHERE YEAR(trip_date) = ?
     GROUP BY MONTH(trip_date)
     ORDER BY month_num"
);
$stmt->bind_param('i', $selected_year);
$stmt->execute();
$monthly_raw = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── Previous year comparison data ───────────────────────────
$stmt = $conn->prepare(
    "SELECT MONTH(trip_date) AS month_num, COUNT(*) AS cnt
     FROM schedules
     WHERE YEAR(trip_date) = ? AND status != 'cancelled'
     GROUP BY MONTH(trip_date)"
);
$stmt->bind_param('i', $prev_year);
$stmt->execute();
$prev_raw = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Build 12-element arrays
$month_names      = ['January','February','March','April','May','June','July','August','September','October','November','December'];
$month_short      = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
$total_arr        = array_fill(0, 12, 0);
$completed_arr    = array_fill(0, 12, 0);
$cancelled_arr    = array_fill(0, 12, 0);
$pending_arr      = array_fill(0, 12, 0);
$prev_arr         = array_fill(0, 12, 0);

foreach ($monthly_raw as $row) {
    $idx = (int)$row['month_num'] - 1;
    $total_arr[$idx]     = (int)$row['total_scheduled'];
    $completed_arr[$idx] = (int)$row['completed'];
    $cancelled_arr[$idx] = (int)$row['cancelled'];
    $pending_arr[$idx]   = (int)$row['pending_count'];
}
foreach ($prev_raw as $row) {
    $prev_arr[(int)$row['month_num'] - 1] = (int)$row['cnt'];
}

// Totals
$grand_total     = array_sum($total_arr);
$grand_completed = array_sum($completed_arr);
$grand_cancelled = array_sum($cancelled_arr);
$grand_pending   = array_sum($pending_arr);
$grand_rate      = $grand_total > 0 ? round($grand_completed / $grand_total * 100, 1) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> | UIS Driver Management</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="<?php echo SITE_URL; ?>/assets/css/style.css" rel="stylesheet">

    <style>
        .page-header {
            background: linear-gradient(135deg, #0c7a8b 0%, #17a2b8 100%);
            border-radius: 14px; color: #fff;
            padding: 1.6rem 2rem; margin-bottom: 1.5rem;
            box-shadow: 0 4px 16px rgba(12,122,139,.20);
        }
        .page-header h1 { font-size: 1.55rem; font-weight: 700; margin: 0; }
        .page-header p  { margin: .3rem 0 0; opacity: .85; font-size: .88rem; }

        .filter-card { border: none; border-radius: 14px; box-shadow: 0 2px 12px rgba(0,53,128,.10); margin-bottom: 1.5rem; }
        .chart-card  { border: none; border-radius: 14px; box-shadow: 0 2px 12px rgba(0,53,128,.10); margin-bottom: 1.5rem; }
        .table-card  { border: none; border-radius: 14px; overflow: hidden; box-shadow: 0 2px 12px rgba(0,53,128,.10); }
        .stat-card   { border: none; border-radius: 14px; box-shadow: 0 2px 12px rgba(0,53,128,.10); }

        .table thead th {
            background: #f8f9fb; font-size: .78rem;
            text-transform: uppercase; letter-spacing: .06em;
            color: #4b5563; border-bottom: 2px solid #e5e7eb; white-space: nowrap;
        }
        .table tbody tr:hover { background: #e8f8fb; }
        .table tfoot td { font-weight: 700; background: #f0f4f8; border-top: 2px solid #dee2e6; }

        .rate-bar { height: 6px; border-radius: 3px; background: #e9ecef; overflow: hidden; min-width: 60px; display: inline-block; }
        .rate-bar-fill { height: 100%; border-radius: 3px; background: #17a2b8; }

        @media print {
            .sidebar,.topbar,.filter-card,.btn,.no-print { display:none !important; }
            .main-content { padding:0 !important; }
            .chart-card { break-inside: avoid; }
        }
    </style>
</head>
<body>

<?php include '../includes/sidebar.php'; ?>

<main class="main-content p-4">

    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1><i class="fas fa-calendar-check me-2"></i>Monthly Schedule Report</h1>
            <p>Schedule counts by month for <?php echo $selected_year; ?>, broken down by completion status.</p>
        </div>
        <div class="d-flex gap-2 no-print">
            <a href="reports.php" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back
            </a>
            <button class="btn btn-light btn-sm fw-semibold" onclick="window.print()">
                <i class="fas fa-print me-1"></i> Print
            </button>
        </div>
    </div>

    <!-- Year filter -->
    <div class="card filter-card no-print">
        <div class="card-body py-3 px-4">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-auto">
                    <label class="form-label fw-semibold mb-1 small text-uppercase text-muted">Select Year</label>
                    <select name="year" class="form-select" style="width:140px;">
                        <?php foreach ($available_years as $yr): ?>
                            <option value="<?php echo $yr; ?>" <?php echo $yr === $selected_year ? 'selected' : ''; ?>>
                                <?php echo $yr; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-info text-white fw-semibold">
                        <i class="fas fa-filter me-1"></i> Apply
                    </button>
                    <a href="report_monthly.php" class="btn btn-outline-secondary ms-1">Reset</a>
                </div>
                <div class="col-auto ms-auto text-muted small">
                    Viewing: <strong><?php echo $selected_year; ?></strong>
                    (comparison: <?php echo $prev_year; ?>)
                </div>
            </form>
        </div>
    </div>

    <!-- Summary stats -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card stat-card p-3 h-100">
                <div class="small text-muted text-uppercase fw-semibold mb-1">Total Schedules</div>
                <div class="fs-3 fw-bold text-info"><?php echo $grand_total; ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card stat-card p-3 h-100">
                <div class="small text-muted text-uppercase fw-semibold mb-1">Completed</div>
                <div class="fs-3 fw-bold text-success"><?php echo $grand_completed; ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card stat-card p-3 h-100">
                <div class="small text-muted text-uppercase fw-semibold mb-1">Cancelled</div>
                <div class="fs-3 fw-bold text-danger"><?php echo $grand_cancelled; ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card stat-card p-3 h-100">
                <div class="small text-muted text-uppercase fw-semibold mb-1">Completion Rate</div>
                <div class="fs-3 fw-bold <?php echo $grand_rate >= 75 ? 'text-success' : ($grand_rate >= 50 ? 'text-warning' : 'text-danger'); ?>">
                    <?php echo $grand_rate; ?>%
                </div>
            </div>
        </div>
    </div>

    <!-- Stacked bar chart -->
    <div class="card chart-card">
        <div class="card-header border-bottom fw-semibold py-3 px-4">
            <i class="fas fa-chart-bar me-2 text-info"></i>
            Monthly Breakdown — <?php echo $selected_year; ?>
            <span class="ms-3 text-muted fw-normal small">vs <?php echo $prev_year; ?> (line)</span>
        </div>
        <div class="card-body" style="height:320px;">
            <canvas id="chartMonthly"></canvas>
        </div>
    </div>

    <!-- Monthly table -->
    <div class="card table-card">
        <div class="card-body p-0">
            <div class="p-3 border-bottom d-flex align-items-center justify-content-between">
                <h6 class="mb-0 fw-semibold text-info">
                    <i class="fas fa-table me-1"></i> Monthly Details — <?php echo $selected_year; ?>
                </h6>
            </div>
            <div class="p-3">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Month</th>
                                <th>Total Scheduled</th>
                                <th>Completed</th>
                                <th>Cancelled</th>
                                <th>Pending / Active</th>
                                <th>Completion Rate</th>
                                <th><?php echo $prev_year; ?> (non-cancelled)</th>
                                <th>YoY Change</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php for ($m = 0; $m < 12; $m++): ?>
                            <?php
                                $total  = $total_arr[$m];
                                $comp   = $completed_arr[$m];
                                $canc   = $cancelled_arr[$m];
                                $pend   = $pending_arr[$m];
                                $prev   = $prev_arr[$m];
                                $rate   = $total > 0 ? round($comp / $total * 100, 1) : 0;
                                $yoy    = $prev > 0 ? round(($total - $prev) / $prev * 100, 1) : null;
                                $yoy_str = $yoy !== null ? (($yoy >= 0 ? '+' : '') . $yoy . '%') : '—';
                                $yoy_class = $yoy === null ? 'text-muted' : ($yoy > 0 ? 'text-success' : 'text-danger');
                                $rate_class = $rate >= 75 ? 'text-success' : ($rate >= 50 ? 'text-warning' : ($total > 0 ? 'text-danger' : 'text-muted'));
                            ?>
                            <tr class="<?php echo $total === 0 ? 'text-muted' : ''; ?>">
                                <td class="fw-semibold"><?php echo $month_names[$m]; ?></td>
                                <td><?php echo $total > 0 ? $total : '—'; ?></td>
                                <td class="text-success fw-semibold"><?php echo $comp > 0 ? $comp : '—'; ?></td>
                                <td class="text-danger"><?php echo $canc > 0 ? $canc : '—'; ?></td>
                                <td class="text-warning"><?php echo $pend > 0 ? $pend : '—'; ?></td>
                                <td>
                                    <?php if ($total > 0): ?>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="rate-bar" style="width:55px;">
                                            <div class="rate-bar-fill" style="width:<?php echo $rate; ?>%;"></div>
                                        </div>
                                        <span class="fw-semibold <?php echo $rate_class; ?>"><?php echo $rate; ?>%</span>
                                    </div>
                                    <?php else: echo '—'; endif; ?>
                                </td>
                                <td><?php echo $prev > 0 ? $prev : '—'; ?></td>
                                <td class="fw-semibold <?php echo $yoy_class; ?>"><?php echo $yoy_str; ?></td>
                            </tr>
                        <?php endfor; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td>TOTAL</td>
                                <td><?php echo $grand_total; ?></td>
                                <td class="text-success"><?php echo $grand_completed; ?></td>
                                <td class="text-danger"><?php echo $grand_cancelled; ?></td>
                                <td class="text-warning"><?php echo $grand_pending; ?></td>
                                <td class="<?php echo $grand_rate >= 75 ? 'text-success' : ($grand_rate >= 50 ? 'text-warning' : 'text-danger'); ?>">
                                    <?php echo $grand_rate; ?>%
                                </td>
                                <td><?php echo array_sum($prev_arr); ?></td>
                                <td>—</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>

</main>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="<?php echo SITE_URL; ?>/assets/js/main.js"></script>

<script>
(function () {
    'use strict';

    const labels    = <?php echo json_encode($month_short,     JSON_HEX_TAG); ?>;
    const completed = <?php echo json_encode($completed_arr,   JSON_HEX_TAG); ?>;
    const cancelled = <?php echo json_encode($cancelled_arr,   JSON_HEX_TAG); ?>;
    const pending   = <?php echo json_encode($pending_arr,     JSON_HEX_TAG); ?>;
    const prevYear  = <?php echo json_encode($prev_arr,        JSON_HEX_TAG); ?>;

    new Chart(document.getElementById('chartMonthly'), {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Completed',
                    data: completed,
                    backgroundColor: 'rgba(40,167,69,0.80)',
                    borderRadius: 4,
                    stack: 'current',
                },
                {
                    label: 'Pending / Active',
                    data: pending,
                    backgroundColor: 'rgba(255,193,7,0.80)',
                    borderRadius: 4,
                    stack: 'current',
                },
                {
                    label: 'Cancelled',
                    data: cancelled,
                    backgroundColor: 'rgba(220,53,69,0.70)',
                    borderRadius: 4,
                    stack: 'current',
                },
                {
                    label: '<?php echo $prev_year; ?> (non-cancelled)',
                    data: prevYear,
                    type: 'line',
                    borderColor: '#003580',
                    backgroundColor: 'rgba(0,53,128,0.08)',
                    tension: 0.4,
                    borderWidth: 2,
                    pointRadius: 4,
                    fill: false,
                    stack: undefined,
                }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            scales: {
                x: { stacked: true, grid: { display: false } },
                y: { stacked: true, beginAtZero: true, grid: { color: '#f0f0f0' } }
            },
            plugins: {
                legend: { position: 'top' }
            }
        }
    });
})();
</script>
</body>
</html>
