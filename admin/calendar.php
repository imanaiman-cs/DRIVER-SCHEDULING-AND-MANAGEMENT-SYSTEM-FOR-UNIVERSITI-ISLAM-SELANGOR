<?php
// ============================================================
// UIS Driver Scheduling and Management System
// admin/calendar.php  –  Calendar View of All Schedules
// Universiti Islam Selangor (UIS)
// ============================================================

$page_title   = 'Schedule Calendar';
$current_page = 'calendar.php';

require_once '../config/database.php';
requireAdmin();

// ============================================================
// FETCH ALL SCHEDULES WITH DRIVER AND VEHICLE INFO
// ============================================================
$schedule_sql = "
    SELECT
        s.schedule_id,
        s.trip_date,
        s.start_time,
        s.end_time,
        s.destination,
        s.purpose,
        s.status,
        s.trip_type,
        s.passenger_count,
        d.name        AS driver_name,
        v.plate_number
    FROM schedules s
    LEFT JOIN drivers  d ON s.driver_id  = d.driver_id
    LEFT JOIN vehicles v ON s.vehicle_id = v.vehicle_id
    ORDER BY s.trip_date, s.start_time
";

$schedule_result = $conn->query($schedule_sql);
$schedules_raw   = [];
if ($schedule_result) {
    while ($row = $schedule_result->fetch_assoc()) {
        $schedules_raw[] = $row;
    }
}

// ============================================================
// STATS BY STATUS
// ============================================================
$status_counts = [
    'pending'     => 0,
    'approved'    => 0,
    'in_progress' => 0,
    'completed'   => 0,
    'cancelled'   => 0,
];
$total_schedules = 0;

foreach ($schedules_raw as $s) {
    $total_schedules++;
    $st = $s['status'] ?? 'pending';
    if (isset($status_counts[$st])) {
        $status_counts[$st]++;
    }
}

// ============================================================
// BUILD FULLCALENDAR EVENTS JSON
// ============================================================
$status_colors = [
    'pending'     => '#f59e0b',
    'approved'    => '#3b82f6',
    'in_progress' => '#06b6d4',
    'completed'   => '#10b981',
    'cancelled'   => '#9ca3af',
];

$fc_events = [];
foreach ($schedules_raw as $s) {
    $color  = $status_colors[$s['status']] ?? '#6b7280';

    // Build title with optional crown prefix for top management trips
    $prefix = '';
    if (isset($s['trip_type']) && $s['trip_type'] === 'top_management') {
        $prefix = '★ ';
    }

    $driver_label = !empty($s['driver_name']) ? $s['driver_name'] : 'Unassigned';
    $title        = $prefix . htmlspecialchars($s['destination'], ENT_QUOTES, 'UTF-8')
                  . ' – ' . htmlspecialchars($driver_label, ENT_QUOTES, 'UTF-8');

    // Build start / end datetime strings
    $trip_date  = $s['trip_date'] ?? date('Y-m-d');
    $start_time = !empty($s['start_time']) ? substr($s['start_time'], 0, 5) : '00:00';
    $end_time   = !empty($s['end_time'])   ? substr($s['end_time'],   0, 5) : null;

    $event = [
        'id'              => (int) $s['schedule_id'],
        'title'           => $title,
        'start'           => $trip_date . 'T' . $start_time,
        'color'           => $color,
        'borderColor'     => $color,
        'extendedProps'   => [
            'schedule_id'     => (int) $s['schedule_id'],
            'destination'     => $s['destination'] ?? '',
            'purpose'         => $s['purpose'] ?? '',
            'status'          => $s['status'] ?? '',
            'trip_type'       => $s['trip_type'] ?? 'regular',
            'passenger_count' => (int) ($s['passenger_count'] ?? 0),
            'driver_name'     => $s['driver_name'] ?? null,
            'plate_number'    => $s['plate_number'] ?? null,
            'trip_date'       => $trip_date,
            'start_time'      => $s['start_time'] ?? null,
            'end_time'        => $s['end_time'] ?? null,
        ],
    ];

    if ($end_time !== null) {
        $event['end'] = $trip_date . 'T' . $end_time;
    }

    $fc_events[] = $event;
}

