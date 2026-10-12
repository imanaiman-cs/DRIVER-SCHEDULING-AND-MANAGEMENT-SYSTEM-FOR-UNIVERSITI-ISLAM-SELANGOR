<?php
/**
 * Ranked driver table for ONE leg.
 * Needs: $rec (from DriverRecommender::recommend), $inputName (radio name), $selected (driver id or 0), $currentDriverId (0 if none)
 */
$ranked = $rec['ranked']; $w = $rec['weights'];
$selected = (int)($selected ?? 0);
if ($selected === 0) {          // pre-select the best driver without a clash
    foreach ($ranked as $c) { if (!$c['clashes'] && (int)$c['id'] !== (int)($currentDriverId ?? 0)) { $selected = (int)$c['id']; break; } }
}
?>
<div class="table-responsive">
<table class="table table-sm align-middle mb-2">
    <thead><tr><th></th><th>Driver</th><th class="text-end" title="100 = least busy this month">Score</th><th class="text-end">Tasks this month</th><th class="text-end">After-hours</th><th>Notes</th></tr></thead>
    <tbody>
    <?php foreach ($ranked as $i => $c): $cur = (int)$c['id'] === (int)($currentDriverId ?? 0); ?>
        <tr class="<?= $c['clashes'] ? 'table-warning' : '' ?>">
            <td><input class="form-check-input" type="radio" name="<?= e($inputName) ?>" value="<?= (int)$c['id'] ?>" id="<?= e($inputName) ?>-<?= (int)$c['id'] ?>" <?= $selected === (int)$c['id'] ? 'checked' : '' ?> <?= $cur ? 'disabled' : '' ?>></td>
            <td><label for="<?= e($inputName) ?>-<?= (int)$c['id'] ?>"><?= e($c['name']) ?></label>
                <?= $i === 0 && !$c['clashes'] ? '<span class="badge text-bg-dark ms-1">Recommended</span>' : '' ?>
                <?= $cur ? '<span class="badge text-bg-secondary ms-1">Current</span>' : '' ?>
                <div class="small text-muted"><?= e($c['phone'] ?? '') ?></div></td>
            <td class="text-end fw-semibold"><?= e(number_format($c['score'], 1)) ?></td>
            <td class="text-end"><?= (int)$c['tasks'] ?></td>
            <td class="text-end"><?= (int)$c['after_hours'] ?></td>
            <td class="small">
                <?php if ($c['welfare']): ?><div>On welfare duty (&minus;<?= e(rtrim(rtrim(number_format($w['welfare'], 1), '0'), '.')) ?> points)</div><?php endif; ?>
                <?php foreach ($c['clashes'] as $cl): ?><div class="text-danger">Clash: <?= e($cl) ?></div><?php endforeach; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$ranked): ?><tr><td colspan="6" class="text-center text-danger py-3">No driver can take this leg. See the list below.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
<?php if ($rec['excluded']): ?>
<details class="small mb-2"><summary class="text-muted">Why are <?= count($rec['excluded']) ?> drivers not listed?</summary>
    <ul class="mb-0 mt-1"><?php foreach ($rec['excluded'] as $x): ?><li><?= e($x['name']) ?> - <?= e($x['reason']) ?></li><?php endforeach; ?></ul>
</details>
<?php endif; ?>
<details class="small text-muted mb-1"><summary>How is the score worked out?</summary>
    <p class="mb-1 mt-1">Score = 100 &minus; (<?= e($w['tasks']) ?> &times; share of tasks + <?= e($w['after']) ?> &times; share of after-hours work) &times; 100 &minus; welfare penalty.
    "Share" compares the driver with the busiest driver in this list, so 100 means the least busy. These weights are set under Manage &rarr; Settings.
    A clash with another job is only a warning, so those drivers are listed last.</p>
</details>
