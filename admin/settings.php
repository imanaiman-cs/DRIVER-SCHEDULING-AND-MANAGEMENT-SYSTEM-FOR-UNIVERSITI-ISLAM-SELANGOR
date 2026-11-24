<?php
// ============================================================
// UIS Driver Scheduling and Management System
// admin/settings.php  –  the values behind the rules (weights, lapse time, ...)
// They live in the `settings` table, so they can be changed without touching code.
// The three Recommend Driver weights must add up to 1, so the score stays on a clear 0-100 scale.
// ============================================================
$page_title   = 'System Settings';
$current_page = 'settings.php';
require_once '../config/database.php';
require_once '../includes/admin_page.php';
requireAdmin();

// key => [label, kind, min, max, help]
$FIELDS = [
    'W_TASKS'              => ['Weight: tasks this month',          'float', 0, 1,   'Recommend Driver: share of the score from the number of tasks this month (request tasks, internal tasks, welfare duty). Fewer tasks rank higher. SRS: 0.5.'],
    'W_WEEKEND'            => ['Weight: weekend tasks this month',  'float', 0, 1,   'Share from the number of weekend tasks this month (weekend tasks are overtime). Fewer rank higher. SRS: 0.3.'],
    'W_EXPERIENCE'         => ['Weight: years of experience',       'float', 0, 1,   'Share from the years of service (start date of service). More experience ranks higher. SRS: 0.2. The three weights must add up to 1.'],
    'RECOMMEND_TOP'        => ['Recommended drivers shown',         'int',   1, 20,  'The best-ranked drivers marked Recommended. Choosing another driver asks for a confirmation.'],
    'LAPSE_HOURS'          => ['Lapse after (hours)',               'int',   1, 720, 'A request not approved by the Head of Department after this many hours becomes Lapsed and its vehicles are released (48 = two days).'],
    'REMINDER_HOURS'       => ['Reminder email after (hours)',      'int',   1, 720, 'The Head of Department gets one reminder email when a request is still Pending after this many hours (24 = one day).'],
    'URGENT_HOURS'         => ['Urgent when departing within (hours)', 'int', 1, 720, 'A request departing within this many hours is Urgent and shown at the top of the lists (48 = two days).'],
    'TASK_DURATION_HOURS'  => ['Default task duration (hours)',     'float', 0.5, 24, 'Length of the outbound and the return task of a request. The Assistant can change it per request for a long-distance trip.'],
    'RESERVATION_BUFFER_HOURS' => ['Reservation after return (hours)', 'float', 0, 24, 'A vehicle stays reserved this long after the return time.'],
    'CHARGE_PER_KM'        => ['Personal charge per km (RM)',       'float', 0, 100, 'Charge of a Personal lorry request per kilometre of the round trip (RM3).'],
    'WELFARE_START'        => ['Welfare duty start time',           'time',  0, 0,   'The 24-hour welfare duty starts at this time each day.'],
    'UIS_EMAIL_DOMAINS'    => ['UIS email domains',                 'text',  0, 0,   'Only emails of these domains can register (comma list, e.g. uis.edu.my).'],
    'CODE_EXPIRY_MINUTES'  => ['Verification code expiry (minutes)', 'int', 1, 1440, 'A registration or reset password code expires after this many minutes.'],
    'SESSION_TIMEOUT_MIN'  => ['Idle sign-out (minutes)',           'int',   1, 240, 'A user is signed out after this many minutes without activity (15).'],
    'MAX_FAILED_ATTEMPTS'  => ['Failed logins before blocking',     'int',   1, 20,  'After this many wrong passwords in a row the login is blocked (5).'],
    'LOCKOUT_MINUTES'      => ['Login block (minutes)',             'int',   1, 240, 'How long the login stays blocked after too many failed attempts (15).'],
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
        } elseif ($kind === 'text') {
            if ($raw === '' || mb_strlen($raw) > 255) { $errors[$k] = 'Enter a value.'; }
        } elseif ($kind === 'int') {
            if (!preg_match('/^\d+$/', $raw) || (int)$raw < $min || (int)$raw > $max) { $errors[$k] = "Enter a whole number from $min to $max."; }
        } elseif (!is_numeric($raw) || (float)$raw < $min || (float)$raw > $max) {
            $errors[$k] = "Enter a number from $min to $max.";
        }
        $new[$k] = $raw;
    }
    if (!isset($errors['W_TASKS']) && !isset($errors['W_WEEKEND']) && !isset($errors['W_EXPERIENCE'])
        && abs((float)$new['W_TASKS'] + (float)$new['W_WEEKEND'] + (float)$new['W_EXPERIENCE'] - 1.0) > 0.0001) {
        $errors['W_EXPERIENCE'] = 'The three weights must add up to exactly 1.';
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
            addHistory($conn, null, 'settings_update', null, implode('; ', $changed));
        }
        setFlash('success', $changed ? 'Settings saved.' : 'Nothing changed.');
        header('Location: settings.php');
        exit();
    }
}
?>
<?php adminPageStart('System Settings'); ?>
    <h1 class="text-2xl font-extrabold tracking-tight mb-1"><i class="fas fa-sliders me-2 text-[0.8em] text-primary"></i>System Settings</h1>
    <p class="text-base-content/60 text-sm">Changes take effect immediately.</p>
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
