<?php

namespace MedCore\Modules\Providers;

use MedCore\Core\Auth;
use MedCore\Core\Authorization;
use MedCore\Core\Request;
use MedCore\Core\Response;
use MedCore\Core\Session;
use MedCore\Core\Validator;
use MedCore\Repositories\OrganizationRepository;
use MedCore\Repositories\ProviderRepository;
use MedCore\Services\AuditLogger;

/**
 * MediLink provider management (staff side).
 */
final class ProviderController
{
    public function index(Request $request): void
    {
        Authorization::authorize('providers.view');
        $page = max(1, (int) $request->input('page', 1));
        $search = trim((string) $request->input('q', ''));
        $orgScope = Authorization::hasRole((int) Auth::id(), 'super_admin')
            ? null
            : Authorization::organizationIdsOf((int) Auth::id());

        Response::view('providers/index', [
            'title'  => 'Care providers',
            'result' => ProviderRepository::paginate($page, 15, $search, $orgScope),
            'search' => $search,
            'canEdit' => Authorization::can((int) Auth::id(), 'providers.update'),
        ]);
    }

    public function create(): void
    {
        Authorization::authorize('providers.create');
        Response::view('providers/form', [
            'title'    => 'Add provider',
            'provider' => null,
            'orgs'     => $this->writableOrgs(),
        ]);
    }

    public function store(Request $request): void
    {
        Authorization::authorize('providers.create');
        $v = $this->validateProvider($request);
        if ($v->fails()) {
            Session::flashInput($request->all());
            Session::flashErrors($v->errors());
            Response::redirect('/providers/create');
            return;
        }
        $data = $v->validated();
        if (!$this->canWriteToOrg((int) $data['organization_id'])) {
            Response::abort(403, 'You cannot add providers to that organization.');
        }
        $data['status'] = 'active';
        $id = ProviderRepository::create($data);
        AuditLogger::log(Auth::id(), 'providers.created', 'provider', $id, ['name' => $data['full_name']], $request);
        Session::flash('success', 'Provider added.');
        Response::redirect('/providers');
    }

    public function edit(Request $request, string $id): void
    {
        Authorization::authorize('providers.update');
        $provider = ProviderRepository::find((int) $id);
        if ($provider === null || !$this->canWriteToOrg((int) $provider['organization_id'])) {
            Response::abort(404);
        }
        Response::view('providers/form', [
            'title'    => 'Edit provider',
            'provider' => $provider,
            'orgs'     => $this->writableOrgs(),
        ]);
    }

    public function update(Request $request, string $id): void
    {
        Authorization::authorize('providers.update');
        $provider = ProviderRepository::find((int) $id);
        if ($provider === null || !$this->canWriteToOrg((int) $provider['organization_id'])) {
            Response::abort(404);
        }
        $v = $this->validateProvider($request);
        if ($v->fails()) {
            Session::flashErrors($v->errors());
            Response::redirect('/providers/' . (int) $id . '/edit');
            return;
        }
        $data = $v->validated();
        if (!$this->canWriteToOrg((int) $data['organization_id'])) {
            Response::abort(403, 'You cannot move providers to that organization.');
        }
        ProviderRepository::update((int) $id, $data);
        AuditLogger::log(Auth::id(), 'providers.updated', 'provider', (int) $id, null, $request);
        Session::flash('success', 'Provider updated.');
        Response::redirect('/providers');
    }

    /* ------------------------------ helpers --------------------------- */

    private function validateProvider(Request $request): Validator
    {
        return Validator::make($request->all(), [
            'organization_id' => 'required|integer',
            'full_name'       => 'required|min:2|max:120',
            'title'           => 'max:40',
            'specialty'       => 'required|max:80',
            'email'           => 'email|max:190',
            'phone'           => 'phone|max:20',
            'location'        => 'max:120',
            'bio'             => 'max:1000',
            'status'          => 'in:active,inactive',
        ]);
    }

    private function writableOrgs(): array
    {
        if (Authorization::hasRole((int) Auth::id(), 'super_admin')) {
            return OrganizationRepository::allActive();
        }
        $orgs = [];
        foreach (Authorization::organizationIdsOf((int) Auth::id()) as $orgId) {
            $org = OrganizationRepository::find($orgId);
            if ($org !== null && $org['status'] === 'approved') {
                $orgs[] = $org;
            }
        }
        return $orgs;
    }

    private function canWriteToOrg(int $orgId): bool
    {
        foreach ($this->writableOrgs() as $org) {
            if ((int) $org['id'] === $orgId) {
                return true;
            }
        }
        return false;
    }
}
