<?php
require_once '../config/database.php';
requireAdmin();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    $id = (int)$_POST['id'];

    // Prevent deletion of in-progress schedules
    $check = $conn->prepare("SELECT status FROM schedules WHERE schedule_id = ?");
    $check->bind_param("i", $id);
    $check->execute();
    $row = $check->get_result()->fetch_assoc();

    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Schedule not found.']);
        exit();
    }
    if ($row['status'] === 'in_progress') {
        echo json_encode(['success' => false, 'message' => 'Cannot delete a schedule that is currently in progress.']);
        exit();
    }

    $stmt = $conn->prepare("DELETE FROM schedules WHERE schedule_id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Schedule deleted successfully.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to delete schedule.']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
}
