<?php
// ============================================================
// UIS Driver Scheduling and Management System
// admin/schedules.php  –  Manage Schedules (list view)
// Universiti Islam Selangor (UIS)
// ============================================================

require_once '../config/database.php';
requireAdmin();

$page_title   = 'Manage Schedules';
$current_page = 'schedules.php';

// ── Filters from GET ─────────────────────────────────────────
$filter_status     = trim($_GET['status']      ?? '');
$filter_date_from  = trim($_GET['date_from']   ?? '');
$filter_date_to    = trim($_GET['date_to']     ?? '');
$filter_driver     = (int)($_GET['driver_id']  ?? 0);

// ── Build the base query ─────────────────────────────────────
$where  = [];
$params = [];
$types  = '';

if ($filter_status !== '') {
    $where[]  = 's.status = ?';
    $params[] = $filter_status;
    $types   .= 's';
}
if ($filter_date_from !== '') {
    $where[]  = 's.trip_date >= ?';
    $params[] = $filter_date_from;
    $types   .= 's';
}
if ($filter_date_to !== '') {
    $where[]  = 's.trip_date <= ?';
    $params[] = $filter_date_to;
    $types   .= 's';
}
if ($filter_driver > 0) {
    $where[]  = 's.driver_id = ?';
    $params[] = $filter_driver;
    $types   .= 'i';
}

