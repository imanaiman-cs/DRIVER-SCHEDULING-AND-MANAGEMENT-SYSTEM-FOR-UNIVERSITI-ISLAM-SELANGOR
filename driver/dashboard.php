<?php
$page_title = 'Driver Dashboard';
$current_page = 'driver_dashboard';
require_once '../config/database.php';
requireDriver();

$driver_id = (int)$_SESSION['driver_id'];

// Fetch driver info
$drv_stmt = $conn->prepare("SELECT * FROM drivers WHERE driver_id = ?");
$drv_stmt->bind_param("i", $driver_id);
$drv_stmt->execute();
$driver = $drv_stmt->get_result()->fetch_assoc();

if (!$driver) {
    session_destroy();
    header('Location: ../login.php?error=driver_not_found');
    exit();
}

$monthly_counts = getMonthlyTaskCounts($conn);
$my_counts      = $monthly_counts[$driver_id] ?? ['tasks' => 0, 'weekend' => 0];
$month_tasks    = (int)$my_counts['tasks'];
$month_weekend  = (int)$my_counts['weekend'];

$priority_score = calculateAllocationScore(
    $month_tasks,
    $month_weekend,
    (float)$driver['experience_years']
);

$today = date('Y-m-d');
$week_start = date('Y-m-d', strtotime('monday this week'));
$week_end   = date('Y-m-d', strtotime('sunday this week'));
$month_start = date('Y-m-01');
$month_end   = date('Y-m-t');

// Stats
$stat_today = $conn->prepare("SELECT COUNT(*) as cnt FROM schedules WHERE driver_id=? AND trip_date=? AND status NOT IN ('cancelled')");
$stat_today->bind_param("is", $driver_id, $today);
$stat_today->execute();
$today_trips = (int)$stat_today->get_result()->fetch_assoc()['cnt'];

$stat_week = $conn->prepare("SELECT COUNT(*) as cnt FROM schedules WHERE driver_id=? AND trip_date BETWEEN ? AND ? AND status NOT IN ('cancelled')");
$stat_week->bind_param("iss", $driver_id, $week_start, $week_end);
$stat_week->execute();
$week_trips = (int)$stat_week->get_result()->fetch_assoc()['cnt'];

$stat_completed = $conn->prepare("SELECT COUNT(*) as cnt FROM schedules WHERE driver_id=? AND status='completed'");
$stat_completed->bind_param("i", $driver_id);
$stat_completed->execute();
$completed_trips = (int)$stat_completed->get_result()->fetch_assoc()['cnt'];

$stat_pending = $conn->prepare("SELECT COUNT(*) as cnt FROM schedules WHERE driver_id=? AND status IN ('pending','approved')");
$stat_pending->bind_param("i", $driver_id);
$stat_pending->execute();
$pending_trips = (int)$stat_pending->get_result()->fetch_assoc()['cnt'];

