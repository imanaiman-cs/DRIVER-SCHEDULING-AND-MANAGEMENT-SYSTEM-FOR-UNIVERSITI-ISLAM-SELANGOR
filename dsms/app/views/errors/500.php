<?php /** @var ?string $detail */ ?>
<div class="text-center py-5">
    <h1 class="display-6">Something went wrong</h1>
    <p class="text-muted">The problem has been recorded. Please try again, or contact the system administrator.</p>
    <?php if (!empty($detail)): ?>
        <pre class="text-start small bg-light border rounded p-3 mx-auto" style="max-width:700px"><?= e($detail) ?></pre>
        <p class="small text-muted">(Detail shown because <code>debug</code> is on in config.php.)</p>
    <?php endif; ?>
    <a class="btn btn-dark" href="<?= e(url('home/index')) ?>">Back to home</a>
</div>
