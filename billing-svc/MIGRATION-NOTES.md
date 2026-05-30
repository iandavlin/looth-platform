# billing-svc — migration notes (dark-launch scaffold)

> **Status:** turn-1 scaffold, 2026-05-30. **Nothing here changes live
> behaviour.** The WP plugin `lg-patreon-stripe-poller` keeps running, keeps
> polling Patreon, keeps writing `wp_capabilities`. This is a faithful *copy* of
> its tier-decision logic into a standalone, framework-free service, proven by
> unit tests, ready to be *wired in* at step 1.

Source of truth for the port:
`/var/www/dev/wp-content/plugins/lg-patreon-stripe-poller/src/` — read directly
(read-only, with sudo) so the port matches real behaviour, not memory.

---

## What's ported (and where)

| WP plugin (LGMS\…) | billing-svc (LGBilling\…) | Fidelity |
|---|---|---|
| `Arbiter::computeWinningTier()` | `Arbiter\Arbiter::computeWinningTier()` | byte-faithful (highest-wins, fail-closed) |
| `Arbiter::currentTier()` | `Arbiter\Arbiter::currentTier()` | byte-faithful |
| `Arbiter::isUpgradeToPaid()` | `Arbiter\Arbiter::isUpgradeToPaid()` | byte-faithful |
| `Arbiter::sync()` guard ladder | `Arbiter\Arbiter::decide()` | faithful (decision only, no writes) |
| `Patreon\PatreonSourceReader::readForUser()` | `Source\PatreonSourceReader` | faithful; WP calls → `UserDirectory` port |
| `RoleSourceWriter::readAllForUser()` merge | `Arbiter\ArbiterService::mergedSources()` | faithful (persisted rows + live Patreon overlay) |
| `InternalRestController` tier/provenance maps | *noted, not ported yet* | step-1 endpoint relocation |

**Tier vocabulary** (`LGBilling\Tier`) is copied identically: `looth1..4`
ascending, `looth1` = free floor, `looth4` = comp/admin (never source-driven).

---

## The interface seam (this is the whole point)

The WP Arbiter *decided* and *wrote* in one method. The port splits them:

```
UserDirectory (read) ─┐
SourceStore   (read) ─┤→ Arbiter::decide() → TierDecision ─→ TierWriter::apply()
SourceReader  (read) ─┘     (pure, fail-closed)                 (SINGLE WRITER)
```

- **`Arbiter::decide()`** — pure, WP-free, no I/O. Returns a `TierDecision`
  (apply/skip + winning tier + old tier + upgrade flag). Fully unit-tested.
- **`TierWriter`** — the **single-writer grant seam** (`src/Writer/TierWriter.php`).
  The one interface through which entitlement is granted. Implementations:
  - `RecordingTierWriter` — in-memory reference impl (idempotent + audited +
    fail-closed). Drives the tests; proves the seam in isolation.
  - `WpRoleTierWriter` — **step-1 target** (WP roles). **Stub that throws** this
    milestone — billing-svc does NOT write `wp_capabilities` from outside WP yet.
  - `MemberTierWriter` — **step-2 target** (`profile_app.member_tier`). **Stub
    that throws** — tier authority has not inverted yet.

Today's WP roles vs tomorrow's `member_tier` are just two implementations of the
same seam. That is what lets step 2 repoint the write target without touching
the arbitration logic.

---

## Side effects NOT ported (must re-attach in the WP-targeting writer at step 1)

`Arbiter::sync()` did WP-specific work after deciding. The scaffold left these
out **on purpose** (a dark launch must not fire them). When `WpRoleTierWriter`
becomes real, it MUST reproduce all of them so behaviour stays identical:

1. **`add_role`/`remove_role`** preserving every non-tier role (administrator,
   bbp_*). `looth1` is sticky — never removed (gift buyers keep gift caps).
2. **`bp_set_member_type($id, 'starter'|'')`** — directory visibility tracks
   paid status (looth1/none → `starter` hidden; looth2+ → cleared).
