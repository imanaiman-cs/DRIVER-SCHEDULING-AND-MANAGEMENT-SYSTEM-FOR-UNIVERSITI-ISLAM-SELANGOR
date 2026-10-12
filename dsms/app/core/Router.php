<?php
/**
 * Router - turns  index.php?page=controller/action  into a method call.
 *
 *   ?page=requests/view&id=5   ->   RequestsController::view()
 *   (no page given)            ->   HomeController::index()
 *
 * Only controller methods that are declared in the controller itself can be
 * called from the URL, never inherited helpers, and the page name may only
 * contain lowercase letters, digits and underscores (no path tricks).
 */
class Router
{
    public function dispatch(): void
    {
        $page = $_GET['page'] ?? 'home/index';

        if (!preg_match('/^[a-z][a-z0-9_]*\/[a-z][a-z0-9_]*$/', $page)) {
            $this->notFound();
            return;
        }

        [$controllerName, $action] = explode('/', $page);
        $class = str_replace(' ', '', ucwords(str_replace('_', ' ', $controllerName))) . 'Controller';

        if (!class_exists($class)) {
            $this->notFound();
            return;
        }

        $method = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $action))));
        if (!method_exists($class, $method)) {
            $this->notFound();
            return;
        }
        $reflection = new ReflectionMethod($class, $method);
        if (!$reflection->isPublic() || $reflection->getDeclaringClass()->getName() === 'Controller') {
            $this->notFound();
            return;
        }

        (new $class())->$method();
    }

    private function notFound(): void
    {
        http_response_code(404);
        View::render('errors/404', ['title' => 'Page not found']);
    }
}
