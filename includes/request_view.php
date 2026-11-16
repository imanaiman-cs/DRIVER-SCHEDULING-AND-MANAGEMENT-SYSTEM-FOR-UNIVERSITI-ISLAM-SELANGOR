<?php
// ============================================================
// includes/request_view.php  -  one way to read and show a vehicle request (UC011)
//
//   requestSelectSql()               the SELECT every page uses (request + requester + vehicle + Head of Department)
//   requestDetailsHtml($conn,$req,$o) the details: trip, status history, reasons, comments, documents and - only when
//                                     allowed - the drivers and vehicles of the two tasks
//   requestStatusBadge($status)      the status as a coloured label ("Driver Assigned", "Confirmed" ...)
//
// "The driver and vehicle are shown only AFTER the assignment is Confirmed": the staff view never gets them earlier.
// The Head of Department sees what is assigned too only for his own information once it is Confirmed;
// the Administrative Assistant and the Head of Admin always see the assignment.
// ============================================================

require_once __DIR__ . '/upload.php';

function requestSelectSql(): string
{
    return "SELECT vr.*, u.full_name AS staff_name, udp.department_name AS staff_department, u.department_id AS staff_department_id, u.role AS staff_role, u.phone AS staff_phone,
                   hod.full_name AS hod_name, v.plate_number, v.model AS vehicle_model, v.capacity
              FROM vehicle_requests vr
              JOIN users u ON u.user_id = vr.staff_id
              LEFT JOIN departments udp ON udp.department_id = u.department_id
              LEFT JOIN users hod ON hod.user_id = vr.supervisor_id
              LEFT JOIN vehicles v ON v.vehicle_id = vr.vehicle_id";
}

/**
 * UC017 step 2: the filters of the request lists (status, departure date from / to, department).
 * @return array{0:string,1:string,2:array,3:array} [sql fragment starting with ' AND ' or '', bind types, params, the cleaned filter values]
 */
function requestListFilter(array $get, bool $withDepartment = false): array
{
    $status = (string)($get['status'] ?? '');
    $from = (string)($get['from'] ?? ''); $to = (string)($get['to'] ?? '');
    $dept = $withDepartment ? trim((string)($get['department'] ?? '')) : '';
    $f = ['status' => in_array($status, ['pending', 'approved', 'assigned', 'processed', 'rejected', 'cancelled', 'lapsed', 'completed'], true) ? $status : '',
          'from' => isValidDate($from) ? $from : '', 'to' => isValidDate($to) ? $to : '', 'department' => mb_substr($dept, 0, 100)];
    $sql = ''; $types = ''; $p = [];
    if ($f['status'] !== '') { $sql .= ' AND vr.status = ?'; $types .= 's'; $p[] = $f['status']; }
    if ($f['from'] !== '')   { $sql .= ' AND vr.trip_date >= ?'; $types .= 's'; $p[] = $f['from']; }
    if ($f['to'] !== '')     { $sql .= ' AND vr.trip_date <= ?'; $types .= 's'; $p[] = $f['to']; }
    if ($f['department'] !== '') { $sql .= ' AND udp.department_name = ?'; $types .= 's'; $p[] = $f['department']; }
    return [$sql, $types, $p, $f];
}

/** The filter form of the request lists. $keep = hidden fields to keep (for example the tab). */
function requestFilterForm(array $f, array $keep = [], ?array $departments = null): void
{
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    ?>
    <form method="get" class="row g-2 align-items-end mb-3">
        <?php foreach ($keep as $k => $v): ?><input type="hidden" name="<?= $h($k) ?>" value="<?= $h($v) ?>"><?php endforeach; ?>
        <div class="col-6 col-md-3"><label class="form-label small mb-0" for="f_status">Status</label>
            <select class="form-select form-select-sm" id="f_status" name="status"><option value="">All</option>
                <?php foreach (['pending', 'approved', 'assigned', 'processed', 'rejected', 'cancelled', 'lapsed', 'completed'] as $st): ?><option value="<?= $st ?>" <?= $f['status'] === $st ? 'selected' : '' ?>><?= $h(requestStatusLabel($st)) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-0" for="f_from">Departure from</label><input type="date" class="form-control form-control-sm" id="f_from" name="from" value="<?= $h($f['from']) ?>"></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-0" for="f_to">to</label><input type="date" class="form-control form-control-sm" id="f_to" name="to" value="<?= $h($f['to']) ?>"></div>
        <?php if ($departments !== null): ?>
        <div class="col-6 col-md-3"><label class="form-label small mb-0" for="f_dept">Department</label>
            <select class="form-select form-select-sm" id="f_dept" name="department"><option value="">All</option>
                <?php foreach ($departments as $d): ?><option value="<?= $h($d) ?>" <?= $f['department'] === $d ? 'selected' : '' ?>><?= $h($d) ?></option><?php endforeach; ?></select></div>
        <?php endif; ?>
        <div class="col-auto"><button class="btn btn-sm btn-outline-success">Filter</button> <a class="btn btn-sm btn-outline-secondary" href="?<?= $h(http_build_query($keep)) ?>">Clear</a></div>
    </form>
    <?php
}

