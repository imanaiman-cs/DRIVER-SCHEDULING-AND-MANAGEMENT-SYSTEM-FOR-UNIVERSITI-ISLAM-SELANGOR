<?php
// ============================================================
// ajax/deactivate_vehicle.php  -  UC009 Deactivate Vehicle (vehicles are never deleted)
// POST id. Refused while the vehicle has upcoming trips; the trips are listed in `items`.
// ============================================================
require_once '../config/database.php';
require_once '../admin/fleet_helpers.php';
fleetAjaxGuard();

$res = setVehicleStatus($conn, (int)($_POST['id'] ?? 0), 'inactive');
if ($res['ok']) { setFlash('success', $res['message']); }
echo json_encode(['success' => $res['ok'], 'message' => $res['message'], 'items' => array_map(fn($i) => ['text' => $i['text']], $res['items'])]);
