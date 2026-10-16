<?php

namespace MedCore\Core;

/**
 * PHP-template view renderer.
 *
 * Views live in app/Views. Dot notation: View::render('admin.users.index')
 * resolves to app/Views/admin/users/index.php wrapped in a layout.
 */
final class View
{
    private static array $shared = [];

    public static function share(string $key, $value): void
    {
        self::$shared[$key] = $value;
    }

    public static function render(string $template, array $data = [], string $layout = 'main'): string
    {
        $content = self::partial($template, $data);
        if ($layout === '') {
            return $content;
        }
        return self::partial('layouts/' . $layout, array_merge($data, ['content' => $content]));
    }

    public static function partial(string $template, array $data = []): string
    {
        $path = APP_PATH . '/Views/' . str_replace('.', '/', $template) . '.php';
        if (!is_file($path)) {
            throw new \RuntimeException("View not found: {$template} ({$path})");
        }
        extract(array_merge(self::$shared, $data), EXTR_SKIP);
        ob_start();
        require $path;
        return (string) ob_get_clean();
    }
}
