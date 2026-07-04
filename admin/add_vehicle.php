<?php
// ============================================================
// UIS Driver Scheduling and Management System
// admin/add_vehicle.php  –  Add New Vehicle
// Universiti Islam Selangor (UIS)
// ============================================================

require_once '../config/database.php';
requireAdmin();

$page_title   = 'Add New Vehicle';
$current_page = 'add_vehicle.php';

// ── Form field defaults ──────────────────────────────────────
$errors = [];
$form   = [
    'plate_number'     => '',
    'vehicle_type'     => '',
    'brand'            => '',
    'model'            => '',
    'year'             => '',
    'capacity'         => '',
    'fuel_type'        => '',
    'status'           => 'available',
    'last_maintenance' => '',
    'next_maintenance' => '',
    'mileage'          => '',
    'notes'            => '',
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
        // Uniqueness check
        $chk = $conn->prepare("SELECT vehicle_id FROM vehicles WHERE plate_number = ?");
        $chk->bind_param('s', $form['plate_number']);
        $chk->execute();
        $chk->store_result();
        if ($chk->num_rows > 0) {
            $errors['plate_number'] = 'A vehicle with this plate number already exists.';
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

    // Mileage (optional)
    $mileage_val = ($form['mileage'] !== '') ? (int)$form['mileage'] : 0;
    if ($form['mileage'] !== '' && $mileage_val < 0) {
        $errors['mileage'] = 'Mileage cannot be negative.';
    }

    // ── Insert if no errors ───────────────────────────────────
    if (empty($errors)) {
        $stmt = $conn->prepare(
            "INSERT INTO vehicles
                (plate_number, vehicle_type, brand, model, year, capacity,
                 fuel_type, status, last_maintenance, next_maintenance, mileage, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param(
            'ssssiiisssis',
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
            $form['notes']
        );

        if ($stmt->execute()) {
            setFlash('success', 'Vehicle <strong>' . htmlspecialchars($form['plate_number']) . '</strong> added successfully.');
            header('Location: ' . SITE_URL . '/admin/vehicles.php');
            exit();
        } else {
            $errors['db'] = 'Database error: ' . htmlspecialchars($conn->error);
        }
        $stmt->close();
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
    </style>
</head>
<body>

<?php include '../includes/sidebar.php'; ?>

<main class="main-content p-4">

    <!-- Page header -->
    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1><i class="fas fa-circle-plus me-2" aria-hidden="true"></i>Add New Vehicle</h1>
            <p>Register a new vehicle in the UIS fleet.</p>
        </div>
        <a href="<?php echo SITE_URL; ?>/admin/vehicles.php" class="btn btn-light fw-semibold">
            <i class="fas fa-arrow-left me-1" aria-hidden="true"></i> Back to Vehicles
        </a>
    </div>

    <!-- Flash / DB error -->
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

    <form method="POST" action="" novalidate id="addVehicleForm">

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
                               placeholder="e.g. WA1234B"
                               maxlength="20"
                               required
                               autocomplete="off">
                        <?php if (isset($errors['plate_number'])): ?>
                            <div class="invalid-feedback"><?php echo htmlspecialchars($errors['plate_number']); ?></div>
                        <?php else: ?>
                            <div class="form-text">Auto-converted to uppercase.</div>
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
                               placeholder="e.g. Toyota"
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
                               placeholder="e.g. Hiace"
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
                               value="<?php echo htmlspecialchars($form['year']); ?>"
                               min="1990" max="2025"
                               placeholder="e.g. 2020"
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
                                   value="<?php echo htmlspecialchars($form['capacity']); ?>"
                                   min="1" max="100"
                                   placeholder="e.g. 14"
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
                            <option value="" disabled <?php echo $form['fuel_type'] === '' ? 'selected' : ''; ?>>-- Select --</option>
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
                                   value="<?php echo htmlspecialchars($form['mileage']); ?>"
                                   min="0"
                                   placeholder="e.g. 45000">
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
                                  maxlength="1000"
                                  placeholder="Any additional information about this vehicle..."><?php echo htmlspecialchars($form['notes']); ?></textarea>
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
                <i class="fas fa-floppy-disk me-1" aria-hidden="true"></i> Save Vehicle
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
            var pos   = this.selectionStart;
            this.value = this.value.toUpperCase();
            this.setSelectionRange(pos, pos);
        });
    }

    // ── Next maintenance must be after last maintenance ───────
    var lastMaint = document.getElementById('last_maintenance');
    var nextMaint = document.getElementById('next_maintenance');

    if (lastMaint && nextMaint) {
        lastMaint.addEventListener('change', function () {
            if (this.value) {
                nextMaint.min = this.value;
                if (nextMaint.value && nextMaint.value <= this.value) {
                    nextMaint.value = '';
                }
            }
        });
    }

    // ── Client-side form validation ───────────────────────────
    var form = document.getElementById('addVehicleForm');
    if (form) {
        form.addEventListener('submit', function (e) {
            var valid = true;

            // Plate number required
            if (plateInput && plateInput.value.trim() === '') {
                plateInput.classList.add('is-invalid');
                valid = false;
            }

            // Vehicle type required
            var typeSelected = document.querySelector('input[name="vehicle_type"]:checked');
            var typeErr = document.querySelector('.type-radio-error');
            if (!typeSelected) {
                if (!typeErr) {
                    var errDiv = document.createElement('div');
                    errDiv.className = 'text-danger small mt-1 type-radio-error';
                    errDiv.innerHTML = '<i class="fas fa-circle-exclamation me-1"></i>Please select a vehicle type.';
                    document.querySelector('input[name="vehicle_type"]').closest('.col-12').appendChild(errDiv);
                }
                valid = false;
            } else if (typeErr) {
                typeErr.remove();
            }

            if (!valid) { e.preventDefault(); }
        });
    }

})();
</script>

</body>
</html>
