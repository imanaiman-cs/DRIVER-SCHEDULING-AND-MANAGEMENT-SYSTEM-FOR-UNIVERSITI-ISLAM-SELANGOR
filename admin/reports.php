<?php
// ============================================================
// UIS Driver Scheduling and Management System
// admin/reports.php  –  Reports Hub
// Universiti Islam Selangor (UIS)
// ============================================================

require_once '../config/database.php';
requireAdmin();

$page_title   = 'Reports';
$current_page = 'reports.php';

// ── Date range filter ────────────────────────────────────────
$from_date = isset($_GET['from_date']) && $_GET['from_date'] !== ''
    ? $_GET['from_date']
    : date('Y-01-01');
$to_date = isset($_GET['to_date']) && $_GET['to_date'] !== ''
    ? $_GET['to_date']
    : date('Y-m-d');

// ── Summary stats ────────────────────────────────────────────

// Total completed trips in range
$stmt = $conn->prepare("SELECT COUNT(*) FROM schedules WHERE status='completed' AND trip_date BETWEEN ? AND ?");
$stmt->bind_param('ss', $from_date, $to_date);
$stmt->execute();
$total_completed = (int)$stmt->get_result()->fetch_row()[0];
$stmt->close();

// Top performing driver (highest avg performance_score)
$stmt = $conn->prepare(
    "SELECT d.name, d.performance_score FROM drivers d
     LEFT JOIN schedules s ON d.driver_id = s.driver_id
       AND s.trip_date BETWEEN ? AND ?
     GROUP BY d.driver_id
     ORDER BY d.performance_score DESC LIMIT 1"
);
$stmt->bind_param('ss', $from_date, $to_date);
$stmt->execute();
$top_driver = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Most used vehicle (most schedules in range)
$stmt = $conn->prepare(
    "SELECT v.plate_number, v.vehicle_type, COUNT(s.schedule_id) as trip_count
     FROM vehicles v
     LEFT JOIN schedules s ON v.vehicle_id = s.vehicle_id
       AND s.trip_date BETWEEN ? AND ?
     GROUP BY v.vehicle_id
     ORDER BY trip_count DESC LIMIT 1"
);
$stmt->bind_param('ss', $from_date, $to_date);
$stmt->execute();
$top_vehicle = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Busiest month (current year)
$current_year = date('Y');
$stmt = $conn->prepare(
    "SELECT MONTHNAME(trip_date) as month_name, COUNT(*) as cnt
     FROM schedules
     WHERE YEAR(trip_date) = ? AND status != 'cancelled'
     GROUP BY MONTH(trip_date)
     ORDER BY cnt DESC LIMIT 1"
);
$stmt->bind_param('i', $current_year);
$stmt->execute();
$busiest_month = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ── Chart data: Driver performance bar chart ─────────────────
$stmt = $conn->prepare(
    "SELECT d.name, d.performance_score,
            COUNT(s.schedule_id) as total_trips
     FROM drivers d
     LEFT JOIN schedules s ON d.driver_id = s.driver_id
       AND s.trip_date BETWEEN ? AND ?
     GROUP BY d.driver_id
     ORDER BY d.performance_score DESC
     LIMIT 8"
);
$stmt->bind_param('ss', $from_date, $to_date);
$stmt->execute();
$driver_perf_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$driver_names  = [];
$driver_scores = [];
foreach ($driver_perf_rows as $row) {
    // Shorten long names for chart labels
    $parts = explode(' ', $row['name']);
    $driver_names[]  = $parts[0] . (isset($parts[1]) ? ' ' . $parts[1] : '');
    $driver_scores[] = (float)$row['performance_score'];
}

// ── Chart data: Workload horizontal bar ──────────────────────
$stmt = $conn->prepare(
    "SELECT d.name, COUNT(s.schedule_id) as total_trips
     FROM drivers d
     LEFT JOIN schedules s ON d.driver_id = s.driver_id
       AND s.trip_date BETWEEN ? AND ?
     GROUP BY d.driver_id
     ORDER BY total_trips DESC
     LIMIT 8"
);
$stmt->bind_param('ss', $from_date, $to_date);
$stmt->execute();
$workload_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$wl_names = [];
$wl_trips = [];
foreach ($workload_rows as $row) {
    $parts      = explode(' ', $row['name']);
    $wl_names[] = $parts[0] . (isset($parts[1]) ? ' ' . $parts[1] : '');
    $wl_trips[] = (int)$row['total_trips'];
}

