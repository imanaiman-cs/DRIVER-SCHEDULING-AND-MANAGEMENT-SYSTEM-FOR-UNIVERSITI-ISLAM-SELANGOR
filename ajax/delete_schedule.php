<?php
require_once '../config/database.php';
require_once '../includes/mailer.php';
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

    // Snapshot before deleting so the driver can still be told which task was cancelled
    $before = null;
    try {
        $before = snapshotSchedule($conn, $id);
    } catch (Throwable $e) {
        error_log($e->getMessage());
    }

    $stmt = $conn->prepare("DELETE FROM schedules WHERE schedule_id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        $message = 'Schedule deleted successfully.';
        try {
            $email_results = notifyScheduleDeleted($conn, $before);
            foreach ($email_results as $r) {
                if (($r['status'] ?? '') === 'sent') {
                    $message .= ' The driver was notified by e-mail.';
                    break;
                }
            }
        } catch (Throwable $e) {
            error_log($e->getMessage());
        }
        echo json_encode(['success' => true, 'message' => $message]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to delete schedule.']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
}
