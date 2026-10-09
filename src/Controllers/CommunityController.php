<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AdServer;
use App\Core\ApiException;
use App\Core\Audit;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Features;
use App\Core\Notifier;
use App\Core\RateLimiter;
use App\Core\Rbac;
use App\Core\Request;
use App\Core\Response;
use App\Core\SecureStorage;
use App\Core\Settings;
use App\Core\Env;
use App\Core\Validator;

/**
 * Facebook-style community. It is independent of matchmaking: members with pending or
 * rejected profiles can still post, react, follow and share. Community screens show only
 * usernames, never matchmaking profile details.
 */
final class CommunityController
{
    private const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];
    private const VIDEO_MIMES = ['video/mp4', 'video/webm', 'video/quicktime'];

    /** Visibility policy for post alias $p with author alias u. Returns [sql, params]. */
    private static function visible(int $uid, string $p = 'p'): array
    {
        $sql = "$p.status = 'published' AND $p.deleted_at IS NULL AND u.status = 'active' AND (
                  $p.visibility = 'public' OR $p.author_id = ?
                  OR ($p.visibility = 'followers' AND EXISTS (SELECT 1 FROM follows f WHERE f.follower_id = ? AND f.followee_id = $p.author_id))
                ) AND NOT EXISTS (SELECT 1 FROM blocks b WHERE (b.blocker_id = ? AND b.blocked_id = $p.author_id)
                                  OR (b.blocker_id = $p.author_id AND b.blocked_id = ?))";
        return [$sql, [$uid, $uid, $uid, $uid]];
    }

    // ----------------------------------------------------------------- feed
    public function feed(Request $r): array
    {
        Features::require('social.feed');
        $uid = $r->userId();
        $before = max(0, (int) $r->query('before', 0));
        $tag = $r->query('tag') ? strtolower(Validator::str($r->query('tag'), 'tag', 2, 50, false) ?? '') : '';
        [$visSql, $visParams] = self::visible($uid);

        $sql = "SELECT p.id, p.author_id, u.username, p.body, p.visibility, p.media_type, p.storage_key IS NOT NULL AS has_media,
                       p.shared_post_id, p.reaction_count, p.comment_count, p.share_count, p.created_at,
                       (SELECT pr.reaction FROM post_reactions pr WHERE pr.post_id = p.id AND pr.user_id = ?) AS my_reaction,
                       EXISTS (SELECT 1 FROM post_saves ps WHERE ps.post_id = p.id AND ps.user_id = ?) AS saved
                FROM community_posts p JOIN users u ON u.id = p.author_id
                WHERE {$visSql}";
        $params = [$uid, $uid, ...$visParams];
        if ($before > 0) {
            $sql .= ' AND p.id < ?';
            $params[] = $before;
        }
        if ($tag !== '') {
            $sql .= ' AND EXISTS (SELECT 1 FROM post_hashtags ph JOIN hashtags h ON h.id = ph.hashtag_id WHERE ph.post_id = p.id AND h.tag = ?)';
            $params[] = $tag;
        }
        $sql .= ' ORDER BY p.id DESC LIMIT 20';

        $items = [];
        foreach (Database::all($sql, $params) as $row) {
            $items[] = ['type' => 'post', 'post' => $this->format($row, $uid)];
        }
        // Sponsored placement: at most one advert per page, always labelled as sponsored.
        if ($tag === '' && count($items) >= 4) {
            $ad = AdServer::pick($uid, 'community_feed');
            if ($ad !== null) {
                array_splice($items, 3, 0, [['type' => 'ad', 'ad' => $ad]]);
            }
        }
        return ['items' => $items, 'next_before' => $items === [] ? null : $this->lastPostId($items)];
    }

    public function show(Request $r): array
    {
        Features::require('social.feed');
        $uid = $r->userId();
        $post = $this->visiblePost((int) $r->param('id'), $uid);
        return ['post' => $this->format($post, $uid), 'comments_url' => '/api/community/posts/' . $post['id'] . '/comments'];
    }

    public function create(Request $r): array
    {
        Features::require('social.feed');
        $uid = $r->userId();
        RateLimiter::hit('post:create:' . $uid, 30, 3600);
        $max = Settings::getInt('community.max_post_chars', 2000);
        $body = Validator::str($r->input('body'), 'body', 0, $max, false);
        $visibility = Validator::in($r->input('visibility', 'public'), 'visibility', ['public', 'followers', 'private']);

        $file = $r->files['media'] ?? null;
        $hasFile = is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        if (($body === null || $body === '') && !$hasFile) {
            Validator::fail('body', 'Write something or attach a photo or video.');
        }
        $key = null;
        $mediaType = null;
        $mime = null;
        $size = null;
        if ($hasFile) {
            $upload = SecureStorage::inspectUpload($file, [...self::IMAGE_MIMES, ...self::VIDEO_MIMES], 50 * 1048576, 'media');
            $mediaType = in_array($upload['mime'], self::IMAGE_MIMES, true) ? 'image' : 'video';
            if ($mediaType === 'image' && $upload['size'] > 8 * 1048576) {
                Validator::fail('media', 'Images must be 8 MB or smaller.');
            }
            $key = SecureStorage::putFile('posts', $upload['tmp']);
            $mime = $upload['mime'];
            $size = $upload['size'];
        }

        $id = Database::transaction(function () use ($uid, $body, $visibility, $key, $mediaType, $mime, $size): int {
            $id = Database::insert(
                'INSERT INTO community_posts (author_id, body, visibility, storage_key, media_type, media_mime, media_size_bytes)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$uid, $body, $visibility, $key, $mediaType, $mime, $size]
            );
            foreach (self::hashtagsIn($body ?? '') as $tag) {
                Database::exec('INSERT IGNORE INTO hashtags (tag) VALUES (?)', [$tag]);
                Database::exec(
                    'INSERT IGNORE INTO post_hashtags (post_id, hashtag_id) SELECT ?, id FROM hashtags WHERE tag = ?',
                    [$id, $tag]
                );
            }
            return $id;
        });
        $this->notifyMentions($body ?? '', $uid, $id);
        Audit::log($uid, 'post.created', 'post', $id, null, null, ['visibility' => $visibility]);
        Response::status(201);
        return ['post' => $this->format($this->visiblePost($id, $uid), $uid)];
    }

    public function delete(Request $r): array
    {
        $uid = $r->userId();
        $post = Database::one('SELECT * FROM community_posts WHERE id = ? AND deleted_at IS NULL', [(int) $r->param('id')]);
        if ($post === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Post not found.');
        }
        $isModerator = Rbac::can($uid, 'community.moderate');
        if ((int) $post['author_id'] !== $uid && !$isModerator) {
            throw new ApiException(403, 'FORBIDDEN', 'You can only delete your own posts.');
        }
        Database::exec("UPDATE community_posts SET status = 'removed', deleted_at = NOW() WHERE id = ?", [$post['id']]);
        SecureStorage::secureDelete('posts', $post['storage_key']);
        if ((int) $post['author_id'] !== $uid) {
            Audit::log($uid, 'post.removed', 'post', $post['id']);
        }
        return ['ok' => true];
    }

    public function media(Request $r): ?array
    {
        Features::require('social.feed');
        $post = $this->visiblePost((int) $r->param('id'), $r->userId());
        if ($post['storage_key'] === null) {
            throw new ApiException(404, 'NOT_FOUND', 'No media on this post.');
        }
        Response::binary(SecureStorage::get('posts', $post['storage_key']), $post['media_mime'], 'inline', 'post-media');
        return null;
    }

    // ------------------------------------------------------- reactions/comments
    public function react(Request $r): array
    {
        Features::require('social.reactions');
        $uid = $r->userId();
        $post = $this->visiblePost((int) $r->param('id'), $uid);
        $reaction = Validator::in($r->input('reaction', 'like'), 'reaction', ['like', 'love', 'haha', 'wow', 'sad', 'angry']);
        Database::exec(
            'INSERT INTO post_reactions (post_id, user_id, reaction) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE reaction = VALUES(reaction), created_at = NOW()',
            [$post['id'], $uid, $reaction]
        );
        $this->recount((int) $post['id'], 'reaction_count', 'post_reactions');
        if ((int) $post['author_id'] !== $uid) {
            Notifier::notify((int) $post['author_id'], 'reaction', 'New reaction', 'Someone reacted to your post.', ['post_id' => $post['id']]);
        }
        return ['reaction' => $reaction];
    }

    public function unreact(Request $r): array
    {
        $uid = $r->userId();
        $post = $this->visiblePost((int) $r->param('id'), $uid);
        Database::exec('DELETE FROM post_reactions WHERE post_id = ? AND user_id = ?', [$post['id'], $uid]);
        $this->recount((int) $post['id'], 'reaction_count', 'post_reactions');
        return ['ok' => true];
    }

    public function comments(Request $r): array
    {
        Features::require('social.reactions');
        $uid = $r->userId();
        $post = $this->visiblePost((int) $r->param('id'), $uid);
        $rows = Database::all(
            "SELECT c.id, c.parent_id, c.body, c.created_at, u.id AS author_id, u.username
             FROM post_comments c JOIN users u ON u.id = c.author_id
             WHERE c.post_id = ? AND c.status = 'published' AND u.status = 'active'
               AND NOT EXISTS (SELECT 1 FROM blocks b WHERE (b.blocker_id = ? AND b.blocked_id = c.author_id) OR (b.blocker_id = c.author_id AND b.blocked_id = ?))
             ORDER BY c.id ASC LIMIT 200",
            [$post['id'], $uid, $uid]
        );
        return ['items' => $rows];
    }

    /** Replies are limited to one level: a reply to a reply attaches to the top-level comment. */
    public function comment(Request $r): array
    {
        Features::require('social.reactions');
        $uid = $r->userId();
        $post = $this->visiblePost((int) $r->param('id'), $uid);
        $body = Validator::str($r->input('body'), 'body', 1, 1000);
        $parentId = $r->input('parent_id') ? Validator::int($r->input('parent_id'), 'parent_id', 1, PHP_INT_MAX) : null;
        if ($parentId !== null) {
            $parent = Database::one('SELECT id, parent_id FROM post_comments WHERE id = ? AND post_id = ? AND status = ?', [$parentId, $post['id'], 'published']);
            if ($parent === null) {
                throw new ApiException(422, 'INVALID_PARENT', 'The comment you are replying to is not available.');
            }
            $parentId = $parent['parent_id'] !== null ? (int) $parent['parent_id'] : (int) $parent['id'];
        }
        RateLimiter::hit('comment:' . $uid, 60, 3600);
        $id = Database::transaction(function () use ($post, $uid, $body, $parentId): int {
            $id = Database::insert('INSERT INTO post_comments (post_id, author_id, parent_id, body) VALUES (?, ?, ?, ?)',
                [$post['id'], $uid, $parentId, $body]);
            $this->recount((int) $post['id'], 'comment_count', 'post_comments', "status = 'published'");
            return $id;
        });
        if ((int) $post['author_id'] !== $uid) {
            Notifier::notify((int) $post['author_id'], 'comment', 'New comment', 'Someone commented on your post.', ['post_id' => $post['id']]);
        }
        $this->notifyMentions($body, $uid, (int) $post['id']);
        Response::status(201);
        return ['id' => $id];
    }

    public function deleteComment(Request $r): array
    {
        $uid = $r->userId();
        $comment = Database::one('SELECT * FROM post_comments WHERE id = ?', [(int) $r->param('id')]);
        if ($comment === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Comment not found.');
        }
        if ((int) $comment['author_id'] !== $uid && !Rbac::can($uid, 'community.moderate')) {
            throw new ApiException(403, 'FORBIDDEN', 'You can only delete your own comments.');
        }
        Database::exec("UPDATE post_comments SET status = 'removed' WHERE id = ?", [$comment['id']]);
        $this->recount((int) $comment['post_id'], 'comment_count', 'post_comments', "status = 'published'");
        return ['ok' => true];
    }

    // -------------------------------------------------------------- sharing
    /** Internal reshare, or a shareable link / native share for public posts only. */
    public function share(Request $r): array
    {
        Features::require('social.sharing');
        $uid = $r->userId();
        $post = $this->visiblePost((int) $r->param('id'), $uid);
        $channel = Validator::in($r->input('channel', 'internal'), 'channel', ['internal', 'link', 'native']);
        if ($channel === 'internal') {
            $note = Validator::str($r->input('body'), 'body', 0, 500, false);
            $id = Database::transaction(function () use ($uid, $post, $note): int {
                $id = Database::insert(
                    "INSERT INTO community_posts (author_id, body, visibility, shared_post_id) VALUES (?, ?, ?, ?)",
                    [$uid, $note, $post['visibility'], $post['id']]
                );
                Database::exec('INSERT INTO post_shares (post_id, user_id, channel) VALUES (?, ?, ?)', [$post['id'], $uid, 'internal']);
                $this->recount((int) $post['id'], 'share_count', 'post_shares');
                return $id;
            });
            if ((int) $post['author_id'] !== $uid) {
                Notifier::notify((int) $post['author_id'], 'share', 'Your post was shared', null, ['post_id' => $post['id']]);
            }
            Response::status(201);
            return ['id' => $id, 'channel' => 'internal'];
        }

        // External sharing exposes only public posts, never private or follower-only content.
        if ($post['visibility'] !== 'public') {
            throw new ApiException(403, 'SHARE_NOT_ALLOWED', 'Only public posts can be shared outside the platform.');
        }
        if (empty($post['share_token'])) {
            Database::exec('UPDATE community_posts SET share_token = ? WHERE id = ? AND share_token IS NULL', [bin2hex(random_bytes(16)), $post['id']]);
            $post['share_token'] = Database::value('SELECT share_token FROM community_posts WHERE id = ?', [$post['id']]);
        }
        Database::exec('INSERT INTO post_shares (post_id, user_id, channel) VALUES (?, ?, ?)', [$post['id'], $uid, $channel]);
        $this->recount((int) $post['id'], 'share_count', 'post_shares');
        $url = Env::appUrl() . '/share/' . $post['share_token'];
        $excerpt = mb_substr((string) $post['body'], 0, 140);
        return [
            'share_url' => $url,
            'native_share' => ['title' => 'Post by @' . $post['username'], 'text' => $excerpt, 'url' => $url],
        ];
    }

    /** Public, unauthenticated view of a shared link: body and username only. */
    public function sharedView(Request $r): array
    {
        Features::require('social.sharing');
        RateLimiter::hit('share:view:' . $r->ip, 120, 3600);
        $post = Database::one(
            "SELECT p.body, p.created_at, u.username FROM community_posts p JOIN users u ON u.id = p.author_id
             WHERE p.share_token = ? AND p.visibility = 'public' AND p.status = 'published' AND p.deleted_at IS NULL AND u.status = 'active'",
            [$r->param('token')]
        );
        if ($post === null) {
            throw new ApiException(404, 'NOT_FOUND', 'This shared post is no longer available.');
        }
        return ['post' => ['body' => $post['body'], 'author' => $post['username'], 'created_at' => $post['created_at']]];
    }

    public function save(Request $r): array
    {
        $uid = $r->userId();
        $post = $this->visiblePost((int) $r->param('id'), $uid);
        Database::exec('INSERT IGNORE INTO post_saves (user_id, post_id) VALUES (?, ?)', [$uid, $post['id']]);
        return ['ok' => true];
    }

    public function unsave(Request $r): array
    {
        Database::exec('DELETE FROM post_saves WHERE user_id = ? AND post_id = ?', [$r->userId(), (int) $r->param('id')]);
        return ['ok' => true];
    }

    public function saved(Request $r): array
    {
        $uid = $r->userId();
        [$visSql, $visParams] = self::visible($uid);
        $rows = Database::all(
            "SELECT p.id, p.author_id, u.username, p.body, p.visibility, p.media_type, p.storage_key IS NOT NULL AS has_media,
                    p.shared_post_id, p.reaction_count, p.comment_count, p.share_count, p.created_at,
                    NULL AS my_reaction, 1 AS saved
             FROM post_saves ps JOIN community_posts p ON p.id = ps.post_id JOIN users u ON u.id = p.author_id
             WHERE ps.user_id = ? AND {$visSql} ORDER BY ps.created_at DESC LIMIT 50",
            [$uid, ...$visParams]
        );
        return ['items' => array_map(fn($row) => $this->format($row, $uid), $rows)];
    }

    // ------------------------------------------------------- social graph
    public function follow(Request $r): array
    {
        $uid = $r->userId();
        $target = Validator::int($r->input('user_id'), 'user_id', 1, PHP_INT_MAX);
        if ($target === $uid) {
            Validator::fail('user_id', 'You cannot follow yourself.');
        }
        $exists = Database::value("SELECT 1 FROM users WHERE id = ? AND status = 'active' AND deleted_at IS NULL", [$target]);
        if (!$exists || Database::value('SELECT 1 FROM blocks WHERE (blocker_id = ? AND blocked_id = ?) OR (blocker_id = ? AND blocked_id = ?)', [$uid, $target, $target, $uid])) {
            throw new ApiException(404, 'NOT_FOUND', 'Member not found.');
        }
        $inserted = Database::exec('INSERT IGNORE INTO follows (follower_id, followee_id) VALUES (?, ?)', [$uid, $target]);
        if ($inserted > 0) {
            Notifier::notify($target, 'follow', 'New follower', 'Someone started following you.');
        }
        return ['ok' => true, 'following' => true];
    }

    public function unfollow(Request $r): array
    {
        Database::exec('DELETE FROM follows WHERE follower_id = ? AND followee_id = ?', [$r->userId(), (int) $r->param('id')]);
        return ['ok' => true, 'following' => false];
    }

    // ------------------------------------------------- discovery & search
    public function trending(Request $r): array
    {
        Features::require('social.feed');
        $rows = Database::all(
            "SELECT h.tag, COUNT(*) AS posts FROM post_hashtags ph
             JOIN hashtags h ON h.id = ph.hashtag_id
             JOIN community_posts p ON p.id = ph.post_id
             WHERE p.created_at > NOW() - INTERVAL 1 DAY AND p.status = 'published' AND p.visibility = 'public' AND p.deleted_at IS NULL
             GROUP BY h.tag ORDER BY posts DESC, h.tag ASC LIMIT 10"
        );
        return ['items' => $rows];
    }

    public function search(Request $r): array
    {
        Features::require('social.feed');
        $uid = $r->userId();
        $q = Validator::str($r->query('q'), 'q', 2, 100);
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        [$visSql, $visParams] = self::visible($uid);
        $posts = Database::all(
            "SELECT p.id, p.author_id, u.username, p.body, p.visibility, p.media_type, p.storage_key IS NOT NULL AS has_media,
                    p.shared_post_id, p.reaction_count, p.comment_count, p.share_count, p.created_at,
                    NULL AS my_reaction, 0 AS saved
             FROM community_posts p JOIN users u ON u.id = p.author_id
             WHERE {$visSql} AND p.body LIKE ? ORDER BY p.id DESC LIMIT 20",
            [...$visParams, $like]
        );
        $tags = Database::all(
            "SELECT h.tag, COUNT(ph.post_id) AS posts FROM hashtags h JOIN post_hashtags ph ON ph.hashtag_id = h.id
             WHERE h.tag LIKE ? GROUP BY h.tag ORDER BY posts DESC LIMIT 10",
            [strtolower(str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], ltrim($q, '#'))) . '%']
        );
        return ['posts' => array_map(fn($row) => $this->format($row, $uid), $posts), 'tags' => $tags];
    }

    public function rules(Request $r): array
    {
        return [
            'rules' => Settings::get('community.rules', ''),
            'announcements' => Database::all("SELECT id, title, body, published_at FROM announcements WHERE status = 'published' ORDER BY published_at DESC LIMIT 10"),
        ];
    }

    // -------------------------------------------------------- notifications
    public function notifications(Request $r): array
    {
        $uid = $r->userId();
        $rows = Database::all(
            'SELECT id, type, title, body, data, read_at, created_at FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 50',
            [$uid]
        );
        $unread = (int) Database::value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$uid]);
        return ['items' => array_map(static function ($n) {
            $n['data'] = $n['data'] ? json_decode((string) $n['data'], true) : null;
            return $n;
        }, $rows), 'unread' => $unread];
    }

    public function markNotificationsRead(Request $r): array
    {
        Database::exec('UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL', [$r->userId()]);
        return ['ok' => true];
    }

    // ------------------------------------------------------------ helpers
    private function visiblePost(int $id, int $uid): array
    {
        [$visSql, $visParams] = self::visible($uid);
        $post = Database::one("SELECT p.*, u.username FROM community_posts p JOIN users u ON u.id = p.author_id WHERE p.id = ? AND {$visSql}", [$id, ...$visParams]);
        if ($post === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Post not found.');
        }
        return $post;
    }

    private function format(array $row, int $uid): array
    {
        $shared = null;
        if (!empty($row['shared_post_id'])) {
            [$visSql, $visParams] = self::visible($uid);
            $orig = Database::one("SELECT p.id, p.body, p.created_at, u.username FROM community_posts p JOIN users u ON u.id = p.author_id WHERE p.id = ? AND {$visSql}",
                [$row['shared_post_id'], ...$visParams]);
            $shared = $orig ? ['id' => (int) $orig['id'], 'body' => $orig['body'], 'author' => $orig['username'], 'created_at' => $orig['created_at']] : ['unavailable' => true];
        }
        $tags = array_column(Database::all(
            'SELECT h.tag FROM post_hashtags ph JOIN hashtags h ON h.id = ph.hashtag_id WHERE ph.post_id = ? ORDER BY h.tag',
            [$row['id']]
        ), 'tag');
        return [
            'id' => (int) $row['id'],
            'author' => ['username' => $row['username'], 'user_id' => (int) $row['author_id'], 'is_me' => (int) $row['author_id'] === $uid],
            'body' => $row['body'],
            'visibility' => $row['visibility'],
            'media' => !empty($row['has_media']) ? ['type' => $row['media_type'], 'url' => '/api/community/posts/' . $row['id'] . '/media'] : null,
            'shared' => $shared,
            'hashtags' => $tags,
            'reaction_count' => (int) ($row['reaction_count'] ?? 0),
            'comment_count' => (int) ($row['comment_count'] ?? 0),
            'share_count' => (int) ($row['share_count'] ?? 0),
            'my_reaction' => $row['my_reaction'] ?? null,
            'saved' => (bool) ($row['saved'] ?? false),
            'created_at' => $row['created_at'],
        ];
    }

    private function lastPostId(array $items): ?int
    {
        for ($i = count($items) - 1; $i >= 0; $i--) {
            if (($items[$i]['type'] ?? '') === 'post') {
                return $items[$i]['post']['id'];
            }
        }
        return null;
    }

    /** Recomputes denormalised counters from source rows. */
    private function recount(int $postId, string $column, string $table, string $where = '1=1'): void
    {
        Database::exec("UPDATE community_posts SET {$column} = (SELECT COUNT(*) FROM {$table} WHERE post_id = ? AND {$where}) WHERE id = ?", [$postId, $postId]);
    }

    /** @return string[] lower-cased unique hashtags */
    public static function hashtagsIn(string $text): array
    {
        preg_match_all('/#([A-Za-z0-9_]{2,50})/u', $text, $m);
        return array_values(array_unique(array_map('strtolower', $m[1] ?? [])));
    }

    private function notifyMentions(string $text, int $authorId, int $postId): void
    {
        preg_match_all('/@([a-z0-9_]{3,30})/i', $text, $m);
        foreach (array_unique(array_map('strtolower', $m[1] ?? [])) as $username) {
            $target = Database::one("SELECT id FROM users WHERE username = ? AND status = 'active'", [$username]);
            if ($target && (int) $target['id'] !== $authorId && !Database::value('SELECT 1 FROM blocks WHERE (blocker_id = ? AND blocked_id = ?) OR (blocker_id = ? AND blocked_id = ?)', [$authorId, $target['id'], $target['id'], $authorId])) {
                Notifier::notify((int) $target['id'], 'mention', 'You were mentioned', null, ['post_id' => $postId]);
            }
        }
    }
}
