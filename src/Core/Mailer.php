<?php
/**
 * Mailer — transactional email with pluggable transports.
 *
 * Transports:
 *   log  — appends to storage/logs/mail.log (default; used in development)
 *   smtp — sends via a configured SMTP server using stream sockets
 *
 * In development every message is also visible in the Dev Inbox page.
 */

declare(strict_types=1);

namespace CodeAlpha\Core;

final class Mailer
{
    public static function send(string $to, string $subject, string $htmlBody, string $textBody = ''): bool
    {
        $transport = (string) config('mail.transport', 'log');
        $textBody = $textBody !== '' ? $textBody : trim(strip_tags($htmlBody));

        // Always keep a log copy for the dev inbox / audit trail.
        self::logToFile($to, $subject, $textBody);

        if ($transport === 'smtp') {
            return self::sendSmtp($to, $subject, $htmlBody, $textBody);
        }
        return true;
    }

    private static function logToFile(string $to, string $subject, string $body): void
    {
        try {
            $dir = (string) config('storage.logs');
            if (!is_dir($dir)) {
                mkdir($dir, 0770, true);
            }
            $line = json_encode([
                'to' => $to, 'subject' => $subject, 'body' => $body, 'at' => date('c'),
            ], JSON_UNESCAPED_SLASHES) . "\n";
            file_put_contents($dir . '/mail.log', $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            error_log('[CodeAlpha] mail log failure: ' . $e->getMessage());
        }
    }

    private static function sendSmtp(string $to, string $subject, string $htmlBody, string $textBody): bool
    {
        $cfg = config('mail.smtp', []);
        $host = (string) ($cfg['host'] ?? '');
        if ($host === '') {
            return false;
        }
        $port = (int) ($cfg['port'] ?? 587);
        $encryption = strtolower((string) ($cfg['encryption'] ?? 'tls'));
        $username = (string) ($cfg['username'] ?? '');
        $password = (string) ($cfg['password'] ?? '');
        $from = (string) config('mail.from', 'no-reply@localhost');
        $fromName = (string) config('mail.from_name', 'CodeAlpha');

        $remote = ($encryption === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $fp = @stream_socket_client($remote, $errno, $errstr, 10);
        if (!$fp) {
            error_log('[CodeAlpha] SMTP connect failed: ' . $errstr);
            return false;
        }
        $read = static function () use ($fp): string {
            $data = '';
            while (($line = fgets($fp, 515)) !== false) {
                $data .= $line;
                if (isset($line[3]) && $line[3] === ' ') {
                    break;
                }
            }
            return $data;
        };
        $write = static function (string $cmd) use ($fp, $read): string {
            fwrite($fp, $cmd . "\r\n");
            return $read();
        };

        $read(); // greeting
        $write('EHLO ' . ($encryption === 'ssl' ? $host : gethostname() ?: 'localhost'));
        if ($encryption === 'tls') {
            $write('STARTTLS');
            stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $write('EHLO ' . gethostname() ?: 'localhost');
        }
        if ($username !== '') {
            $write('AUTH LOGIN');
            $write(base64_encode($username));
            $write(base64_encode($password));
        }
        $write('MAIL FROM: <' . $from . '>');
        $write('RCPT TO: <' . $to . '>');
        $write('DATA');
        $boundary = '----codealpha_' . bin2hex(random_bytes(8));
        $message = 'From: ' . mb_encode_mimeheader($fromName) . ' <' . $from . ">\r\n"
            . 'To: ' . $to . "\r\n"
            . 'Subject: ' . mb_encode_mimeheader($subject) . "\r\n"
            . 'MIME-Version: 1.0' . "\r\n"
            . 'Content-Type: multipart/alternative; boundary="' . $boundary . '"' . "\r\n"
            . "\r\n"
            . '--' . $boundary . "\r\n"
            . 'Content-Type: text/plain; charset=utf-8' . "\r\n\r\n"
            . $textBody . "\r\n"
            . '--' . $boundary . "\r\n"
            . 'Content-Type: text/html; charset=utf-8' . "\r\n\r\n"
            . $htmlBody . "\r\n"
            . '--' . $boundary . '--' . "\r\n";
        $write($message . "\r\n.");
        $write('QUIT');
        fclose($fp);
        return true;
    }

    /** Render a simple branded HTML email. */
    public static function layout(string $title, string $bodyHtml): string
    {
        $appName = e(config('app.name', 'CodeAlpha'));
        $appUrl = e(config('app.url', ''));
        return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . e($title) . '</title></head>'
            . '<body style="font-family:Arial,Helvetica,sans-serif;background:#f4f4f7;padding:24px;">'
            . '<div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:12px;padding:32px;">'
            . '<h1 style="margin-top:0;font-size:20px;color:#1c1e21;">' . $appName . '</h1>'
            . '<h2 style="font-size:17px;color:#1c1e21;">' . e($title) . '</h2>'
            . $bodyHtml
            . '<p style="color:#8a8d91;font-size:12px;margin-top:32px;">This is an automated message from '
            . '<a href="' . $appUrl . '">' . $appName . '</a>. Please do not reply.</p>'
            . '</div></body></html>';
    }
}
