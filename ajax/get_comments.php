<?php
// ajax/get_comments.php  –  comments on one request (read only). GET: request_id
require_once '../config/database.php';
header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}
$request_id = (int)($_GET['request_id'] ?? 0);
if (!requestForCurrentUser($conn, $request_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$stmt = $conn->prepare('SELECT c.comment, c.user_role, c.created_at, u.full_name
                          FROM request_comments c JOIN users u ON u.user_id = c.user_id
                         WHERE c.request_id = ? ORDER BY c.created_at, c.comment_id');
$stmt->bind_param('i', $request_id);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$labels = ['admin' => 'Admin Assistant', 'superadmin' => 'Admin Assistant', 'head_admin' => 'Head of Admin', 'supervisor' => 'Head of Section', 'staff' => 'Staff'];
foreach ($rows as &$r) {
    $r['role_label'] = $labels[$r['user_role']] ?? $r['user_role'];
}
unset($r);
echo json_encode(['success' => true, 'comments' => $rows, 'can_post' => (isAdmin() || isSupervisor())]);
