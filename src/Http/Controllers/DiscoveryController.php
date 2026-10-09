<?php
declare(strict_types=1);
namespace CodeAlpha\Http\Controllers;

use CodeAlpha\Core\Auth;
use CodeAlpha\Core\Database;
use CodeAlpha\Services\MatchingService;

final class DiscoveryController extends Controller
{
    public function apiDiscover(): never
    {
        $user = Auth::user();
        if (!$user) $this->error('Unauthenticated',401);
        MatchingService::heartbeat((int)$user['id']);
        $filters = [
            'page' => $this->request->int('page',1),
            'min_age' => $this->request->query('min_age'),
            'max_age' => $this->request->query('max_age'),
            'relationship_intention' => $this->request->query('relationship_intention'),
            'interests' => $this->request->query('interests') ? explode(',', (string)$this->request->query('interests')) : [],
            'max_distance_km' => $this->request->query('max_distance_km'),
        ];
        $result = MatchingService::discover($user, $filters);
        // Inject ad every 6 cards if available
        $this->ok($result);
    }

    public function apiLike(): never
    {
        $user = $this->user();
        $targetId = (int)($this->request->input('user_id') ?? $this->request->input('target_user_id'));
        if (!$targetId) $this->error('user_id required',422);
        try { $res = MatchingService::like((int)$user['id'], $targetId); } catch (\InvalidArgumentException $e) { $this->error($e->getMessage(),404); }
        $this->ok($res);
    }

    public function apiPass(): never
    {
        $user = $this->user();
        $targetId = (int)($this->request->input('user_id') ?? $this->request->input('target_user_id'));
        if (!$targetId) $this->error('user_id required',422);
        try { $res = MatchingService::pass((int)$user['id'], $targetId); } catch (\InvalidArgumentException $e) { $this->error($e->getMessage(),404); }
        $this->ok($res);
    }

    public function apiFavourite(): never
    {
        $user = $this->user();
        $targetId = (int)($this->request->input('user_id') ?? $this->request->input('target_user_id'));
        if (!$targetId) $this->error('user_id required',422);
        try { MatchingService::favourite((int)$user['id'], $targetId); } catch (\InvalidArgumentException $e) { $this->error($e->getMessage(),404); }
        $this->ok(['favourited'=>true]);
    }

    public function apiUnfavourite(array $params): never
    {
        $user = $this->user();
        MatchingService::unfavourite((int)$user['id'], (int)$params['user_id']);
        $this->ok(['unfavourited'=>true]);
    }

    public function apiMatches(): never
    {
        $user = $this->user();
        $this->ok(['matches'=> MatchingService::matches((int)$user['id'])]);
    }

    public function apiFavourites(): never
    {
        $user = $this->user();
        $this->ok(['favourites'=> MatchingService::favourites((int)$user['id'])]);
    }

    public function apiUnmatch(array $params): never
    {
        $user = $this->user();
        $ok = MatchingService::unmatch((int)$user['id'], (int)$params['id']);
        if (!$ok) $this->error('Match not found or not yours',404);
        $this->ok(['unmatched'=>true]);
    }

    public function apiShow(array $params): never
    {
        $viewer = $this->user();
        $targetId = (int)$params['id'];
        $target = Database::one('SELECT * FROM users WHERE id = :id AND deleted_at IS NULL', ['id'=>$targetId]);
        if (!$target) $this->error('Profile not found',404);
        $isStaff = \CodeAlpha\Services\RbacService::can((int)$viewer['id'], 'users.view');
        $full = MatchingService::fullProfile((int)$viewer['id'], $target, $isStaff);
        if (!$full) $this->error('Profile not found',404);
        $this->ok(['profile'=>$full]);
    }
}
