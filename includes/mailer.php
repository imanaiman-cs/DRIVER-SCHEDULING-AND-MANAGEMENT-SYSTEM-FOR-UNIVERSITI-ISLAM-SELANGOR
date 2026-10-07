<?php
// ============================================================
// UIS Driver Scheduling and Management System
// includes/mailer.php  –  E-mail notifications to drivers
//
// Settings live in config/mail.php (copy of config/mail.sample.php).
// While sending is switched off, or a driver has no valid address,
// the message is still written to the e-mail log so nothing is lost.
// ============================================================

require_once __DIR__ . '/phpmailer/Exception.php';
require_once __DIR__ . '/phpmailer/PHPMailer.php';
require_once __DIR__ . '/phpmailer/SMTP.php';
require_once __DIR__ . '/task_sheet.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

// ── Settings ─────────────────────────────────────────────────

/**
 * Loads config/mail.php over safe defaults (sending off).
 *
 * @return array<string, mixed>
 */
function mailConfig(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $defaults = [
        'enabled'     => false,
        'host'        => 'smtp.gmail.com',
        'port'        => 587,
        'encryption'  => 'tls',
        'auth'        => true,
        'username'    => '',
        'password'    => '',
        'from_email'  => '',
        'from_name'   => 'UIS Transport Unit',
        'reply_to'    => '',
        'redirect_to' => [],
    ];

    $file   = __DIR__ . '/../config/mail.php';
    $loaded = is_file($file) ? include $file : [];
    $config = array_merge($defaults, is_array($loaded) ? $loaded : []);

    if (is_string($config['redirect_to'])) {
        $config['redirect_to'] = preg_split('/[;,\s]+/', $config['redirect_to'], -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
    $config['redirect_to'] = array_values(array_filter(
        (array)$config['redirect_to'],
        fn($a) => is_string($a) && filter_var(trim($a), FILTER_VALIDATE_EMAIL)
    ));
    if ($config['from_email'] === '') {
        $config['from_email'] = (string)$config['username'];
    }

    return $config;
}

/**
 * True when real sending is configured and switched on.
 */
function mailEnabled(): bool
{
    $c = mailConfig();
    if ($c['enabled'] !== true || $c['host'] === '' || !filter_var($c['from_email'], FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    return $c['auth'] !== true || ($c['username'] !== '' && $c['password'] !== '');
}

// ── Sending and logging ──────────────────────────────────────

/**
 * Writes one row to email_log.
 *
 * @param array<string, mixed> $row
 */
function logEmail(mysqli $conn, array $row): void
{
    $driver_id    = isset($row['driver_id']) ? (int)$row['driver_id'] : null;
    $to_email     = $row['to_email']     ?? null;
    $delivered_to = $row['delivered_to'] ?? null;
    $subject      = mb_substr((string)($row['subject'] ?? ''), 0, 255);
    $kind         = (string)($row['kind'] ?? 'notice');
    $ref_date     = $row['ref_date']     ?? null;
    $schedule_ids = $row['schedule_ids'] ?? null;
    $status       = (string)$row['status'];
    $error        = isset($row['error_message']) ? mb_substr((string)$row['error_message'], 0, 500) : null;
    $body         = $row['body_html']    ?? null;

    $stmt = $conn->prepare(
        "INSERT INTO email_log
            (driver_id, to_email, delivered_to, subject, kind, ref_date, schedule_ids, status, error_message, body_html)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    if (!$stmt) {
        error_log('email_log insert failed: ' . $conn->error);
        return;
    }
    $stmt->bind_param('isssssssss', $driver_id, $to_email, $delivered_to, $subject, $kind, $ref_date, $schedule_ids, $status, $error, $body);
    $stmt->execute();
    $stmt->close();
}

/**
 * Sends (or, when switched off, only logs) one e-mail.
 *
 * @param array<string, mixed> $meta  driver_id, kind, ref_date, schedule_ids (int[])
 *
 * @return array{status: string, message: string}  status: sent | skipped | failed
 */
function sendMail(mysqli $conn, ?string $to, string $subject, string $html, string $text, array $meta = []): array
{
    $to = $to !== null ? trim($to) : null;
    $log = [
        'driver_id'    => $meta['driver_id'] ?? null,
        'to_email'     => $to,
        'subject'      => $subject,
        'kind'         => $meta['kind'] ?? 'notice',
        'ref_date'     => $meta['ref_date'] ?? null,
        'schedule_ids' => isset($meta['schedule_ids']) ? implode(',', array_map('intval', (array)$meta['schedule_ids'])) : null,
        'body_html'    => $html,
    ];

    if ($to === null || $to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $log['status']        = 'skipped';
        $log['error_message'] = 'No valid e-mail address is saved for this driver.';
        logEmail($conn, $log);
        return ['status' => 'skipped', 'message' => $log['error_message']];
    }

    if (!mailEnabled()) {
        $log['status']        = 'skipped';
        $log['error_message'] = 'E-mail sending is switched off (see config/mail.php). The message was saved in the log only.';
        logEmail($conn, $log);
        return ['status' => 'skipped', 'message' => $log['error_message']];
    }

    $c          = mailConfig();
    $recipients = $c['redirect_to'] ?: [$to];
    if ($c['redirect_to']) {
        $subject             = '[TEST for ' . $to . '] ' . $subject;
        $log['subject']      = mb_substr($subject, 0, 255);
        $log['delivered_to'] = implode(', ', $recipients);
    }

    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = (string)$c['host'];
        $mail->Port       = (int)$c['port'];
        $mail->SMTPAuth   = $c['auth'] === true;
        $mail->Username   = (string)$c['username'];
        $mail->Password   = (string)$c['password'];
        $mail->SMTPSecure = $c['encryption'] === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS
                          : ($c['encryption'] === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : '');
        $mail->SMTPAutoTLS = $c['encryption'] === 'tls';
        $mail->Timeout    = 15;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom((string)$c['from_email'], (string)$c['from_name']);
        if ($c['reply_to'] !== '' && filter_var($c['reply_to'], FILTER_VALIDATE_EMAIL)) {
            $mail->addReplyTo((string)$c['reply_to']);
        }
        foreach ($recipients as $r) {
            $mail->addAddress($r);
        }

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = $text;
        $mail->send();

        $log['status'] = 'sent';
        logEmail($conn, $log);
        return ['status' => 'sent', 'message' => 'Sent to ' . implode(', ', $recipients) . '.'];
    } catch (MailException $e) {
        $log['status']        = 'failed';
        $log['error_message'] = $e->getMessage();
        logEmail($conn, $log);
        error_log('Mail failed: ' . $e->getMessage());
        return ['status' => 'failed', 'message' => $e->getMessage()];
    } catch (Throwable $e) {
        $log['status']        = 'failed';
        $log['error_message'] = $e->getMessage();
        logEmail($conn, $log);
        error_log('Mail failed: ' . $e->getMessage());
        return ['status' => 'failed', 'message' => $e->getMessage()];
    }
}

/**
 * One-line summary of several send results, for flash messages.
 *
 * @param array<int, array{status: string, message: string}> $results
 */
function emailSummary(array $results): string
{
    if (!$results) {
        return '';
    }
    $count = ['sent' => 0, 'skipped' => 0, 'failed' => 0];
    foreach ($results as $r) {
        $count[$r['status']] = ($count[$r['status']] ?? 0) + 1;
    }
    $n = count($results);
    if ($count['failed'] > 0) {
        return $count['failed'] . ' of ' . $n . ' driver e-mail(s) could not be sent — see Notifications for details.';
    }
    if ($count['sent'] === 0 && !mailEnabled()) {
        return $n . ' driver e-mail(s) saved in the log (e-mail sending is switched off).';
    }
    if ($count['skipped'] > 0) {
        return $count['sent'] . ' driver e-mail(s) sent, ' . $count['skipped'] . ' skipped (no e-mail address).';
    }
    return $count['sent'] . ' driver e-mail(s) sent.';
}

// ── Templates ────────────────────────────────────────────────

/** Row labels, in the order of the official Tugasan Pemandu sheet. */
function emailTaskRows(array $t): array
{
    $plate   = trim((string)($t['vehicle_plate'] ?? ''));
    $brand   = trim((string)($t['vehicle_brand'] ?? ''));
    $vehicle = trim(vehicleTypeMalay($t['vehicle_type'] ?? null) . ' ' . $brand . ($plate !== '' ? ' - ' . $plate : ''));
    $time    = trim(formatMalayTime($t['start_time'] ?? '') . ' - ' . formatMalayTime($t['end_time'] ?? ''), ' -');
    $date    = !empty($t['trip_date']) ? date('j.n.Y', strtotime($t['trip_date'])) : '';

    return [
        'Tarikh'          => $date,
        'Masa'            => $time,
        'Kenderaan'       => $vehicle,
        'Lokasi'          => (string)($t['destination'] ?? ''),
        'Tempat Menunggu' => (string)($t['waiting_place'] ?? ''),
        'Tujuan'          => (string)($t['purpose'] ?? ''),
        'Pegawai'         => (string)($t['officer_name'] ?? ''),
        'No. Tel'         => (string)($t['officer_phone'] ?? ''),
    ];
}

function emailEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/**
 * HTML card for one task. $changed lists row labels to highlight.
 *
 * @param string[] $changed
 */
function emailTaskCardHtml(array $t, array $changed = [], string $accent = '#0b5d3b'): string
{
    $rows = '';
    foreach (emailTaskRows($t) as $label => $value) {
        $display = trim($value) === '' ? '—' : emailEscape(mb_strtoupper($value, 'UTF-8'));
        if ($label === 'No. Tel' && trim($value) !== '') {
            $digits  = preg_replace('/[^0-9+]/', '', $value);
            $display = '<a href="tel:' . emailEscape($digits) . '" style="color:#0b5d3b;text-decoration:none;">' . emailEscape($value) . '</a>';
        }
        $hot  = in_array($label, $changed, true);
        $bg   = $hot ? 'background:#fef3c7;' : '';
        $note = $hot ? ' <span style="color:#92400e;font-size:11px;font-weight:700;">&nbsp;UBAH</span>' : '';
        $rows .= '<tr>'
            . '<td style="padding:6px 10px;width:130px;color:#6b7280;font-size:13px;' . $bg . '">' . emailEscape($label) . '</td>'
            . '<td style="padding:6px 6px;color:#6b7280;font-size:13px;width:10px;' . $bg . '">:</td>'
            . '<td style="padding:6px 10px;color:#1a2035;font-size:14px;font-weight:600;' . $bg . '">' . $display . $note . '</td>'
            . '</tr>';
    }
    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" '
        . 'style="border:1px solid #dfe7e2;border-left:4px solid ' . $accent . ';border-radius:8px;margin:0 0 14px;background:#ffffff;">'
        . $rows . '</table>';
}

/**
 * Plain-text version of one task.
 *
 * @param string[] $changed
 */
function emailTaskCardText(array $t, array $changed = []): string
{
    $out = '';
    foreach (emailTaskRows($t) as $label => $value) {
        $v    = trim($value) === '' ? '-' : mb_strtoupper($value, 'UTF-8');
        $out .= str_pad($label, 16) . ': ' . $v . (in_array($label, $changed, true) ? '   <-- UBAH' : '') . "\n";
    }
    return $out;
}

/**
 * Builds subject, HTML and text for a driver e-mail.
 *
 * @param string                         $kind   assigned | updated | cancelled | removed | reminder
 * @param array<string, mixed>           $driver needs 'name'
 * @param array<int, array<string,mixed>> $tasks  rows from fetchTasksForEmail()
 * @param array<int, string[]>           $changes schedule_id => changed row labels (updated only)
 *
 * @return array{subject: string, html: string, text: string}
 */
function buildDriverEmail(string $kind, array $driver, array $tasks, array $changes = []): array
{
    $n     = count($tasks);
    $first = $tasks ? reset($tasks) : [];
    $date1 = !empty($first['trip_date']) ? date('j.n.Y', strtotime($first['trip_date'])) : '';

    $intro = [
        'assigned'  => $n === 1 ? 'You have been assigned a new task. The details are below.'
                                : 'You have been assigned ' . $n . ' new tasks. The details are below.',
        'updated'   => 'A task assigned to you has been changed. The changed details are highlighted.',
        'cancelled' => 'The task below has been cancelled. You do not need to do anything.',
        'removed'   => 'You have been taken off the task below. It has been given to another driver.',
        'reminder'  => $n === 1 ? 'Reminder: you have a task tomorrow.' : 'Reminder: you have ' . $n . ' tasks tomorrow.',
        'test'      => 'This is a test message from the UIS Driver Scheduling and Management System.',
    ][$kind] ?? '';

    $subject = [
        'assigned'  => $n === 1 ? 'New driver assignment — ' . $date1 : $n . ' new driver assignments',
        'updated'   => 'Task updated — ' . $date1,
        'cancelled' => 'Task cancelled — ' . $date1,
        'removed'   => 'Task reassigned — ' . $date1,
        'reminder'  => $n === 1 ? 'Reminder: task tomorrow (' . $date1 . ')' : 'Reminder: ' . $n . ' tasks tomorrow (' . $date1 . ')',
        'test'      => 'UIS e-mail test',
    ][$kind] ?? 'Driver notification';

    $accent = in_array($kind, ['cancelled', 'removed'], true) ? '#b91c1c' : ($kind === 'updated' ? '#b45309' : '#0b5d3b');
    $name   = trim((string)($driver['name'] ?? 'Driver'));
    $link   = rtrim(SITE_URL, '/') . '/driver/schedules.php';

    $cards = '';
    $plain = '';
    foreach ($tasks as $t) {
        $ch     = $changes[(int)($t['schedule_id'] ?? 0)] ?? [];
        $cards .= emailTaskCardHtml($t, $ch, $accent);
        $plain .= emailTaskCardText($t, $ch) . "\n";
    }

    $notice = 'Sekiranya Pegawai lewat daripada masa ditetapkan, Pemandu adalah dipohon untuk menghubungi Pegawai berkaitan untuk tindakan selanjutnya.';
    $showTasks = $tasks && $kind !== 'test';

    $html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
        . '<body style="margin:0;padding:0;background:#f3f5f4;font-family:Segoe UI,Arial,sans-serif;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f5f4;padding:24px 12px;"><tr><td align="center">'
        . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden;">'
        . '<tr><td style="background:#0b5d3b;padding:20px 24px;">'
        . '<div style="color:#ffffff;font-size:18px;font-weight:700;">Universiti Islam Selangor</div>'
        . '<div style="color:#c9e5d6;font-size:13px;margin-top:2px;">Unit Pengangkutan · Tugasan Pemandu</div></td></tr>'
        . '<tr><td style="padding:24px;">'
        . '<p style="margin:0 0 6px;color:#1a2035;font-size:15px;">Assalamualaikum <strong>' . emailEscape($name) . '</strong>,</p>'
        . '<p style="margin:0 0 18px;color:#374151;font-size:14px;line-height:1.55;">' . emailEscape($intro) . '</p>'
        . ($showTasks ? $cards : '')
        . ($showTasks ? '<p style="margin:4px 0 18px;color:#6b7280;font-size:12.5px;line-height:1.5;font-style:italic;">' . emailEscape($notice) . '</p>' : '')
        . '<p style="margin:0 0 6px;"><a href="' . emailEscape($link) . '" style="display:inline-block;background:#0b5d3b;color:#ffffff;text-decoration:none;font-weight:700;font-size:14px;padding:10px 20px;border-radius:8px;">View my schedule</a></p>'
        . '</td></tr>'
        . '<tr><td style="background:#f8faf9;border-top:1px solid #edf1ee;padding:14px 24px;color:#6b7280;font-size:12px;">'
        . 'This is an automated message from the UIS Driver Scheduling and Management System. Please do not reply to this e-mail.</td></tr>'
        . '</table></td></tr></table></body></html>';

    $text = "Assalamualaikum {$name},\n\n{$intro}\n\n"
        . ($showTasks ? $plain . $notice . "\n\n" : '')
        . "View my schedule: {$link}\n\n"
        . "-- UIS Driver Scheduling and Management System (automated message)\n";

    return ['subject' => $subject, 'html' => $html, 'text' => $text];
}

// ── Data helpers ─────────────────────────────────────────────

/**
 * Schedule rows with driver and vehicle details, keyed by schedule_id.
 *
 * @param int[] $ids
 *
 * @return array<int, array<string, mixed>>
 */
function fetchTasksForEmail(mysqli $conn, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
    if (!$ids) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare(
        "SELECT s.*, d.name AS driver_name, d.email AS driver_email,
                v.plate_number AS vehicle_plate, v.brand AS vehicle_brand,
                v.model AS vehicle_model, v.vehicle_type AS vehicle_type
         FROM schedules s
         LEFT JOIN drivers  d ON d.driver_id  = s.driver_id
         LEFT JOIN vehicles v ON v.vehicle_id = s.vehicle_id
         WHERE s.schedule_id IN ($placeholders)
         ORDER BY s.trip_date, s.start_time"
    );
    $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
    $stmt->execute();
    $out = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $out[(int)$row['schedule_id']] = $row;
    }
    $stmt->close();
    return $out;
}

/** Snapshot of one schedule, taken before it is changed or deleted. */
function snapshotSchedule(mysqli $conn, int $schedule_id): ?array
{
    $rows = fetchTasksForEmail($conn, [$schedule_id]);
    return $rows[$schedule_id] ?? null;
}

/**
 * Sends one e-mail per driver covering the given tasks.
 *
 * @param array<int, array<string, mixed>> $tasks   rows from fetchTasksForEmail()
 * @param array<int, string[]>             $changes
 *
 * @return array<int, array{status: string, message: string}>
 */
function mailTasksToDrivers(mysqli $conn, string $kind, array $tasks, array $changes = [], ?string $ref_date = null): array
{
    $byDriver = [];
    foreach ($tasks as $t) {
        if (!empty($t['driver_id'])) {
            $byDriver[(int)$t['driver_id']][] = $t;
        }
    }

    $results = [];
    foreach ($byDriver as $driver_id => $driverTasks) {
        $driver = ['name' => $driverTasks[0]['driver_name'] ?? 'Driver'];
        $mail   = buildDriverEmail($kind, $driver, $driverTasks, $changes);
        $results[] = sendMail($conn, $driverTasks[0]['driver_email'] ?? null, $mail['subject'], $mail['html'], $mail['text'], [
            'driver_id'    => $driver_id,
            'kind'         => $kind,
            'ref_date'     => $ref_date ?? ($driverTasks[0]['trip_date'] ?? null),
            'schedule_ids' => array_column($driverTasks, 'schedule_id'),
        ]);
    }
    return $results;
}

// ── Events ───────────────────────────────────────────────────

/**
 * Tells each driver about newly assigned tasks (one e-mail per driver).
 *
 * @param int[] $schedule_ids
 */
function notifyDriversAssigned(mysqli $conn, array $schedule_ids): array
{
    $today = date('Y-m-d');
    $tasks = array_filter(
        fetchTasksForEmail($conn, $schedule_ids),
        fn($t) => !empty($t['driver_id']) && $t['trip_date'] >= $today && !in_array($t['status'], ['cancelled', 'completed'], true)
    );
    return mailTasksToDrivers($conn, 'assigned', $tasks);
}

/**
 * After a schedule was edited: tells the right driver(s) what changed.
 *
 * @param array<string, mixed>|null $before snapshotSchedule() taken before the update
 */
function notifyScheduleChanged(mysqli $conn, ?array $before, int $schedule_id): array
{
    $after = snapshotSchedule($conn, $schedule_id);
    if (!$after) {
        return [];
    }
    $today = date('Y-m-d');
    $results = [];

    $oldDriver = (int)($before['driver_id'] ?? 0);
    $newDriver = (int)($after['driver_id'] ?? 0);
    $wasCancelled = ($before['status'] ?? '') === 'cancelled';
    $isCancelled  = $after['status'] === 'cancelled';
    $isDone       = $after['status'] === 'completed';
    $isPast       = $after['trip_date'] < $today;

    if ($before === null) {
        return ($newDriver && !$isPast && !$isCancelled) ? mailTasksToDrivers($conn, 'assigned', [$schedule_id => $after]) : [];
    }

    if ($isCancelled && !$wasCancelled) {
        if ($oldDriver && ($before['trip_date'] >= $today)) {
            $results = array_merge($results, mailTasksToDrivers($conn, 'cancelled', [$schedule_id => $before]));
        }
        return $results;
    }
    if ($isCancelled || $isDone) {
        return [];
    }

    if ($oldDriver !== $newDriver || $wasCancelled) {
        if ($oldDriver && $oldDriver !== $newDriver && $before['trip_date'] >= $today && !$wasCancelled) {
            $results = array_merge($results, mailTasksToDrivers($conn, 'removed', [$schedule_id => $before]));
        }
        if ($newDriver && !$isPast) {
            $results = array_merge($results, mailTasksToDrivers($conn, 'assigned', [$schedule_id => $after]));
        }
        return $results;
    }

    if (!$newDriver || $isPast) {
        return [];
    }

    $labels = [
        'trip_date'     => 'Tarikh',
        'start_time'    => 'Masa',
        'end_time'      => 'Masa',
        'vehicle_id'    => 'Kenderaan',
        'destination'   => 'Lokasi',
        'waiting_place' => 'Tempat Menunggu',
        'purpose'       => 'Tujuan',
        'officer_name'  => 'Pegawai',
        'officer_phone' => 'No. Tel',
    ];
    $changed = [];
    foreach ($labels as $field => $label) {
        $a = trim((string)($before[$field] ?? ''));
        $b = trim((string)($after[$field]  ?? ''));
        if ($a !== $b && !in_array($label, $changed, true)) {
            $changed[] = $label;
        }
    }
    if (!$changed) {
        return [];
    }
    return mailTasksToDrivers($conn, 'updated', [$schedule_id => $after], [$schedule_id => $changed]);
}

/**
 * After a schedule was deleted: tells its driver it is cancelled.
 *
 * @param array<string, mixed>|null $before snapshotSchedule() taken before the delete
 */
function notifyScheduleDeleted(mysqli $conn, ?array $before): array
{
    if (!$before || empty($before['driver_id']) || $before['trip_date'] < date('Y-m-d')
        || in_array($before['status'], ['cancelled', 'completed'], true)) {
        return [];
    }
    return mailTasksToDrivers($conn, 'cancelled', [(int)$before['schedule_id'] => $before]);
}

/**
 * Reminder e-mails for every driver with tasks on $date (default: tomorrow).
 * A driver who already received a reminder for that date is skipped unless $force.
 *
 * @return array<int, array{status: string, message: string}>
 */
function sendRemindersForDate(mysqli $conn, ?string $date = null, bool $force = false): array
{
    $date = $date ?: date('Y-m-d', strtotime('+1 day'));

    $stmt = $conn->prepare(
        "SELECT schedule_id FROM schedules
         WHERE trip_date = ? AND driver_id IS NOT NULL AND status IN ('pending','approved')"
    );
    $stmt->bind_param('s', $date);
    $stmt->execute();
    $ids = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'schedule_id');
    $stmt->close();

    $tasks = fetchTasksForEmail($conn, $ids);

    if (!$force) {
        $done = $conn->prepare("SELECT DISTINCT driver_id FROM email_log WHERE kind = 'reminder' AND ref_date = ? AND status = 'sent'");
        $done->bind_param('s', $date);
        $done->execute();
        $already = array_column($done->get_result()->fetch_all(MYSQLI_ASSOC), 'driver_id');
        $done->close();
        $tasks = array_filter($tasks, fn($t) => !in_array((string)$t['driver_id'], array_map('strval', $already), true));
    }

    return mailTasksToDrivers($conn, 'reminder', $tasks, [], $date);
}
