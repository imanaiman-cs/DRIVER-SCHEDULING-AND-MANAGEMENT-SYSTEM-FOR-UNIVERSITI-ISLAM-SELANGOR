<?php
// ============================================================
// UIS Driver Scheduling and Management System
// ajax/send_message.php – Send an internal message
// ============================================================
require_once '../config/database.php';
requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

$sender_id   = (int)$_SESSION['user_id'];
$receiver_id = (int)($_POST['receiver_id'] ?? 0);
$body        = trim($_POST['body'] ?? '');
$parent_id   = (int)($_POST['parent_id'] ?? 0) ?: null;

if ($receiver_id <= 0 || $body === '') {
    echo json_encode(['success' => false, 'message' => 'Receiver and message body are required.']);
    exit();
}

// Verify receiver exists
$chk = $conn->prepare("SELECT user_id FROM users WHERE user_id = ?");
$chk->bind_param('i', $receiver_id);
$chk->execute();
if (!$chk->get_result()->fetch_assoc()) {
    echo json_encode(['success' => false, 'message' => 'Receiver not found.']);
    exit();
}

$stmt = $conn->prepare(
    "INSERT INTO messages (sender_id, receiver_id, body, parent_id, created_at)
     VALUES (?, ?, ?, ?, NOW())"
);
$stmt->bind_param('iisi', $sender_id, $receiver_id, $body, $parent_id);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message_id' => $conn->insert_id]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to send message.']);
}
