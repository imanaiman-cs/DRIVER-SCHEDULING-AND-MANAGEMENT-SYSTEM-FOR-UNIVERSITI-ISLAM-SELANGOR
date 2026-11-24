<?php
// ============================================================
// includes/auth_layout.php  -  the page frame of Register Account and Reset Password (same look as the login page)
// ============================================================
require_once __DIR__ . '/ui.php';

function authPageStart(string $title, string $sub): void
{
    ?>
<!DOCTYPE html>
<html lang="en" data-theme="uis-light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($title) ?> | UIS Driver Management</title>
    <link rel="icon" type="image/png" href="assets/images/uis-favicon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Condensed:wght@600&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/app.css?v=<?= @filemtime(__DIR__ . '/../assets/css/app.css') ?>" rel="stylesheet">
    <?php uisThemeHead(); ?>
</head>
<body class="uis-page flex min-h-screen flex-col">
    <div class="flex justify-end gap-2 px-4 pt-4"><?= uisLangSwitch() ?><?= uisThemeSwitch() ?></div>
    <main class="flex flex-1 items-center justify-center px-4 pt-4 pb-10">
        <div class="w-full max-w-lg overflow-hidden rounded-lg bg-base-100 shadow-2xl">
            <div class="bg-gradient-to-br from-[#07301f] to-primary px-6 pt-6 pb-5 text-center text-white">
                <span class="mx-auto mb-3 grid size-14 place-items-center rounded-full bg-white shadow-lg ring-4 ring-secondary/60">
                    <img src="assets/images/uis-favicon.png" alt="UIS crest" class="size-10 object-contain">
                </span>
                <h1 class="text-lg font-bold"><?= h($title) ?></h1>
                <p class="mt-1 text-xs tracking-[0.08em] text-white/75 uppercase">Driver Scheduling &amp; Management System</p>
            </div>
            <div class="px-8 pt-6 pb-6">
                <p class="mb-5 text-sm text-base-content/70"><?= h($sub) ?></p>
    <?php
}

function authPageEnd(): void
{
    ?>
            </div>
            <div class="border-t border-base-300 bg-base-200 px-6 py-3.5 text-center text-sm"><a class="link link-primary" href="login.php"><i class="fas fa-arrow-left me-1"></i>Back to Sign In</a></div>
        </div>
    </main>
    <footer class="pb-6 text-center text-xs text-base-content/65">&copy; <?= date('Y') ?> Universiti Islam Selangor. All rights reserved.</footer>
    <script src="assets/js/ui.js"></script>
</body>
</html>
    <?php
}

/** The steps of the form, with the current one marked. */
function authSteps(array $steps, int $at): string
{
    $h = '<ul class="steps steps-horizontal mb-6 w-full text-xs">';
    foreach ($steps as $i => $s) { $h .= '<li class="step' . ($i <= $at ? ' step-primary' : '') . '">' . h($s) . '</li>'; }
    return $h . '</ul>';
}

function authAlert(?string $error, ?string $info = null): string
{
    $h = '';
    if ($error) { $h .= '<div class="alert alert-soft alert-error mb-4 text-sm" role="alert"><i class="fas fa-circle-exclamation"></i><span>' . h($error) . '</span></div>'; }
    if ($info) { $h .= '<div class="alert alert-soft alert-info mb-4 text-sm" role="status"><i class="fas fa-circle-info"></i><span>' . h($info) . '</span></div>'; }
    return $h;
}
