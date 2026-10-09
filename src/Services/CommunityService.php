<?php
/**
 * CommunityService — the Facebook-inspired social module.
 *
 * Posts (text/image/video/link), nested comments, reactions, internal and
 * external sharing, follows, hashtags, topics, search, trending, saved
 * posts, reports and moderation hooks. The community is independent of
 * matchmaking: it stays available when discovery is disabled, and pending
 * (unapproved) members can still participate.
 *
 * Sharing never exposes private profile details or verification media:
 * share links point at the public post page only.
 */

declare(strict_types=1);

namespace CodeAlpha\Services;

use CodeAlpha\Core\AuditLog;
use CodeAlpha\Core\Database;
use CodeAlpha\Core\Uploader;

final class CommunityService
{
    private const MAX_BODY = 5000;

    // ------------------------------------------------------------------
    // Posts
    // ------------------------------------------------------------------

    public static function createPost(int $userId, array $payload, ?array $file = null): array
    {
        $type = (string) ($payload['type'] ?? 'text');
        if (!in_array($type, ['text', 'image', 'video', 'link'], true)) {
            $type = 'text';
        }
        $body = trim((string) ($payload['body'] ?? ''));
        if (mb_strlen($body) > self::MAX_BODY) {
            throw new \InvalidArgumentException('Post is too long (max ' . self::MAX_BODY . ' characters).');
        }
        if ($type === 'text' && $body === '' && !$file) {
            throw new \InvalidArgumentException('Post text is required.');
        }

        $mediaPath = null;
        $mediaMime = null;
        if ($file && in_array($type, ['image', 'video'], true)) {
            $stored = Uploader::storePublic($file, $type, 'post');
            $mediaPath = substr($stored['path'], strlen('/uploads/'));
            $mediaMime = $stored['mime'];
        }

        $linkUrl = null;
        if ($type === 'link') {
            $linkUrl = trim((string) ($payload['link_url'] ?? ''));
            if ($linkUrl !== '' && !filter_var($linkUrl, FILTER_VALIDATE_URL)) {
                throw new \InvalidArgumentException('Link URL is invalid.');
            }
            if ($linkUrl === '') {
                $type = 'text';
            }
        }

        $visibility = (string) ($payload['visibility'] ?? 'public');
        if (!in_array($visibility, ['public', 'followers'], true)) {
            $visibility = 'public';
        }

        $hashtags = self::extractHashtags($body);
        $topic = trim((string) ($payload['topic'] ?? ''));
        $topic = $topic !== '' ? mb_substr($topic, 0, 100) : null;

        $now = date('Y-m-d H:i:s');
        $postId = (int) Database::insert('community_posts', [
            'user_id'      => $userId,
            'type'         => $type,
            'body'         => $body !== '' ? $body : null,
            'media_path'   => $mediaPath,
            'media_mime'   => $mediaMime,
            'link_url'     => $linkUrl,
            'visibility'   => $visibility,
            'topic'        => $topic,
            'hashtags'     => $hashtags === [] ? null : json_encode(array_values($hashtags)),
            'status'       => 'published',
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        // Mentions → notifications.
        self::notifyMentions($userId, $postId, $body);

        return self::getPost($userId, $postId);
    }

    public static function updatePost(int $userId, int $postId, array $payload): array
    {
        $post = self::getPost($userId, $postId);
        if (!$post || (int) $post['user_id'] !== $userId) {
            throw new \InvalidArgumentException('Post not found.');
        }
        $body = trim((string) ($payload['body'] ?? $post['body']));
        if (mb_strlen($body) > self::MAX_BODY) {
            throw new \InvalidArgumentException('Post is too long.');
        }
        $visibility = (string) ($payload['visibility'] ?? $post['visibility']);
        if (!in_array($visibility, ['public', 'followers'], true)) {
            $visibility = 'public';
        }
        Database::update('community_posts', [
            'body'       => $body !== '' ? $body : null,
            'visibility' => $visibility,
            'hashtags'   => json_encode(array_values(self::extractHashtags($body))),
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $postId]);
        return self::getPost($userId, $postId);
    }

    public static function deletePost(int $userId, int $postId): bool
    {
        $post = Database::one('SELECT * FROM community_posts WHERE id = :id', ['id' => $postId]);
        if (!$post) {
            return false;
        }
        if ((int) $post['user_id'] !== $userId && !can('community.manage')) {
            throw new \InvalidArgumentException('You can only delete your own posts.');
        }
        Database::update('community_posts', [
            'status' => 'removed', 'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $postId]);
        AuditLog::record($userId, 'community.post_removed', 'community_post', (string) $postId);
        return true;
    }

    public static function getPost(int $viewerId, int $postId): ?array
    {
        $post = Database::one('SELECT * FROM community_posts WHERE id = :id', ['id' => $postId]);
        if (!$post) {
            return null;
        }
        return self::postView($viewerId, $post);
    }

    /**
     * Feed: announcements + pinned + posts from followed members + recent
     * public posts. Ads are injected by the controller (AdvertService).
     */
    public static function feed(int $userId, array $filters = []): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = 15;
        $topic = $filters['topic'] ?? null;
        $following = !empty($filters['following']);

        $where = "p.status = 'published'";
        $params = [];
        if ($topic) {
            $where .= ' AND p.topic = :topic';
            $params['topic'] = $topic;
        }
        if ($following) {
            $where .= ' AND EXISTS (SELECT 1 FROM follows f WHERE f.follower_id = :uid AND f.followee_id = p.user_id)';
            $params['uid'] = $userId;
        }
        $where .= " AND (p.visibility = 'public' OR p.user_id = :uid"
            . ' OR EXISTS (SELECT 1 FROM follows f2 WHERE f2.follower_id = :uid AND f2.followee_id = p.user_id))';
        $params['uid'] = $userId;

        $rows = Database::all(
            "SELECT p.* FROM community_posts p
              WHERE $where
              ORDER BY p.is_announcement DESC, p.is_pinned DESC, p.created_at DESC
              LIMIT 300",
            $params
        );

        $items = array_map(static fn ($row) => self::postView($userId, $row), $rows);
        $total = count($items);
        return [
            'items' => array_slice($items, ($page - 1) * $perPage, $perPage),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    public static function postsByMember(int $viewerId, int $memberId, int $page = 1): array
    {
        $rows = Database::all(
            "SELECT p.* FROM community_posts p
              WHERE p.user_id = :uid AND p.status = 'published'
                AND (p.visibility = 'public' OR p.user_id = :viewer)
              ORDER BY p.created_at DESC LIMIT 100",
            ['uid' => $memberId, 'viewer' => $viewerId]
        );
        $items = array_map(static fn ($row) => self::postView($viewerId, $row), $rows);
        return array_slice($items, ($page - 1) * 15, 15);
    }

    // ------------------------------------------------------------------
    // Comments & reactions
    // ------------------------------------------------------------------

    public static function comment(int $userId, int $postId, string $body, ?int $parentId = null): array
    {
        $post = Database::one("SELECT * FROM community_posts WHERE id = :id AND status = 'published'", ['id' => $postId]);
        if (!$post) {
            throw new \InvalidArgumentException('Post not found.');
        }
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > 2000) {
            throw new \InvalidArgumentException('Comment must be 1-2000 characters.');
        }
        if ($parentId) {
            $parent = Database::one('SELECT * FROM post_comments WHERE id = :id AND post_id = :pid AND status = \'published\'', ['id' => $parentId, 'pid' => $postId]);
            if (!$parent) {
                throw new \InvalidArgumentException('Parent comment not found.');
            }
        }
        $now = date('Y-m-d H:i:s');
        $commentId = (int) Database::insert('post_comments', [
            'post_id'    => $postId,
            'user_id'    => $userId,
            'parent_id'  => $parentId,
            'body'       => $body,
            'status'     => 'published',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Notify the post author (and parent comment author for replies).
        $notifyTo = (int) $post['user_id'];
        if ($parentId) {
            $parent = Database::one('SELECT user_id FROM post_comments WHERE id = :id', ['id' => $parentId]);
            if ($parent && (int) $parent['user_id'] !== $userId && (int) $parent['user_id'] !== $notifyTo) {
                $notifyTo = (int) $parent['user_id'];
            }
        }
        if ($notifyTo !== $userId) {
            NotificationService::notify($notifyTo, 'comment', 'New comment on your post', mb_substr($body, 0, 120), ['post_id' => $postId, 'comment_id' => $commentId]);
        }
        self::notifyMentions($userId, $postId, $body, 'comment');

        $comment = Database::one('SELECT * FROM post_comments WHERE id = :id', ['id' => $commentId]);
        return self::commentView($userId, $comment);
    }

    public static function commentsFor(int $viewerId, int $postId): array
    {
        $rows = Database::all(
            "SELECT * FROM post_comments WHERE post_id = :pid AND status = 'published' ORDER BY created_at ASC",
            ['pid' => $postId]
        );
        $views = array_map(static fn ($row) => self::commentView($viewerId, $row), $rows);
        // Nest replies under their parents.
        $byId = [];
        foreach ($views as $view) {
            $byId[$view['id']] = $view + ['replies' => []];
        }
        $roots = [];
        foreach ($byId as $id => $view) {
            if ($view['parent_id'] && isset($byId[$view['parent_id']])) {
                $byId[$view['parent_id']]['replies'][] = &$byId[$id];
            } else {
                $roots[] = &$byId[$id];
            }
            unset($view);
        }
        return $roots;
    }

    private static function allowedReactionTypes(): array
    {
        return ['like', 'love', 'haha', 'wow', 'sad', 'angry'];
    }

    public static function reactPost(int $userId, int $postId, string $type): array
    {
        if (!in_array($type, self::allowedReactionTypes(), true)) {
            $type = 'like';
        }
        $post = Database::one("SELECT * FROM community_posts WHERE id = :id AND status = 'published'", ['id' => $postId]);
        if (!$post) {
            throw new \InvalidArgumentException('Post not found.');
        }
        self::setReaction('post', $userId, $postId, null, $type);
        if ((int) $post['user_id'] !== $userId) {
            NotificationService::notify((int) $post['user_id'], 'reaction', 'Someone reacted to your post', ucfirst($type), ['post_id' => $postId]);
        }
        return self::reactionCounts('post', $postId, null) + ['my_reaction' => $type];
    }

    public static function unreactPost(int $userId, int $postId): array
    {
        Database::delete('post_reactions', 'post_id = :pid AND user_id = :uid AND comment_id IS NULL', ['pid' => $postId, 'uid' => $userId]);
        return self::reactionCounts('post', $postId, null) + ['my_reaction' => null];
    }

    public static function reactComment(int $userId, int $commentId, string $type): array
    {
        if (!in_array($type, self::allowedReactionTypes(), true)) {
            $type = 'like';
        }
        $comment = Database::one("SELECT * FROM post_comments WHERE id = :id AND status = 'published'", ['id' => $commentId]);
        if (!$comment) {
            throw new \InvalidArgumentException('Comment not found.');
        }
        self::setReaction('comment', $userId, (int) $comment['post_id'], $commentId, $type);
        if ((int) $comment['user_id'] !== $userId) {
            NotificationService::notify((int) $comment['user_id'], 'reaction', 'Someone reacted to your comment', ucfirst($type), ['post_id' => (int) $comment['post_id'], 'comment_id' => $commentId]);
        }
        return self::reactionCounts('comment', (int) $comment['post_id'], $commentId) + ['my_reaction' => $type];
    }

    public static function unreactComment(int $userId, int $commentId): array
    {
        $comment = Database::one('SELECT * FROM post_comments WHERE id = :id', ['id' => $commentId]);
        if ($comment) {
            Database::delete('post_reactions', 'comment_id = :cid AND user_id = :uid', ['cid' => $commentId, 'uid' => $userId]);
            return self::reactionCounts('comment', (int) $comment['post_id'], $commentId) + ['my_reaction' => null];
        }
        return ['my_reaction' => null];
    }

    private static function setReaction(string $target, int $userId, int $postId, ?int $commentId, string $type): void
    {
        // One reaction per user per target: replace existing.
        Database::delete(
            'post_reactions',
            'user_id = :uid AND post_id = :pid AND ' . ($commentId ? 'comment_id = :cid' : 'comment_id IS NULL'),
            ['uid' => $userId, 'pid' => $postId] + ($commentId ? ['cid' => $commentId] : [])
        );
        Database::insert('post_reactions', [
            'post_id'    => $postId,
            'comment_id' => $commentId,
            'user_id'    => $userId,
            'type'       => $type,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private static function reactionCounts(string $target, int $postId, ?int $commentId): array
    {
        $where = 'post_id = :pid AND ' . ($commentId ? 'comment_id = :cid' : 'comment_id IS NULL');
        $params = ['pid' => $postId] + ($commentId ? ['cid' => $commentId] : []);
        $rows = Database::all(
            "SELECT type, COUNT(*) AS n FROM post_reactions WHERE $where GROUP BY type",
            $params
        );
        $counts = array_fill_keys(self::allowedReactionTypes(), 0);
        foreach ($rows as $row) {
            $counts[$row['type']] = (int) $row['n'];
        }
        $counts['total'] = array_sum($counts);
        return $counts;
    }

    // ------------------------------------------------------------------
    // Sharing, saves, follows
    // ------------------------------------------------------------------

    /**
     * Record a share. External shares return the public share URL — it
     * exposes only the public post page, never private profile data.
     */
    public static function share(int $userId, int $postId, string $channel = 'internal'): string
    {
        $post = Database::one("SELECT * FROM community_posts WHERE id = :id AND status = 'published'", ['id' => $postId]);
        if (!$post) {
            throw new \InvalidArgumentException('Post not found.');
        }
        Database::insert('post_shares', [
            'post_id'    => $postId,
            'user_id'    => $userId,
            'channel'    => in_array($channel, ['internal', 'copy_link', 'whatsapp', 'facebook', 'twitter', 'other'], true) ? $channel : 'other',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return url('/community/post/' . $postId);
    }

    public static function shareCount(int $postId): int
    {
        return (int) Database::scalar('SELECT COUNT(*) FROM post_shares WHERE post_id = :pid', ['pid' => $postId]);
    }

    public static function save(int $userId, int $postId): bool
    {
        $exists = Database::one('SELECT 1 FROM saved_posts WHERE user_id = :u AND post_id = :p', ['u' => $userId, 'p' => $postId]);
        if (!$exists) {
            Database::insert('saved_posts', ['user_id' => $userId, 'post_id' => $postId, 'created_at' => date('Y-m-d H:i:s')]);
        }
        return true;
    }

    public static function unsave(int $userId, int $postId): bool
    {
        Database::delete('saved_posts', 'user_id = :u AND post_id = :p', ['u' => $userId, 'p' => $postId]);
        return true;
    }

    public static function savedPosts(int $userId): array
    {
        $rows = Database::all(
            "SELECT p.* FROM saved_posts s JOIN community_posts p ON p.id = s.post_id
              WHERE s.user_id = :uid AND p.status = 'published' ORDER BY s.created_at DESC LIMIT 100",
            ['uid' => $userId]
        );
        return array_map(static fn ($row) => self::postView($userId, $row), $rows);
    }

    public static function follow(int $userId, int $targetId): bool
    {
        if ($userId === $targetId) {
            return false;
        }
        $target = Database::one('SELECT id FROM users WHERE id = :id AND deleted_at IS NULL', ['id' => $targetId]);
        if (!$target) {
            throw new \InvalidArgumentException('Member not found.');
        }
        $exists = Database::one('SELECT 1 FROM follows WHERE follower_id = :a AND followee_id = :b', ['a' => $userId, 'b' => $targetId]);
        if (!$exists) {
            Database::insert('follows', ['follower_id' => $userId, 'followee_id' => $targetId, 'created_at' => date('Y-m-d H:i:s')]);
            NotificationService::notify($targetId, 'follow', 'You have a new follower', '', ['user_id' => $userId]);
        }
        return true;
    }

    public static function unfollow(int $userId, int $targetId): bool
    {
        Database::delete('follows', 'follower_id = :a AND followee_id = :b', ['a' => $userId, 'b' => $targetId]);
        return true;
    }

    public static function isFollowing(int $userId, int $targetId): bool
    {
        return (bool) Database::scalar(
            'SELECT COUNT(*) FROM follows WHERE follower_id = :a AND followee_id = :b',
            ['a' => $userId, 'b' => $targetId]
        );
    }

    // ------------------------------------------------------------------
    // Search, trending, topics
    // ------------------------------------------------------------------

    public static function search(int $viewerId, string $query, string $tab = 'posts'): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        $like = '%' . $query . '%';
        if ($tab === 'members') {
            $rows = Database::all(
                "SELECT u.id, u.uuid, p.display_name, p.bio, p.location_city,
                        (SELECT path FROM profile_photos pp WHERE pp.user_id = u.id AND pp.is_avatar = 1 AND pp.moderation_status = 'approved' ORDER BY pp.position ASC LIMIT 1) AS avatar,
                        (SELECT COUNT(*) FROM follows f WHERE f.followee_id = u.id) AS followers
                   FROM users u JOIN profiles p ON p.user_id = u.id
                  WHERE u.status = 'active' AND (p.display_name LIKE :q OR p.bio LIKE :q2 OR p.location_city LIKE :q3)
                  ORDER BY followers DESC LIMIT 50",
                ['q' => $like, 'q2' => $like, 'q3' => $like]
            );
            return array_map(static function ($row) use ($viewerId) {
                return [
                    'id' => (int) $row['id'],
                    'uuid' => $row['uuid'],
                    'display_name' => $row['display_name'],
                    'bio' => $row['bio'],
                    'location_city' => $row['location_city'],
                    'avatar' => $row['avatar'] ? '/uploads/' . $row['avatar'] : null,
                    'followers' => (int) $row['followers'],
                    'is_following' => self::isFollowing($viewerId, (int) $row['id']),
                ];
            }, $rows);
        }
        if ($tab === 'hashtags') {
            $rows = Database::all(
                "SELECT p.hashtags FROM community_posts p
                  WHERE p.status = 'published' AND p.hashtags LIKE :q
                  ORDER BY p.created_at DESC LIMIT 200",
                ['q' => $like]
            );
            $counts = [];
            foreach ($rows as $row) {
                foreach (json_decode((string) $row['hashtags'], true) ?: [] as $tag) {
                    $tag = strtolower($tag);
                    $counts[$tag] = ($counts[$tag] ?? 0) + 1;
                }
            }
            arsort($counts);
            $out = [];
            foreach (array_slice($counts, 0, 30, true) as $tag => $n) {
                $out[] = ['tag' => $tag, 'posts' => $n];
            }
            return $out;
        }
        // posts
        $rows = Database::all(
            "SELECT p.* FROM community_posts p
              WHERE p.status = 'published' AND (p.body LIKE :q OR p.topic LIKE :q2)
              ORDER BY p.created_at DESC LIMIT 50",
            ['q' => $like, 'q2' => $like]
        );
        return array_map(static fn ($row) => self::postView($viewerId, $row), $rows);
    }

    public static function trending(int $viewerId, int $hours = 24): array
    {
        $since = date('Y-m-d H:i:s', time() - $hours * 3600);
        // Trending hashtags by post volume in the window.
        $rows = Database::all(
            "SELECT hashtags, (SELECT COUNT(*) FROM post_reactions pr WHERE pr.post_id = community_posts.id) AS reactions,
                    (SELECT COUNT(*) FROM post_comments pc WHERE pc.post_id = community_posts.id) AS comments
               FROM community_posts
              WHERE status = 'published' AND created_at >= :since AND hashtags IS NOT NULL
              ORDER BY created_at DESC LIMIT 300",
            ['since' => $since]
        );
        $tagScore = [];
        foreach ($rows as $row) {
            $weight = 1 + (int) $row['reactions'] * 2 + (int) $row['comments'] * 3;
            foreach (json_decode((string) $row['hashtags'], true) ?: [] as $tag) {
                $tag = strtolower($tag);
                $tagScore[$tag] = ($tagScore[$tag] ?? 0) + $weight;
            }
        }
        arsort($tagScore);
        $out = [];
        foreach (array_slice($tagScore, 0, 12, true) as $tag => $score) {
            $out[] = ['tag' => $tag, 'score' => $score];
        }
        return $out;
    }

    public static function topics(): array
    {
        $rows = Database::all(
            "SELECT topic, COUNT(*) AS posts FROM community_posts
              WHERE status = 'published' AND topic IS NOT NULL AND topic <> ''
              GROUP BY topic ORDER BY posts DESC LIMIT 30"
        );
        return array_map(static fn ($row) => ['topic' => $row['topic'], 'posts' => (int) $row['posts']], $rows);
    }

    // ------------------------------------------------------------------
    // Reports & moderation hooks
    // ------------------------------------------------------------------

    public static function reportPost(int $reporterId, int $postId, string $type, string $reason, string $details = ''): int
    {
        $post = Database::one('SELECT id, user_id FROM community_posts WHERE id = :id', ['id' => $postId]);
        if (!$post) {
            throw new \InvalidArgumentException('Post not found.');
        }
        return self::createReport($reporterId, (int) $post['user_id'], $postId, null, $type, $reason, $details);
    }

    public static function reportComment(int $reporterId, int $commentId, string $type, string $reason, string $details = ''): int
    {
        $comment = Database::one('SELECT id, user_id, post_id FROM post_comments WHERE id = :id', ['id' => $commentId]);
        if (!$comment) {
            throw new \InvalidArgumentException('Comment not found.');
        }
        return self::createReport($reporterId, (int) $comment['user_id'], (int) $comment['post_id'], $commentId, $type, $reason, $details);
    }

    private static function createReport(int $reporterId, int $reportedUserId, ?int $postId, ?int $commentId, string $type, string $reason, string $details): int
    {
        $allowed = ['spam', 'harassment', 'nudity', 'fake_profile', 'scam', 'underage', 'impersonation', 'hate_speech', 'other'];
        if (!in_array($type, $allowed, true)) {
            $type = 'other';
        }
        $reportId = (int) Database::insert('user_reports', [
            'reporter_id'      => $reporterId,
            'reported_user_id' => $reportedUserId,
            'post_id'          => $postId,
            'comment_id'       => $commentId,
            'type'             => $type,
            'reason'           => substr($reason, 0, 255),
            'details'          => $details !== '' ? substr($details, 0, 2000) : null,
            'status'           => 'open',
            'created_at'       => date('Y-m-d H:i:s'),
        ]);
        AuditLog::record($reporterId, 'report.create', 'user_report', (string) $reportId, ['type' => $type]);
        // Notify moderators.
        $mods = Database::all(
            'SELECT DISTINCT ur.user_id FROM user_roles ur
               JOIN role_permissions rp ON rp.role_id = ur.role_id
               JOIN permissions p ON p.id = rp.permission_id
              WHERE p.slug = :slug',
            ['slug' => 'moderation.review']
        );
        foreach ($mods as $mod) {
            NotificationService::notify((int) $mod['user_id'], 'moderation', 'New report in the moderation queue', $reason, ['report_id' => $reportId]);
        }
        return $reportId;
    }

    // ------------------------------------------------------------------
    // Views
    // ------------------------------------------------------------------

    public static function postView(int $viewerId, array $post): array
    {
        $author = Database::one('SELECT id, uuid FROM users WHERE id = :id', ['id' => $post['user_id']]);
        $profile = Database::one('SELECT display_name, verified_at FROM profiles WHERE user_id = :id', ['id' => $post['user_id']]);
        $avatar = Database::scalar(
            "SELECT path FROM profile_photos WHERE user_id = :id AND is_avatar = 1 AND moderation_status = 'approved' ORDER BY position ASC LIMIT 1",
            ['id' => $post['user_id']]
        );
        $reactions = self::reactionCounts('post', (int) $post['id'], null);
        $myReaction = Database::scalar(
            'SELECT type FROM post_reactions WHERE post_id = :pid AND user_id = :uid AND comment_id IS NULL',
            ['pid' => $post['id'], 'uid' => $viewerId]
        );
        $commentCount = (int) Database::scalar(
            "SELECT COUNT(*) FROM post_comments WHERE post_id = :pid AND status = 'published'",
            ['pid' => $post['id']]
        );
        $saved = (bool) Database::scalar(
            'SELECT COUNT(*) FROM saved_posts WHERE post_id = :pid AND user_id = :uid',
            ['pid' => $post['id'], 'uid' => $viewerId]
        );
        return [
            'id'               => (int) $post['id'],
            'type'             => $post['type'],
            'body'             => $post['body'],
            'media_url'        => $post['media_path'] ? '/uploads/' . $post['media_path'] : null,
            'media_mime'       => $post['media_mime'],
            'link_url'         => $post['link_url'],
            'visibility'       => $post['visibility'],
            'topic'            => $post['topic'],
            'hashtags'         => json_decode((string) ($post['hashtags'] ?? ''), true) ?: [],
            'is_announcement'  => (bool) $post['is_announcement'],
            'is_pinned'        => (bool) $post['is_pinned'],
            'status'           => $post['status'],
            'author'           => [
                'id' => (int) $post['user_id'],
                'uuid' => $author['uuid'] ?? null,
                'display_name' => $profile['display_name'] ?? null,
                'avatar' => $avatar ? '/uploads/' . $avatar : null,
                'verified' => !empty($profile['verified_at']),
            ],
            'reactions'        => $reactions,
            'my_reaction'      => $myReaction,
            'comment_count'    => $commentCount,
            'share_count'      => self::shareCount((int) $post['id']),
            'saved'            => $saved,
            'share_url'        => url('/community/post/' . $post['id']),
            'created_at'       => $post['created_at'],
            'updated_at'       => $post['updated_at'],
        ];
    }

    public static function commentView(int $viewerId, array $comment): array
    {
        $profile = Database::one('SELECT display_name FROM profiles WHERE user_id = :id', ['id' => $comment['user_id']]);
        $avatar = Database::scalar(
            "SELECT path FROM profile_photos WHERE user_id = :id AND is_avatar = 1 AND moderation_status = 'approved' ORDER BY position ASC LIMIT 1",
            ['id' => $comment['user_id']]
        );
        $reactions = self::reactionCounts('comment', (int) $comment['post_id'], (int) $comment['id']);
        $myReaction = Database::scalar(
            'SELECT type FROM post_reactions WHERE comment_id = :cid AND user_id = :uid',
            ['cid' => $comment['id'], 'uid' => $viewerId]
        );
        return [
            'id'          => (int) $comment['id'],
            'post_id'     => (int) $comment['post_id'],
            'parent_id'   => $comment['parent_id'] !== null ? (int) $comment['parent_id'] : null,
            'body'        => $comment['body'],
            'author'      => [
                'id' => (int) $comment['user_id'],
                'display_name' => $profile['display_name'] ?? null,
                'avatar' => $avatar ? '/uploads/' . $avatar : null,
            ],
            'reactions'   => $reactions,
            'my_reaction' => $myReaction,
            'created_at'  => $comment['created_at'],
        ];
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** Extract #hashtags from text. */
    public static function extractHashtags(string $text): array
    {
        preg_match_all('/#([\p{L}\p{N}_]{2,40})/u', $text, $m);
        $tags = array_map(static fn ($t) => mb_strtolower($t), $m[1] ?? []);
        return array_values(array_unique($tags));
    }

    /** Extract @mentions and notify those members. */
    private static function notifyMentions(int $authorId, int $postId, string $text, string $kind = 'post'): void
    {
        preg_match_all('/@([\p{L}\p{N}_]{2,40})/u', $text, $m);
        $names = array_unique($m[1] ?? []);
        foreach ($names as $name) {
            $member = Database::one(
                'SELECT u.id FROM users u JOIN profiles p ON p.user_id = u.id WHERE u.status = \'active\' AND LOWER(p.display_name) = :name LIMIT 1',
                ['name' => mb_strtolower($name)]
            );
            if ($member && (int) $member['id'] !== $authorId) {
                NotificationService::notify(
                    (int) $member['id'],
                    'mention',
                    'You were mentioned in a ' . $kind,
                    mb_substr($text, 0, 120),
                    ['post_id' => $postId]
                );
            }
        }
    }
}
