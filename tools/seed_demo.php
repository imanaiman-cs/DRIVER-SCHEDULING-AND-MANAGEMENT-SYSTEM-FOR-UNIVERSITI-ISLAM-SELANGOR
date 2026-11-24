<?php
// ============================================================
// tools/seed_demo.php  -  builds the demo data through the real workflow functions (CLI only)
//
//   mysql -uroot < database.sql        (base data only, run it with the DEMO section removed)
//   php tools/seed_demo.php
//   python3 tools/make_demo_sql.py     (dumps the demo rows with dates relative to the day of the import)
//
// Every request, task, approval and report goes through the same functions as the pages, so the demo data obeys
// the SRS rules (reservations, recommendation filters, statuses, history, notifications).
// ============================================================
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
define('SKIP_REQUEST_JOBS', true);
define('UPLOAD_TEST_MODE', true);
require __DIR__ . '/../config/database.php';

function actAs(mysqli $conn, string $staffNo): array
{
    $u = loadUser($conn, (int)dbValue($conn, 'SELECT user_id FROM users WHERE staff_no = ?', [$staffNo]));
    $_SESSION = ['user_id' => (int)$u['user_id'], 'role' => $u['role'], 'full_name' => $u['full_name'], 'department_id' => $u['department_id'], 'driver_id' => $u['driver_id']];
    return $u;
}
function must(array $res, string $what): array
{
    if (!$res['ok']) { fwrite(STDERR, "FAILED $what: {$res['message']} " . json_encode($res['errors'] ?? []) . "\n"); exit(1); }
    return $res;
}
function letter(): array
{
    $f = tempnam(sys_get_temp_dir(), 'doc');
    file_put_contents($f, "%PDF-1.4\n1 0 obj<< /Type /Catalog >>endobj\ntrailer<< /Root 1 0 R >>\n%%EOF\n");
    return ['name' => 'surat_sokongan.pdf', 'type' => 'application/pdf', 'tmp_name' => $f, 'error' => 0, 'size' => filesize($f)];
}
function d(int $days): string { return date('Y-m-d', strtotime("+$days day")); }
function submit(mysqli $conn, string $who, array $in, bool $doc = true): int
{
    $u = actAs($conn, $who);
    $in += ['quantity' => 1, 'driver_option' => 'with_driver', 'purpose_type' => 'official', 'passengers' => 1];
    return (int)must(submitRequest($conn, $u, $in, $doc ? letter() : null), "submit by $who")['request_id'];
}
/** Assign the best recommended drivers to every reserved vehicle. */
function autoAssign(mysqli $conn, int $id, float $hours = 1, bool $sameDriver = true): void
{
    actAs($conn, 'admin');
    $r = loadRequest($conn, $id);
    $win = requestTaskWindows($r, $hours);
    $plan = []; $used = [];
    foreach (requestVehicles($conn, $id) as $rv) {
        if ($rv['is_vendor']) { $plan[$rv['rv_id']] = ['vendor_name' => 'Syarikat Bas Ekspres Maju Sdn Bhd', 'vendor_plate' => 'WXY ' . (4400 + $rv['rv_id'])]; continue; }
        foreach (['outbound', 'return'] as $leg) {
            if ($leg === 'return' && $sameDriver) { $plan[$rv['rv_id']]['return'] = $plan[$rv['rv_id']]['outbound']; continue; }
            $rec = recommendDrivers($conn, ['licence' => $rv['licence_required'], 'start' => $win[$leg][0], 'end' => $win[$leg][1], 'except_request' => $id]);
            foreach ($rec['ranked'] as $c) { if (!isset($used[$leg][$c['driver_id']])) { $plan[$rv['rv_id']][$leg] = $c['driver_id']; $used[$leg][$c['driver_id']] = 1; break; } }
        }
    }
    must(assignRequest($conn, $id, $plan, $hours), "assign #$id");
}
function bestDriver(mysqli $conn, array $t): int
{
    $rec = recommendDrivers($conn, ['licence' => $t['licence_required'], 'start' => $t['start_dt'], 'end' => $t['end_dt'], 'except_task' => (int)$t['task_id']]);
    return (int)$rec['ranked'][0]['driver_id'];
}
/** Moves a request (and its reservation, tasks and history) back in time by $days days. */
function shiftBack(mysqli $conn, int $id, int $days): void
{
    dbExec($conn, 'UPDATE requests SET depart_at = DATE_SUB(depart_at, INTERVAL ? DAY), return_at = DATE_SUB(return_at, INTERVAL ? DAY), created_at = DATE_SUB(created_at, INTERVAL ? DAY),
                   hod_approved_at = DATE_SUB(hod_approved_at, INTERVAL ? DAY), assigned_at = DATE_SUB(assigned_at, INTERVAL ? DAY), hoa_approved_at = DATE_SUB(hoa_approved_at, INTERVAL ? DAY),
                   approval_email_at = DATE_SUB(approval_email_at, INTERVAL ? DAY), rejected_at = DATE_SUB(rejected_at, INTERVAL ? DAY), cancelled_at = DATE_SUB(cancelled_at, INTERVAL ? DAY)
                   WHERE request_id = ?', [$days, $days, $days, $days, $days, $days, $days, $days, $days, $id]);
    dbExec($conn, 'UPDATE request_vehicles SET start_dt = DATE_SUB(start_dt, INTERVAL ? DAY), end_dt = DATE_SUB(end_dt, INTERVAL ? DAY) WHERE request_id = ?', [$days, $days, $id]);
    dbExec($conn, 'UPDATE tasks SET start_dt = DATE_SUB(start_dt, INTERVAL ? DAY), end_dt = DATE_SUB(end_dt, INTERVAL ? DAY), approved_at = DATE_SUB(approved_at, INTERVAL ? DAY) WHERE request_id = ?', [$days, $days, $days, $id]);
    $conn->query('DROP TRIGGER IF EXISTS trg_history_no_update');
    dbExec($conn, 'UPDATE request_history SET created_at = DATE_SUB(created_at, INTERVAL ? DAY) WHERE request_id = ?', [$days, $id]);
    $conn->query("CREATE TRIGGER trg_history_no_update BEFORE UPDATE ON request_history FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'request_history is insert-only'");
}
/** The driver writes the report of a task that has ended. */
function report(mysqli $conn, int $taskId, int $km, string $fuel = '3/4', array $problem = []): void
{
    $t = loadTask($conn, $taskId);
    actAs($conn, (string)dbValue($conn, 'SELECT u.staff_no FROM drivers d JOIN users u ON u.user_id = d.user_id WHERE d.driver_id = ?', [(int)$t['driver_id']]));
    $in = ['actual_depart' => date('Y-m-d\TH:i', strtotime($t['start_dt'])), 'actual_return' => date('Y-m-d\TH:i', strtotime($t['end_dt']) + 1800),
           'end_mileage' => (string)((int)$t['current_mileage'] + $km), 'fuel_level' => $fuel, 'vehicle_condition' => $problem ? 'problem' : 'good'] + $problem;
    must(saveTaskReport($conn, $t, (int)$t['driver_id'], $in, null), "report task $taskId");
}

dbExec($conn, "UPDATE vehicles SET status = 'active' WHERE vehicle_id = 7");   // under maintenance at the end
$trip = fn(array $x) => $x + ['waiting_location' => 'Main entrance, Block A (Canselori)'];

// ---------- past, completed trips (workload, reports, vehicle usage) ----------
$past = [
    ['stf002', 'Car', 12, 'Registrar Office meeting at the Ministry of Higher Education', 'Kementerian Pendidikan Tinggi', 'No. 2, Menara 2, Jalan P5/6, Presint 5, 62200 Putrajaya', 2, 62],
    ['stf003', 'Van', 10, 'Student leaders programme at Pusat Islam Selangor', 'Pusat Islam Selangor', 'Jalan Masjid, Seksyen 5, 40000 Shah Alam, Selangor', 10, 48],
    ['stf001', 'MPV', 8, 'School visit for the teaching practicum', 'SMK Seksyen 9 Shah Alam', 'Jalan Sepana 9/9, Seksyen 9, 40100 Shah Alam, Selangor', 5, 36],
    ['stf002', 'Car', 6, 'Collect documents from the Land Office', 'Pejabat Tanah dan Galian Selangor', 'Bangunan Sultan Salahuddin Abdul Aziz Shah, 40000 Shah Alam', 1, 40],
];
foreach ($past as $i => [$who, $type, $back, $purpose, $dest, $addr, $pax, $km]) {
    $id = submit($conn, $who, $trip(['depart_date' => d(1), 'depart_time' => $i % 2 ? '09:00' : '08:30', 'return_date' => d(1), 'return_time' => '15:30',
        'vehicle_type' => $type, 'purpose' => $purpose, 'destination' => $dest, 'address' => $addr, 'passengers' => $pax]));
    actAs($conn, (string)dbValue($conn, 'SELECT staff_no FROM users WHERE user_id = (SELECT approver_id FROM requests WHERE request_id = ?)', [$id]));
    must(hodDecide($conn, $id, true), 'hod');
    autoAssign($conn, $id, 1, $i % 2 === 0);
    actAs($conn, 'head001');
    must(hoaApproveAssignment($conn, $id), 'hoa');
    shiftBack($conn, $id, $back + 1);
    foreach (dbAll($conn, 'SELECT task_id FROM tasks WHERE request_id = ? ORDER BY start_dt', [$id]) as $j => $t) {
        report($conn, (int)$t['task_id'], (int)($km / 2) + $j, $j ? '1/2' : '3/4', $i === 1 && $j === 1 ? ['problem_type' => 'Lights', 'problem_desc' => 'Left brake light not working.', 'can_be_used' => '1'] : []);
    }
}

// ---------- approved upcoming trips ----------
$r1 = submit($conn, 'stf001', $trip(['depart_date' => d(3), 'depart_time' => '08:00', 'return_date' => d(3), 'return_time' => '17:00', 'vehicle_type' => 'Car',
    'purpose' => 'National higher education forum (faculty delegation)', 'destination' => 'Putrajaya International Convention Centre (PICC)', 'address' => 'Dataran Gemilang, Presint 5, 62000 Putrajaya', 'passengers' => 3]));
actAs($conn, 'hos002'); must(hodDecide($conn, $r1, true), 'hod'); autoAssign($conn, $r1); actAs($conn, 'head001'); must(hoaApproveAssignment($conn, $r1), 'hoa');

$r2 = submit($conn, 'stf003', $trip(['depart_date' => d(1), 'depart_time' => '14:00', 'return_date' => d(1), 'return_time' => '18:00', 'vehicle_type' => 'Van',
    'purpose' => 'Student counselling outreach', 'destination' => 'Kolej Komuniti Hulu Langat', 'address' => 'Jalan Hulu Langat, 43100 Hulu Langat, Selangor', 'passengers' => 8]));
actAs($conn, 'hos004'); must(hodDecide($conn, $r2, true), 'hod'); autoAssign($conn, $r2); actAs($conn, 'head001'); must(hoaApproveAssignment($conn, $r2), 'hoa');

// a driver replacement that the Head of Admin approved
$t = dbOne($conn, "SELECT task_id FROM tasks WHERE request_id = ? AND trip_type = 'return'", [$r1]);
$tt = loadTask($conn, (int)$t['task_id']);
$alt = recommendDrivers($conn, ['licence' => $tt['licence_required'], 'start' => $tt['start_dt'], 'end' => $tt['end_dt'], 'except_task' => (int)$t['task_id'], 'exclude_driver' => (int)$tt['driver_id']]);
actAs($conn, 'admin'); must(requestReplacement($conn, (int)$t['task_id'], (int)$alt['ranked'][0]['driver_id'], 'The driver has a medical appointment.'), 'replace');
actAs($conn, 'head001'); must(hoaDecideReplacement($conn, (int)dbValue($conn, 'SELECT MAX(replacement_id) FROM driver_replacements'), true), 'repl');

// ---------- requests waiting at each step ----------
$r3 = submit($conn, 'stf001', $trip(['depart_date' => d(9), 'depart_time' => '07:30', 'return_date' => d(9), 'return_time' => '18:00', 'vehicle_type' => 'Bus', 'quantity' => 4,
    'purpose' => 'Faculty students field trip (Bachelor of Education)', 'destination' => 'Muzium Negara', 'address' => 'Jalan Damansara, 50480 Kuala Lumpur', 'passengers' => 150]));
actAs($conn, 'hos002'); must(hodDecide($conn, $r3, true), 'hod'); autoAssign($conn, $r3, 2);   // Driver Assigned, waiting for the Head of Admin (with one vendor bus)

$r4 = submit($conn, 'stf002', $trip(['depart_date' => d(2), 'depart_time' => '09:30', 'return_date' => d(2), 'return_time' => '13:00', 'vehicle_type' => 'MPV',
    'purpose' => 'Benchmarking visit to the Registrar of UNISEL', 'destination' => 'Universiti Selangor (UNISEL)', 'address' => 'Jalan Timur Tambahan, 45600 Bestari Jaya, Selangor', 'passengers' => 4]));
actAs($conn, 'hos003'); must(hodDecide($conn, $r4, true), 'hod');   // waiting for driver assignment (Urgent)

$r5 = submit($conn, 'stf003', $trip(['depart_date' => d(6), 'depart_time' => '08:00', 'return_date' => d(7), 'return_time' => '17:00', 'vehicle_type' => 'Van',
    'purpose' => 'Leadership camp (two days)', 'destination' => 'Kem Bina Negara Bukit Tinggi', 'address' => 'Bukit Tinggi, 28750 Bentong, Pahang', 'passengers' => 12]));   // Pending at the HoD

$r6 = submit($conn, 'hos002', $trip(['depart_date' => d(5), 'depart_time' => '10:00', 'return_date' => d(5), 'return_time' => '16:00', 'vehicle_type' => 'Car',
    'purpose' => 'Deans meeting', 'destination' => 'Universiti Malaya', 'address' => 'Jalan Profesor Diraja Ungku Aziz, 50603 Kuala Lumpur', 'passengers' => 1]));   // HoD request -> parent HoD (hos005)

$r7 = submit($conn, 'stf002', $trip(['depart_date' => d(4), 'depart_time' => '09:00', 'return_date' => d(4), 'return_time' => '12:00', 'vehicle_type' => 'Car', 'driver_option' => 'pay_claim',
    'purpose' => 'Seminar on records management', 'destination' => 'Arkib Negara Malaysia', 'address' => 'Jalan Tuanku Abdul Halim, 50480 Kuala Lumpur', 'passengers' => 1]));
actAs($conn, 'hos003'); must(hodDecide($conn, $r7, true), 'hod'); actAs($conn, 'head001'); must(hoaDecideRequest($conn, $r7, true), 'hoa');   // approved Pay and Claim (letter)

$r8 = submit($conn, 'drv001', $trip(['depart_date' => d(8), 'depart_time' => '08:00', 'return_date' => d(8), 'return_time' => '14:00', 'vehicle_type' => 'Lorry', 'driver_option' => 'self_drive', 'purpose_type' => 'personal',
    'purpose' => 'Moving house', 'destination' => 'Taman Bukit Mewah', 'address' => 'Jalan BM 1/2, Taman Bukit Mewah, 43000 Kajang', 'distance_km' => 46, 'waiting_location' => 'Vehicle yard']), false);
actAs($conn, 'admin'); must(assignRequest($conn, $r8, [], 1, 46.0), 'confirm'); actAs($conn, 'head001'); must(hoaDecideRequest($conn, $r8, true), 'hoa');   // approved Personal (letter)

$r9 = submit($conn, 'stf003', $trip(['depart_date' => d(11), 'depart_time' => '09:00', 'return_date' => d(11), 'return_time' => '13:00', 'vehicle_type' => 'Lorry', 'purpose_type' => 'personal',
    'purpose' => 'Transport furniture for a family wedding', 'destination' => 'Kampung Sungai Merab', 'address' => 'Kampung Sungai Merab Luar, 43000 Kajang', 'distance_km' => 24]), false);   // Personal, waiting for the Assistant

$r10 = submit($conn, 'stf002', $trip(['depart_date' => d(5), 'depart_time' => '09:00', 'return_date' => d(5), 'return_time' => '12:00', 'vehicle_type' => 'MPV',
    'purpose' => 'Collect certificates from the printer', 'destination' => 'Percetakan Nasional Malaysia', 'address' => 'Jalan Chan Sow Lin, 55200 Kuala Lumpur', 'passengers' => 2]));
actAs($conn, 'hos003'); must(hodDecide($conn, $r10, false, 'The printer will deliver the certificates by courier.'), 'reject');

$r11 = submit($conn, 'stf001', $trip(['depart_date' => d(7), 'depart_time' => '08:30', 'return_date' => d(7), 'return_time' => '12:30', 'vehicle_type' => 'Car',
    'purpose' => 'Technology in education seminar', 'destination' => 'Cyberjaya Convention Centre', 'address' => 'Persiaran Multimedia, 63000 Cyberjaya', 'passengers' => 2]));
actAs($conn, 'stf001'); must(cancelRequest($conn, $r11, currentUserFor($conn, 'stf001'), 'The seminar was postponed to next month.'), 'cancel');

$r12 = submit($conn, 'stf003', $trip(['depart_date' => d(4), 'depart_time' => '09:00', 'return_date' => d(4), 'return_time' => '13:00', 'vehicle_type' => 'Car',
    'purpose' => 'Student welfare visit', 'destination' => 'Hospital Kajang', 'address' => 'Jalan Semenyih, 43000 Kajang', 'passengers' => 2]));
dbExec($conn, 'UPDATE requests SET created_at = DATE_SUB(created_at, INTERVAL 3 DAY) WHERE request_id = ?', [$r12]);
runRequestJobs($conn);   // r12 lapses (reminder first, then lapse)

function currentUserFor(mysqli $conn, string $staffNo): array { return loadUser($conn, (int)dbValue($conn, 'SELECT user_id FROM users WHERE staff_no = ?', [$staffNo])); }

// ---------- internal tasks ----------
actAs($conn, 'admin');
$i1 = must(saveInternalTask($conn, 0, ['task_type' => 'workshop', 'task_date' => d(2), 'start_time' => '09:00', 'end_time' => '11:00', 'vehicle_id' => 7, 'description' => 'Collect the Urvan after the gearbox repair (Auto Service Klang)']), 'it')['task_id'];
$i2 = must(saveInternalTask($conn, 0, ['task_type' => 'refuelling', 'task_date' => d(1), 'start_time' => '08:00', 'end_time' => '08:45', 'vehicle_id' => 8, 'description' => 'Refuel bus BGN 9595 before the field trip']), 'it')['task_id'];
must(assignInternalTask($conn, $i2, bestDriver($conn, loadTask($conn, $i2))), 'assign it'); actAs($conn, 'head001'); must(hoaDecideTask($conn, $i2, true), 'it ok');
actAs($conn, 'admin');
$i3 = must(saveInternalTask($conn, 0, ['task_type' => 'test_drive', 'task_date' => d(3), 'start_time' => '14:00', 'end_time' => '15:00', 'vehicle_id' => 12, 'description' => 'Test drive after the brake service']), 'it')['task_id'];
must(assignInternalTask($conn, $i3, bestDriver($conn, loadTask($conn, $i3))), 'assign it');   // waiting for the Head of Admin
$i4 = must(saveInternalTask($conn, 0, ['task_type' => 'workshop', 'task_date' => d(1), 'start_time' => '10:00', 'end_time' => '12:00', 'vehicle_id' => 4, 'description' => 'Service at 60,000 km']), 'it')['task_id'];
must(assignInternalTask($conn, $i4, bestDriver($conn, loadTask($conn, $i4))), 'assign it'); actAs($conn, 'head001'); must(hoaDecideTask($conn, $i4, true), 'it ok');
dbExec($conn, 'UPDATE tasks SET start_dt = DATE_SUB(start_dt, INTERVAL 5 DAY), end_dt = DATE_SUB(end_dt, INTERVAL 5 DAY) WHERE task_id = ?', [$i4]);
report($conn, $i4, 14, 'Full');
dbExec($conn, "UPDATE vehicles SET status = 'maintenance' WHERE vehicle_id = 7");

// ---------- welfare duty: this week approved, next week waiting for drivers ----------
actAs($conn, 'admin');
$mon = weekMonday(date('Y-m-d'));
$veh = [];
for ($i = 0; $i < 7; $i++) { $veh[date('Y-m-d', strtotime("$mon +$i day"))] = $i % 2 ? 2 : 6; }
must(createWelfareWeek($conn, $mon, $veh), 'welfare');
$days = array_filter(welfareDays($conn, loadWelfareWeek($conn, $mon), $mon));
$drv = [];
foreach ($days as $t) { $drv[(int)$t['task_id']] = bestDriver($conn, $t); }
must(assignWelfareWeek($conn, $mon, $drv), 'welfare assign');
actAs($conn, 'head001'); must(hoaDecideWelfare($conn, (int)loadWelfareWeek($conn, $mon)['welfare_id'], true), 'welfare ok');
dbExec($conn, "UPDATE vehicles SET status = 'maintenance' WHERE vehicle_id = 7");
// days of this week that have passed: back-date into the week (createWelfareWeek skips passed days)
actAs($conn, 'admin');
$next = date('Y-m-d', strtotime("$mon +7 day"));
$veh = [];
for ($i = 0; $i < 7; $i++) { $veh[date('Y-m-d', strtotime("$next +$i day"))] = $i % 2 ? 6 : 2; }
must(createWelfareWeek($conn, $next, $veh), 'welfare next');

// ---------- schedule changes ----------
actAs($conn, 'drv005'); must(submitScheduleChange($conn, 5, d(12), d(13), 'Family wedding in Kelantan'), 'sc');
actAs($conn, 'drv007'); must(submitScheduleChange($conn, 7, d(15), d(15), 'Court summons as a witness'), 'sc2');
actAs($conn, 'head001'); must(hoaDecideScheduleChange($conn, (int)dbValue($conn, 'SELECT MAX(change_id) FROM schedule_changes'), true), 'sc ok');

dbExec($conn, "DELETE FROM email_log"); dbExec($conn, "DELETE FROM whatsapp_log"); dbExec($conn, "DELETE FROM settings WHERE setting_key = 'JOBS_LAST_RUN'");
echo "Demo data created.\n";