$fc_events_json = json_encode($fc_events, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);

// Current admin display name
$admin_name = htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Admin', ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> | UIS Driver Management</title>

    <!-- Bootstrap 5.3 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome 6.4 -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- Google Fonts – Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <!-- Custom CSS -->
    <link href="<?php echo SITE_URL; ?>/assets/css/style.css" rel="stylesheet">

    <style>
        /* ── FullCalendar overrides ──────────────────────────── */
        #calendarWrapper {
            background: #fff;
            border-radius: 14px;
            padding: 1.25rem 1.25rem 1.5rem;
            box-shadow: 0 2px 12px rgba(0, 53, 128, .10);
        }

        .fc .fc-toolbar-title {
            font-size: 1.15rem;
            font-weight: 700;
            color: #003580;
        }

        .fc .fc-button-primary {
            background-color: #003580;
            border-color: #003580;
            font-size: 0.82rem;
            font-weight: 600;
            border-radius: 8px;
            padding: 5px 12px;
            transition: background-color 0.18s, border-color 0.18s;
        }

        .fc .fc-button-primary:not(:disabled):hover,
        .fc .fc-button-primary:not(:disabled):focus {
            background-color: #002465;
            border-color: #002465;
        }

        .fc .fc-button-primary:not(:disabled).fc-button-active {
            background-color: #002465;
            border-color: #002465;
        }

        .fc .fc-button-primary:disabled {
            background-color: #6b7280;
            border-color: #6b7280;
        }

        .fc .fc-col-header-cell-cushion {
            font-weight: 600;
            color: #374151;
            font-size: 0.82rem;
        }

        .fc .fc-daygrid-day-number {
            font-size: 0.82rem;
            color: #374151;
        }

        .fc .fc-event {
            border-radius: 6px;
            font-size: 0.76rem;
            font-weight: 500;
            padding: 1px 4px;
            cursor: pointer;
            border: none;
        }

        .fc .fc-event:hover {
            opacity: 0.88;
        }

        .fc .fc-day-today {
            background: rgba(0, 53, 128, 0.05) !important;
        }

        .fc .fc-day-today .fc-daygrid-day-number {
            background: #003580;
            color: #fff;
            border-radius: 50%;
            width: 26px;
            height: 26px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .fc .fc-list-event:hover td {
            background: #f0f4fb;
        }

        .fc .fc-list-day-cushion {
            background: #f0f4fb !important;
            font-weight: 600;
            color: #003580;
            font-size: 0.82rem;
        }

        .fc .fc-list-event-title a {
            color: #1a2035;
            font-size: 0.83rem;
        }

        /* ── Legend dots ─────────────────────────────────────── */
        .legend-dot {
            width: 13px;
            height: 13px;
            border-radius: 50%;
            display: inline-block;
            flex-shrink: 0;
        }

        /* ── Stat mini-cards ─────────────────────────────────── */
        .cal-stat-card {
            border: none;
            border-radius: 12px;
            padding: 0.85rem 1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .cal-stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 1.1rem;
            color: #fff;
        }

        .cal-stat-value {
            font-size: 1.5rem;
            font-weight: 800;
            line-height: 1;
            color: #1a2035;
        }

        .cal-stat-label {
            font-size: 0.73rem;
            color: #6b7280;
            margin-top: 2px;
        }

        /* ── Modal detail rows ───────────────────────────────── */
        .detail-row {
            display: flex;
            align-items: flex-start;
            gap: 0.6rem;
            margin-bottom: 0.6rem;
            font-size: 0.86rem;
        }

        .detail-row .detail-icon {
            width: 28px;
            height: 28px;
            border-radius: 7px;
            background: #f0f4fb;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            color: #003580;
            font-size: 0.75rem;
        }

        .detail-row .detail-label {
            font-weight: 600;
            color: #374151;
            min-width: 110px;
        }

        .detail-row .detail-value {
            color: #1a2035;
        }

        @media (max-width: 575.98px) {
            #calendarWrapper {
                padding: 0.75rem 0.5rem 1rem;
            }

            .fc .fc-toolbar {
                flex-direction: column;
                gap: 0.5rem;
            }
        }
    </style>
