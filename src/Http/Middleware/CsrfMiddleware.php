<?php
/**
 * CsrfMiddleware — verifies the CSRF token on state-changing requests.
 */

declare(strict_types=1);

namespace CodeAlpha\Http\Middleware;

use CodeAlpha\Core\Csrf;
use CodeAlpha\Core\Request;

final class CsrfMiddleware
{
    public function handle(callable $next)
    {
        Csrf::requireValid(new Request());
        return $next();
    }
}
