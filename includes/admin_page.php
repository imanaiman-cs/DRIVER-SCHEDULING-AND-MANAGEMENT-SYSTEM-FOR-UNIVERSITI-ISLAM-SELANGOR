<?php
// ============================================================
// includes/admin_page.php  –  the shared top and bottom of a page (any role: the sidebar adapts to the signed-in role)
// (settings, leaves, welfare, internal tasks ...) so each page only holds its own content.
//
//   adminPageStart('Title');   ...page content...   adminPageEnd();
// Needs $page_title / $current_page to be set before adminPageStart() (the sidebar reads them).
// ============================================================

/** The <head> tags every signed-in page shares (Tailwind + DaisyUI build, Font Awesome icons, IBM Plex). */
function appHead(string $title): void
{
    ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?> | UIS Driver Management</title>
    <link rel="icon" type="image/png" href="<?= SITE_URL ?>/assets/images/uis-favicon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Condensed:wght@600&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
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
    global $conn, $page_title, $current_page;
    $page_title = $title;
    ?>
<!DOCTYPE html>
<?php
    if (isset($_GET['look'])) { $_SESSION['look_preview'] = in_array($_GET['look'], ['glass', 'bento', 'forest'], true) ? $_GET['look'] : ''; }
    $look = $_SESSION['look_preview'] ?? '';
?>
<html lang="en" data-theme="uis"<?= $look ? ' data-look="' . $look . '"' : '' ?>>
<head>
<?php appHead($title); ?>
</head>
<body class="min-h-screen bg-[#e6e9e7]">
<?php require __DIR__ . '/sidebar.php'; ?>
<main class="mx-auto w-full max-w-[1400px] flex-1 px-4 pt-2 pb-8 sm:px-6 lg:px-8" id="mainContent">
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

/** A valid Y-m-d date? (kept for older pages; see isValidDate in workflow.php) */
function validDateStr(string $d): bool
{
    return isValidDate($d);
}

/** Active drivers (carpool drivers), for lists and pickers. */
function activeDrivers(mysqli $conn): array
{
    return $conn->query("SELECT driver_id, name, employee_id, license_class FROM drivers WHERE status = 'active' ORDER BY name")->fetch_all(MYSQLI_ASSOC);
}
