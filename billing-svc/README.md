# billing-svc

Standalone, framework-free **entitlement/tier service** for loothgroup.com — the
extracted tier-decision logic that lives, today, inside the WordPress plugin
`lg-patreon-stripe-poller`. Built to take WordPress off the **entitlement
authority** path (see `docs/design-membership-rebuild.md` — the KEYSTONE box).

> **Current status: dark-launch scaffold (turn 1).** Faithful *copy* of the WP
> Arbiter + Patreon source reader, behind a single-writer interface, with
> fail-closed unit tests. **Changes no live behaviour.** The WP plugin keeps
> running untouched. See `MIGRATION-NOTES.md`.

## What it is / isn't

- **Is:** the relocated *tier-decision* logic (Arbiter), the Patreon source
  reader, and the single-writer grant seam — small, boring, auditable.
- **Isn't:** a payments rewrite. It holds **no Stripe keys** at this stage; the
  money engine (`/srv/lg-stripe-billing/`) keeps payment credentials. Credential
  isolation is the whole thesis (design §1 / §5b-B): the fat app never holds
  payment secrets, and this service never holds the JWT key / identity tables.

## Layout

```
billing-svc/
├── autoload.php              framework-free PSR-4 loader (no composer needed for tests)
├── composer.json             PSR-4 LGBilling\ → src/  (+ phpunit dev dep)
├── src/
│   ├── Tier.php              tier vocabulary (looth1..4, floor, paid set)
│   ├── Port/UserDirectory.php    read-only WP-state port (kills get_user_meta WP-isms)
│   ├── Source/                   SourceReader + PatreonSourceReader (ported) + SourceStore
│   ├── Arbiter/                  Arbiter (pure decide) + TierDecision + ArbiterService + EventContext
│   ├── Writer/                   TierWriter SEAM + TierGrant/Result + Recording/WpRole/MemberTier writers
│   ├── Audit/                    AuditLog + InMemoryAuditLog (immutable grant log)
│   ├── Db/Connection.php         pg connection (peer auth, search_path=billing) — not used yet
│   └── Schema/schema.sql         billing pg schema (sources, immutable audit, idempotency)
├── db/grants.sql             single-writer DB grants (the §5b-A enforcement)
├── deploy/                    systemd unit+timer, FPM pool, internal-only nginx — NOT INSTALLED
├── tests/ArbiterTest.php      fail-closed FIRST, then guards/parity/reader/writer
└── MIGRATION-NOTES.md         what's ported, the seam, what step-1/2 repoint touches
```

## Run the tests (framework-free)

```bash
php tests/ArbiterTest.php
```

No `composer install` required — `autoload.php` is a zero-dependency PSR-4
loader. Exit code 0 = all pass. Test order is deliberate: **§1 fail-closed
asserts first** (absence/ambiguity never yields a paid tier — design §5b-C),
then guards, winner parity with the WP Arbiter, the Patreon reader port, and the
single-writer idempotency/audit contract.

## Security posture baked in from turn 1 (design §5b)

- **Single writer:** all grants go through `Writer\TierWriter`; `db/grants.sql`
  enforces it at the DB-grant level (only the billing-svc role writes; the audit
  table is append-only even for it).
- **Fail-closed:** `Arbiter::computeWinningTier` defaults to the floor on
  null/empty/ambiguous sources; the writer converges nulls DOWN, never up.
- **Idempotent + audited:** every grant carries a deterministic `event_id`;
  replays are no-ops; every attempt writes an immutable audit row.
- **Internal-only endpoints:** the relocated tier endpoints (step 1/2) are
  loopback-only — see `deploy/nginx-billing-svc-internal.conf`. No public block,
  ever.
- **No Stripe creds reachable by the fat app:** none in any web pool env; none
  in this scaffold at all.
