<?php
declare(strict_types=1);
namespace CodeAlpha\Http\Controllers\Staff;

use CodeAlpha\Core\Auth;
use CodeAlpha\Http\Controllers\Controller;
use CodeAlpha\Services\MarketingService;

final class SuperAdvertController extends Controller
{
    public function index(): never { $this->view('staff/super/adverts-index', ['adverts'=>MarketingService::adverts()]); }
    public function create(): never { $this->view('staff/super/advert-form', ['campaigns'=>\CodeAlpha\Core\Database::all('SELECT id, name FROM campaigns ORDER BY name')]); }
    public function store(): never
    {
        $file=$this->request->file('media')??$this->request->file('image');
        $id=MarketingService::createAdvert($this->request->all(), (int)Auth::id(), $file);
        flash('success','Advert created (pending review).');
        \CodeAlpha\Core\Response::redirect('/staff/super/advertisements');
    }
    public function review(array $p): never
    {
        $decision=(string)($this->request->input('decision')??'approve');
        MarketingService::reviewAdvert((int)$p['id'], $decision, (int)Auth::id(), (string)$this->request->input('notes',''));
        flash('success','Advert '. $decision.'d.');
        \CodeAlpha\Core\Response::redirect('/staff/super/advertisements');
    }
}
