<?php
// ============================================================
// UIS Driver Scheduling and Management System
// ajax/request_action.php  –  AJAX endpoint: approve or reject
//                             a staff vehicle request
//                             (supervisor / head of section)
// Universiti Islam Selangor (UIS)
// ============================================================

require_once '../config/database.php';

header('Content-Type: application/json');

// ── Auth guard: supervisor only (JSON 403, no redirect) ───────
if (!isLoggedIn() || !isSupervisor()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// ── Only accept POST requests ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

// ── Read and validate inputs ──────────────────────────────────
$request_id = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
$action     = isset($_POST['action'])     ? trim($_POST['action'])    : '';
$notes      = isset($_POST['notes'])      ? trim($_POST['notes'])     : '';

// request_id must be a positive integer
if ($request_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid request ID.']);
    exit();
}

// action must be exactly 'approve' or 'reject'
if (!in_array($action, ['approve', 'reject'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid action. Must be "approve" or "reject".']);
    exit();
}

// notes are required when rejecting
if ($action === 'reject' && $notes === '') {
    echo json_encode(['success' => false, 'message' => 'Supervisor notes are required when rejecting a request.']);
    exit();
}

// ── Determine new status ──────────────────────────────────────
$new_status = ($action === 'approve') ? 'approved' : 'rejected';

// ── Perform the update ────────────────────────────────────────
// The supervisor must own the request (supervisor_id matches the
// session user) and it must still be pending (optimistic lock).
$supervisor_id = (int)$_SESSION['user_id'];

$stmt = $conn->prepare(
    "UPDATE vehicle_requests
     SET status           = ?,
         supervisor_notes = ?,
         reviewed_at      = NOW()
     WHERE request_id    = ?
       AND supervisor_id = ?
       AND status        = 'pending'"
);

if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    exit();
}

$stmt->bind_param('ssii', $new_status, $notes, $request_id, $supervisor_id);
$stmt->execute();

$affected = $stmt->affected_rows;
$stmt->close();

// ── Respond ───────────────────────────────────────────────────
if ($affected === 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Request already reviewed or not assigned to you.'
    ]);
    exit();
}

$message = ($action === 'approve')
    ? 'Request approved.'
    : 'Request rejected.';

echo json_encode([
    'success' => true,
    'message' => $message,
]);
?>
