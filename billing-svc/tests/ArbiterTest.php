<?php

declare(strict_types=1);

/**
 * billing-svc arbitration + single-writer tests.
 * Run (framework-free, no composer install needed):  php tests/ArbiterTest.php
 *
 * Test ORDER is deliberate and load-bearing (bootstrap + design §5b-C):
 *   §1  FAIL-CLOSED   — the security-critical default, asserted FIRST.
 *   §2  GUARD SKIPS   — faithful to the WP Arbiter's early-return protections.
 *   §3  PARITY        — same source map => same winner as the WP Arbiter.
 *   §4  PATREON READER— faithful port of PatreonSourceReader.
 *   §5  SINGLE-WRITER — idempotency / replay-safety / audit (§5b-A/E).
 *
 * The proof the extraction is faithful: §3/§4 mirror the WP plugin's logic;
 * §1 proves absence-of-data can never yield a paid tier.
 */

require __DIR__ . '/../autoload.php';

use LGBilling\Arbiter\Arbiter;
use LGBilling\Arbiter\ArbiterService;
use LGBilling\Arbiter\EventContext;
use LGBilling\Audit\InMemoryAuditLog;
use LGBilling\Source\InMemorySourceStore;
use LGBilling\Source\PatreonSourceReader;
use LGBilling\Tests\FakeUserDirectory;
use LGBilling\Tier;
use LGBilling\Writer\RecordingTierWriter;
use LGBilling\Writer\TierGrant;

$pass = 0;
$fail = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        echo "  PASS  {$name}\n";
        $pass++;
    } else {
        echo "  FAIL  {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
        $fail++;
    }
}

/** The one assertion that matters most: a result must never be a paid tier. */
function refuteePaid(string $name, ?string $tier): void
{
    check($name, !Tier::isPaid($tier), 'resolved to PAID tier ' . var_export($tier, true));
}

$ctx = static fn (string $eventId): EventContext =>
    new EventContext($eventId, 'test:arbiter', '2026-05-30T00:00:00Z');

// ─────────────────────────────────────────────────────────────────────────
echo "§1  FAIL-CLOSED — absence/ambiguity must never grant paid\n";
// ─────────────────────────────────────────────────────────────────────────

// F1: no source rows at all -> null ("hold at floor"), never paid.
$r = Arbiter::computeWinningTier([]);
check('F1 empty sources -> null (do-not-touch)', $r === null, var_export($r, true));
refuteePaid('F1 empty sources not paid', $r);

// F2: rows exist but every tier is null (lapsed) -> floor looth1, not paid.
$r = Arbiter::computeWinningTier(['stripe' => null, 'patreon' => null]);
check('F2 all-null tiers -> looth1', $r === Tier::LOOTH1, var_export($r, true));
refuteePaid('F2 all-null not paid', $r);

// F3: garbage / unknown tier strings are ignored, never elevate.
$r = Arbiter::computeWinningTier(['x' => 'admin', 'y' => 'looth9', 'z' => '', 'w' => 'LOOTH3']);
check('F3 garbage tiers -> looth1 floor', $r === Tier::LOOTH1, var_export($r, true));
refuteePaid('F3 garbage not paid', $r);

// F4: a lone null patreon opinion -> floor, not paid.
$r = Arbiter::computeWinningTier(['patreon' => null]);
refuteePaid('F4 lone-null source not paid', $r);

// F5: decide() with empty sources still fail-closes (winner null/floor, never paid).
$d = Arbiter::decide(['looth1'], null, []);
check('F5 decide empty -> apply', $d->apply === true, $d->reason);
refuteePaid('F5 decide empty winner not paid', $d->winningTier);

