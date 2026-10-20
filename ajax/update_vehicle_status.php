<?php
// ============================================================
// ajax/update_vehicle_status.php  -  UC008 Update Vehicle Status
// POST id, status (available | maintenance | inactive), and for maintenance: workshop, return_date (Y-m-d)
// "In Use" is never set here: the system shows it when a Confirmed trip covers the current time.
// ============================================================
require_once '../config/database.php';
require_once '../admin/fleet_helpers.php';
fleetAjaxGuard();

$res = setVehicleStatus($conn, (int)($_POST['id'] ?? 0), trim((string)($_POST['status'] ?? '')),
                        (string)($_POST['workshop'] ?? ''), trim((string)($_POST['return_date'] ?? '')));
if ($res['ok']) { setFlash($res['items'] ? 'warning' : 'success', $res['message']); }
echo json_encode(['success' => $res['ok'], 'message' => $res['message'], 'items' => array_map(fn($i) => ['text' => $i['text']], $res['items'])]);
