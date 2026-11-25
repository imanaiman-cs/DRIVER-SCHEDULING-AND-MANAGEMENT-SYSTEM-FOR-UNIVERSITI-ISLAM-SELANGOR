<?php
// ============================================================
// SDD_CLASS_210 - NotificationService (Domain Layer, SDD_PKG_200)
// Sends in-system notifications and e-mails for all subsystems. Drivers can also get WhatsApp (OpenWA) when it is
// switched on in config/whatsapp.php. An e-mail failure is logged and never stops the main action.
// ============================================================

class NotificationService
{
    /** Address of the UIS email server (config/mail.php). */
    private string $smtpHost;

    public function __construct()
    {
        $this->smtpHost = (string)(mailConfig()['host'] ?? '');
    }

    /** Saves a notification for each user and sends the same message by e-mail (SDD 5.21.2.1). */
    public function notify(array $userIDs, string $message, ?string $link = null): void
    {
        foreach (array_unique(array_filter(array_map('intval', $userIDs))) as $uid) {
            $u = User::findByID($uid);
            if (!$u) { continue; }
            $nid = Notification::create($uid, $message, $link);
            if ($this->sendEmail($u['email'], 'DSMS: ' . mb_substr($message, 0, 70), $this->body($u['name'], $message, $link))) {
                Notification::markEmailSent($nid);
            }
            if ($u['role'] === User::DRIVER && function_exists('sendWhatsApp')) {
                try { sendWhatsApp($uid, $u['phone_no'], "[UIS Vehicle Section] $message\n" . self::url($link)); } catch (Throwable $e) { appLog('whatsapp', $e->getMessage()); }
            }
        }
    }

    /** Notifies every active user with the role. */
    public function notifyRole(string $role, string $message, ?string $link = null): void
    {
        $this->notify(array_column(User::findByRole($role), 'user_id'), $message, $link);
    }

    /** Sends one e-mail through SMTP. True when it was sent (or e-mail is switched off and it was only logged). */
    public function sendEmail(?string $to, string $subject, string $body): bool
    {
        $html = nl2br(htmlspecialchars($body), false);
        $res = sendMail($to, $subject, '<div style="font-family:Arial,sans-serif;font-size:14px">' . $html . '</div>', $body);
        return $res['status'] === 'sent';
    }

    /** Returns the number of unread notifications. */
    public function countUnread(int $userID): int
    {
        return Notification::countUnread($userID);
    }

    /** The notifications of the user, a page at a time. */
    public function listForUser(int $userID, int $limit, int $offset): array
    {
        return ['rows' => Notification::findByUser($userID, $limit, $offset), 'total' => Notification::countByUser($userID), 'unread' => Notification::countUnread($userID)];
    }

    /** Marks the notification as read and returns the page it opens (relative links of this system only). */
    public function openNotification(int $notificationID, int $userID): string
    {
        $n = Notification::findByID($notificationID, $userID);
        if (!$n) { return SITE_URL . '/notifications.php'; }
        Notification::markRead($notificationID, $userID);
        $link = trim((string)$n['link']);
        $safe = $link !== '' && !preg_match('#^[a-z][a-z0-9+.-]*:|^//|[\\\x00-\x1f]#i', $link) && !str_contains($link, '..');
        return $safe ? SITE_URL . '/' . ltrim($link, '/') : SITE_URL . '/notifications.php';
    }

    public function markAllRead(int $userID): void
    {
        Notification::markAllRead($userID);
    }

    /** E-mails of recent notifications that failed are sent again (called by the ScheduledJob). */
    public function retryFailedEmails(): int
    {
        if (!mailEnabled()) { return 0; }
        $n = 0;
        foreach (Notification::findEmailNotSent() as $row) {
            if ($this->sendEmail($row['email'], 'DSMS: ' . mb_substr($row['message'], 0, 70), $this->body($row['name'], $row['message'], $row['link']))) {
                Notification::markEmailSent((int)$row['notification_id']);
                $n++;
            }
        }
        return $n;
    }

    private function body(string $name, string $message, ?string $link): string
    {
        return "Salam $name,\n\n$message\n\n" . ($link ? 'Open: ' . self::url($link) . "\n\n" : '') . 'UIS Vehicle Section (DSMS)';
    }

    /** A stored link (page below SITE_URL) as a full address. */
    public static function url(?string $link): string
    {
        return $link ? rtrim(SITE_URL, '/') . '/' . ltrim($link, '/') : SITE_URL . '/notifications.php';
    }
}
