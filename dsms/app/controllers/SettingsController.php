<?php
/**
 * SettingsController - UC018 System settings.
 * Values live in the `settings` table so the admin can change them without touching code.
 * The two scoring weights must add up to 1.0, so the score stays on a clear 0-100 scale
 * that can be explained in one sentence.
 */
class SettingsController extends Controller
{
    /** key => [label, kind, min, max, help] */
    private const FIELDS = [
        'W_TASKS'            => ['Weight: tasks this month',  'float', 0, 1,   'Share of the score that depends on how many tasks the driver already has this month.'],
        'W_AFTER_HOURS'      => ['Weight: after-hours work',  'float', 0, 1,   'Share of the score that depends on after-hours / weekend work this month. Must add up to 1 with the weight above.'],
        'WELFARE_PENALTY'    => ['Welfare penalty (points)',  'int',   0, 100, 'Points taken off the score of a driver who is on the welfare roster (already worked late recently).'],
        'LAPSE_HOURS'        => ['Lapse after (hours)',       'int',   1, 720, 'A request still Pending Approval after this many hours becomes Lapsed.'],
        'MIN_DAYS_AHEAD'     => ['Minimum days ahead',        'int',   0, 30,  'Two-day rule (form BPTK/BPI/2025/PIN.5): requests need at least this many days notice.'],
        'WORK_START'         => ['Office hours start',        'time',  0, 0,   'Trips starting before this time count as after-hours.'],
        'WORK_END'           => ['Office hours end',          'time',  0, 0,   'Trips ending after this time count as after-hours.'],
        'SESSION_TIMEOUT_MIN'=> ['Session timeout (minutes)', 'int',   1, 240, 'Automatic logout after this much inactivity.'],
        'MAX_FAILED_ATTEMPTS'=> ['Lock after failed logins',  'int',   1, 20,  'The account is locked after this many wrong passwords in a row.'],
        'MAX_UPLOAD_MB'      => ['Max upload size (MB)',      'int',   1, 20,  'Largest supporting document that can be uploaded.'],
    ];

    public function index(): void
    {
        $this->adminOnly();
        $this->show((new Setting())->all(), []);
    }

    private function show(array $values, array $errors): void
    {
        $this->view('settings/index', ['title' => 'System settings', 'fields' => self::FIELDS, 'values' => $values, 'errors' => $errors]);
    }

    public function save(): void
    {
        $this->adminOnly();
        $this->requirePost();
        $new = []; $errors = [];
        foreach (self::FIELDS as $key => [$label, $kind, $min, $max]) {
            $raw = trim((string)($_POST[$key] ?? ''));
            if ($kind === 'time') {
                if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $raw)) { $errors[$key] = 'Use HH:MM (24-hour).'; }
            } elseif ($kind === 'int') {
                if (!preg_match('/^\d+$/', $raw) || (int)$raw < $min || (int)$raw > $max) { $errors[$key] = "Enter a whole number from $min to $max."; }
            } else {
                if (!is_numeric($raw) || (float)$raw < $min || (float)$raw > $max) { $errors[$key] = "Enter a number from $min to $max."; }
            }
            $new[$key] = $raw;
        }
        if (!isset($errors['W_TASKS'], $errors['W_AFTER_HOURS']) && abs((float)$new['W_TASKS'] + (float)$new['W_AFTER_HOURS'] - 1.0) > 0.0001) {
            $errors['W_AFTER_HOURS'] = 'The two weights must add up to exactly 1.';
        }
        if (!isset($errors['WORK_START'], $errors['WORK_END']) && $new['WORK_END'] <= $new['WORK_START']) {
            $errors['WORK_END'] = 'Office hours must end after they start.';
        }
        if ($errors) {
            $this->show($new, $errors);
            return;
        }

        $setting = new Setting();
        $changed = [];
        $current = $setting->all();
        foreach ($new as $key => $value) {
            if ((string)($current[$key] ?? '') !== $value) {
                $setting->set($key, $value);
                $changed[] = "$key: " . ($current[$key] ?? '') . " -> $value";
            }
        }
        if ($changed) {
            (new AuditLog())->record('settings_update', null, implode('; ', $changed));
        }
        $this->flashRedirect('success', $changed ? 'Settings saved.' : 'Nothing changed.', 'settings/index');
    }
}
