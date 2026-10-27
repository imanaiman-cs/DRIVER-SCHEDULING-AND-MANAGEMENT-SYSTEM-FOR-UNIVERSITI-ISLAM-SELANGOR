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
    return "SELECT vr.*, u.full_name AS staff_name, u.department AS staff_department, u.role AS staff_role, u.phone AS staff_phone,
                   hod.full_name AS hod_name, v.plate_number, v.model AS vehicle_model, v.vehicle_type, v.capacity
              FROM vehicle_requests vr
              JOIN users u ON u.user_id = vr.staff_id
              LEFT JOIN users hod ON hod.user_id = vr.supervisor_id
              LEFT JOIN vehicles v ON v.vehicle_id = vr.vehicle_id";
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
            <div class="text-muted">Purpose</div>
            <div><?= nl2br(reqH($req['purpose'])) ?></div>
        </div>
        <?php if (!empty($req['passenger_names'])): ?>
        <div class="col-12">
            <div class="text-muted">Passenger names</div>
            <div><?= nl2br(reqH($req['passenger_names'])) ?></div>
        </div>
        <?php endif; ?>
        <div class="col-md-6">
            <div class="text-muted">Requested vehicle</div>
            <div><?= $req['plate_number'] ? reqH($req['plate_number'] . ' - ' . $req['vehicle_model'] . ' (' . $req['vehicle_type'] . ', ' . $req['capacity'] . ' seats)') : '&mdash;' ?></div>
            <?php if (isRequestWithoutDriver($req)): ?><div class="mt-1"><span class="badge bg-secondary"><i class="fas fa-steering-wheel me-1"></i>Without driver</span></div><?php endif; ?>
        </div>
        <div class="col-md-6">
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
            $stmt = $conn->prepare("SELECT s.leg_no, s.trip_date, s.start_time, s.end_time, s.status, d.name AS driver, d.phone AS driver_phone, v.plate_number, v.model
                                      FROM schedules s LEFT JOIN drivers d ON d.driver_id = s.driver_id LEFT JOIN vehicles v ON v.vehicle_id = s.vehicle_id
                                     WHERE s.request_id = ? AND s.status <> 'cancelled' ORDER BY s.leg_no");
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
                        <td class="text-nowrap"><?= (int)$t['leg_no'] === 2 ? 'Return' : 'Outbound' ?></td>
                        <td class="text-nowrap"><?= date('d M H:i', strtotime($t['trip_date'] . ' ' . $t['start_time'])) ?>-<?= substr($t['end_time'], 0, 5) ?></td>
                        <td><?= reqH($t['driver'] ?: 'no driver') ?><?= $t['driver_phone'] ? ' <span class="text-muted">' . reqH($t['driver_phone']) . '</span>' : '' ?></td>
                        <td><?= reqH($t['plate_number']) ?> <span class="text-muted"><?= reqH($t['model']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php elseif (isRequestWithoutDriver($req)): ?>
        <div class="col-12">
            <div class="text-muted">Driver and vehicle <?= $req['status'] === 'assigned' ? '(waiting for the Head of Admin to approve)' : '' ?></div>
            <div>Without driver: no driver is assigned. Only the use of <?= reqH($req['plate_number'] ?: 'the vehicle') ?> is approved.</div>
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
