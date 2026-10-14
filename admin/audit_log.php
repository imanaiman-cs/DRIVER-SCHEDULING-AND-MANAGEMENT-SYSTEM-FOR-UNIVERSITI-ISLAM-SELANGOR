<?php
// ============================================================
// UIS Driver Scheduling and Management System
// admin/audit_log.php  –  who did what to a request (read only)
// The audit log is insert-only: nothing here can change or delete an entry.
// ============================================================
$page_title   = 'Audit Log';
$current_page = 'audit_log.php';
require_once '../config/database.php';
requireAdmin();

$action     = trim((string)($_GET['action'] ?? ''));
$request_id = (int)($_GET['request'] ?? 0);

$where = []; $types = ''; $params = [];
if ($action !== '')    { $where[] = 'a.action = ?';     $types .= 's'; $params[] = $action; }
if ($request_id > 0)   { $where[] = 'a.request_id = ?'; $types .= 'i'; $params[] = $request_id; }
$sql = "SELECT a.*, u.full_name FROM audit_log a LEFT JOIN users u ON u.user_id = a.user_id"
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . ' ORDER BY a.log_id DESC LIMIT 300';
$stmt = $conn->prepare($sql);
if ($types !== '') { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$actions = [];
$r = $conn->query('SELECT DISTINCT action FROM audit_log ORDER BY action');
while ($r && ($x = $r->fetch_assoc())) { $actions[] = $x['action']; }

$role_names = ['admin' => 'Admin Assistant', 'superadmin' => 'Admin Assistant', 'head_admin' => 'Head of Admin', 'staff' => 'Staff',
               'supervisor' => 'Head of Section', 'driver' => 'Driver', 'system' => 'System'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Log | UIS Driver Management</title>
    <link rel="icon" type="image/png" href="<?= SITE_URL ?>/assets/images/uis-favicon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="<?= SITE_URL ?>/assets/css/style.css" rel="stylesheet">
</head>
<body>
<?php require_once '../includes/sidebar.php'; ?>
<main class="main-content p-4" id="mainContent">
    <?php showFlash(); ?>
    <h4 class="fw-bold mb-1"><i class="fas fa-clipboard-list text-success me-2"></i>Audit Log</h4>
    <p class="text-muted small">Every important action on a request is recorded here. Entries cannot be edited or deleted. The latest 300 are shown.</p>

    <form class="row g-2 mb-3" method="get">
        <div class="col-md-3">
            <select class="form-select form-select-sm" name="action" aria-label="Action">
                <option value="">All actions</option>
                <?php foreach ($actions as $a): ?>
                <option value="<?= htmlspecialchars($a) ?>" <?= $action === $a ? 'selected' : '' ?>><?= htmlspecialchars($a) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <input class="form-control form-control-sm" type="number" min="1" name="request" placeholder="Request no." value="<?= $request_id ?: '' ?>">
        </div>
        <div class="col-auto"><button class="btn btn-sm btn-success" type="submit">Filter</button></div>
    </form>

    <div class="card border-0 shadow-sm"><div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead class="table-light"><tr><th>When</th><th>Who</th><th>Action</th><th>Request</th><th>Details</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="text-nowrap small"><?= date('d M Y, H:i', strtotime($r['created_at'])) ?></td>
                    <td><?= htmlspecialchars($r['full_name'] ?? 'System') ?>
                        <span class="text-muted small"><?= htmlspecialchars($role_names[$r['user_role'] ?? ''] ?? ($r['user_role'] ?? '')) ?></span></td>
                    <td><code><?= htmlspecialchars($r['action']) ?></code></td>
                    <td><?= $r['request_id'] ? '<a href="?request=' . (int)$r['request_id'] . '">#' . (int)$r['request_id'] . '</a>' : '-' ?></td>
                    <td class="small"><?= htmlspecialchars($r['reason'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="5" class="text-center text-muted py-4">No entries.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div></div>
</main>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= SITE_URL ?>/assets/js/main.js"></script>
</body>
</html>
