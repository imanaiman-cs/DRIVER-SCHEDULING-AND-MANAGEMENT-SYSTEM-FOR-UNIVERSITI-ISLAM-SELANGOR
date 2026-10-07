<?php
// ============================================================
// UIS Driver Scheduling and Management System
// ajax/delete_driver.php  –  AJAX endpoint: delete a driver
// Universiti Islam Selangor (UIS)
// ============================================================

require_once '../config/database.php';
require_once '../includes/upload.php';
requireAdmin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    $id = (int)$_POST['id'];

    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid driver ID.']);
        exit();
    }

    // Verify the driver actually exists (and remember the photo path)
    $exists = $conn->prepare("SELECT driver_id, photo FROM drivers WHERE driver_id = ? LIMIT 1");
    $exists->bind_param('i', $id);
    $exists->execute();
    $driver_row = $exists->get_result()->fetch_assoc();
    $exists->close();
    if (!$driver_row) {
        echo json_encode(['success' => false, 'message' => 'Driver not found.']);
        exit();
    }
    $photo_path = $driver_row['photo'];

    // Check if driver has active schedules
    $check = $conn->prepare(
        "SELECT COUNT(*) AS cnt
         FROM schedules
         WHERE driver_id = ?
           AND status IN ('pending', 'approved', 'in_progress')"
    );
    $check->bind_param('i', $id);
    $check->execute();
    $cnt = $check->get_result()->fetch_assoc()['cnt'];
    $check->close();

    if ($cnt > 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Cannot delete driver with active schedules (' . (int)$cnt . ' active assignment' . ((int)$cnt !== 1 ? 's' : '') . '). Please reassign or cancel those schedules first.'
        ]);
        exit();
    }

    // Proceed with deletion
    $stmt = $conn->prepare("DELETE FROM drivers WHERE driver_id = ?");
    $stmt->bind_param('i', $id);

    if ($stmt->execute()) {
        $stmt->close();
        deleteUploadedImage($photo_path);
        echo json_encode(['success' => true, 'message' => 'Driver deleted successfully.']);
    } else {
        $stmt->close();
        echo json_encode(['success' => false, 'message' => 'Failed to delete driver. Please try again.']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
}
?>
