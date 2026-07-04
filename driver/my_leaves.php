<?php
$page_title   = 'My Leave History';
$current_page = 'my_leaves.php';
require_once '../config/database.php';
requireDriver();

$driver_id = (int)$_SESSION['driver_id'];

// Fetch all leave requests for this driver with reviewer name
$stmt = $conn->prepare("
    SELECT lr.*, u.full_name AS reviewer_name
    FROM leave_requests lr
    LEFT JOIN users u ON lr.reviewed_by = u.user_id
    WHERE lr.driver_id = ?
    ORDER BY lr.created_at DESC
");
$stmt->bind_param('i', $driver_id);
$stmt->execute();
$leaves = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Summary counts
$total    = count($leaves);
$pending  = 0;
$approved = 0;
$rejected = 0;
foreach ($leaves as $l) {
    if ($l['status'] === 'pending')  $pending++;
    if ($l['status'] === 'approved') $approved++;
    if ($l['status'] === 'rejected') $rejected++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Leave History | UIS Driver Management</title>
    <!-- Bootstrap 5.3 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome 6.4 -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- DataTables Bootstrap 5 theme -->
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
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

    <!-- Page Header -->
    <div style="background: linear-gradient(135deg, #003580 0%, #0056b3 100%); border-radius: 14px; color: #fff; padding: 1.4rem 2rem; margin-bottom: 1.5rem;">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h4 class="fw-bold mb-1">
                    <i class="fas fa-clock-rotate-left me-2"></i>My Leave History
                </h4>
                <p class="mb-0 opacity-75">Track all your leave and emergency requests</p>
            </div>
            <a href="leave_request.php" class="btn btn-light btn-sm fw-semibold">
                <i class="fas fa-plus me-1"></i>New Leave Request
            </a>
        </div>
    </div>

    <!-- Summary Stat Cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card text-center border-0 shadow-sm h-100" style="border-radius:12px;">
                <div class="card-body py-3">
                    <div class="h3 fw-bold text-primary mb-1"><?= $total ?></div>
                    <div class="small text-muted fw-semibold">Total Requests</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center border-0 shadow-sm h-100"
                 style="border-radius:12px;border-left:4px solid #f59e0b !important;">
                <div class="card-body py-3">
                    <div class="h3 fw-bold text-warning mb-1"><?= $pending ?></div>
                    <div class="small text-muted fw-semibold">Pending</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center border-0 shadow-sm h-100"
                 style="border-radius:12px;border-left:4px solid #198754 !important;">
                <div class="card-body py-3">
                    <div class="h3 fw-bold text-success mb-1"><?= $approved ?></div>
                    <div class="small text-muted fw-semibold">Approved</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center border-0 shadow-sm h-100"
                 style="border-radius:12px;border-left:4px solid #dc3545 !important;">
                <div class="card-body py-3">
                    <div class="h3 fw-bold text-danger mb-1"><?= $rejected ?></div>
                    <div class="small text-muted fw-semibold">Rejected</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Leave Requests Table -->
    <div class="card border-0 shadow-sm" style="border-radius:14px;">
        <div class="card-header bg-white border-bottom px-4 py-3" style="border-radius:14px 14px 0 0;">
            <h6 class="mb-0 fw-semibold">
                <i class="fas fa-list-check text-primary me-2"></i>All Leave Requests
            </h6>
        </div>
        <div class="card-body p-0">
            <?php if (empty($leaves)): ?>
            <div class="text-center py-5">
                <i class="fas fa-calendar-xmark fa-3x text-muted mb-3"></i>
                <h6 class="text-muted">No leave requests found</h6>
                <p class="text-muted small mb-3">You have not submitted any leave requests yet.</p>
                <a href="leave_request.php" class="btn btn-primary btn-sm">
                    <i class="fas fa-plus me-1"></i>Submit a Request
                </a>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0" id="leavesTable">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Type</th>
                            <th>Period</th>
                            <th>Submitted</th>
                            <th>Status</th>
                            <th>Admin Notes</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($leaves as $i => $l): ?>
                        <?php
                        $start = new DateTime($l['start_date']);
                        $end   = new DateTime($l['end_date']);
                        $days  = (int)$start->diff($end)->days + 1;

                        $type_map = [
                            'emergency' => ['danger',          'bolt',            'Emergency'],
                            'medical'   => ['info',            'stethoscope',     'Medical'],
                            'annual'    => ['success',         'umbrella-beach',  'Annual'],
                            'personal'  => ['warning text-dark','user-clock',     'Personal'],
                        ];
                        [$tc, $ti, $tl] = $type_map[$l['leave_type']] ?? ['secondary', 'circle', ucfirst($l['leave_type'])];

                        $status_map = [
                            'pending'  => ['warning text-dark', 'clock',        'Pending'],
                            'approved' => ['success',           'check-circle', 'Approved'],
                            'rejected' => ['danger',            'times-circle', 'Rejected'],
                        ];
                        [$sc, $si, $sl] = $status_map[$l['status']] ?? ['secondary', 'circle', ucfirst($l['status'])];
                        ?>
                        <tr>
                            <td class="fw-semibold text-muted"><?= $i + 1 ?></td>
                            <td>
                                <span class="badge bg-<?= $tc ?>">
                                    <i class="fas fa-<?= $ti ?> me-1"></i><?= $tl ?>
                                </span>
                            </td>
                            <td>
                                <div class="fw-semibold small">
                                    <?= date('d M Y', strtotime($l['start_date'])) ?>
                                    &rarr;
                                    <?= date('d M Y', strtotime($l['end_date'])) ?>
                                </div>
                                <div class="text-muted" style="font-size:0.78rem;">
                                    <i class="fas fa-calendar-days me-1"></i>
                                    <?= $days ?> day<?= $days !== 1 ? 's' : '' ?>
                                </div>
                            </td>
                            <td>
                                <span class="small text-muted">
                                    <?= date('d M Y', strtotime($l['created_at'])) ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge bg-<?= $sc ?>">
                                    <i class="fas fa-<?= $si ?> me-1"></i><?= $sl ?>
                                </span>
                            </td>
                            <td>
                                <?php if (!empty($l['admin_notes'])): ?>
                                <span class="small text-muted d-inline-block"
                                      style="max-width:200px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;vertical-align:middle;"
                                      title="<?= htmlspecialchars($l['admin_notes']) ?>">
                                    <?= htmlspecialchars($l['admin_notes']) ?>
                                </span>
                                <?php else: ?>
                                <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button class="btn btn-sm btn-outline-primary"
                                        onclick="viewDetails(<?= htmlspecialchars(json_encode($l), ENT_QUOTES) ?>)"
                                        title="View Details">
                                    <i class="fas fa-eye me-1"></i>View
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<!-- View Details Modal -->
<div class="modal fade" id="detailsModal" tabindex="-1"
     aria-labelledby="detailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="border-radius:14px;overflow:hidden;">
            <div class="modal-header text-white border-0"
                 style="background: linear-gradient(135deg, #003580 0%, #0056b3 100%);">
                <h5 class="modal-title fw-bold" id="detailsModalLabel">
                    <i class="fas fa-calendar-check me-2"></i>Leave Request Details
                </h5>
                <button type="button" class="btn-close btn-close-white"
                        data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4" id="detailsModalBody">
                <p class="text-muted text-center py-3">Loading...</p>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-xmark me-1"></i>Close
                </button>
                <a href="leave_request.php" class="btn btn-primary">
                    <i class="fas fa-plus me-1"></i>New Request
                </a>
            </div>
        </div>
    </div>
</div>

<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<!-- Bootstrap 5.3 JS Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- DataTables core -->
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<!-- DataTables Bootstrap 5 integration -->
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<!-- Custom JS -->
<script src="<?= SITE_URL ?>/assets/js/main.js"></script>
<script>
(function () {
    'use strict';

    // ── DataTable initialisation ─────────────────────────────────────
    if (document.getElementById('leavesTable')) {
        $('#leavesTable').DataTable({
            order:       [[3, 'desc']],
            pageLength:  10,
            columnDefs:  [{ orderable: false, targets: [6] }],
            language: {
                emptyTable:  'No leave requests found.',
                search:      'Search:',
                lengthMenu:  'Show _MENU_ entries',
                info:        'Showing _START_ to _END_ of _TOTAL_ requests',
                infoEmpty:   'Showing 0 to 0 of 0 requests',
                zeroRecords: 'No matching requests found.',
                paginate: {
                    first:    'First',
                    last:     'Last',
                    next:     'Next',
                    previous: 'Previous'
                }
            }
        });
    }

    // ── Leave type display metadata ──────────────────────────────────
    var typeMap = {
        emergency: { color: 'danger',          icon: 'bolt',            label: 'Emergency' },
        medical:   { color: 'info',            icon: 'stethoscope',     label: 'Medical'   },
        annual:    { color: 'success',         icon: 'umbrella-beach',  label: 'Annual'    },
        personal:  { color: 'warning text-dark', icon: 'user-clock',   label: 'Personal'  }
    };

    var statusMap = {
        pending:  { color: 'warning text-dark', icon: 'clock',         label: 'Pending'  },
        approved: { color: 'success',           icon: 'check-circle',  label: 'Approved' },
        rejected: { color: 'danger',            icon: 'times-circle',  label: 'Rejected' }
    };

    // ── View Details modal ───────────────────────────────────────────
    window.viewDetails = function (l) {
        var t = typeMap[l.leave_type]  || { color: 'secondary', icon: 'circle', label: l.leave_type };
        var s = statusMap[l.status]    || { color: 'secondary', icon: 'circle', label: l.status };

        var startD = new Date(l.start_date + 'T00:00:00');
        var endD   = new Date(l.end_date   + 'T00:00:00');
        var days   = Math.round((endD - startD) / 86400000) + 1;

        var submittedFormatted = l.created_at
            ? new Date(l.created_at).toLocaleDateString('en-MY', {
                year: 'numeric', month: 'long', day: 'numeric'
              })
            : '—';

        var reviewerHtml = l.reviewer_name
            ? '<span class="fw-semibold">' + escHtml(l.reviewer_name) + '</span>'
            : '<span class="text-muted fst-italic">Not yet reviewed</span>';

        var notesHtml = l.admin_notes
            ? '<div class="p-3 bg-light rounded mt-1">' + escHtml(l.admin_notes) + '</div>'
            : '<div class="mt-1"><span class="text-muted fst-italic">No notes provided.</span></div>';

        document.getElementById('detailsModalBody').innerHTML =
            '<div class="row g-3">' +
                '<div class="col-sm-6">' +
                    label('Leave Type') +
                    '<div class="mt-1">' +
                        '<span class="badge bg-' + t.color + ' fs-6 px-3 py-2">' +
                            '<i class="fas fa-' + t.icon + ' me-2"></i>' + t.label +
                        '</span>' +
                    '</div>' +
                '</div>' +
                '<div class="col-sm-6">' +
                    label('Status') +
                    '<div class="mt-1">' +
                        '<span class="badge bg-' + s.color + ' fs-6 px-3 py-2">' +
                            '<i class="fas fa-' + s.icon + ' me-2"></i>' + s.label +
                        '</span>' +
                    '</div>' +
                '</div>' +
                '<div class="col-sm-4">' +
                    label('Start Date') +
                    '<div class="fw-semibold mt-1">' + fmtDate(l.start_date) + '</div>' +
                '</div>' +
                '<div class="col-sm-4">' +
                    label('End Date') +
                    '<div class="fw-semibold mt-1">' + fmtDate(l.end_date) + '</div>' +
                '</div>' +
                '<div class="col-sm-4">' +
                    label('Duration') +
                    '<div class="fw-semibold mt-1 text-primary">' +
                        days + ' day' + (days !== 1 ? 's' : '') +
                    '</div>' +
                '</div>' +
                '<div class="col-12">' +
                    label('Reason') +
                    '<div class="p-3 bg-light rounded mt-1">' + escHtml(l.reason) + '</div>' +
                '</div>' +
                '<div class="col-sm-6">' +
                    label('Submitted On') +
                    '<div class="fw-semibold mt-1">' + submittedFormatted + '</div>' +
                '</div>' +
                '<div class="col-sm-6">' +
                    label('Reviewed By') +
                    reviewerHtml +
                '</div>' +
                '<div class="col-12">' +
                    label('Admin Notes') +
                    notesHtml +
                '</div>' +
            '</div>';

        new bootstrap.Modal(document.getElementById('detailsModal')).show();
    };

    // ── Helpers ──────────────────────────────────────────────────────
    function label(text) {
        return '<label class="text-muted fw-semibold text-uppercase" ' +
               'style="font-size:0.72rem;letter-spacing:.05em;">' + text + '</label>';
    }

    function escHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function fmtDate(str) {
        if (!str) return '—';
        var months = ['Jan','Feb','Mar','Apr','May','Jun',
                      'Jul','Aug','Sep','Oct','Nov','Dec'];
        var d = new Date(str + 'T00:00:00');
        return ('0' + d.getDate()).slice(-2) + ' ' +
               months[d.getMonth()] + ' ' + d.getFullYear();
    }

})();
</script>
</body>
</html>
