<?php
/**
 * NotificationService - tell people something happened.
 * Every notification goes two ways: the in-app bell, and an e-mail (MailService).
 * No other class needs to know HOW a person is notified.
 */
class NotificationService
{
    /**
     * @param int|int[] $userIds
     * @param string|null $link a page_link() such as "?page=requests/show&id=5"
     */
    public static function notify($userIds, string $message, ?string $link = null): void
    {
        $notification = new Notification();
        $users = new User();
        foreach (array_unique(array_filter((array)$userIds)) as $userId) {
            // A failed notification or e-mail must never undo the real action (approve, cancel, ...).
            try {
                $notification->add((int)$userId, mb_substr($message, 0, 500), $link);
                $user = $users->find((int)$userId);
                if ($user && $user['is_active'] && $user['email'] !== '') {
                    $url = $link ? rtrim($GLOBALS['config']['app_url'], '/') . '/index.php' . $link : '';
                    MailService::send(
                        $user['email'],
                        '[DSMS] ' . mb_substr($message, 0, 70),
                        "Salam / Hello {$user['name']},\n\n$message\n\n" . ($url ? "Open it here: $url\n\n" : '') . "-- DSMS, UIS Vehicle Section\n"
                    );
                }
            } catch (Throwable $e) {
                app_log('Notification failed: ' . $e->getMessage(), 'error.log');
            }
        }
    }
}