// ── Chart data: Vehicle usage doughnut ───────────────────────
$stmt = $conn->prepare(
    "SELECT v.plate_number, COUNT(s.schedule_id) as trip_count
     FROM vehicles v
     LEFT JOIN schedules s ON v.vehicle_id = s.vehicle_id
       AND s.trip_date BETWEEN ? AND ?
     GROUP BY v.vehicle_id
     ORDER BY trip_count DESC"
);
$stmt->bind_param('ss', $from_date, $to_date);
$stmt->execute();
$vehicle_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$vehicle_labels = array_column($vehicle_rows, 'plate_number');
$vehicle_counts = array_map('intval', array_column($vehicle_rows, 'trip_count'));

// ── Chart data: Monthly trend line chart (current year) ──────
$stmt = $conn->prepare(
    "SELECT MONTH(trip_date) as month_num, COUNT(*) as cnt
     FROM schedules
     WHERE YEAR(trip_date) = ? AND status != 'cancelled'
     GROUP BY MONTH(trip_date)
     ORDER BY month_num"
);
$stmt->bind_param('i', $current_year);
$stmt->execute();
$monthly_raw = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$monthly_labels = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
$monthly_counts = array_fill(0, 12, 0);
foreach ($monthly_raw as $row) {
    $monthly_counts[(int)$row['month_num'] - 1] = (int)$row['cnt'];
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="<?php echo SITE_URL; ?>/assets/css/style.css" rel="stylesheet">

    <style>
        .page-header {
            background: linear-gradient(135deg, #003580 0%, #0056b3 100%);
            border-radius: 14px;
            color: #fff;
            padding: 1.6rem 2rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 16px rgba(0,53,128,.20);
        }
        .page-header h1 { font-size: 1.55rem; font-weight: 700; margin: 0; }
        .page-header p  { margin: .3rem 0 0; opacity: .8; font-size: .88rem; }

        .stat-card {
            border: none;
            border-radius: 14px;
            box-shadow: 0 2px 12px rgba(0,53,128,.10);
            transition: transform .2s, box-shadow .2s;
        }
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(0,53,128,.16);
        }
        .stat-icon {
            width: 52px; height: 52px;
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.35rem;
        }
        .stat-value { font-size: 1.9rem; font-weight: 700; line-height: 1; }
        .stat-label { font-size: .78rem; text-transform: uppercase; letter-spacing: .06em; color: #6c757d; }

        .report-card {
            border: none;
            border-radius: 14px;
            box-shadow: 0 2px 12px rgba(0,53,128,.10);
            transition: transform .2s, box-shadow .2s;
            overflow: hidden;
        }
        .report-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0,53,128,.16);
        }
        .report-card .card-header {
            background: #f8f9fb;
            border-bottom: 1px solid #e5e7eb;
            font-weight: 600;
            font-size: .9rem;
            padding: .85rem 1.2rem;
        }
        .report-card .card-header .report-icon {
            width: 34px; height: 34px;
            border-radius: 8px;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: .9rem;
            margin-right: .5rem;
        }
        .chart-container {
            position: relative;
            height: 220px;
        }
        .filter-card {
            border: none;
            border-radius: 14px;
            box-shadow: 0 2px 12px rgba(0,53,128,.10);
            margin-bottom: 1.5rem;
        }
        @media print {
            .sidebar, .topbar, .filter-card, .btn, .page-header p { display: none !important; }
            .main-content { padding: 0 !important; }
        }
    </style>
</head>
<body>

<?php include '../includes/sidebar.php'; ?>

