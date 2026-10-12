<?php /** @var array $rows */ ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Notifications</h1>
    <form method="post" action="<?= e(url('notifications/read_all')) ?>"><?= Csrf::field() ?><button class="btn btn-sm btn-outline-dark">Mark all as read</button></form>
</div>
<div class="list-group">
<?php foreach ($rows as $n): ?>
    <a class="list-group-item list-group-item-action <?= $n['is_read'] ? '' : 'fw-semibold' ?>" href="<?= e(url('notifications/open', ['id' => $n['id']])) ?>">
        <div><?= $n['is_read'] ? '' : '<span class="badge text-bg-dark me-1">New</span>' ?><?= e($n['message']) ?></div>
        <div class="small text-muted fw-normal"><?= e(fmt_datetime($n['created_at'])) ?></div>
    </a>
<?php endforeach; ?>
<?php if (!$rows): ?><div class="list-group-item text-center text-muted py-4">No notifications yet.</div><?php endif; ?>
</div>
