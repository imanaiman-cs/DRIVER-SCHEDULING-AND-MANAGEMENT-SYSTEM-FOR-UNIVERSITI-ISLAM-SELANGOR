<?php
// ============================================================
// tools/seed_demo.php  -  builds the demo data through the Domain Layer controllers of the SDD (CLI only)
//
//   mysql -uroot < database.sql        (base data only, run it with the DEMO section removed)
//   php tools/seed_demo.php
//   python3 tools/make_demo_sql.py     (dumps the demo rows with dates relative to the day of the import)
//
// Every request, task, approval and report goes through the same controllers as the pages, so the demo data obeys
// the SRS rules (reservations, recommendation filters, statuses, history, notifications).
// ============================================================
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
define('SKIP_REQUEST_JOBS', true);
define('UPLOAD_TEST_MODE', true);
require __DIR__ . '/../config/database.php';

$rc = new RequestController(); $ap = new ApprovalController(); $as = new AssignmentController(); $tc = new TaskController();
$dtc = new DriverTaskController(); $rs = new RecommendationService();

function u(string $staffID): array { return User::findByID((int)User::findByStaffID($staffID)['user_id']); }
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
function submit(string $who, array $in, bool $doc = true): int
{
    global $rc;
    $in += ['quantity' => 1, 'driver_option' => Request::WITH_DRIVER, 'purpose_type' => Request::OFFICIAL, 'no_of_passengers' => 1];
    return (int)must($rc->submitRequest(u($who), $in, $doc ? letter() : null), "submit by $who")['request_id'];
}
function headDecide(int $id, bool $approve, string $comment = ''): void
{
    global $rc, $ap;
    $r = Request::findByID($id);
    must($ap->decideRequest($rc->approverOf($r), $id, $approve ? 'Approve' : 'Reject', $comment), "head decides #$id");
}
function hoaApproveAssignment(int $id): void
{
    global $ap;
    must($ap->decideAssignment(u('head001'), array_column(Task::findByRequest($id), 'task_id'), 'Approve'), "hoa approves #$id");
}
/** The Assistant picks the vehicle of each reservation and the best recommended drivers. */
function autoAssign(int $id, float $hours = 1, bool $sameDriver = true): void
{
    global $as, $rs;
    $r = Request::findByID($id);
    $win = $as->taskWindows($r, $hours);
    $lic = licenceForVehicleType($r['vehicle_type']);
    $plan = []; $used = []; $usedVeh = [];
    foreach (VehicleReservation::findByRequest($id) as $res) {
        $rid = (int)$res['reservation_id'];
        if ($res['is_vendor']) { $plan[$rid] = ['vendor_name' => 'Syarikat Bas Ekspres Maju Sdn Bhd', 'vendor_plate_no' => 'WXY ' . (4400 + $rid)]; continue; }
        foreach ((new VehicleController())->freeForReservation($res) as $v) { if (!isset($usedVeh[$v['vehicle_id']])) { $plan[$rid]['vehicle_id'] = (int)$v['vehicle_id']; $usedVeh[$v['vehicle_id']] = 1; break; } }
        foreach (['Outbound' => 'outbound', 'Return' => 'return'] as $leg => $key) {
            if ($key === 'return' && $sameDriver) { $plan[$rid]['return'] = $plan[$rid]['outbound']; continue; }
            foreach ($rs->recommendDrivers($lic, $win[$leg][0], $win[$leg][1], ['except_request' => $id])['ranked'] as $c) {
                if (!isset($used[$key][$c['driver_id']])) { $plan[$rid][$key] = (int)$c['driver_id']; $used[$key][$c['driver_id']] = 1; break; }
            }
        }
    }
    must($as->assignRequestTask(u('admin'), $id, $plan, $hours), "assign #$id");
}
function bestDriver(int $taskID, int $exclude = 0): int
{
    global $tc, $rs;
    $t = $tc->getTask($taskID);
    return (int)$rs->recommendDrivers($t['licence_required'], $t['start_datetime'], $t['end_datetime'], ['except_task' => [$taskID], 'exclude_driver' => $exclude])['ranked'][0]['driver_id'];
}
function withHistoryUnlocked(callable $f): void
{
    Database::execute('DROP TRIGGER IF EXISTS trg_history_no_update');
    $f();
    Database::execute("CREATE TRIGGER trg_history_no_update BEFORE UPDATE ON request_status_history FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'request_status_history is insert-only'");
}
/** Moves a request (and its reservations, tasks and history) back in time by $days days. */
function shiftBack(int $id, int $days): void
{
    Database::execute('UPDATE request SET depart_datetime = DATE_SUB(depart_datetime, INTERVAL ? DAY), return_datetime = DATE_SUB(return_datetime, INTERVAL ? DAY),
                       date_submitted = DATE_SUB(date_submitted, INTERVAL ? DAY), hod_approved_date = DATE_SUB(hod_approved_date, INTERVAL ? DAY),
                       hoa_approved_date = DATE_SUB(hoa_approved_date, INTERVAL ? DAY) WHERE request_id = ?', [$days, $days, $days, $days, $days, $id]);
    Database::execute('UPDATE vehicle_reservation SET start_datetime = DATE_SUB(start_datetime, INTERVAL ? DAY), end_datetime = DATE_SUB(end_datetime, INTERVAL ? DAY) WHERE request_id = ?', [$days, $days, $id]);
    Database::execute('UPDATE task SET start_datetime = DATE_SUB(start_datetime, INTERVAL ? DAY), end_datetime = DATE_SUB(end_datetime, INTERVAL ? DAY), approved_date = DATE_SUB(approved_date, INTERVAL ? DAY) WHERE request_id = ?', [$days, $days, $days, $id]);
    withHistoryUnlocked(fn() => Database::execute('UPDATE request_status_history SET changed_at = DATE_SUB(changed_at, INTERVAL ? DAY) WHERE request_id = ?', [$days, $id]));
    Database::execute('UPDATE notification SET date_sent = DATE_SUB(date_sent, INTERVAL ? DAY) WHERE link LIKE ?', [$days, '%id=' . $id]);
}
/** The driver writes the report of a task that has ended. */
function report(int $taskID, int $km, string $fuel = '3/4', array $problem = []): void
{
    global $tc, $dtc;
    $t = $tc->getTask($taskID);
    $mil = (int)Vehicle::findByID((int)$t['vehicle_id'])['current_mileage'];
    $in = ['actual_depart' => date('Y-m-d\TH:i', strtotime($t['start_datetime'])), 'actual_return' => date('Y-m-d\TH:i', strtotime($t['end_datetime']) + 1800),
           'end_mileage' => (string)($mil + $km), 'fuel_level' => $fuel, 'vehicle_condition' => $problem ? 'Problem' : 'Good'] + $problem;
    must($dtc->submitReport((int)$t['driver_id'], $taskID, $in, null), "report task $taskID");
}

Database::execute("UPDATE vehicle SET status = 'Active' WHERE vehicle_id = 7");   // under maintenance at the end
$trip = fn(array $x) => $x + ['waiting_location' => 'Main entrance, Block A (Canselori)'];

// ---------- past, completed trips (workload, reports, vehicle usage) ----------
$past = [
    ['stf002', 'Car', 12, 'Registrar Office meeting at the Ministry of Higher Education', 'Kementerian Pendidikan Tinggi', 'No. 2, Menara 2, Jalan P5/6, Presint 5, 62200 Putrajaya', 2, 62],
    ['stf003', 'Van', 10, 'Student leaders programme at Pusat Islam Selangor', 'Pusat Islam Selangor', 'Jalan Masjid, Seksyen 5, 40000 Shah Alam, Selangor', 10, 48],
    ['stf001', 'MPV', 8, 'School visit for the teaching practicum', 'SMK Seksyen 9 Shah Alam', 'Jalan Sepana 9/9, Seksyen 9, 40100 Shah Alam, Selangor', 5, 36],
    ['stf002', 'Car', 6, 'Collect documents from the Land Office', 'Pejabat Tanah dan Galian Selangor', 'Bangunan Sultan Salahuddin Abdul Aziz Shah, 40000 Shah Alam', 1, 40],
];
foreach ($past as $i => [$who, $type, $back, $purpose, $dest, $addr, $pax, $km]) {
    $id = submit($who, $trip(['depart_date' => d(1), 'depart_time' => $i % 2 ? '09:00' : '08:30', 'return_date' => d(1), 'return_time' => '15:30',
        'vehicle_type' => $type, 'purpose' => $purpose, 'destination' => $dest, 'address' => $addr, 'no_of_passengers' => $pax]));
    headDecide($id, true);
    autoAssign($id, 1, $i % 2 === 0);
    hoaApproveAssignment($id);
    shiftBack($id, $back + 1);
    foreach (Task::findByRequest($id) as $j => $t) {
        report((int)$t['task_id'], (int)($km / 2) + $j, $j ? '1/2' : '3/4', $i === 1 && $j === 1 ? ['problem_type' => 'Lights', 'problem_desc' => 'Left brake light not working.', 'can_be_used' => '1'] : []);
    }
}
(new ScheduledJob())->completeRequests();

// ---------- approved upcoming trips ----------
$r1 = submit('stf001', $trip(['depart_date' => d(3), 'depart_time' => '08:00', 'return_date' => d(3), 'return_time' => '17:00', 'vehicle_type' => 'Car',
    'purpose' => 'National higher education forum (faculty delegation)', 'destination' => 'Putrajaya International Convention Centre (PICC)', 'address' => 'Dataran Gemilang, Presint 5, 62000 Putrajaya', 'no_of_passengers' => 3]));
headDecide($r1, true); autoAssign($r1); hoaApproveAssignment($r1);

$r2 = submit('stf003', $trip(['depart_date' => d(1), 'depart_time' => '14:00', 'return_date' => d(1), 'return_time' => '18:00', 'vehicle_type' => 'Van',
    'purpose' => 'Student counselling outreach', 'destination' => 'Kolej Komuniti Hulu Langat', 'address' => 'Jalan Hulu Langat, 43100 Hulu Langat, Selangor', 'no_of_passengers' => 8]));
headDecide($r2, true); autoAssign($r2); hoaApproveAssignment($r2);

// a driver replacement that the Head of Admin approved
$t = array_values(array_filter(Task::findByRequest($r1), fn($x) => $x['trip_type'] === 'Return'))[0];
must($as->replaceDriver(u('admin'), (int)$t['task_id'], bestDriver((int)$t['task_id'], (int)$t['driver_id']), 'The driver has a medical appointment.'), 'replace');
must($ap->decideReplacement(u('head001'), (int)DriverReplacement::findPendingByTask((int)$t['task_id'])['replacement_id'], 'Approve'), 'replacement');

// ---------- requests waiting at each step ----------
$r3 = submit('stf001', $trip(['depart_date' => d(9), 'depart_time' => '07:30', 'return_date' => d(9), 'return_time' => '18:00', 'vehicle_type' => 'Bus', 'quantity' => 4,
    'purpose' => 'Faculty students field trip (Bachelor of Education)', 'destination' => 'Muzium Negara', 'address' => 'Jalan Damansara, 50480 Kuala Lumpur', 'no_of_passengers' => 150]));
headDecide($r3, true); autoAssign($r3, 2);   // Driver Assigned, waiting for the Head of Admin (with one vendor bus)

$r4 = submit('stf002', $trip(['depart_date' => d(2), 'depart_time' => '09:30', 'return_date' => d(2), 'return_time' => '13:00', 'vehicle_type' => 'MPV',
    'purpose' => 'Benchmarking visit to the Registrar of UNISEL', 'destination' => 'Universiti Selangor (UNISEL)', 'address' => 'Jalan Timur Tambahan, 45600 Bestari Jaya, Selangor', 'no_of_passengers' => 4]));
headDecide($r4, true);   // waiting for driver assignment (Urgent)

submit('stf003', $trip(['depart_date' => d(6), 'depart_time' => '08:00', 'return_date' => d(7), 'return_time' => '17:00', 'vehicle_type' => 'Van',
    'purpose' => 'Leadership camp (two days)', 'destination' => 'Kem Bina Negara Bukit Tinggi', 'address' => 'Bukit Tinggi, 28750 Bentong, Pahang', 'no_of_passengers' => 12]));   // Pending at the HoD

submit('hos002', $trip(['depart_date' => d(5), 'depart_time' => '10:00', 'return_date' => d(5), 'return_time' => '16:00', 'vehicle_type' => 'Car',
    'purpose' => 'Deans meeting', 'destination' => 'Universiti Malaya', 'address' => 'Jalan Profesor Diraja Ungku Aziz, 50603 Kuala Lumpur', 'no_of_passengers' => 1]));   // HoD request -> parent HoD (hos005)

submit('admin', $trip(['depart_date' => d(6), 'depart_time' => '09:00', 'return_date' => d(6), 'return_time' => '12:00', 'vehicle_type' => 'Car',
    'purpose' => 'Road tax renewal for the fleet', 'destination' => 'JPJ Shah Alam', 'address' => 'Persiaran Damai, Seksyen 11, 40000 Shah Alam', 'no_of_passengers' => 1,
    'waiting_location' => 'Vehicle yard']));   // Administrative Assistant's request -> Head of Admin (head of BPI)

$r7 = submit('stf002', $trip(['depart_date' => d(4), 'depart_time' => '09:00', 'return_date' => d(4), 'return_time' => '12:00', 'vehicle_type' => 'Car', 'driver_option' => Request::PAY_AND_CLAIM,
    'purpose' => 'Seminar on records management', 'destination' => 'Arkib Negara Malaysia', 'address' => 'Jalan Tuanku Abdul Halim, 50480 Kuala Lumpur', 'no_of_passengers' => 1]));
headDecide($r7, true); must($ap->decideRequest(u('head001'), $r7, 'Approve'), 'hoa');   // approved Pay and Claim (letter)

$r8 = submit('drv001', $trip(['depart_date' => d(8), 'depart_time' => '08:00', 'return_date' => d(8), 'return_time' => '14:00', 'vehicle_type' => 'Lorry', 'driver_option' => Request::SELF_DRIVE,
    'purpose_type' => Request::PERSONAL, 'purpose' => 'Moving house', 'destination' => 'Taman Bukit Mewah', 'address' => 'Jalan BM 1/2, Taman Bukit Mewah, 43000 Kajang', 'distance_km' => 46,
    'waiting_location' => 'Vehicle yard']), false);
must($as->assignRequestTask(u('admin'), $r8, [], 1, 46.0), 'confirm'); must($ap->decideRequest(u('head001'), $r8, 'Approve'), 'hoa');   // approved Personal (letter)

submit('stf003', $trip(['depart_date' => d(11), 'depart_time' => '09:00', 'return_date' => d(11), 'return_time' => '13:00', 'vehicle_type' => 'Lorry', 'purpose_type' => Request::PERSONAL,
    'purpose' => 'Transport furniture for a family wedding', 'destination' => 'Kampung Sungai Merab', 'address' => 'Kampung Sungai Merab Luar, 43000 Kajang', 'distance_km' => 24]), false);   // Personal, waiting for the Assistant

$r10 = submit('stf002', $trip(['depart_date' => d(5), 'depart_time' => '09:00', 'return_date' => d(5), 'return_time' => '12:00', 'vehicle_type' => 'MPV',
    'purpose' => 'Collect certificates from the printer', 'destination' => 'Percetakan Nasional Malaysia', 'address' => 'Jalan Chan Sow Lin, 55200 Kuala Lumpur', 'no_of_passengers' => 2]));
headDecide($r10, false, 'The printer will deliver the certificates by courier.');

$r11 = submit('stf001', $trip(['depart_date' => d(7), 'depart_time' => '08:30', 'return_date' => d(7), 'return_time' => '12:30', 'vehicle_type' => 'Car',
    'purpose' => 'Technology in education seminar', 'destination' => 'Cyberjaya Convention Centre', 'address' => 'Persiaran Multimedia, 63000 Cyberjaya', 'no_of_passengers' => 2]));
must($rc->cancelRequest(u('stf001'), $r11, 'The seminar was postponed to next month.'), 'cancel');

$r12 = submit('stf003', $trip(['depart_date' => d(4), 'depart_time' => '09:00', 'return_date' => d(4), 'return_time' => '13:00', 'vehicle_type' => 'Car',
    'purpose' => 'Student welfare visit', 'destination' => 'Hospital Kajang', 'address' => 'Jalan Semenyih, 43000 Kajang', 'no_of_passengers' => 2]));
Database::execute('UPDATE request SET date_submitted = DATE_SUB(date_submitted, INTERVAL 3 DAY) WHERE request_id = ?', [$r12]);
withHistoryUnlocked(fn() => Database::execute('UPDATE request_status_history SET changed_at = DATE_SUB(changed_at, INTERVAL 3 DAY) WHERE request_id = ?', [$r12]));
(new ScheduledJob())->run();   // r12 lapses (not approved within two days)

// ---------- internal tasks ----------
$adm = u('admin'); $hoa = u('head001');
$it = fn(array $f) => (int)must($tc->addInternalTask($adm, $f), 'internal task')['task_id'];
$i1 = $it(['task_type' => 'Workshop', 'task_date' => d(2), 'start_time' => '09:00', 'end_time' => '11:00', 'vehicle_id' => 7, 'description' => 'Collect the Urvan after the gearbox repair (Auto Service Klang)']);
$i2 = $it(['task_type' => 'Refuelling', 'task_date' => d(1), 'start_time' => '08:00', 'end_time' => '08:45', 'vehicle_id' => 8, 'description' => 'Refuel bus BGN 9595 before the field trip']);
must($as->assignInternalTask($adm, $i2, bestDriver($i2)), 'assign it'); must($ap->decideAssignment($hoa, [$i2], 'Approve'), 'it ok');
$i3 = $it(['task_type' => 'Test Drive', 'task_date' => d(3), 'start_time' => '14:00', 'end_time' => '15:00', 'vehicle_id' => 12, 'description' => 'Test drive after the brake service']);
must($as->assignInternalTask($adm, $i3, bestDriver($i3)), 'assign it');   // waiting for the Head of Admin
$i4 = $it(['task_type' => 'Workshop', 'task_date' => d(1), 'start_time' => '10:00', 'end_time' => '12:00', 'vehicle_id' => 4, 'description' => 'Service at 60,000 km']);
must($as->assignInternalTask($adm, $i4, bestDriver($i4)), 'assign it'); must($ap->decideAssignment($hoa, [$i4], 'Approve'), 'it ok');
Database::execute('UPDATE task SET start_datetime = DATE_SUB(start_datetime, INTERVAL 5 DAY), end_datetime = DATE_SUB(end_datetime, INTERVAL 5 DAY), approved_date = DATE_SUB(approved_date, INTERVAL 5 DAY) WHERE task_id = ?', [$i4]);
report($i4, 14, 'Full');

// ---------- welfare duty: this week approved, next week waiting for drivers ----------
$mon = weekMonday(date('Y-m-d'));
$veh = [];
for ($i = 0; $i < 7; $i++) { $veh[date('Y-m-d', strtotime("$mon +$i day"))] = $i % 2 ? 2 : 6; }
must($tc->createWelfareWeek($adm, $mon, $veh), 'welfare');
['week' => $wk, 'days' => $days] = $tc->getWelfareWeek($mon);
$drv = [];
foreach (array_filter($days) as $t) { $drv[(int)$t['task_id']] = bestDriver((int)$t['task_id']); }
must($as->assignWelfareDuty($adm, (int)$wk['duty_id'], $drv), 'welfare assign');
must($ap->decideAssignment($hoa, array_keys($drv), 'Approve'), 'welfare ok');
$next = date('Y-m-d', strtotime("$mon +7 day"));
$veh = [];
for ($i = 0; $i < 7; $i++) { $veh[date('Y-m-d', strtotime("$next +$i day"))] = $i % 2 ? 6 : 2; }
must($tc->createWelfareWeek($adm, $next, $veh), 'welfare next');
Database::execute("UPDATE vehicle SET status = 'Under Maintenance' WHERE vehicle_id = 7");

// ---------- schedule changes ----------
must($dtc->requestScheduleChange(15, ['start_date' => d(12), 'end_date' => d(13), 'reason' => 'Family wedding in Kelantan']), 'sc');   // drv005, pending
must($dtc->requestScheduleChange(17, ['start_date' => d(15), 'end_date' => d(15), 'reason' => 'Court summons as a witness']), 'sc2');   // drv007
must($ap->decideScheduleChange($hoa, (int)Database::value('SELECT MAX(change_id) FROM schedule_change'), 'Approve'), 'sc ok');

echo "Demo data created.\n";
