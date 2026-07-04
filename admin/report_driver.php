<?php
// ============================================================
// UIS Driver Scheduling and Management System
// admin/report_driver.php  –  Driver Performance Report
// Universiti Islam Selangor (UIS)
// ============================================================

require_once '../config/database.php';
requireAdmin();

$page_title   = 'Driver Performance Report';
$current_page = 'report_performance.php';

// ── Date range (default: current month) ─────────────────────
$from_date = isset($_GET['from_date']) && $_GET['from_date'] !== ''
    ? $_GET['from_date']
    : date('Y-m-01');
$to_date = isset($_GET['to_date']) && $_GET['to_date'] !== ''
    ? $_GET['to_date']
    : date('Y-m-d');

// ── Main query ───────────────────────────────────────────────
$stmt = $conn->prepare(
    "SELECT d.driver_id, d.name, d.experience_years, d.attendance_rate,
            d.performance_score, d.certification_score,
            COUNT(s.schedule_id) AS total_trips,
            SUM(CASE WHEN s.status = 'completed' THEN 1 ELSE 0 END) AS completed_trips,
            COALESCE(SUM(TIMESTAMPDIFF(HOUR, s.start_time, s.end_time)), 0) AS total_hours
     FROM drivers d
     LEFT JOIN schedules s
           ON d.driver_id = s.driver_id
          AND s.trip_date BETWEEN ? AND ?
     GROUP BY d.driver_id
     ORDER BY d.performance_score DESC"
);
$stmt->bind_param('ss', $from_date, $to_date);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Attach priority score and completion rate, then re-sort
foreach ($rows as &$r) {
    $r['priority_score'] = calculatePriorityScore(
        (float)$r['experience_years'],
        (float)$r['attendance_rate'],
        (float)$r['performance_score'],
        (float)$r['certification_score']
    );
    $r['completion_rate'] = $r['total_trips'] > 0
        ? round(($r['completed_trips'] / $r['total_trips']) * 100, 1)
        : 0;
}
unset($r);

usort($rows, fn($a, $b) => $b['priority_score'] <=> $a['priority_score']);

