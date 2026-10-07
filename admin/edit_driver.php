<?php
// ============================================================
// UIS Driver Scheduling and Management System
// admin/edit_driver.php  –  Edit Existing Driver
// Universiti Islam Selangor (UIS)
// ============================================================

require_once '../config/database.php';
requireAdmin();

$page_title   = 'Edit Driver';
$current_page = 'edit_driver.php';

// ── Validate ?id parameter ───────────────────────────────────
$driver_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($driver_id <= 0) {
    setFlash('danger', 'Invalid driver ID.');
    header('Location: ' . SITE_URL . '/admin/drivers.php');
    exit();
}

// ── Fetch existing driver ────────────────────────────────────
$stmt = $conn->prepare("SELECT * FROM drivers WHERE driver_id = ? LIMIT 1");
$stmt->bind_param('i', $driver_id);
$stmt->execute();
$result = $stmt->get_result();
$driver = $result->fetch_assoc();
$stmt->close();

if (!$driver) {
    setFlash('danger', 'Driver not found.');
    header('Location: ' . SITE_URL . '/admin/drivers.php');
    exit();
}

$page_title = 'Edit Driver – ' . htmlspecialchars($driver['name']);

// ── Initialise form from existing record ─────────────────────
$form = [
    'employee_id'         => $driver['employee_id'],
    'name'                => $driver['name'],
    'phone'               => $driver['phone'],
    'email'               => $driver['email']               ?? '',
    'address'             => $driver['address']             ?? '',
    'experience_years'    => $driver['experience_years'],
    'performance_score'   => $driver['performance_score'],
    'certification_score' => $driver['certification_score'],
    'license_number'      => $driver['license_number']      ?? '',
    'license_class'       => array_values(array_filter(array_map('trim', explode(',', (string)($driver['license_class'] ?? ''))), 'strlen')),
    'license_expiry'      => $driver['license_expiry']      ?? '',
    'status'              => $driver['status'],
    'driver_type'         => $driver['driver_type']         ?? 'regular',
];

$errors = [];

// ── Current-month workload & allocation score ────────────────
$monthly_counts = getMonthlyTaskCounts($conn);
$tasks_this_month = $monthly_counts[$driver_id]['tasks']   ?? 0;
$weekend_tasks    = $monthly_counts[$driver_id]['weekend'] ?? 0;