// ------------------------------------------------------------
// Request journey: where a request is on its way (drawn as a stepper). Purely a view of vr.status.
// ------------------------------------------------------------

/**
 * The stages of a request and how far it is.
 * @return array{steps: array<int, array{label:string, icon:string, state:string}>, ended:?string}
 *   state: done | current | todo | failed.   ended: rejected | cancelled | lapsed | null
 */
function requestJourney(array $req): array
{
    $rental = isPersonalRental($req);
    $noSection = isPayClaimRequest($req);                       // Pay and Claim: no vehicle and no driver to assign
    $steps = [['Submitted', 'paper-plane']];
    if (!$rental) { $steps[] = ['Head of Department', 'stamp']; }
    if (!$noSection) { $steps[] = ['Vehicle Section', isRequestWithoutDriver($req) ? 'car-side' : 'user-check']; }
    $steps[] = ['Head of Admin', 'user-shield'];
    $steps[] = ['Trip', 'route'];
    $steps[] = ['Completed', 'flag-checkered'];
    $idx = array_flip(array_column($steps, 0));
    $status = (string)$req['status'];
    // the stage that is being worked on now (everything before it is done)
    $current = match ($status) {
        'pending'   => $rental ? $idx['Vehicle Section'] : $idx['Head of Department'],
        'approved'  => $idx['Vehicle Section'] ?? $idx['Head of Admin'],
        'assigned'  => $idx['Head of Admin'],
        'processed' => $idx['Trip'],
        'completed' => count($steps),
        'lapsed', 'rejected' => $rental ? $idx['Vehicle Section'] : $idx['Head of Department'],
        default     => empty($req['reviewed_at']) && !$rental ? $idx['Head of Department'] : ($idx['Vehicle Section'] ?? $idx['Head of Admin']),   // cancelled
    };
    $ended = in_array($status, ['rejected', 'cancelled', 'lapsed'], true) ? $status : null;
    $out = [];
    foreach ($steps as $i => [$label, $icon]) {
        $state = $i < $current ? 'done' : ($i === $current ? ($ended ? 'failed' : 'current') : 'todo');
        $out[] = ['label' => $label, 'icon' => $icon, 'state' => $state];
    }
    return ['steps' => $out, 'ended' => $ended];
}

/** The journey as HTML. $mini = a thin rail for list rows; otherwise the full stepper with labels. */
function requestJourneyHtml(array $req, bool $mini = false): string
{
    $j = requestJourney($req);
    $n = count($j['steps']);
    $doneCount = count(array_filter($j['steps'], fn($s) => $s['state'] === 'done'));
    $label = $j['ended'] ? requestStatusLabel($req['status']) : ($doneCount >= $n ? 'Completed' : 'Now: ' . $j['steps'][$doneCount]['label']);
    if ($mini) {
        $h = '<div class="rq-rail' . ($j['ended'] ? ' is-ended' : '') . '" role="img" aria-label="' . reqH('Step ' . min($doneCount + 1, $n) . ' of ' . $n . '. ' . $label) . '">';
        foreach ($j['steps'] as $s) { $h .= '<i class="rq-rail-seg is-' . $s['state'] . '" title="' . reqH($s['label']) . '"></i>'; }
        return $h . '<span class="rq-rail-text">' . reqH($label) . '</span></div>';
    }
    $h = '<ol class="rq-journey' . ($j['ended'] ? ' is-ended' : '') . '" aria-label="Progress of the request">';
    foreach ($j['steps'] as $s) {
        $icon = $s['state'] === 'done' ? 'check' : ($s['state'] === 'failed' ? 'xmark' : $s['icon']);
        $h .= '<li class="rq-step is-' . $s['state'] . '"' . ($s['state'] === 'current' ? ' aria-current="step"' : '') . '><span class="rq-dot"><i class="fas fa-' . $icon . '" aria-hidden="true"></i></span>'
            . '<span class="rq-label">' . reqH($s['label']) . ($s['state'] === 'failed' ? '<small>' . reqH(requestStatusLabel($req['status'])) . '</small>' : '') . '</span></li>';
    }
    return $h . '</ol>';
}

