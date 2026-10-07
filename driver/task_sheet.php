<?php
// ============================================================
// UIS Driver Scheduling and Management System
// driver/task_sheet.php  –  Printable "Tugasan Pemandu" sheet
// Universiti Islam Selangor (UIS)
// ============================================================

require_once '../config/database.php';
require_once '../includes/task_sheet.php';
requireDriver();

$driver_id   = (int)($_SESSION['driver_id'] ?? 0);
$schedule_id = (int)($_GET['id'] ?? 0);

if ($schedule_id <= 0 || $driver_id <= 0) {
    setFlash('danger', 'Schedule not found.');
    header('Location: ' . SITE_URL . '/driver/schedules.php');
    exit();
}

// Ownership enforced in the query: drivers may only open their own tasks.
$stmt = $conn->prepare(
    "SELECT s.schedule_id, s.trip_date, s.start_time, s.end_time,
            s.destination, s.purpose, s.officer_name, s.officer_phone,
            s.waiting_place,
            d.name         AS driver_name,
            v.plate_number AS vehicle_plate,
            v.brand        AS vehicle_brand,
            v.model        AS vehicle_model,
            v.vehicle_type AS vehicle_type
     FROM schedules s
     LEFT JOIN drivers  d ON s.driver_id  = d.driver_id
     LEFT JOIN vehicles v ON s.vehicle_id = v.vehicle_id
     WHERE s.schedule_id = ? AND s.driver_id = ?"
);
$stmt->bind_param('ii', $schedule_id, $driver_id);
$stmt->execute();
$schedule = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$schedule) {
    setFlash('danger', 'Schedule not found.');
    header('Location: ' . SITE_URL . '/driver/schedules.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="ms">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tugasan Pemandu #<?php echo (int)$schedule['schedule_id']; ?></title>
    <link rel="icon" type="image/png" href="<?php echo SITE_URL; ?>/assets/images/uis-favicon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #eef1f0; }
        .sheet-card {
            width: 210mm; max-width: 100%; min-height: 297mm;
            margin: 1.5rem auto; padding: 20mm;
            background: #fff; border: 2px solid #0b5d3b; border-radius: 4px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, .12);
        }
        .btn-uis { background: #0b5d3b; border-color: #0b5d3b; color: #fff; }
        .btn-uis:hover { background: #094a2f; border-color: #094a2f; color: #fff; }
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
    <div class="toolbar no-print d-flex gap-2 px-2">
        <button type="button" class="btn btn-uis btn-sm" onclick="window.print()">Print</button>
        <a href="<?php echo SITE_URL; ?>/driver/schedules.php" class="btn btn-outline-secondary btn-sm">Back</a>
    </div>

    <div class="sheet-card">
        <?php renderTaskSheet($schedule); ?>
    </div>
</body>
</html>
