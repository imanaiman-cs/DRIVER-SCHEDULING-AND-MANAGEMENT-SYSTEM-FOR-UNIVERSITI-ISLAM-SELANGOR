<?php
// ============================================================
// UIS Driver Scheduling and Management System
// driver/calendar.php  –  My Schedule Calendar (Driver)
// Universiti Islam Selangor (UIS)
// ============================================================

$page_title   = 'My Calendar';
$current_page = 'calendar.php';

require_once '../config/database.php';
requireDriver();

$driver_id = (int)$_SESSION['driver_id'];

// ── Fetch all schedules for this driver ──────────────────────
$stmt = $conn->prepare(
    "SELECT s.schedule_id, s.trip_date, s.start_time, s.end_time,
            s.destination, s.purpose, s.status, s.trip_type,
            s.passenger_count, v.plate_number
     FROM schedules s
     LEFT JOIN vehicles v ON s.vehicle_id = v.vehicle_id
     WHERE s.driver_id = ?
     ORDER BY s.trip_date, s.start_time"
);
$stmt->bind_param('i', $driver_id);
$stmt->execute();
$schedules = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── Status → FullCalendar colour map ────────────────────────
$status_colors = [
    'pending'     => '#f59e0b',
    'approved'    => '#3b82f6',
    'in_progress' => '#06b6d4',
    'completed'   => '#10b981',
    'cancelled'   => '#9ca3af',
];

// ── Build FullCalendar events array ─────────────────────────
$fc_events = [];
foreach ($schedules as $row) {
    $status     = $row['status']    ?? 'pending';
    $trip_type  = $row['trip_type'] ?? '';
    $dest       = $row['destination'] ?? '';
    $color      = $status_colors[$status] ?? '#6b7280';

    // Crown prefix for top-management trips
    $title = ($trip_type === 'top_management' ? "★ " : '') . $dest;

    $fc_events[] = [
        'id'    => $row['schedule_id'],
        'title' => $title,
        'start' => $row['trip_date'] . 'T' . $row['start_time'],
        'end'   => $row['trip_date'] . 'T' . $row['end_time'],
        'color' => $color,
        'extendedProps' => [
            'schedule_id'     => (int)$row['schedule_id'],
            'destination'     => $dest,
            'purpose'         => $row['purpose']         ?? '',
            'status'          => $status,
            'trip_type'       => $trip_type,
            'passenger_count' => (int)$row['passenger_count'],
            'plate_number'    => $row['plate_number']    ?? '',
            'trip_date'       => $row['trip_date'],
            'start_time'      => substr($row['start_time'], 0, 5),
            'end_time'        => substr($row['end_time'],   0, 5),
        ],
    ];
}

$fc_events_json = json_encode($fc_events, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);

