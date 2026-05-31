<?php
declare(strict_types=1);

namespace Looth\ProfileApp;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

final class Auth
{
    public const COOKIE      = 'looth_id';
    public const PUBLIC_KEY  = '/etc/looth/jwt-public.pem';

    private static ?array  $cachedUser  = null;
    private static bool    $cacheBuilt  = false;
    private static ?string $publicKey   = null;
    private static ?array  $lastClaims  = null;
    private static ?bool   $isAdmin     = null;

    /** Returns the JWT claims or null if absent/invalid. */
    public static function claims(): ?array
    {
        if (self::$cacheBuilt) return self::$lastClaims;
        self::$cacheBuilt = true;

        $jwt = self::readToken();
        if ($jwt === null) return null;

        if (self::$publicKey === null) {
            self::$publicKey = @file_get_contents(self::PUBLIC_KEY);
            if (!self::$publicKey) {
                error_log('profile-app Auth: cannot read ' . self::PUBLIC_KEY);
                return null;
            }
        }

        try {
            $decoded = JWT::decode($jwt, new Key(self::$publicKey, 'RS256'));
            self::$lastClaims = (array) $decoded;
            return self::$lastClaims;
        } catch (\Throwable $e) {
            // Expired / signature mismatch / malformed — treat as anonymous.
            return null;
        }
    }

    /** Returns the profile-app user row for the bearer, or null if anonymous. */
    public static function currentUser(): ?array
    {
        if (self::$cachedUser !== null) return self::$cachedUser ?: null;

        $claims = self::claims();
        if (!$claims || empty($claims['sub'])) return null;

        $stmt = Db::pg()->prepare('SELECT * FROM users WHERE uuid = :u');
        $stmt->execute([':u' => strtolower((string)$claims['sub'])]);
        $row = $stmt->fetch();

        self::$cachedUser = $row ?: [];
        return $row ?: null;
    }

    /** Required-auth helper for API endpoints. 401s if no user resolved. */
    public static function requireUser(): array
    {
        $u = self::currentUser();
        if (!$u) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'auth_required']);
            exit;
        }
        return $u;
    }

    /**
     * Whether the bearer is a WordPress administrator. Gates front-end catalog management
     * (admins add/deactivate catalog rows from the picker). The looth_id token carries no
     * role claim today, so this checks wp_capabilities in the WP DB via the peer-auth MySQL
     * socket (same access pattern as me-name's wp_users mirror). Cached per request.
     */
    public static function isAdmin(): bool
    {
        if (self::$isAdmin !== null) return self::$isAdmin;
        self::$isAdmin = false;
        $claims = self::claims();
        $wpId   = (int) ($claims['wp_user_id'] ?? 0);
        if ($wpId < 1) return false;
        try {
            $u  = posix_getpwuid(posix_geteuid())['name'] ?? 'profile-app';
            $my = new \PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=' . LG_PROFILE_APP_MYSQL_DB,
                $u, '', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $s = $my->prepare("SELECT meta_value FROM wp_usermeta WHERE user_id = ? AND meta_key = 'wp_capabilities'");
            $s->execute([$wpId]);
            $caps = (string) $s->fetchColumn();
            self::$isAdmin = $caps !== '' && strpos($caps, '"administrator"') !== false;
        } catch (\Throwable $e) {
            error_log('[Auth::isAdmin] cap check failed: ' . $e->getMessage());
        }
        return self::$isAdmin;
    }

    private static function readToken(): ?string
    {
        if (!empty($_COOKIE[self::COOKIE])) return (string)$_COOKIE[self::COOKIE];
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (stripos($auth, 'Bearer ') === 0) return trim(substr($auth, 7));
        return null;
    }
}
