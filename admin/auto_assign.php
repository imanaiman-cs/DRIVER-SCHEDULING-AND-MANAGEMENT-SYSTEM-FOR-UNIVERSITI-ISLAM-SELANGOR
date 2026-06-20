<?php
$page_title = 'Auto Assign Drivers';
$current_page = 'auto_assign';
require_once '../config/database.php';
requireAdmin();

$assignments   = [];
$errors        = [];
$success_count = 0;
$preview_mode  = true;

// ---------- Run Assignment Algorithm ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'preview' || $_POST['action'] === 'confirm') {
        $confirm = ($_POST['action'] === 'confirm');

        // Fetch all unassigned pending schedules ordered by date/time
        $unassigned_sql = "SELECT s.*, v.plate_number, v.vehicle_type, v.capacity
                           FROM schedules s
                           LEFT JOIN vehicles v ON s.vehicle_id = v.vehicle_id
                           WHERE s.driver_id IS NULL AND s.status = 'pending'
                           ORDER BY s.trip_date ASC, s.start_time ASC";
        $unassigned_result = $conn->query($unassigned_sql);

        if (!$unassigned_result) {
            $errors[] = 'Database query failed: ' . $conn->error;
        } else {
            $schedules_to_assign = [];
            while ($row = $unassigned_result->fetch_assoc()) {
                $schedules_to_assign[] = $row;
            }

            // Track per-day workload in memory to handle same-day sequential assignments
            $daily_hours = [];
            $daily_trips = [];
            $daily_assigned_slots = []; // [driver_id][date][] = [start, end]

            foreach ($schedules_to_assign as $schedule) {
                $trip_date  = $schedule['trip_date'];
                $start_time = $schedule['start_time'];
                $end_time   = $schedule['end_time'];
                $trip_hours = (strtotime($end_time) - strtotime($start_time)) / 3600;

                /* ------ Find available active drivers for this slot ------ */
                // Exclude drivers already assigned to overlapping schedules in DB
                $avail_sql = "
                    SELECT d.*
                    FROM drivers d
                    WHERE d.status = 'active'
                      AND d.driver_id NOT IN (
                            SELECT driver_id FROM schedules
                            WHERE trip_date = ?
                              AND status NOT IN ('cancelled')
                              AND driver_id IS NOT NULL
                              AND (
                                (start_time <  ? AND end_time > ?)
                             OR (start_time <  ? AND end_time > ?)
                             OR (start_time >= ? AND end_time <= ?)
                              )
                      )";
                $stmt = $conn->prepare($avail_sql);
                $stmt->bind_param(
                    "sssssss",
                    $trip_date,
                    $end_time,   $start_time,
                    $end_time,   $start_time,
                    $start_time, $end_time
                );
                $stmt->execute();
                $avail_drivers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

                // Filter by in-memory assignments (same-day, already assigned in this run)
                $eligible = [];
                foreach ($avail_drivers as $drv) {
                    $did = $drv['driver_id'];

                    // Check in-memory slot conflicts
                    $conflict = false;
                    if (isset($daily_assigned_slots[$did][$trip_date])) {
                        foreach ($daily_assigned_slots[$did][$trip_date] as $slot) {
                            if ($start_time < $slot['end'] && $end_time > $slot['start']) {
                                $conflict = true;
                                break;
                            }
                        }
                    }
                    if ($conflict) continue;

                    // Check 8-hour daily cap
                    $existing_hours = $daily_hours[$did][$trip_date] ?? 0;
                    // Pull from DB if not yet tracked
                    if (!isset($daily_hours[$did][$trip_date])) {
                        $wl = getDriverWorkload($conn, $did, $trip_date);
                        $existing_hours = (float)$wl['total_hours'];
                        $daily_hours[$did][$trip_date] = $existing_hours;
                        $daily_trips[$did][$trip_date] = (int)$wl['count'];
                    }
                    if ($existing_hours + $trip_hours > 8) continue;

                    // Calculate priority score
                    $priority = calculatePriorityScore(
                        $drv['experience_years'],
                        $drv['attendance_rate'],
                        $drv['performance_score'],
                        $drv['certification_score']
                    );

                    $eligible[] = [
                        'driver'        => $drv,
                        'priority'      => $priority,
                        'daily_trips'   => $daily_trips[$did][$trip_date] ?? 0,
                        'daily_hours'   => $existing_hours,
                    ];
                }

                if (empty($eligible)) {
                    $errors[] = "No available driver for schedule #{$schedule['schedule_id']} — {$schedule['destination']} on {$schedule['trip_date']} {$schedule['start_time']}–{$schedule['end_time']}.";
                    $assignments[] = [
                        'schedule'  => $schedule,
                        'driver'    => null,
                        'priority'  => null,
                        'status'    => 'failed',
                        'reason'    => 'No eligible driver (all busy or at daily limit)',
                    ];
                    continue;
                }

                // Sort: fewer daily trips first → higher priority score second
                usort($eligible, function ($a, $b) {
                    if ($a['daily_trips'] !== $b['daily_trips']) {
                        return $a['daily_trips'] - $b['daily_trips'];
                    }
                    return $b['priority'] <=> $a['priority'];
                });

                $chosen = $eligible[0];
                $chosen_driver = $chosen['driver'];
                $did = $chosen_driver['driver_id'];

                // Record assignment
                $daily_hours[$did][$trip_date] = ($daily_hours[$did][$trip_date] ?? 0) + $trip_hours;
                $daily_trips[$did][$trip_date] = ($daily_trips[$did][$trip_date] ?? 0) + 1;
                $daily_assigned_slots[$did][$trip_date][] = ['start' => $start_time, 'end' => $end_time];

                if ($confirm) {
                    $upd = $conn->prepare("UPDATE schedules SET driver_id = ?, priority_score = ?, status = 'approved', updated_at = NOW() WHERE schedule_id = ?");
                    $upd->bind_param("idi", $did, $chosen['priority'], $schedule['schedule_id']);
                    $upd->execute();
                    $success_count++;
                }

                $assignments[] = [
                    'schedule' => $schedule,
                    'driver'   => $chosen_driver,
                    'priority' => $chosen['priority'],
                    'status'   => 'assigned',
                    'reason'   => '',
                ];
            }

            if ($confirm && $success_count > 0) {
                setFlash('success', "$success_count schedule(s) successfully assigned.");
            }
            $preview_mode = !$confirm;
        }
    }
}

