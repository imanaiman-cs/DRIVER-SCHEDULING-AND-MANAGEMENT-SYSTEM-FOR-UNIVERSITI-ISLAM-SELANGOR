<?php /** @var string $scope @var array $rows @var int $total @var int $page @var int $pages @var array $filters */
$all = $scope === 'all';
$pageName = $all ? 'requests/all' : 'requests/index';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0"><?= $all ? 'All requests' : 'My requests' ?></h1>
    <a class="btn btn-dark btn-sm" href="<?= e(url('requests/create')) ?>">+ New request</a>
</div>
<form class="row g-2 mb-3" method="get" action="<?= e(base_url() . '/index.php') ?>">
    <input type="hidden" name="page" value="<?= e($pageName) ?>">
    <div class="col-sm-4"><input class="form-control form-control-sm" name="q" placeholder="Search destination, purpose or request no." value="<?= e($filters['q']) ?>"></div>
    <div class="col-sm-3"><select class="form-select form-select-sm" name="status">
        <option value="">All statuses</option>
        <?php foreach (RequestStatus::ALL as $s): ?><option <?= $filters['status'] === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?>
    </select></div>
    <div class="col-auto"><button class="btn btn-sm btn-outline-dark" type="submit">Filter</button></div>
</form>
<div class="card"><div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0">
        <thead><tr><th>No.</th><?php if ($all): ?><th>Requester</th><?php endif; ?><th>Destination</th><th>Vehicle</th><th>Departure</th><th>Return</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td>#<?= (int)$r['id'] ?></td>
                <?php if ($all): ?><td><?= e($r['requester_name']) ?></td><?php endif; ?>
                <td><?= e($r['destination_name']) ?></td>
                <td><?= e($r['reg_no']) ?></td>
                <td class="text-nowrap"><?= e(fmt_datetime($r['departure_datetime'])) ?></td>
                <td class="text-nowrap"><?= e(fmt_datetime($r['return_datetime'])) ?></td>
                <td><?= status_badge($r['status']) ?></td>
                <td class="text-end"><a class="btn btn-sm btn-outline-dark" href="<?= e(url('requests/show', ['id' => $r['id']])) ?>">View</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="<?= $all ? 8 : 7 ?>" class="text-center text-muted py-4">No requests found.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div></div>
<?php if ($pages > 1): ?>
<nav class="mt-3"><ul class="pagination pagination-sm mb-0">
    <?php for ($i = 1; $i <= $pages; $i++): ?>
        <li class="page-item <?= $i === $page ? 'active' : '' ?>"><a class="page-link" href="<?= e(url($pageName, ['p' => $i, 'q' => $filters['q'], 'status' => $filters['status']])) ?>"><?= $i ?></a></li>
    <?php endfor; ?>
</ul></nav>
<?php endif; ?>
<div class="small text-muted mt-2"><?= (int)$total ?> request(s)</div>