<main class="main-content p-4">

    <!-- Page header -->
    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1><i class="fas fa-chart-bar me-2" aria-hidden="true"></i>Reports &amp; Analytics</h1>
            <p>Overview of driver performance, workload distribution, vehicle usage and schedules.</p>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-warning fw-semibold" onclick="window.print()">
                <i class="fas fa-print me-1"></i> Print
            </button>
        </div>
    </div>

    <!-- Date range filter -->
    <div class="card filter-card">
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
                    <button type="submit" class="btn btn-primary fw-semibold">
                        <i class="fas fa-filter me-1"></i> Generate
                    </button>
                    <a href="reports.php" class="btn btn-outline-secondary ms-1">
                        <i class="fas fa-rotate-left me-1"></i> Reset
                    </a>
                </div>
                <div class="col-auto ms-auto text-muted small">
                    Showing: <strong><?php echo htmlspecialchars($from_date); ?></strong>
                    to <strong><?php echo htmlspecialchars($to_date); ?></strong>
                </div>
            </form>
        </div>
    </div>

    <!-- Summary stat cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card stat-card h-100 p-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon bg-success bg-opacity-10 text-success">
                        <i class="fas fa-circle-check"></i>
                    </div>
                    <div>
                        <div class="stat-value text-success"><?php echo $total_completed; ?></div>
                        <div class="stat-label">Completed Trips</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card stat-card h-100 p-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                        <i class="fas fa-trophy"></i>
                    </div>
                    <div>
                        <div class="stat-value text-primary" style="font-size:1.1rem;line-height:1.4;">
                            <?php echo $top_driver ? htmlspecialchars(explode(' ', $top_driver['name'])[0]) : '—'; ?>
                        </div>
                        <div class="stat-label">Top Driver</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card stat-card h-100 p-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon bg-warning bg-opacity-10 text-warning">
                        <i class="fas fa-car"></i>
                    </div>
                    <div>
                        <div class="stat-value text-warning" style="font-size:1.1rem;line-height:1.4;">
                            <?php echo $top_vehicle ? htmlspecialchars($top_vehicle['plate_number']) : '—'; ?>
                        </div>
                        <div class="stat-label">Most Used Vehicle</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card stat-card h-100 p-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon bg-info bg-opacity-10 text-info">
                        <i class="fas fa-calendar-star"></i>
                    </div>
                    <div>
                        <div class="stat-value text-info" style="font-size:1.1rem;line-height:1.4;">
                            <?php echo $busiest_month ? htmlspecialchars($busiest_month['month_name']) : '—'; ?>
                        </div>
                        <div class="stat-label">Busiest Month</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Report type cards (2x2 grid) -->
    <div class="row g-4">

        <!-- 1. Driver Performance -->
        <div class="col-12 col-xl-6">
            <div class="card report-card h-100">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <div>
                        <span class="report-icon bg-primary bg-opacity-10 text-primary">
                            <i class="fas fa-trophy"></i>
                        </span>
                        Driver Performance Report
                    </div>
                    <a href="report_driver.php" class="btn btn-sm btn-primary">
                        View Full Report <i class="fas fa-arrow-right ms-1"></i>
                    </a>
                </div>
                <div class="card-body">
                    <p class="text-muted small mb-3">
                        Driver performance scores based on experience, attendance, performance and certification metrics.
                    </p>
                    <div class="chart-container">
                        <canvas id="chartDriverPerf"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- 2. Workload Distribution -->
        <div class="col-12 col-xl-6">
            <div class="card report-card h-100">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <div>
                        <span class="report-icon bg-warning bg-opacity-10 text-warning">
                            <i class="fas fa-chart-pie"></i>
                        </span>
                        Workload Distribution Report
                    </div>
                    <a href="report_workload.php" class="btn btn-sm btn-warning text-dark">
                        View Full Report <i class="fas fa-arrow-right ms-1"></i>
                    </a>
                </div>
                <div class="card-body">
                    <p class="text-muted small mb-3">
                        Distribution of trips assigned per driver. Identifies overloaded or underutilised drivers.
                    </p>
                    <div class="chart-container">
                        <canvas id="chartWorkload"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- 3. Vehicle Usage -->
        <div class="col-12 col-xl-6">
            <div class="card report-card h-100">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <div>
                        <span class="report-icon bg-success bg-opacity-10 text-success">
                            <i class="fas fa-car-side"></i>
                        </span>
                        Vehicle Usage Report
                    </div>
                    <a href="report_vehicle.php" class="btn btn-sm btn-success">
                        View Full Report <i class="fas fa-arrow-right ms-1"></i>
                    </a>
                </div>
                <div class="card-body">
                    <p class="text-muted small mb-3">
                        Trip count and utilisation per vehicle in the fleet.
                    </p>
                    <div class="chart-container">
                        <canvas id="chartVehicle"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- 4. Monthly Schedule -->
        <div class="col-12 col-xl-6">
            <div class="card report-card h-100">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <div>
                        <span class="report-icon bg-info bg-opacity-10 text-info">
                            <i class="fas fa-calendar-check"></i>
                        </span>
                        Monthly Schedule Report
                    </div>
                    <a href="report_monthly.php" class="btn btn-sm btn-info text-white">
                        View Full Report <i class="fas fa-arrow-right ms-1"></i>
                    </a>
                </div>
                <div class="card-body">
                    <p class="text-muted small mb-3">
                        Month-by-month schedule counts for <?php echo $current_year; ?>.
                    </p>
                    <div class="chart-container">
                        <canvas id="chartMonthly"></canvas>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /.row report cards -->