// Fetch counts for preview panel
$unassigned_count_row = $conn->query("SELECT COUNT(*) as cnt FROM schedules WHERE driver_id IS NULL AND status='pending'")->fetch_assoc();
$unassigned_count = (int)$unassigned_count_row['cnt'];

$active_drivers_row = $conn->query("SELECT COUNT(*) as cnt FROM drivers WHERE status='active'")->fetch_assoc();
$active_drivers_count = (int)$active_drivers_row['cnt'];

// Top 5 drivers by priority score (for display)
$drivers_result = $conn->query("SELECT *, (SELECT COUNT(*) FROM schedules WHERE driver_id=d.driver_id AND status NOT IN ('cancelled') AND trip_date=CURDATE()) as today_trips FROM drivers d WHERE status='active' ORDER BY name");
$all_drivers = [];
while ($row = $drivers_result->fetch_assoc()) {
    $row['priority_score'] = calculatePriorityScore(
        $row['experience_years'], $row['attendance_rate'],
        $row['performance_score'], $row['certification_score']
    );
    $all_drivers[] = $row;
}
usort($all_drivers, fn($a,$b) => $b['priority_score'] <=> $a['priority_score']);

// Current unassigned schedules for preview table
$preview_result = $conn->query("SELECT s.*, v.plate_number, v.vehicle_type FROM schedules s LEFT JOIN vehicles v ON s.vehicle_id=v.vehicle_id WHERE s.driver_id IS NULL AND s.status='pending' ORDER BY s.trip_date, s.start_time LIMIT 20");
$preview_schedules = [];
while ($row = $preview_result->fetch_assoc()) {
    $preview_schedules[] = $row;
}

require_once '../includes/header.php';
require_once '../includes/sidebar.php';
?>

