<?php
/**
 * View - renders a PHP template inside a layout.
 *
 * View::render('home/index', ['title' => 'Home']) loads app/views/home/index.php,
 * captures its HTML and places it into app/views/layouts/main.php as $content.
 */
class View
{
    public static function render(string $view, array $data = [], string $layout = 'main'): void
    {
        $viewFile = dirname(__DIR__) . '/views/' . $view . '.php';
        if (!is_file($viewFile)) {
            throw new RuntimeException("View not found: $view");
        }

        // Every template can use $config (app name, org name, ...).
        // It is a global, so it must be handed to the template's own scope.
        $config = $GLOBALS['config'];

        // Make the data available as plain variables inside the template.
        extract($data, EXTR_SKIP);

        ob_start();
        require $viewFile;
        $content = ob_get_clean();

        if ($layout === '') {            // layout-less output (e.g. a printable page)
            echo $content;
            return;
        }
        $title = $data['title'] ?? $GLOBALS['config']['app_title'];
        require dirname(__DIR__) . '/views/layouts/' . $layout . '.php';
    }
}
