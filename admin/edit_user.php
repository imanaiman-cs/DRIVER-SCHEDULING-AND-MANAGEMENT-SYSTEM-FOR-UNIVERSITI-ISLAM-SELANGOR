<?php
// ============================================================
// admin/edit_user.php  -  UC001C Update Staff and Driver Account
// The department and role (Staff / Head of Department) of a staff account; the department, licence classes,
// licence expiry date and start date of service of a driver. One Head of Department per department.
// The user is informed by email. Name, phone, address and password are changed by the user (UC016).
// ============================================================
$current_page = 'edit_user.php';
require_once '../config/database.php';
require_once '../includes/ui.php';
requireAdmin();

$u = loadUser($conn, (int)($_GET['id'] ?? $_POST['user_id'] ?? 0));
if (!$u) { setFlash('danger', 'No record is found.'); header('Location: users.php'); exit(); }
$errors = []; $formError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $res = updateAccount($conn, (int)$u['user_id'], $_POST);
    if ($res['ok']) { redirectWithResult($res, 'view_user.php?id=' . $u['user_id']); }
    $errors = $res['errors'] ?? [];
    $formError = $res['message'];
}
$src = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST + $u : $u;
$departments = departmentsList($conn);
adminPageStart('Edit User');
pageHeader('Edit ' . $u['full_name'], 'user-pen', $u['staff_no'] . ' · ' . $u['email'], '<a class="btn btn-ghost" href="view_user.php?id=' . (int)$u['user_id'] . '"><i class="fas fa-arrow-left"></i>Back</a>');
if ($formError) { echo '<div class="alert alert-soft alert-error mb-5"><i class="fas fa-circle-exclamation"></i><span>' . h($formError) . '</span></div>'; }
cardStart();
?>
<form method="post" class="grid grid-cols-12 gap-4" novalidate>
    <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
    <div class="col-span-12 md:col-span-6"><label class="mb-1 block text-sm font-medium" for="department_id">Department</label>
        <select class="select w-full<?= errClass($errors, 'department_id', 'select') ?>" id="department_id" name="department_id" required>
            <?php foreach ($departments as $d): ?><option value="<?= (int)$d['department_id'] ?>" <?= (int)$src['department_id'] === (int)$d['department_id'] ? 'selected' : '' ?>><?= h($d['department_name']) ?></option><?php endforeach; ?>
        </select><?= fieldError($errors, 'department_id') ?></div>
    <div class="col-span-12 md:col-span-6"><label class="mb-1 block text-sm font-medium" for="role">Role</label>
        <?php if (in_array($u['role'], ['staff', 'supervisor'], true)): ?>
        <select class="select w-full<?= errClass($errors, 'role', 'select') ?>" id="role" name="role">
            <option value="staff" <?= $src['role'] === 'staff' ? 'selected' : '' ?>>Staff</option>
            <option value="supervisor" <?= $src['role'] === 'supervisor' ? 'selected' : '' ?>>Head of Department</option>
        </select><?= fieldError($errors, 'role') ?>
        <?php else: ?><input class="input w-full" value="<?= h(roleLabel($u['role'])) ?>" disabled><?php endif; ?></div>
    <?php if ($u['role'] === 'driver'): ?><?= driverFieldsHtml($src, $errors) ?><?php endif; ?>
    <div class="col-span-12 flex gap-2"><button class="btn btn-primary" type="submit"><i class="fas fa-floppy-disk"></i>Save</button><a class="btn btn-ghost" href="view_user.php?id=<?= (int)$u['user_id'] ?>">Cancel</a></div>
</form>
<?php cardEnd(); adminPageEnd(); ?>
