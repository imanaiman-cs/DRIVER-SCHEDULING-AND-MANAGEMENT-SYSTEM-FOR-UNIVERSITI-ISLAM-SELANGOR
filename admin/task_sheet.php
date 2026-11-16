<?php
// ============================================================
// UIS Driver Scheduling and Management System
// admin/task_sheet.php  –  Printable "Tugasan Pemandu" sheet
// Universiti Islam Selangor (UIS)
// ============================================================

require_once '../config/database.php';
require_once '../includes/task_sheet.php';
require_once '../includes/schedule_view.php';
requireAdmin();

$schedule_id = (int)($_GET['id'] ?? 0);
if ($schedule_id <= 0) {
    setFlash('danger', 'Invalid schedule ID.');
    header('Location: ' . SITE_URL . '/admin/calendar.php');
    exit();
}

$schedule = taskSheetRow($conn, $schedule_id);

if (!$schedule) {
    setFlash('danger', 'Schedule not found.');
    header('Location: ' . SITE_URL . '/admin/calendar.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="ms" data-theme="uis">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tugasan Pemandu #<?php echo (int)$schedule['schedule_id']; ?></title>
    <link rel="icon" type="image/png" href="<?php echo SITE_URL; ?>/assets/images/uis-favicon.png">
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans+Condensed:wght@600&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="<?php echo SITE_URL; ?>/assets/css/app.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/app.css'); ?>" rel="stylesheet">
    <style>
        body { background: var(--color-base-200); }
        .sheet-card {
            width: 210mm; max-width: 100%; min-height: 297mm;
            margin: 1.5rem auto; padding: 20mm;
            background: #fff; border: 2px solid #0b5d3b; border-radius: 4px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, .12);
        }
        .toolbar { width: 210mm; max-width: 100%; margin: 1.5rem auto 0; }

        @page { size: A4; margin: 15mm; }
        @media print {
            body { background: #fff; }
            .no-print { display: none !important; }
            .sheet-card {
                width: auto; min-height: 0; margin: 0; padding: 0;
                border: 2px solid #0b5d3b; box-shadow: none; border-radius: 0;
                padding: 10mm;
            }
        }
    </style>
</head>
<body>
    <div class="toolbar no-print flex gap-2 px-2">
        <button type="button" class="btn btn-sm btn-primary" onclick="window.print()">Print</button>
        <a href="<?php echo SITE_URL; ?>/admin/calendar.php" class="btn btn-outline btn-sm">Back</a>
    </div>

    <div class="sheet-card">
        <?php renderTaskSheet($schedule); ?>
    </div>
</body>
</html>
