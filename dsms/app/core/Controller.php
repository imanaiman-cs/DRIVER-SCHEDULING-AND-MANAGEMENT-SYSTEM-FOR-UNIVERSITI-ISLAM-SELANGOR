<?php
/**
 * Controller - base class for all controllers.
 *
 * A controller method = one page/action. It checks permission, asks the
 * models for data, then renders a view. No SQL and no HTML in controllers.
 */
abstract class Controller
{
    protected function view(string $view, array $data = [], string $layout = 'main'): void
    {
        View::render($view, $data, $layout);
    }

    protected function redirect(string $page, array $params = []): void
    {
        header('Location: ' . url($page, $params));
        exit;
    }

    /** Trimmed posted text. */
    protected function post(string $key, string $default = ''): string
    {
        return trim((string)($_POST[$key] ?? $default));
    }

    protected function postInt(string $key): int
    {
        return (int)($_POST[$key] ?? 0);
    }

    /** ?id=5 in the URL. */
    protected function getInt(string $key): int
    {
        return (int)($_GET[$key] ?? 0);
    }

    /** Set a flash message and go to another page. */
    protected function flashRedirect(string $type, string $message, string $page, array $params = []): void
    {
        Session::flash($type, $message);
        $this->redirect($page, $params);
    }

    /** Pages for the Vehicle Section (admin_assistant and head_admin share every admin function). */
    protected function adminOnly(): void
    {
        Auth::requireRole('admin_assistant', 'head_admin');
    }

    /** Actions that change data must be POST + carry a valid CSRF token. */
    protected function requirePost(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            exit('Method not allowed.');
        }
        if (!Csrf::verify($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            View::render('errors/csrf', ['title' => 'Session expired']);
            exit;
        }
    }
}
