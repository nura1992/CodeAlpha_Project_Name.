<?php
/**
 * Controller — base class with request/response conveniences.
 */

declare(strict_types=1);

namespace CodeAlpha\Http\Controllers;

use CodeAlpha\Core\Request;
use CodeAlpha\Core\Response;

abstract class Controller
{
    protected Request $request;

    public function __construct()
    {
        $this->request = new Request();
    }

    protected function json($data, int $status = 200): never
    {
        Response::json($data, $status);
    }

    protected function ok(array $data = [], int $status = 200): never
    {
        Response::ok($data, $status);
    }

    protected function error(string $message, int $status = 400, array $extra = []): never
    {
        Response::error($message, $status, $extra);
    }

    protected function view(string $template, array $data = [], string $layout = 'app'): never
    {
        Response::view($template, $data, $layout);
    }

    protected function redirect(string $url): never
    {
        Response::redirect($url);
    }

    /** Current authenticated user or 401. */
    protected function user(): array
    {
        $user = \CodeAlpha\Core\Auth::user();
        if (!$user) {
            $this->error('Authentication required.', 401, ['code' => 'unauthenticated']);
        }
        return $user;
    }
}
