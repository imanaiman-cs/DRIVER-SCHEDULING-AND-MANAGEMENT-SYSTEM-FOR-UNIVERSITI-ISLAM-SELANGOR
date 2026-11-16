<?php
// ============================================================
// profile.php  -  UC016 Manage Profile (every role): view the own profile, update the name, phone number and address,
// change the password. The staff ID, UIS e-mail, department and role cannot be changed here. A driver also sees the licence class,
// licence expiry date and start date of service, which the Administrative Assistant updates in Edit Driver.
// ============================================================
$current_page = 'profile.php';
require_once 'config/database.php';
require_once 'includes/admin_page.php';
require_once 'admin/fleet_helpers.php';          // licenceBadges()
requireLogin();

$uid = (int)$_SESSION['user_id'];
$stmt = $conn->prepare('SELECT u.*, (SELECT dn.department_name FROM departments dn WHERE dn.department_id = u.department_id) AS department FROM users u WHERE u.user_id = ?');
$stmt->bind_param('i', $uid);
$stmt->execute();
$me = $stmt->get_result()->fetch_assoc();
$stmt->close();
$driver = null;
if ($me['driver_id'] !== null) {
    $q = $conn->prepare('SELECT * FROM drivers WHERE driver_id = ?');
    $did = (int)$me['driver_id'];
    $q->bind_param('i', $did);
    $q->execute();
    $driver = $q->get_result()->fetch_assoc();
    $q->close();
}
$errors = [];
$v = ['full_name' => $me['full_name'], 'phone' => (string)$me['phone'], 'address' => (string)$me['address']];
$mode = isset($_GET['edit']) ? 'edit' : (isset($_GET['password']) ? 'password' : 'view');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)($_POST['action'] ?? '');
    if ($act === 'update') {                                                         // A1
        $mode = 'edit';
        $v = ['full_name' => preg_replace('/\s+/', ' ', trim((string)($_POST['full_name'] ?? ''))), 'phone' => trim((string)($_POST['phone'] ?? '')), 'address' => trim((string)($_POST['address'] ?? ''))];
        if ($v['full_name'] === '' || $v['phone'] === '' || $v['address'] === '') { $errors['all'] = 'Please fill in all required fields: the name, the phone number and the address.'; }   // A1.1
        else {
            if (mb_strlen($v['full_name']) < 3 || mb_strlen($v['full_name']) > 100) { $errors['full_name'] = 'Enter the full name (3 to 100 characters).'; }
            if (!preg_match('/^[0-9 +()\-]{7,20}$/', $v['phone'])) { $errors['phone'] = 'Enter a valid phone number (digits, spaces, + - and brackets, 7 to 20 characters).'; }
            if (mb_strlen($v['address']) > 500) { $errors['address'] = 'The address can have at most 500 characters.'; }
        }
        if (!$errors) {
            $stmt = $conn->prepare('UPDATE users SET full_name = ?, phone = ?, address = ? WHERE user_id = ?');
            $stmt->bind_param('sssi', $v['full_name'], $v['phone'], $v['address'], $uid);
            $stmt->execute();
            $stmt->close();
            if ($driver) {                                                           // the driver record keeps the same name, phone and address
                $stmt = $conn->prepare('UPDATE drivers SET name = ?, phone = ?, address = ? WHERE driver_id = ?');
                $stmt->bind_param('sssi', $v['full_name'], $v['phone'], $v['address'], $did);
                $stmt->execute();
                $stmt->close();
            }
            $_SESSION['full_name'] = $v['full_name'];
            auditLog($conn, 'profile_update', null, 'Own profile updated (' . $me['username'] . ')');
            setFlash('success', 'Your profile was updated.');
            header('Location: profile.php');
            exit();
        }
    } elseif ($act === 'password') {                                                 // A2
        $mode = 'password';
        $current = (string)($_POST['current_password'] ?? '');
        $new = (string)($_POST['new_password'] ?? '');
        if (!verifyPassword($current, $me['password']) || $new !== (string)($_POST['confirm_password'] ?? '')) {
            $errors['password'] = 'The current password is wrong or the new passwords do not match.';                                 // A2.1
        } elseif (($problem = passwordProblem($new)) !== null) {
            $errors['password'] = $problem;
        } elseif ($new === $current) {
            $errors['password'] = 'The new password must be different from the current one.';
        } else {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $stmt = $conn->prepare('UPDATE users SET password = ? WHERE user_id = ?');
            $stmt->bind_param('si', $hash, $uid);
            $stmt->execute();
            $stmt->close();
            auditLog($conn, 'password_changed', null, 'Own password changed');
            setFlash('success', 'Your password was changed.');
            header('Location: profile.php');
            exit();
        }
    }
}
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
adminPageStart('My Profile');
?>
    <h1 class="text-2xl font-medium mb-3">My profile</h1>
    <?php if (!empty($errors['all'])): ?><div class="alert alert-soft alert-error mb-4"><?= $h($errors['all']) ?></div><?php endif; ?>
    <div class="card border border-base-300 bg-base-100 mb-3"><div class="card-body">
    <?php if ($mode === 'edit'): ?>
        <form method="post" novalidate><input type="hidden" name="action" value="update">
            <div class="grid-12 grid grid-cols-12 gap-4">
                <div class="col-span-12"><label class="mb-1 block text-sm font-medium" for="full_name">Name *</label><input class="input w-full" id="full_name" name="full_name" maxlength="100" required value="<?= $h($v['full_name']) ?>">
                    <?= isset($errors['full_name']) ? '<div class="mt-1 block text-xs text-error">' . $h($errors['full_name']) . '</div>' : '' ?></div>
                <div class="col-span-12 md:col-span-6"><label class="mb-1 block text-sm font-medium" for="phone">Phone number *</label><input class="input w-full" id="phone" name="phone" maxlength="20" required value="<?= $h($v['phone']) ?>">
                    <?= isset($errors['phone']) ? '<div class="mt-1 block text-xs text-error">' . $h($errors['phone']) . '</div>' : '' ?></div>
                <div class="col-span-12"><label class="mb-1 block text-sm font-medium" for="address">Address *</label><textarea class="textarea w-full" id="address" name="address" rows="2" maxlength="500" required><?= $h($v['address']) ?></textarea>
                    <?= isset($errors['address']) ? '<div class="mt-1 block text-xs text-error">' . $h($errors['address']) . '</div>' : '' ?></div>
            </div>
            <p class="text-sm text-base-content/60 mt-3 mb-0">The staff ID, UIS e-mail, department and role cannot be changed here.</p>
            <div class="mt-3 flex gap-2"><button class="btn btn-success" type="submit">Save</button><a class="btn btn-outline" href="profile.php">Cancel</a></div>
        </form>
    <?php else: ?>
        <dl class="grid-12 grid grid-cols-12 gap-x-6 gap-y-1 mb-0">
            <dt class="col-span-12 sm:col-span-4">Staff ID</dt><dd class="col-span-12 sm:col-span-8"><?= $h($me['username']) ?></dd>
            <dt class="col-span-12 sm:col-span-4">Name</dt><dd class="col-span-12 sm:col-span-8"><?= $h($me['full_name']) ?></dd>
            <dt class="col-span-12 sm:col-span-4">UIS e-mail</dt><dd class="col-span-12 sm:col-span-8"><?= $h($me['email']) ?></dd>
            <dt class="col-span-12 sm:col-span-4">Phone number</dt><dd class="col-span-12 sm:col-span-8"><?= $h($me['phone'] ?: '-') ?></dd>
            <dt class="col-span-12 sm:col-span-4">Address</dt><dd class="col-span-12 sm:col-span-8"><?= $h($me['address'] ?: '-') ?></dd>
            <dt class="col-span-12 sm:col-span-4">Department</dt><dd class="col-span-12 sm:col-span-8"><?= $h($me['department'] ?: '-') ?></dd>
            <dt class="col-span-12 sm:col-span-4">Role</dt><dd class="col-span-12 sm:col-span-8"><?= $h(ACCOUNT_ROLES[$me['role']] ?? $me['role']) ?></dd>
            <?php if ($driver): ?>
            <dt class="col-span-12 sm:col-span-4">Licence class</dt><dd class="col-span-12 sm:col-span-8"><?= licenceBadges($driver['license_class']) ?></dd>
            <dt class="col-span-12 sm:col-span-4">Licence expiry date</dt><dd class="col-span-12 sm:col-span-8"><?= $driver['license_expiry'] ? $h(date('d M Y', strtotime($driver['license_expiry']))) : '-' ?></dd>
            <dt class="col-span-12 sm:col-span-4">Start date of service</dt><dd class="col-span-12 sm:col-span-8"><?= $driver['date_joined'] ? $h(date('d M Y', strtotime($driver['date_joined']))) : '-' ?></dd>
            <?php endif; ?>
        </dl>
        <?php if ($driver): ?><p class="text-sm text-base-content/60 mt-3 mb-0">Your licence details are updated by the Vehicle Section.</p><?php endif; ?>
        <div class="mt-3 flex flex-wrap gap-2"><a class="btn btn-success" href="profile.php?edit=1"><i class="fas fa-pen me-1"></i>Edit</a><a class="btn btn-outline" href="profile.php?password=1"><i class="fas fa-key me-1"></i>Change password</a></div>
    <?php endif; ?>
    </div></div>
    <?php if ($mode === 'password'): ?>
    <div class="card border border-base-300 bg-base-100"><div class="card-body">
        <h6 class="font-bold">Change password</h6>
        <?php if (!empty($errors['password'])): ?><div class="alert alert-soft alert-error mb-4"><?= $h($errors['password']) ?></div><?php endif; ?>
        <form method="post" novalidate class="grid-12 grid grid-cols-12 gap-4"><input type="hidden" name="action" value="password">
            <div class="col-span-12 md:col-span-4"><label class="mb-1 block text-sm font-medium" for="current_password">Current password *</label><input type="password" class="input w-full" id="current_password" name="current_password" required autocomplete="current-password"></div>
            <div class="col-span-12 md:col-span-4"><label class="mb-1 block text-sm font-medium" for="new_password">New password *</label><input type="password" class="input w-full" id="new_password" name="new_password" required autocomplete="new-password" maxlength="72"></div>
            <div class="col-span-12 md:col-span-4"><label class="mb-1 block text-sm font-medium" for="confirm_password">Confirm password *</label><input type="password" class="input w-full" id="confirm_password" name="confirm_password" required autocomplete="new-password" maxlength="72"></div>
            <div class="col-span-12"><button class="btn btn-success" type="submit">Save</button> <a class="btn btn-outline" href="profile.php">Cancel</a></div>
        </form>
    </div></div>
    <?php endif; ?>
<?php adminPageEnd(); ?>