$current_priority = calculateAllocationScore(
    $tasks_this_month,
    $weekend_tasks,
    (float)$driver['experience_years']
);

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
        $errors['employee_id'] = 'Employee ID is required.';
    } else {
        // Unique check – exclude current record
        $chk = $conn->prepare("SELECT driver_id FROM drivers WHERE employee_id = ? AND driver_id != ?");
        $chk->bind_param('si', $form['employee_id'], $driver_id);
        $chk->execute();
        $chk->store_result();
        if ($chk->num_rows > 0) {
            $errors['employee_id'] = 'This Employee ID is already registered to another driver.';
        }
        $chk->close();
    }

    if ($form['name'] === '') {
        $errors['name'] = 'Full name is required.';
    }

    if ($form['phone'] === '') {
        $errors['phone'] = 'Phone number is required.';
    }

    if ($form['email'] !== '' && !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address.';
    }

    if ($form['experience_years'] === '') {
        $errors['experience_years'] = 'Experience years is required.';
    } elseif (!is_numeric($form['experience_years']) || (float)$form['experience_years'] < 0 || (float)$form['experience_years'] > 50) {
        $errors['experience_years'] = 'Experience must be between 0 and 50 years.';
    }

    if ($form['performance_score'] === '') {
        $errors['performance_score'] = 'Performance score is required.';
    } elseif (!is_numeric($form['performance_score']) || (float)$form['performance_score'] < 0 || (float)$form['performance_score'] > 10) {
        $errors['performance_score'] = 'Performance score must be between 0 and 10.';
    }

    if ($form['certification_score'] === '') {
        $errors['certification_score'] = 'Certification score is required.';
    } elseif (!is_numeric($form['certification_score']) || (float)$form['certification_score'] < 0 || (float)$form['certification_score'] > 10) {
        $errors['certification_score'] = 'Certification score must be between 0 and 10.';
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
        $errors['license_class'] = 'Please select at least one license class.';
    } elseif (array_diff($form['license_class'], $allowed_classes)) {
        $errors['license_class'] = 'Invalid license class selected.';
        $form['license_class']   = array_values(array_intersect($allowed_classes, $form['license_class']));
    } else {
        $form['license_class'] = array_values(array_intersect($allowed_classes, $form['license_class']));
    }

    // ── Update if no errors ──────────────────────────────────
    if (empty($errors)) {
        $exp   = (float)$form['experience_years'];
        $perf  = (float)$form['performance_score'];
        $cert  = (float)$form['certification_score'];
        $license_class_csv = implode(',', $form['license_class']);

        $expiry = $form['license_expiry'] !== '' ? $form['license_expiry'] : null;

        $stmt = $conn->prepare(
            "UPDATE drivers SET
                employee_id = ?, name = ?, phone = ?, email = ?, address = ?,
                experience_years = ?, performance_score = ?,
                certification_score = ?, license_number = ?, license_class = ?,
                license_expiry = ?, status = ?, driver_type = ?, updated_at = NOW()
             WHERE driver_id = ?"
        );

        $stmt->bind_param(
            'sssssdddsssssi',
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
            $driver_id
        );

        if ($stmt->execute()) {
            $stmt->close();
            setFlash('success', 'Driver <strong>' . htmlspecialchars($form['name']) . '</strong> has been updated successfully.');
            header('Location: ' . SITE_URL . '/admin/drivers.php');
            exit();
        } else {
            $stmt->close();
            $errors['db'] = 'A database error occurred. Please try again.';
        }
    }

    // Recalculate preview after POST (for when re-displaying the form on error)
    $current_priority = calculateAllocationScore(
        $tasks_this_month,
        $weekend_tasks,
        (float)($form['experience_years'] ?: 0)
    );
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

        .score-high   { color: #065f46; }
        .score-medium { color: #92400e; }
        .score-low    { color: #991b1b; }

        .required-star { color: #dc3545; }

        /* Current score badge */
        .current-score-badge {
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            padding: .4rem .9rem;
            border-radius: 20px;
            font-weight: 700;
            font-size: .9rem;
        }
        .driver-meta-pill {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            background: rgba(255,255,255,.15);
            border-radius: 20px;
            padding: .3rem .8rem;
            font-size: .8rem;
        }
    </style>
</head>
<body>

<?php include '../includes/sidebar.php'; ?>

<main class="main-content p-4">

    <!-- Page header -->
    <?php
        $headerScoreClass = 'bg-danger bg-opacity-25 text-white';
        if ($current_priority >= 7)     $headerScoreClass = 'bg-success bg-opacity-25 text-white';
        elseif ($current_priority >= 4) $headerScoreClass = 'bg-warning  bg-opacity-25 text-dark';
    ?>
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <h1><i class="fas fa-pen-to-square me-2" aria-hidden="true"></i>Edit Driver</h1>
                <p>
                    Updating record for
                    <strong><?php echo htmlspecialchars($driver['name']); ?></strong>
                    &nbsp;
                    <span class="driver-meta-pill">
                        <i class="fas fa-id-badge fa-xs"></i>
                        <?php echo htmlspecialchars($driver['employee_id']); ?>
                    </span>
                </p>
            </div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <div>
                    <div style="font-size:.7rem;opacity:.7;text-align:center;margin-bottom:.2rem;">Current Score</div>
                    <?php
                        $scoreClsEdit = 'priority-low';
                        if ($current_priority >= 7)     $scoreClsEdit = 'priority-high';
                        elseif ($current_priority >= 4) $scoreClsEdit = 'priority-medium';
                    ?>
                    <span class="current-score-badge <?php echo $headerScoreClass; ?>">
                        <i class="fas fa-bolt"></i>
                        <?php echo number_format($current_priority, 2); ?> / 10
                    </span>
                </div>
                <a href="<?php echo SITE_URL; ?>/admin/drivers.php" class="btn btn-light fw-semibold">
                    <i class="fas fa-arrow-left me-1" aria-hidden="true"></i> Back
                </a>
            </div>
        </div>
    </div>

    <!-- Flash / validation errors -->
    <?php if (!empty($errors)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-circle-exclamation me-2" aria-hidden="true"></i>
        <strong>Please fix the following errors:</strong>
        <ul class="mb-0 mt-1">
            <?php foreach ($errors as $err): ?>
                <li><?php echo htmlspecialchars($err); ?></li>
            <?php endforeach; ?>
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>

    <form method="POST"
          action="edit_driver.php?id=<?php echo (int)$driver_id; ?>"
          id="editDriverForm"
          novalidate>

        <div class="row g-4">

            <!-- ── Left: Form fields ─────────────────────────── -->
            <div class="col-lg-8">

                <!-- Personal Information -->
                <div class="card form-card mb-4">
                    <div class="card-body p-4">
                        <div class="section-title">
                            <i class="fas fa-user me-1"></i> Personal Information
                        </div>
                        <div class="row g-3">

                            <div class="col-md-6">
                                <label for="employee_id" class="form-label">
                                    Employee ID <span class="required-star">*</span>
                                </label>
                                <input type="text"
                                       id="employee_id"
                                       name="employee_id"
                                       class="form-control <?php echo isset($errors['employee_id']) ? 'is-invalid' : ''; ?>"
                                       value="<?php echo htmlspecialchars($form['employee_id']); ?>"
                                       placeholder="e.g. EMP-0001"
                                       maxlength="50"
                                       required>
                                <?php if (isset($errors['employee_id'])): ?>
                                    <div class="invalid-feedback"><?php echo htmlspecialchars($errors['employee_id']); ?></div>
                                <?php endif; ?>
                            </div>

                            <div class="col-md-6">
                                <label for="name" class="form-label">
                                    Full Name <span class="required-star">*</span>
                                </label>
                                <input type="text"
                                       id="name"
                                       name="name"
                                       class="form-control <?php echo isset($errors['name']) ? 'is-invalid' : ''; ?>"
                                       value="<?php echo htmlspecialchars($form['name']); ?>"
                                       placeholder="e.g. Ahmad bin Ali"
                                       maxlength="150"
                                       required>
                                <?php if (isset($errors['name'])): ?>
                                    <div class="invalid-feedback"><?php echo htmlspecialchars($errors['name']); ?></div>
                                <?php endif; ?>
                            </div>

                            <div class="col-md-6">
                                <label for="phone" class="form-label">
                                    Phone Number <span class="required-star">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-phone"></i></span>
                                    <input type="text"
                                           id="phone"
                                           name="phone"
                                           class="form-control <?php echo isset($errors['phone']) ? 'is-invalid' : ''; ?>"
                                           value="<?php echo htmlspecialchars($form['phone']); ?>"
                                           placeholder="e.g. 0123456789"
                                           maxlength="20"
                                           required>
                                    <?php if (isset($errors['phone'])): ?>
                                        <div class="invalid-feedback"><?php echo htmlspecialchars($errors['phone']); ?></div>
                                    <?php endif; ?>
                                </div>
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
                                           maxlength="150">
                                    <?php if (isset($errors['email'])): ?>
                                        <div class="invalid-feedback"><?php echo htmlspecialchars($errors['email']); ?></div>
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
                                <select id="driver_type" name="driver_type" class="form-select">
                                    <option value="regular"        <?php echo $form['driver_type'] === 'regular'        ? 'selected' : ''; ?>>Regular</option>
                                    <option value="top_management" <?php echo $form['driver_type'] === 'top_management' ? 'selected' : ''; ?>>Top Management</option>
                                </select>
                                <div class="form-text">Top Management drivers handle VIP and executive trips only.</div>
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
                                    Experience Years <span class="required-star">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-calendar-days"></i></span>
                                    <input type="number"
                                           id="experience_years"
                                           name="experience_years"
                                           class="form-control <?php echo isset($errors['experience_years']) ? 'is-invalid' : ''; ?>"
                                           value="<?php echo htmlspecialchars($form['experience_years']); ?>"
                                           min="0" max="50" step="0.5"
                                           required>
                                    <span class="input-group-text">yrs</span>
                                    <?php if (isset($errors['experience_years'])): ?>
                                        <div class="invalid-feedback"><?php echo htmlspecialchars($errors['experience_years']); ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="form-text">Capped at 20 yrs for workload-balancing score.</div>
                            </div>

                            <div class="col-md-6">
                                <label for="performance_score" class="form-label">
                                    Performance Score <span class="required-star">*</span>
                                    <span class="fw-normal text-muted">(0–10 scale)</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-star"></i></span>
                                    <input type="number"
                                           id="performance_score"
                                           name="performance_score"
                                           class="form-control <?php echo isset($errors['performance_score']) ? 'is-invalid' : ''; ?>"
                                           value="<?php echo htmlspecialchars($form['performance_score']); ?>"
                                           min="0" max="10" step="0.1"
                                           required>
                                    <span class="input-group-text">/ 10</span>
                                    <?php if (isset($errors['performance_score'])): ?>
                                        <div class="invalid-feedback"><?php echo htmlspecialchars($errors['performance_score']); ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="form-text">Overall driving performance rating (informational only).</div>
                            </div>

                            <div class="col-md-6">
                                <label for="certification_score" class="form-label">
                                    Certification Score <span class="required-star">*</span>
                                    <span class="fw-normal text-muted">(0–10 scale)</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-certificate"></i></span>
                                    <input type="number"
                                           id="certification_score"
                                           name="certification_score"
                                           class="form-control <?php echo isset($errors['certification_score']) ? 'is-invalid' : ''; ?>"
                                           value="<?php echo htmlspecialchars($form['certification_score']); ?>"
                                           min="0" max="10" step="0.1"
                                           required>
                                    <span class="input-group-text">/ 10</span>
                                    <?php if (isset($errors['certification_score'])): ?>
                                        <div class="invalid-feedback"><?php echo htmlspecialchars($errors['certification_score']); ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="form-text">Certification/training score (informational only).</div>
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
                                       maxlength="30">
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">License Class <span class="required-star">*</span></label>
                                <div id="license_class_group" class="<?php echo isset($errors['license_class']) ? 'is-invalid' : ''; ?>">
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
                                    <div class="invalid-feedback d-block"><?php echo htmlspecialchars($errors['license_class']); ?></div>
                                <?php endif; ?>
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

                <!-- Record metadata -->
                <div class="card form-card mb-4">
                    <div class="card-body p-3 px-4">
                        <div class="row g-2 text-muted" style="font-size:.8rem;">
                            <div class="col-md-6">
                                <i class="fas fa-plus-circle me-1"></i>
                                <strong>Created:</strong>
                                <?php echo formatDate($driver['created_at']); ?>
                            </div>
                            <div class="col-md-6">
                                <i class="fas fa-pen-to-square me-1"></i>
                                <strong>Last Updated:</strong>
                                <?php echo formatDate($driver['updated_at']); ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Action buttons -->
                <div class="d-flex gap-2 justify-content-end">
                    <a href="<?php echo SITE_URL; ?>/admin/drivers.php" class="btn btn-outline-secondary px-4">
                        <i class="fas fa-xmark me-1" aria-hidden="true"></i> Cancel
                    </a>
                    <button type="submit" class="btn btn-primary px-5 fw-semibold">
                        <i class="fas fa-floppy-disk me-1" aria-hidden="true"></i> Update Driver
                    </button>
                </div>

            </div><!-- /left col -->

            <!-- ── Right: Allocation Score ───────────────────── -->
            <div class="col-lg-4">
                <div class="priority-preview">
                    <div class="mb-3 d-flex align-items-center gap-2">
                        <div style="width:36px;height:36px;border-radius:10px;background:#0b5d3b;
                                    display:flex;align-items:center;justify-content:center;color:#fff;">
                            <i class="fas fa-scale-balanced" aria-hidden="true"></i>
                        </div>
                        <div>
                            <div class="fw-bold text-primary" style="font-size:.95rem;">Allocation Score</div>
                            <div class="text-muted" style="font-size:.75rem;">Workload balancing &middot; <?php echo date('F Y'); ?></div>
                        </div>
                    </div>

                    <div class="text-center mb-3">
                        <div class="score-display <?php
                            echo $current_priority >= 7 ? 'score-high' : ($current_priority >= 4 ? 'score-medium' : 'score-low');
                        ?>" id="previewScore">
                            <?php echo number_format($current_priority, 2); ?>
                        </div>
                        <div class="text-muted" style="font-size:.78rem;">out of 10.00</div>
                    </div>

                    <?php
                        $barPct   = min($current_priority * 10, 100);
                        $barColor = $current_priority >= 7 ? '#10b981' : ($current_priority >= 4 ? '#f59e0b' : '#ef4444');

                        // Per-factor contribution (points out of 10) – mirrors calculateAllocationScore()
                        $expYears      = (float)($form['experience_years'] ?: 0);
                        $taskContrib   = (1.0 - min($tasks_this_month / 10.0, 1.0)) * 10 * 0.50;
                        $wkndContrib   = (1.0 - min($weekend_tasks / 4.0, 1.0)) * 10 * 0.30;
                        $expContrib    = min($expYears / 20.0, 1.0) * 10 * 0.20;
                    ?>
                    <div class="score-bar mb-3">
                        <div class="score-bar-fill" id="previewBar"
                             style="width:<?php echo $barPct; ?>%;background:<?php echo $barColor; ?>;"></div>
                    </div>

                    <div class="mb-3">
                        <div class="component-row">
                            <span class="component-label"><i class="fas fa-list-check fa-xs me-1"></i> Tasks this month (50%)</span>
                            <span class="component-value">
                                <?php echo (int)$tasks_this_month; ?> task<?php echo $tasks_this_month === 1 ? '' : 's'; ?>
                                = <?php echo number_format($taskContrib, 2); ?>
                            </span>
                        </div>
                        <div class="component-row">
                            <span class="component-label"><i class="fas fa-calendar-week fa-xs me-1"></i> Weekend tasks (30%)</span>
                            <span class="component-value">
                                <?php echo (int)$weekend_tasks; ?> task<?php echo $weekend_tasks === 1 ? '' : 's'; ?>
                                = <?php echo number_format($wkndContrib, 2); ?>
                            </span>
                        </div>
                        <div class="component-row">
                            <span class="component-label"><i class="fas fa-calendar-days fa-xs me-1"></i> Experience (20%)</span>
                            <span class="component-value" id="prevExp"
                                  data-task="<?php echo round($taskContrib, 4); ?>"
                                  data-wknd="<?php echo round($wkndContrib, 4); ?>">
                                <?php echo number_format($expYears, 1); ?> yrs
                                = <?php echo number_format($expContrib, 2); ?>
                            </span>
                        </div>
                    </div>

                    <div class="p-2 rounded-2" style="background:rgba(11,93,59,.06);font-size:.72rem;color:#6b7280;line-height:1.6;">
                        Fewer tasks and fewer weekend tasks this month, and more experience, give a higher score.
                        Task and weekend counts are read-only and come from this month's schedules.
                    </div>

                    <div class="mt-3 pt-2 border-top">
                        <div class="d-flex gap-2 flex-wrap" style="font-size:.72rem;">
                            <span class="badge" style="background:#d1fae5;color:#065f46;">7.0–10.0 High</span>
                            <span class="badge" style="background:#fef3c7;color:#92400e;">4.0–6.9 Medium</span>
                            <span class="badge" style="background:#fee2e2;color:#991b1b;">0.0–3.9 Low</span>
                        </div>
                    </div>
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

    // Experience is the only editable factor in the allocation score;
    // task / weekend contributions are fixed for the current month.
    var expInput     = document.getElementById('experience_years');
    var previewScore = document.getElementById('previewScore');
    var previewBar   = document.getElementById('previewBar');
    var prevExp      = document.getElementById('prevExp');

    function updatePreview() {
        if (!expInput || !prevExp) { return; }
        var exp         = parseFloat(expInput.value) || 0;
        var taskContrib = parseFloat(prevExp.getAttribute('data-task')) || 0;
        var wkndContrib = parseFloat(prevExp.getAttribute('data-wknd')) || 0;
        var expContrib  = Math.min(exp / 20.0, 1.0) * 10 * 0.20;

        var total = Math.round((taskContrib + wkndContrib + expContrib) * 100) / 100;

        var colorClass, barColor;
        if (total >= 7)      { colorClass = 'score-high';   barColor = '#10b981'; }
        else if (total >= 4) { colorClass = 'score-medium'; barColor = '#f59e0b'; }
        else                 { colorClass = 'score-low';    barColor = '#ef4444'; }

        previewScore.textContent    = total.toFixed(2);
        previewScore.className      = 'score-display ' + colorClass;
        previewBar.style.width      = Math.min(total * 10, 100) + '%';
        previewBar.style.background = barColor;
        prevExp.textContent         = exp.toFixed(1) + ' yrs = ' + expContrib.toFixed(2);
    }

    if (expInput) {
        expInput.addEventListener('input', updatePreview);
        expInput.addEventListener('change', updatePreview);
    }

    // ── Client-side validation ───────────────────────────────
    var form = document.getElementById('editDriverForm');
    if (form) {
        form.addEventListener('submit', function (e) {
            var valid = true;

            ['employee_id', 'name', 'phone', 'experience_years', 'performance_score', 'certification_score'].forEach(function (fieldId) {
                var el = document.getElementById(fieldId);
                if (el && el.value.trim() === '') {
                    el.classList.add('is-invalid');
                    valid = false;
                } else if (el) {
                    el.classList.remove('is-invalid');
                }
            });

            // At least one license class
            var lcGroup = document.getElementById('license_class_group');
            if (lcGroup) {
                if (!form.querySelector('input[name="license_class[]"]:checked')) {
                    lcGroup.classList.add('is-invalid');
                    valid = false;
                } else {
                    lcGroup.classList.remove('is-invalid');
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
