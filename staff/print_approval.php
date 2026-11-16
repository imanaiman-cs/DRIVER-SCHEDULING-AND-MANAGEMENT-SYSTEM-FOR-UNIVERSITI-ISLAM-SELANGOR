<?php
// ============================================================
// staff/print_approval.php  -  UC017 A3 Print Claim Approval / A4 Print Rental Approval
// A printable approval letter (print or save as PDF from the browser) for an APPROVED Pay and Claim request or Personal Rental.
// Who may open it: the requester, the Head of Department of the requester's PTJ, the Administrative Assistant and the Head of Admin.
// The claim payment and the rental payment are handled by the Bursary outside the system.
// ============================================================
require_once '../config/database.php';
require_once '../includes/admin_page.php';     // appHead()
requireLogin();

$id = (int)($_GET['id'] ?? 0);
$req = requestForCurrentUser($conn, $id);
$rental = $req && isPersonalRental($req);
if (!$req || !($rental || isPayClaimRequest($req)) || !in_array($req['status'], ['processed', 'completed'], true)) {
    http_response_code(404);
    setFlash('warning', 'No approval letter is available for that request. It must be an approved Pay and Claim request or Personal Rental.');
    header('Location: ' . SITE_URL . '/' . requestPageForRole((string)$_SESSION['role']));
    exit();
}
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$hist = requestHistory($conn, $id);
$when = fn(string $action) => array_values(array_filter($hist, fn($r) => $r['action'] === $action));
$hod = $when('approve')[0] ?? null;
$hoa = $when('confirm')[0] ?? null;
$submitted = $when('submit')[0] ?? null;
$title = $rental ? 'Personal Rental Approval' : 'Claim Approval';
$fmt = fn($d) => date('d M Y, H:i', strtotime($d));
?>
<!DOCTYPE html>
<html lang="en" data-theme="uis">
<head>
<?php appHead($title . ' #' . $id); ?>
    <style>
        .sheet h1{letter-spacing:.04em;text-transform:uppercase}
        .sheet dd{margin-bottom:.5rem}
        .sign{border-top:1px solid currentColor;margin-top:3rem;padding-top:.3rem;font-size:.85rem}
        @media print{body{background:#fff}.sheet{border:0!important;margin:0!important;padding:0!important}}
    </style>
</head>
<body class="min-h-screen bg-base-200">
<div class="sheet mx-auto my-8 max-w-3xl border border-base-300 bg-base-100 px-6 py-8 sm:px-10">
    <div class="text-center mb-4">
        <div class="font-bold">UNIVERSITI ISLAM SELANGOR</div>
        <div>Vehicle Section</div>
        <h1 class="mt-3 text-xl font-semibold"><?= $h($title) ?> Letter</h1>
        <div class="text-base-content/60 text-sm">Request #<?= $id ?> &middot; printed <?= date('d M Y, H:i') ?></div>
    </div>
    <dl class="grid-12 grid grid-cols-12 gap-x-6 gap-y-1">
        <dt class="col-span-12 sm:col-span-4 text-sm font-semibold text-base-content/60">Requester</dt><dd class="col-span-12 sm:col-span-8"><?= $h($req['staff_name']) ?> (<?= $h($req['staff_department']) ?>)</dd>
        <dt class="col-span-12 sm:col-span-4 text-sm font-semibold text-base-content/60">Type</dt><dd class="col-span-12 sm:col-span-8"><?= $rental ? 'Personal Rental of a ' . $h($req['vehicle_type']) : 'Pay and Claim (own vehicle ' . $h($req['own_vehicle_plate']) . ')' ?></dd>
        <dt class="col-span-12 sm:col-span-4 text-sm font-semibold text-base-content/60">Destination</dt><dd class="col-span-12 sm:col-span-8"><?= $h($req['destination']) ?><br><?= $h($req['destination_address']) ?></dd>
        <dt class="col-span-12 sm:col-span-4 text-sm font-semibold text-base-content/60">Departure</dt><dd class="col-span-12 sm:col-span-8"><?= $h($fmt(requestDepartDT($req))) ?></dd>
        <dt class="col-span-12 sm:col-span-4 text-sm font-semibold text-base-content/60">Return</dt><dd class="col-span-12 sm:col-span-8"><?= $h($fmt(requestReturnDT($req))) ?></dd>
        <?php if (!$rental): ?>
        <dt class="col-span-12 sm:col-span-4 text-sm font-semibold text-base-content/60">Purpose</dt><dd class="col-span-12 sm:col-span-8"><?= nl2br($h($req['purpose'])) ?> (<?= $h(ucfirst($req['purpose_type'])) ?>)</dd>
        <dt class="col-span-12 sm:col-span-4 text-sm font-semibold text-base-content/60">Passengers</dt><dd class="col-span-12 sm:col-span-8"><?= (int)$req['passenger_count'] ?><?= $req['passenger_names'] ? ': ' . $h($req['passenger_names']) : '' ?></dd>
        <?php else: ?>
        <dt class="col-span-12 sm:col-span-4 text-sm font-semibold text-base-content/60">Round-trip distance</dt><dd class="col-span-12 sm:col-span-8"><?= $h(rtrim(rtrim(number_format((float)$req['distance_km'], 1), '0'), '.')) ?> km</dd>
        <dt class="col-span-12 sm:col-span-4 text-sm font-semibold text-base-content/60">Charge</dt><dd class="col-span-12 sm:col-span-8"><strong>RM <?= $h(number_format((float)$req['charge'], 2)) ?></strong> (RM <?= $h(number_format((float)getSetting($conn, 'RENTAL_RATE_PER_KM', '3.00'), 2)) ?> per km)</dd>
        <?php endif; ?>
        <dt class="col-span-12 sm:col-span-4 text-sm font-semibold text-base-content/60">Submitted</dt><dd class="col-span-12 sm:col-span-8"><?= $submitted ? $h($fmt($submitted['created_at'])) : $h($fmt($req['created_at'])) ?></dd>
        <?php if (!$rental): ?><dt class="col-span-12 sm:col-span-4 text-sm font-semibold text-base-content/60">Approved by Head of Department</dt><dd class="col-span-12 sm:col-span-8"><?= $hod ? $h($hod['who']) . ', ' . $h($fmt($hod['created_at'])) : '-' ?></dd><?php endif; ?>
        <dt class="col-span-12 sm:col-span-4 text-sm font-semibold text-base-content/60">Approved by Head of Admin</dt><dd class="col-span-12 sm:col-span-8"><?= $hoa ? $h($hoa['who']) . ', ' . $h($fmt($hoa['created_at'])) : '-' ?></dd>
    </dl>
    <p class="text-sm text-base-content/60"><?= $rental ? 'The rental payment is handled by the Bursary outside the system.' : 'The claim payment is handled by the Bursary outside the system.' ?></p>
    <div class="grid grid-cols-2 gap-8"><div><div class="sign">Requester</div></div><div><div class="sign">Head of Admin</div></div></div>
    <div class="mt-6 flex justify-center gap-2 no-print"><button class="btn btn-success" onclick="window.print()">Print or save as PDF</button><a class="btn btn-outline" href="javascript:history.back()">Back</a></div>
</div>
</body>
</html>
