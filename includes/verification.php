<?php
// ============================================================
// includes/verification.php  -  UC014 Send Verification Email (used by UC013 Register Account and UC015 Reset Password)
// A 6-digit code is sent to the UIS e-mail and expires after VERIFICATION_MINUTES. A new code replaces any earlier code of the same
// e-mail and purpose. A wrong code is counted: after VERIFICATION_MAX_TRIES wrong tries the code is dead and a new one is needed.
// The code is stored only as a hash. When e-mail sending is switched off (config/mail.php) the message is only written to email_log.
// ============================================================

const VERIFICATION_MINUTES   = 15;
const VERIFICATION_MAX_TRIES = 5;

/** UC013 exception 2.2: only an e-mail of the UIS domain (setting UIS_EMAIL_DOMAIN) is accepted. */
function isUisEmail(mysqli $conn, string $email): bool
{
    $domain = strtolower(trim((string)getSetting($conn, 'UIS_EMAIL_DOMAIN', 'uis.edu.my')));
    $email = strtolower(trim($email));
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false && $domain !== '' && substr($email, -strlen('@' . $domain)) === '@' . $domain;
}

/**
 * Creates a new code and e-mails it. Returns ['ok' => bool, 'message' => string].
 * UC014 exception 1.1: if the e-mail cannot be sent the code is removed and the user is asked to try again later.
 */
function sendVerificationCode(mysqli $conn, string $email, string $staffId, string $purpose): array
{
    $code = (string)random_int(100000, 999999);
    $hash = password_hash($code, PASSWORD_DEFAULT);
    $stmt = $conn->prepare('DELETE FROM verification_codes WHERE email = ? AND purpose = ?');   // a new code replaces any earlier one
    $stmt->bind_param('ss', $email, $purpose);
    $stmt->execute();
    $stmt->close();
    $expiry = date('Y-m-d H:i:s', time() + VERIFICATION_MINUTES * 60);
    $stmt = $conn->prepare('INSERT INTO verification_codes (email, staff_id, purpose, code_hash, expiry_time) VALUES (?, ?, ?, ?, ?)');
    $stmt->bind_param('sssss', $email, $staffId, $purpose, $hash, $expiry);
    $stmt->execute();
    $stmt->close();
    $what = $purpose === 'register' ? 'register your account' : 'reset your password';
    $min = VERIFICATION_MINUTES;
    $html = '<p>Your verification code to ' . $what . ' in the UIS Vehicle Section system is:</p><p style="font-size:1.6rem;letter-spacing:.2em"><strong>' . $code . '</strong></p><p>It is valid for ' . $min . ' minutes. If you did not ask for it, ignore this e-mail.</p>';
    $text = "Your verification code to $what in the UIS Vehicle Section system is: $code\nIt is valid for $min minutes. If you did not ask for it, ignore this e-mail.";
    $res = sendMail($conn, $email, '[UIS Vehicle Section] Verification code', $html, $text, ['kind' => 'verification']);
    if ($res['status'] === 'failed') {
        $stmt = $conn->prepare('DELETE FROM verification_codes WHERE email = ? AND purpose = ?');
        $stmt->bind_param('ss', $email, $purpose);
        $stmt->execute();
        $stmt->close();
        return ['ok' => false, 'message' => 'The verification e-mail could not be sent. Please try again later.'];
    }
    return ['ok' => true, 'message' => 'A verification code was sent to ' . $email . '. It is valid for ' . $min . ' minutes.'];
}

/**
 * Checks the code. On success the code is marked verified (so the next step can be done once) and true is returned.
 * @return array{ok:bool, message:string}
 */
function checkVerificationCode(mysqli $conn, string $email, string $purpose, string $code): array
{
    $stmt = $conn->prepare('SELECT code_id, code_hash, expiry_time, attempts, verified_at FROM verification_codes WHERE email = ? AND purpose = ? ORDER BY code_id DESC LIMIT 1');
    $stmt->bind_param('ss', $email, $purpose);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $fail = ['ok' => false, 'message' => 'Verification failed. Check the code and try again, or request a new code.'];   // UC013 3.1
    if (!$row || strtotime($row['expiry_time']) < time()) { return ['ok' => false, 'message' => 'The code has expired or was not found. Please request a new code.']; }
    if ((int)$row['attempts'] >= VERIFICATION_MAX_TRIES) { return ['ok' => false, 'message' => 'Too many wrong tries. Please request a new code.']; }
    if (!preg_match('/^\d{6}$/', trim($code)) || !password_verify(trim($code), $row['code_hash'])) {
        $stmt = $conn->prepare('UPDATE verification_codes SET attempts = attempts + 1 WHERE code_id = ?');
        $id = (int)$row['code_id'];
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        return $fail;
    }
    $stmt = $conn->prepare('UPDATE verification_codes SET verified_at = NOW() WHERE code_id = ?');
    $id = (int)$row['code_id'];
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
    return ['ok' => true, 'message' => 'Verified.'];
}

/** Has this e-mail a code of this purpose that was verified and has not expired? (the last step may then be done) */
function isVerified(mysqli $conn, string $email, string $purpose): bool
{
    $stmt = $conn->prepare('SELECT 1 FROM verification_codes WHERE email = ? AND purpose = ? AND verified_at IS NOT NULL AND expiry_time >= NOW() LIMIT 1');
    $stmt->bind_param('ss', $email, $purpose);
    $stmt->execute();
    $ok = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $ok;
}

/** The code is used: it cannot be used again. */
function consumeVerification(mysqli $conn, string $email, string $purpose): void
{
    $stmt = $conn->prepare('DELETE FROM verification_codes WHERE email = ? AND purpose = ?');
    $stmt->bind_param('ss', $email, $purpose);
    $stmt->execute();
    $stmt->close();
}
