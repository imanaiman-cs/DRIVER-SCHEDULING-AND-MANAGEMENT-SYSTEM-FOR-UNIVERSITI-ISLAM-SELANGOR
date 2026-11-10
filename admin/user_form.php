<?php
if (!isset($creating)) { http_response_code(404); exit(); }   // only included by add_user.php and edit_user.php
// admin/user_form.php  -  the account form shared by add_user.php and edit_user.php (included, not opened on its own).
// Needs: $v values, $errors, $roles (assignable roles), $creating, $departments, $lockRole (role cannot change), $h.
$err = fn($k) => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . $h($errors[$k]) . '</div>' : '';
?>
<div class="row g-3">
    <div class="col-12 col-md-6"><label class="form-label" for="username">Staff ID (login name) <?= $creating ? '*' : '' ?></label>
        <input class="form-control" id="username" name="username" maxlength="50" value="<?= $h($v['username']) ?>" <?= $creating ? 'required' : 'readonly' ?>><?= $err('username') ?>
        <?php if (!$creating): ?><div class="form-text">The staff ID cannot be changed.</div><?php endif; ?></div>
    <div class="col-12 col-md-6"><label class="form-label" for="role">Role *</label>
        <select class="form-select" id="role" name="role" <?= $lockRole ? 'disabled' : 'required' ?>>
            <?php foreach ($roles as $k => $lbl): ?><option value="<?= $k ?>" <?= $v['role'] === $k ? 'selected' : '' ?>><?= $h($lbl) ?></option><?php endforeach; ?></select>
        <?php if ($lockRole): ?><input type="hidden" name="role" value="<?= $h($v['role']) ?>"><div class="form-text"><?= $h($lockRole) ?></div><?php endif; ?><?= $err('role') ?></div>
    <div class="col-12"><label class="form-label" for="full_name">Full name *</label>
        <input class="form-control" id="full_name" name="full_name" maxlength="100" required value="<?= $h($v['full_name']) ?>"><?= $err('full_name') ?></div>
    <div class="col-12 col-md-6"><label class="form-label" for="email">E-mail *</label>
        <input type="email" class="form-control" id="email" name="email" maxlength="100" required value="<?= $h($v['email']) ?>"><?= $err('email') ?></div>
    <div class="col-12 col-md-6"><label class="form-label" for="phone">Phone number</label>
        <input class="form-control" id="phone" name="phone" maxlength="20" value="<?= $h($v['phone']) ?>"><?= $err('phone') ?></div>
    <div class="col-12"><label class="form-label" for="department">PTJ (department) *</label>
        <input class="form-control" id="department" name="department" maxlength="100" list="deptList" required value="<?= $h($v['department']) ?>"><?= $err('department') ?>
        <datalist id="deptList"><?php foreach ($departments as $d): ?><option value="<?= $h($d) ?>"><?php endforeach; ?></datalist>
        <div class="form-text">Requests of Staff and Drivers in this PTJ are approved by its Head of Department. A new PTJ needs its Head of Department first.</div></div>
</div>
