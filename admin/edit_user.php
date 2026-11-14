<?php
// ============================================================
// admin/edit_user.php  -  Update Staff and Driver Account (SRS 14 Nov UC001.3)
// The Administrative Assistant / Head of Admin updates the department and role of a staff or driver account and, for a driver, the licence class,
// the licence expiry date and the start date of service. The user is told by e-mail (and in the system). Name, phone, address and password are
// changed by the user in Manage Profile (UC016). Only the Head of Admin changes Administrative Assistant and Head of Admin accounts.
// ============================================================
$current_page = 'edit_user.php';
require_once '../config/database.php';
require_once '../includes/admin_page.php';
require_once 'fleet_helpers.php';
requireAdmin();

$me = (int)$_SESSION['user_id'];
$actorRole = (string)$_SESSION['role'];
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = $conn->prepare('SELECT * FROM users WHERE user_id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$u = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$u) { setFlash('danger', 'That account was not found.'); header('Location: users.php'); exit(); }
if ($actorRole !== 'head_admin' && in_array($u['role'], ['admin', 'head_admin'], true)) {
    setFlash('danger', 'Only the Head of Admin can change an Administrative Assistant or Head of Admin account.');
    header('Location: users.php');
    exit();
}
$drv = null;
if ($u['driver_id'] !== null) {
    $q = $conn->prepare('SELECT * FROM drivers WHERE driver_id = ?');
    $q->bind_param('i', $u['driver_id']);
    $q->execute();
    $drv = $q->get_result()->fetch_assoc();
    $q->close();
}

$roles = assignableRoles($actorRole);
if (!isset($roles[$u['role']])) { $roles[$u['role']] = ACCOUNT_ROLES[$u['role']]; }
$lockRole = '';
if ($id === $me) { $lockRole = 'You cannot change your own role.'; }
elseif ($drv) { $lockRole = 'This account is linked to a driver record, so the role stays Driver.'; }
$departments = departmentChoices($conn);
$v = ['department' => (string)$u['department'], 'role' => $u['role'],
      'license_class' => $drv ? (string)$drv['license_class'] : '', 'license_expiry' => $drv ? (string)$drv['license_expiry'] : '', 'date_joined' => $drv ? (string)$drv['date_joined'] : ''];
$errors = [];
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $v['department'] = trim((string)($_POST['department'] ?? ''));
    $v['role'] = $lockRole !== '' ? $u['role'] : (string)($_POST['role'] ?? '');
    if ($v['department'] === '' || mb_strlen($v['department']) > 100) { $errors['department'] = 'Fill in the department.'; }                  // 3.1
    if ($lockRole === '' && !isset($roles[$v['role']])) { $errors['role'] = 'You cannot give this role.'; }
    if ($drv) {
        [$dv, $de] = driverInput($_POST, false, false, true);
        $v = array_merge($v, ['license_class' => $dv['license_class'], 'license_expiry' => $dv['license_expiry'], 'date_joined' => $dv['date_joined']]);
        $errors += $de;
        if ($dv['license_expiry'] === '') { $errors['license_expiry'] = 'Fill in the licence expiry date.'; }
    }
    if (empty($errors['role']) && $v['role'] === 'supervisor' && $v['department'] !== '' && ($hod = headOfDepartmentOf($conn, $v['department'], $id))) {
        $errors['role'] = 'The department already has a Head of Department (' . $hod['full_name'] . ').';   // 3.2
    }
    $oldDept = (string)$u['department'];
    $movesAway = $u['role'] === 'supervisor' && ($v['role'] !== 'supervisor' || $v['department'] !== $oldDept) && (int)$u['is_active'] === 1;
    if (!$errors && $movesAway && pendingRequestsOfApprover($conn, $id) > 0) {
        $errors['role'] = 'This Head of Department has pending requests. Handle the pending requests first.';
    }
    if (!$errors) {
        $stmt = $conn->prepare('UPDATE users SET department = ?, role = ? WHERE user_id = ?');
        $stmt->bind_param('ssi', $v['department'], $v['role'], $id);
        $stmt->execute(); $stmt->close();
        if ($v['role'] === 'supervisor') {
            $stmt = $conn->prepare('UPDATE users SET supervisor_id = NULL WHERE user_id = ?'); $stmt->bind_param('i', $id); $stmt->execute(); $stmt->close();
        } else {
            $ap = approverIdForDepartment($conn, $v['department'], $v['role']);
            $stmt = $conn->prepare('UPDATE users SET supervisor_id = ? WHERE user_id = ?'); $stmt->bind_param('ii', $ap, $id); $stmt->execute(); $stmt->close();
        }
        refreshApprovers($conn, $oldDept);
        refreshApprovers($conn, $v['department']);
        $changes = [];
        if ($oldDept !== $v['department']) { $changes[] = 'department'; }
        if ($u['role'] !== $v['role']) { $changes[] = 'role'; }
        if ($drv) {
            $stmt = $conn->prepare('UPDATE drivers SET license_class = ?, license_expiry = ?, date_joined = ? WHERE driver_id = ?');
            $stmt->bind_param('sssi', $v['license_class'], $v['license_expiry'], $v['date_joined'], $drv['driver_id']);
            $stmt->execute(); $stmt->close();
            foreach (['license_class' => 'licence class', 'license_expiry' => 'licence expiry date', 'date_joined' => 'start date of service'] as $k => $lbl) {
                if ((string)$drv[$k] !== (string)$v[$k]) { $changes[] = $lbl; }
            }
        }
        auditLog($conn, 'user_update', null, $u['full_name'] . ' (' . $u['username'] . ') changed: ' . ($changes ? implode(', ', $changes) : 'nothing'));
        if ($changes) { notifyUsers($conn, [$id], 'The Vehicle Section updated your account: ' . implode(', ', $changes) . '.', 'profile.php'); }      // UC001.3 step 3.3: e-mail to the user
        setFlash('success', 'The account of ' . $u['full_name'] . ' was updated.' . ($changes ? ' The user was told by e-mail.' : ''));
        header('Location: users.php');
        exit();
    }
}
$err = fn($k) => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . $h($errors[$k]) . '</div>' : '';   // after the checks: an arrow function copies $errors when it is created
adminPageStart('Update account');
$have = array_filter(explode(',', (string)$v['license_class']));
?>
    <h4 class="fw-bold mb-1"><i class="fas fa-user-pen text-success me-2"></i>Update account <span class="badge <?= (int)$u['is_active'] ? 'bg-success' : 'bg-secondary' ?> ms-2"><?= (int)$u['is_active'] ? 'Active' : 'Inactive' ?></span></h4>
    <p class="text-muted small"><?= $h($u['full_name']) ?> &middot; <?= $h($u['username']) ?> &middot; <?= $h($u['email']) ?>. The name, phone, address and password are changed by the user in the profile.</p>
    <?php if ($errors): ?><div class="alert alert-danger" role="alert">Please correct the highlighted fields.</div><?php endif; ?>
    <div class="card border-0 shadow-sm"><div class="card-body">
        <form method="post" novalidate>
            <input type="hidden" name="id" value="<?= $id ?>">
            <div class="row g-3">
                <div class="col-12 col-md-6"><label class="form-label" for="department">Department <span class="text-danger">*</span></label>
                    <input class="form-control" id="department" name="department" maxlength="100" list="deptList" required value="<?= $h($v['department']) ?>"><?= $err('department') ?>
                    <datalist id="deptList"><?php foreach ($departments as $d): ?><option value="<?= $h($d) ?>"><?php endforeach; ?></datalist></div>
                <div class="col-12 col-md-6"><label class="form-label" for="role">Role <span class="text-danger">*</span></label>
                    <select class="form-select" id="role" name="role" <?= $lockRole ? 'disabled' : 'required' ?>>
                        <?php foreach ($roles as $k => $lbl): ?><option value="<?= $k ?>" <?= $v['role'] === $k ? 'selected' : '' ?>><?= $h($lbl) ?></option><?php endforeach; ?></select>
                    <?php if ($lockRole): ?><input type="hidden" name="role" value="<?= $h($v['role']) ?>"><div class="form-text"><?= $h($lockRole) ?></div><?php endif; ?><?= $err('role') ?></div>
                <?php if ($drv): ?>
                <div class="col-12"><span class="form-label d-block">Licence class <span class="text-danger">*</span></span>
                    <?php foreach (LICENCE_CLASSES as $k => $l): ?><div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="license_class[]" id="lc_<?= $k ?>" value="<?= $k ?>" <?= in_array($k, $have, true) ? 'checked' : '' ?>><label class="form-check-label" for="lc_<?= $k ?>"><?= $h($l) ?></label></div><?php endforeach; ?><?= $err('license_class') ?></div>
                <div class="col-12 col-md-6"><label class="form-label" for="license_expiry">Licence expiry date <span class="text-danger">*</span></label><input type="date" class="form-control" id="license_expiry" name="license_expiry" value="<?= $h($v['license_expiry']) ?>"><?= $err('license_expiry') ?></div>
                <div class="col-12 col-md-6"><label class="form-label" for="date_joined">Start date of service <span class="text-danger">*</span></label><input type="date" class="form-control" id="date_joined" name="date_joined" max="<?= date('Y-m-d') ?>" value="<?= $h($v['date_joined']) ?>"><?= $err('date_joined') ?></div>
                <?php endif; ?>
            </div>
            <div class="mt-4 d-flex gap-2"><button class="btn btn-success" type="submit"><i class="fas fa-save me-1"></i>Save</button><a class="btn btn-outline-secondary" href="users.php">Cancel</a></div>
        </form>
    </div></div>
<?php adminPageEnd(); ?>