// ── Chart data ───────────────────────────────────────────────
$chart_labels = [];
$chart_scores = [];
foreach ($rows as $r) {
    $parts           = explode(' ', $r['name']);
    $chart_labels[]  = $parts[0] . (isset($parts[1]) ? ' ' . $parts[1] : '');
    $chart_scores[]  = $r['priority_score'];
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
            background: linear-gradient(135deg, #003580 0%, #0056b3 100%);
            border-radius: 14px; color: #fff;
            padding: 1.6rem 2rem; margin-bottom: 1.5rem;
            box-shadow: 0 4px 16px rgba(0,53,128,.20);
        }
        .page-header h1 { font-size: 1.55rem; font-weight: 700; margin: 0; }
        .page-header p  { margin: .3rem 0 0; opacity: .8; font-size: .88rem; }

        .filter-card  { border: none; border-radius: 14px; box-shadow: 0 2px 12px rgba(0,53,128,.10); margin-bottom: 1.5rem; }
        .chart-card   { border: none; border-radius: 14px; box-shadow: 0 2px 12px rgba(0,53,128,.10); margin-bottom: 1.5rem; }
        .table-card   { border: none; border-radius: 14px; overflow: hidden; box-shadow: 0 2px 12px rgba(0,53,128,.10); }
        .table thead th {
            background: #f8f9fb; font-size: .78rem;
            text-transform: uppercase; letter-spacing: .06em;
            color: #4b5563; border-bottom: 2px solid #e5e7eb; white-space: nowrap;
        }
        .table tbody tr:hover { background: #f0f5ff; }

        .badge-rank { width:28px;height:28px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-weight:700;font-size:.8rem; }
        .rank-gold   { background:#ffd700;color:#7a5800; }
        .rank-silver { background:#c0c0c0;color:#4a4a4a; }
        .rank-bronze { background:#cd7f32;color:#fff; }
        .rank-other  { background:#e9ecef;color:#495057; }

        .score-bar { height:7px;border-radius:4px;background:#e9ecef;overflow:hidden; }
        .score-bar-fill { height:100%;border-radius:4px; }

        @media print {
            .sidebar,.topbar,.filter-card,.btn,.no-print { display:none !important; }
            .main-content { padding:0 !important; }
        }
    </style>
</head>
<body>

<?php include '../includes/sidebar.php'; ?>

<main class="main-content p-4">

    <!-- Page header -->
    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1><i class="fas fa-trophy me-2"></i>Driver Performance Report</h1>
            <p>Ranked driver list based on priority score: experience, attendance, performance and certification.</p>
        </div>
        <div class="d-flex gap-2 no-print">
            <a href="reports.php" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back
            </a>
            <button class="btn btn-warning btn-sm fw-semibold" onclick="window.print()">
                <i class="fas fa-print me-1"></i> Print
            </button>
        </div>
    </div>

    <!-- Date range filter -->
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
                    <button type="submit" class="btn btn-primary fw-semibold">
                        <i class="fas fa-filter me-1"></i> Apply Filter
                    </button>
                    <a href="report_driver.php" class="btn btn-outline-secondary ms-1">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Priority Score Chart -->
    <div class="card chart-card">
        <div class="card-header border-bottom fw-semibold py-3 px-4">
            <i class="fas fa-chart-bar me-2 text-primary"></i> Priority Score Comparison
        </div>
        <div class="card-body" style="height:280px;">
            <canvas id="chartPriority"></canvas>
        </div>
    </div>

    <!-- Driver Performance Table -->
    <div class="card table-card">
        <div class="card-body p-0">
            <div class="p-3 border-bottom d-flex align-items-center justify-content-between flex-wrap gap-2">
                <h6 class="mb-0 fw-semibold text-primary">
                    <i class="fas fa-table me-1"></i> Performance Rankings
                </h6>
                <span class="text-muted small">
                    Period: <?php echo htmlspecialchars($from_date); ?> to <?php echo htmlspecialchars($to_date); ?>
                </span>
            </div>

            <div class="p-3">
                <div class="table-responsive">
                    <table id="perfTable" class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Rank</th>
                                <th>Driver Name</th>
                                <th>Experience</th>
                                <th>Attendance %</th>
                                <th>Performance</th>
                                <th>Certification</th>
                                <th>Priority Score</th>
                                <th>Total Trips</th>
                                <th>Completed</th>
                                <th>Total Hours</th>
                                <th>Completion %</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $i => $r): ?>
                            <?php
                                $rank  = $i + 1;
                                $badge = match($rank) {
                                    1 => '<span class="badge-rank rank-gold">1</span>',
                                    2 => '<span class="badge-rank rank-silver">2</span>',
                                    3 => '<span class="badge-rank rank-bronze">3</span>',
                                    default => '<span class="badge-rank rank-other">' . $rank . '</span>',
                                };
                                $ps        = $r['priority_score'];
                                $psClass   = $ps >= 7 ? 'text-success' : ($ps >= 4 ? 'text-warning' : 'text-danger');
                                $crClass   = $r['completion_rate'] >= 80 ? 'text-success' : ($r['completion_rate'] >= 50 ? 'text-warning' : 'text-danger');
                            ?>
                            <tr <?php echo $rank <= 3 ? 'class="table-active"' : ''; ?>>
                                <td><?php echo $badge; ?></td>
                                <td class="fw-semibold"><?php echo htmlspecialchars($r['name']); ?></td>
                                <td><?php echo number_format((float)$r['experience_years'], 1); ?> yrs</td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="score-bar" style="width:60px;">
                                            <div class="score-bar-fill bg-info"
                                                 style="width:<?php echo min(100,(float)$r['attendance_rate']); ?>%"></div>
                                        </div>
                                        <?php echo number_format((float)$r['attendance_rate'],1); ?>%
                                    </div>
                                </td>
                                <td><?php echo number_format((float)$r['performance_score'],2); ?>/10</td>
                                <td><?php echo number_format((float)$r['certification_score'],2); ?>/10</td>
                                <td class="fw-bold <?php echo $psClass; ?>">
                                    <?php echo number_format($ps,2); ?>
                                </td>
                                <td><?php echo (int)$r['total_trips']; ?></td>
                                <td><?php echo (int)$r['completed_trips']; ?></td>
                                <td><?php echo (int)$r['total_hours']; ?> h</td>
                                <td class="fw-semibold <?php echo $crClass; ?>">
                                    <?php echo $r['completion_rate']; ?>%
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

    $('#perfTable').DataTable({
        order: [[6, 'desc']],
        pageLength: 25,
        language: { search: 'Search drivers:' }
    });

    const labels = <?php echo json_encode($chart_labels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    const scores = <?php echo json_encode($chart_scores, JSON_HEX_TAG); ?>;
    const colors = scores.map(function(s) {
        if (s >= 7) return 'rgba(40,167,69,0.8)';
        if (s >= 4) return 'rgba(255,193,7,0.8)';
        return 'rgba(220,53,69,0.8)';
    });

    new Chart(document.getElementById('chartPriority'), {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Priority Score',
                data: scores,
                backgroundColor: colors,
                borderRadius: 5,
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
})();
</script>
</body>
</html>
