<?php

declare(strict_types=1);

namespace LGBilling\Db;

use PDO;

/**
 * PDO connection for billing-svc — framework-free, mirrors archive-poc's
 * config.php pattern (NOT the Slim DI container of lg-stripe-billing).
 *
 * DSN comes from the LG_BILLING_SVC_DSN env var, exported via env[] in the FPM
 * pool (deploy/php-fpm-pool-billing-svc.conf) and set on CLI for bin/ scripts.
 * Unix-socket peer auth (STRANGLER-COORDINATION.md §3i): no user/password — the
 * pg role identity comes from the billing-svc OS user.
 *
 *   LG_BILLING_SVC_DSN='pgsql:host=/var/run/postgresql;dbname=looth'
 *
 * search_path is pinned to `billing` here (not via ALTER ROLE) because pg roles
 * may be shared across stranglers and can't carry a single per-role default —
 * same reasoning as archive-poc pinning `discovery`.
 *
 * NOTE: this is NOT instantiated this milestone (the scaffold runs no live DB).
 * It is the connection the step-1 PdoSourceStore / PdoAuditLog will use.
 */
final class Connection
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $dsn = getenv('LG_BILLING_SVC_DSN');
        if ($dsn === false || $dsn === '') {
            throw new \RuntimeException(
                'LG_BILLING_SVC_DSN is not set. Export it in the FPM pool '
                . '(env[]) or on the CLI. See deploy/php-fpm-pool-billing-svc.conf.'
            );
        }

        $pdo = new PDO($dsn, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
            $pdo->exec('SET search_path = billing, public');
        }

        self::$pdo = $pdo;
        return self::$pdo;
    }
}
