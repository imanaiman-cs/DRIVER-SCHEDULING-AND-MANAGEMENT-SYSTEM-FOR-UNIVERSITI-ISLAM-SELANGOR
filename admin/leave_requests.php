<?php
// ============================================================
// UIS Driver Scheduling and Management System
// admin/leave_requests.php  –  Manage Driver Leave Requests
// Universiti Islam Selangor (UIS)
// ============================================================

require_once '../config/database.php';
requireAdmin();

$page_title   = 'Leave Requests';
$current_page = 'leave_requests.php';

// ── Fetch all leave requests ─────────────────────────────────
$sql = "
    SELECT lr.*, d.name AS driver_name, d.employee_id, u.full_name AS reviewer_name
    FROM leave_requests lr
    JOIN drivers d ON lr.driver_id = d.driver_id
    LEFT JOIN users u ON lr.reviewed_by = u.user_id
    ORDER BY
        CASE lr.status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END,
        lr.created_at DESC
";
$result       = $conn->query($sql);
$all_requests = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

// ── Summary counts ───────────────────────────────────────────
$total    = count($all_requests);
$pending  = 0;
$approved = 0;
$rejected = 0;

foreach ($all_requests as $r) {
    switch ($r['status']) {
        case 'pending':  $pending++;  break;
        case 'approved': $approved++; break;
        case 'rejected': $rejected++; break;
    }
}

// ── Apply tab filter ─────────────────────────────────────────
$active_tab = $_GET['status'] ?? 'all';
$active_tab = in_array($active_tab, ['all', 'pending', 'approved', 'rejected'], true)
    ? $active_tab
    : 'all';

$display_requests = $active_tab === 'all'
    ? $all_requests
    : array_filter($all_requests, fn($r) => $r['status'] === $active_tab);