</head>
<body>

<?php require_once '../includes/sidebar.php'; ?>

<!-- ================================================================
     MAIN CONTENT
     ================================================================ -->
<main class="main-content p-4">

    <!-- ── Desktop Top Navbar ─────────────────────────────────── -->
    <div class="d-none d-lg-flex align-items-center justify-content-between mb-4 pb-3"
         style="border-bottom: 2px solid #e5e9f0;">

        <!-- Left: breadcrumb + title -->
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1" style="font-size: 0.78rem;">
                    <li class="breadcrumb-item">
                        <a href="<?php echo SITE_URL; ?>/admin/dashboard.php"
                           class="text-decoration-none" style="color: var(--uis-primary);">
                            <i class="fas fa-home me-1"></i>Home
                        </a>
                    </li>
                    <li class="breadcrumb-item">
                        <a href="<?php echo SITE_URL; ?>/admin/schedules.php"
                           class="text-decoration-none" style="color: var(--uis-primary);">
                            Schedules
                        </a>
                    </li>
                    <li class="breadcrumb-item active" aria-current="page">Calendar View</li>
                </ol>
            </nav>
            <h1 class="page-title mb-0" style="font-size: 1.6rem;">
                <i class="fas fa-calendar-week me-2" style="color: var(--uis-primary);"></i>Schedule Calendar
            </h1>
            <p class="page-subtitle mb-0">Visual overview of all trip schedules by date.</p>
        </div>

        <!-- Right: quick links + user dropdown -->
        <div class="d-flex align-items-center gap-2">
            <a href="<?php echo SITE_URL; ?>/admin/add_schedule.php"
               class="btn btn-sm"
               style="background: #003580; color: #fff; border-radius: 8px; font-size: 0.82rem; font-weight: 600; padding: 6px 14px;">
                <i class="fas fa-calendar-plus me-1"></i>New Schedule
            </a>
            <a href="<?php echo SITE_URL; ?>/admin/schedules.php"
               class="btn btn-sm btn-outline-secondary"
               style="border-radius: 8px; font-size: 0.82rem; padding: 6px 14px;">
                <i class="fas fa-list me-1"></i>List View
            </a>
        </div>
    </div>

    <?php showFlash(); ?>

    <!-- ================================================================
         PAGE HEADER BANNER
         ================================================================ -->
    <div style="background: linear-gradient(135deg, #003580 0%, #0056b3 100%); border-radius: 14px; color: #fff; padding: 1.6rem 2rem; margin-bottom: 1.5rem; box-shadow: 0 4px 16px rgba(0,53,128,.20);">
        <div class="d-flex flex-column flex-sm-row align-items-start align-items-sm-center justify-content-between gap-3">
            <div>
                <h2 class="mb-1" style="font-size: 1.3rem; font-weight: 800; letter-spacing: -0.01em;">
                    <i class="fas fa-calendar-week me-2" style="opacity: 0.85;"></i>Trip Schedule Calendar
                </h2>
                <p class="mb-0" style="font-size: 0.85rem; opacity: 0.8;">
                    Click any event to view full trip details. Colour-coded by schedule status.
                </p>
            </div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <a href="<?php echo SITE_URL; ?>/admin/add_schedule.php"
                   class="btn btn-sm"
                   style="background: rgba(255,255,255,0.18); color: #fff; border: 1.5px solid rgba(255,255,255,0.35); border-radius: 8px; font-size: 0.82rem; font-weight: 600;">
                    <i class="fas fa-calendar-plus me-1"></i>New Schedule
                </a>
                <a href="<?php echo SITE_URL; ?>/admin/schedules.php"
                   class="btn btn-sm"
                   style="background: rgba(255,255,255,0.18); color: #fff; border: 1.5px solid rgba(255,255,255,0.35); border-radius: 8px; font-size: 0.82rem; font-weight: 600;">
                    <i class="fas fa-table-list me-1"></i>List View
                </a>
            </div>
        </div>
    </div>

    <!-- ================================================================
         STATS SUMMARY ROW
         ================================================================ -->
    <div class="row g-3 mb-4">

        <!-- Total Schedules -->
        <div class="col-6 col-sm-4 col-lg-2">
            <div class="cal-stat-card" style="background: #eff6ff; border: 1.5px solid #bfdbfe;">
                <div class="cal-stat-icon" style="background: #003580;">
                    <i class="fas fa-calendar-days"></i>
                </div>
                <div>
                    <div class="cal-stat-value"><?php echo number_format($total_schedules); ?></div>
                    <div class="cal-stat-label">Total</div>
                </div>
            </div>
        </div>

        <!-- Pending -->
        <div class="col-6 col-sm-4 col-lg-2">
            <div class="cal-stat-card" style="background: #fffbeb; border: 1.5px solid #fde68a;">
                <div class="cal-stat-icon" style="background: #f59e0b;">
                    <i class="fas fa-clock"></i>
                </div>
                <div>
                    <div class="cal-stat-value"><?php echo number_format($status_counts['pending']); ?></div>
                    <div class="cal-stat-label">Pending</div>
                </div>
            </div>
        </div>

        <!-- Approved -->
        <div class="col-6 col-sm-4 col-lg-2">
            <div class="cal-stat-card" style="background: #eff6ff; border: 1.5px solid #bfdbfe;">
                <div class="cal-stat-icon" style="background: #3b82f6;">
                    <i class="fas fa-circle-check"></i>
                </div>
                <div>
                    <div class="cal-stat-value"><?php echo number_format($status_counts['approved']); ?></div>
                    <div class="cal-stat-label">Approved</div>
                </div>
            </div>
        </div>

        <!-- In Progress -->
        <div class="col-6 col-sm-4 col-lg-2">
            <div class="cal-stat-card" style="background: #ecfeff; border: 1.5px solid #a5f3fc;">
                <div class="cal-stat-icon" style="background: #06b6d4;">
                    <i class="fas fa-road"></i>
                </div>
                <div>
                    <div class="cal-stat-value"><?php echo number_format($status_counts['in_progress']); ?></div>
                    <div class="cal-stat-label">In Progress</div>
                </div>
            </div>
        </div>

        <!-- Completed -->
        <div class="col-6 col-sm-4 col-lg-2">
            <div class="cal-stat-card" style="background: #ecfdf5; border: 1.5px solid #a7f3d0;">
                <div class="cal-stat-icon" style="background: #10b981;">
                    <i class="fas fa-flag-checkered"></i>
                </div>
                <div>
                    <div class="cal-stat-value"><?php echo number_format($status_counts['completed']); ?></div>
                    <div class="cal-stat-label">Completed</div>
                </div>
            </div>
        </div>

        <!-- Cancelled -->
        <div class="col-6 col-sm-4 col-lg-2">
            <div class="cal-stat-card" style="background: #f9fafb; border: 1.5px solid #e5e7eb;">
                <div class="cal-stat-icon" style="background: #9ca3af;">
                    <i class="fas fa-ban"></i>
                </div>
                <div>
                    <div class="cal-stat-value"><?php echo number_format($status_counts['cancelled']); ?></div>
                    <div class="cal-stat-label">Cancelled</div>
                </div>
            </div>
        </div>

    </div><!-- /.row stats -->

    <!-- ================================================================
         LEGEND + CALENDAR CARD
         ================================================================ -->
    <div class="row g-3 mb-4">
        <div class="col-12">
            <!-- Legend bar -->
            <div class="d-flex flex-wrap align-items-center gap-3 mb-3 px-1">
                <span style="font-size: 0.78rem; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.06em;">Legend:</span>

                <div class="d-flex align-items-center gap-1">
                    <span class="legend-dot" style="background: #f59e0b;"></span>
                    <span style="font-size: 0.8rem; color: #374151; font-weight: 500;">Pending</span>
                </div>
                <div class="d-flex align-items-center gap-1">
                    <span class="legend-dot" style="background: #3b82f6;"></span>
                    <span style="font-size: 0.8rem; color: #374151; font-weight: 500;">Approved</span>
                </div>
                <div class="d-flex align-items-center gap-1">
                    <span class="legend-dot" style="background: #06b6d4;"></span>
                    <span style="font-size: 0.8rem; color: #374151; font-weight: 500;">In Progress</span>
                </div>
                <div class="d-flex align-items-center gap-1">
                    <span class="legend-dot" style="background: #10b981;"></span>
                    <span style="font-size: 0.8rem; color: #374151; font-weight: 500;">Completed</span>
                </div>
                <div class="d-flex align-items-center gap-1">
                    <span class="legend-dot" style="background: #9ca3af;"></span>
                    <span style="font-size: 0.8rem; color: #374151; font-weight: 500;">Cancelled</span>
                </div>
                <div class="d-flex align-items-center gap-1 ms-sm-2">
                    <span style="font-size: 0.88rem; color: #d97706;">&#9733;</span>
                    <span style="font-size: 0.8rem; color: #374151; font-weight: 500;">Top Management Trip</span>
                </div>
            </div>

            <!-- FullCalendar wrapper -->
            <div id="calendarWrapper">
                <div id="scheduleCalendar"></div>
            </div>
        </div>
    </div>