// Today's schedules
$today_stmt = $conn->prepare("
    SELECT s.*, v.plate_number, v.vehicle_type, v.brand, v.model
    FROM schedules s
    LEFT JOIN vehicles v ON s.vehicle_id = v.vehicle_id
    WHERE s.driver_id = ? AND s.trip_date = ? AND s.status NOT IN ('cancelled')
    ORDER BY s.start_time ASC
");
$today_stmt->bind_param("is", $driver_id, $today);
$today_stmt->execute();
$today_schedules = $today_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Upcoming schedules (next 7 days, excluding today)
$upcoming_stmt = $conn->prepare("
    SELECT s.*, v.plate_number, v.vehicle_type
    FROM schedules s
    LEFT JOIN vehicles v ON s.vehicle_id = v.vehicle_id
    WHERE s.driver_id = ? AND s.trip_date > ? AND s.trip_date <= DATE_ADD(?, INTERVAL 7 DAY)
      AND s.status NOT IN ('cancelled')
    ORDER BY s.trip_date ASC, s.start_time ASC
    LIMIT 10
");
$upcoming_stmt->bind_param("iss", $driver_id, $today, $today);
$upcoming_stmt->execute();
$upcoming_schedules = $upcoming_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Recent completed
$recent_stmt = $conn->prepare("
    SELECT s.*, v.plate_number, v.vehicle_type
    FROM schedules s
    LEFT JOIN vehicles v ON s.vehicle_id = v.vehicle_id
    WHERE s.driver_id = ? AND s.status = 'completed'
    ORDER BY s.trip_date DESC, s.end_time DESC
    LIMIT 5
");
$recent_stmt->bind_param("i", $driver_id);
$recent_stmt->execute();
$recent_completed = $recent_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Team jobs: who else is on the same job as me
$teams = getJobTeams($conn, array_merge(
    array_column($today_schedules, 'schedule_id'),
    array_column($upcoming_schedules, 'schedule_id')
));
$teamLine = static function (array $members): string {
    return implode(', ', array_map(static function ($m) {
        return htmlspecialchars($m['driver_name'] ?? 'Unassigned') . ($m['plate_number'] ? ' (' . htmlspecialchars($m['plate_number']) . ')' : '');
    }, $members));
};

require_once '../includes/header.php';
require_once '../includes/sidebar.php';
?>

<main class="main-content">
    <?php showFlash(); ?>

    <!-- Welcome Banner -->
    <?php $sc = $priority_score; $score_tone = $sc>=7 ? 'good' : ($sc>=5 ? 'fair' : 'low'); ?>
    <section class="driver-hero mb-4" aria-label="Welcome">
      <div class="driver-hero-text">
        <h1 class="driver-hero-title">Welcome back, <?= htmlspecialchars($driver['name']) ?></h1>
        <p class="driver-hero-meta">
          <span><i class="fas fa-id-badge me-2"></i><?= htmlspecialchars($driver['employee_id'] ?? 'N/A') ?></span>
          <span><i class="fas fa-calendar-day me-2"></i><?= date('l, d F Y') ?></span>
        </p>
      </div>
      <div class="driver-hero-score" title="Higher score = higher chance of being assigned the next job">
        <span class="driver-hero-score-label">Allocation Score</span>
        <span class="driver-hero-score-value score-<?= $score_tone ?>"><?= $priority_score ?><small>/10</small></span>
      </div>
    </section>

    <!-- Stat Cards -->
    <div class="row g-3 mb-4">
      <div class="col-6 col-md-3">
        <div class="stat-card stat-blue">
          <div class="stat-card-icon"><i class="fas fa-route" style="font-size:1.4rem;"></i></div>
          <div class="stat-card-body">
            <div class="stat-card-value"><?= $today_trips ?></div>
            <div class="stat-card-label">Today's Trips</div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="stat-card stat-sky">
          <div class="stat-card-icon"><i class="fas fa-calendar-week" style="font-size:1.4rem;"></i></div>
          <div class="stat-card-body">
            <div class="stat-card-value"><?= $week_trips ?></div>
            <div class="stat-card-label">This Week</div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="stat-card stat-green">
          <div class="stat-card-icon"><i class="fas fa-check-circle" style="font-size:1.4rem;"></i></div>
          <div class="stat-card-body">
            <div class="stat-card-value"><?= $completed_trips ?></div>
            <div class="stat-card-label">Completed</div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="stat-card stat-amber">
          <div class="stat-card-icon"><i class="fas fa-clock" style="font-size:1.4rem;"></i></div>
          <div class="stat-card-body">
            <div class="stat-card-value"><?= $pending_trips ?></div>
            <div class="stat-card-label">Pending</div>
          </div>
        </div>
      </div>
    </div>

    <div class="row g-4">
      <!-- Today's Schedule -->
      <div class="col-lg-7">
        <div class="card border-0 shadow-sm h-100">
          <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center">
            <h6 class="mb-0 fw-semibold"><i class="fas fa-calendar-day text-primary me-2"></i>Today's Schedule</h6>
            <span class="badge bg-primary rounded-pill"><?= count($today_schedules) ?></span>
          </div>
          <div class="card-body">
            <?php if (empty($today_schedules)): ?>
            <div class="text-center py-4">
              <i class="fas fa-coffee fa-3x text-muted mb-3"></i>
              <p class="text-muted">No trips scheduled for today.</p>
            </div>
            <?php else: ?>
            <?php foreach ($today_schedules as $ts): ?>
            <div class="border rounded-3 p-3 mb-3 position-relative <?= $ts['status']==='in_progress'?'border-info border-2':'' ?>">
              <div class="d-flex gap-3 align-items-start">
                <div class="text-center bg-primary text-white rounded-3 px-3 py-2" style="min-width:80px">
                  <div class="fw-bold"><?= substr($ts['start_time'],0,5) ?></div>
                  <div class="small opacity-75">to</div>
                  <div class="fw-bold"><?= substr($ts['end_time'],0,5) ?></div>
                </div>
                <div class="flex-grow-1">
                  <h6 class="fw-semibold mb-1"><?= htmlspecialchars($ts['destination']) ?></h6>
                  <p class="text-muted small mb-2"><?= htmlspecialchars($ts['purpose'] ?? '') ?></p>
                  <div class="d-flex flex-wrap gap-2 align-items-center">
                    <?php if ($ts['plate_number']): ?>
                    <span class="badge bg-secondary"><i class="fas fa-car me-1"></i><?= htmlspecialchars($ts['plate_number']) ?> (<?= $ts['vehicle_type'] ?>)</span>
                    <?php endif; ?>
                    <span class="badge bg-light text-dark"><i class="fas fa-users me-1"></i><?= $ts['passenger_count'] ?> pax</span>
                  </div>
                  <?php if (!empty($teams[(int)$ts['schedule_id']])): ?>
                  <div class="small mt-2"><i class="fas fa-users text-muted me-1"></i><strong>Team job</strong> with <?= $teamLine($teams[(int)$ts['schedule_id']]) ?></div>
                  <?php endif; ?>
                  <?php if (!empty($ts['officer_name']) || !empty($ts['officer_phone']) || !empty($ts['waiting_place'])): ?>
                  <div class="small mt-2">
                    <?php if (!empty($ts['officer_name'])): ?><span class="me-2"><i class="fas fa-user-tie text-muted me-1"></i><?= htmlspecialchars($ts['officer_name']) ?></span><?php endif; ?>
                    <?php if (!empty($ts['officer_phone'])): ?><a href="tel:<?= htmlspecialchars(str_replace(' ', '', $ts['officer_phone'])) ?>" class="me-2"><i class="fas fa-phone me-1"></i><?= htmlspecialchars($ts['officer_phone']) ?></a><?php endif; ?>
                    <?php if (!empty($ts['waiting_place'])): ?><span class="text-muted"><i class="fas fa-map-marker-alt me-1"></i><?= htmlspecialchars($ts['waiting_place']) ?></span><?php endif; ?>
                  </div>
                  <?php endif; ?>
                </div>
                <div>
                  <?php
                  $smap = ['pending'=>['warning text-dark','clock'],'approved'=>['primary','thumbs-up'],'in_progress'=>['info','spinner fa-spin'],'completed'=>['success','check-circle'],'cancelled'=>['danger','times-circle']];
                  [$bc,$ic] = $smap[$ts['status']] ?? ['secondary','circle'];
                  ?>
                  <span class="badge bg-<?= $bc ?>"><i class="fas fa-<?= $ic ?> me-1"></i><?= ucfirst(str_replace('_',' ',$ts['status'])) ?></span>
                  <?php if (in_array($ts['status'],['approved','in_progress'])): ?>
                  <div class="mt-2">
                    <?php if ($ts['status']==='approved'): ?>
                    <button class="btn btn-sm btn-info" onclick="updateStatus(<?= $ts['schedule_id'] ?>,'in_progress',this)">Start Trip</button>
                    <?php elseif ($ts['status']==='in_progress'): ?>
                    <button class="btn btn-sm btn-success" onclick="updateStatus(<?= $ts['schedule_id'] ?>,'completed',this)">Complete</button>
                    <?php endif; ?>
                  </div>
                  <?php endif; ?>
                </div>
              </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Performance Card + Upcoming -->
      <div class="col-lg-5">
        <!-- Performance Stats -->
        <div class="card border-0 shadow-sm mb-4">
          <div class="card-header bg-white border-bottom">
            <h6 class="mb-0 fw-semibold"><i class="fas fa-chart-bar text-success me-2"></i>My Performance</h6>
          </div>
          <div class="card-body">
            <?php
            $task_factor    = 1 - min($month_tasks / 10, 1);
            $weekend_factor = 1 - min($month_weekend / 4, 1);
            $exp_factor     = min(((float)$driver['experience_years']) / 20, 1);
            $metrics = [
                ['label'=>'Tasks this month','value'=>$task_factor*10,'raw'=>$month_tasks.' task'.($month_tasks!==1?'s':''),'weight'=>50,'color'=>'green'],
                ['label'=>'Weekend tasks','value'=>$weekend_factor*10,'raw'=>$month_weekend.' weekend task'.($month_weekend!==1?'s':''),'weight'=>30,'color'=>'gold'],
                ['label'=>'Experience','value'=>$exp_factor*10,'raw'=>$driver['experience_years'].' yrs','weight'=>20,'color'=>'teal'],
            ];
            foreach ($metrics as $m):
            $pct = ($m['value']/10)*100;
            ?>
            <div class="mb-3">
              <div class="d-flex justify-content-between align-items-center mb-1">
                <small class="fw-semibold"><?= $m['label'] ?> <span class="text-muted">(<?= $m['weight'] ?>%)</span></small>
                <small class="text-muted"><?= $m['raw'] ?></small>
              </div>
              <div class="progress" style="height:8px">
                <div class="progress-bar bar-<?= $m['color'] ?>" role="progressbar" aria-valuenow="<?= round($pct) ?>" aria-valuemin="0" aria-valuemax="100" style="width:<?= round($pct) ?>%"></div>
              </div>
            </div>
            <?php endforeach; ?>
            <hr>
            <div class="d-flex justify-content-between align-items-center">
              <strong>Allocation Score</strong>
              <?php $bc = $priority_score>=7?'success':($priority_score>=5?'warning':'danger'); ?>
              <span class="badge bg-<?= $bc ?> fs-6"><?= $priority_score ?> / 10</span>
            </div>
          </div>
        </div>

        <!-- Upcoming -->
        <div class="card border-0 shadow-sm">
          <div class="card-header bg-white border-bottom d-flex justify-content-between">
            <h6 class="mb-0 fw-semibold"><i class="fas fa-calendar-alt text-primary me-2"></i>Upcoming Trips</h6>
            <a href="schedules.php" class="btn btn-sm btn-outline-primary">View All</a>
          </div>
          <div class="card-body p-0">
            <?php if (empty($upcoming_schedules)): ?>
            <div class="text-center py-4"><p class="text-muted small">No upcoming trips.</p></div>
            <?php else: ?>
            <ul class="list-group list-group-flush">
              <?php foreach ($upcoming_schedules as $us): ?>
              <li class="list-group-item py-3">
                <div class="d-flex justify-content-between align-items-start">
                  <div>
                    <div class="fw-semibold small"><?= htmlspecialchars($us['destination']) ?></div>
                    <small class="text-muted">
                      <i class="fas fa-calendar me-1"></i><?= date('d M',strtotime($us['trip_date'])) ?>
                      &nbsp;<i class="fas fa-clock me-1"></i><?= substr($us['start_time'],0,5) ?>
                    </small>
                    <?php if (!empty($teams[(int)$us['schedule_id']])): ?>
                    <div class="small mt-1"><i class="fas fa-users text-muted me-1"></i>Team job with <?= $teamLine($teams[(int)$us['schedule_id']]) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($us['officer_name']) || !empty($us['officer_phone']) || !empty($us['waiting_place'])): ?>
                    <div class="small mt-1">
                      <?php if (!empty($us['officer_name'])): ?><span class="me-2"><i class="fas fa-user-tie text-muted me-1"></i><?= htmlspecialchars($us['officer_name']) ?></span><?php endif; ?>
                      <?php if (!empty($us['officer_phone'])): ?><a href="tel:<?= htmlspecialchars(str_replace(' ', '', $us['officer_phone'])) ?>" class="me-2"><i class="fas fa-phone me-1"></i><?= htmlspecialchars($us['officer_phone']) ?></a><?php endif; ?>
                      <?php if (!empty($us['waiting_place'])): ?><span class="text-muted"><i class="fas fa-map-marker-alt me-1"></i><?= htmlspecialchars($us['waiting_place']) ?></span><?php endif; ?>
                    </div>
                    <?php endif; ?>
                  </div>
                  <?php
                  $smap2 = ['pending'=>'warning text-dark','approved'=>'primary','in_progress'=>'info','completed'=>'success','cancelled'=>'secondary'];
                  $bc2 = $smap2[$us['status']] ?? 'secondary';
                  ?>
                  <span class="badge bg-<?= $bc2 ?>"><?= ucfirst(str_replace('_',' ',$us['status'])) ?></span>
                </div>
              </li>
              <?php endforeach; ?>
            </ul>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Recent Completed -->
    <?php if (!empty($recent_completed)): ?>
    <div class="card border-0 shadow-sm mt-4">
      <div class="card-header bg-white border-bottom">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-history text-success me-2"></i>Recent Completed Trips</h6>
      </div>
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead class="table-light">
            <tr><th scope="col">Date</th><th scope="col">Destination</th><th scope="col">Time</th><th scope="col">Vehicle</th><th scope="col">Status</th></tr>
          </thead>
          <tbody>
            <?php foreach ($recent_completed as $rc): ?>
            <tr>
              <td><?= date('d M Y', strtotime($rc['trip_date'])) ?></td>
              <td><?= htmlspecialchars($rc['destination']) ?></td>
              <td><?= substr($rc['start_time'],0,5) ?> – <?= substr($rc['end_time'],0,5) ?></td>
              <td><?= $rc['plate_number'] ? htmlspecialchars($rc['plate_number']) : '<span class="text-muted">N/A</span>' ?></td>
              <td><span class="badge bg-success">Completed</span></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>
</main>

<?php
$extra_js = '<script>
function updateStatus(scheduleId, newStatus, btn){
  const dialogs={
    in_progress:{title:"Start this trip?",text:"The trip will be marked as In Progress.",confirmText:"Start Trip"},
    completed:{title:"Complete this trip?",text:"The trip will be marked as completed. This cannot be undone.",confirmText:"Mark Completed"}
  };
  const d=dialogs[newStatus]||{title:"Update status?",text:"",confirmText:"Update"};
  UIS.confirm({title:d.title,text:d.text,confirmText:d.confirmText,tone:"primary",icon:newStatus==="completed"?"fa-circle-check":"fa-play"}).then(function(ok){
    if(!ok) return;
    btn.disabled=true;
    fetch("../ajax/update_schedule_status.php",{
      method:"POST",
      headers:{"Content-Type":"application/x-www-form-urlencoded"},
      body:"schedule_id="+scheduleId+"&status="+newStatus
    })
    .then(r=>r.json())
    .then(data=>{
      if(data.success){ location.reload(); }
      else{ UIS.alert(data.message||"The status could not be updated.",{title:"Update failed",tone:"danger"}); btn.disabled=false; }
    })
    .catch(()=>{ UIS.alert("Please check your connection and try again.",{title:"Network error",tone:"danger"}); btn.disabled=false; });
  });
}
</script>';
require_once '../includes/footer.php';
?>
