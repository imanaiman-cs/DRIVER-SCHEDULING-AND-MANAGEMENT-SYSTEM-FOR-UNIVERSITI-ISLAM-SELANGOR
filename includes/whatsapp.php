<?php
// ============================================================
// WhatsApp through an OpenWA server (https://github.com/rmyndharis/OpenWA): POST /api/sessions/{id}/messages/send-text
// An extra channel next to the in-system notification and the e-mail (Iman's decision, 16 Nov 2026; WhatsApp is not in the 14 Nov use cases).
// ONLY DRIVERS get WhatsApp (Iman, 16 Nov 2026): every message notifyUsers() sends to a driver (new trip confirmed, task cancelled,
// replaced, schedule change, internal/welfare task ...) also goes to WhatsApp. All other roles get the in-system notification and e-mail only.
// A failure never stops the workflow: it is written to whatsapp_log and the other channels still go out. E-mail stays the guaranteed channel.
// ============================================================

function whatsappConfig(): array
{
    static $c = null;
    if ($c === null) {
        $file = __DIR__ . '/../config/whatsapp.php';
        $c = is_file($file) ? (array)(include $file) : [];
        $c += ['enabled' => false, 'base_url' => '', 'api_key' => '', 'session_id' => '',
               'country_code' => '60', 'timeout' => 5, 'redirect_to' => ''];
    }
    return $c;
}

function whatsappEnabled(): bool
{
    $c = whatsappConfig();
    return !empty($c['enabled']) && $c['base_url'] !== '' && $c['session_id'] !== '';
}

/** Only drivers get WhatsApp; every other role uses e-mail (fixed rule, not a setting). */
function whatsappForRole(?string $role): bool
{
    return $role === 'driver';
}

/** "012-345 6789" -> "60123456789"; null when it cannot be a mobile number. */
function whatsappNumber(?string $phone, string $country = '60'): ?string
{
    $d = preg_replace('/\D+/', '', (string)$phone);
    if ($d === '') { return null; }
    if (strncmp($d, '00', 2) === 0) { $d = substr($d, 2); }
    elseif ($d[0] === '0') { $d = $country . substr($d, 1); }
    return (strlen($d) >= 9 && strlen($d) <= 15) ? $d : null;
}

function logWhatsapp(mysqli $conn, ?int $userId, ?string $number, string $message, string $status, ?string $error): void
{
    $stmt = $conn->prepare('INSERT INTO whatsapp_log (user_id, to_number, message, status, error_message) VALUES (?, ?, ?, ?, ?)');
    if (!$stmt) { error_log('whatsapp_log insert failed: ' . $conn->error); return; }
    $msg = mb_substr($message, 0, 1000);
    $err = $error !== null ? mb_substr($error, 0, 500) : null;
    $stmt->bind_param('issss', $userId, $number, $msg, $status, $err);
    $stmt->execute();
    $stmt->close();
}

/**
 * Sends one text message. Returns ['status' => sent|failed|skipped, 'message' => string].
 */
function sendWhatsApp(mysqli $conn, ?int $userId, ?string $phone, string $text): array
{
    $c = whatsappConfig();
    if (!whatsappEnabled()) {   // outside the SRS: while it is off nothing happens and nothing is logged
        return ['status' => 'skipped', 'message' => 'WhatsApp sending is switched off (see config/whatsapp.php).'];
    }
    $number = whatsappNumber($phone, (string)$c['country_code']);
    if ($number === null) {
        $err = 'No valid mobile number is saved for this user.';
        logWhatsapp($conn, $userId, null, $text, 'skipped', $err);
        return ['status' => 'skipped', 'message' => $err];
    }
    $target = preg_replace('/\D+/', '', (string)$c['redirect_to']) ?: $number;
    $body = json_encode(['chatId' => $target . '@c.us', 'text' => $text], JSON_UNESCAPED_UNICODE);
    $headers = "Content-Type: application/json\r\n";
    if ($c['api_key'] !== '') { $headers .= 'X-API-Key: ' . $c['api_key'] . "\r\n"; }
    $ctx = stream_context_create(['http' => [
        'method' => 'POST', 'header' => $headers, 'content' => $body,
        'timeout' => max(1, (int)$c['timeout']), 'ignore_errors' => true,
    ]]);
    $url = rtrim((string)$c['base_url'], '/') . '/api/sessions/' . rawurlencode((string)$c['session_id']) . '/messages/send-text';
    $resp = @file_get_contents($url, false, $ctx);
    $code = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) { $code = (int)$m[1]; }
    }
    if ($resp !== false && $code >= 200 && $code < 300) {
        logWhatsapp($conn, $userId, $number, $text, 'sent', null);
        return ['status' => 'sent', 'message' => 'sent'];
    }
    $err = $resp === false ? 'The OpenWA server could not be reached.' : 'OpenWA answered HTTP ' . $code . ': ' . mb_substr((string)$resp, 0, 200);
    logWhatsapp($conn, $userId, $number, $text, 'failed', $err);
    return ['status' => 'failed', 'message' => $err];
}
