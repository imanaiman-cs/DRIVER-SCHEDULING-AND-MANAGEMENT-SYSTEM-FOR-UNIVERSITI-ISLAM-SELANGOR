<?php /** @var string $month @var array $roster @var array $drivers @var string $prev @var string $next @var int $days */ ?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <h1 class="h4 mb-0">Welfare duty</h1>
    <form method="post" action="<?= e(url('welfare/generate')) ?>" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="month" value="<?= e($month) ?>">
        <button class="btn btn-dark btn-sm" data-confirm="Fill the empty days of this month automatically?">Fill empty days automatically</button></form>
</div>
<p class="text-muted small">One driver is on 24-hour welfare duty each day and is kept free for emergencies. On duty, a driver gets a lower score in the driver recommendation.</p>
<div class="d-flex align-items-center gap-2 mb-3">
    <a class="btn btn-sm btn-outline-dark" href="<?= e(url('welfare/index', ['month' => $prev])) ?>">&laquo;</a>
    <strong><?= e(date('F Y', strtotime($month . '-01'))) ?></strong>
    <a class="btn btn-sm btn-outline-dark" href="<?= e(url('welfare/index', ['month' => $next])) ?>">&raquo;</a>
</div>
<div class="card"><div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
        <thead><tr><th>Date</th><th>Day</th><th>Driver on duty</th><th>Set / replace</th></tr></thead>
        <tbody>
        <?php for ($d = 1; $d <= $days; $d++): $date = sprintf('%s-%02d', $month, $d); $w = $roster[$date] ?? null; $weekend = (int)date('N', strtotime($date)) >= 6; ?>
            <tr class="<?= $date === date('Y-m-d') ? 'table-active' : '' ?>">
                <td class="text-nowrap"><?= e(date('d M', strtotime($date))) ?></td>
                <td class="<?= $weekend ? 'text-muted' : '' ?>"><?= e(date('D', strtotime($date))) ?></td>
                <td><?php if ($w): ?><strong><?= e($w['driver_name']) ?></strong>
                    <?= $w['replaced_name'] ? '<div class="small text-muted">replaced ' . e($w['replaced_name']) . ': ' . e($w['replacement_reason'] ?? '') . '</div>' : '' ?>
                    <?php else: ?><span class="text-muted">-</span><?php endif; ?></td>
                <td>
                    <form method="post" action="<?= e(url('welfare/set')) ?>" class="row g-1">
                        <?= Csrf::field() ?><input type="hidden" name="duty_date" value="<?= e($date) ?>">
                        <div class="col-auto"><select class="form-select form-select-sm" name="driver_id" aria-label="Driver for <?= e($date) ?>">
                            <?php foreach ($drivers as $dr): ?><option value="<?= (int)$dr['id'] ?>" <?= $w && (int)$w['driver_id'] === (int)$dr['id'] ? 'selected' : '' ?>><?= e($dr['name']) ?></option><?php endforeach; ?>
                        </select></div>
                        <?php if ($w): ?><div class="col-auto"><input class="form-control form-control-sm" name="reason" placeholder="Reason if replacing" maxlength="500" aria-label="Reason"></div><?php endif; ?>
                        <div class="col-auto"><button class="btn btn-sm btn-outline-dark" type="submit"><?= $w ? 'Replace' : 'Set' ?></button></div>
                        <?php if ($w): ?><div class="col-auto"><button class="btn btn-sm btn-outline-secondary" formaction="<?= e(url('welfare/clear')) ?>" data-confirm="Clear the duty for <?= e(fmt_date($date)) ?>?">Clear</button></div><?php endif; ?>
                    </form>
                </td>
            </tr>
        <?php endfor; ?>
        </tbody>
    </table>
</div></div>
