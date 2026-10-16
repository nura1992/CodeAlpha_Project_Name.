<?php

namespace MedCore\Core;

/**
 * HTTP response helpers.
 */
final class Response
{
    /** Render a view template inside a layout and send it. */
    public static function view(string $template, array $data = [], string $layout = 'main', int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        echo View::render($template, $data, $layout);
    }

    public static function json($data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function redirect(string $to, int $status = 302): void
    {
        http_response_code($status);
        header('Location: ' . $to);
    }

    public static function back(string $fallback = '/'): void
    {
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if ($referer && $host && str_contains($referer, $host)) {
            self::redirect($referer);
            return;
        }
        self::redirect($fallback);
    }

    public static function abort(int $status, string $message = ''): void
    {
        $titles = [
            403 => 'Access denied',
            404 => 'Page not found',
            419 => 'Page expired',
            429 => 'Too many requests',
            500 => 'Something went wrong',
        ];
        $title = $titles[$status] ?? 'Error';
        self::view('errors/error', [
            'status'  => $status,
            'title'   => $title,
            'message' => $message !== '' ? $message : $title,
        ], 'auth', $status);
        throw new HttpExit();
    }
}
