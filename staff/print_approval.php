<?php
// ============================================================
// staff/print_approval.php  -  UC017 A3 Print Claim Approval / A4 Print Rental Approval
// A printable approval letter (print or save as PDF from the browser) for an APPROVED Pay and Claim request or Personal Rental.
// Who may open it: the requester, the Head of Department of the requester's PTJ, the Administrative Assistant and the Head of Admin.
// The claim payment and the rental payment are handled by the Bursary outside the system.
// ============================================================
require_once '../config/database.php';
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
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $h($title) ?> #<?= $id ?> | UIS Vehicle Section</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body{background:#eef2ef}.sheet{max-width:48rem;margin:2rem auto;background:#fff;padding:2.2rem 2.5rem;border:1px solid #cfd8d2}
        h1{font-size:1.25rem;letter-spacing:.04em;text-transform:uppercase}dt{color:#5b6b62;font-weight:600;font-size:.85rem}dd{margin-bottom:.7rem}
        .sign{border-top:1px solid #000;margin-top:3rem;padding-top:.3rem;font-size:.85rem}
        @media print{body{background:#fff}.sheet{border:0;margin:0;padding:0}.no-print{display:none!important}}
    </style>
</head>
<body>
<div class="sheet">
    <div class="text-center mb-4">
        <div class="fw-bold">UNIVERSITI ISLAM SELANGOR</div>
        <div>Vehicle Section</div>
        <h1 class="mt-3"><?= $h($title) ?> Letter</h1>
        <div class="text-muted small">Request #<?= $id ?> &middot; printed <?= date('d M Y, H:i') ?></div>
    </div>
    <dl class="row">
        <dt class="col-sm-4">Requester</dt><dd class="col-sm-8"><?= $h($req['staff_name']) ?> (<?= $h($req['staff_department']) ?>)</dd>
        <dt class="col-sm-4">Type</dt><dd class="col-sm-8"><?= $rental ? 'Personal Rental of a ' . $h($req['vehicle_type']) : 'Pay and Claim (own vehicle ' . $h($req['own_vehicle_plate']) . ')' ?></dd>
        <dt class="col-sm-4">Destination</dt><dd class="col-sm-8"><?= $h($req['destination']) ?><br><?= $h($req['destination_address']) ?></dd>
        <dt class="col-sm-4">Departure</dt><dd class="col-sm-8"><?= $h($fmt(requestDepartDT($req))) ?></dd>
        <dt class="col-sm-4">Return</dt><dd class="col-sm-8"><?= $h($fmt(requestReturnDT($req))) ?></dd>
        <?php if (!$rental): ?>
        <dt class="col-sm-4">Purpose</dt><dd class="col-sm-8"><?= nl2br($h($req['purpose'])) ?> (<?= $h(ucfirst($req['purpose_type'])) ?>)</dd>
        <dt class="col-sm-4">Passengers</dt><dd class="col-sm-8"><?= (int)$req['passenger_count'] ?><?= $req['passenger_names'] ? ': ' . $h($req['passenger_names']) : '' ?></dd>
        <?php else: ?>
        <dt class="col-sm-4">Round-trip distance</dt><dd class="col-sm-8"><?= $h(rtrim(rtrim(number_format((float)$req['distance_km'], 1), '0'), '.')) ?> km</dd>
        <dt class="col-sm-4">Charge</dt><dd class="col-sm-8"><strong>RM <?= $h(number_format((float)$req['charge'], 2)) ?></strong> (RM <?= $h(number_format((float)getSetting($conn, 'RENTAL_RATE_PER_KM', '3.00'), 2)) ?> per km)</dd>
        <?php endif; ?>
        <dt class="col-sm-4">Submitted</dt><dd class="col-sm-8"><?= $submitted ? $h($fmt($submitted['created_at'])) : $h($fmt($req['created_at'])) ?></dd>
        <?php if (!$rental): ?><dt class="col-sm-4">Approved by Head of Department</dt><dd class="col-sm-8"><?= $hod ? $h($hod['who']) . ', ' . $h($fmt($hod['created_at'])) : '-' ?></dd><?php endif; ?>
        <dt class="col-sm-4">Approved by Head of Admin</dt><dd class="col-sm-8"><?= $hoa ? $h($hoa['who']) . ', ' . $h($fmt($hoa['created_at'])) : '-' ?></dd>
    </dl>
    <p class="small text-muted"><?= $rental ? 'The rental payment is handled by the Bursary outside the system.' : 'The claim payment is handled by the Bursary outside the system.' ?></p>
    <div class="row"><div class="col-6"><div class="sign">Requester</div></div><div class="col-6"><div class="sign">Head of Admin</div></div></div>
    <div class="text-center mt-4 no-print"><button class="btn btn-success" onclick="window.print()">Print or save as PDF</button> <a class="btn btn-outline-secondary" href="javascript:history.back()">Back</a></div>
</div>
</body>
</html>
