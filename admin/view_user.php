<?php
// ============================================================
// admin/view_user.php  -  UC001B account details (driver: licence class, expiry, start date of service),
// with Edit and Deactivate (UC001D). When a deactivation is refused, the tasks to replace are listed.
// ============================================================
$current_page = 'view_user.php';
require_once '../config/database.php';
require_once '../includes/ui.php';
requireAdmin();

$u = loadUser($conn, (int)($_GET['id'] ?? 0));
if (!$u) { setFlash('danger', 'No record is found.'); header('Location: users.php'); exit(); }
$uid = (int)$u['user_id'];
$blockers = $u['status'] === 'active' ? deactivateBlockers($conn, $u) : [];
$actions = '<a class="btn btn-ghost" href="users.php"><i class="fas fa-arrow-left"></i>Back</a><a class="btn btn-outline" href="edit_user.php?id=' . $uid . '"><i class="fas fa-pen"></i>Edit</a>';
if ($uid !== (int)$_SESSION['user_id']) {
    $actions .= '<form method="post" action="users.php"><input type="hidden" name="user_id" value="' . $uid . '">'
        . ($u['status'] === 'active'
            ? '<button class="btn btn-outline btn-error" name="action" value="deactivate" type="submit" data-confirm="' . h($u['full_name']) . ' will no longer be able to log in. All records remain in the system." data-confirm-title="Deactivate this account?" data-confirm-button="Confirm" data-confirm-tone="danger"><i class="fas fa-user-slash"></i>Deactivate</button>'
            : '<button class="btn btn-outline btn-success" name="action" value="activate" type="submit"><i class="fas fa-user-check"></i>Activate</button>') . '</form>';
}
adminPageStart('User Account');
pageHeader($u['full_name'], 'user', roleLabel($u['role']) . ' · ' . ($u['department_name'] ?? ''), $actions);
?>
<div class="grid grid-cols-12 gap-5">
<div class="col-span-12 lg:col-span-6">
<?php cardStart('Account details', 'id-card'); ?>
<dl>
    <?= dlRow('Staff ID', h($u['staff_no'])) ?>
    <?= dlRow('Name', h($u['full_name'])) ?>
    <?= dlRow('UIS email', h($u['email'])) ?>
    <?= dlRow('Phone number', h($u['phone'] ?: '-')) ?>
    <?= dlRow('Address', h($u['address'] ?: '-')) ?>
    <?= dlRow('Department', h($u['department_name'] ?? '-')) ?>
    <?= dlRow('Role', h(roleLabel($u['role'])) . ($u['pending_role'] ? ' <span class="badge badge-warning badge-sm">asks: Head of Department</span>' : '')) ?>
    <?= dlRow('Status', userStatusBadge($u['status'])) ?>
    <?php if ($u['role'] === 'driver'): ?>
        <?= dlRow('Licence class', h(str_replace(',', ', ', (string)$u['licence_classes']))) ?>
        <?= dlRow('Licence expiry date', h(fmtD($u['licence_expiry'])) . ($u['licence_expiry'] < date('Y-m-d') ? ' <span class="badge badge-error badge-sm">expired</span>' : '')) ?>
        <?= dlRow('Start date of service', h(fmtD($u['start_date'])) . ' (' . h(experienceYears($u['start_date'], date('Y-m-d'))) . ' years)') ?>
    <?php endif; ?>
    <?= dlRow('Last sign-in', h($u['last_login_at'] ? fmtDT($u['last_login_at']) : 'Never')) ?>
</dl>
<?php cardEnd(); ?>
</div>
<div class="col-span-12 lg:col-span-6">
<?php if ($blockers): ?>
    <div class="alert alert-soft alert-warning mb-5 block"><div class="font-semibold"><i class="fas fa-triangle-exclamation me-1"></i>Before deactivating</div><p class="text-sm"><?= h($blockers['message']) ?></p>
    <?php if ($blockers['tasks']): ?><ul class="mt-2 text-sm"><?php foreach ($blockers['tasks'] as $t): ?>
        <li class="flex flex-wrap items-center justify-between gap-2 border-t border-base-300 py-1.5"><span><?= h(taskTypeLabel($t)) ?>, <?= h(fmtPeriod($t['start_dt'], $t['end_dt'])) ?> <?= plate($t['plate_number']) ?> <?= taskStatusBadge($t['status']) ?></span>
        <?php if ($t['status'] === 'approved'): ?><a class="btn btn-xs btn-primary" href="replace_driver.php?task=<?= (int)$t['task_id'] ?>">Replace Driver</a><?php else: ?><span class="text-xs text-base-content/60">Waiting for the Head of Admin</span><?php endif; ?></li>
    <?php endforeach; ?></ul><?php endif; ?></div>
<?php endif; ?>
<?php if ($u['role'] === 'driver' && $u['driver_id']): $c = driverMonthCounts($conn, date('Y-m'))[(int)$u['driver_id']] ?? ['request' => 0, 'internal' => 0, 'welfare' => 0, 'weekend' => 0, 'tasks' => 0]; ?>
<?php cardStart('This month', 'chart-simple'); ?>
<dl><?= dlRow('Request tasks', (string)$c['request']) ?><?= dlRow('Internal tasks', (string)$c['internal']) ?><?= dlRow('Welfare duties', (string)$c['welfare']) ?><?= dlRow('Weekend tasks', (string)$c['weekend']) ?></dl>
<a class="btn btn-sm btn-outline mt-2" href="schedule.php?driver_id=<?= (int)$u['driver_id'] ?>">Open the schedule</a>
<?php cardEnd(); ?>
<?php endif; ?>
</div>
</div>
<?php adminPageEnd(); ?>
