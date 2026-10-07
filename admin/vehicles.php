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

        /* ── Maintenance date highlights ── */
        .maint-overdue  { color: #dc3545; font-weight: 600; }
        .maint-soon     { color: #fd7e14; font-weight: 600; }


        /* ── Vehicle cards ── */
        .vtoolbar { display:flex; flex-wrap:wrap; gap:.75rem; align-items:center; justify-content:space-between; margin-bottom:1rem; }
        .vtoolbar-left { display:flex; flex-wrap:wrap; gap:.6rem; align-items:center; }
        .vsearch { position:relative; }
        .vsearch i { position:absolute; left:.75rem; top:50%; transform:translateY(-50%); color:#9ca3af; font-size:.8rem; }
        .vsearch input { padding-left:2.1rem; min-width:230px; border-radius:10px; }
        .vchip {
            border:1px solid #d9e2dd; background:#fff; color:#374151; border-radius:999px;
            padding:.32rem .85rem; font-size:.8rem; font-weight:600; cursor:pointer; transition:all .15s;
        }
        .vchip:hover { border-color:#0b5d3b; color:#0b5d3b; }
        .vchip.active { background:#0b5d3b; border-color:#0b5d3b; color:#fff; }
        .vchip .n { opacity:.7; font-weight:500; margin-left:.25rem; }
        .view-toggle .btn { font-size:.82rem; padding:.35rem .8rem; }
        .view-toggle .btn.active { background:#0b5d3b; border-color:#0b5d3b; color:#fff; }

        .vcard {
            --vc: #15803d;
            position:relative; height:100%;
            background:#fff; border:1px solid #e3e9e5; border-radius:14px; overflow:hidden;
            display:flex; flex-direction:column;
            box-shadow:0 1px 3px rgba(18,32,24,.05);
            transition:box-shadow .2s, transform .2s, border-color .2s;
        }
        .vcard::before { content:''; position:absolute; inset:0 0 auto 0; height:4px; background:var(--vc); }
        .vcard:hover { transform:translateY(-2px); box-shadow:0 8px 22px rgba(18,32,24,.10); border-color:#cfdad3; }
        .vcard.st-available   { --vc:#15803d; }
        .vcard.st-in_use      { --vc:#0e7490; }
        .vcard.st-maintenance { --vc:#b45309; }
        .vcard.st-retired     { --vc:#6b7280; }
        .vcard.st-retired .vcard-body { opacity:.72; filter:grayscale(.6); }

        .vcard-body { padding:1.15rem 1.15rem .9rem; flex:1; }
        .vcard-top  { display:flex; align-items:flex-start; justify-content:space-between; gap:.5rem; margin-bottom:.9rem; }
        .vtype {
            width:46px; height:46px; border-radius:12px; flex-shrink:0;
            background:#eef5f1; color:#0b5d3b; font-size:1.2rem;
            display:flex; align-items:center; justify-content:center;
        }
        .vstatus {
            display:inline-flex; align-items:center; gap:.4rem; font-size:.72rem; font-weight:700;
            letter-spacing:.03em; text-transform:uppercase; color:var(--vc);
            background:color-mix(in srgb, var(--vc) 10%, #fff); border-radius:999px; padding:.28rem .65rem;
        }
        .vstatus::before { content:''; width:7px; height:7px; border-radius:50%; background:var(--vc); }

        .vplate {
            display:inline-block; font-family:'Courier New', ui-monospace, monospace;
            font-weight:800; font-size:1.12rem; letter-spacing:.14em; color:#111827;
            background:#fff; border:2px solid #1f2937; border-radius:6px; padding:.1rem .65rem;
            box-shadow:inset 0 0 0 1px #e5e7eb; margin-bottom:.45rem;
        }
        .vname  { font-weight:700; color:#1a2035; font-size:1rem; line-height:1.25; }
        .vmeta  { font-size:.78rem; color:#6b7280; }

        .vspecs { display:grid; grid-template-columns:repeat(3,1fr); margin:1rem 0 .85rem;
                  border-top:1px solid #edf1ee; border-bottom:1px solid #edf1ee; }
        .vspec  { padding:.65rem .25rem; text-align:center; }
        .vspec + .vspec { border-left:1px solid #edf1ee; }
        .vspec .v { font-weight:700; color:#1a2035; font-size:.92rem; }
        .vspec .v i { color:#0b5d3b; margin-right:.3rem; font-size:.78rem; }
        .vspec .l { font-size:.66rem; text-transform:uppercase; letter-spacing:.07em; color:#9ca3af; margin-top:.1rem; }

        .vservice { display:flex; align-items:center; justify-content:space-between; gap:.5rem; font-size:.8rem; color:#4b5563; }
        .vservice .lbl { display:flex; align-items:center; gap:.55rem; min-width:0; }
        .vservice .lbl i { color:#9ca3af; font-size:.95rem; }
        .vservice .lbl small { display:block; font-size:.66rem; text-transform:uppercase; letter-spacing:.07em; color:#9ca3af; line-height:1.2; }
        .vservice .lbl strong { display:block; font-size:.82rem; color:#1a2035; font-weight:600; white-space:nowrap; line-height:1.3; }
        .vsvc-chip { font-size:.7rem; font-weight:700; border-radius:999px; padding:.18rem .6rem; white-space:nowrap; }
        .vsvc-ok      { background:#dcfce7; color:#166534; }
        .vsvc-soon    { background:#fef3c7; color:#92400e; }
        .vsvc-overdue { background:#fee2e2; color:#991b1b; }
        .vsvc-none    { background:#f3f4f6; color:#6b7280; }

        .vcard-foot {
            display:flex; align-items:center; justify-content:space-between;
            padding:.65rem 1.15rem; background:#f8faf9; border-top:1px solid #edf1ee;
        }
        .vtrips { font-size:.78rem; color:#6b7280; }
        .vtrips b { color:#1a2035; }
        .vactions .btn { padding:.25rem .55rem; font-size:.78rem; border-radius:8px; }
        .vempty { text-align:center; padding:3rem 1rem; color:#9ca3af; display:none; }
        .vempty i { font-size:2rem; margin-bottom:.6rem; display:block; }

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


    <!-- ── Vehicle Cards View ─────────────────────────────────── -->
    <?php
        $type_counts = [];
        foreach ($vehicles as $v) { $type_counts[$v['vehicle_type']] = ($type_counts[$v['vehicle_type']] ?? 0) + 1; }
        ksort($type_counts);
        $soon_limit = date('Y-m-d', strtotime('+30 days'));
    ?>
    <div id="cardsView">
        <div class="vtoolbar">
            <div class="vtoolbar-left">
                <div class="vsearch">
                    <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
                    <input type="search" id="vSearch" class="form-control form-control-sm"
                           placeholder="Search vehicles" aria-label="Search vehicles">
                </div>
                <button type="button" class="vchip active" data-status="all">All<span class="n"><?php echo $total; ?></span></button>
                <button type="button" class="vchip" data-status="available">Available<span class="n"><?php echo $available; ?></span></button>
                <button type="button" class="vchip" data-status="in_use">In Use<span class="n"><?php echo $in_use; ?></span></button>
                <button type="button" class="vchip" data-status="maintenance">Maintenance<span class="n"><?php echo $maintenance; ?></span></button>
                <button type="button" class="vchip" data-status="retired">Retired<span class="n"><?php echo $retired; ?></span></button>
                <select id="vType" class="form-select form-select-sm" style="width:auto;border-radius:10px;" aria-label="Filter by type">
                    <option value="all">All types</option>
                    <?php foreach ($type_counts as $t => $c): ?>
                    <option value="<?php echo htmlspecialchars($t); ?>"><?php echo htmlspecialchars($t); ?> (<?php echo $c; ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="btn-group view-toggle" role="group" aria-label="Switch view">
                <button type="button" class="btn btn-outline-secondary active" data-view="cards"><i class="fas fa-table-cells-large me-1"></i>Cards</button>
                <button type="button" class="btn btn-outline-secondary" data-view="table"><i class="fas fa-list me-1"></i>Table</button>
            </div>
        </div>

        <div class="row g-3" id="vehicleCards">
        <?php foreach ($vehicles as $v): ?>
            <?php
                $vStatusLabel = match($v['status']) {
                    'available' => 'Available', 'in_use' => 'In Use',
                    'maintenance' => 'Maintenance', 'retired' => 'Retired',
                    default => ucfirst($v['status']),
                };
                $vIcon = match($v['vehicle_type']) {
                    'Bus' => 'fa-bus', 'Minibus' => 'fa-bus-simple', 'Van' => 'fa-van-shuttle',
                    'Lorry' => 'fa-truck', 'Motorcycle' => 'fa-motorcycle', default => 'fa-car',
                };
                $vFuelIcon = match($v['fuel_type']) {
                    'Electric' => 'fa-bolt', 'Hybrid' => 'fa-leaf', default => 'fa-gas-pump',
                };

                $hasNext = !empty($v['next_maintenance']) && $v['next_maintenance'] !== '0000-00-00';
                if ($hasNext) {
                    $days = (int)floor((strtotime($v['next_maintenance']) - strtotime($today)) / 86400);
                    if ($days < 0)        { $svcClass = 'vsvc-overdue'; $svcText = 'Overdue ' . abs($days) . ' day' . (abs($days) === 1 ? '' : 's'); }
                    elseif ($days === 0)  { $svcClass = 'vsvc-soon';    $svcText = 'Due today'; }
                    elseif ($v['next_maintenance'] <= $soon_limit) { $svcClass = 'vsvc-soon'; $svcText = 'In ' . $days . ' day' . ($days === 1 ? '' : 's'); }
                    else                  { $svcClass = 'vsvc-ok';      $svcText = 'On schedule'; }
                } else {
                    $svcClass = 'vsvc-none'; $svcText = 'Not set';
                }
                $vSearch = strtolower($v['plate_number'] . ' ' . $v['brand'] . ' ' . $v['model']);
                $plateJs = htmlspecialchars(addslashes($v['plate_number']));
            ?>
            <div class="col-12 col-md-6 col-xl-4 col-xxl-3 vcol"
                 data-status="<?php echo htmlspecialchars($v['status']); ?>"
                 data-type="<?php echo htmlspecialchars($v['vehicle_type']); ?>"
                 data-search="<?php echo htmlspecialchars($vSearch); ?>">
                <article class="vcard st-<?php echo htmlspecialchars($v['status']); ?>">
                    <div class="vcard-body">
                        <div class="vcard-top">
                            <span class="vtype" title="<?php echo htmlspecialchars($v['vehicle_type']); ?>">
                                <i class="fas <?php echo $vIcon; ?>" aria-hidden="true"></i>
                            </span>
                            <span class="vstatus"><?php echo $vStatusLabel; ?></span>
                        </div>

                        <div class="vplate"><?php echo htmlspecialchars($v['plate_number']); ?></div>
                        <div class="vname"><?php echo htmlspecialchars(trim($v['brand'] . ' ' . $v['model'])); ?></div>
                        <div class="vmeta">
                            <?php echo htmlspecialchars($v['vehicle_type']); ?>
                            <?php if (!empty($v['year'])): ?> &middot; <?php echo (int)$v['year']; ?><?php endif; ?>
                        </div>

                        <div class="vspecs">
                            <div class="vspec">
                                <div class="v"><i class="fas fa-user-group" aria-hidden="true"></i><?php echo (int)$v['capacity']; ?></div>
                                <div class="l">Seats</div>
                            </div>
                            <div class="vspec">
                                <div class="v"><i class="fas <?php echo $vFuelIcon; ?>" aria-hidden="true"></i><?php echo htmlspecialchars($v['fuel_type']); ?></div>
                                <div class="l">Fuel</div>
                            </div>
                            <div class="vspec">
                                <div class="v"><?php echo number_format((int)$v['mileage']); ?></div>
                                <div class="l">Km</div>
                            </div>
                        </div>

                        <div class="vservice">
                            <span class="lbl">
                                <i class="fas fa-screwdriver-wrench" aria-hidden="true"></i>
                                <span>
                                    <small>Next service</small>
                                    <strong><?php echo $hasNext
                                        ? htmlspecialchars(date('d M Y', strtotime($v['next_maintenance'])))
                                        : '&mdash;'; ?></strong>
                                </span>
                            </span>
                            <span class="vsvc-chip <?php echo $svcClass; ?>"><?php echo $svcText; ?></span>
                        </div>
                    </div>

                    <div class="vcard-foot">
                        <span class="vtrips"><b><?php echo (int)$v['usage_count']; ?></b> trip<?php echo (int)$v['usage_count'] === 1 ? '' : 's'; ?></span>
                        <span class="vactions d-flex gap-1">
                            <a href="<?php echo SITE_URL; ?>/admin/edit_vehicle.php?id=<?php echo (int)$v['vehicle_id']; ?>"
                               class="btn btn-outline-primary" title="Edit vehicle"
                               aria-label="Edit <?php echo htmlspecialchars($v['plate_number']); ?>"><i class="fas fa-pen-to-square" aria-hidden="true"></i></a>
                            <button type="button" class="btn btn-outline-secondary" title="Change status"
                                    onclick="openStatusModal(<?php echo (int)$v['vehicle_id']; ?>, '<?php echo $plateJs; ?>', '<?php echo htmlspecialchars($v['status']); ?>')"
                                    aria-label="Change status of <?php echo htmlspecialchars($v['plate_number']); ?>"><i class="fas fa-arrow-right-arrow-left" aria-hidden="true"></i></button>
                            <button type="button" class="btn btn-outline-danger" title="Delete vehicle"
                                    onclick="confirmDelete(<?php echo (int)$v['vehicle_id']; ?>, '<?php echo $plateJs; ?>')"
                                    aria-label="Delete <?php echo htmlspecialchars($v['plate_number']); ?>"><i class="fas fa-trash" aria-hidden="true"></i></button>
                        </span>
                    </div>
                </article>
            </div>
        <?php endforeach; ?>
        </div>

        <div class="vempty" id="vEmpty"><i class="fas fa-car-burst" aria-hidden="true"></i>No vehicles match your filters.</div>
    </div><!-- /#cardsView -->

    <!-- ── DataTable Card (table view) ────────────────────────── -->
    <div id="tableView" style="display:none;">
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
                                    'Lorry'  => ['fa-truck',         'rgba(253,126,20,.1)',  '#fd7e14'],
                                    'Motorcycle' => ['fa-motorcycle','rgba(32,201,151,.1)',  '#20c997'],
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
    </div><!-- /#tableView -->

</main><!-- /.main-content -->

<!-- ================================================================
     STATUS CHANGE MODAL
     ================================================================ -->
<div class="modal fade" id="statusModal" tabindex="-1" aria-labelledby="statusModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-3">
            <div class="modal-header text-white"
                 style="background: linear-gradient(135deg,#0b5d3b 0%,#15804f 100%);">
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

    // ── Cards: filter + view toggle ───────────────────────────
    (function () {
        var cols      = document.querySelectorAll('#vehicleCards .vcol');
        var empty     = document.getElementById('vEmpty');
        var searchEl  = document.getElementById('vSearch');
        var typeEl    = document.getElementById('vType');
        var chips     = document.querySelectorAll('.vchip');
        var state     = { status: 'all', type: 'all', q: '' };

        function apply() {
            var shown = 0;
            cols.forEach(function (c) {
                var ok = (state.status === 'all' || c.dataset.status === state.status)
                      && (state.type   === 'all' || c.dataset.type   === state.type)
                      && (!state.q || c.dataset.search.indexOf(state.q) !== -1);
                c.style.display = ok ? '' : 'none';
                if (ok) shown++;
            });
            empty.style.display = shown ? 'none' : 'block';
        }

        chips.forEach(function (chip) {
            chip.addEventListener('click', function () {
                chips.forEach(function (c) { c.classList.remove('active'); });
                chip.classList.add('active');
                state.status = chip.dataset.status;
                apply();
            });
        });
        searchEl.addEventListener('input', function () { state.q = searchEl.value.trim().toLowerCase(); apply(); });
        typeEl.addEventListener('change', function () { state.type = typeEl.value; apply(); });

        var cardsView = document.getElementById('cardsView');
        var tableView = document.getElementById('tableView');
        var toggles   = document.querySelectorAll('.view-toggle [data-view]');

        function setView(v) {
            cardsView.style.display = v === 'cards' ? '' : 'none';
            tableView.style.display = v === 'table' ? '' : 'none';
            toggles.forEach(function (b) { b.classList.toggle('active', b.dataset.view === v); });
            try { localStorage.setItem('vehiclesView', v); } catch (e) {}
            if (v === 'table') { $('#vehiclesTable').DataTable().columns.adjust(); }
        }
        toggles.forEach(function (b) { b.addEventListener('click', function () { setView(b.dataset.view); }); });

        var saved = 'cards';
        try { saved = localStorage.getItem('vehiclesView') || 'cards'; } catch (e) {}
        setView(saved === 'table' ? 'table' : 'cards');
    })();

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
