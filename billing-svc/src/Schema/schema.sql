-- billing-svc schema — Postgres, schema `billing`
-- (STRANGLER-COORDINATION.md §3i: one postgres, N schemas; billing-svc owns `billing`).
--
-- This DDL is the planned shape for step 1/2. It is NOT applied this milestone
-- (dark-launch scaffold — no live DB changes). The coordinator applies it under
-- the billing pg role when step 1 lands. Idempotent (IF NOT EXISTS) so it is
-- safe to re-run.
--
-- Security shape (design §5b-A/E) is encoded structurally here; the matching
-- GRANTs live in db/grants.sql and are the *enforcement*.

CREATE SCHEMA IF NOT EXISTS billing;

-- ── Persisted per-source tier opinions ──────────────────────────────────────
-- Framework-free equivalent of the WP plugin's lg_role_sources table.
-- Stripe + manual_admin opinions are persisted here; the Patreon opinion is
-- read live and merged on top (PatreonSourceReader), never persisted.
-- tier NULL = source is present but reports no entitlement (lapsed).
CREATE TABLE IF NOT EXISTS billing.lg_role_sources (
    wp_user_id  BIGINT       NOT NULL,
    user_uuid   UUID         NULL,           -- populated as step-2 lands
    source      VARCHAR(32)  NOT NULL,       -- 'stripe' | 'manual_admin' | ...
    tier        VARCHAR(32)  NULL,           -- looth1..4, NULL = lapsed
    updated_at  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    PRIMARY KEY (wp_user_id, source)
);
CREATE INDEX IF NOT EXISTS idx_role_sources_source ON billing.lg_role_sources (source);
CREATE INDEX IF NOT EXISTS idx_role_sources_uuid   ON billing.lg_role_sources (user_uuid);

-- ── Immutable grant audit — design §5b-E ────────────────────────────────────
-- ONE row per grant attempt (applied, no-op, or refused). APPEND-ONLY: the
-- billing pg role gets INSERT + SELECT only on this table — no UPDATE, no
-- DELETE (see db/grants.sql). A fraudulent/buggy grant is detectable after the
-- fact and reversible (by issuing a corrective grant, never by editing history).
CREATE TABLE IF NOT EXISTS billing.tier_grant_audit (
    id          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_uuid   UUID         NULL,
    wp_user_id  BIGINT       NOT NULL,
    old_tier    VARCHAR(32)  NULL,
    new_tier    VARCHAR(32)  NULL,
    source      VARCHAR(32)  NULL,           -- winning source, NULL = held at floor
    event_id    VARCHAR(128) NOT NULL,       -- idempotency key (§5b-A)
    actor       VARCHAR(128) NOT NULL,       -- 'arbiter:patreon-poll', 'admin:<id>', ...
    applied     BOOLEAN      NOT NULL,
    outcome     VARCHAR(32)  NOT NULL,       -- 'applied' | 'no-op' | 'duplicate-event' | 'invalid-target-refused'
    ts          TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_audit_user     ON billing.tier_grant_audit (wp_user_id, ts);
CREATE INDEX IF NOT EXISTS idx_audit_event    ON billing.tier_grant_audit (event_id);

-- ── Idempotency / replay guard — design §5b-A ───────────────────────────────
-- A captured-and-replayed grant must be a no-op. A unique APPLIED event id is
-- the chokepoint: the writer refuses to apply an event id already marked
-- applied. (The audit table can answer this, but a dedicated unique index
-- makes the guard a hard DB constraint, not just application logic.)
CREATE TABLE IF NOT EXISTS billing.processed_events (
    event_id      VARCHAR(128) PRIMARY KEY,
    wp_user_id    BIGINT       NOT NULL,
    first_seen_at TIMESTAMPTZ  NOT NULL DEFAULT now()
);

-- ── STEP-2 (planned, NOT created here) ──────────────────────────────────────
-- The tier authority inversion lands `member_tier` in the profile_app schema,
-- NOT in billing. billing-svc is its SOLE writer; profile-app web role gets
-- SELECT only. Shape, for reference (created by step-2 under its own review):
--
--   CREATE TABLE profile_app.member_tier (
--       user_uuid   UUID PRIMARY KEY,
--       tier        VARCHAR(32) NOT NULL,      -- public vocab or looth*? — coord decides
--       provenance  VARCHAR(16) NOT NULL,      -- paid|comp|lapsed|new (§1 enum)
--       source      VARCHAR(32) NULL,
--       updated_at  TIMESTAMPTZ NOT NULL DEFAULT now()
--   );
