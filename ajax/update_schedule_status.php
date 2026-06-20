<?php
// ============================================================
// UIS Driver Scheduling and Management System
// ajax/update_schedule_status.php  –  AJAX status updater
// Universiti Islam Selangor (UIS)
// ============================================================

require_once '../config/database.php';
requireLogin();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

$schedule_id = isset($_POST['schedule_id']) ? (int)$_POST['schedule_id'] : 0;
$new_status  = isset($_POST['status']) ? trim($_POST['status']) : '';

if ($schedule_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid schedule ID.']);
    exit();
}

$allowed_statuses = ['in_progress', 'completed', 'cancelled'];
if (!in_array($new_status, $allowed_statuses)) {
    echo json_encode(['success' => false, 'message' => 'Invalid status.']);
    exit();
}

// Drivers can only update their own schedules
if (isDriver()) {
    $driver_id = $_SESSION['driver_id'] ?? 0;
    if (!$driver_id) {
        echo json_encode(['success' => false, 'message' => 'Driver session invalid.']);
        exit();
    }
    $stmt = $conn->prepare(
        "UPDATE schedules SET status = ?, updated_at = NOW()
         WHERE schedule_id = ? AND driver_id = ?"
    );
    $stmt->bind_param('sii', $new_status, $schedule_id, $driver_id);
} else {
    // Admin: can update any schedule
    $stmt = $conn->prepare(
        "UPDATE schedules SET status = ?, updated_at = NOW()
         WHERE schedule_id = ?"
    );
    $stmt->bind_param('si', $new_status, $schedule_id);
}

if ($stmt->execute()) {
    if ($stmt->affected_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Schedule not found or no change made.']);
    } else {
        echo json_encode([
            'success' => true,
            'message' => 'Status updated to ' . ucfirst(str_replace('_', ' ', $new_status))
        ]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Update failed. Please try again.']);
}

$stmt->close();
