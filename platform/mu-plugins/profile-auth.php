<?php
/**
 * Plugin Name: Looth Auth (JWT minter)
 * Description: Mints an RS256 JWT on WP login + drops it as the `looth_id`
 *              cookie scoped to .loothgroup.com. All other Looth services
 *              (profile-app, archive-poc, future native app) verify the same
 *              JWT against the matching public key — there is no other auth.
 * Version:     0.1.0
 *
 * Wiring:
 *   - hook wp_login          → mint + set cookie
 *   - hook init              → if user is logged in and cookie missing, mint
 *   - REST  /wp-json/looth/auth/refresh → re-mint from current WP session
 *
 * Keys:
 *   /etc/looth/jwt-private.pem  (640 root:looth-dev)
 *   /etc/looth/jwt-public.pem   (644 root:root — readable everywhere)
 *
 * `sub` claim is UUIDv5(LOOTH_IDENTITY_NAMESPACE, lower(trim(user_email))).
 * If a user changes their email in WP, the next minted token will resolve to
 * a different uuid than profile-app stored — caveat noted in slice one handoff.
 */

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/looth-vendor/autoload.php';

const LOOTH_AUTH_NAMESPACE      = 'eaef23f7-9bc9-4a95-ac49-ffff632e6646';
const LOOTH_AUTH_COOKIE         = 'looth_id';
const LOOTH_AUTH_COOKIE_DOMAIN  = '.dev.loothgroup.com';      // dev — adjust per env at deploy time
const LOOTH_AUTH_TTL_SECONDS    = 30 * 24 * 60 * 60;          // 30 days
const LOOTH_AUTH_PRIVATE_KEY    = '/etc/looth/jwt-private.pem';
const LOOTH_AUTH_PUBLIC_KEY     = '/etc/looth/jwt-public.pem';   // 644 root:root — readable
const LOOTH_AUTH_ISS            = 'https://dev.loothgroup.com';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Ramsey\Uuid\Uuid;

function looth_auth_compute_uuid(string $email): string {
    $ns = Uuid::fromString(LOOTH_AUTH_NAMESPACE);
    return Uuid::uuid5($ns, strtolower(trim($email)))->toString();
}

function looth_auth_mint_jwt(WP_User $user): string {
    static $pk = null;
    if ($pk === null) $pk = @file_get_contents(LOOTH_AUTH_PRIVATE_KEY);
    if (!$pk) throw new RuntimeException('looth-auth: private key unreadable');

    $now = time();
    $payload = [
        'iss'        => LOOTH_AUTH_ISS,
        'sub'        => looth_auth_compute_uuid($user->user_email),
        'wp_user_id' => (int) $user->ID,
        'email'      => $user->user_email,
        'iat'        => $now,
        'exp'        => $now + LOOTH_AUTH_TTL_SECONDS,
    ];
    return JWT::encode($payload, $pk, 'RS256');
}

// Verify a looth_id JWT against the public key. Returns the claims array on a
// good token (signature + exp + iss all valid), or null on anything suspect.
// Used by the reverse session bridge below — we mint a real WP session off this
// token, so it must be cryptographically trusted, never just parsed.
function looth_auth_verify_jwt(string $jwt): ?array {
    static $pub = null;
    if ($pub === null) $pub = @file_get_contents(LOOTH_AUTH_PUBLIC_KEY) ?: false;
    if (!$pub) return null;
    try {
        $claims = (array) JWT::decode($jwt, new Key($pub, 'RS256'));   // throws on bad sig/expiry
    } catch (Throwable $e) {
        return null;
    }
    if (($claims['iss'] ?? '') !== LOOTH_AUTH_ISS) return null;
    return $claims;
}

