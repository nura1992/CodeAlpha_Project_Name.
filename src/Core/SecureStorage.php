<?php
declare(strict_types=1);

namespace App\Core;

use finfo;

/**
 * Protected media storage. Files live OUTSIDE the public web root (STORAGE_PATH),
 * are encrypted at rest, get random names, and are only returned by authorised
 * PHP endpoints. Original filenames are never used.
 */
final class SecureStorage
{
    public const BUCKETS = ['verification', 'photos', 'messages', 'posts', 'ads'];

    public static function root(): string
    {
        $root = rtrim((string) Env::get('STORAGE_PATH', APP_ROOT . '/storage'), '/');
        foreach (array_merge(['private', 'logs'], array_map(static fn($b) => 'private/' . $b, self::BUCKETS)) as $dir) {
            $path = $root . '/' . $dir;
            if (!is_dir($path)) {
                mkdir($path, 0700, true);
            }
        }
        return $root;
    }

    private static function path(string $bucket, string $key): string
    {
        if (!in_array($bucket, self::BUCKETS, true) || !preg_match('/^[a-f0-9]{48}$/', $key)) {
            throw new ApiException(404, 'NOT_FOUND', 'Media not found.');
        }
        return self::root() . '/private/' . $bucket . '/' . $key;
    }

    /**
     * Validates an uploaded file (real MIME via finfo, not the client header) and returns its metadata.
     * @return array{tmp:string,mime:string,size:int,sha256:string}
     */
    public static function inspectUpload(?array $file, array $allowedMimes, int $maxBytes, string $field): array
    {
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            Validator::fail($field, 'Please choose a file to upload.');
        }
        $tmp = (string) $file['tmp_name'];
        if (!is_uploaded_file($tmp)) {
            Validator::fail($field, 'Invalid upload.');
        }
        $size = (int) filesize($tmp);
        if ($size <= 0 || $size > $maxBytes) {
            Validator::fail($field, 'File is empty or larger than the allowed size (' . (int) ($maxBytes / 1048576) . ' MB).');
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '';
        if (!in_array($mime, $allowedMimes, true)) {
            Validator::fail($field, 'This file type is not allowed.');
        }
        return ['tmp' => $tmp, 'mime' => $mime, 'size' => $size, 'sha256' => hash_file('sha256', $tmp)];
    }

    /** Stores encrypted bytes and returns the random storage key. */
    public static function put(string $bucket, string $bytes): string
    {
        $key = bin2hex(random_bytes(24));
        $path = self::path($bucket, $key);
        if (file_put_contents($path, Crypto::encrypt($bytes, 'storage|' . $bucket), LOCK_EX) === false) {
            throw new \RuntimeException('Unable to write protected media.');
        }
        chmod($path, 0600);
        return $key;
    }

    public static function putFile(string $bucket, string $tmpPath): string
    {
        $bytes = (string) file_get_contents($tmpPath);
        return self::put($bucket, $bytes);
    }

    public static function get(string $bucket, string $key): string
    {
        $path = self::path($bucket, $key);
        if (!is_file($path)) {
            throw new ApiException(410, 'MEDIA_UNAVAILABLE', 'This media is no longer available.');
        }
        return Crypto::decrypt((string) file_get_contents($path), 'storage|' . $bucket);
    }

    /** Overwrites the file with random bytes before unlinking (best-effort secure delete). */
    public static function secureDelete(string $bucket, ?string $key): void
    {
        if ($key === null || $key === '') {
            return;
        }
        $path = self::path($bucket, $key);
        if (!is_file($path)) {
            return;
        }
        $size = (int) filesize($path);
        $fh = fopen($path, 'r+b');
        if ($fh !== false) {
            for ($written = 0; $written < $size; $written += 65536) {
                fwrite($fh, random_bytes(min(65536, $size - $written)));
            }
            fflush($fh);
            fclose($fh);
        }
        unlink($path);
    }

    public static function mimeToExt(string $mime): string
    {
        return ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? 'bin';
    }
}
