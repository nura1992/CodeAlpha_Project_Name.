<?php

namespace MedCore\Middleware;

use MedCore\Core\Auth;
use MedCore\Core\Request;
use MedCore\Core\Response;
use MedCore\Core\Session;

/**
 * Rejects guests: redirects web users to /login, JSON clients get 401.
 */
final class RequireLogin
{
    public static function handle(): void
    {
        if (Auth::check()) {
            return;
        }
        $request = new Request();
        if ($request->wantsJson()) {
            Response::json(['error' => 'Unauthenticated'], 401);
            throw new \MedCore\Core\HttpExit();
        }
        Session::flash('warning', 'Please sign in to continue.');
        Session::set('_intended', $request->path());
        Response::redirect('/login');
        throw new \MedCore\Core\HttpExit();
    }
}
