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
require_once '../includes/admin_page.php';
requireAdmin();

// key => [label, kind, min, max, help]
$FIELDS = [
    'W_TASKS'              => ['Weight: tasks this month',         'float', 0, 1,   'Share of the driver score that depends on how many tasks (request trips + internal tasks + welfare duty days) the driver already has this month. Fewer tasks = better rank.'],
    'W_AFTER_HOURS'        => ['Weight: outside working hours',    'float', 0, 1,   'Share that depends on tasks outside working hours (Saturday/Sunday, before the start or after the end of working hours). Must add up to 1 with the weight above.'],
    'LAPSE_HOURS'          => ['Lapse after (hours)',              'int',   1, 720, 'A request still Pending after this many hours (48 = two days) becomes Lapsed and its vehicle is released.'],
    'REMINDER_HOURS'       => ['Approval reminder after (hours)',  'int',   1, 719, 'A request still Pending after this many hours (24) gets one automatic reminder to the Head of Department and the staff member, in the system and by e-mail. It must be less than the lapse time.'],
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
    if (!isset($errors['REMINDER_HOURS'], $errors['LAPSE_HOURS']) && (int)$new['REMINDER_HOURS'] >= (int)$new['LAPSE_HOURS']) {
        $errors['REMINDER_HOURS'] = 'The reminder must go out before the request lapses.';
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
<?php adminPageStart('System Settings'); ?>
    <h1 class="text-2xl font-extrabold tracking-tight mb-1"><i class="fas fa-sliders me-2 text-[0.8em] text-primary"></i>System Settings</h1>
    <p class="text-base-content/60 text-sm">Changes take effect immediately and are written to the audit log.</p>
    <form method="post" novalidate>
        <div class="card border border-base-300 bg-base-100"><div class="card-body"><div class="grid-12 grid grid-cols-12 gap-4">
        <?php foreach ($FIELDS as $k => [$label, $kind, $min, $max, $help]): ?>
            <div class="col-span-12 md:col-span-6">
                <label class="mb-1 block text-sm font-medium" for="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($label) ?></label>
                <input class="input w-full <?= isset($errors[$k]) ? 'input-error' : '' ?>" id="<?= htmlspecialchars($k) ?>" name="<?= htmlspecialchars($k) ?>"
                       type="<?= $kind === 'time' ? 'time' : 'text' ?>" value="<?= htmlspecialchars($values[$k]) ?>" required>
                <?php if (isset($errors[$k])): ?><div class="mt-1 block text-xs text-error"><?= htmlspecialchars($errors[$k]) ?></div><?php endif; ?>
                <div class="mt-1 block text-xs text-base-content/60"><?= htmlspecialchars($help) ?></div>
            </div>
        <?php endforeach; ?>
        </div></div></div>
        <button class="btn btn-success mt-4" type="submit"><i class="fas fa-floppy-disk me-1"></i>Save settings</button>
    </form>
<?php adminPageEnd(); ?>
