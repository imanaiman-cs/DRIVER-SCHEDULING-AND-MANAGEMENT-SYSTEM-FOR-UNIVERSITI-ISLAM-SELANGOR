<?php
// ============================================================
// staff/approval_letter.php  -  UC017 A3 Print Approval Letter
// An approved Pay and Claim or Personal request: request details, approval dates and the names of the Head of
// Department and Head of Admin who approved it; for a Personal request also the distance and the charge.
// Print it, or choose "Save as PDF" in the print dialog to download it as a PDF.
// ============================================================
require_once '../config/database.php';
requireLogin();

$me = currentUser();
$id = (int)($_GET['id'] ?? 0);
$r = $id ? loadRequest($conn, $id) : null;
if (!$r || !requestVisibleTo($conn, $r, $me) || !hasApprovalLetter($r)) {
    setFlash('danger', 'An approval letter is only available for an approved Pay and Claim or Personal request.');
    header('Location: request.php?id=' . $id);
    exit();
}
$rows = [
    'Reference' => 'DSMS/' . date('Y', strtotime($r['created_at'])) . '/' . str_pad((string)$id, 5, '0', STR_PAD_LEFT),
    'Requester' => $r['requester_name'] . ' (' . $r['requester_staff_no'] . ')',
    'Department' => $r['department_name'] ?? '-',
    'Vehicle' => $r['vehicle_type'] . ', ' . driverOptionLabel($r['driver_option']),
    'Purpose type' => purposeTypeLabel($r['purpose_type']),
    'Purpose' => $r['purpose'],
    'Destination' => $r['destination'] . ', ' . $r['address'],
    'Departure' => fmtDT($r['depart_at']),
    'Return' => fmtDT($r['return_at']),
    'Passengers' => (string)$r['passengers'],
];
if ($r['purpose_type'] === 'personal') {
    $rows['Round-trip distance'] = number_format((float)$r['distance_km'], 1) . ' km';
    $rows['Charge'] = money((float)$r['charge']) . ' (RM ' . number_format(getSettingFloat($conn, 'CHARGE_PER_KM'), 2) . ' per km)';
}
$vehicles = $r['driver_option'] === 'pay_claim' ? [] : requestVehicles($conn, $id);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Approval Letter - Request #<?= $id ?></title>
<style>
  :root { color-scheme: light; }
  body { font-family: "IBM Plex Sans", Arial, sans-serif; color: #13221b; background: #eef2ef; margin: 0; }
  .sheet { background: #fff; max-width: 780px; margin: 24px auto; padding: 48px 56px; box-shadow: 0 2px 12px rgba(0,0,0,.08); }
  header { display: flex; align-items: center; gap: 16px; border-bottom: 3px solid #0b5d3b; padding-bottom: 14px; }
  header img { width: 64px; height: 64px; object-fit: contain; }
  header h1 { font-size: 18px; margin: 0; color: #0b5d3b; } header p { margin: 2px 0 0; font-size: 12px; color: #555; }
  h2 { font-size: 16px; text-align: center; margin: 28px 0 18px; text-decoration: underline; }
  table { width: 100%; border-collapse: collapse; font-size: 14px; }
  td { padding: 7px 8px; border-bottom: 1px solid #e3e8e5; vertical-align: top; } td:first-child { width: 34%; color: #4b5b53; }
  .sign { display: grid; grid-template-columns: 1fr 1fr; gap: 32px; margin-top: 36px; font-size: 13px; }
  .sign div { border-top: 1px solid #999; padding-top: 6px; }
  .note { font-size: 12px; color: #555; margin-top: 28px; }
  .bar { max-width: 780px; margin: 16px auto 0; display: flex; gap: 8px; justify-content: flex-end; }
  .bar button, .bar a { font: inherit; padding: 8px 14px; border-radius: 6px; border: 1px solid #0b5d3b; background: #0b5d3b; color: #fff; cursor: pointer; text-decoration: none; }
  .bar a { background: #fff; color: #0b5d3b; }
  @media print { body { background: #fff; } .bar { display: none; } .sheet { box-shadow: none; margin: 0; max-width: none; padding: 0; } }
</style>
</head>
<body>
<div class="bar"><a href="request.php?id=<?= $id ?>">Back</a><button type="button" onclick="window.print()">Print / Save as PDF</button></div>
<div class="sheet">
  <header>
    <img src="<?= SITE_URL ?>/assets/images/uis-logo.png" alt="UIS">
    <div><h1>UNIVERSITI ISLAM SELANGOR</h1><p>Vehicle Section, Registrar Office &middot; Driver Scheduling and Management System</p></div>
  </header>
  <p style="text-align:right;font-size:13px;margin-top:14px">Date: <?= h(date('d F Y', strtotime($r['hoa_approved_at'] ?? 'now'))) ?></p>
  <p style="font-size:14px"><?= h($r['requester_name']) ?><br><?= h($r['department_name'] ?? '') ?></p>
  <h2>APPROVAL OF VEHICLE USE<?= $r['purpose_type'] === 'personal' ? ' (PERSONAL PURPOSE)' : ($r['driver_option'] === 'pay_claim' ? ' (PAY AND CLAIM)' : '') ?></h2>
  <p style="font-size:14px">The vehicle request below has been approved.<?= $r['driver_option'] === 'pay_claim' ? ' You may use your own car and claim the mileage from the Bursary.' : '' ?><?= $r['purpose_type'] === 'personal' ? ' The charge is paid to the Bursary.' : '' ?></p>
  <table>
    <?php foreach ($rows as $k => $val): ?><tr><td><?= h($k) ?></td><td><?= h($val) ?></td></tr><?php endforeach; ?>
    <?php if ($vehicles): ?><tr><td>Vehicle assigned</td><td><?= h(implode(', ', array_map(fn($v) => $v['plate_number'] . ' ' . $v['brand'] . ' ' . $v['model'], array_filter($vehicles, fn($v) => $v['vehicle_id'])))) ?></td></tr><?php endif; ?>
  </table>
  <div class="sign">
    <?php if ($r['purpose_type'] !== 'personal'): ?>
    <div><strong>Approved by the Head of Department</strong><br><?= h($r['hod_approved_name'] ?? '-') ?><br><?= h(fmtDT($r['hod_approved_at'])) ?></div>
    <?php else: ?>
    <div><strong>Charge confirmed by the Administrative Assistant</strong><br><?= h(fmtDT($r['charge_confirmed_at'] ?? $r['assigned_at'])) ?></div>
    <?php endif; ?>
    <div><strong>Approved by the Head of Admin</strong><br><?= h($r['hoa_approved_name'] ?? '-') ?><br><?= h(fmtDT($r['hoa_approved_at'])) ?></div>
  </div>
  <p class="note">This letter is generated by the system and does not need a signature. The claim payment and the rental payment are handled by the Bursary outside the system.</p>
</div>
</body>
</html>
