<?php
// ============================================================
// UIS Driver Scheduling and Management System
// driver/profile.php  -  UC002 View Driver Profile + UC003 Update Driver Profile (by the driver)
//
// A driver sees and updates ONLY their own profile: phone number, licence classes (D, GDL, PSV) and licence expiry date.
// The name and staff ID come from the UIS staff system and cannot be changed here. The Administrative Assistant is told
// (in the system) when the licence classes change. Exceptions: no licence class ticked, or a licence expiry date that
// has already passed, are refused and nothing is saved.
// ============================================================
$current_page = 'profile.php';
require_once '../config/database.php';
require_once '../includes/admin_page.php';
require_once '../admin/fleet_helpers.php';
requireDriver();

$driver_id = (int)($_SESSION['driver_id'] ?? 0);
$stmt = $conn->prepare('SELECT * FROM drivers WHERE driver_id = ?');
$stmt->bind_param('i', $driver_id);
$stmt->execute();
$me = $driver_id > 0 ? $stmt->get_result()->fetch_assoc() : null;
$stmt->close();

$editing = isset($_GET['edit']) || $_SERVER['REQUEST_METHOD'] === 'POST';
$errors = [];
$v = $me ? ['license_class' => normaliseLicenceList(explode(',', (string)$me['license_class'])), 'license_expiry' => (string)$me['license_expiry'], 'phone' => (string)$me['phone']] : [];

if ($me && $_SERVER['REQUEST_METHOD'] === 'POST') {
    [$in, $errors] = driverInput($_POST, true, true);
    $v = ['license_class' => $in['license_class'], 'license_expiry' => $in['license_expiry'], 'phone' => $in['phone']];
    if (!$errors) {
        $phone = $in['phone'] === '' ? null : $in['phone'];
        $exp = $in['license_expiry'] === '' ? null : $in['license_expiry'];
        $stmt = $conn->prepare('UPDATE drivers SET license_class = ?, phone = ?, license_expiry = ? WHERE driver_id = ?');
        $stmt->bind_param('sssi', $in['license_class'], $phone, $exp, $driver_id);
        $stmt->execute();
        $stmt->close();
        auditLog($conn, 'driver_update', null, $me['name'] . ' updated their own profile (' . $in['license_class'] . ')');
        if (normaliseLicenceList(explode(',', (string)$me['license_class'])) !== $in['license_class']) {      // UC003 3.3
            notifyUsers($conn, assistantUserIds($conn), $me['name'] . ' changed the licence classes: ' . ($me['license_class'] ?: 'none') . ' -> ' . $in['license_class'] . '.', 'admin/view_driver.php?id=' . $driver_id);
        }
        setFlash('success', 'Your profile was updated.');
        header('Location: profile.php');
        exit();
    }
}

adminPageStart('My Profile');
?>
    <h4 class="fw-bold mb-3"><i class="fas fa-id-card text-success me-2"></i>My Profile</h4>
    <?php if (!$me): ?>
        <div class="alert alert-warning">Your driver profile was not found. Please contact the Vehicle Section.</div>
    <?php elseif (!$editing): ?>
        <div class="card border-0 shadow-sm"><div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-4">Name</dt><dd class="col-sm-8"><?= htmlspecialchars($me['name']) ?></dd>
                <dt class="col-sm-4">Staff ID</dt><dd class="col-sm-8"><?= htmlspecialchars($me['employee_id']) ?></dd>
                <dt class="col-sm-4">Phone number</dt><dd class="col-sm-8"><?= htmlspecialchars((string)$me['phone'] ?: '-') ?></dd>
                <dt class="col-sm-4">Licence classes</dt><dd class="col-sm-8"><?= licenceBadges($me['license_class']) ?></dd>
                <dt class="col-sm-4">Licence expiry date</dt><dd class="col-sm-8"><?= $me['license_expiry'] ? date('d M Y', strtotime($me['license_expiry'])) : '-' ?></dd>
            </dl>
            <a class="btn btn-success mt-3" href="profile.php?edit=1"><i class="fas fa-pen me-1"></i>Update</a>
            <div class="form-text mt-2">Your name and staff ID come from the UIS staff system.</div>
        </div></div>
    <?php else: ?>
        <?php if ($errors): ?><div class="alert alert-danger" role="alert"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
        <form method="post" class="card border-0 shadow-sm" novalidate><div class="card-body">
            <div class="row g-3 mb-3">
                <div class="col-12 col-md-6"><label class="form-label" for="r_name">Name</label><input class="form-control" id="r_name" value="<?= htmlspecialchars($me['name']) ?>" readonly></div>
                <div class="col-12 col-md-6"><label class="form-label" for="r_staff">Staff ID</label><input class="form-control" id="r_staff" value="<?= htmlspecialchars($me['employee_id']) ?>" readonly></div>
            </div>
            <?php $have = array_filter(explode(',', (string)($v['license_class'] ?? ''))); ?>
            <div class="row g-3">
                <div class="col-12"><span class="form-label d-block">Licence classes <span class="text-danger">*</span></span>
                    <?php foreach (LICENCE_CLASSES as $k => $l): ?>
                        <div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="license_class[]" id="lc_<?= $k ?>" value="<?= $k ?>" <?= in_array($k, $have, true) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="lc_<?= $k ?>"><?= htmlspecialchars($l) ?></label></div>
                    <?php endforeach; ?></div>
                <div class="col-12 col-md-6"><label class="form-label" for="phone">Phone number</label><input class="form-control" id="phone" name="phone" maxlength="20" value="<?= htmlspecialchars((string)($v['phone'] ?? '')) ?>"></div>
                <div class="col-12 col-md-6"><label class="form-label" for="license_expiry">Licence expiry date</label><input type="date" class="form-control" id="license_expiry" name="license_expiry" value="<?= htmlspecialchars((string)($v['license_expiry'] ?? '')) ?>"></div>
            </div>
            <div class="mt-4 d-flex gap-2"><button class="btn btn-success" type="submit">Save</button><a class="btn btn-outline-secondary" href="profile.php">Cancel</a></div>
        </div></form>
    <?php endif; ?>
<?php adminPageEnd(); ?>