/** "in 3 days" / "in 5 h" / "today" chip for a trip that is still coming (empty for past or ended requests). */
function tripCountdownHtml(string $startDt, bool $active = true): string
{
    $sec = strtotime($startDt) - time();
    if (!$active || $sec <= 0) { return ''; }
    $text = $sec < 3600 ? 'in ' . max(1, (int)round($sec / 60)) . ' min' : ($sec < 86400 ? 'in ' . (int)round($sec / 3600) . ' h' : 'in ' . (int)floor($sec / 86400) . ' day' . ($sec >= 172800 ? 's' : ''));
    $tone = $sec < 86400 ? 'soon' : ($sec < 172800 ? 'near' : 'later');
    return '<span class="trip-chip is-' . $tone . '"><i class="fas fa-clock" aria-hidden="true"></i> ' . $text . '</span>';
}

function requestStatusBadge(string $status): string
{
    return '<span class="badge ' . requestStatusBadgeClass($status) . '">' . htmlspecialchars(requestStatusLabel($status)) . '</span>';
}

function reqH($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES); }

/** Departure and return as one readable line. */
function requestPeriodText(array $req): string
{
    $d = strtotime(requestDepartDT($req));
    $r = strtotime(requestReturnDT($req));
    return date('D, d M Y H:i', $d) . ' &rarr; ' . (date('Y-m-d', $d) === date('Y-m-d', $r) ? date('H:i', $r) : date('D, d M Y H:i', $r));
}

/**
 * @param array $o show_assignment (bool, default false), comments (bool, default true), history (bool, default true)
 */
