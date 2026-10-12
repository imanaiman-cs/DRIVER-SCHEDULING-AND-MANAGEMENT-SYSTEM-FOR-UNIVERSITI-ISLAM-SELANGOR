<?php /** @var array $r request */ ?>
<div class="card mb-3"><div class="card-body py-2 small">
    <div class="d-flex flex-wrap gap-3">
        <span><strong>#<?= (int)$r['id'] ?></strong> <?= status_badge($r['status']) ?></span>
        <span><?= e($r['destination_name']) ?></span>
        <span><?= e(fmt_datetime($r['departure_datetime'])) ?> &rarr; <?= e(fmt_datetime($r['return_datetime'])) ?></span>
        <span><?= e($r['reg_no']) ?> <?= e($r['brand'] . ' ' . $r['model']) ?> (needs <strong><?= e($r['required_licence']) ?></strong> licence)</span>
        <span><?= (int)$r['passenger_count'] ?> pax &middot; <?= e($r['requester_name']) ?></span>
        <a href="<?= e(url('requests/show', ['id' => $r['id']])) ?>">Open request</a>
    </div>
</div></div>