3. **`_lg_pending_welcome` meta + `WelcomeMailer::sendIfNeeded()`** on
   upgrade-into-paid (the `isUpgradeToPaid` flag is already computed in
   `TierDecision`).
4. **`do_action('looth_tier_changed', …)`** on a real transition — drives
   `PurgeNotifier` (profile-app cache invalidation). Must keep firing on
   **downgrades**, not just upgrades (§5b-E revocation).

The `TierDecision` already carries what these need: `oldTier`, `winningTier`,
`isUpgradeToPaid`, `isTransition()`, and the merged `sources` (for provenance via
`InternalRestController::deriveProvenance` once that's relocated).

---

## ⚠ Fidelity flags for the coordinator (decisions, not bugs)

1. **`computeWinningTier([])` returns `null`, not `looth1`.** Faithful to WP:
   empty sources = "don't touch / hold at floor." The bootstrap phrased the
   fail-closed default as "→ public (looth1)". These reconcile because a `null`
   winner makes the writer converge the user DOWN to the floor (`Tier::FLOOR`),
   never up — see `RecordingTierWriter` + test **F6**. The security guarantee
   asserted is the strong one: *absence/ambiguity is NEVER a paid tier* (tests
   F1–F7). Confirm you're happy with `null`-means-floor rather than collapsing
   `null`→`looth1` inside the Arbiter (I kept WP's exact semantics).

2. **Stripe-source guard depends on `payment_source` user-meta + a `looth1`
   role check.** Ported verbatim. Step 1 must supply `payment_source` to
   `decide()` from the same source WP read it from, or the guard silently stops
   protecting Stripe-owned users.

3. **Patreon tier is read LIVE, never persisted.** `ArbiterService` overlays the
   live `PatreonSourceReader` opinion on top of persisted `SourceStore` rows,
   overwriting any stale `patreon` row — exactly as `RoleSourceWriter`. The
   step-1 `PdoSourceStore` must NOT persist Patreon rows or this breaks.

4. **`event_id` must be deterministic** (hash of user+source+tier+cursor), NOT a
   timestamp/random — see `EventContext`. A random id defeats replay-safety
   (every replay would look new). The poll-tick caller owns generating it.

---

## What step-1 / step-2 will touch (so the coordinator can scope the repoint)

**Step 1 — relocate the poller (behaviour-identical):**
- Implement `WpRoleTierWriter` (re-attach the 4 side effects above) OR run the
  poll loop that calls WP's existing role-write path over loopback.
- Implement `PdoSourceStore` + `PdoAuditLog` against the `billing` schema
  (`src/Schema/schema.sql`); apply `db/grants.sql` (single-writer).
- Relocate the `user-context` read endpoint into
  `/billing-internal/v1/user-context/{id}` (internal-only, §5b-D).
- **profile-app repoint:** the loopback URL in profile-app `Whoami.php` moves
  from `/wp-json/looth-internal/v1/user-context/{id}` to the billing-svc socket.
  ⚠ `profile-app/` is shared (profile-2.0 + shim) — flag before editing.
- Install `deploy/*` (FPM pool, systemd unit/timer, nginx internal location).
  Pre-ship gate: off-box curl proves the endpoint 403s.

**Step 2 — invert tier authority:**
- Create `profile_app.member_tier` (shape stubbed in `schema.sql`), grants per
  `db/grants.sql` step-2 block (billing-svc INSERT/UPDATE; profile-app SELECT).
- Implement `MemberTierWriter`. `/whoami` reads `member_tier` locally; the WP
  loopback retires.

---

## Internal endpoints added (inventory for §5b-D — keep loopback-only)

| Endpoint | Step | Method | Auth | Exposure |
|---|---|---|---|---|
| `/billing-internal/v1/user-context/{id}` | 1 | GET | internal secret | loopback ONLY — must 403 off-box |
| `/billing-internal/v1/tier-grant` | 2 | POST | internal secret | loopback ONLY — must 403 off-box |

Neither gets a public nginx location block, ever. No public/location-near-me
data blocks on this service.
