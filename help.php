<?php
// ============================================================
// UIS Driver Scheduling and Management System
// help.php  -  short guide for the signed-in role
// ============================================================
$current_page = 'help.php';
require_once 'config/database.php';
require_once 'includes/admin_page.php';
requireLogin();
$role = $_SESSION['role'] ?? 'staff';

$statuses = [
    'pending' => 'Waiting for the Head of Department. After two days without a decision it becomes Lapsed.',
    'approved' => 'The Head of Department approved. The Administrative Assistant now chooses the driver(s).',
    'assigned' => 'A driver was chosen. The Head of Admin must approve the assignment.',
    'processed' => 'Approved by the Head of Admin. Driver and vehicle are final; the driver and you are told.',
    'rejected' => 'The Head of Department rejected the request. The reason is shown.',
    'cancelled' => 'Cancelled (the reason is shown). The vehicle is released.',
    'lapsed' => 'Not approved within two days. The vehicle is released.',
];
$steps = [
    'staff' => ['Open <strong>Request a Vehicle</strong>. Enter departure and return (date and time), the number of passengers and whether you need a driver.',
                'Choose one of the vehicles that are free in that period. Fill in the destination (name and full address), purpose, passenger names, phone number and waiting location. Attach PDF, JPG or PNG files (5 MB each) if you have them.',
                'A trip less than 2 days away asks you to confirm. A trip less than 1 day away cannot be requested online: use the paper form BPTK/BPI/2025/PIN.5.',
                'Follow the status in <strong>My Requests</strong>. You can cancel your own request before the trip starts (the reason is optional).'],
    'driver' => ['Open <strong>My Tasks</strong>: your upcoming tasks in date order, and the Past Tasks tab. You only see confirmed tasks.',
                 'Each task shows the date, time, vehicle, full destination address, waiting location, purpose and the officer name and phone number.',
                 '<strong>Schedule Change</strong>: tell the Vehicle Section the dates you cannot work, or ask to be withdrawn from a task (the Head of Admin decides; the task stays with you until it is approved).',
                 'On a task, press <strong>Update Vehicle</strong> to enter the current mileage, the fuel level and any problem with the vehicle.',
                 'Open <strong>My Profile</strong> to see your details and to update your phone number, licence classes and licence expiry date.',
                 'You do not confirm tasks or update the trip status. Like everyone you can also request a vehicle for yourself.'],
    'supervisor' => ['Open <strong>Vehicle Approvals</strong>. You only see requests of your own PTJ.',
                     'Approve, or reject with a reason. You can add a comment, and cancel a request (reason required).',
                     'A request from a Head of Department is approved by his own head.'],
    'admin' => ['<strong>Vehicle Requests</strong>: Approved requests need a driver. Press <em>Assign driver</em>: the recommendation (fewer tasks and fewer tasks outside working hours rank higher) is shown first; you choose the outbound driver, then use the same driver and vehicle for the return trip or choose a separate driver and vehicle.',
                'When a bus needs a PSV licence and no UIS driver is free, record a <strong>vendor bus</strong> (vendor name, bus registration number, vendor driver) in Assign Driver.',
                'The Head of Admin approves every assignment and decides the <strong>Schedule Changes</strong> asked by the drivers. A Confirmed trip can have a driver replaced (reason required) until the task starts.',
                '<strong>Record Paper Form</strong>: for an approved paper form BPTK/BPI/2025/PIN.5, enter its details and upload the scan. The request is saved as Approved and the vehicle is locked.',
                '<strong>Approval Reminders</strong>: the system reminds the Head of Department once after 1 day; you can also send a reminder by hand.',
                '<strong>Internal Tasks</strong>, <strong>Welfare Duty</strong> and the <strong>Schedule Calendar</strong>; <strong>Drivers</strong> and <strong>Vehicles</strong> (never deleted, only deactivated); <strong>Reports</strong> by month (screen, print, PDF, Excel).'],
];
$steps['head_admin'] = array_merge($steps['admin'], ['<strong>Approve Assignments</strong> (Head of Admin only): approve or reject each assignment, also the ones you made yourself. A rejection needs a reason.']);
adminPageStart('Help');
?>
    <h4 class="fw-bold mb-1"><i class="fas fa-circle-question text-success me-2"></i>Help</h4>
    <p class="text-muted small">You are signed in as <strong><?= htmlspecialchars(roleLabel($role)) ?></strong>. Every role is also Staff and can request a vehicle.</p>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body">
        <h6 class="fw-bold">What to do</h6>
        <ol class="mb-0"><?php foreach ($steps[$role] ?? $steps['staff'] as $s): ?><li class="mb-1"><?= $s ?></li><?php endforeach; ?></ol>
    </div></div>
    <div class="card border-0 shadow-sm"><div class="card-body">
        <h6 class="fw-bold">Request statuses</h6>
        <table class="table table-sm mb-0"><tbody>
        <?php foreach ($statuses as $k => $text): ?>
            <tr><td class="text-nowrap"><?= requestStatusBadge($k) ?></td><td><?= htmlspecialchars($text) ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
    </div></div>
<?php adminPageEnd(); ?>
