<?php
// ============================================================
// UIS Driver Scheduling and Management System
// admin/view_schedule.php  –  View Schedule Details
// Universiti Islam Selangor (UIS)
// ============================================================

require_once '../config/database.php';
requireAdmin();

$page_title   = 'View Schedule';
$current_page = 'view_schedule.php';

$schedule_id = (int)($_GET['id'] ?? 0);
if ($schedule_id <= 0) {
    setFlash('danger', 'Invalid schedule ID.');
    header('Location: ' . SITE_URL . '/admin/schedules.php');
    exit();
}

$stmt = $conn->prepare(
    "SELECT s.*,
            d.name           AS driver_name,
            d.employee_id    AS driver_employee_id,
            d.phone          AS driver_phone,
            v.plate_number   AS vehicle_plate,
            v.brand          AS vehicle_brand,
            v.model          AS vehicle_model,
            v.vehicle_type   AS vehicle_type,
            v.capacity       AS vehicle_capacity,
            u.full_name      AS created_by_name
     FROM schedules s
     LEFT JOIN drivers  d ON s.driver_id  = d.driver_id
     LEFT JOIN vehicles v ON s.vehicle_id = v.vehicle_id
     LEFT JOIN users    u ON s.created_by = u.user_id
     WHERE s.schedule_id = ?"
);
$stmt->bind_param('i', $schedule_id);
$stmt->execute();
$schedule = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$schedule) {
    setFlash('danger', 'Schedule not found.');
    header('Location: ' . SITE_URL . '/admin/schedules.php');
    exit();
}

require_once '../includes/header.php';
require_once '../includes/sidebar.php';
?>

