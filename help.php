<?php
// ============================================================
// help.php  -  short guide for the signed-in role (follows the SRS use cases)
// ============================================================
$current_page = 'help.php';
require_once 'config/database.php';
require_once 'includes/ui.php';
requireLogin();
$role = $_SESSION['role'] ?? 'staff';
$ms = uisLang() === 'ms';

$statuses = [
    'pending'         => 'Waiting for the Head of Department (or, for a Personal lorry request, the Administrative Assistant). One reminder email is sent after one day; after two days without approval the request becomes Lapsed.',
    'hod_approved'    => 'Approved by the Head of Department. With Driver: the Administrative Assistant assigns the drivers. Pay and Claim / Self Drive: waiting for the Head of Admin.',
    'driver_assigned' => 'The vehicle and drivers are assigned. The Head of Admin approves the assignment.',
    'approved'        => 'Final. The requester sees the drivers and vehicles; the drivers see their tasks.',
    'rejected'        => 'Rejected with a reason. The vehicle reservation is released.',
    'cancelled'       => 'Cancelled before the trip, with a reason. The vehicle reservation is released.',
    'lapsed'          => 'Not approved in time. It was not cancelled by the Vehicle Section. The vehicle reservation is released.',
    'completed'       => 'The trip has ended.',
];
$request = [
    '<strong>New Request</strong>: enter the departure and return date and time, then <em>Check Availability</em>. You see each vehicle type (car, MPV, van, bus, lorry) with the units left.',
    'Select the type and quantity, and the driver option: <em>With Driver</em>, <em>Pay and Claim</em> (car: you use your own car), or <em>Self Drive</em> (lorry, drivers with a GDL licence only).',
    'Select the purpose type. Official and Unofficial need the supporting letter. <em>Personal</em> (lorry only) needs the round-trip distance instead; you pay RM3 per km and the request goes to the Administrative Assistant.',
    'The vehicles are reserved when you submit (first submitted, first served). A trip less than two days away is Urgent. If not enough buses are left, the rest are hired from a vendor.',
    'Follow it in <strong>My Request</strong>. You can cancel it before the trip (a reason is required). An approved Pay and Claim or Personal request has a <em>Print Approval Letter</em> button.',
];
$steps = [
    'staff' => $request,
    'supervisor' => array_merge(['<strong>Department Request</strong>: approve or reject (reason required) the requests of your department within two days. Urgent requests are at the top. You may also cancel a department request (reason required).',
                                 'Your own requests are approved by the Head of Department of the parent department.'], $request),
    'driver' => array_merge(['<strong>My Task</strong>: your approved tasks (request trips, internal tasks, welfare duty) with the vehicle, destination, waiting location and officer.',
                             'After the departure time, open the task and <em>Create Report</em>: actual times, end mileage, fuel level and vehicle condition (problem type, description, photo, whether the vehicle can still be used). You can correct it later.',
                             '<strong>Schedule Change</strong>: tell the Vehicle Section the dates you cannot work, or open a task and <em>Request Change</em> to be removed from it. The Head of Admin decides.'], $request),
    'admin' => ['<strong>Assign Request Task</strong>: for each reserved vehicle choose the outbound and return driver. The recommended drivers are ranked by tasks this month (50%), weekend tasks (30%) and experience (20%); a driver outside the recommendation needs a confirmation. Enter vendor details for vendor buses. For Personal requests check the distance and charge.',
                '<strong>Internal Task</strong> (workshop, refuelling, test drive) and <strong>Welfare Duty</strong> (weekly 24-hour duty with a car or van): add them, then assign the drivers. Every assignment is approved by the Head of Admin.',
                '<strong>Driver Schedule</strong>: the week or month of every driver. <em>Needs replacement</em> lists tasks whose driver has an approved schedule change; use <em>Replace Driver</em> (reason required).',
                '<strong>User Account</strong>: create driver accounts, edit department, role and licence details, confirm Head of Department roles, deactivate accounts. <strong>Vehicle</strong>: add, update mileage, fuel and status, deactivate.',
                '<strong>Report</strong>: Driver Workload, Driver Replacement and Vehicle Usage by month, exported as PDF or Excel.'],
];
$steps['admin'] = array_merge($steps['admin'], $request);
$steps['head_admin'] = array_merge(['<strong>Pending Approval</strong>: approve or reject (reason required) driver assignments, internal tasks, welfare duty schedules, driver replacements, and Pay and Claim / Self Drive / Personal Self Drive requests. A rejected assignment goes back to the Administrative Assistant.',
                                    '<strong>Schedule Change</strong>: approve or reject the drivers\' requests. Approved drivers are not recommended on those dates and their tasks are sent for replacement.'], $steps['admin']);
adminPageStart('Help');
pageHeader('Help', 'circle-question', 'You are signed in as ' . roleLabel($role) . '.');
?>
    <div class="card mb-4 border border-base-300 bg-base-100"><div class="card-body">
        <h2 class="text-base font-bold">What you can do</h2>
        <ol class="ms-5 list-decimal"><?php foreach ($steps[$role] ?? $steps['staff'] as $s): ?><li class="mb-1.5"><?= $s ?></li><?php endforeach; ?></ol>
    </div></div>
    <div class="card border border-base-300 bg-base-100"><div class="card-body">
        <h2 class="text-base font-bold">Request statuses</h2>
        <table class="table"><tbody>
        <?php foreach ($statuses as $k => $text): ?><tr><td class="whitespace-nowrap"><?= requestStatusBadge($k) ?></td><td><?= h($text) ?></td></tr><?php endforeach; ?>
        </tbody></table>
    </div></div>
<?php adminPageEnd(); ?>
