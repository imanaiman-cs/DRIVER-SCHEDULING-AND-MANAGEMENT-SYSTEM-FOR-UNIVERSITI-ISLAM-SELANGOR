<?php
// ============================================================
// UIS Driver Scheduling and Management System
// admin/settings.php  –  the values behind the rules (weights, lapse time, ...)
// They live in the `settings` table, so they can be changed without touching code.
// The two scoring weights must add up to 1, so the score stays on a clear 0-100 scale.
// ============================================================
$page_title   = 'System Settings';
$current_page = 'settings.php';
require_once '../config/database.php';
requireAdmin();

// key => [label, kind, min, max, help]
$FIELDS = [
    'W_TASKS'              => ['Weight: tasks this month',         'float', 0, 1,   'Share of the driver score that depends on how many tasks (request trips + internal tasks + welfare duty days) the driver already has this month. Fewer tasks = better rank.'],
    'W_AFTER_HOURS'        => ['Weight: outside working hours',    'float', 0, 1,   'Share that depends on tasks outside working hours (Saturday/Sunday, before the start or after the end of working hours). Must add up to 1 with the weight above.'],
    'LAPSE_HOURS'          => ['Lapse after (hours)',              'int',   1, 720, 'A request still Pending after this many hours (48 = two days) becomes Lapsed and its vehicle is released.'],
    'WORK_START'           => ['Working hours start',              'time',  0, 0,   'A task starting before this time is outside working hours (08:00).'],
    'WORK_END'             => ['Working hours end',                'time',  0, 0,   'A task ending after this time is outside working hours (17:30).'],
    'TASK_DURATION_HOURS'  => ['Length of a trip task (hours)',    'int',   1, 12,  'The outbound and the return task of a trip each last this long (the vehicle stays locked for the whole departure-to-return period).'],
    'SESSION_TIMEOUT_MIN'  => ['Idle sign-out (minutes)',          'int',   1, 240, 'A user is signed out after this many minutes without activity (15).'],
    'MAX_FAILED_ATTEMPTS'  => ['Failed logins before blocking',    'int',   1, 20,  'After this many wrong passwords in a row the login is blocked (5).'],
    'LOCKOUT_MINUTES'      => ['Login block (minutes)',            'int',   1, 240, 'How long the login stays blocked after too many failed attempts (15).'],
];

$errors = [];
$values = [];
foreach ($FIELDS as $k => $_) { $values[$k] = (string)getSetting($conn, $k); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new = [];
    foreach ($FIELDS as $k => [$label, $kind, $min, $max]) {
        $raw = trim((string)($_POST[$k] ?? ''));
        if ($kind === 'time') {
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $raw)) { $errors[$k] = 'Use HH:MM (24-hour).'; }
        } elseif ($kind === 'int') {
            if (!preg_match('/^\d+$/', $raw) || (int)$raw < $min || (int)$raw > $max) { $errors[$k] = "Enter a whole number from $min to $max."; }
        } elseif (!is_numeric($raw) || (float)$raw < $min || (float)$raw > $max) {
            $errors[$k] = "Enter a number from $min to $max.";
        }
        $new[$k] = $raw;
    }
    if (!isset($errors['W_TASKS'], $errors['W_AFTER_HOURS']) && abs((float)$new['W_TASKS'] + (float)$new['W_AFTER_HOURS'] - 1.0) > 0.0001) {
        $errors['W_AFTER_HOURS'] = 'The two weights must add up to exactly 1.';
    }
    if (!isset($errors['WORK_START'], $errors['WORK_END']) && $new['WORK_END'] <= $new['WORK_START']) {
        $errors['WORK_END'] = 'Office hours must end after they start.';
    }
    $values = $new + $values;
    if (!$errors) {
        $changed = [];
        foreach ($new as $k => $v) {
            $old = (string)getSetting($conn, $k);
            if ($old !== $v) {
                saveSetting($conn, $k, $v);
                $changed[] = "$k: $old -> $v";
            }
        }
        if ($changed) {
            auditLog($conn, 'settings_update', null, implode('; ', $changed));
        }
        setFlash('success', $changed ? 'Settings saved.' : 'Nothing changed.');
        header('Location: settings.php');
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings | UIS Driver Management</title>
    <link rel="icon" type="image/png" href="<?= SITE_URL ?>/assets/images/uis-favicon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="<?= SITE_URL ?>/assets/css/style.css" rel="stylesheet">
</head>
<body>
<?php require_once '../includes/sidebar.php'; ?>
<main class="main-content p-4" id="mainContent">
    <?php showFlash(); ?>
    <h4 class="fw-bold mb-1"><i class="fas fa-sliders text-success me-2"></i>System Settings</h4>
    <p class="text-muted small">Changes take effect immediately and are written to the audit log.</p>
    <form method="post" novalidate>
        <div class="card border-0 shadow-sm"><div class="card-body"><div class="row g-3">
        <?php foreach ($FIELDS as $k => [$label, $kind, $min, $max, $help]): ?>
            <div class="col-md-6">
                <label class="form-label small fw-semibold" for="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($label) ?></label>
                <input class="form-control <?= isset($errors[$k]) ? 'is-invalid' : '' ?>" id="<?= htmlspecialchars($k) ?>" name="<?= htmlspecialchars($k) ?>"
                       type="<?= $kind === 'time' ? 'time' : 'text' ?>" value="<?= htmlspecialchars($values[$k]) ?>" required>
                <?php if (isset($errors[$k])): ?><div class="invalid-feedback"><?= htmlspecialchars($errors[$k]) ?></div><?php endif; ?>
                <div class="form-text"><?= htmlspecialchars($help) ?></div>
            </div>
        <?php endforeach; ?>
        </div></div></div>
        <button class="btn btn-success mt-3" type="submit"><i class="fas fa-floppy-disk me-1"></i>Save settings</button>
    </form>
</main>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= SITE_URL ?>/assets/js/main.js"></script>
</body>
</html>
