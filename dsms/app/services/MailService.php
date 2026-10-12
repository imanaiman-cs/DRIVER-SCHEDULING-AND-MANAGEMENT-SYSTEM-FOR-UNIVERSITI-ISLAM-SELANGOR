<?php
/**
 * MailService - sends e-mail with PHPMailer (installed by Composer).
 *
 * If no SMTP host is configured (the default on a laptop), nothing is sent: the e-mail is
 * written to storage/logs/mail.log instead, so the demo works without a mail server and
 * the examiner can still see what WOULD have been sent. A mail failure never breaks the page.
 */
class MailService
{
    public static function send(string $to, string $subject, string $body): bool
    {
        $mail = $GLOBALS['config']['mail'];
        $canSend = $mail['host'] !== '' && class_exists('PHPMailer\\PHPMailer\\PHPMailer');
        if (!$canSend) {
            app_log("TO: $to | SUBJECT: $subject\n" . $body . "\n----", 'mail.log');
            return true;
        }
        try {
            $m = new PHPMailer\PHPMailer\PHPMailer(true);
            $m->isSMTP();
            $m->Host       = $mail['host'];
            $m->Port       = (int)$mail['port'];
            $m->SMTPAuth   = $mail['username'] !== '';
            $m->Username   = $mail['username'];
            $m->Password   = $mail['password'];
            $m->SMTPSecure = $mail['encryption'];
            $m->Timeout    = 10;                       // never make a page wait long for a slow mail server
            $m->CharSet    = 'UTF-8';
            $m->setFrom($mail['from_email'], $mail['from_name']);
            $m->addAddress($to);
            $m->Subject = $subject;
            $m->Body    = $body;
            $m->isHTML(false);
            $m->send();
            return true;
        } catch (Throwable $e) {
            app_log("Mail to $to failed: " . $e->getMessage(), 'error.log');
            return false;
        }
    }
}
