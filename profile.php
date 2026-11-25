<?php
// ============================================================
// profile.php  -  View Layer: AccountUI (SDD_CLASS_101) displayProfile()
//   UC016 Manage Profile (every role) through AccountController
//   UC016A View Profile    staff ID, name, UIS email, phone, address, department, role (+ licence and start date for a driver)
//   UC016B Update Profile  name, phone number and address
//   UC016C Change Password current password, new password, confirm
// Users cannot change their own staff ID, UIS email, department or role. A driver's licence details are
// updated by the Administrative Assistant (UC001C).
// ============================================================
$current_page = 'profile.php';
require_once 'config/database.php';
require_once 'includes/ui.php';
requireLogin();

$uid = (int)$_SESSION['user_id'];
$errors = [];
$editing = isset($_GET['edit']);
$pwErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    $ac = new AccountController();
    if ($act === 'profile') {
        $res = $ac->updateProfile($uid, $_POST);
        if ($res['ok']) { redirectWithResult($res, 'profile.php'); }
        $errors = $res['errors'] ?? [];
        $editing = true;
    } elseif ($act === 'password') {
        $res = $ac->changePassword($uid, (string)($_POST['current_password'] ?? ''), (string)($_POST['new_password'] ?? ''), (string)($_POST['confirm_password'] ?? ''));
        if ($res['ok']) { redirectWithResult($res, 'profile.php'); }
        $pwErrors = $res['errors'] ?? ['current_password' => $res['message']];
    }
}

$me = (new AccountController())->getProfile($uid);
if (!$me) { header('Location: logout.php'); exit(); }
$val = fn($k) => h($_POST[$k] ?? $me[$k] ?? '');

adminPageStart('My Profile');
pageHeader('My Profile', 'user', 'Your account details. The staff ID, UIS email, department and role are managed by the Vehicle Section.');
?>
<div class="grid grid-cols-12 gap-5">
    <div class="col-span-12 lg:col-span-7">
        <?php cardStart('Profile', 'id-card'); ?>
        <?php if (!$editing): ?>
            <dl>
                <?= dlRow('Staff ID', h($me['staff_id'])) ?>
                <?= dlRow('Name', h($me['name'])) ?>
                <?= dlRow('UIS email', h($me['email'])) ?>
                <?= dlRow('Phone number', h($me['phone_no'] ?: '-')) ?>
                <?= dlRow('Address', nl2br(h($me['address'] ?: '-'))) ?>
                <?= dlRow('Department', h($me['department_name'] ?? '-')) ?>
                <?= dlRow('Role', h($me['role']) . ($me['pending_role'] ? ' <span class="badge badge-warning badge-sm ms-1">Head of Department role waiting for confirmation</span>' : '')) ?>
                <?php if ($me['role'] === User::DRIVER): ?>
                    <?= dlRow('Licence class', h(str_replace(',', ', ', (string)$me['licence_classes']))) ?>
                    <?= dlRow('Licence expiry date', h(fmtD($me['licence_expiry'])) . ($me['licence_expiry'] && $me['licence_expiry'] < date('Y-m-d') ? ' <span class="badge badge-error badge-sm">expired</span>' : '')) ?>
                    <?= dlRow('Start date of service', h(fmtD($me['start_date'])) . ' <span class="text-xs text-base-content/60">(' . h(Driver::getYearsOfExperience($me)) . ' years)</span>') ?>
                <?php endif; ?>
            </dl>
            <div class="mt-3"><a class="btn btn-primary btn-sm" href="profile.php?edit=1"><i class="fas fa-pen"></i>Edit</a></div>
        <?php else: ?>
            <form method="post" class="grid grid-cols-2 gap-4" novalidate>
                <input type="hidden" name="action" value="profile">
                <div class="col-span-2">
                    <label class="mb-1 block text-sm font-medium" for="full_name">Name</label>
                    <input class="input w-full<?= errClass($errors, 'name') ?>" id="full_name" name="name" maxlength="150" required value="<?= $val('name') ?>">
                    <?= fieldError($errors, 'name') ?>
                </div>
                <div class="col-span-2 sm:col-span-1">
                    <label class="mb-1 block text-sm font-medium" for="phone">Phone number</label>
                    <input type="tel" class="input w-full<?= errClass($errors, 'phone_no') ?>" id="phone" name="phone_no" maxlength="20" required value="<?= $val('phone_no') ?>">
                    <?= fieldError($errors, 'phone_no') ?>
                </div>
                <div class="col-span-2">
                    <label class="mb-1 block text-sm font-medium" for="address">Address</label>
                    <textarea class="textarea w-full<?= errClass($errors, 'address', 'textarea') ?>" id="address" name="address" rows="2" maxlength="255"><?= $val('address') ?></textarea>
                    <?= fieldError($errors, 'address') ?>
                </div>
                <div class="col-span-2 flex gap-2"><button class="btn btn-primary" type="submit"><i class="fas fa-floppy-disk"></i>Save</button><a class="btn btn-ghost" href="profile.php">Cancel</a></div>
            </form>
        <?php endif; ?>
        <?php cardEnd(); ?>
    </div>
    <div class="col-span-12 lg:col-span-5" id="password">
        <?php cardStart('Change Password', 'key'); ?>
            <?php if ($me['role'] === User::DRIVER): ?><p class="mb-3 text-sm text-base-content/65">You may change the default password you received by email.</p><?php endif; ?>
            <form method="post" class="flex flex-col gap-3" novalidate>
                <input type="hidden" name="action" value="password">
                <div><label class="mb-1 block text-sm font-medium" for="current_password">Current password</label>
                    <input type="password" class="input w-full<?= errClass($pwErrors, 'current_password') ?>" id="current_password" name="current_password" required autocomplete="current-password"><?= fieldError($pwErrors, 'current_password') ?></div>
                <div><label class="mb-1 block text-sm font-medium" for="new_password">New password</label>
                    <input type="password" class="input w-full<?= errClass($pwErrors, 'new_password') ?>" id="new_password" name="new_password" required minlength="8" autocomplete="new-password"><?= fieldError($pwErrors, 'new_password') ?>
                    <div class="mt-1 text-xs text-base-content/60">At least 8 characters with letters and numbers.</div></div>
                <div><label class="mb-1 block text-sm font-medium" for="confirm_password">Confirm password</label>
                    <input type="password" class="input w-full<?= errClass($pwErrors, 'confirm_password') ?>" id="confirm_password" name="confirm_password" required autocomplete="new-password"><?= fieldError($pwErrors, 'confirm_password') ?></div>
                <button class="btn btn-primary" type="submit"><i class="fas fa-key"></i>Save</button>
            </form>
        <?php cardEnd(); ?>
    </div>
</div>
<?php adminPageEnd(); ?>