<div class="main-content">
  <!-- Top Bar -->
  <div class="topbar d-flex align-items-center justify-content-between px-4 py-2 bg-white shadow-sm">
    <div class="d-flex align-items-center gap-3">
      <button class="btn btn-sm btn-outline-secondary d-lg-none" onclick="toggleSidebar()"><i class="fas fa-bars"></i></button>
      <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
          <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
          <li class="breadcrumb-item active">Auto Assign Drivers</li>
        </ol>
      </nav>
    </div>
    <div class="d-flex align-items-center gap-3">
      <small class="text-muted" id="liveClock"></small>
      <a href="../logout.php" class="btn btn-sm btn-outline-danger"><i class="fas fa-sign-out-alt me-1"></i>Logout</a>
    </div>
  </div>

  <div class="container-fluid p-4">
    <?php showFlash(); ?>

    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
      <div>
        <h4 class="fw-bold text-primary mb-1"><i class="fas fa-robot me-2"></i>Auto Assign Drivers</h4>
        <p class="text-muted mb-0">Automatically assign drivers to unscheduled trips using priority scoring and workload balancing.</p>
      </div>
      <a href="schedules.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Back to Schedules</a>
    </div>

    <!-- Summary Cards -->
    <div class="row g-3 mb-4">
      <div class="col-md-3">
        <div class="card stat-card border-0 shadow-sm h-100">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-center">
              <div>
                <p class="text-muted mb-1 small">Unassigned Schedules</p>
                <h3 class="fw-bold text-warning mb-0"><?= $unassigned_count ?></h3>
              </div>
              <div class="stat-icon bg-warning bg-opacity-10 text-warning">
                <i class="fas fa-calendar-times"></i>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="card stat-card border-0 shadow-sm h-100">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-center">
              <div>
                <p class="text-muted mb-1 small">Active Drivers</p>
                <h3 class="fw-bold text-success mb-0"><?= $active_drivers_count ?></h3>
              </div>
              <div class="stat-icon bg-success bg-opacity-10 text-success">
                <i class="fas fa-user-check"></i>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="card stat-card border-0 shadow-sm h-100">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-center">
              <div>
                <p class="text-muted mb-1 small">Assignments Made</p>
                <h3 class="fw-bold text-primary mb-0"><?= count(array_filter($assignments, fn($a) => $a['status']==='assigned')) ?></h3>
              </div>
              <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                <i class="fas fa-check-double"></i>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="card stat-card border-0 shadow-sm h-100">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-center">
              <div>
                <p class="text-muted mb-1 small">Failed Assignments</p>
                <h3 class="fw-bold text-danger mb-0"><?= count($errors) ?></h3>
              </div>
              <div class="stat-icon bg-danger bg-opacity-10 text-danger">
                <i class="fas fa-exclamation-triangle"></i>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="row g-4">
      <!-- LEFT: Algorithm Controls & Explanation -->
      <div class="col-lg-4">

        <!-- Algorithm Info -->
        <div class="card border-0 shadow-sm mb-4">
          <div class="card-header bg-primary text-white">
            <h6 class="mb-0"><i class="fas fa-info-circle me-2"></i>Priority Score Formula</h6>
          </div>
          <div class="card-body">
            <div class="bg-light rounded p-3 mb-3">
              <code class="text-dark small">
                Priority Score =<br>
                &nbsp;(Experience &times; 30%) +<br>
                &nbsp;(Attendance &times; 20%) +<br>
                &nbsp;(Performance &times; 30%) +<br>
                &nbsp;(Certification &times; 20%)
              </code>
            </div>
            <ul class="list-unstyled small text-muted mb-0">
              <li><i class="fas fa-dot-circle text-primary me-2"></i>Experience normalised to 0–10 (max 20 yrs)</li>
              <li><i class="fas fa-dot-circle text-primary me-2"></i>Attendance rate (%) normalised to 0–10</li>
              <li><i class="fas fa-dot-circle text-primary me-2"></i>Performance &amp; Certification on 0–10 scale</li>
            </ul>
          </div>
        </div>

        <!-- Optimisation Rules -->
        <div class="card border-0 shadow-sm mb-4">
          <div class="card-header bg-info text-white">
            <h6 class="mb-0"><i class="fas fa-cogs me-2"></i>Optimisation Rules</h6>
          </div>
          <div class="card-body">
            <ul class="list-unstyled small mb-0">
              <li class="mb-2"><span class="badge bg-warning text-dark me-2">1</span>Max 8 working hours per driver per day</li>
              <li class="mb-2"><span class="badge bg-warning text-dark me-2">2</span>No overlapping schedule conflicts</li>
              <li class="mb-2"><span class="badge bg-warning text-dark me-2">3</span>Lower-workload drivers prioritised first</li>
              <li class="mb-2"><span class="badge bg-warning text-dark me-2">4</span>Highest priority score as tiebreaker</li>
              <li><span class="badge bg-warning text-dark me-2">5</span>Only active drivers are considered</li>
            </ul>
          </div>
        </div>

        <!-- Action Buttons -->
        <?php if ($unassigned_count > 0): ?>
        <div class="card border-0 shadow-sm mb-4">
          <div class="card-body">
            <h6 class="fw-semibold mb-3">Run Assignment</h6>

            <!-- Step 1: Preview -->
            <form method="POST" id="previewForm">
              <input type="hidden" name="action" value="preview">
              <button type="submit" class="btn btn-warning w-100 mb-2">
                <i class="fas fa-eye me-2"></i>Preview Assignments
              </button>
            </form>

            <?php if (!empty($assignments) && $preview_mode): ?>
            <!-- Step 2: Confirm -->
            <form method="POST" id="confirmForm">
              <input type="hidden" name="action" value="confirm">
              <?php $confirm_count = count(array_filter($assignments, fn($a)=>$a['status']==='assigned')); ?>
              <button type="submit" class="btn btn-success w-100" onclick="return confirm('Confirm all <?= $confirm_count ?> assignment(s)?')">
                <i class="fas fa-check-circle me-2"></i>Confirm & Apply Assignments
              </button>
            </form>
            <?php endif; ?>
          </div>
        </div>
        <?php else: ?>
        <div class="alert alert-success">
          <i class="fas fa-check-circle me-2"></i>All pending schedules already have drivers assigned!
        </div>
        <?php endif; ?>

        <!-- Top Drivers Sidebar -->
        <div class="card border-0 shadow-sm">
          <div class="card-header bg-white border-bottom">
            <h6 class="mb-0 fw-semibold">Top Drivers by Priority</h6>
          </div>
          <div class="card-body p-0">
            <ul class="list-group list-group-flush">
              <?php foreach (array_slice($all_drivers, 0, 5) as $i => $drv): ?>
              <li class="list-group-item d-flex align-items-center gap-3 py-3">
                <span class="fw-bold text-muted" style="width:20px"><?= $i+1 ?></span>
                <div class="flex-grow-1">
                  <div class="fw-semibold small"><?= htmlspecialchars($drv['name']) ?></div>
                  <div class="text-muted" style="font-size:0.75rem"><?= $drv['experience_years'] ?> yrs exp · Today: <?= $drv['today_trips'] ?> trip(s)</div>
                  <div class="progress mt-1" style="height:4px">
                    <div class="progress-bar bg-primary" style="width:<?= ($drv['priority_score']/10)*100 ?>%"></div>
                  </div>
                </div>
                <?php
                $sc = $drv['priority_score'];
                $bc = $sc >= 7 ? 'success' : ($sc >= 5 ? 'warning' : 'danger');
                ?>
                <span class="badge bg-<?= $bc ?> rounded-pill"><?= $sc ?></span>
              </li>
              <?php endforeach; ?>
            </ul>
          </div>
        </div>
      </div>

      <!-- RIGHT: Schedules & Results -->
      <div class="col-lg-8">

        <!-- Error alerts -->
        <?php foreach ($errors as $err): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
          <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($err) ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endforeach; ?>

        <?php if (!empty($assignments)): ?>
        <!-- Assignment Results -->
        <div class="card border-0 shadow-sm mb-4">
          <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center">
            <h6 class="mb-0 fw-semibold">
              <?= $preview_mode ? 'Preview Results' : 'Assignment Results' ?>
              <span class="badge bg-<?= $preview_mode ? 'warning text-dark' : 'success' ?> ms-2">
                <?= $preview_mode ? 'Preview' : 'Confirmed' ?>
              </span>
            </h6>
            <span class="small text-muted"><?= count($assignments) ?> schedule(s) processed</span>
          </div>
          <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table table-hover mb-0">
                <thead class="table-primary">
                  <tr>
                    <th>Schedule ID</th>
                    <th>Destination</th>
                    <th>Date / Time</th>
                    <th>Assigned Driver</th>
                    <th>Priority Score</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($assignments as $a): ?>
                  <tr>
                    <td><span class="badge bg-secondary">#<?= $a['schedule']['schedule_id'] ?></span></td>
                    <td><?= htmlspecialchars($a['schedule']['destination']) ?></td>
                    <td>
                      <small><?= htmlspecialchars($a['schedule']['trip_date']) ?></small><br>
                      <small class="text-muted"><?= substr($a['schedule']['start_time'],0,5) ?> – <?= substr($a['schedule']['end_time'],0,5) ?></small>
                    </td>
                    <td>
                      <?php if ($a['driver']): ?>
                        <strong><?= htmlspecialchars($a['driver']['name']) ?></strong>
                      <?php else: ?>
                        <span class="text-danger"><i class="fas fa-times-circle me-1"></i>Unassignable</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if ($a['priority'] !== null): ?>
                        <?php $bc = $a['priority'] >= 7 ? 'success' : ($a['priority'] >= 5 ? 'warning' : 'danger'); ?>
                        <span class="badge bg-<?= $bc ?>"><?= $a['priority'] ?></span>
                      <?php else: ?>
                        <span class="text-muted">—</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if ($a['status'] === 'assigned'): ?>
                        <span class="badge bg-<?= $preview_mode ? 'warning text-dark' : 'success' ?>">
                          <?= $preview_mode ? 'Will Assign' : 'Assigned' ?>
                        </span>
                      <?php else: ?>
                        <span class="badge bg-danger" title="<?= htmlspecialchars($a['reason']) ?>">Failed</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
        <?php endif; ?>

        <!-- Unassigned Schedules Preview -->
        <?php if (!empty($preview_schedules) && empty($assignments)): ?>
        <div class="card border-0 shadow-sm">
          <div class="card-header bg-white border-bottom">
            <h6 class="mb-0 fw-semibold">Pending Unassigned Schedules</h6>
          </div>
          <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table table-hover mb-0">
                <thead class="table-light">
                  <tr>
                    <th>#</th>
                    <th>Destination</th>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Vehicle</th>
                    <th>Passengers</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($preview_schedules as $ps): ?>
                  <tr>
                    <td><?= $ps['schedule_id'] ?></td>
                    <td><?= htmlspecialchars($ps['destination']) ?></td>
                    <td><?= htmlspecialchars($ps['trip_date']) ?></td>
                    <td><?= substr($ps['start_time'],0,5) ?> – <?= substr($ps['end_time'],0,5) ?></td>
                    <td>
                      <?php if ($ps['plate_number']): ?>
                        <span class="badge bg-secondary"><?= htmlspecialchars($ps['plate_number']) ?></span>
                        <small class="text-muted"><?= $ps['vehicle_type'] ?></small>
                      <?php else: ?>
                        <span class="text-muted">Not set</span>
                      <?php endif; ?>
                    </td>
                    <td><?= (int)$ps['passenger_count'] ?></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?php if ($unassigned_count > 20): ?>
            <div class="card-footer text-muted small">Showing 20 of <?= $unassigned_count ?> unassigned schedules.</div>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>

        <?php if ($unassigned_count === 0 && empty($assignments)): ?>
        <div class="card border-0 shadow-sm">
          <div class="card-body text-center py-5">
            <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
            <h5>All Schedules Assigned</h5>
            <p class="text-muted">There are no pending schedules without an assigned driver.</p>
            <a href="schedules.php" class="btn btn-primary">View All Schedules</a>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php
$extra_js = '<script>
(function(){
  function tick(){
    const now = new Date();
    const el = document.getElementById("liveClock");
    if(el) el.textContent = now.toLocaleString("en-MY",{weekday:"short",year:"numeric",month:"short",day:"numeric",hour:"2-digit",minute:"2-digit",second:"2-digit"});
  }
  tick(); setInterval(tick,1000);
})();
</script>';
require_once '../includes/footer.php';
?>
