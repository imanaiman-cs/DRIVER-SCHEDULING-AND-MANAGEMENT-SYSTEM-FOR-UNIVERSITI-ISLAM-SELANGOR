<?php
// One-time messages set with Session::flash(type, text): success, danger, warning, info.
foreach (Session::takeFlash() as $type => $messages):
    foreach ($messages as $message): ?>
    <div class="alert alert-<?= e($type) ?> alert-dismissible fade show" role="alert">
        <?= e($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endforeach; endforeach; ?>
