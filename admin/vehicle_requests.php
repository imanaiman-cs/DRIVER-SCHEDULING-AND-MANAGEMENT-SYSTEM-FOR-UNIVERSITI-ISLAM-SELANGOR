<?php
// ============================================================
// UIS Driver Scheduling and Management System
// admin/vehicle_requests.php  –  e-Kenderaan Vehicle Requests
// Universiti Islam Selangor (UIS)
//
// Staff submit requests -> supervisor approves -> admin
// processes approved requests into schedules on this page.
// ============================================================

require_once '../config/database.php';
require_once '../includes/upload.php';
requireAdmin();

$page_title   = 'Vehicle Requests';
$current_page = 'vehicle_requests.php';

// ── Fetch all vehicle requests (e-Kenderaan) ─────────────────
$sql = "
    SELECT vr.*,
           s.full_name   AS staff_name,
           s.department,
           sup.full_name AS supervisor_name,
           v.plate_number, v.brand, v.model, v.vehicle_type, v.capacity
    FROM vehicle_requests vr
    JOIN users s        ON vr.staff_id      = s.user_id
    LEFT JOIN users sup ON vr.supervisor_id = sup.user_id
    LEFT JOIN vehicles v ON vr.vehicle_id   = v.vehicle_id
    ORDER BY FIELD(vr.status, 'approved') DESC, vr.created_at DESC
";
$result       = $conn->query($sql);
$all_requests = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

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
$approved  = 0;   // supervisor-approved, awaiting admin processing
$pending   = 0;   // awaiting supervisor review
$processed = 0;
$rejected  = 0;
$cancelled = 0;   // withdrawn by staff – never counted as pending/awaiting

foreach ($all_requests as $r) {
    switch ($r['status']) {
        case 'approved':  $approved++;  break;
        case 'pending':   $pending++;   break;
        case 'processed': $processed++; break;
        case 'rejected':  $rejected++;  break;
        case 'cancelled': $cancelled++; break;
    }
}

