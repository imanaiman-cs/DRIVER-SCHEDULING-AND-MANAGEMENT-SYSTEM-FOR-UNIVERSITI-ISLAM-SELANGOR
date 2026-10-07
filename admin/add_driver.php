<?php
// ============================================================
// UIS Driver Scheduling and Management System
// admin/add_driver.php  –  Add New Driver
// Universiti Islam Selangor (UIS)
// ============================================================

require_once '../config/database.php';
require_once '../includes/upload.php';
requireAdmin();

$page_title   = 'Add New Driver';
$current_page = 'add_driver.php';

// ── Form field defaults ──────────────────────────────────────
$form = [
    'employee_id'         => '',
    'name'                => '',
    'phone'               => '',
    'email'               => '',
    'address'             => '',
    'experience_years'    => '',
    'performance_score'   => '',
    'certification_score' => '',
    'license_number'      => '',
    'license_class'       => [],
    'license_expiry'      => '',
    'status'              => 'active',
    'driver_type'         => 'regular',
];

$errors = [];

// ── POST handler ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Collect & sanitise
    $form['employee_id']         = trim($_POST['employee_id']         ?? '');
    $form['name']                = trim($_POST['name']                ?? '');
    $form['phone']               = trim($_POST['phone']               ?? '');
    $form['email']               = trim($_POST['email']               ?? '');
    $form['address']             = trim($_POST['address']             ?? '');
    $form['experience_years']    = trim($_POST['experience_years']    ?? '');
    $form['performance_score']   = trim($_POST['performance_score']   ?? '');
    $form['certification_score'] = trim($_POST['certification_score'] ?? '');
    $form['license_number']      = trim($_POST['license_number']      ?? '');
    $posted_classes              = $_POST['license_class'] ?? [];
    $form['license_class']       = is_array($posted_classes)
        ? array_values(array_filter(array_map('trim', array_map('strval', $posted_classes)), 'strlen'))
        : [];
    $form['license_expiry']      = trim($_POST['license_expiry']      ?? '');
    $form['status']              = trim($_POST['status']              ?? 'active');
    $form['driver_type']         = trim($_POST['driver_type']         ?? 'regular');

    // ── Validation ───────────────────────────────────────────
    if ($form['employee_id'] === '') {
        $errors['employee_id'] = 'Enter the employee ID, for example EMP-0001.';
    } else {
        // Unique check
        $chk = $conn->prepare("SELECT driver_id FROM drivers WHERE employee_id = ?");
        $chk->bind_param('s', $form['employee_id']);
        $chk->execute();
        $chk->store_result();
        if ($chk->num_rows > 0) {
            $errors['employee_id'] = 'This Employee ID is already registered. Check the ID, or edit the existing driver from the Drivers list.';
        }
        $chk->close();
    }

    if ($form['name'] === '') {
        $errors['name'] = 'Enter the full name of the driver, for example Ahmad bin Ali.';
    }

    if ($form['phone'] === '') {
        $errors['phone'] = 'Enter a phone number the driver can be reached on, for example 0123456789.';
    }

    if ($form['email'] !== '' && !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email address in the form name@example.com, or leave this field empty.';
    }

    if ($form['experience_years'] === '') {
        $errors['experience_years'] = 'Enter the years of driving experience, for example 5 (enter 0 if none).';
    } elseif (!is_numeric($form['experience_years']) || (float)$form['experience_years'] < 0 || (float)$form['experience_years'] > 50) {
        $errors['experience_years'] = 'Experience must be a number between 0 and 50 years, for example 5 or 2.5.';
    }

    if ($form['performance_score'] === '') {
        $errors['performance_score'] = 'Enter a performance score from 0 to 10, for example 7.5.';
    } elseif (!is_numeric($form['performance_score']) || (float)$form['performance_score'] < 0 || (float)$form['performance_score'] > 10) {
        $errors['performance_score'] = 'Performance score must be a number between 0 and 10, for example 7.5.';
    }

    if ($form['certification_score'] === '') {
        $errors['certification_score'] = 'Enter a certification score from 0 to 10, for example 8.';
    } elseif (!is_numeric($form['certification_score']) || (float)$form['certification_score'] < 0 || (float)$form['certification_score'] > 10) {
        $errors['certification_score'] = 'Certification score must be a number between 0 and 10, for example 8.';
    }

    $allowed_statuses = ['active', 'inactive', 'on_leave'];
    if (!in_array($form['status'], $allowed_statuses, true)) {
        $form['status'] = 'active';
    }

    if (!in_array($form['driver_type'], ['regular', 'top_management'], true)) {
        $form['driver_type'] = 'regular';
    }

    // License class: one or more of B2, D, E (stored as comma list in stable order)
    $allowed_classes = ['B2', 'D', 'E'];
    if (empty($form['license_class'])) {
        $errors['license_class'] = 'Select at least one license class: B2, D or E.';
    } elseif (array_diff($form['license_class'], $allowed_classes)) {
        $errors['license_class'] = 'One of the selected license classes is not recognised. Select only B2, D or E.';
        $form['license_class']   = array_values(array_intersect($allowed_classes, $form['license_class']));
    } else {
        $form['license_class'] = array_values(array_intersect($allowed_classes, $form['license_class']));
    }

    // Photo (optional) – stored only after all other validation passes
    $photo_path = null;
    if (empty($errors)) {
        $up = saveUploadedImage($_FILES['photo'] ?? ['error' => UPLOAD_ERR_NO_FILE], 'drivers');
        if (!$up['ok']) {
            $errors['photo'] = $up['error'];
        } else {
            $photo_path = $up['path'];
        }
    }

    // ── Insert if no errors ──────────────────────────────────
    if (empty($errors)) {
        $exp   = (float)$form['experience_years'];
        $perf  = (float)$form['performance_score'];
        $cert  = (float)$form['certification_score'];
        $license_class_csv = implode(',', $form['license_class']);

        $stmt = $conn->prepare(
            "INSERT INTO drivers
                (employee_id, name, phone, email, address,
                 experience_years, performance_score, certification_score,
                 license_number, license_class, license_expiry, status, driver_type, photo, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())"
        );

        $expiry = $form['license_expiry'] !== '' ? $form['license_expiry'] : null;

        $stmt->bind_param(
            'sssssdddssssss',
            $form['employee_id'],
            $form['name'],
            $form['phone'],
            $form['email'],
            $form['address'],
            $exp, $perf, $cert,
            $form['license_number'],
            $license_class_csv,
            $expiry,
            $form['status'],
            $form['driver_type'],
            $photo_path
        );

        if ($stmt->execute()) {
            $stmt->close();
            setFlash('success', 'Driver <strong>' . htmlspecialchars($form['name']) . '</strong> has been added successfully.');
            header('Location: ' . SITE_URL . '/admin/drivers.php');
            exit();
        } else {
            error_log('add_driver: ' . $stmt->error);
            $stmt->close();
            $errors['db'] = 'The driver could not be saved because of a system error. Your entries are still on this page, so please try again. If it keeps happening, contact the system administrator.';
            // Insert failed – do not leave an orphaned upload behind
            deleteUploadedImage($photo_path);
        }
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
    <!-- DataTables Bootstrap 5 (CSS only needed for sidebar) -->
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
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
            padding: 1.4rem 2rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 16px rgba(11,93,59,.20);
        }
        .page-header h1 { font-size: 1.45rem; font-weight: 700; margin: 0; }
        .page-header p  { margin: .25rem 0 0; opacity: .8; font-size: .86rem; }

        .form-card {
            border: none;
            border-radius: 14px;
            box-shadow: 0 2px 12px rgba(11,93,59,.10);
        }
        .section-title {
            font-size: .78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: #0b5d3b;
            border-bottom: 2px solid #e8f0fe;
            padding-bottom: .5rem;
            margin-bottom: 1.2rem;
        }
        .form-label {
            font-size: .82rem;
            font-weight: 600;
            color: #374151;
            margin-bottom: .3rem;
        }
        .form-control, .form-select {
            border-radius: 8px;
            border: 1.5px solid #e5e7eb;
            font-size: .88rem;
            padding: .5rem .85rem;
            transition: border-color .2s, box-shadow .2s;
        }
        .form-control:focus, .form-select:focus {
            border-color: #15804f;
            box-shadow: 0 0 0 3px rgba(0,86,179,.12);
        }
        .form-control.is-invalid, .form-select.is-invalid {
            border-color: #dc3545;
        }
        .input-group-text {
            border-radius: 8px 0 0 8px;
            background: #f3f4f6;
            border: 1.5px solid #e5e7eb;
            color: #6b7280;
            font-size: .85rem;
        }
        .input-group .form-control {
            border-radius: 0 8px 8px 0;
        }

        /* Priority preview box */
        .priority-preview {
            border-radius: 12px;
            border: 2px dashed #c7d8f5;
            background: #f0f6f2;
            padding: 1.2rem 1.5rem;
            position: sticky;
            top: 1.5rem;
        }
        .priority-preview .score-display {
            font-size: 2.8rem;
            font-weight: 800;
            line-height: 1;
        }
        .priority-preview .score-bar {
            height: 10px;
            border-radius: 5px;
            background: #dce8fb;
            overflow: hidden;
        }
        .priority-preview .score-bar-fill {
            height: 100%;
            border-radius: 5px;
            transition: width .4s ease, background .4s ease;
        }
        .component-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: .82rem;
            padding: .25rem 0;
            border-bottom: 1px solid rgba(11,93,59,.06);
        }
        .component-row:last-child { border-bottom: none; }
        .component-label { color: #6b7280; }
        .component-value { font-weight: 600; color: #1a2035; }

        /* Score badge colors */
        .score-high   { color: #065f46; }
        .score-medium { color: #92400e; }
        .score-low    { color: #991b1b; }

        .required-star { color: #dc3545; }

        .photo-preview-circle {
            width: 96px;
            height: 96px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #e5e7eb;
            background: #f8f9fb;
        }
    </style>
</head>
<body>

<?php include '../includes/sidebar.php'; ?>

<main class="main-content p-4">

    <!-- Page header -->
    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1><i class="fas fa-user-plus me-2" aria-hidden="true"></i>Add New Driver</h1>
            <p>Fill in the form below to register a new driver at UIS.</p>
        </div>
        <a href="<?php echo SITE_URL; ?>/admin/drivers.php" class="btn btn-light fw-semibold">
            <i class="fas fa-arrow-left me-1" aria-hidden="true"></i> Back to Drivers
        </a>
    </div>

    <!-- Flash / validation errors -->
    <?php if (isset($errors['db'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-circle-exclamation me-2" aria-hidden="true"></i>
        <?php echo htmlspecialchars($errors['db']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>
    <?php
    // Field => [plain-language name, id of the control the summary link jumps to]
    $error_fields = [
        'photo'               => ['Photo',               'photo'],
        'employee_id'         => ['Employee ID',         'employee_id'],
        'name'                => ['Full name',           'name'],
        'phone'               => ['Phone number',        'phone'],
        'email'               => ['Email address',       'email'],
        'experience_years'    => ['Experience years',    'experience_years'],
        'performance_score'   => ['Performance score',   'performance_score'],
        'certification_score' => ['Certification score', 'certification_score'],
        'license_class'       => ['License class',       'license_class_group'],
    ];
    $summary_errors = array_intersect_key($error_fields, $errors);
    ?>
    <?php if (!empty($summary_errors)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert" id="errorSummary">
        <i class="fas fa-circle-exclamation me-2" aria-hidden="true"></i>
        <strong>Nothing was saved. Please fix <?php echo count($summary_errors) === 1 ? 'this problem' : 'these ' . count($summary_errors) . ' problems'; ?> and submit again:</strong>
        <ul class="mb-0 mt-1">
            <?php foreach ($summary_errors as $key => [$field_name, $field_id]): ?>
                <li><a href="#<?php echo $field_id; ?>" class="alert-link"><?php echo htmlspecialchars($field_name); ?></a>: <?php echo htmlspecialchars($errors[$key]); ?></li>
            <?php endforeach; ?>
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>

    <form method="POST" action="add_driver.php" id="addDriverForm" enctype="multipart/form-data" novalidate>

        <div class="row g-4">

            <!-- ── Left: Form fields ─────────────────────────── -->
            <div class="col-lg-8">

                <!-- Personal Information -->
                <div class="card form-card mb-4">
                    <div class="card-body p-4">
                        <div class="section-title">
                            <i class="fas fa-user me-1"></i> Personal Information
                        </div>
                        <p class="required-legend"><span class="req">*</span> Required field</p>
                        <div class="row g-3">

                            <div class="col-12">
                                <label for="photo" class="form-label">Photo</label>
                                <div class="d-flex align-items-center gap-3 flex-wrap">
                                    <img alt="Preview of the selected photo" id="photoPreview"
                                         class="photo-preview-circle"
                                         src="<?php echo htmlspecialchars(driverPhotoUrl(null)); ?>">
                                    <div class="flex-grow-1" style="min-width:220px;">
                                        <input type="file"
                                               id="photo"
                                               name="photo"
                                               class="form-control <?php echo isset($errors['photo']) ? 'is-invalid' : ''; ?>"
                                               accept="image/jpeg,image/png,image/webp"
                                               <?php echo isset($errors['photo']) ? 'aria-invalid="true" aria-describedby="photo_error photo_help"' : 'aria-describedby="photo_help"'; ?>>
                                        <?php if (isset($errors['photo'])): ?>
                                            <div class="invalid-feedback" id="photo_error"><?php echo htmlspecialchars($errors['photo']); ?></div>
                                        <?php endif; ?>
                                        <div class="form-text" id="photo_help">Optional &middot; JPG, PNG or WebP &middot; max 2 MB &middot; landscape works best</div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label for="employee_id" class="form-label">
                                    Employee ID <span class="req" aria-hidden="true">*</span>
                                </label>
                                <input type="text"
                                       id="employee_id"
                                       name="employee_id"
                                       class="form-control <?php echo isset($errors['employee_id']) ? 'is-invalid' : ''; ?>"
                                       value="<?php echo htmlspecialchars($form['employee_id']); ?>"
                                       placeholder="e.g. EMP-0001"
                                       maxlength="50"
                                       required
                                       aria-required="true"
                                       autocomplete="off"
                                       spellcheck="false"
                                       <?php echo isset($errors['employee_id']) ? 'aria-invalid="true" aria-describedby="employee_id_error employee_id_help"' : 'aria-describedby="employee_id_help"'; ?>>
                                <?php if (isset($errors['employee_id'])): ?>
                                    <div class="invalid-feedback" id="employee_id_error"><?php echo htmlspecialchars($errors['employee_id']); ?></div>
                                <?php endif; ?>
                                <div class="form-text" id="employee_id_help">Must be unique, for example EMP-0001.</div>
                            </div>

                            <div class="col-md-6">
                                <label for="name" class="form-label">
                                    Full Name <span class="req" aria-hidden="true">*</span>
                                </label>
                                <input type="text"
                                       id="name"
                                       name="name"
                                       class="form-control <?php echo isset($errors['name']) ? 'is-invalid' : ''; ?>"
                                       value="<?php echo htmlspecialchars($form['name']); ?>"
                                       placeholder="e.g. Ahmad bin Ali"
                                       maxlength="150"
                                       required
                                       aria-required="true"
                                       autocomplete="off"
                                       <?php echo isset($errors['name']) ? 'aria-invalid="true" aria-describedby="name_error"' : ''; ?>>
                                <?php if (isset($errors['name'])): ?>
                                    <div class="invalid-feedback" id="name_error"><?php echo htmlspecialchars($errors['name']); ?></div>
                                <?php endif; ?>
                            </div>

                            <div class="col-md-6">
                                <label for="phone" class="form-label">
                                    Phone Number <span class="req" aria-hidden="true">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-phone"></i></span>
                                    <input type="tel"
                                           id="phone"
                                           name="phone"
                                           class="form-control <?php echo isset($errors['phone']) ? 'is-invalid' : ''; ?>"
                                           value="<?php echo htmlspecialchars($form['phone']); ?>"
                                           placeholder="e.g. 0123456789"
                                           maxlength="20"
                                           required
                                           aria-required="true"
                                           inputmode="tel"
                                           autocomplete="off"
                                           <?php echo isset($errors['phone']) ? 'aria-invalid="true" aria-describedby="phone_error phone_help"' : 'aria-describedby="phone_help"'; ?>>
                                    <?php if (isset($errors['phone'])): ?>
                                        <div class="invalid-feedback" id="phone_error"><?php echo htmlspecialchars($errors['phone']); ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="form-text" id="phone_help">Example: 0123456789 or 011-2835 4792.</div>
                            </div>

                            <div class="col-md-6">
                                <label for="email" class="form-label">Email Address</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                                    <input type="email"
                                           id="email"
                                           name="email"
                                           class="form-control <?php echo isset($errors['email']) ? 'is-invalid' : ''; ?>"
                                           value="<?php echo htmlspecialchars($form['email']); ?>"
                                           placeholder="e.g. ahmad@example.com"
                                           maxlength="150"
                                           autocomplete="off"
                                           <?php echo isset($errors['email']) ? 'aria-invalid="true" aria-describedby="email_error"' : ''; ?>>
                                    <?php if (isset($errors['email'])): ?>
                                        <div class="invalid-feedback" id="email_error"><?php echo htmlspecialchars($errors['email']); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="col-12">
                                <label for="address" class="form-label">Address</label>
                                <textarea id="address"
                                          name="address"
                                          class="form-control"
                                          rows="2"
                                          placeholder="Full postal address"
                                          maxlength="500"><?php echo htmlspecialchars($form['address']); ?></textarea>
                            </div>

                            <div class="col-md-6">
                                <label for="status" class="form-label">Status</label>
                                <select id="status" name="status" class="form-select">
                                    <option value="active"   <?php echo $form['status'] === 'active'   ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo $form['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                    <option value="on_leave" <?php echo $form['status'] === 'on_leave' ? 'selected' : ''; ?>>On Leave</option>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label for="driver_type" class="form-label">Driver Type</label>
                                <select id="driver_type" name="driver_type" class="form-select" aria-describedby="driver_type_help">
                                    <option value="regular"        <?php echo $form['driver_type'] === 'regular'        ? 'selected' : ''; ?>>Regular</option>
                                    <option value="top_management" <?php echo $form['driver_type'] === 'top_management' ? 'selected' : ''; ?>>Top Management</option>
                                </select>
                                <div class="form-text" id="driver_type_help">Top Management drivers handle VIP and executive trips only.</div>
                            </div>

                        </div>
                    </div>
                </div><!-- /Personal Info -->

                <!-- Scoring Metrics -->
                <div class="card form-card mb-4">
                    <div class="card-body p-4">
                        <div class="section-title">
                            <i class="fas fa-chart-bar me-1"></i> Scoring Metrics
                        </div>
                        <div class="row g-3">

                            <div class="col-md-6">
                                <label for="experience_years" class="form-label">
                                    Experience Years <span class="req" aria-hidden="true">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-calendar-days"></i></span>
                                    <input type="number"
                                           id="experience_years"
                                           name="experience_years"
                                           class="form-control <?php echo isset($errors['experience_years']) ? 'is-invalid' : ''; ?>"
                                           value="<?php echo htmlspecialchars($form['experience_years']); ?>"
                                           placeholder="0.0"
                                           min="0" max="50" step="0.5"
                                           required
                                           aria-required="true"
                                           inputmode="decimal"
                                           <?php echo isset($errors['experience_years']) ? 'aria-invalid="true" aria-describedby="experience_years_error experience_years_help"' : 'aria-describedby="experience_years_help"'; ?>>
                                    <span class="input-group-text">yrs</span>
                                    <?php if (isset($errors['experience_years'])): ?>
                                        <div class="invalid-feedback" id="experience_years_error"><?php echo htmlspecialchars($errors['experience_years']); ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="form-text" id="experience_years_help">Years of driving experience (0–50, half years allowed). Capped at 20 yrs for workload-balancing score.</div>
                            </div>

                            <div class="col-md-6">
                                <label for="performance_score" class="form-label">
                                    Performance Score <span class="req" aria-hidden="true">*</span>
                                    <span class="fw-normal text-muted">(0–10 scale)</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-star"></i></span>
                                    <input type="number"
                                           id="performance_score"
                                           name="performance_score"
                                           class="form-control <?php echo isset($errors['performance_score']) ? 'is-invalid' : ''; ?>"
                                           value="<?php echo htmlspecialchars($form['performance_score']); ?>"
                                           placeholder="0.0"
                                           min="0" max="10" step="0.1"
                                           required
                                           aria-required="true"
                                           inputmode="decimal"
                                           <?php echo isset($errors['performance_score']) ? 'aria-invalid="true" aria-describedby="performance_score_error performance_score_help"' : 'aria-describedby="performance_score_help"'; ?>>
                                    <span class="input-group-text">/ 10</span>
                                    <?php if (isset($errors['performance_score'])): ?>
                                        <div class="invalid-feedback" id="performance_score_error"><?php echo htmlspecialchars($errors['performance_score']); ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="form-text" id="performance_score_help">Overall driving performance rating, 0&ndash;10 (informational only).</div>
                            </div>

                            <div class="col-md-6">
                                <label for="certification_score" class="form-label">
                                    Certification Score <span class="req" aria-hidden="true">*</span>
                                    <span class="fw-normal text-muted">(0–10 scale)</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-certificate"></i></span>
                                    <input type="number"
                                           id="certification_score"
                                           name="certification_score"
                                           class="form-control <?php echo isset($errors['certification_score']) ? 'is-invalid' : ''; ?>"
                                           value="<?php echo htmlspecialchars($form['certification_score']); ?>"
                                           placeholder="0.0"
                                           min="0" max="10" step="0.1"
                                           required
                                           aria-required="true"
                                           inputmode="decimal"
                                           <?php echo isset($errors['certification_score']) ? 'aria-invalid="true" aria-describedby="certification_score_error certification_score_help"' : 'aria-describedby="certification_score_help"'; ?>>
                                    <span class="input-group-text">/ 10</span>
                                    <?php if (isset($errors['certification_score'])): ?>
                                        <div class="invalid-feedback" id="certification_score_error"><?php echo htmlspecialchars($errors['certification_score']); ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="form-text" id="certification_score_help">Certification/training score, 0&ndash;10 (informational only).</div>
                            </div>

                        </div>
                    </div>
                </div><!-- /Scoring -->

                <!-- License Information -->
                <div class="card form-card mb-4">
                    <div class="card-body p-4">
                        <div class="section-title">
                            <i class="fas fa-id-card me-1"></i> License Information
                        </div>
                        <div class="row g-3">

                            <div class="col-md-5">
                                <label for="license_number" class="form-label">License Number</label>
                                <input type="text"
                                       id="license_number"
                                       name="license_number"
                                       class="form-control"
                                       value="<?php echo htmlspecialchars($form['license_number']); ?>"
                                       placeholder="e.g. D1234567"
                                       maxlength="30"
                                       autocomplete="off"
                                       spellcheck="false">
                            </div>

                            <div class="col-md-3">
                                <div id="license_class_label" class="form-label">License Class <span class="req" aria-hidden="true">*</span><span class="visually-hidden"> (required, select at least one)</span></div>
                                <div id="license_class_group" class="<?php echo isset($errors['license_class']) ? 'is-invalid' : ''; ?>"
                                     role="group" tabindex="-1" aria-labelledby="license_class_label"
                                     <?php echo isset($errors['license_class']) ? 'aria-invalid="true" aria-describedby="license_class_error license_class_help"' : 'aria-describedby="license_class_help"'; ?>>
                                    <?php foreach (['B2' => 'B2 (Motorcycle)', 'D' => 'D (Car/Van/Minibus)', 'E' => 'E (Bus/Lorry)'] as $lc => $lcLabel): ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox"
                                               name="license_class[]"
                                               id="license_class_<?php echo $lc; ?>"
                                               value="<?php echo $lc; ?>"
                                               <?php echo in_array($lc, $form['license_class'], true) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="license_class_<?php echo $lc; ?>" style="font-size:.85rem;">
                                            <?php echo htmlspecialchars($lcLabel); ?>
                                        </label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php if (isset($errors['license_class'])): ?>
                                    <div class="invalid-feedback d-block" id="license_class_error"><?php echo htmlspecialchars($errors['license_class']); ?></div>
                                <?php endif; ?>
                                <div class="form-text" id="license_class_help">B2 = motorcycle, D = car/van/minibus, E = bus/lorry. Select all that apply.</div>
                            </div>

                            <div class="col-md-4">
                                <label for="license_expiry" class="form-label">License Expiry Date</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                                    <input type="date"
                                           id="license_expiry"
                                           name="license_expiry"
                                           class="form-control"
                                           value="<?php echo htmlspecialchars($form['license_expiry']); ?>">
                                </div>
                            </div>

                        </div>
                    </div>
                </div><!-- /License -->

                <!-- Action buttons -->
                <div class="d-flex gap-2 justify-content-end">
                    <button type="submit" class="btn btn-primary px-5 fw-semibold">
                        <i class="fas fa-floppy-disk me-1" aria-hidden="true"></i> Save Driver
                    </button>
                    <a href="<?php echo SITE_URL; ?>/admin/drivers.php" class="btn btn-outline-secondary px-4">
                        <i class="fas fa-xmark me-1" aria-hidden="true"></i> Cancel
                    </a>
                </div>

            </div><!-- /left col -->

            <!-- ── Right: Allocation info ────────────────────── -->
            <div class="col-lg-4">
                <div class="priority-preview">
                    <div class="mb-3 d-flex align-items-center gap-2">
                        <div style="width:36px;height:36px;border-radius:10px;background:#0b5d3b;
                                    display:flex;align-items:center;justify-content:center;color:#fff;">
                            <i class="fas fa-scale-balanced" aria-hidden="true"></i>
                        </div>
                        <div class="fw-bold text-primary" style="font-size:.95rem;">How Drivers Are Recommended</div>
                    </div>

                    <p class="mb-0" style="font-size:.84rem;color:#4b5563;line-height:1.6;">
                        Drivers are recommended automatically using workload balancing:
                        fewer tasks this month (50%), fewer weekend tasks (30%), more experience (20%).
                    </p>
                </div><!-- /priority-preview -->
            </div><!-- /right col -->

        </div><!-- /.row -->

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

    // ── Photo live preview ───────────────────────────────────
    var photoInput   = document.getElementById('photo');
    var photoPreview = document.getElementById('photoPreview');
    if (photoInput && photoPreview) {
        var defaultSrc = photoPreview.getAttribute('src');
        photoInput.addEventListener('change', function () {
            var f = this.files && this.files[0];
            if (f && f.type.indexOf('image/') === 0) {
                var reader = new FileReader();
                reader.onload = function (ev) { photoPreview.src = ev.target.result; };
                reader.readAsDataURL(f);
            } else {
                photoPreview.src = defaultSrc;
            }
        });
    }

    // ── Accessible inline errors (used by client-side validation) ──
    function helpIdFor(el) {
        return document.getElementById(el.id + '_help') ? el.id + '_help' : '';
    }
    function setFieldError(el, msg) {
        var errId = el.id + '_error';
        var msgEl = document.getElementById(errId);
        if (!msgEl) {
            msgEl = document.createElement('div');
            msgEl.className = 'invalid-feedback';
            msgEl.id = errId;
            msgEl.setAttribute('data-client', '1');
            // Same place the server-side message uses: end of the input group,
            // or straight after the input when it is not in a group
            var grp = el.closest('.input-group');
            if (grp) { grp.appendChild(msgEl); } else { el.insertAdjacentElement('afterend', msgEl); }
        }
        msgEl.textContent = msg;
        el.classList.add('is-invalid');
        el.setAttribute('aria-invalid', 'true');
        el.setAttribute('aria-describedby', (errId + ' ' + helpIdFor(el)).trim());
    }
    function clearFieldError(el) {
        el.classList.remove('is-invalid');
        el.removeAttribute('aria-invalid');
        var msgEl = document.getElementById(el.id + '_error');
        if (msgEl && msgEl.getAttribute('data-client') === '1') { msgEl.remove(); }
        var help = helpIdFor(el);
        if (help) { el.setAttribute('aria-describedby', help); } else { el.removeAttribute('aria-describedby'); }
    }
    function setGroupError(group, msg) {
        var msgEl = document.getElementById('license_class_error');
        if (!msgEl) {
            msgEl = document.createElement('div');
            msgEl.className = 'invalid-feedback d-block';
            msgEl.id = 'license_class_error';
            msgEl.setAttribute('data-client', '1');
            group.insertAdjacentElement('afterend', msgEl);
        }
        msgEl.textContent = msg;
        group.classList.add('is-invalid');
        group.setAttribute('aria-invalid', 'true');
        group.setAttribute('aria-describedby', 'license_class_error license_class_help');
    }
    function clearGroupError(group) {
        group.classList.remove('is-invalid');
        group.removeAttribute('aria-invalid');
        group.setAttribute('aria-describedby', 'license_class_help');
        var msgEl = document.getElementById('license_class_error');
        if (msgEl && msgEl.getAttribute('data-client') === '1') { msgEl.remove(); }
    }

    // ── Client-side validation ───────────────────────────────
    var form = document.getElementById('addDriverForm');
    if (form) {
        form.addEventListener('submit', function (e) {
            var valid = true;

            // Required fields – each failure shows an actionable message and is
            // linked to the field for screen readers (aria-invalid / aria-describedby).
            var requiredMessages = {
                employee_id:         'Enter the employee ID, for example EMP-0001.',
                name:                'Enter the full name of the driver, for example Ahmad bin Ali.',
                phone:               'Enter a phone number the driver can be reached on, for example 0123456789.',
                experience_years:    'Enter the years of driving experience, for example 5 (enter 0 if none).',
                performance_score:   'Enter a performance score from 0 to 10, for example 7.5.',
                certification_score: 'Enter a certification score from 0 to 10, for example 8.'
            };

            Object.keys(requiredMessages).forEach(function (fieldId) {
                var el = document.getElementById(fieldId);
                if (!el) { return; }
                if (el.value.trim() === '') {
                    setFieldError(el, requiredMessages[fieldId]);
                    valid = false;
                } else {
                    clearFieldError(el);
                }
            });

            // At least one license class
            var lcGroup = document.getElementById('license_class_group');
            if (lcGroup) {
                if (!form.querySelector('input[name="license_class[]"]:checked')) {
                    setGroupError(lcGroup, 'Select at least one license class: B2, D or E.');
                    valid = false;
                } else {
                    clearGroupError(lcGroup);
                }
            }

            if (!valid) {
                e.preventDefault();
                document.querySelector('.is-invalid').scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        });
    }

})();
</script>

</body>
</html>
