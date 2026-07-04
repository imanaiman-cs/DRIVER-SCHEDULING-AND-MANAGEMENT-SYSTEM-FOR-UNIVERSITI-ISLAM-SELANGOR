<?php
// ============================================================
// UIS Driver Scheduling and Management System
// admin/edit_vehicle.php  –  Edit Existing Vehicle
// Universiti Islam Selangor (UIS)
// ============================================================

require_once '../config/database.php';
requireAdmin();

$page_title   = 'Edit Vehicle';
$current_page = 'edit_vehicle.php';

// ── Load vehicle ─────────────────────────────────────────────
$vehicle_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($vehicle_id <= 0) {
    setFlash('danger', 'Invalid vehicle ID.');
    header('Location: ' . SITE_URL . '/admin/vehicles.php');
    exit();
}

$sel = $conn->prepare("SELECT * FROM vehicles WHERE vehicle_id = ?");
$sel->bind_param('i', $vehicle_id);
$sel->execute();
$vehicle = $sel->get_result()->fetch_assoc();
$sel->close();

if (!$vehicle) {
    setFlash('danger', 'Vehicle not found.');
    header('Location: ' . SITE_URL . '/admin/vehicles.php');
    exit();
}

// ── Last trip info ────────────────────────────────────────────
$last_trip     = null;
$last_trip_sql = $conn->prepare(
    "SELECT s.trip_date, s.start_time, s.end_time, s.destination,
            d.name AS driver_name
     FROM schedules s
     LEFT JOIN drivers d ON d.driver_id = s.driver_id
     WHERE s.vehicle_id = ?
       AND s.status IN ('completed', 'in_progress')
     ORDER BY s.trip_date DESC, s.start_time DESC
     LIMIT 1"
);
$last_trip_sql->bind_param('i', $vehicle_id);
$last_trip_sql->execute();
$last_trip = $last_trip_sql->get_result()->fetch_assoc();
$last_trip_sql->close();

// ── Form field defaults from DB ───────────────────────────────
$errors = [];
$form   = [
    'plate_number'     => $vehicle['plate_number'],
    'vehicle_type'     => $vehicle['vehicle_type'],
    'brand'            => $vehicle['brand'],
    'model'            => $vehicle['model'],
    'year'             => $vehicle['year'],
    'capacity'         => $vehicle['capacity'],
    'fuel_type'        => $vehicle['fuel_type'],
    'status'           => $vehicle['status'],
    'last_maintenance' => $vehicle['last_maintenance'] ?? '',
    'next_maintenance' => $vehicle['next_maintenance'] ?? '',
    'mileage'          => $vehicle['mileage'] ?? '',
    'notes'            => $vehicle['notes'] ?? '',
];

