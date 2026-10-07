<?php
// ============================================================
// UIS Driver Scheduling and Management System
// supervisor/approvals.php  –  Vehicle Request Approvals
//                              (Head of Section / Supervisor)
// Universiti Islam Selangor (UIS)
// ============================================================

require_once '../config/database.php';
require_once '../includes/upload.php';
requireSupervisor();

$page_title   = 'Vehicle Approvals';
$current_page = 'approvals.php';

$supervisor_id = (int)$_SESSION['user_id'];

// ── Fetch vehicle requests assigned to this supervisor ───────
$stmt = $conn->prepare("
    SELECT vr.*,
           s.full_name  AS staff_name,
           s.department AS staff_department,
           v.plate_number, v.brand, v.model, v.vehicle_type, v.capacity
    FROM vehicle_requests vr
    JOIN users s        ON vr.staff_id   = s.user_id
    LEFT JOIN vehicles v ON vr.vehicle_id = v.vehicle_id
    WHERE vr.supervisor_id = ?
    ORDER BY FIELD(vr.status, 'pending') DESC, vr.created_at DESC
");
$stmt->bind_param('i', $supervisor_id);
$stmt->execute();
$all_requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── Supporting documents (one query for all requests) ────────
$request_documents = [];
if ($all_requests) {
    $raw_docs = getRequestDocuments($conn, array_map('intval', array_column($all_requests, 'request_id')));
    foreach ($raw_docs as $rid => $docs) {
        foreach ($docs as $d) {
            $request_documents[(int)$rid][] = [
                'doc_id'         => (int)$d['doc_id'],
                'doc_type'       => $d['doc_type'],
                'doc_type_label' => $d['doc_type_label'],
                'original_name'  => $d['original_name'],
                'mime_type'      => $d['mime_type'],
                'file_size'      => (int)$d['file_size'],
                'size_label'     => formatFileSize((int)$d['file_size']),
                'uploaded_at'    => $d['uploaded_at'],
                'url'            => $d['url'],
            ];
        }
    }
}

// ── Summary counts ───────────────────────────────────────────
$total     = count($all_requests);
$pending   = 0;
$approved  = 0;
$rejected  = 0;
$processed = 0;

foreach ($all_requests as $r) {
    switch ($r['status']) {
        case 'pending':   $pending++;   break;
        case 'approved':  $approved++;  break;
        case 'rejected':  $rejected++;  break;
        case 'processed': $processed++; break;
    }
}

// ── Apply tab filter ─────────────────────────────────────────
$active_tab = $_GET['status'] ?? 'all';
$active_tab = in_array($active_tab, ['all', 'pending', 'approved', 'rejected', 'processed'], true)
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
    <link rel="icon" type="image/png" href="<?php echo SITE_URL; ?>/assets/images/uis-favicon.png">

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
            background: linear-gradient(135deg, #0b5d3b 0%, #15804f 100%);
            border-radius: 14px;
            color: #fff;
            padding: 1.6rem 2rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 16px rgba(11,93,59,.20);
        }
        .page-header h1 { font-size: 1.55rem; font-weight: 700; margin: 0; }
        .page-header p  { margin: .3rem 0 0; opacity: .8; font-size: .88rem; }

        /* ── Summary cards ── */
        .stat-card {
            border: none;
            border-radius: 14px;
            box-shadow: 0 2px 12px rgba(11,93,59,.10);
            transition: transform .2s, box-shadow .2s;
        }
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(11,93,59,.16);
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
            box-shadow: 0 2px 12px rgba(11,93,59,.10);
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
        .table tbody tr:hover { background: #f0f6f2; }

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
            background: #0b5d3b;
            color: #fff;
        }
        .status-tabs .nav-link:not(.active):hover {
            background: #f0f6f2;
            color: #0b5d3b;
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
        <h1><i class="fas fa-clipboard-check me-2" aria-hidden="true"></i>Vehicle Approvals</h1>
        <p>Review and approve vehicle requests submitted by staff in your section.</p>
    </div>

    <!-- Flash message -->
    <?php showFlash(); ?>

    <!-- ── Summary Cards ──────────────────────────────────────── -->
    <div class="row g-3 mb-4">

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

        <!-- Total -->
        <div class="col-6 col-md-3">
            <a href="?status=all" class="text-decoration-none">
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
            </a>
        </div>

    </div><!-- /.row summary cards -->

    <!-- ── Table Card ────────────────────────────────────────── -->
    <div class="card table-card">
        <div class="card-body p-0">

            <!-- Card header with tabs -->
            <div class="p-3 border-bottom d-flex align-items-center justify-content-between flex-wrap gap-3">
                <h6 class="mb-0 fw-semibold text-primary">
                    <i class="fas fa-table me-1" aria-hidden="true"></i> Vehicle Requests
                </h6>
                <!-- Status Tabs -->
                <ul class="nav status-tabs gap-1 mb-0">
                    <li class="nav-item">
                        <a class="nav-link <?php echo $active_tab === 'all'       ? 'active' : ''; ?>"
                           href="?status=all">
                            All
                            <span class="badge ms-1 <?php echo $active_tab === 'all' ? 'bg-white text-primary' : 'bg-primary'; ?> rounded-pill">
                                <?php echo $total; ?>
                            </span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $active_tab === 'pending'   ? 'active' : ''; ?>"
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
                        <a class="nav-link <?php echo $active_tab === 'approved'  ? 'active' : ''; ?>"
                           href="?status=approved">
                            Approved
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $active_tab === 'rejected'  ? 'active' : ''; ?>"
                           href="?status=rejected">
                            Rejected
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $active_tab === 'processed' ? 'active' : ''; ?>"
                           href="?status=processed">
                            Processed
                        </a>
                    </li>
                </ul>
            </div>

            <!-- Table -->
            <div class="p-3">
                <div class="table-responsive">
                    <table id="approvalsTable" class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col">#</th>
                                <th scope="col">Staff</th>
                                <th scope="col">Trip Date</th>
                                <th scope="col">Destination</th>
                                <th scope="col">Vehicle</th>
                                <th scope="col">Passengers</th>
                                <th scope="col">Status</th>
                                <th scope="col">Submitted</th>
                                <th scope="col" class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($display_requests as $i => $req): ?>
                            <?php
                                $status_badge = requestStatusBadgeClass($req['status']);
                                $status_label = ucfirst($req['status']);

                                // Vehicle label
                                if (!empty($req['vehicle_id']) && !empty($req['plate_number'])) {
                                    $vehicle_label = $req['plate_number'];
                                    $vehicle_sub   = trim(($req['brand'] ?? '') . ' ' . ($req['model'] ?? ''));
                                } else {
                                    $vehicle_label = 'Any';
                                    $vehicle_sub   = '';
                                }

                                // Supporting documents count
                                $doc_count = count($request_documents[(int)$req['request_id']] ?? []);

                                // Submitted date
                                $submitted = date('d M Y', strtotime($req['created_at']));
                            ?>
                            <tr>
                                <td class="text-muted small"><?php echo $i + 1; ?></td>
                                <td>
                                    <div class="fw-semibold">
                                        <?php echo htmlspecialchars($req['staff_name']); ?>
                                        <?php if ($doc_count > 0): ?>
                                        <span class="badge rounded-pill ms-1 align-middle"
                                              style="background:#e8f5ee;color:#0b5d3b;font-weight:600;"
                                              title="<?php echo $doc_count; ?> supporting document(s) attached">
                                            <i class="fas fa-paperclip me-1" aria-hidden="true"></i><?php echo $doc_count; ?>
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="small text-muted"><?php echo htmlspecialchars($req['staff_department'] ?? ''); ?></div>
                                </td>
                                <td class="small">
                                    <div class="fw-medium"><?php echo formatDate($req['trip_date']); ?></div>
                                    <div class="text-muted">
                                        <?php echo formatTime($req['start_time']); ?>
                                        &ndash;
                                        <?php echo formatTime($req['end_time']); ?>
                                    </div>
                                </td>
                                <td class="small"><?php echo htmlspecialchars($req['destination']); ?></td>
                                <td class="small">
                                    <?php if ($vehicle_label === 'Any'): ?>
                                        <span class="text-muted fst-italic">Any</span>
                                    <?php else: ?>
                                        <div class="fw-medium font-monospace"><?php echo htmlspecialchars($vehicle_label); ?></div>
                                        <?php if ($vehicle_sub !== ''): ?>
                                        <div class="text-muted"><?php echo htmlspecialchars($vehicle_sub); ?></div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td class="small text-center">
                                    <i class="fas fa-users text-muted me-1" aria-hidden="true"></i><?php echo (int)$req['passenger_count']; ?>
                                </td>
                                <td>
                                    <span class="badge <?php echo $status_badge; ?> rounded-pill">
                                        <?php echo htmlspecialchars($status_label); ?>
                                    </span>
                                </td>
                                <td class="small text-muted"
                                    data-order="<?php echo htmlspecialchars($req['created_at']); ?>">
                                    <?php echo $submitted; ?>
                                </td>
                                <td class="text-center">
                                    <div class="d-flex justify-content-center gap-1 flex-nowrap">
                                        <!-- View Details -->
                                        <button type="button"
                                                class="btn btn-outline-info btn-action"
                                                title="View Details"
                                                onclick="viewRequest(<?php echo (int)$req['request_id']; ?>)"
                                                aria-label="View vehicle request details">
                                            <i class="fas fa-eye" aria-hidden="true"></i>
                                        </button>
                                        <?php if ($req['status'] === 'pending'): ?>
                                        <!-- Approve -->
                                        <button type="button"
                                                class="btn btn-outline-success btn-action"
                                                title="Approve"
                                                onclick="openAction(<?php echo (int)$req['request_id']; ?>, 'approve', '<?php echo htmlspecialchars(addslashes($req['staff_name'])); ?>')"
                                                aria-label="Approve vehicle request">
                                            <i class="fas fa-check" aria-hidden="true"></i>
                                        </button>
                                        <!-- Reject -->
                                        <button type="button"
                                                class="btn btn-outline-danger btn-action"
                                                title="Reject"
                                                onclick="openAction(<?php echo (int)$req['request_id']; ?>, 'reject', '<?php echo htmlspecialchars(addslashes($req['staff_name'])); ?>')"
                                                aria-label="Reject vehicle request">
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
                    <i class="fas fa-clipboard-check fa-3x mb-3 opacity-25"></i>
                    <p class="mb-0">No <?php echo $active_tab !== 'all' ? htmlspecialchars($active_tab) . ' ' : ''; ?>vehicle requests found.</p>
                </div>
                <?php endif; ?>

            </div><!-- /p-3 -->
        </div><!-- /.card-body -->
    </div><!-- /.table-card -->

</main><!-- /.main-content -->


<!-- ================================================================
     VIEW DETAILS MODAL
     ================================================================ -->
<div class="modal fade" id="viewRequestModal" tabindex="-1" aria-labelledby="viewRequestModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content rounded-3">
            <div class="modal-header text-white"
                 style="background: linear-gradient(135deg,#0b5d3b 0%,#15804f 100%);">
                <h5 class="modal-title fw-bold" id="viewRequestModalLabel">
                    <i class="fas fa-clipboard-check me-2" aria-hidden="true"></i>
                    Vehicle Request Details
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4" id="viewRequestBody">
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
                 style="background: linear-gradient(135deg,#0b5d3b 0%,#15804f 100%);">
                <h5 class="modal-title fw-bold" id="actionModalLabel">Confirm Action</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <p id="actionModalDesc" class="mb-3"></p>
                <div class="mb-3">
                    <label for="supervisorNotes" class="form-label fw-semibold">
                        Supervisor Notes
                        <span id="notesRequired" class="text-danger ms-1">*</span>
                    </label>
                    <textarea id="supervisorNotes"
                              class="form-control"
                              rows="3"
                              placeholder="Enter notes for the staff member…"
                              style="border-radius: 10px; border: 1.5px solid #e5e9f0;"></textarea>
                    <div class="invalid-feedback" id="supervisorNotesError">
                        Supervisor notes are required when rejecting a request.
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


<!-- ── Embedded request data (JSON for JS) ── -->
<script>
const REQUEST_DATA = <?php
    $json_data = [];
    foreach ($all_requests as $r) {
        $json_data[] = [
            'request_id'       => (int)$r['request_id'],
            'staff_name'       => $r['staff_name'],
            'staff_department' => $r['staff_department'] ?? '',
            'trip_date'        => $r['trip_date'],
            'start_time'       => $r['start_time'],
            'end_time'         => $r['end_time'],
            'destination'      => $r['destination'],
            'purpose'          => $r['purpose'],
            'passenger_count'  => (int)$r['passenger_count'],
            'officer_name'     => $r['officer_name']  ?? '',
            'officer_phone'    => $r['officer_phone'] ?? '',
            'waiting_place'    => $r['waiting_place'] ?? '',
            'vehicle_id'      => $r['vehicle_id'] !== null ? (int)$r['vehicle_id'] : null,
            'plate_number'     => $r['plate_number'] ?? '',
            'brand'            => $r['brand'] ?? '',
            'model'            => $r['model'] ?? '',
            'vehicle_type'     => $r['vehicle_type'] ?? '',
            'capacity'         => $r['capacity'] !== null ? (int)$r['capacity'] : null,
            'status'           => $r['status'],
            'supervisor_notes' => $r['supervisor_notes'] ?? '',
            'reviewed_at'      => $r['reviewed_at'] ?? '',
            'created_at'       => $r['created_at'],
            'documents'        => $request_documents[(int)$r['request_id']] ?? [],
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

    // ── Supporting documents list (URLs come from the server only) ──
    function documentsHtml(docs) {
        if (!docs || !docs.length) {
            return '<div class="small text-muted">No documents attached.</div>';
        }
        var html = '<ul class="list-unstyled mb-0">';
        docs.forEach(function (d) {
            var isPdf = d.mime_type === 'application/pdf';
            var icon  = isPdf
                ? '<i class="fas fa-file-pdf fa-lg" style="color:#b91c1c;"></i>'
                : '<i class="fas fa-file-image fa-lg" style="color:#0b5d3b;"></i>';
            var url = String(d.url || '');
            var dl  = url + (url.indexOf('?') === -1 ? '?download=1' : '&download=1');
            html +=
                '<li class="d-flex align-items-center gap-2 p-2 mb-2 border rounded bg-white">'
              +   '<span class="flex-shrink-0 text-center" style="width:1.6rem;">' + icon + '</span>'
              +   '<div class="flex-grow-1" style="min-width:0;">'
              +     '<div class="text-truncate fw-semibold small" title="' + escHtml(d.original_name) + '">'
              +       escHtml(d.original_name)
              +     '</div>'
              +     '<div class="d-flex align-items-center gap-2 flex-wrap">'
              +       '<span class="badge rounded-pill" style="background:#e8f5ee;color:#0b5d3b;font-weight:600;">'
              +         escHtml(d.doc_type_label)
              +       '</span>'
              +       '<span class="text-muted" style="font-size:0.75rem;">' + escHtml(d.size_label) + '</span>'
              +     '</div>'
              +   '</div>'
              +   '<div class="flex-shrink-0 d-flex gap-1">'
              +     '<a href="' + escHtml(url) + '" target="_blank" rel="noopener" '
              +        'class="btn btn-sm btn-outline-success py-0 px-2"><i class="fas fa-eye me-1"></i>View</a>'
              +     '<a href="' + escHtml(dl) + '" rel="noopener" '
              +        'class="btn btn-sm btn-outline-secondary py-0 px-2"><i class="fas fa-download me-1"></i>Download</a>'
              +   '</div>'
              + '</li>';
        });
        return html + '</ul>';
    }

    // ── Phone number as tel: link ───────────────────────────
    function telLink(phone) {
        if (!phone) return '<span class="text-muted fst-italic">&mdash;</span>';
        return '<a href="tel:' + escHtml(String(phone).replace(/[^0-9+]/g, '')) + '" class="text-decoration-none">'
             + escHtml(phone) + '</a>';
    }

    // ── Format date string (YYYY-MM-DD) to readable form ──
    function fmtDate(str) {
        if (!str) return '&mdash;';
        var d = new Date(str);
        var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        return d.getDate() + ' ' + months[d.getMonth()] + ' ' + d.getFullYear();
    }

    // ── Format time string (HH:MM:SS) to 12-hour form ─────
    function fmtTime(str) {
        if (!str) return '&mdash;';
        var parts = str.split(':');
        var h = parseInt(parts[0], 10);
        var m = parts[1] || '00';
        var suffix = h >= 12 ? 'pm' : 'am';
        h = h % 12;
        if (h === 0) h = 12;
        return h + ':' + m + ' ' + suffix;
    }

    // ── Status badge map ────────────────────────────────────
    var statusBadgeMap = {
        pending:   'bg-warning text-dark',
        approved:  'bg-success',
        rejected:  'bg-danger',
        processed: 'bg-primary',
    };

    // ── DataTable initialisation ─────────────────────────────
    $('#approvalsTable').DataTable({
        order:      [[7, 'desc']],   // sort by Submitted desc
        pageLength: 25,
        responsive: true,
        columnDefs: [
            { orderable: false, targets: 8 },
            { searchable: false, targets: [0, 8] }
        ],
        language: {
            search:         'Search:',
            lengthMenu:     'Show _MENU_ entries per page',
            info:           'Showing _START_ to _END_ of _TOTAL_ requests',
            infoEmpty:      'No requests found',
            emptyTable:     'No vehicle requests found',
            zeroRecords:    'No requests match the search'
        }
    });

    // ── View Details Modal ────────────────────────────────────
    window.viewRequest = function (requestId) {
        var r = REQUEST_DATA.find(function (row) { return row.request_id === requestId; });
        if (!r) return;

        var statusBadge = statusBadgeMap[r.status] || 'bg-secondary';

        var vehicleHtml;
        if (r.vehicle_id && r.plate_number) {
            vehicleHtml = '<span class="font-monospace fw-semibold">' + escHtml(r.plate_number) + '</span>'
                        + ' &middot; ' + escHtml((r.brand + ' ' + r.model).trim());
            if (r.vehicle_type) {
                vehicleHtml += ' <span class="text-muted">(' + escHtml(r.vehicle_type)
                             + (r.capacity ? ', ' + r.capacity + ' seats' : '')
                             + ')</span>';
            }
        } else {
            vehicleHtml = '<span class="text-muted fst-italic">Any available vehicle</span>';
        }

        var body = '<div class="row g-3">'

            // Staff & status header
            + '<div class="col-12">'
            +   '<div class="d-flex align-items-center gap-3 flex-wrap">'
            +     '<div style="width:48px;height:48px;border-radius:50%;background:linear-gradient(135deg,#0b5d3b,#15804f);'
            +          'display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:1.1rem;">'
            +       escHtml(r.staff_name.charAt(0).toUpperCase())
            +     '</div>'
            +     '<div>'
            +       '<div class="fw-bold fs-5">' + escHtml(r.staff_name) + '</div>'
            +       '<div class="small text-muted">' + escHtml(r.staff_department) + '</div>'
            +     '</div>'
            +     '<div class="ms-auto d-flex gap-2 flex-wrap">'
            +       '<span class="badge ' + statusBadge + ' rounded-pill">' + escHtml(r.status.charAt(0).toUpperCase() + r.status.slice(1)) + '</span>'
            +     '</div>'
            +   '</div>'
            +   '<hr class="mt-3 mb-0">'
            + '</div>'

            // Trip date & time
            + '<div class="col-md-6">'
            +   '<div class="detail-label">Trip Date</div>'
            +   '<div class="detail-value">' + fmtDate(r.trip_date) + '</div>'
            +   '<div class="small text-muted">' + fmtTime(r.start_time) + ' &ndash; ' + fmtTime(r.end_time) + '</div>'
            + '</div>'
            + '<div class="col-md-6">'
            +   '<div class="detail-label">Date Submitted</div>'
            +   '<div class="detail-value">' + fmtDate(r.created_at ? r.created_at.substring(0, 10) : '') + '</div>'
            + '</div>'

            // Destination & passengers
            + '<div class="col-md-6">'
            +   '<div class="detail-label">Destination</div>'
            +   '<div class="detail-value">' + escHtml(r.destination) + '</div>'
            + '</div>'
            + '<div class="col-md-6">'
            +   '<div class="detail-label">Passengers</div>'
            +   '<div class="detail-value">' + r.passenger_count + '</div>'
            + '</div>'

            // Officer details
            + '<div class="col-12">'
            +   '<div class="detail-label">Officer Name(s)</div>'
            +   '<div class="detail-value">' + (r.officer_name ? escHtml(r.officer_name) : '<span class="text-muted fst-italic">&mdash;</span>') + '</div>'
            + '</div>'
            + '<div class="col-md-6">'
            +   '<div class="detail-label">Officer Phone No.</div>'
            +   '<div class="detail-value">' + telLink(r.officer_phone) + '</div>'
            + '</div>'
            + '<div class="col-md-6">'
            +   '<div class="detail-label">Waiting Place</div>'
            +   '<div class="detail-value">' + (r.waiting_place ? escHtml(r.waiting_place) : '<span class="text-muted fst-italic">&mdash;</span>') + '</div>'
            + '</div>'

            // Requested vehicle
            + '<div class="col-12">'
            +   '<div class="detail-label">Requested Vehicle</div>'
            +   '<div class="detail-value">' + vehicleHtml + '</div>'
            + '</div>'

            // Supporting documents
            + '<div class="col-12">'
            +   '<div class="detail-label">Supporting Documents</div>'
            +   documentsHtml(r.documents)
            + '</div>'

            // Purpose
            + '<div class="col-12">'
            +   '<div class="detail-label">Purpose</div>'
            +   '<div class="p-3 rounded-3" style="background:#f8f9fb;font-size:.9rem;line-height:1.6;">'
            +     (r.purpose ? escHtml(r.purpose) : '<span class="text-muted fst-italic">No purpose provided.</span>')
            +   '</div>'
            + '</div>';

        // Supervisor notes & review date (only when reviewed)
        if (r.status !== 'pending') {
            body += '<div class="col-12">'
                  +   '<hr class="mb-2">'
                  +   '<div class="detail-label">Supervisor Notes</div>'
                  +   '<div class="p-3 rounded-3" style="background:#f8f9fb;font-size:.9rem;line-height:1.6;">'
                  +     (r.supervisor_notes ? escHtml(r.supervisor_notes) : '<span class="text-muted fst-italic">No notes added.</span>')
                  +   '</div>'
                  + '</div>'
                  + '<div class="col-12">'
                  +   '<div class="detail-label">Reviewed At</div>'
                  +   '<div class="detail-value">' + (r.reviewed_at ? fmtDate(r.reviewed_at.substring(0, 10)) : '&mdash;') + '</div>'
                  + '</div>';
        }

        body += '</div>';

        document.getElementById('viewRequestBody').innerHTML = body;
        var modal = new bootstrap.Modal(document.getElementById('viewRequestModal'));
        modal.show();
    };


    // ── Action modal state ───────────────────────────────────
    var _actionRequestId = 0;
    var _actionType      = '';

    window.openAction = function (requestId, action, staffName) {
        _actionRequestId = requestId;
        _actionType      = action;

        var isReject = (action === 'reject');

        var header = document.getElementById('actionModalHeader');
        var label  = document.getElementById('actionModalLabel');
        var desc   = document.getElementById('actionModalDesc');
        var btn    = document.getElementById('actionConfirmBtn');
        var req    = document.getElementById('notesRequired');
        var notes  = document.getElementById('supervisorNotes');

        if (isReject) {
            header.style.background = 'linear-gradient(135deg,#991b1b,#dc2626)';
            label.textContent  = 'Reject Vehicle Request';
            desc.innerHTML     = 'You are rejecting the vehicle request from <strong>' + escHtml(staffName) + '</strong>. Please provide a reason below.';
            btn.className      = 'btn btn-danger';
            btn.innerHTML      = '<i class="fas fa-xmark me-1"></i> Reject Request';
            req.style.display  = 'inline';
        } else {
            header.style.background = 'linear-gradient(135deg,#065f46,#059669)';
            label.textContent  = 'Approve Vehicle Request';
            desc.innerHTML     = 'You are approving the vehicle request from <strong>' + escHtml(staffName) + '</strong>. You may optionally add a note below.';
            btn.className      = 'btn btn-success';
            btn.innerHTML      = '<i class="fas fa-check me-1"></i> Approve Request';
            req.style.display  = 'none';
        }

        // Remind the Head of Section when the request carries documents
        var reqData = REQUEST_DATA.find(function (row) { return row.request_id === requestId; });
        var docCount = reqData && reqData.documents ? reqData.documents.length : 0;
        if (docCount > 0) {
            desc.innerHTML += '<div class="small mt-2 p-2 rounded" style="background:#e8f5ee;color:#0b5d3b;">'
                            + '<i class="fas fa-paperclip me-1"></i>' + docCount + ' document(s) attached &mdash; '
                            + 'review them in View before approving</div>';
        }

        // Reset notes field
        notes.value = '';
        notes.classList.remove('is-invalid');

        var modal = new bootstrap.Modal(document.getElementById('actionModal'));
        modal.show();
    };

    window.submitAction = function () {
        var notes = document.getElementById('supervisorNotes').value.trim();

        // Reject requires notes
        if (_actionType === 'reject' && notes === '') {
            document.getElementById('supervisorNotes').classList.add('is-invalid');
            return;
        }

        document.getElementById('supervisorNotes').classList.remove('is-invalid');

        var btn = document.getElementById('actionConfirmBtn');
        btn.disabled    = true;
        btn.innerHTML   = '<span class="spinner-border spinner-border-sm me-1"></span> Processing…';

        fetch(SITE_URL + '/ajax/request_action.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                request_id: _actionRequestId,
                action:     _actionType,
                notes:      notes
            })
        })
        .then(function (response) { return response.json(); })
        .then(function (res) {
            bootstrap.Modal.getInstance(document.getElementById('actionModal')).hide();

            if (res.success) {
                Swal.fire({
                    icon:              'success',
                    title:             _actionType === 'approve' ? 'Approved!' : 'Rejected!',
                    text:              res.message,
                    timer:             1800,
                    showConfirmButton: false
                }).then(function () {
                    // Reload keeps the current URL, so the active ?status tab is preserved
                    location.reload();
                });
            } else {
                Swal.fire({
                    icon:  'error',
                    title: 'Failed',
                    text:  res.message || 'Something went wrong. Please try again.'
                });
            }
        })
        .catch(function () {
            bootstrap.Modal.getInstance(document.getElementById('actionModal')).hide();
            Swal.fire({ icon: 'error', title: 'Network Error', text: 'Could not reach the server. Please try again.' });
        })
        .finally(function () {
            btn.disabled = false;
        });
    };

})();
</script>

</body>
</html>
