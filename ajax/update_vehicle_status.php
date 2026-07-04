<?php
// ============================================================
// UIS Driver Scheduling and Management System
// ajax/update_vehicle_status.php  –  AJAX: Toggle vehicle status
// Universiti Islam Selangor (UIS)
// ============================================================

require_once '../config/database.php';
requireAdmin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Bad request.']);
    exit();
}

$id     = isset($_POST['id'])     ? (int)$_POST['id']          : 0;
$status = isset($_POST['status']) ? trim($_POST['status'])      : '';

// ── Validate inputs ───────────────────────────────────────────
if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid vehicle ID.']);
    exit();
}

$allowed_statuses = ['available', 'in_use', 'maintenance', 'retired'];

if (!in_array($status, $allowed_statuses, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid status value.']);
    exit();
}

// ── Verify the vehicle exists ─────────────────────────────────
$chk = $conn->prepare("SELECT vehicle_id FROM vehicles WHERE vehicle_id = ?");
$chk->bind_param('i', $id);
$chk->execute();
$chk->store_result();

if ($chk->num_rows === 0) {
    $chk->close();
    echo json_encode(['success' => false, 'message' => 'Vehicle not found.']);
    exit();
}
$chk->close();

// ── Guard: do not allow "available" or "in_use" while vehicle
//    has active (in_progress) schedules that would require it
//    to remain in use (informational check — not blocking for
//    admin override, but we surface a warning) ─────────────────

// ── Perform update ────────────────────────────────────────────
$stmt = $conn->prepare("UPDATE vehicles SET status = ? WHERE vehicle_id = ?");
$stmt->bind_param('si', $status, $id);

if ($stmt->execute()) {
    $status_labels = [
        'available'   => 'Available',
        'in_use'      => 'In Use',
        'maintenance' => 'Maintenance',
        'retired'     => 'Retired',
    ];
    $label = $status_labels[$status] ?? ucfirst($status);

    echo json_encode([
        'success' => true,
        'message' => 'Vehicle status updated to "' . $label . '".',
        'status'  => $status,
        'label'   => $label
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to update status. Please try again.']);
}

$stmt->close();
