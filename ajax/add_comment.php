<?php
// ============================================================
// ajax/add_comment.php  -  UC015 Add Comment (Head of Department, Administrative Assistant, Head of Admin)
// POST request_id, comment [, return_to]. Saved with the commenter's name and date; the staff member is notified.
// Comments are add-only (database triggers refuse edit/delete).
// ============================================================
require_once '../config/database.php';
if (!isLoggedIn() || !(isAdmin() || isSupervisor())) { http_response_code(403); header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit(); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { actionResponse(['ok' => false, 'message' => 'Invalid request method.'], 405); }
$req = requestForCurrentUser($conn, (int)($_POST['request_id'] ?? 0));
if (!$req) { actionResponse(['ok' => false, 'message' => 'Request not found.'], 404); }
actionResponse(addRequestComment($conn, $req, (string)($_POST['comment'] ?? '')));
