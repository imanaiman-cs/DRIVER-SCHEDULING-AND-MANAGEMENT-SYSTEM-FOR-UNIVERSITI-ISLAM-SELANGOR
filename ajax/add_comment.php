<?php
// ============================================================
// ajax/add_comment.php  –  add a comment to a request
// Head of Section and the transport unit can comment. A comment can never be
// edited or deleted afterwards (R9), so there is no such endpoint.
// POST: request_id, comment
// ============================================================
require_once '../config/database.php';
header('Content-Type: application/json');

function commentFail(string $m, int $http = 200): void
{
    http_response_code($http);
    echo json_encode(['success' => false, 'message' => $m]);
    exit();
}

if (!isLoggedIn() || !(isAdmin() || isSupervisor())) { commentFail('Unauthorized', 403); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { commentFail('Invalid request method.'); }

$request_id = (int)($_POST['request_id'] ?? 0);
$comment    = trim((string)($_POST['comment'] ?? ''));
$req = requestForCurrentUser($conn, $request_id);
if (!$req) { commentFail('Unauthorized', 403); }
if ($comment === '' || mb_strlen($comment) > 1000) { commentFail('A comment must be between 1 and 1000 characters.'); }

$uid  = (int)$_SESSION['user_id'];
$role = (string)$_SESSION['role'];
$stmt = $conn->prepare('INSERT INTO request_comments (request_id, user_id, user_role, comment) VALUES (?, ?, ?, ?)');
$stmt->bind_param('iiss', $request_id, $uid, $role, $comment);
$stmt->execute();
$stmt->close();

// tell the others (not the writer)
$who = [(int)$req['staff_id']];
if (!empty($req['supervisor_id'])) { $who[] = (int)$req['supervisor_id']; }
if (isSupervisor()) { $who = array_merge($who, adminUserIds($conn)); }
notifyUsers($conn, array_diff($who, [$uid]),
    ($_SESSION['full_name'] ?? 'Someone') . ' commented on request #' . $request_id . ': ' . mb_substr($comment, 0, 120),
    requestPageForRole('staff'));

echo json_encode(['success' => true, 'message' => 'Comment added.']);
