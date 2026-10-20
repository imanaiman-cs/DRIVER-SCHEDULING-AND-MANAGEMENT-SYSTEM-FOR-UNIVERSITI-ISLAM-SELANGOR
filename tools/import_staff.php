<?php
// ============================================================
// UIS Driver Scheduling and Management System
// tools/import_staff.php  -  simulates the sync with the UIS staff system
//
// In the real deployment the user accounts come from the UIS staff system: the Vehicle Section does NOT
// create, edit or delete users. For this prototype a CSV file plays the part of that system.
//
//   php tools/import_staff.php                       reads tools/staff_sample.csv
//   php tools/import_staff.php path\to\staff.csv     reads another file
//
// CSV columns (first row = header):
//   staff_id        unique staff number; becomes the login name
//   name, email, phone, department (the PTJ)
//   role            staff | driver | head_of_department | administrative_assistant | head_of_admin
//                   (the internal keys supervisor, admin and head_admin are accepted too)
//   status          active | inactive            (inactive = cannot sign in, history is kept)
//   password_hash   a bcrypt hash (php -r "echo password_hash('secret', PASSWORD_BCRYPT);").
//                   Empty = keep the existing password; for a NEW user an empty value is an error.
//   approver_staff_id (optional) staff_id of the person who approves this user's vehicle requests.
//                   Empty = the Head of Department of the same department (a Head of Department's own head must be given).
//
// Existing users (same staff_id) are UPDATED, new ones are inserted. Nobody is ever deleted.
// Command line only.
// ============================================================
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}
define('SKIP_REQUEST_JOBS', true);
require_once __DIR__ . '/../config/database.php';

$file = $argv[1] ?? __DIR__ . '/staff_sample.csv';
if (!is_readable($file)) {
    fwrite(STDERR, "Cannot read $file\n");
    exit(1);
}

$ROLE_KEYS = [
    'staff' => 'staff', 'driver' => 'driver',
    'head_of_department' => 'supervisor', 'supervisor' => 'supervisor',
    'administrative_assistant' => 'admin', 'admin' => 'admin',
    'head_of_admin' => 'head_admin', 'head_admin' => 'head_admin',
];

$fh = fopen($file, 'r');
$header = fgetcsv($fh);
if (!$header) { fwrite(STDERR, "Empty file.\n"); exit(1); }
$header = array_map(fn($h) => strtolower(trim((string)$h)), $header);
foreach (['staff_id', 'name', 'email', 'role', 'status'] as $need) {
    if (!in_array($need, $header, true)) { fwrite(STDERR, "Missing column: $need\n"); exit(1); }
}

$rows = [];
$line = 1;
while (($r = fgetcsv($fh)) !== false) {
    $line++;
    if (count($r) === 1 && trim((string)$r[0]) === '') { continue; }
    $rows[$line] = array_combine($header, array_pad(array_map('trim', $r), count($header), ''));
}
fclose($fh);

$added = $updated = $skipped = 0;
$find = $conn->prepare('SELECT user_id FROM users WHERE username = ?');

foreach ($rows as $line => $d) {
    $sid = $d['staff_id'];
    $role = $ROLE_KEYS[strtolower($d['role'])] ?? null;
    $status = strtolower($d['status']);
    if ($sid === '' || $d['name'] === '' || !filter_var($d['email'], FILTER_VALIDATE_EMAIL) || $role === null || !in_array($status, ['active', 'inactive'], true)) {
        echo "Line $line skipped: staff_id, name, a valid email, a known role and status active/inactive are required.\n";
        $skipped++;
        continue;
    }
    $active = $status === 'active' ? 1 : 0;
    $hash = $d['password_hash'] ?? '';
    if ($hash !== '' && !str_starts_with($hash, '$2')) {
        echo "Line $line skipped: password_hash must be a bcrypt hash.\n";
        $skipped++;
        continue;
    }
    $find->bind_param('s', $sid);
    $find->execute();
    $existing = $find->get_result()->fetch_assoc();

    // approver: given explicitly, else the Head of Department of the same PTJ
    $approver = null;
    if (($d['approver_staff_id'] ?? '') !== '') {
        $q = $conn->prepare('SELECT user_id FROM users WHERE username = ?');
        $q->bind_param('s', $d['approver_staff_id']);
        $q->execute();
        $approver = $q->get_result()->fetch_row()[0] ?? null;
        $q->close();
    } elseif ($role !== 'supervisor' && ($d['department'] ?? '') !== '') {
        $q = $conn->prepare("SELECT user_id FROM users WHERE role = 'supervisor' AND department = ? AND is_active = 1 ORDER BY user_id LIMIT 1");
        $q->bind_param('s', $d['department']);
        $q->execute();
        $approver = $q->get_result()->fetch_row()[0] ?? null;
        $q->close();
    }
    $dept = $d['department'] !== '' ? $d['department'] : null;
    $phone = $d['phone'] !== '' ? $d['phone'] : null;

    if ($existing) {
        $uid = (int)$existing['user_id'];
        $st = $conn->prepare('UPDATE users SET full_name = ?, email = ?, phone = ?, department = ?, role = ?, is_active = ?,
                                     supervisor_id = COALESCE(?, supervisor_id), password = IF(? = \'\', password, ?) WHERE user_id = ?');
        $st->bind_param('sssssiissi', $d['name'], $d['email'], $phone, $dept, $role, $active, $approver, $hash, $hash, $uid);
        $st->execute();
        $st->close();
        $updated++;
    } else {
        if ($hash === '') {
            echo "Line $line skipped: a new user ($sid) needs a password_hash.\n";
            $skipped++;
            continue;
        }
        $st = $conn->prepare('INSERT INTO users (username, password, email, full_name, role, department, supervisor_id, phone, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $st->bind_param('ssssssisi', $sid, $hash, $d['email'], $d['name'], $role, $dept, $approver, $phone, $active);
        $st->execute();
        $st->close();
        $added++;
    }
}
echo "Done: $added added, $updated updated, $skipped skipped.\n";
exit($skipped > 0 ? 2 : 0);
