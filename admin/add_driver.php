<?php
// ============================================================
// admin/add_driver.php  -  View Layer: UserManagementUI (SDD_CLASS_102) displayAccountForm() - UC001A Create Driver Account
// Staff ID, name, UIS email, phone, licence classes, licence expiry date, start date of service -> the account is
// created with the Driver role and a default password, and the login details are sent to the driver's UIS email.
// ============================================================
$current_page = 'add_driver.php';
require_once '../config/database.php';
require_once '../includes/ui.php';
requireAdmin();

$errors = []; $formError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $res = (new AccountController())->createDriverAccount($_POST);
    if ($res['ok']) {
        setFlash(!empty($res['email_failed']) ? 'warning' : 'success', $res['message']);
        header('Location: view_user.php?id=' . $res['user_id']);
        exit();
    }
    $errors = $res['errors'] ?? [];
    $formError = $res['message'];
}
$v = fn($k) => h($_POST[$k] ?? '');
adminPageStart('Create Driver');
pageHeader('Create Driver', 'user-plus', 'Drivers do not register themselves. The login details are sent to the driver\'s UIS email; changing the default password is optional.', '<a class="btn btn-ghost" href="users.php"><i class="fas fa-arrow-left"></i>Back</a>');
if ($formError) { echo '<div class="alert alert-soft alert-error mb-5"><i class="fas fa-circle-exclamation"></i><span>' . h($formError) . '</span></div>'; }
cardStart();
?>
<form method="post" class="grid grid-cols-12 gap-4" novalidate>
    <div class="col-span-12 md:col-span-3"><label class="mb-1 block text-sm font-medium" for="staff_no">Staff ID</label>
        <input class="input w-full<?= errClass($errors, 'staff_id') ?>" id="staff_no" name="staff_id" maxlength="20" required value="<?= $v('staff_id') ?>"><?= fieldError($errors, 'staff_id') ?></div>
    <div class="col-span-12 md:col-span-5"><label class="mb-1 block text-sm font-medium" for="full_name">Name</label>
        <input class="input w-full<?= errClass($errors, 'name') ?>" id="full_name" name="name" maxlength="150" required value="<?= $v('name') ?>"><?= fieldError($errors, 'name') ?></div>
    <div class="col-span-12 md:col-span-4"><label class="mb-1 block text-sm font-medium" for="phone">Phone number</label>
        <input type="tel" class="input w-full<?= errClass($errors, 'phone_no') ?>" id="phone" name="phone_no" maxlength="20" required value="<?= $v('phone_no') ?>"><?= fieldError($errors, 'phone_no') ?></div>
    <div class="col-span-12 md:col-span-6"><label class="mb-1 block text-sm font-medium" for="email">UIS email</label>
        <input type="email" class="input w-full<?= errClass($errors, 'email') ?>" id="email" name="email" maxlength="150" required value="<?= $v('email') ?>" placeholder="name@uis.edu.my"><?= fieldError($errors, 'email') ?></div>
    <div class="col-span-12 md:col-span-6"></div>
    <?= driverFieldsHtml($_POST, $errors) ?>
    <div class="col-span-12 flex gap-2"><button class="btn btn-primary" type="submit"><i class="fas fa-floppy-disk"></i>Save</button><a class="btn btn-ghost" href="users.php">Cancel</a></div>
</form>
<?php cardEnd(); adminPageEnd(); ?>
