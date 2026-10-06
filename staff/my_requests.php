<?php
$page_title   = 'My Requests';
$current_page = 'my_requests.php';
require_once '../config/database.php';
requireStaff();

$staff_id = (int)$_SESSION['user_id'];

// Fetch all vehicle requests for this staff member with vehicle + supervisor info
$stmt = $conn->prepare("
    SELECT vr.*, v.plate_number, v.brand, v.model, u.full_name AS supervisor_name
    FROM vehicle_requests vr
    LEFT JOIN vehicles v ON vr.vehicle_id = v.vehicle_id
    LEFT JOIN users u ON vr.supervisor_id = u.user_id
    WHERE vr.staff_id = ?
    ORDER BY vr.created_at DESC
");
$stmt->bind_param('i', $staff_id);
$stmt->execute();
$requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Summary counts
$total    = count($requests);
$pending  = 0;
$approved = 0;
$rejected = 0;
foreach ($requests as $r) {
    if ($r['status'] === 'pending')  $pending++;
    if ($r['status'] === 'approved') $approved++;
    if ($r['status'] === 'rejected') $rejected++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Requests | UIS Driver Management</title>
    <link rel="icon" type="image/png" href="<?php echo SITE_URL; ?>/assets/images/uis-favicon.png">
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
    <div style="background: linear-gradient(135deg, #0b5d3b 0%, #15804f 100%); border-radius: 14px; color: #fff; padding: 1.4rem 2rem; margin-bottom: 1.5rem;">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h4 class="fw-bold mb-1">
                    <i class="fas fa-clock-rotate-left me-2"></i>My Requests
                </h4>
                <p class="mb-0 opacity-75">Track all your e-Kenderaan vehicle requests</p>
            </div>
            <a href="request_vehicle.php" class="btn btn-light btn-sm fw-semibold">
                <i class="fas fa-plus me-1"></i>New Request
            </a>
        </div>
    </div>

    <!-- Status Legend -->
    <div class="alert alert-info border-0 shadow-sm" style="border-radius:12px;">
        <div class="d-flex gap-2">
            <i class="fas fa-circle-info mt-1"></i>
            <div class="small">
                <strong>Request status guide:</strong>
                <span class="badge bg-warning text-dark ms-1">Pending</span> waiting for your Head of Section's decision &nbsp;&bull;&nbsp;
                <span class="badge bg-success">Approved</span> waiting for the transport unit to assign a driver &nbsp;&bull;&nbsp;
                <span class="badge bg-primary">Processed</span> driver &amp; vehicle assigned &nbsp;&bull;&nbsp;
                <span class="badge bg-danger">Rejected</span> see supervisor notes for the reason.
            </div>
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

    <!-- Requests Table -->
    <div class="card border-0 shadow-sm" style="border-radius:14px;">
        <div class="card-header bg-white border-bottom px-4 py-3" style="border-radius:14px 14px 0 0;">
            <h6 class="mb-0 fw-semibold">
                <i class="fas fa-list-check text-primary me-2"></i>All Vehicle Requests
            </h6>
        </div>
        <div class="card-body p-0">
            <?php if (empty($requests)): ?>
            <div class="text-center py-5">
                <i class="fas fa-file-circle-plus fa-3x text-muted mb-3"></i>
                <h6 class="text-muted">No requests found</h6>
                <p class="text-muted small mb-3">You have not submitted any vehicle requests yet.</p>
                <a href="request_vehicle.php" class="btn btn-primary btn-sm">
                    <i class="fas fa-plus me-1"></i>Request a Vehicle
                </a>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0" id="requestsTable">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Trip Date</th>
                            <th>Destination</th>
                            <th>Vehicle</th>
                            <th>Status</th>
                            <th>Submitted</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($requests as $i => $r): ?>
                        <?php
                        $status_map = [
                            'pending'   => ['warning text-dark', 'clock',         'Pending'],
                            'approved'  => ['success',           'check-circle',  'Approved'],
                            'rejected'  => ['danger',            'times-circle',  'Rejected'],
                            'processed' => ['primary',           'user-check',    'Processed — driver assigned'],
                        ];
                        [$sc, $si, $sl] = $status_map[$r['status']] ?? ['secondary', 'circle', ucfirst($r['status'])];
                        ?>
                        <tr>
                            <td class="fw-semibold text-muted"><?= $i + 1 ?></td>
                            <td>
                                <div class="fw-semibold small"><?= formatDate($r['trip_date']) ?></div>
                                <div class="text-muted" style="font-size:0.78rem;">
                                    <i class="fas fa-clock me-1"></i>
                                    <?= substr($r['start_time'], 0, 5) ?> &ndash; <?= substr($r['end_time'], 0, 5) ?>
                                </div>
                            </td>
                            <td>
                                <span class="small d-inline-block"
                                      style="max-width:200px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;vertical-align:middle;"
                                      title="<?= htmlspecialchars($r['destination']) ?>">
                                    <?= htmlspecialchars($r['destination']) ?>
                                </span>
                            </td>
                            <td>
                                <?php if (!empty($r['plate_number'])): ?>
                                <span class="badge bg-secondary">
                                    <i class="fas fa-car me-1"></i><?= htmlspecialchars($r['plate_number']) ?>
                                </span>
                                <?php else: ?>
                                <span class="text-muted small">Any</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-<?= $sc ?>">
                                    <i class="fas fa-<?= $si ?> me-1"></i><?= $sl ?>
                                </span>
                            </td>
                            <td>
                                <span class="small text-muted"><?= formatDate($r['created_at']) ?></span>
                            </td>
                            <td>
                                <button class="btn btn-sm btn-outline-primary"
                                        onclick="viewDetails(<?= (int)$r['request_id'] ?>)"
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
                 style="background: linear-gradient(135deg, #0b5d3b 0%, #15804f 100%);">
                <h5 class="modal-title fw-bold" id="detailsModalLabel">
                    <i class="fas fa-file-signature me-2"></i>Vehicle Request Details
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
                <a href="request_vehicle.php" class="btn btn-primary">
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
// Embedded request data keyed by request_id for the details modal
var REQUESTS_DATA = <?= json_encode(
    array_column($requests, null, 'request_id'),
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
) ?>;

(function () {
    'use strict';

    // ── DataTable initialisation ─────────────────────────────────────
    if (document.getElementById('requestsTable')) {
        $('#requestsTable').DataTable({
            order:       [[5, 'desc']],
            pageLength:  10,
            columnDefs:  [{ orderable: false, targets: [6] }],
            language: {
                emptyTable:  'No vehicle requests found.',
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

    // ── Status display metadata ──────────────────────────────────────
    var statusMap = {
        pending:   { color: 'warning text-dark', icon: 'clock',        label: 'Pending' },
        approved:  { color: 'success',           icon: 'check-circle', label: 'Approved' },
        rejected:  { color: 'danger',            icon: 'times-circle', label: 'Rejected' },
        processed: { color: 'primary',           icon: 'user-check',   label: 'Processed — driver assigned' }
    };

    // ── View Details modal ───────────────────────────────────────────
    window.viewDetails = function (requestId) {
        var r = REQUESTS_DATA[requestId];
        if (!r) return;

        var s = statusMap[r.status] || { color: 'secondary', icon: 'circle', label: r.status };

        var vehicleHtml;
        if (r.plate_number) {
            var vehicleName = [r.brand, r.model].filter(Boolean).join(' ');
            vehicleHtml = '<span class="badge bg-secondary fs-6 px-3 py-2">' +
                              '<i class="fas fa-car me-2"></i>' + escHtml(r.plate_number) +
                          '</span>' +
                          (vehicleName
                              ? ' <span class="small text-muted ms-1">' + escHtml(vehicleName) + '</span>'
                              : '');
        } else {
            vehicleHtml = '<span class="text-muted fst-italic">Any available vehicle</span>';
        }

        var reviewed = !!r.reviewed_at;

        var supervisorHtml = r.supervisor_name
            ? '<div class="fw-semibold mt-1">' + escHtml(r.supervisor_name) + '</div>'
            : '<div class="mt-1"><span class="text-muted fst-italic">Not yet reviewed</span></div>';

        var notesHtml = '';
        if (reviewed) {
            notesHtml =
                '<div class="col-12">' +
                    label('Supervisor Notes') +
                    (r.supervisor_notes
                        ? '<div class="p-3 bg-light rounded mt-1">' + escHtml(r.supervisor_notes) + '</div>'
                        : '<div class="mt-1"><span class="text-muted fst-italic">No notes provided.</span></div>') +
                '</div>';
        }

        var submittedFormatted = r.created_at
            ? new Date(r.created_at.replace(' ', 'T')).toLocaleString('en-MY', {
                year: 'numeric', month: 'long', day: 'numeric',
                hour: '2-digit', minute: '2-digit'
              })
            : '—';

        document.getElementById('detailsModalBody').innerHTML =
            '<div class="row g-3">' +
                '<div class="col-sm-6">' +
                    label('Status') +
                    '<div class="mt-1">' +
                        '<span class="badge bg-' + s.color + ' fs-6 px-3 py-2">' +
                            '<i class="fas fa-' + s.icon + ' me-2"></i>' + s.label +
                        '</span>' +
                    '</div>' +
                '</div>' +
                '<div class="col-sm-6">' +
                    label('Passengers') +
                    '<div class="fw-semibold mt-1">' +
                        '<i class="fas fa-users me-1 text-muted"></i>' + escHtml(String(r.passenger_count)) + ' pax' +
                    '</div>' +
                '</div>' +
                '<div class="col-sm-4">' +
                    label('Trip Date') +
                    '<div class="fw-semibold mt-1">' + fmtDate(r.trip_date) + '</div>' +
                '</div>' +
                '<div class="col-sm-4">' +
                    label('Start Time') +
                    '<div class="fw-semibold mt-1">' + escHtml((r.start_time || '').substring(0, 5)) + '</div>' +
                '</div>' +
                '<div class="col-sm-4">' +
                    label('End Time') +
                    '<div class="fw-semibold mt-1">' + escHtml((r.end_time || '').substring(0, 5)) + '</div>' +
                '</div>' +
                '<div class="col-12">' +
                    label('Destination') +
                    '<div class="fw-semibold mt-1">' + escHtml(r.destination) + '</div>' +
                '</div>' +
                '<div class="col-12">' +
                    label('Purpose') +
                    '<div class="p-3 bg-light rounded mt-1">' + escHtml(r.purpose) + '</div>' +
                '</div>' +
                '<div class="col-sm-6">' +
                    label('Requested Vehicle') +
                    '<div class="mt-1">' + vehicleHtml + '</div>' +
                '</div>' +
                '<div class="col-sm-6">' +
                    label('Head of Section') +
                    supervisorHtml +
                '</div>' +
                notesHtml +
                '<div class="col-sm-6">' +
                    label('Submitted On') +
                    '<div class="fw-semibold mt-1">' + escHtml(submittedFormatted) + '</div>' +
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
