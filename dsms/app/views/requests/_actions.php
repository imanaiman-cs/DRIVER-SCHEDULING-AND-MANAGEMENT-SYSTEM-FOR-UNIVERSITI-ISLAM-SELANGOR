<?php
/** Approve / reject buttons - only for the person who may decide (RequestPolicy::canApprove). Shared by show.php. */
if (RequestPolicy::canApprove(Auth::user(), $r)): ?>
    <form method="post" action="<?= e(url('approvals/approve')) ?>" class="d-inline">
        <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <button class="btn btn-dark btn-sm" data-confirm="Approve request #<?= (int)$r['id'] ?>?">Approve</button>
    </form>
    <button class="btn btn-outline-dark btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#rejectModal">Reject</button>
    <div class="modal fade" id="rejectModal" tabindex="-1" aria-labelledby="rejectTitle" aria-hidden="true"><div class="modal-dialog"><form class="modal-content" method="post" action="<?= e(url('approvals/reject')) ?>">
        <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <div class="modal-header"><h2 class="modal-title h5" id="rejectTitle">Reject request #<?= (int)$r['id'] ?></h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body"><label class="form-label small" for="reject_reason">Reason (required, the requester will read it)</label>
            <textarea class="form-control" id="reject_reason" name="reason" rows="3" maxlength="500" required></textarea></div>
        <div class="modal-footer"><button type="button" class="btn btn-link" data-bs-dismiss="modal">Back</button><button class="btn btn-dark" type="submit">Reject request</button></div>
    </form></div></div>
<?php endif; ?>
<?php
/** Vehicle Section shortcuts (only for admins; the target pages check the role again). */
if (RequestPolicy::isAdmin(Auth::user())):
    if ($r['status'] === RequestStatus::APPROVED): ?>
        <a class="btn btn-dark btn-sm" href="<?= e(url('assignments/assign', ['id' => $r['id']])) ?>">Assign driver</a>
    <?php elseif (in_array($r['status'], [RequestStatus::ASSIGNED, RequestStatus::CONFIRMED], true) && !$r['is_self_drive'] && $r['return_datetime'] > date('Y-m-d H:i:s')): ?>
        <a class="btn btn-outline-dark btn-sm" href="<?= e(url('assignments/change', ['id' => $r['id']])) ?>">Change driver</a>
    <?php endif;
    if ($r['status'] === RequestStatus::ASSIGNED && Auth::hasRole('head_admin')): ?>
        <form method="post" action="<?= e(url('assignments/confirm')) ?>" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button class="btn btn-dark btn-sm" data-confirm="Approve the drivers for request #<?= (int)$r['id'] ?>?">Approve assignment</button></form>
    <?php endif;
endif; ?>