// F6: the SINGLE WRITER converges a null winner DOWN to the floor — it must not
//     preserve a previously-held paid tier when data goes away (revocation hole).
$audit  = new InMemoryAuditLog();
$writer = new RecordingTierWriter($audit);
$writer->apply(new TierGrant(42, null, null, 'looth3', 'stripe', 'evt-up', 'test', '2026-05-30T00:00:00Z'));
check('F6 setup: user is looth3', $writer->effectiveTier(42) === 'looth3');
$writer->apply(new TierGrant(42, null, 'looth3', null, null, 'evt-down', 'test', '2026-05-30T00:00:01Z'));
check('F6 null winner -> floor looth1', $writer->effectiveTier(42) === Tier::LOOTH1, $writer->effectiveTier(42) ?? 'null');
refuteePaid('F6 downgrade not paid', $writer->effectiveTier(42));

// F7: end-to-end through ArbiterService — user with NO sources, not stripe ->
//     fail-closed to floor, never paid.
$users = new FakeUserDirectory();
$users->seedUser(7, ['looth1'], ['payment_source' => 'patreon']); // patreon but no paid role
$store = new InMemorySourceStore();                                // no persisted rows
$audit7 = new InMemoryAuditLog();
$writer7 = new RecordingTierWriter($audit7);
$svc = new ArbiterService($users, $store, [new PatreonSourceReader($users)], $writer7);
$svc->sync(7, $ctx('evt-7'));
refuteePaid('F7 e2e no-paid-source -> not paid', $writer7->effectiveTier(7));

// ─────────────────────────────────────────────────────────────────────────
echo "\n§2  GUARD SKIPS — faithful WP early-return protections\n";
// ─────────────────────────────────────────────────────────────────────────

$d = Arbiter::decide(null, null, ['stripe' => 'looth3']);
check('G1 no such user -> skip', !$d->apply && $d->reason === 'no such WP user', $d->reason);

$d = Arbiter::decide(['looth4', 'administrator'], null, []);
check('G2 looth4 protected -> skip', !$d->apply && $d->reason === 'looth4 protected, skipped', $d->reason);

$d = Arbiter::decide(['looth3'], 'stripe', []); // stripe + no looth1 role + no source row
check('G3 stripe-source w/o row -> skip', !$d->apply && $d->reason === 'stripe-source w/o source row, skipped', $d->reason);

$d = Arbiter::decide(['looth1'], 'stripe', ['stripe' => 'looth3']); // stripe but HAS looth1 -> proceed
check('G4 stripe + looth1 -> arbitrates', $d->apply && $d->winningTier === 'looth3', $d->reason . '/' . var_export($d->winningTier, true));

// ─────────────────────────────────────────────────────────────────────────
echo "\n§3  PARITY — same source map => same winner as the WP Arbiter\n";
// ─────────────────────────────────────────────────────────────────────────

check('P1 single stripe=looth3', Arbiter::computeWinningTier(['stripe' => 'looth3']) === 'looth3');
check('P2 stripe<patreon -> highest', Arbiter::computeWinningTier(['stripe' => 'looth2', 'patreon' => 'looth3']) === 'looth3');
check('P3 manual_admin looth4 wins', Arbiter::computeWinningTier(['patreon' => 'looth2', 'manual_admin' => 'looth4']) === 'looth4');
check('P4 currentTier highest role', Arbiter::currentTier(['looth2', 'administrator', 'bbp_participant']) === 'looth2');
check('P5 currentTier none -> null', Arbiter::currentTier(['administrator']) === null);

check('P6 upgrade null->looth2', Arbiter::isUpgradeToPaid(null, 'looth2') === true);
check('P7 upgrade looth2->looth3', Arbiter::isUpgradeToPaid('looth2', 'looth3') === true);
check('P8 downgrade looth3->looth2 not upgrade', Arbiter::isUpgradeToPaid('looth3', 'looth2') === false);
check('P9 null->looth1 not paid-upgrade', Arbiter::isUpgradeToPaid(null, 'looth1') === false);
check('P10 looth2->looth2 not upgrade', Arbiter::isUpgradeToPaid('looth2', 'looth2') === false);

// ─────────────────────────────────────────────────────────────────────────
echo "\n§4  PATREON READER — faithful port of PatreonSourceReader\n";
// ─────────────────────────────────────────────────────────────────────────

