<?php
// ============================================================
// UIS Driver Scheduling and Management System
// admin/notifications.php  –  E-mail notifications and log
// Universiti Islam Selangor (UIS)
// ============================================================

require_once '../config/database.php';
require_once __DIR__ . '/../includes/mailer.php';
requireAdmin();

$page_title   = 'Notifications';
$current_page = 'notifications.php';

// ── Data helpers ─────────────────────────────────────────────

/**
 * Latest e-mail log rows (newest first) with the driver's name.
 *
 * @return array<int, array<string, mixed>>
 */
function fetchEmailLog(mysqli $conn, int $limit = 200): array
{
    $limit = max(1, min($limit, 1000));
    $stmt  = $conn->prepare(
        "SELECT l.log_id, l.driver_id, l.to_email, l.delivered_to, l.subject, l.kind,
                l.ref_date, l.status, l.error_message, l.body_html, l.created_at,
                d.name AS driver_name
         FROM email_log l
         LEFT JOIN drivers d ON d.driver_id = l.driver_id
         ORDER BY l.created_at DESC, l.log_id DESC
         LIMIT ?"
    );
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('i', $limit);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/** Total number of rows in the log, for the "latest 200 of N" note. */
function countEmailLog(mysqli $conn): int
{
    $res = $conn->query('SELECT COUNT(*) AS n FROM email_log');
    $row = $res ? $res->fetch_assoc() : null;
    return (int)($row['n'] ?? 0);
}

/** Human label and CSS class for an e-mail type. */
function emailKindInfo(string $kind): array
{
    return match ($kind) {
        'assigned'  => ['New task',   'k-assigned'],
        'updated'   => ['Changed',    'k-updated'],
        'cancelled' => ['Cancelled',  'k-cancelled'],
        'removed'   => ['Reassigned', 'k-removed'],
        'reminder'  => ['Reminder',   'k-reminder'],
        'test'      => ['Test',       'k-test'],
        default     => [ucfirst($kind), 'k-test'],
    };
}

// ── Actions (Post / Redirect / Get) ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'test') {
        $to = trim((string)($_POST['test_email'] ?? ''));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            setFlash('danger', 'Please type a valid e-mail address for the test.');
        } else {
            $name = (string)($_SESSION['full_name'] ?? 'Administrator');
            $mail = buildDriverEmail('test', ['name' => $name], []);
            $res  = sendMail($conn, $to, $mail['subject'], $mail['html'], $mail['text'], ['kind' => 'test']);

            if ($res['status'] === 'sent') {
                setFlash('success', 'Test e-mail sent. ' . $res['message'] . ' It can take a minute to arrive; check the spam folder too.');
            } elseif ($res['status'] === 'skipped') {
                setFlash('warning', 'Nothing was sent. ' . $res['message']);
            } else {
                setFlash('danger', 'The test e-mail could not be sent: ' . $res['message']);
            }
        }
    } elseif ($action === 'reminders') {
        $results = sendRemindersForDate($conn);
        if (!$results) {
            setFlash('info', 'No drivers have tasks tomorrow (or they have all been reminded already).');
        } else {
            $failed = count(array_filter($results, fn($r) => $r['status'] === 'failed'));
            $sent   = count(array_filter($results, fn($r) => $r['status'] === 'sent'));
            $type   = $failed > 0 ? 'danger' : ($sent > 0 ? 'success' : 'warning');
            setFlash($type, emailSummary($results));
        }
    }

    header('Location: ' . SITE_URL . '/admin/notifications.php');
    exit();
}

// ── Page data ────────────────────────────────────────────────
$cfg       = mailConfig();
$enabled   = mailEnabled();
$redirects = $cfg['redirect_to'];
$logRows   = fetchEmailLog($conn, 200);
$logTotal  = countEmailLog($conn);

$counts = ['all' => count($logRows), 'sent' => 0, 'skipped' => 0, 'failed' => 0];
foreach ($logRows as $r) {
    if (isset($counts[$r['status']])) {
        $counts[$r['status']]++;
    }
}

// Overall totals for the stat cards
$totals = ['sent' => 0, 'skipped' => 0, 'failed' => 0];
if ($res = $conn->query('SELECT status, COUNT(*) AS n FROM email_log GROUP BY status')) {
    foreach ($res->fetch_all(MYSQLI_ASSOC) as $r) {
        $totals[$r['status']] = (int)$r['n'];
    }
}
$lastAt = $logRows ? $logRows[0]['created_at'] : null;

