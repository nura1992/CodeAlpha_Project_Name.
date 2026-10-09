<?php
/**
 * Uploader — secure file upload handling.
 *
 * Public media (avatars, community posts) is stored under public/uploads with
 * randomised names. Protected media (verification videos, chat attachments)
 * is stored under storage/protected which is outside the web root and is
 * only ever served through authorised PHP endpoints with signed URLs.
 *
 * Validation: extension whitelist + finfo MIME sniffing + size limits.
 */

declare(strict_types=1);

namespace CodeAlpha\Core;

use RuntimeException;

final class Uploader
{
    private const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    private const IMAGE_EXTS  = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    private const VIDEO_MIMES = ['video/mp4', 'video/webm', 'video/quicktime', 'video/x-matroska'];
    private const VIDEO_EXTS  = ['mp4', 'webm', 'mov', 'mkv'];
    private const AUDIO_MIMES = ['audio/mpeg', 'audio/mp4', 'audio/ogg', 'audio/wav', 'audio/webm', 'audio/aac', 'audio/x-m4a'];
    private const AUDIO_EXTS  = ['mp3', 'm4a', 'ogg', 'wav', 'webm', 'aac'];

    /** Allowed kinds and their [mimes, exts, maxBytes]. */
    private static function kinds(): array
    {
        return [
            'image' => [self::IMAGE_MIMES, self::IMAGE_EXTS, 10 * 1024 * 1024],
            'video' => [self::VIDEO_MIMES, self::VIDEO_EXTS, 100 * 1024 * 1024],
            'audio' => [self::AUDIO_MIMES, self::AUDIO_EXTS, 25 * 1024 * 1024],
        ];
    }

    /**
     * Validate an uploaded file array ($_FILES['x'] style).
     *
     * @return array{ext:string, mime:string, size:int}
     */
    public static function validate(?array $file, string $kind, ?int $maxBytes = null): array
    {
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('No file was uploaded or the upload failed.');
        }
        if (!isset(self::kinds()[$kind])) {
            throw new RuntimeException('Unsupported upload kind.');
        }
        [$mimes, $exts, $defaultMax] = self::kinds()[$kind];
        $max = $maxBytes ?? $defaultMax;

        $size = (int) $file['size'];
        if ($size <= 0 || $size > $max) {
            throw new RuntimeException('File exceeds the maximum allowed size of ' . round($max / 1048576, 1) . ' MB.');
        }

        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $exts, true)) {
            throw new RuntimeException('File type .' . $ext . ' is not allowed.');
        }

        // Sniff the real MIME type from content, not the client-supplied one.
        $mime = 'application/octet-stream';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $sniffed = finfo_file($finfo, (string) $file['tmp_name']);
            finfo_close($finfo);
            if (is_string($sniffed) && $sniffed !== '') {
                $mime = $sniffed;
            }
        } elseif (is_uploaded_file((string) $file['tmp_name'])) {
            $mime = (string) ($file['type'] ?? $mime);
        }
        if (!in_array($mime, $mimes, true)) {
            throw new RuntimeException('File content type ' . $mime . ' is not allowed.');
        }

        return ['ext' => $ext, 'mime' => $mime, 'size' => $size];
    }

    /**
     * Move an uploaded file into a destination directory with a random name.
     *
     * @return string relative path (from the destination dir) of the stored file
     */
    public static function store(array $file, string $destDir, string $ext, string $prefix = ''): string
    {
        if (!is_dir($destDir)) {
            mkdir($destDir, 0770, true);
        }
        $relative = date('Y/m');
        $targetDir = rtrim($destDir, '/') . '/' . $relative;
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0770, true);
        }
        $name = ($prefix !== '' ? $prefix . '_' : '') . bin2hex(random_bytes(12)) . '.' . $ext;
        $target = $targetDir . '/' . $name;
        if (!move_uploaded_file((string) $file['tmp_name'], $target)) {
            // Fallback for non-HTTP contexts (CLI, tests).
            if (!@rename((string) $file['tmp_name'], $target)) {
                throw new RuntimeException('Failed to store the uploaded file.');
            }
        }
        @chmod($target, 0640);
        return $relative . '/' . $name;
    }

    /** Store a public upload; returns the web path (/uploads/...). */
    public static function storePublic(array $file, string $kind, string $prefix = ''): array
    {
        $info = self::validate($file, $kind);
        $relative = self::store($file, (string) config('storage.uploads'), $info['ext'], $prefix);
        return [
            'path' => '/uploads/' . $relative,
            'fs_path' => rtrim((string) config('storage.uploads'), '/') . '/' . $relative,
            'mime' => $info['mime'],
            'size' => $info['size'],
        ];
    }

    /** Store a protected upload (outside web root); returns metadata only. */
    public static function storeProtected(array $file, string $kind, string $subDir, string $prefix = ''): array
    {
        $info = self::validate($file, $kind);
        $base = rtrim((string) config('storage.protected'), '/') . '/' . trim($subDir, '/');
        $relative = self::store($file, $base, $info['ext'], $prefix);
        return [
            'path' => trim($subDir, '/') . '/' . $relative,
            'fs_path' => $base . '/' . $relative,
            'mime' => $info['mime'],
            'size' => $info['size'],
        ];
    }

    /** Securely delete a file (overwrite then unlink). */
    public static function secureDelete(string $fsPath): void
    {
        if (!is_file($fsPath)) {
            return;
        }
        try {
            $size = (int) filesize($fsPath);
            $fp = fopen($fsPath, 'r+b');
            if ($fp) {
                $chunk = str_repeat("\0", 8192);
                $written = 0;
                while ($written < $size) {
                    $n = fwrite($fp, substr($chunk, 0, min(8192, $size - $written)));
                    if ($n === false) {
                        break;
                    }
                    $written += $n;
                }
                fflush($fp);
                fclose($fp);
            }
        } catch (\Throwable $e) {
            // Best effort.
        }
        @unlink($fsPath);
    }
}
