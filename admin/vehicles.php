<?php
// ============================================================
// UIS Driver Scheduling and Management System
// admin/vehicles.php  –  Manage Vehicles (list view)
// Universiti Islam Selangor (UIS)
// ============================================================

require_once '../config/database.php';
requireAdmin();

$page_title   = 'Manage Vehicles';
$current_page = 'vehicles.php';

// ── Fetch all vehicles with schedule usage count ─────────────
$sql = "SELECT v.*,
               COUNT(s.schedule_id) AS usage_count
        FROM vehicles v
        LEFT JOIN schedules s ON s.vehicle_id = v.vehicle_id
        GROUP BY v.vehicle_id
        ORDER BY v.plate_number ASC";

$result   = $conn->query($sql);
$vehicles = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

// ── Summary counts ───────────────────────────────────────────
$total       = count($vehicles);
$available   = 0;
$in_use      = 0;
$maintenance = 0;
$retired     = 0;

$today = date('Y-m-d');

foreach ($vehicles as $v) {
    switch ($v['status']) {
        case 'available':   $available++;   break;
        case 'in_use':      $in_use++;      break;
        case 'maintenance': $maintenance++; break;
        case 'retired':     $retired++;     break;
    }
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

        /* ── Maintenance date highlights ── */
        .maint-overdue  { color: #dc3545; font-weight: 600; }
        .maint-soon     { color: #fd7e14; font-weight: 600; }

        /* ── Vehicle type icon ── */
        .vehicle-type-icon {
            width: 32px; height: 32px;
            border-radius: 8px;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: .85rem;
            margin-right: .4rem;
            flex-shrink: 0;
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
            <h1><i class="fas fa-car me-2" aria-hidden="true"></i>Manage Vehicles</h1>
            <p>View, add, edit and manage all UIS fleet vehicles.</p>
        </div>
        <a href="<?php echo SITE_URL; ?>/admin/add_vehicle.php" class="btn btn-warning fw-semibold">
            <i class="fas fa-circle-plus me-1" aria-hidden="true"></i> Add New Vehicle
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
                        <i class="fas fa-car" aria-hidden="true"></i>
                    </div>
                    <div>
                        <div class="stat-value text-primary"><?php echo $total; ?></div>
                        <div class="stat-label">Total Vehicles</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Available -->
        <div class="col-6 col-md-3">
            <div class="card stat-card h-100 p-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon bg-success bg-opacity-10 text-success">
                        <i class="fas fa-circle-check" aria-hidden="true"></i>
                    </div>
                    <div>
                        <div class="stat-value text-success"><?php echo $available; ?></div>
                        <div class="stat-label">Available</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- In Use -->
        <div class="col-6 col-md-3">
            <div class="card stat-card h-100 p-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                        <i class="fas fa-road" aria-hidden="true"></i>
                    </div>
                    <div>
                        <div class="stat-value text-primary"><?php echo $in_use; ?></div>
                        <div class="stat-label">In Use</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Under Maintenance -->
        <div class="col-6 col-md-3">
            <div class="card stat-card h-100 p-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon bg-warning bg-opacity-10 text-warning">
                        <i class="fas fa-wrench" aria-hidden="true"></i>
                    </div>
                    <div>
                        <div class="stat-value text-warning"><?php echo $maintenance; ?></div>
                        <div class="stat-label">Under Maintenance</div>
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
                    <i class="fas fa-table me-1" aria-hidden="true"></i> All Vehicles
                </h6>
                <span class="badge bg-primary bg-opacity-10 text-primary fw-normal px-3 py-2">
                    <?php echo $total; ?> record<?php echo $total !== 1 ? 's' : ''; ?>
                </span>
            </div>

            <div class="p-3">
                <div class="table-responsive">
                    <table id="vehiclesTable" class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th style="width:40px;">#</th>
                                <th>Plate Number</th>
                                <th>Type</th>
                                <th>Brand / Model / Year</th>
                                <th>Capacity</th>
                                <th>Fuel</th>
                                <th>Status</th>
                                <th>Last Maint.</th>
                                <th>Next Maint.</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($vehicles as $i => $v): ?>
                            <?php
                                // Status badge
                                $statusClass = vehicleStatusBadgeClass($v['status']);
                                $statusLabel = match($v['status']) {
                                    'available'   => 'Available',
                                    'in_use'      => 'In Use',
                                    'maintenance' => 'Maintenance',
                                    'retired'     => 'Retired',
                                    default       => ucfirst($v['status']),
                                };

                                // Vehicle type icon + colour
                                [$typeIcon, $typeBg, $typeColor] = match($v['vehicle_type']) {
                                    'Bus'    => ['fa-bus',           'rgba(13,110,253,.1)',  '#0d6efd'],
                                    'Van'    => ['fa-shuttle-van',   'rgba(25,135,84,.1)',   '#198754'],
                                    'Minibus'=> ['fa-bus-simple',    'rgba(111,66,193,.1)',  '#6f42c1'],
                                    default  => ['fa-car',           'rgba(220,53,69,.1)',   '#dc3545'],
                                };

                                // Next maintenance date highlight
                                $nextMaintClass = '';
                                if (!empty($v['next_maintenance']) && $v['next_maintenance'] !== '0000-00-00') {
                                    if ($v['next_maintenance'] < $today) {
                                        $nextMaintClass = 'maint-overdue';
                                    } elseif ($v['next_maintenance'] <= date('Y-m-d', strtotime('+30 days'))) {
                                        $nextMaintClass = 'maint-soon';
                                    }
                                }
                            ?>
                            <tr>
                                <td class="text-muted small"><?php echo $i + 1; ?></td>

                                <td>
                                    <span class="fw-semibold font-monospace text-dark">
                                        <?php echo htmlspecialchars($v['plate_number']); ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="d-flex align-items-center">
                                        <span class="vehicle-type-icon"
                                              style="background:<?php echo $typeBg; ?>;color:<?php echo $typeColor; ?>;">
                                            <i class="fas <?php echo $typeIcon; ?>" aria-hidden="true"></i>
                                        </span>
                                        <span class="small"><?php echo htmlspecialchars($v['vehicle_type']); ?></span>
                                    </div>
                                </td>

                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($v['brand']); ?></div>
                                    <div class="small text-muted">
                                        <?php echo htmlspecialchars($v['model']); ?>
                                        &middot;
                                        <?php echo htmlspecialchars((string)$v['year']); ?>
                                    </div>
                                </td>

                                <td class="small">
                                    <i class="fas fa-person text-muted me-1" aria-hidden="true"></i>
                                    <?php echo (int)$v['capacity']; ?>
                                </td>

                                <td class="small"><?php echo htmlspecialchars($v['fuel_type']); ?></td>

                                <td>
                                    <span class="badge <?php echo $statusClass; ?> rounded-pill">
                                        <?php echo $statusLabel; ?>
                                    </span>
                                </td>

                                <td class="small">
                                    <?php echo !empty($v['last_maintenance']) && $v['last_maintenance'] !== '0000-00-00'
                                        ? htmlspecialchars(date('d M Y', strtotime($v['last_maintenance'])))
                                        : '<span class="text-muted">&mdash;</span>'; ?>
                                </td>

                                <td class="small">
                                    <?php if (!empty($v['next_maintenance']) && $v['next_maintenance'] !== '0000-00-00'): ?>
                                        <span class="<?php echo $nextMaintClass; ?>">
                                            <?php if ($nextMaintClass === 'maint-overdue'): ?>
                                                <i class="fas fa-triangle-exclamation me-1" aria-hidden="true"></i>
                                            <?php elseif ($nextMaintClass === 'maint-soon'): ?>
                                                <i class="fas fa-clock me-1" aria-hidden="true"></i>
                                            <?php endif; ?>
                                            <?php echo htmlspecialchars(date('d M Y', strtotime($v['next_maintenance']))); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">&mdash;</span>
                                    <?php endif; ?>
                                </td>

                                <td class="text-center">
                                    <div class="d-flex justify-content-center gap-1 flex-nowrap">
                                        <!-- Edit -->
                                        <a href="<?php echo SITE_URL; ?>/admin/edit_vehicle.php?id=<?php echo (int)$v['vehicle_id']; ?>"
                                           class="btn btn-outline-primary btn-action"
                                           title="Edit Vehicle"
                                           aria-label="Edit <?php echo htmlspecialchars($v['plate_number']); ?>">
                                            <i class="fas fa-pen-to-square" aria-hidden="true"></i>
                                        </a>

                                        <!-- Toggle Status -->
                                        <button type="button"
                                                class="btn btn-outline-secondary btn-action"
                                                title="Change Status"
                                                onclick="openStatusModal(<?php echo (int)$v['vehicle_id']; ?>, '<?php echo htmlspecialchars(addslashes($v['plate_number'])); ?>', '<?php echo htmlspecialchars($v['status']); ?>')"
                                                aria-label="Change status of <?php echo htmlspecialchars($v['plate_number']); ?>">
                                            <i class="fas fa-arrow-right-arrow-left" aria-hidden="true"></i>
                                        </button>

                                        <!-- Delete -->
                                        <button type="button"
                                                class="btn btn-outline-danger btn-action"
                                                title="Delete Vehicle"
                                                onclick="confirmDelete(<?php echo (int)$v['vehicle_id']; ?>, '<?php echo htmlspecialchars(addslashes($v['plate_number'])); ?>')"
                                                aria-label="Delete <?php echo htmlspecialchars($v['plate_number']); ?>">
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
     STATUS CHANGE MODAL
     ================================================================ -->
<div class="modal fade" id="statusModal" tabindex="-1" aria-labelledby="statusModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-3">
            <div class="modal-header text-white"
                 style="background: linear-gradient(135deg,#003580 0%,#0056b3 100%);">
                <h5 class="modal-title fw-bold" id="statusModalLabel">
                    <i class="fas fa-arrow-right-arrow-left me-2" aria-hidden="true"></i>
                    Change Vehicle Status
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <p class="mb-1 text-muted small">Vehicle</p>
                <p class="fw-bold font-monospace fs-5 mb-3" id="modalVehiclePlate"></p>
                <label for="modalStatusSelect" class="form-label fw-semibold">Select New Status</label>
                <select class="form-select" id="modalStatusSelect">
                    <option value="available">Available</option>
                    <option value="in_use">In Use</option>
                    <option value="maintenance">Maintenance</option>
                    <option value="retired">Retired</option>
                </select>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="btnConfirmStatus">
                    <i class="fas fa-check me-1" aria-hidden="true"></i> Update Status
                </button>
            </div>
        </div>
    </div>
</div>

<script>
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

    // ── DataTable initialisation ──────────────────────────────
    $('#vehiclesTable').DataTable({
        order:       [[1, 'asc']],
        pageLength:  25,
        responsive:  true,
        columnDefs: [
            { orderable: false, targets: 9 },
            { searchable: false, targets: 9 }
        ],
        language: {
            search:      'Search vehicles:',
            lengthMenu:  'Show _MENU_ vehicles per page',
            info:        'Showing _START_ to _END_ of _TOTAL_ vehicles',
            infoEmpty:   'No vehicles found',
            emptyTable:  'No vehicles registered yet',
            zeroRecords: 'No vehicles match the search'
        }
    });

    // ── Status Modal ──────────────────────────────────────────
    var _statusVehicleId = null;

    window.openStatusModal = function (vehicleId, plate, currentStatus) {
        _statusVehicleId = vehicleId;
        document.getElementById('modalVehiclePlate').textContent = plate;
        document.getElementById('modalStatusSelect').value = currentStatus;
        new bootstrap.Modal(document.getElementById('statusModal')).show();
    };

    document.getElementById('btnConfirmStatus').addEventListener('click', function () {
        var newStatus = document.getElementById('modalStatusSelect').value;
        var btn       = this;
        btn.disabled  = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Updating...';

        $.ajax({
            url:      SITE_URL + '/ajax/update_vehicle_status.php',
            method:   'POST',
            data:     { id: _statusVehicleId, status: newStatus },
            dataType: 'json'
        })
        .done(function (res) {
            bootstrap.Modal.getInstance(document.getElementById('statusModal')).hide();
            if (res.success) {
                Swal.fire({
                    icon:              'success',
                    title:             'Status Updated',
                    text:              res.message,
                    timer:             1600,
                    showConfirmButton: false
                }).then(function () { location.reload(); });
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: res.message });
            }
        })
        .fail(function () {
            bootstrap.Modal.getInstance(document.getElementById('statusModal')).hide();
            Swal.fire({ icon: 'error', title: 'Network Error', text: 'Unable to reach the server. Please try again.' });
        })
        .always(function () {
            btn.disabled  = false;
            btn.innerHTML = '<i class="fas fa-check me-1"></i> Update Status';
        });
    });

    // ── Delete with SweetAlert2 ───────────────────────────────
    window.confirmDelete = function (vehicleId, plate) {
        Swal.fire({
            title:              'Delete Vehicle?',
            html:               'Are you sure you want to delete vehicle <strong>' + escHtml(plate) + '</strong>?<br><small class="text-muted">This action cannot be undone.</small>',
            icon:               'warning',
            showCancelButton:   true,
            confirmButtonColor: '#dc3545',
            cancelButtonColor:  '#6c757d',
            confirmButtonText:  '<i class="fas fa-trash me-1"></i> Yes, Delete',
            cancelButtonText:   'Cancel',
            focusCancel:        true
        }).then(function (result) {
            if (!result.isConfirmed) return;

            Swal.fire({
                title:             'Deleting...',
                text:              'Please wait.',
                allowOutsideClick: false,
                didOpen: function () { Swal.showLoading(); }
            });

            $.ajax({
                url:      SITE_URL + '/ajax/delete_vehicle.php',
                method:   'POST',
                data:     { id: vehicleId },
                dataType: 'json'
            })
            .done(function (res) {
                if (res.success) {
                    Swal.fire({
                        icon:              'success',
                        title:             'Deleted!',
                        text:              res.message,
                        timer:             1800,
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

    // ── HTML escape helper ────────────────────────────────────
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
