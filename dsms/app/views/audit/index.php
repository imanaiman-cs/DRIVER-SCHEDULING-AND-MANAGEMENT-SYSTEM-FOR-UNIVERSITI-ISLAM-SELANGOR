<?php /** @var array $rows @var array $actions @var string $action @var int $requestId */ ?>
<h1 class="h4 mb-1">Audit log</h1>
<p class="text-muted small">Every important action is recorded here. Entries can never be edited or deleted. The latest 300 are shown.</p>
<form class="row g-2 mb-3" method="get" action="<?= e(base_url() . '/index.php') ?>">
    <input type="hidden" name="page" value="audit/index">
    <div class="col-md-3"><select class="form-select form-select-sm" name="action">
        <option value="">All actions</option>
        <?php foreach ($actions as $a): ?><option value="<?= e($a) ?>" <?= $action === $a ? 'selected' : '' ?>><?= e($a) ?></option><?php endforeach; ?>
    </select></div>
    <div class="col-md-3"><input class="form-control form-control-sm" type="number" min="1" name="request" placeholder="Request no." value="<?= $requestId ?: '' ?>"></div>
    <div class="col-auto"><button class="btn btn-sm btn-outline-dark" type="submit">Filter</button></div>
</form>
<div class="card"><div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
        <thead><tr><th>When</th><th>Who</th><th>Action</th><th>Request</th><th>Details</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td class="text-nowrap"><?= e(fmt_datetime($r['created_at'])) ?></td>
                <td><?= e($r['user_name'] ?? 'System') ?> <span class="text-muted small"><?= e($r['user_role'] ? role_label($r['user_role']) : '') ?></span></td>
                <td><code><?= e($r['action']) ?></code></td>
                <td><?= $r['request_id'] ? '<a href="' . e(url('requests/show', ['id' => $r['request_id']])) . '">#' . (int)$r['request_id'] . '</a>' : '-' ?></td>
                <td class="small"><?= e($r['reason'] ?? '') ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="5" class="text-center text-muted py-4">No entries.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div></div>
