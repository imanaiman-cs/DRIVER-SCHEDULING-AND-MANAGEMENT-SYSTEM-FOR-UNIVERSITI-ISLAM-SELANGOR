<?php /** @var array $rows */ ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Vehicles</h1>
    <a class="btn btn-sm btn-dark" href="<?= e(url('vehicles/edit')) ?>">Add vehicle</a>
</div>
<div class="card"><div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
        <thead><tr><th>Reg. no</th><th>Vehicle</th><th>Type</th><th class="text-end">Seats</th><th>Licence</th><th>Road tax</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <?php $expired = $r['road_tax_expiry'] && $r['road_tax_expiry'] < date('Y-m-d'); ?>
            <tr class="<?= $r['status'] === 'Inactive' ? 'text-muted' : '' ?>">
                <td><strong><?= e($r['reg_no']) ?></strong></td>
                <td><?= e($r['brand'] . ' ' . $r['model']) ?></td>
                <td><?= e($r['vehicle_type']) ?></td>
                <td class="text-end"><?= (int)$r['capacity'] ?></td>
                <td><?= e($r['required_licence']) ?></td>
                <td><?= $r['road_tax_expiry'] ? e(fmt_date($r['road_tax_expiry'])) : '-' ?><?= $expired ? ' <span class="badge text-bg-danger">expired</span>' : '' ?></td>
                <td>
                    <?php if ($r['status'] === 'Inactive'): ?><span class="badge text-bg-light border">Inactive</span>
                    <?php elseif ($r['in_maintenance']): ?><span class="badge text-bg-warning">In maintenance</span>
                    <?php else: ?><span class="badge text-bg-light border">Available</span><?php endif; ?>
                </td>
                <td class="text-end"><a class="btn btn-sm btn-outline-dark" href="<?= e(url('vehicles/edit', ['id' => $r['id']])) ?>">Manage</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div></div>
