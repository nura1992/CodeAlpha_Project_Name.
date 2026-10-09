<?php
/**
 * App — application kernel. Owns the router and runs the request lifecycle.
 */

declare(strict_types=1);

namespace CodeAlpha\Core;

final class App
{
    private static ?Router $router = null;

    public static function router(): Router
    {
        if (self::$router === null) {
            self::$router = new Router();
            (require dirname(__DIR__, 2) . '/src/routes.php')(self::$router);
        }
        return self::$router;
    }

    public static function run(): void
    {
        $request = Request::capture();
        self::router()->dispatch($request);
    }
}