$pu = new FakeUserDirectory();
$pu->seedUser(1, ['looth2', 'bbp_participant'], ['payment_source' => 'patreon', 'lgpo_patreon_tier_id' => 'tier_abc']);
$pu->seedUser(2, ['looth3', 'looth2'], ['payment_source' => 'patreon']);
$pu->seedUser(3, ['subscriber'], ['payment_source' => 'patreon']);
$pu->seedUser(4, ['looth3'], ['payment_source' => 'stripe']); // stripe-owned
$reader = new PatreonSourceReader($pu);

check('PR1 userId<=0 -> null', $reader->readForUser(0) === null);
check('PR2 non-patreon -> null', $reader->readForUser(4) === null);
$row = $reader->readForUser(1);
check('PR3 patreon looth2 + tier_id', $row !== null && $row['tier'] === 'looth2' && $row['tier_id'] === 'tier_abc', json_encode($row));
$row = $reader->readForUser(2);
check('PR4 highest-first scan -> looth3', $row !== null && $row['tier'] === 'looth3', json_encode($row));
$row = $reader->readForUser(3);
check('PR5 no looth role -> default looth1', $row !== null && $row['tier'] === 'looth1', json_encode($row));
check('PR6 empty tier_id -> null', $row !== null && $row['tier_id'] === null, json_encode($row));
check('PR7 unknown user -> null', $reader->readForUser(999) === null);

// ─────────────────────────────────────────────────────────────────────────
echo "\n§5  SINGLE-WRITER — idempotency / replay-safety / audit (§5b-A/E)\n";
// ─────────────────────────────────────────────────────────────────────────

$a = new InMemoryAuditLog();
$w = new RecordingTierWriter($a);

$res = $w->apply(new TierGrant(10, 'uuid-10', null, 'looth3', 'stripe', 'evt-100', 'arbiter:stripe', '2026-05-30T00:00:00Z'));
check('W1 first grant applied', $res->applied === true && $w->effectiveTier(10) === 'looth3', $res->reason);

// Replay the SAME event id -> must be a no-op (replay-safe).
$res = $w->apply(new TierGrant(10, 'uuid-10', null, 'looth3', 'stripe', 'evt-100', 'arbiter:stripe', '2026-05-30T00:00:05Z'));
check('W2 replay same event -> duplicate', $res->applied === false && $res->reason === 'duplicate-event', $res->reason);
check('W2 replay leaves tier unchanged', $w->effectiveTier(10) === 'looth3');

// Same tier, fresh event id -> no-op (nothing to change).
$res = $w->apply(new TierGrant(10, 'uuid-10', 'looth3', 'looth3', 'stripe', 'evt-101', 'arbiter:stripe', '2026-05-30T00:01:00Z'));
check('W3 same-tier fresh event -> no-op', $res->applied === false && $res->reason === 'no-op', $res->reason);

// Invalid target -> refused at the writer chokepoint (belt-and-braces).
$res = $w->apply(new TierGrant(10, 'uuid-10', 'looth3', 'looth9', 'stripe', 'evt-102', 'arbiter:stripe', '2026-05-30T00:02:00Z'));
check('W4 invalid target refused', $res->applied === false && $res->reason === 'invalid-target-refused', $res->reason);
check('W4 refusal left tier intact', $w->effectiveTier(10) === 'looth3');

// Audit completeness: every attempt left a row; exactly one is applied=true.
$rows = $a->all();
$applied = array_filter($rows, static fn ($r) => $r['applied'] === true);
check('W5 audit has a row per attempt', count($rows) === 4, 'rows=' . count($rows));
check('W5 exactly one applied row', count($applied) === 1, 'applied=' . count($applied));
$first = $rows[0];
check('W5 audit row carries event_id+actor+old+new+source+ts',
    $first['event_id'] === 'evt-100' && $first['actor'] === 'arbiter:stripe'
    && $first['old'] === null && $first['new'] === 'looth3'
    && $first['source'] === 'stripe' && $first['ts'] === '2026-05-30T00:00:00Z',
    json_encode($first));

// ─────────────────────────────────────────────────────────────────────────
echo "\n────────────────────────────────────────\n";
echo "  {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