</main>

<!-- JS -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="<?php echo SITE_URL; ?>/assets/js/main.js"></script>

<script>
(function () {
    'use strict';

    const driverNames  = <?php echo json_encode($driver_names,  JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    const driverScores = <?php echo json_encode($driver_scores, JSON_HEX_TAG); ?>;
    const wlNames      = <?php echo json_encode($wl_names,      JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    const wlTrips      = <?php echo json_encode($wl_trips,      JSON_HEX_TAG); ?>;
    const vehicleLabels= <?php echo json_encode($vehicle_labels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    const vehicleCounts= <?php echo json_encode($vehicle_counts, JSON_HEX_TAG); ?>;
    const monthLabels  = <?php echo json_encode($monthly_labels, JSON_HEX_TAG); ?>;
    const monthlyCounts= <?php echo json_encode($monthly_counts, JSON_HEX_TAG); ?>;

    // 1. Driver Performance – vertical bar
    new Chart(document.getElementById('chartDriverPerf'), {
        type: 'bar',
        data: {
            labels: driverNames,
            datasets: [{
                label: 'Performance Score',
                data: driverScores,
                backgroundColor: 'rgba(0,53,128,0.75)',
                borderRadius: 5,
                borderSkipped: false,
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            scales: {
                y: { beginAtZero: true, max: 10, grid: { color: '#f0f0f0' } },
                x: { grid: { display: false } }
            },
            plugins: { legend: { display: false } }
        }
    });

    // 2. Workload – horizontal bar
    new Chart(document.getElementById('chartWorkload'), {
        type: 'bar',
        data: {
            labels: wlNames,
            datasets: [{
                label: 'Total Trips',
                data: wlTrips,
                backgroundColor: 'rgba(255,193,7,0.8)',
                borderRadius: 5,
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true, maintainAspectRatio: false,
            scales: {
                x: { beginAtZero: true, grid: { color: '#f0f0f0' } },
                y: { grid: { display: false } }
            },
            plugins: { legend: { display: false } }
        }
    });

    // 3. Vehicle Usage – doughnut
    const doughnutColors = [
        '#003580','#0056b3','#3b82f6','#60a5fa','#93c5fd','#bfdbfe'
    ];
    new Chart(document.getElementById('chartVehicle'), {
        type: 'doughnut',
        data: {
            labels: vehicleLabels,
            datasets: [{
                data: vehicleCounts,
                backgroundColor: doughnutColors.slice(0, vehicleLabels.length),
                borderWidth: 2,
                borderColor: '#fff'
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: {
                legend: { position: 'right', labels: { boxWidth: 12, font: { size: 11 } } }
            },
            cutout: '60%'
        }
    });

    // 4. Monthly – line chart
    new Chart(document.getElementById('chartMonthly'), {
        type: 'line',
        data: {
            labels: monthLabels,
            datasets: [{
                label: 'Schedules',
                data: monthlyCounts,
                borderColor: '#17a2b8',
                backgroundColor: 'rgba(23,162,184,0.12)',
                tension: 0.4,
                fill: true,
                pointBackgroundColor: '#17a2b8',
                pointRadius: 4,
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            scales: {
                y: { beginAtZero: true, grid: { color: '#f0f0f0' } },
                x: { grid: { display: false } }
            },
            plugins: { legend: { display: false } }
        }
    });
})();
</script>
</body>
</html>