$display_requests = array_values($display_requests);
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
        .stat-icon {
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

        /* ── Table card ── */
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

        /* ── Action buttons ── */
        .btn-action {
            padding: .28rem .6rem;
            font-size: .78rem;
            border-radius: 7px;
        }

        /* ── Status tabs ── */
        .status-tabs .nav-link {
            border-radius: 8px;
            font-size: .84rem;
            font-weight: 500;
            color: #6c757d;
            padding: .45rem 1rem;
        }
        .status-tabs .nav-link.active {
            background: #003580;
            color: #fff;
        }
        .status-tabs .nav-link:not(.active):hover {
            background: #f0f5ff;
            color: #003580;
        }

        /* ── Detail modal ── */
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
    </style>
</head>
<body>

<?php include '../includes/sidebar.php'; ?>

<!-- ── Main Content ─────────────────────────────────────────── -->
<main class="main-content p-4">

    <!-- Page header -->
    <div class="page-header">
        <h1><i class="fas fa-calendar-xmark me-2" aria-hidden="true"></i>Leave Requests</h1>
        <p>Review and manage driver leave requests submitted to UIS.</p>
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
                        <i class="fas fa-file-lines" aria-hidden="true"></i>
                    </div>
                    <div>
                        <div class="stat-value text-primary"><?php echo $total; ?></div>
                        <div class="stat-label">Total</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pending -->
        <div class="col-6 col-md-3">
            <a href="?status=pending" class="text-decoration-none">
                <div class="card stat-card h-100 p-3">
                    <div class="d-flex align-items-center gap-3">
                        <div class="stat-icon bg-warning bg-opacity-10 text-warning">
                            <i class="fas fa-clock" aria-hidden="true"></i>
                        </div>
                        <div>
                            <div class="stat-value text-warning"><?php echo $pending; ?></div>
                            <div class="stat-label">Pending</div>
                        </div>
                    </div>
                </div>
            </a>
        </div>

        <!-- Approved -->
        <div class="col-6 col-md-3">
            <a href="?status=approved" class="text-decoration-none">
                <div class="card stat-card h-100 p-3">
                    <div class="d-flex align-items-center gap-3">
                        <div class="stat-icon bg-success bg-opacity-10 text-success">
                            <i class="fas fa-circle-check" aria-hidden="true"></i>
                        </div>
                        <div>
                            <div class="stat-value text-success"><?php echo $approved; ?></div>
                            <div class="stat-label">Approved</div>
                        </div>
                    </div>
                </div>
            </a>
        </div>

        <!-- Rejected -->
        <div class="col-6 col-md-3">
            <a href="?status=rejected" class="text-decoration-none">
                <div class="card stat-card h-100 p-3">
                    <div class="d-flex align-items-center gap-3">
                        <div class="stat-icon bg-danger bg-opacity-10 text-danger">
                            <i class="fas fa-circle-xmark" aria-hidden="true"></i>
                        </div>
                        <div>
                            <div class="stat-value text-danger"><?php echo $rejected; ?></div>
                            <div class="stat-label">Rejected</div>
                        </div>
                    </div>
                </div>
            </a>
        </div>

    </div><!-- /.row summary cards -->

    <!-- ── Table Card ────────────────────────────────────────── -->
    <div class="card table-card">
        <div class="card-body p-0">

            <!-- Card header with tabs -->
            <div class="p-3 border-bottom d-flex align-items-center justify-content-between flex-wrap gap-3">
                <h6 class="mb-0 fw-semibold text-primary">
                    <i class="fas fa-table me-1" aria-hidden="true"></i> Leave Requests
                </h6>
                <!-- Status Tabs -->
                <ul class="nav status-tabs gap-1 mb-0">
                    <li class="nav-item">
                        <a class="nav-link <?php echo $active_tab === 'all'      ? 'active' : ''; ?>"
                           href="?status=all">
                            All
                            <span class="badge ms-1 <?php echo $active_tab === 'all' ? 'bg-white text-primary' : 'bg-primary'; ?> rounded-pill">
                                <?php echo $total; ?>
                            </span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $active_tab === 'pending'  ? 'active' : ''; ?>"
                           href="?status=pending">
                            Pending
                            <?php if ($pending > 0): ?>
                            <span class="badge ms-1 <?php echo $active_tab === 'pending' ? 'bg-white text-warning' : 'bg-warning text-dark'; ?> rounded-pill">
                                <?php echo $pending; ?>
                            </span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $active_tab === 'approved' ? 'active' : ''; ?>"
                           href="?status=approved">
                            Approved
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $active_tab === 'rejected' ? 'active' : ''; ?>"
                           href="?status=rejected">
                            Rejected
                        </a>
                    </li>
                </ul>
            </div>

            <!-- Table -->
            <div class="p-3">
                <div class="table-responsive">
                    <table id="leaveTable" class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Driver</th>
                                <th>Type</th>
                                <th>Period</th>
                                <th>Submitted</th>
                                <th>Status</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($display_requests as $i => $req): ?>
                            <?php
                                // Leave type badge
                                $type_badge = match($req['leave_type']) {
                                    'emergency' => 'bg-danger',
                                    'medical'   => 'bg-warning text-dark',
                                    'annual'    => 'bg-info text-dark',
                                    'personal'  => 'bg-secondary',
                                    default     => 'bg-secondary',
                                };
                                $type_label = ucfirst($req['leave_type']);

                                // Status badge
                                $status_badge = match($req['status']) {
                                    'pending'  => 'bg-warning text-dark',
                                    'approved' => 'bg-success',
                                    'rejected' => 'bg-danger',
                                    default    => 'bg-secondary',
                                };
                                $status_label = ucfirst($req['status']);

                                // Calculate duration in days
                                $start_ts  = strtotime($req['start_date']);
                                $end_ts    = strtotime($req['end_date']);
                                $days      = max(1, (int)(($end_ts - $start_ts) / 86400) + 1);

                                // Submitted date
                                $submitted = date('d M Y', strtotime($req['created_at']));
                            ?>
                            <tr>
                                <td class="text-muted small"><?php echo $i + 1; ?></td>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($req['driver_name']); ?></div>
                                    <div class="small text-muted font-monospace"><?php echo htmlspecialchars($req['employee_id']); ?></div>
                                </td>
                                <td>
                                    <span class="badge <?php echo $type_badge; ?> rounded-pill">
                                        <?php echo htmlspecialchars($type_label); ?>
                                    </span>
                                </td>
                                <td class="small">
                                    <div class="fw-medium">
                                        <?php echo date('d M Y', strtotime($req['start_date'])); ?>
                                        &rarr;
                                        <?php echo date('d M Y', strtotime($req['end_date'])); ?>
                                    </div>
                                    <div class="text-muted">
                                        <?php echo $days; ?> day<?php echo $days !== 1 ? 's' : ''; ?>
                                    </div>
                                </td>
                                <td class="small text-muted"><?php echo $submitted; ?></td>
                                <td>
                                    <span class="badge <?php echo $status_badge; ?> rounded-pill">
                                        <?php echo htmlspecialchars($status_label); ?>
                                    </span>
                                    <?php if (!empty($req['reviewer_name']) && $req['status'] !== 'pending'): ?>
                                    <div class="small text-muted mt-1">
                                        by <?php echo htmlspecialchars($req['reviewer_name']); ?>
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <div class="d-flex justify-content-center gap-1 flex-nowrap">
                                        <!-- View Details -->
                                        <button type="button"
                                                class="btn btn-outline-info btn-action"
                                                title="View Details"
                                                onclick="viewLeave(<?php echo (int)$req['request_id']; ?>)"
                                                aria-label="View leave request details">
                                            <i class="fas fa-eye" aria-hidden="true"></i>
                                        </button>
                                        <?php if ($req['status'] === 'pending'): ?>
                                        <!-- Approve -->
                                        <button type="button"
                                                class="btn btn-outline-success btn-action"
                                                title="Approve"
                                                onclick="openAction(<?php echo (int)$req['request_id']; ?>, 'approve', '<?php echo htmlspecialchars(addslashes($req['driver_name'])); ?>')"
                                                aria-label="Approve leave request">
                                            <i class="fas fa-check" aria-hidden="true"></i>
                                        </button>
                                        <!-- Reject -->
                                        <button type="button"
                                                class="btn btn-outline-danger btn-action"
                                                title="Reject"
                                                onclick="openAction(<?php echo (int)$req['request_id']; ?>, 'reject', '<?php echo htmlspecialchars(addslashes($req['driver_name'])); ?>')"
                                                aria-label="Reject leave request">
                                            <i class="fas fa-xmark" aria-hidden="true"></i>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div><!-- /.table-responsive -->

                <?php if (empty($display_requests)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="fas fa-calendar-check fa-3x mb-3 opacity-25"></i>
                    <p class="mb-0">No <?php echo $active_tab !== 'all' ? $active_tab . ' ' : ''; ?>leave requests found.</p>
                </div>
                <?php endif; ?>

            </div><!-- /p-3 -->
        </div><!-- /.card-body -->
    </div><!-- /.table-card -->

</main><!-- /.main-content -->


<!-- ================================================================
     VIEW DETAILS MODAL
     ================================================================ -->
<div class="modal fade" id="viewLeaveModal" tabindex="-1" aria-labelledby="viewLeaveModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content rounded-3">
            <div class="modal-header text-white"
                 style="background: linear-gradient(135deg,#003580 0%,#0056b3 100%);">
                <h5 class="modal-title fw-bold" id="viewLeaveModalLabel">
                    <i class="fas fa-calendar-xmark me-2" aria-hidden="true"></i>
                    Leave Request Details
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4" id="viewLeaveBody">
                <!-- Populated by JS -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>


<!-- ================================================================
     APPROVE / REJECT ACTION MODAL
     ================================================================ -->
<div class="modal fade" id="actionModal" tabindex="-1" aria-labelledby="actionModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content rounded-3">
            <div class="modal-header text-white" id="actionModalHeader"
                 style="background: linear-gradient(135deg,#003580 0%,#0056b3 100%);">
                <h5 class="modal-title fw-bold" id="actionModalLabel">Confirm Action</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <p id="actionModalDesc" class="mb-3"></p>
                <div class="mb-3">
                    <label for="adminNotes" class="form-label fw-semibold">
                        Admin Notes
                        <span id="notesRequired" class="text-danger ms-1">*</span>
                    </label>
                    <textarea id="adminNotes"
                              class="form-control"
                              rows="3"
                              placeholder="Enter notes for the driver…"
                              style="border-radius: 10px; border: 1.5px solid #e5e9f0;"></textarea>
                    <div class="invalid-feedback" id="adminNotesError">
                        Admin notes are required when rejecting a request.
                    </div>
                </div>
            </div>
            <div class="modal-footer gap-2">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn" id="actionConfirmBtn" onclick="submitAction()">
                    Confirm
                </button>
            </div>
        </div>
    </div>
</div>


<!-- ── Embedded leave data (JSON for JS) ── -->
<script>
const LEAVE_DATA = <?php
    $json_data = [];
    foreach ($all_requests as $r) {
        $start_ts = strtotime($r['start_date']);
        $end_ts   = strtotime($r['end_date']);
        $days     = max(1, (int)(($end_ts - $start_ts) / 86400) + 1);
        $json_data[] = [
            'request_id'    => (int)$r['request_id'],
            'driver_name'   => $r['driver_name'],
            'employee_id'   => $r['employee_id'],
            'leave_type'    => $r['leave_type'],
            'start_date'    => $r['start_date'],
            'end_date'      => $r['end_date'],
            'days'          => $days,
            'reason'        => $r['reason'],
            'status'        => $r['status'],
            'admin_notes'   => $r['admin_notes'] ?? '',
            'reviewer_name' => $r['reviewer_name'] ?? '',
            'created_at'    => $r['created_at'],
            'updated_at'    => $r['updated_at'] ?? '',
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

    // ── Format date string (YYYY-MM-DD) to readable form ──
    function fmtDate(str) {
        if (!str) return '&mdash;';
        var d = new Date(str);
        var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        return d.getDate() + ' ' + months[d.getMonth()] + ' ' + d.getFullYear();
    }

    // ── Leave type badge map ────────────────────────────────
    var typeBadgeMap = {
        emergency: 'bg-danger',
        medical:   'bg-warning text-dark',
        annual:    'bg-info text-dark',
        personal:  'bg-secondary',
    };

    var statusBadgeMap = {
        pending:  'bg-warning text-dark',
        approved: 'bg-success',
        rejected: 'bg-danger',
    };

    // ── DataTable initialisation ─────────────────────────────
    $('#leaveTable').DataTable({
        order:      [[4, 'desc']],   // sort by Submitted desc
        pageLength: 25,
        responsive: true,
        columnDefs: [
            { orderable: false, targets: 6 },
            { searchable: false, targets: [0, 6] }
        ],
        language: {
            search:         'Search:',
            lengthMenu:     'Show _MENU_ entries per page',
            info:           'Showing _START_ to _END_ of _TOTAL_ requests',
            infoEmpty:      'No requests found',
            emptyTable:     'No leave requests found',
            zeroRecords:    'No requests match the search'
        }
    });

    // ── View Details Modal ────────────────────────────────────
    window.viewLeave = function (requestId) {
        var r = LEAVE_DATA.find(function (row) { return row.request_id === requestId; });
        if (!r) return;

        var typeBadge   = typeBadgeMap[r.leave_type]   || 'bg-secondary';
        var statusBadge = statusBadgeMap[r.status]      || 'bg-secondary';

        var body = '<div class="row g-3">'

            // Driver & status header
            + '<div class="col-12">'
            +   '<div class="d-flex align-items-center gap-3 flex-wrap">'
            +     '<div style="width:48px;height:48px;border-radius:50%;background:linear-gradient(135deg,#003580,#0056b3);'
            +          'display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:1.1rem;">'
            +       escHtml(r.driver_name.charAt(0).toUpperCase())
            +     '</div>'
            +     '<div>'
            +       '<div class="fw-bold fs-5">' + escHtml(r.driver_name) + '</div>'
            +       '<div class="small text-muted font-monospace">' + escHtml(r.employee_id) + '</div>'
            +     '</div>'
            +     '<div class="ms-auto d-flex gap-2 flex-wrap">'
            +       '<span class="badge ' + typeBadge   + ' rounded-pill">' + escHtml(r.leave_type.charAt(0).toUpperCase() + r.leave_type.slice(1)) + '</span>'
            +       '<span class="badge ' + statusBadge + ' rounded-pill">' + escHtml(r.status.charAt(0).toUpperCase() + r.status.slice(1)) + '</span>'
            +     '</div>'
            +   '</div>'
            +   '<hr class="mt-3 mb-0">'
            + '</div>'

            // Leave period & dates
            + '<div class="col-md-6">'
            +   '<div class="detail-label">Leave Period</div>'
            +   '<div class="detail-value">' + fmtDate(r.start_date) + ' &rarr; ' + fmtDate(r.end_date) + '</div>'
            +   '<div class="small text-muted">' + r.days + ' day' + (r.days !== 1 ? 's' : '') + '</div>'
            + '</div>'
            + '<div class="col-md-6">'
            +   '<div class="detail-label">Date Submitted</div>'
            +   '<div class="detail-value">' + fmtDate(r.created_at ? r.created_at.substring(0, 10) : '') + '</div>'
            + '</div>'

            // Reason
            + '<div class="col-12">'
            +   '<div class="detail-label">Reason</div>'
            +   '<div class="p-3 rounded-3" style="background:#f8f9fb;font-size:.9rem;line-height:1.6;">'
            +     (r.reason ? escHtml(r.reason) : '<span class="text-muted fst-italic">No reason provided.</span>')
            +   '</div>'
            + '</div>';

        // Admin notes & reviewer (only when reviewed)
        if (r.status !== 'pending') {
            body += '<div class="col-12">'
                  +   '<hr class="mb-2">'
                  +   '<div class="detail-label">Admin Notes</div>'
                  +   '<div class="p-3 rounded-3" style="background:#f8f9fb;font-size:.9rem;line-height:1.6;">'
                  +     (r.admin_notes ? escHtml(r.admin_notes) : '<span class="text-muted fst-italic">No notes added.</span>')
                  +   '</div>'
                  + '</div>'
                  + '<div class="col-12">'
                  +   '<div class="detail-label">Reviewed By</div>'
                  +   '<div class="detail-value">' + (r.reviewer_name ? escHtml(r.reviewer_name) : '&mdash;') + '</div>'
                  + '</div>';
        }

        body += '</div>';

        document.getElementById('viewLeaveBody').innerHTML = body;
        var modal = new bootstrap.Modal(document.getElementById('viewLeaveModal'));
        modal.show();
    };


    // ── Action modal state ───────────────────────────────────
    var _actionRequestId = 0;
    var _actionType      = '';

    window.openAction = function (requestId, action, driverName) {
        _actionRequestId = requestId;
        _actionType      = action;

        var isReject = (action === 'reject');

        var header = document.getElementById('actionModalHeader');
        var label  = document.getElementById('actionModalLabel');
        var desc   = document.getElementById('actionModalDesc');
        var btn    = document.getElementById('actionConfirmBtn');
        var req    = document.getElementById('notesRequired');
        var notes  = document.getElementById('adminNotes');

        if (isReject) {
            header.style.background = 'linear-gradient(135deg,#991b1b,#dc2626)';
            label.textContent  = 'Reject Leave Request';
            desc.innerHTML     = 'You are rejecting the leave request from <strong>' + escHtml(driverName) + '</strong>. Please provide a reason below.';
            btn.className      = 'btn btn-danger';
            btn.innerHTML      = '<i class="fas fa-xmark me-1"></i> Reject Request';
            req.style.display  = 'inline';
        } else {
            header.style.background = 'linear-gradient(135deg,#065f46,#059669)';
            label.textContent  = 'Approve Leave Request';
            desc.innerHTML     = 'You are approving the leave request from <strong>' + escHtml(driverName) + '</strong>. You may optionally add a note below.';
            btn.className      = 'btn btn-success';
            btn.innerHTML      = '<i class="fas fa-check me-1"></i> Approve Request';
            req.style.display  = 'none';
        }

        // Reset notes field
        notes.value = '';
        notes.classList.remove('is-invalid');

        var modal = new bootstrap.Modal(document.getElementById('actionModal'));
        modal.show();
    };

    window.submitAction = function () {
        var notes = document.getElementById('adminNotes').value.trim();

        // Reject requires notes
        if (_actionType === 'reject' && notes === '') {
            document.getElementById('adminNotes').classList.add('is-invalid');
            return;
        }

        document.getElementById('adminNotes').classList.remove('is-invalid');

        var btn = document.getElementById('actionConfirmBtn');
        btn.disabled    = true;
        btn.innerHTML   = '<span class="spinner-border spinner-border-sm me-1"></span> Processing…';

        $.ajax({
            url:      SITE_URL + '/ajax/leave_action.php',
            method:   'POST',
            data: {
                request_id:  _actionRequestId,
                action:      _actionType,
                admin_notes: notes
            },
            dataType: 'json'
        })
        .done(function (res) {
            bootstrap.Modal.getInstance(document.getElementById('actionModal')).hide();

            if (res.success) {
                Swal.fire({
                    icon:              'success',
                    title:             _actionType === 'approve' ? 'Approved!' : 'Rejected!',
                    text:              res.message,
                    timer:             1800,
                    showConfirmButton: false
                }).then(function () { location.reload(); });
            } else {
                Swal.fire({
                    icon:  'error',
                    title: 'Failed',
                    text:  res.message || 'Something went wrong. Please try again.'
                });
            }
        })
        .fail(function () {
            bootstrap.Modal.getInstance(document.getElementById('actionModal')).hide();
            Swal.fire({ icon: 'error', title: 'Network Error', text: 'Could not reach the server. Please try again.' });
        })
        .always(function () {
            btn.disabled = false;
        });
    };

})();
</script>

</body>
</html>
