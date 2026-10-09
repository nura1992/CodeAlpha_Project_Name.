<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Outbound HTTPS client for payment gateway APIs. Only allowlisted hosts are reachable,
 * redirects are not followed and TLS verification is mandatory.
 */
final class HttpClient
{
    private const ALLOWED_HOSTS = ['api.paystack.co', 'api.flutterwave.com'];

    /** @return array{status:int,json:?array,raw:string} */
    public static function request(string $method, string $url, array $headers = [], ?array $json = null): array
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || !in_array($host, self::ALLOWED_HOSTS, true)) {
            throw new RuntimeException('Outbound host is not allowlisted.');
        }
        $ch = curl_init($url);
        $httpHeaders = ['Accept: application/json'];
        foreach ($headers as $h) {
            $httpHeaders[] = $h;
        }
        $opts = [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => $json !== null ? array_merge($httpHeaders, ['Content-Type: application/json']) : $httpHeaders,
        ];
        if ($json !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($json, JSON_THROW_ON_ERROR);
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Gateway request failed: ' . $err);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $decoded = json_decode((string) $raw, true);
        return ['status' => $status, 'json' => is_array($decoded) ? $decoded : null, 'raw' => (string) $raw];
    }
}
