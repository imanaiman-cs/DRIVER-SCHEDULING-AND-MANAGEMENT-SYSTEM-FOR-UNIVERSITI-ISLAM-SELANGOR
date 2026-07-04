<?php
// ============================================================
// UIS Driver Scheduling and Management System
// admin/report_workload.php  –  Workload Distribution Report
// Universiti Islam Selangor (UIS)
// ============================================================

require_once '../config/database.php';
requireAdmin();

$page_title   = 'Workload Distribution Report';
$current_page = 'report_workload.php';

// ── Date range ───────────────────────────────────────────────
$from_date = isset($_GET['from_date']) && $_GET['from_date'] !== ''
    ? $_GET['from_date']
    : date('Y-01-01');
$to_date = isset($_GET['to_date']) && $_GET['to_date'] !== ''
    ? $_GET['to_date']
    : date('Y-m-d');

// ── Workload query ───────────────────────────────────────────
$stmt = $conn->prepare(
    "SELECT d.driver_id, d.name, d.status AS driver_status,
            COUNT(s.schedule_id) AS total_trips,
            COALESCE(SUM(TIMESTAMPDIFF(HOUR, s.start_time, s.end_time)), 0) AS total_hours,
            SUM(CASE WHEN s.status = 'completed' THEN 1 ELSE 0 END) AS completed_trips
     FROM drivers d
     LEFT JOIN schedules s
           ON d.driver_id = s.driver_id
          AND s.trip_date BETWEEN ? AND ?
          AND s.status != 'cancelled'
     GROUP BY d.driver_id
     ORDER BY total_trips DESC"
);
$stmt->bind_param('ss', $from_date, $to_date);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── Compute average and deviation ───────────────────────────
$total_drivers = count($rows);
$total_trips_all = array_sum(array_column($rows, 'total_trips'));
$avg_trips = $total_drivers > 0 ? $total_trips_all / $total_drivers : 0;

// Fair distribution score: 100 - (std_dev / avg * 100), clamped 0-100
$variance = 0;
if ($total_drivers > 1 && $avg_trips > 0) {
    foreach ($rows as $r) {
        $variance += pow((int)$r['total_trips'] - $avg_trips, 2);
    }
    $variance /= $total_drivers;
}
$std_dev   = sqrt($variance);
$fair_score = $avg_trips > 0
    ? max(0, min(100, round(100 - ($std_dev / $avg_trips * 100), 1)))
    : 100;

// Classify each driver
foreach ($rows as &$r) {
    $trips = (int)$r['total_trips'];
    if ($avg_trips > 0) {
        $deviation_pct = abs($trips - $avg_trips) / $avg_trips * 100;
        if ($trips > $avg_trips && $deviation_pct > 50) {
            $r['load_class'] = 'overloaded';
        } elseif ($trips < $avg_trips && $deviation_pct > 50) {
            $r['load_class'] = 'underutilized';
        } else {
            $r['load_class'] = 'balanced';
        }
    } else {
        $r['load_class'] = 'balanced';
    }
}
unset($r);

