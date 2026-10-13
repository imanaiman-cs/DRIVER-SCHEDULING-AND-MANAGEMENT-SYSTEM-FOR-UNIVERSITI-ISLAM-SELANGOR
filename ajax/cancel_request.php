<?php
// ============================================================
// UIS Driver Scheduling and Management System
// ajax/cancel_request.php  –  AJAX endpoint: cancel a vehicle request
// Universiti Islam Selangor (UIS)
//
// Who may cancel (a reason is ALWAYS required):
//   staff       their own requests
//   supervisor  requests where they are the approver, or from their department
//   admin       any request
// Only while the request is still active (pending / approved / processed)
// and the trip has not started yet.
// ============================================================

require_once '../config/database.php';

header('Content-Type: application/json');

function cancelFail(string $message, int $http = 200): void
{
    http_response_code($http);
    echo json_encode(['success' => false, 'message' => $message]);
    exit();
}

if (!isLoggedIn() || !(isStaff() || isSupervisor() || isAdmin())) {
    cancelFail('Unauthorized', 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    cancelFail('Invalid request method.');
}

$request_id = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
$reason     = trim((string)($_POST['reason'] ?? ''));
if ($request_id <= 0) {
    cancelFail('Invalid request ID.');
}
if (mb_strlen($reason) < 3) {
    cancelFail('Please write the reason for cancelling.');
}

$stmt = $conn->prepare(
    "SELECT vr.request_id, vr.staff_id, vr.supervisor_id, vr.status, vr.trip_date, vr.start_time, s.department
       FROM vehicle_requests vr JOIN users s ON s.user_id = vr.staff_id
      WHERE vr.request_id = ? LIMIT 1"
);
$stmt->bind_param('i', $request_id);
$stmt->execute();
$req = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$req) {
    cancelFail('Request not found.');
}

// ── Permission: may THIS user cancel THIS request? ──────────────
$uid = (int)$_SESSION['user_id'];
$allowed = false;
if (isAdmin()) {
    $allowed = true;
} elseif (isStaff()) {
    $allowed = ((int)$req['staff_id'] === $uid);
} elseif (isSupervisor()) {
    $allowed = ((int)$req['supervisor_id'] === $uid)
            || (!empty($_SESSION['department']) && $req['department'] === $_SESSION['department']);
}
if (!$allowed) {
    cancelFail('You are not allowed to cancel this request.', 403);
}
if (!in_array($req['status'], REQUEST_ACTIVE_STATUSES, true)) {
    cancelFail('This request is already ' . $req['status'] . ' and cannot be cancelled.');
}
if (strtotime($req['trip_date'] . ' ' . $req['start_time']) <= time()) {
    cancelFail('The trip has already started, so the request can no longer be cancelled.');
}

$result = changeRequestStatus($conn, $request_id, 'cancelled', ['reason' => $reason]);

echo json_encode([
    'success' => $result['ok'],
    'message' => $result['ok'] ? 'The request has been cancelled and the vehicle is free again.' : $result['message'],
]);
