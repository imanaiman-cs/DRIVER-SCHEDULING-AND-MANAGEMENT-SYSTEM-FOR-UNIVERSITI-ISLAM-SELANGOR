<?php
/** NotificationsController - UC006 In-app notifications (the bell). */
class NotificationsController extends Controller
{
    public function index(): void
    {
        Auth::requireLogin();
        $this->view('notifications/index', ['title' => 'Notifications', 'rows' => (new Notification())->forUser((int)Auth::id(), 100)]);
    }

    /** Mark one as read and go to what it is about. Only our own "?page=..." links are followed. */
    public function open(): void
    {
        Auth::requireLogin();
        $id = $this->getInt('id');
        $model = new Notification();
        $row = null;
        foreach ($model->forUser((int)Auth::id(), 100) as $n) {
            if ((int)$n['id'] === $id) { $row = $n; break; }
        }
        if (!$row) {
            $this->redirect('notifications/index');
        }
        $model->markRead((int)Auth::id(), $id);
        header('Location: ' . link_href($row['link']));
        exit;
    }

    public function readAll(): void
    {
        Auth::requireLogin();
        $this->requirePost();
        (new Notification())->markAllRead((int)Auth::id());
        $this->flashRedirect('success', 'All notifications marked as read.', 'notifications/index');
    }
}