function looth_auth_set_cookie(string $jwt): void {
    setcookie(LOOTH_AUTH_COOKIE, $jwt, [
        'expires'  => time() + LOOTH_AUTH_TTL_SECONDS,
        'path'     => '/',
        'domain'   => LOOTH_AUTH_COOKIE_DOMAIN,
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function looth_auth_clear_cookie(): void {
    setcookie(LOOTH_AUTH_COOKIE, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'domain'   => LOOTH_AUTH_COOKIE_DOMAIN,
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// On successful login, mint + set.
add_action('wp_login', function ($user_login, WP_User $user) {
    try { looth_auth_set_cookie(looth_auth_mint_jwt($user)); }
    catch (Throwable $e) { error_log('looth-auth mint failed: ' . $e->getMessage()); }
}, 10, 2);

// On logout, clear.
add_action('wp_logout', function () { looth_auth_clear_cookie(); });

// Belt to wp_logout's suspenders: clear_auth_cookie fires on programmatic
// cookie clears + session destruction (password change, "log out everywhere")
// that wp_logout alone misses. Without this, those paths leave a valid looth_id
// behind and the user stays "logged in" to strangler surfaces after WP logout.
// Double-clear on a plain logout is harmless (setcookie with past expiry twice).
add_action('clear_auth_cookie', function () { looth_auth_clear_cookie(); });

// On any normal pageview where user is logged in but cookie is missing, mint.
add_action('init', function () {
    if (is_admin() && wp_doing_ajax()) return;
    if (defined('REST_REQUEST') && REST_REQUEST) return;
    if (!is_user_logged_in()) return;
    if (!empty($_COOKIE[LOOTH_AUTH_COOKIE])) return;
    if (headers_sent()) return;
    try { looth_auth_set_cookie(looth_auth_mint_jwt(wp_get_current_user())); }
    catch (Throwable $e) { error_log('looth-auth init-mint failed: ' . $e->getMessage()); }
});

// Reverse direction: a valid looth_id JWT but NO live WP session → establish one.
// The forward hook above keeps the JWT in sync when WP says logged-in; this keeps
// the WP session in sync when the JWT says logged-in. Without it the two disagree:
//   - Patreon-onboarded members created before onboard minted a WP cookie carry a
//     looth_id (whoami=logged-in) but have no WP session, so wp_validate_auth_cookie
//     returns anon and the membership nonce bridge / /me/* surface 401s.
//   - On this DB-reload-heavy box a reload wipes server-side session tokens, so the
//     WP cookie stops validating while the stateless JWT survives — same split.
// Contract this restores: whoami=logged-in ⇒ a valid WP auth cookie exists.
// Runs early (priority 1) so the rest of the request sees the authenticated user.
add_action('init', function () {
    if (is_admin() && wp_doing_ajax()) return;
    if (defined('REST_REQUEST') && REST_REQUEST) return;   // healed via the next pageview, not mid-REST
    if (is_user_logged_in()) return;                        // WP session already valid — nothing to heal
    if (empty($_COOKIE[LOOTH_AUTH_COOKIE])) return;         // no identity to bridge from
    if (headers_sent()) return;

    $claims = looth_auth_verify_jwt((string) $_COOKIE[LOOTH_AUTH_COOKIE]);
    if (!$claims) return;                                   // forged / expired / wrong issuer → ignore

    // Anchor on email, not wp_user_id: IDs are recyclable after a DB reload, so a
    // stale id claim can point at a different human. The JWT sub (uuid5 of email)
    // makes email the identity of record; the id claim is advisory only.
    $email = strtolower(trim((string) ($claims['email'] ?? '')));
    if ($email === '') return;
    $user = get_user_by('email', $email);
    if (!$user) return;

    wp_set_current_user($user->ID, $user->user_login);
    wp_set_auth_cookie($user->ID, true);
    error_log('looth-auth: bridged WP session from looth_id for ' . $email . ' (#' . $user->ID . ')');
}, 1);

// Admin bar: "My Profile" link visible to all logged-in users.
// (Visible whether or not they've claimed; the editor handles the interstitial.)
add_action('admin_bar_menu', function (WP_Admin_Bar $bar) {
    if (!is_user_logged_in()) return;
    $bar->add_node([
        'id'    => 'looth-my-profile',
        'title' => 'My Profile',
        'href'  => 'https://dev.loothgroup.com/profile/edit',
        'meta'  => ['title' => 'Edit your Looth profile'],
    ]);
}, 80);

// BuddyBoss member nav: front-end "My Profile 2.0" entry. The WP admin bar
// is hidden on front-end for members, so the slice-1.5 admin_bar item only
// showed in wp-admin. BB's nav renders on every front-end page.
add_action('bp_setup_nav', function () {
    if (!is_user_logged_in() || !function_exists('bp_core_new_nav_item')) return;
    bp_core_new_nav_item([
        'name'                    => 'My Profile 2.0',
        'slug'                    => 'profile-2',
        'position'                => 5,
        'default_subnav_slug'     => 'profile-2',
        'show_for_displayed_user' => false,    // only on user's own profile
        'item_css_id'             => 'looth-profile-2',
        'screen_function'         => function () {
            wp_safe_redirect('/profile/edit');
            exit;
        },
    ]);
});

// REST: /wp-json/looth/auth/refresh — re-mint from current WP session.
// REST: /wp-json/looth/auth/issue?return=<path>  — mint + 302 back to the
//   given path. Used by profile-app when it sees a wordpress_logged_in cookie
//   but no looth_id cookie; the round-trip is invisible to the user.
add_action('rest_api_init', function () {
    register_rest_route('looth/auth', '/refresh', [
        'methods'  => 'POST',
        'permission_callback' => function () { return is_user_logged_in(); },
        'callback' => function () {
            try {
                $jwt = looth_auth_mint_jwt(wp_get_current_user());
                looth_auth_set_cookie($jwt);
                return ['ok' => true, 'exp' => time() + LOOTH_AUTH_TTL_SECONDS];
            } catch (Throwable $e) {
                return new WP_Error('mint_failed', $e->getMessage(), ['status' => 500]);
            }
        },
    ]);

    register_rest_route('looth/auth', '/issue', [
        'methods'  => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function (WP_REST_Request $req) {
            $return = $req->get_param('return') ?: '/profile/edit';
            // Only allow same-origin returns — never bounce to off-host URLs.
            if (!is_string($return) || $return === '' || $return[0] !== '/') {
                $return = '/profile/edit';
            }
            $back = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'dev.loothgroup.com') . $return;
            if (!is_user_logged_in()) {
                $login = wp_login_url($back);
                wp_redirect($login); exit;
            }
            try {
                $jwt = looth_auth_mint_jwt(wp_get_current_user());
                looth_auth_set_cookie($jwt);
            } catch (Throwable $e) {
                error_log('looth-auth issue mint failed: ' . $e->getMessage());
            }
            wp_redirect($back); exit;
        },
    ]);
});