// ── Chart data ───────────────────────────────────────────────
$chart_labels = [];
$chart_trips  = [];
$chart_colors = [];
foreach ($rows as $r) {
    $parts          = explode(' ', $r['name']);
    $chart_labels[] = $parts[0] . (isset($parts[1]) ? ' ' . $parts[1] : '');
    $chart_trips[]  = (int)$r['total_trips'];
    $chart_colors[] = match($r['load_class']) {
        'overloaded'   => 'rgba(220,53,69,0.80)',
        'underutilized'=> 'rgba(255,193,7,0.80)',
        default        => 'rgba(40,167,69,0.80)',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> | UIS Driver Management</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="<?php echo SITE_URL; ?>/assets/css/style.css" rel="stylesheet">

    <style>
        .page-header {
            background: linear-gradient(135deg, #856404 0%, #ffc107 100%);
            border-radius: 14px; color: #fff;
            padding: 1.6rem 2rem; margin-bottom: 1.5rem;
            box-shadow: 0 4px 16px rgba(133,100,4,.20);
        }
        .page-header h1 { font-size: 1.55rem; font-weight: 700; margin: 0; color:#1a2035; }
        .page-header p  { margin: .3rem 0 0; font-size: .88rem; color:#3d3000; }

        .filter-card  { border: none; border-radius: 14px; box-shadow: 0 2px 12px rgba(0,53,128,.10); margin-bottom: 1.5rem; }
        .chart-card   { border: none; border-radius: 14px; box-shadow: 0 2px 12px rgba(0,53,128,.10); margin-bottom: 1.5rem; }
        .table-card   { border: none; border-radius: 14px; overflow: hidden; box-shadow: 0 2px 12px rgba(0,53,128,.10); }
        .stat-card    { border: none; border-radius: 14px; box-shadow: 0 2px 12px rgba(0,53,128,.10); }

        .table thead th {
            background: #f8f9fb; font-size: .78rem;
            text-transform: uppercase; letter-spacing: .06em;
            color: #4b5563; border-bottom: 2px solid #e5e7eb; white-space: nowrap;
        }
        .table tbody tr:hover { background: #fffbe6; }

        .load-badge {
            font-size: .75rem; font-weight: 600; border-radius: 20px;
            padding: .3em .7em; letter-spacing: .02em;
        }
        .load-overloaded   { background:#fee2e2; color:#991b1b; }
        .load-balanced     { background:#d1fae5; color:#065f46; }
        .load-underutilized{ background:#fef3c7; color:#92400e; }

        .score-bar { height:7px; border-radius:4px; background:#e9ecef; overflow:hidden; display:inline-block; }
        .score-bar-fill { height:100%; border-radius:4px; }

        @media print {
            .sidebar,.topbar,.filter-card,.btn,.no-print { display:none !important; }
            .main-content { padding:0 !important; }
        }
    </style>
</head>
<body>

<?php include '../includes/sidebar.php'; ?>

<main class="main-content p-4">

    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1><i class="fas fa-chart-pie me-2"></i>Workload Distribution Report</h1>
            <p>Trip load per driver — identifies overloaded and underutilised drivers.</p>
        </div>
        <div class="d-flex gap-2 no-print">
            <a href="reports.php" class="btn btn-outline-dark btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back
            </a>
            <button class="btn btn-dark btn-sm fw-semibold" onclick="window.print()">
                <i class="fas fa-print me-1"></i> Print
            </button>
        </div>
    </div>

    <!-- Filter -->
    <div class="card filter-card no-print">
        <div class="card-body py-3 px-4">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-auto">
                    <label class="form-label fw-semibold mb-1 small text-uppercase text-muted">From Date</label>
                    <input type="date" name="from_date" class="form-control"
                           value="<?php echo htmlspecialchars($from_date); ?>">
                </div>
                <div class="col-auto">
                    <label class="form-label fw-semibold mb-1 small text-uppercase text-muted">To Date</label>
                    <input type="date" name="to_date" class="form-control"
                           value="<?php echo htmlspecialchars($to_date); ?>">
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-warning fw-semibold">
                        <i class="fas fa-filter me-1"></i> Apply Filter
                    </button>
                    <a href="report_workload.php" class="btn btn-outline-secondary ms-1">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Summary stat cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card stat-card p-3 h-100">
                <div class="small text-muted text-uppercase fw-semibold mb-1">Total Trips</div>
                <div class="fs-3 fw-bold text-warning"><?php echo $total_trips_all; ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card stat-card p-3 h-100">
                <div class="small text-muted text-uppercase fw-semibold mb-1">Avg Trips / Driver</div>
                <div class="fs-3 fw-bold text-primary"><?php echo number_format($avg_trips, 1); ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card stat-card p-3 h-100">
                <div class="small text-muted text-uppercase fw-semibold mb-1">Fair Distribution Score</div>
                <div class="fs-3 fw-bold <?php echo $fair_score >= 70 ? 'text-success' : ($fair_score >= 40 ? 'text-warning' : 'text-danger'); ?>">
                    <?php echo $fair_score; ?>%
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card stat-card p-3 h-100">
                <div class="small text-muted text-uppercase fw-semibold mb-1">Total Drivers</div>
                <div class="fs-3 fw-bold text-info"><?php echo $total_drivers; ?></div>
            </div>
        </div>
    </div>

    <!-- Legend -->
    <div class="d-flex gap-3 mb-3 flex-wrap no-print">
        <span class="load-badge load-overloaded"><i class="fas fa-arrow-up me-1"></i> Overloaded (&gt;50% above avg)</span>
        <span class="load-badge load-balanced"><i class="fas fa-check me-1"></i> Balanced (within 50%)</span>
        <span class="load-badge load-underutilized"><i class="fas fa-arrow-down me-1"></i> Underutilised (&gt;50% below avg)</span>
    </div>

    <!-- Chart -->
    <div class="card chart-card">
        <div class="card-header border-bottom fw-semibold py-3 px-4">
            <i class="fas fa-chart-bar me-2 text-warning"></i> Trips Per Driver
        </div>
        <div class="card-body" style="height:300px;">
            <canvas id="chartWorkload"></canvas>
        </div>
    </div>

    <!-- Table -->
    <div class="card table-card">
        <div class="card-body p-0">
            <div class="p-3 border-bottom d-flex align-items-center justify-content-between">
                <h6 class="mb-0 fw-semibold text-warning">
                    <i class="fas fa-table me-1"></i> Driver Workload Details
                </h6>
                <span class="text-muted small">
                    <?php echo htmlspecialchars($from_date); ?> to <?php echo htmlspecialchars($to_date); ?>
                </span>
            </div>
            <div class="p-3">
                <div class="table-responsive">
                    <table id="workloadTable" class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Driver Name</th>
                                <th>Status</th>
                                <th>Total Trips</th>
                                <th>Completed</th>
                                <th>Total Hours</th>
                                <th>vs Average</th>
                                <th>Load Status</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $i => $r): ?>
                            <?php
                                $diff = (int)$r['total_trips'] - $avg_trips;
                                $diff_pct = $avg_trips > 0 ? round($diff / $avg_trips * 100, 1) : 0;
                                $diff_str = ($diff >= 0 ? '+' : '') . $diff_pct . '%';
                                $diff_class = $diff > 0 ? 'text-danger' : ($diff < 0 ? 'text-warning' : 'text-success');

                                $badge_class = match($r['load_class']) {
                                    'overloaded'    => 'load-overloaded',
                                    'underutilized' => 'load-underutilized',
                                    default         => 'load-balanced',
                                };
                                $badge_label = match($r['load_class']) {
                                    'overloaded'    => '<i class="fas fa-arrow-up me-1"></i>Overloaded',
                                    'underutilized' => '<i class="fas fa-arrow-down me-1"></i>Underutilised',
                                    default         => '<i class="fas fa-check me-1"></i>Balanced',
                                };
                                $ds_class = driverStatusBadgeClass($r['driver_status']);
                            ?>
                            <tr>
                                <td class="text-muted small"><?php echo $i + 1; ?></td>
                                <td class="fw-semibold"><?php echo htmlspecialchars($r['name']); ?></td>
                                <td>
                                    <span class="badge <?php echo $ds_class; ?> rounded-pill">
                                        <?php echo ucfirst(str_replace('_', ' ', $r['driver_status'])); ?>
                                    </span>
                                </td>
                                <td class="fw-bold"><?php echo (int)$r['total_trips']; ?></td>
                                <td><?php echo (int)$r['completed_trips']; ?></td>
                                <td><?php echo (int)$r['total_hours']; ?> h</td>
                                <td class="fw-semibold <?php echo $diff_class; ?>"><?php echo $diff_str; ?></td>
                                <td>
                                    <span class="load-badge <?php echo $badge_class; ?>">
                                        <?php echo $badge_label; ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

</main>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="<?php echo SITE_URL; ?>/assets/js/main.js"></script>

<script>
(function () {
    'use strict';

    $('#workloadTable').DataTable({
        order: [[3, 'desc']],
        pageLength: 25,
        language: { search: 'Search drivers:' }
    });

    const labels = <?php echo json_encode($chart_labels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    const trips  = <?php echo json_encode($chart_trips,  JSON_HEX_TAG); ?>;
    const colors = <?php echo json_encode($chart_colors, JSON_HEX_TAG); ?>;
    const avg    = <?php echo $avg_trips; ?>;

    new Chart(document.getElementById('chartWorkload'), {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Total Trips',
                    data: trips,
                    backgroundColor: colors,
                    borderRadius: 5,
                },
                {
                    label: 'Average',
                    data: Array(labels.length).fill(avg),
                    type: 'line',
                    borderColor: '#003580',
                    borderDash: [6, 4],
                    borderWidth: 2,
                    pointRadius: 0,
                    fill: false,
                }
            ]
        },
        options: {
            indexAxis: 'y',
            responsive: true, maintainAspectRatio: false,
            scales: {
                x: { beginAtZero: true, grid: { color: '#f0f0f0' } },
                y: { grid: { display: false } }
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
