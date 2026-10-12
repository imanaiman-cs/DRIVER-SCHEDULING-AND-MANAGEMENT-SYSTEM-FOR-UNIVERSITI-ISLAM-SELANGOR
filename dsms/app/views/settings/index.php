<?php /** @var array $fields @var array $values @var array $errors */ ?>
<h1 class="h4 mb-1">System settings</h1>
<p class="text-muted small">Changes take effect immediately and are written to the audit log.</p>
<form method="post" action="<?= e(url('settings/save')) ?>">
    <?= Csrf::field() ?>
    <div class="card"><div class="card-body">
        <div class="row g-3">
        <?php foreach ($fields as $key => [$label, $kind, $min, $max, $help]): ?>
            <div class="col-md-6">
                <label class="form-label small" for="<?= e($key) ?>"><?= e($label) ?></label>
                <input class="form-control <?= isset($errors[$key]) ? 'is-invalid' : '' ?>" id="<?= e($key) ?>" name="<?= e($key) ?>"
                       type="<?= $kind === 'time' ? 'time' : 'text' ?>" value="<?= e($values[$key] ?? '') ?>" required>
                <?php if (isset($errors[$key])): ?><div class="invalid-feedback"><?= e($errors[$key]) ?></div><?php endif; ?>
                <div class="form-text"><?= e($help) ?></div>
            </div>
        <?php endforeach; ?>
        </div>
    </div></div>
    <button class="btn btn-dark mt-3" type="submit">Save settings</button>
</form>
