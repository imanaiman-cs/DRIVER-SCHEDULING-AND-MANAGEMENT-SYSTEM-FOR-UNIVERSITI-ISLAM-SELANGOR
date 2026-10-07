<?php
// ============================================================
// UIS Driver Scheduling and Management System
// ajax/cancel_request.php  –  AJAX endpoint: staff cancels
//                             (withdraws) their own vehicle
//                             request while it is still pending
// Universiti Islam Selangor (UIS)
// ============================================================

require_once '../config/database.php';

header('Content-Type: application/json');

// ── Auth guard: staff only (JSON 403, no redirect) ────────────
if (!isLoggedIn() || !isStaff()) {
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

// request_id must be a positive integer
if ($request_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid request ID.']);
    exit();
}

// ── Perform the update ────────────────────────────────────────
// The staff member must own the request and it must still be
// awaiting the supervisor (optimistic lock). Once approved or
// processed the staff must contact the transport unit instead.
$staff_id = (int)$_SESSION['user_id'];

$stmt = $conn->prepare(
    "UPDATE vehicle_requests
     SET status       = 'cancelled',
         cancelled_at = NOW()
     WHERE request_id = ?
       AND staff_id   = ?
       AND status IN ('pending')"
);

if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    exit();
}

$stmt->bind_param('ii', $request_id, $staff_id);
$stmt->execute();

$affected = $stmt->affected_rows;
$stmt->close();

// ── Respond ───────────────────────────────────────────────────
if ($affected === 0) {
    echo json_encode([
        'success' => false,
        'message' => 'This request can no longer be cancelled because it has already been reviewed. Please contact the transport unit.'
    ]);
    exit();
}

echo json_encode([
    'success' => true,
    'message' => 'Your request has been cancelled.',
]);
?>
