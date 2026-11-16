<?php
// ============================================================
// admin/edit_driver.php  -  UC003 A1 Update Driver (by the Assistant / Head of Admin; the driver is told): licence classes, phone, address, licence number / expiry, photo
// Name and e-mail come from the UIS staff system, so they are read-only here.
// ============================================================
$current_page = 'edit_driver.php';
require_once '../config/database.php';
require_once '../includes/admin_page.php';
require_once '../includes/upload.php';
require_once 'fleet_helpers.php';
requireAdmin();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = $conn->prepare('SELECT * FROM drivers WHERE driver_id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$cur = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$cur) { setFlash('danger', 'Driver not found.'); header('Location: drivers.php'); exit(); }

$v = ['license_class' => normaliseLicenceList(explode(',', (string)$cur['license_class'])), 'license_number' => (string)$cur['license_number'],
      'license_expiry' => (string)$cur['license_expiry'], 'date_joined' => (string)$cur['date_joined'], 'address' => (string)$cur['address'], 'phone' => (string)$cur['phone']];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    [$v, $errors] = driverInput($_POST, true, true, true);      // the Assistant / Head of Admin also edit the date joined (UC003 A1)
    $photo = $cur['photo']; $newPhoto = null;
    if (!$errors) {
        $up = saveUploadedImage($_FILES['photo'] ?? ['error' => UPLOAD_ERR_NO_FILE], 'drivers');
        if (!$up['ok']) { $errors['photo'] = $up['error']; } elseif ($up['path']) { $newPhoto = $up['path']; $photo = $newPhoto; }
    }
    if (!$errors) {
        $num = $v['license_number'] === '' ? null : $v['license_number'];
        $exp = $v['license_expiry'] === '' ? null : $v['license_expiry'];
        $addr = $v['address'] === '' ? null : $v['address'];
        $phone = $v['phone'] === '' ? null : $v['phone'];
        $stmt = $conn->prepare('UPDATE drivers SET license_class = ?, phone = ?, address = ?, license_number = ?, license_expiry = ?, date_joined = ?, photo = ? WHERE driver_id = ?');
        $stmt->bind_param('sssssssi', $v['license_class'], $phone, $addr, $num, $exp, $v['date_joined'], $photo, $id);
        $stmt->execute();
        $stmt->close();
        if ($newPhoto) { deleteUploadedImage($cur['photo']); }
        auditLog($conn, 'driver_update', null, $cur['name'] . ' (' . $v['license_class'] . ')');
        if ($du = userIdOfDriver($conn, $id)) {      // UC003 A1: the driver is told
            notifyUsers($conn, [$du], 'Your driver profile was updated by the Vehicle Section (licence classes: ' . $v['license_class'] . ').', 'driver/profile.php');
        }
        setFlash('success', $cur['name'] . ' was updated.');
        header('Location: view_driver.php?id=' . $id);
        exit();
    }
}
adminPageStart('Edit Driver');
?>
    <h1 class="text-2xl font-medium mb-4">Edit Driver</h1>
    <?php if ($errors): ?><div class="alert alert-soft alert-error mb-4" role="alert">Please correct the highlighted fields.</div><?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="card border border-base-300 bg-base-100" novalidate><div class="card-body">
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="grid-12 grid grid-cols-12 gap-4 mb-4">
            <div class="col-span-12 md:col-span-4"><label class="mb-1 block text-sm font-medium" for="r_staff">Staff no.</label><input class="input w-full" id="r_staff" value="<?= htmlspecialchars($cur['employee_id']) ?>" readonly></div>
            <div class="col-span-12 md:col-span-4"><label class="mb-1 block text-sm font-medium" for="r_name">Name</label><input class="input w-full" id="r_name" value="<?= htmlspecialchars($cur['name']) ?>" readonly></div>
            <div class="col-span-12 md:col-span-4"><label class="mb-1 block text-sm font-medium" for="r_mail">E-mail</label><input class="input w-full" id="r_mail" value="<?= htmlspecialchars((string)$cur['email']) ?>" readonly></div>
            <div class="col-span-12 mt-1 block text-xs text-base-content/60 mt-0">Name and e-mail come from the UIS staff system.</div>
        </div>
        <?php driverFormFields($v, $errors, true, $cur['photo'], true); ?>
        <div class="mt-4 flex gap-2"><button class="btn btn-success" type="submit">Save changes</button><a class="btn btn-outline" href="view_driver.php?id=<?= $id ?>">Cancel</a></div>
    </div></form>
<?php adminPageEnd(); ?>