function requestDetailsHtml(mysqli $conn, array $req, array $o = []): string
{
    $rid = (int)$req['request_id'];
    $showAssign = !empty($o['show_assignment']) || (in_array($req['status'], ['processed', 'completed'], true) && ($o['show_after_confirmed'] ?? true));
    ob_start();
    ?>
    <?= requestJourneyHtml($req) ?>
    <div class="row g-3 small">
        <div class="col-md-6">
            <div class="text-muted">Requester</div>
            <div class="fw-semibold"><?= reqH($req['staff_name']) ?> <span class="text-muted fw-normal">(<?= reqH($req['staff_department']) ?>)</span></div>
            <div><?= reqH($req['officer_phone']) ?></div>
        </div>
        <div class="col-md-6">
            <div class="text-muted">Trip</div>
            <div class="fw-semibold"><?= requestPeriodText($req) ?></div>
            <div><?= (int)$req['passenger_count'] ?> passenger(s)</div>
        </div>
        <div class="col-md-6">
            <div class="text-muted">Destination</div>
            <div class="fw-semibold"><?= reqH($req['destination']) ?></div>
            <div><?= reqH($req['destination_address']) ?></div>
        </div>
        <div class="col-md-6">
            <div class="text-muted">Waiting location</div>
            <div><?= reqH($req['waiting_place'] === 'Other' ? ($req['waiting_address'] ?: 'Other') : $req['waiting_place']) ?></div>
        </div>
        <div class="col-12">
            <div class="text-muted">Purpose<?= !empty($req['purpose_type']) ? ' (' . reqH(PURPOSE_TYPES[$req['purpose_type']] ?? ucfirst(str_replace('_', ' ', $req['purpose_type']))) . ')' : '' ?></div>
            <div><?= nl2br(reqH($req['purpose'])) ?></div>
        </div>
        <?php if (!empty($req['passenger_names'])): ?>
        <div class="col-12">
            <div class="text-muted">Passenger names</div>
            <div><?= nl2br(reqH($req['passenger_names'])) ?></div>
        </div>
        <?php endif; ?>
        <div class="col-md-6">
            <div class="text-muted"><?= isPayClaimRequest($req) ? 'Own vehicle' : 'Requested vehicle' ?></div>
            <div><?= isPayClaimRequest($req) ? reqH($req['own_vehicle_plate'] ?: '—') : ($req['vehicle_type'] ? reqH(((int)$req['quantity'] > 1 ? (int)$req['quantity'] . ' x ' : '') . $req['vehicle_type']) . ($req['plate_number'] ? ' &middot; ' . reqH($req['plate_number'] . ' - ' . $req['vehicle_model'] . ' (' . $req['capacity'] . ' seats)') : ' <span class="text-muted">(the vehicle is chosen when the driver is assigned)</span>') : '&mdash;') ?></div>
            <?php if (isPayClaimRequest($req)): ?><div class="mt-1"><span class="badge bg-warning text-dark"><i class="fas fa-coins me-1"></i>Pay and Claim</span></div>
            <?php elseif (isRequestWithoutDriver($req)): ?><div class="mt-1"><span class="badge bg-secondary"><i class="fas fa-steering-wheel me-1"></i>Without driver</span></div><?php endif; ?>
        </div>
        <div class="col-md-6">
            <?php if (isPersonalRental($req)): ?><div class="mb-1"><span class="badge bg-info text-dark"><i class="fas fa-truck me-1"></i>Personal Rental</span> <?= reqH(rtrim(rtrim(number_format((float)$req['distance_km'], 1), '0'), '.')) ?> km &middot; charge RM <?= reqH(number_format((float)$req['charge'], 2)) ?> <span class="small text-muted">(no Head of Department: Assistant, then Head of Admin)</span></div><?php endif; ?>
            <?php if ((isPayClaimRequest($req) || isPersonalRental($req)) && in_array($req['status'], ['processed', 'completed'], true)): ?><div class="mb-2"><a class="btn btn-sm btn-outline-success" target="_blank" rel="noopener" href="<?= reqH(SITE_URL) ?>/staff/print_approval.php?id=<?= (int)$req['request_id'] ?>"><i class="fas fa-print me-1"></i><?= isPersonalRental($req) ? 'Print Rental Approval' : 'Print Claim Approval' ?></a></div><?php endif; ?>
            <?php if (!empty($req['is_urgent'])): ?><div class="mb-1"><span class="badge bg-danger"><i class="fas fa-bolt me-1"></i>Urgent</span> <span class="small text-muted">submitted less than 2 days before departure</span></div><?php endif; ?>
            <div class="text-muted">Head of Department</div>
            <div><?= reqH($req['hod_name'] ?: '—') ?></div>
        </div>
        <?php if (in_array($req['status'], ['rejected', 'cancelled', 'lapsed'], true)): ?>
        <div class="col-12">
            <div class="alert alert-secondary py-2 mb-0">
                <strong><?= reqH(requestStatusLabel($req['status'])) ?></strong>
                <?php if ($req['status'] === 'lapsed'): ?> - it was not approved within <?= reqH(lapseWindowText($conn)) ?>.
                <?php elseif (!empty($req['status_reason'])): ?> - <?= reqH($req['status_reason']) ?>
                <?php elseif ($req['status'] === 'cancelled'): ?> - no reason was given.<?php endif; ?>
                <?php if ($req['status'] === 'cancelled' && !empty($req['cancelled_by_role'])): ?>
                    <span class="text-muted">(by <?= reqH(requestActorLabel($req['cancelled_by_role'])) ?>)</span>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($showAssign): ?>
        <?php
            $stmt = $conn->prepare("SELECT s.trip_type, s.start_dt, s.end_dt, s.status, d.name AS driver, d.phone AS driver_phone, v.plate_number, v.model,
                                           s.vendor_name, s.vendor_bus_plate, s.vendor_driver_name
                                      FROM tasks s LEFT JOIN drivers d ON d.driver_id = s.driver_id LEFT JOIN vehicles v ON v.vehicle_id = s.vehicle_id
                                     WHERE s.task_source = 'request' AND s.request_id = ? AND s.status <> 'cancelled' ORDER BY s.trip_type");
            $stmt->bind_param('i', $rid);
            $stmt->execute();
            $tasks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        ?>
        <?php if ($tasks): ?>
        <div class="col-12">
            <div class="text-muted">Driver and vehicle <?= $req['status'] === 'assigned' ? '(waiting for the Head of Admin to approve)' : '' ?></div>
            <table class="table table-sm mb-0">
                <tbody>
                <?php foreach ($tasks as $t): ?>
                    <tr>
                        <td class="text-nowrap"><?= $t['trip_type'] === 'return' ? 'Return' : 'Outbound' ?></td>
                        <td class="text-nowrap"><?= date('d M H:i', strtotime($t['start_dt'])) ?>-<?= date('H:i', strtotime($t['end_dt'])) ?></td>
                        <?php if ($t['vendor_name'] !== null): ?>
                        <td><?= reqH($t['vendor_driver_name'] ?: $t['vendor_name']) ?> <span class="badge bg-warning text-dark">Vendor bus</span></td>
                        <td><?= reqH($t['vendor_bus_plate']) ?> <span class="text-muted"><?= reqH($t['vendor_name']) ?></span></td>
                        <?php else: ?>
                        <td><?= reqH($t['driver'] ?: 'no driver') ?><?= $t['driver_phone'] ? ' <span class="text-muted">' . reqH($t['driver_phone']) . '</span>' : '' ?></td>
                        <td><?= reqH($t['plate_number']) ?> <span class="text-muted"><?= reqH($t['model']) ?></span></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php elseif (isRequestWithoutDriver($req)): ?>
        <div class="col-12">
            <div class="text-muted">Driver and vehicle <?= $req['status'] === 'assigned' ? '(waiting for the Head of Admin to approve)' : '' ?></div>
            <div><?= isPayClaimRequest($req) ? 'Pay and Claim: the staff member uses their own vehicle (' . reqH($req['own_vehicle_plate'] ?: '-') . '). No UIS vehicle or driver is booked.' : 'Without driver: no driver is assigned. Only the use of ' . reqH($req['plate_number'] ?: 'the vehicle') . ' is approved.' ?></div>
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <?php $docs = getRequestDocuments($conn, [$rid])[$rid] ?? []; if ($docs): ?>
        <div class="col-12">
            <div class="text-muted">Supporting documents</div>
            <?php foreach ($docs as $d): ?>
                <a class="d-inline-block me-3" href="<?= reqH($d['url']) ?>" target="_blank" rel="noopener"><i class="fas fa-paperclip me-1"></i><?= reqH($d['original_name']) ?> <span class="text-muted">(<?= reqH(formatFileSize($d['file_size'])) ?>)</span></a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (($o['comments'] ?? true) && ($comments = requestComments($conn, $rid))): ?>
        <div class="col-12">
            <div class="text-muted">Comments</div>
            <?php foreach ($comments as $c): ?>
                <div class="border-start border-3 ps-2 mb-1" style="border-color:#15803d !important;">
                    <?= nl2br(reqH($c['comment'])) ?>
                    <div class="text-muted" style="font-size:.75rem;"><?= reqH($c['full_name']) ?> (<?= reqH($c['role_label']) ?>), <?= date('d M Y H:i', strtotime($c['created_at'])) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($o['history'] ?? true): ?>
        <div class="col-12">
            <div class="text-muted">Status history</div>
            <ul class="list-unstyled mb-0">
            <?php $internalOnly = ['assign', 'reject_assignment', 'replace_driver'];   // the Vehicle Section's own steps
                  foreach (requestHistory($conn, $rid) as $h): if ($h['action'] === 'comment' || (!isAdmin() && in_array($h['action'], $internalOnly, true))) { continue; } ?>
                <li>
                    <span class="text-muted"><?= date('d M Y H:i', strtotime($h['created_at'])) ?></span>
                    <strong><?= reqH($h['label']) ?></strong> - <?= reqH($h['who']) ?>
                    <?= $h['reason'] && !in_array($h['action'], ['submit'], true) ? '<span class="text-muted"> (' . reqH($h['reason']) . ')</span>' : '' ?>
                </li>
            <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
    </div>
    <?php
    return (string)ob_get_clean();
}

/**
 * Answers an action endpoint. A normal form post (field return_to = a relative page of this site) gets a flash
 * message and a redirect; anything else gets JSON {success, message}.
 */
function actionResponse(array $res, int $http_on_error = 200): void
{
    $to = (string)($_POST['return_to'] ?? '');
    if ($to !== '' && preg_match('#^[A-Za-z0-9_./-]+\.php(\?[A-Za-z0-9_=&.%-]*)?$#', $to) && !str_contains($to, '..')) {
        setFlash($res['ok'] ? 'success' : 'danger', $res['message']);
        header('Location: ' . SITE_URL . '/' . $to);
        exit();
    }
    if (!$res['ok'] && $http_on_error) { http_response_code($http_on_error); }
    header('Content-Type: application/json');
    echo json_encode(['success' => $res['ok'], 'message' => $res['message']]);
    exit();
}
