<?php
// ============================================================
// includes/admin_page.php  –  the shared top and bottom of the newer admin pages
// (settings, leaves, welfare, internal tasks ...) so each page only holds its own content.
//
//   adminPageStart('Title');   ...page content...   adminPageEnd();
// Needs $page_title / $current_page to be set before adminPageStart() (the sidebar reads them).
// ============================================================

function adminPageStart(string $title): void
{
    global $conn, $page_title, $current_page;
    $page_title = $title;
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?> | UIS Driver Management</title>
    <link rel="icon" type="image/png" href="<?= SITE_URL ?>/assets/images/uis-favicon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="<?= SITE_URL ?>/assets/css/style.css" rel="stylesheet">
</head>
<body>
<?php require __DIR__ . '/sidebar.php'; ?>
<main class="main-content p-4" id="mainContent">
    <?php showFlash(); ?>
<?php
}

function adminPageEnd(): void
{
    ?>
</main>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= SITE_URL ?>/assets/js/main.js"></script>
</body>
</html>
<?php
}

/** A valid Y-m-d date? */
function validDateStr(string $d): bool
{
    $t = DateTime::createFromFormat('Y-m-d', $d);
    return $t && $t->format('Y-m-d') === $d;
}

/** Active regular drivers (Top Management drivers are not used for normal duties; R8). */
function regularActiveDrivers(mysqli $conn): array
{
    return $conn->query("SELECT driver_id, name FROM drivers WHERE status = 'active' AND driver_type = 'regular' ORDER BY name")->fetch_all(MYSQLI_ASSOC);
}

/** user_id of the login account of a driver (to notify them), or null. */
function userIdOfDriver(mysqli $conn, int $driver_id): ?int
{
    $r = $conn->query('SELECT user_id FROM users WHERE driver_id = ' . $driver_id . ' LIMIT 1')->fetch_assoc();
    return $r ? (int)$r['user_id'] : null;
}
