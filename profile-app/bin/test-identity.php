<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

use Looth\ProfileApp\Identity;

$cases = [
    ['ian@example.com',         'ian@example.com'],
    ['  IAN@Example.COM  ',     'ian@example.com'],
    ["Ian@Example.com\n",       'ian@example.com'],
];

$ok = true;
$canonical = Identity::computeUuid('ian@example.com');
foreach ($cases as [$in, $expEqualTo]) {
    $got = Identity::computeUuid($in);
    $exp = Identity::computeUuid($expEqualTo);
    $pass = $got === $exp;
    echo sprintf("[%s] %-30s → %s\n", $pass ? 'OK' : 'FAIL', json_encode($in), $got);
    if (!$pass) $ok = false;
}

// Different emails must produce different uuids.
$alt = Identity::computeUuid('someoneelse@example.com');
if ($alt === $canonical) {
    echo "[FAIL] distinct emails collided\n"; $ok = false;
} else {
    echo "[OK]   distinct emails → distinct uuids ($alt)\n";
}

// Namespace stability: assert against a precomputed expected value so a
// future namespace rotation gets caught loudly.
$expectedNs = 'eaef23f7-9bc9-4a95-ac49-ffff632e6646';
if (LOOTH_IDENTITY_NAMESPACE !== $expectedNs) {
    echo "[FAIL] namespace changed — every existing uuid would be orphaned\n";
    $ok = false;
}

exit($ok ? 0 : 1);