// ── Apply tab filter ─────────────────────────────────────────
$active_tab = $_GET['status'] ?? 'all';
$active_tab = in_array($active_tab, ['all', 'approved', 'pending', 'processed', 'rejected', 'cancelled'], true)
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
        .stat-card.stat-actionable {
            border-left: 4px solid #0b5d3b;
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
        <h1><i class="fas fa-file-signature me-2" aria-hidden="true"></i>Vehicle Requests (e-Kenderaan)</h1>
        <p>Process supervisor-approved staff vehicle requests into trip schedules.</p>
    </div>

    <!-- Flash message -->
    <?php showFlash(); ?>

    <!-- ── Summary Cards ──────────────────────────────────────── -->
    <div class="row g-3 mb-4">

        <!-- Awaiting Processing (actionable) -->
        <div class="col-6 col-md-3">
            <a href="?status=approved" class="text-decoration-none">
                <div class="card stat-card stat-actionable h-100 p-3">
                    <div class="d-flex align-items-center gap-3">
                        <div class="stat-icon bg-success bg-opacity-10 text-success">
                            <i class="fas fa-calendar-plus" aria-hidden="true"></i>
                        </div>
                        <div>
                            <div class="stat-value text-success"><?php echo $approved; ?></div>
                            <div class="stat-label">Awaiting Processing</div>
                        </div>
                    </div>
                </div>
            </a>
        </div>

        <!-- Pending Supervisor -->
        <div class="col-6 col-md-3">
            <a href="?status=pending" class="text-decoration-none">
                <div class="card stat-card h-100 p-3">
                    <div class="d-flex align-items-center gap-3">
                        <div class="stat-icon bg-warning bg-opacity-10 text-warning">
                            <i class="fas fa-user-clock" aria-hidden="true"></i>
                        </div>
                        <div>
                            <div class="stat-value text-warning"><?php echo $pending; ?></div>
                            <div class="stat-label">Pending Supervisor</div>
                        </div>
                    </div>
                </div>
            </a>
        </div>

        <!-- Processed -->
        <div class="col-6 col-md-3">
            <a href="?status=processed" class="text-decoration-none">
                <div class="card stat-card h-100 p-3">
                    <div class="d-flex align-items-center gap-3">
                        <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                            <i class="fas fa-circle-check" aria-hidden="true"></i>
                        </div>
                        <div>
                            <div class="stat-value text-primary"><?php echo $processed; ?></div>
                            <div class="stat-label">Processed</div>
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
                    <i class="fas fa-table me-1" aria-hidden="true"></i> Vehicle Requests
                </h6>
                <!-- Status Tabs -->
                <ul class="nav status-tabs gap-1 mb-0">
                    <li class="nav-item">
                        <a class="nav-link <?php echo $active_tab === 'all' ? 'active' : ''; ?>"
                           href="?status=all">
                            All
                            <span class="badge ms-1 <?php echo $active_tab === 'all' ? 'bg-white text-primary' : 'bg-primary'; ?> rounded-pill">
                                <?php echo $total; ?>
                            </span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $active_tab === 'approved' ? 'active' : ''; ?>"
                           href="?status=approved">
                            Awaiting Processing
                            <?php if ($approved > 0): ?>
                            <span class="badge ms-1 <?php echo $active_tab === 'approved' ? 'bg-white text-success' : 'bg-success'; ?> rounded-pill">
                                <?php echo $approved; ?>
                            </span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $active_tab === 'pending' ? 'active' : ''; ?>"
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
                        <a class="nav-link <?php echo $active_tab === 'processed' ? 'active' : ''; ?>"
                           href="?status=processed">
                            Processed
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $active_tab === 'rejected' ? 'active' : ''; ?>"
                           href="?status=rejected">
                            Rejected
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $active_tab === 'cancelled' ? 'active' : ''; ?>"
                           href="?status=cancelled">
                            Cancelled
                            <?php if ($cancelled > 0): ?>
                            <span class="badge ms-1 <?php echo $active_tab === 'cancelled' ? 'bg-white text-secondary' : 'bg-secondary'; ?> rounded-pill">
                                <?php echo $cancelled; ?>
                            </span>
                            <?php endif; ?>
                        </a>
                    </li>
                </ul>
            </div>

            <!-- Table -->
            <div class="p-3">
                <div class="table-responsive">
                    <table id="requestsTable" class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col">Staff</th>
                                <th scope="col">Supervisor</th>
                                <th scope="col">Trip Date</th>
                                <th scope="col">Destination</th>
                                <th scope="col">Vehicle</th>
                                <th scope="col">Pax</th>
                                <th scope="col">Status</th>
                                <th scope="col">Submitted</th>
                                <th scope="col" class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($display_requests as $req): ?>
                            <?php
                                $status_badge = requestStatusBadgeClass($req['status']);
                                $status_label = ucfirst($req['status']);
                                $submitted    = date('d M Y', strtotime($req['created_at']));
                                $doc_count    = count($request_documents[(int)$req['request_id']] ?? []);
                            ?>
                            <tr>
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
                                    <div class="small text-muted"><?php echo htmlspecialchars($req['department'] ?? '—'); ?></div>
                                </td>
                                <td class="small">
                                    <?php echo !empty($req['supervisor_name'])
                                        ? htmlspecialchars($req['supervisor_name'])
                                        : '<span class="text-muted">&mdash;</span>'; ?>
                                </td>
                                <td class="small">
                                    <div class="fw-medium"><?php echo formatDate($req['trip_date']); ?></div>
                                    <div class="text-muted">
                                        <?php echo formatTime($req['start_time']); ?> &ndash; <?php echo formatTime($req['end_time']); ?>
                                    </div>
                                </td>
                                <td class="small"><?php echo htmlspecialchars($req['destination']); ?></td>
                                <td class="small">
                                    <?php if (!empty($req['plate_number'])): ?>
                                        <div class="fw-medium font-monospace"><?php echo htmlspecialchars($req['plate_number']); ?></div>
                                        <div class="text-muted"><?php echo htmlspecialchars(trim(($req['vehicle_type'] ?? '') . ' ' . ($req['brand'] ?? ''))); ?></div>
                                    <?php else: ?>
                                        <span class="badge bg-light text-muted border">Any</span>
                                    <?php endif; ?>
                                </td>
                                <td class="small text-center"><?php echo (int)$req['passenger_count']; ?></td>
                                <td>
                                    <span class="badge <?php echo $status_badge; ?> rounded-pill">
                                        <?php echo htmlspecialchars($req['status'] === 'approved' ? 'Awaiting Processing' : $status_label); ?>
                                    </span>
                                </td>
                                <td class="small text-muted" data-order="<?php echo htmlspecialchars($req['created_at']); ?>">
                                    <?php echo $submitted; ?>
                                </td>
                                <td class="text-center">
                                    <div class="d-flex justify-content-center gap-1 flex-nowrap">
                                        <?php if ($req['status'] === 'approved'): ?>
                                        <!-- Process into schedule -->
                                        <button type="button"
                                                class="btn btn-uis-primary btn-action"
                                                title="Process into Schedule"
                                                onclick="openProcess(<?php echo (int)$req['request_id']; ?>)"
                                                aria-label="Process request into a schedule">
                                            <i class="fas fa-calendar-plus" aria-hidden="true"></i>
                                        </button>
                                        <?php endif; ?>
                                        <!-- View Details -->
                                        <button type="button"
                                                class="btn btn-outline-info btn-action"
                                                title="View Details"
                                                onclick="viewRequest(<?php echo (int)$req['request_id']; ?>)"
                                                aria-label="View request details">
                                            <i class="fas fa-eye" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div><!-- /.table-responsive -->

                <?php if (empty($display_requests)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="fas fa-file-circle-check fa-3x mb-3 opacity-25"></i>
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
                    <i class="fas fa-file-signature me-2" aria-hidden="true"></i>
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
     PROCESS REQUEST MODAL
     ================================================================ -->
<div class="modal fade" id="processModal" tabindex="-1" aria-labelledby="processModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content rounded-3">
            <div class="modal-header text-white"
                 style="background: linear-gradient(135deg,#0b5d3b 0%,#15804f 100%);">
                <h5 class="modal-title fw-bold" id="processModalLabel">
                    <i class="fas fa-calendar-plus me-2" aria-hidden="true"></i>
                    Process Request into Schedule
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <p class="mb-3">Create a schedule from this supervisor-approved request:</p>
                <div id="processSummary" class="p-3 rounded-3 mb-3" style="background:#f8f9fb;font-size:.9rem;">
                    <!-- Populated by JS -->
                </div>
                <h6 class="fw-bold mb-3 mt-4" style="color:#0b5d3b;">
                    <i class="fas fa-user-check me-2" aria-hidden="true"></i>Assign now <span class="fw-normal text-muted small">(optional)</span>
                </h6>
                <div class="row g-3">
                    <div class="col-12">
                        <label for="processTripType" class="form-label fw-semibold">Trip type</label>
                        <select id="processTripType" class="form-select">
                            <option value="regular">Regular</option>
                            <option value="top_management">Top Management (VIP)</option>
                        </select>
                        <div class="form-text">Top Management trips are only offered to Top Management drivers.</div>
                    </div>
                    <div class="col-12">
                        <label for="processVehicle" class="form-label fw-semibold">Vehicle</label>
                        <select id="processVehicle" class="form-select" disabled>
                            <option value="0">Loading vehicles&hellip;</option>
                        </select>
                        <div id="processVehicleHint" class="form-text" aria-live="polite"></div>
                    </div>
                    <div class="col-12">
                        <label for="processDriver" class="form-label fw-semibold">Driver</label>
                        <select id="processDriver" class="form-select" disabled>
                            <option value="0">Loading drivers&hellip;</option>
                        </select>
                        <div id="processDriverHint" class="form-text" aria-live="polite"></div>
                    </div>
                </div>
                <div id="processNote" class="alert alert-info py-2 small mt-3 mb-0">
                    <i class="fas fa-circle-info me-1" aria-hidden="true"></i>
                    <span id="processNoteText"></span>
                </div>
            </div>
            <div class="modal-footer gap-2">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-uis-primary" id="processConfirmBtn" onclick="submitProcess()">
                    <i class="fas fa-calendar-plus me-1" aria-hidden="true"></i> Create Schedule
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
            'department'       => $r['department'] ?? '',
            'supervisor_name'  => $r['supervisor_name'] ?? '',
            'supervisor_notes' => $r['supervisor_notes'] ?? '',
            'reviewed_at'      => $r['reviewed_at'] ?? '',
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
            'vehicle_brand'    => $r['brand'] ?? '',
            'vehicle_model'    => $r['model'] ?? '',
            'vehicle_type'     => $r['vehicle_type'] ?? '',
            'vehicle_capacity' => $r['capacity'] !== null ? (int)$r['capacity'] : null,
            'status'           => $r['status'],
            'schedule_id'      => $r['schedule_id'] !== null ? (int)$r['schedule_id'] : null,
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

    // ── Format time string (HH:MM:SS) to 12-hour form ──────
    function fmtTime(str) {
        if (!str) return '&mdash;';
        var parts = str.split(':');
        var h = parseInt(parts[0], 10);
        var m = parts[1] || '00';
        var ampm = h >= 12 ? 'pm' : 'am';
        h = h % 12 || 12;
        return h + ':' + m + ' ' + ampm;
    }

    // ── Status badge map (matches requestStatusBadgeClass) ──
    var statusBadgeMap = {
        pending:   'bg-warning text-dark',
        approved:  'bg-success',
        rejected:  'bg-danger',
        processed: 'bg-primary',
        cancelled: 'bg-secondary',
    };

    var statusLabelMap = {
        pending:   'Pending Supervisor',
        approved:  'Awaiting Processing',
        rejected:  'Rejected',
        processed: 'Processed',
        cancelled: 'Cancelled',
    };

    function findRequest(requestId) {
        return REQUEST_DATA.find(function (row) { return row.request_id === requestId; });
    }

    function vehicleLabel(r) {
        if (!r.plate_number) return '<span class="text-muted">Any available vehicle</span>';
        var extra = [r.vehicle_type, r.vehicle_brand, r.vehicle_model].filter(Boolean).join(' ');
        return escHtml(r.plate_number)
            + (extra ? ' <span class="text-muted">(' + escHtml(extra) + ')</span>' : '')
            + (r.vehicle_capacity ? ' <span class="text-muted">&mdash; ' + r.vehicle_capacity + ' pax</span>' : '');
    }

    // ── DataTable initialisation ─────────────────────────────
    $('#requestsTable').DataTable({
        order:      [[7, 'desc']],   // sort by Submitted desc
        pageLength: 25,
        responsive: true,
        columnDefs: [
            { orderable: false, targets: 8 },
            { searchable: false, targets: [8] }
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
        var r = findRequest(requestId);
        if (!r) return;

        var statusBadge = statusBadgeMap[r.status] || 'bg-secondary';
        var statusLabel = statusLabelMap[r.status] || r.status;

        var body = '<div class="row g-3">'

            // Staff & status header
            + '<div class="col-12">'
            +   '<div class="d-flex align-items-center gap-3 flex-wrap">'
            +     '<div style="width:48px;height:48px;border-radius:50%;background:linear-gradient(135deg,#0b5d3b,#15804f);'
            +          'display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:1.1rem;">'
            +       escHtml((r.staff_name || '?').charAt(0).toUpperCase())
            +     '</div>'
            +     '<div>'
            +       '<div class="fw-bold fs-5">' + escHtml(r.staff_name) + '</div>'
            +       '<div class="small text-muted">' + (r.department ? escHtml(r.department) : '&mdash;') + '</div>'
            +     '</div>'
            +     '<div class="ms-auto d-flex gap-2 flex-wrap">'
            +       '<span class="badge ' + statusBadge + ' rounded-pill">' + escHtml(statusLabel) + '</span>'
            +     '</div>'
            +   '</div>'
            +   '<hr class="mt-3 mb-0">'
            + '</div>'

            // Trip details
            + '<div class="col-md-6">'
            +   '<div class="detail-label">Trip Date</div>'
            +   '<div class="detail-value">' + fmtDate(r.trip_date) + '</div>'
            +   '<div class="small text-muted">' + fmtTime(r.start_time) + ' &ndash; ' + fmtTime(r.end_time) + '</div>'
            + '</div>'
            + '<div class="col-md-6">'
            +   '<div class="detail-label">Date Submitted</div>'
            +   '<div class="detail-value">' + fmtDate(r.created_at ? r.created_at.substring(0, 10) : '') + '</div>'
            + '</div>'

            + '<div class="col-md-6">'
            +   '<div class="detail-label">Destination</div>'
            +   '<div class="detail-value">' + escHtml(r.destination) + '</div>'
            + '</div>'
            + '<div class="col-md-6">'
            +   '<div class="detail-label">Passengers</div>'
            +   '<div class="detail-value">' + r.passenger_count + ' pax</div>'
            + '</div>'

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

            + '<div class="col-12">'
            +   '<div class="detail-label">Requested Vehicle</div>'
            +   '<div class="detail-value">' + vehicleLabel(r) + '</div>'
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

        // Supervisor review (shown once a supervisor has reviewed it; a
        // request cancelled by staff while pending was never reviewed)
        if (r.status !== 'pending' && r.status !== 'cancelled') {
            body += '<div class="col-12"><hr class="mb-2">'
                  +   '<div class="detail-label">Reviewed By (Supervisor)</div>'
                  +   '<div class="detail-value">' + (r.supervisor_name ? escHtml(r.supervisor_name) : '&mdash;') + '</div>'
                  +   (r.reviewed_at
                        ? '<div class="small text-muted">on ' + fmtDate(r.reviewed_at.substring(0, 10)) + '</div>'
                        : '')
                  + '</div>'
                  + '<div class="col-12">'
                  +   '<div class="detail-label">Supervisor Notes</div>'
                  +   '<div class="p-3 rounded-3" style="background:#f8f9fb;font-size:.9rem;line-height:1.6;">'
                  +     (r.supervisor_notes ? escHtml(r.supervisor_notes) : '<span class="text-muted fst-italic">No notes added.</span>')
                  +   '</div>'
                  + '</div>';
        } else if (r.supervisor_name) {
            body += '<div class="col-12"><hr class="mb-2">'
                  +   '<div class="detail-label">Assigned Supervisor</div>'
                  +   '<div class="detail-value">' + escHtml(r.supervisor_name) + '</div>'
                  + '</div>';
        }

        // Link to the created schedule when processed
        if (r.status === 'processed' && r.schedule_id) {
            body += '<div class="col-12">'
                  +   '<a href="' + SITE_URL + '/admin/view_schedule.php?id=' + r.schedule_id + '" class="btn btn-uis-primary btn-sm">'
                  +     '<i class="fas fa-calendar-check me-1" aria-hidden="true"></i>'
                  +     'View Schedule #' + String(r.schedule_id).padStart(4, '0')
                  +   '</a>'
                  + '</div>';
        }

        body += '</div>';

        document.getElementById('viewRequestBody').innerHTML = body;
        var modal = new bootstrap.Modal(document.getElementById('viewRequestModal'));
        modal.show();
    };


    // ── Process modal ────────────────────────────────────────
    var _processRequestId = 0;

    window.openProcess = function (requestId) {
        var r = findRequest(requestId);
        if (!r || r.status !== 'approved') return;

        _processRequestId = requestId;

        var summary = '<div class="row g-2">'
            + '<div class="col-6"><div class="detail-label">Staff</div>'
            +   '<div class="detail-value">' + escHtml(r.staff_name) + '</div>'
            +   '<div class="small text-muted">' + (r.department ? escHtml(r.department) : '&mdash;') + '</div></div>'
            + '<div class="col-6"><div class="detail-label">Trip Date</div>'
            +   '<div class="detail-value">' + fmtDate(r.trip_date) + '</div>'
            +   '<div class="small text-muted">' + fmtTime(r.start_time) + ' &ndash; ' + fmtTime(r.end_time) + '</div></div>'
            + '<div class="col-6"><div class="detail-label">Destination</div>'
            +   '<div class="detail-value">' + escHtml(r.destination) + '</div></div>'
            + '<div class="col-6"><div class="detail-label">Passengers</div>'
            +   '<div class="detail-value">' + r.passenger_count + ' pax</div></div>'
            + '<div class="col-12"><div class="detail-label">Officer Name(s)</div>'
            +   '<div class="detail-value">' + (r.officer_name ? escHtml(r.officer_name) : '&mdash;') + '</div></div>'
            + '<div class="col-6"><div class="detail-label">Officer Phone No.</div>'
            +   '<div class="detail-value">' + telLink(r.officer_phone) + '</div></div>'
            + '<div class="col-6"><div class="detail-label">Waiting Place</div>'
            +   '<div class="detail-value">' + (r.waiting_place ? escHtml(r.waiting_place) : '&mdash;') + '</div></div>'
            + '<div class="col-12"><div class="detail-label">Requested vehicle</div>'
            +   '<div class="detail-value">' + vehicleLabel(r) + '</div></div>'
            + '<div class="col-12"><div class="detail-label">Supporting Documents</div>'
            +   '<div class="detail-value">'
            +     ((r.documents && r.documents.length)
                    ? '<i class="fas fa-paperclip me-1" style="color:#0b5d3b;" aria-hidden="true"></i>'
                      + r.documents.length + ' document(s) attached'
                    : '<span class="text-muted fw-normal">No documents attached.</span>')
            +   '</div></div>'
            + '</div>';

        document.getElementById('processSummary').innerHTML = summary;

        var btn = document.getElementById('processConfirmBtn');
        btn.disabled  = false;
        btn.innerHTML = '<i class="fas fa-calendar-plus me-1" aria-hidden="true"></i> Create Schedule';

        document.getElementById('processTripType').value = 'regular';
        loadProcessVehicles(r);

        var modal = new bootstrap.Modal(document.getElementById('processModal'));
        modal.show();
    };

    // ── Vehicle and driver pickers ────────────────────────────
    function setHint(id, text, tone) {
        var el = document.getElementById(id);
        el.textContent = text || '';
        el.className = 'form-text' + (tone ? ' text-' + tone + ' fw-semibold' : '');
    }

    function updateProcessNote() {
        var driverId = parseInt(document.getElementById('processDriver').value, 10) || 0;
        document.getElementById('processNoteText').textContent = driverId > 0
            ? 'The schedule will be created as Approved with this driver, and the driver is e-mailed the details.'
            : 'No driver chosen: the schedule stays Pending and unassigned. Use Auto Assign later to pick the best available driver.';
    }

    function loadProcessVehicles(r) {
        var vSel = document.getElementById('processVehicle');
        vSel.disabled = true;
        vSel.innerHTML = '<option value="0">Loading vehicles&hellip;</option>';
        setHint('processVehicleHint', '');

        var q = new URLSearchParams({
            trip_date: r.trip_date, start_time: r.start_time, end_time: r.end_time,
            passengers: r.passenger_count
        });
        fetch(SITE_URL + '/ajax/get_free_vehicles.php?' + q.toString(), { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                var list = (data && data.success && data.vehicles) ? data.vehicles : [];
                var html = '<option value="0">&mdash; Decide later (no vehicle yet) &mdash;</option>';
                var requested = r.vehicle_id ? parseInt(r.vehicle_id, 10) : 0;
                var foundRequested = false;
                list.forEach(function (v) {
                    var isReq = v.vehicle_id === requested;
                    if (isReq) foundRequested = true;
                    var name = [v.brand, v.model].filter(Boolean).join(' ') || v.vehicle_type;
                    html += '<option value="' + v.vehicle_id + '"' + (isReq ? ' selected' : '') + '>'
                         + escHtml(v.plate_number + ' \u2014 ' + name + ' (' + v.vehicle_type + ', ' + v.capacity + ' seats)'
                         + (isReq ? ' \u00b7 requested' : '')) + '</option>';
                });
                vSel.innerHTML = html;
                vSel.disabled = false;

                if (requested && !foundRequested) {
                    setHint('processVehicleHint', 'The vehicle the staff asked for is no longer free for this time. Pick another one or decide later.', 'danger');
                } else if (!requested) {
                    setHint('processVehicleHint', list.length + ' vehicle(s) free with enough seats. The staff chose \u201cany available vehicle\u201d.');
                } else {
                    setHint('processVehicleHint', 'Showing vehicles that are free at this time and seat ' + r.passenger_count + ' or more.');
                }
                loadProcessDrivers();
            })
            .catch(function () {
                vSel.innerHTML = '<option value="0">&mdash; Decide later (no vehicle yet) &mdash;</option>';
                vSel.disabled = false;
                setHint('processVehicleHint', 'Could not load vehicles. You can still create the schedule and choose later.', 'danger');
                loadProcessDrivers();
            });
    }

    function loadProcessDrivers() {
        var r    = findRequest(_processRequestId);
        var dSel = document.getElementById('processDriver');
        if (!r) return;
        dSel.disabled = true;
        dSel.innerHTML = '<option value="0">Loading drivers&hellip;</option>';
        setHint('processDriverHint', '');

        var fd = new FormData();
        fd.append('trip_date',  r.trip_date);
        fd.append('start_time', r.start_time);
        fd.append('end_time',   r.end_time);
        fd.append('vehicle_id', document.getElementById('processVehicle').value || '0');
        fd.append('trip_type',  document.getElementById('processTripType').value);

        fetch(SITE_URL + '/ajax/get_available_drivers.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                var list = (data && data.success && data.drivers) ? data.drivers : [];
                var html = '<option value="0">&mdash; Leave unassigned (use Auto Assign later) &mdash;</option>';
                list.forEach(function (d, i) {
                    html += '<option value="' + d.driver_id + '"' + (i === 0 ? ' selected' : '') + '>'
                         + escHtml((i === 0 ? '\u2605 Recommended: ' : '') + d.name + ' \u2014 score ' + Number(d.priority).toFixed(2)
                         + ' (' + d.month_tasks + ' task' + (d.month_tasks === 1 ? '' : 's') + ' this month, ' + d.month_weekend + ' weekend)')
                         + '</option>';
                });
                dSel.innerHTML = html;
                dSel.disabled = false;
                if (list.length) {
                    setHint('processDriverHint', list.length + ' driver(s) are free and hold the right licence. The top score is recommended; change it if you prefer someone else.');
                } else {
                    setHint('processDriverHint', 'No driver is free with the right licence for this time and trip type. Leave unassigned or change the vehicle or trip type.', 'danger');
                }
                updateProcessNote();
            })
            .catch(function () {
                dSel.innerHTML = '<option value="0">&mdash; Leave unassigned (use Auto Assign later) &mdash;</option>';
                dSel.disabled = false;
                setHint('processDriverHint', 'Could not load drivers. You can leave it unassigned.', 'danger');
                updateProcessNote();
            });
    }

    document.getElementById('processVehicle').addEventListener('change', loadProcessDrivers);
    document.getElementById('processTripType').addEventListener('change', loadProcessDrivers);
    document.getElementById('processDriver').addEventListener('change', updateProcessNote);

    window.submitProcess = function () {
        if (!_processRequestId) return;

        var btn = document.getElementById('processConfirmBtn');
        btn.disabled  = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Processing&hellip;';

        var formData = new FormData();
        formData.append('request_id', _processRequestId);
        formData.append('vehicle_id', document.getElementById('processVehicle').value || '0');
        formData.append('driver_id',  document.getElementById('processDriver').value  || '0');
        formData.append('trip_type',  document.getElementById('processTripType').value);

        fetch(SITE_URL + '/ajax/process_request.php', {
            method:      'POST',
            body:        formData,
            credentials: 'same-origin'
        })
        .then(function (response) { return response.json(); })
        .then(function (res) {
            var modalEl  = document.getElementById('processModal');
            var instance = bootstrap.Modal.getInstance(modalEl);
            if (instance) instance.hide();

            if (res.success) {
                var num = String(res.schedule_id).padStart(4, '0');
                var assigned = res.driver_assigned === true;
                Swal.fire({
                    icon:               'success',
                    title:              'Schedule #' + num + (assigned ? ' created and assigned' : ' created'),
                    text:               assigned
                                          ? (res.driver_name + ' has been assigned.' + (res.email ? ' ' + res.email : ''))
                                          : 'The request has been processed. Assign a driver next.',
                    showCancelButton:   true,
                    confirmButtonText:  assigned
                                          ? '<i class="fas fa-eye me-1"></i> View schedule'
                                          : '<i class="fas fa-wand-magic-sparkles me-1"></i> Go to Auto Assign',
                    cancelButtonText:   'Stay here',
                    confirmButtonColor: '#0b5d3b',
                }).then(function (result) {
                    if (result.isConfirmed) {
                        window.location.href = SITE_URL + (assigned
                            ? '/admin/view_schedule.php?id=' + res.schedule_id
                            : '/admin/auto_assign.php');
                    } else {
                        location.reload();
                    }
                });
            } else {
                Swal.fire({
                    icon:  'error',
                    title: 'Failed',
                    text:  res.message || 'Something went wrong. Please try again.'
                });
                btn.disabled  = false;
                btn.innerHTML = '<i class="fas fa-calendar-plus me-1" aria-hidden="true"></i> Create Schedule';
            }
        })
        .catch(function () {
            var modalEl  = document.getElementById('processModal');
            var instance = bootstrap.Modal.getInstance(modalEl);
            if (instance) instance.hide();
            Swal.fire({ icon: 'error', title: 'Network Error', text: 'Could not reach the server. Please try again.' });
            btn.disabled  = false;
            btn.innerHTML = '<i class="fas fa-calendar-plus me-1" aria-hidden="true"></i> Create Schedule';
        });
    };

})();
</script>

</body>
</html>
