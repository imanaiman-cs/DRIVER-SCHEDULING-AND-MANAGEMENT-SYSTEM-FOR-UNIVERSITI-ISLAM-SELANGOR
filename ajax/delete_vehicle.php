<?php
// ============================================================
// UIS Driver Scheduling and Management System
// ajax/delete_vehicle.php  –  AJAX: Delete a vehicle
// Universiti Islam Selangor (UIS)
// ============================================================

require_once '../config/database.php';
require_once '../includes/upload.php';
requireAdmin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Bad request.']);
    exit();
}

$id = (int)$_POST['id'];

if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid vehicle ID.']);
    exit();
}

// ── Check for active (non-cancelled) schedules ────────────────
$check = $conn->prepare(
    "SELECT COUNT(*) AS cnt
     FROM schedules
     WHERE vehicle_id = ?
       AND status IN ('pending', 'approved', 'in_progress')"
);
$check->bind_param('i', $id);
$check->execute();
$cnt = $check->get_result()->fetch_assoc()['cnt'];
$check->close();

if ($cnt > 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Cannot delete: this vehicle has ' . (int)$cnt . ' active schedule(s). Please cancel or reassign them first.'
    ]);
    exit();
}

// ── Fetch the photo path so the file can be removed after delete ──
$photo_path = null;
$pq = $conn->prepare("SELECT photo FROM vehicles WHERE vehicle_id = ? LIMIT 1");
$pq->bind_param('i', $id);
$pq->execute();
$prow = $pq->get_result()->fetch_assoc();
$pq->close();
if ($prow) {
    $photo_path = $prow['photo'];
}

// ── Perform delete ────────────────────────────────────────────
$stmt = $conn->prepare("DELETE FROM vehicles WHERE vehicle_id = ?");
$stmt->bind_param('i', $id);

if ($stmt->execute()) {
    deleteUploadedImage($photo_path);
    echo json_encode(['success' => true, 'message' => 'Vehicle deleted successfully.']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to delete vehicle. Please try again.']);
}

$stmt->close();
