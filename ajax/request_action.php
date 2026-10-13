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

// ── Do it through the status engine ───────────────────────────
// It checks the supervisor owns the request and that it is still pending,
// releases the vehicle on a rejection, writes the audit log and tells the
// requester (with the reason).
$result = changeRequestStatus($conn, $request_id, $action === 'approve' ? 'approved' : 'rejected', [
    'reason'             => $action === 'reject' ? $notes : null,
    'only_if_supervisor' => (int)$_SESSION['user_id'],
]);

if ($action === 'approve' && $result['ok'] && $notes !== '') {
    // an optional note on approval is kept as before
    $upd = $conn->prepare('UPDATE vehicle_requests SET supervisor_notes = ? WHERE request_id = ?');
    $upd->bind_param('si', $notes, $request_id);
    $upd->execute();
    $upd->close();
}

echo json_encode([
    'success' => $result['ok'],
    'message' => $result['ok'] ? ($action === 'approve' ? 'Request approved.' : 'Request rejected.') : $result['message'],
]);
