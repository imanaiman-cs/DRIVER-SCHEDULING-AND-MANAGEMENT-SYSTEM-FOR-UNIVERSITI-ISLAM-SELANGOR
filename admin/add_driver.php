<?php
// ============================================================
// admin/add_driver.php  -  UC001 Add Driver
// User accounts come from the UIS staff system. The administrator picks an EXISTING account with the Driver role
// that is not linked to a driver record yet, and ticks the licence classes.
// ============================================================
$current_page = 'add_driver.php';
require_once '../config/database.php';
require_once '../includes/admin_page.php';
require_once '../includes/upload.php';
require_once 'fleet_helpers.php';
requireAdmin();

$sel = (int)($_POST['user_id'] ?? $_GET['user_id'] ?? 0);
$v = ['license_class' => '', 'license_number' => '', 'license_expiry' => '', 'address' => '', 'phone' => ''];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    [$v, $errors] = driverInput($_POST, false);
    if ($sel <= 0) { $errors['user_id'] = 'Select the driver account.'; }
    $photo = null;
    if (!$errors) {
        $up = saveUploadedImage($_FILES['photo'] ?? ['error' => UPLOAD_ERR_NO_FILE], 'drivers');
        if (!$up['ok']) { $errors['photo'] = $up['error']; } else { $photo = $up['path']; }
    }
    if (!$errors) {
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("SELECT username, full_name, email, phone, driver_id FROM users WHERE user_id = ? AND role = 'driver' AND is_active = 1 FOR UPDATE");
            $stmt->bind_param('i', $sel);
            $stmt->execute();
            $u = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$u) {
                $errors['user_id'] = 'This account does not exist, is not active or does not have the Driver role.';
            } elseif ($u['driver_id'] !== null) {
                $errors['user_id'] = $u['full_name'] . ' is already linked to a driver record.';
            }
            if (!$errors) {
                $emp = strtoupper($u['username']);
                $phone = $u['phone'] !== null ? mb_substr($u['phone'], 0, 20) : null;
                $num = $v['license_number'] === '' ? null : $v['license_number'];
                $exp = $v['license_expiry'] === '' ? null : $v['license_expiry'];
                $addr = $v['address'] === '' ? null : $v['address'];
                $stmt = $conn->prepare("INSERT INTO drivers (employee_id, name, phone, email, address, license_number, license_class, license_expiry, status, photo)
                                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', ?)");
                $stmt->bind_param('sssssssss', $emp, $u['full_name'], $phone, $u['email'], $addr, $num, $v['license_class'], $exp, $photo);
                $stmt->execute();
                $new = (int)$conn->insert_id;
                $stmt->close();
                $stmt = $conn->prepare('UPDATE users SET driver_id = ? WHERE user_id = ? AND driver_id IS NULL');
                $stmt->bind_param('ii', $new, $sel);
                $stmt->execute();
                $linked = $stmt->affected_rows === 1;
                $stmt->close();
                if (!$linked) { throw new RuntimeException('link'); }
                $conn->commit();
                auditLog($conn, 'driver_add', null, $u['full_name'] . ' (' . $v['license_class'] . ')');
                setFlash('success', $u['full_name'] . ' was added as a driver.');
                header('Location: view_driver.php?id=' . $new);
                exit();
            }
            $conn->rollback();
        } catch (Throwable $e) {
            $conn->rollback();
            if ($e instanceof mysqli_sql_exception && (int)$e->getCode() === 1062) { $errors['user_id'] = 'A driver record with staff no. ' . strtoupper($u['username'] ?? '') . ' already exists.'; }
            else { error_log('add_driver: ' . $e->getMessage()); $errors['_'] = 'Database error. Please try again.'; }
        }
        if ($errors) { deleteUploadedImage($photo); }
    }
}

$cands = $conn->query("SELECT user_id, username, full_name, email FROM users WHERE role = 'driver' AND is_active = 1 AND driver_id IS NULL ORDER BY full_name")->fetch_all(MYSQLI_ASSOC);
adminPageStart('Add Driver');
?>
    <h1 class="text-2xl font-extrabold tracking-tight mb-1"><i class="fas fa-plus me-2 text-[0.8em] text-primary"></i>Add Driver</h1>
    <p class="text-base-content/60 text-sm">Choose an existing account that has the Driver role in the UIS staff system. The name and e-mail are taken from that account.</p>
    <?php if ($errors): ?><div class="alert alert-soft alert-error" role="alert">Please correct the highlighted fields.<?= isset($errors['_']) ? ' ' . htmlspecialchars($errors['_']) : '' ?></div><?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="card border border-base-300 bg-base-100" novalidate><div class="card-body">
        <div class="mb-4"><label class="mb-1 block text-sm font-medium" for="user_id">Driver account <span class="text-error">*</span></label>
            <select class="select w-full" id="user_id" name="user_id" required><option value="">Choose...</option>
                <?php foreach ($cands as $c): ?><option value="<?= (int)$c['user_id'] ?>" <?= $sel === (int)$c['user_id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['full_name'] . ' (' . $c['username'] . ', ' . $c['email'] . ')') ?></option><?php endforeach; ?>
            </select>
            <?php if (isset($errors['user_id'])): ?><div class="mt-1 block text-xs text-error"><?= htmlspecialchars($errors['user_id']) ?></div><?php endif; ?>
            <?php if (!$cands): ?><div class="mt-1 block text-xs text-base-content/60 text-warning">There is no driver account waiting to be registered. New accounts arrive from the staff system import.</div><?php endif; ?></div>
        <?php driverFormFields($v, $errors, false); ?>
        <div class="mt-6 flex gap-2"><button class="btn btn-success" type="submit">Save driver</button><a class="btn btn-outline" href="drivers.php">Cancel</a></div>
    </div></form>
<?php adminPageEnd(); ?>
