<?php
// ============================================================
// UIS Driver Scheduling and Management System
// admin/add_driver.php  –  Add New Driver
// Universiti Islam Selangor (UIS)
// ============================================================

require_once '../config/database.php';
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
    'attendance_rate'     => '',
    'performance_score'   => '',
    'certification_score' => '',
    'license_number'      => '',
    'license_class'       => '',
    'license_expiry'      => '',
    'status'              => 'active',
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
    $form['attendance_rate']     = trim($_POST['attendance_rate']     ?? '');
    $form['performance_score']   = trim($_POST['performance_score']   ?? '');
    $form['certification_score'] = trim($_POST['certification_score'] ?? '');
    $form['license_number']      = trim($_POST['license_number']      ?? '');
    $form['license_class']       = trim($_POST['license_class']       ?? '');
    $form['license_expiry']      = trim($_POST['license_expiry']      ?? '');
    $form['status']              = trim($_POST['status']              ?? 'active');

    // ── Validation ───────────────────────────────────────────
    if ($form['employee_id'] === '') {
        $errors['employee_id'] = 'Employee ID is required.';
    } else {
        // Unique check
        $chk = $conn->prepare("SELECT driver_id FROM drivers WHERE employee_id = ?");
        $chk->bind_param('s', $form['employee_id']);
        $chk->execute();
        $chk->store_result();
        if ($chk->num_rows > 0) {
            $errors['employee_id'] = 'This Employee ID is already registered.';
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

    if ($form['attendance_rate'] === '') {
        $errors['attendance_rate'] = 'Attendance rate is required.';
    } elseif (!is_numeric($form['attendance_rate']) || (float)$form['attendance_rate'] < 0 || (float)$form['attendance_rate'] > 100) {
        $errors['attendance_rate'] = 'Attendance rate must be between 0 and 100.';
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

    $allowed_classes = ['D', 'DA', 'GDL', 'PSV', 'E', ''];
    if (!in_array($form['license_class'], $allowed_classes, true)) {
        $form['license_class'] = '';
    }

    // ── Insert if no errors ──────────────────────────────────
    if (empty($errors)) {
        $exp   = (float)$form['experience_years'];
        $att   = (float)$form['attendance_rate'];
        $perf  = (float)$form['performance_score'];
        $cert  = (float)$form['certification_score'];

        $stmt = $conn->prepare(
            "INSERT INTO drivers
                (employee_id, name, phone, email, address,
                 experience_years, attendance_rate, performance_score, certification_score,
                 license_number, license_class, license_expiry, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())"
        );

        $expiry = $form['license_expiry'] !== '' ? $form['license_expiry'] : null;

        $stmt->bind_param(
            'sssssddddssss',
            $form['employee_id'],
            $form['name'],
            $form['phone'],
            $form['email'],
            $form['address'],
            $exp, $att, $perf, $cert,
            $form['license_number'],
            $form['license_class'],
            $expiry,
            $form['status']
        );

        if ($stmt->execute()) {
            $stmt->close();
            setFlash('success', 'Driver <strong>' . htmlspecialchars($form['name']) . '</strong> has been added successfully.');
            header('Location: ' . SITE_URL . '/admin/drivers.php');
            exit();
        } else {
            $stmt->close();
            $errors['db'] = 'A database error occurred. Please try again.';
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
            background: linear-gradient(135deg, #003580 0%, #0056b3 100%);
            border-radius: 14px;
            color: #fff;
            padding: 1.4rem 2rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 16px rgba(0,53,128,.20);
        }
        .page-header h1 { font-size: 1.45rem; font-weight: 700; margin: 0; }
        .page-header p  { margin: .25rem 0 0; opacity: .8; font-size: .86rem; }

        .form-card {
            border: none;
            border-radius: 14px;
            box-shadow: 0 2px 12px rgba(0,53,128,.10);
        }
        .section-title {
            font-size: .78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: #003580;
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
            border-color: #0056b3;
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
            background: #f0f5ff;
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
            border-bottom: 1px solid rgba(0,53,128,.06);
        }
        .component-row:last-child { border-bottom: none; }
        .component-label { color: #6b7280; }
        .component-value { font-weight: 600; color: #1a2035; }

        /* Score badge colors */
        .score-high   { color: #065f46; }
        .score-medium { color: #92400e; }
        .score-low    { color: #991b1b; }

        .required-star { color: #dc3545; }
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

    <form method="POST" action="add_driver.php" id="addDriverForm" novalidate>

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
                                           placeholder="0.0"
                                           min="0" max="50" step="0.5"
                                           required>
                                    <span class="input-group-text">yrs</span>
                                    <?php if (isset($errors['experience_years'])): ?>
                                        <div class="invalid-feedback"><?php echo htmlspecialchars($errors['experience_years']); ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="form-text">Years of driving experience (0–50). Capped at 20 yrs for scoring.</div>
                            </div>

                            <div class="col-md-6">
                                <label for="attendance_rate" class="form-label">
                                    Attendance Rate <span class="required-star">*</span>
                                </label>
                                <div class="input-group">
                                    <input type="number"
                                           id="attendance_rate"
                                           name="attendance_rate"
                                           class="form-control <?php echo isset($errors['attendance_rate']) ? 'is-invalid' : ''; ?>"
                                           value="<?php echo htmlspecialchars($form['attendance_rate']); ?>"
                                           placeholder="0.0"
                                           min="0" max="100" step="0.5"
                                           required>
                                    <span class="input-group-text">%</span>
                                    <?php if (isset($errors['attendance_rate'])): ?>
                                        <div class="invalid-feedback"><?php echo htmlspecialchars($errors['attendance_rate']); ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="form-text">Percentage attendance (0–100%). Weighted at 20%.</div>
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
                                           placeholder="0.0"
                                           min="0" max="10" step="0.1"
                                           required>
                                    <span class="input-group-text">/ 10</span>
                                    <?php if (isset($errors['performance_score'])): ?>
                                        <div class="invalid-feedback"><?php echo htmlspecialchars($errors['performance_score']); ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="form-text">Overall driving performance rating. Weighted at 30%.</div>
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
                                           placeholder="0.0"
                                           min="0" max="10" step="0.1"
                                           required>
                                    <span class="input-group-text">/ 10</span>
                                    <?php if (isset($errors['certification_score'])): ?>
                                        <div class="invalid-feedback"><?php echo htmlspecialchars($errors['certification_score']); ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="form-text">Certification/training score. Weighted at 20%.</div>
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
                                <label for="license_class" class="form-label">License Class</label>
                                <select id="license_class" name="license_class" class="form-select">
                                    <option value=""  <?php echo $form['license_class'] === ''    ? 'selected' : ''; ?>>-- Select --</option>
                                    <option value="D"   <?php echo $form['license_class'] === 'D'   ? 'selected' : ''; ?>>D (Car)</option>
                                    <option value="DA"  <?php echo $form['license_class'] === 'DA'  ? 'selected' : ''; ?>>DA (Auto Car)</option>
                                    <option value="GDL" <?php echo $form['license_class'] === 'GDL' ? 'selected' : ''; ?>>GDL (Goods Vehicle)</option>
                                    <option value="PSV" <?php echo $form['license_class'] === 'PSV' ? 'selected' : ''; ?>>PSV (Public Service)</option>
                                    <option value="E"   <?php echo $form['license_class'] === 'E'   ? 'selected' : ''; ?>>E (Motorcycle)</option>
                                </select>
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
                    <a href="<?php echo SITE_URL; ?>/admin/drivers.php" class="btn btn-outline-secondary px-4">
                        <i class="fas fa-xmark me-1" aria-hidden="true"></i> Cancel
                    </a>
                    <button type="submit" class="btn btn-primary px-5 fw-semibold">
                        <i class="fas fa-user-plus me-1" aria-hidden="true"></i> Add Driver
                    </button>
                </div>

            </div><!-- /left col -->

            <!-- ── Right: Priority Score Preview ─────────────── -->
            <div class="col-lg-4">
                <div class="priority-preview">
                    <div class="mb-3 d-flex align-items-center gap-2">
                        <div style="width:36px;height:36px;border-radius:10px;background:#003580;
                                    display:flex;align-items:center;justify-content:center;color:#fff;">
                            <i class="fas fa-bolt" aria-hidden="true"></i>
                        </div>
                        <div>
                            <div class="fw-bold text-primary" style="font-size:.95rem;">Priority Score Preview</div>
                            <div class="text-muted" style="font-size:.75rem;">Updates as you type</div>
                        </div>
                    </div>

                    <div class="text-center mb-3">
                        <div class="score-display" id="previewScore" style="color:#6b7280;">
                            &mdash;
                        </div>
                        <div class="text-muted" style="font-size:.78rem;">out of 10.00</div>
                    </div>

                    <div class="score-bar mb-3">
                        <div class="score-bar-fill" id="previewBar" style="width:0%;background:#9ca3af;"></div>
                    </div>

                    <div class="mb-3">
                        <div class="component-row">
                            <span class="component-label"><i class="fas fa-calendar-days fa-xs me-1"></i> Experience (30%)</span>
                            <span class="component-value" id="prevExp">—</span>
                        </div>
                        <div class="component-row">
                            <span class="component-label"><i class="fas fa-clock fa-xs me-1"></i> Attendance (20%)</span>
                            <span class="component-value" id="prevAtt">—</span>
                        </div>
                        <div class="component-row">
                            <span class="component-label"><i class="fas fa-star fa-xs me-1"></i> Performance (30%)</span>
                            <span class="component-value" id="prevPerf">—</span>
                        </div>
                        <div class="component-row">
                            <span class="component-label"><i class="fas fa-certificate fa-xs me-1"></i> Certification (20%)</span>
                            <span class="component-value" id="prevCert">—</span>
                        </div>
                    </div>

                    <div class="p-2 rounded-2" style="background:rgba(0,53,128,.06);font-size:.72rem;color:#6b7280;line-height:1.6;">
                        <strong>Formula:</strong><br>
                        (Exp/20 &times; 10 &times; 0.30)<br>
                        + (Att/100 &times; 10 &times; 0.20)<br>
                        + (Perf &times; 0.30)<br>
                        + (Cert &times; 0.20)
                    </div>

                    <!-- Score category guide -->
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

    var expInput  = document.getElementById('experience_years');
    var attInput  = document.getElementById('attendance_rate');
    var perfInput = document.getElementById('performance_score');
    var certInput = document.getElementById('certification_score');

    var previewScore = document.getElementById('previewScore');
    var previewBar   = document.getElementById('previewBar');
    var prevExp  = document.getElementById('prevExp');
    var prevAtt  = document.getElementById('prevAtt');
    var prevPerf = document.getElementById('prevPerf');
    var prevCert = document.getElementById('prevCert');

    function updatePreview() {
        var exp  = parseFloat(expInput.value)  || 0;
        var att  = parseFloat(attInput.value)  || 0;
        var perf = parseFloat(perfInput.value) || 0;
        var cert = parseFloat(certInput.value) || 0;

        var hasAny = expInput.value !== '' || attInput.value !== '' || perfInput.value !== '' || certInput.value !== '';
        if (!hasAny) {
            previewScore.innerHTML = '&mdash;';
            previewScore.className = 'score-display';
            previewBar.style.width = '0%';
            previewBar.style.background = '#9ca3af';
            prevExp.textContent = '—';
            prevAtt.textContent = '—';
            prevPerf.textContent = '—';
            prevCert.textContent = '—';
            return;
        }

        // Normalise
        var expScore  = Math.min((exp / 20.0) * 10.0, 10.0);
        var attScore  = (att / 100.0) * 10.0;
        var perfScore = perf;
        var certScore = cert;

        // Weighted total
        var total = (expScore * 0.30) + (attScore * 0.20) + (perfScore * 0.30) + (certScore * 0.20);
        total = Math.round(total * 100) / 100;

        // Display
        previewScore.textContent = total.toFixed(2);

        var colorClass, barColor;
        if (total >= 7) {
            colorClass = 'score-high';
            barColor   = '#10b981';
        } else if (total >= 4) {
            colorClass = 'score-medium';
            barColor   = '#f59e0b';
        } else {
            colorClass = 'score-low';
            barColor   = '#ef4444';
        }

        previewScore.className    = 'score-display ' + colorClass;
        previewBar.style.width    = Math.min(total * 10, 100) + '%';
        previewBar.style.background = barColor;

        // Component values (showing normalised × weight contribution)
        prevExp.textContent  = expScore.toFixed(2)  + ' × 0.30 = ' + (expScore  * 0.30).toFixed(2);
        prevAtt.textContent  = attScore.toFixed(2)  + ' × 0.20 = ' + (attScore  * 0.20).toFixed(2);
        prevPerf.textContent = perfScore.toFixed(2) + ' × 0.30 = ' + (perfScore * 0.30).toFixed(2);
        prevCert.textContent = certScore.toFixed(2) + ' × 0.20 = ' + (certScore * 0.20).toFixed(2);
    }

    [expInput, attInput, perfInput, certInput].forEach(function (el) {
        if (el) {
            el.addEventListener('input', updatePreview);
            el.addEventListener('change', updatePreview);
        }
    });

    // Initial run if form is repopulated after error
    updatePreview();

    // ── Client-side validation ───────────────────────────────
    var form = document.getElementById('addDriverForm');
    if (form) {
        form.addEventListener('submit', function (e) {
            var valid = true;

            // Required fields
            ['employee_id', 'name', 'phone', 'experience_years', 'attendance_rate', 'performance_score', 'certification_score'].forEach(function (fieldId) {
                var el = document.getElementById(fieldId);
                if (el && el.value.trim() === '') {
                    el.classList.add('is-invalid');
                    valid = false;
                } else if (el) {
                    el.classList.remove('is-invalid');
                }
            });

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
