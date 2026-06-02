<?php
/**
 * Viewer state for the shared header — cached /whoami loopback.
 *
 * Mirrors events / bb-mirror: render the chrome viewer-aware without paying
 * the WP-bootstrap tax on every request. Cached in /dev/shm keyed by the
 * WP session cookie (or "anon" if absent). TTL 45s — coarse enough to absorb
 * the role-change → /whoami-mint cycle.
 *
 * Per coord §0c: this is the TRANSITIONAL viewer source. Post-shim-replacement,
 * surfaces read identity from the `looth_id` JWT + `lg_tier` cookie directly
 * (no loopback). This helper is the bridge until that contract ships.
 *
 * CURL gotchas (called out in coord briefing — events-landing hit these):
 *   HTTP/2 ALPN handshake times out from a fresh FPM worker → force HTTP/1.1
 *   CURLOPT_TIMEOUT = 5 — bound the cold-cache latency
 */

declare(strict_types=1);

if (!function_exists('lg_membership_whoami')) {
function lg_membership_whoami(): ?array {
    static $fetched = false, $result = null;
    if ($fetched) return $result;
    $fetched = true;
    if (PHP_SAPI === 'cli') return null;

    $ttl  = 45;
    $sess = '';
    foreach ($_COOKIE as $k => $v) {
        if (strpos($k, 'wordpress_logged_in_') === 0) { $sess = (string)$v; break; }
    }
    $key   = $sess !== '' ? hash('sha256', $sess) : 'anon';
    $cache = '/dev/shm/lg-membership-whoami-' . $key . '.json';
    if (is_readable($cache) && (time() - (int)filemtime($cache)) < $ttl) {
        $hit = json_decode((string)file_get_contents($cache), true);
        if (is_array($hit) && array_key_exists('v', $hit)) {
            return $result = (is_array($hit['v']) ? $hit['v'] : null);
        }
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => 'https://127.0.0.1/wp-json/looth/v1/whoami',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_HTTPHEADER     => [
            'Host: ' . LG_MEMBERSHIP_HOST,
            'Cookie: ' . ($_SERVER['HTTP_COOKIE'] ?? ''),
        ],
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $result = ($code === 200 && is_string($body)) ? (json_decode($body, true) ?: null) : null;
    @file_put_contents($cache, json_encode(['v' => $result]), LOCK_EX);
    return $result;
}
}

/**
 * Mint a wp_rest nonce for the logged-in caller via loopback.
 *
 * The interactive ported surfaces (refund, affiliate, gift/subscription mgmt)
 * POST to cookie+nonce-gated /wp-json/lg-member-sync/v1/* routes. A standalone
 * page can't mint the nonce itself, so it loopback-fetches GET
 * /wp-json/looth/v1/rest-nonce (forwarding the browser's WP cookies, exactly
 * like lg_membership_whoami) and embeds the result where the shortcode did
 * `echo $nonce`. Returns '' for anon / on failure (the JS then no-ops/403s,
 * same as a logged-out shortcode visitor). Cached per request.
 */
if (!function_exists('lg_membership_rest_nonce')) {
function lg_membership_rest_nonce(): string {
    static $fetched = false, $nonce = '';
    if ($fetched) return $nonce;
    $fetched = true;
    if (PHP_SAPI === 'cli') return '';

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => 'https://127.0.0.1/wp-json/looth/v1/rest-nonce',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_HTTPHEADER     => [
            'Host: ' . LG_MEMBERSHIP_HOST,
            'Cookie: ' . ($_SERVER['HTTP_COOKIE'] ?? ''),
        ],
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 200 && is_string($body)) {
        $j = json_decode($body, true);
        if (is_array($j) && !empty($j['nonce'])) $nonce = (string) $j['nonce'];
    }
    return $nonce;
}
}

/**
 * Build the §0a-compliant ctx array for lg_shared_render_site_header().
 * Pass-through from /whoami plus the consumer-responsibility fields
 * (active_nav, logout_url, logo_url, profile_url).
 *
 * @param string $active_nav   '' for membership pages (not in canonical top nav per §0d)
 */
if (!function_exists('lg_membership_header_ctx')) {
function lg_membership_header_ctx(string $active_nav = ''): array {
    $who    = lg_membership_whoami();
    $authed = ($who['authenticated'] ?? false) === true;

    return [
        'authenticated' => $authed,
        'tier'          => (string)($who['tier'] ?? 'public'),
        'display_name'  => (string)($who['display_name'] ?? ''),
        'avatar_url'    => $who['avatar_url'] ?? null,
        'capabilities'  => (array)($who['capabilities'] ?? []),
        'msg_unread'    => null,                                       // lazy via REST
        'notif_unread'  => null,                                       // lazy via REST
        'logo_url'      => LG_MEMBERSHIP_LOGO,
        'profile_url'   => '/profile/edit',
        'active_nav'    => $active_nav,                                // coord §0a
        'logout_url'    => $authed ? '/wp-login.php?action=logout' : null,
    ];
}
}