</main><!-- /.main-content -->

<!-- ================================================================
     TRIP DETAIL MODAL
     ================================================================ -->
<div class="modal fade" id="tripDetailModal" tabindex="-1" aria-labelledby="tripDetailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-md">
        <div class="modal-content" style="border: none; border-radius: 16px; overflow: hidden; box-shadow: 0 8px 32px rgba(0,53,128,.18);">

            <!-- Modal Header -->
            <div class="modal-header" id="modalHeader"
                 style="background: linear-gradient(135deg, #003580 0%, #0056b3 100%); padding: 1rem 1.25rem; border: none;">
                <div class="d-flex align-items-center gap-2 w-100">
                    <div style="width: 36px; height: 36px; border-radius: 9px; background: rgba(255,255,255,0.15); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                        <i class="fas fa-calendar-check" style="color: #fff; font-size: 0.95rem;"></i>
                    </div>
                    <div class="flex-grow-1 min-w-0">
                        <h5 class="modal-title mb-0" id="tripDetailModalLabel"
                            style="color: #fff; font-size: 1rem; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                            Trip Details
                        </h5>
                        <div id="modalScheduleId" style="font-size: 0.74rem; color: rgba(255,255,255,0.7);"></div>
                    </div>
                    <span id="modalStatusBadge" class="badge ms-auto flex-shrink-0" style="font-size: 0.75rem; padding: 5px 10px; border-radius: 20px;"></span>
                </div>
                <button type="button" class="btn-close btn-close-white ms-2 flex-shrink-0" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <!-- Modal Body -->
            <div class="modal-body p-4">
                <div id="modalTripTypeAlert" class="alert mb-3 d-none"
                     style="background: #fffbeb; border: 1.5px solid #fde68a; color: #92400e; border-radius: 8px; font-size: 0.82rem; padding: 0.5rem 0.85rem;">
                    <i class="fas fa-star me-1" style="color: #d97706;"></i>
                    <strong>Top Management Trip</strong> — Priority handling required.
                </div>

                <div class="detail-row">
                    <div class="detail-icon"><i class="fas fa-map-marker-alt"></i></div>
                    <div>
                        <div class="detail-label">Destination</div>
                        <div class="detail-value" id="modalDestination"></div>
                    </div>
                </div>

                <div class="detail-row">
                    <div class="detail-icon"><i class="fas fa-align-left"></i></div>
                    <div>
                        <div class="detail-label">Purpose</div>
                        <div class="detail-value" id="modalPurpose"></div>
                    </div>
                </div>

                <div class="detail-row">
                    <div class="detail-icon"><i class="fas fa-calendar-day"></i></div>
                    <div>
                        <div class="detail-label">Trip Date</div>
                        <div class="detail-value" id="modalTripDate"></div>
                    </div>
                </div>

                <div class="detail-row">
                    <div class="detail-icon"><i class="fas fa-clock"></i></div>
                    <div>
                        <div class="detail-label">Time</div>
                        <div class="detail-value" id="modalTime"></div>
                    </div>
                </div>

                <div class="detail-row">
                    <div class="detail-icon"><i class="fas fa-user-tie"></i></div>
                    <div>
                        <div class="detail-label">Driver</div>
                        <div class="detail-value" id="modalDriver"></div>
                    </div>
                </div>

                <div class="detail-row">
                    <div class="detail-icon"><i class="fas fa-car"></i></div>
                    <div>
                        <div class="detail-label">Vehicle</div>
                        <div class="detail-value" id="modalVehicle"></div>
                    </div>
                </div>

                <div class="detail-row">
                    <div class="detail-icon"><i class="fas fa-users"></i></div>
                    <div>
                        <div class="detail-label">Passengers</div>
                        <div class="detail-value" id="modalPassengers"></div>
                    </div>
                </div>
            </div>

            <!-- Modal Footer -->
            <div class="modal-footer" style="border-top: 1px solid #f0f4f8; padding: 0.85rem 1.25rem; gap: 0.5rem;">
                <a id="modalViewBtn" href="#"
                   class="btn btn-sm"
                   style="background: #003580; color: #fff; border-radius: 8px; font-size: 0.82rem; font-weight: 600; padding: 6px 16px;">
                    <i class="fas fa-eye me-1"></i>View Full Details
                </a>
                <a id="modalEditBtn" href="#"
                   class="btn btn-sm btn-outline-secondary"
                   style="border-radius: 8px; font-size: 0.82rem; padding: 6px 16px;">
                    <i class="fas fa-pen me-1"></i>Edit
                </a>
                <button type="button" class="btn btn-sm btn-light"
                        style="border-radius: 8px; font-size: 0.82rem; padding: 6px 16px;"
                        data-bs-dismiss="modal">
                    Close
                </button>
            </div>

        </div>
    </div>
