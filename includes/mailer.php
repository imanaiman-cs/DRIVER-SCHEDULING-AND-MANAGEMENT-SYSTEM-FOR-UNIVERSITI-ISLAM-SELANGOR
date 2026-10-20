<?php
// ============================================================
// UIS Driver Scheduling and Management System
// includes/mailer.php  –  sends the e-mail copy of every notification (PHPMailer, SMTP)
//
// Settings live in config/mail.php (copy of config/mail.sample.php).
// While sending is switched off, or a user has no valid address, or the SMTP server fails, the message is
// still written to the e-mail log (table email_log) and the in-system notification is kept: nothing is lost.
// ============================================================

require_once __DIR__ . '/phpmailer/Exception.php';
require_once __DIR__ . '/phpmailer/PHPMailer.php';
require_once __DIR__ . '/phpmailer/SMTP.php';

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
    $user_id      = isset($row['user_id']) ? (int)$row['user_id'] : null;

    $stmt = $conn->prepare(
        "INSERT INTO email_log
            (driver_id, to_email, delivered_to, subject, kind, ref_date, schedule_ids, status, error_message, body_html, user_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    if (!$stmt) {
        error_log('email_log insert failed: ' . $conn->error);
        return;
    }
    $stmt->bind_param('isssssssssi', $driver_id, $to_email, $delivered_to, $subject, $kind, $ref_date, $schedule_ids, $status, $error, $body, $user_id);
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
        'user_id'      => $meta['user_id'] ?? null,
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