$where_sql = count($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

$sql = "SELECT
            s.schedule_id,
            s.trip_date,
            s.start_time,
            s.end_time,
            s.destination,
            s.purpose,
            s.passenger_count,
            s.status,
            s.priority_score,
            s.notes,
            s.created_at,
            d.driver_id,
            d.name        AS driver_name,
            d.employee_id AS driver_employee_id,
            v.vehicle_id,
            v.plate_number,
            v.vehicle_type,
            v.capacity
        FROM schedules s
        LEFT JOIN drivers  d ON s.driver_id  = d.driver_id
        LEFT JOIN vehicles v ON s.vehicle_id = v.vehicle_id
        {$where_sql}
        ORDER BY s.trip_date DESC, s.start_time DESC";

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$schedules = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── Summary counts ───────────────────────────────────────────
$total      = count($schedules);
$pending    = 0;
$in_progress = 0;
$this_month_completed = 0;
$current_month = date('Y-m');

foreach ($schedules as $s) {
    if ($s['status'] === 'pending')     $pending++;
    if ($s['status'] === 'in_progress') $in_progress++;
    if ($s['status'] === 'completed' && substr($s['trip_date'], 0, 7) === $current_month) {
        $this_month_completed++;
    }
}

// ── All active drivers for filter dropdown ───────────────────
$drivers_result = $conn->query("SELECT driver_id, name, employee_id FROM drivers WHERE status = 'active' ORDER BY name ASC");
$all_drivers    = $drivers_result ? $drivers_result->fetch_all(MYSQLI_ASSOC) : [];

// ── Count unassigned pending schedules ───────────────────────
$unassigned_count_result = $conn->query("SELECT COUNT(*) AS cnt FROM schedules WHERE driver_id IS NULL AND status = 'pending'");
$unassigned_count = (int)($unassigned_count_result->fetch_assoc()['cnt'] ?? 0);

// ── Handle CSV export ────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="schedules_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Schedule ID','Trip Date','Start Time','End Time','Destination','Purpose','Passengers','Driver','Vehicle','Status','Priority Score','Notes','Created At']);
    foreach ($schedules as $s) {
        fputcsv($out, [
            $s['schedule_id'],
            $s['trip_date'],
            $s['start_time'],
            $s['end_time'],
            $s['destination'],
            $s['purpose'] ?? '',
            $s['passenger_count'],
            $s['driver_name'] ?? 'Unassigned',
            $s['plate_number'] ? ($s['plate_number'] . ' (' . $s['vehicle_type'] . ')') : 'Not Assigned',
            $s['status'],
            $s['priority_score'],
            $s['notes'] ?? '',
            $s['created_at'],
        ]);
    }
    fclose($out);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> | UIS Driver Management</title>

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome 6 -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- DataTables Bootstrap 5 -->
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Custom CSS -->
    <link href="<?php echo SITE_URL; ?>/assets/css/style.css" rel="stylesheet">

    <style>
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
        .stat-card .stat-icon {
            width: 52px; height: 52px;
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.35rem;
        }
        .stat-value { font-size: 2rem; font-weight: 700; line-height: 1; }
        .stat-label { font-size: .8rem; text-transform: uppercase; letter-spacing: .06em; color: #6c757d; }

        .table-card {
            border: none;
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 2px 12px rgba(0,53,128,.10);
        }
        .table thead th {
            background: #f8f9fb;
            font-size: .78rem;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: #4b5563;
            border-bottom: 2px solid #e5e7eb;
            white-space: nowrap;
        }
        .table tbody tr:hover { background: #f0f5ff; }

        .btn-action {
            padding: .28rem .6rem;
            font-size: .78rem;
            border-radius: 7px;
        }
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

        .filter-card {
            border: none;
            border-radius: 14px;
            box-shadow: 0 2px 12px rgba(0,53,128,.07);
            margin-bottom: 1.25rem;
        }

        /* Detail modal */
        .detail-label {
            font-size: .75rem;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: #6c757d;
            margin-bottom: .15rem;
        }
        .detail-value {
            font-weight: 600;
            color: #1a2035;
            word-break: break-word;
        }

        /* Status badge text tweaks */
        .badge.status-pending     { background: #fef3c7; color: #92400e; }
        .badge.status-approved    { background: #dbeafe; color: #1e40af; }
        .badge.status-in_progress { background: #cffafe; color: #155e75; }
        .badge.status-completed   { background: #d1fae5; color: #065f46; }
        .badge.status-cancelled   { background: #f3f4f6; color: #4b5563; }
    </style>
</head>
<body>

<?php include '../includes/sidebar.php'; ?>

<main class="main-content p-4">

    <!-- Page header -->
    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1><i class="fas fa-calendar-days me-2" aria-hidden="true"></i>Manage Schedules</h1>
            <p>View, create, edit and manage all trip schedules at UIS.</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <?php if ($unassigned_count > 0): ?>
            <a href="<?php echo SITE_URL; ?>/admin/auto_assign.php" class="btn btn-warning fw-semibold">
                <i class="fas fa-wand-magic-sparkles me-1" aria-hidden="true"></i>
                Auto Assign All
                <span class="badge bg-dark ms-1"><?php echo $unassigned_count; ?></span>
            </a>
            <?php endif; ?>
            <a href="<?php echo SITE_URL; ?>/admin/add_schedule.php" class="btn btn-light fw-semibold text-primary">
                <i class="fas fa-calendar-plus me-1" aria-hidden="true"></i> Create Schedule
            </a>
        </div>
    </div>

    <!-- Flash message -->
    <?php showFlash(); ?>

    <!-- ── Summary Cards ─────────────────────────────────────── -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card stat-card h-100 p-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                        <i class="fas fa-calendar-days" aria-hidden="true"></i>
                    </div>
                    <div>
                        <div class="stat-value text-primary"><?php echo $total; ?></div>
                        <div class="stat-label">Total</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card stat-card h-100 p-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon bg-warning bg-opacity-10 text-warning">
                        <i class="fas fa-hourglass-half" aria-hidden="true"></i>
                    </div>
                    <div>
                        <div class="stat-value text-warning"><?php echo $pending; ?></div>
                        <div class="stat-label">Pending</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card stat-card h-100 p-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon bg-info bg-opacity-10 text-info">
                        <i class="fas fa-truck-moving" aria-hidden="true"></i>
                    </div>
                    <div>
                        <div class="stat-value text-info"><?php echo $in_progress; ?></div>
                        <div class="stat-label">In Progress</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card stat-card h-100 p-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon bg-success bg-opacity-10 text-success">
                        <i class="fas fa-circle-check" aria-hidden="true"></i>
                    </div>
                    <div>
                        <div class="stat-value text-success"><?php echo $this_month_completed; ?></div>
                        <div class="stat-label">Completed (Month)</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Filter Bar ─────────────────────────────────────────── -->
    <div class="card filter-card">
        <div class="card-body py-3">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-12 col-sm-6 col-md-2">
                    <label class="form-label small fw-semibold mb-1">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All Statuses</option>
                        <?php foreach (['pending','approved','in_progress','completed','cancelled'] as $st): ?>
                        <option value="<?php echo $st; ?>" <?php echo $filter_status === $st ? 'selected' : ''; ?>>
                            <?php echo ucwords(str_replace('_',' ', $st)); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-sm-6 col-md-2">
                    <label class="form-label small fw-semibold mb-1">Date From</label>
                    <input type="date" name="date_from" class="form-control form-control-sm"
                           value="<?php echo htmlspecialchars($filter_date_from); ?>">
                </div>
                <div class="col-12 col-sm-6 col-md-2">
                    <label class="form-label small fw-semibold mb-1">Date To</label>
                    <input type="date" name="date_to" class="form-control form-control-sm"
                           value="<?php echo htmlspecialchars($filter_date_to); ?>">
                </div>
                <div class="col-12 col-sm-6 col-md-3">
                    <label class="form-label small fw-semibold mb-1">Driver</label>
                    <select name="driver_id" class="form-select form-select-sm">
                        <option value="">All Drivers</option>
                        <?php foreach ($all_drivers as $dr): ?>
                        <option value="<?php echo (int)$dr['driver_id']; ?>"
                            <?php echo $filter_driver === (int)$dr['driver_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($dr['name'] . ' (' . $dr['employee_id'] . ')'); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm px-3">
                        <i class="fas fa-filter me-1" aria-hidden="true"></i> Filter
                    </button>
                    <a href="<?php echo SITE_URL; ?>/admin/schedules.php" class="btn btn-outline-secondary btn-sm px-3">
                        <i class="fas fa-rotate-left me-1" aria-hidden="true"></i> Reset
                    </a>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>"
                       class="btn btn-outline-success btn-sm px-3 ms-auto">
                        <i class="fas fa-file-csv me-1" aria-hidden="true"></i> CSV
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- ── DataTable Card ─────────────────────────────────────── -->
    <div class="card table-card">
        <div class="card-body p-0">
            <div class="p-3 border-bottom d-flex align-items-center justify-content-between flex-wrap gap-2">
                <h6 class="mb-0 fw-semibold text-primary">
                    <i class="fas fa-table me-1" aria-hidden="true"></i> All Schedules
                </h6>
                <span class="badge bg-primary bg-opacity-10 text-primary fw-normal px-3 py-2">
                    <?php echo $total; ?> record<?php echo $total !== 1 ? 's' : ''; ?>
                </span>
            </div>
            <div class="p-3">
                <div class="table-responsive">
                    <table id="schedulesTable" class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Schedule ID</th>
                                <th>Destination</th>
                                <th>Driver</th>
                                <th>Vehicle</th>
                                <th>Date</th>
                                <th>Time</th>
                                <th>Status</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($schedules as $i => $s): ?>
                            <?php
                                $statusClass = match($s['status']) {
                                    'pending'     => 'status-pending',
                                    'approved'    => 'status-approved',
                                    'in_progress' => 'status-in_progress',
                                    'completed'   => 'status-completed',
                                    'cancelled'   => 'status-cancelled',
                                    default       => 'bg-secondary',
                                };
                                $statusLabel = ucwords(str_replace('_', ' ', $s['status']));
                            ?>
                            <tr>
                                <td class="text-muted small"><?php echo $i + 1; ?></td>
                                <td>
                                    <span class="fw-medium text-primary font-monospace small">
                                        #<?php echo str_pad($s['schedule_id'], 4, '0', STR_PAD_LEFT); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($s['destination']); ?></div>
                                    <?php if ($s['purpose']): ?>
                                    <div class="small text-muted"><?php echo htmlspecialchars($s['purpose']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($s['driver_name']): ?>
                                        <div class="fw-medium"><?php echo htmlspecialchars($s['driver_name']); ?></div>
                                        <div class="small text-muted font-monospace"><?php echo htmlspecialchars($s['driver_employee_id']); ?></div>
                                    <?php else: ?>
                                        <span class="badge bg-danger rounded-pill">Unassigned</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($s['plate_number']): ?>
                                        <div class="fw-medium font-monospace"><?php echo htmlspecialchars($s['plate_number']); ?></div>
                                        <div class="small text-muted"><?php echo htmlspecialchars($s['vehicle_type']); ?> &bull; <?php echo (int)$s['capacity']; ?> pax</div>
                                    <?php else: ?>
                                        <span class="text-muted small">Not Assigned</span>
                                    <?php endif; ?>
                                </td>
                                <td class="small fw-medium"><?php echo formatDate($s['trip_date']); ?></td>
                                <td class="small text-nowrap">
                                    <?php echo formatTime($s['start_time']); ?> &ndash; <?php echo formatTime($s['end_time']); ?>
                                </td>
                                <td>
                                    <span class="badge <?php echo $statusClass; ?> rounded-pill">
                                        <?php echo $statusLabel; ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <div class="d-flex justify-content-center gap-1 flex-nowrap">
                                        <!-- View -->
                                        <button type="button"
                                                class="btn btn-outline-info btn-action"
                                                title="View Details"
                                                onclick="viewSchedule(<?php echo (int)$s['schedule_id']; ?>)"
                                                aria-label="View schedule #<?php echo $s['schedule_id']; ?>">
                                            <i class="fas fa-eye" aria-hidden="true"></i>
                                        </button>
                                        <!-- Edit -->
                                        <a href="<?php echo SITE_URL; ?>/admin/edit_schedule.php?id=<?php echo (int)$s['schedule_id']; ?>"
                                           class="btn btn-outline-primary btn-action"
                                           title="Edit Schedule"
                                           aria-label="Edit schedule #<?php echo $s['schedule_id']; ?>">
                                            <i class="fas fa-pen-to-square" aria-hidden="true"></i>
                                        </a>
                                        <?php if ($s['driver_name'] === null && $s['status'] === 'pending'): ?>
                                        <!-- Assign Driver -->
                                        <a href="<?php echo SITE_URL; ?>/admin/auto_assign.php?schedule_id=<?php echo (int)$s['schedule_id']; ?>"
                                           class="btn btn-outline-warning btn-action"
                                           title="Assign Driver"
                                           aria-label="Assign driver to schedule #<?php echo $s['schedule_id']; ?>">
                                            <i class="fas fa-user-plus" aria-hidden="true"></i>
                                        </a>
                                        <?php endif; ?>
                                        <!-- Delete -->
                                        <button type="button"
                                                class="btn btn-outline-danger btn-action"
                                                title="Delete Schedule"
                                                onclick="confirmDelete(<?php echo (int)$s['schedule_id']; ?>, '<?php echo htmlspecialchars(addslashes($s['destination'])); ?>')"
                                                aria-label="Delete schedule #<?php echo $s['schedule_id']; ?>">
                                            <i class="fas fa-trash" aria-hidden="true"></i>
                                        </button>
                                    </div>
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

<!-- ================================================================
     VIEW SCHEDULE MODAL
     ================================================================ -->
<div class="modal fade" id="viewScheduleModal" tabindex="-1" aria-labelledby="viewScheduleModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content rounded-3">
            <div class="modal-header text-white" style="background: linear-gradient(135deg,#003580 0%,#0056b3 100%);">
                <h5 class="modal-title fw-bold" id="viewScheduleModalLabel">
                    <i class="fas fa-calendar-check me-2" aria-hidden="true"></i>
                    <span id="modalScheduleTitle">Schedule Details</span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4" id="viewScheduleBody">
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <a href="#" id="modalEditScheduleBtn" class="btn btn-primary">
                    <i class="fas fa-pen-to-square me-1" aria-hidden="true"></i> Edit Schedule
                </a>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Embedded schedule data for JS -->
<script>
const SCHEDULES_DATA = <?php
    $json_data = [];
    foreach ($schedules as $s) {
        $json_data[] = [
            'schedule_id'        => (int)$s['schedule_id'],
            'trip_date'          => $s['trip_date'],
            'start_time'         => $s['start_time'],
            'end_time'           => $s['end_time'],
            'destination'        => $s['destination'],
            'purpose'            => $s['purpose'] ?? '',
            'passenger_count'    => (int)$s['passenger_count'],
            'status'             => $s['status'],
            'priority_score'     => (float)$s['priority_score'],
            'notes'              => $s['notes'] ?? '',
            'created_at'         => $s['created_at'],
            'driver_name'        => $s['driver_name'],
            'driver_employee_id' => $s['driver_employee_id'],
            'plate_number'       => $s['plate_number'],
            'vehicle_type'       => $s['vehicle_type'],
            'capacity'           => (int)($s['capacity'] ?? 0),
        ];
    }
    echo json_encode($json_data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
?>;
const SITE_URL = '<?php echo SITE_URL; ?>';
</script>

<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<!-- Bootstrap 5 JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- DataTables -->
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<!-- Custom JS -->
<script src="<?php echo SITE_URL; ?>/assets/js/main.js"></script>

<script>
(function () {
    'use strict';

    // ── DataTable ────────────────────────────────────────────
    $('#schedulesTable').DataTable({
        order:      [[5, 'desc']],
        pageLength: 25,
        responsive: true,
        columnDefs: [
            { orderable: false, targets: 8 },
            { searchable: false, targets: 8 }
        ],
        language: {
            search:      'Search schedules:',
            lengthMenu:  'Show _MENU_ schedules per page',
            info:        'Showing _START_ to _END_ of _TOTAL_ schedules',
            infoEmpty:   'No schedules found',
            emptyTable:  'No schedules created yet',
            zeroRecords: 'No schedules match the search'
        }
    });

    // ── HTML escape helper ────────────────────────────────────
    function escHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function formatDate(d) {
        if (!d) return '&mdash;';
        const dt = new Date(d + 'T00:00:00');
        return dt.toLocaleDateString('en-GB', {day:'2-digit', month:'short', year:'numeric'});
    }

    function formatTime(t) {
        if (!t) return '&mdash;';
        const [h, m] = t.split(':');
        const hour = parseInt(h, 10);
        return (hour % 12 || 12) + ':' + m + ' ' + (hour < 12 ? 'am' : 'pm');
    }

    // ── View Schedule Modal ──────────────────────────────────
    window.viewSchedule = function (scheduleId) {
        const s = SCHEDULES_DATA.find(function (r) { return r.schedule_id === scheduleId; });
        if (!s) return;

        document.getElementById('modalScheduleTitle').textContent = 'Schedule #' + String(s.schedule_id).padStart(4, '0');
        document.getElementById('modalEditScheduleBtn').href = SITE_URL + '/admin/edit_schedule.php?id=' + s.schedule_id;

        const statusMap = {
            pending:     ['Pending',     'status-pending'],
            approved:    ['Approved',    'status-approved'],
            in_progress: ['In Progress', 'status-in_progress'],
            completed:   ['Completed',   'status-completed'],
            cancelled:   ['Cancelled',   'status-cancelled'],
        };
        const [statusLabel, statusCls] = statusMap[s.status] || [s.status, 'bg-secondary'];

        const body = `
        <div class="row g-3">
            <div class="col-12 d-flex align-items-center gap-3 pb-2 border-bottom">
                <div>
                    <h5 class="fw-bold mb-1">${escHtml(s.destination)}</h5>
                    <span class="small text-muted">${s.purpose ? escHtml(s.purpose) : 'No purpose specified'}</span>
                </div>
                <div class="ms-auto">
                    <span class="badge ${statusCls} rounded-pill fs-6">${statusLabel}</span>
                </div>
            </div>
            <div class="col-md-6">
                <h6 class="text-primary fw-semibold mb-3"><i class="fas fa-calendar me-1"></i> Trip Details</h6>
                <div class="row g-2">
                    <div class="col-6">
                        <div class="detail-label">Trip Date</div>
                        <div class="detail-value">${formatDate(s.trip_date)}</div>
                    </div>
                    <div class="col-6">
                        <div class="detail-label">Time</div>
                        <div class="detail-value">${formatTime(s.start_time)} &ndash; ${formatTime(s.end_time)}</div>
                    </div>
                    <div class="col-6 mt-1">
                        <div class="detail-label">Passengers</div>
                        <div class="detail-value">${s.passenger_count}</div>
                    </div>
                    <div class="col-6 mt-1">
                        <div class="detail-label">Priority Score</div>
                        <div class="detail-value">${parseFloat(s.priority_score).toFixed(2)}</div>
                    </div>
                    ${s.notes ? `<div class="col-12 mt-1">
                        <div class="detail-label">Notes</div>
                        <div class="detail-value small">${escHtml(s.notes)}</div>
                    </div>` : ''}
                </div>
            </div>
            <div class="col-md-6">
                <h6 class="text-primary fw-semibold mb-3"><i class="fas fa-users me-1"></i> Assignment</h6>
                <div class="row g-2">
                    <div class="col-12">
                        <div class="detail-label">Driver</div>
                        <div class="detail-value">
                            ${s.driver_name
                                ? escHtml(s.driver_name) + '<span class="small text-muted ms-2 font-monospace">' + escHtml(s.driver_employee_id) + '</span>'
                                : '<span class="badge bg-danger rounded-pill">Unassigned</span>'}
                        </div>
                    </div>
                    <div class="col-12 mt-1">
                        <div class="detail-label">Vehicle</div>
                        <div class="detail-value">
                            ${s.plate_number
                                ? '<span class="font-monospace">' + escHtml(s.plate_number) + '</span> &bull; ' + escHtml(s.vehicle_type) + ' (' + s.capacity + ' pax)'
                                : '<span class="text-muted">Not Assigned</span>'}
                        </div>
                    </div>
                    <div class="col-12 mt-1">
                        <div class="detail-label">Created</div>
                        <div class="detail-value small">${s.created_at ? new Date(s.created_at).toLocaleString('en-GB') : '&mdash;'}</div>
                    </div>
                </div>
            </div>
        </div>`;

        document.getElementById('viewScheduleBody').innerHTML = body;
        new bootstrap.Modal(document.getElementById('viewScheduleModal')).show();
    };

    // ── Delete with SweetAlert2 ──────────────────────────────
    window.confirmDelete = function (scheduleId, destination) {
        Swal.fire({
            title:              'Delete Schedule?',
            html:               `Are you sure you want to delete the schedule to <strong>${escHtml(destination)}</strong>?<br><small class="text-muted">This action cannot be undone.</small>`,
            icon:               'warning',
            showCancelButton:   true,
            confirmButtonColor: '#dc3545',
            cancelButtonColor:  '#6c757d',
            confirmButtonText:  '<i class="fas fa-trash me-1"></i> Yes, Delete',
            cancelButtonText:   'Cancel',
            focusCancel:        true,
        }).then(function (result) {
            if (!result.isConfirmed) return;

            Swal.fire({ title: 'Deleting...', text: 'Please wait.', allowOutsideClick: false, didOpen: function () { Swal.showLoading(); } });

            $.ajax({
                url:      SITE_URL + '/ajax/delete_schedule.php',
                method:   'POST',
                data:     { id: scheduleId },
                dataType: 'json'
            }).done(function (res) {
                if (res.success) {
                    Swal.fire({ icon: 'success', title: 'Deleted!', text: res.message, timer: 1800, showConfirmButton: false })
                        .then(function () { location.reload(); });
                } else {
                    Swal.fire({ icon: 'error', title: 'Cannot Delete', text: res.message });
                }
            }).fail(function () {
                Swal.fire({ icon: 'error', title: 'Error', text: 'A network error occurred. Please try again.' });
            });
        });
    };

})();
</script>

</body>
</html>
