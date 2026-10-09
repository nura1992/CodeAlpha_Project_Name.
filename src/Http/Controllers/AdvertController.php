<?php
declare(strict_types=1);
namespace CodeAlpha\Http\Controllers;

use CodeAlpha\Core\Auth;
use CodeAlpha\Services\MarketingService;

final class AdvertController extends Controller
{
    public function apiServe(): never
    {
        $user = Auth::user();
        $placement = (string)($this->request->query('placement') ?? 'community_feed');
        $ad = MarketingService::serveAd($user ? (int)$user['id'] : null, $placement, $this->request->ip(), $this->request->userAgent());
        $this->ok(['ad'=>$ad]);
    }
    public function apiImpression(array $params): never
    {
        // Impressions are counted at serve time; this endpoint is idempotent no-op for beacon fallback
        $this->ok(['recorded'=>true]);
    }
    public function apiClick(array $params): never
    {
        $user = Auth::user();
        $adId = (int)$params['id'];
        MarketingService::recordClick($adId, $user ? (int)$user['id'] : null, $this->request->ip(), $this->request->userAgent());
        $row = \CodeAlpha\Core\Database::one('SELECT cta_url FROM advertisements WHERE id=:id', ['id'=>$adId]);
        $url = $row['cta_url'] ?? '/community';
        \CodeAlpha\Core\Response::redirect($url);
    }
}
