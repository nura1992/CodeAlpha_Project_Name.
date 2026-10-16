<?php

namespace MedCore\Modules\PublicSite;

use MedCore\Core\Auth;
use MedCore\Core\Request;
use MedCore\Core\Response;
use MedCore\Repositories\ProviderRepository;

/**
 * Public landing page + open provider directory (MediLink search).
 * Exposes only non-sensitive directory fields.
 */
final class LandingController
{
    public function landing(): void
    {
        if (Auth::check()) {
            Response::redirect('/dashboard');
            return;
        }
        Response::view('public/landing', [
            'title'      => 'Your health, connected',
            'specialties' => ProviderRepository::distinctSpecialties(),
        ], 'public');
    }

    public function directory(Request $request): void
    {
        $q = trim((string) $request->input('q', ''));
        $specialty = trim((string) $request->input('specialty', ''));
        $location = trim((string) $request->input('location', ''));

        Response::view('public/directory', [
            'title'       => 'Find care',
            'providers'   => ProviderRepository::searchDirectory($q, $specialty, $location),
            'specialties' => ProviderRepository::distinctSpecialties(),
            'q'           => $q,
            'specialty'   => $specialty,
            'location'    => $location,
        ], 'public');
    }
}
