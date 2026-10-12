<?php /** Comments on a request (add only - R9). Shared by show.php; needs $r and $comments. */
?>
<div class="card mb-3"><div class="card-body">
    <h2 class="h6">Comments</h2>
    <?php foreach ($comments as $c): ?>
        <div class="border-bottom pb-2 mb-2 small">
            <strong><?= e($c['name']) ?></strong> <span class="text-muted">(<?= e(role_label($c['user_role'])) ?>) &middot; <?= e(fmt_datetime($c['created_at'])) ?></span><br>
            <?= nl2br(e($c['comment'])) ?>
        </div>
    <?php endforeach; ?>
    <?php if (!$comments): ?><p class="small text-muted">No comments yet.</p><?php endif; ?>
    <form method="post" action="<?= e(url('comments/add')) ?>" class="mt-2">
        <?= Csrf::field() ?><input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
        <label class="form-label small" for="comment">Add a comment</label>
        <textarea class="form-control form-control-sm" id="comment" name="comment" rows="2" maxlength="1000" required></textarea>
        <div class="d-flex justify-content-between align-items-center mt-2">
            <span class="form-text">Comments cannot be edited or deleted after posting.</span>
            <button class="btn btn-sm btn-dark" type="submit">Post comment</button>
        </div>
    </form>
</div></div>
