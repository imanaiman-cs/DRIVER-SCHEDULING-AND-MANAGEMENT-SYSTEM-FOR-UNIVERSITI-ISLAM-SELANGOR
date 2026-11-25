<?php
// ============================================================
// includes/admin_page.php  –  View Layer: the shared top and bottom of a page (any role: the sidebar adapts to the signed-in role)
// (settings, leaves, welfare, internal tasks ...) so each page only holds its own content.
//
//   adminPageStart('Title');   ...page content...   adminPageEnd();
// Needs $page_title / $current_page to be set before adminPageStart() (the sidebar reads them).
// ============================================================

/** The <head> tags every signed-in page shares (Tailwind + DaisyUI build, Font Awesome icons, IBM Plex Sans). */
function appHead(string $title): void
{
    ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?> | UIS Driver Management</title>
    <link rel="icon" type="image/png" href="<?= SITE_URL ?>/assets/images/uis-favicon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Condensed:wght@600&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script>try { if (localStorage.getItem('uis-rail') === 'closed') { document.documentElement.classList.add('rail-closed'); } } catch (e) {}</script>
    <?php uisThemeHead(); ?>
    <link href="<?= SITE_URL ?>/assets/css/app.css?v=<?= @filemtime(__DIR__ . '/../assets/css/app.css') ?>" rel="stylesheet">
<?php
}

/** Closes the drawer opened by sidebar.php (the page column, then the drawer itself). */
function appLayoutEnd(): void
{
    echo "</div><!-- /.ember-panel -->\n</div><!-- /.drawer-content -->\n</div><!-- /.drawer -->\n";
}

function adminPageStart(string $title): void
{
    global $page_title, $current_page;
    $page_title = $title;
    ?>
<!DOCTYPE html>
<html lang="en" data-theme="uis-light">
<head>
<?php appHead($title); ?>
</head>
<body class="uis-page min-h-screen">
<?php require __DIR__ . '/sidebar.php'; ?>
<main class="mx-auto w-full max-w-[1400px] flex-1 px-4 pt-6 pb-10 sm:px-6 lg:px-8" id="mainContent">
    <?php showFlash(); ?>
<?php
}

function adminPageEnd(): void
{
    ?>
</main>
<?php appLayoutEnd(); ?>
</body>
</html>
<?php
}

