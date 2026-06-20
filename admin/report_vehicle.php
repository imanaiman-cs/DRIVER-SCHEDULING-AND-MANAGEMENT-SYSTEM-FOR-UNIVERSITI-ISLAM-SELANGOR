<?php
// ============================================================
// UIS Driver Scheduling and Management System
// admin/report_vehicle.php  –  Vehicle Usage Report
// Universiti Islam Selangor (UIS)
// ============================================================

require_once '../config/database.php';
requireAdmin();

$page_title   = 'Vehicle Usage Report';
$current_page = 'report_vehicle_usage.php';

// ── Date range ───────────────────────────────────────────────
$from_date = isset($_GET['from_date']) && $_GET['from_date'] !== ''
    ? $_GET['from_date']
    : date('Y-01-01');
$to_date = isset($_GET['to_date']) && $_GET['to_date'] !== ''
    ? $_GET['to_date']
    : date('Y-m-d');

// ── Vehicle usage query ──────────────────────────────────────
$stmt = $conn->prepare(
    "SELECT v.vehicle_id, v.plate_number, v.vehicle_type, v.brand, v.model,
            v.capacity, v.status AS vehicle_status,
            v.last_maintenance, v.next_maintenance,
            COUNT(s.schedule_id) AS total_trips,
            COALESCE(SUM(TIMESTAMPDIFF(HOUR, s.start_time, s.end_time)), 0) AS total_hours
     FROM vehicles v
     LEFT JOIN schedules s
           ON v.vehicle_id = s.vehicle_id
          AND s.trip_date BETWEEN ? AND ?
          AND s.status != 'cancelled'
     GROUP BY v.vehicle_id
     ORDER BY total_trips DESC"
);
$stmt->bind_param('ss', $from_date, $to_date);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── Total trips for utilisation % ────────────────────────────
$grand_total_trips = array_sum(array_column($rows, 'total_trips'));
foreach ($rows as &$r) {
    $r['utilization_pct'] = $grand_total_trips > 0
        ? round((int)$r['total_trips'] / $grand_total_trips * 100, 1)
        : 0;
}
unset($r);

