<?php
declare(strict_types=1);
namespace CodeAlpha\Http\Controllers;

use CodeAlpha\Services\CommunityService;

final class CommunityController extends Controller
{
    public function apiFeed(): never
    {
        $user = $this->user();
        $feed = CommunityService::feed((int)$user['id'], ['page'=>$this->request->int('page',1), 'topic'=>$this->request->query('topic'), 'following'=>$this->request->bool('following')]);
        $this->ok($feed);
    }
    public function apiCreate(): never
    {
        $user = $this->user();
        $payload = $this->request->all();
        $file = $this->request->file('media') ?? $this->request->file('image') ?? $this->request->file('video');
        try { $post = CommunityService::createPost((int)$user['id'], $payload, $file); } catch (\InvalidArgumentException $e) { $this->error($e->getMessage(),422); }
        $this->ok(['post'=>$post],201);
    }
    public function apiShow(array $params): never
    {
        $user = $this->user();
        $post = CommunityService::getPost((int)$user['id'], (int)$params['id']);
        if (!$post) $this->error('Post not found',404);
        $this->ok(['post'=>$post, 'comments'=> CommunityService::commentsFor((int)$user['id'], (int)$params['id'])]);
    }
    public function apiUpdate(array $params): never
    {
        $user = $this->user();
        try { $post = CommunityService::updatePost((int)$user['id'], (int)$params['id'], $this->request->all()); } catch (\InvalidArgumentException $e) { $this->error($e->getMessage(),404); }
        $this->ok(['post'=>$post]);
    }
    public function apiDelete(array $params): never
    {
        $user = $this->user();
        try { CommunityService::deletePost((int)$user['id'], (int)$params['id']); } catch (\InvalidArgumentException $e) { $this->error($e->getMessage(),403); }
        $this->ok(['deleted'=>true]);
    }
    public function apiReact(array $params): never
    {
        $user = $this->user();
        $type = (string)($this->request->input('type') ?? 'like');
        try { $r = CommunityService::reactPost((int)$user['id'], (int)$params['id'], $type); } catch (\InvalidArgumentException $e) { $this->error($e->getMessage(),404); }
        $this->ok($r);
    }
    public function apiUnreact(array $params): never
    {
        $user = $this->user();
        $this->ok(CommunityService::unreactPost((int)$user['id'], (int)$params['id']));
    }
    public function apiComments(array $params): never
    {
        $user = $this->user();
        $this->ok(['comments'=> CommunityService::commentsFor((int)$user['id'], (int)$params['id'])]);
    }
    public function apiComment(array $params): never
    {
        $user = $this->user();
        $body = (string)($this->request->input('body') ?? '');
        $parent = $this->request->int('parent_id') ?: null;
        try { $c = CommunityService::comment((int)$user['id'], (int)$params['id'], $body, $parent); } catch (\InvalidArgumentException $e) { $this->error($e->getMessage(),422); }
        $this->ok(['comment'=>$c],201);
    }
    public function apiReactComment(array $params): never
    {
        $user = $this->user();
        $type = (string)($this->request->input('type') ?? 'like');
        try { $r = CommunityService::reactComment((int)$user['id'], (int)$params['id'], $type); } catch (\InvalidArgumentException $e) { $this->error($e->getMessage(),404); }
        $this->ok($r);
    }
    public function apiUnreactComment(array $params): never
    {
        $user = $this->user();
        $this->ok(CommunityService::unreactComment((int)$user['id'], (int)$params['id']));
    }
    public function apiShare(array $params): never
    {
        $user = $this->user();
        $channel = (string)($this->request->input('channel') ?? 'internal');
        try { $url = CommunityService::share((int)$user['id'], (int)$params['id'], $channel); } catch (\InvalidArgumentException $e) { $this->error($e->getMessage(),404); }
        $this->ok(['share_url'=>$url]);
    }
    public function apiSave(array $params): never
    {
        $user = $this->user();
        CommunityService::save((int)$user['id'], (int)$params['id']);
        $this->ok(['saved'=>true]);
    }
    public function apiUnsave(array $params): never
    {
        $user = $this->user();
        CommunityService::unsave((int)$user['id'], (int)$params['id']);
        $this->ok(['saved'=>false]);
    }
    public function apiSaved(): never
    {
        $user = $this->user();
        $this->ok(['posts'=> CommunityService::savedPosts((int)$user['id'])]);
    }
    public function apiFollow(array $params): never
    {
        $user = $this->user();
        try { CommunityService::follow((int)$user['id'], (int)$params['id']); } catch (\InvalidArgumentException $e) { $this->error($e->getMessage(),404); }
        $this->ok(['following'=>true]);
    }
    public function apiUnfollow(array $params): never
    {
        $user = $this->user();
        CommunityService::unfollow((int)$user['id'], (int)$params['id']);
        $this->ok(['following'=>false]);
    }
    public function apiMember(array $params): never
    {
        $user = $this->user();
        $id = (int)$params['id'];
        $row = \CodeAlpha\Core\Database::one('SELECT u.id, u.uuid, p.display_name, p.bio, p.location_city, p.verified_at FROM users u LEFT JOIN profiles p ON p.user_id=u.id WHERE u.id=:id AND u.deleted_at IS NULL', ['id'=>$id]);
        if (!$row) $this->error('Member not found',404);
        $avatar = \CodeAlpha\Core\Database::scalar("SELECT path FROM profile_photos WHERE user_id=:id AND is_avatar=1 AND moderation_status='approved' ORDER BY position ASC LIMIT 1", ['id'=>$id]);
        $row['avatar']=$avatar?'/uploads/'.$avatar:null;
        $row['is_following']= CommunityService::isFollowing((int)$user['id'], $id);
        $row['followers']=(int)\CodeAlpha\Core\Database::scalar('SELECT COUNT(*) FROM follows WHERE followee_id=:id',['id'=>$id]);
        $row['following_count']=(int)\CodeAlpha\Core\Database::scalar('SELECT COUNT(*) FROM follows WHERE follower_id=:id',['id'=>$id]);
        $this->ok(['member'=>$row, 'posts'=> CommunityService::postsByMember((int)$user['id'], $id)]);
    }
    public function apiTrending(): never
    {
        $user = $this->user();
        $this->ok(['trending'=> CommunityService::trending((int)$user['id'])]);
    }
    public function apiSearch(): never
    {
        $user = $this->user();
        $q = (string)($this->request->query('q') ?? $this->request->query('query') ?? '');
        $tab = (string)($this->request->query('tab') ?? 'posts');
        $this->ok(['results'=> CommunityService::search((int)$user['id'], $q, $tab), 'query'=>$q]);
    }
    public function apiTopics(): never
    {
        $this->ok(['topics'=> CommunityService::topics()]);
    }
    public function apiReport(array $params): never
    {
        $user = $this->user();
        $type = (string)($this->request->input('type') ?? 'other');
        $reason = trim((string)($this->request->input('reason') ?? ''));
        if ($reason==='') $this->error('Reason required',422);
        try { $id = CommunityService::reportPost((int)$user['id'], (int)$params['id'], $type, $reason, (string)$this->request->input('details','')); } catch (\InvalidArgumentException $e) { $this->error($e->getMessage(),404); }
        $this->ok(['report_id'=>$id],201);
    }
    public function apiReportComment(array $params): never
    {
        $user = $this->user();
        $type = (string)($this->request->input('type') ?? 'other');
        $reason = trim((string)($this->request->input('reason') ?? ''));
        if ($reason==='') $this->error('Reason required',422);
        try { $id = CommunityService::reportComment((int)$user['id'], (int)$params['id'], $type, $reason, (string)$this->request->input('details','')); } catch (\InvalidArgumentException $e) { $this->error($e->getMessage(),404); }
        $this->ok(['report_id'=>$id],201);
    }
}
