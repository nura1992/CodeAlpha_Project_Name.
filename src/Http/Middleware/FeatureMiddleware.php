<?php
/**
 * FeatureMiddleware — rejects requests to disabled modules server-side.
 *
 * Usage: FeatureMiddleware:messaging  (constructor argument = feature key)
 *
 * The frontend hides disabled features, but this middleware is the actual
 * enforcement: a disabled module returns 404 (so its existence is not even
 * revealed) or a JSON error with a clear code.
 */

declare(strict_types=1);

namespace CodeAlpha\Http\Middleware;

use CodeAlpha\Core\FeatureFlags;
use CodeAlpha\Core\Request;
use CodeAlpha\Core\Response;

final class FeatureMiddleware
{
    public function __construct(private string $feature)
    {
    }

    public function handle(callable $next)
    {
        if (!FeatureFlags::isEnabled($this->feature)) {
            $request = new Request();
            if ($request->wantsJson()) {
                Response::error(
                    FeatureFlags::label($this->feature) . ' is currently disabled by the administrator.',
                    404,
                    ['code' => 'feature_disabled', 'feature' => $this->feature]
                );
            }
            Response::view('errors/feature-disabled', [
                'feature' => $this->feature,
                'label'   => FeatureFlags::label($this->feature),
            ], 'guest');
        }
        return $next();
    }
}