</div><!-- /#tripDetailModal -->

<!-- ================================================================
     SCRIPTS
     ================================================================ -->
<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<!-- Bootstrap 5.3 JS Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- FullCalendar v6 -->
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js"></script>
<!-- Custom CSS -->
<script src="<?php echo SITE_URL; ?>/assets/js/main.js"></script>

<script>
(function () {
    'use strict';

    /* ── Schedule data from PHP ──────────────────────────────── */
    var FC_EVENTS = <?php echo $fc_events_json; ?>;

    /* ── Status label map ────────────────────────────────────── */
    var STATUS_LABELS = {
        'pending':     'Pending',
        'approved':    'Approved',
        'in_progress': 'In Progress',
        'completed':   'Completed',
        'cancelled':   'Cancelled'
    };

    /* ── Status colour map (for modal header accent) ─────────── */
    var STATUS_COLORS = {
        'pending':     '#f59e0b',
        'approved':    '#3b82f6',
        'in_progress': '#06b6d4',
        'completed':   '#10b981',
        'cancelled':   '#9ca3af'
    };

    /* ── Bootstrap modal instance ────────────────────────────── */
    var tripModal      = new bootstrap.Modal(document.getElementById('tripDetailModal'));
    var SITE_URL       = '<?php echo SITE_URL; ?>';

    /* ── Helpers ─────────────────────────────────────────────── */
    function esc(str) {
        if (!str) return '';
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(str));
        return d.innerHTML;
    }

    function formatDateDisplay(ymd) {
        if (!ymd) return '—';
        var parts  = ymd.split('-');
        var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        var d      = parseInt(parts[2], 10);
        var m      = parseInt(parts[1], 10) - 1;
        var y      = parts[0];
        return d + ' ' + months[m] + ' ' + y;
    }

    function formatTimeDisplay(timeStr) {
        if (!timeStr) return '—';
        // timeStr may be "HH:MM:SS" or "HH:MM"
        var parts = timeStr.split(':');
        var h     = parseInt(parts[0], 10);
        var m     = parts[1] || '00';
        var ampm  = h >= 12 ? 'pm' : 'am';
        var h12   = h % 12 || 12;
        return h12 + ':' + m + ' ' + ampm;
    }

    /* ── Populate and open the modal ─────────────────────────── */
    function openTripModal(info) {
        var ep  = info.event.extendedProps;
        var sid = ep.schedule_id;
        var st  = ep.status || 'pending';

        // Header
        document.getElementById('tripDetailModalLabel').textContent =
            esc(ep.destination) || 'Trip Details';
        document.getElementById('modalScheduleId').textContent = 'Schedule #' + sid;

        // Status badge
        var badgeEl    = document.getElementById('modalStatusBadge');
        badgeEl.textContent  = STATUS_LABELS[st] || st;
        badgeEl.style.background = STATUS_COLORS[st] || '#6b7280';
        badgeEl.style.color      = (st === 'pending') ? '#1a2035' : '#fff';

        // Top management alert
        var tmAlert = document.getElementById('modalTripTypeAlert');
        if (ep.trip_type === 'top_management') {
            tmAlert.classList.remove('d-none');
        } else {
            tmAlert.classList.add('d-none');
        }

        // Detail fields
        document.getElementById('modalDestination').textContent =
            ep.destination || '—';

        document.getElementById('modalPurpose').textContent =
            ep.purpose || '—';

        document.getElementById('modalTripDate').textContent =
            formatDateDisplay(ep.trip_date);

        var timeStr = formatTimeDisplay(ep.start_time);
        if (ep.end_time) {
            timeStr += ' – ' + formatTimeDisplay(ep.end_time);
        }
        document.getElementById('modalTime').textContent = timeStr;

        document.getElementById('modalDriver').textContent =
            ep.driver_name || 'Unassigned';

        document.getElementById('modalVehicle').textContent =
            ep.plate_number || 'Unassigned';

        document.getElementById('modalPassengers').textContent =
            (ep.passenger_count !== undefined && ep.passenger_count !== null)
                ? ep.passenger_count + ' pax'
                : '—';

        // Action buttons
        document.getElementById('modalViewBtn').href =
            SITE_URL + '/admin/view_schedule.php?id=' + sid;
        document.getElementById('modalEditBtn').href =
            SITE_URL + '/admin/edit_schedule.php?id=' + sid;

        tripModal.show();
    }

    /* ── Initialise FullCalendar ─────────────────────────────── */
    document.addEventListener('DOMContentLoaded', function () {
        var calEl = document.getElementById('scheduleCalendar');
        if (!calEl) return;

        var calendar = new FullCalendar.Calendar(calEl, {
            initialView:     'dayGridMonth',
            headerToolbar: {
                left:   'prev,next today',
                center: 'title',
                right:  'dayGridMonth,timeGridWeek,listMonth'
            },
            buttonText: {
                today:        'Today',
                month:        'Month',
                week:         'Week',
                listMonth:    'List'
            },
            height:          'auto',
            aspectRatio:     1.8,
            events:          FC_EVENTS,
            eventDisplay:    'block',
            dayMaxEvents:    4,
            moreLinkText:    function (n) { return '+' + n + ' more'; },
            nowIndicator:    true,
            weekNumbers:     false,
            firstDay:        1,        // Monday first
            eventTimeFormat: {
                hour:   '2-digit',
                minute: '2-digit',
                hour12: true
            },
            eventClick: function (info) {
                info.jsEvent.preventDefault();
                openTripModal(info);
            },
            eventMouseEnter: function (info) {
                info.el.style.transform  = 'translateY(-1px)';
                info.el.style.boxShadow  = '0 4px 10px rgba(0,0,0,0.18)';
                info.el.style.transition = 'transform 0.15s, box-shadow 0.15s';
                info.el.style.zIndex     = '9';
            },
            eventMouseLeave: function (info) {
                info.el.style.transform = '';
                info.el.style.boxShadow = '';
                info.el.style.zIndex    = '';
            }
        });

        calendar.render();

        /* Redraw on sidebar toggle to fix layout */
        var sidebarEl = document.getElementById('mainSidebar');
        if (sidebarEl) {
            var observer = new MutationObserver(function () {
                setTimeout(function () { calendar.updateSize(); }, 310);
            });
            observer.observe(sidebarEl, { attributes: true, attributeFilter: ['class'] });
        }
    });

})();
</script>

</body>
</html>
