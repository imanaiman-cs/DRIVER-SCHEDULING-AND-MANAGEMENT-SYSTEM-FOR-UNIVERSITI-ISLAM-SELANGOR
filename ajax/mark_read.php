<?php
// ============================================================
// UIS Driver Scheduling and Management System
// ajax/mark_read.php – Mark messages from a sender as read
// ============================================================
require_once '../config/database.php';
requireLogin();

header('Content-Type: application/json');

$receiver_id = (int)$_SESSION['user_id'];
$sender_id   = (int)($_POST['sender_id'] ?? 0);

if ($sender_id <= 0) {
    echo json_encode(['success' => false]);
    exit();
}

$stmt = $conn->prepare(
    "UPDATE messages SET is_read = 1
     WHERE sender_id = ? AND receiver_id = ? AND is_read = 0"
);
$stmt->bind_param('ii', $sender_id, $receiver_id);
$stmt->execute();

echo json_encode(['success' => true, 'updated' => $stmt->affected_rows]);
