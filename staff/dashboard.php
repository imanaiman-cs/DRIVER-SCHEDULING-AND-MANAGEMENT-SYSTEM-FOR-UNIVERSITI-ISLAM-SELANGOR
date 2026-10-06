<?php
$page_title   = 'Staff Dashboard';
$current_page = 'dashboard.php';
require_once '../config/database.php';
requireStaff();

$staff_id   = (int)$_SESSION['user_id'];
$full_name  = $_SESSION['full_name'] ?? 'Staff';
$department = $_SESSION['department'] ?? '';

// ── Stat counts ──────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT
        COUNT(*)                                                    AS total,
        SUM(status = 'pending')                                     AS pending,
        SUM(status = 'approved')                                    AS approved,
        SUM(status IN ('rejected','processed'))                     AS done
    FROM vehicle_requests
    WHERE staff_id = ?
");
$stmt->bind_param('i', $staff_id);
$stmt->execute();
$counts = $stmt->get_result()->fetch_assoc();
$stmt->close();

$total_requests = (int)($counts['total']    ?? 0);
$pending_count  = (int)($counts['pending']  ?? 0);
$approved_count = (int)($counts['approved'] ?? 0);
$done_count     = (int)($counts['done']     ?? 0);

// ── Recent requests (last 5) ─────────────────────────────────────
$stmt = $conn->prepare("
    SELECT vr.*, v.plate_number
    FROM vehicle_requests vr
    LEFT JOIN vehicles v ON vr.vehicle_id = v.vehicle_id
    WHERE vr.staff_id = ?
    ORDER BY vr.created_at DESC
    LIMIT 5
");
$stmt->bind_param('i', $staff_id);
$stmt->execute();
$recent_requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── Upcoming approved/processed trips ────────────────────────────
$stmt = $conn->prepare("
    SELECT vr.*, v.plate_number
    FROM vehicle_requests vr
    LEFT JOIN vehicles v ON vr.vehicle_id = v.vehicle_id
    WHERE vr.staff_id = ?
      AND vr.status IN ('approved','processed')
      AND vr.trip_date >= CURDATE()
    ORDER BY vr.trip_date ASC, vr.start_time ASC
");
$stmt->bind_param('i', $staff_id);
$stmt->execute();
$upcoming_trips = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Dashboard | UIS Driver Management</title>
    <link rel="icon" type="image/png" href="<?php echo SITE_URL; ?>/assets/images/uis-favicon.png">
    <!-- Bootstrap 5.3 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome 6.4 -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- Google Fonts – Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <!-- Custom CSS -->
    <link href="<?= SITE_URL ?>/assets/css/style.css" rel="stylesheet">
</head>
<body>
<?php require_once '../includes/sidebar.php'; ?>

<main class="main-content p-4">
    <?php showFlash(); ?>

    <!-- Welcome Header -->
    <div style="background: linear-gradient(135deg, #0b5d3b 0%, #15804f 100%); border-radius: 14px; color: #fff; padding: 1.4rem 2rem; margin-bottom: 1.5rem;">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h4 class="fw-bold mb-1">
                    <i class="fas fa-user-circle me-2"></i>Welcome, <?= htmlspecialchars($full_name) ?>
                </h4>
                <p class="mb-0 opacity-75">
                    <i class="fas fa-building me-1"></i><?= htmlspecialchars($department !== '' ? $department : 'Universiti Islam Selangor') ?>
                    &nbsp;&bull;&nbsp;
                    <i class="fas fa-calendar-day me-1"></i><?= date('l, d F Y') ?>
                </p>
            </div>
            <a href="request_vehicle.php" class="btn btn-light btn-sm fw-semibold">
                <i class="fas fa-file-circle-plus me-1"></i>Request Vehicle
            </a>
        </div>
    </div>

    <!-- Stat Cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-card-icon bg-primary-soft"><i class="fas fa-file-signature" style="font-size:1.4rem;"></i></div>
                <div class="stat-card-body">
                    <div class="stat-card-value"><?= $total_requests ?></div>
                    <div class="stat-card-label">Total Requests</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-card-icon bg-warning-soft"><i class="fas fa-clock" style="font-size:1.4rem;"></i></div>
                <div class="stat-card-body">
                    <div class="stat-card-value"><?= $pending_count ?></div>
                    <div class="stat-card-label">Pending</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-card-icon bg-success-soft"><i class="fas fa-check-circle" style="font-size:1.4rem;"></i></div>
                <div class="stat-card-body">
                    <div class="stat-card-value"><?= $approved_count ?></div>
                    <div class="stat-card-label">Approved</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-card-icon bg-info-soft"><i class="fas fa-flag-checkered" style="font-size:1.4rem;"></i></div>
                <div class="stat-card-body">
                    <div class="stat-card-value"><?= $done_count ?></div>
                    <div class="stat-card-label">Completed/Rejected</div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- Recent Requests -->
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm h-100" style="border-radius:14px;">
                <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center px-4 py-3" style="border-radius:14px 14px 0 0;">
                    <h6 class="mb-0 fw-semibold">
                        <i class="fas fa-clock-rotate-left text-primary me-2"></i>Recent Requests
                    </h6>
                    <a href="my_requests.php" class="btn btn-sm btn-outline-primary">View All</a>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($recent_requests)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-file-circle-plus fa-3x text-muted mb-3"></i>
                        <h6 class="text-muted">No requests yet</h6>
                        <p class="text-muted small mb-3">You have not submitted any vehicle requests.</p>
                        <a href="request_vehicle.php" class="btn btn-primary btn-sm">
                            <i class="fas fa-plus me-1"></i>Request a Vehicle
                        </a>
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Trip Date</th>
                                    <th>Destination</th>
                                    <th>Vehicle</th>
                                    <th>Status</th>
                                    <th>Submitted</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_requests as $i => $r): ?>
                                <tr>
                                    <td class="fw-semibold text-muted"><?= $i + 1 ?></td>
                                    <td class="fw-semibold small"><?= formatDate($r['trip_date']) ?></td>
                                    <td>
                                        <span class="small text-truncate d-inline-block" style="max-width:180px;"
                                              title="<?= htmlspecialchars($r['destination']) ?>">
                                            <?= htmlspecialchars($r['destination']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($r['plate_number'])): ?>
                                        <span class="badge bg-secondary"><i class="fas fa-car me-1"></i><?= htmlspecialchars($r['plate_number']) ?></span>
                                        <?php else: ?>
                                        <span class="text-muted small">&mdash; any &mdash;</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge <?= requestStatusBadgeClass($r['status']) ?>">
                                            <?= ucfirst($r['status']) ?>
                                        </span>
                                    </td>
                                    <td><span class="small text-muted"><?= formatDate($r['created_at']) ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Upcoming Approved Trips -->
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm h-100" style="border-radius:14px;">
                <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center px-4 py-3" style="border-radius:14px 14px 0 0;">
                    <h6 class="mb-0 fw-semibold">
                        <i class="fas fa-route text-success me-2"></i>Upcoming Approved Trips
                    </h6>
                    <span class="badge bg-success rounded-pill"><?= count($upcoming_trips) ?></span>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($upcoming_trips)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-calendar-check fa-3x text-muted mb-3"></i>
                        <p class="text-muted small mb-0">No upcoming approved trips.</p>
                    </div>
                    <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($upcoming_trips as $t): ?>
                        <li class="list-group-item py-3 px-4">
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <div class="min-width-0">
                                    <div class="fw-semibold small"><?= htmlspecialchars($t['destination']) ?></div>
                                    <small class="text-muted">
                                        <i class="fas fa-calendar me-1"></i><?= formatDate($t['trip_date']) ?>
                                        &nbsp;<i class="fas fa-clock me-1"></i><?= substr($t['start_time'], 0, 5) ?> &ndash; <?= substr($t['end_time'], 0, 5) ?>
                                    </small>
                                </div>
                                <span class="badge <?= requestStatusBadgeClass($t['status']) ?>">
                                    <?= ucfirst($t['status']) ?>
                                </span>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<!-- Bootstrap 5.3 JS Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- Custom JS -->
<script src="<?= SITE_URL ?>/assets/js/main.js"></script>
</body>
</html>
