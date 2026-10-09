<?php
/**
 * Response — JSON / redirect / view rendering helpers.
 *
 * Views are plain PHP templates in /views. A layout wraps the template and
 * receives $content plus the shared $data. All output in templates must use
 * the e() helper (htmlspecialchars) to prevent XSS.
 */

declare(strict_types=1);

namespace CodeAlpha\Core;

final class Response
{
    public static function json($data, int $status = 200, array $headers = []): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function ok(array $data = [], int $status = 200): never
    {
        self::json(array_merge(['success' => true], $data), $status);
    }

    public static function error(string $message, int $status = 400, array $extra = []): never
    {
        self::json(array_merge(['success' => false, 'error' => $message], $extra), $status);
    }

    public static function redirect(string $url, int $status = 302): never
    {
        http_response_code($status);
        header('Location: ' . $url);
        exit;
    }

    public static function back(Request $request): never
    {
        $referer = (string) ($request->server('HTTP_REFERER') ?? '/');
        self::redirect($referer !== '' ? $referer : '/');
    }

    /**
     * Render a view template inside a layout.
     *
     * @param string $template e.g. 'user/discover'
     * @param array  $data     variables extracted into the template scope
     * @param string $layout   layout name in views/layouts ('' for none)
     */
    public static function view(string $template, array $data = [], string $layout = 'app'): never
    {
        $data['__template'] = $template;
        $render = static function (string $__file, array $__data): string {
            extract($__data, EXTR_SKIP);
            ob_start();
            include $__file;
            return (string) ob_get_clean();
        };

        $templateFile = dirname(__DIR__, 2) . '/views/' . $template . '.php';
        if (!is_file($templateFile)) {
            http_response_code(500);
            echo 'Template not found: ' . htmlspecialchars($template);
            exit;
        }

        $content = $render($templateFile, $data);

        if ($layout !== '') {
            $layoutFile = dirname(__DIR__, 2) . '/views/layouts/' . $layout . '.php';
            if (!is_file($layoutFile)) {
                http_response_code(500);
                echo 'Layout not found: ' . htmlspecialchars($layout);
                exit;
            }
            $data['content'] = $content;
            echo $render($layoutFile, $data);
        } else {
            echo $content;
        }
        exit;
    }

    public static function notFound(string $message = 'Not found'): never
    {
        if ((new Request())->wantsJson()) {
            self::error($message, 404);
        }
        http_response_code(404);
        self::view('errors/404', ['message' => $message], 'guest');
    }

    public static function forbidden(string $message = 'You are not allowed to perform this action.'): never
    {
        if ((new Request())->wantsJson()) {
            self::error($message, 403, ['code' => 'forbidden']);
        }
        http_response_code(403);
        self::view('errors/403', ['message' => $message], 'guest');
    }

    /** Stream a file download / inline file with optional HTTP Range support. */
    public static function file(string $path, string $mime, string $downloadName = null, bool $inline = true): never
    {
        if (!is_file($path)) {
            self::notFound('File not found');
        }
        $size = filesize($path);
        $start = 0;
        $end = $size - 1;
        $range = (new Request())->header('Range');
        $status = 200;

        if ($range !== '' && preg_match('/bytes=(\d*)-(\d*)/', $range, $m)) {
            if ($m[1] !== '') {
                $start = (int) $m[1];
            }
            if ($m[2] !== '') {
                $end = min((int) $m[2], $size - 1);
            }
            if ($start > $end || $start >= $size) {
                http_response_code(416);
                header('Content-Range: bytes */' . $size);
                exit;
            }
            $status = 206;
        }

        http_response_code($status);
        header('Content-Type: ' . $mime);
        header('X-Content-Type-Options: nosniff');
        header('Accept-Ranges: bytes');
        header('Content-Length: ' . ($end - $start + 1));
        if ($status === 206) {
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        }
        if ($downloadName !== null) {
            header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', $downloadName) . '"');
        }

        $fp = fopen($path, 'rb');
        fseek($fp, $start);
        $remaining = $end - $start + 1;
        while ($remaining > 0 && !feof($fp)) {
            $chunk = min(8192, $remaining);
            echo fread($fp, $chunk);
            $remaining -= $chunk;
            flush();
        }
        fclose($fp);
        exit;
    }
}
