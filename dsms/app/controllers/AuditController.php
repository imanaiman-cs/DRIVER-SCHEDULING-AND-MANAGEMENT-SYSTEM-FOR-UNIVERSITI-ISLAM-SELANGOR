<?php
/** AuditController - UC020 Audit log viewer (read only: the log is insert-only, R10). */
class AuditController extends Controller
{
    public function index(): void
    {
        $this->adminOnly();
        $model = new AuditLog();
        $action = (string)($_GET['action'] ?? '');
        $requestId = $this->getInt('request');
        $this->view('audit/index', [
            'title' => 'Audit log',
            'rows' => $model->search($action, $requestId, 300),
            'actions' => $model->actions(), 'action' => $action, 'requestId' => $requestId,
        ]);
    }
}
