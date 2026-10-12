<?php /** @var string $title @var string $content */ ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> | <?= e($config['app_name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= e(asset('css/app.css')) ?>" rel="stylesheet">
</head>
<body class="d-flex align-items-center min-vh-100">
<main class="container" style="max-width: 440px;">
    <div class="text-center mb-4">
        <div class="display-6 fw-bold"><?= e($config['app_name']) ?></div>
        <div class="text-muted"><?= e($config['app_title']) ?></div>
        <div class="small text-muted"><?= e($config['org_name']) ?></div>
    </div>
    <?php require __DIR__ . '/../partials/flash.php'; ?>
    <?= $content ?>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
