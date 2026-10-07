<?php
// ============================================================
// UIS Driver Scheduling and Management System
// admin/edit_vehicle.php  –  Edit Existing Vehicle
// Universiti Islam Selangor (UIS)
// ============================================================

require_once '../config/database.php';
require_once '../includes/upload.php';
requireAdmin();

$max_year = (int)date('Y') + 1;   // next model year is allowed

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
        $errors['plate_number'] = 'Enter the plate number, for example WA1234B.';
    } elseif (!preg_match('/^[A-Z0-9\s\-]+$/', $form['plate_number'])) {
        $errors['plate_number'] = 'Plate number can only contain letters, digits, spaces and hyphens (for example WA1234B). Remove any other characters.';
    } else {
        // Uniqueness: exclude the current vehicle
        $chk = $conn->prepare("SELECT vehicle_id FROM vehicles WHERE plate_number = ? AND vehicle_id != ?");
        $chk->bind_param('si', $form['plate_number'], $vehicle_id);
        $chk->execute();
        $chk->store_result();
        if ($chk->num_rows > 0) {
            $errors['plate_number'] = 'This plate number is already used by another vehicle. Check the number, or edit that vehicle instead.';
        }
        $chk->close();
    }

    // Vehicle type
    $allowed_types = ['Bus', 'Van', 'Car', 'Minibus', 'Lorry', 'Motorcycle'];
    if (!in_array($form['vehicle_type'], $allowed_types, true)) {
        $errors['vehicle_type'] = 'Select a vehicle type: Bus, Van, Car, Minibus, Lorry or Motorcycle.';
    }

    // Brand
    if ($form['brand'] === '') {
        $errors['brand'] = 'Enter the vehicle brand, for example Toyota.';
    }

    // Model
    if ($form['model'] === '') {
        $errors['model'] = 'Enter the vehicle model, for example Hiace.';
    }

    // Year
    $year_int = (int)$form['year'];
    if ($form['year'] === '' || $year_int < 1990 || $year_int > $max_year) {
        $errors['year'] = 'Year must be a whole number between 1990 and ' . $max_year . ', for example 2020.';
    }

    // Capacity
    $cap_int = (int)$form['capacity'];
    if ($form['capacity'] === '' || $cap_int < 1 || $cap_int > 100) {
        $errors['capacity'] = 'Seating capacity must be a whole number between 1 and 100, for example 14.';
    }

    // Fuel type
    $allowed_fuels = ['Petrol', 'Diesel', 'Electric', 'Hybrid'];
    if (!in_array($form['fuel_type'], $allowed_fuels, true)) {
        $errors['fuel_type'] = 'Select a fuel type: Petrol, Diesel, Electric or Hybrid.';
    }

    // Status
    $allowed_statuses = ['available', 'in_use', 'maintenance', 'retired'];
    if (!in_array($form['status'], $allowed_statuses, true)) {
        $errors['status'] = 'Select a status from the list: Available, In Use, Maintenance or Retired.';
    }

    // Maintenance dates
    $last_maint_val = !empty($form['last_maintenance']) ? $form['last_maintenance'] : null;
    $next_maint_val = !empty($form['next_maintenance']) ? $form['next_maintenance'] : null;

    if ($last_maint_val !== null && $next_maint_val !== null) {
        if ($next_maint_val <= $last_maint_val) {
            $errors['next_maintenance'] = 'Next maintenance date must be later than the last maintenance date. Choose a later date, or correct the last maintenance date.';
        }
    }

    // Mileage
    $mileage_val = ($form['mileage'] !== '') ? (int)$form['mileage'] : 0;
    if ($form['mileage'] !== '' && $mileage_val < 0) {
        $errors['mileage'] = 'Mileage must be 0 or more. Enter the odometer reading in whole kilometres.';
    }

    // Photo (optional) – stored only after all other validation passes
    $old_photo   = $vehicle['photo'] ?? null;
    $photo_path  = $old_photo;      // value written to the DB
    $new_upload  = null;            // path of a freshly uploaded file
    $remove_photo = !empty($_POST['remove_photo']);
    if (empty($errors)) {
        $up = saveUploadedImage($_FILES['photo'] ?? ['error' => UPLOAD_ERR_NO_FILE], 'vehicles');
        if (!$up['ok']) {
            $errors['photo'] = $up['error'];
        } elseif ($up['path'] !== null) {
            $new_upload = $up['path'];
            $photo_path = $new_upload;
        } elseif ($remove_photo) {
            $photo_path = null;
        }
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
                notes            = ?,
                photo            = ?
             WHERE vehicle_id = ?"
        );
        $stmt->bind_param(
            'ssssiiisssissi',
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
            $photo_path,
            $vehicle_id
        );

        if ($stmt->execute()) {
            // Remove the replaced / removed photo only after the UPDATE succeeded
            if ($old_photo !== null && $old_photo !== $photo_path) {
                deleteUploadedImage($old_photo);
            }
            setFlash('success', 'Vehicle <strong>' . htmlspecialchars($form['plate_number']) . '</strong> updated successfully.');
            header('Location: ' . SITE_URL . '/admin/vehicles.php');
            exit();
        } else {
            error_log('edit_vehicle: ' . $conn->error);
            $errors['db'] = 'The vehicle could not be saved because of a system error. Your entries are still on this page, so please try again. If it keeps happening, contact the system administrator.';
            // Update failed – do not leave an orphaned upload behind
            deleteUploadedImage($new_upload);
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
    <link rel="icon" type="image/png" href="<?php echo SITE_URL; ?>/assets/images/uis-favicon.png">

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
            background: linear-gradient(135deg, #0b5d3b 0%, #15804f 100%);
            border-radius: 14px;
            color: #fff;
            padding: 1.6rem 2rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 16px rgba(11,93,59,.20);
        }
        .page-header h1 { font-size: 1.55rem; font-weight: 700; margin: 0; }
        .page-header p  { margin: .3rem 0 0; opacity: .8; font-size: .88rem; }

        .form-card {
            border: none;
            border-radius: 14px;
            box-shadow: 0 2px 12px rgba(11,93,59,.10);
            margin-bottom: 1.5rem;
        }
        .form-card .card-header {
            background: #f8f9fb;
            border-bottom: 1px solid #e5e7eb;
            border-radius: 14px 14px 0 0 !important;
            font-weight: 600;
            font-size: .9rem;
            color: #0b5d3b;
            padding: .9rem 1.25rem;
        }
        .section-icon {
            width: 30px; height: 30px;
            border-radius: 8px;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: .8rem;
            margin-right: .5rem;
            background: rgba(11,93,59,.1);
            color: #0b5d3b;
        }

        .type-card {
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            padding: .75rem 1rem;
            cursor: pointer;
            transition: border-color .15s, background .15s;
            text-align: center;
        }
        .type-card:hover { border-color: #15804f; background: #f0f6f2; }
        .type-radio:checked + .type-card {
            border-color: #0b5d3b;
            background: #e8f0fe;
        }
        .type-radio { display: none; }
        .type-icon { font-size: 1.4rem; margin-bottom: .3rem; }

        .photo-preview {
            width: 100%;
            max-width: 220px;
            aspect-ratio: 4 / 3;
            object-fit: cover;
            border-radius: 10px;
            border: 1.5px solid #e5e7eb;
            background: #f8f9fb;
        }

        .last-trip-card {
            border-left: 4px solid #15804f;
            background: #f0f6f2;
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

    <?php
    // Field => [plain-language name, id of the control the summary link jumps to]
    $error_fields = [
        'plate_number'     => ['Plate number',          'plate_number'],
        'brand'            => ['Brand',                 'brand'],
        'model'            => ['Model',                 'model'],
        'vehicle_type'     => ['Vehicle type',          'vehicle_type_group'],
        'photo'            => ['Photo',                 'photo'],
        'year'             => ['Year',                  'year'],
        'capacity'         => ['Seating capacity',      'capacity'],
        'fuel_type'        => ['Fuel type',             'fuel_type'],
        'status'           => ['Status',                'status'],
        'last_maintenance' => ['Last maintenance date', 'last_maintenance'],
        'next_maintenance' => ['Next maintenance date', 'next_maintenance'],
        'mileage'          => ['Current mileage',       'mileage'],
    ];
    $summary_errors = array_intersect_key($error_fields, $errors);
    ?>
    <?php if (!empty($summary_errors)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert" id="errorSummary">
            <i class="fas fa-triangle-exclamation me-2" aria-hidden="true"></i>
            <strong>Nothing was saved. Please fix <?php echo count($summary_errors) === 1 ? 'this problem' : 'these ' . count($summary_errors) . ' problems'; ?> and submit again:</strong>
            <ul class="mb-0 mt-1">
                <?php foreach ($summary_errors as $key => [$field_name, $field_id]): ?>
                    <li><a href="#<?php echo $field_id; ?>" class="alert-link"><?php echo htmlspecialchars($field_name); ?></a>: <?php echo htmlspecialchars($errors[$key]); ?></li>
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
          enctype="multipart/form-data"
          novalidate
          id="editVehicleForm">

        <!-- ── Section 1: Vehicle Information ─────────────────── -->
        <div class="card form-card">
            <div class="card-header">
                <span class="section-icon"><i class="fas fa-car" aria-hidden="true"></i></span>
                Vehicle Information
            </div>
            <div class="card-body p-4">
                <p class="required-legend"><span class="req">*</span> Required field</p>
                <div class="row g-3">

                    <!-- Plate Number -->
                    <div class="col-md-4">
                        <label for="plate_number" class="form-label fw-semibold">
                            Plate Number <span class="req" aria-hidden="true">*</span>
                        </label>
                        <input type="text"
                               class="form-control font-monospace text-uppercase <?php echo isset($errors['plate_number']) ? 'is-invalid' : ''; ?>"
                               id="plate_number"
                               name="plate_number"
                               value="<?php echo htmlspecialchars($form['plate_number']); ?>"
                               maxlength="20"
                               required
                               autocomplete="off"
                               aria-required="true"
                               <?php echo isset($errors['plate_number']) ? 'aria-invalid="true" aria-describedby="plate_number_error plate_number_help"' : 'aria-describedby="plate_number_help"'; ?>
                               autocapitalize="characters"
                               spellcheck="false">
                        <?php if (isset($errors['plate_number'])): ?>
                            <div class="invalid-feedback" id="plate_number_error"><?php echo htmlspecialchars($errors['plate_number']); ?></div>
                        <?php endif; ?>
                        <div class="form-text" id="plate_number_help">Example: WA1234B. Letters are converted to uppercase automatically.</div>
                    </div>

                    <!-- Brand -->
                    <div class="col-md-4">
                        <label for="brand" class="form-label fw-semibold">
                            Brand <span class="req" aria-hidden="true">*</span>
                        </label>
                        <input type="text"
                               class="form-control <?php echo isset($errors['brand']) ? 'is-invalid' : ''; ?>"
                               id="brand"
                               name="brand"
                               value="<?php echo htmlspecialchars($form['brand']); ?>"
                               maxlength="100"
                               required
                               aria-required="true"
                               <?php echo isset($errors['brand']) ? 'aria-invalid="true" aria-describedby="brand_error"' : ''; ?>>
                        <?php if (isset($errors['brand'])): ?>
                            <div class="invalid-feedback" id="brand_error"><?php echo htmlspecialchars($errors['brand']); ?></div>
                        <?php endif; ?>
                    </div>

                    <!-- Model -->
                    <div class="col-md-4">
                        <label for="model" class="form-label fw-semibold">
                            Model <span class="req" aria-hidden="true">*</span>
                        </label>
                        <input type="text"
                               class="form-control <?php echo isset($errors['model']) ? 'is-invalid' : ''; ?>"
                               id="model"
                               name="model"
                               value="<?php echo htmlspecialchars($form['model']); ?>"
                               maxlength="100"
                               required
                               aria-required="true"
                               <?php echo isset($errors['model']) ? 'aria-invalid="true" aria-describedby="model_error"' : ''; ?>>
                        <?php if (isset($errors['model'])): ?>
                            <div class="invalid-feedback" id="model_error"><?php echo htmlspecialchars($errors['model']); ?></div>
                        <?php endif; ?>
                    </div>

                    <!-- Vehicle Type -->
                    <div class="col-12">
                        <div id="vehicle_type_label" class="form-label fw-semibold d-block">
                            Vehicle Type <span class="req" aria-hidden="true">*</span>
                        </div>
                        <div class="row g-2" id="vehicle_type_group" role="radiogroup" tabindex="-1"
                             aria-labelledby="vehicle_type_label" aria-required="true"
                             <?php echo isset($errors['vehicle_type']) ? 'aria-invalid="true" aria-describedby="vehicle_type_error"' : ''; ?>>
                            <?php
                            $type_options = [
                                'Bus'     => ['fa-bus',         'Bus',     '#0d6efd'],
                                'Van'     => ['fa-shuttle-van', 'Van',     '#198754'],
                                'Car'     => ['fa-car',         'Car',     '#dc3545'],
                                'Minibus' => ['fa-bus-simple',  'Minibus', '#6f42c1'],
                                'Lorry'   => ['fa-truck',       'Lorry',   '#fd7e14'],
                                'Motorcycle' => ['fa-motorcycle', 'Motorcycle', '#20c997'],
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
                                       required
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
                            <div class="text-danger small mt-1" id="vehicle_type_error">
                                <i class="fas fa-circle-exclamation me-1" aria-hidden="true"></i>
                                <?php echo htmlspecialchars($errors['vehicle_type']); ?>
                            </div>
                        <?php endif; ?>
                    </div>

                </div><!-- /.row -->
            </div>
        </div>

        <!-- ── Photo ───────────────────────────────────────────── -->
        <?php $has_photo = !empty($vehicle['photo']); ?>
        <div class="card form-card">
            <div class="card-header">
                <span class="section-icon"><i class="fas fa-image" aria-hidden="true"></i></span>
                Photo
            </div>
            <div class="card-body p-4">
                <div class="row g-3 align-items-center">
                    <div class="col-md-4 col-lg-3">
                        <img alt="Preview of the selected photo" id="photoPreview"
                             class="photo-preview"
                             src="<?php echo htmlspecialchars(vehiclePhotoUrl($vehicle['photo'] ?? null, $form['vehicle_type'])); ?>"
                             data-base="<?php echo htmlspecialchars(SITE_URL . '/assets/images/vehicles/'); ?>"
                             data-has-photo="<?php echo $has_photo ? '1' : '0'; ?>">
                    </div>
                    <div class="col-md-8 col-lg-9">
                        <label for="photo" class="form-label fw-semibold">Photo</label>
                        <input type="file"
                               class="form-control <?php echo isset($errors['photo']) ? 'is-invalid' : ''; ?>"
                               id="photo"
                               name="photo"
                               accept="image/jpeg,image/png,image/webp"
                               <?php echo isset($errors['photo']) ? 'aria-invalid="true" aria-describedby="photo_error photo_help"' : 'aria-describedby="photo_help"'; ?>>
                        <?php if (isset($errors['photo'])): ?>
                            <div class="invalid-feedback" id="photo_error"><?php echo htmlspecialchars($errors['photo']); ?></div>
                        <?php endif; ?>
                        <div class="form-text" id="photo_help">Optional &middot; JPG, PNG or WebP &middot; max 2 MB &middot; landscape works best</div>
                        <?php if ($has_photo): ?>
                        <div class="form-check mt-2">
                            <input class="form-check-input" type="checkbox" id="remove_photo" name="remove_photo" value="1">
                            <label class="form-check-label small" for="remove_photo">Remove photo</label>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
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
                            Year <span class="req" aria-hidden="true">*</span>
                        </label>
                        <input type="number"
                               class="form-control <?php echo isset($errors['year']) ? 'is-invalid' : ''; ?>"
                               id="year"
                               name="year"
                               value="<?php echo htmlspecialchars((string)$form['year']); ?>"
                               min="1990" max="<?php echo $max_year; ?>"
                               required
                               aria-required="true"
                               inputmode="numeric"
                               step="1"
                               <?php echo isset($errors['year']) ? 'aria-invalid="true" aria-describedby="year_error year_help"' : 'aria-describedby="year_help"'; ?>>
                        <?php if (isset($errors['year'])): ?>
                            <div class="invalid-feedback" id="year_error"><?php echo htmlspecialchars($errors['year']); ?></div>
                        <?php endif; ?>
                        <div class="form-text" id="year_help">Whole year, 1990&ndash;<?php echo $max_year; ?>.</div>
                    </div>

                    <!-- Seating Capacity -->
                    <div class="col-md-3">
                        <label for="capacity" class="form-label fw-semibold">
                            Seating Capacity <span class="req" aria-hidden="true">*</span>
                        </label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-person" aria-hidden="true"></i></span>
                            <input type="number"
                                   class="form-control <?php echo isset($errors['capacity']) ? 'is-invalid' : ''; ?>"
                                   id="capacity"
                                   name="capacity"
                                   value="<?php echo htmlspecialchars((string)$form['capacity']); ?>"
                                   min="1" max="100"
                                   required
                                   aria-required="true"
                                   inputmode="numeric"
                                   step="1"
                                   <?php echo isset($errors['capacity']) ? 'aria-invalid="true" aria-describedby="capacity_error capacity_help"' : 'aria-describedby="capacity_help"'; ?>>
                            <?php if (isset($errors['capacity'])): ?>
                                <div class="invalid-feedback" id="capacity_error"><?php echo htmlspecialchars($errors['capacity']); ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="form-text" id="capacity_help">Number of seats, 1&ndash;100.</div>
                    </div>

                    <!-- Fuel Type -->
                    <div class="col-md-3">
                        <label for="fuel_type" class="form-label fw-semibold">
                            Fuel Type <span class="req" aria-hidden="true">*</span>
                        </label>
                        <select class="form-select <?php echo isset($errors['fuel_type']) ? 'is-invalid' : ''; ?>"
                                id="fuel_type" name="fuel_type" required
                                aria-required="true"
                                <?php echo isset($errors['fuel_type']) ? 'aria-invalid="true" aria-describedby="fuel_type_error"' : ''; ?>>
                            <?php foreach (['Petrol', 'Diesel', 'Electric', 'Hybrid'] as $fuel): ?>
                                <option value="<?php echo $fuel; ?>" <?php echo $form['fuel_type'] === $fuel ? 'selected' : ''; ?>>
                                    <?php echo $fuel; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['fuel_type'])): ?>
                            <div class="invalid-feedback" id="fuel_type_error"><?php echo htmlspecialchars($errors['fuel_type']); ?></div>
                        <?php endif; ?>
                    </div>

                    <!-- Status -->
                    <div class="col-md-3">
                        <label for="status" class="form-label fw-semibold">
                            Status <span class="req" aria-hidden="true">*</span>
                        </label>
                        <select class="form-select <?php echo isset($errors['status']) ? 'is-invalid' : ''; ?>"
                                id="status" name="status" required
                                aria-required="true"
                                <?php echo isset($errors['status']) ? 'aria-invalid="true" aria-describedby="status_error"' : ''; ?>>
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
                            <div class="invalid-feedback" id="status_error"><?php echo htmlspecialchars($errors['status']); ?></div>
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
                               max="<?php echo date('Y-m-d'); ?>"
                               <?php echo isset($errors['last_maintenance']) ? 'aria-invalid="true" aria-describedby="last_maintenance_error"' : ''; ?>>
                        <?php if (isset($errors['last_maintenance'])): ?>
                            <div class="invalid-feedback" id="last_maintenance_error"><?php echo htmlspecialchars($errors['last_maintenance']); ?></div>
                        <?php endif; ?>
                    </div>

                    <!-- Next Maintenance Date -->
                    <div class="col-md-4">
                        <label for="next_maintenance" class="form-label fw-semibold">Next Maintenance Date</label>
                        <input type="date"
                               class="form-control <?php echo isset($errors['next_maintenance']) ? 'is-invalid' : ''; ?>"
                               id="next_maintenance"
                               name="next_maintenance"
                               value="<?php echo htmlspecialchars($form['next_maintenance']); ?>"
                               <?php echo isset($errors['next_maintenance']) ? 'aria-invalid="true" aria-describedby="next_maintenance_error next_maintenance_help"' : 'aria-describedby="next_maintenance_help"'; ?>>
                        <?php if (isset($errors['next_maintenance'])): ?>
                            <div class="invalid-feedback" id="next_maintenance_error"><?php echo htmlspecialchars($errors['next_maintenance']); ?></div>
                        <?php endif; ?>
                        <div class="form-text" id="next_maintenance_help">Must be after the last maintenance date.</div>
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
                                   min="0"
                                   inputmode="numeric"
                                   step="1"
                                   <?php echo isset($errors['mileage']) ? 'aria-invalid="true" aria-describedby="mileage_error mileage_help"' : 'aria-describedby="mileage_help"'; ?>>
                            <span class="input-group-text">km</span>
                            <?php if (isset($errors['mileage'])): ?>
                                <div class="invalid-feedback" id="mileage_error"><?php echo htmlspecialchars($errors['mileage']); ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="form-text" id="mileage_help">Odometer reading in whole kilometres.</div>
                    </div>

                    <!-- Notes -->
                    <div class="col-12">
                        <label for="notes" class="form-label fw-semibold">Notes</label>
                        <textarea class="form-control"
                                  id="notes"
                                  name="notes"
                                  rows="3"
                                  maxlength="1000"
                                  aria-describedby="notes_help"><?php echo htmlspecialchars($form['notes']); ?></textarea>
                        <div class="form-text" id="notes_help">Optional. Maximum 1000 characters.</div>
                    </div>

                </div><!-- /.row -->
            </div>
        </div>

        <!-- ── Form Actions ────────────────────────────────────── -->
        <div class="d-flex gap-2 justify-content-end mb-4">
            <a href="<?php echo SITE_URL; ?>/admin/vehicles.php" class="btn btn-outline-secondary px-4">
                <i class="fas fa-xmark me-1" aria-hidden="true"></i> Cancel
            </a>
            <button type="submit" class="btn btn-primary fw-semibold px-4">
                <i class="fas fa-floppy-disk me-1" aria-hidden="true"></i> Save Changes
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

    // ── Photo live preview ────────────────────────────────────
    var photoInput   = document.getElementById('photo');
    var photoPreview = document.getElementById('photoPreview');
    var removeBox    = document.getElementById('remove_photo');
    var originalSrc  = photoPreview ? photoPreview.getAttribute('src') : '';
    var hasPhoto     = photoPreview && photoPreview.getAttribute('data-has-photo') === '1';

    function typeIllustration() {
        var checked = document.querySelector('input[name="vehicle_type"]:checked');
        var slug = checked ? checked.value.toLowerCase() : 'car';
        return photoPreview.getAttribute('data-base') + slug + '.svg';
    }

    function refreshPreview() {
        var f = photoInput && photoInput.files && photoInput.files[0];
        if (f && f.type.indexOf('image/') === 0) {
            var reader = new FileReader();
            reader.onload = function (ev) { photoPreview.src = ev.target.result; };
            reader.readAsDataURL(f);
        } else if (hasPhoto && !(removeBox && removeBox.checked)) {
            photoPreview.src = originalSrc;
        } else {
            photoPreview.src = typeIllustration();
        }
    }

    if (photoInput && photoPreview) {
        photoInput.addEventListener('change', refreshPreview);
        if (removeBox) { removeBox.addEventListener('change', refreshPreview); }
        document.querySelectorAll('input[name="vehicle_type"]').forEach(function (r) {
            r.addEventListener('change', function () {
                if (!hasPhoto || (removeBox && removeBox.checked)) { refreshPreview(); }
            });
        });
    }

    // ── Client-side form validation ───────────────────────────
    var form = document.getElementById('editVehicleForm');
    if (form) {
        form.addEventListener('submit', function (e) {
            var valid = true;

            // Plate number required
            if (plateInput && plateInput.value.trim() === '') {
                plateInput.classList.add('is-invalid');
                plateInput.setAttribute('aria-invalid', 'true');
                var plateErr = document.getElementById('plate_number_error');
                if (!plateErr) {
                    plateErr = document.createElement('div');
                    plateErr.className = 'invalid-feedback';
                    plateErr.id = 'plate_number_error';
                    plateErr.textContent = 'Enter the plate number, for example WA1234B.';
                    plateInput.insertAdjacentElement('afterend', plateErr);
                }
                plateInput.setAttribute('aria-describedby', 'plate_number_error plate_number_help');
                valid = false;
            }

            var typeSelected = document.querySelector('input[name="vehicle_type"]:checked');
            var typeErrExisting = document.querySelector('.type-radio-error');
            if (!typeSelected) {
                if (!typeErrExisting) {
                    var errDiv = document.createElement('div');
                    errDiv.className = 'text-danger small mt-1 type-radio-error';
                    errDiv.id = 'vehicle_type_error';
                    errDiv.innerHTML = '<i class="fas fa-circle-exclamation me-1" aria-hidden="true"></i>Select a vehicle type: Bus, Van, Car, Minibus, Lorry or Motorcycle.';
                    document.querySelector('input[name="vehicle_type"]').closest('.col-12').appendChild(errDiv);
                }
                var typeGroup = document.getElementById('vehicle_type_group');
                if (typeGroup) {
                    typeGroup.setAttribute('aria-invalid', 'true');
                    typeGroup.setAttribute('aria-describedby', 'vehicle_type_error');
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
