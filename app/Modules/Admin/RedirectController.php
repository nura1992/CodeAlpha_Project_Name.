<?php

namespace MedCore\Modules\Admin;

use MedCore\Core\Request;
use MedCore\Core\Response;

/**
 * Convenience redirects for friendly URLs.
 */
final class RedirectController
{
    public function toAppointments(Request $request): void
    {
        Response::redirect('/appointments');
    }
}