// ── POST handler ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Collect & sanitise
    $form['plate_number']     = strtoupper(trim($_POST['plate_number']     ?? ''));
    $form['vehicle_type']     = trim($_POST['vehicle_type']     ?? '');
    $form['brand']            = trim($_POST['brand']            ?? '');
    $form['model']            = trim($_POST['model']            ?? '');
    $form['year']             = trim($_POST['year']             ?? '');
    $form['capacity']         = trim($_POST['capacity']         ?? '');
    $form['fuel_type']        = trim($_POST['fuel_type']        ?? '');
    $form['status']           = trim($_POST['status']           ?? 'available');
    $form['last_maintenance'] = trim($_POST['last_maintenance'] ?? '');
    $form['next_maintenance'] = trim($_POST['next_maintenance'] ?? '');
    $form['mileage']          = trim($_POST['mileage']          ?? '');
    $form['notes']            = trim($_POST['notes']            ?? '');

    // ── Server-side validation ───────────────────────────────

    // Plate number
    if ($form['plate_number'] === '') {
        $errors['plate_number'] = 'Plate number is required.';
    } elseif (!preg_match('/^[A-Z0-9\s\-]+$/', $form['plate_number'])) {
        $errors['plate_number'] = 'Plate number may only contain letters, digits, spaces and hyphens.';
    } else {
        // Uniqueness: exclude the current vehicle
        $chk = $conn->prepare("SELECT vehicle_id FROM vehicles WHERE plate_number = ? AND vehicle_id != ?");
        $chk->bind_param('si', $form['plate_number'], $vehicle_id);
        $chk->execute();
        $chk->store_result();
        if ($chk->num_rows > 0) {
            $errors['plate_number'] = 'Another vehicle with this plate number already exists.';
        }
        $chk->close();
    }

    // Vehicle type
    $allowed_types = ['Bus', 'Van', 'Car', 'Minibus'];
    if (!in_array($form['vehicle_type'], $allowed_types, true)) {
        $errors['vehicle_type'] = 'Please select a valid vehicle type.';
    }

    // Brand
    if ($form['brand'] === '') {
        $errors['brand'] = 'Brand is required.';
    }

    // Model
    if ($form['model'] === '') {
        $errors['model'] = 'Model is required.';
    }

    // Year
    $year_int = (int)$form['year'];
    if ($form['year'] === '' || $year_int < 1990 || $year_int > 2025) {
        $errors['year'] = 'Year must be between 1990 and 2025.';
    }

    // Capacity
    $cap_int = (int)$form['capacity'];
    if ($form['capacity'] === '' || $cap_int < 1) {
        $errors['capacity'] = 'Seating capacity must be at least 1.';
    }

    // Fuel type
    $allowed_fuels = ['Petrol', 'Diesel', 'Electric', 'Hybrid'];
    if (!in_array($form['fuel_type'], $allowed_fuels, true)) {
        $errors['fuel_type'] = 'Please select a valid fuel type.';
    }

    // Status
    $allowed_statuses = ['available', 'in_use', 'maintenance', 'retired'];
    if (!in_array($form['status'], $allowed_statuses, true)) {
        $errors['status'] = 'Please select a valid status.';
    }

    // Maintenance dates
    $last_maint_val = !empty($form['last_maintenance']) ? $form['last_maintenance'] : null;
    $next_maint_val = !empty($form['next_maintenance']) ? $form['next_maintenance'] : null;

    if ($last_maint_val !== null && $next_maint_val !== null) {
        if ($next_maint_val <= $last_maint_val) {
            $errors['next_maintenance'] = 'Next maintenance date must be after last maintenance date.';
        }
    }

    // Mileage
    $mileage_val = ($form['mileage'] !== '') ? (int)$form['mileage'] : 0;
    if ($form['mileage'] !== '' && $mileage_val < 0) {
        $errors['mileage'] = 'Mileage cannot be negative.';
    }

    // ── Update if no errors ───────────────────────────────────
    if (empty($errors)) {
        $stmt = $conn->prepare(
            "UPDATE vehicles SET
                plate_number     = ?,
                vehicle_type     = ?,
                brand            = ?,
                model            = ?,
                year             = ?,
                capacity         = ?,
                fuel_type        = ?,
                status           = ?,
                last_maintenance = ?,
                next_maintenance = ?,
                mileage          = ?,
                notes            = ?
             WHERE vehicle_id = ?"
        );
        $stmt->bind_param(
            'ssssiiisssisi',
            $form['plate_number'],
            $form['vehicle_type'],
            $form['brand'],
            $form['model'],
            $year_int,
            $cap_int,
            $form['fuel_type'],
            $form['status'],
            $last_maint_val,
            $next_maint_val,
            $mileage_val,
            $form['notes'],
            $vehicle_id
        );

        if ($stmt->execute()) {
            setFlash('success', 'Vehicle <strong>' . htmlspecialchars($form['plate_number']) . '</strong> updated successfully.');
            header('Location: ' . SITE_URL . '/admin/vehicles.php');
            exit();
        } else {
            $errors['db'] = 'Database error: ' . htmlspecialchars($conn->error);
        }
        $stmt->close();
    }
}

