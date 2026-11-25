<?php
// ============================================================
// driver/schedule_change.php  -  View Layer: DriverTaskUI (SDD_CLASS_108) displayScheduleChangeForm() - UC022 Request Schedule Change
// The driver tells the Vehicle Section the dates when they cannot work (unavailable date or dates + reason).
// The list shows each request's status; a Pending request can be cancelled. Removing yourself from one task is
// done from the task (My Task > task > Request Change).
// ============================================================
$current_page = 'schedule_change.php';
require_once '../config/database.php';
require_once '../includes/ui.php';
requireRoles([User::DRIVER]);

$did = (int)$_SESSION['user_id'];
$dt = new DriverTaskController();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    if ($act === 'new') {
        $res = $dt->requestScheduleChange($did, $_POST);
        if ($res['ok']) { redirectWithResult($res, 'schedule_change.php'); }
        setFlash('danger', $res['message']);
    } elseif ($act === 'cancel') {
        redirectWithResult($dt->cancelScheduleChange($did, (int)($_POST['change_id'] ?? 0)), 'schedule_change.php');
    }
}
$rows = $dt->listScheduleChanges($did);
adminPageStart('Schedule Change');
pageHeader('Schedule Change', 'calendar-xmark', 'Tell the Vehicle Section the dates when you cannot work, for example a weekend. The Head of Admin approves it; you are then not given tasks on those dates.');
?>
<?php cardStart('New Request', 'plus'); ?>
<form method="post" class="grid grid-cols-12 items-end gap-3" novalidate>
    <input type="hidden" name="action" value="new">
    <div class="col-span-6 md:col-span-2"><label class="mb-1 block text-sm font-medium" for="start_date">From date</label>
        <input type="date" class="input w-full" id="start_date" name="start_date" min="<?= date('Y-m-d') ?>" required value="<?= h($_POST['start_date'] ?? '') ?>"></div>
    <div class="col-span-6 md:col-span-2"><label class="mb-1 block text-sm font-medium" for="end_date">To date</label>
        <input type="date" class="input w-full" id="end_date" name="end_date" min="<?= date('Y-m-d') ?>" value="<?= h($_POST['end_date'] ?? '') ?>"></div>
    <div class="col-span-12 md:col-span-6"><label class="mb-1 block text-sm font-medium" for="reason">Reason</label>
        <input class="input w-full" id="reason" name="reason" maxlength="255" required value="<?= h($_POST['reason'] ?? '') ?>"></div>
    <div class="col-span-12 md:col-span-2"><button class="btn btn-primary w-full" type="submit"><i class="fas fa-paper-plane"></i>Submit</button></div>
    <div class="col-span-12 text-xs text-base-content/60">Leave "To date" empty for a single day.</div>
</form>
<?php cardEnd(); ?>
<div class="card border border-base-300 bg-base-100"><div class="overflow-x-auto"><table class="table">
<thead><tr><th>Dates</th><th>Reason</th><th>Status</th><th>Decided</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?>
<tr>
    <td class="whitespace-nowrap"><?= h(fmtD($r['start_date'])) ?><?= $r['end_date'] !== $r['start_date'] ? ' - ' . h(fmtD($r['end_date'])) : '' ?>
        <?php if ($r['task_id']): ?><div class="text-xs text-base-content/60">Affects task #<?= (int)$r['task_id'] ?></div><?php endif; ?></td>
    <td><?= h($r['reason']) ?><?php if ($r['reject_reason']): ?><div class="text-xs text-error"><?= h($r['reject_reason']) ?></div><?php endif; ?></td>
    <td><?= decisionBadge($r['status']) ?><?php if ($r['approved_by_name']): ?><div class="text-xs text-base-content/60"><?= h($r['approved_by_name']) ?></div><?php endif; ?></td>
    <td class="whitespace-nowrap text-sm"><?= h($r['decision_date'] ? fmtDT($r['decision_date']) : '-') ?></td>
    <td class="text-end"><?php if ($r['status'] === ScheduleChange::PENDING): ?><form method="post"><input type="hidden" name="action" value="cancel"><input type="hidden" name="change_id" value="<?= (int)$r['change_id'] ?>">
        <button class="btn btn-sm btn-outline btn-error" type="submit" data-confirm="The request will not be sent to the Head of Admin." data-confirm-title="Cancel this request?" data-confirm-button="Cancel request" data-confirm-tone="danger">Cancel</button></form><?php endif; ?></td>
</tr>
<?php endforeach; ?>
<?php if (!$rows): ?><?= emptyRow(5, 'You have not asked for a schedule change yet.') ?><?php endif; ?>
</tbody></table></div></div>
<?php adminPageEnd(); ?>
