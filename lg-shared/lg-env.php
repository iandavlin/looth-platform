<?php
/**
 * Shared env/host source of truth.
 *
 * Reads /etc/looth/env (simple KEY=VALUE) once, memoized, and hands every
 * strangler app the box's env + public host:
 *
 *     require_once '/srv/lg-shared/lg-env.php';
 *     $shared = function_exists('lg_env') ? lg_env() : [];
 *     $env  = $shared['env']  ?? <app's existing detection>;   // shared wins, existing = fallback
 *     $host = $shared['host'] ?? <app's existing host derivation>;
 *
 * CONTRACT — reversible + box-safe:
 *   - If /etc/looth/env is ABSENT or unreadable, lg_env() returns [] and every
 *     caller falls through to its own detection => the box behaves EXACTLY as
 *     it did before this file existed. (dev1 ships WITHOUT the file today, so
 *     its behavior is unchanged; only dev2 carries the file.)
 *   - Keys: LG_ENV -> ['env'], LG_PUBLIC_HOST -> ['host']. A missing/empty key
 *     is omitted from the array, so the caller's ?? fallback still covers it.
 *
 * At the cut, flipping the TWO values in /etc/looth/env (dev2 -> live and
 * dev2.loothgroup.com -> loothgroup.com) is the whole environment switch; the
 * CODE stays byte-identical on every box, so dev1/dev2/prod run one binary.
 *
 * No class, no composer/autoloader — same zero-dependency style as
 * jwt-verify.php so bb-mirror / archive-poc / events / membership can
 * require it as-is.
 */

if (!function_exists('lg_env')) {

    function lg_env(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;

        $cache = [];                       // absent/unreadable => empty => callers fall back
        $file  = '/etc/looth/env';
        if (!is_readable($file)) return $cache;

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            $eq = strpos($line, '=');
            if ($eq === false) continue;
            $key = trim(substr($line, 0, $eq));
            $val = trim(substr($line, $eq + 1));
            $val = preg_replace('/^([\'"])(.*)\1$/', '$2', $val);   // strip optional quotes
            if ($key === 'LG_ENV' && $val !== '') {
                $cache['env'] = $val;
            } elseif ($key === 'LG_PUBLIC_HOST' && $val !== '') {
                // host can feed curl 'Host:' headers downstream — sanitize to
                // hostname[:port] (defense in depth; the file is root-owned).
                $cache['host'] = preg_replace('/[^A-Za-z0-9.\-:]/', '', $val);
            }
        }
        return $cache;
    }
}
