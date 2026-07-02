<?php
$page_title   = 'Submit Leave Request';
$current_page = 'leave_request.php';
require_once '../config/database.php';
requireDriver();

$driver_id = (int)$_SESSION['driver_id'];
$user_id   = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'] ?? '';

$errors = [];
$form   = ['leave_type' => '', 'start_date' => '', 'end_date' => '', 'reason' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $leave_type = trim($_POST['leave_type'] ?? '');
    $start_date = trim($_POST['start_date'] ?? '');
    $end_date   = trim($_POST['end_date']   ?? '');
    $reason     = trim($_POST['reason']     ?? '');

    $form = compact('leave_type', 'start_date', 'end_date', 'reason');

    $allowed_types = ['emergency', 'medical', 'annual', 'personal'];
    if (!in_array($leave_type, $allowed_types, true)) {
        $errors[] = 'Please select a valid leave type.';
    }
    if (empty($start_date) || !strtotime($start_date)) {
        $errors[] = 'Start date is required and must be a valid date.';
    }
    if (empty($end_date) || !strtotime($end_date)) {
        $errors[] = 'End date is required and must be a valid date.';
    }
    if (!empty($start_date) && !empty($end_date) && $end_date < $start_date) {
        $errors[] = 'End date must be on or after the start date.';
    }
    if (strlen($reason) < 20) {
        $errors[] = 'Reason must be at least 20 characters long.';
    }

    if (empty($errors)) {
        $days = (int)(new DateTime($start_date))->diff(new DateTime($end_date))->days + 1;

        $stmt = $conn->prepare(
            "INSERT INTO leave_requests (driver_id, leave_type, start_date, end_date, reason)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('issss', $driver_id, $leave_type, $start_date, $end_date, $reason);

        if ($stmt->execute()) {
            $stmt->close();
            setFlash('success', 'Your leave request has been submitted successfully. Please await admin review.');
            header('Location: my_leaves.php');
            exit();
        } else {
            $errors[] = 'A database error occurred. Please try again.';
            $stmt->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submit Leave Request | UIS Driver Management</title>
    <!-- Bootstrap 5.3 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome 6.4 -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- Google Fonts – Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <!-- Custom CSS -->
    <link href="<?= SITE_URL ?>/assets/css/style.css" rel="stylesheet">
</head>
<body>
<?php require_once '../includes/sidebar.php'; ?>

<main class="main-content p-4">
    <?php showFlash(); ?>

    <!-- Page Header -->
    <div style="background: linear-gradient(135deg, #003580 0%, #0056b3 100%); border-radius: 14px; color: #fff; padding: 1.4rem 2rem; margin-bottom: 1.5rem;">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h4 class="fw-bold mb-1">
                    <i class="fas fa-calendar-plus me-2"></i>Submit Leave Request
                </h4>
                <p class="mb-0 opacity-75">Request time off from your driving duties</p>
            </div>
            <a href="my_leaves.php" class="btn btn-light btn-sm fw-semibold">
                <i class="fas fa-clock-rotate-left me-1"></i>My Leave History
            </a>
        </div>
    </div>

    <!-- Validation Errors -->
    <?php if (!empty($errors)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-triangle-exclamation me-2"></i>
        <strong>Please fix the following errors:</strong>
        <ul class="mb-0 mt-2 ps-3">
            <?php foreach ($errors as $e): ?>
            <li><?= htmlspecialchars($e) ?></li>
            <?php endforeach; ?>
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>

    <div class="row g-4">

        <!-- ── Request Form ────────────────────────────────────────── -->
        <div class="col-lg-8">
            <div class="card" style="border: none; border-radius: 14px; box-shadow: 0 2px 12px rgba(0,53,128,.10);">
                <div class="card-header bg-white border-bottom px-4 py-3" style="border-radius: 14px 14px 0 0;">
                    <h6 class="mb-0 fw-semibold">
                        <i class="fas fa-file-pen text-primary me-2"></i>Request Details
                    </h6>
                </div>
                <div class="card-body px-4 py-4">
                    <form method="POST" action="" novalidate>

                        <!-- Leave Type -->
                        <div class="mb-4">
                            <label class="form-label fw-semibold" for="leave_type">
                                Leave Type <span class="text-danger">*</span>
                            </label>
                            <select class="form-select" id="leave_type" name="leave_type" required>
                                <option value="" disabled <?= $form['leave_type'] === '' ? 'selected' : '' ?>>
                                    Select leave type...
                                </option>
                                <option value="emergency" <?= $form['leave_type'] === 'emergency' ? 'selected' : '' ?>>
                                    Emergency Leave
                                </option>
                                <option value="medical" <?= $form['leave_type'] === 'medical' ? 'selected' : '' ?>>
                                    Medical Leave
                                </option>
                                <option value="annual" <?= $form['leave_type'] === 'annual' ? 'selected' : '' ?>>
                                    Annual Leave
                                </option>
                                <option value="personal" <?= $form['leave_type'] === 'personal' ? 'selected' : '' ?>>
                                    Personal Leave
                                </option>
                            </select>
                        </div>

                        <!-- Date Range -->
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold" for="start_date">
                                    Start Date <span class="text-danger">*</span>
                                </label>
                                <input type="date" class="form-control" id="start_date" name="start_date"
                                       value="<?= htmlspecialchars($form['start_date']) ?>"
                                       min="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold" for="end_date">
                                    End Date <span class="text-danger">*</span>
                                </label>
                                <input type="date" class="form-control" id="end_date" name="end_date"
                                       value="<?= htmlspecialchars($form['end_date']) ?>"
                                       min="<?= date('Y-m-d') ?>" required>
                                <div class="form-text mt-1" id="dayCount"></div>
                            </div>
                        </div>

                        <!-- Reason -->
                        <div class="mb-4">
                            <label class="form-label fw-semibold" for="reason">
                                Reason <span class="text-danger">*</span>
                            </label>
                            <textarea class="form-control" id="reason" name="reason" rows="5"
                                      minlength="20" required
                                      placeholder="Describe your reason for leave (minimum 20 characters)..."><?= htmlspecialchars($form['reason']) ?></textarea>
                            <div class="form-text mt-1">
                                <span id="charCount" class="fw-semibold">0</span>
                                <span class="text-muted"> characters (minimum 20 required)</span>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="d-flex gap-2 flex-wrap">
                            <button type="submit" class="btn btn-primary px-4 fw-semibold">
                                <i class="fas fa-paper-plane me-2"></i>Submit Request
                            </button>
                            <a href="my_leaves.php" class="btn btn-outline-secondary px-4">
                                <i class="fas fa-xmark me-1"></i>Cancel
                            </a>
                        </div>

                    </form>
                </div>
            </div>
        </div>

        <!-- ── Leave Types Info Box ────────────────────────────────── -->
        <div class="col-lg-4">
            <div class="card" style="border: none; border-radius: 14px; box-shadow: 0 2px 12px rgba(0,53,128,.10);">
                <div class="card-header bg-white border-bottom px-4 py-3" style="border-radius: 14px 14px 0 0;">
                    <h6 class="mb-0 fw-semibold">
                        <i class="fas fa-circle-info text-info me-2"></i>Leave Types Guide
                    </h6>
                </div>
                <div class="card-body px-4 py-3">

                    <!-- Emergency -->
                    <div class="d-flex gap-3 mb-3 pb-3 border-bottom">
                        <div class="flex-shrink-0">
                            <span class="badge bg-danger rounded-circle p-2"
                                  style="width:36px;height:36px;display:flex;align-items:center;justify-content:center;">
                                <i class="fas fa-bolt fa-sm"></i>
                            </span>
                        </div>
                        <div>
                            <div class="fw-semibold small mb-1">Emergency Leave</div>
                            <div class="text-muted" style="font-size:0.82rem;">
                                Sudden inability to attend an assigned trip (last-minute notice).
                                Use when an unforeseen circumstance prevents you from fulfilling a duty.
                            </div>
                        </div>
                    </div>

                    <!-- Medical -->
                    <div class="d-flex gap-3 mb-3 pb-3 border-bottom">
                        <div class="flex-shrink-0">
                            <span class="badge bg-info rounded-circle p-2"
                                  style="width:36px;height:36px;display:flex;align-items:center;justify-content:center;">
                                <i class="fas fa-stethoscope fa-sm"></i>
                            </span>
                        </div>
                        <div>
                            <div class="fw-semibold small mb-1">Medical Leave</div>
                            <div class="text-muted" style="font-size:0.82rem;">
                                Doctor-advised rest period (MC required). A valid Medical Certificate
                                must be submitted to HR upon return to duty.
                            </div>
                        </div>
                    </div>

                    <!-- Annual -->
                    <div class="d-flex gap-3 mb-3 pb-3 border-bottom">
                        <div class="flex-shrink-0">
                            <span class="badge bg-success rounded-circle p-2"
                                  style="width:36px;height:36px;display:flex;align-items:center;justify-content:center;">
                                <i class="fas fa-umbrella-beach fa-sm"></i>
                            </span>
                        </div>
                        <div>
                            <div class="fw-semibold small mb-1">Annual Leave</div>
                            <div class="text-muted" style="font-size:0.82rem;">
                                Planned annual leave entitlement as per your employment terms.
                                Please submit requests in advance to allow scheduling adjustments.
                            </div>
                        </div>
                    </div>

                    <!-- Personal -->
                    <div class="d-flex gap-3 mb-3">
                        <div class="flex-shrink-0">
                            <span class="badge bg-warning rounded-circle p-2"
                                  style="width:36px;height:36px;display:flex;align-items:center;justify-content:center;">
                                <i class="fas fa-user-clock fa-sm text-dark"></i>
                            </span>
                        </div>
                        <div>
                            <div class="fw-semibold small mb-1">Personal Leave</div>
                            <div class="text-muted" style="font-size:0.82rem;">
                                Personal matters such as family events or other obligations
                                not covered by other leave types.
                            </div>
                        </div>
                    </div>

                    <hr class="my-3">
                    <div class="alert alert-warning mb-0 py-2 px-3" style="font-size:0.82rem;border-radius:8px;">
                        <i class="fas fa-triangle-exclamation me-1"></i>
                        All requests are subject to admin review and approval. Approved leave will be
                        reflected in your schedule automatically.
                    </div>
                </div>
            </div>
        </div>

    </div>
</main>

<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<!-- Bootstrap 5.3 JS Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- Custom JS -->
<script src="<?= SITE_URL ?>/assets/js/main.js"></script>
<script>
(function () {
    'use strict';

    var startInput = document.getElementById('start_date');
    var endInput   = document.getElementById('end_date');
    var dayCount   = document.getElementById('dayCount');
    var reasonEl   = document.getElementById('reason');
    var charCount  = document.getElementById('charCount');

    function updateDayCount() {
        var s = startInput.value;
        var e = endInput.value;
        if (s && e) {
            if (e >= s) {
                var ms   = new Date(e + 'T00:00:00') - new Date(s + 'T00:00:00');
                var days = Math.round(ms / 86400000) + 1;
                dayCount.textContent  = days + ' day' + (days !== 1 ? 's' : '') + ' selected';
                dayCount.className    = 'form-text mt-1 text-primary fw-semibold';
            } else {
                dayCount.textContent = 'End date must be on or after the start date.';
                dayCount.className   = 'form-text mt-1 text-danger fw-semibold';
            }
        } else {
            dayCount.textContent = '';
        }
    }

    function updateCharCount() {
        var len = reasonEl.value.length;
        charCount.textContent = len;
        charCount.className   = len >= 20 ? 'fw-semibold text-success' : 'fw-semibold text-danger';
    }

    startInput.addEventListener('change', function () {
        if (endInput.value && endInput.value < startInput.value) {
            endInput.value = startInput.value;
        }
        endInput.min = startInput.value;
        updateDayCount();
    });

    endInput.addEventListener('change', updateDayCount);
    reasonEl.addEventListener('input',  updateCharCount);

    updateCharCount();
    updateDayCount();
})();
</script>
</body>
</html>
