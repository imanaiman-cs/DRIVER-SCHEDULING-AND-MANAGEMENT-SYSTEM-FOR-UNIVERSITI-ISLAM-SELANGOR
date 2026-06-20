<?php
// ============================================================
// UIS Driver Scheduling and Management System
// admin/drivers.php  –  Manage Drivers (list view)
// Universiti Islam Selangor (UIS)
// ============================================================

require_once '../config/database.php';
requireAdmin();

$page_title   = 'Manage Drivers';
$current_page = 'drivers.php';

// ── Fetch all drivers ────────────────────────────────────────
$result  = $conn->query("SELECT * FROM drivers ORDER BY name ASC");
$drivers = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

// ── Summary counts ───────────────────────────────────────────
$total    = count($drivers);
$active   = 0;
$on_leave = 0;
$inactive = 0;

foreach ($drivers as &$d) {
    // Attach calculated priority score to each row
    $d['priority_score'] = calculatePriorityScore(
        (float)$d['experience_years'],
        (float)$d['attendance_rate'],
        (float)$d['performance_score'],
        (float)$d['certification_score']
    );

    switch ($d['status']) {
        case 'active':   $active++;   break;
        case 'on_leave': $on_leave++; break;
        case 'inactive': $inactive++; break;
    }
}
unset($d);
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
        /* ── Summary cards ── */
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
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            line-height: 1;
        }
        .stat-label {
            font-size: .8rem;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: #6c757d;
        }

        /* ── Table tweaks ── */
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

        /* ── Priority score badge ── */
        .badge-priority {
            font-size: .82rem;
            font-weight: 600;
            border-radius: 20px;
            padding: .35em .75em;
            letter-spacing: .02em;
        }
        .priority-high   { background: #d1fae5; color: #065f46; }
        .priority-medium { background: #fef3c7; color: #92400e; }
        .priority-low    { background: #fee2e2; color: #991b1b; }

        /* ── Action buttons ── */
        .btn-action {
            padding: .28rem .6rem;
            font-size: .78rem;
            border-radius: 7px;
        }

        /* ── Page header ── */
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

        /* ── View modal ── */
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
        .score-bar {
            height: 8px;
            border-radius: 4px;
            background: #e9ecef;
            overflow: hidden;
        }
        .score-bar-fill {
            height: 100%;
            border-radius: 4px;
            transition: width .6s ease;
        }
    </style>
</head>
<body>

<?php include '../includes/sidebar.php'; ?>

<!-- ── Main Content ─────────────────────────────────────────── -->
<main class="main-content p-4">

    <!-- Page header -->
    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1><i class="fas fa-users me-2" aria-hidden="true"></i>Manage Drivers</h1>
            <p>View, add, edit and manage all registered drivers at UIS.</p>
        </div>
        <a href="<?php echo SITE_URL; ?>/admin/add_driver.php" class="btn btn-warning fw-semibold">
            <i class="fas fa-user-plus me-1" aria-hidden="true"></i> Add New Driver
        </a>
    </div>

    <!-- Flash message -->
    <?php showFlash(); ?>

    <!-- ── Summary Cards ──────────────────────────────────────── -->
    <div class="row g-3 mb-4">

        <!-- Total -->
        <div class="col-6 col-md-3">
            <div class="card stat-card h-100 p-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                        <i class="fas fa-users" aria-hidden="true"></i>
                    </div>
                    <div>
                        <div class="stat-value text-primary"><?php echo $total; ?></div>
                        <div class="stat-label">Total Drivers</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Active -->
        <div class="col-6 col-md-3">
            <div class="card stat-card h-100 p-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon bg-success bg-opacity-10 text-success">
                        <i class="fas fa-circle-check" aria-hidden="true"></i>
                    </div>
                    <div>
                        <div class="stat-value text-success"><?php echo $active; ?></div>
                        <div class="stat-label">Active</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- On Leave -->
        <div class="col-6 col-md-3">
            <div class="card stat-card h-100 p-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon bg-warning bg-opacity-10 text-warning">
                        <i class="fas fa-clock" aria-hidden="true"></i>
                    </div>
                    <div>
                        <div class="stat-value text-warning"><?php echo $on_leave; ?></div>
                        <div class="stat-label">On Leave</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Inactive -->
        <div class="col-6 col-md-3">
            <div class="card stat-card h-100 p-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon bg-danger bg-opacity-10 text-danger">
                        <i class="fas fa-ban" aria-hidden="true"></i>
                    </div>
                    <div>
                        <div class="stat-value text-danger"><?php echo $inactive; ?></div>
                        <div class="stat-label">Inactive</div>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /.row summary cards -->

    <!-- ── DataTable Card ─────────────────────────────────────── -->
    <div class="card table-card">
        <div class="card-body p-0">
            <div class="p-3 border-bottom d-flex align-items-center justify-content-between flex-wrap gap-2">
                <h6 class="mb-0 fw-semibold text-primary">
                    <i class="fas fa-table me-1" aria-hidden="true"></i> All Drivers
                </h6>
                <span class="badge bg-primary bg-opacity-10 text-primary fw-normal px-3 py-2">
                    <?php echo $total; ?> record<?php echo $total !== 1 ? 's' : ''; ?>
                </span>
            </div>

            <div class="p-3">
                <div class="table-responsive">
                    <table id="driversTable" class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Employee ID</th>
                                <th>Name</th>
                                <th>Phone</th>
                                <th>Experience</th>
                                <th>Priority Score</th>
                                <th>Attendance %</th>
                                <th>Performance</th>
                                <th>Status</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($drivers as $i => $d): ?>
                            <?php
                                $score     = $d['priority_score'];
                                $scoreClass = 'priority-low';
                                if ($score >= 7)      $scoreClass = 'priority-high';
                                elseif ($score >= 4)  $scoreClass = 'priority-medium';

                                $statusClass = driverStatusBadgeClass($d['status']);
                                $statusLabel = match($d['status']) {
                                    'active'   => 'Active',
                                    'inactive' => 'Inactive',
                                    'on_leave' => 'On Leave',
                                    default    => ucfirst($d['status']),
                                };
                            ?>
                            <tr>
                                <td class="text-muted small"><?php echo $i + 1; ?></td>
                                <td>
                                    <span class="fw-medium text-primary font-monospace">
                                        <?php echo htmlspecialchars($d['employee_id']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($d['name']); ?></div>
                                    <div class="small text-muted"><?php echo htmlspecialchars($d['email'] ?? ''); ?></div>
                                </td>
                                <td class="small"><?php echo htmlspecialchars($d['phone']); ?></td>
                                <td class="small">
                                    <?php echo number_format((float)$d['experience_years'], 1); ?> yr<?php echo (float)$d['experience_years'] != 1 ? 's' : ''; ?>
                                </td>
                                <td>
                                    <span class="badge-priority <?php echo $scoreClass; ?>">
                                        <?php echo number_format($score, 2); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="score-bar flex-grow-1" style="max-width:70px;">
                                            <div class="score-bar-fill bg-info"
                                                 style="width:<?php echo min(100, (float)$d['attendance_rate']); ?>%"></div>
                                        </div>
                                        <span class="small"><?php echo number_format((float)$d['attendance_rate'], 1); ?>%</span>
                                    </div>
                                </td>
                                <td class="small"><?php echo number_format((float)$d['performance_score'], 1); ?>/10</td>
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
                                                title="View Driver"
                                                onclick="viewDriver(<?php echo (int)$d['driver_id']; ?>)"
                                                aria-label="View <?php echo htmlspecialchars($d['name']); ?>">
                                            <i class="fas fa-eye" aria-hidden="true"></i>
                                        </button>
                                        <!-- Edit -->
                                        <a href="<?php echo SITE_URL; ?>/admin/edit_driver.php?id=<?php echo (int)$d['driver_id']; ?>"
                                           class="btn btn-outline-primary btn-action"
                                           title="Edit Driver"
                                           aria-label="Edit <?php echo htmlspecialchars($d['name']); ?>">
                                            <i class="fas fa-pen-to-square" aria-hidden="true"></i>
                                        </a>
                                        <!-- Delete -->
                                        <button type="button"
                                                class="btn btn-outline-danger btn-action"
                                                title="Delete Driver"
                                                onclick="confirmDelete(<?php echo (int)$d['driver_id']; ?>, '<?php echo htmlspecialchars(addslashes($d['name'])); ?>')"
                                                aria-label="Delete <?php echo htmlspecialchars($d['name']); ?>">
                                            <i class="fas fa-trash" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div><!-- /.table-responsive -->
            </div>
        </div>
    </div><!-- /.card table-card -->

</main><!-- /.main-content -->

<!-- ================================================================
     VIEW DRIVER MODAL
     ================================================================ -->
<div class="modal fade" id="viewDriverModal" tabindex="-1" aria-labelledby="viewDriverModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content rounded-3">
            <div class="modal-header text-white"
                 style="background: linear-gradient(135deg,#003580 0%,#0056b3 100%);">
                <h5 class="modal-title fw-bold" id="viewDriverModalLabel">
                    <i class="fas fa-id-card me-2" aria-hidden="true"></i>
                    <span id="modalDriverName">Driver Details</span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4" id="viewDriverBody">
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <a href="#" id="modalEditBtn" class="btn btn-primary">
                    <i class="fas fa-pen-to-square me-1" aria-hidden="true"></i> Edit Driver
                </a>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- ── Embedded driver data (JSON for JS) ── -->
<script>
const DRIVERS_DATA = <?php
    $json_data = [];
    foreach ($drivers as $d) {
        $json_data[] = [
            'driver_id'           => (int)$d['driver_id'],
            'employee_id'         => $d['employee_id'],
            'name'                => $d['name'],
            'phone'               => $d['phone'],
            'email'               => $d['email'] ?? '',
            'address'             => $d['address'] ?? '',
            'experience_years'    => (float)$d['experience_years'],
            'attendance_rate'     => (float)$d['attendance_rate'],
            'performance_score'   => (float)$d['performance_score'],
            'certification_score' => (float)$d['certification_score'],
            'license_number'      => $d['license_number'] ?? '',
            'license_class'       => $d['license_class'] ?? '',
            'license_expiry'      => $d['license_expiry'] ?? '',
            'status'              => $d['status'],
            'priority_score'      => $d['priority_score'],
            'created_at'          => $d['created_at'] ?? '',
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

    // ── DataTable initialisation ─────────────────────────────
    $('#driversTable').DataTable({
        order:       [[5, 'desc']],   // sort by Priority Score desc by default
        pageLength:  25,
        responsive:  true,
        columnDefs: [
            { orderable: false, targets: 9 },  // Actions column not sortable
            { searchable: false, targets: 9 }
        ],
        language: {
            search:         'Search drivers:',
            lengthMenu:     'Show _MENU_ drivers per page',
            info:           'Showing _START_ to _END_ of _TOTAL_ drivers',
            infoEmpty:      'No drivers found',
            emptyTable:     'No drivers registered yet',
            zeroRecords:    'No drivers match the search'
        }
    });

    // ── View Driver Modal ────────────────────────────────────
    window.viewDriver = function (driverId) {
        const d = DRIVERS_DATA.find(function (row) { return row.driver_id === driverId; });
        if (!d) return;

        document.getElementById('modalDriverName').textContent = d.name;

        // Edit button link
        document.getElementById('modalEditBtn').href = SITE_URL + '/admin/edit_driver.php?id=' + d.driver_id;

        // Priority score badge colour
        const score     = parseFloat(d.priority_score);
        let scoreClass  = 'priority-low';
        let scoreBgBar  = '#ef4444';
        if (score >= 7)     { scoreClass = 'priority-high';   scoreBgBar = '#10b981'; }
        else if (score >= 4){ scoreClass = 'priority-medium'; scoreBgBar = '#f59e0b'; }

        // Exp score for breakdown (cap at 20 yrs → 10)
        const expScore  = Math.min((d.experience_years / 20) * 10, 10).toFixed(2);
        const attScore  = ((d.attendance_rate / 100) * 10).toFixed(2);

        // Status label/colour
        const statusMap = { active: ['Active','bg-success'], inactive: ['Inactive','bg-danger'], on_leave: ['On Leave','bg-warning text-dark'] };
        const [statusLabel, statusCls] = statusMap[d.status] || [d.status, 'bg-secondary'];

        // License expiry formatting
        const expiryFormatted = d.license_expiry
            ? new Date(d.license_expiry).toLocaleDateString('en-GB', {day:'2-digit',month:'short',year:'numeric'})
            : '&mdash;';

        // Created at
        const createdFormatted = d.created_at
            ? new Date(d.created_at).toLocaleDateString('en-GB', {day:'2-digit',month:'short',year:'numeric'})
            : '&mdash;';

        const body = `
        <div class="row g-3">

            <!-- Personal Information -->
            <div class="col-12">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <div style="width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,#003580,#0056b3);
                                display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:1.1rem;">
                        ${d.name.charAt(0).toUpperCase()}
                    </div>
                    <div>
                        <div class="fw-bold fs-5">${escHtml(d.name)}</div>
                        <span class="badge ${statusCls} rounded-pill">${statusLabel}</span>
                    </div>
                    <div class="ms-auto">
                        <span class="badge-priority ${scoreClass}" style="font-size:1rem;padding:.4em .9em;">
                            &#9733; ${score.toFixed(2)}
                        </span>
                    </div>
                </div>
                <hr class="my-2">
            </div>

            <!-- Left column -->
            <div class="col-md-6">
                <h6 class="text-primary fw-semibold mb-3">
                    <i class="fas fa-user me-1"></i> Personal Information
                </h6>
                <div class="row g-2">
                    <div class="col-6">
                        <div class="detail-label">Employee ID</div>
                        <div class="detail-value font-monospace">${escHtml(d.employee_id)}</div>
                    </div>
                    <div class="col-6">
                        <div class="detail-label">Phone</div>
                        <div class="detail-value">${escHtml(d.phone)}</div>
                    </div>
                    <div class="col-12 mt-1">
                        <div class="detail-label">Email</div>
                        <div class="detail-value">${d.email ? escHtml(d.email) : '&mdash;'}</div>
                    </div>
                    <div class="col-12 mt-1">
                        <div class="detail-label">Address</div>
                        <div class="detail-value small">${d.address ? escHtml(d.address) : '&mdash;'}</div>
                    </div>
                    <div class="col-6 mt-1">
                        <div class="detail-label">Registered</div>
                        <div class="detail-value small">${createdFormatted}</div>
                    </div>
                </div>
            </div>

            <!-- Right column -->
            <div class="col-md-6">
                <h6 class="text-primary fw-semibold mb-3">
                    <i class="fas fa-id-card me-1"></i> License Information
                </h6>
                <div class="row g-2">
                    <div class="col-6">
                        <div class="detail-label">License No.</div>
                        <div class="detail-value font-monospace">${d.license_number ? escHtml(d.license_number) : '&mdash;'}</div>
                    </div>
                    <div class="col-6">
                        <div class="detail-label">License Class</div>
                        <div class="detail-value">${d.license_class ? escHtml(d.license_class) : '&mdash;'}</div>
                    </div>
                    <div class="col-12 mt-1">
                        <div class="detail-label">License Expiry</div>
                        <div class="detail-value">${expiryFormatted}</div>
                    </div>
                    <div class="col-6 mt-1">
                        <div class="detail-label">Experience</div>
                        <div class="detail-value">${d.experience_years} year${d.experience_years !== 1 ? 's' : ''}</div>
                    </div>
                </div>
            </div>

            <!-- Priority Score Breakdown -->
            <div class="col-12">
                <hr class="my-1">
                <h6 class="text-primary fw-semibold mb-3">
                    <i class="fas fa-chart-bar me-1"></i> Priority Score Breakdown
                </h6>
                <div class="row g-3">
                    <div class="col-6 col-md-3">
                        <div class="detail-label">Experience (30%)</div>
                        <div class="detail-value mb-1">${expScore} / 10</div>
                        <div class="score-bar"><div class="score-bar-fill" style="width:${expScore*10}%;background:#3b82f6;"></div></div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="detail-label">Attendance (20%)</div>
                        <div class="detail-value mb-1">${attScore} / 10</div>
                        <div class="score-bar"><div class="score-bar-fill" style="width:${attScore*10}%;background:#06b6d4;"></div></div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="detail-label">Performance (30%)</div>
                        <div class="detail-value mb-1">${parseFloat(d.performance_score).toFixed(1)} / 10</div>
                        <div class="score-bar"><div class="score-bar-fill" style="width:${d.performance_score*10}%;background:#8b5cf6;"></div></div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="detail-label">Certification (20%)</div>
                        <div class="detail-value mb-1">${parseFloat(d.certification_score).toFixed(1)} / 10</div>
                        <div class="score-bar"><div class="score-bar-fill" style="width:${d.certification_score*10}%;background:#f59e0b;"></div></div>
                    </div>
                </div>
                <div class="mt-3 p-3 rounded-3" style="background:#f8f9fb;">
                    <div class="d-flex align-items-center justify-content-between">
                        <span class="fw-semibold text-secondary">Overall Priority Score</span>
                        <span class="badge-priority ${scoreClass}" style="font-size:.95rem;padding:.4em 1em;">
                            ${score.toFixed(2)} / 10
                        </span>
                    </div>
                    <div class="score-bar mt-2">
                        <div class="score-bar-fill" style="width:${score*10}%;background:${scoreBgBar};"></div>
                    </div>
                    <div class="mt-1 small text-muted">
                        Formula: (Exp&times;0.30) + (Att&times;0.20) + (Perf&times;0.30) + (Cert&times;0.20)
                    </div>
                </div>
            </div>

        </div>`;

        document.getElementById('viewDriverBody').innerHTML = body;

        const modal = new bootstrap.Modal(document.getElementById('viewDriverModal'));
        modal.show();
    };

    // ── Delete with SweetAlert2 ─────────────────────────────
    window.confirmDelete = function (driverId, driverName) {
        Swal.fire({
            title:              'Delete Driver?',
            html:               `Are you sure you want to delete <strong>${escHtml(driverName)}</strong>?<br><small class="text-muted">This action cannot be undone.</small>`,
            icon:               'warning',
            showCancelButton:   true,
            confirmButtonColor: '#dc3545',
            cancelButtonColor:  '#6c757d',
            confirmButtonText:  '<i class="fas fa-trash me-1"></i> Yes, Delete',
            cancelButtonText:   'Cancel',
            focusCancel:        true,
        }).then(function (result) {
            if (!result.isConfirmed) return;

            Swal.fire({
                title:            'Deleting...',
                text:             'Please wait.',
                allowOutsideClick: false,
                didOpen: function () { Swal.showLoading(); }
            });

            $.ajax({
                url:    SITE_URL + '/ajax/delete_driver.php',
                method: 'POST',
                data:   { id: driverId },
                dataType: 'json'
            })
            .done(function (res) {
                if (res.success) {
                    Swal.fire({
                        icon:  'success',
                        title: 'Deleted!',
                        text:  res.message,
                        timer: 1800,
                        showConfirmButton: false
                    }).then(function () { location.reload(); });
                } else {
                    Swal.fire({ icon: 'error', title: 'Cannot Delete', text: res.message });
                }
            })
            .fail(function () {
                Swal.fire({ icon: 'error', title: 'Error', text: 'A network error occurred. Please try again.' });
            });
        });
    };

    // ── HTML escape helper ──────────────────────────────────
    function escHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

})();
</script>

</body>
</html>