// ── Summary counts ───────────────────────────────────────────
$total_count   = count($schedules);
$pending_count = 0; $approved_count = 0; $inprog_count = 0;
$done_count    = 0; $cancel_count   = 0;
foreach ($schedules as $r) {
    switch ($r['status']) {
        case 'pending':     $pending_count++;  break;
        case 'approved':    $approved_count++; break;
        case 'in_progress': $inprog_count++;   break;
        case 'completed':   $done_count++;     break;
        case 'cancelled':   $cancel_count++;   break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> | UIS Driver Management</title>

    <!-- Bootstrap 5.3 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome 6.4 -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- Google Fonts – Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <!-- Custom CSS -->
    <link href="<?php echo SITE_URL; ?>/assets/css/style.css" rel="stylesheet">

    <style>
        /* ── FullCalendar overrides ── */
        #calendarWrapper {
            background: #fff;
            border-radius: 12px;
            padding: 1.25rem 1.5rem 1.5rem;
            box-shadow: 0 2px 12px rgba(0,53,128,.07);
        }
        .fc .fc-toolbar-title {
            font-size: 1.15rem;
            font-weight: 700;
            color: #1a2035;
        }
        .fc .fc-button-primary {
            background: #003580;
            border-color: #003580;
            font-size: 0.82rem;
            font-weight: 600;
            border-radius: 7px !important;
        }
        .fc .fc-button-primary:hover,
        .fc .fc-button-primary:focus {
            background: #002460;
            border-color: #002460;
        }
        .fc .fc-button-primary:not(:disabled).fc-button-active {
            background: #0056b3;
            border-color: #0056b3;
        }
        .fc-event {
            border: none !important;
            border-radius: 5px !important;
            font-size: 0.78rem;
            font-weight: 600;
            cursor: pointer;
            padding: 2px 5px !important;
        }
        .fc-daygrid-event-dot {
            display: none;
        }
        .fc-list-event-title a {
            color: inherit !important;
            text-decoration: none !important;
        }
        .fc-list-event:hover td {
            background: #eff6ff !important;
        }
        .fc-col-header-cell {
            background: #f0f5ff;
            font-size: 0.8rem;
            font-weight: 700;
            color: #003580;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .fc-daygrid-day-number {
            color: #374151;
            font-size: 0.82rem;
            font-weight: 500;
        }
        .fc-day-today {
            background: #eff6ff !important;
        }
        .fc-day-today .fc-daygrid-day-number {
            background: #003580;
            color: #fff;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.78rem;
        }

        /* ── Legend pills ── */
        .legend-dot {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            flex-shrink: 0;
        }

        /* ── Stat mini-cards ── */
        .cal-stat {
            border-radius: 10px;
            padding: .65rem 1rem;
            display: flex;
            align-items: center;
            gap: .6rem;
        }
        .cal-stat .cs-icon {
            width: 34px;
            height: 34px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .9rem;
        }
        .cal-stat .cs-val {
            font-size: 1.3rem;
            font-weight: 800;
            line-height: 1;
        }
        .cal-stat .cs-lbl {
            font-size: .7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .04em;
            opacity: .72;
        }

        /* ── Modal detail rows ── */
        .trip-detail-row {
            display: flex;
            gap: .5rem;
            padding: .55rem 0;
            border-bottom: 1px solid #f0f4f8;
            font-size: .875rem;
        }
        .trip-detail-row:last-child {
            border-bottom: none;
        }
        .trip-detail-row .td-label {
            min-width: 130px;
            color: #6b7280;
            font-weight: 500;
        }
        .trip-detail-row .td-value {
            color: #1a2035;
            font-weight: 600;
            flex: 1;
        }
    </style>
</head>
<body>

<?php require_once '../includes/sidebar.php'; ?>

<main class="main-content p-4">

    <!-- ── Breadcrumb / top bar (desktop) ──────────────────── -->
    <div class="d-none d-lg-flex align-items-center justify-content-between mb-4 pb-3"
         style="border-bottom: 2px solid #e5e9f0;">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1" style="font-size: .78rem;">
                    <li class="breadcrumb-item">
                        <a href="<?php echo SITE_URL; ?>/driver/dashboard.php"
                           class="text-decoration-none" style="color: var(--uis-primary);">
                            <i class="fas fa-home me-1"></i>Home
                        </a>
                    </li>
                    <li class="breadcrumb-item active">Calendar</li>
                </ol>
            </nav>
            <h1 class="page-title mb-0" style="font-size: 1.6rem;">
                <i class="fas fa-calendar-week me-2" style="color: var(--uis-primary);"></i>My Calendar
            </h1>
            <p class="page-subtitle mb-0">Visual overview of your assigned trips.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="<?php echo SITE_URL; ?>/driver/schedules.php"
               class="btn btn-outline-primary btn-sm">
                <i class="fas fa-list me-1"></i>List View
            </a>
            <a href="<?php echo SITE_URL; ?>/driver/dashboard.php"
               class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-gauge-high me-1"></i>Dashboard
            </a>
        </div>
    </div>

    <?php showFlash(); ?>

    <!-- ── Page header gradient ─────────────────────────────── -->
    <div style="background: linear-gradient(135deg, #003580 0%, #0056b3 100%);
                border-radius: 14px; color: #fff;
                padding: 1.4rem 2rem; margin-bottom: 1.5rem;">
        <div class="row align-items-center g-3">
            <div class="col-lg-6">
                <h4 class="fw-bold mb-1">
                    <i class="fas fa-calendar-week me-2 opacity-75"></i>Trip Calendar
                </h4>
                <p class="mb-0 opacity-75" style="font-size: .875rem;">
                    <i class="fas fa-user-tie me-1"></i>
                    <?php echo htmlspecialchars($_SESSION['full_name']); ?>
                    &nbsp;&bull;&nbsp;
                    <i class="fas fa-calendar me-1"></i>
                    <?php echo date('l, d F Y'); ?>
                </p>
            </div>
            <div class="col-lg-6">
                <div class="row g-2">
                    <!-- Total -->
                    <div class="col-6 col-sm-4">
                        <div class="cal-stat" style="background: rgba(255,255,255,.13);">
                            <div class="cs-icon" style="background: rgba(255,255,255,.18); color:#fff;">
                                <i class="fas fa-calendar-days"></i>
                            </div>
                            <div>
                                <div class="cs-val text-white"><?php echo $total_count; ?></div>
                                <div class="cs-lbl text-white">Total</div>
                            </div>
                        </div>
                    </div>
                    <!-- Approved -->
                    <div class="col-6 col-sm-4">
                        <div class="cal-stat" style="background: rgba(255,255,255,.13);">
                            <div class="cs-icon" style="background: rgba(59,130,246,.35); color:#fff;">
                                <i class="fas fa-thumbs-up"></i>
                            </div>
                            <div>
                                <div class="cs-val text-white"><?php echo $approved_count; ?></div>
                                <div class="cs-lbl text-white">Approved</div>
                            </div>
                        </div>
                    </div>
                    <!-- Completed -->
                    <div class="col-6 col-sm-4">
                        <div class="cal-stat" style="background: rgba(255,255,255,.13);">
                            <div class="cs-icon" style="background: rgba(16,185,129,.35); color:#fff;">
                                <i class="fas fa-check-circle"></i>
                            </div>
                            <div>
                                <div class="cs-val text-white"><?php echo $done_count; ?></div>
                                <div class="cs-lbl text-white">Completed</div>
                            </div>
                        </div>
                    </div>
                    <!-- Pending -->
                    <div class="col-6 col-sm-4">
                        <div class="cal-stat" style="background: rgba(255,255,255,.13);">
                            <div class="cs-icon" style="background: rgba(245,158,11,.35); color:#fff;">
                                <i class="fas fa-clock"></i>
                            </div>
                            <div>
                                <div class="cs-val text-white"><?php echo $pending_count; ?></div>
                                <div class="cs-lbl text-white">Pending</div>
                            </div>
                        </div>
                    </div>
                    <!-- In Progress -->
                    <div class="col-6 col-sm-4">
                        <div class="cal-stat" style="background: rgba(255,255,255,.13);">
                            <div class="cs-icon" style="background: rgba(6,182,212,.35); color:#fff;">
                                <i class="fas fa-car"></i>
                            </div>
                            <div>
                                <div class="cs-val text-white"><?php echo $inprog_count; ?></div>
                                <div class="cs-lbl text-white">In Progress</div>
                            </div>
                        </div>
                    </div>
                    <!-- Cancelled -->
                    <div class="col-6 col-sm-4">
                        <div class="cal-stat" style="background: rgba(255,255,255,.13);">
                            <div class="cs-icon" style="background: rgba(156,163,175,.35); color:#fff;">
                                <i class="fas fa-times-circle"></i>
                            </div>
                            <div>
                                <div class="cs-val text-white"><?php echo $cancel_count; ?></div>
                                <div class="cs-lbl text-white">Cancelled</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Calendar card ────────────────────────────────────── -->
    <div id="calendarWrapper">
        <div id="driverCalendar"></div>
    </div>

    <!-- ── Colour legend ────────────────────────────────────── -->
    <div class="card border-0 shadow-sm mt-3">
        <div class="card-body py-3 px-4">
            <div class="d-flex flex-wrap align-items-center gap-3">
                <span class="fw-semibold text-muted" style="font-size: .8rem;">
                    <i class="fas fa-circle-info me-1"></i>Legend:
                </span>
                <span class="d-flex align-items-center gap-2" style="font-size: .8rem;">
                    <span class="legend-dot" style="background: #f59e0b;"></span>
                    <span class="text-muted fw-500">Pending</span>
                </span>
                <span class="d-flex align-items-center gap-2" style="font-size: .8rem;">
                    <span class="legend-dot" style="background: #3b82f6;"></span>
                    <span class="text-muted fw-500">Approved</span>
                </span>
                <span class="d-flex align-items-center gap-2" style="font-size: .8rem;">
                    <span class="legend-dot" style="background: #06b6d4;"></span>
                    <span class="text-muted fw-500">In Progress</span>
                </span>
                <span class="d-flex align-items-center gap-2" style="font-size: .8rem;">
                    <span class="legend-dot" style="background: #10b981;"></span>
                    <span class="text-muted fw-500">Completed</span>
                </span>
                <span class="d-flex align-items-center gap-2" style="font-size: .8rem;">
                    <span class="legend-dot" style="background: #9ca3af;"></span>
                    <span class="text-muted fw-500">Cancelled</span>
                </span>
                <span class="ms-auto d-flex align-items-center gap-2" style="font-size: .78rem; color: #6b7280;">
                    <i class="fas fa-star" style="color: #b45309;"></i>
                    Top management trip
                </span>
            </div>
        </div>
    </div>

</main>

<!-- ================================================================
     TRIP DETAIL MODAL
     ================================================================ -->
<div class="modal fade" id="tripDetailModal" tabindex="-1" aria-labelledby="tripDetailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 14px; overflow: hidden;">

            <!-- Header -->
            <div class="modal-header text-white"
                 style="background: linear-gradient(135deg, #003580 0%, #0056b3 100%); border: none; padding: 1.1rem 1.5rem;">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-circle d-flex align-items-center justify-content-center"
                         style="width: 40px; height: 40px; background: rgba(255,255,255,.2); flex-shrink: 0;">
                        <i class="fas fa-calendar-check" style="font-size: 1rem;"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold mb-0" id="tripDetailModalLabel">Trip Details</h5>
                        <small class="opacity-75" id="modalTripId">Schedule #—</small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white ms-auto"
                        data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <!-- Body -->
            <div class="modal-body p-4">

                <!-- Status badge row -->
                <div class="d-flex align-items-center justify-content-between mb-4">
                    <div>
                        <span id="modalStatusBadge" class="badge fs-6 px-3 py-2"></span>
                        <span id="modalTripTypeBadge" class="badge bg-secondary ms-2 fs-6 px-3 py-2 d-none"></span>
                    </div>
                    <a id="modalListLink" href="#" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-list me-1"></i>List View
                    </a>
                </div>

                <!-- Detail rows -->
                <div class="trip-detail-row">
                    <span class="td-label"><i class="fas fa-map-marker-alt text-danger me-2"></i>Destination</span>
                    <span class="td-value" id="modalDestination">—</span>
                </div>
                <div class="trip-detail-row">
                    <span class="td-label"><i class="fas fa-align-left text-secondary me-2"></i>Purpose</span>
                    <span class="td-value" id="modalPurpose">—</span>
                </div>
                <div class="row g-0">
                    <div class="col-md-4">
                        <div class="trip-detail-row">
                            <span class="td-label"><i class="fas fa-calendar text-primary me-2"></i>Date</span>
                            <span class="td-value" id="modalDate">—</span>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="trip-detail-row">
                            <span class="td-label"><i class="fas fa-play text-success me-2"></i>Start</span>
                            <span class="td-value" id="modalStartTime">—</span>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="trip-detail-row">
                            <span class="td-label"><i class="fas fa-stop text-danger me-2"></i>End</span>
                            <span class="td-value" id="modalEndTime">—</span>
                        </div>
                    </div>
                </div>
                <div class="row g-0">
                    <div class="col-md-6">
                        <div class="trip-detail-row">
                            <span class="td-label"><i class="fas fa-car text-info me-2"></i>Vehicle</span>
                            <span class="td-value" id="modalVehicle">—</span>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="trip-detail-row">
                            <span class="td-label"><i class="fas fa-users text-warning me-2"></i>Passengers</span>
                            <span class="td-value" id="modalPassengers">—</span>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Footer -->
            <div class="modal-footer border-top" style="background: #f8fafc; padding: .9rem 1.5rem;">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>Close
                </button>
                <a href="<?php echo SITE_URL; ?>/driver/schedules.php"
                   class="btn btn-primary btn-sm">
                    <i class="fas fa-calendar-days me-1"></i>View All Schedules
                </a>
            </div>

        </div>
    </div>
</div>

<!-- ================================================================
     SCRIPTS
     ================================================================ -->
<!-- jQuery (required by DataTables in footer if included on other pages; safe to include here) -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<!-- Bootstrap 5.3 JS Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- FullCalendar v6 -->
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js"></script>
<!-- Custom JS -->
<script src="<?php echo SITE_URL; ?>/assets/js/main.js"></script>

<script>
(function () {
    'use strict';

    /* ── FullCalendar events from PHP ── */
    var calendarEvents = <?php echo $fc_events_json; ?>;

    /* ── Status display helpers ── */
    var statusLabels = {
        pending:     'Pending',
        approved:    'Approved',
        in_progress: 'In Progress',
        completed:   'Completed',
        cancelled:   'Cancelled'
    };
    var statusClasses = {
        pending:     'bg-warning text-dark',
        approved:    'bg-primary',
        in_progress: 'bg-info text-dark',
        completed:   'bg-success',
        cancelled:   'bg-secondary'
    };

    /* ── Format date string (YYYY-MM-DD) → '01 Jan 2025' ── */
    function fmtDate(str) {
        if (!str) return '—';
        var d = new Date(str + 'T00:00:00');
        return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
    }

    /* ── Open trip-detail modal ── */
    function openTripModal(props) {
        var status    = props.status     || 'pending';
        var tripType  = props.trip_type  || '';
        var dest      = props.destination || '—';
        var purpose   = props.purpose     || '—';
        var dateStr   = props.trip_date   || '';
        var startT    = props.start_time  || '—';
        var endT      = props.end_time    || '—';
        var plate     = props.plate_number || '';
        var pax       = props.passenger_count;
        var sid       = props.schedule_id;

        /* Header */
        document.getElementById('modalTripId').textContent = 'Schedule #' + (sid || '—');

        /* Status badge */
        var sbEl = document.getElementById('modalStatusBadge');
        sbEl.className = 'badge fs-6 px-3 py-2 ' + (statusClasses[status] || 'bg-secondary');
        sbEl.textContent = statusLabels[status] || status;

        /* Trip type badge */
        var ttEl = document.getElementById('modalTripTypeBadge');
        if (tripType === 'top_management') {
            ttEl.classList.remove('d-none');
            ttEl.innerHTML = '<i class="fas fa-star me-1"></i>Top Management';
            ttEl.style.background = '#b45309';
        } else if (tripType) {
            ttEl.classList.remove('d-none');
            ttEl.className = 'badge ms-2 fs-6 px-3 py-2 bg-secondary';
            ttEl.textContent = tripType.replace(/_/g, ' ').replace(/\b\w/g, function(c){ return c.toUpperCase(); });
        } else {
            ttEl.classList.add('d-none');
        }

        /* Fields */
        document.getElementById('modalDestination').textContent = dest;
        document.getElementById('modalPurpose').textContent     = purpose || '—';
        document.getElementById('modalDate').textContent        = fmtDate(dateStr);
        document.getElementById('modalStartTime').textContent   = startT;
        document.getElementById('modalEndTime').textContent     = endT;
        document.getElementById('modalVehicle').textContent     = plate || 'Not assigned';
        document.getElementById('modalPassengers').textContent  = (pax !== undefined && pax !== null) ? pax + ' pax' : '—';

        new bootstrap.Modal(document.getElementById('tripDetailModal')).show();
    }

    /* ── Initialise FullCalendar ── */
    document.addEventListener('DOMContentLoaded', function () {
        var calEl = document.getElementById('driverCalendar');
        if (!calEl) return;

        var calendar = new FullCalendar.Calendar(calEl, {
            initialView: 'dayGridMonth',
            height: 'auto',
            firstDay: 1,        // Monday start
            nowIndicator: true,
            navLinks: true,
            dayMaxEvents: 3,    // "+N more" on busy days
            headerToolbar: {
                left:   'prev,next today',
                center: 'title',
                right:  'dayGridMonth,timeGridWeek,listMonth'
            },
            buttonText: {
                today:      'Today',
                month:      'Month',
                week:       'Week',
                listMonth:  'List'
            },
            events: calendarEvents,
            eventDidMount: function (info) {
                /* Tooltip via Bootstrap (title attribute) */
                var p = info.event.extendedProps;
                var tip = (p.destination || '') + '\n'
                        + (p.start_time || '') + ' – ' + (p.end_time || '') + '\n'
                        + 'Status: ' + (statusLabels[p.status] || p.status);
                info.el.setAttribute('title', tip);
                info.el.setAttribute('data-bs-toggle', 'tooltip');
                info.el.setAttribute('data-bs-placement', 'top');
                new bootstrap.Tooltip(info.el, { trigger: 'hover' });
            },
            eventClick: function (info) {
                info.jsEvent.preventDefault();
                openTripModal(info.event.extendedProps);
            },
            /* Style list view rows by status colour */
            eventContent: function (arg) {
                if (arg.view.type === 'listMonth') {
                    var props = arg.event.extendedProps;
                    var color = arg.event.backgroundColor || '#6b7280';
                    var label = statusLabels[props.status] || props.status;
                    var dot = '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;'
                            + 'background:' + color + ';margin-right:6px;flex-shrink:0;"></span>';
                    var isTop = (props.trip_type === 'top_management');
                    var star  = isTop ? '<i class="fas fa-star me-1" style="color:#b45309;font-size:.7rem;"></i>' : '';
                    return {
                        html: '<div style="display:flex;align-items:center;gap:4px;font-size:.82rem;font-weight:600;">'
                            + dot + star
                            + '<span>' + (props.destination || '') + '</span>'
                            + '<span class="ms-auto badge" style="background:' + color + ';font-size:.68rem;font-weight:600;">'
                            + label + '</span>'
                            + '</div>'
                    };
                }
            },
            /* No events placeholder */
            noEventsContent: '<div style="text-align:center;padding:2rem;color:#9ca3af;">'
                           + '<i class="fas fa-calendar-times" style="font-size:2rem;display:block;margin-bottom:.5rem;"></i>'
                           + 'No trips scheduled</div>'
        });

        calendar.render();
    });
})();
</script>

</body>
</html>
