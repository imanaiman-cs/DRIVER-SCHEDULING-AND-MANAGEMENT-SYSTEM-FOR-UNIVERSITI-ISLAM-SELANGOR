<?php
// ============================================================
// admin/add_driver.php  -  Register Driver (SRS 10 Nov UC001 step 3)
// The Assistant enters the staff ID, name, UIS e-mail, phone, licence class, licence expiry date and start date of service. The system
// creates the Driver account with a default password and e-mails the login details to the driver's UIS e-mail.
// (An existing Driver account that is not linked to a driver record yet can still be linked with the second form.)
// ============================================================
$current_page = 'add_driver.php';
require_once '../config/database.php';
require_once '../includes/admin_page.php';
require_once '../includes/upload.php';
require_once 'fleet_helpers.php';
requireAdmin();

$sel = (int)($_POST['user_id'] ?? $_GET['user_id'] ?? 0);
$v = ['license_class' => '', 'license_number' => '', 'license_expiry' => '', 'date_joined' => '', 'address' => '', 'phone' => ''];
$errors = [];

$nv = ['username' => '', 'full_name' => '', 'email' => '', 'phone' => '', 'license_class' => '', 'license_number' => '', 'license_expiry' => '', 'date_joined' => '', 'address' => ''];
$nerrors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['mode'] ?? '') === 'register') {
    $in = $_POST + ['department' => 'Transport Unit', 'role' => 'driver'];
    $in['department'] = 'Transport Unit';
    [$av, $nerrors] = accountInput($conn, $in, true);
    foreach (['username', 'full_name', 'email', 'phone'] as $k) { if ($av[$k] === '' && $k !== 'phone') { $nerrors[$k] = $nerrors[$k] ?? 'Fill in this field.'; } }      // 3.2
    if ($av['phone'] === '') { $nerrors['phone'] = 'Fill in this field.'; }
    if (empty($nerrors['email']) && !isUisEmail($conn, $av['email'])) { $nerrors['email'] = 'Only a UIS e-mail address (@' . getSetting($conn, 'UIS_EMAIL_DOMAIN', 'uis.edu.my') . ') is accepted.'; }
    if (isset($nerrors['username']) && str_contains($nerrors['username'], 'already')) { $nerrors['username'] = 'This staff ID is already registered. The account already exists.'; }     // 3.1
    if (isset($nerrors['email']) && str_contains($nerrors['email'], 'already')) { $nerrors['email'] = 'This e-mail address is already registered. The account already exists.'; }
    [$dv, $de] = driverInput($_POST, false, false, true);
    if ($de) { $nerrors += $de; }
    if ($dv['license_expiry'] === '') { $nerrors['license_expiry'] = 'Fill in this field.'; }
    $nv = $av + $dv;
    $photo = null;
    if (!$nerrors) {
        $up = saveUploadedImage($_FILES['photo'] ?? ['error' => UPLOAD_ERR_NO_FILE], 'drivers');
        if (!$up['ok']) { $nerrors['photo'] = $up['error']; } else { $photo = $up['path']; }
    }
    if (!$nerrors) {
        $default = substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(12))), 0, 8) . random_int(10, 99);   // letters and digits
        $conn->begin_transaction();
        try {
            $uid = createAccount($conn, $av, 'driver', $default);
            $num = $dv['license_number'] === '' ? null : $dv['license_number'];
            $addr = $dv['address'] === '' ? null : $dv['address'];
            $phone = $av['phone'] !== '' ? $av['phone'] : null;
            $emp = strtoupper($av['username']);
            $stmt = $conn->prepare("INSERT INTO drivers (employee_id, name, phone, email, address, license_number, license_class, license_expiry, date_joined, status, photo)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?)");
            $stmt->bind_param('ssssssssss', $emp, $av['full_name'], $phone, $av['email'], $addr, $num, $dv['license_class'], $dv['license_expiry'], $dv['date_joined'], $photo);
            $stmt->execute();
            $did = (int)$conn->insert_id;
            $stmt->close();
            $stmt = $conn->prepare('UPDATE users SET driver_id = ? WHERE user_id = ?');
            $stmt->bind_param('ii', $did, $uid);
            $stmt->execute();
            $stmt->close();
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            deleteUploadedImage($photo);
            if ($e instanceof mysqli_sql_exception && (int)$e->getCode() === 1062) { $nerrors['username'] = 'This staff ID or e-mail address is already registered. The account already exists.'; }
            else { error_log('register driver: ' . $e->getMessage()); $nerrors['_'] = 'Database error. Please try again.'; }
        }
        if (!$nerrors) {
            auditLog($conn, 'driver_add', null, $av['full_name'] . ' (' . $dv['license_class'] . ') registered with a login account');
            $site = rtrim(SITE_URL, '/') . '/login.php';
            $html = '<p>Dear ' . htmlspecialchars($av['full_name']) . ',</p><p>Your driver account in the UIS Vehicle Section system is ready.</p><p>Staff ID: <strong>' . htmlspecialchars($av['username']) . '</strong><br>Default password: <strong>' . htmlspecialchars($default) . '</strong></p><p>Sign in at <a href="' . htmlspecialchars($site) . '">' . htmlspecialchars($site) . '</a>. You may change the password in your profile if you wish.</p>';
            $text = "Dear {$av['full_name']},\n\nYour driver account in the UIS Vehicle Section system is ready.\nStaff ID: {$av['username']}\nDefault password: $default\nSign in at $site. You may change the password in your profile if you wish.";
            $mail = sendMail($conn, $av['email'], '[UIS Vehicle Section] Your driver account', $html, $text, ['kind' => 'account', 'user_id' => $uid, 'driver_id' => $did]);
            setFlash('success', $av['full_name'] . ' was registered as a driver.' . (($mail['status'] ?? '') === 'sent' ? ' The login details were sent to ' . $av['email'] . '.' : ' The login details are recorded in the e-mail log (the e-mail could not be sent now).'));
            header('Location: view_driver.php?id=' . $did);
            exit();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['mode'] ?? '') !== 'register') {
    [$v, $errors] = driverInput($_POST, false, false, true);
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
                $stmt = $conn->prepare("INSERT INTO drivers (employee_id, name, phone, email, address, license_number, license_class, license_expiry, date_joined, status, photo)
                                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?)");
                $stmt->bind_param('ssssssssss', $emp, $u['full_name'], $phone, $u['email'], $addr, $num, $v['license_class'], $exp, $v['date_joined'], $photo);
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
                notifyUsers($conn, [$sel], 'You were registered as a driver of the Vehicle Section (licence classes: ' . $v['license_class'] . '). You can see your tasks in My Tasks.', 'driver/schedules.php');   // UC001 4.3
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
adminPageStart('Register Driver');
?>
    <h4 class="fw-bold mb-1"><i class="fas fa-plus text-success me-2"></i>Register Driver</h4>
    <p class="text-muted small">Register a driver: the system creates the account with a default password and e-mails the login details to the UIS e-mail address.</p>
    <?php if ($nerrors): ?><div class="alert alert-danger" role="alert">Please correct the highlighted fields.<?= isset($nerrors['_']) ? ' ' . htmlspecialchars($nerrors['_']) : '' ?></div><?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="card border-0 shadow-sm mb-4" novalidate><div class="card-body">
        <input type="hidden" name="mode" value="register">
        <?php $nerr = fn($k) => isset($nerrors[$k]) ? '<div class="invalid-feedback d-block">' . htmlspecialchars($nerrors[$k]) . '</div>' : ''; $nval = fn($k) => htmlspecialchars((string)($nv[$k] ?? '')); ?>
        <div class="row g-3 mb-1">
            <div class="col-12 col-md-4"><label class="form-label" for="username">Staff ID <span class="text-danger">*</span></label><input class="form-control" id="username" name="username" maxlength="50" value="<?= $nval('username') ?>"><?= $nerr('username') ?></div>
            <div class="col-12 col-md-8"><label class="form-label" for="full_name">Name <span class="text-danger">*</span></label><input class="form-control" id="full_name" name="full_name" maxlength="100" value="<?= $nval('full_name') ?>"><?= $nerr('full_name') ?></div>
            <div class="col-12 col-md-6"><label class="form-label" for="email">UIS e-mail <span class="text-danger">*</span></label><input type="email" class="form-control" id="email" name="email" maxlength="100" value="<?= $nval('email') ?>"><?= $nerr('email') ?></div>
            <div class="col-12 col-md-6"><label class="form-label" for="phone">Phone number <span class="text-danger">*</span></label><input class="form-control" id="phone" name="phone" maxlength="20" value="<?= $nval('phone') ?>"><?= $nerr('phone') ?></div>
        </div>
        <?php driverFormFields($nv, $nerrors, false, null, true); ?>
        <div class="mt-4 d-flex gap-2"><button class="btn btn-success" type="submit"><i class="fas fa-save me-1"></i>Register driver</button><a class="btn btn-outline-secondary" href="drivers.php">Cancel</a></div>
    </div></form>

    <h6 class="fw-bold">Link an existing driver account</h6>
    <p class="text-muted small">Choose an existing account that has the Driver role and is not linked to a driver record yet. The name and e-mail are taken from that account.</p>
    <?php if ($errors): ?><div class="alert alert-danger" role="alert">Please correct the highlighted fields.<?= isset($errors['_']) ? ' ' . htmlspecialchars($errors['_']) : '' ?></div><?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="card border-0 shadow-sm" novalidate><div class="card-body">
        <div class="mb-3"><label class="form-label" for="user_id">Driver account <span class="text-danger">*</span></label>
            <select class="form-select" id="user_id" name="user_id" required><option value="">Choose...</option>
                <?php foreach ($cands as $c): ?><option value="<?= (int)$c['user_id'] ?>" <?= $sel === (int)$c['user_id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['full_name'] . ' (' . $c['username'] . ', ' . $c['email'] . ')') ?></option><?php endforeach; ?>
            </select>
            <?php if (isset($errors['user_id'])): ?><div class="invalid-feedback d-block"><?= htmlspecialchars($errors['user_id']) ?></div><?php endif; ?>
            <?php if (!$cands): ?><div class="form-text text-warning">There is no driver account waiting to be registered. New accounts arrive from the staff system import.</div><?php endif; ?></div>
        <?php driverFormFields($v, $errors, false, null, true); ?>
        <div class="mt-4 d-flex gap-2"><button class="btn btn-success" type="submit">Save driver</button><a class="btn btn-outline-secondary" href="drivers.php">Cancel</a></div>
    </div></form>
<?php adminPageEnd(); ?>
