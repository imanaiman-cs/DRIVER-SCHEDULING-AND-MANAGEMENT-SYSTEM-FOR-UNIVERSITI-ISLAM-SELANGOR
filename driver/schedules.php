<?php
$page_title = 'My Schedules';
$current_page = 'driver_schedules';
require_once '../config/database.php';
requireDriver();

$driver_id = (int)$_SESSION['driver_id'];
$today = date('Y-m-d');

// Active tab
$tab = $_GET['tab'] ?? 'upcoming';
$allowed_tabs = ['today','upcoming','completed','all'];
if (!in_array($tab, $allowed_tabs)) $tab = 'upcoming';

// Build query based on tab
$where = "s.driver_id = ?";
$params = [$driver_id];
$types  = "i";

switch ($tab) {
    case 'today':
        $where .= " AND s.trip_date = ?";
        $params[] = $today; $types .= "s";
        break;
    case 'upcoming':
        $where .= " AND s.trip_date >= ? AND s.status NOT IN ('cancelled','completed')";
        $params[] = $today; $types .= "s";
        break;
    case 'completed':
        $where .= " AND s.status = 'completed'";
        break;
}

$sql = "SELECT s.*, v.plate_number, v.vehicle_type, v.brand, v.model, v.capacity
        FROM schedules s
        LEFT JOIN vehicles v ON s.vehicle_id = v.vehicle_id
        WHERE $where
        ORDER BY s.trip_date DESC, s.start_time DESC";

$stmt = $conn->prepare($sql);
if (count($params) > 1) {
    $stmt->bind_param($types, ...$params);
} else {
    $stmt->bind_param($types, $driver_id);
}
$stmt->execute();
$schedules = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Tab counts
$cnt_query = function($extra_where, $extra_params = [], $extra_types = '') use ($conn, $driver_id) {
    $s2 = $conn->prepare("SELECT COUNT(*) as cnt FROM schedules WHERE driver_id=? $extra_where");
    if ($extra_params) {
        $s2->bind_param("i".$extra_types, $driver_id, ...$extra_params);
    } else {
        $s2->bind_param("i", $driver_id);
    }
    $s2->execute();
    return (int)$s2->get_result()->fetch_assoc()['cnt'];
};

$cnt_today     = $cnt_query("AND trip_date=? AND status NOT IN ('cancelled')", [$today], "s");
$cnt_upcoming  = $cnt_query("AND trip_date>=? AND status NOT IN ('cancelled','completed')", [$today], "s");
$cnt_completed = $cnt_query("AND status='completed'");
$cnt_all       = $cnt_query("");

require_once '../includes/header.php';
require_once '../includes/sidebar.php';
?>

