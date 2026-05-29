# Design — At-login `looth_id` mint, retire the WP shim

> **Status:** v0 draft, 2026-05-29. Reviewers: coordinator (ratification),
> lg-shell (WP-hook coverage), archive-poc + bb-mirror (inline-verify migration).
> **Do not start the build until the design is reviewed.**

## TL;DR

Move JWT minting from "lazy at consumer call" (current shim + per-request
loopback to derive identity from the WP cookie) to "eager at WP session
establishment." Result: every logged-in user always carries a `looth_id`
cookie; strangler consumers verify it inline with the RS256 public key.
The WP shim's whoami translation becomes a fallback that retires once
covered.

## What stays the same — the hard scope boundary

- **WP still owns passwords and the user store.** This is not the auth-authority
  inversion. WP authenticates; we *also* issue our token at the moment WP
  establishes a session.
- **profile-app still owns the JWT signing key.** WP calls profile-app's
  mint endpoint over the loopback shared-secret channel; private key never
  leaves the profile-app filesystem.
- **STRANGLER-COORDINATION §1 tier vocabulary, §2 whoami shape, §3 looth1
  semantics** — all unchanged. The wire format consumers verify is the
  same shape they get from `/whoami` today.

## 1. Mint endpoint

**Route:** `POST /profile-api/v0/internal/mint-token`
**Auth:** `X-LG-Internal-Auth` header verified with `hash_equals()` against
`/etc/lg-internal-secret`. Localhost-only via the existing
`location ^~ /profile-api/v0/internal/` nginx prefix block (same gate
as `internal/purge-whoami`).

**Request body:**
```json
{ "wp_user_id": 1234 }
```

**Response 200:**
```json
{
  "token": "eyJ0eXAiOi...",
  "exp":   1782468939
}
```

**Errors:**
- 400 `wp_user_id_required` — missing/non-integer/<1
- 403 `forbidden` — bad/missing shared secret (same as purge endpoint)
- 404 `no_bridge` — wp_user_id has no `wp_user_bridge` row
- 502 `mint_failed` — JWT signer error (key unreadable, etc.) — caller
  MUST treat as graceful-degrade signal, see §6

