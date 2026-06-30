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

$priority_score = calculatePriorityScore(
    $driver['experience_years'],
    $driver['attendance_rate'],
    $driver['performance_score'],
    $driver['certification_score']
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

require_once '../includes/header.php';
require_once '../includes/sidebar.php';
?>

<div class="main-content">
  <!-- Top Bar -->
  <div class="topbar d-flex align-items-center justify-content-between px-4 py-2 bg-white shadow-sm">
    <div class="d-flex align-items-center gap-3">
      <button class="btn btn-sm btn-outline-secondary d-lg-none" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i>
      </button>
      <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
        <li class="breadcrumb-item active">Dashboard</li>
      </ol></nav>
    </div>
    <div class="d-flex align-items-center gap-3">
      <small class="text-muted" id="liveClock"></small>
      <a href="../logout.php" class="btn btn-sm btn-outline-danger"><i class="fas fa-sign-out-alt me-1"></i>Logout</a>
    </div>
  </div>

  <div class="container-fluid p-4">
    <?php showFlash(); ?>

    <!-- Welcome Banner -->
    <div class="card border-0 shadow-sm mb-4 bg-primary text-white">
      <div class="card-body py-4">
        <div class="row align-items-center">
          <div class="col-md-8">
            <h4 class="fw-bold mb-1">Welcome back, <?= htmlspecialchars($driver['name']) ?>!</h4>
            <p class="mb-0 opacity-75">
              <i class="fas fa-id-badge me-2"></i><?= htmlspecialchars($driver['employee_id'] ?? 'N/A') ?>
              &nbsp;&bull;&nbsp;
              <i class="fas fa-calendar-day me-2"></i><?= date('l, d F Y') ?>
            </p>
          </div>
          <div class="col-md-4 text-md-end mt-3 mt-md-0">
            <?php $sc = $priority_score; $bc = $sc>=7?'success':($sc>=5?'warning':'danger'); ?>
            <div class="d-inline-block bg-white bg-opacity-20 rounded-3 px-4 py-2">
              <div class="small opacity-75">Priority Score</div>
              <div class="h2 fw-bold mb-0">
                <span class="badge bg-<?= $bc ?> fs-5"><?= $priority_score ?></span>
                <small class="fs-6 opacity-75">/10</small>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

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
              <?php if ($ts['status']==='in_progress'): ?>
              <span class="position-absolute top-0 end-0 badge bg-info rounded-0 rounded-bottom-start">In Progress</span>
              <?php endif; ?>
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
            $metrics = [
                ['label'=>'Experience','value'=>min(($driver['experience_years']/20)*10,10),'raw'=>$driver['experience_years'].' yrs','weight'=>30,'color'=>'primary'],
                ['label'=>'Attendance','value'=>($driver['attendance_rate']/100)*10,'raw'=>$driver['attendance_rate'].'%','weight'=>20,'color'=>'info'],
                ['label'=>'Performance','value'=>$driver['performance_score'],'raw'=>$driver['performance_score'].'/10','weight'=>30,'color'=>'success'],
                ['label'=>'Certification','value'=>$driver['certification_score'],'raw'=>$driver['certification_score'].'/10','weight'=>20,'color'=>'warning'],
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
                <div class="progress-bar bg-<?= $m['color'] ?>" style="width:<?= round($pct) ?>%"></div>
              </div>
            </div>
            <?php endforeach; ?>
            <hr>
            <div class="d-flex justify-content-between align-items-center">
              <strong>Priority Score</strong>
              <?php $bc = $priority_score>=7?'success':($priority_score>=5?'warning':'danger'); ?>
              <span class="badge bg-<?= $bc ?> fs-6"><?= $priority_score ?> / 10</span>
            </div>
          </div>
        </div>

        <!-- Upcoming -->
        <div class="card border-0 shadow-sm">
          <div class="card-header bg-white border-bottom d-flex justify-content-between">
            <h6 class="mb-0 fw-semibold"><i class="fas fa-calendar-alt text-info me-2"></i>Upcoming Trips</h6>
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
            <tr><th>Date</th><th>Destination</th><th>Time</th><th>Vehicle</th><th>Status</th></tr>
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
  </div>
</div>

<?php
$extra_js = '<script>
(function(){
  function tick(){
    const el=document.getElementById("liveClock");
    if(el) el.textContent=new Date().toLocaleString("en-MY",{weekday:"short",year:"numeric",month:"short",day:"numeric",hour:"2-digit",minute:"2-digit",second:"2-digit"});
  }
  tick(); setInterval(tick,1000);
})();

function updateStatus(scheduleId, newStatus, btn){
  const labels={in_progress:"Start this trip?",completed:"Mark this trip as completed?"};
  if(!confirm(labels[newStatus]||"Update status?")) return;
  btn.disabled=true;
  fetch("../ajax/update_schedule_status.php",{
    method:"POST",
    headers:{"Content-Type":"application/x-www-form-urlencoded"},
    body:"schedule_id="+scheduleId+"&status="+newStatus
  })
  .then(r=>r.json())
  .then(data=>{
    if(data.success){ location.reload(); }
    else{ alert(data.message||"Update failed"); btn.disabled=false; }
  })
  .catch(()=>{ alert("Network error"); btn.disabled=false; });
}
</script>';
require_once '../includes/footer.php';
?>