// ── Chart data ───────────────────────────────────────────────
$chart_labels = array_column($rows, 'plate_number');
$chart_counts = array_map('intval', array_column($rows, 'total_trips'));
$doughnut_colors = ['#003580','#0056b3','#3b82f6','#60a5fa','#93c5fd','#bfdbfe','#dbeafe'];
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
            background: linear-gradient(135deg, #155724 0%, #28a745 100%);
            border-radius: 14px; color: #fff;
            padding: 1.6rem 2rem; margin-bottom: 1.5rem;
            box-shadow: 0 4px 16px rgba(21,87,36,.20);
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
        .table tbody tr:hover { background: #f0fff4; }

        .util-bar { height:7px; border-radius:4px; background:#e9ecef; overflow:hidden; min-width:60px; display:inline-block; }
        .util-bar-fill { height:100%; border-radius:4px; background:#28a745; }

        .maint-ok   { color:#28a745; }
        .maint-warn { color:#ffc107; }
        .maint-due  { color:#dc3545; }

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
            <h1><i class="fas fa-car-side me-2"></i>Vehicle Usage Report</h1>
            <p>Trip counts, hours driven and utilisation rate per vehicle in the UIS fleet.</p>
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
                    <button type="submit" class="btn btn-success fw-semibold">
                        <i class="fas fa-filter me-1"></i> Apply Filter
                    </button>
                    <a href="report_vehicle.php" class="btn btn-outline-secondary ms-1">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Summary -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card stat-card p-3 h-100">
                <div class="small text-muted text-uppercase fw-semibold mb-1">Total Vehicles</div>
                <div class="fs-3 fw-bold text-success"><?php echo count($rows); ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card stat-card p-3 h-100">
                <div class="small text-muted text-uppercase fw-semibold mb-1">Total Trips</div>
                <div class="fs-3 fw-bold text-primary"><?php echo $grand_total_trips; ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card stat-card p-3 h-100">
                <div class="small text-muted text-uppercase fw-semibold mb-1">Total Hours Driven</div>
                <div class="fs-3 fw-bold text-info"><?php echo array_sum(array_column($rows,'total_hours')); ?> h</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card stat-card p-3 h-100">
                <div class="small text-muted text-uppercase fw-semibold mb-1">Most Used</div>
                <div class="fs-5 fw-bold text-warning">
                    <?php echo $rows ? htmlspecialchars($rows[0]['plate_number']) : '—'; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Doughnut chart + table layout -->
    <div class="row g-4">
        <div class="col-12 col-lg-5">
            <div class="card chart-card h-100">
                <div class="card-header border-bottom fw-semibold py-3 px-4">
                    <i class="fas fa-circle-half-stroke me-2 text-success"></i> Trip Distribution
                </div>
                <div class="card-body d-flex align-items-center justify-content-center" style="height:320px;">
                    <canvas id="chartVehicle"></canvas>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-7">
            <div class="card table-card h-100">
                <div class="card-body p-0">
                    <div class="p-3 border-bottom d-flex align-items-center justify-content-between">
                        <h6 class="mb-0 fw-semibold text-success">
                            <i class="fas fa-table me-1"></i> Vehicle Details
                        </h6>
                        <span class="text-muted small">
                            <?php echo htmlspecialchars($from_date); ?> to <?php echo htmlspecialchars($to_date); ?>
                        </span>
                    </div>
                    <div class="p-3">
                        <div class="table-responsive">
                            <table id="vehicleTable" class="table table-hover align-middle mb-0 small">
                                <thead>
                                    <tr>
                                        <th>Plate</th>
                                        <th>Type</th>
                                        <th>Capacity</th>
                                        <th>Trips</th>
                                        <th>Hours</th>
                                        <th>Utilisation</th>
                                        <th>Status</th>
                                        <th>Maintenance</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($rows as $r): ?>
                                    <?php
                                        $vs_class = vehicleStatusBadgeClass($r['vehicle_status']);
                                        $vs_label = match($r['vehicle_status']) {
                                            'available'   => 'Available',
                                            'in_use'      => 'In Use',
                                            'maintenance' => 'Maintenance',
                                            'retired'     => 'Retired',
                                            default       => ucfirst($r['vehicle_status']),
                                        };

                                        // Maintenance indicator
                                        $next_maint = $r['next_maintenance'];
                                        $maint_class = 'maint-ok';
                                        $maint_icon  = 'fa-circle-check';
                                        $maint_label = $next_maint ? formatDate($next_maint) : '—';
                                        if ($next_maint) {
                                            $days_left = (strtotime($next_maint) - time()) / 86400;
                                            if ($days_left < 0)       { $maint_class = 'maint-due';  $maint_icon = 'fa-triangle-exclamation'; }
                                            elseif ($days_left <= 30) { $maint_class = 'maint-warn'; $maint_icon = 'fa-clock'; }
                                        }
                                    ?>
                                    <tr>
                                        <td class="fw-semibold font-monospace"><?php echo htmlspecialchars($r['plate_number']); ?></td>
                                        <td><?php echo htmlspecialchars($r['vehicle_type']); ?></td>
                                        <td><?php echo (int)$r['capacity']; ?></td>
                                        <td class="fw-bold"><?php echo (int)$r['total_trips']; ?></td>
                                        <td><?php echo (int)$r['total_hours']; ?> h</td>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <div class="util-bar" style="width:55px;">
                                                    <div class="util-bar-fill"
                                                         style="width:<?php echo $r['utilization_pct']; ?>%"></div>
                                                </div>
                                                <?php echo $r['utilization_pct']; ?>%
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo $vs_class; ?> rounded-pill">
                                                <?php echo $vs_label; ?>
                                            </span>
                                        </td>
                                        <td class="<?php echo $maint_class; ?>" title="Next maintenance: <?php echo $maint_label; ?>">
                                            <i class="fas <?php echo $maint_icon; ?> me-1"></i>
                                            <?php echo $maint_label; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div><!-- /.row -->

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

    $('#vehicleTable').DataTable({
        order: [[3, 'desc']],
        pageLength: 10,
        language: { search: 'Search vehicles:' }
    });

    const labels = <?php echo json_encode($chart_labels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    const counts = <?php echo json_encode($chart_counts, JSON_HEX_TAG); ?>;
    const colors = <?php echo json_encode(array_slice($doughnut_colors, 0, count($rows)), JSON_HEX_TAG); ?>;

    new Chart(document.getElementById('chartVehicle'), {
        type: 'doughnut',
        data: {
            labels: labels,
            datasets: [{
                data: counts,
                backgroundColor: colors,
                borderWidth: 3,
                borderColor: '#fff'
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { boxWidth: 14, padding: 16, font: { size: 12 } }
                },
                tooltip: {
                    callbacks: {
                        label: function(ctx) {
                            const total = ctx.dataset.data.reduce(function(a, b) { return a + b; }, 0);
                            const pct   = total > 0 ? Math.round(ctx.raw / total * 100) : 0;
                            return ' ' + ctx.raw + ' trips (' + pct + '%)';
                        }
                    }
                }
            },
            cutout: '58%'
        }
    });
})();
</script>
</body>
</html>