$defaultTo = $redirects[0] ?? '';

// Bodies and errors for the "View" modal, keyed by log id
$viewMap = [];
foreach ($logRows as $r) {
    [$kindLabel] = emailKindInfo((string)$r['kind']);
    $viewMap[(int)$r['log_id']] = [
        'subject' => (string)$r['subject'],
        'to'      => (string)($r['to_email'] ?? ''),
        'sentTo'  => (string)($r['delivered_to'] ?? ''),
        'driver'  => (string)($r['driver_name'] ?? ''),
        'kind'    => $kindLabel,
        'status'  => (string)$r['status'],
        'when'    => date('d M Y, g:i a', strtotime($r['created_at'])),
        'error'   => (string)($r['error_message'] ?? ''),
        'body'    => (string)($r['body_html'] ?? ''),
    ];
}
$viewJson = json_encode(
    $viewMap,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
) ?: '{}';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> | UIS Driver Management</title>
    <link rel="icon" type="image/png" href="<?php echo SITE_URL; ?>/assets/images/uis-favicon.png">

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome 6 -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- DataTables Bootstrap 5 -->
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Custom CSS -->
    <link href="<?php echo SITE_URL; ?>/assets/css/style.css" rel="stylesheet">

    <style>
        /* ── Page header ── */
        .page-header {
            background: linear-gradient(135deg, #0b5d3b 0%, #15804f 100%);
            border-radius: 14px;
            color: #fff;
            padding: 1.6rem 2rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 16px rgba(11,93,59,.20);
        }
        .page-header h1 { font-size: 1.55rem; font-weight: 700; margin: 0; color: #fff; }
        .page-header p  { margin: .3rem 0 0; opacity: .85; font-size: .88rem; }

        /* ── Cards ── */
        .n-card {
            border: none;
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 2px 12px rgba(11,93,59,.10);
        }
        .n-card .n-card-head {
            padding: 1rem 1.25rem .25rem;
            font-weight: 700;
            color: #0b5d3b;
            font-size: 1rem;
        }
        .n-card .n-card-head i { margin-right: .45rem; }
        .n-card .n-card-body { padding: .5rem 1.25rem 1.25rem; }

        /* ── Status card ── */
        .mail-state {
            display: inline-flex; align-items: center; gap: .45rem;
            font-weight: 700; font-size: .78rem; letter-spacing: .05em;
            border-radius: 999px; padding: .3rem .8rem;
        }
        .mail-state::before { content: ''; width: 8px; height: 8px; border-radius: 50%; background: currentColor; }
        .mail-on  { background: #dcfce7; color: #166534; }
        .mail-off { background: #f3f4f6; color: #4b5563; }
        .kv { display: flex; flex-wrap: wrap; gap: .2rem 2rem; font-size: .88rem; color: #374151; }
        .kv span.k { color: #6b7280; margin-right: .35rem; }
        .kv strong { color: #1a2035; font-weight: 600; word-break: break-all; }
        .testmode {
            display: flex; gap: .65rem; align-items: flex-start;
            background: #fffbeb; border: 1px solid #fcd34d; color: #92400e;
            border-radius: 10px; padding: .7rem .9rem; font-size: .86rem; line-height: 1.45;
        }
        .testmode i { margin-top: .15rem; }
        .howto {
            background: #f8faf9; border: 1px solid #e3e9e5; border-radius: 10px;
            padding: .8rem 1rem; font-size: .86rem; color: #374151;
        }
        .howto code {
            display: block; margin-top: .5rem; padding: .55rem .75rem;
            background: #1f2937; color: #e5e7eb; border-radius: 8px;
            font-size: .8rem; white-space: normal; line-height: 1.5;
        }

        /* ── Stat cards ── */
        .stat-card { border: none; border-radius: 14px; box-shadow: 0 2px 12px rgba(11,93,59,.10); }
        .stat-card .stat-icon {
            width: 52px; height: 52px; border-radius: 12px; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center; font-size: 1.3rem;
        }
        .stat-value { font-size: 1.9rem; font-weight: 700; line-height: 1; }
        .stat-value.sm { font-size: 1.05rem; line-height: 1.25; }
        .stat-label { font-size: .78rem; text-transform: uppercase; letter-spacing: .06em; color: #6c757d; }

        /* ── Log toolbar and chips ── */
        .vchip {
            border: 1px solid #d9e2dd; background: #fff; color: #374151; border-radius: 999px;
            padding: .32rem .85rem; font-size: .8rem; font-weight: 600; cursor: pointer; transition: all .15s;
        }
        .vchip:hover { border-color: #0b5d3b; color: #0b5d3b; }
        .vchip.active { background: #0b5d3b; border-color: #0b5d3b; color: #fff; }
        .vchip .n { opacity: .7; font-weight: 500; margin-left: .25rem; }

        /* ── Log table ── */
        #logTable thead th {
            background: #f8f9fb; font-size: .76rem; text-transform: uppercase; letter-spacing: .06em;
            color: #4b5563; border-bottom: 2px solid #e5e7eb; white-space: nowrap;
        }
        #logTable { min-width: 860px; }
        #logTable tbody tr:hover { background: #f0f6f2; }
        .subj { max-width: 340px; overflow-wrap: anywhere; }
        .err-text { color: #b91c1c; font-size: .74rem; max-width: 240px; overflow-wrap: anywhere; line-height: 1.3; margin-top: .25rem; }

        .pill {
            display: inline-block; font-size: .72rem; font-weight: 700; letter-spacing: .02em;
            border-radius: 999px; padding: .22rem .7rem; white-space: nowrap;
        }
        .k-assigned  { background: #dcfce7; color: #166534; }
        .k-updated   { background: #fef3c7; color: #92400e; }
        .k-cancelled,
        .k-removed   { background: #fee2e2; color: #991b1b; }
        .k-reminder  { background: #e0e7ef; color: #3b4a63; }
        .k-test      { background: #f3f4f6; color: #4b5563; }
        .s-sent      { background: #dcfce7; color: #166534; }
        .s-skipped   { background: #f3f4f6; color: #4b5563; }
        .s-failed    { background: #fee2e2; color: #991b1b; }

        .empty-log { text-align: center; padding: 3rem 1rem; color: #6b7280; }
        .empty-log i { font-size: 2.2rem; display: block; margin-bottom: .7rem; color: #9ca3af; }

        /* ── Preview modal ── */
        #mailFrame { width: 100%; height: 480px; border: 1px solid #e3e9e5; border-radius: 10px; background: #fff; }
        .pv-meta { font-size: .86rem; color: #374151; }
        .pv-meta dt { color: #6b7280; font-weight: 500; }
        .pv-meta dd { margin-bottom: .3rem; overflow-wrap: anywhere; }

        .help-list li { margin-bottom: .45rem; }
        @media (max-width: 575.98px) {
            .page-header { padding: 1.2rem 1.1rem; }
            #mailFrame { height: 380px; }
        }
    </style>
</head>
<body>

<?php include '../includes/sidebar.php'; ?>

<!-- ── Main Content ─────────────────────────────────────────── -->
<main class="main-content p-4">

    <!-- Page header -->
    <div class="page-header">
        <h1><i class="fas fa-bell me-2" aria-hidden="true"></i>Notifications</h1>
        <p>See what drivers were e-mailed, send a test message and send tomorrow's reminders.</p>
    </div>

    <?php showFlash(); ?>

    <!-- ── Status ─────────────────────────────────────────────── -->
    <div class="n-card mb-4">
        <div class="n-card-head d-flex align-items-center justify-content-between flex-wrap gap-2">
            <span><i class="fas fa-envelope-circle-check" aria-hidden="true"></i>E-mail status</span>
            <span class="mail-state <?php echo $enabled ? 'mail-on' : 'mail-off'; ?>">
                E-mail sending: <?php echo $enabled ? 'ON' : 'OFF'; ?>
            </span>
        </div>
        <div class="n-card-body">
            <div class="kv mb-3">
                <div><span class="k">SMTP server</span><strong><?php echo htmlspecialchars((string)$cfg['host'] . ':' . (int)$cfg['port']); ?></strong></div>
                <div><span class="k">From</span><strong><?php
                    echo $cfg['from_email'] !== ''
                        ? htmlspecialchars(trim((string)$cfg['from_name'] . ' <' . $cfg['from_email'] . '>'))
                        : '<span class="text-muted fw-normal">not set</span>';
                ?></strong></div>
            </div>

            <?php if ($redirects): ?>
            <div class="testmode" role="note">
                <i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
                <div>
                    <strong>Test mode:</strong> every e-mail is delivered to:
                    <strong><?php echo htmlspecialchars(implode(', ', $redirects)); ?></strong>
                    (not to the real drivers).
                </div>
            </div>
            <?php endif; ?>

            <?php if (!$enabled): ?>
            <div class="howto <?php echo $redirects ? 'mt-3' : ''; ?>">
                E-mail sending is switched off, so messages are only saved in the log below. To turn it on:
                <code>Copy config/mail.sample.php to config/mail.php, fill in your Gmail address and App Password, set 'enabled' =&gt; true</code>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Actions ────────────────────────────────────────────── -->
    <div class="row g-3 mb-4">
        <div class="col-lg-7">
            <div class="n-card h-100">
                <div class="n-card-head"><i class="fas fa-paper-plane" aria-hidden="true"></i>Send a test e-mail</div>
                <div class="n-card-body">
                    <p class="text-muted small mb-3">Check that your settings work before drivers are e-mailed.</p>
                    <form method="post" action="<?php echo SITE_URL; ?>/admin/notifications.php" class="row g-2 align-items-end">
                        <input type="hidden" name="action" value="test">
                        <div class="col-sm">
                            <label for="testEmail" class="form-label fw-semibold small mb-1">Send the test to</label>
                            <input type="email" class="form-control" id="testEmail" name="test_email" required
                                   placeholder="name@example.com"
                                   value="<?php echo htmlspecialchars($defaultTo); ?>">
                        </div>
                        <div class="col-sm-auto">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-paper-plane me-1" aria-hidden="true"></i> Send test
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="n-card h-100">
                <div class="n-card-head"><i class="fas fa-clock-rotate-left" aria-hidden="true"></i>Tomorrow's reminders</div>
                <div class="n-card-body">
                    <p class="text-muted small mb-3">
                        Reminders go out automatically each afternoon if the scheduled task is set up.
                        You can also send them now.
                    </p>
                    <form method="post" action="<?php echo SITE_URL; ?>/admin/notifications.php">
                        <input type="hidden" name="action" value="reminders">
                        <button type="submit" class="btn btn-warning fw-semibold"
                                data-confirm="Every driver with a task tomorrow will get one reminder e-mail. Drivers who already got one are skipped."
                                data-confirm-title="Send reminders?"
                                data-confirm-button="Send reminders">
                            <i class="fas fa-bell me-1" aria-hidden="true"></i> Send tomorrow's reminders now
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Summary cards ──────────────────────────────────────── -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="card stat-card h-100 p-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon bg-success bg-opacity-10 text-success"><i class="fas fa-circle-check" aria-hidden="true"></i></div>
                    <div><div class="stat-value text-success"><?php echo $totals['sent']; ?></div><div class="stat-label">Sent</div></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card stat-card h-100 p-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon bg-secondary bg-opacity-10 text-secondary"><i class="fas fa-forward" aria-hidden="true"></i></div>
                    <div><div class="stat-value text-secondary"><?php echo $totals['skipped']; ?></div><div class="stat-label">Skipped</div></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card stat-card h-100 p-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon bg-danger bg-opacity-10 text-danger"><i class="fas fa-circle-xmark" aria-hidden="true"></i></div>
                    <div><div class="stat-value text-danger"><?php echo $totals['failed']; ?></div><div class="stat-label">Failed</div></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card stat-card h-100 p-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon bg-primary bg-opacity-10 text-primary"><i class="fas fa-clock" aria-hidden="true"></i></div>
                    <div>
                        <div class="stat-value sm text-dark"><?php echo $lastAt ? htmlspecialchars(date('d M Y', strtotime($lastAt))) : '&mdash;'; ?></div>
                        <div class="stat-label"><?php echo $lastAt ? htmlspecialchars(date('g:i a', strtotime($lastAt))) . ' · ' : ''; ?>Last e-mail</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── E-mail log ─────────────────────────────────────────── -->
    <div class="n-card mb-4">
        <div class="n-card-head d-flex align-items-center justify-content-between flex-wrap gap-2">
            <span><i class="fas fa-list-check" aria-hidden="true"></i>E-mail log</span>
            <?php if ($logTotal > count($logRows)): ?>
                <span class="small text-muted fw-normal">Showing the latest <?php echo count($logRows); ?> of <?php echo $logTotal; ?></span>
            <?php endif; ?>
        </div>
        <div class="n-card-body">
        <?php if (!$logRows): ?>
            <div class="empty-log">
                <i class="fas fa-inbox" aria-hidden="true"></i>
                <div class="fw-semibold text-dark mb-1">No e-mails yet</div>
                Every e-mail the system sends to a driver (or tries to send) will be listed here,
                even while sending is switched off. Try the test e-mail above.
            </div>
        <?php else: ?>
            <div class="d-flex flex-wrap gap-2 mb-3" id="chips">
                <button type="button" class="vchip active" data-status="all">All<span class="n"><?php echo $counts['all']; ?></span></button>
                <button type="button" class="vchip" data-status="sent">Sent<span class="n"><?php echo $counts['sent']; ?></span></button>
                <button type="button" class="vchip" data-status="skipped">Skipped<span class="n"><?php echo $counts['skipped']; ?></span></button>
                <button type="button" class="vchip" data-status="failed">Failed<span class="n"><?php echo $counts['failed']; ?></span></button>
            </div>

            <div class="table-responsive">
                <table id="logTable" class="table table-hover align-middle mb-0" style="width:100%">
                    <thead>
                        <tr>
                            <th scope="col">When</th>
                            <th scope="col">Driver</th>
                            <th scope="col">To</th>
                            <th scope="col">Type</th>
                            <th scope="col">Subject</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="text-center">Details</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($logRows as $r):
                        [$kindLabel, $kindClass] = emailKindInfo((string)$r['kind']);
                        $ts      = strtotime($r['created_at']);
                        $status  = (string)$r['status'];
                        $errMsg  = (string)($r['error_message'] ?? '');
                        $statusLabel = ucfirst($status);
                    ?>
                        <tr data-status="<?php echo htmlspecialchars($status); ?>">
                            <td class="text-nowrap small" data-order="<?php echo (int)$ts; ?>">
                                <div class="fw-semibold"><?php echo htmlspecialchars(date('d M Y', $ts)); ?></div>
                                <div class="text-muted"><?php echo htmlspecialchars(date('g:i a', $ts)); ?></div>
                            </td>
                            <td class="small fw-semibold">
                                <?php echo $r['driver_name'] !== null && $r['driver_name'] !== ''
                                    ? htmlspecialchars($r['driver_name'])
                                    : '<span class="text-muted fw-normal">&mdash;</span>'; ?>
                            </td>
                            <td class="small" style="overflow-wrap:anywhere;">
                                <?php echo ($r['to_email'] ?? '') !== ''
                                    ? htmlspecialchars($r['to_email'])
                                    : '<span class="text-muted">no address</span>'; ?>
                                <?php if (!empty($r['delivered_to'])): ?>
                                    <div class="text-muted" style="font-size:.74rem;">
                                        <i class="fas fa-share me-1" aria-hidden="true"></i>redirected to <?php echo htmlspecialchars($r['delivered_to']); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><span class="pill <?php echo $kindClass; ?>"><?php echo htmlspecialchars($kindLabel); ?></span></td>
                            <td class="small subj"><?php echo htmlspecialchars($r['subject']); ?></td>
                            <td>
                                <span class="pill s-<?php echo htmlspecialchars($status); ?>"
                                      <?php if ($status === 'failed' && $errMsg !== ''): ?>title="<?php echo htmlspecialchars($errMsg, ENT_QUOTES); ?>"<?php endif; ?>>
                                    <?php echo htmlspecialchars($statusLabel); ?>
                                </span>
                                <?php if ($status === 'failed' && $errMsg !== ''): ?>
                                    <div class="err-text"><?php echo htmlspecialchars($errMsg); ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <button type="button" class="btn btn-outline-primary btn-sm js-view"
                                        data-id="<?php echo (int)$r['log_id']; ?>"
                                        aria-label="View e-mail: <?php echo htmlspecialchars($r['subject'], ENT_QUOTES); ?>">
                                    <i class="fas fa-eye me-1" aria-hidden="true"></i> View
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        </div>
    </div>

    <!-- ── Help ───────────────────────────────────────────────── -->
    <div class="n-card mb-2">
        <div class="n-card-head"><i class="fas fa-circle-info" aria-hidden="true"></i>What drivers are e-mailed about</div>
        <div class="n-card-body">
            <ul class="help-list small text-secondary mb-0 ps-3">
                <li><strong class="text-dark">New task:</strong> when a driver is assigned a task. If several tasks are assigned at once (for example by auto-assign), the driver gets ONE e-mail listing all of them.</li>
                <li><strong class="text-dark">Changed details:</strong> when the date, time, vehicle, place, purpose or officer of a task changes. The changed rows are highlighted.</li>
                <li><strong class="text-dark">Cancelled or removed:</strong> when a task is cancelled or deleted, or moved to another driver (the first driver is told they were taken off).</li>
                <li><strong class="text-dark">Reminder:</strong> one e-mail the day before, listing the driver's tasks for tomorrow. A driver is never reminded twice for the same day.</li>
            </ul>
        </div>
    </div>

</main><!-- /.main-content -->

<!-- ================================================================
     E-MAIL PREVIEW MODAL
     ================================================================ -->
<div class="modal fade" id="viewModal" tabindex="-1" aria-labelledby="viewModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable">
        <div class="modal-content rounded-3">
            <div class="modal-header text-white" style="background: linear-gradient(135deg,#0b5d3b 0%,#15804f 100%);">
                <h5 class="modal-title fw-bold" id="viewModalLabel">
                    <i class="fas fa-envelope-open-text me-2" aria-hidden="true"></i>E-mail preview
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <dl class="pv-meta row mb-2" id="pvMeta"></dl>
                <div class="alert alert-danger py-2 small d-none" id="pvError" role="alert"></div>
                <div class="text-muted small mb-2">This is exactly what the driver received (or would have received).</div>
                <iframe id="mailFrame" title="E-mail content" sandbox srcdoc=""></iframe>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
const SITE_URL = '<?php echo SITE_URL; ?>';
const EMAIL_VIEW = <?php echo $viewJson; ?>;
</script>

<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<!-- Bootstrap 5 JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- DataTables -->
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<!-- Custom JS -->
<script src="<?php echo SITE_URL; ?>/assets/js/main.js"></script>

<script>
(function () {
    'use strict';

    function escHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    // ── Log table + status chips ──────────────────────────────
    var table = document.getElementById('logTable');
    if (table) {
        var activeStatus = 'all';

        $.fn.dataTable.ext.search.push(function (settings, data, dataIndex) {
            if (settings.nTable !== table || activeStatus === 'all') return true;
            var row = settings.aoData[dataIndex].nTr;
            return !!row && row.getAttribute('data-status') === activeStatus;
        });

        var dt = $(table).DataTable({
            order: [[0, 'desc']],
            pageLength: 25,
            columnDefs: [
                { orderable: false, targets: 6 },
                { searchable: false, targets: 6 }
            ],
            language: {
                search:      'Search e-mails:',
                lengthMenu:  'Show _MENU_ e-mails',
                info:        'Showing _START_ to _END_ of _TOTAL_ e-mails',
                infoEmpty:   'No e-mails found',
                zeroRecords: 'No e-mails match your search or filter'
            }
        });

        var chips = document.querySelectorAll('#chips .vchip');
        chips.forEach(function (chip) {
            chip.addEventListener('click', function () {
                chips.forEach(function (c) { c.classList.remove('active'); });
                chip.classList.add('active');
                activeStatus = chip.getAttribute('data-status');
                dt.draw();
            });
        });
    }

    // ── "View" modal ──────────────────────────────────────────
    var modalEl = document.getElementById('viewModal');
    var frame   = document.getElementById('mailFrame');
    var metaEl  = document.getElementById('pvMeta');
    var errEl   = document.getElementById('pvError');

    function metaRow(label, value) {
        if (!value) return '';
        return '<dt class="col-sm-3">' + escHtml(label) + '</dt><dd class="col-sm-9">' + escHtml(value) + '</dd>';
    }

    function openView(id) {
        var m = EMAIL_VIEW[id];
        if (!m) return;

        var to = m.to;
        if (m.driver) to = m.driver + (m.to ? ' <' + m.to + '>' : '');

        metaEl.innerHTML =
            metaRow('Subject', m.subject) +
            metaRow('To', to) +
            metaRow('Delivered to', m.sentTo ? m.sentTo + ' (test redirect)' : '') +
            metaRow('Type', m.kind) +
            metaRow('Status', m.status.charAt(0).toUpperCase() + m.status.slice(1)) +
            metaRow('Date', m.when);

        if (m.error) {
            errEl.innerHTML = '<strong>' + (m.status === 'failed' ? 'Error: ' : 'Note: ') + '</strong>' + escHtml(m.error);
            errEl.classList.toggle('alert-danger', m.status === 'failed');
            errEl.classList.toggle('alert-secondary', m.status !== 'failed');
            errEl.classList.remove('d-none');
        } else {
            errEl.classList.add('d-none');
        }

        frame.srcdoc = m.body || '<p style="font-family:sans-serif;color:#6b7280;padding:16px;">No message content was saved for this e-mail.</p>';
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('.js-view') : null;
        if (btn) openView(btn.getAttribute('data-id'));
    });

    modalEl.addEventListener('hidden.bs.modal', function () { frame.srcdoc = ''; });
})();
</script>

</body>
</html>