<main class="main-content">

    <!-- ── Desktop Top Navbar ─────────────────────────────────── -->
    <div class="d-none d-lg-flex align-items-center justify-content-between mb-4 pb-3"
         style="border-bottom: 2px solid #e5e9f0;">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1" style="font-size:0.78rem;">
                    <li class="breadcrumb-item">
                        <a href="<?php echo SITE_URL; ?>/admin/dashboard.php"
                           class="text-decoration-none" style="color:var(--uis-primary);">
                            <i class="fas fa-home me-1"></i>Home
                        </a>
                    </li>
                    <li class="breadcrumb-item">
                        <a href="<?php echo SITE_URL; ?>/admin/schedules.php"
                           class="text-decoration-none" style="color:var(--uis-primary);">Schedules</a>
                    </li>
                    <li class="breadcrumb-item active" aria-current="page">
                        #<?php echo $schedule_id; ?>
                    </li>
                </ol>
            </nav>
            <h1 class="page-title mb-0" style="font-size:1.6rem;">
                <i class="fas fa-calendar-days me-2" style="color:var(--uis-primary);"></i>
                Schedule Details
            </h1>
        </div>
        <div class="d-flex gap-2">
            <a href="<?php echo SITE_URL; ?>/admin/edit_schedule.php?id=<?php echo $schedule_id; ?>"
               class="btn btn-uis-primary btn-sm">
                <i class="fas fa-pen me-1"></i>Edit Schedule
            </a>
            <a href="<?php echo SITE_URL; ?>/admin/schedules.php"
               class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i>Back
            </a>
        </div>
    </div>

    <?php showFlash(); ?>

    <div class="row g-3">

        <!-- ── Schedule Info Card ─────────────────────────────── -->
        <div class="col-12 col-lg-8">
            <div class="content-card">
                <div class="content-card-header">
                    <h5 class="content-card-title">
                        <i class="fas fa-info-circle"></i> Trip Information
                    </h5>
                    <span class="badge <?php echo statusBadgeClass($schedule['status']); ?>" style="font-size:0.82rem;">
                        <?php echo ucfirst(str_replace('_', ' ', $schedule['status'])); ?>
                    </span>
                </div>
                <div class="content-card-body">
                    <div class="row g-3">

                        <div class="col-sm-6">
                            <div style="font-size:0.75rem;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px;">
                                Destination
                            </div>
                            <div style="font-size:0.95rem;font-weight:600;color:#1a2035;">
                                <i class="fas fa-location-dot me-1" style="color:var(--uis-primary);"></i>
                                <?php echo htmlspecialchars($schedule['destination']); ?>
                            </div>
                        </div>

                        <div class="col-sm-6">
                            <div style="font-size:0.75rem;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px;">
                                Purpose
                            </div>
                            <div style="font-size:0.95rem;color:#374151;">
                                <?php echo htmlspecialchars($schedule['purpose'] ?: '—'); ?>
                            </div>
                        </div>

                        <div class="col-sm-4">
                            <div style="font-size:0.75rem;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px;">
                                Trip Date
                            </div>
                            <div style="font-size:0.9rem;font-weight:600;color:#1a2035;">
                                <i class="fas fa-calendar me-1" style="color:var(--uis-primary);"></i>
                                <?php echo formatDate($schedule['trip_date']); ?>
                            </div>
                        </div>

                        <div class="col-sm-4">
                            <div style="font-size:0.75rem;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px;">
                                Start Time
                            </div>
                            <div style="font-size:0.9rem;font-weight:600;color:#1a2035;">
                                <i class="fas fa-clock me-1" style="color:var(--uis-secondary);"></i>
                                <?php echo date('h:i A', strtotime($schedule['start_time'])); ?>
                            </div>
                        </div>

                        <div class="col-sm-4">
                            <div style="font-size:0.75rem;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px;">
                                End Time
                            </div>
                            <div style="font-size:0.9rem;font-weight:600;color:#1a2035;">
                                <i class="fas fa-clock me-1" style="color:#6b7280;"></i>
                                <?php echo date('h:i A', strtotime($schedule['end_time'])); ?>
                            </div>
                        </div>

                        <div class="col-sm-4">
                            <div style="font-size:0.75rem;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px;">
                                Passengers
                            </div>
                            <div style="font-size:0.9rem;color:#374151;">
                                <i class="fas fa-users me-1" style="color:var(--uis-primary);"></i>
                                <?php echo (int)$schedule['passenger_count']; ?> pax
                            </div>
                        </div>

                        <div class="col-sm-4">
                            <div style="font-size:0.75rem;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px;">
                                Trip Type
                            </div>
                            <div style="font-size:0.9rem;color:#374151;">
                                <?php if (($schedule['trip_type'] ?? 'regular') === 'top_management'): ?>
                                <span class="badge" style="background:#7c3aed;">
                                    <i class="fas fa-crown me-1"></i>Top Management
                                </span>
                                <?php else: ?>
                                <span class="badge bg-secondary">
                                    <i class="fas fa-car me-1"></i>Regular
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="col-sm-4">
                            <div style="font-size:0.75rem;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px;">
                                Priority Score
                            </div>
                            <div style="font-size:0.9rem;color:#374151;">
                                <i class="fas fa-star me-1" style="color:#f59e0b;"></i>
                                <?php echo number_format((float)$schedule['priority_score'], 2); ?>
                            </div>
                        </div>

                        <?php if (!empty($schedule['notes'])): ?>
                        <div class="col-12">
                            <div style="font-size:0.75rem;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px;">
                                Notes
                            </div>
                            <div style="font-size:0.88rem;color:#374151;background:#f8fafc;border-radius:8px;padding:10px 14px;border-left:3px solid var(--uis-primary);">
                                <?php echo nl2br(htmlspecialchars($schedule['notes'])); ?>
                            </div>
                        </div>
                        <?php endif; ?>

                    </div>
                </div>
            </div>
        </div>

        <!-- ── Assignment + Meta Card ─────────────────────────── -->
        <div class="col-12 col-lg-4">

            <!-- Driver -->
            <div class="content-card mb-3">
                <div class="content-card-header">
                    <h5 class="content-card-title">
                        <i class="fas fa-id-card"></i> Assigned Driver
                    </h5>
                </div>
                <div class="content-card-body">
                    <?php if (!empty($schedule['driver_name'])): ?>
                    <div class="d-flex align-items-center gap-3">
                        <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                             style="width:48px;height:48px;background:linear-gradient(135deg,var(--uis-primary),var(--uis-secondary));color:#fff;font-size:1rem;font-weight:700;">
                            <?php
                                $parts = array_filter(explode(' ', trim($schedule['driver_name'])));
                                $di = '';
                                foreach (array_slice($parts, 0, 2) as $p) { $di .= strtoupper($p[0]); }
                                echo htmlspecialchars($di ?: 'D');
                            ?>
                        </div>
                        <div>
                            <div style="font-weight:600;color:#1a2035;font-size:0.92rem;">
                                <?php echo htmlspecialchars($schedule['driver_name']); ?>
                            </div>
                            <?php if (!empty($schedule['driver_employee_id'])): ?>
                            <div style="font-size:0.78rem;color:#6b7280;">
                                ID: <?php echo htmlspecialchars($schedule['driver_employee_id']); ?>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($schedule['driver_phone'])): ?>
                            <div style="font-size:0.78rem;color:#6b7280;">
                                <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($schedule['driver_phone']); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-3" style="color:#9ca3af;">
                        <i class="fas fa-user-slash fa-lg mb-2 d-block"></i>
                        <div style="font-size:0.84rem;">No driver assigned</div>
                        <a href="<?php echo SITE_URL; ?>/admin/auto_assign.php?schedule_id=<?php echo $schedule_id; ?>"
                           class="btn btn-sm btn-uis-primary mt-2">
                            <i class="fas fa-wand-magic-sparkles me-1"></i>Auto Assign
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Vehicle -->
            <div class="content-card mb-3">
                <div class="content-card-header">
                    <h5 class="content-card-title">
                        <i class="fas fa-car"></i> Assigned Vehicle
                    </h5>
                </div>
                <div class="content-card-body">
                    <?php if (!empty($schedule['vehicle_plate'])): ?>
                    <div class="d-flex align-items-center gap-3">
                        <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                             style="width:48px;height:48px;background:linear-gradient(135deg,#059669,#047857);color:#fff;font-size:0.9rem;">
                            <i class="fas fa-car"></i>
                        </div>
                        <div>
                            <div style="font-weight:700;color:#1a2035;font-size:0.95rem;font-family:monospace;">
                                <?php echo htmlspecialchars($schedule['vehicle_plate']); ?>
                            </div>
                            <?php if (!empty($schedule['vehicle_brand'])): ?>
                            <div style="font-size:0.78rem;color:#6b7280;">
                                <?php echo htmlspecialchars($schedule['vehicle_brand'] . ' ' . $schedule['vehicle_model']); ?>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($schedule['vehicle_type'])): ?>
                            <div style="font-size:0.78rem;color:#6b7280;">
                                <?php echo ucfirst(htmlspecialchars($schedule['vehicle_type'])); ?>
                                <?php if (!empty($schedule['vehicle_capacity'])): ?>
                                · <?php echo (int)$schedule['vehicle_capacity']; ?> seats
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-3" style="color:#9ca3af;">
                        <i class="fas fa-car-burst fa-lg mb-2 d-block"></i>
                        <div style="font-size:0.84rem;">No vehicle assigned</div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Meta -->
            <div class="content-card">
                <div class="content-card-header">
                    <h5 class="content-card-title">
                        <i class="fas fa-circle-info"></i> Record Info
                    </h5>
                </div>
                <div class="content-card-body">
                    <div class="d-flex flex-column gap-2" style="font-size:0.83rem;">
                        <div class="d-flex justify-content-between">
                            <span style="color:#6b7280;">Schedule ID</span>
                            <span style="font-weight:600;color:#1a2035;">#<?php echo $schedule_id; ?></span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span style="color:#6b7280;">Created By</span>
                            <span style="font-weight:600;color:#1a2035;">
                                <?php echo htmlspecialchars($schedule['created_by_name'] ?? 'System'); ?>
                            </span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span style="color:#6b7280;">Created At</span>
                            <span style="color:#374151;">
                                <?php echo date('d M Y, h:i A', strtotime($schedule['created_at'])); ?>
                            </span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span style="color:#6b7280;">Last Updated</span>
                            <span style="color:#374151;">
                                <?php echo date('d M Y, h:i A', strtotime($schedule['updated_at'])); ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

        </div><!-- /.col-lg-4 -->
    </div><!-- /.row -->

    <!-- ── Action Buttons (mobile) ─────────────────────────────── -->
    <div class="d-flex gap-2 mt-3 d-lg-none">
        <a href="<?php echo SITE_URL; ?>/admin/edit_schedule.php?id=<?php echo $schedule_id; ?>"
           class="btn btn-uis-primary flex-fill">
            <i class="fas fa-pen me-1"></i>Edit
        </a>
        <a href="<?php echo SITE_URL; ?>/admin/schedules.php"
           class="btn btn-outline-secondary flex-fill">
            <i class="fas fa-arrow-left me-1"></i>Back
        </a>
    </div>

</main>

<?php require_once '../includes/footer.php'; ?>
