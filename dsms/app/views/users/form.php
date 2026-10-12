<?php /** @var ?array $user @var array $old @var array $errors @var array $ptjs @var array $approvers */
$field = fn($k) => isset($errors[$k]) ? '<div class="text-danger small">' . e($errors[$k]) . '</div>' : '';
?>
<h1 class="h4 mb-3"><?= $user ? 'Edit user' : 'New user' ?></h1>
<div class="card" style="max-width: 760px;"><div class="card-body">
<form method="post" action="<?= e(url('users/save')) ?>" class="row g-3">
    <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int)($user['id'] ?? 0) ?>">
    <div class="col-md-4"><label class="form-label" for="staff_id">Staff ID</label>
        <input class="form-control" id="staff_id" name="staff_id" value="<?= old($old, 'staff_id') ?>" required><?= $field('staff_id') ?></div>
    <div class="col-md-8"><label class="form-label" for="name">Full name</label>
        <input class="form-control" id="name" name="name" value="<?= old($old, 'name') ?>" required><?= $field('name') ?></div>
    <div class="col-md-7"><label class="form-label" for="email">E-mail</label>
        <input class="form-control" type="email" id="email" name="email" value="<?= old($old, 'email') ?>" required><?= $field('email') ?></div>
    <div class="col-md-5"><label class="form-label" for="phone">Phone</label>
        <input class="form-control" id="phone" name="phone" value="<?= old($old, 'phone') ?>"></div>
    <div class="col-md-4"><label class="form-label" for="role">Role</label>
        <select class="form-select" id="role" name="role" required>
            <?php foreach (Auth::ROLES as $r): ?>
                <option value="<?= e($r) ?>" <?= ($old['role'] ?? 'staff') === $r ? 'selected' : '' ?>><?= e(role_label($r)) ?></option>
            <?php endforeach; ?>
        </select><?= $field('role') ?></div>
    <div class="col-md-4"><label class="form-label" for="ptj_id">Department (PTJ)</label>
        <select class="form-select" id="ptj_id" name="ptj_id">
            <option value="">-</option>
            <?php foreach ($ptjs as $p): ?>
                <option value="<?= (int)$p['id'] ?>" <?= (int)($old['ptj_id'] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['code']) ?> - <?= e($p['name']) ?></option>
            <?php endforeach; ?>
        </select></div>
    <div class="col-md-4"><label class="form-label" for="approver_id">Approver</label>
        <select class="form-select" id="approver_id" name="approver_id">
            <option value="">- none -</option>
            <?php foreach ($approvers as $a): ?>
                <option value="<?= (int)$a['id'] ?>" <?= (int)($old['approver_id'] ?? 0) === (int)$a['id'] ? 'selected' : '' ?>><?= e($a['name']) ?> (<?= e($a['staff_id']) ?>)</option>
            <?php endforeach; ?>
        </select><?= $field('approver_id') ?>
        <div class="form-text">Staff: their Head of Department. A Head of Department: their own head.</div></div>
    <?php if (!$user): ?>
        <div class="col-12"><div class="alert alert-secondary py-2 mb-0 small">A temporary password is generated and shown once after saving. The user must change it at the first login.</div></div>
    <?php endif; ?>
    <div class="col-12"><button class="btn btn-dark" type="submit">Save</button>
        <a class="btn btn-link" href="<?= e(url('users/index')) ?>">Cancel</a></div>
</form>
</div></div>
