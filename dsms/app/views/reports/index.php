<?php /** @var array $report @var string $month @var string $prev @var string $next */ ?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2 no-print">
    <h1 class="h4 mb-0">Monthly report</h1>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-sm btn-outline-dark" onclick="window.print()">Print</button>
        <a class="btn btn-sm btn-dark" href="<?= e(url('reports/pdf', ['month' => $month])) ?>">Download PDF</a>
    </div>
</div>
<div class="d-flex align-items-center gap-2 mb-3 no-print">
    <a class="btn btn-sm btn-outline-dark" href="<?= e(url('reports/index', ['month' => $prev])) ?>">&laquo;</a>
    <strong><?= e(date('F Y', strtotime($month . '-01'))) ?></strong>
    <a class="btn btn-sm btn-outline-dark" href="<?= e(url('reports/index', ['month' => $next])) ?>">&raquo;</a>
</div>
<div class="card report-sheet"><div class="card-body">
    <div class="rep-title">
        <div class="fw-semibold"><?= e($config['org_name']) ?></div>
        <div>Monthly vehicle and driver report &middot; <?= e(date('F Y', strtotime($month . '-01'))) ?></div>
    </div>
    <?php include __DIR__ . '/_tables.php'; ?>
</div></div>
