<?php
// ============================================================
// ajax/request_action.php  -  UC013 Approve Request / UC014 Reject Request (Head of Department)
//
// POST request_id, action = approve | reject [, reason] [, comment] [, return_to]
//   The Head of Department only acts on requests of his own PTJ (he is the approver).
//   Reject needs a reason: the vehicle lock is released and the staff member is told the reason.
//   Approve: the staff member and the Administrative Assistant are notified.
//   A comment is optional with either (UC015).
// ============================================================
require_once '../config/database.php';
if (!isLoggedIn() || !isSupervisor()) { http_response_code(403); header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit(); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { actionResponse(['ok' => false, 'message' => 'Invalid request method.'], 405); }

$rid = (int)($_POST['request_id'] ?? 0);
$action = (string)($_POST['action'] ?? '');
$reason = trim((string)($_POST['reason'] ?? ''));
$req = $rid > 0 ? requestForCurrentUser($conn, $rid) : null;
if (!$req || !hodOwnsRequest($req)) { actionResponse(['ok' => false, 'message' => 'This request is not one of yours.'], 403); }
if (!in_array($action, ['approve', 'reject'], true)) { actionResponse(['ok' => false, 'message' => 'Unknown action.'], 422); }
if ($action === 'reject' && mb_strlen($reason) < 3) { actionResponse(['ok' => false, 'message' => 'Please give a reason for rejecting (at least 3 characters).'], 422); }
if (strtotime(requestDepartDT($req)) <= time()) { actionResponse(['ok' => false, 'message' => 'The trip time has already passed.'], 422); }

$res = changeRequestStatus($conn, $rid, $action === 'approve' ? 'approved' : 'rejected', [
    'reason' => $action === 'reject' ? $reason : null, 'only_if_supervisor' => (int)$_SESSION['user_id'],
]);
if ($res['ok'] && trim((string)($_POST['comment'] ?? '')) !== '') { addRequestComment($conn, $req, (string)$_POST['comment']); }
actionResponse(['ok' => $res['ok'], 'message' => $res['ok'] ? ($action === 'approve' ? 'Request approved.' : 'Request rejected.') : $res['message']]);
