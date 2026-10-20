<?php
// ============================================================
// ajax/cancel_request.php  -  UC012 Cancel Request
//
// POST request_id [, reason] [, comment] [, return_to]
//   Staff (any role, own request): the reason is optional.
//   Head of Department (his requests) and Administrative Assistant / Head of Admin (any): the reason is REQUIRED.
//   Not after the trip has started. The request is never deleted: the status becomes Cancelled, the vehicle lock is
//   released, any assigned driver is removed, and the staff member, the Head of Department, the Administrative
//   Assistant and the driver are notified.
// ============================================================
require_once '../config/database.php';
if (!isLoggedIn()) { http_response_code(403); header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit(); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { actionResponse(['ok' => false, 'message' => 'Invalid request method.'], 405); }

$rid = (int)($_POST['request_id'] ?? 0);
$reason = trim((string)($_POST['reason'] ?? ''));
$req = $rid > 0 ? requestForCurrentUser($conn, $rid) : null;
if (!$req) { actionResponse(['ok' => false, 'message' => 'Request not found.'], 404); }

$rules = cancelRulesFor($req);
if (!$rules['allowed']) { actionResponse(['ok' => false, 'message' => $rules['message']], 403); }
if ($rules['reason_required'] && mb_strlen($reason) < 3) {
    actionResponse(['ok' => false, 'message' => 'Please give a reason for cancelling (at least 3 characters).'], 422);
}
$res = changeRequestStatus($conn, $rid, 'cancelled', ['reason' => $reason]);
if ($res['ok'] && trim((string)($_POST['comment'] ?? '')) !== '') { addRequestComment($conn, $req, (string)$_POST['comment']); }
actionResponse(['ok' => $res['ok'], 'message' => $res['ok'] ? 'The request has been cancelled and the vehicle is free again.' : $res['message']]);