<div class="main-content">
  <!-- Top Bar -->
  <div class="topbar d-flex align-items-center justify-content-between px-4 py-2 bg-white shadow-sm">
    <div class="d-flex align-items-center gap-3">
      <button class="btn btn-sm btn-outline-secondary d-lg-none" onclick="toggleSidebar()"><i class="fas fa-bars"></i></button>
      <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
        <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
        <li class="breadcrumb-item active">My Schedules</li>
      </ol></nav>
    </div>
    <div class="d-flex align-items-center gap-3">
      <small class="text-muted"><?= date('D, d M Y') ?></small>
      <a href="../logout.php" class="btn btn-sm btn-outline-danger"><i class="fas fa-sign-out-alt me-1"></i>Logout</a>
    </div>
  </div>

  <div class="container-fluid p-4">
    <?php showFlash(); ?>

    <div class="d-flex justify-content-between align-items-center mb-4">
      <div>
        <h4 class="fw-bold text-primary mb-1"><i class="fas fa-calendar-alt me-2"></i>My Schedules</h4>
        <p class="text-muted mb-0">View and manage your assigned trips.</p>
      </div>
    </div>

    <!-- Tabs -->
    <ul class="nav nav-tabs mb-4">
      <?php
      $tabs = [
        'today'     => ['label'=>'Today',     'count'=>$cnt_today,     'icon'=>'calendar-day',   'color'=>'info'],
        'upcoming'  => ['label'=>'Upcoming',   'count'=>$cnt_upcoming,  'icon'=>'calendar-check', 'color'=>'primary'],
        'completed' => ['label'=>'Completed',  'count'=>$cnt_completed, 'icon'=>'check-circle',   'color'=>'success'],
        'all'       => ['label'=>'All Trips',  'count'=>$cnt_all,       'icon'=>'list',           'color'=>'secondary'],
      ];
      foreach ($tabs as $key => $t):
      ?>
      <li class="nav-item">
        <a class="nav-link <?= $tab===$key?'active':'' ?>" href="?tab=<?= $key ?>">
          <i class="fas fa-<?= $t['icon'] ?> me-1 text-<?= $t['color'] ?>"></i>
          <?= $t['label'] ?>
          <span class="badge bg-<?= $t['color'] ?> ms-1 rounded-pill"><?= $t['count'] ?></span>
        </a>
      </li>
      <?php endforeach; ?>
    </ul>

    <!-- Schedule Table -->
    <div class="card border-0 shadow-sm">
      <div class="card-body p-0">
        <?php if (empty($schedules)): ?>
        <div class="text-center py-5">
          <i class="fas fa-calendar-times fa-3x text-muted mb-3"></i>
          <h5 class="text-muted">No schedules found</h5>
          <p class="text-muted small">
            <?php if ($tab==='today'): ?>No trips assigned for today.
            <?php elseif ($tab==='upcoming'): ?>No upcoming trips.
            <?php elseif ($tab==='completed'): ?>No completed trips yet.
            <?php else: ?>No trip records found.<?php endif; ?>
          </p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
          <table class="table table-hover data-table mb-0" id="schedulesTable">
            <thead class="table-primary">
              <tr>
                <th>#</th>
                <th>Date</th>
                <th>Time</th>
                <th>Destination</th>
                <th>Purpose</th>
                <th>Vehicle</th>
                <th>Pax</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($schedules as $i => $s): ?>
              <?php
              $smap = [
                'pending'     => ['warning text-dark', 'clock'],
                'approved'    => ['primary', 'thumbs-up'],
                'in_progress' => ['info', 'car'],
                'completed'   => ['success', 'check-circle'],
                'cancelled'   => ['secondary', 'times-circle'],
              ];
              [$bc,$ic] = $smap[$s['status']] ?? ['secondary','circle'];
              $is_today = ($s['trip_date'] === $today);
              ?>
              <tr class="<?= $is_today?'table-active':'' ?>">
                <td><?= $s['schedule_id'] ?></td>
                <td>
                  <?= date('d M Y', strtotime($s['trip_date'])) ?>
                  <?php if ($is_today): ?><span class="badge bg-danger ms-1">Today</span><?php endif; ?>
                </td>
                <td>
                  <i class="fas fa-play text-success" style="font-size:10px"></i> <?= substr($s['start_time'],0,5) ?><br>
                  <i class="fas fa-stop text-danger" style="font-size:10px"></i> <?= substr($s['end_time'],0,5) ?>
                </td>
                <td>
                  <strong><?= htmlspecialchars($s['destination']) ?></strong>
                </td>
                <td><?= htmlspecialchars($s['purpose'] ?? '—') ?></td>
                <td>
                  <?php if ($s['plate_number']): ?>
                  <span class="badge bg-secondary"><?= htmlspecialchars($s['plate_number']) ?></span><br>
                  <small class="text-muted"><?= $s['vehicle_type'] ?></small>
                  <?php else: ?>
                  <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td><i class="fas fa-users text-muted me-1"></i><?= (int)$s['passenger_count'] ?></td>
                <td><span class="badge bg-<?= $bc ?>"><i class="fas fa-<?= $ic ?> me-1"></i><?= ucfirst(str_replace('_',' ',$s['status'])) ?></span></td>
                <td>
                  <div class="d-flex gap-1">
                    <button class="btn btn-sm btn-outline-info" onclick="viewDetails(<?= htmlspecialchars(json_encode($s), ENT_QUOTES) ?>)" title="View Details">
                      <i class="fas fa-eye"></i>
                    </button>
                    <?php if ($s['status']==='approved'): ?>
                    <button class="btn btn-sm btn-info" onclick="updateStatus(<?= $s['schedule_id'] ?>,'in_progress',this)" title="Start Trip">
                      <i class="fas fa-play"></i>
                    </button>
                    <?php elseif ($s['status']==='in_progress'): ?>
                    <button class="btn btn-sm btn-success" onclick="updateStatus(<?= $s['schedule_id'] ?>,'completed',this)" title="Complete Trip">
                      <i class="fas fa-flag-checkered"></i>
                    </button>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- View Details Modal -->
<div class="modal fade" id="viewModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title"><i class="fas fa-info-circle me-2"></i>Trip Details</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="viewModalBody">Loading...</div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<?php
$extra_js = '<script>
function viewDetails(s){
  const statMap={pending:"warning",approved:"primary",in_progress:"info",completed:"success",cancelled:"secondary"};
  const sc=statMap[s.status]||"secondary";
  document.getElementById("viewModalBody").innerHTML=`
    <div class="row g-3">
      <div class="col-md-6"><label class="text-muted small">Destination</label><div class="fw-semibold">${s.destination}</div></div>
      <div class="col-md-6"><label class="text-muted small">Purpose</label><div class="fw-semibold">${s.purpose||"—"}</div></div>
      <div class="col-md-4"><label class="text-muted small">Date</label><div class="fw-semibold">${s.trip_date}</div></div>
      <div class="col-md-4"><label class="text-muted small">Start Time</label><div class="fw-semibold">${s.start_time}</div></div>
      <div class="col-md-4"><label class="text-muted small">End Time</label><div class="fw-semibold">${s.end_time}</div></div>
      <div class="col-md-4"><label class="text-muted small">Vehicle</label><div class="fw-semibold">${s.plate_number||"Not assigned"}</div></div>
      <div class="col-md-4"><label class="text-muted small">Passengers</label><div class="fw-semibold">${s.passenger_count}</div></div>
      <div class="col-md-4"><label class="text-muted small">Status</label><div><span class="badge bg-${sc}">${s.status.replace("_"," ")}</span></div></div>
      ${s.notes?`<div class="col-12"><label class="text-muted small">Notes</label><div>${s.notes}</div></div>`:""}
    </div>
  `;
  new bootstrap.Modal(document.getElementById("viewModal")).show();
}

function updateStatus(scheduleId, newStatus, btn){
  const labels={in_progress:"Start this trip now?",completed:"Mark trip as completed?"};
  if(!confirm(labels[newStatus]||"Update status?")) return;
  btn.disabled=true;
  fetch("../ajax/update_schedule_status.php",{
    method:"POST",
    headers:{"Content-Type":"application/x-www-form-urlencoded"},
    body:"schedule_id="+scheduleId+"&status="+newStatus
  })
  .then(r=>r.json())
  .then(d=>{ if(d.success) location.reload(); else{ alert(d.message||"Failed"); btn.disabled=false; }})
  .catch(()=>{ alert("Network error"); btn.disabled=false; });
}
</script>';
require_once '../includes/footer.php';
?>
