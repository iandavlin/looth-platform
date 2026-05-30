<?php

declare(strict_types=1);

/**
 * Framework-free PSR-4 autoloader for billing-svc.
 *
 * billing-svc is deliberately framework-free (mirrors archive-poc, NOT the
 * Slim-based lg-stripe-billing). This lets the ported Arbiter + Patreon reader
 * and the unit tests run with plain `php` — no `composer install`, no vendor/.
 *
 * Maps the LGBilling\ namespace prefix to src/, and LGBilling\Tests\ to tests/.
 * If composer's vendor/autoload.php is ever generated it can be required
 * instead; this file is the zero-dependency default.
 */

spl_autoload_register(static function (string $class): void {
    static $prefixes = [
        'LGBilling\\Tests\\' => __DIR__ . '/tests/',
        'LGBilling\\'        => __DIR__ . '/src/',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }
        $relative = substr($class, strlen($prefix));
        $path = $baseDir . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require $path;
        }
        return;
    }
});
