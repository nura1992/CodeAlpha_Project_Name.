<?php
/**
 * MediaService — protected media access.
 *
 * Verification videos and chat attachments live outside the web root and
 * are served only through PHP endpoints protected by:
 *   1. a required permission (checked by the controller), and
 *   2. an expiring, HMAC-signed URL (defence in depth).
 *
 * Signed URLs are bound to a purpose and expire quickly. Streaming supports
 * HTTP Range requests so video playback can seek.
 */

declare(strict_types=1);

namespace CodeAlpha\Services;

use CodeAlpha\Core\Database;

final class MediaService
{
    private static function signingKey(): string
    {
        return hash_hkdf('sha256', (string) config('app.key'), 32, 'codealpha-media-signing');
    }

    /**
     * Create a signed URL for a protected media path.
     */
    public static function signedUrl(string $endpoint, string $mediaPath, ?int $ttl = null, array $extra = []): string
    {
        $ttl = $ttl ?? (int) config('verification.signed_url_ttl', 300);
        $expires = time() + $ttl;
        $query = array_merge($extra, [
            'path'    => $mediaPath,
            'expires' => $expires,
        ]);
        $qs = http_build_query($query);
        $signature = hash_hmac('sha256', $endpoint . '?' . $qs, self::signingKey());
        return url($endpoint . '?' . $qs . '&signature=' . $signature);
    }

    /**
     * Validate a signed URL's query parameters.
     *
     * @return array{path:string, expires:int}|null
     */
    public static function validateSignature(string $endpoint, array $query): ?array
    {
        $signature = (string) ($query['signature'] ?? '');
        $path = (string) ($query['path'] ?? '');
        $expires = (int) ($query['expires'] ?? 0);
        if ($signature === '' || $path === '' || $expires <= time()) {
            return null;
        }
        unset($query['signature']);
        ksort($query);
        $qs = http_build_query($query);
        $expected = hash_hmac('sha256', $endpoint . '?' . $qs, self::signingKey());
        if (!hash_equals($expected, $signature)) {
            return null;
        }
        // Path must stay inside the protected storage root.
        $root = realpath((string) config('storage.protected'));
        $full = realpath(dirname((string) config('storage.protected')) . '/' . ltrim($path, '/'));
        if ($root === false || $full === false || !str_starts_with($full, $root)) {
            return null;
        }
        return ['path' => $path, 'expires' => $expires];
    }

    /** Resolve a protected media path to an absolute filesystem path. */
    public static function resolvePath(string $relativePath): ?string
    {
        $root = realpath((string) config('storage.protected'));
        $full = realpath(rtrim((string) config('storage.protected'), '/') . '/' . ltrim($relativePath, '/'));
        if ($root === false || $full === false || !str_starts_with($full, $root)) {
            return null;
        }
        return is_file($full) ? $full : null;
    }

    /** Look up the MIME type for a path. */
    public static function mimeFor(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($ext) {
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'mov' => 'video/quicktime',
            'mkv' => 'video/x-matroska',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'mp3' => 'audio/mpeg',
            'm4a' => 'audio/mp4',
            'ogg' => 'audio/ogg',
            'wav' => 'audio/wav',
            'aac' => 'audio/aac',
            default => 'application/octet-stream',
        };
    }

    /**
     * Enforce retention policy: delete verification media older than the
     * configured retention period. Run from cron (bin/cleanup.php).
     */
    public static function enforceRetention(): int
    {
        $days = (int) config('verification.retention_days', 90);
        $cutoff = date('Y-m-d H:i:s', time() - $days * 86400);
        $deleted = 0;
        $rows = Database::all(
            'SELECT id, media_path FROM verification_requests WHERE created_at < :cutoff',
            ['cutoff' => $cutoff]
        );
        foreach ($rows as $row) {
            $fs = self::resolvePath((string) $row['media_path']);
            if ($fs) {
                \CodeAlpha\Core\Uploader::secureDelete($fs);
                $deleted++;
            }
            Database::update('verification_requests', ['media_path' => ''], 'id = :id', ['id' => $row['id']]);
        }
        return $deleted;
    }
}
