<?php

namespace MedCore\Middleware;

use MedCore\Core\Auth;
use MedCore\Core\Response;

/**
 * Redirects already-authenticated users away from login/register pages.
 */
final class GuestOnly
{
    public static function handle(): void
    {
        if (Auth::check()) {
            Response::redirect('/dashboard');
            throw new \MedCore\Core\HttpExit();
        }
    }
}
