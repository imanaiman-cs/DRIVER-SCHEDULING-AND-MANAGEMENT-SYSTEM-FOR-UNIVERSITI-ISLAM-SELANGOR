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
    'W_TASKS'              => ['Weight: tasks this month',   'float', 0, 1,   'Share of the driver score that depends on how many tasks (trips + internal tasks) the driver already has this month.'],
    'W_AFTER_HOURS'        => ['Weight: after-hours work',   'float', 0, 1,   'Share that depends on after-hours work (Sat/Sun, early start, late finish). Must add up to 1 with the weight above.'],
    'WELFARE_PENALTY'      => ['Welfare penalty (points)',   'int',   0, 100, 'Points taken off the score of a driver who is on welfare duty that day.'],
    'MIN_DAYS_AHEAD'       => ['Minimum days ahead',         'int',   0, 30,  'Two-day rule: a request must be made at least this many days before the trip (paper form BPTK/BPI/2025/PIN.5 for urgent trips).'],
    'REMINDER_AFTER_HOURS' => ['Auto reminder after (hours)','int',   1, 720, 'The Head of Section gets one automatic reminder when a request has waited this long. Use a value below the lapse time.'],
    'LAPSE_HOURS'          => ['Lapse after (hours)',        'int',   1, 720, 'A request still pending after this many hours becomes Lapsed.'],
    'WORK_START'           => ['Office hours start',         'time',  0, 0,   'A trip starting before this time counts as after-hours.'],
    'WORK_END'             => ['Office hours end',           'time',  0, 0,   'A trip ending after this time counts as after-hours.'],
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
    if (!isset($errors['REMINDER_AFTER_HOURS'], $errors['LAPSE_HOURS']) && (int)$new['REMINDER_AFTER_HOURS'] >= (int)$new['LAPSE_HOURS']) {
        $errors['REMINDER_AFTER_HOURS'] = 'The reminder must come before the request lapses.';
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
