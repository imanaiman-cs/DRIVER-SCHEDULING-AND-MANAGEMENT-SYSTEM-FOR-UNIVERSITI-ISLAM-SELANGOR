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
