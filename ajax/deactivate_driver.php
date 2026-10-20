<?php
// ============================================================
// ajax/deactivate_driver.php  -  UC004 Deactivate Driver (drivers are never deleted)
// POST id [, reactivate=1]. Refused while the driver has upcoming tasks; they are listed in `items`
// (request tasks carry replace_url, the page where the driver is replaced).
// ============================================================
require_once '../config/database.php';
require_once '../admin/fleet_helpers.php';
fleetAjaxGuard();

$res = setDriverActive($conn, (int)($_POST['id'] ?? 0), !empty($_POST['reactivate']));
if ($res['ok']) { setFlash('success', $res['message']); }
echo json_encode(['success' => $res['ok'], 'message' => $res['message'], 'items' => array_map(
    fn($i) => ['text' => $i['text'], 'replace_url' => ($i['kind'] === 'request' && !empty($i['schedule_id'])) ? 'replace_driver.php?schedule_id=' . (int)$i['schedule_id'] : null],
    $res['items'])]);
