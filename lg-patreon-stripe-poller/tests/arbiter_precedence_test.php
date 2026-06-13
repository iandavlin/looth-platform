<?php
/**
 * Guards the MEDIUM fix: tier precedence is EXPLICIT (a rank map), not the
 * lexical strcmp it used to be. The pure ranking + winning-tier methods don't
 * touch WordPress, so they're testable in isolation.
 *
 * Regression target: replacing tierRank()-based comparisons with strcmp() —
 * which happens to work for looth1..4 today but silently mis-ranks the moment
 * a tier is re-slugged or added outside the looth<n> lexical run.
 */

declare(strict_types=1);

require __DIR__ . '/_assert.php';
require dirname( __DIR__ ) . '/src/Arbiter.php';

use LGMS\Arbiter;

// --- explicit rank --------------------------------------------------------
lgms_eq( 1, Arbiter::tierRank( 'looth1' ), 'looth1 ranks 1' );
lgms_eq( 2, Arbiter::tierRank( 'looth2' ), 'looth2 ranks 2' );
lgms_eq( 3, Arbiter::tierRank( 'looth3' ), 'looth3 ranks 3' );
lgms_eq( 4, Arbiter::tierRank( 'looth4' ), 'looth4 ranks 4' );
lgms_eq( 0, Arbiter::tierRank( null ), 'null ranks below every tier' );
lgms_eq( 0, Arbiter::tierRank( 'administrator' ), 'non-tier role ranks 0' );

// rank is strictly monotonic across the ladder
lgms_ok(
    Arbiter::tierRank( 'looth1' ) < Arbiter::tierRank( 'looth2' )
    && Arbiter::tierRank( 'looth2' ) < Arbiter::tierRank( 'looth3' )
    && Arbiter::tierRank( 'looth3' ) < Arbiter::tierRank( 'looth4' ),
    'rank is strictly increasing looth1<looth2<looth3<looth4'
);

// --- winning tier across sources -----------------------------------------
lgms_eq( 'looth4', Arbiter::winningTierForSources( [ 'stripe' => 'looth4', 'patreon' => 'looth2' ] ), 'highest source wins (looth4)' );
lgms_eq( 'looth3', Arbiter::winningTierForSources( [ 'patreon' => 'looth3', 'manual_admin' => 'looth1' ] ), 'highest source wins (looth3)' );
lgms_eq( 'looth2', Arbiter::winningTierForSources( [ 'stripe' => 'looth2' ] ), 'single source wins' );

// order-independence: same set, reversed insertion → same winner
lgms_eq(
    Arbiter::winningTierForSources( [ 'a' => 'looth2', 'b' => 'looth4' ] ),
    Arbiter::winningTierForSources( [ 'b' => 'looth4', 'a' => 'looth2' ] ),
    'winner is independent of source order'
);

// rows present but no tier opinion → looth1 (lapsed), per contract
lgms_eq( 'looth1', Arbiter::winningTierForSources( [ 'stripe' => null ] ), 'rows with null tier fall back to looth1' );

// no rows at all → null (don't touch the user)
lgms_eq( null, Arbiter::winningTierForSources( [] ), 'no sources → null' );

// junk tier strings are ignored, not ranked
lgms_eq( 'looth2', Arbiter::winningTierForSources( [ 'x' => 'looth9000', 'y' => 'looth2' ] ), 'unknown tier string is ignored' );

lgms_done( 'arbiter_precedence' );