**Implementation notes:**
- Internally calls existing `Auth::mintJwt($wpUser)` machinery (already
  used by `looth-auth` mu-plugin's `/wp-json/looth/auth/issue` route).
- Returns the raw token; setting the cookie is the caller's job (the WP
  hook will use WP's cookie helpers to align attributes — see §3).
- No Redis cache. Mint is cheap (~5ms RSA sign); per-login frequency
  doesn't justify caching.

## 2. WP hooks — every session-establishment path

A miss here means a silently shim-dependent session. Every entry point:

| Hook | When | Why this matters |
|---|---|---|
| `wp_login` | Form login (`wp_signon`), programmatic login | The canonical login moment |
| `set_logged_in_cookie` | Anytime WP plants its login cookie | Catches re-auth via remember-me, password reset auto-login, manual programmatic logins (e.g. `wp_set_auth_cookie()` after signup) |
| `clear_auth_cookie` | Logout, password change, session destroy | Clears `looth_id` |
| `wp_logout` | Explicit user logout | Defensive — overlaps with `clear_auth_cookie` but cheap to double-fire |

**Implementation pattern (single mu-plugin):**

```php
function lg_shell_mint_looth_id(int $wp_user_id, $expires = 0): void {
    if (!$wp_user_id) return;
    $body = wp_json_encode(['wp_user_id' => $wp_user_id]);
    $resp = wp_remote_post('https://127.0.0.1/profile-api/v0/internal/mint-token', [
        'sslverify' => false,
        'timeout'   => 5,
        'headers'   => [
            'X-LG-Internal-Auth' => LG_INTERNAL_SECRET,
            'Content-Type'       => 'application/json',
            'Host'               => 'dev.loothgroup.com',
        ],
        'body'      => $body,
    ]);
    if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) !== 200) {
        // Graceful degrade: log + continue. Login still succeeds; consumers
        // fall back to shim.
        error_log('[looth-id-mint] failed: ' . (is_wp_error($resp) ? $resp->get_error_message() : 'http ' . wp_remote_retrieve_response_code($resp)));
        return;
    }
    $data  = json_decode(wp_remote_retrieve_body($resp), true);
    $token = is_array($data) ? ($data['token'] ?? '') : '';
    if (!$token) return;
    // Cookie attributes per §3.
    setcookie(LOOTH_AUTH_COOKIE, $token, [
        'expires'  => $expires ?: (time() + 2592000),
        'path'     => COOKIEPATH,
        'domain'   => COOKIE_DOMAIN,   // .dev.loothgroup.com / .loothgroup.com
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// Form/programmatic login
add_action('wp_login', function ($login, WP_User $user) {
    lg_shell_mint_looth_id((int)$user->ID);
}, 10, 2);

// Catches everything wp_login misses (auto-login after signup, after
// password reset, after billing flows, etc).
add_action('set_logged_in_cookie', function ($cookie, $expire, $expiration, $user_id) {
    lg_shell_mint_looth_id((int)$user_id, (int)$expire);
}, 10, 4);

// Logout / session destroy
add_action('clear_auth_cookie', function () {
    setcookie(LOOTH_AUTH_COOKIE, '', [
        'expires'  => time() - 3600,
        'path'     => COOKIEPATH,
        'domain'   => COOKIE_DOMAIN,
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
});
```

**Replaces:** the slice-2.5 `wp_login` + `init` hooks in
`profile-auth.php` that mint inline. The new mu-plugin centralizes the
flow and uses the internal mint endpoint instead of an in-process
`looth_auth_mint_jwt()` call. Why move it out of `profile-auth.php`?
So lg-shell owns the surface, and the next hook addition (password
reset, social signup, etc) doesn't require profile-app source edits.

## 3. Cookie attributes

| Attribute | Value | Reason |
|---|---|---|
| Name | `looth_id` | Existing — consumers already read this |
| Domain | `COOKIE_DOMAIN` constant (`.dev.loothgroup.com` / `.loothgroup.com`) | Cross-surface (archive-poc, bb-mirror live under same apex) |
| Path | `/` (via `COOKIEPATH`) | Visible to every strangler surface |
| HttpOnly | true | Prevents XSS exfiltration; consumers verify server-side |
| Secure | true | HTTPS only — dev and live both serve TLS |
| SameSite | `Lax` | Allows top-level navigation; blocks cross-site POST |
| Expires | Aligns with WP session; `wp_login` → 30 days, remember-me → 14 days otherwise → session | Matches WP's existing auth-cookie behavior so a user logging out everywhere clears both atomically |

## 4. Key distribution for inline verify

Consumers verify with the RS256 **public** key. Private key stays in
profile-app.

**Public key location (dev):** `/etc/looth/jwt-public.pem` (already exists
— used by profile-app's `Auth::PUBLIC_KEY`).

**Distribution mechanism:** each strangler surface that needs inline
verify reads the same path. The file is mode 0644 root:root, world-
readable. **No HTTP key endpoint** — keeps things simple and avoids a
new TOFU surface. Live deploy plan adds the same file at the same path.

If a key rotation becomes necessary, JWT header `kid` is supported in
RS256; we'd add a 2nd key file and the verify helper would try both for
a grace window. Out of scope for v1.

**Shared verify helper** (proposed location: `/srv/lg-shared/jwt-verify.php`):
```php
function lg_shared_verify_looth_id(?string $cookie): ?array {
    if (!is_string($cookie) || $cookie === '') return null;
    $pubkey = @file_get_contents('/etc/looth/jwt-public.pem');
    if (!is_string($pubkey)) return null;
    try {
        $decoded = \Firebase\JWT\JWT::decode($cookie, new \Firebase\JWT\Key($pubkey, 'RS256'));
        return (array) $decoded;
    } catch (\Throwable $e) {
        return null;
    }
}
```

Same `firebase/php-jwt` dependency profile-app already uses. Consumers
add the composer dep to their own vendor tree.

## 5. Consumer migration — incremental, reversible

Each consumer (archive-poc, bb-mirror) gets the same two-step migration:

**Step A — add inline verify, keep shim fallback:**
```php
$claims = lg_shared_verify_looth_id($_COOKIE['looth_id'] ?? null);
if ($claims) {
    // Inline path: have wp_user_id + uuid + email from claims.
    $viewer = lg_consumer_viewer_from_claims($claims);
} else {
    // Fallback path: existing loopback to /wp-json/looth/v1/whoami
    $viewer = lg_consumer_viewer_from_shim();
}
```

This is additive — no consumer behavior change for users who already had a
`looth_id` cookie; users who didn't (mid-rollout) still work via shim.

**Step B — retire shim fallback:** once telemetry shows zero shim hits
for N days on dev (proposed N=7), remove the fallback branch. Consumer
goes inline-only.

**Metrics to gate Step B:**
- Per-consumer counter: how many requests hit the fallback branch
- Per-consumer counter: how many requests hit the inline branch
- Threshold: fallback < 0.1% of requests for 7 consecutive days

## 6. Failure modes — graceful degradation is required

| Failure | Behavior |
|---|---|
| Mint endpoint unreachable at login | WP login succeeds; `looth_id` not set; consumers fall back to shim |
| Mint endpoint returns 502 | Same as unreachable — log + continue |
| Mint endpoint returns 404 `no_bridge` | Log warning; reconcile-bridge script should be run; user can still log in |
| `LG_INTERNAL_SECRET` missing in WP | mu-plugin no-ops silently with error_log; treat as unreachable |
| Public key file missing on consumer | Consumer falls back to shim (`verify` returns null) |
| Public key rotated without updating consumers | Same — consumers fall back to shim until updated |
| User has a stale `looth_id` from before rotation | Verify fails → fall back to shim → shim mints new one upstream → eventually replaced |

**Hard rule:** at no point may a missing/failed `looth_id` mint cause WP
login to fail. The shim fallback path is what makes this safe to deploy.

## 7. Shim retirement criteria

The shim (`profile-whoami-shim.mu-plugin.php`) and the `/wp-json/looth/v1/whoami`
endpoint can be retired when ALL of:

- [ ] All 4 WP hooks (`wp_login`, `set_logged_in_cookie`, `clear_auth_cookie`, `wp_logout`) verified firing in dev cold-walk
- [ ] Each consumer (archive-poc, bb-mirror) reports < 0.1% shim-fallback rate for 7 consecutive days on dev
- [ ] Live cutover migration script confirmed runs without dependency on shim
- [ ] Coordinator review confirms no remaining caller of `/wp-json/looth/v1/whoami`

Retirement = delete mu-plugin + remove `rest_authentication_errors` filter
+ remove route registration. profile-app's `/profile-api/v0/whoami` endpoint
stays — it's the authoritative whoami for cases where inline verify
needs a fresh tier (e.g., suspected stale cookie).

## 8. Dev test plan

**Prereqs:**
- `/etc/looth/jwt-public.pem` readable by all strangler-user accounts
- `/etc/lg-internal-secret` readable by `www-data` (already in place)

**Cold-walk additions to `bin/walk-onboarding.sh`:**

1. **Form login mint:** create fresh user → simulate form login → assert
   `looth_id` cookie set on response → decode and verify claims contain
   correct `wp_user_id` + `sub` (uuid)
2. **Programmatic login mint:** call `wp_set_auth_cookie()` via WP-CLI
   → assert `looth_id` cookie returned in subsequent HTTP request (via
   `set_logged_in_cookie` hook firing)
3. **Logout clears:** form logout → assert `looth_id` cookie cleared
   (`expires` in past)
4. **Mint-down → login still works:** stop profile-app FPM → log in →
   verify WP login succeeds (302 to dashboard) + no `looth_id` set +
   shim fallback returns expected `/whoami` shape on subsequent consumer
   call
5. **Mint-down recovery:** restart profile-app FPM → next page load
   triggers re-mint (via `init` hook fallback we'll add to the new
   mu-plugin: if logged-in AND no `looth_id`, mint)
6. **Stale token → re-mint:** drop a token signed by old key (simulate
   rotation) → verify inline check fails → shim fallback fires → next
   login re-mints with new key

**Perf measurement:** on dev with `looth_id` present, measure TTFB on a
logged-in `/forums-poc/<topic>` page (bb-mirror) before and after
inline-verify rollout. Target: ≥ 80ms reduction (current loopback to
`/wp-json/looth/v1/whoami` adds ~100ms warm).

## 9. Sequencing

1. **Design ratification** (coordinator + lg-shell + archive-poc + bb-mirror)
2. **Build mint endpoint** (`api/v0/internal-mint-token.php`) + nginx
   route + walk smoke
3. **Build/move mu-plugin** to `/var/www/dev/wp-content/mu-plugins/lg-shell-looth-id.php`
   (or wherever lg-shell wants it) — covers all 4 hooks
4. **Build shared verify helper** at `/srv/lg-shared/jwt-verify.php`
5. **Consumer migrations** (Step A — additive, parallel): archive-poc + bb-mirror
   add inline-then-fallback logic
6. **Telemetry rollup**: shim fallback rate metrics in each consumer
7. **N-day observation window** (proposed 7 days dev)
8. **Step B retirement**: consumers drop fallback; shim mu-plugin removed
9. **Documentation update**: STRANGLER-COORDINATION.md §2 marks shim
   retired; §5 diagram replaces shim arrow with cookie arrow

## 10. Open questions for review

1. **mu-plugin home.** This design proposes a new file
   `lg-shell-looth-id.php` in `lg-shell`'s namespace. The existing
   `profile-auth.php` (which has the slice-2.5 init-mint logic) — keep
   alongside as a complementary fallback (mints on `init` if missing for
   browse-only sessions that never logged in but somehow have the WP
   cookie), or remove?
2. **Mint on every page-with-WP-session-but-no-looth_id?** Belt-and-
   suspenders. Catches edge cases the 4 hooks miss. Cost: a per-pageview
   loopback for sessions in that state (rare after the 4 hooks land).
3. **`exp` claim alignment.** Current slice-2.5 JWT has 30-day TTL.
   Should the eager-mint match WP's auth-cookie expiry exactly (which
   varies: 30d remember-me, 2d session)? Or stay 30d for simplicity?
4. **Key file location on live.** `/etc/looth/jwt-public.pem` exists on
   dev. Confirm live deploy plan includes provisioning at same path
   with mode 0644.
5. **Linktree question** (unrelated but flagged): adding `linktree` to
   `Profile::SOCIAL_KINDS` as a one-line precursor — out of scope for
   this design doc but tracked in `plan-social-consolidation.md`.

---

Ready for review. Cross-cutting bits (cookie contract, consumer verify
helper) ratification pass requested before build kickoff.