// Normalise null date values for the form
if (empty($form['last_maintenance']) || $form['last_maintenance'] === '0000-00-00') {
    $form['last_maintenance'] = '';
}
if (empty($form['next_maintenance']) || $form['next_maintenance'] === '0000-00-00') {
    $form['next_maintenance'] = '';
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
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Custom CSS -->
    <link href="<?php echo SITE_URL; ?>/assets/css/style.css" rel="stylesheet">

    <style>
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

        .form-card {
            border: none;
            border-radius: 14px;
            box-shadow: 0 2px 12px rgba(0,53,128,.10);
            margin-bottom: 1.5rem;
        }
        .form-card .card-header {
            background: #f8f9fb;
            border-bottom: 1px solid #e5e7eb;
            border-radius: 14px 14px 0 0 !important;
            font-weight: 600;
            font-size: .9rem;
            color: #003580;
            padding: .9rem 1.25rem;
        }
        .section-icon {
            width: 30px; height: 30px;
            border-radius: 8px;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: .8rem;
            margin-right: .5rem;
            background: rgba(0,53,128,.1);
            color: #003580;
        }

        .type-card {
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            padding: .75rem 1rem;
            cursor: pointer;
            transition: border-color .15s, background .15s;
            text-align: center;
        }
        .type-card:hover { border-color: #0056b3; background: #f0f5ff; }
        .type-radio:checked + .type-card {
            border-color: #003580;
            background: #e8f0fe;
        }
        .type-radio { display: none; }
        .type-icon { font-size: 1.4rem; margin-bottom: .3rem; }

        .last-trip-card {
            border-left: 4px solid #0056b3;
            background: #f0f5ff;
            border-radius: 0 10px 10px 0;
        }
    </style>
</head>
<body>

<?php include '../includes/sidebar.php'; ?>

<main class="main-content p-4">

    <!-- Page header -->
    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1><i class="fas fa-pen-to-square me-2" aria-hidden="true"></i>Edit Vehicle</h1>
            <p>Update details for plate <strong><?php echo htmlspecialchars($vehicle['plate_number']); ?></strong>.</p>
        </div>
        <a href="<?php echo SITE_URL; ?>/admin/vehicles.php" class="btn btn-light fw-semibold">
            <i class="fas fa-arrow-left me-1" aria-hidden="true"></i> Back to Vehicles
        </a>
    </div>

    <!-- Flash / DB errors -->
    <?php showFlash(); ?>
    <?php if (isset($errors['db'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php echo $errors['db']; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-triangle-exclamation me-2" aria-hidden="true"></i>
            <strong>Please fix the following errors before submitting:</strong>
            <ul class="mb-0 mt-1">
                <?php foreach ($errors as $key => $msg): ?>
                    <?php if ($key !== 'db'): ?>
                        <li><?php echo htmlspecialchars($msg); ?></li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Last Trip Info Banner -->
    <?php if ($last_trip): ?>
    <div class="alert last-trip-card mb-4 p-3" role="note">
        <div class="d-flex align-items-start gap-3">
            <div class="text-primary mt-1">
                <i class="fas fa-route fa-lg" aria-hidden="true"></i>
            </div>
            <div>
                <div class="fw-semibold text-primary mb-1">Last Trip Record</div>
                <div class="small text-muted">
                    <span class="me-3">
                        <i class="fas fa-calendar me-1" aria-hidden="true"></i>
                        <?php echo htmlspecialchars(date('d M Y', strtotime($last_trip['trip_date']))); ?>
                    </span>
                    <?php if (!empty($last_trip['start_time']) && !empty($last_trip['end_time'])): ?>
                    <span class="me-3">
                        <i class="fas fa-clock me-1" aria-hidden="true"></i>
                        <?php echo htmlspecialchars(date('g:i a', strtotime($last_trip['start_time']))); ?>
                        &ndash;
                        <?php echo htmlspecialchars(date('g:i a', strtotime($last_trip['end_time']))); ?>
                    </span>
                    <?php endif; ?>
                    <?php if (!empty($last_trip['destination'])): ?>
                    <span class="me-3">
                        <i class="fas fa-location-dot me-1" aria-hidden="true"></i>
                        <?php echo htmlspecialchars($last_trip['destination']); ?>
                    </span>
                    <?php endif; ?>
                    <?php if (!empty($last_trip['driver_name'])): ?>
                    <span>
                        <i class="fas fa-user me-1" aria-hidden="true"></i>
                        <?php echo htmlspecialchars($last_trip['driver_name']); ?>
                    </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <form method="POST"
          action="<?php echo SITE_URL; ?>/admin/edit_vehicle.php?id=<?php echo $vehicle_id; ?>"
          novalidate
          id="editVehicleForm">

        <!-- ── Section 1: Vehicle Information ─────────────────── -->
        <div class="card form-card">
            <div class="card-header">
                <span class="section-icon"><i class="fas fa-car" aria-hidden="true"></i></span>
                Vehicle Information
            </div>
            <div class="card-body p-4">
                <div class="row g-3">

                    <!-- Plate Number -->
                    <div class="col-md-4">
                        <label for="plate_number" class="form-label fw-semibold">
                            Plate Number <span class="text-danger">*</span>
                        </label>
                        <input type="text"
                               class="form-control font-monospace text-uppercase <?php echo isset($errors['plate_number']) ? 'is-invalid' : ''; ?>"
                               id="plate_number"
                               name="plate_number"
                               value="<?php echo htmlspecialchars($form['plate_number']); ?>"
                               maxlength="20"
                               required
                               autocomplete="off">
                        <?php if (isset($errors['plate_number'])): ?>
                            <div class="invalid-feedback"><?php echo htmlspecialchars($errors['plate_number']); ?></div>
                        <?php endif; ?>
                    </div>

                    <!-- Brand -->
                    <div class="col-md-4">
                        <label for="brand" class="form-label fw-semibold">
                            Brand <span class="text-danger">*</span>
                        </label>
                        <input type="text"
                               class="form-control <?php echo isset($errors['brand']) ? 'is-invalid' : ''; ?>"
                               id="brand"
                               name="brand"
                               value="<?php echo htmlspecialchars($form['brand']); ?>"
                               maxlength="100"
                               required>
                        <?php if (isset($errors['brand'])): ?>
                            <div class="invalid-feedback"><?php echo htmlspecialchars($errors['brand']); ?></div>
                        <?php endif; ?>
                    </div>

                    <!-- Model -->
                    <div class="col-md-4">
                        <label for="model" class="form-label fw-semibold">
                            Model <span class="text-danger">*</span>
                        </label>
                        <input type="text"
                               class="form-control <?php echo isset($errors['model']) ? 'is-invalid' : ''; ?>"
                               id="model"
                               name="model"
                               value="<?php echo htmlspecialchars($form['model']); ?>"
                               maxlength="100"
                               required>
                        <?php if (isset($errors['model'])): ?>
                            <div class="invalid-feedback"><?php echo htmlspecialchars($errors['model']); ?></div>
                        <?php endif; ?>
                    </div>

                    <!-- Vehicle Type -->
                    <div class="col-12">
                        <label class="form-label fw-semibold d-block">
                            Vehicle Type <span class="text-danger">*</span>
                        </label>
                        <div class="row g-2">
                            <?php
                            $type_options = [
                                'Bus'     => ['fa-bus',         'Bus',     '#0d6efd'],
                                'Van'     => ['fa-shuttle-van', 'Van',     '#198754'],
                                'Car'     => ['fa-car',         'Car',     '#dc3545'],
                                'Minibus' => ['fa-bus-simple',  'Minibus', '#6f42c1'],
                            ];
                            foreach ($type_options as $val => [$icon, $label, $color]):
                                $checked = ($form['vehicle_type'] === $val) ? 'checked' : '';
                            ?>
                            <div class="col-6 col-md-3">
                                <input type="radio"
                                       class="type-radio"
                                       id="type_<?php echo $val; ?>"
                                       name="vehicle_type"
                                       value="<?php echo $val; ?>"
                                       <?php echo $checked; ?>>
                                <label for="type_<?php echo $val; ?>" class="type-card d-block w-100">
                                    <div class="type-icon" style="color:<?php echo $color; ?>;">
                                        <i class="fas <?php echo $icon; ?>" aria-hidden="true"></i>
                                    </div>
                                    <div class="small fw-semibold"><?php echo $label; ?></div>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if (isset($errors['vehicle_type'])): ?>
                            <div class="text-danger small mt-1">
                                <i class="fas fa-circle-exclamation me-1" aria-hidden="true"></i>
                                <?php echo htmlspecialchars($errors['vehicle_type']); ?>
                            </div>
                        <?php endif; ?>
                    </div>

                </div><!-- /.row -->
            </div>
        </div>

        <!-- ── Section 2: Specifications ──────────────────────── -->
        <div class="card form-card">
            <div class="card-header">
                <span class="section-icon"><i class="fas fa-sliders" aria-hidden="true"></i></span>
                Specifications
            </div>
            <div class="card-body p-4">
                <div class="row g-3">

                    <!-- Year -->
                    <div class="col-md-3">
                        <label for="year" class="form-label fw-semibold">
                            Year <span class="text-danger">*</span>
                        </label>
                        <input type="number"
                               class="form-control <?php echo isset($errors['year']) ? 'is-invalid' : ''; ?>"
                               id="year"
                               name="year"
                               value="<?php echo htmlspecialchars((string)$form['year']); ?>"
                               min="1990" max="2025"
                               required>
                        <?php if (isset($errors['year'])): ?>
                            <div class="invalid-feedback"><?php echo htmlspecialchars($errors['year']); ?></div>
                        <?php endif; ?>
                    </div>

                    <!-- Seating Capacity -->
                    <div class="col-md-3">
                        <label for="capacity" class="form-label fw-semibold">
                            Seating Capacity <span class="text-danger">*</span>
                        </label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-person" aria-hidden="true"></i></span>
                            <input type="number"
                                   class="form-control <?php echo isset($errors['capacity']) ? 'is-invalid' : ''; ?>"
                                   id="capacity"
                                   name="capacity"
                                   value="<?php echo htmlspecialchars((string)$form['capacity']); ?>"
                                   min="1" max="100"
                                   required>
                            <?php if (isset($errors['capacity'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['capacity']); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Fuel Type -->
                    <div class="col-md-3">
                        <label for="fuel_type" class="form-label fw-semibold">
                            Fuel Type <span class="text-danger">*</span>
                        </label>
                        <select class="form-select <?php echo isset($errors['fuel_type']) ? 'is-invalid' : ''; ?>"
                                id="fuel_type" name="fuel_type" required>
                            <?php foreach (['Petrol', 'Diesel', 'Electric', 'Hybrid'] as $fuel): ?>
                                <option value="<?php echo $fuel; ?>" <?php echo $form['fuel_type'] === $fuel ? 'selected' : ''; ?>>
                                    <?php echo $fuel; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['fuel_type'])): ?>
                            <div class="invalid-feedback"><?php echo htmlspecialchars($errors['fuel_type']); ?></div>
                        <?php endif; ?>
                    </div>

                    <!-- Status -->
                    <div class="col-md-3">
                        <label for="status" class="form-label fw-semibold">
                            Status <span class="text-danger">*</span>
                        </label>
                        <select class="form-select <?php echo isset($errors['status']) ? 'is-invalid' : ''; ?>"
                                id="status" name="status" required>
                            <?php
                            $status_options = [
                                'available'   => 'Available',
                                'in_use'      => 'In Use',
                                'maintenance' => 'Maintenance',
                                'retired'     => 'Retired',
                            ];
                            foreach ($status_options as $val => $label):
                            ?>
                                <option value="<?php echo $val; ?>" <?php echo $form['status'] === $val ? 'selected' : ''; ?>>
                                    <?php echo $label; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['status'])): ?>
                            <div class="invalid-feedback"><?php echo htmlspecialchars($errors['status']); ?></div>
                        <?php endif; ?>
                    </div>

                </div><!-- /.row -->
            </div>
        </div>

        <!-- ── Section 3: Maintenance Info ────────────────────── -->
        <div class="card form-card">
            <div class="card-header">
                <span class="section-icon"><i class="fas fa-wrench" aria-hidden="true"></i></span>
                Maintenance Information
            </div>
            <div class="card-body p-4">
                <div class="row g-3">

                    <!-- Last Maintenance Date -->
                    <div class="col-md-4">
                        <label for="last_maintenance" class="form-label fw-semibold">Last Maintenance Date</label>
                        <input type="date"
                               class="form-control <?php echo isset($errors['last_maintenance']) ? 'is-invalid' : ''; ?>"
                               id="last_maintenance"
                               name="last_maintenance"
                               value="<?php echo htmlspecialchars($form['last_maintenance']); ?>"
                               max="<?php echo date('Y-m-d'); ?>">
                        <?php if (isset($errors['last_maintenance'])): ?>
                            <div class="invalid-feedback"><?php echo htmlspecialchars($errors['last_maintenance']); ?></div>
                        <?php endif; ?>
                    </div>

                    <!-- Next Maintenance Date -->
                    <div class="col-md-4">
                        <label for="next_maintenance" class="form-label fw-semibold">Next Maintenance Date</label>
                        <input type="date"
                               class="form-control <?php echo isset($errors['next_maintenance']) ? 'is-invalid' : ''; ?>"
                               id="next_maintenance"
                               name="next_maintenance"
                               value="<?php echo htmlspecialchars($form['next_maintenance']); ?>">
                        <?php if (isset($errors['next_maintenance'])): ?>
                            <div class="invalid-feedback"><?php echo htmlspecialchars($errors['next_maintenance']); ?></div>
                        <?php else: ?>
                            <div class="form-text">Must be after the last maintenance date.</div>
                        <?php endif; ?>
                    </div>

                    <!-- Current Mileage -->
                    <div class="col-md-4">
                        <label for="mileage" class="form-label fw-semibold">Current Mileage (km)</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-gauge-high" aria-hidden="true"></i></span>
                            <input type="number"
                                   class="form-control <?php echo isset($errors['mileage']) ? 'is-invalid' : ''; ?>"
                                   id="mileage"
                                   name="mileage"
                                   value="<?php echo htmlspecialchars((string)$form['mileage']); ?>"
                                   min="0">
                            <span class="input-group-text">km</span>
                            <?php if (isset($errors['mileage'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['mileage']); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Notes -->
                    <div class="col-12">
                        <label for="notes" class="form-label fw-semibold">Notes</label>
                        <textarea class="form-control"
                                  id="notes"
                                  name="notes"
                                  rows="3"
                                  maxlength="1000"><?php echo htmlspecialchars($form['notes']); ?></textarea>
                        <div class="form-text">Optional. Maximum 1000 characters.</div>
                    </div>

                </div><!-- /.row -->
            </div>
        </div>

        <!-- ── Form Actions ────────────────────────────────────── -->
        <div class="d-flex gap-2 justify-content-end mb-4">
            <a href="<?php echo SITE_URL; ?>/admin/vehicles.php" class="btn btn-secondary">
                <i class="fas fa-xmark me-1" aria-hidden="true"></i> Cancel
            </a>
            <button type="submit" class="btn btn-primary fw-semibold px-4">
                <i class="fas fa-floppy-disk me-1" aria-hidden="true"></i> Update Vehicle
            </button>
        </div>

    </form>

</main><!-- /.main-content -->

<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<!-- Bootstrap 5 JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- Custom JS -->
<script src="<?php echo SITE_URL; ?>/assets/js/main.js"></script>

<script>
(function () {
    'use strict';

    // ── Auto-uppercase plate number ───────────────────────────
    var plateInput = document.getElementById('plate_number');
    if (plateInput) {
        plateInput.addEventListener('input', function () {
            var pos    = this.selectionStart;
            this.value = this.value.toUpperCase();
            this.setSelectionRange(pos, pos);
        });
    }

    // ── Next maintenance must be after last maintenance ───────
    var lastMaint = document.getElementById('last_maintenance');
    var nextMaint = document.getElementById('next_maintenance');

    if (lastMaint && nextMaint) {
        // Initialise min on page load
        if (lastMaint.value) {
            nextMaint.min = lastMaint.value;
        }

        lastMaint.addEventListener('change', function () {
            if (this.value) {
                nextMaint.min = this.value;
                if (nextMaint.value && nextMaint.value <= this.value) {
                    nextMaint.value = '';
                }
            } else {
                nextMaint.removeAttribute('min');
            }
        });
    }

    // ── Client-side form validation ───────────────────────────
    var form = document.getElementById('editVehicleForm');
    if (form) {
        form.addEventListener('submit', function (e) {
            var valid = true;

            if (plateInput && plateInput.value.trim() === '') {
                plateInput.classList.add('is-invalid');
                valid = false;
            }

            var typeSelected = document.querySelector('input[name="vehicle_type"]:checked');
            var typeErrExisting = document.querySelector('.type-radio-error');
            if (!typeSelected) {
                if (!typeErrExisting) {
                    var errDiv = document.createElement('div');
                    errDiv.className = 'text-danger small mt-1 type-radio-error';
                    errDiv.innerHTML = '<i class="fas fa-circle-exclamation me-1"></i>Please select a vehicle type.';
                    document.querySelector('input[name="vehicle_type"]').closest('.col-12').appendChild(errDiv);
                }
                valid = false;
            } else if (typeErrExisting) {
                typeErrExisting.remove();
            }

            if (!valid) { e.preventDefault(); }
        });
    }

})();
</script>

</body>
</html>
