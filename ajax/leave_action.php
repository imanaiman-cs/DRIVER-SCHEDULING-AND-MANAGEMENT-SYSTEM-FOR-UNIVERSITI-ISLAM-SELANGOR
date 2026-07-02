<?php
// ============================================================
// UIS Driver Scheduling and Management System
// ajax/leave_action.php  –  AJAX endpoint: approve or reject
//                           a driver leave request
// Universiti Islam Selangor (UIS)
// ============================================================

require_once '../config/database.php';
requireAdmin();

header('Content-Type: application/json');

// ── Only accept POST requests ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

// ── Read and validate inputs ──────────────────────────────────
$request_id  = isset($_POST['request_id'])  ? (int)$_POST['request_id']         : 0;
$action      = isset($_POST['action'])      ? trim($_POST['action'])             : '';
$admin_notes = isset($_POST['admin_notes']) ? trim($_POST['admin_notes'])        : '';

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

// admin_notes is required when rejecting
if ($action === 'reject' && $admin_notes === '') {
    echo json_encode(['success' => false, 'message' => 'Admin notes are required when rejecting a leave request.']);
    exit();
}

// ── Determine new status ──────────────────────────────────────
$new_status = ($action === 'approve') ? 'approved' : 'rejected';

// ── Perform the update ────────────────────────────────────────
$reviewer_id = (int)$_SESSION['user_id'];

$stmt = $conn->prepare(
    "UPDATE leave_requests
     SET status      = ?,
         reviewed_by = ?,
         admin_notes = ?,
         updated_at  = NOW()
     WHERE request_id = ?
       AND status     = 'pending'"
);

if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    exit();
}

$stmt->bind_param('sisi', $new_status, $reviewer_id, $admin_notes, $request_id);
$stmt->execute();

$affected = $stmt->affected_rows;
$stmt->close();

// ── Respond ───────────────────────────────────────────────────
if ($affected === 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Request not found or already processed.'
    ]);
    exit();
}

$message = ($action === 'approve')
    ? 'Leave request has been approved successfully.'
    : 'Leave request has been rejected.';

echo json_encode([
    'success' => true,
    'message' => $message,
]);
?>
