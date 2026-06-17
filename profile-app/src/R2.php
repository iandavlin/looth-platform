<?php
declare(strict_types=1);

namespace Looth\ProfileApp;

/**
 * R2 — minimal Cloudflare R2 (S3 API) client for profile-media ORIGINALS.
 *
 * SigV4-signed PUT/GET/DELETE/HEAD over curl, no aws-sdk dependency (profile-app
 * stays lean). Path-style addressing (endpoint/<bucket>/<key>), region "auto".
 *
 * Config comes from the FPM pool env (set at deploy; swapped to the DEDICATED
 * live bucket + scoped creds at cut — never in git):
 *   LG_PROFILE_R2_ENDPOINT  https://<account>.r2.cloudflarestorage.com
 *   LG_PROFILE_R2_BUCKET    loothgroup-uploads-dev (dev) | profile-media (live)
 *   LG_PROFILE_R2_KEY / LG_PROFILE_R2_SECRET   scoped R2 token
 *   LG_PROFILE_R2_PREFIX    optional key prefix (e.g. "profile-media")
 *
 * Keys mirror the served path: <class>/<uuid>/<file>. The resizer .cache/ stays
 * LOCAL (regenerable); only originals live here. enabled() lets callers fall back
 * to the legacy local store while the migration is in flight.
 */
final class R2
{
    private static function cfg(string $k): string
    {
        $v = getenv($k);
        return is_string($v) ? $v : '';
    }

    public static function enabled(): bool
    {
        return self::cfg('LG_PROFILE_R2_ENDPOINT') !== '' && self::cfg('LG_PROFILE_R2_BUCKET') !== '';
    }

    /** Prepend the configured prefix to a relative <class>/<uuid>/<file> key. */
    private static function key(string $rel): string
    {
        $p = trim(self::cfg('LG_PROFILE_R2_PREFIX'), '/');
        $rel = ltrim($rel, '/');
        return $p === '' ? $rel : $p . '/' . $rel;
    }

    public static function put(string $rel, string $body, string $contentType = 'application/octet-stream'): bool
    {
        return self::request('PUT', self::key($rel), $body, $contentType) !== null;
    }

    /** Object body, or null on 404 / error. */
    public static function get(string $rel): ?string
    {
        return self::request('GET', self::key($rel), '', null);
    }

    public static function delete(string $rel): bool
    {
        return self::request('DELETE', self::key($rel), '', null) !== null;
    }

    public static function exists(string $rel): bool
    {
        return self::request('HEAD', self::key($rel), '', null) !== null;
    }

    /**
     * SigV4-signed S3 request. Returns the response body on 2xx ('' for PUT/
     * DELETE/HEAD), null on 404 / 4xx / 5xx / transport error.
     */
    private static function request(string $method, string $key, string $body, ?string $contentType): ?string
    {
        if (!self::enabled()) { error_log('[r2] not configured'); return null; }

        $endpoint = rtrim(self::cfg('LG_PROFILE_R2_ENDPOINT'), '/');
        $host     = (string) parse_url($endpoint, PHP_URL_HOST);
        $bucket   = self::cfg('LG_PROFILE_R2_BUCKET');
        $ak       = self::cfg('LG_PROFILE_R2_KEY');
        $sk       = self::cfg('LG_PROFILE_R2_SECRET');
        $region   = 'auto';
        $service  = 's3';

        // Canonical URI: each path segment percent-encoded, '/' preserved (S3 style).
        $canonicalUri = '/' . $bucket . '/' . str_replace('%2F', '/', rawurlencode($key));
        $payloadHash  = hash('sha256', $body);
        $amzDate      = gmdate('Ymd\THis\Z');
        $dateStamp    = gmdate('Ymd');

        $headers = [
            'host'                 => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date'           => $amzDate,
        ];
        if ($contentType !== null && $method === 'PUT') $headers['content-type'] = $contentType;
        ksort($headers);
        $signedHeaders    = implode(';', array_keys($headers));
        $canonicalHeaders = '';
        foreach ($headers as $k => $v) $canonicalHeaders .= $k . ':' . $v . "\n";

        $canonicalRequest = $method . "\n" . $canonicalUri . "\n\n"
            . $canonicalHeaders . "\n" . $signedHeaders . "\n" . $payloadHash;

        $scope        = $dateStamp . '/' . $region . '/' . $service . '/aws4_request';
        $stringToSign = "AWS4-HMAC-SHA256\n" . $amzDate . "\n" . $scope . "\n" . hash('sha256', $canonicalRequest);

        $kDate    = hash_hmac('sha256', $dateStamp, 'AWS4' . $sk, true);
        $kRegion  = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $authz = 'AWS4-HMAC-SHA256 Credential=' . $ak . '/' . $scope
            . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature;

        $curlHeaders = ['Authorization: ' . $authz];
        foreach ($headers as $k => $v) {
            if ($k === 'host') continue;
            $curlHeaders[] = $k . ': ' . $v;
        }

        $ch = curl_init($endpoint . $canonicalUri);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $curlHeaders,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY         => $method === 'HEAD',
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 30,
        ]);
        if ($method === 'PUT') curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false)            { error_log("[r2] $method $key transport error: $err"); return null; }
        if ($code >= 200 && $code < 300) return is_string($resp) ? $resp : '';
        if ($code === 404)               return null;
        error_log("[r2] $method $key -> HTTP $code");
        return null;
    }
}
