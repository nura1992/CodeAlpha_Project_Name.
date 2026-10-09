<?php
declare(strict_types=1);
namespace CodeAlpha\Http\Controllers\Staff;

use CodeAlpha\Core\Auth;
use CodeAlpha\Http\Controllers\Controller;
use CodeAlpha\Services\MarketingService;

final class SuperCampaignController extends Controller
{
    public function index(): never { $this->view('staff/super/campaigns-index', ['campaigns'=>MarketingService::campaigns()]); }
    public function create(): never { $this->view('staff/super/campaign-form', ['editing'=>null]); }
    public function store(): never
    {
        $id=MarketingService::createCampaign($this->request->all(), (int)Auth::id());
        flash('success','Campaign created.');
        \CodeAlpha\Core\Response::redirect('/staff/super/campaigns/'.$id);
    }
    public function show(array $p): never
    {
        $c=MarketingService::campaign((int)$p['id']); if(!$c) \CodeAlpha\Core\Response::notFound();
        $this->view('staff/super/campaign-show', ['campaign'=>$c]);
    }
    public function update(array $p): never
    {
        MarketingService::updateCampaign((int)$p['id'], $this->request->all(), (int)Auth::id());
        flash('success','Campaign updated.');
        \CodeAlpha\Core\Response::redirect('/staff/super/campaigns/'.$p['id']);
    }
}
